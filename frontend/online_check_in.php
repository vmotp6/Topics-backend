<?php
// 線上簽到表單頁面
// 招生中心身份可以編輯表單配置，一般用戶可以填寫表單

// 引入資料庫設定和 session 配置
require_once '../../Topics-frontend/frontend/config.php';
require_once 'session_config.php';

// 獲取場次ID
$session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
if ($session_id === 0) {
    die('錯誤：缺少場次ID');
}

// 檢查是否為招生中心身份（STA 或 STAM）
// 如果 URL 中有 fill_mode 參數，強制顯示填寫模式（用於分享給參與者的連結）
$force_fill_mode = isset($_GET['fill_mode']) && $_GET['fill_mode'] === '1';

$user_role = $_SESSION['role'] ?? '';
// 如果強制填寫模式，或者沒有登入，則顯示填寫模式
$is_admission_center = !$force_fill_mode && !empty($_SESSION['logged_in']) && in_array($user_role, ['STA', 'STAM', '行政人員', '招生中心組員', 'ADM', '管理員']);

// 建立資料庫連接
$conn = getDatabaseConnection();

// 獲取場次資訊
$stmt = $conn->prepare("SELECT * FROM admission_sessions WHERE id = ?");
$stmt->bind_param("i", $session_id);
$stmt->execute();
$session_result = $stmt->get_result();
$session = $session_result->fetch_assoc();
$stmt->close();

if (!$session) {
    die('錯誤：找不到指定的場次');
}

