<?php
session_start();

// 移除登入檢查，允許直接查看統計分析
// if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
//     header("Location: login.php");
//     exit;
// }

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 設置頁面標題
$page_title = '教師活動紀錄管理';

// 檢查用戶權限和部門
$current_user = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$user_department = '';
$department_filter = '';
// 判斷是否為管理員：角色為 ADM（管理員）或 STA（行政人員），或舊的中文角色名稱
$is_admin = ($user_role === 'ADM' || $user_role === '管理員' || $current_user === 'admin' || $current_user === 'admin1');
$is_school_admin = ($user_role === '學校行政人員' || $user_role === '行政人員' || $user_role === 'STA' || $current_user === 'IMD' || $is_admin);

// 如果是 IMD 帳號，只能查看資管科的資料
if ($current_user === 'IMD') {
    $user_department = '資訊管理科';
    $department_filter = " AND (t.department = '資訊管理科' OR t.department LIKE '%資管%')";
}

// 建立資料庫連接
$conn = getDatabaseConnection();
    
    // 檢查資料庫連接
    if (!$conn) {
        die('資料庫連接失敗');
    }
    
    // 檢查 activity_records 表是否存在
    $table_check = $conn->query("SHOW TABLES LIKE 'activity_records'");
    if ($table_check->num_rows === 0) {
        error_log('activity_records 表不存在');
    } else {
        error_log('activity_records 表存在');
        
        // 檢查表結構
        $structure_check = $conn->query("DESCRIBE activity_records");
        if ($structure_check) {
            $columns = $structure_check->fetch_all(MYSQLI_ASSOC);
            error_log('activity_records 表結構: ' . json_encode($columns));
        }
        
        // 檢查表中是否有數據
        $count_check = $conn->query("SELECT COUNT(*) as count FROM activity_records");
        if ($count_check) {
            $count_result = $count_check->fetch_assoc();
            error_log('activity_records 表記錄數: ' . $count_result['count']);
        }
        
        // 檢查 teacher 表是否存在
        $teacher_table_check = $conn->query("SHOW TABLES LIKE 'teacher'");
        if ($teacher_table_check->num_rows === 0) {
            error_log('teacher 表不存在');
        } else {
            error_log('teacher 表存在');
        }
    }

// 檢查是否有傳入 teacher_id
$teacher_id = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;

