<?php

/**
 * Tourfecto - صفحات الموقع العام: الرئيسية، المميزات، الأسعار، من نحن، تواصل، اطلب عرض، الاسترجاع، المدونة
 *
 * كل المحتوى جاي من MarketingContent (عربي + إنجليزي) وكل الصفحات بتتعرض جوه PublicLayout.
 */
class MarketingController extends Controller
{
    private function t($pair): string
    {
        return MarketingContent::t($pair);
    }

    private function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    private function te($pair): string
    {
        return $this->e($this->t($pair));
    }

    private function out(string $html, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    private static function check(): string
    {
        return '<span class="ck"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>';
    }

    private function icon(string $name, int $size = 20): string
    {
        return function_exists('icon_svg') ? icon_svg($name, $size) : '';
    }

    // ================================================================== الرئيسية

    /** GET / */
    /** الرئيسية: "الباقات تبدأ من ..." بعملة الزائر + زر العملة */
    private function fromPrice(): string
    {
        try {
            require_once __DIR__ . '/../Services/Payment/CurrencyService.php';
            require_once __DIR__ . '/../Services/Subscription/BillingPlanCatalog.php';
            $min = 0.0;
            foreach (BillingPlanCatalog::read() as $p) {
                $m = (float) ($p['price_monthly'] ?? 0);
                if ($m > 0 && ($min === 0.0 || $m < $min)) $min = $m;
            }
            if ($min <= 0) return '';
            header('Vary: Cookie, Accept-Language');
            $isAr = $this->t(['ar', 'en']) === 'ar';
            $price = CurrencyService::format($min, CurrencyService::forViewer($this->user ?? null), $isAr);
            require_once __DIR__ . '/../Services/Subscription/PlanExtras.php';
            $cfg = (new PlanExtras())->config();
            $trial = !empty($cfg['trial_enabled']) ? sprintf($this->t([((int) $cfg['trial_days'] <= 10 ? ' · تجربة مجانية %d أيام' : ' · تجربة مجانية %d يوم'), ' · %d-day free trial']), (int) $cfg['trial_days']) : '';
            return '<div class="hero-from" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:center;margin-top:14px;font-size:14px;opacity:.9">'
                . '<a href="/pricing" style="color:inherit">' . $this->e(sprintf($this->t(['الباقات تبدأ من %s / شهر', 'Plans from %s / month']), $price) . $trial) . '</a>'
                . '<span data-tf-currency="compact"></span></div>';
        } catch (Throwable $e) {
            return '';
        }
    }

    /** POST /api/public/free-check {url, lang} - الفحص المجاني لموقع شركة رحلات (عام، من غير تسجيل) */
    public function freeCheck(array $params = []): array
    {
        if ($rl = $this->rateLimitGuard('ip', 'free_site_check', 6, 600)) {
            return $rl;
        }
        $lang = $this->get('lang') === 'ar' ? 'ar' : 'en';
        $svc = new FreeSiteCheckService($lang);
        $url = FreeSiteCheckService::normalizeUrl((string) $this->get('url', ''));
        if ($url === null) {
            return $this->error($lang === 'ar' ? 'اكتب رابط موقعك صح، مثلًا yourtours.com' : 'Enter a valid website address, e.g. yourtours.com', 422);
        }
        try {
            $r = $svc->run($url);
        } catch (Throwable $e) {
            Logger::warning('Free site check failed', ['url' => $url, 'error' => $e->getMessage()]);
            $r = ['ok' => false, 'error' => $lang === 'ar' ? 'مقدرناش نفحص الموقع دلوقتي، جرّب تاني.' : 'We could not check the site right now, try again.'];
        }
        if (empty($r['ok'])) {
            return $this->error($r['error'] ?? 'Error', 422);
        }
        // آخر الفحوصات (للأدمن: مين مهتم) - الدومين والنتيجة بس
        try {
            $ss = new SystemSettingsService();
            $log = json_decode($ss->get('free_checks_recent', '[]'), true) ?: [];
            array_unshift($log, ['host' => $r['host'], 'score' => $r['score'], 'lang' => $lang, 'at' => date('Y-m-d H:i:s')]);
            $ss->set('free_checks_recent', json_encode(array_slice($log, 0, 200), JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
        error_log('[MarketingController] ignored error: ' . $e->getMessage());
        }
        return $this->success($r);
    }

    public function home(array $params = []): array
    {
        $h = MarketingContent::home();
        [$l1, $grad, $l2] = MarketingContent::isAr() ? $h['title'][0] : $h['title'][1];

        $ticker = '';
        foreach (array_merge($h['ticker'], $h['ticker']) as $tk) {
            $ticker .= '<span><b>' . $this->te($tk) . '</b></span>';
        }
        $feats = '';
        foreach (MarketingContent::features() as $slug => $f) {
            $feats .= '<a href="/features/' . $slug . '" class="hf reveal"><span class="fi tone-' . $f['tone'] . '">' . $this->icon($f['icon'], 19) . '</span><b>' . $this->te($f['title']) . '</b><span>' . $this->te($f['short']) . '</span></a>';
        }
        $journey = '';
        foreach ($h['journey'] as $i => [$jt, $jd]) {
            $journey .= '<div class="step-card"><div class="step-num">' . ($i + 1) . '</div><h3>' . $this->te($jt) . '</h3><p>' . $this->te($jd) . '</p></div>';
        }
        $facts = '';
        foreach ($h['facts'] as [$n, $l]) {
            $facts .= '<div><b dir="ltr">' . $this->te($n) . '</b><span>' . $this->te($l) . '</span></div>';
        }
        $how = '';
        foreach ($h['how'] as $i => [$st, $sd]) {
            $how .= '<div class="step-card"><div class="step-num">0' . ($i + 1) . '</div><h3>' . $this->te($st) . '</h3><p>' . $this->te($sd) . '</p></div>';
        }
        $ex = '';
        foreach ($h['examples'] as [$ic, $et, $es, $eo]) {
            $ex .= '<div class="example-card"><div class="example-badge">' . $this->te($h['ex_badge']) . '</div><div class="example-icon">' . $this->icon($ic, 22) . '</div>'
                . '<h3>' . $this->te($et) . '</h3><p>' . $this->te($es) . '</p><div class="example-outcome"><span class="oi">' . $this->icon('trending-up', 16) . '</span><p>' . $this->te($eo) . '</p></div></div>';
        }
        $faq = '';
        $faqLd = [];
        foreach (array_merge($this->homeFaqExtra(), $h['faq']) as [$q, $a]) {
            $faq .= '<details class="faq-item"><summary>' . $this->te($q) . '</summary><p>' . $this->te($a) . '</p></details>';
            $faqLd[] = ['@type' => 'Question', 'name' => $this->t($q), 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $this->t($a)]];
        }
        $cards = $h['cards'];

        // الصفحة الرئيسية الجديدة (2026-10-24): الهوية الجديدة، وأمثلة من شغل المنصة الحقيقي بدل أرقام متألفة
        $v2 = $this->homeV2($faq, $this->fromPrice());
        if ($v2 !== '') {
            $this->out(PublicLayout::render([
                'title' => $this->t(['حجوزات وتقييمات وسيو لشركات الرحلات', 'Bookings, reviews and SEO for tour companies']),
                'description' => $this->t(['منصة واحدة لشركات الرحلات: ردود على التقييمات بكل اللغات، فحص سيو لصفحات رحلاتك، موقع حجز ودفع، وشات واتساب 24 ساعة.', 'One platform for tour companies: review replies in every language, SEO audits of your tour pages, a booking site with payments and 24/7 WhatsApp chat.']),
                'path' => '/',
                'head' => '<link rel="stylesheet" href="' . $this->e(function_exists('asset_v') ? asset_v('/assets/css/home-v2.css') : '/assets/css/home-v2.css') . '">',
                'ogImage' => PublicLayout::baseUrl() . '/assets/brand/og-image.png',
                'body' => $v2,
                'jsonld' => [
                    ['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => 'Tourfecto', 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web', 'description' => $this->t($h['meta_desc']), 'url' => PublicLayout::baseUrl()],
                    ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $faqLd],
                ],
            ]));
            return [];
        }

        $body = '<section class="hero">'
            . '<div><div class="eyebrow"><span class="p"></span>' . $this->te($h['eyebrow']) . '</div>'
            . '<h1>' . $this->e($l1) . ' <span class="grad">' . $this->e($grad) . '</span> ' . $this->e($l2) . '</h1>'
            . '<p class="sub">' . $this->te($h['sub']) . '</p>'
            . '<div class="hero-ctas"><a href="/register" class="btn-primary">' . $this->te($h['cta1']) . '</a><a href="/demo" class="cta-ghost">' . $this->te($h['cta2']) . '</a></div>' . $this->fromPrice() . '</div>'
            . '<div class="globe-stage" id="globeStage"><div class="hero-map" id="heroMap" role="img" aria-label="' . $this->te($h['eyebrow']) . '"></div>'
            . '<div class="orbit-card oc1"><div class="n" data-count="48200" data-prefix="$">$0</div><div class="l">' . $this->te($cards[0]) . '</div></div>'
            . '<div class="orbit-card oc2"><div class="n" data-count="4.9" data-decimal="1">0</div><div class="l">' . $this->te($cards[1]) . '</div></div>'
            . '<div class="orbit-card oc3"><div class="n" data-count="126" data-prefix="">0</div><div class="l">' . $this->te($cards[2]) . '</div></div></div>'
            . '</section>'
            . '<div class="ticker-wrap" aria-hidden="true"><div class="ticker">' . $ticker . '</div></div>'
            . '<section class="blk"><div class="sec-head"><h2 class="sec-title">' . $this->te($h['feat_title']) . '</h2><p class="sec-sub">' . $this->te($h['feat_sub']) . '</p></div>'
            . '<div class="home-feats">' . $feats . '</div>'
            . '<div style="text-align:center;margin-top:26px"><a href="/features" class="cta-ghost">' . $this->te($h['feat_all']) . '</a></div></section>'
            . '<section class="blk alt"><div class="sec-head"><h2 class="sec-title">' . $this->te($h['journey_title']) . '</h2></div><div class="steps-grid journey reveal">' . $journey . '</div>'
            . '<div class="facts reveal" style="margin-top:28px">' . $facts . '</div></section>'
            . '<section class="blk"><div class="sec-head"><h2 class="sec-title">' . $this->te($h['how_title']) . '</h2><p class="sec-sub">' . $this->te($h['how_sub']) . '</p></div><div class="steps-grid reveal">' . $how . '</div></section>'
            . '<section class="blk alt"><div class="sec-head"><h2 class="sec-title">' . $this->te($h['ex_title']) . '</h2><p class="sec-sub">' . $this->te($h['ex_note']) . '</p></div><div class="examples-grid reveal">' . $ex . '</div></section>'
            . '<section class="blk"><div class="sec-head"><h2 class="sec-title">' . $this->te(['أسئلة شائعة', 'Frequently asked questions']) . '</h2></div><div class="faq-list">' . $faq . '</div></section>'
            . PublicLayout::ctaBand();

        $script = '<script src="/assets/js/tf-geo.js" defer></script><script src="/assets/js/home-map.js" defer></script>'
            . '<script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("[data-count]").forEach(function(el){var t=parseFloat(el.dataset.count),d=parseInt(el.dataset.decimal||"0"),p=el.dataset.prefix||"",c=0,s=t/60;(function k(){c=Math.min(c+s,t);el.textContent=p+c.toLocaleString("en-US",{maximumFractionDigits:d,minimumFractionDigits:d});if(c<t)requestAnimationFrame(k);})();});});</script>';

        $this->out(PublicLayout::render([
            'title' => $this->t($h['meta_title']),
            'description' => $this->t($h['meta_desc']),
            'path' => '/',
            'body' => $body . $script,
            'jsonld' => [
                ['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => 'Tourfecto', 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web', 'description' => $this->t($h['meta_desc']), 'url' => PublicLayout::baseUrl()],
                ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $faqLd],
            ],
        ]));
        return [];
    }

