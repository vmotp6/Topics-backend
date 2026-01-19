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

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_student_no (student_no),
    INDEX idx_student_name (student_name),
    INDEX idx_created_at (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  $conn->query($sql);
}

function photoUrl($photo_path) {
  $p = trim((string)$photo_path);
  if ($p === '') return '';
  // 若已是絕對路徑或完整 URL，原樣回傳
  if (preg_match('/^(https?:\\/\\/|\\/)/i', $p)) return $p;
  // new_student_basic_info.php 存的是 uploads/... 相對路徑，從後台要指到前台目錄
  return '/Topics-frontend/frontend/' . ltrim($p, '/');
}

$page_title = '新生基本資料管理';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
 $view = isset($_GET['view']) ? $_GET['view'] : 'active';
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

    // ========================================
    // 1. 基本 WHERE 條件：歷史/目前學生
    // ========================================
    $where = ' WHERE 1=1 ';
    if ($view === 'history') {
        // 歷史資料：畢業日期 <= 今天
        $where .= " AND (LENGTH(student_no) >= 3 AND CURDATE() >= DATE(CONCAT(CAST(SUBSTRING(student_no, 1, 3) AS UNSIGNED) + 1916, '-08-01'))) ";
    } else {
        // 目前學生：畢業日期 > 今天 或 學號格式不符
        $where .= " AND (LENGTH(student_no) < 3 OR CURDATE() < DATE(CONCAT(CAST(SUBSTRING(student_no, 1, 3) AS UNSIGNED) + 1916, '-08-01'))) ";
    }

    // ========================================
    // 2. 搜尋條件
    // ========================================
    $types = '';
    $params = [];
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
        $params = [$like, $like, $like, $like, $like];
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
        id, student_no, student_name, class_name,
        DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at
      FROM new_student_basic_info" . $where . "
      ORDER BY created_at DESC, id DESC
      LIMIT ? OFFSET ?";

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

    $conn->close();
} catch (Exception $e) {
    $error_message = $e->getMessage();
}


// ==========================================
// 處理分頁與歷史資料邏輯
// ==========================================
$view_mode = $_GET['view'] ?? 'active'; 
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : 0;

$current_month = (int)date('m');
$current_year = (int)date('Y');
$grad_threshold_year = ($current_month >= 8) ? $current_year : $current_year - 1;
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
            <input name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="搜尋：學號/姓名/班級/身分證號/手機">
            <button type="submit"><i class="fas fa-search"></i> 查詢</button>
            <a class="reset" href="new_student_basic_info_management.php"><i class="fas fa-eraser"></i> 清除</a>
            <a class="reset" href="new_student_basic_info_management.php?view=<?php echo htmlspecialchars($view); ?>"><i class="fas fa-eraser"></i> 清除</a>
          </form>
        </div>

        <div class="panel" style="padding:0; overflow:auto;">
          <table  id="enrollmentTable">
            <div style="margin-bottom: 15px; display: flex; gap: 10px; margin-top:15px; margin-left:10px;">
              <a href="?view=active" style="padding: 6px 12px; border: 1px solid <?php echo $view !== 'history' ? '#91d5ff' : '#d9d9d9'; ?>; border-radius: 6px; text-decoration: none; background: <?php echo $view !== 'history' ? '#f0f7ff' : '#fff'; ?>; color: <?php echo $view !== 'history' ? '#1890ff' : '#595959'; ?>;">目前學生</a>
              <a href="?view=history" style="padding: 6px 12px; border: 1px solid <?php echo $view === 'history' ? '#91d5ff' : '#d9d9d9'; ?>; border-radius: 6px; text-decoration: none; background: <?php echo $view === 'history' ? '#f0f7ff' : '#fff'; ?>; color: <?php echo $view === 'history' ? '#1890ff' : '#595959'; ?>;">歷史資料</a>
            </div>
            <thead>
              <tr class="table-row-clickable">
                <th style="min-width:110px;">學號</th>
                <th style="min-width:110px;">姓名</th>
                <th style="min-width:110px;">班級</th>
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
                    <td> <?php echo htmlspecialchars($r['student_no'] ?? ''); ?> <?php if($view === 'history'): ?>
                        <span class="tag" style="background:#fff1f0; color:#cf1322;">歷史資料</span>
                        <?php endif; ?>
                    </td>
                    <td><strong><?php echo htmlspecialchars($r['student_name'] ?? ''); ?></strong></td>
                    <td><?php echo htmlspecialchars($r['class_name'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($r['created_at'] ?? ''); ?></td>
                    <td>
                      <a href="new_student_basic_info_detail.php?id=<?php echo (int)($r['id'] ?? 0); ?>" style="display:inline-block; padding:6px 10px; border:1px solid #1890ff; border-radius:6px; text-decoration:none; color:#1890ff; font-weight:700; background:#fff;">
                        查看詳情
                      </a>
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
    </script>
</body>
</html>
