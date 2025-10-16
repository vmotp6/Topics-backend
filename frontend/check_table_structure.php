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

// 檢查 continued_admission 表結構
$stmt = $pdo->prepare("DESCRIBE continued_admission");
$stmt->execute();
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 檢查是否有資料
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM continued_admission");
$stmt->execute();
$count_result = $stmt->fetch(PDO::FETCH_ASSOC);
$total_count = $count_result['count'];

// 檢查狀態分布
$stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM continued_admission GROUP BY status");
$stmt->execute();
$status_distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>資料表結構檢查</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .section { margin: 30px 0; }
        .section h2 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        .status-badge { padding: 4px 8px; border-radius: 4px; color: white; font-size: 12px; }
        .status-pending { background-color: #ffa500; }
        .status-approved { background-color: #28a745; }
        .status-rejected { background-color: #dc3545; }
        .status-waitlist { background-color: #17a2b8; }
    </style>
</head>
<body>
    <h1>續招報名資料表結構檢查</h1>
    
    <div class="section">
        <h2>📊 資料統計</h2>
        <p><strong>總筆數：</strong><?php echo $total_count; ?> 筆</p>
        
        <h3>狀態分布：</h3>
        <table>
            <thead>
                <tr>
                    <th>狀態</th>
                    <th>筆數</th>
                    <th>百分比</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($status_distribution as $status): ?>
                <tr>
                    <td>
                        <span class="status-badge status-<?php echo $status['status']; ?>">
                            <?php 
                            $status_text = [
                                'pending' => '待審核',
                                'approved' => '錄取',
                                'rejected' => '不錄取',
                                'waitlist' => '備取'
                            ];
                            echo $status_text[$status['status']] ?? $status['status'];
                            ?>
                        </span>
                    </td>
                    <td><?php echo $status['count']; ?></td>
                    <td><?php echo round(($status['count'] / $total_count) * 100, 1); ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <div class="section">
        <h2>🏗️ 資料表結構</h2>
        <table>
            <thead>
                <tr>
                    <th>欄位名稱</th>
                    <th>資料類型</th>
                    <th>允許空值</th>
                    <th>鍵值</th>
                    <th>預設值</th>
                    <th>額外資訊</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($columns as $column): ?>
                <tr>
                    <td><strong><?php echo $column['Field']; ?></strong></td>
                    <td><?php echo $column['Type']; ?></td>
                    <td><?php echo $column['Null']; ?></td>
                    <td><?php echo $column['Key']; ?></td>
                    <td><?php echo $column['Default'] ?? 'NULL'; ?></td>
                    <td><?php echo $column['Extra']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <div class="section">
        <h2>🔍 最近5筆資料</h2>
        <?php
        $stmt = $pdo->prepare("SELECT id, name, status, reviewed_at, created_at FROM continued_admission ORDER BY created_at DESC LIMIT 5");
        $stmt->execute();
        $recent_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>姓名</th>
                    <th>狀態</th>
                    <th>審核時間</th>
                    <th>報名時間</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_data as $row): ?>
                <tr>
                    <td><?php echo $row['id']; ?></td>
                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                    <td>
                        <span class="status-badge status-<?php echo $row['status']; ?>">
                            <?php 
                            $status_text = [
                                'pending' => '待審核',
                                'approved' => '錄取',
                                'rejected' => '不錄取',
                                'waitlist' => '備取'
                            ];
                            echo $status_text[$row['status']] ?? $row['status'];
                            ?>
                        </span>
                    </td>
                    <td><?php echo $row['reviewed_at'] ?? '未審核'; ?></td>
                    <td><?php echo $row['created_at']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <div class="section">
        <h2>🔗 相關連結</h2>
        <p>
            <a href="test_status_update.php">測試狀態更新功能</a> | 
            <a href="admission_center.php">招生中心</a> | 
            <a href="continued_admission_list.php">續招審核列表</a>
        </p>
    </div>
</body>
</html>
