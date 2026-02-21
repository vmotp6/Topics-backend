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
    '主任' => 'DI',
    'di' => 'DI',
    'DI' => 'DI',
];
$normalized_role = $role_map[$session_role] ?? $session_role;

$is_teacher = ($normalized_role === 'TEA');
$is_director = ($normalized_role === 'DI');

if (!($is_teacher || $is_director)) {
    http_response_code(403);
    echo '權限不足：僅教師(TEA)或主任(DI)可使用此功能。';
    exit;
}

$page_title = '畢業生資訊填寫';
$error_message = '';
$success_message = '';
$achievement_options = [
    'COM' => '競賽類',
    'CER' => '證照類',
    'HON' => '校內榮譽',
    'SP' => '體育競賽',
    'SK' => '技能檢定',
    'OT' => '其他'
];

// 初始化變數（實際值稍後於 try 區段取得）
$teacher_department = '';
$teacher_department_name = '';
$graduated_by_dept = [];
$university_list = [];
$achievement_list = [];

$achievements = isset($_POST['achievements'])
    ? implode(',', $_POST['achievements'])
    : NULL;

$achievement_note = $_POST['achievement_note'] ?? NULL;

try {   
    $conn = getDatabaseConnection();
    if (!$conn) throw new Exception('資料庫連接失敗');

    // 撈大學類型
    $university_list = [];
    $res = $conn->query("SELECT type_code as code, type_name as name FROM university_types ORDER BY id ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $university_list[] = $row;
        }
    }

    // 撈成就類型
    $achievement_list = [];
    $res = $conn->query("SELECT type_code as code, type_name as name FROM achievement_types ORDER BY id ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $achievement_list[] = $row;
        }
    }

    // 確保相關資料表存在
    ensureTablesExist($conn);
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
    // ===== 取得使用者科系（老師 / 主任分流）=====
    if ($is_teacher) {
        if (empty($teacher_department)) {
            throw new Exception('無法取得您的科系資訊，請聯絡管理員。');
        }
    } elseif ($is_director) {
        // 主任允許沒有 teacher.department
        // 科系會在 send_to_center 時再取 director / teacher fallback
        $teacher_department = ''; 
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
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['action']) && $_POST['action'] === 'save') {
            try {
                // 僅允許教師執行儲存動作，主任為唯讀
                if (!$is_teacher) {
                    $error_message = '您沒有編輯權限（僅教師可編輯）。';
                } else {
                    $student_id = (int)($_POST['student_id'] ?? 0);
                    $university = trim($_POST['university'] ?? '');
                    // 將多選的成就分別存入三個欄位，並重新索引以避免鍵值不連續
                    $raw_achievements = $_POST['achievements'] ?? [];
                    $achievements_array = array_values(array_filter(array_map('trim', (array)$raw_achievements)));
                    $achievements = $achievements_array[0] ?? null;      // 第一個成就
                    $achievements1 = $achievements_array[1] ?? null;     // 第二個成就
                    $achievements2 = $achievements_array[2] ?? null;     // 第三個成就
                    $achievement_note = trim($_POST['achievement_note'] ?? '');

                    // 調試：記錄接收到的數據（包含三個成就欄位）
                    error_log("DEBUG: 接收到的數據 - student_id={$student_id}, university={$university}, achievements={$achievements}, achievements1={$achievements1}, achievements2={$achievements2}, achievement_note={$achievement_note}");

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
                                           SET university = ?, achievements = ?, achievements1 = ?, achievements2 = ?, achievement_note = ?
                                           WHERE id = ?";
                            $stmt = $conn->prepare($update_sql);
                            if (!$stmt) throw new Exception('SQL準備失敗: ' . $conn->error);

                            error_log("DEBUG: 執行更新SQL - UPDATE new_student_basic_info SET university=?, achievements=?, achievements1=?, achievements2=?, achievement_note=? WHERE id=$student_id");
                            
                            $stmt->bind_param('sssssi', $university, $achievements, $achievements1, $achievements2, $achievement_note, $student_id);
                            if ($stmt->execute()) {
                                error_log("DEBUG: 更新成功，受影響行數=" . $stmt->affected_rows);
                                $success_message = '已成功保存學生資料';
                            } else {
                                error_log("DEBUG: 更新失敗，錯誤=" . $stmt->error);
                                $error_message = '保存失敗: ' . $stmt->error;
                            }
                            $stmt->close();
                        }
                    }
                }
            } catch (Exception $e) {
                $error_message = '保存出錯: ' . $e->getMessage();
            }
        }


        // 主任發起寄送至招生中心（由主任執行）
        if (isset($_POST['action']) && $_POST['action'] === 'send_to_center' && $is_director) {
            try {
                // 取得主任所屬科系（優先 director 表）
                $director_dept = '';
                $dchk = $conn->query("SHOW TABLES LIKE 'director'");
                if ($dchk && $dchk->num_rows > 0) {
                    $dq = $conn->prepare("SELECT department FROM director WHERE user_id = ? LIMIT 1");
                    if ($dq) {
                        $dq->bind_param('i', $session_user_id);
                        $dq->execute();
                        $dr = $dq->get_result();
                        if ($dr && ($drw = $dr->fetch_assoc())) $director_dept = trim((string)$drw['department']);
                        $dq->close();
                    }
                }
                if ($director_dept === '') {
                    // fallback to teacher table
                    $tq = $conn->prepare("SELECT t.department FROM teacher t WHERE t.user_id = ? LIMIT 1");
                    if ($tq) {
                        $tq->bind_param('i', $session_user_id);
                        $tq->execute();
                        $tr = $tq->get_result();
                        if ($tr && ($trw = $tr->fetch_assoc())) $director_dept = trim((string)$trw['department']);
                        $tq->close();
                    }
                }
                if ($director_dept === '') throw new Exception('無法取得您的科系，無法寄送');

                // 確保有 submission 記錄且兩班皆已提交（從資料表檢查）
                $grad_year_west = (int)date('Y');
                $roc_enroll_year = $grad_year_west - 1916;
                $year_range = getAcademicYearRangeByRoc($roc_enroll_year);
                $graduation_roc_year = $roc_enroll_year + 5;
                
                // 檢查資料表中孝班和忠班是否都已提交
                $chk = $conn->prepare("SELECT class_name FROM graduated_class_submissions WHERE graduation_roc_year = ? AND department_code = ? AND class_name IN ('孝班', '忠班') GROUP BY class_name");
                $submitted_classes = [];
                if ($chk) {
                    $chk->bind_param('is', $graduation_roc_year, $director_dept);
                    $chk->execute(); $cr = $chk->get_result();
                    while ($r = $cr->fetch_assoc()) $submitted_classes[] = $r['class_name'];
                    $chk->close();
                }
                
                // 檢查必須兩班都存在
                $has_xiao = in_array('孝班', $submitted_classes, true);
                $has_zhong = in_array('忠班', $submitted_classes, true);
                if (!($has_xiao && $has_zhong)) {
                    if (!$has_xiao && !$has_zhong) {
                        throw new Exception('資料表中未見任何班級提交記錄');
                    } elseif ($has_xiao && !$has_zhong) {
                        throw new Exception('孝班已提交，但忠班尚未提交，無法寄送');
                    } else {
                        throw new Exception('忠班已提交，但孝班尚未提交，無法寄送');
                    }
                }

                // 檢查是否已寄送過
                ensureGraduatedDirectorEmailLogTable($conn);
                $chk2 = $conn->prepare("SELECT COUNT(*) AS cnt FROM graduated_director_email_log WHERE graduation_roc_year = ? AND department_code = ?");
                if ($chk2) {
                    $chk2->bind_param('is', $graduation_roc_year, $director_dept);
                    $chk2->execute(); $r2 = $chk2->get_result();
                    $already_sent = false;
                    if ($r2 && ($rw2 = $r2->fetch_assoc())) $already_sent = ((int)$rw2['cnt'] > 0);
                    $chk2->close();
                    if ($already_sent) throw new Exception('本年度已由主任寄送過給招生中心，若要再次寄出請先在資料庫移除紀錄或聯絡管理員。');
                }

                // 取得學生資料（孝班與忠班）
                $kw1 = '%孝%'; $kw2 = '%忠%';
$students_sql = "
SELECT 
    s.id,
    s.student_no,
    s.student_name,
    s.class_name,
    s.university,
    u.type_name AS university_name,
    s.achievements,
    s.achievement_note,
    s.created_at
FROM new_student_basic_info s
LEFT JOIN university_types u
    ON TRIM(UPPER(s.university)) = TRIM(UPPER(u.type_code))
" . $dept_join . "
WHERE (s.`$dept_col` = ?" . ($dept_join !== '' ? " OR COALESCE(d.name,'') = ?" : "") . ")
  AND (s.class_name LIKE ? OR s.class_name LIKE ?)
  AND s.created_at >= ?
  AND s.created_at <= ?
ORDER BY s.student_no ASC
";

$stmt2 = $conn->prepare($students_sql);
if ($stmt2) {
    if ($dept_join !== '') {
        $stmt2->bind_param(
            'ssssss',
            $director_dept,
            $director_dept,
            $kw1,
            $kw2,
            $year_range['start'],
            $year_range['end']
        );
    } else {
        $stmt2->bind_param(
            'sssss',
            $director_dept,
            $kw1,
            $kw2,
            $year_range['start'],
            $year_range['end']
        );
    }

    $stmt2->execute();
    $res2 = $stmt2->get_result();
    $rows = [];
    while ($rr = $res2->fetch_assoc()) {
        $achievement_text = convertAchievementsToText($rr['achievements'] ?? '', $achievement_options);
        $rows[] = [
            $rr['student_no'] ?? '',
            $rr['student_name'] ?? '',
            $rr['class_name'] ?? '',
            $rr['university_name'] ?? $rr['university'] ?? '—', // <- 正確顯示中文名稱
            $achievement_text
        ];
    }
    $stmt2->close();
}

                if (empty($rows)) throw new Exception('查無學生資料，無法產生 Excel');

                // 產生 xlsx
                $excel_rows = array_merge([['學號','姓名','班級','大學','成就']], $rows);
                $tmp = tempnam(sys_get_temp_dir(), 'grad_send_');
                if ($tmp === false) throw new Exception('無法建立暫存檔');
                $excel_path = $tmp . '.xlsx'; @rename($tmp, $excel_path);
                if (!build_graduated_xlsx_custom($excel_rows, $excel_path)) throw new Exception('產生 Excel 失敗');

                // 找出招生中心收件者（多個備援搜尋方式）
                $center_emails = [];
                
                // 方案1: 搜尋 user 表的 STA/STAM 角色
                if (hasColumn($conn, 'user', 'email') && hasColumn($conn, 'user', 'role')) {
                    $qe = $conn->prepare("SELECT email FROM user WHERE (role IN ('STA','STAM') OR role LIKE '%STA%') AND email <> '' AND email IS NOT NULL");
                    if ($qe) { 
                        $qe->execute(); 
                        $rqe = $qe->get_result(); 
                        while ($re = $rqe->fetch_assoc()) { 
                            $email = trim((string)($re['email'] ?? ''));
                            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                $center_emails[] = $email;
                            }
                        } 
                        $qe->close(); 
                    }
                }
                
                // 方案2: 若無收件者，嘗試搜尋 admissions_email 或相似欄位
                if (empty($center_emails) && hasColumn($conn, 'user', 'admissions_email')) {
                    $qe2 = $conn->prepare("SELECT admissions_email FROM user WHERE admissions_email <> '' AND admissions_email IS NOT NULL LIMIT 5");
                    if ($qe2) { 
                        $qe2->execute(); 
                        $rqe2 = $qe2->get_result(); 
                        while ($re2 = $rqe2->fetch_assoc()) { 
                            $email = trim((string)($re2['admissions_email'] ?? ''));
                            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                $center_emails[] = $email;
                            }
                        } 
                        $qe2->close(); 
                    }
                }
                
                // 方案3: 若仍無收件者，試試 admin 表或其他職務表
                if (empty($center_emails) && hasColumn($conn, 'admin', 'email')) {
                    $qe3 = $conn->prepare("SELECT email FROM admin WHERE email <> '' AND email IS NOT NULL LIMIT 5");
                    if ($qe3) { 
                        $qe3->execute(); 
                        $rqe3 = $qe3->get_result(); 
                        while ($re3 = $rqe3->fetch_assoc()) { 
                            $email = trim((string)($re3['email'] ?? ''));
                            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                $center_emails[] = $email;
                            }
                        } 
                        $qe3->close(); 
                    }
                }

                // 移除重複
                $center_emails = array_unique($center_emails);

                // 載入郵件函數
                $email_loaded = false;
                $email_paths = [__DIR__ . '/includes/email_functions.php', dirname(__DIR__) . '/../Topics-frontend/frontend/includes/email_functions.php', __DIR__ . '/../../Topics-frontend/frontend/includes/email_functions.php'];
                foreach ($email_paths as $ep) { if (file_exists($ep)) { require_once $ep; $email_loaded = function_exists('sendEmail'); break; } }

                $subject = '【畢業資料】' . ($teacher_department_name ?: $director_dept) . ' - 孝班與忠班';
                $body = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><p>科系：' . htmlspecialchars($teacher_department_name ?: $director_dept) . '</p><p>主任已將孝班與忠班之畢業資料彙整（附件 Excel）。</p><p>此信由系統自動發送，請勿直接回覆。</p></body></html>';
                $altBody = '主任已將畢業資料彙整，詳見附件。';
                $attachments = [['path'=>$excel_path,'name'=>'畢業資料_' . preg_replace('/[^\p{L}\p{N}\-_]/u','_',$director_dept) . '_' . date('Ymd') . '.xlsx','mime'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']];

                $sent = 0;
                $send_errors = [];
                
                if (!$email_loaded) {
                    $send_errors[] = '【警告】郵件函式未載入 sendEmail()';
                }
                
                if (empty($center_emails)) {
                    $send_errors[] = '【警告】未找到招生中心收件者（應搜尋 STA/STAM 角色或 admin 表）';
                } else {
                    foreach (array_unique($center_emails) as $to) {
                        if ($to === '') continue;
                        try {
                            $ok = @sendEmail($to, $subject, $body, $altBody, $attachments);
                            if ($ok) {
                                $sent++;
                            } else {
                                $send_errors[] = "寄送至 {$to} 失敗（sendEmail 回傳 false）";
                            }
                        } catch (Exception $sendEx) {
                            $send_errors[] = "寄送至 {$to} 發生例外: " . $sendEx->getMessage();
                        }
                    }
                }

                if (file_exists($excel_path)) @unlink($excel_path);

                if ($sent > 0) {
                    // 寄送成功時，立即紀錄已寄送到資料表（防重複寄送）
                    ensureGraduatedDirectorEmailLogTable($conn);
                    $ins = $conn->prepare("INSERT INTO graduated_director_email_log (graduation_roc_year, department_code, sent_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE sent_at = NOW()");
                    if ($ins) { 
                        $ins->bind_param('is', $graduation_roc_year, $director_dept); 
                        if ($ins->execute()) {
                            $success_message = '✓ 已成功寄送給招生中心，共 ' . $sent . ' 封';
                        } else {
                            $success_message = '✓ 已寄送給招生中心(' . $sent . '封)，但記錄保存失敗：' . $ins->error;
                        }
                        $ins->close(); 
                    } else {
                        $success_message = '✓ 已寄送給招生中心(' . $sent . '封)，但無法準備記錄語句';
                    }
                    if (!empty($send_errors)) {
                        $success_message .= ' [偵錯: ' . implode('; ', $send_errors) . ']';
                    }
                } else {
                    // 未能寄送任何郵件時，不記錄（允許重新嘗試）
                    $error_message = '寄送失敗：' . (empty($send_errors) ? '未知原因' : implode('; ', $send_errors));
                }

            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        }
        // 教師提交整班畢業資料
        if (isset($_POST['action']) && $_POST['action'] === 'submit_class') {
            $submit_class = trim($_POST['class_name'] ?? '');
            if ($submit_class === '') {
                $error_message = '缺少班級名稱';
            } else {
                // 建立紀錄表
                ensureGraduatedClassSubmissionTable($conn);

                // 計算畢業民國年與學年度範圍（同上）
                $grad_year_west = (int)date('Y');
                $roc_enroll_year = $grad_year_west - 1916;
                $graduation_roc_year = $roc_enroll_year + 5;

                // 決定科系 key（儲存 department 代碼或名稱）
                $dept_code_val = $teacher_department;

                $ins = $conn->prepare("INSERT INTO graduated_class_submissions (graduation_roc_year, department_code, class_name, submitted_by_user_id, submitted_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE submitted_by_user_id = VALUES(submitted_by_user_id), submitted_at = VALUES(submitted_at)");
                if ($ins) {
                    $ins->bind_param('issi', $graduation_roc_year, $dept_code_val, $submit_class, $session_user_id);
                    if ($ins->execute()) {
                        $success_message = '已提交本班畢業資料';
                    } else {
                        $error_message = '提交失敗：' . $ins->error;
                    }
                    $ins->close();
                } else {
                    $error_message = '準備提交失敗：' . $conn->error;
                }

                // 檢查是否兩班皆已提交（孝班與忠班）
                $check = $conn->prepare("SELECT class_name FROM graduated_class_submissions WHERE graduation_roc_year = ? AND (class_name = '孝班' OR class_name = '忠班') AND department_code = ? GROUP BY class_name");
                if ($check) {
                    $check->bind_param('is', $graduation_roc_year, $dept_code_val);
                    $check->execute();
                    $cr = $check->get_result();
                    $submitted_classes = [];
                    while ($r = $cr->fetch_assoc()) { $submitted_classes[] = $r['class_name']; }
                    $check->close();

                    if (in_array('孝班', $submitted_classes, true) && in_array('忠班', $submitted_classes, true)) {
                        // 兩班皆已提交：僅記錄，改由主任審查後由主任送出給招生中心
                        $success_message .= '；✓ 孝班與忠班皆已提交，請主任審查並按「提交至招生中心」。';
                    } else {
                        // 只有一班已提交
                        if (in_array('孝班', $submitted_classes, true)) {
                            $success_message .= '；孝班已提交，等待忠班提交。';
                        } elseif (in_array('忠班', $submitted_classes, true)) {
                            $success_message .= '；忠班已提交，等待孝班提交。';
                        }
                    }
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
                // ===== 決定查詢時使用的科系（教師用 teacher_department，主任需動態取得）=====
                $query_dept = $teacher_department; // 預設為教師科系
                if ($is_director && empty($query_dept)) {
                    // 主任需要動態取得其所屬科系
                    $director_dept_query = '';
                    $dchk_s = $conn->query("SHOW TABLES LIKE 'director'");
                    if ($dchk_s && $dchk_s->num_rows > 0) {
                        $dq_s = $conn->prepare("SELECT department FROM director WHERE user_id = ? LIMIT 1");
                        if ($dq_s) {
                            $dq_s->bind_param('i', $session_user_id);
                            $dq_s->execute();
                            $dr_s = $dq_s->get_result();
                            if ($dr_s && ($drw_s = $dr_s->fetch_assoc())) $director_dept_query = trim((string)$drw_s['department']);
                            $dq_s->close();
                        }
                    }
                    if ($director_dept_query === '') {
                        $tq_s = $conn->prepare("SELECT t.department FROM teacher t WHERE t.user_id = ? LIMIT 1");
                        if ($tq_s) {
                            $tq_s->bind_param('i', $session_user_id);
                            $tq_s->execute();
                            $tr_s = $tq_s->get_result();
                            if ($tr_s && ($trw_s = $tr_s->fetch_assoc())) $director_dept_query = trim((string)$trw_s['department']);
                            $tq_s->close();
                        }
                    }
                    $query_dept = $director_dept_query;
                }
                
                // 依類別轉換為關鍵字 (孝 -> %孝% ; 忠 -> %忠%)
                $kw = (mb_strpos($selected_class, '孝') !== false) ? '%孝%' : '%忠%';

                $students_sql = "SELECT s.id, s.student_no, s.student_name, s.university, u.type_name AS university_name, s.achievements, s.achievement_note, s.created_at
                                FROM new_student_basic_info s
                                LEFT JOIN university_types u
                                    ON TRIM(UPPER(s.university)) = TRIM(UPPER(u.type_code))
                                " . $dept_join . " WHERE (s.`$dept_col` = ?";
                if ($dept_join !== '') {
                    $students_sql .= " OR COALESCE(d.name,'') = ?";
                }
                $students_sql .= ") AND s.class_name LIKE ? AND s.created_at >= ? AND s.created_at <= ? ORDER BY s.student_no ASC";

                $stmt = $conn->prepare($students_sql);
                if (!$stmt) throw new Exception('查詢準備失敗: ' . $conn->error);

                if ($dept_join !== '') {
                    $stmt->bind_param('sssss', $query_dept, $query_dept, $kw, $year_range['start'], $year_range['end']);
                } else {
                    $stmt->bind_param('ssss', $query_dept, $kw, $year_range['start'], $year_range['end']);
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

function convertAchievementsToText($codes_string, $achievement_options) {
    if (empty($codes_string)) return '';

    $codes = explode(',', $codes_string);
    $texts = [];

    foreach ($codes as $code) {
        $code = trim($code);
        if (isset($achievement_options[$code])) {
            $texts[] = $achievement_options[$code];
        }
    }

    return implode('、', $texts);
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
    // 創建 university_types 表（如果不存在）
    $sql_uni = "CREATE TABLE IF NOT EXISTS university_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type_code VARCHAR(20) NOT NULL UNIQUE,
        type_name VARCHAR(100) NOT NULL,
        description TEXT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($sql_uni);

    // 創建 achievement_types 表（如果不存在）
    $sql_ach = "CREATE TABLE IF NOT EXISTS achievement_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type_code VARCHAR(20) NOT NULL UNIQUE,
        type_name VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($sql_ach);

    // 檢查並添加必要的欄位到 new_student_basic_info
    $check_columns = [
        'university' => "ALTER TABLE new_student_basic_info ADD COLUMN university VARCHAR(100) DEFAULT NULL",
        'achievements' => "ALTER TABLE new_student_basic_info ADD COLUMN achievements LONGTEXT DEFAULT NULL",
        'achievement_note' => "ALTER TABLE new_student_basic_info ADD COLUMN achievement_note LONGTEXT DEFAULT NULL"
    ];

    foreach ($check_columns as $col => $alter_sql) {
        if (!hasColumn($conn, 'new_student_basic_info', $col)) {
            $result = $conn->query($alter_sql);
            if ($result === false && strpos($conn->error, '1061') === false) {
                // 1061 是 'Duplicate column name' 錯誤，可以忽略
                // 其他錯誤會被記錄
            }
        }
    }
}

// 建立教師提交班級畢業資料的紀錄表
function ensureGraduatedClassSubmissionTable($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS graduated_class_submissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        graduation_roc_year INT NOT NULL,
        department_code VARCHAR(100) NOT NULL,
        class_name VARCHAR(50) NOT NULL,
        submitted_by_user_id INT DEFAULT NULL,
        submitted_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_year_dept_class (graduation_roc_year, department_code, class_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($sql);
}

// 輕量 xlsx 產生器（簡易版，僅輸出一個 sheet）
function build_graduated_xlsx_custom($rows, $output_path) {
    if (!class_exists('ZipArchive')) return false;
    $zip = new ZipArchive();
    if ($zip->open($output_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
    $esc = function ($v) {
        $v = (string)$v;
        return str_replace(['&','<','>','"',"'"], ['&amp;','&lt;','&gt;','&quot;','&apos;'], $v);
    };
    $col_letter = function ($i) {
        $i = (int)$i + 1;
        $letters = '';
        while ($i > 0) { $mod = ($i - 1) % 26; $letters = chr(65 + $mod) . $letters; $i = (int)(($i - 1) / 26); }
        return $letters;
    };
    $sheet_rows = '';
    $row_num = 1;
    foreach ($rows as $row) {
        $sheet_rows .= '<row r="' . $row_num . '">';
        foreach ($row as $col_num => $cell) {
            $sheet_rows .= '<c r="' . $col_letter($col_num) . $row_num . '" t="inlineStr"><is><t xml:space="preserve">' . $esc($cell) . '</t></is></c>';
        }
        $sheet_rows .= '</row>';
        $row_num++;
    }
    $sheet_xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheet_rows . '</sheetData></worksheet>';
    $wb_xml = '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', $wb_xml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet_xml);
    $zip->close();
    return file_exists($output_path);
}

// 紀錄主任寄送給招生中心的表（避免重複寄送）
function ensureGraduatedDirectorEmailLogTable($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS graduated_director_email_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        graduation_roc_year INT NOT NULL,
        department_code VARCHAR(50) NOT NULL,
        sent_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_year_dept (graduation_roc_year, department_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($sql);
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

                <!-- 主任審核與寄送面板（常駐，不含在學生列表內） -->
                <?php if ($is_director):
                    $dir_panel_html = '';
                    try {
                        $connd = getDatabaseConnection();
                        $director_dept = '';
                        if ($connd) {
                            $dchk = $connd->query("SHOW TABLES LIKE 'director'");
                            if ($dchk && $dchk->num_rows > 0) {
                                $dq = $connd->prepare("SELECT department FROM director WHERE user_id = ? LIMIT 1");
                                if ($dq) { $dq->bind_param('i', $session_user_id); $dq->execute(); $dr = $dq->get_result(); if ($dr && ($drw = $dr->fetch_assoc())) $director_dept = trim((string)$drw['department']); $dq->close(); }
                            }
                            if ($director_dept === '') {
                                $tq = $connd->prepare("SELECT t.department FROM teacher t WHERE t.user_id = ? LIMIT 1");
                                if ($tq) { $tq->bind_param('i', $session_user_id); $tq->execute(); $tr = $tq->get_result(); if ($tr && ($trw = $tr->fetch_assoc())) $director_dept = trim((string)$trw['department']); $tq->close(); }
                            }
                            $grad_year_west = (int)date('Y');
                            $roc_enroll_year = $grad_year_west - 1916;
                            $graduation_roc_year = $roc_enroll_year + 5;
                            $submissions = [];
                            // 只查詢孝班和忠班的提交狀態
                            $stmtc = $connd->prepare("SELECT class_name, submitted_by_user_id, submitted_at FROM graduated_class_submissions WHERE graduation_roc_year = ? AND department_code = ? AND class_name IN ('孝班', '忠班')");
                            if ($stmtc) { $stmtc->bind_param('is', $graduation_roc_year, $director_dept); $stmtc->execute(); $rc = $stmtc->get_result(); while ($rw = $rc->fetch_assoc()) $submissions[$rw['class_name']] = $rw; $stmtc->close(); }
                            ensureGraduatedDirectorEmailLogTable($connd);
                            $chk3 = $connd->prepare("SELECT sent_at FROM graduated_director_email_log WHERE graduation_roc_year = ? AND department_code = ? ORDER BY sent_at DESC LIMIT 1");
                            $already_sent = false;
                            $last_sent_time = '';
                            if ($chk3) { $chk3->bind_param('is', $graduation_roc_year, $director_dept); $chk3->execute(); $r3 = $chk3->get_result(); if ($r3 && ($rw3 = $r3->fetch_assoc())) { $already_sent = true; $last_sent_time = $rw3['sent_at']; } $chk3->close(); }
                            $connd->close();
                        }
                    } catch (Exception $e) { }
                ?>
                    <div class="panel">
                        <div class="panel-header"><i class="fas fa-user-tie"></i> 主任：審核與寄送至招生中心</div>
                        <div class="panel-description">科系：<?php echo htmlspecialchars($teacher_department_name ?: $director_dept ?? $teacher_department); ?>；檢視教師提交狀態與寄送操作。</div>
                        <div style="margin-top:8px;">
                            <ul>
                                <li>孝班：<?php echo isset($submissions['孝班']) ? ('已提交（' . htmlspecialchars($submissions['孝班']['submitted_at']) . '）') : '未提交'; ?></li>
                                <li>忠班：<?php echo isset($submissions['忠班']) ? ('已提交（' . htmlspecialchars($submissions['忠班']['submitted_at']) . '）') : '未提交'; ?></li>
                            </ul>
                            <?php $can_send = (isset($submissions['孝班']) && isset($submissions['忠班']) && !$already_sent); ?>
                            <form method="post" style="margin-top:12px; display:inline;" onsubmit="return confirm('確定要將本科系之畢業資料送出給招生中心（會附上 Excel）嗎？');">
                                <input type="hidden" name="action" value="send_to_center">
                                <button type="submit" class="btn btn-primary" <?php echo $can_send ? '' : 'disabled'; ?>>提交至招生中心並寄送 Excel</button>
                            </form>
                            <?php if ($already_sent): ?>
                                <div style="margin-top:8px;color:#52c41a;"><i class="fas fa-check"></i> 已寄送給招生中心（本年度）<?php echo $last_sent_time ? '：' . htmlspecialchars($last_sent_time) : ''; ?></div>
                            <?php else: ?>
                                <div style="margin-top:8px;color:#999;font-size:13px;">
                                    <?php if (!isset($submissions['孝班']) && !isset($submissions['忠班'])): ?>
                                        未收到任何班級提交
                                    <?php elseif (!isset($submissions['孝班'])): ?>
                                        缺少孝班提交
                                    <?php elseif (!isset($submissions['忠班'])): ?>
                                        缺少忠班提交
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- 學生列表 -->
                <?php if (!empty($selected_class)): ?>
                    <div class="panel">
                        <div class="panel-header">
                            <i class="fas fa-list"></i>
                            <?php echo htmlspecialchars($selected_class); ?> (共 <?php echo $total_students; ?> 人)
                        </div>

                        <div style="margin-top:8px; display:flex; gap:12px; align-items:center;">
                            <?php
                                // 檢查此班是否已提交（僅教師需要顯示）
$class_submitted = false;
if ($is_teacher && $selected_class !== '') {
    $conn_chk = getDatabaseConnection();
    if ($conn_chk) {
        $graduation_roc_year = $roc_enroll_year + 5;
        $stmt_chk = $conn_chk->prepare("SELECT 1 FROM graduated_class_submissions WHERE graduation_roc_year = ? AND department_code = ? AND class_name = ? LIMIT 1");
        if ($stmt_chk) {
            $stmt_chk->bind_param('iss', $graduation_roc_year, $teacher_department, $selected_class);
            $stmt_chk->execute();
            $stmt_chk->store_result();
            $class_submitted = ($stmt_chk->num_rows > 0);
            $stmt_chk->close();
        }
        $conn_chk->close();
    }
}
                            ?>

                            <!-- 只在教師身分顯示狀態與提交按鈕 -->
                            <?php if ($is_teacher): ?>
                                <div style="flex:1; color:#666;">狀態：<?php echo $class_submitted ? '<span style="color:#52c41a;">已提交</span>' : '<span style="color:#999;">未提交</span>'; ?></div>
                                <div>
                                    <?php $btn_label = $class_submitted ? '已提交（等待主任送出）' : '提交本班畢業資料'; ?>
                                    <button class="btn btn-primary" style="margin-bottom:18px;" id="submitClassBtn" onclick="submitClass('<?php echo htmlspecialchars(addslashes($selected_class)); ?>')" <?php echo $class_submitted ? 'disabled' : ''; ?>><?php echo htmlspecialchars($btn_label); ?></button>
                                </div>
                            <?php endif; ?>
                            
                            <!-- 主任不顯示狀態，只有查看按鈕 -->
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
                                            <td><?php echo htmlspecialchars($student['university_name'] ?? '—'); ?></td>
                                            <td>
                                                <div style="max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                    <?php 
                                                        echo htmlspecialchars(
                                                    convertAchievementsToText(
                                                $student['achievements'],
                                         $achievement_options
                                                            )
                                                        ); 
                                                    ?>
                                                    <?php if (!empty($student['achievements']) && strlen($student['achievements']) > 30): ?>
                                                        ...
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($is_director): ?>
                                                    <button class="btn btn-primary" onclick="openEditModal(<?php echo (int)$student['id']; ?>, '<?php echo htmlspecialchars(addslashes($student['student_no'])); ?>', '<?php echo htmlspecialchars(addslashes($student['student_name'])); ?>', '<?php echo htmlspecialchars(addslashes($student['university'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($student['achievements'] ?? '')); ?>', true, '<?php echo htmlspecialchars(addslashes($student['achievement_note'] ?? '')); ?>')">查看</button>
                                                <?php else: ?>
                                                    <button class="btn btn-primary" onclick="openEditModal(<?php echo (int)$student['id']; ?>, '<?php echo htmlspecialchars(addslashes($student['student_no'])); ?>', '<?php echo htmlspecialchars(addslashes($student['student_name'])); ?>', '<?php echo htmlspecialchars(addslashes($student['university'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($student['achievements'] ?? '')); ?>', false, '<?php echo htmlspecialchars(addslashes($student['achievement_note'] ?? '')); ?>')">編輯</button>
                                                <?php endif; ?>
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
                <span id="modalTitle">編輯學生資訊</span>
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
                    <label class="form-label">大學類型</label>
                    <select name="university" id="universityInput" class="form-input">
                        <option value="">請選擇</option>
                        <?php foreach ($university_list as $u): ?>
                            <option value="<?= htmlspecialchars($u['code']) ?>">
                                <?= htmlspecialchars($u['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

               <div class="form-group">
                <label class="form-label">成就與榮譽</label>

                <div class="checkbox-group">
                    <?php foreach ($achievement_list as $a): ?>
                        <label>
                            <input type="checkbox" name="achievements[]" value="<?= htmlspecialchars($a['code']) ?>">
                            <?= htmlspecialchars($a['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>

    <!-- 成就備註欄位 -->
    <div style="margin-top:10px;">
        <label class="form-label">成就備註</label>
        <textarea name="achievement_note"
                  id="achievementNoteInput"
                  class="form-input"
                  placeholder="例如：2025 亞洲機器人競賽勇奪第一名"></textarea>
    </div>

</div>

                <div class="button-group">
                    <button type="button" id="modalCancelBtn" class="btn btn-secondary" onclick="closeEditModal()">取消</button>
                    <button type="submit" id="modalSaveBtn" class="btn btn-primary">保存</button>
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

        function openEditModal(studentId, studentNo, studentName, university, achievements, readOnly, achievementNote) {
            const form = document.getElementById('editForm');
            document.getElementById('studentId').value = studentId;
            document.getElementById('studentNoDisplay').textContent = studentNo;
            document.getElementById('studentNameDisplay').textContent = studentName;
            document.getElementById('universityInput').value = university;
            
            // 設置成就備註
            const noteInput = document.getElementById('achievementNoteInput');
            noteInput.value = achievementNote || '';
            
            // 取消全部勾選
            document.querySelectorAll('input[name="achievements[]"]').forEach(cb => {
                cb.checked = false;
            });
            
            // 根據成就代碼設置勾選
            if (achievements) {
                let arr = achievements.split(',');
                arr.forEach(code => {
                    let checkbox = document.querySelector(
                        'input[name="achievements[]"][value="' + code.trim() + '"]'
                    );
                    if (checkbox) checkbox.checked = true;
                });
            }
            
            // 設置讀寫狀態
            const uni = document.getElementById('universityInput');
            const checkboxes = document.querySelectorAll('input[name="achievements[]"]');
            const saveBtn = document.getElementById('modalSaveBtn');
            const title = document.getElementById('modalTitle');
            
            if (readOnly) {
                uni.disabled = true;
                checkboxes.forEach(cb => cb.disabled = true);
                noteInput.disabled = true;
                saveBtn.style.display = 'none';
                title.textContent = '查看學生資訊';
                form.dataset.readonly = '1';
            } else {
                uni.disabled = false;
                checkboxes.forEach(cb => cb.disabled = false);
                noteInput.disabled = false;
                saveBtn.style.display = '';
                title.textContent = '編輯學生資訊';
                form.dataset.readonly = '0';
            }
            
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
            // prevent submitting in readonly mode
            if (this.dataset.readonly === '1') { e.preventDefault(); return false; }
            e.preventDefault();
            
            const formData = new FormData(this);
            
            // 調試：輸出表單數據
            console.log('提交的數據:');
            for (let [key, value] of formData.entries()) {
                console.log(key + ':', value);
            }
            
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

        // 提交整班畢業資料
        function submitClass(className) {
            if (!confirm('確定要提交「' + className + '」的畢業資料嗎？提交後等待主任確認。')) return;
            const btn = document.getElementById('submitClassBtn');
            btn.disabled = true;
            const orig = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 提交中...';
            const form = new FormData();
            form.append('action', 'submit_class');
            form.append('class_name', className);
            fetch(window.location.href, { method: 'POST', body: form })
            .then(r => r.text())
            .then(() => { window.location.reload(); })
            .catch(err => { console.error(err); alert('提交失敗'); btn.disabled = false; btn.innerHTML = orig; });
        }
    </script>
</body>
</html>
