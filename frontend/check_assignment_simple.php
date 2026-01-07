<?php
/**
 * 簡單檢查工具：直接查詢資料庫檢查分配問題
 */

require_once __DIR__ . '/session_config.php';
checkBackendLogin();

require_once '../../Topics-frontend/frontend/config.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>簡單檢查工具</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .error { color: red; }
        .success { color: green; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 4px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>簡單檢查工具</h1>
    
    <?php
    try {
        $conn = getDatabaseConnection();
        $user_id = $_SESSION['user_id'] ?? 0;
        $user_role = $_SESSION['role'] ?? '';
        
        echo '<h2>當前用戶資訊</h2>';
        echo '<p><strong>User ID:</strong> ' . htmlspecialchars($user_id) . '</p>';
        echo '<p><strong>Role:</strong> ' . htmlspecialchars($user_role) . '</p>';
        
        if ($user_role === 'DI') {
            // 獲取主任的科系代碼
            $table_check = $conn->query("SHOW TABLES LIKE 'director'");
            if ($table_check && $table_check->num_rows > 0) {
                $dept_stmt = $conn->prepare("SELECT department FROM director WHERE user_id = ?");
            } else {
                $dept_stmt = $conn->prepare("SELECT department FROM teacher WHERE user_id = ?");
            }
            $dept_stmt->bind_param("i", $user_id);
            $dept_stmt->execute();
            $dept_result = $dept_stmt->get_result();
            $dept_row = $dept_result->fetch_assoc();
            $user_dept_code = $dept_row ? $dept_row['department'] : null;
            
            echo '<p><strong>科系代碼:</strong> ' . htmlspecialchars($user_dept_code ?? '未找到') . '</p>';
            
            if ($user_dept_code) {
                echo '<h2>檢查1: 所有分配給此科系的學生（不考慮 graduation_year）</h2>';
                $all_stmt = $conn->prepare("
                    SELECT id, name, assigned_department, graduation_year, created_at 
                    FROM enrollment_intention 
                    WHERE assigned_department = ? 
                    ORDER BY created_at DESC 
                    LIMIT 20
                ");
                $all_stmt->bind_param("s", $user_dept_code);
                $all_stmt->execute();
                $all_result = $all_stmt->get_result();
                
                if ($all_result && $all_result->num_rows > 0) {
                    echo '<p class="success">找到 ' . $all_result->num_rows . ' 筆記錄</p>';
                    echo '<table>';
                    echo '<tr><th>ID</th><th>姓名</th><th>分配科系</th><th>畢業年份</th><th>建立時間</th></tr>';
                    while ($row = $all_result->fetch_assoc()) {
                        echo '<tr>';
                        echo '<td>' . htmlspecialchars($row['id']) . '</td>';
                        echo '<td>' . htmlspecialchars($row['name']) . '</td>';
                        echo '<td>' . htmlspecialchars($row['assigned_department']) . '</td>';
                        echo '<td>' . htmlspecialchars($row['graduation_year'] ?? 'NULL') . '</td>';
                        echo '<td>' . htmlspecialchars($row['created_at']) . '</td>';
                        echo '</tr>';
                    }
                    echo '</table>';
                } else {
                    echo '<p class="error">❌ 沒有找到任何記錄！</p>';
                }
                
                echo '<h2>檢查2: 符合查詢條件的學生（考慮 graduation_year）</h2>';
                $current_month = (int)date('m');
                $current_year = (int)date('Y');
                $grad_threshold_year = ($current_month >= 8) ? $current_year : $current_year - 1;
                
                echo '<p>當前月份: ' . $current_month . ', 當前年份: ' . $current_year . ', 閾值: ' . $grad_threshold_year . '</p>';
                
                $filtered_stmt = $conn->prepare("
                    SELECT id, name, assigned_department, graduation_year, created_at 
                    FROM enrollment_intention 
                    WHERE assigned_department = ? 
                    AND (graduation_year > ? OR graduation_year IS NULL)
                    ORDER BY created_at DESC 
                    LIMIT 20
                ");
                $filtered_stmt->bind_param("si", $user_dept_code, $grad_threshold_year);
                $filtered_stmt->execute();
                $filtered_result = $filtered_stmt->get_result();
                
                if ($filtered_result && $filtered_result->num_rows > 0) {
                    echo '<p class="success">找到 ' . $filtered_result->num_rows . ' 筆記錄（通過 graduation_year 過濾）</p>';
                    echo '<table>';
                    echo '<tr><th>ID</th><th>姓名</th><th>分配科系</th><th>畢業年份</th><th>建立時間</th></tr>';
                    while ($row = $filtered_result->fetch_assoc()) {
                        echo '<tr>';
                        echo '<td>' . htmlspecialchars($row['id']) . '</td>';
                        echo '<td>' . htmlspecialchars($row['name']) . '</td>';
                        echo '<td>' . htmlspecialchars($row['assigned_department']) . '</td>';
                        echo '<td>' . htmlspecialchars($row['graduation_year'] ?? 'NULL') . '</td>';
                        echo '<td>' . htmlspecialchars($row['created_at']) . '</td>';
                        echo '</tr>';
                    }
                    echo '</table>';
                } else {
                    echo '<p class="error">❌ 沒有找到任何記錄（被 graduation_year 過濾掉了）</p>';
                }
                
                echo '<h2>檢查3: 最近提交的就讀意願（檢查自動分配）</h2>';
                $recent_stmt = $conn->query("
                    SELECT 
                        ei.id,
                        ei.name,
                        ei.assigned_department,
                        ei.graduation_year,
                        ei.created_at,
                        ec1.department_code AS first_choice_code
                    FROM enrollment_intention ei
                    LEFT JOIN enrollment_choices ec1 ON ei.id = ec1.enrollment_id AND ec1.choice_order = 1
                    ORDER BY ei.created_at DESC
                    LIMIT 10
                ");
                
                if ($recent_stmt && $recent_stmt->num_rows > 0) {
                    echo '<table>';
                    echo '<tr><th>ID</th><th>姓名</th><th>第一志願科系</th><th>已分配科系</th><th>狀態</th><th>建立時間</th></tr>';
                    while ($row = $recent_stmt->fetch_assoc()) {
                        $status = '';
                        if (empty($row['assigned_department'])) {
                            $status = '<span class="error">❌ 未分配</span>';
                        } elseif ($row['first_choice_code'] == $row['assigned_department']) {
                            $status = '<span class="success">✓ 已分配</span>';
                        } else {
                            $status = '<span class="error">⚠️ 不匹配</span>';
                        }
                        
                        echo '<tr>';
                        echo '<td>' . htmlspecialchars($row['id']) . '</td>';
                        echo '<td>' . htmlspecialchars($row['name']) . '</td>';
                        echo '<td>' . htmlspecialchars($row['first_choice_code'] ?? 'NULL') . '</td>';
                        echo '<td>' . htmlspecialchars($row['assigned_department'] ?? 'NULL') . '</td>';
                        echo '<td>' . $status . '</td>';
                        echo '<td>' . htmlspecialchars($row['created_at']) . '</td>';
                        echo '</tr>';
                    }
                    echo '</table>';
                }
            }
        }
        
        $conn->close();
        
    } catch (Exception $e) {
        echo '<p class="error">錯誤: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
    ?>
</body>
</html>

