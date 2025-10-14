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
$page_title = '教師活動紀錄管理';

// 建立資料庫連接
$conn = getDatabaseConnection();

// 檢查是否有傳入 teacher_id
$teacher_id = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;

if ($teacher_id > 0) {
    // --- 詳細記錄視圖 ---
    // 查詢特定教師的活動記錄
    $activity_records = [];
    $teacher_name = '';
    $records_sql = "SELECT ar.*, t.name AS teacher_name, t.department AS teacher_department
                    FROM activity_records ar
                    LEFT JOIN teacher t ON ar.teacher_id = t.user_id
                    WHERE ar.teacher_id = ?
                    ORDER BY ar.activity_date DESC, ar.id DESC";
    $stmt = $conn->prepare($records_sql);
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        $activity_records = $result->fetch_all(MYSQLI_ASSOC);
        if (!empty($activity_records)) {
            $teacher_name = $activity_records[0]['teacher_name'];
            $page_title = '活動紀錄 - ' . htmlspecialchars($teacher_name);
        }
    }
    $stmt->close();
} else {
    // --- 教師列表視圖 ---
    $teachers_with_records = [];
    $teachers_sql = "SELECT t.user_id, t.name AS teacher_name, t.department AS teacher_department, COUNT(ar.id) AS record_count
                     FROM teacher t
                     JOIN activity_records ar ON t.user_id = ar.teacher_id
                     GROUP BY t.user_id, t.name, t.department
                     ORDER BY record_count DESC, t.name ASC";
    $result = $conn->query($teachers_sql);
    if ($result) {
        $teachers_with_records = $result->fetch_all(MYSQLI_ASSOC);
    }
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
        .content { padding: 24px; }
        .breadcrumb { margin-bottom: 16px; font-size: 16px; color: var(--text-secondary-color); }
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        
        .card { background: var(--card-background-color); border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); border: 1px solid var(--border-color); margin-bottom: 24px; }
        .card-header { padding: 16px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: #fafafa; }
        .card-header h3 { font-size: 18px; font-weight: 600; color: var(--text-color); }
        .card-body { padding: 24px; }

        .table-container { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 16px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 14px; white-space: nowrap; }
        .table th { background: #fafafa; font-weight: 600; }
        .table tr:hover { background: #fafafa; }

        .search-input {
            padding: 8px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            font-size: 14px;
            width: 250px;
        }

        .empty-state { text-align: center; padding: 40px; color: var(--text-secondary-color); }

        .btn-view {
            padding: 4px 12px;
            border: 1px solid #1890ff;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
            background: #fff;
            color: #1890ff;
        }
        .btn-view:hover { background: #1890ff; color: white; }

        /* Modal styles */
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.45); }
        .modal-content { background-color: #fff; margin: 5% auto; padding: 0; border-radius: 8px; width: 90%; max-width: 600px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .modal-header { padding: 16px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
        .modal-title { font-size: 16px; font-weight: 600; }
        .close { color: var(--text-secondary-color); font-size: 20px; font-weight: bold; cursor: pointer; }
        .modal-body { padding: 24px; max-height: 60vh; overflow-y: auto; }
        .modal-body p { margin: 0 0 12px; }
        .modal-body strong { color: var(--text-color); }
        .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border-color); text-align: right; }
        .btn { padding: 8px 16px; border: 1px solid #d9d9d9; border-radius: 6px; cursor: pointer; font-size: 14px; background: #fff; }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            <div class="content">
                <?php if ($teacher_id > 0): // 詳細記錄視圖 ?>
                    <div class="breadcrumb">
                        <a href="index.php">首頁</a> / <a href="activity_records.php">教師活動紀錄</a> / <?php echo htmlspecialchars($teacher_name); ?>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h3><?php echo htmlspecialchars($teacher_name); ?> 的紀錄列表 (共 <?php echo count($activity_records); ?> 筆)</h3>
                            <input type="text" id="searchInput" class="search-input" placeholder="搜尋學校或類型...">
                        </div>
                        <div class="card-body table-container">
                            <?php if (empty($activity_records)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                                    <p>這位教師目前尚無任何活動紀錄。</p>
                                </div>
                            <?php else: ?>
                                <table class="table" id="recordsTable">
                                    <thead>
                                        <tr>
                                            <th>活動日期</th>
                                            <th>學校名稱</th>
                                            <th>活動類型</th>
                                            <th>活動時間</th>
                                            <th>提交時間</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($activity_records as $record): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($record['activity_date']); ?></td>
                                            <td><?php echo htmlspecialchars($record['school_name']); ?></td>
                                            <td><?php echo htmlspecialchars($record['activity_type']); ?></td>
                                            <td><?php echo htmlspecialchars($record['activity_time']); ?></td>
                                            <td><?php echo date('Y/m/d H:i', strtotime($record['created_at'])); ?></td>
                                            <td>
                                                <button class="btn-view" onclick='viewRecord(<?php echo json_encode($record, JSON_UNESCAPED_UNICODE); ?>)'>查看</button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php else: // 教師列表視圖 ?>
                    <div class="breadcrumb">
                        <a href="index.php">首頁</a> / 教師活動紀錄管理
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h3>教師列表 (共 <?php echo count($teachers_with_records); ?> 位)</h3>
                            <input type="text" id="searchInput" class="search-input" placeholder="搜尋教師姓名或系所...">
                        </div>
                        <div class="card-body table-container">
                            <?php if (empty($teachers_with_records)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-users-slash fa-3x" style="margin-bottom: 16px;"></i>
                                    <p>目前沒有任何教師提交過活動紀錄。</p>
                                </div>
                            <?php else: ?>
                                <table class="table" id="recordsTable">
                                    <thead>
                                        <tr>
                                            <th>教師姓名</th>
                                            <th>所屬系所</th>
                                            <th>紀錄筆數</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($teachers_with_records as $teacher): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($teacher['teacher_name']); ?></td>
                                            <td><?php echo htmlspecialchars($teacher['teacher_department']); ?></td>
                                            <td><?php echo htmlspecialchars($teacher['record_count']); ?></td>
                                            <td>
                                                <a href="?teacher_id=<?php echo $teacher['user_id']; ?>" class="btn-view">查看紀錄</a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 查看 Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">查看活動紀錄</h3>
                <span class="close" onclick="closeModal('viewModal')">&times;</span>
            </div>
            <div class="modal-body" id="viewModalBody">
                <!-- Content will be injected by JS -->
            </div>
            <div class="modal-footer">
                <button class="btn" onclick="closeModal('viewModal')">關閉</button>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('searchInput');
        const table = document.getElementById('recordsTable');
        const rows = table ? table.getElementsByTagName('tbody')[0].getElementsByTagName('tr') : [];

        if (searchInput) {
            searchInput.addEventListener('keyup', function() {
                const filter = searchInput.value.toLowerCase();
                
                const isTeacherListView = <?php echo $teacher_id > 0 ? 'false' : 'true'; ?>;

                for (let i = 0; i < rows.length; i++) {
                    const cells = rows[i].getElementsByTagName('td');
                    let text = '';

                    if (isTeacherListView) {
                        // 搜尋教師姓名和系所
                        if (cells[0]) text += cells[0].textContent || cells[0].innerText;
                        if (cells[1]) text += (cells[1].textContent || cells[1].innerText);
                    } else {
                        // 搜尋學校名稱和活動類型
                        if (cells[1]) text += cells[1].textContent || cells[1].innerText;
                        if (cells[2]) text += (cells[2].textContent || cells[2].innerText);
                    }

                    if (text.toLowerCase().indexOf(filter) > -1) {
                        rows[i].style.display = "";
                    } else {
                        rows[i].style.display = "none";
                    }
                }
            });
        }
    });

    function viewRecord(record) {
        const modalBody = document.getElementById('viewModalBody');
        let content = `
            <p><strong>活動日期:</strong> ${record.activity_date || 'N/A'}</p>
            <p><strong>教師姓名:</strong> ${record.teacher_name || 'N/A'}</p>
            <p><strong>所屬系所:</strong> ${record.teacher_department || 'N/A'}</p>
            <p><strong>學校名稱:</strong> ${record.school_name || 'N/A'}</p>
            <p><strong>活動類型:</strong> ${record.activity_type || 'N/A'}</p>
            <p><strong>活動時間:</strong> ${record.activity_time || 'N/A'}</p>
            <p><strong>提交時間:</strong> ${new Date(record.created_at).toLocaleString()}</p>
            <hr>
            <p><strong>聯絡窗口:</strong> ${record.contact_person || '未填寫'}</p>
            <p><strong>聯絡電話:</strong> ${record.contact_phone || '未填寫'}</p>
            <p><strong>參與對象:</strong> ${record.participants || '未填寫'}</p>
            <hr>
            <p><strong>活動紀錄/回饋:</strong></p>
            <div style="background:#f5f5f5; padding:10px; border-radius:4px; white-space: pre-wrap;">${record.activity_feedback || '未填寫'}</div>
            <p style="margin-top:12px;"><strong>檢討與建議:</strong></p>
            <div style="background:#f5f5f5; padding:10px; border-radius:4px; white-space: pre-wrap;">${record.suggestion || '未填寫'}</div>
        `;
        modalBody.innerHTML = content;
        document.getElementById('viewModal').style.display = 'block';
    }

    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }

    window.onclick = function(event) {
        const modal = document.getElementById('viewModal');
        if (event.target == modal) {
            modal.style.display = "none";
        }
    }
    </script>
</body>
</html>