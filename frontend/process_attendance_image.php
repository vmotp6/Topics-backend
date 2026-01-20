<?php
/**
 * 处理签到表图片 OCR 识别
 * 识别图片中的姓名和电话号码，自动匹配数据库并更新出席记录
 */

// 关闭错误显示，确保只输出 JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

// 设置错误处理，捕获所有错误
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// 设置异常处理
set_exception_handler(function($exception) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => '系統錯誤：' . $exception->getMessage()
    ]);
    exit;
});

require_once __DIR__ . '/session_config.php';

// 检查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '未授權訪問']);
    exit;
}

require_once '../../Topics-frontend/frontend/config.php';

// OCR 配置（可以在 config.php 中设置，或直接在这里修改）
// 注意：云服务OCR（Google Cloud Vision 或 百度OCR）識別準確率遠高於 Tesseract，特別是對手寫文字

// Google Cloud Vision API 配置（推薦，最準確，特別適合手寫文字）
// 获取 API Key：https://cloud.google.com/vision/docs/setup
// 免费额度：每月前 1000 次请求免费
define('GOOGLE_CLOUD_VISION_ENABLED', true); // 設置為 true 啟用
define('GOOGLE_CLOUD_VISION_API_KEY', ''); // 您的 Google Cloud Vision API 密鑰
// 示例：define('GOOGLE_CLOUD_VISION_API_KEY', 'AIzaSyXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX');


header('Content-Type: application/json');

// 获取参数
$session_id = isset($_POST['session_id']) ? intval($_POST['session_id']) : 0;
if ($session_id === 0) {
    echo json_encode(['success' => false, 'message' => '無效的場次ID']);
    exit;
}

// 检查文件上传
if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => '請選擇有效的圖片文件']);
    exit;
}

$file = $_FILES['image'];
$allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
$file_type = mime_content_type($file['tmp_name']);

if (!in_array($file_type, $allowed_types)) {
    echo json_encode(['success' => false, 'message' => '不支持的圖片格式，請上傳 JPG、PNG、GIF 或 WEBP 格式']);
    exit;
}

// 檢查文件大小（限制為 10MB）
if ($file['size'] > 10 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => '圖片大小不能超過 10MB']);
    exit;
}

