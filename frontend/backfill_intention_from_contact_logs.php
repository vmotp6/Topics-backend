<?php
/**
 * 一次性回填：依「每筆名單的最新一筆聯絡紀錄」重新計算意願度，寫入 enrollment_intention.intention_level，
 * 使列表意願欄能顯示以前填寫過的聯絡紀錄所對應的意願度。
 * 使用方式：登入後在瀏覽器開啟此頁執行一次即可。
 */
require_once __DIR__ . '/session_config.php';
checkBackendLogin();

$config_path = '../../Topics-frontend/frontend/config.php';
if (!file_exists($config_path)) {
    $alt_paths = [
        '../../../Topics-frontend/frontend/config.php',
        __DIR__ . '/../../Topics-frontend/frontend/config.php',
        dirname(__DIR__) . '/../Topics-frontend/frontend/config.php'
    ];
    foreach ($alt_paths as $alt_path) {
        if (file_exists($alt_path)) {
            $config_path = $alt_path;
            break;
        }
    }
}
if (!file_exists($config_path)) {
    die('系統錯誤：找不到設定檔');
}
require_once $config_path;
require_once __DIR__ . '/enrollment_intention_eval.php';
require_once __DIR__ . '/includes/intention_change_log.php';

header('Content-Type: text/html; charset=utf-8');

$conn = getDatabaseConnection();

// 每筆名單取最新一筆聯絡紀錄（依 contact_date DESC, id DESC）
$sql = "
    SELECT c.id AS contact_log_id, c.enrollment_id, c.teacher_id, c.notes
    FROM enrollment_contact_logs c
    INNER JOIN (
        SELECT enrollment_id, MAX(id) AS max_id
        FROM enrollment_contact_logs
        GROUP BY enrollment_id
    ) t ON c.enrollment_id = t.enrollment_id AND c.id = t.max_id
";
$result = $conn->query($sql);
if (!$result) {
    echo '<p style="color:red;">查詢失敗：' . htmlspecialchars($conn->error) . '</p>';
    $conn->close();
    exit;
}

$updated = 0;
$skipped = 0;
$errors = [];

// 預備：更新就讀意願名單的 intention_level
$upd = $conn->prepare("UPDATE enrollment_intention SET intention_level = ? WHERE id = ?");
if (!$upd) {
    echo '<p style="color:red;">準備 UPDATE intention_level 失敗：' . htmlspecialchars($conn->error) . '</p>';
    $conn->close();
    exit;
}

// 預備：更新聯絡紀錄 notes，補上「系統自動評估意願度」說明（若尚未存在）
$updNotes = $conn->prepare("UPDATE enrollment_contact_logs SET notes = ? WHERE enrollment_id = ? AND id = ?");
if (!$updNotes) {
    echo '<p style="color:red;">準備 UPDATE notes 失敗：' . htmlspecialchars($conn->error) . '</p>';
    $upd->close();
    $conn->close();
    exit;
}

$getOld = $conn->prepare("SELECT intention_level FROM enrollment_intention WHERE id = ? LIMIT 1");

while ($row = $result->fetch_assoc()) {
    $enrollment_id = (int)$row['enrollment_id'];
    $log_id = (int)($row['contact_log_id'] ?? 0);
    $log_teacher_id = isset($row['teacher_id']) ? (int)$row['teacher_id'] : null;
    $notes = (string)($row['notes'] ?? '');
    $level = evaluateIntentionLevelFromNotes($notes);
    if ($level === null) {
        $skipped++;
        continue;
    }

    $old_level = null;
    if ($getOld) {
        $getOld->bind_param("i", $enrollment_id);
        $getOld->execute();
        $res = $getOld->get_result();
        $orow = $res->fetch_assoc();
        if ($orow && isset($orow['intention_level']) && (string)$orow['intention_level'] !== '') {
            $old_level = trim((string)$orow['intention_level']);
        }
    }

    // 1) 更新就讀意願名單的 intention_level（列表意願欄用）
    $upd->bind_param("si", $level, $enrollment_id);
    if ($upd->execute() && $upd->affected_rows >= 0) {
        $updated++;
        logIntentionChange($conn, $enrollment_id, $old_level, $level, $log_id ?: null, $log_teacher_id ?: null);
    } else {
        $errors[] = "enrollment_id={$enrollment_id}: " . $upd->error;
    }

    // 2) 若聯絡紀錄 notes 尚未包含「系統自動評估意願度」，則補上一行說明，讓聯絡紀錄也看得到
    if (strpos($notes, '系統自動評估意願度') === false) {
        $level_label = ($level === 'high') ? '高意願' : (($level === 'low') ? '低意願' : '中意願');
        $newNotes = $notes;
        if ($newNotes !== '') {
            $newNotes .= "\n";
        }
        $newNotes .= "系統自動評估意願度：" . $level_label;
        $updNotes->bind_param("sii", $newNotes, $enrollment_id, $log_id);
        $updNotes->execute();
    }
}
if ($getOld) $getOld->close();

$updNotes->close();
$upd->close();
$conn->close();

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>回填意願度</title></head><body>';
echo '<h2>依既有聯絡紀錄回填意願度</h2>';
echo '<p>已依每筆名單的<strong>最新一筆聯絡紀錄</strong>重新計算意願度，並寫入就讀意願名單的意願欄位，且在該筆聯絡紀錄中補上「系統自動評估意願度」說明。</p>';
echo '<ul>';
echo '<li><strong>已更新意願度：</strong>' . (int)$updated . ' 筆</li>';
echo '<li><strong>無法從 notes 解析（略過）：</strong>' . (int)$skipped . ' 筆</li>';
if (!empty($errors)) {
    echo '<li style="color:red;"><strong>更新失敗：</strong>' . count($errors) . ' 筆 — ' . htmlspecialchars(implode('；', array_slice($errors, 0, 5)));
    if (count($errors) > 5) echo '…';
    echo '</li>';
}
echo '</ul>';
echo '<p><a href="enrollment_list.php">返回就讀意願名單</a></p>';
echo '</body></html>';
