-- 創建出席紀錄資料表
CREATE TABLE IF NOT EXISTS `attendance_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL COMMENT '場次ID',
  `application_id` int(11) NOT NULL COMMENT '報名ID (admission_applications.id)',
  `attendance_status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '出席狀態: 0=未到, 1=已到',
  `check_in_time` datetime DEFAULT NULL COMMENT '簽到時間',
  `notes` text DEFAULT NULL COMMENT '備註',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新時間',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_session_application` (`session_id`, `application_id`),
  KEY `idx_session_id` (`session_id`),
  KEY `idx_application_id` (`application_id`),
  CONSTRAINT `fk_attendance_session` FOREIGN KEY (`session_id`) REFERENCES `admission_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attendance_application` FOREIGN KEY (`application_id`) REFERENCES `admission_applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='出席紀錄表';

