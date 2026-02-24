<?php
/**
 * 報名階段提醒郵件網頁測試工具
 * 可以通過瀏覽器訪問此頁面來測試郵件發送功能
 */

require_once __DIR__ . '/session_config.php';
checkBackendLogin();

// 只有管理員和行政人員可以執行
$user_role = $_SESSION['role'] ?? '';
if (!in_array($user_role, ['ADM', 'STA'])) {
    die('權限不足，只有管理員和行政人員可以執行此測試');
}

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

// 引入主腳本的函數（只引入函數定義部分）
// 注意：send_registration_stage_reminders.php 包含主程式，需要分離
// 先定義必要的函數
if (!function_exists('getCurrentRegistrationStage')) {
    function getCurrentRegistrationStage() {
        $current_month = (int)date('m');
        if ($current_month >= 4 && $current_month < 5) {
            return 'full_exempt'; // 4月：完全免試
        } elseif ($current_month >= 5 && $current_month < 6) {
            return 'priority_exam'; // 5月：優先免試
        } elseif ($current_month >= 6 && $current_month < 8) {
            return 'joint_exam'; // 6-7月：聯合免試
        } elseif ($current_month >= 8) {
            return 'continued_recruitment'; // 8月以後：續招
        }
        return null; // 非報名期間
    }
}

