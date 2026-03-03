<?php
/**
 * 意願變動紀錄：建立表與寫入 helper
 * 表 enrollment_intention_change_log 記錄每次 intention_level 的變動與時間。
 */

/** 表名 */
define('INTENTION_CHANGE_LOG_TABLE', 'enrollment_intention_change_log');

/**
 * 若表不存在則建立
 * @param mysqli $conn
 */
function ensureIntentionChangeLogTable($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS " . INTENTION_CHANGE_LOG_TABLE . " (
        id INT AUTO_INCREMENT PRIMARY KEY,
        enrollment_id INT NOT NULL,
        old_level VARCHAR(20) DEFAULT NULL COMMENT '變動前 high/medium/low，NULL 表示初次設定',
        new_level VARCHAR(20) NOT NULL COMMENT '變動後 high/medium/low',
        contact_log_id INT DEFAULT NULL COMMENT '觸發此次變動的聯絡紀錄 id',
        teacher_id INT DEFAULT NULL COMMENT '觸發者（聯絡紀錄或說明會操作者）',
        changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_enrollment (enrollment_id),
        KEY idx_changed_at (changed_at),
        KEY idx_teacher (teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $conn->query($sql);
}

/**
 * 寫入一筆意願變動紀錄
 * @param mysqli $conn
 * @param int $enrollment_id
 * @param string|null $old_level 變動前 high/medium/low，無則 null
 * @param string $new_level 變動後 high/medium/low
 * @param int|null $contact_log_id 觸發的聯絡紀錄 id，無則 null
 * @param int|null $teacher_id 觸發者 user id，無則 null
 */
function logIntentionChange($conn, $enrollment_id, $old_level, $new_level, $contact_log_id = null, $teacher_id = null) {
    ensureIntentionChangeLogTable($conn);
    $stmt = $conn->prepare(
        "INSERT INTO " . INTENTION_CHANGE_LOG_TABLE . " (enrollment_id, old_level, new_level, contact_log_id, teacher_id, changed_at) VALUES (?, ?, ?, ?, ?, NOW())"
    );
    if (!$stmt) return;
    $cid = $contact_log_id !== null && $contact_log_id !== '' ? (int)$contact_log_id : 0;
    $tid = $teacher_id !== null && $teacher_id !== '' ? (int)$teacher_id : 0;
    $stmt->bind_param("issii", $enrollment_id, $old_level, $new_level, $cid, $tid);
    $stmt->execute();
    $stmt->close();
}
