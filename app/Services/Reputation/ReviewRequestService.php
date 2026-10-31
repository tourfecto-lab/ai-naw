<?php

/**
 * Tourfecto - Review Request Service
 * جدولة وإرسال طلبات المراجعات التلقائية بعد انتهاء الخدمة، عن طريق
 * واتساب أو إيميل - بيستخدم نفس بنية ChatManager::sendMessageForWebsite()
 * الحقيقية (نفس اتصال UltraMsg المربوط بكل موقع + Mailer الموجود
 * لقناة الإيميل).
 *
 * @version 1.1.0
 * تحديث 2026-08-10: دعم قناة الإيميل، Duplicate Protection، Opt-Out
 * دائم (مستقل عن حالة الطلب الفردي)، Attribution حقيقي مع جدول
 * reviews، فلاتر/صفحات لقائمة الطلبات، Analytics حقيقي فقط لو
 * العينة كافية، ومساعد AI لصياغة الرسائل (يستخدم GeminiClient
 * الموجود فعليًا - نفس مفتاح المشروع، مفيش تكامل جديد).
 */
class ReviewRequestService
{
    /** @var Database */
    private $db;
    /** @var ChatManager */
    private $chatManager;

    /** أقل عدد عينات مطلوب قبل ما نعرض أي نسبة/متوسط في الـ Analytics */
    private const MIN_SAMPLE_FOR_ANALYTICS = 3;

    /** نافذة زمنية افتراضية (بالساعات) لاعتبار طلبين "مكررين" لنفس الضيف */
    private const DUPLICATE_WINDOW_HOURS = 24;