try {
    $conn = getDatabaseConnection();
    
    // 保存上传的图片
    $upload_dir = __DIR__ . '/../uploads/attendance/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $new_filename = 'attendance_' . $session_id . '_' . time() . '_' . uniqid() . '.' . $file_extension;
    $upload_path = $upload_dir . $new_filename;
    
    if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
        throw new Exception('圖片上傳失敗');
    }
    
    // OCR 識別文本（帶詳細錯誤信息）
    $ocr_result = performOCR($upload_path);
    
    if (!$ocr_result['success']) {
        $error_msg = 'OCR 識別失敗：' . $ocr_result['error'];
        if (!empty($ocr_result['debug_info'])) {
            $error_msg .= "\n\n調試信息：\n" . $ocr_result['debug_info'];
        }
        throw new Exception($error_msg);
    }
    
    $ocr_text = $ocr_result['text'];
    
    if (empty($ocr_text)) {
        throw new Exception('無法識別圖片中的文字。可能原因：\n1. 圖片模糊或對比度不足\n2. 文字太小或不清楚\n3. 圖片格式問題\n\n建議：\n- 確保圖片清晰\n- 文字足夠大且對比度高\n- 嘗試調整圖片亮度/對比度');
    }
    
    // 在調試信息中添加原始OCR文本
    $ocr_result['debug_info'] .= "\n=== OCR 原始識別結果 ===\n";
    $ocr_result['debug_info'] .= $ocr_text . "\n";
    $ocr_result['debug_info'] .= "=== 原始識別結果結束 ===\n\n";
    
    // 解析文本，提取姓名和電話號碼
    $parsed_data = parseAttendanceText($ocr_text, $ocr_result['debug_info']);
    
    if (empty($parsed_data)) {
        throw new Exception('無法從圖片中提取有效的姓名和電話號碼資訊');
    }
    
    // 獲取該場次的所有報名者資訊
    $stmt = $conn->prepare("
        SELECT id, student_name, contact_phone, email 
        FROM admission_applications 
        WHERE session_id = ?
    ");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $applications = [];
    while ($row = $result->fetch_assoc()) {
        $applications[] = $row;
    }
    $stmt->close();
    
    // 匹配并更新出席記錄
    $conn->begin_transaction();
    $matched_count = 0;
    $unmatched_count = 0;
    $matched_details = [];
    $unmatched_details = [];
    $current_time = date('Y-m-d H:i:s');
    
    foreach ($parsed_data as $item) {
        $found = false;
        $matched_application = null;
        
        // 嘗試匹配：優先使用電話號碼，其次使用姓名
        foreach ($applications as $app) {
            // 標準化電話號碼（移除空格、橫線等）
            $app_phone = normalizePhone($app['contact_phone']);
            $item_phone = normalizePhone($item['phone']);
            
            // 先匹配電話號碼（最準確）
            if (!empty($item_phone) && !empty($app_phone) && $item_phone === $app_phone) {
                $found = true;
                $matched_application = $app;
                break;
            }
            
            // 如果電話號碼不匹配，嘗試匹配姓名
            if (!$found && !empty($item['name']) && !empty($app['student_name'])) {
                // 移除空格後比較
                $app_name = trim($app['student_name']);
                $item_name = trim($item['name']);
                
                if ($app_name === $item_name || 
                    mb_strpos($app_name, $item_name) !== false || 
                    mb_strpos($item_name, $app_name) !== false) {
                    // 如果姓名匹配，但電話號碼也提供，需要驗證電話號碼
                    if (empty($item_phone) || empty($app_phone) || $item_phone === $app_phone) {
                        $found = true;
                        $matched_application = $app;
                        break;
                    }
                }
            }
        }
        
        if ($found && $matched_application) {
            $application_id = $matched_application['id'];
            
            // 檢查是否已存在記錄
            $check_stmt = $conn->prepare("SELECT id FROM attendance_records WHERE session_id = ? AND application_id = ?");
            $check_stmt->bind_param("ii", $session_id, $application_id);
            $check_stmt->execute();
            $exists = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();
            
            if ($exists) {
                // 更新現有記錄
                $update_stmt = $conn->prepare("UPDATE attendance_records SET attendance_status = 1, check_in_time = ?, absent_time = NULL WHERE session_id = ? AND application_id = ?");
                $update_stmt->bind_param("sii", $current_time, $session_id, $application_id);
                $update_stmt->execute();
                $update_stmt->close();
            } else {
                // 新增記錄
                $insert_stmt = $conn->prepare("INSERT INTO attendance_records (session_id, application_id, attendance_status, check_in_time, absent_time) VALUES (?, ?, 1, ?, NULL)");
                $insert_stmt->bind_param("iis", $session_id, $application_id, $current_time);
                $insert_stmt->execute();
                $insert_stmt->close();
            }
            
            $matched_count++;
            $matched_details[] = [
                'name' => $matched_application['student_name'],
                'phone' => $matched_application['contact_phone'],
                'matched_by' => !empty($item['phone']) ? 'phone' : 'name'
            ];
        } else {
            $unmatched_count++;
            $unmatched_details[] = [
                'name' => $item['name'] ?? '',
                'phone' => $item['phone'] ?? ''
            ];
        }
    }
    
    $conn->commit();
    $conn->close();
    
    // 刪除臨時圖片文件（可選，如果需要保留可以註釋掉）
    // @unlink($upload_path);
    
    // 返回結果（包含解析後的資料用於調試）
    $response = [
        'success' => true,
        'message' => "識別完成！成功匹配 {$matched_count} 筆，未匹配 {$unmatched_count} 筆",
        'matched_count' => $matched_count,
        'unmatched_count' => $unmatched_count,
        'matched_details' => $matched_details,
        'unmatched_details' => $unmatched_details,
        'ocr_debug_info' => $ocr_result['debug_info'] ?? '',
        'parsed_data' => $parsed_data, // 添加解析後的資料用於調試
        'ocr_raw_text' => $ocr_text // 添加原始OCR文本用於調試
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    // 回滾事務（如果存在）
    if (isset($conn)) {
        try {
            // 使用兼容方式檢查事務狀態
            if (method_exists($conn, 'in_transaction') && $conn->in_transaction()) {
                $conn->rollback();
            } else {
                // 如果沒有 in_transaction 方法，直接嘗試回滾（如果出錯會被忽略）
                @$conn->rollback();
            }
        } catch (Exception $rollback_error) {
            // 忽略回滾錯誤
        }
        $conn->close();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
} catch (Error $e) {
    // 回滾事務（如果存在）
    if (isset($conn)) {
        try {
            // 使用兼容方式檢查事務狀態
            if (method_exists($conn, 'in_transaction') && $conn->in_transaction()) {
                $conn->rollback();
            } else {
                // 如果沒有 in_transaction 方法，直接嘗試回滾（如果出錯會被忽略）
                @$conn->rollback();
            }
        } catch (Exception $rollback_error) {
            // 忽略回滾錯誤
        }
        $conn->close();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '系統錯誤：' . $e->getMessage()]);
    exit;
}

/**
 * 執行 OCR 識別（改進版，帶詳細錯誤資訊）
 * 返回數組：['success' => bool, 'text' => string, 'error' => string, 'debug_info' => string]
 */
function performOCR($image_path) {
    $result = [
        'success' => false,
        'text' => '',
        'error' => '',
        'debug_info' => ''
    ];
    
    // 檢查圖片文件是否存在
    if (!file_exists($image_path)) {
        $result['error'] = '圖片文件不存在';
        return $result;
    }
    
    // 檢查文件大小
    $file_size = filesize($image_path);
    if ($file_size === 0) {
        $result['error'] = '圖片文件為空';
        return $result;
    }
    
    $result['debug_info'] .= "圖片路徑：$image_path\n";
    $result['debug_info'] .= "文件大小：" . round($file_size / 1024, 2) . " KB\n";
    
    // 嘗試多種圖片預處理方案（提高識別率）
    $preprocess_methods = [
        'enhanced' => '增強預處理（對比度+銳化+二值化）',
        'binary' => '二值化預處理',
        'contrast' => '高對比度預處理',
        'original' => '原始圖片（無預處理）'
    ];
    
    $processed_images = [];
    foreach ($preprocess_methods as $method => $desc) {
        if ($method === 'original') {
            $processed_images[$method] = $image_path;
        } else {
            $processed = preprocessImage($image_path, $method);
            if ($processed) {
                $processed_images[$method] = $processed;
            }
        }
    }
    
    $result['debug_info'] .= "準備了 " . count($processed_images) . " 種預處理方案\n";
    // 方法1: 使用 Tesseract OCR（如果已安装）
    // 檢查 Tesseract 是否可用
    $tesseract_cmd = null;
    $possible_paths = [
        'tesseract',  // 在 PATH 內
        'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
        'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
    ];
    
    foreach ($possible_paths as $path) {
        if ($path === 'tesseract') {
            // 檢查是否在 PATH 內
            $test = @shell_exec("where tesseract 2>nul");
            if (!empty($test)) {
                $tesseract_cmd = 'tesseract';
                break;
            }
        } else {
            if (file_exists($path)) {
                $tesseract_cmd = $path;
                break;
            }
        }
    }
    
    if ($tesseract_cmd) {
        $result['debug_info'] .= "找到 Tesseract：$tesseract_cmd\n";
        
        // 檢查可用的語言包
        $langs_output = @shell_exec('"' . $tesseract_cmd . '" --list-langs 2>&1');
        $result['debug_info'] .= "語言包檢查輸出：$langs_output\n";
        
        $has_chi_tra = strpos($langs_output, 'chi_tra') !== false;
        $has_eng = strpos($langs_output, 'eng') !== false;
        
        // 根據可用語言包選擇語言參數
        if ($has_chi_tra && $has_eng) {
            $lang_param = 'chi_tra+eng';  // 最佳：繁體中文 + 英文
            $result['debug_info'] .= "使用語言：chi_tra+eng\n";
        } elseif ($has_chi_tra) {
            $lang_param = 'chi_tra';  // 只有繁體中文
            $result['debug_info'] .= "使用語言：chi_tra\n";
        } elseif ($has_eng) {
            $lang_param = 'eng';  // 只有英文（準確率較低）
            $result['debug_info'] .= "使用語言：eng（注意：沒有中文語言包，中文識別準確率較低）\n";
        } else {
            $lang_param = '';  // 使用預設語言
            $result['debug_info'] .= "使用預設語言\n";
        }
        
        // 嘗試多種預處理方案 + OCR 參數組合
        $psm_modes = [
            '' => '標準模式',
            '--psm 6' => '單一文本塊',
            '--psm 7' => '單行文本（適合簽到表）',
            '--psm 8' => '單詞模式',
            '--psm 11' => '稀疏文本（適合手寫）',
            '--psm 13' => '原始行模式'
        ];
        
        $ocr_attempts = [];
        // 對每種預處理方案和PSM模式進行組合嘗試
        foreach ($processed_images as $preprocess_method => $processed_path) {
            foreach ($psm_modes as $psm => $psm_name) {
                $ocr_attempts[] = [
                    'preprocess' => $preprocess_method,
                    'preprocess_desc' => $preprocess_methods[$preprocess_method] ?? $preprocess_method,
                    'psm' => $psm,
                    'psm_name' => $psm_name,
                    'image_path' => $processed_path
                ];
            }
        }
        
        $result['debug_info'] .= "\n開始嘗試 " . count($ocr_attempts) . " 種組合（" . count($processed_images) . " 種預處理 × " . count($psm_modes) . " 種PSM模式）...\n\n";
        
        $best_result = null;
        $best_score = 0;
        
        foreach ($ocr_attempts as $idx => $attempt) {
            $unique_id = uniqid();
            $temp_text_file_attempt = sys_get_temp_dir() . '/ocr_' . $unique_id . '.txt';
            
            $command = sprintf(
                '"%s" "%s" "%s" %s %s 2>&1',
                $tesseract_cmd,
                escapeshellarg($attempt['image_path']),
                escapeshellarg($temp_text_file_attempt),
                $lang_param ? '-l ' . escapeshellarg($lang_param) : '',
                $attempt['psm']
            );
            
            $result['debug_info'] .= "嘗試 " . ($idx + 1) . "/" . count($ocr_attempts) . "：{$attempt['preprocess_desc']} + {$attempt['psm_name']}\n";
            
            $command_output = @shell_exec($command);
            
            $output_file = $temp_text_file_attempt . '.txt';
            if (file_exists($output_file)) {
                $text = trim(file_get_contents($output_file));
                @unlink($output_file);
                
                $text_length = mb_strlen($text);
                $result['debug_info'] .= "  結果：文字長度 $text_length 字符";
                
                if (!empty($text)) {
                    // 檢查文本質量（包含數字和字母/中文）
                    $has_digits = preg_match('/\d/', $text);
                    $has_letters = preg_match('/[a-zA-Z\x{4e00}-\x{9fa5}]/u', $text);
                    $digit_count = preg_match_all('/\d/', $text);
                    $letter_count = preg_match_all('/[a-zA-Z\x{4e00}-\x{9fa5}]/u', $text);
                    
                    // 計算質量分數
                    $score = 0;
                    if ($has_digits) $score += 10;
                    if ($has_letters) $score += 10;
                    $score += min($digit_count, 20); // 數字越多越好，最多20分
                    $score += min($letter_count, 20); // 字母/中文越多越好，最多20分
                    $score += min($text_length, 30); // 文本長度，最多30分
                    
                    // 檢查是否包含電話號碼模式
                    if (preg_match('/09\d{8}/', $text)) {
                        $score += 30; // 包含電話號碼格式，大幅加分
                    } elseif (preg_match('/\d{8,10}/', $text)) {
                        $score += 15; // 包含8-10位数字
                    }
                    
                    $result['debug_info'] .= " (質量分數：" . $score . "，數字=" . ($has_digits ? '是' : '否') . ", 字母=" . ($has_letters ? '是' : '否') . ")\n";
                    
                    // 如果质量分数足够高，直接使用
                    if ($score >= 50 && $has_digits && $has_letters && $text_length >= 5) {
                        $result['success'] = true;
                        $result['text'] = $text;
                        $result['debug_info'] .= "  ✓ 識別成功！質量分數：$score\n";
                        $result['debug_info'] .= "  使用方案：{$attempt['preprocess_desc']} + {$attempt['psm_name']}\n";
                        
                        // 清理其他预处理图片
                        foreach ($processed_images as $method => $path) {
                            if ($method !== 'original' && $path !== $attempt['image_path'] && file_exists($path)) {
                                @unlink($path);
                            }
                        }
                        
                        return $result;
                    }
                    
                    // 保存最佳结果
                    if ($score > $best_score) {
                        $best_score = $score;
                        $best_result = [
                            'text' => $text,
                            'preprocess' => $attempt['preprocess_desc'],
                            'psm' => $attempt['psm_name'],
                            'score' => $score
                        ];
                    }
                } else {
                    $result['debug_info'] .= " (空結果)\n";
                }
            } else {
                $result['debug_info'] .= " ✗ 輸出文件未生成\n";
            }
        }
        
        // 如果所有嘗試都沒有高質量結果，使用最佳結果
        if ($best_result && $best_result['score'] > 0) {
            $result['success'] = true;
            $result['text'] = $best_result['text'];
            $result['debug_info'] .= "\n使用最佳結果（質量分數：{$best_result['score']}）\n";
            $result['debug_info'] .= "方案：{$best_result['preprocess']} + {$best_result['psm']}\n";
            
            // 清理预处理图片
            foreach ($processed_images as $method => $path) {
                if ($method !== 'original' && file_exists($path)) {
                    @unlink($path);
                }
            }
            
            return $result;
        }
        
        // 所有尝试都失败
        $result['error'] = '所有 OCR 模式都無法識別文字';
        $result['debug_info'] .= "\n所有嘗試都失敗\n";
        $result['debug_info'] .= "可能原因：\n";
        $result['debug_info'] .= "1. 圖片質量太差（模糊、對比度低）\n";
        $result['debug_info'] .= "2. 文字太小或不清楚\n";
        $result['debug_info'] .= "3. 手寫文字識別困難（建議使用打印文字）\n";
        $result['debug_info'] .= "4. 缺少中文語言包（只有 eng 時中文識別率低）\n";
    } else {
        $result['error'] = '未找到 Tesseract OCR';
        $result['debug_info'] .= "錯誤：在所有可能路徑中都未找到 Tesseract\n";
    }
    
    // 方法2: 优先使用 Google Cloud Vision API（最准确，特别是手写文字）
    if (defined('GOOGLE_CLOUD_VISION_ENABLED') && GOOGLE_CLOUD_VISION_ENABLED && 
        defined('GOOGLE_CLOUD_VISION_API_KEY') && !empty(GOOGLE_CLOUD_VISION_API_KEY)) {
        $result['debug_info'] .= "\n嘗試使用 Google Cloud Vision API...\n";
        $vision_result = performGoogleCloudVisionOCR($image_path);
        if ($vision_result['success']) {
            $result['success'] = true;
            $result['text'] = $vision_result['text'];
            $result['debug_info'] .= $vision_result['debug_info'];
            $result['debug_info'] .= "\n✓ 使用 Google Cloud Vision API 識別成功\n";
            
            // 清理预处理图片
            foreach ($processed_images as $method => $path) {
                if ($method !== 'original' && file_exists($path)) {
                    @unlink($path);
                }
            }
            
            return $result;
        } else {
            $result['debug_info'] .= $vision_result['debug_info'] ?? '';
            $result['debug_info'] .= "\nGoogle Cloud Vision API 失敗：" . ($vision_result['error'] ?? '未知錯誤') . "\n";
        }
    }
    
    
    // 方法4: 如果都沒有，返回錯誤提示
    if (!$result['success']) {
        if (empty($result['error'])) {
            $result['error'] = '未安装 OCR 工具或无法使用';
        }
        $result['debug_info'] .= "\n解決方案：\n";
        $result['debug_info'] .= "1. 安装 Tesseract OCR：https://github.com/UB-Mannheim/tesseract/wiki\n";
        $result['debug_info'] .= "2. 添加中文语言包以提高识别准确率\n";
        $result['debug_info'] .= "3. 确保图片清晰、文字足够大、对比度高\n";
    }
    
    return $result;
}

/**
 * 圖片預處理：提高 OCR 識別率
 * 包括：調整對比度、亮度、去噪、二值化等
 * @param string $image_path 原始圖片路徑
 * @param string $method 預處理方法：'enhanced', 'binary', 'contrast'
 * @return string|null 處理後的圖片路徑，失敗返回null
 */
function preprocessImage($image_path, $method = 'enhanced') {
    // 检查 GD 扩展是否可用
    if (!extension_loaded('gd')) {
        return null;  // 如果沒有 GD 擴展，跳過預處理
    }
    
    // 獲取圖片資訊
    $image_info = @getimagesize($image_path);
    if (!$image_info) {
        return null;
    }
    
    $mime_type = $image_info['mime'];
    $width = $image_info[0];
    $height = $image_info[1];
    
    // 创建图片资源
    $image = null;
    switch ($mime_type) {
        case 'image/jpeg':
        case 'image/jpg':
            $image = @imagecreatefromjpeg($image_path);
            break;
        case 'image/png':
            $image = @imagecreatefrompng($image_path);
            break;
        case 'image/gif':
            $image = @imagecreatefromgif($image_path);
            break;
        default:
            return null;
    }
    
    if (!$image) {
        return null;
    }
    
    // 如果圖片太小，放大（提高識別率）
    if ($width < 800 || $height < 600) {
        $scale = max(800 / $width, 600 / $height);
        $new_width = (int)($width * $scale);
        $new_height = (int)($height * $scale);
        $resized = imagecreatetruecolor($new_width, $new_height);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
        imagedestroy($image);
        $image = $resized;
        $width = $new_width;
        $height = $new_height;
    }
    
    // 轉換為灰度圖（提高識別率）
    $gray = imagecreatetruecolor($width, $height);
    
    // 手動轉換為灰度（更好的控制）
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $rgb = imagecolorat($image, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            // 使用加權平均法轉換為灰度
            $gray_value = (int)(0.299 * $r + 0.587 * $g + 0.114 * $b);
            $gray_color = imagecolorallocate($gray, $gray_value, $gray_value, $gray_value);
            imagesetpixel($gray, $x, $y, $gray_color);
        }
    }
    
    // 根據方法選擇不同的預處理策略
    if ($method === 'binary') {
        // 方法1：二值化（黑白化）- 最適合手寫文字
        $threshold = 140; // 二值化閾值（稍微提高以保留更多細節）
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($gray, $x, $y);
                $gray_value = $rgb & 0xFF;
                $new_value = $gray_value > $threshold ? 255 : 0;
                $new_color = imagecolorallocate($gray, $new_value, $new_value, $new_value);
                imagesetpixel($gray, $x, $y, $new_color);
            }
        }
    } elseif ($method === 'contrast') {
        // 方法2：高對比度增強
        imagefilter($gray, IMG_FILTER_CONTRAST, -50);
        imagefilter($gray, IMG_FILTER_CONTRAST, -30);
        imagefilter($gray, IMG_FILTER_BRIGHTNESS, 5);
        // 輕微銳化
        imagefilter($gray, IMG_FILTER_EDGEDETECT);
        imagefilter($gray, IMG_FILTER_NEGATE);
        imagefilter($gray, IMG_FILTER_CONTRAST, -30);
        imagefilter($gray, IMG_FILTER_NEGATE);
    } else {
        // 方法3：增強預處理（默認）- 對比度 + 銳化 + 自適應二值化
        // 應用多次對比度增強（更強）
        imagefilter($gray, IMG_FILTER_CONTRAST, -30);
        imagefilter($gray, IMG_FILTER_CONTRAST, -20);
        
        // 應用亮度調整
        imagefilter($gray, IMG_FILTER_BRIGHTNESS, 10);
        
        // 應用銳化（多次）
        imagefilter($gray, IMG_FILTER_EDGEDETECT);
        imagefilter($gray, IMG_FILTER_SMOOTH, -3);
        imagefilter($gray, IMG_FILTER_MEAN_REMOVAL);
        
        // 自適應二值化處理（提高手寫文字識別率）
        $threshold = 140; // 閾值
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($gray, $x, $y);
                $gray_value = $rgb & 0xFF;
                // 自適應：大於閾值設為白色(255)，否則設為更深的黑色
                $new_value = $gray_value > $threshold ? 255 : max(0, $gray_value - 40);
                $new_color = imagecolorallocate($gray, $new_value, $new_value, $new_value);
                imagesetpixel($gray, $x, $y, $new_color);
            }
        }
        
        // 再次應用對比度增強
        imagefilter($gray, IMG_FILTER_CONTRAST, -50);
    }
    
    // 保存處理後的圖片
    $processed_path = sys_get_temp_dir() . '/ocr_processed_' . $method . '_' . uniqid() . '.png';
    imagepng($gray, $processed_path, 9);
    imagedestroy($image);
    imagedestroy($gray);
    
    return $processed_path;
}

