<?php
require_once __DIR__ . '/session_config.php';

checkBackendLogin();

// 引用設定檔和資料庫連接
require_once __DIR__ . '/../../Topics-frontend/frontend/config.php'; 

$conn = getDatabaseConnection();
$message = '';
$message_type = '';

// --- 處理表單提交 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            // 新增欄位
            case 'add_field':
                $stmt = $conn->prepare(
                    "INSERT INTO admission_form_fields (field_name, field_label, field_type, placeholder, is_required, sort_order, field_options) VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $is_required = isset($_POST['is_required']) ? 1 : 0;
                $stmt->bind_param("ssssiis", 
                    $_POST['field_name'], 
                    $_POST['field_label'], 
                    $_POST['field_type'], 
                    $_POST['placeholder'], 
                    $is_required, 
                    $_POST['sort_order'],
                    $_POST['field_options']
                );
                $stmt->execute();
                $message = "新欄位已成功新增！";
                $message_type = 'success';
                break;

            // 更新欄位
            case 'update_field':
                $stmt = $conn->prepare(
                    "UPDATE admission_form_fields SET field_label = ?, field_type = ?, placeholder = ?, is_required = ?, is_active = ?, sort_order = ?, field_options = ? WHERE id = ?"
                );
                $is_required = isset($_POST['is_required']) ? 1 : 0;
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                $stmt->bind_param("sssiisi", 
                    $_POST['field_label'], 
                    $_POST['field_type'], 
                    $_POST['placeholder'], 
                    $is_required, 
                    $is_active,
                    $_POST['sort_order'],
                    $_POST['field_options'],
                    $_POST['field_id']
                );
                $stmt->execute();
                $message = "欄位已成功更新！";
                $message_type = 'success';
                break;

            // 刪除欄位
            case 'delete_field':
                // 增加 is_core = 0 的條件，防止刪除核心欄位
                $stmt = $conn->prepare("DELETE FROM admission_form_fields WHERE id = ? AND is_core = 0");
                $stmt->bind_param("i", $_POST['field_id']);
                $stmt->execute();
                if ($stmt->affected_rows > 0) {
                    $message = "欄位已成功刪除！";
                    $message_type = 'success';
                } else {
                    $message = "刪除失敗！可能因為這是核心欄位或欄位不存在。";
                    $message_type = 'error';
                }
                break;
        }
    } catch (Exception $e) {
        $message = "操作失敗：" . $e->getMessage();
        $message_type = 'error';
    }
}

// --- 獲取資料 ---
$fields = [];
$editing_field = null;

// 獲取所有欄位用於列表顯示
$result = $conn->query("SELECT * FROM admission_form_fields ORDER BY sort_order ASC");
while ($row = $result->fetch_assoc()) {
    $fields[] = $row;
}

// 如果是編輯模式，獲取特定欄位的資料
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $stmt = $conn->prepare("SELECT * FROM admission_form_fields WHERE id = ?");
    $stmt->bind_param("i", $_GET['id']);
    $stmt->execute();
    $edit_result = $stmt->get_result();
    if ($edit_result->num_rows > 0) {
        $editing_field = $edit_result->fetch_assoc();
    } else {
        $message = "找不到要編輯的欄位。";
        $message_type = 'error';
    }
}

$conn->close();
?>

<?php include 'header.php'; ?>

