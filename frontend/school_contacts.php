<?php
require_once __DIR__ . '/session_config.php';

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 設置頁面標題
$page_title = '學校聯絡人管理';
$current_page = 'school_contacts';

// 建立資料庫連接
$conn = getDatabaseConnection();

// 檢查 schools_contacts 表是否存在，如果不存在則創建
$table_check = $conn->query("SHOW TABLES LIKE 'schools_contacts'");
if (!$table_check || $table_check->num_rows == 0) {
    // 創建 schools_contacts 表
    $create_table_sql = "CREATE TABLE IF NOT EXISTS schools_contacts (
        id INT AUTO_INCREMENT PRIMARY KEY COMMENT '聯絡人ID',
        school_code VARCHAR(50) DEFAULT NULL COMMENT '學校代碼（關聯到 school_data 表）',
        contact_name VARCHAR(100) DEFAULT NULL COMMENT '聯絡人姓名',
        email VARCHAR(120) NOT NULL COMMENT 'Email地址',
        phone VARCHAR(50) DEFAULT NULL COMMENT '電話',
        title VARCHAR(100) DEFAULT NULL COMMENT '職稱',
        is_active TINYINT(1) DEFAULT 1 COMMENT '是否啟用',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新時間',
        INDEX idx_school_code (school_code),
        INDEX idx_email (email),
        INDEX idx_is_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='學校聯絡人資料表'";
    
    if (!$conn->query($create_table_sql)) {
        die("創建 schools_contacts 表失敗: " . $conn->error);
    }
} else {
    // 檢查表結構，確保有必要的欄位
    $columns_result = $conn->query("DESCRIBE schools_contacts");
    $columns = [];
    while ($column = $columns_result->fetch_assoc()) {
        $columns[] = $column['Field'];
    }
    
    // 如果缺少 school_code 欄位，添加它
    if (!in_array('school_code', $columns)) {
        // 檢查是否有 school_id 欄位，如果有則重命名
        if (in_array('school_id', $columns)) {
            $conn->query("ALTER TABLE schools_contacts CHANGE school_id school_code VARCHAR(50) DEFAULT NULL COMMENT '學校代碼'");
        } else {
            $conn->query("ALTER TABLE schools_contacts ADD COLUMN school_code VARCHAR(50) DEFAULT NULL COMMENT '學校代碼' AFTER id");
        }
    }
    
    // 如果缺少 contact_name 欄位，添加它（或從 name 欄位重命名）
    if (!in_array('contact_name', $columns)) {
        if (in_array('name', $columns)) {
            $conn->query("ALTER TABLE schools_contacts CHANGE name contact_name VARCHAR(100) DEFAULT NULL COMMENT '聯絡人姓名'");
        } else {
            $conn->query("ALTER TABLE schools_contacts ADD COLUMN contact_name VARCHAR(100) DEFAULT NULL COMMENT '聯絡人姓名' AFTER school_code");
        }
    }
    
    // 如果缺少 title 欄位，添加它（或從 position 欄位重命名）
    if (!in_array('title', $columns)) {
        if (in_array('position', $columns)) {
            $conn->query("ALTER TABLE schools_contacts CHANGE position title VARCHAR(100) DEFAULT NULL COMMENT '職稱'");
        } else {
            $conn->query("ALTER TABLE schools_contacts ADD COLUMN title VARCHAR(100) DEFAULT NULL COMMENT '職稱' AFTER phone");
        }
    }
    
    // 確保有 is_active 欄位
    if (!in_array('is_active', $columns)) {
        $conn->query("ALTER TABLE schools_contacts ADD COLUMN is_active TINYINT(1) DEFAULT 1 COMMENT '是否啟用' AFTER title");
    }
}

$message = "";
$messageType = "";

