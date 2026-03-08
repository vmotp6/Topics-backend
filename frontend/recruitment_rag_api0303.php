<?php

/**
 * 招生知識庫 RAG API
 * - 依關鍵字搜尋 recruitment_knowledge（問題＋回答）
 * - 有資料：組成 context，呼叫 Ollama（預設 qwen2.5:3b）整理回答
 * - 沒資料：不呼叫 AI，只回「目前沒有擴充資訊」
 * - 回傳 sources：含問題、來源、建立時間、建立者、附件數
 */
if (session_status() === PHP_SESSION_NONE) {
    session_name('KANGNING_SESSION');
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

if (
    empty($_SESSION['user_id']) &&
    empty($_SESSION['admin_logged_in']) &&
    empty($_SESSION['logged_in'])
) {
    echo json_encode(['success' => false, 'error' => '請先登入']);
    exit;
}

$role = trim($_SESSION['role'] ?? '');
$role_map = [
    '管理員' => 'ADM',
    '行政人員' => 'STA', '招生中心' => 'STA',
    '主任' => 'DI', 
    '老師' => 'TEA',
];
$norm = $role_map[$role] ?? $role;
if (!in_array($norm, ['ADM', 'STA', 'TEA', 'DI'], true)) {
    echo json_encode(['success' => false, 'error' => '僅老師或管理員可使用提問功能']);
    exit;
}

require_once __DIR__ . '/../../Topics-frontend/frontend/config.php';

$input    = json_decode(file_get_contents('php://input'), true) ?: [];
$question = trim($input['question'] ?? $_POST['question'] ?? '');
if ($question === '') {
    echo json_encode(['success' => false, 'error' => '請輸入問題']);
    exit;
}

// 常見寒暄過濾
if (preg_match('/你好|嗨|哈囉|在嗎|Hello|HELLO|HEllo/u', $question)) {
    echo json_encode([
        'success' => true,
        'ai_used' => false,
        'answer'  => "您好 😊 這裡是招生知識庫系統，請直接輸入招生相關問題，例如學費、時間、科系等。",
        'sources' => [],
        'files'   => []
    ]);
    exit;
}

// 先偵測是否有明確學校名稱
$school_pattern = '/康寧大學|國立臺灣大學|台灣科技大學|海洋科技大學|其他學校名稱/u'; // 可自行擴充
$mentioned_school = [];
if (preg_match_all($school_pattern, $question, $matches)) {
    $mentioned_school = $matches[0];
}

// 判斷回覆的學校
$target_school = '康寧大學'; // 默認
$school_warning = '';
// 若問題中提到非康寧大學，加入警告
if (!empty($mentioned_school) && !in_array('康寧大學', $mentioned_school)) {
    echo json_encode([
        'success' => true,
        'ai_used' => false,
        'answer'  => "⚠️ 注意：本系統專屬於康寧大學招生知識庫，僅提供康寧大學的官方資訊。\n您查詢的其他學校資料請自行查詢教育部或該校官方網站。\n\n",
        'sources' => [],
        'files'   => []
    ]);
    exit;
}
$conn = getDatabaseConnection();

// 確認資料表與欄位存在
$t1 = $conn->query("SHOW TABLES LIKE 'recruitment_knowledge'");
$t2 = $conn->query("SHOW TABLES LIKE 'recruitment_knowledge_files'");

if (!$t1 || !$t2 || $t1->num_rows === 0 || $t2->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => '招生知識庫資料表尚未建立']);
    exit;
}

$cols = $conn->query("SHOW COLUMNS FROM recruitment_knowledge LIKE 'question'");
if (!$cols || $cols->num_rows === 0) {
    
    echo json_encode(['success' => false, 'error' => 'recruitment_knowledge 結構不是「問題→回答」，請先更新資料表結構']);
    exit;
}

/* 關鍵字搜尋 question / answer */
$sources       = [];
$files         = [];
$context_parts = [];

