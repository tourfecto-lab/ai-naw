<?php

/**
 * Tourfecto - Email Platform Bridge (ربط التسويق بالبريد بباقي خدمات المنصة)
 * @version 1.0.0
 *
 * الموديول كان معزول: الحجوزات والـ CRM ومحرك الحجز مكانوش بيوصلوا للبريد
 * خالص، وأعمدة الإيراد المنسوب (attributed_revenue) متضافة من غير ما أي كود
 * يملاها. الكلاس ده هو نقطة الربط الوحيدة:
 *
 *   1) أحداث الحجز (BookingEngine): booking_created / booking_confirmed /
 *      booking_cancelled → مشغّلات أتمتة بسياق الحجز ({{trip_name}}، {{trip_date}}...).
 *   2) مشغّلات الرحلة (cron): trip_upcoming (قبل الرحلة بـ N يوم) /
 *      trip_completed (بعد الرحلة بـ N يوم) — مرة واحدة لكل حجز.
 *   3) أحداث CRM (CrmAutomationService::trigger): crm_lead_created / crm_deal_won.
 *   4) إسناد الإيراد: كليك على رابط موقع العميل في إيميل → tf_em في الرابط →
 *      ودجت الحجز بيبعته → الحجز بيتنسب للحملة/الأتمتة، وبيتحسب في
 *      attributed_revenue عند التأكيد ويتشال عند الإلغاء.
 *   5) خطوات أتمتة بتنفّذ في خدمات تانية: إنشاء مهمة CRM / إشعار لصاحب الحساب.
 *
 * قواعد ثابتة:
 *   - عمره ما يرمي Exception لنقطة الاستدعاء (الحجز/CRM أهم من البريد).
 *   - عمره ما يرجّع اشتراك حد عمل إلغاء/bounce: المشترك الموجود بيتحدّث
 *     attributes بتاعته بس، والأتمتة بتشتغل للـ subscribed فقط.
 *   - إضافة عميل جديد كمشترك بتحصل بس لو صاحب الحساب فعّلها صراحة
 *     (email_platform_settings، الافتراضي مقفول) وبتتسجل في سجل الموافقات.
 */
class EmailPlatformBridge
{
    public const TRIGGER_BOOKING_CREATED = 'booking_created';
    public const TRIGGER_BOOKING_CONFIRMED = 'booking_confirmed';
    public const TRIGGER_BOOKING_CANCELLED = 'booking_cancelled';
    public const TRIGGER_TRIP_UPCOMING = 'trip_upcoming';
    public const TRIGGER_TRIP_COMPLETED = 'trip_completed';
    public const TRIGGER_CRM_LEAD_CREATED = 'crm_lead_created';
    public const TRIGGER_CRM_DEAL_WON = 'crm_deal_won';

    public const STEP_CREATE_CRM_TASK = 'create_crm_task';
    public const STEP_NOTIFY_OWNER = 'notify_owner';
    public const STEP_SEND_WHATSAPP = 'send_whatsapp';
    public const STEP_REQUEST_REVIEW = 'request_review';

    /** حدث CRM → مشغّل أتمتة البريد */
    public const CRM_EVENT_MAP = [
        'lead.created' => self::TRIGGER_CRM_LEAD_CREATED,
        'deal.won' => self::TRIGGER_CRM_DEAL_WON,
    ];

    public const ATTRIBUTION_PARAM = 'tf_em';

    private const DEFAULT_SETTINGS = [
        'sync_booking_customers' => 0,
        'booking_list_id' => null,
        'sync_crm_contacts' => 0,
        'crm_list_id' => null,
        'revenue_attribution' => 1,
        'attribution_window_days' => 30,
    ];

    /** @var Database */
    private $db;

