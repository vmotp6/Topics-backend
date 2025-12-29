<?php
session_start();

// 檢查登入狀態
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 獲取使用者角色和資訊
$user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['role'] ?? '';
$username = $_SESSION['username'] ?? '';

// 權限判斷
$is_admin = ($user_role === 'ADM');
$is_staff = ($user_role === 'STA');
$is_director = ($user_role === 'DI');
$is_stam = ($user_role === 'STAM');

// 檢查 STAM 是否有教師活動紀錄權限
$stam_has_permission = false;
if ($is_stam && $user_id) {
    try {
        $conn_temp = getDatabaseConnection();
        $perm_stmt = $conn_temp->prepare("SELECT permission_code FROM staff_member_permissions WHERE user_id = ? AND permission_code = 'teacher_activity_records'");
        $perm_stmt->bind_param("i", $user_id);
        $perm_stmt->execute();
        $perm_result = $perm_stmt->get_result();
        if ($perm_result->num_rows > 0) {
            $stam_has_permission = true;
        }
        $perm_stmt->close();
        $conn_temp->close();
    } catch (Exception $e) {
        error_log('Error checking STAM permission: ' . $e->getMessage());
    }
}

// 檢查權限：只有學校行政、管理員、主任或有權限的STAM可以訪問
if (!($is_admin || $is_staff || $is_director || ($is_stam && $stam_has_permission))) {
    header("Location: index.php");
    exit;
}

// 獲取主任的科系代碼
$user_department_code = null;
if ($is_director) {
    try {
        $conn_temp = getDatabaseConnection();
        // 優先從 director 表獲取部門代碼（主任）
        $table_check = $conn_temp->query("SHOW TABLES LIKE 'director'");
        if ($table_check && $table_check->num_rows > 0) {
            $stmt_dept = $conn_temp->prepare("SELECT department FROM director WHERE user_id = ?");
        } else {
            $stmt_dept = $conn_temp->prepare("SELECT department FROM teacher WHERE user_id = ?");
        }
        $stmt_dept->bind_param("i", $user_id);
        $stmt_dept->execute();
        $result_dept = $stmt_dept->get_result();
        if ($row = $result_dept->fetch_assoc()) {
            $user_department_code = $row['department'];
        }
        $stmt_dept->close();
        $conn_temp->close();
    } catch (Exception $e) {
        error_log('Error fetching director department: ' . $e->getMessage());
    }
}

// 設置頁面標題
$page_title = '教師活動紀錄';
if ($is_director && $user_department_code) {
    // 獲取科系名稱
    $conn_temp = getDatabaseConnection();
    $dept_stmt = $conn_temp->prepare("SELECT name FROM departments WHERE code = ?");
    $dept_stmt->bind_param("s", $user_department_code);
    $dept_stmt->execute();
    $dept_result = $dept_stmt->get_result();
    if ($dept_row = $dept_result->fetch_assoc()) {
        $page_title = $dept_row['name'] . ' - 教師活動紀錄';
    }
    $dept_stmt->close();
    $conn_temp->close();
}

// 建立資料庫連接
$conn = getDatabaseConnection();
if (!$conn) {
    die('資料庫連接失敗');
}

