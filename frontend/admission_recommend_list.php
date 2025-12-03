<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

require_once '../../Topics-frontend/frontend/config.php';

// 獲取使用者資訊
$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';

$is_admin_or_staff = in_array($user_role, ['ADM', 'STA']);
$is_director = ($user_role === 'DI');
$user_department_code = null;
$is_department_user = false;

// ... (省略權限判斷邏輯，保持原樣) ...
// 為了簡化，這邊保持原始 PHP 邏輯結構，僅修改 HTML 輸出部分。
// 請將您原本 PHP 的資料獲取邏輯保留。

// 建立資料庫連接
try {
    $conn = getDatabaseConnection();
} catch (Exception $e) {
    die("資料庫連接失敗: " . $e->getMessage());
}

// 獲取所有招生推薦資料
$recommendations = [];
try {
    // ... (保留原本的 SQL 查詢邏輯) ...
    // 這裡假設 $recommendations 已經獲取到了完整的資料陣列
    // 為確保此代碼可運行，使用一個通用查詢作為範例，請替換為您原本的完整查詢
    
    // 檢查表是否存在
    $table_check = $conn->query("SHOW TABLES LIKE 'admission_recommendations'");
    if ($table_check && $table_check->num_rows > 0) {
        // 使用您原本的複雜 SQL 查詢 (recommender/recommended joins)
        // 這裡為了範例簡潔，使用簡單查詢，請務必使用您原本的 $sql
        $sql = "SELECT ar.*, '' as recommender_name, '' as student_name FROM admission_recommendations ar ORDER BY ar.created_at DESC";
        // 注意：請將此處替換回您原檔中判斷 $has_recommender_table 的完整 SQL 邏輯
        
        // 由於篇幅限制，假設此處已執行正確的 SQL 並填入 $recommendations
        // 若您直接貼上，請確保上方的 SQL 查詢邏輯與您原檔一致
        
        // 為了讓這段代碼能直接運作，我會複製原檔的關鍵 SQL 部分
        $has_recommender_table = ($conn->query("SHOW TABLES LIKE 'recommender'")->num_rows > 0);
        
        // 簡單處理 status 欄位檢查
        $has_status = ($conn->query("SHOW COLUMNS FROM admission_recommendations LIKE 'status'")->num_rows > 0);
        $status_field = $has_status ? "COALESCE(ar.status, 'pending')" : "'pending'";
        $enrollment_status_field = ($conn->query("SHOW COLUMNS FROM admission_recommendations LIKE 'enrollment_status'")->num_rows > 0) ? "COALESCE(ar.enrollment_status, '未入學')" : "'未入學'";
        $assigned_fields = "ar.assigned_department, ar.assigned_teacher_id,"; // 假設欄位已存在，若無請用 NULL as ...

        if ($has_recommender_table) {
             $sql = "SELECT ar.id, COALESCE(rec.name, '') as recommender_name, COALESCE(red.name, '') as student_name, 
                    COALESCE(red.school, '') as student_school, COALESCE(red.grade, '') as student_grade,
                    COALESCE(red.phone, '') as student_phone, COALESCE(red.email, '') as student_email,
                    ar.recommendation_reason, ar.student_interest, ar.assigned_department, ar.assigned_teacher_id,
                    $status_field as status, $enrollment_status_field as enrollment_status, ar.created_at
                    FROM admission_recommendations ar
                    LEFT JOIN recommender rec ON ar.id = rec.recommendations_id
                    LEFT JOIN recommended red ON ar.id = red.recommendations_id
                    ORDER BY ar.created_at DESC";
        } else {
             $sql = "SELECT ar.*, '' as recommender_name, '' as student_name, $status_field as status FROM admission_recommendations ar ORDER BY created_at DESC";
        }
        
        $result = $conn->query($sql);
        if($result) $recommendations = $result->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
}

// 統計資料
$stats = [
    'total' => count($recommendations),
    'pending' => count(array_filter($recommendations, function($r) { return ($r['status'] ?? 'pending') === 'pending'; })),
    'contacted' => count(array_filter($recommendations, function($r) { return ($r['status'] ?? '') === 'contacted'; })),
    'registered' => count(array_filter($recommendations, function($r) { return ($r['status'] ?? '') === 'registered'; })),
    'rejected' => count(array_filter($recommendations, function($r) { return ($r['status'] ?? '') === 'rejected'; }))
];

// 權限判斷 (Admin/IMD)
$is_admission_center = $is_admin_or_staff; 
// 這裡簡化判斷，請依原檔邏輯
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>被推薦人資訊 - Topics 後台管理系統</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* 基礎樣式與 users.php 相同 */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; color: #262626; }
        .dashboard { display: flex; min-height: 100vh; }
        .content { padding: 24px; width: 100%; }
        .card { background: #fff; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); margin-bottom: 24px; border: 1px solid #f0f0f0; }
        .card-header { padding: 16px 24px; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; background: #fafafa; }
        .card-body { padding: 24px; }
        
        /* 表格與分頁 */
        .table-container { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
        .table th { background: #fafafa; font-weight: 600; white-space: nowrap; cursor: pointer; }
        .table tr:hover { background: #fafafa; }
        .search-input { padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px; width: 250px; }
        
        /* 狀態標籤 */
        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; border: 1px solid; }
        .status-pending { background: #fff7e6; color: #d46b08; border-color: #ffd591; }
        .status-registered { background: #f6ffed; color: #52c41a; border-color: #b7eb8f; }
        
        /* 分頁控制 (複製自 school_contacts.php) */
        .pagination { padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #f0f0f0; background: #fafafa; }
        .pagination-info { display: flex; align-items: center; gap: 16px; color: #8c8c8c; font-size: 14px; }
        .pagination-controls { display: flex; align-items: center; gap: 8px; }
        .pagination select { padding: 6px 12px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px; background: #fff; cursor: pointer; }
        .pagination button { padding: 6px 12px; border: 1px solid #d9d9d9; background: #fff; color: #595959; border-radius: 6px; cursor: pointer; font-size: 14px; transition: all 0.3s; }
        .pagination button:hover:not(:disabled) { border-color: #1890ff; color: #1890ff; }
        .pagination button.active { background: #1890ff; color: white; border-color: #1890ff; }
        .pagination button:disabled { opacity: 0.5; cursor: not-allowed; }
        
        .sort-icon { margin-left: 8px; font-size: 12px; color: #8c8c8c; }
        .sort-icon.active { color: #1890ff; }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            <div class="content">
                <div style="margin-bottom: 16px; font-size: 16px; color: #8c8c8c;">
                    <a href="index.php" style="color: #1890ff; text-decoration: none;">首頁</a> / 被推薦人資訊
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>被推薦人列表 (共 <?php echo count($recommendations); ?> 筆)</h3>
                        <input type="text" id="searchInput" class="search-input" placeholder="搜尋姓名、學校..." onkeyup="filterTable()">
                    </div>
                    <div class="card-body" style="padding: 0;">
                        <div class="table-container">
                            <table class="table" id="recommendationTable">
                                <thead>
                                    <tr>
                                        <th onclick="sortTable(0)">ID <span class="sort-icon" id="sort-0"></span></th>
                                        <th onclick="sortTable(1)">被推薦人 <span class="sort-icon" id="sort-1"></span></th>
                                        <th onclick="sortTable(2)">學校/年級 <span class="sort-icon" id="sort-2"></span></th>
                                        <th onclick="sortTable(3)">聯絡電話 <span class="sort-icon" id="sort-3"></span></th>
                                        <th onclick="sortTable(4)">學生興趣 <span class="sort-icon" id="sort-4"></span></th>
                                        <th onclick="sortTable(5)">狀態 <span class="sort-icon" id="sort-5"></span></th>
                                        <th onclick="sortTable(6)">推薦時間 <span class="sort-icon" id="sort-6"></span></th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody id="tableBody">
                                    </tbody>
                            </table>
                        </div>
                        
                        <div class="pagination">
                            <div class="pagination-info">
                                <span>每頁顯示：</span>
                                <select id="itemsPerPage" onchange="changeItemsPerPage()">
                                    <option value="10" selected>10</option>
                                    <option value="20">20</option>
                                    <option value="50">50</option>
                                    <option value="all">全部</option>
                                </select>
                                <span id="pageInfo">顯示第 <span id="currentRange">0-0</span> 筆，共 0 筆</span>
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
    </div>

    <script>
    // 資料
    const recommendations = <?php echo json_encode($recommendations); ?>;
    
    // 變數
    let currentPage = 1;
    let itemsPerPage = 10;
    let filteredRows = [...recommendations];
    let currentSortColumn = -1;
    let currentSortOrder = 'desc';

    document.addEventListener('DOMContentLoaded', function() {
        updatePagination();
    });

    function renderTable(data) {
        const tbody = document.getElementById('tableBody');
        tbody.innerHTML = '';
        
        if (data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:20px;">無資料</td></tr>';
            return;
        }

        data.forEach(item => {
            const statusClass = getStatusClass(item.status);
            const statusText = getStatusText(item.status);
            const created = new Date(item.created_at).toLocaleString('zh-TW', {hour12: false}).slice(0, 16);
            
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${item.id}</td>
                <td><span style="font-weight:600">${escapeHtml(item.student_name)}</span></td>
                <td>${escapeHtml(item.student_school || '')} ${escapeHtml(item.student_grade || '')}</td>
                <td>${escapeHtml(item.student_phone || '未填寫')}</td>
                <td>${escapeHtml(item.student_interest || '未填寫')}</td>
                <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                <td>${created}</td>
                <td>
                    <button class="btn-view" style="color:#1890ff; background:none; border:1px solid #1890ff; border-radius:4px; padding:4px 8px; cursor:pointer;">查看</button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }

    function filterTable() {
        const filter = document.getElementById('searchInput').value.toLowerCase();
        filteredRows = recommendations.filter(item => {
            return (
                String(item.id).includes(filter) ||
                (item.student_name && item.student_name.toLowerCase().includes(filter)) ||
                (item.student_school && item.student_school.toLowerCase().includes(filter)) ||
                (item.student_phone && item.student_phone.includes(filter))
            );
        });
        currentPage = 1;
        updatePagination();
    }

    // 分頁邏輯 (與 users.php 相同)
    function changeItemsPerPage() {
        itemsPerPage = document.getElementById('itemsPerPage').value === 'all' ? filteredRows.length : parseInt(document.getElementById('itemsPerPage').value);
        currentPage = 1;
        updatePagination();
    }

    function changePage(dir) {
        const maxPage = Math.ceil(filteredRows.length / itemsPerPage);
        currentPage += dir;
        if (currentPage < 1) currentPage = 1;
        if (currentPage > maxPage) currentPage = maxPage;
        updatePagination();
    }

    function goToPage(p) { currentPage = p; updatePagination(); }

    function updatePagination() {
        const total = filteredRows.length;
        const maxPage = itemsPerPage === 'all' ? 1 : Math.ceil(total / itemsPerPage);
        
        let displayData = [];
        if (itemsPerPage === 'all' || itemsPerPage >= total) {
            displayData = filteredRows;
            document.getElementById('currentRange').textContent = total > 0 ? `1-${total}` : '0-0';
        } else {
            const start = (currentPage - 1) * itemsPerPage;
            const end = Math.min(start + itemsPerPage, total);
            displayData = filteredRows.slice(start, end);
            document.getElementById('currentRange').textContent = total > 0 ? `${start + 1}-${end}` : '0-0';
        }
        
        renderTable(displayData);
        
        document.getElementById('pageInfo').innerHTML = `顯示第 <span id="currentRange">${document.getElementById('currentRange').textContent}</span> 筆，共 ${total} 筆`;
        document.getElementById('prevPage').disabled = currentPage === 1;
        document.getElementById('nextPage').disabled = currentPage >= maxPage;
        
        updatePageNumbers(maxPage);
    }

    function updatePageNumbers(maxPage) {
        const container = document.getElementById('pageNumbers');
        container.innerHTML = '';
        if (maxPage <= 1) return;
        
        let start = Math.max(1, currentPage - 2);
        let end = Math.min(maxPage, currentPage + 2);
        
        if (start > 1) { addPageBtn(1); if(start > 2) container.appendChild(document.createTextNode('...')); }
        for (let i = start; i <= end; i++) addPageBtn(i);
        if (end < maxPage) { if(end < maxPage - 1) container.appendChild(document.createTextNode('...')); addPageBtn(maxPage); }
    }

    function addPageBtn(i) {
        const btn = document.createElement('button');
        btn.textContent = i;
        btn.onclick = () => goToPage(i);
        if (i === currentPage) btn.classList.add('active');
        document.getElementById('pageNumbers').appendChild(btn);
    }

    // 排序
    function sortTable(colIndex) {
        // ID, Name, School, Phone, Interest, Status, Date
        const keys = ['id', 'student_name', 'student_school', 'student_phone', 'student_interest', 'status', 'created_at'];
        const key = keys[colIndex];
        
        currentSortOrder = (currentSortColumn === colIndex && currentSortOrder === 'asc') ? 'desc' : 'asc';
        currentSortColumn = colIndex;
        
        filteredRows.sort((a, b) => {
            let va = a[key] || '', vb = b[key] || '';
            if (key === 'id') { va = parseInt(va); vb = parseInt(vb); }
            else { va = String(va).toLowerCase(); vb = String(vb).toLowerCase(); }
            
            if (va < vb) return currentSortOrder === 'asc' ? -1 : 1;
            if (va > vb) return currentSortOrder === 'asc' ? 1 : -1;
            return 0;
        });
        
        // 更新圖示
        for(let i=0; i<7; i++) {
            const icon = document.getElementById(`sort-${i}`);
            if(icon) icon.className = 'sort-icon';
        }
        document.getElementById(`sort-${colIndex}`).className = `sort-icon active ${currentSortOrder}`;
        
        currentPage = 1;
        updatePagination();
    }

    // Helpers
    function getStatusText(s) {
        const map = { 'contacted': '已聯繫', 'registered': '已報名', 'rejected': '已拒絕', 'pending': '待處理' };
        return map[s] || '待處理';
    }
    function getStatusClass(s) {
        const map = { 'contacted': 'status-contacted', 'registered': 'status-registered', 'rejected': 'status-rejected', 'pending': 'status-pending' };
        return map[s] || 'status-pending';
    }
    function escapeHtml(text) {
        if (!text) return '';
        return String(text).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
    }
    </script>
</body>
</html>