<?php
require_once __DIR__ . '/session_config.php';
checkBackendLogin();

require_once '../../Topics-frontend/frontend/config.php';
require_once __DIR__ . '/includes/continued_admission_ranking.php';
require_once __DIR__ . '/includes/continued_admission_committee_functions.php';

$user_role = $_SESSION['role'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);

// 招生委員會/招生中心：先以 ADM/STA 控制（如需新增角色再擴充）
if (!in_array($user_role, ['ADM', 'STA'], true)) {
    http_response_code(403);
    echo "權限不足";
    exit;
}

date_default_timezone_set('Asia/Taipei');
$year = (int)date('Y');

$conn = getDatabaseConnection();
caEnsureCommitteeTables($conn);

$message = null;
$message_ok = true;

// 讀取現有公告草稿/已發布
$announcement = caGetAnnouncement($conn, $year);
$default_title = "續招錄取名單公告（{$year}）";
$default_content = "【報到提醒】\n請依招生中心公告時間與規定辦理報到。\n\n（請在此編輯公告內容，例如報到時間、地點、應備文件、聯絡窗口等。）";

// 取得統一公告時間（若無則空）
$global_announce_time = caGetGlobalAnnounceTime($conn);

// 自動檢查並發布已到期的公告（如果時間到了但還沒發布）
// 注意：這裡只檢查並發布，不重新讀取附件（因為附件已經在前台公告欄中）
if ($announcement && !empty($announcement['publish_at']) && empty($announcement['published_at'])) {
    $publish_ts = strtotime($announcement['publish_at']);
    $now_ts = time();
    if ($publish_ts <= $now_ts) {
        // 時間已到，自動發布
        // 檢查前台公告欄是否已有草稿（包含附件）
        $source = "continued_admission_{$year}";
        $check_bulletin_stmt = $conn->prepare("SELECT id FROM bulletin_board WHERE source = ? AND type_code = 'result' LIMIT 1");
        $existing_files = [];
        if ($check_bulletin_stmt) {
            $check_bulletin_stmt->bind_param("s", $source);
            $check_bulletin_stmt->execute();
            $check_bulletin_res = $check_bulletin_stmt->get_result();
            $existing_bulletin = $check_bulletin_res->fetch_assoc();
            $check_bulletin_stmt->close();
            
            // 如果有現有公告，獲取其附件（避免丟失）
            if ($existing_bulletin) {
                $bulletin_id = (int)$existing_bulletin['id'];
                $files_stmt = $conn->prepare("SELECT file_path, original_filename, file_size, file_type FROM bulletin_files WHERE bulletin_id = ?");
                if ($files_stmt) {
                    $files_stmt->bind_param("i", $bulletin_id);
                    $files_stmt->execute();
                    $files_result = $files_stmt->get_result();
                    while ($file_row = $files_result->fetch_assoc()) {
                        $existing_files[] = [
                            'file_path' => $file_row['file_path'],
                            'original_filename' => $file_row['original_filename'],
                            'file_size' => (int)$file_row['file_size'],
                            'file_type' => $file_row['file_type'] ?? 'application/octet-stream'
                        ];
                    }
                    $files_stmt->close();
                }
            }
        }
        
        try {
            $res = caPublishAnnouncement($conn, $year, $user_id, true, $existing_files);
            // 重新讀取公告資料
            $announcement = caGetAnnouncement($conn, $year);
        } catch (Throwable $e) {
            // 發布失敗，記錄錯誤但不中斷頁面載入
            error_log("自動發布公告失敗: " . $e->getMessage());
        }
    }
}

// 取得本年度「委員會確認錄取結果」簽章狀態（僅供畫面顯示）
// 注意：每次登入需重新簽章，所以只檢查當前 session 中的簽章狀態
$committee_signature = null;
$session_signature_key = "committee_signature_{$year}_{$user_id}";

