-- 將 signatures.user_id 關聯到 user.id 的外鍵腳本
-- 執行前請先備份資料庫（重要）
-- 備份方式範例：在 phpMyAdmin 匯出整個資料庫 topics_good

-- 1. 檢查是否有不合法的 user_id（在 user 表中找不到的）
-- 建議先在 phpMyAdmin 或直接執行下列查詢，確認結果為 0 筆再加外鍵：
-- SELECT s.*
-- FROM signatures s
-- LEFT JOIN user u ON s.user_id = u.id
-- WHERE s.user_id IS NOT NULL
--   AND u.id IS NULL;

-- 若上面的查詢有資料，請先手動修正或將那些筆的 user_id 設為 NULL，例如：
-- UPDATE signatures
-- SET user_id = NULL
-- WHERE user_id = 99999; -- 不存在的 user id 範例

-- 2. 確保 user_id 有索引（若已經有索引會自動略過錯誤）
ALTER TABLE `signatures`
  ADD INDEX `idx_signatures_user_id` (`user_id`);

-- 3. 新增外鍵約束，將 signatures.user_id 關聯到 user.id
--   - 刪除 user 時：將對應簽章的 user_id 設為 NULL（保留簽章紀錄）
--   - 更新 user.id 時：同步更新（一般不會改 id，這只是保險）
ALTER TABLE `signatures`
  ADD CONSTRAINT `fk_signatures_user`
  FOREIGN KEY (`user_id`) REFERENCES `user`(`id`)
  ON DELETE SET NULL
  ON UPDATE CASCADE;


