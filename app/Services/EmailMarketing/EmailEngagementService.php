<?php

/**
 * Tourfecto - Email Engagement Service
 * @version 1.0.0
 *
 * 1) تصنيف الفتح: من 2021 Apple Mail Privacy Protection بيحمّل بكسل التتبع
 *    لكل رسالة توصل (حتى لو محدش فتحها)، وفلاتر الأمان في الشركات
 *    (Mimecast/Proofpoint/Barracuda...) بتفتح الرسالة ثواني بعد التسليم.
 *    الاتنين بيضخّموا معدل الفتح ويشغّلوا أتمتة "عند الفتح" غلط. هنا كل فتح
 *    بيتسجّل نوعه (human / apple_mpp / scanner) ومعدل الفتح الحقيقي بيتحسب
 *    من الـ human بس. Gmail/Yahoo proxies بتحمّل الصورة لما المستخدم يفتح
 *    فعلًا، فبتتحسب human.
 *
 * 2) أفضل وقت للإرسال (Send-Time Optimization): الساعة اللي المشترك بيفتح/
 *    يضغط فيها أكتر (من تفاعلاته الحقيقية بس) بتتحفظ في optimal_send_hour،
 *    والحملة اللي فعّلت الخيار بتأجّل كل مستلم لأقرب موعد بالساعة دي خلال
 *    24 ساعة — عبر next_attempt_at اللي sendBatch بيحترمه أصلًا.
 *
 * الساعات بتوقيت قاعدة البيانات (نفس NOW() اللي بيكتب opened_at وsent_at).
 */
class EmailEngagementService
{
    public const TYPE_HUMAN = 'human';
    public const TYPE_APPLE_MPP = 'apple_mpp';
    public const TYPE_SCANNER = 'scanner';

    /** فتح أسرع من كده بعد التسليم = فحص آلي */
    public const SCANNER_SECONDS = 5;

    private const SCANNER_UA = [
        'barracuda', 'mimecast', 'proofpoint', 'symantec', 'messagelabs', 'trendmicro', 'forcepoint',
        'fireeye', 'sophos', 'cisco', 'ironport', 'zscaler', 'bot', 'crawler', 'spider', 'python',
        'curl', 'wget', 'go-http-client', 'java/', 'okhttp', 'headless', 'phantomjs', 'preview',
    ];

    /** Gmail/Yahoo/Outlook بيحمّلوا الصورة لما المستخدم يفتح فعلًا */
    private const HUMAN_PROXY_UA = ['googleimageproxy', 'yahoomailproxy', 'microsoft office', 'outlook', 'thunderbird'];

    /** @var Database */
    private $db;