// 檢查 session 中是否有簽章記錄（每次登入需重新簽章）
if (isset($_SESSION[$session_signature_key]) && !empty($_SESSION[$session_signature_key])) {
    $signature_id = (int)$_SESSION[$session_signature_key];
    try {
        $sig_stmt = $conn->prepare("
            SELECT id, created_at, signature_path
            FROM signatures
            WHERE id = ? AND user_id = ?
              AND document_type = 'continued_admission_committee_confirm'
              AND document_id = ?
            LIMIT 1
        ");
        if ($sig_stmt) {
            $sig_stmt->bind_param("iii", $signature_id, $user_id, $year);
            $sig_stmt->execute();
            $sig_res = $sig_stmt->get_result();
            if ($sig_res && ($row = $sig_res->fetch_assoc())) {
                $committee_signature = $row;
            }
            $sig_stmt->close();
        }
    } catch (Throwable $e) {
        $committee_signature = null;
        // 如果查詢失敗，清除 session 中的記錄
        unset($_SESSION[$session_signature_key]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_announcement') {
            $title = trim((string)($_POST['title'] ?? $default_title));
            $content = trim((string)($_POST['content'] ?? $default_content));
            $publish_at = trim((string)($_POST['publish_at'] ?? ''));
            $publish_at = $publish_at !== '' ? $publish_at : $global_announce_time;

            if ($title === '' || $content === '') {
                throw new Exception("公告標題與內容不可為空");
            }

            caUpsertAnnouncement($conn, $year, $title, $content, $publish_at, $user_id);
            $announcement = caGetAnnouncement($conn, $year);
            $message = "已儲存公告草稿";
        }

        if ($action === 'save_and_schedule_announcement') {
            $title = trim((string)($_POST['title'] ?? $default_title));
            $content = trim((string)($_POST['content'] ?? $default_content));
            // 使用統一公告時間，不允許用戶設定
            $publish_at = $global_announce_time;

            if ($title === '' || $content === '') {
                throw new Exception("公告標題與內容不可為空");
            }

            if (!$publish_at) {
                throw new Exception("請先在「科系名額管理」設定統一公告錄取時間");
            }

            // 儲存公告內容
            caUpsertAnnouncement($conn, $year, $title, $content, $publish_at, $user_id);
            $announcement = caGetAnnouncement($conn, $year);

            // 處理附件上傳（存到前台公告既有的 uploads/bulletin_files，才能在前台詳情顯示/下載）
            $uploaded_files = [];
            $upload_dir = __DIR__ . '/../../Topics-frontend/frontend/uploads/bulletin_files/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            if (isset($_FILES['files']) && !empty($_FILES['files']['tmp_name'][0])) {
                $allowed_extensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'gif'];
                $max_file_size = 10 * 1024 * 1024; // 10MB

                foreach ($_FILES['files']['tmp_name'] as $key => $tmp_name) {
                    if ($_FILES['files']['error'][$key] === UPLOAD_ERR_OK && !empty($tmp_name)) {
                        $original_name = $_FILES['files']['name'][$key];
                        $file_size = $_FILES['files']['size'][$key];
                        $file_extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

                        if ($file_size > $max_file_size) {
                            throw new Exception("檔案 {$original_name} 大小超過 10MB 限制");
                        }

                        if (!in_array($file_extension, $allowed_extensions)) {
                            throw new Exception("檔案 {$original_name} 類型不允許");
                        }

                        $safe_filename = time() . '_' . uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $original_name);
                        $target_file = $upload_dir . $safe_filename;

                        if (move_uploaded_file($tmp_name, $target_file)) {
                            // 獲取檔案 MIME 類型
                            $file_type = $_FILES['files']['type'][$key] ?? 'application/octet-stream';
                            
                            $uploaded_files[] = [
                                // 前台可讀的相對路徑（download_bulletin_file.php 也會用到）
                                'file_path' => 'uploads/bulletin_files/' . $safe_filename,
                                'original_filename' => $original_name,
                                'file_size' => $file_size,
                                'file_type' => $file_type
                            ];
                        }
                    }
                }
            }

            // 儲存附件資訊到資料庫（需要擴充 continued_admission_result_announcements 表或使用新表）
            // 這裡先簡單處理，可以後續擴充

            // 排程/立即發布公告：
            // - 若已超過公告時間（publish_at <= now），則立即發布到前台公告欄（published）
            // - 否則先同步為草稿（draft），等待排程執行 publish_continued_admission_announcement.php
            $sync = isset($_POST['sync_bulletin']) && $_POST['sync_bulletin'] === '1';
            $file_count = count($uploaded_files);
            $now_ts = time();
            $publish_ts = $publish_at ? strtotime($publish_at) : false;

            if ($publish_ts !== false && $publish_ts <= $now_ts) {
                $pub = caPublishAnnouncement($conn, $year, $user_id, $sync, $uploaded_files);
                $message = "已儲存公告內容並立即發布"
                    . ($file_count > 0 ? "（已上傳 {$file_count} 個附件）" : "")
                    . ($sync ? ("，並同步到前台公告欄（公告ID: " . ($pub['bulletin_id'] ? $pub['bulletin_id'] : "未知") . "）") : "");
            } else {
                $sch = caScheduleAnnouncement($conn, $year, $user_id, $sync, $uploaded_files);
                $message = "已儲存公告內容並排程發布"
                    . ($file_count > 0 ? "（已上傳 {$file_count} 個附件）" : "")
                    . ($sync ? ("，並同步到前台公告欄草稿" . ($sch['bulletin_id'] ? "（公告ID: {$sch['bulletin_id']}）" : "")) : "");
            }
        }

        if ($action === 'confirm_ranking') {
            // 檢查是否有待處理名單
            $pending_check_stmt = $conn->prepare("
                SELECT COUNT(*) as count
                FROM continued_admission
                WHERE assigned_department IS NOT NULL AND assigned_department != ''
                  AND LEFT(apply_no, 4) = ?
                  AND (status IS NULL OR status IN ('PE','pending'))
            ");
            $pending_count = 0;
            if ($pending_check_stmt) {
                $pending_check_stmt->bind_param("i", $year);
                $pending_check_stmt->execute();
                $pending_result = $pending_check_stmt->get_result();
                if ($pending_row = $pending_result->fetch_assoc()) {
                    $pending_count = (int)$pending_row['count'];
                }
                $pending_check_stmt->close();
            }
            
            if ($pending_count > 0) {
                throw new Exception("尚有 {$pending_count} 筆待處理名單，請先處理完畢後再確認錄取結果。");
            }
            
            // 確認前必須先完成電子簽章（檢查當前 session 中的簽章）
            $session_signature_key = "committee_signature_{$year}_{$user_id}";
            $has_signature = isset($_SESSION[$session_signature_key]) && !empty($_SESSION[$session_signature_key]);
            
            if (!$has_signature) {
                throw new Exception("請先完成電子簽章，再確認錄取結果。");
            }
            
            // 驗證簽章是否有效
            $signature_id = (int)$_SESSION[$session_signature_key];
            try {
                $sig_stmt = $conn->prepare("
                    SELECT id 
                    FROM signatures 
                    WHERE id = ? AND user_id = ?
                      AND document_type = 'continued_admission_committee_confirm'
                      AND document_id = ?
                    LIMIT 1
                ");
                if ($sig_stmt) {
                    $sig_stmt->bind_param("iii", $signature_id, $user_id, $year);
                    $sig_stmt->execute();
                    $sig_res = $sig_stmt->get_result();
                    $valid_signature = $sig_res && $sig_res->fetch_assoc();
                    $sig_stmt->close();
                    
                    if (!$valid_signature) {
                        throw new Exception("簽章驗證失敗，請重新簽章。");
                    }
                } else {
                    throw new Exception("無法驗證簽章，請重新簽章。");
                }
            } catch (Throwable $e) {
                // 清除無效的簽章記錄
                unset($_SESSION[$session_signature_key]);
                throw new Exception("簽章驗證失敗：" . $e->getMessage());
            }

            // 寫入各科系錄取結果（status/admission_rank）
            $result = processAdmissionRanking($conn, null);
            $message = "已確認錄取結果：處理 {$result['total_processed']} 筆（正取 {$result['approved']}、備取 {$result['waitlist']}、不錄取 {$result['rejected']}）";
        }

        if ($action === 'queue_emails') {
            $announcement = caGetAnnouncement($conn, $year);
            if (!$announcement) {
                throw new Exception("請先儲存公告內容（草稿）");
            }
            $queued = caQueueResultEmails($conn, $year, (string)$announcement['content']);
            $message = "已建立寄信佇列：新增 {$queued['queued']} 封，略過 {$queued['skipped']} 筆（無 email 或重複）";
        }

        if ($action === 'schedule_announcement') {
            $announcement = caGetAnnouncement($conn, $year);
            if (!$announcement) {
                throw new Exception("請先儲存公告內容（草稿）");
            }
            $sync = isset($_POST['sync_bulletin']) && $_POST['sync_bulletin'] === '1';
            $sch = caScheduleAnnouncement($conn, $year, $user_id, $sync);
            $message = "已排程公告（將於公告時間自動發布）" . ($sync ? ("，並同步到前台公告欄草稿" . ($sch['bulletin_id'] ? "（公告ID: {$sch['bulletin_id']}）" : "")) : "");
        }
    } catch (Throwable $e) {
        $message_ok = false;
        $message = "操作失敗：" . $e->getMessage();
    }
}

