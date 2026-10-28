<?php

/**
 * Tourfecto - Competitor Intelligence: Sitemap Monitor
 * @version 1.0.0
 *
 * تنفيذ حقيقي وآمن لبند "New Pages / Removed Pages" من الأمر الأصلي:
 * بدل عمل crawl عشوائي وغير آمن للموقع (خطر SSRF/Flooding)، بيقرأ
 * sitemap.xml العام للمنافس (لو موجود - ملف معلن ومخصص أصلاً للفهرسة
 * العامة، مسموح الوصول له دايمًا) ويقارن قائمة الروابط بين آخر لقطتين.
 * أي رابط جديد = new_page، أي رابط اختفى = removed_page - كلها أدلة
 * حقيقية (روابط فعلية) مش تخمين.
 */
class SitemapMonitor
{
    private const MAX_URLS_STORED = 500; // سقف معقول - بعض المواقع عندها آلاف الروابط
    private const TIMEOUT_SECONDS = 10;

    public function checkAndRecord(Competitor $competitor): ?CiChange
    {
        $baseUrl = $this->resolveBaseUrl($competitor);
        if ($baseUrl === null) {
            return null;
        }

        $sitemapUrl = SsrfGuard::buildSubPageUrl($baseUrl, 'sitemap.xml') ?? ($baseUrl . '/sitemap.xml');
        $check = SsrfGuard::validateUrl($sitemapUrl);
        if (!$check['safe']) {
            return null;
        }

        $urls = $this->fetchSitemapUrls($sitemapUrl);

        $competitorId = (int) $competitor->getAttribute('id');
        // $urls = null لما مفيش sitemap (أغلب المواقع) - implode(null) كان بيوقّع المراقبة كلها
        $newHash = hash('sha256', implode('|', $urls ?? []));

        $snapshot = new CiSnapshot([
            'competitor_id' => $competitorId,
            'page_type' => 'sitemap',
            'url' => $sitemapUrl,
            'http_status' => $urls !== null ? 200 : null,
            'content_hash' => $newHash,
            'normalized_excerpt' => $urls !== null ? implode("\n", array_slice($urls, 0, self::MAX_URLS_STORED)) : null,
            'fetch_status' => $urls !== null ? 'ok' : 'failed',
            'fetch_error' => $urls === null ? 'sitemap_unavailable_or_unparseable' : null,
        ]);
        $snapshot->save();

        if ($urls === null) {
            return null; // sitemap مش موجود/متاح - مش خطأ يستاهل تنبيه، غالبية المواقع مفيهاش sitemap.xml أصلاً
        }

        $previous = $this->getPreviousSitemapSnapshot($competitorId, (int) $snapshot->getAttribute('id'));
        if ($previous === null || $previous->getAttribute('fetch_status') === 'failed') {
            return null; // أول لقطة أو مفيش أساس مقارنة موثوق
        }

        $previousUrls = array_filter(explode("\n", (string) $previous->getAttribute('normalized_excerpt')));
        $addedUrls = array_diff($urls, $previousUrls);
        $removedUrls = array_diff($previousUrls, $urls);

        if (empty($addedUrls) && empty($removedUrls)) {
            return null; // Nothing Changed
        }

        // إشارة توظيف (Job Postings) - مصدر استخبارات استراتيجي بتتبعه
        // منصات Crayon/Kompyte: ظهور/اختفاء صفحة careers/jobs عند المنافس
        // بيقول كتير عن توسعهم أو تقلصهم. بتترفع الخطورة لـ high فورًا.
        $careersAdded = array_values(array_filter($addedUrls, [self::class, 'isCareerUrl']));
        $careersRemoved = array_values(array_filter($removedUrls, [self::class, 'isCareerUrl']));
        $hasCareersSignal = !empty($careersAdded) || !empty($careersRemoved);

        $changeType = !empty($addedUrls) && empty($removedUrls) ? 'new_page' : (empty($addedUrls) ? 'removed_page' : 'new_page');
        $severity = $hasCareersSignal ? 'high' : ((count($addedUrls) + count($removedUrls)) >= 5 ? 'medium' : 'low');
        $pageType = $hasCareersSignal ? 'careers' : 'sitemap';

        $visibleNewValue = array_merge(
            array_map(fn ($u) => "[careers] {$u}", $careersAdded),
            array_diff($addedUrls, $careersAdded),
            array_map(fn ($u) => "[careers-removed] {$u}", $careersRemoved),
            array_map(fn ($u) => "[removed] {$u}", array_diff($removedUrls, $careersRemoved))
        );

        $change = new CiChange([
            'competitor_id' => $competitorId,
            'user_id' => (int) $competitor->getAttribute('user_id'),
            'page_type' => $pageType,
            'change_type' => $changeType,
            'severity' => $severity,
            'previous_value' => 'Sitemap had ' . count($previousUrls) . ' URLs',
            'new_value' => implode("\n", array_slice($visibleNewValue, 0, 30)),
            'source_url' => $sitemapUrl,
            'confidence' => 'high', // مقارنة روابط فعلية، مش استنتاج
            'snapshot_before_id' => (int) $previous->getAttribute('id'),
            'snapshot_after_id' => (int) $snapshot->getAttribute('id'),
        ]);
        $change->save();

        $competitor->setAttribute('last_change_at', date('Y-m-d H:i:s'));
        $competitor->save();

        return $change;
    }

