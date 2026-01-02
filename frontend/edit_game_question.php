<?php
require_once __DIR__ . '/session_config.php';

checkBackendLogin();

// 獲取使用者角色並統一代碼
$user_role = $_SESSION['role'] ?? '';
$role_map = [
    '管理員' => 'ADM',
    'admin' => 'ADM',
    '行政人員' => 'STA',
    '學校行政人員' => 'STA'
];
if (isset($role_map[$user_role])) {
    $user_role = $role_map[$user_role];
}

// 檢查權限：只有管理員和行政人員可以訪問
$is_admin = ($user_role === 'ADM');
$is_staff = ($user_role === 'STA');

if (!($is_admin || $is_staff)) {
    header("Location: index.php");
    exit;
}

require_once '../../Topics-frontend/frontend/config.php';
$conn = getDatabaseConnection();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$data = ['category'=>'fight', 'is_active'=>1, 'difficulty'=>1]; // 預設值

if ($id > 0) {
    $stmt = $conn->prepare("SELECT * FROM game_questions WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cat = $_POST['category'];
    $q = $_POST['question'];
    $oa = $_POST['option_a'];
    $ob = $_POST['option_b'];
    $oc = $_POST['option_c'];
    $od = $_POST['option_d'];
    $correct = $_POST['correct_option'];
    $exp = $_POST['explanation'];
    $diff = intval($_POST['difficulty']);
    $active = isset($_POST['is_active']) ? 1 : 0;

    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE game_questions SET category=?, question=?, option_a=?, option_b=?, option_c=?, option_d=?, correct_option=?, explanation=?, difficulty=?, is_active=? WHERE id=?");
        $stmt->bind_param("ssssssssiii", $cat, $q, $oa, $ob, $oc, $od, $correct, $exp, $diff, $active, $id);
    } else {
        $stmt = $conn->prepare("INSERT INTO game_questions (category, question, option_a, option_b, option_c, option_d, correct_option, explanation, difficulty, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssssii", $cat, $q, $oa, $ob, $oc, $od, $correct, $exp, $diff, $active);
    }
    
    if ($stmt->execute()) {
        header("Location: game_management.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $id ? '編輯' : '新增' ?>題目</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f8f9fa; margin: 0; }
        .main-content { margin-left: 250px; padding: 30px; }
        .card { background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); max-width: 800px; margin: 0 auto; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; color: #333; }
        input[type="text"], select, textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .btn { padding: 12px 24px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; color:white; }
        .btn-primary { background: #4361ee; }
        .btn-secondary { background: #e9ecef; color: #333; text-decoration: none; display:inline-block; text-align:center;}
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content">
            <div class="card">
                <h2><?= $id ? '編輯' : '新增' ?>題目</h2>
                <form method="POST">
                    <div class="row">
                        <div class="form-group">
                            <label>遊戲類型 (Category)</label>
                            <select name="category" id="category" required onchange="toggleExplanation()">
                                <option value="fight" <?= $data['category']=='fight'?'selected':'' ?>>🥊 格鬥問答</option>
                                <option value="nursing" <?= $data['category']=='nursing'?'selected':'' ?>>💉 護理科互動</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>難度 (Difficulty)</label>
                            <select name="difficulty">
                                <option value="1" <?= ($data['difficulty']??1)==1?'selected':'' ?>>簡單</option>
                                <option value="2" <?= ($data['difficulty']??1)==2?'selected':'' ?>>中等</option>
                                <option value="3" <?= ($data['difficulty']??1)==3?'selected':'' ?>>困難</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>題目內容</label>
                        <textarea name="question" rows="3" required><?= htmlspecialchars($data['question'] ?? '') ?></textarea>
                    </div>

                    <div class="row">
                        <div class="form-group"><label>選項 A</label><input type="text" name="option_a" required value="<?= htmlspecialchars($data['option_a'] ?? '') ?>"></div>
                        <div class="form-group"><label>選項 B</label><input type="text" name="option_b" required value="<?= htmlspecialchars($data['option_b'] ?? '') ?>"></div>
                    </div>
                    <div class="row">
                        <div class="form-group"><label>選項 C</label><input type="text" name="option_c" required value="<?= htmlspecialchars($data['option_c'] ?? '') ?>"></div>
                        <div class="form-group"><label>選項 D</label><input type="text" name="option_d" required value="<?= htmlspecialchars($data['option_d'] ?? '') ?>"></div>
                    </div>

                    <div class="form-group">
                        <label>正確答案</label>
                        <select name="correct_option" required>
                            <?php foreach(['A','B','C','D'] as $opt): ?>
                                <option value="<?= $opt ?>" <?= ($data['correct_option']??'')==$opt?'selected':'' ?>>選項 <?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" id="explanationGroup" style="display:none;">
                        <label>題目解析 (護理科用)</label>
                        <textarea name="explanation" rows="3"><?= htmlspecialchars($data['explanation'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label><input type="checkbox" name="is_active" value="1" <?= ($data['is_active']??1)?'checked':'' ?>> 啟用此題目</label>
                    </div>

                    <div style="margin-top:30px; display:flex; gap:10px;">
                        <button type="submit" class="btn btn-primary">儲存設定</button>
                        <a href="game_management.php" class="btn btn-secondary">取消</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
        function toggleExplanation() {
            const cat = document.getElementById('category').value;
            document.getElementById('explanationGroup').style.display = (cat === 'nursing') ? 'block' : 'none';
        }
        toggleExplanation();
    </script>
</body>
</html>