<div class="container mt-4">
    <h2>編輯入學申請表單欄位</h2>
    <p>您可以在這裡新增、修改或刪除「入學申請」頁面的表單欄位。</p>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'danger'; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <!-- 編輯或新增表單 -->
    <div class="card mb-4">
        <div class="card-header">
            <?php echo $editing_field ? '正在編輯欄位：' . htmlspecialchars($editing_field['field_label']) : '新增欄位'; ?>
        </div>
        <div class="card-body">
            <form method="POST" action="edit_admission.php">
                <input type="hidden" name="action" value="<?php echo $editing_field ? 'update_field' : 'add_field'; ?>">
                <?php if ($editing_field): ?>
                    <input type="hidden" name="field_id" value="<?php echo $editing_field['id']; ?>">
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="field_label" class="form-label">欄位標籤 (顯示名稱)</label>
                        <input type="text" class="form-control" id="field_label" name="field_label" value="<?php echo htmlspecialchars($editing_field['field_label'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="field_name" class="form-label">欄位名稱 (程式用，英文)</label>
                        <input type="text" class="form-control" id="field_name" name="field_name" value="<?php echo htmlspecialchars($editing_field['field_name'] ?? ''); ?>" <?php echo $editing_field ? 'readonly' : 'required'; ?>>
                        <?php if ($editing_field): ?>
                            <small class="form-text text-muted">核心欄位或已建立的欄位名稱不可修改。</small>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="field_type" class="form-label">欄位類型</label>
                        <select class="form-select" id="field_type" name="field_type">
                            <?php 
                            $types = ['text', 'email', 'tel', 'textarea', 'select'];
                            foreach ($types as $type) {
                                $selected = ($editing_field['field_type'] ?? '') === $type ? 'selected' : '';
                                echo '<option value="' . htmlspecialchars($type) . '" ' . $selected . '>' . htmlspecialchars($type) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="sort_order" class="form-label">顯示順序</label>
                        <input type="number" class="form-control" id="sort_order" name="sort_order" value="<?php echo htmlspecialchars($editing_field['sort_order'] ?? '0'); ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label for="placeholder" class="form-label">預設提示文字 (Placeholder)</label>
                    <input type="text" class="form-control" id="placeholder" name="placeholder" value="<?php echo htmlspecialchars($editing_field['placeholder'] ?? ''); ?>">
                </div>

                <div class="mb-3">
                    <label for="field_options" class="form-label">選項 (用於 select 類型)</label>
                    <input type="text" class="form-control" id="field_options" name="field_options" value="<?php echo htmlspecialchars($editing_field['field_options'] ?? ''); ?>">
                    <small class="form-text text-muted">請用逗號 (,) 分隔多個選項。例如：選項一,選項二,選項三</small>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="is_required" name="is_required" value="1" <?php echo ($editing_field['is_required'] ?? 0) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="is_required">
                        此為必填欄位
                    </label>
                </div>

                <?php if ($editing_field): ?>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?php echo ($editing_field['is_active'] ?? 0) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="is_active">
                        啟用此欄位
                    </label>
                </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary"><?php echo $editing_field ? '更新欄位' : '新增欄位'; ?></button>
                <?php if ($editing_field): ?>
                    <a href="edit_admission.php" class="btn btn-secondary">取消編輯</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- 欄位列表 -->
    <div class="card">
        <div class="card-header">
            現有欄位列表
        </div>
        <div class="card-body">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>順序</th>
                        <th>標籤</th>
                        <th>名稱 (程式用)</th>
                        <th>類型</th>
                        <th>必填</th>
                        <th>啟用</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fields as $field): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($field['sort_order']); ?></td>
                            <td><?php echo htmlspecialchars($field['field_label']); ?></td>
                            <td><code><?php echo htmlspecialchars($field['field_name']); ?></code></td>
                            <td><?php echo htmlspecialchars($field['field_type']); ?></td>
                            <td><?php echo $field['is_required'] ? '<span class="badge bg-success">是</span>' : '<span class="badge bg-secondary">否</span>'; ?></td>
                            <td><?php echo $field['is_active'] ? '<span class="badge bg-success">是</span>' : '<span class="badge bg-secondary">否</span>'; ?></td>
                            <td>
                                <a href="?action=edit&id=<?php echo $field['id']; ?>" class="btn btn-sm btn-outline-primary">編輯</a>
                                <?php if (!$field['is_core']): ?>
                                    <form method="POST" action="edit_admission.php" onsubmit="return confirm('您確定要刪除這個欄位嗎？');" style="display: inline-block;">
                                        <input type="hidden" name="action" value="delete_field">
                                        <input type="hidden" name="field_id" value="<?php echo $field['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">刪除</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
