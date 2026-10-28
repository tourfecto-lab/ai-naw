<?php

/**
 * Tourfecto - تأكيد البريد الإلكتروني
 *
 * إزاي بيشتغل:
 *  1. أول ما العميل يسجّل، بنبعتله إيميل فيه رابط /verify-email/{token}
 *     (التوكن بيتخزن مشفّر sha256 وصلاحيته 24 ساعة).
 *  2. العميل يقدر يستخدم المنصة عادي لمدة "مهلة" (افتراضي 72 ساعة)
 *     وفوق اللوحة بيظهرله شريط "أكّد بريدك" فيه زرار إعادة إرسال.
 *  3. بعد المهلة، صفحات اللوحة بتحوّله لصفحة /verify-email لحد ما يأكّد.
 *
 * مابيتقفلش على:
 *  - الأدمن، وأعضاء فريق العمل (اللي صاحب الحساب ضافهم - إيميلهم اتعرف من الدعوة).
 *  - الحسابات القديمة (الـ migration بيعلّمها متأكدة).
 *  - لو البريد (SMTP) مش متظبط - عشان محدش يتقفل بسبب إعداد ناقص عندنا.
 *  - لو جدول التوكنات مش موجود (الـ migration لسه ماتشغّلش).
 *
 * إعدادات اختيارية في .env:
 *   EMAIL_VERIFICATION_REQUIRED=true   (false = الشريط بس من غير قفل)
 *   EMAIL_VERIFICATION_GRACE_HOURS=72
 */
class EmailVerification
{
    private const TOKEN_TTL_HOURS = 24;
    private const ADMIN_ROLES = ['super_admin', 'admin'];

    private static ?bool $tableReady = null;

    public static function tableReady(): bool
    {
        if (self::$tableReady !== null) {
            return self::$tableReady;
        }
        try {
            $rows = Database::getInstance()->query("SHOW TABLES LIKE 'email_verification_tokens'");
            self::$tableReady = !empty($rows);
        } catch (Throwable $e) {
            self::$tableReady = false;
        }
        return self::$tableReady;
    }

    public static function graceHours(): int
    {
        $v = function_exists('env') ? env('EMAIL_VERIFICATION_GRACE_HOURS') : getenv('EMAIL_VERIFICATION_GRACE_HOURS');
        $h = ($v === null || $v === false || $v === '') ? 72 : (int) $v;
        return max(0, min($h, 24 * 30));
    }

    public static function enforcementOn(): bool
    {
        $v = function_exists('env') ? env('EMAIL_VERIFICATION_REQUIRED', 'default') : getenv('EMAIL_VERIFICATION_REQUIRED');
        if (is_bool($v)) {
            return $v;
        }
        if ($v === null || $v === false || $v === '' || $v === 'default') {
            return true;
        }
        return !in_array(strtolower((string) $v), ['0', 'false', 'no', 'off'], true);
    }

    /** @var array<int,array> */
    private static array $fresh = [];

