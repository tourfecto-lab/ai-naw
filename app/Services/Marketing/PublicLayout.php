<?php

/**
 * Tourfecto - القالب المشترك للموقع العام (هيدر + فوتر + SEO)
 *
 * كل الصفحات العامة الجديدة (المميزات، الأسعار، من نحن، تواصل، المدونة...) بتتعرض جوه القالب ده
 * عشان الهيدر والفوتر يبقوا واحد في كل الموقع. نفس هوية compass.css (الوضع الفاتح/الداكن).
 */
class PublicLayout
{
    public static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    public static function baseUrl(): string
    {
        $u = defined('APP_URL') ? (string) APP_URL : '';
        if ($u === '' || strpos($u, 'yourdomain') !== false || strpos($u, 'localhost') !== false || strpos($u, '127.0.0.1') !== false) {
            $u = 'https://tourfecto.pro';
        }
        return rtrim($u, '/');
    }

    /**
     * @param array $o title, description, body, path, active, head (extra), jsonld (array), noindex, ogType, ogImage
     */
    public static function render(array $o): string
    {
        $C = 'MarketingContent';
        $lang = $C::lang();
        $isAr = $lang === 'ar';
        $dir = $isAr ? 'rtl' : 'ltr';
        $e = fn ($v) => self::e($v);
        $site = function_exists('site_name') ? site_name() : 'Tourfecto';
        $title = trim((string) ($o['title'] ?? ''));
        $fullTitle = $title !== '' ? $title . ' | ' . $site : $site;
        $desc = (string) ($o['description'] ?? '');
        $path = (string) ($o['path'] ?? strtok($_SERVER['REQUEST_URI'] ?? '/', '?'));
        $base = self::baseUrl();
        $canonical = $base . $path . ($isAr ? '?lang=ar' : '?lang=en');
        $ogImage = (string) ($o['ogImage'] ?? $base . '/assets/brand/og-image.png');
        $active = (string) ($o['active'] ?? '');
        $brand = function_exists('site_brand_html') ? site_brand_html(false) : $e($site);
        $favicon = function_exists('site_favicon_html') ? site_favicon_html() : '';

        // روابط الهيدر
        $links = '';
        $mobile = '';
        foreach ($C::nav() as [$href, $label, $key]) {
            $on = $active === $key ? ' aria-current="page" class="on"' : '';
            if ($key === 'features') {
                $links .= self::featuresMenu($active === 'features');
            } else {
                $links .= '<a href="' . $href . '"' . $on . '>' . $e($C::t($label)) . '</a>';
            }
            $mobile .= '<a href="' . $href . '">' . $e($C::t($label)) . '</a>';
        }
        $otherLang = $isAr ? 'en' : 'ar';
        $langLink = '<a class="cta-ghost lang-switch" href="' . $e($path . '?lang=' . $otherLang) . '" hreflang="' . $otherLang . '" lang="' . $otherLang . '">' . ($isAr ? 'English' : 'العربية') . '</a>';
        $loggedIn = !empty($_SESSION['user']);
        $acct = $loggedIn
            ? '<a href="/dashboard" class="btn-primary sm">' . $e($C::t(['لوحة التحكم', 'Dashboard'])) . '</a>'
            : '<a href="/login" class="nav-login">' . $e($C::t(['دخول', 'Log in'])) . '</a><a href="/register" class="btn-primary sm">' . $e($C::t(['ابدأ مجانًا', 'Start free'])) . '</a>';
        $mobileAcct = $loggedIn
            ? '<a href="/dashboard">' . $e($C::t(['لوحة التحكم', 'Dashboard'])) . '</a>'
            : '<a href="/login">' . $e($C::t(['دخول', 'Log in'])) . '</a><a href="/register">' . $e($C::t(['ابدأ مجانًا', 'Start free'])) . '</a>';

        $jsonld = '';
        $ld = $o['jsonld'] ?? [];
        $ld[] = self::organizationLd($site, $base);
        foreach ($ld as $block) {
            $jsonld .= '<script type="application/ld+json">' . json_encode($block, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>';
        }
        $robots = !empty($o['noindex']) ? '<meta name="robots" content="noindex">' : '';
        $year = date('Y');
        $footer = self::footer();
        $body = (string) ($o['body'] ?? '');
        // Controller-generated local URL only, never a user-supplied redirect.
        $serviceRegisterUrl = $o['serviceRegisterUrl'] ?? '';
        if (is_string($serviceRegisterUrl) && str_starts_with($serviceRegisterUrl, '/register?feature=')) {
            $replacement = 'href="' . $e($serviceRegisterUrl) . '"';
            $acct = str_replace('href="/register"', $replacement, $acct);
            $mobileAcct = str_replace('href="/register"', $replacement, $mobileAcct);
            $footer = str_replace('href="/register"', $replacement, $footer);
        }

        $head = (string) ($o['head'] ?? '');
        $ogType = $e($o['ogType'] ?? 'website');
        $menuLabel = $e($C::t(['القائمة', 'Menu']));
        $isAr_label = $isAr ? 'English' : 'العربية';
        $skip = $e($C::t(['تخطى للمحتوى', 'Skip to content']));
        $themeJs = function_exists('asset_v') ? asset_v('/assets/js/tf-theme.js') : '/assets/js/tf-theme.js';
        $curJs = function_exists('asset_v') ? asset_v('/assets/js/tf-currency.js') : '/assets/js/tf-currency.js';
        $siteCss = function_exists('asset_v') ? asset_v('/assets/css/site.css') : '/assets/css/site.css';

        return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}" dir="{$dir}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$e($fullTitle)}</title>
<meta name="description" content="{$e($desc)}">
{$robots}
<link rel="canonical" href="{$e($canonical)}">
<link rel="alternate" hreflang="ar" href="{$e($base . $path . '?lang=ar')}">
<link rel="alternate" hreflang="en" href="{$e($base . $path . '?lang=en')}">
<link rel="alternate" hreflang="x-default" href="{$e($base . $path)}">
<meta property="og:type" content="{$ogType}">
<meta property="og:title" content="{$e($fullTitle)}">
<meta property="og:description" content="{$e($desc)}">
<meta property="og:url" content="{$e($canonical)}">
<meta property="og:image" content="{$e($ogImage)}">
<meta property="og:locale" content="{$e($isAr ? 'ar_EG' : 'en_US')}">
<meta name="twitter:card" content="summary_large_image">

{$favicon}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<script src="{$e($curJs)}" defer></script>
<script src="{$e($themeJs)}"></script>
<link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/compass.css">
<link rel="stylesheet" href="{$e($siteCss)}">
{$jsonld}
{$head}
</head>
<body class="compass site">
<script>document.documentElement.classList.add('js-ready')</script>
<a class="skip-link" href="#main">{$skip}</a>
<div class="wrap">
<nav class="topnav" id="mainNav">
  <a href="/" class="brand">{$brand}</a>
  <div class="navlinks">{$links}</div>
  <div class="nav-right">
    {$langLink}
    {$acct}
    <button type="button" class="mobile-menu-btn" id="mobileMenuBtn" aria-label="{$menuLabel}" aria-expanded="false" aria-controls="mobileMenu"><span></span></button>
  </div>
</nav>
<div class="mobile-menu" id="mobileMenu">{$mobile}<a href="{$e($path . '?lang=' . $otherLang)}">{$isAr_label}</a>{$mobileAcct}</div>
<main id="main">
{$body}
</main>
{$footer}
<div class="footer-bottom-line"><span>&copy; {$year} {$e($site)}</span></div>
</div>
<script>
(function(){
  var nav=document.getElementById('mainNav');
  window.addEventListener('scroll',function(){nav.classList.toggle('scrolled',window.scrollY>20);},{passive:true});
  var b=document.getElementById('mobileMenuBtn'),m=document.getElementById('mobileMenu');
  b.addEventListener('click',function(){var o=m.classList.toggle('open');b.classList.toggle('open',o);b.setAttribute('aria-expanded',o?'true':'false');});
  document.addEventListener('click',function(e){document.querySelectorAll('details.navdrop[open]').forEach(function(d){if(!d.contains(e.target))d.removeAttribute('open');});});
  document.addEventListener('keydown',function(e){if(e.key==='Escape')document.querySelectorAll('details.navdrop[open]').forEach(function(d){d.removeAttribute('open');});});
  if('IntersectionObserver' in window){var io=new IntersectionObserver(function(es){es.forEach(function(x){if(x.isIntersecting){x.target.classList.add('in');io.unobserve(x.target);}});},{threshold:.12});
  document.querySelectorAll('.reveal').forEach(function(el){io.observe(el);});}else{document.querySelectorAll('.reveal').forEach(function(el){el.classList.add('in');});}
})();
</script>
</body>
</html>
HTML;
    }

    /** قائمة المميزات المنسدلة في الهيدر */
    private static function featuresMenu(bool $on): string
    {
        $C = 'MarketingContent';
        $items = '';
        foreach ($C::features() as $slug => $f) {
            $items .= '<a href="/features/' . $slug . '" class="nd-item"><span class="fi tone-' . $f['tone'] . '">' . (function_exists('icon_svg') ? icon_svg($f['icon'], 18) : '') . '</span>'
                . '<span><b>' . self::e($C::t($f['title'])) . '</b><small>' . self::e($C::t($f['short'])) . '</small></span></a>';
        }
        return '<details class="navdrop' . ($on ? ' on' : '') . '"><summary>' . self::e($C::t(['المميزات', 'Features'])) . '<i aria-hidden="true"></i></summary>'
            . '<div class="nd-panel"><div class="nd-grid">' . $items . '</div>'
            . '<a class="nd-all" href="/features">' . self::e($C::t(['كل المميزات ←', 'All features →'])) . '</a></div></details>';
    }

    /**
     * بيانات تواصل الشركة من إعدادات الأدمن (الهوية): الإيميل والتليفون والواتساب والعنوان.
     * الإيميل الافتراضي info@tourfecto.pro (بريد المنصة)؛ الباقي بيظهر بس لو متسجل.
     */
    public static function company(): array
    {
        static $c = null;
        if ($c !== null) {
            return $c;
        }
        $get = static function (string $k): string {
            try {
                return class_exists('SystemSettingsService') ? trim((string) (new SystemSettingsService())->get($k, '')) : '';
            } catch (Throwable $e) {
                return '';
            }
        };
        $wa = function_exists('support_whatsapp_number') ? preg_replace('/\D/', '', (string) support_whatsapp_number()) : '';
        return $c = [
            'email' => $get('contact_email') ?: 'info@tourfecto.pro',
            'phone' => $get('contact_phone'),
            'whatsapp' => $wa,
            'address' => $get('site_address'),
        ];
    }

    /** بيانات الشركة اللي جوجل ومحركات الذكاء الاصطناعي بيقروها (Organization) */
    private static function organizationLd(string $site, string $base): array
    {
        $co = self::company();
        $org = [
            '@context' => 'https://schema.org', '@type' => 'Organization', '@id' => $base . '/#organization',
            'name' => $site, 'url' => $base, 'logo' => $base . '/assets/icons/icon-512.png', 'image' => $base . '/assets/brand/og-image.png',
            'description' => 'Tourfecto is an AI platform for tour operators, travel agencies and hotels: review replies in every traveller’s language, an SEO hub that audits tour pages, a booking website with online payments, 24/7 AI chat on the website and WhatsApp, marketing, competitor monitoring and CRM.',
            'email' => $co['email'],
            'knowsLanguage' => ['ar', 'en'],
            'areaServed' => 'Worldwide',
            'contactPoint' => [array_filter(['@type' => 'ContactPoint', 'contactType' => 'customer support', 'email' => $co['email'], 'telephone' => $co['phone'] ?: ($co['whatsapp'] ? '+' . $co['whatsapp'] : null), 'availableLanguage' => ['Arabic', 'English'], 'url' => $base . '/contact'])],
        ];
        if ($co['phone'] !== '') $org['telephone'] = $co['phone'];
        if ($co['address'] !== '') $org['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $co['address']];
        return $org;
    }

    public static function footer(): string
    {
        $C = 'MarketingContent';
        $e = fn ($v) => self::e($v);
        $t = fn ($p) => $e($C::t($p));
        $brand = function_exists('site_brand_html') ? site_brand_html(false) : 'Tourfecto';
        $feat = '';
        foreach (array_slice($C::features(), 0, 6, true) as $slug => $f) {
            $feat .= '<a href="/features/' . $slug . '">' . $e($C::t($f['title'])) . '</a>';
        }
        $wa = function_exists('support_whatsapp_number') ? support_whatsapp_number() : '';
        $waLink = $wa ? '<a href="https://wa.me/' . $e($wa) . '" target="_blank" rel="noopener">' . $t(['واتساب', 'WhatsApp']) . '</a>' : '';
        return '<footer class="site-footer-full"><div class="footer-cols five">'
            . '<div class="footer-col"><div class="footer-brand">' . $brand . '</div><p>' . $t(['منصة واحدة لشركات الرحلات والسياحة: الحجوزات، العملاء، التسويق، والسمعة - بالذكاء الاصطناعي.', 'One platform for tour and travel companies: bookings, customers, marketing and reputation - powered by AI.']) . '</p>'
            . self::contactLines()
            . '<a href="/register" class="btn-primary sm footer-cta">' . $t(['ابدأ مجانًا', 'Start free']) . '</a></div>'
            . '<div class="footer-col"><h4>' . $t(['المميزات', 'Features']) . '</h4>' . $feat . '<a href="/features">' . $t(['كل المميزات', 'All features']) . '</a></div>'
            . '<div class="footer-col"><h4>' . $t(['الشركة', 'Company']) . '</h4><a href="/about">' . $t(['من نحن', 'About']) . '</a><a href="/pricing">' . $t(['الأسعار', 'Pricing']) . '</a><a href="/blog">' . $t(['المدونة', 'Blog']) . '</a><a href="/changelog">' . $t(['إيه الجديد', 'What’s new']) . '</a><a href="/status">' . $t(['حالة الخدمة', 'Status']) . '</a></div>'
            . '<div class="footer-col"><h4>' . $t(['الدعم', 'Support']) . '</h4><a href="/contact">' . $t(['تواصل معنا', 'Contact us']) . '</a><a href="/demo">' . $t(['اطلب عرض توضيحي', 'Book a demo']) . '</a><a href="/help">' . $t(['مركز المساعدة', 'Help center']) . '</a><a href="/docs">' . $t(['التوثيق', 'Docs']) . '</a>' . $waLink . '</div>'
            . '<div class="footer-col"><h4>' . $t(['قانوني', 'Legal']) . '</h4><a href="/terms">' . $t(['شروط الاستخدام', 'Terms']) . '</a><a href="/privacy">' . $t(['سياسة الخصوصية', 'Privacy']) . '</a><a href="/refund-policy">' . $t(['سياسة الاسترجاع', 'Refund policy']) . '</a><a href="/cookies">' . $t(['الكوكيز', 'Cookies']) . '</a></div>'
            . '</div></footer>';
    }

    /** سطور التواصل (إيميل، تليفون، واتساب، عنوان) - في الفوتر وفي الرئيسية */
    public static function contactLines(): string
    {
        $C = 'MarketingContent';
        $co = self::company();
        $e = fn ($v) => self::e($v);
        $row = static fn (string $icon, string $html) => '<div style="display:flex;gap:8px;align-items:flex-start;margin:6px 0;font-size:14px;line-height:1.6"><span aria-hidden="true">' . $icon . '</span><span>' . $html . '</span></div>';
        $out = $row('✉️', '<a href="mailto:' . $e($co['email']) . '" dir="ltr">' . $e($co['email']) . '</a>');
        if ($co['phone'] !== '') $out .= $row('📞', '<a href="tel:' . $e(preg_replace('/[^\d+]/', '', $co['phone'])) . '" dir="ltr">' . $e($co['phone']) . '</a>');
        if ($co['whatsapp'] !== '') $out .= $row('💬', '<a href="https://wa.me/' . $e($co['whatsapp']) . '" target="_blank" rel="noopener" dir="ltr">+' . $e($co['whatsapp']) . '</a> · ' . $e($C::t(['واتساب', 'WhatsApp'])));
        if ($co['address'] !== '') $out .= $row('📍', $e($co['address']));
        return '<div class="footer-contact" style="margin:10px 0 14px">' . $out . '</div>';
    }

    // ------------------------------------------------------------------ أجزاء مشتركة للصفحات

    public static function hero(string $eyebrow, string $title, string $sub, string $ctas = '', string $extraClass = ''): string
    {
        return '<header class="pg-hero ' . $extraClass . '">' . ($eyebrow !== '' ? '<div class="eyebrow"><span class="p"></span>' . self::e($eyebrow) . '</div>' : '')
            . '<h1>' . self::e($title) . '</h1>' . ($sub !== '' ? '<p class="sub">' . self::e($sub) . '</p>' : '') . ($ctas !== '' ? '<div class="hero-ctas">' . $ctas . '</div>' : '') . '</header>';
    }

    public static function ctaBand(): string
    {
        $C = 'MarketingContent';
        return '<section class="cta-band"><div class="final-cta-box reveal"><h2>' . self::e($C::t(['جاهز تنظّم شغلك؟', 'Ready to run things smoother?'])) . '</h2>'
            . '<p>' . self::e($C::t(['ابدأ مجانًا في دقايق، أو احجز عرض توضيحي ونوريك المنصة على شغلك انت.', 'Start free in minutes, or book a demo and we’ll walk you through it with your own business.'])) . '</p>'
            . '<div class="hero-ctas" style="justify-content:center"><a href="/register" class="btn-primary">' . self::e($C::t(['ابدأ مجانًا', 'Start free'])) . '</a>'
            . '<a href="/demo" class="cta-ghost">' . self::e($C::t(['اطلب عرض توضيحي', 'Book a demo'])) . '</a></div></div></section>';
    }

    public static function breadcrumb(array $items): array
    {
        $base = self::baseUrl();
        $list = [];
        foreach ($items as $i => [$name, $url]) {
            $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => $base . $url];
        }
        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];
    }
}
