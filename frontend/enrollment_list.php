<?php
session_start();

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 設置頁面標題
$page_title = (isset($_SESSION['username']) && $_SESSION['username'] === 'IMD') ? '資管科就讀意願名單' : '就讀意願名單';

// 建立資料庫連接
$conn = getDatabaseConnection();

// 檢查是否為IMD用戶
$is_imd_user = (isset($_SESSION['username']) && $_SESSION['username'] === 'IMD');

// 獲取報名資料（根據用戶權限過濾）
if ($is_imd_user) {
    // IMD用戶只能看到資管科相關的就讀意願
    $stmt = $conn->prepare("SELECT * FROM enrollment_intention 
                           WHERE intention1 LIKE '%資管%' OR intention1 LIKE '%資訊管理%' 
                           OR intention2 LIKE '%資管%' OR intention2 LIKE '%資訊管理%' 
                           OR intention3 LIKE '%資管%' OR intention3 LIKE '%資訊管理%'
                           ORDER BY created_at DESC");
} else {
    // 一般管理員可以看到所有就讀意願
    $stmt = $conn->prepare("SELECT * FROM enrollment_intention ORDER BY created_at DESC");
}
$stmt->execute();
$result = $stmt->get_result();
$enrollments = $result->fetch_all(MYSQLI_ASSOC);

// 如果是IMD用戶，獲取老師列表
$teachers = [];
if ($is_imd_user) {
    $teacher_stmt = $conn->prepare("
        SELECT u.id, u.username, t.name, t.department 
        FROM user u 
        LEFT JOIN teacher t ON u.id = t.user_id 
        WHERE u.role = '老師' 
        ORDER BY t.name ASC
    ");
    $teacher_stmt->execute();
    $teacher_result = $teacher_stmt->get_result();
    $teachers = $teacher_result->fetch_all(MYSQLI_ASSOC);
}

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
            /* 防止內部過寬的元素撐開主內容區，影響 header */
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
            /* overflow: hidden; */ /* 移除此行以允許內部容器的捲軸顯示 */
        }

        .table-container {
            overflow-x: auto;
        }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 16px 24px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 16px; white-space: nowrap; }
        .table th { background: #fafafa; font-weight: 600; color: #262626; cursor: pointer; user-select: none; position: relative; }
        .table td {
            color: #595959; /* 與 users.php 統一表格內文顏色 */
        }
        .table th:hover { background: #f0f0f0; }
        .sort-icon { margin-left: 8px; font-size: 12px; color: #8c8c8c; }
        .sort-icon.active { color: #1890ff; }
        .sort-icon.asc::after { content: "↑"; }
        .sort-icon.desc::after { content: "↓"; }
        .table tr:hover { background: #fafafa; }

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

        .assign-btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s;
        }
        .assign-btn:hover {
            background: #40a9ff;
            transform: translateY(-1px);
        }
        .assign-btn i {
            margin-right: 4px;
        }

        /* 彈出視窗樣式 */
        .modal {
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            width: 90%;
            max-width: 500px;
            max-height: 80vh;
            overflow-y: auto;
        }
        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 {
            margin: 0;
            color: var(--text-color);
        }
        .close {
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            color: var(--text-secondary-color);
        }
        .close:hover {
            color: var(--text-color);
        }
        .modal-body {
            padding: 20px;
        }
        .modal-body p {
            margin-bottom: 16px;
            font-size: 16px;
        }
        .teacher-list h4 {
            margin-bottom: 12px;
            color: var(--text-color);
        }
        .teacher-options {
            max-height: 300px;
            overflow-y: auto;
        }
        .teacher-option {
            display: block;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .teacher-option:hover {
            background-color: #f5f5f5;
            border-color: var(--primary-color);
        }
        .teacher-option input[type="radio"] {
            margin-right: 12px;
        }
        .teacher-info {
            display: inline-block;
            vertical-align: top;
        }
        .teacher-info strong {
            display: block;
            color: var(--text-color);
            margin-bottom: 4px;
        }
        .teacher-dept {
            color: var(--text-secondary-color);
            font-size: 14px;
        }
        .modal-footer {
            padding: 20px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        .btn-cancel, .btn-confirm {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }
        .btn-cancel {
            background-color: #f5f5f5;
            color: var(--text-color);
        }
        .btn-cancel:hover {
            background-color: #e8e8e8;
        }
        .btn-confirm {
            background-color: var(--primary-color);
            color: white;
        }
        .btn-confirm:hover {
            background-color: #40a9ff;
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
                    <div class="table-search">
                        <input type="text" id="searchInput" class="search-input" placeholder="搜尋姓名或電話...">
                    </div>
                </div>

                <div class="table-wrapper">
                    <div class="table-container">
                        <?php if (empty($enrollments)): ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                                <p>目前尚無任何就讀意願登錄資料。</p>
                            </div>
                        <?php else: ?>
                            <table class="table" id="enrollmentTable">
                                <thead>
                                    <tr>
                                        <th onclick="sortTable(0)">姓名</th>
                                        <th onclick="sortTable(1)">身分別</th>
                                        <th onclick="sortTable(2)">性別</th>
                                        <th onclick="sortTable(3)">聯絡電話一</th>
                                        <th onclick="sortTable(4)">聯絡電話二</th>
                                        <th onclick="sortTable(5)">Email</th>
                                        <th onclick="sortTable(6)">就讀學校</th>
                                        <th onclick="sortTable(7)">年級</th>
                                        <th onclick="sortTable(8)">意願一 (學制)</th>
                                        <th onclick="sortTable(9)">意願二 (學制)</th>
                                        <th onclick="sortTable(10)">意願三 (學制)</th>
                                        <th onclick="sortTable(11)">Line ID</th>
                                        <th onclick="sortTable(12)">Facebook</th>
                                        <th onclick="sortTable(13)">備註</th>
                                        <th onclick="sortTable(14)">狀態</th>
                                        <th onclick="sortTable(15, 'date')">填寫日期</th>
                                        <?php if ($is_imd_user): ?>
                                        <th>操作</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($enrollments as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['name']); ?></td>
                                        <td><?php echo htmlspecialchars($item['identity']); ?></td>
                                        <td><?php echo htmlspecialchars($item['gender'] ?? '未提供'); ?></td>
                                        <td><?php echo htmlspecialchars($item['phone1']); ?></td>
                                        <td><?php echo htmlspecialchars($item['phone2'] ?? '無'); ?></td>
                                        <td><?php echo htmlspecialchars($item['email']); ?></td>
                                        <td><?php echo htmlspecialchars($item['junior_high']); ?></td>
                                        <td><?php echo htmlspecialchars($item['current_grade']); ?></td>
                                        <td><?php echo htmlspecialchars($item['intention1'] . ' (' . ($item['system1'] ?? 'N/A') . ')'); ?></td>
                                        <td><?php echo htmlspecialchars($item['intention2'] . ' (' . ($item['system2'] ?? 'N/A') . ')'); ?></td>
                                        <td><?php echo htmlspecialchars($item['intention3'] . ' (' . ($item['system3'] ?? 'N/A') . ')'); ?></td>
                                        <td><?php echo htmlspecialchars($item['line_id'] ?? '無'); ?></td>
                                        <td><?php echo htmlspecialchars($item['facebook'] ?? '無'); ?></td>
                                        <td><?php echo htmlspecialchars($item['remarks'] ?? '無'); ?></td>
                                        <td><?php echo htmlspecialchars($item['status'] ?? 'pending'); ?></td>
                                        <td><?php echo date('Y/m/d H:i', strtotime($item['created_at'])); ?></td>
                                        <?php if ($is_imd_user): ?>
                                        <td>
                                            <button class="assign-btn" onclick="openAssignModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['name']); ?>')">
                                                <i class="fas fa-user-plus"></i> 分配
                                            </button>
                                        </td>
                                        <?php endif; ?>
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

    <!-- 分配學生彈出視窗 -->
    <?php if ($is_imd_user): ?>
    <div id="assignModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>分配學生</h3>
                <span class="close" onclick="closeAssignModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p>學生：<span id="studentName"></span></p>
                <div class="teacher-list">
                    <h4>選擇老師：</h4>
                    <div class="teacher-options">
                        <?php foreach ($teachers as $teacher): ?>
                        <label class="teacher-option">
                            <input type="radio" name="teacher" value="<?php echo $teacher['id']; ?>">
                            <div class="teacher-info">
                                <strong><?php echo htmlspecialchars($teacher['name'] ?? $teacher['username']); ?></strong>
                                <span class="teacher-dept"><?php echo htmlspecialchars($teacher['department'] ?? '未設定科系'); ?></span>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel" onclick="closeAssignModal()">取消</button>
                <button class="btn-confirm" onclick="assignStudent()">確認分配</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        let sortStates = {}; // { colIndex: 'asc' | 'desc' }

        const searchInput = document.getElementById('searchInput');
        const table = document.getElementById('enrollmentTable');
        const rows = table ? table.getElementsByTagName('tbody')[0].getElementsByTagName('tr') : [];

        if (searchInput) {
            searchInput.addEventListener('keyup', function() {
                const filter = searchInput.value.toLowerCase();
                
                for (let i = 0; i < rows.length; i++) {
                    const nameCell = rows[i].getElementsByTagName('td')[0];
                    const phoneCell = rows[i].getElementsByTagName('td')[2];
                    
                    if (nameCell || phoneCell) {
                        const nameText = nameCell.textContent || nameCell.innerText;
                        const phoneText = phoneCell.textContent || phoneCell.innerText;
                        
                        if (nameText.toLowerCase().indexOf(filter) > -1 || phoneText.toLowerCase().indexOf(filter) > -1) {
                            rows[i].style.display = "";
                        } else {
                            rows[i].style.display = "none";
                        }
                    }
                }
            });
        }

        window.sortTable = function(colIndex, type = 'string') {
            const table = document.getElementById('enrollmentTable');
            const tbody = table.getElementsByTagName('tbody')[0];
            const rows = Array.from(tbody.getElementsByTagName('tr'));
            
            const currentOrder = sortStates[colIndex] === 'asc' ? 'desc' : 'asc';
            sortStates = { [colIndex]: currentOrder }; // Reset other column states

            rows.sort((a, b) => {
                const valA = a.getElementsByTagName('td')[colIndex].textContent.trim();
                const valB = b.getElementsByTagName('td')[colIndex].textContent.trim();

                let comparison = 0;
                if (type === 'date') {
                    comparison = new Date(valA) - new Date(valB);
                } else if (!isNaN(valA) && !isNaN(valB)) {
                    comparison = parseFloat(valA) - parseFloat(valB);
                } else {
                    comparison = valA.localeCompare(valB, 'zh-Hant');
                }

                return currentOrder === 'asc' ? comparison : -comparison;
            });

            // Re-append sorted rows
            rows.forEach(row => tbody.appendChild(row));

            // Update sort icons
            updateSortIcons(colIndex, currentOrder);
        };

        function updateSortIcons(activeIndex, order) {
            const headers = document.querySelectorAll('#enrollmentTable th');
            headers.forEach((th, index) => {
                const icon = th.querySelector('.sort-icon');
                if (icon) {
                    if (index === activeIndex) {
                        icon.className = `sort-icon active ${order}`;
                    } else {
                        icon.className = 'sort-icon';
                    }
                }
            });
        }

        // Initial sort by date desc
        function initialSort() {
            const dateColumnIndex = 15;
            sortStates = { [dateColumnIndex]: 'desc' };
            sortTable(dateColumnIndex, 'date'); // Sort once to set desc
            sortTable(dateColumnIndex, 'date'); // Sort again to trigger desc
        }

        if (rows.length > 0) {
            initialSort();
        }
    });

    // 分配學生相關變數
    let currentStudentId = null;

    // 開啟分配學生彈出視窗
    function openAssignModal(studentId, studentName) {
        currentStudentId = studentId;
        document.getElementById('studentName').textContent = studentName;
        document.getElementById('assignModal').style.display = 'flex';
        
        // 清除之前的選擇
        const radioButtons = document.querySelectorAll('input[name="teacher"]');
        radioButtons.forEach(radio => radio.checked = false);
    }

    // 關閉分配學生彈出視窗
    function closeAssignModal() {
        document.getElementById('assignModal').style.display = 'none';
        currentStudentId = null;
    }

    // 分配學生
    function assignStudent() {
        const selectedTeacher = document.querySelector('input[name="teacher"]:checked');
        
        if (!selectedTeacher) {
            alert('請選擇一位老師');
            return;
        }

        const teacherId = selectedTeacher.value;
        
        // 發送AJAX請求
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'assign_student.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            alert('學生分配成功！');
                            closeAssignModal();
                            // 可以選擇重新載入頁面或更新UI
                            location.reload();
                        } else {
                            alert('分配失敗：' + (response.message || '未知錯誤'));
                        }
                    } catch (e) {
                        alert('回應格式錯誤：' + xhr.responseText);
                    }
                } else {
                    alert('請求失敗，狀態碼：' + xhr.status);
                }
            }
        };
        
        xhr.send('student_id=' + encodeURIComponent(currentStudentId) + 
                 '&teacher_id=' + encodeURIComponent(teacherId));
    }

    // 點擊彈出視窗外部關閉
    document.getElementById('assignModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeAssignModal();
        }
    });
    </script>
</body>
</html>