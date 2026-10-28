<?php

/**
 * Tourfecto - CRM Controller
 * لا يوجد نظام CRM منفصل في أي موديول مرفوع؛ هذه اللوحة تجمّع بيانات
 * العملاء الموجودة فعليًا (websites, reviews, agency_clients) في واجهة
 * واحدة بدل تكرار جدول "عملاء" موازٍ يكرر بيانات users/websites أصلًا.
 * @version 1.0.0
 */
class CrmController extends Controller
{
    /** @var CrmLeadService */
    private $leadService;
    private $permissionService;

    public function __construct()
    {
        parent::__construct();
        $this->leadService = new CrmLeadService();
        $this->permissionService = new CrmPermissionService();
    }

    /**
     * الحساب (Tenant) الفعلي - راجع نفس الشرح في CrmApiController::tenantId().
     * إضافة المرحلة 6 (بند 30 - استكمال): هذه الدالة كانت غائبة تمامًا من
     * هذا الملف في المرحلة 5، وبالتالي Leads/Deals ظلّت بمعزل عن نظام
     * الفريق الجديد. تمت إضافتها هنا الآن + استبدال $this->user['id'] بيها
     * في مواضع العزل الفعلية فقط (وليس كل الاستخدامات - راجع CHANGELOG).
     */
    private function tenantId(): int
    {
        return $this->permissionService->resolveTenantId((int) ($this->user['id'] ?? 0));
    }

    /** شريط تابات مشترك بين صفحات CRM التلاتة */
    private function crmTabsHtml(string $active): string
    {
        $tabs = [
            'overview' => [$this->tr('crm.tab.overview'), '/crm'],
            'leads' => [$this->tr('crm.tab.leads'), '/crm/leads'],
            'deals' => [$this->tr('crm.tab.deals'), '/crm/deals'],
            'contacts' => [$this->tr('crm.tab.contacts'), '/crm/contacts'],
            'companies' => [$this->tr('crm.tab.companies'), '/crm/companies'],
            'tasks' => [$this->tr('crm.tab.tasks'), '/crm/tasks'],
            'appointments' => [$this->tr('crm.tab.appointments'), '/crm/appointments'],
            'automation' => [$this->tr('crm.tab.automation'), '/crm/automation'],
            'sequences'=>['تسلسلات / Sequences','/crm/sequences'],
            'team' => [$this->tr('crm.tab.team'), '/crm/team'],
            'reports' => [$this->tr('crm.tab.reports'), '/crm/reports'],
        ];
        $html = '<div class="p-tabs" style="margin-bottom:18px;">';
        foreach ($tabs as $key => [$label, $url]) {
            $activeClass = $key === $active ? ' active' : '';
            $html .= "<a href=\"{$url}\" class=\"p-tab{$activeClass}\" style=\"text-decoration:none;\">{$label}</a>";
        }
        return $html . '</div>';
    }

    /** GET /crm */
    public function index(array $params = []): array
    {
        $tabsHtml = $this->crmTabsHtml('overview');
        $body = <<<HTML
        {$tabsHtml}
HTML;
        $body .= <<<HTML
        <div class="p-grid cols-3" id="crmStats"><div class="p-empty">{$this->tr('common.loading')}</div></div>
        <div class="p-card no-pad" style="margin-top:20px;">
            <div class="p-card-head" style="padding:18px 20px 0;"><h3>{$this->tr('crm.sites.title')}</h3></div>
            <div class="p-table-scroll"><table class="p-table" id="crmTable">
                <thead><tr><th>{$this->tr('crm.col.site_name')}</th><th>{$this->tr('crm.col.review_count')}</th><th>{$this->tr('crm.col.avg_rating')}</th><th>{$this->tr('crm.col.last_activity')}</th></tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="4">{$this->tr('common.loading')}</td></tr></tbody>
            </table></div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON;

    async function load() {
        const res = await fetchJSON('/api/crm/overview');
        if (!res.success) return;

        document.getElementById('crmStats').innerHTML = `
            <div class="p-card stat-tile"><div class="stat-icon blue">🌐</div><div class="stat-info"><div class="stat-value">${esc(res.data.total_websites)}</div><div class="stat-label">${I18N['crm.stat.websites']}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon green">⭐</div><div class="stat-info"><div class="stat-value">${esc(res.data.total_reviews)}</div><div class="stat-label">${I18N['crm.stat.total_reviews']}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon orange">📈</div><div class="stat-info"><div class="stat-value">${esc(res.data.avg_rating || '-')}</div><div class="stat-label">${I18N['crm.stat.avg_rating']}</div></div></div>
        `;

        const tbody = document.querySelector('#crmTable tbody');
        if (res.data.websites && res.data.websites.length) {
            tbody.innerHTML = res.data.websites.map(w => `
                <tr><td>${esc(w.brand_name || w.domain)}</td><td>${esc(w.review_count || 0)}</td><td>${w.avg_rating ? esc(w.avg_rating) + ' ⭐' : '-'}</td><td class="p-cell-muted">${esc(w.created_at || '-')}</td></tr>
            `).join('');
        } else {
            tbody.innerHTML = `<tr><td colspan="4" class="p-cell-muted text-center">${I18N['crm.no_data']}</td></tr>`;
        }
    }
    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('crm', $this->tr('crm.page.title'), $this->tr('crm.page.subtitle'), $body, $script);
        exit;
    }