if (!function_exists('ensureRegistrationColumns')) {
    function ensureRegistrationColumns($conn) {
        $cols = [
            'registration_stage' => "VARCHAR(20) DEFAULT NULL COMMENT 'full_exempt/priority_exam/joint_exam/continued_recruitment 當前報名階段'",
            'full_exempt_reminded' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '完全免試是否已提醒'",
            'full_exempt_registered' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '完全免試是否已報名'",
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
}

if (!function_exists('sendRegistrationStageReminderEmail')) {
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
}

if (!function_exists('getStageTimeRange')) {
    function getStageTimeRange($stage) {
        $current_year = (int)date('Y');
        $ranges = [
            'full_exempt' => "{$current_year}年4月",
            'full_exempt' => "{$current_year}年4月",
            'priority_exam' => "{$current_year}年5月",
            'joint_exam' => "{$current_year}年6-7月",
            'continued_recruitment' => "{$current_year}年8月以後"
        ];
        return $ranges[$stage] ?? '';
    }
}

$action = $_GET['action'] ?? '';
$test_stage = $_GET['stage'] ?? '';
$test_email = $_GET['test_email'] ?? '';
$update_db = isset($_GET['update_db']) && $_GET['update_db'] === '1';

$results = [];
$error_message = '';
$success_message = '';

// 處理測試請求
if ($action === 'test' && !empty($test_stage)) {
    try {
        // 驗證階段
        $valid_stages = ['full_exempt', 'priority_exam', 'joint_exam', 'continued_recruitment'];
        if (!in_array($test_stage, $valid_stages)) {
            throw new Exception('無效的階段參數');
        }
        
        // 如果指定了測試郵箱，直接發送
        if (!empty($test_email)) {
            $test_name = "測試學生";
            $sent = sendRegistrationStageReminderEmail($test_email, $test_name, $test_stage);
            
            if ($sent) {
                $success_message = "測試郵件已成功發送到：{$test_email}";
                $results[] = [
                    'type' => 'success',
                    'message' => "郵件發送成功：{$test_email}",
                    'email' => $test_email,
                    'name' => $test_name
                ];
            } else {
                $error_message = "郵件發送失敗，請檢查 SMTP 設定和錯誤日誌";
                $results[] = [
                    'type' => 'error',
                    'message' => "郵件發送失敗：{$test_email}",
                    'email' => $test_email
                ];
            }
        } else {
            // 查詢資料庫中的學生
            $conn = getDatabaseConnection();
            ensureRegistrationColumns($conn);
            
            // 計算當年度畢業年份
            $current_month = (int)date('m');
            $current_year = (int)date('Y');
            $this_year_grad = ($current_month >= 8) ? $current_year + 1 : $current_year;
            
            $reminded_col = $test_stage . '_reminded';
            $reminded_col_escaped = "`{$reminded_col}`";
            
            // 查詢符合條件的學生
            $sql = "SELECT id, name, email, 
                           IFNULL(is_registered, 0) as is_registered,
                           IFNULL({$reminded_col_escaped}, 0) as is_reminded
                    FROM enrollment_intention 
                    WHERE email IS NOT NULL 
                    AND email != '' 
                    AND (IFNULL(is_registered, 0) = 0)
                    AND (IFNULL({$reminded_col_escaped}, 0) = 0)
                    AND graduation_year = ?
                    LIMIT 10";
            
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
                $error_message = "找不到符合條件的學生（當年度國三、有 email、未報名、該階段未提醒）";
            } else {
                $success_count = 0;
                $fail_count = 0;
                
                foreach ($students as $student) {
                    $student_id = $student['id'];
                    $student_name = $student['name'];
                    $student_email = $student['email'];
                    
                    $sent = sendRegistrationStageReminderEmail($student_email, $student_name, $test_stage);
                    
                    if ($sent) {
                        $success_count++;
                        $results[] = [
                            'type' => 'success',
                            'message' => "郵件發送成功",
                            'email' => $student_email,
                            'name' => $student_name,
                            'id' => $student_id
                        ];
                        
                        // 如果選擇更新資料庫，標記為已提醒
                        if ($update_db) {
                            $update_sql = "UPDATE enrollment_intention SET {$reminded_col_escaped} = 1, registration_stage = ? WHERE id = ?";
                            $update_stmt = $conn->prepare($update_sql);
                            if ($update_stmt) {
                                $update_stmt->bind_param("si", $test_stage, $student_id);
                                $update_stmt->execute();
                                $update_stmt->close();
                            }
                        }
                    } else {
                        $fail_count++;
                        $results[] = [
                            'type' => 'error',
                            'message' => "郵件發送失敗",
                            'email' => $student_email,
                            'name' => $student_name,
                            'id' => $student_id
                        ];
                    }
                }
                
                $success_message = "測試完成：成功 {$success_count} 封，失敗 {$fail_count} 封";
                if ($update_db) {
                    $success_message .= "（資料庫已更新）";
                } else {
                    $success_message .= "（測試模式：資料庫未更新）";
                }
            }
            
            $conn->close();
        }
        
    } catch (Exception $e) {
        $error_message = "錯誤：" . $e->getMessage();
    }
}

// 查詢當前階段
$current_stage = getCurrentRegistrationStage();
$stage_names = [
    'full_exempt' => '完全免試',
    'priority_exam' => '優先免試',
    'joint_exam' => '聯合免試',
    'continued_recruitment' => '續招'
];

// 檢查 SMTP 設定
$smtp_configured = !empty(SMTP_USERNAME) && !empty(SMTP_PASSWORD) && !empty(SMTP_FROM_EMAIL);
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>報名階段提醒郵件測試</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Microsoft JhengHei', Arial, sans-serif;
            background: #f5f5f5;
            padding: 20px;
            line-height: 1.6;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 30px;
        }
        
        h1 {
            color: #667eea;
            margin-bottom: 10px;
            border-bottom: 3px solid #667eea;
            padding-bottom: 10px;
        }
        
        .subtitle {
            color: #666;
            margin-bottom: 30px;
        }
        
        .card {
            background: #f9f9f9;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
        }
        
        .card h3 {
            color: #333;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card h3 i {
            color: #667eea;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #333;
            font-weight: bold;
        }
        
        .form-group select,
        .form-group input[type="text"],
        .form-group input[type="email"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        
        .form-group input[type="checkbox"] {
            margin-right: 5px;
        }
        
        .form-group small {
            display: block;
            margin-top: 5px;
            color: #666;
            font-size: 12px;
        }
        
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            margin-right: 10px;
            margin-top: 10px;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5568d3;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        
        .result-item {
            padding: 10px;
            margin-bottom: 10px;
            border-radius: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .result-item.success {
            background: #d4edda;
            color: #155724;
        }
        
        .result-item.error {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .status-badge.success {
            background: #28a745;
            color: white;
        }
        
        .status-badge.error {
            background: #dc3545;
            color: white;
        }
        
        .status-badge.warning {
            background: #ffc107;
            color: #333;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        table th,
        table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        table th {
            background: #f8f9fa;
            font-weight: bold;
        }
        
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
        
        .info-box ul {
            margin-left: 20px;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-envelope"></i> 報名階段提醒郵件測試工具</h1>
        <p class="subtitle">測試報名階段提醒郵件的發送功能</p>
        
        <?php if (!$smtp_configured): ?>
        <div class="alert alert-error">
            <strong>⚠️ SMTP 設定不完整！</strong><br>
            請檢查 config.php 中的 SMTP 設定（SMTP_USERNAME, SMTP_PASSWORD, SMTP_FROM_EMAIL）
        </div>
        <?php else: ?>
        <div class="alert alert-success">
            <strong>✅ SMTP 設定完整</strong><br>
            發送者：<?php echo htmlspecialchars(SMTP_FROM_NAME); ?> &lt;<?php echo htmlspecialchars(SMTP_FROM_EMAIL); ?>&gt;
        </div>
        <?php endif; ?>
        
        <?php if ($current_stage): ?>
        <div class="alert alert-info">
            <strong>📅 當前報名階段：</strong><?php echo htmlspecialchars($stage_names[$current_stage] ?? $current_stage); ?>
        </div>
        <?php else: ?>
        <div class="alert alert-warning">
            <strong>⚠️ 目前非報名期間</strong><br>
            您可以手動選擇階段進行測試
        </div>
        <?php endif; ?>
        
        <div class="card">
            <h3><i class="fas fa-cog"></i> 測試設定</h3>
            <form method="GET" action="">
                <input type="hidden" name="action" value="test">
                
                <div class="form-group">
                    <label for="stage">選擇報名階段：</label>
                    <select id="stage" name="stage" required>
                        <option value="">請選擇階段</option>
                        <option value="full_exempt" <?php echo $test_stage === 'full_exempt' ? 'selected' : ''; ?>>完全免試（4月）</option>
                        <option value="priority_exam" <?php echo $test_stage === 'priority_exam' ? 'selected' : ''; ?>>優先免試（5月）</option>
                        <option value="joint_exam" <?php echo $test_stage === 'joint_exam' ? 'selected' : ''; ?>>聯合免試（6-7月）</option>
                        <option value="continued_recruitment" <?php echo $test_stage === 'continued_recruitment' ? 'selected' : ''; ?>>續招（8月以後）</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="test_email">測試郵箱（選填）：</label>
                    <input type="email" id="test_email" name="test_email" 
                           value="<?php echo htmlspecialchars($test_email); ?>" 
                           placeholder="例如：test@example.com">
                    <small>如果填寫，會直接發送測試郵件到此郵箱（不查詢資料庫）</small>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="update_db" value="1" <?php echo $update_db ? 'checked' : ''; ?>>
                        更新資料庫（標記為已提醒）
                    </label>
                    <small>勾選後，發送成功的郵件會更新資料庫的 {stage}_reminded 欄位</small>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-paper-plane"></i> 執行測試
                </button>
                <a href="test_registration_stage_reminders_web.php" class="btn btn-secondary">
                    <i class="fas fa-redo"></i> 重置
                </a>
            </form>
        </div>
        
        <?php if (!empty($error_message)): ?>
        <div class="alert alert-error">
            <strong>❌ 錯誤：</strong><?php echo htmlspecialchars($error_message); ?>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($success_message)): ?>
        <div class="alert alert-success">
            <strong>✅ 成功：</strong><?php echo htmlspecialchars($success_message); ?>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($results)): ?>
        <div class="card">
            <h3><i class="fas fa-list"></i> 測試結果</h3>
            <table>
                <thead>
                    <tr>
                        <th>狀態</th>
                        <th>姓名</th>
                        <th>Email</th>
                        <th>訊息</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $result): ?>
                    <tr>
                        <td>
                            <span class="status-badge <?php echo $result['type'] === 'success' ? 'success' : 'error'; ?>">
                                <?php echo $result['type'] === 'success' ? '✓ 成功' : '✗ 失敗'; ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($result['name'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($result['email'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($result['message'] ?? ''); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <div class="info-box">
            <h4><i class="fas fa-info-circle"></i> 使用說明</h4>
            <ul>
                <li><strong>快速測試：</strong>填寫「測試郵箱」欄位，系統會直接發送測試郵件到該郵箱</li>
                <li><strong>資料庫測試：</strong>不填寫「測試郵箱」，系統會查詢資料庫中符合條件的學生並發送</li>
                <li><strong>更新資料庫：</strong>勾選後，發送成功的郵件會更新資料庫，標記為已提醒</li>
                <li><strong>測試模式：</strong>不勾選「更新資料庫」時，只測試郵件發送功能，不會更新資料庫</li>
            </ul>
        </div>
    </div>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</body>
</html>