/**
 * 解析簽到表文本，提取姓名和電話號碼
 * 格式：姓名 電話號碼（每行一條）
 */
function parseAttendanceText($text, &$debug_info = '') {
    $lines = explode("\n", $text);
    $parsed_data = [];
    
    $debug_info .= "\n=== 開始解析文本 ===\n";
    $debug_info .= "總行數：" . count($lines) . "\n\n";
    
    foreach ($lines as $line_num => $line) {
        $original_line = $line;
        $line = trim($line);
        if (empty($line)) {
            $debug_info .= "第 " . ($line_num + 1) . " 行：空行，跳過\n";
            continue;
        }
        
        $debug_info .= "第 " . ($line_num + 1) . " 行原始內容：$original_line\n";
        
        // 移除多餘的空格，但保留單個空格用於分割
        $line = preg_replace('/\s+/', ' ', $line);
        $debug_info .= "處理後：$line\n";
        
        // 嘗試匹配電話號碼（台灣手機號格式：09xxxxxxxx）
        // 匹配模式1：09 開頭的 10 位數字（標準格式）
        $phone_pattern = '/(09\d{8})/';
        
        if (preg_match($phone_pattern, $line, $phone_matches)) {
            $phone = $phone_matches[1];
            $debug_info .= "  找到電話號碼（標準格式）：$phone\n";
            
            // 提取电话号码前的文本作为姓名
            $name = trim(preg_replace($phone_pattern, '', $line));
            // 移除姓名前后的特殊字符（如 -、|、: 等）
            $name = preg_replace('/^[-|:：\s]+|[-|:：\s]+$/', '', $name);
            $name = trim($name);
            
            $debug_info .= "  提取的姓名：$name\n";
            
            // 如果姓名为空，嘗試從電話號碼後提取
            if (empty($name)) {
                $parts = preg_split($phone_pattern, $line, 2);
                if (count($parts) > 1 && !empty(trim($parts[1]))) {
                    $name = trim($parts[1]);
                    $name = preg_replace('/^[-|:：\s]+|[-|:：\s]+$/', '', $name);
                    $debug_info .= "  從電話後提取姓名：$name\n";
                }
            }
            
            // 如果仍然沒有姓名，設置為"無名"
            if (empty($name)) {
                $name = '無名';
                $debug_info .= "  姓名為空，設為「無名」\n";
            }
            
            $parsed_data[] = [
                'name' => $name,
                'phone' => $phone
            ];
            $debug_info .= "  ✓ 解析成功：姓名=$name, 電話=$phone\n\n";
        } else {
            // 匹配模式2：查找 8-10 位连续数字（可能是电话号码）
            // 优先查找 9 开头的 9-10 位数字（可能是缺少前导0的电话号码）
            if (preg_match('/(9\d{8,9})/', $line, $phone_matches)) {
                $phone = '0' . $phone_matches[1]; // 添加前导0
                $debug_info .= "  找到電話號碼（9開頭，補0）：$phone\n";
                
                // 提取电话号码前的文本作为姓名
                $name = trim(preg_replace('/(9\d{8,9})/', '', $line));
                $name = preg_replace('/^[-|:：\s]+|[-|:：\s]+$/', '', $name);
                
                if (empty($name)) {
                    $name = '無名';
                }
                
                $parsed_data[] = [
                    'name' => $name,
                    'phone' => $phone
                ];
                $debug_info .= "  ✓ 解析成功：姓名=$name, 電話=$phone\n\n";
            } 
            // 匹配模式3：查找任意 8-10 位數字
            elseif (preg_match('/(\d{8,10})/', $line, $num_matches)) {
                $phone = $num_matches[1];
                $debug_info .= "  找到數字（8-10位）：$phone\n";
                
                // 如果是 9 開頭且長度為 9，添加前導0
                if (strlen($phone) == 9 && substr($phone, 0, 1) === '9') {
                    $phone = '0' . $phone;
                    $debug_info .= "  補0後：$phone\n";
                }
                
                // 提取電話號碼前的文本作為姓名
                $name = trim(preg_replace('/(\d{8,10})/', '', $line));
                $name = preg_replace('/^[-|:：\s]+|[-|:：\s]+$/', '', $name);
                
                
                $name_clean = preg_replace('/[^\x{4e00}-\x{9fa5}a-zA-Z\s]/u', '', $name);
                if (!empty($name_clean)) {
                    $name = trim($name_clean);
                }
                
                if (empty($name)) {
                    $name = '無名';
                }
                
                $parsed_data[] = [
                    'name' => $name,
                    'phone' => $phone
                ];
                $debug_info .= "  ✓ 解析成功：姓名=$name, 電話=$phone\n\n";
            } else {
                // 如果沒有找到電話號碼，嘗試提取可能的姓名和數字
                $line_clean = preg_replace('/[^\x{4e00}-\x{9fa5}a-zA-Z0-9\s]/u', '', $line);
                $debug_info .= "  未找到標準電話格式，嘗試提取：$line_clean\n";
                
                // 嘗試提取數字序列
                if (preg_match('/(\d{6,})/', $line_clean, $num_matches)) {
                    $phone = $num_matches[1];
                    if (strlen($phone) == 9 && substr($phone, 0, 1) === '9') {
                        $phone = '0' . $phone;
                    }
                    
                    $name = trim(str_replace($phone, '', $line_clean));
                    $name = preg_replace('/\s+/', '', $name);
                    
                    if (empty($name)) {
                        $name = '無名';
                    }
                    
                    $parsed_data[] = [
                        'name' => $name,
                        'phone' => $phone
                    ];
                    $debug_info .= "  ✓ 解析成功（備用模式）：姓名=$name, 電話=$phone\n\n";
                } else {
                    $debug_info .= "  ✗ 無法解析此行\n\n";
                }
            }
        }
    }
    
    $debug_info .= "=== 解析完成 ===\n";
    $debug_info .= "共解析出 " . count($parsed_data) . " 條記錄\n\n";
    
    return $parsed_data;
}