    public function __construct($db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    // ============================ Metadata ============================

    public static function triggers(): array
    {
        return [
            self::TRIGGER_BOOKING_CREATED => 'عند إنشاء حجز جديد',
            self::TRIGGER_BOOKING_CONFIRMED => 'عند تأكيد الحجز',
            self::TRIGGER_BOOKING_CANCELLED => 'عند إلغاء الحجز',
            self::TRIGGER_TRIP_UPCOMING => 'قبل موعد الرحلة',
            self::TRIGGER_TRIP_COMPLETED => 'بعد انتهاء الرحلة',
            self::TRIGGER_CRM_LEAD_CREATED => 'عند إنشاء Lead في CRM',
            self::TRIGGER_CRM_DEAL_WON => 'عند كسب صفقة في CRM',
        ];
    }

    public static function stepTypes(): array
    {
        return [
            self::STEP_CREATE_CRM_TASK => 'إنشاء مهمة في CRM',
            self::STEP_NOTIFY_OWNER => 'إشعار لصاحب الحساب',
            self::STEP_SEND_WHATSAPP => 'رسالة واتساب (قالب خدمي معتمد)',
            self::STEP_REQUEST_REVIEW => 'طلب تقييم (Google/TripAdvisor)',
        ];
    }

    /** متغيرات إضافية متاحة في رسائل الأتمتة اللي مشغّلها حجز */
    public static function variables(): array
    {
        return [
            '{{booking_reference}}' => 'مرجع الحجز',
            '{{trip_name}}' => 'اسم الرحلة/الخدمة',
            '{{trip_date}}' => 'تاريخ الرحلة',
            '{{booking_total}}' => 'قيمة الحجز',
            '{{booking_currency}}' => 'عملة الحجز',
        ];
    }

    public static function isBookingTrigger(string $trigger): bool
    {
        return in_array($trigger, [
            self::TRIGGER_BOOKING_CREATED, self::TRIGGER_BOOKING_CONFIRMED, self::TRIGGER_BOOKING_CANCELLED,
            self::TRIGGER_TRIP_UPCOMING, self::TRIGGER_TRIP_COMPLETED,
        ], true);
    }

    /**
     * مطابقة نقية لشرط المشغّل مع الحدث. فلتر اختياري product_id لكل
     * مشغّلات الحجز/الرحلة؛ مشغّلات CRM بتطابق دايمًا.
     */
    public static function triggerMatches(string $triggerType, array $trigger, array $event): bool
    {
        if (self::isBookingTrigger($triggerType)) {
            $productId = (int) ($trigger['product_id'] ?? 0);
            return $productId === 0 || (int) ($event['product_id'] ?? 0) === $productId;
        }
        return in_array($triggerType, self::CRM_EVENT_MAP, true);
    }

    // ============================ Settings ============================

    public function settings(int $userId): array
    {
        try {
            $row = $this->db->query("SELECT * FROM email_platform_settings WHERE user_id = ? LIMIT 1", [$userId])[0] ?? null;
        } catch (\Throwable $e) {
            $row = null; // الجدول لسه ما اتعملش (migration)
        }
        $settings = self::DEFAULT_SETTINGS;
        foreach ($settings as $key => $default) {
            if ($row && array_key_exists($key, $row)) {
                $settings[$key] = $row[$key] === null ? null : (int) $row[$key];
            }
        }
        return $settings;
    }

    public function saveSettings(int $userId, array $in): array
    {
        $current = $this->settings($userId);
        $next = $current;
        foreach (['sync_booking_customers', 'sync_crm_contacts', 'revenue_attribution'] as $flag) {
            if (array_key_exists($flag, $in)) {
                $next[$flag] = !empty($in[$flag]) ? 1 : 0;
            }
        }
        foreach (['booking_list_id', 'crm_list_id'] as $listKey) {
            if (!array_key_exists($listKey, $in)) {
                continue;
            }
            $listId = (int) $in[$listKey];
            if ($listId > 0 && empty($this->db->query("SELECT id FROM email_lists WHERE id = ? AND user_id = ? LIMIT 1", [$listId, $userId]))) {
                return ['success' => false, 'error' => 'القائمة غير موجودة'];
            }
            $next[$listKey] = $listId > 0 ? $listId : null;
        }
        if (array_key_exists('attribution_window_days', $in)) {
            $next['attribution_window_days'] = max(1, min(90, (int) $in['attribution_window_days']));
        }
        try {
            $this->db->query(
                "INSERT INTO email_platform_settings
                    (user_id, sync_booking_customers, booking_list_id, sync_crm_contacts, crm_list_id, revenue_attribution, attribution_window_days)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    sync_booking_customers = VALUES(sync_booking_customers), booking_list_id = VALUES(booking_list_id),
                    sync_crm_contacts = VALUES(sync_crm_contacts), crm_list_id = VALUES(crm_list_id),
                    revenue_attribution = VALUES(revenue_attribution), attribution_window_days = VALUES(attribution_window_days)",
                [$userId, $next['sync_booking_customers'], $next['booking_list_id'], $next['sync_crm_contacts'], $next['crm_list_id'], $next['revenue_attribution'], $next['attribution_window_days']]
            );
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'شغّل migration 2026_10_20_000002_email_platform_integration.sql الأول'];
        }
        return ['success' => true, 'settings' => $next];
    }

    // ============================ Booking events ============================

    /** يُستدعى من BookingEngine بعد ما الـ transaction يخلص. لا يرمي أبدًا. */
    public function onBookingEvent(int $bookingId, string $trigger): void
    {
        try {
            $booking = $this->loadBooking($bookingId);
            if (!$booking) {
                return;
            }
            $userId = (int) $booking['user_id'];
            if ($trigger === self::TRIGGER_BOOKING_CONFIRMED) {
                $this->creditAttribution($bookingId);
            } elseif ($trigger === self::TRIGGER_BOOKING_CANCELLED) {
                $this->reverseAttribution($bookingId);
            }
            $settings = $this->settings($userId);
            $subscriberId = $this->resolveSubscriber(
                $userId,
                (string) ($booking['customer_email'] ?? ''),
                (string) ($booking['customer_name'] ?? ''),
                'booking',
                (bool) $settings['sync_booking_customers'],
                $settings['booking_list_id'],
                self::bookingAttributes($booking)
            );
            if ($subscriberId <= 0) {
                return;
            }
            $this->fire($userId, $trigger, $subscriberId, 'booking:' . $bookingId, self::bookingContext($booking));
        } catch (\Throwable $e) {
            $this->log('booking event failed', ['booking_id' => $bookingId, 'trigger' => $trigger, 'error' => $e->getMessage()]);
        }
    }

    /**
     * مشغّلات الرحلة (قبل/بعد) — بتتنادى من EmailAutomationService::processDue
     * (cron). كل حجز بيدخل الأتمتة مرة واحدة بس (email_automation_triggers_log).
     */
    public function enrollTripAutomations(): void
    {
        try {
            $automations = $this->db->query(
                "SELECT * FROM email_automations WHERE status = 'active' AND trigger_type IN (?, ?)",
                [self::TRIGGER_TRIP_UPCOMING, self::TRIGGER_TRIP_COMPLETED]
            );
        } catch (\Throwable $e) {
            return;
        }
        foreach ($automations as $auto) {
            try {
                $this->enrollTripAutomation($auto);
            } catch (\Throwable $e) {
                $this->log('trip automation enroll failed', ['automation_id' => $auto['id'] ?? 0, 'error' => $e->getMessage()]);
            }
        }
    }

