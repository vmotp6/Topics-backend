<?php
require_once __DIR__ . '/session_config.php';

// 1. 檢查登入狀態 - 支援前台和後台的登入狀態
$isLoggedIn = (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) ||
              (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true);

if (!$isLoggedIn) {
    header("Location: login.php");
    exit;
}

// 2. 引入設定檔
require_once '../../Topics-frontend/frontend/config.php';

// 3. 獲取使用者資訊與權限判斷
$user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['role'] ?? '';
$username = $_SESSION['username'] ?? '';
$user_display_name = $_SESSION['name'] ?? $username;

// 判斷角色群組
$is_admin = in_array($user_role, ['ADM', 'admin', '管理員']);
$is_staff = in_array($user_role, ['STA', 'staff', '行政人員']);
$is_director = in_array($user_role, ['DI', 'director', '主任']);
$is_teacher = in_array($user_role, ['TEA', 'teacher', '老師']);

// 檢查用戶角色是否被允許進入後台
// 只允許管理員、行政人員、主任進入後台
$allowed_backend_roles = ['ADM', 'STA', 'DI', '管理員', '行政人員', '主任'];
if (!in_array($user_role, $allowed_backend_roles)) {
    // 角色不允許進入後台，清除登入狀態
    $_SESSION['admin_logged_in'] = false;
    session_destroy();
    header("Location: login.php");
    exit;
}

// 全域權限標記 (Admin 或 Staff)
$is_super_user = ($is_admin || $is_staff);

// 4. 獲取使用者所屬科系 (針對主任/老師)
$user_department_code = null;
$user_department_name = '';

