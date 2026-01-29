<?php
require_once __DIR__ . '/session_config.php';

checkBackendLogin();

require_once '../../Topics-frontend/frontend/config.php';
require_once __DIR__ . '/includes/continued_admission_auto_assign.php';

// 獲取使用者角色和用戶ID
$user_role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

// 權限判斷：主任和科助不能管理名單（不能管理名額）
$can_manage_list = in_array($user_role, ['ADM', 'STA']); // 只有管理員和學校行政可以管理

// 判斷是否為主任
$is_director = ($user_role === 'DI');
// 判斷是否為一般老師（支援 'TE', 'TEA', '老師'）
$is_teacher = ($user_role === 'TE' || $user_role === 'TEA' || $user_role === '老師');
$user_department_code = null;

// 如果是主任或老師，獲取其科系代碼
if (($is_director || $is_teacher) && $user_id > 0) {
    try {
        $conn_temp = getDatabaseConnection();
        if ($is_director) {
            // 主任從 director 表查詢
            $table_check = $conn_temp->query("SHOW TABLES LIKE 'director'");
            if ($table_check && $table_check->num_rows > 0) {
                $stmt_dept = $conn_temp->prepare("SELECT department FROM director WHERE user_id = ?");
            } else {
                // 如果沒有 director 表，從 teacher 表查詢（向後兼容）
                $stmt_dept = $conn_temp->prepare("SELECT department FROM teacher WHERE user_id = ?");
            }
        } else {
            // 老師直接從 teacher 表查詢
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
// 老師和主任都可以評分被分配的學生
$can_score = ($is_teacher && !empty($user_id)) || ($is_director && !empty($user_department_code));

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

// 獲取審查書面資料時間（從 department_quotas 表）
$review_start = null;
$review_end = null;
$score_deadline = null; // 根據志願序計算的評分截止時間
$current_choice_order = null;
$assigned_dept = $application['assigned_department'] ?? '';
if (!empty($assigned_dept)) {
    $time_stmt = $conn->prepare("SELECT review_start, review_end FROM department_quotas WHERE department_code = ? AND is_active = 1 LIMIT 1");
    $time_stmt->bind_param("s", $assigned_dept);
    $time_stmt->execute();
    $time_result = $time_stmt->get_result();
    if ($time_row = $time_result->fetch_assoc()) {
        $review_start = $time_row['review_start'];
        $review_end = $time_row['review_end'];
    }
    $time_stmt->close();
    
    // 獲取當前志願序並計算評分截止時間
    $current_choice_order = getCurrentChoiceOrder($conn, $application_id);
    if ($current_choice_order && $review_start) {
        $score_deadline = calculateScoreDeadline($review_start, $current_choice_order);
    }
    
    // 如果沒有志願序，使用 review_start 和 review_end 作為評分時間範圍
    if (!$current_choice_order && $review_start && $review_end) {
        // 使用原始的審查時間範圍
        $score_deadline = $review_end;
    }
}

// 檢查是否使用正規化表
$has_normalized_tables = false;
$assign_table_check = $conn->query("SHOW TABLES LIKE 'continued_admission_assignments'");
$score_table_check = $conn->query("SHOW TABLES LIKE 'continued_admission_scores'");
if ($assign_table_check && $assign_table_check->num_rows > 0 && 
    $score_table_check && $score_table_check->num_rows > 0) {
    $has_normalized_tables = true;
}

// 從正規化表獲取分配和評分資訊
$application['assignments'] = [];
$application['scores'] = [];
if ($has_normalized_tables) {
    // 獲取分配資訊
    $assign_stmt = $conn->prepare("
        SELECT reviewer_user_id, reviewer_type, assignment_order, assigned_at
        FROM continued_admission_assignments
        WHERE application_id = ?
        ORDER BY assignment_order ASC
    ");
    $assign_stmt->bind_param("i", $application_id);
    $assign_stmt->execute();
    $assign_result = $assign_stmt->get_result();
    while ($assign_row = $assign_result->fetch_assoc()) {
        $application['assignments'][$assign_row['assignment_order']] = $assign_row;
    }
    $assign_stmt->close();
    
    // 獲取評分資訊
    $score_stmt = $conn->prepare("
        SELECT reviewer_user_id, reviewer_type, assignment_order, self_intro_score, skills_score, scored_at
        FROM continued_admission_scores
        WHERE application_id = ?
        ORDER BY assignment_order ASC
    ");
    $score_stmt->bind_param("i", $application_id);
    $score_stmt->execute();
    $score_result = $score_stmt->get_result();
    while ($score_row = $score_result->fetch_assoc()) {
        $application['scores'][$score_row['assignment_order']] = $score_row;
    }
    $score_stmt->close();
}

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

// 檢查老師或主任是否有權限評分此名單
$teacher_slot = null; // 1, 2, 或 3（3=主任）
if ($action === 'score') {
    $assigned_dept = $application['assigned_department'] ?? '';
    
    // 從 URL 參數獲取 slot
    $url_slot = $_GET['slot'] ?? null;
    
    if ($has_normalized_tables) {
        // 使用正規化表檢查
        $assignment_found = false;
        $user_assignments = []; // 記錄當前用戶的所有分配
        
        // 先找出當前用戶的所有分配
        foreach ($application['assignments'] as $order => $assign) {
            if ($assign['reviewer_user_id'] == $user_id) {
                $user_assignments[$order] = $assign;
            }
        }
        
        if (empty($user_assignments)) {
            error_log("當前用戶沒有分配 - 報名ID: {$application_id}, 用戶ID: {$user_id}");
            header("Location: continued_admission_detail.php?id=" . $application_id);
            exit;
        }
        
        // 如果有指定 URL slot，驗證是否匹配
        if ($url_slot) {
            $url_slot_int = (int)$url_slot;
            if (isset($user_assignments[$url_slot_int])) {
                // URL slot 匹配當前用戶的分配
                $teacher_slot = $url_slot_int;
                $assignment_found = true;
                error_log("找到匹配的分配 - 報名ID: {$application_id}, 用戶ID: {$user_id}, URL slot: {$url_slot_int}, 實際 slot: {$teacher_slot}");
            } else {
                // URL slot 不匹配，使用當前用戶的第一個分配，並重定向到正確的 URL
                $first_order = array_key_first($user_assignments);
                $teacher_slot = $first_order;
                $assignment_found = true;
                error_log("URL slot 不匹配，重定向到正確的 slot - 報名ID: {$application_id}, 用戶ID: {$user_id}, URL slot: {$url_slot_int}, 正確 slot: {$teacher_slot}");
                // 重定向到正確的 slot
                header("Location: continued_admission_detail.php?id={$application_id}&action=score&slot={$teacher_slot}");
                exit;
            }
        } else {
            // 如果沒有指定 slot，使用第一個匹配的分配
            $first_order = array_key_first($user_assignments);
            $teacher_slot = $first_order;
            $assignment_found = true;
            error_log("沒有指定 slot，使用第一個分配 - 報名ID: {$application_id}, 用戶ID: {$user_id}, slot: {$teacher_slot}");
        }
        
        // 額外驗證：主任只能評分自己科系的學生
        if ($is_director && $teacher_slot == 3) {
            if ($assigned_dept !== $user_department_code) {
                header("Location: continued_admission_detail.php?id=" . $application_id);
                exit;
            }
        }
    } else {
        // 向後兼容：使用舊欄位
        $assigned_teacher_1 = $application['assigned_teacher_1_id'] ?? null;
        $assigned_teacher_2 = $application['assigned_teacher_2_id'] ?? null;
        
        if ($is_teacher && !empty($user_id)) {
            // 老師評分
            if ($url_slot == '1' && $assigned_teacher_1 == $user_id) {
                $teacher_slot = 1;
            } elseif ($url_slot == '2' && $assigned_teacher_2 == $user_id) {
                $teacher_slot = 2;
            } elseif ($assigned_teacher_1 == $user_id) {
                $teacher_slot = 1;
            } elseif ($assigned_teacher_2 == $user_id) {
                $teacher_slot = 2;
            } else {
                header("Location: continued_admission_detail.php?id=" . $application_id);
                exit;
            }
        } elseif ($is_director && !empty($user_department_code)) {
            // 主任評分
            if ($url_slot == 'director' || $url_slot == '3') {
                if ($assigned_dept === $user_department_code) {
                    $teacher_slot = 3;
                } else {
                    header("Location: continued_admission_detail.php?id=" . $application_id);
                    exit;
                }
            } elseif ($assigned_dept === $user_department_code) {
                $teacher_slot = 3;
            } else {
                header("Location: continued_admission_detail.php?id=" . $application_id);
                exit;
            }
        } else {
            header("Location: continued_admission_detail.php?id=" . $application_id);
            exit;
        }
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
$choices_with_codes = []; // 保存完整的志願資料（包含代碼）
if (($is_director || $is_teacher) && !empty($user_department_code)) {
    // 主任和老師：只查詢自己科系的志願
    $stmt = $conn->prepare("
        SELECT cac.choice_order, d.name as department_name, cac.department_code
        FROM continued_admission_choices cac
        LEFT JOIN departments d ON cac.department_code = d.code
        WHERE cac.application_id = ? AND cac.department_code = ?
        ORDER BY cac.choice_order ASC
    ");
    $stmt->bind_param("is", $application_id, $user_department_code);
} else {
    // 招生中心/管理員：查詢所有志願
    $stmt = $conn->prepare("
        SELECT cac.choice_order, d.name as department_name, cac.department_code
        FROM continued_admission_choices cac
        LEFT JOIN departments d ON cac.department_code = d.code
        WHERE cac.application_id = ?
        ORDER BY cac.choice_order ASC
    ");
    $stmt->bind_param("i", $application_id);
}
$stmt->execute();
$choices_result = $stmt->get_result();
while ($choice_row = $choices_result->fetch_assoc()) {
    $choices[] = $choice_row['department_name'] ?? $choice_row['department_code'];
    $choices_with_codes[] = [
        'order' => $choice_row['choice_order'],
        'name' => $choice_row['department_name'] ?? $choice_row['department_code'],
        'code' => $choice_row['department_code']
    ];
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

// 注意：不要在這裡關閉連接，因為後續的評分檢查還需要使用
// $conn->close(); // 移到頁面最後關閉

if ($action === 'review') {
    $page_title = '續招報名審核 - ' . htmlspecialchars($application['name']);
} elseif ($action === 'score') {
    $page_title = '續招報名評分 - ' . htmlspecialchars($application['name']);
} else {
    $page_title = '續招報名詳情 - ' . htmlspecialchars($application['name']);
}
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

        /* 標籤頁樣式 */
        .tabs-container { margin-bottom: 0; }
        .tabs-nav { display: flex; border-bottom: 2px solid var(--border-color); background: var(--card-background-color); padding: 0 24px; }
        .tabs-nav .tab-item { 
            padding: 16px 24px; cursor: pointer; font-size: 16px; font-weight: 500; 
            color: var(--text-secondary-color); border-bottom: 2px solid transparent; 
            margin-bottom: -2px; transition: all 0.3s; position: relative;
            display: flex; align-items: center; gap: 8px;
        }
        .tabs-nav .tab-item:hover { color: var(--primary-color); }
        .tabs-nav .tab-item.active { 
            color: var(--primary-color); border-bottom-color: var(--primary-color); 
        }
        .tab-content { display: none; padding: 24px; }
        .tab-content.active { display: block; }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            <div class="content">
                <div class="breadcrumb">
                    <a href="index.php">首頁</a> / <a href="continued_admission_list.php">續招報名管理</a> / <?php 
                        if ($action === 'review') {
                            echo '報名審核';
                        } elseif ($action === 'score') {
                            echo '報名評分';
                        } else {
                            echo '報名詳情';
                        }
                    ?>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3><?php 
                            if ($action === 'review') {
                                echo '報名審核';
                            } elseif ($action === 'score') {
                                echo '報名評分';
                            } else {
                                echo '報名詳情';
                            }
                        ?> (編號: <?php echo $application['apply_no'] ?? $application['ID']; ?>)</h3>
                        <a href="continued_admission_list.php" class="btn-secondary"><i class="fas fa-arrow-left"></i> 返回列表</a>
                    </div>
                    <div class="card-body">
                        <?php if ($action === 'score' && $teacher_slot): ?>
                        <!-- 標籤頁導航 -->
                        <div class="tabs-container">
                            <div class="tabs-nav">
                                <div class="tab-item active" onclick="switchDetailTab('score', this)">
                                    <i class="fas fa-star"></i> 評分
                                </div>
                                <div class="tab-item" onclick="switchDetailTab('basic', this)">
                                    <i class="fas fa-user"></i> 基本資料
                                </div>
                                <div class="tab-item" onclick="switchDetailTab('choice', this)">
                                    <i class="fas fa-star"></i> 志願
                                </div>
                                <div class="tab-item" onclick="switchDetailTab('intro', this)">
                                    <i class="fas fa-pen"></i> 自傳/專長
                                </div>
                                <div class="tab-item" onclick="switchDetailTab('documents', this)">
                                    <i class="fas fa-file-alt"></i> 上傳文件
                                </div>
                            </div>
                        </div>

                        <!-- 評分標籤頁 -->
                        <div id="tab-score" class="tab-content active">
                            <?php
                            // 根據 slot 獲取當前分數（直接從資料庫查詢，確保獲取最新數據）
                            $current_self_intro_score = '';
                            $current_skills_score = '';
                            
                            // 直接從資料庫查詢當前用戶的評分（使用當前用戶ID確保正確）
                            if ($has_normalized_tables) {
                                // 使用當前用戶的 user_id 查詢評分（不是該 slot 原本分配給誰的 ID）
                                $current_score_stmt = $conn->prepare("
                                    SELECT self_intro_score, skills_score, scored_at
                                    FROM continued_admission_scores
                                    WHERE application_id = ? AND reviewer_user_id = ? AND assignment_order = ?
                                    LIMIT 1
                                ");
                                $current_score_stmt->bind_param("iii", $application_id, $user_id, $teacher_slot);
                                $current_score_stmt->execute();
                                $current_score_result = $current_score_stmt->get_result();
                                if ($current_score_row = $current_score_result->fetch_assoc()) {
                                    $current_self_intro_score = $current_score_row['self_intro_score'] ?? '';
                                    $current_skills_score = $current_score_row['skills_score'] ?? '';
                                    $scored_at = $current_score_row['scored_at'] ?? '';
                                    error_log("從資料庫讀取當前用戶的評分 - 報名ID: {$application_id}, 用戶ID: {$user_id}, slot: {$teacher_slot}, 自傳: '{$current_self_intro_score}', 專長: '{$current_skills_score}'");
                                } else {
                                    error_log("資料庫中沒有找到當前用戶的評分記錄 - 報名ID: {$application_id}, 用戶ID: {$user_id}, slot: {$teacher_slot}");
                                }
                                $current_score_stmt->close();
                            }
                            
                            // 如果從資料庫沒找到，嘗試從快取中讀取
                            if (empty($current_self_intro_score) && empty($current_skills_score)) {
                                if ($has_normalized_tables && isset($application['scores'][$teacher_slot])) {
                                    $score_data = $application['scores'][$teacher_slot];
                                    // 重要：必須驗證 reviewer_user_id 是否為當前用戶，避免錯誤地使用其他評審者的評分
                                    if (isset($score_data['reviewer_user_id']) && (int)$score_data['reviewer_user_id'] === (int)$user_id) {
                                        $current_self_intro_score = $score_data['self_intro_score'] ?? '';
                                        $current_skills_score = $score_data['skills_score'] ?? '';
                                    } else {
                                        // 如果不是當前用戶的評分記錄，忽略它
                                        error_log("警告：找到 slot {$teacher_slot} 的評分記錄，但 reviewer_user_id ({$score_data['reviewer_user_id']}) 不匹配當前用戶 ({$user_id})，忽略此記錄");
                                    }
                                } else {
                                    // 向後兼容：使用舊欄位
                                    if ($teacher_slot == 1) {
                                        $current_self_intro_score = $application['self_intro_score_1'] ?? '';
                                        $current_skills_score = $application['skills_score_1'] ?? '';
                                    } elseif ($teacher_slot == 2) {
                                        $current_self_intro_score = $application['self_intro_score_2'] ?? '';
                                        $current_skills_score = $application['skills_score_2'] ?? '';
                                    } elseif ($teacher_slot == 3) {
                                        $current_self_intro_score = $application['self_intro_score_director'] ?? '';
                                        $current_skills_score = $application['skills_score_director'] ?? '';
                                    }
                                }
                            }
                            
                            $reviewer_label = ($teacher_slot == 3) ? '主任' : '老師' . $teacher_slot;
                            
                            // 檢查是否已經評分過
                            // 只有當兩個分數都存在且不為空字符串時，才認為已評分
                            // 注意：0 也是有效分數，所以不能用 empty() 或 == 0 來判斷
                            $is_scored = false;
                            
                            // 先從正規化表檢查（優先）
                            if ($has_normalized_tables) {
                                // 直接查詢資料庫，確保獲取當前用戶的評分狀態
                                // 重要：必須使用 reviewer_user_id 來區分不同老師的評分
                                // 只查詢有實際分數值的記錄（排除 null 和空字符串）
                                $score_check_stmt = $conn->prepare("
                                    SELECT self_intro_score, skills_score 
                                    FROM continued_admission_scores 
                                    WHERE application_id = ? 
                                      AND reviewer_user_id = ?
                                      AND assignment_order = ?
                                      AND self_intro_score IS NOT NULL 
                                      AND self_intro_score != ''
                                      AND skills_score IS NOT NULL 
                                      AND skills_score != ''
                                      AND TRIM(CAST(self_intro_score AS CHAR)) != ''
                                      AND TRIM(CAST(skills_score AS CHAR)) != ''
                                    LIMIT 1
                                ");
                                $score_check_stmt->bind_param("iii", $application_id, $user_id, $teacher_slot);
                                $score_check_stmt->execute();
                                $score_check_result = $score_check_stmt->get_result();
                                
                                error_log("查詢評分記錄 - 報名ID: {$application_id}, 用戶ID: {$user_id}, slot: {$teacher_slot}, 找到記錄數: " . $score_check_result->num_rows);
                                
                                if ($score_check_row = $score_check_result->fetch_assoc()) {
                                    $db_self_intro = $score_check_row['self_intro_score'];
                                    $db_skills = $score_check_row['skills_score'];
                                    
                                    // 調試：記錄原始值
                                    error_log("評分狀態判斷詳情 - 報名ID: {$application_id}, 用戶ID: {$user_id}, slot: {$teacher_slot}");
                                    error_log("  - 自傳分數原始值: " . var_export($db_self_intro, true) . " (類型: " . gettype($db_self_intro) . ")");
                                    error_log("  - 專長分數原始值: " . var_export($db_skills, true) . " (類型: " . gettype($db_skills) . ")");
                                    
                                    // 檢查是否兩個分數都存在（不為 null 且不為空字符串）
                                    // 注意：0 是有效分數，所以不能用 == 0 來判斷
                                    // 但如果分數是 null、空字符串或只包含空白字符，則認為未評分
                                    // 轉換為字符串並去除空白，然後檢查是否為空
                                    $self_intro_str = trim((string)$db_self_intro);
                                    $skills_str = trim((string)$db_skills);
                                    
                                    $has_self_intro = ($db_self_intro !== null && $db_self_intro !== '' && $self_intro_str !== '');
                                    $has_skills = ($db_skills !== null && $db_skills !== '' && $skills_str !== '');
                                    
                                    // 只有當兩個分數都存在且不為空時才認為已評分
                                    $is_scored = $has_self_intro && $has_skills;
                                    
                                    // 調試：記錄詳細的判斷過程
                                    error_log("  - 自傳分數處理後: '{$self_intro_str}'");
                                    error_log("  - 專長分數處理後: '{$skills_str}'");
                                    error_log("  - has_self_intro: " . ($has_self_intro ? 'true' : 'false'));
                                    error_log("  - has_skills: " . ($has_skills ? 'true' : 'false'));
                                    error_log("  - is_scored: " . ($is_scored ? 'true' : 'false'));
                                    
                                    // 如果查詢到記錄但分數為空，強制設為未評分
                                    if (!$has_self_intro || !$has_skills) {
                                        $is_scored = false;
                                        error_log("  - 強制設為未評分（因為分數為空）");
                                    }
                                    
                                    // 更新當前分數值（如果有的話）
                                    if ($has_self_intro) {
                                        $current_self_intro_score = $db_self_intro;
                                    }
                                    if ($has_skills) {
                                        $current_skills_score = $db_skills;
                                    }
                                    error_log("從資料庫檢查評分狀態 - 報名ID: {$application_id}, 用戶ID: {$user_id}, slot: {$teacher_slot}, 自傳: '{$db_self_intro}', 專長: '{$db_skills}', 已評分: " . ($is_scored ? '是' : '否'));
                                } else {
                                    // 沒有查詢到記錄，設為未評分
                                    $is_scored = false;
                                    error_log("資料庫中沒有找到當前用戶的有效評分記錄 - 報名ID: {$application_id}, 用戶ID: {$user_id}, slot: {$teacher_slot}");
                                    
                                    // 檢查是否有空記錄（可能之前插入過但分數為空）
                                    $empty_check_stmt = $conn->prepare("
                                        SELECT id, self_intro_score, skills_score 
                                        FROM continued_admission_scores 
                                        WHERE application_id = ? 
                                          AND reviewer_user_id = ?
                                          AND assignment_order = ?
                                        LIMIT 1
                                    ");
                                    $empty_check_stmt->bind_param("iii", $application_id, $user_id, $teacher_slot);
                                    $empty_check_stmt->execute();
                                    $empty_check_result = $empty_check_stmt->get_result();
                                    if ($empty_check_row = $empty_check_result->fetch_assoc()) {
                                        error_log("發現空記錄 - 報名ID: {$application_id}, 用戶ID: {$user_id}, slot: {$teacher_slot}, 記錄ID: {$empty_check_row['id']}, 自傳: " . var_export($empty_check_row['self_intro_score'], true) . ", 專長: " . var_export($empty_check_row['skills_score'], true));
                                        // 刪除空記錄，避免影響判斷
                                        $delete_empty_stmt = $conn->prepare("DELETE FROM continued_admission_scores WHERE id = ?");
                                        $delete_empty_stmt->bind_param("i", $empty_check_row['id']);
                                        if ($delete_empty_stmt->execute()) {
                                            error_log("已刪除空記錄 - 記錄ID: {$empty_check_row['id']}");
                                        }
                                        $delete_empty_stmt->close();
                                    }
                                    $empty_check_stmt->close();
                                }
                                $score_check_stmt->close();
                            } else {
                                // 從舊欄位檢查
                                $has_self_intro = ($current_self_intro_score !== '' && $current_self_intro_score !== null && $current_self_intro_score !== '0');
                                $has_skills = ($current_skills_score !== '' && $current_skills_score !== null && $current_skills_score !== '0');
                                $is_scored = $has_self_intro && $has_skills;
                                error_log("從舊欄位檢查評分狀態 - 報名ID: {$application_id}, slot: {$teacher_slot}, 自傳: '{$current_self_intro_score}', 專長: '{$current_skills_score}', 已評分: " . ($is_scored ? '是' : '否'));
                            }
                            
                            // 獲取評分時間
                            $scored_at = '';
                            if ($has_normalized_tables && isset($application['scores'][$teacher_slot])) {
                                $score_data = $application['scores'][$teacher_slot];
                                // 重要：必須驗證 reviewer_user_id 是否為當前用戶，避免錯誤地使用其他評審者的評分時間
                                if (isset($score_data['reviewer_user_id']) && (int)$score_data['reviewer_user_id'] === (int)$user_id) {
                                    $scored_at = $score_data['scored_at'] ?? '';
                                }
                            }
                            
                            // 獲取當前志願序（用於顯示）
                            $display_choice_order = getCurrentChoiceOrder($conn, $application_id);
                            if (!$display_choice_order) {
                                $display_choice_order = 1; // 預設為第一志願
                            }
                            
                            // 檢查是否在審查時間內（根據志願序）
                            $time_check_result = checkScoreTimeByChoice($conn, $application_id);
                            $is_within_review_period = $time_check_result['is_within_period'];
                            $review_time_message = $time_check_result['message'];
                            $score_deadline_display = $time_check_result['deadline'];
                            
                            $current_time = time();
                            $review_start_timestamp = $review_start ? strtotime($review_start) : null;
                            $score_deadline_timestamp = $score_deadline_display ? strtotime($score_deadline_display) : null;
                            
                            // 如果已評分，不允許修改（無論是否還在評分期間內）
                            if ($is_scored) {
                                // 已評分後，不允許再修改（只有管理員可以修改，但前端不顯示編輯功能）
                                $is_within_review_period = false;
                                error_log("已評分狀態檢查 - 報名ID: {$application_id}, slot: {$teacher_slot}, 已評分，不允許修改");
                            }
                            
                            // 如果未評分，檢查是否在評分期間內
                            if (!$is_scored && $review_start_timestamp && $current_time >= $review_start_timestamp) {
                                $is_within_review_period = true;
                                error_log("強制允許評分：報名ID={$application_id}, 當前時間=" . date('Y-m-d H:i:s', $current_time) . ", 開始時間=" . date('Y-m-d H:i:s', $review_start_timestamp));
                            }
                            
                            // 調試信息：記錄時間檢查結果
                            error_log("評分頁面時間檢查 - 報名ID: {$application_id}, 志願序: {$display_choice_order}, 是否在評分期間內: " . ($is_within_review_period ? '是' : '否') . ", 訊息: {$review_time_message}");
                            ?>
                            <div class="detail-section" style="background: #fffbe6; border: 2px solid #faad14; text-align: left; max-width: 600px; margin: 0 auto;">
                                <h4 style="color: #faad14; text-align: left; margin-bottom: 20px;">
                                    <i class="fas fa-star"></i> 評分（<?php echo $reviewer_label; ?>）
                                    <?php if ($is_scored): ?>
                                        <span style="font-size: 14px; color: #52c41a; margin-left: 12px;">
                                            <i class="fas fa-check-circle"></i> 已完成評分
                                        </span>
                                    <?php endif; ?>
                                </h4>
                                
                                <?php if ($is_scored && $scored_at): ?>
                                <div style="background: #f6ffed; border: 1px solid #b7eb8f; padding: 12px; border-radius: 6px; margin-bottom: 20px; text-align: left;">
                                    <div style="color: #52c41a; font-size: 14px;">
                                        <i class="fas fa-info-circle"></i> 評分時間：<?php echo date('Y-m-d H:i:s', strtotime($scored_at)); ?>
                                    </div>
                                    <div style="color: #8c8c8c; font-size: 12px; margin-top: 4px;">
                                        評分已完成，無法再修改
                                    </div>
                                </div>
                                <?php elseif (!$is_within_review_period && !empty($review_time_message)): ?>
                                <div style="background: #fff1f0; border: 1px solid #ffccc7; padding: 12px; border-radius: 6px; margin-bottom: 20px; text-align: left;">
                                    <div style="color: #f5222d; font-size: 14px; font-weight: bold;">
                                        <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($review_time_message); ?>
                                    </div>
                                    <?php if ($display_choice_order && $score_deadline_display): ?>
                                    <div style="color: #8c8c8c; font-size: 12px; margin-top: 4px;">
                                        志願<?php echo $display_choice_order; ?>評分截止時間：<?php echo date('Y-m-d H:i', strtotime($score_deadline_display)); ?>
                                    </div>
                                    <?php elseif ($review_start && $review_end): ?>
                                    <div style="color: #8c8c8c; font-size: 12px; margin-top: 4px;">
                                        審查時間：<?php echo date('Y-m-d H:i', strtotime($review_start)); ?> 至 <?php echo date('Y-m-d H:i', strtotime($review_end)); ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php 
                                    // 如果是管理員或行政人員，允許強制評分
                                    $can_force_score = in_array($user_role, ['ADM', 'STA']);
                                    if ($can_force_score): 
                                    ?>
                                    <div style="color: #1890ff; font-size: 12px; margin-top: 8px; padding: 8px; background: #e6f7ff; border-radius: 4px;">
                                        <i class="fas fa-info-circle"></i> 您有管理權限，可以強制進行評分
                                    </div>
                                    <?php 
                                    // 允許管理員強制評分
                                    $is_within_review_period = true;
                                    endif; 
                                    ?>
                                </div>
                                <?php elseif ($is_within_review_period && ($current_choice_order || $review_start)): ?>
                                <div style="background: #e6f7ff; border: 1px solid #91d5ff; padding: 12px; border-radius: 6px; margin-bottom: 20px; text-align: left;">
                                    <?php if ($display_choice_order && $score_deadline_display): ?>
                                    <div style="color: #1890ff; font-size: 14px;">
                                        <i class="fas fa-clock"></i> 志願<?php echo $display_choice_order; ?>評分進行中
                                    </div>
                                    <div style="color: #8c8c8c; font-size: 12px; margin-top: 4px;">
                                        評分截止時間：<?php echo date('Y-m-d H:i', strtotime($score_deadline_display)); ?>
                                    </div>
                                    <?php 
                                    if ($score_deadline_timestamp):
                                        $days_remaining = floor(($score_deadline_timestamp - $current_time) / 86400);
                                        if ($days_remaining <= 1 && $days_remaining >= 0): 
                                    ?>
                                    <div style="color: #faad14; font-size: 12px; margin-top: 4px; font-weight: bold;">
                                        ⚠️ 距離截止時間還有 <?php echo $days_remaining == 0 ? '不到1天' : '1天'; ?>，請盡快完成評分！
                                    </div>
                                    <?php 
                                        endif;
                                    endif; 
                                    ?>
                                    <?php elseif ($review_start && $review_end): ?>
                                    <div style="color: #1890ff; font-size: 14px;">
                                        <i class="fas fa-clock"></i> 審查時間：<?php echo date('Y-m-d H:i', strtotime($review_start)); ?> 至 <?php echo date('Y-m-d H:i', strtotime($review_end)); ?>
                                    </div>
                                    <?php 
                                    if ($review_end_timestamp):
                                        $days_remaining = floor(($review_end_timestamp - $current_time) / 86400);
                                        if ($days_remaining <= 1 && $days_remaining >= 0): 
                                    ?>
                                    <div style="color: #faad14; font-size: 12px; margin-top: 4px; font-weight: bold;">
                                        ⚠️ 距離截止時間還有 <?php echo $days_remaining == 0 ? '不到1天' : '1天'; ?>，請盡快完成評分！
                                    </div>
                                    <?php 
                                        endif;
                                    endif; 
                                    ?>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                
                                <!-- 評分進度顯示 -->
                                <?php if ($has_normalized_tables && !empty($application['assignments'])): ?>
                                <div style="background: #fff; padding: 16px; border-radius: 6px; margin-bottom: 24px; border: 1px solid #f0f0f0;">
                                    <h5 style="margin: 0 0 12px 0; font-size: 14px; color: #8c8c8c;">評分進度</h5>
                                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px;">
                                        <?php foreach ($application['assignments'] as $order => $assign): 
                                            $score_data = $application['scores'][$order] ?? null;
                                            // 重要：必須驗證 reviewer_user_id 是否匹配，避免錯誤地顯示其他評審者的評分狀態
                                            $is_reviewer_scored = false;
                                            if ($score_data && isset($score_data['reviewer_user_id']) && (int)$score_data['reviewer_user_id'] === (int)$assign['reviewer_user_id']) {
                                                $is_reviewer_scored = !empty($score_data['self_intro_score']) && !empty($score_data['skills_score']);
                                            }
                                            $reviewer_type_label = ($assign['reviewer_type'] === 'director') ? '主任' : '老師' . $order;
                                        ?>
                                        <div style="padding: 8px; background: #fafafa; border-radius: 4px; border: 1px solid #e8e8e8;">
                                            <div style="font-size: 12px; font-weight: 500; margin-bottom: 4px;"><?php echo $reviewer_type_label; ?></div>
                                            <?php if ($is_reviewer_scored): 
                                                $total_score = ($score_data['self_intro_score'] ?? 0) + ($score_data['skills_score'] ?? 0);
                                            ?>
                                                <div style="color: #52c41a; font-size: 14px; font-weight: bold;">
                                                    ✅ 已評分 (<?php echo $total_score; ?>分)
                                                </div>
                                            <?php else: ?>
                                                <div style="color: #8c8c8c; font-size: 12px;">⏳ 待評分</div>
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <form id="scoreForm" style="text-align: left;">
                                    <div style="margin-bottom: 24px; text-align: left;">
                                        <label for="selfIntroScore" style="display: block; margin-bottom: 8px; font-weight: 500; text-align: left;">
                                            自傳/自我介紹分數 <span style="color: #8c8c8c; font-size: 12px;">(滿分 80)</span>
                                        </label>
                                        <input type="number" id="selfIntroScore" name="self_intro_score" min="0" max="80" 
                                               value="<?php echo htmlspecialchars($current_self_intro_score); ?>" 
                                               <?php if (!$is_scored): ?>required<?php endif; ?>
                                               style="width: 100%; padding: 12px; border: 2px solid #faad14; border-radius: 6px; font-size: 16px; text-align: left;"
                                               data-is-scored="<?php echo $is_scored ? 'true' : 'false'; ?>"
                                               data-php-is-scored="<?php echo $is_scored ? 'true' : 'false'; ?>"
                                               oninput="updateTotalScore()">
                                        <div style="margin-top: 6px; font-size: 12px; color: #8c8c8c;">
                                            <i class="fas fa-info-circle"></i> 請根據學生的自傳內容完整性、表達清晰度、個人特色展現進行評分
                                        </div>
                                    </div>
                                    
                                    <div style="margin-bottom: 24px; text-align: left;">
                                        <label for="skillsScore" style="display: block; margin-bottom: 8px; font-weight: 500; text-align: left;">
                                            興趣/專長分數 <span style="color: #8c8c8c; font-size: 12px;">(滿分 20)</span>
                                        </label>
                                        <input type="number" id="skillsScore" name="skills_score" min="0" max="20" 
                                               value="<?php echo htmlspecialchars($current_skills_score); ?>" 
                                               <?php if (!$is_scored): ?>required<?php endif; ?>
                                               style="width: 100%; padding: 12px; border: 2px solid #faad14; border-radius: 6px; font-size: 16px; text-align: left;"
                                               data-is-scored="<?php echo $is_scored ? 'true' : 'false'; ?>"
                                               data-php-is-scored="<?php echo $is_scored ? 'true' : 'false'; ?>"
                                               oninput="updateTotalScore()">
                                        <div style="margin-top: 6px; font-size: 12px; color: #8c8c8c;">
                                            <i class="fas fa-info-circle"></i> 請根據興趣與科系相關性、專長展現程度進行評分
                                        </div>
                                    </div>
                                    
                                    <!-- 總分顯示 -->
                                    <div style="background: #fff; padding: 20px; border-radius: 6px; border: 2px solid #faad14; margin-bottom: 24px; text-align: center;">
                                        <div style="font-size: 14px; color: #8c8c8c; margin-bottom: 8px;">總分</div>
                                        <div style="font-size: 36px; font-weight: bold; color: #faad14;">
                                            <span id="totalScore">0</span> <span style="font-size: 20px; color: #8c8c8c;">/ 100</span>
                                        </div>
                                    </div>
                                    
                                    <!-- 評分備註（可選） -->
                                    <?php if (!$is_scored): ?>
                                    <div style="margin-bottom: 24px; text-align: left;">
                                        <label for="scoreNotes" style="display: block; margin-bottom: 8px; font-weight: 500; text-align: left;">
                                            評分備註 <span style="color: #8c8c8c; font-size: 12px;">(選填)</span>
                                        </label>
                                        <textarea id="scoreNotes" name="score_notes" rows="3" 
                                                  style="width: 100%; padding: 8px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px; resize: vertical; text-align: left;"
                                                  placeholder="可選：填寫評分說明或特殊情況..."></textarea>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div style="display: flex; gap: 12px; text-align: left;">
                                        <?php if ($is_scored): ?>
                                        <button type="button" onclick="history.back()" class="btn-secondary" style="flex: 1; padding: 12px;">
                                            <i class="fas fa-arrow-left"></i> 返回列表
                                        </button>
                                        <?php else: ?>
                                        <button type="submit" class="btn-primary" style="flex: 1; padding: 12px;">
                                            <i class="fas fa-save"></i> 送出評分
                                        </button>
                                        <button type="button" onclick="history.back()" class="btn-secondary" style="padding: 12px;">
                                            <i class="fas fa-times"></i> 取消
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- 基本資料標籤頁 -->
                        <div id="tab-basic" class="tab-content">
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
                            </div>
                        </div>

                        <!-- 志願標籤頁 -->
                        <div id="tab-choice" class="tab-content">
                            <div class="detail-grid">
                                <?php if (!(($is_director || $is_teacher) && !empty($user_department_code))): ?>
                                <!-- 主任和老師不顯示志願序 -->
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
                                <?php else: ?>
                                <!-- 主任和老師：只顯示自己科系的志願，不顯示序號 -->
                                <?php if (!empty($choices)): ?>
                                <div class="detail-section">
                                    <h4><i class="fas fa-star"></i> 志願</h4>
                                    <ul style="margin: 0; padding-left: 20px; text-align: left; list-style: none;">
                                        <?php foreach ($choices as $choice): ?>
                                            <li style="margin-bottom: 8px; text-align: left;"><?php echo htmlspecialchars($choice); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- 自傳/專長標籤頁 -->
                        <div id="tab-intro" class="tab-content">
                            <div class="detail-grid">
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
                            </div>
                        </div>

                        <!-- 上傳文件標籤頁 -->
                        <div id="tab-documents" class="tab-content">
                            <div class="detail-grid">
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
                            </div>
                        </div>
                        <?php else: ?>
                        <!-- 非評分模式：保持原有布局 -->
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

                            <?php if (!(($is_director || $is_teacher) && !empty($user_department_code))): ?>
                            <!-- 主任和老師不顯示志願序 -->
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
                            <?php else: ?>
                            <!-- 主任和老師：只顯示自己科系的志願，不顯示序號 -->
                            <?php if (!empty($choices)): ?>
                            <div class="detail-section">
                                <h4><i class="fas fa-star"></i> 志願</h4>
                                <ul style="margin: 0; padding-left: 20px; text-align: left; list-style: none;">
                                    <?php foreach ($choices as $choice): ?>
                                        <li style="margin-bottom: 8px; text-align: left;"><?php echo htmlspecialchars($choice); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>
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
                        <?php endif; ?>
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

    // 標籤頁切換功能
    function switchDetailTab(tabName, clickedElement) {
        // 隱藏所有標籤頁內容
        const tabContents = document.querySelectorAll('.tab-content');
        tabContents.forEach(content => {
            content.classList.remove('active');
        });
        
        // 移除所有標籤的 active 狀態
        const tabItems = document.querySelectorAll('.tab-item');
        tabItems.forEach(item => {
            item.classList.remove('active');
        });
        
        // 顯示選中的標籤頁內容
        const targetTab = document.getElementById('tab-' + tabName);
        if (targetTab) {
            targetTab.classList.add('active');
        }
        
        // 設置選中的標籤為 active
        if (clickedElement) {
            clickedElement.classList.add('active');
        }
    }

    // 自動計算總分
    function updateTotalScore() {
        const selfIntroScoreEl = document.getElementById('selfIntroScore');
        const skillsScoreEl = document.getElementById('skillsScore');
        
        if (!selfIntroScoreEl || !skillsScoreEl) return;
        
        const selfIntroScore = parseInt(selfIntroScoreEl.value) || 0;
        const skillsScore = parseInt(skillsScoreEl.value) || 0;
        const total = selfIntroScore + skillsScore;
        
        const totalScoreEl = document.getElementById('totalScore');
        if (totalScoreEl) {
            totalScoreEl.textContent = total;
            
            // 根據總分改變顏色
            if (total >= 80) {
                totalScoreEl.style.color = '#52c41a'; // 綠色
            } else if (total >= 60) {
                totalScoreEl.style.color = '#faad14'; // 橙色
            } else {
                totalScoreEl.style.color = '#f5222d'; // 紅色
            }
        }
    }

    <?php if ($action === 'score' && $teacher_slot): ?>
    // 頁面載入時計算總分
    document.addEventListener('DOMContentLoaded', function() {
        updateTotalScore();
        
        // 調試：檢查輸入框和按鈕狀態
        const selfIntroInput = document.getElementById('selfIntroScore');
        const skillsInput = document.getElementById('skillsScore');
        let submitButton = document.querySelector('#scoreForm button[type="submit"]');
        
        console.log('輸入框狀態檢查:');
        console.log('  - 自傳輸入框 readonly:', selfIntroInput ? selfIntroInput.readOnly : '找不到');
        console.log('  - 專長輸入框 readonly:', skillsInput ? skillsInput.readOnly : '找不到');
        console.log('  - 提交按鈕 disabled:', submitButton ? submitButton.disabled : '找不到');
        console.log('  - 是否已評分:', <?php echo $is_scored ? 'true' : 'false'; ?>);
        console.log('  - 是否在評分期間內:', <?php echo $is_within_review_period ? 'true' : 'false'; ?>);
        
        // 根據 PHP 的 is_scored 狀態來控制輸入框
        // 重要：直接從 PHP 變數讀取，不依賴 HTML 屬性
        const phpIsScoredValue = <?php echo $is_scored ? 'true' : 'false'; ?>;
        const isScored = phpIsScoredValue === true || phpIsScoredValue === 'true';
        
        console.log('=== 評分狀態檢查 ===');
        console.log('PHP is_scored 原始值:', phpIsScoredValue, '類型:', typeof phpIsScoredValue);
        console.log('PHP is_scored 轉換後:', isScored);
        console.log('當前用戶ID:', <?php echo $user_id; ?>, 'slot:', <?php echo $teacher_slot; ?>);
        console.log('報名ID:', <?php echo $application_id; ?>);
        
        // 強制根據 PHP 的 is_scored 狀態控制輸入框
        // 無論 HTML 屬性如何，都根據 PHP 狀態來設置
        if (selfIntroInput) {
            const phpIsScoredAttr = selfIntroInput.getAttribute('data-php-is-scored') === 'true';
            console.log('自傳輸入框 - data-php-is-scored 屬性:', phpIsScoredAttr);
            console.log('自傳輸入框 - PHP isScored 變數:', isScored);
            console.log('自傳輸入框 - 當前 readonly:', selfIntroInput.readOnly);
            
            // 強制使用 PHP 變數的值，忽略 HTML 屬性（因為可能有緩存問題）
            if (!isScored) {
                console.log('✓ PHP 判斷未評分，強制啟用自傳輸入框');
                selfIntroInput.removeAttribute('readonly');
                selfIntroInput.readOnly = false;
                selfIntroInput.disabled = false;
                selfIntroInput.style.backgroundColor = '';
                selfIntroInput.style.cursor = 'text';
                selfIntroInput.style.pointerEvents = 'auto';
                // 強制移除任何可能阻止輸入的樣式
                selfIntroInput.style.opacity = '1';
                selfIntroInput.style.pointerEvents = 'auto';
            } else {
                // 已評分，確保保持 readonly
                console.log('✗ PHP 判斷已評分，保持自傳輸入框為 readonly');
                selfIntroInput.readOnly = true;
                selfIntroInput.setAttribute('readonly', 'readonly');
                selfIntroInput.style.backgroundColor = '#f5f5f5';
                selfIntroInput.style.cursor = 'not-allowed';
            }
            console.log('自傳輸入框 - 最終 readonly:', selfIntroInput.readOnly);
        }
        
        if (skillsInput) {
            const phpIsScoredAttr = skillsInput.getAttribute('data-php-is-scored') === 'true';
            console.log('專長輸入框 - data-php-is-scored 屬性:', phpIsScoredAttr);
            console.log('專長輸入框 - PHP isScored 變數:', isScored);
            console.log('專長輸入框 - 當前 readonly:', skillsInput.readOnly);
            
            // 強制使用 PHP 變數的值，忽略 HTML 屬性（因為可能有緩存問題）
            if (!isScored) {
                console.log('✓ PHP 判斷未評分，強制啟用專長輸入框');
                skillsInput.removeAttribute('readonly');
                skillsInput.readOnly = false;
                skillsInput.disabled = false;
                skillsInput.style.backgroundColor = '';
                skillsInput.style.cursor = 'text';
                skillsInput.style.pointerEvents = 'auto';
                // 強制移除任何可能阻止輸入的樣式
                skillsInput.style.opacity = '1';
                skillsInput.style.pointerEvents = 'auto';
            } else {
                // 已評分，確保保持 readonly
                console.log('✗ PHP 判斷已評分，保持專長輸入框為 readonly');
                skillsInput.readOnly = true;
                skillsInput.setAttribute('readonly', 'readonly');
                skillsInput.style.backgroundColor = '#f5f5f5';
                skillsInput.style.cursor = 'not-allowed';
            }
            console.log('專長輸入框 - 最終 readonly:', skillsInput.readOnly);
        }
        
        console.log('=== 評分狀態檢查結束 ===');
        
        // 只有在未評分時才啟用提交按鈕
        // 注意：isScored 已在上面聲明，這裡直接使用
        if (submitButton) {
            if (isScored) {
                // 已評分，隱藏提交按鈕
                console.log('已評分，隱藏提交按鈕');
                submitButton.style.display = 'none';
                submitButton.disabled = true;
            } else {
                // 未評分，確保提交按鈕可用且可見
                console.log('未評分，確保提交按鈕可用');
                submitButton.disabled = false;
                submitButton.removeAttribute('disabled');
                submitButton.style.display = '';
                submitButton.style.visibility = 'visible';
                submitButton.style.opacity = '1';
            }
        } else {
            console.error('找不到提交按鈕！');
            // 嘗試創建提交按鈕
            const form = document.getElementById('scoreForm');
            if (form) {
                const buttonContainer = form.querySelector('div[style*="display: flex"]');
                if (buttonContainer) {
                    const newButton = document.createElement('button');
                    newButton.type = 'submit';
                    newButton.className = 'btn-primary';
                    newButton.style.cssText = 'flex: 1; padding: 12px;';
                    newButton.innerHTML = '<i class="fas fa-save"></i> 送出評分';
                    
                    const cancelButton = buttonContainer.querySelector('button[type="button"]');
                    if (cancelButton) {
                        buttonContainer.insertBefore(newButton, cancelButton);
                    } else {
                        buttonContainer.appendChild(newButton);
                    }
                    console.log('已創建提交按鈕');
                    
                    // 重新獲取按鈕並綁定事件
                    submitButton = newButton;
                } else {
                    console.error('找不到按鈕容器！');
                }
            } else {
                console.error('找不到表單！');
            }
        }
        
        // 確保提交按鈕有事件處理器（如果沒有綁定的話）
        if (submitButton && !submitButton.hasAttribute('data-bound')) {
            console.log('為提交按鈕綁定事件處理器');
            submitButton.setAttribute('data-bound', 'true');
        }
    });

    // 綁定表單提交事件（無論是否在評分期間內，都先綁定，後端會檢查）
    // 注意：即使 PHP 判斷為已評分，也綁定事件，因為 JavaScript 可能已經強制移除了 readonly
    const scoreForm = document.getElementById('scoreForm');
    if (scoreForm) {
        // 使用 once: false 確保事件可以多次觸發
        scoreForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const applicationId = <?php echo $application_id; ?>;
            const teacherSlot = <?php echo $teacher_slot; ?>;
            const formData = new FormData(this);
            const selfIntroScore = parseInt(formData.get('self_intro_score')) || 0;
            const skillsScore = parseInt(formData.get('skills_score')) || 0;
            
            console.log('提交評分:', { applicationId, teacherSlot, selfIntroScore, skillsScore });
            
            // 驗證分數
            if (isNaN(selfIntroScore) || selfIntroScore < 0 || selfIntroScore > 80) {
                showToast('自傳分數必須在 0-80 之間', false);
                return;
            }
            
            if (isNaN(skillsScore) || skillsScore < 0 || skillsScore > 20) {
                showToast('興趣/專長分數必須在 0-20 之間', false);
                return;
            }
            
            if (selfIntroScore === 0 && skillsScore === 0) {
                showToast('請至少輸入一個分數', false);
                return;
            }

        // 顯示載入提示
        const submitButton = this.querySelector('button[type="submit"]');
        let originalText = '';
        if (submitButton) {
            originalText = submitButton.innerHTML;
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 提交中...';
        }
        
        console.log('開始提交評分到後端...');
        
        fetch('submit_continued_admission_score.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ 
                application_id: applicationId,
                teacher_slot: teacherSlot,
                self_intro_score: selfIntroScore,
                skills_score: skillsScore
            }),
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('HTTP error! status: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            console.log('後端返回結果:', data);
            
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.innerHTML = originalText;
            }
            
            if (data.success) {
                showToast('評分已送出！', true);
                console.log('評分成功，1.5秒後跳轉');
                setTimeout(() => {
                    // 重新載入當前頁面以顯示最新評分
                    window.location.reload();
                }, 1500);
            } else {
                showToast('評分失敗：' + (data.message || '未知錯誤'), false);
                console.error('評分失敗:', data);
            }
        })
        .catch(error => {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.innerHTML = originalText;
            }
            showToast('評分失敗：' + error.message, false);
            console.error('評分錯誤:', error);
        });
    });
    } else {
        console.error('找不到 scoreForm 表單元素');
    }
    <?php endif; ?>

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
    
    <?php
    // 在頁面最後關閉資料庫連接
    if (isset($conn) && $conn) {
        $conn->close();
    }
    ?>
</body>
</html>