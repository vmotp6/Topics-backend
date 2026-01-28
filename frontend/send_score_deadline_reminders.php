<?php
/**
 * 評分截止提醒郵件發送腳本
 * 在審查截止前一天，發送郵件提醒尚未完成評分的老師
 * 
 * 使用方式：
 * 1. 手動執行：php send_score_deadline_reminders.php
 * 2. 設定定時任務（cron）：每天執行一次
 *   例如：0 9 * * * /usr/bin/php /path/to/send_score_deadline_reminders.php
 */

require_once __DIR__ . '/../../Topics-frontend/frontend/config.php';
require_once __DIR__ . '/../../Topics-frontend/frontend/includes/continued_admission_notification_functions.php';
require_once __DIR__ . '/includes/continued_admission_auto_assign.php';

try {
    $conn = getDatabaseConnection();
    
    // 獲取當前時間
    $current_time = date('Y-m-d H:i:s');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    
    error_log("開始執行評分截止提醒檢查: 當前時間={$current_time}");
    
    // 查詢所有待評分的報名，根據志願序計算評分截止時間
    // 找出評分截止時間為明天的報名
    $app_stmt = $conn->prepare("
        SELECT ca.id, ca.assigned_department, ca.apply_no, ca.name
        FROM continued_admission ca
        WHERE ca.assigned_department IS NOT NULL
          AND ca.assigned_department != ''
          AND (ca.status IS NULL OR ca.status <> 'approved' AND ca.status <> 'AP')
    ");
    $app_stmt->execute();
    $app_result = $app_stmt->get_result();
    $applications = [];
    while ($app_row = $app_result->fetch_assoc()) {
        $applications[] = $app_row;
    }
    $app_stmt->close();
    
    if (empty($applications)) {
        error_log("沒有找到待評分的報名，結束執行");
        $conn->close();
        exit(0);
    }
    
    error_log("找到 " . count($applications) . " 個待評分的報名，開始檢查評分截止時間");
    
    // 找出評分截止時間為明天的報名
    $pending_applications = [];
    foreach ($applications as $app) {
        $app_id = $app['id'];
        $assigned_dept = $app['assigned_department'];
        
        // 獲取審查開始時間
        $time_stmt = $conn->prepare("SELECT review_start FROM department_quotas WHERE department_code = ? AND is_active = 1 LIMIT 1");
        $time_stmt->bind_param("s", $assigned_dept);
        $time_stmt->execute();
        $time_result = $time_stmt->get_result();
        $time_data = $time_result->fetch_assoc();
        $time_stmt->close();
        
        if (!$time_data || empty($time_data['review_start'])) {
            continue;
        }
        
        // 獲取當前志願序
        $choice_order = getCurrentChoiceOrder($conn, $app_id);
        if (!$choice_order) {
            continue;
        }
        
        // 計算評分截止時間
        $deadline = calculateScoreDeadline($time_data['review_start'], $choice_order);
        if (!$deadline) {
            continue;
        }
        
        // 檢查截止時間是否為明天
        if (date('Y-m-d', strtotime($deadline)) === $tomorrow) {
            $pending_applications[] = [
                'id' => $app_id,
                'apply_no' => $app['apply_no'],
                'name' => $app['name'],
                'assigned_department' => $assigned_dept,
                'choice_order' => $choice_order,
                'deadline' => $deadline
            ];
        }
    }
    
    if (empty($pending_applications)) {
        error_log("沒有找到評分截止時間為明天的報名，結束執行");
        $conn->close();
        exit(0);
    }
    
    error_log("找到 " . count($pending_applications) . " 個報名的評分將於明天截止");
    
    $total_reminders_sent = 0;
    $total_teachers_notified = 0;
    
    // 檢查正規化分配表是否存在
    $table_check = $conn->query("SHOW TABLES LIKE 'continued_admission_assignments'");
    $has_normalized_tables = ($table_check && $table_check->num_rows > 0);
    
    if (!$has_normalized_tables) {
        error_log("❌ 正規化分配表不存在，無法執行提醒功能");
        $conn->close();
        exit(1);
    }
    
    // 檢查評分表是否存在
    $score_table_check = $conn->query("SHOW TABLES LIKE 'continued_admission_scores'");
    $has_score_table = ($score_table_check && $score_table_check->num_rows > 0);
    
    if (!$has_score_table) {
        error_log("❌ 評分表不存在，無法執行提醒功能");
        $conn->close();
        exit(1);
    }
    
    // 對每個報名處理
    foreach ($pending_applications as $app_info) {
        $app_id = $app_info['id'];
        $app_name = $app_info['name'];
        $app_apply_no = $app_info['apply_no'];
        $choice_order = $app_info['choice_order'];
        $deadline = $app_info['deadline'];
        
        error_log("處理報名: {$app_name} ({$app_apply_no}), 志願{$choice_order}, 截止時間: {$deadline}");
        
        // 查找已分配但未評分的老師
        $teachers_pending = []; // teacher_id => [applications]
        
        // 查找該報名的所有分配
        $assign_stmt = $conn->prepare("
            SELECT reviewer_user_id, reviewer_type, assignment_order
            FROM continued_admission_assignments
            WHERE application_id = ?
              AND reviewer_type = 'teacher'
        ");
        $assign_stmt->bind_param("i", $app_id);
        $assign_stmt->execute();
        $assign_result = $assign_stmt->get_result();
        
        while ($assign_row = $assign_result->fetch_assoc()) {
            $teacher_id = $assign_row['reviewer_user_id'];
            $slot = $assign_row['assignment_order'];
            
            // 檢查該老師是否已評分
            $score_stmt = $conn->prepare("
                SELECT self_intro_score, skills_score
                FROM continued_admission_scores
                WHERE application_id = ?
                  AND reviewer_user_id = ?
                  AND assignment_order = ?
            ");
            $score_stmt->bind_param("iii", $app_id, $teacher_id, $slot);
            $score_stmt->execute();
            $score_result = $score_stmt->get_result();
            $score_data = $score_result->fetch_assoc();
            $score_stmt->close();
            
            // 如果未評分，加入待提醒列表
            if (!$score_data || empty($score_data['self_intro_score']) || empty($score_data['skills_score'])) {
                if (!isset($teachers_pending[$teacher_id])) {
                    $teachers_pending[$teacher_id] = [];
                }
                $teachers_pending[$teacher_id][] = [
                    'id' => $app_id,
                    'apply_no' => $app_apply_no,
                    'name' => $app_name,
                    'slot' => $slot
                ];
            }
        }
        $assign_stmt->close();
        
        // 發送提醒郵件給每位有待評分項目的老師
        foreach ($teachers_pending as $teacher_id => $pending_apps) {
            error_log("準備發送提醒給老師 ID: {$teacher_id}, 待評分數量: " . count($pending_apps));
            
            $result = sendScoreDeadlineReminder($conn, $teacher_id, $pending_apps, $deadline);
            
            if ($result) {
                $total_reminders_sent++;
                $total_teachers_notified++;
                error_log("✅ 成功發送提醒給老師 ID: {$teacher_id}");
            } else {
                error_log("❌ 發送提醒失敗給老師 ID: {$teacher_id}");
            }
        }
    }
    
    error_log("提醒郵件發送完成: 共通知 {$total_teachers_notified} 位老師，發送 {$total_reminders_sent} 封郵件");
    
    $conn->close();
    
} catch (Exception $e) {
    error_log("執行評分截止提醒腳本時發生錯誤: " . $e->getMessage());
    error_log("錯誤堆疊: " . $e->getTraceAsString());
    exit(1);
}
?>

