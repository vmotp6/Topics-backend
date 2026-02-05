-- 畢業生彙整寄送科系主任之寄送記錄表（避免同一年度同一科系重複寄送）
-- 由 send_graduated_students_to_directors.php 自動建立，亦可手動執行本腳本

CREATE TABLE IF NOT EXISTS graduated_director_email_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  graduation_roc_year INT NOT NULL COMMENT '畢業民國年（例：115=今年畢業）',
  department_code VARCHAR(50) NOT NULL COMMENT '科系代碼或名稱',
  sent_at DATETIME NOT NULL COMMENT '寄送時間',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_year_dept (graduation_roc_year, department_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
