<?php
/**
 * 續招報名排名和錄取功能
 * 處理評分完成後的自動排序、正取、備取、不錄取
 */

require_once __DIR__ . '/../../../Topics-frontend/frontend/config.php';
require_once __DIR__ . '/continued_admission_auto_assign.php';

/**
 * 檢查所有評審是否都已完成評分
 * 
 * @param mysqli $conn 資料庫連接
 * @param int $application_id 報名ID
 * @return bool 是否所有評審都已完成評分
 */
function isAllReviewersScored($conn, $application_id) {
    // 獲取分配數量
    $assign_stmt = $conn->prepare("SELECT COUNT(*) as total FROM continued_admission_assignments WHERE application_id = ?");
    $assign_stmt->bind_param("i", $application_id);
    $assign_stmt->execute();
    $assign_result = $assign_stmt->get_result();
    $assign_data = $assign_result->fetch_assoc();
    $assign_stmt->close();
    
    $total_assignments = (int)($assign_data['total'] ?? 0);
    
    if ($total_assignments == 0) {
        return false;
    }
    
    // 獲取已完成評分數量
    $score_stmt = $conn->prepare("
        SELECT COUNT(*) as scored_count
        FROM continued_admission_scores
        WHERE application_id = ?
          AND self_intro_score IS NOT NULL
          AND skills_score IS NOT NULL
          AND self_intro_score > 0
          AND skills_score > 0
    ");
    $score_stmt->bind_param("i", $application_id);
    $score_stmt->execute();
    $score_result = $score_stmt->get_result();
    $score_data = $score_result->fetch_assoc();
    $score_stmt->close();
    
    $scored_count = (int)($score_data['scored_count'] ?? 0);
    
    return $scored_count >= $total_assignments;
}

/**
 * 獲取所有已完成評分的報名（按科系分組）
 * 
 * @param mysqli $conn 資料庫連接
 * @param string|null $department_code 科系代碼（可選，用於篩選特定科系）
 * @return array 按科系分組的報名列表
 */
function getScoredApplicationsByDepartment($conn, $department_code = null) {
    $applications = [];
    
    // 查詢所有已分配科系的報名
    $sql = "
        SELECT ca.id, ca.apply_no, ca.name, ca.assigned_department, ca.status
        FROM continued_admission ca
        WHERE ca.assigned_department IS NOT NULL
          AND ca.assigned_department != ''
    ";
    
    if ($department_code) {
        $sql .= " AND ca.assigned_department = ?";
    }
    
    $sql .= " ORDER BY ca.assigned_department, ca.id";
    
    $stmt = $department_code ? $conn->prepare($sql) : $conn->prepare($sql);
    if ($department_code) {
        $stmt->bind_param("s", $department_code);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $app_id = $row['id'];
        
        // 檢查是否所有評審都已完成評分
        if (isAllReviewersScored($conn, $app_id)) {
            // 計算平均分數
            $score_info = calculateAverageScore($conn, $app_id);
            
            $dept_code = $row['assigned_department'];
            if (!isset($applications[$dept_code])) {
                $applications[$dept_code] = [];
            }
            
            $applications[$dept_code][] = [
                'id' => $app_id,
                'apply_no' => $row['apply_no'],
                'name' => $row['name'],
                'assigned_department' => $dept_code,
                'status' => $row['status'],
                'average_score' => $score_info['average_score'],
                'total_score' => $score_info['total_score'],
                'reviewer_count' => $score_info['reviewer_count'],
                'scores' => $score_info['scores']
            ];
        }
    }
    $stmt->close();
    
    // 對每個科系的報名按平均分數排序（由高到低）
    foreach ($applications as $dept_code => &$dept_apps) {
        usort($dept_apps, function($a, $b) {
            // 先按平均分數排序（由高到低）
            if ($b['average_score'] != $a['average_score']) {
                return $b['average_score'] <=> $a['average_score'];
            }
            // 如果平均分數相同，按總分排序
            if ($b['total_score'] != $a['total_score']) {
                return $b['total_score'] <=> $a['total_score'];
            }
            // 如果總分也相同，按報名編號排序
            return strcmp($a['apply_no'], $b['apply_no']);
        });
    }
    
    return $applications;
}

/**
 * 批量處理錄取狀態（正取、備取、不錄取）
 * 
 * @param mysqli $conn 資料庫連接
 * @param string|null $department_code 科系代碼（可選，用於處理特定科系）
 * @return array 處理結果統計
 */
function processAdmissionRanking($conn, $department_code = null) {
    // 獲取所有已完成評分的報名（按科系分組）
    $applications_by_dept = getScoredApplicationsByDepartment($conn, $department_code);
    
    $results = [
        'total_processed' => 0,
        'approved' => 0,
        'waitlist' => 0,
        'rejected' => 0,
        'departments' => []
    ];
    
    // 開始事務
    $conn->begin_transaction();
    
    try {
        foreach ($applications_by_dept as $dept_code => $applications) {
            // 獲取該科系的名額和錄取標準
            $quota_stmt = $conn->prepare("
                SELECT total_quota, cutoff_score
                FROM department_quotas
                WHERE department_code = ? AND is_active = 1
                LIMIT 1
            ");
            $quota_stmt->bind_param("s", $dept_code);
            $quota_stmt->execute();
            $quota_result = $quota_stmt->get_result();
            $quota_data = $quota_result->fetch_assoc();
            $quota_stmt->close();
            
            $total_quota = $quota_data ? (int)($quota_data['total_quota'] ?? 0) : 0;
            $cutoff_score = $quota_data ? (float)($quota_data['cutoff_score'] ?? 60) : 60;
            
            $dept_results = [
                'department_code' => $dept_code,
                'total_quota' => $total_quota,
                'cutoff_score' => $cutoff_score,
                'total_applications' => count($applications),
                'approved' => 0,
                'waitlist' => 0,
                'rejected' => 0
            ];
            
            // 過濾出及格的學生（平均分數 >= cutoff_score）
            $qualified_applications = array_filter($applications, function($app) use ($cutoff_score) {
                return $app['average_score'] >= $cutoff_score;
            });
            
            $qualified_count = count($qualified_applications);
            
            // 檢查 admission_rank 欄位是否存在
            $rank_column_check = $conn->query("SHOW COLUMNS FROM continued_admission LIKE 'admission_rank'");
            $has_rank_column = ($rank_column_check && $rank_column_check->num_rows > 0);
            
            // 設定正取（前 total_quota 名）
            $approved_count = min($total_quota, $qualified_count);
            for ($i = 0; $i < $approved_count; $i++) {
                $app = $qualified_applications[$i];
                $rank = $i + 1; // 正取編號從1開始
                
                if ($has_rank_column) {
                    $update_stmt = $conn->prepare("
                        UPDATE continued_admission 
                        SET status = 'AP', 
                            admission_rank = ?
                        WHERE id = ?
                    ");
                    $update_stmt->bind_param("ii", $rank, $app['id']);
                } else {
                    $update_stmt = $conn->prepare("
                        UPDATE continued_admission 
                        SET status = 'AP'
                        WHERE id = ?
                    ");
                    $update_stmt->bind_param("i", $app['id']);
                }
                $update_stmt->execute();
                $update_stmt->close();
                
                $dept_results['approved']++;
                $results['approved']++;
                $results['total_processed']++;
            }
            
            // 設定備取（剩餘的及格學生）
            $waitlist_rank = 1; // 備取編號從1開始
            for ($i = $approved_count; $i < $qualified_count; $i++) {
                $app = $qualified_applications[$i];
                
                if ($has_rank_column) {
                    $update_stmt = $conn->prepare("
                        UPDATE continued_admission 
                        SET status = 'AD', 
                            admission_rank = ?
                        WHERE id = ?
                    ");
                    $update_stmt->bind_param("ii", $waitlist_rank, $app['id']);
                } else {
                    $update_stmt = $conn->prepare("
                        UPDATE continued_admission 
                        SET status = 'AD'
                        WHERE id = ?
                    ");
                    $update_stmt->bind_param("i", $app['id']);
                }
                $update_stmt->execute();
                $update_stmt->close();
                
                $waitlist_rank++;
                $dept_results['waitlist']++;
                $results['waitlist']++;
                $results['total_processed']++;
            }
            
            // 設定不錄取（未達cutoff_score的學生）
            foreach ($applications as $app) {
                if ($app['average_score'] < $cutoff_score) {
                    if ($has_rank_column) {
                        $update_stmt = $conn->prepare("
                            UPDATE continued_admission 
                            SET status = 'RE',
                                admission_rank = NULL
                            WHERE id = ?
                        ");
                    } else {
                        $update_stmt = $conn->prepare("
                            UPDATE continued_admission 
                        SET status = 'RE'
                            WHERE id = ?
                        ");
                    }
                    $update_stmt->bind_param("i", $app['id']);
                    $update_stmt->execute();
                    $update_stmt->close();
                    
                    $dept_results['rejected']++;
                    $results['rejected']++;
                    $results['total_processed']++;
                }
            }
            
            $results['departments'][] = $dept_results;
        }
        
        // 提交事務
        $conn->commit();
        
        return $results;
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("批量處理錄取狀態時發生錯誤: " . $e->getMessage());
        throw $e;
    }
}

/**
 * 獲取單個科系的排名列表（用於顯示）
 * 
 * @param mysqli $conn 資料庫連接
 * @param string $department_code 科系代碼
 * @return array 排名列表
 */
function getDepartmentRanking($conn, $department_code) {
    $applications_by_dept = getScoredApplicationsByDepartment($conn, $department_code);
    
    if (!isset($applications_by_dept[$department_code])) {
        return [];
    }
    
    // 獲取該科系的名額和錄取標準
    $quota_stmt = $conn->prepare("
        SELECT total_quota, cutoff_score
        FROM department_quotas
        WHERE department_code = ? AND is_active = 1
        LIMIT 1
    ");
    $quota_stmt->bind_param("s", $department_code);
    $quota_stmt->execute();
    $quota_result = $quota_stmt->get_result();
    $quota_data = $quota_result->fetch_assoc();
    $quota_stmt->close();
    
    $total_quota = $quota_data ? (int)($quota_data['total_quota'] ?? 0) : 0;
    $cutoff_score = $quota_data ? (float)($quota_data['cutoff_score'] ?? 60) : 60;
    
    $applications = $applications_by_dept[$department_code];
    
    // 為每個報名添加排名和狀態預測
    foreach ($applications as $index => &$app) {
        $app['rank'] = $index + 1;
        $app['is_qualified'] = $app['average_score'] >= $cutoff_score;
        
        if ($app['average_score'] >= $cutoff_score) {
            if ($index < $total_quota) {
                $app['predicted_status'] = 'approved';
                $app['predicted_status_label'] = '正取 ' . ($index + 1);
            } else {
                $app['predicted_status'] = 'waitlist';
                $app['predicted_status_label'] = '備取 ' . ($index - $total_quota + 1);
            }
        } else {
            $app['predicted_status'] = 'rejected';
            $app['predicted_status_label'] = '不錄取';
        }
    }
    
    return [
        'department_code' => $department_code,
        'total_quota' => $total_quota,
        'cutoff_score' => $cutoff_score,
        'applications' => $applications
    ];
}

?>