    private function enrollTripAutomation(array $auto): void
    {
        $userId = (int) $auto['user_id'];
        $trigger = json_decode((string) ($auto['trigger_value'] ?? 'null'), true) ?: [];
        [$from, $to] = self::tripWindow((string) $auto['trigger_type'], (int) ($trigger['days'] ?? 0), date('Y-m-d'));
        $dateExpr = $auto['trigger_type'] === self::TRIGGER_TRIP_UPCOMING ? 'b.start_date' : 'COALESCE(b.end_date, b.start_date)';
        $statuses = $auto['trigger_type'] === self::TRIGGER_TRIP_UPCOMING ? "('confirmed')" : "('confirmed','completed')";
        $bookings = $this->db->query(
            "SELECT b.*, p.name AS product_name FROM bookings b
             LEFT JOIN crm_products p ON p.id = b.product_id AND p.user_id = b.user_id
             WHERE b.user_id = ? AND b.status IN {$statuses}
               AND b.customer_email IS NOT NULL AND b.customer_email != ''
               AND {$dateExpr} BETWEEN ? AND ?
             ORDER BY b.id ASC LIMIT 500",
            [$userId, $from, $to]
        );
        $settings = $this->settings($userId);
        foreach ($bookings as $booking) {
            if (!self::triggerMatches((string) $auto['trigger_type'], $trigger, ['product_id' => (int) $booking['product_id']])) {
                continue;
            }
            $subscriberId = $this->resolveSubscriber(
                $userId, (string) $booking['customer_email'], (string) $booking['customer_name'], 'booking',
                (bool) $settings['sync_booking_customers'], $settings['booking_list_id'], self::bookingAttributes($booking)
            );
            if ($subscriberId > 0) {
                $this->enrollOnce((int) $auto['id'], $subscriberId, 'booking:' . (int) $booking['id'], self::bookingContext($booking));
            }
        }
    }

    /**
     * نافذة التواريخ (Y-m-d) لمشغّلات الرحلة. نافذة مش يوم واحد عشان لو الـ cron
     * وقف يوم أو الحجز اتعمل متأخر ما يضيعش؛ التكرار ممنوع بالسجل.
     *   upcoming: الرحلات من النهارده لحد بعد N يوم (الافتراضي 3)
     *   completed: الرحلات اللي خلصت من N يوم لحد N+7 (الافتراضي 1)
     * @return array{0:string,1:string}
     */
    public static function tripWindow(string $triggerType, int $days, string $today): array
    {
        $base = strtotime($today . ' 00:00:00');
        if ($triggerType === self::TRIGGER_TRIP_UPCOMING) {
            $days = $days > 0 ? min($days, 90) : 3;
            return [date('Y-m-d', $base), date('Y-m-d', $base + $days * 86400)];
        }
        $days = $days > 0 ? min($days, 90) : 1;
        return [date('Y-m-d', $base - ($days + 7) * 86400), date('Y-m-d', $base - $days * 86400)];
    }

    public static function bookingContext(array $booking): array
    {
        return [
            'source' => 'booking',
            'booking_id' => (int) ($booking['id'] ?? 0),
            'product_id' => (int) ($booking['product_id'] ?? 0),
            'booking_reference' => (string) ($booking['booking_reference'] ?? ''),
            'trip_name' => (string) ($booking['product_name'] ?? ''),
            'trip_date' => (string) ($booking['start_date'] ?? ''),
            'booking_total' => number_format((float) ($booking['total_amount'] ?? 0), 2, '.', ''),
            'booking_currency' => (string) ($booking['currency'] ?? ''),
            'customer_phone' => (string) ($booking['customer_phone'] ?? ''),
            'trip_end_date' => (string) (($booking['end_date'] ?? '') ?: ($booking['start_date'] ?? '')),
        ];
    }

    /** خصائص بتتحفظ على المشترك (attributes) عشان الشرائح والتخصيص */
    public static function bookingAttributes(array $booking): array
    {
        return [
            'last_booking_reference' => (string) ($booking['booking_reference'] ?? ''),
            'last_trip_name' => (string) ($booking['product_name'] ?? ''),
            'last_trip_date' => (string) ($booking['start_date'] ?? ''),
            'phone' => (string) ($booking['customer_phone'] ?? ''),
        ];
    }

    // ============================ CRM events ============================

    /** يُستدعى من CrmAutomationService::trigger. لا يرمي أبدًا. */
    public function onCrmEvent(string $crmEvent, int $userId, array $context): void
    {
        $trigger = self::CRM_EVENT_MAP[$crmEvent] ?? null;
        if ($trigger === null || $userId <= 0) {
            return;
        }
        try {
            $contact = $this->crmContact($userId, $context);
            if (!$contact || empty($contact['email'])) {
                return;
            }
            $settings = $this->settings($userId);
            $subscriberId = $this->resolveSubscriber(
                $userId, (string) $contact['email'], (string) ($contact['name'] ?? ''), 'crm',
                (bool) $settings['sync_crm_contacts'], $settings['crm_list_id'], ['crm_contact_id' => (string) $contact['id']]
            );
            if ($subscriberId <= 0) {
                return;
            }
            $ref = $trigger === self::TRIGGER_CRM_DEAL_WON
                ? 'deal:' . (int) ($context['deal_id'] ?? 0)
                : 'lead:' . (int) ($context['lead_id'] ?? 0);
            $this->fire($userId, $trigger, $subscriberId, $ref, ['source' => 'crm', 'crm_contact_id' => (int) $contact['id']] + $context);
        } catch (\Throwable $e) {
            $this->log('crm event failed', ['event' => $crmEvent, 'error' => $e->getMessage()]);
        }
    }