    public function __construct($db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * تصنيف نقي لحدث فتح.
     * @param int|null $secondsSinceSent الثواني من الإرسال لأول فتح (null = مش معروف)
     */
    public static function classifyOpen(?string $userAgent, ?int $secondsSinceSent): string
    {
        $ua = strtolower(trim((string) $userAgent));
        foreach (self::HUMAN_PROXY_UA as $proxy) {
            if ($ua !== '' && strpos($ua, $proxy) !== false) {
                return self::TYPE_HUMAN;
            }
        }
        // Apple MPP بيستخدم UA مختصر جدًا "Mozilla/5.0" من غير أي تفاصيل
        if ($ua === 'mozilla/5.0') {
            return self::TYPE_APPLE_MPP;
        }
        if ($ua === '') {
            return self::TYPE_SCANNER;
        }
        foreach (self::SCANNER_UA as $needle) {
            if (strpos($ua, $needle) !== false) {
                return self::TYPE_SCANNER;
            }
        }
        if ($secondsSinceSent !== null && $secondsSinceSent >= 0 && $secondsSinceSent < self::SCANNER_SECONDS) {
            return self::TYPE_SCANNER;
        }
        return self::TYPE_HUMAN;
    }

    /**
     * يسجّل تفاصيل الفتح على مستلم الحملة ويرجّع نوعه.
     * أول تصنيف human بيفضل human (فتح آلي بعده ما يلغيش فتح حقيقي).
     */
    public function recordCampaignOpen(int $recipientId, ?string $userAgent, ?string $ip): string
    {
        try {
            $row = $this->db->query(
                "SELECT TIMESTAMPDIFF(SECOND, sent_at, NOW()) AS secs, is_human_open FROM email_campaign_recipients WHERE id = ? LIMIT 1",
                [$recipientId]
            )[0] ?? null;
            $secs = isset($row['secs']) && $row['secs'] !== null ? (int) $row['secs'] : null;
            $type = self::classifyOpen($userAgent, $secs);
            $this->db->query(
                "UPDATE email_campaign_recipients
                 SET open_type = CASE WHEN is_human_open = 1 THEN open_type ELSE ? END,
                     is_human_open = GREATEST(is_human_open, ?),  -- بعد open_type: MySQL بيقيّم الـ SET بالترتيب
                     user_agent = COALESCE(user_agent, ?),
                     ip_address = COALESCE(ip_address, ?)
                 WHERE id = ?",
                [$type, $type === self::TYPE_HUMAN ? 1 : 0, $userAgent !== null ? substr($userAgent, 0, 512) : null, $ip !== null ? substr($ip, 0, 64) : null, $recipientId]
            );
            return $type;
        } catch (\Throwable $e) {
            // أعمدة enterprise upgrade لسه مش موجودة: نعتبره حقيقي زي السلوك القديم
            return self::TYPE_HUMAN;
        }
    }

    /** الساعة الأكتر تفاعلًا (فتح حقيقي أو كليك) للمشترك ده، وتتحفظ عليه. */
    public function refreshOptimalHour(int $subscriberId): ?int
    {
        if ($subscriberId <= 0) {
            return null;
        }
        try {
            $rows = $this->db->query(
                "SELECT h, COUNT(*) AS n FROM (
                    SELECT HOUR(opened_at) AS h FROM email_campaign_recipients
                     WHERE subscriber_id = ? AND opened_at IS NOT NULL AND (is_human_open = 1 OR open_type IS NULL) AND opened_at >= DATE_SUB(NOW(), INTERVAL 180 DAY)
                    UNION ALL
                    SELECT HOUR(clicked_at) AS h FROM email_campaign_recipients
                     WHERE subscriber_id = ? AND clicked_at IS NOT NULL AND clicked_at >= DATE_SUB(NOW(), INTERVAL 180 DAY)
                 ) t GROUP BY h ORDER BY n DESC, h ASC LIMIT 1",
                [$subscriberId, $subscriberId]
            );
            if (empty($rows)) {
                return null;
            }
            $hour = (int) $rows[0]['h'];
            $this->db->query("UPDATE email_subscribers SET optimal_send_hour = ?, last_engaged_at = NOW() WHERE id = ?", [$hour, $subscriberId]);
            return $hour;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** أقرب لحظة (timestamp) الساعة فيها = $hour، بعد $now مباشرة (خلال 24 ساعة). */
    public static function nextSlot(int $hour, int $now): int
    {
        $hour = max(0, min(23, $hour));
        $slot = mktime($hour, 0, 0, (int) date('n', $now), (int) date('j', $now), (int) date('Y', $now));
        if ($slot <= $now) {
            $slot = mktime($hour, 0, 0, (int) date('n', $now), (int) date('j', $now) + 1, (int) date('Y', $now));
        }
        return $slot;
    }

    /**
     * تأجيل مستلمي الحملة (pending) لأفضل ساعة لكل واحد. اللي ملوش تاريخ
     * تفاعل بيتبعت فورًا. next_attempt_at بيتقارن بـ UTC في sendBatch.
     * @return int عدد المستلمين اللي اتأجّلوا
     */
    public function applySendTimeOptimization(int $campaignId): int
    {
        try {
            $hours = $this->db->query(
                "SELECT DISTINCT s.optimal_send_hour AS h FROM email_campaign_recipients r
                 JOIN email_subscribers s ON s.id = r.subscriber_id
                 WHERE r.campaign_id = ? AND r.status = 'pending' AND s.optimal_send_hour IS NOT NULL",
                [$campaignId]
            );
            $dbNow = strtotime((string) ($this->db->query("SELECT NOW() AS n")[0]['n'] ?? date('Y-m-d H:i:s')));
            $utcOffset = $dbNow - time(); // فرق توقيت قاعدة البيانات عن UTC (عشان next_attempt_at بالـ UTC)
            $deferred = 0;
            $pdo = $this->db->getConnection();
            foreach ($hours as $row) {
                $slotDb = self::nextSlot((int) $row['h'], $dbNow);
                // أقل من ساعة؟ ابعت دلوقتي بدل تأخير بلا داعي
                if ($slotDb - $dbNow < 3600) {
                    continue;
                }
                $slotUtc = gmdate('Y-m-d H:i:s', $slotDb - $utcOffset);
                $q = $pdo->prepare(
                    "UPDATE email_campaign_recipients r JOIN email_subscribers s ON s.id = r.subscriber_id
                     SET r.next_attempt_at = ?
                     WHERE r.campaign_id = ? AND r.status = 'pending' AND s.optimal_send_hour = ?"
                );
                $q->execute([$slotUtc, $campaignId, (int) $row['h']]);
                $deferred += $q->rowCount();
            }
            return $deferred;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * سلسلة يومية لآخر $days يوم: مرسل / فتح حقيقي / كليك / حجوزات وإيراد من البريد.
     * @return array{days:string[],sent:int[],opens:int[],clicks:int[],bookings:int[],revenue:float[]}
     */
    public function timeseries(int $userId, int $days = 30): array
    {
        $days = max(7, min(180, $days));
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $series = ['sent' => [], 'opens' => [], 'clicks' => [], 'bookings' => [], 'revenue' => []];
        $queries = [
            'sent' => "SELECT DATE(r.sent_at) AS d, COUNT(*) AS n FROM email_campaign_recipients r JOIN email_campaigns c ON c.id = r.campaign_id
                       WHERE c.user_id = ? AND r.sent_at >= ? GROUP BY DATE(r.sent_at)",
            'opens' => "SELECT DATE(r.opened_at) AS d, COUNT(*) AS n FROM email_campaign_recipients r JOIN email_campaigns c ON c.id = r.campaign_id
                       WHERE c.user_id = ? AND r.opened_at >= ? AND (r.is_human_open = 1 OR r.open_type IS NULL) GROUP BY DATE(r.opened_at)",
            'clicks' => "SELECT DATE(r.clicked_at) AS d, COUNT(*) AS n FROM email_campaign_recipients r JOIN email_campaigns c ON c.id = r.campaign_id
                       WHERE c.user_id = ? AND r.clicked_at >= ? GROUP BY DATE(r.clicked_at)",
            'bookings' => "SELECT DATE(credited_at) AS d, COUNT(*) AS n FROM email_booking_attribution
                       WHERE user_id = ? AND credited_at >= ? AND reversed_at IS NULL GROUP BY DATE(credited_at)",
            'revenue' => "SELECT DATE(credited_at) AS d, SUM(amount) AS n FROM email_booking_attribution
                       WHERE user_id = ? AND credited_at >= ? AND reversed_at IS NULL GROUP BY DATE(credited_at)",
        ];
        foreach ($queries as $key => $sql) {
            try {
                $series[$key] = $this->db->query($sql, [$userId, $from . ' 00:00:00']);
            } catch (\Throwable $e) {
                $series[$key] = []; // جدول/عمود لسه ما اتعملش
            }
        }
        return self::fillDays($series, $from, $days);
    }

    /** تحويل صفوف (d, n) لمصفوفات بطول ثابت فيها كل يوم (الأيام الفاضية = 0) */
    public static function fillDays(array $series, string $from, int $days): array
    {
        $labels = [];
        $start = strtotime($from . ' 00:00:00');
        for ($i = 0; $i < $days; $i++) {
            $labels[] = date('Y-m-d', $start + $i * 86400 + 3600); // +ساعة تفادي فرق التوقيت الصيفي
        }
        $out = ['days' => $labels];
        foreach ($series as $key => $rows) {
            $map = [];
            foreach ($rows as $r) {
                $map[(string) $r['d']] = $key === 'revenue' ? (float) $r['n'] : (int) $r['n'];
            }
            $out[$key] = array_map(fn ($d) => $map[$d] ?? ($key === 'revenue' ? 0.0 : 0), $labels);
        }
        return $out;
    }

    /** إحصاءات الفتح الحقيقي لحملة */
    public function campaignOpenBreakdown(int $campaignId): array
    {
        try {
            $rows = $this->db->query(
                "SELECT COALESCE(open_type, 'human') AS t, COUNT(*) AS n, SUM(CASE WHEN is_human_open = 1 OR open_type IS NULL THEN 1 ELSE 0 END) AS human
                 FROM email_campaign_recipients WHERE campaign_id = ? AND opened_at IS NOT NULL GROUP BY COALESCE(open_type, 'human')",
                [$campaignId]
            );
        } catch (\Throwable $e) {
            return ['human' => null, 'apple_mpp' => 0, 'scanner' => 0];
        }
        $out = ['human' => 0, 'apple_mpp' => 0, 'scanner' => 0];
        foreach ($rows as $r) {
            $out['human'] += (int) $r['human'];
            if (isset($out[$r['t']]) && $r['t'] !== 'human') {
                $out[$r['t']] += (int) $r['n'] - (int) $r['human'];
            }
        }
        return $out;
    }
}
