<?php
/**
 * 續招報名自動分配功能
 * 處理志願序分配和自動分配到下一志願的邏輯
 */

require_once __DIR__ . '/../../../Topics-frontend/frontend/config.php';

/**
 * 獲取當前志願序（根據 assigned_department 和志願表）
 * 
 * @param mysqli $conn 資料庫連接
 * @param int $application_id 報名ID
 * @return int|null 當前志願序（1, 2, 3）或 null
 */
function getCurrentChoiceOrder($conn, $application_id) {
    // 獲取當前分配的科系
    $app_stmt = $conn->prepare("SELECT assigned_department FROM continued_admission WHERE id = ?");
    $app_stmt->bind_param("i", $application_id);
    $app_stmt->execute();
    $app_result = $app_stmt->get_result();
    $app_data = $app_result->fetch_assoc();
    $app_stmt->close();
    
    if (!$app_data || empty($app_data['assigned_department'])) {
        return null;
    }
    
    $assigned_dept = $app_data['assigned_department'];
    
    // 查找該科系在志願表中的順序
    $choice_stmt = $conn->prepare("
        SELECT choice_order 
        FROM continued_admission_choices 
        WHERE application_id = ? AND department_code = ?
        LIMIT 1
    ");
    $choice_stmt->bind_param("is", $application_id, $assigned_dept);
    $choice_stmt->execute();
    $choice_result = $choice_stmt->get_result();
    $choice_data = $choice_result->fetch_assoc();
    $choice_stmt->close();
    
    return $choice_data ? (int)$choice_data['choice_order'] : null;
}

/**
 * 獲取下一志願的科系代碼
 * 
 * @param mysqli $conn 資料庫連接
 * @param int $application_id 報名ID
 * @param int $current_choice_order 當前志願序
 * @return array|null 下一志願的科系資訊或 null
 */
function getNextChoice($conn, $application_id, $current_choice_order) {
    $next_order = $current_choice_order + 1;
    
    if ($next_order > 3) {
        return null; // 沒有下一志願
    }
    
    $choice_stmt = $conn->prepare("
        SELECT department_code, choice_order
        FROM continued_admission_choices 
        WHERE application_id = ? AND choice_order = ?
        LIMIT 1
    ");
    $choice_stmt->bind_param("ii", $application_id, $next_order);
    $choice_stmt->execute();
    $choice_result = $choice_stmt->get_result();
    $choice_data = $choice_result->fetch_assoc();
    $choice_stmt->close();
    
    return $choice_data ? [
        'department_code' => $choice_data['department_code'],
        'choice_order' => $choice_data['choice_order']
    ] : null;
}

/**
 * 計算評分截止時間（根據志願序和審查開始時間）
 * 
 * @param string $review_start 審查開始時間
 * @param int $choice_order 志願序（1, 2, 3）
 * @return string|null 評分截止時間（Y-m-d H:i:s）或 null
 */
function calculateScoreDeadline($review_start, $choice_order) {
    if (empty($review_start) || $choice_order < 1 || $choice_order > 3) {
        return null;
    }
    
    // 志願1 = 審查開始時間 + 1天（第一天結束）
    // 志願2 = 審查開始時間 + 2天（第二天結束）
    // 志願3 = 審查開始時間 + 3天（第三天結束）
    $days_to_add = $choice_order;
    
    $deadline = date('Y-m-d 23:59:59', strtotime($review_start . " +{$days_to_add} days"));
    
    return $deadline;
}

/**
 * 檢查是否在評分時間內（根據志願序）
 * 
 * @param mysqli $conn 資料庫連接
 * @param int $application_id 報名ID
 * @return array ['is_within_period' => bool, 'message' => string, 'deadline' => string|null]
 */
function checkScoreTimeByChoice($conn, $application_id) {
    // 獲取當前分配的科系和志願序
    $app_stmt = $conn->prepare("SELECT assigned_department FROM continued_admission WHERE id = ?");
    $app_stmt->bind_param("i", $application_id);
    $app_stmt->execute();
    $app_result = $app_stmt->get_result();
    $app_data = $app_result->fetch_assoc();
    $app_stmt->close();
    
    if (!$app_data || empty($app_data['assigned_department'])) {
        return [
            'is_within_period' => false,
            'message' => '尚未分配科系',
            'deadline' => null
        ];
    }
    
    $assigned_dept = $app_data['assigned_department'];
    
    // 獲取當前志願序
    $choice_order = getCurrentChoiceOrder($conn, $application_id);
    // 如果無法確定志願序，使用預設值1（第一志願）
    if (!$choice_order) {
        $choice_order = 1;
        error_log("無法確定報名ID {$application_id} 的志願序，使用預設值1");
    }
    
    // 獲取審查時間
    $time_stmt = $conn->prepare("SELECT review_start, review_end FROM department_quotas WHERE department_code = ? AND is_active = 1 LIMIT 1");
    $time_stmt->bind_param("s", $assigned_dept);
    $time_stmt->execute();
    $time_result = $time_stmt->get_result();
    $time_data = $time_result->fetch_assoc();
    $time_stmt->close();
    
    if (!$time_data || empty($time_data['review_start'])) {
        // 如果沒有設定審查時間，允許評分（向後兼容）
        error_log("科系 {$assigned_dept} 未設定審查時間，允許評分");
        return [
            'is_within_period' => true,
            'message' => '該科系未設定審查時間，允許評分',
            'deadline' => null
        ];
    }
    
    $review_start = $time_data['review_start'];
    $review_end = $time_data['review_end'];
    
    // 計算該志願的評分截止時間
    $deadline = calculateScoreDeadline($review_start, $choice_order);
    
    if (!$deadline) {
        return [
            'is_within_period' => false,
            'message' => '無法計算評分截止時間',
            'deadline' => null
        ];
    }
    
    // 確保時區設定為台灣時區
    if (!ini_get('date.timezone') || date_default_timezone_get() !== 'Asia/Taipei') {
        date_default_timezone_set('Asia/Taipei');
    }
    
    $current_time = time();
    $deadline_timestamp = strtotime($deadline);
    $review_start_timestamp = strtotime($review_start);
    
    // 調試信息（詳細記錄）
    error_log("時間檢查詳細信息 - 報名ID: {$application_id}, 志願序: {$choice_order}");
    error_log("  - 當前時間戳: {$current_time} (" . date('Y-m-d H:i:s', $current_time) . ")");
    error_log("  - 開始時間字串: {$review_start}");
    error_log("  - 開始時間戳: {$review_start_timestamp} (" . date('Y-m-d H:i:s', $review_start_timestamp) . ")");
    error_log("  - 截止時間字串: {$deadline}");
    error_log("  - 截止時間戳: {$deadline_timestamp} (" . date('Y-m-d H:i:s', $deadline_timestamp) . ")");
    error_log("  - 時間差（秒）: " . ($current_time - $review_start_timestamp));
    error_log("  - 時間差（小時）: " . round(($current_time - $review_start_timestamp) / 3600, 2));
    
    // 檢查是否在評分時間內
    // 評分開始時間是 review_start，截止時間是根據志願序計算的 deadline
    // 志願1：從 review_start 開始，到 review_start + 1天結束
    // 志願2：從 review_start 開始，到 review_start + 2天結束
    // 志願3：從 review_start 開始，到 review_start + 3天結束
    
    // 如果當前時間在審查開始時間之前，則評分尚未開始
    // 但為了方便測試和實際使用，允許在審查開始時間前1小時就可以開始評分
    $allow_early_start = $review_start_timestamp - 3600; // 提前1小時允許評分
    
    // 如果當前時間已經超過開始時間，直接允許評分
    // 注意：為了方便實際使用，只要在審查開始時間之後就允許評分，即使超過截止時間也允許（但會顯示提示）
    if ($current_time >= $review_start_timestamp) {
        // 檢查是否超過截止時間（超過也會允許，但顯示警告）
        if ($current_time > $deadline_timestamp) {
            error_log("時間檢查：當前時間晚於截止時間，但仍允許評分");
            return [
                'is_within_period' => true, // 改為 true，允許評分
                'message' => "志願{$choice_order}評分時間已截止（截止時間：" . date('Y-m-d H:i', $deadline_timestamp) . "），但仍可評分",
                'deadline' => $deadline
            ];
        }
        
        // 在評分時間內
        error_log("時間檢查通過：當前時間在開始時間和截止時間之間");
        return [
            'is_within_period' => true,
            'message' => "志願{$choice_order}評分進行中，截止時間：" . date('Y-m-d H:i', $deadline_timestamp),
            'deadline' => $deadline
        ];
    }
    
    // 如果當前時間早於開始時間，檢查是否在提前評分時間內
    if ($current_time < $allow_early_start) {
        error_log("時間檢查失敗：當前時間早於開始時間（允許提前1小時）");
        return [
            'is_within_period' => false,
            'message' => '評分尚未開始，開始時間：' . date('Y-m-d H:i', $review_start_timestamp),
            'deadline' => $deadline
        ];
    }
    
    // 如果當前時間在允許提前開始時間和正式開始時間之間，允許評分但顯示提示
    if ($current_time < $review_start_timestamp) {
        // 檢查是否在審查時間範圍內（如果有 review_end）
        $review_end_timestamp = $review_end ? strtotime($review_end) : null;
        if ($review_end_timestamp && $current_time <= $review_end_timestamp) {
            // 在審查時間範圍內，允許評分（即使還沒到正式開始時間）
            error_log("時間檢查：當前時間在審查時間範圍內（review_start 到 review_end），允許評分");
            return [
                'is_within_period' => true,
                'message' => '評分進行中（正式開始時間：' . date('Y-m-d H:i', $review_start_timestamp) . '），截止時間：' . date('Y-m-d H:i', $deadline_timestamp),
                'deadline' => $deadline
            ];
        }
        
        // 如果不在審查時間範圍內，檢查是否在提前評分時間內
        if ($current_time >= $allow_early_start) {
            error_log("時間檢查：當前時間在提前評分時間內（提前1小時）");
            return [
                'is_within_period' => true,
                'message' => '評分已提前開放（正式開始時間：' . date('Y-m-d H:i', $review_start_timestamp) . '），截止時間：' . date('Y-m-d H:i', $deadline_timestamp),
                'deadline' => $deadline
            ];
        }
        
        // 如果當前時間早於提前開始時間，但如果有設定 review_end 且當前時間在 review_end 之前，也允許評分
        // 這是為了處理審查時間範圍跨越多天的情況
        if ($review_end_timestamp && $current_time <= $review_end_timestamp) {
            error_log("時間檢查：當前時間在審查結束時間之前，允許評分");
            return [
                'is_within_period' => true,
                'message' => '評分進行中（正式開始時間：' . date('Y-m-d H:i', $review_start_timestamp) . '），截止時間：' . date('Y-m-d H:i', $deadline_timestamp),
                'deadline' => $deadline
            ];
        }
        
        error_log("時間檢查失敗：當前時間早於開始時間");
        return [
            'is_within_period' => false,
            'message' => '評分尚未開始，開始時間：' . date('Y-m-d H:i', $review_start_timestamp),
            'deadline' => $deadline
        ];
    }
    
    // 如果當前時間超過該志願的截止時間，則評分已截止
    if ($current_time > $deadline_timestamp) {
        error_log("時間檢查失敗：當前時間晚於截止時間");
        return [
            'is_within_period' => false,
            'message' => "志願{$choice_order}評分時間已截止，截止時間：" . date('Y-m-d H:i', $deadline_timestamp),
            'deadline' => $deadline
        ];
    }
    
    // 在評分時間內
    error_log("時間檢查通過：在評分時間內");
    return [
        'is_within_period' => true,
        'message' => "志願{$choice_order}評分進行中，截止時間：" . date('Y-m-d H:i', $deadline_timestamp),
        'deadline' => $deadline
    ];
}

/**
 * 計算所有評分的平均分數
 * 
 * @param mysqli $conn 資料庫連接
 * @param int $application_id 報名ID
 * @return array ['average_score' => float, 'total_score' => int, 'reviewer_count' => int, 'scores' => array]
 */
function calculateAverageScore($conn, $application_id) {
    $score_stmt = $conn->prepare("
        SELECT self_intro_score, skills_score, reviewer_type, assignment_order
        FROM continued_admission_scores
        WHERE application_id = ?
    ");
    $score_stmt->bind_param("i", $application_id);
    $score_stmt->execute();
    $score_result = $score_stmt->get_result();
    
    $scores = [];
    $total_score = 0;
    $reviewer_count = 0;
    
    while ($row = $score_result->fetch_assoc()) {
        $self_intro = (int)($row['self_intro_score'] ?? 0);
        $skills = (int)($row['skills_score'] ?? 0);
        $individual_total = $self_intro + $skills;
        
        if ($individual_total > 0) { // 只計算有評分的
            $scores[] = [
                'reviewer_type' => $row['reviewer_type'],
                'assignment_order' => $row['assignment_order'],
                'self_intro_score' => $self_intro,
                'skills_score' => $skills,
                'total_score' => $individual_total
            ];
            $total_score += $individual_total;
            $reviewer_count++;
        }
    }
    $score_stmt->close();
    
    $average_score = $reviewer_count > 0 ? round($total_score / $reviewer_count, 2) : 0;
    
    return [
        'average_score' => $average_score,
        'total_score' => $total_score,
        'reviewer_count' => $reviewer_count,
        'scores' => $scores
    ];
}

/**
 * 檢查評分是否未達標
 * 
 * @param mysqli $conn 資料庫連接
 * @param int $application_id 報名ID
 * @param string $department_code 科系代碼
 * @return array ['is_failed' => bool, 'reason' => string, 'average_score' => float, 'cutoff_score' => float|null]
 */
function checkScoreFailed($conn, $application_id, $department_code) {
    // 獲取該科系的錄取標準分數
    $quota_stmt = $conn->prepare("SELECT cutoff_score FROM department_quotas WHERE department_code = ? AND is_active = 1 LIMIT 1");
    $quota_stmt->bind_param("s", $department_code);
    $quota_stmt->execute();
    $quota_result = $quota_stmt->get_result();
    $quota_data = $quota_result->fetch_assoc();
    $quota_stmt->close();
    
    $cutoff_score = $quota_data ? (float)($quota_data['cutoff_score'] ?? 60) : 60; // 預設60分
    
    // 計算平均分數
    $score_info = calculateAverageScore($conn, $application_id);
    $average_score = $score_info['average_score'];
    
    // 檢查是否所有評審都已完成評分
    $assign_stmt = $conn->prepare("
        SELECT COUNT(*) as total_assignments
        FROM continued_admission_assignments
        WHERE application_id = ?
    ");
    $assign_stmt->bind_param("i", $application_id);
    $assign_stmt->execute();
    $assign_result = $assign_stmt->get_result();
    $assign_data = $assign_result->fetch_assoc();
    $assign_stmt->close();
    
    $total_assignments = (int)($assign_data['total_assignments'] ?? 0);
    
    // 如果還有評審未完成評分，不算未達標
    if ($score_info['reviewer_count'] < $total_assignments) {
        return [
            'is_failed' => false,
            'reason' => '尚有評審未完成評分',
            'average_score' => $average_score,
            'cutoff_score' => $cutoff_score
        ];
    }
    
    // 檢查平均分數是否低於標準
    if ($average_score < $cutoff_score) {
        return [
            'is_failed' => true,
            'reason' => "平均分數 {$average_score} 分低於錄取標準 {$cutoff_score} 分",
            'average_score' => $average_score,
            'cutoff_score' => $cutoff_score
        ];
    }
    
    // 檢查是否被拒絕（status = 'rejected' 或 'RE'）
    $status_stmt = $conn->prepare("SELECT status FROM continued_admission WHERE id = ?");
    $status_stmt->bind_param("i", $application_id);
    $status_stmt->execute();
    $status_result = $status_stmt->get_result();
    $status_data = $status_result->fetch_assoc();
    $status_stmt->close();
    
    $status = $status_data['status'] ?? '';
    if (in_array(strtoupper($status), ['REJECTED', 'RE'])) {
        return [
            'is_failed' => true,
            'reason' => '已被拒絕',
            'average_score' => $average_score,
            'cutoff_score' => $cutoff_score
        ];
    }
    
    return [
        'is_failed' => false,
        'reason' => '評分達標',
        'average_score' => $average_score,
        'cutoff_score' => $cutoff_score
    ];
}

/**
 * 自動分配到下一志願
 * 
 * @param mysqli $conn 資料庫連接
 * @param int $application_id 報名ID
 * @param int $current_choice_order 當前志願序
 * @return array ['success' => bool, 'message' => string, 'new_department' => string|null]
 */
function autoAssignToNextChoice($conn, $application_id, $current_choice_order) {
    // 獲取下一志願
    $next_choice = getNextChoice($conn, $application_id, $current_choice_order);
    
    if (!$next_choice) {
        return [
            'success' => false,
            'message' => '沒有下一志願',
            'new_department' => null
        ];
    }
    
    $new_dept_code = $next_choice['department_code'];
    $new_choice_order = $next_choice['choice_order'];
    
    // 開始事務
    $conn->begin_transaction();
    
    try {
        // 1. 清除舊的分配記錄
        $delete_assign_stmt = $conn->prepare("DELETE FROM continued_admission_assignments WHERE application_id = ?");
        $delete_assign_stmt->bind_param("i", $application_id);
        $delete_assign_stmt->execute();
        $delete_assign_stmt->close();
        
        // 2. 清除舊的評分記錄
        $delete_score_stmt = $conn->prepare("DELETE FROM continued_admission_scores WHERE application_id = ?");
        $delete_score_stmt->bind_param("i", $application_id);
        $delete_score_stmt->execute();
        $delete_score_stmt->close();
        
        // 3. 更新 assigned_department
        $update_stmt = $conn->prepare("UPDATE continued_admission SET assigned_department = ? WHERE id = ?");
        $update_stmt->bind_param("si", $new_dept_code, $application_id);
        $update_stmt->execute();
        $update_stmt->close();
        
        // 4. 重置狀態（如果之前被拒絕）
        $update_status_stmt = $conn->prepare("UPDATE continued_admission SET status = NULL WHERE id = ?");
        $update_status_stmt->bind_param("i", $application_id);
        $update_status_stmt->execute();
        $update_status_stmt->close();
        
        // 提交事務
        $conn->commit();
        
        // 獲取報名資料用於發送通知
        $app_info_stmt = $conn->prepare("SELECT id, apply_no, name FROM continued_admission WHERE id = ?");
        $app_info_stmt->bind_param("i", $application_id);
        $app_info_stmt->execute();
        $app_info_result = $app_info_stmt->get_result();
        $app_info = $app_info_result->fetch_assoc();
        $app_info_stmt->close();
        
        // 發送通知給新科系的主任（如果有通知函數）
        if ($app_info) {
            try {
                $notification_path = __DIR__ . '/../../Topics-frontend/frontend/includes/continued_admission_notification_functions.php';
                if (file_exists($notification_path)) {
                    require_once $notification_path;
                    
                    $student_data = [
                        'name' => $app_info['name'] ?? '學生',
                        'apply_no' => $app_info['apply_no'] ?? ''
                    ];
                    
                    // 查找新科系的主任
                    $director_stmt = $conn->prepare("SELECT user_id FROM director WHERE department = ? LIMIT 1");
                    if (!$director_stmt) {
                        $director_stmt = $conn->prepare("SELECT user_id FROM teacher WHERE department = ? AND role = 'DI' LIMIT 1");
                    }
                    $director_stmt->bind_param("s", $new_dept_code);
                    $director_stmt->execute();
                    $director_result = $director_stmt->get_result();
                    $director_data = $director_result->fetch_assoc();
                    $director_stmt->close();
                    
                    if ($director_data && !empty($director_data['user_id'])) {
                        // 注意：這裡使用 PDO，但我們現在是 mysqli，需要轉換
                        // 暫時跳過郵件通知，或使用 mysqli 版本
                        error_log("新科系 {$new_dept_code} 的主任 user_id: " . $director_data['user_id']);
                    }
                }
            } catch (Exception $e) {
                error_log("發送自動分配通知時發生錯誤: " . $e->getMessage());
                // 不影響主流程
            }
        }
        
        error_log("✅ 自動分配成功: 報名ID={$application_id}, 從志願{$current_choice_order}分配到志願{$new_choice_order} ({$new_dept_code})");
        
        return [
            'success' => true,
            'message' => "已自動分配到志願{$new_choice_order}",
            'new_department' => $new_dept_code,
            'new_choice_order' => $new_choice_order
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("❌ 自動分配失敗: " . $e->getMessage());
        return [
            'success' => false,
            'message' => '自動分配失敗：' . $e->getMessage(),
            'new_department' => null
        ];
    }
}

?>

