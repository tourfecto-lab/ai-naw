<?php
require_once __DIR__ . '/../Services/PlatformGrowth/PlatformOutreachService.php';

/**
 * Tourfecto - لوحة "تسويق المنصة" (أدمن) + لينكات التتبع وإلغاء الاشتراك العامة
 */
class PlatformOutreachController extends Controller
{
    private function allowed(): bool
    {
        return $this->isAuthenticated() && in_array($this->user['role'] ?? '', ['admin', 'super_admin'], true);
    }

    /**
     * لينكات إيميل التجربة بتبدأ بـ pv- : بتفتح نفس الصفحات بس مابتتحسبش
     * على الشركة الحقيقية (ولا فتح ولا ضغطة ولا إلغاء اشتراك).
     * @return array{0:string,1:bool} [التوكن الحقيقي, يتحسب؟]
     */
    private function token(array $params): array
    {
        $t = (string) ($params['token'] ?? '');
        if (str_starts_with($t, 'pv-')) {
            return [substr($t, 3), false];
        }
        return [$t, !$this->allowed()];
    }

    private function guard(): ?array
    {
        if (!$this->allowed()) {
            return $this->error('غير مصرح', 403);
        }
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        return $this->rateLimitGuard('user', 'platform_outreach_admin', 60, 60);
    }

    private function service(): PlatformOutreachService
    {
        return new PlatformOutreachService();
    }

    /** GET /admin/growth */
    public function index(array $params = []): array
    {
        if (!$this->allowed()) {
            return $this->error('غير مصرح', 403);
        }
        $css = htmlspecialchars(asset_v('/assets/css/admin-growth.css'), ENT_QUOTES, 'UTF-8');
        $js = htmlspecialchars(asset_v('/assets/js/admin-growth.js'), ENT_QUOTES, 'UTF-8');
        $body = '<link rel="stylesheet" href="' . $css . '"><section id="growthRoot" class="gr" dir="rtl"><p>جاري التحميل…</p></section><script src="' . $js . '"></script>';
        echo $this->renderPanelPage('platform-growth', 'تسويق المنصة', 'جيب شركات سياحة جديدة بالإيميل - بشكل احترافي وآمن', $body, 'window.AdminGrowth&&window.AdminGrowth.init(window.Panel);');
        exit;
    }

    /** GET /api/admin/growth */
    public function overview(array $params = []): array
    {
        if (!$this->allowed()) {
            return $this->error('غير مصرح', 403);
        }
        header('Cache-Control: private, no-store');
        try {
            $s = $this->service();
            return $this->success(['stats' => $s->stats(), 'settings' => $s->getSettings(true), 'csrf_token' => Csrf::token(), 'admin_email' => (string) ($this->user['email'] ?? '')]);
        } catch (Throwable $e) {
            Logger::error('Growth overview failed', ['error' => $e->getMessage()]);
            return $this->error('تعذر تحميل البيانات: ' . $e->getMessage(), 503);
        }
    }

    /** GET /api/admin/growth/leads */
    public function leads(array $params = []): array
    {
        if (!$this->allowed()) {
            return $this->error('غير مصرح', 403);
        }
        return $this->success($this->service()->leads(['status' => $this->get('status', ''), 'country' => $this->get('country', ''), 'city' => $this->get('city', ''), 'track' => $this->get('track', ''), 'approval' => $this->get('approval', ''), 'q' => $this->get('q', ''), 'page' => $this->get('page', 1)]));
    }

    /** POST /api/admin/growth/scan - افحص مواقع الشركات الجديدة وحدد احتياجها */
    public function scan(array $params = []): array
    {
        if ($e = $this->guard()) {
            return $e;
        }
        @set_time_limit(120);
        return $this->success(['scanned' => $this->service()->scan(20, 80)]);
    }

    /** POST /api/admin/growth/approve - {ids:[..]} أو {all:true} */
    public function approve(array $params = []): array
    {
        if ($e = $this->guard()) {
            return $e;
        }
        $ids = $this->get('ids', []);
        $n = $this->service()->approve(is_array($ids) ? $ids : [], (bool) $this->get('all', false), strtoupper((string) $this->get('country', '')), (bool) $this->get('tours_only', false));
        return $this->success(['approved' => $n], 'اتوافق على ' . $n . ' شركة');
    }

