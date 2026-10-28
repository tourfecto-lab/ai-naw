<?php

/**
 * Tourfecto - SEO Insights Controller (M6: G4/G6/G7)
 * @version 1.0.0
 *
 * نقاط API جديدة تغلق فجوات COMPETITIVE_ANALYSIS_SeoAutoSeo.md:
 *   G7 Rank Tracking (تتبع ترتيب يومي للكلمات المفتاحية + تاريخ)
 *   G6 تقرير بصري (بيانات Charts) + جدولة تقارير بريدية
 *   G4 بيانات كلمات مفتاحية خارجية (Keyword Research source status/enrich)
 *
 * كل الـ endpoints خلف AuthMiddleware + عزل تينانت صارم عبر
 * ownsWebsite() + user_id في كل الاستعلامات.
 */
class SeoInsightsController extends Controller
{
    /** GET /api/seo/rank-tracking?website_id=X - نظرة عامة على ترتيب الكلمات (G7) */
    public function rankTracking(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id');
        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $overview = (new RankTrackingService())->trackingOverview($this->db, $websiteId, (int) $this->user['id'], (int)$this->get('market_id',0));
        return $this->success($overview);
    }

    /** POST /api/seo/rank-tracking/check  { website_id } - فحص ترتيب فوري (G7) */
    public function rankTrackingCheck(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id');
        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $limit = CiRateLimiter::hit('seo_rank_tracking_check', 'user:' . (int) $this->user['id']);
        if (!$limit['allowed']) {
            return $this->error('تم تجاوز حد الفحوصات - ارجع بعد ' . $limit['retry_after'] . ' ثانية', 429);
        }

        $result = (new RankTrackingService())->checkWebsite($this->db, $websiteId, (int) $this->user['id'], (int)$this->get('market_id',0));
        if (empty($result['available'])) {
            return $this->error($result['error'] ?? 'مصدر الترتيبات غير مهيأ', 422);
        }
        return $this->success($result);
    }

    /** GET /api/seo/rank-tracking/history?website_id=X&keyword=... - سلسلة تاريخية لكلمة (G7) */
    public function rankTrackingHistory(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id');
        $keyword = (string) $this->get('keyword', '');
        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }
        if ($keyword === '') {
            return $this->error('keyword مطلوب', 422);
        }

        $history = (new RankTrackingService())->history($this->db, $websiteId, (int) $this->user['id'], $keyword,60,(int)$this->get('market_id',0));
        return $this->success($history);
    }

    /** GET /api/seo/report/charts?website_id=X - بيانات الرسوم البيانية للتقرير (G6) */
    public function reportCharts(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id');
        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $charts = new SeoChartService($this->db);
        return $this->success([
            'score_trend' => $charts->scoreTrend($websiteId, (int) $this->user['id']),
            'category_scores' => $charts->categoryScores($websiteId, (int) $this->user['id']),
            'gsc_top_pages' => $charts->gscTopPages($websiteId),
            'fixes_applied_trend' => $charts->fixesAppliedTrend($websiteId),
        ]);
    }

    /** GET /api/seo/report/schedules?website_id=X - جداول التقارير البريدية (G6) */
    public function reportSchedules(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id');
        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $schedules = (new SeoScheduledReportService($this->db))->listSchedules($websiteId, (int) $this->user['id']);
        return $this->success(['schedules' => $schedules]);
    }

    /** POST /api/seo/report/schedules  { website_id, frequency, hour, weekday?, recipient_email, is_active? } - إنشاء/تحديث جدول (G6) */
    public function reportScheduleSave(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($error=$this->verifyCsrf()) return $error;
        $websiteId = (int) $this->get('website_id');
        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $scheduleId = (int) $this->get('id', 0);
        $result = (new SeoScheduledReportService($this->db))->saveSchedule(
            $websiteId,
            (int) $this->user['id'],
            [
                'frequency' => (string) $this->get('frequency', ''),
                'hour' => (int) $this->get('hour', 8),
                'weekday' => $this->get('weekday', ''),
                'recipient_email' => (string) $this->get('recipient_email', ''),
                'is_active' => (int) $this->get('is_active', 1),
            ],
            $scheduleId > 0 ? $scheduleId : null
        );

        if (!$result['success']) {
            return $this->error($result['error'] ?? 'تعذّر حفظ الجدول', 422);
        }
        return $this->success(['schedule' => $result['schedule']]);
    }

    /** DELETE /api/seo/report/schedules/{id}?website_id=X - حذف جدول (G6) */
    public function reportScheduleDelete(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id');
        $scheduleId = (int) ($params['id'] ?? 0);
        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }
        if ($scheduleId <= 0) {
            return $this->error('id غير صالح', 422);
        }

        $deleted = (new SeoScheduledReportService($this->db))->deleteSchedule($websiteId, (int) $this->user['id'], $scheduleId);
        if (!$deleted) {
            return $this->error('الجدول غير موجود', 404);
        }
        return $this->success(['deleted' => true]);
    }

    /** GET /api/seo/keyword-research/status - حالة تهيئة مصدر بيانات الكلمات (G4) */
    public function keywordResearchStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success((new KeywordResearchService())->status());
    }

    /** POST /api/seo/keyword-research/enrich  { website_id } - تخصيب tracked_keywords ببيانات خارجية (G4) */
    public function keywordResearchEnrich(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id');
        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $result = (new KeywordResearchService())->enrichTrackedKeywords($this->db, $websiteId, (int) $this->user['id']);
        if (empty($result['available'])) {
            return $this->error($result['reason'] ?? 'مصدر بيانات الكلمات غير مهيأ', 422);
        }
        return $this->success($result);
    }

    private function ownsWebsite(int $websiteId): bool
    {
        $rows = $this->db->query(
            "SELECT id FROM websites WHERE id = ? AND user_id = ? LIMIT 1",
            [$websiteId, $this->user['id']]
        );
        return !empty($rows);
    }
}
