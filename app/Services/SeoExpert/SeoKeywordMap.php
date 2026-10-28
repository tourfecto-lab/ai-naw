<?php
/**
 * خريطة الكلمات: كل صفحة ليها كلمة أساسية واحدة، وكشف الصفحات اللي بتنافس بعض على نفس البحث.
 * المصدر الأول بيانات Search Console الحقيقية، وبعدها كلمات البحث المقاسة (DataForSEO) الأقرب لمحتوى الصفحة.
 */
final class SeoKeywordMap
{
    private const STOP = ['the','and','for','with','from','your','our','best','tour','tours','trip','trips','day','private','في','من','الى','إلى','على','عن','رحلة','رحلات'];

    /**
     * @param array $pages صفحات الدورة (url, page_path, title, h1)
     * @param array $gscRows صفوف Search Console بالأبعاد query + page
     * @param array $research كلمات البحث المقاسة (keyword, search_volume, target_page)
     */
    public static function build(array $pages, array $gscRows, array $research): array
    {
        $byUrl = [];
        foreach ($pages as $p) {
            if (!empty($p['url'])) {
                $byUrl[self::key((string) $p['url'])] = $p;
            }
        }
        // 1) Search Console: أكتر بحث جاب ظهور لكل صفحة، وكل بحث بيظهر فيه أكتر من صفحة
        $best = [];
        $byQuery = [];
        foreach ($gscRows as $row) {
            $query = trim((string) ($row['query'] ?? ''));
            $url = self::key((string) ($row['page'] ?? ''));
            $impressions = (int) ($row['impressions'] ?? 0);
            if ($query === '' || !isset($byUrl[$url]) || $impressions <= 0) {
                continue;
            }
            $byQuery[mb_strtolower($query)][$url] = ['url' => $byUrl[$url]['url'], 'query' => $query, 'impressions' => $impressions, 'position' => (float) ($row['position'] ?? 0)];
        }
        // كل صفحة بتفضّل بحث هي الأقوى فيه؛ البحث اللي صفحة تانية أقوى فيه بيتاخد بس لو مفيش غيره
        foreach ($byQuery as $urls) {
            $owner = array_search(max(array_column($urls, 'impressions')), array_column($urls, 'impressions', null), true);
            $ownerUrl = array_keys($urls)[$owner];
            foreach ($urls as $url => $row) {
                $owned = $url === $ownerUrl;
                $current = $best[$url] ?? null;
                if ($current === null || ($owned && !$current['owned']) || ($owned === $current['owned'] && $row['impressions'] > $current['impressions'])) {
                    $best[$url] = ['keyword' => $row['query'], 'source' => 'search_console', 'impressions' => $row['impressions'], 'position' => $row['position'], 'owned' => $owned];
                }
            }
        }
        // 2) الصفحات اللي مالهاش بيانات بحث: أقرب كلمة مقاسة لعنوانها ومسارها (الأعلى في حجم البحث عند التعادل)
        $map = [];
        foreach ($byUrl as $url => $p) {
            if (isset($best[$url])) {
                $map[$url] = ['url' => $p['url']] + array_diff_key($best[$url], ['owned' => true]);
                continue;
            }
            $pageWords = self::words(($p['h1'] ?? '') . ' ' . ($p['title'] ?? '') . ' ' . str_replace(['-', '/', '_'], ' ', (string) ($p['page_path'] ?? '')));
            $pick = null;
            $pickScore = 0.0;
            foreach ($research as $k) {
                $keyword = trim((string) ($k['keyword'] ?? ''));
                if ($keyword === '') {
                    continue;
                }
                $kw = self::words($keyword);
                if (!$kw) {
                    continue;
                }
                $overlap = count(array_intersect($kw, $pageWords)) / count($kw);
                if ($overlap < 0.5) {
                    continue;
                }
                $score = $overlap + log(1 + (int) ($k['search_volume'] ?? 0)) / 100;
                if (!empty($k['target_page']) && self::key((string) $k['target_page']) === $url) {
                    $score += 1;
                }
                if ($score > $pickScore) {
                    $pick = $k;
                    $pickScore = $score;
                }
            }
            if ($pick) {
                $map[$url] = ['url' => $p['url'], 'keyword' => trim((string) $pick['keyword']), 'source' => 'keyword_research', 'volume' => isset($pick['search_volume']) ? (int) $pick['search_volume'] : null];
            }
        }
        // 3) تنافس: نفس البحث بيجيب أكتر من صفحة (كل واحدة 20% من الظهور على الأقل)، أو نفس الكلمة متحددة لصفحتين
        $conflicts = [];
        foreach ($byQuery as $query => $urls) {
            if (count($urls) < 2) {
                continue;
            }
            $total = array_sum(array_column($urls, 'impressions'));
            $strong = array_values(array_map(static fn($u) => array_diff_key($u, ['query' => true]), array_filter($urls, static fn($u) => $total > 0 && $u['impressions'] / $total >= 0.2)));
            if (count($strong) >= 2 && $total >= 10) {
                usort($strong, static fn($a, $b) => $b['impressions'] <=> $a['impressions']);
                $conflicts[] = ['query' => $query, 'source' => 'search_console', 'pages' => $strong];
            }
        }
        $assigned = [];
        foreach ($map as $row) {
            $assigned[mb_strtolower($row['keyword'])][] = $row;
        }
        foreach ($assigned as $keyword => $rows) {
            if (count($rows) >= 2 && !isset($byQuery[$keyword])) {
                $conflicts[] = ['query' => $keyword, 'source' => 'same_target_keyword', 'pages' => array_map(static fn($r) => ['url' => $r['url']], $rows)];
            }
        }
        return ['pages' => array_values($map), 'conflicts' => array_slice($conflicts, 0, 20), 'built_at' => gmdate('c')];
    }

    private static function key(string $url): string
    {
        return strtolower(rtrim(preg_replace('/[?#].*$/', '', $url), '/'));
    }

    private static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($text), $m);
        return array_values(array_diff(array_unique($m[0]), self::STOP));
    }
}
