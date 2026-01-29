-- 執行前請先備份資料庫

ALTER TABLE `continued_admission_scores`
ADD COLUMN `signature_id` INT NULL COMMENT '關聯到 signatures 表的簽章ID' AFTER `scored_at`,
ADD INDEX `idx_signature_id` (`signature_id`);

-- 如需要，可另外手動補上外鍵約束（建議在 signatures 表結構穩定後再加）：
-- ALTER TABLE `continued_admission_scores`
--   ADD CONSTRAINT `fk_score_signature`
--   FOREIGN KEY (`signature_id`) REFERENCES `signatures` (`id`)
--   ON DELETE SET NULL ON UPDATE CASCADE;

