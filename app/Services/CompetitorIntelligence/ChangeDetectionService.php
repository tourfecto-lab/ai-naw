<?php

/**
 * Tourfecto - Competitor Intelligence: Change Detection Service
 * @version 1.0.0
 *
 * منطق "Nothing Changed" مقابل "Something Changed" بمقارنة content_hash
 * بين آخر لقطتين لنفس (competitor_id, page_type)، ثم تصنيف نوع/خطورة/
 * ثقة التغيير. لا يعتمد على تخمين - كله مبني على فرق فعلي بين نصين
 * مُطبَّعين محفوظين فعليًا.
 */
class ChangeDetectionService
{
    /**
     * يقارن لقطة جديدة باللقطة السابقة (لو موجودة) لنفس الصفحة، ويسجّل
     * تغيير في ci_changes لو فيه فرق فعلي. يرجّع الـ CiChange الناتج أو null.
     */
    public function detectAndRecord(Competitor $competitor, string $pageType, CiSnapshot $newSnapshot): ?CiChange
    {
        $userId = (int) $competitor->getAttribute('user_id');
        $competitorId = (int) $competitor->getAttribute('id');

        if ($newSnapshot->getAttribute('fetch_status') === 'failed') {
            // فشل الجلب لا يُعتبر أبدًا "لا يوجد تغيير" - نسجّله كخطأ مراقبة فقط
            // (بيتحدث last_monitoring_error على المنافس من MonitoringEngine).
            return null;
        }

        $previous = $this->getPreviousSnapshot($competitorId, $pageType, (int) $newSnapshot->getAttribute('id'));

        if ($previous === null) {
            // أول لقطة على الإطلاق لهذه الصفحة = صفحة جديدة تُكتشف لأول مرة
            // (مش "تغيير" بمعنى مقارنة، فتُسجَّل بثقة متوسطة كخط أساس فقط
            // لو الصفحة أصلاً غير الصفحة الرئيسية).
            return null;
        }

        if ($previous->getAttribute('fetch_status') === 'failed') {
            // اللقطة السابقة كانت فاشلة أصلاً - مفيش أساس مقارنة موثوق،
            // نعتبرها استعادة اتصال فقط بدون تسجيل تغيير محتوى.
            return null;
        }

        $prevHash = (string) $previous->getAttribute('content_hash');
        $newHash = (string) $newSnapshot->getAttribute('content_hash');

        if ($prevHash !== '' && $prevHash === $newHash) {
            return null; // Nothing Changed
        }

        // Something Changed - نصنّف النوع والخطورة
        $classification = $this->classify($pageType, $previous, $newSnapshot);

        // ميزة "تاريخ الأسعار" (Prisync-style): لو التغيير على صفحة
        // تسعير/عروض/منتج، نحاول نستخرج سعرًا مهيكلًا من النصين عشان
        // نسجّله في ci_changes ويرسم لنا رسم بياني حقيقي لتحركات السعر.
        $priceData = in_array($classification['change_type'], ['pricing_change', 'offer_change', 'new_product'], true)
            ? $this->extractPriceChange($previous, $newSnapshot)
            : null;

        $attributes = [
            'competitor_id' => $competitorId,
            'user_id' => $userId,
            'page_type' => $pageType,
            'change_type' => $classification['change_type'],
            'severity' => $classification['severity'],
            'previous_value' => $this->diffExcerpt((string) $previous->getAttribute('normalized_excerpt')),
            'new_value' => $this->diffExcerpt((string) $newSnapshot->getAttribute('normalized_excerpt')),
            'source_url' => (string) $newSnapshot->getAttribute('url'),
            'confidence' => $classification['confidence'],
            'snapshot_before_id' => (int) $previous->getAttribute('id'),
            'snapshot_after_id' => (int) $newSnapshot->getAttribute('id'),
        ];

        if ($priceData !== null) {
            $attributes['price_before'] = $priceData['price_before'];
            $attributes['price_after'] = $priceData['price_after'];
            $attributes['currency'] = $priceData['currency'];
        }

        $change = new CiChange($attributes);
        $change->save();

        $competitor->setAttribute('last_change_at', date('Y-m-d H:i:s'));
        $competitor->save();

        return $change;
    }

