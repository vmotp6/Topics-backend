<?php
require_once __DIR__ . '/session_config.php';
checkBackendLogin();

require_once '../../Topics-frontend/frontend/config.php';
require_once __DIR__ . '/includes/continued_admission_auto_assign.php';
require_once __DIR__ . '/includes/continued_admission_ranking.php';

header('Content-Type: application/json; charset=utf-8');

$user_role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

// 記錄用戶角色和ID用於調試
error_log("評分權限檢查 - 用戶ID: {$user_id}, 角色: {$user_role}");

// 老師和主任都可以評分
// 支持多種老師角色代碼：'TE', 'TEA', '老師'
$is_teacher = ($user_role === 'TE' || $user_role === 'TEA' || $user_role === '老師');
$is_director = ($user_role === 'DI');

// 如果角色檢查失敗，記錄詳細信息
if (!$is_teacher && !$is_director) {
    error_log("權限不足 - 用戶ID: {$user_id}, 角色: {$user_role}, 不是老師也不是主任");
    echo json_encode([
        'success' => false, 
        'message' => '權限不足：只有老師和主任可以評分（當前角色：' . $user_role . '）'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

error_log("權限檢查通過 - 用戶ID: {$user_id}, 角色: {$user_role}, 是老師: " . ($is_teacher ? '是' : '否') . ", 是主任: " . ($is_director ? '是' : '否'));

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
    // 先查詢所有該用戶的分配記錄，找出對應的 assignment_order
    $assignment_check = $conn->prepare("SELECT reviewer_type, assignment_order 
        FROM continued_admission_assignments 
        WHERE application_id = ? AND reviewer_user_id = ?
        ORDER BY assignment_order ASC");
    $assignment_check->bind_param("ii", $application_id, $user_id);
    $assignment_check->execute();
    $assignment_result = $assignment_check->get_result();
    $assignments = [];
    while ($row = $assignment_result->fetch_assoc()) {
        $assignments[] = $row;
    }
    $assignment_check->close();
    
    // 查找匹配的分配記錄
    $assignment = null;
    foreach ($assignments as $assign) {
        if ($assign['assignment_order'] == $assignment_order) {
            $assignment = $assign;
            break;
        }
    }
    
    error_log("分配檢查 - 報名ID: {$application_id}, 用戶ID: {$user_id}, 請求slot: {$assignment_order}, 找到分配記錄數: " . count($assignments));
    if (count($assignments) > 0) {
        $found_orders = array_column($assignments, 'assignment_order');
        error_log("找到的 assignment_order: " . implode(', ', $found_orders));
    }
    
    if (!$assignment) {
        // 如果找不到對應的 assignment_order，檢查是否有其他分配
        if (count($assignments) > 0) {
            $found_order = $assignments[0]['assignment_order'];
            error_log("找到其他分配記錄，assignment_order: {$found_order}");
            $conn->close();
            echo json_encode([
                'success' => false, 
                'message' => '權限不足：您被分配為 slot ' . $found_order . '，但請求的是 slot ' . $assignment_order . '。請使用正確的評分連結。'
            ], JSON_UNESCAPED_UNICODE);
        } else {
            error_log("用戶未被分配為評審者 - 報名ID: {$application_id}, 用戶ID: {$user_id}");
            $conn->close();
            echo json_encode([
                'success' => false, 
                'message' => '權限不足：您未被分配為此學生的評審者（報名ID: ' . $application_id . ', 用戶ID: ' . $user_id . '）'
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
    
    // 驗證評審者類型是否匹配（放寬檢查，只要被分配就可以評分）
    $expected_type = ($assignment_order == 3) ? 'director' : 'teacher';
    if ($assignment['reviewer_type'] !== $expected_type) {
        error_log("評審者類型不匹配 - 期望: {$expected_type}, 實際: {$assignment['reviewer_type']}, 但允許繼續（因為已被分配）");
        // 不阻止，因為用戶已被分配，可能是類型標記錯誤
    }
    
    error_log("分配驗證通過 - 報名ID: {$application_id}, 用戶ID: {$user_id}, slot: {$assignment_order}, 類型: {$assignment['reviewer_type']}");
    
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
    
    // 檢查是否已經評分過（如果已評分，不允許修改）
    $existing_score_check = $conn->prepare("SELECT self_intro_score, skills_score, scored_at 
        FROM continued_admission_scores 
        WHERE application_id = ? AND reviewer_user_id = ? AND assignment_order = ?");
    $existing_score_check->bind_param("iii", $application_id, $user_id, $assignment_order);
    $existing_score_check->execute();
    $existing_score_result = $existing_score_check->get_result();
    $existing_score = $existing_score_result->fetch_assoc();
    $existing_score_check->close();
    
    // 檢查是否已評分（兩個分數都存在且不為空）
    $has_existing_score = false;
    if ($existing_score) {
        $has_self_intro = isset($existing_score['self_intro_score']) && 
                         $existing_score['self_intro_score'] !== null && 
                         $existing_score['self_intro_score'] !== '' &&
                         trim((string)$existing_score['self_intro_score']) !== '';
        $has_skills = isset($existing_score['skills_score']) && 
                     $existing_score['skills_score'] !== null && 
                     $existing_score['skills_score'] !== '' &&
                     trim((string)$existing_score['skills_score']) !== '';
        $has_existing_score = $has_self_intro && $has_skills;
        
        error_log("檢查已評分狀態 - 報名ID: {$application_id}, 用戶ID: {$user_id}, slot: {$assignment_order}, 自傳: '{$existing_score['self_intro_score']}', 專長: '{$existing_score['skills_score']}', 已評分: " . ($has_existing_score ? '是' : '否'));
    }
    
    // 如果已評分，不允許修改（無論是否還在評分期間內）
    if ($has_existing_score) {
        // 只有管理員或行政人員可以修改已評分的記錄
        $is_admin = in_array($user_role, ['ADM', 'STA']);
        
        if (!$is_admin) {
            // 一般用戶不能修改已評分的記錄
            $conn->close();
            echo json_encode([
                'success' => false, 
                'message' => '評分已完成，無法再修改。評分時間：' . date('Y-m-d H:i:s', strtotime($existing_score['scored_at']))
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } else {
            // 管理員可以修改（用於特殊情況）
            error_log("管理員修改已評分記錄 - 報名ID: {$application_id}, 用戶ID: {$user_id}, slot: {$assignment_order}");
        }
    }
    
    // 檢查是否在審查時間內（根據志願序）
    $time_check = checkScoreTimeByChoice($conn, $application_id);
    
    // 如果是管理員或行政人員，允許跳過時間檢查（用於測試和特殊情況）
    $is_admin = in_array($user_role, ['ADM', 'STA']);
    
    // 暫時放寬時間檢查：如果當前時間在審查開始時間之後，即使超過截止時間也允許評分
    // 或者如果是管理員/行政人員，完全跳過時間檢查
    $allow_score = false;
    if ($time_check['is_within_period']) {
        $allow_score = true;
        error_log("時間檢查通過：在評分期間內");
    } elseif ($is_admin) {
        $allow_score = true;
        error_log("管理員跳過時間檢查進行評分：報名ID={$application_id}, 用戶ID={$user_id}, 時間檢查訊息=" . $time_check['message']);
    } else {
        // 檢查是否在審查開始時間之後（即使超過截止時間，也允許評分）
        $app_dept_stmt = $conn->prepare("SELECT assigned_department FROM continued_admission WHERE id = ?");
        $app_dept_stmt->bind_param("i", $application_id);
        $app_dept_stmt->execute();
        $app_dept_result = $app_dept_stmt->get_result();
        $app_dept_data = $app_dept_result->fetch_assoc();
        $app_dept_stmt->close();
        
        if ($app_dept_data && !empty($app_dept_data['assigned_department'])) {
            $dept_code = $app_dept_data['assigned_department'];
            $time_stmt = $conn->prepare("SELECT review_start FROM department_quotas WHERE department_code = ? AND is_active = 1 LIMIT 1");
            $time_stmt->bind_param("s", $dept_code);
            $time_stmt->execute();
            $time_result = $time_stmt->get_result();
            if ($time_row = $time_result->fetch_assoc()) {
                $review_start = $time_row['review_start'];
                if ($review_start) {
                    $review_start_timestamp = strtotime($review_start);
                    $current_time = time();
                    if ($current_time >= $review_start_timestamp) {
                        // 在審查開始時間之後，允許評分（即使超過截止時間）
                        $allow_score = true;
                        error_log("允許評分（在審查開始時間之後）：報名ID={$application_id}, 當前時間=" . date('Y-m-d H:i:s', $current_time) . ", 開始時間=" . $review_start);
                    } else {
                        error_log("不允許評分（早於審查開始時間）：報名ID={$application_id}, 當前時間=" . date('Y-m-d H:i:s', $current_time) . ", 開始時間=" . $review_start);
                    }
                } else {
                    // 沒有設定審查開始時間，允許評分
                    $allow_score = true;
                    error_log("允許評分（未設定審查開始時間）：報名ID={$application_id}");
                }
            } else {
                // 找不到科系設定，允許評分（向後兼容）
                $allow_score = true;
                error_log("允許評分（找不到科系設定）：報名ID={$application_id}");
            }
            $time_stmt->close();
        } else {
            // 沒有分配科系，允許評分（向後兼容）
            $allow_score = true;
            error_log("允許評分（未分配科系）：報名ID={$application_id}");
        }
    }
    
    if (!$allow_score) {
        $conn->close();
        echo json_encode([
            'success' => false,
            'message' => $time_check['message'] . '（當前時間：' . date('Y-m-d H:i:s') . '）'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 使用 INSERT ... ON DUPLICATE KEY UPDATE 來插入或更新評分
    // 如果記錄已存在但分數為空，允許更新；如果已評分，則不更新（因為已經檢查過）
    $reviewer_type = ($assignment_order == 3) ? 'director' : 'teacher';
    
    // 先嘗試刪除可能存在的空記錄或舊記錄（如果允許修改）
    if ($existing_score && !$has_existing_score) {
        $delete_stmt = $conn->prepare("DELETE FROM continued_admission_scores 
            WHERE application_id = ? AND reviewer_user_id = ? AND assignment_order = ?");
        $delete_stmt->bind_param("iii", $application_id, $user_id, $assignment_order);
        $delete_result = $delete_stmt->execute();
        $delete_stmt->close();
        error_log("刪除空的評分記錄 - 報名ID: {$application_id}, 用戶ID: {$user_id}, slot: {$assignment_order}, 結果: " . ($delete_result ? '成功' : '失敗'));
    }
    
    // 使用 REPLACE INTO 或先 DELETE 再 INSERT 來確保評分被保存
    // 先刪除可能存在的記錄
    $delete_old_stmt = $conn->prepare("DELETE FROM continued_admission_scores 
        WHERE application_id = ? AND reviewer_user_id = ? AND assignment_order = ?");
    $delete_old_stmt->bind_param("iii", $application_id, $user_id, $assignment_order);
    $delete_old_stmt->execute();
    $delete_old_stmt->close();
    
    // 然後插入新記錄
    $insert_stmt = $conn->prepare("INSERT INTO continued_admission_scores 
        (application_id, reviewer_user_id, reviewer_type, assignment_order, self_intro_score, skills_score, scored_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())");
    
    $insert_stmt->bind_param("iisiii", $application_id, $user_id, $reviewer_type, $assignment_order, $self_intro_score, $skills_score);
    
    error_log("準備插入評分 - 報名ID: {$application_id}, 用戶ID: {$user_id}, slot: {$assignment_order}, 自傳: {$self_intro_score}, 專長: {$skills_score}");
    
    if ($insert_stmt->execute()) {
        $affected_rows = $conn->affected_rows;
        $insert_id = $conn->insert_id;
        error_log("評分插入成功 - 影響行數: {$affected_rows}, 插入ID: {$insert_id}, 報名ID: {$application_id}, 用戶ID: {$user_id}, slot: {$assignment_order}, 自傳: {$self_intro_score}, 專長: {$skills_score}");
        
        // 驗證評分是否真的保存成功
        $verify_stmt = $conn->prepare("SELECT self_intro_score, skills_score, scored_at 
            FROM continued_admission_scores 
            WHERE application_id = ? AND reviewer_user_id = ? AND assignment_order = ?");
        $verify_stmt->bind_param("iii", $application_id, $user_id, $assignment_order);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        $verify_data = $verify_result->fetch_assoc();
        $verify_stmt->close();
        
        if ($verify_data) {
            error_log("驗證評分保存成功 - 自傳: {$verify_data['self_intro_score']}, 專長: {$verify_data['skills_score']}, 時間: {$verify_data['scored_at']}");
        } else {
            error_log("警告：評分插入後驗證失敗，找不到記錄！");
        }
        
        // 評分成功後，檢查是否所有評審都已完成評分
        // 如果完成，檢查是否未達標，如果未達標則自動分配到下一志願
        $score_info = calculateAverageScore($conn, $application_id);
        
        // 獲取分配數量
        $assign_count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM continued_admission_assignments WHERE application_id = ?");
        $assign_count_stmt->bind_param("i", $application_id);
        $assign_count_stmt->execute();
        $assign_count_result = $assign_count_stmt->get_result();
        $assign_count_data = $assign_count_result->fetch_assoc();
        $assign_count_stmt->close();
        
        $total_assignments = (int)($assign_count_data['total'] ?? 0);
        $completed_reviews = $score_info['reviewer_count'];
        
        // 如果所有評審都已完成評分
        if ($total_assignments > 0 && $completed_reviews >= $total_assignments) {
            // 獲取當前分配的科系
            $app_dept_stmt = $conn->prepare("SELECT assigned_department FROM continued_admission WHERE id = ?");
            $app_dept_stmt->bind_param("i", $application_id);
            $app_dept_stmt->execute();
            $app_dept_result = $app_dept_stmt->get_result();
            $app_dept_data = $app_dept_result->fetch_assoc();
            $app_dept_stmt->close();
            
            if ($app_dept_data && !empty($app_dept_data['assigned_department'])) {
                $current_dept = $app_dept_data['assigned_department'];
                
                // 檢查是否未達標
                $fail_check = checkScoreFailed($conn, $application_id, $current_dept);
                
                if ($fail_check['is_failed']) {
                    // 獲取當前志願序
                    $current_choice_order = getCurrentChoiceOrder($conn, $application_id);
                    
                    if ($current_choice_order) {
                        // 自動分配到下一志願
                        $auto_assign_result = autoAssignToNextChoice($conn, $application_id, $current_choice_order);
                        
                        if ($auto_assign_result['success']) {
                            $conn->close();
                            echo json_encode([
                                'success' => true,
                                'message' => '評分成功。' . $fail_check['reason'] . '，已自動分配到下一志願',
                                'auto_assigned' => true,
                                'new_department' => $auto_assign_result['new_department']
                            ], JSON_UNESCAPED_UNICODE);
                            exit;
                        } else {
                            // 自動分配失敗，但評分已成功
                            $conn->close();
                            echo json_encode([
                                'success' => true,
                                'message' => '評分成功。' . $fail_check['reason'] . '，但自動分配到下一志願失敗：' . $auto_assign_result['message'],
                                'auto_assigned' => false
                            ], JSON_UNESCAPED_UNICODE);
                            exit;
                        }
                    }
                } else {
                    // 評分達標，處理該科系的所有報名排名和錄取狀態
                    try {
                        $ranking_result = processAdmissionRanking($conn, $current_dept);
                        
                        // 獲取當前報名的錄取狀態
                        $status_stmt = $conn->prepare("SELECT status, admission_rank FROM continued_admission WHERE id = ?");
                        $status_stmt->bind_param("i", $application_id);
                        $status_stmt->execute();
                        $status_result = $status_stmt->get_result();
                        $status_data = $status_result->fetch_assoc();
                        $status_stmt->close();
                        
                        $final_status = $status_data['status'] ?? '';
                        $final_rank = $status_data['admission_rank'] ?? null;
                        
                        $status_message = '';
                        if ($final_status === 'approved' && $final_rank !== null) {
                            $status_message = "，已設定為正取第{$final_rank}號";
                        } elseif ($final_status === 'waitlist' && $final_rank !== null) {
                            // 備取編號就是 admission_rank（已經從1開始）
                            $status_message = "，已設定為備取第{$final_rank}號";
                        } elseif ($final_status === 'rejected') {
                            $status_message = "，未達錄取標準，不錄取";
                        } else {
                            $status_message = "，排名處理完成";
                        }
                        
                        $conn->close();
                        echo json_encode([
                            'success' => true,
                            'message' => '評分成功。所有評審已完成評分' . $status_message,
                            'ranking_processed' => true,
                            'status' => $final_status,
                            'rank' => $final_rank
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                        
                    } catch (Exception $e) {
                        error_log("處理排名時發生錯誤: " . $e->getMessage());
                        // 排名處理失敗不影響評分成功
                    }
                }
            }
        }
        
        $conn->close();
        echo json_encode([
            'success' => true,
            'message' => '評分成功'
        ], JSON_UNESCAPED_UNICODE);
    } else {
        // 檢查錯誤原因
        $error_msg = $conn->error;
        $error_code = $conn->errno;
        error_log("評分插入失敗 - 報名ID: {$application_id}, 用戶ID: {$user_id}, slot: {$assignment_order}, 錯誤: {$error_msg} (錯誤碼: {$error_code})");
        
        if (strpos($error_msg, 'Duplicate entry') !== false || $error_code == 1062) {
            // 如果是重複鍵錯誤，嘗試使用 UPDATE
            error_log("檢測到重複鍵錯誤，嘗試使用 UPDATE 更新評分");
            $update_stmt = $conn->prepare("UPDATE continued_admission_scores 
                SET self_intro_score = ?, skills_score = ?, scored_at = NOW()
                WHERE application_id = ? AND reviewer_user_id = ? AND assignment_order = ?");
            $update_stmt->bind_param("iiiii", $self_intro_score, $skills_score, $application_id, $user_id, $assignment_order);
            
            if ($update_stmt->execute()) {
                $affected_rows = $conn->affected_rows;
                error_log("使用 UPDATE 更新評分成功 - 影響行數: {$affected_rows}");
                $update_stmt->close();
                // 繼續執行後續邏輯（檢查排名等），但需要跳過 INSERT 成功後的代碼
                // 由於結構限制，我們在這裡直接返回成功
                $conn->close();
                echo json_encode([
                    'success' => true,
                    'message' => '評分已更新成功'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            } else {
                $update_error = $update_stmt->error;
                $update_stmt->close();
                $conn->close();
                error_log("UPDATE 也失敗 - 錯誤: {$update_error}");
                echo json_encode([
                    'success' => false,
                    'message' => '評分失敗：無法更新評分記錄 - ' . $update_error
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            $conn->close();
            echo json_encode([
                'success' => false,
                'message' => '評分失敗：' . $error_msg . ' (錯誤碼: ' . $error_code . ')'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
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

