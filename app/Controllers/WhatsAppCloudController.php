<?php

/**
 * Tourfecto - ربط واتساب الرسمي (Cloud API) لكل شركة برقمها
 *
 * GET  /api/whatsapp-cloud/status?website_id=     حالة الربط + رابط الـ webhook + حالة القوالب
 * POST /api/whatsapp-cloud/connect                الربط (بيتحقق من البيانات مع Meta قبل الحفظ)
 * POST /api/whatsapp-cloud/disconnect/{website_id}
 * GET  /api/whatsapp-cloud/templates?website_id=  مزامنة حالة القوالب من Meta
 * POST /api/whatsapp-cloud/templates              إنشاء القوالب الناقصة في حساب الشركة
 * POST /api/whatsapp-cloud/test                   رسالة تجربة
 * GET/POST /api/whatsapp-cloud/webhook/{website_id}?secret=   (من Meta - من غير تسجيل دخول)
 */
require_once dirname(__DIR__).'/Services/Chat/WhatsAppWebhookInbox.php';

class WhatsAppCloudController extends Controller
{
    // ------------------------------------------------------------------ أسرار مشتقة لكل موقع

    private static function key(): string
    {
        if (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY) {
            return (string) ENCRYPTION_KEY;
        }
        if (defined('SALT') && SALT) {
            return (string) SALT;
        }
        // مفيش مفتاح = أسرار الويب-هوك كانت هتتشتق من نص ثابت ومعروف؛ نفشل بدل ما نشتقها.
        throw new RuntimeException('ENCRYPTION_KEY is not configured');
    }

    public static function webhookSecret(int $websiteId): string
    {
        return substr(hash_hmac('sha256', 'wa-cloud-webhook:' . $websiteId, self::key()), 0, 24);
    }

    public static function verifyToken(int $websiteId): string
    {
        return 'tf_' . substr(hash_hmac('sha256', 'wa-cloud-verify:' . $websiteId, self::key()), 0, 20);
    }

    /** Verify token للـ webhook العام (تطبيق المنصة - الربط بضغطة) */
    public static function platformVerifyToken(): string
    {
        return 'tfp_' . substr(hash_hmac('sha256', 'wa-platform-verify', self::key()), 0, 24);
    }

    public static function webhookUrl(int $websiteId): string
    {
        $base = rtrim(defined('APP_URL') ? (string) APP_URL : '', '/');
        return $base . '/api/whatsapp-cloud/webhook/' . $websiteId . '?secret=' . self::webhookSecret($websiteId);
    }

    /** الموقع لازم يكون بتاع المستخدم */
    private function ownedWebsite(int $websiteId): ?array
    {
        if ($websiteId <= 0) {
            return null;
        }
        $r = $this->db->query('SELECT id, user_id, company_name FROM websites WHERE id = ? LIMIT 1', [$websiteId]);
        if (!$r || (int) $r[0]['user_id'] !== (int) ($this->user['id'] ?? 0)) {
            return null;
        }
        return $r[0];
    }

    private function conn(int $websiteId): ?array
    {
        return WhatsAppCloudAPI::connectionRow($websiteId);
    }

    private function templatesView(?array $row): array
    {
        $st = $row ? WhatsAppTemplates::statuses($row) : [];
        $meta = $row ? (json_decode((string) ($row['meta_json'] ?? ''), true) ?: []) : [];
        $out = [];
        foreach (WhatsAppTemplates::DEFS as $name => $d) {
            $out[] = ['name' => $name, 'label' => $d['label'], 'category' => $d['category'], 'params' => $d['params'],
                'ar' => $st[$name]['ar'] ?? null, 'en' => $st[$name]['en'] ?? null,
                'rejected' => $meta['rejected'][$name] ?? null, 'text_ar' => $d['ar']['text'], 'text_en' => $d['en']['text']];
        }
        return $out;
    }

    // ------------------------------------------------------------------ لوحة العميل

    public function status(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        $wid = (int) $this->get('website_id');
        if (!$this->ownedWebsite($wid)) {
            return $this->error('الموقع غير موجود', 404);
        }
        $row = $this->conn($wid);
        $meta = $row ? (json_decode((string) ($row['meta_json'] ?? ''), true) ?: []) : [];
        $ultra = (bool) $this->db->query("SELECT id FROM platform_connections WHERE website_id = ? AND platform = 'ultramsg' AND status = 'connected' LIMIT 1", [$wid]);
        return $this->success([
            'connected' => (bool) $row,
            'phone_number_id' => $row['external_account_id'] ?? null,
            'waba_id' => $row['external_location_id'] ?? null,
            'display_phone' => $meta['display_phone'] ?? null,
            'verified_name' => $meta['verified_name'] ?? null,
            'quality' => $meta['quality'] ?? null,
            'has_app_secret' => $row ? !empty($row['refresh_token']) : false,
            'webhook_secure' => $row ? $this->connectionSecret($row) !== '' : false,
            'webhook_url' => self::webhookUrl($wid),
            'verify_token' => self::verifyToken($wid),
            'templates' => $this->templatesView($row),
            'templates_checked_at' => $meta['templates_checked_at'] ?? null,
            'ultramsg_connected' => $ultra,
            'last_webhook_at' => $meta['last_webhook_at'] ?? null,
            'embedded' => !empty($meta['embedded']),
            'coexistence' => !empty($meta['coexistence']),
            'last_echo_at' => $meta['last_echo_at'] ?? null,
            'sync_errors' => array_values((array) ($meta['sync_errors'] ?? [])),
            'register_error' => $meta['register_error'] ?? null,
        ]);
    }

    public function connect(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        $wid = (int) $this->get('website_id');
        if (!$this->ownedWebsite($wid)) {
            return $this->error('الموقع غير موجود', 404);
        }
        $pnid = preg_replace('/\D/', '', (string) $this->get('phone_number_id', ''));
        $waba = preg_replace('/\D/', '', (string) $this->get('waba_id', ''));
        $token = trim((string) $this->get('access_token', ''));
        $appSecret = trim((string) $this->get('app_secret', ''));
        if ($pnid === '' || $waba === '' || $token === '') {
            return $this->error('Phone Number ID و WhatsApp Business Account ID والتوكن مطلوبين', 422);
        }
        if (!preg_match('/^[a-f0-9]{32}$/i', $appSecret)) {
            return $this->error('App Secret مطلوب لتأمين استقبال الرسائل (32 حرف من 0-9 و a-f)', 422);
        }

        $api = new WhatsAppCloudAPI($pnid, $token, $waba);
        $info = $api->phoneInfo();
        if (!$info['ok']) {
            return $this->error('Meta رفضت البيانات: ' . $info['error'], 422);
        }
        $tpl = $api->listTemplates();
        if (!$tpl['ok']) {
            return $this->error('الرقم صح بس الـ WABA ID أو صلاحيات التوكن غلط (محتاج whatsapp_business_management): ' . $tpl['error'], 422);
        }
        $sub = $api->subscribeApp();

        $connId = $this->saveConnection($wid, $pnid, $waba, $token, $appSecret, $info, ['embedded' => false]);
        WhatsAppTemplates::sync($api, $connId);
        if (class_exists('ActivityLog')) {
            ActivityLog::record('chat', 'whatsapp_cloud.connected', ['user_id' => (int) $this->user['id'], 'subject_type' => 'websites', 'subject_id' => $wid, 'meta' => ['phone' => $info['display_phone']]]);
        }
        return $this->success([
            'display_phone' => $info['display_phone'], 'verified_name' => $info['verified_name'],
            'webhook_url' => self::webhookUrl($wid), 'verify_token' => self::verifyToken($wid),
            'subscribed' => $sub['ok'], 'subscribe_error' => $sub['ok'] ? null : $sub['error'],
        ], 'اتربط رقم ' . $info['display_phone'] . ' ✓');
    }

