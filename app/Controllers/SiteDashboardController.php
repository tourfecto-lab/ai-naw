<?php

/**
 * Tourfecto - Site Dashboard Controller (v2.0.0)
 * لوحة تحكم مستقلة لكل موقع منشأ: نظرة عامة، التصميم، SEO، التقييمات،
 * طلبات التواصل/الحجز. منفصلة عن شات الإنشاء - العميل بيدير موقعه منها
 * مباشرة بعد ما يتولّد، زي أي website builder احترافي.
 */
class SiteDashboardController extends Controller
{
    /** GET /dashboard/sites/{id} - صفحة لوحة تحكم الموقع */
    public function index(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            header('HTTP/1.1 404 Not Found');
            echo 'الموقع ده مش موجود';
            exit;
        }

        $id = (int) $website->getAttribute('id');
        $c = $website->getContent();
        $businessName = htmlspecialchars((string) ($c['business_name'] ?? $website->getAttribute('slug')), ENT_QUOTES, 'UTF-8');
        $niche = $website->resolveNicheKey();
        $nicheCfg = WebsiteNiches::get($niche);
        $studioCss = htmlspecialchars(asset_v('/assets/css/site-design-studio.css'), ENT_QUOTES, 'UTF-8');
        $studioJs = htmlspecialchars(asset_v('/assets/js/site-design-studio.js'), ENT_QUOTES, 'UTF-8');

        $tTitle = $this->tr('sd.title');

        $body = <<<HTML
        <div class="sd-header p-card" style="margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <div>
                <h2 style="margin:0 0 4px;">{$nicheCfg['icon']} {$businessName}</h2>
                <span class="p-badge" id="sdStatusBadge">...</span>
            </div>
            <div style="display:flex;gap:8px;">
                <a href="/sites/{$website->getAttribute('slug')}" target="_blank" class="p-btn outline">👁 معاينة الموقع</a>
                <button class="p-btn primary" onclick="sdPublish()" id="sdPublishBtn">🚀 نشر الموقع</button>
            </div>
        </div>

        <div class="p-tabs" style="margin-bottom:16px;">
            <button class="p-tab active" onclick="sdSwitchTab('overview')" id="sdTabOverviewBtn">📊 نظرة عامة</button>
            <button class="p-tab" onclick="sdSwitchTab('design')" id="sdTabDesignBtn">🎨 التصميم</button>
            <button class="p-tab" onclick="sdSwitchTab('seo')" id="sdTabSeoBtn">🔍 SEO والدومين</button>
            <button class="p-tab" onclick="sdSwitchTab('reviews')" id="sdTabReviewsBtn">⭐ التقييمات</button>
            <button class="p-tab" onclick="sdSwitchTab('leads')" id="sdTabLeadsBtn">📩 طلبات التواصل <span id="sdLeadsCount" class="p-badge-dot" style="display:none;"></span></button>
        </div>

        <div id="sdTabOverview">
            <div class="p-grid cols-3" id="sdOverviewCards">
                <div class="p-card"><span class="p-stat-label">الحالة</span><h3 id="sdOverStatus">-</h3></div>
                <div class="p-card"><span class="p-stat-label">عدد الزيارات</span><h3 id="sdOverViews">-</h3></div>
                <div class="p-card"><span class="p-stat-label">متوسط التقييم</span><h3 id="sdOverRating">-</h3></div>
            </div>
        </div>

        <div id="sdTabDesign" style="display:none;">
            <link rel="stylesheet" href="{$studioCss}">
            <div id="sdStudio" data-site="{$id}"></div>
            <script defer src="{$studioJs}"></script>
            <!-- حقول قديمة (لسه بيقراها كود النظرة العامة) - مخفية -->
            <div hidden><div id="sdTemplatesGrid"></div><select id="sdThemeColor"></select><input id="sdLogoUrl"></div>
        </div>

