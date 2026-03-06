<?php
/**
 * 開系統時執行：若今日尚未執行過，則檢查「分配後 3 天沒有聯絡」的學生並自動轉到下一意願。
 * 每日只執行一次（依檔案記錄的日期判斷）。
 * 供 enrollment_list.php 載入時呼叫。
 */

require_once __DIR__ . '/reassign_uncontacted_functions.php';

/**
 * 若今日尚未執行過，則執行「三天未聯絡 → 轉下一意願」；否則不做事。
 * @param mysqli $conn 資料庫連線
 */
function run_three_day_reassign_if_needed($conn) {
    $lock_file = __DIR__ . '/../.last_three_day_reassign';
    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');

    if (file_exists($lock_file)) {
        $last = trim((string) @file_get_contents($lock_file));
        // 比對日期部分即可（檔案內容可能是 "Y-m-d" 或 "Y-m-d H:i:s"）
        $last_date = substr($last, 0, 10);
        if ($last_date === $today) {
            return;
        }
    }

    $days_3_reassign = 3;

    $sql_3days = "
        SELECT 
            ei.id,
            ei.name,
            ei.assigned_department,
            ei.assigned_teacher_id,
            CASE WHEN ei.assigned_teacher_id IS NOT NULL THEN ei.created_at ELSE ei.updated_at END AS assignment_start,
            d.name AS department_name,
            TIMESTAMPDIFF(HOUR, CASE WHEN ei.assigned_teacher_id IS NOT NULL THEN ei.created_at ELSE ei.updated_at END, NOW()) AS hours_since_assigned
        FROM enrollment_intention ei
        LEFT JOIN departments d ON ei.assigned_department = d.code
        WHERE ei.assigned_department IS NOT NULL
        AND ei.assigned_department != ''
        AND NOT EXISTS (
            SELECT 1 
            FROM enrollment_contact_logs ecl 
            WHERE ecl.enrollment_id = ei.id
        )
        AND TIMESTAMPDIFF(DAY, CASE WHEN ei.assigned_teacher_id IS NOT NULL THEN ei.created_at ELSE ei.updated_at END, NOW()) >= ?
        ORDER BY assignment_start ASC
    ";

    $stmt = $conn->prepare($sql_3days);
    if (!$stmt) {
        return;
    }
    $stmt->bind_param("d", $days_3_reassign);
    $stmt->execute();
    $result = $stmt->get_result();
    $students_3days = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($students_3days as $student) {
        $enrollment_id = (int)$student['id'];
        $current_dept = trim((string)($student['assigned_department'] ?? ''));
        $assignment_start = isset($student['assignment_start']) ? trim((string)$student['assignment_start']) : '';

        // 轉到下一意願前：若「目前意願」尚未有分配歷程，先補一筆（分配時間 = 當時起算日，例如 3/3）
        if ($current_dept !== '' && $assignment_start !== '' && !has_assignment_log_for_department($conn, $enrollment_id, $current_dept)) {
            $order = get_choice_order_for_department($conn, $enrollment_id, $current_dept);
            if ($order >= 1) {
                insert_enrollment_assignment_log($conn, $enrollment_id, $current_dept, $order, 'initial', $assignment_start);
            }
        }

        $next_choice = getNextEnrollmentChoice($conn, $student['id'], $student['assigned_department']);
        if ($next_choice) {
            reassignToNextChoice($conn, $student['id'], $next_choice['department_code'], $student, $next_choice);
        }
    }

    @file_put_contents($lock_file, $now);
}
