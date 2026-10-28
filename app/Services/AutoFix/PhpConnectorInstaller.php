<?php

/**
 * Tourfecto - تركيب ملف الربط على مواقع PHP بدل العميل
 *
 * العميل مش مفروض يفتح ملفات ويكتب أكواد. بيدخل بيانات FTP بتاعة
 * الاستضافة (مرة واحدة - مش بتتحفظ)، والمنصة:
 *   1. بتلاقي فولدر الموقع (اللي فيه index.php)
 *   2. بترفع tourfecto-connect.php
 *   3. بتضيف سطر require_once في أول index.php (بعد ما تاخد نسخة احتياطية)
 *   4. بتفتح الموقع وتتأكد إنه شغال وإن الربط اشتغل
 *   5. لو الموقع وقع أو الربط مااشتغلش: بترجّع index.php زي ما كان
 */
class PhpConnectorInstaller
{
    public const LINE = "require_once __DIR__ . '/tourfecto-connect.php';";
    private const MARKER = 'Tourfecto Connect v4';

    /** @var object FTP (connect/login/exists/get/put/delete/close) - بيتبدل في الاختبارات */
    private $ftp;
    /** @var callable(string):array{status:int,body:string} */
    private $http;

    /** @var int[] ثواني الانتظار قبل كل محاولة فحص */
    public $waits = [0, 3, 6, 10];

    public function __construct(?object $ftp = null, ?callable $http = null)
    {
        $this->ftp = $ftp ?? new PhpConnectorFtp();
        $this->http = $http ?? [self::class, 'httpGet'];
    }

    /**
     * يضيف سطر الربط في أول ملف PHP. null = مش آمن نعدّل الملف ده تلقائيًا.
     * لو السطر موجود أصلًا بيرجّع نفس المحتوى.
     */
    public static function injectRequire(string $php): ?string
    {
        if (strpos($php, 'tourfecto-connect.php') !== false) {
            return $php;
        }
        $bom = strncmp($php, "\xEF\xBB\xBF", 3) === 0 ? "\xEF\xBB\xBF" : '';
        $body = substr($php, strlen($bom));
        if (!preg_match('/^<\?php\b/i', $body)) {
            if (strncmp($body, '<?', 2) === 0 && strncmp($body, '<?=', 3) !== 0) {
                return null; // short tags: مش هنخاطر
            }
            // الملف بيبدأ بـ HTML: نحط السطر قبله من غير أي إخراج (PHP بيتجاهل السطر الجديد اللي بعد علامة الإغلاق)
            return $bom . '<?php ' . self::LINE . " ?>\n" . $body;
        }
        $after = substr($body, 5);
        // declare(strict_types=1) لازم يفضل أول جملة
        if (preg_match('/^(\s*declare\s*\([^)]*\)\s*;)/i', $after, $m)) {
            return $bom . '<?php' . $m[1] . "\n" . self::LINE . substr($after, strlen($m[1]));
        }
        if (preg_match('/^\s*namespace\b/i', $after)) {
            if (!preg_match('/^(\s*namespace\s+[A-Za-z0-9_\\\\]+\s*;)/', $after, $m)) {
                return null; // namespace { ... } بالأقواس
            }
            return $bom . '<?php' . $m[1] . "\n" . self::LINE . substr($after, strlen($m[1]));
        }
        $rest = ltrim($after, " \t");
        return $bom . '<?php ' . self::LINE . (preg_match('/^\r?\n/', $rest) ? '' : "\n") . $rest;
    }

    /** فولدر الموقع: أول مكان فيه index.php */
    public function findRoot(string $host, string $hint = ''): ?string
    {
        $host = strtolower(preg_replace('/^www\./i', '', $host));
        $candidates = array_values(array_unique(array_filter([
            trim($hint, '/'), '', 'public_html', 'htdocs', 'www', 'httpdocs', 'html', 'web',
            $host, 'www.' . $host, 'domains/' . $host . '/public_html', 'wwwroot/' . $host, 'www/wwwroot/' . $host,
        ], static fn ($c) => $c !== null)));
        foreach ($candidates as $dir) {
            if ($this->ftp->exists(($dir === '' ? '' : $dir . '/') . 'index.php')) {
                return $dir;
            }
        }
        return null;
    }

