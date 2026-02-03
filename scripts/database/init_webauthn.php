<?php
/**
 * WebAuthn 資料庫初始化腳本
 * 執行此腳本來建立 WebAuthn 相關的資料庫表
 */

require_once __DIR__ . '/../../Topics-frontend/frontend/config.php';

echo "開始初始化 WebAuthn 資料庫表...\n\n";

try {
    $conn = getDatabaseConnection();
    
    // 讀取 SQL 檔案
    $sql_file = __DIR__ . '/create_webauthn_tables.sql';
    if (!file_exists($sql_file)) {
        throw new Exception("SQL 檔案不存在: $sql_file");
    }
    
    $sql = file_get_contents($sql_file);
    
    // 分割 SQL 語句（以分號分隔）
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && !preg_match('/^--/', $stmt);
        }
    );
    
    foreach ($statements as $statement) {
        // 跳過註解
        $statement = preg_replace('/--.*$/m', '', $statement);
        $statement = trim($statement);
        
        if (empty($statement)) {
            continue;
        }
        
        // 處理 ALTER TABLE 語句（MySQL 不支援 IF NOT EXISTS）
        if (preg_match('/ALTER TABLE\s+(\w+)\s+ADD COLUMN IF NOT EXISTS\s+(\w+)/i', $statement, $matches)) {
            $table_name = $matches[1];
            $column_name = $matches[2];
            
            // 檢查欄位是否已存在
            $check_column = $conn->query("SHOW COLUMNS FROM `$table_name` LIKE '$column_name'");
            if ($check_column && $check_column->num_rows > 0) {
                echo "✓ 表 $table_name 的欄位 $column_name 已存在，跳過\n";
                continue;
            }
            
            // 移除 IF NOT EXISTS
            $statement = preg_replace('/ADD COLUMN IF NOT EXISTS/i', 'ADD COLUMN', $statement);
        }
        
        // 處理 CREATE INDEX IF NOT EXISTS（MySQL 5.7 及以下不支援）
        if (preg_match('/CREATE INDEX IF NOT EXISTS\s+(\w+)\s+ON\s+(\w+)/i', $statement, $matches)) {
            $index_name = $matches[1];
            $table_name = $matches[2];
            
            // 檢查索引是否已存在
            $check_index = $conn->query("SHOW INDEX FROM `$table_name` WHERE Key_name = '$index_name'");
            if ($check_index && $check_index->num_rows > 0) {
                echo "✓ 表 $table_name 的索引 $index_name 已存在，跳過\n";
                continue;
            }
            
            // 移除 IF NOT EXISTS
            $statement = preg_replace('/CREATE INDEX IF NOT EXISTS/i', 'CREATE INDEX', $statement);
        }
        
        // 執行 SQL
        if ($conn->query($statement)) {
            echo "✓ SQL 執行成功\n";
        } else {
            $error = $conn->error;
            // 忽略已存在的錯誤
            if (strpos($error, 'already exists') !== false || 
                strpos($error, 'Duplicate column') !== false) {
                echo "⚠ 已存在，跳過: $error\n";
            } else {
                echo "✗ SQL 執行失敗: $error\n";
                echo "  語句: " . substr($statement, 0, 100) . "...\n";
            }
        }
    }
    
    echo "\n✅ WebAuthn 資料庫初始化完成！\n";
    echo "\n注意事項：\n";
    echo "1. 請確保已執行 create_webauthn_tables.sql 中的所有語句\n";
    echo "2. 如果 signatures 表已存在，需要手動執行 ALTER TABLE 語句添加新欄位\n";
    echo "3. 建議在生產環境執行前先備份資料庫\n";
    
    $conn->close();
    
} catch (Exception $e) {
    echo "❌ 錯誤: " . $e->getMessage() . "\n";
    exit(1);
}
?>

