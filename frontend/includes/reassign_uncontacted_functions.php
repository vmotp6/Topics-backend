<?php
/**
 * 共用：三天未聯絡轉下一意願的查詢與重新分配
 * 供 check_uncontacted_students.php 與 run_three_day_reassign_once.php 使用
 */

require_once __DIR__ . '/enrollment_assignment_log.php';

/**
 * 獲取下一個志願
 */
function getNextEnrollmentChoice($conn, $enrollment_id, $current_department_code) {
    $stmt = $conn->prepare("
        SELECT 
            ec.choice_order,
            ec.department_code,
            d.name AS department_name
        FROM enrollment_choices ec
        LEFT JOIN departments d ON ec.department_code = d.code
        WHERE ec.enrollment_id = ?
        ORDER BY ec.choice_order ASC
    ");
    $stmt->bind_param("i", $enrollment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $choices = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($choices)) {
        return null;
    }

    $current_index = -1;
    foreach ($choices as $index => $choice) {
        if (strtoupper(trim($choice['department_code'] ?? '')) === strtoupper(trim($current_department_code))) {
            $current_index = $index;
            break;
        }
    }

    if ($current_index === -1) {
        return $choices[0];
    }

    if ($current_index + 1 < count($choices)) {
        return $choices[$current_index + 1];
    }

    return null;
}

/**
 * 重新分配給下一個志願
 * @param array|null $next_choice 下一個志願（含 choice_order, department_code），用於寫入分配歷程
 */
function reassignToNextChoice($conn, $enrollment_id, $new_department_code, $student_data, $next_choice = null) {
    try {
        $conn->begin_transaction();

        $stmt = $conn->prepare("UPDATE enrollment_intention SET assigned_department = ?, assigned_teacher_id = NULL WHERE id = ?");
        $stmt->bind_param("si", $new_department_code, $enrollment_id);
        $stmt->execute();
        $stmt->close();

        $choice_order = isset($next_choice['choice_order']) ? (int)$next_choice['choice_order'] : 0;
        if ($choice_order < 1) {
            $cnt = count_enrollment_assignment_logs($conn, $enrollment_id);
            $choice_order = $cnt + 1;
        }
        insert_enrollment_assignment_log($conn, $enrollment_id, $new_department_code, $choice_order, 'reassign');

        $director_stmt = $conn->prepare("
            SELECT u.id, u.name, u.email
            FROM director dir
            INNER JOIN user u ON dir.user_id = u.id
            WHERE dir.department = ?
            LIMIT 1
        ");
        $director_stmt->bind_param("s", $new_department_code);
        $director_stmt->execute();
        $director_result = $director_stmt->get_result();
        $director = $director_result->fetch_assoc();
        $director_stmt->close();

        if ($director && !empty($director['email'])) {
            $student_data_array = [
                'name' => $student_data['name'] ?? '',
                'phone1' => $student_data['phone1'] ?? '',
                'email' => $student_data['email'] ?? ''
            ];
            sendDirectorReassignmentNotification($conn, $new_department_code, $student_data_array, $director);
        }

        $conn->commit();
        return ['success' => true, 'message' => '重新分配成功'];

    } catch (Exception $e) {
        $conn->rollback();
        error_log("重新分配失敗: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * 發送重新分配通知給主任
 */
function sendDirectorReassignmentNotification($conn, $department_code, $student_data, $director) {
    try {
        $director_name = $director['name'] ?? '主任';
        $director_email = $director['email'] ?? '';
        $student_name = $student_data['name'] ?? '學生';

        $dept_stmt = $conn->prepare("SELECT name FROM departments WHERE code = ?");
        $dept_stmt->bind_param("s", $department_code);
        $dept_stmt->execute();
        $dept_result = $dept_stmt->get_result();
        $dept_row = $dept_result->fetch_assoc();
        $dept_stmt->close();
        $department_name = $dept_row['name'] ?? $department_code;

        $subject = "【康寧大學】學生重新分配通知 - 請盡快聯絡";

        $body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: 'Microsoft JhengHei', Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(90deg, #7ac9c7 0%, #956dbd 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px; }
                .alert-box { background: #d1ecf1; border-left: 4px solid #17a2b8; padding: 20px; margin: 20px 0; border-radius: 8px; }
                .info-box { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #667eea; }
                .button { display: inline-block; background: #667eea; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; margin-top: 20px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🔄 學生重新分配通知</h1>
                    <p>學生已重新分配給您的科系</p>
                </div>
                <div class='content'>
                    <p>親愛的 <strong>" . htmlspecialchars($director_name) . "</strong> 主任，您好！</p>
                    <div class='alert-box'>
                        <h3 style='margin-top: 0; color: #0c5460;'>📌 重新分配通知</h3>
                        <p style='font-size: 16px; font-weight: bold; color: #0c5460;'>
                            由於前一個科系超過 3 天未聯絡，系統已自動將學生重新分配給您的科系。
                        </p>
                    </div>
                    <div class='info-box'>
                        <h3 style='margin-top: 0; color: #667eea;'>📝 學生基本資料</h3>
                        <table style='width: 100%; border-collapse: collapse;'>
                            <tr>
                                <td style='padding: 8px 0; font-weight: bold; color: #555; width: 120px;'>學生姓名：</td>
                                <td style='padding: 8px 0; color: #333;'>" . htmlspecialchars($student_name) . "</td>
                            </tr>
                            <tr>
                                <td style='padding: 8px 0; font-weight: bold; color: #555;'>分配科系：</td>
                                <td style='padding: 8px 0; color: #333;'>" . htmlspecialchars($department_name) . "</td>
                            </tr>
                            <tr>
                                <td style='padding: 8px 0; font-weight: bold; color: #555;'>聯絡電話：</td>
                                <td style='padding: 8px 0; color: #333;'>" . htmlspecialchars($student_data['phone1'] ?? '未提供') . "</td>
                            </tr>
                        </table>
                    </div>
                    <div style='text-align: center; margin-top: 30px;'>
                        <a href='http://127.0.0.1/Topics-backend/frontend/enrollment_list.php' class='button'>前往後台查看 →</a>
                    </div>
                </div>
            </div>
        </body>
        </html>
        ";

        if (empty($director_email)) {
            return false;
        }
        $email_include = __DIR__ . '/../../Topics-frontend/frontend/includes/email_functions.php';
        if (!file_exists($email_include)) {
            error_log("reassign_uncontacted_functions: email_functions.php not found");
            return false;
        }
        require_once $email_include;
        return sendEmail($director_email, $subject, $body);

    } catch (Exception $e) {
        error_log("發送重新分配通知失敗: " . $e->getMessage());
        return false;
    }
}