    /**
     * @return array{ok:bool, message:string, root?:string}
     */
    public function install(array $cred, string $siteUrl, string $connectorCode): array
    {
        $siteUrl = rtrim($siteUrl, '/');
        $host = (string) parse_url($siteUrl, PHP_URL_HOST);
        if ($host === '') {
            return ['ok' => false, 'message' => 'رابط موقعك مش مظبوط في المنصة.'];
        }
        $before = ($this->http)($siteUrl . '/');
        $err = $this->ftp->connect((string) $cred['host'], (int) ($cred['port'] ?? 21), (string) $cred['username'], (string) $cred['password']);
        if ($err !== null) {
            return ['ok' => false, 'message' => $err];
        }
        try {
            $root = $this->findRoot($host, (string) ($cred['path'] ?? ''));
            if ($root === null) {
                return ['ok' => false, 'message' => 'دخلنا على الاستضافة بس مالقيناش ملف index.php بتاع موقعك. لو موقعك في فولدر معيّن اكتب اسمه في خانة "فولدر الموقع".'];
            }
            $prefix = $root === '' ? '' : $root . '/';
            if (!$this->ftp->put($prefix . 'tourfecto-connect.php', $connectorCode)) {
                return ['ok' => false, 'message' => 'مقدرناش نرفع ملف الربط - اتأكد إن حساب FTP ده عنده صلاحية كتابة على فولدر الموقع.'];
            }
            $original = $this->ftp->get($prefix . 'index.php');
            if ($original === null) {
                return ['ok' => false, 'message' => 'مقدرناش نقرا ملف index.php.'];
            }
            $patched = self::injectRequire($original);
            if ($patched === null) {
                return ['ok' => false, 'message' => 'ملف index.php بتاع موقعك مكتوب بطريقة خاصة ومش آمن نعدّله تلقائيًا - ابعت لمطوّر موقعك الملف والسطر من "الطريقة اليدوية".'];
            }
            if ($patched !== $original) {
                $this->ftp->put($prefix . 'index.php.tourfecto-backup', $original);
                if (!$this->ftp->put($prefix . 'index.php', $patched)) {
                    return ['ok' => false, 'message' => 'مقدرناش نعدّل index.php - اتأكد إن حساب FTP عنده صلاحية كتابة.'];
                }
            }
            // السيرفرات بتحتفظ بنسخة من ملفات PHP في الذاكرة (opcache) لثواني -
            // فبنعيد الفحص كام مرة قبل ما نحكم
            $check = ['status' => 0, 'body' => ''];
            foreach ($this->waits as $wait) {
                if ($wait > 0) {
                    sleep($wait);
                }
                $check = ($this->http)($siteUrl . '/?tf_check=1&_=' . mt_rand());
                if (strpos((string) $check['body'], self::MARKER) !== false) {
                    break;
                }
            }
            $after = ($this->http)($siteUrl . '/');
            $siteOk = $after['status'] >= 200 && $after['status'] < 400 || ($before['status'] ?? 0) === $after['status'];
            if (strpos((string) $check['body'], self::MARKER) !== false && $siteOk) {
                return ['ok' => true, 'message' => 'الربط اتركّب واشتغل. موقعك شغال عادي.', 'root' => $root];
            }
            if ($patched !== $original) {
                $this->ftp->put($prefix . 'index.php', $original); // نرجّع الموقع زي ما كان
            }
            return ['ok' => false, 'message' => $siteOk
                ? 'رفعنا الملف بس موقعك مش بيستخدم index.php للصفحة الرئيسية، فرجّعنا كل حاجة زي ما كانت. ابعت لمطوّر موقعك الملف والسطر من "الطريقة اليدوية".'
                : 'الموقع ماردّش صح بعد التعديل، فرجّعنا index.php زي ما كان فورًا. موقعك شغال عادي.'];
        } finally {
            $this->ftp->close();
        }
    }

    /** @return array{status:int, body:string} */
    public static function httpGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'TourfectoConnectInstaller/1.0', CURLOPT_HTTPHEADER => ['Cache-Control: no-cache']]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => is_string($body) ? substr($body, 0, 200000) : ''];
    }

    /** مفيش FTP على عناوين داخلية (حماية السيرفر من الاستخدام كوسيط) */
    public static function publicHost(string $host): bool
    {
        if (!preg_match('/^[A-Za-z0-9.\-]{1,253}$/', $host)) {
            return false;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (!$ips) {
            return false;
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }
        return true;
    }
}

/** غلاف بسيط لدوال FTP في PHP (FTPS لو متاح، وبعدين FTP عادي) */
class PhpConnectorFtp
{
    private $conn = null;

    public function connect(string $host, int $port, string $user, string $pass): ?string
    {
        if (!function_exists('ftp_connect')) {
            return 'التركيب التلقائي مش متاح على السيرفر حاليًا (FTP). استخدم الطريقة اليدوية أو كلّم الدعم.';
        }
        $port = $port > 0 ? $port : 21;
        foreach (['ftp_ssl_connect', 'ftp_connect'] as $fn) {
            if (!function_exists($fn)) {
                continue;
            }
            $c = @$fn($host, $port, 15);
            if (!$c) {
                continue;
            }
            if (@ftp_login($c, $user, $pass)) {
                @ftp_pasv($c, true);
                $this->conn = $c;
                return null;
            }
            @ftp_close($c);
            if ($fn === 'ftp_connect') {
                return 'اسم المستخدم أو كلمة السر بتاعة FTP غلط.';
            }
        }
        return 'مقدرناش نوصل لسيرفر الـ FTP. اتأكد من عنوان الـ FTP (غالبًا اسم موقعك أو ftp.اسم-موقعك).';
    }

    public function exists(string $path): bool
    {
        return $this->conn && @ftp_size($this->conn, $path) > -1;
    }

    public function get(string $path): ?string
    {
        if (!$this->conn) {
            return null;
        }
        $h = fopen('php://temp', 'r+');
        $ok = @ftp_fget($this->conn, $h, $path, FTP_BINARY);
        rewind($h);
        $data = $ok ? stream_get_contents($h) : null;
        fclose($h);
        return $data === false ? null : $data;
    }

    public function put(string $path, string $data): bool
    {
        if (!$this->conn) {
            return false;
        }
        $h = fopen('php://temp', 'r+');
        fwrite($h, $data);
        rewind($h);
        $ok = @ftp_fput($this->conn, $path, $h, FTP_BINARY);
        fclose($h);
        return (bool) $ok;
    }

    public function close(): void
    {
        if ($this->conn) {
            @ftp_close($this->conn);
            $this->conn = null;
        }
    }
}
