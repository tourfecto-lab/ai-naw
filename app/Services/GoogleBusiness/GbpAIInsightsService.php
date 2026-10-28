<?php

/**
 * Tourfecto - GBP AI Insights & Recommendations Service
 * تحليل AI مبني حصريًا على بيانات حقيقية اتجابت فعلاً من Google
 * (Insights/Profile/Reviews) - لا أرقام مخترعة. الـ AI هنا "يفسّر ويقترح"
 * بس، ومفيش أي تنفيذ تلقائي لأي تغيير على البروفايل من غير تأكيد صريح
 * من المستخدم عبر GbpProfileService::updateProfile().
 * @version 1.0.0
 * @since 2026-08-09 (GBP Module Upgrade)
 */
class GbpAIInsightsService
{
    /** @var GeminiClient */
    private $ai;
    /** @var GbpInsightsService */
    private $insights;
    /** @var GbpProfileService */
    private $profile;

    public function __construct(?GeminiClient $ai = null)
    {
        $this->ai = $ai ?? new GeminiClient();
        $this->insights = new GbpInsightsService();
        $this->profile = new GbpProfileService();
    }

    /**
     * Insight = ملاحظة + Evidence (الرقم الحقيقي اللي بنيت عليه) + Confidence + إجراء مقترح.
     * لو مفيش بيانات كافية، بيرجع مصفوفة فاضية مع سبب واضح بدل ما يخترع.
     */
    public function generateInsights(int $websiteId, int $userId, ?callable $beforeAi = null): array
    {
        $insightsData = $this->insights->getInsights($websiteId, $userId, 30, true);
        if (!$insightsData['success']) {
            return ['success' => false, 'error' => $insightsData['error'], 'insights' => []];
        }
        // الخصم بيحصل هنا بس - بعد ما نتأكد إن الحساب مربوط وفيه بيانات، وقبل طلب الـ AI مباشرة
        if ($beforeAi !== null && ($blocked = $beforeAi()) !== null) {
            return ['success' => false, 'insights' => []] + $blocked;
        }

        $profileData = $this->profile->getProfile($websiteId, $userId);

        $evidenceInsights = $this->buildEvidenceInsights($insightsData, $profileData);

        // نبعت للـ AI بس الأرقام الحقيقية المستخرجة فعلاً (evidence)، ونطلب
        // منه يصيغها كنصيحة مفهومة، مش يخترع أرقام جديدة. البرومبت بيمنعه
        // صراحة من إضافة أي رقم مش موجود في evidence.
        $summaryPrompt = $this->buildPrompt($evidenceInsights, $profileData);
        $aiResponse = $this->ai->generateContent($summaryPrompt, ['task'=>'gbp_insights', 'maxOutputTokens' => 700]);

        if (class_exists('GbpAuditLogger')) {
            GbpAuditLogger::log(
                'ai_analysis',
                $websiteId,
                $userId,
                ($aiResponse['success'] ?? false) ? 'success' : 'failed',
                ['type' => 'insights_summary']
            );
        }

        return [
            'success' => true,
            'insights' => $evidenceInsights,
            'ai_summary' => ($aiResponse['success'] ?? false) ? trim((string) ($aiResponse['data'] ?? '')) : null,
            'ai_summary_error' => ($aiResponse['success'] ?? false) ? null : ($aiResponse['error'] ?? null),
        ];
    }

    /** توصيات قابلة للتنفيذ - AI يقترح فقط، أي تنفيذ فعلي لازم تأكيد صريح من المستخدم */
    public function generateRecommendations(int $websiteId, int $userId): array
    {
        $profileData = $this->profile->getProfile($websiteId, $userId);
        if (!$profileData['success']) {
            return ['success' => false, 'error' => $profileData['error'], 'recommendations' => []];
        }

        $completeness = $profileData['completeness'];
        $recommendations = [];

        foreach ($completeness['missing'] as $field) {
            $recommendations[] = $this->recommendationFor($field);
        }

        if (empty($completeness['missing'])) {
            $recommendations[] = [
                'action' => 'publish_post',
                'title' => 'انشر تحديث جديد',
                'reason' => 'بروفايلك مكتمل - حافظ على النشاط بمنشور جديد بانتظام لتحسين الظهور',
                'priority' => 'medium',
            ];
        }

        // إضافة (2026-08-10 - Round 4): توصيات مذكورة صراحة في السبيك
        // ومكانتش موجودة قبل كده (Respond to Reviews / Improve Photos /
        // Improve Website CTA) - كل واحدة مبنية على بيانات حقيقية من
        // قاعدة بياناتنا (مراجعات/صور/منشورات منشورة فعليًا)، مش تخمين.
        $unrepliedRec = $this->recommendUnrepliedReviews($websiteId, $userId);
        if ($unrepliedRec) {
            $recommendations[] = $unrepliedRec;
        }

        $photosRec = $this->recommendPhotos($websiteId, $userId);
        if ($photosRec) {
            $recommendations[] = $photosRec;
        }

        $ctaRec = $this->recommendWebsiteCta($websiteId, $userId, $profileData['profile']['website'] ?? null);
        if ($ctaRec) {
            $recommendations[] = $ctaRec;
        }

        return ['success' => true, 'recommendations' => array_values(array_filter($recommendations))];
    }