    /**
     * بيانات المستخدم من الداتابيز مباشرة (بيانات السيشن ممكن تكون قديمة أو
     * ناقصها created_at حسب طريقة تسجيل الدخول).
     */
    private static function fresh(array $user): ?array
    {
        $id = (int) ($user['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        if (!array_key_exists($id, self::$fresh)) {
            try {
                $rows = Database::getInstance()->query(
                    'SELECT * FROM users WHERE id = ? LIMIT 1',
                    [$id]
                );
                $row = $rows[0] ?? null;
                if ($row) {
                    // تأمين: بس اللي اتسجّل بعد تفعيل الميزة (اتبعتله رابط) ممكن يتطلب منه تأكيد.
                    // كده الحسابات القديمة عمرها ما تتقفل حتى لو خطوة الـ migration اللي بتعلّمهم متأكدين ماتنفذتش.
                    $t = Database::getInstance()->query('SELECT 1 AS x FROM email_verification_tokens WHERE user_id = ? LIMIT 1', [$id]);
                    $row['_ev_enrolled'] = !empty($t);
                    $row = array_intersect_key($row, array_flip(['id', 'email', 'role', 'owner_user_id', 'email_verified', 'email_verified_at', 'created_at', '_ev_enrolled']));
                }
                self::$fresh[$id] = $row;
            } catch (Throwable $e) {
                self::$fresh[$id] = null;
            }
        }
        return self::$fresh[$id];
    }

    /** المستخدم ده محتاج يأكّد بريده؟ (من غير ما نحدد لسه هل نقفل ولا لأ) */
    public static function isPending(?array $user): bool
    {
        if (empty($user['id']) || !self::tableReady()) {
            return false;
        }
        $user = self::fresh($user);
        if (!$user) {
            return false;
        }
        if (!empty($user['email_verified_at']) || !empty($user['email_verified']) || empty($user['_ev_enrolled'])) {
            return false;
        }
        if (in_array((string) ($user['role'] ?? ''), self::ADMIN_ROLES, true)) {
            return false;
        }
        if (!empty($user['owner_user_id'])) {
            return false;
        }
        return true;
    }

    /** الساعات المتبقية من المهلة (0 = خلصت) */
    public static function hoursLeft(array $user): int
    {
        $user = self::fresh($user) ?? $user;
        $created = strtotime((string) ($user['created_at'] ?? '')) ?: time();
        $left = ($created + self::graceHours() * 3600) - time();
        return $left > 0 ? (int) ceil($left / 3600) : 0;
    }

    /** نقفل اللوحة عليه دلوقتي؟ */
    public static function mustBlock(?array $user): bool
    {
        if (!self::isPending($user) || !self::enforcementOn()) {
            return false;
        }
        if (self::hoursLeft($user) > 0) {
            return false;
        }
        try {
            if (!(new Mailer())->isConfigured()) {
                return false;
            }
        } catch (Throwable $e) {
            return false;
        }
        return true;
    }

    /**
     * بيعمل توكن جديد ويبعت الإيميل.
     * @return array{success:bool, error?:string, throttled?:bool}
     */
    public static function send(int $userId): array
    {
        if (!self::tableReady()) {
            return ['success' => false, 'error' => 'not_ready'];
        }
        $db = Database::getInstance();
        $rows = $db->query("SELECT id, email, first_name, company_name, language, email_verified_at FROM users WHERE id = ? LIMIT 1", [$userId]);
        if (empty($rows)) {
            return ['success' => false, 'error' => 'not_found'];
        }
        $u = $rows[0];
        if (!empty($u['email_verified_at'])) {
            return ['success' => true, 'already' => true];
        }

        // حماية من الإغراق: 5 إيميلات كحد أقصى في الساعة لنفس الحساب
        $recent = $db->query(
            "SELECT COUNT(*) AS c FROM email_verification_tokens WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)",
            [$userId]
        );
        if ((int) ($recent[0]['c'] ?? 0) >= 5) {
            return ['success' => false, 'throttled' => true, 'error' => 'throttled'];
        }

        $raw = bin2hex(random_bytes(32));
        // الروابط القديمة بتفضل شغّالة لحد ما تنتهي (24 ساعة): العميل اللي داس «ابعت تاني» كذا مرة
        // كان بيفتح إيميل أقدم (Gmail بيجمّعهم في محادثة واحدة) فيلاقي «الرابط مش شغّال».
        $db->query(
            "INSERT INTO email_verification_tokens (user_id, token_hash, expires_at, created_at) VALUES (?, ?, NOW() + INTERVAL " . self::TOKEN_TTL_HOURS . " HOUR, NOW())",
            [$userId, hash('sha256', $raw)]
        );

        $url = rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/verify-email/' . $raw;
        $name = trim((string) ($u['first_name'] ?: $u['company_name'] ?: ''));

        try {
            $mailer = new Mailer();
            if (!$mailer->isConfigured()) {
                Logger::warning('Email verification: mail not configured', ['user_id' => $userId]);
                return ['success' => false, 'error' => 'mail_not_configured'];
            }
            $lang = self::lang((string) ($u['language'] ?? ''));
            $res = $mailer->context('email_verification', $userId)
                ->send((string) $u['email'], $name, self::TEXT[$lang]['subject'], self::emailHtml($name, $url, $lang));
            if (empty($res['success'])) {
                Logger::error('Email verification send failed', ['user_id' => $userId, 'error' => $res['error'] ?? '']);
                return ['success' => false, 'error' => 'send_failed'];
            }
        } catch (Throwable $e) {
            Logger::error('Email verification exception', ['user_id' => $userId, 'message' => $e->getMessage()]);
            return ['success' => false, 'error' => 'send_failed'];
        }
        return ['success' => true];
    }

    /**
     * يتحقق من التوكن ويعلّم الحساب متأكد.
     * @return int|null رقم المستخدم لو نجح
     */
    public static function verify(string $rawToken): ?int
    {
        if (!self::tableReady() || !preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
            return null;
        }
        $db = Database::getInstance();
        $rows = $db->query(
            "SELECT id, user_id FROM email_verification_tokens
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1",
            [hash('sha256', $rawToken)]
        );
        if (empty($rows)) {
            // رابط قديم لحساب اتأكد خلاص (داس على إيميلين مثلًا): ده مش خطأ - نعتبره نجاح
            $done = $db->query(
                "SELECT t.user_id FROM email_verification_tokens t JOIN users u ON u.id = t.user_id
                 WHERE t.token_hash = ? AND u.email_verified_at IS NOT NULL AND t.created_at > (NOW() - INTERVAL 30 DAY) LIMIT 1",
                [hash('sha256', $rawToken)]
            );
            return $done ? (int) $done[0]['user_id'] : null;
        }
        $uid = (int) $rows[0]['user_id'];
        $db->exec("UPDATE email_verification_tokens SET used_at = NOW() WHERE id = ?", [(int) $rows[0]['id']]);
        self::markVerified($uid);
        return $uid;
    }

    public static function markVerified(int $userId): void
    {
        $db = Database::getInstance();
        $db->exec("UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?", [$userId]);
        try {
            $db->exec("UPDATE users SET email_verified = 1 WHERE id = ?", [$userId]); // عمود قديم، ممكن مايكونش موجود
        } catch (Throwable $e) {
        }
        unset(self::$fresh[$userId]);
        $db->exec("UPDATE email_verification_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL", [$userId]);
        if (session_status() === PHP_SESSION_ACTIVE && (int) ($_SESSION['user_id'] ?? 0) === $userId && is_array($_SESSION['user'] ?? null)) {
            $_SESSION['user']['email_verified_at'] = date('Y-m-d H:i:s');
            $_SESSION['user']['email_verified'] = 1;
        }
    }

    /** شريط التنبيه اللي فوق اللوحة (فاضي لو مش محتاج) */
    public static function bannerHtml(?array $user): string
    {
        if (!self::isPending($user)) {
            return '';
        }
        $user = self::fresh($user) ?? $user;
        $email = htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8');
        $left = self::hoursLeft($user);
        $when = '';
        if (self::enforcementOn() && $left > 0) {
            // المدة بتتكتب بلغة الواجهة مباشرة (القاموس مش بيعرف يترجم «3 يوم» → «3 days»)
            $n = $left >= 24 ? (int) ceil($left / 24) : (int) $left;
            if ((function_exists('current_lang') ? current_lang() : 'ar') !== 'ar') {
                $unit = $left >= 24 ? ($n === 1 ? 'day' : 'days') : ($n === 1 ? 'hour' : 'hours');
                $when = ' You need to verify it within ' . $n . ' ' . $unit . ' to keep using the dashboard.';
            } else {
                $unit = $left >= 24 ? ($n === 2 ? 'يومين' : ($n >= 3 && $n <= 10 ? 'أيام' : 'يوم')) : ($n === 2 ? 'ساعتين' : ($n >= 3 && $n <= 10 ? 'ساعات' : 'ساعة'));
                $when = ' لازم تأكّده خلال ' . ($n === 2 ? '' : $n . ' ') . $unit . ' عشان تفضل تقدر تستخدم اللوحة.';
            }
        }
        $spam = (function_exists('current_lang') ? current_lang() : 'ar') !== 'ar'
            ? 'Not in your inbox? Check Spam and Promotions, and mark it “Not spam”.'
            : 'مش لاقيه؟ بُص في Spam وPromotions، ودوس «ليس رسالة غير مرغوب فيها».';
        return <<<HTML
<div class="ev-banner" id="evBanner" role="status" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:0 0 16px;padding:12px 16px;border-radius:12px;background:rgba(239,176,94,.14);border:1px solid rgba(239,176,94,.45);font-size:14px;line-height:1.7">
  <span style="font-size:18px" aria-hidden="true">✉️</span>
  <span style="flex:1;min-width:220px">بعتنالك رابط تأكيد على <b dir="ltr">{$email}</b>.{$when}<br><small style="opacity:.8">{$spam}</small></span>
  <button type="button" id="evResend" style="padding:7px 16px;border-radius:20px;border:0;background:#EFB05E;color:#14100a;font-weight:600;cursor:pointer;font-family:inherit">ابعت الرابط تاني</button>
</div>
<script>
(function(){var b=document.getElementById('evResend');if(!b)return;b.addEventListener('click',function(){b.disabled=true;var t=b.textContent;b.textContent='بيتبعت...';
fetch('/api/auth/resend-verification',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:'{}'})
.then(function(r){return r.json().catch(function(){return {};});}).then(function(d){d=d||{};b.textContent=d.success?(d.message||'تم الإرسال ✓'):(d.error||'حصلت مشكلة، جرّب تاني');setTimeout(function(){b.textContent=t;b.disabled=false;},d.success?30000:4000);})
.catch(function(){b.textContent='حصلت مشكلة، جرّب تاني';b.disabled=false;});});})();
</script>
HTML;
    }

