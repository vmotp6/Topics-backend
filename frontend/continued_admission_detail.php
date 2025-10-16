<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

require_once '../../Topics-frontend/frontend/config.php';

$application_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = isset($_GET['action']) ? $_GET['action'] : 'view';
if ($application_id === 0) {
    header("Location: continued_admission_list.php");
    exit;
}

$conn = getDatabaseConnection();
$stmt = $conn->prepare("SELECT * FROM continued_admission WHERE id = ?");
$stmt->bind_param("i", $application_id);
$stmt->execute();
$result = $stmt->get_result();
$application = $result->fetch_assoc();
$stmt->close();
$conn->close();

if (!$application) {
    header("Location: continued_admission_list.php");
    exit;
}

$page_title = ($action === 'review') ? '續招報名審核 - ' . htmlspecialchars($application['name']) : '續招報名詳情 - ' . htmlspecialchars($application['name']);
$current_page = 'continued_admission_detail';

$documents = json_decode($application['documents'], true);
$choices = json_decode($application['choices'], true);

function formatAddress($app) {
    $address_parts = [
        $app['zip_code'], $app['city'], $app['district'], $app['village'],
        $app['neighbor'] ? $app['neighbor'] . '鄰' : '',
        $app['road'],
        $app['section'] ? $app['section'] . '段' : '',
        $app['lane'] ? $app['lane'] . '巷' : '',
        $app['alley'] ? $app['alley'] . '弄' : '',
        $app['house_no'] ? $app['house_no'] . '號' : '',
        $app['floor'] ? $app['floor'] . '樓' : ''
    ];
    return implode(' ', array_filter($address_parts));
}

