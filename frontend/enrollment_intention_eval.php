<?php
// 共用：依聯絡紀錄 notes 自動評估意願度（供 submit_contact_log 與 backfill 使用）
// 權重由高到低：第一關 低意願(一槍斃命) → 第二關 高意願(正面表態) → 第三關 其餘皆中意願
if (!function_exists('evaluateIntentionLevelFromNotes')) {
    /**
     * 依聯絡紀錄選項自動評估意願度：high / medium / low
     * @param string|null $notes 聯絡紀錄的 notes（格式：【A、B、C】\n備註）
     * @return string|null 'high'|'medium'|'low' 或 null（無法解析時）
     */
    function evaluateIntentionLevelFromNotes($notes) {
        $notes = trim((string)$notes);
        if ($notes === '') return null;
        if (!preg_match('/[【\[]([^】\]]*)[】\]]/u', $notes, $m)) return null;
        $block = $m[1];
        $tags = array_map('trim', preg_split('/[、,，\s]+/u', $block, -1, PREG_SPLIT_NO_EMPTY));
        $tags = array_values(array_unique($tags));

        // 第一關：低意願（一槍斃命）— 勾選任一個即為低意願
        $strong_refusal = ['已決定他校', '志趣不合/沒興趣', '學生無意願', '家長反對'];

        // 第二關：高意願（在未觸發低意願下，勾選任一個即為高意願）
        $positive = ['學生有意願', '家長支持'];

        $has_refusal = count(array_intersect($tags, $strong_refusal)) > 0;
        $has_positive = count(array_intersect($tags, $positive)) > 0;

        if ($has_refusal) return 'low';
        if ($has_positive) return 'high';
        return 'medium'; // 第三關：其餘皆中意願（考慮中、僅抗性、僅詢問、僅後續動作等）
    }
}
