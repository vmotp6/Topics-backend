<?php
/**
 * 檢查未聯絡學生並處理通知與自動分配
 * 
 * 功能：
 * 1. 分配後 1 天沒有聯絡 → 發送通知
 * 2. 分配後 2 天沒有聯絡 → 再發送通知
 * 3. 分配後 3 天沒有聯絡 → 自動分配給下一個志願
 * 
 * 使用方式：
 * - 手動執行：http://127.0.0.1/Topics-backend/frontend/check_uncontacted_students.php
 * - 定時任務：設置 cron job 每天執行一次
 */

require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/../../Topics-frontend/frontend/config.php';
require_once __DIR__ . '/../../Topics-frontend/frontend/includes/enrollment_notification_functions.php';
require_once __DIR__ . '/includes/reassign_uncontacted_functions.php';

header('Content-Type: text/html; charset=utf-8');

// 允許通過 URL 參數設置測試模式（縮短時間間隔）
$test_mode = isset($_GET['test']) && $_GET['test'] === '1';
$days_1_notification = $test_mode ? 0.05 : 1;  // 第1天通知
$days_2_notification = $test_mode ? 0.1 : 2;   // 第2天通知
$days_3_reassign = $test_mode ? 0.15 : 3;      // 第3天轉下一意願

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>檢查未聯絡學生</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 2px solid #667eea; padding-bottom: 10px; }
        .section { margin: 20px 0; padding: 15px; background: #f9f9f9; border-radius: 5px; }
        .success { color: #28a745; font-weight: bold; }
        .warning { color: #ffc107; font-weight: bold; }
        .error { color: #dc3545; font-weight: bold; }
        .info { color: #17a2b8; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 10px; text-align: left; border: 1px solid #ddd; }
        th { background: #667eea; color: white; }
        tr:nth-child(even) { background: #f9f9f9; }
        .test-mode { background: #fff3cd; padding: 10px; border-radius: 5px; margin-bottom: 20px; border-left: 4px solid #ffc107; }
    </style>
</head>
<body>
<div class='container'>
    <h1>🔍 檢查未聯絡學生系統</h1>";

if ($test_mode) {
    echo "<div class='test-mode'>
        <strong>⚠️ 測試模式已啟用</strong><br>
        第1天通知：{$days_1_notification} 天（約 " . round($days_1_notification * 24) . " 小時）<br>
        第2天通知：{$days_2_notification} 天（約 " . round($days_2_notification * 24) . " 小時）<br>
        第3天轉下一意願：{$days_3_reassign} 天（約 " . round($days_3_reassign * 24) . " 小時）<br>
        <a href='?test=0'>切換到正常模式</a>
    </div>";
} else {
    echo "<div class='info'>
        正常模式：第1天通知、第2天通知、第3天轉下一意願<br>
        <a href='?test=1'>切換到測試模式（縮短時間間隔）</a>
    </div>";
}

try {
    $conn = getDatabaseConnection();
    
    // 獲取當前時間
    $now = new DateTime();

    // 起算日：已分配老師 → created_at（主任收到表單）；未分配老師 → updated_at（含剛轉派）
    $assignment_start_sql = "CASE WHEN ei.assigned_teacher_id IS NOT NULL THEN ei.created_at ELSE ei.updated_at END";

    // ==========================================
    // 1. 檢查分配後 1 天沒有聯絡的學生（發送通知）
    // ==========================================
    echo "<div class='section'>";
    echo "<h2>📧 檢查1天未聯絡的學生（發送通知）</h2>";

    $sql_1day = "
        SELECT 
            ei.id,
            ei.name,
            ei.phone1,
            ei.email,
            ei.assigned_department,
            ei.assigned_teacher_id,
            d.name AS department_name,
            u.name AS assigned_teacher_name,
            u.email AS assigned_teacher_email,
            dir.user_id AS director_id,
            dir_user.name AS director_name,
            dir_user.email AS director_email,
            TIMESTAMPDIFF(HOUR, $assignment_start_sql, NOW()) AS hours_since_assigned
        FROM enrollment_intention ei
        LEFT JOIN departments d ON ei.assigned_department = d.code
        LEFT JOIN user u ON ei.assigned_teacher_id = u.id
        LEFT JOIN director dir ON ei.assigned_department = dir.department
        LEFT JOIN user dir_user ON dir.user_id = dir_user.id
        WHERE ei.assigned_department IS NOT NULL
        AND ei.assigned_department != ''
        AND NOT EXISTS (
            SELECT 1 
            FROM enrollment_contact_logs ecl 
            WHERE ecl.enrollment_id = ei.id
        )
        AND TIMESTAMPDIFF(DAY, $assignment_start_sql, NOW()) >= ?
        AND TIMESTAMPDIFF(DAY, $assignment_start_sql, NOW()) < ?
        ORDER BY $assignment_start_sql ASC
    ";

    $stmt_1day = $conn->prepare($sql_1day);
    $stmt_1day->bind_param("dd", $days_1_notification, $days_2_notification);
    $stmt_1day->execute();
    $result_1day = $stmt_1day->get_result();
    $students_1day = $result_1day->fetch_all(MYSQLI_ASSOC);

    if (empty($students_1day)) {
        echo "<p class='info'>✓ 沒有找到需要發送通知的學生（1天未聯絡）</p>";
    } else {
        echo "<p class='warning'>找到 " . count($students_1day) . " 位需要發送通知的學生（第1天）</p>";
        echo "<table>";
        echo "<tr><th>學生ID</th><th>姓名</th><th>科系</th><th>分配給</th><th>已過時間</th><th>操作</th></tr>";
        $notification_sent_1 = 0;
        $notification_failed_1 = 0;
        foreach ($students_1day as $student) {
            $hours = $student['hours_since_assigned'];
            $days = round($hours / 24, 1);
            $recipient_name = $student['assigned_teacher_name'] ?? $student['director_name'] ?? '未知';
            $recipient_email = $student['assigned_teacher_email'] ?? $student['director_email'] ?? null;
            $is_teacher = !empty($student['assigned_teacher_id']);
            echo "<tr>";
            echo "<td>{$student['id']}</td>";
            echo "<td>{$student['name']}</td>";
            echo "<td>{$student['department_name']} ({$student['assigned_department']})</td>";
            echo "<td>" . ($is_teacher ? "老師：{$recipient_name}" : "主任：{$recipient_name}") . "</td>";
            echo "<td>{$days} 天（{$hours} 小時）</td>";
            if (empty($recipient_email)) {
                echo "<td class='error'>✗ 無法發送：收件人沒有郵箱</td>";
                $notification_failed_1++;
            } else {
                $student_data = ['name' => $student['name'], 'phone1' => $student['phone1'] ?? '', 'email' => $student['email'] ?? ''];
                $email_sent = $is_teacher
                    ? sendTeacherReminderNotification($conn, $student['assigned_teacher_id'], $student_data, $days)
                    : sendDirectorReminderNotification($conn, $student['assigned_department'], $student_data, $days);
                if ($email_sent) {
                    echo "<td class='success'>✓ 通知已發送</td>";
                    $notification_sent_1++;
                } else {
                    echo "<td class='error'>✗ 通知發送失敗</td>";
                    $notification_failed_1++;
                }
            }
            echo "</tr>";
        }
        echo "</table>";
        echo "<p><strong>統計：</strong>成功發送 {$notification_sent_1} 封，失敗 {$notification_failed_1} 封</p>";
    }
    echo "</div>";

    // ==========================================
    // 2. 檢查分配後 2 天沒有聯絡的學生（再發送通知）
    // ==========================================
    echo "<div class='section'>";
    echo "<h2>📧 檢查2天未聯絡的學生（再發送通知）</h2>";

    $sql_2days = "
        SELECT 
            ei.id,
            ei.name,
            ei.phone1,
            ei.email,
            ei.assigned_department,
            ei.assigned_teacher_id,
            d.name AS department_name,
            u.name AS assigned_teacher_name,
            u.email AS assigned_teacher_email,
            dir.user_id AS director_id,
            dir_user.name AS director_name,
            dir_user.email AS director_email,
            TIMESTAMPDIFF(HOUR, $assignment_start_sql, NOW()) AS hours_since_assigned
        FROM enrollment_intention ei
        LEFT JOIN departments d ON ei.assigned_department = d.code
        LEFT JOIN user u ON ei.assigned_teacher_id = u.id
        LEFT JOIN director dir ON ei.assigned_department = dir.department
        LEFT JOIN user dir_user ON dir.user_id = dir_user.id
        WHERE ei.assigned_department IS NOT NULL
        AND ei.assigned_department != ''
        AND NOT EXISTS (
            SELECT 1 
            FROM enrollment_contact_logs ecl 
            WHERE ecl.enrollment_id = ei.id
        )
        AND TIMESTAMPDIFF(DAY, $assignment_start_sql, NOW()) >= ?
        AND TIMESTAMPDIFF(DAY, $assignment_start_sql, NOW()) < ?
        ORDER BY $assignment_start_sql ASC
    ";

    $stmt_2days = $conn->prepare($sql_2days);
    $stmt_2days->bind_param("dd", $days_2_notification, $days_3_reassign);
    $stmt_2days->execute();
    $result_2days = $stmt_2days->get_result();
    $students_2days = $result_2days->fetch_all(MYSQLI_ASSOC);

    if (empty($students_2days)) {
        echo "<p class='info'>✓ 沒有找到需要發送通知的學生（2天未聯絡）</p>";
    } else {
        echo "<p class='warning'>找到 " . count($students_2days) . " 位需要發送通知的學生（第2天）</p>";
        echo "<table>";
        echo "<tr><th>學生ID</th><th>姓名</th><th>科系</th><th>分配給</th><th>已過時間</th><th>操作</th></tr>";
        
        $notification_sent = 0;
        $notification_failed = 0;
        
        foreach ($students_2days as $student) {
            $hours = $student['hours_since_assigned'];
            $days = round($hours / 24, 1);
            
            // 決定發送給誰：如果有 assigned_teacher_id，發給老師；否則發給主任
            $recipient_id = $student['assigned_teacher_id'] ?? $student['director_id'];
            $recipient_name = $student['assigned_teacher_name'] ?? $student['director_name'] ?? '未知';
            $recipient_email = $student['assigned_teacher_email'] ?? $student['director_email'] ?? null;
            $is_teacher = !empty($student['assigned_teacher_id']);
            
            echo "<tr>";
            echo "<td>{$student['id']}</td>";
            echo "<td>{$student['name']}</td>";
            echo "<td>{$student['department_name']} ({$student['assigned_department']})</td>";
            echo "<td>" . ($is_teacher ? "老師：{$recipient_name}" : "主任：{$recipient_name}") . "</td>";
            echo "<td>{$days} 天（{$hours} 小時）</td>";
            
            if (empty($recipient_email)) {
                echo "<td class='error'>✗ 無法發送：收件人沒有郵箱</td>";
                $notification_failed++;
            } else {
                // 發送通知郵件
                $student_data = [
                    'name' => $student['name'],
                    'phone1' => $student['phone1'] ?? '',
                    'email' => $student['email'] ?? ''
                ];
                
                $email_sent = false;
                if ($is_teacher) {
                    $email_sent = sendTeacherReminderNotification($conn, $student['assigned_teacher_id'], $student_data, $days);
                } else {
                    $email_sent = sendDirectorReminderNotification($conn, $student['assigned_department'], $student_data, $days);
                }
                
                if ($email_sent) {
                    echo "<td class='success'>✓ 通知已發送</td>";
                    $notification_sent++;
                } else {
                    echo "<td class='error'>✗ 通知發送失敗</td>";
                    $notification_failed++;
                }
            }
            echo "</tr>";
        }
        
        echo "</table>";
        echo "<p><strong>統計：</strong>成功發送 {$notification_sent} 封，失敗 {$notification_failed} 封</p>";
    }
    
    echo "</div>";
    
    // ==========================================
    // 3. 檢查分配後 3 天沒有聯絡的學生（自動分配給下一個志願）
    // ==========================================
    echo "<div class='section'>";
    echo "<h2>🔄 檢查3天未聯絡的學生（自動重新分配）</h2>";
    
    // 三天起算：已分配老師 → 用 created_at（主任收到表單）；未分配老師（含剛轉派）→ 用 updated_at
    $sql_3days = "
        SELECT 
            ei.id,
            ei.name,
            ei.assigned_department,
            ei.assigned_teacher_id,
            CASE WHEN ei.assigned_teacher_id IS NOT NULL THEN ei.created_at ELSE ei.updated_at END AS assignment_start,
            d.name AS department_name,
            TIMESTAMPDIFF(HOUR, CASE WHEN ei.assigned_teacher_id IS NOT NULL THEN ei.created_at ELSE ei.updated_at END, NOW()) AS hours_since_assigned
        FROM enrollment_intention ei
        LEFT JOIN departments d ON ei.assigned_department = d.code
        WHERE ei.assigned_department IS NOT NULL
        AND ei.assigned_department != ''
        AND NOT EXISTS (
            SELECT 1 
            FROM enrollment_contact_logs ecl 
            WHERE ecl.enrollment_id = ei.id
        )
        AND TIMESTAMPDIFF(DAY, CASE WHEN ei.assigned_teacher_id IS NOT NULL THEN ei.created_at ELSE ei.updated_at END, NOW()) >= ?
        ORDER BY assignment_start ASC
    ";
    
    $stmt_3days = $conn->prepare($sql_3days);
    $stmt_3days->bind_param("d", $days_3_reassign);
    $stmt_3days->execute();
    $result_3days = $stmt_3days->get_result();
    $students_3days = $result_3days->fetch_all(MYSQLI_ASSOC);
    
    if (empty($students_3days)) {
        echo "<p class='info'>✓ 沒有找到需要重新分配的學生（3天未聯絡）</p>";
    } else {
        echo "<p class='warning'>找到 " . count($students_3days) . " 位需要重新分配的學生</p>";
        echo "<table>";
        echo "<tr><th>學生ID</th><th>姓名</th><th>當前科系</th><th>已過時間</th><th>下一個志願</th><th>操作結果</th></tr>";
        
        $reassigned_count = 0;
        $reassign_failed = 0;
        
        foreach ($students_3days as $student) {
            $hours = $student['hours_since_assigned'];
            $days = round($hours / 24, 1);
            
            echo "<tr>";
            echo "<td>{$student['id']}</td>";
            echo "<td>{$student['name']}</td>";
            echo "<td>{$student['department_name']} ({$student['assigned_department']})</td>";
            echo "<td>{$days} 天（{$hours} 小時）</td>";
            
            // 獲取下一個志願
            $next_choice = getNextEnrollmentChoice($conn, $student['id'], $student['assigned_department']);
            
            if ($next_choice) {
                echo "<td>{$next_choice['department_name']} ({$next_choice['department_code']})</td>";
                
                // 執行重新分配（傳入 next_choice 以寫入歷程的 choice_order）
                $reassign_result = reassignToNextChoice($conn, $student['id'], $next_choice['department_code'], $student, $next_choice);
                
                if ($reassign_result['success']) {
                    echo "<td class='success'>✓ 已重新分配給 {$next_choice['department_name']}</td>";
                    $reassigned_count++;
                } else {
                    echo "<td class='error'>✗ 重新分配失敗：{$reassign_result['message']}</td>";
                    $reassign_failed++;
                }
            } else {
                echo "<td class='error'>沒有下一個志願</td>";
                echo "<td class='error'>✗ 無法重新分配</td>";
                $reassign_failed++;
            }
            
            echo "</tr>";
        }
        
        echo "</table>";
        echo "<p><strong>統計：</strong>成功重新分配 {$reassigned_count} 位，失敗 {$reassign_failed} 位</p>";
    }
    
    echo "</div>";
    
    // ==========================================
    // 4. 顯示所有已分配但未聯絡的學生（參考資訊）
    // ==========================================
    echo "<div class='section'>";
    echo "<h2>📊 所有已分配但未聯絡的學生（參考）</h2>";
    
    $sql_all = "
        SELECT 
            ei.id,
            ei.name,
            ei.assigned_department,
            ei.assigned_teacher_id,
            CASE WHEN ei.assigned_teacher_id IS NOT NULL THEN ei.created_at ELSE ei.updated_at END AS assignment_start,
            d.name AS department_name,
            TIMESTAMPDIFF(HOUR, CASE WHEN ei.assigned_teacher_id IS NOT NULL THEN ei.created_at ELSE ei.updated_at END, NOW()) AS hours_since_assigned
        FROM enrollment_intention ei
        LEFT JOIN departments d ON ei.assigned_department = d.code
        WHERE ei.assigned_department IS NOT NULL
        AND ei.assigned_department != ''
        AND NOT EXISTS (
            SELECT 1 
            FROM enrollment_contact_logs ecl 
            WHERE ecl.enrollment_id = ei.id
        )
        ORDER BY assignment_start ASC
    ";
    
    $result_all = $conn->query($sql_all);
    $students_all = $result_all->fetch_all(MYSQLI_ASSOC);
    
    if (empty($students_all)) {
        echo "<p class='info'>✓ 沒有未聯絡的學生</p>";
    } else {
        echo "<p>共 " . count($students_all) . " 位未聯絡的學生</p>";
        echo "<table>";
        echo "<tr><th>學生ID</th><th>姓名</th><th>科系</th><th>分配時間</th><th>已過時間</th><th>狀態</th></tr>";
        
        foreach ($students_all as $student) {
            $hours = $student['hours_since_assigned'];
            $days = round($hours / 24, 1);
            
            $status = '';
            $status_class = '';
            if ($days >= $days_3_reassign) {
                $status = '需要重新分配（≥3天）';
                $status_class = 'error';
            } elseif ($days >= $days_2_notification) {
                $status = '需要發送通知（第2天）';
                $status_class = 'warning';
            } elseif ($days >= $days_1_notification) {
                $status = '需要發送通知（第1天）';
                $status_class = 'warning';
            } else {
                $status = '正常';
                $status_class = 'info';
            }
            
            echo "<tr>";
            echo "<td>{$student['id']}</td>";
            echo "<td>{$student['name']}</td>";
            echo "<td>{$student['department_name']} ({$student['assigned_department']})</td>";
            echo "<td>" . ($student['assignment_start'] ?? $student['created_at'] ?? '') . "</td>";
            echo "<td>{$days} 天（{$hours} 小時）</td>";
            echo "<td class='{$status_class}'>{$status}</td>";
            echo "</tr>";
        }
        
        echo "</table>";
    }
    
    echo "</div>";
    
    echo "<div class='section'>";
    echo "<h2>✅ 檢查完成</h2>";
    echo "<p>執行時間：" . date('Y-m-d H:i:s') . "</p>";
    echo "<p><a href='?test=" . ($test_mode ? '0' : '1') . "'>" . ($test_mode ? '切換到正常模式' : '切換到測試模式') . "</a></p>";
    echo "</div>";
    
    $conn->close();
    
} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<h2>❌ 發生錯誤</h2>";
    echo "<p>錯誤訊息：" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>錯誤堆疊：<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre></p>";
    echo "</div>";
}

echo "</div></body></html>";

/**
 * 發送提醒通知給主任
 */
function sendDirectorReminderNotification($conn, $department_code, $student_data, $days) {
    try {
        // 查詢主任資訊
        $director_stmt = $conn->prepare("
            SELECT u.id, u.name, u.email, u.username, d.name AS department_name
            FROM director dir
            INNER JOIN user u ON dir.user_id = u.id
            INNER JOIN departments d ON dir.department = d.code
            WHERE dir.department = ?
            LIMIT 1
        ");
        $director_stmt->bind_param("s", $department_code);
        $director_stmt->execute();
        $director_result = $director_stmt->get_result();
        $director = $director_result->fetch_assoc();
        
        if (!$director || empty($director['email'])) {
            return false;
        }
        
        $director_name = $director['name'] ?? $director['username'] ?? '主任';
        $director_email = $director['email'];
        $department_name = $director['department_name'] ?? $department_code;
        $student_name = $student_data['name'] ?? '學生';
        
        $subject = "【康寧大學】提醒：學生已分配 {$days} 天尚未聯絡";
        
        $body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: 'Microsoft JhengHei', Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(90deg, #ffc107 0%, #ff9800 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px; }
                .alert-box { background: #fff3cd; border-left: 4px solid #ffc107; padding: 20px; margin: 20px 0; border-radius: 8px; }
                .info-box { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #667eea; }
                .button { display: inline-block; background: #667eea; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; margin-top: 20px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>⏰ 聯絡提醒通知</h1>
                    <p>學生已分配 {$days} 天尚未聯絡</p>
                </div>
                <div class='content'>
                    <p>親愛的 <strong>{$director_name}</strong> 主任，您好！</p>
                    
                    <div class='alert-box'>
                        <h3 style='margin-top: 0; color: #856404;'>⚠️ 重要提醒</h3>
                        <p style='font-size: 16px; font-weight: bold; color: #856404;'>
                            學生 <strong>{$student_name}</strong> 已分配給您 <strong>{$days} 天</strong>，但尚未有任何聯絡記錄。
                        </p>
                        <p style='color: #856404;'>
                            如果超過 3 天仍未聯絡，系統將自動將該學生分配給下一個志願科系。
                        </p>
                    </div>
                    
                    <div class='info-box'>
                        <h3 style='margin-top: 0; color: #667eea;'>📝 學生基本資料</h3>
                        <table style='width: 100%; border-collapse: collapse;'>
                            <tr>
                                <td style='padding: 8px 0; font-weight: bold; color: #555; width: 120px;'>學生姓名：</td>
                                <td style='padding: 8px 0; color: #333;'>{$student_name}</td>
                            </tr>
                            <tr>
                                <td style='padding: 8px 0; font-weight: bold; color: #555;'>分配科系：</td>
                                <td style='padding: 8px 0; color: #333;'>{$department_name}</td>
                            </tr>
                            <tr>
                                <td style='padding: 8px 0; font-weight: bold; color: #555;'>聯絡電話：</td>
                                <td style='padding: 8px 0; color: #333;'>" . htmlspecialchars($student_data['phone1'] ?? '未提供') . "</td>
                            </tr>
                        </table>
                    </div>
                    
                    <div style='text-align: center; margin-top: 30px;'>
                        <a href='http://127.0.0.1/Topics-backend/frontend/enrollment_list.php' class='button'>
                            前往後台查看 →
                        </a>
                    </div>
                </div>
            </div>
        </body>
        </html>
        ";
        
        require_once __DIR__ . '/../../Topics-frontend/frontend/includes/email_functions.php';
        return sendEmail($director_email, $subject, $body);
        
    } catch (Exception $e) {
        error_log("發送主任提醒通知失敗: " . $e->getMessage());
        return false;
    }
}

/**
 * 發送提醒通知給老師
 */
function sendTeacherReminderNotification($conn, $teacher_id, $student_data, $days) {
    try {
        // 查詢老師資訊
        $teacher_stmt = $conn->prepare("
            SELECT u.id, u.name, u.email, u.username, d.name AS department_name
            FROM user u
            LEFT JOIN teacher t ON u.id = t.user_id
            LEFT JOIN departments d ON t.department = d.code
            WHERE u.id = ?
            LIMIT 1
        ");
        $teacher_stmt->bind_param("i", $teacher_id);
        $teacher_stmt->execute();
        $teacher_result = $teacher_stmt->get_result();
        $teacher = $teacher_result->fetch_assoc();
        
        if (!$teacher || empty($teacher['email'])) {
            return false;
        }
        
        $teacher_name = $teacher['name'] ?? $teacher['username'] ?? '老師';
        $teacher_email = $teacher['email'];
        $department_name = $teacher['department_name'] ?? '未知科系';
        $student_name = $student_data['name'] ?? '學生';
        
        $subject = "【康寧大學】提醒：學生已分配 {$days} 天尚未聯絡";
        
        $body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: 'Microsoft JhengHei', Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(90deg, #ffc107 0%, #ff9800 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px; }
                .alert-box { background: #fff3cd; border-left: 4px solid #ffc107; padding: 20px; margin: 20px 0; border-radius: 8px; }
                .info-box { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #667eea; }
                .button { display: inline-block; background: #667eea; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; margin-top: 20px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>⏰ 聯絡提醒通知</h1>
                    <p>學生已分配 {$days} 天尚未聯絡</p>
                </div>
                <div class='content'>
                    <p>親愛的 <strong>{$teacher_name}</strong> 老師，您好！</p>
                    
                    <div class='alert-box'>
                        <h3 style='margin-top: 0; color: #856404;'>⚠️ 重要提醒</h3>
                        <p style='font-size: 16px; font-weight: bold; color: #856404;'>
                            學生 <strong>{$student_name}</strong> 已分配給您 <strong>{$days} 天</strong>，但尚未有任何聯絡記錄。
                        </p>
                        <p style='color: #856404;'>
                            請盡快與學生或家長聯絡，記錄聯絡內容。
                        </p>
                    </div>
                    
                    <div class='info-box'>
                        <h3 style='margin-top: 0; color: #667eea;'>📝 學生基本資料</h3>
                        <table style='width: 100%; border-collapse: collapse;'>
                            <tr>
                                <td style='padding: 8px 0; font-weight: bold; color: #555; width: 120px;'>學生姓名：</td>
                                <td style='padding: 8px 0; color: #333;'>{$student_name}</td>
                            </tr>
                            <tr>
                                <td style='padding: 8px 0; font-weight: bold; color: #555;'>所屬科系：</td>
                                <td style='padding: 8px 0; color: #333;'>{$department_name}</td>
                            </tr>
                            <tr>
                                <td style='padding: 8px 0; font-weight: bold; color: #555;'>聯絡電話：</td>
                                <td style='padding: 8px 0; color: #333;'>" . htmlspecialchars($student_data['phone1'] ?? '未提供') . "</td>
                            </tr>
                        </table>
                    </div>
                    
                    <div style='text-align: center; margin-top: 30px;'>
                        <a href='http://127.0.0.1/Topics-backend/frontend/enrollment_list.php' class='button'>
                            前往後台查看 →
                        </a>
                    </div>
                </div>
            </div>
        </body>
        </html>
        ";
        
        require_once __DIR__ . '/../../Topics-frontend/frontend/includes/email_functions.php';
        return sendEmail($teacher_email, $subject, $body);
        
    } catch (Exception $e) {
        error_log("發送老師提醒通知失敗: " . $e->getMessage());
        return false;
    }
}
?>