    /** GET /api/crm/overview */
    public function overview(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $userId = (int) $this->user['id'];

            $websites = $this->db->query(
                "SELECT w.id, w.brand_name, w.domain, w.created_at,
                        COUNT(r.id) as review_count, ROUND(AVG(r.rating), 1) as avg_rating
                 FROM websites w
                 LEFT JOIN reviews r ON r.website_id = w.id
                 WHERE w.user_id = ? AND w.is_active = 1
                 GROUP BY w.id
                 ORDER BY w.created_at DESC",
                [$userId]
            );

            $totalReviews = array_sum(array_column($websites, 'review_count'));
            $ratings = array_filter(array_column($websites, 'avg_rating'));
            $avgRating = $ratings ? round(array_sum($ratings) / count($ratings), 1) : null;

            return $this->success([
                'total_websites' => count($websites),
                'total_reviews' => $totalReviews,
                'avg_rating' => $avgRating,
                'websites' => $websites,
            ]);
        } catch (Exception $e) {
            Logger::error('CRM overview Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تحميل بيانات CRM', 500);
        }
    }

    /** GET /api/crm/leads */
    public function listLeads(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $leads = $this->db->query(
                "SELECT l.id, l.status, l.score, l.last_engagement_at, l.created_at,
                        c.name as contact_name, c.email as contact_email, c.phone as contact_phone
                 FROM crm_leads l
                 JOIN crm_contacts c ON c.id = l.contact_id
                 WHERE c.user_id = ?
                 ORDER BY l.created_at DESC
                 LIMIT 50",
                [$this->tenantId()]
            );
            return $this->success(['leads' => $leads]);
        } catch (Exception $e) {
            Logger::error('listLeads Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تحميل العملاء المحتملين', 500);
        }
    }

    /** POST /api/crm/leads */
    public function createLead(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['name' => 'required'])) {
            return $this->error('اسم جهة الاتصال مطلوب', 422);
        }

        try {
            $contact = $this->leadService->createContact($this->tenantId(), [
                'name' => $this->get('name'),
                'email' => $this->get('email'),
                'phone' => $this->get('phone'),
                'source' => $this->get('source', 'manual'),
            ]);
            $lead = $this->leadService->createLead((int) $contact->getAttribute('id'), (int) $this->user['id']);

            return $this->success(['contact' => $contact->toArray(), 'lead' => $lead->toArray()], 'تم الإنشاء', 201);
        } catch (Exception $e) {
            Logger::error('createLead Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إنشاء العميل المحتمل', 500);
        }
    }

    /** POST /api/crm/leads/{id}/status */
    public function updateLeadStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['status' => 'required'])) {
            return $this->error('الحالة مطلوبة', 422);
        }

        $allowed = ['new', 'nurturing', 'qualified', 'disqualified', 'converted'];
        if (!in_array($this->get('status'), $allowed, true)) {
            return $this->error('حالة غير صحيحة', 422);
        }

        try {
            $lead = $this->leadService->updateStatus((int) ($params['id'] ?? 0), (string) $this->get('status'), $this->tenantId());
            return $this->success(['lead' => $lead->toArray()], 'تم التحديث');
        } catch (Exception $e) {
            Logger::error('updateLeadStatus Error', ['message' => $e->getMessage()]);
            // إصلاح المرحلة 9: استخدام كود الخطأ الفعلي (404 لو Lead مش
            // موجود/مش ملك الحساب) بدل 500 ثابت دايمًا - يتماشى مع تصحيح
            // ثغرة التحقق من الملكية.
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
            return $this->error(CustomerError::message($e->getMessage(), 500), $code);
        }
    }

    /** GET /api/crm/pipeline-stages */
    public function listPipelineStages(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            // ملاحظة: Model::where(['agency_id' => null]) كانت هتولّد
            // `agency_id = ?` مع NULL كمعامل، وده تعبير SQL دايمًا كاذب
            // (لازم IS NULL صراحة) - فاستخدمت SQL خام هنا بدل الـ Model.
            $stages = $this->db->query(
                "SELECT * FROM crm_pipeline_stages WHERE agency_id IS NULL ORDER BY sort_order ASC"
            );
            return $this->success(['stages' => $stages]);
        } catch (Exception $e) {
            Logger::error('listPipelineStages Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تحميل مراحل المسار', 500);
        }
    }

    /** GET /api/crm/deals */
    public function listDeals(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $deals = $this->db->query(
                "SELECT d.*, s.name as stage_name, s.color as stage_color
                 FROM crm_deals d
                 JOIN crm_pipeline_stages s ON s.id = d.stage_id
                 WHERE d.owner_user_id = ?
                 ORDER BY d.created_at DESC
                 LIMIT 100",
                [$this->tenantId()]
            );
            return $this->success(['deals' => $deals]);
        } catch (Exception $e) {
            Logger::error('listDeals Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تحميل الصفقات', 500);
        }
    }

    /** POST /api/crm/deals */
    public function createDeal(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['title' => 'required', 'stage_id' => 'required'])) {
            return $this->error('بيانات ناقصة', 422);
        }

        try {
            $deal = new CrmDeal([
                'owner_user_id' => $this->tenantId(),
                'contact_id' => $this->get('contact_id'),
                'lead_id' => $this->get('lead_id'),
                'stage_id' => (int) $this->get('stage_id'),
                'title' => $this->get('title'),
                'value' => $this->get('value', 0),
                'currency' => $this->get('currency', 'USD'),
            ]);
            $deal->save();

            ActivityLog::record('crm', 'deal.created', [
                'user_id' => $this->user['id'], 'subject_type' => 'crm_deals', 'subject_id' => (int) $deal->getAttribute('id'),
            ]);

            // إضافة المرحلة 3 (بند 12/36): سطر واحد لإطلاق Automation - بدون
            // أي تغيير في منطق إنشاء الصفقة نفسه.
            // تحديث المرحلة 6: tenantId() بدل user['id'] مباشرة عشان قواعد
            // الأتمتة تتطابق مع حساب الـTenant الصحيح لو المُنفّذ عضو فريق.
            (new CrmAutomationService())->trigger('deal.created', $this->tenantId(), [
                'deal_id' => (int) $deal->getAttribute('id'),
            ]);

            return $this->success(['deal' => $deal->toArray()], 'تم إنشاء الصفقة', 201);
        } catch (Exception $e) {
            Logger::error('createDeal Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إنشاء الصفقة', 500);
        }
    }

    /** POST /api/crm/deals/{id}/stage - نقل صفقة لمرحلة تانية (كانت الوظيفة دي ناقصة بالكامل - مفيش طريقة كانت موجودة لتحديث صفقة بعد إنشائها) */
    public function updateDealStage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['stage_id' => 'required'])) {
            return $this->error('المرحلة الجديدة مطلوبة', 422);
        }

        try {
            $deal = (new CrmDeal())->find((int) ($params['id'] ?? 0));
            if (!$deal || (int) $deal->getAttribute('owner_user_id') !== $this->tenantId()) {
                return $this->error('الصفقة غير موجودة', 404);
            }

            $stageId = (int) $this->get('stage_id');
            $stageRows = $this->db->query("SELECT * FROM crm_pipeline_stages WHERE id = ? LIMIT 1", [$stageId]);
            if (empty($stageRows)) {
                return $this->error('المرحلة غير موجودة', 404);
            }
            $stage = $stageRows[0];

            $deal->setAttribute('stage_id', $stageId);
            if ((bool) $stage['is_won']) {
                $deal->setAttribute('status', 'won');
                $deal->setAttribute('closed_at', date('Y-m-d H:i:s'));
            } elseif ((bool) $stage['is_lost']) {
                $deal->setAttribute('status', 'lost');
                $deal->setAttribute('closed_at', date('Y-m-d H:i:s'));
            } else {
                $deal->setAttribute('status', 'open');
                $deal->setAttribute('closed_at', null);
            }
            $deal->save();

            // ربط جديد: لو الصفقة اتقفلت "مكسوبة" وعميلنا مفعّل خيار
            // "ربط تلقائي مع CRM" في إعدادات حملة طلب المراجعات، ننشئ
            // طلب مراجعة تلقائي للـ contact بتاع الصفقة - من غير ما
            // يوقف نقل الصفقة نفسها لو فشل لأي سبب (رقم غلط، مفيش موقع...).
            if ((bool) $stage['is_won'] && class_exists('ReviewRequestService')) {
                try {
                    $contact = (new CrmContact())->find((int) $deal->getAttribute('contact_id'));
                    if ($contact) {
                        (new ReviewRequestService())->maybeCreateFromCrmDeal(
                            $this->tenantId(),
                            (string) $contact->getAttribute('name'),
                            $contact->getAttribute('phone')
                        );
                    }
                } catch (Exception $e) {
                    Logger::warning('CRM auto review-request skipped', ['deal_id' => $deal->getAttribute('id'), 'message' => $e->getMessage()]);
                }
            }

            ActivityLog::record('crm', 'deal.stage_changed', [
                'user_id' => $this->user['id'], 'subject_type' => 'crm_deals', 'subject_id' => $deal->getAttribute('id'),
            ]);

            // إضافة المرحلة 3 (بند 12/36): سطر واحد لإطلاق Automation - نفس
            // نمط تكامل ReviewRequestService فوق بالظبط (استدعاء إضافي بعد
            // نجاح النقل، بدون ما يمنع نقل الصفقة نفسها لو فشل لأي سبب).
            // 'deal.won'/'deal.lost' يغطيان مثال الطلب الأصلي حرفيًا: "WHEN:
            // Deal becomes Won THEN: Create Customer, Create Onboarding Task,
            // Notify Team".
            try {
                $automationEvent = 'deal.stage_changed';
                if ((bool) $stage['is_won']) {
                    $automationEvent = 'deal.won';
                } elseif ((bool) $stage['is_lost']) {
                    $automationEvent = 'deal.lost';
                }
                (new CrmAutomationService())->trigger($automationEvent, $this->tenantId(), [
                    'deal_id' => (int) $deal->getAttribute('id'), 'stage_id' => $stageId,
                ]);
            } catch (Exception $e) {
                Logger::warning('CRM automation trigger skipped', ['deal_id' => $deal->getAttribute('id'), 'message' => $e->getMessage()]);
            }

            return $this->success(['deal' => $deal->toArray()], 'تم النقل');
        } catch (Exception $e) {
            Logger::error('updateDealStage Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر نقل الصفقة', 500);
        }
    }

    /** GET /crm/leads */
    public function showLeads(array $params = []): array
    {
        $tabsHtml = $this->crmTabsHtml('leads');
        $body = <<<HTML
        {$tabsHtml}
        <div class="p-toolbar" style="flex-wrap:wrap;gap:8px;">
            <input type="text" id="leadSearch" class="form-control" style="max-width:200px;" placeholder="{$this->tr('crm.contacts.search_placeholder')}">
            <select id="leadFilterStatus" class="form-control" style="max-width:150px;">
                <option value="">{$this->tr('crm.filters.status_any')}</option>
                <option value="new">{$this->tr('crm.leads.status.new')}</option>
                <option value="nurturing">{$this->tr('crm.leads.status.nurturing')}</option>
                <option value="qualified">{$this->tr('crm.leads.status.qualified')}</option>
                <option value="disqualified">{$this->tr('crm.leads.status.disqualified')}</option>
                <option value="converted">{$this->tr('crm.leads.status.converted')}</option>
            </select>
            <button class="p-btn xs" onclick="applyLeadFilters()">{$this->tr('crm.filters.apply')}</button>
            <button class="p-btn xs" onclick="clearLeadFilters()">{$this->tr('crm.filters.clear')}</button>
            <span style="flex:1;"></span>
            <a class="p-btn xs" href="/api/crm/leads/export">{$this->tr('crm.export.button')}</a>
            <button class="p-btn" onclick="document.getElementById('newLeadModal').classList.add('open')">+ {$this->tr('crm.leads.new')}</button>
        </div>
        <div class="p-card no-pad">
            <div class="p-table-scroll"><table class="p-table" id="leadsTable">
                <thead><tr><th>{$this->tr('crm.leads.col.name')}</th><th>{$this->tr('crm.leads.col.email')}</th><th>{$this->tr('crm.leads.col.phone')}</th><th>{$this->tr('crm.leads.col.status')}</th><th>{$this->tr('crm.leads.col.last_engagement')}</th></tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="5">{$this->tr('common.loading')}</td></tr></tbody>
            </table></div>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;">
            <div id="leadsPaginationInfo" class="p-cell-muted" style="font-size:12.5px;"></div>
            <div>
                <button class="p-btn xs" id="leadsPrevBtn" onclick="changeLeadsPage(-1)">‹ {$this->tr('crm.pagination.prev')}</button>
                <button class="p-btn xs" id="leadsNextBtn" onclick="changeLeadsPage(1)">{$this->tr('crm.pagination.next')} ›</button>
            </div>
        </div>

        <div class="p-modal-overlay" id="newLeadModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>{$this->tr('crm.leads.new')}</h3><button class="p-modal-close" onclick="document.getElementById('newLeadModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label class="form-label">{$this->tr('crm.leads.name')} *</label>
                    <input type="text" id="leadName" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label">{$this->tr('crm.leads.email')}</label>
                    <input type="email" id="leadEmail" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label">{$this->tr('crm.leads.phone')}</label>
                    <input type="text" id="leadPhone" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label">{$this->tr('crm.leads.source')}</label>
                    <select id="leadSource" class="form-control">
                        <option value="manual">{$this->tr('crm.leads.source.manual')}</option>
                        <option value="website_form">{$this->tr('crm.leads.source.website_form')}</option>
                        <option value="referral">{$this->tr('crm.leads.source.referral')}</option>
                        <option value="other">{$this->tr('crm.leads.source.other')}</option>
                    </select>
                </div>
                <div class="p-modal-foot"><button class="p-btn primary" onclick="addLead()">{$this->tr('common.add')}</button></div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;
    let currentPage = 1;
    let totalPages = 1;

    const statusLabels = {
        new: I18N['crm.leads.status.new'], nurturing: I18N['crm.leads.status.nurturing'], qualified: I18N['crm.leads.status.qualified'],
        disqualified: I18N['crm.leads.status.disqualified'], converted: I18N['crm.leads.status.converted'],
    };
    const statusOptions = Object.entries(statusLabels).map(([v, l]) => `<option value="${v}">${l}</option>`).join('');

    window.changeLeadStatus = async function (id, status) {
        const res = await fetchJSON('/api/crm/leads/' + id + '/status', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ status }),
        });
        if (res.success) toast(I18N['common.updated'], 'success');
        else toast(res.error || I18N['common.update_failed'], 'error');
        load();
    };

    window.addLead = async function () {
        const name = document.getElementById('leadName').value.trim();
        if (!name) { toast(I18N['crm.leads.name_required'], 'error'); return; }
        const res = await fetchJSON('/api/crm/leads', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                name,
                email: document.getElementById('leadEmail').value.trim(),
                phone: document.getElementById('leadPhone').value.trim(),
                source: document.getElementById('leadSource').value,
            }),
        });
        document.getElementById('newLeadModal').classList.remove('open');
        if (res.success) { toast(I18N['common.added'], 'success'); load(); }
        else toast(res.error || I18N['crm.leads.add_failed'], 'error');
    };

    window.applyLeadFilters = function () { currentPage = 1; load(); };
    window.clearLeadFilters = function () {
        document.getElementById('leadSearch').value = '';
        document.getElementById('leadFilterStatus').value = '';
        currentPage = 1;
        load();
    };
    window.changeLeadsPage = function (delta) {
        const next = currentPage + delta;
        if (next < 1 || next > totalPages) return;
        currentPage = next;
        load();
    };

    async function load() {
        const params = new URLSearchParams({ page: currentPage, per_page: 25 });
        const search = document.getElementById('leadSearch').value.trim();
        const status = document.getElementById('leadFilterStatus').value;
        if (search) params.set('search', search);
        if (status) params.set('status', status);

        const res = await fetchJSON('/api/crm/leads/search?' + params.toString());
        const tbody = document.querySelector('#leadsTable tbody');
        if (res.success && res.data.items && res.data.items.length) {
            tbody.innerHTML = res.data.items.map(l => `
                <tr>
                    <td>${esc(l.contact_name || '-')}</td>
                    <td style="direction:ltr;text-align:left;">${esc(l.contact_email || '-')}</td>
                    <td style="direction:ltr;text-align:left;">${esc(l.contact_phone || '-')}</td>
                    <td><select class="p-select xs" onchange="changeLeadStatus(${l.id}, this.value)">${statusOptions.replace(`value="${l.status}"`, `value="${l.status}" selected`)}</select></td>
                    <td class="p-cell-muted">${l.last_engagement_at ? formatDate(l.last_engagement_at) : '-'}</td>
                </tr>`).join('');
            totalPages = res.data.total_pages || 1;
            document.getElementById('leadsPaginationInfo').textContent =
                I18N['crm.pagination.page_of'].replace('{page}', res.data.page).replace('{total}', totalPages) + ' · ' + res.data.total;
            document.getElementById('leadsPrevBtn').disabled = res.data.page <= 1;
            document.getElementById('leadsNextBtn').disabled = res.data.page >= totalPages;
        } else {
            tbody.innerHTML = `<tr><td colspan="5" class="p-cell-muted text-center">${I18N['crm.leads.none_yet']}</td></tr>`;
            document.getElementById('leadsPaginationInfo').textContent = '';
        }
    }
    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('crm', $this->tr('crm.leads.title'), $this->tr('crm.leads.subtitle'), $body, $script);
        exit;
    }

    /** GET /crm/deals */
    public function showDeals(array $params = []): array
    {
        $tabsHtml = $this->crmTabsHtml('deals');
        $body = <<<HTML
        {$tabsHtml}
        <div class="p-toolbar" style="flex-wrap:wrap;gap:8px;">
            <button class="p-btn xs" id="viewToggleKanban" onclick="switchView('kanban')">{$this->tr('crm.deals.view_kanban')}</button>
            <button class="p-btn xs" id="viewToggleList" onclick="switchView('list')">{$this->tr('crm.deals.view_list')}</button>
            <span style="flex:1;"></span>
            <a class="p-btn xs" href="/api/crm/deals/export">{$this->tr('crm.export.button')}</a>
            <button class="p-btn" onclick="document.getElementById('newDealModal').classList.add('open')">+ {$this->tr('crm.deals.new')}</button>
        </div>
        <div id="dealsBoard" style="display:flex;gap:14px;overflow-x:auto;padding-bottom:10px;">{$this->tr('common.loading')}</div>

        <div id="dealsListView" style="display:none;">
            <div class="p-toolbar" style="flex-wrap:wrap;gap:8px;">
                <input type="text" id="dealSearch" class="form-control" style="max-width:200px;" placeholder="{$this->tr('crm.contacts.search_placeholder')}">
                <select id="dealFilterStatus" class="form-control" style="max-width:150px;">
                    <option value="">{$this->tr('crm.filters.status_any')}</option>
                    <option value="open">{$this->tr('crm.deals.status.open')}</option>
                    <option value="won">{$this->tr('crm.deals.status.won')}</option>
                    <option value="lost">{$this->tr('crm.deals.status.lost')}</option>
                </select>
                <input type="number" id="dealMinValue" class="form-control" style="max-width:120px;" placeholder="{$this->tr('crm.deals.min_value')}">
                <input type="number" id="dealMaxValue" class="form-control" style="max-width:120px;" placeholder="{$this->tr('crm.deals.max_value')}">
                <button class="p-btn xs" onclick="applyDealFilters()">{$this->tr('crm.filters.apply')}</button>
                <button class="p-btn xs" onclick="clearDealFilters()">{$this->tr('crm.filters.clear')}</button>
            </div>
            <div class="p-card no-pad">
                <div class="p-table-scroll"><table class="p-table" id="dealsTable">
                    <thead><tr><th>{$this->tr('crm.deals.title_label')}</th><th>{$this->tr('crm.deals.stage')}</th><th>{$this->tr('crm.deals.value')}</th><th>{$this->tr('crm.leads.col.status')}</th></tr></thead>
                    <tbody><tr class="p-loading-row"><td colspan="4">{$this->tr('common.loading')}</td></tr></tbody>
                </table></div>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;">
                <div id="dealsPaginationInfo" class="p-cell-muted" style="font-size:12.5px;"></div>
                <div>
                    <button class="p-btn xs" id="dealsPrevBtn" onclick="changeDealsPage(-1)">‹ {$this->tr('crm.pagination.prev')}</button>
                    <button class="p-btn xs" id="dealsNextBtn" onclick="changeDealsPage(1)">{$this->tr('crm.pagination.next')} ›</button>
                </div>
            </div>
        </div>

        <div class="p-modal-overlay" id="newDealModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>{$this->tr('crm.deals.new')}</h3><button class="p-modal-close" onclick="document.getElementById('newDealModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label class="form-label">{$this->tr('crm.deals.title_label')} *</label>
                    <input type="text" id="dealTitle" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label">{$this->tr('crm.deals.value')}</label>
                    <input type="number" id="dealValue" class="form-control" style="margin-bottom:10px;" value="0">
                    <label class="form-label">{$this->tr('crm.deals.currency')}</label>
                    <select id="dealCurrency" class="form-control" style="margin-bottom:10px;">
                        <option value="USD">USD</option>
                        <option value="EGP">EGP</option>
                        <option value="EUR">EUR</option>
                    </select>
                    <label class="form-label">{$this->tr('crm.deals.stage')}</label>
                    <select id="dealStage" class="form-control"></select>
                </div>
                <div class="p-modal-foot"><button class="p-btn primary" onclick="addDeal()">{$this->tr('common.add')}</button></div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    let stages = [];
    let dragDealId = null;
    let currentView = 'kanban';
    let listPage = 1;
    let listTotalPages = 1;

    async function loadStages() {
        const res = await fetchJSON('/api/crm/pipeline-stages');
        stages = res.success ? res.data.stages : [];
        document.getElementById('dealStage').innerHTML = stages.map(s => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
    }

    window.addDeal = async function () {
        const title = document.getElementById('dealTitle').value.trim();
        if (!title) { toast(I18N['crm.deals.title_required'], 'error'); return; }
        const res = await fetchJSON('/api/crm/deals', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                title,
                value: document.getElementById('dealValue').value || 0,
                currency: document.getElementById('dealCurrency').value,
                stage_id: document.getElementById('dealStage').value,
            }),
        });
        document.getElementById('newDealModal').classList.remove('open');
        if (res.success) { toast(I18N['crm.deals.created'], 'success'); loadCurrentView(); }
        else toast(res.error || I18N['crm.deals.create_failed'], 'error');
    };

    window.onDealDragStart = function (id) { dragDealId = id; };
    window.onColumnDrop = async function (stageId) {
        if (!dragDealId) return;
        const res = await fetchJSON('/api/crm/deals/' + dragDealId + '/stage', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ stage_id: stageId }),
        });
        dragDealId = null;
        if (res.success) load();
        else toast(res.error || I18N['crm.deals.move_failed'], 'error');
    };

    // ================= Kanban (الأصلية - لم تتغيّر) =================

    async function load() {
        const res = await fetchJSON('/api/crm/deals');
        const deals = res.success ? res.data.deals : [];
        const board = document.getElementById('dealsBoard');

        board.innerHTML = stages.map(stage => {
            const stageDeals = deals.filter(d => String(d.stage_id) === String(stage.id));
            const total = stageDeals.reduce((sum, d) => sum + parseFloat(d.value || 0), 0);
            return `
            <div style="min-width:250px;flex-shrink:0;" ondragover="event.preventDefault()" ondrop="onColumnDrop(${stage.id})">
                <div class="p-card" style="border-top:3px solid ${esc(stage.color || '#6366f1')};padding:12px;margin-bottom:10px;">
                    <strong>${esc(stage.name)}</strong>
                    <div class="p-cell-muted" style="font-size:12px;">${stageDeals.length} ${I18N['crm.deals.deal_word']} · ${total.toLocaleString()}</div>
                </div>
                ${stageDeals.map(d => `
                    <div class="p-card" draggable="true" ondragstart="onDealDragStart(${d.id})" style="padding:12px;margin-bottom:8px;cursor:grab;">
                        <div style="font-weight:700;font-size:13.5px;margin-bottom:4px;">${esc(d.title)}</div>
                        <div class="p-cell-muted" style="font-size:12.5px;">${esc(d.value || 0)} ${esc(d.currency || '')}</div>
                    </div>`).join('') || `<p class="p-cell-muted" style="font-size:12.5px;">${I18N['crm.deals.empty_column']}</p>`}
            </div>`;
        }).join('');
    }

    // ================= List View جديدة (بند 29، 37) - بديل اختياري للـKanban =================

    window.switchView = function (view) {
        currentView = view;
        document.getElementById('dealsBoard').style.display = view === 'kanban' ? 'flex' : 'none';
        document.getElementById('dealsListView').style.display = view === 'list' ? 'block' : 'none';
        document.getElementById('viewToggleKanban').classList.toggle('primary', view === 'kanban');
        document.getElementById('viewToggleList').classList.toggle('primary', view === 'list');
        loadCurrentView();
    };

    window.applyDealFilters = function () { listPage = 1; loadListView(); };
    window.clearDealFilters = function () {
        document.getElementById('dealSearch').value = '';
        document.getElementById('dealFilterStatus').value = '';
        document.getElementById('dealMinValue').value = '';
        document.getElementById('dealMaxValue').value = '';
        listPage = 1;
        loadListView();
    };
    window.changeDealsPage = function (delta) {
        const next = listPage + delta;
        if (next < 1 || next > listTotalPages) return;
        listPage = next;
        loadListView();
    };

    async function loadListView() {
        const params = new URLSearchParams({ page: listPage, per_page: 25 });
        const search = document.getElementById('dealSearch').value.trim();
        const status = document.getElementById('dealFilterStatus').value;
        const minValue = document.getElementById('dealMinValue').value;
        const maxValue = document.getElementById('dealMaxValue').value;
        if (search) params.set('search', search);
        if (status) params.set('status', status);
        if (minValue) params.set('min_value', minValue);
        if (maxValue) params.set('max_value', maxValue);

        const res = await fetchJSON('/api/crm/deals/search?' + params.toString());
        const tbody = document.querySelector('#dealsTable tbody');
        const list = res.success ? res.data.items : [];
        tbody.innerHTML = list.length ? list.map(d => `
            <tr>
                <td>${esc(d.title)}</td>
                <td><span class="p-badge" style="background:${esc(d.stage_color || '#6366f1')};color:#fff;">${esc(d.stage_name)}</span></td>
                <td>${esc(d.value)} ${esc(d.currency)}</td>
                <td><span class="p-badge">${esc(d.status)}</span></td>
            </tr>`).join('') : `<tr><td colspan="4" class="p-cell-muted text-center">${I18N['crm.deals.empty_column']}</td></tr>`;

        if (res.success) {
            listTotalPages = res.data.total_pages || 1;
            document.getElementById('dealsPaginationInfo').textContent =
                I18N['crm.pagination.page_of'].replace('{page}', res.data.page).replace('{total}', listTotalPages) + ' · ' + res.data.total;
            document.getElementById('dealsPrevBtn').disabled = res.data.page <= 1;
            document.getElementById('dealsNextBtn').disabled = res.data.page >= listTotalPages;
        }
    }

    function loadCurrentView() {
        if (currentView === 'kanban') load(); else loadListView();
    }

    loadStages().then(load);
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('crm', $this->tr('crm.deals.title'), $this->tr('crm.deals.subtitle'), $body, $script);
        exit;
    }

    // ================================================================
    // الصفحات التالية أُضيفت كجزء من موديول AI CRM (contacts/companies/
    // tasks/appointments/reports) - نفس نمط showLeads/showDeals أعلاه
    // بالضبط، وتعتمد على نقاط الـAPI في CrmApiController الجديد.
    // ================================================================

    /** GET /crm/contacts */
    public function showContacts(array $params = []): array
    {
        $tabsHtml = $this->crmTabsHtml('contacts');
        $body = <<<HTML
        {$tabsHtml}
        <div class="p-toolbar" style="flex-wrap:wrap;gap:8px;">
            <input type="text" id="contactSearch" class="form-control" style="max-width:220px;" placeholder="{$this->tr('crm.contacts.search_placeholder')}">
            <select id="filterStatus" class="form-control" style="max-width:150px;"><option value="">{$this->tr('crm.filters.status_any')}</option><option value="active">{$this->tr('crm.filters.active')}</option><option value="inactive">{$this->tr('crm.filters.inactive')}</option></select>
            <select id="filterSource" class="form-control" style="max-width:150px;"></select>
            <button class="p-btn xs" onclick="applyFilters()">{$this->tr('crm.filters.apply')}</button>
            <button class="p-btn xs" onclick="clearFilters()">{$this->tr('crm.filters.clear')}</button>
            <button class="p-btn xs" onclick="document.getElementById('saveSegmentModal').classList.add('open')">{$this->tr('crm.segments.save_current')}</button>
            <span style="flex:1;"></span>
            <button class="p-btn" onclick="document.getElementById('newContactModal').classList.add('open')">+ {$this->tr('crm.contacts.new')}</button>
        </div>

        <div style="display:flex;gap:14px;align-items:flex-start;">
            <div class="p-card" style="min-width:200px;max-width:220px;padding:14px;">
                <h4 style="margin:0 0 8px;font-size:13px;">{$this->tr('crm.segments.title')}</h4>
                <div id="segmentsList" class="p-cell-muted" style="font-size:12.5px;">{$this->tr('common.loading')}</div>
            </div>

            <div style="flex:1;min-width:0;">
                <div class="p-card no-pad">
                    <div class="p-table-scroll"><table class="p-table" id="contactsTable">
                        <thead><tr><th>{$this->tr('crm.contacts.col.name')}</th><th>{$this->tr('crm.contacts.col.email')}</th><th>{$this->tr('crm.contacts.col.phone')}</th><th>{$this->tr('crm.contacts.col.source')}</th><th>{$this->tr('crm.contacts.col.status')}</th><th></th></tr></thead>
                        <tbody><tr class="p-loading-row"><td colspan="6">{$this->tr('common.loading')}</td></tr></tbody>
                    </table></div>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;">
                    <div id="paginationInfo" class="p-cell-muted" style="font-size:12.5px;"></div>
                    <div>
                        <button class="p-btn xs" id="prevPageBtn" onclick="changePage(-1)">‹ {$this->tr('crm.pagination.prev')}</button>
                        <button class="p-btn xs" id="nextPageBtn" onclick="changePage(1)">{$this->tr('crm.pagination.next')} ›</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="p-modal-overlay" id="newContactModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>{$this->tr('crm.contacts.new')}</h3><button class="p-modal-close" onclick="document.getElementById('newContactModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label class="form-label">{$this->tr('crm.contacts.col.name')} *</label>
                    <input type="text" id="cName" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label">{$this->tr('crm.contacts.col.email')}</label>
                    <input type="email" id="cEmail" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label">{$this->tr('crm.contacts.col.phone')}</label>
                    <input type="text" id="cPhone" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label">{$this->tr('crm.leads.source')}</label>
                    <select id="cSource" class="form-control">
                        <option value="manual">{$this->tr('crm.leads.source.manual')}</option>
                        <option value="website">{$this->tr('crm.leads.source.website_form')}</option>
                        <option value="whatsapp">WhatsApp</option>
                        <option value="referral">{$this->tr('crm.leads.source.referral')}</option>
                        <option value="other">{$this->tr('crm.leads.source.other')}</option>
                    </select>
                </div>
                <div class="p-modal-foot"><button class="p-btn primary" onclick="addContact()">{$this->tr('common.add')}</button></div>
            </div>
        </div>

        <div class="p-modal-overlay" id="saveSegmentModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>{$this->tr('crm.segments.save_current')}</h3><button class="p-modal-close" onclick="document.getElementById('saveSegmentModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label class="form-label">{$this->tr('crm.segments.name')} *</label>
                    <input type="text" id="segmentName" class="form-control">
                </div>
                <div class="p-modal-foot"><button class="p-btn primary" onclick="saveSegment()">{$this->tr('common.save')}</button></div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    let currentPage = 1;
    let totalPages = 1;
    let activeSegmentId = null;

    window.addContact = async function () {
        const name = document.getElementById('cName').value.trim();
        if (!name) { toast(I18N['crm.leads.name_required'], 'error'); return; }
        const res = await fetchJSON('/api/crm/contacts', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                name,
                email: document.getElementById('cEmail').value.trim(),
                phone: document.getElementById('cPhone').value.trim(),
                source: document.getElementById('cSource').value,
            }),
        });
        document.getElementById('newContactModal').classList.remove('open');
        if (res.success) { toast(I18N['common.added'], 'success'); load(); }
        else toast(res.error || I18N['crm.leads.add_failed'], 'error');
    };

    window.applyFilters = function () { activeSegmentId = null; currentPage = 1; load(); };
    window.clearFilters = function () {
        document.getElementById('contactSearch').value = '';
        document.getElementById('filterStatus').value = '';
        document.getElementById('filterSource').value = '';
        activeSegmentId = null;
        currentPage = 1;
        load();
    };
    window.changePage = function (delta) {
        const next = currentPage + delta;
        if (next < 1 || next > totalPages) return;
        currentPage = next;
        load();
    };

    window.runSegment = function (id) {
        activeSegmentId = id;
        currentPage = 1;
        load();
    };

    window.saveSegment = async function () {
        const name = document.getElementById('segmentName').value.trim();
        if (!name) { toast(I18N['crm.segments.name_required'], 'error'); return; }
        const res = await fetchJSON('/api/crm/segments', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name, filters: currentFilters() }),
        });
        document.getElementById('saveSegmentModal').classList.remove('open');
        if (res.success) { toast(I18N['common.added'], 'success'); loadSegments(); }
        else toast(res.error || I18N['crm.leads.add_failed'], 'error');
    };

    window.deleteSegment = async function (id, event) {
        event.stopPropagation();
        const res = await fetchJSON('/api/crm/segments/' + id, { method: 'DELETE' });
        if (res.success) { toast(I18N['common.updated'], 'success'); loadSegments(); if (activeSegmentId === id) window.clearFilters(); }
        else toast(res.error, 'error');
    };

    function currentFilters() {
        const f = {};
        const search = document.getElementById('contactSearch').value.trim();
        const status = document.getElementById('filterStatus').value;
        const source = document.getElementById('filterSource').value;
        if (search) f.search = search;
        if (status) f.status = status;
        if (source) f.source = source;
        return f;
    }

    function render(list) {
        const tbody = document.querySelector('#contactsTable tbody');
        if (list.length) {
            tbody.innerHTML = list.map(c => `
                <tr>
                    <td><a href="/crm/contacts/${c.id}">${esc(c.name)}</a></td>
                    <td style="direction:ltr;text-align:left;">${esc(c.email || '-')}</td>
                    <td style="direction:ltr;text-align:left;">${esc(c.phone || '-')}</td>
                    <td>${esc(c.source || '-')}</td>
                    <td><span class="p-badge ${c.status === 'active' ? 'green' : ''}">${esc(c.status || '-')}</span></td>
                    <td><a class="p-btn xs" href="/crm/contacts/${c.id}">${I18N['crm.contacts.view_360']}</a></td>
                </tr>`).join('');
        } else {
            tbody.innerHTML = `<tr><td colspan="6" class="p-cell-muted text-center">${I18N['crm.contacts.none_yet']}</td></tr>`;
        }
    }

    async function loadSources() {
        const res = await fetchJSON('/api/crm/lead-sources');
        const select = document.getElementById('filterSource');
        select.innerHTML = `<option value="">${I18N['crm.filters.source_any']}</option>` +
            (res.success ? res.data.sources.map(s => `<option value="${esc(s.source_key)}">${esc(s.name)}</option>`).join('') : '');
    }

    async function loadSegments() {
        const res = await fetchJSON('/api/crm/segments');
        const box = document.getElementById('segmentsList');
        if (!res.success || !res.data.segments.length) { box.textContent = I18N['crm.segments.none_yet']; return; }
        box.innerHTML = res.data.segments.map(s => `
            <div style="display:flex;justify-content:space-between;align-items:center;padding:4px 0;cursor:pointer;" onclick="runSegment(${s.id})">
                <span style="${activeSegmentId === s.id ? 'font-weight:700;' : ''}">${esc(s.name)}</span>
                ${s.is_system == 0 ? `<span onclick="deleteSegment(${s.id}, event)" style="cursor:pointer;color:#ef4444;">✕</span>` : ''}
            </div>`).join('');
    }

    async function load() {
        let url, res;
        if (activeSegmentId) {
            url = '/api/crm/segments/' + activeSegmentId + '/run?page=' + currentPage + '&per_page=25';
        } else {
            const qs = new URLSearchParams(Object.assign({ page: currentPage, per_page: 25 }, currentFilters())).toString();
            url = '/api/crm/contacts/search?' + qs;
        }
        res = await fetchJSON(url);
        if (!res.success) { render([]); return; }
        render(res.data.items);
        totalPages = res.data.total_pages || 1;
        document.getElementById('paginationInfo').textContent =
            I18N['crm.pagination.page_of'].replace('{page}', res.data.page).replace('{total}', totalPages) +
            ' · ' + res.data.total + ' ' + I18N['crm.contacts.title'];
        document.getElementById('prevPageBtn').disabled = res.data.page <= 1;
        document.getElementById('nextPageBtn').disabled = res.data.page >= totalPages;
    }

    loadSources();
    loadSegments();
    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('crm', $this->tr('crm.contacts.title'), $this->tr('crm.contacts.subtitle'), $body, $script);
        exit;
    }

    /** GET /crm/contacts/{id} - Customer 360 (بند 2) */
    public function showContactProfile(array $params = []): array
    {
        $contactId = (int) ($params['id'] ?? 0);
        $tabsHtml = $this->crmTabsHtml('contacts');
        $body = <<<HTML
        {$tabsHtml}
        <div id="c360Root">{$this->tr('common.loading')}</div>
HTML;

        // ملاحظة: يُستخدم هنا nowdoc (<<<'JS' بعلامات اقتباس) بدل heredoc
        // العادي عمدًا، لأن السكريبت مليء بقوالب JS Template Literals من
        // نوع ${...} - لو استخدمنا heredoc عادي (بدون علامات اقتباس) كان
        // PHP هيحاول يفسّر ${I18N['...']} كمتغير PHP فعلي (نفس صيغة
        // {$var['key']})، وهو خطأ فعلي كان موجود هنا قبل التصحيح. بدل
        // الاعتماد على escaping يدوي لكل $ (عرضة للنسيان)، نستخدم nowdoc +
        // placeholder واحد بس (__CONTACT_ID__) يتم استبداله بأمان بعد كده.
        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;
    const contactId = __CONTACT_ID__;

    function section(title, rows, extraHtml) {
        return `<div class="p-card" style="margin-bottom:14px;"><div class="p-card-head"><h3>${title}</h3></div><div style="padding:0 20px 16px;">${extraHtml || ''}${rows}</div></div>`;
    }

    window.loadAiSummary = async function () {
        const box = document.getElementById('aiSummaryBox');
        box.innerHTML = I18N['common.loading'];
        const res = await fetchJSON('/api/crm/contacts/' + contactId + '/ai-summary');
        if (res.success) {
            box.innerHTML = `<div style="white-space:pre-line;line-height:1.8;">${esc(res.data.summary)}</div>`;
        } else {
            box.innerHTML = `<p class="p-cell-muted">${esc(res.error || I18N['crm.ai.summary_failed'])}</p>`;
        }
    };

    window.loadLeadNba = async function (leadId, targetId) {
        const target = document.getElementById(targetId);
        target.textContent = I18N['common.loading'];
        const res = await fetchJSON('/api/crm/leads/' + leadId + '/next-best-action');
        target.innerHTML = res.success ? `<strong>${esc(res.data.action)}</strong> - ${esc(res.data.reason)}` : esc(res.error || '-');
    };

    window.scoreLead = async function (leadId, targetId) {
        const target = document.getElementById(targetId);
        target.textContent = I18N['common.loading'];
        const res = await fetchJSON('/api/crm/leads/' + leadId + '/score', { method: 'POST' });
        if (res.success) {
            const l = res.data.lead;
            target.innerHTML = `<span class="p-badge ${l.priority === 'high' ? 'green' : ''}">${esc(l.score)} - ${esc(l.priority || '-')}</span> <span class="p-cell-muted">${esc(l.score_reason || '')}</span>`;
        } else {
            target.textContent = res.error || '-';
        }
    };

    window.sendWa = async function () {
        const text = document.getElementById('waText').value.trim();
        if (!text) return;
        const result = document.getElementById('commResult');
        result.textContent = I18N['common.loading'];
        const res = await fetchJSON('/api/crm/contacts/' + contactId + '/send-whatsapp', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ text, website_id: Number(document.getElementById('crmWaWebsite').value) }),
        });
        if (res.success) {
            result.textContent = I18N['crm.comm.sent'];
            document.getElementById('waText').value = '';
            toast(I18N['crm.comm.sent'], 'success');
        } else {
            result.textContent = res.error || I18N['crm.comm.send_failed'];
            toast(res.error || I18N['crm.comm.send_failed'], 'error');
        }
    };

    window.sendEmailMsg = async function () {
        const subject = document.getElementById('emailSubject').value.trim();
        const body = document.getElementById('emailBody').value.trim();
        if (!subject || !body) { toast(I18N['crm.comm.email_required'], 'error'); return; }
        const result = document.getElementById('commResult');
        result.textContent = I18N['common.loading'];
        const res = await fetchJSON('/api/crm/contacts/' + contactId + '/send-email', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ subject, body }),
        });
        if (res.success) {
            result.textContent = I18N['crm.comm.sent'];
            document.getElementById('emailSubject').value = '';
            document.getElementById('emailBody').value = '';
            toast(I18N['crm.comm.sent'], 'success');
        } else {
            result.textContent = res.error || I18N['crm.comm.send_failed'];
            toast(res.error || I18N['crm.comm.send_failed'], 'error');
        }
    };

    window.sendSmsMsg = async function () {
        const text = document.getElementById('smsText').value.trim();
        if (!text) return;
        const result = document.getElementById('commResult');
        result.textContent = I18N['common.loading'];
        const res = await fetchJSON('/api/crm/contacts/' + contactId + '/send-sms', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ text }),
        });
        if (res.success) {
            result.textContent = I18N['crm.comm.sent'];
            document.getElementById('smsText').value = '';
            toast(I18N['crm.comm.sent'], 'success');
        } else {
            result.textContent = res.error || I18N['crm.comm.send_failed'];
            toast(res.error || I18N['crm.comm.send_failed'], 'error');
        }
    };

    async function loadCommStatus() {
        const res = await fetchJSON('/api/crm/communication/status');
        const note = document.getElementById('commStatusNote');
        if (!res.success) { note.textContent = ''; return; }
        const selector = document.getElementById('crmWaWebsite');
        selector.innerHTML = '<option value="">Select WhatsApp sender</option>' + (res.data.whatsapp_websites || []).map(w => `<option value="${Number(w.id)}">${esc(w.domain || ('Website ' + w.id))}</option>`).join('');
        if ((res.data.whatsapp_websites || []).length === 1) selector.value = res.data.whatsapp_websites[0].id;
        const parts = [];
        parts.push(res.data.whatsapp_configured ? I18N['crm.comm.whatsapp_ready'] : I18N['crm.comm.whatsapp_not_configured']);
        parts.push(res.data.email_configured ? I18N['crm.comm.email_ready'] : I18N['crm.comm.email_not_configured']);
        parts.push(res.data.sms_configured ? I18N['crm.comm.sms_ready'] : I18N['crm.comm.sms_not_configured']);
        note.textContent = parts.join(' · ');
    }

    async function load() {
        const res = await fetchJSON('/api/crm/contacts/' + contactId + '/360');
        const root = document.getElementById('c360Root');
        if (!res.success) { root.innerHTML = `<div class="p-empty">${res.error || I18N['crm.contacts.load_failed']}</div>`; return; }
        const d = res.data;
        const c = d.contact;

        let html = `<div class="p-card" style="margin-bottom:14px;padding:20px;">
            <h2 style="margin:0 0 6px;">${esc(c.name)}</h2>
            <div class="p-cell-muted">${esc(c.email || '-')} · ${esc(c.phone || '-')} · ${esc(c.country || '')}</div>
            <div style="margin-top:8px;"><span class="p-badge">${esc(c.status)}</span> <span class="p-badge">${esc(c.source || '')}</span> ${d.company ? '<span class="p-badge gold">' + esc(d.company.name) + '</span>' : ''}</div>
        </div>`;

        html += `<div class="p-card" style="margin-bottom:14px;padding:20px;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0;">${I18N['crm.ai.summary_title']}</h3>
                <button class="p-btn xs" onclick="loadAiSummary()">${I18N['crm.ai.generate']}</button>
            </div>
            <div id="aiSummaryBox" class="p-cell-muted" style="margin-top:10px;">${I18N['crm.ai.summary_hint']}</div>
        </div>`;

        html += `<div class="p-card" style="margin-bottom:14px;padding:20px;">
            <h3 style="margin:0 0 10px;">${I18N['crm.comm.title']}</h3>
            <div id="commStatusNote" class="p-cell-muted" style="margin-bottom:10px;font-size:12.5px;"></div>
            <div style="display:flex;gap:8px;margin-bottom:8px;">
                <select id="crmWaWebsite" class="form-control" aria-label="WhatsApp sender" style="max-width:220px;"><option value="">Select WhatsApp sender</option></select>
                <input type="text" id="waText" class="form-control" placeholder="${I18N['crm.comm.whatsapp_placeholder']}">
                <button class="p-btn" onclick="sendWa()">${I18N['crm.comm.send_whatsapp']}</button>
            </div>
            <div style="display:flex;gap:8px;margin-bottom:8px;">
                <input type="text" id="smsText" class="form-control" placeholder="${I18N['crm.comm.sms_placeholder']}">
                <button class="p-btn" onclick="sendSmsMsg()">${I18N['crm.comm.send_sms']}</button>
            </div>
            <div style="display:flex;gap:8px;">
                <input type="text" id="emailSubject" class="form-control" style="max-width:220px;" placeholder="${I18N['crm.comm.subject_placeholder']}">
                <input type="text" id="emailBody" class="form-control" placeholder="${I18N['crm.comm.email_placeholder']}">
                <button class="p-btn" onclick="sendEmailMsg()">${I18N['crm.comm.send_email']}</button>
            </div>
            <div id="commResult" class="p-cell-muted" style="margin-top:8px;font-size:12.5px;"></div>
        </div>`;

        html += section(I18N['crm.leads.title'], d.leads.length ? d.leads.map(l => `
            <div style="padding:8px 0;border-bottom:1px solid var(--border,#eee);">
                <div>#${l.id} - ${esc(l.status)} ${l.value ? '· ' + esc(l.value) + ' ' + esc(l.currency||'') : ''}
                    <button class="p-btn xs" onclick="scoreLead(${l.id}, 'leadScore${l.id}')">${I18N['crm.ai.score_lead']}</button>
                    <button class="p-btn xs" onclick="loadLeadNba(${l.id}, 'leadNba${l.id}')">${I18N['crm.ai.next_best_action']}</button>
                </div>
                <div id="leadScore${l.id}" class="p-cell-muted" style="font-size:12.5px;margin-top:4px;">${l.score ? (esc(l.score) + ' - ' + esc(l.priority||'-') + (l.score_reason ? ' · ' + esc(l.score_reason) : '')) : ''}</div>
                <div id="leadNba${l.id}" class="p-cell-muted" style="font-size:12.5px;"></div>
            </div>`).join('') : `<p class="p-cell-muted">${I18N['crm.leads.none_yet']}</p>`);

        html += section(I18N['crm.deals.title'], d.deals.length ? d.deals.map(x => `<div class="p-cell-muted" style="padding:6px 0;border-bottom:1px solid var(--border,#eee);">${esc(x.title)} - <span class="p-badge" style="background:${esc(x.stage_color||'#6366f1')};color:#fff;">${esc(x.stage_name)}</span> · ${esc(x.value)} ${esc(x.currency)}</div>`).join('') : `<p class="p-cell-muted">${I18N['crm.deals.empty_column']}</p>`);

        html += section(I18N['crm.tasks.title'], d.tasks.length ? d.tasks.map(x => `<div class="p-cell-muted" style="padding:6px 0;border-bottom:1px solid var(--border,#eee);">${esc(x.title)} - ${esc(x.status)} ${x.due_date ? '· ' + formatDate(x.due_date) : ''}</div>`).join('') : `<p class="p-cell-muted">${I18N['crm.tasks.none_yet']}</p>`);

        html += section(I18N['crm.appointments.title'], d.appointments.length ? d.appointments.map(x => `<div class="p-cell-muted" style="padding:6px 0;border-bottom:1px solid var(--border,#eee);">${esc(x.title)} - ${formatDate(x.starts_at)} · ${esc(x.status)}</div>`).join('') : `<p class="p-cell-muted">${I18N['crm.appointments.none_yet']}</p>`);

        html += section(I18N['crm.notes.title'], d.notes.length ? d.notes.map(x => `<div class="p-cell-muted" style="padding:6px 0;border-bottom:1px solid var(--border,#eee);">${esc(x.body)}</div>`).join('') : `<p class="p-cell-muted">${I18N['crm.notes.none_yet']}</p>`);

        html += section(I18N['crm.timeline.title'], d.timeline.length ? d.timeline.map(x => `<div class="p-cell-muted" style="padding:6px 0;border-bottom:1px solid var(--border,#eee);">${esc(x.action)} · ${formatDate(x.created_at)}</div>`).join('') : `<p class="p-cell-muted">${I18N['crm.timeline.empty']}</p>`);

        root.innerHTML = html;
        loadCommStatus();
    }
    load();
})();
JS;
        $script = str_replace('__CONTACT_ID__', (string) $contactId, $script);

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('crm', $this->tr('crm.contacts.profile_title'), $this->tr('crm.contacts.subtitle'), $body, $script);
        exit;
    }

    /** GET /crm/companies */
    public function showCompanies(array $params = []): array
    {
        $tabsHtml = $this->crmTabsHtml('companies');
        $body = <<<HTML
        {$tabsHtml}
        <div class="p-toolbar" style="flex-wrap:wrap;gap:8px;">
            <input type="text" id="companySearch" class="form-control" style="max-width:220px;" placeholder="{$this->tr('crm.contacts.search_placeholder')}">
            <button class="p-btn xs" onclick="applyCompanyFilters()">{$this->tr('crm.filters.apply')}</button>
            <button class="p-btn xs" onclick="clearCompanyFilters()">{$this->tr('crm.filters.clear')}</button>
            <span style="flex:1;"></span>
            <button class="p-btn" onclick="document.getElementById('newCompanyModal').classList.add('open')">+ {$this->tr('crm.companies.new')}</button>
        </div>
        <div class="p-card no-pad">
            <div class="p-table-scroll"><table class="p-table" id="companiesTable">
                <thead><tr><th>{$this->tr('crm.companies.col.name')}</th><th>{$this->tr('crm.companies.col.industry')}</th><th>{$this->tr('crm.companies.col.website')}</th><th>{$this->tr('crm.companies.col.phone')}</th></tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="4">{$this->tr('common.loading')}</td></tr></tbody>
            </table></div>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;">
            <div id="companiesPaginationInfo" class="p-cell-muted" style="font-size:12.5px;"></div>
            <div>
                <button class="p-btn xs" id="companiesPrevBtn" onclick="changeCompaniesPage(-1)">‹ {$this->tr('crm.pagination.prev')}</button>
                <button class="p-btn xs" id="companiesNextBtn" onclick="changeCompaniesPage(1)">{$this->tr('crm.pagination.next')} ›</button>
            </div>
        </div>
        <div class="p-modal-overlay" id="newCompanyModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>{$this->tr('crm.companies.new')}</h3><button class="p-modal-close" onclick="document.getElementById('newCompanyModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label class="form-label">{$this->tr('crm.companies.col.name')} *</label>
                    <input type="text" id="coName" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label">{$this->tr('crm.companies.col.industry')}</label>
                    <input type="text" id="coIndustry" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label">{$this->tr('crm.companies.col.website')}</label>
                    <input type="text" id="coWebsite" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label">{$this->tr('crm.companies.col.phone')}</label>
                    <input type="text" id="coPhone" class="form-control">
                </div>
                <div class="p-modal-foot"><button class="p-btn primary" onclick="addCompany()">{$this->tr('common.add')}</button></div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    let currentPage = 1;
    let totalPages = 1;

    window.addCompany = async function () {
        const name = document.getElementById('coName').value.trim();
        if (!name) { toast(I18N['crm.companies.name_required'], 'error'); return; }
        const res = await fetchJSON('/api/crm/companies', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                name,
                industry: document.getElementById('coIndustry').value.trim(),
                website: document.getElementById('coWebsite').value.trim(),
                phone: document.getElementById('coPhone').value.trim(),
            }),
        });
        document.getElementById('newCompanyModal').classList.remove('open');
        if (res.success) { toast(I18N['common.added'], 'success'); load(); }
        else toast(res.error || I18N['crm.leads.add_failed'], 'error');
    };

    window.applyCompanyFilters = function () { currentPage = 1; load(); };
    window.clearCompanyFilters = function () { document.getElementById('companySearch').value = ''; currentPage = 1; load(); };
    window.changeCompaniesPage = function (delta) {
        const next = currentPage + delta;
        if (next < 1 || next > totalPages) return;
        currentPage = next;
        load();
    };

    async function load() {
        const params = new URLSearchParams({ page: currentPage, per_page: 25 });
        const search = document.getElementById('companySearch').value.trim();
        if (search) params.set('search', search);

        const res = await fetchJSON('/api/crm/companies/search?' + params.toString());
        const tbody = document.querySelector('#companiesTable tbody');
        const list = res.success ? res.data.items : [];
        tbody.innerHTML = list.length ? list.map(c => `
            <tr><td>${esc(c.name)}</td><td>${esc(c.industry || '-')}</td><td style="direction:ltr;text-align:left;">${esc(c.website || '-')}</td><td style="direction:ltr;text-align:left;">${esc(c.phone || '-')}</td></tr>
        `).join('') : `<tr><td colspan="4" class="p-cell-muted text-center">${I18N['crm.companies.none_yet']}</td></tr>`;

        if (res.success) {
            totalPages = res.data.total_pages || 1;
            document.getElementById('companiesPaginationInfo').textContent =
                I18N['crm.pagination.page_of'].replace('{page}', res.data.page).replace('{total}', totalPages) + ' · ' + res.data.total;
            document.getElementById('companiesPrevBtn').disabled = res.data.page <= 1;
            document.getElementById('companiesNextBtn').disabled = res.data.page >= totalPages;
        }
    }
    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('crm', $this->tr('crm.companies.title'), $this->tr('crm.companies.subtitle'), $body, $script);
        exit;
    }

    /** GET /crm/tasks */
    public function showTasks(array $params = []): array
    {
        $tabsHtml = $this->crmTabsHtml('tasks');
        $body = <<<HTML
        {$tabsHtml}
        <div class="p-toolbar" style="flex-wrap:wrap;gap:8px;">
            <input type="text" id="taskSearch" class="form-control" style="max-width:200px;" placeholder="{$this->tr('crm.contacts.search_placeholder')}">
            <select id="taskFilterStatus" class="form-control" style="max-width:150px;">
                <option value="">{$this->tr('crm.filters.status_any')}</option>
                <option value="open">{$this->tr('crm.tasks.status.open')}</option>
                <option value="in_progress">{$this->tr('crm.tasks.status.in_progress')}</option>
                <option value="done">{$this->tr('crm.tasks.status.done')}</option>
                <option value="cancelled">{$this->tr('crm.tasks.status.cancelled')}</option>
            </select>
            <select id="taskFilterPriority" class="form-control" style="max-width:150px;">
                <option value="">{$this->tr('crm.filters.priority_any')}</option>
                <option value="low">{$this->tr('crm.priority.low')}</option>
                <option value="medium">{$this->tr('crm.priority.medium')}</option>
                <option value="high">{$this->tr('crm.priority.high')}</option>
            </select>
            <button class="p-btn xs" onclick="applyTaskFilters()">{$this->tr('crm.filters.apply')}</button>
            <button class="p-btn xs" onclick="clearTaskFilters()">{$this->tr('crm.filters.clear')}</button>
            <span style="flex:1;"></span>
            <a class="p-btn xs" href="/api/crm/tasks/export">{$this->tr('crm.export.button')}</a>
            <button class="p-btn" onclick="document.getElementById('newTaskModal').classList.add('open')">+ {$this->tr('crm.tasks.new')}</button>
        </div>
        <div class="p-card no-pad">
            <div class="p-table-scroll"><table class="p-table" id="tasksTable">
                <thead><tr><th>{$this->tr('crm.tasks.col.title')}</th><th>{$this->tr('crm.tasks.col.due')}</th><th>{$this->tr('crm.tasks.col.priority')}</th><th>{$this->tr('crm.tasks.col.status')}</th></tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="4">{$this->tr('common.loading')}</td></tr></tbody>
            </table></div>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;">
            <div id="tasksPaginationInfo" class="p-cell-muted" style="font-size:12.5px;"></div>
            <div>
                <button class="p-btn xs" id="tasksPrevBtn" onclick="changeTasksPage(-1)">‹ {$this->tr('crm.pagination.prev')}</button>
                <button class="p-btn xs" id="tasksNextBtn" onclick="changeTasksPage(1)">{$this->tr('crm.pagination.next')} ›</button>
            </div>
        </div>
        <div class="p-modal-overlay" id="newTaskModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>{$this->tr('crm.tasks.new')}</h3><button class="p-modal-close" onclick="document.getElementById('newTaskModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label class="form-label">{$this->tr('crm.tasks.col.title')} *</label>
                    <input type="text" id="tTitle" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label">{$this->tr('crm.tasks.col.due')}</label>
                    <input type="datetime-local" id="tDue" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label">{$this->tr('crm.tasks.col.priority')}</label>
                    <select id="tPriority" class="form-control">
                        <option value="low">{$this->tr('crm.priority.low')}</option>
                        <option value="medium" selected>{$this->tr('crm.priority.medium')}</option>
                        <option value="high">{$this->tr('crm.priority.high')}</option>
                    </select>
                </div>
                <div class="p-modal-foot"><button class="p-btn primary" onclick="addTask()">{$this->tr('common.add')}</button></div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;
    let currentPage = 1;
    let totalPages = 1;

    window.addTask = async function () {
        const title = document.getElementById('tTitle').value.trim();
        if (!title) { toast(I18N['crm.tasks.title_required'], 'error'); return; }
        const res = await fetchJSON('/api/crm/tasks', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                title,
                due_date: document.getElementById('tDue').value || null,
                priority: document.getElementById('tPriority').value,
            }),
        });
        document.getElementById('newTaskModal').classList.remove('open');
        if (res.success) { toast(I18N['common.added'], 'success'); load(); }
        else toast(res.error || I18N['crm.leads.add_failed'], 'error');
    };

    window.toggleTaskDone = async function (id, done) {
        const res = await fetchJSON('/api/crm/tasks/' + id + '/status', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ status: done ? 'done' : 'open' }),
        });
        if (res.success) load(); else toast(res.error, 'error');
    };

    window.applyTaskFilters = function () { currentPage = 1; load(); };
    window.clearTaskFilters = function () {
        document.getElementById('taskSearch').value = '';
        document.getElementById('taskFilterStatus').value = '';
        document.getElementById('taskFilterPriority').value = '';
        currentPage = 1;
        load();
    };
    window.changeTasksPage = function (delta) {
        const next = currentPage + delta;
        if (next < 1 || next > totalPages) return;
        currentPage = next;
        load();
    };

    async function load() {
        const params = new URLSearchParams({ page: currentPage, per_page: 25 });
        const search = document.getElementById('taskSearch').value.trim();
        const status = document.getElementById('taskFilterStatus').value;
        const priority = document.getElementById('taskFilterPriority').value;
        if (search) params.set('search', search);
        if (status) params.set('status', status);
        if (priority) params.set('priority', priority);

        const res = await fetchJSON('/api/crm/tasks/search?' + params.toString());
        const tbody = document.querySelector('#tasksTable tbody');
        const list = res.success ? res.data.items : [];
        tbody.innerHTML = list.length ? list.map(t => {
            const overdue = t.due_date && new Date(t.due_date) < new Date() && t.status !== 'done';
            return `<tr>
                <td><label><input type="checkbox" ${t.status === 'done' ? 'checked' : ''} onchange="toggleTaskDone(${t.id}, this.checked)"> ${esc(t.title)}</label></td>
                <td class="${overdue ? 'p-cell-danger' : 'p-cell-muted'}">${t.due_date ? formatDate(t.due_date) : '-'}</td>
                <td><span class="p-badge">${esc(t.priority)}</span></td>
                <td><span class="p-badge ${t.status === 'done' ? 'green' : ''}">${esc(t.status)}</span></td>
            </tr>`;
        }).join('') : `<tr><td colspan="4" class="p-cell-muted text-center">${I18N['crm.tasks.none_yet']}</td></tr>`;

        if (res.success) {
            totalPages = res.data.total_pages || 1;
            document.getElementById('tasksPaginationInfo').textContent =
                I18N['crm.pagination.page_of'].replace('{page}', res.data.page).replace('{total}', totalPages) + ' · ' + res.data.total;
            document.getElementById('tasksPrevBtn').disabled = res.data.page <= 1;
            document.getElementById('tasksNextBtn').disabled = res.data.page >= totalPages;
        }
    }
    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('crm', $this->tr('crm.tasks.title'), $this->tr('crm.tasks.subtitle'), $body, $script);
        exit;
    }

    /** GET /crm/appointments */
    public function showAppointments(array $params = []): array
    {
        $tabsHtml = $this->crmTabsHtml('appointments');
        $body = <<<HTML
        {$tabsHtml}
        <div class="p-toolbar" style="flex-wrap:wrap;gap:8px;">
            <input type="text" id="apptSearch" class="form-control" style="max-width:200px;" placeholder="{$this->tr('crm.contacts.search_placeholder')}">
            <select id="apptFilterStatus" class="form-control" style="max-width:150px;">
                <option value="">{$this->tr('crm.filters.status_any')}</option>
                <option value="scheduled">{$this->tr('crm.appointments.status.scheduled')}</option>
                <option value="confirmed">{$this->tr('crm.appointments.status.confirmed')}</option>
                <option value="completed">{$this->tr('crm.appointments.status.completed')}</option>
                <option value="cancelled">{$this->tr('crm.appointments.status.cancelled')}</option>
                <option value="no_show">{$this->tr('crm.appointments.status.no_show')}</option>
            </select>
            <button class="p-btn xs" onclick="applyApptFilters()">{$this->tr('crm.filters.apply')}</button>
            <button class="p-btn xs" onclick="clearApptFilters()">{$this->tr('crm.filters.clear')}</button>
            <span style="flex:1;"></span>
            <button class="p-btn" onclick="document.getElementById('newApptModal').classList.add('open')">+ {$this->tr('crm.appointments.new')}</button>
        </div>
        <div class="p-card no-pad">
            <div class="p-table-scroll"><table class="p-table" id="apptsTable">
                <thead><tr><th>{$this->tr('crm.appointments.col.title')}</th><th>{$this->tr('crm.appointments.col.when')}</th><th>{$this->tr('crm.appointments.col.status')}</th></tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="3">{$this->tr('common.loading')}</td></tr></tbody>
            </table></div>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;">
            <div id="apptsPaginationInfo" class="p-cell-muted" style="font-size:12.5px;"></div>
            <div>
                <button class="p-btn xs" id="apptsPrevBtn" onclick="changeApptsPage(-1)">‹ {$this->tr('crm.pagination.prev')}</button>
                <button class="p-btn xs" id="apptsNextBtn" onclick="changeApptsPage(1)">{$this->tr('crm.pagination.next')} ›</button>
            </div>
        </div>
        <div class="p-modal-overlay" id="newApptModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>{$this->tr('crm.appointments.new')}</h3><button class="p-modal-close" onclick="document.getElementById('newApptModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label class="form-label">{$this->tr('crm.appointments.col.title')} *</label>
                    <input type="text" id="aTitle" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label">{$this->tr('crm.appointments.col.when')} *</label>
                    <input type="datetime-local" id="aStarts" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label">{$this->tr('crm.appointments.purpose')}</label>
                    <input type="text" id="aPurpose" class="form-control">
                </div>
                <div class="p-modal-foot"><button class="p-btn primary" onclick="addAppt()">{$this->tr('common.add')}</button></div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;
    let currentPage = 1;
    let totalPages = 1;

    window.addAppt = async function () {
        const title = document.getElementById('aTitle').value.trim();
        const starts = document.getElementById('aStarts').value;
        if (!title || !starts) { toast(I18N['crm.appointments.required'], 'error'); return; }
        const res = await fetchJSON('/api/crm/appointments', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ title, starts_at: starts.replace('T', ' '), purpose: document.getElementById('aPurpose').value.trim() }),
        });
        document.getElementById('newApptModal').classList.remove('open');
        if (res.success) { toast(I18N['common.added'], 'success'); load(); }
        else toast(res.error || I18N['crm.leads.add_failed'], 'error');
    };

    window.applyApptFilters = function () { currentPage = 1; load(); };
    window.clearApptFilters = function () {
        document.getElementById('apptSearch').value = '';
        document.getElementById('apptFilterStatus').value = '';
        currentPage = 1;
        load();
    };
    window.changeApptsPage = function (delta) {
        const next = currentPage + delta;
        if (next < 1 || next > totalPages) return;
        currentPage = next;
        load();
    };

    async function load() {
        const params = new URLSearchParams({ page: currentPage, per_page: 25 });
        const search = document.getElementById('apptSearch').value.trim();
        const status = document.getElementById('apptFilterStatus').value;
        if (search) params.set('search', search);
        if (status) params.set('status', status);

        const res = await fetchJSON('/api/crm/appointments/search?' + params.toString());
        const tbody = document.querySelector('#apptsTable tbody');
        const list = res.success ? res.data.items : [];
        tbody.innerHTML = list.length ? list.map(a => `
            <tr><td>${esc(a.title)}</td><td class="p-cell-muted">${formatDate(a.starts_at)}</td><td><span class="p-badge">${esc(a.status)}</span></td></tr>
        `).join('') : `<tr><td colspan="3" class="p-cell-muted text-center">${I18N['crm.appointments.none_yet']}</td></tr>`;

        if (res.success) {
            totalPages = res.data.total_pages || 1;
            document.getElementById('apptsPaginationInfo').textContent =
                I18N['crm.pagination.page_of'].replace('{page}', res.data.page).replace('{total}', totalPages) + ' · ' + res.data.total;
            document.getElementById('apptsPrevBtn').disabled = res.data.page <= 1;
            document.getElementById('apptsNextBtn').disabled = res.data.page >= totalPages;
        }
    }
    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('crm', $this->tr('crm.appointments.title'), $this->tr('crm.appointments.subtitle'), $body, $script);
        exit;
    }

    /** GET /crm/reports (بند 23، 24) */
    public function showReports(array $params = []): array
    {
        $tabsHtml = $this->crmTabsHtml('reports');
        $body = <<<HTML
        {$tabsHtml}
        <div class="p-grid cols-4" id="crmReportStats" style="margin-bottom:18px;"><div class="p-empty">{$this->tr('common.loading')}</div></div>
        <div class="p-grid cols-2">
            <div class="p-card" style="padding:18px;"><h3>{$this->tr('crm.reports.by_source')}</h3><div id="reportBySource" class="p-cell-muted">{$this->tr('common.loading')}</div></div>
            <div class="p-card" style="padding:18px;"><h3>{$this->tr('crm.reports.rep_performance')}</h3><div id="reportByRep" class="p-cell-muted">{$this->tr('common.loading')}</div></div>
        </div>

        <div class="p-card" style="padding:18px;margin-top:18px;">
            <h3 style="margin-top:0;">{$this->tr('crm.forecast.title')}</h3>
            <div id="forecastBox" class="p-cell-muted">{$this->tr('common.loading')}</div>
        </div>

        <div class="p-card" style="padding:18px;margin-top:18px;">
            <h3 style="margin-top:0;">{$this->tr('crm.ai.assistant_title')}</h3>
            <p class="p-cell-muted" style="margin-top:-6px;">{$this->tr('crm.ai.assistant_hint')}</p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
                <button class="p-btn xs" onclick="askAssistant('{$this->tr('crm.ai.q1')}')">{$this->tr('crm.ai.q1')}</button>
                <button class="p-btn xs" onclick="askAssistant('{$this->tr('crm.ai.q2')}')">{$this->tr('crm.ai.q2')}</button>
                <button class="p-btn xs" onclick="askAssistant('{$this->tr('crm.ai.q3')}')">{$this->tr('crm.ai.q3')}</button>
                <button class="p-btn xs" onclick="askAssistant('{$this->tr('crm.ai.q4')}')">{$this->tr('crm.ai.q4')}</button>
            </div>
            <div style="display:flex;gap:8px;">
                <input type="text" id="assistantInput" class="form-control" placeholder="{$this->tr('crm.ai.ask_placeholder')}">
                <button class="p-btn primary" onclick="askAssistant()">{$this->tr('crm.ai.ask_btn')}</button>
            </div>
            <div id="assistantAnswer" style="margin-top:14px;white-space:pre-line;line-height:1.8;"></div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    const dash = (val, suffix) => (val === null || val === undefined) ? I18N['crm.reports.not_enough_data'] : (esc(val) + (suffix || ''));

    window.askAssistant = async function (presetQuestion) {
        const input = document.getElementById('assistantInput');
        const question = presetQuestion || input.value.trim();
        if (!question) return;
        input.value = question;
        const box = document.getElementById('assistantAnswer');
        box.textContent = I18N['common.loading'];
        const res = await fetchJSON('/api/crm/assistant/ask', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ question }),
        });
        box.textContent = res.success ? res.data.answer : (res.error || I18N['crm.ai.assistant_failed']);
    };

    async function loadForecast() {
        const res = await fetchJSON('/api/crm/forecast');
        const box = document.getElementById('forecastBox');
        if (!res.success) { box.textContent = res.error || '-'; return; }
        const f = res.data;
        let html = `<div class="p-cell-muted" style="margin-bottom:8px;">${I18N['crm.forecast.disclaimer']}</div>`;
        html += `<div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:12px;">
            <div><strong>${esc(f.expected_pipeline.weighted_value)}</strong><div class="p-cell-muted" style="font-size:12px;">${I18N['crm.forecast.weighted_pipeline']}</div></div>
            <div><strong>${esc(f.potential_revenue.value)}</strong><div class="p-cell-muted" style="font-size:12px;">${I18N['crm.forecast.potential_revenue']}</div></div>
            <div><strong>${f.likely_wins.length}</strong><div class="p-cell-muted" style="font-size:12px;">${I18N['crm.forecast.likely_wins']}</div></div>
            <div><strong>${f.at_risk_deals.length}</strong><div class="p-cell-muted" style="font-size:12px;">${I18N['crm.forecast.at_risk']}</div></div>
        </div>`;
        html += `<div class="p-cell-muted" style="font-size:12.5px;">${esc(f.forecast_confidence_note)}</div>`;
        box.innerHTML = html;
    }

    async function load() {
        const res = await fetchJSON('/api/crm/dashboard/stats');
        if (!res.success) return;
        const s = res.data;

        document.getElementById('crmReportStats').innerHTML = `
            <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${esc(s.total_leads)}</div><div class="stat-label">${I18N['crm.reports.total_leads']}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${dash(s.conversion_rate, '%')}</div><div class="stat-label">${I18N['crm.reports.conversion_rate']}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${esc(s.pipeline_value)}</div><div class="stat-label">${I18N['crm.reports.pipeline_value']}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${esc(s.won_deals)}</div><div class="stat-label">${I18N['crm.reports.won_deals']}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${esc(s.overdue_follow_ups)}</div><div class="stat-label">${I18N['crm.reports.overdue_follow_ups']}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${esc(s.overdue_tasks)}</div><div class="stat-label">${I18N['crm.reports.overdue_tasks']}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${dash(s.average_deal_value)}</div><div class="stat-label">${I18N['crm.reports.avg_deal_value']}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${dash(s.average_sales_cycle_days, ' ' + I18N['crm.reports.days'])}</div><div class="stat-label">${I18N['crm.reports.sales_cycle']}</div></div></div>
        `;

        document.getElementById('reportBySource').innerHTML = s.lead_sources.length
            ? s.lead_sources.map(r => `<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border,#eee);"><span>${esc(r.source)}</span><strong>${esc(r.total)}</strong></div>`).join('')
            : I18N['crm.reports.not_enough_data'];

        document.getElementById('reportByRep').innerHTML = s.sales_rep_performance.length
            ? s.sales_rep_performance.map(r => `<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border,#eee);"><span>${esc((r.first_name||'') + ' ' + (r.last_name||''))}</span><strong>${esc(r.deals_count)} · ${esc(r.won_value)}</strong></div>`).join('')
            : I18N['crm.reports.not_enough_data'];
    }
    load();
    loadForecast();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('crm', $this->tr('crm.reports.title'), $this->tr('crm.reports.subtitle'), $body, $script);
        exit;
    }

    /** GET /crm/automation (بند 12، 36) */
    /** GET /crm/automation (بند 12، 36) - Visual Builder حقيقي بدل القوالب الجاهزة فقط */
    public function showAutomation(array $params = []): array
    {
        $tabsHtml = $this->crmTabsHtml('automation');
        $body = <<<HTML
        {$tabsHtml}
        <div class="p-toolbar">
            <div id="templateButtons" class="p-cell-muted">{$this->tr('common.loading')}</div>
            <button class="p-btn primary" onclick="openBuilder()">+ {$this->tr('crm.automation.new_rule')}</button>
        </div>
        <div class="p-card no-pad">
            <div class="p-table-scroll"><table class="p-table" id="rulesTable">
                <thead><tr><th>{$this->tr('crm.automation.col.name')}</th><th>{$this->tr('crm.automation.col.trigger')}</th><th>{$this->tr('crm.automation.col.actions')}</th><th>{$this->tr('crm.automation.col.status')}</th><th></th></tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="5">{$this->tr('common.loading')}</td></tr></tbody>
            </table></div>
        </div>

        <div class="p-modal-overlay" id="builderModal">
            <div class="p-modal" style="max-width:640px;">
                <div class="p-modal-head">
                    <h3 id="builderTitle">{$this->tr('crm.automation.new_rule')}</h3>
                    <button class="p-modal-close" onclick="closeBuilder()">×</button>
                </div>
                <div class="p-modal-body">
                    <label class="form-label">{$this->tr('crm.automation.rule_name')} *</label>
                    <input type="text" id="builderName" class="form-control" style="margin-bottom:12px;">

                    <label class="form-label">{$this->tr('crm.automation.when')} *</label>
                    <select id="builderTrigger" class="form-control" style="margin-bottom:16px;" onchange="onTriggerChange()"></select>

                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <label class="form-label" style="margin:0;">{$this->tr('crm.automation.conditions')}</label>
                        <button class="p-btn xs" onclick="addConditionRow()">+ {$this->tr('crm.automation.add_condition')}</button>
                    </div>
                    <p class="p-cell-muted" style="font-size:12px;margin-top:0;">{$this->tr('crm.automation.conditions_hint')}</p>
                    <div id="conditionsContainer" style="margin-bottom:16px;"></div>

                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <label class="form-label" style="margin:0;">{$this->tr('crm.automation.then')} *</label>
                        <button class="p-btn xs" onclick="addActionRow()">+ {$this->tr('crm.automation.add_action')}</button>
                    </div>
                    <div id="actionsContainer"></div>
                </div>
                <div class="p-modal-foot">
                    <button class="p-btn primary" onclick="saveRule()">{$this->tr('common.save')}</button>
                </div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;

    let schema = { triggers: {}, operators: {}, action_types: {} };
    let lastRules = [];
    let editingRuleId = null;

    // تسميات عرض بس (Frontend labels) لحقول الشروط/الإجراءات المُرجَعة من
    // /api/crm/automation/schema - القيم الفعلية والمنطق الحقيقي بالكامل من
    // CrmAutomationService::SCHEMA على السيرفر، هنا بس أسماء ودّية للعرض.
    const FIELD_LABELS = {
        contact_id: 'رقم جهة الاتصال', lead_id: 'رقم الـLead', deal_id: 'رقم الصفقة',
        stage_id: 'رقم المرحلة', status: 'الحالة الجديدة', previous_status: 'الحالة السابقة',
        title: 'العنوان', body: 'النص', due_offset_days: 'بعد كام يوم', priority: 'الأولوية', owner_user_id: 'رقم مستخدم المسؤول',
    };

    window.addRuleFromTemplate = async function (key) {
        const res = await fetchJSON('/api/crm/automation/rules/from-template', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ template_key: key }),
        });
        if (res.success) { toast(I18N['common.added'], 'success'); loadRules(); }
        else toast(res.error || I18N['crm.leads.add_failed'], 'error');
    };

    window.toggleRule = async function (id) {
        const res = await fetchJSON('/api/crm/automation/rules/' + id + '/toggle', { method: 'POST' });
        if (res.success) loadRules(); else toast(res.error, 'error');
    };

    window.deleteRule = async function (id) {
        const res = await fetchJSON('/api/crm/automation/rules/' + id, { method: 'DELETE' });
        if (res.success) { toast(I18N['common.updated'], 'success'); loadRules(); }
        else toast(res.error, 'error');
    };

    // ================= Visual Builder =================

    window.openBuilder = function (rule) {
        editingRuleId = rule ? rule.id : null;
        document.getElementById('builderTitle').textContent = rule ? I18N['crm.automation.edit_rule'] : I18N['crm.automation.new_rule'];
        document.getElementById('builderName').value = rule ? rule.name : '';
        document.getElementById('conditionsContainer').innerHTML = '';
        document.getElementById('actionsContainer').innerHTML = '';

        const triggerSelect = document.getElementById('builderTrigger');
        triggerSelect.innerHTML = Object.entries(schema.triggers).map(([key, t]) =>
            `<option value="${key}">${esc(t.label_ar)}</option>`
        ).join('');
        triggerSelect.value = rule ? rule.trigger_event : Object.keys(schema.triggers)[0];

        refreshActionTypeOptions();

        if (rule) {
            (JSON.parse(rule.conditions || '[]')).forEach(c => addConditionRow(c));
            (JSON.parse(rule.actions || '[]')).forEach(a => addActionRow(a));
        }

        document.getElementById('builderModal').classList.add('open');
    };

    window.closeBuilder = function () {
        document.getElementById('builderModal').classList.remove('open');
    };

    window.onTriggerChange = function () {
        // تحديث خيارات حقول الشروط لكل الصفوف الموجودة على حسب الحدث الجديد
        document.querySelectorAll('.condFieldSelect').forEach(sel => populateConditionFieldOptions(sel));
        refreshActionTypeOptions();
    };

    function currentTrigger() {
        return document.getElementById('builderTrigger').value;
    }

    function populateConditionFieldOptions(select) {
        const fields = (schema.triggers[currentTrigger()] || {}).context_fields || [];
        const current = select.value;
        select.innerHTML = fields.map(f => `<option value="${f}">${esc(FIELD_LABELS[f] || f)}</option>`).join('');
        if (fields.includes(current)) select.value = current;
    }

    window.addConditionRow = function (cond) {
        const row = document.createElement('div');
        row.className = 'automation-row';
        row.style.cssText = 'display:flex;gap:6px;margin-bottom:6px;align-items:center;';
        row.innerHTML = `
            <select class="form-control condFieldSelect" style="max-width:170px;"></select>
            <select class="form-control condOperatorSelect" style="max-width:110px;">
                ${Object.entries(schema.operators).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('')}
            </select>
            <input type="text" class="form-control condValueInput" placeholder="${I18N['crm.automation.value']}">
            <button class="p-btn xs" onclick="this.parentElement.remove()">✕</button>
        `;
        document.getElementById('conditionsContainer').appendChild(row);
        const fieldSelect = row.querySelector('.condFieldSelect');
        populateConditionFieldOptions(fieldSelect);
        if (cond) {
            fieldSelect.value = cond.field || '';
            row.querySelector('.condOperatorSelect').value = cond.operator || '=';
            row.querySelector('.condValueInput').value = cond.value ?? '';
        }
    };

    function actionTypesForCurrentTrigger() {
        const trig = currentTrigger();
        return Object.entries(schema.action_types).filter(([, def]) => def.applies_to === '*' || (def.applies_to || []).includes(trig));
    }

    function refreshActionTypeOptions() {
        document.querySelectorAll('.actionTypeSelect').forEach(sel => {
            const current = sel.value;
            sel.innerHTML = actionTypesForCurrentTrigger().map(([k, def]) => `<option value="${k}">${esc(def.label_ar)}</option>`).join('');
            if ([...sel.options].some(o => o.value === current)) sel.value = current;
            renderActionFields(sel.closest('.automation-action-row'));
        });
    }

    function renderActionFields(row, presetValues) {
        const type = row.querySelector('.actionTypeSelect').value;
        const def = schema.action_types[type];
        const container = row.querySelector('.actionFieldsContainer');
        if (!def) { container.innerHTML = ''; return; }
        container.innerHTML = def.fields.map(f => {
            const label = FIELD_LABELS[f] || f;
            const val = presetValues ? (presetValues[f] ?? '') : '';
            if (f === 'priority') {
                return `<select class="form-control actionFieldInput" data-field="${f}" style="max-width:130px;">
                    <option value="low" ${val === 'low' ? 'selected' : ''}>${I18N['crm.priority.low']}</option>
                    <option value="medium" ${val === 'medium' || !val ? 'selected' : ''}>${I18N['crm.priority.medium']}</option>
                    <option value="high" ${val === 'high' ? 'selected' : ''}>${I18N['crm.priority.high']}</option>
                </select>`;
            }
            const inputType = (f === 'due_offset_days' || f === 'owner_user_id') ? 'number' : 'text';
            return `<input type="${inputType}" class="form-control actionFieldInput" data-field="${f}" placeholder="${esc(label)}" style="max-width:160px;" value="${esc(val)}">`;
        }).join('');
    }

    window.addActionRow = function (action) {
        const row = document.createElement('div');
        row.className = 'automation-action-row';
        row.style.cssText = 'display:flex;gap:6px;margin-bottom:8px;align-items:flex-start;flex-wrap:wrap;';
        row.innerHTML = `
            <select class="form-control actionTypeSelect" style="max-width:200px;" onchange="renderActionFieldsPublic(this)"></select>
            <span class="actionFieldsContainer" style="display:flex;gap:6px;flex-wrap:wrap;"></span>
            <button class="p-btn xs" onclick="this.parentElement.remove()">✕</button>
        `;
        document.getElementById('actionsContainer').appendChild(row);
        const typeSelect = row.querySelector('.actionTypeSelect');
        typeSelect.innerHTML = actionTypesForCurrentTrigger().map(([k, def]) => `<option value="${k}">${esc(def.label_ar)}</option>`).join('');
        if (action) typeSelect.value = action.type;
        renderActionFields(row, action);
    };

    window.renderActionFieldsPublic = function (select) {
        renderActionFields(select.closest('.automation-action-row'));
    };

    window.saveRule = async function () {
        const name = document.getElementById('builderName').value.trim();
        if (!name) { toast(I18N['crm.automation.name_required'], 'error'); return; }

        const conditions = [...document.querySelectorAll('#conditionsContainer .automation-row')].map(row => ({
            field: row.querySelector('.condFieldSelect').value,
            operator: row.querySelector('.condOperatorSelect').value,
            value: row.querySelector('.condValueInput').value,
        }));

        const actions = [...document.querySelectorAll('#actionsContainer .automation-action-row')].map(row => {
            const type = row.querySelector('.actionTypeSelect').value;
            const action = { type };
            row.querySelectorAll('.actionFieldInput').forEach(input => {
                action[input.dataset.field] = input.value;
            });
            return action;
        });

        if (!actions.length) { toast(I18N['crm.automation.action_required'], 'error'); return; }

        const payload = { name, trigger_event: currentTrigger(), conditions, actions };
        const url = editingRuleId ? '/api/crm/automation/rules/' + editingRuleId : '/api/crm/automation/rules';
        const res = await fetchJSON(url, {
            method: editingRuleId ? 'PUT' : 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
        });
        if (res.success) {
            toast(I18N['common.updated'], 'success');
            closeBuilder();
            loadRules();
        } else {
            toast(res.error || I18N['crm.leads.add_failed'], 'error');
        }
    };

    window.editRule = function (id) {
        const rule = lastRules.find(r => r.id == id);
        if (rule) openBuilder(rule);
    };

    // ================= Loading =================

    async function loadTemplates() {
        const res = await fetchJSON('/api/crm/automation/templates');
        const box = document.getElementById('templateButtons');
        if (!res.success) { box.textContent = ''; return; }
        const entries = Object.entries(res.data.templates);
        box.innerHTML = entries.map(([key, tpl]) =>
            `<button class="p-btn xs" onclick="addRuleFromTemplate('${key}')">+ ${esc(tpl.name_ar)}</button>`
        ).join(' ');
    }

    async function loadRules() {
        const res = await fetchJSON('/api/crm/automation/rules');
        lastRules = res.success ? res.data.rules : [];
        const tbody = document.querySelector('#rulesTable tbody');
        tbody.innerHTML = lastRules.length ? lastRules.map(r => `
            <tr>
                <td>${esc(r.name)}</td>
                <td><span class="p-badge">${esc((schema.triggers[r.trigger_event] || {}).label_ar || r.trigger_event)}</span></td>
                <td class="p-cell-muted" style="font-size:12px;">${(JSON.parse(r.actions || '[]')).map(a => esc((schema.action_types[a.type] || {}).label_ar || a.type)).join('، ')}</td>
                <td><span class="p-badge ${r.is_active == 1 ? 'green' : ''}">${r.is_active == 1 ? I18N['crm.automation.active'] : I18N['crm.automation.inactive']}</span></td>
                <td>
                    <button class="p-btn xs" onclick="editRule(${r.id})">${I18N['common.edit']}</button>
                    <button class="p-btn xs" onclick="toggleRule(${r.id})">${I18N['crm.automation.toggle']}</button>
                    <button class="p-btn xs" onclick="deleteRule(${r.id})">${I18N['common.delete']}</button>
                </td>
            </tr>`).join('') : `<tr><td colspan="5" class="p-cell-muted text-center">${I18N['crm.automation.none_yet']}</td></tr>`;
    }

    async function init() {
        const res = await fetchJSON('/api/crm/automation/schema');
        if (res.success) schema = res.data;
        loadTemplates();
        loadRules();
    }
    init();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('crm', $this->tr('crm.automation.title'), $this->tr('crm.automation.subtitle'), $body, $script);
        exit;
    }

    /** GET /crm/team (بند 30) */
    public function showTeam(array $params = []): array
    {
        $tabsHtml = $this->crmTabsHtml('team');
        $body = <<<HTML
        {$tabsHtml}
        <div id="teamRoot">{$this->tr('common.loading')}</div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;

    const ROLE_LABELS = {
        admin: I18N['crm.team.role.admin'], manager: I18N['crm.team.role.manager'],
        sales: I18N['crm.team.role.sales'], support: I18N['crm.team.role.support'], viewer: I18N['crm.team.role.viewer'],
    };

    window.addMember = async function () {
        const email = document.getElementById('memberEmail').value.trim();
        const role = document.getElementById('memberRole').value;
        if (!email) { toast(I18N['crm.team.email_required'], 'error'); return; }
        const res = await fetchJSON('/api/crm/team', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email, role }),
        });
        if (res.success) {
            toast(I18N['common.added'], 'success');
            document.getElementById('memberEmail').value = '';
            load();
        } else {
            toast(res.error || I18N['crm.leads.add_failed'], 'error');
        }
    };

    window.updateRole = async function (id, role) {
        const res = await fetchJSON('/api/crm/team/' + id, {
            method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ role }),
        });
        if (res.success) toast(I18N['common.updated'], 'success'); else toast(res.error, 'error');
    };

    window.removeMember = async function (id) {
        const res = await fetchJSON('/api/crm/team/' + id, { method: 'DELETE' });
        if (res.success) { toast(I18N['common.updated'], 'success'); load(); }
        else toast(res.error, 'error');
    };

    async function load() {
        const res = await fetchJSON('/api/crm/team');
        const root = document.getElementById('teamRoot');
        if (!res.success) { root.innerHTML = `<div class="p-empty">${esc(res.error || '-')}</div>`; return; }
        const d = res.data;

        let html = `<div class="p-card" style="padding:18px;margin-bottom:16px;">
            <div class="p-cell-muted">${I18N['crm.team.my_role']}: <strong>${esc(ROLE_LABELS[d.my_role] || d.my_role)}</strong>
            ${d.is_tenant_owner ? ' · ' + I18N['crm.team.owner_note'] : ''}</div>
            <div class="p-cell-muted" style="font-size:12.5px;margin-top:4px;">${I18N['crm.team.my_permissions']}: ${d.my_permissions.map(p => esc(p)).join('، ')}</div>
        </div>`;

        if (d.is_tenant_owner) {
            html += `<div class="p-card" style="padding:18px;margin-bottom:16px;">
                <h3 style="margin-top:0;">${I18N['crm.team.add_member']}</h3>
                <p class="p-cell-muted" style="font-size:12.5px;">${I18N['crm.team.add_hint']}</p>
                <div style="display:flex;gap:8px;">
                    <input type="email" id="memberEmail" class="form-control" placeholder="${I18N['crm.team.email_placeholder']}">
                    <select id="memberRole" class="form-control" style="max-width:160px;">
                        <option value="admin">${I18N['crm.team.role.admin']}</option>
                        <option value="manager">${I18N['crm.team.role.manager']}</option>
                        <option value="sales" selected>${I18N['crm.team.role.sales']}</option>
                        <option value="support">${I18N['crm.team.role.support']}</option>
                        <option value="viewer">${I18N['crm.team.role.viewer']}</option>
                    </select>
                    <button class="p-btn primary" onclick="addMember()">${I18N['common.add']}</button>
                </div>
            </div>`;
        }

        html += `<div class="p-card no-pad"><div class="p-table-scroll"><table class="p-table">
            <thead><tr><th>${I18N['crm.team.col.name']}</th><th>${I18N['crm.team.col.email']}</th><th>${I18N['crm.team.col.role']}</th><th></th></tr></thead>
            <tbody>`;
        html += d.members.length ? d.members.map(m => `
            <tr>
                <td>${esc((m.first_name || '') + ' ' + (m.last_name || ''))}</td>
                <td style="direction:ltr;text-align:left;">${esc(m.email)}</td>
                <td>
                    ${d.is_tenant_owner
                        ? `<select class="form-control" style="max-width:150px;" onchange="updateRole(${m.id}, this.value)">
                            ${Object.entries(ROLE_LABELS).map(([k, l]) => `<option value="${k}" ${m.role === k ? 'selected' : ''}>${esc(l)}</option>`).join('')}
                        </select>`
                        : `<span class="p-badge">${esc(ROLE_LABELS[m.role] || m.role)}</span>`}
                </td>
                <td>${d.is_tenant_owner ? `<button class="p-btn xs" onclick="removeMember(${m.id})">${I18N['common.delete']}</button>` : ''}</td>
            </tr>`).join('') : `<tr><td colspan="4" class="p-cell-muted text-center">${I18N['crm.team.none_yet']}</td></tr>`;
        html += `</tbody></table></div></div>`;

        root.innerHTML = html;
    }
    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('crm', $this->tr('crm.team.title'), $this->tr('crm.team.subtitle'), $body, $script);
        exit;
    }

    public function sequences(array $params=[]): array{
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        $body=$this->crmTabsHtml('sequences').'<section class="p-card"><h2>تسلسلات المتابعة / Follow-up sequences</h2><p>اختار متابعة يدوية لإنشاء مهمة، أو إرسال تلقائي باستخدام SMTP أو واتساب الخاص بالحساب. التأخير هو مدة الانتظار بعد الخطوة.</p><form id="crmSequenceForm"><label>الاسم / Name <input name="name" required class="p-input" maxlength="190"></label><div id="crmSequenceSteps"></div><button type="button" class="p-btn outline" id="crmAddStep">إضافة خطوة / Add step</button> <button class="p-btn primary">حفظ التسلسل / Save</button></form><p id="crmSequenceNotice" role="status"></p></section><section class="p-card"><h3>التسلسلات / Sequences</h3><div id="crmSequenceList"></div></section><section class="p-card"><h3>التسجيلات / Enrollments</h3><div id="crmSequenceEnrollments"></div></section>';
        // الملف بيستخدم window.Panel، فلازم يتحمّل بعد panel.js (كان جوه الـ body
        // فبيشتغل قبله والصفحة تقف: Cannot read properties of undefined).
        $script='var s=document.createElement("script");s.src='.json_encode(asset_v('/assets/js/crm-sequences.js'),JSON_UNESCAPED_SLASHES).';document.body.appendChild(s);';
        echo $this->renderPanelPage('crm','تسلسلات المتابعة','Follow-up sequences',$body,$script);exit;
    }

}