function getStatusText($status) {
    switch ($status) {
        case 'approved': return '錄取';
        case 'rejected': return '未錄取';
        case 'waitlist': return '備取';
        default: return '待審核';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    // 這裡可以加入一個非AJAX的表單提交處理，但為了體驗一致性，我們將使用AJAX
}

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
            --primary-color: #1890ff; --text-color: #262626; --text-secondary-color: #8c8c8c; 
            --border-color: #f0f0f0; --background-color: #f0f2f5; --card-background-color: #fff;
            --success-color: #52c41a; --danger-color: #f5222d; --warning-color: #faad14;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: var(--background-color); color: var(--text-color); overflow-x: hidden; }
        .dashboard { display: flex; min-height: 100vh; }
        .content { padding: 24px; width: 100%; }
        .breadcrumb { margin-bottom: 16px; font-size: 16px; color: var(--text-secondary-color); }
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        
        .card { background: var(--card-background-color); border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); border: 1px solid var(--border-color); margin-bottom: 24px; }
        .card-header { padding: 16px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: #fafafa; }
        .card-header h3 { font-size: 18px; font-weight: 600; color: var(--text-color); }
        .card-body { padding: 24px; }

        .detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px; }
        .detail-section { background: #fafafa; padding: 20px; border-radius: 6px; border: 1px solid var(--border-color); }
        .detail-section h4 { font-size: 16px; margin-bottom: 16px; padding-bottom: 8px; border-bottom: 1px solid var(--border-color); }
        .detail-item { display: grid; grid-template-columns: 120px 1fr; gap: 8px; margin-bottom: 12px; font-size: 14px; }
        .detail-item-label { font-weight: 500; color: var(--text-secondary-color); text-align: right; }
        .detail-item-value { word-break: break-all; }
        .detail-item-value.long-text { white-space: pre-wrap; background: #fff; padding: 8px; border-radius: 4px; border: 1px solid #e8e8e8; }

        .document-list { list-style: none; padding: 0; }
        .document-list li { margin-bottom: 8px; }
        .document-list a { text-decoration: none; color: var(--primary-color); }
        .document-list a:hover { text-decoration: underline; }

        .btn-secondary {
            padding: 8px 16px; border: 1px solid #d9d9d9; border-radius: 6px; cursor: pointer; font-size: 14px;
            transition: all 0.3s; background: #fff; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
            color: #595959;
        }
        .btn-secondary:hover { background: #f5f5f5; border-color: #40a9ff; color: #40a9ff; }
        .btn-primary {
            padding: 8px 16px; border: 1px solid var(--primary-color); border-radius: 6px; cursor: pointer; font-size: 14px;
            transition: all 0.3s; background: var(--primary-color); color: white; display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-primary:hover { background: #40a9ff; border-color: #40a9ff; }

        .status-select {
            padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px;
        }
        .status-select:focus {
            outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.2);
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
                    <a href="index.php">首頁</a> / <a href="continued_admission_list.php">續招報名管理</a> / <?php echo ($action === 'review') ? '報名審核' : '報名詳情'; ?>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3><?php echo ($action === 'review') ? '報名審核' : '報名詳情'; ?> (編號: <?php echo $application['id']; ?>)</h3>
                        <a href="continued_admission_list.php" class="btn-secondary"><i class="fas fa-arrow-left"></i> 返回列表</a>
                    </div>
                    <div class="card-body">
                        <div class="detail-grid">
                            <?php if ($action !== 'review'): ?>
                            <div class="detail-section" style="grid-column: 1 / -1; background: #f6ffed;">
                                <h4 style="color: #52c41a;"><i class="fas fa-info-circle"></i> 審核狀態</h4>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                                    <div class="detail-item" style="margin: 0;">
                                        <span class="detail-item-label">目前狀態:</span>
                                        <span class="detail-item-value" style="font-weight: bold; color: #52c41a;"><?php echo getStatusText($application['status']); ?></span>
                                    </div>
                                    <?php if (!empty($application['reviewer_id'])): ?>
                                    <div class="detail-item" style="margin: 0;">
                                        <span class="detail-item-label">審核老師:</span>
                                        <span class="detail-item-value"><?php echo htmlspecialchars($application['reviewer_id']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($application['reviewed_at'])): ?>
                                    <div class="detail-item" style="margin: 0;">
                                        <span class="detail-item-label">審核時間:</span>
                                        <span class="detail-item-value"><?php echo date('Y/m/d H:i', strtotime($application['reviewed_at'])); ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($application['review_notes'])): ?>
                                <div style="margin-top: 16px;">
                                    <span class="detail-item-label">審核備註:</span>
                                    <div style="background: #fff; padding: 12px; border-radius: 6px; border: 1px solid #d9d9d9; margin-top: 8px; white-space: pre-wrap;"><?php echo htmlspecialchars($application['review_notes']); ?></div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <div class="detail-section">
                                <h4><i class="fas fa-user"></i> 基本資料</h4>
                                <div class="detail-item"><span class="detail-item-label">姓名:</span> <span class="detail-item-value"><?php echo htmlspecialchars($application['name']); ?></span></div>
                                <div class="detail-item"><span class="detail-item-label">身分證字號:</span> <span class="detail-item-value"><?php echo htmlspecialchars($application['id_number']); ?></span></div>
                                <div class="detail-item"><span class="detail-item-label">准考證號碼:</span> <span class="detail-item-value"><?php echo htmlspecialchars($application['exam_no'] ?: '未填寫'); ?></span></div>
                                <div class="detail-item"><span class="detail-item-label">生日:</span> <span class="detail-item-value"><?php echo "{$application['birth_year']}/{$application['birth_month']}/{$application['birth_day']}"; ?></span></div>
                                <div class="detail-item"><span class="detail-item-label">性別:</span> <span class="detail-item-value"><?php echo htmlspecialchars($application['gender'] === 'male' ? '男' : '女'); ?></span></div>
                                <div class="detail-item"><span class="detail-item-label">室內電話:</span> <span class="detail-item-value"><?php echo htmlspecialchars($application['phone'] ?: '未填寫'); ?></span></div>
                                <div class="detail-item"><span class="detail-item-label">行動電話:</span> <span class="detail-item-value"><?php echo htmlspecialchars($application['mobile']); ?></span></div>
                                <div class="detail-item"><span class="detail-item-label">就讀國中:</span> <span class="detail-item-value"><?php echo htmlspecialchars($application['school_city'] . ' ' . $application['school_name']); ?></span></div>
                            </div>

                            <div class="detail-section">
                                <h4><i class="fas fa-map-marker-alt"></i> 地址資訊</h4>
                                <div class="detail-item"><span class="detail-item-label">戶籍地址:</span> <span class="detail-item-value"><?php echo htmlspecialchars(formatAddress($application)); ?></span></div>
                                <div class="detail-item"><span class="detail-item-label">通訊地址:</span> <span class="detail-item-value"><?php echo $application['same_address'] ? '同戶籍地址' : htmlspecialchars($application['contact_address']); ?></span></div>
                            </div>

                            <div class="detail-section">
                                <h4><i class="fas fa-user-friends"></i> 監護人資訊</h4>
                                <div class="detail-item"><span class="detail-item-label">姓名:</span> <span class="detail-item-value"><?php echo htmlspecialchars($application['guardian_name']); ?></span></div>
                                <div class="detail-item"><span class="detail-item-label">室內電話:</span> <span class="detail-item-value"><?php echo htmlspecialchars($application['guardian_phone'] ?: '未填寫'); ?></span></div>
                                <div class="detail-item"><span class="detail-item-label">行動電話:</span> <span class="detail-item-value"><?php echo htmlspecialchars($application['guardian_mobile']); ?></span></div>
                            </div>

                            <div class="detail-section">
                                <h4><i class="fas fa-star"></i> 志願序</h4>
                                <?php if (!empty($choices)): ?>
                                    <ol>
                                        <?php foreach ($choices as $choice): ?>
                                            <li><?php echo htmlspecialchars($choice); ?></li>
                                        <?php endforeach; ?>
                                    </ol>
                                <?php else: ?>
                                    <p>未選擇志願</p>
                                <?php endif; ?>
                            </div>

                            <div class="detail-section">
                                <h4><i class="fas fa-file-alt"></i> 上傳文件</h4>
                                <?php if (!empty($documents)): ?>
                                    <ul class="document-list">
                                        <?php foreach ($documents as $doc): ?>
                                            <li>
                                                <a href="/Topics-frontend/frontend/<?php echo htmlspecialchars($doc['path']); ?>" target="_blank">
                                                    <i class="fas fa-link"></i> <?php echo htmlspecialchars($doc['filename']); ?> (<?php echo htmlspecialchars($doc['type']); ?>)
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p>未上傳任何文件</p>
                                <?php endif; ?>
                            </div>

                            <div class="detail-section">
                                <h4><i class="fas fa-pen"></i> 自傳/專長</h4>
                                <div class="detail-item" style="grid-template-columns: 1fr;">
                                    <span class="detail-item-label" style="text-align: left;">自傳/自我介紹:</span>
                                    <div class="detail-item-value long-text"><?php echo htmlspecialchars($application['self_intro']); ?></div>
                                </div>
                                <div class="detail-item" style="grid-template-columns: 1fr; margin-top: 16px;">
                                    <span class="detail-item-label" style="text-align: left;">興趣/專長:</span>
                                    <div class="detail-item-value long-text"><?php echo htmlspecialchars($application['skills']); ?></div>
                                </div>
                            </div>
                            
                            <?php if ($action === 'review'): ?>
                            <div class="detail-section" style="grid-column: 1 / -1; background: #e6f7ff; margin-top: 24px;">
                                <h4 style="color: var(--primary-color);"><i class="fas fa-check-circle"></i> 審核操作</h4>
                                <form id="reviewForm">
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                                        <div class="detail-item" style="margin: 0;">
                                            <span class="detail-item-label">目前狀態:</span>
                                            <span class="detail-item-value" id="currentStatusText" style="font-weight: bold;"><?php echo getStatusText($application['status']); ?></span>
                                        </div>
                                        <div class="detail-item" style="margin: 0;">
                                            <span class="detail-item-label">審核決定:</span>
                                            <select id="statusSelector" class="status-select" name="status" required>
                                                <option value="">請選擇審核結果</option>
                                                <option value="approved" <?php echo $application['status'] === 'approved' ? 'selected' : ''; ?>>錄取</option>
                                                <option value="rejected" <?php echo $application['status'] === 'rejected' ? 'selected' : ''; ?>>不錄取</option>
                                                <option value="waitlist" <?php echo $application['status'] === 'waitlist' ? 'selected' : ''; ?>>備取</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div style="margin-bottom: 20px;">
                                        <label for="reviewNotes" style="display: block; margin-bottom: 8px; font-weight: 500;">審核備註 (選填):</label>
                                        <textarea id="reviewNotes" name="review_notes" rows="4" style="width: 100%; padding: 8px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px; resize: vertical;" placeholder="請輸入審核意見或備註..."><?php echo htmlspecialchars($application['review_notes'] ?? ''); ?></textarea>
                                    </div>
                                    <div style="display: flex; gap: 12px;">
                                        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> 送出審核結果</button>
                                        <button type="button" onclick="history.back()" class="btn-secondary"><i class="fas fa-times"></i> 取消</button>
                                    </div>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 訊息提示框 -->
    <div id="toast" style="position: fixed; top: 20px; right: 20px; background-color: #333; color: white; padding: 15px 20px; border-radius: 8px; z-index: 9999; display: none; opacity: 0; transition: opacity 0.5s;"></div>

    <script>
    function showToast(message, isSuccess = true) {
        const toast = document.getElementById('toast');
        toast.textContent = message;
        toast.style.backgroundColor = isSuccess ? '#4CAF50' : '#f44336';
        toast.style.display = 'block';
        toast.style.opacity = 1;
        setTimeout(() => {
            toast.style.opacity = 0;
            setTimeout(() => { toast.style.display = 'none'; }, 500);
        }, 3000);
    }

    <?php if ($action === 'review'): ?>
    document.getElementById('reviewForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const applicationId = <?php echo $application_id; ?>;
        const formData = new FormData(this);
        const status = formData.get('status');
        const reviewNotes = formData.get('review_notes');
        
        if (!status) {
            showToast('請選擇審核結果', false);
            return;
        }

        fetch('/Topics-backend/frontend/update_admission_status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ 
                id: applicationId, 
                status: status,
                review_notes: reviewNotes
            }),
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`伺服器錯誤: ${response.status} ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                showToast('審核結果已送出！');
                setTimeout(() => {
                    window.location.href = 'continued_admission_list.php';
                }, 1500);
            } else {
                showToast('審核失敗：' + (data.message || '未知錯誤'), false);
            }
        })
        .catch(error => {
            showToast('前端請求錯誤：' + error.message, false);
        });
    });
    <?php endif; ?>
    </script>
</body>
</html>
```

### 3. 更新側邊欄連結

最後，我將修改 `d:\Topics\Topics-backend\frontend\sidebar.php` 中的「續招」連結，使其指向我們剛剛建立的 `continued_admission_list.php` 頁面。

```diff
--- a/d:/Topics/Topics-backend/frontend/sidebar.php
+++ b/d:/Topics/Topics-backend/frontend/sidebar.php