$keywords = preg_split('/\s+/u', $question, -1, PREG_SPLIT_NO_EMPTY);
$keywords = array_filter($keywords, function ($w) { return mb_strlen($w, 'UTF-8') >= 2; });

// 若整句是中文、沒有空白
if (count($keywords) === 1) {
    $only = reset($keywords);
    $len  = mb_strlen($only, 'UTF-8');
    if (!preg_match('/[A-Za-z0-9]/u', $only) && !preg_match('/\s/u', $only)) {
        if ($len >= 6) {
            // 6 字以上：用「前段 + 後 2 字」AND 條件，避免錯檢
            // 例：「優先免試時間」→ 需同時含「優先免試」與「時間」，可命中「優先免試招生時間」，排除「優先免試科系」
            $keywords = [
                mb_substr($only, 0, $len - 2, 'UTF-8'),
                mb_substr($only, -2, null, 'UTF-8'),
            ];
        } elseif ($len >= 4 && $len <= 5) {
            $extra   = [mb_substr($only, 0, 2, 'UTF-8'), mb_substr($only, -2, null, 'UTF-8')];
            $keywords = array_values(array_unique(array_merge($keywords, $extra)));
        }
    }
}
$like_conds = [];
$params     = [];
$types      = '';
$use_and    = false;

// 若 keywords 是拆成「前段 + 後綴」的 AND 模式（長句 6+ 字）
if (count($keywords) === 2) {
    $k1 = $keywords[0];
    $k2 = $keywords[1];
    if (mb_strlen($k1, 'UTF-8') >= 2 && mb_strlen($k2, 'UTF-8') === 2) {
        $use_and = true;
        $like_conds[] = "((k.question LIKE ? OR k.answer LIKE ?) AND (k.question LIKE ? OR k.answer LIKE ?))";
        $p1 = '%' . $k1 . '%';
        $p2 = '%' . $k2 . '%';
        $params = [$p1, $p1, $p2, $p2];
        $types  = 'ssss';
    }
}
if (!$use_and) {
    foreach (array_slice($keywords, 0, 5) as $k) {
        $like_conds[] = "(k.question LIKE ? OR k.answer LIKE ?)";
        $p = '%' . $k . '%';
        $params[] = $p;
        $params[] = $p;
        $types   .= 'ss';
    }
}

$where = "k.is_active = 1";
if (!empty($like_conds)) {
    $where .= $use_and ? " AND " . $like_conds[0] : " AND (" . implode(' OR ', $like_conds) . ")";
}

// 只查 recruitment_knowledge、recruitment_knowledge_files；user 僅用於 created_by_name 顯示
$escaped_q = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $conn->real_escape_string($question));
$order_relevance = "(CASE WHEN (k.question LIKE '%{$escaped_q}%' OR k.answer LIKE '%{$escaped_q}%') THEN 0 ELSE 1 END)";
$sql = "
    SELECT
        k.*,
        COALESCE(u.name, u.username, '') AS created_by_name,
        (SELECT COUNT(*) FROM recruitment_knowledge_files f WHERE f.knowledge_id = k.id) AS file_count
    FROM recruitment_knowledge k
    LEFT JOIN user u ON u.id = k.created_by
    WHERE {$where}
    ORDER BY {$order_relevance}, k.updated_at DESC, k.id DESC
    LIMIT 5
";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
} else {
    $res = $conn->query($sql);
}

$kid_list = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $kid = (int)$row['id'];
        $kid_list[] = $kid;
        $label = (string)($row['source_label'] ?? '');

        $sources[] = [
            'id'              => $kid,
            'question'        => (string)($row['question'] ?? ''),
            'source_label'    => (string)$label,
            'created_at'      => (string)($row['created_at'] ?? ''),
            'created_by'      => (int)($row['created_by'] ?? 0),
            'created_by_name' => (string)($row['created_by_name'] ?? ''),
            'file_count'      => (int)($row['file_count'] ?? 0),
        ];

        $context_parts[] =
            trim((string)($row['answer'] ?? ''));
    }
}

