<?php

/**
 * Tourfecto - Home Controller
 * الصفحة الرئيسية للموقع
 * @version 4.0.0 - Compass design system (نظام "البوصلة" المعتمد):
 * كرة أرضية 3D حقيقية بتتبع الماوس، مسارات طيران متحركة، كروت خدمات
 * بميل 3D، وقائمة كاملة لكل أدوات المنصة. الإنجليزي هو اللغة الأساسية،
 * الدولار هو العملة الأساسية.
 */

class HomeController extends Controller
{
    /** GET / */
    /** GET /sitemap.xml - خريطة موقع ديناميكية حقيقية تشمل كل الصفحات العامة */
    public function sitemap(array $params = []): array
    {
        $baseUrl = 'https://tourfecto.pro';
        $today = date('Y-m-d');

        $staticPages = [
            ['url' => '/', 'priority' => '1.0'],
            ['url' => '/pricing', 'priority' => '0.9'],
            ['url' => '/features', 'priority' => '0.9'],
            ['url' => '/about', 'priority' => '0.6'],
            ['url' => '/contact', 'priority' => '0.6'],
            ['url' => '/demo', 'priority' => '0.7'],
            ['url' => '/blog', 'priority' => '0.7'],
            ['url' => '/refund-policy', 'priority' => '0.3'],
            ['url' => '/register', 'priority' => '0.8'],
            ['url' => '/help', 'priority' => '0.6'],
            ['url' => '/help/faq', 'priority' => '0.6'],
            ['url' => '/help/contact', 'priority' => '0.5'],
            ['url' => '/docs', 'priority' => '0.5'],
            ['url' => '/docs/api', 'priority' => '0.4'],
            ['url' => '/docs/guide', 'priority' => '0.4'],
            ['url' => '/terms', 'priority' => '0.3'],
            ['url' => '/privacy', 'priority' => '0.3'],
        ];

        $urlsXml = '';
        foreach ($staticPages as $page) {
            $urlsXml .= "    <url>\n        <loc>{$baseUrl}{$page['url']}</loc>\n        <lastmod>{$today}</lastmod>\n        <priority>{$page['priority']}</priority>\n    </url>\n";
        }

        // صفحات المميزات (بدل /services القديمة اللي بقت بتحوّل بـ 301)
        if (class_exists('MarketingContent')) {
            foreach (array_keys(MarketingContent::features()) as $slug) {
                $urlsXml .= "    <url>\n        <loc>{$baseUrl}/features/{$slug}</loc>\n        <lastmod>{$today}</lastmod>\n        <priority>0.8</priority>\n    </url>\n";
            }
        }
        // مقالات المدونة المنشورة
        try {
            foreach (Database::getInstance()->query("SELECT DISTINCT slug, MAX(updated_at) u FROM blog_posts WHERE status = 'published' AND published_at <= NOW() GROUP BY slug ORDER BY u DESC LIMIT 1000") as $p) {
                $slug = htmlspecialchars((string) $p['slug'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $mod = date('Y-m-d', strtotime((string) $p['u']));
                $urlsXml .= "    <url>\n        <loc>{$baseUrl}/blog/{$slug}</loc>\n        <lastmod>{$mod}</lastmod>\n        <priority>0.6</priority>\n    </url>\n";
            }
        } catch (Throwable $e) {
            // جدول المدونة لسه ماتعملش
        }

        header('Content-Type: application/xml; charset=utf-8');
        echo <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
{$urlsXml}</urlset>
XML;
        exit;
    }

    public function index(array $params = []): array
    {
        // الصفحة الرئيسية الجديدة (نفس هيدر وفوتر باقي الموقع العام). القديمة متسابة تحت كمرجع.
        if (class_exists('MarketingController')) {
            return (new MarketingController())->home($params);
        }
        $appName = defined('APP_NAME') ? APP_NAME : 'Tourfecto';

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderHomePage($appName);
        exit;
    }

    private function renderHomePage(string $appName): string
    {
        $year = date('Y');
        $lang = current_lang();
        $dir = current_dir();

        $langMenu = '';
        foreach (language_switcher_links() as $l) {
            $activeClass = $l['active'] ? ' on' : '';
            $langMenu .= '<a href="' . htmlspecialchars($l['url'], ENT_QUOTES, 'UTF-8') . "\" class=\"{$activeClass}\">{$l['label']}</a>";
        }

        // كل خدمات المنصة الكاملة (شرائح مدمجة) - بنعيد استخدام مفاتيح
        // الـ sidebar الموجودة أصلًا بدل ما نكرر ترجمات جديدة لنفس الأسامي
        $allTools = [
            ['🤖', 'sidebar.seo_analysis', 'ai-seo-analysis'], ['🗂️', 'sidebar.ai_reports', 'ai-seo-analysis'], ['✍️', 'sidebar.ai_articles', 'ai-articles'],
            ['📱', 'sidebar.social', 'social-media'], ['📣', 'sidebar.ads', 'ads-management'], ['💡', 'sidebar.marketing_assistant', 'marketing-assistant'],
            ['🎨', 'sidebar.creative_studio', 'creative-studio'], ['📍', 'sidebar.gbp_content', 'google-business'], ['💬', 'sidebar.chat', 'whatsapp-chatbot'],
            ['🛠️', 'sidebar.website_optimizer', 'website-optimizer'], ['🌐', 'sidebar.websites', null], ['🏢', 'sidebar.agency', 'agency-white-label'],
            ['📊', 'sidebar.executive', null], ['📈', 'sidebar.analytics', null], ['🔐', 'sidebar.activity', null],
        ];
        $chipsHtml = '';
        foreach ($allTools as [$icon, $key, $serviceSlug]) {
            $label = '<span>' . $this->tr($key) . '</span>';
            $chipsHtml .= $serviceSlug
                ? '<a href="/services/' . htmlspecialchars($serviceSlug, ENT_QUOTES) . '" class="chip chip-link">' . $label . '</a>'
                : '<div class="chip">' . $label . '</div>';
        }

        // تصحيح باغ فادح: {site_name()} و{site_brand_html()} جوه heredoc
        // مبيتفسّروش من PHP خالص (بيدعم متغيرات بس مش استدعاء دوال
        // مباشر) - لازم نحسبهم في متغيرات الأول.
        $ic = [];
        foreach (['star', 'target', 'trending-up', 'users', 'dollar', 'file-text', 'eye', 'building', 'briefcase', 'compass', 'message'] as $n) {
            $ic[$n] = icon_svg($n, 22);
        }
        $icSm = [];
        foreach (['dollar', 'star', 'file-text', 'eye', 'message', 'trending-up'] as $n) {
            $icSm[$n] = icon_svg($n, 16);
        }
        $icSun = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';
        $siteNameSafe = site_name();
        $brandHtml = site_brand_html(false);
        $footerBrandHtml = site_brand_html(false);
        $faviconHtml = site_favicon_html();

        return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}" dir="{$dir}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="tourfecto-verification" content="a0736bd729912db6d9fb6f0d1fb9bdc8">
    <title>{$siteNameSafe} | {$this->tr('app.tagline')}</title>
    <meta name="description" content="{$this->tr('app.meta_description')}">
    {$faviconHtml}
    <meta property="og:type" content="website">
    <meta property="og:title" content="{$siteNameSafe} | {$this->tr('app.tagline')}">
    <meta property="og:description" content="{$this->tr('app.meta_description')}">
    <meta property="og:image" content="https://tourfecto.pro/assets/icons/icon-512.png">
    <meta name="twitter:card" content="summary">
    <meta name="theme-color" content="#F7F8FA">
    <meta name="twitter:title" content="{$siteNameSafe} | {$this->tr('app.tagline')}">
    <meta name="twitter:description" content="{$this->tr('app.meta_description')}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <script src="/assets/js/tf-theme.js?v=20261016"></script>
    <link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/compass.css">
</head>
<body class="compass">
<script>document.documentElement.classList.add('js-ready')</script>

<div class="wrap">
    <nav class="topnav" id="mainNav">
        <a href="/" class="brand">{$brandHtml}</a>
        <div class="navlinks">
            <a href="/#product">{$this->tr('nav.product')}</a>
            <a href="/pricing">{$this->tr('nav.pricing')}</a>
            <a href="/#success">{$this->tr('nav.success_stories')}</a>
            <a href="/help/contact">{$this->tr('nav.contact')}</a>
        </div>
        <div class="nav-right">
            <details class="langsel">
                <summary>{$this->tr('lang.switch')}</summary>
                <div class="menu">{$langMenu}</div>
            </details>
            <a href="/register" class="cta-ghost">{$this->tr('nav.try_free')}</a>
            <button type="button" class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Menu"><span></span></button>
        </div>
    </nav>
    <div class="mobile-menu" id="mobileMenu">
        <a href="/#product">{$this->tr('nav.product')}</a>
        <a href="/pricing">{$this->tr('nav.pricing')}</a>
        <a href="/#success">{$this->tr('nav.success_stories')}</a>
        <a href="/help/contact">{$this->tr('nav.contact')}</a>
        <a href="/register">{$this->tr('nav.try_free')}</a>
    </div>

    <section class="hero">
        <div>
            <div class="eyebrow"><span class="p"></span>{$this->tr('hero.eyebrow')}</div>
            <h1>{$this->tr('hero.title_line1')} <span class="grad">{$this->tr('hero.title_grad')}</span> {$this->tr('hero.title_line2')}</h1>
            <p class="sub">{$this->tr('hero.subtitle')}</p>
            <div class="hero-ctas">
                <a href="/register" class="btn-primary">{$this->tr('hero.cta_primary')}</a>
                <a href="/help" class="cta-ghost">{$this->tr('hero.cta_secondary')}</a>
            </div>
        </div>

        <div class="globe-stage" id="globeStage">
            <div class="hero-map" id="heroMap" role="img" aria-label="{$this->tr('worldtour.heading')}"></div>
            <div class="orbit-card oc1"><div class="n" data-count="48200" data-prefix="$">$0</div><div class="l">{$this->tr('hero.card.revenue')}</div></div>
            <div class="orbit-card oc2"><div class="n" data-count="4.9" data-decimal="1">0</div><div class="l">{$this->tr('rep.stats.avg_rating')}</div></div>
            <div class="orbit-card oc3"><div class="n" data-count="12" data-prefix="+" data-suffix="%">+0%</div><div class="l">{$this->tr('hero.card.rating')}</div></div>
        </div>
    </section>

    <div class="ticker-wrap">
        <div class="ticker">
            <span><b>{$this->tr('ticker.replies')}</b></span>
            <span><b>{$this->tr('ticker.revenue')}</b></span>
            <span><b>{$this->tr('ticker.competitors')}</b></span>
            <span><b>{$this->tr('ticker.ai')}</b></span>
            <span><b>{$this->tr('ticker.replies')}</b></span>
            <span><b>{$this->tr('ticker.revenue')}</b></span>
            <span><b>{$this->tr('ticker.competitors')}</b></span>
            <span><b>{$this->tr('ticker.ai')}</b></span>
        </div>
    </div>

    <div class="section-head reveal world-tour">
        <div class="eyebrow"><span class="p"></span>{$this->tr('worldtour.eyebrow')}</div>
        <h2>{$this->tr('worldtour.heading')}</h2>
        <p>{$this->tr('worldtour.subheading')}</p>
    </div>

    <section class="world-tour reveal" aria-hidden="true">
        <div class="landmarks-marquee">
            <div class="lm-track lm-1">
                <span><b>Dubai</b></span><span><b>Paris</b></span><span><b>Rome</b></span><span><b>New York</b></span>
                <span><b>Tokyo</b></span><span><b>Prague</b></span><span><b>Giza</b></span><span><b>London</b></span>
                <span><b>Sharm El Sheikh</b></span><span><b>Makkah</b></span><span><b>Interlaken</b></span><span><b>Bali</b></span>
                <span><b>Dubai</b></span><span><b>Paris</b></span><span><b>Rome</b></span><span><b>New York</b></span>
                <span><b>Tokyo</b></span><span><b>Prague</b></span><span><b>Giza</b></span><span><b>London</b></span>
                <span><b>Sharm El Sheikh</b></span><span><b>Makkah</b></span><span><b>Interlaken</b></span><span><b>Bali</b></span>
            </div>
        </div>
        <div class="landmarks-marquee">
            <div class="lm-track lm-2">
                <span><b>5-Star Hotels</b></span><span><b>Cruise Lines</b></span><span><b>200+ Airlines</b></span><span><b>Beach Resorts</b></span>
                <span><b>Tour Operators</b></span><span><b>Boutique Stays</b></span><span><b>Yacht Charters</b></span><span><b>Travel Agencies</b></span>
                <span><b>5-Star Hotels</b></span><span><b>Cruise Lines</b></span><span><b>200+ Airlines</b></span><span><b>Beach Resorts</b></span>
                <span><b>Tour Operators</b></span><span><b>Boutique Stays</b></span><span><b>Yacht Charters</b></span><span><b>Travel Agencies</b></span>
            </div>
        </div>
    </section>

    <div class="section-head reveal">
        <div class="eyebrow"><span class="p"></span>{$this->tr('services.eyebrow')}</div>
        <h2>{$this->tr('services.heading')}</h2>
        <p>{$this->tr('services.subheading')}</p>
    </div>

    <section id="product">
        <div class="compass-grid" id="tiltGrid">
            <a href="/services/reputation-management" class="compass-card reveal"><div class="compass-icon">{$ic['star']}</div><h3>{$this->tr('features.reputation.title')}</h3><p>{$this->tr('features.reputation.desc')}</p></a>
            <a href="/services/competitor-monitoring" class="compass-card reveal"><div class="compass-icon">{$ic['target']}</div><h3>{$this->tr('features.competitor.title')}</h3><p>{$this->tr('features.competitor.desc')}</p></a>
            <a href="/services/revenue-intelligence" class="compass-card reveal"><div class="compass-icon">{$ic['trending-up']}</div><h3>{$this->tr('features.revenue.title')}</h3><p>{$this->tr('features.revenue.desc')}</p></a>
            <a href="/services/crm" class="compass-card reveal"><div class="compass-icon">{$ic['users']}</div><h3>{$this->tr('features.crm.title')}</h3><p>{$this->tr('features.crm.desc')}</p></a>
        </div>

        <div class="all-services reveal">
            <div class="all-services-label">{$this->tr('services.all_tools')}</div>
            <div class="chip-grid">{$chipsHtml}</div>
        </div>

        <div class="stats-strip reveal">
            <div class="stat-block"><div class="num">98%</div><div class="lbl">{$this->tr('stats.reply_satisfaction')}</div></div>
            <div class="stat-block"><div class="num">4.2×</div><div class="lbl">{$this->tr('stats.faster_response')}</div></div>
            <div class="stat-block"><div class="num">120+</div><div class="lbl">{$this->tr('stats.agencies')}</div></div>
            <div class="stat-block"><div class="num">24/7</div><div class="lbl">{$this->tr('stats.monitoring')}</div></div>
        </div>
    </section>

    <section>
        <div class="dash reveal">
            <div class="drail">
                <div><div class="dlabel">{$this->tr('sidebar.group.main')}</div>
                    <div class="dlink on"><span class="dt"></span>{$this->tr('sidebar.executive')}</div>
                    <div class="dlink"><span class="dt"></span>{$this->tr('sidebar.analytics')}</div>
                </div>
                <div><div class="dlabel">{$this->tr('sidebar.group.business_intelligence')}</div>
                    <div class="dlink"><span class="dt"></span>{$this->tr('sidebar.revenue')}</div>
                    <div class="dlink"><span class="dt"></span>{$this->tr('sidebar.competitor_monitoring')}</div>
                </div>
                <div><div class="dlabel">{$this->tr('sidebar.group.customers')}</div>
                    <div class="dlink"><span class="dt"></span>{$this->tr('sidebar.crm')}</div>
                    <div class="dlink"><span class="dt"></span>{$this->tr('sidebar.reputation')}</div>
                </div>
            </div>
            <div class="dmain">
                <div class="dtop"><h3>{$this->tr('dash.preview.title')}</h3><div class="live"><span class="p"></span>{$this->tr('dash.preview.live')}</div></div>
                <div class="kgrid">
                    <div class="kcard g"><div class="ic">{$icSm['dollar']}</div><div class="kval">$48,200</div><div class="klabel">{$this->tr('executive.kpi.revenue')}</div></div>
                    <div class="kcard"><div class="ic">{$icSm['star']}</div><div class="kval">4.9</div><div class="klabel">{$this->tr('rep.stats.avg_rating')}</div></div>
                    <div class="kcard"><div class="ic">{$icSm['file-text']}</div><div class="kval">23</div><div class="klabel">{$this->tr('executive.kpi.open_deals')}</div></div>
                    <div class="kcard c"><div class="ic">{$icSm['eye']}</div><div class="kval">6</div><div class="klabel">{$this->tr('executive.kpi.competitors')}</div></div>
                </div>
                <div class="bigchart">
                    <b style="height:40%;animation-delay:.05s"></b><b style="height:65%;animation-delay:.1s"></b>
                    <b style="height:50%;animation-delay:.15s"></b><b style="height:80%;animation-delay:.2s"></b>
                    <b style="height:60%;animation-delay:.25s"></b><b style="height:95%;animation-delay:.3s"></b>
                    <b style="height:72%;animation-delay:.35s"></b><b style="height:85%;animation-delay:.4s"></b>
                </div>
            </div>
        </div>
    </section>

    <div class="section-head reveal">
        <div class="eyebrow"><span class="p"></span>{$this->tr('howitworks.eyebrow')}</div>
        <h2>{$this->tr('howitworks.heading')}</h2>
        <p>{$this->tr('howitworks.subheading')}</p>
    </div>

    <section id="how-it-works">
        <div class="steps-grid reveal">
            <div class="step-card">
                <div class="step-num">01</div>
                <h3>{$this->tr('howitworks.step1.title')}</h3>
                <p>{$this->tr('howitworks.step1.desc')}</p>
            </div>
            <div class="step-card">
                <div class="step-num">02</div>
                <h3>{$this->tr('howitworks.step2.title')}</h3>
                <p>{$this->tr('howitworks.step2.desc')}</p>
            </div>
            <div class="step-card">
                <div class="step-num">03</div>
                <h3>{$this->tr('howitworks.step3.title')}</h3>
                <p>{$this->tr('howitworks.step3.desc')}</p>
            </div>
            <div class="step-card">
                <div class="step-num">04</div>
                <h3>{$this->tr('howitworks.step4.title')}</h3>
                <p>{$this->tr('howitworks.step4.desc')}</p>
            </div>
        </div>
    </section>

    <div class="section-head reveal">
        <div class="eyebrow"><span class="p"></span>{$this->tr('faq.eyebrow')}</div>
        <h2>{$this->tr('faq.heading')}</h2>
    </div>

    <section id="faq">
        <div class="faq-list reveal">
            <details class="faq-item">
                <summary>{$this->tr('faq.q1.question')}</summary>
                <p>{$this->tr('faq.q1.answer')}</p>
            </details>
            <details class="faq-item">
                <summary>{$this->tr('faq.q2.question')}</summary>
                <p>{$this->tr('faq.q2.answer')}</p>
            </details>
            <details class="faq-item">
                <summary>{$this->tr('faq.q3.question')}</summary>
                <p>{$this->tr('faq.q3.answer')}</p>
            </details>
            <details class="faq-item">
                <summary>{$this->tr('faq.q4.question')}</summary>
                <p>{$this->tr('faq.q4.answer')}</p>
            </details>
            <details class="faq-item">
                <summary>{$this->tr('faq.q5.question')}</summary>
                <p>{$this->tr('faq.q5.answer')}</p>
            </details>
        </div>
    </section>

    <div class="section-head reveal" id="success">
        <div class="eyebrow"><span class="p"></span>{$this->tr('examples.eyebrow')}</div>
        <h2>{$this->tr('examples.heading')}</h2>
        <p>{$this->tr('examples.disclaimer')}</p>
    </div>

    <section id="illustrative-examples">
        <div class="examples-grid reveal">
            <div class="example-card">
                <div class="example-badge">{$this->tr('examples.badge')}</div>
                <div class="example-icon">{$ic['building']}</div>
                <h3>{$this->tr('examples.hotel.title')}</h3>
                <p>{$this->tr('examples.hotel.scenario')}</p>
                <div class="example-outcome">
                    <span class="oi">{$icSm['message']}</span>
                    <p>{$this->tr('examples.hotel.outcome')}</p>
                </div>
            </div>
            <div class="example-card">
                <div class="example-badge">{$this->tr('examples.badge')}</div>
                <div class="example-icon">{$ic['briefcase']}</div>
                <h3>{$this->tr('examples.tour.title')}</h3>
                <p>{$this->tr('examples.tour.scenario')}</p>
                <div class="example-outcome">
                    <span class="oi">{$icSm['trending-up']}</span>
                    <p>{$this->tr('examples.tour.outcome')}</p>
                </div>
            </div>
            <div class="example-card">
                <div class="example-badge">{$this->tr('examples.badge')}</div>
                <div class="example-icon">{$ic['compass']}</div>
                <h3>{$this->tr('examples.agency.title')}</h3>
                <p>{$this->tr('examples.agency.scenario')}</p>
                <div class="example-outcome">
                    <span class="oi">{$icSm['star']}</span>
                    <p>{$this->tr('examples.agency.outcome')}</p>
                </div>
            </div>
        </div>
    </section>

    <section id="final-cta">
        <div class="final-cta-box reveal">
            <h2>{$this->tr('finalcta.heading')}</h2>
            <p>{$this->tr('finalcta.subheading')}</p>
            <div class="hero-ctas" style="justify-content:center;">
                <a href="/register" class="btn-primary">{$this->tr('finalcta.cta')}</a>
                <a href="/pricing" class="cta-ghost">{$this->tr('finalcta.cta_secondary')}</a>
            </div>
        </div>
    </section>

    <footer class="site-footer-full">
        <div class="footer-cols">
            <div class="footer-col">
                <div class="footer-brand">{$footerBrandHtml}</div>
                <p>{$this->tr('footer.tagline')}</p>
            </div>
            <div class="footer-col">
                <h4>{$this->tr('footer.col.product')}</h4>
                <a href="/#product">{$this->tr('footer.all_services')}</a>
                <a href="/pricing">{$this->tr('footer.pricing')}</a>
                <a href="/register">{$this->tr('footer.register')}</a>
            </div>
            <div class="footer-col">
                <h4>{$this->tr('footer.col.support')}</h4>
                <a href="/help">{$this->tr('footer.help_center')}</a>
                <a href="/help/faq">{$this->tr('footer.faq')}</a>
                <a href="/help/contact">{$this->tr('footer.contact')}</a>
                <a href="/docs">{$this->tr('footer.docs')}</a>
            </div>
            <div class="footer-col">
                <h4>{$this->tr('footer.col.legal')}</h4>
                <a href="/terms">{$this->tr('footer.terms')}</a>
                <a href="/privacy">{$this->tr('footer.privacy')}</a>
            </div>
        </div>
        <div class="footer-bottom">&copy; {$year} {$appName}. {$this->tr('footer.rights')}</div>
    </footer>
</div>

<script src="/assets/js/tf-geo.js"></script>
<script src="/assets/js/home-map.js"></script>
<script>
    var nav = document.getElementById('mainNav');
    window.addEventListener('scroll', function () { nav.classList.toggle('scrolled', window.scrollY > 20); });

    var menuBtn = document.getElementById('mobileMenuBtn');
    var mobileMenu = document.getElementById('mobileMenu');
    menuBtn.addEventListener('click', function () {
        menuBtn.classList.toggle('open');
        mobileMenu.classList.toggle('open');
    });
    mobileMenu.querySelectorAll('a').forEach(function (a) {
        a.addEventListener('click', function () { menuBtn.classList.remove('open'); mobileMenu.classList.remove('open'); });
    });

    document.querySelectorAll('[data-count]').forEach(function (el) {
        var target = parseFloat(el.dataset.count);
        var decimals = parseInt(el.dataset.decimal || '0');
        var prefix = el.dataset.prefix || '';
        var suffix = el.dataset.suffix || '';
        var cur = 0;
        var step = target / 60;
        function tick() {
            cur = Math.min(cur + step, target);
            el.textContent = prefix + cur.toLocaleString(undefined, { maximumFractionDigits: decimals, minimumFractionDigits: decimals }) + suffix;
            if (cur < target) requestAnimationFrame(tick);
        }
        requestAnimationFrame(tick);
    });

    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) { if (e.isIntersecting) e.target.classList.add('in'); });
    }, { threshold: 0.15 });
    document.querySelectorAll('.reveal').forEach(function (el) { io.observe(el); });
</script>
</body>
</html>
HTML;
    }
}