// 處理表單提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'add_contact':
                $sql = "INSERT INTO schools_contacts (school_code, contact_name, email, phone, title, is_active) VALUES (?, ?, ?, ?, ?, 1)";
                $stmt = $conn->prepare($sql);
                $school_code = $_POST['school_code'];
                $contact_name = $_POST['contact_name'] ?: null;
                $email = $_POST['email'];
                $phone = $_POST['phone'];
                $title = $_POST['title'];
                $stmt->bind_param("sssss", $school_code, $contact_name, $email, $phone, $title);
                if ($stmt->execute()) {
                    $message = "聯絡人新增成功！"; $messageType = "success";
                }
                break;

            case 'update_contact':
                $sql = "UPDATE schools_contacts SET school_code = ?, contact_name = ?, email = ?, phone = ?, title = ?, is_active = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $school_code = $_POST['school_code'];
                $contact_name = $_POST['contact_name'] ?: null;
                $email = $_POST['email'];
                $phone = $_POST['phone'];
                $title = $_POST['title'];
                $is_active = intval($_POST['is_active']);
                $contact_id = intval($_POST['contact_id']);
                $stmt->bind_param("sssssii", $school_code, $contact_name, $email, $phone, $title, $is_active, $contact_id);
                if ($stmt->execute()) {
                    $message = "聯絡人更新成功！"; $messageType = "success";
                }
                break;

            case 'delete_contact':
                $sql = "DELETE FROM schools_contacts WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $_POST['contact_id']);
                if ($stmt->execute()) {
                    $message = "聯絡人刪除成功！"; $messageType = "success";
                }
                break;
        }
        if (isset($stmt) && $stmt->error) {
            throw new Exception($stmt->error);
        }
    } catch (Exception $e) {
        $message = "操作失敗：" . $e->getMessage();
        $messageType = "error";
    }
}

// 獲取所有聯絡人資料（JOIN school_data 表獲取學校名稱）
$contacts_sql = "
    SELECT sc.*, 
           sd.name as school_name
    FROM schools_contacts sc
    LEFT JOIN school_data sd ON sc.school_code = sd.school_code
    ORDER BY sd.name, sc.contact_name";
$contacts = $conn->query($contacts_sql)->fetch_all(MYSQLI_ASSOC);

// 獲取所有學校列表（用於下拉選單）
$schools_sql = "SELECT school_code, name FROM school_data WHERE is_active = 1 ORDER BY name";
$schools = $conn->query($schools_sql)->fetch_all(MYSQLI_ASSOC);

