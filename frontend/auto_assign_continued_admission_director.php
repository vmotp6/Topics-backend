<?php
/**
 * 根據評分結果自動分配學生給對應科系主任
 * 邏輯：找到第一個正取的志願，將學生分配給該科系主任進行評分
 */

require_once __DIR__ . '/session_config.php';
require_once '../../Topics-frontend/frontend/config.php';
require_once __DIR__ . '/includes/continued_admission_ranking.php';

try {
    $conn = getDatabaseConnection();

    // 檢查 continued_admission_assignments 表是否存在，如果不存在則創建
    $table_check = $conn->query("SHOW TABLES LIKE 'continued_admission_assignments'");
    if (!$table_check || $table_check->num_rows == 0) {
        $create_table_sql = "
            CREATE TABLE continued_admission_assignments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                application_id INT NOT NULL,
                reviewer_user_id INT NOT NULL,
                reviewer_type ENUM('teacher', 'director') NOT NULL, 
                assignment_order INT NOT NULL COMMENT '1=第一位老師, 2=第二位老師, 3=主任',
                assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                assigned_by_user_id INT NOT NULL,
                INDEX idx_application (application_id),
                INDEX idx_reviewer (reviewer_user_id),
                INDEX idx_type_order (reviewer_type, assignment_order),
                FOREIGN KEY (application_id) REFERENCES continued_admission(id) ON DELETE CASCADE,
                FOREIGN KEY (reviewer_user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (assigned_by_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        $conn->query($create_table_sql);
    }

    // 獲取所有續招報名記錄，按狀態分組
    $stmt = $conn->prepare("
        SELECT ca.id, ca.apply_no, ca.name, ca.status
        FROM continued_admission ca
        WHERE ca.status IN ('pending', 'PE')
        ORDER BY ca.id
    ");
    $stmt->execute();
    $result = $stmt->get_result();

    $assigned_count = 0;
    $awaiting_count = 0;

    while ($application = $result->fetch_assoc()) {
        $application_id = $application['id'];

        // 檢查該學生是否已有評分記錄
        $score_check = $conn->prepare("
            SELECT COUNT(*) as score_count
            FROM continued_admission_scores
            WHERE application_id = ?
        ");
        $score_check->bind_param("i", $application_id);
        $score_check->execute();
        $score_result = $score_check->get_result();
        $score_row = $score_result->fetch_assoc();
        $has_scores = $score_row['score_count'] > 0;
        $score_check->close();

        // 如果還沒有評分，先等待評分之後再自動分配
        if (!$has_scores) {
            $awaiting_count++;
            continue;
        }

        // 檢查是否已經分配
        $assign_check = $conn->prepare("
            SELECT COUNT(*) as assignment_count
            FROM continued_admission_assignments
            WHERE application_id = ? AND reviewer_type = 'director'
        ");
        $assign_check->bind_param("i", $application_id);
        $assign_check->execute();
        $assign_result = $assign_check->get_result();
        $assign_row = $assign_result->fetch_assoc();
        if ($assign_row['assignment_count'] > 0) {
            $assign_check->close();
            continue; // 已經分配，跳過
        }
        $assign_check->close();

        // 1. 獲取該學生的所有志願及評分結果
        $choices_stmt = $conn->prepare("
            SELECT cac.choice_order, cac.department_code, d.name as department_name
            FROM continued_admission_choices cac
            LEFT JOIN departments d ON cac.department_code = d.code
            WHERE cac.application_id = ?
            ORDER BY cac.choice_order ASC
        ");
        $choices_stmt->bind_param("i", $application_id);
        $choices_stmt->execute();
        $choices_result = $choices_stmt->get_result();

        $assigned_to_dept = null;
        $assigned_director_id = null;

        // 遍歷該學生的所有志願，找到第一個正取的科系
        while ($choice = $choices_result->fetch_assoc()) {
            $choice_dept_code = $choice['department_code'];
            
            // 獲取該科系的錄取標準和名額
            $dept_ranking = getDepartmentRanking($conn, $choice_dept_code);

            if (empty($dept_ranking) || empty($dept_ranking['applications'])) {
                continue; // 該科系沒有評分結果
            }

            // 查找該學生在該科系的排名
            $student_in_dept = null;
            $student_rank = null;
            foreach ($dept_ranking['applications'] as $idx => $app) {
                if ($app['id'] == $application_id) {
                    $student_in_dept = $app;
                    $student_rank = $idx + 1;
                    break;
                }
            }

            // 檢查該學生是否在該科系正取
            if ($student_in_dept && $student_in_dept['average_score'] >= $dept_ranking['cutoff_score'] && $student_rank <= $dept_ranking['total_quota']) {
                // 找到第一個正取的科系
                $assigned_to_dept = $choice_dept_code;

                // 2. 找到該科系的主任
                $director_stmt = $conn->prepare("
                    SELECT u.id, u.username, u.name
                    FROM users u
                    INNER JOIN director d ON u.id = d.user_id
                    WHERE d.department = ? AND u.role = 'DI'
                    LIMIT 1
                ");
                $director_stmt->bind_param("s", $assigned_to_dept);
                $director_stmt->execute();
                $director_result = $director_stmt->get_result();

                if ($director_result->num_rows > 0) {
                    $director = $director_result->fetch_assoc();
                    $assigned_director_id = $director['id'];
                    $director_stmt->close();
                    break; // 找到主任，跳出循環
                } else {
                    $director_stmt->close();
                    continue; // 沒有主任，繼續找下一個志願
                }
            }
        }

        $choices_stmt->close();

        // 3. 如果找到合適的科系和主任，則進行分配
        if ($assigned_to_dept && $assigned_director_id) {
            // 開始事務
            $conn->begin_transaction();

            try {
                // 更新 continued_admission 表
                $update_stmt = $conn->prepare("
                    UPDATE continued_admission
                    SET assigned_department = ?
                    WHERE id = ?
                ");
                $update_stmt->bind_param("si", $assigned_to_dept, $application_id);
                $update_stmt->execute();
                $update_stmt->close();

                // 插入分配記錄（主任評分）
                $insert_stmt = $conn->prepare("
                    INSERT INTO continued_admission_assignments
                    (application_id, reviewer_user_id, reviewer_type, assignment_order, assigned_at, assigned_by_user_id)
                    VALUES (?, ?, 'director', 3, NOW(), ?)
                ");
                $system_user_id = 0; // 系統自動分配，使用 0 表示
                $insert_stmt->bind_param("iii", $application_id, $assigned_director_id, $system_user_id);
                $insert_stmt->execute();
                $insert_stmt->close();

                // 提交事務
                $conn->commit();

                $assigned_count++;
            } catch (Exception $e) {
                $conn->rollback();
                error_log('Error assigning director for application ' . $application_id . ': ' . $e->getMessage());
            }
        }
    }

    $stmt->close();
    $conn->close();

    error_log("Auto assignment completed: $assigned_count assigned, $awaiting_count awaiting scores");

} catch (Exception $e) {
    error_log('Auto assignment script error: ' . $e->getMessage());
}
?>
