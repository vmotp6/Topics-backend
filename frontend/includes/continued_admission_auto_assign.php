<?php
// 若 PHP 啟用 opcache 且未自動更新，嘗試重置避免載入舊版本
if (function_exists('opcache_reset')) {
	@opcache_reset();
}
/**
 * 續招自動分配與評分時間檢查相關函數
 */

if (!function_exists('caTableExists')) {
	function caTableExists($conn, $table_name) {
		if (empty($table_name)) {
			return false;
		}
		$table_name_escaped = $conn->real_escape_string($table_name);
		$table_result = $conn->query("SHOW TABLES LIKE '{$table_name_escaped}'");
		return ($table_result && $table_result->num_rows > 0);
	}
}

if (!function_exists('caColumnExists')) {
	function caColumnExists($conn, $table_name, $column_name) {
		if (empty($table_name) || empty($column_name)) {
			return false;
		}
		$table_name_escaped = $conn->real_escape_string($table_name);
		$column_name_escaped = $conn->real_escape_string($column_name);
		$column_result = $conn->query("SHOW COLUMNS FROM `{$table_name_escaped}` LIKE '{$column_name_escaped}'");
		return ($column_result && $column_result->num_rows > 0);
	}
}

if (!function_exists('getCurrentChoiceOrder')) {
	/**
	 * 取得目前分配科系的志願序
	 * 
	 * @param mysqli $conn
	 * @param int $application_id
	 * @return int|null
	 */
	function getCurrentChoiceOrder($conn, $application_id) {
		if (empty($application_id)) {
			return null;
		}

		$app_stmt = $conn->prepare("SELECT assigned_department FROM continued_admission WHERE id = ? LIMIT 1");
		if (!$app_stmt) {
			return null;
		}
		$app_stmt->bind_param("i", $application_id);
		$app_stmt->execute();
		$app_result = $app_stmt->get_result();
		$app_data = $app_result ? $app_result->fetch_assoc() : null;
		$app_stmt->close();

		$assigned_dept = $app_data['assigned_department'] ?? '';
		if (empty($assigned_dept)) {
			return null;
		}

		if (!caTableExists($conn, 'continued_admission_choices')) {
			return null;
		}

		$choice_stmt = $conn->prepare("SELECT choice_order FROM continued_admission_choices WHERE application_id = ? AND department_code = ? LIMIT 1");
		if (!$choice_stmt) {
			return null;
		}
		$choice_stmt->bind_param("is", $application_id, $assigned_dept);
		$choice_stmt->execute();
		$choice_result = $choice_stmt->get_result();
		$choice_data = $choice_result ? $choice_result->fetch_assoc() : null;
		$choice_stmt->close();

		if (!$choice_data || !isset($choice_data['choice_order'])) {
			return null;
		}

		return (int)$choice_data['choice_order'];
	}
}

if (!function_exists('calculateScoreDeadline')) {
	/**
	 * 根據審查開始時間與志願序計算評分截止時間
	 * 
	 * @param string $review_start
	 * @param int $choice_order
	 * @return string|null
	 */
	function calculateScoreDeadline($review_start, $choice_order) {
		if (empty($review_start) || empty($choice_order)) {
			return null;
		}

		$start_ts = strtotime($review_start);
		if (!$start_ts) {
			return null;
		}

		$choice_order = (int)$choice_order;
		if ($choice_order < 1) {
			return null;
		}

		// 每個志願預設 24 小時
		$hours_per_choice = 24;
		$deadline_ts = $start_ts + ($hours_per_choice * $choice_order * 3600);

		return date('Y-m-d H:i:s', $deadline_ts);
	}
}

if (!function_exists('checkScoreTimeByChoice')) {
	/**
	 * 檢查是否在評分期間內（依志願序）
	 * 
	 * @param mysqli $conn
	 * @param int $application_id
	 * @return array
	 */
	function checkScoreTimeByChoice($conn, $application_id) {
		$result = [
			'is_within_period' => true,
			'message' => '',
			'deadline' => null
		];

		if (empty($application_id)) {
			return $result;
		}

		$app_stmt = $conn->prepare("SELECT assigned_department FROM continued_admission WHERE id = ? LIMIT 1");
		if (!$app_stmt) {
			return $result;
		}
		$app_stmt->bind_param("i", $application_id);
		$app_stmt->execute();
		$app_result = $app_stmt->get_result();
		$app_data = $app_result ? $app_result->fetch_assoc() : null;
		$app_stmt->close();

		$assigned_dept = $app_data['assigned_department'] ?? '';
		if (empty($assigned_dept)) {
			$result['message'] = '未分配科系，未設定審查時間';
			return $result;
		}

		$time_stmt = $conn->prepare("SELECT review_start, review_end FROM department_quotas WHERE department_code = ? AND is_active = 1 LIMIT 1");
		if (!$time_stmt) {
			$result['message'] = '無法取得審查時間';
			return $result;
		}
		$time_stmt->bind_param("s", $assigned_dept);
		$time_stmt->execute();
		$time_result = $time_stmt->get_result();
		$time_data = $time_result ? $time_result->fetch_assoc() : null;
		$time_stmt->close();

		$review_start = $time_data['review_start'] ?? null;
		$review_end = $time_data['review_end'] ?? null;

		if (empty($review_start) && empty($review_end)) {
			$result['message'] = '未設定審查時間';
			return $result;
		}

		$choice_order = getCurrentChoiceOrder($conn, $application_id);
		$deadline = null;
		if (!empty($choice_order) && !empty($review_start)) {
			$deadline = calculateScoreDeadline($review_start, $choice_order);
		}
		if (empty($deadline) && !empty($review_end)) {
			$deadline = $review_end;
		}

		$result['deadline'] = $deadline;

		$now_ts = time();
		if (!empty($review_start)) {
			$start_ts = strtotime($review_start);
			if ($start_ts && $now_ts < $start_ts) {
				$result['is_within_period'] = false;
				$result['message'] = '尚未到評分時間';
				return $result;
			}
		}

		if (!empty($deadline)) {
			$deadline_ts = strtotime($deadline);
			if ($deadline_ts && $now_ts > $deadline_ts) {
				$result['is_within_period'] = false;
				$result['message'] = '已超過評分截止時間';
				return $result;
			}
		}

		return $result;
	}
}