    private function crmContact(int $userId, array $context): ?array
    {
        $contactId = (int) ($context['contact_id'] ?? 0);
        if ($contactId <= 0 && !empty($context['lead_id'])) {
            $contactId = (int) ($this->db->query("SELECT contact_id FROM crm_leads WHERE id = ? LIMIT 1", [(int) $context['lead_id']])[0]['contact_id'] ?? 0);
        }
        if ($contactId <= 0 && !empty($context['deal_id'])) {
            $contactId = (int) ($this->db->query("SELECT contact_id FROM crm_deals WHERE id = ? LIMIT 1", [(int) $context['deal_id']])[0]['contact_id'] ?? 0);
        }
        if ($contactId <= 0) {
            return null;
        }
        return $this->db->query("SELECT id, name, email FROM crm_contacts WHERE id = ? AND user_id = ? LIMIT 1", [$contactId, $userId])[0] ?? null;
    }

    // ============================ Subscribers & enrollment ============================

    /**
     * يرجّع id المشترك لو "subscribed"، أو 0.
     * - موجود: بيحدّث attributes بس (من غير ما يلمس الحالة).
     * - جديد: بيتضاف بس لو المزامنة مفعّلة.
     */
    private function resolveSubscriber(int $userId, string $email, string $name, string $source, bool $sync, $listId, array $attributes): int
    {
        $email = strtolower(trim($email));
        if ($userId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 0;
        }
        $existing = $this->db->query(
            "SELECT id, status, attributes FROM email_subscribers WHERE user_id = ? AND email = ? LIMIT 1",
            [$userId, $email]
        )[0] ?? null;
        $attributes = array_filter($attributes, fn ($v) => $v !== '' && $v !== null);

        if ($existing) {
            if (!empty($attributes)) {
                $merged = array_merge(json_decode((string) ($existing['attributes'] ?? 'null'), true) ?: [], $attributes);
                $this->db->query("UPDATE email_subscribers SET attributes = ? WHERE id = ?", [json_encode($merged, JSON_UNESCAPED_UNICODE), (int) $existing['id']]);
            }
            return $existing['status'] === 'subscribed' ? (int) $existing['id'] : 0;
        }
        if (!$sync || $this->isSuppressed($userId, $email)) {
            return 0;
        }
        $result = (new EmailListService())->subscribe($userId, $email, [
            'name' => $name,
            'attributes' => $attributes,
            'source' => $source,
            'consent_text' => $source === 'booking'
                ? 'عميل حالي: اتضاف تلقائيًا من حجز (مزامنة عملاء الحجوزات مفعّلة من صاحب الحساب)'
                : 'جهة اتصال CRM: اتضافت تلقائيًا (مزامنة CRM مفعّلة من صاحب الحساب)',
        ], $listId !== null ? (int) $listId : null);
        return !empty($result['success']) && ($result['status'] ?? '') === 'subscribed' ? (int) $result['id'] : 0;
    }

