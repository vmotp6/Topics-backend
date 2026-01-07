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

// 引入資料庫設定
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
  if (preg_match('/^(https?:\\/\\/|\\/)/i', $p)) return $p;
  return '/Topics-frontend/frontend/' . ltrim($p, '/');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  http_response_code(400);
  echo '缺少 id';
  exit;
}

$page_title = '新生基本資料詳情';
$row = null;
$error_message = '';

try {
  $conn = getDatabaseConnection();
  if (!$conn) throw new Exception('資料庫連接失敗');
  ensureNewStudentBasicInfoTable($conn);

  $stmt = $conn->prepare("SELECT
      id, student_no, student_name, class_name, enrollment_identity, birthday, gender, id_number, mobile, address, previous_school, photo_path,
      parent_title, parent_name, parent_birth_year, parent_occupation, parent_phone, parent_education,
      guardian_relation, guardian_name, guardian_phone, guardian_mobile, guardian_line, guardian_email,
      emergency_name, emergency_phone, emergency_mobile,
      is_indigenous, is_new_immigrant_child, is_overseas_chinese,
      DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at
    FROM new_student_basic_info WHERE id = ? LIMIT 1");
  if (!$stmt) throw new Exception('查詢準備失敗');
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  $conn->close();

  if (!$row) throw new Exception('找不到資料');
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

    /* 參考前台 new_student_basic_info.php 風格的只讀展示 */
    .hero {
      background: #b3caebff;
      border-radius: 18px;
      padding: 22px 18px;
      box-shadow: 0 10px 24px rgba(100, 120, 224, 0.14);
      margin-bottom: 14px;
      text-align: center;
      color: #fff;
    }
    .hero h2 { margin: 0; font-size: 28px; font-weight: 900; }
    .hero p { margin: 8px 0 0 0; font-size: 14px; color: rgba(34, 32, 32, 0.92); line-height: 1.7; }

    .card {
      background: #fff;
      border: 1px solid #e9ecef;
      border-radius: 12px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.06);
      padding: 20px;
    }

    .section-title {
      margin: 16px 0 10px 0;
      font-size: 16px;
      font-weight: 900;
      color: #003366;
      display: flex;
      gap: 8px;
      align-items: center;
    }

    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
    .field label { display:block; font-size: 14px; color:#666; margin-bottom: 6px; font-weight: 800; }
    .value {
      border: 1px solid #e0e0e0;
      background: #fff;
      border-radius: 0;
      padding: 10px 12px;
      font-size: 16px;
      color: #333;
      min-height: 42px;
      box-sizing: border-box;
      white-space: pre-wrap;
    }

    .tag { display:inline-block; padding: 2px 10px; border-radius: 999px; background: #f5f5f5; color: #333; font-weight: 700; font-size: 12px; }
    .tag.yes { background: #e6f7ff; color:#1890ff; }
    .muted { color: #777; font-size: 12px; }

    .photo {
      width: 120px;
      height: 160px;
      border: 1px solid #eee;
      object-fit: cover;
      background: #fafafa;
    }

    .back {
      display:inline-flex; gap:8px; align-items:center;
      padding: 8px 12px;
      border: 1px solid #d9d9d9;
      border-radius: 6px;
      text-decoration: none;
      color: #333;
      background: #fff;
      font-weight: 700;
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

        <div style="margin-bottom:12px;">
          <a class="back" href="new_student_basic_info_management.php"><i class="fas fa-arrow-left"></i> 返回列表</a>
        </div>

        <section class="hero">
          <h2>新生入學基本資料詳情</h2>
          <p>建立時間：<?php echo htmlspecialchars($row['created_at'] ?? ''); ?></p>
        </section>

        <?php if (!empty($error_message)): ?>
          <div class="card" style="border-color:#ffccc7; background:#fff2f0; color:#a8071a;">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
          </div>
        <?php else: ?>
          <div class="card">
            <div class="section-title"><i class="fas fa-id-card"></i> 新生入學基本資料</div>
            <div class="grid">
              <div class="field"><label>學號</label><div class="value"><?php echo htmlspecialchars($row['student_no'] ?? ''); ?></div></div>
              <div class="field"><label>姓名</label><div class="value"><?php echo htmlspecialchars($row['student_name'] ?? ''); ?></div></div>
              <div class="field"><label>班級</label><div class="value"><?php echo htmlspecialchars($row['class_name'] ?? ''); ?></div></div>
              <div class="field"><label>在學身分</label><div class="value"><?php echo htmlspecialchars($row['enrollment_identity'] ?? ''); ?></div></div>
              <div class="field"><label>生日</label><div class="value"><?php echo htmlspecialchars($row['birthday'] ?? ''); ?></div></div>
              <div class="field"><label>性別</label><div class="value"><?php echo htmlspecialchars($row['gender'] ?? ''); ?></div></div>
              <div class="field"><label>身分證號</label><div class="value"><?php echo htmlspecialchars($row['id_number'] ?? ''); ?></div></div>
              <div class="field"><label>手機</label><div class="value"><?php echo htmlspecialchars($row['mobile'] ?? ''); ?></div></div>
              <div class="field" style="grid-column: 1 / -1;"><label>通訊地址</label><div class="value"><?php echo htmlspecialchars($row['address'] ?? ''); ?></div></div>
              <div class="field"><label>前一學校</label><div class="value"><?php echo htmlspecialchars($row['previous_school'] ?? ''); ?></div></div>
              <div class="field">
                <label>2 吋照片</label>
                <div class="value" style="display:flex; align-items:center; gap:12px;">
                  <?php $p = photoUrl($row['photo_path'] ?? ''); ?>
                  <?php if ($p !== ''): ?>
                    <a href="<?php echo htmlspecialchars($p); ?>" target="_blank" rel="noopener">
                      <img class="photo" src="<?php echo htmlspecialchars($p); ?>" alt="photo">
                    </a>
                    <div class="muted">點照片可開新視窗</div>
                  <?php else: ?>
                    <div class="muted">無</div>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <div class="section-title"><i class="fas fa-user-friends"></i> 家長或監護人資訊</div>
            <div class="grid">
              <div class="field"><label>稱謂</label><div class="value"><?php echo htmlspecialchars($row['parent_title'] ?? ''); ?></div></div>
              <div class="field"><label>姓名</label><div class="value"><?php echo htmlspecialchars($row['parent_name'] ?? ''); ?></div></div>
              <div class="field"><label>年次</label><div class="value"><?php echo htmlspecialchars($row['parent_birth_year'] ?? ''); ?></div></div>
              <div class="field"><label>職業</label><div class="value"><?php echo htmlspecialchars($row['parent_occupation'] ?? ''); ?></div></div>
              <div class="field"><label>電話</label><div class="value"><?php echo htmlspecialchars($row['parent_phone'] ?? ''); ?></div></div>
              <div class="field"><label>教育程度</label><div class="value"><?php echo htmlspecialchars($row['parent_education'] ?? ''); ?></div></div>
            </div>

            <div class="section-title"><i class="fas fa-user-shield"></i> 監護人資料</div>
            <div class="grid">
              <div class="field"><label>關係</label><div class="value"><?php echo htmlspecialchars($row['guardian_relation'] ?? ''); ?></div></div>
              <div class="field"><label>姓名</label><div class="value"><?php echo htmlspecialchars($row['guardian_name'] ?? ''); ?></div></div>
              <div class="field"><label>電話</label><div class="value"><?php echo htmlspecialchars($row['guardian_phone'] ?? ''); ?></div></div>
              <div class="field"><label>手機</label><div class="value"><?php echo htmlspecialchars($row['guardian_mobile'] ?? ''); ?></div></div>
              <div class="field"><label>LINE</label><div class="value"><?php echo htmlspecialchars($row['guardian_line'] ?? ''); ?></div></div>
              <div class="field"><label>EMAIL</label><div class="value"><?php echo htmlspecialchars($row['guardian_email'] ?? ''); ?></div></div>
            </div>

            <div class="section-title"><i class="fas fa-phone"></i> 緊急聯絡人</div>
            <div class="grid">
              <div class="field"><label>姓名</label><div class="value"><?php echo htmlspecialchars($row['emergency_name'] ?? ''); ?></div></div>
              <div class="field"><label>電話</label><div class="value"><?php echo htmlspecialchars($row['emergency_phone'] ?? ''); ?></div></div>
              <div class="field"><label>手機</label><div class="value"><?php echo htmlspecialchars($row['emergency_mobile'] ?? ''); ?></div></div>
            </div>

            <div class="section-title"><i class="fas fa-clipboard-check"></i> 個人身分資料</div>
            <div class="grid">
              <div class="field">
                <label>本人是否為原住民</label>
                <div class="value">
                  <span class="tag <?php echo ((int)($row['is_indigenous'] ?? 0) === 1) ? 'yes' : ''; ?>">
                    <?php echo ((int)($row['is_indigenous'] ?? 0) === 1) ? '是' : '否'; ?>
                  </span>
                </div>
              </div>
              <div class="field">
                <label>本人是否為新住民子女</label>
                <div class="value">
                  <span class="tag <?php echo ((int)($row['is_new_immigrant_child'] ?? 0) === 1) ? 'yes' : ''; ?>">
                    <?php echo ((int)($row['is_new_immigrant_child'] ?? 0) === 1) ? '是' : '否'; ?>
                  </span>
                </div>
              </div>
              <div class="field">
                <label>本人是否為僑生</label>
                <div class="value">
                  <span class="tag <?php echo ((int)($row['is_overseas_chinese'] ?? 0) === 1) ? 'yes' : ''; ?>">
                    <?php echo ((int)($row['is_overseas_chinese'] ?? 0) === 1) ? '是' : '否'; ?>
                  </span>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>

      </div>
    </div>
  </div>
</body>
</html>