    /** أقصى عدد محاولات إرسال (تلقائية + يدوية) قبل ما نمنع أي Retry تاني (Section 19) */
    private const MAX_SEND_ATTEMPTS = 3;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->chatManager = new ChatManager();
    }

    /** إعدادات موقع معيّن - بترجع الافتراضية لو الموقع لسه معملوش إعدادات خاصة */
    public function getSettings(int $websiteId): array
    {
        $rows = (new ReviewRequestSettings())->where(['website_id' => $websiteId], [], 1);
        if (!empty($rows)) {
            return $rows[0]->toArray();
        }
        return ReviewRequestSettings::defaults($websiteId);
    }

    /** حفظ/تحديث إعدادات موقع */
    public function saveSettings(int $websiteId, array $data): void
    {
        $existing = (new ReviewRequestSettings())->where(['website_id' => $websiteId], [], 1);
        $settings = !empty($existing) ? $existing[0] : new ReviewRequestSettings();
        $data['website_id'] = $websiteId;
        $settings->fill($data);
        $settings->save();
    }

    /**
     * إضافة طلب مراجعة جديد - بيتحسب موعد الإرسال تلقائيًا (تاريخ انتهاء
     * الخدمة + عدد ساعات التأخير). بيدعم قناتين فقط فعليًا: واتساب
     * (UltraMsg المربوط بالموقع) أو إيميل (Mailer الموجود) - أي قناة
     * تانية مرفوضة صراحة، مفيش "Mock Integration".
     *
     * قبل الإنشاء: يفحص Opt-Out الدائم، ثم يفحص تكرار حديث لنفس
     * الضيف/القناة (Duplicate Protection) - يرمي Exception واضح بدل ما
     * يبعت رسالة تانية للضيف نفسه من غير داعي.
     *
     * @throws Exception لو القناة غير مهيأة، أو الضيف Opted-Out، أو
     *                   فيه طلب حديث مكرر لنفس الضيف/القناة.
     */
    public function createRequest(
        int $userId,
        int $websiteId,
        string $guestName,
        ?string $guestPhone,
        string $serviceEndDate,
        string $channel = 'whatsapp',
        ?string $guestEmail = null,
        string $source = 'manual',
        ?int $crmDealId = null,
        string $destinationPlatform = 'other'
    ): ReviewRequest {
        if (!in_array($channel, ['whatsapp', 'email', 'sms'], true)) {
            throw new Exception('قناة غير مدعومة - المتاح حاليًا: واتساب أو إيميل أو SMS');
        }
        // Admin-controlled plan limit (unlimited unless the admin sets one). An infrastructure error never blocks the customer.
        try {
            ReputationLimits::assertCanCreateRequest($userId, $this->db);
            if ($channel === 'sms') {
                ReputationLimits::assertCanSendSms($userId, $this->db);
            }
        } catch (ReputationLimitReached $limit) {
            throw $limit;
        } catch (Throwable $e) {
            Logger::warning('Reputation limit check skipped', ['message' => $e->getMessage()]);
        }
        if (!in_array($destinationPlatform, ['google_business', 'tripadvisor', 'other'], true)) {
            throw new Exception('وجهة تقييم غير مدعومة');
        }

        $guestPhone = $guestPhone !== null ? preg_replace('/[^0-9]/', '', $guestPhone) : null;
        $guestEmail = $guestEmail !== null ? trim($guestEmail) : null;

        if (in_array($channel, ['whatsapp', 'sms'], true) && empty($guestPhone)) {
            throw new Exception($channel === 'whatsapp' ? 'رقم واتساب مطلوب لقناة واتساب' : 'رقم الهاتف مطلوب لقناة SMS');
        }
        if ($channel === 'email' && empty($guestEmail)) {
            throw new Exception('إيميل الضيف مطلوب لقناة الإيميل');
        }
        if ($channel === 'email' && !filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('صيغة الإيميل غير صحيحة');
        }

        if (!$this->isChannelConfigured($websiteId, $channel)) {
            throw new Exception($this->channelNotConfiguredMessage($channel));
        }

        if ($this->isOptedOut($websiteId, $guestPhone, $guestEmail)) {
            throw new Exception('هذا الضيف طلب عدم التواصل معه سابقًا (Opt-Out) - لا يمكن إنشاء طلب جديد له');
        }

        $duplicate = $this->findRecentDuplicate($websiteId, $guestPhone, $guestEmail);
        if ($duplicate) {
            throw new Exception(
                'يوجد طلب مراجعة حديث بالفعل لنفس الضيف (رقم #' . $duplicate['id'] . '، بتاريخ ' . $duplicate['created_at'] . ') خلال آخر ' . self::DUPLICATE_WINDOW_HOURS . ' ساعة'
            );
        }

        $settings = $this->getSettings($websiteId);
        $reviewLink = $this->resolveReviewLink($settings, $destinationPlatform);

        if (empty($reviewLink)) {
            throw new Exception('لازم تحدّد رابط تقييم لوجهة "' . $this->destinationLabel($destinationPlatform) . '" في إعدادات الحملة الأول');
        }

        $delayHours = (int) $settings['default_delay_hours'];
        $scheduledAt = date('Y-m-d H:i:s', strtotime($serviceEndDate) + ($delayHours * 3600));

        $request = new ReviewRequest();
        $request->fill([
            'user_id' => $userId,
            'website_id' => $websiteId,
            'guest_name' => $guestName,
            'guest_phone' => $guestPhone,
            'guest_email' => $guestEmail,
            'channel' => $channel,
            'service_end_date' => $serviceEndDate,
            'delay_hours' => $delayHours,
            'review_link' => $reviewLink,
            'destination_platform' => $destinationPlatform,
            'status' => 'scheduled',
            'scheduled_send_at' => $scheduledAt,
            'source' => $source,
            'crm_deal_id' => $crmDealId,
        ]);
        $request->save();

        return $request;
    }

    /**
     * يحدد رابط التقييم الفعلي حسب الوجهة المختارة (Section 4) - كل
     * وجهة ليها رابط منفصل ممكن يتحدد في الإعدادات؛ لو مش محدد، بيرجع
     * لـ default_review_link كاحتياطي بدل ما يفشل فورًا. الروابط كلها
     * إدخال يدوي حقيقي من صاحب الموقع - مفيش توليد رابط Google تلقائي
     * (محتاج Place ID مش متوفر من التكامل الحالي).
     */
    private function resolveReviewLink(array $settings, string $destinationPlatform): string
    {
        if ($destinationPlatform === 'google_business' && !empty($settings['google_review_link'])) {
            return $settings['google_review_link'];
        }
        if ($destinationPlatform === 'tripadvisor' && !empty($settings['tripadvisor_review_link'])) {
            return $settings['tripadvisor_review_link'];
        }
        return $settings['default_review_link'] ?? '';
    }

    private function destinationLabel(string $destinationPlatform): string
    {
        $labels = ['google_business' => 'Google Business Profile', 'tripadvisor' => 'TripAdvisor', 'other' => 'أخرى'];
        return $labels[$destinationPlatform] ?? $destinationPlatform;
    }

    /**
     * هل هذا الضيف (برقم واتساب أو إيميل) موجود في قائمة عدم التواصل
     * الخاصة بالموقع ده؟ لازم واحد منهم على الأقل يتحدد.
     */
    public function isOptedOut(int $websiteId, ?string $guestPhone, ?string $guestEmail): bool
    {
        if (empty($guestPhone) && empty($guestEmail)) {
            return false;
        }

        $sql = "SELECT id FROM review_request_opt_outs WHERE website_id = ? AND (";
        $params = [$websiteId];
        $conditions = [];

        if (!empty($guestPhone)) {
            $conditions[] = "guest_phone = ?";
            $params[] = $guestPhone;
        }
        if (!empty($guestEmail)) {
            $conditions[] = "LOWER(guest_email) = LOWER(?)";
            $params[] = $guestEmail;
        }

        $sql .= implode(' OR ', $conditions) . ") LIMIT 1";
        $rows = $this->db->query($sql, $params);
        return !empty($rows);
    }

    /**
     * فحص تكرار: هل فيه طلب مراجعة اتعمل لنفس الضيف (نفس رقم/إيميل)
     * على نفس الموقع خلال آخر X ساعة ولسه مش ملغي/فاشل؟ (Section 14
     * Duplicate Protection) - بيتفحص قبل أي إنشاء (يدوي أو تلقائي).
     */
    public function findRecentDuplicate(int $websiteId, ?string $guestPhone, ?string $guestEmail, int $windowHours = self::DUPLICATE_WINDOW_HOURS): ?array
    {
        if (empty($guestPhone) && empty($guestEmail)) {
            return null;
        }

        $sql = "SELECT id, created_at, status FROM review_requests
                WHERE website_id = ? AND status NOT IN ('opted_out', 'failed')
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR) AND (";
        $params = [$websiteId, $windowHours];
        $conditions = [];

        if (!empty($guestPhone)) {
            $conditions[] = "guest_phone = ?";
            $params[] = $guestPhone;
        }
        if (!empty($guestEmail)) {
            $conditions[] = "LOWER(guest_email) = LOWER(?)";
            $params[] = $guestEmail;
        }

        $sql .= implode(' OR ', $conditions) . ") ORDER BY created_at DESC LIMIT 1";
        $rows = $this->db->query($sql, $params);
        return !empty($rows) ? $rows[0] : null;
    }

    /**
     * هل القناة دي فعلاً مهيأة لهذا الموقع؟ بيفحص التكامل الحقيقي بس
     * (PlatformConnection لواتساب/UltraMsg، Mailer العام للإيميل) -
     * ما فيش "Mock" أو افتراض اتصال غير موجود.
     */
    public function isChannelConfigured(int $websiteId, string $channel): bool
    {
        if ($channel === 'email') {
            return class_exists('Mailer') ? (new Mailer())->isConfigured() : false;
        }

        if ($channel === 'whatsapp') {
            if(WhatsAppCloudAPI::forWebsite($websiteId))return true;
            if (class_exists('WhatsAppQrGateway') && WhatsAppQrGateway::enabled() && (new PlatformConnection())->where(['website_id' => $websiteId, 'platform' => 'whatsapp_qr', 'status' => 'connected'], [], 1)) return true;
            $connection = (new PlatformConnection())->where([
                'website_id' => $websiteId,
                'platform' => 'ultramsg',
                'status' => 'connected',
            ], [], 1);
            return !empty($connection);
        }

        if ($channel === 'sms') {
            // G2: قناة SMS عبر CrmSmsService (Twilio) - إعدادات على مستوى
            // المنصة (SystemSettingsService). لو التكامل مش مهيأ، تُعتبر
            // القناة غير مفعّلة بخطأ واضح. غير مختبَر بمفتاح Twilio حقيقي.
            return class_exists('CrmSmsService') && (new CrmSmsService())->isConfigured();
        }

        return false;
    }

    /** رسالة "القناة غير مفعّلة" المناسبة لكل قناة (رسالة واضحة بلا Mock) */
    private function channelNotConfiguredMessage(string $channel): string
    {
        if ($channel === 'whatsapp') {
            return 'قناة واتساب غير مفعّلة لهذا الموقع - اربط واتساب من إعدادات الشات وواتساب الأول';
        }
        if ($channel === 'sms') {
            return 'قناة SMS غير مفعّلة - أضف بيانات Twilio (Account SID/Auth Token/رقم الإرسال) من إعدادات الأدمن';
        }
        return 'قناة الإيميل غير مفعّلة - Mailer غير مهيأ في النظام حاليًا';
    }

    /** حالة كل القنوات المتاحة لموقع معيّن - لعرضها في واجهة إنشاء الطلب */
    public function getChannelStatus(int $websiteId): array
    {
        return [
            'whatsapp' => $this->isChannelConfigured($websiteId, 'whatsapp') ? 'connected' : 'not_configured',
            'email' => $this->isChannelConfigured($websiteId, 'email') ? 'connected' : 'not_configured',
            'sms' => $this->isChannelConfigured($websiteId, 'sms') ? 'connected' : 'not_configured',
        ];
    }

    /**
     * حالة وجهات التقييم (Google Business / TripAdvisor) - بيقرأ نفس
     * جدول PlatformConnection المستخدم فعليًا في ReputationController،
     * من غير أي تكرار لمنطق OAuth نفسه.
     */
    public function getDestinationStatus(int $websiteId): array
    {
        $destinations = [];

        foreach (['google_business' => 'Google Business Profile', 'tripadvisor' => 'TripAdvisor'] as $platform => $label) {
            $connections = (new PlatformConnection())->where([
                'website_id' => $websiteId,
                'platform' => $platform,
                'status' => 'connected',
            ], [], 1);

            $destinations[] = [
                'platform' => $platform,
                'label' => $label,
                'connected' => !empty($connections),
                'location_name' => !empty($connections) ? $connections[0]->getAttribute('external_location_name') : null,
            ];
        }

        return $destinations;
    }

    /**
     * بحث عن Contacts موجودة فعليًا في الـ CRM بتاع نفس المستخدم
     * (Section 5: Customer Selection) - بدل ما العميل يكتب اسم/رقم/إيميل
     * يدوي كل مرة. بيرجع لكل contact حالة آخر طلب مراجعة (لو موجود)
     * بمطابقة الرقم/الإيميل الحقيقيين في review_requests، من غير أي
     * بيانات وهمية.
     */
    public function searchCrmContacts(int $userId, string $search = '', int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $where = "user_id = ?";
        $params = [$userId];

        if ($search !== '') {
            $where .= " AND (name LIKE ? OR phone LIKE ? OR email LIKE ?)";
            $term = '%' . $search . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $contacts = $this->db->query(
            "SELECT id, name, email, phone, source, notes FROM crm_contacts WHERE {$where} ORDER BY id DESC LIMIT ?",
            array_merge($params, [$limit])
        );

        foreach ($contacts as &$contact) {
            $lastRequest = null;
            if (!empty($contact['phone']) || !empty($contact['email'])) {
                $rows = $this->db->query(
                    "SELECT id, status, created_at FROM review_requests
                     WHERE user_id = ? AND (guest_phone = ? OR (guest_email IS NOT NULL AND LOWER(guest_email) = LOWER(?)))
                     ORDER BY created_at DESC LIMIT 1",
                    [$userId, $contact['phone'] ?? '', $contact['email'] ?? '']
                );
                $lastRequest = !empty($rows) ? $rows[0] : null;
            }
            $contact['previous_request'] = $lastRequest;
        }

        return $contacts;
    }

    /**
     * Smart Timing (Section 11) - النظام مش قادر يعرف فعليًا إن "العميل
     * راضٍ" أو "خلّص حجزه دلوقتي" من غير بيانات CRM موثوقة لده (مش
     * موجودة في المشروع الحالي)، فبدل ما نخترع إشارة رضا وهمية، بنستخدم
     * بيانات حقيقية متاحة فعلاً: توزيع delay_hours لطلبات وصلت فعلاً
     * لمرحلة "reviewed" في تاريخ نفس الموقع - يعطي فكرة استرشادية حقيقية
     * (مش سببية مضمونة) عن الوقت اللي الضيوف بيستجيبوا فيه غالبًا.
     */
    public function getSmartTimingSuggestion(int $websiteId): array
    {
        $rows = $this->db->query(
            "SELECT delay_hours FROM review_requests WHERE website_id = ? AND status = 'reviewed'",
            [$websiteId]
        );

        if (count($rows) < self::MIN_SAMPLE_FOR_ANALYTICS) {
            return ['not_enough_data' => true];
        }

        $avg = array_sum(array_column($rows, 'delay_hours')) / count($rows);
        return [
            'not_enough_data' => false,
            'suggested_delay_hours' => (int) round($avg),
            'sample_size' => count($rows),
        ];
    }

    /** كل قوالب الرسائل الجاهزة لموقع معيّن - بيزرع القوالب الافتراضية أول مرة لو مفيش أي قالب */
    public function getTemplates(int $websiteId): array
    {
        $templates = (new ReviewRequestTemplate())->where(['website_id' => $websiteId], ['id' => 'ASC']);

        if (empty($templates)) {
            foreach (ReviewRequestTemplate::defaultPresets() as $preset) {
                $template = new ReviewRequestTemplate();
                $template->fill(array_merge($preset, ['website_id' => $websiteId]));
                $template->save();
            }
            $templates = (new ReviewRequestTemplate())->where(['website_id' => $websiteId], ['id' => 'ASC']);
        }

        return array_map(fn ($t) => $t->toArray(), $templates);
    }

    /** إنشاء قالب مخصص جديد (Section 9) */
    public function createTemplate(int $websiteId, string $name, string $messageTemplate, ?string $emailSubject = null): ReviewRequestTemplate
    {
        if (trim($name) === '' || trim($messageTemplate) === '') {
            throw new Exception('اسم القالب ونص الرسالة مطلوبين');
        }

        $template = new ReviewRequestTemplate();
        $template->fill([
            'website_id' => $websiteId,
            'name' => $name,
            'preset_type' => 'custom',
            'message_template' => $messageTemplate,
            'email_subject' => $emailSubject,
        ]);
        $template->save();

        return $template;
    }

    /** حذف قالب - بشرط إنه بتاع نفس الموقع (Tenant Isolation) */
    public function deleteTemplate(int $templateId, int $websiteId): void
    {
        $template = (new ReviewRequestTemplate())->find($templateId);
        if (!$template || (int) $template->getAttribute('website_id') !== $websiteId) {
            throw new Exception('القالب غير موجود');
        }
        $template->delete();
    }

    /**
     * إنشاء طلب مراجعة تلقائي لما صفقة CRM تتقفل "مكسوبة" - بس لو
     * العميل مفعّل الخيار ده في إعدادات الحملة. بيستخدم أول موقع للمستخدم
     * (الصفقات مش مرتبطة بموقع معيّن حاليًا)، وبيتجاهل بهدوء لو مفيش
     * موقع أو مفيش وسيلة تواصل صالحة للـ contact (رقم أو إيميل) - مش
     * لازم يوقف نقل الصفقة نفسها بسبب طلب المراجعة (Section 12:
     * Automation Trigger = After Deal Won).
     *
     * الأولوية لقناة واتساب لو الرقم متاح ومهيأ، وإلا الإيميل لو متاح
     * ومهيأ - بدون اختراع قناة تالتة.
     */
    public function maybeCreateFromCrmDeal(int $userId, string $guestName, ?string $guestPhone, ?string $guestEmail = null, ?int $dealId = null): ?ReviewRequest
    {
        if (empty($guestPhone) && empty($guestEmail)) {
            return null;
        }

        try {
            $websites = (new Website())->where(['user_id' => $userId], ['created_at' => 'ASC'], 1);
            if (empty($websites)) {
                return null;
            }
            $websiteId = (int) $websites[0]->getAttribute('id');

            $settings = $this->getSettings($websiteId);
            if (empty($settings['auto_from_crm_won']) || empty($settings['default_review_link'])) {
                return null;
            }

            $channel = null;
            if (!empty($guestPhone) && $this->isChannelConfigured($websiteId, 'whatsapp')) {
                $channel = 'whatsapp';
            } elseif (!empty($guestEmail) && $this->isChannelConfigured($websiteId, 'email')) {
                $channel = 'email';
            } elseif (!empty($guestPhone) && $this->isChannelConfigured($websiteId, 'sms')) {
                $channel = 'sms';
            }

            if ($channel === null) {
                Logger::warning('maybeCreateFromCrmDeal: no configured channel available', ['user_id' => $userId, 'website_id' => $websiteId]);
                return null;
            }

            return $this->createRequest(
                $userId,
                $websiteId,
                $guestName,
                in_array($channel, ['whatsapp', 'sms'], true) ? $guestPhone : null,
                date('Y-m-d H:i:s'),
                $channel,
                $channel === 'email' ? $guestEmail : null,
                'crm',
                $dealId
            );
        } catch (Exception $e) {
            Logger::error('maybeCreateFromCrmDeal Error', ['user_id' => $userId, 'message' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * لما مراجعة جديدة توصل من أي منصة (Google، TripAdvisor...)، بنفحص
     * لو اسمها بيطابق اسم ضيف عندنا طلب مراجعة "اتبعت" أو "اتبعتله
     * تذكير" لسه مايتقفلش - لو كده، نعلّمه "قيّم" تلقائيًا، ونربطه
     * بالـreview الحقيقي (matched_review_id) لو الـid اتبعت (Section 20:
     * Review Attribution). المطابقة بالاسم بس (مش مضمونة 100% لو حد
     * قيّم باسم مختلف عن اللي كتبناه)، فده تحسين إضافي مش مصدر حقيقة
     * أساسي - العميل لسه يقدر يشوف كل الطلبات وحالتها الحقيقية بنفسه.
     *
     * @param int|null $reviewId معرف السجل الحقيقي في جدول reviews (لو متاح) لربط Attribution.
     */
    public function markReviewedIfMatching(int $websiteId, string $reviewerName, ?int $reviewId = null): void
    {
        $reviewerName = trim($reviewerName);
        if ($reviewerName === '') {
            return;
        }

        $matches = $this->db->query(
            "SELECT id FROM review_requests
             WHERE website_id = ? AND status IN ('sent', 'reminded') AND LOWER(guest_name) = LOWER(?)
             LIMIT 1",
            [$websiteId, $reviewerName]
        );

        if (empty($matches)) {
            return;
        }

        $request = (new ReviewRequest())->find((int) $matches[0]['id']);
        if ($request) {
            $request->setAttribute('status', 'reviewed');
            $request->setAttribute('reviewed_at', date('Y-m-d H:i:s'));
            if ($reviewId !== null) {
                $request->setAttribute('matched_review_id', $reviewId);
            }
            $request->save();
        }
    }

    /**
     * كل طلبات موقع معيّن مع فلاتر/بحث/صفحات حقيقية (Section 22).
     * @param array $filters status, channel, search (اسم/رقم/إيميل), date_from, date_to
     * @return array{items: ReviewRequest[], total:int, page:int, per_page:int}
     */
    public function getRequestsFiltered(int $websiteId, array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ["website_id = ?"];
        $params = [$websiteId];

        if (!empty($filters['status'])) {
            $where[] = "status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['channel'])) {
            $where[] = "channel = ?";
            $params[] = $filters['channel'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = "created_at >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = "created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['search'])) {
            $where[] = "(guest_name LIKE ? OR guest_phone LIKE ? OR guest_email LIKE ?)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $whereSql = implode(' AND ', $where);

        $totalRow = $this->db->query("SELECT COUNT(*) AS c FROM review_requests WHERE {$whereSql}", $params);
        $total = !empty($totalRow) ? (int) $totalRow[0]['c'] : 0;

        $rows = $this->db->query(
            "SELECT * FROM review_requests WHERE {$whereSql} ORDER BY created_at DESC LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
        );

        $items = array_map(fn ($row) => new ReviewRequest($row), $rows);

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** طلب واحد بالتفصيل - مع التأكد إنه بتاع نفس الموقع (Tenant Isolation) */
    public function getRequest(int $requestId, int $websiteId): ?ReviewRequest
    {
        $request = (new ReviewRequest())->find($requestId);
        if (!$request || (int) $request->getAttribute('website_id') !== $websiteId) {
            return null;
        }
        return $request;
    }

    /**
     * إلغاء طلب لسه ما اتبعتش (لو الضيف طلب مايتواصلش معاه مثلاً)، مع
     * تسجيله في قائمة Opt-Out الدائمة (Section 15) عشان يمنع أي طلب
     * جديد ليه مستقبلاً - مش بس يلغي الطلب الحالي.
     */
    public function optOut(int $requestId, ?string $reason = null): void
    {
        $request = (new ReviewRequest())->find($requestId);
        if (!$request) {
            throw new Exception('الطلب غير موجود');
        }
        if ($request->getAttribute('status') !== 'scheduled') {
            throw new Exception('لا يمكن إلغاء طلب اتبعت بالفعل');
        }

        $request->setAttribute('status', 'opted_out');
        $request->save();

        $optOut = new ReviewRequestOptOut();
        $optOut->fill([
            'website_id' => (int) $request->getAttribute('website_id'),
            'guest_phone' => $request->getAttribute('guest_phone'),
            'guest_email' => $request->getAttribute('guest_email'),
            'reason' => $reason,
            'source_request_id' => $requestId,
        ]);
        $optOut->save();
    }

    /**
     * إعادة محاولة إرسال طلب فشل (Section 19) - محاولة فورية (مش مستنية
     * الـ cron)، بحد أقصى MAX_SEND_ATTEMPTS محاولة إجمالية (تلقائية +
     * يدوية) عشان نمنع Infinite Retry. لو نجحت، الحالة بترجع 'sent'
     * وبيتحدث sent_at وerror_message بيتمسح.
     */
    public function retryRequest(int $requestId, int $websiteId): ReviewRequest
    {
        $request = $this->getRequest($requestId, $websiteId);
        if (!$request) {
            throw new Exception('الطلب غير موجود');
        }
        if ($request->getAttribute('status') !== 'failed') {
            throw new Exception('لا يمكن إعادة المحاولة إلا لطلب فشل إرساله');
        }
        if ((int) $request->getAttribute('attempts') >= self::MAX_SEND_ATTEMPTS) {
            throw new Exception('تم الوصول للحد الأقصى لمحاولات الإرسال (' . self::MAX_SEND_ATTEMPTS . ') لهذا الطلب');
        }

        $channel = (string) ($request->getAttribute('channel') ?: 'whatsapp');
        $recipient = $channel === 'email' ? $request->getAttribute('guest_email') : $request->getAttribute('guest_phone');

        if (empty($recipient)) {
            throw new Exception('لا يوجد مستلم صالح لقناة ' . $channel);
        }
        if (!$this->isChannelConfigured($websiteId, $channel)) {
            throw new Exception($this->channelNotConfiguredMessage($channel));
        }

        $settings = $this->getSettings($websiteId);
        $message = $this->renderTemplate($settings['message_template'], (string) $request->getAttribute('guest_name'), (string) $request->getAttribute('review_link'));

        $sent = $this->sendByChannel($websiteId, $channel, $recipient, $message);
        $request->setAttribute('attempts', (int) $request->getAttribute('attempts') + 1);

        if ($sent) {
            $request->setAttribute('status', 'sent');
            $request->setAttribute('sent_at', date('Y-m-d H:i:s'));
            $request->setAttribute('error_message', null);
        } else {
            $request->setAttribute('error_message', $this->channelSendFailedMessage($channel));
        }
        $request->save();

        return $request;
    }

    /**
     * تعديل طلب لسه ما اتبعتش (Section 6/24) - مسموح بس لو status = scheduled
     * (بعد الإرسال مفيش تعديل، الأثر (Attribution/Timeline) لازم يفضل
     * حقيقي). بيعيد فحص القناة/التكرار/Opt-Out زي الإنشاء بالظبط، وبيعيد
     * حساب موعد الإرسال لو service_end_date اتغيّر.
     */
    public function updateRequest(int $requestId, int $websiteId, array $data): ReviewRequest
    {
        $request = $this->getRequest($requestId, $websiteId);
        if (!$request) {
            throw new Exception('الطلب غير موجود');
        }
        if ($request->getAttribute('status') !== 'scheduled') {
            throw new Exception('لا يمكن تعديل طلب اتبعت بالفعل - يمكنك إلغاؤه فقط');
        }

        $channel = (string) ($data['channel'] ?? $request->getAttribute('channel'));
        if (!in_array($channel, ['whatsapp', 'email', 'sms'], true)) {
            throw new Exception('قناة غير مدعومة');
        }

        $guestName = (string) ($data['guest_name'] ?? $request->getAttribute('guest_name'));
        $guestPhone = array_key_exists('guest_phone', $data) ? $data['guest_phone'] : $request->getAttribute('guest_phone');
        $guestEmail = array_key_exists('guest_email', $data) ? $data['guest_email'] : $request->getAttribute('guest_email');
        $guestPhone = $guestPhone !== null ? preg_replace('/[^0-9]/', '', (string) $guestPhone) : null;
        $guestEmail = $guestEmail !== null ? trim((string) $guestEmail) : null;

        if (in_array($channel, ['whatsapp', 'sms'], true) && empty($guestPhone)) {
            throw new Exception($channel === 'whatsapp' ? 'رقم واتساب مطلوب لقناة واتساب' : 'رقم الهاتف مطلوب لقناة SMS');
        }
        if ($channel === 'email' && empty($guestEmail)) {
            throw new Exception('إيميل الضيف مطلوب لقناة الإيميل');
        }
        if ($channel === 'email' && !filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('صيغة الإيميل غير صحيحة');
        }
        if (!$this->isChannelConfigured($websiteId, $channel)) {
            throw new Exception($this->channelNotConfiguredMessage($channel));
        }
        if ($this->isOptedOut($websiteId, $guestPhone, $guestEmail)) {
            throw new Exception('هذا الضيف طلب عدم التواصل معه سابقًا (Opt-Out)');
        }

        // فحص تكرار - باستثناء الطلب الحالي نفسه من نتيجة الفحص
        $duplicate = $this->findRecentDuplicate($websiteId, $guestPhone, $guestEmail);
        if ($duplicate && (int) $duplicate['id'] !== $requestId) {
            throw new Exception('يوجد طلب مراجعة حديث آخر لنفس الضيف (رقم #' . $duplicate['id'] . ')');
        }

        $destinationPlatform = (string) ($data['destination_platform'] ?? $request->getAttribute('destination_platform') ?: 'other');
        if (!in_array($destinationPlatform, ['google_business', 'tripadvisor', 'other'], true)) {
            throw new Exception('وجهة تقييم غير مدعومة');
        }

        $serviceEndDate = (string) ($data['service_end_date'] ?? $request->getAttribute('service_end_date'));
        $settings = $this->getSettings($websiteId);
        $delayHours = (int) $settings['default_delay_hours'];
        $scheduledAt = date('Y-m-d H:i:s', strtotime($serviceEndDate) + ($delayHours * 3600));
        $reviewLink = $this->resolveReviewLink($settings, $destinationPlatform);

        if (empty($reviewLink)) {
            throw new Exception('لازم تحدّد رابط تقييم لوجهة "' . $this->destinationLabel($destinationPlatform) . '" في إعدادات الحملة الأول');
        }

        $request->fill([
            'guest_name' => $guestName,
            'guest_phone' => $guestPhone,
            'guest_email' => $guestEmail,
            'channel' => $channel,
            'service_end_date' => $serviceEndDate,
            'scheduled_send_at' => $scheduledAt,
            'destination_platform' => $destinationPlatform,
            'review_link' => $reviewLink,
        ]);
        $request->save();

        return $request;
    }

    /**
     * بتتنفّذ من الـ cron كل شوية: تبعت أي طلبات "مستحقة" (موعدها وصل)،
     * وتبعت تذكير للي اتبعتلهم من زمان وماردّوش. بتدعم القناتين
     * (واتساب/إيميل) - بتختار المستلم والقناة الصحيحة من عمود channel
     * الفعلي المخزّن مع كل طلب وقت الإنشاء.
     */
    /**
     * إرسال طلب تقييم واحد مستحق (مشترك بين الـ cron والموافقة من مركز الأتمتة).
     * @param string|null $messageOverride نص الرسالة لو العميل عدّله وهو بيوافق
     */
    public function sendScheduledRequest(array $row, ?string $messageOverride = null): bool
    {
        $settings = $this->getSettings((int) $row['website_id']);
        $channel = $row['channel'] ?? 'whatsapp';
        $recipient = $channel === 'email' ? $row['guest_email'] : $row['guest_phone'];

        $request = (new ReviewRequest())->find((int) $row['id']);

        if (empty($recipient)) {
            $request->setAttribute('status', 'failed');
            $request->setAttribute('error_message', 'لا يوجد مستلم صالح لقناة ' . $channel);
            $request->save();
            return false;
        }

        $link = $this->trackedLink($row);
        $message = ($messageOverride !== null && trim($messageOverride) !== '')
            ? str_replace((string) $row['review_link'], $link, $messageOverride)
            : $this->renderTemplate($settings['message_template'], $row['guest_name'], $link);
        $sent = $this->sendByChannel((int) $row['website_id'], $channel, $recipient, $message,$row);
        $request->setAttribute('attempts', (int) $row['attempts'] + 1);

        if ($sent) {
            $request->setAttribute('status', 'sent');
            $request->setAttribute('sent_at', date('Y-m-d H:i:s'));
        } else {
            $request->setAttribute('status', 'failed');
            $request->setAttribute('error_message', $this->channelSendFailedMessage($channel));
        }
        $request->save();
        return (bool) $sent;
    }

    /**
     * رابط متتبَّع: /rr/{token} بيسجّل إن الضيف فتح الرابط وبعدين يحوّله لصفحة التقييم.
     * كده تعرف مين فتح فعلاً، ومانبعتش تذكير لحد فتح خلاص.
     */
    public function trackedLink(array $row): string
    {
        $base = defined('APP_URL') ? rtrim((string) APP_URL, '/') : '';
        if ($base === '' || empty($row['id'])) {
            return (string) $row['review_link'];
        }
        $token = (string) ($row['track_token'] ?? '');
        if ($token === '') {
            try {
                $token = bin2hex(random_bytes(10));
                $this->db->exec('UPDATE review_requests SET track_token = ? WHERE id = ? AND track_token IS NULL', [$token, (int) $row['id']]);
                $r = $this->db->query('SELECT track_token FROM review_requests WHERE id = ?', [(int) $row['id']]);
                $token = (string) ($r[0]['track_token'] ?? '');
            } catch (Throwable $e) {
                return (string) $row['review_link']; // العمود لسه مش موجود (التحديث ماتطبقش)
            }
        }
        return $token !== '' ? $base . '/rr/' . $token : (string) $row['review_link'];
    }

    /** يسجّل الضغطة ويرجّع رابط التقييم الحقيقي (أو null لو التوكن غلط) */
    public function registerClick(string $token): ?string
    {
        if (!preg_match('/^[a-f0-9]{20}$/', $token)) {
            return null;
        }
        $r = $this->db->query('SELECT id, review_link FROM review_requests WHERE track_token = ? LIMIT 1', [$token]);
        if (!$r) {
            return null;
        }
        $this->db->exec('UPDATE review_requests SET click_count = click_count + 1, clicked_at = COALESCE(clicked_at, NOW()) WHERE id = ?', [(int) $r[0]['id']]);
        $link = (string) $r[0]['review_link'];
        return filter_var($link, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $link) ? $link : null;
    }

    /**
     * طلبات تقييم تلقائية من الحجوزات: أي حجز مؤكد/مكتمل يوم رحلته عدّى (آخر 3 أيام بس،
     * عشان تفعيل الخاصية مايبعتش لحجوزات قديمة) - مرة واحدة لكل حجز.
     * @return int عدد الطلبات اللي اتعملت
     */
    public function createFromRecentBookings(): int
    {
        try {
            $rows = $this->db->query(
                "SELECT b.id, b.user_id, b.customer_name, b.customer_phone, b.customer_email, b.start_date
                   FROM bookings b
                  WHERE b.status IN ('confirmed','completed')
                    AND b.start_date BETWEEN ? AND ?
                    AND (COALESCE(b.customer_phone,'') <> '' OR COALESCE(b.customer_email,'') <> '')
                    AND NOT EXISTS (SELECT 1 FROM review_requests r WHERE r.booking_id = b.id)
                  ORDER BY b.id LIMIT 200",
                // بتوقيت التطبيق مش توقيت قاعدة البيانات (UTC) عشان "امبارح" تبقى امبارح عند العميل
                [date('Y-m-d', strtotime('-3 days')), date('Y-m-d', strtotime('-1 day'))]
            );
        } catch (Throwable $e) {
            return 0; // عمود booking_id لسه مش موجود
        }
        $made = 0;
        $siteFor = [];
        foreach ($rows as $b) {
            $uid = (int) $b['user_id'];
            if (!array_key_exists($uid, $siteFor)) {
                $w = (new Website())->where(['user_id' => $uid], ['created_at' => 'ASC'], 1);
                $siteFor[$uid] = $w ? (int) $w[0]->getAttribute('id') : 0;
            }
            $websiteId = $siteFor[$uid];
            if ($websiteId <= 0) {
                continue;
            }
            $settings = $this->getSettings($websiteId);
            if (empty($settings['auto_from_bookings']) || empty($settings['is_enabled'])) {
                continue;
            }
            $phone = (string) ($b['customer_phone'] ?? '');
            $email = (string) ($b['customer_email'] ?? '');
            $channel = null;
            if ($phone !== '' && $this->isChannelConfigured($websiteId, 'whatsapp')) {
                $channel = 'whatsapp';
            } elseif ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && $this->isChannelConfigured($websiteId, 'email')) {
                $channel = 'email';
            }
            if ($channel === null) {
                continue;
            }
            $dest = !empty($settings['google_review_link']) ? 'google_business' : (!empty($settings['tripadvisor_review_link']) ? 'tripadvisor' : 'other');
            try {
                $req = $this->createRequest($uid, $websiteId, (string) $b['customer_name'],
                    $channel === 'whatsapp' ? $phone : null,
                    $b['start_date'] . ' 20:00:00', $channel,
                    $channel === 'email' ? $email : null, 'booking', null, $dest);
                $this->db->exec('UPDATE review_requests SET booking_id = ? WHERE id = ?', [(int) $b['id'], (int) $req->getAttribute('id')]);
                $made++;
            } catch (ReputationLimitReached $limit) {
                // Monthly plan limit reached: do not mark the booking, it is retried when the limit allows again
                continue;
            } catch (Throwable $e) {
                // مكرر/opt-out/مفيش رابط: نعلّم الحجز عشان مانحاولش كل ربع ساعة
                Logger::info('review request from booking skipped', ['booking_id' => $b['id'], 'reason' => $e->getMessage()]);
                try {
                    $this->db->exec(
                        "INSERT INTO review_requests (user_id, website_id, guest_name, channel, service_end_date, delay_hours, review_link, destination_platform, status, source, booking_id, error_message)
                         VALUES (?, ?, ?, ?, ?, 0, '', 'other', 'failed', 'booking', ?, ?)",
                        [$uid, $websiteId, mb_substr((string) $b['customer_name'], 0, 150), $channel, $b['start_date'] . ' 20:00:00', (int) $b['id'], mb_substr('اتخطّى: ' . $e->getMessage(), 0, 255)]
                    );
                } catch (Throwable $e2) {
                }
            }
        }
        return $made;
    }

    public function processDueRequests(): array
    {
        $sentCount = 0;
        $remindedCount = 0;
        $failedCount = 0;

        // 1) طلبات جديدة مستحقة الإرسال
        $due = $this->db->query(
            "SELECT * FROM review_requests WHERE status = 'scheduled' AND scheduled_send_at <= NOW() LIMIT 50"
        );

        foreach ($due as $row) {
            // مركز الأتمتة: الطلب يتبعت لوحده، أو يستنى موافقة، أو يتلغي
            $gate = null;
            $engine = null;
            if (class_exists('AutomationEngine')) {
                $engine = AutomationEngine::instance();
                $gate = $engine->gate(
                    (int) $row['user_id'],
                    (int) $row['website_id'],
                    'review_requests',
                    in_array($row['channel'] ?? 'whatsapp', ['whatsapp', 'email', 'sms'], true) ? ($row['channel'] ?? 'whatsapp') : 'whatsapp',
                    'review_request',
                    (string) $row['id'],
                    [
                        'title' => (string) ($row['guest_name'] ?? ''),
                        'context' => 'انتهاء الخدمة: ' . (string) ($row['service_end_date'] ?? ''),
                        'draft' => $this->renderTemplate($this->getSettings((int) $row['website_id'])['message_template'], $row['guest_name'], $row['review_link']),
                    ]
                );
                if ($gate['action'] === 'wait') {
                    continue;
                }
                if ($gate['action'] === 'skip') {
                    if (in_array($gate['reason'] ?? '', ['rejected', 'manual_mode', 'expired'], true)) {
                        $request = (new ReviewRequest())->find((int) $row['id']);
                        $request->setAttribute('status', 'failed');
                        $request->setAttribute('error_message', [
                            'rejected' => 'اتلغى من مركز الأتمتة',
                            'expired' => 'انتهت مهلة الموافقة في مركز الأتمتة',
                        ][$gate['reason']] ?? 'الإرسال التلقائي متوقف (وضع يدوي)');
                        $request->save();
                    }
                    continue;
                }
            }

            $ok = $this->sendScheduledRequest($row, $gate['final_text'] ?? null);
            if ($gate && $engine) {
                $engine->complete($gate['decision_id'] ?? null, $ok, $ok ? null : 'تعذر الإرسال');
            }
            $ok ? $sentCount++ : $failedCount++;
        }

        // 2) تذكيرات لطلبات اتبعتت من زمان (حسب reminder_after_hours لكل موقع)
        //    الفلترة في SQL (قبل كده كان بيجيب أول 200 بس، فلو عندك أكتر الباقي مايوصلوش تذكير أبدًا)
        //    واللي فتح الرابط خلاص مش محتاج تذكير.
        $sentRequests = $this->db->query(
            "SELECT r.* FROM review_requests r
               LEFT JOIN review_request_settings st ON st.website_id = r.website_id
              WHERE r.status = 'sent' AND r.sent_at IS NOT NULL
                AND r.clicked_at IS NULL
                AND COALESCE(st.reminder_enabled, 1) = 1
                AND r.sent_at <= (NOW() - INTERVAL COALESCE(st.reminder_after_hours, 48) HOUR)
              ORDER BY r.sent_at ASC LIMIT 200"
        );

        foreach ($sentRequests as $row) {
            $settings = $this->getSettings((int) $row['website_id']);
            if (empty($settings['reminder_enabled'])) {
                continue;
            }

            $reminderDue = strtotime($row['sent_at']) + ((int) $settings['reminder_after_hours'] * 3600);
            if (time() < $reminderDue) {
                continue;
            }

            $channel = $row['channel'] ?? 'whatsapp';
            $recipient = $channel === 'email' ? $row['guest_email'] : $row['guest_phone'];
            if (empty($recipient)) {
                continue;
            }

            if ($this->isOptedOut((int) $row['website_id'], $row['guest_phone'] ?? null, $row['guest_email'] ?? null)) {
                continue;
            }
            $message = $this->renderTemplate($settings['reminder_template'], $row['guest_name'], $this->trackedLink($row));
            $row['wa_reminder']=true;
            $sent = $this->sendByChannel((int) $row['website_id'], $channel, $recipient, $message,$row);

            $request = (new ReviewRequest())->find((int) $row['id']);
            if ($sent) {
                $request->setAttribute('status', 'reminded');
                $request->setAttribute('reminded_at', date('Y-m-d H:i:s'));
                $remindedCount++;
                $request->save();
            }
        }

        return ['sent' => $sentCount, 'reminded' => $remindedCount, 'failed' => $failedCount];
    }

    /**
     * إرسال رسالة عبر القناة الصحيحة - واتساب/إيميل عبر ChatManager
     * (الموجود أصلًا)، وSMS عبر CrmSmsService (Twilio) كقناة إضافية (G2).
     * الرجوع: true فقط عند نجاح فعلي؛ أي فشل (تكامل غير مهيأ/تعذر الاتصال)
     * يُسجَّل ويرجع false — لا Mock أبدًا.
     */
    private function sendByChannel(int $websiteId, string $channel, string $recipient, string $message,array $request=[]): bool
    {
        if ($channel === 'sms') {
            if (!class_exists('CrmSmsService')) {
                return false;
            }
            $result = (new CrmSmsService())->sendTextMessage($recipient, $message);
            return !empty($result['success']);
        }

        if($channel==='whatsapp'&&WhatsAppCloudAPI::connectionRow($websiteId)){
            $w=$this->db->query('SELECT user_id,company_name FROM websites WHERE id=?',[$websiteId])[0]??null;if(!$w||empty($request['id'])||(int)$request['user_id']!==(int)$w['user_id'])return false;
            $lang=ContentLanguageService::forWebsite($websiteId,(int)$w['user_id']);
            $result=WhatsAppTransactionalService::send((int)$w['user_id'],$websiteId,$recipient,'tf_review_request',$lang,[$request['guest_name']??'Guest',$w['company_name']??'Tour operator',$request['review_link']??''],'review:'.$request['id'].(!empty($request['wa_reminder'])?':reminder':':initial'));
            return !empty($result['ok']);
        }
        return $this->chatManager->sendMessageForWebsite($websiteId, $recipient, $message, $channel);
    }

    /** رسالة فشل الإرسال الواضحة لكل قناة (تُحفظ في error_message) */
    private function channelSendFailedMessage(string $channel): string
    {
        if ($channel === 'whatsapp') {
            return 'تعذر إرسال الرسالة - تأكد من ربط واتساب للموقع ده';
        }
        if ($channel === 'sms') {
            return 'تعذر إرسال SMS - تأكد من إعدادات Twilio في لوحة الأدمن';
        }
        return 'تعذر إرسال الإيميل - تأكد من إعدادات Mailer';
    }

    /**
     * استبدال Variables في القالب - {{customer_name}}/{name} و
     * {{review_link}}/{review_link} مدعومين معًا للتوافق مع القوالب
     * القديمة، مع Sanitization بسيط (إزالة أي HTML tags من القيم قبل
     * الحقن، لأن الرسالة ممكن تتبعت كـ HTML عبر قناة الإيميل).
     */
    private function renderTemplate(string $template, string $name, string $reviewLink): string
    {
        $safeName = strip_tags($name);
        $safeLink = filter_var($reviewLink, FILTER_VALIDATE_URL) ? $reviewLink : strip_tags($reviewLink);

        return str_replace(
            ['{name}', '{{customer_name}}', '{review_link}', '{{review_link}}'],
            [$safeName, $safeName, $safeLink, $safeLink],
            $template
        );
    }

    /** إحصائيات سريعة لموقع معيّن (لعرضها في لوحة العميل) */
    public function getStats(int $websiteId): array
    {
        $rows = $this->db->query(
            "SELECT status, COUNT(*) AS c FROM review_requests WHERE website_id = ? GROUP BY status",
            [$websiteId]
        );
        $stats = ['scheduled' => 0, 'sent' => 0, 'reminded' => 0, 'reviewed' => 0, 'opted_out' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $stats[$row['status']] = (int) $row['c'];
        }
        $stats['total_sent'] = $stats['sent'] + $stats['reminded'] + $stats['reviewed'];
        try {
            $c = $this->db->query('SELECT COUNT(*) AS c FROM review_requests WHERE website_id = ? AND clicked_at IS NOT NULL', [$websiteId]);
            $stats['clicked'] = (int) ($c[0]['c'] ?? 0);
        } catch (Throwable $e) {
            $stats['clicked'] = 0;
        }
        return $stats;
    }

    /**
     * Analytics حقيقي (Section 21) - أي مقياس محتاج عينة كافية
     * (MIN_SAMPLE_FOR_ANALYTICS) بيترجع not_enough_data:true بدل ما
     * يعرض نسبة مضللة من عدد قليل جدًا من الطلبات.
     */
    public function getAnalytics(int $websiteId): array
    {
        $stats = $this->getStats($websiteId);
        $totalSent = $stats['total_sent'];
        $reviewed = $stats['reviewed'];

        $analytics = [
            'requests_sent' => $totalSent,
            'requests_completed' => $reviewed,
            'conversion_rate' => null,
            'avg_time_to_review_hours' => null,
            'channel_performance' => [],
            'not_enough_data' => $totalSent < self::MIN_SAMPLE_FOR_ANALYTICS,
        ];

        if ($totalSent >= self::MIN_SAMPLE_FOR_ANALYTICS) {
            $analytics['conversion_rate'] = round(($reviewed / $totalSent) * 100, 1);
        }

        // متوسط الوقت من الإرسال للتقييم - فقط من طلبات ليها sent_at و
        // reviewed_at حقيقيين الاتنين، من غير أي تقدير/اختراع
        $timeRows = $this->db->query(
            "SELECT TIMESTAMPDIFF(HOUR, sent_at, reviewed_at) AS hours_diff
             FROM review_requests
             WHERE website_id = ? AND sent_at IS NOT NULL AND reviewed_at IS NOT NULL",
            [$websiteId]
        );
        if (count($timeRows) >= self::MIN_SAMPLE_FOR_ANALYTICS) {
            $sum = array_sum(array_column($timeRows, 'hours_diff'));
            $analytics['avg_time_to_review_hours'] = round($sum / count($timeRows), 1);
        }

        // أداء كل قناة على حدة
        $channelRows = $this->db->query(
            "SELECT channel,
                    COUNT(*) AS total,
                    SUM(CASE WHEN status IN ('sent','reminded','reviewed') THEN 1 ELSE 0 END) AS sent_count,
                    SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) AS reviewed_count
             FROM review_requests WHERE website_id = ? GROUP BY channel",
            [$websiteId]
        );
        foreach ($channelRows as $row) {
            $channelTotal = (int) $row['sent_count'];
            $analytics['channel_performance'][] = [
                'channel' => $row['channel'],
                'total_requests' => (int) $row['total'],
                'sent' => $channelTotal,
                'reviewed' => (int) $row['reviewed_count'],
                'conversion_rate' => $channelTotal >= self::MIN_SAMPLE_FOR_ANALYTICS
                    ? round(((int) $row['reviewed_count'] / $channelTotal) * 100, 1)
                    : null,
                'not_enough_data' => $channelTotal < self::MIN_SAMPLE_FOR_ANALYTICS,
            ];
        }

        return $analytics;
    }

    /**
     * مساعد AI لصياغة رسالة الطلب (Section 10) - بيستخدم GeminiClient
     * الموجود فعليًا في المشروع (نفس مفتاح Gemini المُهيّأ من لوحة
     * الأدمن). الهدف طلب Feedback حقيقي ومحايد فقط - الـ prompt نفسه
     * بيمنع صراحة أي طلب لتقييم إيجابي/مزيف.
     *
     * @param string $action generate|rewrite|shorten|professional|translate
     */
    public function aiAssistMessage(string $action, string $currentText, array $context = []): array
    {
        if (!class_exists('GeminiClient')) {
            return ['success' => false, 'error' => 'خدمة الذكاء الاصطناعي غير متاحة في هذا المشروع'];
        }

        $allowedActions = ['generate', 'rewrite', 'shorten', 'professional', 'translate'];
        if (!in_array($action, $allowedActions, true)) {
            return ['success' => false, 'error' => 'إجراء غير مدعوم'];
        }

        $businessName = strip_tags((string) ($context['business_name'] ?? ''));
        $targetLanguage = strip_tags((string) ($context['target_language'] ?? 'ar'));
        $safeCurrentText = strip_tags($currentText);

        $baseRules = "أنت تكتب رسالة قصيرة تطلب من عميل تقييم تجربته بصدق وحياد. "
            . "ممنوع تمامًا أن تطلب تقييمًا إيجابيًا أو مزيفًا أو أن توحي بذلك. "
            . "استخدم المتغيرين {name} و {review_link} بالضبط كما هما في مكانهما المناسب. "
            . "اكتب الرسالة فقط بدون أي شرح أو مقدمة.";

        $prompts = [
            'generate' => "{$baseRules}\nاسم الشركة: {$businessName}\nاكتب رسالة ودودة لطلب تقييم بعد انتهاء الخدمة.",
            'rewrite' => "{$baseRules}\nأعد صياغة هذه الرسالة بأسلوب مختلف مع الحفاظ على نفس المعنى:\n{$safeCurrentText}",
            'shorten' => "{$baseRules}\nاختصر هذه الرسالة قدر الإمكان مع الحفاظ على المتغيرات:\n{$safeCurrentText}",
            'professional' => "{$baseRules}\nأعد صياغة هذه الرسالة بأسلوب أكثر احترافية:\n{$safeCurrentText}",
            'translate' => "{$baseRules}\nترجم هذه الرسالة إلى اللغة ({$targetLanguage}) مع الحفاظ على المتغيرات:\n{$safeCurrentText}",
        ];

        try {
            $client = new GeminiClient();
            $result = $client->generateContent($prompts[$action], ['task'=>'review_content']);

            if (empty($result['success']) || empty($result['data'])) {
                return ['success' => false, 'error' => $result['error'] ?? 'تعذر توليد النص حاليًا'];
            }

            return ['success' => true, 'text' => trim(strip_tags($result['data']))];
        } catch (Exception $e) {
            Logger::error('aiAssistMessage Error', ['message' => $e->getMessage()]);
            return ['success' => false, 'error' => 'تعذر توليد النص حاليًا'];
        }
    }

    /**
     * تصدير CSV لطلبات موقع معيّن مع نفس الفلاتر المستخدمة في القائمة
     * (Section 23) - بيرجع الصفوف فقط، الـ Controller مسؤول عن الـ
     * headers/streaming (نفس نمط AdminController::exportUsers الموجود).
     */
    public function getRequestsForExport(int $websiteId, array $filters = []): array
    {
        $result = $this->getRequestsFiltered($websiteId, $filters, 1, 5000);
        return array_map(fn (ReviewRequest $r) => $r->toArray(), $result['items']);
    }
}