        <div id="sdTabSeo" style="display:none;">
            <div class="p-card">
                <label for="sdSeoTitle" class="form-label">عنوان الصفحة (SEO Title)</label>
                <input type="text" id="sdSeoTitle" class="form-control" style="margin-bottom:10px;">
                <label for="sdSeoDesc" class="form-label">وصف الصفحة (SEO Description)</label>
                <textarea id="sdSeoDesc" class="form-control" rows="3" style="margin-bottom:10px;"></textarea>
                <label for="sdCustomDomain" class="form-label">دومين مخصص (اختياري)</label>
                <input type="text" id="sdCustomDomain" class="form-control" style="margin-bottom:10px;" dir="ltr" placeholder="www.example.com">
                <button class="p-btn primary" onclick="sdSaveSeo()">حفظ</button><button class="p-btn outline" onclick="sdDomainInstructions()">تحقق من النطاق</button><pre id="sdDomainInstructions" style="white-space:pre-wrap;direction:ltr"></pre>
            </div>
        </div>

        <div id="sdTabReviews" style="display:none;">
            <div id="sdReviewsList" class="p-list">جاري التحميل...</div>
        </div>

        <div id="sdTabLeads" style="display:none;">
            <div id="sdLeadsList" class="p-list">جاري التحميل...</div>
        </div>
HTML;

        $script = <<<JS
(function () {
    const P = window.Panel;
    const fetchJSON = P.fetchJSON, esc = P.esc, toast = P.toast;
    const siteId = {$id};

    window.sdSwitchTab = function (tab) {
        ['overview', 'design', 'seo', 'reviews', 'leads'].forEach(t => {
            document.getElementById('sdTab' + t.charAt(0).toUpperCase() + t.slice(1)).style.display = (t === tab) ? '' : 'none';
            document.getElementById('sdTab' + t.charAt(0).toUpperCase() + t.slice(1) + 'Btn').classList.toggle('active', t === tab);
        });
        if (tab === 'design' && window.SdStudio) SdStudio.open();
        if (tab === 'reviews') sdLoadReviews();
        if (tab === 'leads') sdLoadLeads();
    };

    async function sdLoadOverview() {
        const res = await fetchJSON(`/api/site-dashboard/\${siteId}/overview`);
        if (!res.success) return;
        const d = res.data;
        document.getElementById('sdStatusBadge').textContent = d.status === 'published' ? '🟢 منشور' : '⚪ مسوّدة';
        document.getElementById('sdOverStatus').textContent = d.status === 'published' ? 'منشور' : 'مسوّدة';
        document.getElementById('sdOverViews').textContent = d.views_count ?? 0;
        document.getElementById('sdOverRating').textContent = d.average_rating > 0 ? ('⭐ ' + d.average_rating) : 'لا يوجد بعد';
        document.getElementById('sdThemeColor').value = d.theme_color || 'gold';
        document.getElementById('sdLogoUrl').value = d.logo_url || '';
        document.getElementById('sdSeoTitle').value = d.seo_title || '';
        document.getElementById('sdSeoDesc').value = d.seo_description || '';
        document.getElementById('sdCustomDomain').value = d.custom_domain || '';
        const badge = document.getElementById('sdLeadsCount');
        if (d.new_leads_count > 0) { badge.style.display = ''; badge.textContent = d.new_leads_count; } else { badge.style.display = 'none'; }
    }

    window.sdPublish = async function () {
        const res = await fetchJSON(`/api/website-builder/\${siteId}/publish`, { method: 'POST' });
        if (res.success) { toast('تم النشر بنجاح'); sdLoadOverview(); } else { toast(res.error || 'حصل خطأ', 'error'); }
    };

    async function sdLoadTemplates() {
        const res = await fetchJSON(`/api/site-dashboard/\${siteId}/templates`);
        const grid = document.getElementById('sdTemplatesGrid');
        if (!res.success || !res.data.templates.length) { grid.innerHTML = '<p>لا توجد تصميمات متاحة لمجالك حاليًا</p>'; return; }
        grid.innerHTML = res.data.templates.map(t => `
            <div class="p-card sd-template-card \${t.id === res.data.current_template_id ? 'active' : ''}" onclick="sdPickTemplate(\${t.id})" style="cursor:pointer;">
                <div class="sd-template-preview" style="background-image:url('\${esc(t.preview_image || '')}');height:110px;border-radius:8px;background-size:cover;background-position:center;margin-bottom:8px;background-color:#222;"></div>
                <strong>\${esc(t.name_ar)}</strong>
                \${t.is_premium ? '<span class="p-badge gold">مميز</span>' : ''}
            </div>
        `).join('');
    }

    window.sdPickTemplate = async function (templateId) {
        const res = await fetchJSON(`/api/site-dashboard/\${siteId}/design`, {
            method: 'PUT', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ template_id: templateId }),
        });
        if (res.success) { toast('تم اختيار التصميم'); sdLoadTemplates(); } else { toast(res.error || 'حصل خطأ', 'error'); }
    };

