<?php
require_once __DIR__ . '/session_config.php';

checkBackendLogin();

// 引入資料庫設定（相對或多路徑可在 config.php 內處理）
require_once '../../Topics-frontend/frontend/config.php';

// 檢查用戶角色 - 僅允許教師(TEA)進入
$session_role = $_SESSION['role'] ?? '';
$session_username = $_SESSION['username'] ?? '';
$session_user_id = $_SESSION['user_id'] ?? 0;

$role_map = [
    '老師' => 'TEA',
    'teacher' => 'TEA',
    'TEA' => 'TEA',
];
$normalized_role = $role_map[$session_role] ?? $session_role;

if ($normalized_role !== 'TEA') {
    http_response_code(403);
    echo '權限不足：僅教師(TEA)可使用此功能。';
    exit;
}

$page_title = '畢業生資訊填寫';
$error_message = '';
$success_message = '';

// 初始化變數（實際值稍後於 try 區段取得）
$teacher_department = '';
$teacher_department_name = '';
$graduated_by_dept = [];

$teacher_department = '';
$teacher_department_name = '';
$graduated_by_dept = [];

try {
    $conn = getDatabaseConnection();
    if (!$conn) throw new Exception('資料庫連接失敗');

    // 確保相關資料表存在
    ensureTablesExist($conn);

    // 獲取老師的科系資訊
    $dept_check = $conn->query("SHOW TABLES LIKE 'teacher'");
    if ($dept_check && $dept_check->num_rows > 0) {
        $teacher_sql = "SELECT t.department FROM teacher t WHERE t.user_id = ?";
        $teacher_stmt = $conn->prepare($teacher_sql);
        if ($teacher_stmt) {
            $teacher_stmt->bind_param('i', $session_user_id);
            $teacher_stmt->execute();
            $teacher_result = $teacher_stmt->get_result();
            if ($teacher_row = $teacher_result->fetch_assoc()) {
                $teacher_department = $teacher_row['department'];
                
                // 如果有 departments 表，查詢科系名稱
                $dept_table_check = $conn->query("SHOW TABLES LIKE 'departments'");
                if ($dept_table_check && $dept_table_check->num_rows > 0) {
                    $dept_name_sql = "SELECT name FROM departments WHERE code = ?";
                    $dept_name_stmt = $conn->prepare($dept_name_sql);
                    if ($dept_name_stmt) {
                        $dept_name_stmt->bind_param('s', $teacher_department);
                        $dept_name_stmt->execute();
                        $dept_name_result = $dept_name_stmt->get_result();
                        if ($dept_name_row = $dept_name_result->fetch_assoc()) {
                            $teacher_department_name = $dept_name_row['name'];
                        }
                        $dept_name_stmt->close();
                    }
                }
            }
            $teacher_stmt->close();
        }
    }

    if (empty($teacher_department)) {
        throw new Exception('無法取得您的科系資訊，請聯絡管理員。');
    }

    // 偵測資料庫中第一個存在的科系欄位（需在提交前取得以供驗證使用）
    $dept_col = detectFirstExistingColumn($conn, 'new_student_basic_info', [
        'department_id', 'department', 'department_code', 'dept_code', 'dept'
    ]);

    if (empty($dept_col)) {
        throw new Exception('資料庫中找不到科系欄位，請聯絡管理員。');
    }

    // 決定是否有 departments 表並建構 join 條件（用於容錯比對 code 或 name）
    $dept_key = '';
    $dept_join = '';
    $dept_table_check = $conn->query("SHOW TABLES LIKE 'departments'");
    if ($dept_table_check && $dept_table_check->num_rows > 0) {
        if (hasColumn($conn, 'departments', 'code')) $dept_key = 'code';
        elseif (hasColumn($conn, 'departments', 'id')) $dept_key = 'id';
        if ($dept_key !== '' && hasColumn($conn, 'departments', 'name')) {
            if ($dept_key === 'code') {
                $dept_join = " LEFT JOIN departments d ON s.`$dept_col` COLLATE utf8mb4_unicode_ci = d.`$dept_key` COLLATE utf8mb4_unicode_ci ";
            } else {
                $dept_join = " LEFT JOIN departments d ON s.`$dept_col` = d.`$dept_key` ";
            }
        }
    }

    // 處理表單提交
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        if ($_POST['action'] === 'save') {
            $student_id = (int)($_POST['student_id'] ?? 0);
            $university = trim($_POST['university'] ?? '');
            $achievements = trim($_POST['achievements'] ?? '');

            if ($student_id <= 0) {
                $error_message = '無效的學生 ID';
            } else {
                // 驗證學生是否屬於該科系（容錯：比對欄位值或 departments.name）
                $verify_sql = "SELECT s.id FROM new_student_basic_info s " . $dept_join . " WHERE s.id = ? AND (s.`$dept_col` = ?";
                if ($dept_join !== '') {
                    $verify_sql .= " OR COALESCE(d.name,'') = ?";
                }
                $verify_sql .= ")";
                $verify_stmt = $conn->prepare($verify_sql);
                if (!$verify_stmt) throw new Exception('SQL準備失敗: ' . $conn->error);

                // 綁定參數（id, department [, department])
                if ($dept_join !== '') {
                    $verify_stmt->bind_param('iss', $student_id, $teacher_department, $teacher_department);
                } else {
                    $verify_stmt->bind_param('is', $student_id, $teacher_department);
                }
                $verify_stmt->execute();
                $verify_result = $verify_stmt->get_result();

                if ($verify_result->num_rows === 0) {
                    $error_message = '您無權編輯此學生的資訊。';
                    $verify_stmt->close();
                } else {
                    $verify_stmt->close();
                    // 更新學生資料
                    $update_sql = "UPDATE new_student_basic_info 
                                   SET university = ?, achievements = ?
                                   WHERE id = ?";
                    $stmt = $conn->prepare($update_sql);
                    if (!$stmt) throw new Exception('SQL準備失敗: ' . $conn->error);

                    $stmt->bind_param('ssi', $university, $achievements, $student_id);
                    if ($stmt->execute()) {
                        $success_message = '已成功保存學生資料';
                    } else {
                        $error_message = '保存失敗: ' . $stmt->error;
                    }
                    $stmt->close();
                }
            }
        }
    }

    // 獲取選中的班級類別（僅允許選擇：孝班 或 忠班）
    $selected_class = isset($_GET['class']) ? trim($_GET['class']) : '';

    // 偵測科系欄位名稱（使用與 send_graduated_students_to_directors.php 相同的邏輯）
    $dept_col = detectFirstExistingColumn($conn, 'new_student_basic_info', [
        'department_id', 'department', 'department_code', 'dept_code', 'dept'
    ]);

    if (empty($dept_col)) {
        throw new Exception('資料庫中找不到科系欄位，請聯絡管理員。');
    }

    // 本學年度範圍（以建立時間判定本屆畢業生）
    $grad_year_west = (int)date('Y');
    $roc_enroll_year = $grad_year_west - 1916; // 與 send_graduated_students_to_directors.php 相同邏輯
    $year_range = getAcademicYearRangeByRoc($roc_enroll_year);

    // 顯示類別：孝班、忠班（按老師科系篩選、且僅限本學年度建立的學生）
    $available_classes = ['孝班', '忠班'];

    // 獲取選定班級的學生列表
    $students = [];
    $total_students = 0;
    if (!empty($selected_class)) {
        // 驗證班級名稱（防止 SQL 注入）
            if (!in_array($selected_class, $available_classes)) {
                $error_message = '無效的班級選擇';
            } else {
                // 依類別轉換為關鍵字 (孝 -> %孝% ; 忠 -> %忠%)
                $kw = (mb_strpos($selected_class, '孝') !== false) ? '%孝%' : '%忠%';

                $students_sql = "SELECT s.id, s.student_no, s.student_name, s.university, s.achievements, s.created_at
                                FROM new_student_basic_info s " . $dept_join . " WHERE (s.`$dept_col` = ?";
                if ($dept_join !== '') {
                    $students_sql .= " OR COALESCE(d.name,'') = ?";
                }
                $students_sql .= ") AND s.class_name LIKE ? AND s.created_at >= ? AND s.created_at <= ? ORDER BY s.student_no ASC";

                $stmt = $conn->prepare($students_sql);
                if (!$stmt) throw new Exception('查詢準備失敗: ' . $conn->error);

                if ($dept_join !== '') {
                    $stmt->bind_param('sssss', $teacher_department, $teacher_department, $kw, $year_range['start'], $year_range['end']);
                } else {
                    $stmt->bind_param('ssss', $teacher_department, $kw, $year_range['start'], $year_range['end']);
                }
                $stmt->execute();
                $result = $stmt->get_result();
                $students = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
                $total_students = count($students);
                $stmt->close();
            }
    }

    $conn->close();

} catch (Exception $e) {
    $error_message = $e->getMessage();
}