    /**
     * هل الرابط صفحة توظيف؟ Heuristic على اسم الـ host/المسار
     * (careers/jobs/join/hiring/vacancies) - نفس المنطق اللي بتستخدمه
     * منصات تتبع Job Postings. الكلمة لازم تكون مقطعًا كاملًا (حدود:
     * بداية/نهاية أو بعد / أو . للسوب دومين)، مش جزءًا من كلمة مركبة
     * (joinery أو jobs-in-seo مثلًا). عامة وثابتة عشان قابلة للاختبار offline.
     */
    public static function isCareerUrl(string $url): bool
    {
        $haystack = (string) (parse_url($url, PHP_URL_HOST) ?? '') . (string) (parse_url($url, PHP_URL_PATH) ?? '');

        if (preg_match('#(?:^|[/.])(?:careers?|jobs?|hiring|vacancies)(?:$|[/.])#i', $haystack)) {
            return true;
        }
        if (preg_match('#join[\-_]?us#i', $haystack)) {
            return true; // "join-us" / "joinus" شائعة جدًا
        }
        if (preg_match('#(?:^|[/.])join(?:$|[/.])#i', $haystack)) {
            return true;
        }
        return false;
    }

    /**
     * @return string[]|null قائمة الروابط، أو null لو sitemap مش متاح/غير صالح
     */
    private function fetchSitemapUrls(string $sitemapUrl): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $sitemapUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'TourfectoCompetitorIntelligenceBot/1.0 (+https://tourfecto.com/bot)',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_errno($ch) !== 0;
        curl_close($ch);

        if ($error || $status !== 200 || empty($body)) {
            return null;
        }

        // sitemap index (بيشاور على sitemaps فرعية) - بناخد أول 3 فقط لمنع Flooding
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        if ($xml === false) {
            return null;
        }

        $urls = [];
        if (isset($xml->sitemap)) {
            $count = 0;
            foreach ($xml->sitemap as $sub) {
                if ($count++ >= 3) {
                    break;
                }
                $subUrl = (string) $sub->loc;
                if ($subUrl !== '' && SsrfGuard::isSafe($subUrl)) {
                    $subUrls = $this->fetchSitemapUrls($subUrl);
                    if ($subUrls !== null) {
                        $urls = array_merge($urls, $subUrls);
                    }
                }
            }
        } elseif (isset($xml->url)) {
            foreach ($xml->url as $u) {
                $loc = (string) $u->loc;
                if ($loc !== '') {
                    $urls[] = $loc;
                }
            }
        }

        return array_slice(array_unique($urls), 0, self::MAX_URLS_STORED);
    }

    private function getPreviousSitemapSnapshot(int $competitorId, int $excludeId): ?CiSnapshot
    {
        $db = Database::getInstance();
        $rows = $db->query(
            "SELECT * FROM ci_snapshots WHERE competitor_id = ? AND page_type = 'sitemap' AND id != ? ORDER BY captured_at DESC, id DESC LIMIT 1",
            [$competitorId, $excludeId]
        );
        return !empty($rows) ? new CiSnapshot($rows[0]) : null;
    }

    private function resolveBaseUrl(Competitor $competitor): ?string
    {
        return CompetitorDomain::normalizeSafe($competitor->getAttribute('competitor_domain'));
    }
}
