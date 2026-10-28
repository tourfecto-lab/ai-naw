<?php

/** Shared read-only presentation for potential keyword overlap, including legacy tasks. */
final class SeoKeywordConflictReview
{
    public static function instructions(array $conflict, bool $arabic, ?string $checkedAt = null): string
    {
        $measured = ($conflict['source'] ?? '') === 'search_console';
        $text = $arabic
            ? 'تحتاج الصفحات إلى مراجعة نية البحث. ظهور أكثر من صفحة أو استهداف كلمة مشتركة لا يثبت وحده وجود ضرر. لا تدمج أو تحذف أو تحوّل صفحات الرئيسية والتواصل والمدونة والخدمات المختلفة لمجرد اشتراكها في كلمة. راجع الاستعلام والصفحة والفترة وتغير النقرات والترتيب قبل اختيار تعديل مناسب.'
            : 'Review search intent first. Multiple pages appearing for a query or sharing a target keyword does not by itself prove harm. Do not merge, delete or redirect home, contact, blog or distinct service pages merely because they share a keyword. Compare query/page data, dates, clicks and ranking changes before choosing an edit.';
        $text .= "\n" . ($arabic ? 'مصدر الإشارة: ' : 'Signal source: ') . ($measured ? 'Google Search Console' : ($arabic ? 'استهداف كلمة مشتركة أو مصدر غير مؤكد؛ ليس دليل ترتيب' : 'shared keyword target or unconfirmed source; not ranking evidence'));
        if ($checkedAt) $text .= "\n" . ($arabic ? 'تاريخ البيانات: ' : 'Data recorded: ') . $checkedAt;
        foreach ($conflict['pages'] ?? [] as $page) {
            if (!is_array($page) || !is_string($page['url'] ?? null)) continue;
            $text .= "\n" . $page['url'];
            if ($measured) {
                foreach (['clicks', 'impressions', 'position'] as $key) {
                    if (isset($page[$key]) && is_numeric($page[$key])) $text .= ' · ' . $key . ': ' . $page[$key];
                }
            }
        }
        return $text;
    }

    public static function forTask(array $task, array $snapshot, bool $arabic): array
    {
        if (($task['field_name'] ?? '') !== 'keyword_conflict') return $task;
        $title = (string) ($task['title'] ?? '');
        $query = trim(substr($title, (strpos($title, ':') ?: -1) + 1));
        $conflict = ['query' => $query, 'source' => 'unknown', 'pages' => []];
        foreach ($snapshot['keyword_map']['conflicts'] ?? [] as $candidate) {
            if (is_array($candidate) && mb_strtolower(trim((string) ($candidate['query'] ?? ''))) === mb_strtolower($query)) {
                $conflict = $candidate;
                break;
            }
        }
        if (!$conflict['pages']) {
            preg_match_all('~https?://[^\s<>]+~u', (string) ($task['instructions'] ?? ''), $urls);
            $conflict['pages'] = array_map(static fn($url) => ['url' => $url], array_values(array_unique($urls[0])));
        }
        $task['title'] = ($arabic ? 'راجع تداخل استهداف الكلمات: ' : 'Review overlapping keyword targets: ') . $query;
        $task['instructions'] = self::instructions($conflict, $arabic, $snapshot['keyword_map']['built_at'] ?? null);
        $task['conflict_review'] = ['state' => 'needs_intent_review', 'source' => $conflict['source'], 'automatic_merge' => false];
        return $task;
    }
}
