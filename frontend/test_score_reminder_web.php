<?php
/**
 * 評分截止提醒郵件網頁測試工具
 * 可以通過瀏覽器訪問此頁面來測試郵件發送功能
 */

require_once __DIR__ . '/session_config.php';
checkBackendLogin();

// 只有管理員和行政人員可以執行
$user_role = $_SESSION['role'] ?? '';
if (!in_array($user_role, ['ADM', 'STA'])) {
    die('權限不足，只有管理員和行政人員可以執行此測試');
}

require_once __DIR__ . '/../../Topics-frontend/frontend/config.php';
require_once __DIR__ . '/../../Topics-frontend/frontend/includes/continued_admission_notification_functions.php';

$test_date = $_GET['test_date'] ?? date('Y-m-d', strtotime('+1 day'));
$action = $_GET['action'] ?? '';

$results = [];
$error_message = '';

if ($action === 'test') {
    try {
        $conn = getDatabaseConnection();
        
        // 查詢所有啟用的科系，找出審查截止時間為測試日期的科系
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
            $error_message = "沒有找到審查截止時間為 {$test_date} 的科系";
        } else {
            // 檢查正規化分配表是否存在
            $table_check = $conn->query("SHOW TABLES LIKE 'continued_admission_assignments'");
            $has_normalized_tables = ($table_check && $table_check->num_rows > 0);
            
            if (!$has_normalized_tables) {
                $error_message = "正規化分配表不存在，無法執行提醒功能";
            } else {
                $score_table_check = $conn->query("SHOW TABLES LIKE 'continued_admission_scores'");
                $has_score_table = ($score_table_check && $score_table_check->num_rows > 0);
                
                if (!$has_score_table) {
                    $error_message = "評分表不存在，無法執行提醒功能";
                } else {
                    $total_reminders_sent = 0;
                    $total_teachers_notified = 0;
                    
                    foreach ($departments as $dept) {
                        $dept_code = $dept['department_code'];
                        $dept_name = $dept['department_name'];
                        $review_end = $dept['review_end'];
                        
                        // 查找該科系所有待評分的報名
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
                            $results[] = [
                                'type' => 'info',
                                'message' => "科系 {$dept_name} ({$dept_code}) 沒有待評分的報名"
                            ];
                            continue;
                        }
                        
                        // 對每個報名，查找已分配但未評分的老師
                        $teachers_pending = [];
                        
                        foreach ($applications as $app) {
                            $app_id = $app['id'];
                            
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
                                }
                            }
                            $assign_stmt->close();
                        }
                        
                        if (empty($teachers_pending)) {
                            $results[] = [
                                'type' => 'success',
                                'message' => "科系 {$dept_name} ({$dept_code}) 所有分配的老師都已完成評分"
                            ];
                            continue;
                        }
                        
                        // 發送提醒郵件
                        foreach ($teachers_pending as $teacher_id => $pending_apps) {
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
                            $teacher_email = $teacher_info['email'] ?? '';
                            
                            if (empty($teacher_email)) {
                                $results[] = [
                                    'type' => 'warning',
                                    'message' => "跳過老師 {$teacher_name} (ID: {$teacher_id})：沒有設置郵箱"
                                ];
                                continue;
                            }
                            
                            $result = sendScoreDeadlineReminder($conn, $teacher_id, $pending_apps, $review_end);
                            
                            if ($result) {
                                $total_reminders_sent++;
                                $total_teachers_notified++;
                                $results[] = [
                                    'type' => 'success',
                                    'message' => "✅ 成功發送提醒給 {$teacher_name} ({$teacher_email})，待評分數量: " . count($pending_apps)
                                ];
                            } else {
                                $results[] = [
                                    'type' => 'error',
                                    'message' => "❌ 發送提醒失敗給 {$teacher_name} ({$teacher_email})"
                                ];
                            }
                        }
                    }
                    
                    $results[] = [
                        'type' => 'summary',
                        'message' => "測試完成：共通知 {$total_teachers_notified} 位老師，發送 {$total_reminders_sent} 封郵件"
                    ];
                }
            }
        }
        
        $conn->close();
        
    } catch (Exception $e) {
        $error_message = "執行測試時發生錯誤: " . $e->getMessage();
    }
}

