<?php
require_once __DIR__ . '/session_config.php';
checkBackendLogin();

require_once '../../Topics-frontend/frontend/config.php';

$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$can_view_review_result = ($username === '12' && $user_role === 'STA');
if (!$can_view_review_result) {
    header('Location: admission_recommend_list.php');
    exit();
}

$page_title = '獎金發送名單';
$current_page = 'admission_recommend_list';

try {
    $conn = getDatabaseConnection();
} catch (Exception $e) {
    die("資料庫連接失敗: " . $e->getMessage());
}

// 確保表存在（若尚未發送過也能進頁面）
try {
    $table_check = $conn->query("SHOW TABLES LIKE 'bonus_send_logs'");
    if ($table_check && $table_check->num_rows == 0) {
        $conn->query("CREATE TABLE bonus_send_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recommendation_id INT NOT NULL,
            recommender_name VARCHAR(100) NOT NULL DEFAULT '',
            recommender_student_id VARCHAR(50) NOT NULL DEFAULT '',
            sent_by VARCHAR(100) NOT NULL DEFAULT '',
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_recommendation_id (recommendation_id),
            INDEX idx_sent_at (sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
} catch (Exception $e) {
    // ignore
}

$rows = [];
try {
    // 額外帶出被推薦人姓名（若 recommended 表存在）
    $has_recommended = false;
    $t = $conn->query("SHOW TABLES LIKE 'recommended'");
    $has_recommended = ($t && $t->num_rows > 0);

    if ($has_recommended) {
        $sql = "SELECT
            b.recommendation_id,
            b.recommender_name,
            b.recommender_student_id,
            COALESCE(b.amount, 1500) AS amount,
            COALESCE(red.name,'') AS student_name,
            b.sent_by,
            b.sent_at
        FROM bonus_send_logs b
        LEFT JOIN recommended red ON b.recommendation_id = red.recommendations_id
        ORDER BY b.sent_at DESC";
    } else {
        $sql = "SELECT
            b.recommendation_id,
            b.recommender_name,
            b.recommender_student_id,
            COALESCE(b.amount, 1500) AS amount,
            '' AS student_name,
            b.sent_by,
            b.sent_at
        FROM bonus_send_logs b
        ORDER BY b.sent_at DESC";
    }

    $res = $conn->query($sql);
    if ($res) {
        $rows = $res->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    error_log('bonus_send_list query error: ' . $e->getMessage());
    $rows = [];
}

?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($page_title); ?> - Topics 後台管理系統</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <style>
    :root {
      --primary-color: #1890ff;
      --text-color: #262626;
      --text-secondary-color: #8c8c8c;
      --border-color: #f0f0f0;
      --background-color: #f0f2f5;
      --card-background-color: #fff;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
      background: var(--background-color);
      color: var(--text-color);
      overflow-x: hidden;
    }
    .dashboard { display: flex; min-height: 100vh; }
    .content { padding: 24px; width: 100%; }
    .page-controls {
      display:flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      margin-bottom: 16px;
    }
    .breadcrumb { font-size: 16px; color: var(--text-secondary-color); }
    .breadcrumb a { color: var(--primary-color); text-decoration: none; }
    .breadcrumb a:hover { text-decoration: underline; }
    .btn-view {
      padding: 6px 12px;
      border: 1px solid #1890ff;
      border-radius: 6px;
      cursor: pointer;
      font-size: 14px;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s;
      background: #fff;
      color: #1890ff;
      white-space: nowrap;
    }
    .btn-view:hover { background:#1890ff; color:#fff; }
    .table-wrapper {
      background: var(--card-background-color);
      border-radius: 8px;
      box-shadow: 0 1px 2px rgba(0,0,0,0.03);
      border: 1px solid var(--border-color);
    }
    .toolbar {
      display:flex;
      justify-content: space-between;
      align-items:center;
      gap: 12px;
      padding: 16px 18px;
      border-bottom: 1px solid var(--border-color);
    }
    .toolbar .count { color: var(--text-secondary-color); font-size: 14px; }
    .toolbar-right {
      display:flex;
      align-items:center;
      gap: 10px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }
    .search-input {
      padding: 8px 10px;
      border: 1px solid #d9d9d9;
      border-radius: 8px;
      font-size: 14px;
      width: 190px;
    }
    .search-input:focus {
      outline: none;
      border-color: #1890ff;
      box-shadow: 0 0 0 2px rgba(24,144,255,0.15);
    }
    .btn-secondary {
      background: #fff;
      color: #595959;
      border-color: #d9d9d9;
    }
    .btn-secondary:hover {
      border-color: #1890ff;
      color: #1890ff;
      background: #fff;
    }
    .table-container { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td {
      padding: 14px 18px;
      text-align: left;
      border-bottom: 1px solid var(--border-color);
      font-size: 14px;
      white-space: nowrap;
    }
    th { background: #fafafa; font-weight: 700; }
    tr:hover { background: #fafafa; }
    .empty-state {
      padding: 40px;
      text-align: center;
      color: var(--text-secondary-color);
    }

    /* 置中彈窗（沿用 admission_recommend_list.php 的風格） */
    .modal {
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0,0,0,0.5);
      display: none;
      align-items: center;
      justify-content: center;
    }
    .modal-content {
      background-color: white;
      border-radius: 8px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.15);
      width: 92%;
      max-width: 980px;
      max-height: 85vh;
      overflow-y: auto;
    }
    .modal-header {
      padding: 18px 20px;
      border-bottom: 1px solid var(--border-color);
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .modal-header h3 { margin: 0; color: var(--text-color); font-size: 18px; }
    .close {
      font-size: 24px;
      font-weight: bold;
      cursor: pointer;
      color: var(--text-secondary-color);
    }
    .close:hover { color: var(--text-color); }
    .modal-body { padding: 18px 20px; }
    .detail-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 18px;
    }
    .detail-box h4 {
      margin: 0 0 10px 0;
      font-size: 16px;
      color: var(--text-color);
    }
    .detail-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 14px;
      background: #fff;
    }
    .detail-table td {
      border: 1px solid #e5e5e5;
      padding: 10px 12px;
      white-space: normal;
      vertical-align: top;
    }
    .detail-label {
      width: 140px;
      background: #f5f5f5;
      font-weight: 700;
      color: #595959;
    }
    .detail-section {
      margin-top: 16px;
    }
    .muted { color: #8c8c8c; }
    @media (max-width: 900px) {
      .detail-grid { grid-template-columns: 1fr; }
      .detail-label { width: 130px; }
    }
  </style>
</head>
<body>
  <div class="dashboard">
    <?php include 'sidebar.php'; ?>
    <div class="main-content" id="mainContent">
      <?php include 'header.php'; ?>
      <div class="content">
        <div class="page-controls">
          <div class="breadcrumb">
            <a href="index.php">首頁</a> /
            <a href="admission_recommend_list.php">被推薦人資訊</a> /
            <?php echo htmlspecialchars($page_title); ?>
          </div>
          <div style="display:flex; gap:10px; align-items:center;">
            <a class="btn-view" href="bonus_send_export.php"><i class="fas fa-file-excel"></i> 匯出EXCEL</a>
            <a class="btn-view" href="admission_recommend_list.php?view=pass"><i class="fas fa-arrow-left"></i> 返回通過名單</a>
          </div>
        </div>

        <div class="table-wrapper">
          <div class="toolbar">
            <div style="display:flex; align-items:center; gap:10px;">
              <i class="fas fa-list" style="color:#1890ff;"></i>
              <strong>已發送獎金名單</strong>
            </div>
            <div class="toolbar-right">
              <input type="text" id="filterRecommenderName" class="search-input" placeholder="查詢推薦人姓名">
              <input type="text" id="filterRecommenderId" class="search-input" placeholder="查詢推薦人學號/編號">
              <button type="button" class="btn-view" id="btnBonusQuery"><i class="fas fa-search"></i> 查詢</button>
              <button type="button" class="btn-view btn-secondary" id="btnBonusClear"><i class="fas fa-eraser"></i> 清除</button>
              <div class="count" id="bonusCount">共 <?php echo count($rows); ?> 筆</div>
            </div>
          </div>
          <div class="table-container">
            <?php if (empty($rows)): ?>
              <div class="empty-state">
                <i class="fas fa-inbox fa-3x" style="display:block; margin:0 auto 10px; opacity:0.7;"></i>
                <div>目前尚無已發送獎金的紀錄</div>
              </div>
            <?php else: ?>
              <table>
                <thead>
                  <tr>
                    <th>推薦ID</th>
                    <th>推薦人姓名</th>
                    <th>推薦人學號/編號</th>
                    <th>獎金金額</th>
                    <th>被推薦人</th>
                    <th>發送者</th>
                    <th>發送時間</th>
                    <th>操作</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($rows as $r): ?>
                    <tr>
                      <tr class="bonus-row"
                          data-recommender-name="<?php echo htmlspecialchars((string)($r['recommender_name'] ?? '')); ?>"
                          data-recommender-id="<?php echo htmlspecialchars((string)($r['recommender_student_id'] ?? '')); ?>">
                      <td><?php echo htmlspecialchars($r['recommendation_id'] ?? ''); ?></td>
                      <td><?php echo htmlspecialchars($r['recommender_name'] ?? ''); ?></td>
                      <td><?php echo htmlspecialchars($r['recommender_student_id'] ?? ''); ?></td>
                      <td><?php echo '$' . number_format((int)($r['amount'] ?? 1500)); ?></td>
                      <td><?php echo htmlspecialchars($r['student_name'] ?? ''); ?></td>
                      <td><?php echo htmlspecialchars($r['sent_by'] ?? ''); ?></td>
                      <td><?php echo htmlspecialchars($r['sent_at'] ?? ''); ?></td>
                      <td>
                        <button type="button" class="btn-view" onclick="openBonusDetail(<?php echo (int)($r['recommendation_id'] ?? 0); ?>)">
                          <i class="fas fa-eye"></i> 查看詳情
                        </button>
                      </td>
                      </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- 詳情彈窗：同頁顯示推薦人/被推薦人資訊 -->
  <div id="bonusDetailModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3>推薦詳情</h3>
        <span class="close" onclick="closeBonusDetail()">&times;</span>
      </div>
      <div class="modal-body">
        <div id="bonusDetailLoading" class="muted">載入中...</div>
        <div id="bonusDetailContent" style="display:none;">
          <div class="detail-grid">
            <div class="detail-box">
              <h4>被推薦人資訊</h4>
              <table class="detail-table">
                <tr><td class="detail-label">姓名</td><td id="bd_student_name"></td></tr>
                <tr><td class="detail-label">就讀學校</td><td id="bd_student_school"></td></tr>
                <tr><td class="detail-label">年級</td><td id="bd_student_grade"></td></tr>
                <tr><td class="detail-label">電子郵件</td><td id="bd_student_email"></td></tr>
                <tr><td class="detail-label">聯絡電話</td><td id="bd_student_phone"></td></tr>
                <tr><td class="detail-label">LINE ID</td><td id="bd_student_line"></td></tr>
                <tr><td class="detail-label">學生興趣</td><td id="bd_student_interest"></td></tr>
              </table>
            </div>
            <div class="detail-box">
              <h4>推薦人資訊</h4>
              <table class="detail-table">
                <tr><td class="detail-label">姓名</td><td id="bd_rec_name"></td></tr>
                <tr><td class="detail-label">學號/教師編號</td><td id="bd_rec_id"></td></tr>
                <tr><td class="detail-label">年級</td><td id="bd_rec_grade"></td></tr>
                <tr><td class="detail-label">科系</td><td id="bd_rec_dept"></td></tr>
                <tr><td class="detail-label">聯絡電話</td><td id="bd_rec_phone"></td></tr>
                <tr><td class="detail-label">電子郵件</td><td id="bd_rec_email"></td></tr>
              </table>
            </div>
          </div>
          <div class="detail-section">
            <h4 style="margin: 16px 0 10px 0; font-size:16px;">推薦資訊</h4>
            <table class="detail-table">
              <tr><td class="detail-label">推薦理由</td><td id="bd_reason"></td></tr>
              <tr><td class="detail-label">推薦時間</td><td id="bd_created_at"></td></tr>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    // 已發送獎金名單：查詢/清除（前端過濾，不重新查 DB）
    function applyBonusFilters() {
      const nameVal = (document.getElementById('filterRecommenderName')?.value || '').trim().toLowerCase();
      const idVal = (document.getElementById('filterRecommenderId')?.value || '').trim().toLowerCase();
      const rows = Array.from(document.querySelectorAll('tr.bonus-row'));
      let visible = 0;
      rows.forEach(tr => {
        const nm = (tr.getAttribute('data-recommender-name') || '').toLowerCase();
        const rid = (tr.getAttribute('data-recommender-id') || '').toLowerCase();
        let ok = true;
        if (nameVal && nm.indexOf(nameVal) === -1) ok = false;
        if (idVal && rid.indexOf(idVal) === -1) ok = false;
        tr.style.display = ok ? '' : 'none';
        if (ok) visible++;
      });
      const cnt = document.getElementById('bonusCount');
      if (cnt) cnt.textContent = `共 ${visible} 筆`;
    }

    function clearBonusFilters() {
      const n = document.getElementById('filterRecommenderName');
      const i = document.getElementById('filterRecommenderId');
      if (n) n.value = '';
      if (i) i.value = '';
      applyBonusFilters();
    }

    function setText(id, value) {
      const el = document.getElementById(id);
      if (!el) return;
      const v = (value === null || value === undefined || String(value).trim() === '') ? '未填寫' : String(value);
      el.textContent = v;
      if (v === '未填寫') el.classList.add('muted'); else el.classList.remove('muted');
    }

    function openBonusDetail(recommendationId) {
      const rid = parseInt(recommendationId || 0, 10) || 0;
      if (!rid) return;

      const modal = document.getElementById('bonusDetailModal');
      const loading = document.getElementById('bonusDetailLoading');
      const content = document.getElementById('bonusDetailContent');
      if (!modal || !loading || !content) return;

      loading.style.display = 'block';
      content.style.display = 'none';
      modal.style.display = 'flex';

      fetch(`bonus_send_detail.php?id=${encodeURIComponent(String(rid))}`, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
          if (!data || !data.success) {
            throw new Error((data && data.message) ? data.message : '載入失敗');
          }
          const d = data.data || {};

          setText('bd_student_name', d.student_name);
          setText('bd_student_school', d.student_school);
          setText('bd_student_grade', d.student_grade);
          setText('bd_student_email', d.student_email);
          setText('bd_student_phone', d.student_phone);
          setText('bd_student_line', d.student_line_id);
          setText('bd_student_interest', d.student_interest);

          setText('bd_rec_name', d.recommender_name);
          setText('bd_rec_id', d.recommender_student_id);
          setText('bd_rec_grade', d.recommender_grade);
          setText('bd_rec_dept', d.recommender_department);
          setText('bd_rec_phone', d.recommender_phone);
          setText('bd_rec_email', d.recommender_email);

          setText('bd_reason', d.recommendation_reason);
          setText('bd_created_at', d.created_at);

          loading.style.display = 'none';
          content.style.display = 'block';
        })
        .catch(err => {
          loading.textContent = '載入失敗：' + (err && err.message ? err.message : '未知錯誤');
        });
    }

    function closeBonusDetail() {
      const modal = document.getElementById('bonusDetailModal');
      const loading = document.getElementById('bonusDetailLoading');
      const content = document.getElementById('bonusDetailContent');
      if (modal) modal.style.display = 'none';
      if (loading) { loading.textContent = '載入中...'; loading.style.display = 'block'; }
      if (content) content.style.display = 'none';
    }

    // 點背景關閉
    const bonusDetailModal = document.getElementById('bonusDetailModal');
    if (bonusDetailModal) {
      bonusDetailModal.addEventListener('click', function(e) {
        if (e.target === this) closeBonusDetail();
      });
    }

    // 綁定查詢/清除
    document.addEventListener('DOMContentLoaded', function() {
      const btnQ = document.getElementById('btnBonusQuery');
      const btnC = document.getElementById('btnBonusClear');
      const n = document.getElementById('filterRecommenderName');
      const i = document.getElementById('filterRecommenderId');
      if (btnQ) btnQ.addEventListener('click', applyBonusFilters);
      if (btnC) btnC.addEventListener('click', clearBonusFilters);
      // Enter 觸發查詢
      [n, i].forEach(el => {
        if (!el) return;
        el.addEventListener('keyup', function(e) {
          if (e.key === 'Enter') applyBonusFilters();
        });
      });
    });
  </script>
</body>
</html>

