<?php
require_once __DIR__ . '/session_config.php';

// 檢查登入狀態和角色權限
checkBackendLogin();

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

try {
    // 建立資料庫連接
    $conn = getDatabaseConnection();
} catch (Exception $e) {
    die("資料庫連接失敗: " . $e->getMessage());
}

$page_title = '科系名額管理';
$current_page = 'department_quota_management';
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

        .btn-primary {
            padding: 8px 16px; border: 1px solid var(--primary-color); border-radius: 6px; cursor: pointer; font-size: 14px;
            transition: all 0.3s; background: var(--primary-color); color: white; display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-primary:hover { background: #40a9ff; border-color: #40a9ff; }
        .btn-secondary {
            padding: 8px 16px; border: 1px solid #d9d9d9; border-radius: 6px; cursor: pointer; font-size: 14px;
            transition: all 0.3s; background: #fff; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
            color: #595959;
        }
        .btn-secondary:hover { background: #f5f5f5; border-color: #40a9ff; color: #40a9ff; }
        .btn-danger {
            padding: 8px 16px; border: 1px solid var(--danger-color); border-radius: 6px; cursor: pointer; font-size: 14px;
            transition: all 0.3s; background: var(--danger-color); color: white; display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-danger:hover { background: #ff4d4f; border-color: #ff4d4f; }

        .quota-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; }
        .quota-card { background: #fff; border: 1px solid var(--border-color); border-radius: 8px; padding: 20px; transition: all 0.3s; }
        .quota-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.1); transform: translateY(-2px); }
        .quota-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .quota-header h4 { font-size: 16px; font-weight: 600; color: var(--text-color); margin: 0; }
        .quota-code { background: var(--primary-color); color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; }
        .quota-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 16px; }
        .stat-item { text-align: center; }
        .stat-label { display: block; font-size: 12px; color: var(--text-secondary-color); margin-bottom: 4px; }
        .stat-value { display: block; font-size: 18px; font-weight: 600; }
        .stat-value.total { color: var(--primary-color); }
        .stat-value.enrolled { color: var(--success-color); }
        .stat-value.remaining { color: var(--warning-color); }
        .stat-value.remaining.full { color: var(--danger-color); }
        .quota-progress { margin: 12px 0; }
        .progress-bar { width: 100%; height: 8px; background: #f0f0f0; border-radius: 4px; overflow: hidden; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, var(--success-color), var(--warning-color)); transition: width 0.3s; }
        
        .quota-actions { display: flex; gap: 8px; margin-top: 16px; }
        .quota-actions .btn { padding: 6px 12px; font-size: 12px; }
        
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: #fff; margin: 5% auto; padding: 20px; border-radius: 8px; width: 90%; max-width: 500px; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-header h3 { margin: 0; }
        .close { color: #aaa; font-size: 28px; font-weight: bold; cursor: pointer; }
        .close:hover { color: #000; }
        
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 500; }
        .form-group input { width: 100%; padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px; }
        .form-group input:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.2); }
        .form-group input[readonly] { background: #f5f5f5; cursor: not-allowed; }
        .empty-state { text-align: center; padding: 40px; color: var(--text-secondary-color); }
        .required-asterisk { color: var(--danger-color); margin-right: 4px; }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            <div class="content">
                <div class="breadcrumb">
                    <a href="index.php">首頁</a> / <a href="continued_admission_list.php">續招報名管理</a> / <?php echo $page_title; ?>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-graduation-cap"></i> <?php echo $page_title; ?></h3>
                        <a href="continued_admission_list.php" class="btn-secondary">
                            <i class="fas fa-arrow-left"></i> 返回續招報名管理
                        </a>
                    </div>
                    <div class="card-body">
                        <div id="quotaGrid" class="quota-grid">
                            <!-- 科系名額卡片將由 JavaScript 動態載入 -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 編輯名額模態框 -->
    <div id="quotaModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="quotaModalTitle">編輯名額</h3>
                <span class="close" onclick="closeQuotaModal()">&times;</span>
            </div>
            <form id="quotaForm">
                <input type="hidden" id="quotaDepartmentId" name="id"> <!-- This will be course_id -->
                <input type="hidden" id="quotaDepartmentNameHidden" name="name">
                <div class="form-group">
                    <label for="quotaDepartmentName">科系名稱</label>
                    <input type="text" id="quotaDepartmentName" readonly>
                </div>
                <div class="form-group">
                    <label for="quotaValue"><span class="required-asterisk">*</span>招生名額</label>
                    <input type="number" id="quotaValue" name="total_quota" min="0" required>
                </div>
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" onclick="closeQuotaModal()" class="btn-secondary">取消</button>
                    <button type="submit" class="btn-primary">更新</button>
                </div>
            </form>
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

    function loadQuotas() {
        fetch('department_quota_api.php?action=get_quotas')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderQuotaGrid(data.data);
                } else {
                    showToast('載入資料失敗: ' + data.message, false);
                }
            })
            .catch(error => {
                showToast('載入資料失敗: ' + error.message, false);
            });
    }

    function renderQuotaGrid(quotas) {
        const grid = document.getElementById('quotaGrid');
        if (quotas.length === 0) {
            grid.innerHTML = '<div class="empty-state"><i class="fas fa-graduation-cap fa-3x" style="margin-bottom: 16px;"></i><p>尚無科系資料</p></div>';
            return;
        }

        grid.innerHTML = quotas.map(quota => `
            <div class="quota-card">
                <div class="quota-header">
                    <h4>${quota.name}</h4>
                    <!-- <span class="quota-code">${quota.code}</span> -->
                </div>
                <div class="quota-stats">
                    <div class="stat-item">
                        <span class="stat-label">總名額</span>
                        <span class="stat-value total">${quota.total_quota}</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">已錄取</span>
                        <span class="stat-value enrolled">${quota.current_enrolled}</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">剩餘名額</span>
                        <span class="stat-value remaining ${quota.remaining <= 0 ? 'full' : ''}">${Math.max(0, quota.remaining)}</span>
                    </div>
                </div>
                <div class="quota-actions">
                    <button onclick="editQuota(${quota.id}, '${quota.name}', ${quota.total_quota})" class="btn btn-secondary">
                        <i class="fas fa-edit"></i> 編輯名額
                    </button>
                </div>
            </div>
        `).join('');
    }

    function closeQuotaModal() {
        document.getElementById('quotaModal').style.display = 'none';
    }

    function editQuota(id, name, quota) {
        document.getElementById('quotaModalTitle').textContent = `編輯 ${name} 名額`;
        document.getElementById('quotaDepartmentId').value = id; // This is course_id
        document.getElementById('quotaDepartmentName').value = name;
        document.getElementById('quotaDepartmentNameHidden').value = name; // Pass name for API
        document.getElementById('quotaValue').value = quota;
        document.getElementById('quotaModal').style.display = 'block';
    }

    document.getElementById('quotaForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const data = Object.fromEntries(formData);
        
        fetch('department_quota_api.php?action=update_or_add_quota', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('名額更新成功');
                closeQuotaModal();
                loadQuotas();
            } else {
                showToast('更新失敗: ' + data.message, false);
            }
        })
        .catch(error => {
            showToast('更新失敗: ' + error.message, false);
        });
    });

    // 點擊模態框外部關閉
    window.onclick = function(event) {
        const quotaModal = document.getElementById('quotaModal');
        if (event.target === quotaModal) {
            closeQuotaModal();
        }
    }

    // 頁面載入時載入資料
    document.addEventListener('DOMContentLoaded', function() {
        loadQuotas();
    });
    </script>
</body>
</html>