if ($teacher_id > 0) {
    // --- 詳細記錄視圖 ---
    // 查詢特定教師的活動記錄
    $activity_records = [];
    $teacher_name = '';
    $records_sql = "SELECT ar.*, u.name AS teacher_name, t.department AS teacher_department
                    FROM activity_records ar
                    LEFT JOIN teacher t ON ar.teacher_id = t.user_id
                    LEFT JOIN user u ON t.user_id = u.id
                    WHERE ar.teacher_id = ? $department_filter
                    ORDER BY ar.activity_date DESC, ar.id DESC";
    $stmt = $conn->prepare($records_sql);
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        $activity_records = $result->fetch_all(MYSQLI_ASSOC);
        if (!empty($activity_records)) {
            $teacher_name = $activity_records[0]['teacher_name'];
            $page_title = '活動紀錄 - ' . htmlspecialchars($teacher_name);
        }
    }
    $stmt->close();
} else {
    // --- 教師列表視圖 ---
    $teachers_with_records = [];
    $teachers_sql = "SELECT t.user_id, u.name AS teacher_name, t.department AS teacher_department, COUNT(ar.id) AS record_count
                     FROM teacher t
                     JOIN activity_records ar ON t.user_id = ar.teacher_id
                     LEFT JOIN user u ON t.user_id = u.id
                     WHERE 1=1 $department_filter
                     GROUP BY t.user_id, u.name, t.department
                     ORDER BY record_count DESC, u.name ASC";
    $result = $conn->query($teachers_sql);

    // 為了統計圖表，獲取所有活動記錄
    $all_activity_records = [];
    $all_records_sql = "SELECT ar.*, u.name AS teacher_name, t.department AS teacher_department
                        FROM activity_records ar
                        LEFT JOIN teacher t ON ar.teacher_id = t.user_id
                        LEFT JOIN user u ON t.user_id = u.id
                        WHERE 1=1 $department_filter
                        ORDER BY ar.activity_date DESC, ar.id DESC";
    $all_records_result = $conn->query($all_records_sql);
    if ($all_records_result) {
        $all_activity_records = $all_records_result->fetch_all(MYSQLI_ASSOC);
        // 調試信息
        error_log('查詢到的活動記錄數量: ' . count($all_activity_records));
    } else {
        error_log('查詢活動記錄失敗: ' . $conn->error);
    }

    if ($result) {
        $teachers_with_records = $result->fetch_all(MYSQLI_ASSOC);
    }
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
        }

        .container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 250px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }

        .main-content {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
        }

        .breadcrumb {
            background: rgba(255, 255, 255, 0.9);
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .breadcrumb a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            margin: 0;
            font-size: 1.3em;
            font-weight: 600;
        }

        .card-body {
            padding: 20px;
        }

        .search-input {
            padding: 10px 15px;
            border: 2px solid #e9ecef;
            border-radius: 25px;
            font-size: 14px;
            width: 300px;
            transition: all 0.3s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        /* 響應式設計 - 篩選控制項 */
        @media (max-width: 768px) {
            .card-header > div:last-child {
                grid-template-columns: 1fr !important;
            }
            
            .search-input {
                width: 100% !important;
            }
        }

        .table-container {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .table th,
        .table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }

        .table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }

        .table tbody tr:hover {
            background: #f8f9fa;
        }

        .btn-view {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn-view:hover {
            background: var(--secondary-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }

        .empty-state i {
            color: #dee2e6;
            margin-bottom: 15px;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 15px;
            width: 80%;
            max-width: 600px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 20px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            margin: 0;
            font-size: 1.3em;
        }

        .close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: opacity 0.3s ease;
        }

        .close:hover {
            opacity: 0.7;
        }

        .modal-body {
            padding: 20px;
            max-height: 400px;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 15px 20px;
            border-top: 1px solid #e9ecef;
            text-align: right;
        }

        .btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .btn:hover {
            background: var(--secondary-color);
        }

        .chart-container {
            position: relative;
            height: 400px;
            margin: 20px 0;
        }

        .chart-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .chart-title {
            font-size: 1.1em;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
            text-align: center;
        }

        .chart-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include 'sidebar.php'; ?>
        <div class="main-content">
                <?php if ($teacher_id > 0): // 詳細記錄視圖 ?>
                    <div class="breadcrumb">
                        <a href="index.php">首頁</a> / <a href="activity_records.php">教師活動紀錄</a> / <?php echo htmlspecialchars($teacher_name); ?>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h3><?php echo htmlspecialchars($teacher_name); ?> 的紀錄列表 (共 <?php echo count($activity_records); ?> 筆)</h3>
                            <input type="text" id="searchInput" class="search-input" placeholder="搜尋學校或類型...">
                        </div>
                        <div class="card-body table-container">
                            <?php if (empty($activity_records)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                                    <p>這位教師目前尚無任何活動紀錄。</p>
                                </div>
                            <?php else: ?>
                                <table class="table" id="recordsTable">
                                    <thead>
                                        <tr>
                                            <th>活動日期</th>
                                            <th>學校名稱</th>
                                            <th>活動類型</th>
                                            <th>活動時間</th>
                                            <th>提交時間</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($activity_records as $record): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($record['activity_date']); ?></td>
                                            <td><?php echo htmlspecialchars($record['school_name']); ?></td>
                                            <td><?php echo htmlspecialchars($record['activity_type']); ?></td>
                                            <td><?php echo htmlspecialchars($record['activity_time']); ?></td>
                                            <td><?php echo date('Y/m/d H:i', strtotime($record['created_at'])); ?></td>
                                            <td>
                                                <button class="btn-view" onclick='viewRecord(<?php echo json_encode($record, JSON_UNESCAPED_UNICODE); ?>)'>查看</button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php else: // 教師列表視圖 ?>
                    <div class="breadcrumb">
                        <a href="index.php">首頁</a> / 教師活動紀錄管理
                    </div>

                    <!-- 統計分析區塊 -->
                    <div class="card">
                        <div class="card-body">
                        <h4 style="color: #667eea; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-chart-bar"></i> 招生活動統計分析
                        </h4>
                        <!-- 招生活動統計按鈕組 -->
                            <div style="display: flex; gap: 12px; margin-bottom: 24px; flex-wrap: wrap;">
                                <button class="btn-view" onclick="showTeacherStats()"><i class="fas fa-users"></i> 教師活動統計</button>
                                <button class="btn-view" onclick="showActivityTypeStats()"><i class="fas fa-chart-pie"></i> 活動類型分析</button>
                                <button class="btn-view" onclick="showTimeStats()"><i class="fas fa-calendar-alt"></i> 時間分布分析</button>
                                <button class="btn-view" onclick="showSchoolStats()"><i class="fas fa-school"></i> 合作學校統計</button>
                            <button class="btn-view" onclick="clearActivityCharts()" style="background: #dc3545; color: white; border-color: #dc3545;">
                                <i class="fas fa-arrow-up"></i> 收回圖表
                            </button>
                            </div>
                        
                        <!-- 招生活動統計內容區域 -->
                        <div id="activityAnalyticsContent" style="min-height: 200px;">
                                <div class="empty-state">
                                    <i class="fas fa-chart-line fa-3x" style="margin-bottom: 16px;"></i>
                                    <h4>選擇上方的統計類型來查看詳細分析</h4>
                                    <p>提供教師活動參與度、活動類型分布、時間趨勢等多維度統計</p>
                                </div>
                            </div>
                        
                        <!-- 就讀意願統計按鈕組 -->
                        <div style="border-top: 1px solid #f0f0f0; padding-top: 20px; margin-top: 20px;">
                            <h4 style="color: #667eea; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                                <i class="fas fa-graduation-cap"></i> 就讀意願統計分析
                            </h4>
                            <div style="display: flex; gap: 12px; margin-bottom: 24px; flex-wrap: wrap;">
                                <button class="btn-view" onclick="showEnrollmentSystemStats()">
                                    <i class="fas fa-chart-pie"></i> 學制分布分析
                                </button>
                                <button class="btn-view" onclick="showEnrollmentGradeStats()">
                                    <i class="fas fa-users"></i> 年級分布分析
                                </button>
                                <button class="btn-view" onclick="showEnrollmentGenderStats()">
                                    <i class="fas fa-venus-mars"></i> 性別分布分析
                                </button>
                                <button class="btn-view" onclick="showEnrollmentIdentityStats()">
                                    <i class="fas fa-user-tag"></i> 身分別分析
                                </button>
                                <button class="btn-view" onclick="showEnrollmentMonthlyStats()">
                                    <i class="fas fa-calendar-alt"></i> 月度趨勢分析
                                </button>
                                <?php if ($is_admin || $is_school_admin): ?>
                                <button class="btn-view" onclick="showEnrollmentSchoolDepartmentStats()">
                                    <i class="fas fa-school"></i> 國中選擇科系分析
                                </button>
                                <?php endif; ?>
                                <button class="btn-view" onclick="clearEnrollmentCharts()" style="background: #dc3545; color: white; border-color: #dc3545;">
                                    <i class="fas fa-arrow-up"></i> 收回圖表
                                </button>
                            </div>
                        </div>
                        
                        <!-- 就讀意願統計內容區域 -->
                        <div id="enrollmentAnalyticsContent" style="min-height: 200px;">
                            <div style="margin-bottom: 20px;">
                                <h4 style="color: #667eea; margin-bottom: 15px;">
                                    <i class="fas fa-graduation-cap"></i> 科系分布分析
                                    <span style="font-size: 0.8em; color: #999; margin-left: 10px;">（<?php echo $current_user === 'IMD' ? '資管科專屬視圖' : '就讀意願統計專屬視圖'; ?>）</span>
                                </h4>
                                
                                <div class="chart-card">
                                    <div class="chart-title">科系選擇分布</div>
                                    <div class="chart-container">
                                        <canvas id="enrollmentDepartmentChart"></canvas>
                                    </div>
                                </div>
                                
                                <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                                    <h5 style="color: #333; margin-bottom: 15px;">科系詳細統計</h5>
                                    <div id="enrollmentDepartmentStats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                        <!-- 統計數據將由JavaScript動態載入 -->
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 續招報名統計按鈕組 -->
                        <div style="border-top: 1px solid #f0f0f0; padding-top: 20px; margin-top: 20px;">
                            <h4 style="color: #667eea; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                                <i class="fas fa-file-alt"></i> 續招報名統計分析
                            </h4>
                            <div style="display: flex; gap: 12px; margin-bottom: 24px; flex-wrap: wrap;">
                                <button class="btn-view" onclick="showContinuedAdmissionGenderStats()">
                                    <i class="fas fa-venus-mars"></i> 性別分布分析
                                </button>
                                <button class="btn-view" onclick="showContinuedAdmissionCityStats()">
                                    <i class="fas fa-map-marker-alt"></i> 縣市分布分析
                                </button>
                                <button class="btn-view" onclick="showContinuedAdmissionMonthlyStats()">
                                    <i class="fas fa-calendar-alt"></i> 月度趨勢分析
                                </button>
                                <button class="btn-view" onclick="showContinuedAdmissionStatusStats()">
                                    <i class="fas fa-clipboard-check"></i> 審核狀態分析
                                </button>
                                <button class="btn-view" onclick="clearContinuedAdmissionCharts()" style="background: #dc3545; color: white; border-color: #dc3545;">
                                    <i class="fas fa-arrow-up"></i> 收回圖表
                                </button>
                            </div>
                        </div>
                        
                        <!-- 續招報名統計內容區域 -->
                        <div id="continuedAdmissionAnalyticsContent" style="min-height: 200px;">
                            <div style="margin-bottom: 20px;">
                                <h4 style="color: #667eea; margin-bottom: 15px;">
                                    <i class="fas fa-list-ol"></i> 志願選擇分析
                                    <span style="font-size: 0.8em; color: #999; margin-left: 10px;">（<?php echo $current_user === 'IMD' ? '資管科專屬視圖' : '續招報名統計專屬視圖'; ?>）</span>
                                </h4>
                                
                                <div class="chart-card">
                                    <div class="chart-title">志願選擇分布</div>
                                    <div class="chart-container">
                                        <canvas id="continuedAdmissionChoicesChart"></canvas>
                                    </div>
                                </div>
                                
                                <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                                    <h5 style="color: #333; margin-bottom: 15px;">志願詳細統計</h5>
                                    <div id="continuedAdmissionChoicesStats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                        <!-- 統計數據將由JavaScript動態載入 -->
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 五專入學說明會統計按鈕組 -->
                        <div style="border-top: 1px solid #f0f0f0; padding-top: 20px; margin-top: 20px;">
                            <h4 style="color: #667eea; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                                <i class="fas fa-graduation-cap"></i> 五專入學說明會統計分析
                            </h4>
                            <div style="display: flex; gap: 12px; margin-bottom: 24px; flex-wrap: wrap;">
                                <button class="btn-view" onclick="showAdmissionGradeStats()">
                                    <i class="fas fa-users"></i> 年級分布分析
                                </button>
                                <button class="btn-view" onclick="showAdmissionSchoolStats()">
                                    <i class="fas fa-school"></i> 學校分布分析
                                </button>
                                <button class="btn-view" onclick="showAdmissionSessionStats()">
                                    <i class="fas fa-calendar-alt"></i> 場次分布分析
                                </button>
                                <button class="btn-view" onclick="showAdmissionCourseStats()">
                                    <i class="fas fa-book-open"></i> 課程選擇分析
                                </button>
                                <button class="btn-view" onclick="showAdmissionReceiveInfoStats()">
                                    <i class="fas fa-envelope"></i> 資訊接收分析
                                </button>
                                <button class="btn-view" onclick="clearAdmissionCharts()" style="background: #dc3545; color: white; border-color: #dc3545;">
                                    <i class="fas fa-arrow-up"></i> 收回圖表
                                </button>
                            </div>
                        </div>
                        
                        <!-- 五專入學說明會統計內容區域 -->
                        <div id="admissionAnalyticsContent" style="min-height: 200px;">
                            <div class="empty-state">
                                <i class="fas fa-graduation-cap fa-3x" style="margin-bottom: 16px;"></i>
                                <h4>選擇上方的統計類型來查看詳細分析</h4>
                                <p>提供年級分布、學校分布、場次分布、課程選擇、月度趨勢、資訊接收等多維度統計</p>
                            </div>
                        </div>
                        </div>
                    </div>


                <?php endif; ?>
        </div>
    </div>

    <!-- 查看 Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">查看活動紀錄</h3>
                <span class="close" onclick="closeModal('viewModal')">&times;</span>
            </div>
            <div class="modal-body" id="viewModalBody">
                <!-- Content will be injected by JS -->
            </div>
            <div class="modal-footer">
                <button class="btn" onclick="closeModal('viewModal')">關閉</button>
            </div>
        </div>
    </div>

    <script>
    // 將 PHP 數據傳遞給 JavaScript
    const activityRecords = <?php echo json_encode($all_activity_records ?? []); ?>;
    const isTeacherListView = <?php echo $teacher_id > 0 ? 'false' : 'true'; ?>;
    const userDepartment = '<?php echo $user_department; ?>';
    const currentUser = '<?php echo $current_user; ?>';
    const userRole = '<?php echo $user_role; ?>';
    const isSchoolAdmin = <?php echo $is_school_admin ? 'true' : 'false'; ?>;
    
    // 從學校名稱中提取縣市（全局函數，供多處使用）
    function extractCityFromSchoolName(schoolName) {
        if (!schoolName) return '';
        
        // 先嘗試匹配 XX市立 或 XX縣立（例如：台北市立XX國中）
        let cityMatch = schoolName.match(/^(.+?)(?:市立|縣立)/);
        if (cityMatch) {
            const cityName = cityMatch[1] + (schoolName.includes('市立') ? '市' : '縣');
            return cityName;
        }
        
        // 再嘗試匹配 XX市 或 XX縣（在開頭，例如：台北市XX國中）
        cityMatch = schoolName.match(/^(.+?)(?:市|縣)/);
        if (cityMatch) {
            const cityName = cityMatch[1] + (schoolName.includes('市') ? '市' : '縣');
            return cityName;
        }
        
        return '';
    }

    // 調試信息
    console.log('PHP 傳遞的數據:', activityRecords);
    console.log('數據長度:', activityRecords.length);
    console.log('當前用戶:', currentUser);
    console.log('用戶部門:', userDepartment);
    console.log('用戶角色:', userRole);
    console.log('是否為學校行政人員:', isSchoolAdmin);
    
    // ========== 輔助函數 ==========
    
    // 構建 API URL，如果用戶有部門限制則添加參數
    function buildApiUrl(baseUrl, action) {
        let url = `${baseUrl}?action=${action}`;
        if (userDepartment) {
            url += `&department=${encodeURIComponent(userDepartment)}`;
        }
        return url;
    }
    
    // ========== 簡化版測試函數 ==========
    
    
    // 調試：檢查函數是否正確定義
    console.log('函數檢查:');
    console.log('showTeacherStats:', typeof showTeacherStats);
    console.log('showActivityTypeStats:', typeof showActivityTypeStats);
    console.log('showTimeStats:', typeof showTimeStats);
    console.log('showSchoolStats:', typeof showSchoolStats);
    
    // 招生活動統計 - 教師活動統計
        function showTeacherStats() {
        console.log('showTeacherStats 被調用');
        
        // 統計每位教師的活動
            const teacherStats = {};
            activityRecords.forEach(record => {
                const teacherName = record.teacher_name || '未知教師';
            const department = record.teacher_department || '未知科系';
            
                if (!teacherStats[teacherName]) {
                teacherStats[teacherName] = {
                    name: teacherName,
                    department: department,
                    totalActivities: 0
                };
            }
            teacherStats[teacherName].totalActivities++;
        });
        
        const teacherStatsArray = Object.values(teacherStats).sort((a, b) => b.totalActivities - a.totalActivities);
            
            const content = `
            <div style="margin-bottom: 20px;">
                <h4 style="color: #667eea; margin-bottom: 15px;">
                    <i class="fas fa-users"></i> 教師活動統計
                </h4>
                
                <div class="chart-card">
                    <div class="chart-title">教師活動參與度</div>
                <div class="chart-container">
                        <canvas id="teacherActivityChart"></canvas>
                    </div>
                </div>
                
                <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                    <h5 style="color: #333; margin-bottom: 15px;">教師詳細統計</h5>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                        ${teacherStatsArray.map(teacher => `
                            <div style="background: white; padding: 20px; border-radius: 10px; border-left: 4px solid #667eea;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                    <h5 style="margin: 0; color: #333;">${teacher.name}</h5>
                                    <span style="background: #667eea; color: white; padding: 4px 8px; border-radius: 12px; font-size: 0.8em;">
                                        ${teacher.department}
                                    </span>
                                </div>
                                <div style="text-align: center; background: #f8f9fa; padding: 10px; border-radius: 8px;">
                                    <div style="font-size: 1.5em; font-weight: bold; color: #667eea;">${teacher.totalActivities}</div>
                                    <div style="font-size: 0.8em; color: #666;">總活動數</div>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
                </div>
            `;

        document.getElementById('activityAnalyticsContent').innerHTML = content;
        
        // 創建長條圖
            setTimeout(() => {
            const canvasElement = document.getElementById('teacherActivityChart');
            if (!canvasElement) return;
            
            const ctx = canvasElement.getContext('2d');
            new Chart(ctx, {
                    type: 'bar',
                    data: {
                    labels: teacherStatsArray.map(teacher => teacher.name),
                        datasets: [{
                        label: '活動數量',
                        data: teacherStatsArray.map(teacher => teacher.totalActivities),
                        backgroundColor: '#667eea',
                        borderColor: '#5a6fd8',
                            borderWidth: 1
                        }]
                    },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
                });
            }, 100);
        }

        function showActivityTypeStats() {
        console.log('showActivityTypeStats 被調用');
        
        // 統計活動類型
            const typeStats = {};
            activityRecords.forEach(record => {
                const type = record.activity_type || '未知類型';
            if (!typeStats[type]) {
                typeStats[type] = 0;
            }
                typeStats[type]++;
            });
        
        const typeStatsArray = Object.entries(typeStats).map(([type, count]) => ({
            type,
            count,
            percentage: ((count / activityRecords.length) * 100).toFixed(1)
        })).sort((a, b) => b.count - a.count);

            const content = `
            <div style="margin-bottom: 20px;">
                <h4 style="color: #667eea; margin-bottom: 15px;">
                    <i class="fas fa-chart-pie"></i> 活動類型分布分析
                </h4>
                
                <div class="chart-card">
                    <div class="chart-title">活動類型圓餅圖</div>
                    <div class="chart-container">
                        <canvas id="activityTypePieChart"></canvas>
                    </div>
                </div>
                
                <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                    <h5 style="color: #333; margin-bottom: 15px;">詳細統計數據</h5>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                        ${typeStatsArray.map((item, index) => {
                            const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'];
                            const color = colors[index % colors.length];
                            return `
                                <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid ${color};">
                                    <div style="font-weight: bold; color: #333; margin-bottom: 5px;">${item.type}</div>
                                    <div style="font-size: 1.5em; font-weight: bold; color: ${color};">${item.count}次</div>
                                    <div style="font-size: 0.9em; color: #666;">${item.percentage}%</div>
                </div>
            `;
                        }).join('')}
                    </div>
                </div>
            </div>
        `;
        
        document.getElementById('activityAnalyticsContent').innerHTML = content;
        
        // 創建圓餅圖
            setTimeout(() => {
            const canvasElement = document.getElementById('activityTypePieChart');
            if (!canvasElement) return;
            
            const ctx = canvasElement.getContext('2d');
            new Chart(ctx, {
                type: 'pie',
                    data: {
                    labels: typeStatsArray.map(item => item.type),
                        datasets: [{
                        data: typeStatsArray.map(item => item.count),
                        backgroundColor: [
                            '#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'
                        ],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                usePointStyle: true,
                                font: { size: 16 }
                            }
                        },
                        tooltip: {
                            enabled: true,
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((value / total) * 100).toFixed(1);
                                    return `${label}: ${value}次 (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
                });
            }, 100);
        }

        function showTimeStats() {
        console.log('showTimeStats 被調用');
        
        // 統計月份分布
        const monthStats = {};
            activityRecords.forEach(record => {
            if (record.activity_date) {
                const date = new Date(record.activity_date);
                const month = date.getMonth() + 1; // 0-11 -> 1-12
                const monthKey = `${month}月`;
                
                if (!monthStats[monthKey]) {
                    monthStats[monthKey] = 0;
                }
                monthStats[monthKey]++;
            }
        });
        
        // 統計星期分布
        const dayStats = {};
        activityRecords.forEach(record => {
            if (record.activity_date) {
                const date = new Date(record.activity_date);
                const dayOfWeek = date.getDay(); // 0=Sunday, 1=Monday, ...
                const dayNames = ['星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六'];
                const dayKey = dayNames[dayOfWeek];
                
                if (!dayStats[dayKey]) {
                    dayStats[dayKey] = 0;
                }
                dayStats[dayKey]++;
            }
        });
        
        const monthStatsArray = Object.entries(monthStats).sort((a, b) => {
            const monthA = parseInt(a[0]);
            const monthB = parseInt(b[0]);
            return monthA - monthB;
        });
        
        const dayStatsArray = Object.entries(dayStats).sort((a, b) => {
            const dayOrder = ['星期一', '星期二', '星期三', '星期四', '星期五', '星期六', '星期日'];
            return dayOrder.indexOf(a[0]) - dayOrder.indexOf(b[0]);
        });

            const content = `
            <div style="margin-bottom: 20px;">
                <h4 style="color: #667eea; margin-bottom: 15px;">
                    <i class="fas fa-calendar-alt"></i> 時間分布分析
                </h4>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="chart-card">
                        <div class="chart-title">月份分布</div>
                <div class="chart-container">
                            <canvas id="monthStatsChart"></canvas>
                        </div>
                    </div>
                    
                    <div class="chart-card">
                        <div class="chart-title">星期分布</div>
                        <div class="chart-container">
                            <canvas id="dayStatsChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <div style="background: #f8f9fa; padding: 20px; border-radius: 10px;">
                    <h5 style="color: #333; margin-bottom: 15px;">詳細時間統計</h5>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div>
                            <h6 style="color: #667eea; margin-bottom: 10px;">月份統計</h6>
                            ${monthStatsArray.map(([month, count]) => `
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #eee;">
                                    <span>${month}</span>
                                    <span style="font-weight: bold; color: #667eea;">${count}次</span>
                                </div>
                            `).join('')}
                        </div>
                        <div>
                            <h6 style="color: #667eea; margin-bottom: 10px;">星期統計</h6>
                            ${dayStatsArray.map(([day, count]) => `
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #eee;">
                                    <span>${day}</span>
                                    <span style="font-weight: bold; color: #667eea;">${count}次</span>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                </div>
                </div>
            `;

        document.getElementById('activityAnalyticsContent').innerHTML = content;
        
        // 創建圖表
            setTimeout(() => {
            // 月份分布圖
            const monthCanvas = document.getElementById('monthStatsChart');
            if (monthCanvas) {
                const monthCtx = monthCanvas.getContext('2d');
                new Chart(monthCtx, {
                    type: 'line',
                    data: {
                        labels: monthStatsArray.map(([month]) => month),
                        datasets: [{
                            label: '活動數量',
                            data: monthStatsArray.map(([, count]) => count),
                            borderColor: '#667eea',
                            backgroundColor: 'rgba(102, 126, 234, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            y: { beginAtZero: true, ticks: { stepSize: 1 } }
                        }
                    }
                });
            }
            
            // 星期分布圖
            const dayCanvas = document.getElementById('dayStatsChart');
            if (dayCanvas) {
                const dayCtx = dayCanvas.getContext('2d');
                new Chart(dayCtx, {
                    type: 'bar',
                    data: {
                        labels: dayStatsArray.map(([day]) => day),
                        datasets: [{
                            label: '活動數量',
                            data: dayStatsArray.map(([, count]) => count),
                            backgroundColor: '#28a745',
                            borderColor: '#1e7e34',
                            borderWidth: 1
                        }]
                    },
                    options: { 
                        responsive: true, 
                        maintainAspectRatio: false, 
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            y: { beginAtZero: true, ticks: { stepSize: 1 } }
                        }
                    }
                });
            }
            }, 100);
        }

        function showSchoolStats() {
        console.log('showSchoolStats 被調用');
        
        // 統計合作學校
            const schoolStats = {};
            activityRecords.forEach(record => {
            const schoolName = record.school_name || '未知學校';
            if (!schoolStats[schoolName]) {
                schoolStats[schoolName] = {
                    name: schoolName,
                    count: 0,
                    teachers: new Set()
                };
            }
            schoolStats[schoolName].count++;
            if (record.teacher_name) {
                schoolStats[schoolName].teachers.add(record.teacher_name);
            }
        });
        
        const schoolStatsArray = Object.values(schoolStats).map(school => ({
            ...school,
            teacherCount: school.teachers.size,
            teachers: Array.from(school.teachers)
        })).sort((a, b) => b.count - a.count);

            const content = `
            <div style="margin-bottom: 20px;">
                <h4 style="color: #667eea; margin-bottom: 15px;">
                    <i class="fas fa-school"></i> 合作學校統計
                </h4>
                
                <div class="chart-card">
                    <div class="chart-title">學校合作頻率</div>
                <div class="chart-container">
                    <canvas id="schoolStatsChart"></canvas>
                    </div>
                </div>
                
                <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                    <h5 style="color: #333; margin-bottom: 15px;">學校詳細統計</h5>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 20px;">
                        ${schoolStatsArray.map((school, index) => {
                            const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'];
                            const color = colors[index % colors.length];
                            return `
                                <div style="background: white; padding: 20px; border-radius: 10px; border-left: 4px solid ${color};">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                        <h5 style="margin: 0; color: #333;">${school.name}</h5>
                                        <span style="background: ${color}; color: white; padding: 4px 8px; border-radius: 12px; font-size: 0.8em;">
                                            ${school.count}次合作
                                        </span>
                                    </div>
                                    
                                    <div style="margin-bottom: 15px;">
                                        <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                            <span style="color: #666;">參與教師數:</span>
                                            <span style="font-weight: bold; color: ${color};">${school.teacherCount}人</span>
                                        </div>
                                        <div style="display: flex; justify-content: space-between;">
                                            <span style="color: #666;">總活動數:</span>
                                            <span style="font-weight: bold; color: ${color};">${school.count}次</span>
                                        </div>
                                    </div>
                                    
                                    ${school.teachers.length > 0 ? `
                                        <div>
                                            <div style="color: #666; font-size: 0.9em; margin-bottom: 8px;">參與教師:</div>
                                            <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                                ${school.teachers.map(teacher => `
                                                    <span style="background: #f8f9fa; padding: 2px 6px; border-radius: 4px; font-size: 0.8em; color: #666;">
                                                        ${teacher}
                                                    </span>
                                                `).join('')}
                                            </div>
                                        </div>
                                    ` : ''}
                </div>
            `;
                        }).join('')}
                    </div>
                </div>
            </div>
        `;
        
        document.getElementById('activityAnalyticsContent').innerHTML = content;
        
        // 創建長條圖
            setTimeout(() => {
            const canvasElement = document.getElementById('schoolStatsChart');
            if (!canvasElement) return;
            
            const ctx = canvasElement.getContext('2d');
            new Chart(ctx, {
                    type: 'bar',
                    data: {
                    labels: schoolStatsArray.map(school => school.name),
                        datasets: [{
                        label: '合作次數',
                        data: schoolStatsArray.map(school => school.count),
                        backgroundColor: '#667eea',
                        borderColor: '#5a6fd8',
                            borderWidth: 1
                        }]
                    },
                    options: { 
                        responsive: true, 
                        maintainAspectRatio: false, 
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        },
                        x: {
                            ticks: {
                                maxRotation: 45,
                                minRotation: 0
                            }
                        }
                    }
                    }
                });
            }, 100);
    }
    
    function clearActivityCharts() {
        console.log('clearActivityCharts 被調用');
        
        // 清除所有Chart.js實例
        Chart.helpers.each(Chart.instances, function(instance) {
            if (instance.canvas.id.includes('teacherActivityChart') || 
                instance.canvas.id.includes('activityTypePieChart') ||
                instance.canvas.id.includes('monthStatsChart') ||
                instance.canvas.id.includes('dayStatsChart') ||
                instance.canvas.id.includes('schoolStatsChart') ||
                instance.canvas.id.includes('departmentOverviewChart')) {
                instance.destroy();
            }
        });
        
        // 如果是學校行政人員，顯示全校科系招生總覽
        if (isSchoolAdmin) {
            showDepartmentOverviewStats();
        } else {
            // 否則顯示空狀態
        document.getElementById('activityAnalyticsContent').innerHTML = `
            <div class="empty-state">
                <i class="fas fa-chart-line fa-3x" style="margin-bottom: 16px;"></i>
                <h4>選擇上方的統計類型來查看詳細分析</h4>
                <p>提供教師活動參與度、活動類型分布、時間趨勢等多維度統計</p>
            </div>
        `;
        }
    }
    
    // 學校行政人員專用 - 科系招生總覽（自動顯示）
    function showDepartmentOverviewStats() {
        console.log('showDepartmentOverviewStats 被調用 - 顯示全部科系招生總覽');
        
        // 統計每個科系的活動數量
        const departmentStats = {};
        activityRecords.forEach(record => {
            const department = record.teacher_department || '未知科系';
            if (!departmentStats[department]) {
                departmentStats[department] = {
                    name: department,
                    totalActivities: 0,
                    teachers: new Set()
                };
            }
            departmentStats[department].totalActivities++;
            if (record.teacher_name) {
                departmentStats[department].teachers.add(record.teacher_name);
            }
        });
        
        const departmentStatsArray = Object.values(departmentStats).map(dept => ({
            name: dept.name,
            totalActivities: dept.totalActivities,
            teacherCount: dept.teachers.size
        })).sort((a, b) => b.totalActivities - a.totalActivities);
        
        const content = `
            <div style="margin-bottom: 20px;">
                <h4 style="color: #667eea; margin-bottom: 15px;">
                    <i class="fas fa-chart-bar"></i> 全校科系招生活動總覽
                    <span style="font-size: 0.8em; color: #999; margin-left: 10px;">（<?php echo $current_user === 'IMD' ? '資管科專屬視圖' : '學校行政人員專屬視圖'; ?>）</span>
                </h4>
                
                <div class="chart-card">
                    <div class="chart-title">各科系招生活動數量統計 <span style="font-size: 0.9em; color: #999;">（點擊科系查看該科系教師列表）</span></div>
                    <div class="chart-container">
                        <canvas id="departmentOverviewChart"></canvas>
                    </div>
                </div>
                
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 10px; margin-top: 20px;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; text-align: center;">
                        <div>
                            <div style="font-size: 2.5em; font-weight: bold; margin-bottom: 5px;">${departmentStatsArray.reduce((sum, d) => sum + d.totalActivities, 0)}</div>
                            <div style="font-size: 1em; opacity: 0.9;">總活動數</div>
                        </div>
                        <div>
                            <div style="font-size: 2.5em; font-weight: bold; margin-bottom: 5px;">${departmentStatsArray.reduce((sum, d) => sum + d.teacherCount, 0)}</div>
                            <div style="font-size: 1em; opacity: 0.9;">參與教師總數</div>
                        </div>
                        <div>
                            <div style="font-size: 2.5em; font-weight: bold; margin-bottom: 5px;">${departmentStatsArray.length}</div>
                            <div style="font-size: 1em; opacity: 0.9;">參與科系總數</div>
                        </div>
                    </div>
                </div>
                
                <!-- 科系教師列表顯示區域 -->
                <div id="departmentTeacherListContainer" style="margin-top: 20px;"></div>
            </div>
        `;
        
        document.getElementById('activityAnalyticsContent').innerHTML = content;
        
        // 創建圖表
        setTimeout(() => {
            const canvasElement = document.getElementById('departmentOverviewChart');
            if (!canvasElement) return;
            
            const ctx = canvasElement.getContext('2d');
            const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8', '#6f42c1', '#fd7e14', '#20c997'];
            
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: departmentStatsArray.map(dept => dept.name),
                    datasets: [{
                        label: '招生活動數量',
                        data: departmentStatsArray.map(dept => dept.totalActivities),
                        backgroundColor: departmentStatsArray.map((_, index) => colors[index % colors.length]),
                        borderColor: departmentStatsArray.map((_, index) => colors[index % colors.length]),
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    onClick: (event, elements) => {
                        if (elements.length > 0) {
                            const index = elements[0].index;
                            const departmentName = departmentStatsArray[index].name;
                            console.log(`點擊科系: ${departmentName}`);
                            showDepartmentTeacherList(departmentName);
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                afterLabel: function(context) {
                                    const index = context.dataIndex;
                                    return `參與教師：${departmentStatsArray[index].teacherCount} 人`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                font: {
                                    size: 13
                                }
                            },
                            title: {
                                display: true,
                                text: '活動數量',
                                font: {
                                    size: 15,
                                    weight: 'bold'
                                },
                                padding: {
                                    bottom: 10
                                },
                                align: 'center'
                            }
                        },
                        x: {
                            ticks: {
                                maxRotation: 45,
                                minRotation: 0,
                                color: '#2563eb',
                                font: {
                                    size: 15,
                                    weight: 'bold'
                                }
                            }
                        }
                    }
                }
            });
        }, 100);
    }
    
    // 顯示科系教師列表
    function showDepartmentTeacherList(departmentName) {
        console.log(`顯示 ${departmentName} 的教師列表`);
        
        // 統計該科系的教師資訊
        const teacherStats = {};
        
        activityRecords.forEach(record => {
            if (record.teacher_department === departmentName) {
                const teacherId = record.teacher_id;
                const teacherName = record.teacher_name;
                
                if (!teacherStats[teacherId]) {
                    teacherStats[teacherId] = {
                        id: teacherId,
                        name: teacherName,
                        department: departmentName,
                        activityCount: 0,
                        activities: []
                    };
                }
                
                teacherStats[teacherId].activityCount++;
                teacherStats[teacherId].activities.push({
                    school: record.school_name,
                    type: record.activity_type,
                    date: record.activity_date
                });
            }
        });
        
        const teacherList = Object.values(teacherStats).sort((a, b) => b.activityCount - a.activityCount);
        
        // 收集所有可用的科系（從所有記錄中）
        const allDepartments = new Set();
        activityRecords.forEach(record => {
            if (record.teacher_department) {
                allDepartments.add(record.teacher_department);
            }
        });
        const departmentOptions = Array.from(allDepartments).sort();
        
        const content = `
            <div style="background: white; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden; margin-top: 20px;">
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 15px; flex-wrap: wrap;">
                        <h3 style="margin: 0; font-size: 1.3em; font-weight: 600;">
                            <i class="fas fa-users"></i> ${departmentName} - 教師列表 (共 <span id="deptTeacherCount">${teacherList.length}</span> 位)
                        </h3>
                        
                        <div style="display: flex; gap: 10px; align-items: center; flex: 1; max-width: 600px;">
                            <!-- 搜尋框 -->
                            <div style="position: relative; flex: 1;">
                                <i class="fas fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: rgba(255,255,255,0.7);"></i>
                                <input type="text" 
                                       id="deptTeacherSearch" 
                                       placeholder="搜尋教師姓名..." 
                                       style="padding: 8px 12px 8px 35px; border: 2px solid rgba(255,255,255,0.3); border-radius: 20px; background: rgba(255,255,255,0.2); color: white; font-size: 14px; width: 100%;">
                                <style>
                                    #deptTeacherSearch::placeholder {
                                        color: white !important;
                                        opacity: 1;
                                    }
                                </style>
                            </div>
                            
                            <button onclick="document.getElementById('departmentTeacherListContainer').innerHTML = ''" 
                                    style="background: rgba(255,255,255,0.2); color: white; border: 1px solid white; padding: 8px 16px; border-radius: 20px; cursor: pointer; font-weight: 500; transition: all 0.3s; white-space: nowrap;">
                                <i class="fas fa-times"></i> 關閉
                            </button>
                        </div>
                    </div>
                </div>
                
                <div style="padding: 20px;">
                    
                    <div style="overflow-x: auto;">
                        <table class="table" id="deptTeacherTable" style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr>
                                    <th style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef; background: #f8f9fa; font-weight: 600; color: #495057;">教師姓名</th>
                                    <th style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef; background: #f8f9fa; font-weight: 600; color: #495057;">所屬系所</th>
                                    <th style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef; background: #f8f9fa; font-weight: 600; color: #495057;">紀錄筆數</th>
                                    <th style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef; background: #f8f9fa; font-weight: 600; color: #495057;">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${teacherList.map(teacher => `
                                    <tr style="transition: background 0.2s;" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='white'">
                                        <td style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef;">${teacher.name}</td>
                                        <td style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef;">${teacher.department}</td>
                                        <td style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef;">${teacher.activityCount}</td>
                                        <td style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef;">
                                            <button onclick="showTeacherActivityDetails(${teacher.id}, '${teacher.name}')" 
                                                    class="btn-view"
                                                    style="background: #667eea; color: white; border: none; padding: 8px 16px; border-radius: 20px; cursor: pointer; font-size: 14px; transition: all 0.3s ease;">
                                                查看紀錄
                                            </button>
                                        </td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                    
                    <div style="margin-top: 20px; padding: 15px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 8px; color: white; text-align: center;">
                        <strong>
                            <i class="fas fa-chart-bar"></i> ${departmentName} 共有 <span id="deptTotalCount">${teacherList.length}</span> 位教師參與，累計 ${teacherList.reduce((sum, t) => sum + t.activityCount, 0)} 場活動
                        </strong>
                    </div>
                </div>
            </div>
        `;
        
        document.getElementById('departmentTeacherListContainer').innerHTML = content;
        
        // 添加搜尋和篩選功能
        setTimeout(() => {
            const searchInput = document.getElementById('deptTeacherSearch');
            const deptFilter = document.getElementById('deptFilter');
            const table = document.getElementById('deptTeacherTable');
            
            // 統一的篩選函數
            function applyDeptFilters() {
                if (!table) return;
                
                const searchValue = searchInput ? searchInput.value.toLowerCase() : '';
                const deptValue = deptFilter ? deptFilter.value : '';
                const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
                let visibleCount = 0;
                
                for (let i = 0; i < rows.length; i++) {
                    const cells = rows[i].getElementsByTagName('td');
                    const teacherName = cells[0] ? cells[0].textContent.toLowerCase() : '';
                    const department = cells[1] ? cells[1].textContent : '';
                    
                    let shouldShow = true;
                    
                    // 姓名篩選
                    if (searchValue && teacherName.indexOf(searchValue) === -1) {
                        shouldShow = false;
                    }
                    
                    // 科系篩選
                    if (deptValue && department !== deptValue) {
                        shouldShow = false;
                    }
                    
                    if (shouldShow) {
                        rows[i].style.display = '';
                        visibleCount++;
                    } else {
                        rows[i].style.display = 'none';
                    }
                }
                
                // 更新顯示數量
                const countElement = document.getElementById('deptTeacherCount');
                if (countElement) {
                    countElement.textContent = visibleCount;
                }
            }
            
            // 為搜尋框添加事件監聽器
            if (searchInput) {
                searchInput.addEventListener('keyup', applyDeptFilters);
                
                // 設置input樣式（placeholder顏色）
                searchInput.style.setProperty('color', 'white');
                searchInput.addEventListener('focus', function() {
                    this.style.background = 'rgba(255,255,255,0.3)';
                });
                searchInput.addEventListener('blur', function() {
                    this.style.background = 'rgba(255,255,255,0.2)';
                });
            }
            
            // 為科系下拉選單添加事件監聽器
            if (deptFilter) {
                deptFilter.addEventListener('change', applyDeptFilters);
            }
        }, 100);
        
        // 平滑滾動到教師列表
        document.getElementById('departmentTeacherListContainer').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    
    // 顯示教師詳細活動紀錄
    function showTeacherActivityDetails(teacherId, teacherName) {
        console.log(`顯示教師 ${teacherName} 的詳細活動紀錄`);
        
        // 篩選該教師的所有活動
        const teacherActivities = activityRecords.filter(record => record.teacher_id == teacherId);
        
        if (teacherActivities.length === 0) {
            alert('查無該教師的活動紀錄');
            return;
        }
        
        const teacherDept = teacherActivities[0].teacher_department;
        
        const content = `
            <div style="background: white; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden; margin-top: 20px;">
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0; font-size: 1.3em; font-weight: 600;">
                        <i class="fas fa-clipboard-list"></i> ${teacherName} 的紀錄列表 (共 ${teacherActivities.length} 筆)
                    </h3>
                    <button onclick="document.getElementById('departmentTeacherListContainer').scrollIntoView({ behavior: 'smooth' }); showDepartmentTeacherList('${teacherDept}')" 
                            style="background: rgba(255,255,255,0.2); color: white; border: 1px solid white; padding: 8px 16px; border-radius: 5px; cursor: pointer; font-weight: 500; transition: all 0.3s;">
                        <i class="fas fa-arrow-left"></i> 返回教師列表
                    </button>
                </div>
                
                <div style="padding: 20px;">
                    <div style="overflow-x: auto;">
                        <table class="table" style="width: 100%; border-collapse: collapse; margin-top: 15px;">
                            <thead>
                                <tr>
                                    <th style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef; background: #f8f9fa; font-weight: 600; color: #495057;">活動日期</th>
                                    <th style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef; background: #f8f9fa; font-weight: 600; color: #495057;">學校名稱</th>
                                    <th style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef; background: #f8f9fa; font-weight: 600; color: #495057;">活動類型</th>
                                    <th style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef; background: #f8f9fa; font-weight: 600; color: #495057;">活動時間</th>
                                    <th style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef; background: #f8f9fa; font-weight: 600; color: #495057;">提交時間</th>
                                    <th style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef; background: #f8f9fa; font-weight: 600; color: #495057;">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${teacherActivities.map((activity, index) => {
                                    // 格式化提交時間
                                    let formattedCreatedAt = '-';
                                    if (activity.created_at) {
                                        const date = new Date(activity.created_at);
                                        const year = date.getFullYear();
                                        const month = String(date.getMonth() + 1).padStart(2, '0');
                                        const day = String(date.getDate()).padStart(2, '0');
                                        const hours = String(date.getHours()).padStart(2, '0');
                                        const minutes = String(date.getMinutes()).padStart(2, '0');
                                        formattedCreatedAt = `${year}/${month}/${day} ${hours}:${minutes}`;
                                    }
                                    
                                    return `
                                        <tr style="transition: background 0.2s;" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='white'">
                                            <td style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef;">${activity.activity_date}</td>
                                            <td style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef;">${activity.school_name}</td>
                                            <td style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef;">${activity.activity_type}</td>
                                            <td style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef;">${activity.activity_time || '上班日'}</td>
                                            <td style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef;">${formattedCreatedAt}</td>
                                            <td style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef;">
                                                <button class="btn-view" onclick='viewRecord(${JSON.stringify(activity)})'
                                                        style="background: #667eea; color: white; border: none; padding: 8px 16px; border-radius: 20px; cursor: pointer; font-size: 14px; transition: all 0.3s ease;">
                                                    查看
                                                </button>
                                            </td>
                                        </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;
        
        document.getElementById('departmentTeacherListContainer').innerHTML = content;
        
        // 平滑滾動到詳細紀錄
        document.getElementById('departmentTeacherListContainer').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    
    // 就讀意願統計 - 科系分布分析
    function showEnrollmentDepartmentStats() {
        console.log('showEnrollmentDepartmentStats 被調用');
        
        const apiUrl = buildApiUrl('../../Topics-frontend/frontend/api/enrollment_stats_api.php', 'department');
        console.log('API URL:', apiUrl);
        
        // 從API獲取就讀意願數據
        fetch(apiUrl)
            .then(response => {
                console.log('API 響應狀態:', response.status, response.statusText);
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('API 返回數據:', data);
                
                if (data.error) {
                    document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                        </div>
                    `;
                    return;
                }
                
                // 檢查數據是否為空數組
                if (!data || !Array.isArray(data) || data.length === 0) {
                    document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #6c757d;">
                            <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>暫無數據</h4>
                            <p>目前沒有科系分布統計數據</p>
                        </div>
                    `;
                    return;
                }
                
                const content = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-graduation-cap"></i> 科系分布分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">科系選擇分布</div>
                            <div class="chart-container">
                                <canvas id="enrollmentDepartmentChart"></canvas>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">科系詳細統計</h5>
                            
                            <!-- 總報名人數 -->
                            <div style="background: white; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #667eea;">
                                <div style="font-weight: bold; color: #333; margin-bottom: 5px;">總報名人數</div>
                                <div style="font-size: 1.5em; font-weight: bold; color: #667eea;">${data.reduce((sum, d) => sum + d.value, 0)}人</div>
                            </div>
                            
                            <!-- 科系列表 -->
                            <div style="background: white; border-radius: 8px; overflow: hidden;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr style="background: #f8f9fa;">
                                            <th style="padding: 15px; text-align: left; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">科系名稱</th>
                                            <th style="padding: 15px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">報名人數</th>
                                            <th style="padding: 15px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">百分比</th>
                                            <th style="padding: 15px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${data.map((item, index) => {
                                            const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8', '#6f42c1', '#fd7e14', '#20c997', '#e83e8c', '#6c757d'];
                                            const color = colors[index % colors.length];
                                            const total = data.reduce((sum, d) => sum + d.value, 0);
                                            const percentage = ((item.value / total) * 100).toFixed(1);
                                            return `
                                                <tr style="border-bottom: 1px solid #dee2e6;">
                                                    <td style="padding: 15px; font-weight: 500; color: #333;">${item.name}</td>
                                                    <td style="padding: 15px; text-align: center; font-weight: bold; color: #333;">${item.value}人</td>
                                                    <td style="padding: 15px; text-align: center; color: #666;">${percentage}%</td>
                                                    <td style="padding: 15px; text-align: center;">
                                                        <button onclick="showDepartmentStudents('${item.name}')" 
                                                                style="background: ${color}; color: white; border: none; padding: 8px 16px; border-radius: 20px; cursor: pointer; font-size: 14px; transition: all 0.3s ease;">
                                                            查看詳情
                                                        </button>
                                                    </td>
                                                </tr>
                                            `;
                                        }).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('enrollmentAnalyticsContent').innerHTML = content;
                
                // 創建圓餅圖
                setTimeout(() => {
                    const canvasElement = document.getElementById('enrollmentDepartmentChart');
                    if (!canvasElement) return;
                    
                    const ctx = canvasElement.getContext('2d');
                    new Chart(ctx, {
                        type: 'pie',
                        data: {
                            labels: data.map(item => item.name),
                            datasets: [{
                                data: data.map(item => item.value),
                                backgroundColor: [
                                    '#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8', '#6f42c1', '#fd7e14', '#20c997', '#e83e8c', '#6c757d'
                                ],
                                borderWidth: 2,
                                borderColor: '#fff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        padding: 20,
                                        usePointStyle: true,
                                        font: { size: 16 }
                                    }
                                },
                                tooltip: {
                                    enabled: true,
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const value = context.parsed;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = ((value / total) * 100).toFixed(1);
                                            return `${label}: ${value}人 (${percentage}%)`;
                                        }
                                    }
                                }
                            }
                        }
                    });
                }, 100);
            })
            .catch(error => {
                console.error('載入科系統計數據失敗:', error);
                console.error('錯誤詳情:', {
                    message: error.message,
                    stack: error.stack,
                    apiUrl: apiUrl
                });
                document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                        <p style="font-size: 0.9em; color: #999; margin-top: 10px;">錯誤: ${error.message}</p>
                        <p style="font-size: 0.8em; color: #999; margin-top: 5px;">請檢查瀏覽器控制台 (F12) 以獲取詳細錯誤信息</p>
                    </div>
                `;
            });
    }
    
    function showEnrollmentSystemStats() {
        console.log('showEnrollmentSystemStats 被調用 - 顯示科系分布統計');
        
        // 清除之前的圖表實例
        Chart.helpers.each(Chart.instances, function(instance) {
            if (instance.canvas.id.includes('enrollmentDepartmentChart') ||
                instance.canvas.id.includes('enrollmentSystemChart')) {
                instance.destroy();
            }
        });
        
        // 顯示載入中提示
        document.getElementById('enrollmentAnalyticsContent').innerHTML = `
            <div style="text-align: center; padding: 40px;">
                <i class="fas fa-spinner fa-spin fa-3x" style="color: #667eea; margin-bottom: 16px;"></i>
                <h4>載入科系分布統計中...</h4>
            </div>
        `;
        
        // 學制分布分析按鈕實際顯示科系分布統計
        showEnrollmentDepartmentStats();
    }
    
    function showEnrollmentGradeStats() {
        console.log('showEnrollmentGradeStats 被調用');
        
        // 從API獲取年級分布數據
        fetch(buildApiUrl('../../Topics-frontend/frontend/api/enrollment_stats_api.php', 'grade'))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                        </div>
                    `;
                    return;
                }
                
                const content = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-users"></i> 年級分布分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">年級分布統計</div>
                            <div class="chart-container">
                                <canvas id="enrollmentGradeChart"></canvas>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">年級詳細統計</h5>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                ${data.map((item, index) => {
                                    const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'];
                                    const color = colors[index % colors.length];
                                    const total = data.reduce((sum, d) => sum + d.value, 0);
                                    const percentage = ((item.value / total) * 100).toFixed(1);
                                    return `
                                        <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid ${color};">
                                            <div style="font-weight: bold; color: #333; margin-bottom: 5px;">${item.name}</div>
                                            <div style="font-size: 1.5em; font-weight: bold; color: ${color};">${item.value}人</div>
                                            <div style="font-size: 0.9em; color: #666;">${percentage}%</div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('enrollmentAnalyticsContent').innerHTML = content;
                
                // 創建長條圖
                setTimeout(() => {
                    const canvasElement = document.getElementById('enrollmentGradeChart');
                    if (!canvasElement) return;
                    
                    const ctx = canvasElement.getContext('2d');
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: data.map(item => item.name),
                            datasets: [{
                                label: '人數',
                                data: data.map(item => item.value),
                                backgroundColor: '#667eea',
                                borderColor: '#5a6fd8',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        stepSize: 1
                                    }
                                }
                            }
                        }
                    });
                }, 100);
            })
            .catch(error => {
                console.error('載入年級統計數據失敗:', error);
                document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }
    
    function showEnrollmentGenderStats() {
        console.log('showEnrollmentGenderStats 被調用');
        
        // 從API獲取性別分布數據
        fetch(buildApiUrl('../../Topics-frontend/frontend/api/enrollment_stats_api.php', 'gender'))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                        </div>
                    `;
                    return;
                }
                
                const content = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-venus-mars"></i> 性別分布分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">性別分布統計</div>
                            <div class="chart-container">
                                <canvas id="enrollmentGenderChart"></canvas>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">性別詳細統計</h5>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                ${data.map((item, index) => {
                                    const colors = ['#667eea', '#e91e63'];
                                    const color = colors[index % colors.length];
                                    const total = data.reduce((sum, d) => sum + d.value, 0);
                                    const percentage = ((item.value / total) * 100).toFixed(1);
                                    return `
                                        <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid ${color};">
                                            <div style="font-weight: bold; color: #333; margin-bottom: 5px;">${item.name}</div>
                                            <div style="font-size: 1.5em; font-weight: bold; color: ${color};">${item.value}人</div>
                                            <div style="font-size: 0.9em; color: #666;">${percentage}%</div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('enrollmentAnalyticsContent').innerHTML = content;
                
                // 創建圓餅圖
                setTimeout(() => {
                    const canvasElement = document.getElementById('enrollmentGenderChart');
                    if (!canvasElement) return;
                    
                    const ctx = canvasElement.getContext('2d');
                    new Chart(ctx, {
                        type: 'pie',
                        data: {
                            labels: data.map(item => item.name),
                            datasets: [{
                                data: data.map(item => item.value),
                                backgroundColor: ['#667eea', '#e91e63'],
                                borderWidth: 2,
                                borderColor: '#fff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        padding: 20,
                                        usePointStyle: true,
                                        font: { size: 16 }
                                    }
                                },
                                tooltip: {
                                    enabled: true,
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const value = context.parsed;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = ((value / total) * 100).toFixed(1);
                                            return `${label}: ${value}人 (${percentage}%)`;
                                        }
                                    }
                                }
                            }
                        }
                    });
                }, 100);
            })
            .catch(error => {
                console.error('載入性別統計數據失敗:', error);
                document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }
    
    function showEnrollmentIdentityStats() {
        console.log('showEnrollmentIdentityStats 被調用');
        
        // 從API獲取身分別分布數據
        fetch(buildApiUrl('../../Topics-frontend/frontend/api/enrollment_stats_api.php', 'identity'))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                        </div>
                    `;
                    return;
                }
                
                const content = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-user-tag"></i> 身分別分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">身分別分布統計</div>
                            <div class="chart-container">
                                <canvas id="enrollmentIdentityChart"></canvas>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">身分別詳細統計</h5>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                ${data.map((item, index) => {
                                    const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'];
                                    const color = colors[index % colors.length];
                                    const total = data.reduce((sum, d) => sum + d.value, 0);
                                    const percentage = ((item.value / total) * 100).toFixed(1);
                                    return `
                                        <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid ${color};">
                                            <div style="font-weight: bold; color: #333; margin-bottom: 5px;">${item.name}</div>
                                            <div style="font-size: 1.5em; font-weight: bold; color: ${color};">${item.value}人</div>
                                            <div style="font-size: 0.9em; color: #666;">${percentage}%</div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('enrollmentAnalyticsContent').innerHTML = content;
                
                // 創建圓餅圖
                setTimeout(() => {
                    const canvasElement = document.getElementById('enrollmentIdentityChart');
                    if (!canvasElement) return;
                    
                    const ctx = canvasElement.getContext('2d');
                    new Chart(ctx, {
                        type: 'doughnut',
                        data: {
                            labels: data.map(item => item.name),
                            datasets: [{
                                data: data.map(item => item.value),
                                backgroundColor: [
                                    '#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'
                                ],
                                borderWidth: 2,
                                borderColor: '#fff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        padding: 20,
                                        usePointStyle: true,
                                        font: { size: 16 }
                                    }
                                },
                                tooltip: {
                                    enabled: true,
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const value = context.parsed;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = ((value / total) * 100).toFixed(1);
                                            return `${label}: ${value}人 (${percentage}%)`;
                                        }
                                    }
                                }
                            }
                        }
                    });
                }, 100);
            })
            .catch(error => {
                console.error('載入身分別統計數據失敗:', error);
                document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }
    
    function showEnrollmentMonthlyStats() {
        console.log('showEnrollmentMonthlyStats 被調用');
        
        // 從API獲取月度趨勢數據
        fetch(buildApiUrl('../../Topics-frontend/frontend/api/enrollment_stats_api.php', 'monthly'))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                        </div>
                    `;
                    return;
                }
                
                const content = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-calendar-alt"></i> 月度趨勢分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">月度報名趨勢</div>
                            <div class="chart-container">
                                <canvas id="enrollmentMonthlyChart"></canvas>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">月度詳細統計</h5>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                ${data.map((item, index) => {
                                    const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'];
                                    const color = colors[index % colors.length];
                                    const total = data.reduce((sum, d) => sum + d.value, 0);
                                    const percentage = ((item.value / total) * 100).toFixed(1);
                                    return `
                                        <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid ${color};">
                                            <div style="font-weight: bold; color: #333; margin-bottom: 5px;">${item.name}</div>
                                            <div style="font-size: 1.5em; font-weight: bold; color: ${color};">${item.value}人</div>
                                            <div style="font-size: 0.9em; color: #666;">${percentage}%</div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('enrollmentAnalyticsContent').innerHTML = content;
                
                // 創建線圖
                setTimeout(() => {
                    const canvasElement = document.getElementById('enrollmentMonthlyChart');
                    if (!canvasElement) return;
                    
                    const ctx = canvasElement.getContext('2d');
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: data.map(item => item.name),
                            datasets: [{
                                label: '報名人數',
                                data: data.map(item => item.value),
                                borderColor: '#667eea',
                                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                                borderWidth: 3,
                                fill: true,
                                tension: 0.4,
                                pointBackgroundColor: '#667eea',
                                pointBorderColor: '#fff',
                                pointBorderWidth: 2,
                                pointRadius: 6
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        stepSize: 1
                                    }
                                }
                            }
                        }
                    });
                }, 100);
            })
            .catch(error => {
                console.error('載入月度統計數據失敗:', error);
                document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }
    
    function showEnrollmentSchoolDepartmentStats() {
        console.log('showEnrollmentSchoolDepartmentStats 被調用');
        
        const apiUrl = buildApiUrl('../../Topics-frontend/frontend/api/enrollment_stats_api.php', 'school_department');
        console.log('API URL:', apiUrl);
        
        // 從API獲取國中選擇科系統計數據
        fetch(apiUrl)
            .then(response => {
                console.log('API 響應狀態:', response.status, response.statusText);
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('API 返回數據:', data);
                if (data.error) {
                    document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                            <p style="font-size: 0.9em; color: #999; margin-top: 10px;">請檢查瀏覽器控制台以獲取詳細錯誤信息</p>
                        </div>
                    `;
                    return;
                }
                
                if (!data || data.length === 0) {
                    document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #6c757d;">
                            <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>暫無數據</h4>
                            <p>目前沒有國中選擇科系的統計數據</p>
                        </div>
                    `;
                    return;
                }
                
                // 收集所有縣市（只提取縣市部分，不包含學校名稱）
                const cities = new Set();
                data.forEach(school => {
                    if (school && school.school) {
                        const city = extractCityFromSchoolName(school.school);
                        if (city) {
                            cities.add(city);
                        }
                    }
                });
                const cityList = Array.from(cities).sort();
                
                console.log('提取的縣市列表:', cityList);
                console.log('學校數據範例:', data.slice(0, 5).map(s => ({ 
                    school: s.school, 
                    extractedCity: extractCityFromSchoolName(s.school) 
                })));
                
                console.log('提取的縣市列表:', cityList);
                console.log('學校數據範例:', data.slice(0, 3));
                
                // 儲存原始數據供篩選使用
                window.schoolDepartmentData = data;
                window.schoolDepartmentCityList = cityList;
                
                // 渲染內容（包含篩選控件）
                renderSchoolDepartmentContent(data, cityList);
            })
            .catch(error => {
                console.error('載入國中選擇科系統計數據失敗:', error);
                console.error('錯誤詳情:', {
                    message: error.message,
                    stack: error.stack,
                    apiUrl: apiUrl
                });
                document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                        <p style="font-size: 0.9em; color: #999; margin-top: 10px;">錯誤: ${error.message}</p>
                        <p style="font-size: 0.8em; color: #999; margin-top: 5px;">請檢查瀏覽器控制台 (F12) 以獲取詳細錯誤信息</p>
                    </div>
                `;
            });
    }
    
    // 渲染學校科系內容（支援篩選）
    function renderSchoolDepartmentContent(data, cityList, filteredData = null, savedState = null) {
        const displayData = filteredData || data;
        
        // 檢查是否已經有篩選控件容器，如果沒有則創建
        let filterContainer = document.getElementById('schoolDeptFilterContainer');
        let dataContainer = document.getElementById('schoolDeptDataContainer');
        
        // 如果是第一次渲染，創建完整的內容
        if (!filterContainer) {
            const fullContent = `
                <div style="margin-bottom: 20px;">
                    <h4 style="color: #667eea; margin-bottom: 15px;">
                        <i class="fas fa-school"></i> 國中選擇科系分析
                    </h4>
                    
                    <!-- 篩選控件容器（固定，不會被重新渲染） -->
                    <div id="schoolDeptFilterContainer" style="background: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                        <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                            <div style="flex: 1; min-width: 200px;">
                                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #495057; font-size: 14px;">
                                    <i class="fas fa-search"></i> 關鍵字搜尋
                                </label>
                                <input type="text" id="schoolDeptKeywordFilter" placeholder="搜尋學校名稱或科系..." 
                                       style="width: 100%; padding: 10px 12px; border: 2px solid #e9ecef; border-radius: 8px; font-size: 14px; transition: all 0.3s;"
                                       autocomplete="off"
                                       spellcheck="false"
                                       tabindex="0">
                            </div>
                            <div style="display: flex; gap: 10px; align-items: flex-end;">
                                <button id="exportSchoolDeptExcelBtn" 
                                        style="padding: 10px 20px; background: #28a745; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 500; transition: all 0.3s;"
                                        onmouseover="this.style.background='#218838'"
                                        onmouseout="this.style.background='#28a745'"
                                        onclick="exportSchoolDepartmentToExcel()">
                                    <i class="fas fa-file-excel"></i> 匯出 Excel
                                </button>
                                <button id="resetSchoolDeptFilterBtn" 
                                        style="padding: 10px 20px; background: #6c757d; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 500; transition: all 0.3s;"
                                        onmouseover="this.style.background='#5a6268'"
                                        onmouseout="this.style.background='#6c757d'">
                                    <i class="fas fa-redo"></i> 重置篩選
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 數據展示容器（會根據篩選結果更新） -->
                    <div id="schoolDeptDataContainer"></div>
                </div>
            `;
            
            document.getElementById('enrollmentAnalyticsContent').innerHTML = fullContent;
            
            // 初始化事件監聽器
            initializeSchoolDeptFilters(cityList);
        }
        
        // 只更新數據展示部分
        dataContainer = document.getElementById('schoolDeptDataContainer');
        if (dataContainer) {
            const dataContent = `
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 10px; margin-bottom: 20px;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; text-align: center;">
                        <div>
                            <div style="font-size: 2.5em; font-weight: bold; margin-bottom: 5px;">${displayData.length}</div>
                            <div style="font-size: 1em; opacity: 0.9;">參與國中數</div>
                        </div>
                        <div>
                            <div style="font-size: 2.5em; font-weight: bold; margin-bottom: 5px;">${displayData.reduce((sum, s) => sum + s.total_students, 0)}</div>
                            <div style="font-size: 1em; opacity: 0.9;">總選擇次數</div>
                        </div>
                    </div>
                </div>
                
                ${displayData.length === 0 ? `
                    <div style="text-align: center; padding: 40px; color: #6c757d; background: white; border-radius: 10px;">
                        <i class="fas fa-search fa-3x" style="margin-bottom: 16px; opacity: 0.3;"></i>
                        <h4>沒有符合篩選條件的資料</h4>
                        <p>請嘗試調整篩選條件</p>
                    </div>
                ` : `
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px;">
                        ${displayData.map((school, index) => {
                            // 使用更淺的顏色
                            const colors = ['#a8b5f0', '#7dd87d', '#ffd966', '#f5a5a5', '#7dd4e8', '#b8a5e8', '#ffb366', '#7dd4c8'];
                            const color = colors[index % colors.length];
                            
                            return `
                                <div style="background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: hidden;">
                                    <div style="background: ${color}; color: white; padding: 15px 20px;">
                                        <h5 style="margin: 0; font-size: 1.1em; font-weight: 600; display: flex; justify-content: space-between; align-items: center;">
                                            <span><i class="fas fa-school"></i> ${school.school}</span>
                                            <span style="background: rgba(255,255,255,0.3); padding: 4px 12px; border-radius: 15px; font-size: 0.9em;">
                                                ${school.total_students}次選擇
                                            </span>
                                        </h5>
                                    </div>
                                    
                                    <div style="padding: 20px;">
                                        <div style="margin-bottom: 15px; font-size: 0.9em; color: #666;">
                                            共選擇 <strong style="color: ${color};">${school.departments.length}</strong> 個科系
                                        </div>
                                        
                                        <div style="max-height: 400px; overflow-y: auto;">
                                            <table style="width: 100%; border-collapse: collapse;">
                                                <thead>
                                                    <tr style="background: #f8f9fa; border-bottom: 2px solid #dee2e6;">
                                                        <th style="padding: 10px; text-align: left; font-weight: 600; color: #495057; font-size: 0.9em;">科系名稱</th>
                                                        <th style="padding: 10px; text-align: center; font-weight: 600; color: #495057; font-size: 0.9em;">總數</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    ${school.departments.map((dept, deptIndex) => {
                                                        return `
                                                            <tr style="border-bottom: 1px solid #e9ecef; ${deptIndex % 2 === 0 ? 'background: #f8f9fa;' : ''}">
                                                                <td style="padding: 12px 10px; font-weight: 500; color: #333;">${dept.name}</td>
                                                                <td style="padding: 12px 10px; text-align: center; font-weight: bold; color: ${color};">${dept.total}</td>
                                                            </tr>
                                                        `;
                                                    }).join('')}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            `;
                        }).join('')}
                    </div>
                `}
            `;
            
            dataContainer.innerHTML = dataContent;
        }
    }
    
    // 初始化篩選控件的事件監聽器（只執行一次）
    function initializeSchoolDeptFilters(cityList) {
        setTimeout(() => {
            const keywordInput = document.getElementById('schoolDeptKeywordFilter');
            const resetBtn = document.getElementById('resetSchoolDeptFilterBtn');
            
            if (keywordInput) {
                // 確保輸入框可以正常輸入
                keywordInput.disabled = false;
                keywordInput.readOnly = false;
                keywordInput.style.pointerEvents = 'auto';
                keywordInput.style.userSelect = 'text';
                keywordInput.style.cursor = 'text';
                
                // 添加焦点和失焦样式处理
                keywordInput.addEventListener('focus', function() {
                    this.style.borderColor = '#667eea';
                    this.style.boxShadow = '0 0 0 3px rgba(102, 126, 234, 0.1)';
                }, false);
                
                keywordInput.addEventListener('blur', function() {
                    this.style.borderColor = '#e9ecef';
                    this.style.boxShadow = 'none';
                }, false);
                
                // 使用防抖函數來優化性能
                let filterTimeout;
                const triggerFilter = function() {
                    clearTimeout(filterTimeout);
                    filterTimeout = setTimeout(() => {
                        if (window.filterSchoolDepartment) {
                            window.filterSchoolDepartment();
                        }
                    }, 500); // 增加延遲時間，減少重新渲染頻率
                };
                
                // 只使用 input 事件，這是最可靠的事件，不會干擾輸入
                keywordInput.addEventListener('input', function(e) {
                    e.stopPropagation(); // 阻止事件冒泡
                    triggerFilter();
                }, false);
            }
            
            if (resetBtn) {
                resetBtn.addEventListener('click', function() {
                    if (window.resetSchoolDepartmentFilter) {
                        window.resetSchoolDepartmentFilter();
                    }
                }, false);
            }
        }, 100);
    }
    
    // 篩選學校科系資料（全局函數）
    window.filterSchoolDepartment = function() {
        if (!window.schoolDepartmentData || !window.schoolDepartmentCityList) {
            console.error('篩選失敗：缺少數據');
            return;
        }
        
        const keywordFilterEl = document.getElementById('schoolDeptKeywordFilter');
        
        if (!keywordFilterEl) {
            console.error('篩選失敗：找不到篩選控件');
            return;
        }
        
        const keywordFilter = keywordFilterEl.value.toLowerCase().trim() || '';
        
        console.log('篩選條件:', { keywordFilter, '數據總數': window.schoolDepartmentData.length });
        
        // 篩選資料
        const filteredData = [];
        
        for (let i = 0; i < window.schoolDepartmentData.length; i++) {
            const school = window.schoolDepartmentData[i];
            
            if (!school || !school.school) continue;
            
            // 關鍵字篩選（搜尋學校名稱或科系名稱）
            if (keywordFilter) {
                const schoolName = (school.school || '').toString();
                const schoolMatch = schoolName.toLowerCase().includes(keywordFilter);
                
                // 篩選科系列表，只保留符合關鍵字的科系
                const departments = Array.isArray(school.departments) ? school.departments : [];
                const filteredDepartments = departments.filter(dept => {
                    if (!dept || !dept.name) return false;
                    const deptName = dept.name.toString().toLowerCase();
                    return deptName.includes(keywordFilter);
                });
                
                console.log(`學校: ${schoolName}, 關鍵字: ${keywordFilter}, 學校匹配: ${schoolMatch}, 科系匹配數: ${filteredDepartments.length}`);
                
                // 如果學校名稱和科系都不符合，則過濾掉整個學校
                if (!schoolMatch && filteredDepartments.length === 0) {
                    continue; // 不符合關鍵字篩選，跳過
                }
                
                // 如果學校名稱符合，保留所有科系；如果只有科系符合，只保留符合的科系
                if (schoolMatch) {
                    // 學校名稱符合，保留所有科系
                    filteredData.push(school);
                } else {
                    // 只有科系符合，只保留符合的科系
                    const newSchool = {
                        school: school.school,
                        departments: filteredDepartments,
                        total_students: filteredDepartments.reduce((sum, dept) => sum + (dept.total || 0), 0)
                    };
                    filteredData.push(newSchool);
                }
            } else {
                // 沒有關鍵字篩選，直接加入
                filteredData.push(school);
            }
        }
        
        console.log('篩選結果:', filteredData.length, '所學校');
        
        // 只更新數據展示部分，不重新渲染輸入框
        if (typeof renderSchoolDepartmentContent === 'function') {
            renderSchoolDepartmentContent(window.schoolDepartmentData, window.schoolDepartmentCityList, filteredData);
        } else {
            console.error('renderSchoolDepartmentContent 函數不存在');
        }
    };
    
    // 為了向後兼容，也保留原來的函數名
    function filterSchoolDepartment() {
        window.filterSchoolDepartment();
    }
    
    // 重置篩選（全局函數）
    window.resetSchoolDepartmentFilter = function() {
        const keywordFilter = document.getElementById('schoolDeptKeywordFilter');
        
        if (keywordFilter) keywordFilter.value = '';
        
        // 重新渲染原始資料
        if (window.schoolDepartmentData && window.schoolDepartmentCityList) {
            if (typeof renderSchoolDepartmentContent === 'function') {
                renderSchoolDepartmentContent(window.schoolDepartmentData, window.schoolDepartmentCityList);
            } else {
                console.error('renderSchoolDepartmentContent 函數不存在');
            }
        }
    };
    
    // 為了向後兼容，也保留原來的函數名
    function resetSchoolDepartmentFilter() {
        window.resetSchoolDepartmentFilter();
    }
    
    // 匯出國中選擇科系統計資料為 Excel
    function exportSchoolDepartmentToExcel() {
        console.log('開始匯出 Excel');
        
        // 獲取當前顯示的資料（如果有篩選，使用篩選後的資料；否則使用原始資料）
        let exportData = window.schoolDepartmentData || [];
        
        // 檢查是否有篩選條件
        const keywordFilter = document.getElementById('schoolDeptKeywordFilter');
        if (keywordFilter && keywordFilter.value.trim()) {
            // 如果有篩選，需要重新應用篩選邏輯來獲取當前顯示的資料
            const keyword = keywordFilter.value.toLowerCase().trim();
            exportData = window.schoolDepartmentData.filter(school => {
                if (!school || !school.school) return false;
                
                const schoolName = school.school.toString().toLowerCase();
                const schoolMatch = schoolName.includes(keyword);
                
                const departments = Array.isArray(school.departments) ? school.departments : [];
                const filteredDepartments = departments.filter(dept => {
                    if (!dept || !dept.name) return false;
                    return dept.name.toString().toLowerCase().includes(keyword);
                });
                
                return schoolMatch || filteredDepartments.length > 0;
            }).map(school => {
                const schoolName = school.school.toString().toLowerCase();
                const schoolMatch = schoolName.includes(keyword);
                
                if (schoolMatch) {
                    return school; // 學校名稱符合，保留所有科系
                } else {
                    // 只有科系符合，只保留符合的科系
                    const departments = Array.isArray(school.departments) ? school.departments : [];
                    const filteredDepartments = departments.filter(dept => {
                        if (!dept || !dept.name) return false;
                        return dept.name.toString().toLowerCase().includes(keyword);
                    });
                    return {
                        school: school.school,
                        departments: filteredDepartments,
                        total_students: filteredDepartments.reduce((sum, dept) => sum + (dept.total || 0), 0)
                    };
                }
            });
        }
        
        if (!exportData || exportData.length === 0) {
            alert('目前沒有可匯出的資料');
            return;
        }
        
        // 準備 Excel 資料
        // 第一行：標題
        const excelData = [
            ['學校名稱', '科系名稱', '選擇次數']
        ];
        
        // 遍歷每個學校和科系
        exportData.forEach(school => {
            if (school.departments && school.departments.length > 0) {
                school.departments.forEach((dept, index) => {
                    excelData.push([
                        index === 0 ? school.school : '', // 只在第一行顯示學校名稱
                        dept.name || '',
                        dept.total || 0
                    ]);
                });
            } else {
                // 如果沒有科系資料，至少顯示學校名稱
                excelData.push([
                    school.school || '',
                    '',
                    0
                ]);
            }
        });
        
        // 添加統計行
        excelData.push([]); // 空行
        excelData.push(['統計', '', '']);
        excelData.push(['參與國中數', '', exportData.length]);
        excelData.push(['總選擇次數', '', exportData.reduce((sum, s) => sum + (s.total_students || 0), 0)]);
        
        // 創建工作表
        const ws = XLSX.utils.aoa_to_sheet(excelData);
        
        // 設置列寬
        ws['!cols'] = [
            { wch: 30 }, // 學校名稱
            { wch: 25 }, // 科系名稱
            { wch: 12 }  // 選擇次數
        ];
        
        // 創建工作簿
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, '國中選擇科系統計');
        
        // 生成檔案名稱（包含日期時間）
        const now = new Date();
        const dateStr = now.getFullYear() + 
                       String(now.getMonth() + 1).padStart(2, '0') + 
                       String(now.getDate()).padStart(2, '0') + '_' +
                       String(now.getHours()).padStart(2, '0') + 
                       String(now.getMinutes()).padStart(2, '0');
        const fileName = `國中選擇科系統計_${dateStr}.xlsx`;
        
        // 匯出檔案
        XLSX.writeFile(wb, fileName);
        
        console.log('Excel 匯出完成:', fileName);
    }
    
    function clearEnrollmentCharts() {
        console.log('clearEnrollmentCharts 被調用');
        
        // 清除所有就讀意願相關的Chart.js實例，但保留科系分布分析
        Chart.helpers.each(Chart.instances, function(instance) {
            if (instance.canvas.id.includes('enrollmentSystemChart') ||
                instance.canvas.id.includes('enrollmentGradeChart') ||
                instance.canvas.id.includes('enrollmentGenderChart') ||
                instance.canvas.id.includes('enrollmentIdentityChart') ||
                instance.canvas.id.includes('enrollmentMonthlyChart')) {
                instance.destroy();
            }
        });
        
        // 重新顯示科系分布分析，確保它始終顯示
        showEnrollmentDepartmentStats();
    }
    
    // 顯示續招報名科系學生詳情
    function showContinuedAdmissionDepartmentStudents(departmentName) {
        console.log('顯示續招報名科系學生詳情:', departmentName);

        // 顯示載入中
        const loadingContent = `
            <div style="background: white; border-radius: 10px; padding: 20px; max-width: 800px; max-height: 600px; overflow-y: auto;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #dee2e6; padding-bottom: 15px;">
                    <h4 style="margin: 0; color: #333;">
                        <i class="fas fa-users"></i> ${departmentName} - 學生名單
                    </h4>
                    <button onclick="closeStudentModal()" style="background: #dc3545; color: white; border: none; padding: 8px 16px; border-radius: 5px; cursor: pointer;">
                        <i class="fas fa-times"></i> 關閉
                    </button>
                </div>
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-spinner fa-spin fa-2x" style="color: #667eea; margin-bottom: 15px;"></i>
                    <p>載入學生資料中...</p>
                </div>
            </div>
        `;

        showStudentModal(loadingContent);

        // 從API獲取該科系的學生資料
        fetch('../../Topics-frontend/frontend/api/continued_admission_department_students_api.php?department=' + encodeURIComponent(departmentName))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    const errorContent = `
                        <div style="background: white; border-radius: 10px; padding: 20px; max-width: 800px; max-height: 600px; overflow-y: auto;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #dee2e6; padding-bottom: 15px;">
                                <h4 style="margin: 0; color: #333;">
                                    <i class="fas fa-users"></i> ${departmentName} - 學生名單
                                </h4>
                                <button onclick="closeStudentModal()" style="background: #dc3545; color: white; border: none; padding: 8px 16px; border-radius: 5px; cursor: pointer;">
                                    <i class="fas fa-times"></i> 關閉
                                </button>
                            </div>
                            <div style="text-align: center; padding: 40px; color: #dc3545;">
                                <i class="fas fa-exclamation-triangle fa-2x" style="margin-bottom: 15px;"></i>
                                <p>載入學生資料失敗: ${data.error}</p>
                            </div>
                        </div>
                    `;
                    document.getElementById('studentModal').innerHTML = errorContent;
                    return;
                }

                // 創建模態視窗內容
                const modalContent = `
                    <div style="background: white; border-radius: 10px; padding: 20px; max-width: 800px; max-height: 600px; overflow-y: auto;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #dee2e6; padding-bottom: 15px;">
                            <h4 style="margin: 0; color: #333;">
                                <i class="fas fa-users"></i> ${departmentName} - 學生名單
                            </h4>
                            <button onclick="closeStudentModal()" style="background: #dc3545; color: white; border: none; padding: 8px 16px; border-radius: 5px; cursor: pointer;">
                                <i class="fas fa-times"></i> 關閉
                            </button>
                        </div>

                        <div style="margin-bottom: 15px;">
                            <span style="background: #667eea; color: white; padding: 5px 12px; border-radius: 15px; font-size: 14px;">
                                共 ${data.length} 位學生
                            </span>
                        </div>

                        <div style="background: #f8f9fa; border-radius: 8px; overflow: hidden;">
                            <table style="width: 100%; border-collapse: collapse;">
                                <thead>
                                    <tr style="background: #e9ecef;">
                                        <th style="padding: 12px; text-align: left; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">姓名</th>
                                        <th style="padding: 12px; text-align: left; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">學校</th>
                                        <th style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">年級</th>
                                        <th style="padding: 12px; text-align: left; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">聯絡電話</th>
                                        <th style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">報名時間</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${data.map((student, index) => `
                                        <tr style="border-bottom: 1px solid #dee2e6;">
                                            <td style="padding: 12px; font-weight: 500; color: #333;">${student.name || '未填寫'}</td>
                                            <td style="padding: 12px; color: #666;">${student.school || '未填寫'}</td>
                                            <td style="padding: 12px; text-align: center; color: #666;">${student.grade || '未填寫'}</td>
                                            <td style="padding: 12px; color: #666;">
                                                ${student.phone1 ? `<div style="margin-bottom: 2px;"><i class="fas fa-phone" style="color: #667eea; margin-right: 5px;"></i>${student.phone1}</div>` : ''}
                                                ${student.phone2 ? `<div><i class="fas fa-phone" style="color: #667eea; margin-right: 5px;"></i>${student.phone2}</div>` : ''}
                                                ${!student.phone1 && !student.phone2 ? '未填寫' : ''}
                                            </td>
                                            <td style="padding: 12px; text-align: center; color: #666; font-size: 0.9em;">${student.created_at ? new Date(student.created_at).toLocaleDateString('zh-TW') : '未填寫'}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;

                // 更新模態視窗內容
                document.getElementById('studentModal').innerHTML = modalContent;
            })
            .catch(error => {
                console.error('載入學生資料失敗:', error);
                const errorContent = `
                    <div style="background: white; border-radius: 10px; padding: 20px; max-width: 800px; max-height: 600px; overflow-y: auto;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #dee2e6; padding-bottom: 15px;">
                            <h4 style="margin: 0; color: #333;">
                                <i class="fas fa-users"></i> ${departmentName} - 學生名單
                            </h4>
                            <button onclick="closeStudentModal()" style="background: #dc3545; color: white; border: none; padding: 8px 16px; border-radius: 5px; cursor: pointer;">
                                <i class="fas fa-times"></i> 關閉
                            </button>
                        </div>
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-2x" style="margin-bottom: 15px;"></i>
                            <p>載入學生資料失敗，請稍後再試</p>
                        </div>
                    </div>
                `;
                document.getElementById('studentModal').innerHTML = errorContent;
            });
    }

    // 顯示科系學生詳情
    function showDepartmentStudents(departmentName) {
        console.log('顯示科系學生詳情:', departmentName);
        
        // 顯示載入中
        const loadingContent = `
            <div style="background: white; border-radius: 10px; padding: 20px; max-width: 800px; max-height: 600px; overflow-y: auto;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #dee2e6; padding-bottom: 15px;">
                    <h4 style="margin: 0; color: #333;">
                        <i class="fas fa-users"></i> ${departmentName} - 學生名單
                    </h4>
                    <button onclick="closeStudentModal()" style="background: #dc3545; color: white; border: none; padding: 8px 16px; border-radius: 5px; cursor: pointer;">
                        <i class="fas fa-times"></i> 關閉
                    </button>
                </div>
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-spinner fa-spin fa-2x" style="color: #667eea; margin-bottom: 15px;"></i>
                    <p>載入學生資料中...</p>
                </div>
            </div>
        `;
        
        showStudentModal(loadingContent);
        
        // 從API獲取該科系的學生資料
        fetch('../../Topics-frontend/frontend/api/enrollment_department_students_api.php?department=' + encodeURIComponent(departmentName))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    const errorContent = `
                        <div style="background: white; border-radius: 10px; padding: 20px; max-width: 800px; max-height: 600px; overflow-y: auto;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #dee2e6; padding-bottom: 15px;">
                                <h4 style="margin: 0; color: #333;">
                                    <i class="fas fa-users"></i> ${departmentName} - 學生名單
                                </h4>
                                <button onclick="closeStudentModal()" style="background: #dc3545; color: white; border: none; padding: 8px 16px; border-radius: 5px; cursor: pointer;">
                                    <i class="fas fa-times"></i> 關閉
                                </button>
                            </div>
                            <div style="text-align: center; padding: 40px; color: #dc3545;">
                                <i class="fas fa-exclamation-triangle fa-2x" style="margin-bottom: 15px;"></i>
                                <p>載入學生資料失敗: ${data.error}</p>
                            </div>
                        </div>
                    `;
                    document.getElementById('studentModal').innerHTML = errorContent;
                    return;
                }
                
                // 創建模態視窗內容
                const modalContent = `
                    <div style="background: white; border-radius: 10px; padding: 20px; max-width: 800px; max-height: 600px; overflow-y: auto;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #dee2e6; padding-bottom: 15px;">
                            <h4 style="margin: 0; color: #333;">
                                <i class="fas fa-users"></i> ${departmentName} - 學生名單
                            </h4>
                            <button onclick="closeStudentModal()" style="background: #dc3545; color: white; border: none; padding: 8px 16px; border-radius: 5px; cursor: pointer;">
                                <i class="fas fa-times"></i> 關閉
                            </button>
                        </div>
                        
                        <div style="margin-bottom: 15px;">
                            <span style="background: #667eea; color: white; padding: 5px 12px; border-radius: 15px; font-size: 14px;">
                                共 ${data.length} 位學生
                            </span>
                        </div>
                        
                        <div style="background: #f8f9fa; border-radius: 8px; overflow: hidden;">
                            <table style="width: 100%; border-collapse: collapse;">
                                <thead>
                                    <tr style="background: #e9ecef;">
                                        <th style="padding: 12px; text-align: left; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">姓名</th>
                                        <th style="padding: 12px; text-align: left; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">學校</th>
                                        <th style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">年級</th>
                                        <th style="padding: 12px; text-align: left; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">聯絡電話</th>
                                        <th style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">報名時間</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${data.map((student, index) => `
                                        <tr style="border-bottom: 1px solid #dee2e6;">
                                            <td style="padding: 12px; font-weight: 500; color: #333;">${student.name || '未填寫'}</td>
                                            <td style="padding: 12px; color: #666;">${student.school || '未填寫'}</td>
                                            <td style="padding: 12px; text-align: center; color: #666;">${student.grade || '未填寫'}</td>
                                            <td style="padding: 12px; color: #666;">
                                                ${student.phone1 ? `<div style="margin-bottom: 2px;"><i class="fas fa-phone" style="color: #667eea; margin-right: 5px;"></i>${student.phone1}</div>` : ''}
                                                ${student.phone2 ? `<div><i class="fas fa-phone" style="color: #667eea; margin-right: 5px;"></i>${student.phone2}</div>` : ''}
                                                ${!student.phone1 && !student.phone2 ? '未填寫' : ''}
                                            </td>
                                            <td style="padding: 12px; text-align: center; color: #666; font-size: 0.9em;">${student.created_at ? new Date(student.created_at).toLocaleDateString('zh-TW') : '未填寫'}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
                
                // 更新模態視窗內容
                document.getElementById('studentModal').innerHTML = modalContent;
            })
            .catch(error => {
                console.error('載入學生資料失敗:', error);
                const errorContent = `
                    <div style="background: white; border-radius: 10px; padding: 20px; max-width: 800px; max-height: 600px; overflow-y: auto;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #dee2e6; padding-bottom: 15px;">
                            <h4 style="margin: 0; color: #333;">
                                <i class="fas fa-users"></i> ${departmentName} - 學生名單
                            </h4>
                            <button onclick="closeStudentModal()" style="background: #dc3545; color: white; border: none; padding: 8px 16px; border-radius: 5px; cursor: pointer;">
                                <i class="fas fa-times"></i> 關閉
                            </button>
                        </div>
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-2x" style="margin-bottom: 15px;"></i>
                            <p>載入學生資料失敗，請稍後再試</p>
                        </div>
                    </div>
                `;
                document.getElementById('studentModal').innerHTML = errorContent;
            });
    }
    
    // 生成模擬學生資料
    function generateMockStudents(departmentName) {
        const studentCounts = {
            '嬰幼兒保育科': 5,
            '護理科': 4,
            '無特定': 1,
            '資訊管理科': 1,
            '應用外語科': 1,
            '視光科': 1,
            '企業管理科': 1
        };
        
        const count = studentCounts[departmentName] || 1;
        const students = [];
        
        const names = ['張小明', '李美華', '王大雄', '陳小芳', '林志強', '黃淑芬', '劉建國', '吳雅婷'];
        const schools = ['台北市立第一中學', '新北市立第二中學', '桃園市立第三中學', '台中市立第四中學', '台南市立第五中學'];
        const grades = ['一年級', '二年級', '三年級'];
        
        for (let i = 0; i < count; i++) {
            students.push({
                name: names[i] || `學生${i + 1}`,
                school: schools[i % schools.length],
                grade: grades[i % grades.length],
                created_at: new Date(Date.now() - Math.random() * 30 * 24 * 60 * 60 * 1000).toLocaleDateString('zh-TW')
            });
        }
        
        return students;
    }
    
    // 顯示學生模態視窗
    function showStudentModal(content) {
        // 創建模態視窗背景
        const modal = document.createElement('div');
        modal.id = 'studentModal';
        modal.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 10000;
        `;
        
        modal.innerHTML = content;
        document.body.appendChild(modal);
        
        // 點擊背景關閉模態視窗
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeStudentModal();
            }
        });
    }
    
    // 關閉學生模態視窗
    function closeStudentModal() {
        const modal = document.getElementById('studentModal');
        if (modal) {
            modal.remove();
        }
    }
    
    // 續招報名統計 - 性別分布分析
    function showContinuedAdmissionGenderStats() {
        console.log('showContinuedAdmissionGenderStats 被調用');
        
        fetch(buildApiUrl('../../Topics-frontend/frontend/api/continued_admission_stats_api.php', 'gender'))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                        </div>
                    `;
                    return;
                }
                
                const content = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-venus-mars"></i> 性別分布分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">性別分布統計</div>
                            <div class="chart-container">
                                <canvas id="continuedAdmissionGenderChart"></canvas>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">性別詳細統計</h5>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                ${data.map((item, index) => {
                                    const colors = ['#667eea', '#e91e63'];
                                    const color = colors[index % colors.length];
                                    const total = data.reduce((sum, d) => sum + d.value, 0);
                                    const percentage = ((item.value / total) * 100).toFixed(1);
                                    return `
                                        <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid ${color};">
                                            <div style="font-weight: bold; color: #333; margin-bottom: 5px;">${item.name}</div>
                                            <div style="font-size: 1.5em; font-weight: bold; color: ${color};">${item.value}人</div>
                                            <div style="font-size: 0.9em; color: #666;">${percentage}%</div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = content;
                
                // 創建圓餅圖
                setTimeout(() => {
                    const canvasElement = document.getElementById('continuedAdmissionGenderChart');
                    if (!canvasElement) return;
                    
                    const ctx = canvasElement.getContext('2d');
                    new Chart(ctx, {
                        type: 'pie',
                        data: {
                            labels: data.map(item => item.name),
                            datasets: [{
                                data: data.map(item => item.value),
                                backgroundColor: ['#667eea', '#e91e63'],
                                borderWidth: 2,
                                borderColor: '#fff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        padding: 20,
                                        usePointStyle: true,
                                        font: { size: 16 }
                                    }
                                },
                                tooltip: {
                                    enabled: true,
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const value = context.parsed;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = ((value / total) * 100).toFixed(1);
                                            return `${label}: ${value}人 (${percentage}%)`;
                                        }
                                    }
                                }
                            }
                        }
                    });
                }, 100);
            })
            .catch(error => {
                console.error('載入性別統計數據失敗:', error);
                document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }
    
    // 續招報名統計 - 縣市分布分析
    function showContinuedAdmissionCityStats() {
        console.log('showContinuedAdmissionCityStats 被調用');
        
        fetch(buildApiUrl('../../Topics-frontend/frontend/api/continued_admission_stats_api.php', 'school_city'))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                        </div>
                    `;
                    return;
                }
                
                const content = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-map-marker-alt"></i> 縣市分布分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">就讀縣市分布</div>
                            <div class="chart-container">
                                <canvas id="continuedAdmissionCityChart"></canvas>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">縣市詳細統計</h5>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                ${data.map((item, index) => {
                                    const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'];
                                    const color = colors[index % colors.length];
                                    const total = data.reduce((sum, d) => sum + d.value, 0);
                                    const percentage = ((item.value / total) * 100).toFixed(1);
                                    return `
                                        <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid ${color};">
                                            <div style="font-weight: bold; color: #333; margin-bottom: 5px;">${item.name}</div>
                                            <div style="font-size: 1.5em; font-weight: bold; color: ${color};">${item.value}人</div>
                                            <div style="font-size: 0.9em; color: #666;">${percentage}%</div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = content;
                
                // 創建長條圖
                setTimeout(() => {
                    const canvasElement = document.getElementById('continuedAdmissionCityChart');
                    if (!canvasElement) return;
                    
                    const ctx = canvasElement.getContext('2d');
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: data.map(item => item.name),
                            datasets: [{
                                label: '報名人數',
                                data: data.map(item => item.value),
                                backgroundColor: '#667eea',
                                borderColor: '#5a6fd8',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        stepSize: 1
                                    }
                                }
                            }
                        }
                    });
                }, 100);
            })
            .catch(error => {
                console.error('載入縣市統計數據失敗:', error);
                document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }
    
    // 續招報名統計 - 志願選擇分析
    function showContinuedAdmissionChoicesStats() {
        console.log('showContinuedAdmissionChoicesStats 被調用');
        
        fetch(buildApiUrl('../../Topics-frontend/frontend/api/continued_admission_stats_api.php', 'choices'))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                        </div>
                    `;
                    return;
                }
                
                const content = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-list-ol"></i> 志願選擇分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">科系志願選擇分布</div>
                            <div class="chart-container">
                                <canvas id="continuedAdmissionChoicesChart"></canvas>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">科系詳細統計</h5>
                            
                            <!-- 總報名人數 -->
                            <div style="background: white; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #667eea;">
                                <div style="font-weight: bold; color: #333; margin-bottom: 5px;">總報名人數</div>
                                <div style="font-size: 1.5em; font-weight: bold; color: #667eea;">${data.reduce((sum, d) => sum + d.value, 0)}人</div>
                            </div>
                            
                            <!-- 科系列表 -->
                            <div style="background: white; border-radius: 8px; overflow: hidden;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr style="background: #f8f9fa;">
                                            <th style="padding: 15px; text-align: left; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">科系名稱</th>
                                            <th style="padding: 15px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">報名人數</th>
                                            <th style="padding: 15px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">百分比</th>
                                            <th style="padding: 15px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${data.map((item, index) => {
                                            const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8', '#6f42c1', '#fd7e14', '#20c997', '#e83e8c', '#6c757d'];
                                            const color = colors[index % colors.length];
                                            const total = data.reduce((sum, d) => sum + d.value, 0);
                                            const percentage = ((item.value / total) * 100).toFixed(1);
                                            return `
                                                <tr style="border-bottom: 1px solid #dee2e6;">
                                                    <td style="padding: 15px; font-weight: 500; color: #333;">${item.name}</td>
                                                    <td style="padding: 15px; text-align: center; font-weight: bold; color: #333;">${item.value}人</td>
                                                    <td style="padding: 15px; text-align: center; color: #666;">${percentage}%</td>
                                                    <td style="padding: 15px; text-align: center;">
                                                        <button onclick="showContinuedAdmissionDepartmentStudents('${item.name}')"
                                                                style="background: ${color}; color: white; border: none; padding: 8px 16px; border-radius: 20px; cursor: pointer; font-size: 14px; transition: all 0.3s ease;">
                                                            查看詳情
                                                        </button>
                                                    </td>
                                                </tr>
                                            `;
                                        }).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = content;
                
                // 創建圓餅圖
                setTimeout(() => {
                    const canvasElement = document.getElementById('continuedAdmissionChoicesChart');
                    if (!canvasElement) return;
                    
                    const ctx = canvasElement.getContext('2d');
                    new Chart(ctx, {
                        type: 'pie',
                        data: {
                            labels: data.map(item => item.name),
                            datasets: [{
                                data: data.map(item => item.value),
                                backgroundColor: [
                                    '#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'
                                ],
                                borderWidth: 2,
                                borderColor: '#fff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        padding: 20,
                                        usePointStyle: true,
                                        font: { size: 16 }
                                    }
                                },
                                tooltip: {
                                    enabled: true,
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const value = context.parsed;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = ((value / total) * 100).toFixed(1);
                                            return `${label}: ${value}次 (${percentage}%)`;
                                        }
                                    }
                                }
                            }
                        }
                    });
                }, 100);
            })
            .catch(error => {
                console.error('載入志願統計數據失敗:', error);
                document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }
    
    // 續招報名統計 - 月度趨勢分析
    function showContinuedAdmissionMonthlyStats() {
        console.log('showContinuedAdmissionMonthlyStats 被調用');
        
        fetch(buildApiUrl('../../Topics-frontend/frontend/api/continued_admission_stats_api.php', 'monthly'))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                        </div>
                    `;
                    return;
                }
                
                const content = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-calendar-alt"></i> 月度趨勢分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">月度報名趨勢</div>
                            <div class="chart-container">
                                <canvas id="continuedAdmissionMonthlyChart"></canvas>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">月度詳細統計</h5>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                ${data.map((item, index) => {
                                    const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'];
                                    const color = colors[index % colors.length];
                                    const total = data.reduce((sum, d) => sum + d.value, 0);
                                    const percentage = ((item.value / total) * 100).toFixed(1);
                                    return `
                                        <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid ${color};">
                                            <div style="font-weight: bold; color: #333; margin-bottom: 5px;">${item.name}</div>
                                            <div style="font-size: 1.5em; font-weight: bold; color: ${color};">${item.value}人</div>
                                            <div style="font-size: 0.9em; color: #666;">${percentage}%</div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = content;
                
                // 創建線圖
                setTimeout(() => {
                    const canvasElement = document.getElementById('continuedAdmissionMonthlyChart');
                    if (!canvasElement) return;
                    
                    const ctx = canvasElement.getContext('2d');
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: data.map(item => item.name),
                            datasets: [{
                                label: '報名人數',
                                data: data.map(item => item.value),
                                borderColor: '#667eea',
                                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                                borderWidth: 3,
                                fill: true,
                                tension: 0.4,
                                pointBackgroundColor: '#667eea',
                                pointBorderColor: '#fff',
                                pointBorderWidth: 2,
                                pointRadius: 6
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        stepSize: 1
                                    }
                                }
                            }
                        }
                    });
                }, 100);
            })
            .catch(error => {
                console.error('載入月度統計數據失敗:', error);
                document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }
    
    // 續招報名統計 - 審核狀態分析
    function showContinuedAdmissionStatusStats() {
        console.log('showContinuedAdmissionStatusStats 被調用');
        
        fetch(buildApiUrl('../../Topics-frontend/frontend/api/continued_admission_stats_api.php', 'status'))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                        </div>
                    `;
                    return;
                }
                
                const content = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-clipboard-check"></i> 審核狀態分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">審核狀態分布</div>
                            <div class="chart-container">
                                <canvas id="continuedAdmissionStatusChart"></canvas>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">狀態詳細統計</h5>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                ${data.map((item, index) => {
                                    const colors = ['#ffc107', '#28a745', '#dc3545'];
                                    const color = colors[index % colors.length];
                                    const total = data.reduce((sum, d) => sum + d.value, 0);
                                    const percentage = ((item.value / total) * 100).toFixed(1);
                                    return `
                                        <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid ${color};">
                                            <div style="font-weight: bold; color: #333; margin-bottom: 5px;">${item.name}</div>
                                            <div style="font-size: 1.5em; font-weight: bold; color: ${color};">${item.value}人</div>
                                            <div style="font-size: 0.9em; color: #666;">${percentage}%</div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = content;
                
                // 創建圓餅圖
                setTimeout(() => {
                    const canvasElement = document.getElementById('continuedAdmissionStatusChart');
                    if (!canvasElement) return;
                    
                    const ctx = canvasElement.getContext('2d');
                    new Chart(ctx, {
                        type: 'doughnut',
                        data: {
                            labels: data.map(item => item.name),
                            datasets: [{
                                data: data.map(item => item.value),
                                backgroundColor: ['#ffc107', '#28a745', '#dc3545'],
                                borderWidth: 2,
                                borderColor: '#fff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        padding: 20,
                                        usePointStyle: true,
                                        font: { size: 16 }
                                    }
                                },
                                tooltip: {
                                    enabled: true,
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const value = context.parsed;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = ((value / total) * 100).toFixed(1);
                                            return `${label}: ${value}人 (${percentage}%)`;
                                        }
                                    }
                                }
                            }
                        }
                    });
                }, 100);
            })
            .catch(error => {
                console.error('載入狀態統計數據失敗:', error);
                document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }
    
    function clearContinuedAdmissionCharts() {
        console.log('clearContinuedAdmissionCharts 被調用');
        
        // 清除所有續招報名相關的Chart.js實例，但保留志願選擇分析
        Chart.helpers.each(Chart.instances, function(instance) {
            if (instance.canvas.id.includes('continuedAdmissionGenderChart') || 
                instance.canvas.id.includes('continuedAdmissionCityChart') ||
                instance.canvas.id.includes('continuedAdmissionMonthlyChart') ||
                instance.canvas.id.includes('continuedAdmissionStatusChart')) {
                instance.destroy();
            }
        });
        
        // 重新顯示志願選擇分析，確保它始終顯示
        showContinuedAdmissionChoicesStats();
    }
    
    // 五專入學說明會統計 - 年級分布分析
    function showAdmissionGradeStats() {
        console.log('showAdmissionGradeStats 被調用');
        
        fetch(buildApiUrl('../../Topics-frontend/frontend/api/admission_stats_api.php', 'grade'))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('admissionAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                        </div>
                    `;
                    return;
                }
                
                const content = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-users"></i> 年級分布分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">年級分布統計</div>
                            <div class="chart-container">
                                <canvas id="admissionGradeChart"></canvas>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">年級詳細統計</h5>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                ${data.map((item, index) => {
                                    const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'];
                                    const color = colors[index % colors.length];
                                    const total = data.reduce((sum, d) => sum + d.value, 0);
                                    const percentage = ((item.value / total) * 100).toFixed(1);
                                    return `
                                        <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid ${color};">
                                            <div style="font-weight: bold; color: #333; margin-bottom: 5px;">${item.name}</div>
                                            <div style="font-size: 1.5em; font-weight: bold; color: ${color};">${item.value}人</div>
                                            <div style="font-size: 0.9em; color: #666;">${percentage}%</div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('admissionAnalyticsContent').innerHTML = content;
                
                // 創建長條圖
                setTimeout(() => {
                    const canvasElement = document.getElementById('admissionGradeChart');
                    if (!canvasElement) return;
                    
                    const ctx = canvasElement.getContext('2d');
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: data.map(item => item.name),
                            datasets: [{
                                label: '報名人數',
                                data: data.map(item => item.value),
                                backgroundColor: '#667eea',
                                borderColor: '#5a6fd8',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        stepSize: 1
                                    }
                                }
                            }
                        }
                    });
                }, 100);
            })
            .catch(error => {
                console.error('載入年級統計數據失敗:', error);
                document.getElementById('admissionAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }
    
    // 五專入學說明會統計 - 學校分布分析
    function showAdmissionSchoolStats() {
        console.log('showAdmissionSchoolStats 被調用');
        
        fetch(buildApiUrl('../../Topics-frontend/frontend/api/admission_stats_api.php', 'school'))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('admissionAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                        </div>
                    `;
                    return;
                }
                
                const content = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-school"></i> 學校分布分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">學校報名人數分布</div>
                            <div class="chart-container">
                                <canvas id="admissionSchoolChart"></canvas>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">學校詳細統計</h5>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                ${data.map((item, index) => {
                                    const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'];
                                    const color = colors[index % colors.length];
                                    const total = data.reduce((sum, d) => sum + d.value, 0);
                                    const percentage = ((item.value / total) * 100).toFixed(1);
                                    return `
                                        <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid ${color};">
                                            <div style="font-weight: bold; color: #333; margin-bottom: 5px;">${item.name}</div>
                                            <div style="font-size: 1.5em; font-weight: bold; color: ${color};">${item.value}人</div>
                                            <div style="font-size: 0.9em; color: #666;">${percentage}%</div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('admissionAnalyticsContent').innerHTML = content;
                
                // 創建長條圖
                setTimeout(() => {
                    const canvasElement = document.getElementById('admissionSchoolChart');
                    if (!canvasElement) return;
                    
                    const ctx = canvasElement.getContext('2d');
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: data.map(item => item.name),
                            datasets: [{
                                label: '報名人數',
                                data: data.map(item => item.value),
                                backgroundColor: '#667eea',
                                borderColor: '#5a6fd8',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        stepSize: 1
                                    }
                                }
                            }
                        }
                    });
                }, 100);
            })
            .catch(error => {
                console.error('載入學校統計數據失敗:', error);
                document.getElementById('admissionAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }
    
    // 五專入學說明會統計 - 場次分布分析
    function showAdmissionSessionStats() {
        console.log('showAdmissionSessionStats 被調用');
        
        fetch(buildApiUrl('../../Topics-frontend/frontend/api/admission_stats_api.php', 'session'))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('admissionAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                        </div>
                    `;
                    return;
                }
                
                const content = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-calendar-alt"></i> 場次分布分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">場次報名人數分布</div>
                            <div class="chart-container">
                                <canvas id="admissionSessionChart"></canvas>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">場次詳細統計</h5>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                ${data.map((item, index) => {
                                    const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'];
                                    const color = colors[index % colors.length];
                                    const total = data.reduce((sum, d) => sum + d.value, 0);
                                    const percentage = ((item.value / total) * 100).toFixed(1);
                                    return `
                                        <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid ${color};">
                                            <div style="font-weight: bold; color: #333; margin-bottom: 5px;">${item.name}</div>
                                            <div style="font-size: 1.5em; font-weight: bold; color: ${color};">${item.value}人</div>
                                            <div style="font-size: 0.9em; color: #666;">${percentage}%</div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('admissionAnalyticsContent').innerHTML = content;
                
                // 創建圓餅圖
                setTimeout(() => {
                    const canvasElement = document.getElementById('admissionSessionChart');
                    if (!canvasElement) return;
                    
                    const ctx = canvasElement.getContext('2d');
                    new Chart(ctx, {
                        type: 'pie',
                        data: {
                            labels: data.map(item => item.name),
                            datasets: [{
                                data: data.map(item => item.value),
                                backgroundColor: [
                                    '#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'
                                ],
                                borderWidth: 2,
                                borderColor: '#fff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        padding: 20,
                                        usePointStyle: true,
                                        font: { size: 16 }
                                    }
                                },
                                tooltip: {
                                    enabled: true,
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const value = context.parsed;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = ((value / total) * 100).toFixed(1);
                                            return `${label}: ${value}人 (${percentage}%)`;
                                        }
                                    }
                                }
                            }
                        }
                    });
                }, 100);
            })
            .catch(error => {
                console.error('載入場次統計數據失敗:', error);
                document.getElementById('admissionAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }
    
    // 五專入學說明會統計 - 課程選擇分析
    function showAdmissionCourseStats() {
        console.log('showAdmissionCourseStats 被調用');
        
        fetch(buildApiUrl('../../Topics-frontend/frontend/api/admission_stats_api.php', 'course'))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('admissionAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                        </div>
                    `;
                    return;
                }
                
                const content = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-book-open"></i> 課程選擇分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">體驗課程選擇分布</div>
                            <div class="chart-container">
                                <canvas id="admissionCourseChart"></canvas>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">課程詳細統計</h5>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                                ${data.map((item, index) => {
                                    const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'];
                                    const color = colors[index % colors.length];
                                    const total = data.reduce((sum, d) => sum + d.value, 0);
                                    const percentage = ((item.value / total) * 100).toFixed(1);
                                    return `
                                        <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid ${color};">
                                            <div style="font-weight: bold; color: #333; margin-bottom: 10px;">${item.name}</div>
                                            <div style="font-size: 1.5em; font-weight: bold; color: ${color}; margin-bottom: 5px;">${item.value}次</div>
                                            <div style="font-size: 0.9em; color: #666; margin-bottom: 8px;">${percentage}%</div>
                                            <div style="display: flex; justify-content: space-between; font-size: 0.8em;">
                                                <span style="color: #28a745;">第一選擇: ${item.first_choice || 0}</span>
                                                <span style="color: #ffc107;">第二選擇: ${item.second_choice || 0}</span>
                                            </div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('admissionAnalyticsContent').innerHTML = content;
                
                // 創建圓餅圖
                setTimeout(() => {
                    const canvasElement = document.getElementById('admissionCourseChart');
                    if (!canvasElement) return;
                    
                    const ctx = canvasElement.getContext('2d');
                    new Chart(ctx, {
                        type: 'pie',
                        data: {
                            labels: data.map(item => item.name),
                            datasets: [{
                                data: data.map(item => item.value),
                                backgroundColor: [
                                    '#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'
                                ],
                                borderWidth: 2,
                                borderColor: '#fff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        padding: 20,
                                        usePointStyle: true,
                                        font: { size: 16 }
                                    }
                                },
                                tooltip: {
                                    enabled: true,
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const value = context.parsed;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = ((value / total) * 100).toFixed(1);
                                            return `${label}: ${value}次 (${percentage}%)`;
                                        }
                                    }
                                }
                            }
                        }
                    });
                }, 100);
            })
            .catch(error => {
                console.error('載入課程統計數據失敗:', error);
                document.getElementById('admissionAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }
    
    // 五專入學說明會統計 - 資訊接收分析
    function showAdmissionReceiveInfoStats() {
        console.log('showAdmissionReceiveInfoStats 被調用');
        
        fetch(buildApiUrl('../../Topics-frontend/frontend/api/admission_stats_api.php', 'receive_info'))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('admissionAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                        </div>
                    `;
                    return;
                }
                
                const content = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-envelope"></i> 資訊接收分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">資訊接收意願分布</div>
                            <div class="chart-container">
                                <canvas id="admissionReceiveInfoChart"></canvas>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">資訊接收詳細統計</h5>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                ${data.map((item, index) => {
                                    const colors = ['#28a745', '#dc3545', '#6c757d'];
                                    const color = colors[index % colors.length];
                                    const total = data.reduce((sum, d) => sum + d.value, 0);
                                    const percentage = ((item.value / total) * 100).toFixed(1);
                                    return `
                                        <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid ${color};">
                                            <div style="font-weight: bold; color: #333; margin-bottom: 5px;">${item.name}</div>
                                            <div style="font-size: 1.5em; font-weight: bold; color: ${color};">${item.value}人</div>
                                            <div style="font-size: 0.9em; color: #666;">${percentage}%</div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('admissionAnalyticsContent').innerHTML = content;
                
                // 創建圓餅圖
                setTimeout(() => {
                    const canvasElement = document.getElementById('admissionReceiveInfoChart');
                    if (!canvasElement) return;
                    
                    const ctx = canvasElement.getContext('2d');
                    new Chart(ctx, {
                        type: 'doughnut',
                        data: {
                            labels: data.map(item => item.name),
                            datasets: [{
                                data: data.map(item => item.value),
                                backgroundColor: ['#28a745', '#dc3545', '#6c757d'],
                                borderWidth: 2,
                                borderColor: '#fff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        padding: 20,
                                        usePointStyle: true,
                                        font: { size: 16 }
                                    }
                                },
                                tooltip: {
                                    enabled: true,
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const value = context.parsed;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = ((value / total) * 100).toFixed(1);
                                            return `${label}: ${value}人 (${percentage}%)`;
                                        }
                                    }
                                }
                            }
                        }
                    });
                }, 100);
            })
            .catch(error => {
                console.error('載入資訊接收統計數據失敗:', error);
                document.getElementById('admissionAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }
    
    function clearAdmissionCharts() {
        console.log('clearAdmissionCharts 被調用');
        
        // 清除所有五專入學說明會相關的Chart.js實例
        Chart.helpers.each(Chart.instances, function(instance) {
            if (instance.canvas.id.includes('admissionGradeChart') || 
                instance.canvas.id.includes('admissionSchoolChart') ||
                instance.canvas.id.includes('admissionSessionChart') ||
                instance.canvas.id.includes('admissionCourseChart') ||
                instance.canvas.id.includes('admissionMonthlyChart') ||
                instance.canvas.id.includes('admissionReceiveInfoChart')) {
                instance.destroy();
            }
        });
        
        document.getElementById('admissionAnalyticsContent').innerHTML = `
            <div class="empty-state">
                <i class="fas fa-graduation-cap fa-3x" style="margin-bottom: 16px;"></i>
                <h4>選擇上方的統計類型來查看詳細分析</h4>
                <p>提供年級分布、學校分布、場次分布、課程選擇、月度趨勢、資訊接收等多維度統計</p>
            </div>
        `;
    }
    
    // ========== 其他必要函數 ==========
    
    // 搜尋功能
    document.addEventListener('DOMContentLoaded', function() {
        console.log('DOM已載入，開始初始化...');
        
        // 如果是學校行政人員且在教師列表視圖，自動顯示科系招生總覽
        if (isSchoolAdmin && isTeacherListView) {
            console.log('學校行政人員登入，自動顯示科系招生總覽');
            setTimeout(() => {
                showDepartmentOverviewStats();
            }, 500);
        }
        
        // 自動顯示就讀意願統計的科系分布分析
        setTimeout(() => {
            showEnrollmentDepartmentStats();
        }, 1000);
        
        // 自動顯示續招報名統計的志願選擇分析
        setTimeout(() => {
            showContinuedAdmissionChoicesStats();
        }, 1500);
        
        // 初始化完成
        console.log('統計分析系統初始化完成');
        
        const searchInput = document.getElementById('searchInput');
        const teacherNameFilter = document.getElementById('teacherNameFilter');
        const departmentFilter = document.getElementById('departmentFilter');
        const table = document.getElementById('recordsTable');
        const rows = table ? table.getElementsByTagName('tbody')[0].getElementsByTagName('tr') : [];

        // 統一的篩選函數
        function applyFilters() {
            const searchValue = searchInput ? searchInput.value.toLowerCase() : '';
            const teacherNameValue = teacherNameFilter ? teacherNameFilter.value : '';
            const departmentValue = departmentFilter ? departmentFilter.value : '';
            let visibleCount = 0;

                for (let i = 0; i < rows.length; i++) {
                    const cells = rows[i].getElementsByTagName('td');
                let shouldShow = true;

                    if (isTeacherListView) {
                    const teacherName = cells[0] ? (cells[0].textContent || cells[0].innerText) : '';
                    const department = cells[1] ? (cells[1].textContent || cells[1].innerText) : '';
                    
                    // 搜尋框篩選（同時搜尋姓名和系所）
                    if (searchValue) {
                        const searchText = teacherName + department;
                        if (searchText.toLowerCase().indexOf(searchValue) === -1) {
                            shouldShow = false;
                        }
                    }
                    
                    // 教師姓名下拉選單篩選
                    if (teacherNameValue && teacherName !== teacherNameValue) {
                        shouldShow = false;
                    }
                    
                    // 科系下拉選單篩選
                    if (departmentValue && department !== departmentValue) {
                        shouldShow = false;
                    }
                    } else {
                    // 詳細記錄視圖的搜尋
                    const schoolName = cells[1] ? (cells[1].textContent || cells[1].innerText) : '';
                    const activityType = cells[2] ? (cells[2].textContent || cells[2].innerText) : '';
                    const searchText = schoolName + activityType;
                    
                    if (searchValue && searchText.toLowerCase().indexOf(searchValue) === -1) {
                        shouldShow = false;
                    }
                }

                if (shouldShow) {
                        rows[i].style.display = "";
                    visibleCount++;
                    } else {
                        rows[i].style.display = "none";
                    }
                }
            
            // 更新顯示的教師數量
            const totalCountElement = document.getElementById('totalTeacherCount');
            if (totalCountElement) {
                totalCountElement.textContent = visibleCount;
            }
        }

        // 為各個篩選控制項添加事件監聽器
        if (searchInput) {
            searchInput.addEventListener('keyup', applyFilters);
        }
        
        if (teacherNameFilter) {
            teacherNameFilter.addEventListener('change', applyFilters);
        }
        
        if (departmentFilter) {
            departmentFilter.addEventListener('change', applyFilters);
        }
    });
    
    // 重置篩選
    function resetFilters() {
        const searchInput = document.getElementById('searchInput');
        const teacherNameFilter = document.getElementById('teacherNameFilter');
        const departmentFilter = document.getElementById('departmentFilter');
        
        if (searchInput) searchInput.value = '';
        if (teacherNameFilter) teacherNameFilter.value = '';
        if (departmentFilter) departmentFilter.value = '';
        
        // 重新應用篩選（實際上是清除所有篩選）
        const table = document.getElementById('recordsTable');
        if (table) {
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            let totalCount = 0;
            for (let i = 0; i < rows.length; i++) {
                rows[i].style.display = "";
                totalCount++;
            }
            
            const totalCountElement = document.getElementById('totalTeacherCount');
            if (totalCountElement) {
                totalCountElement.textContent = totalCount;
            }
        }
    }

    // 查看記錄詳情
    function viewRecord(record) {
        const modalBody = document.getElementById('viewModalBody');
        let content = `
            <p><strong>活動日期:</strong> ${record.activity_date || 'N/A'}</p>
            <p><strong>教師姓名:</strong> ${record.teacher_name || 'N/A'}</p>
            <p><strong>所屬系所:</strong> ${record.teacher_department || 'N/A'}</p>
            <p><strong>學校名稱:</strong> ${record.school_name || 'N/A'}</p>
            <p><strong>活動類型:</strong> ${record.activity_type || 'N/A'}</p>
            <p><strong>活動時間:</strong> ${record.activity_time || 'N/A'}</p>
            <p><strong>提交時間:</strong> ${new Date(record.created_at).toLocaleString()}</p>
            <hr>
            <p><strong>聯絡窗口:</strong> ${record.contact_person || '未填寫'}</p>
            <p><strong>聯絡電話:</strong> ${record.contact_phone || '未填寫'}</p>
            <p><strong>參與對象:</strong> ${record.participants || '未填寫'}</p>
        `;
        
        if (record.notes) {
            content += `<hr><p><strong>備註:</strong></p><p style="white-space: pre-wrap;">${record.notes}</p>`;
        }
        
        modalBody.innerHTML = content;
        document.getElementById('viewModal').style.display = 'block';
    }

    // 關閉Modal
    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }

    // 點擊Modal外部關閉
    window.onclick = function(event) {
        const modals = document.getElementsByClassName('modal');
        for (let modal of modals) {
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        }
    }
    </script>
</body>
</html>