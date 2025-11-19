<?php
// 獲取當前頁面名稱來設置active狀態
$current_page = basename($_SERVER['PHP_SELF'], '.php');

// 獲取使用者角色
$user_role = $_SESSION['role'] ?? '';
$username = $_SESSION['username'] ?? '';

// 判斷是否為管理員 (包含多種可能的角色名稱)
$is_admin = in_array($user_role, ['admin', '管理員']) || $username === 'admin1';
?>

<!-- 側邊欄 -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">後台</div>
        <button class="collapse-btn" id="collapseBtn" title="收合側邊欄">
            <i class="fas fa-bars"></i>
        </button>
    </div>
    
    <div class="sidebar-menu">
        <?php if ($is_admin): // 管理員顯示所有項目 ?>
            <a href="index.php" class="menu-item <?php echo $current_page === 'index' ? 'active' : ''; ?>">
                <i class="fas fa-home"></i>
                <span>首頁</span>
            </a>
            <a href="users.php" class="menu-item <?php echo in_array($current_page, ['users', 'edit_user', 'add_user']) ? 'active' : ''; ?>">
                <i class="fas fa-users"></i>
                <span>使用者管理</span>
            </a>
            <a href="enrollment_list.php" class="menu-item <?php echo $current_page === 'enrollment_list' ? 'active' : ''; ?>">
                <i class="fas fa-file-signature"></i>
                <span>就讀意願名單</span>
            </a>
            <a href="activity_records.php" class="menu-item <?php echo $current_page === 'activity_records' ? 'active' : ''; ?>">
                <i class="fas fa-tasks"></i>
                <span>統計分析</span>
            </a>
            <a href="page_management.php" class="menu-item <?php echo $current_page === 'page_management' ? 'active' : ''; ?>">
                <i class="fas fa-cog"></i>
                <span>頁面管理</span>
            </a>
            <a href="ollama_admin.php" class="menu-item <?php echo $current_page === 'ollama_admin' ? 'active' : ''; ?>">
                <i class="fas fa-robot"></i>
                <span>AI模型管理</span>
            </a>
            <a href="settings.php" class="menu-item <?php echo $current_page === 'settings' ? 'active' : ''; ?>">
                <i class="fas fa-cog"></i>
                <span>場次設定</span>
            </a>
            <a href="continued_admission_list.php" class="menu-item <?php echo in_array($current_page, ['continued_admission_list', 'continued_admission_detail']) ? 'active' : ''; ?>">
                <i class="fas fa-user-plus"></i>
                <span>續招</span>
            </a>
            <a href="admission_center.php" class="menu-item <?php echo $current_page === 'admission_center' ? 'active' : ''; ?>">
                <i class="fas fa-graduation-cap"></i>
                <span>招生中心</span>
            </a>
            <?php if ($username === 'admin1'): ?>
            <a href="admission_recommend_list.php" class="menu-item <?php echo $current_page === 'admission_recommend_list' ? 'active' : ''; ?>">
                <i class="fas fa-user-friends"></i>
                <span>招生推薦</span>
            </a>
            <a href="school_contacts.php" class="menu-item <?php echo $current_page === 'school_contacts' ? 'active' : ''; ?>">
                <i class="fas fa-address-book"></i>
                <span>學校聯絡人</span>
            </a>
            <?php endif; ?>
        <?php elseif ($user_role === '學校行政人員'): // 學校行政人員的權限 ?>
            <a href="enrollment_list.php" class="menu-item <?php echo $current_page === 'enrollment_list' ? 'active' : ''; ?>">
                <i class="fas fa-file-signature"></i>
                <span>就讀意願名單</span>
            </a>
            <a href="activity_records.php" class="menu-item <?php echo $current_page === 'activity_records' ? 'active' : ''; ?>">
                <i class="fas fa-tasks"></i>
                <span>統計分析</span>
            </a>
            <a href="settings.php" class="menu-item <?php echo $current_page === 'settings' ? 'active' : ''; ?>">
                <i class="fas fa-cog"></i>
                <span>場次設定</span>
            </a>
        <?php endif; ?>

        <a href="logout.php" class="menu-item">
            <i class="fas fa-sign-out-alt"></i>
            <span>登出</span>
        </a>
    </div>
</div>

<style>
/* 側邊欄樣式 */
.sidebar {
    width: 250px;
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
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: relative;
}

.sidebar.collapsed .sidebar-header {
    padding: 0;
    height: 60px;
    justify-content: center;
    border-bottom: none;
}

.sidebar-logo {
    font-size: 26px;
    font-weight: 600;
    color: #1890ff;
    transition: opacity 0.3s ease;
    flex: 1;
    text-align: center;
}

.sidebar.collapsed .sidebar-logo {
    opacity: 0;
    visibility: hidden;
    flex: 0;
    display: none;
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
    position: relative;
    width: 32px;
    height: 32px;
    background: #fff;
    border: 1px solid #d9d9d9;
    border-radius: 50%;
    color: #595959;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    transition: all 0.3s;
    z-index: 1001;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    flex-shrink: 0;
}

.sidebar.collapsed .collapse-btn {
    position: absolute;
    top: 16px;
    right: 14px;
}

.collapse-btn:hover {
    background: #f5f5f5;
    border-color: #1890ff;
    color: #1890ff;
    transform: scale(1.05);
}

/* 主內容區 */
.main-content {
    flex: 1;
    margin-left: 250px;
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
            
            // 更新按鈕圖標和提示文字
            const icon = this.querySelector('i');
            if (sidebar.classList.contains('collapsed')) {
                icon.className = 'fas fa-chevron-right';
                this.title = '開啟側邊欄';
            } else {
                icon.className = 'fas fa-bars';
                this.title = '收合側邊欄';
            }
        });
    }
});
</script>
