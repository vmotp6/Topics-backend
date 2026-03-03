<?php
// 共用：依聯絡紀錄 notes 自動評估意願度（供 submit_contact_log 與 backfill 使用）
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

        $strong_refusal = ['學生無意願', '家長反對', '已決定他校'];
        $positive = ['學生有意願', '家長支持'];
        $neutral_only = ['詢問獎學金/補助', '詢問校車/住宿', '詢問升學/就業'];

        $has_refusal = count(array_intersect($tags, $strong_refusal)) > 0;
        $has_positive = count(array_intersect($tags, $positive)) > 0;
        $has_considering = in_array('考慮中', $tags, true);
        $has_positive_label = in_array('學生有意願', $tags, true) || in_array('家長支持', $tags, true);
        $has_negative_label = in_array('學生無意願', $tags, true) || in_array('家長反對', $tags, true);
        $contradictory = $has_positive_label && $has_negative_label;

        $only_neutral_or_other = true;
        $other_labels = array_merge($positive, $strong_refusal, ['考慮中']);
        foreach ($tags as $t) {
            if (in_array($t, $other_labels, true)) {
                $only_neutral_or_other = false;
                break;
            }
        }
        $has_any_neutral = count(array_intersect($tags, $neutral_only)) > 0;
        $no_clear_stance = $only_neutral_or_other && (count($tags) === 0 || $has_any_neutral || count(array_intersect($tags, ['學生接聽', '家長接聽', '已加LINE', '邀請參訪', '寄送資料', '需再次聯絡'])) > 0);

        if ($has_refusal) return 'low';
        if ($has_positive && !$has_refusal) return 'high';
        if ($has_considering || $contradictory || $no_clear_stance) return 'medium';
        return 'medium';
    }
}
