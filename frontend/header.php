<?php
// 設置頁面標題，如果沒有傳入則使用預設值
$page_title = isset($page_title) ? $page_title : '後台管理系統';
?>

<!-- 頁面標題欄 -->
<div class="page-header">
    <div class="header-content">
        <div class="page-title">
            <?php echo htmlspecialchars($page_title); ?>
        </div>
        <div class="header-right">
            <div class="notification-icon">
                <i class="fas fa-bell"></i>
                <span class="notification-badge">3</span>
            </div>
            <div class="user-info">
                <div class="user-avatar">A</div>
                <span class="user-name">admin</span>
            </div>
        </div>
    </div>
</div>

<style>
/* 頁面標題欄樣式 */
.page-header {
    background: #fff;
    border-bottom: 1px solid #f0f0f0;
    padding: 0;
    position: sticky;
    top: 0;
    z-index: 999;
}

.header-content {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 24px;
    max-width: 100%;
}

.page-title {
    font-size: 24px;
    font-weight: 600;
    color: #262626;
    margin: 0;
}

.header-right {
    display: flex;
    align-items: center;
    gap: 20px;
}

.notification-icon {
    position: relative;
    cursor: pointer;
    color: #595959;
    font-size: 18px;
    transition: color 0.3s;
}

.notification-icon:hover {
    color: #1890ff;
}

.notification-badge {
    position: absolute;
    top: -8px;
    right: -8px;
    background: #ff4d4f;
    color: #fff;
    font-size: 12px;
    font-weight: 600;
    border-radius: 50%;
    width: 18px;
    height: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
}

.user-info {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 6px;
    transition: background-color 0.3s;
}

.user-info:hover {
    background: #f5f5f5;
}

.user-avatar {
    width: 32px;
    height: 32px;
    background: #f0f0f0;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    color: #595959;
    font-size: 14px;
}

.user-name {
    font-size: 14px;
    color: #262626;
    font-weight: 500;
}

/* 響應式設計 */
@media (max-width: 768px) {
    .header-content {
        padding: 12px 16px;
    }
    
    .page-title {
        font-size: 20px;
    }
    
    .header-right {
        gap: 16px;
    }
    
    .user-name {
        display: none;
    }
}
</style>
