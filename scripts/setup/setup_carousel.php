<?php
/**
 * 輪播系統設置腳本
 * 用於創建輪播相關的資料庫表
 */

// 資料庫配置
$host = '100.79.58.120';
$username = 'root';
$password = '';
$database = 'topics_good';

try {
    // 建立資料庫連線
    $pdo = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ 資料庫連線成功\n";
    
    // 讀取SQL腳本
    $sqlFile = __DIR__ . '/../database/create_carousel_tables.sql';
    
    if (!file_exists($sqlFile)) {
        throw new Exception("SQL腳本文件不存在: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // 分割SQL語句（以分號為分隔符）
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    echo "📝 開始執行SQL語句...\n";
    
    foreach ($statements as $statement) {
        if (empty($statement) || strpos($statement, '--') === 0) {
            continue; // 跳過空語句和註釋
        }
        
        try {
            $pdo->exec($statement);
            echo "✅ 執行成功: " . substr($statement, 0, 50) . "...\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') !== false) {
                echo "⚠️  表已存在，跳過: " . substr($statement, 0, 50) . "...\n";
            } else {
                echo "❌ 執行失敗: " . $e->getMessage() . "\n";
                echo "   語句: " . substr($statement, 0, 100) . "...\n";
            }
        }
    }
    
    echo "\n🎉 輪播系統設置完成！\n";
    echo "📋 已創建的表:\n";
    echo "   - carousel_items (輪播項目表)\n";
    echo "   - carousel_settings (輪播設定表)\n";
    echo "\n📝 預設數據已插入，包含4個輪播項目和基本設定\n";
    echo "\n🔗 現在您可以:\n";
    echo "   1. 啟動後台API服務器 (python api.py)\n";
    echo "   2. 訪問後台管理頁面進行輪播管理\n";
    echo "   3. 前台首頁將自動從API載入輪播數據\n";
    
} catch (Exception $e) {
    echo "❌ 設置失敗: " . $e->getMessage() . "\n";
    echo "\n🔧 請檢查:\n";
    echo "   1. 資料庫服務是否啟動\n";
    echo "   2. 資料庫連線資訊是否正確\n";
    echo "   3. 資料庫 'topics_good' 是否存在\n";
    echo "   4. 用戶是否有創建表的權限\n";
}
?>
