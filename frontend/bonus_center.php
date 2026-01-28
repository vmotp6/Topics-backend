<?php
require_once __DIR__ . '/session_config.php';
checkBackendLogin();

require_once '../../Topics-frontend/frontend/config.php';

$username = $_SESSION['username'] ?? '';

// 權限：使用 session_config.php 的 helper（支援中文/代碼角色），允許行政人員與管理員
$can_bonus_center = (isStaff() || isAdmin());
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

function currentAcademicYearRoc() {
    $y = (int)date('Y');
    $m = (int)date('n');
    return ($m >= 8) ? ($y - 1911) : ($y - 1912);
}

// 建立「屆別」設定表（若不存在）
try {
    $t = $conn->query("SHOW TABLES LIKE 'bonus_settings_yearly'");
    if ($t && $t->num_rows == 0) {
        $conn->query("CREATE TABLE bonus_settings_yearly (
            cohort_year INT PRIMARY KEY,
            amount INT NOT NULL DEFAULT 1500,
            updated_by VARCHAR(100) NOT NULL DEFAULT '',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
} catch (Exception $e) {
    // ignore
}

$current_year = currentAcademicYearRoc();

// 確保目前學年度有預設值
try {
    $chk = $conn->prepare("SELECT cohort_year FROM bonus_settings_yearly WHERE cohort_year = ? LIMIT 1");
    if ($chk) {
        $chk->bind_param('i', $current_year);
        $chk->execute();
        $r = $chk->get_result();
        $exists = ($r && $r->num_rows > 0);
        $chk->close();
        if (!$exists) {
            $ins = $conn->prepare("INSERT INTO bonus_settings_yearly (cohort_year, amount, updated_by) VALUES (?, 1500, ?)");
            if ($ins) {
                $ins->bind_param('is', $current_year, $username);
                @$ins->execute();
                $ins->close();
            }
        }
    }
} catch (Exception $e) {
    // ignore
}

$message = '';
$message_type = 'success';

// 更新金額（依屆別）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];

    if ($action === 'save_year_amount') {
        $year = isset($_POST['cohort_year']) ? (int)$_POST['cohort_year'] : 0;
        $amount = isset($_POST['amount']) ? (int)$_POST['amount'] : -1;

        if ($year <= 0) {
            $message = '屆別無效';
            $message_type = 'error';
        } elseif ($year < $current_year) {
            $message = '過去屆別不可更改金額（屆別 ' . $year . '）';
            $message_type = 'error';
        } elseif ($amount < 0) {
            $message = '金額必須為 0 或以上';
            $message_type = 'error';
        } else {
            try {
                $upd = $conn->prepare("INSERT INTO bonus_settings_yearly (cohort_year, amount, updated_by)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE amount = VALUES(amount), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP");
                if ($upd) {
                    $upd->bind_param('iis', $year, $amount, $username);
                    if ($upd->execute()) {
                        $message = '獎金金額已更新（屆別 ' . $year . '）';
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
    } elseif ($action === 'add_year') {
        $year = isset($_POST['cohort_year_new']) ? (int)$_POST['cohort_year_new'] : 0;
        $amount = isset($_POST['amount_new']) ? (int)$_POST['amount_new'] : -1;

        if ($year <= 0) {
            $message = '屆別無效';
            $message_type = 'error';
        } elseif ($year < $current_year) {
            $message = '過去屆別不可新增/更改金額（屆別 ' . $year . '）';
            $message_type = 'error';
        } elseif ($amount < 0) {
            $message = '金額必須為 0 或以上';
            $message_type = 'error';
        } else {
            try {
                $ins = $conn->prepare("INSERT INTO bonus_settings_yearly (cohort_year, amount, updated_by)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE amount = VALUES(amount), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP");
                if ($ins) {
                    $ins->bind_param('iis', $year, $amount, $username);
                    if ($ins->execute()) {
                        $message = '已新增/更新屆別 ' . $year . ' 的獎金金額';
                        $message_type = 'success';
                    } else {
                        $message = '新增失敗：' . $ins->error;
                        $message_type = 'error';
                    }
                    $ins->close();
                } else {
                    $message = '新增失敗：SQL 準備失敗';
                    $message_type = 'error';
                }
            } catch (Exception $e) {
                $message = '系統錯誤：' . $e->getMessage();
                $message_type = 'error';
            }
        }
    }
}

// 讀取目前學年度金額
$current_amount = 1500;
$updated_by = '';
$updated_at = '';
try {
    $stmt = $conn->prepare("SELECT amount, updated_by, updated_at FROM bonus_settings_yearly WHERE cohort_year = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $current_year);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            $current_amount = (int)($row['amount'] ?? 1500);
            $updated_by = (string)($row['updated_by'] ?? '');
            $updated_at = (string)($row['updated_at'] ?? '');
        }
        $stmt->close();
    }
} catch (Exception $e) {
    // ignore
}

// 讀取全部屆別
$year_rows = [];
try {
    $res = $conn->query("SELECT cohort_year, amount, updated_by, updated_at FROM bonus_settings_yearly ORDER BY cohort_year DESC");
    if ($res) $year_rows = $res->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    $year_rows = [];
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
        .btn:disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }
        .input:disabled {
            background: #fafafa;
            color: #8c8c8c;
            cursor: not-allowed;
        }
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
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 12px; border-bottom: 1px solid var(--border-color); font-size: 14px; text-align: left; }
        th { background: #fafafa; font-weight: 800; }
        .tag { display:inline-flex; align-items:center; padding: 2px 10px; border-radius: 999px; background: #f5f5f5; font-weight: 900; font-size: 12px; }
        .tag.primary { background: #e6f7ff; color: #1890ff; }
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
                        <h3><i class="fas fa-coins"></i> 目前學年度獎金金額 <span class="tag primary"><?php echo (int)$current_year; ?> 屆</span></h3>
                        <div class="amount">$<?php echo number_format((int)$current_amount); ?></div>
                        <div class="muted">最後更新：<?php echo htmlspecialchars($updated_at !== '' ? $updated_at : '—'); ?>（<?php echo htmlspecialchars($updated_by !== '' ? $updated_by : '—'); ?>）</div>
                        <div style="height: 14px;"></div>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="save_year_amount">
                            <input type="hidden" name="cohort_year" value="<?php echo (int)$current_year; ?>">
                            <div class="row">
                                <input class="input" type="number" name="amount" min="0" step="1" value="<?php echo (int)$current_amount; ?>" required>
                                <button class="btn" type="submit"><i class="fas fa-save"></i> 儲存</button>
                            </div>
                            <div class="muted" style="margin-top:10px;">提示：發送獎金時會以「目前學年度（<?php echo (int)$current_year; ?>）」的金額計算並寫入發送紀錄。</div>
                        </form>
                    </div>

                    <div class="card">
                        <h3><i class="fas fa-layer-group"></i> 各屆別獎金設定</h3>
                        <?php if (empty($year_rows)): ?>
                            <div class="muted">目前尚無設定。</div>
                        <?php else: ?>
                            <div style="overflow:auto;">
                                <table>
                                    <thead>
                                        <tr>
                                            <th style="min-width:90px;">屆別</th>
                                            <th style="min-width:140px;">金額</th>
                                            <th style="min-width:180px;">最後更新</th>
                                            <th style="min-width:120px;">操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($year_rows as $yr): ?>
                                            <?php
                                                $y = (int)($yr['cohort_year'] ?? 0);
                                                $a = (int)($yr['amount'] ?? 1500);
                                                $is_past = ($y > 0 && $y < (int)$current_year);
                                            ?>
                                            <tr>
                                                <td>
                                                    <span class="tag <?php echo $y === (int)$current_year ? 'primary' : ''; ?>">
                                                        <?php echo $y; ?> 屆
                                                    </span>
                                                </td>
                                                <td>$<?php echo number_format($a); ?></td>
                                                <td class="muted">
                                                    <?php echo htmlspecialchars((string)($yr['updated_at'] ?? '—')); ?>
                                                    （<?php echo htmlspecialchars((string)($yr['updated_by'] ?? '—')); ?>）
                                                </td>
                                                <td>
                                                    <?php if ($is_past): ?>
                                                        <div class="muted" style="font-weight:800;">過去屆別不可更改</div>
                                                    <?php else: ?>
                                                        <form method="POST" action="" class="row" style="gap:8px;">
                                                            <input type="hidden" name="action" value="save_year_amount">
                                                            <input type="hidden" name="cohort_year" value="<?php echo (int)$y; ?>">
                                                            <input class="input" style="width:140px;" type="number" name="amount" min="0" step="1" value="<?php echo (int)$a; ?>" required>
                                                            <button class="btn" type="submit"><i class="fas fa-save"></i> 更新</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>

                        <div style="height: 12px;"></div>
                        <div class="muted" style="margin-bottom:8px; font-weight:800;">新增/更新指定屆別</div>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="add_year">
                            <div class="row">
                                <input class="input" style="width:140px;" type="number" name="cohort_year_new" min="1" step="1" placeholder="例如 115" required>
                                <input class="input" style="width:180px;" type="number" name="amount_new" min="0" step="1" placeholder="金額" required>
                                <button class="btn secondary" type="submit"><i class="fas fa-plus"></i> 新增/更新</button>
                            </div>
                            <div class="muted" style="margin-top:10px;">注意：僅允許更新「目前屆別（<?php echo (int)$current_year; ?>）」與未來屆別；過去屆別不可更改。</div>
                        </form>

                        <div style="height: 14px;"></div>
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