// 偵測資料庫中第一個存在的科系欄位
function detectFirstExistingColumn($conn, $table, $candidates) {
    if (!is_array($candidates)) return '';
    foreach ($candidates as $col) {
        if (hasColumn($conn, $table, $col)) return (string)$col;
    }
    return '';
}

function ensureTablesExist($conn) {
    // 檢查並添加必要的欄位
    $check_columns = [
        'university' => "ALTER TABLE new_student_basic_info ADD COLUMN university VARCHAR(100) DEFAULT NULL",
        'achievements' => "ALTER TABLE new_student_basic_info ADD COLUMN achievements LONGTEXT DEFAULT NULL"
    ];

    foreach ($check_columns as $col => $alter_sql) {
        if (!hasColumn($conn, 'new_student_basic_info', $col)) {
            $conn->query($alter_sql);
        }
    }
}

function hasColumn($conn, $table, $column) {
    if (!$conn) return false;
    $table = trim((string)$table);
    $column = trim((string)$column);
    if ($table === '' || $column === '') return false;
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        if (!$stmt) return false;
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $cnt = 0;
        $stmt->bind_result($cnt);
        $stmt->fetch();
        $ok = ((int)$cnt > 0);
        $stmt->close();
        return $ok;
    } catch (Exception $e) {
        return false;
    }
}

