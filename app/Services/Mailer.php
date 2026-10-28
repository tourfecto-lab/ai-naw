<?php

/**
 * Tourfecto - Mailer (SMTP خالص بـ PHP، من غير أي مكتبة خارجية زي
 * PHPMailer). السبب: السيرفر ده مفيهوش Terminal/SSH لتشغيل composer
 * require، فأي مكتبة خارجية محتاجة تتضاف كملفات يدويًا. بديل بسيط:
 * كلاس SMTP خفيف بيتكلم مباشرة مع سيرفر البريد عن طريق sockets،
 * بيدعم STARTTLS وAUTH LOGIN (كافي لأي مزوّد شائع زي Gmail/Hostinger).
 * @version 1.0.0
 */
class Mailer
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $encryption; // tls | ssl | ''
    private string $fromEmail;
    private string $fromName;
    private int $timeout = 15;

    public function __construct()
    {
        $this->host = defined('MAIL_HOST') ? MAIL_HOST : '';
        $this->port = defined('MAIL_PORT') ? (int) MAIL_PORT : 587;
        $this->username = defined('MAIL_USERNAME') ? MAIL_USERNAME : '';
        $this->password = defined('MAIL_PASSWORD') ? MAIL_PASSWORD : '';
        $this->encryption = defined('MAIL_ENCRYPTION') ? strtolower(MAIL_ENCRYPTION) : 'tls';
        $this->fromEmail = defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : 'noreply@tourfecto.com';
        $this->fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Tourfecto';

        // إعدادات البريد من لوحة الأدمن (الإعدادات / التكاملات) ليها الأولوية
        // على .env - قبل كده كانت بتتحفظ ومابتتستخدمش خالص.
        if (class_exists('SystemSettingsService')) {
            try {
                $s = new SystemSettingsService();
                $map = ['mail_host' => 'host', 'mail_port' => 'port', 'mail_username' => 'username', 'mail_password' => 'password',
                        'mail_encryption' => 'encryption', 'mail_from_address' => 'from_email', 'mail_from_name' => 'from_name'];
                $cfg = [];
                foreach ($map as $key => $field) {
                    // الأولوية: صفحة التكاملات (integration_mail_host) ثم صفحة الإعدادات (mail_host) ثم .env
                    $v = trim($s->get('integration_' . $key, ''));
                    if ($v === '') {
                        $v = trim($s->get($key, ''));
                    }
                    if ($v !== '') {
                        $cfg[$field] = $field === 'port' ? (int) $v : $v;
                    }
                }
                if ($cfg) {
                    $this->configure($cfg);
                }
            } catch (Throwable $e) {
            }
        }
    }

    /**
     * يضبط إعدادات SMTP يدويًا (يُستخدم لإعدادات كل مستخدم من قاعدة البيانات).
     * القيم الفارغة لا تتجاوز القيم الحالية.
     */
    public function configure(array $settings): self
    {
        if (!empty($settings['host'])) {
            $this->host = (string) $settings['host'];
        }
        if (array_key_exists('port', $settings) && (int) $settings['port'] > 0) {
            $this->port = (int) $settings['port'];
        }
        if (array_key_exists('username', $settings) && $settings['username'] !== null && $settings['username'] !== '') {
            $this->username = (string) $settings['username'];
        }
        if (array_key_exists('password', $settings) && $settings['password'] !== null && $settings['password'] !== '') {
            $this->password = (string) $settings['password'];
        }
        if (!empty($settings['encryption'])) {
            $this->encryption = strtolower((string) $settings['encryption']);
        }
        if (!empty($settings['from_email'])) {
            $this->fromEmail = (string) $settings['from_email'];
        }
        if (!empty($settings['from_name'])) {
            $this->fromName = (string) $settings['from_name'];
        }
        return $this;
    }

    /**
     * يختبر الاتصال بسيرفر SMTP والمصادقة دون إرسال أي بريد.
     * @return array ['success'=>bool, 'error'=>?string]
     */
    public function testConnection(): array
    {
        $socketHost = ($this->encryption === 'ssl' ? 'ssl://' : '') . $this->host;
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client("{$socketHost}:{$this->port}", $errno, $errstr, $this->timeout);

        if (!$socket) {
            return ['success' => false, 'error' => "تعذر الاتصال بسيرفر البريد ({$errstr})"];
        }

        try {
            $this->expect($socket, '220');
            $this->command($socket, "EHLO " . ($_SERVER['SERVER_NAME'] ?? 'tourfecto.pro'), '250');

            if ($this->encryption === 'tls') {
                $this->command($socket, "STARTTLS", '220');
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new Exception('فشل تفعيل TLS مع سيرفر البريد');
                }
                $this->command($socket, "EHLO " . ($_SERVER['SERVER_NAME'] ?? 'tourfecto.pro'), '250');
            }

            $this->command($socket, "AUTH LOGIN", '334');
            $this->command($socket, base64_encode($this->username), '334');
            $this->command($socket, base64_encode($this->password), '235');

            $this->command($socket, "QUIT", '221');
            fclose($socket);

            return ['success' => true];
        } catch (Exception $e) {
            @fclose($socket);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** هل الإيميل متظبط أصلاً؟ (يوزر/باسورد حقيقيين مش placeholders) */
    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->username !== '' && $this->password !== ''
            && strpos($this->username, 'your-email') === false
            && strpos($this->password, 'your-app-password') === false;
    }

    /**
     * @param array<string,string> $extraHeaders هيدرز إضافية تُحقن في الرسالة
     *                              (مثل List-Unsubscribe). القيم تُنقّى من CR/LF
     *                              حمايةً من header injection.
     * @return array ['success'=>bool, 'error'=>?string]
     */
    /** @var string|null نوع الرسالة في سجل البريد (password_reset / invoice ...) */
    private $logContext = null;
    /** @var int|null العميل صاحب الإيميل (لو معروف) */
    private $logUserId = null;
    /** @var int|null لو دي إعادة إرسال لرسالة قديمة */
    private $resentFromId = null;

    /** يحدد نوع الرسالة اللي هيظهر في سجل البريد بلوحة الأدمن */
    public function context(string $context, ?int $userId = null): self
    {
        $this->logContext = substr($context, 0, 60);
        $this->logUserId = $userId;
        return $this;
    }

    /** @internal إعادة الإرسال من سجل البريد */
    public function resentFrom(int $id): self
    {
        $this->resentFromId = $id;
        return $this;
    }

    /**
     * بيبعت الرسالة وبيسجّلها في سجل البريد (لوحة الأدمن ← سجل البريد) سواء نجحت أو فشلت.
     * @param array<string,string> $extraHeaders
     * @return array ['success'=>bool, 'error'=>?string]
     */
    public function send(string $toEmail, string $toName, string $subject, string $htmlBody, array $extraHeaders = []): array
    {
        $t = microtime(true);
        $res = $this->deliver($toEmail, $toName, $subject, $htmlBody, $extraHeaders);
        if (class_exists('MailLog')) {
            $isBulk = false;
            foreach (array_keys($extraHeaders) as $h) {
                if (stripos((string) $h, 'List-Unsubscribe') === 0) {
                    $isBulk = true;
                }
            }
            MailLog::record([
                'to_email' => $toEmail,
                'to_name' => $toName,
                'subject' => $subject,
                // رسايل الحملات الجماعية مابنخزنش جسمها (حجم)
                'body_html' => $isBulk ? null : $htmlBody,
                'status' => !empty($res['success']) ? 'sent' : 'failed',
                'error' => $res['error'] ?? null,
                'context' => $this->logContext ?? ($isBulk ? 'campaign' : self::inferContext()),
                'smtp_host' => $this->host,
                'from_email' => $this->fromEmail,
                'user_id' => $this->logUserId,
                'resent_from_id' => $this->resentFromId,
                'duration_ms' => (int) round((microtime(true) - $t) * 1000),
            ]);
        }
        return $res;
    }

    /** نوع الرسالة من مكان الإرسال (عشان سجل البريد يبقى مفهوم من غير ما نعدّل كل مكان بيبعت) */
    private static function inferContext(): ?string
    {
        $map = [
            'AuthController' => 'auth', 'WorkspaceController' => 'team_invite', 'CrmTeamInviteService' => 'team_invite',
            'OutreachController' => 'outreach', 'SendBookingConfirmationJob' => 'booking_confirmation',
            'SendCompetitorAlertEmailJob' => 'competitor_alert', 'SendRevenueDigestJob' => 'revenue_digest',
            'SendOnboardingCompletionEmailJob' => 'onboarding', 'SeoScheduledReportService' => 'seo_report',
            'CrmEmailService' => 'crm', 'EmailChannelAPI' => 'chat_email', 'SmtpSettingsService' => 'customer_smtp',
            'IntegrationsController' => 'smtp_test', 'ReviewRequestService' => 'review_request',
            'SubscriptionLifecycleService' => 'billing', 'AdminAlertService' => 'admin_alert', 'NotificationService' => 'notification',
        ];
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6) as $f) {
            $cls = $f['class'] ?? '';
            if ($cls === '' || $cls === __CLASS__) {
                continue;
            }
            if ($cls === 'AuthController' && ($f['function'] ?? '') === 'forgotPassword') {
                return 'password_reset';
            }
            return $map[$cls] ?? substr($cls, 0, 60);
        }
        return null;
    }

    private function deliver(string $toEmail, string $toName, string $subject, string $htmlBody, array $extraHeaders = []): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'إعدادات البريد (MAIL_USERNAME/MAIL_PASSWORD في .env) لسه مش متظبطة'];
        }

        $socketHost = ($this->encryption === 'ssl' ? 'ssl://' : '') . $this->host;

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client("{$socketHost}:{$this->port}", $errno, $errstr, $this->timeout);

        if (!$socket) {
            return ['success' => false, 'error' => "تعذر الاتصال بسيرفر البريد ({$errstr})"];
        }

        try {
            $this->expect($socket, '220');
            $this->command($socket, "EHLO " . ($_SERVER['SERVER_NAME'] ?? 'tourfecto.pro'), '250');

            if ($this->encryption === 'tls') {
                $this->command($socket, "STARTTLS", '220');
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new Exception('فشل تفعيل TLS مع سيرفر البريد');
                }
                $this->command($socket, "EHLO " . ($_SERVER['SERVER_NAME'] ?? 'tourfecto.pro'), '250');
            }

            $this->command($socket, "AUTH LOGIN", '334');
            $this->command($socket, base64_encode($this->username), '334');
            $this->command($socket, base64_encode($this->password), '235');

            $this->command($socket, "MAIL FROM:<{$this->fromEmail}>", '250');
            $this->command($socket, "RCPT TO:<{$toEmail}>", '250');
            $this->command($socket, "DATA", '354');

            $headers = $this->compileHeaders($toEmail, $toName, $subject, $extraHeaders);

            // كل سطر يبدأ بنقطة لازم يتضاعف (SMTP dot-stuffing)
            $escapedBody = preg_replace('/^\./m', '..', $htmlBody);

            $this->rawWrite($socket, $headers . "\r\n" . $escapedBody . "\r\n.\r\n");
            $this->expect($socket, '250');

            $this->rawWrite($socket, "QUIT\r\n");
            fclose($socket);

            return ['success' => true];
        } catch (Exception $e) {
            @fclose($socket);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** بريد المرسل الفعلي كما سيستخدمه السيرفر (مهم لاشتقاق دومين List-Unsubscribe). */
    public function getFromEmail(): string
    {
        return $this->fromEmail;
    }

    /**
     * بناء هيدرز الرسالة كاملة (Date/From/To/Subject + إضافية).
     * protected عشان الاختبارات تقدر تتأكد من المحتوى من غير socket.
     * @param array<string,string> $extraHeaders
     */
    protected function compileHeaders(string $toEmail, string $toName, string $subject, array $extraHeaders = []): string
    {
        $boundaryDate = date('r');
        $fromNameEncoded = '=?UTF-8?B?' . base64_encode($this->fromName) . '?=';
        $toNameEncoded = '=?UTF-8?B?' . base64_encode($toName) . '?=';
        $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $headers = "Date: {$boundaryDate}\r\n"
            . "From: {$fromNameEncoded} <{$this->fromEmail}>\r\n"
            . "To: {$toNameEncoded} <{$toEmail}>\r\n"
            . "Subject: {$subjectEncoded}\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n";

        foreach ($extraHeaders as $name => $value) {
            $safeName = preg_replace('/[^A-Za-z0-9\-]/', '', (string) $name);
            $safeValue = str_replace(["\r", "\n"], '', (string) $value);
            if ($safeName !== '') {
                $headers .= $safeName . ': ' . $safeValue . "\r\n";
            }
        }

        return $headers;
    }

    private function rawWrite($socket, string $data): void
    {
        fwrite($socket, $data);
    }

    private function command($socket, string $cmd, string $expectedCode): string
    {
        fwrite($socket, $cmd . "\r\n");
        return $this->expect($socket, $expectedCode);
    }

    private function expect($socket, string $expectedCode): string
    {
        $response = '';
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            // آخر سطر في رد SMTP بيكون فيه مسافة بعد الكود (مش شرطة -)
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        if (strpos($response, $expectedCode) !== 0) {
            throw new Exception("رد غير متوقع من سيرفر البريد: {$response}");
        }

        return $response;
    }
}
