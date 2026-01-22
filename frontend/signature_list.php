<?php
require_once __DIR__ . '/session_config.php';

// 檢查登入狀態和角色權限
checkBackendLogin();

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 設置頁面標題
$page_title = '簽名記錄';
$current_page = 'signature_list';

// 獲取使用者資訊
$user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['role'] ?? '';
$is_admin = in_array($user_role, ['ADM', '管理員']);

// 建立資料庫連接
try {
    $conn = getDatabaseConnection();
} catch (Exception $e) {
    die("資料庫連接失敗: " . $e->getMessage());
}

// 檢查簽名表是否存在
$table_check = $conn->query("SHOW TABLES LIKE 'signatures'");
if ($table_check && $table_check->num_rows > 0) {
    // 獲取簽名記錄
    if ($is_admin) {
        // 管理員可以看到所有簽名
        $stmt = $conn->prepare("
            SELECT s.*, u.name as user_name, u.username
            FROM signatures s
            LEFT JOIN users u ON s.user_id = u.id
            ORDER BY s.created_at DESC
            LIMIT 100
        ");
        $stmt->execute();
    } else {
        // 一般用戶只能看到自己的簽名
        $stmt = $conn->prepare("
            SELECT s.*, u.name as user_name, u.username
            FROM signatures s
            LEFT JOIN users u ON s.user_id = u.id
            WHERE s.user_id = ?
            ORDER BY s.created_at DESC
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
    }
    
    $result = $stmt->get_result();
    $signatures = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $signatures = [];
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: var(--background-color);
            color: var(--text-color);
        }
        .dashboard {
            display: flex;
            min-height: 100vh;
        }
        .content {
            padding: 24px;
            width: 100%;
        }
        .breadcrumb {
            margin-bottom: 16px;
            font-size: 16px;
            color: var(--text-secondary-color);
        }
        .breadcrumb a {
            color: var(--primary-color);
            text-decoration: none;
        }
        .breadcrumb a:hover {
            text-decoration: underline;
        }
        .card {
            background: var(--card-background-color);
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .card-header {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fafafa;
        }
        .card-header h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-color);
        }
        .card-body {
            padding: 24px;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        .table th, .table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        .table th {
            background: #fafafa;
            font-weight: 600;
            color: var(--text-color);
        }
        .table tr:hover {
            background: #fafafa;
        }
        .signature-image {
            max-width: 200px;
            max-height: 100px;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
        }
        .signature-image:hover {
            border-color: var(--primary-color);
        }
        .btn {
            padding: 6px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
            background: #fff;
            color: var(--text-color);
        }
        .btn-primary {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        .btn-primary:hover {
            background: #40a9ff;
        }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--text-secondary-color);
        }
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
        .modal.active {
            display: flex;
        }
        .modal-content {
            background-color: white;
            border-radius: 8px;
            padding: 20px;
            max-width: 90%;
            max-height: 90%;
            overflow: auto;
        }
        .modal-content img {
            max-width: 100%;
            height: auto;
        }
        .close {
            float: right;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            color: var(--text-secondary-color);
        }
        .close:hover {
            color: var(--text-color);
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            <div class="content">
                <div class="breadcrumb">
                    <a href="index.php">首頁</a> / <a href="signature.php">電子簽章</a> / <?php echo $page_title; ?>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-file-signature"></i> 簽名記錄</h3>
                        <a href="signature.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> 新增簽名
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($signatures)): ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                                <p>目前尚無簽名記錄</p>
                                <a href="signature.php" class="btn btn-primary" style="margin-top: 16px;">立即簽名</a>
                            </div>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <?php if ($is_admin): ?>
                                            <th>簽名者</th>
                                        <?php endif; ?>
                                        <th>簽名預覽</th>
                                        <th>文件類型</th>
                                        <th>文件ID</th>
                                        <th>簽名時間</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($signatures as $sig): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($sig['id']); ?></td>
                                            <?php if ($is_admin): ?>
                                                <td><?php echo htmlspecialchars($sig['user_name'] ?? $sig['username'] ?? '未知'); ?></td>
                                            <?php endif; ?>
                                            <td>
                                                <img src="<?php echo htmlspecialchars($sig['signature_path']); ?>" 
                                                     alt="簽名" 
                                                     class="signature-image"
                                                     onclick="showSignatureModal('<?php echo htmlspecialchars($sig['signature_path']); ?>')">
                                            </td>
                                            <td><?php echo htmlspecialchars($sig['document_type'] ?? 'general'); ?></td>
                                            <td><?php echo $sig['document_id'] ? htmlspecialchars($sig['document_id']) : '-'; ?></td>
                                            <td><?php echo htmlspecialchars($sig['created_at']); ?></td>
                                            <td>
                                                <a href="<?php echo htmlspecialchars($sig['signature_path']); ?>" 
                                                   download 
                                                   class="btn">
                                                    <i class="fas fa-download"></i> 下載
                                                </a>
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

    <!-- 簽名預覽模態框 -->
    <div id="signatureModal" class="modal" onclick="closeSignatureModal()">
        <div class="modal-content" onclick="event.stopPropagation()">
            <span class="close" onclick="closeSignatureModal()">&times;</span>
            <h3 style="margin-bottom: 16px;">簽名預覽</h3>
            <img id="modalSignatureImage" src="" alt="簽名">
        </div>
    </div>

    <script>
        function showSignatureModal(imagePath) {
            document.getElementById('modalSignatureImage').src = imagePath;
            document.getElementById('signatureModal').classList.add('active');
        }

        function closeSignatureModal() {
            document.getElementById('signatureModal').classList.remove('active');
        }

        // 按 ESC 鍵關閉模態框
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeSignatureModal();
            }
        });
    </script>
</body>
</html>

