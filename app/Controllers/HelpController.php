<?php

/**
 * Tourfecto - Help Controller
 * صفحات المساعدة والأسئلة الشائعة والتواصل
 * @version 2.0.0
 *
 * ملاحظة: قبل كده كانت الدوال دي بترجّع JSON خام بدل صفحات حقيقية
 * (يعني أي عميل يدوس "مساعدة" أو "تواصل معنا" كان يشوف نص برمجي بدل
 * صفحة). اتبنت من الصفر كصفحات HTML حقيقية بنفس هوية الصفحات العامة
 * (compass.css) زي /terms و/privacy بالظبط.
 */
class HelpController extends Controller
{
    private function pageShell(string $title, string $bodyHtml, string $extraCss = ''): string
    {
        $appName = defined('APP_NAME') ? APP_NAME : 'Tourfecto';
        $brandHtml = site_brand_html();
        $lang = function_exists('current_lang') ? current_lang() : 'ar';
        $dir = function_exists('current_dir') ? current_dir() : 'rtl';
        $year = date('Y');

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
    <script src="/assets/js/tf-theme.js?v=20261016"></script>
    <link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@300;400;500;600;700&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/compass.css">
    <style>
        .help-wrap { max-width: 820px; margin: 0 auto; padding: 60px 8vw 100px; }
        .help-wrap h1 { font-family: 'Fraunces', serif; font-size: 32px; margin-bottom: 10px; }
        .help-wrap .lead { color: #C9D2E0; font-size: 15px; margin-bottom: 40px; }
        .help-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 20px; }
        @media (max-width: 640px) { .help-cards { grid-template-columns: 1fr; } }
        .help-card {
            display: block; padding: 22px; border-radius: 16px; text-decoration: none;
            background: linear-gradient(160deg, rgba(255,255,255,.04), rgba(255,255,255,.01));
            border: 1px solid rgba(255,255,255,.09); transition: .2s;
        }
        .help-card:hover { border-color: var(--gold, #EFB05E); transform: translateY(-2px); }
        .help-card .hi { font-size: 26px; margin-bottom: 10px; }
        .help-card .ht { font-family: 'Fraunces', serif; font-size: 17px; color: #fff; margin-bottom: 6px; }
        .help-card .hd { color: #9AA6BE; font-size: 13px; line-height: 1.7; }
        .faq-item { border-bottom: 1px solid rgba(255,255,255,.09); padding: 18px 0; }
        .faq-item:last-child { border-bottom: none; }
        .faq-q { font-weight: 700; color: #fff; font-size: 15px; margin-bottom: 8px; }
        .faq-a { color: #C9D2E0; font-size: 14px; line-height: 1.8; }
        .form-field { margin-bottom: 16px; }
        .form-field label { display: block; font-size: 13px; font-weight: 700; color: #C9D2E0; margin-bottom: 6px; }
        .form-field input, .form-field textarea {
            width: 100%; padding: 12px 14px; border-radius: 10px; border: 1px solid rgba(255,255,255,.14);
            background: rgba(255,255,255,.04); color: #fff; font-family: inherit; font-size: 14px;
        }
        .form-field textarea { min-height: 130px; resize: vertical; }
        .form-field input:focus, .form-field textarea:focus { outline: none; border-color: var(--gold, #EFB05E); }
        .help-btn {
            display: inline-flex; align-items: center; gap: 8px; padding: 12px 26px; border-radius: 30px;
            background: var(--gold, #EFB05E); color: #14100a; font-weight: 700; border: none; cursor: pointer; font-size: 14px;
        }
        .help-btn:disabled { opacity: .6; cursor: not-allowed; }
        .help-alert { padding: 12px 16px; border-radius: 10px; font-size: 13.5px; margin-bottom: 16px; display: none; }
        .help-alert.success { background: rgba(78,205,196,.12); color: #4ECDC4; display: block; }
        .help-alert.error { background: rgba(255,107,91,.12); color: #FF6B5B; display: block; }
        .whatsapp-cta {
            display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 30px;
            background: rgba(37,211,102,.12); color: #25D366; text-decoration: none; font-weight: 700; font-size: 13.5px; margin-top: 6px;
        }
        {$extraCss}
    </style>
</head>
<body class="compass">
<div class="stars"></div>
<div class="wrap">
    <nav class="topnav">
        <a href="/" class="brand">{$brandHtml}</a>
        <div class="nav-right"><a href="/" class="cta-ghost">← الرجوع للرئيسية</a></div>
    </nav>

    <div class="help-wrap">
        {$bodyHtml}
    </div>

    <footer class="site-footer">
        <div>&copy; {$year} {$appName}. جميع الحقوق محفوظة.</div>
        <div><a href="/terms">الشروط</a><a href="/privacy">الخصوصية</a><a href="/help/contact">تواصل معنا</a></div>
    </footer>
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

    /** GET /help */
    public function index(array $params = []): array
    {
        $C = 'MarketingContent';
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $t = fn ($p) => $e($C::t($p));
        $ic = fn ($n) => function_exists('icon_svg') ? icon_svg($n, 18) : '';
        $wa = support_whatsapp_number();
        $cards = [
            ['/help/faq', 'book', 'amber', ['الأسئلة الشائعة', 'FAQ'], ['إجابات سريعة عن الحجوزات، الشات، التقييمات، والاشتراكات.', 'Quick answers about bookings, chat, reviews and subscriptions.']],
            ['/contact', 'mail', 'accent', ['تواصل معنا', 'Contact us'], ['ابعتلنا رسالة وهنرد عليك في أقرب وقت.', 'Send us a message and we’ll reply shortly.']],
            ['/docs', 'file-text', 'violet', ['التوثيق', 'Documentation'], ['شرح تفصيلي للمنصة والـ API.', 'Detailed guides and the API reference.']],
            ['/demo', 'monitor', 'teal', ['اطلب عرض توضيحي', 'Book a demo'], ['نوريك المنصة على شغلك في مكالمة قصيرة.', 'We’ll walk you through it on your own business.']],
        ];
        $html = '';
        foreach ($cards as [$href, $icon, $tone, $title, $desc]) {
            $html .= '<a class="side-card" href="' . $href . '"><span class="fi tone-' . $tone . '">' . $ic($icon) . '</span><div><b>' . $t($title) . '</b><span>' . $t($desc) . '</span></div></a>';
        }
        if ($wa) {
            $html .= '<a class="side-card" href="https://wa.me/' . $e($wa) . '" target="_blank" rel="noopener"><span class="fi tone-teal">' . $ic('message') . '</span><div><b>' . $t(['واتساب', 'WhatsApp']) . '</b><span>' . $t(['أسرع طريقة توصلنا.', 'The fastest way to reach us.']) . '</span></div></a>';
        }
        $body = PublicLayout::hero($C::t(['المساعدة', 'Help']), $C::t(['مركز المساعدة', 'Help center']), $C::t(['عايز حاجة؟ هتلاقيها هنا.', 'Need something? You’ll find it here.']))
            . '<section class="blk tight" style="padding-top:0"><div class="container-narrow" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px">' . $html . '</div></section>';
        header('Content-Type: text/html; charset=utf-8');
        echo PublicLayout::render(['title' => $C::t(['مركز المساعدة', 'Help center']), 'description' => $C::t(['مساعدة واستفسارات منصة Tourfecto.', 'Tourfecto help and support.']), 'path' => '/help', 'body' => $body]);
        exit;
    }

    /** GET /help/faq */
    public function faq(array $params = []): array
    {
        // تصحيح: الأسئلة الشائعة بقت قابلة للتعديل من لوحة الأدمن
        // (جدول faq_items) بدل ما تكون مكتوبة في كود PHP ثابت. لو الجدول
        // لسه فاضي (الـ migration ما اتشغّلش)، بنرجع لنفس الأسئلة
        // الافتراضية القديمة عشان الصفحة تفضل شغالة.
        try {
            $items = (new FaqItem())->where(['is_active' => 1], ['sort_order' => 'ASC']);
            $faqs = array_map(fn ($f) => [$f->getAttribute('question'), $f->getAttribute('answer')], $items);
        } catch (Exception $e) {
            $faqs = [];
        }
        // الـ migration اتشغّل أكتر من مرة فكل سؤال اتسجل كذا نسخة؛ السؤال المكرر بيضر السيو (FAQPage Schema متكرر)
        $unique = [];
        foreach ($faqs as $faq) {
            $unique[mb_strtolower(trim((string) $faq[0]))] ??= $faq;
        }
        $faqs = array_values($unique);

        if (empty($faqs)) {
            $faqs = [
                ['ما هو Tourfecto بالضبط؟', 'منصة تسويق وإدارة سمعة بالذكاء الاصطناعي مخصصة للشركات السياحية والفندقية - تحليل SEO/AEO/GEO، توليد مقالات جاهزة للنشر، الرد على مراجعات العملاء، ومتابعة رسائل واتساب والموقع من مكان واحد.'],
                ['إزاي أبدأ تحليل موقعي؟', 'من "تحليل الذكاء الاصطناعي" في القائمة الجانبية، حط رابط موقعك وروابط 3 منافسين، وهتاخد تقرير كامل يشمل كلمات مفتاحية، فجوات محتوى، واقتراحات تحسين.'],
                ['المقالات اللي بتتولد ممكن أنشرها فين؟', 'تقدر تنزّلها (Markdown) أو تنسخها وتنشرها يدوي، أو تربط موقعك (ووردبريس أو أي موقع تاني ببرمجة خاصة) من زرار "نشر" في صفحة أي مقال، وهتتنشر مباشرة.'],
                ['إزاي أربط Google Business Profile أو TripAdvisor؟', 'من صفحة "الربط والتكاملات" في القائمة الجانبية، اختار الموقع، ودوس "ربط" جنب المنصة اللي عايزها.'],
                ['نسيت باسورد حسابي، أعمل إيه؟', 'من صفحة تسجيل الدخول دوس "نسيت كلمة المرور؟" وهنبعتلك رابط لإعادة التعيين على إيميلك.'],
                ['إزاي أغيّر أو ألغي اشتراكي؟', 'من صفحة "الاشتراك" هتلاقي تفاصيل باقتك الحالية وخيارات الترقية أو الإلغاء.'],
                ['بياناتي وبيانات عملائي آمنة؟', 'كل بيانات الاعتماد الحساسة (زي مفاتيح المنصات المرتبطة) بتتشفّر في قاعدة البيانات. تقدر تقرأ التفاصيل كاملة في صفحة الخصوصية.'],
            ];
        }

        $C = 'MarketingContent';
        $items = '';
        $ld = [];
        foreach ($faqs as [$q, $a]) {
            $items .= '<details class="faq-item"><summary>' . htmlspecialchars($q, ENT_QUOTES, 'UTF-8') . '</summary><p>' . nl2br(htmlspecialchars($a, ENT_QUOTES, 'UTF-8')) . '</p></details>';
            $ld[] = ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]];
        }
        $body = PublicLayout::hero($C::t(['المساعدة', 'Help']), $C::t(['الأسئلة الشائعة', 'Frequently asked questions']), $C::t(['مش لاقي إجابة سؤالك؟ تواصل معانا من صفحة التواصل.', 'Can’t find your answer? Reach us from the contact page.']), '<a href="/contact" class="cta-ghost">' . htmlspecialchars($C::t(['تواصل معنا', 'Contact us']), ENT_QUOTES, 'UTF-8') . '</a>')
            . '<section class="blk tight" style="padding-top:0"><div class="faq-list">' . $items . '</div></section>';
        header('Content-Type: text/html; charset=utf-8');
        echo PublicLayout::render(['title' => $C::t(['الأسئلة الشائعة', 'FAQ']), 'description' => $C::t(['إجابات أسئلة منصة Tourfecto.', 'Answers to common Tourfecto questions.']), 'path' => '/help/faq', 'body' => $body,
            'jsonld' => [['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $ld]]]);
        exit;
    }

    /** GET /help/contact */
    public function contact(array $params = []): array
    {
        // صفحة التواصل الجديدة (نفس الفورم + طلب العرض) - /help/contact بيحوّل ليها
        if (class_exists('MarketingController')) {
            header('Location: /contact', true, 301);
            exit;
        }
        $whatsappBlock = '';
        $supportWhatsapp = support_whatsapp_number();
        if ($supportWhatsapp) {
            $number = htmlspecialchars($supportWhatsapp, ENT_QUOTES, 'UTF-8');
            $whatsappBlock = <<<HTML
            <p class="lead" style="margin-top:24px;margin-bottom:8px;">أو لو عايز رد أسرع:</p>
            <a href="https://wa.me/{$number}" target="_blank" rel="noopener" class="whatsapp-cta">💬 راسلنا على واتساب</a>
HTML;
        }

        $body = <<<HTML
<h1>تواصل معنا</h1>
<p class="lead">اكتب رسالتك وهنرد عليك على الإيميل اللي تحطه.</p>

<div id="contactAlert" class="help-alert"></div>

<form id="contactForm">
    <div class="form-field">
        <label for="name">الاسم</label>
        <input type="text" id="name" name="name" required>
    </div>
    <div class="form-field">
        <label for="email">البريد الإلكتروني</label>
        <input type="email" id="email" name="email" required>
    </div>
    <div class="form-field">
        <label for="message">رسالتك</label>
        <textarea id="message" name="message" required></textarea>
    </div>
    <button type="submit" class="help-btn" id="contactSubmitBtn">إرسال الرسالة</button>
</form>
{$whatsappBlock}

<script>
document.getElementById('contactForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const alertBox = document.getElementById('contactAlert');
    const btn = document.getElementById('contactSubmitBtn');
    alertBox.className = 'help-alert';
    alertBox.style.display = 'none';
    btn.disabled = true;
    const original = btn.textContent;
    btn.textContent = 'جارِ الإرسال...';

    try {
        const res = await fetch('/help/contact', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                name: document.getElementById('name').value.trim(),
                email: document.getElementById('email').value.trim(),
                message: document.getElementById('message').value.trim(),
            }),
        });
        const data = await res.json();

        if (data.success) {
            alertBox.className = 'help-alert success';
            alertBox.textContent = 'تم استلام رسالتك، هنتواصل معاك قريبًا ✔';
            document.getElementById('contactForm').reset();
        } else {
            alertBox.className = 'help-alert error';
            alertBox.textContent = data.error || 'تعذر إرسال الرسالة';
        }
    } catch (err) {
        alertBox.className = 'help-alert error';
        alertBox.textContent = 'تعذر الاتصال بالخادم';
    } finally {
        btn.disabled = false;
        btn.textContent = original;
    }
});
</script>
HTML;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->pageShell('تواصل معنا', $body);
        exit;
    }

    /** POST /help/contact */
    public function sendContact(array $params = []): array
    {
        if (!$this->validate([
            'name' => 'required',
            'email' => 'required|email',
            'message' => 'required',
        ])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        try {
            $submission = new ContactSubmission([
                'name' => (string) $this->get('name'),
                'email' => (string) $this->get('email'),
                'message' => (string) $this->get('message'),
                'user_id' => $_SESSION['user_id'] ?? null,
                'status' => 'new',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
            $submission->save();
        } catch (Exception $e) {
            Logger::error('Contact Submission DB Error', ['message' => $e->getMessage()]);
            // نكمل عادي حتى لو فشل الحفظ في قاعدة البيانات - على الأقل يتسجل في اللوج
        }

        Logger::info('Contact Form Submission', [
            'name' => $this->get('name'),
            'email' => $this->get('email'),
            'message' => $this->get('message'),
        ]);

        return $this->success([], 'تم استلام رسالتك وسنتواصل معك قريبًا');
    }
}
