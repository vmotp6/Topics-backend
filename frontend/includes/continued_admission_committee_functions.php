<?php
/**
 * 續招：招生委員會確認錄取 / 公告 / 寄信（後台共用函式）
 */
require_once __DIR__ . '/../../../Topics-frontend/frontend/config.php';

function caEnsureCommitteeTables(mysqli $conn): void {
    // 1) 公告表（僅供續招用）
    $conn->query("
        CREATE TABLE IF NOT EXISTS continued_admission_result_announcements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            scope VARCHAR(50) NOT NULL DEFAULT 'all' COMMENT 'all 或 department_code',
            year INT NOT NULL COMMENT '年度（西元）',
            title VARCHAR(255) NOT NULL,
            content TEXT NOT NULL,
            publish_at DATETIME NULL,
            published_at DATETIME NULL,
            created_by_user_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_scope_year (scope, year),
            INDEX idx_publish_at (publish_at),
            INDEX idx_published_at (published_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 2) 寄信佇列
    $conn->query("
        CREATE TABLE IF NOT EXISTS continued_admission_email_queue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            application_id INT NOT NULL,
            to_email VARCHAR(255) NOT NULL,
            to_name VARCHAR(255) NULL,
            department_code VARCHAR(50) NULL,
            result_status VARCHAR(50) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body MEDIUMTEXT NOT NULL,
            scheduled_at DATETIME NOT NULL,
            sent_at DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending/sent/failed',
            error TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_app_email_type (application_id, to_email, scheduled_at),
            INDEX idx_status_scheduled (status, scheduled_at),
            INDEX idx_application_id (application_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function caGetAnnounceTimeForDept(mysqli $conn, string $deptCode): ?string {
    $stmt = $conn->prepare("SELECT announce_time FROM department_quotas WHERE department_code = ? AND is_active = 1 LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param("s", $deptCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $t = $row['announce_time'] ?? null;
    return $t ? (string)$t : null;
}

function caGetGlobalAnnounceTime(mysqli $conn): ?string {
    $res = $conn->query("
        SELECT MAX(announce_time) AS max_announce_time
        FROM department_quotas
        WHERE is_active = 1 AND announce_time IS NOT NULL AND announce_time != ''
    ");
    if (!$res) return null;
    $row = $res->fetch_assoc();
    $t = $row['max_announce_time'] ?? null;
    return $t ? (string)$t : null;
}

function caUpsertAnnouncement(mysqli $conn, int $year, string $title, string $content, ?string $publishAt, ?int $createdByUserId): void {
    $scope = 'all';
    $stmt = $conn->prepare("
        INSERT INTO continued_admission_result_announcements (scope, year, title, content, publish_at, created_by_user_id)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            content = VALUES(content),
            publish_at = VALUES(publish_at),
            created_by_user_id = VALUES(created_by_user_id),
            updated_at = NOW()
    ");
    if (!$stmt) {
        throw new Exception("無法準備公告 upsert SQL: " . $conn->error);
    }
    $stmt->bind_param("sisssi", $scope, $year, $title, $content, $publishAt, $createdByUserId);
    $stmt->execute();
    $stmt->close();
}

function caGetAnnouncement(mysqli $conn, int $year): ?array {
    $scope = 'all';
    $stmt = $conn->prepare("SELECT * FROM continued_admission_result_announcements WHERE scope = ? AND year = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param("si", $scope, $year);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function caMarkAnnouncementPublished(mysqli $conn, int $year): void {
    $scope = 'all';
    $stmt = $conn->prepare("UPDATE continued_admission_result_announcements SET published_at = NOW(), updated_at = NOW() WHERE scope = ? AND year = ?");
    if (!$stmt) throw new Exception("無法更新公告 published_at: " . $conn->error);
    $stmt->bind_param("si", $scope, $year);
    $stmt->execute();
    $stmt->close();
}

function caEnsureBulletinBaseTables(mysqli $conn): bool {
    $need = ['bulletin_board', 'bulletin_types', 'bulletin_statuses'];
    foreach ($need as $t) {
        $r = $conn->query("SHOW TABLES LIKE '{$t}'");
        if (!$r || $r->num_rows === 0) return false;
    }
    // 確保有預設 type/status（若已存在則略過）
    $conn->query("INSERT IGNORE INTO bulletin_types (code, name, description, color, display_order) VALUES ('result','錄取結果','錄取結果、報到通知等結果公告','result',3)");
    $conn->query("INSERT IGNORE INTO bulletin_statuses (code, name, description, display_order) VALUES ('published','已發布','已發布的公告',2)");
    $conn->query("INSERT IGNORE INTO bulletin_statuses (code, name, description, display_order) VALUES ('draft','草稿','尚未發布的草稿',1)");

    // 確保 bulletin_urls / bulletin_files 存在（某些舊環境可能未執行擴充腳本）
    // 1) URLs
    $conn->query("
        CREATE TABLE IF NOT EXISTS bulletin_urls (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bulletin_id INT NOT NULL COMMENT '公告ID（外鍵關聯到 bulletin_board 表）',
            url VARCHAR(500) NOT NULL COMMENT '連結URL',
            title VARCHAR(255) NULL COMMENT '連結標題（可選）',
            display_order INT DEFAULT 0 COMMENT '顯示順序',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
            INDEX idx_bulletin_id (bulletin_id),
            INDEX idx_display_order (display_order),
            FOREIGN KEY (bulletin_id) REFERENCES bulletin_board(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='公告相關連結表'
    ");

    // 2) Files
    $conn->query("
        CREATE TABLE IF NOT EXISTS bulletin_files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bulletin_id INT NOT NULL COMMENT '公告ID（外鍵關聯到 bulletin_board 表）',
            file_path VARCHAR(500) NOT NULL COMMENT '檔案路徑',
            original_filename VARCHAR(255) NOT NULL COMMENT '原始檔案名稱',
            file_size INT NULL COMMENT '檔案大小（位元組）',
            file_type VARCHAR(100) NULL COMMENT '檔案類型（MIME type）',
            display_order INT DEFAULT 0 COMMENT '顯示順序',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
            INDEX idx_bulletin_id (bulletin_id),
            INDEX idx_display_order (display_order),
            FOREIGN KEY (bulletin_id) REFERENCES bulletin_board(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='公告檔案表'
    ");

    // 3) 補齊可能缺少的欄位（避免 INSERT 失敗導致前台看不到附件）
    try {
        $col = $conn->query("SHOW COLUMNS FROM bulletin_files LIKE 'file_type'");
        if (!$col || $col->num_rows === 0) {
            $conn->query("ALTER TABLE bulletin_files ADD COLUMN file_type VARCHAR(100) NULL COMMENT '檔案類型（MIME type）' AFTER file_size");
        }
        $col = $conn->query("SHOW COLUMNS FROM bulletin_files LIKE 'file_size'");
        if (!$col || $col->num_rows === 0) {
            $conn->query("ALTER TABLE bulletin_files ADD COLUMN file_size INT NULL COMMENT '檔案大小（位元組）' AFTER original_filename");
        }
        $col = $conn->query("SHOW COLUMNS FROM bulletin_files LIKE 'display_order'");
        if (!$col || $col->num_rows === 0) {
            $conn->query("ALTER TABLE bulletin_files ADD COLUMN display_order INT DEFAULT 0 COMMENT '顯示順序' AFTER file_type");
        }
    } catch (Throwable $e) {
        // ignore
    }

    return true;
}

/**
 * 將續招公告同步到前台公告欄 bulletin_board（type=result）
 * - 以 source=continued_admission_{year} 作為唯一識別，避免重複發佈
 * @param array $files 附件列表，格式：[['file_path' => '...', 'original_filename' => '...', 'file_size' => ...], ...]
 */
function caSyncAnnouncementToBulletin(mysqli $conn, int $year, int $userId, string $title, string $content, ?string $publishAt, string $statusCode = 'draft', array $files = []): ?int {
    if (!caEnsureBulletinBaseTables($conn)) return null;

    $source = "continued_admission_{$year}";
    $startDate = null;
    if ($publishAt) {
        $ts = strtotime($publishAt);
        if ($ts !== false) $startDate = date('Y-m-d', $ts);
    }
    if (!$startDate) $startDate = date('Y-m-d');

    // 注意：前台公告詳情頁會使用 nl2br(htmlspecialchars(content)) 渲染，
    // 所以這裡必須存「純文字」，不要存 HTML，否則會顯示成 <br/> / <p> 的文字。
    $link = "continued_admission_results.php?year={$year}";
    $plainContent = rtrim((string)$content) . "\n\n👉 查看續招錄取名單：{$link}\n";

    $sel = $conn->prepare("SELECT id FROM bulletin_board WHERE source = ? AND type_code = 'result' LIMIT 1");
    if (!$sel) return null;
    $sel->bind_param("s", $source);
    $sel->execute();
    $existing = $sel->get_result()->fetch_assoc();
    $sel->close();

    $bulletinId = null;
    if ($existing && isset($existing['id'])) {
        $bid = (int)$existing['id'];
        $u = $conn->prepare("UPDATE bulletin_board SET title=?, content=?, status_code=?, start_date=?, end_date=NULL, updated_at=NOW() WHERE id=?");
        if (!$u) return $bid;
        $u->bind_param("ssssi", $title, $plainContent, $statusCode, $startDate, $bid);
        $u->execute();
        $u->close();
        $bulletinId = $bid;
    } else {
    $ins = $conn->prepare("INSERT INTO bulletin_board (user_id, title, content, type_code, status_code, source, start_date, end_date, created_at) VALUES (?, ?, ?, 'result', ?, ?, ?, NULL, NOW())");
    if (!$ins) return null;
    $ins->bind_param("isssss", $userId, $title, $plainContent, $statusCode, $source, $startDate);
    $ins->execute();
    $newId = (int)$conn->insert_id;
    $ins->close();
        $bulletinId = $newId ?: null;
    }

    // 處理附件：先刪除舊附件，再插入新附件
    if ($bulletinId && !empty($files)) {
        // 刪除舊附件
        $del_stmt = $conn->prepare("DELETE FROM bulletin_files WHERE bulletin_id = ?");
        if ($del_stmt) {
            $del_stmt->bind_param("i", $bulletinId);
            $del_stmt->execute();
            $del_stmt->close();
        }

        // 插入新附件
        $ins_file_stmt = $conn->prepare("INSERT INTO bulletin_files (bulletin_id, file_path, original_filename, file_size, file_type, display_order) VALUES (?, ?, ?, ?, ?, ?)");
        if ($ins_file_stmt) {
            $order = 0;
            foreach ($files as $file) {
                $filePath = $file['file_path'] ?? '';
                $originalName = $file['original_filename'] ?? '';
                $fileSize = (int)($file['file_size'] ?? 0);
                $fileType = $file['file_type'] ?? 'application/octet-stream';
                
                // 續招公告附件改存前台既有的 uploads/bulletin_files/
                // 這裡不再做路徑轉換，直接照 file_path 寫入 bulletin_files
                
                $ins_file_stmt->bind_param("issisi", $bulletinId, $filePath, $originalName, $fileSize, $fileType, $order);
                $ins_file_stmt->execute();
                $order++;
            }
            $ins_file_stmt->close();
        }
    }

    return $bulletinId;
}

/**
 * 發布公告：寫入 published_at，並可選同步到前台公告欄
 * 注意：即使前台公告已經是草稿狀態，也會更新為 published 狀態
 * @param array $files 附件列表
 */
function caPublishAnnouncement(mysqli $conn, int $year, int $userId, bool $syncBulletin = true, array $files = []): array {
    $ann = caGetAnnouncement($conn, $year);
    if (!$ann) throw new Exception("找不到公告草稿，請先儲存公告內容");

    // 先標記為已發布
    caMarkAnnouncementPublished($conn, $year);
    
    $bulletinId = null;
    if ($syncBulletin) {
        // 同步到前台公告欄，狀態設為 'published'
        // 即使之前是草稿狀態，這裡也會更新為 published
        $bulletinId = caSyncAnnouncementToBulletin(
            $conn,
            $year,
            $userId,
            (string)($ann['title'] ?? "續招錄取名單公告（{$year}）"),
            (string)($ann['content'] ?? ''),
            isset($ann['publish_at']) ? (string)$ann['publish_at'] : null,
            'published',  // 強制設為 published 狀態
            $files
        );
        
        // 確保前台公告狀態確實更新為 published（防止更新失敗）
        if ($bulletinId) {
            $update_status_stmt = $conn->prepare("UPDATE bulletin_board SET status_code = 'published', updated_at = NOW() WHERE id = ?");
            if ($update_status_stmt) {
                $update_status_stmt->bind_param("i", $bulletinId);
                $update_status_stmt->execute();
                $update_status_stmt->close();
            }
        }
    }
    return ['bulletin_id' => $bulletinId];
}

/**
 * 排程發布：同步到前台公告欄為「草稿」，不會立刻公開；
 * 等 publish_at 到時由 publish_continued_admission_announcement.php 自動改成 published。
 * @param array $files 附件列表
 */
function caScheduleAnnouncement(mysqli $conn, int $year, int $userId, bool $syncBulletin = true, array $files = []): array {
    $ann = caGetAnnouncement($conn, $year);
    if (!$ann) throw new Exception("找不到公告草稿，請先儲存公告內容");

    $bulletinId = null;
    if ($syncBulletin) {
        $bulletinId = caSyncAnnouncementToBulletin(
            $conn,
            $year,
            $userId,
            (string)($ann['title'] ?? "續招錄取名單公告（{$year}）"),
            (string)($ann['content'] ?? ''),
            isset($ann['publish_at']) ? (string)$ann['publish_at'] : null,
            'draft',
            $files
        );
    }
    return ['bulletin_id' => $bulletinId];
}

function caBuildResultEmail(string $studentName, string $deptName, string $status, ?int $rank, string $announcementContent): array {
    $statusLabel = $status;
    if ($status === 'approved' || $status === 'AP') {
        $statusLabel = '正取' . ($rank ? " {$rank} 號" : '');
    } elseif ($status === 'waitlist' || $status === 'AD') {
        $statusLabel = '備取' . ($rank ? " {$rank} 號" : '');
    } elseif ($status === 'rejected' || $status === 'RE') {
        $statusLabel = '不錄取';
    }

    $subject = "【康寧大學續招】錄取結果通知 - {$studentName}";
    $safeContent = nl2br(htmlspecialchars($announcementContent, ENT_QUOTES, 'UTF-8'));
    $body = "
    <!DOCTYPE html>
    <html><head><meta charset='UTF-8'></head>
    <body style='font-family:Microsoft JhengHei, Arial, sans-serif; color:#333; line-height:1.7;'>
      <div style='max-width:680px; margin:0 auto; padding:24px;'>
        <div style='background:linear-gradient(90deg,#1890ff 0%,#096dd9 100%); color:#fff; padding:22px 24px; border-radius:10px 10px 0 0;'>
          <div style='font-size:22px; font-weight:700;'>續招錄取結果通知</div>
          <div style='opacity:.9; margin-top:6px;'>請依公告內容辦理報到</div>
        </div>
        <div style='background:#f8f9fa; padding:22px 24px; border-radius:0 0 10px 10px;'>
          <p>親愛的 <strong>{$studentName}</strong> 您好：</p>
          <div style='background:#fff; border-left:4px solid #1890ff; padding:14px 16px; border-radius:8px; margin:14px 0;'>
            <div><strong>分配科系：</strong>{$deptName}</div>
            <div><strong>錄取結果：</strong><span style='font-size:18px; font-weight:800; color:#096dd9;'>{$statusLabel}</span></div>
          </div>
          <div style='background:#fff; padding:14px 16px; border-radius:8px; border:1px solid #eee;'>
            <div style='font-weight:700; margin-bottom:8px;'>公告內容</div>
            <div>{$safeContent}</div>
          </div>
          <div style='margin-top:18px; font-size:13px; color:#666; text-align:center;'>
            此郵件由系統自動寄出，請勿直接回覆。如有疑問請聯繫招生中心。
          </div>
        </div>
      </div>
    </body></html>";

    $altBody = "續招錄取結果通知\n\n學生：{$studentName}\n分配科系：{$deptName}\n錄取結果：{$statusLabel}\n\n公告內容：\n{$announcementContent}\n";
    return ['subject' => $subject, 'body' => $body, 'altBody' => $altBody, 'statusLabel' => $statusLabel];
}

function caQueueResultEmails(mysqli $conn, int $year, string $announcementContent): array {
    // 取得 email 欄位是否存在（避免舊資料庫沒有 email）
    $hasEmail = false;
    $colRes = $conn->query("SHOW COLUMNS FROM continued_admission LIKE 'email'");
    if ($colRes && $colRes->num_rows > 0) $hasEmail = true;

    if (!$hasEmail) {
        return ['queued' => 0, 'skipped' => 0, 'reason' => 'continued_admission 缺少 email 欄位'];
    }

    // 取得科系名稱
    $deptNameMap = [];
    $deptRes = $conn->query("SELECT code, name FROM departments");
    if ($deptRes) {
        while ($r = $deptRes->fetch_assoc()) $deptNameMap[$r['code']] = $r['name'];
    }

    // 撈出已決定結果者（含今年）
    $stmt = $conn->prepare("
        SELECT id, name, email, assigned_department, status, admission_rank, apply_no
        FROM continued_admission
        WHERE assigned_department IS NOT NULL AND assigned_department != ''
          AND LEFT(apply_no, 4) = ?
          AND status IN ('approved','AP','waitlist','AD','rejected','RE')
    ");
    if (!$stmt) throw new Exception("無法準備寄信名單查詢: " . $conn->error);
    $stmt->bind_param("i", $year);
    $stmt->execute();
    $rs = $stmt->get_result();

    $queued = 0;
    $skipped = 0;
    while ($row = $rs->fetch_assoc()) {
        $to = trim((string)($row['email'] ?? ''));
        if ($to === '') { $skipped++; continue; }

        $deptCode = (string)($row['assigned_department'] ?? '');
        $deptName = $deptNameMap[$deptCode] ?? $deptCode;
        $announceAt = caGetAnnounceTimeForDept($conn, $deptCode) ?: caGetGlobalAnnounceTime($conn) ?: date('Y-m-d H:i:s');

        $mail = caBuildResultEmail((string)($row['name'] ?? '同學'), $deptName, (string)$row['status'], isset($row['admission_rank']) ? (int)$row['admission_rank'] : null, $announcementContent);

        $ins = $conn->prepare("
            INSERT IGNORE INTO continued_admission_email_queue
              (application_id, to_email, to_name, department_code, result_status, subject, body, scheduled_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$ins) { $skipped++; continue; }
        $appId = (int)$row['id'];
        $toName = (string)($row['name'] ?? '');
        $status = (string)$row['status'];
        $ins->bind_param("isssssss", $appId, $to, $toName, $deptCode, $status, $mail['subject'], $mail['body'], $announceAt);
        if ($ins->execute() && $ins->affected_rows > 0) $queued++;
        $ins->close();
    }
    $stmt->close();
    return ['queued' => $queued, 'skipped' => $skipped];
}


