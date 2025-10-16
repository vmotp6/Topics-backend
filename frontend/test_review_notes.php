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

// 檢查是否有 review_notes 欄位
$stmt = $pdo->prepare("SHOW COLUMNS FROM continued_admission LIKE 'review_notes'");
$stmt->execute();
$has_review_notes = $stmt->rowCount() > 0;

// 獲取續招報名資料
$stmt = $pdo->prepare("SELECT id, name, status, review_notes, reviewed_at FROM continued_admission ORDER BY created_at DESC LIMIT 5");
$stmt->execute();
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>審核備註功能測試</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .status-badge { padding: 4px 8px; border-radius: 4px; color: white; font-size: 12px; }
        .status-pending { background-color: #ffa500; }
        .status-approved { background-color: #28a745; }
        .status-rejected { background-color: #dc3545; }
        .status-waitlist { background-color: #17a2b8; }
        .test-form { background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; }
        .test-form input, .test-form textarea, .test-form select { 
            width: 100%; padding: 8px; margin: 5px 0; border: 1px solid #ddd; border-radius: 4px; 
        }
        .test-form button { 
            background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; 
        }
        .test-form button:hover { background: #0056b3; }
        .result { margin: 20px 0; padding: 15px; border-radius: 4px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
    </style>
</head>
<body>
    <h1>審核備註功能測試</h1>
    
    <div class="info result">
        <h3>📋 資料表結構檢查</h3>
        <p><strong>review_notes 欄位：</strong><?php echo $has_review_notes ? '✅ 存在' : '❌ 不存在'; ?></p>
        <?php if ($has_review_notes): ?>
            <p>✅ 可以正常使用審核備註功能</p>
        <?php else: ?>
            <p>⚠️ 需要添加 review_notes 欄位才能使用審核備註功能</p>
        <?php endif; ?>
    </div>
    
    <div class="test-form">
        <h3>🧪 測試審核備註功能</h3>
        <form id="testForm">
            <label>選擇學生：</label>
            <select id="studentSelect" required>
                <option value="">請選擇學生</option>
                <?php foreach ($applications as $app): ?>
                <option value="<?php echo $app['id']; ?>">
                    <?php echo htmlspecialchars($app['name']); ?> (ID: <?php echo $app['id']; ?>)
                </option>
                <?php endforeach; ?>
            </select>
            
            <label>審核狀態：</label>
            <select id="statusSelect" required>
                <option value="">請選擇狀態</option>
                <option value="approved">錄取</option>
                <option value="rejected">不錄取</option>
                <option value="waitlist">備取</option>
                <option value="pending">待審核</option>
            </select>
            
            <label>審核備註：</label>
            <textarea id="reviewNotes" rows="4" placeholder="請輸入審核備註..."></textarea>
            
            <button type="submit">測試更新</button>
        </form>
    </div>
    
    <div id="testResult"></div>
    
    <h3>📊 最近5筆資料</h3>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>姓名</th>
                <th>狀態</th>
                <th>審核備註</th>
                <th>審核時間</th>
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
                <td><?php echo htmlspecialchars($app['review_notes'] ?? '無'); ?></td>
                <td><?php echo $app['reviewed_at'] ?? '未審核'; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <div style="margin: 30px 0;">
        <h3>🔗 相關連結</h3>
        <p>
            <a href="continued_admission_list.php">續招審核列表</a> | 
            <a href="admission_center.php">招生中心</a> | 
            <a href="check_table_structure.php">資料表結構檢查</a>
        </p>
    </div>
    
    <script>
        document.getElementById('testForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const studentId = document.getElementById('studentSelect').value;
            const status = document.getElementById('statusSelect').value;
            const reviewNotes = document.getElementById('reviewNotes').value;
            const resultDiv = document.getElementById('testResult');
            
            if (!studentId || !status) {
                resultDiv.innerHTML = '<div class="error result">請選擇學生和狀態</div>';
                return;
            }
            
            resultDiv.innerHTML = '<div class="info result">測試中...</div>';
            
            fetch('update_admission_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    id: studentId,
                    status: status,
                    review_notes: reviewNotes
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    resultDiv.innerHTML = '<div class="success result">✅ 測試成功！' + data.message + '</div>';
                    // 重新載入頁面以顯示更新後的資料
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    resultDiv.innerHTML = '<div class="error result">❌ 測試失敗：' + data.message + '</div>';
                }
            })
            .catch(error => {
                resultDiv.innerHTML = '<div class="error result">❌ 錯誤：' + error.message + '</div>';
            });
        });
    </script>
</body>
</html>
