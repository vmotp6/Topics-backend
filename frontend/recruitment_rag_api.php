<?php
/**
 * 招生知識庫 RAG API - 重構版
 */

session_name('KANGNING_SESSION');
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../Topics-frontend/frontend/config.php';

/* -------------------- Helper Functions -------------------- */

function jsonExit($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function checkLogin() {
    if (empty($_SESSION['user_id']) && empty($_SESSION['admin_logged_in']) && empty($_SESSION['logged_in'])) {
        jsonExit(['success' => false, 'error' => '請先登入']);
    }
}

function normalizeRole($role) {
    $map = [
        '管理員' => 'ADM',
        '行政人員' => 'STA', '招生中心' => 'STA',
        '主任' => 'DI', '老師' => 'TEA',
    ];
    return $map[$role] ?? $role;
}

function isValidRole($role) {
    return in_array($role, ['ADM','STA','TEA','DI'], true);
}

function sanitizeKeywords($question) {
    $keywords = preg_split('/\s+/u', $question, -1, PREG_SPLIT_NO_EMPTY);
    $keywords = array_filter($keywords, fn($w) => mb_strlen($w, 'UTF-8') >= 2);

    if (count($keywords) === 1) {
        $only = reset($keywords);
        $len = mb_strlen($only, 'UTF-8');
        if (!preg_match('/[A-Za-z0-9]/u', $only) && !preg_match('/\s/u', $only)) {
            if ($len >= 6) {
                $keywords = [
                    mb_substr($only, 0, $len - 2, 'UTF-8'),
                    mb_substr($only, -2, null, 'UTF-8')
                ];
            } elseif ($len >= 4) {
                $keywords = array_values(array_unique(array_merge($keywords, [
                    mb_substr($only, 0, 2, 'UTF-8'),
                    mb_substr($only, -2, null, 'UTF-8')
                ])));
            }
        }
    }
    return $keywords;
}

function callOllama($prompt, $model='qwen2.5:3b') {
    $url = 'http://127.0.0.1:11434/api/generate';
    $data = json_encode(['model'=>$model,'prompt'=>$prompt,'stream'=>false]);
    $opts = ['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\n",'content'=>$data,'timeout'=>180]];
    $result = @file_get_contents($url,false,stream_context_create($opts));
    if (!$result) return null;
    $json = json_decode($result,true);
    return $json['response'] ?? null;

}

/* -------------------- 驗證 -------------------- */
checkLogin();

$role = normalizeRole(trim($_SESSION['role'] ?? ''));
if (!isValidRole($role)) {
    jsonExit(['success'=>false,'error'=>'僅老師或管理員可使用提問功能']);
}

/* -------------------- 取得使用者問題 -------------------- */
$input    = json_decode(file_get_contents('php://input'), true) ?: [];
$question = trim($input['question'] ?? $_POST['question'] ?? '');
if ($question === '') jsonExit(['success'=>false,'error'=>'請輸入問題']);

/* -------------------- 常見寒暄 -------------------- */
if (preg_match('/你好|嗨|哈囉|在嗎|Hello|HELLO|HEllo/u', $question)) {
    jsonExit([
        'success'=>true,
        'ai_used'=>false,
        'main_answer'=>"您好 😊 這裡是招生知識庫系統，請直接輸入招生相關問題，例如學費、時間、科系等。",
        'extra_answers'=>[],
        'ai_answer'=>"",'sources'=>[],'files'=>[]
    ]);
}

/* -------------------- 學校檢查 -------------------- */
$school_pattern = '/康寧大學|國立臺灣大學|台灣科技大學|海洋科技大學|其他學校名稱/u';
preg_match_all($school_pattern,$question,$matches);
$mentioned_school = $matches[0] ?? [];
if (!empty($mentioned_school) && !in_array('康寧大學',$mentioned_school)) {
    jsonExit([
        'success'=>true,
        'ai_used'=>false,
        'main_answer'=>"⚠️ 注意：本系統專屬於康寧大學招生知識庫，僅提供康寧大學的官方資訊。\n您查詢的其他學校資料請自行查詢教育部或該校官方網站。",
        'sources'=>[],'files'=>[]
    ]);
}

/* -------------------- 資料庫檢查 -------------------- */
$conn = getDatabaseConnection();
foreach (['recruitment_knowledge','recruitment_knowledge_files'] as $tbl) {
    $res = $conn->query("SHOW TABLES LIKE '{$tbl}'");
    if (!$res || $res->num_rows === 0) {
        jsonExit(['success'=>false,'error'=>"資料表 {$tbl} 尚未建立"]);
    }
}

/* -------------------- 關鍵字搜尋 -------------------- */
$keywords = sanitizeKeywords($question);

$like_conds = $params = [];
$types = '';
$use_and = false;
if (count($keywords)===2 && mb_strlen($keywords[0],'UTF-8')>=2 && mb_strlen($keywords[1],'UTF-8')===2) {
    $use_and = true;
    $like_conds[] = "((k.question LIKE ? OR k.answer LIKE ?) AND (k.question LIKE ? OR k.answer LIKE ?))";
    $params = ['%'.$keywords[0].'%','%'.$keywords[0].'%','%'.$keywords[1].'%','%'.$keywords[1].'%'];
    $types  = 'ssss';
} else {
    foreach (array_slice($keywords,0,5) as $k) {
        $like_conds[]="(k.question LIKE ? OR k.answer LIKE ?)";
        $params[]= '%'.$k.'%';
        $params[]= '%'.$k.'%';
        $types .= 'ss';
    }
}

$where = "k.is_active = 1";
if (!empty($like_conds)) $where .= $use_and ? " AND ".$like_conds[0] : " AND (".implode(' OR ',$like_conds).")";

$escaped_q = str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$conn->real_escape_string($question));
$order_relevance = "(CASE WHEN (k.question LIKE '%{$escaped_q}%' OR k.answer LIKE '%{$escaped_q}%') THEN 0 ELSE 1 END)";

$sql = "
SELECT k.*, COALESCE(u.name,u.username,'') AS created_by_name,
(SELECT COUNT(*) FROM recruitment_knowledge_files f WHERE f.knowledge_id=k.id) AS file_count
FROM recruitment_knowledge k
LEFT JOIN user u ON u.id=k.created_by
WHERE {$where}
ORDER BY {$order_relevance}, k.updated_at DESC, k.id DESC
LIMIT 5
";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types,...$params);
    $stmt->execute();
    $res = $stmt->get_result();
} else $res = $conn->query($sql);