/**
 * 標準化電話號碼（移除空格、橫線等）
 */
function normalizePhone($phone) {
    if (empty($phone)) return '';
    // 移除所有非數字字符，保留數字
    $phone = preg_replace('/[^\d]/', '', $phone);
    // 如果是 0 開頭，保留；否則嘗試添加 0
    if (strlen($phone) >= 9 && strlen($phone) <= 10) {
        if (substr($phone, 0, 1) !== '0' && substr($phone, 0, 1) === '9') {
            $phone = '0' . $phone;
        }
    }
    return $phone;
}

/**
 * 使用 Google Cloud Vision API 進行 OCR 識別
 * 這是最準確的OCR服務，特別適合手寫文字識別
 */
function performGoogleCloudVisionOCR($image_path) {
    $result = [
        'success' => false,
        'text' => '',
        'error' => '',
        'debug_info' => ''
    ];
    
    try {
        // 讀取圖片並轉換為 base64
        $image_data = file_get_contents($image_path);
        $base64_image = base64_encode($image_data);
        
        $result['debug_info'] .= "圖片大小：" . strlen($image_data) . " 字節\n";
        
        // 构建请求
        $url = 'https://vision.googleapis.com/v1/images:annotate?key=' . GOOGLE_CLOUD_VISION_API_KEY;
        
        $request_data = [
            'requests' => [
                [
                    'image' => [
                        'content' => $base64_image
                    ],
                    'features' => [
                        [
                            'type' => 'TEXT_DETECTION',  // 文本檢測（包括手寫）
                            'maxResults' => 1
                        ]
                    ],
                    'imageContext' => [
                        'languageHints' => ['zh-TW', 'en']  // 提示使用繁體中文和英文
                    ]
                ]
            ]
        ];
        
        $result['debug_info'] .= "發送請求到 Google Cloud Vision API...\n";
        
        // 發送請求
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($curl_error) {
            $result['error'] = '網絡錯誤：' . $curl_error;
            $result['debug_info'] .= "CURL 錯誤：$curl_error\n";
            return $result;
        }
        
        $result['debug_info'] .= "HTTP 狀態碼：$http_code\n";
        
        if ($http_code !== 200) {
            $result['error'] = 'API 請求失敗，HTTP 狀態碼：' . $http_code;
            $result['debug_info'] .= "響應內容：" . substr($response, 0, 500) . "\n";
            return $result;
        }
        
        $response_data = json_decode($response, true);
        
        if (isset($response_data['responses'][0]['error'])) {
            $result['error'] = 'API 錯誤：' . $response_data['responses'][0]['error']['message'];
            $result['debug_info'] .= "API 錯誤：" . $result['error'] . "\n";
            return $result;
        }
        
        if (isset($response_data['responses'][0]['textAnnotations']) && 
            !empty($response_data['responses'][0]['textAnnotations'])) {
            // 第一個元素是完整的識別文本
            $full_text = $response_data['responses'][0]['textAnnotations'][0]['description'];
            $result['text'] = $full_text;
            $result['success'] = true;
            $result['debug_info'] .= "識別成功，文字長度：" . mb_strlen($full_text) . " 字符\n";
        } else {
            $result['error'] = '未檢測到文字';
            $result['debug_info'] .= "API 響應中未找到文字\n";
        }
        
    } catch (Exception $e) {
        $result['error'] = '處理錯誤：' . $e->getMessage();
        $result['debug_info'] .= "異常：" . $e->getMessage() . "\n";
    }
    
    return $result;
}

