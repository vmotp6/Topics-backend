<?php
require_once __DIR__ . '/session_config.php';

checkBackendLogin();

require_once '../../Topics-frontend/frontend/config.php';

// 獲取使用者角色和用戶ID
$user_role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

// 權限判斷：主任和科助不能管理名單（不能管理名額）
$can_manage_list = in_array($user_role, ['ADM', 'STA']); // 只有管理員和學校行政可以管理

// 判斷是否為主任
$is_director = ($user_role === 'DI');
$user_department_code = null;

// 如果是主任，獲取其科系代碼
if ($is_director && $user_id > 0) {
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
        error_log('Error fetching user department: ' . $e->getMessage());
    }
}

// 主任可以審核分配給他的名單
$can_review = $can_manage_list || ($is_director && !empty($user_department_code));

$application_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = isset($_GET['action']) ? $_GET['action'] : 'view';

if ($application_id === 0) {
    header("Location: continued_admission_list.php");
    exit;
}

$conn = getDatabaseConnection();

// 查詢報名資料
$stmt = $conn->prepare("SELECT * FROM continued_admission WHERE ID = ?");
$stmt->bind_param("i", $application_id);
$stmt->execute();
$result = $stmt->get_result();
$application = $result->fetch_assoc();
$stmt->close();

if (!$application) {
    $conn->close();
    header("Location: continued_admission_list.php");
    exit;
}

// 檢查主任是否有權限審核此名單
if ($action === 'review') {
    if ($can_manage_list) {
        // 招生中心可以審核所有名單
        // 允許繼續
    } elseif ($is_director && !empty($user_department_code)) {
        // 主任只能審核分配給他的科系的名單
        $assigned_dept = $application['assigned_department'] ?? '';
        if ($assigned_dept !== $user_department_code) {
            // 沒有權限，重定向到查看頁面
            header("Location: continued_admission_detail.php?id=" . $application_id);
            exit;
        }
    } else {
        // 沒有權限，重定向到查看頁面
        header("Location: continued_admission_detail.php?id=" . $application_id);
        exit;
    }
}

// 查詢學校名稱
$school_name = '';
$school_code = $application['school'] ?? '';
if (!empty($school_code)) {
    $stmt = $conn->prepare("SELECT name, city, district FROM school_data WHERE school_code = ? LIMIT 1");
    $stmt->bind_param("s", $school_code);
    $stmt->execute();
    $school_result = $stmt->get_result();
    if ($school_row = $school_result->fetch_assoc()) {
        $school_name = $school_row['name'] . ' (' . ($school_row['city'] ?? '') . ($school_row['district'] ?? '') . ')';
    } else {
        $school_name = $school_code;
    }
    $stmt->close();
}

// 查詢地址資訊
$address_data = null;
$stmt = $conn->prepare("SELECT * FROM continued_admission_addres WHERE admission_id = ? LIMIT 1");
$stmt->bind_param("i", $application_id);
$stmt->execute();
$address_result = $stmt->get_result();
if ($address_row = $address_result->fetch_assoc()) {
    $address_data = $address_row;
}
$stmt->close();

