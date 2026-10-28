<?php

/**
 * Tourfecto - Competitor Intelligence: Keyword Ranking Service (G1)
 * @version 1.0.0
 *
 * تتبع ترتيب الكلمات المفتاحية (SERP) لكل منافس عبر الزمن.
 *
 * - recordRanking(): تسجيل قياس يدوي (رقم ترتيب/رابط/مصدر) ببيانات
 *   حقيقية - أساس السجل الزمني.
 * - listRankings(): آخر قياس لكل كلمة + أفضل ترتيب + الاتجاه.
 * - history(): السلسلة الزمنية الكاملة لكلمة واحدة (للرسم).
 * - runScheduledCheck(): يكلّف مصدر SERP خارجي (KeywordRankingSourceInterface)
 *   بفحص الكلمات وجمع النتائج. لو مفيش مصدر مهيأ يرجّع available=false
 *   بسبب واضح - لا يختلق ترتيبات أبدًا (NO FAKE DATA، نفس نمط
 *   NullDiscoverySource).
 *
 * الترتيب الصاعد = أفضل (المركز 1 هو الأول في نتائج البحث).
 */
class KeywordRankingService
{
    /** @var KeywordRankingSourceInterface */
    private $source;

    public function __construct(?KeywordRankingSourceInterface $source = null)
    {
        $this->source = $source ?? new DataForSeoRankingSource();
    }

    /**
     * تسجيل قياس ترتيب لكلمة مفتاحية (يدوي أو من تكامل).
     *
     * @param int|null $position الترتيب (1-100)؛ null = خارج أول 100 نتيجة
     * @return array{success:bool, error?:string, ranking?:array}
     */
    public function recordRanking(
        int $competitorId,
        string $keyword,
        ?int $position = null,
        ?string $url = null,
        string $source = 'manual',
        ?string $checkedAt = null
    ): array {
        $keyword = trim($keyword);
        if ($keyword === '' || mb_strlen($keyword) > 255) {
            return ['success' => false, 'error' => 'invalid_keyword'];
        }
        if ($position !== null && ($position < 1 || $position > 100)) {
            return ['success' => false, 'error' => 'invalid_position'];
        }

        $ranking = new CiKeywordRanking([
            'competitor_id' => $competitorId,
            'keyword' => $keyword,
            'position' => $position,
            'url' => $url ?: null,
            'source' => $source,
            'checked_at' => $checkedAt ?: date('Y-m-d H:i:s'),
        ]);
        $ranking->save();

        return ['success' => true, 'ranking' => $ranking->toArray()];
    }

    /**
     * آخر قياس لكل كلمة مفتاحية تابعة للمنافس + أفضل ترتيب + اتجاه
     * (مقارنة بآخر قياسين). الترتيب الأفضل = أصغر رقم (1 أولًا).
     *
     * @return array<int, array{keyword:string, position:?int, best_position:?int,
     *                           trend:int, url:?string, source:string, checked_at:string}>
     */
    public function listRankings(int $competitorId, int $limit = 200): array
    {
        $rows = Database::getInstance()->query(
            "SELECT keyword, position, url, source, checked_at
             FROM ci_keyword_rankings
             WHERE competitor_id = ?
             ORDER BY checked_at ASC, id ASC",
            [$competitorId]
        );

        $latest = [];
        $best = [];
        foreach ($rows as $row) {
            $kw = (string) $row['keyword'];
            $pos = $row['position'] !== null ? (int) $row['position'] : null;

            if (!isset($latest[$kw])) {
                $latest[$kw] = $row;
            } else {
                $latest[$kw] = $row; // آخر صف (الترتيب صاعد زمنيًا)
            }

            if ($pos !== null) {
                if (!isset($best[$kw]) || $pos < (int) $best[$kw]) {
                    $best[$kw] = $pos;
                }
            }
        }

        // الاتجاه: الفرق بين آخر قياسين (قيمة سالبة = تحسّن في الترتيب)
        $trend = [];
        $lastTwo = [];
        foreach ($rows as $row) {
            $kw = (string) $row['keyword'];
            if ($row['position'] === null) {
                continue;
            }
            $lastTwo[$kw][] = (int) $row['position'];
            if (count($lastTwo[$kw]) > 2) {
                array_shift($lastTwo[$kw]);
            }
        }
        foreach ($lastTwo as $kw => $positions) {
            if (count($positions) === 2) {
                $trend[$kw] = $positions[1] - $positions[0];
            } else {
                $trend[$kw] = 0;
            }
        }

        $out = [];
        foreach ($latest as $kw => $row) {
            $out[] = [
                'keyword' => $kw,
                'position' => $row['position'] !== null ? (int) $row['position'] : null,
                'best_position' => $best[$kw] ?? null,
                'trend' => $trend[$kw] ?? 0,
                'url' => $row['url'],
                'source' => $row['source'],
                'checked_at' => $row['checked_at'],
            ];
        }

        // ترتيب العرض: الكلمات اللي ليها ترتيب حالي أولًا (أفضل ترتيب)،
        // ثم اللي بلا ترتيب أبجديًا.
        usort($out, function ($a, $b) {
            $aPos = $a['position'] ?? PHP_INT_MAX;
            $bPos = $b['position'] ?? PHP_INT_MAX;
            if ($aPos !== $bPos) {
                return $aPos <=> $bPos;
            }
            return strcasecmp($a['keyword'], $b['keyword']);
        });

        return array_slice($out, 0, $limit);
    }

