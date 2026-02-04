<?php
/**
 * 續招錄取公告：到時間自動發布（可由 Windows 工作排程器 / cron 定時執行）
 *
 * - Web 模式：需要後台登入（避免被外部濫用）
 * - CLI 模式：可用工作排程器直接執行（不需 session）
 */
date_default_timezone_set('Asia/Taipei');

$is_cli = (PHP_SAPI === 'cli');

if (!$is_cli) {
    // Web 模式：
    // - 預設需要後台登入（避免被外部濫用）
    // - 但為了支援 Windows 工作排程器/cron「用 URL 觸發」且不依賴 php.exe，
    //   允許帶 token 的請求在未登入狀態下觸發
    $token_ok = false;
    $token = (string)($_GET['token'] ?? '');
    $token_file = __DIR__ . '/cron_tokens.php';
    if ($token !== '' && is_file($token_file)) {
        require_once $token_file;
        if (defined('CA_PUBLISH_TOKEN') && CA_PUBLISH_TOKEN !== 'CHANGE_ME' && hash_equals((string)CA_PUBLISH_TOKEN, $token)) {
            $token_ok = true;
        }
    }

    if (!$token_ok) {
        require_once __DIR__ . '/session_config.php';
        checkBackendLogin();
    }
    header('Content-Type: text/plain; charset=utf-8');

    if (!$token_ok) {
        $user_role = $_SESSION['role'] ?? '';
        if (!in_array($user_role, ['ADM', 'STA'], true)) {
            http_response_code(403);
            echo "權限不足\n";
            exit;
        }
    }
}

require_once '../../Topics-frontend/frontend/config.php';
require_once __DIR__ . '/includes/continued_admission_committee_functions.php';

$conn = getDatabaseConnection();
caEnsureCommitteeTables($conn);

$year = (int)date('Y');
if ($is_cli) {
    global $argv;
    foreach (($argv ?? []) as $arg) {
        if (strpos($arg, '--year=') === 0) {
            $v = (int)substr($arg, 7);
            if ($v > 2000) $year = $v;
        }
    }
} else {
    $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
}

$now = date('Y-m-d H:i:s');
echo "[{$now}] 檢查續招公告是否可發布（year={$year}）...\n";

$ann = caGetAnnouncement($conn, $year);
if (!$ann) {
    echo "找不到公告草稿（continued_admission_result_announcements）。\n";
    $conn->close();
    exit;
}

$publishAt = $ann['publish_at'] ?? null;
$publishedAt = $ann['published_at'] ?? null;

// 檢查前台公告是否還存在，如果不存在則清理 published_at，允許重新發布
if ($publishedAt) {
    $source = "continued_admission_{$year}";
    $check_stmt = $conn->prepare("SELECT id, status_code FROM bulletin_board WHERE source = ? AND type_code = 'result' LIMIT 1");
    if ($check_stmt) {
        $check_stmt->bind_param("s", $source);
        $check_stmt->execute();
        $check_res = $check_stmt->get_result();
        $bulletin_data = $check_res->fetch_assoc();
        $check_stmt->close();
        
        if (!$bulletin_data) {
            // 前台公告已被刪除，清理 published_at，允許重新發布
            $cleanup_stmt = $conn->prepare("UPDATE continued_admission_result_announcements SET published_at = NULL WHERE scope = 'all' AND year = ?");
            if ($cleanup_stmt) {
                $cleanup_stmt->bind_param("i", $year);
                $cleanup_stmt->execute();
                $cleanup_stmt->close();
                echo "⚠️ 前台公告已被刪除，已清理 published_at，將重新發布。\n";
            }
        } else {
            // 檢查前台公告狀態，如果是草稿且時間已到，需要重新發布
            $bulletin_status = $bulletin_data['status_code'] ?? '';
            if ($bulletin_status === 'published') {
                echo "已發布（published_at={$publishedAt}），不需處理。\n";
                $conn->close();
                exit;
            } else {
                // 前台公告是草稿狀態，但後台已標記為發布，需要同步狀態
                echo "⚠️ 前台公告狀態為草稿，但後台已標記為發布，將更新前台狀態。\n";
            }
        }
    } else {
        echo "已發布（published_at={$publishedAt}），不需處理。\n";
        $conn->close();
        exit;
    }
}

// 若沒設定 publish_at，允許立即發布
// 檢查時間：如果設定了 publish_at，必須時間到了或過了才能發布
if ($publishAt) {
    $publish_ts = strtotime($publishAt);
    $now_ts = strtotime($now);
    
    if ($publish_ts === false) {
        echo "⚠️ publish_at 時間格式錯誤（{$publishAt}），允許立即發布。\n";
    } elseif ($publish_ts > $now_ts) {
        echo "尚未到公告時間（publish_at={$publishAt}，現在={$now}）。\n";
        $conn->close();
        exit;
    } else {
        echo "✓ 已到公告時間（publish_at={$publishAt}，現在={$now}），開始發布。\n";
    }
} else {
    echo "⚠️ 未設定 publish_at，允許立即發布。\n";
}

// CLI 模式下沒有 user_id，使用 1 兜底（若你們管理員 id 不是 1，可改成從參數帶入）
$userId = 1;
if (!$is_cli) {
    // token 模式未登入時，無法取得 user_id；維持 1 當兜底即可（只用於 bulletin_board 的 user_id 欄位）
    $userId = (int)($_SESSION['user_id'] ?? 1);
}

try {
    $res = caPublishAnnouncement($conn, $year, $userId, true);
    echo "✅ 已發布公告" . ($res['bulletin_id'] ? "（同步公告ID: {$res['bulletin_id']}）" : "") . "\n";
} catch (Throwable $e) {
    echo "❌ 發布失敗：{$e->getMessage()}\n";
}

$conn->close();




