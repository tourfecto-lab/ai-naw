<?php

/**
 * Tourfecto - مين من العملاء بيستخدم إيه (للأدمن)
 *
 * لكل عميل (صاحب حساب): وصل لحد فين في طريق "أول حجز" (موقع منشور ← رحلة قابلة للحجز ← توفر ←
 * طريقة دفع ← أول حجز) وأنهي مميزات مفعّلها (تذكير الرحلة، ملخص الصبح، طلبات التقييم التلقائية،
 * لينكات الدفع، الحجز من الشات...). الهدف: تعرف تكلّم مين ("واقف عند التوفر") وتبني إيه بعد كده
 * على أساس استخدام حقيقي. كل الأرقام استعلامات مجمّعة (GROUP BY) - مش استعلام لكل عميل.
 */
class FeatureAdoptionService
{
    public const STAGES = [
        'site' => 'لسه ماعملش موقع حجز',
        'tour' => 'مفيش رحلة قابلة للحجز',
        'availability' => 'ماحددش التوفر',
        'payments' => 'مااختارش طريقة استلام الفلوس',
        'first_booking' => 'جاهز ومستني أول حجز',
        'booking' => 'بيستقبل حجوزات',
    ];

    public const FEATURES = [
        'trip_reminder' => 'تذكير الرحلة',
        'morning_brief' => 'ملخص الصبح',
        'review_auto' => 'طلبات تقييم بعد الرحلة',
        'payment_links' => 'لينكات الدفع',
        'manual_payments' => 'تسجيل دفع يدوي',
        'chat_bookings' => 'حجز من الشات',
        'kb' => 'معلومات البوت',
        'online_payments' => 'دفع أونلاين (Stripe)',
    ];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    /** [user_id => قيمة] من استعلام فيه user_id و v */
    private function map(string $sql, array $p = []): array
    {
        $out = [];
        try {
            foreach ($this->db->query($sql, $p) as $r) {
                $out[(int) $r['user_id']] = $r['v'];
            }
        } catch (Throwable $e) {
            // جدول/عمود لسه ماتعملش (التحديث ماتطبقش) - الميزة تتحسب صفر
        }
        return $out;
    }