    window.sdSaveDesign = async function () {
        const res = await fetchJSON(`/api/site-dashboard/\${siteId}/design`, {
            method: 'PUT', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                theme_color: document.getElementById('sdThemeColor').value,
                logo_url: document.getElementById('sdLogoUrl').value,
            }),
        });
        if (res.success) toast('تم الحفظ'); else toast(res.error || 'حصل خطأ', 'error');
    };

    window.sdSaveSeo = async function () {
        const res = await fetchJSON(`/api/site-dashboard/\${siteId}/seo`, {
            method: 'PUT', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                seo_title: document.getElementById('sdSeoTitle').value,
                seo_description: document.getElementById('sdSeoDesc').value,
                custom_domain: document.getElementById('sdCustomDomain').value,
            }),
        });
        if (res.success) toast('تم الحفظ'); else toast(res.error || 'حصل خطأ', 'error');
    };

    window.sdDomainInstructions=async function(){
        const r=await fetchJSON(`/api/site-dashboard/\${siteId}/domain`);if(!r.success){toast(r.error||'Error','error');return;}
        const d=r.data;document.getElementById('sdDomainInstructions').textContent=d.txt_name?d.state+'\\nTXT '+d.txt_name+'\\n'+d.txt_value+'\\nCNAME/A → '+d.routing_target+'\\nBind this domain and enable HTTPS in your hosting panel.':'Save a domain first.';
        if(d.txt_name){const v=await fetchJSON(`/api/site-dashboard/\${siteId}/domain/verify`,{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'});toast(v.success?'DNS verified; configure HTTPS in hosting.':v.error,v.success?'success':'error');}
    };
    async function sdLoadReviews() {
        const res = await fetchJSON(`/api/site-dashboard/\${siteId}/reviews`);
        const list = document.getElementById('sdReviewsList');
        if (!res.success || !res.data.reviews.length) { list.innerHTML = '<p>لا توجد تقييمات بعد</p>'; return; }
        list.innerHTML = res.data.reviews.map(r => `
            <div class="p-card" style="margin-bottom:10px;">
                <div style="display:flex;justify-content:space-between;">
                    <strong>\${esc(r.visitor_name)}</strong>
                    <span>\${'⭐'.repeat(r.rating)}</span>
                </div>
                <p>\${esc(r.comment || '')}</p>
                <div style="display:flex;gap:8px;">
                    \${r.status !== 'approved' ? `<button class="p-btn xs primary" onclick="sdReviewAction(\${r.id},'approved')">✔ اعتماد</button>` : '<span class="p-badge green">معتمد</span>'}
                    \${r.status !== 'rejected' ? `<button class="p-btn xs outline" onclick="sdReviewAction(\${r.id},'rejected')">✖ رفض</button>` : ''}
                </div>
            </div>
        `).join('');
    }

    window.sdReviewAction = async function (reviewId, status) {
        const res = await fetchJSON(`/api/site-dashboard/\${siteId}/reviews/\${reviewId}`, {
            method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ status }),
        });
        if (res.success) sdLoadReviews(); else toast(res.error || 'حصل خطأ', 'error');
    };

    async function sdLoadLeads() {
        const res = await fetchJSON(`/api/site-dashboard/\${siteId}/leads`);
        const list = document.getElementById('sdLeadsList');
        if (!res.success || !res.data.leads.length) { list.innerHTML = '<p>لا توجد طلبات بعد</p>'; return; }
        list.innerHTML = res.data.leads.map(l => `
            <div class="p-card" style="margin-bottom:10px;">
                <div style="display:flex;justify-content:space-between;">
                    <strong>\${esc(l.visitor_name)}</strong>
                    <span class="p-badge \${l.status === 'new' ? 'gold' : ''}">\${l.status}</span>
                </div>
                <p dir="ltr" style="text-align:right;">\${esc(l.phone || '')} \${l.email ? ' - ' + esc(l.email) : ''}</p>
                <p>\${esc(l.message || '')}</p>
                <select onchange="sdLeadStatus(\${l.id}, this.value)">
                    <option value="new" \${l.status === 'new' ? 'selected' : ''}>جديد</option>
                    <option value="contacted" \${l.status === 'contacted' ? 'selected' : ''}>تم التواصل</option>
                    <option value="closed" \${l.status === 'closed' ? 'selected' : ''}>مغلق</option>
                </select>
            </div>
        `).join('');
    }

    window.sdLeadStatus = async function (leadId, status) {
        const res = await fetchJSON(`/api/site-dashboard/\${siteId}/leads/\${leadId}`, {
            method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ status }),
        });
        if (!res.success) toast(res.error || 'حصل خطأ', 'error');
    };

    sdLoadOverview();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('website_builder', $tTitle, $businessName, $body, $script);
        exit;
    }

    /** GET /api/site-dashboard/{id}/overview */
    public function overview(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }

        $averageRating = (new WebsiteReview())->averageRating((int) $website->getAttribute('id'));
        $newLeads = (new WebsiteLead())->newCountFor((int) $website->getAttribute('id'));

        return $this->success([
            'status' => $website->getAttribute('status'),
            'views_count' => (int) $website->getAttribute('views_count'),
            'average_rating' => $averageRating,
            'theme_color' => $website->getAttribute('theme_color'),
            'logo_url' => $website->getAttribute('logo_url'),
            'seo_title' => $website->getAttribute('seo_title'),
            'seo_description' => $website->getAttribute('seo_description'),
            'custom_domain' => $website->getAttribute('custom_domain'),
            'new_leads_count' => $newLeads,
        ]);
    }

    /** GET /api/site-dashboard/{id}/templates */
    public function templates(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }

        $niche = $website->resolveNicheKey();
        $rows = (new WebsiteTemplate())->forNiche($niche);
        $templates = array_map(fn ($t) => [
            'id' => (int) $t->getAttribute('id'),
            'name_ar' => $t->getAttribute('name_ar'),
            'preview_image' => $t->getAttribute('preview_image'),
            'is_premium' => (bool) $t->getAttribute('is_premium'),
        ], $rows);

        return $this->success([
            'niche_key' => $niche,
            'templates' => $templates,
            'current_template_id' => (int) $website->getAttribute('template_id'),
        ]);
    }

    /** PUT /api/site-dashboard/{id}/design */
    public function updateDesign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }

        $themeColors = WebsiteBuilderController::THEMES;
        if ($this->get('theme_color') !== null && isset($themeColors[(string) $this->get('theme_color')])) {
            $website->setAttribute('theme_color', (string) $this->get('theme_color'));
        }
        if ($this->get('logo_url') !== null) {
            $website->setAttribute('logo_url', (string) $this->get('logo_url'));
        }
        if ($this->get('template_id') !== null) {
            $templateId = (int) $this->get('template_id');
            $template = (new WebsiteTemplate())->find($templateId);
            if ($template && $template->getAttribute('niche_key') === $website->resolveNicheKey()) {
                $website->setAttribute('template_id', $templateId);
            }
        }
        $website->save();
        return $this->success([], 'تم الحفظ');
    }

    /** PUT /api/site-dashboard/{id}/seo */
    public function updateSeo(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }

        foreach (['seo_title', 'seo_description'] as $field) {
            if ($this->get($field) !== null) {
                $website->setAttribute($field, (string) $this->get($field));
            }
        }
        try{
            if($this->get('custom_domain')!==null){$domain=CustomDomainService::normalize((string)$this->get('custom_domain'));$status=CustomDomainService::configure((int)$website->getAttribute('id'),(int)$this->user['id'],$domain);$website->setAttribute('custom_domain',$domain);}
            if(!$website->save())throw new RuntimeException('تعذر الحفظ');
            return $this->success(['domain'=>CustomDomainService::status((int)$website->getAttribute('id'),(int)$this->user['id'])],'تم الحفظ');
        }catch(Throwable $e){return $this->error(CustomerError::message($e->getMessage()),422);}
    }

    /** GET /api/site-dashboard/{id}/reviews */
    public function reviews(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }

        $rows = (new WebsiteReview())->allFor((int) $website->getAttribute('id'));
        $reviews = array_map(fn ($r) => [
            'id' => (int) $r->getAttribute('id'),
            'visitor_name' => $r->getAttribute('visitor_name'),
            'rating' => (int) $r->getAttribute('rating'),
            'comment' => $r->getAttribute('comment'),
            'status' => $r->getAttribute('status'),
        ], $rows);

        return $this->success(['reviews' => $reviews]);
    }

    /** PUT /api/site-dashboard/{id}/reviews/{reviewId} - اعتماد أو رفض تقييم */
    public function updateReview(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }

        $review = (new WebsiteReview())->find((int) ($params['reviewId'] ?? 0));
        if (!$review || (int) $review->getAttribute('website_id') !== (int) $website->getAttribute('id')) {
            return $this->error('غير موجود', 404);
        }
        $status = (string) $this->get('status');
        if (!in_array($status, ['approved', 'rejected', 'pending'], true)) {
            return $this->error('حالة غير صحيحة', 422);
        }
        $review->setAttribute('status', $status);
        $review->save();
        return $this->success([], 'تم التحديث');
    }

    /** GET /api/site-dashboard/{id}/leads */
    public function leads(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }

        $rows = (new WebsiteLead())->allFor((int) $website->getAttribute('id'));
        $leads = array_map(fn ($l) => [
            'id' => (int) $l->getAttribute('id'),
            'visitor_name' => $l->getAttribute('visitor_name'),
            'phone' => $l->getAttribute('phone'),
            'email' => $l->getAttribute('email'),
            'message' => $l->getAttribute('message'),
            'status' => $l->getAttribute('status'),
        ], $rows);

        return $this->success(['leads' => $leads]);
    }

    /** PUT /api/site-dashboard/{id}/leads/{leadId} */
    public function updateLead(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }

        $lead = (new WebsiteLead())->find((int) ($params['leadId'] ?? 0));
        if (!$lead || (int) $lead->getAttribute('website_id') !== (int) $website->getAttribute('id')) {
            return $this->error('غير موجود', 404);
        }
        $status = (string) $this->get('status');
        if (!in_array($status, ['new', 'contacted', 'closed'], true)) {
            return $this->error('حالة غير صحيحة', 422);
        }
        $lead->setAttribute('status', $status);
        $lead->save();
        return $this->success([], 'تم التحديث');
    }

    // ================================================================ استوديو التصميم

    private const STYLE_LABELS = [
        'classic' => ['كلاسيك', 'Classic', 'نظيف ومتوازن لأي نشاط', 'Clean and balanced for any business'],
        'luxe' => ['فاخر', 'Luxe', 'رحلات خاصة وVIP', 'Private and VIP trips'],
        'adventure' => ['مغامرة', 'Adventure', 'سفاري وصحرا وأنشطة', 'Safari, desert and activities'],
        'coastal' => ['ساحلي', 'Coastal', 'بحر وغطس ورحلات مراكب', 'Sea, diving and boat trips'],
        'heritage' => ['تاريخي', 'Heritage', 'آثار ومعابد وجولات ثقافية', 'Temples, history and culture'],
        'minimal' => ['بسيط', 'Minimal', 'هادي وعصري', 'Calm and modern'],
    ];

    private function saveContent(GeneratedWebsite $w, array $c): void
    {
        $w->setAttribute('content_json', json_encode($c, JSON_UNESCAPED_UNICODE));
        $w->save();
    }

    /** GET /api/site-dashboard/{id}/studio */
    public function studio(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $w = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$w) return $this->error('غير موجود', 404);
        $c = $w->getContent();
        $slug = (string) $w->getAttribute('slug');
        $styles = [];
        $isAr = str_starts_with(current_lang(), 'ar');
        foreach (self::STYLE_LABELS as $k => [$ar, $en, $hintAr, $hintEn]) $styles[] = ['key' => $k, 'ar' => $ar, 'en' => $en, 'hint' => $isAr ? $hintAr : $hintEn];
        $colors = [];
        foreach (WebsiteBuilderController::THEMES as $k => $v) $colors[] = ['key' => $k, 'hex' => $v['accent']];
        $tours = [];
        foreach ((array) ($c['tours'] ?? $c['rooms'] ?? []) as $t) {
            if (!empty($t['slug'])) $tours[] = ['slug' => (string) $t['slug'], 'name' => (string) ($t['name'] ?? ''), 'image_url' => (string) ($t['image_url'] ?? '')];
        }
        return $this->success([
            'slug' => $slug, 'style' => (string) ($c['style'] ?? ''), 'auto_style' => class_exists('TourSiteTheme') ? TourSiteTheme::pickStyle(array_diff_key($c, ['style' => 1]), $slug) : 'classic',
            'styles' => $styles, 'theme_color' => (string) ($w->getAttribute('theme_color') ?: 'gold'), 'colors' => $colors,
            'logo_url' => (string) ($w->getAttribute('logo_url') ?? ''), 'hero_image' => (string) ($c['hero_image'] ?? ''),
            'gallery' => array_values(array_map(fn ($g) => is_array($g) ? (string) ($g['src'] ?? '') : (string) $g, (array) ($c['gallery'] ?? []))),
            'tours' => $tours, 'is_tours' => isset($c['tours']),
        ]);
    }

    /** POST /api/site-dashboard/{id}/studio {style?, theme_color?} */
    public function saveStudio(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($e = $this->verifyCsrf()) return $e;
        $w = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$w) return $this->error('غير موجود', 404);
        $c = $w->getContent();
        if ($this->get('style') !== null) {
            $st = (string) $this->get('style');
            if ($st === '') unset($c['style']);
            elseif (isset(self::STYLE_LABELS[$st])) $c['style'] = $st;
            $w->setAttribute('content_json', json_encode($c, JSON_UNESCAPED_UNICODE));
        }
        $col = (string) $this->get('theme_color', '');
        if ($col !== '' && isset(WebsiteBuilderController::THEMES[$col])) $w->setAttribute('theme_color', $col);
        $w->save();
        return $this->success([], 'اتحفظ');
    }

    /** POST /api/site-dashboard/{id}/upload (multipart: file, kind=logo|hero|gallery|tour, tour_slug?) */
    public function upload(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if (($e = $this->verifyCsrf()) || ($e = $this->rateLimitGuard('user', 'site_upload', 60, 3600))) return $e;
        $w = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$w) return $this->error('غير موجود', 404);
        $kind = (string) ($_POST['kind'] ?? '');
        $id = (int) $w->getAttribute('id');
        $c = $w->getContent();
        $media = new SiteMediaService();
        if ($kind === 'gallery' && count((array) ($c['gallery'] ?? [])) >= SiteMediaService::maxGallery()) return $this->error('الجاليري فيه أقصى عدد صور (24)', 422);
        $tourKey = null;
        if ($kind === 'tour') {
            $slug = (string) ($_POST['tour_slug'] ?? '');
            $listKey = isset($c['tours']) ? 'tours' : 'rooms';
            foreach ((array) ($c[$listKey] ?? []) as $i => $t) if (($t['slug'] ?? '') === $slug) $tourKey = [$listKey, $i];
            if (!$tourKey) return $this->error('الرحلة مش موجودة', 404);
        }
        $r = $media->store($id, $kind, $_FILES['file'] ?? []);
        if (empty($r['success'])) return $this->error($r['error'] ?? 'تعذر رفع الصورة', 422);
        $url = $r['url'];
        if ($kind === 'logo') {
            $media->delete($id, (string) $w->getAttribute('logo_url'));
            $w->setAttribute('logo_url', $url);
            $w->save();
        } elseif ($kind === 'hero') {
            $media->delete($id, (string) ($c['hero_image'] ?? ''));
            $c['hero_image'] = $url;
            $this->saveContent($w, $c);
        } elseif ($kind === 'gallery') {
            $c['gallery'] = array_values((array) ($c['gallery'] ?? []));
            $c['gallery'][] = ['src' => $url, 'alt' => (string) ($c['business_name'] ?? '')];
            $this->saveContent($w, $c);
        } else {
            [$lk, $i] = $tourKey;
            $media->delete($id, (string) ($c[$lk][$i]['image_url'] ?? ''));
            $c[$lk][$i]['image_url'] = $url;
            $this->saveContent($w, $c);
        }
        return $this->success(['url' => $url], 'الصورة اترفعت');
    }

    /** POST /api/site-dashboard/{id}/media/remove {kind, src?, tour_slug?} */
    public function removeMedia(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($e = $this->verifyCsrf()) return $e;
        $w = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$w) return $this->error('غير موجود', 404);
        $id = (int) $w->getAttribute('id');
        $c = $w->getContent();
        $media = new SiteMediaService();
        $kind = (string) $this->get('kind');
        if ($kind === 'logo') {
            $media->delete($id, (string) $w->getAttribute('logo_url'));
            $w->setAttribute('logo_url', '');
            $w->save();
        } elseif ($kind === 'hero') {
            $media->delete($id, (string) ($c['hero_image'] ?? ''));
            unset($c['hero_image']);
            $this->saveContent($w, $c);
        } elseif ($kind === 'gallery') {
            $src = (string) $this->get('src');
            $c['gallery'] = array_values(array_filter((array) ($c['gallery'] ?? []), fn ($g) => (is_array($g) ? ($g['src'] ?? '') : $g) !== $src));
            $media->delete($id, $src);
            $this->saveContent($w, $c);
        }
        return $this->success([], 'اتشالت');
    }

    private function ownedWebsite(int $id): ?GeneratedWebsite
    {
        if (!$id) {
            return null;
        }
        $website = (new GeneratedWebsite())->find($id);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->user['id']) {
            return null;
        }
        return $website;
    }

    public function domainStatus(array $params=[]): array{
        $site=$this->ownedWebsite((int)($params['id']??0));if(!$site)return $this->error('غير موجود',404);
        return $this->success(CustomDomainService::status((int)$site->getAttribute('id'),(int)$this->user['id']));
    }
    public function verifyDomain(array $params=[]): array{
        $site=$this->ownedWebsite((int)($params['id']??0));if(!$site)return $this->error('غير موجود',404);
        try{return $this->success(CustomDomainService::verify((int)$site->getAttribute('id'),(int)$this->user['id']));}catch(Throwable $e){return $this->error(CustomerError::message($e->getMessage()),422);}
    }

}