    /**
     * الإيميل بلغة العميل وبنفس شكل إيميلات المنصة اللي بتوصل الـ Inbox.
     * كان عربي بس، من غير هوية، وفيه رابط طويل مكتوب كنص - Gmail كان بيحطه Spam
     * أو Promotions، والعميل الأجنبي مش فاهمه.
     */
    private const TEXT = [
        'ar' => ['subject' => 'أكّد بريدك على Tourfecto', 'hi' => 'أهلًا %s 👋', 'body' => 'شكرًا إنك سجّلت في Tourfecto. دوس على الزرار عشان تأكد إن البريد ده بتاعك - كده هتوصلك التنبيهات والفواتير، وتقدر تسترجع حسابك لو نسيت كلمة السر.', 'btn' => 'تأكيد البريد الإلكتروني', 'ttl' => 'الرابط صالح 24 ساعة. لو مش انت اللي سجّلت، تجاهل الإيميل ده.', 'alt' => 'الزرار مش شغّال؟ افتح الرابط ده', 'fallback' => 'عميلنا العزيز'],
        'en' => ['subject' => 'Confirm your email for Tourfecto', 'hi' => 'Hi %s 👋', 'body' => 'Thanks for signing up to Tourfecto. Press the button to confirm this is your email - so you receive alerts and invoices, and can recover your account if you forget your password.', 'btn' => 'Confirm my email', 'ttl' => 'This link is valid for 24 hours. If you did not sign up, you can ignore this email.', 'alt' => 'Button not working? Open this link', 'fallback' => 'there'],
        'fr' => ['subject' => 'Confirmez votre e-mail pour Tourfecto', 'hi' => 'Bonjour %s 👋', 'body' => 'Merci de vous être inscrit sur Tourfecto. Cliquez sur le bouton pour confirmer cette adresse : vous recevrez vos alertes et factures, et pourrez récupérer votre compte si vous oubliez votre mot de passe.', 'btn' => 'Confirmer mon e-mail', 'ttl' => 'Ce lien est valable 24 heures. Si vous ne vous êtes pas inscrit, ignorez cet e-mail.', 'alt' => 'Le bouton ne marche pas ? Ouvrez ce lien', 'fallback' => ''],
        'de' => ['subject' => 'Bestätigen Sie Ihre E-Mail für Tourfecto', 'hi' => 'Hallo %s 👋', 'body' => 'Danke für Ihre Anmeldung bei Tourfecto. Klicken Sie auf den Button, um diese Adresse zu bestätigen – so erhalten Sie Hinweise und Rechnungen und können Ihr Konto wiederherstellen, falls Sie Ihr Passwort vergessen.', 'btn' => 'E-Mail bestätigen', 'ttl' => 'Der Link ist 24 Stunden gültig. Wenn Sie sich nicht angemeldet haben, ignorieren Sie diese E-Mail.', 'alt' => 'Button funktioniert nicht? Diesen Link öffnen', 'fallback' => ''],
    ];

