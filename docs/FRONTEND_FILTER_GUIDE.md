# 前台頁面過濾隱藏貼文說明

## 問題說明

後台管理頁面已經可以正確更新 `is_published` 欄位來隱藏貼文，但前台頁面 `senior_messages.php` 需要添加過濾條件，才能不顯示隱藏的貼文。

## 前台頁面需要修改

在 `senior_messages.php` 中，查詢貼文的 SQL 語句需要添加過濾條件：

### 修改前（會顯示所有貼文，包括隱藏的）：
```php
$sql = "SELECT * FROM senior_messages ORDER BY created_at DESC";
```

### 修改後（只顯示未隱藏的貼文）：
```php
// 只顯示 is_published = 1 的貼文（隱藏的貼文 is_published = 0）
$sql = "SELECT * FROM senior_messages WHERE is_published = 1 ORDER BY created_at DESC";
```

## 完整的修改範例

```php
// 在 senior_messages.php 中
$conn = getDatabaseConnection();

// 查詢貼文時，過濾掉隱藏的貼文
$sql = "SELECT sm.*, 
               u.username, u.name as user_name
        FROM senior_messages sm
        LEFT JOIN user u ON sm.user_id = u.id
        WHERE sm.is_published = 1  -- 只顯示未隱藏的貼文
        ORDER BY sm.created_at DESC";

$result = $conn->query($sql);
// ... 後續處理
```

## 注意事項

1. **預設值處理**：如果資料庫中有些舊資料的 `is_published` 為 NULL，可能需要：
   ```sql
   WHERE (sm.is_published = 1 OR sm.is_published IS NULL)
   ```
   或者先將 NULL 值更新為 1：
   ```sql
   UPDATE senior_messages SET is_published = 1 WHERE is_published IS NULL;
   ```

2. **管理員查看**：如果需要讓管理員在前台也能看到隱藏的貼文，可以：
   ```php
   $is_admin = ($_SESSION['role'] ?? '') === 'ADM';
   $where_clause = $is_admin ? '' : 'WHERE sm.is_published = 1';
   $sql = "SELECT * FROM senior_messages $where_clause ORDER BY created_at DESC";
   ```

3. **確認欄位名稱**：請確認資料庫中確實有 `is_published` 欄位，如果沒有，需要：
   - 使用 `is_hidden` 欄位（如果存在）
   - 或者添加 `is_published` 欄位

## 測試方法

1. 在後台點擊「隱藏」按鈕
2. 檢查資料庫：`SELECT id, is_published FROM senior_messages WHERE id = 12;`
3. 確認 `is_published` 已更新為 0
4. 刷新前台頁面，該貼文應該不再顯示

## 如果還是不行

請檢查：
1. 前台頁面的 SQL 查詢是否正確過濾了 `is_published = 0` 的貼文
2. 是否有其他地方也在查詢貼文但沒有過濾條件
3. 是否有快取機制需要清除