    /** Respond to Reviews - مبني على عدد المراجعات الفعلية اللي مفيهاش رد لسه (من جدول reviews الحقيقي) */
    private function recommendUnrepliedReviews(int $websiteId, int $userId): ?array
    {
        try {
            $db = Database::getInstance();
            $rows = $db->query(
                "SELECT COUNT(*) AS cnt FROM reviews
                 WHERE website_id = ? AND user_id = ? AND source_platform = 'google_business' AND reply_sent_at IS NULL",
                [$websiteId, $userId]
            );
            $count = (int) ($rows[0]['cnt'] ?? 0);
            if ($count <= 0) {
                return null;
            }

            return [
                'action' => 'respond_to_reviews',
                'title' => "رد على {$count} مراجعة بدون رد",
                'reason' => "عندك {$count} مراجعة على Google مفيش عليها رد لسه - الرد بيحسّن ثقة العملاء ويظهر النشاط كمهتم",
                'priority' => $count >= 5 ? 'high' : 'medium',
                'link' => '/reputation/reviews',
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Improve Photos - مبني على عدد الصور الحقيقي المرفوع فعليًا على Google (جدول gbp_photos) */
    private function recommendPhotos(int $websiteId, int $userId): ?array
    {
        try {
            $db = Database::getInstance();
            $rows = $db->query("SELECT COUNT(*) AS cnt FROM gbp_photos WHERE website_id = ? AND user_id = ?", [$websiteId, $userId]);
            $count = (int) ($rows[0]['cnt'] ?? 0);
            // Google بينصح بـ 10 صور على الأقل لبروفايل قوي - رقم إرشادي معروف، مش مخترع من عندنا
            if ($count >= 10) {
                return null;
            }

            return [
                'action' => 'improve_photos',
                'title' => $count === 0 ? 'ارفع صور للنشاط' : "أضف صور أكتر ({$count} حاليًا)",
                'reason' => 'البروفايلات اللي فيها صور أكتر بتاخد تفاعل وثقة أعلى من العملاء على Google',
                'priority' => $count === 0 ? 'high' : 'low',
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Improve Website CTA - لو الموقع موجود بس مفيش منشور منشور فعليًا بزرار CTA بيوجّه له مؤخرًا */
    private function recommendWebsiteCta(int $websiteId, int $userId, ?string $website): ?array
    {
        if (!$website) {
            return null;
        } // لو الموقع أصلًا مش موجود، دي توصية "update_website" مش دي

        try {
            $db = Database::getInstance();
            $rows = $db->query(
                "SELECT COUNT(*) AS cnt FROM gbp_scheduled_posts sp
                 JOIN gbp_content c ON c.id = sp.gbp_content_id
                 WHERE c.website_id = ? AND c.user_id = ? AND sp.status = 'published' AND sp.published_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                [$websiteId, $userId]
            );
            $count = (int) ($rows[0]['cnt'] ?? 0);
            if ($count > 0) {
                return null;
            } // فيه منشور منشور فعلاً - CTA بيظهر بالفعل

            return [
                'action' => 'improve_website_cta',
                'title' => 'وجّه العملاء لموقعك عبر منشور',
                'reason' => 'مفيش منشور اتنشر آخر 30 يوم بزرار "اعرف أكتر" اللي بيوجّه لموقعك - انشر تحديث جديد عشان الزرار يظهر',
                'priority' => 'medium',
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    private function recommendationFor(string $missingField): ?array
    {
        $map = [
            'description' => ['action' => 'update_description', 'title' => 'أضف وصفًا للنشاط', 'reason' => 'الوصف غير موجود حاليًا في البروفايل - يساعد العملاء على فهم نشاطك ويحسّن الظهور في البحث', 'priority' => 'high'],
            'phone' => ['action' => 'update_phone', 'title' => 'أضف رقم هاتف', 'reason' => 'رقم الهاتف غير موجود - يقلل فرص تواصل العملاء المباشر', 'priority' => 'high'],
            'website' => ['action' => 'update_website', 'title' => 'أضف رابط الموقع', 'reason' => 'رابط الموقع غير موجود في البروفايل', 'priority' => 'medium'],
            'regular_hours' => ['action' => 'update_hours', 'title' => 'حدّث ساعات العمل', 'reason' => 'ساعات العمل غير محددة - العملاء لا يعرفون مواعيد الزيارة', 'priority' => 'high'],
            'primary_category' => ['action' => 'review_category', 'title' => 'راجع تصنيف النشاط', 'reason' => 'التصنيف الأساسي غير محدد بوضوح', 'priority' => 'medium'],
            'address' => ['action' => 'update_address', 'title' => 'تأكد من دقة العنوان', 'reason' => 'بيانات العنوان غير مكتملة', 'priority' => 'medium'],
        ];

        return $map[$missingField] ?? null;
    }

    private function buildEvidenceInsights(array $insightsData, array $profileData): array
    {
        $items = [];
        $totals = $insightsData['totals'] ?? [];
        $change = $insightsData['previous_period']['change_percent'] ?? null;

        $metricLabels = [
            'website_clicks' => 'نقرات الموقع الإلكتروني',
            'phone_calls' => 'مكالمات الهاتف',
            'direction_requests' => 'طلبات الاتجاهات',
            'views' => 'مشاهدات البروفايل',
            'searches' => 'مرات الظهور في البحث',
        ];

        foreach ($metricLabels as $key => $label) {
            $value = $totals[$key] ?? 0;
            $insight = [
                'metric' => $key,
                'evidence' => "{$label}: {$value} خلال آخر " . ($insightsData['range']['days'] ?? 30) . ' يوم',
                'confidence' => 'high', // مباشرة من بيانات Google الرسمية، مش تقدير
            ];

            if ($change !== null && isset($change[$key])) {
                $direction = $change[$key] > 0 ? 'زيادة' : ($change[$key] < 0 ? 'انخفاض' : 'استقرار');
                $insight['comparison'] = "{$direction} بنسبة " . abs($change[$key]) . '% مقارنة بالفترة السابقة';
                $insight['insight_text'] = "{$label} سجّلت {$direction} بنسبة " . abs($change[$key]) . '% مقارنة بالفترة السابقة.';
            } else {
                $insight['comparison'] = 'Not enough data for comparison';
                $insight['insight_text'] = "{$label}: {$value} (لا تتوفر بيانات كافية للمقارنة بفترة سابقة)";
            }

            $insight['recommended_action'] = $this->actionForMetric($key, $change[$key] ?? null);
            $items[] = $insight;
        }

        if ($profileData['success']) {
            $items[] = [
                'metric' => 'profile_completeness',
                'evidence' => 'نسبة اكتمال البروفايل: ' . $profileData['completeness']['score'] . '%',
                'confidence' => 'high',
                'insight_text' => 'نسبة اكتمال البروفايل الحالية ' . $profileData['completeness']['score'] . '% بناءً على الحقول المتاحة فعليًا.',
                'recommended_action' => $profileData['completeness']['score'] < 100
                    ? 'أكمل الحقول الناقصة: ' . implode('، ', $profileData['completeness']['missing'])
                    : 'البروفايل مكتمل - حافظ على تحديثه بانتظام',
            ];
        }

        return $items;
    }

    private function actionForMetric(string $key, ?float $changePercent): string
    {
        if ($changePercent !== null && $changePercent < -10) {
            $actions = [
                'website_clicks' => 'راجع رابط الموقع وتأكد إنه يعمل، وأضف Call-to-Action في منشور جديد',
                'phone_calls' => 'تأكد من صحة رقم الهاتف الظاهر في البروفايل',
                'direction_requests' => 'تأكد من دقة العنوان والموقع على الخريطة',
                'views' => 'انشر تحديثًا جديدًا وأضف صورًا حديثة لتحسين الظهور',
                'searches' => 'راجع الكلمات المستخدمة في وصف النشاط والتصنيف',
            ];
            return $actions[$key] ?? 'راجع بيانات البروفايل';
        }

        return 'استمر بنفس الأداء - النتائج مستقرة أو في تحسن';
    }

    private function buildPrompt(array $evidenceInsights, array $profileData): string
    {
        $evidenceLines = array_map(fn ($i) => '- ' . ($i['insight_text'] ?? $i['evidence']), $evidenceInsights);
        $evidenceText = implode("\n", $evidenceLines);

        return "لخّص أداء صفحة Google Business Profile التالية للعميل بأسلوب مباشر ومشجع باللغة العربية، "
            . "بحد أقصى 4 جمل قصيرة. استخدم فقط الأرقام والحقائق المذكورة تحت - ممنوع تمامًا إضافة أي رقم أو "
            . "إحصائية غير مذكورة هنا:\n\n{$evidenceText}";
    }
}
