<?php
// 獲取當前頁面名稱來設置active狀態
$current_page = basename($_SERVER['PHP_SELF'], '.php');

// 獲取使用者角色
$user_role = $_SESSION['role'] ?? ''; // 預期為 ADM, STA, DI, TEA, STAM, IM, AS 等代碼
$username = $_SESSION['username'] ?? ''; // 預期為用戶名
$user_id = $_SESSION['user_id'] ?? null; // 用戶ID，用於權限檢查

// 簡單的調試：如果想看角色信息，可以在URL加上 ?show_debug=1
if (isset($_GET['show_debug']) && $_GET['show_debug'] == '1') {
    echo '<div style="background: #fff3cd; border: 2px solid #ffc107; padding: 15px; margin: 15px; border-radius: 4px; font-family: monospace; font-size: 12px; z-index: 9999; position: relative;">';
    echo '<strong style="font-size: 14px;">角色調試信息：</strong><br>';
    echo 'SESSION[role]: ' . htmlspecialchars($_SESSION['role'] ?? 'NOT SET') . '<br>';
    echo 'SESSION[username]: ' . htmlspecialchars($_SESSION['username'] ?? 'NOT SET') . '<br>';
    echo 'SESSION[user_id]: ' . htmlspecialchars($_SESSION['user_id'] ?? 'NOT SET') . '<br>';
    echo '</div>';
}

// 角色代碼映射：將中文名稱或舊代碼轉換為新代碼（向後兼容）
$original_role = $user_role; // 保存原始角色值用於調試
$role_map = [
    '管理員' => 'ADM',
    'admin' => 'ADM',
    'Admin' => 'ADM',
    '行政人員' => 'STA',
    '學校行政人員' => 'STA',
    'staff' => 'STA',
    '主任' => 'DI',
    'director' => 'DI',
    '老師' => 'TEA',
    'teacher' => 'TEA',
    '招生中心組員' => 'STAM',
    '資管科主任' => 'IM',
    '資管主任' => 'IM',
    'IM主任' => 'IM',
    '科助' => 'AS',
    'assistant' => 'AS',
    // 如果已經是正確代碼，保持不變
    'ADM' => 'ADM',
    'STA' => 'STA',
    'DI' => 'DI',
    'TEA' => 'TEA',
    'STAM' => 'STAM',
    'IM' => 'IM',
    'AS' => 'AS'
];
// 如果角色是中文名稱，轉換為代碼
if (isset($role_map[$user_role])) {
    $user_role = $role_map[$user_role];
} else {
    // 如果不在映射表中，嘗試模糊匹配
    // 檢查是否包含IM相關的關鍵字，自動映射為IM
    if (stripos($user_role, 'IM') !== false || stripos($user_role, '資管') !== false || stripos($user_role, '資訊管理') !== false) {
        $user_role = 'IM';
        if (isset($_GET['debug_role']) && $_GET['debug_role'] == '1') {
            error_log("Auto-mapped role to IM: " . $original_role);
        }
    }
    // 檢查是否包含AS相關的關鍵字，自動映射為AS
    elseif (stripos($user_role, 'AS') !== false || stripos($user_role, '科助') !== false) {
        $user_role = 'AS';
        if (isset($_GET['debug_role']) && $_GET['debug_role'] == '1') {
            error_log("Auto-mapped role to AS: " . $original_role);
        }
    }
}

// 允許登入的角色：管理員、學校行政、主任、科助、招生中心組員、資管科主任、科助
$allowed_roles = ['ADM', 'STA', 'DI', 'TEA', 'STAM', 'IM', 'AS'];
$can_access = in_array($user_role, $allowed_roles);

// 權限判斷（使用轉換後的標準代碼）
$is_admin = ($user_role === 'ADM'); // 管理員：全部都可以
$is_staff = ($user_role === 'STA'); // 學校行政：首頁、就讀意願名單(全)、續招(全)、招生推薦、統計分析、入學說明會、學校聯絡人
$is_director = ($user_role === 'DI'); // 主任：首頁、就讀意願名單、續招(不能管理名單)、統計分析
$is_teacher = ($user_role === 'TEA'); // 科助：首頁、就讀意願名單、續招(不能管理名單)、統計分析
$is_stam = ($user_role === 'STAM'); // 招生中心組員：根據分配的權限顯示
$is_as = ($user_role === 'AS'); // 科助：根據分配的權限顯示

