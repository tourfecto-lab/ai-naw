<?php

/**
 * Tourfecto - Documentation Controller
 * صفحات التوثيق (دليل الاستخدام وتوثيق API)
 * @version 2.0.0
 *
 * ملاحظة: قبل كده كانت الدوال دي بترجّع JSON خام. اتبنت من الصفر كصفحات
 * HTML حقيقية. الصفحات دي مش مربوطة بروابط في واجهة العميل العادية
 * (موجّهة لمطوّرين/دعم فني)، فمش أولوية عالية زي صفحات المساعدة، لكن
 * برضو لازم تبقى صفحات حقيقية مش نص برمجي لو حد فتحها.
 */
class DocumentationController extends Controller
{
    private function pageShell(string $title, string $bodyHtml): string
    {
        $appName = defined('APP_NAME') ? APP_NAME : 'Tourfecto';
        $brandHtml = site_brand_html();
        $lang = function_exists('current_lang') ? current_lang() : 'ar';
        $dir = function_exists('current_dir') ? current_dir() : 'rtl';

        return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}" dir="{$dir}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#060A13">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="mobile-web-app-capable" content="yes">
    <title>{$title} | {$appName}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <script src="/assets/js/tf-theme.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@300;400;500;600;700&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/compass.css">
    <style>
        .docs-wrap { max-width: 860px; margin: 0 auto; padding: 60px 8vw 100px; }
        .docs-wrap h1 { font-family: 'Fraunces', serif; font-size: 32px; margin-bottom: 10px; }
        .docs-wrap h2 { font-family: 'Fraunces', serif; font-size: 21px; margin: 34px 0 12px; color: #fff; }
        .docs-wrap .lead { color: #C9D2E0; font-size: 15px; margin-bottom: 36px; }
        .docs-wrap p, .docs-wrap li { color: #C9D2E0; font-size: 14.5px; line-height: 1.9; }
        .docs-wrap ul, .docs-wrap ol { padding-inline-start: 22px; margin-bottom: 14px; }
        .docs-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 10px; }
        @media (max-width: 640px) { .docs-cards { grid-template-columns: 1fr; } }
        .docs-card {
            display: block; padding: 22px; border-radius: 16px; text-decoration: none;
            background: linear-gradient(160deg, rgba(255,255,255,.04), rgba(255,255,255,.01));
            border: 1px solid rgba(255,255,255,.09); transition: .2s;
        }
        .docs-card:hover { border-color: var(--gold, #EFB05E); transform: translateY(-2px); }
        .docs-card .di { font-size: 26px; margin-bottom: 10px; }
        .docs-card .dt { font-family: 'Fraunces', serif; font-size: 17px; color: #fff; margin-bottom: 6px; }
        .docs-card .dd { color: #9AA6BE; font-size: 13px; line-height: 1.7; }
        .endpoint-group { margin-bottom: 26px; }
        .endpoint-row {
            display: flex; align-items: center; gap: 12px; padding: 8px 0;
            border-bottom: 1px solid rgba(255,255,255,.06); font-family: 'JetBrains Mono', monospace; font-size: 12.5px;
        }
        .method-badge { flex-shrink: 0; width: 56px; text-align: center; padding: 3px 0; border-radius: 6px; font-weight: 700; font-size: 10.5px; }
        .method-GET { background: rgba(78,205,196,.15); color: #4ECDC4; }
        .method-POST { background: rgba(239,176,94,.18); color: #EFB05E; }
        .method-PUT { background: rgba(130,170,255,.18); color: #82AAFF; }
        .method-DELETE { background: rgba(255,107,91,.18); color: #FF6B5B; }
        .endpoint-path { color: #C9D2E0; word-break: break-all; }
    </style>
</head>
<body class="compass">
<div class="stars"></div>
<div class="wrap">
    <nav class="topnav">
        <a href="/" class="brand">{$brandHtml}</a>
        <div class="nav-right"><a href="/" class="cta-ghost">← الرجوع للرئيسية</a></div>
    </nav>
    <div class="docs-wrap">
        {$bodyHtml}
    </div>
</div>
<button id="pwaInstallBtn" class="pwa-install-fab" type="button" aria-label="تثبيت التطبيق" title="تثبيت التطبيق">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <span>تثبيت التطبيق</span>
</button>
<style>
.pwa-install-fab {
    position: fixed;
    bottom: 24px;
    left: 24px;
    z-index: 9999;
    display: none;
    align-items: center;
    gap: 8px;
    background: var(--primary-color, #0077be);
    color: #fff;
    border: none;
    border-radius: 999px;
    padding: 12px 18px;
    font-family: inherit;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    box-shadow: 0 8px 24px rgba(0, 119, 190, .35);
    transition: transform .2s ease, box-shadow .2s ease, opacity .2s ease;
}
.pwa-install-fab:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 28px rgba(0, 119, 190, .45);
}
.pwa-install-fab svg { flex-shrink: 0; }
@media (max-width: 480px) {
    .pwa-install-fab span { display: none; }
    .pwa-install-fab { padding: 14px; border-radius: 50%; bottom: 18px; left: 18px; }
}
</style>
<script>
(function () {
    var btn = document.getElementById('pwaInstallBtn');
    if (!btn) return;
    var deferredPrompt = null;

    function isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    }

    if (isStandalone()) return;

    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferredPrompt = e;
        btn.style.display = 'flex';
    });

    btn.addEventListener('click', function () {
        if (!deferredPrompt) return;
        btn.style.display = 'none';
        var promptEvent = deferredPrompt;
        deferredPrompt = null;
        promptEvent.prompt();
        promptEvent.userChoice.then(function () {});
    });

    window.addEventListener('appinstalled', function () {
        btn.style.display = 'none';
        deferredPrompt = null;
    });
})();
</script>
</body>
</html>
HTML;
    }

    /** GET /docs */
    public function index(array $params = []): array
    {
        $body = <<<'HTML'
<h1>التوثيق</h1>
<p class="lead">دليل استخدام المنصة، وتوثيق فني لكل نقاط الـ API لو محتاج تتكامل برمجيًا.</p>

<div class="docs-cards">
    <a href="/docs/guide" class="docs-card">
        <div class="di">📖</div>
        <div class="dt">دليل الاستخدام</div>
        <div class="dd">شرح كامل لكل ميزة في المنصة - التحليل بالذكاء الاصطناعي، إدارة السمعة، نشر المقالات، والربط مع مواقع خارجية.</div>
    </a>
    <a href="/docs/api" class="docs-card">
        <div class="di">🔌</div>
        <div class="dt">توثيق API</div>
        <div class="dd">قائمة كل نقاط الـ API المتاحة في المنصة - مفيدة لو بتبني تكامل خاص أو تستخدم مفتاح API بتاعك.</div>
    </a>
</div>
HTML;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->pageShell('التوثيق', $body);
        exit;
    }

    /** GET /docs/api */
    public function api(array $params = []): array
    {
        $router = new Router();
        require_once dirname(__DIR__) . '/routes/api.php';

        $grouped = [];
        foreach ($router->getRoutes() as $method => $list) {
            foreach ($list as $route) {
                $path = $route['path'];
                // تجميع حسب أول قسم في المسار بعد /api/ (مثلاً /api/ai/... -> "ai")
                $segment = preg_match('#^/api/([a-z0-9_-]+)#i', $path, $m) ? $m[1] : 'أخرى';
                $grouped[$segment][] = ['method' => $method, 'path' => $path];
            }
        }
        ksort($grouped);

        $groupLabels = [
            'auth' => 'المصادقة', 'user' => 'المستخدم', 'ai' => 'الذكاء الاصطناعي',
            'reputation' => 'السمعة والتقييمات', 'chat' => 'المحادثات', 'websites' => 'المواقع',
            'subscription' => 'الاشتراكات', 'dashboard' => 'لوحة التحكم', 'admin' => 'الإدارة',
            'reports' => 'التقارير', 'settings' => 'الإعدادات', 'health' => 'الحالة الصحية',
            'docs' => 'التوثيق', 'search-console' => 'Search Console', 'publishing' => 'النشر',
            'social' => 'السوشيال ميديا', 'ads' => 'الإعلانات', 'crm' => 'CRM',
            'partner' => 'Partner API (شركاء خارجيون)',
        ];

        $sectionsHtml = '';
        foreach ($grouped as $segment => $routes) {
            $label = $groupLabels[$segment] ?? $segment;
            $rowsHtml = '';
            foreach ($routes as $r) {
                $methodEsc = htmlspecialchars($r['method'], ENT_QUOTES, 'UTF-8');
                $pathEsc = htmlspecialchars($r['path'], ENT_QUOTES, 'UTF-8');
                $rowsHtml .= "<div class=\"endpoint-row\"><span class=\"method-badge method-{$methodEsc}\">{$methodEsc}</span><span class=\"endpoint-path\">{$pathEsc}</span></div>\n";
            }
            $labelEsc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            $sectionsHtml .= "<div class=\"endpoint-group\"><h2>{$labelEsc}</h2>{$rowsHtml}</div>\n";
        }

        $body = <<<HTML
<h1>توثيق API</h1>
<p class="lead">كل نقاط الـ API الحالية في المنصة. أغلبها بيحتاج تسجيل دخول (Session) أو مفتاح API من صفحة الإعدادات → API.</p>
{$sectionsHtml}
HTML;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->pageShell('توثيق API', $body);
        exit;
    }

    /**
     * مواصفة OpenAPI 3.0 (JSON) مولّدة تلقائيًا من الـ routes الفعلية.
     * المرحلة 2 من خطة API Gateway - 2026-08-06. الهدف: أي أداة توليد
     * SDK (زي openapi-generator) تقدر تاخد الملف ده وتولّد مكتبة جاهزة
     * لـ Android/iOS/Flutter/React من غير ما حد يكتب كل endpoint يدوي.
     *
     * ملاحظة: بما إن الـ routes الحالية معندهاش تعريف request/response
     * schema صريح، المواصفة هنا بتوصف بس المسار والطريقة والـ tag
     * والمصادقة المطلوبة (مفيدة فعليًا لتوليد استدعاءات HTTP أساسية)،
     * مش body/response schema كاملة. ده يتوسع لاحقًا لكل endpoint على
     * حدة وقت ما يحتاج توثيق أدق.
     * GET /api/docs/openapi.json
     */
    public function openapi(array $params = []): array
    {
        $router = new Router();
        require dirname(__DIR__) . '/routes/api.php';

        // نفس قائمة المسارات العامة (public) المعرّفة في AuthMiddleware -
        // بنستخدمها هنا بس عشان نعرض "لا يحتاج مصادقة" صح في المواصفة،
        // مش لتنفيذ أي منطق حماية فعلي (ده شغل AuthMiddleware نفسه).
        $publicPrefixes = [
            '/api/auth/login', '/api/auth/register', '/api/auth/forgot-password',
            '/api/auth/reset-password', '/api/health', '/api/ping', '/api/docs',
            '/api/review/webhook', '/api/partner/',
        ];

        $paths = [];
        foreach ($router->getRoutes() as $method => $list) {
            foreach ($list as $route) {
                $path = $route['path'];
                $segment = preg_match('#^/api/([a-z0-9_-]+)#i', $path, $m) ? $m[1] : 'other';

                $isPublic = false;
                foreach ($publicPrefixes as $prefix) {
                    if (strpos($path, $prefix) === 0) {
                        $isPublic = true;
                        break;
                    }
                }

                $security = [];
                if (!$isPublic) {
                    $security = $segment === 'partner'
                        ? [['ApiKeyAuth' => []]]
                        : [['BearerAuth' => []], ['CookieAuth' => []]];
                }

                $parameters = [];
                if (preg_match_all('#\{([a-zA-Z0-9_]+)\}#', $path, $pm)) {
                    foreach ($pm[1] as $paramName) {
                        $parameters[] = [
                            'name' => $paramName,
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'string'],
                        ];
                    }
                }

                $paths[$path][strtolower($method)] = array_filter([
                    'summary' => strtoupper($method) . ' ' . $path,
                    'tags' => [$segment],
                    'parameters' => $parameters ?: null,
                    'security' => $security ?: null,
                    'responses' => [
                        '200' => ['description' => 'نجاح - راجع حقل success/data في الرد'],
                        '401' => ['description' => 'غير مصرح - توكن مفقود أو غير صالح'],
                        '422' => ['description' => 'بيانات الطلب غير صالحة'],
                    ],
                ]);
            }
        }
        ksort($paths);

        $spec = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => (defined('APP_NAME') ? APP_NAME : 'Tourfecto') . ' API',
                'version' => defined('APP_VERSION') ? APP_VERSION : '1.0.0',
                'description' => 'مواصفة مولّدة تلقائيًا من الـ routes الفعلية في المنصة.',
            ],
            'servers' => [
                ['url' => (defined('APP_URL') ? APP_URL : '') , 'description' => 'الخادم الأساسي (يدعم /api و /api/v1 alias)'],
            ],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT',
                        'description' => 'access_token المُرجع من POST /api/auth/login أو /api/auth/token/refresh',
                    ],
                    'CookieAuth' => [
                        'type' => 'apiKey',
                        'in' => 'cookie',
                        'name' => 'auth_token',
                        'description' => 'يُستخدم تلقائيًا من متصفح الويب بعد تسجيل الدخول',
                    ],
                    'ApiKeyAuth' => [
                        'type' => 'apiKey',
                        'in' => 'header',
                        'name' => 'X-API-Key',
                        'description' => 'مفتاح Partner API - يُصدر من لوحة الأدمن',
                    ],
                ],
            ],
        ];

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** GET /docs/guide */
    public function guide(array $params = []): array
    {
        $body = <<<'HTML'
<h1>دليل الاستخدام</h1>
<p class="lead">دليل سريع لكل ميزة رئيسية في تورفكتو.</p>

<h2>🎯 البداية</h2>
<ol>
    <li>سجّل حساب من <a href="/register">صفحة التسجيل</a>.</li>
    <li>أضف موقعك الأول من "مواقعي" في القائمة الجانبية.</li>
    <li>من "الربط والتكاملات" اربط أي حسابات خارجية عايز تستخدمها (Google Business، TripAdvisor، Search Console، واتساب).</li>
</ol>

<h2>🤖 تحليل الذكاء الاصطناعي (SEO/AEO/GEO)</h2>
<p>من "تحليل الذكاء الاصطناعي" حط رابط موقعك وروابط 3 منافسين، وهتاخد تقرير فيه كلمات مفتاحية مقترحة، فجوات محتوى، واقتراحات تحسين تظهرك في نتائج البحث ومحركات الإجابة الذكية (زي ChatGPT وGemini).</p>

<h2>✍️ توليد ونشر المقالات</h2>
<p>من "المقالات التسويقية" اكتب موضوع/كلمة مفتاحية وهيتولّدلك مقال SEO كامل جاهز. تقدر تنزّله أو تنسخه، أو تربط موقعك (ووردبريس أو أي موقع تاني ببرمجة خاصة عن طريق webhook بسيط) وتنشره بضغطة زرار.</p>

<h2>⭐ إدارة السمعة</h2>
<p>بعد ربط Google Business Profile و/أو TripAdvisor، هتقدر تشوف كل مراجعات عملائك في مكان واحد، وتولّد ردود بالذكاء الاصطناعي عليها، وتراجعها قبل النشر.</p>

<h2>💬 المحادثات (واتساب)</h2>
<p>اربط حساب UltraMsg بتاعك من "الربط والتكاملات". أي رسالة واتساب واردة، الذكاء الاصطناعي هيقترحلك رد، وتقدر تعدّله أو تولّد رد تاني قبل ما توافق عليه ويتبعت فعليًا (أو تفعّل "Auto Pilot" لإرسال تلقائي بدون مراجعة).</p>

<h2>💳 الاشتراك والفوترة</h2>
<p>من صفحة "الاشتراك" تقدر تشوف باقتك الحالية، تترقّى، أو تلغي. الفواتير والسجل موجودين في نفس الصفحة.</p>

<h2>⚙️ الإعدادات</h2>
<p>من "الإعدادات" تقدر تعدّل بياناتك الشخصية وصورتك، تغيّر كلمة المرور، تظبط تفضيلات الإشعارات، وتدير مفتاح الـ API بتاعك.</p>

<p class="lead" style="margin-top:36px;">لسه محتاج مساعدة؟ <a href="/help/contact" style="color: var(--teal);">تواصل معنا ←</a></p>
HTML;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->pageShell('دليل الاستخدام', $body);
        exit;
    }
}
