<?php

/**
 * Tourfecto - Auth Controller
 * تسجيل الدخول / التسجيل / تسجيل الخروج / استعادة كلمة المرور
 * @version 1.0.5
 *
 * تصحيح نهائي (2026-07-12) — تم التحقق من بنية جدول users الحقيقية مباشرة
 * من phpMyAdmin. الأعمدة الصحيحة: password_hash, country_code, status
 * (enum: active/suspended/cancelled/pending...), email_verified_at,
 * first_name, last_name. عمود role هو enum('super_admin','admin','manager','agent')
 * فقط — لا توجد قيمة 'user' في enum الأدوار.
 *
 * تعديلان مهمان في register():
 * 1) role: كان 'admin' — ثغرة أمنية خطيرة (أي مستخدم يسجل نفسه بيصير أدمن
 *    كامل الصلاحيات). تم تغييره لـ 'manager' (أعلى قيمة متاحة في enum لا
 *    تمنح صلاحيات إدارة كل المستخدمين/الاشتراكات في النظام).
 * 2) status: كان 'pending' بدون أي آلية تفعيل فعلية (تأكيد البريد معطّل
 *    501)، فكان أي حساب جديد يفضل عالق لا يقدر يدخل أبدًا. تم تغييره لـ
 *    'active' ليقدر المستخدم يدخل فورًا بعد التسجيل.
 */

class AuthController extends Controller
{
    /**
     * ===================== Two-Factor Authentication (TOTP) =====================
     * تطبيق RFC 6238 (TOTP) يتم تفويضه إلى app/Services/TotpService.php
     * (نفس الخوارزمية اللي بتستخدمها Google Authenticator / Authy:
     * HMAC-SHA1، نافذة 30 ثانية، 6 أرقام) - من غير أي مكتبة خارجية،
     * ومختبَر ضد قيم RFC 6238 الرسمية في tests/Unit/TotpServiceTest.php.
     *
     * الميثودات الستاتيك هنا بتتفوض لـ TotpService بنفس التوقيعات
     * القديمة (generateTotpSecret / verifyTotpCode / generateRecoveryCodes)
     * عشان أي كود قديم بيناديها (UserController، صفحات الـ2FA) يفضل
     * شغال من غير أي تعديل.
     */

    /** توليد secret عشوائي جديد (160-bit، القياس الموصى به لـTOTP) */
    public static function generateTotpSecret(): string
    {
        if (class_exists('TotpService')) {
            return TotpService::generateSecret();
        }
        return self::fallbackBase32Encode(random_bytes(20));
    }

    /**
     * التحقق من كود TOTP بهامش ±1 خطوة زمنية (30 ثانية قبل/بعد) عشان نتحمل
     * فرق بسيط في ساعة جهاز المستخدم - ده معيار شائع ومقبول أمنيًا، مش
     * توسيع مبالغ فيه (لسه بيرفض أي كود قديم من أكتر من 30 ثانية).
     */
    public static function verifyTotpCode(string $base32Secret, string $code): bool
    {
        if (class_exists('TotpService')) {
            return TotpService::verify($base32Secret, $code, 1);
        }
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $now = time();
        foreach ([-30, 0, 30] as $offset) {
            if (hash_equals(self::fallbackTotpCode($base32Secret, $now + $offset), $code)) {
                return true;
            }
        }
        return false;
    }

