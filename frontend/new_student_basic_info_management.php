<?php
require_once __DIR__ . '/session_config.php';
checkBackendLogin();

// 僅允許 招生中心（STA）與管理員（ADM）
$user_role = $_SESSION['role'] ?? '';
$allowed = ['STA', '行政人員', '學校行政人員', 'ADM', '管理員'];
if (!in_array($user_role, $allowed, true)) {
  http_response_code(403);
  echo '權限不足：僅招生中心（STA）可使用此功能。';
  exit;
}

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

function ensureNewStudentBasicInfoTable($conn) {
  $sql = "CREATE TABLE IF NOT EXISTS new_student_basic_info (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_no VARCHAR(50) NOT NULL,
    student_name VARCHAR(100) NOT NULL,
    class_name VARCHAR(100) NOT NULL,
    enrollment_identity VARCHAR(100) DEFAULT NULL,
    birthday DATE DEFAULT NULL,
    gender VARCHAR(20) DEFAULT NULL,
    id_number VARCHAR(50) DEFAULT NULL,
    mobile VARCHAR(50) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    previous_school VARCHAR(150) DEFAULT NULL,
    photo_path VARCHAR(255) DEFAULT NULL,

    parent_title VARCHAR(50) DEFAULT NULL,
    parent_name VARCHAR(100) DEFAULT NULL,
    parent_birth_year VARCHAR(20) DEFAULT NULL,
    parent_occupation VARCHAR(100) DEFAULT NULL,
    parent_phone VARCHAR(50) DEFAULT NULL,
    parent_education VARCHAR(100) DEFAULT NULL,

    guardian_relation VARCHAR(50) DEFAULT NULL,
    guardian_name VARCHAR(100) DEFAULT NULL,
    guardian_phone VARCHAR(50) DEFAULT NULL,
    guardian_mobile VARCHAR(50) DEFAULT NULL,
    guardian_line VARCHAR(100) DEFAULT NULL,
    guardian_email VARCHAR(150) DEFAULT NULL,

    emergency_name VARCHAR(100) DEFAULT NULL,
    emergency_phone VARCHAR(50) DEFAULT NULL,
    emergency_mobile VARCHAR(50) DEFAULT NULL,

    is_indigenous TINYINT(1) DEFAULT 0,
    is_new_immigrant_child TINYINT(1) DEFAULT 0,
    is_overseas_chinese TINYINT(1) DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_student_no (student_no),
    INDEX idx_student_name (student_name),
    INDEX idx_created_at (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  $conn->query($sql);
}

function photoUrl($photo_path) {
  $p = trim((string)$photo_path);
  if ($p === '') return '';
  // 若已是絕對路徑或完整 URL，原樣回傳
  if (preg_match('/^(https?:\\/\\/|\\/)/i', $p)) return $p;
  // new_student_basic_info.php 存的是 uploads/... 相對路徑，從後台要指到前台目錄
  return '/Topics-frontend/frontend/' . ltrim($p, '/');
}

$page_title = '新生基本資料管理';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
if ($limit <= 0) $limit = 50;
if ($limit > 200) $limit = 200;
if ($offset < 0) $offset = 0;

$rows = [];
$total = 0;
$error_message = '';

try {
  $conn = getDatabaseConnection();
  if (!$conn) throw new Exception('資料庫連接失敗');
  ensureNewStudentBasicInfoTable($conn);

  $where = '';
  $types = '';
  $params = [];
  if ($q !== '') {
    $where = " WHERE student_no LIKE ? OR student_name LIKE ? OR class_name LIKE ? OR id_number LIKE ? OR mobile LIKE ? ";
    $like = '%' . $q . '%';
    $types = 'sssss';
    $params = [$like, $like, $like, $like, $like];
  }

  // total
  $countSql = "SELECT COUNT(*) AS cnt FROM new_student_basic_info" . $where;
  $countStmt = $conn->prepare($countSql);
  if (!$countStmt) throw new Exception('查詢準備失敗');
  if (!empty($params)) $countStmt->bind_param($types, ...$params);
  $countStmt->execute();
  $res = $countStmt->get_result();
  if ($r = $res->fetch_assoc()) $total = (int)$r['cnt'];
  $countStmt->close();

  // list（列表頁只顯示：學號、姓名、班級）
  $listSql = "SELECT
      id, student_no, student_name, class_name,
      DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at
    FROM new_student_basic_info" . $where . "
    ORDER BY created_at DESC, id DESC
    LIMIT ? OFFSET ?";

  $stmt = $conn->prepare($listSql);
  if (!$stmt) throw new Exception('查詢準備失敗');

  if (!empty($params)) {
    $types2 = $types . 'ii';
    $params2 = $params;
    $params2[] = $limit;
    $params2[] = $offset;
    $stmt->bind_param($types2, ...$params2);
  } else {
    $stmt->bind_param('ii', $limit, $offset);
  }
  $stmt->execute();
  $r2 = $stmt->get_result();
  $rows = $r2 ? $r2->fetch_all(MYSQLI_ASSOC) : [];
  $stmt->close();

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
    .filters input {
      padding: 8px 10px;
      border: 1px solid #d9d9d9;
      border-radius: 6px;
      font-size: 14px;
      min-width: 260px;
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

    table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e0e0e0; }
    th, td { padding: 10px 10px; border: 1px solid #e0e0e0; text-align: left; font-size: 13px; vertical-align: top; }
    th { background: #fafafa; position: sticky; top: 0; z-index: 1; }
    .muted { color: #777; font-size: 12px; }
    .tag { display:inline-block; padding: 2px 10px; border-radius: 999px; background: #f5f5f5; color: #333; font-weight: 700; font-size: 12px; }
    .tag.yes { background: #e6f7ff; color:#1890ff; }

    .photo {
      width: 46px;
      height: 46px;
      border-radius: 6px;
      object-fit: cover;
      border: 1px solid #eee;
      background: #fafafa;
    }

    .pager { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }
    .pager a {
      display:inline-block;
      padding: 6px 10px;
      border: 1px solid #d9d9d9;
      border-radius: 6px;
      text-decoration: none;
      color: #333;
      background: #fff;
      font-weight: 600;
    }

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
                <i class="fas fa-clipboard-list"></i> <?php echo htmlspecialchars($page_title); ?>
              </div>
              <div class="muted">查看訪客提交的新生入學基本資料（含照片上傳）。</div>
            </div>
            <div class="muted">共 <?php echo (int)$total; ?> 筆</div>
          </div>
        </div>

        <?php if (!empty($error_message)): ?>
          <div class="panel" style="border-color:#ffccc7; background:#fff2f0; color:#a8071a;">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
          </div>
        <?php endif; ?>

        <div class="panel">
          <form method="GET" class="filters">
            <input name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="搜尋：學號/姓名/班級/身分證號/手機">
            <button type="submit"><i class="fas fa-search"></i> 查詢</button>
            <a class="reset" href="new_student_basic_info_management.php"><i class="fas fa-eraser"></i> 清除</a>
          </form>
        </div>

        <div class="panel" style="padding:0; overflow:auto;">
          <table>
            <thead>
              <tr>
                <th style="min-width:110px;">學號</th>
                <th style="min-width:110px;">姓名</th>
                <th style="min-width:110px;">班級</th>
                <th style="min-width:160px;">建立時間</th>
                <th style="min-width:120px;">操作</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($rows)): ?>
                <tr><td colspan="5" style="padding:18px; text-align:center; color:#666;">目前沒有資料</td></tr>
              <?php else: ?>
                <?php foreach ($rows as $r): ?>
                  <tr>
                    <td><?php echo htmlspecialchars($r['student_no'] ?? ''); ?></td>
                    <td><strong><?php echo htmlspecialchars($r['student_name'] ?? ''); ?></strong></td>
                    <td><?php echo htmlspecialchars($r['class_name'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($r['created_at'] ?? ''); ?></td>
                    <td>
                      <a href="new_student_basic_info_detail.php?id=<?php echo (int)($r['id'] ?? 0); ?>" style="display:inline-block; padding:6px 10px; border:1px solid #1890ff; border-radius:6px; text-decoration:none; color:#1890ff; font-weight:700; background:#fff;">
                        查看詳情
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="panel">
          <?php
            $baseParams = [];
            if ($q !== '') $baseParams['q'] = $q;
            $baseParams['limit'] = (string)$limit;
            $prevOffset = max(0, $offset - $limit);
            $nextOffset = $offset + $limit;
          ?>
          <div class="pager">
            <div class="muted">顯示 <?php echo ($total === 0) ? 0 : ($offset + 1); ?> - <?php echo min($total, $offset + $limit); ?> / <?php echo (int)$total; ?></div>
            <div style="display:flex; gap:10px; align-items:center;">
              <?php if ($offset > 0): ?>
                <?php $p = http_build_query(array_merge($baseParams, ['offset' => (string)$prevOffset])); ?>
                <a href="new_student_basic_info_management.php?<?php echo htmlspecialchars($p); ?>"><i class="fas fa-chevron-left"></i> 上一頁</a>
              <?php endif; ?>
              <?php if ($nextOffset < $total): ?>
                <?php $p2 = http_build_query(array_merge($baseParams, ['offset' => (string)$nextOffset])); ?>
                <a href="new_student_basic_info_management.php?<?php echo htmlspecialchars($p2); ?>">下一頁 <i class="fas fa-chevron-right"></i></a>
              <?php endif; ?>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</body>
</html>