// 統計資訊
$total_contacts = count($contacts);
$active_contacts = count(array_filter($contacts, function($c) { return $c['is_active'] == 1; }));
$inactive_contacts = $total_contacts - $active_contacts;

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
            --success-color: #52c41a;
            --warning-color: #faad14;
            --danger-color: #ff4d4f;
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
        .page-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            gap: 16px;
        }
        .breadcrumb { margin-bottom: 0; font-size: 16px; color: var(--text-secondary-color); }
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        .table-search {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .table-search input {
            padding: 8px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            width: 240px;
            background: #fff;
            font-size: 16px;
            transition: all 0.3s;
        }
        .table-search input:focus {
            outline: none;
            border-color: #1890ff;
            box-shadow: 0 0 0 2px rgba(24,144,255,0.2);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: var(--card-background-color);
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
        }
        
        .stat-icon.total { 
            background: linear-gradient(135deg, #1890ff, #40a9ff); 
        }
        .stat-icon.active { 
            background: linear-gradient(135deg, #52c41a, #73d13d); 
        }
         .card { background: var(--card-background-color); border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); border: 1px solid var(--border-color); margin-bottom: 24px; }
        .card-header { padding: 16px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: #fafafa; }
        .card-header h3 { font-size: 18px; font-weight: 600; color: var(--text-color); }
        .card-body {
            padding: 0;
            overflow-x: auto;
        }

        .table-container { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 16px 24px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 16px; }
        .table th:first-child, .table td:first-child {
            padding-left: 60px;
        }
        .table th { background: #fafafa; font-weight: 600; color: #262626; cursor: pointer; user-select: none; position: relative; }
        .table th:hover { background: #f0f0f0; }
        .sort-icon {
            margin-left: 8px;
            font-size: 12px;
            color: #8c8c8c;
        }
        .sort-icon.active {
            color: #1890ff;
        }
        .sort-icon.asc::after {
            content: "↑";
        }
        .sort-icon.desc::after {
            content: "↓";
        }
        .table td { color: #595959; }
        .table tr:hover { background: #fafafa; }
        .table a.btn { text-decoration: none; }

        .btn { padding: 8px 16px; border: 1px solid #d9d9d9; border-radius: 6px; cursor: pointer; font-size: 14px; transition: all 0.3s; background: #fff; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
        .btn-primary { background: var(--primary-color); color: white; border-color: var(--primary-color); }
        .btn-primary:hover { background: #40a9ff; border-color: #40a9ff; }

        /* 表格內操作按鈕樣式 */
        .action-buttons { display: flex; gap: 8px; }
        .btn-action {
            padding: 4px 12px; border-radius: 4px; font-size: 14px;
            text-decoration: none; display: inline-block; transition: all 0.3s;
            background: #fff;
        }
        .btn-edit { color: var(--success-color); border: 1px solid var(--success-color); }
        .btn-edit:hover { background: var(--success-color); color: white; }
        .btn-delete { color: var(--danger-color); border: 1px solid var(--danger-color); }
        .btn-delete:hover { background: var(--danger-color); color: white; }

        .form-group { margin-bottom: 20px; }
        .form-label { display: block; margin-bottom: 8px; font-weight: 500; }
        .form-control { width: 100%; padding: 10px 12px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px; transition: border-color 0.3s; }
        .form-control:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.2); }
        .form-row { display: flex; gap: 16px; }
        .form-row .form-group { flex: 1; }

        .message { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-weight: 500; }
        .message.success { background: #f6ffed; border: 1px solid #b7eb8f; color: var(--success-color); }
        .message.error { background: #fff2f0; border: 1px solid #ffccc7; color: var(--danger-color); }

        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; }
        .status-active { background: #f6ffed; color: var(--success-color); border: 1px solid #b7eb8f; }
        .status-inactive { background: #fff2f0; color: var(--danger-color); border: 1px solid #ffccc7; }
        
        .title-badge {
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            display: inline-block;
        }
        .title-badge {
            background: linear-gradient(135deg, #722ed1, #9254de);
            color: white;
            border: 1px solid #722ed1;
        }

        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.45); }
        .modal-content { background-color: #fff; margin: 5% auto; padding: 0; border-radius: 8px; width: 90%; max-width: 600px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .modal-header { padding: 16px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
        .modal-title { font-size: 16px; font-weight: 600; }
        .close { color: var(--text-secondary-color); font-size: 20px; font-weight: bold; cursor: pointer; }
        .close:hover { color: var(--text-color); }
        .modal-body { padding: 24px; }
        .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 8px; background: #fafafa; }
        .required-asterisk { color: var(--danger-color); margin-right: 4px; }


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
             background: #fff;
             color: #595959;
             border-radius: 6px;
             cursor: pointer;
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
                        <input type="text" id="searchInput" placeholder="搜尋學校、聯絡人或Email..." onkeyup="filterTable()">
                        <button class="btn btn-primary" onclick="showModal('addContactModal')">
                            <i class="fas fa-plus"></i> 新增聯絡人
                        </button>
                    </div>
                </div>

                <?php if ($message): ?>
                    <div class="message <?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-body">
                        <?php if (empty($contacts)): ?>
                            <div style="text-align: center; padding: 40px; color: var(--text-secondary-color);">
                                <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                                <p>目前尚無聯絡人資料。</p>
                            </div>
                        <?php else: ?>
                            <table class="table" id="contactsTable">
                                <thead>
                                    <tr>
                                        <th onclick="sortTable(0)">學校名稱 <span class="sort-icon" id="sort-0"></span></th>
                                        <th onclick="sortTable(1)">聯絡人姓名 <span class="sort-icon" id="sort-1"></span></th>
                                        <th onclick="sortTable(2)">職稱 <span class="sort-icon" id="sort-2"></span></th>
                                        <th onclick="sortTable(3)">Email <span class="sort-icon" id="sort-3"></span></th>
                                        <th onclick="sortTable(4)">電話 <span class="sort-icon" id="sort-4"></span></th>
                                        <th onclick="sortTable(5)">狀態 <span class="sort-icon" id="sort-5"></span></th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($contacts as $contact): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($contact['school_name'] ?: $contact['school_code']); ?></td>
                                        <td><?php echo htmlspecialchars($contact['contact_name'] ?: '-'); ?></td>
                                        <td><span class="title-badge"><?php echo htmlspecialchars($contact['title']); ?></span></td>
                                        <td><?php echo htmlspecialchars($contact['email']); ?></td>
                                        <td><?php echo htmlspecialchars($contact['phone']); ?></td>
                                        <td><span class="status-badge <?php echo $contact['is_active'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $contact['is_active'] ? '啟用' : '停用'; ?></span></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-action btn-edit" onclick='editContact(<?php echo json_encode($contact); ?>)'>編輯</button>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('確定要刪除此聯絡人嗎？');">
                                                    <input type="hidden" name="action" value="delete_contact">
                                                    <input type="hidden" name="contact_id" value="<?php echo $contact['id']; ?>">
                                                    <button type="submit" class="btn-action btn-delete">刪除</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                             </table>
                         <?php endif; ?>
                     </div>
                     <!-- 分頁控制 -->
                     <div class="pagination">
                         <div class="pagination-info">
                             <span>每頁顯示：</span>
                             <select id="itemsPerPage" onchange="changeItemsPerPage()">
                                 <option value="10" selected>10</option>
                                 <option value="20" >20</option>
                                 <option value="50">50</option>
                                 <option value="100">100</option>
                                 <option value="all">全部</option>
                             </select>
                             <span id="pageInfo">顯示第 <span id="currentRange">1-<?php echo min(20, count($contacts)); ?></span> 筆，共 <?php echo count($contacts); ?> 筆</span>
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

    <!-- 新增聯絡人 Modal -->
    <div id="addContactModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">新增聯絡人</h3>
                <span class="close" onclick="closeModal('addContactModal')">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_contact">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label"><span class="required-asterisk">*</span>學校</label>
                        <select name="school_code" class="form-control" required>
                            <option value="">請選擇學校</option>
                            <?php foreach ($schools as $school): ?>
                                <option value="<?php echo htmlspecialchars($school['school_code']); ?>">
                                    <?php echo htmlspecialchars($school['name'] . ' (' . $school['school_code'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">聯絡人姓名 (選填)</label>
                        <input type="text" name="contact_name" class="form-control">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label"><span class="required-asterisk">*</span> Email</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><span class="required-asterisk">*</span> 電話</label>
                            <input type="text" name="phone" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><span class="required-asterisk">*</span> 職稱</label>
                        <input type="text" name="title" class="form-control" required placeholder="例如：校長、主任、組長">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('addContactModal')">取消</button>
                    <button type="submit" class="btn btn-primary">新增</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 編輯聯絡人 Modal -->
    <div id="editContactModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">編輯聯絡人</h3>
                <span class="close" onclick="closeModal('editContactModal')">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_contact">
                <input type="hidden" name="contact_id" id="edit_contact_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label"><span class="required-asterisk">*</span> 學校</label>
                        <select name="school_code" id="edit_school_code" class="form-control" required>
                            <option value="">請選擇學校</option>
                            <?php foreach ($schools as $school): ?>
                                <option value="<?php echo htmlspecialchars($school['school_code']); ?>">
                                    <?php echo htmlspecialchars($school['name'] . ' (' . $school['school_code'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">聯絡人姓名 (選填)</label>
                        <input type="text" name="contact_name" id="edit_contact_name" class="form-control">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label"><span class="required-asterisk">*</span> Email</label>
                            <input type="email" name="email" id="edit_email" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><span class="required-asterisk">*</span> 電話</label>
                            <input type="text" name="phone" id="edit_phone" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label"><span class="required-asterisk">*</span> 職稱</label>
                            <input type="text" name="title" id="edit_title" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><span class="required-asterisk">*</span> 狀態</label>
                            <select name="is_active" id="edit_is_active" class="form-control" required>
                                <option value="1">啟用</option>
                                <option value="0">停用</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('editContactModal')">取消</button>
                    <button type="submit" class="btn btn-primary">儲存</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        function editContact(contact) {
            document.getElementById('edit_contact_id').value = contact.id;
            document.getElementById('edit_school_code').value = contact.school_code;
            document.getElementById('edit_contact_name').value = contact.contact_name || '';
            document.getElementById('edit_email').value = contact.email;
            document.getElementById('edit_phone').value = contact.phone;
            document.getElementById('edit_title').value = contact.title;
            document.getElementById('edit_is_active').value = contact.is_active;
            showModal('editContactModal');
        }

         // 分頁相關變數
         let currentPage = 1;
         let itemsPerPage = 10;
         let allRows = [];
         let filteredRows = [];
         
         // 排序相關變數
         let currentSortColumn = -1;
         let currentSortOrder = 'asc';

         // 表格搜尋功能
         function filterTable() {
             const input = document.getElementById('searchInput');
             const filter = input.value.toLowerCase();
             const table = document.getElementById('contactsTable');
             
             if (!table) return;
             
             const tbody = table.getElementsByTagName('tbody')[0];
             if (!tbody) return;
             
             allRows = Array.from(tbody.getElementsByTagName('tr'));
             
             filteredRows = allRows.filter(row => {
                 const cells = row.getElementsByTagName('td');
                 if (cells.length < 4) return false;
                 
                 const schoolText = cells[0].textContent || cells[0].innerText;
                 const contactText = cells[1].textContent || cells[1].innerText;
                 const emailText = cells[3].textContent || cells[3].innerText;
                 
                 return schoolText.toLowerCase().indexOf(filter) > -1 || 
                        contactText.toLowerCase().indexOf(filter) > -1 || 
                        emailText.toLowerCase().indexOf(filter) > -1;
             });
             
             currentPage = 1;
             updatePagination();
         }

         // 頁面載入時初始化
         document.addEventListener('DOMContentLoaded', function() {
             const table = document.getElementById('contactsTable');
             
             if (table) {
                 const tbody = table.getElementsByTagName('tbody')[0];
                 if (tbody) {
                     allRows = Array.from(tbody.getElementsByTagName('tr'));
                     filteredRows = allRows;
                     
                     // 初始化分頁
                     updatePagination();
                 }
             }
         });

         function changeItemsPerPage() {
             itemsPerPage = document.getElementById('itemsPerPage').value === 'all' ? 
                           filteredRows.length : 
                           parseInt(document.getElementById('itemsPerPage').value);
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
             
             // 確保 itemsPerPage 是正確的數字或 'all'
             let pageSize;
             if (itemsPerPage === 'all') {
                 pageSize = totalItems;
             } else {
                 pageSize = typeof itemsPerPage === 'number' ? itemsPerPage : parseInt(itemsPerPage);
                 if (isNaN(pageSize) || pageSize <= 0) {
                     pageSize = 10;
                     itemsPerPage = 10;
                 }
             }
             
             // 計算總頁數：只有當資料筆數大於每頁顯示筆數時，才需要多頁
             const totalPages = totalItems > pageSize ? Math.ceil(totalItems / pageSize) : 1;
             
             // 隱藏所有行
             allRows.forEach(row => row.style.display = 'none');
             
             if (itemsPerPage === 'all' || pageSize >= totalItems) {
                 // 顯示所有過濾後的行
                 filteredRows.forEach(row => row.style.display = '');
                 
                 // 更新分頁資訊
                 document.getElementById('currentRange').textContent = 
                     totalItems > 0 ? `1-${totalItems}` : '0-0';
             } else {
                 // 計算當前頁的範圍
                 const start = (currentPage - 1) * pageSize;
                 const end = Math.min(start + pageSize, totalItems);
                 
                 // 顯示當前頁的行
                 for (let i = start; i < end; i++) {
                     if (filteredRows[i]) {
                         filteredRows[i].style.display = '';
                     }
                 }
                 
                 // 更新分頁資訊
                 document.getElementById('currentRange').textContent = 
                     totalItems > 0 ? `${start + 1}-${end}` : '0-0';
             }
             
             // 更新總數
             document.getElementById('pageInfo').innerHTML = 
                 `顯示第 <span id="currentRange">${document.getElementById('currentRange').textContent}</span> 筆，共 ${totalItems} 筆`;
             
             // 更新上一頁/下一頁按鈕
             document.getElementById('prevPage').disabled = currentPage === 1;
             document.getElementById('nextPage').disabled = currentPage >= totalPages || totalPages <= 1;
             
             // 更新頁碼按鈕
             updatePageNumbers(totalPages);
         }

         function updatePageNumbers(totalPages) {
             const pageNumbers = document.getElementById('pageNumbers');
             if (!pageNumbers) return;
             
             pageNumbers.innerHTML = '';
             
             // 總是顯示頁碼按鈕（即使只有1頁）
             if (totalPages >= 1) {
                 // 如果只有1頁，只顯示"1"
                 // 如果有多頁，顯示所有頁碼
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

         // 表格排序功能
         function sortTable(columnIndex) {
             const table = document.getElementById('contactsTable');
             if (!table) return;
             
             const tbody = table.getElementsByTagName('tbody')[0];
             if (!tbody) return;
             
             // 切換排序順序
             if (currentSortColumn === columnIndex) {
                 currentSortOrder = currentSortOrder === 'asc' ? 'desc' : 'asc';
             } else {
                 currentSortColumn = columnIndex;
                 currentSortOrder = 'asc';
             }
             
             // 獲取所有可見的行（考慮搜尋過濾）
             const rows = Array.from(tbody.getElementsByTagName('tr'));
             const visibleRows = rows.filter(row => row.style.display !== 'none');
             
             // 排序
             visibleRows.sort((a, b) => {
                 const aValue = a.getElementsByTagName('td')[columnIndex].textContent.trim();
                 const bValue = b.getElementsByTagName('td')[columnIndex].textContent.trim();
                 
                 // 數字排序（用於狀態等）
                 if (columnIndex === 5) { // 狀態欄位
                     const aStatus = a.getElementsByTagName('td')[columnIndex].textContent.includes('啟用') ? 1 : 0;
                     const bStatus = b.getElementsByTagName('td')[columnIndex].textContent.includes('啟用') ? 1 : 0;
                     return currentSortOrder === 'asc' ? aStatus - bStatus : bStatus - aStatus;
                 }
                 
                 // 文字排序
                 if (aValue < bValue) return currentSortOrder === 'asc' ? -1 : 1;
                 if (aValue > bValue) return currentSortOrder === 'asc' ? 1 : -1;
                 return 0;
             });
             
             // 重新排列 DOM
             visibleRows.forEach(row => tbody.appendChild(row));
             
             // 更新排序圖標
             updateSortIcons();
             
             // 重新應用分頁
             currentPage = 1;
             updatePagination();
         }
         
         // 更新排序圖標
         function updateSortIcons() {
             // 清除所有圖標
             for (let i = 0; i < 6; i++) {
                 const icon = document.getElementById(`sort-${i}`);
                 if (icon) {
                     icon.className = 'sort-icon';
                 }
             }
             
             // 設置當前排序欄位的圖標
             if (currentSortColumn >= 0) {
                 const icon = document.getElementById(`sort-${currentSortColumn}`);
                 if (icon) {
                     icon.className = `sort-icon active ${currentSortOrder}`;
                 }
             }
         }
     </script>
</body>
</html>