// 查詢所有設定了審查時間的科系（用於顯示）
$conn = getDatabaseConnection();
$all_depts_stmt = $conn->prepare("
    SELECT department_code, review_end, review_start, d.name AS department_name
    FROM department_quotas dq
    LEFT JOIN departments d ON dq.department_code = d.code
    WHERE dq.is_active = 1
      AND dq.review_end IS NOT NULL
    ORDER BY review_end ASC
    LIMIT 20
");
$all_depts_stmt->execute();
$all_depts_result = $all_depts_stmt->get_result();
$all_departments = [];
while ($row = $all_depts_result->fetch_assoc()) {
    $all_departments[] = $row;
}
$all_depts_stmt->close();
$conn->close();

$page_title = '評分截止提醒測試';
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Topics 後台管理系統</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1890ff;
            --text-color: #262626;
            --text-secondary-color: #8c8c8c;
            --border-color: #f0f0f0;
            --background-color: #f0f2f5;
            --card-background-color: #fff;
            --success-color: #52c41a;
            --error-color: #f5222d;
            --warning-color: #faad14;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: var(--background-color);
            color: var(--text-color);
            padding: 24px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .card {
            background: var(--card-background-color);
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .card-header {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border-color);
            background: #fafafa;
        }
        .card-header h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-color);
        }
        .card-body {
            padding: 24px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-color);
        }
        .form-group input[type="date"] {
            padding: 8px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            font-size: 14px;
            width: 200px;
        }
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background: var(--primary-color);
            color: white;
        }
        .btn-primary:hover {
            background: #40a9ff;
        }
        .btn-secondary {
            background: #f5f5f5;
            color: var(--text-color);
            border: 1px solid #d9d9d9;
        }
        .btn-secondary:hover {
            background: #e8e8e8;
        }
        .result-item {
            padding: 12px;
            margin-bottom: 8px;
            border-radius: 6px;
            border-left: 4px solid;
        }
        .result-item.success {
            background: #f6ffed;
            border-color: var(--success-color);
            color: #389e0d;
        }
        .result-item.error {
            background: #fff1f0;
            border-color: var(--error-color);
            color: #cf1322;
        }
        .result-item.warning {
            background: #fffbe6;
            border-color: var(--warning-color);
            color: #d48806;
        }
        .result-item.info {
            background: #e6f7ff;
            border-color: var(--primary-color);
            color: #0958d9;
        }
        .result-item.summary {
            background: #f0f0f0;
            border-color: #595959;
            color: #262626;
            font-weight: bold;
            font-size: 16px;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }
        .table th,
        .table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        .table th {
            background: #fafafa;
            font-weight: 600;
        }
        .breadcrumb {
            margin-bottom: 16px;
            font-size: 16px;
            color: var(--text-secondary-color);
        }
        .breadcrumb a {
            color: var(--primary-color);
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="breadcrumb">
            <a href="index.php">首頁</a> / <a href="continued_admission_list.php">續招報名管理</a> / <?php echo $page_title; ?>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-envelope"></i> 評分截止提醒郵件測試</h3>
            </div>
            <div class="card-body">
                <form method="GET" action="">
                    <input type="hidden" name="action" value="test">
                    <div class="form-group">
                        <label for="test_date">測試日期（審查截止日期）：</label>
                        <input type="date" id="test_date" name="test_date" value="<?php echo htmlspecialchars($test_date); ?>" required>
                        <p style="margin-top: 8px; color: var(--text-secondary-color); font-size: 12px;">
                            <i class="fas fa-info-circle"></i> 選擇審查截止日期，系統會查找該日期截止的科系，並發送提醒郵件給未評分的老師
                        </p>
                    </div>
                    <div style="display: flex; gap: 12px;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> 執行測試並發送郵件
                        </button>
                        <a href="test_score_reminder_web.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> 重置
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!empty($error_message)): ?>
        <div class="card">
            <div class="card-body">
                <div class="result-item error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($results)): ?>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> 測試結果</h3>
            </div>
            <div class="card-body">
                <?php foreach ($results as $result): ?>
                <div class="result-item <?php echo $result['type']; ?>">
                    <?php echo htmlspecialchars($result['message']); ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-calendar"></i> 已設定的審查時間（最近 20 筆）</h3>
            </div>
            <div class="card-body">
                <?php if (empty($all_departments)): ?>
                <p style="color: var(--text-secondary-color);">目前沒有設定審查時間的科系</p>
                <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>科系名稱</th>
                            <th>科系代碼</th>
                            <th>審查開始時間</th>
                            <th>審查截止時間</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_departments as $dept): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($dept['department_name'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($dept['department_code']); ?></td>
                            <td><?php echo $dept['review_start'] ? date('Y-m-d H:i', strtotime($dept['review_start'])) : '未設定'; ?></td>
                            <td>
                                <?php 
                                if ($dept['review_end']): 
                                    $end_date = date('Y-m-d', strtotime($dept['review_end']));
                                    $is_tomorrow = ($end_date === date('Y-m-d', strtotime('+1 day')));
                                ?>
                                    <span style="<?php echo $is_tomorrow ? 'color: #f5222d; font-weight: bold;' : ''; ?>">
                                        <?php echo date('Y-m-d H:i', strtotime($dept['review_end'])); ?>
                                        <?php if ($is_tomorrow): ?>
                                            <span style="background: #fff1f0; padding: 2px 6px; border-radius: 4px; font-size: 12px; margin-left: 8px;">明天截止</span>
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    未設定
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="?action=test&test_date=<?php echo urlencode(date('Y-m-d', strtotime($dept['review_end']))); ?>" 
                                   class="btn btn-primary" style="padding: 4px 8px; font-size: 12px;">
                                    <i class="fas fa-paper-plane"></i> 測試此日期
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-info-circle"></i> 使用說明</h3>
            </div>
            <div class="card-body">
                <h4>測試步驟：</h4>
                <ol style="margin-left: 20px; margin-top: 12px; line-height: 2;">
                    <li>選擇或輸入一個審查截止日期</li>
                    <li>點擊「執行測試並發送郵件」按鈕</li>
                    <li>系統會查找該日期截止的科系</li>
                    <li>找出所有已分配但未評分的老師</li>
                    <li>發送提醒郵件給這些老師</li>
                </ol>
                
                <h4 style="margin-top: 24px;">注意事項：</h4>
                <ul style="margin-left: 20px; margin-top: 12px; line-height: 2; color: var(--text-secondary-color);">
                    <li>此測試會實際發送郵件，請謹慎使用</li>
                    <li>建議先在測試環境中驗證郵件功能</li>
                    <li>正式環境建議使用定時任務自動執行</li>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>