    private function isSuppressed(int $userId, string $email): bool
    {
        try {
            return !empty($this->db->query("SELECT id FROM email_suppressions WHERE user_id = ? AND email = ? LIMIT 1", [$userId, $email]));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** يسجّل المشترك في كل أتمتة نشطة مطابقة للمشغّل، وينفّذ الخطوات الفورية لمشاركاته هو بس. */
    private function fire(int $userId, string $trigger, int $subscriberId, string $refKey, array $context): void
    {
        $automations = $this->db->query(
            "SELECT * FROM email_automations WHERE user_id = ? AND status = 'active' AND trigger_type = ?",
            [$userId, $trigger]
        );
        $service = new EmailAutomationService();
        foreach ($automations as $auto) {
            $value = json_decode((string) ($auto['trigger_value'] ?? 'null'), true) ?: [];
            if (!self::triggerMatches($trigger, $value, $context)) {
                continue;
            }
            $entryId = $this->enrollOnce((int) $auto['id'], $subscriberId, $refKey, $context);
            if ($entryId > 0) {
                $service->processEntry($entryId);
            }
        }
    }

    /** دخول مرة واحدة لكل (أتمتة، مشترك، مرجع). يرجّع id المشاركة أو 0. */
    public function enrollOnce(int $automationId, int $subscriberId, string $refKey, array $context): int
    {
        try {
            if (!empty($this->db->query(
                "SELECT 1 FROM email_automation_triggers_log WHERE automation_id = ? AND subscriber_id = ? AND ref_key = ? LIMIT 1",
                [$automationId, $subscriberId, $refKey]
            ))) {
                return 0;
            }
        } catch (\Throwable $e) {
            return 0; // من غير جدول منع التكرار الأأمن إننا ما نسجّلش خالص
        }
        if (!(new EmailAutomationService())->enroll($automationId, $subscriberId, $context)) {
            return 0;
        }
        $this->db->query(
            "INSERT IGNORE INTO email_automation_triggers_log (automation_id, subscriber_id, ref_key) VALUES (?, ?, ?)",
            [$automationId, $subscriberId, $refKey]
        );
        return (int) ($this->db->query(
            "SELECT id FROM email_automation_entries WHERE automation_id = ? AND subscriber_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1",
            [$automationId, $subscriberId]
        )[0]['id'] ?? 0);
    }

    // ============================ Automation steps ============================

    /**
     * تنفيذ خطوات الربط. null = النوع مش تبعنا.
     */
    public function executeStep(string $type, int $userId, int $subscriberId, array $value, array $context): ?bool
    {
        if (!isset(self::stepTypes()[$type])) {
            return null;
        }
        $sub = $this->db->query("SELECT id, name, email, attributes FROM email_subscribers WHERE id = ? AND user_id = ? LIMIT 1", [$subscriberId, $userId])[0] ?? null;
        if (!$sub) {
            return false;
        }
        $vars = self::stepVariables($sub, $context);
        try {
            if ($type === self::STEP_CREATE_CRM_TASK) {
                $title = self::fill((string) ($value['title'] ?? ''), $vars);
                if ($title === '' || !class_exists('CrmTaskService')) {
                    return false;
                }
                $contactId = (int) ($context['crm_contact_id'] ?? 0);
                if ($contactId <= 0) {
                    $contactId = (int) ($this->db->query("SELECT id FROM crm_contacts WHERE user_id = ? AND email = ? LIMIT 1", [$userId, $sub['email']])[0]['id'] ?? 0);
                }
                $dueDays = max(0, min(365, (int) ($value['due_days'] ?? 1)));
                $priority = in_array($value['priority'] ?? '', ['low', 'medium', 'high'], true) ? $value['priority'] : 'medium';
                (new CrmTaskService())->create($userId, [
                    'title' => mb_substr($title, 0, 255),
                    'description' => self::fill((string) ($value['description'] ?? ''), $vars) ?: ('من أتمتة البريد — ' . $sub['email']),
                    'due_date' => date('Y-m-d', time() + $dueDays * 86400),
                    'priority' => $priority,
                    'related_type' => $contactId > 0 ? 'contact' : null,
                    'related_id' => $contactId > 0 ? $contactId : null,
                ]);
                return true;
            }
            if ($type === self::STEP_SEND_WHATSAPP) {
                return $this->stepWhatsApp($userId, $sub, $value, $context, $vars);
            }
            if ($type === self::STEP_REQUEST_REVIEW) {
                return $this->stepReviewRequest($userId, $sub, $value, $context);
            }
            if ($type === self::STEP_NOTIFY_OWNER) {
                $title = self::fill((string) ($value['title'] ?? ''), $vars) ?: ('أتمتة البريد: ' . $sub['email']);
                if (class_exists('Notification')) {
                    Notification::notify($userId, 'email_automation', mb_substr($title, 0, 255), self::fill((string) ($value['body'] ?? ''), $vars), '/email-marketing');
                }
                return true;
            }
        } catch (\Throwable $e) {
            $this->log('automation step failed', ['type' => $type, 'error' => $e->getMessage()]);
        }
        return false;
    }

    /**
     * واتساب: قالب UTILITY معتمد بس (رسائل خدمة زي تذكير الرحلة) عبر
     * WhatsAppTransactionalService — نفس مسار الإرسال الموثّق في المنصة.
     * رسائل التسويق على واتساب ليها موافقة منفصلة (WhatsAppMarketingService)
     * فمش بنبعتها من هنا. أي رقم عامل opt-out (واتساب أو التقييمات) بيتخطّى.
     */
    private function stepWhatsApp(int $userId, array $sub, array $value, array $context, array $vars): bool
    {
        $template = trim((string) ($value['template_name'] ?? ''));
        $phone = self::subscriberPhone($sub, $context);
        if ($template === '' || $phone === '' || !class_exists('WhatsAppTransactionalService')) {
            return false;
        }
        $websiteId = $this->resolveWebsiteId($userId, (int) ($value['website_id'] ?? 0));
        if ($websiteId <= 0 || $this->phoneOptedOut($userId, $websiteId, $phone)) {
            return false;
        }
        $params = [];
        foreach (array_filter(array_map('trim', explode('|', (string) ($value['params'] ?? ''))), 'strlen') as $param) {
            $params[] = self::fill($param, $vars);
        }
        // مفتاح التنفيذ ثابت لكل (مشاركة، خطوة): إعادة المعالجة ما تبعتش الرسالة مرتين
        $operation = 'email_auto:' . (int) ($context['_entry_id'] ?? 0) . ':' . (int) ($context['_step_id'] ?? 0) . ':' . (int) $sub['id'];
        $language = preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', (string) ($value['language'] ?? '')) ? (string) $value['language'] : 'ar';
        $result = WhatsAppTransactionalService::send($userId, $websiteId, $phone, $template, $language, $params, $operation);
        if (empty($result['ok'])) {
            $this->log('whatsapp step not sent', ['user_id' => $userId, 'error' => $result['error'] ?? '']);
        }
        return !empty($result['ok']);
    }

    /** طلب تقييم عبر ReviewRequestService (بيحترم opt-out ومنع التكرار والجدولة بتاعته). */
    private function stepReviewRequest(int $userId, array $sub, array $value, array $context): bool
    {
        if (!class_exists('ReviewRequestService')) {
            return false;
        }
        $websiteId = $this->resolveWebsiteId($userId, (int) ($value['website_id'] ?? 0));
        if ($websiteId <= 0) {
            return false;
        }
        $channel = in_array($value['channel'] ?? '', ['email', 'whatsapp'], true) ? $value['channel'] : 'email';
        $phone = self::subscriberPhone($sub, $context);
        if ($channel === 'whatsapp' && $phone === '') {
            $channel = 'email';
        }
        $destination = in_array($value['destination'] ?? '', ['google_business', 'tripadvisor', 'other'], true) ? $value['destination'] : 'google_business';
        $endDate = (string) ($context['trip_end_date'] ?? '');
        $serviceEnd = preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) && strtotime($endDate) <= time() ? $endDate . ' 20:00:00' : date('Y-m-d H:i:s');
        $service = new ReviewRequestService();
        try {
            $request = $service->createRequest(
                $userId, $websiteId, (string) ($sub['name'] ?: $sub['email']),
                $channel === 'whatsapp' ? $phone : null, $serviceEnd, $channel,
                $channel === 'email' ? (string) $sub['email'] : null, 'email_automation', null, $destination
            );
        } catch (\Throwable $e) {
            // مكرر / opt-out / القناة أو رابط التقييم مش متظبط
            $this->log('review request step skipped', ['user_id' => $userId, 'reason' => $e->getMessage()]);
            return false;
        }
        if (!empty($context['booking_id'])) {
            try {
                // عشان الطلب التلقائي من الحجوزات ما يعملش طلب تاني لنفس الحجز
                $this->db->query('UPDATE review_requests SET booking_id = ? WHERE id = ? AND booking_id IS NULL', [(int) $context['booking_id'], (int) $request->getAttribute('id')]);
            } catch (\Throwable $e) {
            }
        }
        return true;
    }

    public static function subscriberPhone(array $sub, array $context): string
    {
        $phone = (string) ($context['customer_phone'] ?? '');
        if ($phone === '') {
            $attributes = json_decode((string) ($sub['attributes'] ?? 'null'), true) ?: [];
            $phone = (string) ($attributes['phone'] ?? '');
        }
        return preg_replace('/[^0-9]/', '', $phone);
    }

    /** موقع مملوك للحساب (جدول websites): المحدد في الخطوة أو أول موقع. */
    private function resolveWebsiteId(int $userId, int $requested): int
    {
        if ($requested > 0) {
            return (int) ($this->db->query("SELECT id FROM websites WHERE id = ? AND user_id = ? LIMIT 1", [$requested, $userId])[0]['id'] ?? 0);
        }
        return (int) ($this->db->query("SELECT id FROM websites WHERE user_id = ? ORDER BY created_at ASC, id ASC LIMIT 1", [$userId])[0]['id'] ?? 0);
    }

    private function phoneOptedOut(int $userId, int $websiteId, string $phone): bool
    {
        try {
            if (!empty($this->db->query("SELECT id FROM wa_marketing_contacts WHERE user_id = ? AND phone LIKE ? AND consent_state = 'opted_out' LIMIT 1", [$userId, '%' . substr($phone, -9)]))) {
                return true;
            }
        } catch (\Throwable $e) {
        }
        try {
            return !empty($this->db->query("SELECT id FROM review_request_opt_outs WHERE website_id = ? AND guest_phone = ? LIMIT 1", [$websiteId, $phone]));
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function stepVariables(array $sub, array $context): array
    {
        $vars = [
            'name' => (string) ($sub['name'] ?? ''),
            'email' => (string) ($sub['email'] ?? ''),
        ];
        foreach (['booking_reference', 'trip_name', 'trip_date', 'booking_total', 'booking_currency'] as $key) {
            $vars[$key] = (string) ($context[$key] ?? '');
        }
        return $vars;
    }

    /** استبدال {{var}} في نص عادي (مش HTML) */
    public static function fill(string $text, array $vars): string
    {
        foreach ($vars as $key => $val) {
            $text = str_replace('{{' . $key . '}}', (string) $val, $text);
        }
        return trim($text);
    }

    // ============================ Revenue attribution ============================

    /**
     * يضيف tf_em لرابط الكليك لو الوجهة موقع تابع لصاحب الحملة (موقعه أو
     * موقع مبني على المنصة). روابط الطرف التالت ما بيتلمسوش (ما نسرّبش توكن).
     */
    public function decorateClickUrl(string $clickToken, string $url): string
    {
        try {
            if (!self::isValidToken($clickToken)) {
                return $url;
            }
            $userId = $this->clickOwner($clickToken);
            if ($userId <= 0 || !$this->settings($userId)['revenue_attribution']) {
                return $url;
            }
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host === '') {
                return $url;
            }
            $platformHost = defined('APP_URL') ? strtolower((string) parse_url(APP_URL, PHP_URL_HOST)) : '';
            if ($platformHost !== '' && self::hostAllowed($host, [$platformHost])) {
                // على دومين المنصة: بس صفحات مواقع الحساب نفسه (/sites/{slug})، مش مواقع حسابات تانية
                if (!self::pathInSites((string) parse_url($url, PHP_URL_PATH), $this->ownedSiteSlugs($userId))) {
                    return $url;
                }
            } elseif (!self::hostAllowed($host, $this->ownedHosts($userId))) {
                return $url;
            }
            return self::appendQueryParam($url, self::ATTRIBUTION_PARAM, $clickToken);
        } catch (\Throwable $e) {
            return $url;
        }
    }

    /** يُستدعى من BookingEngine::createBooking بعد الإنشاء. */
    public function attributeBooking(int $userId, int $bookingId, string $clickToken): void
    {
        if (!self::isValidToken($clickToken) || $userId <= 0 || $bookingId <= 0) {
            return;
        }
        try {
            $settings = $this->settings($userId);
            if (!$settings['revenue_attribution']) {
                return;
            }
            $since = date('Y-m-d H:i:s', time() - (int) $settings['attribution_window_days'] * 86400);
            $source = $this->db->query(
                "SELECT r.campaign_id, NULL AS automation_id, r.subscriber_id FROM email_campaign_recipients r
                 JOIN email_campaigns c ON c.id = r.campaign_id
                 WHERE r.click_token = ? AND c.user_id = ? AND r.clicked_at >= ? LIMIT 1",
                [$clickToken, $userId, $since]
            )[0] ?? $this->db->query(
                "SELECT NULL AS campaign_id, automation_id, subscriber_id FROM email_automation_logs
                 WHERE click_token = ? AND user_id = ? AND clicked_at >= ? LIMIT 1",
                [$clickToken, $userId, $since]
            )[0] ?? null;
            if (!$source) {
                return;
            }
            $booking = $this->db->query("SELECT total_amount, currency FROM bookings WHERE id = ? AND user_id = ? LIMIT 1", [$bookingId, $userId])[0] ?? null;
            if (!$booking) {
                return;
            }
            $this->db->query(
                "INSERT IGNORE INTO email_booking_attribution
                    (booking_id, user_id, campaign_id, automation_id, subscriber_id, click_token, amount, currency)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$bookingId, $userId, $source['campaign_id'], $source['automation_id'], $source['subscriber_id'], $clickToken, (float) $booking['total_amount'], $booking['currency']]
            );
        } catch (\Throwable $e) {
            $this->log('booking attribution failed', ['booking_id' => $bookingId, 'error' => $e->getMessage()]);
        }
    }

    /** إضافة الإيراد للحملة/الأتمتة عند التأكيد (idempotent). */
    public function creditAttribution(int $bookingId): void
    {
        $pdo = $this->db->getConnection();
        try {
            $q = $pdo->prepare(
                "UPDATE email_booking_attribution a JOIN bookings b ON b.id = a.booking_id
                 SET a.credited_at = NOW(), a.amount = b.total_amount, a.currency = b.currency
                 WHERE a.booking_id = ? AND a.credited_at IS NULL AND a.reversed_at IS NULL"
            );
            $q->execute([$bookingId]);
            if ($q->rowCount() === 0) {
                return;
            }
            $this->applyAttributionDelta($bookingId, 1);
        } catch (\Throwable $e) {
            $this->log('credit attribution failed', ['booking_id' => $bookingId, 'error' => $e->getMessage()]);
        }
    }

    /** شيل الإيراد لو الحجز اتلغى بعد ما اتحسب. */
    public function reverseAttribution(int $bookingId): void
    {
        $pdo = $this->db->getConnection();
        try {
            $q = $pdo->prepare(
                "UPDATE email_booking_attribution SET reversed_at = NOW()
                 WHERE booking_id = ? AND credited_at IS NOT NULL AND reversed_at IS NULL"
            );
            $q->execute([$bookingId]);
            if ($q->rowCount() === 0) {
                return;
            }
            $this->applyAttributionDelta($bookingId, -1);
        } catch (\Throwable $e) {
            $this->log('reverse attribution failed', ['booking_id' => $bookingId, 'error' => $e->getMessage()]);
        }
    }

    private function applyAttributionDelta(int $bookingId, int $sign): void
    {
        $row = $this->db->query("SELECT campaign_id, automation_id, amount FROM email_booking_attribution WHERE booking_id = ? LIMIT 1", [$bookingId])[0] ?? null;
        if (!$row) {
            return;
        }
        $amount = (float) $row['amount'] * $sign;
        foreach (['email_campaigns' => $row['campaign_id'], 'email_automations' => $row['automation_id']] as $table => $id) {
            if ((int) $id <= 0) {
                continue;
            }
            $this->db->query(
                "UPDATE {$table}
                 SET attributed_bookings_count = GREATEST(0, CAST(attributed_bookings_count AS SIGNED) + ?),
                     attributed_revenue = GREATEST(0, attributed_revenue + ?)
                 WHERE id = ?",
                [$sign, $amount, (int) $id]
            );
        }
    }

    /** ملخص إيراد البريد لصاحب الحساب (للتقارير/الداشبورد). */
    public function revenueSummary(int $userId): array
    {
        try {
            $rows = $this->db->query(
                "SELECT currency, COUNT(*) AS bookings, SUM(amount) AS revenue
                 FROM email_booking_attribution
                 WHERE user_id = ? AND credited_at IS NOT NULL AND reversed_at IS NULL
                 GROUP BY currency",
                [$userId]
            );
            $top = $this->db->query(
                "SELECT id, name, attributed_bookings_count, attributed_revenue FROM email_campaigns
                 WHERE user_id = ? AND attributed_bookings_count > 0
                 ORDER BY attributed_revenue DESC LIMIT 5",
                [$userId]
            );
        } catch (\Throwable $e) {
            return ['by_currency' => [], 'top_campaigns' => []];
        }
        return ['by_currency' => $rows, 'top_campaigns' => $top];
    }

    private function clickOwner(string $clickToken): int
    {
        $row = $this->db->query(
            "SELECT c.user_id FROM email_campaign_recipients r JOIN email_campaigns c ON c.id = r.campaign_id WHERE r.click_token = ? LIMIT 1",
            [$clickToken]
        )[0] ?? $this->db->query("SELECT user_id FROM email_automation_logs WHERE click_token = ? LIMIT 1", [$clickToken])[0] ?? null;
        return (int) ($row['user_id'] ?? 0);
    }

    private function ownedSiteSlugs(int $userId): array
    {
        try {
            return array_map(fn ($r) => (string) $r['slug'], $this->db->query("SELECT slug FROM generated_websites WHERE user_id = ?", [$userId]));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** المسار /sites/{slug} أو /sites/{slug}/... لواحد من الـ slugs */
    public static function pathInSites(string $path, array $slugs): bool
    {
        foreach ($slugs as $slug) {
            $prefix = '/sites/' . $slug;
            if ($slug !== '' && ($path === $prefix || strpos($path, $prefix . '/') === 0)) {
                return true;
            }
        }
        return false;
    }

    /** الدومينات المخصصة لمواقع الحساب + مواقعه المسجّلة */
    private function ownedHosts(int $userId): array
    {
        $hosts = [];
        try {
            foreach ($this->db->query("SELECT custom_domain FROM generated_websites WHERE user_id = ? AND custom_domain IS NOT NULL AND custom_domain != ''", [$userId]) as $r) {
                $hosts[] = (string) (parse_url('https://' . preg_replace('#^https?://#i', '', (string) $r['custom_domain']), PHP_URL_HOST));
            }
        } catch (\Throwable $e) {
        }
        try {
            foreach ($this->db->query("SELECT main_url FROM websites WHERE user_id = ?", [$userId]) as $r) {
                $hosts[] = (string) parse_url((string) $r['main_url'], PHP_URL_HOST);
            }
        } catch (\Throwable $e) {
        }
        return array_values(array_unique(array_filter(array_map('strtolower', $hosts))));
    }

    /** الـ host مطابق أو subdomain (www.x.com يطابق x.com والعكس) */
    public static function hostAllowed(string $host, array $allowed): bool
    {
        $host = preg_replace('/^www\./', '', strtolower($host));
        foreach ($allowed as $candidate) {
            $candidate = preg_replace('/^www\./', '', strtolower((string) $candidate));
            if ($candidate === '') {
                continue;
            }
            if ($host === $candidate || substr($host, -strlen('.' . $candidate)) === '.' . $candidate) {
                return true;
            }
        }
        return false;
    }

    /** إضافة باراميتر للرابط مع الحفاظ على الـ fragment والاستبدال لو موجود */
    public static function appendQueryParam(string $url, string $key, string $value): string
    {
        $fragment = '';
        $hashPos = strpos($url, '#');
        if ($hashPos !== false) {
            $fragment = substr($url, $hashPos);
            $url = substr($url, 0, $hashPos);
        }
        $url = preg_replace('/([?&])' . preg_quote($key, '/') . '=[^&]*(&|$)/', '$1', $url);
        $url = rtrim($url, '?&');
        $sep = strpos($url, '?') === false ? '?' : '&';
        return $url . $sep . rawurlencode($key) . '=' . rawurlencode($value) . $fragment;
    }

    /**
     * زيارة موقع مبني على المنصة برابط فيه tf_em → كوكي موقّعة 30 يوم، عشان
     * الحجز يتنسب للإيميل حتى لو الزائر اتنقل بين الصفحات قبل ما يحجز.
     * الملكية والنافذة الزمنية بيتحقق منهم attributeBooking وقت الحجز.
     */
    public static function captureQuery(int $websiteId): void
    {
        $token = $_GET[self::ATTRIBUTION_PARAM] ?? '';
        if ($websiteId <= 0 || !is_string($token) || !self::isValidToken($token) || headers_sent()) {
            return;
        }
        $key = self::signingKey();
        if ($key === '') {
            return;
        }
        $time = (string) time();
        $sig = hash_hmac('sha256', 'em.' . $websiteId . '.' . $token . '.' . $time, $key);
        setcookie('tf_em_' . $websiteId, $token . '.' . $time . '.' . $sig, [
            'expires' => time() + 30 * 86400, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }

    public static function cookieToken(int $websiteId): string
    {
        $raw = $_COOKIE['tf_em_' . $websiteId] ?? '';
        if (!is_string($raw)) {
            return '';
        }
        [$token, $time, $sig] = array_pad(explode('.', $raw), 3, '');
        $key = self::signingKey();
        if ($key === '' || !self::isValidToken($token) || !ctype_digit($time)
            || (int) $time < time() - 30 * 86400 || (int) $time > time() + 60
            || !hash_equals(hash_hmac('sha256', 'em.' . $websiteId . '.' . $token . '.' . $time, $key), $sig)) {
            return '';
        }
        return $token;
    }

    private static function signingKey(): string
    {
        $key = function_exists('env') ? (string) (env('APP_KEY') ?: env('ENCRYPTION_KEY') ?: '') : '';
        return $key !== '' ? $key : (defined('ENCRYPTION_KEY') ? (string) ENCRYPTION_KEY : '');
    }

    public static function isValidToken(string $token): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32,64}$/D', $token);
    }

    // ============================ Helpers ============================

    private function loadBooking(int $bookingId): ?array
    {
        return $this->db->query(
            "SELECT b.*, p.name AS product_name FROM bookings b
             LEFT JOIN crm_products p ON p.id = b.product_id AND p.user_id = b.user_id
             WHERE b.id = ? LIMIT 1",
            [$bookingId]
        )[0] ?? null;
    }

    private function log(string $message, array $ctx): void
    {
        if (class_exists('Logger')) {
            Logger::warning('EmailPlatformBridge: ' . $message, $ctx);
        }
    }
}
