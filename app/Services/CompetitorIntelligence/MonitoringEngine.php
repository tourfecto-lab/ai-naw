<?php

/**
 * Tourfecto - Competitor Intelligence: Monitoring Engine
 * @version 1.0.0
 *
 * ينسّق دورة مراقبة كاملة لمنافس واحد: يجلب الصفحات العامة المهمة
 * بأمان (SsrfGuard + WebsiteSnapshotFetcher)، يخزّن لقطة، يمرّرها لـ
 * ChangeDetectionService، ثم يولّد تنبيهات عبر AlertService لو حصل
 * تغيير فعلي. يُستخدم من MonitorCompetitorJob (خلفية) ومن "Check Now"
 * اليدوي في الـ Controller (استدعاء واحد متزامن لمنافس واحد فقط - لا
 * يجوز أبدًا استدعاؤه لعشرات المنافسين داخل نفس الـ HTTP request).
 */
class MonitoringEngine
{
    /** الصفحات العامة المدعومة، بنفس ترتيب الأهمية */
    private const MONITORED_PAGES = [
        'homepage' => '',
        'pricing' => 'pricing',
        'products' => 'products',
        'services' => 'services',
        'offers' => 'offers',
        'blog' => 'blog',
        'contact' => 'contact',
    ];

    /** @var WebsiteSnapshotFetcher */
    private $fetcher;
    /** @var ChangeDetectionService */
    private $detector;
    /** @var AlertService */
    private $alerts;
    /** @var SitemapMonitor */
    private $sitemapMonitor;
    /** @var ProductPriceTrackerService */
    private $priceTracker;

    public function __construct(
        ?WebsiteSnapshotFetcher $fetcher = null,
        ?ChangeDetectionService $detector = null,
        ?AlertService $alerts = null,
        ?SitemapMonitor $sitemapMonitor = null,
        ?ProductPriceTrackerService $priceTracker = null
    ) {
        $this->fetcher = $fetcher ?? new WebsiteSnapshotFetcher();
        $this->detector = $detector ?? new ChangeDetectionService();
        $this->alerts = $alerts ?? new AlertService();
        $this->sitemapMonitor = $sitemapMonitor ?? new SitemapMonitor();
        $this->priceTracker = $priceTracker ?? new ProductPriceTrackerService();
    }

    /**
     * @return array{competitor_id:int, pages_checked:int, changes_detected:int, failures:int, results:array}
     */
    public function monitor(Competitor $competitor): array
    {
        $baseUrl = $this->resolveBaseUrl($competitor);
        $results = [];
        $changesDetected = 0;
        $failures = 0;

        if ($baseUrl === null) {
            $competitor->setAttribute('last_monitoring_error', 'no_valid_website_url');
            $competitor->save();
            return ['competitor_id' => (int) $competitor->getAttribute('id'), 'pages_checked' => 0, 'changes_detected' => 0, 'failures' => 1, 'results' => []];
        }

        // Rate limiting بسيط: ننام جزء من الثانية بين طلبات نفس المنافس
        // عشان محدش يعتبرنا Flooding (طلب 7 صفحات كحد أقصى لكل دورة أصلًا).
        foreach (self::MONITORED_PAGES as $pageType => $path) {
            $url = $path === '' ? $baseUrl : (SsrfGuard::buildSubPageUrl($baseUrl, $path) ?? $baseUrl);

            $fetched = $this->fetcher->fetch($url);

            $snapshot = new CiSnapshot([
                'competitor_id' => (int) $competitor->getAttribute('id'),
                'page_type' => $pageType,
                'url' => $url,
                'http_status' => $fetched['http_status'],
                'content_hash' => $fetched['content_hash'],
                'title' => $fetched['title'],
                'meta_description' => $fetched['meta_description'],
                'normalized_excerpt' => $fetched['normalized_excerpt'],
                'structured_data_hash' => $fetched['structured_data_hash'],
                'tech_signals' => !empty($fetched['tech_signals']) ? json_encode($fetched['tech_signals'], JSON_UNESCAPED_UNICODE) : null,
                'fetch_status' => $fetched['success'] ? 'ok' : 'failed',
                'fetch_error' => $fetched['error'],
            ]);
            $snapshot->save();

            if (!$fetched['success']) {
                $failures++;
                $results[$pageType] = ['status' => 'failed', 'error' => $fetched['error']];
                usleep(150000);
                continue;
            }

            $change = $this->detector->detectAndRecord($competitor, $pageType, $snapshot);

            // G7: تتبع أسعار المنتجات/SKUs بجدولة منتظمة - نستخرج كل
            // الأسعار الواضحة من لقطات صفحات التسعير/المنتجات/العروض
            // ونخزنها بسجل زمني (إضافة غير هدّامة فوق Change Detection).
            if (in_array($pageType, ['pricing', 'products', 'offers'], true)) {
                $this->priceTracker->trackFromSnapshot($snapshot);
            }

            if ($change !== null) {
                $changesDetected++;
                $this->alerts->notifyChange($competitor, $change);
                $results[$pageType] = ['status' => 'changed', 'change_id' => (int) $change->getAttribute('id'), 'severity' => $change->getAttribute('severity')];
            } else {
                $results[$pageType] = ['status' => 'ok_no_change'];
            }

            // احترام حدود الموقع المستهدف - لا Flooding بين صفحة وأخرى لنفس المنافس
            usleep(150000);
        }

        // اكتشاف صفحات جديدة/محذوفة فعليًا عبر sitemap.xml (بند "New Pages /
        // Removed Pages") - مصدر إضافي منفصل عن الـ 7 صفحات الثابتة فوق.
        // فشله (sitemap مش موجود أصلاً - شائع) مش بيُحسَب كـ failure عام
        // للدورة، لأنه مصدر تكميلي اختياري مش أساسي.
        $sitemapChange = $this->sitemapMonitor->checkAndRecord($competitor);
        if ($sitemapChange !== null) {
            $changesDetected++;
            $this->alerts->notifyChange($competitor, $sitemapChange);
            $results['sitemap'] = ['status' => 'changed', 'change_id' => (int) $sitemapChange->getAttribute('id'), 'severity' => $sitemapChange->getAttribute('severity')];
        }

        if ($failures < count(self::MONITORED_PAGES)) {
            // على الأقل صفحة واحدة نجحت = الدورة اعتُبرت ناجحة إجمالًا
            $competitor->setAttribute('last_monitored_at', date('Y-m-d H:i:s'));
            $competitor->setAttribute('last_monitoring_error', $failures > 0 ? "partial_failure:{$failures}/" . count(self::MONITORED_PAGES) . '_pages' : null);
        } else {
            $competitor->setAttribute('last_monitoring_error', 'all_pages_failed');
        }
        $competitor->save();

        ActivityLog::record('competitor_intelligence', 'monitoring.cycle_completed', [
            'user_id' => (int) $competitor->getAttribute('user_id'),
            'subject_type' => 'competitors',
            'subject_id' => (int) $competitor->getAttribute('id'),
            'meta' => ['changes_detected' => $changesDetected, 'failures' => $failures],
        ]);

        return [
            'competitor_id' => (int) $competitor->getAttribute('id'),
            'pages_checked' => count(self::MONITORED_PAGES),
            'changes_detected' => $changesDetected,
            'failures' => $failures,
            'results' => $results,
        ];
    }

    private function resolveBaseUrl(Competitor $competitor): ?string
    {
        return CompetitorDomain::normalizeSafe($competitor->getAttribute('competitor_domain'));
    }
}
