<?php
session_start();

// 檢查是否已登入，如果沒有登入則跳轉到登入頁面
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 處理登出
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header("Location: login.php");
    exit;
}

// 設置頁面標題
$page_title = '系統概覽';
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Topics 後台管理系統</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f0f2f5;
            color: #262626;
            overflow-x: hidden;
        }
        
        /* 主介面樣式 */
        .dashboard {
            display: flex;
            min-height: 100vh;
        }
        
        /* 內容區域 */
        .content {
            padding: 24px;
        }
        
        /* 統計卡片 */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: #fff;
            padding: 24px;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            display: flex;
            align-items: center;
            gap: 16px;
            border: 1px solid #f0f0f0;
            transition: all 0.3s;
        }
        
        .stat-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            transform: translateY(-2px);
        }
        
        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }
        
        .stat-icon.users { 
            background: linear-gradient(135deg, #1890ff, #40a9ff); 
        }
        .stat-icon.students { 
            background: linear-gradient(135deg, #52c41a, #73d13d); 
        }
        .stat-icon.teachers { 
            background: linear-gradient(135deg, #fa8c16, #ffa940); 
        }
        .stat-icon.disabled { 
            background: linear-gradient(135deg, #ff4d4f, #ff7875); 
        }
        .stat-icon.enabled { 
            background: linear-gradient(135deg, #52c41a, #73d13d); 
        }
        .stat-icon.pending { 
            background: linear-gradient(135deg, #1890ff, #40a9ff); 
        }
        
        .stat-info h3 {
    font-size: 32px;
    margin-bottom: 4px;
    color: #262626;
    font-weight: 600;
}
        
        .stat-info p {
    color: #8c8c8c;
    font-weight: 500;
    font-size: 16px;
}
        
        .message {
    padding: 12px 16px;
    border-radius: 6px;
    margin-bottom: 16px;
    font-weight: 500;
}

.message.success {
    background: #f6ffed;
    border: 1px solid #b7eb8f;
    color: #52c41a;
}

.message.error {
    background: #fff2f0;
    border: 1px solid #ffccc7;
    color: #ff4d4f;
}

.loading {
    text-align: center;
    padding: 40px;
    color: #8c8c8c;
    font-size: 16px;
}

/* 歡迎卡片 */
.welcome-section {
    background: #fff;
    border-radius: 8px;
    padding: 32px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.03);
    border: 1px solid #f0f0f0;
    margin-top: 24px;
}

.welcome-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
}

.welcome-text h2 {
    font-size: 28px;
    color: #262626;
    margin-bottom: 8px;
    font-weight: 600;
}

.welcome-text p {
    color: #8c8c8c;
    font-size: 16px;
    line-height: 1.6;
    margin-bottom: 20px;
}

.welcome-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.welcome-actions .btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    font-size: 16px;
    text-decoration: none;
    border-radius: 6px;
    transition: all 0.3s;
}
        
        .btn {
    padding: 8px 16px;
    border: 1px solid #d9d9d9;
    border-radius: 6px;
    cursor: pointer;
    font-size: 16px;
    transition: all 0.3s;
    background: #fff;
    text-decoration: none;
    display: inline-block;
}
        
        .btn-primary {
            background: #1890ff;
            color: white;
            border-color: #1890ff;
        }
        
        .btn-primary:hover {
            background: #40a9ff;
            border-color: #40a9ff;
            color: white;
        }
        
        .btn-secondary {
            background: #fff;
            color: #595959;
            border-color: #d9d9d9;
        }
        
        .btn-secondary:hover {
            background: #f5f5f5;
            border-color: #40a9ff;
            color: #40a9ff;
        }
        

        /* 響應式設計 */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .welcome-card {
                flex-direction: column;
                text-align: center;
            }

            .nav-tabs .nav-link {
                padding: 12px 16px;
                font-size: 14px;
            }

            .tab-content {
                padding: 16px;
            }
        }
    </style>
</head>
<body>
    <!-- 主介面 -->
    <div class="dashboard">
        <!-- 引入側邊欄 -->
        <?php include 'sidebar.php'; ?>
        
        <!-- 主內容區 -->
        <div class="main-content" id="mainContent">
            <!-- 引入標題欄 -->
            <?php include 'header.php'; ?>
            
            <!-- 內容區域 -->
            <div class="content">
                <div id="messageContainer"></div>
                
                <!-- 統計卡片 -->
                <div class="stats-grid" id="statsGrid">
                    <div class="loading">載入中...</div>
                </div>
                
                <!-- 狀態統計卡片 -->
                <div class="stats-grid" id="statusStatsGrid" style="margin-top: 24px;">
                    <div class="loading">載入中...</div>
                </div>
                
                <!-- 歡迎訊息 -->
                <div class="welcome-section">
                    <div class="welcome-card">
                        <div class="welcome-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="welcome-content">
                            <h2>歡迎使用 Topics 後台管理系統</h2>
                            <p>這是一個完整的用戶管理系統，您可以：</p>
                            <ul>
                                <li>查看系統統計資訊</li>
                                <li>管理用戶資料</li>
                                <li>編輯用戶資訊</li>
                                <li>重置用戶密碼</li>
                                <li>管理 AI 模型</li>
                            </ul>
                            <div class="welcome-actions">
                                <a href="users.php" class="btn btn-primary">
                                    <i class="fas fa-users"></i>
                                    前往使用者管理
                                </a>
                                <a href="ollama_admin.php" class="btn btn-secondary">
                                    <i class="fas fa-robot"></i>
                                    AI 模型管理
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script>
    const API_BASE_URL = 'http://100.79.58.120:5001';
    
    // 載入統計資料
    async function loadStats() {
        try {
            const response = await fetch(`${API_BASE_URL}/admin/stats`);
            const data = await response.json();
            
            if (response.ok) {
                // 基本統計卡片
                document.getElementById('statsGrid').innerHTML = `
                    <div class="stat-card">
                        <div class="stat-icon users">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-info">
                            <h3>${data.total_users}</h3>
                            <p>總使用者數</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon students">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        <div class="stat-info">
                            <h3>${data.total_students}</h3>
                            <p>學生數</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon teachers">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                        <div class="stat-info">
                            <h3>${data.total_teachers}</h3>
                            <p>老師數</p>
                        </div>
                    </div>
                `;
                
                // 狀態統計卡片
                document.getElementById('statusStatsGrid').innerHTML = `
                    <div class="stat-card">
                        <div class="stat-icon enabled">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-info">
                            <h3>${data.enabled_users}</h3>
                            <p>啟用用戶</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon disabled">
                            <i class="fas fa-ban"></i>
                        </div>
                        <div class="stat-info">
                            <h3>${data.disabled_users}</h3>
                            <p>停用用戶</p>
                        </div>
                    </div>
                `;
            } else {
                showMessage('載入統計資料失敗', 'error');
            }
        } catch (error) {
            console.error('Error loading stats:', error);
            showMessage('載入統計資料失敗', 'error');
        }
    }
    
    // 顯示訊息
    function showMessage(message, type) {
        const messageContainer = document.getElementById('messageContainer');
        messageContainer.innerHTML = `<div class="message ${type}">${message}</div>`;
        
        setTimeout(() => {
            messageContainer.innerHTML = '';
        }, 3000);
    }
    
    
    // 頁面載入時執行
    document.addEventListener('DOMContentLoaded', function() {
        loadStats();
    });
    </script>
</body>
</html>