// 統計：是否仍有未完成評分/未決定結果的人（用於委員會確認）
$pending_count = 0;
$pending_list = [];
try {
    // 只看今年且已分配科系的
    $stmt = $conn->prepare("
        SELECT ca.id, ca.apply_no, ca.name, ca.assigned_department,
               d.name AS dept_name
        FROM continued_admission ca
        LEFT JOIN departments d ON ca.assigned_department = d.code
        WHERE ca.assigned_department IS NOT NULL AND ca.assigned_department != ''
          AND LEFT(ca.apply_no, 4) = ?
          AND (ca.status IS NULL OR ca.status IN ('PE','pending'))
        ORDER BY ca.apply_no
    ");
    $stmt->bind_param("i", $year);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $pending_list[] = $row;
    }
    $pending_count = count($pending_list);
    $stmt->close();
} catch (Throwable $e) {
    // ignore
}

// 簽章狀態已在上面檢查過（基於 session），這裡不需要重複查詢

$page_title = "續招：招生委員會確認/公告/寄信";
$current_page = 'continued_admission_committee';
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo htmlspecialchars($page_title); ?> - Topics 後台管理系統</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <style>
    :root {
      --primary:#1890ff;
      --primary-soft:#e6f4ff;
      --bg:#f5f7fb;
      --card:#ffffff;
      --text:#262626;
      --muted:#8c8c8c;
      --border:#e5e7eb;
      --ok:#52c41a;
      --danger:#f5222d;
      --warning:#faad14;
      --radius:12px;
      --shadow:0 12px 30px rgba(15, 23, 42, 0.08);
    }

    * { box-sizing:border-box; }

    body {
      margin:0;
      font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,'Microsoft JhengHei',sans-serif;
      background:radial-gradient(circle at top left,#e0f2ff 0,#f5f7fb 45%,#f5f7fb 100%);
      color:var(--text);
    }

    .content {
      padding:32px 24px 40px;
      max-width:1180px;
      margin:0 auto;
    }

    .card {
      background:var(--card);
      border-radius:var(--radius);
      border:1px solid var(--border);
      padding:20px 20px 18px;
      margin-bottom:18px;
      box-shadow:0 4px 10px rgba(15, 23, 42, 0.04);
    }

    .page-header-card {
      padding:22px 22px 20px;
      border:none;
      background:linear-gradient(120deg,#e6f4ff 0,#fdfbff 45%,#ffffff 100%);
      box-shadow:var(--shadow);
    }

    .page-title {
      font-size:20px;
      font-weight:800;
      letter-spacing:0.02em;
      display:flex;
      align-items:center;
      gap:10px;
    }

    .page-title span.badge-year {
      font-size:12px;
      padding:2px 10px;
      border-radius:999px;
      background:#fff;
      border:1px solid #d0e4ff;
      color:#1d4ed8;
    }

    .row {
      display:flex;
      gap:18px;
      flex-wrap:wrap;
      align-items:flex-start;
    }

    .col {
      flex:1;
      min-width:340px;
    }

    .step-title {
      font-weight:700;
      font-size:15px;
      display:flex;
      align-items:center;
      gap:8px;
      margin-bottom:6px;
    }

    .step-index {
      width:22px;
      height:22px;
      border-radius:999px;
      background:var(--primary-soft);
      color:var(--primary);
      font-size:12px;
      font-weight:700;
      display:flex;
      align-items:center;
      justify-content:center;
    }

    .hint {
      color:var(--muted);
      font-size:13px;
      line-height:1.7;
    }

    .field {
      margin:10px 0;
    }

    .field label {
      display:block;
      font-weight:600;
      margin-bottom:6px;
      font-size:14px;
    }

    input[type="text"],
    input[type="datetime-local"],
    textarea {
      width:100%;
      border:1px solid #d9d9d9;
      border-radius:8px;
      padding:10px 12px;
      font-size:14px;
      box-sizing:border-box;
      transition:border-color .15s ease, box-shadow .15s ease;
      background:#fcfdff;
    }

    input[type="text"]:focus,
    input[type="datetime-local"]:focus,
    textarea:focus {
      outline:none;
      border-color:var(--primary);
      box-shadow:0 0 0 2px rgba(24,144,255,.18);
      background:#ffffff;
    }

    textarea {
      min-height: 180px;
      resize: vertical;
    }

    .btn {
      border:none;
      border-radius:6px;
      padding:9px 14px;
      cursor:pointer;
      font-size:14px;
      display:inline-flex;
      align-items:center;
      gap:6px;
      transition:all .18s ease;
      box-shadow:0 2px 4px rgba(15,23,42,0.08);
    }

    .btn.btn-sm {
      padding:5px 10px;
      font-size:12px;
      box-shadow:none;
    }

    .btn-primary {
      background:var(--primary);
      color:#fff;
    }

    .btn-primary:hover {
      background:#1d5fd3;
      transform:translateY(-1px);
      box-shadow:0 6px 12px rgba(24,144,255,0.35);
    }

    .btn-secondary {
      background:#fff;
      border:1px solid #d9d9d9;
      color:#262626;
    }

    .btn-secondary:hover {
      border-color:var(--primary);
      color:var(--primary);
      transform:translateY(-1px);
    }

    .btn-danger {
      background:var(--danger);
      color:#fff;
    }

    .btn[disabled] {
      opacity:.6;
      cursor:not-allowed;
      box-shadow:none;
      transform:none;
    }

    .msg {
      padding:12px 14px;
      border-radius:8px;
      margin-bottom:14px;
      border:1px solid;
      display:flex;
      align-items:flex-start;
      gap:8px;
      font-size:14px;
    }

    .msg.ok {
      background:#f6ffed;
      border-color:#b7eb8f;
      color:#135200;
    }

    .msg.bad {
      background:#fff1f0;
      border-color:#ffa39e;
      color:#a8071a;
    }

    .badge {
      display:inline-block;
      padding:3px 10px;
      border-radius:999px;
      font-size:12px;
      margin-left:8px;
    }

    .badge.ok {
      background:#f6ffed;
      color:#135200;
      border:1px solid #b7eb8f;
    }

    .badge.warn {
      background:#fffbe6;
      color:#ad6800;
      border:1px solid #ffe58f;
    }

    .link {
      color:var(--primary);
      text-decoration:none;
    }

    .link:hover {
      text-decoration:underline;
    }

    /* 待處理學生表格美化 */
    .pending-table {
      width:100%;
      border-collapse:collapse;
      font-size:13px;
    }

    .pending-table th,
    .pending-table td {
      padding:6px 8px;
    }

    .pending-table thead tr {
      border-bottom:1px solid #ffe58f;
      background:#fffaf0;
    }

    .pending-table tbody tr:nth-child(even) {
      background:#fffef7;
    }

    .pending-table tbody tr:hover {
      background:#fff7e6;
    }

    @media (max-width: 768px) {
      .content {
        padding:20px 14px 28px;
      }
      .page-header-card {
        padding:18px 16px;
      }
    }
  </style>
</head>
<body>
  <div class="content">
    <div class="card page-header-card">
      <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
        <div>
          <div class="page-title">
            <i class="fas fa-clipboard-check" style="color:#1d4ed8;"></i>
            <span>招生委員會：確認錄取結果 / 公告 / 自動寄信</span>
            <span class="badge-year">年度：<?php echo (int)$year; ?></span>
          </div>
          <div class="hint" style="margin-top:6px;">建議流程：<strong>先確認錄取結果</strong> → 儲存公告內容 → 建立寄信佇列 → 由排程自動發送</div>
        </div>
        <a class="btn btn-secondary" href="continued_admission_list.php?tab=ranking" style="text-decoration:none; display:inline-flex; align-items:center; gap:8px;">
          <i class="fas fa-list"></i> 回達到錄取標準名單
        </a>
      </div>
    </div>

    <?php if ($message): ?>
      <div class="msg <?php echo $message_ok ? 'ok' : 'bad'; ?>">
        <?php echo htmlspecialchars($message); ?>
      </div>
    <?php endif; ?>

    <div class="row">
      <div class="col">
        <div class="card">
          <div class="step-title">
            <span class="step-index">1</span>
            <span>確認錄取結果（寫入系統）</span>
          </div>
          <div class="hint">
            - 會依各科系名額/錄取標準，將已完成評分的名單寫回 `continued_admission.status` 與 `admission_rank`。<br>
            - 若仍有未評分或狀態未決定者，會顯示待處理數量。
          </div>
          <div class="hint" style="margin-top:8px; padding:10px 12px; background:#f0f7ff; border-radius:8px; border:1px dashed #91caff;">
            <div style="margin-bottom:6px;">
              <i class="fas fa-file-signature" style="margin-right:6px; color:#1d4ed8;"></i>
              確認錄取結果前，<strong>需要先完成本年度的電子簽章</strong>。
            </div>
            <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; font-size:13px;">
              <span>目前簽章狀態：</span>
              <?php if ($committee_signature): ?>
                <span class="badge ok">
                  已完成簽名（<?php echo htmlspecialchars(date('Y/m/d H:i', strtotime($committee_signature['created_at']))); ?>）
                </span>
              <?php else: ?>
                <span class="badge warn">尚未簽名</span>
              <?php endif; ?>
              <button type="button"
                      class="btn btn-secondary btn-sm"
                      onclick="window.open('signature.php?document_id=<?php echo (int)$year; ?>&document_type=continued_admission_committee_confirm','signature','width=900,height=700');">
                <i class="fas fa-pen"></i> 前往簽名
              </button>
            </div>
          </div>
          <div style="margin-top:10px; padding:10px 12px; background:#f5f7ff; border-radius:8px; border:1px solid #d6e4ff; display:flex; justify-content:space-between; align-items:center; gap:10px;">
            <div>
              <div style="font-size:13px; font-weight:600; color:#1d4ed8; display:flex; align-items:center; gap:6px;">
                <i class="fas fa-file-signature"></i> 委員會電子簽章
              </div>
              <div style="font-size:12px; color:var(--muted); margin-top:2px;">
                <?php if ($committee_signature): ?>
                  已簽名時間：<?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($committee_signature['created_at']))); ?>
                <?php else: ?>
                  尚未簽名，請先完成簽章後再按「確認錄取結果」。
                <?php endif; ?>
              </div>
            </div>
            <a href="signature.php?document_id=<?php echo (int)$year; ?>&document_type=continued_admission_committee_confirm"
               target="_blank"
               class="btn btn-secondary"
               style="text-decoration:none; white-space:nowrap;">
              <i class="fas fa-pen-nib"></i> 前往簽名
            </a>
          </div>
          <div style="margin-top:10px;">
            待處理（尚未決定結果）：
            <?php if ($pending_count === 0): ?>
              <span class="badge ok">0</span>
            <?php else: ?>
              <span class="badge warn"><?php echo (int)$pending_count; ?></span>
              <?php if ($pending_count > 0): ?>
                <div style="margin-top:12px; padding:12px; background:#fffbe6; border:1px solid #ffe58f; border-radius:8px; font-size:13px;">
                  <div style="font-weight:600; margin-bottom:8px; color:#ad6800;">待處理學生名單：</div>
                  <table class="pending-table">
                    <thead>
                      <tr style="border-bottom:1px solid #ffe58f;">
                        <th style="text-align:left; padding:6px 8px; color:#ad6800;">報名編號</th>
                        <th style="text-align:left; padding:6px 8px; color:#ad6800;">姓名</th>
                        <th style="text-align:left; padding:6px 8px; color:#ad6800;">分配科系</th>
                        <th style="text-align:left; padding:6px 8px; color:#ad6800;">操作</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($pending_list as $pending): ?>
                        <tr style="border-bottom:1px solid #f0f0f0;">
                          <td style="padding:6px 8px;"><?php echo htmlspecialchars($pending['apply_no'] ?? ''); ?></td>
                          <td style="padding:6px 8px;"><?php echo htmlspecialchars($pending['name'] ?? ''); ?></td>
                          <td style="padding:6px 8px;"><?php echo htmlspecialchars($pending['dept_name'] ?? $pending['assigned_department'] ?? ''); ?></td>
                          <td style="padding:6px 8px;">
                            <a href="continued_admission_detail.php?id=<?php echo (int)$pending['id']; ?>" target="_blank" style="color:var(--primary); text-decoration:none; font-size:12px;">
                              <i class="fas fa-external-link-alt"></i> 查看詳情
                            </a>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <form id="confirmRankingForm" method="post" style="margin-top:12px;">
            <input type="hidden" name="action" value="confirm_ranking" />
            <!-- 簽章 ID（由簽名頁面回傳後寫入，方便之後若要記錄） -->
            <input type="hidden" name="signature_id" value="" />
            <button class="btn btn-primary" type="button" onclick="confirmRankingWithSignature()" 
                    id="confirmRankingBtn" 
                    <?php if ($pending_count > 0 || !$committee_signature): ?>disabled<?php endif; ?>>
              <i class="fas fa-check"></i> 確認錄取結果（需簽名）
            </button>
            <?php if ($pending_count > 0): ?>
              <div style="margin-top:8px; padding:8px; background:#fff1f0; border:1px solid #ffccc7; border-radius:6px; font-size:13px; color:#a8071a;">
                <i class="fas fa-exclamation-triangle"></i> 尚有 <?php echo (int)$pending_count; ?> 筆待處理名單，請先處理完畢後再確認。
              </div>
            <?php endif; ?>
            <?php if (!$committee_signature): ?>
              <div style="margin-top:8px; padding:8px; background:#fff1f0; border:1px solid #ffccc7; border-radius:6px; font-size:13px; color:#a8071a;">
                <i class="fas fa-exclamation-triangle"></i> 請先完成電子簽章後再確認。
              </div>
            <?php endif; ?>
          </form>
        </div>
      </div>

      <div class="col">
        <div class="card">
          <div class="step-title">
            <span class="step-index">2</span>
            <span>公告內容與發布（儲存草稿 / 排程公告）</span>
          </div>
          <div class="hint">
            - 公告時間將使用「科系名額管理」設定的統一公告錄取時間（<?php echo htmlspecialchars($global_announce_time ?? '未設定'); ?>）。<br>
            - 此步驟會儲存公告內容並排程發布到前台公告欄。
          </div>
          <form method="post" enctype="multipart/form-data" style="margin-top:12px;">
            <input type="hidden" name="action" value="save_and_schedule_announcement" />
            <div class="field">
              <label>公告標題</label>
              <input type="text" name="title" value="<?php echo htmlspecialchars($announcement['title'] ?? $default_title); ?>" />
            </div>
            <div class="field">
              <label>公告內容（也會放入寄信內容）</label>
              <textarea name="content"><?php echo htmlspecialchars($announcement['content'] ?? $default_content); ?></textarea>
            </div>
            <div class="field">
              <label>相關附件 <span style="color:var(--muted); font-weight:normal; font-size:12px;">（可選，可上傳多個檔案）</span></label>
              <div id="files-container" style="margin-bottom:8px;">
                <div class="file-item" style="display:flex; gap:8px; margin-bottom:8px; align-items:center;">
                  <input type="file" name="files[]" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif" style="flex:1;" />
                  <button type="button" class="btn btn-secondary btn-sm" onclick="removeFileItem(this)" style="white-space:nowrap;">刪除</button>
                </div>
              </div>
              <button type="button" class="btn btn-secondary btn-sm" onclick="addFileItem()" style="margin-bottom:8px;">
                <i class="fas fa-plus"></i> 新增檔案
              </button>
              <div class="hint">可上傳多個相關檔案（PDF、Word、Excel、圖片等），每個檔案最大 10MB</div>
            </div>
            <!-- 預設同步到前台公告欄，不需要顯示勾選框 -->
            <input type="hidden" name="sync_bulletin" value="1" />
            <div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap;">
              <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> 儲存並排程公告</button>
              <a class="btn btn-secondary" href="publish_continued_admission_announcement.php" target="_blank" style="text-decoration:none;">
                <i class="fas fa-play"></i> 手動觸發發布腳本（測試用）
              </a>
              <a class="btn btn-secondary" href="../../Topics-frontend/frontend/bulletin_board.php" target="_blank" style="text-decoration:none;">
                <i class="fas fa-external-link-alt"></i> 前台公告欄預覽
              </a>
            </div>
            <div class="hint" style="margin-top:12px; padding:10px; background:#fffbe6; border:1px solid #ffe58f; border-radius:8px;">
              <strong>⚠️ 重要提醒：</strong>公告會在設定的時間自動發布，但需要設定定時任務執行 <code>publish_continued_admission_announcement.php</code>。如果時間到了但公告未發布，請點擊「手動觸發發布腳本」或檢查定時任務設定。
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="step-title">
        <span class="step-index">3</span>
        <span>建立寄信佇列（到公告時間自動寄出）</span>
      </div>
      <div class="hint">
        - 會把「已決定結果（正取/備取/不錄取）」且有 email 的學生加入佇列。<br>
        - 寄送時間：以各科系的 <code>announce_time</code> 為準（未設定則使用統一公告時間或現在）。<br>
        - 實際發送：請用工作排程器/cron 執行 `send_continued_admission_result_emails.php`。
      </div>
      <form method="post" style="margin-top:12px;">
        <input type="hidden" name="action" value="queue_emails" />
        <button class="btn btn-primary" type="submit"><i class="fas fa-envelope"></i> 建立寄信佇列</button>
      </form>
      <div class="hint" style="margin-top:10px;">
        排程執行入口：<a class="link" href="send_continued_admission_result_emails.php" target="_blank">send_continued_admission_result_emails.php</a>
      </div>
    </div>


  </div>

  <script>
    // 1) 開啟簽名視窗
    function openCommitteeSignatureWindow() {
      const year = <?php echo (int)$year; ?>;
      const url = 'signature.php?document_id=' + encodeURIComponent(year) +
                  '&document_type=continued_admission_committee_confirm';
      const width = 900;
      const height = 700;
      const left = (window.screen.width - width) / 2;
      const top = (window.screen.height - height) / 2;

      window.open(
        url,
        'committee_signature',
        'width=' + width + ',height=' + height +
        ',left=' + left + ',top=' + top +
        ',resizable=yes,scrollbars=yes'
      );
    }

    // 2) 點擊「確認錄取結果」時，檢查是否已簽名和是否有待處理名單
    function confirmRankingWithSignature() {
      // 檢查是否有待處理名單
      const pendingCount = <?php echo (int)$pending_count; ?>;
      if (pendingCount > 0) {
        alert('尚有 ' + pendingCount + ' 筆待處理名單，請先處理完畢後再確認錄取結果。');
        return;
      }
      
      // 檢查是否已有簽名（從 PHP 傳入的變數）
      const hasSignature = <?php echo $committee_signature ? 'true' : 'false'; ?>;
      
      if (hasSignature) {
        // 如果已簽名，直接提交表單
        const form = document.getElementById('confirmRankingForm');
        if (form) {
          form.submit();
        }
      } else {
        // 如果未簽名，打開簽名視窗
        openCommitteeSignatureWindow();
      }
    }

    // 3) 接收簽名頁面回傳的訊息，簽完後更新 session 並自動送出表單
    window.addEventListener('message', function (event) {
      if (!event || !event.data || event.data.type !== 'signature_saved') {
        return;
      }

      try {
        // 儲存簽章 ID（若未來要在後端記錄，可從 $_POST['signature_id'] 取得）
        const signatureIdInput = document.querySelector('#confirmRankingForm input[name="signature_id"]');
        if (signatureIdInput && event.data.signature_id) {
          signatureIdInput.value = event.data.signature_id;
        }

        // 將簽章 ID 保存到 session（通過後端 API）
        if (event.data.signature_id) {
          fetch('save_committee_signature_session.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
            },
            body: JSON.stringify({
              signature_id: event.data.signature_id,
              year: <?php echo (int)$year; ?>
            })
          }).then(response => response.json())
            .then(data => {
              if (data.success) {
                // 更新按鈕狀態
                const btn = document.getElementById('confirmRankingBtn');
                if (btn) {
                  btn.disabled = false;
                }
                // 移除簽章提示
                const sigWarning = document.querySelector('#confirmRankingForm div[style*="請先完成電子簽章"]');
                if (sigWarning) {
                  sigWarning.remove();
                }
                // 自動送出「確認錄取結果」表單
                const form = document.getElementById('confirmRankingForm');
                if (form) {
                  form.submit();
                }
              } else {
                alert('保存簽章狀態失敗：' + (data.message || '未知錯誤'));
              }
            })
            .catch(error => {
              console.error('保存簽章狀態時發生錯誤:', error);
              alert('保存簽章狀態失敗，請重新整理頁面後再試');
            });
        } else {
          // 如果沒有簽章 ID，直接提交表單（向後兼容）
          const form = document.getElementById('confirmRankingForm');
          if (form) {
            form.submit();
          }
        }
      } catch (e) {
        console.error('處理簽名回傳時發生錯誤:', e);
      }
    });

    // 檔案上傳相關函數
    function addFileItem() {
      const container = document.getElementById('files-container');
      const newItem = document.createElement('div');
      newItem.className = 'file-item';
      newItem.style.cssText = 'display:flex; gap:8px; margin-bottom:8px; align-items:center;';
      newItem.innerHTML = `
        <input type="file" name="files[]" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif" style="flex:1;" />
        <button type="button" class="btn btn-secondary btn-sm" onclick="removeFileItem(this)" style="white-space:nowrap;">刪除</button>
      `;
      container.appendChild(newItem);
    }

    function removeFileItem(btn) {
      const container = document.getElementById('files-container');
      if (container.children.length > 1) {
        btn.closest('.file-item').remove();
      } else {
        alert('至少需要保留一個檔案欄位');
      }
    }
  </script>
</body>
</html>
<?php $conn->close(); ?>


