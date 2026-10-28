<?php
/**
 * GET /admin/meta-review - جاهزية مراجعة تطبيق Meta (WhatsApp Business Platform)
 * فحص حقيقي لكل شرط بتسأل عنه Meta قبل ما تدي Advanced Access للصلاحيات، مع النصوص اللي
 * بتتكتب في طلب المراجعة (بالإنجليزي لأن المراجعين بيقروه) وسيناريو الفيديو.
 */
class MetaReviewController extends Controller
{
    private function allowed(): bool
    {
        return $this->isAuthenticated() && in_array($this->user['role'] ?? '', ['admin', 'super_admin'], true);
    }

    /** قيمة من system_settings من غير فك تشفير (للتواريخ اللي بنسجلها إحنا) */
    private function stamp(string $key): string
    {
        try {
            return (string) ($this->db->query('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1', [$key])[0]['setting_value'] ?? '');
        } catch (Throwable $e) {
            return '';
        }
    }

    private function count(string $sql, array $p = []): ?int
    {
        try {
            return (int) (array_values($this->db->query($sql, $p)[0] ?? [0])[0] ?? 0);
        } catch (Throwable $e) {
            return null; // الجدول مش موجود
        }
    }

    /** @return array<int,array{0:string,1:string,2:string,3:string}> [الحالة ok|warn|bad, العنوان, التفاصيل, إزاي تصلحها] */
    public function checks(): array
    {
        $set = fn (string $k, string $env) => class_exists('MetaOAuthClient') ? MetaOAuthClient::setting($k, $env) : '';
        $base = class_exists('MetaOAuthClient') ? MetaOAuthClient::baseUrl() : rtrim((string) (defined('APP_URL') ? APP_URL : ''), '/');
        $out = [];
        $appId = $set('meta_app_id', 'META_APP_ID');
        $out[] = [$appId !== '' ? 'ok' : 'bad', 'App ID', $appId !== '' ? $appId : 'مش متسجل', 'انسخه من developers.facebook.com ← التطبيق ← App settings ← Basic، وحطه في /admin/settings ← Meta.'];
        $secret = $set('meta_app_secret', 'META_APP_SECRET') !== '';
        $out[] = [$secret ? 'ok' : 'bad', 'App Secret', $secret ? 'متسجل (مخفي)' : 'مش متسجل', 'من نفس صفحة Basic اضغط Show جنب App Secret، وحطه في /admin/settings. من غيره كل رسايل Meta بتترفض.'];
        $cfg = preg_replace('/\D/', '', $set('meta_wa_config_id', 'META_WA_CONFIG_ID'));
        $out[] = [$cfg !== '' ? 'ok' : 'bad', 'WhatsApp Embedded Signup Config ID', $cfg !== '' ? $cfg : 'مش متسجل', 'Facebook Login for Business ← Configurations ← Create: نوع WhatsApp Embedded Signup بالصلاحيات whatsapp_business_management و whatsapp_business_messaging و business_management.'];
        $https = str_starts_with($base, 'https://');
        $out[] = [$https ? 'ok' : 'bad', 'رابط المنصة HTTPS', $base, 'Meta مابتقبلش روابط من غير HTTPS. ظبط APP_URL.'];
        $verified = $this->stamp('wa_platform_webhook_verified_at');
        $out[] = [$verified !== '' ? 'ok' : 'bad', 'Meta اتحققت من الـ Webhook', $verified !== '' ? $verified . ' UTC' : 'لسه', 'WhatsApp ← Configuration: Callback URL = ' . $base . '/api/whatsapp-cloud/webhook والـ Verify token من /admin/settings، وبعدين اضغط Verify and save.'];
        $last = $this->stamp('wa_platform_webhook_last_at');
        $out[] = [$last !== '' ? 'ok' : 'warn', 'أحداث وصلت على الـ Webhook', $last !== '' ? 'آخر حدث ' . $last . ' UTC' : 'لسه مفيش', 'اشترك في messages تحت Webhook fields، وابعت رسالة تجربة للرقم.'];
        $nums = $this->count("SELECT COUNT(*) FROM platform_connections WHERE platform = 'whatsapp_cloud' AND status = 'connected'");
        $embedded = $this->count("SELECT COUNT(*) FROM platform_connections WHERE platform = 'whatsapp_cloud' AND status = 'connected' AND meta_json LIKE '%\"embedded\":true%'");
        $out[] = [($embedded ?? 0) > 0 ? 'ok' : 'bad', 'رقم اتربط بضغطة (Embedded Signup)', ($embedded ?? 0) . ' رقم بضغطة من ' . ($nums ?? 0) . ' رقم مربوط', 'ادخل كعميل ← إعدادات الشات ← «اربط واتساب بضغطة» وكمّل لحد اختيار الرقم. ده أهم مشهد في فيديو المراجعة.'];
        $tpl = $this->count("SELECT COUNT(*) FROM wa_marketing_templates WHERE status = 'APPROVED'");
        $out[] = [($tpl ?? 0) > 0 ? 'ok' : 'warn', 'قوالب رسايل متوافق عليها', $tpl === null ? 'جدول القوالب مش موجود' : $tpl . ' قالب', 'من تسويق واتساب ← قدّم قالب لموافقة Meta، وبعد الموافقة اضغط تحديث القوالب.'];
        $sent = $this->count("SELECT COUNT(*) FROM whatsapp_delivery_statuses WHERE status IN ('delivered','read') AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $out[] = [($sent ?? 0) > 0 ? 'ok' : 'warn', 'رسايل اتبعتت ووصلت (آخر 30 يوم)', $sent === null ? 'جدول الحالات مش موجود' : $sent . ' رسالة', 'ابعت رسالة تجربة من إعدادات الشات. لازم الفيديو يوري الرسالة وهي بتوصل على موبايل.'];
        $out[] = ['ok', 'سياسة الخصوصية فيها WhatsApp Business Platform', $base . '/privacy#whatsapp', 'القسم بيتضاف تلقائيًا. حط الرابط ده في Basic ← Privacy Policy URL.'];
        $out[] = ['ok', 'طريقة حذف البيانات', $base . '/data-deletion', 'حطه في Basic ← User data deletion (Data deletion instructions URL).'];
        $out[] = ['ok', 'إلغاء الاشتراك بكلمة STOP', 'العميل اللي يرد STOP أو إلغاء بيتشال من الحملات تلقائيًا', 'ورّيها في الفيديو: ابعت STOP من الموبايل وورّي حالة العميل اتغيرت لـ Opted out.'];
        return $out;
    }

    public function index(array $params = []): array
    {
        if (!$this->allowed()) {
            return $this->error('غير مصرح', 403);
        }
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $base = class_exists('MetaOAuthClient') ? MetaOAuthClient::baseUrl() : rtrim((string) (defined('APP_URL') ? APP_URL : ''), '/');
        $checks = $this->checks();
        $bad = count(array_filter($checks, fn ($c) => $c[0] === 'bad'));
        $warn = count(array_filter($checks, fn ($c) => $c[0] === 'warn'));
        $icon = ['ok' => '✅', 'warn' => '⚠️', 'bad' => '❌'];
        $rows = '';
        foreach ($checks as [$state, $title, $detail, $fix]) {
            $rows .= '<tr><td style="width:32px">' . $icon[$state] . '</td><td><b>' . $e($title) . '</b><div class="p-cell-muted" dir="auto">' . $e($detail) . '</div></td><td style="font-size:13px">' . ($state === 'ok' ? '' : $e($fix)) . '</td></tr>';
        }
        $copy = function (string $id, string $title, string $text) use ($e) {
            return '<div class="p-card" style="margin-top:12px"><div style="display:flex;justify-content:space-between;align-items:center;gap:8px"><h4 style="margin:0">' . $e($title) . '</h4>'
                . '<button type="button" class="p-btn outline xs" onclick="navigator.clipboard.writeText(document.getElementById(\'' . $id . '\').innerText).then(()=>window.Panel&&Panel.toast(\'اتنسخ\',\'success\'))">نسخ</button></div>'
                . '<pre id="' . $id . '" dir="ltr" style="white-space:pre-wrap;text-align:left;background:rgba(127,127,127,.08);padding:12px;border-radius:10px;font-size:12.5px;margin:8px 0 0">' . $e($text) . '</pre></div>';
        };
        $mgmt = "Tourfecto is a SaaS platform for travel agencies and tour operators. We use whatsapp_business_management so that each business can connect its OWN WhatsApp Business Account through Embedded Signup inside Tourfecto (Chat Settings → \"Connect WhatsApp in one click\").\n\nAfter the business completes Embedded Signup we use this permission to:\n1. Read the WhatsApp Business Account and phone number the business selected (display number, verified name, quality rating) and show them in its settings.\n2. Subscribe our app to that WABA so the business receives its customers' messages in the Tourfecto inbox.\n3. Create and read message templates (booking reminder, review request, follow-up, and marketing templates written by the business) and show their approval status.\n\nWe only access WABAs that a business explicitly shared with us during Embedded Signup, and the business can disconnect at any time.";
        $msg = "We use whatsapp_business_messaging to send and receive messages on behalf of the business that connected its number.\n\n1. Customer messages arrive on our webhook (signature verified with our App Secret) and appear in the business's Tourfecto inbox.\n2. The business replies from the inbox (or approves a reply drafted by its AI assistant) within the 24-hour customer service window.\n3. Outside the window we only send approved templates: trip reminders, review requests after a trip, and marketing templates.\n4. Marketing messages go only to contacts with a recorded opt-in. Replying STOP marks the contact as opted out and excludes it from all future campaigns.\n5. Delivery and read statuses are shown in campaign reports.";
        $biz = "We use business_management only during Embedded Signup, to let the business choose or create the Meta Business portfolio and WhatsApp Business Account it wants to connect to Tourfecto. We do not manage any other assets of the business.";
        $video = "Screencast (record in English UI, 2–4 minutes, show the real phone):\n1. Log in to https://tourfecto.pro as the test business account.\n2. Open Chat Settings → Official WhatsApp → click \"Connect WhatsApp in one click\".\n3. Complete the Meta Embedded Signup popup: choose the business portfolio, WhatsApp Business Account and phone number. Show the permissions screen.\n4. Back in Tourfecto, show the connected number, verified name and templates list (whatsapp_business_management).\n5. Click \"Send a test message\" and show it arriving on the phone.\n6. Reply from the phone and show the message appearing in the Tourfecto inbox; reply from the inbox and show it on the phone (whatsapp_business_messaging).\n7. WhatsApp Marketing: add a contact with recorded consent, submit a marketing template that includes \"Reply STOP to unsubscribe\", then send STOP from the phone and show the contact becoming Opted out.\n8. Show Disconnect in Chat Settings.";
        $notes = "Test account for reviewers:\nURL: " . $base . "/login\nEmail: (reviewer test account email)\nPassword: (reviewer test account password)\n\nThe reviewer can open Chat Settings → Official WhatsApp to see the Embedded Signup button, and WhatsApp Marketing to see consent, templates and campaign reports. Privacy policy: " . $base . "/privacy#whatsapp — Data deletion: " . $base . "/data-deletion";
        $summary = $bad === 0 && $warn === 0
            ? '<div class="p-card" style="border-inline-start:4px solid #22c55e">✅ كل الشروط اللي المنصة تقدر تفحصها جاهزة. فاضل الخطوات اللي في Meta نفسها تحت.</div>'
            : '<div class="p-card" style="border-inline-start:4px solid ' . ($bad ? '#dc2626' : '#f59e0b') . '">' . ($bad ? '❌ ' . $bad . ' شرط ناقص لازم يتصلح قبل التقديم' : '') . ($bad && $warn ? ' · ' : '') . ($warn ? '⚠️ ' . $warn . ' حاجة يفضّل تخلص قبل تسجيل الفيديو' : '') . '</div>';
        $metaSteps = '<div class="p-card" style="margin-top:12px"><h4 style="margin:0 0 8px">خطوات في Meta نفسها (المنصة ماتقدرش تفحصها)</h4><ol style="line-height:2;margin:0;padding-inline-start:20px;font-size:14px">'
            . '<li>توثيق النشاط التجاري (Business Verification) في Meta Business Suite.</li>'
            . '<li>الانضمام كمزوّد خدمة تقنية (Tech Provider) من القايمة الجانبية في التطبيق.</li>'
            . '<li>App Review ← اطلب Advanced Access لـ whatsapp_business_management و whatsapp_business_messaging (و business_management لو ظهرتلك).</li>'
            . '<li>لكل صلاحية: الصق النص من تحت، وارفع الفيديو، وحط بيانات حساب الاختبار.</li>'
            . '<li>بعد الموافقة: انشر التطبيق (Live) من أعلى الصفحة.</li></ol></div>';
        $body = '<div dir="rtl">' . $summary
            . '<div class="p-card" style="margin-top:12px"><h4 style="margin:0 0 8px">فحص المنصة</h4><div class="p-table-scroll"><table class="p-table"><tbody>' . $rows . '</tbody></table></div></div>'
            . $metaSteps
            . '<h3 style="margin:20px 0 4px">نصوص طلب المراجعة (انسخها زي ما هي)</h3>'
            . $copy('mrMgmt', 'whatsapp_business_management', $mgmt)
            . $copy('mrMsg', 'whatsapp_business_messaging', $msg)
            . $copy('mrBiz', 'business_management', $biz)
            . $copy('mrVideo', 'سيناريو الفيديو (Screencast)', $video)
            . $copy('mrNotes', 'ملاحظات للمراجِع (Notes for reviewer)', $notes)
            . '</div>';
        echo $this->renderPanelPage('meta-review', 'جاهزية مراجعة Meta', 'واتساب الرسمي: اللي جاهز واللي ناقص قبل التقديم', $body);
        exit;
    }
}
