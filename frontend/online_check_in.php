<?php
// 線上簽到表單頁面
// 不需要登入驗證，任何人都可以簽到

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 獲取場次ID
$session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
if ($session_id === 0) {
    die('錯誤：缺少場次ID');
}

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

$conn->close();

// 設置頁面標題
$page_title = '線上簽到 - ' . htmlspecialchars($session['session_name']);
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
    </style>
</head>
<body>
    <div class="check-in-container">
        <div class="check-in-header">
            <h1><i class="fas fa-check-circle"></i> 線上簽到</h1>
            <div class="session-name"><?php echo htmlspecialchars($session['session_name']); ?></div>
            <?php if (!empty($session['session_date'])): ?>
                <div class="session-date">
                    <i class="fas fa-calendar"></i> 
                    <?php echo date('Y年m月d日', strtotime($session['session_date'])); ?>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="info-box">
            <p><i class="fas fa-info-circle"></i> 請填寫以下資訊完成簽到。無論是否有報名，都可以在此簽到。</p>
        </div>
        
        <div id="messageContainer"></div>
        
        <form id="checkInForm">
            <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
            
            <div class="form-group">
                <label class="form-label">
                    姓名 <span class="required">*</span>
                </label>
                <input type="text" name="name" class="form-control" required placeholder="請輸入您的姓名">
            </div>
            
            <div class="form-group">
                <label class="form-label">
                    Email
                </label>
                <input type="email" name="email" class="form-control" placeholder="請輸入您的Email（選填）">
            </div>
            
            <div class="form-group">
                <label class="form-label">
                    電話 <span class="required">*</span>
                </label>
                <input type="tel" name="phone" class="form-control" required placeholder="請輸入您的電話號碼">
            </div>
            
            <div class="form-group">
                <label class="form-label">
                    備註
                </label>
                <textarea name="notes" class="form-control" rows="3" placeholder="如有其他需要說明的事項，請在此填寫（選填）"></textarea>
            </div>
            
            <button type="submit" class="btn btn-primary" id="submitBtn">
                <i class="fas fa-check"></i> 完成簽到
            </button>
        </form>
        
        <div class="loading" id="loading">
            <i class="fas fa-spinner"></i>
            <p style="margin-top: 12px; color: var(--text-secondary-color);">處理中...</p>
        </div>
    </div>
    
    <script>
        document.getElementById('checkInForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const form = this;
            const submitBtn = document.getElementById('submitBtn');
            const loading = document.getElementById('loading');
            const messageContainer = document.getElementById('messageContainer');
            
            // 隱藏之前的訊息
            messageContainer.innerHTML = '';
            
            // 顯示載入狀態
            form.style.display = 'none';
            loading.style.display = 'block';
            submitBtn.disabled = true;
            
            // 收集表單資料
            const formData = new FormData(form);
            
            // 發送請求
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
                    
                    // 清空表單
                    form.reset();
                    
                    // 3秒後可以再次提交
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
    </script>
</body>
</html>

