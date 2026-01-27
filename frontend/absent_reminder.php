<?php
/**
 * 未到警示頁面
 * 顯示有報名但未出席的清單，並提醒老師在 24 小時內進行電話追蹤或改約下一場次
 */

require_once __DIR__ . '/session_config.php';
checkBackendLogin();

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 獲取場次ID（可選）
$session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'urgent'; // urgent: 24小時內, all: 全部

$conn = getDatabaseConnection();

// 獲取當前年份
$current_year = (int)date('Y');
$current_datetime = date('Y-m-d H:i:s');
$hours_24_ago = date('Y-m-d H:i:s', strtotime('-24 hours'));

// 查詢有報名但未出席的記錄
// 條件：有報名記錄，但沒有出席記錄，且場次結束時間在 24 小時內
$where_conditions = [];
$where_params = [];
$where_types = '';

if ($view_mode === 'urgent') {
    // 只顯示 24 小時內需要追蹤的記錄
    $where_conditions[] = "s.session_end_date >= ? AND s.session_end_date <= ?";
    $where_params[] = $hours_24_ago;
    $where_params[] = $current_datetime;
    $where_types .= 'ss';
}

if ($session_id > 0) {
    $where_conditions[] = "s.id = ?";
    $where_params[] = $session_id;
    $where_types .= 'i';
}

$where_clause = !empty($where_conditions) ? 'AND ' . implode(' AND ', $where_conditions) : '';

$sql = "
    SELECT 
        s.id as session_id,
        s.session_name,
        s.session_date,
        s.session_end_date,
        s.session_type,
        aa.id as application_id,
        aa.student_name,
        aa.email,
        aa.contact_phone,
        aa.created_at as registration_date,
        sd.name as school_name,
        TIMESTAMPDIFF(HOUR, s.session_end_date, NOW()) as hours_since_end
    FROM admission_sessions s
    INNER JOIN admission_applications aa ON s.id = aa.session_id
    LEFT JOIN school_data sd ON aa.school = sd.school_code
    LEFT JOIN attendance_records ar ON aa.id = ar.application_id 
        AND ar.session_id = s.id 
        AND ar.attendance_status = 1
    WHERE YEAR(aa.created_at) = ?
    AND YEAR(s.session_date) = ?
    AND s.session_end_date IS NOT NULL
    AND s.session_end_date <= ?
    AND ar.id IS NULL
    AND (aa.notes IS NULL OR aa.notes NOT LIKE '%未報名但有來%')
    {$where_clause}
    ORDER BY s.session_end_date DESC, aa.student_name ASC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("準備 SQL 語句失敗：" . $conn->error);
}

// 綁定參數
$bind_params = array_merge([$current_year, $current_year, $current_datetime], $where_params);
$bind_types = 'iis' . $where_types;
$stmt->bind_param($bind_types, ...$bind_params);
$stmt->execute();
$result = $stmt->get_result();
$absent_list = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 統計資訊
$urgent_count = 0; // 24 小時內
$total_count = count($absent_list);
foreach ($absent_list as $item) {
    $hours_since = $item['hours_since_end'] ?? 0;
    if ($hours_since >= 0 && $hours_since <= 24) {
        $urgent_count++;
    }
}

$conn->close();

