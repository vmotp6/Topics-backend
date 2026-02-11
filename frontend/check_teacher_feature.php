<?php
/**
 * 教師學生大學與成就榮譽功能 - 驗證檢查表
 * 用於驗證系統是否正確安裝和配置
 */

// 不需要登入，直接檢查
define('SKIP_LOGIN_CHECK', true);

require_once __DIR__ . '/session_config.php';
require_once '../../Topics-frontend/frontend/config.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>教師功能驗證檢查</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f0f2f5;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: #fff;
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 24px;
            color: #262626;
        }
        .check-item {
            display: flex;
            align-items: center;
            padding: 12px;
            margin-bottom: 12px;
            border-radius: 6px;
            border-left: 4px solid #d9d9d9;
        }
        .check-item.success {
            background: #f6ffed;
            border-left-color: #52c41a;
        }
        .check-item.error {
            background: #fff2f0;
            border-left-color: #ff4d4f;
        }
        .check-item.warning {
            background: #fffbe6;
            border-left-color: #faad14;
        }
        .check-icon {
            font-size: 20px;
            margin-right: 12px;
            min-width: 30px;
            text-align: center;
        }
        .check-content {
            flex: 1;
        }
        .check-label {
            font-weight: 600;
            margin-bottom: 4px;
        }
        .check-detail {
            font-size: 12px;
            color: #8c8c8c;
        }
        .section {
            margin-bottom: 32px;
        }
        .section-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid #1890ff;
            color: #262626;
        }
        .summary {
            margin-top: 24px;
            padding: 16px;
            background: #f5f5f5;
            border-radius: 6px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="title">
            <i class="fas fa-heartbeat"></i> 教師學生大學與成就榮譽功能驗證
        </div>

        <?php
        $checks = [
            'file_exists' => [
                'label' => '主頁面文件',
                'detail' => 'teacher_student_university_info.php',
                'result' => file_exists(__DIR__ . '/teacher_student_university_info.php')
            ],
            'sidebar_updated' => [
                'label' => '側邊欄菜單',
                'detail' => 'sidebar.php 已更新',
                'result' => strpos(file_get_contents(__DIR__ . '/sidebar.php'), 'teacher_student_university_info') !== false
            ],
            'session_config' => [
                'label' => 'Session 配置',
                'detail' => 'session_config.php 可訪問',
                'result' => file_exists(__DIR__ . '/session_config.php')
            ]
        ];

        $database_checks = [];
        
        try {
            $conn = getDatabaseConnection();
            if ($conn) {
                // 檢查表是否存在
                $table_check = $conn->query("SHOW TABLES LIKE 'new_student_basic_info'");
                $table_exists = $table_check && $table_check->num_rows > 0;
                $database_checks['table_exists'] = [
                    'label' => 'new_student_basic_info 表',
                    'detail' => '表結構存在',
                    'result' => $table_exists
                ];

                if ($table_exists) {
                    // 檢查必要的欄位
                    $col_check = $conn->query("SHOW COLUMNS FROM new_student_basic_info LIKE 'class_name'");
                    $database_checks['class_name_col'] = [
                        'label' => 'class_name 欄位',
                        'detail' => '用於班級分類',
                        'result' => $col_check && $col_check->num_rows > 0
                    ];

                    $col_check = $conn->query("SHOW COLUMNS FROM new_student_basic_info LIKE 'university'");
                    $database_checks['university_col'] = [
                        'label' => 'university 欄位',
                        'detail' => '用於存儲大學名稱',
                        'result' => $col_check && $col_check->num_rows > 0,
                        'warning' => $col_check && $col_check->num_rows === 0 ? '系統會在首次訪問時自動建立' : null
                    ];

                    $col_check = $conn->query("SHOW COLUMNS FROM new_student_basic_info LIKE 'achievements'");
                    $database_checks['achievements_col'] = [
                        'label' => 'achievements 欄位',
                        'detail' => '用於存儲成就與榮譽',
                        'result' => $col_check && $col_check->num_rows > 0,
                        'warning' => $col_check && $col_check->num_rows === 0 ? '系統會在首次訪問時自動建立' : null
                    ];

                    // 檢查班級數據
                    $class_check = $conn->query("SELECT COUNT(*) as cnt FROM new_student_basic_info WHERE class_name LIKE '%孝%' OR class_name LIKE '%忠%'");
                    if ($class_check) {
                        $class_row = $class_check->fetch_assoc();
                        $database_checks['class_data'] = [
                            'label' => '班級數據',
                            'detail' => '找到 ' . $class_row['cnt'] . ' 筆包含孝班或忠班的記錄',
                            'result' => $class_row['cnt'] > 0
                        ];
                    }
                }
                $conn->close();
            } else {
                $database_checks['connection'] = [
                    'label' => '資料庫連接',
                    'detail' => '連接失敗',
                    'result' => false
                ];
            }
        } catch (Exception $e) {
            $database_checks['connection'] = [
                'label' => '資料庫連接',
                'detail' => $e->getMessage(),
                'result' => false
            ];
        }

        // 統計結果
        $total_checks = count($checks) + count($database_checks);
        $passed = 0;
        $warning = 0;

        echo '<div class="section">';
        echo '<div class="section-title"><i class="fas fa-file-code"></i> 文件檢查</div>';
        foreach ($checks as $key => $check) {
            $passed += $check['result'] ? 1 : 0;
            $class = $check['result'] ? 'success' : 'error';
            $icon = $check['result'] ? 'fas fa-check-circle' : 'fas fa-times-circle';
            echo '<div class="check-item ' . $class . '">';
            echo '<div class="check-icon"><i class="' . $icon . '"></i></div>';
            echo '<div class="check-content">';
            echo '<div class="check-label">' . $check['label'] . '</div>';
            echo '<div class="check-detail">' . $check['detail'] . '</div>';
            echo '</div></div>';
        }
        echo '</div>';

        echo '<div class="section">';
        echo '<div class="section-title"><i class="fas fa-database"></i> 資料庫檢查</div>';
        foreach ($database_checks as $key => $check) {
            if ($check['result']) {
                $passed++;
                $class = 'success';
                $icon = 'fas fa-check-circle';
            } else {
                $class = isset($check['warning']) && $check['warning'] ? 'warning' : 'error';
                $icon = isset($check['warning']) && $check['warning'] ? 'fas fa-exclamation-triangle' : 'fas fa-times-circle';
            }
            if (isset($check['warning']) && $check['warning']) {
                $warning++;
            }
            echo '<div class="check-item ' . $class . '">';
            echo '<div class="check-icon"><i class="' . $icon . '"></i></div>';
            echo '<div class="check-content">';
            echo '<div class="check-label">' . $check['label'] . '</div>';
            echo '<div class="check-detail">' . $check['detail'];
            if (isset($check['warning']) && $check['warning']) {
                echo ' - <strong>' . $check['warning'] . '</strong>';
            }
            echo '</div></div></div>';
        }
        echo '</div>';

        // 總結
        $passed_rate = round(($passed / $total_checks) * 100);
        $status_class = $passed_rate === 100 ? 'success' : ($warning > 0 ? 'warning' : 'error');
        $status_icon = $passed_rate === 100 ? 'fas fa-smile' : ($warning > 0 ? 'fas fa-exclamation' : 'fas fa-frown');
        
        echo '<div class="summary">';
        echo '<div style="display: flex; align-items: center; margin-bottom: 12px;">';
        echo '<i class="' . $status_icon . '" style="font-size: 32px; margin-right: 16px; color: ' . ($passed_rate === 100 ? '#52c41a' : '#faad14') . '"></i>';
        echo '<div>';
        echo '<div style="font-weight: 700; font-size: 18px;">檢查完成</div>';
        echo '<div style="color: #8c8c8c;">通過 ' . $passed . '/' . $total_checks . ' 項檢查 (' . $passed_rate . '%)</div>';
        echo '</div></div>';

        if ($passed_rate === 100) {
            echo '<div style="color: #52c41a; font-weight: 600;">✓ 所有系統檢查都已通過！功能已準備就緒。</div>';
        } elseif ($warning > 0) {
            echo '<div style="color: #faad14; font-weight: 600;">⚠ 某些欄位將在首次使用時自動建立，無需額外操作。</div>';
        } else {
            echo '<div style="color: #ff4d4f; font-weight: 600;">✗ 系統配置未完成，請聯繫管理員。</div>';
        }
        echo '</div>';
        ?>
    </div>
</body>
</html>
