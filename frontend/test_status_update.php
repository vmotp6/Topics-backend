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

// 獲取續招報名資料
$stmt = $pdo->prepare("SELECT id, name, status, reviewed_at FROM continued_admission ORDER BY created_at DESC LIMIT 10");
$stmt->execute();
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>狀態更新測試</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .status-badge { padding: 4px 8px; border-radius: 4px; color: white; font-size: 12px; }
        .status-pending { background-color: #ffa500; }
        .status-approved { background-color: #28a745; }
        .status-rejected { background-color: #dc3545; }
        .status-waitlist { background-color: #17a2b8; }
        button { padding: 4px 8px; margin: 2px; cursor: pointer; }
    </style>
</head>
<body>
    <h1>續招報名狀態測試</h1>
    
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>姓名</th>
                <th>目前狀態</th>
                <th>審核時間</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($applications as $app): ?>
            <tr>
                <td><?php echo $app['id']; ?></td>
                <td><?php echo htmlspecialchars($app['name']); ?></td>
                <td>
                    <span class="status-badge status-<?php echo $app['status']; ?>">
                        <?php 
                        $status_text = [
                            'pending' => '待審核',
                            'approved' => '錄取',
                            'rejected' => '不錄取',
                            'waitlist' => '備取'
                        ];
                        echo $status_text[$app['status']] ?? $app['status'];
                        ?>
                    </span>
                </td>
                <td><?php echo $app['reviewed_at'] ?? '未審核'; ?></td>
                <td>
                    <button onclick="updateStatus(<?php echo $app['id']; ?>, 'approved')">設為錄取</button>
                    <button onclick="updateStatus(<?php echo $app['id']; ?>, 'rejected')">設為不錄取</button>
                    <button onclick="updateStatus(<?php echo $app['id']; ?>, 'waitlist')">設為備取</button>
                    <button onclick="updateStatus(<?php echo $app['id']; ?>, 'pending')">設為待審核</button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <div id="result" style="margin-top: 20px; padding: 10px; background: #f8f9fa; border-radius: 4px;"></div>
    
    <script>
        function updateStatus(id, status) {
            const resultDiv = document.getElementById('result');
            resultDiv.innerHTML = '更新中...';
            
            fetch('update_admission_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    id: id,
                    status: status
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    resultDiv.innerHTML = `✅ ${data.message}`;
                    // 重新載入頁面以顯示更新後的狀態
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                } else {
                    resultDiv.innerHTML = `❌ ${data.message}`;
                }
            })
            .catch(error => {
                resultDiv.innerHTML = `❌ 錯誤: ${error.message}`;
            });
        }
    </script>
</body>
</html>
