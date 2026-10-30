<?php

/**
 * Tourfecto - Auto SEO Embed Service
 * بيحوّل الـWebsite Optimizer من "تحليل + كود تنسخه بإيدك" لـ"تنفيذ تلقائي
 * فعلي" على أي موقع خارجي (WordPress/Shopify/HTML عادي) من غير ما العميل
 * يلمس كود موقعه: العميل بيحط <script> واحد، والسكربت بيسحب من السيرفر كل
 * الإصلاحات المعتمدة ويحقنها في <head> وقت التحميل.
 *
 * الفرق عن Auto-Pilot الأصلي (Phase 13):
 * - الأصلي بيكتب في generated_websites (مواقع اتبنت بالـBuilder بتاعنا بس).
 * - ده بيشتغل على أي دومين خارجي عبر embed.js + auto_seo_applied_fixes.
 *
 * @version 1.0.0
 */
class AutoSeoEmbedService
{
    /** أوضاع الطيار الآلي وشروط التطبيق لكل وضع */
    private const MODE_SEVERITIES = [
        'off'          => [],
        'conservative' => ['critical', 'high'],
        'balanced'     => ['critical', 'high', 'medium'],
        'aggressive'   => ['critical', 'high', 'medium', 'low'],
    ];

    /** الحقول اللي السكربت يقدر يحقنها فعليًا في المتصفح */
    private const INJECTABLE_FIELDS = [
        'seo_title', 'seo_description', 'canonical_url', 'viewport',
        'og_tags', 'json_ld', 'faq_schema', 'speakable', 'image_alt',
        'image_lazy_load',
    ];

    /** حقول لازم تتخدم Server-Side (مش من المتصفح) */
    private const SERVER_SIDE_FIELDS = ['robots_txt', 'llms_txt', 'sitemap', 'image_webp_convert', 'hreflang_tags'];

    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * ربط موقع خارجي بالمنصة وتوليد التوكنات.
     * @return array ['embed_token'=>string,'api_key'=>string,'embed_code'=>string]
     */
    public function connectWebsite(int $userId, int $websiteId, string $method = 'script'): array
    {
        // ما نسمحش بربط دومين المنصة نفسها: الـ proxy كان هيحوّل المنصة لنفسها ويوقّفها
        $row = $this->db->query("SELECT main_url FROM websites WHERE id = ? AND user_id = ? LIMIT 1", [$websiteId, $userId]);
        $host = (string) (parse_url((string) ($row[0]['main_url'] ?? ''), PHP_URL_HOST) ?? '');
        if ($host !== '' && class_exists('SeoProxyService') && SeoProxyService::isPlatformHost($host)) {
            throw new Exception('لا يمكن ربط دومين المنصة نفسها بخدمة Auto SEO. اربط موقع عميل أو موقعك الخاص.');
        }

        // الكود اللي العميل حطه في موقعه بيفضل هو هو: تشغيل السيو تاني كان بيولّد توكن جديد فالكود المتركّب يبطل
        $prev = $this->db->query("SELECT embed_token, embed_api_key FROM websites WHERE id = ? AND user_id = ? LIMIT 1", [$websiteId, $userId])[0] ?? [];
        $embedToken = preg_match('/^emb_[a-f0-9]{24}$/', (string) ($prev['embed_token'] ?? '')) ? (string) $prev['embed_token'] : 'emb_' . bin2hex(random_bytes(12));
        $apiKey     = (string) ($prev['embed_api_key'] ?? '') !== '' ? (string) $prev['embed_api_key'] : 'tpk_' . bin2hex(random_bytes(16));

        // مركز الأتمتة: بعد الربط الإصلاحات بتشتغل لوحدها (balanced) إلا لو
        // العميل مختار "يدوي". كل إصلاح بيعدّي على بوابة المحرك قبل التطبيق.
        $initialMode = 'balanced';
        if (class_exists('AutomationEngine') && AutomationEngine::instance()->getPolicy($userId, 'website_fixes')['mode'] === 'manual') {
            $initialMode = 'off';
        }

        $this->db->exec(
            "UPDATE websites
                SET is_connected = 1,
                    connection_method = ?,
                    embed_token = ?,
                    embed_api_key = ?,
                    auto_pilot_mode = ?,
                    auto_fix_enabled = 1,
                    connected_at = NOW(),
                    last_sync_at = NOW()
              WHERE id = ? AND user_id = ?",
            [$method, $embedToken, $apiKey, $initialMode, $websiteId, $userId]
        );

        // Auto-connect: يولّد مفتاح IndexNow تلقائيًا (لو مش موجود) عشان
        // الفهرسة الفورية تكون جاهزة من أول لحظة ربط من غير خطوة إضافية.
        $this->ensureIndexNowKey($websiteId);

        // بداية الشغل فورًا بعد الربط: تدقيق + تطبيق الإصلاحات المسموحة،
        // بدل ما نستنى الدورة المجدولة الجاية.
        try {
            if ($initialMode !== 'off' && class_exists('QueueManager')) {
                (new QueueManager($this->db))->push('AutoSeoReauditJob', ['website_id' => $websiteId, 'user_id' => $userId, 'trigger' => 'connected'], 'default', 60);
            }
        } catch (Throwable $e) {
            // الطابور مش متاح - الدورة المجدولة هتغطي
        }

        return [
            'embed_token' => $embedToken,
            'api_key'     => $apiKey,
            'embed_code'  => $this->buildEmbedCode($embedToken),
        ];
    }

