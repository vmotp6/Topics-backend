<?php
/**
 * 畢業生彙整並寄送給科系主任
 * 系統依科系彙整畢業生名單，寄送給該科系主任，請主任找指導老師填寫榮譽成就或升學就讀大學。
 */
require_once __DIR__ . '/session_config.php';
checkBackendLogin();

$user_role = $_SESSION['role'] ?? '';
$allowed = ['STA', '行政人員', '學校行政人員', 'ADM', '管理員'];
if (!in_array($user_role, $allowed, true)) {
  http_response_code(403);
  echo '權限不足：僅招生中心（STA）或管理員可使用。';
  exit;
}

$config_path = __DIR__ . '/config.php';
if (!file_exists($config_path)) {
  $alt_paths = [
    __DIR__ . '/../config.php',
    dirname(__DIR__) . '/../Topics-frontend/frontend/config.php',
    __DIR__ . '/../../Topics-frontend/frontend/config.php'
  ];
  foreach ($alt_paths as $p) {
    if (file_exists($p)) { $config_path = $p; break; }
  }
}
if (!file_exists($config_path)) {
  die('錯誤：找不到資料庫設定檔案 (config.php)');
}
require_once $config_path;

// 郵件函數（多路徑）
$email_loaded = false;
$email_paths = [
  __DIR__ . '/includes/email_functions.php',
  dirname(__DIR__) . '/../Topics-frontend/frontend/includes/email_functions.php',
  __DIR__ . '/../../Topics-frontend/frontend/includes/email_functions.php'
];
foreach ($email_paths as $ep) {
  if (file_exists($ep)) {
    require_once $ep;
    $email_loaded = function_exists('sendEmail');
    break;
  }
}

