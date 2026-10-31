<?php

/**
 * Tourfecto - Review Request Controller
 * طلب مراجعات تلقائي بعد انتهاء الخدمة عن طريق واتساب
 * @version 1.0.0
 */
class ReviewRequestController extends Controller
{
    /** @var ReviewRequestService */
    private $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new ReviewRequestService();
    }

    /** GET /rr/{token} - رابط التقييم المتتبَّع (عام، من غير تسجيل دخول) */
    public function trackClick(array $params = []): array
    {
        $url = null;
        try {
            $url = $this->service->registerClick((string) ($params['token'] ?? ''));
        } catch (Throwable $e) {
            Logger::warning('review link click failed', ['error' => CustomerError::message($e->getMessage(), 500)]);
        }
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        if ($url) {
            header('Location: ' . $url, true, 302);
            exit;
        }
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><p style="font-family:sans-serif;text-align:center;margin-top:20vh">الرابط ده مش شغّال - كلّم الشركة اللي بعتتهولك.</p>';
        exit;
    }

    /** GET /review-us/{code} - public landing page reached from the QR / printed link */
    public function reviewUs(array $params = []): array
    {
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('Content-Type: text/html; charset=utf-8');
        try {
            $websiteId = ReviewLanding::verify((string) ($params['code'] ?? ''));
        } catch (Throwable $e) {
            $websiteId = 0;
        }
        $site = $websiteId ? ((new Website())->find($websiteId)) : null;
        if (!$site) {
            http_response_code(404);
            echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><p style="font-family:sans-serif;text-align:center;margin-top:20vh">This link is not valid.</p>';
            exit;
        }
        $company = trim((string) ($site->getAttribute('company_name') ?: $site->getAttribute('main_url')));
        $lang = (string) $this->get('lang', '');
        $ar = $lang === 'ar' || ($lang !== 'en' && function_exists('current_lang') && current_lang() === 'ar');
        $dest = ReviewLanding::destinations($this->service->getSettings($websiteId));
        if ($ar) {
            foreach ($dest as &$d) {
                $d['label'] = $d['key'] === 'other' ? 'قيّمنا' : $d['label'];
            }
            unset($d);
        }
        echo ReviewLanding::page($company, $dest, '', GuestSurveys::ready() && GuestSurveyController::surveysAllowed((int) $site->getAttribute('user_id')) ? '/survey/' . GuestSurveys::code($websiteId) : '', $ar);
        exit;
    }

    /** GET /api/review-requests/usage - this month's use against the plan limits (only meaningful when a limit is set) */
    public function getUsage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success(['usage' => ReputationLimits::summary((int) $this->user['id'])]);
        } catch (Throwable $e) {
            Logger::warning('Reputation usage unavailable', ['message' => $e->getMessage()]);
            return $this->success(['usage' => null]);
        }
    }

    /** GET /api/review-requests/qr?website_id= - link + QR (SVG) for the owner */
    public function getQr(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id', 0);
        if (!$this->ownsWebsite($websiteId)) {
            return $this->error('غير مصرح', 403);
        }
        try {
            $base = rtrim(defined('APP_URL') ? (string) APP_URL : '', '/');
            $url = ReviewLanding::url($websiteId, $base);
            $dest = ReviewLanding::destinations($this->service->getSettings($websiteId));
            return $this->success([
                'url' => $url,
                'svg' => ReviewLanding::qrSvg($url),
                'destinations' => $dest,
                'ready' => !empty($dest),
            ]);
        } catch (Throwable $e) {
            Logger::error('Review QR error', ['message' => $e->getMessage()]);
            return $this->error('Could not create the QR code', 500);
        }
    }

    /** GET /review-requests */
    public function index(array $params = []): array
    {
        $tAddGuest = $this->tr('rr.add_guest');
        $tSettings = $this->tr('rr.settings');
        $tScheduled = $this->tr('rr.stat.scheduled');
        $tSent = $this->tr('rr.stat.sent');
        $tReminded = $this->tr('rr.stat.reminded');
        $tFailed = $this->tr('rr.stat.failed');
        $tGuest = $this->tr('rr.col.guest');
        $tServiceEnd = $this->tr('rr.col.service_end');
        $tSendDate = $this->tr('rr.col.send_date');
        $tStatus = $this->tr('chat.col.status');
        $tLoading = $this->tr('common.loading');

        $body = <<<HTML
        <div class="p-toolbar">
            <select id="rrWebsiteId" class="p-select"></select>
            <button class="p-btn" onclick="resetAddGuestModal();P.openModal('addGuestModal')">+ {$tAddGuest}</button>
            <button class="p-btn outline" onclick="openDetailsFromAnalytics()">📊 {$this->tr('rr.analytics')}</button>
            <button class="p-btn outline" onclick="toggleDestinations()">🔗 {$this->tr('rr.destinations')}</button>
            <button class="p-btn outline" onclick="toggleQr()">📱 QR & link</button>
            <button class="p-btn outline" onclick="exportRR()">⬇ {$this->tr('rr.export')}</button>
            <button class="p-btn outline" onclick="P.openModal('rrSettingsModal')">⚙️ {$tSettings}</button>
        </div>

        <div class="p-card" id="rrDestinationsCard" style="margin-top:10px;display:none;">
            <h4 style="margin:0 0 10px;">🔗 {$this->tr('rr.destinations')}</h4>
            <div id="rrDestinationsBody" class="p-cell-muted">{$tLoading}</div>
        </div>

        <div class="p-grid cols-4" id="rrStatsGrid" style="margin-top:16px;">
            <div class="p-card stat-tile"><div class="stat-icon blue">⏳</div><div class="stat-info"><div class="stat-value" id="rrStatScheduled">0</div><div class="stat-label">{$tScheduled}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon green">📤</div><div class="stat-info"><div class="stat-value" id="rrStatSent">0</div><div class="stat-label">{$tSent}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon orange">🔔</div><div class="stat-info"><div class="stat-value" id="rrStatReminded">0</div><div class="stat-label">{$tReminded}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon green">👆</div><div class="stat-info"><div class="stat-value" id="rrStatClicked">0</div><div class="stat-label">فتحوا رابط التقييم</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon purple">✖</div><div class="stat-info"><div class="stat-value" id="rrStatFailed">0</div><div class="stat-label">{$tFailed}</div></div></div>
        </div>

        <div class="p-card" id="rrAnalyticsCard" style="margin-top:16px;display:none;">
            <h4 style="margin:0 0 10px;">📊 {$this->tr('rr.analytics')}</h4>
            <div id="rrAnalyticsBody" class="p-cell-muted">{$tLoading}</div>
        </div>

        <div class="p-toolbar" style="margin-top:16px;">
            <select id="rrFilterStatus" class="p-select">
                <option value="">{$this->tr('rr.filter.all_statuses')}</option>
                <option value="scheduled">{$this->tr('rr.status.scheduled')}</option>
                <option value="sent">{$this->tr('rr.status.sent')}</option>
                <option value="reminded">{$this->tr('rr.status.reminded')}</option>
                <option value="reviewed">{$this->tr('rr.status.reviewed')}</option>
                <option value="opted_out">{$this->tr('rr.status.opted_out')}</option>
                <option value="failed">{$this->tr('rr.status.failed')}</option>
            </select>
            <select id="rrFilterChannel" class="p-select">
                <option value="">{$this->tr('rr.filter.all_channels')}</option>
                <option value="whatsapp">{$this->tr('rr.channel.whatsapp')}</option>
                <option value="email">{$this->tr('rr.channel.email')}</option>
                <option value="sms">{$this->tr('rr.channel.sms')}</option>
            </select>
            <input type="text" id="rrFilterSearch" class="form-control" style="max-width:220px;" placeholder="{$this->tr('rr.filter.search_placeholder')}">
            <button class="p-btn outline xs" onclick="P.reqPage=1;loadRequests();">{$this->tr('common.search')}</button>
        </div>

        <div class="p-card no-pad" style="margin-top:10px;">
            <div class="p-table-scroll">
                <table class="p-table" id="rrTable">
                    <thead><tr><th>{$tGuest}</th><th>{$this->tr('rr.col.channel')}</th><th>{$this->tr('rr.details.recipient')}</th><th>{$tServiceEnd}</th><th>{$tSendDate}</th><th>{$tStatus}</th><th></th></tr></thead>
                    <tbody><tr class="p-loading-row"><td colspan="7">{$tLoading}</td></tr></tbody>
                </table>
            </div>
            <div class="p-toolbar" id="rrPagination" style="padding:10px;"></div>
        </div>

        <div class="p-modal-overlay" id="addGuestModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3 id="addGuestModalTitle">+ {$this->tr('rr.add_guest_title')}</h3><button class="p-modal-close" onclick="P.closeModal('addGuestModal')">×</button></div>
                <div class="p-modal-body">
                    <input type="hidden" id="guestEditId" value="">
                    <div id="crmContactPickerWrap">
                        <label class="form-label">{$this->tr('rr.crm.pick_contact')}</label>
                        <input type="text" id="crmContactSearch" class="form-control" style="margin-bottom:4px;" placeholder="{$this->tr('rr.crm.search_placeholder')}" oninput="searchCrmContactsDebounced()">
                        <div id="crmContactResults" class="p-cell-muted" style="font-size:13px;margin-bottom:10px;"></div>
                    </div>
                    <hr style="border-color:var(--panel-border);margin:10px 0;">
                    <label class="form-label">{$this->tr('rr.guest_name')}</label>
                    <input type="text" id="guestName" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label">{$this->tr('rr.channel')}</label>
                    <select id="guestChannel" class="form-control" style="margin-bottom:10px;" onchange="toggleGuestChannelFields()">
                        <option value="whatsapp">{$this->tr('rr.channel.whatsapp')}</option>
                        <option value="email">{$this->tr('rr.channel.email')}</option>
                        <option value="sms">{$this->tr('rr.channel.sms')}</option>
                    </select>
                    <div id="guestChannelStatusHint" class="p-cell-muted" style="font-size:12px;margin-bottom:10px;"></div>
                    <div id="guestPhoneWrap">
                        <label class="form-label">{$this->tr('rr.guest_phone')}</label>
                        <input type="text" id="guestPhone" class="form-control" style="margin-bottom:10px;" dir="ltr" placeholder="201xxxxxxxxx">
                    </div>
                    <div id="guestEmailWrap" style="display:none;">
                        <label class="form-label">{$this->tr('rr.guest_email')}</label>
                        <input type="email" id="guestEmail" class="form-control" style="margin-bottom:10px;" dir="ltr" placeholder="guest@example.com">
                    </div>
                    <label class="form-label">{$this->tr('rr.destination')}</label>
                    <select id="guestDestination" class="form-control" style="margin-bottom:6px;" onchange="updateDestinationHint()">
                        <option value="other">{$this->tr('rr.destination.other')}</option>
                        <option value="google_business">{$this->tr('rr.destination.google')}</option>
                        <option value="tripadvisor">{$this->tr('rr.destination.tripadvisor')}</option>
                    </select>
                    <div id="guestDestinationHint" class="p-cell-muted" style="font-size:12px;margin-bottom:10px;"></div>
                    <label class="form-label">{$this->tr('rr.service_end_datetime')}</label>
                    <input type="datetime-local" id="serviceEndDate" class="form-control">
                    <div id="smartTimingHint" class="p-cell-muted" style="font-size:12px;margin-top:6px;"></div>
                    <div id="addGuestAlert" class="alert alert-danger" style="display:none;margin-top:10px;"></div>
                </div>
                <div class="p-modal-foot"><button class="p-btn primary" id="addGuestSubmitBtn" onclick="addGuest()">{$this->tr('rr.add_and_schedule')}</button></div>
            </div>
        </div>

        <div class="p-modal-overlay" id="rrDetailsModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>{$this->tr('rr.details_title')}</h3><button class="p-modal-close" onclick="P.closeModal('rrDetailsModal')">×</button></div>
                <div class="p-modal-body" id="rrDetailsBody">{$tLoading}</div>
            </div>
        </div>

        <div class="p-modal-overlay" id="rrSettingsModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>⚙️ {$this->tr('rr.settings_title')}</h3><button class="p-modal-close" onclick="P.closeModal('rrSettingsModal')">×</button></div>
                <div class="p-modal-body">
                    <label style="display:flex;align-items:center;gap:8px;margin-bottom:14px;"><input type="checkbox" id="rrEnabled"> {$this->tr('rr.campaign.enabled')}</label>
                    <label class="form-label">{$this->tr('rr.review_link')} ({$this->tr('rr.destination.other')})</label>
                    <input type="url" id="rrReviewLink" class="form-control" style="margin-bottom:10px;" dir="ltr" placeholder="https://...">
                    <label class="form-label">{$this->tr('rr.review_link.google')}</label>
                    <input type="url" id="rrGoogleReviewLink" class="form-control" style="margin-bottom:10px;" dir="ltr" placeholder="https://g.page/r/...">
                    <label class="form-label">{$this->tr('rr.review_link.tripadvisor')}</label>
                    <input type="url" id="rrTripadvisorReviewLink" class="form-control" style="margin-bottom:10px;" dir="ltr" placeholder="https://www.tripadvisor.com/...">
                    <label class="form-label">{$this->tr('rr.delay_hours')}</label>
                    <input type="number" id="rrDelayHours" class="form-control" style="margin-bottom:6px;">
                    <div id="smartTimingSettingsHint" class="p-cell-muted" style="font-size:12px;margin-bottom:10px;"></div>
                    <label class="form-label">{$this->tr('rr.ai.target_language')}</label>
                    <input type="text" id="rrAiTargetLang" class="form-control" style="margin-bottom:10px;max-width:160px;" value="ar" placeholder="ar / en / fr...">
                    <label class="form-label">{$this->tr('rr.templates.title')}</label>
                    <div class="p-toolbar" style="margin-bottom:10px;">
                        <select id="rrTemplatePicker" class="p-select"></select>
                        <button type="button" class="p-btn outline xs" onclick="applyTemplate()">{$this->tr('rr.templates.apply')}</button>
                        <button type="button" class="p-btn outline xs" onclick="P.openModal('rrNewTemplateModal')">+ {$this->tr('rr.templates.new')}</button>
                        <button type="button" class="p-btn outline xs" onclick="deleteCurrentTemplate()">🗑 {$this->tr('rr.templates.delete')}</button>
                    </div>
                    <label class="form-label" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px;">
                        <span>{$this->tr('rr.message_template')}</span>
                        <span>
                            <button type="button" class="p-btn outline xs" onclick="aiAssist('message', 'generate')">🪄 {$this->tr('rr.ai.generate')}</button>
                            <button type="button" class="p-btn outline xs" onclick="aiAssist('message', 'rewrite')">✨ {$this->tr('rr.ai.rewrite')}</button>
                            <button type="button" class="p-btn outline xs" onclick="aiAssist('message', 'shorten')">✂️ {$this->tr('rr.ai.shorten')}</button>
                            <button type="button" class="p-btn outline xs" onclick="aiAssist('message', 'professional')">🎩 {$this->tr('rr.ai.professional')}</button>
                            <button type="button" class="p-btn outline xs" onclick="aiAssist('message', 'translate')">🌐 {$this->tr('rr.ai.translate')}</button>
                        </span>
                    </label>
                    <textarea id="rrMessageTemplate" class="form-control" rows="3" style="margin-bottom:10px;"></textarea>
                    <label class="form-label">{$this->tr('rr.email_subject')}</label>
                    <input type="text" id="rrEmailSubject" class="form-control" style="margin-bottom:10px;">
                    <hr style="border-color:var(--panel-border);margin:14px 0;">
                    <label style="display:flex;align-items:center;gap:8px;margin-bottom:14px;"><input type="checkbox" id="rrReminderEnabled"> {$this->tr('rr.enable_reminder')}</label>
                    <label class="form-label">{$this->tr('rr.reminder_after')}</label>
                    <input type="number" id="rrReminderHours" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px;">
                        <span>{$this->tr('rr.reminder_template')}</span>
                        <span>
                            <button type="button" class="p-btn outline xs" onclick="aiAssist('reminder', 'generate')">🪄 {$this->tr('rr.ai.generate')}</button>
                            <button type="button" class="p-btn outline xs" onclick="aiAssist('reminder', 'rewrite')">✨ {$this->tr('rr.ai.rewrite')}</button>
                            <button type="button" class="p-btn outline xs" onclick="aiAssist('reminder', 'shorten')">✂️ {$this->tr('rr.ai.shorten')}</button>
                            <button type="button" class="p-btn outline xs" onclick="aiAssist('reminder', 'professional')">🎩 {$this->tr('rr.ai.professional')}</button>
                            <button type="button" class="p-btn outline xs" onclick="aiAssist('reminder', 'translate')">🌐 {$this->tr('rr.ai.translate')}</button>
                        </span>
                    </label>
                    <textarea id="rrReminderTemplate" class="form-control" rows="3" style="margin-bottom:14px;"></textarea>
                    <hr style="border-color:var(--panel-border);margin:14px 0;">
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" id="rrAutoFromCrm"> 🔗 {$this->tr('rr.link_crm')}
                    </label>
                    <p class="p-cell-muted" style="font-size:12px;margin-top:6px;">{$this->tr('rr.link_crm_hint')}</p>
                    <label style="display:flex;align-items:center;gap:8px;margin-top:12px;">
                        <input type="checkbox" id="rrAutoFromBookings"> 🧳 ابعت طلب تقييم تلقائي بعد كل رحلة محجوزة
                    </label>
                    <p class="p-cell-muted" style="font-size:12px;margin-top:6px;">أي حجز مؤكد بيتبعتله طلب تقييم بعد يوم الرحلة بعدد ساعات التأخير اللي فوق (بنفس القناة المتاحة: واتساب أو إيميل). الطلب بيعدّي على مركز الأتمتة لو محتاج موافقتك.</p>
                    <div id="rrSettingsAlert" class="alert alert-danger" style="display:none;margin-top:10px;"></div>
                </div>
                <div class="p-modal-foot"><button class="p-btn primary" onclick="saveRRSettings()">{$this->tr('rr.save_settings')}</button></div>
            </div>
        </div>

        <div class="p-modal-overlay" id="rrNewTemplateModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>+ {$this->tr('rr.templates.new')}</h3><button class="p-modal-close" onclick="P.closeModal('rrNewTemplateModal')">×</button></div>
                <div class="p-modal-body">
                    <label class="form-label">{$this->tr('rr.templates.name')}</label>
                    <input type="text" id="newTemplateName" class="form-control" style="margin-bottom:10px;">
                    <label class="form-label">{$this->tr('rr.message_template')}</label>
                    <textarea id="newTemplateMessage" class="form-control" rows="3" style="margin-bottom:10px;"></textarea>
                    <label class="form-label">{$this->tr('rr.email_subject')}</label>
                    <input type="text" id="newTemplateSubject" class="form-control">
                </div>
                <div class="p-modal-foot"><button class="p-btn primary" onclick="saveNewTemplate()">{$this->tr('rr.templates.save')}</button></div>
            </div>
        </div>
HTML;


        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;
    let currentWebsiteId = null;
    P.reqPage = 1;

    async function loadWebsites() {
        const res = await fetchJSON('/api/websites');
        const sel = document.getElementById('rrWebsiteId');
        if (res.success && res.data.websites && res.data.websites.length) {
            sel.innerHTML = res.data.websites.map(w => `<option value="${w.id}">${esc(w.company_name || w.main_url)}</option>`).join('');
            P.syncWebsiteSelect('rrWebsiteId');
            currentWebsiteId = sel.value;
            sel.addEventListener('change', () => { currentWebsiteId = sel.value; P.reqPage = 1; loadAll(); toggleGuestChannelFields(); });
            loadAll();
        } else {
            sel.innerHTML = '<option value="">' + I18N['integrations.no_websites'] + '</option>';
        }
    }

    function statusPillHtml(status) {
        const map = {
            scheduled: '<span class="pill blue">⏳ ' + I18N['rr.status.scheduled'] + '</span>',
            sent: '<span class="pill green">📤 ' + I18N['rr.status.sent'] + '</span>',
            reminded: '<span class="pill orange">🔔 ' + I18N['rr.status.reminded'] + '</span>',
            reviewed: '<span class="pill green">✔ ' + I18N['rr.status.reviewed'] + '</span>',
            opted_out: '<span class="pill gray">' + I18N['rr.status.opted_out'] + '</span>',
            failed: '<span class="pill red">✖ ' + I18N['rr.status.failed'] + '</span>',
        };
        return map[status] || status;
    }

    function channelLabel(channel) {
        if (channel === 'email') return '✉️ ' + I18N['rr.channel.email'];
        if (channel === 'sms') return '📱 ' + I18N['rr.channel.sms'];
        return '💬 ' + I18N['rr.channel.whatsapp'];
    }

    async function loadAll() {
        if (!currentWebsiteId) return;
        const [statsRes] = await Promise.all([
            fetchJSON('/api/review-requests/stats?website_id=' + currentWebsiteId),
        ]);

        if (statsRes.success) {
            const s = statsRes.data.stats;
            document.getElementById('rrStatScheduled').textContent = s.scheduled;
            document.getElementById('rrStatSent').textContent = s.total_sent;
            const ck = document.getElementById('rrStatClicked'); if (ck) ck.textContent = s.clicked || 0;
            document.getElementById('rrStatReminded').textContent = s.reminded;
            document.getElementById('rrStatFailed').textContent = s.failed;
        }

        await loadRequests();
        await loadSettingsIntoForm();
        await loadSmartTiming();
    }

    async function loadSmartTiming() {
        const res = await fetchJSON('/api/review-requests/smart-timing?website_id=' + currentWebsiteId);
        const hint1 = document.getElementById('smartTimingHint');
        const hint2 = document.getElementById('smartTimingSettingsHint');
        if (!res.success || res.data.timing.not_enough_data) {
            const msg = '💡 ' + I18N['rr.smart_timing.not_enough_data'];
            if (hint1) hint1.textContent = msg;
            if (hint2) hint2.textContent = msg;
            return;
        }
        const t = res.data.timing;
        const msg = '💡 ' + I18N['rr.smart_timing.suggestion'].replace('{hours}', t.suggested_delay_hours).replace('{count}', t.sample_size);
        if (hint1) hint1.textContent = msg;
        if (hint2) hint2.textContent = msg;
    }

    window.loadRequests = async function () {
        if (!currentWebsiteId) return;
        const status = document.getElementById('rrFilterStatus').value;
        const channel = document.getElementById('rrFilterChannel').value;
        const search = document.getElementById('rrFilterSearch').value.trim();
        const qs = new URLSearchParams({ website_id: currentWebsiteId, page: P.reqPage || 1, per_page: 25 });
        if (status) qs.set('status', status);
        if (channel) qs.set('channel', channel);
        if (search) qs.set('search', search);

        const listRes = await fetchJSON('/api/review-requests?' + qs.toString());
        const tbody = document.querySelector('#rrTable tbody');

        if (listRes.success && listRes.data.requests && listRes.data.requests.length) {
            window.__rrRows = {};
            tbody.innerHTML = listRes.data.requests.map(r => {
                window.__rrRows[r.id] = r;
                return `
                <tr>
                    <td>${esc(r.guest_name)}</td>
                    <td class="p-cell-muted">${channelLabel(r.channel)}</td>
                    <td dir="ltr" class="p-cell-muted">${esc(r.channel === 'email' ? (r.guest_email || '') : (r.guest_phone || ''))}</td>
                    <td class="p-cell-muted">${formatDate(r.service_end_date)}</td>
                    <td class="p-cell-muted">${formatDate(r.scheduled_send_at)}</td>
                    <td>${statusPillHtml(r.status)}</td>
                    <td>
                        <button class="p-btn outline xs" onclick="showRequestDetails(${r.id})">${I18N['rr.details']}</button>
                        ${r.status === 'scheduled' ? `<button class="p-btn outline xs" onclick="editGuest(${r.id})">${I18N['rr.edit']}</button>` : ''}
                        ${r.status === 'scheduled' ? `<button class="p-btn outline xs" onclick="optOutGuest(${r.id})">${I18N['admin.cancel']}</button>` : ''}
                        ${r.status === 'failed' ? `<button class="p-btn outline xs" onclick="retryGuest(${r.id})">🔁 ${I18N['rr.retry']}</button>` : ''}
                    </td>
                </tr>`;
            }).join('');

            const total = listRes.data.total || 0;
            const perPage = listRes.data.per_page || 25;
            const page = listRes.data.page || 1;
            const totalPages = Math.max(1, Math.ceil(total / perPage));
            document.getElementById('rrPagination').innerHTML = totalPages > 1 ? `
                <button class="p-btn outline xs" ${page <= 1 ? 'disabled' : ''} onclick="P.reqPage=${page - 1};loadRequests();">‹</button>
                <span class="p-cell-muted" style="margin:0 8px;">${page} / ${totalPages}</span>
                <button class="p-btn outline xs" ${page >= totalPages ? 'disabled' : ''} onclick="P.reqPage=${page + 1};loadRequests();">›</button>
            ` : '';
        } else {
            tbody.innerHTML = '<tr><td colspan="7" class="p-empty">' + I18N['rr.no_requests'] + '</td></tr>';
            document.getElementById('rrPagination').innerHTML = '';
        }
    };

    window.showRequestDetails = async function (id) {
        P.openModal('rrDetailsModal');
        const body = document.getElementById('rrDetailsBody');
        body.innerHTML = I18N['common.loading'];

        const res = await fetchJSON('/api/review-requests/' + id + '?website_id=' + currentWebsiteId);
        if (!res.success) {
            body.innerHTML = '<p class="p-cell-muted">' + (res.error || I18N['rr.details_failed']) + '</p>';
            return;
        }

        const r = res.data.request;
        const timeline = res.data.timeline || [];
        const eventLabels = {
            created: I18N['rr.timeline.created'], scheduled: I18N['rr.timeline.scheduled'],
            sent: I18N['rr.timeline.sent'], reminded: I18N['rr.timeline.reminded'],
            reviewed: I18N['rr.timeline.reviewed'], failed: I18N['rr.timeline.failed'],
            clicked: '👆 فتح رابط التقييم',
            opted_out: I18N['rr.status.opted_out'],
        };

        const destLabels = { google_business: I18N['rr.destination.google'], tripadvisor: I18N['rr.destination.tripadvisor'], other: I18N['rr.destination.other'] };

        body.innerHTML = `
            <div class="p-grid cols-2" style="margin-bottom:14px;">
                <div><strong>${I18N['rr.guest_name']}:</strong> ${esc(r.guest_name)}</div>
                <div><strong>${I18N['rr.col.channel']}:</strong> ${channelLabel(r.channel)}</div>
                <div><strong>${I18N['rr.details.recipient']}:</strong> ${esc(r.channel === 'email' ? (r.guest_email || '-') : (r.guest_phone || '-'))}</div>
                <div><strong>${I18N['rr.destination']}:</strong> ${esc(destLabels[r.destination_platform] || r.destination_platform)}</div>
                <div><strong>${I18N['chat.col.status']}:</strong> ${statusPillHtml(r.status)}</div>
                ${r.error_message ? `<div style="grid-column:1/-1;"><strong>${I18N['rr.details.last_error']}:</strong> <span class="p-cell-muted">${esc(r.error_message)}</span></div>` : ''}
            </div>
            <h4 style="margin:14px 0 8px;">${I18N['rr.details.timeline']}</h4>
            ${timeline.length ? `<ul style="list-style:none;padding:0;margin:0;">` + timeline.map(t => `
                <li style="padding:6px 0;border-bottom:1px solid var(--panel-border);display:flex;justify-content:space-between;">
                    <span>${eventLabels[t.event] || t.event}</span>
                    <span class="p-cell-muted">${t.at ? formatDate(t.at) : '-'}</span>
                </li>`).join('') + `</ul>` : `<p class="p-cell-muted">${I18N['rr.details.no_timeline']}</p>`}
        `;
    };

    window.openDetailsFromAnalytics = async function () {
        const card = document.getElementById('rrAnalyticsCard');
        const isHidden = card.style.display === 'none';
        card.style.display = isHidden ? 'block' : 'none';
        if (!isHidden || !currentWebsiteId) return;

        const body = document.getElementById('rrAnalyticsBody');
        body.textContent = I18N['common.loading'];
        const res = await fetchJSON('/api/review-requests/analytics?website_id=' + currentWebsiteId);
        if (!res.success) { body.textContent = res.error || I18N['rr.details_failed']; return; }

        const a = res.data.analytics;
        if (a.not_enough_data) {
            body.innerHTML = `<p>${I18N['rr.analytics.not_enough_data']}</p>`;
            return;
        }

        let html = `<div class="p-grid cols-3">
            <div><strong>${I18N['rr.analytics.conversion_rate']}:</strong> ${a.conversion_rate !== null ? a.conversion_rate + '%' : I18N['rr.analytics.not_enough_data']}</div>
            <div><strong>${I18N['rr.analytics.avg_time_to_review']}:</strong> ${a.avg_time_to_review_hours !== null ? a.avg_time_to_review_hours + ' ' + I18N['rr.analytics.hours'] : I18N['rr.analytics.not_enough_data']}</div>
            <div><strong>${I18N['rr.analytics.requests_sent']}:</strong> ${a.requests_sent}</div>
        </div>`;

        if (a.channel_performance && a.channel_performance.length) {
            html += `<h4 style="margin:14px 0 8px;">${I18N['rr.analytics.channel_performance']}</h4><ul style="list-style:none;padding:0;">`;
            a.channel_performance.forEach(c => {
                html += `<li style="padding:4px 0;">${channelLabel(c.channel)}: ${c.sent} ${I18N['rr.stat.sent']}, ${c.not_enough_data ? I18N['rr.analytics.not_enough_data'] : c.conversion_rate + '%'}</li>`;
            });
            html += `</ul>`;
        }

        body.innerHTML = html;
    };

    window.exportRR = function () {
        if (!currentWebsiteId) return;
        const status = document.getElementById('rrFilterStatus').value;
        const channel = document.getElementById('rrFilterChannel').value;
        const qs = new URLSearchParams({ website_id: currentWebsiteId });
        if (status) qs.set('status', status);
        if (channel) qs.set('channel', channel);
        window.location.href = '/api/review-requests/export?' + qs.toString();
    };

    (async function showUsage() {
        const res = await P.fetchJSON('/api/review-requests/usage');
        const u = res.success && res.data.usage;
        if (!u) return;
        const parts = [];
        [['review_requests_monthly', 'Requests this month'], ['sms_requests_monthly', 'SMS this month']].forEach(function (k) {
            const m = u[k[0]];
            if (m && m.limit >= 0 && !(k[0] === 'sms_requests_monthly' && m.limit === 0)) parts.push({ label: k[1], used: m.used, limit: m.limit });
        });
        if (!parts.length) return;
        const bar = document.querySelector('.p-toolbar');
        if (!bar) return;
        const chip = document.createElement('span');
        chip.id = 'rrUsage';
        chip.className = 'pill';
        chip.setAttribute('role', 'status');
        chip.style.cssText = 'align-self:center;padding:6px 12px';
        chip.textContent = parts.map(function (x) { return x.label + ': ' + x.used + ' / ' + x.limit; }).join(' · ');
        if (parts.some(function (x) { return x.used >= x.limit; })) { chip.style.background = '#fee2e2'; chip.style.color = '#991b1b'; chip.title = 'You reached your plan limit. Upgrade your plan to send more.'; }
        bar.appendChild(chip);
    })();
    window.toggleQr = async function () {
        let box = document.getElementById('qrPanel');
        if (box) { box.remove(); return; }
        const wid = document.getElementById('rrWebsiteId').value;
        const res = await P.fetchJSON('/api/review-requests/qr?website_id=' + encodeURIComponent(wid));
        box = document.createElement('div');
        box.id = 'qrPanel';
        box.className = 'p-card';
        box.style.cssText = 'margin:12px 0;display:flex;gap:18px;flex-wrap:wrap;align-items:center';
        if (!res.success) { box.textContent = res.error || 'Could not create the QR code'; }
        else {
            const d = res.data;
            const warn = d.ready ? '' : '<p class="p-cell-muted">Add your Google or TripAdvisor review link in Campaign Settings first, otherwise the page will have no buttons.</p>';
            box.innerHTML = '<div style="width:200px;max-width:100%" role="img" aria-label="QR code for the review page">' + d.svg + '</div>'
                + '<div style="flex:1;min-width:240px"><h3 style="margin:0 0 6px">Review page for your guests</h3>'
                + '<p class="p-cell-muted" style="margin:0 0 8px">Print the QR on counters, receipts or vehicles. Everyone who scans it sees all your review sites.</p>'
                + '<input class="p-select" readonly style="width:100%" id="qrUrl" value="' + P.esc(d.url) + '">'
                + '<div style="display:flex;gap:8px;margin-top:8px"><button class="p-btn xs" id="qrCopy">Copy link</button><button class="p-btn outline xs" id="qrPrint">Print</button></div>' + warn + '</div>';
        }
        document.querySelector('.p-toolbar').insertAdjacentElement('afterend', box);
        const c = document.getElementById('qrCopy'), pr = document.getElementById('qrPrint');
        if (c) c.onclick = () => { navigator.clipboard.writeText(document.getElementById('qrUrl').value).then(() => P.toast('Link copied', 'success'), () => P.toast('Copy failed', 'error')); };
        if (pr) pr.onclick = () => { const w = window.open('', '_blank'); if (!w) return; w.document.write('<title>QR</title><body style="text-align:center;font-family:sans-serif">' + res.data.svg + '<h2>Scan to leave us a review</h2></body>'); w.document.close(); w.focus(); w.print(); };
    };
    window.toggleDestinations = async function () {
        const card = document.getElementById('rrDestinationsCard');
        const isHidden = card.style.display === 'none';
        card.style.display = isHidden ? 'block' : 'none';
        if (!isHidden || !currentWebsiteId) return;

        const body = document.getElementById('rrDestinationsBody');
        body.textContent = I18N['common.loading'];
        const res = await fetchJSON('/api/review-requests/destinations?website_id=' + currentWebsiteId);
        if (!res.success || !res.data.destinations || !res.data.destinations.length) {
            body.textContent = res.error || I18N['rr.destinations.none'];
            return;
        }

        body.innerHTML = `<ul style="list-style:none;padding:0;margin:0;">` + res.data.destinations.map(d => `
            <li style="padding:6px 0;border-bottom:1px solid var(--panel-border);display:flex;justify-content:space-between;align-items:center;">
                <span>${esc(d.label)}${d.location_name ? ' — <span class="p-cell-muted">' + esc(d.location_name) + '</span>' : ''}</span>
                <span class="pill ${d.connected ? 'green' : 'gray'}">${d.connected ? I18N['rr.destinations.connected'] : I18N['rr.channel.not_configured']}</span>
            </li>`).join('') + `</ul>`;
    };

    window.editGuest = function (id) {
        const r = (window.__rrRows || {})[id];
        if (!r) return;
        document.getElementById('guestEditId').value = id;
        document.getElementById('crmContactPickerWrap').style.display = 'none';
        document.getElementById('addGuestModalTitle').textContent = I18N['rr.edit'] + ': ' + r.guest_name;
        document.getElementById('guestName').value = r.guest_name || '';
        document.getElementById('guestChannel').value = r.channel || 'whatsapp';
        document.getElementById('guestPhone').value = r.guest_phone || '';
        document.getElementById('guestEmail').value = r.guest_email || '';
        document.getElementById('guestDestination').value = r.destination_platform || 'other';
        // service_end_date من الـ API بصيغة "YYYY-MM-DD HH:MM:SS" - datetime-local محتاج "YYYY-MM-DDTHH:MM"
        document.getElementById('serviceEndDate').value = (r.service_end_date || '').replace(' ', 'T').slice(0, 16);
        document.getElementById('addGuestSubmitBtn').textContent = I18N['rr.save_edit'];
        toggleGuestChannelFields();
        updateDestinationHint();
        P.openModal('addGuestModal');
    };

    window.retryGuest = async function (id) {
        const res = await fetchJSON('/api/review-requests/' + id + '/retry', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: currentWebsiteId }),
        });
        if (res.success) { toast(I18N['rr.retry_done'], 'success'); loadRequests(); }
        else toast(res.error || I18N['rr.retry_failed'], 'error');
    };

    window.updateDestinationHint = async function () {
        const platform = document.getElementById('guestDestination').value;
        const hint = document.getElementById('guestDestinationHint');
        hint.textContent = '';
        if (platform === 'other' || !currentWebsiteId) return;

        const res = await fetchJSON('/api/review-requests/destinations?website_id=' + currentWebsiteId);
        if (!res.success) return;
        const dest = (res.data.destinations || []).find(d => d.platform === platform);
        if (dest && !dest.connected) {
            hint.textContent = '⚠️ ' + I18N['rr.destinations.oauth_hint'];
        }
    };

    window.toggleGuestChannelFields = async function () {
        const channel = document.getElementById('guestChannel').value;
        const needsPhone = channel === 'whatsapp' || channel === 'sms';
        document.getElementById('guestPhoneWrap').style.display = needsPhone ? 'block' : 'none';
        document.getElementById('guestEmailWrap').style.display = channel === 'email' ? 'block' : 'none';

        const hint = document.getElementById('guestChannelStatusHint');
        hint.textContent = '';
        if (!currentWebsiteId) return;
        const res = await fetchJSON('/api/review-requests/channel-status?website_id=' + currentWebsiteId);
        if (res.success && res.data.channels[channel] !== 'connected') {
            hint.textContent = '⚠️ ' + I18N['rr.channel.not_configured'];
        }
    };

    async function loadSettingsIntoForm() {
        const res = await fetchJSON('/api/review-requests/settings?website_id=' + currentWebsiteId);
        if (!res.success) return;
        const s = res.data.settings;
        // من غير رابط تقييم أي طلب بيترفض وقت الإضافة؛ بنقول ده للعميل من أول الصفحة مش بعد ما يكتب بيانات الضيف
        const AR = (document.documentElement.lang || 'ar').startsWith('ar');
        let warn = document.getElementById('rrNoLinkWarn');
        const noLink = !s.default_review_link && !s.google_review_link && !s.tripadvisor_review_link;
        if (noLink && !warn) {
            warn = document.createElement('div');
            warn.id = 'rrNoLinkWarn';
            warn.className = 'alert alert-warning';
            warn.style.margin = '0 0 14px';
            warn.innerHTML = '<b>' + (AR ? 'قبل ما تبعت طلبات تقييم:' : 'Before sending review requests:') + '</b> '
                + (AR ? 'حط رابط صفحة التقييم بتاعتك (Google أو TripAdvisor) من «🔗 وجهات التقييم» أو «⚙️ إعدادات الحملة». من غيره المنصة مش هتقدر تبعت أي طلب.' : 'add your review page link (Google or TripAdvisor) from “🔗 Review destinations” or “⚙️ Campaign settings”. Without it no request can be sent.')
                + (AR ? ' <span class="p-cell-muted">رابط Google بتلاقيه في ملف نشاطك على جوجل ← «اطلب تقييمات» ← نسخ الرابط.</span>' : ' <span class="p-cell-muted">Find your Google link in your Business Profile → “Ask for reviews” → copy link.</span>');
            (document.querySelector('.panel-content') || document.body).prepend(warn);
        } else if (!noLink && warn) {
            warn.remove();
        }
        document.getElementById('rrEnabled').checked = s.is_enabled == 1;
        document.getElementById('rrReviewLink').value = s.default_review_link || '';
        document.getElementById('rrGoogleReviewLink').value = s.google_review_link || '';
        document.getElementById('rrTripadvisorReviewLink').value = s.tripadvisor_review_link || '';
        document.getElementById('rrDelayHours').value = s.default_delay_hours;
        document.getElementById('rrMessageTemplate').value = s.message_template;
        document.getElementById('rrEmailSubject').value = s.email_subject || '';
        document.getElementById('rrReminderEnabled').checked = s.reminder_enabled == 1;
        document.getElementById('rrReminderHours').value = s.reminder_after_hours;
        document.getElementById('rrReminderTemplate').value = s.reminder_template;
        document.getElementById('rrAutoFromCrm').checked = s.auto_from_crm_won == 1;
        document.getElementById('rrAutoFromBookings').checked = s.auto_from_bookings == 1;
        await loadTemplatePicker();
    }

    async function loadTemplatePicker() {
        const res = await fetchJSON('/api/review-requests/templates?website_id=' + currentWebsiteId);
        const picker = document.getElementById('rrTemplatePicker');
        if (!res.success || !res.data.templates || !res.data.templates.length) {
            picker.innerHTML = '';
            window.__rrTemplates = {};
            return;
        }
        window.__rrTemplates = {};
        picker.innerHTML = res.data.templates.map(t => {
            window.__rrTemplates[t.id] = t;
            return `<option value="${t.id}">${esc(t.name)}</option>`;
        }).join('');
    }

    window.applyTemplate = function () {
        const id = document.getElementById('rrTemplatePicker').value;
        const t = (window.__rrTemplates || {})[id];
        if (!t) return;
        document.getElementById('rrMessageTemplate').value = t.message_template;
        document.getElementById('rrEmailSubject').value = t.email_subject || '';
        toast(I18N['rr.templates.applied'], 'success');
    };

    window.deleteCurrentTemplate = async function () {
        const id = document.getElementById('rrTemplatePicker').value;
        if (!id) return;
        if (!confirm(I18N['rr.templates.delete_confirm'])) return;
        const res = await fetchJSON('/api/review-requests/templates/' + id + '?website_id=' + currentWebsiteId, { method: 'DELETE' });
        if (res.success) { toast(I18N['common.deleted'], 'success'); loadTemplatePicker(); }
        else toast(res.error || I18N['rr.templates.delete_failed'], 'error');
    };

    window.saveNewTemplate = async function () {
        const name = document.getElementById('newTemplateName').value.trim();
        const message_template = document.getElementById('newTemplateMessage').value.trim();
        const email_subject = document.getElementById('newTemplateSubject').value.trim();
        if (!name || !message_template) { toast(I18N['rr.all_fields_required'], 'error'); return; }

        const res = await fetchJSON('/api/review-requests/templates', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: currentWebsiteId, name, message_template, email_subject }),
        });
        if (res.success) {
            toast(I18N['rr.templates.saved'], 'success');
            document.getElementById('newTemplateName').value = '';
            document.getElementById('newTemplateMessage').value = '';
            document.getElementById('newTemplateSubject').value = '';
            P.closeModal('rrNewTemplateModal');
            loadTemplatePicker();
        } else {
            toast(res.error || I18N['rr.templates.save_failed'], 'error');
        }
    };

    let crmSearchTimer = null;
    window.searchCrmContactsDebounced = function () {
        clearTimeout(crmSearchTimer);
        crmSearchTimer = setTimeout(searchCrmContacts, 350);
    };

    async function searchCrmContacts() {
        const term = document.getElementById('crmContactSearch').value.trim();
        const box = document.getElementById('crmContactResults');
        if (term.length < 2) { box.innerHTML = ''; return; }

        const res = await fetchJSON('/api/review-requests/crm-contacts?search=' + encodeURIComponent(term));
        if (!res.success || !res.data.contacts.length) {
            box.innerHTML = I18N['rr.crm.no_results'];
            return;
        }

        window.__rrContacts = {};
        box.innerHTML = res.data.contacts.map(c => {
            window.__rrContacts[c.id] = c;
            const prev = c.previous_request ? ` — <span class="p-cell-muted">${I18N['rr.crm.previous_request']}: ${esc(c.previous_request.status)}</span>` : '';
            return `<div style="padding:5px 0;cursor:pointer;border-bottom:1px solid var(--panel-border);" onclick="selectCrmContact(${c.id})">
                <strong>${esc(c.name)}</strong> <span dir="ltr" class="p-cell-muted">${esc(c.phone || c.email || '')}</span>${prev}
            </div>`;
        }).join('');
    }

    window.selectCrmContact = function (id) {
        const c = (window.__rrContacts || {})[id];
        if (!c) return;
        document.getElementById('guestName').value = c.name || '';
        if (c.phone) {
            document.getElementById('guestChannel').value = 'whatsapp';
            document.getElementById('guestPhone').value = c.phone;
        } else if (c.email) {
            document.getElementById('guestChannel').value = 'email';
            document.getElementById('guestEmail').value = c.email;
        }
        toggleGuestChannelFields();
        document.getElementById('crmContactSearch').value = c.name || '';
        document.getElementById('crmContactResults').innerHTML = '';
    };

    window.addGuest = async function () {
        const alertBox = document.getElementById('addGuestAlert');
        alertBox.style.display = 'none';
        const editId = document.getElementById('guestEditId').value;
        const guest_name = document.getElementById('guestName').value.trim();
        const channel = document.getElementById('guestChannel').value;
        const guest_phone = document.getElementById('guestPhone').value.trim();
        const guest_email = document.getElementById('guestEmail').value.trim();
        const destination_platform = document.getElementById('guestDestination').value;
        const service_end_date = document.getElementById('serviceEndDate').value;

        if (!guest_name || !service_end_date || ((channel === 'whatsapp' || channel === 'sms') && !guest_phone) || (channel === 'email' && !guest_email)) {
            alertBox.textContent = I18N['rr.all_fields_required'];
            alertBox.style.display = 'block';
            return;
        }

        const payload = { website_id: currentWebsiteId, guest_name, channel, guest_phone, guest_email, destination_platform, service_end_date };
        const res = editId
            ? await fetchJSON('/api/review-requests/' + editId, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
            : await fetchJSON('/api/review-requests', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });

        if (res.success) {
            toast(editId ? I18N['common.updated'] : I18N['rr.guest_added'], 'success');
            resetAddGuestModal();
            P.closeModal('addGuestModal');
            P.reqPage = 1;
            loadAll();
        } else {
            alertBox.textContent = res.error || I18N['rr.add_failed'];
            alertBox.style.display = 'block';
        }
    };

    function resetAddGuestModal() {
        document.getElementById('guestEditId').value = '';
        document.getElementById('addGuestModalTitle').textContent = '+ ' + I18N['rr.add_guest_title'];
        document.getElementById('addGuestSubmitBtn').textContent = I18N['rr.add_and_schedule'];
        document.getElementById('guestName').value = '';
        document.getElementById('guestPhone').value = '';
        document.getElementById('guestEmail').value = '';
        document.getElementById('guestDestination').value = 'other';
        document.getElementById('guestDestinationHint').textContent = '';
        document.getElementById('serviceEndDate').value = '';
        document.getElementById('crmContactSearch').value = '';
        document.getElementById('crmContactResults').innerHTML = '';
        document.getElementById('crmContactPickerWrap').style.display = 'block';
    }
    window.resetAddGuestModal = resetAddGuestModal;

    window.optOutGuest = async function (id) {
        if (!confirm(I18N['rr.optout_confirm'])) return;
        const res = await fetchJSON('/api/review-requests/' + id + '/opt-out', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: currentWebsiteId, reason: 'manual_cancel' }),
        });
        if (res.success) { toast(I18N['common.deleted'], 'success'); loadRequests(); }
        else toast(res.error || I18N['rr.optout_failed'], 'error');
    };

    window.aiAssist = async function (field, action) {
        const textareaId = field === 'message' ? 'rrMessageTemplate' : 'rrReminderTemplate';
        const textarea = document.getElementById(textareaId);
        const target_language = (document.getElementById('rrAiTargetLang').value || 'ar').trim();

        if (action !== 'generate' && !textarea.value.trim()) {
            toast(I18N['rr.ai.empty_text'], 'error');
            return;
        }

        const res = await fetchJSON('/api/review-requests/ai-assist', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: currentWebsiteId, action, text: textarea.value, target_language }),
        });
        if (res.success) { textarea.value = res.data.text; toast(I18N['rr.ai.done'], 'success'); }
        else toast(res.error || I18N['rr.ai.failed'], 'error');
    };

    window.saveRRSettings = async function () {
        const alertBox = document.getElementById('rrSettingsAlert');
        alertBox.style.display = 'none';
        const payload = {
            website_id: currentWebsiteId,
            is_enabled: document.getElementById('rrEnabled').checked ? 1 : 0,
            default_review_link: document.getElementById('rrReviewLink').value.trim(),
            google_review_link: document.getElementById('rrGoogleReviewLink').value.trim(),
            tripadvisor_review_link: document.getElementById('rrTripadvisorReviewLink').value.trim(),
            default_delay_hours: document.getElementById('rrDelayHours').value,
            message_template: document.getElementById('rrMessageTemplate').value,
            email_subject: document.getElementById('rrEmailSubject').value,
            reminder_enabled: document.getElementById('rrReminderEnabled').checked ? 1 : 0,
            reminder_after_hours: document.getElementById('rrReminderHours').value,
            reminder_template: document.getElementById('rrReminderTemplate').value,
            auto_from_crm_won: document.getElementById('rrAutoFromCrm').checked ? 1 : 0,
            auto_from_bookings: document.getElementById('rrAutoFromBookings').checked ? 1 : 0,
        };

        const res = await fetchJSON('/api/review-requests/settings', {
            method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
        });

        if (res.success) { toast(I18N['common.updated'], 'success'); P.closeModal('rrSettingsModal'); }
        else { alertBox.textContent = res.error || I18N['common.update_failed']; alertBox.style.display = 'block'; }
    };

    toggleGuestChannelFields();
    loadWebsites();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('review_requests', $this->tr('rr.page_title'), $this->tr('rr.page_subtitle'), $body, $script);
        exit;
    }

    /** GET /api/review-requests - يدعم فلاتر (status, channel, search, date_from, date_to) وصفحات (page, per_page) */
    public function listRequests(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id', 0);
        if (!$this->ownsWebsite($websiteId)) {
            return $this->error('غير مصرح', 403);
        }

        try {
            $filters = [
                'status' => (string) $this->get('status', ''),
                'channel' => (string) $this->get('channel', ''),
                'search' => (string) $this->get('search', ''),
                'date_from' => (string) $this->get('date_from', ''),
                'date_to' => (string) $this->get('date_to', ''),
            ];
            $page = (int) $this->get('page', 1);
            $perPage = (int) $this->get('per_page', 25);

            $result = $this->service->getRequestsFiltered($websiteId, array_filter($filters), $page, $perPage);

            return $this->success([
                'requests' => array_map(fn ($r) => $r->toArray(), $result['items']),
                'total' => $result['total'],
                'page' => $result['page'],
                'per_page' => $result['per_page'],
            ]);
        } catch (Exception $e) {
            Logger::error('listRequests Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب الطلبات', 500);
        }
    }

    /** GET /api/review-requests/{id} - تفاصيل طلب واحد + Timeline حقيقي */
    public function getRequest(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id', 0);
        if (!$this->ownsWebsite($websiteId)) {
            return $this->error('غير مصرح', 403);
        }

        try {
            $request = $this->service->getRequest((int) ($params['id'] ?? 0), $websiteId);
            if (!$request) {
                return $this->error('الطلب غير موجود', 404);
            }
            return $this->success([
                'request' => $request->toArray(),
                'timeline' => $request->buildTimeline(),
            ]);
        } catch (Exception $e) {
            Logger::error('getRequest Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب تفاصيل الطلب', 500);
        }
    }

    /** GET /api/review-requests/stats */
    public function getStats(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id', 0);
        if (!$this->ownsWebsite($websiteId)) {
            return $this->error('غير مصرح', 403);
        }

        try {
            return $this->success(['stats' => $this->service->getStats($websiteId)]);
        } catch (Exception $e) {
            return $this->error('تعذر جلب الإحصائيات', 500);
        }
    }

    /** GET /api/review-requests/analytics - Section 21، بيرجع not_enough_data:true لو العينة صغيرة */
    public function getAnalytics(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id', 0);
        if (!$this->ownsWebsite($websiteId)) {
            return $this->error('غير مصرح', 403);
        }

        try {
            return $this->success(['analytics' => $this->service->getAnalytics($websiteId)]);
        } catch (Exception $e) {
            Logger::error('getAnalytics Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب التحليلات', 500);
        }
    }

    /** GET /api/review-requests/channel-status - حالة قنوات الإرسال الفعلية (واتساب/إيميل) */
    public function getChannelStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id', 0);
        if (!$this->ownsWebsite($websiteId)) {
            return $this->error('غير مصرح', 403);
        }

        return $this->success(['channels' => $this->service->getChannelStatus($websiteId)]);
    }

    /** GET /api/review-requests/destinations - حالة اتصال Google Business/TripAdvisor الفعلية */
    public function getDestinations(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id', 0);
        if (!$this->ownsWebsite($websiteId)) {
            return $this->error('غير مصرح', 403);
        }

        return $this->success(['destinations' => $this->service->getDestinationStatus($websiteId)]);
    }

    /** GET /api/review-requests/crm-contacts?search= - Customer Selection من CRM الموجود فعليًا (Section 5) */
    public function getCrmContacts(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $contacts = $this->service->searchCrmContacts(
                (int) $this->user['id'],
                (string) $this->get('search', ''),
                (int) $this->get('limit', 20)
            );
            return $this->success(['contacts' => $contacts]);
        } catch (Exception $e) {
            Logger::error('getCrmContacts Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب العملاء', 500);
        }
    }

    /** GET /api/review-requests/smart-timing - اقتراح استرشادي لأفضل توقيت بناءً على بيانات حقيقية (Section 11) */
    public function getSmartTiming(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id', 0);
        if (!$this->ownsWebsite($websiteId)) {
            return $this->error('غير مصرح', 403);
        }

        return $this->success(['timing' => $this->service->getSmartTimingSuggestion($websiteId)]);
    }

    /** GET /api/review-requests/templates - قوالب رسائل جاهزة (Section 9) */
    public function getTemplates(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id', 0);
        if (!$this->ownsWebsite($websiteId)) {
            return $this->error('غير مصرح', 403);
        }

        try {
            return $this->success(['templates' => $this->service->getTemplates($websiteId)]);
        } catch (Exception $e) {
            Logger::error('getTemplates Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب القوالب', 500);
        }
    }

    /** POST /api/review-requests/templates - إضافة قالب مخصص جديد */
    public function createTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id', 0);
        if (!$this->ownsWebsite($websiteId)) {
            return $this->error('غير مصرح', 403);
        }

        try {
            $template = $this->service->createTemplate(
                $websiteId,
                (string) $this->get('name', ''),
                (string) $this->get('message_template', ''),
                $this->get('email_subject') !== null ? (string) $this->get('email_subject') : null
            );
            return $this->success(['template' => $template->toArray()], 'تمت الإضافة', 201);
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        }
    }

    /** DELETE /api/review-requests/templates/{id} */
    public function deleteTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id', 0);
        if (!$this->ownsWebsite($websiteId)) {
            return $this->error('غير مصرح', 403);
        }

        try {
            $this->service->deleteTemplate((int) ($params['id'] ?? 0), $websiteId);
            return $this->success([], 'تم الحذف');
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        }
    }

    /** POST /api/review-requests */
    public function createRequest(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['website_id' => 'required', 'guest_name' => 'required', 'service_end_date' => 'required'])) {
            return $this->error('بيانات ناقصة', 422);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$this->ownsWebsite($websiteId)) {
            return $this->error('غير مصرح', 403);
        }

        $channel = (string) $this->get('channel', 'whatsapp');
        $guestPhone = $this->get('guest_phone') !== null ? (string) $this->get('guest_phone') : null;
        $guestEmail = $this->get('guest_email') !== null ? (string) $this->get('guest_email') : null;

        try {
            $request = $this->service->createRequest(
                (int) $this->user['id'],
                $websiteId,
                (string) $this->get('guest_name'),
                $guestPhone,
                (string) $this->get('service_end_date'),
                $channel,
                $guestEmail,
                'manual',
                null,
                (string) $this->get('destination_platform', 'other')
            );
            return $this->success(['request' => $request->toArray()], 'تمت الإضافة', 201);
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        }
    }

    /** POST /api/review-requests/{id}/opt-out */
    public function optOut(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $requestId = (int) ($params['id'] ?? 0);

            // تأكيد Tenant Isolation: الطلب ده بتاع موقع بتاع المستخدم الحالي فعلاً
            $existing = (new ReviewRequest())->find($requestId);
            if (!$existing || !$this->ownsWebsite((int) $existing->getAttribute('website_id'))) {
                return $this->error('غير مصرح', 403);
            }

            $reason = $this->get('reason') !== null ? (string) $this->get('reason') : null;
            $this->service->optOut($requestId, $reason);
            return $this->success([], 'تم الإلغاء وتسجيله في قائمة عدم التواصل');
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        }
    }

    /** POST /api/review-requests/{id}/retry - إعادة محاولة فورية لطلب فشل (Section 19)، بحد أقصى محاولات */
    public function retryRequest(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id', 0);
        if (!$this->ownsWebsite($websiteId)) {
            return $this->error('غير مصرح', 403);
        }

        try {
            $request = $this->service->retryRequest((int) ($params['id'] ?? 0), $websiteId);
            return $this->success(['request' => $request->toArray()], 'تمت إعادة المحاولة');
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        }
    }

    /** PUT /api/review-requests/{id} - تعديل طلب لسه ما اتبعتش (Section 6) */
    public function updateRequest(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id', 0);
        if (!$this->ownsWebsite($websiteId)) {
            return $this->error('غير مصرح', 403);
        }

        try {
            $data = array_filter([
                'guest_name' => $this->get('guest_name'),
                'channel' => $this->get('channel'),
                'guest_phone' => $this->get('guest_phone'),
                'guest_email' => $this->get('guest_email'),
                'service_end_date' => $this->get('service_end_date'),
                'destination_platform' => $this->get('destination_platform'),
            ], fn ($v) => $v !== null);

            $request = $this->service->updateRequest((int) ($params['id'] ?? 0), $websiteId, $data);
            return $this->success(['request' => $request->toArray()], 'تم التعديل');
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        }
    }

    /** POST /api/review-requests/ai-assist - مساعد AI لصياغة الرسالة (Section 10) */
    public function aiAssist(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['action' => 'required'])) {
            return $this->error('بيانات ناقصة', 422);
        }

        $websiteId = (int) $this->get('website_id', 0);
        $website = ($websiteId && $this->ownsWebsite($websiteId)) ? (new Website())->find($websiteId) : null;

        $result = $this->service->aiAssistMessage(
            (string) $this->get('action'),
            (string) $this->get('text', ''),
            [
                'business_name' => $website ? (string) $website->getAttribute('company_name') : '',
                'target_language' => (string) $this->get('target_language', 'ar'),
            ]
        );

        if (!$result['success']) {
            return $this->error($result['error'], 422);
        }
        return $this->success(['text' => $result['text']]);
    }

    /** GET /api/review-requests/export - CSV Export بنفس فلاتر القائمة (Section 23) */
    public function exportCsv(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            http_response_code(401);
            exit;
        }
        $websiteId = (int) $this->get('website_id', 0);
        if (!$this->ownsWebsite($websiteId)) {
            http_response_code(403);
            exit;
        }

        try {
            $filters = array_filter([
                'status' => (string) $this->get('status', ''),
                'channel' => (string) $this->get('channel', ''),
                'search' => (string) $this->get('search', ''),
                'date_from' => (string) $this->get('date_from', ''),
                'date_to' => (string) $this->get('date_to', ''),
            ]);
            $rows = $this->service->getRequestsForExport($websiteId, $filters);

            $output = fopen('php://temp', 'r+');
            fputcsv($output, ['#', 'الضيف', 'القناة', 'واتساب', 'إيميل', 'الحالة', 'موعد الإرسال', 'تاريخ الإرسال', 'تاريخ التقييم', 'تاريخ الإنشاء']);
            foreach ($rows as $row) {
                fputcsv($output, [
                    $row['id'], $row['guest_name'], $row['channel'] ?? 'whatsapp', $row['guest_phone'] ?? '',
                    $row['guest_email'] ?? '', $row['status'], $row['scheduled_send_at'], $row['sent_at'] ?? '',
                    $row['reviewed_at'] ?? '', $row['created_at'] ?? '',
                ]);
            }
            rewind($output);
            $csv = "\xEF\xBB\xBF" . stream_get_contents($output);
            fclose($output);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="tourfecto-review-requests-' . date('Y-m-d') . '.csv"');
            header('Content-Length: ' . strlen($csv));
            echo $csv;
            exit;
        } catch (Exception $e) {
            Logger::error('exportCsv Error', ['message' => $e->getMessage()]);
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(500);
            echo 'تعذر التصدير';
            exit;
        }
    }

    /** GET /api/review-requests/settings */
    public function getSettings(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id', 0);
        if (!$this->ownsWebsite($websiteId)) {
            return $this->error('غير مصرح', 403);
        }

        return $this->success(['settings' => $this->service->getSettings($websiteId)]);
    }

    /** PUT /api/review-requests/settings */
    public function saveSettings(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id', 0);
        if (!$this->ownsWebsite($websiteId)) {
            return $this->error('غير مصرح', 403);
        }

        try {
            $this->service->saveSettings($websiteId, [
                'is_enabled' => (int) $this->get('is_enabled', 1),
                'default_review_link' => (string) $this->get('default_review_link', ''),
                'google_review_link' => (string) $this->get('google_review_link', ''),
                'tripadvisor_review_link' => (string) $this->get('tripadvisor_review_link', ''),
                'default_delay_hours' => (int) $this->get('default_delay_hours', 4),
                'message_template' => (string) $this->get('message_template', ''),
                'email_subject' => (string) $this->get('email_subject', ''),
                'reminder_enabled' => (int) $this->get('reminder_enabled', 1),
                'reminder_after_hours' => (int) $this->get('reminder_after_hours', 48),
                'reminder_template' => (string) $this->get('reminder_template', ''),
                'auto_from_crm_won' => (int) $this->get('auto_from_crm_won', 0),
                'auto_from_bookings' => (int) $this->get('auto_from_bookings', 0),
            ]);
            return $this->success([], 'تم الحفظ');
        } catch (Exception $e) {
            Logger::error('saveSettings Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر الحفظ', 500);
        }
    }

    /** يتأكد إن الموقع ده فعلاً ملك المستخدم الحالي */
    private function ownsWebsite(int $websiteId): bool
    {
        if (!$websiteId) {
            return false;
        }
        $website = (new Website())->find($websiteId);
        return $website && (int) $website->getAttribute('user_id') === (int) $this->user['id'];
    }
}