// 查詢志願選擇
$choices = [];
$stmt = $conn->prepare("
    SELECT cac.choice_order, d.name as department_name, cac.department_code
    FROM continued_admission_choices cac
    LEFT JOIN departments d ON cac.department_code = d.code
    WHERE cac.application_id = ?
    ORDER BY cac.choice_order ASC
");
$stmt->bind_param("i", $application_id);
$stmt->execute();
$choices_result = $stmt->get_result();
while ($choice_row = $choices_result->fetch_assoc()) {
    $choices[] = $choice_row['department_name'] ?? $choice_row['department_code'];
}
$stmt->close();

// 查詢審核者名稱
$reviewer_name = '';
if (!empty($application['reviewer_id'])) {
    $stmt = $conn->prepare("SELECT name FROM user WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $application['reviewer_id']);
    $stmt->execute();
    $reviewer_result = $stmt->get_result();
    if ($reviewer_row = $reviewer_result->fetch_assoc()) {
        $reviewer_name = $reviewer_row['name'];
    }
    $stmt->close();
}

$conn->close();

$page_title = ($action === 'review') ? '續招報名審核 - ' . htmlspecialchars($application['name']) : '續招報名詳情 - ' . htmlspecialchars($application['name']);
$current_page = 'continued_admission_detail';

$documents = json_decode($application['documents'], true);

function formatAddress($addr) {
    if (!$addr) return '未填寫';
    $address_parts = [
        $addr['zip_code'] ?? '',
        $addr['address'] ?? ''
    ];
    return implode(' ', array_filter($address_parts));
}

function getStatusText($status) {
    // 支援資料庫狀態代碼和前端狀態值
    switch ($status) {
        case 'AP':
        case 'approved': return '錄取';
        case 'RE':
        case 'rejected': return '未錄取';
        case 'AD':
        case 'waitlist': return '備取';
        case 'PE':
        case 'pending': return '待審核';
        default: return '待審核';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    // 這裡可以加入一個非AJAX的表單提交處理，但為了體驗一致性，我們將使用AJAX
}

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
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: var(--background-color); color: var(--text-color); overflow-x: hidden; }
        .dashboard { display: flex; min-height: 100vh; }
        .content { padding: 24px; width: 100%; }
        .breadcrumb { margin-bottom: 16px; font-size: 16px; color: var(--text-secondary-color); }
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        
        .card { background: var(--card-background-color); border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); border: 1px solid var(--border-color); margin-bottom: 24px; }
        .card-header { padding: 16px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: #fafafa; }
        .card-header h3 { font-size: 18px; font-weight: 600; color: var(--text-color); }
        .card-body { padding: 24px; }

        .detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px; }
        .detail-section { background: #fafafa; padding: 20px; border-radius: 6px; border: 1px solid var(--border-color); text-align: left !important; }
        .detail-section h4 { font-size: 16px; margin-bottom: 16px; padding-bottom: 8px; border-bottom: 1px solid var(--border-color); text-align: left !important; }
        .detail-section > * { text-align: left !important; }
        .detail-item { display: grid; grid-template-columns: 120px 1fr; gap: 8px; margin-bottom: 12px; font-size: 14px; text-align: left !important; width: 100%; justify-items: start; }
        .detail-item-label { font-weight: 500; color: var(--text-secondary-color); text-align: right; }
        .detail-item-value { word-break: break-all; text-align: left !important; }
        .detail-item-value.long-text { white-space: pre-wrap; background: #fff; padding: 8px; border-radius: 4px; border: 1px solid #e8e8e8; text-align: left !important; }

        .document-list { list-style: none; padding: 0; }
        .document-list li { margin-bottom: 8px; }
        .document-list a { text-decoration: none; color: var(--primary-color); }
        .document-list a:hover { text-decoration: underline; }

        .btn-secondary {
            padding: 8px 16px; border: 1px solid #d9d9d9; border-radius: 6px; cursor: pointer; font-size: 14px;
            transition: all 0.3s; background: #fff; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
            color: #595959;
        }
        .btn-secondary:hover { background: #f5f5f5; border-color: #40a9ff; color: #40a9ff; }
        .btn-primary {
            padding: 8px 16px; border: 1px solid var(--primary-color); border-radius: 6px; cursor: pointer; font-size: 14px;
            transition: all 0.3s; background: var(--primary-color); color: white; display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-primary:hover { background: #40a9ff; border-color: #40a9ff; }

        .status-select {
            padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px;
        }
        .status-select:focus {
            outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.2);
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
                    <a href="index.php">首頁</a> / <a href="continued_admission_list.php">續招報名管理</a> / <?php echo ($action === 'review') ? '報名審核' : '報名詳情'; ?>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3><?php echo ($action === 'review') ? '報名審核' : '報名詳情'; ?> (編號: <?php echo $application['apply_no'] ?? $application['ID']; ?>)</h3>
                        <a href="continued_admission_list.php" class="btn-secondary"><i class="fas fa-arrow-left"></i> 返回列表</a>
                    </div>
                    <div class="card-body">
                        <div class="detail-grid">
                            <div class="detail-section" style="text-align: left !important;">
                                <h4 style="text-align: left !important;"><i class="fas fa-user"></i> 基本資料</h4>
                                <div class="detail-item" style="text-align: left !important; justify-items: start;"><span class="detail-item-label">報名編號:</span> <span class="detail-item-value" style="text-align: left !important;"><?php echo htmlspecialchars($application['apply_no'] ?? $application['ID']); ?></span></div>
                                <div class="detail-item" style="text-align: left !important; justify-items: start;"><span class="detail-item-label">姓名:</span> <span class="detail-item-value" style="text-align: left !important;"><?php echo htmlspecialchars($application['name']); ?></span></div>
                                <div class="detail-item" style="text-align: left !important; justify-items: start;"><span class="detail-item-label">身分證字號:</span> <span class="detail-item-value" style="text-align: left !important;"><?php echo htmlspecialchars($application['id_number']); ?></span></div>
                                <div class="detail-item" style="text-align: left !important; justify-items: start;"><span class="detail-item-label">准考證號碼:</span> <span class="detail-item-value" style="text-align: left !important;"><?php echo htmlspecialchars($application['exam_no'] ?: '未填寫'); ?></span></div>
                                <div class="detail-item" style="text-align: left !important; justify-items: start;"><span class="detail-item-label">生日:</span> <span class="detail-item-value" style="text-align: left !important;"><?php echo $application['birth_date'] ? date('Y/m/d', strtotime($application['birth_date'])) : '未填寫'; ?></span></div>
                                <div class="detail-item" style="text-align: left !important; justify-items: start;"><span class="detail-item-label">性別:</span> <span class="detail-item-value" style="text-align: left !important;"><?php echo ($application['gender'] == 1) ? '男' : '女'; ?></span></div>
                                <div class="detail-item" style="text-align: left !important; justify-items: start;"><span class="detail-item-label">外籍生:</span> <span class="detail-item-value" style="text-align: left !important;"><?php echo ($application['foreign_student'] == 1) ? '是' : '否'; ?></span></div>
                                <div class="detail-item" style="text-align: left !important; justify-items: start;"><span class="detail-item-label">室內電話:</span> <span class="detail-item-value" style="text-align: left !important;"><?php echo htmlspecialchars($application['phone'] ?: '未填寫'); ?></span></div>
                                <div class="detail-item" style="text-align: left !important; justify-items: start;"><span class="detail-item-label">行動電話:</span> <span class="detail-item-value" style="text-align: left !important;"><?php echo htmlspecialchars($application['mobile']); ?></span></div>
                                <div class="detail-item" style="text-align: left !important; justify-items: start;"><span class="detail-item-label">就讀國中:</span> <span class="detail-item-value" style="text-align: left !important;"><?php echo htmlspecialchars($school_name); ?></span></div>
                                <div class="detail-item" style="text-align: left !important; justify-items: start;"><span class="detail-item-label">戶籍地址:</span> <span class="detail-item-value" style="text-align: left !important;"><?php echo htmlspecialchars(formatAddress($address_data)); ?></span></div>
                                <div class="detail-item" style="text-align: left !important; justify-items: start;"><span class="detail-item-label">通訊地址:</span> <span class="detail-item-value" style="text-align: left !important;"><?php echo ($address_data && $address_data['same_address'] == 1) ? '同戶籍地址' : htmlspecialchars($address_data['contact_address'] ?? '未填寫'); ?></span></div>
                                <div class="detail-item" style="text-align: left !important; justify-items: start;"><span class="detail-item-label">監護人姓名:</span> <span class="detail-item-value" style="text-align: left !important;"><?php echo htmlspecialchars($application['guardian_name']); ?></span></div>
                                <div class="detail-item" style="text-align: left !important; justify-items: start;"><span class="detail-item-label">監護人室內電話:</span> <span class="detail-item-value" style="text-align: left !important;"><?php echo htmlspecialchars($application['guardian_phone'] ?: '未填寫'); ?></span></div>
                                <div class="detail-item" style="text-align: left !important; justify-items: start;"><span class="detail-item-label">監護人行動電話:</span> <span class="detail-item-value" style="text-align: left !important;"><?php echo htmlspecialchars($application['guardian_mobile']); ?></span></div>
                            </div>

                            <?php if (!($is_director && !empty($user_department_code))): ?>
                            <!-- 主任不顯示志願序 -->
                            <div class="detail-section">
                                <h4><i class="fas fa-star"></i> 志願序</h4>
                                <?php if (!empty($choices)): ?>
                                    <ol style="margin: 0; padding-left: 20px; text-align: left;">
                                        <?php foreach ($choices as $choice): ?>
                                            <li style="margin-bottom: 8px; text-align: left;"><?php echo htmlspecialchars($choice); ?></li>
                                        <?php endforeach; ?>
                                    </ol>
                                <?php else: ?>
                                    <p style="margin: 0; text-align: left;">未選擇志願</p>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <div class="detail-section">
                                <h4><i class="fas fa-pen"></i> 自傳/專長</h4>
                                <div class="detail-item" style="grid-template-columns: 1fr;">
                                    <span class="detail-item-label" style="text-align: left;">自傳/自我介紹:</span>
                                    <div class="detail-item-value long-text"><?php echo htmlspecialchars($application['self_intro']); ?></div>
                                </div>
                                <div class="detail-item" style="grid-template-columns: 1fr; margin-top: 16px;">
                                    <span class="detail-item-label" style="text-align: left;">興趣/專長:</span>
                                    <div class="detail-item-value long-text"><?php echo htmlspecialchars($application['skills']); ?></div>
                                </div>
                            </div>
                            <div class="detail-section" style="text-align: left !important;">
                                <h4 style="text-align: left !important;"><i class="fas fa-file-alt"></i> 上傳文件</h4>
                                <?php
                                $document_types = [
                                    'exam' => '114 年國中教育會考成績單',
                                    'skill' => '技藝教育課程結業證明',
                                    'leader' => '擔任班級幹部證明',
                                    'service' => '服務學習時數證明',
                                    'fitness' => '體適能成績證明',
                                    'contest' => '競賽成績證明',
                                    'other' => '其他相關證明文件'
                                ];
                                
                                // 將上傳的文件按類型組織
                                $documents_by_type = [];
                                if (!empty($documents) && is_array($documents)) {
                                    foreach ($documents as $doc) {
                                        $doc_type = $doc['type'] ?? '';
                                        if (!isset($documents_by_type[$doc_type])) {
                                            $documents_by_type[$doc_type] = [];
                                        }
                                        $documents_by_type[$doc_type][] = $doc;
                                    }
                                }
                                ?>
                                <ul class="document-list" style="list-style: none; padding: 0; margin: 0; text-align: left !important;">
                                    <?php foreach ($document_types as $type => $type_name): ?>
                                        <?php 
                                        $has_file = isset($documents_by_type[$type]) && !empty($documents_by_type[$type]);
                                        $first_doc = $has_file ? $documents_by_type[$type][0] : null;
                                        ?>
                                        <li style="margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #f0f0f0; text-align: left !important;">
                                            <div class="detail-item" style="text-align: left !important; justify-items: start; margin: 0; grid-template-columns: 200px 1fr; gap: 16px;">
                                                <span class="detail-item-label" style="text-align: right;"><?php echo htmlspecialchars($type_name); ?>:</span>
                                                <?php if ($has_file && $first_doc): ?>
                                                    <span class="detail-item-value" style="text-align: left !important;">
                                                        <a href="/Topics-frontend/frontend/<?php echo htmlspecialchars($first_doc['path']); ?>" target="_blank" style="color: var(--primary-color); text-decoration: none; font-weight: 500;">
                                                            <i class="fas fa-file"></i> <?php echo htmlspecialchars($type_name); ?>
                                                        </a>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="detail-item-value" style="text-align: left !important; color: var(--text-secondary-color);">無</span>
                                                <?php endif; ?>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>


                            
                            <?php if ($action !== 'review' && $application['status'] !== 'pending' && $application['status'] !== 'PE'): ?>
                            <div class="detail-section" style="background: #f6ffed; text-align: left;">
                                <h4 style="color: #52c41a; text-align: left;"><i class="fas fa-info-circle"></i> 審核</h4>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; text-align: left;">
                                    <div style="display: flex; flex-direction: column; gap: 4px; text-align: left;">
                                        <span class="detail-item-label" style="text-align: left;">審核結果:</span>
                                        <span style="font-weight: bold; color: #52c41a; text-align: left; font-size: 14px;"><?php echo getStatusText($application['status']); ?></span>
                                    </div>
                                    <?php if (!empty($reviewer_name)): ?>
                                    <div style="display: flex; flex-direction: column; gap: 4px; text-align: left;">
                                        <span class="detail-item-label" style="text-align: left;">審核老師:</span>
                                        <span style="text-align: left; font-size: 14px;"><?php echo htmlspecialchars($reviewer_name); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($application['reviewed_at'])): ?>
                                    <div style="display: flex; flex-direction: column; gap: 4px; text-align: left;">
                                        <span class="detail-item-label" style="text-align: left;">審核時間:</span>
                                        <span style="text-align: left; font-size: 14px;"><?php echo date('Y/m/d H:i', strtotime($application['reviewed_at'])); ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div style="margin-top: 16px; text-align: left;">
                                    <span class="detail-item-label" style="display: block; text-align: left; margin-bottom: 8px;">審核備註:</span>
                                    <div style="background: #fff; padding: 12px; border-radius: 6px; border: 1px solid #d9d9d9; white-space: pre-wrap; text-align: left;"><?php echo htmlspecialchars($application['review_notes'] ?: '無'); ?></div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($action === 'review'): ?>
                            <div class="detail-section" style="background: #e6f7ff; text-align: left;">
                                <h4 style="color: var(--primary-color); text-align: left;"><i class="fas fa-check-circle"></i> 審核</h4>
                                <form id="reviewForm" style="text-align: left;">
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; text-align: left;">
                                        <div style="display: flex; flex-direction: column; gap: 8px; text-align: left;">
                                            <span class="detail-item-label" style="text-align: left;">目前狀態:</span>
                                            <span id="currentStatusText" style="font-weight: bold; text-align: left; font-size: 14px;"><?php echo getStatusText($application['status']); ?></span>
                                        </div>
                                        <div style="display: flex; flex-direction: column; gap: 8px; text-align: left;">
                                            <label for="statusSelector" class="detail-item-label" style="text-align: left;">審核決定:</label>
                                            <select id="statusSelector" class="status-select" name="status" required style="width: 100%; text-align: left;">
                                                <option value="">請選擇審核結果</option>
                                                <option value="approved" <?php echo ($application['status'] === 'approved' || $application['status'] === 'AP') ? 'selected' : ''; ?>>錄取</option>
                                                <option value="rejected" <?php echo ($application['status'] === 'rejected' || $application['status'] === 'RE') ? 'selected' : ''; ?>>不錄取</option>
                                                <option value="waitlist" <?php echo ($application['status'] === 'waitlist' || $application['status'] === 'AD') ? 'selected' : ''; ?>>備取</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div style="margin-bottom: 20px; text-align: left;">
                                        <label for="reviewNotes" style="display: block; margin-bottom: 8px; font-weight: 500; text-align: left;">審核備註 (選填):</label>
                                        <textarea id="reviewNotes" name="review_notes" rows="4" style="width: 100%; padding: 8px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px; resize: vertical; text-align: left;" placeholder="請輸入審核意見或備註..."><?php echo htmlspecialchars($application['review_notes'] ?? ''); ?></textarea>
                                    </div>
                                    <div style="display: flex; gap: 12px; text-align: left;">
                                        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> 送出審核結果</button>
                                        <button type="button" onclick="history.back()" class="btn-secondary"><i class="fas fa-times"></i> 取消</button>
                                    </div>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 訊息提示框 -->
    <div id="toast" style="position: fixed; top: 20px; right: 20px; background-color: #333; color: white; padding: 15px 20px; border-radius: 8px; z-index: 9999; display: none; opacity: 0; transition: opacity 0.5s;"></div>

    <script>
    function showToast(message, isSuccess = true) {
        const toast = document.getElementById('toast');
        toast.textContent = message;
        toast.style.backgroundColor = isSuccess ? '#4CAF50' : '#f44336';
        toast.style.display = 'block';
        toast.style.opacity = 1;
        setTimeout(() => {
            toast.style.opacity = 0;
            setTimeout(() => { toast.style.display = 'none'; }, 500);
        }, 3000);
    }

    <?php if ($action === 'review'): ?>
    document.getElementById('reviewForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const applicationId = <?php echo $application_id; ?>;
        const formData = new FormData(this);
        const status = formData.get('status');
        const reviewNotes = formData.get('review_notes');
        
        if (!status) {
            showToast('請選擇審核結果', false);
            return;
        }

        fetch('/Topics-backend/frontend/update_admission_status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ 
                id: applicationId, 
                status: status,
                review_notes: reviewNotes
            }),
        })
        .then(response => {
            // 如果回應不成功 (例如 409 Conflict)，將整個 response 拋出到 catch 區塊處理
            if (!response.ok) {
                throw response;
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                showToast('審核結果已送出！');
                setTimeout(() => {
                    window.location.href = 'continued_admission_list.php';
                }, 1500);
            } else {
                showToast('審核失敗：' + (data.message || '未知錯誤'), false);
            }
        })
        .catch(error => {
            // 檢查 error 是否為 Response 物件
            if (error instanceof Response) {
                error.json().then(errorData => {
                    // 從後端 JSON 獲取錯誤訊息
                    showToast('操作失敗：' + (errorData.message || `伺服器錯誤 ${error.status}`), false);
                }).catch(() => {
                    // 如果解析 JSON 失敗，顯示通用錯誤
                    showToast(`操作失敗：伺服器錯誤 ${error.status} ${error.statusText}`, false);
                });
            } else {
                // 處理網路錯誤或其他非 Response 的錯誤
                console.error('Fetch Error:', error);
                showToast('操作失敗：' + error.message, false);
            }
        });
    });
    <?php endif; ?>
    </script>
</body>
</html>