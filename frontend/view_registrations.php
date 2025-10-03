<?php
session_start();

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 獲取場次ID
$session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
if ($session_id === 0) {
    header("Location: settings.php");
    exit;
}

// 建立資料庫連接
$conn = getDatabaseConnection();

// 獲取場次資訊
$stmt = $conn->prepare("SELECT * FROM admission_sessions WHERE id = ?");
$stmt->bind_param("i", $session_id);
$stmt->execute();
$session_result = $stmt->get_result();
$session = $session_result->fetch_assoc();

if (!$session) {
    // 如果找不到場次，跳轉回設定頁面
    header("Location: settings.php");
    exit;
}

// 獲取該場次的報名者列表
$stmt = $conn->prepare("SELECT * FROM admission_applications WHERE session_id = ? ORDER BY application_date DESC");
$stmt->bind_param("i", $session_id);
$stmt->execute();
$registrations_result = $stmt->get_result();
$registrations = $registrations_result->fetch_all(MYSQLI_ASSOC);

$conn->close();

// 設置頁面標題
$page_title = '查看報名名單 - ' . htmlspecialchars($session['session_name']);
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
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: var(--background-color); color: var(--text-color); overflow-x: hidden; }
        .dashboard { display: flex; min-height: 100vh; }
        .content { padding: 24px; }
        .breadcrumb { margin-bottom: 16px; font-size: 16px; color: var(--text-secondary-color); }
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        
        .card { background: var(--card-background-color); border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); border: 1px solid var(--border-color); margin-bottom: 24px; }
        .card-header { padding: 16px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: #fafafa; }
        .card-header h3 { font-size: 18px; font-weight: 600; color: var(--text-color); }
        .card-body { padding: 24px; }

        .table-container { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 16px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 14px; }
        .table th { background: #fafafa; font-weight: 600; }
        .table tr:hover { background: #fafafa; }

        .btn { padding: 8px 16px; border: 1px solid #d9d9d9; border-radius: 6px; cursor: pointer; font-size: 14px; transition: all 0.3s; background: #fff; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-secondary { background: #fff; color: #595959; border-color: #d9d9d9; }
        .btn-secondary:hover { background: #f5f5f5; border-color: #40a9ff; color: #40a9ff; }

        .empty-state { text-align: center; padding: 40px; color: var(--text-secondary-color); }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            <div class="content">
                <div class="breadcrumb">
                    <a href="index.php">首頁</a> / <a href="settings.php">場次設定</a> / 查看報名名單
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3><?php echo htmlspecialchars($session['session_name']); ?> - 報名名單 (共 <?php echo count($registrations); ?> 人)</h3>
                        <a href="settings.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> 返回場次設定</a>
                    </div>
                    <div class="card-body table-container">
                        <?php if (empty($registrations)): ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                                <p>目前尚無人報名此場次。</p>
                            </div>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>姓名</th>
                                        <th>Email</th>
                                        <th>電話</th>
                                        <th>就讀學校</th>
                                        <th>年級</th>
                                        <th>體驗課程</th>
                                        <th>報名日期</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($registrations as $reg): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($reg['student_name']); ?></td>
                                        <td><?php echo htmlspecialchars($reg['email']); ?></td>
                                        <td><?php echo htmlspecialchars($reg['phone']); ?></td>
                                        <td><?php echo htmlspecialchars($reg['current_school']); ?></td>
                                        <td><?php echo htmlspecialchars($reg['grade']); ?></td>
                                        <td><?php echo htmlspecialchars($reg['course_interest']); ?></td>
                                        <td><?php echo date('Y/m/d H:i', strtotime($reg['application_date'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

```

我已經完成了這些修改。現在，您在「場次設定」頁面應該能看到「查看名單」按鈕，點擊後即可瀏覽該場次的報名者列表。

<!--
[PROMPT_SUGGESTION]我希望在報名名單頁面增加一個「匯出成Excel」的功能。[/PROMPT_SUGGESTION]
[PROMPT_SUGGESTION]可以讓我在後台直接編輯報名者的資料嗎？[/PROMPT_SUGGESTION]
