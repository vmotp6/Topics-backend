<?php
/**
 * 自動發送報名階段提醒郵件
 * 
 * 功能說明：
 * - 在每個報名階段開始時，自動發送 Gmail 提醒給學生
 * - 如果學生已經報名（is_registered = 1），則不會再發送
 * - 如果該階段已經提醒過（{stage}_reminded = 1），則不會重複發送
 * 
 * 使用方式：
 * 1. 手動執行：php send_registration_stage_reminders.php
 * 2. 設定 cron job：每天執行一次（建議在早上執行）
 *   例如：0 9 * * * /usr/bin/php /path/to/send_registration_stage_reminders.php
 */

// 設定時區
date_default_timezone_set('Asia/Taipei');

// 引入配置檔案
$config_path = '../../Topics-frontend/frontend/config.php';
if (!file_exists($config_path)) {
    $alt_paths = [
        '../../../Topics-frontend/frontend/config.php',
        __DIR__ . '/../../Topics-frontend/frontend/config.php',
        dirname(__DIR__) . '/../Topics-frontend/frontend/config.php'
    ];
    
    $found = false;
    foreach ($alt_paths as $alt_path) {
        if (file_exists($alt_path)) {
            $config_path = $alt_path;
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        die('錯誤：找不到資料庫設定檔案 (config.php)');
    }
}

require_once $config_path;

// 引入郵件發送函數
$email_functions_path = '../../Topics-frontend/frontend/includes/email_functions.php';
if (!file_exists($email_functions_path)) {
    $alt_paths = [
        '../../../Topics-frontend/frontend/includes/email_functions.php',
        __DIR__ . '/../../Topics-frontend/frontend/includes/email_functions.php',
        dirname(__DIR__) . '/../Topics-frontend/frontend/includes/email_functions.php'
    ];
    
    $found = false;
    foreach ($alt_paths as $alt_path) {
        if (file_exists($alt_path)) {
            $email_functions_path = $alt_path;
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        die('錯誤：找不到郵件函數檔案 (email_functions.php)');
    }
}

require_once $email_functions_path;

/**
 * 判斷當前報名階段
 */
function getCurrentRegistrationStage() {
    $current_month = (int)date('m');
    if ($current_month >= 5 && $current_month < 6) {
        return 'priority_exam'; // 5月：優先免試
    } elseif ($current_month >= 6 && $current_month < 8) {
        return 'joint_exam'; // 6-7月：聯合免試
    } elseif ($current_month >= 8) {
        return 'continued_recruitment'; // 8月以後：續招
    }
    return null; // 非報名期間
}

/**
 * 確保報名提醒相關欄位存在
 */
function ensureRegistrationColumns($conn) {
    $cols = [
        'registration_stage' => "VARCHAR(20) DEFAULT NULL COMMENT 'priority_exam/joint_exam/continued_recruitment 當前報名階段'",
        'priority_exam_reminded' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '優先免試是否已提醒'",
        'priority_exam_registered' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '優先免試是否已報名'",
        'joint_exam_reminded' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '聯合免試是否已提醒'",
        'joint_exam_registered' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '聯合免試是否已報名'",
        'continued_recruitment_reminded' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '續招是否已提醒'",
        'continued_recruitment_registered' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '續招是否已報名'",
        'is_registered' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否已報名（任一階段）'"
    ];
    foreach ($cols as $name => $def) {
        $r = @$conn->query("SHOW COLUMNS FROM enrollment_intention LIKE '$name'");
        if (!$r || $r->num_rows === 0) {
            @$conn->query("ALTER TABLE enrollment_intention ADD COLUMN $name $def");
        }
    }
}

/**
 * 發送報名階段提醒郵件
 */
function sendRegistrationStageReminderEmail($email, $studentName, $stage) {
    $stage_names = [
        'priority_exam' => '優先免試',
        'joint_exam' => '聯合免試',
        'continued_recruitment' => '續招'
    ];
    
    $stage_name = $stage_names[$stage] ?? '報名';
    
    $subject = "康寧大學 - {$stage_name}報名提醒通知";
    
    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, 'Microsoft JhengHei', sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(90deg, #7ac9c7 0%, #956dbd 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
            .reminder-box { background: #fff3cd; border: 1px solid #ffeaa7; padding: 20px; margin: 20px 0; border-radius: 8px; }
            .info-box { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #667eea; }
            .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
            .highlight { color: #667eea; font-weight: bold; }
            .button { display: inline-block; padding: 12px 30px; background: #667eea; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🎓 康寧大學報名提醒</h1>
                <p>{$stage_name}階段開始</p>
            </div>
            <div class='content'>
                <h2>親愛的 <span class='highlight'>{$studentName}</span> 同學，您好！</h2>
                
                <div class='reminder-box'>
                    <h3>⏰ 重要提醒</h3>
                    <p>目前正值 <strong>{$stage_name}</strong> 報名階段，提醒您記得完成報名手續。</p>
                </div>
                
                <div class='info-box'>
                    <h3>📋 報名資訊</h3>
                    <p><strong>報名階段：</strong>{$stage_name}</p>
                    <p><strong>報名時間：</strong>" . getStageTimeRange($stage) . "</p>
                    <p>請您把握時間，儘早完成報名程序。</p>
                </div>
                
                <div class='info-box'>
                    <h3>📞 聯絡資訊</h3>
                    <p>如有任何問題，歡迎與我們聯繫：</p>
                    <p><strong>招生諮詢專線：</strong>請洽學校總機</p>
                    <p><strong>電子郵件：</strong>" . SMTP_FROM_EMAIL . "</p>
                </div>
                
                <p>期待您的加入，讓我們一起開啟美好的學習旅程！</p>
                
                <div class='footer'>
                    <p>此郵件由系統自動發送，請勿直接回覆</p>
                    <p><strong>康寧大學招生組</strong></p>
                    <p>發送時間：" . date('Y-m-d H:i:s') . "</p>
                </div>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // 純文字版本
    $altBody = "
康寧大學 - {$stage_name}報名提醒通知

親愛的 {$studentName} 同學，您好！

目前正值 {$stage_name} 報名階段，提醒您記得完成報名手續。

報名資訊：
- 報名階段：{$stage_name}
- 報名時間：" . getStageTimeRange($stage) . "

請您把握時間，儘早完成報名程序。

如有任何問題，歡迎與我們聯繫：
- 招生諮詢專線：請洽學校總機
- 電子郵件：" . SMTP_FROM_EMAIL . "

期待您的加入，讓我們一起開啟美好的學習旅程！

康寧大學招生組
發送時間：" . date('Y-m-d H:i:s') . "
    ";
    
    return sendEmail($email, $subject, $body, $altBody);
}

/**
 * 取得階段時間範圍
 */
function getStageTimeRange($stage) {
    $current_year = (int)date('Y');
    $ranges = [
        'priority_exam' => "{$current_year}年5月",
        'joint_exam' => "{$current_year}年6-7月",
        'continued_recruitment' => "{$current_year}年8月以後"
    ];
    return $ranges[$stage] ?? '';
}

// 主程式開始
echo "========================================\n";
echo "報名階段提醒郵件發送系統\n";
echo "執行時間：" . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

try {
    // 檢查當前報名階段
    $current_stage = getCurrentRegistrationStage();
    
    if (!$current_stage) {
        echo "目前非報名期間，無需發送提醒郵件。\n";
        exit(0);
    }
    
    $stage_names = [
        'priority_exam' => '優先免試',
        'joint_exam' => '聯合免試',
        'continued_recruitment' => '續招'
    ];
    
    echo "當前報名階段：{$stage_names[$current_stage]}\n\n";
    
    // 連接資料庫
    $conn = getDatabaseConnection();
    ensureRegistrationColumns($conn);
    
    // 計算當年度畢業年份
    $current_month = (int)date('m');
    $current_year = (int)date('Y');
    $this_year_grad = ($current_month >= 8) ? $current_year + 1 : $current_year;
    
    // 查詢需要發送郵件的學生
    // 條件：
    // 1. 有 email
    // 2. 未報名（is_registered = 0）
    // 3. 該階段未提醒過（{stage}_reminded = 0）
    // 4. 當年度國三（graduation_year = this_year_grad）
    // 5. 未結案（case_closed = 0）
    $reminded_col = $current_stage . '_reminded';
    
    // 使用反引號包圍動態欄位名稱，確保 SQL 語句正確
    $reminded_col_escaped = "`{$reminded_col}`";
    
    $sql = "SELECT id, name, email 
            FROM enrollment_intention 
            WHERE email IS NOT NULL 
            AND email != '' 
            AND (IFNULL(is_registered, 0) = 0)
            AND (IFNULL({$reminded_col_escaped}, 0) = 0)
            AND graduation_year = ?
            AND (IFNULL(case_closed, 0) = 0)";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("準備 SQL 語句失敗：" . $conn->error);
    }
    
    $stmt->bind_param("i", $this_year_grad);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $students = [];
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    $stmt->close();
    
    echo "找到 " . count($students) . " 位需要發送提醒郵件的學生\n\n";
    
    if (empty($students)) {
        echo "沒有需要發送郵件的學生，程式結束。\n";
        $conn->close();
        exit(0);
    }
    
    // 發送郵件
    $success_count = 0;
    $fail_count = 0;
    $updated_count = 0;
    
    foreach ($students as $student) {
        $student_id = $student['id'];
        $student_name = $student['name'];
        $student_email = $student['email'];
        
        echo "正在發送郵件給：{$student_name} ({$student_email})... ";
        
        // 發送郵件
        $sent = sendRegistrationStageReminderEmail($student_email, $student_name, $current_stage);
        
        if ($sent) {
            echo "✓ 成功\n";
            $success_count++;
            
            // 更新資料庫，標記為已提醒
            $update_sql = "UPDATE enrollment_intention SET {$reminded_col_escaped} = 1, registration_stage = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            if ($update_stmt) {
                $update_stmt->bind_param("si", $current_stage, $student_id);
                if ($update_stmt->execute()) {
                    $updated_count++;
                }
                $update_stmt->close();
            }
        } else {
            echo "✗ 失敗\n";
            $fail_count++;
        }
    }
    
    echo "\n========================================\n";
    echo "發送結果統計：\n";
    echo "成功發送：{$success_count} 封\n";
    echo "發送失敗：{$fail_count} 封\n";
    echo "資料庫更新：{$updated_count} 筆\n";
    echo "========================================\n";
    
    $conn->close();
    
} catch (Exception $e) {
    echo "錯誤：" . $e->getMessage() . "\n";
    echo "堆疊追蹤：" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n程式執行完成。\n";
?>
