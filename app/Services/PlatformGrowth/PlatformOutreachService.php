<?php

require_once __DIR__ . '/OutreachNeeds.php';
require_once __DIR__ . '/OutreachAudit.php';

/**
 * Tourfecto - تسويق المنصة نفسها لشركات السياحة (من لوحة الأدمن)
 *
 * - استيراد شركات من Apify (Google Maps وغيره) أو JSON/CSV، من غير تكرار
 * - لو الشركة مالهاش إيميل: بندوّر عليه في موقعها (صفحة التواصل)
 * - سلسلة من 3 رسايل (تعارف ← تحليل مجاني ← رسالة أخيرة) مخصصة لكل شركة
 *   باسمها ومدينتها وتقييمها على جوجل، وبالعربي أو الإنجليزي حسب البلد
 * - حماية سمعة الإيميل: حد يومي بيزيد تدريجيًا، مواعيد عمل، لينك إلغاء اشتراك
 *   في كل رسالة، وقف تلقائي لو الإيميلات الراجعة كترت
 * - الشركة اللي تسجّل في المنصة بتقف رسايلها لوحدها
 */
class PlatformOutreachService
{
    public const STATUSES = ['new', 'no_email', 'queued', 'contacted', 'finished', 'replied', 'interested', 'signed_up', 'unsubscribed', 'bounced', 'invalid', 'paused'];
    private const STOP = ['replied', 'interested', 'signed_up', 'unsubscribed', 'bounced', 'invalid', 'paused', 'finished'];
    private const ARAB = ['EG', 'SA', 'AE', 'KW', 'QA', 'BH', 'OM', 'JO', 'LB', 'IQ', 'SY', 'PS', 'YE', 'LY', 'TN', 'DZ', 'MA', 'SD', 'MR'];
    private const BAD_LOCAL = '/^(no-?reply|donotreply|do-not-reply|mailer-daemon|postmaster|abuse|webmaster|privacy|gdpr|example|test|user|email|your|name)$/i';
    private const BAD_DOMAIN = '/(example\.(com|org)|sentry\.io|wixpress\.com|sentry-next|domain\.com|email\.com|yourdomain|godaddy\.com|\.png|\.jpe?g|\.gif|\.webp|\.svg)$/i';

    private PDO $pdo;
    private SystemSettingsService $settings;
    /** @var callable(string,int):?array{body:string,url:string} */
    private $fetch;

    /** بنتأكد إن دومين الإيميل بيستقبل بريد قبل الإرسال (بيتقفل في الاختبارات) */
    public bool $checkMx = true;

    /** @var callable(string):string 'down' | 'blocked' | 'error' (لما الصفحة مارجعتش) */
    public $probe = [self::class, 'siteStatus'];

    /** @var callable(string,string):?array نتيجة Places Find Place (null = ماقدرناش نتأكد) */
    public $places = [self::class, 'placesFind'];

    public function __construct(?PDO $pdo = null, ?SystemSettingsService $settings = null, ?callable $fetch = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        $this->settings = $settings ?? new SystemSettingsService();
        $this->fetch = $fetch ?? [self::class, 'httpFetch'];
        $this->ensureSchema();
    }

    private function q(string $sql, array $args = []): PDOStatement
    {
        $s = $this->pdo->prepare($sql);
        $s->execute($args);
        return $s;
    }

