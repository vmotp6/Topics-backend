<?php
// 獲取當前頁面名稱來設置active狀態
$current_page = basename($_SERVER['PHP_SELF'], '.php');

// 獲取使用者角色
$user_role = $_SESSION['role'] ?? ''; // 預期為 ADM, STA, DI, TEA, STU 等代碼
$username = $_SESSION['username'] ?? ''; // 預期為用戶名

// 允許登入的角色：管理員、學校行政、主任、科助
$allowed_roles = ['ADM', 'STA', 'DI', 'TEA'];
$can_access = in_array($user_role, $allowed_roles);

// 權限判斷
$is_admin = ($user_role === 'ADM'); // 管理員：全部都可以
$is_staff = ($user_role === 'STA'); // 學校行政：首頁、就讀意願名單(全)、續招(全)、招生推薦、統計分析、入學說明會、學校聯絡人
$is_director = ($user_role === 'DI'); // 主任：首頁、就讀意願名單、續招(不能管理名單)、統計分析
$is_teacher = ($user_role === 'TEA'); // 科助：首頁、就讀意願名單、續招(不能管理名單)、統計分析
// -------------------------------------------------------------
?>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">後台</div>
        <button class="collapse-btn" id="collapseBtn" title="收合側邊欄">
            <i class="fas fa-bars"></i>
        </button>
    </div>
    
    <div class="sidebar-menu">
        <?php if ($can_access): // 允許登入的角色顯示主選單 ?>
            
            <!-- 首頁 - 所有角色都可以看到 -->
            <a href="index.php" class="menu-item <?php echo $current_page === 'index' ? 'active' : ''; ?>">
                <i class="fas fa-home"></i>
                <span>首頁</span>
            </a>
            
            <!-- 使用者管理 - 僅管理員 -->
            <?php if ($is_admin): ?>
                <a href="users.php" class="menu-item <?php echo in_array($current_page, ['users', 'edit_user', 'add_user']) ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i>
                    <span>使用者管理</span>
                </a>
            <?php endif; ?>

            <!-- 就讀意願名單 - 所有角色都可以看到 -->
            <a href="enrollment_list.php" class="menu-item <?php echo $current_page === 'enrollment_list' ? 'active' : ''; ?>">
                <i class="fas fa-file-signature"></i>
                <span>就讀意願名單</span>
            </a>

            <!-- 續招 - 所有角色都可以看到（但權限不同） -->
            <a href="continued_admission_list.php" class="menu-item <?php echo in_array($current_page, ['continued_admission_list', 'continued_admission_detail']) ? 'active' : ''; ?>">
                <i class="fas fa-user-plus"></i>
                <span>續招</span>
            </a>

            <!-- 招生推薦 - 僅學校行政和管理員 -->
            <?php if ($is_staff || $is_admin || $is_director): ?>
                <a href="admission_recommend_list.php" class="menu-item <?php echo $current_page === 'admission_recommend_list' ? 'active' : ''; ?>">
                    <i class="fas fa-user-friends"></i>
                    <span>招生推薦</span>
                </a>
            <?php endif; ?>
            
            <!-- 統計分析 - 所有角色都可以看到 -->
            <a href="activity_records.php" class="menu-item <?php echo $current_page === 'activity_records' ? 'active' : ''; ?>">
                <i class="fas fa-tasks"></i>
                <span>統計分析</span>
            </a>
            
            <!-- 教師活動紀錄 - 學校行政和主任可以看到 -->
            <?php if ($is_staff || $is_admin || $is_director): ?>
                <a href="teacher_activity_records.php" class="menu-item <?php echo $current_page === 'teacher_activity_records' ? 'active' : ''; ?>">
                    <i class="fas fa-clipboard-list"></i>
                    <span>教師活動紀錄</span>
                </a>
            <?php endif; ?>
            
            <!-- AI模型管理 - 僅管理員 -->
            <?php if ($is_admin): ?>
                <a href="ollama_admin.php" class="menu-item <?php echo $current_page === 'ollama_admin' ? 'active' : ''; ?>">
                    <i class="fas fa-robot"></i>
                    <span>AI模型管理</span>
                </a>
            <?php endif; ?>
            
            <!-- 學長姐留言管理 - 僅管理員 -->
            <?php if ($is_admin): ?>
                <a href="senior_messages_management.php" class="menu-item <?php echo $current_page === 'senior_messages_management' ? 'active' : ''; ?>">
                    <i class="fas fa-comments"></i>
                    <span>學長姐留言管理</span>
                </a>
            <?php endif; ?>
            
            <!-- 入學說明會 - 僅學校行政和管理員 -->
            <?php if ($is_staff || $is_admin): ?>
                <a href="settings.php" class="menu-item <?php echo $current_page === 'settings' ? 'active' : ''; ?>">
                    <i class="fas fa-cogs"></i>
                    <span>入學說明會</span>
                </a>
            <?php endif; ?>
            
            <!-- 招生問答管理 - 僅學校行政和管理員 -->
            <?php if ($is_staff || $is_admin): ?>
                <a href="qa_management.php" class="menu-item <?php echo $current_page === 'qa_management' ? 'active' : ''; ?>">
                    <i class="fas fa-question-circle"></i>
                    <span>招生問答管理</span>
                </a>
            <?php endif; ?>
            
            <!-- 學校聯絡人 - 僅學校行政和管理員 -->
            <?php if ($is_staff || $is_admin): ?>
                <a href="school_contacts.php" class="menu-item <?php echo $current_page === 'school_contacts' ? 'active' : ''; ?>">
                    <i class="fas fa-address-book"></i>
                    <span>學校聯絡人</span>
                </a>
            <?php endif; ?>
            
            <!-- 國中招生申請名單 - 僅學校行政和管理員 -->
            <?php if ($is_staff || $is_admin): ?>
                <a href="mobile_junior_B.php" class="menu-item <?php echo $current_page === 'mobile_junior_B' ? 'active' : ''; ?>">
                    <i class="fas fa-school"></i>
                    <span>國中招生申請名單</span>
                </a>
            <?php endif; ?>

            <a href="page_management.php" class="menu-item <?php echo $current_page === 'page_management' ? 'active' : ''; ?>">
                <i class="fas fa-file-alt"></i>
                <span>頁面管理</span>
            </a>
        <?php endif; // 結束主選單的判斷 ?>

        <a href="logout.php" class="menu-item">
            <i class="fas fa-sign-out-alt"></i>
            <span>登出</span>
        </a>
    </div>
