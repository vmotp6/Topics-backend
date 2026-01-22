<?php
require_once __DIR__ . '/session_config.php';
checkBackendLogin();

require_once '../../Topics-frontend/frontend/config.php';

header('Content-Type: application/json; charset=utf-8');

$user_role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

// 老師和主任都可以評分
$is_teacher = ($user_role === 'TE' || $user_role === '老師');
$is_director = ($user_role === 'DI');

if (!$is_teacher && !$is_director) {
    echo json_encode(['success' => false, 'message' => '權限不足'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$application_id = isset($data['application_id']) ? (int)$data['application_id'] : 0;
$teacher_slot = isset($data['teacher_slot']) ? $data['teacher_slot'] : '';
$self_intro_score = isset($data['self_intro_score']) ? (int)$data['self_intro_score'] : null;
$skills_score = isset($data['skills_score']) ? (int)$data['skills_score'] : null;

if ($application_id === 0 || empty($teacher_slot)) {
    echo json_encode(['success' => false, 'message' => '參數錯誤'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 支持 1, 2, 3 (1=第一位老師, 2=第二位老師, 3=主任)
// 處理字符串或數字格式
if (is_string($teacher_slot)) {
    if ($teacher_slot === 'director') {
        $assignment_order = 3;
    } else {
        $assignment_order = (int)$teacher_slot;
    }
} else {
    $assignment_order = (int)$teacher_slot;
}

if ($assignment_order < 1 || $assignment_order > 3) {
    echo json_encode(['success' => false, 'message' => '無效的評分位置（必須是 1、2 或 3）'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($self_intro_score === null || $skills_score === null) {
    echo json_encode(['success' => false, 'message' => '請填寫所有分數'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($self_intro_score < 0 || $self_intro_score > 80) {
    echo json_encode(['success' => false, 'message' => '自傳分數必須在 0-80 之間'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($skills_score < 0 || $skills_score > 20) {
    echo json_encode(['success' => false, 'message' => '興趣/專長分數必須在 0-20 之間'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = getDatabaseConnection();
    
    // 檢查正規化分配表是否存在
    $table_check = $conn->query("SHOW TABLES LIKE 'continued_admission_assignments'");
    if (!$table_check || $table_check->num_rows == 0) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => '資料表不存在，請先執行正規化 SQL 腳本'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 檢查報名記錄是否存在
    $check_stmt = $conn->prepare("SELECT id, assigned_department FROM continued_admission WHERE id = ?");
    $check_stmt->bind_param("i", $application_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $application = $check_result->fetch_assoc();
    $check_stmt->close();
    
    if (!$application) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => '找不到報名記錄'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 檢查該用戶是否被分配為評審者
    $assignment_check = $conn->prepare("SELECT reviewer_type, assignment_order 
        FROM continued_admission_assignments 
        WHERE application_id = ? AND reviewer_user_id = ? AND assignment_order = ?");
    $assignment_check->bind_param("iii", $application_id, $user_id, $assignment_order);
    $assignment_check->execute();
    $assignment_result = $assignment_check->get_result();
    $assignment = $assignment_result->fetch_assoc();
    $assignment_check->close();
    
    if (!$assignment) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => '權限不足：您未被分配為此學生的評審者'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 驗證評審者類型是否匹配
    $expected_type = ($assignment_order == 3) ? 'director' : 'teacher';
    if ($assignment['reviewer_type'] !== $expected_type) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => '權限驗證失敗：評審者類型不匹配'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 如果是主任，額外驗證科系
    if ($is_director && $assignment_order == 3) {
        $assigned_dept = $application['assigned_department'] ?? '';
        $director_dept_stmt = $conn->prepare("SELECT department FROM director WHERE user_id = ? LIMIT 1");
        if (!$director_dept_stmt) {
            $director_dept_stmt = $conn->prepare("SELECT department FROM teacher WHERE user_id = ? LIMIT 1");
        }
        $director_dept_stmt->bind_param("i", $user_id);
        $director_dept_stmt->execute();
        $director_dept_result = $director_dept_stmt->get_result();
        $director_dept = $director_dept_result->fetch_assoc();
        $director_dept_stmt->close();
        
        if (!$director_dept || $assigned_dept !== $director_dept['department']) {
            $conn->close();
            echo json_encode(['success' => false, 'message' => '權限不足：只能評分自己科系的學生'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    // 檢查正規化評分表是否存在
    $score_table_check = $conn->query("SHOW TABLES LIKE 'continued_admission_scores'");
    if (!$score_table_check || $score_table_check->num_rows == 0) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => '評分表不存在，請先執行正規化 SQL 腳本'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 使用 INSERT ... ON DUPLICATE KEY UPDATE 來插入或更新評分
    $reviewer_type = ($assignment_order == 3) ? 'director' : 'teacher';
    
    $insert_stmt = $conn->prepare("INSERT INTO continued_admission_scores 
        (application_id, reviewer_user_id, reviewer_type, assignment_order, self_intro_score, skills_score, scored_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE 
            self_intro_score = VALUES(self_intro_score),
            skills_score = VALUES(skills_score),
            scored_at = NOW()");
    
    $insert_stmt->bind_param("iisiii", $application_id, $user_id, $reviewer_type, $assignment_order, $self_intro_score, $skills_score);
    
    if ($insert_stmt->execute()) {
        $conn->close();
        echo json_encode([
            'success' => true,
            'message' => '評分成功'
        ], JSON_UNESCAPED_UNICODE);
    } else {
        $conn->close();
        echo json_encode([
            'success' => false,
            'message' => '評分失敗：' . $conn->error
        ], JSON_UNESCAPED_UNICODE);
    }
    
    $insert_stmt->close();
    
} catch (Exception $e) {
    error_log('submit_continued_admission_score.php error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '系統錯誤：' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>

