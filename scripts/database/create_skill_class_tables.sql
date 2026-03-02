-- ============================================================
-- 技藝班管理相關資料表
-- 角色：招生中心(STA) 新增技藝班場次、註記國中生分配到各科系
--       主任(DI) 僅見自己科系場次、分配老師負責技藝班
--       老師(TEA) 管理每週上課內容、查看國中生每週回饋
-- ============================================================

-- 技藝班場次（招生中心建立，每場次屬一科系）
CREATE TABLE IF NOT EXISTS `skill_class_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_name` varchar(200) NOT NULL COMMENT '場次名稱',
  `department_code` varchar(50) NOT NULL COMMENT '科系代碼（對應 departments.code）',
  `description` text DEFAULT NULL COMMENT '場次說明',
  `session_date` date DEFAULT NULL COMMENT '場次日期',
  `session_end_date` date DEFAULT NULL COMMENT '場次結束日期',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否啟用：0=停用，1=啟用',
  `created_by` int(11) DEFAULT NULL COMMENT '建立者 user.id',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_department_code` (`department_code`),
  KEY `idx_session_date` (`session_date`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='技藝班場次';

-- 國中生分配到各科系/場次（招生中心註記哪些國中生分配到各科系技藝班）
CREATE TABLE IF NOT EXISTS `skill_class_student_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL COMMENT '技藝班場次 id',
  `department_code` varchar(50) NOT NULL COMMENT '科系代碼',
  `student_name` varchar(100) NOT NULL COMMENT '國中生姓名',
  `school_code` varchar(50) DEFAULT NULL COMMENT '學校代碼（可對應 school_data）',
  `school_name` varchar(150) DEFAULT NULL COMMENT '學校名稱',
  `contact_phone` varchar(50) DEFAULT NULL COMMENT '聯絡電話',
  `email` varchar(100) DEFAULT NULL COMMENT '電子郵件',
  `notes` text DEFAULT NULL COMMENT '備註',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_session_id` (`session_id`),
  KEY `idx_department_code` (`department_code`),
  CONSTRAINT `fk_skill_student_session` FOREIGN KEY (`session_id`) REFERENCES `skill_class_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='技藝班國中生分配（註記哪些國中生分配到各科系）';

-- 主任分配老師負責技藝班（各科系主任只能為自己科系場次分配老師）
CREATE TABLE IF NOT EXISTS `skill_class_teacher_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL COMMENT '技藝班場次 id',
  `teacher_user_id` int(11) NOT NULL COMMENT '負責老師 user.id',
  `assigned_by` int(11) DEFAULT NULL COMMENT '分配者（主任）user.id',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_session_teacher` (`session_id`, `teacher_user_id`),
  KEY `idx_teacher_user_id` (`teacher_user_id`),
  KEY `idx_assigned_by` (`assigned_by`),
  CONSTRAINT `fk_skill_teacher_session` FOREIGN KEY (`session_id`) REFERENCES `skill_class_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='技藝班教師分配';

-- 每週上課內容（老師填寫）
CREATE TABLE IF NOT EXISTS `skill_class_weekly_content` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL COMMENT '技藝班場次 id',
  `week_number` int(11) NOT NULL COMMENT '第幾週',
  `week_start_date` date DEFAULT NULL COMMENT '該週起始日',
  `content` text DEFAULT NULL COMMENT '上課內容',
  `created_by` int(11) DEFAULT NULL COMMENT '填寫者（老師）user.id',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_session_week` (`session_id`, `week_number`),
  KEY `idx_session_id` (`session_id`),
  CONSTRAINT `fk_skill_weekly_session` FOREIGN KEY (`session_id`) REFERENCES `skill_class_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='技藝班每週上課內容';

-- 國中生每週回饋（老師可查看）
CREATE TABLE IF NOT EXISTS `skill_class_student_feedback` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL COMMENT '技藝班場次 id',
  `student_assignment_id` int(11) NOT NULL COMMENT '國中生分配 id',
  `week_number` int(11) NOT NULL COMMENT '第幾週',
  `feedback_content` text DEFAULT NULL COMMENT '回饋內容',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_session_week` (`session_id`, `week_number`),
  KEY `idx_student_assignment_id` (`student_assignment_id`),
  CONSTRAINT `fk_skill_feedback_session` FOREIGN KEY (`session_id`) REFERENCES `skill_class_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_skill_feedback_assignment` FOREIGN KEY (`student_assignment_id`) REFERENCES `skill_class_student_assignments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='國中生每週回饋';