    /** توليد 8 recovery codes (نص عادي مرة واحدة بس وقت العرض، بعدها مبيتخزنش إلا مُشفّر) */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        if (class_exists('TotpService')) {
            return TotpService::generateRecoveryCodes($count);
        }
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(5))); // 10 حروف/أرقام
        }
        return $codes;
    }

    /** ===== Fallback داخلي (لو TotpService مش محمّل لسبب ما) - نفس الخوارزمية ===== */

    private static function fallbackBase32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($binary, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $output .= $alphabet[bindec($chunk)];
        }
        return $output;
    }

    private static function fallbackTotpCode(string $base32Secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $counter = intdiv($timestamp, 30);
        $binCounter = pack('N*', 0) . pack('N*', $counter);
        $secretBinary = self::fallbackBase32Decode($base32Secret);
        $hash = hash_hmac('sha1', $binCounter, $secretBinary, true);
        $offset = ord($hash[19]) & 0x0F;
        $truncated = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);
        return str_pad((string) ($truncated % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function fallbackBase32Decode(string $b32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32));
        $binary = '';
        foreach (str_split($b32) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) {
                continue;
            }
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) === 8) {
                $output .= chr(bindec($byte));
            }
        }
        return $output;
    }

    /** ===================== نهاية Two-Factor Authentication Core ===================== */

    /**
     * عرض نموذج تسجيل الدخول
     * GET /login
     */
    /** مسار إعادة توجيه آمن من ?redirect= (نفس الموقع بس، مفيش // ولا بروتوكول) */
    private function safeRedirectParam(): ?string
    {
        $r = is_string($_GET['redirect'] ?? null) ? $_GET['redirect'] : '';
        if ($r === '' || strlen($r) > 500 || $r[0] !== '/' || str_starts_with($r, '//') || str_contains($r, '\\')
            || preg_match('/[\x00-\x1f]/', $r) || preg_match('#^/(login|register|logout)\b#', $r)) {
            return null;
        }
        return $r;
    }

    /** Feature selection is an allowlisted onboarding hint, never an external redirect. */
    private function serviceFeatureFromRequest(): string
    {
        $feature = is_string($_GET['feature'] ?? null) ? $_GET['feature'] : '';
        if ($feature === '') {
            $redirect = $this->safeRedirectParam();
            if ($redirect !== null && parse_url($redirect, PHP_URL_PATH) === '/onboarding') {
                parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
                $feature = is_string($query['feature'] ?? null) ? $query['feature'] : '';
            }
        }
        return class_exists('MarketingContent') && isset(MarketingContent::features()[$feature]) ? $feature : '';
    }

    public function showLoginForm(array $params = []): array
    {
        if ($this->hasValidSessionUser()) {
            header('Location: ' . ($this->safeRedirectParam() ?? '/dashboard'));
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAuthPage('login');
        exit;
    }

    /**
     * تحقق حقيقي (وليس مجرد فحص وجود المفتاح) من أن جلسة المستخدم صالحة
     * فعلاً: المستخدم موجود في قاعدة البيانات، حالته 'active'، وعنده
     * api_token متطابق مع كوكي auth_token المستخدم من AuthMiddleware.
     *
     * ملاحظة مهمة (سبب حلقة إعادة التوجيه اللا نهائية /login <-> /dashboard):
     * الكود القديم كان يعتمد على $_SESSION['user_id'] فقط لقرار تحويل
     * زائر /login لـ /dashboard، بينما AuthMiddleware اللي بيحمي /dashboard
     * بيعتمد فقط على كوكي/توكن auth_token (مش على السيشن إطلاقًا). فلو
     * السيشن فيها user_id لكن الكوكي غير موجودة أو التوكن مش متطابق مع
     * قاعدة البيانات (مثلاً كوكي انمسحت، أو توكن اتولّد وقت الدخول ومكانش
     * محفوظ فعليًا في العمود api_token)، كانت النتيجة: /login تحوّل لـ
     * /dashboard، و /dashboard يرفض الدخول ويحوّل لـ /login، وهكذا للأبد.
     * الدالة دي بتكسر الحلقة عند مصدرها: لو السيشن مش متطابقة فعليًا مع
     * قاعدة البيانات، بنمسحها ونعرض صفحة الدخول العادية بدل التحويل.
     */
    private function hasValidSessionUser(): bool
    {
        if (empty($_SESSION['user_id'])) {
            return false;
        }

        try {
            $userModel = new User();
            $user = $userModel->find((int) $_SESSION['user_id']);

            if (!$user || $user->getAttribute('status') !== 'active') {
                unset($_SESSION['user_id'], $_SESSION['user']);
                BrowserSessionService::cookie('',time()-3600);
                return false;
            }

            // تصحيح ذاتي: لو الكوكي مفقودة أو مش متطابقة مع توكن المستخدم
            // الفعلي في قاعدة البيانات، نعيد ضبطها الآن بدل ما نسيب
            // AuthMiddleware يرفض الطلب على /dashboard ويرجّع المستخدم
            // للوب. كده الجلسة والكوكي بيتزامنوا دايمًا مع بعض.
            if(empty($_SESSION['browser_session_hash']))BrowserSessionService::issue((int)$user->getAttribute('id'));
            elseif(!BrowserSessionService::validate((string)($_COOKIE['auth_token']??''))) {unset($_SESSION['user_id'],$_SESSION['user'],$_SESSION['browser_session_hash']);return false;}
            return true;
        } catch (Throwable $e) {
            // أي خطأ هنا (مثلاً قاعدة البيانات غير متاحة مؤقتًا) لازم يخلي
            // المستخدم يشوف فورم الدخول، مش يدخله في لوب تحويل.
            return false;
        }
    }

    /**
     * تسجيل الدخول
     * POST /login و POST /api/auth/login
     */
    public function login(array $params = []): array
    {
        if ($csrfError = $this->csrfGuard()) {
            return $csrfError;
        }

        // حارس معدل الطلبات: حد تسجيل دخول صارم لكل عنوان IP (مشترك بين
        // كل نقط نهايات المصادقة) - الحماية من محاولات الاختراق الموزّعة.
        if ($rateError = $this->rateLimitGuard('ip', 'auth_ip', 30, 60)) {
            return $rateError;
        }

        if (!$this->validate([
            'email' => 'required|email',
            'password' => 'required',
        ])) {
            return $this->error('بيانات الدخول غير صحيحة', 422, $this->getErrors());
        }

        $email = trim((string) $this->get('email'));
        $password = (string) $this->get('password');

        // حماية من محاولات الاختراق المتكررة (Brute Force): كانت بيانات
        // كل محاولة دخول بتتسجل فعليًا في login_history، لكن مفيش أي فحص
        // كان بيستخدم البيانات دي لمنع محاولات لا نهائية - يعني أي حد
        // يقدر يجرّب آلاف الباسوردات على نفس الإيميل من غير أي مانع.
        $lockout = $this->checkLoginRateLimit($email);
        if ($lockout !== null) {
            return $lockout;
        }

        try {
            $userModel = new User();
            $results = $userModel->where(['email' => $email], [], 1);

            if (empty($results)) {
                $this->recordLoginHistory(null, $email, 'failed');
                return $this->error('البريد الإلكتروني أو كلمة المرور غير صحيحة', 401);
            }

            // تصحيح: where() يُرجع بالفعل مصفوفة من كائنات User جاهزة
            // (انظر Model::where). عمل (array) على كائن به خصائص protected
            // ينتج مفاتيح مشوّهة (مثل "\0*\0attributes") وليس أعمدة الجدول
            // مباشرة، فكانت $user تُبنى ببيانات فارغة تمامًا، فتفشل مقارنة
            // كلمة المرور دائمًا مهما كانت صحيحة.
            $user = $results[0];

            if (!$user->verifyPassword($password)) {
                $this->recordLoginHistory((int) $user->getAttribute('id'), $email, 'failed');
                return $this->error('البريد الإلكتروني أو كلمة المرور غير صحيحة', 401);
            }

            // تم التصحيح: العمود الفعلي في الجدول هو "status" وليس "is_active".
            // القيمة المتوقعة عند الحساب المُفعّل هي 'active'.
            if ($user->getAttribute('status') !== 'active') {
                $this->recordLoginHistory((int) $user->getAttribute('id'), $email, 'failed');
                return $this->error('هذا الحساب غير مُفعّل أو موقوف، تواصل مع الدعم', 403);
            }

            // Two-Factor Authentication (Profile Center Phase 5): لو
            // مُفعّل، منكملش تسجيل الدخول هنا خالص - مفيش $_SESSION['user_id']،
            // مفيش كوكي، مفيش JWT. بنسجل بس "معلّق" ونطلب كود التحقق في
            // خطوة منفصلة. الجلسة الفعلية بتتفتح في verifyTwoFactor() بعد
            // نجاح الكود بس.
            if ((bool) $user->getAttribute('two_factor_enabled')) {
                $_SESSION['pending_2fa_user_id'] = (int) $user->getAttribute('id');
                $_SESSION['pending_2fa_expires'] = time() + 300; // 5 دقايق
                return $this->success(['two_factor_required' => true], 'مطلوب التحقق بخطوتين');
            }

            $this->recordLoginHistory((int) $user->getAttribute('id'), $email, 'success');
            return $this->completeLogin($user);

        } catch (Throwable $e) {
            // Throwable مش Exception عمدًا: أخطاء زي "Class not found" أو
            // TypeError هي Error مش Exception، ومكانتش بتتلقط هنا قبل كده -
            // كانت بتخرج كـ fatal error خام (مش JSON) فيكسر فرونت إند
            // الفورم ويظهر "تعذر الاتصال بالخادم" بدل رسالة خطأ واضحة.
            Logger::error('Login Error', ['message' => $e->getMessage()]);

            $debugMsg = (defined('APP_DEBUG') && APP_DEBUG)
                ? 'حدث خطأ أثناء تسجيل الدخول: ' . $e->getMessage()
                : 'حدث خطأ أثناء تسجيل الدخول';

            return $this->error($debugMsg, 500);
        }
    }

    /**
     * الجزء المشترك من تسجيل الدخول (Session + Cookie + JWT) بعد التأكد
     * الكامل من هوية المستخدم - سواء مباشرة (مفيش 2FA) أو بعد نجاح خطوة
     * التحقق بخطوتين. مُستخرجة من login() الأصلية بدون أي تغيير في
     * المنطق نفسه (شامل current_refresh_token_id لصفحة الأمان)، فقط
     * لتفادي تكرار نفس الكود في verifyTwoFactor().
     */
    /**
     * تسجيل وقت آخر دخول (users.last_login) - العمود موجود والملف الشخصي
     * وصفحة الأدمن بيعرضوه، بس ماكانش بيتحدّث خالص فكان دايمًا "لم يسجّل الدخول بعد".
     */
    private function markLoggedIn(int $userId): void
    {
        try {
            Database::getInstance()->query("UPDATE users SET last_login = NOW() WHERE id = ?", [$userId]);
        } catch (Throwable $e) {
            Logger::warning('markLoggedIn failed', ['user_id' => $userId, 'message' => $e->getMessage()]);
        }
    }

    private function completeLogin(User $user): array
    {
        $userData = $user->toArray();

        // جلسة الويب
        $_SESSION['user_id'] = $userData['id'];
        $this->markLoggedIn((int) $userData['id']);
        $_SESSION['user'] = $userData;

        // كوكي التوكن (يُستخدم لاحقًا مع AuthMiddleware في طلبات API)
        // تصحيح (2026-08-20): كان الكود بيقرا التوكن من $userData =
        // $user->toArray()، لكن api_token مدرج في $hidden بتاع موديل User،
        // فكان بيرجع null دايمًا → بيتولّد توكن جديد في كل تسجيل دخول →
        // أي جهاز جديد بيلغي توكن كل الأجهزة التانية (فريق الشركة
        // بيتطردوا من الجلسات). بنقرأه من الـ attribute الفعلي مباشرة.
        if(session_status()===PHP_SESSION_ACTIVE)session_regenerate_id(true);
        $token=BrowserSessionService::issue((int)$user->getAttribute('id'));

        $this->log('user_login', ['user_id' => $userData['id']]);

        // إصدار زوج JWT (access + refresh) - المرحلة 2 من خطة API Gateway.
        $jwtTokens = $this->issueJwtTokenPair((int) $userData['id']);

        // نتذكر أي صف Session/RefreshToken بالظبط بيمثّل الجلسة الحالية
        // اللي بيستخدمها المتصفح ده دلوقتي، عشان صفحة الأمان تقدر
        // تعلّم عليها "الجهاز الحالي" فعليًا بدل تخمين.
        $_SESSION['current_refresh_token_id'] = $jwtTokens['refresh_token_id'];
        BrowserSessionService::bindRefresh($token,(int)$jwtTokens['refresh_token_id']);

        return $this->success([
            'user' => $userData,
            'token' => $token,
            'access_token' => $jwtTokens['access_token'],
            'refresh_token' => $jwtTokens['refresh_token'],
            'token_type' => 'Bearer',
            'expires_in' => defined('JWT_ACCESS_TOKEN_TTL') ? JWT_ACCESS_TOKEN_TTL : 900,
        ], 'تم تسجيل الدخول بنجاح');
    }

    public function showTwoFactorChallenge(array $params = []): array
    {
        if (empty($_SESSION['pending_2fa_user_id']) || time() > ($_SESSION['pending_2fa_expires'] ?? 0)) {
            header('Location: /login');
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        $lang = htmlspecialchars(current_lang(), ENT_QUOTES, 'UTF-8');
        $dir = current_dir();
        $tTitle = $this->tr('auth.2fa.title');
        $tSubtitle = $this->tr('auth.2fa.subtitle');
        $tCodeLabel = $this->tr('auth.2fa.code_label');
        $tSubmit = $this->tr('auth.2fa.submit');
        $tRecoveryHint = $this->tr('auth.2fa.recovery_hint');
        $tProcessing = $this->trJs('auth.processing');

        echo <<<HTML
<!DOCTYPE html>
<html lang="{$lang}" dir="{$dir}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$tTitle}</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background:#f6f7fb; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; }
        .card { background:#fff; border-radius:12px; padding:32px; max-width:380px; width:90%; box-shadow:0 4px 24px rgba(0,0,0,.08); text-align:center; }
        h1 { font-size:20px; margin:0 0 8px; }
        p { color:#666; font-size:14px; margin:0 0 20px; }
        input { width:100%; padding:12px; font-size:20px; letter-spacing:4px; text-align:center; border:1px solid #ddd; border-radius:8px; box-sizing:border-box; margin-bottom:12px; }
        button { width:100%; padding:12px; background:#4f46e5; color:#fff; border:none; border-radius:8px; font-size:15px; cursor:pointer; }
        button:disabled { opacity:.6; }
        .alert { background:#fdecea; color:#c0392b; padding:10px; border-radius:8px; font-size:13px; margin-bottom:12px; display:none; }
        .hint { font-size:12px; color:#999; margin-top:16px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>{$tTitle}</h1>
        <p>{$tSubtitle}</p>
        <div id="alertBox" class="alert"></div>
        <form id="tfaForm">
            <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="000000" maxlength="10" autofocus>
            <button type="submit" id="submitBtn">{$tSubmit}</button>
        </form>
        <p class="hint">{$tRecoveryHint}</p>
    </div>
    <script>
        document.getElementById('tfaForm').addEventListener('submit', async function (e) {
            e.preventDefault();
            const alertBox = document.getElementById('alertBox');
            const submitBtn = document.getElementById('submitBtn');
            alertBox.style.display = 'none';
            submitBtn.disabled = true;
            const original = submitBtn.textContent;
            submitBtn.textContent = {$tProcessing};
            try {
                const res = await fetch('/login/2fa/verify', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ code: document.getElementById('code').value.trim() })
                });
                const result = await res.json();
                if (result.success) {
                    window.location.href = '/dashboard';
                    return;
                }
                alertBox.textContent = result.error || 'حدث خطأ';
                alertBox.style.display = 'block';
            } catch (err) {
                alertBox.textContent = 'تعذر الاتصال بالخادم';
                alertBox.style.display = 'block';
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = original;
            }
        });
    </script>
</body>
</html>
HTML;
        exit;
    }

    /**
     * POST /login/2fa/verify
     * الخطوة الثانية من تسجيل الدخول - كود TOTP أو Recovery Code. بيقرأ
     * فقط من $_SESSION['pending_2fa_user_id'] (مش من أي user_id جاي من
     * الطلب نفسه) عشان محدش يقدر يتحقق بدل مستخدم تاني.
     */
    public function verifyTwoFactor(array $params = []): array
    {
        $pendingUserId = $_SESSION['pending_2fa_user_id'] ?? null;
        $expires = $_SESSION['pending_2fa_expires'] ?? 0;

        if (!$pendingUserId || time() > $expires) {
            unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_expires']);
            return $this->error('انتهت صلاحية الجلسة، سجّل الدخول من جديد', 401);
        }

        if (!$this->validate(['code' => 'required'])) {
            return $this->error('أدخل كود التحقق', 422, $this->getErrors());
        }

        // حماية من محاولات تخمين كود 2FA المتكررة (Brute Force): كود
        // TOTP مكوّن من 6 أرقام، فبدون حد أقصى للمحاولات أي مهاجم يقدر
        // يجرّب آلاف الأكواد على نفس الجلسة المعلّقة. بنحسب محاولات
        // الفشل على نفس المستخدم (لو معروف) أو على الـ IP (لو مش معروف)
        // خلال آخر 15 دقيقة ونمنع الاستمرار بعد 5 محاولات فاشلة - نفس
        // نهج checkLoginRateLimit() الموجود أصلًا لتسجيل الدخول.
        $lockoutKey = $pendingUserId ? '2fa_user_' . (int) $pendingUserId : '2fa_ip_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if ($this->checkTwoFactorRateLimit($lockoutKey)) {
            return $this->error('محاولات فاشلة كتير للتحقق بخطوتين. استنى 15 دقيقة وسجّل الدخول من جديد.', 429);
        }

        $userModel = new User();
        $user = $userModel->find((int) $pendingUserId);
        if (!$user || !(bool) $user->getAttribute('two_factor_enabled')) {
            unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_expires']);
            return $this->error('حالة غير صالحة، سجّل الدخول من جديد', 401);
        }

        $code = trim((string) $this->get('code'));
        $secret = (string) $user->getAttribute('two_factor_secret');
        $valid = $secret !== '' && self::verifyTotpCode($secret, $code);

        if (!$valid) {
            // نجرّب Recovery Code لو الكود مش TOTP صحيح
            $recoveryCodesJson = $user->getAttribute('two_factor_recovery_codes');
            $recoveryCodes = $recoveryCodesJson ? (json_decode((string) $recoveryCodesJson, true) ?: []) : [];
            foreach ($recoveryCodes as $i => $hashedCode) {
                if (password_verify($code, (string) $hashedCode)) {
                    unset($recoveryCodes[$i]);
                    $user->setAttribute('two_factor_recovery_codes', json_encode(array_values($recoveryCodes)));
                    $user->save();
                    $valid = true;
                    break;
                }
            }
        }

        if (!$valid) {
            $this->recordLoginHistory((int) $user->getAttribute('id'), (string) $user->getAttribute('email'), 'failed');
            return $this->error('كود التحقق غير صحيح', 401);
        }

        unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_expires']);
        $this->resetTwoFactorRateLimit($lockoutKey);
        $this->recordLoginHistory((int) $user->getAttribute('id'), (string) $user->getAttribute('email'), 'success');
        return $this->completeLogin($user);
    }

    /**
     * عرض نموذج التسجيل
     * GET /register
     */
    public function showRegisterForm(array $params = []): array
    {
        if ($this->hasValidSessionUser()) {
            header('Location: ' . ($this->safeRedirectParam() ?? '/dashboard'));
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderAuthPage('register');
        exit;
    }

    /** حماية CSRF: يتأكد إن توكن الفورم مطابق لتوكن الجلسة قبل أي عملية تغيير بيانات */
    private function csrfGuard(): ?array
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        // نداءات /api/* أو المصحوبة بـ Authorization Bearer مش معتمدة على
        // كوكي الجلسة (عملاء خارجيين/تطبيق موبايل)، فمش عرضة لـ CSRF
        // بنفس طريقة فورم المتصفح - منستثنيها عشان معملش مشاكل لعملاء شرعيين.
        if (strpos($path, '/api/') === 0) {
            return null;
        }
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        if (!empty($headers['Authorization']) || !empty($headers['authorization'])) {
            return null;
        }

        $submitted = (string) $this->get('csrf_token');
        if (!class_exists('Csrf') || !Csrf::verify($submitted)) {
            return $this->error('انتهت صلاحية الجلسة، حدّث الصفحة وحاول تاني', 419);
        }
        return null;
    }

    /**
     * تسجيل مستخدم جديد
     * POST /register و POST /api/auth/register
     */
    public function register(array $params = []): array
    {
        if ($csrfError = $this->csrfGuard()) {
            return $csrfError;
        }

        if ($rateError = $this->rateLimitGuard('ip', 'auth_ip', 30, 60)) {
            return $rateError;
        }

        if (!$this->validate([
            'company_name' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8',
        ])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        try { $locale=InternationalSettingsService::accountValues(['country_code'=>(string)$this->get('country_code',''),'language'=>(string)$this->get('language','en'),'timezone'=>(string)$this->get('timezone','UTC'),'currency'=>(string)$this->get('currency','USD')]); } catch(InvalidArgumentException $e) { return $this->error($e->getMessage(),422); }
        $email = trim((string) $this->get('email'));
        $companyName = trim((string) $this->get('company_name'));

        try {
            $userModel = new User();
            $existing = $userModel->where(['email' => $email], [], 1);

            if (!empty($existing)) {
                return $this->error('هذا البريد الإلكتروني مستخدم بالفعل', 409);
            }

            // 🐛 باگ حقيقي اتلقى أثناء اختبار تسجيل حساب فعلي: الكود القديم هنا
            // كان بيقسّم *اسم الشركة نفسه* على أساس إنه first_name/last_name -
            // مثلاً شركة اسمها "شركة تجربة السياحة" كانت بتتسجل بـ
            // first_name="شركة" و last_name="تجربة السياحة"! ده بيظهر غلط في
            // أي رسالة ترحيب أو إيميل بيستخدم اسم المستخدم الشخصي.
            // الفورم الحالي فعلاً مش بيجمع first_name/last_name منفصلين (زي
            // ما التعليق الأصلي بيقول)، فبدل ما نولّد بيانات وهمية، سيبناهم
            // فاضيين - الكود عنده بالفعل fallback سليم بيرجع لـcompany_name
            // لو first_name فاضي (Controllers/AuthController.php:~1109،
            // displayName = first_name ?: company_name).
            $firstName = null;
            $lastName = null;

            $newUser = User::create([
                'company_name' => $companyName,
                'email' => $email,
                'password' => $this->get('password'), // User::create يعمل hash داخليًا إلى password_hash
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $this->get('phone'),
                'country_code' => $locale['country_code'],
                'language'=>$locale['language'], 'timezone'=>$locale['timezone'], 'currency'=>$locale['currency'],
                'role' => 'manager',   // كان 'admin' خطأً — ثغرة صلاحيات، كل مستخدم يسجل نفسه كان يصير أدمن كامل
                'status' => 'active',  // كان 'pending' بدون أي آلية تفعيل فعلية، فكان الحساب يفضل عالق للأبد
            ]);

            if (!$newUser) {
                return $this->error('تعذر إنشاء الحساب، حاول مرة أخرى', 500);
            }

            $userData = $newUser->toArray();
            $_SESSION['user_id'] = $userData['id'];
            $this->markLoggedIn((int) $userData['id']);
            $_SESSION['user'] = $userData;

            // api_token مخفي في toArray() - كان بيتولّد توكن جديد مش متسجّل فالكوكي تبقى بلا قيمة
            if(session_status()===PHP_SESSION_ACTIVE)session_regenerate_id(true);
            $token=BrowserSessionService::issue((int)$newUser->getAttribute('id'));

            $this->log('user_register', ['user_id' => $userData['id']]);

            // إيميل تأكيد البريد (لو فشل الإرسال التسجيل بيكمل عادي - العميل يقدر يعيد الإرسال من الشريط)
            $verification = ['success' => false];
            try {
                if (class_exists('EmailVerification')) {
                    $verification = EmailVerification::send((int) $userData['id']);
                }
            } catch (Throwable $e) {
                Logger::error('Register verification mail error', ['message' => $e->getMessage()]);
            }
            $userData['email_verification_sent'] = !empty($verification['success']);

            return $this->success(['user' => $userData, 'token' => $token], 'تم إنشاء الحساب بنجاح', 201);

        } catch (Throwable $e) {
            Logger::error('Register Error', ['message' => $e->getMessage()]);

            // مؤقت للتشخيص: يعرض رسالة الخطأ الحقيقية عند تفعيل APP_DEBUG بدل
            // الرسالة العامة الثابتة، لمعرفة السبب الفعلي وراء أي فشل مستقبلي.
            $debugMsg = (defined('APP_DEBUG') && APP_DEBUG)
                ? 'حدث خطأ أثناء إنشاء الحساب: ' . $e->getMessage()
                : 'حدث خطأ أثناء إنشاء الحساب';

            return $this->error($debugMsg, 500);
        }
    }

    /**
     * تسجيل الخروج
     * GET /logout و POST /api/auth/logout
     */
    public function logout(array $params = []): array
    {
        BrowserSessionService::revoke($_COOKIE['auth_token']??null);
        $currentRefreshId = $_SESSION['current_refresh_token_id'] ?? null;
        if ($currentRefreshId) {
            $token = (new RefreshToken())->find((int) $currentRefreshId);
            if ($token && !$token->getAttribute('revoked_at')) {
                $token->revoke();
            }
        }

        unset($_SESSION['user_id'], $_SESSION['user'], $_SESSION['current_refresh_token_id']);
        BrowserSessionService::cookie('',time()-3600);
        session_regenerate_id(true);

        // تصحيح: كل روابط "تسجيل الخروج" في الموقع كله (القائمة الجانبية
        // العادية، لوحة الأدمن، الداشبورد) هي روابط <a href="/logout">
        // عادية (GET) مش استدعاء JS - يعني المتصفح كان بيعرض نص JSON خام
        // على الشاشة بدل ما يوجّه المستخدم لأي مكان. كل مستخدم في الموقع
        // كان بيشوف الباغ ده في كل مرة يسجّل خروج.
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $isApiRequest = strpos($path, '/api/') === 0;

        if (!$isApiRequest) {
            header('Location: /login');
            exit;
        }

        return $this->success([], 'تم تسجيل الخروج بنجاح');
    }

    /** المنصات المدعومة لتسجيل الدخول الاجتماعي */
    private const SOCIAL_PROVIDERS = ['google', 'facebook'];

    /**
     * بداية تسجيل الدخول بمنصة اجتماعية - بيولّد state عشوائي (حماية CSRF)
     * ويحوّل المستخدم لصفحة موافقة المنصة.
     * GET /auth/{provider}
     */
    public function socialRedirect(array $params = []): array
    {
        if ($rateError = $this->rateLimitGuard('ip', 'auth_ip', 30, 60)) {
            return $rateError;
        }

        $provider = (string) ($params['provider'] ?? '');
        if (!in_array($provider, self::SOCIAL_PROVIDERS, true)) {
            header('Location: /login?error=oauth_error_1');
            exit;
        }

        try {
            $client = new SocialLoginClient($provider);
            if (!$client->isConfigured()) {
                header('Location: /login?error=oauth_error_2');
                exit;
            }

            $state = bin2hex(random_bytes(24));
            $_SESSION['oauth_state'] = $state;
            $_SESSION['oauth_provider'] = $provider;
            $_SESSION['oauth_service_feature'] = $this->serviceFeatureFromRequest();

            // Connected Accounts (Profile Center Phase 2): ربط حساب OAuth
            // بمستخدم مسجّل دخوله بالفعل، بدل إعادة تسجيل الدخول/إنشاء
            // حساب جديد حسب إيميل الـOAuth (اللي ممكن يبقى مختلف عن حساب
            // Tourfecto الحالي ويسبب دخول/ربط بحساب غلط - ثغرة أمنية
            // حقيقية لو زرار "ربط حساب" كان بيوجّه لمسار تسجيل الدخول
            // العادي مباشرة).
            if (($_GET['link'] ?? '') === '1' && !empty($_SESSION['user_id'])) {
                $_SESSION['oauth_link_user_id'] = (int) $_SESSION['user_id'];
            } else {
                unset($_SESSION['oauth_link_user_id']);
            }

            header('Location: ' . $client->buildAuthUrl($state));
            exit;
        } catch (Throwable $e) {
            Logger::error('socialRedirect Error', ['provider' => $provider, 'message' => $e->getMessage()]);
            header('Location: /login?error=oauth_error_3');
            exit;
        }
    }

    /**
     * استقبال رجوع Google/Facebook بعد الموافقة (GET، فيه code و state).
     * GET /auth/{provider}/callback
     */
    public function socialCallback(array $params = []): array
    {
        if ($rateError = $this->rateLimitGuard('ip', 'auth_ip', 30, 60)) {
            return $rateError;
        }

        $provider = (string) ($params['provider'] ?? '');
        if (!in_array($provider, self::SOCIAL_PROVIDERS, true)) {
            header('Location: /login?error=oauth_error_1');
            exit;
        }

        $code = (string) ($_GET['code'] ?? '');
        $state = (string) ($_GET['state'] ?? '');

        if (!empty($_GET['error'])) {
            header('Location: /login?error=oauth_error_4');
            exit;
        }

        if (!$this->verifyOAuthState($provider, $state) || $code === '') {
            header('Location: /login?error=oauth_error_5');
            exit;
        }

        try {
            $client = new SocialLoginClient($provider);
            $tokenResult = $client->exchangeCodeForToken($code);
            if (!$tokenResult['success']) {
                Logger::error('socialCallback token exchange failed', ['provider' => $provider, 'error' => $tokenResult['error'] ?? '']);
                header('Location: /login?error=oauth_error_6');
                exit;
            }

            $profile = $client->fetchProfile($tokenResult['access_token']);
            if (!$profile) {
                header('Location: /login?error=oauth_error_7');
                exit;
            }

            $this->completeSocialLogin($provider, $profile['id'], $profile['email'], $profile['name']);
            return [];
        } catch (Throwable $e) {
            Logger::error('socialCallback Error', ['provider' => $provider, 'message' => $e->getMessage()]);
            header('Location: /login?error=oauth_error_8');
            exit;
        }
    }

    private function verifyOAuthState(string $provider, string $state): bool
    {
        $valid = !empty($_SESSION['oauth_state'])
            && ($_SESSION['oauth_provider'] ?? '') === $provider
            && hash_equals($_SESSION['oauth_state'], $state);
        unset($_SESSION['oauth_state'], $_SESSION['oauth_provider']);
        return $valid;
    }

    /**
     * يلاقي/ينشئ المستخدم المرتبط بحساب اجتماعي معيّن، يفتحله جلسة دخول
     * (زي login() بالظبط)، ثم يحوّله لـ /dashboard.
     */
    private function completeSocialLogin(string $provider, string $providerUserId, ?string $email, ?string $name): void
    {
        $db = Database::getInstance();
        $userModel = new User();

        $linked = $db->query(
            "SELECT user_id FROM oauth_accounts WHERE provider = ? AND provider_user_id = ? LIMIT 1",
            [$provider, $providerUserId]
        );

        // Connected Accounts (Profile Center Phase 2): وضع "ربط حساب" من
        // Profile > Connected Accounts (مستخدم داخل بالفعل) - لا نعمل
        // تسجيل دخول/إنشاء حساب جديد هنا، فقط نربط مزوّد الـOAuth بنفس
        // المستخدم الحالي. لازم نتحقق الأول ده مش مربوط أصلًا بمستخدم
        // تاني، وإلا هنسمح بربط نفس حساب Google بأكتر من حساب Tourfecto.
        if (!empty($_SESSION['oauth_link_user_id'])) {
            $linkUserId = (int) $_SESSION['oauth_link_user_id'];
            unset($_SESSION['oauth_link_user_id']);

            if (!empty($linked) && (int) $linked[0]['user_id'] !== $linkUserId) {
                header('Location: /profile/settings?oauth_error=' . rawurlencode('حساب ' . ucfirst($provider) . ' ده مربوط بالفعل بحساب Tourfecto تاني'));
                exit;
            }

            $db->exec(
                "INSERT INTO oauth_accounts (user_id, provider, provider_user_id, email, created_at) VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE email = VALUES(email)",
                [$linkUserId, $provider, $providerUserId, $email]
            );

            header('Location: /profile/settings?oauth_connected=' . rawurlencode($provider));
            exit;
        }

        $user = null;
        if (!empty($linked)) {
            $user = $userModel->find((int) $linked[0]['user_id']);
        }
        // A stale OAuth link must not skip the existing-email lookup.
        if (!$user && $email) {
            $email = trim($email);
            $user = User::findByEmail($email);
        }

        if (!$user) {
            if (!$email) {
                header('Location: /login?error=oauth_error_13');
                exit;
            }

            $nameParts = $name ? preg_split('/\s+/', trim($name), 2) : [];
            $newUser = User::create([
                'company_name' => $name ?: $email,
                'email' => $email,
                'password' => bin2hex(random_bytes(16)), // كلمة مرور عشوائية - المستخدم دخل بالـ OAuth، ممكن يحدّدها لاحقًا من "نسيت كلمة المرور"
                'first_name' => $nameParts[0] ?? ($name ?: $email),
                'last_name' => $nameParts[1] ?? '-',
                'role' => 'manager',
                'status' => 'active',
                'email_verified_at' => date('Y-m-d H:i:s'),
            ]);

            // Another request may have created this email after our lookup.
            if (!$newUser) {
                $newUser = User::findByEmail($email);
            }
            if (!$newUser) {
                header('Location: /login?error=oauth_error_14');
                exit;
            }
            $user = $newUser;
        }

        if ($user->getAttribute('status') !== 'active') {
            header('Location: /login?error=oauth_error_15');
            exit;
        }

        $db->exec(
            "INSERT INTO oauth_accounts (user_id, provider, provider_user_id, email, created_at) VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE email = VALUES(email)",
            [(int) $user->getAttribute('id'), $provider, $providerUserId, $email]
        );

        $userData = $user->toArray();
        $_SESSION['user_id'] = $userData['id'];
        $this->markLoggedIn((int) $userData['id']);
        $_SESSION['user'] = $userData;

        if(session_status()===PHP_SESSION_ACTIVE)session_regenerate_id(true);
        $token=BrowserSessionService::issue((int)$user->getAttribute('id'));

        $this->log('user_login', ['user_id' => $userData['id'], 'provider' => $provider]);
        $this->recordLoginHistory((int) $userData['id'], $userData['email'] ?? '', 'success');

        $feature = $_SESSION['oauth_service_feature'] ?? '';
        unset($_SESSION['oauth_service_feature']);
        $destination = is_string($feature) && class_exists('MarketingContent') && isset(MarketingContent::features()[$feature])
            ? '/onboarding?feature=' . $feature : '/dashboard';
        header('Location: ' . $destination);
        exit;
    }

    /**
     * تحديث جلسة/توكن قصير الأجل
     * POST /api/auth/refresh
     */
    public function refresh(array $params = []): array
    {
        if (empty($_SESSION['user_id']) || !BrowserSessionService::validate((string)($_COOKIE['auth_token']??''))) {
            return $this->error('غير مسجل دخول', 401);
        }
        return $this->success(['user' => $_SESSION['user'] ?? []], 'الجلسة سارية');
    }

    /**
     * إصدار زوج JWT (access + refresh) لمستخدم - يُستخدم من login() وأي
     * مكان تاني محتاج يولّد توكنات جديدة لنفس المستخدم.
     */
    private function issueJwtTokenPair(int $userId): array
    {
        $accessTtl = defined('JWT_ACCESS_TOKEN_TTL') ? JWT_ACCESS_TOKEN_TTL : 900;
        $accessToken = JwtService::issue(['sub' => $userId, 'type' => 'access'], $accessTtl);

        $deviceName = $this->get('device_name');
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $refresh = RefreshToken::issueFor($userId, $deviceName, $ip, $userAgent);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refresh['raw_token'],
            'refresh_token_id' => (int) $refresh['model']->getAttribute('id'),
        ];
    }

    /**
     * استبدال refresh token بزوج جديد (access + refresh) من غير ما
     * المستخدم يدخل الباسورد تاني. بيعمل "rotation": التوكن القديم
     * بيتلغي فورًا وبيتصدر واحد جديد بدله - لو حد سرق refresh token
     * قديم وحاول يستخدمه بعد ما صاحبه استخدمه، هيترفض لإنه بقى ملغي.
     * POST /api/auth/token/refresh
     * body: { refresh_token }
     */
    public function refreshJwtToken(array $params = []): array
    {
        $rawToken = (string) $this->get('refresh_token', '');
        if ($rawToken === '') {
            return $this->error('refresh_token is required', 400);
        }

        $tokenRecord = RefreshToken::verify($rawToken);
        if (!$tokenRecord) {
            return $this->error('Invalid, expired, or revoked refresh token', 401);
        }

        $userId = (int) $tokenRecord->getAttribute('user_id');

        try {
            $userModel = new User();
            $user = $userModel->find($userId);
            if (!$user || $user->getAttribute('status') !== 'active') {
                return $this->error('Account is inactive or no longer exists', 403);
            }
        } catch (Throwable $e) {
            return $this->error('Failed to verify account status', 500);
        }

        $browserToken = (string) ($_COOKIE['auth_token'] ?? '');
        $browserUser = BrowserSessionService::validate($browserToken);
        // Rotation: نلغي التوكن القديم فورًا ونصدر زوج جديد بدله
        $tokenRecord->touchUsage();
        $tokenRecord->revoke();
        $newTokens = $this->issueJwtTokenPair($userId);
        if ($browserUser && (int) $browserUser['id'] === $userId) {
            $stmt = $this->db->getConnection()->prepare('UPDATE browser_sessions SET refresh_token_id=? WHERE token_hash=? AND refresh_token_id=? AND revoked_at IS NULL');
            $stmt->execute([(int) $newTokens['refresh_token_id'], hash('sha256', $browserToken), (int) $tokenRecord->getAttribute('id')]);
        }

        return $this->success([
            'access_token' => $newTokens['access_token'],
            'refresh_token' => $newTokens['refresh_token'],
            'token_type' => 'Bearer',
            'expires_in' => defined('JWT_ACCESS_TOKEN_TTL') ? JWT_ACCESS_TOKEN_TTL : 900,
        ], 'تم تحديث التوكن بنجاح');
    }

    /**
     * إلغاء refresh token واحد بس (تسجيل خروج من جهاز واحد - مفيد
     * لتطبيق الموبايل عند تسجيل الخروج، بدون ما يأثر على أي جهاز تاني
     * لنفس المستخدم مسجّل دخول عليه).
     * POST /api/auth/token/revoke
     * body: { refresh_token }
     */
    public function revokeJwtToken(array $params = []): array
    {
        $rawToken = (string) $this->get('refresh_token', '');
        if ($rawToken === '') {
            return $this->error('refresh_token is required', 400);
        }

        $tokenRecord = RefreshToken::verify($rawToken);
        if ($tokenRecord) {
            $tokenRecord->revoke();
        }

        // نرجّع نجاح حتى لو التوكن مش لاقيه أصلاً - عشان مانديش معلومة
        // لأي حد بيحاول يخمّن توكنات صالحة (نفس مبدأ "لا تكشف السبب"
        // المتبع في نقاط تسجيل الدخول)
        return $this->success([], 'تم تسجيل الخروج من هذا الجهاز');
    }

    /**
     * عرض نموذج نسيت كلمة المرور
     * GET /forgot-password
     */
    public function showForgotForm(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderForgotPasswordPage();
        exit;
    }

    /**
     * إرسال رابط استعادة كلمة المرور
     * POST /forgot-password و POST /api/auth/forgot-password
     */
    public function forgotPassword(array $params = []): array
    {
        if ($rateError = $this->rateLimitGuard('ip', 'auth_ip', 30, 60)) {
            return $rateError;
        }

        if (!$this->validate(['email' => 'required|email'])) {
            return $this->error('بريد إلكتروني غير صحيح', 422, $this->getErrors());
        }

        $genericMessage = 'إذا كان البريد مسجلاً لدينا، ستصلك رسالة استعادة كلمة المرور خلال دقائق';

        try {
            $user = User::findByEmail((string) $this->get('email'));
            if (!$user) {
                // نرجّع نفس الرسالة العامة سواء الإيميل موجود أو لأ، عشان محدش
                // يقدر يكتشف إيميلات مسجلة عندنا من عدمها (user enumeration).
                return $this->success([], $genericMessage);
            }

            // حماية من إساءة استخدام الميزة دي لإغراق إيميل حد تاني برسائل
            // استعادة كتير (مزعج ومحتمل يُستخدم للتحرش). لو 3 طلبات خلال
            // آخر 15 دقيقة لنفس الحساب، منوقفش تظاهريًا (لسه بنرجع نفس
            // الرسالة العامة) لكن مبنبعتش إيميل جديد فعليًا.
            $recentRequests = (new PasswordResetToken())->where(
                ['user_id' => $user->getAttribute('id')],
                ['created_at' => 'DESC'],
                10
            );
            $recentCount = 0;
            foreach ($recentRequests as $rt) {
                if (strtotime((string) $rt->getAttribute('created_at')) > time() - 900) {
                    $recentCount++;
                }
            }
            if ($recentCount >= 3) {
                return $this->success([], $genericMessage);
            }

            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);

            $resetToken = new PasswordResetToken([
                'user_id' => $user->getAttribute('id'),
                'token_hash' => $tokenHash,
                'expires_at' => date('Y-m-d H:i:s', time() + 3600), // ساعة واحدة
            ]);
            $resetToken->save();

            $resetUrl = rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/reset-password/' . $rawToken;
            $displayName = trim((string) ($user->getAttribute('first_name') ?: $user->getAttribute('company_name') ?: ''));

            $mailer = new Mailer();
            if ($mailer->isConfigured()) {
                $html = $this->renderResetEmailHtml($displayName, $resetUrl);
                $result = $mailer->send((string) $user->getAttribute('email'), $displayName, 'إعادة تعيين كلمة المرور - Tourfecto', $html);
                if (!$result['success']) {
                    Logger::error('Forgot Password Mail Error', ['message' => $result['error'] ?? '']);
                }
            } else {
                // مفيش إعدادات بريد شغّالة - نسجّل رابط الاستعادة في اللوج
                // كحل مؤقت عشان الأدمن يقدر يبعته يدوي للعميل لحد ما يظبط SMTP.
                Logger::warning('Password Reset (email not configured - manual link needed)', [
                    'email' => $user->getAttribute('email'),
                    'reset_url' => $resetUrl,
                ]);
            }

            $this->log('Password Reset Requested', ['user_id' => $user->getAttribute('id')]);

            return $this->success([], $genericMessage);
        } catch (Exception $e) {
            Logger::error('Forgot Password Error', ['message' => $e->getMessage()]);
            // برضو رسالة عامة - مفيش داعي نكشف وجود مشكلة تقنية للمهاجم المحتمل
            return $this->success([], $genericMessage);
        }
    }

    /**
     * عرض نموذج إعادة تعيين كلمة المرور
     * GET /reset-password/{token}
     */
    public function showResetForm(array $params): array
    {
        $token = (string) ($params['token'] ?? '');
        $valid = $this->findValidResetToken($token) !== null;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderResetPasswordPage($token, $valid);
        exit;
    }

    /**
     * إعادة تعيين كلمة المرور
     * POST /reset-password و POST /api/auth/reset-password
     */
    public function resetPassword(array $params = []): array
    {
        if ($rateError = $this->rateLimitGuard('ip', 'auth_ip', 30, 60)) {
            return $rateError;
        }

        if (!$this->validate(['token' => 'required', 'password' => 'required|min:8'])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        $token = (string) $this->get('token');
        $resetToken = $this->findValidResetToken($token);

        if (!$resetToken) {
            return $this->error('رابط إعادة التعيين غير صالح أو منتهي الصلاحية، اطلب رابط جديد', 422);
        }

        try {
            $user = (new User())->find((int) $resetToken->getAttribute('user_id'));
            if (!$user) {
                return $this->error('الحساب غير موجود', 404);
            }

            $user->updatePassword((string) $this->get('password'));

            // أمان: إعادة تعيين كلمة المرور تخلي أي جلسة قديمة بكلمة
            // المرور القديمة باطلة فورًا (كل الأجهزة) - نفس مبدأ
            // updatePassword في UserController. حتى لو كان فيه جهاز مسجل
            // دخول بجلسة مسروقة، بعد إعادة التعيين لازم يعيد تسجيل الدخول
            // بكلمة المرور الجديدة.
            RefreshToken::revokeAllForUser((int) $user->getAttribute('id'));

            $resetToken->setAttribute('used_at', date('Y-m-d H:i:s'));
            $resetToken->save();

            $this->log('Password Reset Completed', ['user_id' => $user->getAttribute('id')]);

            return $this->success([], 'تم تغيير كلمة المرور بنجاح، تقدر تسجّل دخول دلوقتي');
        } catch (Exception $e) {
            Logger::error('Reset Password Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إعادة تعيين كلمة المرور', 500);
        }
    }

    /** يدوّر على توكن صالح (مش مستخدم ومش منتهي) عن طريق hash التوكن الخام المُستلم */
    private function findValidResetToken(string $rawToken): ?PasswordResetToken
    {
        if ($rawToken === '') {
            return null;
        }

        $tokenHash = hash('sha256', $rawToken);
        $matches = (new PasswordResetToken())->where(['token_hash' => $tokenHash], [], 1);

        if (empty($matches)) {
            return null;
        }

        $resetToken = $matches[0];
        if ($resetToken->getAttribute('used_at')) {
            return null;
        }
        if (strtotime((string) $resetToken->getAttribute('expires_at')) < time()) {
            return null;
        }

        return $resetToken;
    }

    private function renderResetEmailHtml(string $name, string $resetUrl): string
    {
        $appName = defined('APP_NAME') ? APP_NAME : 'Tourfecto';
        $safeName = htmlspecialchars($name ?: 'عزيزي العميل', ENT_QUOTES, 'UTF-8');
        $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<div dir="rtl" style="font-family: sans-serif; max-width: 480px; margin: 0 auto; padding: 24px;">
    <h2 style="color: #14100a;">مرحبًا {$safeName} 👋</h2>
    <p style="color: #444; line-height: 1.8;">وصلنا طلب إعادة تعيين كلمة المرور لحسابك في {$appName}. لو أنت اللي طلبت ده، دوس الزرار تحت خلال ساعة من دلوقتي:</p>
    <p style="text-align: center; margin: 30px 0;">
        <a href="{$safeUrl}" style="background:#EFB05E;color:#14100a;padding:14px 32px;border-radius:30px;text-decoration:none;font-weight:bold;">إعادة تعيين كلمة المرور</a>
    </p>
    <p style="color: #888; font-size: 12.5px;">لو مطلبتش ده، تجاهل الإيميل ده - حسابك آمن ومفيش حاجة هتتغيّر.</p>
    <p style="color: #888; font-size: 11.5px; word-break: break-all;">أو انسخ الرابط ده: {$safeUrl}</p>
</div>
HTML;
    }

    private function authPageShell(string $title, string $bodyHtml): string
    {
        $appName = defined('APP_NAME') ? APP_NAME : 'Tourfecto';
        $brandHtml = site_brand_html();
        $faviconHtml = site_favicon_html();
        $lang = function_exists('current_lang') ? current_lang() : 'ar';
        $dir = function_exists('current_dir') ? current_dir() : 'rtl';
        $navHome = $this->tr('nav.home');
        $navPricing = $this->tr('nav.pricing');
        $navHelp = $this->tr('nav.help');

        return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}" dir="{$dir}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} | {$appName}</title>
    {$faviconHtml}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <script src="/assets/js/tf-theme.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        :root { --auth-bg:#F4F5F8; --auth-card:#FFFFFF; --auth-line:#E3E6EC; --auth-text:#0F1B2D; --auth-muted:#5D687C; --auth-gold:#1F4FFF; --auth-input:#FFFFFF; --auth-err:#D92D20; --auth-errbg:#FDECEA; --auth-ok:#0E9F6E; --auth-okbg:#E3F6EE; color-scheme:light; }
        html[data-theme="dark"] { --auth-bg:#080D18; --auth-card:#0F1729; --auth-line:#1B2438; --auth-text:#E8ECF5; --auth-muted:#93A0B8; --auth-gold:#4C6FFF; --auth-input:#131C31; --auth-err:#FF7A70; --auth-errbg:rgba(255,122,112,.13); --auth-ok:#34D399; --auth-okbg:rgba(52,211,153,.13); color-scheme:dark; }
        body { background: var(--auth-bg); color: var(--auth-text); font-family: 'Readex Pro', 'Segoe UI', Tahoma, sans-serif; }
        .navbar { background: var(--auth-card); box-shadow: none; border-bottom: 1px solid var(--auth-line); }
        .navbar-brand { color: var(--auth-text) !important; font-weight: 700; }
        .nav-link { color: var(--auth-muted) !important; }
        .nav-link:hover { color: var(--auth-gold) !important; }
        .auth-wrap { min-height: calc(100vh - 70px); display: flex; align-items: center; justify-content: center; padding: var(--spacing-xl) var(--spacing-md); }
        .card, .auth-card {
            width: 100%; max-width: 420px; padding: var(--spacing-xl);
            background: var(--auth-card); border: 1px solid var(--auth-line); border-radius: 18px; box-shadow: 0 24px 60px -28px rgba(11,27,51,.35);
        }
        .auth-card h1, h1 { font-weight: 600; font-size: var(--font-size-xl); text-align: center; margin-bottom: var(--spacing-sm); color: var(--auth-text); }
        .auth-card .sub, .sub { text-align: center; color: var(--auth-muted); font-size: 13.5px; margin-bottom: var(--spacing-lg); }
        .auth-switch, .auth-switch a { text-align: center; margin-top: var(--spacing-md); color: var(--auth-muted); }
        .auth-switch a, a { color: var(--auth-gold); }
        .form-label { color: var(--auth-muted); }
        .form-control { background: var(--auth-input); border: 1px solid var(--auth-line); color: var(--auth-text); border-radius: 10px; }
        .form-control:focus { border-color: var(--auth-gold); box-shadow: 0 0 0 3px color-mix(in srgb, var(--auth-gold) 18%, transparent); }
        .form-text { color: var(--auth-muted); }
        .btn-primary, .btn-block { background: var(--auth-gold); border-color: var(--auth-gold); color: #fff; font-weight: 500; border-radius: 10px; }
        .btn-primary:hover { filter: brightness(1.1); }
        .alert-danger { background: var(--auth-errbg); color: var(--auth-err); border-color: transparent; }
        .alert-success { background: var(--auth-okbg); color: var(--auth-ok); border-color: transparent; }
        #formAlert, #successBox { display: none; }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container navbar-container">
            <a href="/" class="navbar-brand">{$brandHtml}</a>
            <ul class="navbar-nav">
                <li><a href="/" class="nav-link">{$navHome}</a></li>
                <li><a href="/pricing" class="nav-link">{$navPricing}</a></li>
                <li><a href="/help" class="nav-link">{$navHelp}</a></li>
            </ul>
        </div>
    </nav>
    <div class="auth-wrap">
        <div class="card auth-card">
            {$bodyHtml}
        </div>
    </div>
</body>
</html>
HTML;
    }

    private function renderForgotPasswordPage(): string
    {
        $title = $this->tr('auth.forgot.title');
        $sub = $this->tr('auth.forgot.sub');
        $emailLabel = $this->tr('auth.email');
        $submitLabel = $this->tr('auth.forgot.submit');
        $backLink = $this->tr('auth.back_to_login');
        $sendingText = $this->trJs('auth.forgot.sending');
        $genericSuccess = $this->trJs('auth.forgot.success_generic');
        $genericError = $this->trJs('auth.generic_error');
        $connectionError = $this->trJs('auth.connection_error');

        $body = <<<HTML
<h1>{$title}</h1>
<p class="sub">{$sub}</p>

<div id="formAlert" class="alert alert-danger"></div>
<div id="successBox" class="alert alert-success"></div>

<form id="forgotForm" novalidate>
    <div class="form-group">
        <label class="form-label" for="email">{$emailLabel}</label>
        <input type="email" id="email" name="email" class="form-control" required>
    </div>
    <button type="submit" class="btn btn-primary btn-block" id="submitBtn">{$submitLabel}</button>
</form>

<p class="auth-switch"><a href="/login">{$backLink}</a></p>

<script>
document.getElementById('forgotForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const alertBox = document.getElementById('formAlert');
    const successBox = document.getElementById('successBox');
    const btn = document.getElementById('submitBtn');
    alertBox.style.display = 'none';
    successBox.style.display = 'none';
    btn.disabled = true;
    const original = btn.textContent;
    btn.textContent = {$sendingText};

    try {
        const res = await fetch('/forgot-password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email: document.getElementById('email').value.trim() }),
        });
        const data = await res.json();

        if (data.success) {
            successBox.textContent = data.message || {$genericSuccess};
            successBox.style.display = 'block';
            document.getElementById('forgotForm').reset();
        } else {
            alertBox.textContent = data.error || {$genericError};
            alertBox.style.display = 'block';
        }
    } catch (err) {
        alertBox.textContent = {$connectionError};
        alertBox.style.display = 'block';
    } finally {
        btn.disabled = false;
        btn.textContent = original;
    }
});
</script>
HTML;

        return $this->authPageShell($title, $body);
    }

    private function renderResetPasswordPage(string $token, bool $valid): string
    {
        $tokenEsc = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

        if (!$valid) {
            $invalidTitle = $this->tr('auth.reset.invalid_title');
            $invalidSub = $this->tr('auth.reset.invalid_sub');
            $requestNew = $this->tr('auth.reset.request_new');
            $backLink = $this->tr('auth.back_to_login');

            $body = <<<HTML
<h1>{$invalidTitle}</h1>
<p class="sub">{$invalidSub}</p>
<a href="/forgot-password" class="btn btn-primary btn-block">{$requestNew}</a>
<p class="auth-switch"><a href="/login">{$backLink}</a></p>
HTML;
            return $this->authPageShell($invalidTitle, $body);
        }

        $title = $this->tr('auth.reset.title');
        $sub = $this->tr('auth.reset.sub');
        $newPasswordLabel = $this->tr('auth.reset.new_password');
        $minCharsHint = $this->tr('auth.reset.min_chars');
        $confirmLabel = $this->tr('auth.reset.confirm_password');
        $submitLabel = $this->tr('auth.reset.submit');
        $mismatchError = $this->trJs('auth.reset.mismatch_error');
        $savingText = $this->trJs('auth.reset.saving');
        $genericSuccess = $this->trJs('auth.reset.success_generic');
        $genericError = $this->trJs('auth.generic_error');
        $connectionError = $this->trJs('auth.connection_error');

        $body = <<<HTML
<h1>{$title}</h1>
<p class="sub">{$sub}</p>

<div id="formAlert" class="alert alert-danger"></div>
<div id="successBox" class="alert alert-success"></div>

<form id="resetForm" novalidate>
    <input type="hidden" id="token" value="{$tokenEsc}">
    <div class="form-group">
        <label class="form-label" for="password">{$newPasswordLabel}</label>
        <input type="password" id="password" class="form-control" minlength="8" required>
        <small class="form-text">{$minCharsHint}</small>
    </div>
    <div class="form-group">
        <label class="form-label" for="password_confirm">{$confirmLabel}</label>
        <input type="password" id="password_confirm" class="form-control" minlength="8" required>
    </div>
    <button type="submit" class="btn btn-primary btn-block" id="submitBtn">{$submitLabel}</button>
</form>

<script>
document.getElementById('resetForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const alertBox = document.getElementById('formAlert');
    const successBox = document.getElementById('successBox');
    const btn = document.getElementById('submitBtn');
    alertBox.style.display = 'none';
    successBox.style.display = 'none';

    const password = document.getElementById('password').value;
    const confirm = document.getElementById('password_confirm').value;
    if (password !== confirm) {
        alertBox.textContent = {$mismatchError};
        alertBox.style.display = 'block';
        return;
    }

    btn.disabled = true;
    const original = btn.textContent;
    btn.textContent = {$savingText};

    try {
        const res = await fetch('/reset-password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: document.getElementById('token').value, password }),
        });
        const data = await res.json();

        if (data.success) {
            successBox.textContent = data.message || {$genericSuccess};
            successBox.style.display = 'block';
            document.getElementById('resetForm').style.display = 'none';
            setTimeout(() => window.location.href = '/login', 1800);
        } else {
            alertBox.textContent = data.error || {$genericError};
            alertBox.style.display = 'block';
        }
    } catch (err) {
        alertBox.textContent = {$connectionError};
        alertBox.style.display = 'block';
    } finally {
        btn.disabled = false;
        btn.textContent = original;
    }
});
</script>
HTML;

        return $this->authPageShell($title, $body);
    }

    /**
     * تأكيد البريد الإلكتروني
     * GET /verify-email/{token} (الرابط اللي في الإيميل) و POST /api/auth/verify-email {token}
     */
    public function verifyEmail(array $params = []): array
    {
        $isApi = strpos(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/api/') === 0;
        if ($rateError = $this->rateLimitGuard('ip', 'auth_ip', 30, 60)) {
            return $rateError;
        }
        $token = (string) ($params['token'] ?? $this->get('token') ?? '');
        $uid = class_exists('EmailVerification') ? EmailVerification::verify($token) : null;

        if ($uid) {
            $this->log('email_verified', ['user_id' => $uid]);
        }
        if ($isApi) {
            return $uid
                ? $this->success(['verified' => true], 'تم تأكيد البريد الإلكتروني بنجاح')
                : $this->error('رابط التأكيد غير صالح أو انتهت صلاحيته', 400);
        }

        if ($uid) {
            $loggedIn = (int) ($_SESSION['user_id'] ?? 0);
            header('Location: ' . ($loggedIn ? '/dashboard?verified=1' : '/login?verified=1'));
            exit;
        }

        $loggedIn = !empty($_SESSION['user_id']);
        $action = $loggedIn
            ? '<a href="/verify-email" class="btn btn-primary btn-block">ابعتلي رابط جديد</a>'
            : '<a href="/login" class="btn btn-primary btn-block">سجّل دخول عشان نبعتلك رابط جديد</a>';
        $body = <<<HTML
<h1>الرابط ده مش شغّال</h1>
<p class="sub">ممكن يكون انتهت صلاحيته (24 ساعة) أو اتستخدم قبل كده، أو في رابط أحدث اتبعت بعده.</p>
{$action}
<p class="auth-switch"><a href="/dashboard">الرجوع للوحة التحكم</a></p>
HTML;
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(400);
        echo $this->authPageShell('تأكيد البريد الإلكتروني', $body);
        exit;
    }

    /**
     * صفحة "أكّد بريدك" - بيتحوّل لها العميل بعد انتهاء المهلة
     * GET /verify-email
     */
    public function verifyNotice(array $params = []): array
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }
        $rows = Database::getInstance()->query("SELECT * FROM users WHERE id = ? LIMIT 1", [(int) $_SESSION['user_id']]);
        $user = $rows[0] ?? null;
        if (!$user || !class_exists('EmailVerification') || !EmailVerification::isPending($user)) {
            header('Location: /dashboard');
            exit;
        }
        $email = htmlspecialchars((string) $user['email'], ENT_QUOTES, 'UTF-8');
        $body = <<<HTML