    /** الجداول بتتعمل لوحدها أول مرة (من غير ما الأدمن يشغّل SQL) */
    private function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS platform_leads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            source VARCHAR(40) NOT NULL DEFAULT 'import',
            company VARCHAR(255) NOT NULL DEFAULT '',
            email VARCHAR(255) NULL,
            phone VARCHAR(80) NULL,
            website VARCHAR(500) NULL,
            domain VARCHAR(255) NULL,
            city VARCHAR(120) NULL,
            country VARCHAR(8) NULL,
            category VARCHAR(255) NULL,
            rating DECIMAL(3,2) NULL,
            reviews INT NULL,
            maps_url VARCHAR(700) NULL,
            language VARCHAR(5) NOT NULL DEFAULT 'en',
            status VARCHAR(20) NOT NULL DEFAULT 'new',
            step TINYINT NOT NULL DEFAULT 0,
            next_send_at DATETIME NULL,
            last_sent_at DATETIME NULL,
            opened_at DATETIME NULL,
            clicked_at DATETIME NULL,
            enrich_tries TINYINT NOT NULL DEFAULT 0,
            token CHAR(32) NOT NULL,
            notes TEXT NULL,
            data_json TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_pl_email (email),
            UNIQUE KEY uq_pl_token (token),
            KEY idx_pl_status (status, next_send_at),
            KEY idx_pl_domain (domain)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS platform_lead_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lead_id INT NOT NULL,
            step TINYINT NOT NULL,
            subject VARCHAR(255) NOT NULL,
            status VARCHAR(12) NOT NULL,
            error VARCHAR(500) NULL,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            opened_at DATETIME NULL,
            clicked_at DATETIME NULL,
            KEY idx_plm_lead (lead_id),
            KEY idx_plm_sent (sent_at)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // مسارات الاحتياج (OutreachNeeds): كل شركة ليها مسار أساسي وتاني، وموافقة الأدمن قبل أول رسالة
        $this->addColumns('platform_leads', [
            'track' => 'VARCHAR(30) NULL',
            'track2' => 'VARCHAR(30) NULL',
            'phase' => 'TINYINT NOT NULL DEFAULT 1',
            'needs_json' => 'TEXT NULL',
            'scanned_at' => 'DATETIME NULL',
            'approved' => 'TINYINT NOT NULL DEFAULT 0',
            'report_viewed_at' => 'DATETIME NULL',
            'whatsapp_at' => 'DATETIME NULL',
            'score' => 'TINYINT NULL',
        ]);
        $this->addColumns('platform_lead_messages', ['track' => 'VARCHAR(30) NULL']);
        $done = true;
    }

    private function addColumns(string $table, array $cols): void
    {
        try {
            $have = $this->q('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table])->fetchAll(PDO::FETCH_COLUMN);
            foreach ($cols as $name => $def) {
                if (!in_array($name, $have, true)) {
                    $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$name} {$def}");
                }
            }
        } catch (Throwable $e) {
            Logger::warning('Platform outreach schema update failed', ['table' => $table, 'error' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------ الإعدادات

    public static function defaults(): array
    {
        return [
            'enabled' => '0',
            'daily_limit' => '40',
            'per_run' => '3',
            'hour_from' => '9',
            'hour_to' => '17',
            'skip_days' => '5', // الجمعة (0=الأحد)
            'timezone' => 'Africa/Cairo',
            'step_gaps' => '3,4',
            'sender_name' => 'Tourfecto',
            'sender_title' => '',
            'from_email' => '',
            'reply_to' => '',
            'smtp_host' => '',
            'smtp_port' => '587',
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_encryption' => 'tls',
            'signup_url' => (defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://tourfecto.pro') . '/register',
            'company_address' => 'Tourfecto · Cairo, Egypt',
            'ai_opener' => '1',
            'track_opens' => '0',
            'source_url' => '',
            'first_send_date' => '',
            'templates' => '',
            // رقم واتساب التسويق (أرقام بس مع كود الدولة): الشركة هي اللي بتبدأ المحادثة من زرار في الإيميل أو التقرير
            'whatsapp_number' => '',
            // أول رسالة لكل شركة مابتطلعش غير لما الأدمن يوافق عليها
            'require_approval' => '1',
            // بعد ما مسار الاحتياج الأول يخلص من غير رد: كام يوم ونبدأ مسار الاحتياج التاني
            'phase2_gap' => '14',
            // صورة المرسل في التوقيع (رابط https لصورة مربعة) واللوجو في الهيدر - فاضي = لوجو المنصة
            'sender_photo_url' => '',
            'brand_logo_url' => '',
        ];
    }

    public function getSettings(bool $forAdmin = false): array
    {
        $out = [];
        foreach (self::defaults() as $k => $v) {
            $out[$k] = $this->settings->get('outreach_' . $k, $v);
        }
        if ($out['smtp_password'] !== '') {
            try {
                $out['smtp_password'] = (new Encryption())->decrypt($out['smtp_password'], 'platform-outreach');
            } catch (Throwable $e) {
                $out['smtp_password'] = '';
            }
        }
        $tpl = json_decode($out['templates'], true);
        $out['templates'] = is_array($tpl) && $tpl ? array_replace_recursive(self::defaultTemplates(), $tpl) : self::defaultTemplates();
        if ($forAdmin) {
            $out['smtp_password_set'] = $out['smtp_password'] !== '';
            $out['smtp_password'] = '';
        }
        return $out;
    }

    public function saveSettings(array $in): array
    {
        $d = self::defaults();
        foreach ($in as $k => $v) {
            if (!array_key_exists($k, $d) || in_array($k, ['first_send_date'], true)) {
                continue;
            }
            if ($k === 'templates') {
                $v = json_encode(self::cleanTemplates($v), JSON_UNESCAPED_UNICODE);
            } elseif ($k === 'smtp_password') {
                if (!is_string($v) || $v === '') {
                    continue; // فاضي = سيب القديم
                }
                $v = (new Encryption())->encrypt($v, 'platform-outreach');
            } else {
                $v = mb_substr(trim((string) (is_bool($v) ? (int) $v : $v)), 0, 500);
            }
            if (in_array($k, ['from_email', 'reply_to'], true) && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('إيميل المرسل مش مظبوط');
            }
            if ($k === 'daily_limit') {
                $v = (string) max(1, min(300, (int) $v));
            }
            if ($k === 'whatsapp_number') {
                $v = preg_replace('/\D/', '', (string) $v);
                if ($v !== '' && (strlen($v) < 8 || strlen($v) > 15)) {
                    throw new InvalidArgumentException('رقم الواتساب لازم يكون بكود الدولة، أرقام بس (مثلًا 201142775856)');
                }
            }
            if ($k === 'phase2_gap') {
                $v = (string) max(3, min(60, (int) $v));
            }
            if (in_array($k, ['sender_photo_url', 'brand_logo_url'], true) && $v !== '' && !preg_match('#^https://[^\s"\'<>]+$#i', (string) $v)) {
                throw new InvalidArgumentException('رابط الصورة لازم يبدأ بـ https://');
            }
            if (in_array($k, ['hour_from', 'hour_to'], true)) {
                $v = (string) max(0, min(23, (int) $v));
            }
            $this->settings->set('outreach_' . $k, (string) $v);
        }
        return $this->getSettings(true);
    }

    private static function cleanTemplates($v): array
    {
        $out = self::defaultTemplates();
        if (!is_array($v)) {
            return $out;
        }
        foreach (['ar', 'en'] as $lang) {
            foreach ([1, 2, 3] as $step) {
                $t = $v[$lang][$step] ?? $v[$lang][(string) $step] ?? null;
                if (is_array($t) && is_string($t['subject'] ?? null) && is_string($t['body'] ?? null) && trim($t['subject']) !== '' && trim($t['body']) !== '') {
                    $out[$lang][$step] = ['subject' => mb_substr(trim($t['subject']), 0, 200), 'body' => mb_substr(trim($t['body']), 0, 5000)];
                }
            }
        }
        return $out;
    }

    /** الرسايل الافتراضية: قصيرة وشخصية ودعوة واحدة واضحة */
    public static function defaultTemplates(): array
    {
        return [
            'ar' => [
                1 => ['subject' => '{{company}}: فكرة سريعة لحجوزات أكتر', 'body' => "أهلًا فريق {{company}}،\n\n{{opener}}\n\nأنا {{sender_name}} من Tourfecto، منصة ذكاء اصطناعي معمولة مخصوص لشركات السياحة. بتاخد منكم الشغل اليومي اللي بياكل الوقت:\n\n• ترد على رسائل العملاء في واتساب وفيسبوك وإنستجرام 24 ساعة\n• تكتب مقالات وتظبط موقعكم عشان يظهر أعلى في جوجل\n• تعمل خطة سوشيال شهرية بصوركم ولوجو شركتكم وتنشرها لوحدها\n• ترد على تقييمات جوجل باحترافية\n\nتحبوا تجربوها على {{company}}؟ التسجيل بياخد دقيقتين:\n{{signup_link}}\n\nتحياتي،\n{{sender_name}}{{sender_title_line}}"],
                2 => ['subject' => 'تحليل مجاني لموقع {{company}}', 'body' => "أهلًا تاني،\n\nحبيت أكمّل على رسالتي اللي فاتت بحاجة مفيدة ليكم حتى لو مااشتركتوش:\n\nTourfecto بتعمل تحليل مجاني لموقع {{website_or_company}} في دقيقة: إيه اللي موقفكم في جوجل، والمنافسين بيعملوا إيه، وإزاي تجيبوا حجوزات أكتر من الموقع.\n\nسجّلوا وشغّلوا التحليل بنفسكم:\n{{signup_link}}\n\nأو ردّوا على الإيميل ده بكلمة \"موافق\" وأنا أبعتلكم النتيجة.\n\n{{sender_name}}"],
                3 => ['subject' => 'آخر رسالة مني ({{company}})', 'body' => "أهلًا،\n\nمش عاوز أزحم الإيميل عندكم، فدي آخر رسالة.\n\nلو في وقت تاني حابين تخلّوا الرد على العملاء والتسويق يشتغل لوحده، Tourfecto موجودة وتقدروا تبدأوا من هنا:\n{{signup_link}}\n\nبالتوفيق لـ {{company}}،\n{{sender_name}}"],
            ],
            'en' => [
                1 => ['subject' => '{{company}}: a quick idea for more bookings', 'body' => "Hi {{company}} team,\n\n{{opener}}\n\nI'm {{sender_name}} from Tourfecto, an AI platform built for tour and travel companies. It takes the daily busywork off your plate:\n\n• Replies to customer messages on WhatsApp, Facebook and Instagram 24/7\n• Writes articles and fixes your website SEO so you rank higher on Google\n• Builds a monthly social plan with your own photos and logo, and posts it for you\n• Answers your Google reviews professionally\n\nWould you like to try it for {{company}}? Signing up takes two minutes:\n{{signup_link}}\n\nBest regards,\n{{sender_name}}{{sender_title_line}}"],
                2 => ['subject' => 'Free website check for {{company}}', 'body' => "Hi again,\n\nFollowing up with something useful even if you don't sign up:\n\nTourfecto runs a free one-minute analysis of {{website_or_company}}: what is holding you back on Google, what competitors do better, and how to get more bookings from your site.\n\nRun it yourself here:\n{{signup_link}}\n\nOr just reply \"yes\" and I'll send you the results.\n\n{{sender_name}}"],
                3 => ['subject' => 'Closing the loop ({{company}})', 'body' => "Hi,\n\nI don't want to crowd your inbox, so this is my last note.\n\nIf you ever want customer replies and marketing to run on autopilot, Tourfecto is here:\n{{signup_link}}\n\nWishing {{company}} a great season,\n{{sender_name}}"],
            ],
        ];
    }

    // ------------------------------------------------------------------ الاستيراد

    /** @return array{added:int, updated:int, skipped:int, no_email:int, total:int} */
    public function importFromUrl(string $url): array
    {
        if (!preg_match('#^https://#i', $url)) {
            throw new InvalidArgumentException('الرابط لازم يبدأ بـ https://');
        }
        $u = $url;
        if (preg_match('#api\.apify\.com/v2/datasets/#i', $u)) {
            if (!preg_match('/[?&]format=/', $u)) {
                $u .= (strpos($u, '?') === false ? '?' : '&') . 'format=json';
            }
            if (!preg_match('/[?&]clean=/', $u)) {
                $u .= '&clean=true';
            }
        }
        $r = ($this->fetch)($u, 60000000);
        if (!$r) {
            throw new RuntimeException('مقدرناش نفتح الرابط. اتأكد إن الرابط شغال (ولو من Apify اتأكد إن الـ signature لسه صالحة).');
        }
        $rows = json_decode($r['body'], true);
        if (!is_array($rows)) {
            throw new InvalidArgumentException('الرابط مارجّعش بيانات JSON');
        }
        $this->settings->set('outreach_source_url', $url);
        return $this->importRows(array_values($rows), preg_match('/apify/i', $url) ? 'apify' : 'import');
    }

    /** JSON أو CSV متلزق */
    public function importText(string $text): array
    {
        $text = trim($text);
        $rows = json_decode($text, true);
        if (!is_array($rows)) {
            $lines = preg_split('/\r?\n/', $text);
            $head = str_getcsv(array_shift($lines));
            $rows = [];
            foreach ($lines as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $vals = str_getcsv($line);
                $rows[] = array_combine(array_map('strtolower', array_map('trim', $head)), array_pad(array_slice($vals, 0, count($head)), count($head), ''));
            }
        }
        return $this->importRows(array_values($rows), 'import');
    }

    public function importRows(array $rows, string $source): array
    {
        $stats = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'no_email' => 0, 'total' => count($rows)];
        $customers = $this->customerKeys();
        // شركات اتحذفت بطلبها (AI CEO → قاعدة شركات السياحة): ماتتستوردش تاني
        $suppress = null;
        try { $suppress = class_exists('TourismBusinessDb') ? new TourismBusinessDb() : null; } catch (Throwable $e) { $suppress = null; }
        foreach (array_slice($rows, 0, 20000) as $row) {
            if (!is_array($row)) {
                $stats['skipped']++;
                continue;
            }
            $lead = self::mapRow($row);
            if ($lead['company'] === '' && $lead['email'] === null && $lead['website'] === null) {
                $stats['skipped']++;
                continue;
            }
            // معالم ومتاحف وجوامع من TripAdvisor: مش هتشتري أداة تسويق
            if (!empty($lead['extra']['ta']) && class_exists('OutreachNeeds') && !OutreachNeeds::isSeller($lead['extra']['ta'], $lead['category'])) {
                $stats['skipped']++;
                continue;
            }
            if ($suppress && $suppress->isSuppressed($lead['email'], $lead['domain'], $lead['phone'])) {
                $stats['skipped']++;
                continue;
            }
            // عملاء المنصة الحاليين مابنبعتلهمش تسويق
            if (($lead['email'] && isset($customers['e:' . strtolower($lead['email'])])) || ($lead['domain'] && isset($customers['d:' . $lead['domain']]))) {
                $stats['skipped']++;
                continue;
            }
            $existing = null;
            if ($lead['email']) {
                $existing = $this->q('SELECT id,status,step,data_json FROM platform_leads WHERE email=?', [$lead['email']])->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$existing && $lead['domain']) {
                $existing = $this->q('SELECT id,status,email,step,data_json FROM platform_leads WHERE domain=? LIMIT 1', [$lead['domain']])->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($existing && $lead['email'] && !empty($existing['email']) && strcasecmp($existing['email'], $lead['email']) !== 0) {
                    $existing = null; // نفس الشركة بإيميل تاني: نضيفه (بنقفل الرسايل لو حد منهم ردّ)
                }
            }
            if ($existing) {
                // بنكمّل البيانات الناقصة بس، من غير ما نلمس الحالة
                $this->q('UPDATE platform_leads SET email=COALESCE(email,?),phone=COALESCE(phone,?),website=COALESCE(website,?),domain=COALESCE(domain,?),city=COALESCE(city,?),country=COALESCE(country,?),category=COALESCE(category,?),rating=COALESCE(rating,?),reviews=COALESCE(reviews,?),maps_url=COALESCE(maps_url,?),status=IF(status=\'no_email\' AND ? IS NOT NULL,\'new\',status) WHERE id=?', [
                    $lead['email'], $lead['phone'], $lead['website'], $lead['domain'], $lead['city'], $lead['country'], $lead['category'], $lead['rating'], $lead['reviews'], $lead['maps_url'], $lead['email'], $existing['id'],
                ]);
                // بيانات TripAdvisor جديدة لشركة لسه مااتبعتلهاش: نحفظها ونعيد تشخيص احتياجها
                if (!empty($lead['extra']['ta'])) {
                    $data = json_decode((string) ($existing['data_json'] ?? ''), true) ?: [];
                    $data['ta'] = $lead['extra']['ta'];
                    $this->q('UPDATE platform_leads SET data_json=?' . ((int) ($existing['step'] ?? 0) === 0 ? ',scanned_at=NULL' : '') . ' WHERE id=?', [json_encode($data, JSON_UNESCAPED_UNICODE), $existing['id']]);
                }
                $stats['updated']++;
                continue;
            }
            $status = $lead['email'] ? 'new' : 'no_email';
            if (!$lead['email']) {
                $stats['no_email']++;
            }
            $this->q('INSERT INTO platform_leads(source,company,email,phone,website,domain,city,country,category,rating,reviews,maps_url,language,status,token,data_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                $source, $lead['company'], $lead['email'], $lead['phone'], $lead['website'], $lead['domain'], $lead['city'], $lead['country'], $lead['category'], $lead['rating'], $lead['reviews'], $lead['maps_url'], $lead['language'], $status, bin2hex(random_bytes(16)), json_encode($lead['extra'], JSON_UNESCAPED_UNICODE),
            ]);
            $stats['added']++;
        }
        return $stats;
    }

    /** أعمدة Apify (Google Maps / Contact scraper) أو أي جدول عادي */
    public static function mapRow(array $r): array
    {
        $pick = static function (array $keys) use ($r) {
            foreach ($keys as $k) {
                if (isset($r[$k]) && is_scalar($r[$k]) && trim((string) $r[$k]) !== '') {
                    return trim((string) $r[$k]);
                }
            }
            return null;
        };
        $emails = [];
        foreach ($r as $k => $v) {
            if (stripos((string) $k, 'email') === false) {
                continue;
            }
            foreach ((array) $v as $e) {
                if (is_string($e)) {
                    $emails[] = $e;
                } elseif (is_array($e) && is_string($e['value'] ?? $e['email'] ?? null)) {
                    $emails[] = $e['value'] ?? $e['email'];
                }
            }
        }
        $website = $pick(['website', 'site', 'url_website', 'domain', 'web', 'Website', 'الموقع']);
        if ($website && !preg_match('#^https?://#i', $website)) {
            $website = 'https://' . $website;
        }
        if ($website && preg_match('#(google\.[a-z.]+/maps|facebook\.com|instagram\.com|wa\.me|tripadvisor\.)#i', $website)) {
            $website = null; // مش موقع الشركة
        }
        $domain = $website ? strtolower(preg_replace('/^www\./i', '', (string) parse_url($website, PHP_URL_HOST))) : null;
        $email = self::bestEmail($emails, $domain);
        // TripAdvisor (Apify): الدولة والمدينة جوه addressObj، والتقييمات السلبية والبيع عن طريق Viator في الحقول دي
        $addr = is_array($r['addressObj'] ?? null) ? $r['addressObj'] : [];
        $ta = class_exists('OutreachNeeds') ? OutreachNeeds::tripAdvisorFacts($r) : [];
        $countryRaw = (string) ($pick(['countryCode', 'country_code', 'country']) ?? ($addr['country'] ?? ''));
        $country = strtoupper($countryRaw);
        $country = preg_match('/^[A-Z]{2}$/', $country) ? $country : self::countryCode($countryRaw);
        if (!$country && is_array($r['ancestorLocations'] ?? null)) {
            foreach ($r['ancestorLocations'] as $a) {
                if (is_array($a) && ($a['subcategory'] ?? '') === 'Country' && is_string($a['name'] ?? null)) {
                    $country = self::countryCode($a['name']);
                }
            }
        }
        $phone = $pick(['phone', 'phoneUnformatted', 'phone_number', 'phones', 'الهاتف']);
        if (!$phone && is_array($r['phones'] ?? null) && is_string($r['phones'][0] ?? null)) {
            $phone = $r['phones'][0];
        }
        $country = $country ?: self::guessCountry($phone, $website ? (string) parse_url($website, PHP_URL_HOST) : null);
        $category = !empty($ta['subcategories']) ? implode(', ', array_slice($ta['subcategories'], 0, 3)) : $pick(['categoryName', 'category', 'type']);
        if (!$category && is_array($r['categories'] ?? null)) {
            $category = implode(', ', array_slice(array_filter($r['categories'], 'is_string'), 0, 3));
        }
        $rating = $pick(['totalScore', 'rating', 'stars']);
        $reviews = $pick(['reviewsCount', 'numberOfReviews', 'reviews', 'userRatingsTotal']);
        $company = (string) ($pick(['title', 'name', 'company', 'companyName', 'business_name', 'الاسم', 'الشركة']) ?? '');
        $arabicName = preg_match('/\p{Arabic}/u', $company) === 1;
        return [
            'company' => mb_substr($company, 0, 255),
            'email' => $email,
            'phone' => $phone ? mb_substr($phone, 0, 80) : null,
            'website' => $website ? mb_substr($website, 0, 500) : null,
            'domain' => $domain ?: null,
            'city' => ($c = $pick(['city', 'neighborhood', 'state', 'المدينة']) ?? (is_string($addr['city'] ?? null) ? $addr['city'] : null)) ? mb_substr($c, 0, 120) : null,
            'country' => $country,
            'category' => $category ? mb_substr($category, 0, 255) : null,
            'rating' => is_numeric($rating) && $rating > 0 ? round((float) $rating, 2) : null,
            'reviews' => is_numeric($reviews) ? (int) $reviews : null,
            'maps_url' => ($m = $pick(['url', 'placeUrl', 'googleMapsUrl', 'webUrl'])) && preg_match('#^https?://#', $m) ? mb_substr($m, 0, 700) : null,
            'language' => $country === 'TR' ? 'tr' : (($country && in_array($country, self::ARAB, true)) || (!$country && $arabicName) ? 'ar' : 'en'),
            'extra' => array_filter(['address' => $pick(['address', 'street']), 'place_id' => $pick(['placeId']), 'ta' => $ta]),
        ];
    }

    /** اسم الدولة بالإنجليزي أو العربي (زي ما TripAdvisor وGoogle Maps بيكتبوه) ← كود من حرفين */
    public static function countryCode(string $name): ?string
    {
        $n = strtolower(trim(preg_replace('/^the\s+/i', '', trim($name))));
        if ($n === '') {
            return null;
        }
        if (preg_match('/^[a-z]{2}$/', $n) && in_array(strtoupper($n), self::COUNTRY_CODES, true)) {
            return strtoupper($n);
        }
        return self::COUNTRY_NAMES[$n] ?? null;
    }

    /** لو الملف مافيهوش الدولة: من كود التليفون الدولي، وبعدين من امتداد الدومين (.com.tr، .eg…) */
    public static function guessCountry(?string $phone, ?string $host): ?string
    {
        $digits = $phone ? preg_replace('/\D/', '', preg_replace('/^\s*00/', '+', $phone)) : '';
        if ($phone && preg_match('/^\s*(\+|00)/', $phone) && $digits !== '') {
            // الأكواد الأطول الأول (971 قبل 97)
            foreach ([4, 3, 2, 1] as $len) {
                $code = self::DIAL[substr($digits, 0, $len)] ?? null;
                if ($code) {
                    return $code;
                }
            }
        }
        if ($host && preg_match('/\.([a-z]{2})$/i', $host, $m)) {
            $tld = strtoupper($m[1]);
            $tld = $tld === 'UK' ? 'GB' : $tld;
            // امتدادات بتتستخدم كدومين عام مش كدولة
            if (in_array($tld, self::COUNTRY_CODES, true) && !in_array($tld, ['IO', 'CO', 'ME', 'TV', 'AI', 'LY', 'TO', 'FM', 'AM', 'CC', 'WS', 'GG', 'SO'], true)) {
                return $tld;
            }
        }
        return null;
    }

    private const COUNTRY_NAMES = [
        'turkiye' => 'TR', 'türkiye' => 'TR', 'turkey' => 'TR', 'تركيا' => 'TR', 'egypt' => 'EG', 'مصر' => 'EG',
        'united arab emirates' => 'AE', 'uae' => 'AE', 'الإمارات' => 'AE', 'saudi arabia' => 'SA', 'السعودية' => 'SA',
        'jordan' => 'JO', 'الأردن' => 'JO', 'morocco' => 'MA', 'المغرب' => 'MA', 'tunisia' => 'TN', 'تونس' => 'TN',
        'oman' => 'OM', 'عمان' => 'OM', 'qatar' => 'QA', 'قطر' => 'QA', 'kuwait' => 'KW', 'الكويت' => 'KW', 'bahrain' => 'BH', 'البحرين' => 'BH',
        'lebanon' => 'LB', 'لبنان' => 'LB', 'iraq' => 'IQ', 'العراق' => 'IQ', 'syria' => 'SY', 'palestine' => 'PS', 'palestinian territories' => 'PS',
        'algeria' => 'DZ', 'الجزائر' => 'DZ', 'libya' => 'LY', 'sudan' => 'SD', 'yemen' => 'YE',
        'greece' => 'GR', 'cyprus' => 'CY', 'italy' => 'IT', 'spain' => 'ES', 'portugal' => 'PT', 'france' => 'FR', 'malta' => 'MT',
        'germany' => 'DE', 'austria' => 'AT', 'switzerland' => 'CH', 'netherlands' => 'NL', 'the netherlands' => 'NL', 'belgium' => 'BE',
        'united kingdom' => 'GB', 'uk' => 'GB', 'england' => 'GB', 'scotland' => 'GB', 'wales' => 'GB', 'northern ireland' => 'GB', 'ireland' => 'IE',
        'iceland' => 'IS', 'norway' => 'NO', 'sweden' => 'SE', 'finland' => 'FI', 'denmark' => 'DK',
        'czech republic' => 'CZ', 'czechia' => 'CZ', 'poland' => 'PL', 'hungary' => 'HU', 'croatia' => 'HR', 'slovenia' => 'SI', 'montenegro' => 'ME',
        'albania' => 'AL', 'bosnia and herzegovina' => 'BA', 'serbia' => 'RS', 'north macedonia' => 'MK', 'bulgaria' => 'BG', 'romania' => 'RO',
        'slovakia' => 'SK', 'estonia' => 'EE', 'latvia' => 'LV', 'lithuania' => 'LT', 'ukraine' => 'UA', 'russia' => 'RU',
        'georgia' => 'GE', 'azerbaijan' => 'AZ', 'armenia' => 'AM', 'uzbekistan' => 'UZ', 'kazakhstan' => 'KZ', 'kyrgyzstan' => 'KG',
        'israel' => 'IL', 'iran' => 'IR',
        'united states' => 'US', 'usa' => 'US', 'united states of america' => 'US', 'canada' => 'CA', 'mexico' => 'MX',
        'brazil' => 'BR', 'argentina' => 'AR', 'chile' => 'CL', 'peru' => 'PE', 'colombia' => 'CO', 'ecuador' => 'EC', 'bolivia' => 'BO',
        'costa rica' => 'CR', 'panama' => 'PA', 'guatemala' => 'GT', 'belize' => 'BZ', 'cuba' => 'CU', 'dominican republic' => 'DO', 'jamaica' => 'JM',
        'thailand' => 'TH', 'indonesia' => 'ID', 'malaysia' => 'MY', 'maldives' => 'MV', 'vietnam' => 'VN', 'viet nam' => 'VN', 'cambodia' => 'KH',
        'laos' => 'LA', 'philippines' => 'PH', 'singapore' => 'SG', 'japan' => 'JP', 'south korea' => 'KR', 'china' => 'CN', 'taiwan' => 'TW',
        'hong kong' => 'HK', 'india' => 'IN', 'sri lanka' => 'LK', 'nepal' => 'NP', 'bhutan' => 'BT', 'pakistan' => 'PK', 'bangladesh' => 'BD',
        'australia' => 'AU', 'new zealand' => 'NZ', 'fiji' => 'FJ',
        'south africa' => 'ZA', 'kenya' => 'KE', 'tanzania' => 'TZ', 'uganda' => 'UG', 'rwanda' => 'RW', 'ethiopia' => 'ET', 'namibia' => 'NA',
        'botswana' => 'BW', 'zimbabwe' => 'ZW', 'zambia' => 'ZM', 'madagascar' => 'MG', 'mauritius' => 'MU', 'seychelles' => 'SC',
        'ghana' => 'GH', 'nigeria' => 'NG', 'senegal' => 'SN', 'cape verde' => 'CV', 'cabo verde' => 'CV',
    ];
    private const COUNTRY_CODES = ['TR', 'EG', 'AE', 'SA', 'JO', 'MA', 'TN', 'OM', 'QA', 'KW', 'BH', 'LB', 'IQ', 'SY', 'PS', 'DZ', 'LY', 'SD', 'YE', 'MR',
        'GR', 'CY', 'IT', 'ES', 'PT', 'FR', 'MT', 'DE', 'AT', 'CH', 'NL', 'BE', 'GB', 'IE', 'IS', 'NO', 'SE', 'FI', 'DK', 'CZ', 'PL', 'HU', 'HR', 'SI',
        'ME', 'AL', 'BA', 'RS', 'MK', 'BG', 'RO', 'SK', 'EE', 'LV', 'LT', 'UA', 'RU', 'GE', 'AZ', 'AM', 'UZ', 'KZ', 'KG', 'IL', 'IR', 'US', 'CA', 'MX',
        'BR', 'AR', 'CL', 'PE', 'CO', 'EC', 'BO', 'CR', 'PA', 'GT', 'BZ', 'CU', 'DO', 'JM', 'TH', 'ID', 'MY', 'MV', 'VN', 'KH', 'LA', 'PH', 'SG', 'JP',
        'KR', 'CN', 'TW', 'HK', 'IN', 'LK', 'NP', 'BT', 'PK', 'BD', 'AU', 'NZ', 'FJ', 'ZA', 'KE', 'TZ', 'UG', 'RW', 'ET', 'NA', 'BW', 'ZW', 'ZM', 'MG',
        'MU', 'SC', 'GH', 'NG', 'SN', 'CV', 'IO', 'CO', 'TV', 'AI', 'TO', 'FM', 'CC', 'WS', 'GG', 'SO', 'LU', 'MC', 'AD', 'SM', 'LI'];
    private const DIAL = [
        '90' => 'TR', '20' => 'EG', '971' => 'AE', '966' => 'SA', '962' => 'JO', '212' => 'MA', '216' => 'TN', '968' => 'OM', '974' => 'QA',
        '965' => 'KW', '973' => 'BH', '961' => 'LB', '964' => 'IQ', '963' => 'SY', '970' => 'PS', '213' => 'DZ', '218' => 'LY', '249' => 'SD',
        '30' => 'GR', '357' => 'CY', '39' => 'IT', '34' => 'ES', '351' => 'PT', '33' => 'FR', '356' => 'MT', '49' => 'DE', '43' => 'AT', '41' => 'CH',
        '31' => 'NL', '32' => 'BE', '44' => 'GB', '353' => 'IE', '354' => 'IS', '47' => 'NO', '46' => 'SE', '358' => 'FI', '45' => 'DK', '420' => 'CZ',
        '48' => 'PL', '36' => 'HU', '385' => 'HR', '386' => 'SI', '382' => 'ME', '355' => 'AL', '387' => 'BA', '381' => 'RS', '389' => 'MK', '359' => 'BG',
        '40' => 'RO', '995' => 'GE', '994' => 'AZ', '374' => 'AM', '998' => 'UZ', '972' => 'IL', '98' => 'IR', '52' => 'MX', '55' => 'BR', '54' => 'AR',
        '56' => 'CL', '51' => 'PE', '57' => 'CO', '593' => 'EC', '506' => 'CR', '507' => 'PA', '66' => 'TH', '62' => 'ID', '60' => 'MY', '960' => 'MV',
        '84' => 'VN', '855' => 'KH', '63' => 'PH', '65' => 'SG', '81' => 'JP', '82' => 'KR', '86' => 'CN', '852' => 'HK', '91' => 'IN', '94' => 'LK',
        '977' => 'NP', '61' => 'AU', '64' => 'NZ', '27' => 'ZA', '254' => 'KE', '255' => 'TZ', '256' => 'UG', '250' => 'RW', '251' => 'ET', '264' => 'NA',
        '230' => 'MU', '248' => 'SC', '233' => 'GH', '234' => 'NG', '221' => 'SN', '7' => 'RU',
    ];

    /** أحسن إيميل: اللي على دومين الشركة، وبعدين info/sales/booking */
    public static function bestEmail(array $emails, ?string $domain): ?string
    {
        $good = [];
        foreach ($emails as $e) {
            $e = strtolower(trim(preg_replace('/^mailto:/i', '', (string) $e)));
            $e = preg_replace('/\?.*$/', '', $e);
            if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            [$local, $host] = explode('@', $e, 2);
            if (preg_match(self::BAD_LOCAL, $local) || preg_match(self::BAD_DOMAIN, $host) || strlen($e) > 120) {
                continue;
            }
            $score = 0;
            if ($domain && ($host === $domain || str_ends_with($host, '.' . $domain))) {
                $score += 10;
            }
            if (preg_match('/^(info|sales|booking|bookings|reservations?|contact|hello|tours?|travel|marketing|ceo|manager)/', $local)) {
                $score += 3;
            }
            $good[$e] = max($good[$e] ?? 0, $score);
        }
        if (!$good) {
            return null;
        }
        arsort($good);
        return (string) array_key_first($good);
    }

    private function customerKeys(): array
    {
        $out = [];
        try {
            foreach ($this->q('SELECT email FROM users')->fetchAll(PDO::FETCH_COLUMN) as $e) {
                $out['e:' . strtolower((string) $e)] = true;
            }
            foreach ($this->q('SELECT main_url FROM websites')->fetchAll(PDO::FETCH_COLUMN) as $u) {
                $h = strtolower(preg_replace('/^www\./i', '', (string) parse_url((string) $u, PHP_URL_HOST)));
                if ($h !== '') {
                    $out['d:' . $h] = true;
                }
            }
        } catch (Throwable $e) {
        error_log('[PlatformOutreachService] ignored error: ' . $e->getMessage());
        }
        return $out;
    }

    // ------------------------------------------------------------------ إيميل من الموقع

    /** الشركات اللي مالهاش إيميل: بندوّر في صفحة التواصل بتاعتها */
    public function enrich(int $limit = 8, int $budget = 40): int
    {
        $start = time();
        $found = 0;
        $rows = $this->q("SELECT id,website,domain FROM platform_leads WHERE status='no_email' AND website IS NOT NULL AND enrich_tries<2 ORDER BY enrich_tries,id LIMIT " . (int) $limit)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $l) {
            if (time() - $start > $budget) {
                break;
            }
            $this->q('UPDATE platform_leads SET enrich_tries=enrich_tries+1 WHERE id=?', [$l['id']]);
            $emails = [];
            $base = rtrim((string) $l['website'], '/');
            foreach ([$base . '/', $base . '/contact', $base . '/contact-us', $base . '/ar/contact'] as $i => $url) {
                $p = ($this->fetch)($url, 1500000);
                if (!$p) {
                    continue;
                }
                $emails = array_merge($emails, self::emailsIn($p['body']));
                if ($i === 0 && preg_match_all('#href=["\']([^"\']*(contact|about|تواصل|اتصل)[^"\']*)["\']#iu', $p['body'], $m)) {
                    // لو فيه لينك "تواصل معنا" مختلف
                    foreach (array_slice($m[1], 0, 1) as $href) {
                        $abs = preg_match('#^https?://#i', $href) ? $href : $base . '/' . ltrim($href, '/');
                        if (($q = ($this->fetch)($abs, 1500000))) {
                            $emails = array_merge($emails, self::emailsIn($q['body']));
                        }
                    }
                }
                if (self::bestEmail($emails, $l['domain'])) {
                    break;
                }
            }
            $email = self::bestEmail($emails, $l['domain']);
            if ($email && !$this->q('SELECT 1 FROM platform_leads WHERE email=?', [$email])->fetchColumn()) {
                $this->q("UPDATE platform_leads SET email=?,status='new' WHERE id=?", [$email, $l['id']]);
                $found++;
            }
        }
        return $found;
    }

    /**
     * فحص موقع كل شركة جديدة وتحديد احتياجها (المسار الأول والتاني) - من الكرون أو من زرار في الأدمن.
     * الشركة مابيتبعتلهاش لحد ما تتفحص، عشان أول رسالة تبقى عن مشكلتها هي.
     */
    public function scan(int $limit = 8, int $budget = 50): int
    {
        if (!class_exists('OutreachNeeds')) {
            return 0;
        }
        if ($this->settings->get('outreach_cleanup_v2', '') === '') {
            $this->cleanupV2();
            $this->settings->set('outreach_cleanup_v2', date('Y-m-d H:i:s'));
        }
        // الفحص الاحترافي اتضاف: كل الشركات اللي لسه مااتبعتلهاش بتتفحص تاني بيه (مرة واحدة)
        if ($this->settings->get('outreach_testclean_v1', '') === '') {
            // شركات لسه مااتبعتلهاش ولا رسالة: أي فتح أو ضغط عليها كان تجربة من الأدمن (رسالة تجربة أو معاينة)
            $this->q("UPDATE platform_leads SET status=IF(status='interested' AND whatsapp_at IS NOT NULL,'new',status), opened_at=NULL, clicked_at=NULL, report_viewed_at=NULL, whatsapp_at=NULL WHERE step=0 AND (opened_at IS NOT NULL OR clicked_at IS NOT NULL OR report_viewed_at IS NOT NULL OR whatsapp_at IS NOT NULL)");
            $this->settings->set('outreach_testclean_v1', date('Y-m-d H:i:s'));
        }
        if ($this->settings->get('outreach_country_v1', '') === '') {
            $this->backfillCountries();
            $this->settings->set('outreach_country_v1', date('Y-m-d H:i:s'));
        }
        if ($this->settings->get('outreach_audit_v4', '') === '') {
            $this->q("UPDATE platform_leads SET scanned_at=NULL WHERE step=0 AND status IN ('new','no_email')");
            $this->settings->set('outreach_audit_v4', date('Y-m-d H:i:s'));
        }
        $start = time();
        $done = 0;
        $rows = $this->q("SELECT * FROM platform_leads WHERE scanned_at IS NULL AND step=0 AND status IN ('new','no_email') ORDER BY (email IS NULL), reviews DESC, id LIMIT " . (int) $limit)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $l) {
            if (time() - $start > $budget) {
                break;
            }
            $data = json_decode((string) ($l['data_json'] ?? ''), true) ?: [];
            $this->audit($l, $data);
            $done++;
        }
        return $done;
    }

    /**
     * الفحص الاحترافي لشركة: لحد 3 صفحات من موقعها + sitemap، ملفها على Google Maps، ومقارنة بالمنافسين.
     * النواقص بتتحفظ في data_json.audit والمسارات بتتحسب منها.
     */
    public function audit(array $l, array $data): array
    {
        $site = null;
        if (!empty($l['website'])) {
            $t0 = microtime(true);
            $p = ($this->fetch)((string) $l['website'], 2500000);
            $ms = (int) round((microtime(true) - $t0) * 1000);
            if ($p) {
                $pages = [OutreachAudit::parsePage($p['body'], $p['url'])];
                foreach (array_filter($pages[0]['links']) as $u) {
                    if (count($pages) >= 3) {
                        break;
                    }
                    if ($q = ($this->fetch)($u, 2000000)) {
                        $pages[] = OutreachAudit::parsePage($q['body'], $q['url']);
                    }
                }
                $origin = (string) parse_url($p['url'], PHP_URL_SCHEME) . '://' . (string) parse_url($p['url'], PHP_URL_HOST);
                $sm = ($this->fetch)($origin . '/sitemap.xml', 3000000);
                $site = OutreachAudit::mergePages($pages, $ms, stripos($p['url'], 'https://') === 0, $sm ? (bool) preg_match('/<(urlset|sitemapindex)\b/i', $sm['body']) : false);
            } else {
                // مواقع كتير بترفض الزيارات الأوتوماتيك (403 / Cloudflare) وهي شغالة عادي للناس:
                // "الموقع مابيفتحش" بس لو الدومين ميت أو السيرفر واقع فعلًا، غير كده مانقولش حاجة عن الموقع
                $code = ($this->probe)((string) $l['website']);
                $site = $code === 'down' ? ['ok' => false] : null;
                $data['site_status'] = $code;
            }
        }
        $city = trim((string) ($l['city'] ?? ''));
        $google = OutreachAudit::googleFacts(($this->places)(OutreachAudit::cleanName((string) $l['company']), $city));
        // البحث بالاسم من غير مدينة ضعيف: «مالقيناش ملف» هنا مش دليل إن الشركة مالهاش ملف، فمنقولهاش في الرسالة ولا التقرير
        if ($city === '' && empty($google['found'])) {
            $google = ['checked' => false];
        }
        $bench = OutreachAudit::benchmark($l, $this->peers((string) ($l['city'] ?? '')));
        $ta = (array) ($data['ta'] ?? []);
        $gaps = OutreachAudit::gaps($l, $ta, $site, $google, $bench);
        $score = OutreachAudit::score($gaps, $site !== null && !empty($site['ok']));
        $data['site'] = $site;
        $data['audit'] = ['google' => $google, 'bench' => $bench, 'gaps' => $gaps, 'score' => $score, 'at' => date('Y-m-d H:i:s')];
        $needs = OutreachAudit::needsFromGaps($gaps);
        if (!$needs) {
            // مفيش نقص واضح: الرد على الزباين والظهور في جوجل بيفيدوا أي شركة سياحة
            $needs = [['track' => !empty($l['website']) ? 'seo' : 'website', 'score' => 10, 'facts' => ['gaps' => []]]];
        }
        $this->q('UPDATE platform_leads SET data_json=?,needs_json=?,track=?,track2=?,score=?,scanned_at=NOW() WHERE id=?', [
            json_encode($data, JSON_UNESCAPED_UNICODE),
            json_encode(array_slice($needs, 0, 4), JSON_UNESCAPED_UNICODE),
            $needs[0]['track'] ?? null,
            $needs[1]['track'] ?? null,
            $score['total'],
            $l['id'],
        ]);
        return $data['audit'];
    }

    /** شركات نفس المدينة (للمقارنة بالمنافسين) - مرة واحدة لكل مدينة في الدورة */
    private function peers(string $city): array
    {
        static $cache = [];
        if ($city === '') {
            return [];
        }
        return $cache[$city] ??= $this->q("SELECT company,reviews,rating,category,city FROM platform_leads WHERE city=? AND status<>'invalid' LIMIT 3000", [$city])->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Google Places "Find Place": الشركة موجودة على Google Maps؟ تقييمها وعدد تقييماتها ومواعيدها */
    public static function placesFind(string $company, string $city): ?array
    {
        $key = '';
        try {
            $s = new SystemSettingsService();
            $key = $s->get('google_maps_api_key', '') ?: $s->get('integration_google_maps_api_key', '');
        } catch (Throwable $e) {
        error_log('[PlatformOutreachService] ignored error: ' . $e->getMessage());
        }
        if ($key === '' && defined('GOOGLE_MAPS_API_KEY')) {
            $key = (string) GOOGLE_MAPS_API_KEY;
        }
        if ($key === '' || trim($company) === '' || !function_exists('curl_init')) {
            return null;
        }
        $url = 'https://maps.googleapis.com/maps/api/place/findplacefromtext/json?inputtype=textquery&fields=' . rawurlencode('place_id,name,rating,user_ratings_total,opening_hours,business_status')
            . '&input=' . rawurlencode(trim($company . ' ' . $city)) . '&key=' . rawurlencode($key);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true]);
        $body = curl_exec($ch);
        curl_close($ch);
        $j = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($j) || !in_array($j['status'] ?? '', ['OK', 'ZERO_RESULTS'], true)) {
            return null; // مشكلة في المفتاح أو الحد: مانقولش "مالكمش ملف جوجل" غلط
        }
        return $j;
    }

    /**
     * مرة واحدة بعد تحديث التشخيص: المولات اللي اتستوردت قبل كده بتتشال من الإرسال، والشركات اللي
     * اتقال عليها "الموقع مابيفتحش" (ممكن كان رافض الزيارة الأوتوماتيك بس) بتتفحص تاني
     */
    private function cleanupV2(): void
    {
        $rows = $this->q("SELECT id,category,data_json,track,needs_json FROM platform_leads WHERE step=0 AND status IN ('new','no_email')")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $ta = (array) ((json_decode((string) ($r['data_json'] ?? ''), true) ?: [])['ta'] ?? []);
            if ($ta && !OutreachNeeds::isSeller($ta, $r['category'])) {
                $this->q("UPDATE platform_leads SET status='invalid',approved=0,notes=CONCAT(COALESCE(notes,''),' [not a tour business]') WHERE id=?", [$r['id']]);
            } elseif ($r['track'] === 'website' && strpos((string) $r['needs_json'], '"down"') !== false) {
                $this->q('UPDATE platform_leads SET scanned_at=NULL,approved=0 WHERE id=?', [$r['id']]);
            }
        }
    }

    private function saveDiagnosis(array $l, array $data, ?array $site): array
    {
        $needs = OutreachNeeds::diagnose($l, (array) ($data['ta'] ?? []), $site);
        $this->q('UPDATE platform_leads SET data_json=?,needs_json=?,track=?,track2=?,scanned_at=NOW() WHERE id=?', [
            json_encode($data, JSON_UNESCAPED_UNICODE),
            json_encode(array_slice($needs, 0, 4), JSON_UNESCAPED_UNICODE),
            $needs[0]['track'] ?? null,
            $needs[1]['track'] ?? null,
            $l['id'],
        ]);
        return $needs;
    }

    /** المسار والرسالة جوه المسار للخطوة دي: 1-3 المسار الأول، 4 = أول رسالة في التاني، 5 = آخر رسالة فيه */
    public static function stepTrack(array $lead, int $step): array
    {
        if (empty($lead['track'])) {
            return [null, $step];
        }
        if ($step <= 3) {
            return [(string) $lead['track'], $step];
        }
        return [(string) ($lead['track2'] ?: $lead['track']), $step === 4 ? 1 : 3];
    }

    public static function lastStep(array $lead): int
    {
        return !empty($lead['track']) && !empty($lead['track2']) ? 5 : 3;
    }

    public static function emailsIn(string $html): array
    {
        $html = str_ireplace(['[at]', '(at)', ' at ', '&#64;', '&commat;'], ['@', '@', '@', '@', '@'], $html);
        preg_match_all('/(?:mailto:)?([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})/', $html, $m);
        return array_values(array_unique($m[1]));
    }

    // ------------------------------------------------------------------ كتابة الرسالة

    public function compose(array $lead, int $step, array $set, ?callable $ai = null): array
    {
        [$track, $local] = self::stepTrack($lead, $step);
        if ($track !== null && class_exists('OutreachNeeds')) {
            return $this->composeTrack($lead, $step, $track, $local, $set, $ai);
        }
        $lang = $lead['language'] === 'ar' ? 'ar' : 'en';
        $tpl = $set['templates'][$lang][$step] ?? self::defaultTemplates()[$lang][$step];
        $opener = $step === 1 ? $this->opener($lead, $lang, $set, $ai) : '';
        $link = self::withParams((string) $set['signup_url'], ['utm_source' => 'outreach', 'utm_medium' => 'email', 'utm_campaign' => 'step' . $step, 'ref' => $lead['token']]);
        $vars = [
            '{{company}}' => OutreachAudit::cleanName((string) $lead['company']) !== '' ? OutreachAudit::cleanName((string) $lead['company']) : ($lang === 'ar' ? 'شركتكم' : 'your company'),
            '{{city}}' => (string) ($lead['city'] ?? ''),
            '{{rating}}' => $lead['rating'] !== null ? rtrim(rtrim(number_format((float) $lead['rating'], 1), '0'), '.') : '',
            '{{reviews}}' => (string) ($lead['reviews'] ?? ''),
            '{{website_or_company}}' => $lead['domain'] ?: ($lead['company'] ?: ''),
            '{{opener}}' => $opener,
            '{{signup_link}}' => '__LINK__',
            '{{sender_name}}' => (string) $set['sender_name'],
            '{{sender_title_line}}' => trim((string) $set['sender_title']) !== '' ? "\n" . $set['sender_title'] : '',
        ];
        $subject = trim(preg_replace('/\s+/u', ' ', strtr($tpl['subject'], $vars)));
        $text = preg_replace("/\n{3,}/", "\n\n", strtr($tpl['body'], $vars));
        $unsub = (defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://tourfecto.pro') . '/p/u/' . $lead['token'];
        $click = (defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://tourfecto.pro') . '/p/c/' . $lead['token'] . '/' . $step;
        $html = self::toHtml($text, $click, $link, $lang);
        $footer = $lang === 'ar'
            ? 'وصلتك الرسالة دي لأن شركتكم ظاهرة كشركة سياحة على الإنترنت. مش عاوز رسايل تاني؟ <a href="' . htmlspecialchars($unsub) . '" style="color:#888">إلغاء الاشتراك</a>'
            : 'You received this because your company is listed publicly as a travel business. Not interested? <a href="' . htmlspecialchars($unsub) . '" style="color:#888">Unsubscribe</a>';
        $dir = $lang === 'ar' ? 'rtl' : 'ltr';
        $pixel = !empty($set['track_opens']) && $set['track_opens'] !== '0'
            ? '<img src="' . htmlspecialchars((defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/p/o/' . $lead['token'] . '/' . $step) . '" width="1" height="1" alt="">' : '';
        $body = '<div dir="' . $dir . '" style="font-family:Arial,Tahoma,sans-serif;font-size:15px;line-height:1.7;color:#1f2933;max-width:600px">' . $html
            . '<p style="font-size:12px;color:#888;margin-top:28px;border-top:1px solid #eee;padding-top:10px">' . $footer . '<br>' . htmlspecialchars((string) $set['company_address']) . '</p>' . $pixel . '</div>';
        return ['subject' => $subject, 'html' => $body, 'text' => str_replace('__LINK__', $link, $text), 'unsubscribe' => $unsub];
    }

    /** رسالة مسار الاحتياج: المشكلة بأرقام الشركة ← الحل ← التقرير المجاني ← (آخر رسالة) التسجيل والواتساب */
    private function composeTrack(array $lead, int $step, string $track, int $local, array $set, ?callable $ai): array
    {
        $lang = in_array($lead['language'] ?? '', OutreachNeeds::LANGS, true) ? $lead['language'] : 'en';
        $t = OutreachNeeds::texts($track, $lang);
        $facts = [];
        foreach ((array) (json_decode((string) ($lead['needs_json'] ?? ''), true) ?: []) as $n) {
            if (($n['track'] ?? '') === $track) {
                $facts = (array) ($n['facts'] ?? []);
            }
        }
        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://tourfecto.pro';
        $company = OutreachAudit::cleanName((string) ($lead['company'] ?? '')) !== '' ? OutreachAudit::cleanName((string) $lead['company']) : ['ar' => 'شركتكم', 'en' => 'your company', 'tr' => 'şirketiniz'][$lang];
        $opener = $step === 1 ? $this->aiOpener($lead, $lang, $set, $ai) : '';
        $hasWa = (string) ($set['whatsapp_number'] ?? '') !== '';
        $btn = OutreachNeeds::BUTTONS[$lang];
        $vars = [
            '{{company}}' => $company,
            '{{opener}}' => $opener !== '' ? $opener . "\n\n" : '',
            '{{evidence}}' => $this->auditEvidence($lead + ['company' => $company], $track, $lang, $local === 1)
                ?: OutreachNeeds::evidence($track, $facts, $lead + ['company' => $company], $lang),
            '{{solution}}' => $t['solution'],
            '{{bullets}}' => $t['bullets'],
            '{{pitch}}' => $t['pitch'],
            '{{website_or_company}}' => $lead['domain'] ?: $company,
            '{{report_link}}' => '__REPORT__',
            '{{signup_link}}' => '__LINK__',
            '{{whatsapp_link}}' => $hasWa ? '__WA__' : $btn['reply'],
            '{{sender_name}}' => (string) $set['sender_name'],
            '{{sender_title_line}}' => trim((string) $set['sender_title']) !== '' ? "\n" . $set['sender_title'] : '',
        ];
        // الأرقام والاسم جوه الجمل نفسها بتتبدّل مرة تانية
        $fill = static fn (string $s): string => strtr(strtr($s, $vars), ['{{company}}' => $company, '{{website_or_company}}' => $vars['{{website_or_company}}']]);
        $subject = trim(preg_replace('/\s+/u', ' ', $fill($t[['', 's1', 's2', 's3'][$local]])));
        $text = preg_replace("/\n{3,}/", "\n\n", $fill($t['b' . $local]));
        if (!$hasWa) {
            // مفيش رقم واتساب: سطر "أو كلّمونا على واتساب" بيتشال والرد على الإيميل بياخد مكانه
            $text = preg_replace("/\n\n[^\n]*\n" . preg_quote($btn['reply'], '/') . '/u', "\n\n" . $btn['reply'], $text);
        }
        $unsub = $base . '/p/u/' . $lead['token'];
        $links = [
            '__LINK__' => [$base . '/p/c/' . $lead['token'] . '/' . $step, $btn['signup'], self::withParams((string) $set['signup_url'], ['utm_source' => 'outreach', 'utm_medium' => 'email', 'utm_campaign' => $track . '_' . $step, 'ref' => $lead['token']])],
            '__REPORT__' => [$base . '/p/r/' . $lead['token'] . '?s=' . $step, $btn['report'], $base . '/p/r/' . $lead['token'] . '?s=' . $step],
            '__WA__' => [$base . '/p/w/' . $lead['token'] . '/' . $step, $btn['wa'], $base . '/p/w/' . $lead['token'] . '/' . $step],
        ];
        $needs = (array) (json_decode((string) ($lead['needs_json'] ?? ''), true) ?: []);
        $card = $this->auditCard($lead + ['company' => $company], $track, $lang, $local === 1);
        $html = self::renderTrackHtml($text, $lang, $local, $links, $vars['{{evidence}}'], (string) $set['sender_name'], (string) $set['sender_title'], $local < 3 ? OutreachNeeds::otherServices($track, $needs) : [], $set, $base, $unsub, $lead, $step, $card);
        $plain = strtr($text, array_map(static fn ($l) => $l[2], $links));
        return ['subject' => $subject, 'html' => $html, 'text' => $plain, 'unsubscribe' => $unsub, 'track' => $track];
    }

    /**
     * تصميم الإيميل: شريط ذهبي وهيدر أبيض باللوجو، كارت الفحص (الدرجة قدام المنافسين + كل نقص في سطر بلون خطورته)،
     * زرار كبير للتقرير، خدمات تانية في كروت، توقيع بصورة.
     * جداول وستايل جوه العناصر (Gmail وOutlook مابيقروش CSS خارجي)، وصورتين بس عشان مايروحش Spam.
     */
    private const CARD_UI = [
        'ar' => ['score' => 'الدرجة الرقمية', 'you' => 'إنتم', 'avg' => 'متوسط {city}', 'badge' => 'فحص مجاني'],
        'en' => ['score' => 'Digital score', 'you' => 'You', 'avg' => '{city} average', 'badge' => 'Free check'],
        'tr' => ['score' => 'Dijital puan', 'you' => 'Siz', 'avg' => '{city} ortalaması', 'badge' => 'Ücretsiz analiz'],
    ];

    private static function renderTrackHtml(string $text, string $lang, int $local, array $links, string $evidence, string $sender, string $title, array $others, array $set, string $base, string $unsub, array $lead, int $step, ?array $card = null): string
    {
        $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $ui = OutreachNeeds::UI[$lang];
        $cu = self::CARD_UI[$lang] ?? self::CARD_UI['en'];
        $dir = $lang === 'ar' ? 'rtl' : 'ltr';
        $align = $lang === 'ar' ? 'right' : 'left';
        $far = $lang === 'ar' ? 'left' : 'right';
        $font = $lang === 'ar' ? "Tahoma,'Segoe UI',Arial,sans-serif" : "'Segoe UI',Helvetica,Arial,sans-serif";
        // الهوية الجديدة (زي الصفحة الرئيسية): أيقونة T الدهبي على كحلي - اللوجو القديم المرفوع بيتجاهل
        $logo = trim((string) ($set['brand_logo_url'] ?? ''));
        if ($logo === '' || preg_match('#tourfecto-favicon|/logo_\d#', $logo)) $logo = $base . '/assets/icons/icon-192.png?v=tf-brand-20261024';
        $photo = trim((string) ($set['sender_photo_url'] ?? '')) ?: $logo;
        $navy = '#0A1A2E';
        $gold = '#C99A3B';
        $inline = static function (string $s) use ($links, $e, $navy): string {
            $s = $e($s);
            foreach ($links as $ph => [$href, $label]) {
                $s = str_replace($ph, '<a href="' . $e($href) . '" style="color:' . $navy . ';font-weight:bold;text-decoration:underline">' . $e($label) . '</a>', $s);
            }
            return $s;
        };
        $p = static fn (string $html, string $m = '0 0 16px') => '<p style="margin:' . $m . ';font-size:15px;line-height:1.75;color:#334155;text-align:' . $align . '">' . $html . '</p>';
        $signature = '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-top:26px;border-top:1px solid #E5E9F0"><tr>'
            . '<td width="60" valign="top" style="padding-top:18px"><img src="' . $e($photo) . '" width="48" height="48" alt="' . $e($sender) . '" style="display:block;border-radius:50%;width:48px;height:48px;object-fit:cover;border:2px solid ' . $gold . '"></td>'
            . '<td valign="top" style="padding:18px 12px 0;font-size:14px;line-height:1.5;color:#334155;text-align:' . $align . '"><b style="font-size:15px;color:' . $navy . '">' . $e($sender) . '</b><br><span style="color:#64748B">' . $e($title !== '' ? $title : 'Tourfecto') . '</span><br><a href="' . $e($base) . '" style="color:' . $gold . ';text-decoration:none;font-weight:bold">tourfecto.pro</a></td></tr></table>';

        // كارت الفحص: بدل فقرة الأرقام الطويلة
        $cardHtml = '';
        if ($card && $card['items']) {
            $rows = '';
            if ($card['score'] !== null) {
                $s = $card['score'];
                $col = $s >= 75 ? '#16A34A' : ($s >= 50 ? '#D97706' : '#DC2626');
                $bar = static fn (string $label, int $v, string $c, bool $bold) => '<tr><td style="padding:3px 0;font-size:12px;color:#64748B;white-space:nowrap;width:1%;text-align:' . $align . '">' . $label . '</td><td style="padding:3px 8px"><table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#E5E9F0;border-radius:6px"><tr><td width="' . max(4, min(100, $v)) . '%" style="background:' . $c . ';border-radius:6px;height:8px;font-size:0;line-height:0">&nbsp;</td><td style="font-size:0;line-height:0">&nbsp;</td></tr></table></td><td style="padding:3px 0;font-size:13px;width:1%;white-space:nowrap;color:' . ($bold ? $c : '#64748B') . ';font-weight:' . ($bold ? 'bold' : 'normal') . '">' . $v . '</td></tr>';
                $bars = $bar($e($cu['you']), $s, $col, true)
                    . ($card['avg'] !== null ? $bar($e(strtr($cu['avg'], ['{city}' => $card['city']])), (int) $card['avg'], '#94A3B8', false) : '');
                $rows .= '<tr><td style="padding:18px 18px 14px;border-bottom:1px solid #E5E9F0"><table role="presentation" cellpadding="0" cellspacing="0" width="100%"><tr>'
                    . '<td width="84" valign="middle"><table role="presentation" cellpadding="0" cellspacing="0"><tr><td width="72" height="72" align="center" valign="middle" style="width:72px;height:72px;border-radius:50%;border:5px solid ' . $col . ';font-size:26px;font-weight:bold;color:' . $col . ';text-align:center;line-height:1">' . $s . '<div style="font-size:10px;color:#94A3B8;font-weight:normal;margin-top:2px">/100</div></td></tr></table></td>'
                    . '<td valign="middle" style="padding:0 12px"><div style="font-size:12px;font-weight:bold;letter-spacing:.2px;color:#64748B;margin-bottom:6px;text-align:' . $align . '">' . $e($cu['score']) . ' · ' . $e($card['company']) . '</div>'
                    . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%">' . $bars . '</table></td></tr></table></td></tr>';
            }
            $list = '';
            foreach ($card['items'] as $it) {
                $dot = $it['sev'] >= 7 ? '#DC2626' : ($it['sev'] >= 4 ? '#D97706' : '#64748B');
                $list .= '<tr><td width="18" valign="top" style="padding:9px 0 0"><div style="width:9px;height:9px;border-radius:50%;background:' . $dot . ';font-size:0;line-height:0">&nbsp;</div></td>'
                    . '<td style="padding:4px 0;font-size:14px;line-height:1.65;color:#1E293B;text-align:' . $align . '">' . $e($it['text']) . '</td></tr>';
            }
            $rows .= '<tr><td style="padding:14px 18px 12px"><div style="font-size:12px;font-weight:bold;letter-spacing:.4px;color:#9A6B00;margin-bottom:6px;text-align:' . $align . '">🔍 ' . $e($ui['noticed']) . '</div><table role="presentation" cellpadding="0" cellspacing="0" width="100%">' . $list . '</table></td></tr>';
            $cardHtml = '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:4px 0 22px;background:#FBFCFE;border:1px solid #E5E9F0;border-top:3px solid ' . $gold . ';border-radius:12px">' . $rows . '</table>';
        }

        $body = '';
        $signed = false;
        foreach (preg_split("/\n{2,}/", trim($text)) as $i => $para) {
            $lines = explode("\n", $para);
            $trimmed = trim($para);
            if ($evidence !== '' && strpos($trimmed, $evidence) !== false) {
                if ($cardHtml !== '') {
                    $rest = trim(str_replace($evidence, '', $trimmed));
                    $body .= ($rest !== '' ? $p($inline($rest), '0 0 12px') : '') . $cardHtml;
                } else {
                    $body .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:4px 0 22px;background:#FBFCFE;border:1px solid #E5E9F0;border-top:3px solid ' . $gold . ';border-radius:12px"><tr><td style="padding:14px 18px;text-align:' . $align . '">'
                        . '<div style="font-size:12px;font-weight:bold;letter-spacing:.4px;color:#9A6B00;margin-bottom:4px">🔍 ' . $e($ui['noticed']) . '</div><div style="font-size:15px;line-height:1.7;color:#1E293B">' . $e($trimmed) . '</div></td></tr></table>';
                }
                continue;
            }
            // أي سطر لوحده عليه لينك (التقرير / التسجيل / الواتساب) بيبقى زرار، والكلام اللي قبله فقرة عادية
            $btnLines = array_filter($lines, static fn ($l) => isset($links[trim($l)]));
            if ($btnLines) {
                $textLines = array_filter($lines, static fn ($l) => !isset($links[trim($l)]));
                if ($textLines) {
                    $body .= $p(implode('<br>', array_map($inline, $textLines)), '0 0 12px');
                }
                foreach ($btnLines as $bl) {
                    $ph = trim($bl);
                    [$href, $label] = $links[$ph];
                    [$bg, $fg] = $ph === '__WA__' ? ['#25D366', '#ffffff'] : ($ph === '__REPORT__' ? [$gold, $navy] : [$navy, '#ffffff']);
                    $grad = $ph === '__REPORT__' ? 'background-image:linear-gradient(135deg,#C99A3B,#E3BC62);' : '';
                    $body .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:6px 0 22px"><tr><td align="center"><table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="background:' . $bg . ';' . $grad . 'border-radius:12px;text-align:center;box-shadow:0 6px 18px rgba(10,26,46,.18)"><a href="' . $e($href) . '" style="display:inline-block;padding:15px 36px;color:' . $fg . ';font-weight:bold;font-size:16px;text-decoration:none">' . $e($label) . ($ph === '__WA__' ? '' : ' →') . '</a></td></tr></table></td></tr></table>';
                }
                continue;
            }
            if (str_starts_with($trimmed, '•')) {
                $items = '';
                foreach ($lines as $li) {
                    $items .= '<tr><td width="26" valign="top" style="padding:6px 0"><div style="width:18px;height:18px;border-radius:50%;background:#E8F5EE;color:#16A34A;font-size:12px;font-weight:bold;line-height:18px;text-align:center">✓</div></td><td style="padding:5px 0;font-size:15px;line-height:1.6;color:#1E293B;text-align:' . $align . '">' . $e(trim(ltrim(trim($li), '•'))) . '</td></tr>';
                }
                $body .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 20px">' . $items . '</table>';
                continue;
            }
            // التوقيع: اسم المرسل لوحده أو آخر سطر في الفقرة
            if ($sender !== '' && (trim($lines[0]) === $sender || trim(end($lines)) === $sender)) {
                $before = array_filter($lines, static fn ($l) => trim($l) !== $sender && trim($l) !== $title);
                if ($before) {
                    $body .= $p(implode('<br>', array_map($inline, $before)), '0 0 6px');
                }
                $body .= $signature;
                $signed = true;
                continue;
            }
            // أول سطر (التحية) أغمق شوية
            $body .= $i === 0 && count($lines) === 1
                ? '<p style="margin:0 0 14px;font-size:16px;font-weight:bold;color:' . $navy . ';text-align:' . $align . '">' . $inline($lines[0]) . '</p>'
                : $p(implode('<br>', array_map($inline, $lines)));
        }
        if (!$signed) {
            $body .= $signature;
        }
        // قيمة المنصة: «Tourfecto بتشتغل مكانك» (أول رسالتين) - بتغني عن شبكة الخدمات التانية
        $value = $local < 3 ? self::valuePanel($lang, $links, strpos($text, '__LINK__') === false, $e, $align, $navy, $gold) : '';
        $grid = $value === '' && $others ? self::othersGrid($others, $lang, $ui, $e, $align) : '';
        [$footText, $footLink] = OutreachNeeds::FOOTER[$lang];
        $pixel = !empty($set['track_opens']) && $set['track_opens'] !== '0'
            ? '<img src="' . $e($base . '/p/o/' . $lead['token'] . '/' . $step) . '" width="1" height="1" alt="" style="display:block">' : '';
        // سطر المعاينة اللي بيظهر تحت العنوان في Gmail: مشكلة الشركة، مش جملة الهيدر
        $preview = $card && $card['items'] ? $card['items'][0]['text'] : ($evidence !== '' ? $evidence : trim((string) preg_replace('/\s+/u', ' ', strip_tags(str_replace(array_keys($links), '', $text)))));
        $preheader = '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all">' . $e(mb_substr($preview, 0, 140)) . str_repeat('&#8204;&nbsp;', 40) . '</div>';
        return '<div dir="' . $dir . '" style="margin:0;padding:28px 10px;background:#F3F5F9;font-family:' . $font . '">' . $preheader
            . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px;margin:0 auto;border-collapse:separate"><tr><td>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 8px 30px rgba(10,26,46,.10)">'
            . '<tr><td bgcolor="' . $navy . '" style="background:' . $navy . ';background-image:linear-gradient(135deg,#0A1A2E 0%,#12304F 100%);padding:22px 26px;border-radius:18px 18px 0 0"><table role="presentation" cellpadding="0" cellspacing="0" width="100%"><tr>'
            . '<td valign="middle" style="text-align:' . $align . '"><table role="presentation" cellpadding="0" cellspacing="0"><tr><td valign="middle"><img src="' . $e($logo) . '" width="42" height="42" alt="Tourfecto" style="display:block;border-radius:11px"></td>'
            . '<td valign="middle" style="padding:0 12px" dir="ltr"><div style="color:#ffffff;font-size:21px;font-weight:bold;line-height:1.1;letter-spacing:-.2px;font-family:\'Segoe UI\',Helvetica,Arial,sans-serif">Tour<span style="color:#DDB255">fecto</span></div><div dir="' . $dir . '" style="color:#B8C4D6;font-size:12px;margin-top:3px;text-align:' . $align . '">' . $e($ui['tagline']) . '</div></td></tr></table></td>'
            . ($local === 1 && $card ? '<td valign="middle" style="text-align:' . $far . '"><span style="display:inline-block;background:rgba(221,178,85,.16);color:#F1D48F;border:1px solid rgba(221,178,85,.55);border-radius:20px;padding:5px 12px;font-size:12px;font-weight:bold;white-space:nowrap">' . $e($cu['badge']) . '</span></td>' : '')
            . '</tr></table></td></tr>'
            . '<tr><td style="height:4px;line-height:4px;font-size:0;background:' . $gold . ';background-image:linear-gradient(90deg,#C99A3B,#E3BC62,#C99A3B)">&nbsp;</td></tr>'
            . '<tr><td style="padding:26px 26px 24px;text-align:' . $align . '">' . $body . '</td></tr>'
            . $value
            . ($grid !== '' ? '<tr><td style="padding:20px 20px 22px;background:#F7F9FC;border-top:1px solid #EEF2F6;border-radius:0 0 16px 16px">' . $grid . '</td></tr>' : '')
            . '</table>'
            . '<div style="font-size:12px;line-height:1.7;color:#94A3B8;text-align:center;padding:18px 18px 8px"><span dir="ltr" style="color:#0A1A2E;font-weight:bold">Tour<span style="color:#C99A3B">fecto</span></span> · <a href="' . $e($base) . '" style="color:#64748B;text-decoration:none">tourfecto.pro</a> · <a href="mailto:' . $e(class_exists('PublicLayout') ? PublicLayout::company()['email'] : 'info@tourfecto.pro') . '" style="color:#64748B;text-decoration:none">' . $e(class_exists('PublicLayout') ? PublicLayout::company()['email'] : 'info@tourfecto.pro') . '</a></div>'
            . '<div style="font-size:12px;line-height:1.6;color:#94A3B8;text-align:center;padding:0 18px 16px">' . $e($footText) . ' <a href="' . $e($unsub) . '" style="color:#94A3B8">' . $e($footLink) . '</a><br>' . $e((string) $set['company_address']) . '</div>'
            . '</td></tr></table>' . $pixel . '</div>';
    }

    /** نص قسم «المنصة بتشتغل مكانك» بكل لغة */
    private const VALUE_UI = [
        'ar' => [
            'title' => 'Tourfecto بتشتغل مكانك - 24 ساعة',
            'sub' => 'منصة واحدة بتعمل شغل فريق تسويق وخدمة عملاء كامل. إنتم بتركّزوا على الرحلات، وهي بتعمل الباقي - وإنتم بتوافقوا بس.',
            'items' => [
                ['⭐', 'بترد على كل تقييم', 'بلغة السائح نفسه (15 لغة) وتنبهكم فورًا على أي تقييم سلبي.'],
                ['🔎', 'بتحسّن ظهوركم في جوجل', 'تفحص صفحات رحلاتكم وتصلّحها وتكتبلكم مقالات.'],
                ['💬', 'بترد على العملاء', 'على الواتساب والموقع 24 ساعة، وبتعرف رحلاتكم وأسعاركم.'],
                ['📅', 'حجز ودفع مباشر', 'موقع حجز بلغات كتير ولينكات دفع - من غير عمولة الوسطاء.'],
                ['📣', 'تسويق جاهز كل شهر', 'بوستات سوشيال ومقالات وإعلانات بصوركم ولوجو شركتكم.'],
                ['📊', 'عينكم على المنافسين', 'تنبيه لما منافس يغيّر سعره، وملخص كل صبح بأهم الأرقام.'],
            ],
            'steps' => ['سجّلوا في دقيقتين', 'اربطوا جوجل والواتساب', 'المنصة تشتغل وإنتم توافقوا'],
            'facts' => [['15', 'لغة للردود'], ['24/7', 'شغالة طول اليوم'], ['+30', 'مهمة تلقائية'], ['0%', 'عمولة حجز']],
            'cta' => 'جرّبوا Tourfecto مجانًا',
        ],
        'en' => [
            'title' => 'Tourfecto does the work for you - 24/7',
            'sub' => 'One platform that does the job of a full marketing and customer-service team. You focus on the tours, it handles the rest - you just approve.',
            'items' => [
                ['⭐', 'Replies to every review', 'In the traveller’s own language (15 languages), with instant alerts for negative reviews.'],
                ['🔎', 'Improves how you appear on Google', 'Audits and fixes your tour pages and writes articles for you.'],
                ['💬', 'Answers your guests', 'On WhatsApp and your website 24/7 - it knows your tours and prices.'],
                ['📅', 'Direct bookings & payments', 'A multilingual booking site and payment links - no middleman commission.'],
                ['📣', 'Marketing, ready every month', 'Social posts, articles and ads with your own photos and logo.'],
                ['📊', 'Eyes on competitors', 'Alerts when a competitor changes prices, plus a morning summary of your numbers.'],
            ],
            'steps' => ['Sign up in 2 minutes', 'Connect Google & WhatsApp', 'It works, you approve'],
            'facts' => [['15', 'reply languages'], ['24/7', 'always on'], ['30+', 'automated jobs'], ['0%', 'booking commission']],
            'cta' => 'Try Tourfecto free',
        ],
        'tr' => [
            'title' => 'Tourfecto işi sizin yerinize yapar - 7/24',
            'sub' => 'Tam bir pazarlama ve müşteri hizmetleri ekibinin işini yapan tek platform. Siz turlara odaklanın, gerisini o halletsin - siz sadece onaylayın.',
            'items' => [
                ['⭐', 'Her yoruma cevap verir', 'Misafirin kendi dilinde (15 dil) ve olumsuz yorumlarda anında uyarı.'],
                ['🔎', 'Google’daki görünürlüğünüzü iyileştirir', 'Tur sayfalarınızı inceler, düzeltir ve sizin için makale yazar.'],
                ['💬', 'Misafirlerinize cevap verir', 'WhatsApp’ta ve sitenizde 7/24 - turlarınızı ve fiyatlarınızı bilir.'],
                ['📅', 'Doğrudan rezervasyon ve ödeme', 'Çok dilli rezervasyon sitesi ve ödeme linkleri - aracı komisyonu yok.'],
                ['📣', 'Her ay hazır pazarlama', 'Kendi fotoğraflarınız ve logonuzla sosyal medya, makale ve reklamlar.'],
                ['📊', 'Rakipler gözünüzün önünde', 'Rakip fiyat değiştirince uyarı, her sabah rakamlarınızın özeti.'],
            ],
            'steps' => ['2 dakikada kaydolun', 'Google ve WhatsApp’ı bağlayın', 'Platform çalışır, siz onaylarsınız'],
            'facts' => [['15', 'cevap dili'], ['7/24', 'kesintisiz'], ['30+', 'otomatik görev'], ['%0', 'rezervasyon komisyonu']],
            'cta' => 'Tourfecto’yu ücretsiz deneyin',
        ],
    ];

    private static function valuePanel(string $lang, array $links, bool $withCta, callable $e, string $align, string $navy, string $gold): string
    {
        $v = self::VALUE_UI[$lang] ?? self::VALUE_UI['en'];
        $cells = [];
        foreach ($v['items'] as [$icon, $h, $d]) {
            $cells[] = '<td width="50%" valign="top" style="padding:5px"><table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#ffffff;border:1px solid #E5E9F0;border-radius:12px"><tr>'
                . '<td width="46" valign="top" style="padding:12px 0;padding-' . ($align === 'right' ? 'right' : 'left') . ':12px"><div style="width:34px;height:34px;border-radius:10px;background:#FDF6E3;font-size:17px;line-height:34px;text-align:center">' . $icon . '</div></td>'
                . '<td valign="top" style="padding:11px 12px;text-align:' . $align . '"><div style="font-weight:bold;font-size:14px;color:' . $navy . '">' . $e($h) . '</div><div style="font-size:12.5px;color:#64748B;line-height:1.55">' . $e($d) . '</div></td></tr></table></td>';
        }
        $grid = '';
        foreach (array_chunk($cells, 2) as $pair) {
            $grid .= '<tr>' . implode('', $pair) . '</tr>';
        }
        $steps = '';
        foreach ($v['steps'] as $i => $st) {
            $steps .= '<td width="33%" valign="top" style="padding:0 4px;text-align:center"><div style="width:28px;height:28px;margin:0 auto 6px;border-radius:50%;background:' . $gold . ';color:' . $navy . ';font-weight:bold;font-size:14px;line-height:28px;text-align:center">' . ($i + 1) . '</div><div style="font-size:12.5px;line-height:1.45;color:#E2E8F0">' . $e($st) . '</div></td>';
        }
        $facts = '';
        foreach ($v['facts'] as [$n, $l]) {
            $facts .= '<td width="25%" align="center" style="padding:4px 2px"><div dir="ltr" style="font-size:20px;font-weight:bold;color:' . $gold . ';line-height:1.1">' . $e($n) . '</div><div style="font-size:11.5px;color:#64748B;margin-top:2px">' . $e($l) . '</div></td>';
        }
        $cta = '';
        if ($withCta && isset($links['__LINK__'])) {
            $cta = '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:18px 0 4px"><tr><td align="center"><table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="background:' . $gold . ';background-image:linear-gradient(135deg,#C99A3B,#E3BC62);border-radius:12px"><a href="' . $e($links['__LINK__'][0]) . '" style="display:inline-block;padding:14px 34px;color:' . $navy . ';font-weight:bold;font-size:15px;text-decoration:none">' . $e($v['cta']) . ' →</a></td></tr></table></td></tr></table>';
        }
        return '<tr><td style="padding:24px 20px 22px;background:#F7F9FC;border-top:1px solid #EEF2F6">'
            . '<div style="margin:0 6px 4px;font-size:18px;font-weight:bold;color:' . $navy . ';text-align:' . $align . '">' . $e($v['title']) . '</div>'
            . '<div style="margin:0 6px 14px;font-size:13.5px;line-height:1.7;color:#475569;text-align:' . $align . '">' . $e($v['sub']) . '</div>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%">' . $grid . '</table>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-top:14px"><tr>' . $facts . '</tr></table>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-top:14px;background:' . $navy . ';background-image:linear-gradient(135deg,#0A1A2E,#12304F);border-radius:14px"><tr><td style="padding:16px 10px"><table role="presentation" cellpadding="0" cellspacing="0" width="100%"><tr>' . $steps . '</tr></table></td></tr></table>'
            . $cta
            . '</td></tr>';
    }

    private static function othersGrid(array $others, string $lang, array $ui, callable $e, string $align): string
    {
        $cells = [];
        foreach ($others as $t) {
            $s = OutreachNeeds::SHORT[$t] ?? null;
            if (!$s) {
                continue;
            }
            [$name, $desc] = $s[$lang] ?? $s['en'];
            $cells[] = '<td width="50%" valign="top" style="padding:5px"><table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#ffffff;border:1px solid #E5E9F0;border-radius:12px"><tr>'
                . '<td width="44" valign="top" style="padding:12px 0 12px 12px;padding-' . ($align === 'right' ? 'left:0;padding-right:12px' : 'right:0') . '"><div style="width:34px;height:34px;border-radius:10px;background:#FDF6E3;font-size:17px;line-height:34px;text-align:center">' . $s['icon'] . '</div></td>'
                . '<td valign="top" style="padding:11px 12px;text-align:' . $align . '"><div style="font-weight:bold;font-size:14px;color:#0B1F33">' . $e($name) . '</div><div style="font-size:12.5px;color:#64748B;line-height:1.5">' . $e($desc) . '</div></td></tr></table></td>';
        }
        if (!$cells) {
            return '';
        }
        $rows = '';
        foreach (array_chunk($cells, 2) as $pair) {
            $rows .= '<tr>' . implode('', $pair) . (count($pair) === 1 ? '<td width="50%"></td>' : '') . '</tr>';
        }
        return '<div style="margin:0 6px 8px;font-size:13px;font-weight:bold;letter-spacing:.3px;color:#64748B;text-align:' . $align . '">' . $e($ui['also']) . '</div><table role="presentation" cellpadding="0" cellspacing="0" width="100%">' . $rows . '</table>';
    }

    /** كارت الفحص في الإيميل: الدرجة قدام متوسط المنافسين (أول رسالة بس) + نواقص المسار واحدة واحدة */
    private function auditCard(array $lead, string $track, string $lang, bool $withScore): ?array
    {
        $data = json_decode((string) ($lead['data_json'] ?? ''), true) ?: [];
        $mine = array_values(array_filter((array) ($data['audit']['gaps'] ?? []), static fn ($g) => ($g['track'] ?? '') === $track));
        if (!$mine) {
            return null;
        }
        $items = [];
        foreach (array_slice($mine, 0, 3) as $g) {
            if (($t = OutreachAudit::gapText($g, $lead, $lang)) !== '') {
                $items[] = ['text' => $t, 'sev' => (int) ($g['sev'] ?? 5)];
            }
        }
        $score = $withScore && isset($data['audit']['score']['total']) ? (int) $data['audit']['score']['total'] : null;
        $cat = trim((string) explode(',', (string) ($lead['category'] ?? ''))[0]);
        return [
            'items' => $items,
            'score' => $score,
            'avg' => $score !== null ? $this->peerScore((string) ($lead['city'] ?? ''), $cat) : null,
            'city' => (string) ($lead['city'] ?? ''),
            'company' => (string) ($lead['company'] ?? ''),
        ];
    }

    /**
     * "اللي لاحظناه" من الفحص الاحترافي: أهم نقصين في المسار ده بأرقامهم، + الدرجة الرقمية مقارنة بالمنافسين
     * (فاضي = الشركة اتفحصت بالطريقة القديمة، بنرجع للنص العام)
     */
    private function auditEvidence(array $lead, string $track, string $lang, bool $withScore): string
    {
        $data = json_decode((string) ($lead['data_json'] ?? ''), true) ?: [];
        $gaps = (array) ($data['audit']['gaps'] ?? []);
        $mine = array_values(array_filter($gaps, static fn ($g) => ($g['track'] ?? '') === $track));
        if (!$mine) {
            return '';
        }
        $out = implode(' ', array_filter(array_map(static fn ($g) => OutreachAudit::gapText($g, $lead, $lang), array_slice($mine, 0, 2))));
        $score = $data['audit']['score']['total'] ?? null;
        if ($withScore && $score !== null) {
            $cat = trim((string) explode(',', (string) ($lead['category'] ?? ''))[0]);
            $avg = $this->peerScore((string) ($lead['city'] ?? ''), $cat);
            $vs = $avg !== null && $avg > $score
                ? strtr(OutreachAudit::SCORE_VS[$lang] ?? OutreachAudit::SCORE_VS['en'], ['{category}' => $cat, '{city}' => (string) ($lead['city'] ?? ''), '{avg}' => (string) $avg])
                : '';
            $out = strtr(OutreachAudit::SCORE_LINE[$lang] ?? OutreachAudit::SCORE_LINE['en'], ['{company}' => (string) $lead['company'], '{score}' => (string) (int) $score, '{vs}' => $vs]) . ' ' . $out;
        }
        return $out;
    }

    /** متوسط الدرجة الرقمية لنفس المدينة ونفس النشاط (5 شركات على الأقل) */
    private function peerScore(string $city, string $category): ?int
    {
        static $cache = [];
        $key = $city . '|' . $category;
        if (!array_key_exists($key, $cache)) {
            try {
                $r = $this->q("SELECT AVG(score) a, COUNT(*) n FROM platform_leads WHERE city=? AND SUBSTRING_INDEX(category, ',', 1)=? AND score IS NOT NULL AND status<>'invalid'", [$city, $category])->fetch(PDO::FETCH_ASSOC);
                $cache[$key] = $r && (int) $r['n'] >= 5 ? (int) round((float) $r['a']) : null;
            } catch (Throwable $e) {
                $cache[$key] = null;
            }
        }
        return $cache[$key];
    }

    /** أول سطر شخصي بالذكاء الاصطناعي (لو فشل: من غيره، الدليل بالأرقام كفاية) */
    private function aiOpener(array $lead, string $lang, array $set, ?callable $ai): string
    {
        if (($set['ai_opener'] ?? '1') === '0') {
            return '';
        }
        $ai ??= static function (string $prompt): ?string {
            if (!class_exists('AiText')) {
                return null;
            }
            $r = AiText::generate($prompt, ['task' => 'platform_outreach', 'maxOutputTokens' => 600, 'temperature' => 0.7]);
            return !empty($r['success']) ? (string) $r['data'] : null;
        };
        $data = json_decode((string) ($lead['data_json'] ?? ''), true) ?: [];
        $facts = array_filter([
            'company' => $lead['company'] ?? null, 'category' => $lead['category'] ?? null, 'city' => $lead['city'] ?? null, 'country' => $lead['country'] ?? null,
            'rating' => $lead['rating'] ?? null, 'reviews' => $lead['reviews'] ?? null, 'ranking' => $data['ta']['ranking'] ?? null, 'travellers_choice' => !empty($data['ta']['award']) ? 'yes' : null,
        ], static fn ($v) => $v !== null && $v !== '');
        $language = ['ar' => 'simple Arabic that sounds natural to Egyptians and Gulf readers', 'en' => 'English', 'tr' => 'natural Turkish'][$lang] ?? 'English';
        $prompt = 'Write ONE short, warm, specific opening sentence (max 22 words) for a cold email to this travel company, in ' . $language
            . '. Compliment something real from the facts (rating, reviews, ranking, city, type of activity). Do not invent facts, do not mention AI, no greeting, no emojis, no quotes. Facts: ' . json_encode($facts, JSON_UNESCAPED_UNICODE);
        try {
            $line = trim((string) preg_replace('/^["\'«]|["\'»]$/u', '', trim((string) $ai($prompt))));
            // جملة كاملة بس: الموديل ساعات بيقف في نص الكلمة ("İstanbul turlarınızda mis")
            if (self::completeSentence($line) && mb_strlen($line) <= 200 && strpos($line, "\n") === false && !preg_match('/\{\{|\[|\bAI\b|ذكاء|yapay/iu', $line)) {
                return $line;
            }
        } catch (Throwable $e) {
        error_log('[PlatformOutreachService] ignored error: ' . $e->getMessage());
        }
        return '';
    }

    /** سطر الافتتاح لازم يكون جملة كاملة: 6 كلمات على الأقل وبتخلص بعلامة ترقيم */
    public static function completeSentence(string $line): bool
    {
        $line = trim($line);
        return $line !== '' && preg_match('/[.!?؟。…]$/u', $line) === 1 && count(preg_split('/\s+/u', $line)) >= 6;
    }

    private static function toHtml(string $text, string $click, string $link, string $lang): string
    {
        $out = '';
        foreach (preg_split("/\n{2,}/", trim($text)) as $para) {
            $lines = array_map(static fn ($l) => htmlspecialchars($l), explode("\n", $para));
            $p = implode('<br>', $lines);
            $btn = '<a href="' . htmlspecialchars($click) . '" style="color:#0E4D64;font-weight:bold">' . ($lang === 'ar' ? 'جرّب Tourfecto مجانًا' : 'Try Tourfecto free') . '</a>';
            $p = str_replace('__LINK__', $btn, $p);
            $out .= '<p style="margin:0 0 14px">' . $p . '</p>';
        }
        return $out;
    }

    private static function withParams(string $url, array $params): string
    {
        return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
    }

    /** أول سطر شخصي: من تقييم جوجل، أو جملة يكتبها الذكاء الصناعي */
    private function opener(array $lead, string $lang, array $set, ?callable $ai): string
    {
        if (($set['ai_opener'] ?? '1') !== '0') {
            $ai ??= static function (string $prompt): ?string {
                if (!class_exists('AiText')) {
                    return null;
                }
                $r = AiText::generate($prompt, ['task' => 'platform_outreach', 'maxOutputTokens' => 600, 'temperature' => 0.7]);
                return !empty($r['success']) ? (string) $r['data'] : null;
            };
            $facts = array_filter(['company' => $lead['company'], 'category' => $lead['category'], 'city' => $lead['city'], 'country' => $lead['country'], 'google_rating' => $lead['rating'], 'google_reviews' => $lead['reviews'], 'website' => $lead['domain']]);
            $prompt = 'Write ONE short, warm, specific opening sentence (max 25 words) for a cold email to this travel company, '
                . ($lang === 'ar' ? 'in simple Egyptian-friendly Arabic' : 'in English')
                . '. Compliment something real from the facts (rating, reviews, city, type of tours). Do not invent facts, do not mention AI, no greeting, no emojis, no quotes. Facts: ' . json_encode($facts, JSON_UNESCAPED_UNICODE);
            try {
                $line = $ai($prompt);
                $line = trim((string) preg_replace('/^["\'«]|["\'»]$/u', '', trim((string) $line)));
                if (self::completeSentence($line) && mb_strlen($line) <= 220 && strpos($line, "\n") === false && !preg_match('/\{\{|\[|AI|ذكاء/u', $line)) {
                    return $line;
                }
            } catch (Throwable $e) {
            error_log('[PlatformOutreachService] ignored error: ' . $e->getMessage());
            }
        }
        if ($lead['rating'] !== null && (int) $lead['reviews'] >= 5) {
            $r = rtrim(rtrim(number_format((float) $lead['rating'], 1), '0'), '.');
            return $lang === 'ar'
                ? 'شفت إن تقييمكم ' . $r . ' من ' . (int) $lead['reviews'] . ' تقييم على جوجل' . ($lead['city'] ? ' في ' . $lead['city'] : '') . '، وده مجهود يستاهل يوصل لعملاء أكتر.'
                : 'I noticed your ' . $r . '-star rating from ' . (int) $lead['reviews'] . ' Google reviews' . ($lead['city'] ? ' in ' . $lead['city'] : '') . ', which is the kind of reputation that deserves more bookings.';
        }
        return $lang === 'ar' ? 'شفت شركتكم وأنا بدوّر على شركات سياحة' . ($lead['city'] ? ' في ' . $lead['city'] : '') . ' شغلها مميز.' : 'I came across your company while looking for standout travel businesses' . ($lead['city'] ? ' in ' . $lead['city'] : '') . '.';
    }

    // ------------------------------------------------------------------ الإرسال

    /** الحد المسموح النهارده (بيزيد تدريجيًا عشان الإيميل مايتعلّمش سبام) */
    public function todayLimit(array $set): int
    {
        $max = max(1, (int) $set['daily_limit']);
        $first = (string) $set['first_send_date'];
        $days = $first !== '' ? max(0, (int) floor((time() - strtotime($first)) / 86400)) : 0;
        return min($max, 15 + 10 * $days);
    }

    public function sentToday(array $set): int
    {
        $tz = new DateTimeZone($set['timezone'] ?: 'Africa/Cairo');
        $start = (new DateTimeImmutable('today', $tz))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        return (int) $this->q("SELECT COUNT(*) FROM platform_lead_messages WHERE status='sent' AND sent_at>=?", [$start])->fetchColumn();
    }

    public function inWindow(array $set, ?DateTimeImmutable $now = null): bool
    {
        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone($set['timezone'] ?: 'Africa/Cairo'));
        $h = (int) $now->format('G');
        $skip = array_filter(array_map('trim', explode(',', (string) $set['skip_days'])), 'strlen');
        return $h >= (int) $set['hour_from'] && $h < (int) $set['hour_to'] && !in_array($now->format('w'), $skip, true);
    }

    /**
     * دورة إرسال (من الكرون كل 5 دقايق)
     * @param callable|null $send للاختبار: fn(email,name,subject,html,headers):array
     */
    public function run(?callable $send = null, ?callable $ai = null, bool $force = false): array
    {
        $set = $this->getSettings();
        $this->markSignedUp();
        $res = ['sent' => 0, 'failed' => 0, 'reason' => null, 'enriched' => 0];
        if ($set['enabled'] !== '1' && !$force) {
            $res['reason'] = 'disabled';
            return $res;
        }
        if (!$force && !$this->inWindow($set)) {
            $res['reason'] = 'outside_hours';
            return $res;
        }
        if ($this->tooManyBounces()) {
            $this->settings->set('outreach_enabled', '0');
            $this->settings->set('outreach_paused_reason', 'bounces');
            $res['reason'] = 'paused_bounces';
            return $res;
        }
        $left = $this->todayLimit($set) - $this->sentToday($set);
        if ($left <= 0) {
            $res['reason'] = 'daily_limit';
            return $res;
        }
        $mailer = null;
        if (!$send) {
            $mailer = new Mailer();
            if ($set['smtp_host'] !== '') {
                $mailer->configure(['host' => $set['smtp_host'], 'port' => (int) $set['smtp_port'], 'username' => $set['smtp_username'], 'password' => $set['smtp_password'], 'encryption' => $set['smtp_encryption']]);
            }
            $mailer->configure(['from_email' => $set['from_email'], 'from_name' => $set['sender_name']]);
            if (method_exists($mailer, 'context')) {
                $mailer->context('platform_outreach');
            }
            $send = static fn ($to, $name, $subject, $html, $headers) => $mailer->send($to, $name, $subject, $html, $headers);
        }
        $gaps = array_map('intval', explode(',', (string) $set['step_gaps'])) + [0 => 3, 1 => 4];
        $batch = min($left, max(1, (int) $set['per_run']));
        // أول رسالة: لازم الشركة تكون اتشخّصت (عشان الرسالة تبقى عن مشكلتها) واتوافق عليها لو الموافقة مفعّلة
        $approval = ($set['require_approval'] ?? '1') !== '0' ? ' AND approved=1' : '';
        $rows = $this->q("SELECT * FROM platform_leads WHERE email IS NOT NULL AND ((status='new' AND step=0 AND track IS NOT NULL{$approval}) OR (status='contacted' AND step BETWEEN 1 AND 4 AND next_send_at<=NOW())) ORDER BY step DESC, (rating IS NULL), reviews DESC, id LIMIT " . (int) $batch)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $lead) {
            $step = (int) $lead['step'] + 1;
            // نفس الشركة (نفس الدومين) ردّت أو سجّلت أو ألغت: مانكمّلش على باقي إيميلاتها
            if ($lead['domain'] && $this->q("SELECT 1 FROM platform_leads WHERE domain=? AND id<>? AND status IN ('replied','interested','signed_up','unsubscribed') LIMIT 1", [$lead['domain'], $lead['id']])->fetchColumn()) {
                $this->q("UPDATE platform_leads SET status='paused',notes=CONCAT(COALESCE(notes,''),' [same company already handled]') WHERE id=?", [$lead['id']]);
                continue;
            }
            if ($this->checkMx && !self::domainAcceptsMail((string) $lead['email'])) {
                $this->q("UPDATE platform_leads SET status='invalid' WHERE id=?", [$lead['id']]);
                continue;
            }
            $msg = $this->compose($lead, $step, $set, $ai);
            $headers = [
                'List-Unsubscribe' => '<' . $msg['unsubscribe'] . '>' . ($set['reply_to'] || $set['from_email'] ? ', <mailto:' . ($set['reply_to'] ?: $set['from_email']) . '?subject=unsubscribe>' : ''),
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ];
            if ($set['reply_to'] !== '') {
                $headers['Reply-To'] = $set['reply_to'];
            }
            $r = $send((string) $lead['email'], (string) $lead['company'], $msg['subject'], $msg['html'], $headers);
            $ok = !empty($r['success']);
            $this->q('INSERT INTO platform_lead_messages(lead_id,step,subject,status,error,track) VALUES(?,?,?,?,?,?)', [$lead['id'], $step, mb_substr($msg['subject'], 0, 255), $ok ? 'sent' : 'failed', $ok ? null : mb_substr((string) ($r['error'] ?? ''), 0, 500), $msg['track'] ?? null]);
            if ($ok) {
                $res['sent']++;
                $last = self::lastStep($lead);
                if ($step >= $last) {
                    $next = null;
                } elseif ($step === 3) {
                    // المسار الأول خلص من غير رد: المسار التاني بعد فترة أطول ومن زاوية مختلفة
                    $next = date('Y-m-d H:i:s', time() + 86400 * max(3, (int) ($set['phase2_gap'] ?? 14)));
                } else {
                    $next = date('Y-m-d H:i:s', time() + 86400 * max(1, $gaps[$step === 4 ? 1 : $step - 1] ?? 3));
                }
                $this->q('UPDATE platform_leads SET step=?,status=?,phase=?,last_sent_at=NOW(),next_send_at=? WHERE id=?', [$step, $step < $last ? 'contacted' : 'finished', $step >= 3 && $last === 5 ? 2 : 1, $next, $lead['id']]);
                if ($set['first_send_date'] === '') {
                    $this->settings->set('outreach_first_send_date', date('Y-m-d'));
                    $set['first_send_date'] = date('Y-m-d');
                }
            } else {
                $res['failed']++;
                // السيرفر رفض الإيميل نفسه (مش موجود): مانبعتلوش تاني
                if (preg_match('/\b(550|551|553|554)\b|user unknown|does not exist|no such user|mailbox unavailable|invalid recipient/i', (string) ($r['error'] ?? ''))) {
                    $this->q("UPDATE platform_leads SET status='bounced' WHERE id=?", [$lead['id']]);
                }
            }
        }
        return $res;
    }

    private static function domainAcceptsMail(string $email): bool
    {
        $host = substr(strrchr($email, '@') ?: '', 1);
        if ($host === '' || !function_exists('checkdnsrr')) {
            return $host !== '';
        }
        static $cache = [];
        return $cache[$host] ??= (checkdnsrr($host, 'MX') || checkdnsrr($host, 'A'));
    }

    /** لو أكتر من 8% من رسايل آخر أسبوع رجعت: نوقف قبل ما الدومين يتحرق */
    private function tooManyBounces(): bool
    {
        $sent = (int) $this->q("SELECT COUNT(*) FROM platform_lead_messages WHERE status='sent' AND sent_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();
        $bounced = (int) $this->q("SELECT COUNT(*) FROM platform_leads WHERE status='bounced' AND updated_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();
        return $sent >= 30 && $bounced / max(1, $sent) > 0.08;
    }

    /** اللي سجّل في المنصة (بنفس الإيميل أو نفس دومين الموقع) بتقف رسايله */
    public function markSignedUp(): int
    {
        try {
            $n = $this->q("UPDATE platform_leads l JOIN users u ON LOWER(u.email)=LOWER(l.email) SET l.status='signed_up',l.next_send_at=NULL WHERE l.status NOT IN ('signed_up','unsubscribed')")->rowCount();
            $n += $this->q("UPDATE platform_leads l JOIN websites w ON l.domain IS NOT NULL AND LOWER(REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(w.main_url,'://',-1),'/',1),'www.',''))=l.domain SET l.status='signed_up',l.next_send_at=NULL WHERE l.status NOT IN ('signed_up','unsubscribed')")->rowCount();
            return $n;
        } catch (Throwable $e) {
            return 0;
        }
    }

    // ------------------------------------------------------------------ التتبع العام

    public function leadByToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        return $this->q('SELECT * FROM platform_leads WHERE token=?', [$token])->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function unsubscribe(string $token): bool
    {
        $l = $this->leadByToken($token);
        if (!$l) {
            return false;
        }
        $this->q("UPDATE platform_leads SET status='unsubscribed',next_send_at=NULL WHERE id=?", [$l['id']]);
        if ($l['domain']) {
            $this->q("UPDATE platform_leads SET status='unsubscribed',next_send_at=NULL WHERE domain=? AND status IN ('new','no_email','contacted','queued')", [$l['domain']]);
        }
        return true;
    }

    public function track(string $token, int $step, string $kind, bool $record = true): ?array
    {
        $l = $this->leadByToken($token);
        if (!$l || !$record) {
            return $l;
        }
        $col = $kind === 'click' ? 'clicked_at' : 'opened_at';
        $this->q("UPDATE platform_leads SET $col=COALESCE($col,NOW())" . ($kind === 'click' && in_array($l['status'], ['contacted', 'finished'], true) ? ",status='interested'" : '') . ' WHERE id=?', [$l['id']]);
        $this->q("UPDATE platform_lead_messages SET $col=COALESCE($col,NOW()) WHERE lead_id=? AND step=?", [$l['id'], $step]);
        return $l;
    }

    /**
     * بيانات صفحة التقرير المجاني (/p/r/{token}): أهم 3 احتياجات بأرقام الشركة والخدمات اللي بتحلها.
     * فتح التقرير مابيوقفش الرسايل (اللي شاف ومسجّلش بتوصله المتابعة عادي)، بس بيتسجّل للتعلّم.
     */
    public function report(string $token, bool $record = true): ?array
    {
        $l = $this->leadByToken($token);
        if (!$l || !class_exists('OutreachNeeds')) {
            return null;
        }
        if ($record) {
            $this->q('UPDATE platform_leads SET report_viewed_at=COALESCE(report_viewed_at,NOW()) WHERE id=?', [$l['id']]);
        }
        $lang = in_array($l['language'], OutreachNeeds::LANGS, true) ? $l['language'] : 'en';
        $needs = json_decode((string) ($l['needs_json'] ?? ''), true) ?: [];
        if (!$needs) {
            $data = json_decode((string) ($l['data_json'] ?? ''), true) ?: [];
            $needs = OutreachNeeds::diagnose($l, (array) ($data['ta'] ?? []), $data['site'] ?? null);
        }
        $items = [];
        foreach (array_slice($needs, 0, 3) as $n) {
            $items[] = [
                'track' => $n['track'],
                'label' => OutreachNeeds::trackLabel($n['track'], $lang),
                'finding' => OutreachNeeds::evidence($n['track'], (array) ($n['facts'] ?? []), $l, $lang),
                'services' => OutreachNeeds::services($n['track'], $lang),
            ];
        }
        $set = $this->getSettings();
        // الفحص الاحترافي: الدرجة والمحاور وكل نقص بالخدمة اللي بتحله، والمنافسين
        $audit = null;
        $data = json_decode((string) ($l['data_json'] ?? ''), true) ?: [];
        if (!empty($data['audit']['score'])) {
            $a = $data['audit'];
            $cat = trim((string) explode(',', (string) ($l['category'] ?? ''))[0]);
            $gaps = [];
            foreach ((array) $a['gaps'] as $g) {
                $gaps[] = [
                    'pillar' => $g['pillar'],
                    'pillar_label' => OutreachAudit::pillarLabel($g['pillar'], $lang),
                    'sev' => (int) $g['sev'],
                    'text' => OutreachAudit::gapText($g, $l, $lang),
                    'fix' => OutreachNeeds::SHORT[$g['track']][$lang] ?? null,
                    'icon' => OutreachNeeds::SHORT[$g['track']]['icon'] ?? '•',
                ];
            }
            $audit = [
                'score' => (int) $a['score']['total'],
                'peer_score' => $this->peerScore((string) ($l['city'] ?? ''), $cat),
                'pillars' => array_map(static fn ($p) => ['key' => $p, 'label' => OutreachAudit::pillarLabel($p, $lang), 'value' => (int) ($a['score']['pillars'][$p] ?? 100)], OutreachAudit::PILLARS),
                'gaps' => $gaps,
                'bench' => $a['bench'] ?? [],
                'google' => $a['google'] ?? [],
                'social' => array_keys((array) ($data['site']['social'] ?? [])),
                'category' => $cat,
            ];
        }
        return ['lead' => $l, 'lang' => $lang, 'items' => $items, 'audit' => $audit, 'has_whatsapp' => (string) $set['whatsapp_number'] !== ''];
    }

    /** زرار الواتساب: الشركة هي اللي بتبدأ المحادثة (ده المسموح بيه عند Meta) */
    public function whatsappUrl(string $token, int $step, bool $record = true): ?string
    {
        $set = $this->getSettings();
        $num = preg_replace('/\D/', '', (string) $set['whatsapp_number']);
        $l = $this->leadByToken($token);
        if ($num === '' || !$l) {
            return null;
        }
        if ($record) {
            $this->q("UPDATE platform_leads SET whatsapp_at=COALESCE(whatsapp_at,NOW()),clicked_at=COALESCE(clicked_at,NOW())" . (in_array($l['status'], ['new', 'contacted', 'finished'], true) ? ",status='interested',next_send_at=NULL" : '') . ' WHERE id=?', [$l['id']]);
            $this->q('UPDATE platform_lead_messages SET clicked_at=COALESCE(clicked_at,NOW()) WHERE lead_id=? AND step=?', [$l['id'], $step]);
        }
        $lang = in_array($l['language'], ['ar', 'en', 'tr'], true) ? $l['language'] : 'en';
        $topic = class_exists('OutreachNeeds') && $l['track'] ? OutreachNeeds::trackLabel((string) $l['track'], $lang) : 'Tourfecto';
        $msg = [
            'ar' => 'أهلًا، أنا من ' . $l['company'] . '. عايز أعرف أكتر عن Tourfecto (' . $topic . ').',
            'en' => "Hi, I'm from " . $l['company'] . '. I would like to know more about Tourfecto (' . $topic . ').',
            'tr' => 'Merhaba, ' . $l['company'] . " adına yazıyorum. Tourfecto hakkında daha fazla bilgi almak istiyorum (" . $topic . ').',
        ][$lang];
        return 'https://wa.me/' . $num . '?text=' . rawurlencode($msg);
    }

    /** موافقة الأدمن قبل أول رسالة: شركات محددة، أو كل اللي اتشخّصت ولسه مااتبعتلهاش */
    /** تصنيفات TripAdvisor/Google اللي معناها شركة رحلات (مش سبا ولا ملاهي ولا مطعم ولا نقل) */
    public const TOUR_CATEGORY_SQL = "(LOWER(COALESCE(category,'')) LIKE '%tour%' OR LOWER(COALESCE(category,'')) LIKE '%travel agen%' OR LOWER(COALESCE(category,'')) LIKE '%excursion%' OR LOWER(COALESCE(category,'')) LIKE '%safari%' OR LOWER(COALESCE(category,'')) LIKE '%cruise%')";

    public function approve(array $ids, bool $allScanned = false, string $country = '', bool $toursOnly = false): int
    {
        if ($allScanned) {
            $pending = "approved=0 AND step=0 AND status='new' AND track IS NOT NULL AND email IS NOT NULL" . ($toursOnly ? ' AND ' . self::TOUR_CATEGORY_SQL : '');
            $ids = preg_match('/^[A-Z]{2}$/', $country)
                ? $this->q("SELECT id FROM platform_leads WHERE {$pending} AND country=?", [$country])->fetchAll(PDO::FETCH_COLUMN)
                : $this->q("SELECT id FROM platform_leads WHERE {$pending}")->fetchAll(PDO::FETCH_COLUMN);
        }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        // AI CEO: البوابة القانونية لكل سوق (SalesCompliance). أي خطأ = مفيش موافقة (نقفل مش نفتح)
        if ($ids && class_exists('SalesEngine')) {
            try {
                $ids = (new SalesEngine())->compliantIds($ids);
            } catch (Throwable $e) {
                Logger::warning('Outreach approval blocked: compliance check failed', ['error' => $e->getMessage()]);
                return 0;
            }
        }
        if (!$ids) {
            return 0;
        }
        return $this->q('UPDATE platform_leads SET approved=1 WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids)->rowCount();
    }

    /** معاينة الرسالة الجاية لشركة معيّنة (بالظبط زي اللي هتتبعت) */
    public function previewLead(int $id, ?callable $ai = null, int $step = 0): array
    {
        $l = $this->q('SELECT * FROM platform_leads WHERE id=?', [$id])->fetch(PDO::FETCH_ASSOC);
        if (!$l) {
            throw new InvalidArgumentException('الشركة مش موجودة');
        }
        // من غير رقم: الرسالة الجاية؛ برقم: أي رسالة في المسار (1-3 الاحتياج الأول، 4-5 التاني)
        $step = $step > 0 ? min(self::lastStep($l), $step) : min(self::lastStep($l), (int) $l['step'] + 1);
        $m = $this->compose($l, $step, $this->getSettings(), $ai);
        [$track] = self::stepTrack($l, $step);
        return ['subject' => $m['subject'], 'html' => $m['html'], 'step' => $step, 'last_step' => self::lastStep($l), 'track' => $track, 'needs' => json_decode((string) ($l['needs_json'] ?? ''), true) ?: []];
    }

    /** أداء كل مسار (للتعلّم): اتبعت كام، فتحوا التقرير، ضغطوا، سجّلوا - لكل مسار ودولة */
    public function trackStats(): array
    {
        try {
            return $this->q("SELECT m.track, COALESCE(l.country,'?') country, COUNT(DISTINCT m.lead_id) leads, SUM(m.status='sent') sent,
                    COUNT(DISTINCT CASE WHEN l.report_viewed_at IS NOT NULL THEN l.id END) report_views,
                    COUNT(DISTINCT CASE WHEN m.clicked_at IS NOT NULL THEN l.id END) clicks,
                    COUNT(DISTINCT CASE WHEN l.status IN ('replied','interested') THEN l.id END) interested,
                    COUNT(DISTINCT CASE WHEN l.status='signed_up' THEN l.id END) signed_up
                FROM platform_lead_messages m JOIN platform_leads l ON l.id=m.lead_id
                WHERE m.track IS NOT NULL GROUP BY m.track, country ORDER BY signed_up DESC, interested DESC, sent DESC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    public function signupLinkFor(array $lead, int $step): string
    {
        $set = $this->getSettings();
        return self::withParams((string) $set['signup_url'], ['utm_source' => 'outreach', 'utm_medium' => 'email', 'utm_campaign' => 'step' . $step, 'ref' => $lead['token']]);
    }

    // ------------------------------------------------------------------ لوحة الأدمن

    public function stats(): array
    {
        $set = $this->getSettings();
        $by = $this->q('SELECT status,COUNT(*) n FROM platform_leads GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
        $msgs = $this->q("SELECT COUNT(*) sent,SUM(opened_at IS NOT NULL) opened,SUM(clicked_at IS NOT NULL) clicked FROM platform_lead_messages WHERE status='sent'")->fetch(PDO::FETCH_ASSOC);
        $countries = $this->q('SELECT COALESCE(country,\'?\') c,COUNT(*) n FROM platform_leads GROUP BY c ORDER BY n DESC')->fetchAll(PDO::FETCH_KEY_PAIR);
        $approval = ($set['require_approval'] ?? '1') !== '0' ? ' AND approved=1' : '';
        $pending = (int) $this->q("SELECT COUNT(*) FROM platform_leads WHERE email IS NOT NULL AND ((status='new' AND step=0 AND track IS NOT NULL{$approval}) OR (status='contacted' AND step BETWEEN 1 AND 4))")->fetchColumn();
        $tracks = $this->q("SELECT track,COUNT(*) n FROM platform_leads WHERE track IS NOT NULL GROUP BY track ORDER BY n DESC")->fetchAll(PDO::FETCH_KEY_PAIR);
        return [
            'unscanned' => (int) $this->q("SELECT COUNT(*) FROM platform_leads WHERE scanned_at IS NULL AND step=0 AND status IN ('new','no_email')")->fetchColumn(),
            'awaiting_approval' => (int) $this->q("SELECT COUNT(*) FROM platform_leads WHERE approved=0 AND step=0 AND status='new' AND track IS NOT NULL AND email IS NOT NULL")->fetchColumn(),
            'awaiting_tours' => (int) $this->q("SELECT COUNT(*) FROM platform_leads WHERE approved=0 AND step=0 AND status='new' AND track IS NOT NULL AND email IS NOT NULL AND " . self::TOUR_CATEGORY_SQL)->fetchColumn(),
            'tracks' => array_map('intval', $tracks),
            'track_labels' => class_exists('OutreachNeeds') ? array_combine(OutreachNeeds::TRACKS, array_map(static fn ($t) => OutreachNeeds::trackLabel($t, 'ar'), OutreachNeeds::TRACKS)) : [],
            'track_stats' => $this->trackStats(),
            'report_views' => (int) $this->q('SELECT COUNT(*) FROM platform_leads WHERE report_viewed_at IS NOT NULL')->fetchColumn(),
            'whatsapp_clicks' => (int) $this->q('SELECT COUNT(*) FROM platform_leads WHERE whatsapp_at IS NOT NULL')->fetchColumn(),
            'total' => (int) array_sum($by),
            'by_status' => array_map('intval', $by),
            'messages' => ['sent' => (int) $msgs['sent'], 'opened' => (int) $msgs['opened'], 'clicked' => (int) $msgs['clicked']],
            'today' => ['sent' => $this->sentToday($set), 'limit' => $this->todayLimit($set)],
            'pending' => $pending,
            'countries' => $countries,
            'country_stats' => $this->countryStats(),
            'in_window' => $this->inWindow($set),
            'paused_reason' => $this->settings->get('outreach_paused_reason', ''),
            'last_run' => $this->settings->get('outreach_last_run', ''),
        ];
    }

    /** تصنيف الدول: لكل دولة عدد الشركات ووصلنا لفين معاها وأكتر مدنها والاحتياج الغالب فيها */
    public function countryStats(): array
    {
        try {
            $rows = $this->q("SELECT COALESCE(country,'?') c, COUNT(*) total,
                    SUM(email IS NOT NULL AND status<>'invalid') with_email, SUM(status='invalid') invalid,
                    SUM(approved=0 AND step=0 AND status='new' AND track IS NOT NULL AND email IS NOT NULL) awaiting,
                    SUM(approved=1 AND step=0 AND status='new') approved, SUM(step>0) contacted,
                    SUM(report_viewed_at IS NOT NULL) reports, SUM(clicked_at IS NOT NULL) clicks,
                    SUM(status IN ('replied','interested')) interested, SUM(status='signed_up') signed_up,
                    ROUND(AVG(NULLIF(score,0))) avg_score
                FROM platform_leads GROUP BY c ORDER BY total DESC")->fetchAll(PDO::FETCH_ASSOC);
            $cities = [];
            foreach ($this->q("SELECT COALESCE(country,'?') c, city, COUNT(*) n FROM platform_leads WHERE city IS NOT NULL AND city<>'' GROUP BY c, city ORDER BY n DESC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cities[$r['c']][$r['city']] = (int) $r['n'];
            }
            $tracks = [];
            foreach ($this->q("SELECT COALESCE(country,'?') c, track, COUNT(*) n FROM platform_leads WHERE track IS NOT NULL AND status<>'invalid' GROUP BY c, track ORDER BY n DESC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $tracks[$r['c']][$r['track']] = (int) $r['n'];
            }
        } catch (Throwable $e) {
            return [];
        }
        return array_map(static function ($r) use ($cities, $tracks) {
            $out = ['country' => $r['c']];
            foreach (['total', 'with_email', 'invalid', 'awaiting', 'approved', 'contacted', 'reports', 'clicks', 'interested', 'signed_up'] as $k) {
                $out[$k] = (int) $r[$k];
            }
            $out['avg_score'] = $r['avg_score'] !== null ? (int) $r['avg_score'] : null;
            $out['cities'] = $cities[$r['c']] ?? [];
            $out['tracks'] = array_slice($tracks[$r['c']] ?? [], 0, 3, true);
            return $out;
        }, $rows);
    }

    /** مرة واحدة: الشركات القديمة اللي اتستوردت من غير دولة ← من التليفون أو الدومين */
    private function backfillCountries(): void
    {
        $rows = $this->q("SELECT id, phone, domain FROM platform_leads WHERE country IS NULL LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if ($c = self::guessCountry($r['phone'], $r['domain'])) {
                // اللغة بتتغير بس للي لسه مااتبعتلهاش حاجة
                $lang = $c === 'TR' ? 'tr' : (in_array($c, self::ARAB, true) ? 'ar' : 'en');
                $this->q('UPDATE platform_leads SET country=?, language=IF(step=0, ?, language) WHERE id=?', [$c, $lang, $r['id']]);
            }
        }
    }

    public function leads(array $f): array
    {
        $where = ['1=1'];
        $args = [];
        if (!empty($f['status']) && in_array($f['status'], self::STATUSES, true)) {
            $where[] = 'status=?';
            $args[] = $f['status'];
        }
        if (($f['country'] ?? '') === '?') {
            $where[] = 'country IS NULL';
        } elseif (!empty($f['country']) && preg_match('/^[A-Z]{2}$/', (string) $f['country'])) {
            $where[] = 'country=?';
            $args[] = $f['country'];
        }
        if (!empty($f['city']) && is_string($f['city'])) {
            $where[] = 'city=?';
            $args[] = mb_substr($f['city'], 0, 120);
        }
        if (!empty($f['track']) && class_exists('OutreachNeeds') && in_array($f['track'], OutreachNeeds::TRACKS, true)) {
            $where[] = 'track=?';
            $args[] = $f['track'];
        }
        if (($f['approval'] ?? '') === 'pending') {
            $where[] = "approved=0 AND step=0 AND status='new' AND track IS NOT NULL AND email IS NOT NULL";
        }
        if (!empty($f['q'])) {
            $where[] = '(company LIKE ? OR email LIKE ? OR city LIKE ? OR domain LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_substr((string) $f['q'], 0, 80)) . '%';
            array_push($args, $like, $like, $like, $like);
        }
        $page = max(1, (int) ($f['page'] ?? 1));
        $sql = 'SELECT id,company,email,phone,website,city,country,category,rating,reviews,language,status,step,next_send_at,last_sent_at,opened_at,clicked_at,maps_url,notes,track,track2,phase,approved,scanned_at,report_viewed_at,whatsapp_at,needs_json FROM platform_leads WHERE ' . implode(' AND ', $where);
        $total = (int) $this->q('SELECT COUNT(*) FROM platform_leads WHERE ' . implode(' AND ', $where), $args)->fetchColumn();
        $rows = $this->q($sql . ' ORDER BY updated_at DESC,id DESC LIMIT 50 OFFSET ' . (($page - 1) * 50), $args)->fetchAll(PDO::FETCH_ASSOC);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => (int) ceil($total / 50)];
    }

    public function setLeadStatus(int $id, string $status, ?string $note = null): void
    {
        if (!in_array($status, ['new', 'replied', 'interested', 'signed_up', 'unsubscribed', 'bounced', 'paused', 'contacted'], true)) {
            throw new InvalidArgumentException('حالة غير معروفة');
        }
        $l = $this->q('SELECT * FROM platform_leads WHERE id=?', [$id])->fetch(PDO::FETCH_ASSOC);
        if (!$l) {
            throw new InvalidArgumentException('الشركة مش موجودة');
        }
        if ($status === 'contacted' && (int) $l['step'] === 0) {
            $status = 'new'; // استئناف قبل أول رسالة
        }
        $this->q('UPDATE platform_leads SET status=?,next_send_at=IF(?=\'contacted\',COALESCE(next_send_at,NOW()),next_send_at),notes=COALESCE(?,notes) WHERE id=?', [$status, $status, $note, $id]);
    }

    public function deleteLead(int $id): void
    {
        $this->q('DELETE FROM platform_lead_messages WHERE lead_id=?', [$id]);
        $this->q('DELETE FROM platform_leads WHERE id=?', [$id]);
    }

    public function sampleLead(): array
    {
        $l = $this->q("SELECT * FROM platform_leads WHERE email IS NOT NULL ORDER BY (rating IS NULL),reviews DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return $l ?: ['id' => 0, 'company' => 'Nile Dream Tours', 'email' => null, 'city' => 'Luxor', 'country' => 'EG', 'category' => 'Tour agency', 'rating' => 4.8, 'reviews' => 214, 'domain' => 'niledreamtours.com', 'language' => 'ar', 'token' => str_repeat('0', 32)];
    }

    /** الموقع مافتحش: هل هو واقع فعلًا ('down') ولا بس رافض الزيارة الأوتوماتيك ('blocked')؟ */
    public static function siteStatus(string $url): string
    {
        $r = SafeHttp::exec($url, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        ], 5);
        return self::classifyStatus((int) $r['code'], (string) ($r['error'] ?? ''));
    }

    public static function classifyStatus(int $code, string $error): string
    {
        if ($code >= 500 || in_array($code, [404, 410], true)) {
            return 'down';
        }
        if ($code === 0) {
            // الدومين مابيردش (DNS) أو السيرفر مش بيقبل اتصال = واقع؛ "عنوان داخلي" وغيره = مانعرفش
            return preg_match('/DNS|resolve|Connection refused|Failed to connect|timed out|SSL/i', $error) ? 'down' : 'error';
        }
        return $code >= 400 ? 'blocked' : 'error';
    }

    /** @return array{body:string,url:string}|null */
    public static function httpFetch(string $url, int $maxBytes): ?array
    {
        $r = SafeHttp::exec($url, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TourfectoBot/1.0; +https://tourfecto.pro)',
        ], 5);
        if (!is_string($r['response']) || $r['code'] < 200 || $r['code'] >= 300 || $r['response'] === '' || strlen($r['response']) > $maxBytes) {
            return null;
        }
        return ['body' => $r['response'], 'url' => (string) $r['url']];
    }
}