    /**
     * @param string $stage فلتر بالمرحلة (أو '' للكل)
     * @return array{items:array, funnel:array, features:array, total:int}
     */
    public function report(string $stage = '', string $q = ''): array
    {
        $where = "u.owner_user_id IS NULL AND u.role NOT IN ('admin','super_admin') AND u.status = 'active'";
        $args = [];
        if ($q !== '') {
            $where .= ' AND (u.email LIKE ? OR u.company_name LIKE ? OR u.first_name LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            array_push($args, $like, $like, $like);
        }
        $users = $this->db->query(
            "SELECT u.id, u.email, u.first_name, u.last_name, u.company_name, u.created_at, u.last_login
               FROM users u WHERE {$where} ORDER BY u.created_at DESC LIMIT 1000",
            $args
        );

        $site = $this->map("SELECT user_id, COUNT(*) v FROM generated_websites WHERE status = 'published' GROUP BY user_id");
        $tours = $this->map('SELECT user_id, COUNT(*) v FROM crm_products WHERE is_active = 1 AND website_id IS NOT NULL AND tour_slug IS NOT NULL GROUP BY user_id');
        $avail = $this->map('SELECT user_id, COUNT(*) v FROM inventory WHERE date >= CURDATE() AND capacity > 0 GROUP BY user_id');
        $payMode = $this->map('SELECT user_id, mode v FROM operator_payment_settings');
        $bookingsAll = $this->map('SELECT user_id, COUNT(*) v FROM bookings GROUP BY user_id');
        $firstBooking = $this->map('SELECT user_id, MIN(created_at) v FROM bookings GROUP BY user_id');
        $b30 = $this->map("SELECT user_id, COUNT(*) v FROM bookings WHERE created_at >= (NOW() - INTERVAL 30 DAY) AND status <> 'cancelled' GROUP BY user_id");
        $rev30 = $this->map("SELECT user_id, COALESCE(SUM(total_amount),0) v FROM bookings WHERE created_at >= (NOW() - INTERVAL 30 DAY) AND status IN ('confirmed','completed') GROUP BY user_id");
        $f = [
            'trip_reminder' => $this->map('SELECT user_id, enabled v FROM booking_reminder_settings WHERE enabled = 1'),
            'morning_brief' => $this->map('SELECT user_id, enabled v FROM morning_brief_settings WHERE enabled = 1'),
            'review_auto' => $this->map('SELECT w.user_id, 1 v FROM review_request_settings s JOIN websites w ON w.id = s.website_id WHERE s.auto_from_bookings = 1 GROUP BY w.user_id'),
            'payment_links' => $this->map('SELECT user_id, COUNT(*) v FROM booking_payment_links GROUP BY user_id'),
            'manual_payments' => $this->map("SELECT user_id, COUNT(*) v FROM payment_transactions WHERE gateway = 'manual' AND status = 'succeeded' GROUP BY user_id"),
            'chat_bookings' => $this->map("SELECT user_id, COUNT(*) v FROM bookings WHERE source = 'chat' GROUP BY user_id"),
            'kb' => $this->map('SELECT w.user_id, COUNT(*) v FROM ai_knowledge_base k JOIN websites w ON w.id = k.website_id WHERE k.deleted_at IS NULL GROUP BY w.user_id'),
            'online_payments' => $this->map("SELECT user_id, 1 v FROM operator_payment_settings WHERE mode IN ('own_stripe','platform')"),
        ];
        $paidLinks = $this->map("SELECT user_id, COUNT(*) v FROM booking_payment_links WHERE status = 'paid' GROUP BY user_id");

        $funnel = array_fill_keys(array_keys(self::STAGES), 0);
        $featureCounts = array_fill_keys(array_keys(self::FEATURES), 0);
        $items = [];
        foreach ($users as $u) {
            $id = (int) $u['id'];
            $steps = [
                'site' => !empty($site[$id]),
                'tour' => !empty($tours[$id]),
                'availability' => !empty($avail[$id]),
                'payments' => isset($payMode[$id]),
                'first_booking' => !empty($bookingsAll[$id]),
            ];
            $st = 'booking';
            foreach ($steps as $k => $ok) {
                if (!$ok) {
                    $st = $k;
                    break;
                }
            }
            // لو عنده حجوزات فعلاً يبقى "بيستقبل حجوزات" حتى لو فوّت خطوة (مثلاً حجز من الشات من غير موقع)
            if (!empty($bookingsAll[$id])) {
                $st = 'booking';
            }
            $funnel[$st]++;
            $feat = [];
            foreach (self::FEATURES as $k => $label) {
                $on = !empty($f[$k][$id]) && (float) $f[$k][$id] > 0;
                if ($k === 'kb') {
                    $on = (int) ($f[$k][$id] ?? 0) >= 3;
                }
                if ($on) {
                    $feat[] = $k;
                    $featureCounts[$k]++;
                }
            }
            $items[] = [
                'id' => $id,
                'name' => trim(($u['company_name'] ?: '') ?: trim($u['first_name'] . ' ' . $u['last_name'])) ?: $u['email'],
                'email' => $u['email'],
                'created_at' => $u['created_at'],
                'last_login' => $u['last_login'],
                'stage' => $st,
                'stage_label' => self::STAGES[$st],
                'steps' => $steps,
                'features' => $feat,
                'bookings_total' => (int) ($bookingsAll[$id] ?? 0),
                'bookings_30d' => (int) ($b30[$id] ?? 0),
                'revenue_30d' => round((float) ($rev30[$id] ?? 0), 2),
                'first_booking_at' => $firstBooking[$id] ?? null,
                'payment_links' => (int) ($f['payment_links'][$id] ?? 0),
                'payment_links_paid' => (int) ($paidLinks[$id] ?? 0),
            ];
        }
        $total = count($items);
        if ($stage !== '' && isset(self::STAGES[$stage])) {
            $items = array_values(array_filter($items, fn ($i) => $i['stage'] === $stage));
        }
        $features = [];
        foreach (self::FEATURES as $k => $label) {
            $features[] = ['key' => $k, 'label' => $label, 'count' => $featureCounts[$k], 'pct' => $total ? round($featureCounts[$k] / $total * 100) : 0];
        }
        $funnelOut = [];
        foreach (self::STAGES as $k => $label) {
            $funnelOut[] = ['key' => $k, 'label' => $label, 'count' => $funnel[$k]];
        }
        return ['items' => array_slice($items, 0, 500), 'funnel' => $funnelOut, 'features' => $features, 'total' => $total];
    }
}