try {
    $conn = getDatabaseConnection();
    
    // 如果是主任或老師，查詢所屬科系
    if ($is_director || $is_teacher) {
        // 優先查 director 表，再查 teacher 表
        $stmt_dept = $conn->prepare("
            SELECT d.code, d.name 
            FROM (
                SELECT department as code FROM director WHERE user_id = ?
                UNION
                SELECT department as code FROM teacher WHERE user_id = ?
            ) as user_dept
            JOIN departments d ON user_dept.code = d.code
            LIMIT 1
        ");
        $stmt_dept->bind_param("ii", $user_id, $user_id);
        $stmt_dept->execute();
        $res_dept = $stmt_dept->get_result();
        if ($row = $res_dept->fetch_assoc()) {
            $user_department_code = $row['code'];
            $user_department_name = $row['name'];
        }
    }
    
    // 特殊帳號處理 (相容舊邏輯)
    if ($username === 'IMD') {
        $user_department_code = 'IM';
        $user_department_name = '資訊管理科';
    } elseif ($username === 'FLD' || $username === 'AF') {
        $user_department_code = 'AF';
        $user_department_name = '應用外語科';
    }

} catch (Exception $e) {
    error_log("首頁初始化錯誤: " . $e->getMessage());
}

// 設置頁面標題
$page_title = '首頁';
if ($user_department_code) {
    $page_title .= ' - ' . htmlspecialchars($user_department_name);
}

// --- 資料查詢區 (Data Fetching) ---

// 初始化數據
$stats = [
    'pending_reviews' => 0,      // 待審核續招
    'today_enrollments' => 0,    // 今日新增意願
    'unassigned_recommends' => 0,// 未分配推薦
    'upcoming_sessions' => 0     // 近期場次報名數
];
$todos = []; // 待辦事項
$quotas = []; // 名額數據
$sessions = []; // 近期場次

try {
    // A. 統計數據查詢
    
    // 1. 待審核續招 (Continued Admission Pending)
    $sql_pending = "SELECT COUNT(*) as cnt FROM continued_admission WHERE status = 'PE'";
    if ($user_department_code) {
        // 如果有科系限制，只查該科系相關的申請
        // 這裡假設 choices 欄位存 JSON，需用 LIKE 或 JSON 函數過濾
        $dept_search = "%" . $conn->real_escape_string($user_department_name) . "%";
        $sql_pending .= " AND (choices LIKE '$dept_search')"; 
    }
    $res = $conn->query($sql_pending);
    $stats['pending_reviews'] = $res ? $res->fetch_assoc()['cnt'] : 0;

    // 2. 今日新增意願 (Enrollment Intention Today)
    $sql_today = "SELECT COUNT(*) as cnt FROM enrollment_intention WHERE DATE(created_at) = CURDATE()";
    if ($user_department_code) {
        // 簡易過濾：檢查 intention 欄位是否包含科系代碼或名稱 (視資料庫儲存方式而定，這裡做模糊比對)
        $code_search = $conn->real_escape_string($user_department_code);
        $name_search = $conn->real_escape_string($user_department_name);
        // 需要 Join enrollment_choices 比較準確，這裡為求首頁效能先做簡化查詢或 Join
        $sql_today = "
            SELECT COUNT(DISTINCT ei.id) as cnt 
            FROM enrollment_intention ei
            LEFT JOIN enrollment_choices ec ON ei.id = ec.enrollment_id
            WHERE DATE(ei.created_at) = CURDATE()
            AND (ec.department_code = '$code_search' OR ei.assigned_department = '$code_search')
        ";
    }
    $res = $conn->query($sql_today);
    $stats['today_enrollments'] = $res ? $res->fetch_assoc()['cnt'] : 0;

    // 3. 未分配/待處理推薦 (Recommendations)
    if ($is_super_user) {
        // 管理員：看未分配部門的
        $sql_rec = "SELECT COUNT(*) as cnt FROM enrollment_intention WHERE assigned_department IS NULL";
    } elseif ($user_department_code) {
        // 主任：看已分配給自己但未分配給老師的，或是尚未處理的
        $dept = $conn->real_escape_string($user_department_code);
        $sql_rec = "SELECT COUNT(*) as cnt FROM enrollment_intention WHERE assigned_department = '$dept' AND assigned_teacher_id IS NULL";
    } else {
        $sql_rec = "SELECT 0 as cnt";
    }
    $res = $conn->query($sql_rec);
    $stats['unassigned_recommends'] = $res ? $res->fetch_assoc()['cnt'] : 0;

    // 4. 近期說明會總報名 (Upcoming Sessions)
    $sql_sess_count = "
        SELECT COUNT(aa.id) as cnt 
        FROM admission_applications aa
        JOIN admission_sessions s ON aa.session_id = s.id
        WHERE s.session_date >= CURDATE()
    ";
    $res = $conn->query($sql_sess_count);
    $stats['upcoming_sessions'] = $res ? $res->fetch_assoc()['cnt'] : 0;


    // B. 科系名額數據 (Quota)
    $sql_quota = "
        SELECT d.name, dq.total_quota, 
        (SELECT COUNT(*) FROM continued_admission ca 
         WHERE ca.status='approved' 
         AND ca.choices LIKE CONCAT('%', d.name, '%')) as enrolled
        FROM departments d
        LEFT JOIN department_quotas dq ON d.code = dq.department_code
        WHERE dq.is_active = 1
    ";
    if ($user_department_code) {
        $sql_quota .= " AND d.code = '" . $conn->real_escape_string($user_department_code) . "'";
    }
    $sql_quota .= " ORDER BY d.code";
    
    $res_quota = $conn->query($sql_quota);
    if ($res_quota) {
        while($row = $res_quota->fetch_assoc()) {
            // 計算百分比
            $total = intval($row['total_quota']);
            $enrolled = intval($row['enrolled']);
            $percent = $total > 0 ? round(($enrolled / $total) * 100) : 0;
            $row['percent'] = $percent;
            $quotas[] = $row;
        }
    }

    // C. 待辦事項 (Latest Pending Items)
    // 這裡取最新的 5 筆待審核續招資料
    $sql_todo = "
        SELECT id, name, school, created_at, '續招審核' as type
        FROM continued_admission 
        WHERE status = 'PE'
    ";
    if ($user_department_code) {
        $dept_search = "%" . $conn->real_escape_string($user_department_name) . "%";
        $sql_todo .= " AND choices LIKE '$dept_search'";
    }
    $sql_todo .= " ORDER BY created_at DESC LIMIT 5";
    $res_todo = $conn->query($sql_todo);
    if ($res_todo) {
        $todos = $res_todo->fetch_all(MYSQLI_ASSOC);
    }

    // D. 近期場次列表
    $sql_sessions = "
        SELECT s.session_name, s.session_date, s.session_type, s.max_participants,
        (SELECT COUNT(*) FROM admission_applications aa WHERE aa.session_id = s.id) as current_count
        FROM admission_sessions s
        WHERE s.session_date >= CURDATE()
        ORDER BY s.session_date ASC LIMIT 3
    ";
    $res_sessions = $conn->query($sql_sessions);
    if ($res_sessions) {
        $sessions = $res_sessions->fetch_all(MYSQLI_ASSOC);
    }

} catch (Exception $e) {
    error_log("首頁資料查詢失敗: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Topics 後台管理系統</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1890ff;
            --secondary-color: #722ed1;
            --success-color: #52c41a;
            --warning-color: #faad14;
            --danger-color: #f5222d;
            --text-color: #262626;
            --bg-color: #f0f2f5;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg-color); color: var(--text-color); }
        
        .dashboard { display: flex; min-height: 100vh; }
        .content { padding: 24px; width: 100%; overflow-x: hidden; }
        
        /* 歡迎橫幅 */
        .welcome-banner {
            background: linear-gradient(135deg, #fff 0%, #f0f5ff 100%);
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 24px;
            border: 1px solid #e6f7ff;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.02);
        }
        .welcome-text h2 { font-size: 24px; margin-bottom: 8px; color: #003a8c; }
        .welcome-text p { color: #595959; margin: 0; }
        .date-badge { background: #fff; padding: 8px 16px; border-radius: 20px; font-weight: 500; color: var(--primary-color); box-shadow: 0 2px 4px rgba(0,0,0,0.05); }

        /* 數據卡片區 */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: #fff;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            display: flex;
            align-items: center;
            transition: transform 0.2s;
            border-left: 4px solid transparent;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        
        .stat-card.danger { border-left-color: var(--danger-color); }
        .stat-card.success { border-left-color: var(--success-color); }
        .stat-card.warning { border-left-color: var(--warning-color); }
        .stat-card.primary { border-left-color: var(--primary-color); }

        .stat-icon {
            width: 48px; height: 48px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; margin-right: 16px;
        }
        .bg-red-light { background: #fff1f0; color: var(--danger-color); }
        .bg-green-light { background: #f6ffed; color: var(--success-color); }
        .bg-orange-light { background: #fff7e6; color: var(--warning-color); }
        .bg-blue-light { background: #e6f7ff; color: var(--primary-color); }

        .stat-content h3 { font-size: 28px; font-weight: 700; margin: 0; line-height: 1.2; }
        .stat-content p { color: #8c8c8c; font-size: 14px; margin: 0; }

        /* 主內容區 Grid */
        .main-grid {
            display: grid;
            grid-template-columns: 2fr 1fr; /* 左寬右窄 */
            gap: 24px;
            margin-bottom: 24px;
        }
        @media (max-width: 992px) { .main-grid { grid-template-columns: 1fr; } }

        .panel {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            padding: 24px;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        .panel-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px; border-bottom: 1px solid #f0f0f0; padding-bottom: 12px;
        }
        .panel-title { font-size: 18px; font-weight: 600; color: #262626; display: flex; align-items: center; gap: 8px; }
        
        /* 列表樣式 */
        .list-group { display: flex; flex-direction: column; gap: 12px; }
        .list-item {
            display: flex; justify-content: space-between; align-items: center;
            padding: 12px; background: #fafafa; border-radius: 8px;
            transition: background 0.2s;
        }
        .list-item:hover { background: #f0f0f0; }
        .list-item-main { display: flex; flex-direction: column; gap: 4px; }
        .list-item-title { font-weight: 500; font-size: 15px; }
        .list-item-sub { font-size: 12px; color: #8c8c8c; }
        
        /* 進度條樣式 */
        .quota-item { margin-bottom: 16px; }
        .quota-header { display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 6px; }
        .progress-track { background: #f5f5f5; height: 10px; border-radius: 5px; overflow: hidden; }
        .progress-fill { height: 100%; border-radius: 5px; transition: width 0.5s ease; }
        
        /* 按鈕 */
        .btn-sm { padding: 4px 12px; font-size: 13px; border-radius: 4px; text-decoration: none; cursor: pointer; border: 1px solid transparent; }
        .btn-outline { border-color: #d9d9d9; color: #595959; background: #fff; }
        .btn-outline:hover { color: var(--primary-color); border-color: var(--primary-color); }
        .btn-primary-sm { background: var(--primary-color); color: #fff; }
        .btn-primary-sm:hover { background: #40a9ff; }

        /* 系統狀態燈 */
        .status-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: 6px; }
        .dot-green { background: #52c41a; box-shadow: 0 0 0 2px rgba(82,196,26,0.2); }
        .dot-red { background: #ff4d4f; box-shadow: 0 0 0 2px rgba(255,77,79,0.2); }
        .dot-gray { background: #d9d9d9; }

    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            
            <div class="content">
                <div class="welcome-banner">
                    <div class="welcome-text">
                        <h2>早安，<?php echo htmlspecialchars($user_display_name); ?>！</h2>
                        <p>
                            <?php if($user_department_name): ?>
                                目前正在檢視 <strong><?php echo htmlspecialchars($user_department_name); ?></strong> 的招生數據
                            <?php else: ?>
                                這裡是全校招生戰情中心，掌握最新動態
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="date-badge">
                        <i class="far fa-calendar-alt"></i> <?php echo date('Y年m月d日 l'); ?>
                    </div>
                </div>

                <div class="stats-grid">
                    <a href="continued_admission_list.php" style="text-decoration: none;">
                        <div class="stat-card danger">
                            <div class="stat-icon bg-red-light">
                                <i class="fas fa-bell"></i>
                            </div>
                            <div class="stat-content">
                                <h3><?php echo $stats['pending_reviews']; ?></h3>
                                <p style="color: var(--danger-color);">續招待審核</p>
                            </div>
                        </div>
                    </a>

                    <a href="enrollment_list.php" style="text-decoration: none;">
                        <div class="stat-card success">
                            <div class="stat-icon bg-green-light">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="stat-content">
                                <h3><?php echo $stats['today_enrollments']; ?></h3>
                                <p>今日新增意願</p>
                            </div>
                        </div>
                    </a>

                    <a href="enrollment_list.php" style="text-decoration: none;">
                        <div class="stat-card warning">
                            <div class="stat-icon bg-orange-light">
                                <i class="fas fa-user-clock"></i>
                            </div>
                            <div class="stat-content">
                                <h3><?php echo $stats['unassigned_recommends']; ?></h3>
                                <p>推薦待分配/處理</p>
                            </div>
                        </div>
                    </a>

                    <a href="settings.php" style="text-decoration: none;">
                        <div class="stat-card primary">
                            <div class="stat-icon bg-blue-light">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="stat-content">
                                <h3><?php echo $stats['upcoming_sessions']; ?></h3>
                                <p>近期說明會報名</p>
                            </div>
                        </div>
                    </a>
                </div>

                <div class="main-grid">
                    <div class="panel">
                        <div class="panel-header">
                            <div class="panel-title"><i class="fas fa-chart-pie" style="color: var(--primary-color);"></i> 科系名額達成率</div>
                            <?php if($is_super_user || $is_director): ?>
                            <a href="department_quota_management.php" class="btn-sm btn-outline">管理名額</a>
                            <?php endif; ?>
                        </div>
                        <div style="flex: 1; overflow-y: auto; max-height: 400px;">
                            <?php if (empty($quotas)): ?>
                                <div class="empty-state" style="text-align: center; color: #999; padding: 40px;">
                                    <i class="fas fa-inbox fa-2x"></i><br>尚無名額資料
                                </div>
                            <?php else: ?>
                                <?php foreach ($quotas as $quota): 
                                    $color = '#1890ff';
                                    if ($quota['percent'] >= 100) $color = '#f5222d'; // 額滿紅
                                    else if ($quota['percent'] >= 80) $color = '#faad14'; // 快滿橘
                                    else if ($quota['percent'] >= 50) $color = '#52c41a'; // 正常綠
                                ?>
                                <div class="quota-item">
                                    <div class="quota-header">
                                        <span style="font-weight: 600;"><?php echo htmlspecialchars($quota['name']); ?></span>
                                        <span><?php echo $quota['enrolled']; ?> / <?php echo $quota['total_quota']; ?> (<?php echo $quota['percent']; ?>%)</span>
                                    </div>
                                    <div class="progress-track">
                                        <div class="progress-fill" style="width: <?php echo $quota['percent']; ?>%; background-color: <?php echo $color; ?>;"></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-header">
                            <div class="panel-title"><i class="fas fa-clipboard-check" style="color: var(--warning-color);"></i> 待處理事項</div>
                        </div>
                        <div class="list-group" style="overflow-y: auto; max-height: 400px;">
                            <?php if (empty($todos)): ?>
                                <div style="text-align: center; color: #999; padding: 20px;">
                                    <i class="fas fa-check-circle fa-2x" style="color: #52c41a; margin-bottom: 8px;"></i>
                                    <p>太棒了！目前沒有待辦事項</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($todos as $todo): ?>
                                <div class="list-item">
                                    <div class="list-item-main">
                                        <div class="list-item-title"><?php echo htmlspecialchars($todo['name']); ?> - <?php echo htmlspecialchars($todo['type']); ?></div>
                                        <div class="list-item-sub">
                                            <?php echo htmlspecialchars($todo['school']); ?> 
                                            | <?php echo date('m/d H:i', strtotime($todo['created_at'])); ?>
                                        </div>
                                    </div>
                                    <a href="continued_admission_detail.php?id=<?php echo $todo['id']; ?>&action=review" class="btn-sm btn-primary-sm">審核</a>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            
                            <?php if ($stats['unassigned_recommends'] > 0): ?>
                            <div class="list-item" style="background: #fffbe6; border: 1px solid #ffe58f;">
                                <div class="list-item-main">
                                    <div class="list-item-title" style="color: #d48806;">有 <?php echo $stats['unassigned_recommends']; ?> 筆推薦名單未分配</div>
                                </div>
                                <a href="enrollment_list.php" class="btn-sm btn-outline" style="color: #d48806; border-color: #d48806;">前往分配</a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="main-grid" style="grid-template-columns: 1fr 1fr;">
                    <div class="panel">
                        <div class="panel-header">
                            <div class="panel-title"><i class="fas fa-calendar-week" style="color: #722ed1;"></i> 近期說明會</div>
                            <a href="settings.php" class="btn-sm btn-outline">查看全部</a>
                        </div>
                        <div class="list-group">
                            <?php if (empty($sessions)): ?>
                                <p style="color: #999; text-align: center;">近期無活動</p>
                            <?php else: ?>
                                <?php foreach ($sessions as $sess): 
                                    $is_full = ($sess['max_participants'] > 0 && $sess['current_count'] >= $sess['max_participants']);
                                ?>
                                <div class="list-item">
                                    <div class="list-item-main">
                                        <div class="list-item-title"><?php echo htmlspecialchars($sess['session_name']); ?></div>
                                        <div class="list-item-sub">
                                            <i class="far fa-clock"></i> <?php echo date('Y/m/d H:i', strtotime($sess['session_date'])); ?>
                                            | <?php echo ($sess['session_type'] == 1) ? '線上' : '實體'; ?>
                                        </div>
                                    </div>
                                    <div style="text-align: right;">
                                        <span class="badge" style="background: <?php echo $is_full ? '#ff4d4f' : '#e6f7ff'; ?>; color: <?php echo $is_full ? '#fff' : '#1890ff'; ?>; padding: 4px 8px; border-radius: 4px; font-size: 12px;">
                                            <?php echo $sess['current_count']; ?> / <?php echo $sess['max_participants'] ?: '∞'; ?> 人
                                        </span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-header">
                            <div class="panel-title"><i class="fas fa-server" style="color: #595959;"></i> 系統服務狀態</div>
                        </div>
                        <div class="list-group">
                            <div class="list-item">
                                <div class="list-item-main">
                                    <div class="list-item-title">
                                        <span class="status-dot dot-green"></span> 資料庫連線 (MySQL)
                                    </div>
                                    <div class="list-item-sub">100.79.58.120</div>
                                </div>
                                <span style="color: var(--success-color); font-size: 13px;">正常</span>
                            </div>
                            
                            <div class="list-item">
                                <div class="list-item-main">
                                    <div class="list-item-title" id="ai-status-text">
                                        <span class="status-dot dot-gray" id="ai-status-dot"></span> AI 模型服務 (Ollama)
                                    </div>
                                    <div class="list-item-sub" id="ai-status-model">檢查中...</div>
                                </div>
                                <span style="font-size: 13px;" id="ai-status-badge">...</span>
                            </div>
                        </div>
                        
                        <div style="margin-top: 20px; border-top: 1px dashed #f0f0f0; padding-top: 12px;">
                            <h5 style="font-size: 14px; margin-bottom: 10px;">快速入口</h5>
                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                <a href="activity_records.php" class="btn-sm btn-outline"><i class="fas fa-chart-bar"></i> 統計報表</a>
                                <?php if($is_super_user): ?>
                                <a href="users.php" class="btn-sm btn-outline"><i class="fas fa-users"></i> 用戶管理</a>
                                <a href="ollama_admin.php" class="btn-sm btn-outline"><i class="fas fa-robot"></i> AI 設定</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // 檢查 AI 服務狀態
        fetch('../../Topics-frontend/backend/api/ollama/ollama_api.php?action=check_health')
            .then(response => response.json())
            .then(data => {
                const dot = document.getElementById('ai-status-dot');
                const badge = document.getElementById('ai-status-badge');
                const modelText = document.getElementById('ai-status-model');
                
                if (data.success) {
                    dot.className = 'status-dot dot-green';
                    badge.style.color = 'var(--success-color)';
                    badge.textContent = '正常';
                    modelText.textContent = `可用模型數: ${data.models ? data.models.length : 0}`;
                } else {
                    dot.className = 'status-dot dot-red';
                    badge.style.color = 'var(--danger-color)';
                    badge.textContent = '異常';
                    modelText.textContent = data.message || '無法連線';
                }
            })
            .catch(err => {
                const dot = document.getElementById('ai-status-dot');
                const badge = document.getElementById('ai-status-badge');
                const modelText = document.getElementById('ai-status-model');
                
                dot.className = 'status-dot dot-red';
                badge.style.color = 'var(--danger-color)';
                badge.textContent = '斷線';
                modelText.textContent = 'API 無回應';
            });
    });
    </script>
</body>
</html>