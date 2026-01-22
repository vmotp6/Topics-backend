<?php
require_once __DIR__ . '/session_config.php';

// 檢查登入狀態和角色權限
checkBackendLogin();

// 獲取使用者資訊
$user_id = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? '';
$user_name = $_SESSION['name'] ?? $username;

// 獲取可選的文件ID（用於關聯簽名）
$document_id = isset($_GET['document_id']) ? (int)$_GET['document_id'] : null;
$document_type = $_GET['document_type'] ?? 'general'; // 文件類型：general, admission, etc.

// 設置頁面標題
$page_title = '電子簽章';
$current_page = 'signature';
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Topics 後台管理系統</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1890ff;
            --text-color: #262626;
            --text-secondary-color: #8c8c8c;
            --border-color: #f0f0f0;
            --background-color: #f0f2f5;
            --card-background-color: #fff;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: var(--background-color);
            color: var(--text-color);
        }
        .dashboard {
            display: flex;
            min-height: 100vh;
        }
        .content {
            padding: 24px;
            width: 100%;
        }
        .breadcrumb {
            margin-bottom: 16px;
            font-size: 16px;
            color: var(--text-secondary-color);
        }
        .breadcrumb a {
            color: var(--primary-color);
            text-decoration: none;
        }
        .breadcrumb a:hover {
            text-decoration: underline;
        }
        .card {
            background: var(--card-background-color);
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .card-header {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border-color);
            background: #fafafa;
        }
        .card-header h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-color);
        }
        .card-body {
            padding: 24px;
        }
        .signature-container {
            max-width: 900px;
            margin: 0 auto;
        }
        .signature-info {
            text-align: center;
            color: var(--text-secondary-color);
            margin-bottom: 20px;
            padding: 12px;
            background: #f5f5f5;
            border-radius: 4px;
        }
        #signatureCanvas {
            border: 2px solid #d9d9d9;
            border-radius: 8px;
            cursor: crosshair;
            background: white;
            display: block;
            margin: 20px auto;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            touch-action: none;
        }
        #signatureCanvas:active {
            cursor: crosshair;
        }
        .controls {
            text-align: center;
            margin: 30px 0;
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .btn {
            padding: 10px 24px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
            background: #fff;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .btn-primary {
            background: #1890ff;
            color: white;
            border-color: #1890ff;
        }
        .btn-primary:hover {
            background: #40a9ff;
            border-color: #40a9ff;
        }
        .btn-danger {
            background: #f5222d;
            color: white;
            border-color: #f5222d;
        }
        .btn-danger:hover {
            background: #ff4d4f;
            border-color: #ff4d4f;
        }
        .btn-secondary {
            background: #d9d9d9;
            color: #333;
        }
        .btn-secondary:hover {
            background: #bfbfbf;
        }
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .signature-preview {
            margin-top: 30px;
            text-align: center;
            padding: 20px;
            background: #fafafa;
            border-radius: 8px;
            display: none;
        }
        .signature-preview.active {
            display: block;
        }
        .signature-preview h4 {
            margin-bottom: 16px;
            color: var(--text-color);
        }
        .signature-preview img {
            max-width: 100%;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background-color: #333;
            color: white;
            padding: 15px 20px;
            border-radius: 8px;
            z-index: 9999;
            display: none;
            opacity: 0;
            transition: opacity 0.5s;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .toast.show {
            display: block;
            opacity: 1;
        }
        .toast.success {
            background-color: #52c41a;
        }
        .toast.error {
            background-color: #f5222d;
        }
        @media (max-width: 768px) {
            #signatureCanvas {
                width: 100%;
                height: 250px;
            }
            .controls {
                flex-direction: column;
            }
            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            <div class="content">
                <div class="breadcrumb">
                    <a href="index.php">首頁</a> / <?php echo $page_title; ?>
                    <span style="float: right;">
                        <a href="signature_list.php" style="color: var(--primary-color); text-decoration: none;">
                            <i class="fas fa-list"></i> 查看簽名記錄
                        </a>
                    </span>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-file-signature"></i> 電子簽章</h3>
                    </div>
                    <div class="card-body">
                        <div class="signature-container">
                            <div class="signature-info">
                                <p><strong>簽名者：</strong><?php echo htmlspecialchars($user_name); ?> (<?php echo htmlspecialchars($username); ?>)</p>
                                <p style="margin-top: 8px; font-size: 14px;">請在下方區域使用滑鼠或觸控筆進行簽名</p>
                            </div>
                            
                            <canvas id="signatureCanvas" width="800" height="350"></canvas>
                            
                            <div class="controls">
                                <button class="btn btn-danger" onclick="clearSignature()">
                                    <i class="fas fa-eraser"></i> 清除
                                </button>
                                <button class="btn btn-secondary" onclick="saveAsImage()">
                                    <i class="fas fa-download"></i> 下載圖片
                                </button>
                                <button class="btn btn-primary" onclick="submitSignature()" id="submitBtn">
                                    <i class="fas fa-check"></i> 確認簽名
                                </button>
                            </div>
                            
                            <div class="signature-preview" id="preview">
                                <h4>簽名預覽</h4>
                                <img id="previewImage" src="" alt="簽名預覽">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 訊息提示框 -->
    <div id="toast" class="toast"></div>

    <script>
        const canvas = document.getElementById('signatureCanvas');
        const ctx = canvas.getContext('2d');
        let isDrawing = false;
        let lastX = 0;
        let lastY = 0;

        // 設定畫筆樣式
        ctx.strokeStyle = '#000000';
        ctx.lineWidth = 2.5;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';

        // 調整 Canvas 大小以適應螢幕
        function resizeCanvas() {
            const container = canvas.parentElement;
            const maxWidth = Math.min(800, container.clientWidth - 48);
            canvas.style.width = maxWidth + 'px';
            canvas.style.height = (maxWidth * 350 / 800) + 'px';
        }

        window.addEventListener('resize', resizeCanvas);
        resizeCanvas();

        // 獲取滑鼠/觸控在 Canvas 上的座標
        function getEventPos(e) {
            const rect = canvas.getBoundingClientRect();
            const scaleX = canvas.width / rect.width;
            const scaleY = canvas.height / rect.height;
            
            if (e.touches && e.touches.length > 0) {
                return {
                    x: (e.touches[0].clientX - rect.left) * scaleX,
                    y: (e.touches[0].clientY - rect.top) * scaleY
                };
            } else {
                return {
                    x: (e.clientX - rect.left) * scaleX,
                    y: (e.clientY - rect.top) * scaleY
                };
            }
        }

        // 滑鼠事件
        canvas.addEventListener('mousedown', (e) => {
            isDrawing = true;
            const pos = getEventPos(e);
            lastX = pos.x;
            lastY = pos.y;
        });

        canvas.addEventListener('mousemove', (e) => {
            if (!isDrawing) return;
            
            const pos = getEventPos(e);
            drawLine(lastX, lastY, pos.x, pos.y);
            lastX = pos.x;
            lastY = pos.y;
        });

        canvas.addEventListener('mouseup', stopDrawing);
        canvas.addEventListener('mouseout', stopDrawing);

        // 觸控事件（支援手機和平板）
        canvas.addEventListener('touchstart', (e) => {
            e.preventDefault();
            isDrawing = true;
            const pos = getEventPos(e);
            lastX = pos.x;
            lastY = pos.y;
        });

        canvas.addEventListener('touchmove', (e) => {
            e.preventDefault();
            if (!isDrawing) return;
            
            const pos = getEventPos(e);
            drawLine(lastX, lastY, pos.x, pos.y);
            lastX = pos.x;
            lastY = pos.y;
        });

        canvas.addEventListener('touchend', (e) => {
            e.preventDefault();
            stopDrawing();
        });

        function drawLine(x1, y1, x2, y2) {
            ctx.beginPath();
            ctx.moveTo(x1, y1);
            ctx.lineTo(x2, y2);
            ctx.stroke();
        }

        function stopDrawing() {
            isDrawing = false;
        }

        function clearSignature() {
            if (confirm('確定要清除簽名嗎？')) {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                document.getElementById('preview').classList.remove('active');
                showToast('簽名已清除', 'success');
            }
        }

        function saveAsImage() {
            const dataURL = canvas.toDataURL('image/png');
            const link = document.createElement('a');
            link.download = 'signature_' + new Date().getTime() + '.png';
            link.href = dataURL;
            link.click();
            showToast('圖片已下載', 'success');
        }

        function submitSignature() {
            // 檢查是否有簽名
            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const hasSignature = imageData.data.some((channel, index) => {
                return index % 4 !== 3 && channel !== 255; // 檢查是否有非白色像素
            });

            if (!hasSignature) {
                showToast('請先進行簽名', 'error');
                return;
            }

            // 轉換為 Base64
            const signatureData = canvas.toDataURL('image/png');

            // 顯示預覽
            document.getElementById('previewImage').src = signatureData;
            document.getElementById('preview').classList.add('active');

            // 禁用提交按鈕
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 儲存中...';

            // 發送到後端
            fetch('save_signature.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    signature: signatureData,
                    user_id: <?php echo $user_id; ?>,
                    document_id: <?php echo $document_id ? $document_id : 'null'; ?>,
                    document_type: '<?php echo htmlspecialchars($document_type, ENT_QUOTES); ?>',
                    timestamp: new Date().toISOString()
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('簽名已儲存成功！', 'success');
                    // 3秒後可以選擇重定向或關閉視窗
                    setTimeout(() => {
                        if (data.redirect_url) {
                            window.location.href = data.redirect_url;
                        } else if (window.opener) {
                            // 如果是彈出視窗，通知父視窗
                            window.opener.postMessage({
                                type: 'signature_saved',
                                signature_id: data.signature_id,
                                signature_url: data.signature_url
                            }, '*');
                            window.close();
                        }
                    }, 2000);
                } else {
                    showToast('儲存失敗：' + (data.message || '未知錯誤'), 'error');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-check"></i> 確認簽名';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('儲存失敗，請稍後再試', 'error');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-check"></i> 確認簽名';
            });
        }

        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = 'toast ' + type + ' show';
            
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => {
                    toast.textContent = '';
                }, 500);
            }, 3000);
        }
    </script>
</body>
</html>

