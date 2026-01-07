<?php
require_once __DIR__ . '/session_config.php';
checkBackendLogin();

// 只允許 IM（資管科主任）進入
$session_role = $_SESSION['role'] ?? '';
$session_username = $_SESSION['username'] ?? '';
$session_user_id = $_SESSION['user_id'] ?? 0;

// 標準化角色（向後兼容）
$role_map = [
  '資管科主任' => 'IM',
  'IM主任' => 'IM',
  '資管主任' => 'IM',
  'IM' => 'IM',
  '主任' => 'DI',
  'DI' => 'DI',
];
$normalized_role = $role_map[$session_role] ?? $session_role;

// 引入資料庫設定（沿用後台常見的多路徑尋找方式）
$config_path = '../../Topics-frontend/frontend/config.php';
if (!file_exists($config_path)) {
  $alt_paths = [
    '../../../Topics-frontend/frontend/config.php',
    __DIR__ . '/../../Topics-frontend/frontend/config.php',
    dirname(__DIR__) . '/../Topics-frontend/frontend/config.php'
  ];
  foreach ($alt_paths as $p) {
    if (file_exists($p)) { $config_path = $p; break; }
  }
}
if (!file_exists($config_path)) {
  die('錯誤：找不到資料庫設定檔案 (config.php)');
}
require_once $config_path;

// 判斷是否為 IM：role=IM 或特殊帳號 IMD，或 DI 且 director.department=IM
$is_im = false;
try {
  if ($normalized_role === 'IM' || $session_username === 'IMD') {
    $is_im = true;
  } elseif (($normalized_role === 'DI' || $session_role === '主任') && $session_user_id) {
    $conn_chk = getDatabaseConnection();
    if ($conn_chk) {
      $table_check = $conn_chk->query("SHOW TABLES LIKE 'director'");
      if ($table_check && $table_check->num_rows > 0) {
        $stmt = $conn_chk->prepare("SELECT department FROM director WHERE user_id = ? LIMIT 1");
      } else {
        $stmt = $conn_chk->prepare("SELECT department FROM teacher WHERE user_id = ? LIMIT 1");
      }
      $stmt->bind_param("i", $session_user_id);
      $stmt->execute();
      $res = $stmt->get_result();
      if ($row = $res->fetch_assoc()) {
        if (($row['department'] ?? '') === 'IM') $is_im = true;
      }
      $stmt->close();
      $conn_chk->close();
    }
  }
} catch (Exception $e) {
  // ignore
}

if (!$is_im) {
  http_response_code(403);
  echo '權限不足：僅資管科主任（IM）可使用此功能。';
  exit;
}

// Page title
$page_title = '學生聯絡管理（升學博覽會）';

// Filters
$teacher_id = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;

// Data
$teachers = [];
$contacts = [];
$error_message = '';

