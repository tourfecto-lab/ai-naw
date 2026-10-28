<?php

/**
 * Tourfecto - متابعة التجارب المجانية (لوحة الأدمن ← متابعة التجارب)
 *
 * تلات مجموعات يكلّمهم صاحب المنصة عشان التجربة تتحول اشتراك:
 *   active  = تجربة شغّالة (الأقرب للنهاية الأول) + العميل خلّص كام خطوة
 *   ended   = التجربة خلصت آخر 30 يوم ولسه مااشتركش (أسخن عملاء)
 *   never   = سجّل آخر 60 يوم ولسه مابدأش التجربة
 * والأرقام كلها من قاعدة البيانات - مفيش تقديرات.
 */
class TrialFollowUpService
{
    private Database $db;

    /** كود الدولة الدولي لأرقام الموبايل المحلية (0xxxxxxx) */
    private const DIAL = ['EG' => '20', 'SA' => '966', 'AE' => '971', 'KW' => '965', 'QA' => '974', 'BH' => '973', 'OM' => '968', 'JO' => '962', 'LB' => '961', 'MA' => '212', 'TN' => '216', 'DZ' => '213', 'TR' => '90', 'GB' => '44', 'DE' => '49', 'FR' => '33', 'IT' => '39', 'ES' => '34', 'GR' => '30', 'TH' => '66', 'ID' => '62', 'MY' => '60', 'PK' => '92', 'IN' => '91'];

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    private function rows(string $sql, array $p = []): array
    {
        try {
            return $this->db->query($sql, $p) ?: [];
        } catch (Throwable $e) {
            Logger::warning('TrialFollowUp query failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private const CUSTOMER = "u.deleted_at IS NULL AND u.role NOT IN ('admin','super_admin') AND (u.owner_user_id IS NULL OR u.owner_user_id = 0)";
    private const PAID = "EXISTS (SELECT 1 FROM subscriptions p WHERE p.user_id = u.id AND p.status IN ('active','past_due') AND COALESCE(p.price,0) > 0)";

    public function report(): array
    {
        $tp = class_exists('TrialPlanService') ? new TrialPlanService($this->db) : null;
        $cols = 'u.id, u.email, u.company_name, u.display_name, u.first_name, u.last_name, u.phone, u.country_code, u.language, u.created_at, u.last_login, u.email_verified_at';

        $active = $this->rows(
            "SELECT $cols, s.id AS sub_id, s.trial_ends_at, s.created_at AS trial_started
               FROM subscriptions s JOIN users u ON u.id = s.user_id
              WHERE s.status = 'trialing' AND s.trial_ends_at > NOW() AND " . self::CUSTOMER . "
              ORDER BY s.trial_ends_at ASC LIMIT 200"
        );
        $ended = $this->rows(
            "SELECT $cols, s.id AS sub_id, s.trial_ends_at, s.status AS sub_status
               FROM subscriptions s JOIN users u ON u.id = s.user_id
              WHERE s.id = (SELECT MAX(s2.id) FROM subscriptions s2 WHERE s2.user_id = u.id)
                AND s.trial_ends_at IS NOT NULL AND COALESCE(s.price,0) = 0
                AND s.trial_ends_at <= NOW() AND s.trial_ends_at > NOW() - INTERVAL 30 DAY
                AND " . self::CUSTOMER . " AND NOT " . self::PAID . "
              ORDER BY s.trial_ends_at DESC LIMIT 200"
        );
        $never = $this->rows(
            "SELECT $cols, (SELECT COUNT(*) FROM websites w WHERE w.user_id = u.id) AS sites
               FROM users u
              WHERE " . self::CUSTOMER . " AND u.created_at > NOW() - INTERVAL 60 DAY
                AND NOT EXISTS (SELECT 1 FROM subscriptions s WHERE s.user_id = u.id)
              ORDER BY u.created_at DESC LIMIT 200"
        );

        $now = time();
        $shape = function (array $r, string $group) use ($tp, $now): array {
            $out = [
                'id' => (int) $r['id'], 'group' => $group,
                'name' => $this->name($r), 'email' => (string) $r['email'],
                'phone' => $this->waNumber((string) ($r['phone'] ?? ''), (string) ($r['country_code'] ?? '')),
                'phone_raw' => (string) ($r['phone'] ?? ''),
                'country' => (string) ($r['country_code'] ?? ''), 'lang' => in_array($r['language'] ?? '', ['ar', 'en', 'fr', 'de'], true) ? $r['language'] : 'en',
                'created_at' => $r['created_at'], 'last_login' => $r['last_login'], 'email_verified' => !empty($r['email_verified_at']),
            ];
            if (isset($r['sub_id'])) {
                $out['sub_id'] = (int) $r['sub_id'];
                $out['trial_ends_at'] = $r['trial_ends_at'];
                $end = strtotime((string) $r['trial_ends_at']) ?: $now;
                $out['hours_left'] = $group === 'active' ? max(0, (int) ceil(($end - $now) / 3600)) : 0;
                $out['ended_days'] = $group === 'ended' ? max(0, (int) floor(($now - $end) / 86400)) : 0;
                if ($tp) {
                    try {
                        $out['progress'] = $tp->adminProgress((int) $r['id'], (string) $r['trial_ends_at']);
                    } catch (Throwable $e) {
                    }
                }
            }
            if (isset($r['sites'])) {
                $out['sites'] = (int) $r['sites'];
            }
            return $out;
        };

        $a = array_map(fn ($r) => $shape($r, 'active'), $active);
        $e = array_map(fn ($r) => $shape($r, 'ended'), $ended);
        $n = array_map(fn ($r) => $shape($r, 'never'), $never);

        // إجابات العميل في التسجيل الذكي (بيحجز إزاي، الموسم، الهدف، نظام موقعه) - تفيد في المكالمة
        $plans = [];
        try {
            if (class_exists('GrowthPlanService')) {
                $plans = (new GrowthPlanService())->answersFor(array_merge(array_column($a, 'id'), array_column($e, 'id'), array_column($n, 'id')));
            }
        } catch (Throwable $ex) {
        }
        $attach = function (array $x) use ($plans): array {
            $x['plan'] = isset($plans[(int) $x['id']]) ? self::planChips($plans[(int) $x['id']]) : null;
            return $x;
        };
        $a = array_map($attach, $a);
        $e = array_map($attach, $e);
        $n = array_map($attach, $n);

        $checks = $this->freeChecks();
        return [
            'active' => $a, 'ended' => $e, 'never' => $n, 'checks' => $checks,
            'stats' => [
                'active' => count($a),
                'ending_24h' => count(array_filter($a, fn ($x) => $x['hours_left'] <= 24)),
                'ended' => count($e),
                'never' => count($n),
                'checks' => count($checks),
                'checks_unregistered' => count(array_filter($checks, fn ($x) => !$x['registered'])),
            ] + $this->conversion(),
        ];
    }

    /** إجابات خطة النمو → كلام مفهوم للأدمن */
    private static function planChips(array $p): array
    {
        $ans = $p['answers'] ?? [];
        $book = ['whatsapp' => 'واتساب', 'website' => 'موقعه', 'otas' => 'Booking/TripAdvisor/Viator', 'agents' => 'وكلاء', 'social' => 'سوشيال'];
        $season = ['winter' => 'الشتا', 'summer' => 'الصيف', 'all_year' => 'طول السنة'];
        $goal = ['direct_bookings' => 'حجوزات مباشرة وعمولة أقل', 'google' => 'يظهر أعلى في جوجل', 'reviews' => 'تقييمات أكتر', 'beat_competitors' => 'يسبق منافسينه'];
        $out = [];
        if (!empty($ans['booking'])) $out[] = ['بيحجز عن طريق', implode('، ', array_map(fn ($b) => $book[$b] ?? $b, $ans['booking']))];
        if (!empty($ans['season'])) $out[] = ['موسمه', $season[$ans['season']] ?? $ans['season']];
        if (!empty($ans['goal'])) $out[] = ['هدفه', $goal[$ans['goal']] ?? $ans['goal']];
        if (!empty($ans['platform'])) $out[] = ['موقعه على', ucfirst((string) $ans['platform']) . (!empty($ans['booking_engines']) ? ' · ' . implode('، ', $ans['booking_engines']) : '')];
        $kw = array_values(array_filter(array_map(fn ($k) => is_array($k) ? ($k['keyword'] ?? '') : (string) $k, $p['keywords'] ?? [])));
        if ($kw) $out[] = ['كلماته', implode('، ', array_slice($kw, 0, 3))];
        return $out;
    }

    /**
     * المواقع اللي اتفحصت من «افحص موقعك مجانًا» (آخر 200): الدومين والنتيجة،
     * وهل صاحبها سجّل (موقع مضاف بنفس الدومين أو إيميل على نفس الدومين).
     */
    private function freeChecks(): array
    {
        try {
            $log = json_decode((new SystemSettingsService())->get('free_checks_recent', '[]'), true) ?: [];
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($log as $r) {
            $host = strtolower((string) ($r['host'] ?? ''));
            $bare = preg_replace('/^www\./', '', $host);
            if ($bare === '' || isset($seen[$bare])) {
                continue; // آخر فحص بس لكل دومين
            }
            $seen[$bare] = true;
            $count = count(array_filter($log, fn ($x) => preg_replace('/^www\./', '', strtolower((string) ($x['host'] ?? ''))) === $bare));
            $reg = $this->rows(
                "SELECT u.id FROM users u WHERE u.deleted_at IS NULL AND (LOWER(u.email) LIKE ? OR EXISTS (SELECT 1 FROM websites w WHERE w.user_id = u.id AND LOWER(w.url) LIKE ?)) LIMIT 1",
                ['%@' . $bare, '%' . $bare . '%']
            );
            $out[] = ['host' => $bare, 'score' => (int) ($r['score'] ?? 0), 'lang' => (string) ($r['lang'] ?? ''), 'at' => (string) ($r['at'] ?? ''), 'times' => $count,
                      'registered' => (bool) $reg, 'user_id' => $reg ? (int) $reg[0]['id'] : null];
        }
        return $out;
    }

    /** التحويل الحقيقي آخر 90 يوم: كام واحد بدأ تجربة، وكام واحد منهم اشترك بفلوس بعدها */
    private function conversion(): array
    {
        $r = $this->rows(
            "SELECT COUNT(DISTINCT t.user_id) AS started,
                    COUNT(DISTINCT CASE WHEN EXISTS (SELECT 1 FROM subscriptions p WHERE p.user_id = t.user_id AND COALESCE(p.price,0) > 0
                                                       AND p.status IN ('active','past_due','cancelled','expired') AND p.id > t.id) THEN t.user_id END) AS converted
               FROM subscriptions t JOIN users u ON u.id = t.user_id
              WHERE t.trial_ends_at IS NOT NULL AND COALESCE(t.price,0) = 0 AND t.created_at > NOW() - INTERVAL 90 DAY AND " . self::CUSTOMER
        )[0] ?? [];
        $started = (int) ($r['started'] ?? 0);
        $converted = (int) ($r['converted'] ?? 0);
        return ['trials_90d' => $started, 'converted_90d' => $converted, 'conversion_pct' => $started > 0 ? round($converted / $started * 100, 1) : null];
    }

    private function name(array $r): string
    {
        foreach ([$r['company_name'] ?? '', $r['display_name'] ?? '', trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))] as $v) {
            if (trim((string) $v) !== '') {
                return trim((string) $v);
            }
        }
        return (string) strtok((string) $r['email'], '@');
    }

    /** رقم واتساب دولي (أرقام بس) أو فاضي لو مش واضح */
    private function waNumber(string $phone, string $country): string
    {
        $d = preg_replace('/\D/', '', $phone);
        if ($d === '') {
            return '';
        }
        if (strpos($d, '00') === 0) {
            return substr($d, 2);
        }
        if (strpos(trim($phone), '+') === 0) {
            return $d;
        }
        if ($d[0] === '0' && isset(self::DIAL[strtoupper($country)])) {
            return self::DIAL[strtoupper($country)] . substr($d, 1);
        }
        return strlen($d) >= 10 ? $d : '';
    }

    /**
     * مدّ التجربة (أو رجّعها لو خلصت) عدد أيام.
     * @return array{ok:bool, error?:string, ends_at?:string}
     */
    public function extend(int $subId, int $days, int $actorId = 0): array
    {
        $days = max(1, min(14, $days));
        $sub = $this->rows('SELECT id, user_id, trial_ends_at, price, status FROM subscriptions WHERE id = ? LIMIT 1', [$subId])[0] ?? null;
        if (!$sub || empty($sub['trial_ends_at']) || (float) ($sub['price'] ?? 0) > 0) {
            return ['ok' => false, 'error' => 'ده مش اشتراك تجربة'];
        }
        $uid = (int) $sub['user_id'];
        $paid = $this->rows("SELECT id FROM subscriptions WHERE user_id = ? AND id <> ? AND status IN ('active','past_due','trialing') LIMIT 1", [$uid, $subId]);
        if ($paid) {
            return ['ok' => false, 'error' => 'العميل عنده اشتراك تاني شغّال'];
        }
        $this->db->exec(
            "UPDATE subscriptions SET status = 'trialing',
                    trial_ends_at = DATE_ADD(GREATEST(NOW(), trial_ends_at), INTERVAL ? DAY),
                    current_period_end = DATE_ADD(GREATEST(NOW(), trial_ends_at), INTERVAL ? DAY),
                    cancel_at_period_end = 0, updated_at = NOW()
              WHERE id = ?",
            [$days, $days, $subId]
        );
        $end = (string) ($this->rows('SELECT trial_ends_at FROM subscriptions WHERE id = ?', [$subId])[0]['trial_ends_at'] ?? '');
        if (class_exists('Notification')) {
            Notification::notify($uid, 'trial_extended', '🎁 تجربتك المجانية اتمدّت', 'اتضافلك ' . $days . ' ' . ($days === 1 ? 'يوم' : ($days === 2 ? 'يومين' : 'أيام')) . ' على التجربة المجانية - كل الخدمات شغّالة لحد ' . substr($end, 0, 10) . '.', '/dashboard');
        }
        if (class_exists('ActivityLog')) {
            ActivityLog::record('subscription', 'subscription.trial_extended', ['user_id' => $actorId, 'subject_type' => 'subscriptions', 'subject_id' => $subId, 'meta' => ['target_user_id' => $uid, 'days' => $days]]);
        }
        return ['ok' => true, 'ends_at' => $end];
    }
}