// 學年度範圍：傳入入學民國年（roc_year），回傳該學年度的建立時間範圍（西元）
function getAcademicYearRangeByRoc($roc_year) {
    $start_west = $roc_year + 1911;
    $end_west = $roc_year + 1912;
    return [
        'start' => $start_west . '-07-01 00:00:00',
        'end' => $end_west . '-08-01 23:59:59'
    ];
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #f0f2f5;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #262626;
        }

        .dashboard {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            margin-left: 250px;
            transition: all 0.3s ease;
        }

        .main-content.expanded {
            margin-left: 60px;
        }

        .content {
            padding: 24px;
        }

        .panel {
            background: #fff;
            border: 1px solid #f0f0f0;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
            margin-bottom: 20px;
        }

        .panel-header {
            font-size: 18px;
            font-weight: 700;
            color: #262626;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .panel-description {
            color: #8c8c8c;
            font-size: 14px;
            margin-bottom: 20px;
        }

        .class-selector {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .class-btn {
            padding: 10px 16px;
            border: 2px solid #d9d9d9;
            border-radius: 6px;
            background: #fff;
            color: #262626;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .class-btn:hover {
            border-color: #1890ff;
            color: #1890ff;
        }

        .class-btn.active {
            background: #1890ff;
            border-color: #1890ff;
            color: #fff;
        }

        .message {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .message.success {
            background: #f6ffed;
            border: 1px solid #b7eb8f;
            color: #52c41a;
        }

        .message.error {
            background: #fff2f0;
            border: 1px solid #ffccc7;
            color: #ff4d4f;
        }

        .students-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .students-table thead {
            background: #fafafa;
            border-top: 1px solid #e0e0e0;
            border-bottom: 1px solid #e0e0e0;
        }

        .students-table th {
            padding: 12px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
            color: #262626;
        }

        .students-table td {
            padding: 12px;
            border-bottom: 1px solid #e0e0e0;
            font-size: 14px;
        }

        .students-table tbody tr:hover {
            background: #f5f5f5;
        }

        .student-row {
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #262626;
        }

        .form-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: #1890ff;
            box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.2);
        }

        textarea.form-input {
            min-height: 100px;
            resize: vertical;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.45);
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: #fff;
            border-radius: 8px;
            padding: 24px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }

        .modal-header {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #8c8c8c;
        }

        .modal-close:hover {
            color: #262626;
        }

        .button-group {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
        }

        .btn {
            padding: 10px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: #1890ff;
            color: #fff;
        }

        .btn-primary:hover {
            background: #0050b3;
        }

        .btn-secondary {
            background: #f0f0f0;
            color: #262626;
            border: 1px solid #d9d9d9;
        }

        .btn-secondary:hover {
            background: #e6e6e6;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #8c8c8c;
        }

        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .student-info {
            margin-bottom: 12px;
            padding: 8px;
            background: #f5f5f5;
            border-radius: 4px;
        }

        .info-label {
            font-weight: 600;
            color: #262626;
        }

        .info-value {
            color: #8c8c8c;
            margin-left: 8px;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }

            .class-selector {
                flex-direction: column;
            }

            .class-btn {
                width: 100%;
            }

            .students-table {
                font-size: 12px;
            }

            .students-table th,
            .students-table td {
                padding: 8px;
            }

            .modal-content {
                width: 95%;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>

        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>

            <div class="content">
                <!-- 頁面標題 -->
                <div class="panel">
                    <div class="panel-header">
                        <i class="fas fa-graduation-cap"></i>
                        <?php echo htmlspecialchars($page_title); ?>
                    </div>
                    <div class="panel-description">
                        科系：<?php echo htmlspecialchars($teacher_department_name ?: $teacher_department); ?><br>
                        請選擇班級（孝班或忠班）查看畢業生，並填寫大學錄取及成就榮譽資訊
                    </div>
                </div>

                                <?php if (isset($_GET['debug']) && $_GET['debug'] === '1'): ?>
                                    <div class="panel" style="background:#fffbe6;border-left:4px solid #faad14;">
                                        <div class="panel-header"><i class="fas fa-bug"></i> Debug 訊息（僅 debug=1 顯示）</div>
                                        <div class="panel-description" style="font-family: monospace; font-size:13px;">
                                            <div><strong>$dept_col:</strong> <?php echo htmlspecialchars($dept_col ?? ''); ?></div>
                                            <div><strong>$dept_join:</strong> <?php echo htmlspecialchars($dept_join ?? ''); ?></div>
                                            <div><strong>$teacher_department:</strong> <?php echo htmlspecialchars($teacher_department ?? ''); ?></div>
                                            <div><strong>$teacher_department_name:</strong> <?php echo htmlspecialchars($teacher_department_name ?? ''); ?></div>
                                            <div style="margin-top:8px;"><strong>available_classes:</strong></div>
                                            <pre style="background:#fff;padding:8px;border:1px solid #eee;border-radius:6px;max-height:200px;overflow:auto;">
                <?php echo htmlspecialchars(print_r($available_classes ?? [], true)); ?>
                                            </pre>
                                        </div>
                                    </div>
                                <?php endif; ?>

                <!-- 訊息顯示 -->
                <?php if (!empty($success_message)): ?>
                    <div class="message success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error_message)): ?>
                    <div class="message error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <!-- 班級選擇 -->
                <div class="panel">
                    <div class="form-label">選擇班級</div>
                    <div class="class-selector">
                        <?php if (empty($available_classes)): ?>
                            <div style="color: #8c8c8c;">暫無班級資料</div>
                        <?php else: ?>
                            <?php foreach ($available_classes as $class): ?>
                                <button class="class-btn <?php echo $selected_class === $class ? 'active' : ''; ?>"
                                        onclick="selectClass('<?php echo htmlspecialchars(addslashes($class)); ?>')">
                                    <?php echo htmlspecialchars($class); ?>
                                </button>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 學生列表 -->
                <?php if (!empty($selected_class)): ?>
                    <div class="panel">
                        <div class="panel-header">
                            <i class="fas fa-list"></i>
                            <?php echo htmlspecialchars($selected_class); ?> (共 <?php echo $total_students; ?> 人)
                        </div>

                        <?php if ($total_students === 0): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class="fas fa-inbox"></i>
                                </div>
                                <div>此班級暫無學生資料</div>
                            </div>
                        <?php else: ?>
                            <table class="students-table">
                                <thead>
                                    <tr>
                                        <th>學號</th>
                                        <th>姓名</th>
                                        <th>大學</th>
                                        <th>成就榮譽</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($students as $student): ?>
                                        <tr class="student-row">
                                            <td><?php echo htmlspecialchars($student['student_no']); ?></td>
                                            <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                                            <td><?php echo htmlspecialchars($student['university'] ?? '—'); ?></td>
                                            <td>
                                                <div style="max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                    <?php echo htmlspecialchars(substr($student['achievements'] ?? '', 0, 30)); ?>
                                                    <?php if (!empty($student['achievements']) && strlen($student['achievements']) > 30): ?>
                                                        ...
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <button class="btn btn-primary"
                                                        onclick="openEditModal(<?php echo (int)$student['id']; ?>, '<?php echo htmlspecialchars(addslashes($student['student_no'])); ?>', '<?php echo htmlspecialchars(addslashes($student['student_name'])); ?>', '<?php echo htmlspecialchars(addslashes($student['university'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($student['achievements'] ?? '')); ?>')">
                                                    編輯
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 編輯模態窗口 -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span>編輯學生資訊</span>
                <button class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>

            <form id="editForm" method="POST">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="student_id" id="studentId">

                <div class="student-info">
                    <span class="info-label">學號：</span>
                    <span class="info-value" id="studentNoDisplay"></span>
                </div>

                <div class="student-info">
                    <span class="info-label">姓名：</span>
                    <span class="info-value" id="studentNameDisplay"></span>
                </div>

                <div class="form-group">
                    <label class="form-label">大學名稱</label>
                    <input type="text" name="university" id="universityInput" class="form-input"
                           placeholder="例：台灣大學、清華大學">
                </div>

                <div class="form-group">
                    <label class="form-label">成就與榮譽</label>
                    <textarea name="achievements" id="achievementsInput" class="form-input"
                              placeholder="填寫學生在大學期間的成就、獲得的獎項、榮譽等資訊"></textarea>
                </div>

                <div class="button-group">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">取消</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function selectClass(className) {
            const url = new URL(window.location.href);
            url.searchParams.set('class', className);
            window.location.href = url.toString();
        }

        function openEditModal(studentId, studentNo, studentName, university, achievements) {
            document.getElementById('studentId').value = studentId;
            document.getElementById('studentNoDisplay').textContent = studentNo;
            document.getElementById('studentNameDisplay').textContent = studentName;
            document.getElementById('universityInput').value = university;
            document.getElementById('achievementsInput').value = achievements;
            document.getElementById('editModal').classList.add('active');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        // 點擊模態窗口外部時關閉
        document.getElementById('editModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeEditModal();
            }
        });

        // 表單提交後重新載入
        document.getElementById('editForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(html => {
                // 重新載入頁面以顯示更新後的資料
                window.location.reload();
            })
            .catch(error => {
                console.error('Error:', error);
                alert('保存失敗，請重試');
            });
        });
    </script>
</body>
</html>