// 設置頁面標題
$page_title = '未到警示 - 有報名但未出席清單';
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1890ff;
            --success-color: #52c41a;
            --warning-color: #faad14;
            --danger-color: #ff4d4f;
            --text-color: #262626;
            --text-secondary-color: #8c8c8c;
            --border-color: #d9d9d9;
            --background-color: #f0f2f5;
            --card-background-color: #fff;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; 
            background: var(--background-color); 
            color: var(--text-color); 
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .page-header {
            background: var(--card-background-color);
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            padding: 24px;
            margin-bottom: 24px;
        }
        
        .page-header h1 {
            font-size: 28px;
            color: var(--text-color);
            margin-bottom: 16px;
        }
        
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: var(--card-background-color);
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .stat-card.urgent {
            border-left: 4px solid var(--danger-color);
        }
        
        .stat-card.total {
            border-left: 4px solid var(--warning-color);
        }
        
        .stat-card h3 {
            font-size: 14px;
            color: var(--text-secondary-color);
            margin-bottom: 8px;
        }
        
        .stat-card .value {
            font-size: 32px;
            font-weight: 600;
            color: var(--text-color);
        }
        
        .filters {
            background: var(--card-background-color);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
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
            background: #8c8c8c;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #595959;
        }
        
        .btn.active {
            background: var(--primary-color);
            color: white;
        }
        
        .table-container {
            background: var(--card-background-color);
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .table-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            background: #fafafa;
        }
        
        .table-header h2 {
            font-size: 18px;
            margin: 0;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        th {
            background: #fafafa;
            font-weight: 600;
            color: var(--text-color);
        }
        
        tr:hover {
            background: #fafafa;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-urgent {
            background: #fff2f0;
            color: var(--danger-color);
            border: 1px solid #ffccc7;
        }
        
        .status-warning {
            background: #fffbe6;
            color: var(--warning-color);
            border: 1px solid #ffe58f;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-sm {
            padding: 4px 12px;
            font-size: 12px;
        }
        
        .info-box {
            background: #e6f7ff;
            border: 1px solid #91d5ff;
            border-radius: 6px;
            padding: 16px;
            margin-bottom: 24px;
        }
        
        .info-box.warning {
            background: #fffbe6;
            border: 1px solid #ffe58f;
        }
        
        .info-box p {
            margin: 0;
            color: #595959;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="page-header">
            <h1><i class="fas fa-exclamation-triangle"></i> 未到警示</h1>
            <p style="color: var(--text-secondary-color); margin-top: 8px;">
                此頁面顯示有報名但未出席的學生清單。系統會在場次結束後自動發送提醒 Email 給未到學生，您也可以手動觸發發送。
            </p>
        </div>
        
        <div class="stats-cards">
            <div class="stat-card urgent">
                <h3>24 小時內需追蹤</h3>
                <div class="value" style="color: var(--danger-color);"><?php echo $urgent_count; ?></div>
            </div>
            <div class="stat-card total">
                <h3>總未到人數</h3>
                <div class="value" style="color: var(--warning-color);"><?php echo $total_count; ?></div>
            </div>
        </div>
        
        <?php if ($urgent_count > 0): ?>
        <div class="info-box warning">
            <p>
                <i class="fas fa-exclamation-circle"></i> 
                <strong>緊急提醒：</strong>有 <?php echo $urgent_count; ?> 位學生在場次結束後 24 小時內尚未出席，請儘快進行電話追蹤或改約下一場次。
            </p>
        </div>
        <?php endif; ?>
        
        <div class="filters">
            <span style="font-weight: 500;">顯示模式：</span>
            <a href="?view=urgent" class="btn <?php echo $view_mode === 'urgent' ? 'active' : 'btn-secondary'; ?>">
                <i class="fas fa-clock"></i> 24 小時內
            </a>
            <a href="?view=all" class="btn <?php echo $view_mode === 'all' ? 'active' : 'btn-secondary'; ?>">
                <i class="fas fa-list"></i> 全部記錄
            </a>
            <?php if ($session_id > 0): ?>
            <a href="?" class="btn btn-secondary">
                <i class="fas fa-times"></i> 清除篩選
            </a>
            <?php endif; ?>
            <div style="margin-left: auto;">
                <button onclick="sendReminderEmails()" class="btn btn-primary">
                    <i class="fas fa-envelope"></i> 發送提醒郵件給學生
                </button>
            </div>
        </div>
        
        <div class="table-container">
            <div class="table-header">
                <h2>
                    <i class="fas fa-users"></i> 未到學生清單
                    <span style="font-size: 14px; font-weight: normal; color: var(--text-secondary-color); margin-left: 8px;">
                        (共 <?php echo $total_count; ?> 筆)
                    </span>
                </h2>
            </div>
            
            <?php if (empty($absent_list)): ?>
                <div style="padding: 40px; text-align: center; color: var(--text-secondary-color);">
                    <i class="fas fa-check-circle" style="font-size: 48px; color: var(--success-color); margin-bottom: 16px;"></i>
                    <p style="font-size: 16px;">目前沒有需要追蹤的未到記錄</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>場次名稱</th>
                            <th>場次日期</th>
                            <th>學生姓名</th>
                            <th>學校</th>
                            <th>電話</th>
                            <th>Email</th>
                            <th>結束時間</th>
                            <th>狀態</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($absent_list as $item): ?>
                        <?php
                        $hours_since = $item['hours_since_end'] ?? 0;
                        $is_urgent = ($hours_since >= 0 && $hours_since <= 24);
                        $session_date = !empty($item['session_date']) ? date('Y/m/d H:i', strtotime($item['session_date'])) : '-';
                        $session_end_date = !empty($item['session_end_date']) ? date('Y/m/d H:i', strtotime($item['session_end_date'])) : '-';
                        ?>
                        <tr>
                            <td>
                                <a href="attendance_management.php?session_id=<?php echo $item['session_id']; ?>" style="color: var(--primary-color); text-decoration: none;">
                                    <?php echo htmlspecialchars($item['session_name']); ?>
                                </a>
                            </td>
                            <td><?php echo $session_date; ?></td>
                            <td><strong><?php echo htmlspecialchars($item['student_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($item['school_name'] ?: '-'); ?></td>
                            <td>
                                <?php if (!empty($item['contact_phone'])): ?>
                                    <a href="tel:<?php echo htmlspecialchars($item['contact_phone']); ?>" style="color: var(--primary-color); text-decoration: none;">
                                        <i class="fas fa-phone"></i> <?php echo htmlspecialchars($item['contact_phone']); ?>
                                    </a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($item['email'])): ?>
                                    <a href="mailto:<?php echo htmlspecialchars($item['email']); ?>" style="color: var(--primary-color); text-decoration: none;">
                                        <?php echo htmlspecialchars($item['email']); ?>
                                    </a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?php echo $session_end_date; ?></td>
                            <td>
                                <?php if ($is_urgent): ?>
                                    <span class="status-badge status-urgent">
                                        <i class="fas fa-exclamation-circle"></i> 24 小時內
                                    </span>
                                <?php else: ?>
                                    <span class="status-badge status-warning">
                                        <i class="fas fa-clock"></i> <?php echo round($hours_since / 24); ?> 天前
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <a href="tel:<?php echo htmlspecialchars($item['contact_phone']); ?>" class="btn btn-primary btn-sm" title="撥打電話">
                                        <i class="fas fa-phone"></i> 撥打
                                    </a>
                                    <a href="attendance_management.php?session_id=<?php echo $item['session_id']; ?>" class="btn btn-secondary btn-sm" title="查看場次">
                                        <i class="fas fa-eye"></i> 查看
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function sendReminderEmails() {
            if (!confirm('確定要發送未到提醒郵件給所有未到學生嗎？系統會自動檢查已結束的場次，並發送 Email 給有報名但未簽到的學生。')) {
                return;
            }
            
            const btn = event.target.closest('button');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 發送中...';
            
            fetch('send_absent_reminder.php', {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = originalText;
                
                if (data.success) {
                    alert('✓ ' + data.message);
                } else {
                    alert('✗ 發送失敗：' + (data.message || '未知錯誤'));
                }
            })
            .catch(error => {
                btn.disabled = false;
                btn.innerHTML = originalText;
                alert('✗ 發生錯誤：' + error.message);
            });
        }
    </script>
</body>
</html>
