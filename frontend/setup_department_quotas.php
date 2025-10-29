<?php
session_start();

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 資料庫連接設定
$host = '100.79.58.120';
$dbname = 'topics_good';
$db_username = 'root';
$db_password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("資料庫連接失敗: " . $e->getMessage());
}

$page_title = '科系名額設定';
$current_page = 'setup_department_quotas';

$message = '';
$messageType = '';

// 處理表單提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup_quotas'])) {
    try {
        // 讀取 SQL 檔案內容
        $sqlFile = 'create_department_quota_table.sql';
        if (!file_exists($sqlFile)) {
            throw new Exception('SQL 檔案不存在');
        }
        
        $sql = file_get_contents($sqlFile);
        $statements = explode(';', $sql);
        
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                $pdo->exec($statement);
            }
        }
        
        $message = '科系名額資料表建立成功！';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = '建立失敗: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// 檢查資料表是否已存在
$tableExists = false;
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'department_quotas'");
    $tableExists = $stmt->rowCount() > 0;
} catch (Exception $e) {
    // 忽略錯誤
}
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
            --primary-color: #1890ff; --text-color: #262626; --text-secondary-color: #8c8c8c;
            --border-color: #f0f0f0; --background-color: #f0f2f5; --card-background-color: #fff;
            --success-color: #52c41a; --danger-color: #f5222d; --warning-color: #faad14;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: var(--background-color); color: var(--text-color); overflow-x: hidden; }
        .dashboard { display: flex; min-height: 100vh; }
        .content { padding: 24px; width: 100%; }
        .breadcrumb { margin-bottom: 16px; font-size: 16px; color: var(--text-secondary-color); }
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        
        .card { background: var(--card-background-color); border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); border: 1px solid var(--border-color); margin-bottom: 24px; }
        .card-header { padding: 16px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: #fafafa; }
        .card-header h3 { font-size: 18px; font-weight: 600; color: var(--text-color); }
        .card-body { padding: 24px; }

        .btn-primary {
            padding: 12px 24px; border: 1px solid var(--primary-color); border-radius: 6px; cursor: pointer; font-size: 14px;
            transition: all 0.3s; background: var(--primary-color); color: white; display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-primary:hover { background: #40a9ff; border-color: #40a9ff; }
        .btn-secondary {
            padding: 12px 24px; border: 1px solid #d9d9d9; border-radius: 6px; cursor: pointer; font-size: 14px;
            transition: all 0.3s; background: #fff; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
            color: #595959;
        }
        .btn-secondary:hover { background: #f5f5f5; border-color: #40a9ff; color: #40a9ff; }

        .alert {
            padding: 16px; border-radius: 6px; margin-bottom: 20px; border: 1px solid;
        }
        .alert-success {
            background: #f6ffed; border-color: #b7eb8f; color: #52c41a;
        }
        .alert-error {
            background: #fff1f0; border-color: #ffa39e; color: #f5222d;
        }
        .alert-info {
            background: #e6f7ff; border-color: #91d5ff; color: #1890ff;
        }

        .setup-info {
            background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;
        }
        .setup-info h4 {
            color: var(--primary-color); margin-bottom: 12px;
        }
        .setup-info ul {
            margin-left: 20px; margin-bottom: 12px;
        }
        .setup-info li {
            margin-bottom: 6px;
        }

        .status-check {
            display: flex; align-items: center; gap: 8px; margin-bottom: 16px;
        }
        .status-icon {
            width: 20px; height: 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 12px;
        }
        .status-icon.success { background: var(--success-color); }
        .status-icon.error { background: var(--danger-color); }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            <div class="content">
                <div class="breadcrumb">
                    <a href="index.php">首頁</a> / <a href="continued_admission_list.php">續招報名管理</a> / <?php echo $page_title; ?>
                </div>

                <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-database"></i> <?php echo $page_title; ?></h3>
                    </div>
                    <div class="card-body">
                        <div class="status-check">
                            <div class="status-icon <?php echo $tableExists ? 'success' : 'error'; ?>">
                                <i class="fas fa-<?php echo $tableExists ? 'check' : 'times'; ?>"></i>
                            </div>
                            <span>
                                <?php if ($tableExists): ?>
                                    科系名額資料表已存在
                                <?php else: ?>
                                    科系名額資料表尚未建立
                                <?php endif; ?>
                            </span>
                        </div>

                        <div class="setup-info">
                            <h4><i class="fas fa-info-circle"></i> 設定說明</h4>
                            <p>此功能將為續招報名系統建立科系名額管理功能，包括：</p>
                            <ul>
                                <li>建立 <code>department_quotas</code> 資料表，用於儲存各科系的招生名額。</li>
                                <li>此資料表將與 <code>admission_courses</code> (在「場次設定」中管理) 關聯</li>
                            </ul>
                            <p><strong>注意：</strong>如果資料表已存在，此操作將不會重複建立。</p>
                        </div>

                        <?php if (!$tableExists): ?>
                        <form method="POST">
                            <button type="submit" name="setup_quotas" class="btn-primary">
                                <i class="fas fa-play"></i> 建立科系名額資料表
                            </button>
                        </form>
                        <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            科系名額資料表已建立完成！您現在可以：
                            <ul style="margin-top: 8px;">
                                <li><a href="department_quota_management.php">管理科系名額</a></li>
                                <li><a href="continued_admission_list.php">查看續招報名列表</a></li>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