// 檢查是否為資管科主任：role=DI 且部門代碼=IM
$is_im = false;
$user_department_code = null;

// 如果 user_id 不存在但有 username，先從資料庫獲取 user_id
if (!$user_id && $username) {
    require_once '../../Topics-frontend/frontend/config.php';
    $conn_get_id = getDatabaseConnection();
    try {
        $stmt_get_id = $conn_get_id->prepare("SELECT id FROM user WHERE username = ?");
        $stmt_get_id->bind_param("s", $username);
        $stmt_get_id->execute();
        $result_get_id = $stmt_get_id->get_result();
        if ($row_id = $result_get_id->fetch_assoc()) {
            $user_id = $row_id['id'];
            // 同時更新到 session 中，避免重複查詢
            $_SESSION['user_id'] = $user_id;
        }
        $stmt_get_id->close();
    } catch (Exception $e) {
        error_log('Error fetching user_id in sidebar: ' . $e->getMessage());
    }
    $conn_get_id->close();
}

if ($is_director && $user_id) {
    // 查詢用戶的部門代碼
    require_once '../../Topics-frontend/frontend/config.php';
    $conn_dept = getDatabaseConnection();
    try {
        // 優先從 director 表獲取部門代碼
        $table_check = $conn_dept->query("SHOW TABLES LIKE 'director'");
        if ($table_check && $table_check->num_rows > 0) {
            $stmt_dept = $conn_dept->prepare("SELECT department FROM director WHERE user_id = ?");
        } else {
            $stmt_dept = $conn_dept->prepare("SELECT department FROM teacher WHERE user_id = ?");
        }
        $stmt_dept->bind_param("i", $user_id);
        $stmt_dept->execute();
        $result_dept = $stmt_dept->get_result();
        if ($row = $result_dept->fetch_assoc()) {
            $user_department_code = $row['department'];
            // 如果部門代碼是'IM'，則為資管科主任
            if ($user_department_code === 'IM') {
                $is_im = true;
            }
        }
        $stmt_dept->close();
    } catch (Exception $e) {
        error_log('Error fetching user department in sidebar: ' . $e->getMessage());
    }
    $conn_dept->close();
} elseif ($user_role === 'IM' || $user_role === '資管科主任') {
    // 如果role已經是IM或資管科主任，直接設置為true
    $is_im = true;
}

// 額外的安全檢查：如果映射後的 user_role 是 IM，確保 is_im 一定為 true
if ($user_role === 'IM') {
    $is_im = true;
}
// 調試：檢查角色識別（臨時啟用以排查問題）
if (isset($_GET['debug_role']) && $_GET['debug_role'] == '1') {
    error_log("Sidebar Debug - Original role from session: " . ($_SESSION['role'] ?? 'NULL'));
    error_log("Sidebar Debug - Mapped role: " . $user_role);
    error_log("Sidebar Debug - is_im: " . ($is_im ? 'true' : 'false'));
    error_log("Sidebar Debug - username: " . ($username ?? 'NULL'));
}

// 如果是STAM角色，獲取其分配的權限
$stam_permissions = [];
if ($is_stam) {
    // 如果user_id不在session中，嘗試從username獲取
    if (!$user_id && $username) {
        require_once '../../Topics-frontend/frontend/config.php';
        $conn = getDatabaseConnection();
        $user_stmt = $conn->prepare("SELECT id FROM user WHERE username = ? AND role = 'STAM'");
        $user_stmt->bind_param("s", $username);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        if ($user_row = $user_result->fetch_assoc()) {
            $user_id = $user_row['id'];
        }
        $user_stmt->close();
        $conn->close();
    }
    
    // 如果有user_id，獲取權限
    if ($user_id) {
        require_once '../../Topics-frontend/frontend/config.php';
        $conn = getDatabaseConnection();
        
        // 檢查資料表是否存在
        $table_check = $conn->query("SHOW TABLES LIKE 'staff_member_permissions'");
        if ($table_check && $table_check->num_rows > 0) {
            $perm_stmt = $conn->prepare("SELECT permission_code FROM staff_member_permissions WHERE user_id = ?");
            $perm_stmt->bind_param("i", $user_id);
            $perm_stmt->execute();
            $perm_result = $perm_stmt->get_result();
            while ($row = $perm_result->fetch_assoc()) {
                $stam_permissions[] = $row['permission_code'];
            }
            $perm_stmt->close();
        }
        $conn->close();
    }
}