// 取得附件檔案（只查 recruitment_knowledge_files + recruitment_knowledge；user 僅用於 created_by_name）
if (!empty($kid_list)) {
    $ids  = implode(',', array_map('intval', $kid_list));
    $fres = $conn->query(
        "SELECT f.knowledge_id, f.file_path, f.file_original_name,
                k.source_label, k.created_at, COALESCE(u.name, u.username, '') AS created_by_name
         FROM recruitment_knowledge_files f
         JOIN recruitment_knowledge k ON k.id = f.knowledge_id
         LEFT JOIN user u ON u.id = k.created_by
         WHERE f.knowledge_id IN ({$ids})"
    );
    if ($fres) {
        while ($f = $fres->fetch_assoc()) {
            $files[] = [
                'knowledge_id'      => (int)$f['knowledge_id'],
                'file_original_name'=> (string)$f['file_original_name'],
                'file_path'         => (string)$f['file_path'],
                'source_label'      => (string)($f['source_label'] ?? ''),
                'created_at'        => (string)($f['created_at'] ?? ''),
                'created_by_name'   => (string)($f['created_by_name'] ?? ''),
            ];
        }
    }
}

if (!empty($sources) || !empty($files)) {

    // ⭐ 第一筆當主回答
    $main_answer = $context_parts[0] ?? '';

    // ⭐ 其餘當補充
    // ⭐ 其餘當補充，帶上建立者與建立時間
    $extra_answers = [];
    for ($i = 1; $i < count($context_parts); $i++) {
        $extra_answers[] = [
            'answer'          => $context_parts[$i],
            'created_by_name' => $sources[$i]['created_by_name'] ?? '',
            'created_at'      => $sources[$i]['created_at'] ?? '',
            'source_label'    => $sources[$i]['source_label'] ?? '',
        ];
    }

    // 回傳給前端
    echo json_encode([
        'success'       => true,
        'ai_used'       => false,
        'main_answer'   => $main_answer,
        'extra_answers' => $extra_answers,
        'sources'       => $sources,
        'files'         => $files
    ]);
    exit;
    $answer = "";

    if ($main_answer !== '') {
        $answer .= "【主要回答】\n";
        $answer .= trim($main_answer);
    }

    if (!empty($extra_answers)) {
        $answer .= "\n\n【補充資訊】\n";

        foreach ($extra_answers as $extra) {
            $extra = trim($extra);
            if ($extra !== '') {
                $answer .= "- " . $extra . "\n";
            }
        }
    }

    $ai_used = false;

    echo json_encode([
        'success' => true,
        'ai_used' => $ai_used,
        'main_answer'   => $main_answer,
        'extra_answers' => $extra_answers,
        'sources'       => $sources,
        'files'         => $files
    ]);
    exit;
}


// 當問題問「時間／日程／時程」時，只傳含時間相關內容的資料給 AI，排除「科系列表」等答非所問
if (count($context_parts) > 0 && preg_match('/時間|日程|時程|日期|幾月|何時|什麼時候/u', $question)) {
    $pattern = '/時間|日程|時程|日期|幾月|何時|什麼時候/u';
    $filtered_ctx = [];
    $filtered_src = [];
    foreach ($context_parts as $i => $c) {
        if (preg_match($pattern, $c)) {
            $filtered_ctx[] = $c;
            if (isset($sources[$i])) $filtered_src[] = $sources[$i];
        }
    }
    if (!empty($filtered_ctx)) {
        $context_parts = $filtered_ctx;
        $sources = $filtered_src;
    }
}