if (!function_exists('checkScoreFailed')) {
	/**
	 * 檢查是否未達錄取標準
	 * 
	 * @param mysqli $conn
	 * @param int $application_id
	 * @param string $department_code
	 * @return array
	 */
	function checkScoreFailed($conn, $application_id, $department_code) {
		$result = [
			'is_failed' => false,
			'reason' => ''
		];

		if (empty($application_id) || empty($department_code)) {
			return $result;
		}

		$cutoff_score = 60;
		$quota_stmt = $conn->prepare("SELECT cutoff_score FROM department_quotas WHERE department_code = ? AND is_active = 1 LIMIT 1");
		if ($quota_stmt) {
			$quota_stmt->bind_param("s", $department_code);
			$quota_stmt->execute();
			$quota_result = $quota_stmt->get_result();
			if ($quota_data = $quota_result->fetch_assoc()) {
				if ($quota_data['cutoff_score'] !== null && $quota_data['cutoff_score'] !== '') {
					$cutoff_score = (float)$quota_data['cutoff_score'];
				}
			}
			$quota_stmt->close();
		}

		if (!function_exists('calculateAverageScore')) {
			return $result;
		}

		$score_info = calculateAverageScore($conn, $application_id);
		$avg_score = (float)($score_info['average_score'] ?? 0);

		if ($avg_score < $cutoff_score) {
			$result['is_failed'] = true;
			$result['reason'] = "平均分數 {$avg_score} 未達錄取標準 {$cutoff_score}";
		} else {
			$result['reason'] = "平均分數 {$avg_score} 達到錄取標準 {$cutoff_score}";
		}

		return $result;
	}
}

if (!function_exists('autoAssignToNextChoice')) {
	/**
	 * 自動分配到下一志願
	 * 
	 * @param mysqli $conn
	 * @param int $application_id
	 * @param int $current_choice_order
	 * @return array
	 */
	function autoAssignToNextChoice($conn, $application_id, $current_choice_order) {
		if (empty($application_id) || empty($current_choice_order)) {
			return [
				'success' => false,
				'message' => '參數不足'
			];
		}

		if (!caTableExists($conn, 'continued_admission_choices')) {
			return [
				'success' => false,
				'message' => '找不到志願資料表'
			];
		}

		if (!caColumnExists($conn, 'continued_admission', 'assigned_department')) {
			return [
				'success' => false,
				'message' => '資料表缺少 assigned_department 欄位'
			];
		}

		$next_stmt = $conn->prepare("SELECT department_code, choice_order FROM continued_admission_choices WHERE application_id = ? AND choice_order > ? ORDER BY choice_order ASC LIMIT 1");
		if (!$next_stmt) {
			return [
				'success' => false,
				'message' => '無法查詢下一志願'
			];
		}
		$next_stmt->bind_param("ii", $application_id, $current_choice_order);
		$next_stmt->execute();
		$next_result = $next_stmt->get_result();
		$next_choice = $next_result ? $next_result->fetch_assoc() : null;
		$next_stmt->close();

		if (!$next_choice || empty($next_choice['department_code'])) {
			return [
				'success' => false,
				'message' => '沒有下一志願'
			];
		}

		$next_dept = $next_choice['department_code'];

		try {
			$conn->begin_transaction();

			$update_stmt = $conn->prepare("UPDATE continued_admission SET assigned_department = ? WHERE id = ?");
			$update_stmt->bind_param("si", $next_dept, $application_id);
			$update_stmt->execute();
			$update_stmt->close();

			if (caTableExists($conn, 'continued_admission_assignments')) {
				$delete_assign_stmt = $conn->prepare("DELETE FROM continued_admission_assignments WHERE application_id = ?");
				$delete_assign_stmt->bind_param("i", $application_id);
				$delete_assign_stmt->execute();
				$delete_assign_stmt->close();
			}

			if (caTableExists($conn, 'continued_admission_scores')) {
				$delete_score_stmt = $conn->prepare("DELETE FROM continued_admission_scores WHERE application_id = ?");
				$delete_score_stmt->bind_param("i", $application_id);
				$delete_score_stmt->execute();
				$delete_score_stmt->close();
			}

			$conn->commit();

			return [
				'success' => true,
				'message' => '已分配到下一志願',
				'new_department' => $next_dept
			];
		} catch (Exception $e) {
			$conn->rollback();
			return [
				'success' => false,
				'message' => '自動分配失敗：' . $e->getMessage()
			];
		}
	}
}