function hasColumn($conn, $table, $column) {
  if (!$conn) return false;
  $table = trim((string)$table);
  $column = trim((string)$column);
  if ($table === '' || $column === '') return false;
  try {
    $stmt = $conn->prepare("
      SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
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

function detectFirstExistingColumn($conn, $table, $candidates) {
  if (!is_array($candidates)) return '';
  foreach ($candidates as $col) {
    if (hasColumn($conn, $table, $col)) return (string)$col;
  }
  return '';
}

// 學年度：7月1日～隔年8月1日為一年。110學年 = 2021/07/01 ~ 2022/08/01；110年入學到今年(2026)8月才畢業
function getAcademicYearRangeByRoc($roc_year) {
  $start_west = $roc_year + 1911;      // 110 → 2021
  $end_west = $roc_year + 1912;        // 110學年結束年 = 2022
  return [
    'start' => $start_west . '-07-01 00:00:00',
    'end' => $end_west . '-08-01 23:59:59'
  ];
}

// 簡易 xlsx 產生（ZipArchive），供附件用
function build_graduated_xlsx($rows, $output_path) {
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

$page_title = '畢業生彙整寄送科系主任';
$error_message = '';
$result_log = [];
$sent_count = 0;
$skip_no_email = [];
$dry_run = !$email_loaded;
$graduated_by_dept = [];
$do_send = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_send']));
$already_sent = []; // 本年度已寄送科系（key = department_code）
$graduation_roc_year = 0;
$roc_enroll_year = 0;
$year_range = ['start' => '', 'end' => ''];

// 畢業生寄送記錄表：避免同一年度同一科系重複寄送
function ensureGraduatedDirectorEmailLogTable($conn) {
  $sql = "CREATE TABLE IF NOT EXISTS graduated_director_email_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    graduation_roc_year INT NOT NULL COMMENT '畢業民國年（例：115=今年畢業）',
    department_code VARCHAR(50) NOT NULL,
    sent_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_year_dept (graduation_roc_year, department_code)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  $conn->query($sql);
}

try {
  $conn = getDatabaseConnection();
  if (!$conn) throw new Exception('資料庫連接失敗');
  ensureGraduatedDirectorEmailLogTable($conn);

  $dept_col = detectFirstExistingColumn($conn, 'new_student_basic_info', [
    'department_id', 'department', 'department_code', 'dept_code', 'dept'
  ]);
  $dept_join = '';
  $dept_select = "'' AS department_code, '' AS department_name";
  if ($dept_col !== '') {
    $dept_key = '';
    if (hasColumn($conn, 'departments', 'code')) $dept_key = 'code';
    elseif (hasColumn($conn, 'departments', 'id')) $dept_key = 'id';
    if ($dept_key !== '' && hasColumn($conn, 'departments', 'name')) {
      $dept_select = "s.`$dept_col` AS department_code, COALESCE(d.name,'') AS department_name";
      if ($dept_key === 'code') {
        $dept_join = " LEFT JOIN departments d ON s.`$dept_col` COLLATE utf8mb4_unicode_ci = d.`$dept_key` COLLATE utf8mb4_unicode_ci ";
      } else {
        $dept_join = " LEFT JOIN departments d ON s.`$dept_col` = d.`$dept_key` ";
      }
    }
  }

  // 本屆畢業：學年度 7/1～隔年8/1 為一年；110年入學到今年(例2026)8月才畢業 → 以建立時間為準
  $grad_year_west = (int)date('Y');
  $roc_enroll_year = $grad_year_west - 1916; // 入學民國年，例：2026-1916=110
  $year_range = getAcademicYearRangeByRoc($roc_enroll_year); // 110學年 = 2021/07/01 ~ 2022/08/01

  $order_col = ($dept_col !== '') ? "s.`$dept_col`" : "s.student_no";
  $sql = "SELECT s.id, s.student_no, s.student_name, s.class_name, s.created_at, COALESCE(s.status,'在學') AS status, $dept_select
          FROM new_student_basic_info s $dept_join
          WHERE s.created_at >= ? AND s.created_at <= ?
          ORDER BY $order_col, s.student_no";
  $stmt = $conn->prepare($sql);
  if (!$stmt) throw new Exception('查詢新生資料失敗：' . ($conn->error ?: 'prepare 失敗'));
  $stmt->bind_param('ss', $year_range['start'], $year_range['end']);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($res === false) throw new Exception('查詢新生資料失敗：' . ($conn->error ?: 'execute 失敗'));

  $graduated_by_dept = [];
  while ($row = $res->fetch_assoc()) {
    $code = trim((string)($row['department_code'] ?? ''));
    $name = trim((string)($row['department_name'] ?? ''));
    $key = $code !== '' ? $code : ($name !== '' ? $name : '_');
    if (!isset($graduated_by_dept[$key])) {
      $graduated_by_dept[$key] = ['code' => $code, 'name' => $name ?: $code ?: '未分科系', 'students' => []];
    }
    $graduated_by_dept[$key]['students'][] = $row;
  }
  $stmt->close();

  // 查詢今年各科系是否已寄送過
  $graduation_roc_year = $roc_enroll_year + 5; // 畢業民國年 = 入學+5，例：110+5=115
  $already_sent = [];
  $chk = $conn->prepare("SELECT department_code FROM graduated_director_email_log WHERE graduation_roc_year = ?");
  if ($chk) {
    $chk->bind_param('i', $graduation_roc_year);
    $chk->execute();
    $cr = $chk->get_result();
    if ($cr) { while ($r = $cr->fetch_assoc()) { $already_sent[trim((string)($r['department_code'] ?? ''))] = true; } }
    $chk->close();
  }

  $director_table_exists = false;
  $tables = $conn->query("SHOW TABLES LIKE 'director'");
  if ($tables && $tables->num_rows > 0) $director_table_exists = true;

  $force_resend = isset($_POST['force_resend']);
  foreach ($graduated_by_dept as $key => $dept) {
    $dept_name = $dept['name'];
    $dept_code = $dept['code'];
    $students = $dept['students'];
    if (empty($students)) continue;

    $log_key = ($dept_code !== '') ? $dept_code : $key;
    $was_sent = isset($already_sent[$log_key]);

    $directors = [];
    if ($director_table_exists && hasColumn($conn, 'user', 'email')) {
      $dir_sql = "SELECT u.id, u.name, u.email FROM director d INNER JOIN user u ON d.user_id = u.id WHERE d.department = ? OR d.department = ?";
      $dir_stmt = $conn->prepare($dir_sql);
      if ($dir_stmt) {
        $dir_stmt->bind_param('ss', $dept_code, $dept_name);
        $dir_stmt->execute();
        $dr = $dir_stmt->get_result();
        while ($r = $dr->fetch_assoc()) { $directors[] = $r; }
        $dir_stmt->close();
      }
    }

    $table_rows = '';
    foreach ($students as $s) {
      $table_rows .= '<tr><td>' . htmlspecialchars($s['student_no'] ?? '') . '</td><td>' . htmlspecialchars($s['student_name'] ?? '') . '</td><td>' . htmlspecialchars($s['class_name'] ?? '') . '</td></tr>';
    }
    $body = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family: sans-serif;">';
    $body .= '<h2>【畢業生彙整】' . htmlspecialchars($dept_name) . ' 畢業生名單</h2>';
    $body .= '<p>以下為本屆畢業生（學年度 7/1～隔年 8/1 為一年，以建立時間判定），名單詳見附件 Excel，請主任協助找指導老師填寫：</p>';
    $body .= '<ul><li>榮譽成就</li><li>升學就讀大學</li></ul>';
    $body .= '<table border="1" cellpadding="8" cellspacing="0" style="border-collapse: collapse;"><thead><tr><th>學號</th><th>姓名</th><th>班級</th></tr></thead><tbody>' . $table_rows . '</tbody></table>';
    $body .= '<p style="margin-top:20px; color:#666;">此信由系統自動發送，請勿直接回覆。</p></body></html>';
    $altBody = "【畢業生彙整】{$dept_name} 畢業生名單（詳見附件 Excel）\n\n請找指導老師填寫榮譽成就或升學就讀大學。";

    $subject = '【畢業生彙整】' . $dept_name . ' 畢業生名單 - 請填寫榮譽成就與升學資訊';

    if (empty($directors)) {
      $skip_no_email[] = $dept_name . '（無主任或主任未設定 Email）';
      $result_log[] = $dept_name . '：' . count($students) . ' 人，未寄送（無主任 Email）';
      continue;
    }

    if ($do_send && ($force_resend || !$was_sent)) {
      $excel_path = '';
      $attachments = [];
      $excel_rows = [['學號', '姓名', '班級', '科系', '建立時間']];
      foreach ($students as $s) {
        $excel_rows[] = [
          $s['student_no'] ?? '',
          $s['student_name'] ?? '',
          $s['class_name'] ?? '',
          $dept_name,
          $s['created_at'] ?? ''
        ];
      }
      $tmp = tempnam(sys_get_temp_dir(), 'grad_excel_');
      if ($tmp !== false) {
        $excel_path = $tmp . '.xlsx';
        @rename($tmp, $excel_path);
        if (build_graduated_xlsx($excel_rows, $excel_path)) {
          $attachments[] = [
            'path' => $excel_path,
            'name' => '畢業生名單_' . preg_replace('/[^\p{L}\p{N}\-_]/u', '_', $dept_name) . '_' . date('Ymd') . '.xlsx',
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
          ];
        }
      }

      $sent_this = 0;
      foreach ($directors as $dir) {
        $to = trim((string)($dir['email'] ?? ''));
        if ($to === '') continue;
        if ($email_loaded) {
          $ok = @sendEmail($to, $subject, $body, $altBody, $attachments);
          if ($ok) { $sent_this++; $sent_count++; }
        } else {
          $result_log[] = $dept_name . '：若已設定郵件函數，將寄至 ' . $to . '（含 Excel 附件）';
        }
      }
      if ($excel_path !== '' && file_exists($excel_path)) @unlink($excel_path);

      if ($sent_this > 0) {
        $result_log[] = $dept_name . '：' . count($students) . ' 人，已寄送 ' . $sent_this . ' 封給主任（含 Excel 附件）';
        $ins = $conn->prepare("INSERT INTO graduated_director_email_log (graduation_roc_year, department_code, sent_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE sent_at = NOW()");
        if ($ins) {
          $ins->bind_param('is', $graduation_roc_year, $log_key);
          $ins->execute();
          $ins->close();
        }
      } elseif ($email_loaded && !empty($directors)) {
        $skip_no_email[] = $dept_name . '（主任 Email 為空）';
      }
    } elseif ($was_sent && !$force_resend) {
      $result_log[] = $dept_name . '：' . count($students) . ' 人，本年度已寄送過，略過（可勾選「仍要再寄」強制重寄）';
    }
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
    .main-content { flex: 1; margin-left: 250px; padding: 24px; }
    .card { background: #fff; border-radius: 10px; padding: 20px; margin-bottom: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
    .muted { color: #666; font-size: 14px; }
    .success { color: #52c41a; }
    .warning { color: #fa8c16; }
    ul { margin: 8px 0; padding-left: 20px; }
  </style>
</head>
<body>
  <div class="dashboard">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="main-content">
      <?php include __DIR__ . '/header.php'; ?>
      <div class="content">
        <div class="card">
          <h2 style="margin-bottom:8px;"><i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($page_title); ?></h2>
          <p class="muted">學年度以<strong>7月1日～隔年8月1日</strong>為一年，以<strong>建立時間</strong>判定入學年度（最準確）；110年入學到今年8月才畢業。依科系彙整後以<strong>Excel 附件</strong>寄送給該科系主任，請主任找指導老師填寫榮譽成就或升學就讀大學。系統會記錄本年度是否已寄送，避免重複寄送。</p>
        </div>

        <?php if ($error_message): ?>
          <div class="card" style="border-left:4px solid #f5222d;">
            <strong>錯誤</strong>：<?php echo htmlspecialchars($error_message); ?>
          </div>
        <?php endif; ?>

        <?php if ($dry_run && empty($error_message)): ?>
          <div class="card" style="border-left:4px solid #fa8c16;">
            <strong>說明</strong>：未載入郵件函數（includes/email_functions.php），本次僅預覽，未實際寄信。請設定 <code>sendEmail</code> 後再執行。
          </div>
        <?php endif; ?>

        <?php if (!empty($graduated_by_dept)): ?>
          <div class="card">
            <h3 style="margin-bottom:12px;">預覽：依科系彙整之畢業生</h3>
            <p class="muted">以下為<strong>本屆畢業</strong>學生（建立時間落在民國 <?php echo $roc_enroll_year; ?> 學年度：<?php echo substr($year_range['start'], 0, 10); ?>～<?php echo substr($year_range['end'], 0, 10); ?>），按科系彙整。寄送時會附 Excel 名單；已寄送過的科系本年度不會重複寄送，可勾選「仍要再寄」強制重寄。</p>
            <table border="1" cellpadding="10" cellspacing="0" style="border-collapse: collapse; width:100%; margin-top:12px;">
              <thead><tr><th>科系</th><th>畢業生人數</th><th>本年度寄送狀態</th></tr></thead>
              <tbody>
                <?php
                foreach ($graduated_by_dept as $key => $d):
                  $log_key = ($d['code'] !== '') ? $d['code'] : $key;
                  $sent = isset($already_sent[$log_key]);
                ?>
                  <tr>
                    <td><?php echo htmlspecialchars($d['name']); ?></td>
                    <td><?php echo count($d['students']); ?></td>
                    <td><?php echo $sent ? '<span style="color:#52c41a;">已寄送</span>' : '<span style="color:#999;">未寄送</span>'; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <?php if (!$do_send): ?>
              <form method="post" style="margin-top:16px;">
                <input type="hidden" name="confirm_send" value="1">
                <label style="display:inline-flex; align-items:center; gap:8px; margin-bottom:10px;">
                  <input type="checkbox" name="force_resend" value="1"> 仍要再寄（含已寄送過的科系）
                </label>
                <br>
                <button type="submit" style="padding:10px 20px; background:#52c41a; color:#fff; border:none; border-radius:6px; cursor:pointer;"><i class="fas fa-paper-plane"></i> 確認寄送給各科系主任（附 Excel）</button>
              </form>
            <?php endif; ?>
          </div>
        <?php elseif (empty($error_message)): ?>
          <div class="card">
            <p class="muted">目前無<strong>本屆畢業</strong>之學生（建立時間須落在民國 <?php echo (int)date('Y') - 1916; ?> 學年度：<?php echo (int)date('Y') - 1916; ?>年7/1～<?php echo (int)date('Y') - 1915; ?>年8/1，學年度以 7/1～隔年8/1 為一年）。</p>
          </div>
        <?php endif; ?>

        <?php if (!empty($result_log)): ?>
          <div class="card">
            <h3 style="margin-bottom:12px;">執行結果</h3>
            <?php if ($sent_count > 0): ?>
              <p class="success"><i class="fas fa-check-circle"></i> 已寄送共 <?php echo (int)$sent_count; ?> 封郵件。</p>
            <?php endif; ?>
            <ul>
              <?php foreach ($result_log as $log): ?>
                <li><?php echo htmlspecialchars($log); ?></li>
              <?php endforeach; ?>
            </ul>
            <?php if (!empty($skip_no_email)): ?>
              <p class="warning" style="margin-top:12px;">以下科系未寄送（無主任或主任未設定 Email）：</p>
              <ul>
                <?php foreach ($skip_no_email as $s): ?>
                  <li><?php echo htmlspecialchars($s); ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="card">
          <a href="new_student_basic_info_management.php?view=legacy" style="display:inline-block; padding:8px 16px; background:#1890ff; color:#fff; border-radius:6px; text-decoration:none;">返回新生基本資料管理</a>
          &nbsp;
          <a href="send_graduated_students_to_directors.php" style="display:inline-block; padding:8px 16px; border:1px solid #d9d9d9; border-radius:6px; text-decoration:none; color:#333;">重新彙整／寄送</a>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
