<?php
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/includes/department_session_access.php';

checkBackendLogin();

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 獲取場次ID
$session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
if ($session_id === 0) {
    header("Location: settings.php");
    exit;
}

// 建立資料庫連接
$conn = getDatabaseConnection();

$normalized_role = normalizeBackendRole($_SESSION['role'] ?? '');
$is_super_user = isSuperUserRole($normalized_role);
$current_user_id = getOrFetchCurrentUserId($conn);
$user_department_code = null;
$is_department_director = false;

if (!$is_super_user && isDepartmentDirectorRole($normalized_role) && $current_user_id) {
    $user_department_code = getCurrentUserDepartmentCode($conn, $current_user_id);
    if (!empty($user_department_code)) {
        $is_department_director = true;
    }
}

// 獲取場次資訊
$stmt = $conn->prepare("SELECT * FROM admission_sessions WHERE id = ?");
if (!$stmt) {
    die("準備語句失敗: " . $conn->error);
}
$stmt->bind_param("i", $session_id);
$stmt->execute();
$session_result = $stmt->get_result();
$session = $session_result->fetch_assoc();
$stmt->close();

if (!$session) {
    // 如果找不到場次，跳轉回設定頁面
    header("Location: settings.php");
    exit;
}

// 科主任權限：只能查看「自己建立的場次」或「該科系有體驗課程資料」的場次
if ($is_department_director && $current_user_id && $user_department_code) {
    if (!canDepartmentDirectorViewSession($conn, $session_id, (int)$current_user_id, (string)$user_department_code)) {
        $conn->close();
        header("Location: settings.php");
        exit;
    }
}

// 獲取該場次的報名者列表（包含學校名稱）
// 注意：只顯示當前年份的報名記錄
$current_year = date('Y');
$sql = "
        SELECT aa.*, sd.name as school_name_display
        FROM admission_applications aa
        LEFT JOIN school_data sd ON aa.school = sd.school_code
        WHERE aa.session_id = ?
        AND YEAR(aa.created_at) = ?
";
if ($is_department_director && $user_department_code) {
    $sql .= " AND (aa.course_priority_1 = ? OR aa.course_priority_2 = ?) ";
}
$sql .= " ORDER BY aa.id DESC ";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("準備語句失敗: " . $conn->error);
}
if ($is_department_director && $user_department_code) {
    $dept = (string)$user_department_code;
    $stmt->bind_param("iiss", $session_id, $current_year, $dept, $dept);
} else {
    $stmt->bind_param("ii", $session_id, $current_year);
}
$stmt->execute();
$registrations_result = $stmt->get_result();
$registrations = $registrations_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 獲取所有科系資料，用於將科系代碼轉換為科系名稱
$departments_map = [];
$dept_stmt = $conn->prepare("SELECT code, name FROM departments");
if ($dept_stmt) {
    $dept_stmt->execute();
    $dept_result = $dept_stmt->get_result();
    while ($dept_row = $dept_result->fetch_assoc()) {
        $departments_map[$dept_row['code']] = $dept_row['name'];
    }
    $dept_stmt->close();
}

$conn->close();

// 設置頁面標題
$page_title = '查看報名名單 - ' . htmlspecialchars($session['session_name']);
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
        .main-content { flex: 1; display: flex; flex-direction: column; }
        .content { padding: 24px; flex: 1; }
        .breadcrumb { margin-bottom: 16px; font-size: 16px; color: var(--text-secondary-color); }
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        
        .card { background: var(--card-background-color); border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); border: 1px solid var(--border-color); margin-bottom: 24px; }
        .card-header { padding: 16px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: #fafafa; }
        .card-header h3 { font-size: 18px; font-weight: 600; color: var(--text-color); margin: 0; }
        .card-body { padding: 24px; }

        .table-container { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 16px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 14px; }
        .table th { background: #fafafa; font-weight: 600; position: sticky; top: 0; z-index: 10; }
        .table tr:hover { background: #fafafa; }
        .table tbody tr:last-child td { border-bottom: none; }

        .btn { padding: 8px 16px; border: 1px solid #d9d9d9; border-radius: 6px; cursor: pointer; font-size: 14px; transition: all 0.3s; background: #fff; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-secondary { background: #fff; color: #595959; border-color: #d9d9d9; }
        .btn-secondary:hover { background: #f5f5f5; border-color: #40a9ff; color: #40a9ff; }

        .empty-state { text-align: center; padding: 40px; color: var(--text-secondary-color); }
        .empty-state i { display: block; margin-bottom: 16px; opacity: 0.5; }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            <div class="content">
                <div class="breadcrumb">
                    <a href="index.php">首頁</a> / <a href="settings.php">場次設定</a> / 查看報名名單
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3><?php echo htmlspecialchars($session['session_name']); ?> - 報名名單 (共 <?php echo count($registrations); ?> 人)</h3>
                        <a href="settings.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> 返回場次設定</a>
                    </div>
                    <div class="card-body table-container">
                        <?php if (empty($registrations)): ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                                <p>目前尚無人報名此場次。</p>
                            </div>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>姓名</th>
                                        <th>Email</th>
                                        <th>電話</th>
                                        <th>就讀學校</th>
                                        <th>年級</th>
                                        <th>體驗課程</th>
                                        <th>報名日期</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($registrations as $reg): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($reg['student_name']); ?></td>
                                        <td><?php echo htmlspecialchars($reg['email']); ?></td>
                                        <td><?php echo htmlspecialchars($reg['contact_phone']); ?></td>
                                        <td><?php echo htmlspecialchars($reg['school_name_display'] ?? $reg['school'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($reg['grade']); ?></td>
                                        <td>
                                            <?php
                                            $courses = [];
                                            if (!empty($reg['course_priority_1'])) {
                                                $dept_name_1 = isset($departments_map[$reg['course_priority_1']]) 
                                                    ? $departments_map[$reg['course_priority_1']] 
                                                    : $reg['course_priority_1'];
                                                $courses[] = '1. ' . htmlspecialchars($dept_name_1);
                                            }
                                            if (!empty($reg['course_priority_2'])) {
                                                $dept_name_2 = isset($departments_map[$reg['course_priority_2']]) 
                                                    ? $departments_map[$reg['course_priority_2']] 
                                                    : $reg['course_priority_2'];
                                                $courses[] = '2. ' . htmlspecialchars($dept_name_2);
                                            }
                                            echo empty($courses) ? '未選擇' : implode('<br>', $courses);
                                            ?>
                                        </td>
                                        <td><?php echo $reg['created_at'] ? date('Y/m/d H:i', strtotime($reg['created_at'])) : '-'; ?></td>
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
</body>
</html>