<?php
require_once __DIR__ . '/session_config.php';
checkBackendLogin();

require_once '../../Topics-frontend/frontend/config.php';

$username = $_SESSION['username'] ?? '';
$user_role = $_SESSION['role'] ?? '';

// 權限：沿用審核結果可視權限（username=12 & role=STA），並允許管理員
$can_bonus_center = (($username === '12' && $user_role === 'STA') || ($user_role === 'ADM'));
if (!$can_bonus_center) {
    header('Location: index.php');
    exit();
}

$page_title = '獎金專區';
$current_page = 'bonus_center';

try {
    $conn = getDatabaseConnection();
} catch (Exception $e) {
    die("資料庫連接失敗: " . $e->getMessage());
}

// 建立設定表（若不存在）
try {
    $t = $conn->query("SHOW TABLES LIKE 'bonus_settings'");
    if ($t && $t->num_rows == 0) {
        $conn->query("CREATE TABLE bonus_settings (
            id INT PRIMARY KEY,
            amount INT NOT NULL DEFAULT 1500,
            updated_by VARCHAR(100) NOT NULL DEFAULT '',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    // 確保 id=1 的預設值存在
    $exists = $conn->query("SELECT id FROM bonus_settings WHERE id = 1 LIMIT 1");
    if (!$exists || $exists->num_rows == 0) {
        $stmt_ins = $conn->prepare("INSERT INTO bonus_settings (id, amount, updated_by) VALUES (1, 1500, ?)");
        if ($stmt_ins) {
            $stmt_ins->bind_param('s', $username);
            @$stmt_ins->execute();
            $stmt_ins->close();
        }
    }
} catch (Exception $e) {
    // ignore
}

$message = '';
$message_type = 'success';

// 更新金額
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_amount') {
    $amount = isset($_POST['amount']) ? intval($_POST['amount']) : -1;
    if ($amount < 0) {
        $message = '金額必須為 0 或以上';
        $message_type = 'error';
    } else {
        try {
            $upd = $conn->prepare("UPDATE bonus_settings SET amount = ?, updated_by = ? WHERE id = 1");
            if ($upd) {
                $upd->bind_param('is', $amount, $username);
                if ($upd->execute()) {
                    $message = '獎金金額已更新';
                    $message_type = 'success';
                } else {
                    $message = '更新失敗：' . $upd->error;
                    $message_type = 'error';
                }
                $upd->close();
            } else {
                $message = '更新失敗：SQL 準備失敗';
                $message_type = 'error';
            }
        } catch (Exception $e) {
            $message = '系統錯誤：' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// 讀取目前金額
$current_amount = 1500;
$updated_by = '';
$updated_at = '';
try {
    $res = $conn->query("SELECT amount, updated_by, updated_at FROM bonus_settings WHERE id = 1 LIMIT 1");
    if ($res && $row = $res->fetch_assoc()) {
        $current_amount = intval($row['amount'] ?? 1500);
        $updated_by = (string)($row['updated_by'] ?? '');
        $updated_at = (string)($row['updated_at'] ?? '');
    }
} catch (Exception $e) {
    // ignore
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - Topics 後台管理系統</title>
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
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: var(--background-color);
            color: var(--text-color);
        }
        .dashboard { display: flex; min-height: 100vh; }
        .content { padding: 24px; width: 100%; }
        .page-title { font-size: 20px; font-weight: 800; margin-bottom: 16px; display:flex; gap:10px; align-items:center; }
        .grid { display:grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .card {
            background: var(--card-background-color);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 18px 18px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .card h3 { font-size: 16px; margin-bottom: 12px; display:flex; gap:8px; align-items:center; }
        .muted { color: var(--text-secondary-color); font-size: 13px; }
        .amount {
            font-size: 32px;
            font-weight: 900;
            color: #52c41a;
            margin: 8px 0;
        }
        .row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
        .input {
            padding: 10px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 8px;
            font-size: 14px;
            width: 220px;
        }
        .btn {
            padding: 10px 14px;
            border-radius: 8px;
            border: 1px solid #1890ff;
            background: #1890ff;
            color: #fff;
            cursor: pointer;
            font-weight: 800;
            font-size: 14px;
            display:inline-flex;
            gap:8px;
            align-items:center;
        }
        .btn.secondary {
            background: #fff;
            color: #1890ff;
        }
        .btn:hover { filter: brightness(0.97); }
        .alert {
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 14px;
            border: 1px solid;
            font-weight: 700;
            font-size: 14px;
        }
        .alert.success { background: #f6ffed; border-color: #b7eb8f; color: #389e0d; }
        .alert.error { background: #fff2f0; border-color: #ffccc7; color: #cf1322; }
        @media (max-width: 900px) {
            .grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            <div class="content">
                <div class="page-title"><i class="fas fa-gift"></i> <?php echo htmlspecialchars($page_title); ?></div>

                <?php if ($message !== ''): ?>
                    <div class="alert <?php echo $message_type === 'error' ? 'error' : 'success'; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <div class="grid">
                    <div class="card">
                        <h3><i class="fas fa-coins"></i> 目前獎金金額</h3>
                        <div class="amount">$<?php echo number_format((int)$current_amount); ?></div>
                        <div class="muted">最後更新：<?php echo htmlspecialchars($updated_at !== '' ? $updated_at : '—'); ?>（<?php echo htmlspecialchars($updated_by !== '' ? $updated_by : '—'); ?>）</div>
                        <div style="height: 14px;"></div>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="save_amount">
                            <div class="row">
                                <input class="input" type="number" name="amount" min="0" step="1" value="<?php echo (int)$current_amount; ?>" required>
                                <button class="btn" type="submit"><i class="fas fa-save"></i> 儲存</button>
                            </div>
                            <div class="muted" style="margin-top:10px;">提示：此金額會在「獎金發送」寫入發送紀錄時一併保存。</div>
                        </form>
                    </div>

                    <div class="card">
                        <h3><i class="fas fa-list"></i> 常用功能</h3>
                        <div class="row">
                            <a class="btn secondary" href="bonus_send_list.php"><i class="fas fa-receipt"></i> 已發送獎金名單</a>
                            <a class="btn secondary" href="admission_recommend_list.php?view=pass"><i class="fas fa-check-circle"></i> 通過名單（可發送）</a>
                        </div>
                        <div class="muted" style="margin-top:10px;">若你需要匯出，請到「已發送獎金名單」頁右上角點「匯出EXCEL」。</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

