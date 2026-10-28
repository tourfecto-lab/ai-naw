<?php

/**
 * Tourfecto - Partner Controller
 * نقطة انطلاق Partner API: نقاط وصول للقراءة فقط لشركاء خارجيين
 * (زي منصات حجز سياحية تانية) عبر PartnerAuthMiddleware، مستقلة تمامًا
 * عن جلسة/توكن المستخدم العادي.
 *
 * هذا الملف مقصود إنه يبقى مثال أولي (starter) يوضح النمط - يتوسع بيه
 * لاحقًا بنفس الأسلوب: كل endpoint جديد يتحدد له scope واضح في الـ
 * routes، والـ Controller يفترض إن PartnerAuthMiddleware اشتغل قبله
 * ومعاه بيانات الشريك في $_SERVER['auth_partner'].
 *
 * @version 1.0.0
 */

class PartnerController extends Controller
{
    /**
     * فحص اتصال بسيط - أي مفتاح صالح (بدون أي scope خاص) يعدي منها.
     * مفيد للشريك عشان يتأكد إن المفتاح شغال قبل ما يكمل تكامل حقيقي.
     * GET /api/partner/ping
     */
    public function ping(array $params = []): array
    {
        $partner = $_SERVER['auth_partner'] ?? null;

        return $this->success([
            'partner' => $partner['partner_name'] ?? null,
            'scopes' => $partner['scopes'] ?? [],
            'server_time' => date('c'),
        ], 'pong');
    }

    /**
     * ملخص السمعة (تقييمات عامة فقط - بدون أي بيانات شخصية للعملاء
     * زي الاسم أو الإيميل أو رقم الهاتف) لعميل معيّن من عملاء المنصة،
     * محدّد بـ website_id. يتطلب scope: reputation:read
     *
     * GET /api/partner/websites/{website_id}/reputation-summary
     */
    public function reputationSummary(array $params = []): array
    {
        $websiteId = (int) ($params['website_id'] ?? 0);
        if ($websiteId <= 0) {
            return $this->error('Invalid website_id', 400);
        }

        if (!class_exists('Review')) {
            return $this->error('Reputation module unavailable', 503);
        }

        try {
            $stats = Review::getSentimentStats($websiteId);
            $platforms = Review::getPlatformStats($websiteId);

            // إخفاء أي حقل ممكن يبقى حساس لو اتضاف مستقبلاً - نرجّع بس
            // الأرقام المجمّعة (aggregates)، أبدًا مراجعات فردية ببيانات عميل
            return $this->success([
                'website_id' => $websiteId,
                'total_reviews' => $stats['total'],
                'average_rating' => round((float) $stats['avg_rating'], 2),
                'sentiment_breakdown' => [
                    'positive' => $stats['positive'],
                    'neutral' => $stats['neutral'],
                    'negative' => $stats['negative'],
                    'mixed' => $stats['mixed'],
                ],
                'by_platform' => array_map(function ($row) {
                    return [
                        'platform' => $row['platform'],
                        'count' => (int) $row['count'],
                        'average_rating' => round((float) $row['avg_rating'], 2),
                    ];
                }, $platforms),
            ]);
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('Partner reputationSummary error', ['message' => $e->getMessage()]);
            }
            return $this->error('Failed to load reputation summary', 500);
        }
    }
}