// 沒有任何命中：不呼叫 AI，只如實說明
if (count($context_parts) === 0) {

    $chat_mode = true;   // 🔥 標記現在是對話模式

    $prompt =
    "你是康寧大學招生知識庫的 AI 助手。

    目前官方資料庫沒有相關資料。
    請自然回答使用者問題。
    如果不是招生問題，可以自由對話。
    使用者問題：
    {$question}";
    $prompt .= "\n請用繁體中文回答。";
    $answer = callOllama($prompt);

    echo json_encode([
        'success'  => true,
        'ai_used'  => true,
        'answer'   => $answer ?: "我在喔 😊 有什麼想聊的嗎？",
        'ai_error' => '',
        'sources'  => [],
        'files'    => []
    ]);

    $conn->close();
    exit;
}

$context = implode("\n\n---\n\n", array_slice($context_parts, 0, 3));



/**
 * 判斷官方資料庫中「實際存在」的資訊類型
 * 用來告知 AI 哪些欄位可以回答，哪些禁止補齊
 */
$context_flags = [
    'has_schedule'    => false, // 時程 / 日期
    'has_departments' => false, // 科系清單
];

foreach ($context_parts as $c) {
    if (preg_match('/時間|日程|時程|日期|幾月|何時|什麼時候/u', $c)) {
        $context_flags['has_schedule'] = true;
    }
/**
 * 僅在「明確出現科系清單語境」時，
 * 才視為資料庫中真的有「科系資訊」
 */
if (preg_match(
    '/(招生)?科系(一覽|清單|如下|包含|共有|設有)|設有.*科系|科系包括/u',
    $c
)) {
    $context_flags['has_departments'] = true;
}
}

// Ollama API 位置（同首頁 AI 狀態）
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$script   = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = preg_replace('#/Topics-backend/frontend/.*$#', '', $script);
//$ollama_api_url          = $protocol . '://' . $host . $basePath . '/Topics-frontend/backend/api/ollama/backend_ollama_api.php';
$ollama_api_url_fallback = getenv('OLLAMA_API_URL') ?: '';

// 模型選擇：預設偏好 qwen2.5:3b
$preferred_model  = getenv('OLLAMA_RAG_MODEL') ?: 'qwen2.5:3b';
$available_models = [];
$model            = '';

foreach ([$ollama_api_url_fallback, $ollama_api_url] as $apiUrl) {
    if ($apiUrl === '') continue;
    $resp = @file_get_contents($apiUrl . '?action=get_models');
    if ($resp === false) continue;
    $data = json_decode($resp, true);
    if (is_array($data) && isset($data['models']) && is_array($data['models'])) {
        foreach ($data['models'] as $m) {
            if (is_string($m)) {
                $available_models[] = $m;
            } elseif (is_array($m) && !empty($m['name'])) {
                $available_models[] = (string)$m['name'];
            }
        }
    }
    if (!empty($available_models)) break;
}
$available_models = array_values(array_unique(array_filter($available_models)));

if ($preferred_model !== '' && in_array($preferred_model, $available_models, true)) {
    $model = $preferred_model;
} elseif (!empty($available_models)) {
    $model = $available_models[0];
} else {
    $model = $preferred_model; // 可能為空，後面會再處理
}

// 建立 prompt：只能用資料庫內容，不得亂編；只回答與問題直接相關的內容
$question_hint = '';
if (preg_match('/時間|日程|時程|日期|幾月|何時|什麼時候/u', $question)) {
    $question_hint = "老師問的是「時間／日程／時程」，你必須只回答與時間、日期、時程相關的內容。絕對不可回答科系列表、學費、名額等與時間無關的內容。若資料中沒有時間資訊，請回答：「資料庫目前沒有擴充資訊」。\n";
} elseif (preg_match('/科系|科別|有哪些科|幾個科/u', $question)) {
    $question_hint = "老師問的是「科系／科別」，你必須只回答與科系相關的內容。絕對不可回答時間、學費等與科系無關的內容。\n";
}elseif (preg_match('/學費|費用|收費|多少錢/u', $question) && count($context_parts) > 0) {
    $main_answer = $context_parts[0] ?? '';
    $extra_answers = [];
    for ($i = 1; $i < count($context_parts); $i++) {
        $extra_answers[] = [
            'answer' => $context_parts[$i],
            'source' => $sources[$i] ?? null
        ];
    }
    exit;
}

