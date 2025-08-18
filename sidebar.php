<?php
// 獲取當前頁面名稱來設置active狀態
$current_page = basename($_SERVER['PHP_SELF'], '.php');
?>

<!-- 側邊欄 -->
<div class="sidebar" id="sidebar">
    <button class="collapse-btn" id="collapseBtn">
        <i class="fas fa-bars"></i>
    </button>
    
    <div class="sidebar-header">
        <div class="sidebar-logo">後台管理系統</div>
    </div>
    
    
    <div class="sidebar-menu">
        <a href="index.php" class="menu-item <?php echo $current_page === 'index' ? 'active' : ''; ?>">
            <i class="fas fa-home"></i>
            <span>首頁</span>
        </a>
        <a href="users.php" class="menu-item <?php echo in_array($current_page, ['users', 'edit_user']) ? 'active' : ''; ?>">
            <i class="fas fa-users"></i>
            <span>使用者管理</span>
        </a>
        <a href="#" class="menu-item">
            <i class="fas fa-chart-bar"></i>
            <span>統計分析</span>
        </a>
        <a href="#" class="menu-item">
            <i class="fas fa-cog"></i>
            <span>系統設定</span>
        </a>
        <a href="?action=logout" class="menu-item">
            <i class="fas fa-sign-out-alt"></i>
            <span>登出</span>
        </a>
    </div>
</div>

<style>
/* 側邊欄樣式 */
.sidebar {
    width: 280px;
    background: #fff;
    color: #262626;
    position: fixed;
    height: 100vh;
    overflow-y: auto;
    transition: all 0.3s ease;
    z-index: 1000;
    border-right: 1px solid #f0f0f0;
}

.sidebar.collapsed {
    width: 60px;
}

.sidebar-header {
    padding: 30px 20px;
    background: #fff;
    text-align: center;
    border-bottom: 1px solid #f0f0f0;
}

.sidebar-logo {
    font-size: 26px;
    font-weight: 600;
    color: #1890ff;
}

.sidebar.collapsed .sidebar-logo {
    font-size: 18px;
}

.user-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #f5f5f5;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 8px;
    font-size: 16px;
    color: #8c8c8c;
}

.sidebar.collapsed .user-avatar {
    width: 32px;
    height: 32px;
    font-size: 14px;
}

.user-name {
    font-weight: 500;
    margin-bottom: 4px;
    font-size: 16px;
    color: #262626;
}

.sidebar.collapsed .user-name {
    display: none;
}

.user-role {
    font-size: 14px;
    color: #8c8c8c;
}

.sidebar.collapsed .user-role {
    display: none;
}

.sidebar-menu {
    padding: 16px 0;
}

.sidebar.collapsed .sidebar-menu {
    padding: 8px 0;
}

.menu-item {
    padding: 12px 32px;
    display: flex;
    align-items: center;
    color: #595959;
    text-decoration: none;
    transition: all 0.3s;
    border-left: 3px solid transparent;
    font-size: 16px;
}

.sidebar.collapsed .menu-item {
    padding: 12px 8px;
    justify-content: center;
}

.menu-item:hover {
    background: #f5f5f5;
    color: #262626;
}

.menu-item.active {
    background: #e6f7ff;
    border-left-color: #1890ff;
    color: #1890ff;
}

.menu-item i {
    margin-right: 12px;
    width: 16px;
    text-align: center;
}

.sidebar.collapsed .menu-item i {
    margin-right: 0;
}

.sidebar.collapsed .menu-item span {
    display: none;
}

/* 收合按鈕 */
.collapse-btn {
    position: absolute;
    top: 16px;
    right: -12px;
    width: 24px;
    height: 24px;
    background: #fff;
    border: 1px solid #d9d9d9;
    border-radius: 50%;
    color: #595959;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    transition: all 0.3s;
    z-index: 1001;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.collapse-btn:hover {
    background: #f5f5f5;
    border-color: #1890ff;
    color: #1890ff;
}

/* 主內容區 */
.main-content {
    flex: 1;
    margin-left: 280px;
    background: #f0f2f5;
    transition: all 0.3s ease;
}

.main-content.expanded {
    margin-left: 60px;
}

/* 響應式設計 */
@media (max-width: 768px) {
    .sidebar {
        transform: translateX(-100%);
        transition: transform 0.3s;
    }
    
    .main-content {
        margin-left: 0;
    }
}
</style>

<script>
// 側邊欄收合功能
document.addEventListener('DOMContentLoaded', function() {
    const collapseBtn = document.getElementById('collapseBtn');
    if (collapseBtn) {
        collapseBtn.addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            
            // 更新按鈕圖標
            const icon = this.querySelector('i');
            if (sidebar.classList.contains('collapsed')) {
                icon.className = 'fas fa-chevron-right';
            } else {
                icon.className = 'fas fa-bars';
            }
        });
    }
});
</script>
