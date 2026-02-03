<?php
require_once __DIR__ . '/session_config.php';
checkBackendLogin();

require_once '../../Topics-frontend/frontend/config.php';
require_once __DIR__ . '/includes/continued_admission_ranking.php';

$user_role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

// 只有招生中心和主任可以查看
if (!in_array($user_role, ['ADM', 'STA', 'DI'], true)) {
    http_response_code(403);
    echo "權限不足";
    exit;
}

$application_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($application_id === 0) {
    header("Location: continued_admission_list.php?tab=ranking");
    exit;
}

$conn = getDatabaseConnection();

// 查詢報名資料
$stmt = $conn->prepare("SELECT * FROM continued_admission WHERE id = ?");
$stmt->bind_param("i", $application_id);
$stmt->execute();
$result = $stmt->get_result();
$application = $result->fetch_assoc();
$stmt->close();

if (!$application) {
    $conn->close();
    header("Location: continued_admission_list.php?tab=ranking");
    exit;
}

// 查詢評分資料（包含簽章）
// 透過 signature_id 關聯到 signatures 表取得圖片路徑（不在 scores 表重複存路徑）
$scores_stmt = $conn->prepare("
    SELECT cas.*, 
           u.name as reviewer_name,
           u.username as reviewer_username,
           s.signature_path,
           s.signature_filename
    FROM continued_admission_scores cas
    LEFT JOIN user u ON cas.reviewer_user_id = u.id
    LEFT JOIN signatures s ON cas.signature_id = s.id
    WHERE cas.application_id = ?
    ORDER BY cas.assignment_order ASC
");
$scores_stmt->bind_param("i", $application_id);
$scores_stmt->execute();
$scores_result = $scores_stmt->get_result();
$scores = [];
while ($row = $scores_result->fetch_assoc()) {
    $scores[$row['assignment_order']] = $row;
}
$scores_stmt->close();

// 查詢分配資料（獲取評審者資訊）
$assignments_stmt = $conn->prepare("
    SELECT caa.*, u.name as reviewer_name, u.username as reviewer_username
    FROM continued_admission_assignments caa
    LEFT JOIN user u ON caa.reviewer_user_id = u.id
    WHERE caa.application_id = ?
    ORDER BY caa.assignment_order ASC
");
$assignments_stmt->bind_param("i", $application_id);
$assignments_stmt->execute();
$assignments_result = $assignments_stmt->get_result();
$assignments = [];
while ($row = $assignments_result->fetch_assoc()) {
    $assignments[$row['assignment_order']] = $row;
}
$assignments_stmt->close();

// 計算平均分數
$score_info = calculateAverageScore($conn, $application_id);

// 獲取科系資訊
$dept_code = $application['assigned_department'] ?? '';
$dept_name = '';
if ($dept_code) {
    $dept_stmt = $conn->prepare("SELECT name FROM departments WHERE code = ?");
    $dept_stmt->bind_param("s", $dept_code);
    $dept_stmt->execute();
    $dept_result = $dept_stmt->get_result();
    if ($dept_row = $dept_result->fetch_assoc()) {
        $dept_name = $dept_row['name'];
    }
    $dept_stmt->close();
}

// 獲取錄取標準和名額
$quota_stmt = $conn->prepare("SELECT cutoff_score, total_quota FROM department_quotas WHERE department_code = ? AND is_active = 1 LIMIT 1");
$quota_stmt->bind_param("s", $dept_code);
$quota_stmt->execute();
$quota_result = $quota_stmt->get_result();
$cutoff_score = 0;
$total_quota = 0;
if ($quota_row = $quota_result->fetch_assoc()) {
    $cutoff_score = (float)$quota_row['cutoff_score'];
    $total_quota = (int)$quota_row['total_quota'];
}
$quota_stmt->close();

// 獲取學校名稱
$school_name = '';
if (!empty($application['school'])) {
    // 檢查 school_data 表是否存在
    $school_table_check = $conn->query("SHOW TABLES LIKE 'school_data'");
    $has_school_table = ($school_table_check && $school_table_check->num_rows > 0);
    
    if ($has_school_table) {
        $school_stmt = $conn->prepare("SELECT name, city, district FROM school_data WHERE school_code = ? LIMIT 1");
        $school_stmt->bind_param("s", $application['school']);
        $school_stmt->execute();
        $school_result = $school_stmt->get_result();
        if ($school_row = $school_result->fetch_assoc()) {
            $school_name = $school_row['name'];
            if (!empty($school_row['city']) || !empty($school_row['district'])) {
                $school_name .= ' (' . ($school_row['city'] ?? '') . ($school_row['district'] ?? '') . ')';
            }
        } else {
            $school_name = $application['school']; // 如果找不到，顯示代碼
        }
        $school_stmt->close();
    } else {
        // 如果表不存在，直接使用 school 欄位的值
        $school_name = $application['school'];
    }
}

// 處理身分證字號顯示（如果是外籍生，顯示護照號碼）
$id_display = $application['id_number'] ?? '未填寫';
if (strpos($id_display, 'PASSPORT_') === 0) {
    $id_display = '護照號碼：' . substr($id_display, 10);
}

// 處理外籍生標記
$is_foreign = false;
if (isset($application['foreign_student'])) {
    $is_foreign = (int)$application['foreign_student'] === 1;
} elseif (isset($application['is_foreign_student'])) {
    $is_foreign = (int)$application['is_foreign_student'] === 1;
}

$conn->close();

$page_title = "評分詳情 - " . htmlspecialchars($application['name']);
$current_page = 'continued_admission_list';
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
            --success-color: #52c41a; --danger-color: #f5222d; --warning-color: #faad14;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: var(--background-color); color: var(--text-color); }
        .dashboard { display: flex; min-height: 100vh; }
        .content { padding: 24px; width: 100%; }
        .breadcrumb { margin-bottom: 16px; font-size: 16px; color: var(--text-secondary-color); }
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        .card { background: var(--card-background-color); border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); border: 1px solid var(--border-color); margin-bottom: 24px; }
        .card-header { padding: 16px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: #fafafa; }
        .card-header h3 { font-size: 18px; font-weight: 600; color: var(--text-color); }
        .card-body { padding: 24px; }
        .btn-secondary { padding: 8px 16px; border: 1px solid #d9d9d9; border-radius: 6px; cursor: pointer; font-size: 14px; transition: all 0.3s; background: #fff; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; color: #595959; }
        .btn-secondary:hover { background: #f5f5f5; border-color: #40a9ff; color: #40a9ff; }
        .score-section { margin-bottom: 32px; padding: 20px; background: #fafafa; border-radius: 8px; border: 1px solid var(--border-color); }
        .score-section h4 { margin-bottom: 16px; color: var(--text-color); font-size: 16px; }
        .score-detail { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 16px; }
        .score-item { background: white; padding: 16px; border-radius: 6px; border: 1px solid #e8e8e8; }
        .score-item-label { font-size: 12px; color: var(--text-secondary-color); margin-bottom: 8px; }
        .score-item-value { font-size: 24px; font-weight: bold; color: var(--primary-color); }
        .signature-display { margin-top: 16px; padding: 16px; background: white; border-radius: 6px; border: 2px solid var(--success-color); text-align: center; }
        .signature-display img { max-width: 100%; max-height: 200px; border: 1px solid #d9d9d9; border-radius: 4px; padding: 8px; background: white; }
        .reviewer-info { font-size: 14px; color: var(--text-secondary-color); margin-top: 8px; }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            <div class="content">
                <div class="breadcrumb">
                    <a href="index.php">首頁</a> / 
                    <a href="continued_admission_list.php?tab=ranking">達到錄取標準名單</a> / 
                    評分詳情
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>評分詳情 - <?php echo htmlspecialchars($application['name']); ?> (<?php echo htmlspecialchars($application['apply_no'] ?? $application['id']); ?>)</h3>
                        <a href="continued_admission_list.php?tab=ranking" class="btn-secondary">
                            <i class="fas fa-arrow-left"></i> 返回列表
                        </a>
                    </div>
                    <div class="card-body">
                        <!-- 基本資訊 -->
                        <div style="margin-bottom: 24px; padding: 20px; background: #fafafa; border-radius: 6px;">
                            <h4 style="margin-bottom: 16px; color: var(--text-color); font-size: 16px; font-weight: 600;">
                                <i class="fas fa-user"></i> 學生基本資料
                            </h4>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 16px;">
                                <div>
                                    <div style="font-size: 12px; color: var(--text-secondary-color); margin-bottom: 4px;">報名編號</div>
                                    <div style="font-size: 16px; font-weight: 500;"><?php echo htmlspecialchars($application['apply_no'] ?? $application['id']); ?></div>
                                </div>
                                <div>
                                    <div style="font-size: 12px; color: var(--text-secondary-color); margin-bottom: 4px;">姓名</div>
                                    <div style="font-size: 16px; font-weight: 500;"><?php echo htmlspecialchars($application['name']); ?></div>
                                </div>
                                <div>
                                    <div style="font-size: 12px; color: var(--text-secondary-color); margin-bottom: 4px;"><?php echo $is_foreign ? '護照號碼' : '身分證字號'; ?></div>
                                    <div style="font-size: 16px; font-weight: 500;"><?php echo htmlspecialchars($id_display); ?></div>
                                </div>
                                <div>
                                    <div style="font-size: 12px; color: var(--text-secondary-color); margin-bottom: 4px;">准考證號碼</div>
                                    <div style="font-size: 16px; font-weight: 500;"><?php echo htmlspecialchars($application['exam_no'] ?: '未填寫'); ?></div>
                                </div>
                                <div>
                                    <div style="font-size: 12px; color: var(--text-secondary-color); margin-bottom: 4px;">生日</div>
                                    <div style="font-size: 16px; font-weight: 500;"><?php echo $application['birth_date'] ? date('Y/m/d', strtotime($application['birth_date'])) : '未填寫'; ?></div>
                                </div>
                                <div>
                                    <div style="font-size: 12px; color: var(--text-secondary-color); margin-bottom: 4px;">性別</div>
                                    <div style="font-size: 16px; font-weight: 500;"><?php echo (isset($application['gender']) && (int)$application['gender'] === 1) ? '男' : '女'; ?></div>
                                </div>
                                <div>
                                    <div style="font-size: 12px; color: var(--text-secondary-color); margin-bottom: 4px;">外籍生</div>
                                    <div style="font-size: 16px; font-weight: 500;"><?php echo $is_foreign ? '是' : '否'; ?></div>
                                </div>
                                <div>
                                    <div style="font-size: 12px; color: var(--text-secondary-color); margin-bottom: 4px;">室內電話</div>
                                    <div style="font-size: 16px; font-weight: 500;"><?php echo htmlspecialchars($application['phone'] ?: '未填寫'); ?></div>
                                </div>
                                <div>
                                    <div style="font-size: 12px; color: var(--text-secondary-color); margin-bottom: 4px;">行動電話</div>
                                    <div style="font-size: 16px; font-weight: 500;"><?php echo htmlspecialchars($application['mobile'] ?: '未填寫'); ?></div>
                                </div>
                                <div>
                                    <div style="font-size: 12px; color: var(--text-secondary-color); margin-bottom: 4px;">就讀國中</div>
                                    <div style="font-size: 16px; font-weight: 500;"><?php echo htmlspecialchars($school_name ?: ($application['school'] ?? '未填寫')); ?></div>
                                </div>
                                <div>
                                    <div style="font-size: 12px; color: var(--text-secondary-color); margin-bottom: 4px;">分配科系</div>
                                    <div style="font-size: 16px; font-weight: 500;"><?php echo htmlspecialchars($dept_name ?: $dept_code); ?></div>
                                </div>
                                <div>
                                    <div style="font-size: 12px; color: var(--text-secondary-color); margin-bottom: 4px;">平均分數</div>
                                    <?php 
                                    // 根據分數決定顏色：>= 80 綠色，>= 60 橙色，< 60 紅色（與老師評分頁面邏輯一致）
                                    $avg_score = $score_info['average_score'];
                                    $score_color = '#f5222d'; // 預設紅色
                                    if ($avg_score >= 80) {
                                        $score_color = '#52c41a'; // 綠色
                                    } elseif ($avg_score >= 60) {
                                        $score_color = '#faad14'; // 橙色
                                    }
                                    ?>
                                    <div style="font-size: 20px; font-weight: bold; color: <?php echo $score_color; ?>;">
                                        <?php echo number_format($score_info['average_score'], 2); ?> 分
                                    </div>
                                </div>
                            </div>
                            
                            <!-- 監護人資訊 -->
                            <?php if (!empty($application['guardian_name']) || !empty($application['guardian_phone']) || !empty($application['guardian_mobile'])): ?>
                            <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e8e8e8;">
                                <h5 style="margin-bottom: 12px; color: var(--text-color); font-size: 14px; font-weight: 600;">監護人資訊</h5>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                                    <?php if (!empty($application['guardian_name'])): ?>
                                    <div>
                                        <div style="font-size: 12px; color: var(--text-secondary-color); margin-bottom: 4px;">監護人姓名</div>
                                        <div style="font-size: 16px; font-weight: 500;"><?php echo htmlspecialchars($application['guardian_name']); ?></div>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($application['guardian_phone'])): ?>
                                    <div>
                                        <div style="font-size: 12px; color: var(--text-secondary-color); margin-bottom: 4px;">監護人電話</div>
                                        <div style="font-size: 16px; font-weight: 500;"><?php echo htmlspecialchars($application['guardian_phone']); ?></div>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($application['guardian_mobile'])): ?>
                                    <div>
                                        <div style="font-size: 12px; color: var(--text-secondary-color); margin-bottom: 4px;">監護人手機</div>
                                        <div style="font-size: 16px; font-weight: 500;"><?php echo htmlspecialchars($application['guardian_mobile']); ?></div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- 自傳和專長 -->
                            <?php if (!empty($application['self_intro']) || !empty($application['skills'])): ?>
                            <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e8e8e8;">
                                <h5 style="margin-bottom: 12px; color: var(--text-color); font-size: 14px; font-weight: 600;">學生自述</h5>
                                <?php if (!empty($application['self_intro'])): ?>
                                <div style="margin-bottom: 12px;">
                                    <div style="font-size: 12px; color: var(--text-secondary-color); margin-bottom: 4px;">自傳/自我介紹</div>
                                    <div style="font-size: 14px; line-height: 1.6; padding: 12px; background: white; border-radius: 4px; border: 1px solid #e8e8e8; white-space: pre-wrap; word-wrap: break-word;"><?php echo htmlspecialchars($application['self_intro']); ?></div>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($application['skills'])): ?>
                                <div>
                                    <div style="font-size: 12px; color: var(--text-secondary-color); margin-bottom: 4px;">興趣/專長</div>
                                    <div style="font-size: 14px; line-height: 1.6; padding: 12px; background: white; border-radius: 4px; border: 1px solid #e8e8e8; white-space: pre-wrap; word-wrap: break-word;"><?php echo htmlspecialchars($application['skills']); ?></div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- 老師1評分 -->
                        <?php if (isset($scores[1])): 
                            $score = $scores[1];
                            $assignment = $assignments[1] ?? null;
                            $total_score = (int)$score['self_intro_score'] + (int)$score['skills_score'];
                        ?>
                        <div class="score-section">
                            <h4><i class="fas fa-user-tie"></i> 老師1評分</h4>
                            <div class="reviewer-info">
                                評審者：<?php echo htmlspecialchars($assignment['reviewer_name'] ?? $score['reviewer_name'] ?? '未知'); ?>
                                <?php if ($assignment['reviewer_username'] ?? $score['reviewer_username'] ?? ''): ?>
                                    (<?php echo htmlspecialchars($assignment['reviewer_username'] ?? $score['reviewer_username']); ?>)
                                <?php endif; ?>
                                <?php if ($score['scored_at']): ?>
                                    | 評分時間：<?php echo date('Y-m-d H:i:s', strtotime($score['scored_at'])); ?>
                                <?php endif; ?>
                            </div>
                            <div class="score-detail">
                                <div class="score-item">
                                    <div class="score-item-label">自傳/自我介紹</div>
                                    <div class="score-item-value"><?php echo (int)$score['self_intro_score']; ?> / 80</div>
                                </div>
                                <div class="score-item">
                                    <div class="score-item-label">興趣/專長</div>
                                    <div class="score-item-value"><?php echo (int)$score['skills_score']; ?> / 20</div>
                                </div>
                                <div class="score-item">
                                    <div class="score-item-label">總分</div>
                                    <?php 
                                    // 根據總分決定顏色：>= 80 綠色，>= 60 橙色，< 60 紅色（與老師評分頁面邏輯一致）
                                    $color = '#f5222d'; // 預設紅色
                                    if ($total_score >= 80) {
                                        $color = '#52c41a'; // 綠色
                                    } elseif ($total_score >= 60) {
                                        $color = '#faad14'; // 橙色
                                    }
                                    ?>
                                    <div class="score-item-value" style="color: <?php echo $color; ?>;"><?php echo $total_score; ?> / 100</div>
                                </div>
                            </div>
                            <?php 
                            // 處理簽章路徑
                            $signature_display_path = null;
                            if (!empty($score['signature_path'])) {
                                $signature_display_path = $score['signature_path'];
                                // 如果是相對路徑，確保格式正確
                                if (strpos($signature_display_path, 'http') !== 0 && strpos($signature_display_path, '/') !== 0) {
                                    // 相對路徑
                                    if (strpos($signature_display_path, 'uploads/signatures/') !== 0) {
                                        // 如果沒有 uploads/signatures/ 前綴，加上它
                                        $signature_display_path = 'uploads/signatures/' . basename($signature_display_path);
                                    }
                                }
                            }
                            ?>
                            <?php if ($signature_display_path): ?>
                            <div class="signature-display">
                                <div style="font-size: 14px; color: #52c41a; margin-bottom: 12px; font-weight: 500;">
                                    <i class="fas fa-file-signature"></i> 電子簽章
                                </div>
                                <img src="<?php echo htmlspecialchars($signature_display_path); ?>" alt="老師1簽章" style="max-width: 100%; max-height: 200px; border: 1px solid #d9d9d9; border-radius: 4px; padding: 8px; background: white;" onerror="console.error('簽章圖片載入失敗:', this.src); this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'200\' height=\'100\'%3E%3Ctext x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\' fill=\'%23999\'%3E簽章圖片載入失敗%3C/text%3E%3C/svg%3E';">
                                <div style="margin-top: 8px; font-size: 12px; color: #8c8c8c;">
                                    路徑：<?php echo htmlspecialchars($score['signature_path']); ?>
                                </div>
                            </div>
                            <?php else: ?>
                            <div style="margin-top: 16px; padding: 12px; background: #fffbe6; border: 1px solid #ffe58f; border-radius: 6px; text-align: center; color: #ad6800;">
                                <i class="fas fa-info-circle"></i> 尚未簽章
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- 老師2評分 -->
                        <?php if (isset($scores[2])): 
                            $score = $scores[2];
                            $assignment = $assignments[2] ?? null;
                            $total_score = (int)$score['self_intro_score'] + (int)$score['skills_score'];
                        ?>
                        <div class="score-section">
                            <h4><i class="fas fa-user-tie"></i> 老師2評分</h4>
                            <div class="reviewer-info">
                                評審者：<?php echo htmlspecialchars($assignment['reviewer_name'] ?? $score['reviewer_name'] ?? '未知'); ?>
                                <?php if ($assignment['reviewer_username'] ?? $score['reviewer_username'] ?? ''): ?>
                                    (<?php echo htmlspecialchars($assignment['reviewer_username'] ?? $score['reviewer_username']); ?>)
                                <?php endif; ?>
                                <?php if ($score['scored_at']): ?>
                                    | 評分時間：<?php echo date('Y-m-d H:i:s', strtotime($score['scored_at'])); ?>
                                <?php endif; ?>
                            </div>
                            <div class="score-detail">
                                <div class="score-item">
                                    <div class="score-item-label">自傳/自我介紹</div>
                                    <div class="score-item-value"><?php echo (int)$score['self_intro_score']; ?> / 80</div>
                                </div>
                                <div class="score-item">
                                    <div class="score-item-label">興趣/專長</div>
                                    <div class="score-item-value"><?php echo (int)$score['skills_score']; ?> / 20</div>
                                </div>
                                <div class="score-item">
                                    <div class="score-item-label">總分</div>
                                    <?php 
                                    // 根據總分決定顏色：>= 80 綠色，>= 60 橙色，< 60 紅色（與老師評分頁面邏輯一致）
                                    $color = '#f5222d'; // 預設紅色
                                    if ($total_score >= 80) {
                                        $color = '#52c41a'; // 綠色
                                    } elseif ($total_score >= 60) {
                                        $color = '#faad14'; // 橙色
                                    }
                                    ?>
                                    <div class="score-item-value" style="color: <?php echo $color; ?>;"><?php echo $total_score; ?> / 100</div>
                                </div>
                            </div>
                            <?php 
                            // 處理簽章路徑
                            $signature_display_path = null;
                            if (!empty($score['signature_path'])) {
                                $signature_display_path = $score['signature_path'];
                                // 如果是相對路徑，確保格式正確
                                if (strpos($signature_display_path, 'http') !== 0 && strpos($signature_display_path, '/') !== 0) {
                                    // 相對路徑
                                    if (strpos($signature_display_path, 'uploads/signatures/') !== 0) {
                                        // 如果沒有 uploads/signatures/ 前綴，加上它
                                        $signature_display_path = 'uploads/signatures/' . basename($signature_display_path);
                                    }
                                }
                            }
                            ?>
                            <?php if ($signature_display_path): ?>
                            <div class="signature-display">
                                <div style="font-size: 14px; color: #52c41a; margin-bottom: 12px; font-weight: 500;">
                                    <i class="fas fa-file-signature"></i> 電子簽章
                                </div>
                                <img src="<?php echo htmlspecialchars($signature_display_path); ?>" alt="老師2簽章" style="max-width: 100%; max-height: 200px; border: 1px solid #d9d9d9; border-radius: 4px; padding: 8px; background: white;" onerror="console.error('簽章圖片載入失敗:', this.src); this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'200\' height=\'100\'%3E%3Ctext x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\' fill=\'%23999\'%3E簽章圖片載入失敗%3C/text%3E%3C/svg%3E';">
                                <div style="margin-top: 8px; font-size: 12px; color: #8c8c8c;">
                                    路徑：<?php echo htmlspecialchars($score['signature_path']); ?>
                                </div>
                            </div>
                            <?php else: ?>
                            <div style="margin-top: 16px; padding: 12px; background: #fffbe6; border: 1px solid #ffe58f; border-radius: 6px; text-align: center; color: #ad6800;">
                                <i class="fas fa-info-circle"></i> 尚未簽章
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- 主任評分 -->
                        <?php if (isset($scores[3])): 
                            $score = $scores[3];
                            $assignment = $assignments[3] ?? null;
                            $total_score = (int)$score['self_intro_score'] + (int)$score['skills_score'];
                        ?>
                        <div class="score-section">
                            <h4><i class="fas fa-user-shield"></i> 主任評分</h4>
                            <div class="reviewer-info">
                                評審者：<?php echo htmlspecialchars($assignment['reviewer_name'] ?? $score['reviewer_name'] ?? '未知'); ?>
                                <?php if ($assignment['reviewer_username'] ?? $score['reviewer_username'] ?? ''): ?>
                                    (<?php echo htmlspecialchars($assignment['reviewer_username'] ?? $score['reviewer_username']); ?>)
                                <?php endif; ?>
                                <?php if ($score['scored_at']): ?>
                                    | 評分時間：<?php echo date('Y-m-d H:i:s', strtotime($score['scored_at'])); ?>
                                <?php endif; ?>
                            </div>
                            <div class="score-detail">
                                <div class="score-item">
                                    <div class="score-item-label">自傳/自我介紹</div>
                                    <div class="score-item-value"><?php echo (int)$score['self_intro_score']; ?> / 80</div>
                                </div>
                                <div class="score-item">
                                    <div class="score-item-label">興趣/專長</div>
                                    <div class="score-item-value"><?php echo (int)$score['skills_score']; ?> / 20</div>
                                </div>
                                <div class="score-item">
                                    <div class="score-item-label">總分</div>
                                    <?php 
                                    // 根據總分決定顏色：>= 80 綠色，>= 60 橙色，< 60 紅色（與老師評分頁面邏輯一致）
                                    $color = '#f5222d'; // 預設紅色
                                    if ($total_score >= 80) {
                                        $color = '#52c41a'; // 綠色
                                    } elseif ($total_score >= 60) {
                                        $color = '#faad14'; // 橙色
                                    }
                                    ?>
                                    <div class="score-item-value" style="color: <?php echo $color; ?>;"><?php echo $total_score; ?> / 100</div>
                                </div>
                            </div>
                            <?php 
                            // 處理簽章路徑
                            $signature_display_path = null;
                            if (!empty($score['signature_path'])) {
                                $signature_display_path = $score['signature_path'];
                                // 如果是相對路徑，確保格式正確
                                if (strpos($signature_display_path, 'http') !== 0 && strpos($signature_display_path, '/') !== 0) {
                                    // 相對路徑
                                    if (strpos($signature_display_path, 'uploads/signatures/') !== 0) {
                                        // 如果沒有 uploads/signatures/ 前綴，加上它
                                        $signature_display_path = 'uploads/signatures/' . basename($signature_display_path);
                                    }
                                }
                            }
                            ?>
                            <?php if ($signature_display_path): ?>
                            <div class="signature-display">
                                <div style="font-size: 14px; color: #52c41a; margin-bottom: 12px; font-weight: 500;">
                                    <i class="fas fa-file-signature"></i> 電子簽章
                                </div>
                                <img src="<?php echo htmlspecialchars($signature_display_path); ?>" alt="主任簽章" style="max-width: 100%; max-height: 200px; border: 1px solid #d9d9d9; border-radius: 4px; padding: 8px; background: white;" onerror="console.error('簽章圖片載入失敗:', this.src); this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'200\' height=\'100\'%3E%3Ctext x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\' fill=\'%23999\'%3E簽章圖片載入失敗%3C/text%3E%3C/svg%3E';">
                                <div style="margin-top: 8px; font-size: 12px; color: #8c8c8c;">
                                    路徑：<?php echo htmlspecialchars($score['signature_path']); ?>
                                </div>
                            </div>
                            <?php else: ?>
                            <div style="margin-top: 16px; padding: 12px; background: #fffbe6; border: 1px solid #ffe58f; border-radius: 6px; text-align: center; color: #ad6800;">
                                <i class="fas fa-info-circle"></i> 尚未簽章
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

