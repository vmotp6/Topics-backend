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
    $records_sql = "SELECT ar.*, t.name AS teacher_name, t.department AS teacher_department
                    FROM activity_records ar
                    LEFT JOIN teacher t ON ar.teacher_id = t.user_id
                    WHERE ar.teacher_id = ?
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
    $teachers_sql = "SELECT t.user_id, t.name AS teacher_name, t.department AS teacher_department, COUNT(ar.id) AS record_count
                     FROM teacher t
                     JOIN activity_records ar ON t.user_id = ar.teacher_id
                     GROUP BY t.user_id, t.name, t.department
                     ORDER BY record_count DESC, t.name ASC";
    $result = $conn->query($teachers_sql);

    // 為了統計圖表，獲取所有活動記錄
    $all_activity_records = [];
    $all_records_sql = "SELECT ar.*, t.name AS teacher_name, t.department AS teacher_department
                        FROM activity_records ar
                        LEFT JOIN teacher t ON ar.teacher_id = t.user_id
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
                                <button class="btn-view" onclick="showEnrollmentDepartmentStats()">
                                    <i class="fas fa-graduation-cap"></i> 科系分布分析
                                </button>
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
                                <button class="btn-view" onclick="clearEnrollmentCharts()" style="background: #dc3545; color: white; border-color: #dc3545;">
                                    <i class="fas fa-arrow-up"></i> 收回圖表
                                </button>
                            </div>
                        </div>
                        
                        <!-- 就讀意願統計內容區域 -->
                        <div id="enrollmentAnalyticsContent" style="min-height: 200px;">
                            <div class="empty-state">
                                <i class="fas fa-graduation-cap fa-3x" style="margin-bottom: 16px;"></i>
                                <h4>選擇上方的統計類型來查看詳細分析</h4>
                                <p>提供科系分布、學制選擇、年級分布、性別比例等多維度統計</p>
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
                                <button class="btn-view" onclick="showContinuedAdmissionChoicesStats()">
                                    <i class="fas fa-list-ol"></i> 志願選擇分析
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
                            <div class="empty-state">
                                <i class="fas fa-file-alt fa-3x" style="margin-bottom: 16px;"></i>
                                <h4>選擇上方的統計類型來查看詳細分析</h4>
                                <p>提供性別分布、縣市分布、志願選擇、月度趨勢、審核狀態等多維度統計</p>
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
                                <button class="btn-view" onclick="showAdmissionMonthlyStats()">
                                    <i class="fas fa-chart-line"></i> 月度趨勢分析
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


                    <div class="card">
                        <div class="card-header">
                            <h3>教師列表 (共 <?php echo count($teachers_with_records); ?> 位)</h3>
                            <input type="text" id="searchInput" class="search-input" placeholder="搜尋教師姓名或系所...">
                        </div>
                        <div class="card-body table-container">
                            <?php if (empty($teachers_with_records)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-users-slash fa-3x" style="margin-bottom: 16px;"></i>
                                    <p>目前沒有任何教師提交過活動紀錄。</p>
                                </div>
                            <?php else: ?>
                                <table class="table" id="recordsTable">
                                    <thead>
                                        <tr>
                                            <th>教師姓名</th>
                                            <th>所屬系所</th>
                                            <th>紀錄筆數</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($teachers_with_records as $teacher): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($teacher['teacher_name']); ?></td>
                                            <td><?php echo htmlspecialchars($teacher['teacher_department']); ?></td>
                                            <td><?php echo htmlspecialchars($teacher['record_count']); ?></td>
                                            <td>
                                                <a href="?teacher_id=<?php echo $teacher['user_id']; ?>" class="btn-view">查看紀錄</a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
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

    // 調試信息
    console.log('PHP 傳遞的數據:', activityRecords);
    console.log('數據長度:', activityRecords.length);
    
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
                instance.canvas.id.includes('schoolStatsChart')) {
                instance.destroy();
            }
        });
        
        document.getElementById('activityAnalyticsContent').innerHTML = `
            <div class="empty-state">
                <i class="fas fa-chart-line fa-3x" style="margin-bottom: 16px;"></i>
                <h4>選擇上方的統計類型來查看詳細分析</h4>
                <p>提供教師活動參與度、活動類型分布、時間趨勢等多維度統計</p>
            </div>
        `;
    }
    
    // 就讀意願統計 - 科系分布分析
    function showEnrollmentDepartmentStats() {
        console.log('showEnrollmentDepartmentStats 被調用');
        
        // 從API獲取就讀意願數據
        fetch('../../Topics-frontend/frontend/api/enrollment_stats_api.php?action=department')
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
                console.error('載入科系統計數據失敗:', error);
                document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }
    
    function showEnrollmentSystemStats() {
        console.log('showEnrollmentSystemStats 被調用');
        
        // 從API獲取學制分布數據
        fetch('../../Topics-frontend/frontend/api/enrollment_stats_api.php?action=system')
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
                            <i class="fas fa-chart-pie"></i> 學制分布分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">學制選擇分布</div>
                            <div class="chart-container">
                                <canvas id="enrollmentSystemChart"></canvas>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">學制詳細統計</h5>
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
                    const canvasElement = document.getElementById('enrollmentSystemChart');
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
                console.error('載入學制統計數據失敗:', error);
                document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }
    
    function showEnrollmentGradeStats() {
        console.log('showEnrollmentGradeStats 被調用');
        
        // 從API獲取年級分布數據
        fetch('../../Topics-frontend/frontend/api/enrollment_stats_api.php?action=grade')
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
        fetch('../../Topics-frontend/frontend/api/enrollment_stats_api.php?action=gender')
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
        fetch('../../Topics-frontend/frontend/api/enrollment_stats_api.php?action=identity')
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
        fetch('../../Topics-frontend/frontend/api/enrollment_stats_api.php?action=monthly')
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
    
    function clearEnrollmentCharts() {
        console.log('clearEnrollmentCharts 被調用');
        
        // 清除所有就讀意願相關的Chart.js實例
        Chart.helpers.each(Chart.instances, function(instance) {
            if (instance.canvas.id.includes('enrollmentDepartmentChart') || 
                instance.canvas.id.includes('enrollmentSystemChart') ||
                instance.canvas.id.includes('enrollmentGradeChart') ||
                instance.canvas.id.includes('enrollmentGenderChart') ||
                instance.canvas.id.includes('enrollmentIdentityChart') ||
                instance.canvas.id.includes('enrollmentMonthlyChart')) {
                instance.destroy();
            }
        });
        
        document.getElementById('enrollmentAnalyticsContent').innerHTML = `
            <div class="empty-state">
                <i class="fas fa-graduation-cap fa-3x" style="margin-bottom: 16px;"></i>
                <h4>選擇上方的統計類型來查看詳細分析</h4>
                <p>提供科系分布、學制選擇、年級分布、性別比例等多維度統計</p>
            </div>
        `;
    }
    
    // 續招報名統計 - 性別分布分析
    function showContinuedAdmissionGenderStats() {
        console.log('showContinuedAdmissionGenderStats 被調用');
        
        fetch('../../Topics-frontend/frontend/api/continued_admission_stats_api.php?action=gender')
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
        
        fetch('../../Topics-frontend/frontend/api/continued_admission_stats_api.php?action=school_city')
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
        
        fetch('../../Topics-frontend/frontend/api/continued_admission_stats_api.php?action=choices')
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
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                ${data.map((item, index) => {
                                    const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'];
                                    const color = colors[index % colors.length];
                                    const total = data.reduce((sum, d) => sum + d.value, 0);
                                    const percentage = ((item.value / total) * 100).toFixed(1);
                                    return `
                                        <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid ${color};">
                                            <div style="font-weight: bold; color: #333; margin-bottom: 5px;">${item.name}</div>
                                            <div style="font-size: 1.5em; font-weight: bold; color: ${color};">${item.value}次</div>
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
        
        fetch('../../Topics-frontend/frontend/api/continued_admission_stats_api.php?action=monthly')
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
        
        fetch('../../Topics-frontend/frontend/api/continued_admission_stats_api.php?action=status')
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
        
        // 清除所有續招報名相關的Chart.js實例
        Chart.helpers.each(Chart.instances, function(instance) {
            if (instance.canvas.id.includes('continuedAdmissionGenderChart') || 
                instance.canvas.id.includes('continuedAdmissionCityChart') ||
                instance.canvas.id.includes('continuedAdmissionChoicesChart') ||
                instance.canvas.id.includes('continuedAdmissionMonthlyChart') ||
                instance.canvas.id.includes('continuedAdmissionStatusChart')) {
                instance.destroy();
            }
        });
        
        document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = `
            <div class="empty-state">
                <i class="fas fa-file-alt fa-3x" style="margin-bottom: 16px;"></i>
                <h4>選擇上方的統計類型來查看詳細分析</h4>
                <p>提供性別分布、縣市分布、志願選擇、月度趨勢、審核狀態等多維度統計</p>
            </div>
        `;
    }
    
    // 五專入學說明會統計 - 年級分布分析
    function showAdmissionGradeStats() {
        console.log('showAdmissionGradeStats 被調用');
        
        fetch('../../Topics-frontend/frontend/api/admission_stats_api.php?action=grade')
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
        
        fetch('../../Topics-frontend/frontend/api/admission_stats_api.php?action=school')
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
        
        fetch('../../Topics-frontend/frontend/api/admission_stats_api.php?action=session')
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
        
        fetch('../../Topics-frontend/frontend/api/admission_stats_api.php?action=course')
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
    
    // 五專入學說明會統計 - 月度趨勢分析
    function showAdmissionMonthlyStats() {
        console.log('showAdmissionMonthlyStats 被調用');
        
        fetch('../../Topics-frontend/frontend/api/admission_stats_api.php?action=monthly')
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
                            <i class="fas fa-chart-line"></i> 月度趨勢分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">月度報名趨勢</div>
                            <div class="chart-container">
                                <canvas id="admissionMonthlyChart"></canvas>
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
                
                document.getElementById('admissionAnalyticsContent').innerHTML = content;
                
                // 創建線圖
                setTimeout(() => {
                    const canvasElement = document.getElementById('admissionMonthlyChart');
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
        
        fetch('../../Topics-frontend/frontend/api/admission_stats_api.php?action=receive_info')
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
        
        // 初始化完成
        console.log('統計分析系統初始化完成');
        
        const searchInput = document.getElementById('searchInput');
        const table = document.getElementById('recordsTable');
        const rows = table ? table.getElementsByTagName('tbody')[0].getElementsByTagName('tr') : [];

        if (searchInput) {
            searchInput.addEventListener('keyup', function() {
                const filter = searchInput.value.toLowerCase();

                for (let i = 0; i < rows.length; i++) {
                    const cells = rows[i].getElementsByTagName('td');
                    let text = '';

                    if (isTeacherListView) {
                        // 搜尋教師姓名和系所
                        if (cells[0]) text += cells[0].textContent || cells[0].innerText;
                        if (cells[1]) text += (cells[1].textContent || cells[1].innerText);
                    } else {
                        // 搜尋學校名稱和活動類型
                        if (cells[1]) text += cells[1].textContent || cells[1].innerText;
                        if (cells[2]) text += (cells[2].textContent || cells[2].innerText);
                    }

                    if (text.toLowerCase().indexOf(filter) > -1) {
                        rows[i].style.display = "";
                    } else {
                        rows[i].style.display = "none";
                    }
                }
            });
        }
    });

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