    private function connectionSecret(array $row): string
    {
        if (!empty($row['refresh_token'])) {
            try { return (new Encryption())->decrypt((string)$row['refresh_token']); }
            catch (Throwable $e) { return ''; }
        }
        $meta=json_decode((string)($row['meta_json']??''),true)?:[];
        // Only embedded connections belong to the platform Meta application.
        return !empty($meta['embedded']) ? MetaOAuthClient::setting('meta_app_secret','META_APP_SECRET') : '';
    }

    public function deliveryStatuses(array $params=[]): array
    {
        $wid=(int)$this->get('website_id');
        if (!$this->isAuthenticated() || !$this->ownedWebsite($wid)) return $this->error('غير مصرح',403);
        return $this->success(['statuses'=>$this->db->query('SELECT wamid,status,error_message,event_timestamp,updated_at FROM whatsapp_delivery_statuses WHERE website_id=? AND user_id=? ORDER BY updated_at DESC,id DESC LIMIT 20',[$wid,(int)$this->user['id']])]);
    }

    /** حفظ/تحديث الاتصال (مشترك بين الربط اليدوي والربط بضغطة) @return int connection id */
    private function saveConnection(int $wid, string $pnid, string $waba, string $token, string $appSecret, array $info, array $extraMeta = []): int
    {
        $enc = new Encryption();
        $meta = array_merge(['display_phone' => $info['display_phone'], 'verified_name' => $info['verified_name'], 'quality' => $info['quality'], 'connected_by' => (int) $this->user['id']], $extraMeta);
        $existing = $this->db->query("SELECT id, meta_json FROM platform_connections WHERE website_id = ? AND platform = 'whatsapp_cloud' LIMIT 1", [$wid]);
        $name = mb_substr(trim(($info['verified_name'] ?: 'WhatsApp') . ' · ' . $info['display_phone']), 0, 480);
        $secretEnc = $appSecret !== '' ? $enc->encrypt($appSecret) : null;
        if ($existing) {
            $old = json_decode((string) ($existing[0]['meta_json'] ?? ''), true) ?: [];
            $this->db->query(
                "UPDATE platform_connections SET user_id = ?, access_token = ?, refresh_token = ?, external_account_id = ?, external_location_id = ?, external_location_name = ?, status = 'connected', last_error = NULL, connected_at = NOW(), meta_json = ? WHERE id = ?",
                [(int) $this->user['id'], $enc->encrypt($token), $secretEnc, $pnid, $waba, $name, json_encode(array_merge($old, $meta), JSON_UNESCAPED_UNICODE), (int) $existing[0]['id']]
            );
            return (int) $existing[0]['id'];
        }
        return (int) $this->db->query(
            "INSERT INTO platform_connections (website_id, user_id, platform, access_token, refresh_token, external_account_id, external_location_id, external_location_name, status, connected_at, meta_json)
             VALUES (?, ?, 'whatsapp_cloud', ?, ?, ?, ?, ?, 'connected', NOW(), ?)",
            [$wid, (int) $this->user['id'], $enc->encrypt($token), $secretEnc, $pnid, $waba, $name, json_encode($meta, JSON_UNESCAPED_UNICODE)]
        );
    }

    /** GET /api/whatsapp-cloud/embedded-config - بيانات زرار "اربط بضغطة" (لو الأدمن ظبطها) */
    public function embeddedConfig(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        $appId = MetaOAuthClient::setting('meta_app_id', 'META_APP_ID');
        $cfg = preg_replace('/\D/', '', MetaOAuthClient::setting('meta_wa_config_id', 'META_WA_CONFIG_ID'));
        $ok = $appId !== '' && $cfg !== '' && MetaOAuthClient::setting('meta_app_secret', 'META_APP_SECRET') !== '';
        return $this->success(['enabled' => $ok, 'app_id' => $ok ? $appId : null, 'config_id' => $ok ? $cfg : null, 'version' => MetaOAuthClient::apiVersion()]);
    }

    /**
     * POST /api/whatsapp-cloud/embedded {website_id, code, waba_id, phone_number_id}
     * الشركة دخلت بفيسبوك واختارت/عملت رقمها في نافذة Meta - هنا بنكمّل: توكن ← تسجيل الرقم ← الاشتراك في الرسايل ← الحفظ
     */
    public function embedded(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        $wid = (int) $this->get('website_id');
        if (!$this->ownedWebsite($wid)) {
            return $this->error('الموقع غير موجود', 404);
        }
        $code = trim((string) $this->get('code', ''));
        $pnid = preg_replace('/\D/', '', (string) $this->get('phone_number_id', ''));
        $waba = preg_replace('/\D/', '', (string) $this->get('waba_id', ''));
        if ($code === '') {
            return $this->error('الربط اتلغى أو ماكملش - جرّب تاني', 422);
        }
        if ($pnid === '' || $waba === '') {
            return $this->error('Meta مارجعتش الرقم - لازم تكمّل لحد اختيار رقم الواتساب في النافذة', 422);
        }
        $ex = WhatsAppCloudAPI::exchangeCode($code);
        if (!$ex['ok']) {
            return $this->error('تعذر إكمال الربط مع Meta: ' . $ex['error'], 422);
        }
        $api = new WhatsAppCloudAPI($pnid, (string) $ex['token'], $waba);
        $info = $api->phoneInfo();
        if (!$info['ok']) {
            return $this->error('Meta رفضت الرقم: ' . $info['error'], 422);
        }
        // رقم شغال على تطبيق واتساب بزنس في الموبايل (Coexistence): مابنسجلوش على Cloud API
        // (التسجيل بيفصله من التطبيق)، وبنطلب جهات الاتصال والمحادثات القديمة من الموبايل
        $coexist = filter_var($this->get('coexistence', false), FILTER_VALIDATE_BOOLEAN);
        $pin = $coexist ? '' : (string) random_int(100000, 999999);
        $reg = $coexist ? ['ok' => true, 'error' => null] : $api->registerNumber($pin);
        $sub = $api->subscribeApp();
        $sync = [];
        if ($coexist) {
            foreach (['smb_app_state_sync', 'history'] as $type) {
                $sync[$type] = $api->syncPhoneApp($type);
            }
        }
        $connId = $this->saveConnection($wid, $pnid, $waba, (string) $ex['token'], '', $info, [
            'embedded' => true, 'coexistence' => $coexist, 'pin_enc' => $pin !== '' ? (new Encryption())->encrypt($pin) : null, 'registered' => $reg['ok'], 'register_error' => $reg['error'],
            'sync_errors' => array_filter(array_map(fn ($s) => $s['error'], $sync)),
        ]);
        WhatsAppTemplates::sync($api, $connId);
        if (class_exists('ActivityLog')) {
            ActivityLog::record('chat', 'whatsapp_cloud.connected', ['user_id' => (int) $this->user['id'], 'subject_type' => 'websites', 'subject_id' => $wid, 'meta' => ['phone' => $info['display_phone'], 'embedded' => true, 'coexistence' => $coexist]]);
        }
        $warn = [];
        if (!$reg['ok']) {
            $warn[] = 'تسجيل الرقم: ' . $reg['error'];
        }
        if (!$sub['ok']) {
            $warn[] = 'الاشتراك في الرسايل الواردة: ' . $sub['error'];
        }
        foreach ($sync as $type => $s) {
            if (!$s['ok']) {
                $warn[] = ($type === 'history' ? 'سحب المحادثات القديمة من الموبايل: ' : 'سحب جهات الاتصال من الموبايل: ') . $s['error'];
            }
        }
        return $this->success(['display_phone' => $info['display_phone'], 'coexistence' => $coexist, 'warnings' => $warn], 'اتربط رقم ' . $info['display_phone'] . ' ✓');
    }