    /**
     * السلسلة الزمنية الكاملة لقياسات كلمة واحدة (للرسم البياني).
     *
     * @return array<int, array{position:?int, url:?string, source:string, checked_at:string}>
     */
    public function history(int $competitorId, string $keyword, int $limit = 200): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }
        return array_reverse(Database::getInstance()->query(
            "SELECT position, url, source, checked_at
             FROM ci_keyword_rankings
             WHERE competitor_id = ? AND keyword = ?
             ORDER BY checked_at DESC, id DESC
             LIMIT ?",
            [$competitorId, $keyword, max(1,min(1000,$limit))]
        ));
    }

    /**
     * فحص مجدول عبر مصدر SERP مهيأ (إن وجد). لو المصدر غير متاح يرجّع
     * available=false بسبب واضح ولا يسجل أي شيء - لا ترتيبات وهمية.
     *
     * @param string[] $keywords الكلمات المطلوب فحصها
     * @return array{available:bool, reason:?string, recorded:int, results:array}
     */
    public function runScheduledCheck(int $competitorId, string $domain, array $keywords): array
    {
        $keywords = array_values(array_filter(array_map('trim', $keywords), static fn ($k) => $k !== ''));
        if (empty($keywords)) {
            return ['available' => false, 'reason' => 'no_keywords_to_check', 'recorded' => 0, 'results' => []];
        }
        if (!$this->source->isConfigured()) {
            return [
                'available' => false,
                'reason' => 'no_keyword_ranking_source_configured',
                'recorded' => 0,
                'results' => [],
            ];
        }

        $source=$this->source;
        if ($source instanceof DataForSeoRankingSource) {
            $db=Database::getInstance();
            $c=$db->query('SELECT website_id,user_id FROM competitors WHERE id=?',[$competitorId])[0]??null;
            if (!$c) return ['available'=>false,'reason'=>'competitor_not_found','recorded'=>0,'results'=>[]];
            $market=(new SeoMarketService($db))->resolve((int)$c['website_id'],(int)$c['user_id']);
            $source=$source->forMarket($market,(int)$c['website_id']);
        }
        $result = RankTrackingService::batches($source, $domain, $keywords);
        if (!($result['available'] ?? false)) {
            return [
                'available' => false,
                'reason' => $result['reason'] ?? 'keyword_ranking_source_unavailable',
                'recorded' => 0,
                'results' => [],
            ];
        }

        $recorded = 0;
        $results = [];
        foreach (($result['rankings'] ?? []) as $r) {
            $position = isset($r['position']) ? (int) $r['position'] : null;
            if ($position !== null && ($position < 1 || $position > 100)) {
                continue;
            }
            $saved = $this->recordRanking(
                $competitorId,
                (string) ($r['keyword'] ?? ''),
                $position,
                isset($r['url']) ? (string) $r['url'] : null,
                'integration:' . $this->source->sourceName()
            );
            if ($saved['success']) {
                $recorded++;
                $results[] = $saved['ranking'];
            }
        }

        return ['available' => true, 'reason' => null, 'recorded' => $recorded, 'results' => $results];
    }

    /** @return KeywordRankingSourceInterface */
    public function source(): object
    {
        return $this->source;
    }
}