// 獲取搜尋參數
$search_keyword = $_GET['keyword'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;

// 構建查詢條件
$where_conditions = [];
$params = [];
$param_types = '';

if (!empty($search_keyword)) {
    $where_conditions[] = "(d.name LIKE ? OR d.code LIKE ?)";
    $keyword_param = "%{$search_keyword}%";
    $params[] = $keyword_param;
    $params[] = $keyword_param;
    $param_types .= 'ss';
}

// 如果是主任，只查詢該科系
if ($is_director && !empty($user_department_code)) {
    $where_conditions[] = "d.code = ?";
    $params[] = $user_department_code;
    $param_types .= 's';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// 獲取總數
$count_params = $params;
$count_param_types = $param_types;
$count_sql = "SELECT COUNT(*) as total FROM departments d 
              LEFT JOIN teacher t ON d.code = t.department
              LEFT JOIN activity_records ar ON t.user_id = ar.teacher_id
              $where_clause";
$count_stmt = $conn->prepare($count_sql);
if (!empty($count_params)) {
    $count_stmt->bind_param($count_param_types, ...$count_params);
}
$count_stmt->execute();
$total_result = $count_stmt->get_result();
$total_departments = $total_result->fetch_assoc()['total'] ?? 0;
$total_pages = ceil($total_departments / $per_page);

// 獲取科系列表及其活動紀錄總數
$offset = ($page - 1) * $per_page;
$dept_sql = "SELECT d.code, d.name, COUNT(ar.id) AS total_records
             FROM departments d
             LEFT JOIN teacher t ON d.code = t.department
             LEFT JOIN activity_records ar ON t.user_id = ar.teacher_id
             $where_clause
             GROUP BY d.code, d.name
             ORDER BY total_records DESC, d.name ASC
             LIMIT ? OFFSET ?";

// 為列表查詢添加 LIMIT 和 OFFSET 參數
$list_params = $params;
$list_params[] = $per_page;
$list_params[] = $offset;
$list_param_types = $param_types . 'ii';

$stmt = $conn->prepare($dept_sql);
if (!empty($list_param_types)) {
    $stmt->bind_param($list_param_types, ...$list_params);
}
$stmt->execute();
$result = $stmt->get_result();
$departments_with_records = [];
if ($result) {
    $departments_with_records = $result->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Topics 後台管理系統</title>
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
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: var(--background-color); color: var(--text-color); overflow-x: hidden; }
        .dashboard { display: flex; min-height: 100vh; }
        .main-content {
            overflow-x: hidden;
        }
        .content { padding: 24px; width: 100%; }

        .page-controls { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 16px; }
        .breadcrumb { margin-bottom: 0; font-size: 16px; color: var(--text-secondary-color); }
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        
        .table-wrapper {
            background: var(--card-background-color);
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }

        .table-container {
            overflow-x: auto;
        }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 16px 24px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 16px; white-space: nowrap; }
        .table th:first-child, .table td:first-child {
            padding-left: 60px;
        }
        .table th { background: #fafafa; font-weight: 600; color: #262626; cursor: pointer; user-select: none; position: relative; }
        .table td {
            color: #595959;
        }
        .table th:hover { background: #f0f0f0; }
        .table tr:hover { background: #fafafa; }
        
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
        }

        .badge-success {
            background: #28a745;
            color: white;
        }

        .badge-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-view {
            display: inline-block;
            padding: 6px 16px;
            background: #1890ff;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .btn-view:hover {
            background: #40a9ff;
        }

        .search-input {
            padding: 8px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            font-size: 14px;
            width: 250px;
            transition: all 0.3s;
        }
        .search-input:focus {
            outline: none;
            border-color: #1890ff;
            box-shadow: 0 0 0 2px rgba(24,144,255,0.2);
        }

        .empty-state { text-align: center; padding: 40px; color: var(--text-secondary-color); }
        .empty-state i { font-size: 48px; margin-bottom: 16px; color: #d9d9d9; }
        .empty-state h4 { margin-bottom: 8px; color: #595959; }

        /* 分頁 */
        .pagination {
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid var(--border-color);
            background: #fafafa;
        }

        .pagination-info {
            color: var(--text-secondary-color);
            font-size: 14px;
        }

        .pagination-controls {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .pagination button {
            padding: 6px 12px;
            border: 1px solid #d9d9d9;
            background: #fff;
            color: #595959;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }

        .pagination button:hover:not(:disabled) {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }

        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
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
                        <a href="index.php">首頁</a> / <?php echo $page_title; ?>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <input type="text" id="searchInput" class="search-input" placeholder="搜尋科系名稱...">
                    </div>
                </div>

                <div class="table-wrapper">
                    <div class="table-container">
                        <?php if (empty($departments_with_records)): ?>
                            <div class="empty-state">
                                <i class="fas fa-building fa-3x" style="margin-bottom: 16px;"></i>
                                <h4>目前沒有科系資料</h4>
                                <p>系統中尚未有科系資料</p>
                            </div>
                        <?php else: ?>
                            <table class="table" id="departmentTable">
                                <thead>
                                    <tr>
                                        <th>科系</th>
                                        <th style="text-align: center;">活動紀錄數</th>
                                        <th style="text-align: center;">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($departments_with_records as $dept): ?>
                                        <tr data-dept-code="<?php echo htmlspecialchars($dept['code']); ?>">
                                            <td><?php echo htmlspecialchars($dept['name']); ?></td>
                                            <td style="text-align: center;">
                                                <span class="badge <?php echo $dept['total_records'] > 0 ? 'badge-success' : 'badge-secondary'; ?>">
                                                    <?php echo $dept['total_records'] ?? 0; ?>
                                                </span>
                                            </td>
                                            <td style="text-align: center;">
                                                <a href="teacher_activity_records_detail.php?department=<?php echo urlencode($dept['code']); ?>" class="btn-view">
                                                    <i class="fas fa-eye"></i> 查看教師
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                    <!-- 分頁 -->
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <div class="pagination-info">
                            顯示第 <?php echo ($page - 1) * $per_page + 1; ?>-<?php echo min($page * $per_page, $total_departments); ?> 筆，共 <?php echo $total_departments; ?> 筆
                        </div>
                        <div class="pagination-controls">
                            <button onclick="goToPage(<?php echo max(1, $page - 1); ?>)" <?php echo $page <= 1 ? 'disabled' : ''; ?>>
                                <i class="fas fa-chevron-left"></i> 上一頁
                            </button>
                            <span style="padding: 6px 12px;">第 <?php echo $page; ?> / <?php echo $total_pages; ?> 頁</span>
                            <button onclick="goToPage(<?php echo min($total_pages, $page + 1); ?>)" <?php echo $page >= $total_pages ? 'disabled' : ''; ?>>
                                下一頁 <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        function goToPage(page) {
            const url = new URL(window.location.href);
            url.searchParams.set('page', page);
            window.location.href = url.toString();
        }

        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            const departmentTable = document.getElementById('departmentTable');
            
            if (searchInput) {
                searchInput.addEventListener('keyup', function() {
                    const filter = searchInput.value.toLowerCase();
                    
                    if (!departmentTable) return;
                    
                    const tbody = departmentTable.getElementsByTagName('tbody')[0];
                    if (!tbody) return;
                    
                    const rows = tbody.getElementsByTagName('tr');
                    
                    for (let i = 0; i < rows.length; i++) {
                        const cells = rows[i].getElementsByTagName('td');
                        let found = false;
                        
                        for (let j = 0; j < cells.length; j++) {
                            const cellText = cells[j].textContent || cells[j].innerText;
                            if (cellText.toLowerCase().indexOf(filter) > -1) {
                                found = true;
                                break;
                            }
                        }
                        
                        rows[i].style.display = found ? '' : 'none';
                    }
                });
            }
        });
    </script>
</body>
</html>