    /**
     * يستخرج سعرًا مهيكلًا (رقم + عملة) من لقطتي before/after عبر
     * PriceExtractor. لو مفيش سعر واضح في أي من النصين، نرجّع null
     * (مش هنخزن قيم جزئية مضللة). العملة المفضلة = اللي في النص الجديد.
     */
    private function extractPriceChange(CiSnapshot $previous, CiSnapshot $new): ?array
    {
        $before = PriceExtractor::extract((string) $previous->getAttribute('normalized_excerpt'));
        $after = PriceExtractor::extract((string) $new->getAttribute('normalized_excerpt'));

        if ($before === null && $after === null) {
            return null;
        }

        return [
            'price_before' => $before['amount'] ?? null,
            'price_after' => $after['amount'] ?? null,
            'currency' => ($after['currency'] ?? null) ?: ($before['currency'] ?? null),
        ];
    }

    private function getPreviousSnapshot(int $competitorId, string $pageType, int $excludeId): ?CiSnapshot
    {
        $db = Database::getInstance();
        $rows = $db->query(
            "SELECT * FROM `ci_snapshots` WHERE competitor_id = ? AND page_type = ? AND id != ?
             ORDER BY captured_at DESC, id DESC LIMIT 1",
            [$competitorId, $pageType, $excludeId]
        );
        return !empty($rows) ? new CiSnapshot($rows[0]) : null;
    }

    /**
     * تصنيف بسيط قائم على قواعد (rules-based)، شفاف وقابل للتفسير - مش
     * "صندوق أسود". العنوان (title) تغيّر بالكامل = إشارة قوية على تغيير
     * headline/positioning. صفحة pricing/offers تغيّرت = خطورة أعلى من
     * تغيير بسيط في صفحة blog.
     */
    private function classify(string $pageType, CiSnapshot $previous, CiSnapshot $new): array
    {
        $prevTitle = (string) $previous->getAttribute('title');
        $newTitle = (string) $new->getAttribute('title');
        $titleChanged = $prevTitle !== '' && $prevTitle !== $newTitle;

        $prevLen = mb_strlen((string) $previous->getAttribute('normalized_excerpt'));
        $newLen = mb_strlen((string) $new->getAttribute('normalized_excerpt'));
        $lengthDeltaRatio = $prevLen > 0 ? abs($newLen - $prevLen) / $prevLen : 1.0;

        $structuredChanged = (string) $previous->getAttribute('structured_data_hash') !== (string) $new->getAttribute('structured_data_hash');

        if (in_array($pageType, ['pricing'], true)) {
            $changeType = 'pricing_change';
            $severity = 'high';
        } elseif (in_array($pageType, ['offers'], true)) {
            $changeType = 'offer_change';
            $severity = 'high';
        } elseif (in_array($pageType, ['products', 'services'], true)) {
            $changeType = $lengthDeltaRatio > 0.15 ? 'new_product' : 'content_change';
            $severity = $lengthDeltaRatio > 0.15 ? 'high' : 'medium';
        } elseif ($pageType === 'homepage' && $titleChanged) {
            $changeType = 'headline_change';
            $severity = 'medium';
        } elseif ($pageType === 'blog') {
            $changeType = 'content_change';
            $severity = 'low';
        } else {
            $changeType = 'content_change';
            $severity = $lengthDeltaRatio > 0.3 ? 'medium' : 'low';
        }

        // فرق ضخم في حجم المحتوى (>60%) يرفع الخطورة لـ critical بغض النظر عن النوع
        if ($lengthDeltaRatio > 0.6) {
            $severity = 'critical';
        }

        // الثقة: عالية لو التغيير واضح (title تغيّر أو structured data تغيّرت
        // أو فرق حجم كبير)، متوسطة لو فرق طفيف فقط.
        if ($titleChanged || $structuredChanged || $lengthDeltaRatio > 0.3) {
            $confidence = 'high';
        } elseif ($lengthDeltaRatio > 0.05) {
            $confidence = 'medium';
        } else {
            $confidence = 'low';
        }

        return ['change_type' => $changeType, 'severity' => $severity, 'confidence' => $confidence];
    }

    /**
     * نص before/after محدود للعرض في الواجهة (مش المقطع الكامل 20000
     * حرف) - أول 600 حرف كافية لإظهار الفرق للمستخدم مع رابط للمصدر.
     */
    private function diffExcerpt(string $text): string
    {
        return mb_substr($text, 0, 600);
    }
}
