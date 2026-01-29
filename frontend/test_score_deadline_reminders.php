<?php
/**
 * 評分截止提醒郵件測試腳本
 * 用於測試截止前一天的郵件發送功能
 * 
 * 使用方式：
 * php test_score_deadline_reminders.php [測試日期，格式：YYYY-MM-DD，預設為明天]
 */

require_once __DIR__ . '/../../Topics-frontend/frontend/config.php';
require_once __DIR__ . '/../../Topics-frontend/frontend/includes/continued_admission_notification_functions.php';

// 獲取測試日期（命令行參數或預設為明天）
$test_date = isset($argv[1]) ? $argv[1] : date('Y-m-d', strtotime('+1 day'));
$test_datetime = $test_date . ' 23:59:59'; // 假設截止時間為當天23:59:59

echo "========================================\n";
echo "評分截止提醒郵件測試腳本\n";
echo "========================================\n";
echo "測試日期: {$test_date}\n";
echo "當前時間: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

try {
    $conn = getDatabaseConnection();
    
    // 查詢所有啟用的科系，找出審查截止時間為測試日期的科系
    echo "1. 查詢審查截止時間為 {$test_date} 的科系...\n";
    $dept_stmt = $conn->prepare("
        SELECT department_code, review_end, review_start, d.name AS department_name
        FROM department_quotas dq
        LEFT JOIN departments d ON dq.department_code = d.code
        WHERE dq.is_active = 1
          AND dq.review_end IS NOT NULL
          AND DATE(dq.review_end) = ?
    ");
    $dept_stmt->bind_param("s", $test_date);
    $dept_stmt->execute();
    $dept_result = $dept_stmt->get_result();
    $departments = [];
    while ($row = $dept_result->fetch_assoc()) {
        $departments[] = $row;
    }
    $dept_stmt->close();
    
    if (empty($departments)) {
        echo "❌ 沒有找到審查截止時間為 {$test_date} 的科系\n";
        echo "\n提示：請檢查 department_quotas 表中的 review_end 欄位\n";
        echo "或者使用其他日期測試：php test_score_deadline_reminders.php 2024-12-31\n";
        $conn->close();
        exit(0);
    }
    
    echo "✅ 找到 " . count($departments) . " 個科系：\n";
    foreach ($departments as $dept) {
        echo "   - {$dept['department_name']} ({$dept['department_code']}) - 截止時間: {$dept['review_end']}\n";
    }
    echo "\n";
    
    // 檢查正規化分配表是否存在
    $table_check = $conn->query("SHOW TABLES LIKE 'continued_admission_assignments'");
    $has_normalized_tables = ($table_check && $table_check->num_rows > 0);
    
    if (!$has_normalized_tables) {
        echo "❌ 正規化分配表不存在，無法執行提醒功能\n";
        $conn->close();
        exit(1);
    }
    
    // 檢查評分表是否存在
    $score_table_check = $conn->query("SHOW TABLES LIKE 'continued_admission_scores'");
    $has_score_table = ($score_table_check && $score_table_check->num_rows > 0);
    
    if (!$has_score_table) {
        echo "❌ 評分表不存在，無法執行提醒功能\n";
        $conn->close();
        exit(1);
    }
    
    $total_reminders_sent = 0;
    $total_teachers_notified = 0;
    $total_pending_applications = 0;
    
    // 對每個科系處理
    foreach ($departments as $dept) {
        $dept_code = $dept['department_code'];
        $dept_name = $dept['department_name'];
        $review_end = $dept['review_end'];
        
        echo "2. 處理科系: {$dept_name} ({$dept_code})\n";
        echo "   截止時間: {$review_end}\n";
        
        // 查找該科系所有待評分的報名（已分配但未評分）
        $app_stmt = $conn->prepare("
            SELECT ca.id, ca.apply_no, ca.name, ca.assigned_department
            FROM continued_admission ca
            WHERE ca.assigned_department = ?
              AND (ca.status IS NULL OR ca.status <> 'approved' AND ca.status <> 'AP')
        ");
        $app_stmt->bind_param("s", $dept_code);
        $app_stmt->execute();
        $app_result = $app_stmt->get_result();
        $applications = [];
        while ($app_row = $app_result->fetch_assoc()) {
            $applications[] = $app_row;
        }
        $app_stmt->close();
        
        if (empty($applications)) {
            echo "   ⚠️  該科系沒有待評分的報名\n\n";
            continue;
        }
        
        echo "   找到 " . count($applications) . " 個待評分報名\n";
        
        // 對每個報名，查找已分配但未評分的老師
        $teachers_pending = []; // teacher_id => [applications]
        
        foreach ($applications as $app) {
            $app_id = $app['id'];
            
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
                        'apply_no' => $app['apply_no'],
                        'name' => $app['name'],
                        'slot' => $slot
                    ];
                    $total_pending_applications++;
                }
            }
            $assign_stmt->close();
        }
        
        if (empty($teachers_pending)) {
            echo "   ✅ 該科系所有分配的老師都已完成評分\n\n";
            continue;
        }
        
        echo "   找到 " . count($teachers_pending) . " 位老師有待評分項目\n\n";
        
        // 發送提醒郵件給每位有待評分項目的老師
        foreach ($teachers_pending as $teacher_id => $pending_apps) {
            // 查詢老師資訊
            $teacher_info_stmt = $conn->prepare("
                SELECT u.name, u.email, u.username
                FROM user u
                WHERE u.id = ?
            ");
            $teacher_info_stmt->bind_param("i", $teacher_id);
            $teacher_info_stmt->execute();
            $teacher_info_result = $teacher_info_stmt->get_result();
            $teacher_info = $teacher_info_result->fetch_assoc();
            $teacher_info_stmt->close();
            
            $teacher_name = $teacher_info['name'] ?? $teacher_info['username'] ?? "ID: {$teacher_id}";
            $teacher_email = $teacher_info['email'] ?? '無郵箱';
            
            echo "3. 準備發送提醒給: {$teacher_name} ({$teacher_email})\n";
            echo "   待評分數量: " . count($pending_apps) . "\n";
            echo "   待評分學生: ";
            foreach ($pending_apps as $app) {
                echo "{$app['name']} ({$app['apply_no']}) ";
            }
            echo "\n";
            
            if (empty($teacher_email)) {
                echo "   ❌ 跳過：老師沒有設置郵箱\n\n";
                continue;
            }
            
            echo "   正在發送郵件...\n";
            $result = sendScoreDeadlineReminder($conn, $teacher_id, $pending_apps, $review_end);
            
            if ($result) {
                $total_reminders_sent++;
                $total_teachers_notified++;
                echo "   ✅ 郵件發送成功\n\n";
            } else {
                echo "   ❌ 郵件發送失敗（請檢查錯誤日誌）\n\n";
            }
        }
    }
    
    echo "========================================\n";
    echo "測試結果總結\n";
    echo "========================================\n";
    echo "處理科系數量: " . count($departments) . "\n";
    echo "通知老師數量: {$total_teachers_notified}\n";
    echo "發送郵件數量: {$total_reminders_sent}\n";
    echo "待評分項目總數: {$total_pending_applications}\n";
    echo "========================================\n";
    
    if ($total_reminders_sent > 0) {
        echo "\n✅ 測試完成！已成功發送 {$total_reminders_sent} 封提醒郵件\n";
    } else {
        echo "\n⚠️  沒有需要發送的提醒郵件（可能所有老師都已完成評分，或沒有待評分項目）\n";
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo "\n❌ 執行測試時發生錯誤: " . $e->getMessage() . "\n";
    echo "錯誤堆疊: " . $e->getTraceAsString() . "\n";
    exit(1);
}
?>