try {
  $conn = getDatabaseConnection();
  if (!$conn) throw new Exception('資料庫連線失敗');

  // 確保表存在（若尚未建立）
  $conn->query("CREATE TABLE IF NOT EXISTS student_contacts (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(100) NOT NULL,
      junior_high VARCHAR(150) DEFAULT NULL,
      current_grade VARCHAR(50) DEFAULT NULL,
      interest_department VARCHAR(150) DEFAULT NULL,
      activity_source VARCHAR(150) DEFAULT NULL,
      contact_teacher VARCHAR(150) DEFAULT NULL,
      status VARCHAR(100) DEFAULT NULL,
      contact_method VARCHAR(50) DEFAULT NULL,
      contact_method_value VARCHAR(255) DEFAULT NULL,
      contact_content TEXT DEFAULT NULL,
      contact_note VARCHAR(255) DEFAULT NULL,
      contact_date DATE DEFAULT NULL,
      created_by INT DEFAULT NULL,
      created_by_username VARCHAR(150) DEFAULT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_activity_source (activity_source),
      INDEX idx_created_by (created_by),
      INDEX idx_created_at (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // Teachers list (only those who created "升學博覽會" contacts)
  $tSql = "
    SELECT DISTINCT u.id, u.username, COALESCE(u.name, u.username) AS name
    FROM student_contacts sc
    INNER JOIN user u ON sc.created_by = u.id
    WHERE sc.activity_source = '升學博覽會'
      AND u.role IN ('TEA','老師')
    ORDER BY name ASC
  ";
  $tRes = $conn->query($tSql);
  if ($tRes) {
    $teachers = $tRes->fetch_all(MYSQLI_ASSOC);
  }

  // Contacts list
  $where = "WHERE sc.activity_source = '升學博覽會' AND u.role IN ('TEA','老師')";
  $params = [];
  $types = '';
  if ($teacher_id > 0) {
    $where .= " AND u.id = ?";
    $types .= 'i';
    $params[] = $teacher_id;
  }

  $sql = "
    SELECT
      sc.id,
      sc.name,
      sc.junior_high,
      sc.current_grade,
      sc.interest_department,
      sc.activity_source,
      sc.contact_teacher,
      sc.status,
      sc.contact_date,
      sc.contact_method,
      sc.contact_method_value,
      sc.contact_content,
      sc.contact_note,
      DATE_FORMAT(sc.created_at, '%Y-%m-%d %H:%i:%s') AS created_at,
      u.id AS teacher_id,
      COALESCE(u.name, u.username) AS teacher_name,
      u.username AS teacher_username
    FROM student_contacts sc
    INNER JOIN user u ON sc.created_by = u.id
    $where
    ORDER BY sc.created_at DESC, sc.id DESC
    LIMIT 500
  ";

  if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $r = $stmt->get_result();
    $contacts = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
  } else {
    $r = $conn->query($sql);
    $contacts = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
  }

  $conn->close();
} catch (Exception $e) {
  $error_message = $e->getMessage();
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
    .dashboard { display: flex; min-height: 100vh; background: #f0f2f5; }
    .main-content { flex: 1; margin-left: 250px; transition: all 0.3s ease; background: #f0f2f5; }
    .main-content.expanded { margin-left: 60px; }
    .content { padding: 24px; }

    .panel {
      background: #fff;
      border: 1px solid #f0f0f0;
      border-radius: 10px;
      padding: 16px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.06);
      margin-bottom: 16px;
    }

    .filters { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
    .filters label { font-weight: 600; color: #333; }
    .filters select {
      padding: 8px 10px;
      border: 1px solid #d9d9d9;
      border-radius: 6px;
      font-size: 14px;
      min-width: 220px;
      background: #fff;
    }
    .filters button {
      padding: 8px 14px;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      font-weight: 600;
      background: #1890ff;
      color: #fff;
    }
    .filters a.reset {
      padding: 8px 14px;
      border: 1px solid #d9d9d9;
      border-radius: 6px;
      text-decoration: none;
      color: #333;
      background: #fff;
      font-weight: 600;
    }

    table { width: 100%; border-collapse: collapse; background: #fff; }
    th, td { padding: 10px 10px; border-bottom: 1px solid #f0f0f0; text-align: left; font-size: 13px; vertical-align: top; }
    th { background: #fafafa; position: sticky; top: 0; z-index: 1; }
    .pill { display: inline-block; padding: 2px 10px; border-radius: 999px; background: #e6f7ff; color: #1890ff; font-weight: 700; font-size: 12px; }
    .muted { color: #777; font-size: 12px; }

    @media (max-width: 768px) {
      .main-content { margin-left: 0; }
    }
  </style>
</head>
<body>
  <div class="dashboard">
    <?php include 'sidebar.php'; ?>
    <div class="main-content" id="mainContent">
      <?php include 'header.php'; ?>
      <div class="content">

        <div class="panel">
          <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
            <div>
              <div style="font-size:18px; font-weight:700; color:#262626;">
                <i class="fas fa-address-book"></i> <?php echo htmlspecialchars($page_title); ?>
              </div>
              <div class="muted">僅顯示「活動來源＝升學博覽會」且由老師（role=TEA）新增的學生聯絡資訊。</div>
            </div>
            <div class="muted">最多顯示最近 500 筆</div>
          </div>
        </div>

        <?php if (!empty($error_message)): ?>
          <div class="panel" style="border-color:#ffccc7; background:#fff2f0; color:#a8071a;">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
          </div>
        <?php endif; ?>

        <div class="panel">
          <form method="GET" class="filters">
            <label for="teacher_id">老師</label>
            <select id="teacher_id" name="teacher_id">
              <option value="0">全部老師</option>
              <?php foreach ($teachers as $t): ?>
                <option value="<?php echo (int)$t['id']; ?>" <?php echo ((int)$teacher_id === (int)$t['id']) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($t['name'] . '（' . $t['username'] . '）'); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button type="submit"><i class="fas fa-search"></i> 查詢</button>
            <a class="reset" href="student_contact_management_im.php"><i class="fas fa-eraser"></i> 清除</a>
          </form>
        </div>

        <div class="panel" style="padding:0; overflow:auto;">
          <table>
            <thead>
              <tr>
                <th style="min-width:110px;">新增老師</th>
                <th style="min-width:90px;">姓名</th>
                <th style="min-width:110px;">國中</th>
                <th style="min-width:80px;">年級</th>
                <th style="min-width:120px;">興趣科系</th>
                <th style="min-width:110px;">活動來源</th>
                <th style="min-width:110px;">聯絡教師</th>
                <th style="min-width:90px;">狀態</th>
                <th style="min-width:110px;">聯絡日期</th>
                <th style="min-width:160px;">聯絡方式/值</th>
                <th style="min-width:180px;">聯絡內容</th>
                <th style="min-width:220px;">備註</th>
                <th style="min-width:150px;">建立時間</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($contacts)): ?>
                <tr><td colspan="13" style="padding:18px; text-align:center; color:#666;">目前沒有資料</td></tr>
              <?php else: ?>
                <?php foreach ($contacts as $c): ?>
                  <tr>
                    <td>
                      <?php echo htmlspecialchars($c['teacher_name'] ?? ''); ?>
                      <div class="muted"><?php echo htmlspecialchars($c['teacher_username'] ?? ''); ?></div>
                    </td>
                    <td><strong><?php echo htmlspecialchars($c['name'] ?? ''); ?></strong></td>
                    <td><?php echo htmlspecialchars($c['junior_high'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($c['current_grade'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($c['interest_department'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($c['activity_source'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($c['contact_teacher'] ?? ''); ?></td>
                    <td><?php if (!empty($c['status'])): ?><span class="pill"><?php echo htmlspecialchars($c['status']); ?></span><?php endif; ?></td>
                    <td><?php echo htmlspecialchars($c['contact_date'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars(($c['contact_method'] ?? '') . (!empty($c['contact_method_value']) ? (' / ' . $c['contact_method_value']) : '')); ?></td>
                    <td style="white-space:pre-wrap;"><?php echo htmlspecialchars($c['contact_content'] ?? ''); ?></td>
                    <td style="white-space:pre-wrap;"><?php echo htmlspecialchars($c['contact_note'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($c['created_at'] ?? ''); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

      </div>
    </div>
  </div>
</body>
</html>


