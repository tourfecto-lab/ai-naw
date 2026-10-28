<?php

/**
 * Tourfecto - Enterprise Send Ad Conversion Job (Server-Side CAPI & Closed-Loop Attribution)
 * @version 2.0.0
 *
 * يرسل أحداث التحويل المؤكدة إلى Meta Conversions API و Google Enhanced Conversions
 * مع:
 * 1. تشفير كامل للبيانات الشخصية (SHA-256) عبر AdPiiHasher وحساب مؤشر EMQ.
 * 2. منع التكرار (Event Deduplication) باستخدام booking_reference كـ event_id موحد.
 * 3. التغذية الراجعة المباشرة لتقارير أداء الحملات (Closed-Loop Revenue Attribution).
 */
class SendAdConversionJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        $bookingId = (int) ($payload['booking_id'] ?? 0);
        if ($bookingId <= 0) {
            throw new Exception('booking_id مفقود في payload');
        }

        $db = Database::getInstance();

        $rows = $db->query(
            "SELECT b.*, u.campaign_id, u.code AS utm_code,
                    pc.platform AS platform, pc.access_token AS connection_token, pc.external_account_id AS ad_account_id
             FROM bookings b
             JOIN ad_utm_links u ON u.id = b.attributed_utm_link_id
             JOIN ad_campaigns c ON c.id = u.campaign_id
             LEFT JOIN platform_connections pc ON pc.id = c.platform_connection_id
             WHERE b.id = ? AND b.status = 'confirmed' AND b.attributed_utm_link_id IS NOT NULL
             LIMIT 1",
            [$bookingId]
        );
        if (empty($rows)) {
            return;
        }
        $row = $rows[0];

        $emailHash = AdPiiHasher::hashEmail($row['customer_email'] ?? null);
        $phoneHash = AdPiiHasher::hashPhone($row['customer_phone'] ?? null);
        if ($emailHash === null && $phoneHash === null) {
            if (class_exists('Logger')) {
                Logger::info('Ad conversion skipped: no PII to hash', ['booking_id' => $bookingId]);
            }
            return;
        }

        // استخراج وتجزئة الاسم الكامل إن وجد لرفع درجة التطابق (EMQ Boost)
        $customerName = (string) ($row['customer_name'] ?? $row['guest_name'] ?? '');
        $nameHashes = AdPiiHasher::splitAndHashFullName($customerName);

        // تمرير معرّفات الجلسة الإعلانية الاختيارية إن تم تمريرها في الـ payload
        $extraSignals = [
            'fn' => $nameHashes['fn'],
            'ln' => $nameHashes['ln'],
            'fbc' => $payload['fbc'] ?? null,
            'fbp' => $payload['fbp'] ?? null,
            'gclid' => $payload['gclid'] ?? null,
            'client_ip_address' => $payload['client_ip_address'] ?? null,
            'client_user_agent' => $payload['client_user_agent'] ?? null,
            'event_time' => !empty($row['created_at']) ? strtotime((string) $row['created_at']) : time(),
        ];

        $platform = (string) ($row['platform'] ?? '');
        $value = round((float) ($row['total_amount'] ?? 0), 2);
        $currency = strtoupper(trim((string) ($row['currency'] ?? 'USD'))) ?: 'USD';

        $settings = class_exists('SystemSettingsService') ? new SystemSettingsService() : null;

        if ($platform === 'meta_ads') {
            $this->sendMetaConversion($row, $settings, $emailHash, $phoneHash, $value, $currency, $extraSignals);
            return;
        }

        if ($platform === 'google_ads') {
            $this->sendGoogleConversion($row, $settings, $emailHash, $phoneHash, $value, $currency, $extraSignals);
            return;
        }

        if (class_exists('Logger')) {
            Logger::info('Ad conversion skipped: unsupported platform', ['booking_id' => $bookingId, 'platform' => $platform]);
        }
    }

    /** Factory قابلة للاستبدال في الاختبارات (منع أي استدعاء شبكة فعلي) */
    protected function makeMetaApi(string $token): MetaAdsAPI
    {
        return new MetaAdsAPI($token);
    }

    protected function makeGoogleApi(string $token): GoogleAdsAPI
    {
        return new GoogleAdsAPI($token);
    }

    private function sendMetaConversion(
        array $row,
        ?object $settings,
        ?string $emailHash,
        ?string $phoneHash,
        float $value,
        string $currency,
        array $extraSignals = []
    ): void {
        // الحجز بيروح لبيكسل صاحب الحملة بتوكن ربطه هو. قبل كده كان بيروح لبيكسل واحد على مستوى المنصة:
        // إعلانات العميل ماكانتش بتستفيد، وبيانات زباين كل العملاء كانت بتتجمع عند بيكسل واحد.
        $token = !empty($row['connection_token']) ? (string) (new Encryption())->decrypt((string) $row['connection_token']) : '';
        if ($token === '') {
            $this->skip('Meta: campaign has no connected ad account', $row);
            return;
        }
        $api = $this->makeMetaApi($token);
        $pixelId = self::campaignPixel((int) ($row['campaign_id'] ?? 0), $settings);
        if ($pixelId === '' && !empty($row['ad_account_id'])) {
            $pixelId = (string) ($api->listPixels((string) $row['ad_account_id'])[0]['id'] ?? '');
        }
        if ($pixelId === '') {
            $this->skip('Meta: customer ad account has no pixel', $row);
            return;
        }

        $eventData = [
            'event_id' => (string) $row['booking_reference'],
            'value' => $value,
            'currency' => $currency,
            'email_hash' => $emailHash,
            'phone_hash' => $phoneHash,
        ];

        // إضافة الإشارات المعززة للـ EMQ إن وجدت دون كسر التوافق مع الاختبارات الحالية
        foreach (['fn', 'ln', 'fbc', 'fbp', 'client_ip_address', 'client_user_agent', 'event_time'] as $k) {
            if (!empty($extraSignals[$k])) {
                $eventData[$k] = $extraSignals[$k];
            }
        }

        $result = $api->sendConversionEvent($pixelId, $eventData);

        if (!$result['success']) {
            throw new Exception('Meta CAPI failed: ' . ($result['error'] ?? 'خطأ غير معروف'));
        }
    }

    private function sendGoogleConversion(
        array $row,
        ?object $settings,
        ?string $emailHash,
        ?string $phoneHash,
        float $value,
        string $currency,
        array $extraSignals = []
    ): void {
        // نفس الفكرة: حساب Google Ads بتاع صاحب الحملة وإجراء تحويل من حسابه هو، مش حساب واحد على مستوى المنصة
        $customerId = preg_replace('/\D/', '', (string) ($row['ad_account_id'] ?? ''));
        $token = !empty($row['connection_token']) ? (string) (new Encryption())->decrypt((string) $row['connection_token']) : '';
        if ($customerId === '' || $token === '') {
            $this->skip('Google: campaign has no connected ad account', $row);
            return;
        }
        $api = $this->makeGoogleApi($token);
        $conversionAction = $api->uploadConversionAction($customerId);
        if ($conversionAction === null) {
            $this->skip('Google: customer account has no enabled upload (offline) conversion action', $row);
            return;
        }

        $eventData = [
            'event_id' => (string) $row['booking_reference'],
            'value' => $value,
            'currency' => $currency,
            'email_hash' => $emailHash,
            'phone_hash' => $phoneHash,
        ];

        foreach (['fn', 'ln', 'gclid', 'event_time'] as $k) {
            if (!empty($extraSignals[$k])) {
                $eventData[$k] = $extraSignals[$k];
            }
        }

        $result = $api->sendEnhancedConversion($customerId, $conversionAction, $eventData);

        if (!$result['success']) {
            throw new Exception('Google CAPI failed: ' . ($result['error'] ?? 'خطأ غير معروف'));
        }
    }

    /** البيكسل اللي الحملة اتنشرت بيه (AdsController بيحفظه وقت النشر) */
    public static function campaignPixel(int $campaignId, ?object $settings): string
    {
        if ($campaignId <= 0 || $settings === null) {
            return '';
        }
        $v = trim((string) $settings->get('ad_campaign_pixel_' . $campaignId));
        return preg_match('/^\d{5,25}$/', $v) ? $v : '';
    }

    /** العميل لسه ماجهزش التتبع: مش خطأ نعيد عليه المحاولة، بس بنسجّل السبب */
    private function skip(string $reason, array $row): void
    {
        if (class_exists('Logger')) {
            Logger::info('Ad conversion skipped: ' . $reason, ['booking_id' => $row['id'] ?? null, 'campaign_id' => $row['campaign_id'] ?? null]);
        }
    }
}