    /** الاتصال + الـ API لموقع بتاع المستخدم (لملف الشركة) */
    private function apiFor(int $wid): array
    {
        if (!$this->ownedWebsite($wid)) {
            return [null, $this->error('الموقع غير موجود', 404)];
        }
        $api = WhatsAppCloudAPI::forWebsite($wid);
        return $api ? [$api, null] : [null, $this->error('اربط رقم واتساب الرسمي الأول', 422)];
    }

    /**
     * POST /api/whatsapp-cloud/diagnose {website_id} - ليه الرسايل الواردة مش بتظهر؟
     * بيسأل Meta لو تطبيق المنصة مشترك في الـ WABA (ولو لأ بيشتركه تاني)، وبيوري آخر مرة Meta بعتت على الـ webhook
     * وهل التوقيع كان غلط (App Secret) أو الرقم مش معروف.
     */
    public function diagnose(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        $wid = (int) $this->get('website_id');
        [$api, $err] = $this->apiFor($wid);
        if ($err) {
            return $err;
        }
        $row = $this->conn($wid);
        $meta = $row ? (json_decode((string) ($row['meta_json'] ?? ''), true) ?: []) : [];
        $stamp = function (string $k): ?string {
            try {
                return ($this->db->query('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1', [$k])[0]['setting_value'] ?? null) ?: null;
            } catch (Throwable $e) {
                return null;
            }
        };
        $appId = MetaOAuthClient::setting('meta_app_id', 'META_APP_ID');
        $sub = $api->subscribedApps();
        $subscribed = $sub['ok'] && ($appId === '' ? count($sub['ids']) > 0 : in_array($appId, $sub['ids'], true));
        $fixed = false;
        if ($sub['ok'] && !$subscribed) {
            $re = $api->subscribeApp();
            $fixed = $re['ok'];
        }
        $info = $api->phoneInfo();
        $checks = [
            'app_subscribed' => $subscribed || $fixed,
            'resubscribed_now' => $fixed,
            'subscribe_error' => $sub['ok'] ? null : $sub['error'],
            'number_ok' => $info['ok'],
            'number_error' => $info['ok'] ? null : $info['error'],
            'webhook_verified_at' => $stamp('wa_platform_webhook_verified_at'),
            'webhook_last_event_at' => $stamp('wa_platform_webhook_last_at'),
            'webhook_bad_signature_at' => $stamp('wa_platform_webhook_badsig_at'),
            'webhook_unknown_number_at' => $stamp('wa_platform_webhook_unknown_at'),
            'this_number_last_event_at' => $meta['last_webhook_at'] ?? null,
            'last_routing_summary' => ($rawSummary = $stamp('wa_platform_last_route_summary')) ? (json_decode($rawSummary, true) ?: null) : null,
        ];
        // Read-only diagnostics distinguish delivery from queued/failed processing.
        try {
            $counts = $this->db->query("SELECT state, COUNT(*) AS total FROM whatsapp_webhook_inbox WHERE website_id=? GROUP BY state", [$wid]);
            $checks['inbox_queue'] = [];
            foreach ($counts as $count) {
                $checks['inbox_queue'][(string)$count['state']] = (int)$count['total'];
            }
        } catch (Throwable $e) {
            $checks['inbox_queue'] = null; // Schema may not have been installed yet.
        }
        $hints = [];
        if (is_array($checks['inbox_queue']) && !empty($checks['inbox_queue']['failed'])) {
            $hints[] = 'WhatsApp events are reaching the database, but some background processing jobs failed. Check the WhatsApp queue worker and failed jobs.';
        } elseif (is_array($checks['inbox_queue']) && !empty($checks['inbox_queue']['queued'])) {
            $hints[] = 'WhatsApp events are queued. Check that the WhatsApp queue worker is running.';
        }
        if (!$checks['app_subscribed']) {
            $hints[] = 'تطبيق المنصة مش مشترك في رسايل الرقم ده ومقدرناش نشتركه: ' . ($checks['subscribe_error'] ?? '');
        } elseif ($fixed) {
            $hints[] = 'تطبيق المنصة ماكانش مشترك في رسايل الرقم، واشتركناه دلوقتي. ابعت رسالة تاني من موبايلك.';
        }
        if ($checks['webhook_bad_signature_at'] && (!$checks['webhook_last_event_at'] || $checks['webhook_bad_signature_at'] > $checks['webhook_last_event_at'])) {
            $hints[] = 'Meta بتبعت الرسايل بس التوقيع مش مطابق: الـ App Secret المحفوظ في إعدادات الأدمن غلط. انسخه تاني من App settings ← Basic واحفظه.';
        }
        if ($checks['webhook_unknown_number_at'] && !$checks['this_number_last_event_at']) {
            $hints[] = 'Meta بعتت رسايل لرقم مش متربط بأي موقع على المنصة.';
        }
        if (!$checks['webhook_last_event_at'] && !$checks['webhook_bad_signature_at'] && $checks['app_subscribed'] && !$fixed) {
            $hints[] = 'Meta لسه مابعتتش أي حدث للمنصة. اتأكد إنك مشترك في «messages» في التواصل على واتساب ← Webhooks، وإن التطبيق منشور (Live).';
        }
        if ($checks['this_number_last_event_at']) {
            $hints[] = 'الرسايل بتوصل للرقم ده ✓ (آخر حدث ' . $checks['this_number_last_event_at'] . ' UTC). لو مش ظاهرة في الوارد، حدّث صفحة الوارد.';
        }
        return $this->success(['checks' => $checks, 'hints' => $hints]);
    }