// 檢查是否有表單配置表，如果沒有則創建
$table_check = $conn->query("SHOW TABLES LIKE 'online_check_in_form_config'");
if (!$table_check || $table_check->num_rows == 0) {
    $create_table_sql = "CREATE TABLE IF NOT EXISTS `online_check_in_form_config` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `session_id` int(11) NOT NULL COMMENT '場次ID',
      `field_config` text NOT NULL COMMENT '欄位配置 JSON',
      `check_in_url` varchar(500) DEFAULT NULL COMMENT '簽到連結',
      `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
      `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新時間',
      PRIMARY KEY (`id`),
      UNIQUE KEY `idx_session_id` (`session_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='線上簽到表單配置表'";
    
    $conn->query($create_table_sql);
}

// 獲取或初始化表單配置
$form_config = null;
$check_in_url = null;
$config_stmt = $conn->prepare("SELECT field_config, check_in_url FROM online_check_in_form_config WHERE session_id = ?");
$config_stmt->bind_param("i", $session_id);
$config_stmt->execute();
$config_result = $config_stmt->get_result();
if ($config_result->num_rows > 0) {
    $config_data = $config_result->fetch_assoc();
    $form_config = json_decode($config_data['field_config'], true);
    $check_in_url = $config_data['check_in_url'];
}
$config_stmt->close();

// 如果沒有配置，使用預設配置
if (!$form_config) {
    $form_config = [
        ['name' => 'name', 'label' => '姓名', 'type' => 'text', 'required' => true, 'placeholder' => '請輸入您的姓名'],
        ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => false, 'placeholder' => '請輸入您的Email（選填）'],
        ['name' => 'phone', 'label' => '電話', 'type' => 'tel', 'required' => true, 'placeholder' => '請輸入您的電話號碼'],
        ['name' => 'notes', 'label' => '備註', 'type' => 'textarea', 'required' => false, 'placeholder' => '如有其他需要說明的事項，請在此填寫（選填）']
    ];
}

// 生成簽到連結（如果還沒有）
// 連結必須包含 fill_mode=1 參數，確保一般用戶看到的是填寫表單，而不是編輯表單
if (!$check_in_url) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $script_path = dirname($_SERVER['SCRIPT_NAME']);
    // 添加 fill_mode=1 參數，確保顯示填寫模式
    $check_in_url = $protocol . '://' . $host . $script_path . '/online_check_in.php?session_id=' . $session_id . '&fill_mode=1';
}

$conn->close();

// 設置頁面標題
$page_title = $is_admission_center ? '編輯簽到表單 - ' . htmlspecialchars($session['session_name']) : '線上簽到 - ' . htmlspecialchars($session['session_name']);
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1890ff;
            --success-color: #52c41a;
            --warning-color: #faad14;
            --danger-color: #ff4d4f;
            --text-color: #262626;
            --text-secondary-color: #8c8c8c;
            --border-color: #d9d9d9;
            --background-color: #f0f2f5;
            --card-background-color: #fff;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; 
            background: var(--background-color); 
            color: var(--text-color); 
            padding: 20px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .check-in-container {
            background: var(--card-background-color);
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            padding: 40px;
            max-width: 600px;
            width: 100%;
        }
        
        .check-in-header {
            text-align: center;
            margin-bottom: 32px;
        }
        
        .check-in-header h1 {
            font-size: 28px;
            color: var(--text-color);
            margin-bottom: 8px;
        }
        
        .check-in-header .session-name {
            font-size: 18px;
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .check-in-header .session-date {
            font-size: 14px;
            color: var(--text-secondary-color);
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-color);
        }
        
        .form-label .required {
            color: var(--danger-color);
            margin-left: 4px;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 16px;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.2);
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background: #40a9ff;
        }
        
        .btn-primary:disabled {
            background: #d9d9d9;
            cursor: not-allowed;
        }
        
        .message {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 24px;
            font-weight: 500;
        }
        
        .message.success {
            background: #f6ffed;
            border: 1px solid #b7eb8f;
            color: var(--success-color);
        }
        
        .message.error {
            background: #fff2f0;
            border: 1px solid #ffccc7;
            color: var(--danger-color);
        }
        
        .message.warning {
            background: #fffbe6;
            border: 1px solid #ffe58f;
            color: var(--warning-color);
        }
        
        .info-box {
            background: #e6f7ff;
            border: 1px solid #91d5ff;
            border-radius: 6px;
            padding: 16px;
            margin-bottom: 24px;
        }
        
        .info-box p {
            margin: 0;
            color: #595959;
            line-height: 1.6;
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 20px;
        }
        
        .loading i {
            font-size: 24px;
            color: var(--primary-color);
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .edit-mode {
            display: none;
        }
        
        .fill-mode {
            display: none;
        }
        
        .qrcode-container {
            text-align: center;
            padding: 32px;
            background: #f9f9f9;
            border-radius: 12px;
            margin-top: 24px;
            border: 2px solid var(--primary-color);
        }
        
        .qrcode-container h3 {
            font-size: 24px;
            margin-bottom: 24px;
        }
        
        .qrcode-container canvas {
            margin: 0 auto;
            display: block;
            background: white;
            padding: 16px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .qrcode-link {
            margin-top: 24px;
            padding: 20px;
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            word-break: break-all;
            font-size: 14px;
        }
        
        .qrcode-link a {
            color: white;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .qrcode-link a:hover {
            background: #40a9ff;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        
        .field-editor {
            background: #f9f9f9;
            padding: 16px;
            border-radius: 6px;
            margin-bottom: 16px;
            border: 1px solid var(--border-color);
        }
        
        .field-editor-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .field-editor-header h4 {
            margin: 0;
            font-size: 16px;
        }
        
        .btn-danger {
            background: var(--danger-color);
            color: white;
            padding: 6px 12px;
            font-size: 14px;
            width: auto;
        }
        
        .btn-danger:hover {
            background: #ff7875;
        }
        
        .btn-add-field {
            background: var(--success-color);
            color: white;
            margin-bottom: 16px;
            width: auto;
        }
        
        .btn-add-field:hover {
            background: #73d13d;
        }
        
        .btn-secondary {
            background: #8c8c8c;
            color: white;
            margin-right: 12px;
            width: auto;
        }
        
        .btn-secondary:hover {
            background: #595959;
        }
        
        .btn-group {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            flex-wrap: wrap;
        }
        
        .btn-group .btn {
            flex: 1;
            min-width: 120px;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: auto;
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
</head>
<body>
    <div class="check-in-container">
        <div class="check-in-header">
            <h1><i class="fas fa-check-circle"></i> <?php echo $is_admission_center ? '編輯簽到表單' : '線上簽到'; ?></h1>
            <div class="session-name"><?php echo htmlspecialchars($session['session_name']); ?></div>
            <?php if (!empty($session['session_date'])): ?>
                <div class="session-date">
                    <i class="fas fa-calendar"></i> 
                    <?php echo date('Y年m月d日', strtotime($session['session_date'])); ?>
                </div>
            <?php endif; ?>
        </div>
        
        <div id="messageContainer"></div>
        
        <?php if ($is_admission_center): ?>
            <!-- 編輯模式 -->
            <div class="edit-mode" id="editMode">
                <div class="info-box">
                    <p><i class="fas fa-info-circle"></i> 您可以在此編輯簽到表單的欄位。儲存後將生成 QR code 和連結供參與者使用。</p>
                </div>
                
                <form id="formConfigForm">
                    <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
                    <div id="fieldsContainer">
                        <!-- 欄位將由 JavaScript 動態生成 -->
                    </div>
                    
                    <button type="button" class="btn btn-add-field" id="addFieldBtn">
                        <i class="fas fa-plus"></i> 新增欄位
                    </button>
                    
                    <div class="btn-group">
                        <button type="submit" class="btn btn-primary" id="saveConfigBtn">
                            <i class="fas fa-save"></i> 儲存配置
                        </button>
                        <button type="button" class="btn btn-secondary" id="previewBtn">
                            <i class="fas fa-eye"></i> 預覽表單
                        </button>
                    </div>
                    
                    <div style="margin-top: 16px; text-align: center;">
                        <button type="button" class="btn btn-secondary" id="backToEditBtn" style="display: none; width: auto;">
                            <i class="fas fa-edit"></i> 返回編輯
                        </button>
                    </div>
                </form>
                
                <div id="qrcodeSection" style="display: none;">
                    <div class="qrcode-container">
                        <h3 style="margin-bottom: 24px; color: var(--text-color); text-align: center;">
                            <i class="fas fa-qrcode"></i> 簽到 QR Code 與連結
                        </h3>
                        <div id="qrcode" style="margin-bottom: 24px; display: flex; justify-content: center;"></div>
                        <div class="qrcode-link">
                            <strong style="display: block; margin-bottom: 12px; text-align: center; font-size: 16px;">簽到連結：</strong>
                            <div style="text-align: center; margin-bottom: 16px;">
                                <a href="<?php echo htmlspecialchars($check_in_url); ?>" target="_blank" id="checkInLink" style="display: inline-block; padding: 12px 20px; background: var(--primary-color); color: white; text-decoration: none; border-radius: 6px; word-break: break-all; max-width: 100%; font-size: 14px; transition: all 0.3s;">
                                    <i class="fas fa-link"></i> <span id="checkInLinkText"><?php echo htmlspecialchars($check_in_url); ?></span>
                                </a>
                            </div>
                            <div style="text-align: center;">
                                <button type="button" class="btn btn-secondary" onclick="copyLink()" style="width: auto; min-width: 150px;">
                                    <i class="fas fa-copy"></i> 複製連結
                                </button>
                            </div>
                        </div>
                        <div style="margin-top: 24px; padding: 16px; background: #f0f2f5; border-radius: 6px; text-align: center;">
                            <p style="margin: 0; color: var(--text-secondary-color); font-size: 14px;">
                                <i class="fas fa-info-circle"></i> 請將此 QR code 或連結提供給參與者進行簽到
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- 填寫模式 -->
            <div class="fill-mode" id="fillMode">
                <div class="info-box">
                    <p><i class="fas fa-info-circle"></i> 請填寫以下資訊完成簽到。無論是否有報名，都可以在此簽到。</p>
                </div>
                
                <form id="checkInForm">
                    <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
                    <div id="formFieldsContainer">
                        <!-- 表單欄位將由 JavaScript 動態生成 -->
                    </div>
                    
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="fas fa-check"></i> 完成簽到
                    </button>
                </form>
            </div>
        <?php endif; ?>
        
        <div class="loading" id="loading">
            <i class="fas fa-spinner"></i>
            <p style="margin-top: 12px; color: var(--text-secondary-color);">處理中...</p>
        </div>
    </div>
    
    <script>
        const isEditMode = <?php echo $is_admission_center ? 'true' : 'false'; ?>;
        const sessionId = <?php echo $session_id; ?>;
        const formConfig = <?php echo json_encode($form_config, JSON_UNESCAPED_UNICODE); ?>;
        const checkInUrl = <?php echo json_encode($check_in_url, JSON_UNESCAPED_UNICODE); ?>;
        
        // 將 checkInUrl 設為全域變數，方便更新
        window.checkInUrl = checkInUrl;
        
        let fieldCounter = 0;
        
        // 初始化頁面
        if (isEditMode) {
            document.getElementById('editMode').style.display = 'block';
            initEditMode();
        } else {
            document.getElementById('fillMode').style.display = 'block';
            initFillMode();
        }
        
        // 編輯模式初始化
        function initEditMode() {
            const fieldsContainer = document.getElementById('fieldsContainer');
            fieldsContainer.innerHTML = '';
            
            formConfig.forEach((field, index) => {
                addFieldEditor(field, index);
            });
            
            // 如果沒有欄位，添加一個預設欄位
            if (formConfig.length === 0) {
                addFieldEditor({
                    name: 'name',
                    label: '姓名',
                    type: 'text',
                    required: true,
                    placeholder: '請輸入您的姓名'
                }, 0);
            }
            
            // 新增欄位按鈕
            document.getElementById('addFieldBtn').addEventListener('click', function() {
                addFieldEditor({
                    name: 'field_' + (++fieldCounter),
                    label: '新欄位',
                    type: 'text',
                    required: false,
                    placeholder: ''
                }, formConfig.length);
            });
            
            // 儲存配置
            document.getElementById('formConfigForm').addEventListener('submit', function(e) {
                e.preventDefault();
                saveFormConfig();
            });
            
            // 預覽表單
            document.getElementById('previewBtn').addEventListener('click', function() {
                previewForm();
            });
            
            // 返回編輯按鈕
            document.getElementById('backToEditBtn').addEventListener('click', function() {
                document.getElementById('formConfigForm').style.display = 'block';
                document.getElementById('addFieldBtn').style.display = 'block';
                document.querySelector('.btn-group').style.display = 'flex';
                document.getElementById('qrcodeSection').style.display = 'none';
                this.style.display = 'none';
            });
            
            // 如果已經有配置，預先載入 QR code（但保持編輯表單可見，讓用戶可以繼續編輯）
            if (checkInUrl) {
                window.checkInUrl = checkInUrl;
                // 等待 QRCode 庫載入後再生成（但不顯示，等儲存後才顯示）
                let retryCount = 0;
                const maxRetries = 50;
                function waitForQRCode() {
                    if (typeof QRCode !== 'undefined') {
                        // 預先生成但不顯示
                        console.log('QRCode 庫已載入，準備就緒');
                    } else {
                        retryCount++;
                        if (retryCount < maxRetries) {
                            setTimeout(waitForQRCode, 100);
                        }
                    }
                }
                waitForQRCode();
            }
        }
        
        // 添加欄位編輯器
        function addFieldEditor(field, index) {
            const fieldsContainer = document.getElementById('fieldsContainer');
            const fieldEditor = document.createElement('div');
            fieldEditor.className = 'field-editor';
            fieldEditor.dataset.index = index;
            
            const fieldTypes = [
                { value: 'text', label: '文字' },
                { value: 'email', label: 'Email' },
                { value: 'tel', label: '電話' },
                { value: 'textarea', label: '多行文字' },
                { value: 'number', label: '數字' }
            ];
            
            fieldEditor.innerHTML = `
                <div class="field-editor-header">
                    <h4>欄位 ${index + 1}</h4>
                    <button type="button" class="btn btn-danger" onclick="removeField(this)">
                        <i class="fas fa-trash"></i> 刪除
                    </button>
                </div>
                <div class="form-group">
                    <label class="form-label">欄位名稱（英文，用於資料庫）</label>
                    <input type="text" class="form-control field-name" value="${field.name || ''}" placeholder="例如：name, email, phone">
                </div>
                <div class="form-group">
                    <label class="form-label">欄位標籤（顯示名稱）</label>
                    <input type="text" class="form-control field-label" value="${field.label || ''}" placeholder="例如：姓名, Email, 電話">
                </div>
                <div class="form-group">
                    <label class="form-label">欄位類型</label>
                    <select class="form-control field-type">
                        ${fieldTypes.map(type => 
                            `<option value="${type.value}" ${field.type === type.value ? 'selected' : ''}>${type.label}</option>`
                        ).join('')}
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">提示文字</label>
                    <input type="text" class="form-control field-placeholder" value="${field.placeholder || ''}" placeholder="例如：請輸入您的姓名">
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" class="field-required" ${field.required ? 'checked' : ''} id="required_${index}">
                    <label for="required_${index}">必填欄位</label>
                </div>
            `;
            
            fieldsContainer.appendChild(fieldEditor);
        }
        
        // 移除欄位
        function removeField(btn) {
            if (confirm('確定要刪除此欄位嗎？')) {
                btn.closest('.field-editor').remove();
            }
        }
        
        // 儲存表單配置
        function saveFormConfig() {
            const fieldsContainer = document.getElementById('fieldsContainer');
            const fieldEditors = fieldsContainer.querySelectorAll('.field-editor');
            const config = [];
            
            fieldEditors.forEach((editor, index) => {
                const name = editor.querySelector('.field-name').value.trim();
                const label = editor.querySelector('.field-label').value.trim();
                const type = editor.querySelector('.field-type').value;
                const placeholder = editor.querySelector('.field-placeholder').value.trim();
                const required = editor.querySelector('.field-required').checked;
                
                if (name && label) {
                    config.push({
                        name: name,
                        label: label,
                        type: type,
                        required: required,
                        placeholder: placeholder
                    });
                }
            });
            
            if (config.length === 0) {
                showMessage('請至少添加一個欄位', 'error');
                return;
            }
            
            const formData = new FormData();
            formData.append('session_id', sessionId);
            formData.append('field_config', JSON.stringify(config));
            
            const loading = document.getElementById('loading');
            const messageContainer = document.getElementById('messageContainer');
            const saveBtn = document.getElementById('saveConfigBtn');
            
            loading.style.display = 'block';
            saveBtn.disabled = true;
            messageContainer.innerHTML = '';
            
            fetch('save_check_in_form_config.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                loading.style.display = 'none';
                saveBtn.disabled = false;
                
                if (data.success) {
                    showMessage('配置已儲存！', 'success');
                    
                    // 更新 checkInUrl
                    if (data.check_in_url) {
                        // 確保連結包含 fill_mode=1 參數
                        let updatedUrl = data.check_in_url;
                        if (updatedUrl.indexOf('fill_mode=1') === -1) {
                            updatedUrl += (updatedUrl.indexOf('?') === -1 ? '?' : '&') + 'fill_mode=1';
                        }
                        
                        window.checkInUrl = updatedUrl;
                        const linkElement = document.getElementById('checkInLink');
                        const linkTextElement = document.getElementById('checkInLinkText');
                        if (linkElement) {
                            linkElement.href = updatedUrl;
                            if (linkTextElement) {
                                linkTextElement.textContent = updatedUrl;
                            } else {
                                linkElement.innerHTML = '<i class="fas fa-link"></i> <span>' + updatedUrl + '</span>';
                            }
                        }
                    }
                    
                    // 隱藏編輯表單，顯示 QR code 和連結
                    const formConfigForm = document.getElementById('formConfigForm');
                    const addFieldBtn = document.getElementById('addFieldBtn');
                    const btnGroup = document.querySelector('.btn-group');
                    const backToEditBtn = document.getElementById('backToEditBtn');
                    const qrcodeSection = document.getElementById('qrcodeSection');
                    
                    if (formConfigForm) formConfigForm.style.display = 'none';
                    if (addFieldBtn) addFieldBtn.style.display = 'none';
                    if (btnGroup) btnGroup.style.display = 'none';
                    if (backToEditBtn) backToEditBtn.style.display = 'inline-flex';
                    
                    // 立即顯示 QR code 區域（即使 QRCode 庫還沒載入，連結也要顯示）
                    if (qrcodeSection) {
                        qrcodeSection.style.display = 'block';
                        // 滾動到 QR code 區域
                        setTimeout(() => {
                            qrcodeSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }, 100);
                    }
                    
                    // 等待 QRCode 庫載入後再生成 QR code
                    let retryCount = 0;
                    const maxRetries = 50; // 最多等待 5 秒
                    function waitAndGenerate() {
                        if (typeof QRCode !== 'undefined') {
                            try {
                                generateQRCode();
                            } catch (error) {
                                console.error('生成 QR Code 失敗:', error);
                                const qrcodeDiv = document.getElementById('qrcode');
                                if (qrcodeDiv) {
                                    qrcodeDiv.innerHTML = '<p style="color: var(--warning-color); padding: 20px;">QR Code 生成失敗，請使用下方連結進行簽到</p>';
                                }
                            }
                        } else {
                            retryCount++;
                            if (retryCount < maxRetries) {
                                setTimeout(waitAndGenerate, 100);
                            } else {
                                console.warn('QRCode 庫載入超時');
                                const qrcodeDiv = document.getElementById('qrcode');
                                if (qrcodeDiv) {
                                    qrcodeDiv.innerHTML = '<p style="color: var(--warning-color); padding: 20px;">QR Code 載入失敗，請使用下方連結進行簽到</p>';
                                }
                            }
                        }
                    }
                    waitAndGenerate();
                } else {
                    showMessage(data.message || '儲存失敗', 'error');
                }
            })
            .catch(error => {
                loading.style.display = 'none';
                saveBtn.disabled = false;
                showMessage('發生錯誤：' + (error.message || '請稍後再試'), 'error');
            });
        }
        
        // 預覽表單
        function previewForm() {
            const fieldsContainer = document.getElementById('fieldsContainer');
            const fieldEditors = fieldsContainer.querySelectorAll('.field-editor');
            const config = [];
            
            fieldEditors.forEach(editor => {
                const name = editor.querySelector('.field-name').value.trim();
                const label = editor.querySelector('.field-label').value.trim();
                const type = editor.querySelector('.field-type').value;
                const placeholder = editor.querySelector('.field-placeholder').value.trim();
                const required = editor.querySelector('.field-required').checked;
                
                if (name && label) {
                    config.push({
                        name: name,
                        label: label,
                        type: type,
                        required: required,
                        placeholder: placeholder
                    });
                }
            });
            
            // 在新視窗中顯示預覽
            const previewWindow = window.open('', '_blank');
            previewWindow.document.write(generateFormHTML(config));
        }
        
        // 生成表單 HTML（使用與實際表單相同的樣式）
        function generateFormHTML(config) {
            const sessionName = '<?php echo htmlspecialchars($session['session_name'], ENT_QUOTES); ?>';
            const sessionDate = '<?php echo !empty($session['session_date']) ? date('Y年m月d日', strtotime($session['session_date'])) : ''; ?>';
            
            let html = `
                <!DOCTYPE html>
                <html lang="zh-TW">
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <title>表單預覽 - ${sessionName}</title>
                    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
                    <style>
                        :root {
                            --primary-color: #1890ff;
                            --success-color: #52c41a;
                            --warning-color: #faad14;
                            --danger-color: #ff4d4f;
                            --text-color: #262626;
                            --text-secondary-color: #8c8c8c;
                            --border-color: #d9d9d9;
                            --background-color: #f0f2f5;
                            --card-background-color: #fff;
                        }
                        * { margin: 0; padding: 0; box-sizing: border-box; }
                        body { 
                            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; 
                            background: var(--background-color); 
                            color: var(--text-color); 
                            padding: 20px;
                            min-height: 100vh;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                        }
                        .check-in-container {
                            background: var(--card-background-color);
                            border-radius: 12px;
                            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                            padding: 40px;
                            max-width: 600px;
                            width: 100%;
                        }
                        .check-in-header {
                            text-align: center;
                            margin-bottom: 32px;
                        }
                        .check-in-header h1 {
                            font-size: 28px;
                            color: var(--text-color);
                            margin-bottom: 8px;
                        }
                        .check-in-header .session-name {
                            font-size: 18px;
                            color: var(--primary-color);
                            font-weight: 600;
                            margin-bottom: 4px;
                        }
                        .check-in-header .session-date {
                            font-size: 14px;
                            color: var(--text-secondary-color);
                        }
                        .info-box {
                            background: #e6f7ff;
                            border: 1px solid #91d5ff;
                            border-radius: 6px;
                            padding: 16px;
                            margin-bottom: 24px;
                        }
                        .info-box p {
                            margin: 0;
                            color: #595959;
                            line-height: 1.6;
                        }
                        .form-group {
                            margin-bottom: 24px;
                        }
                        .form-label {
                            display: block;
                            margin-bottom: 8px;
                            font-weight: 500;
                            color: var(--text-color);
                        }
                        .form-label .required {
                            color: var(--danger-color);
                            margin-left: 4px;
                        }
                        .form-control {
                            width: 100%;
                            padding: 12px 16px;
                            border: 1px solid var(--border-color);
                            border-radius: 6px;
                            font-size: 16px;
                            transition: all 0.3s;
                        }
                        .form-control:focus {
                            outline: none;
                            border-color: var(--primary-color);
                            box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.2);
                        }
                        .btn {
                            padding: 12px 24px;
                            border: none;
                            border-radius: 6px;
                            font-size: 16px;
                            font-weight: 500;
                            cursor: pointer;
                            transition: all 0.3s;
                            width: 100%;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            gap: 8px;
                        }
                        .btn-primary {
                            background: var(--primary-color);
                            color: white;
                        }
                        .btn-primary:hover {
                            background: #40a9ff;
                        }
                    </style>
                </head>
                <body>
                    <div class="check-in-container">
                        <div class="check-in-header">
                            <h1><i class="fas fa-check-circle"></i> 線上簽到</h1>
                            <div class="session-name">${sessionName}</div>
                            ${sessionDate ? `<div class="session-date"><i class="fas fa-calendar"></i> ${sessionDate}</div>` : ''}
                        </div>
                        <div class="info-box">
                            <p><i class="fas fa-info-circle"></i> 請填寫以下資訊完成簽到。無論是否有報名，都可以在此簽到。</p>
                        </div>
                        <form>
            `;
            
            config.forEach(field => {
                html += `
                    <div class="form-group">
                        <label class="form-label">
                            ${field.label} ${field.required ? '<span class="required">*</span>' : ''}
                        </label>
                `;
                
                if (field.type === 'textarea') {
                    html += `<textarea name="${field.name}" class="form-control" rows="3" ${field.required ? 'required' : ''} placeholder="${(field.placeholder || '').replace(/"/g, '&quot;')}"></textarea>`;
                } else {
                    html += `<input type="${field.type}" name="${field.name}" class="form-control" ${field.required ? 'required' : ''} placeholder="${(field.placeholder || '').replace(/"/g, '&quot;')}">`;
                }
                
                html += `</div>`;
            });
            
            html += `
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-check"></i> 完成簽到
                            </button>
                        </form>
                    </div>
                </body>
                </html>
            `;
            
            return html;
        }
        
        // 生成 QR Code
        function generateQRCode() {
            const qrcodeContainer = document.getElementById('qrcode');
            if (!qrcodeContainer) {
                console.error('找不到 QR code 容器');
                return;
            }
            
            qrcodeContainer.innerHTML = '<p style="padding: 20px; color: var(--text-secondary-color);">正在生成 QR Code...</p>';
            
            const urlToEncode = window.checkInUrl || checkInUrl;
            if (!urlToEncode) {
                console.error('缺少簽到連結');
                qrcodeContainer.innerHTML = '<p style="padding: 20px; color: var(--danger-color);">缺少簽到連結</p>';
                return;
            }
            
            console.log('開始生成 QR Code，連結:', urlToEncode);
            
            // 檢查 QRCode 庫是否已載入
            function tryGenerate() {
                if (typeof QRCode === 'undefined') {
                    console.warn('QRCode 庫尚未載入，等待中...');
                    setTimeout(tryGenerate, 200);
                    return;
                }
                
                try {
                    // 清空容器
                    qrcodeContainer.innerHTML = '';
                    
                    // 使用 QRCode.toCanvas 生成 QR code
                    QRCode.toCanvas(qrcodeContainer, urlToEncode, {
                        width: 256,
                        margin: 2,
                        color: {
                            dark: '#000000',
                            light: '#FFFFFF'
                        },
                        errorCorrectionLevel: 'M'
                    }, function (error) {
                        if (error) {
                            console.error('QR Code 生成失敗:', error);
                            qrcodeContainer.innerHTML = '<p style="padding: 20px; color: var(--warning-color);">QR Code 生成失敗：' + error.message + '<br>請使用下方連結進行簽到</p>';
                        } else {
                            console.log('QR Code 生成成功');
                            // 確保 canvas 居中顯示
                            const canvas = qrcodeContainer.querySelector('canvas');
                            if (canvas) {
                                canvas.style.display = 'block';
                                canvas.style.margin = '0 auto';
                            }
                        }
                    });
                } catch (error) {
                    console.error('QR Code 生成異常:', error);
                    qrcodeContainer.innerHTML = '<p style="padding: 20px; color: var(--warning-color);">QR Code 生成異常：' + error.message + '<br>請使用下方連結進行簽到</p>';
                }
            }
            
            // 開始嘗試生成（最多等待 10 秒）
            let retryCount = 0;
            const maxRetries = 50;
            const tryGenerateWithRetry = function() {
                if (typeof QRCode !== 'undefined') {
                    tryGenerate();
                } else {
                    retryCount++;
                    if (retryCount < maxRetries) {
                        setTimeout(tryGenerateWithRetry, 200);
                    } else {
                        console.error('QRCode 庫載入超時');
                        qrcodeContainer.innerHTML = '<p style="padding: 20px; color: var(--warning-color);">QR Code 庫載入超時，請使用下方連結進行簽到</p>';
                    }
                }
            };
            
            tryGenerateWithRetry();
        }
        
        // 複製連結
        function copyLink() {
            const linkElement = document.getElementById('checkInLink');
            const link = linkElement.href || linkElement.textContent;
            
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(link).then(() => {
                    showMessage('連結已複製到剪貼簿', 'success');
                }).catch(() => {
                    // 降級方案
                    fallbackCopyText(link);
                });
            } else {
                // 降級方案
                fallbackCopyText(link);
            }
        }
        
        // 降級複製方案
        function fallbackCopyText(text) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            try {
                document.execCommand('copy');
                showMessage('連結已複製到剪貼簿', 'success');
            } catch (err) {
                showMessage('複製失敗，請手動複製連結', 'error');
            }
            document.body.removeChild(textarea);
        }
        
        // 填寫模式初始化
        function initFillMode() {
            const formFieldsContainer = document.getElementById('formFieldsContainer');
            formFieldsContainer.innerHTML = '';
            
            formConfig.forEach(field => {
                const formGroup = document.createElement('div');
                formGroup.className = 'form-group';
                
                const label = document.createElement('label');
                label.className = 'form-label';
                label.innerHTML = field.label + (field.required ? ' <span class="required">*</span>' : '');
                
                let input;
                if (field.type === 'textarea') {
                    input = document.createElement('textarea');
                    input.rows = 3;
                } else {
                    input = document.createElement('input');
                    input.type = field.type;
                }
                
                input.name = field.name;
                input.className = 'form-control';
                input.placeholder = field.placeholder || '';
                if (field.required) {
                    input.required = true;
                }
                
                formGroup.appendChild(label);
                formGroup.appendChild(input);
                formFieldsContainer.appendChild(formGroup);
            });
            
            // 表單提交
            document.getElementById('checkInForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                const form = this;
                const submitBtn = document.getElementById('submitBtn');
                const loading = document.getElementById('loading');
                const messageContainer = document.getElementById('messageContainer');
                
                messageContainer.innerHTML = '';
                
                form.style.display = 'none';
                loading.style.display = 'block';
                submitBtn.disabled = true;
                
                const formData = new FormData(form);
                
                fetch('process_online_check_in.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    loading.style.display = 'none';
                    
                    if (data.success) {
                        messageContainer.innerHTML = `
                            <div class="message success">
                                <i class="fas fa-check-circle"></i> ${data.message}
                            </div>
                        `;
                        
                        form.reset();
                        
                        setTimeout(() => {
                            form.style.display = 'block';
                            messageContainer.innerHTML = '';
                        }, 3000);
                    } else {
                        messageContainer.innerHTML = `
                            <div class="message error">
                                <i class="fas fa-exclamation-circle"></i> ${data.message}
                            </div>
                        `;
                        form.style.display = 'block';
                    }
                    
                    submitBtn.disabled = false;
                })
                .catch(error => {
                    loading.style.display = 'none';
                    form.style.display = 'block';
                    messageContainer.innerHTML = `
                        <div class="message error">
                            <i class="fas fa-exclamation-circle"></i> 發生錯誤：${error.message || '請稍後再試'}
                        </div>
                    `;
                    submitBtn.disabled = false;
                });
            });
        }
        
        // 顯示訊息
        function showMessage(message, type) {
            const messageContainer = document.getElementById('messageContainer');
            const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
            messageContainer.innerHTML = `
                <div class="message ${type}">
                    <i class="fas ${icon}"></i> ${message}
                </div>
            `;
            
            setTimeout(() => {
                messageContainer.innerHTML = '';
            }, 5000);
        }
    </script>
</body>
</html>

