<?php
// 設置頁面標題，如果沒有傳入則使用預設值
$page_title = isset($page_title) ? $page_title : '後台管理系統';

// 獲取使用者顯示名稱
$user_display_name = $_SESSION['name'] ?? $_SESSION['username'] ?? 'Admin';

?>

<!-- 頁面標題欄 -->
<div class="page-header">
    <div class="header-content">
        <div class="page-title">
            <?php echo htmlspecialchars($page_title); ?>
        </div>
        <div class="header-right">
            <div class="user-info" id="userInfoDropdown">
                <div class="user-avatar">
                    <i class="fas fa-user"></i>
                </div>
                <span class="user-name"><?php echo htmlspecialchars($user_display_name); ?></span>
                <i class="fas fa-chevron-down dropdown-arrow"></i>
                <div class="dropdown-menu" id="userDropdownMenu">
                    <a href="logout.php" class="dropdown-item">
                        <i class="fas fa-sign-out-alt"></i> 登出
                    </a>
                </div>
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
    padding: 18px 28px; /* 增加上下和左右的間距 */
    max-width: 100%;
}

.page-title {
    font-size: 26px; /* 放大標題字體 */
    font-weight: 600;
    color: #262626;
    margin: 0;
}

.header-right {
    display: flex;
    align-items: center;
    gap: 20px;
}

.user-info {
    display: flex;
    position: relative; /* 為了下拉選單定位 */
    align-items: center;
    gap: 12px; /* 增加頭像和名字的間距 */
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 6px;
    transition: background-color 0.3s;
}

.user-info:hover {
    background: #f5f5f5;
}

.user-avatar {
    width: 36px; /* 放大頭像 */
    height: 36px; /* 放大頭像 */
    background: #f0f0f0;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: normal;
    color: #595959;
    font-size: 16px; /* 放大頭像內的圖示 */
}

.user-name {
    font-size: 16px; /* 放大使用者名稱 */
    color: #262626;
    font-weight: 500;
}

.dropdown-arrow {
    font-size: 12px;
    color: #8c8c8c;
    transition: transform 0.3s;
}

.user-info.open .dropdown-arrow {
    transform: rotate(180deg);
}

.dropdown-menu {
    display: none;
    position: absolute;
    top: 100%;
    right: 0;
    background-color: #fff;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    border: 1px solid #f0f0f0;
    min-width: 160px;
    z-index: 1000;
    padding: 8px 0;
    margin-top: 8px;
}

.dropdown-menu.show {
    display: block;
}

.dropdown-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 16px;
    color: #595959;
    text-decoration: none;
    font-size: 14px;
    transition: background-color 0.3s;
}

.dropdown-item i {
    width: 16px;
}

.dropdown-item:hover {
    background-color: #f5f5f5;
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    const userInfoDropdown = document.getElementById('userInfoDropdown');
    const userDropdownMenu = document.getElementById('userDropdownMenu');

    if (userInfoDropdown) {
        userInfoDropdown.addEventListener('click', function(event) {
            event.stopPropagation();
            this.classList.toggle('open');
            userDropdownMenu.classList.toggle('show');
        });
    }

    document.addEventListener('click', function(event) {
        if (userInfoDropdown && !userInfoDropdown.contains(event.target)) {
            userInfoDropdown.classList.remove('open');
            userDropdownMenu.classList.remove('show');
        }
    });
});
</script>