    /** GET /api/admin/growth/leads/{id}/preview - الرسالة الجاية للشركة دي بالظبط */
    public function leadPreview(array $params = []): array
    {
        if (!$this->allowed()) {
            return $this->error('غير مصرح', 403);
        }
        try {
            return $this->success($this->service()->previewLead((int) ($params['id'] ?? 0), null, (int) $this->get('step', 0)));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    /** POST /api/admin/growth/settings */
    public function saveSettings(array $params = []): array
    {
        if ($e = $this->guard()) {
            return $e;
        }
        try {
            $in = $this->get('settings', []);
            if (!is_array($in)) {
                throw new InvalidArgumentException('بيانات غير صالحة');
            }
            $s = $this->service();
            if (($in['enabled'] ?? '') === '1') {
                (new SystemSettingsService())->set('outreach_paused_reason', '');
            }
            return $this->success(['settings' => $s->saveSettings($in)], 'اتحفظ');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** POST /api/admin/growth/import */
    public function import(array $params = []): array
    {
        if ($e = $this->guard()) {
            return $e;
        }
        @set_time_limit(180);
        try {
            $s = $this->service();
            $url = trim((string) $this->get('url', ''));
            $text = (string) $this->get('text', '');
            $r = $url !== '' ? $s->importFromUrl($url) : ($text !== '' ? $s->importText($text) : null);
            if ($r === null) {
                return $this->error('حط رابط البيانات أو الصق البيانات', 422);
            }
            return $this->success($r, 'تم الاستيراد');
        } catch (InvalidArgumentException | RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Logger::error('Growth import failed', ['error' => $e->getMessage()]);
            return $this->error('حصلت مشكلة في الاستيراد', 500);
        }
    }

    /** POST /api/admin/growth/leads/{id}/status */
    public function leadStatus(array $params = []): array
    {
        if ($e = $this->guard()) {
            return $e;
        }
        try {
            $id = (int) ($params['id'] ?? 0);
            if ($this->get('status') === 'delete') {
                $this->service()->deleteLead($id);
            } else {
                $note = $this->get('note');
                $this->service()->setLeadStatus($id, (string) $this->get('status', ''), is_string($note) && $note !== '' ? mb_substr($note, 0, 1000) : null);
            }
            return $this->success([]);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** POST /api/admin/growth/preview - شكل الرسالة لشركة حقيقية من القائمة */
    public function preview(array $params = []): array
    {
        if ($e = $this->guard()) {
            return $e;
        }
        $s = $this->service();
        $set = $s->getSettings();
        $tpl = $this->get('templates');
        if (is_array($tpl)) {
            $set['templates'] = array_replace_recursive($set['templates'], $tpl);
        }
        $lead = $s->sampleLead();
        $lang = in_array($this->get('lang'), ['ar', 'en'], true) ? $this->get('lang') : $lead['language'];
        $lead['language'] = $lang;
        $step = max(1, min(3, (int) $this->get('step', 1)));
        $m = $s->compose($lead, $step, $set);
        return $this->success(['subject' => $m['subject'], 'html' => $m['html'], 'lead' => ['company' => $lead['company'], 'city' => $lead['city'] ?? '', 'rating' => $lead['rating'] ?? null, 'reviews' => $lead['reviews'] ?? null]]);
    }

    /** POST /api/admin/growth/test - يبعت الرسالة لإيميلك انت */
    public function test(array $params = []): array
    {
        if ($e = $this->guard()) {
            return $e;
        }
        $to = trim((string) $this->get('email', $this->user['email'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->error('اكتب إيميل صحيح', 422);
        }
        $s = $this->service();
        $set = $s->getSettings();
        $lead = $s->sampleLead();
        // لينكات التجربة مابتتحسبش على الشركة الحقيقية اللي اتاخدت كعيّنة
        $lead['token'] = 'pv-' . $lead['token'];
        $step = max(1, min(3, (int) $this->get('step', 1)));
        $m = $s->compose($lead, $step, $set);
        $mailer = new Mailer();
        if ($set['smtp_host'] !== '') {
            $mailer->configure(['host' => $set['smtp_host'], 'port' => (int) $set['smtp_port'], 'username' => $set['smtp_username'], 'password' => $set['smtp_password'], 'encryption' => $set['smtp_encryption']]);
        }
        $mailer->configure(['from_email' => $set['from_email'], 'from_name' => $set['sender_name']]);
        $r = $mailer->send($to, 'Test', '[تجربة] ' . $m['subject'], $m['html'], $set['reply_to'] !== '' ? ['Reply-To' => $set['reply_to']] : []);
        return !empty($r['success']) ? $this->success([], 'اتبعتت لـ ' . $to) : $this->error('الإرسال فشل: ' . ($r['error'] ?? ''), 502);
    }

    /** POST /api/admin/growth/run - دفعة دلوقتي (بتحترم الحد اليومي) */
    public function runNow(array $params = []): array
    {
        if ($e = $this->guard()) {
            return $e;
        }
        @set_time_limit(120);
        $s = $this->service();
        $what = $this->get('what', 'send');
        if ($what === 'enrich') {
            return $this->success(['found' => $s->enrich(15, 50)]);
        }
        $r = $s->run(null, null, true);
        (new SystemSettingsService())->set('outreach_last_run', date('Y-m-d H:i:s'));
        return $this->success($r);
    }

    // ------------------------------------------------------------ لينكات عامة (من غير تسجيل دخول)

    /** GET/POST /p/u/{token} - إلغاء الاشتراك */
    public function unsubscribe(array $params = []): array
    {
        $s = $this->service();
        [$token, $real] = $this->token($params);
        $lead = $s->leadByToken($token);
        $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        if ($post && $lead && $real) {
            $s->unsubscribe($token);
        }
        $ar = ($lead['language'] ?? 'ar') === 'ar';
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        $done = $post || ($lead && $lead['status'] === 'unsubscribed');
        $title = $done ? ($ar ? 'تم إلغاء الاشتراك' : 'You are unsubscribed') : ($ar ? 'إلغاء الاشتراك' : 'Unsubscribe');
        $msg = !$lead ? ($ar ? 'الرابط ده مش صالح.' : 'This link is not valid.')
            : ($done ? ($ar ? 'مش هنبعتلكم رسايل تاني. آسفين على الإزعاج.' : 'You will not receive any more emails from us. Sorry for the bother.')
                : ($ar ? 'متأكدين إنكم مش عاوزين رسايل تاني من Tourfecto؟' : 'Stop all emails from Tourfecto?'));
        $btn = ($lead && !$done) ? '<form method="post"><button style="background:#0E4D64;color:#fff;border:0;padding:12px 22px;border-radius:8px;font-size:15px;cursor:pointer">' . ($ar ? 'أيوه، ألغِ الاشتراك' : 'Yes, unsubscribe') . '</button></form>' : '';
        echo '<!doctype html><html lang="' . ($ar ? 'ar' : 'en') . '" dir="' . ($ar ? 'rtl' : 'ltr') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . $title . '</title></head><body style="font-family:Arial,Tahoma,sans-serif;background:#f4f6f8;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0"><div style="background:#fff;padding:32px;border-radius:14px;max-width:420px;text-align:center;box-shadow:0 4px 20px #0001"><h1 style="font-size:22px">' . $title . '</h1><p style="color:#555;line-height:1.7">' . $msg . '</p>' . $btn . '</div></body></html>';
        exit;
    }

    /** GET /p/c/{token}/{step} - ضغطة على اللينك */
    public function click(array $params = []): array
    {
        $s = $this->service();
        $step = max(1, min(5, (int) ($params['step'] ?? 1)));
        [$token, $real] = $this->token($params);
        $lead = $s->track($token, $step, 'click', $real);
        $url = $lead ? $s->signupLinkFor($lead, $step) : (defined('APP_URL') ? rtrim(APP_URL, '/') : '/') . '/register';
        header('Location: ' . $url, true, 302);
        exit;
    }

    /** GET /p/w/{token}/{step} - زرار الواتساب: الشركة هي اللي بتبدأ المحادثة */
    public function whatsapp(array $params = []): array
    {
        $step = max(1, min(5, (int) ($params['step'] ?? 1)));
        [$token, $real] = $this->token($params);
        $url = $this->service()->whatsappUrl($token, $step, $real);
        header('Location: ' . ($url ?: ((defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/register')), true, 302);
        exit;
    }

    /** GET /p/r/{token} - التقرير المجاني للشركة: احتياجاتها بأرقامها والخدمات اللي بتحلها */
    public function report(array $params = []): array
    {
        [$token, $real] = $this->token($params);
        $r = $this->service()->report($token, $real); // الأدمن وهو بيراجع مابيتحسبش
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Robots-Tag: noindex, nofollow');
        if (!$r) {
            http_response_code(404);
            echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><p style="font-family:Arial;padding:40px;text-align:center">This link is not valid.</p>';
            exit;
        }
        $lang = $r['lang'];
        $l = $r['lead'];
        if (str_starts_with((string) ($params['token'] ?? ''), 'pv-')) {
            $l['token'] = 'pv-' . $l['token']; // زراير التقرير في التجربة كمان مابتتحسبش
        }
        $step = max(1, min(5, (int) ($_GET['s'] ?? 1)));
        $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $T = [
            'ar' => ['title' => 'تقرير مجاني لـ', 'intro' => 'ده اللي لقيناه لما بصّينا على بيانات شركتكم الظاهرة على الإنترنت، وإزاي Tourfecto بيساعد في كل نقطة.', 'fix' => 'إزاي Tourfecto بيحلها', 'cta' => 'ابدأوا مجانًا', 'wa' => 'كلّمونا على واتساب', 'note' => 'التقرير معمول من بيانات عامة (التقييمات وموقعكم). مش عاوزين رسايل تاني؟', 'unsub' => 'إلغاء الاشتراك', 'rating' => 'التقييم'],
            'en' => ['title' => 'Free report for', 'intro' => 'Here is what we found looking at your public online presence, and how Tourfecto helps with each point.', 'fix' => 'How Tourfecto fixes it', 'cta' => 'Start free', 'wa' => 'Message us on WhatsApp', 'note' => 'This report is based on public data (your reviews and website). Prefer no more emails?', 'unsub' => 'Unsubscribe', 'rating' => 'Rating'],
            'tr' => ['title' => 'Ücretsiz rapor:', 'intro' => 'İnternetteki herkese açık bilgilerinize baktığımızda bulduklarımız ve Tourfecto\'nun her konuda nasıl yardımcı olduğu.', 'fix' => 'Tourfecto nasıl çözer', 'cta' => 'Ücretsiz başlayın', 'wa' => "WhatsApp'tan yazın", 'note' => 'Bu rapor herkese açık verilere (yorumlarınız ve web siteniz) dayanır. Başka e-posta istemiyor musunuz?', 'unsub' => 'Abonelikten çıkın'],
        ][$lang];
        $T += ['rating' => 'Puan'];
        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
        $cards = '';
        if (!empty($r['audit'])) {
            $cards = $this->auditSections($r['audit'], $l, $lang, $e);
            $r['items'] = [];
        }
        foreach ($r['items'] as $i => $it) {
            $cards .= '<section class="card"><div class="num">' . ($i + 1) . '</div><div><h2>' . $e($it['label']) . '</h2><p>' . $e($it['finding']) . '</p><h3>' . $e($T['fix']) . '</h3><ul>'
                . implode('', array_map(static fn ($s) => '<li>' . $e($s) . '</li>', $it['services'])) . '</ul></div></section>';
        }
        $meta = array_filter([$l['city'] ?? '', $l['category'] ?? '', $l['rating'] ? $T['rating'] . ' ' . rtrim(rtrim(number_format((float) $l['rating'], 1), '0'), '.') . ($l['reviews'] ? ' (' . number_format((int) $l['reviews']) . ')' : '') : '']);
        $wa = $r['has_whatsapp'] ? '<a class="btn ghost" href="' . $e($base . '/p/w/' . $l['token'] . '/' . $step) . '">' . $e($T['wa']) . '</a>' : '';
        $dir = $lang === 'ar' ? 'rtl' : 'ltr';
        echo '<!doctype html><html lang="' . $lang . '" dir="' . $dir . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . $e($T['title'] . ' ' . $l['company']) . '</title><style>'
            . 'body{margin:0;background:#f4f6f8;color:#1f2933;font-family:Tahoma,Arial,sans-serif;line-height:1.7}.wrap{max-width:760px;margin:0 auto;padding:28px 16px 48px}'
            . 'header{background:#0E4D64;color:#fff;border-radius:16px;padding:26px 24px;margin-bottom:18px}header h1{margin:0 0 6px;font-size:24px}header p{margin:0;opacity:.9}.meta{font-size:13px;opacity:.85;margin-top:8px}'
            . '.card{display:flex;gap:14px;background:#fff;border-radius:14px;padding:18px;margin:12px 0;box-shadow:0 2px 10px #0000000d}.num{flex:0 0 34px;height:34px;border-radius:50%;background:#e6f2f5;color:#0E4D64;font-weight:bold;display:flex;align-items:center;justify-content:center}'
            . '.card h2{margin:2px 0 6px;font-size:18px}.card h3{margin:12px 0 4px;font-size:14px;color:#0E4D64}.card ul{margin:0;padding-inline-start:20px}.card p{margin:0}'
            . '.score{display:flex;gap:18px;align-items:center;background:#fff;border-radius:16px;padding:20px;margin:12px 0;box-shadow:0 2px 10px #0000000d}.ring{flex:0 0 110px;height:110px;border-radius:50%;display:flex;align-items:center;justify-content:center}.ring b{background:#fff;width:86px;height:86px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:30px}'
            . '.score h2{margin:0 0 4px;font-size:19px}.score p{margin:0;color:#555}.pillars{background:#fff;border-radius:16px;padding:18px 20px;margin:12px 0;box-shadow:0 2px 10px #0000000d}.pill{display:grid;grid-template-columns:130px 1fr 40px;gap:10px;align-items:center;margin:8px 0;font-size:14px}.bar{height:10px;background:#eef0f3;border-radius:99px;overflow:hidden}.bar i{display:block;height:100%;border-radius:99px}'
            . '.gap{display:flex;gap:12px;background:#fff;border-radius:14px;padding:14px 16px;margin:10px 0;box-shadow:0 2px 10px #0000000d;border-inline-start:5px solid #ccc}.gap .ic{font-size:22px}.gap small{color:#888;font-weight:bold}.gap p{margin:2px 0 6px}.fix{font-size:13px;color:#0E4D64;background:#eef6f8;border-radius:8px;padding:6px 10px;display:inline-block}'
            . '.chips{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}.chip{padding:5px 12px;border-radius:99px;font-size:13px;font-weight:bold}.chip.on{background:#e3f6ec;color:#1d7a4a}.chip.off{background:#fdecec;color:#b42318;text-decoration:line-through}'
            . 'table.cmp{width:100%;border-collapse:collapse;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 2px 10px #0000000d;font-size:14px}table.cmp th,table.cmp td{padding:10px 12px;border-bottom:1px solid #f0f2f5;text-align:start}table.cmp tr.me{background:#fff7e6;font-weight:bold}h2.sec{font-size:17px;margin:26px 0 6px}'
            . '@media(max-width:560px){.pill{grid-template-columns:100px 1fr 34px}.score{flex-direction:column;text-align:center}}'
            . '.cta{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px}.btn{display:inline-block;background:#0E4D64;color:#fff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:bold}.btn.ghost{background:#25D366}'
            . 'footer{font-size:12px;color:#777;margin-top:26px}footer a{color:#777}</style></head><body><div class="wrap">'
            . '<header><h1>' . $e($T['title'] . ' ' . $l['company']) . '</h1><p>' . $e($T['intro']) . '</p>' . ($meta ? '<div class="meta">' . $e(implode(' · ', $meta)) . '</div>' : '') . '</header>'
            . $cards
            . '<div class="cta"><a class="btn" href="' . $e($base . '/p/c/' . $l['token'] . '/' . $step) . '">' . $e($T['cta']) . '</a>' . $wa . '</div>'
            . '<footer>' . $e($T['note']) . ' <a href="' . $e($base . '/p/u/' . $l['token']) . '">' . $e($T['unsub']) . '</a></footer>'
            . '</div></body></html>';
        exit;
    }

    /** أقسام التقرير الاحترافي: الدرجة، المحاور، النواقص، السوشيال، جوجل، المنافسين */
    private function auditSections(array $a, array $l, string $lang, callable $e): string
    {
        $L = [
            'ar' => ['score' => 'الدرجة الرقمية', 'of' => '{s} من 100', 'avg' => 'متوسط شركات {cat} في {city}: {avg}', 'pillars' => 'المحاور', 'gaps' => 'اللي لقيناه بالتفصيل', 'fix' => 'الحل في Tourfecto:', 'social' => 'السوشيال ميديا على موقعكم', 'google' => 'ملفكم على Google Maps', 'g_found' => 'التقييم {rating} من {reviews} تقييم', 'g_missing' => 'مالقيناش ملف لشركتكم على Google Maps', 'cmp' => 'مقارنة بالمنافسين ({cat} في {city})', 'company' => 'الشركة', 'reviews' => 'التقييمات', 'rating' => 'التقييم', 'you' => 'انتم', 'median' => 'متوسط المدينة'],
            'en' => ['score' => 'Digital score', 'of' => '{s} out of 100', 'avg' => 'Average for {cat} companies in {city}: {avg}', 'pillars' => 'Pillars', 'gaps' => 'What we found, in detail', 'fix' => 'Tourfecto fix:', 'social' => 'Social media on your website', 'google' => 'Your Google Maps profile', 'g_found' => 'Rated {rating} from {reviews} reviews', 'g_missing' => "We couldn't find a Google Maps profile for your company", 'cmp' => 'Compared with competitors ({cat} in {city})', 'company' => 'Company', 'reviews' => 'Reviews', 'rating' => 'Rating', 'you' => 'You', 'median' => 'City median'],
            'tr' => ['score' => 'Dijital puan', 'of' => '100 üzerinden {s}', 'avg' => '{city} içindeki {cat} şirketlerinin ortalaması: {avg}', 'pillars' => 'Alanlar', 'gaps' => 'Bulduklarımız (ayrıntılı)', 'fix' => 'Tourfecto çözümü:', 'social' => 'Web sitenizdeki sosyal medya', 'google' => 'Google Haritalar profiliniz', 'g_found' => '{reviews} yorumdan {rating} puan', 'g_missing' => 'Şirketiniz için Google Haritalar profili bulamadık', 'cmp' => 'Rakiplerle karşılaştırma ({city} içinde {cat})', 'company' => 'Şirket', 'reviews' => 'Yorum', 'rating' => 'Puan', 'you' => 'Siz', 'median' => 'Şehir ortalaması'],
        ][$lang];
        $color = static fn (int $v) => $v >= 75 ? '#2f9e6a' : ($v >= 50 ? '#e0a100' : '#d1453b');
        $s = (int) $a['score'];
        $fr = static fn ($r) => is_numeric($r) && $r > 0 ? number_format((float) $r, 1) : '—';
        $avg = $a['peer_score'] !== null ? strtr($L['avg'], ['{cat}' => $a['category'], '{city}' => (string) ($l['city'] ?? ''), '{avg}' => (string) $a['peer_score']]) : '';
        $html = '<section class="score"><div class="ring" style="background:conic-gradient(' . $color($s) . ' ' . ($s * 3.6) . 'deg,#eef0f3 0)"><b style="color:' . $color($s) . '">' . $s . '</b></div><div><h2>' . $e($L['score']) . ': ' . $e(strtr($L['of'], ['{s}' => (string) $s])) . '</h2><p>' . $e($avg) . '</p></div></section>';
        $html .= '<section class="pillars"><h2 class="sec" style="margin-top:0">' . $e($L['pillars']) . '</h2>';
        foreach ($a['pillars'] as $p) {
            $v = (int) $p['value'];
            $html .= '<div class="pill"><span>' . $e($p['label']) . '</span><div class="bar"><i style="width:' . $v . '%;background:' . $color($v) . '"></i></div><b style="color:' . $color($v) . '">' . $v . '</b></div>';
        }
        $html .= '</section>';
        if ($a['gaps']) {
            $html .= '<h2 class="sec">' . $e($L['gaps']) . '</h2>';
            foreach ($a['gaps'] as $g) {
                $c = $g['sev'] >= 7 ? '#d1453b' : ($g['sev'] >= 5 ? '#e0a100' : '#9aa3af');
                $fix = $g['fix'] ? '<span class="fix">' . $e($L['fix']) . ' <b>' . $e($g['fix'][0]) . '</b> · ' . $e($g['fix'][1]) . '</span>' : '';
                $html .= '<div class="gap" style="border-inline-start-color:' . $c . '"><div class="ic">' . $e($g['icon']) . '</div><div><small>' . $e($g['pillar_label']) . '</small><p>' . $e($g['text']) . '</p>' . $fix . '</div></div>';
            }
        }
        if (!empty($l['website'])) {
            $nets = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'youtube' => 'YouTube', 'linkedin' => 'LinkedIn', 'x' => 'X'];
            $html .= '<h2 class="sec">' . $e($L['social']) . '</h2><div class="chips">' . implode('', array_map(static fn ($k, $n) => '<span class="chip ' . (in_array($k, $a['social'], true) ? 'on">✓ ' : 'off">') . $n . '</span>', array_keys($nets), $nets)) . '</div>';
        }
        if (!empty($a['google']['checked'])) {
            $html .= '<h2 class="sec">' . $e($L['google']) . '</h2><p>' . (!empty($a['google']['found'])
                ? '⭐ ' . $e(strtr($L['g_found'], ['{rating}' => (string) ($a['google']['rating'] ?? '—'), '{reviews}' => number_format((int) $a['google']['reviews'])]))
                : '⚠️ ' . $e($L['g_missing'])) . '</p>';
        }
        $b = $a['bench'];
        if (!empty($b['top'])) {
            $rows = '';
            foreach ($b['top'] as $t) {
                $rows .= '<tr><td>' . $e($t['company']) . '</td><td>' . number_format((int) $t['reviews']) . '</td><td>' . $e($fr($t['rating'] ?? null)) . '</td></tr>';
            }
            $rows .= '<tr class="me"><td>' . $e($L['you']) . ' (' . $e($l['company']) . ')</td><td>' . number_format((int) $l['reviews']) . '</td><td>' . $e($fr($l['rating'] ?? null)) . '</td></tr>';
            $rows .= '<tr><td>' . $e($L['median']) . '</td><td>' . number_format((int) $b['median_reviews']) . '</td><td>' . $e($fr($b['avg_rating'] ?? null)) . '</td></tr>';
            $html .= '<h2 class="sec">' . $e(strtr($L['cmp'], ['{cat}' => (string) ($b['category'] ?? ''), '{city}' => (string) ($l['city'] ?? '')])) . '</h2><table class="cmp"><tr><th>' . $e($L['company']) . '</th><th>' . $e($L['reviews']) . '</th><th>' . $e($L['rating']) . '</th></tr>' . $rows . '</table>';
        }
        return $html;
    }

    /** GET /p/o/{token}/{step} - فتح الرسالة (لو التتبع مفعّل) */
    public function open(array $params = []): array
    {
        try {
            [$token, $real] = $this->token($params);
            $this->service()->track($token, max(1, min(3, (int) ($params['step'] ?? 1))), 'open', $real);
        } catch (Throwable $e) {
        error_log('[PlatformOutreachController] ignored error: ' . $e->getMessage());
        }
        header('Content-Type: image/gif');
        header('Cache-Control: no-store');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit;
    }
}
