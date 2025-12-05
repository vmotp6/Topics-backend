# 前台頁面修復隱藏功能說明

## 問題確認

後台已經正確將 `is_published` 設為 0，但前台頁面仍然顯示隱藏的貼文。

**原因**：前台頁面 `senior_messages.php` 的 SQL 查詢沒有過濾 `is_published = 0` 的貼文。

## 解決方案

### 步驟 1：找到前台頁面的查詢語句

在 `Topics-frontend/frontend/senior_messages.php` 中找到類似這樣的查詢：

```php
// 找到這樣的查詢
$sql = "SELECT * FROM senior_messages ORDER BY created_at DESC";
// 或
$sql = "SELECT sm.* FROM senior_messages sm ORDER BY sm.created_at DESC";
```

### 步驟 2：添加過濾條件

將查詢修改為：

```php
// 修改後：只顯示未隱藏的貼文
$sql = "SELECT * FROM senior_messages WHERE is_published = 1 ORDER BY created_at DESC";
// 或
$sql = "SELECT sm.* FROM senior_messages sm WHERE sm.is_published = 1 ORDER BY sm.created_at DESC";
```

### 步驟 3：處理 NULL 值（如果需要）

如果資料庫中有舊資料的 `is_published` 為 NULL，可以：

**選項 A：只顯示 is_published = 1 的貼文（NULL 不顯示）**
```php
WHERE is_published = 1
```

**選項 B：顯示 is_published = 1 或 NULL 的貼文**
```php
WHERE (is_published = 1 OR is_published IS NULL)
```

**選項 C：先更新所有 NULL 為 1（推薦）**
```sql
UPDATE senior_messages SET is_published = 1 WHERE is_published IS NULL;
```
然後使用選項 A。

### 步驟 4：完整的修改範例

如果前台頁面有 JOIN 其他表，確保在正確的位置添加 WHERE 條件：

```php
// 修改前
$sql = "SELECT sm.*, 
               u.username, u.name as user_name
        FROM senior_messages sm
        LEFT JOIN user u ON sm.user_id = u.id
        ORDER BY sm.created_at DESC";

// 修改後
$sql = "SELECT sm.*, 
               u.username, u.name as user_name
        FROM senior_messages sm
        LEFT JOIN user u ON sm.user_id = u.id
        WHERE sm.is_published = 1  -- 添加這一行
        ORDER BY sm.created_at DESC";
```

### 步驟 5：測試

1. 在後台隱藏一則貼文（設為 is_published = 0）
2. 刷新前台頁面
3. 該貼文應該不再顯示

## 快速檢查腳本

執行以下腳本檢查資料庫狀態：

```bash
php check_hidden_posts.php
```

## 如果還是不行

請檢查：

1. **是否有快取**：清除瀏覽器快取或使用無痕模式測試
2. **是否有多個查詢**：檢查頁面中是否有多個地方在查詢貼文
3. **AJAX 載入**：如果貼文是通過 AJAX 載入的，也需要在 AJAX 查詢中添加過濾條件
4. **確認欄位名稱**：確認資料庫中確實是 `is_published` 欄位，不是其他名稱

## 需要幫助？

如果修改後還是不行，請提供：
1. 前台頁面 `senior_messages.php` 中查詢貼文的 SQL 語句
2. 執行 `php check_hidden_posts.php` 的輸出結果

