-- 建立科系名額管理資料表
CREATE TABLE IF NOT EXISTS `department_quotas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `department_name` varchar(100) NOT NULL COMMENT '科系名稱',
  `department_code` varchar(20) NOT NULL COMMENT '科系代碼',
  `total_quota` int(11) NOT NULL DEFAULT 0 COMMENT '總名額',
  `current_enrolled` int(11) NOT NULL DEFAULT 0 COMMENT '目前已錄取人數',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否啟用',
  `description` text COMMENT '科系描述',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `department_code` (`department_code`),
  KEY `idx_department_name` (`department_name`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='科系名額管理表';

-- 插入預設科系資料
INSERT INTO `department_quotas` (`department_name`, `department_code`, `total_quota`, `description`) VALUES
('資訊科', 'IT', 30, '資訊科技相關課程'),
('電子科', 'EE', 25, '電子工程相關課程'),
('機械科', 'ME', 20, '機械工程相關課程'),
('商管科', 'BM', 35, '商業管理相關課程'),
('餐飲科', 'CF', 40, '餐飲服務相關課程'),
('美容科', 'BC', 30, '美容美髮相關課程'),
('幼保科', 'EC', 25, '幼兒保育相關課程'),
('觀光科', 'TM', 20, '觀光旅遊相關課程');

-- 建立續招報名與科系的關聯表（用於追蹤每個學生的志願選擇）
CREATE TABLE IF NOT EXISTS `continued_admission_choices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `application_id` int(11) NOT NULL COMMENT '續招報名ID',
  `department_code` varchar(20) NOT NULL COMMENT '科系代碼',
  `choice_order` int(11) NOT NULL COMMENT '志願順序',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_application_id` (`application_id`),
  KEY `idx_department_code` (`department_code`),
  KEY `idx_choice_order` (`choice_order`),
  FOREIGN KEY (`application_id`) REFERENCES `continued_admission` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`department_code`) REFERENCES `department_quotas` (`department_code`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='續招報名志願選擇表';
