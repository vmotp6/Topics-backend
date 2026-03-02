<?php
/**
 * 自動分配學生到正取科系的主任進行評分
 * 邏輯：根據評分結果自動找到第一個正取的志願，分配給該科系主任
 */

/**
 * 根據評分結果自動分配學生給正取科系主任
 * @param mysqli $conn 資料庫連接
 * @param int $application_id 報名ID
 * @return array ['success' => true/false, 'message' => '說明', 'assigned_dept' => '科系代碼', 'director_id' => 主任ID]
 */
function autoAssignToAdmittedDepartmentDirector($conn, $application_id) {
    try {
        // 1. 檢查該學生是否已經分配給主任
        $assign_check = $conn->prepare("
            SELECT COUNT(*) as director_count
            FROM continued_admission_assignments
            WHERE application_id = ? AND reviewer_type = 'director'
        ");
        $assign_check->bind_param("i", $application_id);
        $assign_check->execute();
        $assign_result = $assign_check->get_result();
        $assign_row = $assign_result->fetch_assoc();
        $assign_check->close();

        if ($assign_row['director_count'] > 0) {
            return ['success' => false, 'message' => '學生已分配給主任'];
        }

        // 2. 獲取該學生的所有志願
        $choices_stmt = $conn->prepare("
            SELECT cac.choice_order, cac.department_code
            FROM continued_admission_choices cac
            WHERE cac.application_id = ?
            ORDER BY cac.choice_order ASC
        ");
        $choices_stmt->bind_param("i", $application_id);
        $choices_stmt->execute();
        $choices_result = $choices_stmt->get_result();

        $choices = [];
        while ($choice = $choices_result->fetch_assoc()) {
            $choices[] = $choice;
        }
        $choices_stmt->close();

        if (empty($choices)) {
            return ['success' => false, 'message' => '學生未填寫志願'];
        }

        // 3. 獲取該學生的總平均分數
        $avg_score_stmt = $conn->prepare("
            SELECT 
                AVG(self_intro_score + skills_score) as average_score
            FROM continued_admission_scores
            WHERE application_id = ?
        ");
        $avg_score_stmt->bind_param("i", $application_id);
        $avg_score_stmt->execute();
        $avg_score_result = $avg_score_stmt->get_result();
        $avg_score_data = $avg_score_result->fetch_assoc();
        $avg_score_stmt->close();

        $average_score = isset($avg_score_data['average_score']) ? floatval($avg_score_data['average_score']) : 0;

        // 4. 遍歷志願，找到第一個正取的科系
        foreach ($choices as $choice) {
            $dept_code = $choice['department_code'];

            // 獲取該科系的錄取標準
            $cutoff_stmt = $conn->prepare("
                SELECT COALESCE(cutoff_score, 60) as cutoff_score
                FROM continued_admission_score_settings
                WHERE department_code = ? AND YEAR(date) = YEAR(CURDATE())
                LIMIT 1
            ");
            $cutoff_stmt->bind_param("s", $dept_code);
            $cutoff_stmt->execute();
            $cutoff_result = $cutoff_stmt->get_result();
            $cutoff_data = $cutoff_result->fetch_assoc();
            $cutoff_stmt->close();

            $cutoff_score = isset($cutoff_data['cutoff_score']) ? floatval($cutoff_data['cutoff_score']) : 60;

            // 檢查該學生該科系是否達到錄取標準
            if ($average_score >= $cutoff_score) {
                // 找到第一個正取科系
                $dept_name_stmt = $conn->prepare("
                    SELECT name FROM departments WHERE code = ? LIMIT 1
                ");
                $dept_name_stmt->bind_param("s", $dept_code);
                $dept_name_stmt->execute();
                $dept_name_result = $dept_name_stmt->get_result();
                $dept_data = $dept_name_result->fetch_assoc();
                $dept_name_stmt->close();

                $dept_name = ($dept_data && isset($dept_data['name'])) ? $dept_data['name'] : $dept_code;

                // 5. 找到該科系的主任
                $director_stmt = $conn->prepare("
                    SELECT u.id, u.username, u.name
                    FROM users u
                    INNER JOIN director d ON u.id = d.user_id
                    WHERE d.department = ? AND u.role = 'DI'
                    LIMIT 1
                ");
                $director_stmt->bind_param("s", $dept_code);
                $director_stmt->execute();
                $director_result = $director_stmt->get_result();

                if ($director_result->num_rows == 0) {
                    $director_stmt->close();
                    continue; // 該科系無主任，繼續找下一個志願
                }

                $director = $director_result->fetch_assoc();
                $director_id = $director['id'];
                $director_stmt->close();

                // 6. 分配學生給主任
                try {
                    $conn->begin_transaction();

                    // 更新學生的分配科系
                    $update_stmt = $conn->prepare("
                        UPDATE continued_admission
                        SET assigned_department = ?
                        WHERE id = ?
                    ");
                    $update_stmt->bind_param("si", $dept_code, $application_id);
                    $update_stmt->execute();
                    $update_stmt->close();

                    // 插入主任的分配記錄
                    $insert_stmt = $conn->prepare("
                        INSERT INTO continued_admission_assignments
                        (application_id, reviewer_user_id, reviewer_type, assignment_order, assigned_at, assigned_by_user_id)
                        VALUES (?, ?, 'director', 3, NOW(), 0)
                    ");
                    $insert_stmt->bind_param("ii", $application_id, $director_id);
                    $insert_stmt->execute();
                    $insert_stmt->close();

                    $conn->commit();

                    return [
                        'success' => true,
                        'message' => '學生已自動分配給' . $dept_name . '科系主任進行評分',
                        'assigned_dept' => $dept_code,
                        'director_id' => $director_id,
                        'average_score' => round($average_score, 2)
                    ];

                } catch (Exception $e) {
                    $conn->rollback();
                    error_log('Auto assign error: ' . $e->getMessage());
                    return [
                        'success' => false,
                        'message' => '分配失敗：' . $e->getMessage()
                    ];
                }
            }
        }

        return [
            'success' => false,
            'message' => '學生所有志願均未達錄取標準，無法分配'
        ];

    } catch (Exception $e) {
        error_log('Auto assign exception: ' . $e->getMessage());
        return [
            'success' => false,
            'message' => '自動分配異常：' . $e->getMessage()
        ];
    }
}
?>
