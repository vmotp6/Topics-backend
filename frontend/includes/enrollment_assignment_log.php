<?php
/**
 * 就讀意願「科系分配」歷程記錄
 * 記錄何時分配給第一意願、何時轉到第二意願等，方便查詢與報表。
 */

/**
 * 確保 enrollment_department_assignment_log 表存在
 * @param mysqli $conn
 */
function ensure_enrollment_assignment_log_table(mysqli $conn): void {
    $r = @$conn->query("SHOW TABLES LIKE 'enrollment_department_assignment_log'");
    if ($r && $r->num_rows > 0) {
        return;
    }
    $sql = "CREATE TABLE enrollment_department_assignment_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        enrollment_id INT NOT NULL COMMENT 'enrollment_intention.id',
        department_code VARCHAR(50) NOT NULL COMMENT '分配到的科系代碼',
        choice_order TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1=第一意願 2=第二意願 ...',
        source VARCHAR(20) NOT NULL DEFAULT 'initial' COMMENT 'initial=表單/初次 reassign=自動轉派 manual=手動改派',
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '分配時間',
        KEY idx_enrollment (enrollment_id),
        KEY idx_assigned_at (assigned_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='就讀意願科系分配歷程'";
    @$conn->query($sql);
}

/**
 * 寫入一筆分配歷程
 * @param mysqli $conn
 * @param int $enrollment_id
 * @param string $department_code
 * @param int $choice_order 1=第一意願, 2=第二意願...
 * @param string $source 'initial' | 'reassign' | 'manual'
 * @param string|null $assigned_at 指定分配時間（Y-m-d H:i:s），null 則用 NOW()
 * @return bool
 */
function insert_enrollment_assignment_log(mysqli $conn, int $enrollment_id, string $department_code, int $choice_order, string $source = 'initial', ?string $assigned_at = null): bool {
    ensure_enrollment_assignment_log_table($conn);
    $source = in_array($source, ['initial', 'reassign', 'manual'], true) ? $source : 'initial';
    if ($assigned_at !== null && $assigned_at !== '') {
        $stmt = $conn->prepare("INSERT INTO enrollment_department_assignment_log (enrollment_id, department_code, choice_order, source, assigned_at) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("isiss", $enrollment_id, $department_code, $choice_order, $source, $assigned_at);
    } else {
        $stmt = $conn->prepare("INSERT INTO enrollment_department_assignment_log (enrollment_id, department_code, choice_order, source, assigned_at) VALUES (?, ?, ?, ?, NOW())");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("isis", $enrollment_id, $department_code, $choice_order, $source);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * 檢查該筆意願是否已有「此科系」的分配歷程（用於轉派前補登目前意願的分配時間）
 * @param mysqli $conn
 * @param int $enrollment_id
 * @param string $department_code
 * @return bool
 */
function has_assignment_log_for_department(mysqli $conn, int $enrollment_id, string $department_code): bool {
    $stmt = $conn->prepare("SELECT 1 FROM enrollment_department_assignment_log WHERE enrollment_id = ? AND department_code = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("is", $enrollment_id, $department_code);
    $stmt->execute();
    $has = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $has;
}

/**
 * 取得某科系在該生志願中的順序（1=第一意願...），找不到回傳 0
 * @param mysqli $conn
 * @param int $enrollment_id
 * @param string $department_code
 * @return int
 */
function get_choice_order_for_department(mysqli $conn, int $enrollment_id, string $department_code): int {
    $stmt = $conn->prepare("SELECT choice_order FROM enrollment_choices WHERE enrollment_id = ? AND UPPER(TRIM(department_code)) = UPPER(TRIM(?)) LIMIT 1");
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param("is", $enrollment_id, $department_code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['choice_order'] : 0;
}

/**
 * 查詢某筆意願是否已有分配歷程（用於判斷是否為「第一次分配」）
 * @param mysqli $conn
 * @param int $enrollment_id
 * @return int 筆數
 */
function count_enrollment_assignment_logs(mysqli $conn, int $enrollment_id): int {
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM enrollment_department_assignment_log WHERE enrollment_id = ?");
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param("i", $enrollment_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
}
