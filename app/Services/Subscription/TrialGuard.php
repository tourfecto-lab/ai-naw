<?php

/**
 * حماية التجربة المجانية من التكرار: نفس الشخص مايعملش حسابات جديدة عشان يفضل على المجاني.
 *
 * بنسجّل «بصمات» لكل تجربة (كلها hash، من غير بيانات خام):
 *  - email:  الإيميل بعد التوحيد (Gmail: من غير النقط و+tag) - a.b+1@gmail.com = ab@gmail.com
 *  - device: كوكي tf_dev طويلة العمر على المتصفح
 *  - ip:     عنوان الـ IP (مسموح تجربتين بس من نفس الـ IP في 30 يوم - شبكات الموبايل بتشارك IP)
 *  - site:   دومين موقع الشركة (اللي بيتكتب في الإعداد الأولي)
 * الأدمن يقدر يدّي تجربة يدوي لأي حد (من «متابعة التجارب») - الحماية دي على التجربة التلقائية بس.
 */
class TrialGuard
{
    private const DEVICE_COOKIE = 'tf_dev';
    private const IP_LIMIT = 2;
    private const IP_WINDOW_DAYS = 30;

    /** دومينات الإيميلات المؤقتة الأشهر */
    private const DISPOSABLE = [
        'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'sharklasers.com', '10minutemail.com', '10minutemail.net',
        'tempmail.com', 'temp-mail.org', 'temp-mail.io', 'tempmail.net', 'tempmailo.com', 'yopmail.com', 'yopmail.net',
        'throwawaymail.com', 'getnada.com', 'nada.email', 'trashmail.com', 'dispostable.com', 'maildrop.cc', 'mintemail.com',
        'mohmal.com', 'emailondeck.com', 'fakeinbox.com', 'mailnesia.com', 'moakt.com', 'tmail.ws', 'tmpmail.org', 'tmpmail.net',
        'burnermail.io', 'spamgourmet.com', 'mailcatch.com', 'inboxkitten.com', 'emailfake.com', 'luxusmail.org', 'mail.tm',
        'dropmail.me', '1secmail.com', '1secmail.net', 'grr.la', 'byom.de', 'trbvm.com', 'cuvox.de', 'armyspy.com', 'dayrep.com',
    ];

    /** منصات مشتركة: الحساب بيتعرف بأول جزء في المسار مش بالدومين (facebook.com/xyz) */
    private const SHARED_HOSTS = [
        'facebook.com', 'm.facebook.com', 'fb.com', 'instagram.com', 'tripadvisor.com', 'tiktok.com', 'linktr.ee', 'wa.me',
        'google.com', 'maps.google.com', 'g.page', 'goo.gl', 'youtube.com', 'x.com', 'twitter.com', 'linkedin.com',
        'viator.com', 'getyourguide.com', 'booking.com', 'airbnb.com', 'tourfecto.pro', 'tourfecto.com',
    ];

