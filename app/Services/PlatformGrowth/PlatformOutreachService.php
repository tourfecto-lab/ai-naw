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
            'report_last_at' => 'DATETIME NULL',
            'report_views' => 'INT NOT NULL DEFAULT 0',
            'whatsapp_at' => 'DATETIME NULL',
            'score' => 'TINYINT NULL',
        ]);
        // to_email وbody_html: نسخة من الرسالة زي ما اتبعتت (للقسم «الحملات»)، والرسايل القديمة بتفضل من غيرهم
        $this->addColumns('platform_lead_messages', ['track' => 'VARCHAR(30) NULL', 'to_email' => 'VARCHAR(255) NULL', 'body_html' => 'MEDIUMTEXT NULL']);
        // مكتب التواصل (موظفة المبيعات): آخر نتيجة تواصل يدوي لكل شركة + سجل بكل المحاولات
        // priority: أولوية الإرسال 0-100 (مين محتاج فعلًا ومين شغّال فعلًا)، بتتحسب وقت الفحص وبتترتّب بيها قايمة المكتب
        $this->addColumns('platform_leads', ['desk_action' => 'VARCHAR(20) NULL', 'desk_note' => 'VARCHAR(500) NULL', 'desk_at' => 'DATETIME NULL', 'desk_by' => 'VARCHAR(120) NULL', 'priority' => 'TINYINT UNSIGNED NULL', 'desk_social' => 'VARCHAR(255) NULL', 'desk_weak' => 'TINYINT UNSIGNED NULL', 'desk_user_id' => 'BIGINT NULL', 'desk_reason' => 'VARCHAR(20) NULL', 'desk_followup_at' => 'DATETIME NULL', 'desk_tag' => 'VARCHAR(20) NULL']);
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS platform_desk_actions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lead_id INT NOT NULL,
            user_id BIGINT NULL,
            user_name VARCHAR(120) NOT NULL DEFAULT '',
            action VARCHAR(20) NOT NULL,
            note VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_pda_lead (lead_id),
            KEY idx_pda_created (created_at)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addColumns('platform_desk_actions', ['reason' => 'VARCHAR(20) NULL']);
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS platform_staff_comp (
            user_id BIGINT NOT NULL PRIMARY KEY,
            comp_type VARCHAR(12) NOT NULL DEFAULT 'none',
            commission_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
            monthly_salary DECIMAL(10,2) NOT NULL DEFAULT 0,
            note VARCHAR(255) NULL,
            updated_by VARCHAR(120) NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS platform_staff_profile (
            user_id BIGINT NOT NULL PRIMARY KEY,
            full_name VARCHAR(150) NULL,
            phone VARCHAR(40) NULL,
            job_title VARCHAR(100) NULL,
            start_date DATE NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'active',
            address VARCHAR(255) NULL,
            emergency_name VARCHAR(120) NULL,
            emergency_phone VARCHAR(40) NULL,
            national_id_enc TEXT NULL,
            pay_method VARCHAR(20) NULL,
            pay_account_enc TEXT NULL,
            internal_note VARCHAR(500) NULL,
            updated_by VARCHAR(120) NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->addColumns('platform_staff_profile', ['sign_name' => 'VARCHAR(40) NULL', 'recapture_at' => 'DATETIME NULL', 'daily_goal' => 'SMALLINT UNSIGNED NULL']);
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS platform_staff_docs (
            user_id BIGINT NOT NULL,
            side VARCHAR(5) NOT NULL,
            mime VARCHAR(20) NOT NULL,
            data_enc LONGTEXT NOT NULL,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, side)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS platform_staff_docs_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT NOT NULL,
            side VARCHAR(5) NOT NULL,
            mime VARCHAR(20) NOT NULL,
            data_enc LONGTEXT NOT NULL,
            uploaded_at DATETIME NOT NULL,
            archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_pdh_user (user_id)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS platform_staff_doc_views (
            id INT AUTO_INCREMENT PRIMARY KEY,
            viewer_id BIGINT NOT NULL,
            user_id BIGINT NOT NULL,
            side VARCHAR(5) NOT NULL,
            viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_pdv_user (user_id)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS platform_staff_location (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT NOT NULL,
            lat DECIMAL(9,6) NOT NULL,
            lng DECIMAL(9,6) NOT NULL,
            accuracy_m INT NULL,
            is_first TINYINT NOT NULL DEFAULT 0,
            address_lat DECIMAL(9,6) NULL,
            address_lng DECIMAL(9,6) NULL,
            address_for VARCHAR(40) NULL,
            captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_psl_user (user_id)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
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
        $text = trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $text));
        $rows = json_decode($text, true);
        if (!is_array($rows)) {
            $rows = self::parseCsvRows($text);
        }
        return $this->importRows(array_values($rows), 'import');
    }

    /**
     * CSV بتاع Apify: وصف بأسطر متعددة جوّه علامات اقتباس (fgetcsv بيقراه صح، قراءة سطر سطر كانت بتقطّعه)،
     * وأعمدة متداخلة بـ«/» (ratingHistogram/count1 · addressObj/city · subcategories/0) بتتفك لمصفوفات زي ما JSON بيجي،
     * وأسماء الأعمدة بتفضل بحروفها (numberOfReviews, webUrl) مع نسخة بحروف صغيرة للملفات القديمة.
     * @return array<int, array<string, mixed>>
     */
    public static function parseCsvRows(string $text): array
    {
        $h = fopen('php://temp', 'r+');
        // علامة BOM قدام عنوان بين علامات اقتباس بتخلّي fgetcsv يقرا العنوان الأول بالعلامات ("address")
        fwrite($h, (string) preg_replace('/^\xEF\xBB\xBF/', '', $text));
        rewind($h);
        $head = fgetcsv($h, null, ',', '"', '');
        if (!is_array($head) || $head === [null]) {
            fclose($h);
            return [];
        }
        $head = array_map(static fn ($x) => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $x)), $head);
        $rows = [];
        while (($vals = fgetcsv($h, null, ',', '"', '')) !== false) {
            if (count($vals) === 1 && trim((string) $vals[0]) === '') {
                continue;
            }
            $row = [];
            foreach ($head as $i => $key) {
                $v = (string) ($vals[$i] ?? '');
                if ($key === '') {
                    continue;
                }
                if (str_contains($key, '/')) {
                    if ($v !== '') { // خلايا فاضية في subcategories/5.. ماتبقاش عناصر
                        self::setNested($row, explode('/', $key), $v);
                    }
                    continue;
                }
                $row[$key] = $v;
                $lower = strtolower($key);
                if ($lower !== $key && !array_key_exists($lower, $row)) {
                    $row[$lower] = $v;
                }
            }
            $rows[] = $row;
        }
        fclose($h);
        return $rows;
    }

    /** a/b/0 => ['a' => ['b' => [0 => v]]] */
    private static function setNested(array &$arr, array $path, string $value): void
    {
        $key = array_shift($path);
        if ($path === []) {
            $arr[$key] = $value;
            return;
        }
        if (!isset($arr[$key]) || !is_array($arr[$key])) {
            $arr[$key] = [];
        }
        self::setNested($arr[$key], $path, $value);
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
                $existing = $this->q('SELECT id,status,step,data_json,country FROM platform_leads WHERE email=?', [$lead['email']])->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$existing && $lead['domain']) {
                $existing = $this->q('SELECT id,status,email,step,data_json,country FROM platform_leads WHERE domain=? LIMIT 1', [$lead['domain']])->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($existing && $lead['email'] && !empty($existing['email']) && strcasecmp($existing['email'], $lead['email']) !== 0) {
                    $existing = null; // نفس الشركة بإيميل تاني: نضيفه (بنقفل الرسايل لو حد منهم ردّ)
                }
            }
            // شركة من غير إيميل ولا موقع (بتتعرف بتليفونها أو اسمها ومدينتها): غير كده كل رفع للملف بيكرّرها
            if (!$existing && !$lead['email'] && !$lead['domain']) {
                if ($lead['phone']) {
                    $existing = $this->q('SELECT id,status,email,step,data_json FROM platform_leads WHERE phone=? AND status<>\'invalid\' ORDER BY id LIMIT 1', [$lead['phone']])->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                if (!$existing && $lead['company'] !== '' && $lead['city']) {
                    $existing = $this->q('SELECT id,status,email,step,data_json FROM platform_leads WHERE company=? AND city=? AND email IS NULL AND domain IS NULL AND status<>\'invalid\' ORDER BY id LIMIT 1', [$lead['company'], $lead['city']])->fetch(PDO::FETCH_ASSOC) ?: null;
                }
            }
            // نفس الدومين أو الإيميل لكن من بلد تانية (شركة اسمها زي اسمها في مصر وتركيا): مش نفس الشركة، فمانلمسش بياناتها (بيانات تريب أدفايزر بتاعة القاهرة كانت بتتحط على شركة في إسطنبول)
            if ($existing && !empty($existing['country']) && !empty($lead['country']) && strcasecmp((string) $existing['country'], (string) $lead['country']) !== 0) {
                $stats['skipped']++;
                continue;
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
        if ($this->settings->get('outreach_audit_v8', '') === '') {
            // v8: any WhatsApp mention / reserve button counts as present (JSON-escaped links, "Rezervasyon Yap"). v7: a site is 'down' only when sure (DNS/refused/5xx). v6: JS booking apps are detected; v5: Google rating kept only for a name-matched place
            $this->q("UPDATE platform_leads SET scanned_at=NULL WHERE step=0 AND status IN ('new','no_email')");
            $this->settings->set('outreach_audit_v8', date('Y-m-d H:i:s'));
        }
        // «فتح التقرير» المسجّل قبل فلتر روبوتات المعاينة: واتساب كان بيفتح اللينك بنفسه لحظة الإرسال، فأي فتح قريب من وقت الإرسال (من غير عدّاد) مش فتح من الشركة
        if ($this->settings->get('outreach_views_v1', '') === '') {
            $this->q("UPDATE platform_leads SET report_viewed_at=NULL, report_last_at=NULL WHERE COALESCE(report_views,0)=0 AND report_viewed_at IS NOT NULL AND desk_at IS NOT NULL AND report_viewed_at BETWEEN desk_at - INTERVAL 2 MINUTE AND desk_at + INTERVAL 15 MINUTE");
            $this->settings->set('outreach_views_v1', date('Y-m-d H:i:s'));
        }

        if ($this->settings->get('outreach_ta_geo_v1', '') === '') {
            $this->fixForeignTripadvisorData();
            $this->settings->set('outreach_ta_geo_v1', date('Y-m-d H:i:s'));
        }
        if ($this->settings->get('outreach_dedupe_v1', '') === '') {
            $this->mergeDuplicateLeads();
            $this->settings->set('outreach_dedupe_v1', date('Y-m-d H:i:s'));
        }
        $start = time();
        $done = 0;
        $rows = array_map([self::class, 'tidy'], $this->q("SELECT * FROM platform_leads WHERE scanned_at IS NULL AND step=0 AND status IN ('new','no_email') ORDER BY (email IS NULL), reviews DESC, id LIMIT " . (int) $limit)->fetchAll(PDO::FETCH_ASSOC));
        foreach ($rows as $l) {
            if (time() - $start > $budget) {
                break;
            }
            $data = json_decode((string) ($l['data_json'] ?? ''), true) ?: [];
            $this->audit($l, $data);
            $done++;
        }
        // شركات اتبعتلها إيميل قبل التصليحات: بيانات جوجل والموقع اللي في تقريرها بتتحدّث، من غير ما نغيّر مسار رسايل الإيميل اللي لسه جاية
        $rest = $limit - $done;
        if ($rest > 0 && time() - $start <= $budget) {
            $sent = array_map([self::class, 'tidy'], $this->q("SELECT * FROM platform_leads WHERE step>0 AND scanned_at IS NOT NULL AND status NOT IN ('invalid','unsubscribed','bounced') AND data_json LIKE '%\"audit\"%' AND data_json NOT LIKE '%\"v\":8%' ORDER BY id LIMIT " . (int) $rest)->fetchAll(PDO::FETCH_ASSOC));
            foreach ($sent as $l) {
                if (time() - $start > $budget) {
                    break;
                }
                $keep = [$l['track'] ?? null, $l['track2'] ?? null, $l['needs_json'] ?? null];
                $this->audit($l, json_decode((string) ($l['data_json'] ?? ''), true) ?: []);
                $this->q('UPDATE platform_leads SET track=?, track2=?, needs_json=? WHERE id=?', [$keep[0], $keep[1], $keep[2], $l['id']]);
                $done++;
            }
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
        $google = OutreachAudit::googleFacts(($this->places)(OutreachAudit::cleanName((string) $l['company']), $city), (string) $l['company']);
        // البحث بالاسم من غير مدينة ضعيف: «مالقيناش ملف» هنا مش دليل إن الشركة مالهاش ملف، فمنقولهاش في الرسالة ولا التقرير
        if ($city === '' && empty($google['found'])) {
            $google = ['checked' => false];
        }
        $bench = OutreachAudit::benchmark($l, $this->peers((string) ($l['city'] ?? '')));
        $ta = (array) ($data['ta'] ?? []);
        $gaps = OutreachAudit::gaps($l, $ta, $site, $google, $bench);
        $score = OutreachAudit::score($gaps, $site !== null && !empty($site['ok']));
        $data['site'] = $site;
        $data['audit'] = ['google' => $google, 'bench' => $bench, 'gaps' => $gaps, 'score' => $score, 'at' => date('Y-m-d H:i:s'), 'v' => 8];
        $needs = OutreachAudit::needsFromGaps($gaps);
        if (!$needs) {
            // مفيش نقص واضح: الرد على الزباين والظهور في جوجل بيفيدوا أي شركة سياحة
            $needs = [['track' => !empty($l['website']) ? 'seo' : 'website', 'score' => 10, 'facts' => ['gaps' => []]]];
        }
        $this->q('UPDATE platform_leads SET data_json=?,needs_json=?,track=?,track2=?,score=?,priority=?,desk_weak=?,scanned_at=NOW() WHERE id=?', [
            json_encode($data, JSON_UNESCAPED_UNICODE),
            json_encode(array_slice($needs, 0, 4), JSON_UNESCAPED_UNICODE),
            $needs[0]['track'] ?? null,
            $needs[1]['track'] ?? null,
            $score['total'],
            OutreachAudit::priority($l, $data)['score'],
            OutreachAudit::isWeak($gaps) ? 1 : 0,
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

    /**
     * بيانات تريب أدفايزر بتاعة بلد تانية اتحطت على شركة (g294201 = القاهرة على شركة في تركيا مثلًا، أو ترتيب بالعربي على شركة تركية): بتتشال وبتتفحص تاني.
     * @return int عدد الشركات اللي اتصلّحت
     */
    public function fixForeignTripadvisorData(): int
    {
        $n = 0;
        $rows = $this->q("SELECT id, country, language, data_json FROM platform_leads WHERE data_json LIKE '%\"ta\"%' AND country IS NOT NULL AND country<>'EG'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $data = json_decode((string) ($r['data_json'] ?? ''), true) ?: [];
            $ta = (array) ($data['ta'] ?? []);
            $blob = (string) ($ta['url'] ?? '') . ' ' . (string) ($ta['ranking'] ?? '') . ' ' . (string) ($data['maps_url'] ?? '');
            if (!$ta || !preg_match('/g294201|Cairo_Governorate|القاهرة/u', $blob)) {
                continue;
            }
            unset($data['ta']);
            $this->q('UPDATE platform_leads SET data_json=?, scanned_at=NULL, approved=0 WHERE id=?', [json_encode($data, JSON_UNESCAPED_UNICODE), $r['id']]);
            $n++;
        }
        return $n;
    }

    /**
     * شركات من غير إيميل ولا موقع اتكررت مع كل رفع للملف (نفس التليفون أو نفس الاسم والمدينة): نخلّي صف واحد
     * (اللي عليه إجراء موظف، وبعده الأبعد في التواصل، وبعده الأقدم) ونعلّم الباقي invalid
     * @return int عدد الصفوف المكرّرة اللي اتشالت
     */
    public function mergeDuplicateLeads(): int
    {
        $rows = $this->q("SELECT id,company,city,phone,step,desk_action FROM platform_leads WHERE email IS NULL AND domain IS NULL AND status<>'invalid'")->fetchAll(PDO::FETCH_ASSOC);
        $groups = [];
        foreach ($rows as $r) {
            $key = $r['phone'] ? 'p:' . $r['phone'] : 'n:' . mb_strtolower(trim((string) $r['company'])) . '|' . mb_strtolower(trim((string) $r['city']));
            $groups[$key][] = $r;
        }
        $removed = 0;
        foreach ($groups as $g) {
            if (count($g) < 2) {
                continue;
            }
            usort($g, static fn ($a, $b) => [$b['desk_action'] !== null && $b['desk_action'] !== '' ? 1 : 0, (int) $b['step'], (int) $a['id']] <=> [$a['desk_action'] !== null && $a['desk_action'] !== '' ? 1 : 0, (int) $a['step'], (int) $b['id']]);
            array_shift($g); // الصف اللي بنحتفظ بيه
            foreach ($g as $dup) {
                $this->q("UPDATE platform_leads SET status='invalid',approved=0,notes=CONCAT(COALESCE(notes,''),' [duplicate]') WHERE id=?", [$dup['id']]);
                $removed++;
            }
        }
        return $removed;
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
        $url = 'https://maps.googleapis.com/maps/api/place/findplacefromtext/json?inputtype=textquery&fields=' . rawurlencode('place_id,name,types,rating,user_ratings_total,opening_hours,business_status')
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
        $link = self::withParams((string) $set['signup_url'], ['utm_source' => 'outreach', 'utm_medium' => 'email', 'utm_campaign' => 'step' . $step, 'ref' => $lead['token']] + self::siteParam($lead));
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
            '__LINK__' => [$base . '/p/c/' . $lead['token'] . '/' . $step, $btn['signup'], self::withParams((string) $set['signup_url'], ['utm_source' => 'outreach', 'utm_medium' => 'email', 'utm_campaign' => $track . '_' . $step, 'ref' => $lead['token']] + self::siteParam($lead))],
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
        $value = $local < 3 ? self::valuePanel($lang, $links, strpos($text, '__LINK__') === false, $e, $align, $navy, $gold, $local === 1) : '';
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

    /** اسم الشركة من غير علامات الاتجاه المخفية، في كل مكان بيتعرض أو بيتبعت (الصف في قاعدة البيانات مابيتغيّرش) */
    public static function tidy(array $lead): array
    {
        if (isset($lead['company'])) {
            $lead['company'] = OutreachAudit::cleanName((string) $lead['company']);
        }
        return $lead;
    }

    private static function tidyOrNull(?array $lead): ?array
    {
        return $lead === null ? null : self::tidy($lead);
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

    /**
     * سطر العرض من إعدادات التجربة الحية (عدد الأيام وهل بتطلب كارت)، مش رقم مكتوب في الكود.
     * لو الإعدادات مش متاحة بنرجع فاضي بدل ما نكتب وعد مش متأكدين منه.
     */
    public static function trialOffer(string $lang, ?array $cfg = null, ?bool $cardRequired = null): string
    {
        try {
            if ($cfg === null) {
                $x = new PlanExtras();
                $cfg = $x->config();
                $cardRequired ??= $x->cardRequired();
            }
        } catch (Throwable $e) {
            return '';
        }
        $n = (int) ($cfg['trial_days'] ?? 0);
        if ($n < 1 || $cardRequired === null) {
            return '';
        }
        if ($lang === 'ar') {
            $days = $n === 1 ? 'يوم واحد' : ($n === 2 ? 'يومين' : ($n <= 10 ? $n . ' أيام' : $n . ' يومًا'));
            return 'جرّبوا Tourfecto ' . $days . ' مجانًا' . ($cardRequired ? '' : '، من غير كارت');
        }
        if ($lang === 'tr') {
            return 'Tourfecto\'yu ' . $n . ' gün ücretsiz deneyin' . ($cardRequired ? '' : ', kart gerekmez');
        }
        return 'Try Tourfecto free for ' . $n . ($n === 1 ? ' day' : ' days') . ($cardRequired ? '' : ', no card needed');
    }

    private static function valuePanel(string $lang, array $links, bool $withCta, callable $e, string $align, string $navy, string $gold, bool $compact = false): string
    {
        $v = self::VALUE_UI[$lang] ?? self::VALUE_UI['en'];
        $offer = self::trialOffer($lang);
        $offerHtml = $offer !== '' ? '<div style="margin:0 6px 14px;font-size:16px;font-weight:bold;color:' . $navy . ';text-align:' . $align . '">' . $e($offer) . '</div>' : '';
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
            . $offerHtml
            . ($compact ? '' : '<div style="margin:0 6px 4px;font-size:18px;font-weight:bold;color:' . $navy . ';text-align:' . $align . '">' . $e($v['title']) . '</div>'
            . '<div style="margin:0 6px 14px;font-size:13.5px;line-height:1.7;color:#475569;text-align:' . $align . '">' . $e($v['sub']) . '</div>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%">' . $grid . '</table>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-top:14px"><tr>' . $facts . '</tr></table>')
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

    /** موقع الشركة بيتبعت مع لينك التسجيل (نفس باراميتر «افحص موقعك مجانًا») فالتسجيل يكمّل على موقعها بدل ما تكتبه من الأول */
    public static function siteParam(array $lead): array
    {
        $d = strtolower(trim((string) ($lead['domain'] ?? '')));
        $d = (string) preg_replace('/^www\./', '', $d);
        return ($d !== '' && strlen($d) <= 120 && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $d)) ? ['site' => $d] : [];
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
        $rows = array_map([self::class, 'tidy'], $this->q("SELECT * FROM platform_leads WHERE email IS NOT NULL AND ((status='new' AND step=0 AND track IS NOT NULL{$approval}) OR (status='contacted' AND step BETWEEN 1 AND 4 AND next_send_at<=NOW())) ORDER BY step DESC, (rating IS NULL), reviews DESC, id LIMIT " . (int) $batch)->fetchAll(PDO::FETCH_ASSOC));
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
            $this->q('INSERT INTO platform_lead_messages(lead_id,step,subject,status,error,track,to_email,body_html) VALUES(?,?,?,?,?,?,?,?)', [$lead['id'], $step, mb_substr($msg['subject'], 0, 255), $ok ? 'sent' : 'failed', $ok ? null : mb_substr((string) ($r['error'] ?? ''), 0, 500), $msg['track'] ?? null, (string) $lead['email'], (string) $msg['html']]);
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
        return self::tidyOrNull($this->q('SELECT * FROM platform_leads WHERE token=?', [$token])->fetch(PDO::FETCH_ASSOC) ?: null);
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

    /** يوزر-إيجنت روبوت معاينة لينك أو زاحف: مش شركة فتحت التقرير بجد */
    public static function isLinkBot(string $ua): bool
    {
        return $ua === '' || (bool) preg_match('~WhatsApp|facebookexternalhit|Facebot|TelegramBot|Twitterbot|LinkedInBot|Slackbot|SkypeUriPreview|Googlebot|Google-InspectionTool|bingbot|Applebot|Discordbot|HeadlessChrome|python-requests|curl/|Go-http-client|bot|crawler|spider~i', $ua);
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
            $this->q('UPDATE platform_leads SET report_viewed_at=COALESCE(report_viewed_at,NOW()), report_last_at=NOW(), report_views=COALESCE(report_views,0)+1 WHERE id=?', [$l['id']]);
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
                'scorecard' => OutreachAudit::scorecard($l, $data),
                'category' => $cat, 'category_label' => OutreachAudit::publicCategory($cat) ?: (OutreachAudit::GENERIC_CATEGORY[$lang] ?? 'tour'),
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
        $l = self::tidyOrNull($this->q('SELECT * FROM platform_leads WHERE id=?', [$id])->fetch(PDO::FETCH_ASSOC) ?: null);
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

    // ------------------------------------------------------------------ الحملات اللي اتبعتت

    /** كل (دولة × مسار × رقم الرسالة) حملة بأرقامها، مع إجمالي كل اللي اتبعت */
    public function campaigns(): array
    {
        $cols = "SUM(m.status='sent') sent, SUM(m.status='failed') failed,
            COUNT(DISTINCT CASE WHEN m.status='sent' THEN m.lead_id END) recipients,
            SUM(m.opened_at IS NOT NULL) opened, SUM(m.clicked_at IS NOT NULL) clicked,
            COUNT(DISTINCT CASE WHEN l.report_viewed_at IS NOT NULL THEN l.id END) report_views,
            COUNT(DISTINCT CASE WHEN l.whatsapp_at IS NOT NULL THEN l.id END) whatsapp,
            COUNT(DISTINCT CASE WHEN l.status IN ('replied','interested') THEN l.id END) interested,
            COUNT(DISTINCT CASE WHEN l.status='signed_up' THEN l.id END) signed_up,
            COUNT(DISTINCT CASE WHEN l.status='unsubscribed' THEN l.id END) unsubscribed,
            COUNT(DISTINCT CASE WHEN l.status='bounced' THEN l.id END) bounced,
            MIN(m.sent_at) first_sent_at, MAX(m.sent_at) last_sent_at";
        $from = 'FROM platform_lead_messages m JOIN platform_leads l ON l.id=m.lead_id';
        $int = static function (array $r): array {
            foreach (['sent', 'failed', 'recipients', 'opened', 'clicked', 'report_views', 'whatsapp', 'interested', 'signed_up', 'unsubscribed', 'bounced'] as $k) {
                $r[$k] = (int) ($r[$k] ?? 0);
            }
            return $r;
        };
        try {
            $totals = $int($this->q("SELECT {$cols} {$from}")->fetch(PDO::FETCH_ASSOC) ?: []);
            $rows = $this->q("SELECT COALESCE(l.country,'?') country, COALESCE(m.track,'') track, m.step, {$cols} {$from}
                GROUP BY COALESCE(l.country,'?'), COALESCE(m.track,''), m.step ORDER BY MAX(m.sent_at) DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return ['totals' => $int([]), 'campaigns' => []];
        }
        return ['totals' => $totals, 'campaigns' => array_map(static fn ($r) => ['step' => (int) $r['step']] + $int($r), $rows)];
    }

    /** سجل الرسايل اللي اتبعتت (50 في الصفحة): فلاتر الدولة والمسار ورقم الرسالة والحالة والبحث */
    public function sentMessages(array $f): array
    {
        $where = ['1=1'];
        $args = [];
        if (($f['country'] ?? '') === '?') {
            $where[] = 'l.country IS NULL';
        } elseif (!empty($f['country']) && preg_match('/^[A-Z]{2}$/', (string) $f['country'])) {
            $where[] = 'l.country=?';
            $args[] = $f['country'];
        }
        if (isset($f['track']) && $f['track'] !== '') {
            $where[] = 'COALESCE(m.track,\'\')=?';
            $args[] = mb_substr((string) $f['track'], 0, 30);
        }
        if (!empty($f['step'])) {
            $where[] = 'm.step=?';
            $args[] = (int) $f['step'];
        }
        if (in_array($f['status'] ?? '', ['sent', 'failed'], true)) {
            $where[] = 'm.status=?';
            $args[] = $f['status'];
        }
        if (!empty($f['q'])) {
            $where[] = '(l.company LIKE ? OR l.email LIKE ? OR m.to_email LIKE ? OR m.subject LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_substr((string) $f['q'], 0, 80)) . '%';
            array_push($args, $like, $like, $like, $like);
        }
        $page = max(1, (int) ($f['page'] ?? 1));
        $from = ' FROM platform_lead_messages m JOIN platform_leads l ON l.id=m.lead_id WHERE ' . implode(' AND ', $where);
        $total = (int) $this->q('SELECT COUNT(*)' . $from, $args)->fetchColumn();
        $rows = $this->q('SELECT m.id, m.lead_id, l.company, COALESCE(m.to_email,l.email) to_email, l.country, l.city, m.step, m.track, m.subject, m.status, m.error, m.sent_at, m.opened_at, m.clicked_at,
                l.report_viewed_at, l.whatsapp_at, l.status lead_status, l.score, (m.body_html IS NOT NULL) has_body' . $from . ' ORDER BY m.sent_at DESC, m.id DESC LIMIT 50 OFFSET ' . (($page - 1) * 50), $args)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['company'] = OutreachAudit::cleanName((string) $r['company']);
            $r['has_body'] = (bool) $r['has_body'];
        }
        unset($r);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => (int) ceil($total / 50)];
    }

    /**
     * تفاصيل رسالة واحدة: المستلم، الأحداث (فتح، ضغط، تقرير، واتساب)، ونص الرسالة.
     * النص بيتخزّن وقت الإرسال؛ الرسايل اللي اتبعتت قبل كده بيتعمل نصها تاني من نفس القالب والبيانات (من غير سطر الذكاء الصناعي).
     */
    public function messageDetail(int $id): ?array
    {
        $m = $this->q('SELECT * FROM platform_lead_messages WHERE id=?', [$id])->fetch(PDO::FETCH_ASSOC);
        if (!$m) {
            return null;
        }
        $lead = self::tidyOrNull($this->q('SELECT * FROM platform_leads WHERE id=?', [(int) $m['lead_id']])->fetch(PDO::FETCH_ASSOC) ?: null);
        if (!$lead) {
            return null;
        }
        $html = (string) ($m['body_html'] ?? '');
        $source = 'stored';
        if ($html === '') {
            $set = $this->getSettings();
            $set['ai_opener'] = '0';
            $html = (string) $this->compose($lead, max(1, (int) $m['step']), $set, static fn () => null)['html'];
            $source = 'rendered_again';
        }
        $events = [['at' => $m['sent_at'], 'type' => $m['status'] === 'sent' ? 'sent' : 'failed', 'note' => (string) ($m['error'] ?? '')]];
        foreach (['opened_at' => 'opened', 'clicked_at' => 'clicked'] as $col => $type) {
            if (!empty($m[$col])) {
                $events[] = ['at' => $m[$col], 'type' => $type, 'note' => ''];
            }
        }
        foreach (['report_viewed_at' => 'report_viewed', 'whatsapp_at' => 'whatsapp'] as $col => $type) {
            if (!empty($lead[$col])) {
                $events[] = ['at' => $lead[$col], 'type' => $type, 'note' => ''];
            }
        }
        usort($events, static fn ($a, $b) => strcmp((string) $a['at'], (string) $b['at']));
        $siblings = $this->q('SELECT id, step, subject, status, sent_at FROM platform_lead_messages WHERE lead_id=? ORDER BY step, id', [(int) $m['lead_id']])->fetchAll(PDO::FETCH_ASSOC);
        $data = json_decode((string) ($lead['data_json'] ?? ''), true) ?: [];
        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
        return [
            'message' => ['id' => (int) $m['id'], 'step' => (int) $m['step'], 'track' => $m['track'], 'subject' => $m['subject'], 'status' => $m['status'], 'error' => $m['error'],
                'sent_at' => $m['sent_at'], 'to_email' => $m['to_email'] ?: $lead['email'], 'from_email' => $this->getSettings()['from_email']],
            'lead' => ['id' => (int) $lead['id'], 'company' => $lead['company'], 'website' => $lead['website'], 'city' => $lead['city'], 'country' => $lead['country'], 'status' => $lead['status'],
                'rating' => $lead['rating'], 'reviews' => $lead['reviews'], 'phone' => $lead['phone'], 'score' => $data['audit']['score']['total'] ?? $lead['score'] ?? null,
                'needs' => json_decode((string) ($lead['needs_json'] ?? ''), true) ?: []],
            'html' => $html,
            'html_source' => $source,
            // pv- : الأدمن وهو بيفتح التقرير مابيتحسبش فتحة على الشركة
            'report_url' => $base . '/p/r/pv-' . $lead['token'],
            'events' => $events,
            'siblings' => $siblings,
        ];
    }

    // ------------------------------------------------------------------ مكتب التواصل: موظفة بتبعت على واتساب يدويًا

    /** نتايج التواصل اللي الموظفة بتسجّلها */
    public const DESK_ACTIONS = ['contacted', 'replied', 'interested', 'not_interested', 'wrong_number', 'call_back', 'trial', 'subscribed'];

    /** سبب الرفض لما النتيجة «مش مهتمين»: بيتسجّل عشان نعرف أكتر سبب بيرفض عليه السوق كله */
    public const LOST_REASONS = ['price' => 'السعر غالي عليهم', 'competitor' => 'عندهم حل بديل/منافس', 'timing' => 'مش الوقت المناسب دلوقتي', 'no_need' => 'مش حاسّين إنهم محتاجين الخدمة', 'no_reply' => 'اتجاهلت من غير رد', 'other' => 'سبب تاني'];

    /** بعد كام يوم من آخر رسالة واتساب بدون رد الشركة تظهر في «محتاجة متابعة» (لو مفيش موعد مخصّص) */
    public const FOLLOWUP_AFTER_DAYS = 2;

    /** «محتاجة متابعة»: اتبعتلها واتساب من يومين أو أكتر ومفيش نتيجة بعدها، أو الموظفة قالت «أكلمهم تاني» */
    private static function followupSql(): string
    {
        // موعد مخصّص (desk_followup_at) بيغلب القاعدة التلقائية لما يكون متسجّل: مستحق لما وقته يوصل، أيًا كان النتيجة المسجّلة
        return "((desk_followup_at IS NOT NULL AND desk_followup_at <= NOW()) OR (desk_followup_at IS NULL AND (desk_action='call_back' OR (desk_action='contacted' AND desk_at <= NOW() - INTERVAL " . (int) self::FOLLOWUP_AFTER_DAYS . ' DAY))))';
    }

    /** رقم الواتساب بالصيغة الدولية (أرقام بس) من الرقم المتخزّن: +20 / 0020 / أو محلي بيبدأ بصفر. '' لو مش واضح */
    public static function deskPhone(?string $phone, ?string $country): string
    {
        $raw = trim((string) $phone);
        $digits = preg_replace('/\D/', '', $raw);
        if ($digits === '') {
            return '';
        }
        if (preg_match('/^\s*(\+|00)/', $raw)) {
            $digits = (string) preg_replace('/^00/', '', $digits);
        } else {
            $code = $country ? array_search(strtoupper($country), self::DIAL, true) : false;
            if ($code === false) {
                return '';
            }
            $code = (string) $code;
            if (str_starts_with($digits, '0')) {
                $digits = $code . ltrim($digits, '0');
            } elseif (!str_starts_with($digits, $code)) {
                return '';
            }
        }
        return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : '';
    }

    /** الدول اللي فيها شركات ليها رقم، وعدّاد التواصل لكل دولة */
    public function deskCountries(?int $uid = null): array
    {
        // الموظف العادي بيشوف عدّادات شغله هو بس (اللي بعتلهم وردّوا...)؛ اللي لسه ماتبعتلهمش مشتركين
        $own = $uid !== null ? ' AND desk_user_id=' . (int) $uid : '';
        $rows = $this->q("SELECT COALESCE(country,'?') country, COUNT(*) total,
                SUM(desk_action IS NULL AND status<>'unsubscribed' AND COALESCE(desk_weak,0)=0) todo,
                SUM(desk_action IS NULL AND status<>'unsubscribed' AND desk_weak=1) weak,
                SUM(desk_action IN ('contacted','call_back'){$own}) contacted,
                SUM((" . self::followupSql() . "){$own}) followup,
                SUM(report_viewed_at IS NOT NULL AND status<>'unsubscribed' AND (desk_action IS NULL OR (desk_action IN ('contacted','call_back'){$own}))) opened,
                SUM(desk_action IN ('replied','interested'){$own}) hot,
                SUM(desk_action IN ('trial','subscribed'){$own}) won,
                SUM(desk_action IN ('not_interested','wrong_number'){$own}) closed
            FROM platform_leads WHERE phone IS NOT NULL AND phone<>'' AND status<>'invalid'
            GROUP BY COALESCE(country,'?') ORDER BY total DESC")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn ($r) => ['country' => $r['country']] + array_map('intval', array_diff_key($r, ['country' => 1])), $rows);
    }

    /**
     * شركات دولة معيّنة للموظفة: بياناتها ورقمها والرسالة جاهزة للنسخ (نفس رسالة الإيميل: تقرير مبدئي + لينك التقرير الكامل).
     * $f: country, view (todo|contacted|hot|closed|all), q, page. $staff: ['name' => اسم الموظفة بيتحط في توقيع الرسالة]
     */
    /** الشركات اللي لسه ماتواصلناش معاها في الدولة (اللي ليها رقم): نفس المجموعة اللي بتتقسّم عليها المستويات */
    private function deskTodoWhere(string $country): array
    {
        $w = ["phone IS NOT NULL", "phone<>''", "status NOT IN ('invalid','unsubscribed')", 'desk_action IS NULL', 'COALESCE(desk_weak,0)=0'];
        $a = [];
        if ($country === '?') {
            $w[] = 'country IS NULL';
        } elseif (preg_match('/^[A-Z]{2}$/', $country)) {
            $w[] = 'country=?';
            $a[] = $country;
        }
        return [implode(' AND ', $w), $a];
    }

    /** حدود المستويات: أعلى 30% عالية، و35% بعدهم متوسطة. أولوية صفر مش بتبقى عالية أو متوسطة أبدًا */
    public function deskThresholds(string $country): array
    {
        [$w, $a] = $this->deskTodoWhere($country);
        $vals = array_map('intval', $this->q("SELECT COALESCE(priority,0) p FROM platform_leads WHERE {$w} ORDER BY p DESC", $a)->fetchAll(PDO::FETCH_COLUMN));
        $n = count($vals);
        if ($n === 0) {
            return ['high' => 70, 'medium' => 45];
        }
        $at = static fn (float $share): int => $vals[max(0, min($n - 1, (int) ceil($n * $share) - 1))];
        return ['high' => max(1, $at(0.30)), 'medium' => max(1, $at(0.65))];
    }

    /** عدد الشركات اللي لسه ماتواصلناش معاها في الدولة حسب الأولوية */
    public function deskTiers(string $country): array
    {
        [$w, $a] = $this->deskTodoWhere($country);
        $t = $this->deskThresholds($country);
        $r = $this->q("SELECT SUM(COALESCE(priority,0)>={$t['high']}) high, SUM(COALESCE(priority,0)>={$t['medium']} AND COALESCE(priority,0)<{$t['high']}) medium, SUM(COALESCE(priority,0)<{$t['medium']}) low FROM platform_leads WHERE {$w}", $a)->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['high' => (int) ($r['high'] ?? 0), 'medium' => (int) ($r['medium'] ?? 0), 'low' => (int) ($r['low'] ?? 0)];
    }

    /** الشركات اللي اتفحصت قبل ما الأولوية تتضاف: بتتحسب مرة واحدة من بيانات الفحص المخزّنة (من غير فحص جديد) */
    private function ensurePriority(): void
    {
        if ($this->settings->get('outreach_priority_v1', '') !== '') {
            return;
        }
        $rows = $this->q('SELECT id, rating, reviews, needs_json, data_json FROM platform_leads WHERE priority IS NULL AND scanned_at IS NOT NULL LIMIT 20000')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $this->q('UPDATE platform_leads SET priority=? WHERE id=?', [OutreachAudit::priority($r, json_decode((string) ($r['data_json'] ?? ''), true) ?: [])['score'], $r['id']]);
        }
        $this->settings->set('outreach_priority_v1', date('Y-m-d H:i:s'));
    }

    /** الشركات اللي اتفحصت قبل علامة «ضعيفة»: بتتحسب مرة واحدة من المشاكل المخزّنة (من غير فحص جديد) */
    private function ensureWeak(): void
    {
        if ($this->settings->get('outreach_weak_v2', '') !== '') {
            return;
        }
        // كل شركة عندها نتيجة فحص مخزّنة (حتى لو في طابور إعادة الفحص دلوقتي): من مشاكلها المخزّنة
        $rows = $this->q("SELECT id, data_json FROM platform_leads WHERE desk_weak IS NULL AND data_json LIKE '%\"gaps\"%' LIMIT 20000")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $audit = (json_decode((string) ($r['data_json'] ?? ''), true) ?: [])['audit'] ?? null;
            if (!is_array($audit) || !isset($audit['gaps']) || !is_array($audit['gaps'])) {
                continue;
            }
            $this->q('UPDATE platform_leads SET desk_weak=? WHERE id=?', [OutreachAudit::isWeak($audit['gaps']) ? 1 : 0, $r['id']]);
        }
        $this->settings->set('outreach_weak_v2', date('Y-m-d H:i:s'));
    }

    /** الشركات اللي اتبعتلها قبل ما «صاحب الشركة» يتسجّل: بتتنسب لآخر موظف سجّل نفس النتيجة (من سجل الإجراءات) مرة واحدة */
    private function ensureDeskOwner(): void
    {
        if ($this->settings->get('outreach_desk_uid_v1', '') !== '') {
            return;
        }
        $this->q('UPDATE platform_leads l SET l.desk_user_id=(SELECT a.user_id FROM platform_desk_actions a WHERE a.lead_id=l.id AND a.action=l.desk_action AND a.user_id IS NOT NULL ORDER BY a.id DESC LIMIT 1) WHERE l.desk_action IS NOT NULL AND l.desk_user_id IS NULL');
        $this->settings->set('outreach_desk_uid_v1', date('Y-m-d H:i:s'));
    }

    /**
     * من الشركات اللي في قايمة الموظف دلوقتي، مين اتبعتلها (أو ألغت الاشتراك) في الأثناء من موظف تاني: عشان تتشال من قايمته في لحظتها
     * @return array<int,array{id:int,by:string,reason:string}>
     */
    public function deskClaimed(array $ids, int $me): array
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, 200);
        if (!$ids) {
            return [];
        }
        $rows = $this->q('SELECT id, desk_by, status, desk_action FROM platform_leads WHERE id IN (' . implode(',', $ids) . ") AND ((desk_action IS NOT NULL AND COALESCE(desk_user_id,0)<>?) OR status='unsubscribed')", [$me])->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'by' => (string) ($r['desk_by'] ?? ''), 'reason' => $r['status'] === 'unsubscribed' ? 'unsubscribed' : 'sent'], $rows);
    }

    /**
     * «المرسول لهم»: كل رسايل الواتساب اللي اتبعتت (من سجل الإجراءات، من غير اللي اتعمل لها تراجع)، مجمّعة حسب اليوم.
     * $all = true (مين عنده صلاحية الفريق): كل الموظفين وفلتر بالموظف؛ غير كده: اللي بعتهم الموظف نفسه بس
     * $f: from, to (YYYY-MM-DD، الافتراضي النهارده)، staff_id، country، q
     */
    public function deskSent(array $f, int $viewerId, bool $all): array
    {
        $date = static fn ($v, $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) && strtotime((string) $v) !== false ? (string) $v : $d;
        $from = $date($f['from'] ?? '', date('Y-m-d'));
        $to = $date($f['to'] ?? '', date('Y-m-d'));
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if (strtotime($to) - strtotime($from) > 92 * 86400) {
            $from = date('Y-m-d', strtotime($to . ' -92 days'));
        }
        $w = ["a.action='contacted'", 'a.created_at BETWEEN ? AND ?', "NOT EXISTS (SELECT 1 FROM platform_desk_actions u WHERE u.lead_id=a.lead_id AND u.action='undo' AND u.id>a.id)"];
        $args = [$from . ' 00:00:00', $to . ' 23:59:59'];
        $staffId = $all ? (int) ($f['staff_id'] ?? 0) : $viewerId;
        if ($staffId > 0) {
            $w[] = 'a.user_id=?';
            $args[] = $staffId;
        } elseif (!$all) {
            $w[] = '1=0';
        }
        if (!empty($f['country']) && preg_match('/^[A-Z]{2}$/', (string) $f['country'])) {
            $w[] = 'l.country=?';
            $args[] = $f['country'];
        }
        if (!empty($f['q'])) {
            $w[] = '(l.company LIKE ? OR l.phone LIKE ? OR a.note LIKE ? OR l.desk_note LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_substr((string) $f['q'], 0, 80)) . '%';
            array_push($args, $like, $like, $like, $like);
        }
        $rows = $this->q('SELECT a.id log_id, a.lead_id, a.user_id, a.user_name, a.created_at, a.note, l.company, l.country, l.city, l.phone, l.status, l.step email_step, l.report_viewed_at, l.token, l.desk_action, l.desk_note, l.desk_reason, l.desk_followup_at, l.desk_tag
            FROM platform_desk_actions a JOIN platform_leads l ON l.id=a.lead_id WHERE ' . implode(' AND ', $w) . ' ORDER BY a.id DESC LIMIT 2000', $args)->fetchAll(PDO::FETCH_ASSOC);
        $first = [];
        if ($rows) {
            $ids = implode(',', array_unique(array_map(static fn ($r) => (int) $r['lead_id'], $rows)));
            foreach ($this->q("SELECT lead_id, MIN(id) mid FROM platform_desk_actions WHERE action='contacted' AND lead_id IN ({$ids}) GROUP BY lead_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $first[(int) $r['lead_id']] = (int) $r['mid'];
            }
        }
        $labels = ['contacted' => 'اتبعتلها', 'replied' => 'ردّت', 'interested' => 'مهتمة', 'trial' => 'جرّبت المنصة', 'subscribed' => 'اشتركت', 'call_back' => 'هنكلمها تاني', 'not_interested' => 'مش مهتمة', 'wrong_number' => 'الرقم غلط'];
        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://tourfecto.pro';
        $days = [];
        $byStaff = [];
        foreach ($rows as $r) {
            $day = substr((string) $r['created_at'], 0, 10);
            $res = (string) ($r['desk_action'] ?? '');
            $digits = self::deskPhone((string) ($r['phone'] ?? ''), (string) ($r['country'] ?? ''));
            $days[$day][] = ['lead_id' => (int) $r['lead_id'], 'company' => (string) $r['company'], 'country' => (string) ($r['country'] ?? ''), 'city' => (string) ($r['city'] ?? ''), 'phone' => (string) ($r['phone'] ?? ''), 'phone_intl' => $digits !== '' ? '+' . $digits : '',
                'staff_id' => (int) ($r['user_id'] ?? 0), 'staff' => (string) $r['user_name'], 'at' => substr((string) $r['created_at'], 11, 5), 'kind' => ($first[(int) $r['lead_id']] ?? 0) === (int) $r['log_id'] ? 'first' : 'followup',
                'result' => $res, 'result_label' => $labels[$res] ?? '', 'reason' => $r['desk_reason'] ?? null, 'followup_at' => $r['desk_followup_at'] ?? null, 'tag' => $r['desk_tag'] ?? null,
                'note' => (string) ($r['note'] ?: ($r['desk_note'] ?? '')), 'email_step' => (int) $r['email_step'], 'report_viewed' => !empty($r['report_viewed_at']),
                'report_url' => $base . '/p/r/pv-' . $r['token']];
            $k = (int) ($r['user_id'] ?? 0);
            $byStaff[$k] = ['staff_id' => $k, 'name' => (string) $r['user_name'], 'count' => ($byStaff[$k]['count'] ?? 0) + 1];
        }
        krsort($days);
        $out = [];
        foreach ($days as $d => $items) {
            $out[] = ['date' => $d, 'count' => count($items), 'items' => $items];
        }
        usort($byStaff, static fn ($a, $b) => $b['count'] <=> $a['count']);
        $staffList = $all ? array_map(static fn ($r) => ['staff_id' => (int) $r['user_id'], 'name' => (string) $r['name']], $this->q("SELECT user_id, MAX(user_name) name FROM platform_desk_actions WHERE action='contacted' AND user_id IS NOT NULL GROUP BY user_id ORDER BY name")->fetchAll(PDO::FETCH_ASSOC)) : [];
        return ['from' => $from, 'to' => $to, 'total' => count($rows), 'days' => $out, 'by_staff' => array_values($byStaff), 'staff' => $staffList, 'truncated' => count($rows) >= 2000, 'lost_reasons' => self::LOST_REASONS];
    }

    /**
     * شروط WHERE المشتركة بين قايمة المكتب والتصدير: الدولة والعرض (todo/contacted/...) ونطاق الموظف (شخصي/الكل) والأولوية والبحث.
     * @return array{where:string[],args:array,me:int,mineOnly:bool,onboardingBlocked:bool,view:string}
     */
    private function deskWhere(array $f, array $staff): array
    {
        $this->ensurePriority();
        $this->ensureWeak();
        $this->ensureDeskOwner();
        $where = ["phone IS NOT NULL", "phone<>''", "status<>'invalid'"];
        $args = [];
        if (($f['country'] ?? '') === '?') {
            $where[] = 'country IS NULL';
        } elseif (!empty($f['country']) && preg_match('/^[A-Z]{2}$/', (string) $f['country'])) {
            $where[] = 'country=?';
            $args[] = $f['country'];
        }
        $view = (string) ($f['view'] ?? 'todo');
        $views = [
            'todo' => "desk_action IS NULL AND status<>'unsubscribed' AND COALESCE(desk_weak,0)=0",
            'weak' => "desk_action IS NULL AND status<>'unsubscribed' AND desk_weak=1",
            'contacted' => "desk_action IN ('contacted','call_back')",
            'followup' => self::followupSql(),
            'opened' => "report_viewed_at IS NOT NULL AND status<>'unsubscribed' AND (desk_action IS NULL OR desk_action IN ('contacted','call_back'))",
            'hot' => "desk_action IN ('replied','interested')",
            'won' => "desk_action IN ('trial','subscribed')",
            'closed' => "desk_action IN ('not_interested','wrong_number')",
        ];
        if (isset($views[$view])) {
            $where[] = $views[$view];
        }
        // الموظف العادي مابيشوفش شغل زمايله: الشركات اللي بعتلها غيره بتختفي من قايمته، ويشوف اللي بعتلهم هو بس. مين عنده صلاحية الفريق بيشوف الكل
        $me = (int) ($staff['user_id'] ?? 0);
        // مرّ 24 ساعة على تعيينه موظف مبيعات ولسه مصوّرش البطاقة والصورة الشخصية: يوقف عن شغل المكتب (الشركات مختفية) لحد ما يصوّر
        $onboardingBlocked = $me > 0 && $this->staffOnboardingBlocked($me);
        $mineOnly = $me > 0 && empty($staff['all']);
        if ($mineOnly) {
            if ($view === 'opened') {
                $where[] = '(desk_action IS NULL OR desk_user_id=' . $me . ')';
            } elseif (in_array($view, ['contacted', 'followup', 'hot', 'won', 'closed'], true)) {
                $where[] = 'desk_user_id=' . $me;
            } elseif (!isset($views[$view])) {
                $where[] = '(desk_action IS NULL OR desk_user_id=' . $me . ')';
            }
        }
        // أولوية الإرسال: high = محتاجة فعلًا وشغّالة، medium، low = قليلة الاحتياج (مش لازم نتعب فيها الأول)
        // المستويات نسبية جوّه الدولة (أعلى 30% = عالية، اللي بعدهم 35% = متوسطة): عشان التدريج يفرّق مهما كانت الأرقام
        $thr = $this->deskThresholds((string) ($f['country'] ?? ''));
        $tierSql = ['high' => 'COALESCE(priority,0)>=' . $thr['high'], 'medium' => 'COALESCE(priority,0)>=' . $thr['medium'] . ' AND COALESCE(priority,0)<' . $thr['high'], 'low' => 'COALESCE(priority,0)<' . $thr['medium']];
        $tier = (string) ($f['tier'] ?? '');
        if (isset($tierSql[$tier])) {
            $where[] = $tierSql[$tier];
        }
        if (!empty($f['q'])) {
            $where[] = '(company LIKE ? OR email LIKE ? OR phone LIKE ? OR city LIKE ? OR domain LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_substr((string) $f['q'], 0, 80)) . '%';
            array_push($args, $like, $like, $like, $like, $like);
        }
        return ['where' => $where, 'args' => $args, 'me' => $me, 'mineOnly' => $mineOnly, 'onboardingBlocked' => $onboardingBlocked, 'view' => $view, 'thr' => $thr];
    }

    public function deskLeads(array $f, array $staff = []): array
    {
        $b = $this->deskWhere($f, $staff);
        $where = $b['where'];
        $args = $b['args'];
        $me = $b['me'];
        $mineOnly = $b['mineOnly'];
        $onboardingBlocked = $b['onboardingBlocked'];
        $view = $b['view'];
        $thr = $b['thr'];
        $page = max(1, (int) ($f['page'] ?? 1));
        $per = 25;
        $w = implode(' AND ', $where);
        $total = $onboardingBlocked ? 0 : (int) $this->q("SELECT COUNT(*) FROM platform_leads WHERE {$w}", $args)->fetchColumn();
        // المتابعة: الأقدم أولًا (اللي استنّت أكتر)
        // ردّوا/مهتمين: الأحدث رد الأول، عشان محدش ينسى يردّ عليهم
        $order = $view === 'opened' ? 'report_last_at DESC, id DESC' : ($view === 'followup' ? 'desk_at ASC, id' : ($view === 'hot' ? 'desk_at DESC, id DESC' : '(desk_action IS NULL) DESC, COALESCE(priority,0) DESC, COALESCE(reviews,0) DESC, id DESC'));
        $rows = $onboardingBlocked ? [] : $this->q("SELECT * FROM platform_leads WHERE {$w} ORDER BY {$order} LIMIT {$per} OFFSET " . (($page - 1) * $per), $args)->fetchAll(PDO::FETCH_ASSOC);

        $set = $this->getSettings();
        $set['ai_opener'] = '0'; // الرسالة بتتعمل لكل شركة في القايمة: من غير مكالمة ذكاء صناعي لكل واحدة
        if (!empty($staff['name'])) {
            $set['sender_name'] = (string) $staff['name'];
        }
        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://tourfecto.pro';
        // سعر Starter بالدولار وسعر الدولار بالجنيه من الإعدادات (outreach_starter_usd و staff_usd_egp): لو اتغيّرت الأسعار مفيش تعديل في الكود
        $starterUsd = (float) $this->settings->get('outreach_starter_usd', '79') ?: 79.0;
        $usdEgp = (float) $this->settings->get('staff_usd_egp', '50') ?: 50.0;
        $out = [];
        $trial = [];
        foreach ($rows as $r) {
            $l = self::tidy($r);
            $data = json_decode((string) ($l['data_json'] ?? ''), true) ?: [];
            $blocked = $l['status'] === 'unsubscribed';
            $digits = self::deskPhone($l['phone'], $l['country']);
            $gaps = [];
            foreach (array_slice((array) ($data['audit']['gaps'] ?? []), 0, 4) as $g) {
                if (($t = OutreachAudit::gapText($g, $l, 'ar')) !== '') {
                    $gaps[] = $t;
                }
            }
            $needs = [];
            foreach (array_slice((array) (json_decode((string) ($l['needs_json'] ?? ''), true) ?: []), 0, 3) as $n) {
                $needs[] = OutreachNeeds::trackLabel((string) ($n['track'] ?? ''), 'ar');
            }
            $sc = OutreachAudit::scorecard($l, $data);
            $seoState = OutreachAudit::seoAssessment($data);
            $message2 = '';
            // نفس القيمة المخزّنة اللي القايمة مترتّبة ومتفلترة بيها (الحساب الحي بس لو لسه مااتخزّنتش)
            $prioScore = isset($r['priority']) && $r['priority'] !== null ? (int) $r['priority'] : OutreachAudit::priority($l, $data)['score'];
            $message = '';
            if (!$blocked) {
                try {
                    $auditGaps = (array) ($data['audit']['gaps'] ?? []);
                    if (!empty($data['audit']['score'])) {
                        // الشركة اتفحصت: رسالة قصيرة من الحقيقي بس (الموقع، السيو، جوجل ماب، السوشيال)، ولينك التقرير الكامل
                        $lang = in_array($l['language'], OutreachNeeds::LANGS, true) ? $l['language'] : 'en';
                        $trial[$lang] ??= self::trialOffer($lang);
                        $ctx = ['lang' => $lang, 'company' => OutreachAudit::cleanName((string) $l['company']), 'name' => (string) $set['sender_name'], 'title' => (string) $set['sender_title'],
                            'scorecard' => $sc, 'seo' => $seoState, 'gaps' => $auditGaps, 'report_url' => $base . '/p/r/' . $l['token'] . '?s=1', 'trial' => $trial[$lang],
                            'signup_url' => $base . '/p/c/' . $l['token'] . '/1', 'country' => (string) ($l['country'] ?? ''), 'price_usd' => $starterUsd, 'usd_egp' => $usdEgp];
                        $message = OutreachNeeds::deskMessage($ctx);
                        $message2 = OutreachNeeds::deskServicesMessage($ctx);
                    } else {
                        $message = (string) $this->compose($l, 1, $set, static fn () => null)['text'];
                    }
                } catch (Throwable $e) {
                    Logger::warning('Outreach desk message failed', ['lead' => $l['id'], 'error' => $e->getMessage()]);
                }
            }
            // لينك واتساب بالرسالة جاهزة جوّه الشات؛ لو طويلة قوي نفتح الشات من غيرها والموظفة بتلصقها (متصفحات كتير بتقطع اللينكات الطويلة)
            $waUrl = $digits !== '' && !$blocked ? 'https://wa.me/' . $digits : '';
            $waText = false;
            if ($waUrl !== '' && $message !== '') {
                $withText = $waUrl . '?text=' . rawurlencode($message);
                if (strlen($withText) <= 6000) {
                    $waUrl = $withText;
                    $waText = true;
                }
            }
            // الرسالة التانية (الخدمات) بنفس الشات: بتتبعت بعد ما يردّوا
            $waUrl2 = '';
            if ($waUrl !== '' && $message2 !== '' && $digits !== '' && strlen('https://wa.me/' . $digits . '?text=' . rawurlencode($message2)) <= 6000) {
                $waUrl2 = 'https://wa.me/' . $digits . '?text=' . rawurlencode($message2);
            }
            $out[] = [
                'id' => (int) $l['id'], 'company' => OutreachAudit::cleanName((string) $l['company']), 'category' => $l['category'], 'city' => $l['city'], 'country' => $l['country'],
                'website' => $l['website'], 'email' => $l['email'], 'rating' => $l['rating'], 'reviews' => $l['reviews'], 'language' => $l['language'], 'status' => $l['status'],
                'score' => $data['audit']['score']['total'] ?? $l['score'], 'needs' => $needs, 'gaps' => $gaps, 'has_report' => !empty($data['audit']['score']),
                'scorecard' => $sc, 'priority' => $prioScore, 'tier' => $prioScore >= $thr['high'] ? 'high' : ($prioScore >= $thr['medium'] ? 'medium' : 'low'),
                'phone' => (string) $l['phone'], 'phone_intl' => $digits !== '' ? '+' . $digits : '', 'wa_url' => $waUrl, 'wa_has_text' => $waText,
                'blocked' => $blocked, 'message' => $message, 'message2' => $message2, 'wa_url2' => $waUrl2, 'seo' => $seoState,
                'category_flag' => class_exists('OutreachNeeds') ? OutreachNeeds::nonTourSignal((string) ($sc['tripadvisor']['ranking'] ?? ''), (string) ($l['category'] ?? '')) : null,
                'tag' => $l['desk_tag'] ?? null,
                'report_url' => $base . '/p/r/' . $l['token'], 'report_preview_url' => $base . '/p/r/pv-' . $l['token'],
                'email_step' => (int) $l['step'], 'email_sent_at' => $l['last_sent_at'], 'email_clicked' => !empty($l['clicked_at']), 'report_viewed' => !empty($l['report_viewed_at']),
                'report_views' => (int) ($l['report_views'] ?? 0), 'report_first_at' => $l['report_viewed_at'] ?? null, 'report_last_at' => $l['report_last_at'] ?? ($l['report_viewed_at'] ?? null),
                'desk' => ['action' => $l['desk_action'] ?? null, 'note' => $l['desk_note'] ?? null, 'at' => $l['desk_at'] ?? null, 'by' => $l['desk_by'] ?? null, 'reason' => $l['desk_reason'] ?? null, 'followup_at' => $l['desk_followup_at'] ?? null],
            ];
        }
        return ['rows' => $out, 'total' => $total, 'page' => $page, 'pages' => (int) ceil($total / $per), 'counts' => $this->deskCountries($mineOnly ? $me : null), 'tiers' => $this->deskTiers((string) ($f['country'] ?? '')), 'onboarding_blocked' => $onboardingBlocked, 'lost_reasons' => self::LOST_REASONS, 'desk_tags' => self::DESK_TAGS];
    }

    /**
     * تصدير القايمة الحالية (نفس فلتر الدولة والعرض والأولوية والبحث) CSV: كل الشركات المطابقة، مش صفحة واحدة بس.
     * حد أقصى 5000 صف عشان ما يثقّلش السيرفر.
     */
    public function deskExportCsv(array $f, array $staff = []): string
    {
        $b = $this->deskWhere($f, $staff);
        if ($b['onboardingBlocked']) {
            return "company,phone,email,website,city,country,rating,reviews,score,priority,tier,status,desk_action,desk_reason,desk_note,desk_at,desk_by,followup_at,tag\n";
        }
        $w = implode(' AND ', $b['where']);
        $rows = $this->q("SELECT company,phone,email,website,city,country,rating,reviews,score,priority,status,desk_action,desk_reason,desk_note,desk_at,desk_by,desk_followup_at,desk_tag
            FROM platform_leads WHERE {$w} ORDER BY (desk_action IS NULL) DESC, COALESCE(priority,0) DESC, COALESCE(reviews,0) DESC, id DESC LIMIT 5000", $b['args'])->fetchAll(PDO::FETCH_ASSOC);
        $thr = $b['thr'];
        $tier = static function ($p) use ($thr) {
            $p = (int) $p;
            return $p >= $thr['high'] ? 'high' : ($p >= $thr['medium'] ? 'medium' : 'low');
        };
        $esc = static function ($v) {
            $v = (string) ($v ?? '');
            return '"' . str_replace('"', '""', $v) . '"';
        };
        $head = ['company', 'phone', 'email', 'website', 'city', 'country', 'rating', 'reviews', 'score', 'priority', 'tier', 'status', 'desk_action', 'desk_reason', 'desk_note', 'desk_at', 'desk_by', 'followup_at', 'tag'];
        $lines = [implode(',', $head)];
        foreach ($rows as $r) {
            $lines[] = implode(',', [
                $esc($r['company']), $esc($r['phone']), $esc($r['email']), $esc($r['website']), $esc($r['city']), $esc($r['country']),
                $esc($r['rating']), $esc($r['reviews']), $esc($r['score']), $esc($r['priority']), $esc($tier($r['priority'])), $esc($r['status']),
                $esc($r['desk_action']), $esc($r['desk_reason']), $esc($r['desk_note']), $esc($r['desk_at']), $esc($r['desk_by']), $esc($r['desk_followup_at']), $esc($r['desk_tag']),
            ]);
        }
        return "\xEF\xBB\xBF" . implode("\n", $lines) . "\n";
    }

    // ------------------------------------------------------------------ كشف الموظفين: الشغل والعملاء والنسبة والراتب

    public const COMP_TYPES = ['none', 'commission', 'salary', 'both'];

    // ---- ملف الموظف (بيانات شخصية وتواصل وطوارئ واستلام المستحقات)
    /** البيانات المطلوبة من كل موظف: المفتاح => الاسم اللي بيظهر لو ناقصة */
    public const PROFILE_REQUIRED = ['full_name' => 'الاسم بالكامل', 'phone' => 'رقم التليفون', 'national_id' => 'الرقم القومي أو جواز السفر', 'address' => 'العنوان',
        'emergency_name' => 'اسم شخص للطوارئ', 'emergency_phone' => 'تليفون الطوارئ', 'pay_method' => 'طريقة استلام المستحقات', 'pay_account' => 'رقم الحساب أو المحفظة'];
    public const PAY_METHODS = ['vodafone_cash', 'instapay', 'bank', 'cash'];
    public const PROFILE_STATUSES = ['active', 'paused', 'left'];
    /** الموظف يعدّل بيانات نفسه دي */
    private const PROFILE_SELF = ['sign_name', 'full_name', 'phone', 'address', 'emergency_name', 'emergency_phone', 'national_id', 'pay_method', 'pay_account'];
    /** مالك المنصة بس */
    private const PROFILE_OWNER = ['job_title', 'start_date', 'status', 'internal_note', 'daily_goal'];

    private function encField(string $v): string
    {
        return $v === '' ? '' : (new Encryption())->encrypt($v, 'staff-profile');
    }

    private function decField(?string $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        try {
            return (new Encryption())->decrypt($v, 'staff-profile');
        } catch (Throwable $e) {
            return '';
        }
    }

    private static function maskTail(string $v): string
    {
        $n = mb_strlen($v);
        return $n === 0 ? '' : str_repeat('•', max(0, $n - 4)) . mb_substr($v, -4);
    }

    /**
     * ملف الموظف. $reveal = false بيخفي الرقم القومي ورقم الحساب (آخر 4 بس)، ولازم يبقى true لصاحب الملف أو مالك المنصة.
     * @return array<string,mixed> الحقول + missing (البيانات المطلوبة الناقصة) + complete
     */
    public function staffProfile(int $userId, bool $reveal): array
    {
        $r = $this->q('SELECT * FROM platform_staff_profile WHERE user_id=?', [$userId])->fetch(PDO::FETCH_ASSOC) ?: [];
        $nid = $this->decField($r['national_id_enc'] ?? null);
        $acc = $this->decField($r['pay_account_enc'] ?? null);
        $raw = ['sign_name' => (string) ($r['sign_name'] ?? ''), 'full_name' => (string) ($r['full_name'] ?? ''), 'phone' => (string) ($r['phone'] ?? ''), 'address' => (string) ($r['address'] ?? ''), 'emergency_name' => (string) ($r['emergency_name'] ?? ''),
            'emergency_phone' => (string) ($r['emergency_phone'] ?? ''), 'national_id' => $nid, 'pay_method' => (string) ($r['pay_method'] ?? ''), 'pay_account' => $acc];
        $missing = [];
        foreach (self::PROFILE_REQUIRED as $k => $label) {
            if (trim($raw[$k]) === '') {
                $missing[] = $label;
            }
        }
        $shown = ['national_id' => $reveal ? $nid : self::maskTail($nid), 'pay_account' => $reveal ? $acc : self::maskTail($acc)] + $raw; // المخفي الأول عشان يغلب الأصلي
        return $shown + [
            'job_title' => (string) ($r['job_title'] ?? ''), 'start_date' => $r['start_date'] ?? null, 'status' => (string) ($r['status'] ?? 'active'), 'daily_goal' => isset($r['daily_goal']) ? (int) $r['daily_goal'] : null,
            'recapture_at' => $r['recapture_at'] ?? null, 'internal_note' => $reveal ? (string) ($r['internal_note'] ?? '') : '', 'updated_by' => $r['updated_by'] ?? null, 'updated_at' => $r['updated_at'] ?? null,
            'missing' => $missing, 'complete' => $missing === [], 'revealed' => $reveal,
        ];
    }

    /** يحفظ الحقول اللي اتبعتت بس. الموظف بيعدّل حقوله، ومالك المنصة بيعدّل الباقي (المسمى والتعيين والحالة وملاحظة داخلية). فاضي = مسح */
    public function staffProfileSet(int $userId, array $in, bool $isOwner, string $by): array
    {
        if (!$this->q('SELECT 1 FROM users WHERE id=?', [$userId])->fetchColumn()) {
            throw new InvalidArgumentException('الموظف مش موجود');
        }
        $allowed = array_merge(self::PROFILE_SELF, $isOwner ? self::PROFILE_OWNER : []);
        $vals = [];
        foreach ($allowed as $k) {
            if (!array_key_exists($k, $in)) {
                continue;
            }
            $v = trim((string) $in[$k]);
            $len = ['full_name' => 150, 'phone' => 40, 'address' => 255, 'emergency_name' => 120, 'emergency_phone' => 40, 'job_title' => 100, 'internal_note' => 500, 'national_id' => 40, 'pay_account' => 80, 'sign_name' => 40][$k] ?? 0;
            if ($len && mb_strlen($v) > $len) {
                throw new InvalidArgumentException('النص أطول من المسموح: ' . (self::PROFILE_REQUIRED[$k] ?? $k));
            }
            if ($k === 'sign_name' && $v !== '' && (mb_strlen($v) < 2 || !preg_match('/^[\p{L}\p{M}][\p{L}\p{M} .-]*$/u', $v))) {
                throw new InvalidArgumentException('الاسم في التوقيع: حروف بس (من 2 لـ 40 حرف) من غير أرقام أو لينكات');
            }
            if (in_array($k, ['phone', 'emergency_phone'], true) && $v !== '' && !preg_match('/^\+?[0-9 \-()]{6,20}$/', $v)) {
                throw new InvalidArgumentException('رقم التليفون غير صحيح');
            }
            if ($k === 'national_id' && $v !== '' && !preg_match('/^[A-Za-z0-9]{6,30}$/', $v)) {
                throw new InvalidArgumentException('الرقم القومي أو جواز السفر: حروف وأرقام بس من غير مسافات');
            }
            if ($k === 'pay_method' && $v !== '' && !in_array($v, self::PAY_METHODS, true)) {
                throw new InvalidArgumentException('طريقة الاستلام غير معروفة');
            }
            if ($k === 'status' && !in_array($v, self::PROFILE_STATUSES, true)) {
                throw new InvalidArgumentException('الحالة غير معروفة');
            }
            if ($k === 'start_date') {
                if ($v !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)))) {
                    throw new InvalidArgumentException('تاريخ التعيين غير صحيح');
                }
                $v = $v === '' ? null : $v;
            }
            if ($k === 'daily_goal') {
                if ($v !== '' && (!ctype_digit($v) || (int) $v < 1 || (int) $v > 1000)) {
                    throw new InvalidArgumentException('الهدف اليومي لازم يكون رقم من 1 لـ 1000');
                }
                $vals['daily_goal'] = $v === '' ? null : (int) $v;
                continue;
            }
            if ($k === 'national_id') {
                $vals['national_id_enc'] = $this->encField($v); // لو التشفير مش جاهز بيرمي استثناء ومابنخزّنش نص صريح
            } elseif ($k === 'pay_account') {
                $vals['pay_account_enc'] = $this->encField($v);
            } else {
                $vals[$k] = $v === '' ? null : $v;
            }
        }
        $this->q('INSERT IGNORE INTO platform_staff_profile(user_id) VALUES(?)', [$userId]);
        if ($vals) {
            $set = implode(',', array_map(static fn ($c) => "`{$c}`=?", array_keys($vals)));
            $this->q("UPDATE platform_staff_profile SET {$set},updated_by=? WHERE user_id=?", array_merge(array_values($vals), [mb_substr($by, 0, 120), $userId]));
        }
        $released = 0;
        if ($isOwner && ($vals['status'] ?? '') === 'left') {
            $released = $this->staffOffboard($userId, $by);
        }
        return $this->staffProfile($userId, true) + ['released' => $released];
    }

    /**
     * موظف ساب الشغل: الشركات اللي بعتلها ومالهاش رد (اتبعتلها أو «هنكلمها تاني») بترجع لقايمة «لسه ماتواصلتش» عشان موظف تاني ياخدها،
     * والشركات اللي ردّت أو اهتمت أو جرّبت أو اشتركت بتفضل منسوبة له (عشان النسبة). السجل بيحتفظ بكل اللي حصل.
     * @return int عدد الشركات اللي رجعت للقايمة
     */
    public function staffOffboard(int $userId, string $by): int
    {
        $ids = $this->q("SELECT id FROM platform_leads WHERE desk_user_id=? AND desk_action IN ('contacted','call_back')", [$userId])->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $this->q('UPDATE platform_leads SET desk_action=NULL, desk_note=NULL, desk_at=NULL, desk_by=NULL, desk_user_id=NULL WHERE id=? AND desk_user_id=?', [$id, $userId]);
            $this->q('INSERT INTO platform_desk_actions(lead_id,user_id,user_name,action,note) VALUES(?,?,?,?,?)', [$id, $userId, mb_substr($by, 0, 120), 'released', 'الموظف ساب الشغل']);
        }
        return count($ids);
    }

    /** @return array{comp_type:string,commission_pct:float,monthly_salary:float,note:?string,updated_by:?string,updated_at:?string} */
    public function staffCompGet(int $userId): array
    {
        $r = $this->q('SELECT comp_type,commission_pct,monthly_salary,note,updated_by,updated_at FROM platform_staff_comp WHERE user_id=?', [$userId])->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['comp_type' => (string) ($r['comp_type'] ?? 'none'), 'commission_pct' => (float) ($r['commission_pct'] ?? 0), 'monthly_salary' => (float) ($r['monthly_salary'] ?? 0),
            'note' => $r['note'] ?? null, 'updated_by' => $r['updated_by'] ?? null, 'updated_at' => $r['updated_at'] ?? null];
    }

    /** نوع التعويض: نسبة على كل عميل كل شهر، أو راتب شهري ثابت، أو الاتنين. حساب فقط (مفيش دفع فعلي من هنا) */
    public function staffCompSet(int $userId, string $type, float $pct, float $salary, string $note, string $by): array
    {
        if (!in_array($type, self::COMP_TYPES, true)) {
            throw new InvalidArgumentException('نوع التعويض غير معروف');
        }
        if ($pct < 0 || $pct > 100 || $salary < 0 || $salary > 10000000) {
            throw new InvalidArgumentException('النسبة بين 0 و100 والراتب رقم موجب');
        }
        if (!$this->q('SELECT 1 FROM users WHERE id=?', [$userId])->fetchColumn()) {
            throw new InvalidArgumentException('الموظف مش موجود');
        }
        $pct = in_array($type, ['commission', 'both'], true) ? $pct : 0.0;
        $salary = in_array($type, ['salary', 'both'], true) ? $salary : 0.0;
        $this->q('INSERT INTO platform_staff_comp(user_id,comp_type,commission_pct,monthly_salary,note,updated_by) VALUES(?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE comp_type=VALUES(comp_type),commission_pct=VALUES(commission_pct),monthly_salary=VALUES(monthly_salary),note=VALUES(note),updated_by=VALUES(updated_by)',
            [$userId, $type, $pct, $salary, mb_substr(trim($note), 0, 255) ?: null, mb_substr($by, 0, 120)]);
        return $this->staffCompGet($userId);
    }

    // ---- صورة البطاقة (مشفّرة) وقراءة بياناتها، وموقع الموظف مرة واحدة بإذنه
    private const AR_DIGITS = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'];
    private const EG_GOVERNORATES = ['01' => 'القاهرة', '02' => 'الإسكندرية', '03' => 'بورسعيد', '04' => 'السويس', '11' => 'دمياط', '12' => 'الدقهلية', '13' => 'الشرقية', '14' => 'القليوبية', '15' => 'كفر الشيخ', '16' => 'الغربية',
        '17' => 'المنوفية', '18' => 'البحيرة', '19' => 'الإسماعيلية', '21' => 'الجيزة', '22' => 'بني سويف', '23' => 'الفيوم', '24' => 'المنيا', '25' => 'أسيوط', '26' => 'سوهاج', '27' => 'قنا', '28' => 'أسوان', '29' => 'الأقصر',
        '31' => 'البحر الأحمر', '32' => 'الوادي الجديد', '33' => 'مطروح', '34' => 'شمال سيناء', '35' => 'جنوب سيناء', '88' => 'خارج الجمهورية'];
    /** أقصى مسافة (كم) بين موقعه المسجّل وعنوانه قبل ما تتعلّم «بعيد عن العنوان» */
    public const LOCATION_FAR_KM = 30;

    /** الرقم القومي المصري (14 رقم): تاريخ الميلاد والنوع والمحافظة من الرقم نفسه. null لو مش بالصيغة دي */
    public static function egyptianIdInfo(string $id): ?array
    {
        $id = preg_replace('/\s+/', '', strtr(trim($id), self::AR_DIGITS));
        if (!preg_match('/^([23])(\d{2})(\d{2})(\d{2})(\d{2})(\d{3})(\d)(\d)$/', (string) $id, $m)) {
            return null;
        }
        $year = ($m[1] === '2' ? 1900 : 2000) + (int) $m[2];
        if (!checkdate((int) $m[3], (int) $m[4], $year) || strtotime(sprintf('%04d-%02d-%02d', $year, $m[3], $m[4])) > time()) {
            return null;
        }
        return ['birth_date' => sprintf('%04d-%02d-%02d', $year, $m[3], $m[4]), 'gender' => ((int) $m[7]) % 2 === 1 ? 'male' : 'female', 'governorate' => self::EG_GOVERNORATES[$m[5]] ?? ('كود ' . $m[5])];
    }

    /** رد الذكاء الاصطناعي على قراءة البطاقة → اقتراحات منظّمة (الموظفة بتراجعها قبل الحفظ) */
    public static function parseIdOcr(string $text): array
    {
        if (!preg_match('/\{.*\}/s', $text, $m) || !is_array($j = json_decode($m[0], true))) {
            return [];
        }
        $out = ['full_name' => trim(mb_substr((string) ($j['full_name'] ?? ''), 0, 150)), 'national_id' => preg_replace('/\D/', '', strtr((string) ($j['national_id'] ?? ''), self::AR_DIGITS)),
            'address' => trim(mb_substr(strtr((string) ($j['address'] ?? ''), self::AR_DIGITS), 0, 255))];
        if ($out['national_id'] !== '') {
            $info = self::egyptianIdInfo($out['national_id']);
            $out += $info ?? ['warning' => 'الرقم اللي اتقرأ مش بصيغة الرقم القومي المصري (14 رقم): يتراجع يدويًا'];
        }
        return $out;
    }

    /** يحفظ صورة البطاقة مشفّرة بعد ما يتأكد إنها صورة حقيقية ويصغّرها ويشيل بياناتها الوصفية (EXIF) */
    public function staffIdCardSave(int $userId, string $side, string $binary): void
    {
        if (!in_array($side, ['front', 'back', 'photo'], true)) {
            throw new InvalidArgumentException('نوع الصورة غير معروف');
        }
        if ($binary === '' || strlen($binary) > 10 * 1024 * 1024) {
            throw new InvalidArgumentException('الصورة فاضية أو أكبر من 10 ميجا');
        }
        $info = @getimagesizefromstring($binary);
        if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new InvalidArgumentException('الملف مش صورة (JPG أو PNG أو WebP)');
        }
        $mime = $info['mime'];
        if (function_exists('imagecreatefromstring') && ($img = @imagecreatefromstring($binary))) {
            $w = imagesx($img);
            $h = imagesy($img);
            if (max($w, $h) > 1600) {
                $scale = 1600 / max($w, $h);
                $img = imagescale($img, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))) ?: $img;
            }
            ob_start();
            imagejpeg($img, null, 82);
            $binary = (string) ob_get_clean();
            $mime = 'image/jpeg';
        } elseif (strlen($binary) > 3 * 1024 * 1024) {
            throw new InvalidArgumentException('الصورة كبيرة: تتصغّر قبل الرفع');
        }
        $this->archiveDoc($userId, $side);
        $this->q('REPLACE INTO platform_staff_docs(user_id,side,mime,data_enc) VALUES(?,?,?,?)', [$userId, $side, $mime, $this->encField(base64_encode($binary))]);
        // الفيديو القديم بتاع نفس الخطوة مايفضلش "حالي" جنب صورة جديدة: بيتأرشف (لو قديم) وبيتشال من الحالي، والفيديو الجديد بيتحفظ بعدها لو اتبعت
        $this->archiveDoc($userId, ['front' => 'fclip', 'back' => 'bclip', 'photo' => 'pclip'][$side]);
        $this->q('DELETE FROM platform_staff_docs WHERE user_id=? AND side=?', [$userId, ['front' => 'fclip', 'back' => 'bclip', 'photo' => 'pclip'][$side]]);
        if ($side === 'photo') {
            $this->q('UPDATE platform_staff_profile SET recapture_at=NULL WHERE user_id=?', [$userId]);
        }
    }

    /**
     * التصوير الجديد مابيمسحش القديم: القديم بيتنقل لسجل منفصل (للمالك بس) والجديد بيبقى الحالي.
     * بنأرشف دايمًا، حتى لو اللقطة الجديدة جت بعد القديمة بثواني أو في نفس الدقيقة.
     * بنحتفظ بآخر 10 نسخ قديمة لكل خطوة.
     */
    private function archiveDoc(int $userId, string $side): void
    {
        $this->q('INSERT INTO platform_staff_docs_history(user_id,side,mime,data_enc,uploaded_at) SELECT user_id,side,mime,data_enc,uploaded_at FROM platform_staff_docs WHERE user_id=? AND side=?', [$userId, $side]);
        $this->q('DELETE FROM platform_staff_docs_history WHERE user_id=? AND side=? AND id NOT IN (SELECT id FROM (SELECT id FROM platform_staff_docs_history WHERE user_id=? AND side=? ORDER BY id DESC LIMIT 10) k)', [$userId, $side, $userId, $side]);
    }

    /** النسخ القديمة لصور وفيديوهات موظف (للمالك): [{id, side, uploaded_at, archived_at, mime}] الأحدث الأول */
    public function staffIdCardHistory(int $userId): array
    {
        return $this->q('SELECT id, side, mime, uploaded_at, archived_at FROM platform_staff_docs_history WHERE user_id=? ORDER BY id DESC LIMIT 60', [$userId])->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{0:string,1:string}|null [mime, binary] لنسخة قديمة */
    public function staffIdCardHistoryGet(int $userId, int $id): ?array
    {
        $r = $this->q('SELECT mime,data_enc FROM platform_staff_docs_history WHERE user_id=? AND id=?', [$userId, $id])->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }
        $bin = base64_decode($this->decField((string) $r['data_enc']), true);
        return $bin === false || $bin === '' ? null : [(string) $r['mime'], $bin];
    }

    /**
     * حذف نهائي لصور وفيديوهات موظف من غير أي أثر: صورة أو فيديو حالي ($side)، أو نسخة قديمة ($historyId)، أو كل حاجة ($all: الحالي والقديم وسجل الاطلاع عليهم).
     * مابيتسجّلش في أي لوج إنه اتحذف إيه. نسخ الباك أب بتاعة السيرفر مش في إيدينا.
     * @return int عدد الحاجات اللي اتحذفت
     */
    public function staffIdCardDelete(int $userId, string $side = '', int $historyId = 0, bool $all = false): int
    {
        if ($all) {
            $n = $this->q('DELETE FROM platform_staff_docs WHERE user_id=?', [$userId])->rowCount();
            $n += $this->q('DELETE FROM platform_staff_docs_history WHERE user_id=?', [$userId])->rowCount();
            $this->q('DELETE FROM platform_staff_doc_views WHERE user_id=?', [$userId]);
            return $n;
        }
        if ($historyId > 0) {
            return $this->q('DELETE FROM platform_staff_docs_history WHERE user_id=? AND id=?', [$userId, $historyId])->rowCount();
        }
        if (!in_array($side, ['front', 'back', 'photo', 'fclip', 'bclip', 'pclip'], true)) {
            throw new InvalidArgumentException('نوع الصورة غير معروف');
        }
        return $this->q('DELETE FROM platform_staff_docs WHERE user_id=? AND side=?', [$userId, $side])->rowCount();
    }

    /** مقطع فيديو قصير (ثواني) بيتسجّل مع كل لقطة: وش البطاقة (fclip) وضهرها (bclip) والصورة الشخصية (pclip). WebM أو MP4 بس وبحد 6 ميجا */
    public function staffIdCardClipSave(int $userId, string $side, string $binary): void
    {
        if (!in_array($side, ['fclip', 'bclip', 'pclip'], true)) {
            throw new InvalidArgumentException('نوع المقطع غير معروف');
        }
        if ($binary === '' || strlen($binary) > 6 * 1024 * 1024) {
            throw new InvalidArgumentException('المقطع فاضي أو أكبر من 6 ميجا');
        }
        $mime = str_starts_with($binary, "\x1A\x45\xDF\xA3") ? 'video/webm' : (substr($binary, 4, 4) === 'ftyp' ? 'video/mp4' : '');
        if ($mime === '') {
            throw new InvalidArgumentException('المقطع مش فيديو (WebM أو MP4)');
        }
        $this->archiveDoc($userId, $side);
        $this->q('REPLACE INTO platform_staff_docs(user_id,side,mime,data_enc) VALUES(?,?,?,?)', [$userId, $side, $mime, $this->encField(base64_encode($binary))]);
    }

    /** @return array{0:string,1:string}|null [mime, binary] */
    public function staffIdCardGet(int $userId, string $side): ?array
    {
        $r = $this->q('SELECT mime,data_enc FROM platform_staff_docs WHERE user_id=? AND side=?', [$userId, $side])->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }
        $bin = base64_decode($this->decField((string) $r['data_enc']), true);
        return $bin === false || $bin === '' ? null : [(string) $r['mime'], $bin];
    }

    /** سجل الاطلاع على صور الموظف (مين فتحها ومتى): شفافية زي أنظمة الموارد البشرية الكبيرة */
    public function staffDocViewLog(int $viewerId, int $userId, string $side): void
    {
        $this->q('INSERT INTO platform_staff_doc_views(viewer_id,user_id,side) VALUES(?,?,?)', [$viewerId, $userId, $side]);
    }

    /** آخر مرات الاطلاع على صور موظف: [{viewer, side, at}] */
    public function staffDocViews(int $userId, int $limit = 5): array
    {
        $rows = $this->q("SELECT v.side, v.viewed_at, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)),''), SUBSTRING_INDEX(u.email,'@',1)) viewer
            FROM platform_staff_doc_views v LEFT JOIN users u ON u.id=v.viewer_id WHERE v.user_id=? ORDER BY v.id DESC LIMIT " . max(1, min(20, $limit)), [$userId])->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn ($r) => ['viewer' => (string) $r['viewer'], 'side' => (string) $r['side'], 'at' => (string) $r['viewed_at']], $rows);
    }

    /** @return array{front:?string,back:?string,photo:?string} وقت رفع كل صورة (وش البطاقة وضهرها والصورة الشخصية) */
    public function staffIdCardStatus(int $userId): array
    {
        $out = ['front' => null, 'back' => null, 'photo' => null, 'fclip' => null, 'bclip' => null, 'pclip' => null];
        foreach ($this->q('SELECT side,uploaded_at FROM platform_staff_docs WHERE user_id=?', [$userId])->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['side']] = (string) $r['uploaded_at'];
        }
        return $out;
    }

    /**
     * يقرا وش البطاقة بالذكاء الاصطناعي (Gemini) ويرجّع اقتراحات للاسم والرقم القومي والعنوان. مابيحفظش حاجة في الملف.
     * @param callable|null $vision (binary, mime, prompt) => ['success'=>bool,'data'=>string]
     */
    public function staffIdCardExtract(int $userId, ?callable $vision = null): array
    {
        $card = $this->staffIdCardGet($userId, 'front');
        if (!$card) {
            return ['ok' => false, 'error' => 'لازم صورة وش البطاقة الأول'];
        }
        $prompt = 'This is the front of an Egyptian national ID card. Read the printed text exactly as written and answer with ONLY a JSON object: '
            . '{"full_name": "the full name in Arabic as printed", "national_id": "the 14-digit national number", "address": "the address as printed"}. '
            . 'If a field is not readable use an empty string. Do not guess or invent anything.';
        try {
            $vision ??= static fn (string $bin, string $mime, string $p) => (new GeminiClient())->describeImage($bin, $mime, $p, ['temperature' => 0]);
            $r = $vision($card[1], $card[0], $prompt);
        } catch (Throwable $e) {
            Logger::warning('ID card OCR failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'القراءة الآلية مش متاحة دلوقتي: البيانات تتكتب يدويًا'];
        }
        $fields = !empty($r['success']) ? self::parseIdOcr((string) ($r['data'] ?? $r['text'] ?? '')) : [];
        if (!$fields) {
            return ['ok' => false, 'error' => 'ماقدرناش نقرا البطاقة بوضوح: يتعاد التصوير في إضاءة كويسة أو تتكتب البيانات يدويًا'];
        }
        return ['ok' => true, 'fields' => $fields];
    }

    /**
     * موظف مبيعات (outreach.desk) اتعيّن من أكتر من 24 ساعة ولسه مصوّرش وش بطاقته وصورته الشخصية: يوقف عن شغل المكتب
     * (الشركات بتختفي من قايمته) لحد ما يصوّر. مالك المنصة ومين دوره مش مبيعات مابينطبقش عليهم.
     */
    public function staffOnboardingBlocked(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        try {
            $createdAt = $this->q("SELECT created_at FROM admin_staff WHERE user_id=? AND staff_role='sales'", [$userId])->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
        if (!$createdAt || strtotime((string) $createdAt) > time() - 24 * 3600) {
            return false;
        }
        $st = $this->staffIdCardStatus($userId);
        return !($st['front'] && $st['photo']);
    }

    /**
     * يتأكد إن الصورة الشخصية فيها فعلًا وش إنسان ظاهر (مش حيطة أو باب اتصوّر غلط لأن الكاميرا كانت لسه بتتوجّه): بالذكاء الاصطناعي.
     * لو خدمة الذكاء الاصطناعي مش متاحة بيرجّع true (مابنوقفش الموظف بسبب عطل خدمة خارجية، الفحص ده تحسين مش شرط أساسي).
     * @param callable|null $vision (binary, mime, prompt) => ['success'=>bool,'data'=>string]
     */
    public function staffSelfieHasFace(int $userId, ?callable $vision = null): bool
    {
        $card = $this->staffIdCardGet($userId, 'photo');
        if (!$card) {
            return false;
        }
        $prompt = 'Does this photo clearly show a real human face looking toward the camera (not a wall, door, floor, ceiling, or an empty/blurry scene)? Answer with ONLY one word: yes or no.';
        try {
            $vision ??= static fn (string $bin, string $mime, string $p) => (new GeminiClient())->describeImage($bin, $mime, $p, ['temperature' => 0]);
            $r = $vision($card[1], $card[0], $prompt);
        } catch (Throwable $e) {
            Logger::warning('Selfie face check failed', ['error' => $e->getMessage()]);
            return true;
        }
        if (empty($r['success'])) {
            return true;
        }
        return str_starts_with(strtolower(trim((string) ($r['data'] ?? $r['text'] ?? ''))), 'yes');
    }

    /** حقايق حقيقية بس عن المنصة، تتحط في برومبت مساعد المبيعات عشان الذكاء الاصطناعي مايخترعش مميزات مش موجودة */
    private function assistantFacts(): string
    {
        $usd = (float) $this->settings->get('outreach_starter_usd', '79') ?: 79.0;
        $egp = (float) $this->settings->get('staff_usd_egp', '50') ?: 50.0;
        return 'باقة Starter: $' . rtrim(rtrim(number_format($usd, 2), '0'), '.') . ' في الشهر (أو ' . number_format(round($usd * $egp), 0, '.', ',') . ' جنيه لمصر)، تجربة مجانية 3 أيام من غير كارت ائتمان. '
            . 'الخدمات الشغّالة فعلًا: السيو والظهور في جوجل، موقع وحجز على موقع الشركة (الدفع بيوصّل لطريقة الشركة الخاصة PayPal أو إنستاباي أو تحويل بنكي، من غير عمولة من المنصة أبدًا)، مراقبة أسعار المنافسين، CRM وتسويق بالإيميل. '
            . 'ممنوع تدّعي أي مميزة مش في القايمة دي، زي معالجة دفع مدمجة، أو رد تلقائي على واتساب، أو أي حاجة تانية.';
    }

    /**
     * مساعد مبيعات ذكي للموظف: بيقرا سكرين شات واتساب (لو مرفق) أو رسالة الموظف، ويقترح ردّ احترافي ومقنع
     * بنفس لغة المحادثة، ومبني على حقايق حقيقية بس عن المنصة. بيحاول كمان يستخرج اسم الشركة وملخص وموعد متابعة
     * (لو اتقال زي "بعد 3 أيام")، لكن مابيحفظش حاجة في أي ملف - الموظف هو اللي بيراجع ويسجّل بنفسه.
     * @param array{binary:string,mime:string}|null $image
     * @param callable|null $ai (?array $image, string $prompt) => ['success'=>bool,'data'=>string]
     * @return array{ok:bool,error?:string,company_hint?:string,language?:string,summary?:string,suggested_reply?:string,followup_date?:?string}
     */
    /**
     * @param array<int,array{role:string,text:string}> $history آخر أدوار المحادثة (موظفة/مساعد)، من غير صور - عشان المساعد يفهم سياق اللي قبله
     * ويقدر يعدّل على رده (زي "اختصريه" أو "بالإنجليزي") بدل ما يبدأ من الصفر كل مرة.
     */
    public function assistantDraftReply(string $message, ?array $image, int $userId, ?callable $ai = null, string $leadContext = '', array $history = []): array
    {
        $message = trim(mb_substr($message, 0, 1000));
        if ($message === '' && !$image) {
            return ['ok' => false, 'error' => 'اكتب حاجة أو ارفق سكرين المحادثة'];
        }
        $today = date('Y-m-d (l)');
        $leadContext = trim(mb_substr($leadContext, 0, 1000));
        $historyText = '';
        foreach (array_slice($history, -10) as $turn) {
            $role = ($turn['role'] ?? '') === 'assistant' ? 'المساعد' : 'الموظفة';
            $text = trim(mb_substr((string) ($turn['text'] ?? ''), 0, 600));
            if ($text !== '') {
                $historyText .= "[{$role}]: {$text}\n";
            }
        }
        $prompt = 'أنت خبير تسويق ومبيعات محترف، بتساعد موظفة مبيعات في شركة Tourfecto (منصة SaaS لشركات السياحة) تكمّل محادثة واتساب مع شركة سياحة محتملة. '
            . ($image ? 'دي صورة سكرين شات واتساب بين الموظفة والشركة: اقرا المحادثة كلها، وركّز على آخر رسالة من الشركة. ' : '')
            . 'حقايق حقيقية عن المنصة تقدر تستخدمها (ممنوع غيرها): ' . $this->assistantFacts() . "\n"
            . 'النهارده: ' . $today . ". لو الشركة ذكرت وقت للمتابعة (زي \"بعد 3 أيام\" أو \"الأسبوع الجاي\"), احسب التاريخ الفعلي بناء على النهارده واكتبه YYYY-MM-DD HH:MM (افتراضي الساعة 12 ظهرًا لو مفيش وقت محدد). لو مفيش موعد، سيبه فاضي.\n"
            . ($leadContext !== '' ? "معلومات معروفة عن الشركة دي من ملفها عندنا (استخدميها عشان الردّ يبقى مخصّص ليها، ومتكرريش حاجة هي عارفاها أو اتبعتلها قبل كده):\n" . $leadContext . "\n" : '')
            . ($historyText !== '' ? "المحادثة اللي بينك وبين الموظفة لحد دلوقتي (ردّك الجاي لازم يبني عليها، مش يتجاهلها):\n" . $historyText : '')
            . ($message !== '' ? 'رسالة الموظفة الجديدة دلوقتي: ' . $message . "\n" : '')
            . 'ردّ بـ JSON بس من غير أي نص تاني: {"company_hint": "اسم الشركة لو ظاهر، وإلا فاضي", "language": "ar أو en أو tr حسب لغة المحادثة", "summary": "سطر واحد يلخّص آخر رد من الشركة (هيتسجل كملاحظة)", "suggested_reply": "الردّ المقترح، قصير (3-5 أسطر)، بنفس لغة المحادثة، احترافي ومقنع ومبني على الحقايق بس", "followup_date": "YYYY-MM-DD HH:MM أو فاضي"}';
        try {
            $ai ??= static function (?array $img, string $p) {
                $client = new GeminiClient();
                return $img ? $client->describeImage($img['binary'], $img['mime'], $p, ['temperature' => 0.4]) : $client->generateContent($p, ['temperature' => 0.4, 'task' => 'outreach_assistant', 'user_id' => 0]);
            };
            $r = $ai($image, $prompt);
        } catch (Throwable $e) {
            Logger::warning('Outreach assistant failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'المساعد مش متاح دلوقتي، جرّبي تاني بعد شوية'];
        }
        if (empty($r['success'])) {
            return ['ok' => false, 'error' => (string) ($r['error'] ?? 'المساعد مقدرش يرد دلوقتي')];
        }
        $text = (string) ($r['data'] ?? $r['text'] ?? '');
        if (!preg_match('/\{.*\}/s', $text, $m) || !is_array($j = json_decode($m[0], true))) {
            return ['ok' => false, 'error' => 'مقدرتش أفهم الرد، جرّبي تاني'];
        }
        $followup = trim((string) ($j['followup_date'] ?? ''));
        try {
            $followup = $followup !== '' ? $this->normalizeFollowupAt($followup) : null;
        } catch (Throwable $e) {
            $followup = null; // تاريخ غريب من الذكاء الاصطناعي: نسيبه للموظفة تحدده يدوي
        }
        return ['ok' => true, 'company_hint' => trim(mb_substr((string) ($j['company_hint'] ?? ''), 0, 150)),
            'language' => in_array($j['language'] ?? '', ['ar', 'en', 'tr'], true) ? $j['language'] : 'ar',
            'summary' => trim(mb_substr((string) ($j['summary'] ?? ''), 0, 300)), 'suggested_reply' => trim(mb_substr((string) ($j['suggested_reply'] ?? ''), 0, 1500)),
            'followup_date' => $followup];
    }

    private static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;
        return round(6371 * 2 * asin(min(1, sqrt($a))), 1);
    }

    /** عنوان → إحداثيات (Google Geocoding بمفتاح الخرائط اللي عندنا). null لو مش متاح */
    private function geocodeAddress(string $address): ?array
    {
        $key = '';
        try {
            $s = new SystemSettingsService();
            $key = $s->get('google_maps_api_key', '') ?: $s->get('integration_google_maps_api_key', '');
        } catch (Throwable $e) {
            Logger::warning('Geocode key unavailable', ['error' => $e->getMessage()]);
        }
        if ($key === '' && defined('GOOGLE_MAPS_API_KEY')) {
            $key = (string) GOOGLE_MAPS_API_KEY;
        }
        if ($key === '' || trim($address) === '' || !function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init('https://maps.googleapis.com/maps/api/geocode/json?region=eg&address=' . rawurlencode($address) . '&key=' . rawurlencode($key));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_SSL_VERIFYPEER => true]);
        $body = curl_exec($ch);
        curl_close($ch);
        $loc = (is_string($body) ? json_decode($body, true) : null)['results'][0]['geometry']['location'] ?? null;
        return is_array($loc) && isset($loc['lat'], $loc['lng']) ? ['lat' => (float) $loc['lat'], 'lng' => (float) $loc['lng']] : null;
    }

    /** الموظفة سمحت بالموقع: بنسجّله (أول مرة = الأساس). مرة واحدة بإذنها، مش تتبّع مستمر */
    public function staffLocationSave(int $userId, float $lat, float $lng, ?int $accuracy): array
    {
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || (abs($lat) < 0.0001 && abs($lng) < 0.0001)) {
            throw new InvalidArgumentException('الموقع غير صحيح');
        }
        $first = !$this->q('SELECT 1 FROM platform_staff_location WHERE user_id=? LIMIT 1', [$userId])->fetchColumn();
        $this->q('INSERT INTO platform_staff_location(user_id,lat,lng,accuracy_m,is_first) VALUES(?,?,?,?,?)', [$userId, round($lat, 6), round($lng, 6), $accuracy !== null && $accuracy >= 0 ? min($accuracy, 1000000) : null, $first ? 1 : 0]);
        return $this->staffLocation($userId) ?? [];
    }

    /** الموقع الأساسي (أول مرة) وآخر موقع، وبعدهم عن العنوان المسجّل في الملف */
    public function staffLocation(int $userId): ?array
    {
        $rows = $this->q('SELECT * FROM platform_staff_location WHERE user_id=? ORDER BY id', [$userId])->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return null;
        }
        $first = $rows[0];
        $last = end($rows);
        $address = trim((string) ($this->q('SELECT address FROM platform_staff_profile WHERE user_id=?', [$userId])->fetchColumn() ?: ''));
        $addrGeo = null;
        if ($address !== '') {
            $tag = substr(md5($address), 0, 12);
            if (($first['address_for'] ?? '') !== $tag) {
                $g = $this->geocodeAddress($address); // مرة لكل عنوان (حتى لو فشلت: ماننادش جوجل كل مرة)
                $this->q('UPDATE platform_staff_location SET address_lat=?,address_lng=?,address_for=? WHERE id=?', [$g['lat'] ?? null, $g['lng'] ?? null, $tag, $first['id']]);
                $first['address_lat'] = $g['lat'] ?? null;
                $first['address_lng'] = $g['lng'] ?? null;
            }
            if ($first['address_lat'] !== null && $first['address_lng'] !== null) {
                $addrGeo = ['lat' => (float) $first['address_lat'], 'lng' => (float) $first['address_lng']];
            }
        }
        $pt = static fn (array $r) => ['lat' => (float) $r['lat'], 'lng' => (float) $r['lng'], 'accuracy_m' => $r['accuracy_m'] !== null ? (int) $r['accuracy_m'] : null, 'at' => (string) $r['captured_at'],
            'maps_url' => 'https://www.google.com/maps?q=' . (float) $r['lat'] . ',' . (float) $r['lng']];
        $dist = $addrGeo ? self::distanceKm((float) $first['lat'], (float) $first['lng'], $addrGeo['lat'], $addrGeo['lng']) : null;
        return ['first' => $pt($first), 'latest' => $pt($last), 'count' => count($rows), 'has_address' => $address !== '', 'address_located' => $addrGeo !== null,
            'distance_km' => $dist, 'far' => $dist !== null && $dist > self::LOCATION_FAR_KM, 'far_km' => self::LOCATION_FAR_KM];
    }

    /** الاسم اللي بيتوقّع بيه الموظف رسايله ('' لو ماسجّلش اسم) */
    public function staffSignName(int $userId): string
    {
        try {
            return trim((string) $this->q('SELECT sign_name FROM platform_staff_profile WHERE user_id=?', [$userId])->fetchColumn());
        } catch (Throwable $e) {
            return '';
        }
    }

    /** مالك المنصة بيطلب من موظف يعيد تصوير البطاقة والصورة الشخصية: مابيتمسحش حاجة، والتنبيه بيظهر للموظف لحد ما يخلّص الصورة الشخصية (آخر خطوة) */
    public function staffRecaptureRequest(int $userId, string $by): void
    {
        if (!$this->q('SELECT 1 FROM users WHERE id=?', [$userId])->fetchColumn()) {
            throw new InvalidArgumentException('الموظف مش موجود');
        }
        $this->q('INSERT IGNORE INTO platform_staff_profile(user_id) VALUES(?)', [$userId]);
        $this->q('UPDATE platform_staff_profile SET recapture_at=NOW(), updated_by=? WHERE user_id=?', [mb_substr($by, 0, 120), $userId]);
    }

    /** للموظفة نفسها: إيه الناقص عليها (موقع، بيانات، صورة بطاقة) عشان تتنبّه أول ما تدخل المكتب */
    public function staffMe(int $userId): array
    {
        $goal = $this->q('SELECT daily_goal FROM platform_staff_profile WHERE user_id=?', [$userId])->fetchColumn();
        return ['location_recorded' => (bool) $this->q('SELECT 1 FROM platform_staff_location WHERE user_id=? LIMIT 1', [$userId])->fetchColumn(),
            'profile_missing' => count($this->staffProfile($userId, false)['missing']), 'recapture' => $recap = (bool) $this->q('SELECT recapture_at FROM platform_staff_profile WHERE user_id=?', [$userId])->fetchColumn(),
            'id_card' => !$recap && ($docs = $this->staffIdCardStatus($userId))['front'] !== null && $docs['photo'] !== null,
            'daily_goal' => $goal !== false && $goal !== null ? (int) $goal : null, 'sent_today' => $this->staffSentToday($userId)];
    }

    /** عدد الرسايل اللي الموظف بعتها النهارده (contacted) - أساس شريط «هدفك النهارده» */
    public function staffSentToday(int $userId): int
    {
        return (int) $this->q("SELECT COUNT(*) FROM platform_desk_actions WHERE user_id=? AND action='contacted' AND DATE(created_at)=CURDATE()", [$userId])->fetchColumn();
    }

    /** كل الموظفين اللي سجّلوا شغل على المكتب أو عندهم تعويض متسجّل، مع عدد إجراءاتهم */
    /** لوحة أداء يومية للمالك: كل موظف وهدفه اليومي وكام بعت وكام شركة ردّت عليه النهارده */
    public function teamDailyStats(): array
    {
        $staff = $this->staffList();
        if (!$staff) {
            return ['staff' => [], 'totals' => ['sent' => 0, 'replied' => 0]];
        }
        $ids = implode(',', array_map(static fn ($s) => (int) $s['user_id'], $staff));
        $sent = array_column($this->q("SELECT user_id, COUNT(*) n FROM platform_desk_actions WHERE user_id IN ($ids) AND action='contacted' AND DATE(created_at)=CURDATE() GROUP BY user_id")->fetchAll(PDO::FETCH_ASSOC), 'n', 'user_id');
        $replied = array_column($this->q("SELECT user_id, COUNT(DISTINCT lead_id) n FROM platform_desk_actions WHERE user_id IN ($ids) AND action IN ('replied','interested') AND DATE(created_at)=CURDATE() GROUP BY user_id")->fetchAll(PDO::FETCH_ASSOC), 'n', 'user_id');
        $goals = array_column($this->q("SELECT user_id, daily_goal FROM platform_staff_profile WHERE user_id IN ($ids)")->fetchAll(PDO::FETCH_ASSOC), 'daily_goal', 'user_id');
        $out = [];
        $totalSent = 0;
        $totalReplied = 0;
        foreach ($staff as $s) {
            $uid = (int) $s['user_id'];
            $sentN = (int) ($sent[$uid] ?? 0);
            $repliedN = (int) ($replied[$uid] ?? 0);
            $totalSent += $sentN;
            $totalReplied += $repliedN;
            $out[] = ['user_id' => $uid, 'name' => $s['name'], 'sent_today' => $sentN, 'replied_today' => $repliedN, 'daily_goal' => isset($goals[$uid]) && $goals[$uid] !== null ? (int) $goals[$uid] : null];
        }
        usort($out, static fn ($a, $b) => $b['sent_today'] <=> $a['sent_today']);
        return ['staff' => $out, 'totals' => ['sent' => $totalSent, 'replied' => $totalReplied]];
    }

    public function staffList(): array
    {
        $sql = "SELECT u.id user_id, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)),''), SUBSTRING_INDEX(u.email,'@',1)) name, u.email,
                COALESCE(c.comp_type,'none') comp_type, COALESCE(c.commission_pct,0) commission_pct, COALESCE(c.monthly_salary,0) monthly_salary,
                (SELECT COUNT(*) FROM platform_desk_actions a WHERE a.user_id=u.id AND a.action<>'undo') actions
            FROM users u LEFT JOIN platform_staff_comp c ON c.user_id=u.id
            WHERE u.id IN (SELECT user_id FROM platform_desk_actions WHERE user_id IS NOT NULL) OR u.id IN (SELECT user_id FROM platform_staff_comp)%s
            ORDER BY actions DESC, name";
        try {
            $rows = $this->q(sprintf($sql, ' OR u.id IN (SELECT user_id FROM admin_staff)'))->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $rows = $this->q(sprintf($sql, ''))->fetchAll(PDO::FETCH_ASSOC); // جدول فريق الأدمن لسه ما اتعملش
        }
        return array_map(fn ($r) => ['user_id' => (int) $r['user_id'], 'name' => (string) $r['name'], 'email' => (string) $r['email'], 'comp_type' => (string) $r['comp_type'],
            'commission_pct' => (float) $r['commission_pct'], 'monthly_salary' => (float) $r['monthly_salary'], 'actions' => (int) $r['actions'],
            'profile_missing' => count($this->staffProfile((int) $r['user_id'], false)['missing'])], $rows);
    }

    /**
     * العميل بيتنسب لأول موظفة سجّلت إرسال واتساب ليه (التراجع بيمسح الإرسال: بنتجاهله).
     * @return array<int,array{user_id:int,name:string,at:string}> lead_id => الموظفة
     */
    public function staffAttribution(): array
    {
        $first = [];
        foreach ($this->q("SELECT lead_id,user_id,user_name,action,created_at FROM platform_desk_actions WHERE action IN ('contacted','undo','released') AND user_id IS NOT NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $lid = (int) $r['lead_id'];
            if ($r['action'] !== 'contacted') {
                unset($first[$lid]);
            } elseif (!isset($first[$lid])) {
                $first[$lid] = ['user_id' => (int) $r['user_id'], 'name' => (string) $r['user_name'], 'at' => (string) $r['created_at']];
            }
        }
        return $first;
    }

    /**
     * تذكير يومي بالإيميل لكل موظف عنده شركات محتاجة متابعة (مرة واحدة في اليوم، من الكرون بعد الساعة 9).
     * @param callable|null $send (to, name, subject, html, headers) => mixed
     * @return int عدد الموظفين اللي اتبعتلهم تذكير
     */
    public function sendFollowupDigest(?callable $send = null, ?string $today = null): int
    {
        $today ??= date('Y-m-d');
        if ($this->settings->get('outreach_digest_date', '') === $today) {
            return 0;
        }
        $rows = $this->q("SELECT company, desk_user_id uid FROM platform_leads WHERE " . self::followupSql() . " AND desk_user_id IS NOT NULL AND status<>'unsubscribed' ORDER BY desk_at LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
        $by = [];
        foreach ($rows as $r) {
            $by[(int) $r['uid']][] = (string) $r['company'];
        }
        $this->settings->set('outreach_digest_date', $today); // قبل الإرسال: لو الإرسال فشل مانكرّرش الإيميل كل 5 دقايق
        if (!$by) {
            return 0;
        }
        if (!$send) {
            $set = $this->getSettings();
            $mailer = new Mailer();
            if ($set['smtp_host'] !== '') {
                $mailer->configure(['host' => $set['smtp_host'], 'port' => (int) $set['smtp_port'], 'username' => $set['smtp_username'], 'password' => $set['smtp_password'], 'encryption' => $set['smtp_encryption']]);
            }
            $mailer->configure(['from_email' => $set['from_email'], 'from_name' => $set['sender_name']]);
            if (method_exists($mailer, 'context')) {
                $mailer->context('platform_outreach_digest');
            }
            $send = static fn ($to, $name, $subject, $html, $headers) => $mailer->send($to, $name, $subject, $html, $headers);
        }
        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://tourfecto.pro';
        $sent = 0;
        foreach ($by as $uid => $companies) {
            $u = $this->q("SELECT email, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', first_name, last_name)),''), SUBSTRING_INDEX(email,'@',1)) name FROM users WHERE id=?", [$uid])->fetch(PDO::FETCH_ASSOC);
            if (!$u || !filter_var($u['email'], FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $n = count($companies);
            $list = implode('', array_map(static fn ($c) => '<li>' . htmlspecialchars($c, ENT_QUOTES, 'UTF-8') . '</li>', array_slice($companies, 0, 10)));
            $html = '<div dir="rtl" style="font-family:Arial,sans-serif;line-height:1.7"><p>' . $n . ' شركة محتاجة متابعة النهارده (اتبعتلها واتساب من يومين أو أكتر من غير نتيجة):</p><ul>' . $list . '</ul>'
                . ($n > 10 ? '<p>وغيرها ' . ($n - 10) . '…</p>' : '') . '<p><a href="' . htmlspecialchars($base . '/admin/outreach-desk', ENT_QUOTES, 'UTF-8') . '">فتح مكتب التواصل ← محتاجة متابعة</a></p></div>';
            try {
                $send((string) $u['email'], (string) $u['name'], '⏰ ' . $n . ' شركة محتاجة متابعة النهارده', $html, []);
                $sent++;
            } catch (Throwable $e) {
                Logger::warning('Follow-up digest failed', ['user' => $uid, 'error' => $e->getMessage()]);
            }
        }
        return $sent;
    }

    /** مبلغ بأي عملة => جنيه: الجنيه زي ما هو، والدولار بسعر الإعدادات، والباقي من أسعار صرف المنصة (CurrencyService) عن طريق الدولار. null = عملة مش معروفة */
    public static function toEgp(float $amount, string $cur, float $usdEgp): ?float
    {
        $cur = strtoupper($cur ?: 'USD');
        if ($cur === 'EGP') {
            return $amount;
        }
        if ($cur === 'USD') {
            return $amount * $usdEgp;
        }
        if (!class_exists('CurrencyService') || !CurrencyService::valid($cur) || CurrencyService::rate($cur) <= 0) {
            return null;
        }
        return ($amount / CurrencyService::rate($cur)) * $usdEgp;
    }

    /** @return array<int,array<int,array{paid_at:string}>> user_id => فواتيرهم المدفوعة (قراية بس) */
    private function paidInvoiceDates(): array
    {
        $out = [];
        try {
            foreach ($this->q("SELECT user_id, paid_at FROM invoices WHERE status='paid' AND paid_at IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int) $r['user_id']][] = (string) $r['paid_at'];
            }
        } catch (Throwable $e) {
            Logger::warning('Invoices unavailable for the staff statement', ['error' => $e->getMessage()]);
        }
        return $out;
    }

    /** فاتورة مدفوعة تغطي الشهر؟ الشهري: دفع جوّه الشهر، والسنوي: دفع في آخر 12 شهر لحد آخر الشهر */
    public static function coveredByPaidInvoice(array $paidAt, string $planType, string $from, string $to): bool
    {
        $start = $planType === 'yearly' ? date('Y-m-d 00:00:00', strtotime(substr($from, 0, 10) . ' -11 months')) : $from;
        foreach ($paidAt as $d) {
            if ($d >= $start && $d <= $to) {
                return true;
            }
        }
        return false;
    }

    /** الشركات من القايمة اللي بقت عملاء باشتراك مدفوع (مطابقة إيميل الحساب أو دومين الموقع). قراية بس */
    private function paidCustomers(): array
    {
        $cols = 'l.id lead_id, l.company, s.user_id uid, s.id sub_id, s.plan_name, s.plan_type, s.status, s.price, s.currency, s.start_date, s.cancelled_at, s.expiry_date';
        $queries = [
            "SELECT {$cols} FROM platform_leads l JOIN users u ON LOWER(u.email)=LOWER(l.email) JOIN subscriptions s ON s.user_id=u.id WHERE l.status<>'invalid' AND COALESCE(s.price,0)>0",
            "SELECT {$cols} FROM platform_leads l JOIN websites w ON l.domain IS NOT NULL AND LOWER(REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(w.main_url,'://',-1),'/',1),'www.',''))=l.domain
                JOIN subscriptions s ON s.user_id=w.user_id WHERE l.status<>'invalid' AND COALESCE(s.price,0)>0",
        ];
        $out = [];
        foreach ($queries as $sql) {
            try {
                foreach ($this->q($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $out[$r['lead_id'] . ':' . $r['sub_id']] = $r;
                }
            } catch (Throwable $e) {
                Logger::warning('Staff customers query failed', ['error' => $e->getMessage()]);
            }
        }
        return array_values($out);
    }

    /**
     * كشف موظفة لشهر (YYYY-MM): شغلها بالتاريخ والتفاصيل، والعملاء اللي جابتهم ونسبتها الشهرية، والراتب، والإجمالي.
     * النسبة = نسبة من قيمة الاشتراك الشهرية (السنوي ÷ 12) لكل عميل كان اشتراكه ساري في الشهر ده. حساب بس: مش دليل إن الدفع وصل.
     * العملات: جنيه، ودولار بسعر $usdEgp؛ غير كده بيظهر في الملاحظات ومابيتحسبش.
     */
    public function staffReport(int $userId, string $month, float $usdEgp = 50.0, bool $revealProfile = false, bool $revealLocation = false): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $month = date('Y-m');
        }
        $from = $month . '-01 00:00:00';
        $to = date('Y-m-d 23:59:59', strtotime($month . '-01 +1 month -1 day'));
        $u = $this->q("SELECT id, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', first_name, last_name)),''), SUBSTRING_INDEX(email,'@',1)) name, email FROM users WHERE id=?", [$userId])->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
            throw new InvalidArgumentException('الموظف مش موجود');
        }
        $comp = $this->staffCompGet($userId);

        $labels = ['contacted' => 'اتبعت واتساب', 'replied' => 'ردّوا', 'interested' => 'مهتمين', 'trial' => 'جرّبوا المنصة', 'subscribed' => 'اشتركوا', 'call_back' => 'هتكلمهم تاني', 'not_interested' => 'مش مهتمين', 'wrong_number' => 'الرقم غلط', 'undo' => 'تراجع عن الإرسال', 'released' => 'رجعت للقايمة (الموظف ساب الشغل)'];
        $log = $this->q('SELECT a.created_at at, a.action, a.note, l.company, l.country FROM platform_desk_actions a LEFT JOIN platform_leads l ON l.id=a.lead_id WHERE a.user_id=? AND a.created_at BETWEEN ? AND ? ORDER BY a.id DESC LIMIT 500', [$userId, $from, $to])->fetchAll(PDO::FETCH_ASSOC);
        $count = static fn (array $acts): int => count(array_unique(array_map(static fn ($r) => $r['company'] . '|' . $r['action'], array_filter($log, static fn ($r) => in_array($r['action'], $acts, true)))));
        $summary = ['actions' => count($log), 'sent' => $count(['contacted']), 'replied' => $count(['replied', 'interested', 'trial', 'subscribed']), 'trial' => $count(['trial', 'subscribed']), 'subscribed' => $count(['subscribed'])];

        $attr = $this->staffAttribution();
        $customers = [];
        $skipped = [];
        $commissionTotal = 0.0;
        $confirmedTotal = 0.0;
        $paidInvoices = $this->paidInvoiceDates();
        $pct = in_array($comp['comp_type'], ['commission', 'both'], true) ? $comp['commission_pct'] : 0.0;
        foreach ($this->paidCustomers() as $c) {
            if (($attr[(int) $c['lead_id']]['user_id'] ?? 0) !== $userId) {
                continue;
            }
            $start = (string) $c['start_date'];
            $end = $c['cancelled_at'] ?: ($c['status'] === 'active' ? null : $c['expiry_date']);
            $active = $start !== '' && $start <= $to && ($c['status'] === 'active' || ($end !== null && $end >= $from));
            $monthly = (float) $c['price'] / ($c['plan_type'] === 'yearly' ? 12 : 1);
            $cur = strtoupper((string) ($c['currency'] ?: 'USD'));
            $egp = self::toEgp($monthly, $cur, $usdEgp);
            if ($active && $egp === null) {
                $skipped[] = $c['company'] . ' (' . $cur . ')';
            }
            $commission = $active && $egp !== null ? round($egp * $pct / 100, 2) : 0.0;
            // الدفع مؤكّد = فاتورة مدفوعة تغطي الشهر ده (الشهري: اتدفعت في الشهر، والسنوي: اتدفعت في آخر 12 شهر)
            $paid = $active && self::coveredByPaidInvoice($paidInvoices[(int) $c['uid']] ?? [], (string) $c['plan_type'], $from, $to);
            $commissionTotal += $commission;
            $confirmedTotal += $paid ? $commission : 0.0;
            $customers[] = ['lead_id' => (int) $c['lead_id'], 'company' => (string) $c['company'], 'plan' => (string) $c['plan_name'], 'cycle' => (string) $c['plan_type'], 'status' => (string) $c['status'],
                'since' => $start, 'attributed_at' => $attr[(int) $c['lead_id']]['at'] ?? null, 'active_in_month' => $active, 'paid_confirmed' => $paid, 'monthly_egp' => $egp !== null ? round($egp, 2) : null, 'commission_egp' => $commission];
        }
        usort($customers, static fn ($a, $b) => [$b['active_in_month'], $b['commission_egp']] <=> [$a['active_in_month'], $a['commission_egp']]);

        $salary = in_array($comp['comp_type'], ['salary', 'both'], true) ? $comp['monthly_salary'] : 0.0;
        return [
            'staff' => ['user_id' => (int) $u['id'], 'name' => (string) $u['name'], 'email' => (string) $u['email']],
            'month' => $month,
            'profile' => $this->staffProfile($userId, $revealProfile),
            // حالة رفع البطاقة والصورة لصاحب الملف ولمالك المنصة. الموقع (الإحداثيات والمسافة من العنوان) لمالك المنصة بس:
            // الموظف بيعرف إنه اتسجّل ومش أكتر - وحتى في رد الـ API نفسه (مش بس الشاشة) مابيوصلوش وقت فيديوهات الكاميرا، عشان محدش يلاقي دليل عليها بفتح أدوات المطوّر
            'id_card' => $revealProfile ? ($revealLocation ? $this->staffIdCardStatus($userId) : array_intersect_key($this->staffIdCardStatus($userId), array_flip(['front', 'back', 'photo']))) : null,
            'location' => $revealLocation ? $this->staffLocation($userId) : null,
            'doc_views' => $revealLocation ? $this->staffDocViews($userId) : [],
            'id_history' => $revealLocation ? $this->staffIdCardHistory($userId) : [],
            'location_recorded' => $revealProfile ? (bool) $this->q('SELECT 1 FROM platform_staff_location WHERE user_id=? LIMIT 1', [$userId])->fetchColumn() : null,
            'comp' => $comp,
            'summary' => $summary,
            'customers' => $customers,
            'customers_total' => count($customers),
            'customers_active' => count(array_filter($customers, static fn ($c) => $c['active_in_month'])),
            'log' => array_map(static fn ($r) => ['at' => (string) $r['at'], 'action' => (string) $r['action'], 'label' => $labels[$r['action']] ?? (string) $r['action'], 'note' => $r['note'], 'company' => (string) ($r['company'] ?? ''), 'country' => (string) ($r['country'] ?? '')], $log),
            // المستحق = النسبة المؤكّد دفعها + الراتب. النسبة اللي دفعها مش متأكد منه بتظهر لوحدها (بانتظار فاتورة مدفوعة) ومابتدخلش في الإجمالي
            'totals' => ['commission_egp' => round($commissionTotal, 2), 'commission_confirmed_egp' => round($confirmedTotal, 2), 'commission_pending_egp' => round($commissionTotal - $confirmedTotal, 2), 'salary_egp' => round($salary, 2), 'total_egp' => round($confirmedTotal + $salary, 2)],
            'notes' => array_values(array_filter([
                'النسبة بتتحسب على الاشتراكات السارية، وبتدخل في الإجمالي لما تلاقي فاتورة مدفوعة للعميل. اللي من غير فاتورة مدفوعة بيظهر «بانتظار تأكيد الدفع».',
                'الدولار بسعر ' . rtrim(rtrim(number_format($usdEgp, 2, '.', ''), '0'), '.') . ' جنيه، والعملات التانية (ريال، درهم، يورو...) بأسعار الصرف المسجّلة في المنصة. الاشتراك السنوي بيتحسب ÷ 12.',
                $skipped ? 'عملاء بعملة مش في أسعار الصرف ومابيتحسبوش: ' . implode('، ', array_unique($skipped)) : null,
            ])),
        ];
    }

    /**
     * قمع النتايج لدولة: واتساب (اتبعتلهم · ردّوا · جرّبوا · اشتركوا · نسبة الرد) وإيميل (اتبعتلهم · فتحوا التقرير · سجّلوا)،
     * وأداء كل موظفة آخر 7 أيام. «ردّوا/جرّبوا/اشتركوا» من سجل الإجراءات (مش بتتمسح)، و«اتبعتلهم» من الحالة الحالية (التراجع بيمسحها)
     */
    public function deskFunnel(string $country): array
    {
        $cc = "status<>'invalid'"; // كل شركات الدولة (للإيميل)
        $ca = [];
        if ($country === '?') {
            $cc .= ' AND country IS NULL';
        } elseif (preg_match('/^[A-Z]{2}$/', $country)) {
            $cc .= ' AND country=?';
            $ca[] = $country;
        }
        $cw = "phone IS NOT NULL AND phone<>'' AND " . $cc; // اللي ليها رقم (للواتساب)
        $wa = $this->q("SELECT COUNT(*) total,
                SUM(desk_action IS NOT NULL AND desk_action<>'wrong_number') reached,
                SUM(desk_action IS NOT NULL AND desk_action<>'wrong_number' AND DATE(desk_at)=CURDATE()) today
            FROM platform_leads WHERE {$cw}", $ca)->fetch(PDO::FETCH_ASSOC) ?: [];
        $ever = static fn (array $actions): string => "COUNT(DISTINCT CASE WHEN a.action IN ('" . implode("','", $actions) . "') THEN a.lead_id END)";
        $log = $this->q('SELECT ' . $ever(['replied', 'interested', 'trial', 'subscribed']) . ' replied, ' . $ever(['trial', 'subscribed']) . ' trial, ' . $ever(['subscribed']) . " subscribed
            FROM platform_desk_actions a JOIN platform_leads l ON l.id=a.lead_id WHERE {$cw}", $ca)->fetch(PDO::FETCH_ASSOC) ?: [];
        $staff = $this->q("SELECT a.user_name name,
                COUNT(DISTINCT CASE WHEN a.action='contacted' THEN a.lead_id END) sent,
                COUNT(DISTINCT CASE WHEN a.action IN ('replied','interested','trial','subscribed') THEN a.lead_id END) replied
            FROM platform_desk_actions a JOIN platform_leads l ON l.id=a.lead_id
            WHERE {$cw} AND a.created_at >= NOW() - INTERVAL 7 DAY AND a.user_name<>'' GROUP BY a.user_name ORDER BY sent DESC LIMIT 20", $ca)->fetchAll(PDO::FETCH_ASSOC);
        $em = $this->q("SELECT SUM(step>0) sent, SUM(step>0 AND report_viewed_at IS NOT NULL) viewed, SUM(status='signed_up') signed_up FROM platform_leads WHERE {$cc}", $ca)->fetch(PDO::FETCH_ASSOC) ?: [];
        $sent = (int) ($wa['reached'] ?? 0);
        $replied = (int) ($log['replied'] ?? 0);
        return [
            'whatsapp' => [
                'companies' => (int) ($wa['total'] ?? 0),
                'sent' => $sent,
                'sent_today' => (int) ($wa['today'] ?? 0),
                'replied' => $replied,
                'trial' => (int) ($log['trial'] ?? 0),
                'subscribed' => (int) ($log['subscribed'] ?? 0),
                'reply_rate' => $sent > 0 ? round(min(100, $replied * 100 / $sent), 1) : null,
            ],
            'email' => ['sent' => (int) ($em['sent'] ?? 0), 'report_viewed' => (int) ($em['viewed'] ?? 0), 'signed_up' => (int) ($em['signed_up'] ?? 0)],
            'staff' => array_map(static fn ($r) => ['name' => (string) $r['name'], 'sent' => (int) $r['sent'], 'replied' => (int) $r['replied']], $staff),
        ];
    }

    /**
     * الموظفة بتسجّل نتيجة التواصل مع شركة. الرد/الاهتمام/الرفض بيوقّفوا رسايل الإيميل للشركة دي، وإلغاء الاشتراك والتسجيل والراجع مابيتغيروش.
     */
    public function deskAction(int $leadId, string $action, string $note, int $userId, string $userName, bool $manager = false, string $reason = '', ?string $followupAt = null): array
    {
        if ($action !== 'undo' && !in_array($action, self::DESK_ACTIONS, true)) {
            throw new InvalidArgumentException('نتيجة غير معروفة');
        }
        // سبب الرفض معناه بس مع «مش مهتمين»، ومطلوب (مش اختياري) عشان نعرف فعلًا السوق بيرفض ليه
        if ($action === 'not_interested') {
            if ($reason === '' || !isset(self::LOST_REASONS[$reason])) {
                throw new InvalidArgumentException('لازم تختار سبب الرفض');
            }
        } else {
            $reason = '';
        }
        $followupAt = $this->normalizeFollowupAt($followupAt);
        $l = self::tidyOrNull($this->q('SELECT * FROM platform_leads WHERE id=?', [$leadId])->fetch(PDO::FETCH_ASSOC) ?: null);
        if (!$l) {
            throw new InvalidArgumentException('الشركة مش موجودة');
        }
        if ($l['status'] === 'unsubscribed') {
            throw new InvalidArgumentException('الشركة دي ألغت الاشتراك: ممنوع التواصل معاها');
        }
        $note = mb_substr(trim($note), 0, 500);
        $by = mb_substr($userName, 0, 120);
        // أول موظف يبعت للشركة بياخدها: غيره مايقدرش يبعتلها ولا يسجّل عليها (409)، ومين عنده صلاحية الفريق يقدر
        $owner = (int) ($l['desk_user_id'] ?? 0);
        $claimed = (string) ($l['desk_action'] ?? '') !== '';
        $foreign = $claimed && $owner > 0 && $owner !== $userId;
        if ($foreign && !$manager) {
            throw new InvalidArgumentException('الشركة دي اتبعتلها ' . ((string) ($l['desk_by'] ?? '') ?: 'موظف تاني') . ' قبل كده', 409);
        }
        if ($action === 'undo') {
            // فتحت واتساب وماضغطتش إرسال: التراجع بيمسح "اتبعتت" بس، ومابيلمسش الرد أو الاهتمام
            if (!in_array((string) ($l['desk_action'] ?? ''), ['contacted', 'call_back'], true)) {
                throw new InvalidArgumentException('مفيش إرسال للتراجع عنه');
            }
            $this->q('UPDATE platform_leads SET desk_action=NULL, desk_note=NULL, desk_at=NULL, desk_by=NULL, desk_user_id=NULL, desk_reason=NULL, desk_followup_at=NULL WHERE id=?', [$leadId]);
            $this->q('INSERT INTO platform_desk_actions(lead_id,user_id,user_name,action,note) VALUES(?,?,?,?,?)', [$leadId, $userId ?: null, $by, 'undo', null]);
            return ['action' => null, 'note' => null, 'at' => null, 'by' => null, 'reason' => null, 'followup_at' => null];
        }
        if (!$claimed) {
            // مطالبة ذرّية: لو موظفين اتنين داسوا في نفس اللحظة، واحد بس يكسب (التاني بياخد 409 ويتشال من قايمته)
            $n = $this->q('UPDATE platform_leads SET desk_action=?, desk_note=?, desk_at=NOW(), desk_by=?, desk_user_id=?, desk_reason=?, desk_followup_at=? WHERE id=? AND desk_action IS NULL', [$action, $note !== '' ? $note : null, $by, $userId ?: null, $reason !== '' ? $reason : null, $followupAt, $leadId])->rowCount();
            if ($n !== 1) {
                $now = $this->q('SELECT desk_by FROM platform_leads WHERE id=?', [$leadId])->fetchColumn();
                throw new InvalidArgumentException('الشركة دي اتبعتلها ' . ($now ?: 'موظف تاني') . ' قبل كده', 409);
            }
        } elseif ($foreign) {
            // مدير بيسجّل نتيجة على شركة موظف: النتيجة بس، والشركة تفضل منسوبة للي بعتلها
            $this->q('UPDATE platform_leads SET desk_action=?, desk_note=?, desk_at=NOW(), desk_reason=?, desk_followup_at=? WHERE id=?', [$action, $note !== '' ? $note : null, $reason !== '' ? $reason : null, $followupAt, $leadId]);
        } else {
            $this->q('UPDATE platform_leads SET desk_action=?, desk_note=?, desk_at=NOW(), desk_by=?, desk_user_id=?, desk_reason=?, desk_followup_at=? WHERE id=?', [$action, $note !== '' ? $note : null, $by, $userId ?: null, $reason !== '' ? $reason : null, $followupAt, $leadId]);
        }
        $this->q('INSERT INTO platform_desk_actions(lead_id,user_id,user_name,action,note,reason) VALUES(?,?,?,?,?,?)', [$leadId, $userId ?: null, $by, $action, $note !== '' ? $note : null, $reason !== '' ? $reason : null]);
        // اللي ردّ أو اهتم أو رفض مايفضلش يوصله إيميل تلقائي
        $map = ['replied' => 'replied', 'interested' => 'interested', 'not_interested' => 'paused', 'trial' => 'interested'];
        if ($action === 'subscribed') {
            $map['subscribed'] = 'signed_up';
        }
        if (isset($map[$action]) && in_array($l['status'], ['new', 'no_email', 'contacted', 'finished', 'interested', 'replied', 'paused'], true)) {
            $this->setLeadStatus($leadId, $map[$action], 'مكتب التواصل: ' . $action . ($note !== '' ? ' - ' . $note : ''));
        }
        return ['action' => $action, 'note' => $note !== '' ? $note : null, 'at' => date('Y-m-d H:i:s'), 'by' => $by, 'reason' => $reason !== '' ? $reason : null, 'followup_at' => $followupAt];
    }

    /** موعد متابعة مخصّص من الموظفة ("كلّمهم الخميس الساعة 4") بدل القاعدة التلقائية (يومين من غير رد). لازم يكون في المستقبل. null/فاضي = مفيش موعد مخصّص */
    private function normalizeFollowupAt(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        $ts = strtotime($v);
        if ($ts === false) {
            throw new InvalidArgumentException('موعد المتابعة غير صحيح');
        }
        if ($ts < time() - 60) {
            throw new InvalidArgumentException('موعد المتابعة لازم يكون في المستقبل');
        }
        if ($ts > time() + 90 * 86400) {
            throw new InvalidArgumentException('موعد المتابعة بعيد أوي (أكتر من 3 شهور)');
        }
        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * الموظفة فتحت صفحة فيسبوك أو إنستجرام بتاعة الشركة وسجّلت اللي شافته (نشيطة · آخر منشور قديم · مفيهاش منشورات · مش موجودة).
     * ده اللي بيدخل الرسالة؛ المنصات نفسها مابتسمحش بقراءة المنشورات آليًا. $state '' = امسح اللي سجّلته.
     */
    public function deskSetSocial(int $leadId, string $network, string $state): array
    {
        if (!in_array($network, ['facebook', 'instagram'], true) || ($state !== '' && !in_array($state, OutreachAudit::SOCIAL_SEEN, true))) {
            throw new InvalidArgumentException('اختيار غير معروف');
        }
        $l = self::tidyOrNull($this->q('SELECT * FROM platform_leads WHERE id=?', [$leadId])->fetch(PDO::FETCH_ASSOC) ?: null);
        if (!$l) {
            throw new InvalidArgumentException('الشركة مش موجودة');
        }
        $seen = json_decode((string) ($l['desk_social'] ?? ''), true);
        $seen = is_array($seen) ? $seen : [];
        if ($state === '') {
            unset($seen[$network]);
        } else {
            $seen[$network] = $state;
        }
        $this->q('UPDATE platform_leads SET desk_social=? WHERE id=?', [$seen ? json_encode($seen) : null, $leadId]);
        return $seen;
    }

    /** علامات جاهزة مقترحة للشركة (تقدر تكتب أي كلمة تانية لحد 20 حرف) */
    public const DESK_TAGS = ['VIP', 'بارد', 'كبيرة', 'متابعة قريبة'];

    /** تاج حر على الشركة (VIP، بارد، كبيرة...) يساعد الفريق يفرز بصريًا. فاضي = مسح */
    public function deskSetTag(int $leadId, string $tag): ?string
    {
        $tag = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $tag) ?? '');
        if (mb_strlen($tag) > 20) {
            throw new InvalidArgumentException('التاج أطول من 20 حرف');
        }
        if (!$this->q('SELECT 1 FROM platform_leads WHERE id=?', [$leadId])->fetchColumn()) {
            throw new InvalidArgumentException('الشركة مش موجودة');
        }
        $this->q('UPDATE platform_leads SET desk_tag=? WHERE id=?', [$tag !== '' ? $tag : null, $leadId]);
        return $tag !== '' ? $tag : null;
    }

    public function signupLinkFor(array $lead, int $step): string
    {
        $set = $this->getSettings();
        return self::withParams((string) $set['signup_url'], ['utm_source' => 'outreach', 'utm_medium' => 'email', 'utm_campaign' => 'step' . $step, 'ref' => $lead['token']] + self::siteParam($lead));
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
        $l = self::tidyOrNull($this->q('SELECT * FROM platform_leads WHERE id=?', [$id])->fetch(PDO::FETCH_ASSOC) ?: null);
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
        // مانقولش "الموقع واقع" للشركة غير لو متأكدين: الدومين مش موجود أو السيرفر رافض الاتصال أو بيرجّع خطأ سيرفر.
        // 404 على الصفحة الرئيسية وتايم أوت وأخطاء SSL ممكن تكون مؤقتة أو من جهتنا، فمانحكمش بيها
        if ($code >= 500 || $code === 410) {
            return 'down';
        }
        if ($code === 0) {
            return preg_match('/DNS|resolve|Connection refused/i', $error) ? 'down' : 'error';
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
