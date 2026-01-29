<?php
/**
 * 生成測試資料用於測試「達到錄取標準名單」功能
 * 
 * 此腳本會創建：
 * 1. 續招報名資料 (continued_admission)
 * 2. 分配資料 (continued_admission_assignments) - 分配給老師和主任
 * 3. 評分資料 (continued_admission_scores) - 三位評審的評分
 * 4. 科系名額設定 (department_quotas) - 如果還沒有的話
 */

require_once __DIR__ . '/session_config.php';
checkBackendLogin();

require_once '../../Topics-frontend/frontend/config.php';

header('Content-Type: text/html; charset=utf-8');

try {
    $conn = getDatabaseConnection();
    
    // 獲取當前年份
    $current_year = (int)date('Y');
    $apply_no_prefix = $current_year;
    
    // 獲取一些科系代碼（假設有這些科系）
    $dept_result = $conn->query("SELECT code, name FROM departments WHERE code != 'AA' ORDER BY code LIMIT 5");
    $departments = [];
    if ($dept_result) {
        while ($row = $dept_result->fetch_assoc()) {
            $departments[] = $row;
        }
    }
    
    if (empty($departments)) {
        die("錯誤：找不到任何科系資料。請先確保 departments 表中有資料。");
    }
    
    // 獲取一些老師和主任的 user_id（假設有這些用戶）
    // 從 user 表 JOIN teacher 表來獲取姓名
    $teacher_result = $conn->query("
        SELECT t.user_id, u.name, t.department 
        FROM teacher t 
        LEFT JOIN user u ON t.user_id = u.id 
        WHERE t.department IS NOT NULL AND t.department != '' 
        LIMIT 10
    ");
    $teachers = [];
    if ($teacher_result) {
        while ($row = $teacher_result->fetch_assoc()) {
            $teachers[] = $row;
        }
    }
    
    // 檢查是否有 director 表
    $director_table_check = $conn->query("SHOW TABLES LIKE 'director'");
    $has_director_table = ($director_table_check && $director_table_check->num_rows > 0);
    
    $directors = [];
    if ($has_director_table) {
        $director_result = $conn->query("
            SELECT d.user_id, u.name, d.department 
            FROM director d 
            LEFT JOIN user u ON d.user_id = u.id 
            WHERE d.department IS NOT NULL AND d.department != '' 
            LIMIT 5
        ");
        if ($director_result) {
            while ($row = $director_result->fetch_assoc()) {
                $directors[] = $row;
            }
        }
    }
    
    // 如果沒有 director 表或沒有找到主任，從 teacher 表查找（假設 role = 'DI' 的是主任）
    if (empty($directors)) {
        $director_result = $conn->query("
            SELECT t.user_id, u.name, t.department 
            FROM teacher t 
            LEFT JOIN user u ON t.user_id = u.id 
            WHERE t.department IS NOT NULL AND t.department != '' 
            AND (u.role = 'DI' OR u.role = '主任')
            LIMIT 5
        ");
        if ($director_result) {
            while ($row = $director_result->fetch_assoc()) {
                $directors[] = $row;
            }
        }
        
        // 如果還是沒有，就使用前幾個老師作為主任
        if (empty($directors) && !empty($teachers)) {
            $directors = array_slice($teachers, 0, min(5, count($teachers)));
        }
    }
    
    if (empty($teachers) && empty($directors)) {
        die("錯誤：找不到任何老師或主任資料。請先確保 teacher 或 director 表中有資料。");
    }
    
    echo "<h2>開始生成測試資料...</h2>";
    echo "<pre>";
    
    // 1. 確保科系名額設定存在
    echo "1. 設定科系名額...\n";
    foreach ($departments as $dept) {
        $dept_code = $dept['code'];
        
        // 檢查是否已有名額設定
        $quota_check = $conn->prepare("SELECT id FROM department_quotas WHERE department_code = ? AND is_active = 1");
        $quota_check->bind_param("s", $dept_code);
        $quota_check->execute();
        $quota_result = $quota_check->get_result();
        $quota_check->close();
        
        if ($quota_result->num_rows == 0) {
            // 創建名額設定
            $total_quota = 5; // 預設名額 5 名
            $cutoff_score = 60; // 預設錄取標準 60 分
            
            $quota_insert = $conn->prepare("INSERT INTO department_quotas (department_code, total_quota, cutoff_score, is_active) VALUES (?, ?, ?, 1)");
            $quota_insert->bind_param("sii", $dept_code, $total_quota, $cutoff_score);
            $quota_insert->execute();
            $quota_insert->close();
            
            echo "   - 為科系 {$dept['name']} ({$dept_code}) 設定名額：{$total_quota} 名，錄取標準：{$cutoff_score} 分\n";
        } else {
            echo "   - 科系 {$dept['name']} ({$dept_code}) 已有名額設定\n";
        }
    }
    
    // 2. 確保有測試學校資料
    echo "\n2. 準備測試學校資料...\n";
    $school_codes = [];
    
    // 先查詢現有的學校代碼
    $school_result = $conn->query("SELECT school_code FROM school_data WHERE school_code IS NOT NULL AND school_code != '' LIMIT 10");
    if ($school_result) {
        while ($row = $school_result->fetch_assoc()) {
            $school_codes[] = $row['school_code'];
        }
    }
    
    // 如果沒有現有的學校，創建一些測試學校
    if (empty($school_codes)) {
        echo "   - 沒有現有學校資料，創建測試學校...\n";
        for ($i = 1; $i <= 10; $i++) {
            $school_code = "TEST" . str_pad($i, 6, "0", STR_PAD_LEFT);
            $school_name = "測試國中" . $i;
            
            // 檢查是否已存在
            $check_school = $conn->prepare("SELECT id FROM school_data WHERE school_code = ?");
            $check_school->bind_param("s", $school_code);
            $check_school->execute();
            $check_result = $check_school->get_result();
            $check_school->close();
            
            if ($check_result->num_rows == 0) {
                $insert_school = $conn->prepare("INSERT INTO school_data (name, city, district, type, school_code, is_active, data_source) VALUES (?, '測試市', '測試區', '國民中學', ?, 1, '測試資料')");
                $insert_school->bind_param("ss", $school_name, $school_code);
                if ($insert_school->execute()) {
                    $school_codes[] = $school_code;
                    echo "   - 創建測試學校：{$school_name} ({$school_code})\n";
                }
                $insert_school->close();
            } else {
                $school_codes[] = $school_code;
            }
        }
    } else {
        echo "   - 找到 " . count($school_codes) . " 個現有學校\n";
    }
    
    if (empty($school_codes)) {
        die("錯誤：無法創建或找到學校資料。請檢查 school_data 表。");
    }
    
    // 3. 為每個科系創建 10 個報名學生
    echo "\n3. 創建報名資料...\n";
    $application_ids = [];
    
    foreach ($departments as $dept) {
        $dept_code = $dept['code'];
        $dept_name = $dept['name'];
        
        for ($i = 1; $i <= 10; $i++) {
            $apply_no = sprintf("%04d%03d", $current_year, count($application_ids) + 1);
            $name = "測試學生" . ($i + (array_search($dept, $departments) * 10));
            $id_number = "A" . str_pad(count($application_ids) + 1, 9, "0", STR_PAD_LEFT);
            $birth_date = date('Y-m-d', strtotime('-' . (15 + rand(0, 3)) . ' years'));
            $gender = rand(0, 1); // 0=女, 1=男
            $phone = "09" . str_pad(rand(0, 99999999), 8, "0", STR_PAD_LEFT);
            $mobile = $phone;
            // 隨機選擇一個學校代碼
            $school = $school_codes[array_rand($school_codes)];
            $guardian_name = "家長" . $i;
            $guardian_phone = "09" . str_pad(rand(0, 99999999), 8, "0", STR_PAD_LEFT);
            $guardian_mobile = $guardian_phone;
            $self_intro = "這是測試學生的自傳內容 " . $i;
            $skills = "這是測試學生的專長描述 " . $i;
            $status = 'PE'; // 待審核
            $assigned_department = $dept_code;
            
            // 檢查是否有 apply_no 欄位
            $columns_check = $conn->query("SHOW COLUMNS FROM continued_admission LIKE 'apply_no'");
            $has_apply_no = ($columns_check && $columns_check->num_rows > 0);
            
            if ($has_apply_no) {
                $insert_sql = "INSERT INTO continued_admission (
                    apply_no, exam_no, name, id_number, birth_date, gender, phone, mobile, school,
                    guardian_name, guardian_phone, guardian_mobile, self_intro, skills, status, assigned_department, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                
                $stmt = $conn->prepare($insert_sql);
                $stmt->bind_param("sssssissssssssss", 
                    $apply_no, $apply_no, $name, $id_number, $birth_date, $gender, $phone, $mobile, $school,
                    $guardian_name, $guardian_phone, $guardian_mobile, $self_intro, $skills, $status, $assigned_department
                );
            } else {
                $insert_sql = "INSERT INTO continued_admission (
                    exam_no, name, id_number, birth_date, gender, phone, mobile, school,
                    guardian_name, guardian_phone, guardian_mobile, self_intro, skills, status, assigned_department, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                
                $stmt = $conn->prepare($insert_sql);
                $stmt->bind_param("ssssissssssssss", 
                    $apply_no, $name, $id_number, $birth_date, $gender, $phone, $mobile, $school,
                    $guardian_name, $guardian_phone, $guardian_mobile, $self_intro, $skills, $status, $assigned_department
                );
            }
            
            if ($stmt->execute()) {
                $application_id = $conn->insert_id;
                $application_ids[] = [
                    'id' => $application_id,
                    'apply_no' => $apply_no,
                    'name' => $name,
                    'department' => $dept_code
                ];
                echo "   - 創建報名：{$name} ({$apply_no}) - 科系：{$dept_name}\n";
            }
            $stmt->close();
        }
    }
    
    // 4. 為每個報名分配評審者（老師1、老師2、主任）
    echo "\n4. 分配評審者...\n";
    
    // 按科系分組報名
    $applications_by_dept = [];
    foreach ($application_ids as $app) {
        $dept = $app['department'];
        if (!isset($applications_by_dept[$dept])) {
            $applications_by_dept[$dept] = [];
        }
        $applications_by_dept[$dept][] = $app;
    }
    
    // 為每個科系的報名分配評審者
    foreach ($applications_by_dept as $dept_code => $apps) {
        // 找到該科系的老師
        $dept_teachers = array_filter($teachers, function($t) use ($dept_code) {
            return $t['department'] === $dept_code;
        });
        $dept_teachers = array_values($dept_teachers);
        
        // 找到該科系的主任
        $dept_director = null;
        foreach ($directors as $dir) {
            if ($dir['department'] === $dept_code) {
                $dept_director = $dir;
                break;
            }
        }
        
        // 如果沒有該科系的主任，使用第一個主任
        if (!$dept_director && !empty($directors)) {
            $dept_director = $directors[0];
        }
        
        foreach ($apps as $app) {
            $app_id = $app['id'];
            
            // 分配老師1
            if (!empty($dept_teachers) && isset($dept_teachers[0])) {
                $teacher1_id = $dept_teachers[0]['user_id'];
                $assign1 = $conn->prepare("INSERT INTO continued_admission_assignments (application_id, reviewer_user_id, reviewer_type, assignment_order, assigned_at) VALUES (?, ?, 'teacher', 1, NOW())");
                $assign1->bind_param("ii", $app_id, $teacher1_id);
                $assign1->execute();
                $assign1->close();
            }
            
            // 分配老師2（如果有的話）
            if (!empty($dept_teachers) && isset($dept_teachers[1])) {
                $teacher2_id = $dept_teachers[1]['user_id'];
                $assign2 = $conn->prepare("INSERT INTO continued_admission_assignments (application_id, reviewer_user_id, reviewer_type, assignment_order, assigned_at) VALUES (?, ?, 'teacher', 2, NOW())");
                $assign2->bind_param("ii", $app_id, $teacher2_id);
                $assign2->execute();
                $assign2->close();
            }
            
            // 分配主任
            if ($dept_director) {
                $director_id = $dept_director['user_id'];
                $assign3 = $conn->prepare("INSERT INTO continued_admission_assignments (application_id, reviewer_user_id, reviewer_type, assignment_order, assigned_at) VALUES (?, ?, 'director', 3, NOW())");
                $assign3->bind_param("ii", $app_id, $director_id);
                $assign3->execute();
                $assign3->close();
            }
        }
        
        echo "   - 為科系 {$dept_code} 的 " . count($apps) . " 個報名分配評審者\n";
    }
    
    // 5. 為每個報名生成評分（三位評審都評分）
    echo "\n5. 生成評分資料...\n";
    
    foreach ($application_ids as $app) {
        $app_id = $app['id'];
        
        // 獲取該報名的分配記錄
        $assign_stmt = $conn->prepare("SELECT reviewer_user_id, assignment_order FROM continued_admission_assignments WHERE application_id = ? ORDER BY assignment_order");
        $assign_stmt->bind_param("i", $app_id);
        $assign_stmt->execute();
        $assign_result = $assign_stmt->get_result();
        $assignments = [];
        while ($row = $assign_result->fetch_assoc()) {
            $assignments[$row['assignment_order']] = $row['reviewer_user_id'];
        }
        $assign_stmt->close();
        
        // 為每個分配記錄生成評分
        foreach ($assignments as $order => $reviewer_id) {
            // 生成隨機分數（自傳和專長各 0-50 分，總分 0-100 分）
            // 為了測試，讓分數有高有低，有些達到錄取標準（60分），有些不達到
            $base_score = rand(40, 90); // 總分在 40-90 之間
            $self_intro_score = rand(20, min(50, $base_score - 10));
            $skills_score = $base_score - $self_intro_score;
            
            // 確保分數在合理範圍內
            if ($self_intro_score > 50) $self_intro_score = 50;
            if ($skills_score > 50) $skills_score = 50;
            if ($self_intro_score < 0) $self_intro_score = 0;
            if ($skills_score < 0) $skills_score = 0;
            
            $reviewer_type = ($order == 3) ? 'director' : 'teacher';
            
            // 檢查是否已有評分記錄
            $score_check = $conn->prepare("SELECT id FROM continued_admission_scores WHERE application_id = ? AND reviewer_user_id = ? AND assignment_order = ?");
            $score_check->bind_param("iii", $app_id, $reviewer_id, $order);
            $score_check->execute();
            $score_result = $score_check->get_result();
            $score_check->close();
            
            if ($score_result->num_rows == 0) {
                $score_insert = $conn->prepare("INSERT INTO continued_admission_scores (application_id, reviewer_user_id, reviewer_type, assignment_order, self_intro_score, skills_score, scored_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $score_insert->bind_param("iisiii", $app_id, $reviewer_id, $reviewer_type, $order, $self_intro_score, $skills_score);
                $score_insert->execute();
                $score_insert->close();
            }
        }
        
        // 計算平均分數
        $avg_stmt = $conn->prepare("
            SELECT AVG(self_intro_score + skills_score) as avg_score
            FROM continued_admission_scores
            WHERE application_id = ?
            AND self_intro_score > 0 AND skills_score > 0
        ");
        $avg_stmt->bind_param("i", $app_id);
        $avg_stmt->execute();
        $avg_result = $avg_stmt->get_result();
        $avg_row = $avg_result->fetch_assoc();
        $avg_score = round($avg_row['avg_score'] ?? 0, 2);
        $avg_stmt->close();
        
        echo "   - {$app['name']} ({$app['apply_no']}) - 平均分數：{$avg_score} 分\n";
    }
    
    echo "\n✅ 測試資料生成完成！\n";
    echo "\n總共創建了 " . count($application_ids) . " 個報名記錄\n";
    echo "現在可以前往「達到錄取標準名單」頁面查看結果。\n";
    echo "</pre>";
    
    echo "<p><a href='continued_admission_list.php?tab=ranking'>前往「達到錄取標準名單」頁面</a></p>";
    
    $conn->close();
    
} catch (Exception $e) {
    echo "<pre>";
    echo "❌ 錯誤：" . $e->getMessage() . "\n";
    echo "堆疊追蹤：\n" . $e->getTraceAsString();
    echo "</pre>";
}
?>