    public static function ensureSchema(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS trial_fingerprints (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            kind VARCHAR(12) NOT NULL,
            value_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_kind_value (user_id, kind, value_hash),
            KEY idx_kind_value (kind, value_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    }

    private static function pdo(): PDO
    {
        $pdo = Database::getInstance()->getConnection();
        self::ensureSchema($pdo);
        return $pdo;
    }

    private static function hash(string $kind, string $value): string
    {
        $salt = (string) ($_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: 'tf-trial');
        return hash_hmac('sha256', $kind . '|' . $value, $salt);
    }

    public static function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if (!str_contains($email, '@')) {
            return $email;
        }
        [$local, $domain] = explode('@', $email, 2);
        $local = explode('+', $local, 2)[0];
        if ($domain === 'googlemail.com') {
            $domain = 'gmail.com';
        }
        if ($domain === 'gmail.com') {
            $local = str_replace('.', '', $local);
        }
        return $local . '@' . $domain;
    }

    public static function isDisposable(string $email): bool
    {
        $domain = strtolower(substr(strrchr(trim($email), '@') ?: '', 1));
        return $domain !== '' && in_array($domain, self::DISPOSABLE, true);
    }

    /** مفتاح الموقع: الدومين من غير www، أو دومين + أول جزء للمنصات المشتركة */
    public static function siteKey(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^(www\.|m\.)/', '', $host);
        if ($host === '' || !str_contains($host, '.')) {
            return null;
        }
        foreach (self::SHARED_HOSTS as $shared) {
            if ($host === $shared || str_ends_with($host, '.' . $shared) || preg_match('/(^|\.)tripadvisor\.[a-z.]+$|(^|\.)google\.[a-z.]+$/', $host)) {
                $seg = strtolower(trim(explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/'))[0] ?? ''));
                return $seg !== '' ? $host . '/' . $seg : null;
            }
        }
        return $host;
    }

    /** كوكي الجهاز - بتتعمل مع أول زيارة لصفحة التسجيل/الدخول وتعيش سنتين */
    public static function deviceId(bool $create = true): ?string
    {
        $id = (string) ($_COOKIE[self::DEVICE_COOKIE] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/', $id)) {
            return $id;
        }
        if (!$create || headers_sent() || PHP_SAPI === 'cli') {
            return null;
        }
        $id = bin2hex(random_bytes(16));
        setcookie(self::DEVICE_COOKIE, $id, [
            'expires' => time() + 2 * 365 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
        $_COOKIE[self::DEVICE_COOKIE] = $id;
        return $id;
    }

    private static function ip(): string
    {
        return function_exists('get_client_ip') ? (string) get_client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /** مستخدم تاني (غير $uid) عنده نفس البصمة */
    private static function seenElsewhere(PDO $pdo, int $uid, string $kind, string $hash): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM trial_fingerprints WHERE kind=? AND value_hash=? AND user_id<>? LIMIT 1');
        $st->execute([$kind, $hash, $uid]);
        return (bool) $st->fetchColumn();
    }

    /**
     * ليه الحساب ده مايستاهلش تجربة تلقائية؟ (null = تمام)
     * بيتنادى من طلب العميل نفسه (عشان الكوكي والـ IP يبقوا بتوعه هو).
     */
    public static function blockedReason(int $uid): ?string
    {
        try {
            $pdo = self::pdo();
            $st = $pdo->prepare('SELECT email FROM users WHERE id=?');
            $st->execute([$uid]);
            $email = (string) $st->fetchColumn();
            if ($email !== '' && self::isDisposable($email)) {
                return 'disposable_email';
            }
            if ($email !== '' && self::seenElsewhere($pdo, $uid, 'email', self::hash('email', self::normalizeEmail($email)))) {
                return 'email_used';
            }
            $dev = self::deviceId(false);
            if ($dev && self::seenElsewhere($pdo, $uid, 'device', self::hash('device', $dev))) {
                return 'device_used';
            }
            $ip = self::ip();
            if ($ip !== '' && $ip !== '127.0.0.1' && $ip !== '::1') {
                $st = $pdo->prepare('SELECT COUNT(DISTINCT user_id) FROM trial_fingerprints WHERE kind=\'ip\' AND value_hash=? AND user_id<>? AND created_at > DATE_SUB(NOW(), INTERVAL ' . self::IP_WINDOW_DAYS . ' DAY)');
                $st->execute([self::hash('ip', $ip), $uid]);
                if ((int) $st->fetchColumn() >= self::IP_LIMIT) {
                    return 'ip_limit';
                }
            }
            $st = $pdo->prepare('SELECT main_url FROM websites WHERE user_id=? AND main_url IS NOT NULL');
            $st->execute([$uid]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $url) {
                $key = self::siteKey((string) $url);
                if ($key && self::seenElsewhere($pdo, $uid, 'site', self::hash('site', $key))) {
                    return 'site_used';
                }
            }
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('TrialGuard check failed', ['user_id' => $uid, 'error' => $e->getMessage()]);
            }
        }
        return null;
    }

    public static function reasonText(string $reason): string
    {
        if (function_exists('current_lang') && !str_starts_with(current_lang(), 'ar')) {
            return [
                'disposable_email' => 'The free trial is not available for temporary email addresses. Sign up with your business email or choose a plan.',
                'email_used' => 'This email has already used the free trial. Choose a plan to continue.',
                'device_used' => 'This device has already used the free trial on another account. Choose a plan to continue.',
                'ip_limit' => 'The free trial has been used several times from this network. Choose a plan, or contact us if this is a mistake.',
                'site_used' => 'This website has already used the free trial on another account. Choose a plan to continue.',
            ][$reason] ?? 'The free trial is not available for this account. Choose a plan to continue.';
        }
        return [
            'disposable_email' => 'التجربة المجانية مش متاحة للإيميلات المؤقتة. سجّل بإيميل شغلك الحقيقي أو اختار باقة.',
            'email_used' => 'الإيميل ده استخدم التجربة المجانية قبل كده. اختار باقتك عشان تكمّل.',
            'device_used' => 'الجهاز ده استخدم التجربة المجانية قبل كده على حساب تاني. اختار باقتك عشان تكمّل.',
            'ip_limit' => 'التجربة المجانية اتستخدمت كذا مرة من نفس الشبكة. اختار باقتك أو كلّمنا لو ده خطأ.',
            'site_used' => 'الموقع ده استخدم التجربة المجانية قبل كده على حساب تاني. اختار باقتك عشان تكمّل.',
        ][$reason] ?? 'التجربة المجانية مش متاحة للحساب ده. اختار باقتك عشان تكمّل.';
    }

    /** نسجّل بصمات التجربة. $fromRequest=false لما الأدمن هو اللي بيفعّل (الكوكي والـ IP بتوعه مش بتوع العميل) */
    public static function record(int $uid, bool $fromRequest = true): void
    {
        try {
            $pdo = self::pdo();
            $rows = [];
            $st = $pdo->prepare('SELECT email FROM users WHERE id=?');
            $st->execute([$uid]);
            $email = (string) $st->fetchColumn();
            if ($email !== '') {
                $rows[] = ['email', self::hash('email', self::normalizeEmail($email))];
            }
            if ($fromRequest) {
                if ($dev = self::deviceId()) {
                    $rows[] = ['device', self::hash('device', $dev)];
                }
                $ip = self::ip();
                if ($ip !== '') {
                    $rows[] = ['ip', self::hash('ip', $ip)];
                }
            }
            $st = $pdo->prepare('SELECT main_url FROM websites WHERE user_id=? AND main_url IS NOT NULL');
            $st->execute([$uid]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $url) {
                if ($key = self::siteKey((string) $url)) {
                    $rows[] = ['site', self::hash('site', $key)];
                }
            }
            $ins = $pdo->prepare('INSERT IGNORE INTO trial_fingerprints (user_id, kind, value_hash) VALUES (?, ?, ?)');
            foreach ($rows as [$kind, $hash]) {
                $ins->execute([$uid, $kind, $hash]);
            }
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('TrialGuard record failed', ['user_id' => $uid, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * العميل كتب موقعه في الإعداد الأولي وهو في التجربة: لو الموقع ده خد تجربة قبل كده
     * على حساب تاني، التجربة دي بتخلص فورًا (الحساب الجديد معمول عشان يفضل على المجاني).
     * @return bool true لو التجربة اتقفلت
     */
    public static function onWebsiteSaved(int $uid, string $url): bool
    {
        try {
            $key = self::siteKey($url);
            if (!$key) {
                return false;
            }
            $pdo = self::pdo();
            $hash = self::hash('site', $key);
            $st = $pdo->prepare("SELECT id FROM subscriptions WHERE user_id=? AND status='trialing' AND trial_ends_at > NOW() ORDER BY id DESC LIMIT 1");
            $st->execute([$uid]);
            $subId = (int) $st->fetchColumn();
            if ($subId && self::seenElsewhere($pdo, $uid, 'site', $hash)) {
                // التجربة اللي الأدمن فعّلها يدوي مابنلمسهاش
                $st = $pdo->prepare("SELECT 1 FROM activity_logs WHERE subject_type='subscriptions' AND subject_id=? AND action='subscription.trial_started' AND JSON_EXTRACT(meta,'$.by_admin')=true LIMIT 1");
                try {
                    $st->execute([$subId]);
                    if ($st->fetchColumn()) {
                        return false;
                    }
                } catch (Throwable $e) {
                }
                $pdo->prepare("UPDATE subscriptions SET trial_ends_at=NOW(), current_period_end=NOW(), status='past_due', cancel_at_period_end=0, updated_at=NOW() WHERE id=?")->execute([$subId]);
                if (class_exists('Notification')) {
                    Notification::notify($uid, 'subscription_trial_ended', 'التجربة المجانية مش متاحة', self::reasonText('site_used'), '/plans');
                }
                if (class_exists('ActivityLog')) {
                    ActivityLog::record('subscription', 'subscription.trial_blocked_duplicate', ['user_id' => $uid, 'subject_type' => 'subscriptions', 'subject_id' => $subId, 'meta' => ['reason' => 'site_used', 'site' => $key]]);
                }
                return true;
            }
            $st = $pdo->prepare("SELECT 1 FROM subscriptions WHERE user_id=? AND (status='trialing' OR trial_ends_at IS NOT NULL) LIMIT 1");
            $st->execute([$uid]);
            if ($st->fetchColumn()) {
                $pdo->prepare('INSERT IGNORE INTO trial_fingerprints (user_id, kind, value_hash) VALUES (?, \'site\', ?)')->execute([$uid, $hash]);
            }
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('TrialGuard site check failed', ['user_id' => $uid, 'error' => $e->getMessage()]);
            }
        }
        return false;
    }
}