/**
 * 使用百度 OCR API 進行識別
 * 對中文識別準確率很高，適合中國大陸使用
 */
function performBaiduOCR($image_path) {
    $result = [
        'success' => false,
        'text' => '',
        'error' => '',
        'debug_info' => ''
    ];
    
    try {
        // 獲取 access_token
        $token_url = 'https://aip.baidubce.com/oauth/2.0/token';
        $token_params = [
            'grant_type' => 'client_credentials',
            'client_id' => BAIDU_OCR_API_KEY,
            'client_secret' => BAIDU_OCR_SECRET_KEY
        ];
        
        $result['debug_info'] .= "獲取百度 OCR Access Token...\n";
        
        $ch = curl_init($token_url . '?' . http_build_query($token_params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $token_response = curl_exec($ch);
        $token_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($token_http_code !== 200) {
            $result['error'] = '獲取 Access Token 失敗，HTTP 狀態碼：' . $token_http_code;
            return $result;
        }
        
        $token_data = json_decode($token_response, true);
        if (!isset($token_data['access_token'])) {
            $result['error'] = '獲取 Access Token 失敗：' . ($token_data['error_description'] ?? '未知錯誤');
            return $result;
        }
        
        $access_token = $token_data['access_token'];
        $result['debug_info'] .= "成功獲取 Access Token\n";
        
        // 讀取圖片並轉換為 base64
        $image_data = file_get_contents($image_path);
        $base64_image = base64_encode($image_data);
        
        $result['debug_info'] .= "圖片大小：" . strlen($image_data) . " 字節\n";
        
        // 調用 OCR API
        $ocr_url = 'https://aip.baidubce.com/rest/2.0/ocr/v1/general_basic?access_token=' . $access_token;
        
        $ocr_params = [
            'image' => $base64_image,
            'language_type' => 'CHN_ENG',  // 中英文混合
            'detect_direction' => 'true',  // 檢測方向
            'detect_language' => 'true'    // 檢測語言
        ];
        
        $result['debug_info'] .= "發送請求到百度 OCR API...\n";
        
        $ch = curl_init($ocr_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($ocr_params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $ocr_response = curl_exec($ch);
        $ocr_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($ocr_http_code !== 200) {
            $result['error'] = 'API 請求失敗，HTTP 狀態碼：' . $ocr_http_code;
            return $result;
        }
        
        $ocr_data = json_decode($ocr_response, true);
        
        if (isset($ocr_data['error_code'])) {
            $result['error'] = 'API 錯誤：' . ($ocr_data['error_msg'] ?? '未知錯誤');
            $result['debug_info'] .= "錯誤代碼：" . $ocr_data['error_code'] . "\n";
            return $result;
        }
        
        if (isset($ocr_data['words_result']) && !empty($ocr_data['words_result'])) {
            // 合并所有识别的文字
            $text_lines = [];
            foreach ($ocr_data['words_result'] as $word) {
                if (isset($word['words'])) {
                    $text_lines[] = $word['words'];
                }
            }
            $full_text = implode("\n", $text_lines);
            $result['text'] = $full_text;
            $result['success'] = true;
            $result['debug_info'] .= "識別成功，文字長度：" . mb_strlen($full_text) . " 字符\n";
            $result['debug_info'] .= "識別到 " . count($text_lines) . " 行文字\n";
        } else {
            $result['error'] = '未檢測到文字';
            $result['debug_info'] .= "API 響應中未找到文字\n";
        }
        
    } catch (Exception $e) {
        $result['error'] = '處理錯誤：' . $e->getMessage();
        $result['debug_info'] .= "異常：" . $e->getMessage() . "\n";
    }
    
    return $result;
}