// 如果是AS角色，獲取其分配的權限
$as_permissions = [];
if ($is_as) {
    // 如果user_id不在session中，嘗試從username獲取
    if (!$user_id && $username) {
        require_once '../../Topics-frontend/frontend/config.php';
        $conn = getDatabaseConnection();
        $user_stmt = $conn->prepare("SELECT id FROM user WHERE username = ? AND role = 'AS'");
        $user_stmt->bind_param("s", $username);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        if ($user_row = $user_result->fetch_assoc()) {
            $user_id = $user_row['id'];
        }
        $user_stmt->close();
        $conn->close();
    }
    
    // 如果有user_id，獲取權限
    if ($user_id) {
        require_once '../../Topics-frontend/frontend/config.php';
        $conn = getDatabaseConnection();
        
        // 檢查資料表是否存在
        $table_check = $conn->query("SHOW TABLES LIKE 'assistant_permissions'");
        if ($table_check && $table_check->num_rows > 0) {
            $perm_stmt = $conn->prepare("SELECT permission_code FROM assistant_permissions WHERE user_id = ?");
            $perm_stmt->bind_param("i", $user_id);
            $perm_stmt->execute();
            $perm_result = $perm_stmt->get_result();
            while ($row = $perm_result->fetch_assoc()) {
                $as_permissions[] = $row['permission_code'];
            }
            $perm_stmt->close();
        }
        $conn->close();
    }
}

