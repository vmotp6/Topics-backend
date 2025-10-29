<?php
session_start();

// 檢查是否已登入，如果沒有登入則跳轉到登入頁面
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 設置頁面標題
$page_title = (isset($_SESSION['username']) && $_SESSION['username'] === 'IMD') ? '資管科招生中心' : '招生中心';

// 檢查是否為IMD用戶
$is_imd_user = (isset($_SESSION['username']) && $_SESSION['username'] === 'IMD');

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

// 獲取所有報名資料
$all_applications = [];

// 1. 就讀意願登錄 (cooperation_upload)
try {
    if ($is_imd_user) {
        // IMD用戶只能看到資管科相關的就讀意願
        $stmt = $pdo->prepare("SELECT 
            id, name, identity, gender, phone1, phone2, email, 
            intention1, system1, intention2, system2, intention3, system3,
            junior_high, current_grade, line_id, facebook, recommended_teacher, remarks,
            created_at, '就讀意願登錄' as source_type
            FROM enrollment_applications 
            WHERE intention1 LIKE '%資管%' OR intention1 LIKE '%資訊管理%' 
            OR intention2 LIKE '%資管%' OR intention2 LIKE '%資訊管理%' 
            OR intention3 LIKE '%資管%' OR intention3 LIKE '%資訊管理%'
            ORDER BY created_at DESC");
    } else {
        // 一般管理員可以看到所有就讀意願
        $stmt = $pdo->prepare("SELECT 
            id, name, identity, gender, phone1, phone2, email, 
            intention1, system1, intention2, system2, intention3, system3,
            junior_high, current_grade, line_id, facebook, recommended_teacher, remarks,
            created_at, '就讀意願登錄' as source_type
            FROM enrollment_applications 
            ORDER BY created_at DESC");
    }
    $stmt->execute();
    $enrollment_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($enrollment_data as $row) {
        $all_applications[] = $row;
    }
} catch (PDOException $e) {
    error_log("獲取就讀意願登錄資料失敗: " . $e->getMessage());
}

// 2. 續招報名 (continued_admission)
try {
    if ($is_imd_user) {
        // IMD用戶只能看到資管科相關的續招報名
        $stmt = $pdo->prepare("SELECT 
            id, name, id_number, birth_year, birth_month, birth_day, gender,
            phone, mobile, school_city, school_name, guardian_name as guardian, guardian_phone, guardian_mobile,
            self_intro, skills, choices, status, review_notes, reviewed_at,
            created_at, '續招報名' as source_type
            FROM continued_admission 
            WHERE JSON_CONTAINS(choices, JSON_QUOTE('資訊管理科')) 
            OR JSON_CONTAINS(choices, JSON_QUOTE('資管科'))
            OR JSON_SEARCH(choices, 'one', '%資管%') IS NOT NULL
            OR JSON_SEARCH(choices, 'one', '%資訊管理%') IS NOT NULL
            ORDER BY created_at DESC");
    } else {
        // 一般管理員可以看到所有續招報名
        $stmt = $pdo->prepare("SELECT 
            id, name, id_number, birth_year, birth_month, birth_day, gender,
            phone, mobile, school_city, school_name, guardian_name as guardian, guardian_phone, guardian_mobile,
            self_intro, skills, choices, status, review_notes, reviewed_at,
            created_at, '續招報名' as source_type
            FROM continued_admission 
            ORDER BY created_at DESC");
    }
    $stmt->execute();
    $continued_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($continued_data as $row) {
        $all_applications[] = $row;
    }
} catch (PDOException $e) {
    error_log("獲取續招報名資料失敗: " . $e->getMessage());
}

// 3. 入學說明會報名 (admission)
try {
    if ($is_imd_user) {
        // IMD用戶只能看到資管科相關的入學說明會報名
        $stmt = $pdo->prepare("SELECT 
            id, student_name as name, email, school_name, grade, parent_name, contact_phone, line_id,
            session_choice, course_priority_1, course_priority_2, receive_info,
            created_at, '入學說明會報名' as source_type
            FROM admission_applications 
            WHERE course_priority_1 LIKE '%資管%' OR course_priority_1 LIKE '%資訊管理%' 
            OR course_priority_2 LIKE '%資管%' OR course_priority_2 LIKE '%資訊管理%'
            ORDER BY created_at DESC");
    } else {
        // 一般管理員可以看到所有入學說明會報名
        $stmt = $pdo->prepare("SELECT 
            id, student_name as name, email, school_name, grade, parent_name, contact_phone, line_id,
            session_choice, course_priority_1, course_priority_2, receive_info,
            created_at, '入學說明會報名' as source_type
            FROM admission_applications 
            ORDER BY created_at DESC");
    }
    $stmt->execute();
    $admission_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($admission_data as $row) {
        $all_applications[] = $row;
    }
} catch (PDOException $e) {
    error_log("獲取入學說明會報名資料失敗: " . $e->getMessage());
}

// 4. 招生推薦報名 (admission_recommend)
try {
    if ($is_imd_user) {
        // IMD用戶只能看到資管科相關的招生推薦報名
        $stmt = $pdo->prepare("SELECT 
            id, student_name as name, student_school as school_name, student_grade as grade, 
            student_phone as contact_phone, student_email as email, student_line_id as line_id,
            recommender_name, recommender_student_id, recommender_department, recommendation_reason,
            student_interest, additional_info, status, enrollment_status,
            created_at, '招生推薦報名' as source_type
            FROM admission_recommendations 
            WHERE recommender_department LIKE '%資管%' OR recommender_department LIKE '%資訊管理%'
            OR student_interest LIKE '%資管%' OR student_interest LIKE '%資訊管理%'
            ORDER BY created_at DESC");
    } else {
        // 一般管理員可以看到所有招生推薦報名
        $stmt = $pdo->prepare("SELECT 
            id, student_name as name, student_school as school_name, student_grade as grade, 
            student_phone as contact_phone, student_email as email, student_line_id as line_id,
            recommender_name, recommender_student_id, recommender_department, recommendation_reason,
            student_interest, additional_info, status, enrollment_status,
            created_at, '招生推薦報名' as source_type
            FROM admission_recommendations 
            ORDER BY created_at DESC");
    }
    $stmt->execute();
    $recommend_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($recommend_data as $row) {
        $all_applications[] = $row;
    }
} catch (PDOException $e) {
    error_log("獲取招生推薦報名資料失敗: " . $e->getMessage());
}

// 按時間排序
usort($all_applications, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

// 統計資料
$stats = [
    'total' => count($all_applications),
    'enrollment' => count($enrollment_data ?? []),
    'continued' => count($continued_data ?? []),
    'admission' => count($admission_data ?? []),
    'recommend' => count($recommend_data ?? [])
];
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>招生中心 - Topics 後台管理系統</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f0f2f5;
            color: #262626;
            overflow-x: hidden;
        }
        
        /* 主介面樣式 */
        .dashboard {
            display: flex;
            min-height: 100vh;
        }
        
        /* 內容區域 */
        .content {
            padding: 24px;
        }
        
        /* 統計卡片 */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid #f0f0f0;
            transition: all 0.3s;
        }
        
        .stat-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            transform: translateY(-2px);
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
        .stat-icon.enrollment { 
            background: linear-gradient(135deg, #52c41a, #73d13d); 
        }
        .stat-icon.continued { 
            background: linear-gradient(135deg, #fa8c16, #ffa940); 
        }
        .stat-icon.admission { 
            background: linear-gradient(135deg, #722ed1, #9254de); 
        }
        .stat-icon.recommend { 
            background: linear-gradient(135deg, #eb2f96, #f759ab); 
        }
        
        .stat-info h3 {
            font-size: 24px;
            margin-bottom: 4px;
            color: #262626;
            font-weight: 600;
        }
        
        .stat-info p {
            color: #8c8c8c;
            font-weight: 500;
            font-size: 14px;
        }
        
        /* 篩選區域 */
        .filter-section {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid #f0f0f0;
            margin-bottom: 24px;
        }
        
        .filter-row {
            display: flex;
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .sort-row {
            display: flex;
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #f0f0f0;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .filter-group label {
            font-size: 14px;
            color: #595959;
            font-weight: 500;
        }
        
        .filter-group select,
        .filter-group input {
            padding: 8px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            font-size: 14px;
            min-width: 150px;
        }
        
        .filter-group select:focus,
        .filter-group input:focus {
            outline: none;
            border-color: #1890ff;
            box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.2);
        }
        
        .search-btn {
            padding: 8px 16px;
            background: #1890ff;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .search-btn:hover {
            background: #40a9ff;
        }
        
        /* 資料表格 */
        .data-section {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid #f0f0f0;
            overflow: hidden;
        }
        
        .data-header {
            padding: 20px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .data-header h3 {
            font-size: 18px;
            color: #262626;
            font-weight: 600;
        }
        
        .data-count {
            color: #8c8c8c;
            font-size: 14px;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th {
            background: #fafafa;
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            color: #262626;
            border-bottom: 1px solid #f0f0f0;
            font-size: 14px;
        }
        
        .data-table th.sortable {
            cursor: pointer;
            user-select: none;
            transition: background-color 0.3s;
        }
        
        .data-table th.sortable:hover {
            background: #f0f0f0;
        }
        
        .data-table th.sortable i {
            margin-left: 8px;
            opacity: 0.5;
            transition: opacity 0.3s;
        }
        
        .data-table th.sortable:hover i {
            opacity: 1;
        }
        
        .data-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 14px;
            color: #595959;
        }
        
        .data-table tbody tr:hover {
            background: #fafafa;
        }
        
        .source-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            color: white;
        }
        
        .source-badge.enrollment {
            background: #52c41a;
        }
        .source-badge.continued {
            background: #fa8c16;
        }
        .source-badge.admission {
            background: #722ed1;
        }
        .source-badge.recommend {
            background: #eb2f96;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
         .status-badge.pending {
             background: #fff7e6;
             color: #d46b08;
         }
         .status-badge.contacted {
             background: #e6f7ff;
             color: #0958d9;
         }
         .status-badge.registered {
             background: #f6ffed;
             color: #389e0d;
         }
         .status-badge.enrolled {
             background: #f6ffed;
             color: #389e0d;
         }
         .status-badge.approved {
             background: #f6ffed;
             color: #389e0d;
             font-weight: 600;
         }
         .status-badge.waitlist {
             background: #fff7e6;
             color: #fa8c16;
         }
         .status-badge.rejected {
             background: #fff2f0;
             color: #cf1322;
         }
        
        .action-btn {
            padding: 4px 8px;
            border: 1px solid #d9d9d9;
            border-radius: 4px;
            background: #fff;
            color: #595959;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s;
        }
        
        .action-btn:hover {
            border-color: #1890ff;
            color: #1890ff;
        }
        
        .pagination {
            padding: 16px 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            border-top: 1px solid #f0f0f0;
        }
        
        .pagination button {
            padding: 6px 12px;
            border: 1px solid #d9d9d9;
            background: #fff;
            color: #595959;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .pagination button:hover {
            border-color: #1890ff;
            color: #1890ff;
        }
        
        .pagination button.active {
            background: #1890ff;
            color: white;
            border-color: #1890ff;
        }
        
        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* 響應式設計 */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-row {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .data-table {
                font-size: 12px;
            }
            
            .data-table th,
            .data-table td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <!-- 主介面 -->
    <div class="dashboard">
        <!-- 引入側邊欄 -->
        <?php include 'sidebar.php'; ?>
        
        <!-- 主內容區 -->
        <div class="main-content" id="mainContent">
            <!-- 引入標題欄 -->
            <?php include 'header.php'; ?>
            
            <!-- 內容區域 -->
            <div class="content">
                <div class="page-header">
                    <h1><i class="fas fa-graduation-cap"></i> 招生中心</h1>
                    <p>查看所有學生的報名資料</p>
                </div>
                
                <!-- 統計卡片 -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon total">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['total']; ?></h3>
                            <p>總報名數</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon enrollment">
                            <i class="fas fa-heart"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['enrollment']; ?></h3>
                            <p>就讀意願登錄</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon continued">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['continued']; ?></h3>
                            <p>續招報名</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon admission">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['admission']; ?></h3>
                            <p>入學說明會</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon recommend">
                            <i class="fas fa-user-friends"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['recommend']; ?></h3>
                            <p>招生推薦</p>
                        </div>
                    </div>
                </div>
                
                <!-- 篩選和排序區域 -->
                <div class="filter-section">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label>報名類型</label>
                            <select id="sourceFilter">
                                <option value="">全部</option>
                                <option value="就讀意願登錄">就讀意願登錄</option>
                                <option value="續招報名">續招報名</option>
                                <option value="入學說明會報名">入學說明會報名</option>
                                <option value="招生推薦報名">招生推薦報名</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label>姓名搜尋</label>
                            <input type="text" id="nameFilter" placeholder="輸入姓名">
                        </div>
                        
                        <div class="filter-group">
                            <label>學校搜尋</label>
                            <input type="text" id="schoolFilter" placeholder="輸入學校名稱">
                        </div>
                        
                        <div class="filter-group">
                            <label>日期範圍</label>
                            <input type="date" id="dateFrom">
                        </div>
                        
                        <div class="filter-group">
                            <label>至</label>
                            <input type="date" id="dateTo">
                        </div>
                        
                        <button class="search-btn" onclick="filterData()">
                            <i class="fas fa-search"></i> 搜尋
                        </button>
                    </div>
                    
                    <!-- 排序區域 -->
                    <div class="sort-row">
                        <div class="filter-group">
                            <label>排序方式</label>
                            <select id="sortBy">
                                <option value="created_at">報名時間</option>
                                <option value="name">姓名</option>
                                <option value="source_type">報名類型</option>
                                <option value="school">學校</option>
                                <option value="id">ID</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label>排序順序</label>
                            <select id="sortOrder">
                                <option value="desc">新到舊</option>
                                <option value="asc">舊到新</option>
                            </select>
                        </div>
                        
                        <button class="search-btn" onclick="sortData()">
                            <i class="fas fa-sort"></i> 排序
                        </button>
                        
                        <button class="search-btn" onclick="resetFilters()" style="background: #6c757d;">
                            <i class="fas fa-undo"></i> 重置
                        </button>
                    </div>
                </div>
                
                <!-- 資料表格 -->
                <div class="data-section">
                    <div class="data-header">
                        <h3>報名資料列表</h3>
                        <div class="data-count" id="dataCount">共 <?php echo count($all_applications); ?> 筆資料</div>
                    </div>
                    
                    <div style="overflow-x: auto;">
                        <table class="data-table" id="dataTable">
                            <thead>
                                <tr>
                                    <th class="sortable" onclick="sortByColumn('id')">
                                        ID <i class="fas fa-sort"></i>
                                    </th>
                                    <th class="sortable" onclick="sortByColumn('name')">
                                        姓名 <i class="fas fa-sort"></i>
                                    </th>
                                    <th class="sortable" onclick="sortByColumn('source_type')">
                                        報名類型 <i class="fas fa-sort"></i>
                                    </th>
                                    <th>聯絡方式</th>
                                    <th class="sortable" onclick="sortByColumn('school')">
                                        學校/年級 <i class="fas fa-sort"></i>
                                    </th>
                                    <th>志願/興趣</th>
                                    <th>狀態</th>
                                    <th class="sortable" onclick="sortByColumn('created_at')">
                                        報名時間 <i class="fas fa-sort"></i>
                                    </th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody id="dataTableBody">
                                <?php foreach ($all_applications as $app): ?>
                                <tr data-source="<?php echo htmlspecialchars($app['source_type']); ?>">
                                    <td><?php echo $app['id']; ?></td>
                                    <td><?php echo htmlspecialchars($app['name'] ?? ''); ?></td>
                                    <td>
                                        <span class="source-badge <?php echo str_replace(['就讀意願登錄', '續招報名', '入學說明會報名', '招生推薦報名'], ['enrollment', 'continued', 'admission', 'recommend'], $app['source_type']); ?>">
                                            <?php echo htmlspecialchars($app['source_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php 
                                        $contact = [];
                                        if (!empty($app['phone1'])) $contact[] = $app['phone1'];
                                        if (!empty($app['phone'])) $contact[] = $app['phone'];
                                        if (!empty($app['mobile'])) $contact[] = $app['mobile'];
                                        if (!empty($app['contact_phone'])) $contact[] = $app['contact_phone'];
                                        if (!empty($app['student_phone'])) $contact[] = $app['student_phone'];
                                        echo htmlspecialchars(implode(' / ', array_slice($contact, 0, 2)));
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $school_info = [];
                                        if (!empty($app['junior_high'])) $school_info[] = $app['junior_high'];
                                        if (!empty($app['school_name'])) $school_info[] = $app['school_name'];
                                        if (!empty($app['student_school'])) $school_info[] = $app['student_school'];
                                        if (!empty($app['current_grade'])) $school_info[] = $app['current_grade'];
                                        if (!empty($app['grade'])) $school_info[] = $app['grade'];
                                        if (!empty($app['student_grade'])) $school_info[] = $app['student_grade'];
                                        echo htmlspecialchars(implode(' / ', array_slice($school_info, 0, 2)));
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $interests = [];
                                        if (!empty($app['intention1'])) $interests[] = $app['intention1'];
                                        if (!empty($app['course_priority_1'])) $interests[] = $app['course_priority_1'];
                                        if (!empty($app['student_interest'])) $interests[] = $app['student_interest'];
                                        if (!empty($app['choice_nursing'])) $interests[] = '護理科';
                                        if (!empty($app['choice_optometry'])) $interests[] = '視光科';
                                        if (!empty($app['choices'])) {
                                            $choices = json_decode($app['choices'], true);
                                            if (is_array($choices)) {
                                                $interests = array_merge($interests, $choices);
                                            }
                                        }
                                        echo htmlspecialchars(implode(' / ', array_slice($interests, 0, 2)));
                                        ?>
                                    </td>
                                     <td>
                                         <?php 
                                         $status = $app['status'] ?? 'pending';
                                         $status_text = '';
                                         $status_class = '';
                                         
                                         // 根據不同的報名類型和狀態顯示不同的文字
                                         switch ($app['source_type']) {
                                             case '續招報名':
                                                 switch ($status) {
                                                     case 'approved':
                                                         $status_text = '錄取';
                                                         $status_class = 'approved';
                                                         break;
                                                     case 'rejected':
                                                         $status_text = '不錄取';
                                                         $status_class = 'rejected';
                                                         break;
                                                     case 'waitlist':
                                                         $status_text = '備取';
                                                         $status_class = 'waitlist';
                                                         break;
                                                     case 'pending':
                                                     default:
                                                         $status_text = '待審核';
                                                         $status_class = 'pending';
                                                         break;
                                                 }
                                                 break;
                                             case '就讀意願登錄':
                                                 // 就讀意願登錄不需要審核，直接顯示為已報名
                                                 $status_text = '已報名';
                                                 $status_class = 'enrolled';
                                                 break;
                                             case '入學說明會報名':
                                                 $status_text = '已報名';
                                                 $status_class = 'enrolled';
                                                 break;
                                             case '招生推薦報名':
                                                 switch ($status) {
                                                     case 'contacted':
                                                         $status_text = '已聯繫';
                                                         $status_class = 'contacted';
                                                         break;
                                                     case 'registered':
                                                         $status_text = '已報名';
                                                         $status_class = 'registered';
                                                         break;
                                                     case 'rejected':
                                                         $status_text = '已拒絕';
                                                         $status_class = 'rejected';
                                                         break;
                                                     case 'pending':
                                                     default:
                                                         $status_text = '待處理';
                                                         $status_class = 'pending';
                                                         break;
                                                 }
                                                 break;
                                             default:
                                                 $status_text = '待處理';
                                                 $status_class = 'pending';
                                                 break;
                                         }
                                         ?>
                                         <span class="status-badge <?php echo $status_class; ?>">
                                             <?php echo $status_text; ?>
                                         </span>
                                     </td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($app['created_at'])); ?></td>
                                    <td>
                                        <button class="action-btn" onclick="viewDetails(<?php echo $app['id']; ?>, '<?php echo htmlspecialchars($app['source_type']); ?>')">
                                            <i class="fas fa-eye"></i> 查看
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // 儲存原始資料
        let originalData = [];
        
        // 頁面載入時儲存原始資料
        document.addEventListener('DOMContentLoaded', function() {
            const rows = document.querySelectorAll('#dataTableBody tr');
            originalData = Array.from(rows).map(row => ({
                element: row,
                source: row.getAttribute('data-source'),
                name: row.cells[1].textContent,
                school: row.cells[4].textContent,
                date: row.cells[7].textContent,
                id: row.cells[0].textContent
            }));
            
            // 設置預設日期範圍（最近30天）
            const today = new Date();
            const thirtyDaysAgo = new Date(today.getTime() - 30 * 24 * 60 * 60 * 1000);
            
            document.getElementById('dateTo').value = today.toISOString().split('T')[0];
            document.getElementById('dateFrom').value = thirtyDaysAgo.toISOString().split('T')[0];
        });
        
        // 篩選功能
        function filterData() {
            const sourceFilter = document.getElementById('sourceFilter').value;
            const nameFilter = document.getElementById('nameFilter').value.toLowerCase();
            const schoolFilter = document.getElementById('schoolFilter').value.toLowerCase();
            const dateFrom = document.getElementById('dateFrom').value;
            const dateTo = document.getElementById('dateTo').value;
            
            const rows = document.querySelectorAll('#dataTableBody tr');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const source = row.getAttribute('data-source');
                const name = row.cells[1].textContent.toLowerCase();
                const school = row.cells[4].textContent.toLowerCase();
                const date = row.cells[7].textContent.split(' ')[0]; // 只取日期部分
                
                let show = true;
                
                // 報名類型篩選
                if (sourceFilter && source !== sourceFilter) {
                    show = false;
                }
                
                // 姓名篩選
                if (nameFilter && !name.includes(nameFilter)) {
                    show = false;
                }
                
                // 學校篩選
                if (schoolFilter && !school.includes(schoolFilter)) {
                    show = false;
                }
                
                // 日期篩選
                if (dateFrom && date < dateFrom) {
                    show = false;
                }
                if (dateTo && date > dateTo) {
                    show = false;
                }
                
                row.style.display = show ? '' : 'none';
                if (show) visibleCount++;
            });
            
            document.getElementById('dataCount').textContent = `共 ${visibleCount} 筆資料`;
        }
        
        // 排序功能
        function sortData() {
            const sortBy = document.getElementById('sortBy').value;
            const sortOrder = document.getElementById('sortOrder').value;
            
            const tbody = document.getElementById('dataTableBody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            // 只對可見的資料進行排序
            const visibleRows = rows.filter(row => row.style.display !== 'none');
            
            visibleRows.sort((a, b) => {
                let aValue, bValue;
                
                switch (sortBy) {
                    case 'name':
                        aValue = a.cells[1].textContent;
                        bValue = b.cells[1].textContent;
                        break;
                    case 'source_type':
                        aValue = a.getAttribute('data-source');
                        bValue = b.getAttribute('data-source');
                        break;
                    case 'school':
                        aValue = a.cells[4].textContent;
                        bValue = b.cells[4].textContent;
                        break;
                    case 'id':
                        aValue = parseInt(a.cells[0].textContent);
                        bValue = parseInt(b.cells[0].textContent);
                        break;
                    case 'created_at':
                    default:
                        aValue = new Date(a.cells[7].textContent);
                        bValue = new Date(b.cells[7].textContent);
                        break;
                }
                
                if (sortBy === 'id') {
                    return sortOrder === 'asc' ? aValue - bValue : bValue - aValue;
                } else if (sortBy === 'created_at') {
                    return sortOrder === 'asc' ? aValue - bValue : bValue - aValue;
                } else {
                    // 字串排序
                    if (aValue < bValue) return sortOrder === 'asc' ? -1 : 1;
                    if (aValue > bValue) return sortOrder === 'asc' ? 1 : -1;
                    return 0;
                }
            });
            
            // 重新排列DOM元素
            visibleRows.forEach(row => tbody.appendChild(row));
            
            // 更新計數
            document.getElementById('dataCount').textContent = `共 ${visibleRows.length} 筆資料`;
        }
        
        // 重置篩選和排序
        function resetFilters() {
            // 重置所有篩選條件
            document.getElementById('sourceFilter').value = '';
            document.getElementById('nameFilter').value = '';
            document.getElementById('schoolFilter').value = '';
            document.getElementById('sortBy').value = 'created_at';
            document.getElementById('sortOrder').value = 'desc';
            
            // 重置日期範圍
            const today = new Date();
            const thirtyDaysAgo = new Date(today.getTime() - 30 * 24 * 60 * 60 * 1000);
            document.getElementById('dateTo').value = today.toISOString().split('T')[0];
            document.getElementById('dateFrom').value = thirtyDaysAgo.toISOString().split('T')[0];
            
            // 顯示所有資料
            const rows = document.querySelectorAll('#dataTableBody tr');
            rows.forEach(row => {
                row.style.display = '';
            });
            
            // 按原始順序重新排列
            const tbody = document.getElementById('dataTableBody');
            originalData.forEach(data => {
                tbody.appendChild(data.element);
            });
            
            document.getElementById('dataCount').textContent = `共 ${rows.length} 筆資料`;
        }
        
        // 點擊標題排序
        function sortByColumn(column) {
            // 設置排序方式
            document.getElementById('sortBy').value = column;
            
            // 切換排序順序
            const currentOrder = document.getElementById('sortOrder').value;
            const newOrder = currentOrder === 'asc' ? 'desc' : 'asc';
            document.getElementById('sortOrder').value = newOrder;
            
            // 執行排序
            sortData();
            
            // 更新標題圖標
            updateSortIcons(column, newOrder);
        }
        
        // 更新排序圖標
        function updateSortIcons(activeColumn, order) {
            const headers = document.querySelectorAll('.sortable');
            headers.forEach(header => {
                const icon = header.querySelector('i');
                const column = header.onclick.toString().match(/sortByColumn\('([^']+)'\)/)[1];
                
                if (column === activeColumn) {
                    icon.className = order === 'asc' ? 'fas fa-sort-up' : 'fas fa-sort-down';
                    icon.style.opacity = '1';
                } else {
                    icon.className = 'fas fa-sort';
                    icon.style.opacity = '0.5';
                }
            });
        }
        
        // 查看詳情
        function viewDetails(id, sourceType) {
            // 根據不同的報名類型跳轉到對應的詳情頁面
            let url = '';
            switch (sourceType) {
                case '就讀意願登錄':
                    url = '../Topics-frontend/frontend/cooperation_upload.php';
                    break;
                case '續招報名':
                    url = '../Topics-frontend/frontend/continued_admission.php';
                    break;
                case '入學說明會報名':
                    url = '../Topics-frontend/frontend/admission.php';
                    break;
                case '招生推薦報名':
                    url = '../Topics-frontend/frontend/admission_recommend.php';
                    break;
            }
            
            if (url) {
                window.open(url, '_blank');
            }
        }
        
    </script>
</body>
</html>