    /** GET /api/whatsapp-cloud/profile?website_id= - ملف الشركة اللي العملاء بيشوفوه على واتساب */
    public function profile(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        [$api, $err] = $this->apiFor((int) $this->get('website_id'));
        if ($err) {
            return $err;
        }
        $r = $api->businessProfile();
        return $r['ok'] ? $this->success(['profile' => $r['profile'], 'verticals' => WhatsAppCloudAPI::VERTICALS]) : $this->error('تعذر قراءة الملف من Meta: ' . $r['error'], 422);
    }

    /** POST /api/whatsapp-cloud/profile {website_id, about, description, address, email, websites[], vertical} */
    public function saveProfile(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        [$api, $err] = $this->apiFor((int) $this->get('website_id'));
        if ($err) {
            return $err;
        }
        $fields = [];
        foreach (['about', 'description', 'address', 'email', 'vertical'] as $k) {
            $v = $this->get($k, null);
            if ($v !== null) {
                $fields[$k] = (string) $v;
            }
        }
        $sites = $this->get('websites', null);
        if (is_array($sites)) {
            $fields['websites'] = array_map('strval', $sites);
        }
        try {
            $r = $api->updateBusinessProfile($fields);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        return $r['ok'] ? $this->success([], 'اتحفظ ملف الشركة على واتساب ✓') : $this->error('Meta رفضت التعديل: ' . $r['error'], 422);
    }

    /** POST /api/whatsapp-cloud/profile/photo (multipart: website_id, photo) - صورة البروفايل (JPG/PNG مربعة) */
    public function profilePhoto(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        [$api, $err] = $this->apiFor((int) ($_POST['website_id'] ?? 0));
        if ($err) {
            return $err;
        }
        $f = $_FILES['photo'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            return $this->error('اختار صورة', 422);
        }
        // getimagesize() reads the real image type without the fileinfo extension (often missing on aaPanel).
        $size = @getimagesize((string) $f['tmp_name']);
        $mime = $size ? ([IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png'][$size[2]] ?? '') : '';
        if ($mime === '' || (int) $f['size'] > 5 * 1024 * 1024) {
            return $this->error('الصورة لازم تبقى JPG أو PNG وأقل من 5 ميجا', 422);
        }
        if ($size[0] < 192 || $size[1] < 192) {
            return $this->error('الصورة لازم تبقى 192×192 بكسل على الأقل (يفضّل 640×640 مربعة)', 422);
        }
        try {
            $up = $api->uploadMedia((string) $f['tmp_name'], $mime, true);
            if (!$up['ok'] || empty($up['value'])) {
                return $this->error('تعذر رفع الصورة لـ Meta: ' . ($up['error'] ?? ''), 422);
            }
            $r = $api->updateBusinessProfile(['profile_picture_handle' => (string) $up['value']]);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Logger::error('WhatsApp profile photo failed', ['exception_class' => get_class($e), 'error' => mb_substr($e->getMessage(), 0, 300)]);
            return $this->error('تعذر رفع الصورة: ' . mb_substr($e->getMessage(), 0, 200), 500);
        }
        return $r['ok'] ? $this->success([], 'اتغيرت صورة البروفايل ✓') : $this->error('Meta رفضت الصورة: ' . $r['error'], 422);
    }

    // ------------------------------------------------------------------ حالة الحساب والتحكم

    /** GET /api/whatsapp-cloud/health?website_id= - حالة الرقم والحساب لحظيًا من Meta */
    public function health(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        $wid = (int) $this->get('website_id');
        [$api, $err] = $this->apiFor($wid);
        if ($err) {
            return $err;
        }
        $r = $api->accountHealth();
        if (!$r['ok']) {
            return $this->error('تعذر قراءة حالة الحساب من Meta: ' . $r['error'], 422);
        }
        // Keep the stored quality fresh for the connection card.
        $row = $this->conn($wid);
        if ($row && !empty($r['phone']['quality_rating'])) {
            try { WhatsAppTemplates::saveMeta((int) $row['id'], ['quality' => (string) $r['phone']['quality_rating'], 'health_checked_at' => gmdate('Y-m-d H:i:s')]); } catch (Throwable $e) {}
        }
        return $this->success(['phone' => $r['phone'], 'account' => $r['account']]);
    }

    /** POST /api/whatsapp-cloud/pin {website_id, pin} - تغيير رمز التحقق بخطوتين للرقم */
    public function pin(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        [$api, $err] = $this->apiFor((int) $this->get('website_id'));
        if ($err) {
            return $err;
        }
        $pin = (string) $this->get('pin', '');
        if (!preg_match('/^[0-9]{6}$/D', $pin)) {
            return $this->error('الرمز لازم يبقى 6 أرقام', 422);
        }
        $r = $api->setPin($pin);
        return $r['ok'] ? $this->success([], 'اتغير رمز التحقق بخطوتين ✓ احتفظ بيه في مكان آمن') : $this->error('Meta رفضت الرمز: ' . $r['error'], 422);
    }

    /** GET /api/whatsapp-cloud/automation?website_id= - اقتراحات الأسئلة والأوامر اللي بتظهر للعميل */
    public function automation(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        [$api, $err] = $this->apiFor((int) $this->get('website_id'));
        if ($err) {
            return $err;
        }
        $r = $api->conversationalAutomation();
        return $r['ok'] ? $this->success(['prompts' => $r['prompts'], 'commands' => $r['commands']]) : $this->error('تعذر القراءة من Meta: ' . $r['error'], 422);
    }

    /** POST /api/whatsapp-cloud/automation {website_id, prompts[], commands[{command_name, command_description}]} */
    public function saveAutomation(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        [$api, $err] = $this->apiFor((int) $this->get('website_id'));
        if ($err) {
            return $err;
        }
        try {
            $r = $api->setConversationalAutomation((array) $this->get('prompts', []), (array) $this->get('commands', []));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        return $r['ok'] ? $this->success([], 'اتحفظ ✓ ممكن ياخد دقايق لحد ما يظهر عند العملاء') : $this->error('Meta رفضت التعديل: ' . $r['error'], 422);
    }

    /** GET /api/whatsapp-cloud/blocked?website_id= - العملاء المحظورين */
    public function blocked(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        [$api, $err] = $this->apiFor((int) $this->get('website_id'));
        if ($err) {
            return $err;
        }
        $r = $api->blockedUsers();
        return $r['ok'] ? $this->success(['users' => $r['users']]) : $this->error('تعذر القراءة من Meta: ' . $r['error'], 422);
    }

    /** POST /api/whatsapp-cloud/block {website_id, phone, block} - حظر عميل أو فك الحظر */
    public function block(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        $wid = (int) $this->get('website_id');
        [$api, $err] = $this->apiFor($wid);
        if ($err) {
            return $err;
        }
        $block = filter_var($this->get('block', true), FILTER_VALIDATE_BOOLEAN);
        try {
            $r = $api->blockUser((string) $this->get('phone', ''), $block);
        } catch (InvalidArgumentException $e) {
            return $this->error('رقم غير صحيح - اكتبه دولي بكود الدولة', 422);
        }
        if (!$r['ok']) {
            return $this->error(($block ? 'Meta رفضت الحظر: ' : 'Meta رفضت فك الحظر: ') . $r['error'] . ($block ? ' (Meta بتسمح بحظر اللي كلمك آخر 24 ساعة بس)' : ''), 422);
        }
        return $this->success([], $block ? 'اتحظر العميل ✓' : 'اتفك الحظر ✓');
    }

    /** GET /api/whatsapp-cloud/catalog?website_id=&catalog_id= - إعدادات الكتالوج والكتالوجات المربوطة والمنتجات */
    public function catalog(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        $wid = (int) $this->get('website_id');
        [$api, $err] = $this->apiFor($wid);
        if ($err) {
            return $err;
        }
        $settings = $api->commerceSettings();
        $cats = $api->linkedCatalogs();
        $row = $this->conn($wid);
        $meta = $row ? (json_decode((string) ($row['meta_json'] ?? ''), true) ?: []) : [];
        $ids = array_map(fn($c) => (string) ($c['id'] ?? ''), $cats['items']);
        $chosen = (string) $this->get('catalog_id', $meta['catalog_id'] ?? '');
        if (!in_array($chosen, $ids, true)) $chosen = $ids[0] ?? '';
        if ($row && $chosen !== ($meta['catalog_id'] ?? '')) {
            try { WhatsAppTemplates::saveMeta((int) $row['id'], ['catalog_id' => $chosen]); } catch (Throwable $e) {}
        }
        $products = $chosen !== '' ? $api->catalogProducts($chosen) : ['ok' => true, 'items' => [], 'error' => null];
        return $this->success([
            'settings_ok' => $settings['ok'],
            'catalog_visible' => $settings['catalog_visible'],
            'cart_enabled' => $settings['cart_enabled'],
            'catalogs' => $cats['items'],
            // 200/10/100 errors here usually mean the connection was made without catalog access.
            'catalogs_error' => $cats['ok'] ? null : $cats['error'],
            'catalog_id' => $chosen,
            'products' => array_map([self::class, 'productView'], $products['items']),
            'products_error' => $products['ok'] ? null : $products['error'],
        ]);
    }

    /** POST /api/whatsapp-cloud/catalog {website_id, catalog_visible, cart_enabled} */
    public function saveCatalog(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        [$api, $err] = $this->apiFor((int) $this->get('website_id'));
        if ($err) {
            return $err;
        }
        $r = $api->setCommerceSettings(
            filter_var($this->get('catalog_visible', false), FILTER_VALIDATE_BOOLEAN),
            filter_var($this->get('cart_enabled', false), FILTER_VALIDATE_BOOLEAN)
        );
        return $r['ok'] ? $this->success([], 'اتحفظت إعدادات الكتالوج ✓') : $this->error('Meta رفضت التعديل: ' . $r['error'], 422);
    }

    public static function productView(array $p): array
    {
        $img = (string) ($p['image_url'] ?? '');
        return [
            'retailer_id' => (string) ($p['retailer_id'] ?? ''),
            'name' => (string) ($p['name'] ?? ''),
            'price' => (string) ($p['price'] ?? ''),
            'availability' => (string) ($p['availability'] ?? ''),
            'image_url' => preg_match('#^https://#i', $img) ? $img : '',
        ];
    }

    public function disconnect(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        $wid = (int) ($params['website_id'] ?? 0);
        if (!$this->ownedWebsite($wid)) {
            return $this->error('الموقع غير موجود', 404);
        }
        $this->db->query("UPDATE platform_connections SET status = 'disconnected', access_token = NULL, refresh_token = NULL WHERE website_id = ? AND platform = 'whatsapp_cloud'", [$wid]);
        return $this->success([], 'اتفصل الربط');
    }

    public function templates(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        $wid = (int) $this->get('website_id');
        if (!$this->ownedWebsite($wid) || !($row = $this->conn($wid)) || !($api = WhatsAppCloudAPI::forWebsite($wid))) {
            return $this->error('واتساب الرسمي مش مربوط للموقع ده', 404);
        }
        $r = WhatsAppTemplates::sync($api, (int) $row['id']);
        if (!$r['ok']) {
            return $this->error('تعذر جلب القوالب من Meta: ' . $r['error'], 502);
        }
        return $this->success(['templates' => $this->templatesView($this->conn($wid))]);
    }

    public function createTemplates(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        $wid = (int) $this->get('website_id');
        if (!$this->ownedWebsite($wid) || !($row = $this->conn($wid)) || !($api = WhatsAppCloudAPI::forWebsite($wid))) {
            return $this->error('واتساب الرسمي مش مربوط للموقع ده', 404);
        }
        $r = WhatsAppTemplates::createMissing($api, (int) $row['id']);
        $msg = $r['created'] ? 'اتبعت ' . count($r['created']) . ' قالب لمراجعة Meta - الموافقة بتاخد من دقايق لـ 24 ساعة' : 'كل القوالب موجودة بالفعل';
        if ($r['errors']) {
            return $this->error(($r['created'] ? $msg . ' - بس فيه أخطاء: ' : '') . implode(' | ', $r['errors']), 422, ['templates' => $this->templatesView($this->conn($wid))]);
        }
        return $this->success(['templates' => $this->templatesView($this->conn($wid)), 'created' => $r['created']], $msg);
    }

    /** رسالة تجربة: قالب التذكير لو متوافق عليه، وإلا hello_world (موجود في كل حساب جديد) */
    public function test(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        $wid = (int) $this->get('website_id');
        $site = $this->ownedWebsite($wid);
        try{$phone = WhatsAppCloudAPI::normalizePhone((string) $this->get('phone', ''));}catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}
        if (!$site || !($row = $this->conn($wid)) || !($api = WhatsAppCloudAPI::forWebsite($wid))) {
            return $this->error('واتساب الرسمي مش مربوط للموقع ده', 404);
        }
        if (strlen($phone) < 8) {
            return $this->error('اكتب رقم واتساب صحيح بالكود الدولي (مثال 2010xxxxxxxx)', 422);
        }
        $wantLanguage=(string)$this->get('language',ContentLanguageService::forWebsite($wid,(int)$this->user['id']));
        $pick = WhatsAppTemplates::pickLanguage($row, 'tf_trip_reminder', $wantLanguage);
        $st = WhatsAppTemplates::statuses($row)['tf_trip_reminder'] ?? [];
        if ($pick['lang'] && in_array('APPROVED', $st, true)) {
            $co = trim((string) ($site['company_name'] ?? '')) ?: 'Tourfecto';
            $r = $api->sendTemplate($phone, 'tf_trip_reminder', $pick['lang'], $pick['lang'] === 'en' ? ['Test', $co, 'Test trip', 'tomorrow at 09:00'] : ['تجربة', $co, 'رحلة تجريبية', 'بكرة الساعة 09:00']);
            $used = 'tf_trip_reminder';
        } else {
            $r = $api->sendTemplate($phone, 'hello_world', 'en_US');
            $used = 'hello_world';
        }
        if (!$r['ok']) {
            return $this->error('ماوصلتش: ' . $r['error'], 422);
        }
        return $this->success(['template' => $used, 'id' => $r['id']], 'اتبعتت رسالة تجربة (' . $used . ') - شوف الواتساب');
    }

    // ------------------------------------------------------------------ Webhook (من Meta)

    public function webhookVerify(array $params = []): array
    {
        $wid = (int) ($params['website_id'] ?? 0);
        $secret = (string) ($_GET['secret'] ?? '');
        $mode = (string) ($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
        $token = (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
        $challenge = (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');
        if ($wid > 0 && hash_equals(self::webhookSecret($wid), $secret) && $mode === 'subscribe' && hash_equals(self::verifyToken($wid), $token)) {
            http_response_code(200);
            header('Content-Type: text/plain');
            echo preg_replace('/[^A-Za-z0-9_\-]/', '', $challenge);
            exit;
        }
        http_response_code(403);
        header('Content-Type: text/plain');
        echo 'Forbidden';
        exit;
    }

    public function webhook(array $params = []): array
    {
        $wid = (int) ($params['website_id'] ?? 0);
        if ($wid <= 0 || !hash_equals(self::webhookSecret($wid), (string) ($_GET['secret'] ?? ''))) {
            return $this->error('Unauthorized webhook', 401);
        }
        $row = $this->db->query("SELECT * FROM platform_connections WHERE website_id = ? AND platform = 'whatsapp_cloud' AND status = 'connected' ORDER BY id DESC LIMIT 1", [$wid])[0] ?? null;
        if (!$row) {
            $any = $this->db->query("SELECT status FROM platform_connections WHERE website_id = ? AND platform = 'whatsapp_cloud' ORDER BY id DESC LIMIT 1", [$wid])[0] ?? null;
            if (self::shouldAskMetaToRetry($any['status'] ?? null)) {
                // The connection exists but is broken for now (expired token, error): ask Meta to redeliver
                // instead of acknowledging a message that nobody will store.
                if (!headers_sent()) {
                    header('Retry-After: 300');
                }
                return $this->error('WhatsApp connection is temporarily unavailable', 503);
            }
            return $this->success([], 'not connected');
        }
        $raw = (string) file_get_contents('php://input');
        $appSecret = $this->connectionSecret($row);
        if (!WhatsAppWebhookInbox::verifiedSignature($raw, (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256']??''), $appSecret)) {
            return $this->error('Invalid signature or missing App Secret', 401);
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) return $this->error('Invalid JSON', 400);
        return $this->respondThenProcess($this->success(self::processPayload($wid, (int) $row['user_id'], (string) $row['external_account_id'], $payload, (int) $row['id'])));
    }

    /**
     * No connection row at all, or one the owner disconnected on purpose, is final (answer 200 so Meta never keeps
     * retrying and never disables the subscription). Any other non-connected status is treated as temporary.
     */
    private static function shouldAskMetaToRetry(?string $status): bool
    {
        return $status !== null && $status !== '' && !in_array($status, ['connected', 'disconnected'], true);
    }

    /**
     * Acknowledge Meta first, then process the queued messages in the same PHP process so
     * they reach the inbox (and the AI reply runs) in seconds instead of on the next cron minute.
     * Without FPM/LiteSpeed the response is returned normally and the cron processes the queue.
     */
    private function respondThenProcess(array $response): array
    {
        $finish = function_exists('fastcgi_finish_request') ? 'fastcgi_finish_request'
            : (function_exists('litespeed_finish_request') ? 'litespeed_finish_request' : null);
        if ($finish === null || empty($response['success'])) {
            return $response;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        ignore_user_abort(true);
        @set_time_limit(120);
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        $finish();
        WhatsAppWebhookInbox::processQueuedNow();
        exit;
    }

    /** GET /api/whatsapp-cloud/webhook - تحقق Meta من الـ webhook العام (تطبيق المنصة) */
    public function platformWebhookVerify(array $params = []): array
    {
        $mode = (string) ($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
        $token = (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
        $challenge = (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');
        header('Content-Type: text/plain');
        if ($mode === 'subscribe' && hash_equals(self::platformVerifyToken(), $token)) {
            self::markPlatform('wa_platform_webhook_verified_at');
            http_response_code(200);
            echo preg_replace('/[^A-Za-z0-9_\-]/', '', $challenge);
            exit;
        }
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    /**
     * POST /api/whatsapp-cloud/webhook - رسايل كل الشركات اللي ربطت بضغطة (بتيجي على تطبيق المنصة).
     * التوقيع بـ App Secret بتاع المنصة إجباري، والتوجيه للشركة الصح بـ phone_number_id.
     */
    public function platformWebhook(array $params = []): array
    {
        $raw = (string) file_get_contents('php://input');
        $secret = MetaOAuthClient::setting('meta_app_secret', 'META_APP_SECRET');
        $sig = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
        if (!WhatsAppWebhookInbox::verifiedSignature($raw, $sig, $secret)) {
            // Meta بعتت بس التوقيع مش مطابق (غالبًا App Secret في الأدمن غلط) - بنسجلها عشان الفحص يبيّن السبب
            if ($sig !== '') {
                self::markPlatform('wa_platform_webhook_badsig_at');
            }
            return $this->error('Invalid signature', 401);
        }
        $payload = json_decode($raw, true);
        if(!is_array($payload))return $this->error('Invalid JSON',400);
        self::markPlatform('wa_platform_webhook_last_at');
        // Only structural counts are logged: never log customer messages, numbers or tokens.
        try {
            $result = self::routePlatformPayload($payload);
            // Persist privacy-safe routing outcome for authenticated diagnostics.
            // An HTTP 200 only means the request was acknowledged, not that a chat was stored.
            self::savePlatformRoutingSummary($result);
            // Dedicated, minimal diagnostic. Logger::warning is suppressed when APP_DEBUG=false.
            // Log counts only; never persist the incoming payload, token, or phone numbers.
            try {
                $logDir = TOURFECTO_STORAGE . '/logs';
                if (!is_dir($logDir)) { @mkdir($logDir, 0750, true); }
                $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] WhatsApp webhook routing result ' . json_encode([
                    'messages_queued' => (int) $result['messages'],
                    'statuses_queued' => (int) $result['statuses'],
                    'unknown_connections' => (int) $result['unknown'],
                    'skipped' => (int) $result['skipped'],
                ], JSON_UNESCAPED_SLASHES) . "\n";
                @file_put_contents($logDir . '/whatsapp-webhook-diagnostics.log', $line, FILE_APPEND | LOCK_EX);
            } catch (Throwable $ignored) {
                // Diagnostics must never prevent Meta from receiving a successful response.
            }
            return $this->respondThenProcess($this->success($result));
        } catch (Throwable $e) {
            Logger::error('WhatsApp webhook routing failed', ['exception_class' => get_class($e)]);
            throw $e;
        }
    }

    /** آخر مرة Meta اتحققت من الـ webhook العام أو بعتت عليه (صفحة جاهزية مراجعة Meta في الأدمن بتقراها) */
    private static function markPlatform(string $key): void
    {
        try {
            Database::getInstance()->exec(
                'INSERT INTO system_settings (setting_key, setting_value, is_secret) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                [$key, gmdate('Y-m-d H:i:s')]
            );
        } catch (Throwable $e) {
            error_log('[WhatsAppCloudController] markPlatform failed: ' . $e->getMessage());
        }
    }

    /** Resolve a real phone number only; do not infer sender from username/BSUID. */
    private static function resolveIncomingSender(array $message, array $value): string
    {
        $from = trim((string) ($message['from'] ?? ''));
        if (preg_match('/^\+?[1-9][0-9]{7,14}$/D', $from) === 1) {
            return ltrim($from, '+');
        }
        // Fallback is safe only if the entire event has exactly one contact and
        // its wa_id is a valid phone number. No positional guessing for groups.
        $contacts = $value['contacts'] ?? null;
        if (!is_array($contacts) || count($contacts) !== 1) {
            return '';
        }
        $contact = reset($contacts);
        $candidate = is_array($contact) ? trim((string) ($contact['wa_id'] ?? '')) : '';
        return preg_match('/^\+?[1-9][0-9]{7,14}$/D', $candidate) === 1 ? ltrim($candidate, '+') : '';
    }

    /** Opaque WhatsApp business-scoped ID. Never coerce it into a phone number. */
    private static function resolveIncomingBsuid(array $message, array $value): string
    {
        $candidates = [(string)($message['from'] ?? ''), (string)($message['from_user_id'] ?? '')];
        $contacts = $value['contacts'] ?? [];
        if (is_array($contacts) && count($contacts) === 1 && is_array(reset($contacts))) {
            $contact = reset($contacts);
            $candidates[] = (string)($contact['user_id'] ?? '');
            $candidates[] = (string)($contact['wa_id'] ?? '');
        }
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            // Country prefix + dot + opaque identifier; reject phone-number-only strings.
            if (preg_match('/^[A-Z]{2}\.[A-Za-z0-9._-]{8,128}$/D', $candidate) === 1) {
                return $candidate;
            }
        }
        return '';
    }

    /** Counts only; never stores message bodies, access tokens or phone numbers. */
    private static function savePlatformRoutingSummary(array $result): void
    {
        $summary = [
            'at_utc' => gmdate('Y-m-d H:i:s'),
            'matched' => (int) ($result['matched'] ?? 0),
            'messages_queued' => (int) ($result['messages'] ?? 0),
            'statuses_queued' => (int) ($result['statuses'] ?? 0),
            'unknown' => (int) ($result['unknown'] ?? 0),
            'skipped' => (int) ($result['skipped'] ?? 0),
            // Aggregated event shape only. No message bodies, phone numbers, IDs or tokens.
            'event_shape' => (array) ($result['event_shape'] ?? []),
        ];
        try {
            Database::getInstance()->exec(
                'INSERT INTO system_settings (setting_key, setting_value, is_secret) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                ['wa_platform_last_route_summary', json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]
            );
        } catch (Throwable $e) {
            // An optional diagnostic must never block Meta webhook acknowledgement.
        }
    }

    /** يوزّع دفعة webhook عامة على الشركات حسب phone_number_id */
    public static function routePlatformPayload(array $payload, ?object $chatManager = null): array
    {
        $db = Database::getInstance();
        $total = ['messages' => 0, 'statuses' => 0, 'skipped' => 0, 'unknown' => 0, 'matched' => 0,
            'event_shape' => [
                'changes_seen' => 0, 'messages_field_changes' => 0, 'other_field_changes' => 0,
                'raw_messages_seen' => 0, 'raw_statuses_seen' => 0,
                'text_messages_seen' => 0, 'nontext_messages_seen' => 0,
                'messages_missing_id' => 0, 'messages_missing_sender' => 0,
                'sender_recovered_from_contact' => 0, 'sender_unresolved' => 0, 'bsuid_sender_seen' => 0,
                'text_messages_empty_body' => 0,
            ]];
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $ch) {
                // Count the shape of signed Meta events before routing; never store the payload.
                $shape =& $total['event_shape'];
                $shape['changes_seen']++;
                $value = is_array($ch['value'] ?? null) ? $ch['value'] : [];
                if (($ch['field'] ?? '') === 'messages') {
                    $shape['messages_field_changes']++;
                } else {
                    $shape['other_field_changes']++;
                }
                $messages = is_array($value['messages'] ?? null) ? $value['messages'] : [];
                $statuses = is_array($value['statuses'] ?? null) ? $value['statuses'] : [];
                $shape['raw_messages_seen'] += count($messages);
                $shape['raw_statuses_seen'] += count($statuses);
                foreach ($messages as $incoming) {
                    if (!is_array($incoming)) { continue; }
                    if (($incoming['type'] ?? '') === 'text') {
                        $shape['text_messages_seen']++;
                        if (trim((string) ($incoming['text']['body'] ?? '')) === '') {
                            $shape['text_messages_empty_body']++;
                        }
                    } else {
                        $shape['nontext_messages_seen']++;
                    }
                    if (empty($incoming['id'])) { $shape['messages_missing_id']++; }
                    if (empty($incoming['from'])) {
                        $shape['messages_missing_sender']++;
                        if (self::resolveIncomingSender($incoming, $value) !== '') {
                            $shape['sender_recovered_from_contact']++;
                        } elseif (self::resolveIncomingBsuid($incoming, $value) !== '') {
                            $shape['bsuid_sender_seen']++;
                        } else {
                            $shape['sender_unresolved']++;
                        }
                    }
                }
                unset($shape);
                $pn = (string) ($ch['value']['metadata']['phone_number_id'] ?? '');
                $rows = $pn !== '' ? $db->query("SELECT c.id,c.website_id,c.user_id FROM platform_connections c JOIN websites w ON w.id=c.website_id AND w.user_id=c.user_id WHERE c.platform='whatsapp_cloud' AND c.status='connected' AND c.external_account_id=? ORDER BY c.id DESC LIMIT 2",[$pn]) : [];
                $row=count($rows)===1?$rows[0]:null;
                if (!$row) {
                    self::markPlatform('wa_platform_webhook_unknown_at');
                    // Do not log raw IDs or payloads. Count failures to match a connected site.
                    $total['unknown']++;
                    continue;
                }
                $total['matched']++;
                $o = self::processPayload((int) $row['website_id'], (int) $row['user_id'], $pn, ['entry' => [['changes' => [$ch]]]], (int) $row['id'], $chatManager);
                foreach (['messages', 'statuses', 'skipped'] as $k) {
                    $total[$k] += $o[$k];
                }
            }
        }
        return $total;
    }

    /**
     * معالجة دفعة الـ webhook: رسايل واردة ← الشات (ChatManager) · حالات الإرسال ← whatsapp_delivery_statuses (via durable queue)
     * @return array{messages:int, statuses:int, skipped:int}
     */
    public static function processPayload(int $wid, int $userId, string $phoneNumberId, array $payload, int $connId = 0, ?object $chatManager = null): array
    {
        $db = Database::getInstance();
        $out = ['messages' => 0, 'statuses' => 0, 'skipped' => 0];
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $ch) {
                $v = $ch['value'] ?? [];
                // رسالة لرقم تاني على نفس الحساب - مش بتاعة الموقع ده
                if (!empty($v['metadata']['phone_number_id']) && $phoneNumberId !== '' && (string) $v['metadata']['phone_number_id'] !== $phoneNumberId) {
                    $out['skipped']++;
                    continue;
                }
                // رسايل الشركة من تطبيق واتساب بزنس على الموبايل (رقم Coexistence) ← الوارد كرسايل طالعة
                if (($ch['field'] ?? '') === 'smb_message_echoes') {
                    $stored = 0;
                    foreach ((array) ($v['message_echoes'] ?? []) as $echo) {
                        try {
                            if (WhatsAppPhoneEcho::store($wid, $userId, (array) $echo)) {
                                $stored++;
                            }
                        } catch (Throwable $e) {
                            Logger::warning('WhatsApp phone echo not stored', ['website_id' => $wid, 'error' => $e->getMessage()]);
                        }
                    }
                    $out['messages'] += $stored;
                    if ($stored && $connId > 0) {
                        try {
                            WhatsAppTemplates::saveMeta($connId, ['last_echo_at' => gmdate('Y-m-d H:i:s')]);
                        } catch (Throwable $e) {
                        }
                    }
                    continue;
                }
                // جهات الاتصال والمحادثات القديمة من الموبايل: مابنستوردهاش دلوقتي، بس مانعتبرهاش رسايل عملاء جديدة
                if (in_array((string) ($ch['field'] ?? ''), ['history', 'smb_app_state_sync'], true)) {
                    $out['skipped']++;
                    continue;
                }
                $names = [];
                foreach ((array) ($v['contacts'] ?? []) as $c) {
                    $names[(string) ($c['wa_id'] ?? '')] = (string) ($c['profile']['name'] ?? '');
                }
                foreach ((array) ($v['messages'] ?? []) as $m) {
                    $id = (string) ($m['id'] ?? '');
                    if ($id === '') { $out['skipped']++; continue; }
                    $type = (string) ($m['type'] ?? 'text');
                    $flow=null;if($type==='interactive'&&isset($m['interactive']['nfm_reply'])){$raw=$m['interactive']['nfm_reply']['response_json']??'';if(!is_string($raw)||strlen($raw)>20000||!is_object(json_decode($raw))){$out['skipped']++;continue;}$flow=json_decode($raw,true);}

                    $text = match ($type) {
                        'text' => (string) ($m['text']['body'] ?? ''),
                        'button' => (string) ($m['button']['text'] ?? ''),
                        'interactive' => $flow!==null?'[WhatsApp Flow response] '.json_encode($flow,JSON_UNESCAPED_UNICODE):(string) ($m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? ''),
                        'image' => '[Image] ' . (string) ($m['image']['caption'] ?? ''),
                        'video' => '[Video] ' . (string) ($m['video']['caption'] ?? ''),
                        'audio' => '[Voice message]',
                        'document' => '[Document] ' . (string) ($m['document']['filename'] ?? ''),
                        'location' => '[Location] ' . ($m['location']['latitude'] ?? '') . ',' . ($m['location']['longitude'] ?? ''),
                        'sticker' => '[Sticker]',
                        'order' => '[Order] ' . implode(', ', array_map(fn($i) => (int) ($i['quantity'] ?? 1) . ' × ' . (string) ($i['product_retailer_id'] ?? '?') . (isset($i['item_price']) ? ' (' . $i['item_price'] . ' ' . ($i['currency'] ?? '') . ')' : ''), array_slice((array) ($m['order']['product_items'] ?? []), 0, 30))) . (!empty($m['order']['text']) ? ' — ' . (string) $m['order']['text'] : ''),
                        'reaction' => '',
                        default => '[' . $type . ']',
                    };
                    $text = trim($text);
                    // Keep the Meta media ID so the inbox can show the image/voice/file itself.
                    $media = null;
                    if (in_array($type, ['image', 'video', 'audio', 'document', 'sticker'], true) && preg_match('/^[0-9]{1,64}$/D', (string) ($m[$type]['id'] ?? ''))) {
                        $media = [
                            'type' => $type,
                            'id' => (string) $m[$type]['id'],
                            'mime' => mb_substr((string) ($m[$type]['mime_type'] ?? ''), 0, 100),
                            'filename' => mb_substr((string) ($m[$type]['filename'] ?? ''), 0, 240),
                            'voice' => !empty($m[$type]['voice']),
                        ];
                    }
                    // Some webhook variants omit messages[].from. Recover only when
                    // exactly one contact provides an unambiguous WhatsApp phone identifier.
                    // Never treat a username / BSUID as a phone number or guess the sender.
                    $from = self::resolveIncomingSender((array) $m, (array) $v);
                    $bsuid = $from === '' ? self::resolveIncomingBsuid((array)$m, (array)$v) : '';
                    if ($text === '' || ($from === '' && $bsuid === '')) {
                        $out['skipped']++;
                        continue;
                    }
                    $message = [
                        'user_id'=>$userId, 'website_id'=>$wid, 'platform'=>'whatsapp',
                        'phone_number'=>$from, 'wa_bsuid'=>$bsuid,
                        'sender_name'=>$names[$from]??null,
                        'message'=>$text, 'platform_message_id'=>$id, 'timestamp'=>$m['timestamp']??null,
                    ];
                    if ($media !== null) $message['media'] = $media;
                    if($flow!==null){$message['flow_response']=$flow;$message['flow_reply_to']=(string)($m['context']['id']??'');}
                    if (WhatsAppWebhookInbox::enqueue($wid,$userId,'message',$id,$message)) {
                        $out['messages']++;
                    } else { $out['skipped']++; }

                }
                foreach ((array) ($v['statuses'] ?? []) as $st) {
                    $eventKey=(string)($st['id']??'').'|'.(string)($st['status']??'').'|'.(string)($st['timestamp']??'');
                    if (!empty($st['id']) && WhatsAppWebhookInbox::enqueue($wid,$userId,'delivery',$eventKey,$st)) $out['statuses']++;

                }
            }
        }
        if ($connId > 0) {
            try {
                WhatsAppTemplates::saveMeta($connId, ['last_webhook_at' => gmdate('Y-m-d H:i:s')]);
            } catch (Throwable $e) {
            }
        }
        return $out;
    }
}