    private static function lang(string $userLang): string
    {
        $l = strtolower(substr($userLang, 0, 2));
        if (!isset(self::TEXT[$l]) && function_exists('current_lang')) {
            $l = (string) current_lang();
        }
        return isset(self::TEXT[$l]) ? $l : 'en';
    }

    private static function emailHtml(string $name, string $url, string $lang = 'ar'): string
    {
        $T = self::TEXT[$lang] ?? self::TEXT['en'];
        $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $dir = $lang === 'ar' ? 'rtl' : 'ltr';
        $base = rtrim(defined('APP_URL') ? APP_URL : 'https://tourfecto.pro', '/');
        $hi = trim(sprintf($T['hi'], $name !== '' ? $name : $T['fallback']));
        $hi = preg_replace('/\s+👋$/u', ' 👋', preg_replace('/\s{2,}/u', ' ', $hi));
        $safeUrl = $e($url);
        return '<div dir="' . $dir . '" style="font-family:Arial,sans-serif;line-height:1.7;max-width:560px;margin:0 auto;background:#0F1A2C;padding:32px;border-radius:14px;color:#F2F4F8;">'
            . '<div style="text-align:center;margin-bottom:20px;"><a href="' . $e($base) . '" style="text-decoration:none;font-size:18px;font-weight:700;color:#EFB05E;">Tourfecto</a></div>'
            . '<h2 style="margin:0 0 8px;color:#EFB05E;text-align:center;">' . $e($hi) . '</h2>'
            . '<p style="color:#B9C3D4;font-size:14px;text-align:center;">' . $e($T['body']) . '</p>'
            . '<div style="text-align:center;margin:26px 0;"><a href="' . $safeUrl . '" style="display:inline-block;background:#EFB05E;color:#1A1200;text-decoration:none;font-weight:700;font-size:14px;padding:12px 28px;border-radius:10px;">' . $e($T['btn']) . '</a></div>'
            . '<p style="color:#8C97A8;font-size:12px;text-align:center;">' . $e($T['ttl']) . '</p>'
            . '<p style="color:#8C97A8;font-size:12px;text-align:center;"><a href="' . $safeUrl . '" style="color:#8C97A8;">' . $e($T['alt']) . '</a></p>'
            . '<p style="color:#6B7686;font-size:12px;text-align:center;margin-top:24px;">Tourfecto</p>'
            . '</div>';
    }
}
