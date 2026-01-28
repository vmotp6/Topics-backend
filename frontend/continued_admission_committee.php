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

        if ($action === 'confirm_ranking') {
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
    :root { --primary:#1890ff; --bg:#f0f2f5; --card:#fff; --text:#262626; --muted:#8c8c8c; --border:#f0f0f0; --ok:#52c41a; --danger:#f5222d; }
    body { margin:0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,'Microsoft JhengHei',sans-serif; background:var(--bg); color:var(--text); }
    .content { padding:24px; }
    .card { background:var(--card); border:1px solid var(--border); border-radius:10px; padding:18px; margin-bottom:16px; }
    .row { display:flex; gap:16px; flex-wrap:wrap; }
    .col { flex:1; min-width:320px; }
    .btn { border:none; border-radius:6px; padding:10px 14px; cursor:pointer; font-size:14px; }
    .btn-primary { background:var(--primary); color:#fff; }
    .btn-secondary { background:#fff; border:1px solid #d9d9d9; color:#262626; }
    .btn-danger { background:var(--danger); color:#fff; }
    .hint { color:var(--muted); font-size:13px; line-height:1.7; }
    .field { margin:10px 0; }
    .field label { display:block; font-weight:600; margin-bottom:6px; }
    input[type="text"], input[type="datetime-local"], textarea { width:100%; border:1px solid #d9d9d9; border-radius:8px; padding:10px 12px; font-size:14px; box-sizing:border-box; }
    textarea { min-height: 180px; resize: vertical; }
    .msg { padding:12px 14px; border-radius:8px; margin-bottom:12px; border:1px solid; }
    .msg.ok { background:#f6ffed; border-color:#b7eb8f; color:#135200; }
    .msg.bad { background:#fff1f0; border-color:#ffa39e; color:#a8071a; }
    .badge { display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; margin-left:8px; }
    .badge.ok { background:#f6ffed; color:#135200; border:1px solid #b7eb8f; }
    .badge.warn { background:#fffbe6; color:#ad6800; border:1px solid #ffe58f; }
    .link { color:var(--primary); text-decoration:none; }
  </style>
</head>
<body>
  <div class="content">
    <div class="card">
      <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
        <div>
          <div style="font-size:18px; font-weight:800;">招生委員會：確認錄取結果 / 公告 / 自動寄信</div>
          <div class="hint">年度：<?php echo (int)$year; ?>　｜　建議流程：先確認錄取結果 → 儲存公告內容 → 建立寄信佇列 → 由排程自動發送</div>
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
          <div style="font-weight:800; margin-bottom:8px;">1) 確認錄取結果（寫入系統）</div>
          <div class="hint">
            - 會依各科系名額/錄取標準，將已完成評分的名單寫回 `continued_admission.status` 與 `admission_rank`。<br>
            - 若仍有未評分或狀態未決定者，會顯示待處理數量。
          </div>
          <div style="margin-top:10px;">
            待處理（尚未決定結果）：
            <?php if ($pending_count === 0): ?>
              <span class="badge ok">0</span>
            <?php else: ?>
              <span class="badge warn"><?php echo (int)$pending_count; ?></span>
              <?php if ($pending_count > 0): ?>
                <div style="margin-top:12px; padding:12px; background:#fffbe6; border:1px solid #ffe58f; border-radius:6px; font-size:13px;">
                  <div style="font-weight:600; margin-bottom:8px; color:#ad6800;">待處理學生名單：</div>
                  <table style="width:100%; border-collapse:collapse;">
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
          <form method="post" style="margin-top:12px;">
            <input type="hidden" name="action" value="confirm_ranking" />
            <button class="btn btn-primary" type="submit"><i class="fas fa-check"></i> 確認錄取結果</button>
          </form>
        </div>
      </div>

      <div class="col">
        <div class="card">
          <div style="font-weight:800; margin-bottom:8px;">2) 公告內容（儲存草稿 / 設定公告時間）</div>
          <div class="hint">
            - 公告時間預設使用「續招報名管理」的 <code>錄取公告時間(announce_time)</code>（取各科系最大值）。<br>
            - 目前僅供續招系統與寄信內容使用；若要同步到「招生公告欄」，我也可以接續幫你串接。
          </div>
          <form method="post" style="margin-top:12px;">
            <input type="hidden" name="action" value="save_announcement" />
            <div class="field">
              <label>公告標題</label>
              <input type="text" name="title" value="<?php echo htmlspecialchars($announcement['title'] ?? $default_title); ?>" />
            </div>
            <div class="field">
              <label>公告時間（publish_at）</label>
              <input type="datetime-local" name="publish_at" value="<?php echo htmlspecialchars(isset($announcement['publish_at']) && $announcement['publish_at'] ? date('Y-m-d\TH:i', strtotime($announcement['publish_at'])) : ($global_announce_time ? date('Y-m-d\TH:i', strtotime($global_announce_time)) : '')); ?>" />
              <div class="hint">目前系統統一公告時間（最大值）：<?php echo htmlspecialchars($global_announce_time ?? '未設定'); ?></div>
            </div>
            <div class="field">
              <label>公告內容（也會放入寄信內容）</label>
              <textarea name="content"><?php echo htmlspecialchars($announcement['content'] ?? $default_content); ?></textarea>
            </div>
            <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> 儲存公告草稿</button>
          </form>
        </div>
      </div>
    </div>

    <div class="card">
      <div style="font-weight:800; margin-bottom:8px;">3) 建立寄信佇列（到公告時間自動寄出）</div>
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

    <div class="card">
      <div style="font-weight:800; margin-bottom:8px;">4) 公告錄取名單（依公告時間自動發布到前台）</div>
      <div class="hint">
        - 此步驟只會「排程」公告：先同步到前台公告欄為草稿，不會立刻公開。<br>
        - 到 <code>publish_at</code>（公告時間）後，請由排程執行 <code>publish_continued_admission_announcement.php</code> 自動發布。<br>
        - 公告內容會自動附上「查看續招錄取名單」連結（`continued_admission_results.php`）。
      </div>
      <form method="post" style="margin-top:12px;">
        <input type="hidden" name="action" value="schedule_announcement" />
        <label style="display:flex; align-items:center; gap:8px; margin-bottom:10px; cursor:pointer;">
          <input type="checkbox" name="sync_bulletin" value="1" checked />
          <span>同步到前台公告欄草稿（建議勾選）</span>
        </label>
        <button class="btn btn-primary" type="submit"><i class="fas fa-calendar-check"></i> 排程公告</button>
        <a class="btn btn-secondary" href="publish_continued_admission_announcement.php" target="_blank" style="text-decoration:none; margin-left:8px;">
          <i class="fas fa-play"></i> 手動觸發發布腳本（測試用）
        </a>
        <a class="btn btn-secondary" href="../../Topics-frontend/frontend/bulletin_board.php" target="_blank" style="text-decoration:none; margin-left:8px;">
          <i class="fas fa-external-link-alt"></i> 前台公告欄預覽
        </a>
      </form>
    </div>

  </div>
</body>
</html>
<?php $conn->close(); ?>