$official_site = "https://www.ukn.edu.tw/p/412-1000-381.php?Lang=zh-tw";

$prompt =
"【系統規則（最高優先）】
1. 你只能使用『官方招生資料庫內容』中明確出現的文字回答。
2. 嚴禁使用你的背景知識、推測或補齊資料庫未提供的內容。
3. 若某一類資訊在下方標示為「沒有」，你必須完全忽略該類資訊，不得提及。

【系統已檢查資料庫】
- 是否有招生時程資訊：" . ($context_flags['has_schedule'] ? '有' : '沒有') . "
- 是否有科系清單資訊：" . ($context_flags['has_departments'] ? '有' : '沒有') . "

規則補充：
- 若「科系清單資訊 = 沒有」，你不得列出任何科系名稱或數量。
- 若「招生時程資訊 = 沒有」，你不得推測任何日期或時程。
- 若資料庫中沒有明確答案，請回答：「資料庫目前沒有擴充資訊」。

【官方招生資料庫內容】
" . $context . "

【老師的問題】
" . $question . "

若需要提供查詢網址，請使用：
{$official_site}

【請根據以上資料回答】
";

$answer  = '';
$err     = '';
$ai_used = false;

try {
    $tried = false;

    foreach ([$ollama_api_url_fallback, $ollama_api_url] as $apiUrl) {
        if ($apiUrl === '') continue;
        $tried = true;

        $post_data = [
            'action'      => 'ask_question',
            'question'    => $prompt,
            'model'       => $model,
            'use_context' => '0',
        ];
        $opts = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($post_data),
                'timeout' => 180,
            ],
        ];
        $ctx  = stream_context_create($opts);
        $resp = @file_get_contents($apiUrl, false, $ctx);
        if ($resp !== false) {
            $data = json_decode($resp, true);
            if (!empty($data['answer'])) {
                $answer  = trim($data['answer']);
                $ai_used = true;
                break;
            }
            if (!empty($data['response'])) {
                $answer  = trim($data['response']);
                $ai_used = true;
                break;
            }
            if (!empty($data['error'])) {
                $err = (string)$data['error'];
            }

            // 若模型不存在，改用第一個可用模型重試
            if (!empty($available_models) && is_string($err) && stripos($err, 'not found') !== false) {
                $model = $available_models[0];
                $post_data['model'] = $model;
                $opts['http']['content'] = http_build_query($post_data);
                $ctx2  = stream_context_create($opts);
                $resp2 = @file_get_contents($apiUrl, false, $ctx2);
                if ($resp2 !== false) {
                    $data2 = json_decode($resp2, true);
                    if (!empty($data2['answer'])) {
                        $answer  = trim($data2['answer']);
                        $ai_used = true;
                        $err     = '';
                        break;
                    }
                    if (!empty($data2['response'])) {
                        $answer  = trim($data2['response']);
                        $ai_used = true;
                        $err     = '';
                        break;
                    }
                    if (!empty($data2['error'])) {
                        $err = (string)$data2['error'];
                    }
                }
            }
        }
    }

    // 直連 /api/generate 做最後備援
    if ((!$tried || $answer === '') && $err === '') {
        $direct_url = getenv('OLLAMA_URL') ?: 'http://127.0.0.1:11434';
        if ($model === '') $model = 'qwen2.5:3b';
        $body = json_encode([
            'model'  => $model,
            'prompt' => $prompt,
            'stream' => false,
        ]);
        $opts = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n",
                'content' => $body,
                'timeout' => 180,
            ],
        ];
        $ctx  = stream_context_create($opts);
        $resp = @file_get_contents(rtrim($direct_url, '/') . '/api/generate', false, $ctx);
        if ($resp !== false) {
            $data = json_decode($resp, true);
            if (!empty($data['response'])) {
                $answer  = trim($data['response']);
                $ai_used = true;
            } elseif (!empty($data['error'])) {
                $err = (string)$data['error'];
            }
        } else {
            $err = '無法連線至 AI 服務（Ollama），僅能顯示資料庫中的原始內容。';
        }
    }
} catch (Exception $e) {
    $err = $e->getMessage();
}