</div>

<style>
/* 側邊欄樣式 (保持不變) */
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
document.addEventListener('DOMContentLoaded', function() {
    const collapseBtn = document.getElementById('collapseBtn');
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    
    // 1. 定義更新 UI 的函式 (包含圖示切換)
    function updateSidebarState(isCollapsed) {
        if (isCollapsed) {
            sidebar.classList.add('collapsed');
            if(mainContent) mainContent.classList.add('expanded');
            
            // 更新按鈕為 "開啟" 狀態的圖示
            if(collapseBtn) {
                const icon = collapseBtn.querySelector('i');
                if(icon) icon.className = 'fas fa-chevron-right';
                collapseBtn.title = '開啟側邊欄';
            }
        } else {
            sidebar.classList.remove('collapsed');
            if(mainContent) mainContent.classList.remove('expanded');
            
            // 更新按鈕為 "收合" 狀態的圖示
            if(collapseBtn) {
                const icon = collapseBtn.querySelector('i');
                if(icon) icon.className = 'fas fa-bars';
                collapseBtn.title = '收合側邊欄';
            }
        }
    }

    // 2. 頁面載入時：檢查 localStorage 裡是否有紀錄狀態
    // 如果紀錄是 'true'，就保持縮起
    const savedState = localStorage.getItem('sidebarCollapsed') === 'true';
    updateSidebarState(savedState);

    // 3. 點擊按鈕時：切換狀態並儲存到 localStorage
    if (collapseBtn) {
        collapseBtn.addEventListener('click', function() {
            // 切換 class
            const isNowCollapsed = sidebar.classList.toggle('collapsed');
            
            // 同步主內容區
            if (mainContent) {
                mainContent.classList.toggle('expanded');
            }

            // 儲存狀態到瀏覽器 (這樣重新整理或換頁後才會記得)
            localStorage.setItem('sidebarCollapsed', isNowCollapsed);

            // 更新按鈕圖示
            // 注意：這裡因為我們剛剛 toggle 了 class，所以直接用 sidebar 的狀態來判斷
            if (isNowCollapsed) {
                this.querySelector('i').className = 'fas fa-chevron-right';
                this.title = '開啟側邊欄';
            } else {
                this.querySelector('i').className = 'fas fa-bars';
                this.title = '收合側邊欄';
            }
        });
    }
});
</script>