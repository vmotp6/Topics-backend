<?php
require_once __DIR__ . '/session_config.php';
checkBackendLogin();

// 僅允許 招生中心（STA）與管理員（ADM）
$user_role = $_SESSION['role'] ?? '';
$allowed = ['STA', '行政人員', '學校行政人員', 'ADM', '管理員'];
if (!in_array($user_role, $allowed, true)) {
  http_response_code(403);
  echo '權限不足：僅招生中心（STA）可使用此功能。';
  exit;
}

// 引入資料庫設定（沿用後台常見的多路徑尋找方式）
$config_path = '../../Topics-frontend/frontend/config.php';
if (!file_exists($config_path)) {
  $alt_paths = [
    '../../../Topics-frontend/frontend/config.php',
    __DIR__ . '/../../Topics-frontend/frontend/config.php',
    dirname(__DIR__) . '/../Topics-frontend/frontend/config.php'
  ];
  foreach ($alt_paths as $p) {
    if (file_exists($p)) { $config_path = $p; break; }
  }
}
if (!file_exists($config_path)) {
  die('錯誤：找不到資料庫設定檔案 (config.php)');
}
require_once $config_path;

function ensureNewStudentBasicInfoTable($conn) {
  $sql = "CREATE TABLE IF NOT EXISTS new_student_basic_info (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_no VARCHAR(50) NOT NULL,
    student_name VARCHAR(100) NOT NULL,
    class_name VARCHAR(100) NOT NULL,
    department_id VARCHAR(50) NOT NULL,
    enrollment_identity VARCHAR(100) DEFAULT NULL,
    birthday DATE DEFAULT NULL,
    gender VARCHAR(20) DEFAULT NULL,
    id_number VARCHAR(50) DEFAULT NULL,
    mobile VARCHAR(50) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    previous_school VARCHAR(150) DEFAULT NULL,
    photo_path VARCHAR(255) DEFAULT NULL,

    parent_title VARCHAR(50) DEFAULT NULL,
    parent_name VARCHAR(100) DEFAULT NULL,
    parent_birth_year VARCHAR(20) DEFAULT NULL,
    parent_occupation VARCHAR(100) DEFAULT NULL,
    parent_phone VARCHAR(50) DEFAULT NULL,
    parent_education VARCHAR(100) DEFAULT NULL,

    guardian_relation VARCHAR(50) DEFAULT NULL,
    guardian_name VARCHAR(100) DEFAULT NULL,
    guardian_phone VARCHAR(50) DEFAULT NULL,
    guardian_mobile VARCHAR(50) DEFAULT NULL,
    guardian_line VARCHAR(100) DEFAULT NULL,
    guardian_email VARCHAR(150) DEFAULT NULL,

    emergency_name VARCHAR(100) DEFAULT NULL,
    emergency_phone VARCHAR(50) DEFAULT NULL,
    emergency_mobile VARCHAR(50) DEFAULT NULL,

    is_indigenous TINYINT(1) DEFAULT 0,
    is_new_immigrant_child TINYINT(1) DEFAULT 0,
    is_overseas_chinese TINYINT(1) DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT '在學',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_graduated TINYINT(1) DEFAULT 0,
    INDEX idx_student_no (student_no),
    INDEX idx_student_name (student_name),
    INDEX idx_created_at (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  $conn->query($sql);

  // 若資料表已存在，補上狀態欄位（預設：在學），並回填既有資料
  if (!hasColumn($conn, 'new_student_basic_info', 'status')) {
    $conn->query("ALTER TABLE new_student_basic_info ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT '在學' AFTER is_overseas_chinese");
  }
  if (hasColumn($conn, 'new_student_basic_info', 'status')) {
    $conn->query("UPDATE new_student_basic_info SET status = '在學' WHERE status IS NULL OR status = ''");
  }
}

function hasColumn($conn, $table, $column) {
  if (!$conn) return false;
  $table = trim((string)$table);
  $column = trim((string)$column);
  if ($table === '' || $column === '') return false;
  try {
    // 用 INFORMATION_SCHEMA 查欄位是否存在（不依賴 mysqlnd / get_result）
    $stmt = $conn->prepare("
      SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $cnt = 0;
    $stmt->bind_result($cnt);
    $stmt->fetch();
    $ok = ((int)$cnt > 0);
    $stmt->close();
    return $ok;
  } catch (Exception $e) {
    return false;
  }
}

function detectFirstExistingColumn($conn, $table, $candidates) {
  if (!is_array($candidates)) return '';
  foreach ($candidates as $col) {
    if (hasColumn($conn, $table, $col)) return (string)$col;
  }
  return '';
}

function photoUrl($photo_path) {
  $p = trim((string)$photo_path);
  if ($p === '') return '';
  // 若已是絕對路徑或完整 URL，原樣回傳
  if (preg_match('/^(https?:\\/\\/|\\/)/i', $p)) return $p;
  // new_student_basic_info.php 存的是 uploads/... 相對路徑，從後台要指到前台目錄
  return '/Topics-frontend/frontend/' . ltrim($p, '/');
}

// 計算當前學年度的開始和結束日期
// 學年度定義：6月 ~ 隔年6月（例如：2026/06/01 ~ 2027/06/30 為 2026 學年度）
function getCurrentAcademicYearRange() {
  $current_month = (int)date('m');
  $current_year = (int)date('Y');
  
  // 如果當前月份 >= 6月，學年度從今年6月開始到明年6月結束
  // 如果當前月份 < 6月，學年度從去年6月開始到今年6月結束
  if ($current_month >= 6) {
    $start_year = $current_year;
    $end_year = $current_year + 1;
  } else {
    $start_year = $current_year - 1;
    $end_year = $current_year;
  }
  
  return [
    'start' => sprintf('%04d-06-01 00:00:00', $start_year),
    'end' => sprintf('%04d-06-30 23:59:59', $end_year)
  ];
}

// 根據西元年份計算學年度範圍（民國年）
// 學年度：6月 ~ 隔年6月
// 例如：2026年6月1日 ~ 2027年6月30日 為 115學年度（2026-1911=115）
function getAcademicYearRangeByRocYear($roc_year) {
  $ad_year = $roc_year + 1911; // 民國年轉西元年
  return [
    'start' => sprintf('%04d-06-01 00:00:00', $ad_year),
    'end' => sprintf('%04d-06-30 23:59:59', $ad_year + 1)
  ];
}

// 根據 created_at 計算學年度（民國年）
// 如果月份 >= 6，學年度是該年份；如果月份 < 6，學年度是前一年份
function getRocYearFromCreatedAt($created_at) {
  $timestamp = strtotime($created_at);
  if ($timestamp === false) return null;
  
  $year = (int)date('Y', $timestamp);
  $month = (int)date('m', $timestamp);
  
  // 如果月份 < 6，學年度是前一年
  if ($month < 6) {
    $year -= 1;
  }
  
  return $year - 1911; // 轉換為民國年
}

$page_title = '新生基本資料管理';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
 $view = isset($_GET['view']) ? $_GET['view'] : 'active';
$selected_roc_year = isset($_GET['roc_year']) ? (int)$_GET['roc_year'] : 0; // 選中的學年度（民國年）
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
if ($limit <= 0) $limit = 50;
if ($limit > 200) $limit = 200;
if ($offset < 0) $offset = 0;

$rows = [];
$total = 0;
$error_message = '';

try {
    $conn = getDatabaseConnection();
    if (!$conn) throw new Exception('資料庫連接失敗');
    ensureNewStudentBasicInfoTable($conn);

    // 兼容不同版本欄位命名：department_id / department / department_code...
    $dept_col = detectFirstExistingColumn($conn, 'new_student_basic_info', [
        'department_id',
        'department',
        'department_code',
        'dept_code',
        'dept'
    ]);
    $dept_join = '';
    $dept_select = "'' AS department_code, '' AS department_name";
    if ($dept_col !== '') {
        // departments 可能用 code 或 id 當鍵
        $dept_key = '';
        if (hasColumn($conn, 'departments', 'code')) $dept_key = 'code';
        elseif (hasColumn($conn, 'departments', 'id')) $dept_key = 'id';

        if ($dept_key !== '' && hasColumn($conn, 'departments', 'name')) {
            $dept_select = "s.`$dept_col` AS department_code, COALESCE(d.name,'') AS department_name";
            // 避免不同 collation 造成 Illegal mix of collations（多發生於 code 類字串欄位）
            if ($dept_key === 'code') {
                $dept_join = " LEFT JOIN departments d ON s.`$dept_col` COLLATE utf8mb4_unicode_ci = d.`$dept_key` COLLATE utf8mb4_unicode_ci ";
            } else {
                // 若 departments 使用數字主鍵（id），用原本等號比較即可
                $dept_join = " LEFT JOIN departments d ON s.`$dept_col` = d.`$dept_key` ";
            }
        } else {
            $dept_select = "s.`$dept_col` AS department_code, '' AS department_name";
            $dept_join = "";
        }
    }
    // 取得當前學年度範圍
    $academic_year = getCurrentAcademicYearRange();

    // ========================================
    // 1. 基本 WHERE 條件：新生/歷屆學生/歷史資料
    // ========================================
    $where = ' WHERE 1=1 ';

/*
判斷基準：
- 五專修業 5 年
- 畢業日：入學第 5 年的 7/31
*/

$graduateExpr = "DATE(CONCAT(YEAR(created_at) + 5, '-07-31'))";

if ($view === 'history') {
    // 已畢業
    $where .= " AND CURDATE() > $graduateExpr ";
} elseif ($view === 'previous') {
    // 非新生但仍在學
    $where .= " AND CURDATE() <= $graduateExpr
                AND created_at NOT BETWEEN ? AND ? ";
    
    // 如果有選擇學年度，進一步篩選
    if ($selected_roc_year > 0) {
        $selected_year_range = getAcademicYearRangeByRocYear($selected_roc_year);
        $where .= " AND created_at >= ? AND created_at <= ? ";
    }
} else {
    // active：當學年度新生
    $where .= " AND CURDATE() <= $graduateExpr
                AND created_at BETWEEN ? AND ? ";
}



    // ========================================
    // 2. 搜尋條件
    // ========================================
    $types = '';
    $params = [];
    
    // 如果是「新生」或「歷屆學生」，需要加入學年度範圍參數
    if ($view === 'active' || $view === 'previous') {
        $types .= 'ss';
        $params[] = $academic_year['start'];
        $params[] = $academic_year['end'];
        
        // 如果是「歷屆學生」且有選擇學年度，加入學年度篩選參數
        if ($view === 'previous' && $selected_roc_year > 0) {
            $types .= 'ss';
            $params[] = $selected_year_range['start'];
            $params[] = $selected_year_range['end'];
        }
    }
    
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where .= " AND (
            student_no LIKE ? OR
            student_name LIKE ? OR
            class_name LIKE ? OR
            id_number LIKE ? OR
            mobile LIKE ?
        ) ";
        $types .= 'sssss';
        $params = array_merge($params, [$like, $like, $like, $like, $like]);
    }

    // ========================================
    // 3. 計算總筆數
    // ========================================
    $countSql = "SELECT COUNT(*) AS cnt FROM new_student_basic_info" . $where;
    $countStmt = $conn->prepare($countSql);
    if (!$countStmt) throw new Exception('查詢準備失敗');
    if (!empty($params)) $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $res = $countStmt->get_result();
    $total = 0;
    if ($r = $res->fetch_assoc()) $total = (int)$r['cnt'];
    $countStmt->close();
    

    // ========================================
    // 4. 取得列表資料
    // ========================================
    $listSql = "SELECT
    s.id,
    s.student_no,
    s.student_name,
    s.class_name,
    $dept_select,
    DATE_FORMAT(s.created_at, '%Y-%m-%d %H:%i:%s') AS created_at
    FROM new_student_basic_info s" . $dept_join . $where . " ORDER BY s.created_at DESC, s.id DESC LIMIT ? OFFSET ?";


    $stmt = $conn->prepare($listSql);
    if (!$stmt) throw new Exception('查詢準備失敗');

    if (!empty($params)) {
        $types2 = $types . 'ii';
        $params2 = $params;
        $params2[] = $limit;
        $params2[] = $offset;
        $stmt->bind_param($types2, ...$params2);
    } else {
        $stmt->bind_param('ii', $limit, $offset);
    }

    $stmt->execute();
    $r2 = $stmt->get_result();
    $rows = $r2 ? $r2->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    
    // ========================================
    // 5. 查詢所有可用的學年度選項（自動抓取所有學年度）
    // ========================================
    $available_roc_years = [];
    
    if ($view === 'previous') {
        // 查詢所有歷屆學生的學年度（非新生但仍在學）
        $yearSql = "SELECT DISTINCT 
            (CASE 
                WHEN MONTH(created_at) < 6 THEN YEAR(created_at) - 1
                ELSE YEAR(created_at)
            END) - 1911 AS roc_year
            FROM new_student_basic_info
            WHERE CURDATE() <= DATE(CONCAT(YEAR(created_at) + 5, '-07-31'))
            AND created_at NOT BETWEEN ? AND ?
            HAVING roc_year > 0
            ORDER BY roc_year DESC";
        
        $yearStmt = $conn->prepare($yearSql);
        if ($yearStmt) {
            $yearStmt->bind_param('ss', $academic_year['start'], $academic_year['end']);
            $yearStmt->execute();
            $yearResult = $yearStmt->get_result();
            if ($yearResult) {
                while ($yearRow = $yearResult->fetch_assoc()) {
                    $roc_year = (int)$yearRow['roc_year'];
                    if ($roc_year > 0) {
                        $available_roc_years[] = $roc_year;
                    }
                }
            }
            $yearStmt->close();
        }
    }
    

    
    // 排序學年度（從大到小）
    rsort($available_roc_years);

    $conn->close();
} catch (Exception $e) {
    $error_message = $e->getMessage();
}



