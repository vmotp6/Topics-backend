<?php
require_once __DIR__ . '/session_config.php';
checkBackendLogin();

require_once '../../Topics-frontend/frontend/config.php';

$can_view = (isStaff() || isAdmin());
if (!$can_view) {
    header('Location: admission_recommend_list.php');
    exit();
}

$page_title = '可發送獎金名單';
$current_page = 'admission_recommend_list';

try {
    $conn = getDatabaseConnection();
} catch (Exception $e) {
    die('資料庫連接失敗: ' . $e->getMessage());
}

function excel_col_letter($index) {
    $index = (int)$index + 1;
    $letters = '';
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $letters = chr(65 + $mod) . $letters;
        $index = (int)(($index - 1) / 26);
    }
    return $letters;
}

function excel_xml_escape($value) {
    $value = (string)$value;
    return str_replace(['&', '<', '>', '"', "'"], ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'], $value);
}

function build_simple_xlsx($rows, $output_path) {
    if (!class_exists('ZipArchive')) return false;
    $zip = new ZipArchive();
    if ($zip->open($output_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;

    $sheet_rows = '';
    $row_num = 1;
    foreach ($rows as $row) {
        $sheet_rows .= '<row r="' . $row_num . '">';
        $col_num = 0;
        foreach ($row as $cell) {
            $col = excel_col_letter($col_num);
            $cell_ref = $col . $row_num;
            $text = excel_xml_escape($cell);
            $sheet_rows .= '<c r="' . $cell_ref . '" t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>';
            $col_num++;
        }
        $sheet_rows .= '</row>';
        $row_num++;
    }

    $sheet_xml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . $sheet_rows . '</sheetData>'
        . '</worksheet>';
    $workbook_xml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';
    $rels_xml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $workbook_rels_xml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';
    $content_types_xml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>';

    $zip->addFromString('[Content_Types].xml', $content_types_xml);
    $zip->addFromString('_rels/.rels', $rels_xml);
    $zip->addFromString('xl/workbook.xml', $workbook_xml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbook_rels_xml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet_xml);
    $zip->close();
    return true;
}

$hasColumn = function($table, $column) use ($conn) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $cnt = 0;
    $stmt->bind_result($cnt);
    $stmt->fetch();
    $stmt->close();
    return ((int)$cnt > 0);
};

$has_recommender_table = false;
$t1 = $conn->query("SHOW TABLES LIKE 'recommender'");
if ($t1 && $t1->num_rows > 0) $has_recommender_table = true;
$has_recommended_table = false;
$t2 = $conn->query("SHOW TABLES LIKE 'recommended'");
if ($t2 && $t2->num_rows > 0) $has_recommended_table = true;

$ar_has_recommender_name = $hasColumn('admission_recommendations', 'recommender_name');
$ar_has_recommender_student_id = $hasColumn('admission_recommendations', 'recommender_student_id');
$ar_has_recommender_department = $hasColumn('admission_recommendations', 'recommender_department');
$ar_has_recommender_department_code = $hasColumn('admission_recommendations', 'recommender_department_code');
$ar_has_student_name = $hasColumn('admission_recommendations', 'student_name');
$ar_has_status = $hasColumn('admission_recommendations', 'status');
$ar_has_academic_year = $hasColumn('admission_recommendations', 'academic_year');
$academic_year_expr = $ar_has_academic_year ? "COALESCE(CAST(ar.academic_year AS CHAR), '')" : "''";

$rec_name_expr = $has_recommender_table
    ? "COALESCE(rec.name, " . ($ar_has_recommender_name ? "ar.recommender_name" : "''") . ", '')"
    : ($ar_has_recommender_name ? "COALESCE(ar.recommender_name,'')" : "''");
$rec_sid_expr = $has_recommender_table
    ? "COALESCE(rec.id, " . ($ar_has_recommender_student_id ? "ar.recommender_student_id" : "''") . ", '')"
    : ($ar_has_recommender_student_id ? "COALESCE(ar.recommender_student_id,'')" : "''");
$rec_dept_code_expr = $has_recommender_table
    ? "COALESCE(rec.department, " . ($ar_has_recommender_department_code ? "ar.recommender_department_code" : ($ar_has_recommender_department ? "ar.recommender_department" : "''")) . ", '')"
    : ($ar_has_recommender_department_code ? "COALESCE(ar.recommender_department_code,'')" : ($ar_has_recommender_department ? "COALESCE(ar.recommender_department,'')" : "''"));
$rec_dept_name_expr = $has_recommender_table
    ? "COALESCE(rec_dept.name, " . ($ar_has_recommender_department ? "ar.recommender_department" : "''") . ", " . ($ar_has_recommender_department_code ? "ar.recommender_department_code" : "''") . ", '')"
    : "COALESCE(rec_dept2.name, " . ($ar_has_recommender_department ? "ar.recommender_department" : "''") . ", " . ($ar_has_recommender_department_code ? "ar.recommender_department_code" : "''") . ", '')";
$student_name_expr = $has_recommended_table
    ? "COALESCE(red.name, " . ($ar_has_student_name ? "ar.student_name" : "''") . ", '')"
    : ($ar_has_student_name ? "COALESCE(ar.student_name,'')" : "''");
$status_expr = $ar_has_status ? "COALESCE(ar.status,'')" : "''";

$rows = [];
try {
    $approval_join = "LEFT JOIN (
        SELECT r1.*
        FROM recommendation_approval_links r1
        INNER JOIN (
            SELECT recommendation_id, MAX(id) AS max_id
            FROM recommendation_approval_links
            GROUP BY recommendation_id
        ) r2 ON r1.id = r2.max_id
    ) ral ON ral.recommendation_id = ar.id";

    $sql = "SELECT
        ar.id,
        {$status_expr} AS status,
        {$academic_year_expr} AS academic_year,
        {$student_name_expr} AS student_name,
        {$rec_name_expr} AS recommender_name,
        {$rec_sid_expr} AS recommender_student_id,
        {$rec_dept_code_expr} AS recommender_department_code,
        {$rec_dept_name_expr} AS recommender_department,
        COALESCE(ral.status, '') AS director_review_status,
        COALESCE(ral.signed_at, ral.created_at) AS director_review_at,
        ar.created_at
    FROM admission_recommendations ar
    " . ($has_recommender_table ? "LEFT JOIN recommender rec ON ar.id = rec.recommendations_id" : "") . "
    " . ($has_recommended_table ? "LEFT JOIN recommended red ON ar.id = red.recommendations_id" : "") . "
    " . ($has_recommender_table ? "LEFT JOIN departments rec_dept ON {$rec_dept_code_expr} = rec_dept.code" : "") . "
    " . (!$has_recommender_table ? "LEFT JOIN departments rec_dept2 ON " . ($ar_has_recommender_department_code ? "ar.recommender_department_code" : "''") . " = rec_dept2.code" : "") . "
    {$approval_join}
    WHERE (ar.status = 'APD' OR ar.status LIKE '%審核完成%' OR ar.status LIKE '%可發獎金%')
      AND LOWER(COALESCE(ral.status, '')) = 'signed'
    ORDER BY ar.created_at DESC";

    $res = $conn->query($sql);
    if ($res) {
        $rows = $res->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    error_log('bonus_eligible_list query error: ' . $e->getMessage());
    $rows = [];
}

$dept_options = [];
$year_options_eligible = [];
foreach ($rows as $r) {
    $d = trim((string)($r['recommender_department'] ?? ''));
    if ($d !== '') $dept_options[$d] = true;
    $ay = trim((string)($r['academic_year'] ?? ''));
    if ($ay !== '') $year_options_eligible[(int)$ay] = true;
}
$dept_options = array_keys($dept_options);
sort($dept_options);
$year_options_eligible = array_keys($year_options_eligible);
rsort($year_options_eligible, SORT_NUMERIC);
$total_count_eligible = count($rows);

if (isset($_GET['export']) && $_GET['export'] === '1') {
    $export_rows = [[
        '推薦ID',
        '被推薦人',
        '推薦人姓名',
        '推薦人學號/編號',
        '推薦人科系',
        '審核結果',
        '線上簽核',
        '推薦時間'
    ]];
    foreach ($rows as $r) {
        $export_rows[] = [
            (string)($r['id'] ?? ''),
            (string)($r['student_name'] ?? ''),
            (string)($r['recommender_name'] ?? ''),
            (string)($r['recommender_student_id'] ?? ''),
            (string)($r['recommender_department'] ?? ''),
            '審核完成（可發獎金）',
            '已線上簽核',
            (string)($r['created_at'] ?? ''),
        ];
    }

    $filename_base = '可發送獎金名單_' . date('Ymd_His');
    if (class_exists('ZipArchive')) {
        $tmp = tempnam(sys_get_temp_dir(), 'eligible_export_');
        $xlsx_path = $tmp . '.xlsx';
        @rename($tmp, $xlsx_path);
        $built = build_simple_xlsx($export_rows, $xlsx_path);
        if ($built && file_exists($xlsx_path)) {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename_base . '.xlsx"');
            header('Content-Length: ' . filesize($xlsx_path));
            readfile($xlsx_path);
            @unlink($xlsx_path);
            exit;
        }
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename_base . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    foreach ($export_rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
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
    :root { --primary-color:#1890ff; --text-color:#262626; --text-secondary-color:#8c8c8c; --border-color:#f0f0f0; --background-color:#f0f2f5; --card-background-color:#fff; }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: var(--background-color); color: var(--text-color); overflow-x: hidden; }
    .dashboard { display:flex; min-height:100vh; }
    .content { padding:24px; width:100%; }
    .page-controls { display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:16px; }
    .breadcrumb { font-size:16px; color:var(--text-secondary-color); }
    .breadcrumb a { color:var(--primary-color); text-decoration:none; }
    .breadcrumb a:hover { text-decoration:underline; }
    .btn-view { padding:6px 12px; border:1px solid #1890ff; border-radius:6px; cursor:pointer; font-size:14px; text-decoration:none; display:inline-flex; align-items:center; gap:6px; transition:all .2s; background:#fff; color:#1890ff; white-space:nowrap; }
    .btn-view:hover { background:#1890ff; color:#fff; }
    .btn-secondary { background:#fff; color:#595959; border-color:#d9d9d9; }
    .btn-secondary:hover { border-color:#1890ff; color:#1890ff; background:#fff; }
    .table-wrapper { background:var(--card-background-color); border-radius:8px; box-shadow:0 1px 2px rgba(0,0,0,0.03); border:1px solid var(--border-color); }
    .toolbar { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:16px 18px; border-bottom:1px solid var(--border-color); }
    .toolbar-right { display:flex; align-items:center; gap:10px; flex-wrap:wrap; justify-content:flex-end; }
    .toolbar-label { font-size:14px; color:var(--text-color); white-space:nowrap; margin-right:4px; }
    .count { color:var(--text-secondary-color); font-size:14px; }
    .search-input, .search-select { padding:8px 10px; border:1px solid #d9d9d9; border-radius:8px; font-size:14px; min-width:120px; }
    .search-select { min-width:140px; }
    .pagination-bar { display:flex; justify-content:space-between; align-items:center; padding:12px 18px; border-top:1px solid var(--border-color); font-size:14px; color:var(--text-secondary-color); }
    .pagination-bar .page-info { font-weight:500; color:var(--text-color); }
    .pagination-bar button { padding:6px 12px; margin:0 4px; border:1px solid #d9d9d9; border-radius:6px; background:#fff; cursor:pointer; font-size:14px; }
    .pagination-bar button:hover:not(:disabled) { border-color:#1890ff; color:#1890ff; }
    .pagination-bar button:disabled { opacity:0.5; cursor:not-allowed; }
    .search-input:focus, .search-select:focus { outline:none; border-color:#1890ff; box-shadow:0 0 0 2px rgba(24,144,255,0.15); }
    .table-container { overflow-x:auto; }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:14px 18px; text-align:left; border-bottom:1px solid var(--border-color); font-size:14px; white-space:nowrap; }
    th { background:#fafafa; font-weight:700; }
    tr:hover { background:#fafafa; }
    .empty-state { padding:40px; text-align:center; color:var(--text-secondary-color); }
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
            <a class="btn-view" href="bonus_eligible_list.php?export=1"><i class="fas fa-file-excel"></i> 匯出Excel</a>
            <a class="btn-view" href="admission_recommend_list.php?view=approved_bonus"><i class="fas fa-arrow-left"></i> 返回名單</a>
          </div>
        </div>

        <div class="table-wrapper">
          <div class="toolbar">
            <div style="display:flex; align-items:center; gap:10px;">
              <i class="fas fa-coins" style="color:#1890ff;"></i>
              <strong>可發送獎金名單（審核完成且已線上簽核）</strong>
            </div>
            <div class="toolbar-right">
              <span class="toolbar-label">年度篩選</span>
              <select id="filterYear" class="search-select">
                <option value="">全部學年度</option>
                <?php foreach ($year_options_eligible as $y): ?>
                  <option value="<?php echo (int)$y; ?>"><?php echo (int)$y; ?></option>
                <?php endforeach; ?>
              </select>
              <input type="text" id="filterRecommenderName" class="search-input" placeholder="查詢推薦人姓名">
              <input type="text" id="filterRecommenderId" class="search-input" placeholder="查詢推薦人學號/編號">
              <select id="filterDepartment" class="search-select">
                <option value="">全部科系</option>
                <?php foreach ($dept_options as $d): ?>
                  <option value="<?php echo htmlspecialchars($d); ?>"><?php echo htmlspecialchars($d); ?></option>
                <?php endforeach; ?>
              </select>
              <button type="button" class="btn-view" id="btnQuery"><i class="fas fa-search"></i> 查詢</button>
              <button type="button" class="btn-view btn-secondary" id="btnClear"><i class="fas fa-eraser"></i> 清除</button>
              <div class="count" id="eligibleCount">共 <?php echo $total_count_eligible; ?> 筆，每頁顯示 10 筆</div>
            </div>
          </div>
          <div class="table-container">
            <?php if (empty($rows)): ?>
              <div class="empty-state">
                <i class="fas fa-inbox fa-3x" style="display:block; margin:0 auto 10px; opacity:0.7;"></i>
                <div>目前沒有可發送獎金的資料</div>
              </div>
            <?php else: ?>
              <table>
                <thead>
                  <tr>
                    <th>推薦ID</th>
                    <th>被推薦人</th>
                    <th>推薦人姓名</th>
                    <th>推薦人學號/編號</th>
                    <th>推薦人科系</th>
                    <th>審核結果</th>
                    <th>線上簽核</th>
                    <th>推薦時間</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($rows as $r): ?>
                    <tr class="eligible-row"
                        data-recommender-name="<?php echo htmlspecialchars((string)($r['recommender_name'] ?? '')); ?>"
                        data-recommender-id="<?php echo htmlspecialchars((string)($r['recommender_student_id'] ?? '')); ?>"
                        data-recommender-dept="<?php echo htmlspecialchars((string)($r['recommender_department'] ?? '')); ?>"
                        data-academic-year="<?php echo htmlspecialchars((string)($r['academic_year'] ?? '')); ?>">
                      <td><?php echo htmlspecialchars((string)($r['id'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string)($r['student_name'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string)($r['recommender_name'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string)($r['recommender_student_id'] ?? '')); ?></td>
                      <td><?php echo htmlspecialchars((string)($r['recommender_department'] ?? '')); ?></td>
                      <td>審核完成（可發獎金）</td>
                      <td>已線上簽核</td>
                      <td><?php echo htmlspecialchars((string)($r['created_at'] ?? '')); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
          <?php if (!empty($rows)): ?>
          <div class="pagination-bar" id="eligiblePaginationBar">
            <span class="page-info" id="eligiblePageInfo">第 1–10 筆</span>
            <div>
              <button type="button" id="eligiblePrevPage" title="上一頁">上一頁</button>
              <button type="button" id="eligibleNextPage" title="下一頁">下一頁</button>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <script>
    const ELIGIBLE_PAGE_SIZE = 10;
    let eligibleCurrentPage = 1;

    function applyEligibleFilters() {
      const yearVal = (document.getElementById('filterYear')?.value || '').trim();
      const nameVal = (document.getElementById('filterRecommenderName')?.value || '').trim().toLowerCase();
      const idVal = (document.getElementById('filterRecommenderId')?.value || '').trim().toLowerCase();
      const deptVal = (document.getElementById('filterDepartment')?.value || '').trim().toLowerCase();
      const rows = Array.from(document.querySelectorAll('tr.eligible-row'));
      rows.forEach(tr => {
        const yr = (tr.getAttribute('data-academic-year') || '').toString();
        const nm = (tr.getAttribute('data-recommender-name') || '').toLowerCase();
        const rid = (tr.getAttribute('data-recommender-id') || '').toLowerCase();
        const dept = (tr.getAttribute('data-recommender-dept') || '').toLowerCase();
        let ok = true;
        if (yearVal && yr !== yearVal) ok = false;
        if (nameVal && nm.indexOf(nameVal) === -1) ok = false;
        if (idVal && rid.indexOf(idVal) === -1) ok = false;
        if (deptVal && dept !== deptVal) ok = false;
        tr.style.display = ok ? '' : 'none';
        tr.dataset.visible = ok ? '1' : '0';
      });
      eligibleCurrentPage = 1;
      updateEligiblePagination();
    }

    function updateEligiblePagination() {
      const rows = Array.from(document.querySelectorAll('tr.eligible-row'));
      const visibleRows = rows.filter(tr => tr.dataset.visible !== '0');
      const total = visibleRows.length;
      const totalPages = Math.max(1, Math.ceil(total / ELIGIBLE_PAGE_SIZE));
      const start = (eligibleCurrentPage - 1) * ELIGIBLE_PAGE_SIZE;
      const end = Math.min(start + ELIGIBLE_PAGE_SIZE, total);

      rows.forEach(tr => { tr.style.display = 'none'; });
      visibleRows.forEach((tr, idx) => {
        if (idx >= start && idx < end) tr.style.display = '';
      });

      const cnt = document.getElementById('eligibleCount');
      if (cnt) cnt.textContent = '共 ' + total + ' 筆，每頁顯示 ' + ELIGIBLE_PAGE_SIZE + ' 筆';

      const pageInfo = document.getElementById('eligiblePageInfo');
      if (pageInfo) pageInfo.textContent = total === 0 ? '第 0 筆' : '第 ' + (start + 1) + '–' + end + ' 筆';

      const prevBtn = document.getElementById('eligiblePrevPage');
      const nextBtn = document.getElementById('eligibleNextPage');
      if (prevBtn) prevBtn.disabled = eligibleCurrentPage <= 1;
      if (nextBtn) nextBtn.disabled = eligibleCurrentPage >= totalPages;
    }

    function clearEligibleFilters() {
      const y = document.getElementById('filterYear');
      const n = document.getElementById('filterRecommenderName');
      const i = document.getElementById('filterRecommenderId');
      const d = document.getElementById('filterDepartment');
      if (y) y.value = '';
      if (n) n.value = '';
      if (i) i.value = '';
      if (d) d.value = '';
      applyEligibleFilters();
    }

    document.addEventListener('DOMContentLoaded', function() {
      const rows = document.querySelectorAll('tr.eligible-row');
      rows.forEach(tr => { tr.dataset.visible = '1'; });
      updateEligiblePagination();

      document.getElementById('btnQuery')?.addEventListener('click', applyEligibleFilters);
      document.getElementById('btnClear')?.addEventListener('click', clearEligibleFilters);
      document.getElementById('filterYear')?.addEventListener('change', applyEligibleFilters);
      ['filterRecommenderName', 'filterRecommenderId'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('keyup', function(e) {
          if (e.key === 'Enter') applyEligibleFilters();
        });
      });
      document.getElementById('filterDepartment')?.addEventListener('change', applyEligibleFilters);

      document.getElementById('eligiblePrevPage')?.addEventListener('click', function() {
        if (eligibleCurrentPage > 1) { eligibleCurrentPage--; updateEligiblePagination(); }
      });
      document.getElementById('eligibleNextPage')?.addEventListener('click', function() {
        const rows = document.querySelectorAll('tr.eligible-row');
        const visibleCount = Array.from(rows).filter(tr => tr.dataset.visible !== '0').length;
        if (eligibleCurrentPage < Math.ceil(visibleCount / ELIGIBLE_PAGE_SIZE)) {
          eligibleCurrentPage++;
          updateEligiblePagination();
        }
      });
    });
  </script>
</body>
</html>
