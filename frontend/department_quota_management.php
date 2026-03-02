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
        .modal-content { background-color: #fff; margin: 5% auto; padding: 20px; border-radius: 8px; width: 90%; max-width: 560px; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-header h3 { margin: 0; }
        .close { color: #aaa; font-size: 28px; font-weight: bold; cursor: pointer; }
        .close:hover { color: #000; }
        
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 500; }
        .form-group input { width: 100%; padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px; }
        .form-group input:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.2); }
        .form-group input[readonly] { background: #f5f5f5; cursor: not-allowed; }

        .datetime-range {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .datetime-range input[type="datetime-local"] {
            flex: 1;
            min-width: 0;
        }
        .datetime-range span {
            white-space: nowrap;
            color: #595959;
            font-size: 13px;
        }

        @media (max-width: 520px) {
            .modal-content {
                width: 95%;
                margin: 10% auto;
            }
            .datetime-range {
                flex-direction: column;
                align-items: stretch;
            }
            .datetime-range span {
                display: none;
            }
        }
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

                <!-- 統一時間 / 錄取分數設定卡片 -->
                <div class="card" style="margin-bottom: 16px;">
                    <div class="card-header">
                        <h3><i class="fas fa-clock"></i> 統一續招時間與錄取分數設定（全部科系）</h3>
                    </div>
                    <div class="card-body">
                        <div class="form-group" style="max-width: 540px;">
                            <label>統一續招報名時間區間</label>
                            <div class="datetime-range">
                                <input type="datetime-local" id="globalRegisterStart" min="">
                                <span>至</span>
                                <input type="datetime-local" id="globalRegisterEnd" min="">
                            </div>
                        </div>
                        <div class="form-group" style="max-width: 540px;">
                            <label>統一審查書面資料時間區間</label>
                            <div class="datetime-range">
                                <input type="datetime-local" id="globalReviewStart" min="">
                                <span>至</span>
                                <input type="datetime-local" id="globalReviewEnd" min="">
                            </div>
                        </div>
                        <div class="form-group" style="max-width: 360px;">
                            <label>統一錄取公告時間</label>
                            <input type="datetime-local" id="globalAnnounceTime" min="">
                        </div>
                        <div class="form-group" style="max-width: 260px;">
                            <label>統一錄取分數（全部科系）</label>
                            <input type="number" id="globalCutoffScore" min="0" step="1" placeholder="例如：60">
                        </div>
                        <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                            <button id="applyGlobalRegisterTimeBtn" class="btn-primary">
                                <i class="fas fa-check"></i> 套用報名 / 審查 / 公告時間
                            </button>
                            <button id="applyGlobalCutoffBtn" class="btn-secondary">
                                <i class="fas fa-signal"></i> 套用錄取分數到全部科系
                            </button>
                            <span style="font-size: 13px; color: #8c8c8c;">
                                說明：此處設定後，會將報名時間、審查書面資料時間、錄取公告時間與錄取分數套用到所有已啟用的科系；前台續招報名表的開放時間依報名時間區間判斷。
                            </span>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-graduation-cap">   </i> <?php echo $page_title; ?></h3>
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
                    <label for="quotaValue"><span class="required-asterisk">*</span>續招名額</label>
                    <input type="number" id="quotaValue" name="total_quota" min="0" required>
                </div>
                <div class="form-group">
                    <label for="cutoffScore">錄取分數（門檻）</label>
                    <input type="number" id="cutoffScore" name="cutoff_score" min="0" step="1" placeholder="例如：60，留空表示不設限">
                </div>
                <div class="form-group">
                    <label>報名時間區間</label>
                    <div class="datetime-range">
                        <input type="datetime-local" id="registerStart" name="register_start" min="">
                        <span>至</span>
                        <input type="datetime-local" id="registerEnd" name="register_end" min="">
                    </div>
                </div>
                <div class="form-group">
                    <label>審查書面資料時間</label>
                    <div class="datetime-range">
                        <input type="datetime-local" id="reviewStart" name="review_start" min="">
                        <span>至</span>
                        <input type="datetime-local" id="reviewEnd" name="review_end" min="">
                    </div>
                </div>
                <div class="form-group">
                    <label for="announceTime">錄取公告時間</label>
                    <input type="datetime-local" id="announceTime" name="announce_time" min="">
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
    // 取得當前時間字串（用於 datetime-local 的 min 屬性）
    function getCurrentDateTimeLocal() {
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        return `${year}-${month}-${day}T${hours}:${minutes}`;
    }

    // 初始化所有時間輸入欄位的 min 值
    function initDateTimeInputs() {
        const currentTime = getCurrentDateTimeLocal();
        const allDateTimeInputs = document.querySelectorAll('input[type="datetime-local"]');
        allDateTimeInputs.forEach(input => {
            input.setAttribute('min', currentTime);
        });
    }

    // 設定時間範圍驗證（當開始時間改變時，更新結束時間的 min 值）
    function setupDateTimeRangeValidation() {
        // 統一報名時間
        const globalRegisterStart = document.getElementById('globalRegisterStart');
        const globalRegisterEnd = document.getElementById('globalRegisterEnd');
        if (globalRegisterStart && globalRegisterEnd) {
            globalRegisterStart.addEventListener('change', function() {
                if (this.value) {
                    globalRegisterEnd.setAttribute('min', this.value);
                }
            });
        }

        // 統一審查時間
        const globalReviewStart = document.getElementById('globalReviewStart');
        const globalReviewEnd = document.getElementById('globalReviewEnd');
        if (globalReviewStart && globalReviewEnd) {
            globalReviewStart.addEventListener('change', function() {
                if (this.value) {
                    globalReviewEnd.setAttribute('min', this.value);
                }
            });
        }

        // 個別科系報名時間
        const registerStart = document.getElementById('registerStart');
        const registerEnd = document.getElementById('registerEnd');
        if (registerStart && registerEnd) {
            registerStart.addEventListener('change', function() {
                if (this.value) {
                    registerEnd.setAttribute('min', this.value);
                }
            });
        }

        // 個別科系審查時間
        const reviewStart = document.getElementById('reviewStart');
        const reviewEnd = document.getElementById('reviewEnd');
        if (reviewStart && reviewEnd) {
            reviewStart.addEventListener('change', function() {
                if (this.value) {
                    reviewEnd.setAttribute('min', this.value);
                }
            });
        }
    }

    // 驗證時間是否為過去時間
    function validateDateTimeInput(value, fieldName) {
        if (!value) return null; // 空值允許（可選欄位）
        const inputTime = new Date(value);
        const now = new Date();
        if (inputTime < now) {
            return `${fieldName}不能設定為過去的時間`;
        }
        return null;
    }

    // 驗證時間範圍（結束時間必須晚於開始時間）
    function validateTimeRange(start, end, startFieldName, endFieldName) {
        if (!start || !end) return null; // 如果其中一個為空，跳過驗證（可選欄位）
        const startTime = new Date(start);
        const endTime = new Date(end);
        if (endTime <= startTime) {
            return `${endFieldName}必須晚於${startFieldName}`;
        }
        return null;
    }

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

    // 載入統一時間（報名／審查／公告）
    function loadGlobalRegisterTime() {
        fetch('department_quota_api.php?action=get_global_register_time')
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    return;
                }
                const times = data.data || {};
                const startInput = document.getElementById('globalRegisterStart');
                const endInput = document.getElementById('globalRegisterEnd');
                const reviewStartInput = document.getElementById('globalReviewStart');
                const reviewEndInput = document.getElementById('globalReviewEnd');
                const announceInput = document.getElementById('globalAnnounceTime');

                const toInput = (val) => {
                    if (!val) return '';
                    return val.replace(' ', 'T').substring(0, 16);
                };

                // 重新設定 min 值（確保不會選擇過去的時間）
                const currentTime = getCurrentDateTimeLocal();
                if (startInput) {
                    startInput.setAttribute('min', currentTime);
                    startInput.value = toInput(times.register_start);
                }
                if (endInput) {
                    endInput.setAttribute('min', currentTime);
                    endInput.value = toInput(times.register_end);
                    // 如果開始時間有值，更新結束時間的 min
                    if (startInput && startInput.value) {
                        endInput.setAttribute('min', startInput.value);
                    }
                }
                if (reviewStartInput) {
                    reviewStartInput.setAttribute('min', currentTime);
                    reviewStartInput.value = toInput(times.review_start);
                }
                if (reviewEndInput) {
                    reviewEndInput.setAttribute('min', currentTime);
                    reviewEndInput.value = toInput(times.review_end);
                    // 如果開始時間有值，更新結束時間的 min
                    if (reviewStartInput && reviewStartInput.value) {
                        reviewEndInput.setAttribute('min', reviewStartInput.value);
                    }
                }
                if (announceInput) {
                    announceInput.setAttribute('min', currentTime);
                    announceInput.value = toInput(times.announce_time);
                }
            })
            .catch(() => {
                // 靜默失敗即可，不影響主流程
            });
    }

    function formatDateTime(value) {
        if (!value) return '未設定';
        // 後端預期回傳格式：YYYY-MM-DD HH:MM:SS
        // 這裡只顯示到分鐘
        return value.replace('T', ' ').substring(0, 16);
    }

    function renderQuotaGrid(quotas) {
        const grid = document.getElementById('quotaGrid');
        
        // 過濾掉科系代碼為 AA 的科系
        quotas = quotas.filter(quota => quota.code !== 'AA');
        
        if (quotas.length === 0) {
            grid.innerHTML = '<div class="empty-state"><i class="fas fa-graduation-cap fa-3x" style="margin-bottom: 16px;"></i><p>尚無科系資料</p></div>';
            return;
        }

        grid.innerHTML = quotas.map(quota => {
            const registerRange = quota.register_start && quota.register_end
                ? `${formatDateTime(quota.register_start)} ~ ${formatDateTime(quota.register_end)}`
                : '未設定';
            const reviewRange = quota.review_start && quota.review_end
                ? `${formatDateTime(quota.review_start)} ~ ${formatDateTime(quota.review_end)}`
                : '未設定';
            const announceTime = quota.announce_time
                ? formatDateTime(quota.announce_time)
                : '未設定';

            return `
            <div class="quota-card">
                <div class="quota-header">
                    <h4>${quota.name}</h4>
                    <!-- <span class="quota-code">${quota.code}</span> -->
                </div>
                <div class="quota-stats">
                    <div class="stat-item">
                        <span class="stat-label">續招名額</span>
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
                <div style="font-size: 13px; color: #595959; margin-top: 8px; line-height: 1.6;">
                    <div><strong>錄取分數：</strong>${quota.cutoff_score !== null && quota.cutoff_score !== undefined ? quota.cutoff_score : '未設定'}</div>
                    <div><strong>報名時間：</strong>${registerRange}</div>
                    <div><strong>審查書面資料時間：</strong>${reviewRange}</div>
                    <div><strong>錄取公告時間：</strong>${announceTime}</div>
                </div>
                <div class="quota-actions">
                    <button onclick="editQuota(${quota.id}, '${quota.name}', ${quota.total_quota}, ${quota.cutoff_score ?? 'null'}, '${quota.register_start ?? ''}', '${quota.register_end ?? ''}', '${quota.review_start ?? ''}', '${quota.review_end ?? ''}', '${quota.announce_time ?? ''}')" class="btn btn-secondary">
                        <i class="fas fa-edit"></i> 編輯設定
                    </button>
                </div>
            </div>
        `}).join('');
    }

    function closeQuotaModal() {
        document.getElementById('quotaModal').style.display = 'none';
    }

    function toInputDateTime(value) {
        if (!value) return '';
        // value 可能為 'YYYY-MM-DD HH:MM:SS' 或 'YYYY-MM-DDTHH:MM:SS'
        let v = value.replace(' ', 'T');
        return v.substring(0, 16);
    }

    function editQuota(id, name, quota, cutoffScore, registerStart, registerEnd, reviewStart, reviewEnd, announceTime) {
        document.getElementById('quotaModalTitle').textContent = `編輯 ${name} 名額`;
        document.getElementById('quotaDepartmentId').value = id; // This is course_id
        document.getElementById('quotaDepartmentName').value = name;
        document.getElementById('quotaDepartmentNameHidden').value = name; // Pass name for API
        document.getElementById('quotaValue').value = quota;
        document.getElementById('cutoffScore').value = cutoffScore != null ? cutoffScore : '';
        
        // 重新設定 min 值（確保不會選擇過去的時間）
        const currentTime = getCurrentDateTimeLocal();
        const registerStartInput = document.getElementById('registerStart');
        const registerEndInput = document.getElementById('registerEnd');
        const reviewStartInput = document.getElementById('reviewStart');
        const reviewEndInput = document.getElementById('reviewEnd');
        const announceTimeInput = document.getElementById('announceTime');
        
        registerStartInput.setAttribute('min', currentTime);
        registerEndInput.setAttribute('min', currentTime);
        reviewStartInput.setAttribute('min', currentTime);
        reviewEndInput.setAttribute('min', currentTime);
        announceTimeInput.setAttribute('min', currentTime);
        
        // 設定值
        registerStartInput.value = toInputDateTime(registerStart);
        registerEndInput.value = toInputDateTime(registerEnd);
        reviewStartInput.value = toInputDateTime(reviewStart);
        reviewEndInput.value = toInputDateTime(reviewEnd);
        announceTimeInput.value = toInputDateTime(announceTime);
        
        // 如果開始時間有值，更新結束時間的 min
        if (registerStartInput.value) {
            registerEndInput.setAttribute('min', registerStartInput.value);
        }
        if (reviewStartInput.value) {
            reviewEndInput.setAttribute('min', reviewStartInput.value);
        }
        
        document.getElementById('quotaModal').style.display = 'block';
    }

    document.getElementById('quotaForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        // 驗證時間
        const registerStart = document.getElementById('registerStart').value;
        const registerEnd = document.getElementById('registerEnd').value;
        const reviewStart = document.getElementById('reviewStart').value;
        const reviewEnd = document.getElementById('reviewEnd').value;
        const announceTime = document.getElementById('announceTime').value;

        // 驗證所有時間都不是過去時間
        let error = validateDateTimeInput(registerStart, '報名開始時間');
        if (error) {
            showToast(error, false);
            return;
        }
        error = validateDateTimeInput(registerEnd, '報名結束時間');
        if (error) {
            showToast(error, false);
            return;
        }
        error = validateDateTimeInput(reviewStart, '審查開始時間');
        if (error) {
            showToast(error, false);
            return;
        }
        error = validateDateTimeInput(reviewEnd, '審查結束時間');
        if (error) {
            showToast(error, false);
            return;
        }
        error = validateDateTimeInput(announceTime, '錄取公告時間');
        if (error) {
            showToast(error, false);
            return;
        }

        // 驗證時間範圍
        error = validateTimeRange(registerStart, registerEnd, '報名開始時間', '報名結束時間');
        if (error) {
            showToast(error, false);
            return;
        }
        error = validateTimeRange(reviewStart, reviewEnd, '審查開始時間', '審查結束時間');
        if (error) {
            showToast(error, false);
            return;
        }
        
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

    // 套用統一報名時間按鈕
    const applyGlobalBtn = document.getElementById('applyGlobalRegisterTimeBtn');
    if (applyGlobalBtn) {
        applyGlobalBtn.addEventListener('click', function () {
            const start = document.getElementById('globalRegisterStart').value;
            const end = document.getElementById('globalRegisterEnd').value;
            const reviewStart = document.getElementById('globalReviewStart').value;
            const reviewEnd = document.getElementById('globalReviewEnd').value;
            const announceTime = document.getElementById('globalAnnounceTime').value;

            if (!start || !end) {
                showToast('請先填寫完整的統一報名起訖時間', false);
                return;
            }

            // 驗證所有時間都不是過去時間
            let error = validateDateTimeInput(start, '統一報名開始時間');
            if (error) {
                showToast(error, false);
                return;
            }
            error = validateDateTimeInput(end, '統一報名結束時間');
            if (error) {
                showToast(error, false);
                return;
            }
            if (reviewStart) {
                error = validateDateTimeInput(reviewStart, '統一審查開始時間');
                if (error) {
                    showToast(error, false);
                    return;
                }
            }
            if (reviewEnd) {
                error = validateDateTimeInput(reviewEnd, '統一審查結束時間');
                if (error) {
                    showToast(error, false);
                    return;
                }
            }
            if (announceTime) {
                error = validateDateTimeInput(announceTime, '統一錄取公告時間');
                if (error) {
                    showToast(error, false);
                    return;
                }
            }

            // 驗證時間範圍
            error = validateTimeRange(start, end, '統一報名開始時間', '統一報名結束時間');
            if (error) {
                showToast(error, false);
                return;
            }
            if (reviewStart && reviewEnd) {
                error = validateTimeRange(reviewStart, reviewEnd, '統一審查開始時間', '統一審查結束時間');
                if (error) {
                    showToast(error, false);
                    return;
                }
            }

            fetch('department_quota_api.php?action=update_global_register_time', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    register_start: start,
                    register_end: end,
                    review_start: reviewStart,
                    review_end: reviewEnd,
                    announce_time: announceTime
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('已套用統一報名時間到所有科系');
                    loadQuotas();
                } else {
                    showToast('更新失敗: ' + data.message, false);
                }
            })
            .catch(error => {
                showToast('更新失敗: ' + error.message, false);
            });
        });
    }

    // 套用統一錄取分數按鈕
    const applyGlobalCutoffBtn = document.getElementById('applyGlobalCutoffBtn');
    if (applyGlobalCutoffBtn) {
        applyGlobalCutoffBtn.addEventListener('click', function () {
            const input = document.getElementById('globalCutoffScore');
            if (!input) return;

            const rawValue = input.value;
            if (rawValue === '') {
                showToast('請先輸入統一錄取分數', false);
                return;
            }

            const score = parseInt(rawValue, 10);
            if (isNaN(score) || score < 0) {
                showToast('錄取分數必須是 0 以上的整數', false);
                return;
            }

            fetch('department_quota_api.php?action=update_global_cutoff_score', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ cutoff_score: score })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('已套用統一錄取分數到所有科系');
                    loadQuotas();
                } else {
                    showToast('更新失敗: ' + data.message, false);
                }
            })
            .catch(error => {
                showToast('更新失敗: ' + error.message, false);
            });
        });
    }

    // 點擊模態框外部關閉
    window.onclick = function(event) {
        const quotaModal = document.getElementById('quotaModal');
        if (event.target === quotaModal) {
            closeQuotaModal();
        }
    }

    // 頁面載入時載入資料
    document.addEventListener('DOMContentLoaded', function() {
        // 初始化時間輸入欄位的 min 值
        initDateTimeInputs();
        // 設定時間範圍驗證
        setupDateTimeRangeValidation();
        // 載入資料
        loadQuotas();
        loadGlobalRegisterTime();
    });
    </script>
</body>
</html>