// 權限檢查函數（用於STAM和AS角色）
function hasPermission($permission_code, $user_role, $permissions_array) {
    if ($user_role === 'ADM') return true; // 管理員擁有所有權限
    if ($user_role === 'STAM' || $user_role === 'AS') {
        return in_array($permission_code, $permissions_array);
    }
    // 其他角色根據原有邏輯判斷
    return false;
}
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
            
            <!-- 首頁 - 所有角色都可以看到，但STAM和AS用戶不顯示 -->
            <?php if (!$is_stam && !$is_as): ?>
                <a href="index.php" class="menu-item <?php echo $current_page === 'index' ? 'active' : ''; ?>">
                    <i class="fas fa-home"></i>
                    <span>首頁</span>
                </a>
            <?php endif; ?>
            
            <!-- 使用者管理 - 僅管理員 -->
            <?php if ($is_admin): ?>
                <a href="users.php" class="menu-item <?php echo in_array($current_page, ['users', 'edit_user', 'add_user']) ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i>
                    <span>使用者管理</span>
                </a>
            <?php endif; ?>

            <!-- 就讀意願名單 - 所有角色都可以看到，STAM和AS需要權限 -->
            <?php if ((!$is_stam && !$is_as) || ($is_stam && hasPermission('enrollment_list', $user_role, $stam_permissions)) || ($is_as && hasPermission('enrollment_list', $user_role, $as_permissions))): ?>
                <a href="enrollment_list.php" class="menu-item <?php echo $current_page === 'enrollment_list' ? 'active' : ''; ?>">
                    <i class="fas fa-file-signature"></i>
                    <span>就讀意願名單</span>
                </a>
            <?php endif; ?>

            <!-- 續招 - 所有角色都可以看到（但權限不同），STAM和AS需要權限 -->
            <?php if ((!$is_stam && !$is_as) || ($is_stam && hasPermission('continued_admission_list', $user_role, $stam_permissions)) || ($is_as && hasPermission('continued_admission_list', $user_role, $as_permissions))): ?>
                <a href="continued_admission_list.php" class="menu-item <?php echo in_array($current_page, ['continued_admission_list', 'continued_admission_detail']) ? 'active' : ''; ?>">
                    <i class="fas fa-user-plus"></i>
                    <span>續招</span>
                </a>
            <?php endif; ?>

            <!-- 招生推薦 - 僅學校行政和管理員，STAM和AS需要權限 -->
            <?php if (($is_staff || $is_admin || $is_director) || ($is_stam && hasPermission('admission_recommend_list', $user_role, $stam_permissions)) || ($is_as && hasPermission('admission_recommend_list', $user_role, $as_permissions))): ?>
                <a href="admission_recommend_list.php" class="menu-item <?php echo $current_page === 'admission_recommend_list' ? 'active' : ''; ?>">
                    <i class="fas fa-user-friends"></i>
                    <span>招生推薦</span>
                </a>
            <?php endif; ?>

            <!-- 獎金專區 - 僅招生中心特定帳號(12/STA)與管理員 -->
            <?php
            $can_bonus_center = ($is_admin || ($username === '12' && $user_role === 'STA'));
            ?>
            <?php if ($can_bonus_center): ?>
                <a href="bonus_center.php" class="menu-item <?php echo in_array($current_page, ['bonus_center', 'bonus_send_list', 'bonus_send_export'], true) ? 'active' : ''; ?>">
                    <i class="fas fa-gift"></i>
                    <span>獎金專區</span>
                </a>
            <?php endif; ?>
            
            <!-- 統計分析 - 所有角色都可以看到，STAM和AS需要權限 -->
            <?php if ((!$is_stam && !$is_as) || ($is_stam && hasPermission('activity_records', $user_role, $stam_permissions)) || ($is_as && hasPermission('activity_records', $user_role, $as_permissions))): ?>
                <a href="activity_records.php" class="menu-item <?php echo $current_page === 'activity_records' ? 'active' : ''; ?>">
                    <i class="fas fa-tasks"></i>
                    <span>統計分析</span>
                </a>
            <?php endif; ?>
            
            <!-- 教師活動紀錄 - 學校行政和主任可以看到，STAM和AS需要權限 -->
            <?php if (($is_staff || $is_admin || $is_director) || ($is_stam && hasPermission('teacher_activity_records', $user_role, $stam_permissions)) || ($is_as && hasPermission('teacher_activity_records', $user_role, $as_permissions))): ?>
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
            
            <?php if ($is_staff ||$is_admin): ?>
                <a href="video_management.php" class="menu-item <?php echo $current_page === 'video_management' ? 'active' : ''; ?>">
                    <i class="fas fa-video"></i>
                    <span>影片管理</span>
                </a>
            <?php endif; ?>

            <?php if ($is_staff ||$is_admin): ?>
                <a href="game_management.php" class="menu-item <?php echo $current_page === 'game_management' ? 'active' : ''; ?>">
                    <i class="fas fa-gamepad"></i>
                    <span>遊戲管理</span>
                </a>
            <?php endif; ?>
            
            <!-- 入學說明會 - 僅學校行政和管理員，STAM需要權限 -->
            <?php if (($is_staff || $is_admin) || ($is_stam && hasPermission('settings', $user_role, $stam_permissions))): ?>
                <a href="settings.php" class="menu-item <?php echo $current_page === 'settings' ? 'active' : ''; ?>">
                    <i class="fas fa-cogs"></i>
                    <span>入學說明會</span>
                </a>
            <?php endif; ?>

            <?php if ($is_staff || $is_admin): ?>
                <a href="bulletin_board.php" class="menu-item <?php echo in_array($current_page, ['bulletin_board', 'edit_bulletin']) ? 'active' : ''; ?>">
                    <i class="fas fa-bullhorn"></i>
                    <span>招生公告管理</span>
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

            <!-- 新生基本資料管理 - 僅招生中心(學校行政 STA)與管理員 -->
            <?php if ($is_staff || $is_admin): ?>
                <a href="new_student_basic_info_management.php" class="menu-item <?php echo $current_page === 'new_student_basic_info_management' ? 'active' : ''; ?>">
                    <i class="fas fa-clipboard-list"></i>
                    <span>新生基本資料管理</span>
                </a>
            <?php endif; ?>
            
            <!-- 權限管理 - 僅學校行政和管理員 -->
            <?php if ($is_staff || $is_admin): ?>
                <a href="permission_management.php" class="menu-item <?php echo $current_page === 'permission_management' ? 'active' : ''; ?>">
                    <i class="fas fa-key"></i>
                    <span>權限管理</span>
                </a>
            <?php endif; ?>
            
            <!-- 科助權限管理 - 僅資管科主任 -->
            <?php 
            // 臨時調試：在URL加上?debug_role=1可在頁面上看到調試信息
            if (isset($_GET['debug_role']) && $_GET['debug_role'] == '1') {
                echo '<div style="background: #fff3cd; border: 1px solid #ffc107; padding: 10px; margin: 10px; border-radius: 4px; font-size: 12px;">';
                echo '<strong>調試信息：</strong><br>';
                echo '原始角色 (session): ' . htmlspecialchars($original_role ?? 'NULL') . '<br>';
                echo '映射後角色: ' . htmlspecialchars($user_role) . '<br>';
                echo 'is_im 值: ' . ($is_im ? 'true' : 'false') . '<br>';
                echo '用戶名: ' . htmlspecialchars($username) . '<br>';
                echo '用戶ID: ' . htmlspecialchars($user_id ?? 'NULL') . '<br>';
                echo '</div>';
            }
            
            // 確保 IM 角色菜單一定會顯示：如果 user_role 是 IM，強制設置 is_im = true
            if ($user_role === 'IM' && !$is_im) {
                $is_im = true;
            }
            
            // 如果is_im為false，但原始角色可能是IM相關的值，嘗試自動修正
            if (!$is_im && (stripos($original_role, 'IM') !== false || stripos($original_role, '資管') !== false || $original_role === 'IM')) {
                // 如果原始角色包含IM或資管，強制設置為IM
                $user_role = 'IM';
                $is_im = true;
                if (isset($_GET['debug_role']) && $_GET['debug_role'] == '1') {
                    echo '<div style="background: #d1ecf1; border: 1px solid #0c5460; padding: 10px; margin: 10px; border-radius: 4px; font-size: 12px;">';
                    echo '<strong>自動修正：</strong>檢測到IM相關角色，已自動設置為IM<br>';
                    echo '</div>';
                }
            }
            
            if ($is_im): ?>
                <a href="department_permission_management.php" class="menu-item <?php echo $current_page === 'department_permission_management' ? 'active' : ''; ?>">
                    <i class="fas fa-user-shield"></i>
                    <span>科助權限管理</span>
                </a>
                <a href="student_contact_management_im.php" class="menu-item <?php echo $current_page === 'student_contact_management_im' ? 'active' : ''; ?>">
                    <i class="fas fa-address-book"></i>
                    <span>學生聯絡管理</span>
                </a>
            <?php else: ?>
                <!-- 調試：如果角色是IM但菜單沒顯示，這裡會看到調試信息 -->
                <?php if ($user_role === 'IM' && isset($_GET['show_debug']) && $_GET['show_debug'] == '1'): ?>
                    <div style="background: #ffdddd; border: 2px solid #ff0000; padding: 10px; margin: 10px; border-radius: 4px; font-size: 12px; color: #ff0000;">
                        <strong>⚠️ 錯誤：角色是IM但is_im為false！</strong><br>
                        user_role: <?php echo htmlspecialchars($user_role); ?><br>
                        is_im: <?php echo $is_im ? 'true' : 'false'; ?><br>
                        user_id: <?php echo htmlspecialchars($user_id ?? 'NULL'); ?><br>
                        user_department_code: <?php echo htmlspecialchars($user_department_code ?? 'NULL'); ?><br>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <!-- 國中招生申請名單 - 僅學校行政和管理員，STAM需要權限 -->
            <?php if (($is_staff || $is_admin) || ($is_stam && hasPermission('mobile_junior_B', $user_role, $stam_permissions))): ?>
                <a href="mobile_junior_B.php" class="menu-item <?php echo $current_page === 'mobile_junior_B' ? 'active' : ''; ?>">
                    <i class="fas fa-school"></i>
                    <span>國中招生申請名單</span>
                </a>
            <?php endif; ?>
            
            <!-- 聯絡人與群組管理 - 僅管理員 -->
            <?php if ($is_admin): ?>
                <a href="chat_B.php" class="menu-item <?php echo $current_page === 'chat_B' ? 'active' : ''; ?>">
                    <i class="fas fa-users-cog"></i>
                    <span>聯絡人與群組管理</span>
                </a>
            <?php endif; ?>

            <!-- 頁面管理 - STAM和AS用戶不顯示 -->
            <?php if (!$is_stam && !$is_as): ?>
                <a href="page_management.php" class="menu-item <?php echo $current_page === 'page_management' ? 'active' : ''; ?>">
                    <i class="fas fa-file-alt"></i>
                    <span>頁面管理</span>
                </a>
            <?php endif; ?>
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