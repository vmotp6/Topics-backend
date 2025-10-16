<?php
session_start();

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 資料庫連接設定
$host = '100.79.58.120';
$dbname = 'topics_good';
$db_username = 'root';
$db_password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<h2>✅ 資料庫連接成功</h2>";
} catch (PDOException $e) {
    die("❌ 資料庫連接失敗: " . $e->getMessage());
}

// 檢查所有相關的資料表
$tables = ['enrollment_applications', 'continued_admission', 'admission_applications', 'admission_recommendations'];

echo "<h1>招生中心資料庫除錯頁面</h1>";

foreach ($tables as $table) {
    echo "<h3>📋 檢查資料表: {$table}</h3>";
    
    try {
        // 檢查表是否存在
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        $table_exists = $stmt->fetch();
        
        if ($table_exists) {
            echo "✅ 資料表 {$table} 存在<br>";
            
            // 獲取表結構
            $stmt = $pdo->prepare("DESCRIBE {$table}");
            $stmt->execute();
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "<strong>欄位結構:</strong><br>";
            echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
            echo "<tr><th>欄位名稱</th><th>資料類型</th><th>允許空值</th><th>鍵值</th></tr>";
            foreach ($columns as $column) {
                echo "<tr>";
                echo "<td>{$column['Field']}</td>";
                echo "<td>{$column['Type']}</td>";
                echo "<td>{$column['Null']}</td>";
                echo "<td>{$column['Key']}</td>";
                echo "</tr>";
            }
            echo "</table>";
            
            // 獲取資料筆數
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM {$table}");
            $stmt->execute();
            $count = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "<strong>資料筆數:</strong> {$count['count']}<br>";
            
            // 顯示最近的5筆資料
            if ($count['count'] > 0) {
                $stmt = $pdo->prepare("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 5");
                $stmt->execute();
                $recent_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo "<strong>最近5筆資料:</strong><br>";
                echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
                if (!empty($recent_data)) {
                    // 顯示表頭
                    echo "<tr>";
                    foreach (array_keys($recent_data[0]) as $key) {
                        echo "<th>{$key}</th>";
                    }
                    echo "</tr>";
                    
                    // 顯示資料
                    foreach ($recent_data as $row) {
                        echo "<tr>";
                        foreach ($row as $value) {
                            $display_value = is_null($value) ? 'NULL' : htmlspecialchars(substr($value, 0, 50));
                            echo "<td>{$display_value}</td>";
                        }
                        echo "</tr>";
                    }
                }
                echo "</table>";
            }
            
        } else {
            echo "❌ 資料表 {$table} 不存在<br>";
        }
        
    } catch (PDOException $e) {
        echo "❌ 查詢資料表 {$table} 時發生錯誤: " . $e->getMessage() . "<br>";
    }
    
    echo "<hr>";
}

// 測試招生中心的查詢
echo "<h3>🔍 測試招生中心查詢</h3>";

// 測試就讀意願登錄查詢
try {
    $stmt = $pdo->prepare("SELECT 
        id, name, identity, gender, phone1, phone2, email, 
        intention1, system1, intention2, system2, intention3, system3,
        junior_high, current_grade, line_id, facebook, recommended_teacher, remarks,
        created_at, '就讀意願登錄' as source_type
        FROM enrollment_applications 
        ORDER BY created_at DESC 
        LIMIT 3");
    $stmt->execute();
    $enrollment_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<strong>就讀意願登錄資料 (最近3筆):</strong><br>";
    if (!empty($enrollment_data)) {
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>ID</th><th>姓名</th><th>身分別</th><th>聯絡電話</th><th>學校</th><th>志願</th><th>報名時間</th></tr>";
        foreach ($enrollment_data as $row) {
            $intentions = [];
            if (!empty($row['intention1'])) $intentions[] = $row['intention1'];
            if (!empty($row['intention2'])) $intentions[] = $row['intention2'];
            if (!empty($row['intention3'])) $intentions[] = $row['intention3'];
            $intentions_display = implode(', ', $intentions);
            
            echo "<tr>";
            echo "<td>{$row['id']}</td>";
            echo "<td>{$row['name']}</td>";
            echo "<td>{$row['identity']}</td>";
            echo "<td>{$row['phone1']}</td>";
            echo "<td>{$row['junior_high']}</td>";
            echo "<td>{$intentions_display}</td>";
            echo "<td>{$row['created_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "❌ 沒有找到就讀意願登錄資料<br>";
    }
    
} catch (PDOException $e) {
    echo "❌ 查詢就讀意願登錄資料時發生錯誤: " . $e->getMessage() . "<br>";
}

echo "<br>";

// 測試續招報名查詢
try {
    $stmt = $pdo->prepare("SELECT 
        id, name, id_number, birth_year, birth_month, birth_day, gender,
        phone, mobile, school_city, school_name, guardian_name as guardian, guardian_phone, guardian_mobile,
        self_intro, skills, choices,
        created_at, '續招報名' as source_type
        FROM continued_admission 
        ORDER BY created_at DESC 
        LIMIT 3");
    $stmt->execute();
    $continued_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<strong>續招報名資料 (最近3筆):</strong><br>";
    if (!empty($continued_data)) {
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>ID</th><th>姓名</th><th>身分證字號</th><th>聯絡電話</th><th>學校</th><th>志願</th><th>報名時間</th></tr>";
        foreach ($continued_data as $row) {
            $choices = json_decode($row['choices'], true);
            $choices_display = is_array($choices) ? implode(', ', $choices) : $row['choices'];
            
            echo "<tr>";
            echo "<td>{$row['id']}</td>";
            echo "<td>{$row['name']}</td>";
            echo "<td>{$row['id_number']}</td>";
            echo "<td>{$row['mobile']}</td>";
            echo "<td>{$row['school_name']}</td>";
            echo "<td>{$choices_display}</td>";
            echo "<td>{$row['created_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "❌ 沒有找到續招報名資料<br>";
    }
    
} catch (PDOException $e) {
    echo "❌ 查詢續招報名資料時發生錯誤: " . $e->getMessage() . "<br>";
}

echo "<br><a href='admission_center.php'>返回招生中心</a>";
?>