?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo htmlspecialchars($page_title); ?></title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <style>
    .dashboard { display: flex; min-height: 100vh; background: #f0f2f5; }
    .main-content { flex: 1; margin-left: 250px; transition: all 0.3s ease; background: #f0f2f5; }
    .main-content.expanded { margin-left: 60px; }
    .content { padding: 24px; }

    .panel {
      background: #fff;
      border: 1px solid #f0f0f0;
      border-radius: 10px;
      padding: 16px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.06);
      margin-bottom: 16px;
    }

    .filters { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
    .filters input {
      padding: 8px 10px;
      border: 1px solid #d9d9d9;
      border-radius: 6px;
      font-size: 14px;
      min-width: 260px;
    }
    .filters button {
      padding: 8px 14px;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      font-weight: 600;
      background: #1890ff;
      color: #fff;
    }
    .filters a.reset {
      padding: 8px 14px;
      border: 1px solid #d9d9d9;
      border-radius: 6px;
      text-decoration: none;
      color: #333;
      background: #fff;
      font-weight: 600;
    }

    table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e0e0e0; }
    th, td { padding: 10px 10px; border: 1px solid #e0e0e0; text-align: left; font-size: 13px; vertical-align: top; }
    th { background: #fafafa; position: sticky; top: 0; z-index: 1; }
    .muted { color: #777; font-size: 12px; }
    .tag { display:inline-block; padding: 2px 10px; border-radius: 999px; background: #f5f5f5; color: #333; font-weight: 700; font-size: 12px; }
    .tag.yes { background: #e6f7ff; color:#1890ff; }

    .photo {
      width: 46px;
      height: 46px;
      border-radius: 6px;
      object-fit: cover;
      border: 1px solid #eee;
      background: #fafafa;
    }

    .pager { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }
    .pager a {
      display:inline-block;
      padding: 6px 10px;
      border: 1px solid #d9d9d9;
      border-radius: 6px;
      text-decoration: none;
      color: #333;
      background: #fff;
      font-weight: 600;
    }

/* Pagination */
        .pagination { 
          padding: 16px 24px; 
          display: flex; 
          justify-content: space-between; 
          align-items: center; 
          border-top: 1px solid var(--border-color); 
          background: #fafafa; 
        }

        .pagination-info { 
          display: flex; 
          align-items: center; 
          gap: 16px; 
          color: var(--text-secondary-color); 
          font-size: 14px; 
        
        }
        .pagination-controls { 
          display: flex; 
          align-items: center; 
          gap: 8px; 
        }
        .pagination select { 
          padding: 6px 12px;
           border: 1px solid #d9d9d9; 
           border-radius: 6px; 
           font-size: 14px;
           background: #fff; 
           cursor: pointer; 
          }

        .pagination select:focus { 
          outline: none; 
          border-color: #1890ff; 
          box-shadow: 0 0 0 2px rgba(24,144,255,0.2); 
        }

        .pagination button { 
          padding: 6px 12px; 
          border: 1px solid #d9d9d9; 
          background: #fff; color: #595959; 
          border-radius: 6px; cursor: pointer; 
          font-size: 14px; 
          transition: all 0.3s; 
        }

        .pagination button:hover:not(:disabled) {
           border-color: #1890ff; 
           color: #1890ff;
          }

        .pagination button:disabled { 
          opacity: 0.5; 
          cursor: not-allowed; 
        }

        .pagination button.active { 
          background: #1890ff;
          color: white; 
          border-color: #1890ff; 
          }

    
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

    @media (max-width: 768px) {
      .main-content { margin-left: 0; }
    }
  </style>
</head>
<body>
  <div class="dashboard">
    <?php include 'sidebar.php'; ?>
    <div class="main-content" id="mainContent">
      <?php include 'header.php'; ?>
      <div class="content">

        <div class="panel">
          <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
            <div>
              <div style="font-size:18px; font-weight:700; color:#262626;">
                <i class="fas fa-clipboard-list"></i> <?php echo htmlspecialchars($page_title); ?>
              </div>
              <div class="muted">查看訪客提交的新生入學基本資料（含照片上傳）。</div>
            </div>
            <div class="muted">共 <?php echo (int)$total; ?> 筆</div>
          </div>
        </div>

        <?php if (!empty($error_message)): ?>
          <div class="panel" style="border-color:#ffccc7; background:#fff2f0; color:#a8071a;">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
          </div>
        <?php endif; ?>

        <div class="panel">


          <form method="GET" class="filters">
            <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
            <?php if ($view === 'previous' && $selected_roc_year > 0): ?>
              <input type="hidden" name="roc_year" value="<?php echo (int)$selected_roc_year; ?>">
            <?php endif; ?>
            <input name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="搜尋：學號/姓名/班級/身分證號/手機">
            <button type="submit"><i class="fas fa-search"></i> 查詢</button>
            <a class="reset" href="new_student_basic_info_management.php"><i class="fas fa-eraser"></i> 清除</a>
            <a class="reset" href="new_student_basic_info_management.php?view=<?php echo htmlspecialchars($view); ?><?php echo ($view === 'previous' && $selected_roc_year > 0) ? '&roc_year=' . (int)$selected_roc_year : ''; ?>"><i class="fas fa-eraser"></i> 清除</a>
          </form>
        </div>

        <div class="panel" style="padding:0; overflow:auto;">
          <table  id="enrollmentTable">
            <div style="margin-bottom: 15px; display: flex; gap: 10px; align-items: center; margin-top:15px; margin-left:10px;">
              <a href="?view=active" style="padding: 6px 12px; border: 1px solid <?php echo $view === 'active' ? '#91d5ff' : '#d9d9d9'; ?>; border-radius: 6px; text-decoration: none; background: <?php echo $view === 'active' ? '#f0f7ff' : '#fff'; ?>; color: <?php echo $view === 'active' ? '#1890ff' : '#595959'; ?>;">新生</a>
              <a href="?view=previous<?php echo $selected_roc_year > 0 ? '&roc_year=' . $selected_roc_year : ''; ?>" style="padding: 6px 12px; border: 1px solid <?php echo $view === 'previous' ? '#91d5ff' : '#d9d9d9'; ?>; border-radius: 6px; text-decoration: none; background: <?php echo $view === 'previous' ? '#f0f7ff' : '#fff'; ?>; color: <?php echo $view === 'previous' ? '#1890ff' : '#595959'; ?>;">歷屆學生</a>
              <a href="?view=history" style="padding: 6px 12px; border: 1px solid <?php echo $view === 'history' ? '#91d5ff' : '#d9d9d9'; ?>; border-radius: 6px; text-decoration: none; background: <?php echo $view === 'history' ? '#f0f7ff' : '#fff'; ?>; color: <?php echo $view === 'history' ? '#1890ff' : '#595959'; ?>;">歷史資料</a>
              <?php if ($view === 'previous'): ?>
                <select id="rocYearSelect" onchange="changeRocYear(this.value)" style="padding: 6px 12px; border: 1px solid #d9d9d9; border-radius: 6px; background: #fff; color: #333; font-size: 14px; cursor: pointer; margin-left: 75%;">
                  <option value="0">全部學年</option>
                  <?php foreach ($available_roc_years as $roc_year): ?>
                    <option value="<?php echo $roc_year; ?>" <?php echo $selected_roc_year == $roc_year ? 'selected' : ''; ?>>
                      <?php echo $roc_year; ?>學年
                    </option>
                  <?php endforeach; ?>
                </select>
              <?php endif; ?>
            </div>
            <thead>
              <tr class="table-row-clickable">
                <th style="min-width:110px;">學號</th>
                <th style="min-width:110px;">姓名</th>
                <th style="min-width:140px;">所在科系</th>
                <th style="min-width:160px;" id="th-created-at">建立時間  <i class="fas fa-sort"></i></th>
                <th style="min-width:120px;">操作</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($rows)): ?>
                <tr ><td colspan="5" style="padding:18px; text-align:center; color:#666;">目前沒有資料</td></tr>
              <?php else: ?>
                <?php foreach ($rows as $r): ?>
                  <tr  class="table-row-clickable">
                    <td> 
                      <?php echo htmlspecialchars($r['student_no'] ?? ''); ?> 
                      <?php if($view === 'history'): ?>
                        <span class="tag" style="background:#fff1f0; color:#cf1322;">歷史資料</span>
                      <?php elseif($view === 'previous'): ?>
                        <span class="tag" style="background:#fff7e6; color:#d46b08;">歷屆學生</span>
                      <?php endif; ?>
                    </td>
                    <td><strong><?php echo htmlspecialchars($r['student_name'] ?? ''); ?></strong></td>
                    <td><?php echo htmlspecialchars($r['department_name'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($r['created_at'] ?? ''); ?></td>
                    <td>
                      <div style="display:flex; gap:8px; align-items:center;">
                        <a href="new_student_basic_info_detail.php?id=<?php echo (int)($r['id'] ?? 0); ?>" style="display:inline-block; padding:6px 10px; border:1px solid #1890ff; border-radius:6px; text-decoration:none; color:#1890ff; font-weight:700; background:#fff;">
                          查看詳情
                        </a>
                        <?php if($view === 'previous'): ?>
                          <button onclick="addToCurrentStudents(<?php echo (int)($r['id'] ?? 0); ?>)" style="padding:6px 10px; border:1px solid #52c41a; border-radius:6px; background:#f6ffed; color:#52c41a; font-weight:700; cursor:pointer;">
                            加入新生
                          </button>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
          
                            <div class="pagination">
                                <div class="pagination-info">
                                    <span style="color:#8c8c8c;">每頁顯示：</span>
                                    <select id="itemsPerPage" onchange="changeItemsPerPage()">
                                        <option value="10" selected>10</option>
                                        <option value="20">20</option>
                                        <option value="50">50</option>
                                        <option value="100">100</option>
                                        <option value="all">全部</option>
                                    </select>
                                    <span id="pageInfo" style="color:#8c8c8c;">顯示第 <span id="currentRange">1-<?php echo min($limit, $total); ?></span> 筆，共 <?php echo (int)$total; ?> 筆</span>
                                </div>
                                <div class="pagination-controls">
                                    <button id="prevPage" onclick="changePage(-1)" disabled>上一頁</button>
                                    <span id="pageNumbers"></span>
                                    <button id="nextPage" onclick="changePage(1)">下一頁</button>
                                </div>
                            </div>
        </div>


      </div>
    </div>
  </div>
  <script>  
      // Apply sort icons
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const sortBy = urlParams.get('sort_by') || 'created_at';
            const sortOrder = urlParams.get('sort_order') || 'desc';
            
            const icon = document.getElementById('sort-' + sortBy);
            if (icon) {
                icon.classList.add('active');
                icon.classList.add(sortOrder);
            }
            
            // 初始化分頁
            initPagination();
        });

        // 分頁相關變數
        let currentPage = 1;
        let itemsPerPage = 10;
        let allRows = [];
        let filteredRows = [];
        let sortOrder = 'desc'; // 初始排序

      function sortTableByColumn(colIndex) {
      const tbody = document.querySelector('#enrollmentTable tbody');

      allRows.sort((a, b) => {
        const valA = a.cells[colIndex].textContent.trim();
        const valB = b.cells[colIndex].textContent.trim();

        // 嘗試解析日期
        const dateA = new Date(valA);
        const dateB = new Date(valB);

        if (!isNaN(dateA) && !isNaN(dateB)) {
            return sortOrder === 'asc' ? dateA - dateB : dateB - dateA;
        }

          // fallback: 字串排序
          return sortOrder === 'asc' ? valA.localeCompare(valB) : valB.localeCompare(valA);
        });

          allRows.forEach(row => tbody.appendChild(row));
          sortOrder = sortOrder === 'asc' ? 'desc' : 'asc';
          updatePagination();
        }

        document.getElementById('th-created-at').addEventListener('click', () => sortTableByColumn(3));


        // 初始化分頁
        function initPagination() {
            const table = document.getElementById('enrollmentTable');
            if (!table) return;
            
            const tbody = table.getElementsByTagName('tbody')[0];
            if (!tbody) return;
            
            // 只選取主列（有 table-row-clickable 類別的列）
            allRows = Array.from(tbody.querySelectorAll('tr.table-row-clickable'));
            filteredRows = allRows;
            
            updatePagination();
        }

        function changeItemsPerPage() {
            const select = document.getElementById('itemsPerPage');
            itemsPerPage = select.value === 'all' ? 
                          filteredRows.length : 
                          parseInt(select.value);
            currentPage = 1;
            updatePagination();
        }

        function changePage(direction) {
            const totalPages = Math.ceil(filteredRows.length / itemsPerPage);
            currentPage += direction;
            
            if (currentPage < 1) currentPage = 1;
            if (currentPage > totalPages) currentPage = totalPages;
            
            updatePagination();
        }

        function goToPage(page) {
            currentPage = page;
            updatePagination();
        }

        function updatePagination() {
            const totalItems = filteredRows.length;
            const totalPages = itemsPerPage === 'all' ? 1 : Math.ceil(totalItems / itemsPerPage);
            
            // 首先隱藏所有主列和關聯的詳情列
            allRows.forEach(row => {
                row.style.display = 'none';
                // 同時確保關聯的詳情列也被隱藏
                const nextRow = row.nextElementSibling;
                if (nextRow && nextRow.classList.contains('detail-row')) {
                    nextRow.style.display = 'none';
                    // 重置按鈕狀態
                    const detailBtn = row.querySelector('.btn-view[id^="detail-btn-"]');
                    if (detailBtn) {
                        const btnText = detailBtn.querySelector('.btn-text');
                        if (btnText) btnText.textContent = '查看詳情';
                        const icon = detailBtn.querySelector('i');
                        if (icon) icon.className = 'fas fa-eye';
                    }
                }
            });
            // 重置當前打開的詳情 ID
            currentOpenDetailId = null;
            
            if (itemsPerPage === 'all' || itemsPerPage >= totalItems) {
                // 顯示所有過濾後的行
                filteredRows.forEach(row => row.style.display = '');
                
                // 更新分頁資訊
                const rangeElem = document.getElementById('currentRange');
                if (rangeElem) rangeElem.textContent = totalItems > 0 ? `1-${totalItems}` : '0-0';
            } else {
                // 計算當前頁的範圍
                const start = (currentPage - 1) * itemsPerPage;
                const end = Math.min(start + itemsPerPage, totalItems);
                
                // 顯示當前頁的行
                for (let i = start; i < end; i++) {
                    if (filteredRows[i]) {
                        filteredRows[i].style.display = '';
                    }
                }
                
                // 更新分頁資訊
                const rangeElem = document.getElementById('currentRange');
                if (rangeElem) rangeElem.textContent = totalItems > 0 ? `${start + 1}-${end}` : '0-0';
            }
            
            // 更新總數
            const pageInfo = document.getElementById('pageInfo');
            if (pageInfo) {
                const rangeText = document.getElementById('currentRange') ? document.getElementById('currentRange').textContent : '0-0';
                pageInfo.innerHTML = `顯示第 <span id="currentRange">${rangeText}</span> 筆，共 ${totalItems} 筆`;
            }
            
            // 更新上一頁/下一頁按鈕
            const prevBtn = document.getElementById('prevPage');
            const nextBtn = document.getElementById('nextPage');
            if (prevBtn) prevBtn.disabled = currentPage === 1;
            if (nextBtn) nextBtn.disabled = currentPage >= totalPages || totalPages <= 1;
            
            // 更新頁碼按鈕
            updatePageNumbers(totalPages);
        }

        function updatePageNumbers(totalPages) {
            const pageNumbers = document.getElementById('pageNumbers');
            if (!pageNumbers) return;
            pageNumbers.innerHTML = '';
            
            if (totalPages >= 1) {
                const pagesToShow = totalPages === 1 ? [1] : Array.from({length: totalPages}, (_, i) => i + 1);
                
                for (let i of pagesToShow) {
                    const btn = document.createElement('button');
                    btn.textContent = i;
                    btn.onclick = () => goToPage(i);
                    if (i === currentPage) btn.classList.add('active');
                    pageNumbers.appendChild(btn);
                }
            }
        }

        // 加入目前學生（新生）
        function addToCurrentStudents(studentId) {
            if (!confirm('確定要將此學生加入「新生」嗎？系統會更新學生的建立時間到當前學年度。')) {
                return;
            }
            
            fetch('add_student_to_current.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'student_id=' + encodeURIComponent(studentId)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('已成功將學生加入「新生」');
                    // 重新載入頁面
                    window.location.reload();
                } else {
                    alert('操作失敗：' + (data.message || '未知錯誤'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('操作失敗：網路錯誤');
            });
        }

        // 變更學年度篩選
        function changeRocYear(rocYear) {
            const url = new URL(window.location.href);
            url.searchParams.set('view', 'previous');
            if (rocYear && rocYear !== '0') {
                url.searchParams.set('roc_year', rocYear);
            } else {
                url.searchParams.delete('roc_year');
            }
            // 清除分頁參數
            url.searchParams.delete('offset');
            window.location.href = url.toString();
        }
    </script>
</body>
</html>