    /**
     * توليد مفتاح IndexNow تلقائيًا عند الربط لو مش موجود.
     */
    private function ensureIndexNowKey(int $websiteId): void
    {
        try {
            $rows = $this->db->query("SELECT indexnow_key FROM websites WHERE id = ? LIMIT 1", [$websiteId]);
            if (empty($rows) || !empty($rows[0]['indexnow_key'])) {
                return;
            }
            $key = (new IndexNowService())->generateKey();
            $this->db->exec("UPDATE websites SET indexnow_key = ? WHERE id = ?", [$key, $websiteId]);
        } catch (Exception $e) {
            // الجدول/العمود لسه مش موجود على السيرفر - مش خطأ حاسم للربط
        }
    }

    /** فصل الموقع وإيقاف كل الحقن */
    public function disconnectWebsite(int $userId, int $websiteId): void
    {
        $this->db->exec(
            "UPDATE websites
                SET is_connected = 0, auto_fix_enabled = 0, auto_pilot_mode = 'off'
              WHERE id = ? AND user_id = ?",
            [$websiteId, $userId]
        );
        $this->db->exec("UPDATE auto_seo_applied_fixes SET is_active = 0 WHERE website_id = ?", [$websiteId]);
    }

    /**
     * «كود Tourfecto» لموقع: سطر واحد بيشغّل كل خدمات المنصة اللي العميل مشغّلها على الموقع (إصلاحات السيو، شات الموقع).
     * بيتعمل من غير ما يشغّل السيو التلقائي. الموقع اللي بياخد كوده من هنا لأول مرة الشات بيبقى متشغّل فيه.
     */
    public function ensureEmbedToken(int $userId, int $websiteId): ?string
    {
        $row = $this->db->query("SELECT embed_token FROM websites WHERE id = ? AND user_id = ? LIMIT 1", [$websiteId, $userId])[0] ?? null;
        if (!$row) return null;
        if (preg_match('/^emb_[a-f0-9]{24}$/', (string) $row['embed_token'])) return (string) $row['embed_token'];
        $token = 'emb_' . bin2hex(random_bytes(12));
        $this->db->exec("UPDATE websites SET embed_token = ? WHERE id = ? AND user_id = ?", [$token, $websiteId, $userId]);
        $this->setSiteService($websiteId, 'chat', true);
        return $token;
    }

    /** الخدمات اللي الكود بيشغّلها غير السيو (السيو = is_connected). الكود القديم اللي اتعمل للسيو بس مايشغّلش شات من غير ما العميل يختار. */
    public function siteServices(int $websiteId): array
    {
        $row = $this->db->query('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1', ['site_code_' . $websiteId])[0] ?? null;
        $v = $row ? json_decode((string) $row['setting_value'], true) : null;
        return ['chat' => !empty($v['chat'])];
    }

    public function setSiteService(int $websiteId, string $service, bool $on): void
    {
        $services = $this->siteServices($websiteId);
        $services[$service] = $on;
        $key = 'site_code_' . $websiteId;
        $json = json_encode($services);
        if ($this->db->query('SELECT id FROM system_settings WHERE setting_key = ? LIMIT 1', [$key])) {
            $this->db->exec('UPDATE system_settings SET setting_value = ? WHERE setting_key = ?', [$json, $key]);
        } else {
            $this->db->exec('INSERT INTO system_settings (setting_key, setting_value, is_secret) VALUES (?, ?, 0)', [$key, $json]);
        }
    }