/* -------------------- 整理結果 -------------------- */
$sources = $files = $context_parts = [];
$kid_list = [];
if ($res) while($row=$res->fetch_assoc()){
    $kid = (int)$row['id'];
    $kid_list[] = $kid;
    $sources[] = [
        'id'=>$kid,
        'question'=>$row['question'] ?? '',
        'source_label'=>$row['source_label'] ?? '',
        'created_at'=>$row['created_at'] ?? '',
        'created_by'=>$row['created_by'] ?? 0,
        'created_by_name'=>$row['created_by_name'] ?? '',
        'file_count'=>$row['file_count'] ?? 0,
        'ai_answer'=>''
    ];
    
    // AI 用 context
    $context_parts[] =
        "問題：" . ($row['question'] ?? '') .
        "\n回答：" . ($row['answer'] ?? '');
    // 顯示用 sources answer
    $sources[count($sources)-1]['answer'] = $row['answer'] ?? '';
}

/* -------------------- 取得附件 -------------------- */
if (!empty($kid_list)) {
    $ids = implode(',',array_map('intval',$kid_list));
    $fres = $conn->query(
        "SELECT f.knowledge_id,f.file_path,f.file_original_name,k.source_label,k.created_at,COALESCE(u.name,u.username,'') AS created_by_name
        FROM recruitment_knowledge_files f
        JOIN recruitment_knowledge k ON k.id=f.knowledge_id
        LEFT JOIN user u ON u.id=k.created_by
        WHERE f.knowledge_id IN ({$ids})"
    );
    if ($fres) while($f=$fres->fetch_assoc()){
        $files[] = [
            'knowledge_id'=>(int)$f['knowledge_id'],
            'file_original_name'=>$f['file_original_name'],
            'file_path'=>$f['file_path'],
            'source_label'=>$f['source_label'] ?? '',
            'created_at'=>$f['created_at'] ?? '',
            'created_by_name'=>$f['created_by_name'] ?? ''
        ];
    }
}

// -------------------- AI 整理回答 --------------------
$context_text = implode("\n\n---\n\n", array_slice($context_parts,0,5));
$prompt = "
你是康寧大學招生 AI 助手。

使用者問題：
{$question}

以下是招生知識庫資料（可能包含部分答案）：
{$context_text}

請完成以下任務：

1. 理解使用者問題
2. 整合資料庫內容
3. 用自然、清楚的方式重新回答
4. 把重點整理成簡單易懂的說明

回答規則：
- 使用繁體中文
- 可以重新整理語句
- 不要逐字複製資料
- 若資料只有部分資訊，可以合理說明
- 回答要像招生老師在說明

回答格式建議：

【重點回答】
（用2~3句說明）

【詳細說明】
（條列或段落）
";

// 不論資料庫是否有資料，都呼叫 AI
$ai_answer = callOllama($prompt);
$ai_used   = $ai_answer ? true : false;

// 整理 main / extra
$main_answer   = $sources[0]['answer'] ?? '';
$extra_answers = [];

// 將資料庫回答整理成物件陣列，方便前端使用
foreach(array_slice($sources,1) as $src){
    $extra_answers[] = [
        'answer' => trim($src['answer'] ?? ''),
        'source_label' => $src['source_label'] ?? '',
        'created_at' => $src['created_at'] ?? ''
    ];
}

jsonExit([
    'success' => true,
    'ai_used' => $ai_used,
    'main_answer' => $main_answer,
    'extra_answers' => $extra_answers,
    'ai_answer' => $ai_answer ?: '目前資料庫尚無相關官方資訊，建議直接聯絡招生中心。',
    'sources' => $sources,
    'files' => $files
]);

/* -------------------- 回傳 JSON -------------------- */
jsonExit([
    'success'=>true,
    'ai_used'=>$ai_used,
    'main_answer'=>$main_answer,
    'extra_answers'=>$extra_answers,
    'ai_answer'=>$ai_answer ?: '',
    'sources'=>$sources,
    'files'=>$files
]);