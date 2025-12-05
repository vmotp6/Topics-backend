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

// 檢查權限：只有學校行政、管理員和主任可以訪問
if (!($is_admin || $is_staff || $is_director)) {
    header("Location: index.php");
    exit;
}

// 獲取科系代碼參數
$department_code = $_GET['department'] ?? '';

if (empty($department_code)) {
    header("Location: teacher_activity_records.php");
    exit;
}

// 獲取教師ID參數（用於顯示該教師的活動紀錄）
$selected_teacher_id = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;

// 獲取主任的科系代碼
$user_department_code = null;
if ($is_director) {
    try {
        $conn_temp = getDatabaseConnection();
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
    
    // 主任只能查看自己科系的教師
    if ($user_department_code !== $department_code) {
        header("Location: teacher_activity_records.php");
        exit;
    }
}

// 建立資料庫連接
$conn = getDatabaseConnection();
if (!$conn) {
    die('資料庫連接失敗');
}

// 獲取科系名稱
$department_name = '';
$dept_stmt = $conn->prepare("SELECT name FROM departments WHERE code = ?");
$dept_stmt->bind_param("s", $department_code);
$dept_stmt->execute();
$dept_result = $dept_stmt->get_result();
if ($dept_row = $dept_result->fetch_assoc()) {
    $department_name = $dept_row['name'];
}
$dept_stmt->close();

// 查詢該科系有活動紀錄的教師列表及其活動紀錄數（只顯示有填寫活動紀錄的教師）
$teachers_with_records = [];
$teachers_sql = "SELECT t.user_id, u.name AS teacher_name, t.department AS teacher_department, 
                        d.name AS department_name, COUNT(ar.id) AS record_count
                 FROM teacher t
                 INNER JOIN activity_records ar ON t.user_id = ar.teacher_id
                 LEFT JOIN user u ON t.user_id = u.id
                 LEFT JOIN departments d ON t.department = d.code
                 WHERE t.department = ?
                 GROUP BY t.user_id, u.name, t.department, d.name
                 ORDER BY record_count DESC, u.name ASC";
$stmt = $conn->prepare($teachers_sql);
$stmt->bind_param("s", $department_code);
$stmt->execute();
$result = $stmt->get_result();
if ($result) {
    $teachers_with_records = $result->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();

// 如果選擇了教師，查詢該教師的活動紀錄
$selected_teacher_records = [];
$selected_teacher_name = '';
if ($selected_teacher_id > 0) {
    // 驗證該教師是否屬於該科系
    $teacher_check = false;
    foreach ($teachers_with_records as $teacher) {
        if ($teacher['user_id'] == $selected_teacher_id) {
            $teacher_check = true;
            $selected_teacher_name = $teacher['teacher_name'];
            break;
        }
    }
    
    if ($teacher_check) {
        $records_sql = "SELECT ar.*, at.name AS activity_type_name, sd.name AS school_name
                       FROM activity_records ar
                       LEFT JOIN activity_types at ON ar.activity_type = at.ID
                       LEFT JOIN school_data sd ON ar.school = sd.school_code
                       WHERE ar.teacher_id = ?
                       ORDER BY ar.activity_date DESC, ar.id DESC";
        $records_stmt = $conn->prepare($records_sql);
        $records_stmt->bind_param("i", $selected_teacher_id);
        $records_stmt->execute();
        $records_result = $records_stmt->get_result();
        if ($records_result) {
            $selected_teacher_records = $records_result->fetch_all(MYSQLI_ASSOC);
        }
        $records_stmt->close();
    }
}

$conn->close();

// 設置頁面標題
$page_title = $department_name . ' - 教師活動紀錄';
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
            border: none;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-view:hover {
            background: #40a9ff;
        }

        .btn-back {
            display: inline-block;
            padding: 6px 16px;
            background: #f5f5f5;
            color: #262626;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
            transition: all 0.3s;
            margin-right: 12px;
        }

        .btn-back:hover {
            background: #e8e8e8;
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
        
        /* 詳情行樣式 */
        .detail-row {
            background: #f9f9f9;
        }
        .detail-row td {
            padding: 20px;
            background: #f9f9f9;
            border: 2px solid #b3d9ff;
            border-radius: 4px;
        }
        
        /* 活動紀錄表格樣式 */
        .activity-records-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            margin-top: 10px;
        }
        .activity-records-table th,
        .activity-records-table td {
            padding: 10px;
            border: 1px solid #d9d9d9;
            text-align: left;
        }
        .activity-records-table th {
            background: #e6f7ff;
            font-weight: 600;
        }
        .activity-records-table tr:hover {
            background: #f0f8ff;
        }
        
        /* 活動紀錄詳情展開行 */
        .activity-detail-row {
            background: #f5f5f5;
        }
        .activity-detail-row td {
            padding: 15px;
            background: #f5f5f5;
            border-top: 2px solid #91d5ff;
        }
        .detail-info-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        .detail-info-table td {
            padding: 8px;
            border: 1px solid #ddd;
        }
        .detail-info-table td:first-child {
            background: #f5f5f5;
            font-weight: 600;
            width: 150px;
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
                        <a href="index.php">首頁</a> / <a href="teacher_activity_records.php">教師活動紀錄</a> / <?php echo htmlspecialchars($department_name); ?>
                    </div>
                    <div class="table-search">
                        <input type="text" id="searchInput" class="search-input" placeholder="搜尋教師姓名...">
                    </div>
                </div>

                <div class="table-wrapper">
                    <div class="table-container">
                        <?php if (empty($teachers_with_records)): ?>
                            <div class="empty-state">
                                <i class="fas fa-users fa-3x" style="margin-bottom: 16px;"></i>
                                <h4>此科系目前沒有教師活動紀錄</h4>
                                <p>系統中尚未有教師填寫活動紀錄</p>
                            </div>
                        <?php else: ?>
                            <table class="table" id="teacherTable">
                                <thead>
                                    <tr>
                                        <th>教師姓名</th>
                                        <th style="text-align: center;">活動紀錄數</th>
                                        <th style="text-align: center;">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($teachers_with_records as $teacher): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($teacher['teacher_name'] ?? '未設定'); ?></td>
                                            <td style="text-align: center;">
                                                <span class="badge badge-success">
                                                    <?php echo $teacher['record_count'] ?? 0; ?>
                                                </span>
                                            </td>
                                            <td style="text-align: center;">
                                                <button type="button" class="btn-view" id="detail-btn-<?php echo $teacher['user_id']; ?>" onclick="toggleTeacherRecords(<?php echo $teacher['user_id']; ?>)">
                                                    <i class="fas fa-eye"></i> <span class="btn-text">查看紀錄</span>
                                                </button>
                                            </td>
                                        </tr>
                                        <!-- 活動紀錄詳情行（初始隱藏） -->
                                        <tr id="detail-<?php echo $teacher['user_id']; ?>" class="detail-row" style="display: none;">
                                            <td colspan="3">
                                                <div id="records-content-<?php echo $teacher['user_id']; ?>">
                                                    <p style="text-align: center; padding: 20px; color: #8c8c8c;">載入中...</p>
                                                </div>
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

    <script>
        let currentOpenTeacherId = null;
        let loadedTeachers = new Set();
        
        // 切換教師活動紀錄的展開/收合
        function toggleTeacherRecords(teacherId) {
            const detailRow = document.getElementById('detail-' + teacherId);
            const detailBtn = document.getElementById('detail-btn-' + teacherId);
            const btnText = detailBtn ? detailBtn.querySelector('.btn-text') : null;
            
            if (!detailRow) return;
            
            // 如果點擊的是當前已打開的詳情，則關閉它
            if (currentOpenTeacherId === teacherId) {
                detailRow.style.display = 'none';
                currentOpenTeacherId = null;
                if (btnText) {
                    btnText.textContent = '查看紀錄';
                }
                if (detailBtn) {
                    const icon = detailBtn.querySelector('i');
                    if (icon) {
                        icon.className = 'fas fa-eye';
                    }
                }
                return;
            }
            
            // 如果已經有其他詳情打開，先關閉它
            if (currentOpenTeacherId !== null) {
                const previousDetailRow = document.getElementById('detail-' + currentOpenTeacherId);
                const previousDetailBtn = document.getElementById('detail-btn-' + currentOpenTeacherId);
                const previousBtnText = previousDetailBtn ? previousDetailBtn.querySelector('.btn-text') : null;
                
                if (previousDetailRow) {
                    previousDetailRow.style.display = 'none';
                }
                if (previousBtnText) {
                    previousBtnText.textContent = '查看紀錄';
                }
                if (previousDetailBtn) {
                    const icon = previousDetailBtn.querySelector('i');
                    if (icon) {
                        icon.className = 'fas fa-eye';
                    }
                }
            }
            
            // 打開新的詳情
            detailRow.style.display = 'table-row';
            currentOpenTeacherId = teacherId;
            if (btnText) {
                btnText.textContent = '關閉紀錄';
            }
            if (detailBtn) {
                const icon = detailBtn.querySelector('i');
                if (icon) {
                    icon.className = 'fas fa-eye-slash';
                }
            }
            
            // 如果還沒有載入過該教師的紀錄，則載入
            if (!loadedTeachers.has(teacherId)) {
                loadTeacherRecords(teacherId);
            }
        }
        
        // 載入教師的活動紀錄
        function loadTeacherRecords(teacherId) {
            const contentDiv = document.getElementById('records-content-' + teacherId);
            if (!contentDiv) return;
            
            // 顯示載入中
            contentDiv.innerHTML = '<p style="text-align: center; padding: 20px; color: #8c8c8c;">載入中...</p>';
            
            // 使用 AJAX 載入活動紀錄
            const xhr = new XMLHttpRequest();
            xhr.open('GET', 'get_teacher_activity_records.php?teacher_id=' + teacherId, true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                displayTeacherRecords(teacherId, response.records, response.teacher_name);
                                loadedTeachers.add(teacherId);
                            } else {
                                contentDiv.innerHTML = '<p style="text-align: center; padding: 20px; color: #dc3545;">載入失敗：' + (response.message || '未知錯誤') + '</p>';
                            }
                        } catch (e) {
                            contentDiv.innerHTML = '<p style="text-align: center; padding: 20px; color: #dc3545;">解析回應失敗</p>';
                        }
                    } else {
                        contentDiv.innerHTML = '<p style="text-align: center; padding: 20px; color: #dc3545;">載入失敗</p>';
                    }
                }
            };
            xhr.send();
        }
        
        // 顯示教師的活動紀錄
        function displayTeacherRecords(teacherId, records, teacherName) {
            const contentDiv = document.getElementById('records-content-' + teacherId);
            if (!contentDiv) return;
            
            if (!records || records.length === 0) {
                contentDiv.innerHTML = '<p style="text-align: center; padding: 20px; color: #8c8c8c;">尚無活動紀錄</p>';
                return;
            }
            
            let html = '<h4 style="margin: 0 0 15px 0; font-size: 16px; color: #1890ff;"><i class="fas fa-list"></i> ' + escapeHtml(teacherName) + ' 的活動紀錄</h4>';
            html += '<table class="activity-records-table">';
            html += '<thead><tr>';
            html += '<th>活動日期</th>';
            html += '<th>活動類型</th>';
            html += '<th>學校</th>';
            html += '<th>活動時間</th>';
            html += '<th>操作</th>';
            html += '</tr></thead>';
            html += '<tbody>';
            
            records.forEach(function(record, index) {
                const recordId = 'record-' + teacherId + '-' + index;
                html += '<tr onclick="toggleRecordDetail(\'' + recordId + '\')" style="cursor: pointer;">';
                html += '<td>' + escapeHtml(record.activity_date || '未填寫') + '</td>';
                html += '<td>' + escapeHtml(record.activity_type_name || '未填寫') + '</td>';
                html += '<td>' + escapeHtml(record.school_name || record.school || '未填寫') + '</td>';
                html += '<td>' + (record.activity_time == 1 ? '上班日' : record.activity_time == 2 ? '假日' : '未填寫') + '</td>';
                html += '<td>';
                html += '<button type="button" class="btn-view" id="record-detail-btn-' + recordId + '" onclick="event.stopPropagation(); toggleRecordDetail(\'' + recordId + '\')">';
                html += '<i class="fas fa-eye"></i> <span class="btn-text">查看詳情</span>';
                html += '</button>';
                html += '</td>';
                html += '</tr>';
                
                // 詳情行
                html += '<tr id="record-detail-' + recordId + '" class="activity-detail-row" style="display: none;">';
                html += '<td colspan="5">';
                html += '<table class="detail-info-table">';
                html += '<tr><td>活動日期</td><td>' + escapeHtml(record.activity_date || '未填寫') + '</td></tr>';
                html += '<tr><td>活動類型</td><td>' + escapeHtml(record.activity_type_name || '未填寫') + '</td></tr>';
                html += '<tr><td>學校</td><td>' + escapeHtml(record.school_name || record.school || '未填寫') + '</td></tr>';
                html += '<tr><td>活動時間</td><td>' + (record.activity_time == 1 ? '上班日' : record.activity_time == 2 ? '假日' : '未填寫') + '</td></tr>';
                if (record.contact_person) {
                    html += '<tr><td>聯絡窗口</td><td>' + escapeHtml(record.contact_person) + '</td></tr>';
                }
                if (record.contact_phone) {
                    html += '<tr><td>聯絡電話</td><td>' + escapeHtml(record.contact_phone) + '</td></tr>';
                }
                if (record.participants_other_text) {
                    html += '<tr><td>參與對象</td><td>' + escapeHtml(record.participants_other_text) + '</td></tr>';
                }
                if (record.feedback_other_text) {
                    html += '<tr><td>活動回饋</td><td>' + escapeHtml(record.feedback_other_text) + '</td></tr>';
                }
                if (record.suggestion) {
                    html += '<tr><td>建議事項</td><td>' + escapeHtml(record.suggestion) + '</td></tr>';
                }
                html += '<tr><td>建立時間</td><td>' + escapeHtml(record.created_at || '未填寫') + '</td></tr>';
                html += '</table>';
                html += '</td>';
                html += '</tr>';
            });
            
            html += '</tbody></table>';
            contentDiv.innerHTML = html;
        }
        
        // 切換活動紀錄詳情
        function toggleRecordDetail(recordId) {
            const detailRow = document.getElementById('record-detail-' + recordId);
            const detailBtn = document.getElementById('record-detail-btn-' + recordId);
            const btnText = detailBtn ? detailBtn.querySelector('.btn-text') : null;
            
            if (!detailRow) return;
            
            if (detailRow.style.display === 'none') {
                detailRow.style.display = '';
                if (btnText) {
                    btnText.textContent = '關閉詳情';
                }
                if (detailBtn) {
                    const icon = detailBtn.querySelector('i');
                    if (icon) {
                        icon.className = 'fas fa-eye-slash';
                    }
                }
            } else {
                detailRow.style.display = 'none';
                if (btnText) {
                    btnText.textContent = '查看詳情';
                }
                if (detailBtn) {
                    const icon = detailBtn.querySelector('i');
                    if (icon) {
                        icon.className = 'fas fa-eye';
                    }
                }
            }
        }
        
        // HTML 轉義函數
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // 搜尋功能
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            const table = document.getElementById('teacherTable');
            
            if (searchInput && table) {
                searchInput.addEventListener('keyup', function() {
                    const filter = searchInput.value.toLowerCase();
                    const tbody = table.getElementsByTagName('tbody')[0];
                    
                    if (!tbody) return;
                    
                    const rows = tbody.getElementsByTagName('tr');
                    
                    for (let i = 0; i < rows.length; i++) {
                        // 跳過詳情行
                        if (rows[i].classList.contains('detail-row')) {
                            continue;
                        }
                        
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
                        // 同時隱藏/顯示對應的詳情行
                        const detailRow = rows[i].nextElementSibling;
                        if (detailRow && detailRow.classList.contains('detail-row')) {
                            detailRow.style.display = found ? (detailRow.style.display === 'table-row' ? 'table-row' : 'none') : 'none';
                        }
                    }
                });
            }
            
            // 如果 URL 中有 teacher_id 參數，自動展開該教師的紀錄
            <?php if ($selected_teacher_id > 0 && !empty($selected_teacher_records)): ?>
            setTimeout(function() {
                toggleTeacherRecords(<?php echo $selected_teacher_id; ?>);
                // 直接顯示紀錄（因為已經從 PHP 載入）
                const contentDiv = document.getElementById('records-content-<?php echo $selected_teacher_id; ?>');
                if (contentDiv) {
                    displayTeacherRecords(<?php echo $selected_teacher_id; ?>, <?php echo json_encode($selected_teacher_records); ?>, '<?php echo addslashes($selected_teacher_name); ?>');
                    loadedTeachers.add(<?php echo $selected_teacher_id; ?>);
                }
            }, 100);
            <?php endif; ?>
        });
    </script>
</body>
</html>
