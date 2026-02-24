<?php
/**
 * 自動發送報名階段提醒郵件
 *
 * 功能說明：
 * - 老師：提醒去提醒學生（每位負責老師一封，列出尚未報名的學生）
 * - 學生：提醒去報名
 * - 排除：已在其他階段報名（is_registered=1）或結案（check_in_status=completed/declined）的學生
 * - 續招階段依「科系名額管理」設定的報名時間區間判斷
 *
 * 使用方式：
 * 1. 後台手動：就讀意願名單頁點「立即發送階段提醒」
 * 2. 命令列：php send_registration_stage_reminders.php
 * 3. 時間到自動發送：設定 Windows 工作排程器或 cron 每日執行（例如早上 9:00）
 *    Windows：程式/指令碼填 php 路徑，引數填本檔完整路徑
 *    Linux：0 9 * * * /usr/bin/php /path/to/send_registration_stage_reminders.php
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
 * 從 department_quotas 取得續招報名時間區間
 */
function getContinuedRecruitmentTimeRange($conn) {
    $sql = "SELECT MIN(register_start) AS min_start, MAX(register_end) AS max_end 
            FROM department_quotas 
            WHERE is_active = 1 AND register_start IS NOT NULL AND register_end IS NOT NULL";
    $result = $conn->query($sql);
    if (!$result || $result->num_rows === 0) {
        return null;
    }
    $row = $result->fetch_assoc();
    if (empty($row['min_start']) || empty($row['max_end'])) {
        return null;
    }
    return ['start' => $row['min_start'], 'end' => $row['max_end']];
}

/**
 * 判斷當前報名階段
 * 優先免試/聯合免試依月份；續招依「科系名額管理」設定的報名時間區間。時區固定 Asia/Taipei。
 */
function getCurrentRegistrationStage($conn) {
    $info = getCurrentStagePeriodKey($conn);
    return $info ? $info['stage'] : null;
}

/**
 * 取得當前階段與其「期別鍵」（同一期別只發送一次）
 * @return array|null ['stage'=>'...', 'period_key'=>'...'] 或 null
 */
function getCurrentStagePeriodKey($conn) {
    $current_month = (int)date('m');
    $current_year = (int)date('Y');
    $continued_range = getContinuedRecruitmentTimeRange($conn);
    if ($continued_range) {
        $tz = new DateTimeZone('Asia/Taipei');
        $now = new DateTime('now', $tz);
        try {
            $start = new DateTime($continued_range['start'], $tz);
            $end = new DateTime($continued_range['end'], $tz);
            if ($now >= $start && $now <= $end) {
                $period_key = $continued_range['start'] . '_' . $continued_range['end'];
                return ['stage' => 'continued_recruitment', 'period_key' => $period_key];
            }
        } catch (Exception $e) {
            // 解析失敗則不視為續招期間
        }
    }
    if ($current_month >= 4 && $current_month < 5) {
        return ['stage' => 'full_exempt', 'period_key' => $current_year . '-04']; // 4月：完全免試
    }
    if ($current_month >= 5 && $current_month < 6) {
        return ['stage' => 'priority_exam', 'period_key' => $current_year . '-05'];
    }
    if ($current_month >= 6 && $current_month < 8) {
        return ['stage' => 'joint_exam', 'period_key' => $current_year . '-06'];
    }
    return null;
}

/**
 * 確保「階段提醒已發送記錄」資料表存在
 */
function ensureStageReminderLogTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS registration_stage_reminder_log (
        stage VARCHAR(50) NOT NULL,
        period_key VARCHAR(200) NOT NULL,
        sent_at DATETIME NOT NULL,
        PRIMARY KEY (stage, period_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='報名階段提醒每期只發送一次'");
}

/**
 * 確保報名提醒相關欄位存在
 */
function ensureRegistrationColumns($conn) {
    $cols = [
        'registration_stage' => "VARCHAR(20) DEFAULT NULL COMMENT 'full_exempt/priority_exam/joint_exam/continued_recruitment 當前報名階段'",
        'full_exempt_reminded' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '完全免試是否已提醒'",
        'full_exempt_registered' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '完全免試是否已報名'",
        'full_exempt_declined' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '完全免試本階段不報'",
        'priority_exam_reminded' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '優先免試是否已提醒'",
        'priority_exam_registered' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '優先免試是否已報名'",
        'priority_exam_declined' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '優先免試本階段不報'",
        'joint_exam_reminded' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '聯合免試是否已提醒'",
        'joint_exam_registered' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '聯合免試是否已報名'",
        'joint_exam_declined' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '聯合免試本階段不報'",
        'continued_recruitment_reminded' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '續招是否已提醒'",
        'continued_recruitment_registered' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '續招是否已報名'",
        'continued_recruitment_declined' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '續招本階段不報'",
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
        'full_exempt' => '完全免試',
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
 * 發送報名階段提醒郵件給老師（階段開始時提醒其負責的學生尚未報名）
 */
function sendRegistrationStageReminderToTeacher($email, $teacherName, $stage, $studentCount, $studentNamesList) {
    $stage_names = [
        'full_exempt' => '完全免試',
        'priority_exam' => '優先免試',
        'joint_exam' => '聯合免試',
        'continued_recruitment' => '續招'
    ];
    $stage_name = $stage_names[$stage] ?? '報名';
    $subject = "康寧大學 - {$stage_name}報名階段提醒（您負責的學生）";
    $list_html = $studentNamesList ? '<ul style="margin:8px 0;">' . implode('', array_map(function ($n) {
        return '<li>' . htmlspecialchars($n) . '</li>';
    }, $studentNamesList)) . '</ul>' : '';
    $list_text = $studentNamesList ? "\n- " . implode("\n- ", $studentNamesList) : '';
    $body = "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='font-family: Arial, Microsoft JhengHei, sans-serif;'>
    <div style='max-width:600px; margin:0 auto; padding:20px;'>
        <h2>老師您好，</h2>
        <p>目前正值 <strong>{$stage_name}</strong> 報名階段。您負責的學生中有 <strong>{$studentCount}</strong> 位尚未報名，請協助提醒：</p>
        {$list_html}
        <p>請提醒學生把握時間完成報名手續。</p>
        <p style='color:#666; font-size:14px;'>此郵件由系統自動發送。</p>
    </div>
    </body>
    </html>";
    $altBody = "康寧大學 - {$stage_name}報名階段提醒\n\n您負責的學生中有 {$studentCount} 位尚未報名：{$list_text}\n\n請協助提醒學生把握時間完成報名。";
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

/**
 * 執行報名階段提醒發送（老師：提醒去提醒學生；學生：提醒去報名）
 * 排除：已在其他階段報名（is_registered=1）或結案（check_in_status=completed/declined）的學生
 * @return array ['success'=>bool, 'message'=>'', 'stage'=>'', 'stage_name'=>'', 'students_total'=>n, 'students_sent'=>n, 'students_fail'=>n, 'teachers_sent'=>n, 'teachers_fail'=>n, 'updated'=>n, 'error'=>'']
 */
function runRegistrationStageReminders() {
    $stage_names = [
        'full_exempt' => '完全免試',
        'priority_exam' => '優先免試',
        'joint_exam' => '聯合免試',
        'continued_recruitment' => '續招'
    ];
    try {
        $conn = getDatabaseConnection();
        ensureRegistrationColumns($conn);
        $current_stage = getCurrentRegistrationStage($conn);
        if (!$current_stage) {
            $conn->close();
            return ['success' => true, 'message' => '目前非報名期間，無需發送提醒郵件。', 'stage' => null, 'stage_name' => null, 'students_total' => 0, 'students_sent' => 0, 'students_fail' => 0, 'teachers_sent' => 0, 'teachers_fail' => 0, 'updated' => 0, 'error' => ''];
        }
        $current_month = (int)date('m');
        $current_year = (int)date('Y');
        $this_year_grad = ($current_month >= 8) ? $current_year + 1 : $current_year;
        $reminded_col = $current_stage . '_reminded';
        $declined_col = $current_stage . '_declined';
        $reminded_col_escaped = "`{$reminded_col}`";
        $declined_col_escaped = "`{$declined_col}`";
        $sql = "SELECT id, name, email, assigned_teacher_id 
                FROM enrollment_intention 
                WHERE email IS NOT NULL AND email != '' 
                AND (IFNULL(is_registered, 0) = 0)
                AND (IFNULL({$reminded_col_escaped}, 0) = 0)
                AND (IFNULL({$declined_col_escaped}, 0) = 0)
                AND graduation_year = ?
                AND (IFNULL(check_in_status, 'pending') NOT IN ('completed', 'declined'))";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $conn->close();
            return ['success' => false, 'message' => '', 'stage' => $current_stage, 'stage_name' => $stage_names[$current_stage] ?? '', 'students_total' => 0, 'students_sent' => 0, 'students_fail' => 0, 'teachers_sent' => 0, 'teachers_fail' => 0, 'updated' => 0, 'error' => '準備 SQL 失敗：' . $conn->error];
        }
        $stmt->bind_param("i", $this_year_grad);
        $stmt->execute();
        $result = $stmt->get_result();
        $students = [];
        while ($row = $result->fetch_assoc()) {
            $students[] = $row;
        }
        $stmt->close();
        if (empty($students)) {
            $conn->close();
            return ['success' => true, 'message' => '沒有需要發送郵件的學生。', 'stage' => $current_stage, 'stage_name' => $stage_names[$current_stage] ?? '', 'students_total' => 0, 'students_sent' => 0, 'students_fail' => 0, 'teachers_sent' => 0, 'teachers_fail' => 0, 'updated' => 0, 'error' => ''];
        }
        // 先發送學生 Gmail（系統通知）；不寫入「已提醒」—「已提醒」由老師親自提醒後按鈕確認
        $success_count = 0;
        $fail_count = 0;
        foreach ($students as $student) {
            $sent = sendRegistrationStageReminderEmail($student['email'], $student['name'], $current_stage);
            if ($sent) {
                $success_count++;
            } else {
                $fail_count++;
            }
        }
        // 再發送老師提醒
        $teachers_sent = 0;
        $teachers_failed = 0;
        $by_teacher = [];
        foreach ($students as $s) {
            $tid = (int)($s['assigned_teacher_id'] ?? 0);
            if ($tid > 0) {
                if (!isset($by_teacher[$tid])) $by_teacher[$tid] = [];
                $by_teacher[$tid][] = $s['name'];
            }
        }
        foreach ($by_teacher as $teacher_id => $names) {
            $user_stmt = $conn->prepare("SELECT id, name, email FROM user WHERE id = ? AND email IS NOT NULL AND email != '' LIMIT 1");
            if (!$user_stmt) continue;
            $user_stmt->bind_param("i", $teacher_id);
            $user_stmt->execute();
            $user_res = $user_stmt->get_result();
            $teacher = $user_res ? $user_res->fetch_assoc() : null;
            $user_stmt->close();
            if (!$teacher) { $teachers_failed++; continue; }
            $sent = sendRegistrationStageReminderToTeacher($teacher['email'], $teacher['name'], $current_stage, count($names), array_slice($names, 0, 50));
            if ($sent) $teachers_sent++; else $teachers_failed++;
        }
        $conn->close();
        return [
            'success' => true,
            'message' => "已發送學生 {$success_count} 封、老師 {$teachers_sent} 位提醒。",
            'stage' => $current_stage,
            'stage_name' => $stage_names[$current_stage] ?? '',
            'students_total' => count($students),
            'students_sent' => $success_count,
            'students_fail' => $fail_count,
            'teachers_sent' => $teachers_sent,
            'teachers_fail' => $teachers_failed,
            'updated' => 0,
            'error' => ''
        ];
    } catch (Exception $e) {
        return ['success' => false, 'message' => '', 'stage' => null, 'stage_name' => null, 'students_total' => 0, 'students_sent' => 0, 'students_fail' => 0, 'teachers_sent' => 0, 'teachers_fail' => 0, 'updated' => 0, 'error' => $e->getMessage()];
    }
}

// 僅在命令列執行時輸出文字並結束
if (php_sapi_name() === 'cli') {
    echo "========================================\n";
    echo "報名階段提醒郵件發送系統\n";
    echo "執行時間：" . date('Y-m-d H:i:s') . "\n";
    echo "========================================\n\n";
    $r = runRegistrationStageReminders();
    if ($r['stage']) {
        echo "當前報名階段：{$r['stage_name']}\n\n";
    }
    echo $r['message'] . "\n";
    if ($r['success'] && $r['students_total'] > 0) {
        echo "學生郵件 - 成功：{$r['students_sent']} 封，失敗：{$r['students_fail']} 封\n";
        echo "老師提醒 - 成功：{$r['teachers_sent']} 位，失敗：{$r['teachers_fail']} 位\n";
        echo "資料庫更新：{$r['updated']} 筆\n";
    }
    if (!empty($r['error'])) {
        echo "錯誤：" . $r['error'] . "\n";
    }
    echo "\n程式執行完成。\n";
    exit($r['success'] ? 0 : 1);
}