    /** POST /api/public/free-social-check {facebook, instagram, competitors[], lang} - تحقق من لينكات الصفحات والمنافسين وخطة التحليل (عام، من غير تسجيل، من غير أي أرقام) */
    public function freeSocialCheck(array $params = []): array
    {
        if ($rl = $this->rateLimitGuard('ip', 'free_social_check', 12, 600)) {
            return $rl;
        }
        $lang = $this->get('lang') === 'ar' ? 'ar' : 'en';
        $comps = $this->get('competitors', []);
        $r = (new FreeSocialCheckService($lang))->run([
            'facebook' => (string) $this->get('facebook', ''),
            'instagram' => (string) $this->get('instagram', ''),
            'competitors' => is_array($comps) ? $comps : [],
        ]);
        if (empty($r['ok'])) {
            (new SocialIntelService())->logCheck($lang, null, null, [], array_keys((array) ($r['field_errors'] ?? [])));
            return $this->error($r['error'] ?? 'Error', 422, ['field_errors' => $r['field_errors'] ?? []]);
        }
        (new SocialIntelService())->logCheck($lang, $r['own']['facebook']['handle'] ?? null, $r['own']['instagram']['handle'] ?? null,
            array_map(static fn ($c) => $c['platform'] . ':' . $c['handle'], $r['competitors']), []);
        // بنسلّم اللينكات للمنصة بعد التسجيل (كوكي موقّعة 14 يوم) - اللينكات نفسها بس، مفيش بيانات
        $token = FreeSocialCheckService::seal($r['intake']);
        if ($token !== null && !headers_sent()) {
            setcookie(FreeSocialCheckService::COOKIE, $token, ['expires' => time() + 14 * 86400, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
        }
        unset($r['ok'], $r['intake']);
        return $this->success($r);
    }

    /** GET /social-check - صفحة «حلّل صفحاتك ومنافسينك» */
    public function socialCheck(array $params = []): array
    {
        $ar = MarketingContent::isAr();
        $L = json_encode([
            'checking' => $this->t(['بنتحقق من اللينكات…', 'Checking your links…']),
            'recognized' => $this->t(['اللينكات اللي اتعرّفت', 'Links we recognised']),
            'yours' => $this->t(['صفحاتك', 'Your pages']),
            'comps' => $this->t(['المنافسين', 'Competitors']),
            'measure_own' => $this->t(['هنحلل في صفحتك', 'What we analyse on your page']),
            'measure_comp' => $this->t(['وهنقارنك بمنافسينك في', 'And compare you with competitors on']),
            'need' => $this->t(['المطلوب عشان التحليل يشتغل', 'What you need for the analysis']),
            'steps' => $this->t(['بتتنفّذ إزاي بعد الاشتراك', 'How it runs after you subscribe']),
            'plan' => $this->t(['الباقة المناسبة', 'The plan you need']),
            'from' => $this->t(['من', 'from']),
            'month' => $this->t(['/ شهر', '/ month']),
            'comp_note' => $this->t(['بتتحدد حسب عدد منافسي إنستجرام اللي حطيتهم.', 'Based on the number of Instagram competitors you entered.']),
            'honest' => $this->t(['مابنعرضش هنا أي رقم عن صفحتك أو منافسيك. الأرقام بتتقرا من Meta الرسمي بعد ما تربط صفحتك، وأي حاجة مش متاحة بنقولك سببها بدل التخمين.', 'We show no numbers about your page or competitors here. Numbers are read from official Meta data after you connect your page, and anything unavailable is explained instead of guessed.']),
            'cta_new' => $this->t(['سجّل وابدأ التحليل', 'Sign up and start the analysis']),
            'cta_in' => $this->t(['كمّل في خطة السوشيال', 'Continue in your social plan']),
            'err' => $this->t(['حصلت مشكلة، جرّب تاني', 'Something went wrong, try again']),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $in = 'style="width:100%;padding:13px 14px;border-radius:12px;border:1px solid #D5DBE5;font-size:15px;background:transparent;color:inherit" dir="ltr"';
        $form = '<section class="blk tight" style="padding-top:0"><div style="max-width:820px;margin:0 auto">'
            . '<form id="scForm" novalidate style="display:grid;gap:12px">'
            . '<label><b>📘 ' . $this->te(['لينك صفحة فيسبوك', 'Facebook page link']) . '</b><input id="scFb" type="text" inputmode="url" placeholder="https://facebook.com/yourpage" ' . $in . '></label>'
            . '<label><b>📸 ' . $this->te(['لينك حساب إنستجرام', 'Instagram profile link']) . '</b><input id="scIg" type="text" inputmode="url" placeholder="https://instagram.com/yourprofile" ' . $in . '></label>'
            . '<fieldset style="border:1px dashed #D5DBE5;border-radius:12px;padding:12px;display:grid;gap:8px"><legend style="padding:0 6px"><b>' . $this->te(['منافسينك (اختياري، حتى 3)', 'Your competitors (optional, up to 3)']) . '</b></legend>'
            . '<input class="scComp" type="text" inputmode="url" placeholder="instagram.com/competitor" ' . $in . '>'
            . '<input class="scComp" type="text" inputmode="url" placeholder="instagram.com/competitor2" ' . $in . '>'
            . '<input class="scComp" type="text" inputmode="url" placeholder="facebook.com/competitor3" ' . $in . '></fieldset>'
            . '<button type="submit" class="btn-primary" id="scBtn" style="border:0;cursor:pointer;font-family:inherit;justify-self:start">' . $this->te(['حلّل صفحاتي ومنافسيني', 'Analyse my pages and competitors']) . '</button></form>'
            . '<p id="scErr" role="alert" style="color:#D92D20;font-size:14px;min-height:18px;margin:8px 0 0"></p><div id="scOut" aria-live="polite"></div></div></section>';
        $cta = $this->isAuthenticated() ? '/social-plan' : '/register?src=social-check';
        $js = '<script>(function(){var L=' . $L . ',CTA=' . json_encode($cta) . ',AR=' . ($ar ? 'true' : 'false') . ',f=document.getElementById("scForm");if(!f)return;'
            . 'function esc(s){return String(s==null?"":s).replace(/[&<>"\']/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","\'":"&#39;"}[c];});}'
            . 'function li(a){return "<ul style=\"margin:6px 0 0;padding-inline-start:20px;line-height:1.9\">"+a.map(function(x){return "<li>"+esc(Array.isArray(x)?x[0]:x)+"</li>";}).join("")+"</ul>";}'
            . 'function chip(x,icon){return "<div dir=\"ltr\" style=\"display:flex;gap:8px;align-items:center;padding:8px 0;border-bottom:1px solid #E5E9F0\"><span style=\"color:#0E9F6E;font-weight:800\">✓</span><span>"+icon+" "+esc(x.platform)+" · <b>"+esc(x.handle)+"</b></span></div>";}'
            . 'f.addEventListener("submit",async function(e){e.preventDefault();var b=document.getElementById("scBtn"),er=document.getElementById("scErr"),o=document.getElementById("scOut");er.textContent="";o.innerHTML="";'
            . 'var comps=[].slice.call(document.querySelectorAll(".scComp")).map(function(i){return i.value.trim();}).filter(Boolean);'
            . 'var body={facebook:document.getElementById("scFb").value.trim(),instagram:document.getElementById("scIg").value.trim(),competitors:comps,lang:AR?"ar":"en"};'
            . 'var bt=b.textContent;b.disabled=true;b.textContent=L.checking;'
            . 'try{var r=await fetch("/api/public/free-social-check",{method:"POST",headers:{"Content-Type":"application/json","Accept":"application/json"},credentials:"same-origin",body:JSON.stringify(body)});var d=await r.json();'
            . 'if(!d.success){var fe=(d.details&&d.details.field_errors)||{},m=Object.keys(fe).map(function(k){return fe[k];});er.innerHTML=(m.length?m:[d.error||L.err]).map(esc).join("<br>");}'
            . 'else{var x=d.data,h="";h+="<h3 style=\"margin:20px 0 4px\">"+esc(L.recognized)+"</h3>";'
            . 'var own=[];if(x.own.facebook)own.push(chip(x.own.facebook,"📘"));if(x.own.instagram)own.push(chip(x.own.instagram,"📸"));h+="<b>"+esc(L.yours)+"</b>"+own.join("");'
            . 'if(x.competitors.length){h+="<div style=\"margin-top:10px\"><b>"+esc(L.comps)+"</b></div>"+x.competitors.map(function(c){return chip(c,c.platform==="instagram"?"📸":"📘")+"<div style=\"opacity:.8;font-size:13.5px;padding:2px 0 8px 0\">"+esc(c.note)+"</div>";}).join("");}'
            . 'if((x.warnings||[]).length){h+="<div style=\"margin-top:10px;padding:10px 12px;border-radius:10px;background:#FEF3C7;color:#7C2D12\">"+x.warnings.map(esc).join("<br>")+"</div>";}'
            . 'h+="<h3 style=\"margin:20px 0 0\">"+esc(L.measure_own)+"</h3>"+li(x.measures.own);'
            . 'if(x.measures.competitors){h+="<h3 style=\"margin:16px 0 0\">"+esc(L.measure_comp)+"</h3>"+li(x.measures.competitors);}'
            . 'h+="<h3 style=\"margin:16px 0 0\">"+esc(L.need)+"</h3>"+li(x.requirements);'
            . 'h+="<h3 style=\"margin:16px 0 0\">"+esc(L.steps)+"</h3><ol style=\"margin:6px 0 0;padding-inline-start:20px;line-height:1.9\">"+x.steps.map(function(s){return "<li>"+esc(s)+"</li>";}).join("")+"</ol>";'
            . 'if(x.recommended){var rc=x.recommended,nm=AR?rc.name:rc.name_en;h+="<p style=\"margin:14px 0 0\"><b>"+esc(L.plan)+":</b> "+esc(nm)+" — "+esc(L.from)+" $"+esc(rc.price_monthly)+" "+esc(L.month)+"<br><span style=\"opacity:.75;font-size:13px\">"+esc(L.comp_note)+"</span></p>";}'
            . 'h+="<p style=\"margin:14px 0 0;padding:10px 12px;border-radius:10px;border:1px solid #D5DBE5;font-size:13.5px\">"+esc(L.honest)+"</p>";'
            . 'h+="<div style=\"text-align:center;margin:20px 0 6px\"><a class=\"btn-primary\" href=\""+CTA+"\">"+esc(CTA==="/social-plan"?L.cta_in:L.cta_new)+"</a></div>";'
            . 'o.innerHTML=h;o.scrollIntoView({behavior:"smooth",block:"nearest"});}}catch(e2){er.textContent=L.err;}b.disabled=false;b.textContent=bt;});})();</script>';
        $body = PublicLayout::hero($this->t(['تحليل السوشيال', 'Social analysis']), $this->t(['حلّل صفحتك على فيسبوك وإنستجرام وقارنها بمنافسينك', 'Analyse your Facebook and Instagram pages and compare them with competitors']),
            $this->t(['حط لينك صفحتك ولينك إنستجرام (ومنافسينك لو عايز)، وهنقولك بالظبط إيه اللي هيتحلل وإيه المطلوب، وبعد الاشتراك المنصة بتنفّذ التحليل من بياناتك الحقيقية.', 'Paste your page and Instagram links (and competitors if you like). We tell you exactly what gets analysed and what you need, and after you subscribe the platform runs the analysis from your real data.']))
            . $form . '<section class="blk tight"><div style="text-align:center"><a href="/pricing#social" class="cta-ghost">' . $this->te(['شوف باقات السوشيال ميديا', 'See the social media plans']) . '</a></div></section>'
            . PublicLayout::ctaBand() . $js;
        $this->out(PublicLayout::render([
            'title' => $this->t(['تحليل صفحات فيسبوك وإنستجرام والمنافسين', 'Facebook and Instagram page and competitor analysis']),
            'description' => $this->t(['حط لينك صفحتك ومنافسينك واعرف إيه اللي هيتحلل وإيه المطلوب. التحليل بيتنفّذ من بياناتك الرسمية بعد الاشتراك.', 'Add your page and competitor links and see what gets analysed and what you need. The analysis runs on your official data after you subscribe.']),
            'path' => '/social-check', 'active' => 'pricing', 'body' => $body,
        ]));
        return [];
    }

    /** قسم باقات السوشيال في صفحة الأسعار: من نفس إعدادات الأدمن (باقات السوشيال)، وبعملة الزائر */
    /**
     * Pricing tab for the reputation service. Always has real content (what the tools do and the AI replies each platform
     * plan includes, read from the live plan data); stand-alone plan cards appear only while the admin has selling on and
     * the plan marked "on sale" (Admin > Reputation limits).
     */
    private function reputationPlansSection(array $plans, string $viewCur, bool $isAr, bool $loggedIn): string
    {
        $ck = self::check();
        $x = '<span class="ck"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 12h10"/></svg></span>';
        $cards = '';
        try {
            $cfg = ReputationPlans::config();
            if (!empty($cfg['enabled']) && ReputationPlans::ready()) {
                foreach (ReputationPlans::KEYS as $k) {
                    $pl = $cfg['plans'][$k];
                    if (empty($pl['for_sale']) || (float) $pl['price_monthly'] <= 0) {
                        continue;
                    }
                    $sym = '$';
                    $m = (float) $pl['price_monthly'];
                    $y = (float) $pl['price_yearly'];
                    $egM = $viewCur === 'USD' ? CurrencyService::egpHint($m, $isAr) : '';
                    $egY = $viewCur === 'USD' ? CurrencyService::egpHint($y, $isAr) : '';
                    if ($viewCur !== 'USD') {
                        $meta = CurrencyService::CURRENCIES[$viewCur];
                        $sym = ($isAr ? $meta['ar_symbol'] : $meta['symbol']) . ' ';
                        $m = CurrencyService::fromUsd($m, $viewCur);
                        $y = CurrencyService::fromUsd($y, $viewCur);
                    }
                    $L = $pl['limits'];
                    $num = static fn ($v) => (int) $v < 0 ? '∞' : number_format((int) $v);
                    $items = [
                        [true, $num($L['review_requests_monthly']) . ' ' . $this->t(['طلب تقييم في الشهر', 'review requests / month'])],
                        [(int) $L['locations'] !== 0, $num($L['locations']) . ' ' . $this->t(['فرع Google Business', 'Google Business locations'])],
                        [(int) $L['sms_requests_monthly'] !== 0, $this->t(['طلبات تقييم بـ SMS', 'SMS review requests'])],
                        [!empty($L['team_assignment']), $this->t(['تعيين التقييمات لفريقك', 'Assign reviews to your team'])],
                        [!empty($L['csv_import']), $this->t(['استيراد تقييمات من Booking وExpedia وTrustpilot', 'Import reviews from Booking, Expedia and Trustpilot'])],
                        [!empty($L['surveys']), $this->t(['استبيان رأي خاص بدرجة NPS', 'Private feedback survey (NPS)'])],
                        [!empty($L['monthly_report']), $this->t(['تقرير شهري بهوية شركتك', 'Monthly white-label report'])],
                    ];
                    $li = '';
                    foreach ($items as [$on, $txt]) {
                        $li .= '<li class="' . ($on ? '' : 'off') . '">' . ($on ? $ck : $x) . '<span>' . $this->e((string) $txt) . '</span></li>';
                    }
                    $actions = $loggedIn
                        ? '<a href="/reputation/plans" class="btn-primary">' . $this->te(['اشترك من حسابك', 'Subscribe from your account']) . '</a>'
                        : '<a href="/register?src=reputation-pricing" class="btn-primary">' . $this->te(['ابدأ مجانًا', 'Start free']) . '</a>';
                    $cards .= '<div class="plan" data-em="' . $this->e($egM) . '" data-ey="' . $this->e($egY) . '" data-m="' . $this->e(number_format($m, 0)) . '" data-y="' . $this->e(number_format($y, 0)) . '" data-ym="' . $this->e(number_format($y / 12, 0)) . '" data-save="' . ($m > 0 ? max(0, (int) round((1 - $y / ($m * 12)) * 100)) : 0) . '">'
                        . '<h3>' . $this->e($pl['name']) . '</h3>'
                        . '<div class="price"><b><span class="cur">' . $this->e($sym) . '</span><span class="amt">' . $this->e(number_format($m, 0)) . '</span></b><small class="unit">' . $this->te(['/ شهر', '/ month']) . '</small></div>'
                        . ($egM !== '' ? '<div class="egp" style="font-size:12.5px;opacity:.7;margin-top:2px">≈ <bdi>' . $this->e($egM) . '</bdi></div>' : '')
                        . '<div class="per">&nbsp;</div><ul>' . $li . '</ul><div class="actions">' . $actions . '</div></div>';
                }
            }
        } catch (Throwable $e) {
            $cards = '';
        }
        $tools = [
            ['تجميع تقييمات Google في مكان واحد مع تحليل المشاعر والمواضيع', 'Google reviews in one place, with sentiment and topic analysis'],
            ['ردود بالذكاء الاصطناعي بأسلوبك، وقواعد رد تلقائي حسب التقييم', 'AI replies in your voice, with auto-reply rules by rating'],
            ['تنبيه فوري بالتقييم السلبي وتعيينه لحد من فريقك بموعد رد', 'Instant alerts for negative reviews, assignable to your team with a reply deadline'],
            ['طلبات تقييم بواتساب أو إيميل، وصفحة QR للضيوف', 'Review requests by WhatsApp or e-mail, and a QR page for guests'],
            ['استبيان رأي خاص بدرجة NPS من غير ما يخفي روابط التقييم العلني', 'Private feedback survey with an NPS score that never hides your public review links'],
            ['تقرير شهري بهوية شركتك، وجاهز للطباعة أو PDF', 'A monthly report under your own brand, ready to print or save as PDF'],
            ['استيراد تقييمات Booking وExpedia وTrustpilot بملف CSV', 'Import Booking, Expedia and Trustpilot reviews from a CSV file'],
        ];
        $toolsLi = '';
        foreach ($tools as $tl) {
            $toolsLi .= '<li>' . $ck . '<span>' . $this->e($this->t($tl)) . '</span></li>';
        }
        $incl = '';
        foreach ($plans as $key => $pp) {
            $credits = $pp['features']['review_credits'] ?? null;
            if (!is_numeric($credits)) {
                continue;
            }
            $incl .= '<li>' . $ck . '<span><b>' . $this->e(MarketingContent::planName($key, (string) $pp['name'])) . ':</b> '
                . ((int) $credits > 0 ? '<bdi>' . number_format((int) $credits) . '</bdi> ' . $this->e($this->t(['رد ذكي على التقييمات في الشهر', 'AI review replies / month'])) : $this->e($this->t(['من غير ردود مشمولة (الاستخدام من رصيدك)', 'no replies included (pay from your balance)']))) . '</span></li>';
        }
        $cta = $loggedIn
            ? '<a href="/reputation/reviews" class="btn-primary">' . $this->te(['افتح السمعة والتقييمات', 'Open Reputation & reviews']) . '</a>'
            : '<a href="/register?src=reputation-pricing" class="btn-primary">' . $this->te(['ابدأ مجانًا', 'Start free']) . '</a>';
        return '<section class="blk" id="reputation"><div class="sec-head"><h2 class="sec-title">' . $this->te(['السمعة والتقييمات', 'Reputation & reviews']) . '</h2><p class="sec-sub">'
            . $this->te(['اعرف الناس بتقول إيه عنك، ورد بسرعة، واطلب تقييمات من ضيوفك. أدوات السمعة داخلة في باقات المنصة، ولما تكون الباقة المستقلة معروضة بتظهر تحت.', 'See what people say about you, reply fast, and ask your guests for reviews. The reputation tools are included in the platform plans; the stand-alone plans appear below when they are on sale.'])
            . '</p></div><div class="plans" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr))"><div class="plan"><h3>' . $this->te(['اللي هتاخده', 'What you get']) . '</h3><ul>' . $toolsLi . '</ul>'
            . '<div class="actions">' . $cta . '</div></div>'
            . ($incl !== '' ? '<div class="plan"><h3>' . $this->te(['مشمول في باقات المنصة', 'Included in the platform plans']) . '</h3><ul>' . $incl . '</ul></div>' : '') . '</div>'
            . ($cards !== '' ? '<div class="sec-head" style="margin-top:28px"><h3 class="sec-title" style="font-size:20px">' . $this->te(['باقات السمعة المستقلة', 'Stand-alone reputation plans']) . '</h3></div><div class="plans">' . $cards . '</div>' : '')
            . '</section>';
    }

    private function socialPlansSection(string $viewCur, bool $isAr, bool $loggedIn): string
    {
        try {
            $cfg = SocialPackages::config();
        } catch (Throwable $e) {
            return '';
        }
        $ck = self::check();
        $cards = '';
        foreach (SocialPackages::sellableTierKeys() as $k) {
            $t = $cfg['tiers'][$k] ?? null;
            if (!$t || empty($t['for_sale']) || (float) $t['price_monthly'] <= 0) {
                continue;
            }
            $sym = '$';
            $m = (float) $t['price_monthly'];
            $y = (float) $t['price_yearly'];
            $tierCur = (string) ($t['currency'] ?? 'USD');
            $egM = $viewCur === 'USD' ? CurrencyService::egpHint($m, $isAr) : '';
            $egY = $viewCur === 'USD' ? CurrencyService::egpHint($y, $isAr) : '';
            // باقة مسعّرة بالجنيه والزائر بيشوف بالدولار: نعرض الجنيه الأصلي بالظبط مش مبلغ متحوّل ومقرّب
            if ($tierCur === 'EGP' && $viewCur === 'USD') {
                $egM = $isAr ? number_format((float) $t['price_native_monthly'], 0) . ' ج.م' : 'EGP ' . number_format((float) $t['price_native_monthly'], 0);
                $egY = $isAr ? number_format((float) $t['price_native_yearly'], 0) . ' ج.م' : 'EGP ' . number_format((float) $t['price_native_yearly'], 0);
            }
            if ($viewCur !== 'USD') {
                $meta = CurrencyService::CURRENCIES[$viewCur];
                $sym = ($isAr ? $meta['ar_symbol'] : $meta['symbol']) . ' ';
                // الباقة مسعّرة بعملة الزائر نفسها: المبلغ الأصلي بالظبط
                $m = $tierCur === $viewCur ? (float) $t['price_native_monthly'] : CurrencyService::fromUsd($m, $viewCur);
                $y = $tierCur === $viewCur ? (float) $t['price_native_yearly'] : CurrencyService::fromUsd($y, $viewCur);
            }
            $save = $m > 0 ? max(0, (int) round((1 - $y / ($m * 12)) * 100)) : 0;
            $n = static fn ($v) => $v < 0 ? '∞' : number_format((int) $v);
            $name = $isAr ? $t['name'] : $t['name_en'];
            $items = [
                [true, $n($t['accounts']) . ' ' . $this->t(['حساب سوشيال مربوط', 'connected accounts'])],
                [true, $n($t['posts']) . ' ' . $this->t(['منشور في الشهر', 'posts / month'])],
                [true, $n($t['ai_captions']) . ' ' . $this->t(['كابشن بالذكاء الاصطناعي', 'AI captions'])],
                [$t['monthly_plans'] != 0, ($t['monthly_plans'] < 0 ? '∞' : (int) $t['monthly_plans']) . ' ' . $this->t(['خطة شهرية بالذكاء الاصطناعي', 'AI monthly content plans'])],
                [$t['competitors'] > 0, ($t['competitors'] > 0 ? ((int) $t['competitors']) . ' ' : '') . $this->t(['منافسين على إنستجرام', 'Instagram competitors'])],
            ];
            foreach (SocialPackages::FEATURE_KEYS as $fk) {
                $items[] = [!empty($t[$fk]), SocialPackages::LABELS[$fk][$isAr ? 0 : 1]];
            }
            $li = '';
            $x = '<span class="ck"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 12h10"/></svg></span>';
            foreach ($items as [$on, $txt]) {
                $li .= '<li class="' . ($on ? '' : 'off') . '">' . ($on ? $ck : $x) . '<span>' . $this->e((string) $txt) . '</span></li>';
            }
            $actions = $loggedIn
                ? '<a href="/social#package" class="btn-primary">' . $this->te(['اشترك من حسابك', 'Subscribe from your account']) . '</a>'
                : '<a href="/register?src=social-pricing&social=' . rawurlencode($k) . '" class="btn-primary">' . $this->te(['ابدأ مجانًا', 'Start free']) . '</a>';
            $feat = $k === 'pro';
            $cards .= '<div class="plan' . ($feat ? ' featured' : '') . '" data-em="' . $this->e($egM) . '" data-ey="' . $this->e($egY) . '" data-m="' . $this->e(number_format($m, 0)) . '" data-y="' . $this->e(number_format($y, 0)) . '" data-ym="' . $this->e(number_format($y / 12, 0)) . '" data-save="' . $save . '">'
                . ($feat ? '<span class="badge">' . $this->te(['الأكثر اختيارًا', 'Most popular']) . '</span>' : '')
                . '<h3>' . $this->e($name) . '</h3>'
                . '<div class="price"><b><span class="cur">' . $this->e($sym) . '</span><span class="amt">' . $this->e(number_format($m, 0)) . '</span></b><small class="unit">' . $this->te(['/ شهر', '/ month']) . '</small></div>'
                . ($egM !== '' ? '<div class="egp" style="font-size:12.5px;opacity:.7;margin-top:2px">≈ <bdi>' . $this->e($egM) . '</bdi></div>' : '')
                . '<div class="per">&nbsp;</div><ul>' . $li . '</ul><div class="actions">' . $actions . '</div></div>';
        }
        if ($cards === '') {
            return '';
        }
        return '<section class="blk" id="social"><div class="sec-head"><h2 class="sec-title">' . $this->te(['باقات السوشيال ميديا', 'Social media plans']) . '</h2><p class="sec-sub">'
            . $this->te(['انشر وجدول على فيسبوك وإنستجرام وتيك توك ويوتيوب، ورد على التعليقات، وتابع النتائج من مكان واحد. باقة مستقلة بتتدفع من محفظتك، أو مشمولة في باقة المنصة.', 'Publish and schedule on Facebook, Instagram, TikTok and YouTube, reply to comments and track results in one place. A standalone plan paid from your wallet, or included in your platform plan.'])
            . '</p></div><div class="plans">' . $cards . '</div><p style="text-align:center;margin-top:16px"><a href="/social-check" class="cta-ghost">'
            . $this->te(['افحص حضورك على فيسبوك وإنستجرام مجانًا', 'Check your Facebook and Instagram presence for free']) . '</a></p></section>';
    }

    /** محتوى الرئيسية الجديدة: كل اللي فيها ميزة حقيقية في المنصة، والأمثلة شكل الشغل الفعلي (مش نتايج عملاء) */
    /**
     * «افحص موقعك مجانًا»: الزائر يكتب رابط موقعه ويشوف في ثواني إيه اللي بيضيّع عليه حجوزات - من غير تسجيل.
     * الفحص نفسه في FreeSiteCheckService (POST /api/public/free-check).
     */
    private function homeFreeCheck(): string
    {
        $t = fn ($p) => $this->te($p);
        $ar = MarketingContent::isAr();
        $L = json_encode([
            'checking' => $this->t(['بنفحص موقعك…', 'Checking your website…']),
            'score' => $this->t(['درجة جاهزية موقعك للحجز', 'Booking-readiness score']),
            'top' => $this->t(['أهم حاجات بتضيّع عليك حجوزات', 'What is costing you bookings']),
            'all' => $this->t(['كل الفحوصات', 'All checks']),
            'fix' => $this->t(['الحل: ', 'Fix: ']),
            'cta' => $this->t(['صلّح ده مع Tourfecto - جرّب 3 أيام مجانًا', 'Fix this with Tourfecto - try 3 days free']),
            'nocard' => $this->t(['من غير كارت · إلغاء في أي وقت', 'No card · cancel any time']),
            'passed' => $this->t(['فحص ناجح من', 'checks passed out of']),
            'great' => $this->t(['موقعك في حالة كويسة. Tourfecto تقدر تجيبلك حجوزات أكتر بالردود على التقييمات والشات والسيو كل أسبوع.', 'Your website is in good shape. Tourfecto can bring you more bookings with review replies, chat and weekly SEO.']),
            'err' => $this->t(['حصلت مشكلة، جرّب تاني', 'Something went wrong, try again']),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return '<section class="hv-sec hv-check" id="free-check" style="padding-top:28px">'
            . '<div style="max-width:860px;margin:0 auto;border:1px solid var(--line,#E5E9F0);background:var(--panel,#fff);border-radius:var(--hv-r);padding:26px 22px">'
            . '<h2 class="hv-h" style="margin-top:0">' . $t(['افحص موقع شركتك مجانًا', 'Check your tour website for free']) . '</h2>'
            . '<p class="hv-s" style="margin-bottom:16px">' . $t(['في أقل من دقيقة: هل السائح هيلاقيك، ويثق فيك، ويحجز من غير ما يتوه؟ من غير تسجيل.', 'In under a minute: will travellers find you, trust you and book without getting lost? No sign-up needed.']) . '</p>'
            . '<form id="fcForm" style="display:flex;gap:10px;flex-wrap:wrap" novalidate>'
            . '<input id="fcUrl" type="text" inputmode="url" autocomplete="url" dir="ltr" required placeholder="yourtours.com" aria-label="' . $t(['رابط موقعك', 'Your website address']) . '" style="flex:1;min-width:220px;padding:14px 16px;border-radius:14px;border:1px solid var(--line,#D5DBE5);font-size:16px;background:transparent;color:inherit">'
            . '<button type="submit" class="hv-btn gold" id="fcBtn" style="border:0;cursor:pointer;font-family:inherit">' . $t(['افحص موقعي', 'Check my website']) . '</button></form>'
            . '<p id="fcErr" role="alert" style="color:#D92D20;font-size:14px;min-height:18px;margin:8px 0 0"></p>'
            . '<div id="fcOut" aria-live="polite"></div>'
            . '<p style="margin:14px 0 0;font-size:14px"><a href="/social-check">' . $t(['وافحص كمان حضورك على فيسبوك وإنستجرام ←', 'Also check your Facebook and Instagram presence →']) . '</a></p></div></section>'
            . '<script>(function(){var L=' . $L . ',f=document.getElementById("fcForm");if(!f)return;'
            . 'function esc(s){return String(s).replace(/[&<>"\']/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","\'":"&#39;"}[c];});}'
            . 'var col={pass:"#0E9F6E",warn:"#D97706",fail:"#D92D20"},ic={pass:"✓",warn:"!",fail:"✕"};'
            . 'function row(c,big){return \'<div style="display:flex;gap:12px;padding:\'+(big?"14px":"10px")+\' 0;border-bottom:1px solid var(--line,#E5E9F0)"><span style="flex:none;width:26px;height:26px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:800;color:#fff;background:\'+col[c.status]+\'">\'+ic[c.status]+\'</span><div><b>\'+esc(c.title)+\'</b><div style="opacity:.8;font-size:14px;line-height:1.7">\'+esc(c.detail)+\'</div>\'+(c.status!=="pass"?\'<div style="font-size:14px;line-height:1.7"><b>\'+esc(L.fix)+\'</b>\'+esc(c.fix)+\'</div>\':"")+\'</div></div>\';}'
            . 'f.addEventListener("submit",async function(e){e.preventDefault();var u=document.getElementById("fcUrl").value.trim(),b=document.getElementById("fcBtn"),er=document.getElementById("fcErr"),o=document.getElementById("fcOut");er.textContent="";if(!u){document.getElementById("fcUrl").focus();return;}'
            . 'var bt=b.textContent;b.disabled=true;b.textContent=L.checking;o.innerHTML="";'
            . 'try{var r=await fetch("/api/public/free-check",{method:"POST",headers:{"Content-Type":"application/json","Accept":"application/json"},body:JSON.stringify({url:u,lang:"' . ($ar ? 'ar' : 'en') . '"})});var d=await r.json();'
            . 'if(!d.success){er.textContent=d.error||L.err;}else{var x=d.data,sc=x.score,cc=sc>=75?col.pass:(sc>=50?col.warn:col.fail);'
            . 'var top=x.checks.filter(function(c){return x.top.indexOf(c.key)!==-1;});top.sort(function(a,b){return x.top.indexOf(a.key)-x.top.indexOf(b.key);});'
            . 'var h=\'<div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;margin:18px 0 6px"><div style="width:92px;height:92px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:30px;font-weight:800;border:6px solid \'+cc+\';color:\'+cc+\'">\'+sc+\'</div><div><div style="opacity:.8">\'+esc(L.score)+\'</div><b dir="ltr" style="font-size:18px">\'+esc(x.host)+\'</b><div style="opacity:.8;font-size:14px">\'+x.passed+" "+esc(L.passed)+" "+x.total+\'</div></div></div>\';'
            . 'h+=top.length?\'<h3 style="margin:18px 0 4px">\'+esc(L.top)+\'</h3>\'+top.map(function(c){return row(c,true);}).join(""):\'<p style="line-height:1.8">\'+esc(L.great)+\'</p>\';'
            . 'h+=\'<div style="text-align:center;margin:20px 0 6px"><a class="hv-btn gold" href="/register?site=\'+encodeURIComponent(x.host)+\'&src=free-check">\'+esc(L.cta)+\'</a><div style="opacity:.75;font-size:13px;margin-top:8px">\'+esc(L.nocard)+\'</div></div>\';'
            . 'h+=\'<details style="margin-top:14px"><summary style="cursor:pointer;font-weight:700">\'+esc(L.all)+\'</summary>\'+x.checks.map(function(c){return row(c,false);}).join("")+\'</details>\';'
            . 'o.innerHTML=h;o.scrollIntoView({behavior:"smooth",block:"nearest"});}}catch(e2){er.textContent=L.err;}b.disabled=false;b.textContent=bt;});})();</script>';
    }

    private function homeV2(string $faq, string $fromPrice): string
    {
        $t = fn ($p) => $this->te($p);
        $mark = '<svg class="tf-mark is-anim" viewBox="0 0 64 64" aria-hidden="true"><path class="tf-arc" d="M14.5 24.5 C23 18.5 41 18.5 49.5 24.5"/><path class="tf-stem" d="M32 21 V49.5"/><path class="tf-star" d="M50.5 8.5 l1.5 3.8 3.8 1.5 -3.8 1.5 -1.5 3.8 -1.5 -3.8 -3.8 -1.5 3.8 -1.5 z"/></svg>';

        $hero = '<div class="hv-hero"><div class="hv-hero-in"><div>'
            . '<span class="hv-eyebrow">' . $t(['منصة الذكاء الاصطناعي لشركات الرحلات والسياحة', 'The AI platform for tour & travel companies']) . '</span>'
            . '<h1>' . $t(['خلّي السياح', 'Get travellers to']) . ' <span class="g">' . $t(['يلاقوك ويحجزوا منك', 'find you and book direct']) . '</span> ' . $t(['من غير عمولة الوسطاء', 'without OTA commission']) . '</h1>'
            . '<p class="lead">' . $t(['Tourfecto بترد على تقييماتك بلغة كل سائح، وبتفحص صفحات رحلاتك وتصلّح ظهورك في جوجل، وبتشغّل موقع حجز ودفع وشات واتساب 24 ساعة. إنت بتوافق، والمنصة بتنفّذ.', 'Tourfecto replies to your reviews in every traveller’s language, audits your tour pages and fixes your Google visibility, and runs a booking site, payments and 24/7 WhatsApp chat. You approve, the platform executes.']) . '</p>'
            . '<div class="hv-ctas"><a href="/register" class="hv-btn gold">' . $t(['ابدأ مجانًا', 'Start free']) . '</a><a href="/demo" class="hv-btn ghost">' . $t(['اطلب عرض توضيحي', 'Book a demo']) . '</a></div>'
            . $fromPrice
            . '<div class="hv-trust"><span>' . $t(['من غير كارت عشان تبدأ', 'No card to start']) . '</span><span>' . $t(['عربي وإنجليزي', 'Arabic & English']) . '</span><span>' . $t(['إلغاء في أي وقت', 'Cancel any time']) . '</span></div>'
            . '</div>'
            // شكل الشغل الحقيقي: تقييم ألماني فيه شكوى ← رد بالألماني باسمه وبتوقيع الشركة، حجز مدفوع، وصحة السمعة
            . '<div class="hv-stage" aria-hidden="true">'
            . '<div class="hv-card c1"><div class="row"><span class="av">J</span><div><div class="who"><span dir="ltr">Jonas W.</span> <span class="stars">★★★★☆</span></div><div class="meta">TripAdvisor · ' . $t(['من 3 دقايق', '3 min ago']) . '</div></div></div>'
            . '<p class="rv" dir="ltr">Der Reiseleiter Ahmed war super! Leider war die Abholung 40 Minuten zu spät.</p>'
            . '<div class="hv-reply" dir="ltr"><span class="tag" dir="auto">' . $mark . $t(['رد جاهز بالألماني · مستني موافقتك', 'Reply ready in German · awaiting approval']) . '</span>'
            . '<span class="hv-typing">Hallo Jonas, schön, dass Ahmed Ihnen Luxor nähergebracht hat.</span><span class="hv-rest">Dass die Abholung zu spät war, hätte nicht passieren dürfen …</span><span class="sig">Das Tourfecto-Team</span></div></div>'
            . '<div class="hv-card c2"><div class="bk"><span class="ic">✓</span><div><b>' . $t(['حجز جديد من موقعك', 'New booking from your site']) . '</b><span class="meta">' . $t(['الأهرامات وأبو الهول · فردين · بكرة', 'Pyramids & Sphinx · 2 guests · tomorrow']) . '</span></div><span class="paid">' . $t(['مدفوع', 'Paid']) . '</span></div></div>'
            . '<div class="hv-card c3"><span class="hv-ring"><b>92</b></span><div><b>' . $t(['صحة السمعة', 'Reputation health']) . '</b><div class="meta">' . $t(['معدل الرد 100%', 'Reply rate 100%']) . '</div></div></div>'
            . '</div></div></div>';

        $logos = '<section class="hv-logos"><div class="lb">' . $t(['بتتربط بالأدوات اللي شغال عليها', 'Connects with the tools you already use']) . '</div>';
        foreach (['Google Business Profile', 'Search Console', 'Google Analytics', 'TripAdvisor', 'Facebook', 'Instagram', 'WhatsApp', 'Google Ads', 'Stripe', 'WordPress'] as $n) {
            $logos .= '<span>' . $this->e($n) . '</span>';
        }
        $logos .= '</section>';

        $ps = [
            [['السياح بيلاقوك على Viator وGetYourGuide وبتدفع عمولة على كل حجز.', 'Travellers find you on Viator and GetYourGuide, and you pay commission on every booking.'], ['حجز مباشر من موقعك', 'Direct bookings on your site'], ['موقع حجز جاهز بالتوفر الحقيقي ولينك دفع، والفلوس تنزل في حسابك.', 'A ready booking site with live availability and payment links, paid straight into your account.'], '/features/booking-website'],
            [['تقييم سلبي فضل أيام من غير رد، وكل اللي بيدوّروا عليك قروه.', 'A negative review sat unanswered for days, and everyone searching for you read it.'], ['رد خبير خلال دقايق', 'An expert reply within minutes'], ['تنبيه فوري، ورد بلغة السائح باسمه وبتوقيعك، من غير جدال ولا وعود فلوس قدام الناس.', 'An instant alert and a reply in the traveller’s language, by name and signed by you, never arguing in public.'], '/features/reviews-reputation'],
            [['صفحات رحلاتك مش ظاهرة في جوجل، ومحركات الذكاء الاصطناعي مش بتقترحك.', 'Your tour pages don’t show on Google, and AI assistants don’t recommend you.'], ['مركز سيو بيشتغل كل أسبوع', 'An SEO hub that works every week'], ['بيفحص كل صفحة رحلة: السعر والبرنامج والإلغاء والأسئلة، ويصلّح العناوين والروابط والـ Schema.', 'It audits every tour page - price, itinerary, cancellation, FAQs - and fixes titles, links and schema.'], '/features/google-visibility'],
        ];
        $psHtml = '';
        foreach ($ps as [$pr, $st, $sd, $href]) {
            $psHtml .= '<div class="card reveal"><div class="pr">' . $t($pr) . '</div><div class="sl"><b>' . $t($st) . '</b>' . $t($sd) . '</div><a href="' . $href . '">' . $t(['اعرف إزاي ←', 'See how →']) . '</a></div>';
        }

        $feats = '';
        foreach (MarketingContent::features() as $slug => $f) {
            $feats .= '<a href="/features/' . $slug . '" class="hv-feat reveal"><span class="fi">' . $this->icon($f['icon'], 20) . '</span><div><b>' . $this->te($f['title']) . '</b><span>' . $this->te($f['short']) . '</span></div></a>';
        }

        $langs = '';
        foreach (['Deutsch', 'English', 'Italiano', 'Français', 'Español', 'Türkçe', 'Русский', 'العربية', '中文', '日本語'] as $l) {
            $langs .= '<i>' . $this->e($l) . '</i>';
        }
        $show = '<div class="hv-show">'
            . '<div class="it reveal"><div class="txt"><h3>' . $t(['ردود التقييمات بمستوى خبير سمعة', 'Review replies at reputation-expert level']) . '</h3>'
            . '<p>' . $t(['كل تقييم على Google وTripAdvisor بيتقري ويترد عليه بالطريقة اللي الخبير بيعملها.', 'Every Google and TripAdvisor review is read and answered the way an expert would.']) . '</p><ul>'
            . '<li>' . $t(['بلغة السائح نفسه: ألماني، إيطالي، فرنساوي، روسي، صيني وغيرهم', 'In the traveller’s own language: German, Italian, French, Russian, Chinese and more']) . '</li>'
            . '<li>' . $t(['باسمه، ويذكر اللي هو قاله (اسم المرشد، المكان)، وبتوقيع شركتك', 'By name, mentioning what they said (guide, place), signed by your company']) . '</li>'
            . '<li>' . $t(['تنبيه «عاجل» لو التقييم فيه أمان أو فلوس، ومايتنشرش غير بموافقتك', 'An "urgent" alert for safety or money issues, never published without you']) . '</li>'
            . '<li>' . $t(['تشخيص للسمعة: العملاء بيحبوا إيه وبيشتكوا من إيه، بجمل من كلامهم', 'A reputation diagnosis: what guests love and complain about, in their own words']) . '</li>'
            . '</ul><a class="more" href="/features/reviews-reputation">' . $t(['شوف التفاصيل ←', 'See details →']) . '</a></div>'
            . '<div class="hv-shot"><div class="ttl">' . $mark . $t(['بيرد بـ 15 لغة', 'Replies in 15 languages']) . '</div><div class="hv-langs">' . $langs . '</div>'
            . '<div class="ln"><span class="k">' . $t(['التقييم', 'Review']) . '</span><span>★★☆☆☆ · ' . $t(['الاستلام اتأخر', 'Late pickup']) . '</span></div>'
            . '<div class="ln"><span class="k">' . $t(['التنبيه', 'Alert']) . '</span><span class="no">' . $t(['اتبعت على الإيميل فورًا', 'Emailed instantly']) . '</span></div>'
            . '<div class="ln"><span class="k">' . $t(['الرد', 'Reply']) . '</span><span class="ok">' . $t(['جاهز، مستني موافقتك', 'Ready, awaiting approval']) . '</span></div></div></div>'

            . '<div class="it reveal"><div class="txt"><h3>' . $t(['مركز سيو بيفهم صفحات الرحلات', 'An SEO hub that understands tour pages']) . '</h3>'
            . '<p>' . $t(['بيفحص الموقع كل أسبوع، ويصلّح اللي ينفع يتصلّح لوحده، ويقولك بالظبط الباقي.', 'It crawls your site weekly, fixes what it can automatically and tells you exactly what is left.']) . '</p><ul>'
            . '<li>' . $t(['مراجعة كل صفحة رحلة زي ما السائح بيقارنها بـ GetYourGuide', 'Checks every tour page the way travellers compare it with GetYourGuide']) . '</li>'
            . '<li>' . $t(['Schema الرحلة (TouristTrip) والروابط بين الرحلات والصور من غير وصف', 'Trip schema (TouristTrip), links between tours and image descriptions']) . '</li>'
            . '<li>' . $t(['الصفحات اللي بتنافس بعض على نفس البحث، وربط نسخ اللغات (hreflang)', 'Pages competing for the same search, and language versions (hreflang)']) . '</li>'
            . '<li>' . $t(['بيانات حقيقية من Search Console والسرعة من جوجل، ومقالات للكلمات اللي بتجيب حجوزات', 'Real Search Console data, Google speed, and articles for keywords that bring bookings']) . '</li>'
            . '</ul><a class="more" href="/features/google-visibility">' . $t(['شوف التفاصيل ←', 'See details →']) . '</a></div>'
            . '<div class="hv-shot"><div class="ttl">' . $mark . $t(['مراجعة صفحة رحلة', 'Tour page check']) . '</div>'
            . '<div class="ln"><span class="k">' . $t(['السعر والمدة', 'Price & duration']) . '</span><span class="ok">✓</span></div>'
            . '<div class="ln"><span class="k">' . $t(['البرنامج والمشمول', 'Itinerary & inclusions']) . '</span><span class="ok">✓</span></div>'
            . '<div class="ln"><span class="k">' . $t(['سياسة الإلغاء', 'Cancellation policy']) . '</span><span class="no">' . $t(['ناقصة', 'Missing']) . '</span></div>'
            . '<div class="ln"><span class="k">' . $t(['أسئلة شائعة', 'FAQs']) . '</span><span class="no">' . $t(['ناقصة', 'Missing']) . '</span></div>'
            . '<div class="ln"><span class="k">Schema TouristTrip</span><span class="ok">' . $t(['اتضاف لوحده', 'Added automatically']) . '</span></div></div></div>'

            . '<div class="it reveal"><div class="txt"><h3>' . $t(['حجز ودفع وشات من غير عمولة', 'Bookings, payments and chat without commission']) . '</h3>'
            . '<p>' . $t(['من أول ما السائح يسأل لحد ما يرجع يكتب تقييم.', 'From the traveller’s first question to their review after the trip.']) . '</p><ul>'
            . '<li>' . $t(['موقع حجز بكذا لغة بالتوفر الحقيقي ولينكات دفع', 'A multilingual booking site with live availability and payment links']) . '</li>'
            . '<li>' . $t(['شات ذكي 24 ساعة على الموقع والواتساب، بيعرف رحلاتك وأسعارك', '24/7 AI chat on your site and WhatsApp that knows your tours and prices']) . '</li>'
            . '<li>' . $t(['تذكير قبل كل رحلة، وطلب تقييم بعدها', 'A reminder before every trip and a review request after it']) . '</li>'
            . '<li>' . $t(['CRM ومتابعة تلقائية للي سأل وماحجزش', 'CRM and automatic follow-up for those who asked but didn’t book']) . '</li>'
            . '</ul><a class="more" href="/features/bookings-payments">' . $t(['شوف التفاصيل ←', 'See details →']) . '</a></div>'
            . '<div class="hv-shot"><div class="ttl">' . $mark . $t(['يوم شغل عادي', 'A normal day']) . '</div>'
            . '<div class="ln"><span class="k">08:00</span><span>' . $t(['ملخص الصبح: 6 رحلات النهارده', 'Morning brief: 6 trips today']) . '</span></div>'
            . '<div class="ln"><span class="k">11:40</span><span>' . $t(['الشات رد على 9 أسئلة', 'The chat answered 9 questions']) . '</span></div>'
            . '<div class="ln"><span class="k">14:15</span><span class="ok">' . $t(['حجز مدفوع من الموقع', 'Paid booking from the site']) . '</span></div>'
            . '<div class="ln"><span class="k">20:00</span><span>' . $t(['تذكير اتبعت لضيوف بكرة', 'Reminder sent to tomorrow’s guests']) . '</span></div></div></div>'
            . '</div>';

        $steps = '<div class="hv-steps">'
            . '<div class="reveal"><b>' . $t(['سجّل واربط حساباتك', 'Sign up and connect']) . '</b><span>' . $t(['Google وTripAdvisor وواتساب وموقعك، بخطوات واضحة ومن غير مبرمج.', 'Google, TripAdvisor, WhatsApp and your site, with clear steps and no developer.']) . '</span></div>'
            . '<div class="reveal"><b>' . $t(['المنصة بتفحص وتجهّز', 'The platform audits and prepares']) . '</b><span>' . $t(['ردود، خطة سيو، مهام لصفحات الرحلات، وتنبيهات - كلها جاهزة.', 'Replies, an SEO plan, tour-page tasks and alerts - all ready.']) . '</span></div>'
            . '<div class="reveal"><b>' . $t(['إنت بتوافق والمنصة بتنفّذ', 'You approve, it executes']) . '</b><span>' . $t(['تقدر تخلي الحاجات الآمنة أوتوماتيك، وتراجع الباقي بضغطة.', 'Let the safe things run automatically and review the rest in one click.']) . '</span></div>'
            . '</div><div class="hv-facts">'
            . '<div class="reveal"><b>15</b><span>' . $t(['لغة للرد على التقييمات', 'languages for review replies']) . '</span></div>'
            . '<div class="reveal"><b>24/7</b><span>' . $t(['الشات بيرد على عملاءك', 'chat answering your guests']) . '</span></div>'
            . '<div class="reveal"><b>+30</b><span>' . $t(['مهمة تلقائية في الخلفية', 'automated background jobs']) . '</span></div>'
            . '<div class="reveal"><b dir="ltr">AR · EN</b><span>' . $t(['المنصة بالعربي والإنجليزي', 'platform in Arabic and English']) . '</span></div>'
            . '</div>';

        return '<div class="hv2">' . $hero . $this->homeFreeCheck() . $logos
            . '<section class="hv-sec"><h2 class="hv-h">' . $t(['3 مشاكل بتاكل حجوزات أي شركة رحلات', '3 problems that eat every tour company’s bookings']) . '</h2><p class="hv-s">' . $t(['ودي الطريقة اللي Tourfecto بتحلها بيها.', 'And how Tourfecto solves them.']) . '</p><div class="hv-ps">' . $psHtml . '</div></section>'
            . '<section class="hv-sec" style="padding-top:20px"><h2 class="hv-h">' . $t(['شوف المنصة بتشتغل إزاي', 'See how the platform works']) . '</h2><p class="hv-s">' . $t(['أمثلة من شكل الشغل الحقيقي جوه Tourfecto.', 'Examples of what the real work looks like inside Tourfecto.']) . '</p>' . $show . '</section>'
            . '<section class="hv-sec" style="padding-top:20px"><h2 class="hv-h">' . $t(['كل أدوات شركة الرحلات في مكان واحد', 'Every tool a tour company needs, in one place']) . '</h2><p class="hv-s">' . $t(['اختار اللي يهمك واعرف بيشتغل إزاي.', 'Pick what matters to you and see how it works.']) . '</p><div class="hv-feats">' . $feats . '</div></section>'
            . '<section class="hv-sec" style="padding-top:20px"><h2 class="hv-h">' . $t(['تبدأ في 3 خطوات', 'Start in 3 steps']) . '</h2><p class="hv-s">' . $t(['ومساعدة جوه المنصة في كل خطوة.', 'With in-app guidance at every step.']) . '</p>' . $steps . '</section>'
            . $this->homeCompany()
            . '<section class="hv-sec" style="padding-top:20px"><h2 class="hv-h">' . $t(['أسئلة شائعة', 'Frequently asked questions']) . '</h2><div class="hv-faq">' . $faq . '</div></section>'
            . '</div>' . PublicLayout::ctaBand();
    }

    /** أسئلة إضافية للرئيسية - نفس الإجابات اللي محركات البحث والذكاء الاصطناعي بتقراها من FAQPage */
    private function homeFaqExtra(): array
    {
        $email = PublicLayout::company()['email'];
        return [
            [['يعني إيه Tourfecto؟', 'What is Tourfecto?'], ['Tourfecto منصة بالذكاء الاصطناعي لشركات الرحلات والسياحة ووكالات السفر والفنادق. بتجمع في مكان واحد: الرد على التقييمات، مركز السيو، موقع حجز بالدفع أونلاين، شات ذكي على الموقع والواتساب، التسويق، مراقبة المنافسين، وCRM.', 'Tourfecto is an AI platform for tour operators, travel agencies and hotels. It brings together review replies, an SEO hub, a booking website with online payments, AI chat on the website and WhatsApp, marketing, competitor monitoring and a CRM.']],
            [['المنصة مناسبة لمين؟', 'Who is it for?'], ['لشركات الرحلات اليومية والجولات، وكالات السفر، الفنادق وبيوت الضيافة، والوكالات اللي بتدير أكتر من شركة - في أي دولة.', 'Day-tour and activity companies, travel agencies, hotels and guesthouses, and agencies that manage several businesses - in any country.']],
            [['بترد على التقييمات بكام لغة؟', 'In how many languages does it reply to reviews?'], ['المنصة بتكتشف لغة السائح وبترد بنفس لغته (15 لغة منها الإنجليزي والألماني والفرنساوي والإيطالي والإسباني والروسي والعربي)، بالاسم الأول للسائح وتوقيع فريقك، وبتنبهك فورًا على التقييمات السلبية الحرجة.', 'It detects the traveller’s language and replies in it (15 languages including English, German, French, Italian, Spanish, Russian and Arabic), using the guest’s first name and your team’s sign-off, and alerts you immediately about critical negative reviews.']],
            [['الذكاء الاصطناعي بينشر حاجة من غير ما أوافق؟', 'Does the AI publish anything without my approval?'], ['لأ. إنت اللي بتحدد: الحاجات الآمنة (زي الرد على تقييم 5 نجوم) ممكن تبقى تلقائية، والباقي بيستنى موافقتك. التقييمات الحرجة عمرها ما بتتنشر تلقائي.', 'No. You decide: safe actions (like replying to a 5-star review) can run automatically, everything else waits for your approval. Critical reviews are never answered automatically.']],
            [['بيتربط مع إيه؟', 'What does it integrate with?'], ['Google Business Profile، TripAdvisor، Booking.com، واتساب، فيسبوك وإنستجرام، WordPress، Google Search Console وGA4، Stripe، وموقعك الحالي.', 'Google Business Profile, TripAdvisor, Booking.com, WhatsApp, Facebook and Instagram, WordPress, Google Search Console and GA4, Stripe, and your existing website.']],
            [['مركز السيو بيعمل إيه بالظبط؟', 'What exactly does the SEO hub do?'], ['بيفحص صفحات رحلاتك صفحة صفحة (العنوان، الوصف، السعر، المدة، خط السير، الصور، بيانات Schema، والـ hreflang للغات)، وبيقارنك بمنافسينك، وبيطلعلك مهام مرتبة بالأولوية، وبيقيس النتيجة من Search Console.', 'It audits your tour pages one by one (title, description, price, duration, itinerary, images, Schema data and hreflang), compares you with competitors, gives you prioritised tasks and measures the results in Search Console.']],
            [['الأسعار كام؟', 'How much does it cost?'], ['فيه باقات شهرية وسنوية، وتقدر تجرب قبل ما تدفع. الأسعار كاملة في صفحة الأسعار وبتظهر بعملتك.', 'There are monthly and yearly plans, and you can try it before paying. Full prices are on the pricing page, shown in your currency.']],
            [['أتواصل معاكم إزاي؟', 'How do I contact you?'], ['ابعتلنا على ' . $email . ' أو من صفحة تواصل معنا، أو اطلب عرض توضيحي ونوريك المنصة على شغلك.', 'Email us at ' . $email . ', use the contact page, or book a demo and we’ll show you the platform on your own business.']],
        ];
    }

    /** زرار تفعيل الباقة بالواتساب والإيميل (رسالة جاهزة باسم الباقة) */
    private function activateLinks(string $planName, string $wa): string
    {
        $email = PublicLayout::company()['email'];
        $acct = !empty($this->user['email']) ? $this->t([' - إيميل حسابي: ', ' - my account email: ']) . $this->user['email'] : '';
        $msg = $this->t(['أهلاً، عايز أفعّل باقة "', 'Hi, I’d like to activate the "']) . $planName . $this->t(['" (شهري/سنوي). ابعتولي بيانات الدفع.', '" plan (monthly/yearly). Please send me the payment details.']) . $acct;
        $out = '';
        if ($wa !== '') {
            $out .= '<a href="https://wa.me/' . $this->e(preg_replace('/\D/', '', $wa)) . '?text=' . rawurlencode($msg) . '" target="_blank" rel="noopener" class="cta-ghost">💬 ' . $this->te(['فعّل عبر واتساب', 'Activate via WhatsApp']) . '</a>';
        }
        $subject = $this->t(['تفعيل باقة ', 'Activate plan: ']) . $planName;
        $out .= '<a href="mailto:' . $this->e($email) . '?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($msg) . '" class="cta-ghost">✉️ ' . $this->te(['فعّل بالإيميل', 'Activate by email']) . '</a>';
        return $out;
    }

    /** إزاي العميل يفعّل باقته من غير بوابة دفع */
    private function activationSteps(string $wa): string
    {
        $t = fn ($p) => $this->te($p);
        $email = $this->e(PublicLayout::company()['email']);
        $waTxt = $wa !== '' ? $t(['واتساب أو ', 'WhatsApp or ']) : '';
        $step = static fn (string $n, string $h, string $p) => '<div class="step-card"><div class="step-num">' . $n . '</div><h3>' . $h . '</h3><p>' . $p . '</p></div>';
        return '<section class="blk" id="activate"><div class="sec-head"><h2 class="sec-title">' . $t(['إزاي تفعّل باقتك', 'How to activate your plan']) . '</h2>'
            . '<p class="sec-sub">' . $t(['التفعيل بيتم مع فريقنا مباشرة - من غير بطاقة على الموقع.', 'Activation is done directly with our team - no card needed on the website.']) . '</p></div>'
            . '<div class="steps" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px">'
            . $step('1', $t(['ابعتلنا', 'Message us']), $waTxt . $t(['إيميل على ', 'email ']) . '<a href="mailto:' . $email . '" dir="ltr">' . $email . '</a>' . $t([' باسم الباقة والمدة (شهري أو سنوي) وإيميل حسابك.', ' with the plan, the billing cycle (monthly or yearly) and your account email.']))
            . $step('2', $t(['نبعتلك بيانات الدفع', 'We send payment details']), $t(['بنرد عليك بالإيميل/الحساب اللي تحوّل عليه المبلغ، والمبلغ بالظبط بعملتك.', 'We reply with the email/account to pay to and the exact amount in your currency.']))
            . $step('3', $t(['نفعّل الباقة', 'We activate your plan']), $t(['أول ما التحويل يوصل بنفعّل الباقة على حسابك ونبعتلك فاتورة.', 'As soon as the payment arrives we activate the plan on your account and send you an invoice.']))
            . '</div></section>';
    }

    /** عن Tourfecto وبيانات التواصل */
    private function homeCompany(): string
    {
        $t = fn ($p) => $this->te($p);
        return '<section class="hv-sec" style="padding-top:20px"><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px;align-items:start">'
            . '<div class="reveal" style="border:1px solid var(--line);border-radius:16px;padding:22px;background:var(--panel)"><h2 class="hv-h" style="margin:0 0 10px;text-align:start;font-size:24px">' . $t(['عن Tourfecto', 'About Tourfecto']) . '</h2>'
            . '<p style="color:var(--muted);line-height:1.8;margin:0 0 10px">' . $t(['Tourfecto منصة سحابية (SaaS) بالذكاء الاصطناعي متخصصة في شركات الرحلات والسياحة. هدفنا إن أي شركة رحلات - صغيرة أو كبيرة - يبقى عندها نفس أدوات الشركات الكبيرة: سمعة قوية، ظهور في جوجل والذكاء الاصطناعي، وحجوزات مباشرة من غير عمولة الوسطاء.', 'Tourfecto is an AI-powered SaaS platform built for tour and travel companies. Our goal is to give every tour company - small or large - the tools big players have: a strong reputation, visibility on Google and AI assistants, and direct bookings without middleman commission.']) . '</p>'
            . '<p style="margin:0"><a href="/about">' . $t(['اعرف أكتر عنّا ←', 'More about us →']) . '</a></p></div>'
            . '<div class="reveal" style="border:1px solid var(--line);border-radius:16px;padding:22px;background:var(--panel)"><h2 class="hv-h" style="margin:0 0 10px;text-align:start;font-size:24px">' . $t(['تواصل معانا', 'Contact us']) . '</h2>'
            . PublicLayout::contactLines()
            . '<div style="display:flex;gap:10px;flex-wrap:wrap"><a class="btn-primary sm" href="/demo">' . $t(['اطلب عرض توضيحي', 'Book a demo']) . '</a><a class="nav-login" href="/contact">' . $t(['ابعتلنا رسالة', 'Send a message']) . '</a></div></div>'
            . '</div></section>';
    }

    // ================================================================== المميزات

    /** GET /features */
    public function features(array $params = []): array
    {
        $cards = '';
        foreach (MarketingContent::features() as $slug => $f) {
            $bul = '';
            foreach (array_slice($f['capabilities'], 0, 3) as [$ct]) {
                $bul .= '<li>' . $this->te($ct) . '</li>';
            }
            $cards .= '<a href="/features/' . $slug . '" class="feat-card reveal"><span class="fi lg tone-' . $f['tone'] . '">' . $this->icon($f['icon'], 25) . '</span>'
                . '<h3>' . $this->te($f['title']) . '</h3><p>' . $this->te($f['short']) . '</p><ul>' . $bul . '</ul>'
                . '<span class="more">' . $this->te(['اعرف أكتر ←', 'Learn more →']) . '</span></a>';
        }
        $body = PublicLayout::hero($this->t(['المميزات', 'Features']), $this->t(['كل أدوات شركة الرحلات في منصة واحدة', 'Every tool a tour company needs, in one platform']),
            $this->t(['من الحجز والدفع للشات والتقييمات والتسويق - اختار الجزء اللي يهمك واعرف بيشتغل إزاي.', 'From bookings and payments to chat, reviews and marketing - pick what matters to you and see how it works.']),
            '<a href="/register" class="btn-primary">' . $this->te(['ابدأ مجانًا', 'Start free']) . '</a><a href="/pricing" class="cta-ghost">' . $this->te(['شوف الأسعار', 'See pricing']) . '</a>')
            . '<section class="blk tight"><div class="feat-grid">' . $cards . '</div></section>' . PublicLayout::ctaBand();
        $this->out(PublicLayout::render([
            'title' => $this->t(['المميزات', 'Features']),
            'description' => $this->t(['كل مميزات Tourfecto لشركات الرحلات: الحجوزات والدفع، موقع حجز، شات ذكي، تذكير الرحلات، التقييمات، SEO، التسويق، المنافسين، و CRM.', 'All Tourfecto features for tour companies: bookings & payments, booking website, AI chat, trip reminders, reviews, SEO, marketing, competitors and CRM.']),
            'path' => '/features', 'active' => 'features', 'body' => $body,
            'jsonld' => [PublicLayout::breadcrumb([[$this->t(['الرئيسية', 'Home']), '/'], [$this->t(['المميزات', 'Features']), '/features']])],
        ]));
        return [];
    }

    /** GET /features/{slug} */
    public function feature(array $params = []): array
    {
        $slug = (string) ($params['slug'] ?? '');
        $all = MarketingContent::features();
        if (!isset($all[$slug])) {
            return $this->notFound();
        }
        $f = $all[$slug];
        $ck = self::check();
        $registerUrl = self::serviceRegisterUrl($slug);
        $demoUrl = '/demo?interest=' . rawurlencode($slug) . '&lang=' . MarketingContent::lang();

        $preview = '';
        foreach (array_slice($f['capabilities'], 0, 4) as [$ct, $cd]) {
            $preview .= '<li>' . $ck . '<div><b>' . $this->te($ct) . '</b><span>' . $this->te($cd) . '</span></div></li>';
        }
        $caps = '';
        foreach ($f['capabilities'] as [$ct, $cd]) {
            $caps .= '<div class="cap reveal"><h3>' . $this->te($ct) . '</h3><p>' . $this->te($cd) . '</p></div>';
        }
        $steps = '';
        foreach ($f['steps'] as $i => $st) {
            $steps .= '<div class="step-card"><div class="step-num">' . ($i + 1) . '</div><p>' . $this->te($st) . '</p></div>';
        }
        $faq = '';
        $faqLd = [];
        foreach ($f['faq'] as [$q, $a]) {
            $faq .= '<details class="faq-item"><summary>' . $this->te($q) . '</summary><p>' . $this->te($a) . '</p></details>';
            $faqLd[] = ['@type' => 'Question', 'name' => $this->t($q), 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $this->t($a)]];
        }
        // مميزات قريبة: اللي بعدها في الترتيب (بيلف)
        $keys = $f['details']['related'];
        $rel = '';
        for ($i = 1; $i <= 3; $i++) {
            $k = $keys[$i - 1];
            $rel .= '<a href="/features/' . $k . '" class="rel-card"><span class="fi tone-' . $all[$k]['tone'] . '">' . $this->icon($all[$k]['icon'], 18) . '</span>' . $this->te($all[$k]['title']) . '</a>';
        }
        $wa = function_exists('support_whatsapp_number') ? support_whatsapp_number() : '';
        $ask = $wa ? '<a href="https://wa.me/' . $this->e($wa) . '?text=' . rawurlencode($this->t(['أهلاً، عندي سؤال عن ', 'Hi, I have a question about ']) . $this->t($f['title'])) . '" target="_blank" rel="noopener" class="cta-ghost">' . $this->te(['اسألنا على واتساب', 'Ask us on WhatsApp']) . '</a>'
            : '<a href="' . $this->e($demoUrl) . '" class="cta-ghost">' . $this->te(['اطلب عرض توضيحي', 'Book a demo']) . '</a>';

        $body = '<section class="f-hero"><div>'
            . '<nav class="crumbs" aria-label="breadcrumb"><a href="/features">' . $this->te(['المميزات', 'Features']) . '</a><span aria-hidden="true">/</span><span>' . $this->te($f['title']) . '</span></nav>'
            . '<div class="f-kicker"><span class="fi tone-' . $f['tone'] . '">' . $this->icon($f['icon'], 18) . '</span>' . $this->te($f['title']) . '</div>'
            . '<h1>' . $this->te($f['tagline']) . '</h1><p class="sub">' . $this->te($f['short']) . '</p>'
            . '<div class="hero-ctas"><a href="' . $this->e($registerUrl) . '" class="btn-primary">' . $this->te(['جرّبها مجانًا', 'Try it free']) . '</a>' . $ask . '</div></div>'
            . '<div class="f-preview" aria-hidden="true"><div class="f-preview-head"><span class="dots"><i></i><i></i><i></i></span>' . $this->te($f['title']) . '</div><ul>' . $preview . '</ul></div>'
            . '</section>'
            . '<section class="blk alt"><div class="ps-grid"><div class="ps-card"><h3>' . $this->te(['المشكلة', 'The problem']) . '</h3><p>' . $this->te($f['problem']) . '</p></div>'
            . '<div class="ps-card sol"><h3>' . $this->te(['الحل مع Tourfecto', 'How Tourfecto solves it']) . '</h3><p>' . $this->te($f['solution']) . '</p></div></div></section>'
            . '<section class="blk"><div class="sec-head"><h2 class="sec-title">' . $this->te(['هتقدر تعمل إيه', 'What you can do']) . '</h2></div><div class="cap-grid">' . $caps . '</div></section>'
            . '<section class="blk alt"><div class="sec-head"><h2 class="sec-title">' . $this->te(['بتشتغل إزاي', 'How it works']) . '</h2></div><div class="steps3">' . $steps . '</div></section>'
            . $this->serviceSections($slug, $f)
            . $this->planAvailability($slug)
            . ($faq ? '<section class="blk"><div class="sec-head"><h2 class="sec-title">' . $this->te(['أسئلة عن الميزة دي', 'Questions about this feature']) . '</h2></div><div class="faq-list">' . $faq . '</div></section>' : '')
            . '<section class="blk tight"><div class="sec-head" style="margin-bottom:18px"><h2 class="sec-title" style="font-size:22px">' . $this->te(['مميزات تانية', 'More features']) . '</h2></div><div class="related">' . $rel . '</div></section>'
            . '<section class="blk service-final"><h2>' . $this->te(['ابدأ بالخدمة اللي اخترتها','Start with your chosen service']) . '</h2><div class="hero-ctas"><a class="btn-primary" href="' . $this->e($registerUrl) . '">' . $this->te(['ابدأ إعداد حسابك','Set up your account']) . '</a><a class="cta-ghost" href="' . $this->e($demoUrl) . '">' . $this->te(['اطلب عرضًا لهذه الخدمة','Request a demo of this service']) . '</a></div></section>';

        $ld = [PublicLayout::breadcrumb([[$this->t(['الرئيسية', 'Home']), '/'], [$this->t(['المميزات', 'Features']), '/features'], [$this->t($f['title']), '/features/' . $slug]])];
        if ($faqLd) {
            $ld[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $faqLd];
        }
        $this->out(PublicLayout::render([
            'title' => $this->t($f['title']),
            'description' => $this->t($f['short']) . ' ' . $this->t($f['tagline']),
            'serviceRegisterUrl' => $registerUrl,
            'head' => '<link rel="stylesheet" href="/assets/css/service-details.css?v=20261010"><script src="/assets/js/service-details.js?v=20261010" defer></script>',
            'path' => '/features/' . $slug, 'active' => 'features', 'body' => $body, 'jsonld' => $ld,
        ]));
        return [];
    }

    public static function serviceRegisterUrl(string $slug): string
    {
        if (!isset(MarketingContent::features()[$slug])) return '/register';
        return '/register?feature=' . rawurlencode($slug) . '&redirect=' . rawurlencode('/onboarding?feature=' . $slug) . '&lang=' . MarketingContent::lang();
    }

    private function serviceSections(string $slug, array $f): string
    {
        $d = $f['details'];
        $sample = '';
        foreach ($d['example'] as $i => $line) {
            $sample .= '<details class="service-example-step"' . ($i === 0 ? ' open' : '') . '><summary>' . $this->te(['الخطوة ', 'Step ']) . ($i + 1) . '</summary><p>' . $this->te($line) . '</p></details>';
        }
        $tools = '';
        $toolsData = ServicePageDetails::tools();
        foreach ($f['legacy'] as $old) {
            if (!isset($toolsData[$old])) continue;
            [$title, $description] = $toolsData[$old];
            $tools .= '<details id="service-' . $this->e($old) . '" class="service-tool"><summary>' . $this->te($title) . '</summary><p>' . $this->te($description) . '</p></details>';
        }
        $plan = '<p>' . $this->te(['راجع الباقات وحدود الاستخدام قبل الاشتراك. إتاحة الخدمة تعتمد على الباقة والصلاحيات والربط المطلوب؛ رسوم المزوّدين الخارجية ليست مشمولة تلقائيًا.', 'Review plans and usage limits before subscribing. Availability depends on plan, permissions and required connections; external provider fees are not automatically included.']) . '</p>';
        $plans = [];
        if (class_exists('SubscriptionPlan')) {
            try { $plans = SubscriptionPlan::allAsLegacyArray(); } catch (Throwable $e) { $plans = []; }
        }
        if ($plans) {
            $quotaKeys = [
                'ai-chat'=>['chat_credits'=>['رسائل AI / شهر','AI messages / month']],
                'reviews-reputation'=>['review_credits'=>['ردود التقييم / شهر','Review replies / month']],
                'google-visibility'=>['ai_analysis'=>['تحليلات AI / شهر','AI analyses / month']],
                'competitors'=>['competitor_analysis'=>['تحليلات المنافسين / شهر','Competitor analyses / month']],
                'agency-teams'=>['multiple_websites'=>['عدد المواقع','Website allowance']],
            ];
            $plan .= '<div class="service-plan-list">';
            foreach ($plans as $key => $p) {
                $plan .= '<article class="service-plan"><h3>' . $this->e($p['name'] ?? $key) . '</h3>';
                if (isset($p['price_monthly'], $p['currency']) && is_numeric($p['price_monthly'])) {
                    $plan .= '<p><bdi>' . $this->e(number_format((float) $p['price_monthly'], 2) . ' ' . $p['currency']) . '</bdi> ' . $this->te(['/ شهر', '/ month']) . '</p>';
                }
                foreach ($quotaKeys[$slug] ?? [] as $q => $label) {
                    $value = $p['features'][$q] ?? null;
                    $plan .= '<p>' . $this->te($label) . ': <bdi>' . (is_numeric($value) && (float) $value >= 0 ? $this->e($value) : $this->te(['أكد الحد مع الفريق', 'Confirm allowance with our team'])) . '</bdi></p>';
                }
                $plan .= '</article>';
            }
            $plan .= '</div><p>' . $this->te(['هذه أسعار وحدود الباقات المعروضة؛ راجع تفاصيل الباقة وإتاحة الأداة المطلوبة قبل اختيارها.', 'These are the displayed plan prices and allowances; confirm plan details and access to your required tool before choosing.']) . '</p>';
        }
        $plan .= '<a class="cta-ghost" href="/pricing?lang=' . MarketingContent::lang() . '">' . $this->te(['قارن الباقات وحدودها', 'Compare plans and allowances']) . '</a> <a class="cta-ghost" href="/demo?interest=' . $this->e($slug) . '">' . $this->te(['أكد الباقة المناسبة مع الفريق', 'Confirm the right plan with our team']) . '</a>';
        return '<section class="blk alt service-details" id="service-demo"><div class="sec-head"><h2 class="sec-title">' . $this->te(['شوف مثال الاستخدام', 'Explore a use-case example']) . '</h2><p class="sec-sub">' . $this->te(['معاينة توضيحية ببيانات افتراضية، وليست صورة من حساب عميل أو نتيجة فعلية. افتح الخطوات لاستكشافها.', 'An illustrative preview with sample data, not a customer screenshot or an actual result. Open the steps to explore.']) . '</p></div><div class="service-example">' . $sample . '</div></section>'
            . '<section class="blk service-details"><div class="ps-grid"><div class="ps-card"><h2>' . $this->te(['قبل ما تبدأ', 'Before you start']) . '</h2><p>' . $this->te($d['requirements']) . '</p></div><div class="ps-card"><h2>' . $this->te(['الحدود والتكاليف', 'Limits and costs']) . '</h2><p>' . $this->te($d['limits']) . '</p></div></div></section>'
            . ($tools ? '<section class="blk alt service-details"><h2>' . $this->te(['الأدوات داخل الخدمة', 'Tools within this service']) . '</h2><div class="service-tools">' . $tools . '</div></section>' : '')
            . '<section class="blk service-details"><h2>' . $this->te(['اختار الباقة المناسبة', 'Choose the right plan']) . '</h2>' . $plan . '</section>';
    }

    // ================================================================== الأسعار

    /** GET /pricing */
    /** خلية حالة مجموعة خدمات في باقة (من PlatformServiceRegistry::marketingMatrix) */
    private function planStateCell(?array $g): string
    {
        if ($g === null) return '<span class="no">—</span>';
        $full = in_array($g['worst'], ['core', 'included'], true);
        $best = in_array($g['best'], ['core', 'included'], true);
        if ($full) return '<span class="yes">✓</span>';
        if ($best && $g['worst'] === 'pay_per_use') return '<span class="yes">✓</span> <small>' . $this->te(['+ استخدام من المحفظة', '+ wallet usage']) . '</small>';
        if ($best) return '<span class="yes">✓</span> <small>' . $this->te(['جزئيًا', 'Partial']) . '</small>';
        if ($g['best'] === 'pay_per_use') return '<small>' . $this->te(['بالاستخدام', 'Pay per use']) . '</small>';
        return '<span class="no">—</span>';
    }

    /** /features/{slug}: الباقات اللي فيها الخدمة دي - نفس بيانات جدول الأسعار */
    private function planAvailability(string $slug): string
    {
        $plans = class_exists('SubscriptionPlan') ? SubscriptionPlan::allAsLegacyArray() : [];
        $groups = $plans ? PlatformServiceRegistry::marketingMatrix($plans) : [];
        if (!isset($groups[$slug])) return '';
        $cards = '';
        foreach ($plans as $key => $p) {
            $allowance = '';
            if ($slug === 'reviews-reputation') {
                $credits = $p['features']['review_credits'] ?? null;
                if (is_numeric($credits) && (float) $credits >= 0) {
                    $allowance = '<p>' . $this->te(['ردود مشمولة شهريًا: ', 'Included replies per month: ']) . $this->e($credits) . '</p>';
                    if ((float) $credits === 0.0) $allowance .= '<p>' . $this->te(['إتاحة الأداة لا تعني وجود ردود مشمولة. راجع التكلفة قبل الاستخدام.', 'Tool access does not include a reply allowance. Review costs before use.']) . '</p>';
                }
            }
            $cards .= '<div class="cap"><h3>' . $this->e(MarketingContent::planName($key, (string) $p['name'])) . '</h3><p>' . $this->planStateCell($groups[$slug]['plans'][$key] ?? null) . '</p>' . $allowance . '</div>';
        }
        return '<section class="blk" id="plans"><div class="sec-head"><h2 class="sec-title">' . $this->te(['متاحة في أنهي باقة؟', 'Which plans include it?']) . '</h2></div>'
            . '<div class="cap-grid">' . $cards . '</div><p style="text-align:center;margin-top:14px"><a class="cta-ghost" href="/pricing#services-by-plan">' . $this->te(['قارن كل الباقات', 'Compare all plans']) . '</a></p></section>';
    }

    /** مميزات كارت باقة السيو بلغة العميل (الأرقام من حدود الباقة نفسها) */
    private function seoPlanItems(array $f, callable $n): array
    {
        $sites = (int) ($f['multiple_websites'] ?? 1);
        $markets = (int) ($f['seo_markets'] ?? 1);
        $cycles = (int) ($f['seo_cycles'] ?? 1);
        return [
            [true, $sites > 1 ? $this->t(['حتى ', 'Up to ']) . $n($sites) . ' ' . $this->t(['مواقع', 'websites']) : $this->t(['موقع واحد', 'One website'])],
            [true, $markets > 1 ? $n($markets) . ' ' . $this->t(['أسواق ولغات (المحتوى بلغة كل سوق)', 'markets and languages (content in each market language)']) : $this->t(['سوق ولغة واحدة', 'One market and language'])],
            [true, $cycles >= 4 ? $this->t(['فحص وتحسين تلقائي كل أسبوع', 'Automatic check and fixes every week']) : ($cycles >= 2 ? $this->t(['فحص وتحسين تلقائي كل أسبوعين', 'Automatic check and fixes every two weeks']) : $this->t(['فحص وتحسين تلقائي كل شهر', 'Automatic check and fixes every month']))],
            [true, $this->t(['لحد ', 'Up to ']) . $n(BillingPlanCatalog::effectiveSeoPages($f['seo_max_pages'] ?? 100)) . ' ' . $this->t(['صفحة في كل فحص', 'pages per check'])],
            [true, $n($f['articles'] ?? 0) . ' ' . $this->t(['مقالات في الشهر بلغة السوق', 'articles / month in the market language'])],
            [true, $this->t(['قياس ظهورك في ChatGPT و Gemini و Perplexity و Claude', 'Visibility tracking in ChatGPT, Gemini, Perplexity and Claude']) . ' (' . $n($f['ai_visibility'] ?? 0) . ' ' . $this->t(['قياس شهريًا', 'checks / month']) . ')'],
            [true, $this->t(['إرسال صفحاتك لجوجل و Bing و Yandex تلقائيًا', 'Pages sent to Google, Bing and Yandex automatically'])],
            [true, $n($f['ai_analysis'] ?? 0) . ' ' . $this->t(['تقارير SEO/AEO/GEO كاملة مع المنافسين', 'full SEO/AEO/GEO reports with competitors'])],
            [!empty($f['advanced_analytics']), $this->t(['تقارير متقدمة لإدارة أكتر من عميل', 'Advanced reporting for managing several clients'])],
        ];
    }

    public function pricing(array $params = []): array
    {
        $plans = class_exists('SubscriptionPlan') ? SubscriptionPlan::allAsLegacyArray() : [];
        if (!$plans) {
            $this->out(PublicLayout::render(['title'=>$this->t(['الباقات','Plans']),'path'=>'/pricing','active'=>'pricing',
                'body'=>PublicLayout::hero($this->t(['الباقات','Plans']),$this->t(['الباقات غير متاحة حاليًا','Plans are currently unavailable']),$this->t(['تواصل معنا لتأكيد السعر والحدود قبل الاشتراك.','Contact us to confirm prices and allowances before subscribing.']),'<a class="btn-primary" href="/contact">'.$this->te(['تواصل معنا','Contact us']).'</a>')]));
        }

        // باقات خدمة السيو (seo-*) ليها قسم لوحدها؛ الكروت وجدول المقارنة الأساسي للباقات العامة بس
        $seoPlans = array_filter($plans, static fn ($k) => str_starts_with((string) $k, 'seo-'), ARRAY_FILTER_USE_KEY);
        $plans = array_diff_key($plans, $seoPlans);
        $seoCards = '';
        $ck = self::check();
        $x = '<span class="ck"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 12h10"/></svg></span>';
        $loggedIn = $this->isAuthenticated();
        $wa = function_exists('support_whatsapp_number') ? support_whatsapp_number() : '';
        $keys = array_keys($plans);
        $featuredKey = isset($plans['professional']) ? 'professional' : ($keys[(int) floor(count($keys) / 2)] ?? '');
        $maxSave = 0;

        require_once __DIR__ . '/../Services/Payment/CurrencyService.php';
        $viewCur = CurrencyService::forViewer($this->user ?? null);
        header('Vary: Cookie, Accept-Language'); // السعر بيختلف حسب عملة الزائر
        $isAr = $this->t(['ar', 'en']) === 'ar';
        $cards = '';
        foreach ($plans + $seoPlans as $key => $p) {
            $isSeo = isset($seoPlans[$key]);
            $f = $p['features'];
            $sym = $this->e($p['currency_symbol'] ?? '$');
            $m = (float) $p['price_monthly'];
            $y = (float) $p['price_yearly'];
            // عرض بعملة الزائر (الجنيه المصري مثلًا) محسوبة من سعر الدولار
            if ($viewCur !== 'USD' && ($p['currency'] ?? 'USD') === 'USD') {
                $meta = CurrencyService::CURRENCIES[$viewCur];
                $sym = $this->e(($isAr ? $meta['ar_symbol'] : $meta['symbol']) . ' ');
                $m = CurrencyService::fromUsd($m, $viewCur);
                $y = CurrencyService::fromUsd($y, $viewCur);
            }
            $save = $m > 0 ? max(0, (int) round((1 - $y / ($m * 12)) * 100)) : 0;
            $maxSave = max($maxSave, $save);
            $name = MarketingContent::planName($key, (string) $p['name']);
            $n = fn ($v) => '<bdi>' . number_format((int) $v) . '</bdi>';
            $sites = (int) $f['multiple_websites'];
            $items = [
                [true, $this->t(['حتى ', 'Up to ']) . $n($sites) . ' ' . ($sites > 1 ? $this->t(['مواقع', 'websites']) : $this->t(['موقع', 'website']))],
                [true, $n($f['chat_credits']) . ' ' . $this->t(['رسالة شات ذكي شهريًا', 'AI chat messages / month'])],
                [true, $n($f['review_credits']) . ' ' . $this->t(['رد ذكي على التقييمات', 'AI review replies / month'])],
                [true, $n($f['ai_analysis']) . ' ' . $this->t(['تحليل بالذكاء الاصطناعي', 'AI analyses / month'])],
                [true, $n($f['competitor_analysis']) . ' ' . $this->t(['تحليل منافسين', 'competitor analyses / month'])],
                [!empty($f['whatsapp_bot']), $this->t(['شات واتساب', 'WhatsApp chat'])],
                [!empty($f['auto_pilot']), !empty($f['auto_pilot']) ? $this->t(['الطيار الآلي: ردود وإجراءات من غير موافقة', 'Autopilot: replies and actions without approval']) : $this->t(['الردود بتستنى موافقتك', 'Replies wait for your approval'])],
                [!empty($f['advanced_analytics']), $this->t(['تحليلات متقدمة', 'Advanced analytics'])],
            ];
            if ($isSeo) {
                $items = $this->seoPlanItems($f, $n);
            }
            $li = '';
            foreach ($items as [$on, $txt]) {
                // النص فيه <bdi> بس - كل الباقي متعمله escape هنا
                $safe = preg_replace_callback('/<bdi>(.*?)<\/bdi>|([^<]+)/u', fn ($mm) => isset($mm[2]) && $mm[2] !== '' ? $this->e($mm[2]) : '<bdi>' . $this->e($mm[1]) . '</bdi>', $txt);
                $li .= '<li class="' . ($on ? '' : 'off') . '">' . ($on ? $ck : $x) . '<span>' . $safe . '</span></li>';
            }
            if ($loggedIn) {
                $actions = '<a href="/plans?plan=' . rawurlencode($key) . '" class="btn-primary">' . $this->te(['اشترك من حسابك', 'Subscribe from your account']) . '</a>';
            } else {
                $actions = '<a href="/register?plan=' . rawurlencode($key) . '" class="btn-primary">' . $this->te(['ابدأ مجانًا', 'Start free']) . '</a>';
            }
            // مفيش بوابة دفع: التفعيل بيتم بالتواصل، وإحنا بنرد بإيميل/حساب الدفع
            $actions .= $this->activateLinks($name, $wa);
            $feat = $key === ($isSeo ? 'seo-growth' : $featuredKey);
            // السعر بالجنيه جنب الدولار (شرط بنك الدفع)
            $egM = $viewCur === 'USD' ? CurrencyService::egpHint((float) $p['price_monthly'], $isAr) : '';
            $egY = $viewCur === 'USD' ? CurrencyService::egpHint((float) $p['price_yearly'], $isAr) : '';
            $cardHtml = '<div class="plan' . ($feat ? ' featured' : '') . '" data-em="' . $this->e($egM) . '" data-ey="' . $this->e($egY) . '" data-m="' . $this->e(number_format($m, 0)) . '" data-y="' . $this->e(number_format($y, 0)) . '" data-ym="' . $this->e(number_format($y / 12, 0)) . '" data-save="' . $save . '">'
                . ($feat ? '<span class="badge">' . $this->te(['الأكثر اختيارًا', 'Most popular']) . '</span>' : '')
                . '<h3>' . $this->e($name) . '</h3><p class="blurb">' . $this->e(MarketingContent::planBlurb($key)) . '</p>'
                . '<div class="price"><b><span class="cur">' . $sym . '</span><span class="amt">' . $this->e(number_format($m, 0)) . '</span></b><small class="unit">' . $this->te(['/ شهر', '/ month']) . '</small></div>'
                . ($egM !== '' ? '<div class="egp" style="font-size:12.5px;opacity:.7;margin-top:2px">≈ <bdi>' . $this->e($egM) . '</bdi></div>' : '')
                . '<div class="per">&nbsp;</div><ul>' . $li . '</ul><div class="actions">' . $actions . '</div></div>';
            if ($isSeo) {
                $seoCards .= $cardHtml;
            } else {
                $cards .= $cardHtml;
            }
        }
        $socialSection = $this->socialPlansSection($viewCur, $isAr, $loggedIn);
        $reputationSection = $this->reputationPlansSection($plans, $viewCur, $isAr, $loggedIn);
        $seoSection = $seoCards === '' ? '' : '<section class="blk alt" id="seo"><div class="sec-head"><h2 class="sec-title">' . $this->te(['باقات السيو والظهور في الذكاء الاصطناعي', 'SEO and AI visibility plans']) . '</h2><p class="sec-sub">'
            . $this->te(['المنصة بتفحص موقعك وتحسّنه لوحدها كل أسبوع، وبتقيس ظهورك في ChatGPT و Gemini و Perplexity. للي عايز خدمة السيو بس، من غير باقي المنصة.', 'The platform checks and improves your website on its own every week and measures your visibility in ChatGPT, Gemini and Perplexity. For companies that only want SEO, without the rest of the platform.'])
            . '</p></div><div class="plans">' . $seoCards . '</div><p style="text-align:center;font-size:13px;opacity:.75;margin-top:14px">'
            . $this->te(['بنفس سعر أداة تحليل واحدة، والفرق إن المنصة بتنفّذ التحسينات بنفسها. وأرخص بكتير من وكالة سيو.', 'For the price of a single analysis tool, except the platform applies the improvements itself, and far below an SEO agency retainer.']) . '</p></section>';
        if ($cards === '') {
            $cards = '<div class="empty-state" style="grid-column:1/-1"><b>' . $this->te(['الأسعار هتتعلن قريب', 'Pricing coming soon']) . '</b>' . $this->te(['كلمنا ونبعتلك العرض المناسب.', 'Contact us for a quote.']) . '</div>';
        }

        $incl = '';
        foreach (MarketingContent::includedEverywhere() as $it) {
            $incl .= '<div>' . $ck . $this->te($it) . '</div>';
        }

        // جدول المقارنة
        $cmp = '';
        if ($plans) {
            $head = '<th>' . $this->te(['الميزة', 'Feature']) . '</th>';
            foreach ($plans as $key => $p) {
                $head .= '<th>' . $this->e(MarketingContent::planName($key, (string) $p['name'])) . '</th>';
            }
            $rows = [
                [['المواقع', 'Websites'], 'multiple_websites'],
                [['رسايل الشات الذكي / شهر', 'AI chat messages / month'], 'chat_credits'],
                [['ردود التقييمات / شهر', 'Review replies / month'], 'review_credits'],
                [['تحليلات الذكاء الاصطناعي / شهر', 'AI analyses / month'], 'ai_analysis'],
                [['تحليلات المنافسين / شهر', 'Competitor analyses / month'], 'competitor_analysis'],
                [['شات واتساب', 'WhatsApp chat'], 'whatsapp_bot'],
                [['الطيار الآلي (من غير موافقة)', 'Autopilot (no approval needed)'], 'auto_pilot'],
                [['تحليلات متقدمة', 'Advanced analytics'], 'advanced_analytics'],
            ];
            $body = '';
            foreach ($rows as [$label, $k]) {
                $body .= '<tr><td>' . $this->te($label) . '</td>';
                foreach ($plans as $p) {
                    $v = $p['features'][$k] ?? null;
                    $body .= '<td class="v">' . (is_bool($v) ? ($v ? '<span class="yes">✓</span>' : '<span class="no">—</span>') : '<bdi>' . $this->e(number_format((int) $v)) . '</bdi>') . '</td>';
                }
                $body .= '</tr>';
            }
            foreach (array_slice(MarketingContent::includedEverywhere(), 0, 6) as $it) {
                $body .= '<tr><td>' . $this->te($it) . '</td>' . str_repeat('<td class="v"><span class="yes">✓</span></td>', count($plans)) . '</tr>';
            }
            $cmp = '<section class="blk alt"><div class="sec-head"><h2 class="sec-title">' . $this->te(['قارن الباقات', 'Compare plans']) . '</h2></div><div class="cmp-wrap"><table class="cmp"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div></section>';
        }

        // الخدمات في كل باقة - من السجل الموحّد (نفس اللي بيستخدمه مركز الخدمات جوه الحساب)
        $svc = '';
        if ($plans) {
            $groups = PlatformServiceRegistry::marketingMatrix($plans);
            $head = '<th>' . $this->te(['الخدمة', 'Service']) . '</th>';
            foreach ($plans as $key => $p) {
                $head .= '<th>' . $this->e(MarketingContent::planName($key, (string) $p['name'])) . '</th>';
            }
            $body = '';
            foreach (MarketingContent::features() as $slug => $f) {
                if (!isset($groups[$slug])) continue;
                $body .= '<tr><td><a href="/features/' . $this->e($slug) . '">' . $this->te($f['title']) . '</a></td>';
                foreach (array_keys($plans) as $key) {
                    $body .= '<td class="v">' . $this->planStateCell($groups[$slug]['plans'][$key] ?? null) . '</td>';
                }
                $body .= '</tr>';
            }
            $svc = '<section class="blk" id="services-by-plan"><div class="sec-head"><h2 class="sec-title">' . $this->te(['الخدمات في كل باقة', 'Services in every plan']) . '</h2>'
                . '<p class="sec-sub">' . $this->te(['لو استهلاكك عدّى حصة الباقة، رقّي لباقة أكبر أو كلمنا نزودلك الحصة.', 'If you go beyond your plan’s allowance, upgrade or contact us to add more.']) . '</p></div>'
                . '<div class="cmp-wrap"><table class="cmp"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div></section>';
        }

        $faq = '';
        $faqLd = [];
        foreach (MarketingContent::pricingFaq() as [$q, $a]) {
            $faq .= '<details class="faq-item"><summary>' . $this->te($q) . '</summary><p>' . $this->te($a) . '</p></details>';
            $faqLd[] = ['@type' => 'Question', 'name' => $this->t($q), 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $this->t($a)]];
        }
        $saveLbl = $maxSave > 0 ? '<span class="save">' . $this->e(sprintf($this->t(['وفّر حتى %d%%', 'save up to %d%%']), $maxSave)) . '</span>' : '';
        $i18n = json_encode([
            'month' => $this->t(['/ شهر', '/ month']), 'year' => $this->t(['/ سنة', '/ year']),
            'eq' => $this->t(['يعني %s شهريًا · وفّر %d%%', 'That’s %s / month · save %d%%']),
            'eq0' => $this->t(['يعني %s شهريًا', 'That’s %s / month']),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

        // تبويبات الخدمات: الباقات الشاملة (الأساسي)، السيو، السوشيال. كل تبويب لوحده عشان الصفحة ماتطولش
        $incSec = '<section class="blk"><div class="sec-head"><h2 class="sec-title">' . $this->te(['موجود في كل الباقات', 'Included in every plan']) . '</h2></div><div class="incl">' . $incl . '</div></section>';
        $plansHead = '<section class="blk tight" style="padding-top:0"><div class="sec-head"><h2 class="sec-title">' . $this->te(['الباقات الشاملة', 'All-in-one plans']) . '</h2><p class="sec-sub">'
            . $this->te(['باقة واحدة بتجمع خدمات المنصة الأساسية بحصص واضحة (الجدول تحت بيوضح كل خدمة داخلة في أنهي باقة). محتاج خدمة واحدة بس؟ اختار تبويبها فوق.', 'One plan that bundles the platform’s core services with clear quotas (the table below shows which service is in which plan). Need just one service? Pick its tab above.']) . '</p></div></section>';
        $tabDefs = [
            ['plans', $this->t(['الباقات الشاملة', 'All-in-one plans']), true],
            ['seo', $this->t(['السيو والذكاء الاصطناعي', 'SEO & AI visibility']), $seoSection !== ''],
            ['social', $this->t(['السوشيال ميديا', 'Social media']), $socialSection !== ''],
            ['reputation', $this->t(['السمعة والتقييمات', 'Reputation & reviews']), $reputationSection !== ''],
        ];
        $tabBtns = '';
        $tabCount = 0;
        foreach ($tabDefs as [$tid, $tlabel, $ton]) {
            if (!$ton) {
                continue;
            }
            $tabCount++;
            $tabBtns .= '<button type="button" role="tab" id="tabbtn-' . $tid . '" data-tab="' . $tid . '" aria-controls="tab-' . $tid . '" aria-selected="' . ($tid === 'plans' ? 'true' : 'false') . '" tabindex="' . ($tid === 'plans' ? '0' : '-1') . '">' . $this->e($tlabel) . '</button>';
        }
        $fold = fn (string $title, string $html) => $html === '' ? '' : '<details class="svc-fold"><summary>' . $title . '</summary>' . $html . '</details>';
        $foldCmp = $fold($this->te(['قارن الباقات بالتفصيل', 'Compare plans in detail']), $cmp);
        $foldSvc = $fold($this->te(['الخدمات في كل باقة', 'Services in every plan']), $svc);
        $panel = fn (string $tid, string $html) => '<div class="svc-panel" id="tab-' . $tid . '" role="tabpanel" aria-labelledby="tabbtn-' . $tid . '"' . ($tid === 'plans' ? '' : ' hidden') . '>' . $html . '</div>';
        $tabsBlock = ($tabCount > 1 ? '<style>.svc-wrap{display:flex;justify-content:center;padding:0 16px;margin:6px 0 26px}.svc-tabs{display:flex;gap:4px;padding:4px;border:1px solid var(--line);border-radius:12px;background:var(--panel);max-width:100%;flex-wrap:wrap;justify-content:center}'
                . '.svc-tabs button{flex:0 1 auto;border:0;background:none;font:inherit;font-size:14px;padding:10px 16px;border-radius:9px;color:var(--muted);cursor:pointer}.svc-fold>summary{cursor:pointer;list-style:none;text-align:center;font-weight:700;padding:14px 16px;margin:8px auto;max-width:560px;border:1px solid var(--line);border-radius:12px;background:var(--panel)}.svc-fold>summary::-webkit-details-marker{display:none}.svc-fold>summary::after{content:" ▾"}.svc-fold[open]>summary::after{content:" ▴"}'
                . '.svc-tabs button[aria-selected="true"]{background:var(--accent);color:var(--accent-ink)}.svc-tabs button:focus-visible{outline:2px solid var(--accent);outline-offset:2px}</style>'
                . '<div class="svc-wrap"><div class="svc-tabs" role="tablist" aria-label="' . $this->te(['خدمات المنصة', 'Platform services']) . '">' . $tabBtns . '</div></div>' : '')
            . $panel('plans', $plansHead . '<section class="blk tight" style="padding-top:0"><div class="plans">' . $cards . '</div></section>' . $incSec . $foldCmp . $foldSvc)
            . ($seoSection !== '' ? $panel('seo', $seoSection) : '')
            . ($socialSection !== '' ? $panel('social', $socialSection) : '')
            . ($reputationSection !== '' ? $panel('reputation', $reputationSection) : '')
            . ($tabCount > 1 ? '<script>(function(){var tabs=[].slice.call(document.querySelectorAll(".svc-tabs [role=tab]")),panels=[].slice.call(document.querySelectorAll(".svc-panel"));if(!tabs.length)return;'
                . 'var alias={seo:"seo",social:"social",reputation:"reputation",plans:"plans","services-by-plan":"plans"};'
                . 'function show(id,focus){tabs.forEach(function(t){var on=t.dataset.tab===id;t.setAttribute("aria-selected",on?"true":"false");t.tabIndex=on?0:-1;if(on&&focus)t.focus();});panels.forEach(function(p){p.hidden=p.id!=="tab-"+id;});}'
                . 'function fromHash(scroll){var h=decodeURIComponent((location.hash||"").slice(1)),id=alias[h]||"plans";show(id,false);if(scroll&&alias[h]){var el=document.getElementById(h);var dt=el&&el.closest("details");if(dt)dt.open=true;if(el&&!el.closest("[hidden]"))setTimeout(function(){el.scrollIntoView({block:"start"});},30);}}'
                . 'tabs.forEach(function(t,i){t.addEventListener("click",function(){show(t.dataset.tab,false);history.replaceState(null,"",t.dataset.tab==="plans"?location.pathname+location.search:"#"+t.dataset.tab);});'
                . 't.addEventListener("keydown",function(e){var rtl=getComputedStyle(document.documentElement).direction==="rtl",d=0;if(e.key==="ArrowRight")d=rtl?-1:1;else if(e.key==="ArrowLeft")d=rtl?1:-1;else if(e.key==="Home")d=-i;else if(e.key==="End")d=tabs.length-1-i;else return;e.preventDefault();var n=tabs[(i+d+tabs.length)%tabs.length];show(n.dataset.tab,true);});});'
                . 'window.addEventListener("hashchange",function(){fromHash(true);});fromHash(true);})();</script>' : '');

        $body = PublicLayout::hero($this->t(['الأسعار', 'Pricing']), $this->t(['أسعار واضحة على قد شغلك', 'Simple pricing that grows with you']),
            $this->t(['ابدأ مجانًا، وارقّي لما شغلك يكبر. كل الباقات فيها الحجوزات والدفع والأتمتة الأساسية.', 'Start free and upgrade as you grow. Every plan includes bookings, payments and core automation.']))
            . '<section class="blk tight" style="padding-top:0"><div style="text-align:center"><div class="cycle" role="group" aria-label="' . $this->te(['دورة الدفع', 'Billing cycle']) . '">'
            . '<button type="button" data-cycle="m" aria-pressed="true">' . $this->te(['شهري', 'Monthly']) . '</button>'
            . '<button type="button" data-cycle="y" aria-pressed="false">' . $this->te(['سنوي', 'Yearly']) . $saveLbl . '</button></div></div>'
            . '<div style="text-align:center;margin:-6px 0 14px;font-size:13px"><label>' . $this->te(['العملة', 'Currency']) . ' <select id="tfCur" style="padding:5px 8px;border-radius:8px">' . implode('', array_map(fn ($c) => '<option value="' . $this->e($c['code']) . '"' . ($c['code'] === $viewCur ? ' selected' : '') . '>' . $this->e($c['name'] . ' (' . $c['symbol'] . ')') . '</option>', CurrencyService::publicList($isAr))) . '</select></label>'
            . ($viewCur !== 'USD' ? '<div style="opacity:.7;margin-top:4px">' . $this->e(sprintf($this->t(['محسوبة بسعر الصرف النهارده (1$ = %s)', 'Converted at today’s rate ($1 = %s)']), CurrencyService::format(1, $viewCur, $isAr))) . '</div>' : '')
            . '</div><script>document.getElementById("tfCur").addEventListener("change",function(e){fetch("/api/currency",{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json"},body:JSON.stringify({code:e.target.value})}).then(function(){location.reload();});});</script>'
            . '</section>' . $tabsBlock
            . $this->activationSteps($wa)
            . '<section class="blk"><div class="sec-head"><h2 class="sec-title">' . $this->te(['أسئلة عن الأسعار', 'Pricing questions']) . '</h2></div><div class="faq-list">' . $faq . '</div></section>'
            . PublicLayout::ctaBand()
            . '<script>(function(){var T=' . $i18n . ';function f(s){var a=[].slice.call(arguments,1),i=0;return s.replace(/%([sd])(%{1,2})?/g,function(m,type,percent){var v=a[i++];return String(v)+(percent?"%":"");});}'
            . 'var bs=document.querySelectorAll(".cycle button");function set(c){bs.forEach(function(b){b.setAttribute("aria-pressed",b.dataset.cycle===c?"true":"false");});'
            . 'document.querySelectorAll(".plan[data-m]").forEach(function(p){var y=c==="y";p.querySelector(".amt").textContent=y?p.dataset.y:p.dataset.m;p.querySelector(".unit").textContent=y?T.year:T.month;'
            . 'var eg=p.querySelector(".egp bdi");if(eg)eg.textContent=y?p.dataset.ey:p.dataset.em;var cur=p.querySelector(".cur").textContent,s=+p.dataset.save;p.querySelector(".per").textContent=y?(s>0?f(T.eq,cur+p.dataset.ym,s):f(T.eq0,cur+p.dataset.ym)):" ";});}'
            . 'bs.forEach(function(b){b.addEventListener("click",function(){set(b.dataset.cycle);});});})();</script>';

        $this->out(PublicLayout::render([
            'title' => $this->t(['الأسعار والباقات', 'Pricing & plans']),
            'description' => $this->t(['باقات Tourfecto لشركات الرحلات - شهري أو سنوي، وابدأ مجانًا. كل الباقات فيها الحجوزات والدفع والتذكير والتقييمات.', 'Tourfecto plans for tour companies - monthly or yearly, start free. Every plan includes bookings, payments, reminders and reviews.']),
            'path' => '/pricing', 'active' => 'pricing', 'body' => $body,
            'jsonld' => [['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $faqLd]],
        ]));
        return [];
    }

    // ================================================================== من نحن

    /** GET /about */
    public function about(array $params = []): array
    {
        $a = MarketingContent::about();
        $story = '';
        foreach ($a['story'] as $p) {
            $story .= '<p>' . $this->te($p) . '</p>';
        }
        $values = '';
        foreach ($a['values'] as [$vt, $vd]) {
            $values .= '<div class="value reveal"><b>' . $this->te($vt) . '</b><span>' . $this->te($vd) . '</span></div>';
        }
        $feats = '';
        foreach (array_slice(MarketingContent::features(), 0, 6, true) as $slug => $f) {
            $feats .= '<a href="/features/' . $slug . '" class="rel-card"><span class="fi tone-' . $f['tone'] . '">' . $this->icon($f['icon'], 18) . '</span>' . $this->te($f['title']) . '</a>';
        }
        $body = PublicLayout::hero($this->t(['من نحن', 'About us']), $this->t($a['title']), '')
            . '<section class="blk tight" style="padding-top:0"><div class="container-narrow"><p class="about-lead">' . $this->te($a['lead']) . '</p><div class="prose">' . $story . '</div></div></section>'
            . '<section class="blk alt"><div class="sec-head"><h2 class="sec-title">' . $this->te(['بنشتغل إزاي', 'What we believe']) . '</h2></div><div class="values">' . $values . '</div></section>'
            . '<section class="blk"><div class="sec-head"><h2 class="sec-title">' . $this->te(['بنساعد في إيه', 'What we help with']) . '</h2></div><div class="related">' . $feats . '</div></section>'
            . PublicLayout::ctaBand();
        $this->out(PublicLayout::render([
            'title' => $this->t($a['title']),
            'description' => $this->t($a['lead']),
            'path' => '/about', 'active' => 'about', 'body' => $body,
        ]));
        return [];
    }

    // ================================================================== تواصل / اطلب عرض

    private function formScript(string $type): string
    {
        $msgs = json_encode([
            'sending' => $this->t(['جارِ الإرسال…', 'Sending…']),
            'ok' => $type === 'demo' ? $this->t(['وصلنا طلبك ✓ هنتواصل معاك خلال يوم عمل نحدد ميعاد العرض.', 'Request received ✓ We’ll contact you within one business day to schedule your demo.']) : $this->t(['وصلتنا رسالتك ✓ هنرد عليك في أقرب وقت.', 'Message received ✓ We’ll get back to you shortly.']),
            'err' => $this->t(['حصلت مشكلة، جرّب تاني أو كلمنا على واتساب.', 'Something went wrong. Please try again or reach us on WhatsApp.']),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        return '<script>(function(){var M=' . $msgs . ',f=document.getElementById("leadForm"),a=document.getElementById("leadAlert");if(!f)return;'
            . 'f.addEventListener("submit",async function(e){e.preventDefault();var b=f.querySelector("button[type=submit]"),o=b.textContent;b.disabled=true;b.textContent=M.sending;a.className="form-alert";'
            . 'var d={};new FormData(f).forEach(function(v,k){d[k]=v;});'
            . 'try{var r=await fetch("/contact/send",{method:"POST",headers:{"Content-Type":"application/json","Accept":"application/json"},body:JSON.stringify(d)});var j=await r.json().catch(function(){return{};});'
            . 'if(j.success){a.textContent=M.ok;a.className="form-alert ok";f.reset();}else{a.textContent=j.error||j.message||M.err;a.className="form-alert err";}}'
            . 'catch(x){a.textContent=M.err;a.className="form-alert err";}b.disabled=false;b.textContent=o;});})();</script>';
    }

    /** GET /contact */
    public function contact(array $params = []): array
    {
        $wa = function_exists('support_whatsapp_number') ? support_whatsapp_number() : '';
        $email = '';
        if (class_exists('SystemSettingsService')) {
            try {
                $email = (string) (new SystemSettingsService())->get('contact_email', '');
            } catch (Throwable $e) {
            error_log('[MarketingController] ignored error: ' . $e->getMessage());
            }
        }
        $side = '';
        if ($wa) {
            $side .= '<a class="side-card" href="https://wa.me/' . $this->e($wa) . '" target="_blank" rel="noopener"><span class="fi tone-teal">' . $this->icon('message', 18) . '</span><div><b>' . $this->te(['واتساب', 'WhatsApp']) . '</b><span>' . $this->te(['أسرع طريقة توصلنا - بنرد خلال ساعات العمل.', 'The fastest way to reach us - we reply during business hours.']) . '</span></div></a>';
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $side .= '<a class="side-card" href="mailto:' . $this->e($email) . '"><span class="fi tone-accent">' . $this->icon('mail', 18) . '</span><div><b>' . $this->te(['الإيميل', 'Email']) . '</b><span dir="ltr">' . $this->e($email) . '</span></div></a>';
        }
        $side .= '<a class="side-card" href="/demo"><span class="fi tone-violet">' . $this->icon('monitor', 18) . '</span><div><b>' . $this->te(['اطلب عرض توضيحي', 'Book a demo']) . '</b><span>' . $this->te(['نوريك المنصة على شغلك في مكالمة قصيرة.', 'We’ll show you the platform on your own business in a short call.']) . '</span></div></a>'
            . '<a class="side-card" href="/help"><span class="fi tone-amber">' . $this->icon('book', 18) . '</span><div><b>' . $this->te(['مركز المساعدة', 'Help center']) . '</b><span>' . $this->te(['شروحات وأسئلة شائعة.', 'Guides and FAQs.']) . '</span></div></a>';

        $form = '<form class="form-card" id="leadForm" novalidate><h2>' . $this->te(['ابعتلنا رسالة', 'Send us a message']) . '</h2><p>' . $this->te(['هنرد عليك على الإيميل أو الواتساب.', 'We’ll reply by email or WhatsApp.']) . '</p>'
            . '<input type="hidden" name="type" value="contact"><div class="hp" aria-hidden="true"><label>Website<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>'
            . '<div class="fgrid"><div class="field"><label for="cName">' . $this->te(['الاسم', 'Name']) . '</label><input id="cName" name="name" required maxlength="120" autocomplete="name"></div>'
            . '<div class="field"><label for="cEmail">' . $this->te(['الإيميل', 'Email']) . '</label><input id="cEmail" name="email" type="email" required maxlength="150" autocomplete="email"></div>'
            . '<div class="field full"><label for="cPhone">' . $this->te(['رقم الواتساب', 'WhatsApp number']) . ' <span class="opt">' . $this->te(['(اختياري)', '(optional)']) . '</span></label><input id="cPhone" name="phone" type="tel" maxlength="30" autocomplete="tel"></div>'
            . '<div class="field full"><label for="cMsg">' . $this->te(['رسالتك', 'Your message']) . '</label><textarea id="cMsg" name="message" required maxlength="4000"></textarea></div></div>'
            . '<div class="form-foot"><button type="submit" class="btn-primary">' . $this->te(['إرسال', 'Send']) . '</button><small>' . $this->te(['بياناتك بتستخدم للرد عليك بس.', 'We only use your details to reply to you.']) . '</small></div>'
            . '<div class="form-alert" id="leadAlert" role="status" aria-live="polite"></div></form>';

        $body = PublicLayout::hero($this->t(['تواصل معنا', 'Contact us']), $this->t(['إزاي نقدر نساعدك؟', 'How can we help?']), $this->t(['سؤال عن المنصة، مساعدة في حسابك، أو عايز تعرف الباقة المناسبة - إحنا هنا.', 'A question about the platform, help with your account, or choosing the right plan - we’re here.']))
            . '<section class="blk tight" style="padding-top:0"><div class="two-col">' . $form . '<div class="side-cards">' . $side . '</div></div></section>'
            . $this->formScript('contact');
        $this->out(PublicLayout::render([
            'title' => $this->t(['تواصل معنا', 'Contact us']),
            'description' => $this->t(['تواصل مع فريق Tourfecto على الواتساب أو الإيميل أو من خلال الفورم.', 'Reach the Tourfecto team by WhatsApp, email or the contact form.']),
            'path' => '/contact', 'active' => 'contact', 'body' => $body,
        ]));
        return [];
    }

    /** GET /demo */
    public function demo(array $params = []): array
    {
        $opts = '<option value="">' . $this->te(['اختار', 'Choose']) . '</option>';
        foreach ([['1-2', ['1-2', '1-2']], ['3-10', ['3-10', '3-10']], ['11-30', ['11-30', '11-30']], ['30+', ['أكتر من 30', '30+']]] as [$v, $l]) {
            $opts .= '<option value="' . $v . '">' . $this->te($l) . '</option>';
        }
        $selectedInterest = is_string($_GET['interest'] ?? null) ? $_GET['interest'] : '';
        $interest = '<option value="">' . $this->te(['كله', 'Everything']) . '</option>';
        foreach (MarketingContent::features() as $slug => $f) {
            $interest .= '<option value="' . $slug . '"' . ($selectedInterest === $slug ? ' selected' : '') . '>' . $this->te($f['title']) . '</option>';
        }
        $ck = self::check();
        $list = '';
        foreach ([
            ['المنصة على شركتك انت: رحلاتك، أسعارك، وطريقة شغلك.', 'The platform on your own company: your tours, prices and workflow.'],
            ['إزاي تستقبل حجوزات ودفع أونلاين من أول يوم.', 'How to take bookings and online payments from day one.'],
            ['الشات الذكي وهو بيرد ويحجز.', 'The AI chat answering and booking.'],
            ['الباقة المناسبة لحجمك - من غير ضغط.', 'The right plan for your size - no pressure.'],
        ] as $it) {
            $list .= '<li>' . $ck . '<span>' . $this->te($it) . '</span></li>';
        }
        $form = '<form class="form-card" id="leadForm" novalidate><h2>' . $this->te(['احجز عرضك', 'Book your demo']) . '</h2><p>' . $this->te(['30 دقيقة أونلاين، ببلاش.', '30 minutes online, free.']) . '</p>'
            . '<input type="hidden" name="type" value="demo"><div class="hp" aria-hidden="true"><label>Website<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>'
            . '<div class="fgrid"><div class="field"><label for="dName">' . $this->te(['الاسم', 'Name']) . '</label><input id="dName" name="name" required maxlength="120" autocomplete="name"></div>'
            . '<div class="field"><label for="dCompany">' . $this->te(['اسم الشركة', 'Company name']) . '</label><input id="dCompany" name="company" required maxlength="150" autocomplete="organization"></div>'
            . '<div class="field"><label for="dEmail">' . $this->te(['الإيميل', 'Email']) . '</label><input id="dEmail" name="email" type="email" required maxlength="150" autocomplete="email"></div>'
            . '<div class="field"><label for="dPhone">' . $this->te(['رقم الواتساب', 'WhatsApp number']) . '</label><input id="dPhone" name="phone" type="tel" required maxlength="30" autocomplete="tel"></div>'
            . '<div class="field"><label for="dSite">' . $this->te(['موقعك أو صفحتك', 'Your website or page']) . ' <span class="opt">' . $this->te(['(اختياري)', '(optional)']) . '</span></label><input id="dSite" name="site" maxlength="200" dir="ltr" placeholder="https://"></div>'
            . '<div class="field"><label for="dSize">' . $this->te(['عدد الرحلات/البرامج عندك', 'How many tours you run']) . '</label><select id="dSize" name="size">' . $opts . '</select></div>'
            . '<div class="field full"><label for="dInt">' . $this->te(['مهتم بإيه أكتر؟', 'What interests you most?']) . '</label><select id="dInt" name="interest">' . $interest . '</select></div>'
            . '<div class="field full"><label for="dMsg">' . $this->te(['حاجة عايزنا نعرفها؟', 'Anything we should know?']) . ' <span class="opt">' . $this->te(['(اختياري)', '(optional)']) . '</span></label><textarea id="dMsg" name="message" maxlength="4000" style="min-height:90px"></textarea></div></div>'
            . '<div class="form-foot"><button type="submit" class="btn-primary">' . $this->te(['احجز العرض', 'Book the demo']) . '</button></div>'
            . '<div class="form-alert" id="leadAlert" role="status" aria-live="polite"></div></form>';
        $side = '<div class="form-card"><h2 style="font-size:17px">' . $this->te(['هتشوف إيه في العرض', 'What you’ll see']) . '</h2><ul class="checklist" style="margin-top:14px">' . $list . '</ul></div>';

        $body = PublicLayout::hero($this->t(['عرض توضيحي', 'Live demo']), $this->t(['شوف Tourfecto على شغلك انت', 'See Tourfecto on your own business']), $this->t(['سيب بياناتك ونكلمك نحدد ميعاد مناسب.', 'Leave your details and we’ll call to set a time that suits you.']))
            . '<section class="blk tight" style="padding-top:0"><div class="two-col">' . $form . '<div class="side-cards">' . $side . '</div></div></section>'
            . $this->formScript('demo');
        $this->out(PublicLayout::render([
            'title' => $this->t(['اطلب عرض توضيحي', 'Book a demo']),
            'description' => $this->t(['احجز عرض توضيحي مجاني لمنصة Tourfecto على شركتك.', 'Book a free Tourfecto demo for your company.']),
            'path' => '/demo', 'active' => '', 'body' => $body,
        ]));
        return [];
    }

    /** POST /contact/send - رسائل التواصل وطلبات العرض (بتظهر في الأدمن ← رسائل التواصل) */
    public function send(array $params = []): array
    {
        // فخ البوتات: الحقل ده مخفي عن البشر
        if (trim((string) $this->get('website_url', '')) !== '') {
            return $this->success([], 'ok');
        }
        $type = $this->get('type') === 'demo' ? 'demo' : 'contact';
        $name = trim(mb_substr((string) $this->get('name', ''), 0, 120));
        $email = trim(mb_substr((string) $this->get('email', ''), 0, 150));
        $phone = trim(preg_replace('/[^0-9+\s()-]/', '', mb_substr((string) $this->get('phone', ''), 0, 30)));
        $company = trim(mb_substr((string) $this->get('company', ''), 0, 150));
        $site = trim(mb_substr((string) $this->get('site', ''), 0, 200));
        $size = trim(mb_substr((string) $this->get('size', ''), 0, 20));
        $interest = (string) $this->get('interest', '');
        $message = trim(mb_substr((string) $this->get('message', ''), 0, 4000));

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error($this->t(['اكتب اسمك وإيميل صحيح.', 'Please enter your name and a valid email.']), 422);
        }
        if ($type === 'contact' && mb_strlen($message) < 5) {
            return $this->error($this->t(['اكتب رسالتك.', 'Please write your message.']), 422);
        }
        if ($type === 'demo' && ($company === '' || $phone === '')) {
            return $this->error($this->t(['اكتب اسم الشركة ورقم الواتساب.', 'Please enter your company name and WhatsApp number.']), 422);
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        try {
            $recent = (int) ($this->db->query('SELECT COUNT(*) c FROM contact_submissions WHERE ip_address = ? AND created_at >= (NOW() - INTERVAL 1 HOUR)', [$ip])[0]['c'] ?? 0);
            if ($ip !== '' && $recent >= 5) {
                return $this->error($this->t(['بعت رسايل كتير - جرّب تاني بعد شوية أو كلمنا على واتساب.', 'Too many messages - please try again later or reach us on WhatsApp.']), 429);
            }
        } catch (Throwable $e) {
        error_log('[MarketingController] ignored error: ' . $e->getMessage());
        }

        $features = MarketingContent::features();
        $lines = [];
        if ($type === 'demo') {
            $lines[] = '[طلب عرض توضيحي]';
            $lines[] = 'الشركة: ' . $company;
        }
        if ($phone !== '') {
            $lines[] = 'واتساب: ' . $phone;
        }
        if ($site !== '') {
            $lines[] = 'الموقع: ' . $site;
        }
        if ($size !== '') {
            $lines[] = 'عدد الرحلات: ' . $size;
        }
        if ($interest !== '' && isset($features[$interest])) {
            $lines[] = 'مهتم بـ: ' . $features[$interest]['title'][0];
        }
        $lines[] = 'اللغة: ' . MarketingContent::lang();
        $full = implode("\n", $lines) . ($message !== '' ? "\n\n" . $message : '');

        try {
            $this->db->query('INSERT INTO contact_submissions (name, email, message, user_id, status, ip_address) VALUES (?, ?, ?, ?, ?, ?)',
                [$name, $email, $full, !empty($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : null, 'new', $ip ?: null]);
        } catch (Throwable $e) {
            Logger::error('Lead save failed', ['message' => $e->getMessage(), 'type' => $type]);
            return $this->error($this->t(['حصلت مشكلة، جرّب تاني أو كلمنا على واتساب.', 'Something went wrong. Please try again or reach us on WhatsApp.']), 500);
        }
        $this->notifyAdmins($type, $name, $email, $full);
        return $this->success([], 'ok');
    }

    private function notifyAdmins(string $type, string $name, string $email, string $body): void
    {
        $title = $type === 'demo' ? 'طلب عرض توضيحي جديد: ' . $name : 'رسالة تواصل جديدة: ' . $name;
        try {
            $admins = $this->db->query("SELECT id, email FROM users WHERE role IN ('admin','super_admin') AND deleted_at IS NULL");
        } catch (Throwable $e) {
            $admins = [];
        }
        foreach ($admins as $a) {
            try {
                $this->db->query('INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)',
                    [(int) $a['id'], 'contact_' . $type, $title, mb_substr($body, 0, 240), '/admin/contact-messages']);
            } catch (Throwable $e) {
            error_log('[MarketingController] ignored error: ' . $e->getMessage());
            }
        }
        $to = [];
        if (class_exists('SystemSettingsService')) {
            try {
                $cfg = (string) (new SystemSettingsService())->get('admin_alert_emails', '');
                $to = array_filter(array_map('trim', explode(',', $cfg)));
            } catch (Throwable $e) {
            error_log('[MarketingController] ignored error: ' . $e->getMessage());
            }
        }
        if (!$to) {
            $to = array_column($admins, 'email');
        }
        if (!$to || !class_exists('Mailer')) {
            return;
        }
        $html = '<div dir="rtl" style="font-family:Tahoma,Arial;max-width:600px"><h2 style="font-size:18px">' . $this->e($title) . '</h2>'
            . '<p><b>الإيميل:</b> ' . $this->e($email) . '</p><pre style="white-space:pre-wrap;font-family:inherit;background:#f6f7fb;padding:12px;border-radius:8px">' . $this->e($body) . '</pre>'
            . '<p><a href="' . $this->e(PublicLayout::baseUrl() . '/admin/contact-messages') . '">افتح رسائل التواصل في الأدمن</a></p></div>';
        foreach (array_slice(array_unique($to), 0, 5) as $addr) {
            try {
                (new Mailer())->context('admin_alert')->send($addr, 'Tourfecto Admin', $title, $html);
            } catch (Throwable $e) {
                Logger::warning('Lead notify mail failed', ['message' => $e->getMessage()]);
            }
        }
    }

    // ================================================================== سياسة الاسترجاع

    /** GET /refund-policy */
    public function refund(array $params = []): array
    {
        $custom = '';
        if (class_exists('SystemSettingsService')) {
            try {
                $custom = trim((string) (new SystemSettingsService())->get('refund_policy_content', ''));
            } catch (Throwable $e) {
            error_log('[MarketingController] ignored error: ' . $e->getMessage());
            }
        }
        $html = $custom !== '' ? self::sanitizeHtml($custom) : self::defaultRefundHtml(MarketingContent::lang());
        $body = PublicLayout::hero($this->t(['قانوني', 'Legal']), $this->t(['سياسة الاسترجاع والإلغاء', 'Refund & cancellation policy']), '')
            . '<section class="blk tight" style="padding-top:0"><div class="container-narrow prose">' . $html . '</div></section>';
        $this->out(PublicLayout::render([
            'title' => $this->t(['سياسة الاسترجاع', 'Refund policy']),
            'description' => $this->t(['سياسة الاسترجاع والإلغاء لاشتراكات منصة Tourfecto.', 'Refund and cancellation policy for Tourfecto subscriptions.']),
            'path' => '/refund-policy', 'body' => $body,
        ]));
        return [];
    }

    /** HTML الأدمن: بنشيل السكربتات والأحداث (الأدمن موثوق بس احتياط) */
    private static function sanitizeHtml(string $h): string
    {
        $h = preg_replace('#<(script|style|iframe|object|embed)[^>]*>.*?</\1>#is', '', $h);
        $h = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', (string) $h);
        return (string) preg_replace('#(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2#i', '$1="#"', (string) $h);
    }

    public static function defaultRefundHtml(string $lang): string
    {
        if ($lang === 'ar') {
            return <<<'HTML'
<p><small>آخر تحديث: أكتوبر 2026</small></p>
<p>السياسة دي بتوضح إزاي الإلغاء والاسترجاع بيتم لاشتراكات منصة Tourfecto. وهي جزء من <a href="/terms">شروط الاستخدام</a>.</p>
<h2>1. الحساب المجاني والتجربة</h2>
<p>تقدر تعمل حساب مجاني وتجرّب المنصة قبل ما تدفع أي حاجة، عشان تتأكد إنها مناسبة لشغلك.</p>
<h2>2. إلغاء الاشتراك</h2>
<ul>
<li>تقدر تلغي اشتراكك في أي وقت من صفحة <b>الاشتراك</b> في حسابك.</li>
<li>الإلغاء بيمنع التجديد الجاي، والاشتراك بيفضل شغّال لحد آخر المدة اللي دفعتها.</li>
<li>تقدر ترجّع الاشتراك قبل نهاية المدة لو غيّرت رأيك.</li>
</ul>
<h2>3. الاسترجاع</h2>
<p>رسوم الاشتراك بتتدفع مقدمًا عن المدة (شهر أو سنة)، ومش بتسترد بعد بداية المدة، إلا في الحالات دي:</p>
<ul>
<li><b>خصم مكرر أو خطأ في الفاتورة:</b> بنرجّع المبلغ الزيادة بالكامل.</li>
<li><b>عطل من ناحيتنا:</b> لو الخدمة وقفت فترة طويلة بسبب مشكلة عندنا، بنعوّضك بمدة إضافية أو استرجاع جزئي حسب الحالة.</li>
<li><b>أي حالة القانون بيلزمنا فيها بالاسترجاع.</b></li>
</ul>
<h2>4. الترقية وتغيير الباقة</h2>
<p>لما ترقّي لباقة أعلى، بتدفع فرق السعر بس. ولو نزلت لباقة أقل، التغيير بيبدأ من التجديد الجاي.</p>
<h2>5. رصيد المحفظة</h2>
<p>الرصيد اللي في محفظتك جوه المنصة بيستخدم في الاشتراكات والخدمات. لو عندك طلب بخصوص رصيد ماستخدمتهوش، كلمنا ونراجعه معاك.</p>
<h2>6. مدفوعات عملاءك (حجوزات الرحلات)</h2>
<p>الفلوس اللي عملاءك بيدفعوها لحجز رحلاتك (من لينكات الدفع أو موقع الحجز) بتروح لشركتك. استرجاع أو إلغاء حجز رحلة مسؤولية شركة الرحلات حسب سياستها هي، ومش بيخضع للسياسة دي.</p>
<h2>7. إزاي تطلب استرجاع</h2>
<p>ابعتلنا من صفحة <a href="/contact">تواصل معنا</a> بالإيميل المسجّل بيه وسبب الطلب، وهنرد عليك خلال 5 أيام عمل. لو الطلب اتقبل، المبلغ بيرجع على نفس طريقة الدفع خلال 5-10 أيام عمل حسب البنك.</p>
HTML;
        }
        return <<<'HTML'
<p><small>Last updated: October 2026</small></p>
<p>This policy explains how cancellations and refunds work for Tourfecto subscriptions. It forms part of our <a href="/terms">Terms of Service</a>.</p>
<h2>1. Free account and trial</h2>
<p>You can create a free account and try the platform before paying anything, so you can make sure it fits your business.</p>
<h2>2. Cancelling your subscription</h2>
<ul>
<li>You can cancel any time from the <b>Subscription</b> page in your account.</li>
<li>Cancelling stops the next renewal; your plan stays active until the end of the period you’ve paid for.</li>
<li>You can resume the subscription before the period ends if you change your mind.</li>
</ul>
<h2>3. Refunds</h2>
<p>Subscription fees are paid in advance for each period (monthly or yearly) and are non-refundable once the period has started, except in these cases:</p>
<ul>
<li><b>Duplicate charge or billing error:</b> we refund the excess amount in full.</li>
<li><b>Extended outage on our side:</b> if the service is unavailable for a significant period due to a problem on our end, we’ll compensate you with extra time or a partial refund, depending on the case.</li>
<li><b>Any case where a refund is required by law.</b></li>
</ul>
<h2>4. Upgrades and plan changes</h2>
<p>When you upgrade, you only pay the price difference. If you downgrade, the change takes effect at your next renewal.</p>
<h2>5. Wallet balance</h2>
<p>Your in-platform wallet balance is used for subscriptions and services. If you have a request about unused balance, contact us and we’ll review it with you.</p>
<h2>6. Your guests’ payments (tour bookings)</h2>
<p>Money your guests pay to book your tours (via payment links or your booking site) goes to your company. Refunds or cancellations of tour bookings are the tour company’s responsibility under its own policy and are not covered by this policy.</p>
<h2>7. How to request a refund</h2>
<p>Message us from the <a href="/contact">Contact</a> page using your account email and the reason for the request; we’ll reply within 5 business days. If approved, the amount is returned to the original payment method within 5-10 business days, depending on your bank.</p>
HTML;
    }

    // ================================================================== المدونة

    private function blogReady(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM blog_posts LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function readMinutes(string $md): int
    {
        $words = count(preg_split('/\s+/u', MarkdownLite::plain($md), -1, PREG_SPLIT_NO_EMPTY));
        return max(1, (int) ceil($words / 200));
    }

    private function postCard(array $p): string
    {
        $cover = !empty($p['cover_url']) && MarkdownLite::safeUrl((string) $p['cover_url'])
            ? '<div class="post-cover"><img src="' . $this->e($p['cover_url']) . '" alt="" loading="lazy"></div>'
            : '<div class="post-cover ph"><span>' . $this->e(mb_substr((string) $p['title'], 0, 1)) . '</span></div>';
        $date = $p['published_at'] ? date('Y-m-d', strtotime((string) $p['published_at'])) : '';
        $excerpt = (string) ($p['excerpt'] ?: mb_substr(MarkdownLite::plain((string) $p['body']), 0, 160));
        $dir = $p['lang'] === 'ar' ? 'rtl' : 'ltr';
        return '<a class="post-card reveal" href="/blog/' . $this->e($p['slug']) . '" dir="' . $dir . '" lang="' . $this->e($p['lang']) . '">' . $cover . '<div class="post-body">'
            . '<div class="post-meta"><span>' . $this->e($date) . '</span><span>' . self::readMinutes((string) $p['body']) . ' ' . $this->te(['دقايق قراءة', 'min read']) . '</span></div>'
            . '<h3>' . $this->e($p['title']) . '</h3><p>' . $this->e($excerpt) . '</p></div></a>';
    }

    /** GET /blog */
    public function blog(array $params = []): array
    {
        $lang = MarketingContent::lang();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $per = 12;
        $posts = [];
        $total = 0;
        if ($this->blogReady()) {
            $w = "status = 'published' AND published_at <= NOW()";
            $cntLang = (int) ($this->db->query("SELECT COUNT(*) c FROM blog_posts WHERE {$w} AND lang = ?", [$lang])[0]['c'] ?? 0);
            // لو مفيش مقالات بلغة الزائر، نعرض كل اللغات بدل صفحة فاضية
            $args = $cntLang > 0 ? [$lang] : [];
            $wl = $cntLang > 0 ? ' AND lang = ?' : '';
            $total = $cntLang > 0 ? $cntLang : (int) ($this->db->query("SELECT COUNT(*) c FROM blog_posts WHERE {$w}")[0]['c'] ?? 0);
            $off = ($page - 1) * $per;
            $posts = $this->db->query("SELECT id, slug, lang, title, excerpt, body, cover_url, published_at FROM blog_posts WHERE {$w}{$wl} ORDER BY published_at DESC LIMIT {$per} OFFSET {$off}", $args);
        }
        $grid = '';
        foreach ($posts as $p) {
            $grid .= $this->postCard($p);
        }
        if ($grid === '') {
            $grid = '<div class="empty-state" style="grid-column:1/-1"><b>' . $this->te(['المقالات جاية قريب', 'Articles coming soon']) . '</b>' . $this->te(['بنجهز نصايح عملية لشركات الرحلات - ارجع تاني قريب.', 'We’re preparing practical guides for tour companies - check back soon.']) . '</div>';
        }
        $pager = '';
        $pages = (int) ceil($total / $per);
        if ($pages > 1) {
            $pager = '<div class="hero-ctas" style="justify-content:center;margin-top:28px">'
                . ($page > 1 ? '<a class="cta-ghost" href="/blog?page=' . ($page - 1) . '">' . $this->te(['→ الأحدث', '← Newer']) . '</a>' : '')
                . ($page < $pages ? '<a class="cta-ghost" href="/blog?page=' . ($page + 1) . '">' . $this->te(['الأقدم ←', 'Older →']) . '</a>' : '') . '</div>';
        }
        $body = PublicLayout::hero($this->t(['المدونة', 'Blog']), $this->t(['نصايح عملية لشركات الرحلات', 'Practical guides for tour companies']), $this->t(['إزاي تجيب حجوزات أكتر، تحسّن تقييماتك، وتظهر على جوجل.', 'How to get more bookings, improve your reviews and show up on Google.']))
            . '<section class="blk tight" style="padding-top:0"><div class="post-grid">' . $grid . '</div>' . $pager . '</section>';
        $this->out(PublicLayout::render([
            'title' => $this->t(['المدونة', 'Blog']),
            'description' => $this->t(['مقالات ونصايح لشركات الرحلات والسياحة: الحجوزات، التسويق، التقييمات و SEO.', 'Articles and tips for tour and travel companies: bookings, marketing, reviews and SEO.']),
            'path' => '/blog', 'active' => 'blog', 'body' => $body,
        ]));
        return [];
    }

    /** GET /blog/{slug} */
    public function blogPost(array $params = []): array
    {
        $slug = mb_substr(rawurldecode((string) ($params['slug'] ?? '')), 0, 160); // الراوتر بيبعت الرابط encoded (عربي)
        if (!$this->blogReady() || $slug === '') {
            return $this->notFound();
        }
        $lang = MarketingContent::lang();
        $rows = $this->db->query("SELECT * FROM blog_posts WHERE slug = ? AND status = 'published' AND published_at <= NOW() ORDER BY (lang = ?) DESC LIMIT 1", [$slug, $lang]);
        if (!$rows) {
            return $this->notFound();
        }
        $p = $rows[0];
        try {
            $this->db->exec('UPDATE blog_posts SET views = views + 1 WHERE id = ?', [(int) $p['id']]);
        } catch (Throwable $e) {
        error_log('[MarketingController] ignored error: ' . $e->getMessage());
        }
        $dir = $p['lang'] === 'ar' ? 'rtl' : 'ltr';
        $date = date('Y-m-d', strtotime((string) $p['published_at']));
        $cover = !empty($p['cover_url']) && MarkdownLite::safeUrl((string) $p['cover_url']) ? '<div class="post-hero-img"><img src="' . $this->e($p['cover_url']) . '" alt=""></div>' : '';
        $tags = '';
        foreach (array_filter(array_map('trim', explode(',', (string) $p['tags']))) as $tg) {
            $tags .= '<span>' . $this->e($tg) . '</span>';
        }
        $more = $this->db->query("SELECT id, slug, lang, title, excerpt, body, cover_url, published_at FROM blog_posts WHERE status = 'published' AND published_at <= NOW() AND id <> ? AND lang = ? ORDER BY published_at DESC LIMIT 3", [(int) $p['id'], $p['lang']]);
        $moreHtml = '';
        foreach ($more as $m) {
            $moreHtml .= $this->postCard($m);
        }
        $desc = (string) ($p['meta_description'] ?: $p['excerpt'] ?: mb_substr(MarkdownLite::plain((string) $p['body']), 0, 160));
        $body = '<article dir="' . $dir . '" lang="' . $this->e($p['lang']) . '"><header class="post-hero"><nav class="crumbs"><a href="/blog">' . $this->te(['المدونة', 'Blog']) . '</a></nav>'
            . '<h1>' . $this->e($p['title']) . '</h1><div class="post-meta"><time datetime="' . $this->e($date) . '">' . $this->e($date) . '</time><span>' . self::readMinutes((string) $p['body']) . ' ' . $this->te(['دقايق قراءة', 'min read']) . '</span></div></header>'
            . $cover . '<div class="article prose">' . MarkdownLite::toHtml((string) $p['body']) . ($tags ? '<div class="tags">' . $tags . '</div>' : '') . '</div></article>'
            . ($moreHtml ? '<section class="blk"><div class="sec-head"><h2 class="sec-title" style="font-size:22px">' . $this->te(['مقالات تانية', 'More articles']) . '</h2></div><div class="post-grid">' . $moreHtml . '</div></section>' : '')
            . PublicLayout::ctaBand();
        $base = PublicLayout::baseUrl();
        $this->out(PublicLayout::render([
            'title' => (string) $p['title'],
            'description' => $desc,
            'path' => '/blog/' . $p['slug'], 'active' => 'blog', 'body' => $body, 'ogType' => 'article',
            'ogImage' => !empty($p['cover_url']) && preg_match('#^https?://#', (string) $p['cover_url']) ? (string) $p['cover_url'] : null,
            'jsonld' => [
                ['@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => (string) $p['title'], 'description' => $desc, 'datePublished' => date('c', strtotime((string) $p['published_at'])),
                    'dateModified' => date('c', strtotime((string) $p['updated_at'])), 'inLanguage' => $p['lang'], 'mainEntityOfPage' => $base . '/blog/' . $p['slug'],
                    'publisher' => ['@type' => 'Organization', 'name' => 'Tourfecto']] + (!empty($p['cover_url']) ? ['image' => (string) $p['cover_url']] : []),
                PublicLayout::breadcrumb([[$this->t(['المدونة', 'Blog']), '/blog'], [(string) $p['title'], '/blog/' . $p['slug']]]),
            ],
        ]));
        return [];
    }

    // ================================================================== 404

    private function notFound(): array
    {
        $body = PublicLayout::hero('404', $this->t(['الصفحة دي مش موجودة', 'This page doesn’t exist']), $this->t(['يمكن اللينك اتغير. جرّب من هنا:', 'The link may have changed. Try one of these:']),
            '<a href="/" class="btn-primary">' . $this->te(['الرئيسية', 'Home']) . '</a><a href="/features" class="cta-ghost">' . $this->te(['المميزات', 'Features']) . '</a><a href="/blog" class="cta-ghost">' . $this->te(['المدونة', 'Blog']) . '</a>');
        $this->out(PublicLayout::render(['title' => '404', 'description' => '', 'body' => $body, 'noindex' => true]), 404);
        return [];
    }
}