// 若 AI 完全失敗：仍回官方內容，不報錯給使用者
if ($answer === '') {
    $joined =
        "（目前無法使用 AI 整理回答，下方為資料庫中的原始內容摘錄）\n\n" .
        implode("\n\n---\n\n", array_slice($context_parts, 0, 5));
    $answer  = $joined;
    $ai_used = false;
}

/**
 * 最後防線：
 * 若資料庫沒有科系資訊，移除 AI 回答中任何「科系清單段落」
 * 不影響其他正確內容（如時間、檔案）
 */
if (!$context_flags['has_departments']) {
    $answer = preg_replace(
        '/(總共有|共有|包含).*(科系|科)[\s\S]*$/u',
        '',
        $answer
    );
    $answer = trim($answer);
}

/**
 * 若答案在清理後為空：
 * - 不顯示文字回答
 * - 不顯示來源說明
 * - 但「保留檔案清單」
 */
/*if ($answer === '') {
    $ai_used = false;
    $sources = [];   // 關鍵：只清來源
    // files 保留
}*/

echo json_encode([
    'success'  => true,
    'ai_used'  => $ai_used,
    'answer'   => $answer,
    'ai_error' => ($ai_used ? '' : $err),
    'sources'  => $sources,
    'files'    => $files,
]);


function semanticSearchWithOllama($conn, $question)
{
    // 1️⃣ 取出問題列表
    $sql = "
        SELECT id, question, answer, source_label, created_at
        FROM recruitment_knowledge
        WHERE is_active = 1
        ORDER BY updated_at DESC
        LIMIT 30
    ";

    $res = $conn->query($sql);
    if (!$res || $res->num_rows === 0) return [];

    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }

    // 2️⃣ 組問題清單給 Ollama
    $list_text = "";
    foreach ($rows as $r) {
        $list_text .= "ID:{$r['id']} 問題: {$r['question']}\n";
    }

    $prompt = "
你是一個語意比對助手。
請從下列問題列表中，選出與使用者問題最相似的 3 筆 ID。
只回答 ID，用逗號分隔，不要解釋。

使用者問題：
{$question}

問題列表：
{$list_text}
";

    $response = callOllama($prompt);

file_put_contents(
    __DIR__ . '/debug_semantic.txt',
    "使用者問題: {$question}\n模型回傳:\n{$response}\n\n",
    FILE_APPEND
);
    if (!$response) return [];

    // 3️⃣ 抓 ID
    preg_match_all('/\d+/', $response, $matches);
    $ids = $matches[0] ?? [];

    if (empty($ids)) return [];

    // 4️⃣ 再查完整資料
    $id_list = implode(',', array_map('intval', $ids));
    $sql2 = "
        SELECT question, answer, source_label, created_at
        FROM recruitment_knowledge
        WHERE id IN ({$id_list})
    ";

    $res2 = $conn->query($sql2);
    if (!$res2) return [];

    $final = [];
    while ($row = $res2->fetch_assoc()) {
        $final[] =
             $row['answer'];
    }

    return $final;
}

function callOllama($prompt)
{
    $url = 'http://127.0.0.1:11434/api/generate';

    $data = json_encode([
        'model'  => 'qwen2.5:3b',
        'prompt' => $prompt,
        'stream' => false
    ]);

    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => $data,
            'timeout' => 180,
        ],
    ];

    $context = stream_context_create($opts);
    $result  = @file_get_contents($url, false, $context);

    if (!$result) return null;

    $json = json_decode($result, true);
    return $json['response'] ?? null;
}

exit;

