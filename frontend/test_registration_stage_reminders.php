<?php
/**
 * 測試報名階段提醒郵件發送功能
 * 
 * 使用方式：
 * php test_registration_stage_reminders.php [stage] [test_email]
 * 
 * 參數說明：
 * - stage: 可選，指定測試的階段 (full_exempt/priority_exam/joint_exam/continued_recruitment)
 *   如果不指定，會使用當前月份判斷
 * - test_email: 可選，指定測試郵箱，如果不指定，會查詢資料庫中的學生
 * 
 * 範例：
 * php test_registration_stage_reminders.php full_exempt test@example.com
 * php test_registration_stage_reminders.php priority_exam test@example.com
 * php test_registration_stage_reminders.php joint_exam
 * php test_registration_stage_reminders.php
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

// 引入主腳本的函數
require_once __DIR__ . '/send_registration_stage_reminders.php';

// 取得命令列參數
$test_stage = $argv[1] ?? null;
$test_email = $argv[2] ?? null;

// 驗證階段參數
$valid_stages = ['full_exempt', 'priority_exam', 'joint_exam', 'continued_recruitment'];
if ($test_stage && !in_array($test_stage, $valid_stages)) {
    die("錯誤：無效的階段參數。請使用：full_exempt, priority_exam, joint_exam, 或 continued_recruitment\n");
}

echo "========================================\n";
echo "報名階段提醒郵件 - 測試模式\n";
echo "執行時間：" . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

// 檢查 SMTP 設定
echo "1. 檢查 SMTP 設定...\n";
if (empty(SMTP_USERNAME) || empty(SMTP_PASSWORD) || empty(SMTP_FROM_EMAIL)) {
    echo "❌ SMTP 設定不完整！\n";
    echo "   SMTP_USERNAME: " . (defined('SMTP_USERNAME') ? (empty(SMTP_USERNAME) ? '未設定' : SMTP_USERNAME) : '未定義') . "\n";
    echo "   SMTP_PASSWORD: " . (defined('SMTP_PASSWORD') ? (empty(SMTP_PASSWORD) ? '未設定' : '已設定') : '未定義') . "\n";
    echo "   SMTP_FROM_EMAIL: " . (defined('SMTP_FROM_EMAIL') ? (empty(SMTP_FROM_EMAIL) ? '未設定' : SMTP_FROM_EMAIL) : '未定義') . "\n";
    exit(1);
}
echo "✅ SMTP 設定完整\n";
echo "   發送者：". SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">\n\n";

// 決定測試階段（續招依科系名額管理設定；未指定時需連線取得階段）
$current_stage = $test_stage;
if (!$current_stage) {
    $conn_temp = getDatabaseConnection();
    $current_stage = getCurrentRegistrationStage($conn_temp);
    $conn_temp->close();
}
$stage_names = [
    'full_exempt' => '完全免試',
    'priority_exam' => '優先免試',
    'joint_exam' => '聯合免試',
    'continued_recruitment' => '續招'
];

if (!$current_stage) {
    if (!$test_stage) {
        echo "⚠️  目前非報名期間，但可以強制指定階段進行測試\n";
        echo "   使用方式：php test_registration_stage_reminders.php [stage] [test_email]\n";
        echo "   範例：php test_registration_stage_reminders.php full_exempt test@example.com\n";
        exit(0);
    }
}

echo "2. 測試階段：" . ($stage_names[$current_stage] ?? $current_stage) . "\n\n";

// 如果指定了測試郵箱，直接發送測試郵件
if ($test_email) {
    echo "3. 發送測試郵件到指定郵箱：{$test_email}\n";
    
    $test_name = "測試學生";
    $sent = sendRegistrationStageReminderEmail($test_email, $test_name, $current_stage);
    
    if ($sent) {
        echo "✅ 測試郵件發送成功！\n";
        echo "   請檢查 {$test_email} 的收件匣（包含垃圾郵件資料夾）\n";
    } else {
        echo "❌ 測試郵件發送失敗！\n";
        echo "   請檢查錯誤日誌\n";
    }
    exit($sent ? 0 : 1);
}

// 如果沒有指定測試郵箱，查詢資料庫
echo "3. 查詢資料庫中的測試學生...\n";

try {
    $conn = getDatabaseConnection();
    ensureRegistrationColumns($conn);
    
    // 計算當年度畢業年份
    $current_month = (int)date('m');
    $current_year = (int)date('Y');
    $this_year_grad = ($current_month >= 8) ? $current_year + 1 : $current_year;
    
    // 查詢符合條件的學生（測試模式：不檢查是否已提醒）
    $reminded_col = $current_stage . '_reminded';
    $reminded_col_escaped = "`{$reminded_col}`";
    
    // 測試模式：查詢有 email 的學生（不限制是否已提醒）
    $sql = "SELECT id, name, email, 
                   IFNULL(is_registered, 0) as is_registered,
                   IFNULL({$reminded_col_escaped}, 0) as is_reminded
            FROM enrollment_intention 
            WHERE email IS NOT NULL 
            AND email != '' 
            AND graduation_year = ?
            LIMIT 5";
    
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
    
    if (empty($students)) {
        echo "⚠️  找不到符合條件的學生\n";
        echo "   查詢條件：graduation_year = {$this_year_grad}, 有 email, 未結案\n";
        echo "   建議：可以在資料庫中新增測試資料，或使用指定郵箱測試\n";
        echo "   使用方式：php test_registration_stage_reminders.php {$current_stage} your-email@example.com\n";
        $conn->close();
        exit(0);
    }
    
    echo "找到 " . count($students) . " 位學生（顯示前 5 位）\n\n";
    
    // 顯示學生資訊
    echo "學生列表：\n";
    echo str_repeat("-", 80) . "\n";
    printf("%-5s %-20s %-30s %-10s %-10s\n", "ID", "姓名", "Email", "已報名", "已提醒");
    echo str_repeat("-", 80) . "\n";
    foreach ($students as $student) {
        printf("%-5s %-20s %-30s %-10s %-10s\n", 
            $student['id'],
            mb_substr($student['name'], 0, 20),
            mb_substr($student['email'], 0, 30),
            $student['is_registered'] ? '是' : '否',
            $student['is_reminded'] ? '是' : '否'
        );
    }
    echo str_repeat("-", 80) . "\n\n";
    
    // 詢問是否要發送測試郵件
    echo "4. 選擇測試方式：\n";
    echo "   [1] 發送測試郵件給第一位學生（不更新資料庫）\n";
    echo "   [2] 發送測試郵件給所有符合條件的學生（會更新資料庫）\n";
    echo "   [3] 僅查詢，不發送郵件\n";
    echo "\n";
    echo "請輸入選項 (1/2/3，預設為 1): ";
    
    // 在命令列模式下，如果沒有互動輸入，預設選擇 1
    $choice = trim(fgets(STDIN)) ?: '1';
    
    if ($choice === '3') {
        echo "僅查詢模式，不發送郵件。\n";
        $conn->close();
        exit(0);
    }
    
    $send_to_all = ($choice === '2');
    $target_students = $send_to_all ? $students : [$students[0]];
    
    echo "\n5. 開始發送測試郵件...\n\n";
    
    $success_count = 0;
    $fail_count = 0;
    
    foreach ($target_students as $student) {
        $student_id = $student['id'];
        $student_name = $student['name'];
        $student_email = $student['email'];
        
        echo "發送給：{$student_name} ({$student_email})... ";
        
        $sent = sendRegistrationStageReminderEmail($student_email, $student_name, $current_stage);
        
        if ($sent) {
            echo "✅ 成功\n";
            $success_count++;
            
            // 如果選擇發送給所有學生，更新資料庫
            if ($send_to_all) {
                $update_sql = "UPDATE enrollment_intention SET {$reminded_col_escaped} = 1, registration_stage = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                if ($update_stmt) {
                    $update_stmt->bind_param("si", $current_stage, $student_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
            }
        } else {
            echo "❌ 失敗\n";
            $fail_count++;
        }
    }
    
    echo "\n========================================\n";
    echo "測試結果：\n";
    echo "成功發送：{$success_count} 封\n";
    echo "發送失敗：{$fail_count} 封\n";
    if ($send_to_all) {
        echo "資料庫已更新\n";
    } else {
        echo "（測試模式：資料庫未更新）\n";
    }
    echo "========================================\n";
    
    $conn->close();
    
} catch (Exception $e) {
    echo "❌ 錯誤：" . $e->getMessage() . "\n";
    echo "堆疊追蹤：" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n測試完成！\n";
?>
