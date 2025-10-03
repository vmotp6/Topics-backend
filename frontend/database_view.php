<?php
session_start();

// 檢查是否已登入，如果沒有登入則跳轉到登入頁面
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 處理登出
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header("Location: login.php");
    exit;
}

// 資料庫連接設定
$host = 'localhost';
$dbname = 'topics_good';
$username = 'root';
$password = '';
$charset = 'utf8mb4';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=$charset", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("資料庫連接失敗: " . $e->getMessage());
}

// 獲取所有資料表
$tables_query = "SHOW TABLES";
$tables_stmt = $pdo->query($tables_query);
$tables = $tables_stmt->fetchAll(PDO::FETCH_COLUMN);

// 獲取當前選中的資料表
$selected_table = isset($_GET['table']) ? $_GET['table'] : (count($tables) > 0 ? $tables[0] : null);

// 獲取選中資料表的資料
$table_data = [];
$table_columns = [];
if ($selected_table) {
    try {
        // 獲取欄位資訊
        $columns_query = "DESCRIBE `$selected_table`";
        $columns_stmt = $pdo->query($columns_query);
        $table_columns = $columns_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 獲取資料（限制顯示前100筆）
        $data_query = "SELECT * FROM `$selected_table` LIMIT 100";
        $data_stmt = $pdo->query($data_query);
        $table_data = $data_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 獲取總筆數
        $count_query = "SELECT COUNT(*) as total FROM `$selected_table`";
        $count_stmt = $pdo->query($count_query);
        $total_count = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    } catch (PDOException $e) {
        $error_message = "查詢資料表失敗: " . $e->getMessage();
    }
}

// 設置頁面標題
$page_title = '資料庫查看';
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>資料庫查看 - Topics 後台管理系統</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f0f2f5;
            color: #262626;
            overflow-x: hidden;
        }
        
        /* 主介面樣式 */
        .dashboard {
            display: flex;
            min-height: 100vh;
        }
        
        /* 內容區域 */
        .content {
            padding: 18px 36px;
        }
        
        /* 麵包屑 */
        .breadcrumb {
            margin-bottom: 16px;
            font-size: 16px;
            color: #8c8c8c;
        }
        
        .breadcrumb a {
            color: #1890ff;
            text-decoration: none;
        }
        
        .breadcrumb a:hover {
            text-decoration: underline;
        }
        
        /* 資料表選擇器 */
        .table-selector {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid #f0f0f0;
            margin-bottom: 24px;
            overflow: hidden;
        }
        
        .table-header {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f0f0;
            background: #fafafa;
        }
        
        .table-title {
            font-size: 18px;
            font-weight: 600;
            color: #262626;
        }
        
        .table-content {
            padding: 24px;
        }
        
        .table-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
        }
        
        .table-item {
            padding: 12px 16px;
            background: #f5f5f5;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            text-decoration: none;
            color: #262626;
            transition: all 0.3s;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .table-item:hover {
            background: #e6f7ff;
            border-color: #1890ff;
            color: #1890ff;
        }
        
        .table-item.active {
            background: #1890ff;
            border-color: #1890ff;
            color: white;
        }
        
        /* 資料顯示區域 */
        .data-container {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid #f0f0f0;
            overflow: hidden;
        }
        
        .data-header {
            padding: 16px 24px;
            background: #fafafa;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .data-title {
            font-size: 18px;
            font-weight: 600;
            color: #262626;
        }
        
        .data-count {
            color: #8c8c8c;
            font-size: 14px;
        }
        
        .data-table-container {
            overflow-x: auto;
            max-height: 600px;
            overflow-y: auto;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        .data-table th {
            background: #fafafa;
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            color: #262626;
            border-bottom: 1px solid #f0f0f0;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .data-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #f0f0f0;
            color: #595959;
        }
        
        .data-table tr:hover {
            background: #f5f5f5;
        }
        
        .data-table tr:nth-child(even) {
            background: #fafafa;
        }
        
        .data-table tr:nth-child(even):hover {
            background: #f0f0f0;
        }
        
        /* 錯誤訊息 */
        .error-message {
            background: #fff2f0;
            border: 1px solid #ffccc7;
            color: #ff4d4f;
            padding: 16px;
            border-radius: 6px;
            margin: 16px 24px;
        }
        
        /* 空資料訊息 */
        .empty-message {
            text-align: center;
            padding: 40px;
            color: #8c8c8c;
            font-size: 16px;
        }
        
        /* 載入中 */
        .loading {
            text-align: center;
            padding: 40px;
            color: #8c8c8c;
            font-size: 16px;
        }
        
        /* 響應式設計 */
        @media (max-width: 768px) {
            .content {
                padding: 12px 16px;
            }
            
            .table-list {
                grid-template-columns: 1fr;
            }
            
            .data-header {
                flex-direction: column;
                gap: 12px;
                align-items: flex-start;
            }
            
            .data-table-container {
                max-height: 400px;
            }
        }
    </style>
</head>
<body>
    <!-- 主介面 -->
    <div class="dashboard">
        <!-- 引入側邊欄 -->
        <?php include 'sidebar.php'; ?>
        
        <!-- 主內容區 -->
        <div class="main-content" id="mainContent">
            <!-- 引入標題欄 -->
            <?php include 'header.php'; ?>
            
            <!-- 內容區域 -->
            <div class="content">
                <!-- 麵包屑導航 -->
                <div class="breadcrumb">
                    <a href="index.php">首頁</a> > 資料庫查看
                </div>
                
                <!-- 資料表選擇器 -->
                <div class="table-selector">
                    <div class="table-header">
                        <div class="table-title">
                            <i class="fas fa-database"></i>
                            選擇資料表
                        </div>
                    </div>
                    <div class="table-content">
                        <div class="table-list">
                            <?php foreach ($tables as $table): ?>
                                <a href="?table=<?php echo urlencode($table); ?>" 
                                   class="table-item <?php echo $selected_table === $table ? 'active' : ''; ?>">
                                    <i class="fas fa-table"></i>
                                    <?php echo htmlspecialchars($table); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <!-- 資料顯示區域 -->
                <?php if ($selected_table): ?>
                    <div class="data-container">
                        <div class="data-header">
                            <div class="data-title">
                                <i class="fas fa-table"></i>
                                資料表: <?php echo htmlspecialchars($selected_table); ?>
                            </div>
                            <div class="data-count">
                                共 <?php echo isset($total_count) ? number_format($total_count) : '0'; ?> 筆資料
                                <?php if (isset($total_count) && $total_count > 100): ?>
                                    (顯示前 100 筆)
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if (isset($error_message)): ?>
                            <div class="error-message">
                                <i class="fas fa-exclamation-circle"></i>
                                <?php echo htmlspecialchars($error_message); ?>
                            </div>
                        <?php elseif (empty($table_data)): ?>
                            <div class="empty-message">
                                <i class="fas fa-inbox"></i>
                                此資料表沒有資料
                            </div>
                        <?php else: ?>
                            <div class="data-table-container">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <?php foreach ($table_columns as $column): ?>
                                                <th><?php echo htmlspecialchars($column['Field']); ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($table_data as $row): ?>
                                            <tr>
                                                <?php foreach ($row as $value): ?>
                                                    <td>
                                                        <?php 
                                                        if (is_null($value)) {
                                                            echo '<span style="color: #8c8c8c; font-style: italic;">NULL</span>';
                                                        } else {
                                                            echo htmlspecialchars($value);
                                                        }
                                                        ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="data-container">
                        <div class="empty-message">
                            <i class="fas fa-database"></i>
                            資料庫中沒有資料表
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