    /** سطر بيحمّل شات الموقع (لو مش متركّب لوحده في الصفحة) - فاضي لو الشات مش متشغّل للموقع ده */
    private function chatLoaderLine(int $websiteId): string
    {
        if (!$this->siteServices($websiteId)['chat'] || !class_exists('WebChatService') || (new WebChatService())->isSwitchedOff($websiteId)) {
            return '';
        }
        $src = json_encode(rtrim(defined('APP_URL') ? (string) APP_URL : '', '/') . '/widget/chat?site=' . $websiteId . '&t=' . WebChatService::token($websiteId), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        $mark = json_encode('/widget/chat?site=' . $websiteId, JSON_UNESCAPED_SLASHES);
        return "    if (!Array.prototype.some.call(document.scripts, function (s) { return (s.src || '').indexOf({$mark}) !== -1; })) {\n"
            . "      var c = document.createElement('script'); c.src = {$src}; c.async = true; document.head.appendChild(c);\n"
            . "    }";
    }

    /** كود التثبيت اللي العميل بيحطه قبل </head> */
    public function buildEmbedCode(string $embedToken): string
    {
        $base = rtrim((string) (getenv('APP_URL') ?: 'https://tourfecto.pro'), '/');

        return "<script>\n"
            . "(function(){var s=document.createElement('script');"
            . "s.src='{$base}/embed.js?token={$embedToken}';"
            . "s.async=true;s.dataset.tourfecto='auto-seo';"
            . "document.head.appendChild(s);})();\n"
            . "</script>";
    }

    /**
     * هل الإصلاح ده يستحق التطبيق التلقائي في الوضع الحالي؟
     * نفس منطق conservative/balanced/aggressive بتاع Auto-Pilot الأصلي.
     */
    public function shouldAutoApply(array $finding, string $mode): bool
    {
        if ($mode === 'off') {
            return false;
        }

        $fieldName = $finding['field_name'] ?? '';
        if (!in_array($fieldName, array_merge(self::INJECTABLE_FIELDS, self::SERVER_SIDE_FIELDS), true)) {
            return false;
        }

        $allowed = self::MODE_SEVERITIES[$mode] ?? [];
        if (!in_array($finding['severity'] ?? '', $allowed, true)) {
            return false;
        }

        // conservative بياخد الحاجات عالية الأثر بس عشان مايكسرش حاجة
        if ($mode === 'conservative' && (int) ($finding['impact_score'] ?? 0) < 7) {
            return false;
        }

        return !empty($finding['suggested_value']) || !empty($finding['fix_snippet']);
    }

    /**
     * تطبيق إصلاح واحد فعليًا: بيسجّل old/new في auto_seo_change_log وبيضيف
     * الكود في auto_seo_applied_fixes عشان embed.js يحقنه.
     *
     * @return array ['success'=>bool,'log_id'=>?int,'error'=>?string]
     */
    public function applyFix(int $userId, int $websiteId, array $finding, string $trigger, string $mode): array
    {
        $fieldName = (string) ($finding['field_name'] ?? $finding['check_key'] ?? '');
        if ($fieldName === '') {
            return ['success' => false, 'error' => 'الإصلاح ده مالوش حقل مستهدف'];
        }

        $newValue = (string) ($finding['suggested_value'] ?? $finding['fix_snippet'] ?? '');
        if ($newValue === '') {
            return ['success' => false, 'error' => 'مفيش قيمة مقترحة للتطبيق'];
        }

        $oldValue = $this->readCurrentValue($websiteId, $fieldName);

        try {
            $this->db->exec(
                "INSERT INTO auto_seo_applied_fixes
                    (website_id, user_id, finding_id, category, check_key, field_name, injected_code, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE injected_code = VALUES(injected_code), is_active = 1",
                [
                    $websiteId,
                    $userId,
                    $finding['id'] ?? null,
                    $finding['category'] ?? 'seo',
                    $finding['check_key'] ?? null,
                    $fieldName,
                    $finding['fix_snippet'] ?? $newValue,
                ]
            );

            $logId = (int) $this->db->query(
                "INSERT INTO auto_seo_change_log
                    (website_id, user_id, audit_id, finding_id, field_name, old_value, new_value, `trigger`, mode)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $websiteId,
                    $userId,
                    $finding['audit_id'] ?? null,
                    $finding['id'] ?? null,
                    $fieldName,
                    $oldValue,
                    $newValue,
                    $trigger,
                    $mode,
                ]
            );

            $this->db->exec(
                "UPDATE websites
                    SET total_fixes_applied = total_fixes_applied + 1, last_sync_at = NOW()
                  WHERE id = ?",
                [$websiteId]
            );

            return ['success' => true, 'log_id' => $logId];
        } catch (Exception $e) {
            Logger::error('AutoSeo Apply Fix Error', ['message' => $e->getMessage(), 'website_id' => $websiteId]);
            return ['success' => false, 'error' => 'فشل تطبيق الإصلاح'];
        }
    }

    /** التراجع عن تغيير - بيوقّف الحقن فورًا */
    public function rollback(int $userId, int $logId): array
    {
        $rows = $this->db->query(
            "SELECT * FROM auto_seo_change_log WHERE id = ? AND user_id = ? LIMIT 1",
            [$logId, $userId]
        );
        if (empty($rows)) {
            return ['success' => false, 'error' => 'السجل غير موجود'];
        }
        if (!empty($rows[0]['rolled_back_at'])) {
            return ['success' => false, 'error' => 'اتعمله Rollback بالفعل'];
        }

        $log = $rows[0];

        $this->db->exec(
            "UPDATE auto_seo_applied_fixes SET is_active = 0 WHERE website_id = ? AND field_name = ?",
            [$log['website_id'], $log['field_name']]
        );
        $this->db->exec("UPDATE auto_seo_change_log SET rolled_back_at = NOW() WHERE id = ?", [$logId]);
        $this->db->exec("UPDATE websites SET total_rollbacks = total_rollbacks + 1 WHERE id = ?", [$log['website_id']]);

        return ['success' => true];
    }

    /**
     * توليد محتوى embed.js الفعلي اللي المتصفح هيشغّله.
     * ده الجزء اللي بيخلّي التنفيذ "تلقائي" بجد.
     */
    public function buildEmbedJavaScript(string $embedToken): string
    {
        // كود واحد لكل خدمات الموقع: إصلاحات السيو بس لو السيو التلقائي شغال، والشات لو العميل مشغّله في «ربط موقعك»
        $sites = $this->db->query(
            "SELECT id, COALESCE(NULLIF(main_url,''),domain) AS domain, auto_pilot_mode, is_connected FROM websites WHERE embed_token = ? LIMIT 1",
            [$embedToken]
        );
        if (empty($sites)) {
            return "// Tourfecto: site not connected\n";
        }

        $site  = $sites[0];
        $seoOn = (int) $site['is_connected'] === 1;
        $fixes = $seoOn ? $this->db->query(
            "SELECT field_name, injected_code FROM auto_seo_applied_fixes
              WHERE website_id = ? AND is_active = 1",
            [$site['id']]
        ) : [];

        $lines = [];
        foreach ($fixes as $fix) {
            $lines[] = $this->buildInjectionLine($fix['field_name'], (string) $fix['injected_code']);
        }
        $chat = $this->chatLoaderLine((int) $site['id']);
        if ($chat !== '') {
            $lines[] = $chat;
        }
        $active=$seoOn?CompetitorSiteExecutionService::activePatch((int)$site['id']):null;
        if($active){
            $data=json_encode($active,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE);
            $lines[]=<<<JS
    (function(){
      var active={$data}, p=active.patch, home=new URL(active.url);
      if(location.hostname.toLowerCase()!==home.hostname.toLowerCase() || location.pathname.replace(/\/$/,'')!==home.pathname.replace(/\/$/,''))return;
      document.title=p.seo_title;
      var meta=document.querySelector('meta[name="description"]');if(!meta){meta=document.createElement('meta');meta.name='description';document.head.appendChild(meta);}meta.content=p.seo_description;
      var heads=Array.from(document.querySelectorAll('h1'));
      if(!heads.length){var first=document.createElement('h1');document.body.prepend(first);heads=[first];}heads[0].textContent=p.h1;
      heads.slice(1).forEach(function(node){var h=document.createElement('h2');Array.from(node.attributes).forEach(function(a){h.setAttribute(a.name,a.value);});while(node.firstChild)h.appendChild(node.firstChild);node.replaceWith(h);});
      ['tourfecto-competitor-content','tourfecto-competitor-schema'].forEach(function(id){var old=document.getElementById(id);if(old)old.remove();});
      var section=document.createElement('section');section.id='tourfecto-competitor-content';section.style.cssText='max-width:1100px;margin:2rem auto;padding:1.25rem;line-height:1.7';
      var heading=document.createElement('h2');heading.textContent=p.content_heading;section.appendChild(heading);
      var paragraph=document.createElement('p');paragraph.textContent=p.content_text;section.appendChild(paragraph);document.body.appendChild(section);
      var schema=document.createElement('script');schema.id='tourfecto-competitor-schema';schema.type='application/ld+json';schema.textContent=JSON.stringify(p.schema);document.head.appendChild(schema);
    })();
JS;
        }
        $body = implode("\n", array_filter($lines));

        $count  = count($fixes);
        $domain = $site['domain'];
        $mode   = $site['auto_pilot_mode'];
        $now    = date('c');

        return <<<JS
// Tourfecto AI - Auto SEO Engine
// site: {$domain} | mode: {$mode} | fixes: {$count} | generated: {$now}
(function () {
  function apply() {
{$body}
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', apply);
  } else { apply(); }
})();
JS;
    }

    /** بناء سطر الحقن حسب نوع الحقل */
    private function buildInjectionLine(string $field, string $code): string
    {
        switch ($field) {
            case 'seo_title':
                $v = json_encode(trim(strip_tags($code)), JSON_UNESCAPED_UNICODE);
                return "    if (!document.title || document.title.length < 30) { document.title = {$v}; }";

            case 'seo_description':
                $v = json_encode(mb_substr(trim(strip_tags($code)), 0, 160), JSON_UNESCAPED_UNICODE);
                return "    (function(){var m=document.querySelector('meta[name=\"description\"]');"
                     . "if(!m){m=document.createElement('meta');m.name='description';document.head.appendChild(m);}"
                     . "if(!m.content||m.content.length<80){m.content={$v};}})();";

            case 'canonical_url':
                return "    if(!document.querySelector('link[rel=\"canonical\"]')){var c=document.createElement('link');"
                     . "c.rel='canonical';c.href=location.origin+location.pathname;document.head.appendChild(c);}";

            case 'viewport':
                return "    if(!document.querySelector('meta[name=\"viewport\"]')){var v=document.createElement('meta');"
                     . "v.name='viewport';v.content='width=device-width, initial-scale=1';document.head.appendChild(v);}";

            case 'json_ld':
            case 'faq_schema':
            case 'speakable':
                if (preg_match('/<script[^>]*>([\s\S]*?)<\/script>/i', $code, $m)) {
                    $code = $m[1];
                }
                $v = json_encode(trim($code), JSON_UNESCAPED_UNICODE);
                return "    try{var s=document.createElement('script');s.type='application/ld+json';"
                     . "s.textContent={$v};document.head.appendChild(s);}catch(e){}";

            case 'og_tags':
                $v = json_encode($code, JSON_UNESCAPED_UNICODE);
                return "    if(!document.querySelector('meta[property=\"og:title\"]')){"
                     . "document.head.insertAdjacentHTML('beforeend', {$v});}";

            case 'image_alt':
                return "    document.querySelectorAll('img:not([alt])').forEach(function(i){"
                     . "i.alt=(document.title||'').slice(0,80);});";

            case 'image_lazy_load':
                return "    document.querySelectorAll('img:not([loading])').forEach(function(i){"
                     . "if(i!==document.querySelector('img')){i.loading='lazy';}});";

            case 'robots_txt':
            case 'llms_txt':
            case 'sitemap':
            case 'image_webp_convert':
            case 'hreflang_tags':
                return "    // {$field}: served server-side via Tourfecto proxy";

            default:
                return '';
        }
    }

    /** قراءة القيمة الحالية قبل الكتابة فوقها (أساس الـRollback) */
    private function readCurrentValue(int $websiteId, string $field): ?string
    {
        try {
            $rows = $this->db->query(
                "SELECT injected_code FROM auto_seo_applied_fixes
                  WHERE website_id = ? AND field_name = ? ORDER BY id DESC LIMIT 1",
                [$websiteId, $field]
            );
            return $rows[0]['injected_code'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }
}
