<?php
session_start();

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 資料庫連接設定
$host = '100.79.58.120';
$dbname = 'topics_good';
$db_username = 'root';
$db_password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("資料庫連接失敗: " . $e->getMessage());
}

$user_id = $_SESSION['user_id'];

// 處理表單提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        // 更新姓名和Email
        $name = $_POST['name'];
        $email = $_POST['email'];
        $department = $_POST['department'] ?? null;
        $phone = $_POST['phone'] ?? null;

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE user SET name = ?, email = ? WHERE id = ?");
            $stmt->execute([$name, $email, $user_id]);

            if (in_array($_SESSION['role'], ['teacher', '老師']) && $department !== null && $phone !== null) {
                $stmt = $pdo->prepare("UPDATE teacher SET department = ?, phone = ? WHERE user_id = ?");
                $stmt->execute([$department, $phone, $user_id]);
            }

            $pdo->commit();
            // 更新 Session 中的姓名
            $_SESSION['name'] = $name;

            $success_message = "個人資料更新成功！";
        } catch (PDOException $e) {
            $error_message = "資料更新失敗：" . $e->getMessage();
        }
    }
}

// 獲取當前用戶資料
$stmt = $pdo->prepare("SELECT username, name, email, role FROM user WHERE id = ?");
$stmt->execute([$user_id]);
$current_user = $stmt->fetch(PDO::FETCH_ASSOC);

// 如果是老師，額外獲取科系和電話
if ($current_user && in_array($current_user['role'], ['teacher', '老師'])) {
    try {
        $stmt = $pdo->prepare("SELECT department, phone FROM teacher WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $teacher_info = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($teacher_info) {
            $current_user = array_merge($current_user, $teacher_info);
        }
    } catch (PDOException $e) {
        // 如果 teacher 表或欄位不存在，優雅地忽略錯誤
    }
}

$page_title = '個人資料';
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
            --primary-color: #1890ff; --text-color: #262626; --text-secondary-color: #8c8c8c;
            --border-color: #f0f0f0; --background-color: #f0f2f5; --card-background-color: #fff;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: var(--background-color); color: var(--text-color); overflow-x: hidden; }
        .dashboard { display: flex; min-height: 100vh; }
        .content { padding: 24px; width: 100%; }
        .breadcrumb { margin-bottom: 16px; font-size: 16px; color: var(--text-secondary-color); }
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        
        .card { background: var(--card-background-color); border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); border: 1px solid var(--border-color); margin-bottom: 24px; }
        .card-header { padding: 16px 24px; border-bottom: 1px solid var(--border-color); background: #fafafa; display: flex; justify-content: space-between; align-items: center; }
        .card-header h3 { font-size: 18px; font-weight: 600; color: var(--text-color); }
        .card-body { padding: 24px; }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; }
        .form-control { width: 100%; padding: 10px 12px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px; transition: all 0.3s; }
        .form-control:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.2); }
        .form-control[readonly] { background: #f5f5f5; color: #8c8c8c; cursor: not-allowed; }

        .btn { padding: 8px 16px; border: 1px solid #d9d9d9; border-radius: 6px; cursor: pointer; font-size: 14px; transition: all 0.3s; background: #fff; }
        .btn-primary { background: var(--primary-color); color: white; border-color: var(--primary-color); }
        .btn-primary:hover { background: #40a9ff; border-color: #40a9ff; }
        .btn-secondary { color: #595959; } .btn-secondary:hover { color: var(--primary-color); border-color: var(--primary-color); }

        .message { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-weight: 500; }
        .message.success { background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f; }
        .message.error { background: #fff2f0; color: #ff4d4f; border: 1px solid #ffccc7; }

        .profile-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
            max-width: 800px; /* 限制最大寬度，讓版面更集中 */
            margin: 0 auto;
        }

        .form-actions { display: flex; gap: 8px; }

        @media (max-width: 768px) {
            .profile-grid {
                grid-template-columns: 1fr;
                max-width: 100%;
            }
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
                    <a href="index.php">首頁</a> / 個人資料
                </div>

                <?php if (isset($success_message)): ?>
                    <div class="message success"><?php echo htmlspecialchars($success_message); ?></div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                    <div class="message error"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>

                <div class="profile-grid">
                    <!-- 更新個人資料 -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-user-circle"></i> 個人資料</h3>
                            <button type="button" id="editProfileBtn" class="btn btn-secondary"><i class="fas fa-edit"></i> 更改資料</button>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="update_profile" value="1">
                                <div class="form-group">
                                    <label for="username">帳號</label>
                                    <input type="text" id="username" class="form-control" value="<?php echo htmlspecialchars($current_user['username']); ?>" readonly disabled>
                                </div>
                                <div class="form-group">
                                    <label for="name">姓名</label>
                                    <input type="text" id="name" name="name" class="form-control" value="<?php echo htmlspecialchars($current_user['name']); ?>" readonly required>
                                </div>
                                <div class="form-group">
                                    <label for="email">電子郵件</label>
                                    <input type="email" id="email" name="email" class="form-control" value="<?php echo htmlspecialchars($current_user['email']); ?>" readonly required>
                                </div>
                                <?php if (in_array($current_user['role'], ['teacher', '老師'])): ?>
                                <div class="form-group">
                                    <label for="department">科系</label>
                                    <input type="text" id="department" name="department" class="form-control" value="<?php echo htmlspecialchars($current_user['department'] ?? ''); ?>" readonly required>
                                </div>
                                <div class="form-group">
                                    <label for="phone">聯絡電話</label>
                                    <input type="tel" id="phone" name="phone" class="form-control" value="<?php echo htmlspecialchars($current_user['phone'] ?? ''); ?>" readonly required>
                                </div>
                                <?php endif; ?>
                                <div class="form-actions" id="formActions" style="display: none;">
                                    <button type="submit" class="btn btn-primary">儲存變更</button>
                                    <button type="button" id="cancelEditBtn" class="btn">取消</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const editBtn = document.getElementById('editProfileBtn');
        const cancelBtn = document.getElementById('cancelEditBtn');
        const formActions = document.getElementById('formActions');
        const nameInput = document.getElementById('name');
        const emailInput = document.getElementById('email');
        const departmentInput = document.getElementById('department');
        const phoneInput = document.getElementById('phone');

        const originalValues = {
            name: nameInput.value,
            email: emailInput.value,
            department: departmentInput ? departmentInput.value : null,
            phone: phoneInput ? phoneInput.value : null
        };

        function enterEditMode() {
            nameInput.readOnly = false;
            emailInput.readOnly = false;
            if (departmentInput) departmentInput.readOnly = false;
            if (phoneInput) phoneInput.readOnly = false;

            formActions.style.display = 'flex';
            editBtn.style.display = 'none';
            nameInput.focus();
        }

        function exitEditMode() {
            nameInput.readOnly = true;
            emailInput.readOnly = true;
            nameInput.value = originalValues.name;
            emailInput.value = originalValues.email;
            if (departmentInput) {
                departmentInput.readOnly = true;
                departmentInput.value = originalValues.department;
            }
            if (phoneInput) {
                phoneInput.readOnly = true;
                phoneInput.value = originalValues.phone;
            }
            formActions.style.display = 'none';
            editBtn.style.display = 'inline-flex';
        }

        editBtn.addEventListener('click', enterEditMode);
        cancelBtn.addEventListener('click', exitEditMode);
    });
    </script>
</body>
</html>