<h1>أكّد بريدك الإلكتروني ✉️</h1>
<p class="sub">بعتنا رابط تأكيد على <b dir="ltr">{$email}</b>. افتح الإيميل ودوس على الزرار اللي فيه، وبعدها هتقدر تكمّل استخدام اللوحة عادي.</p>
<p class="sub" style="font-size:13px">مش لاقيه؟ بُص في الـ Spam أو Promotions، أو ابعته تاني:</p>
<div id="evMsg" class="alert" style="display:none"></div>
<button type="button" id="evResendBtn" class="btn btn-primary btn-block">ابعت الرابط تاني</button>
<p class="auth-switch">الإيميل غلط؟ <a href="/logout">سجّل خروج</a> وسجّل بإيميل صحيح، أو كلّم الدعم.</p>
<script>
(function(){var b=document.getElementById('evResendBtn'),m=document.getElementById('evMsg');
b.addEventListener('click',function(){b.disabled=true;
fetch('/api/auth/resend-verification',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:'{}'})
.then(function(r){return r.json().catch(function(){return {};});})
.then(function(d){m.style.display='block';m.className='alert '+(d.success?'alert-success':'alert-error');m.textContent=d.message||d.error||'تم';setTimeout(function(){b.disabled=false;},30000);})
.catch(function(){m.style.display='block';m.className='alert alert-error';m.textContent='مشكلة في الاتصال، جرّب تاني';b.disabled=false;});});
setInterval(function(){fetch('/api/auth/verification-status',{credentials:'same-origin',headers:{'Accept':'application/json'}}).then(function(r){return r.json();}).then(function(d){if(d&&d.data&&d.data.verified){location.href='/dashboard?verified=1';}}).catch(function(){});},8000);
})();
</script>
HTML;
        header('Content-Type: text/html; charset=utf-8');
        echo $this->authPageShell('أكّد بريدك الإلكتروني', $body);
        exit;
    }

    /**
     * حالة التأكيد (الصفحة بتسأل كل شوية عشان تكمّل تلقائي بعد ما يأكّد من موبايله مثلًا)
     * GET /api/auth/verification-status
     */
    public function verificationStatus(array $params = []): array
    {
        $uid = (int) ($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) {
            return $this->error('غير مسجّل', 401);
        }
        $rows = Database::getInstance()->query("SELECT email_verified_at FROM users WHERE id = ? LIMIT 1", [$uid]);
        return $this->success(['verified' => !empty($rows[0]['email_verified_at'])]);
    }

    /**
     * إعادة إرسال بريد التأكيد
     * POST /api/auth/resend-verification
     *  - لو مسجّل دخول: بيبعت لإيميل حسابه.
     *  - لو مش مسجّل: {email} وبنرد نفس الرسالة دايمًا (عشان محدش يكتشف الإيميلات المسجّلة).
     */
    public function resendVerification(array $params = []): array
    {
        if ($rateError = $this->rateLimitGuard('ip', 'auth_ip', 10, 60)) {
            return $rateError;
        }
        if (!class_exists('EmailVerification') || !EmailVerification::tableReady()) {
            return $this->error('تأكيد البريد مش متفعّل على السيرفر لسه', 503);
        }

        $uid = (int) ($_SESSION['user_id'] ?? 0);
        if ($uid > 0) {
            $res = EmailVerification::send($uid);
            if (!empty($res['already'])) {
                return $this->success(['verified' => true], 'بريدك متأكّد بالفعل');
            }
            if (!empty($res['throttled'])) {
                return $this->error('بعتنا كذا رابط في آخر ساعة. استنى شوية وبُص في الـ Spam', 429);
            }
            if (($res['error'] ?? '') === 'mail_not_configured') {
                return $this->error('البريد مش متظبط على المنصة حاليًا - كلّم الدعم وهيأكّدوا حسابك يدويًا', 503);
            }
            if (empty($res['success'])) {
                return $this->error('مقدرناش نبعت الإيميل دلوقتي، جرّب كمان شوية أو كلّم الدعم', 500);
            }
            return $this->success([], 'تم إرسال رابط جديد على بريدك');
        }

        $generic = 'لو البريد ده مسجّل ومحتاج تأكيد، هيوصلك رابط خلال دقايق';
        $email = trim((string) $this->get('email'));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('اكتب بريد إلكتروني صحيح', 422);
        }
        try {
            $user = User::findByEmail($email);
            if ($user && !$user->getAttribute('email_verified_at')) {
                EmailVerification::send((int) $user->getAttribute('id'));
            }
        } catch (Throwable $e) {
            Logger::error('Resend verification error', ['message' => $e->getMessage()]);
        }
        return $this->success([], $generic);
    }

    /**
     * توليد صفحة HTML لفورم تسجيل الدخول أو التسجيل.
     *
     * @param string $mode 'login' أو 'register'
     * @return string
     */
    private function renderAuthPage(string $mode): string
    {
        $appName = defined('APP_NAME') ? APP_NAME : 'Tourfecto';
        $topNavBrandHtml = site_brand_html();
        $faviconHtml = site_favicon_html();
        $isRegister = $mode === 'register';
        $lang = current_lang();
        $dir = current_dir();

        $title = $isRegister ? t('auth.register.title') : t('auth.login.title');
        $action = $isRegister ? '/register' : '/login';
        // المستخدم الجديد بعد التسجيل بيتوجه لمعالج الإعداد السريع (/onboarding)
        // عشان يكمّل بياناته أولًا بدل ما يضيع وسط الداشبورد. العائدين بالـ login
        // بيفضلوا يروحوا /dashboard زي ما هو (المعالج لوحدها بيعرف يحدّث بياناته).
        // ?redirect= (مسار داخلي بس) - مثلاً رابط دعوة الوكالة يرجّع المستخدم لصفحة القبول بعد الدخول
        $safeRedirect = $this->safeRedirectParam();
        $feature = $this->serviceFeatureFromRequest();
        $featureNote = '';
        if (class_exists('MarketingContent') && isset(MarketingContent::features()[$feature])) {
            $chosen = MarketingContent::features()[$feature];
            $safeRedirect = $safeRedirect ?? '/onboarding?feature=' . $feature;
            $featureNote = '<p role="status">' . htmlspecialchars(MarketingContent::t(['الخدمة المختارة: ', 'Selected service: ']) . MarketingContent::t($chosen['title']), ENT_QUOTES, 'UTF-8') . '</p>';
        }

        $redirectPathJson = json_encode($safeRedirect ?? ($isRegister ? '/onboarding' : '/dashboard'));
        $socialFeatureQuery = $feature !== '' ? '?feature=' . rawurlencode($feature) : '';
        $switchText = $isRegister ? t('auth.switch.have_account') : t('auth.switch.new_user');
        $switchLink = ($isRegister ? '/login' : '/register') . ($safeRedirect ? '?redirect=' . rawurlencode($safeRedirect) : '');
        $switchLabel = $isRegister ? t('auth.switch.login') : t('auth.switch.register');

        $extraFields = '';
        if ($isRegister) {
            $extraFields = <<<HTML
                <div class="form-group">
                    <label class="form-label" for="company_name">{$this->tr('auth.company_name')}</label>
                    <input type="text" id="company_name" name="company_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="phone">{$this->tr('auth.phone_optional')}</label>
                    <input type="tel" id="phone" name="phone" class="form-control">
                </div>
HTML;
        }

        if ($isRegister) $extraFields .= InternationalSettingsController::registrationFields();

        $passwordHint = $isRegister
            ? '<small class="form-text">' . $this->tr('auth.password_hint') . '</small>'
            : '<small class="form-text"><a href="/forgot-password">' . $this->tr('auth.forgot_password_link') . '</a></small>';

        $oauthErrorMessages = [
            'oauth_error_1' => $lang === 'en' ? 'Unknown sign-in provider' : ('منصة تسجيل دخول غير معروفة'),
            'oauth_error_2' => $lang === 'en' ? 'This sign-in provider is currently disabled' : ('تسجيل الدخول بواسطة ' . 'الحساب الخارجي' . ' غير مفعّل حاليًا'),
            'oauth_error_3' => $lang === 'en' ? 'Could not start sign-in' : ('تعذر بدء تسجيل الدخول بواسطة ' . 'الحساب الخارجي'),
            'oauth_error_4' => $lang === 'en' ? 'Sign-in was cancelled' : ('تم إلغاء تسجيل الدخول بواسطة ' . 'الحساب الخارجي'),
            'oauth_error_5' => $lang === 'en' ? 'Your sign-in attempt expired. Please try again' : ('انتهت صلاحية محاولة تسجيل الدخول، حاول تاني'),
            'oauth_error_6' => $lang === 'en' ? 'Could not sign in' : ('تعذر تسجيل الدخول بواسطة ' . 'الحساب الخارجي'),
            'oauth_error_7' => $lang === 'en' ? 'Could not retrieve your account details' : ('تعذر جلب بيانات الحساب من ' . 'الحساب الخارجي'),
            'oauth_error_8' => $lang === 'en' ? 'An error occurred during sign-in' : ('حدث خطأ أثناء تسجيل الدخول بواسطة ' . 'الحساب الخارجي'),
            'oauth_error_9' => $lang === 'en' ? 'Sign-in was cancelled' : ('تم إلغاء تسجيل الدخول بواسطة Apple'),
            'oauth_error_10' => $lang === 'en' ? 'Could not sign in' : ('تعذر تسجيل الدخول بواسطة Apple'),
            'oauth_error_11' => $lang === 'en' ? 'Could not read your account details' : ('تعذر قراءة بيانات الحساب من Apple'),
            'oauth_error_12' => $lang === 'en' ? 'An error occurred during sign-in' : ('حدث خطأ أثناء تسجيل الدخول بواسطة Apple'),
            'oauth_error_13' => $lang === 'en' ? 'Please allow access to your email to sign in' : ('لازم توافق على مشاركة الإيميل عشان تقدر تسجّل دخول بالطريقة دي'),
            'oauth_error_14' => $lang === 'en' ? 'Could not create the account. Please try again' : ('تعذر إنشاء الحساب، حاول تاني'),
            'oauth_error_15' => $lang === 'en' ? 'This account is inactive or suspended. Please contact support' : ('هذا الحساب غير مُفعّل أو موقوف، تواصل مع الدعم'),
        ];
        $oauthErrorCode = (string) ($_GET['error'] ?? '');
        $oauthError = htmlspecialchars($oauthErrorMessages[$oauthErrorCode] ?? $oauthErrorCode, ENT_QUOTES, 'UTF-8');
        $oauthErrorStyle = $oauthError !== '' ? ' style="display:block;"' : '';
        // رجوع من رابط تأكيد البريد وهو مش مسجّل دخول
        $verifiedNotice = (!$isRegister && ($_GET['verified'] ?? '') === '1')
            ? '<div class="alert alert-success" style="display:block;">✅ تم تأكيد بريدك الإلكتروني بنجاح - سجّل دخولك وكمّل.</div>'
            : '';
        $csrfField = class_exists('Csrf') ? Csrf::field() : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}" dir="{$dir}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} | {$appName}</title>
    {$faviconHtml}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <script src="/assets/js/tf-theme.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        :root { --auth-bg:#F4F5F8; --auth-card:#FFFFFF; --auth-line:#E3E6EC; --auth-text:#0F1B2D; --auth-muted:#5D687C; --auth-gold:#1F4FFF; --auth-input:#FFFFFF; --auth-err:#D92D20; --auth-errbg:#FDECEA; --auth-ok:#0E9F6E; --auth-okbg:#E3F6EE; color-scheme:light; }
        html[data-theme="dark"] { --auth-bg:#080D18; --auth-card:#0F1729; --auth-line:#1B2438; --auth-text:#E8ECF5; --auth-muted:#93A0B8; --auth-gold:#4C6FFF; --auth-input:#131C31; --auth-err:#FF7A70; --auth-errbg:rgba(255,122,112,.13); --auth-ok:#34D399; --auth-okbg:rgba(52,211,153,.13); color-scheme:dark; }
        body { background: var(--auth-bg); color: var(--auth-text); font-family: 'Readex Pro', 'Segoe UI', Tahoma, sans-serif; }
        .navbar { background: var(--auth-card); box-shadow: none; border-bottom: 1px solid var(--auth-line); }
        .navbar-brand { color: var(--auth-text) !important; font-weight: 700; }
        .nav-link { color: var(--auth-muted) !important; }
        .nav-link:hover { color: var(--auth-gold) !important; }
        .auth-wrap {
            min-height: calc(100vh - 70px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: var(--spacing-xl) var(--spacing-md);
        }
        .card, .auth-card {
            width: 100%; max-width: 420px; padding: var(--spacing-xl);
            background: var(--auth-card); border: 1px solid var(--auth-line); border-radius: 18px; box-shadow: 0 24px 60px -28px rgba(11,27,51,.35);
        }
        .auth-card h1, h1 { font-weight: 600; font-size: var(--font-size-xl); text-align: center; margin-bottom: var(--spacing-lg); color: var(--auth-text); }
        .auth-card .sub, .sub { text-align: center; color: var(--auth-muted); font-size: 13.5px; margin-bottom: var(--spacing-lg); }
        .auth-switch, .auth-switch a { text-align: center; margin-top: var(--spacing-md); color: var(--auth-muted); }
        .auth-switch a, a { color: var(--auth-gold); }
        .form-label { color: var(--auth-muted); }
        .form-control {
            background: rgba(255,255,255,.04); border: 1px solid var(--auth-line); color: var(--auth-text);
        }
        .form-control:focus { border-color: var(--auth-gold); box-shadow: 0 0 0 3px color-mix(in srgb, var(--auth-gold) 18%, transparent); }
        .form-text { color: var(--auth-muted); }
        .btn-primary, .btn-block { background: var(--auth-gold); border-color: var(--auth-gold); color: #fff; font-weight: 500; border-radius: 10px; }
        .btn-primary:hover { filter: brightness(1.1); }
        .alert-danger { background: var(--auth-errbg); color: var(--auth-err); border-color: transparent; }
        .alert-success { background: var(--auth-okbg); color: var(--auth-ok); border-color: transparent; }
        #formAlert { display: none; }
        .auth-divider { display:flex; align-items:center; gap:12px; margin:22px 0; color:var(--auth-muted); font-size:12px; }
        .auth-divider::before, .auth-divider::after { content:""; flex:1; height:1px; background:var(--auth-line); }
        .auth-social-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; }
        .auth-social-btn {
            display:flex; align-items:center; justify-content:center; gap:8px;
            padding:11px 14px; border-radius:11px; border:1px solid var(--auth-line);
            background:rgba(255,255,255,.03); color:var(--auth-text); text-decoration:none;
            font-size:13.5px; font-weight:600; transition:border-color .2s, background .2s;
        }
        .auth-social-btn:hover { border-color:var(--auth-gold); background:rgba(255,255,255,.06); }
    </style>
</head>
<body>

    <nav class="navbar">
        <div class="container navbar-container">
            <a href="/" class="navbar-brand">{$topNavBrandHtml}</a>
            <ul class="navbar-nav">
                <li><a href="/" class="nav-link">{$this->tr('nav.home')}</a></li>
                <li><a href="/pricing" class="nav-link">{$this->tr('nav.pricing')}</a></li>
                <li><a href="/help" class="nav-link">{$this->tr('nav.help')}</a></li>
            </ul>
        </div>
    </nav>

    <div class="auth-wrap">
        <div class="card auth-card">
            <h1>{$title}</h1>

            {$verifiedNotice}
            <div id="formAlert" class="alert alert-danger"{$oauthErrorStyle}>{$oauthError}</div>

            <form id="authForm" novalidate>
                {$featureNote}
                {$csrfField}
                {$extraFields}
                <div class="form-group">
                    <label class="form-label" for="email">{$this->tr('auth.email')}</label>
                    <input type="email" id="email" name="email" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="password">{$this->tr('auth.password')}</label>
                    <input type="password" id="password" name="password" class="form-control" required>
                    {$passwordHint}
                </div>
                <button type="submit" class="btn btn-primary btn-block" id="submitBtn">{$title}</button>
            </form>

            <div class="auth-divider">{$this->tr('auth.or')}</div>
            <div class="auth-social-grid">
                <a href="/auth/google{$socialFeatureQuery}" class="auth-social-btn">
                    <svg width="18" height="18" viewBox="0 0 18 18"><path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84c-.21 1.13-.84 2.09-1.8 2.73v2.27h2.91c1.7-1.57 2.69-3.88 2.69-6.64z"/><path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.91-2.27c-.81.54-1.84.86-3.05.86-2.34 0-4.32-1.58-5.03-3.71H.96v2.33C2.44 15.98 5.48 18 9 18z"/><path fill="#FBBC05" d="M3.97 10.7c-.18-.54-.28-1.11-.28-1.7s.1-1.16.28-1.7V4.97H.96A8.996 8.996 0 000 9c0 1.45.35 2.83.96 4.03l3.01-2.33z"/><path fill="#EA4335" d="M9 3.58c1.32 0 2.51.45 3.44 1.35l2.58-2.58C13.46.89 11.43 0 9 0 5.48 0 2.44 2.02.96 4.97l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58z"/></svg>
                    Google
                </a>
                <a href="/auth/facebook{$socialFeatureQuery}" class="auth-social-btn">
                    <svg width="18" height="18" viewBox="0 0 18 18"><path fill="#1877F2" d="M18 9a9 9 0 10-10.4 8.89v-6.29H5.31V9h2.29V7.02c0-2.26 1.35-3.51 3.41-3.51.99 0 2.02.18 2.02.18v2.22h-1.14c-1.12 0-1.47.7-1.47 1.41V9h2.5l-.4 2.6h-2.1v6.29A9 9 0 0018 9z"/></svg>
                    Facebook
                </a>
            </div>

            <p class="auth-switch">
                {$switchText} <a href="{$switchLink}">{$switchLabel}</a>
            </p>
        </div>
    </div>

    <script>
        const AUTH_I18N = { processing: {$this->trJs('auth.processing')} };
        const REDIRECT_PATH = {$redirectPathJson};
        document.getElementById('authForm').addEventListener('submit', async function (e) {
            e.preventDefault();

            const alertBox = document.getElementById('formAlert');
            const submitBtn = document.getElementById('submitBtn');
            alertBox.style.display = 'none';

            const payload = Object.fromEntries(new FormData(e.target).entries());

            submitBtn.disabled = true;
            const originalLabel = submitBtn.textContent;
            submitBtn.textContent = AUTH_I18N.processing;

            try {
                const res = await fetch('{$action}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const result = await res.json();

                if (result.success) {
                    window.location.href = REDIRECT_PATH;
                    return;
                }

                let message = result.error || 'حدث خطأ، حاول مرة أخرى';
                if (result.details && typeof result.details === 'object') {
                    const fieldErrors = Object.values(result.details).flat();
                    if (fieldErrors.length) {
                        message = fieldErrors.join(' - ');
                    }
                }
                alertBox.textContent = message;
                alertBox.style.display = 'block';
            } catch (err) {
                alertBox.textContent = 'تعذر الاتصال بالخادم، حاول مرة أخرى';
                alertBox.style.display = 'block';
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = originalLabel;
            }
        });
    </script>
</body>
</html>
HTML;
    }

    /**
     * تسجيل محاولة دخول (ناجحة أو فاشلة) في جدول login_history، مع الجهاز والموقع الجغرافي.
     * أي فشل هنا (جدول ناقص، مشكلة اتصال...) لا يجب أن يوقف عملية تسجيل الدخول نفسها.
     *
     * @param int|null $userId
     * @param string $email
     * @param string $status 'success' أو 'failed'
     * @param bool $isImpersonation
     */
    /**
     * فحص محاولات الدخول المتكررة على نفس البريد. لو 5 محاولات فاشلة
     * أو أكتر خلال آخر 15 دقيقة، نمنع أي محاولة جديدة لنفس الإيميل
     * (سواء كانت كلمة المرور صح أو غلط) لمدة 15 دقيقة كمان - يمنع أي
     * أداة آلية من تجربة آلاف الباسوردات، ومحدش بيتأثر في الاستخدام
     * العادي (5 غلطات نادر حد يوصلها وهو بيكتب صح).
     * @return array|null رسالة خطأ لو محظور، أو null لو مسموح يكمّل
     */
    private function checkLoginRateLimit(string $email): ?array
    {
        try {
            $db = Database::getInstance();
            $rows = $db->query(
                "SELECT COUNT(*) AS attempts FROM login_history
                 WHERE email_attempted = ? AND status = 'failed' AND created_at > (NOW() - INTERVAL 15 MINUTE)",
                [$email]
            );
            $attempts = (int) ($rows[0]['attempts'] ?? 0);

            if ($attempts >= 5) {
                return $this->error(
                    'محاولات دخول كتير فشلت على الإيميل ده. استنى 15 دقيقة وجرّب تاني، أو استخدم "نسيت كلمة المرور؟" لو نسيت الباسورد فعلاً.',
                    429
                );
            }
        } catch (Exception $e) {
            // لو فشل الفحص لأي سبب، منمنعش تسجيل الدخول العادي بسببه -
            // الحماية دي إضافية مش أساسية لعمل الموقع
            Logger::error('checkLoginRateLimit Error', ['message' => $e->getMessage()]);
        }
        return null;
    }

    /**
     * فحص حد محاولات التحقق بخطوتين (Brute-Force Protection) - Phase 15.
     * يسمح بـ 5 محاولات خلال 15 دقيقة على نفس المستخدم (أو الـ IP لو
     * المستخدم لسه مش معروف)، وبعدها يُحظر. يستخدم نفس RateLimiter
     * الموجود أصلًا في المشروع (rate_limit_blocks) - لا أي اعتماد جديد.
     *
     * @return bool true = مقفل، يجب رفض الطلب
     */
    private function checkTwoFactorRateLimit(string $identifier): bool
    {
        try {
            $limiter = new RateLimiter();
            return !$limiter->check($identifier, '2fa_verify', 5, 900);
        } catch (\Throwable $e) {
            Logger::error('checkTwoFactorRateLimit Error', ['message' => $e->getMessage()]);
            return false; // لو فشل الفحص لأي سبب، منمنعش التحقق بسببه
        }
    }

    /** فك الحظر عن التحقق بخطوتين بعد نجاح الكود - يصفّر عدّاد المحاولات */
    private function resetTwoFactorRateLimit(string $identifier): void
    {
        try {
            $limiter = new RateLimiter();
            $limiter->unblockIdentifier($identifier);
        } catch (\Throwable $e) {
            Logger::error('resetTwoFactorRateLimit Error', ['message' => $e->getMessage()]);
        }
    }

    protected function recordLoginHistory(?int $userId, string $email, string $status, bool $isImpersonation = false): void
    {
        try {
            $ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
            $userAgent = function_exists('get_user_agent') ? get_user_agent() : ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');

            $device = class_exists('DeviceDetector')
                ? DeviceDetector::parse($userAgent)
                : ['device_type' => null, 'browser' => null, 'platform' => null];

            $geo = class_exists('GeoIPService')
                ? GeoIPService::lookup($ip)
                : ['country' => null, 'city' => null, 'region' => null, 'latitude' => null, 'longitude' => null];

            $db = Database::getInstance();
            $sql = "INSERT INTO login_history
                        (user_id, email_attempted, status, ip_address, user_agent, device_type, browser, platform,
                         country, city, region, latitude, longitude, session_id, is_impersonation, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            $db->exec($sql, [
                $userId,
                mb_substr($email, 0, 255),
                $status,
                $ip,
                $userAgent,
                $device['device_type'] ?? null,
                $device['browser'] ?? null,
                $device['platform'] ?? null,
                $geo['country'] ?? null,
                $geo['city'] ?? null,
                $geo['region'] ?? null,
                $geo['latitude'] ?? null,
                $geo['longitude'] ?? null,
                session_id() ?: null,
                $isImpersonation ? 1 : 0,
            ]);
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('recordLoginHistory failed', ['message' => $e->getMessage()]);
            }
        }
    }
}
