<?php

/**
 * Tourfecto - Website Builder Controller
 * معالج شات موجّه لتوليد موقع سياحي كامل، + عرض عام للموقع المولّد على
 * tourfecto.pro/sites/{slug}، + تعديل بسيط بعد التوليد.
 * @version 1.0.0
 */
class WebsiteBuilderController extends Controller
{
    /** @var WebsiteBuilderService */
    private $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new WebsiteBuilderService();
    }

    /** GET /website-builder */
    public function index(array $params = []): array
    {
        $tTitle = $this->tr('wb.title');
        $tSubtitle = $this->tr('wb.subtitle');
        $tPlaceholder = $this->tr('wb.input_placeholder');
        $planBadge = class_exists('PlanUsage') ? PlanUsage::badgeHtml((int) ($this->user['id'] ?? 0), 'website_generation') . PlanUsage::badgeHtml((int) ($this->user['id'] ?? 0), 'website_item') : '';
        $tMyWebsites = $this->tr('wb.my_websites');

        $body = <<<HTML
        <div class="assistant-shell">
            <aside class="assistant-sidebar">
                <button class="p-btn primary btn-block" onclick="restartWizard()">+ {$this->tr('wb.new_website')}</button>
                <button class="p-btn outline btn-block" style="margin-top:8px" onclick="openPayoutSettings()">💳 استلام فلوس الحجوزات</button>
                <div id="payoutHint" style="font-size:12px;color:var(--panel-text-muted);margin-top:6px;line-height:1.6"></div>
                <h4 style="margin:16px 0 8px;font-size:12.5px;color:var(--panel-text-muted);">{$tMyWebsites}</h4>
                <div id="myWebsitesList" class="assistant-conv-list"><div class="p-empty">{$this->tr('common.loading')}</div></div>
            </aside>
            <main class="assistant-main">
                <div id="messagesArea" class="assistant-messages"></div>
                <div class="assistant-input-box">
                    <textarea id="messageInput" rows="1" placeholder="{$tPlaceholder}" onkeydown="handleInputKeydown(event)"></textarea>
                    <button id="sendBtn" class="p-btn primary" onclick="sendAnswer()">➤</button>
                </div>
                {$planBadge}
                <div id="wbAlert" class="alert alert-danger" style="display:none;margin-top:10px;"></div>
            </main>
        </div>

        <div class="p-modal-overlay" id="payoutModal">
            <div class="p-modal" style="max-width:560px;">
                <div class="p-modal-head"><h3>💳 استلام فلوس الحجوزات</h3><button class="p-modal-close" onclick="P.closeModal('payoutModal')">×</button></div>
                <div class="p-modal-body">
                    <p style="margin:0 0 12px;line-height:1.8;color:var(--panel-text-muted)">الزوار اللي بيحجزوا من موقعك - فلوسهم تروح فين؟</p>
                    <label style="display:block;margin:10px 0;line-height:1.7"><input type="radio" name="poMode" value="request"> <b>طلب حجز بس</b> - الحجز بيوصلك كطلب، وإنت تتواصل مع العميل وتأكد وتقبض بطريقتك (كاش، تحويل، فوري...)</label>
                    <label style="display:block;margin:10px 0;line-height:1.7"><input type="radio" name="poMode" value="own_stripe"> <b>دفع أونلاين على حساب Stripe بتاعك</b> - العميل يدفع بالكارت والفلوس تنزل في حسابك مباشرة</label>
                    <label id="poPlatformRow" style="display:none;margin:10px 0;line-height:1.7"><input type="radio" name="poMode" value="platform"> <b>المنصة تقبض وتحوّلك</b></label>
                    <div id="poStripeBox" style="display:none;margin-top:12px;padding:12px;border:1px solid var(--panel-border,#e3e6ec);border-radius:10px">
                        <div id="poKeyState" style="font-size:13px;margin-bottom:8px"></div>
                        <label class="form-label">مفتاح Stripe السري (Secret key)</label>
                        <input type="password" id="poKey" class="form-control" dir="ltr" placeholder="sk_live_..." autocomplete="off">
                        <div style="font-size:12px;color:var(--panel-text-muted);margin-top:6px;line-height:1.7">من لوحة Stripe ← Developers ← API keys ← Secret key. بيتخزّن مشفّر ومحدش بيشوفه.</div>
                        <button type="button" class="p-btn outline xs" id="poRemoveKey" style="display:none;margin-top:8px" onclick="removePayoutKey()">شيل المفتاح</button>
                    </div>
                    <div id="poAlert" class="alert alert-danger" style="display:none;margin-top:10px"></div>
                </div>
                <div class="p-modal-foot"><button class="p-btn primary" id="poSave" onclick="savePayoutSettings()">حفظ</button></div>
            </div>
        </div>

        <div class="p-modal-overlay" id="editSiteModal">
            <div class="p-modal" style="max-width:720px;">
                <div class="p-modal-head"><h3>{$this->tr('wb.manage_title')}</h3><button class="p-modal-close" onclick="P.closeModal('editSiteModal')">×</button></div>
                <div class="p-modal-body">
                    <div class="p-tabs" style="margin-bottom:16px;">
                        <button class="p-tab active" id="mgTabGeneralBtn" onclick="switchManageTab('general')">⚙️ {$this->tr('wb.tab_general')}</button>
                        <button class="p-tab" id="mgTabToursBtn" onclick="switchManageTab('tours')">🧳 {$this->tr('wb.tab_tours')}</button>
                    </div>

                    <div id="mgTabGeneral">
                        <label class="form-label">{$this->tr('wb.field.theme_color')}</label>
                        <select id="editThemeColor" class="form-control" style="margin-bottom:10px;">
                            <option value="gold">🟡 {$this->tr('wb.theme.gold')}</option>
                            <option value="blue">🔵 {$this->tr('wb.theme.blue')}</option>
                            <option value="green">🟢 {$this->tr('wb.theme.green')}</option>
                            <option value="red">🔴 {$this->tr('wb.theme.red')}</option>
                            <option value="purple">🟣 {$this->tr('wb.theme.purple')}</option>
                        </select>
                        <label class="form-label">{$this->tr('wb.field.business_name')}</label>
                        <input type="text" id="editBusinessName" class="form-control" style="margin-bottom:10px;">
                        <label class="form-label">{$this->tr('wb.field.tagline')}</label>
                        <input type="text" id="editTagline" class="form-control" style="margin-bottom:10px;">
                        <label class="form-label">{$this->tr('wb.field.hero_headline')}</label>
                        <input type="text" id="editHeroHeadline" class="form-control" style="margin-bottom:10px;">
                        <label class="form-label">{$this->tr('wb.field.hero_subtext')}</label>
                        <input type="text" id="editHeroSubtext" class="form-control" style="margin-bottom:10px;">
                        <label class="form-label">{$this->tr('wb.field.about_text')}</label>
                        <textarea id="editAboutText" class="form-control" rows="3" style="margin-bottom:10px;"></textarea>
                        <label class="form-label">{$this->tr('wb.field.cta_text')}</label>
                        <input type="text" id="editCtaText" class="form-control" style="margin-bottom:10px;">
                        <div class="p-grid cols-2">
                            <div><label class="form-label">{$this->tr('wb.field.contact_phone')}</label><input type="text" id="editPhone" class="form-control" dir="ltr"></div>
                            <div><label class="form-label">{$this->tr('wb.field.contact_whatsapp')}</label><input type="text" id="editWhatsapp" class="form-control" dir="ltr"></div>
                            <div><label class="form-label">{$this->tr('wb.field.contact_email')}</label><input type="email" id="editEmail" class="form-control" dir="ltr"></div>
                            <div><label class="form-label">{$this->tr('wb.field.contact_address')}</label><input type="text" id="editAddress" class="form-control"></div>
                        </div>
                        <button class="p-btn primary" style="margin-top:14px;" onclick="saveSiteEdit()">{$this->tr('common.save')}</button>
                    </div>

                    <div id="mgTabTours" style="display:none;">
                        <div id="toursManageList"></div>
                        <div class="p-card" style="margin-top:14px;">
                            <h4 style="margin-bottom:8px;font-size:13.5px;">➕ {$this->tr('wb.add_tour_title')}</h4>
                            <textarea id="newTourDescription" class="form-control" rows="2" placeholder="{$this->tr('wb.add_tour_placeholder')}" style="margin-bottom:10px;"></textarea>
                            <button class="p-btn primary" onclick="addNewTour()">✨ {$this->tr('wb.add_tour_button')}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;

    // ===== استلام فلوس الحجوزات (الفلوس تروح لصاحب النشاط مش للمنصة)
    let payoutState = null;
    const PO_LABEL = { request: 'الحجوزات بتوصلك كطلبات - من غير دفع أونلاين', own_stripe: 'الدفع الأونلاين شغّال على حساب Stripe بتاعك ✓', platform: 'المنصة بتقبض وتحوّلك' };
    async function loadPayout() {
        const r = await fetchJSON('/api/operator-payments');
        if (!r.success) return;
        payoutState = r.data;
        const h = document.getElementById('payoutHint'); if (h) h.textContent = PO_LABEL[payoutState.mode] || '';
    }
    function poSync() {
        const m = (document.querySelector('input[name=poMode]:checked') || {}).value;
        document.getElementById('poStripeBox').style.display = m === 'own_stripe' ? 'block' : 'none';
    }
    window.openPayoutSettings = async function () {
        await loadPayout();
        const s = payoutState || { mode: 'request' };
        document.querySelectorAll('input[name=poMode]').forEach(r => { r.checked = r.value === s.mode; r.disabled = s.can_edit === false; r.onchange = poSync; });
        document.getElementById('poPlatformRow').style.display = s.platform_allowed ? 'block' : 'none';
        document.getElementById('poKey').value = '';
        document.getElementById('poKeyState').textContent = s.has_stripe_key ? ('مفتاح متسجّل ينتهي بـ ••••' + (s.stripe_key_last4 || '') + (s.stripe_account_name ? ' · ' + s.stripe_account_name : '') + ' - سيبه فاضي لو مش عايز تغيّره') : 'لسه مفيش مفتاح';
        document.getElementById('poRemoveKey').style.display = s.has_stripe_key && s.can_edit !== false ? 'inline-block' : 'none';
        document.getElementById('poSave').disabled = s.can_edit === false;
        document.getElementById('poAlert').style.display = s.can_edit === false ? 'block' : 'none';
        if (s.can_edit === false) document.getElementById('poAlert').textContent = 'صاحب الحساب بس يقدر يغيّر طريقة استلام الفلوس.';
        poSync();
        P.openModal('payoutModal');
    };
    window.savePayoutSettings = async function () {
        const btn = document.getElementById('poSave'), al = document.getElementById('poAlert');
        al.style.display = 'none'; btn.disabled = true; btn.textContent = 'بنتأكد...';
        const mode = (document.querySelector('input[name=poMode]:checked') || {}).value || 'request';
        const key = document.getElementById('poKey').value.trim();
        const r = await fetchJSON('/api/operator-payments', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(key ? { mode, stripe_secret_key: key } : { mode }) });
        btn.disabled = false; btn.textContent = 'حفظ';
        if (r.success) { toast('تم الحفظ', 'success'); P.closeModal('payoutModal'); loadPayout(); }
        else { al.textContent = r.error || 'تعذر الحفظ'; al.style.display = 'block'; }
    };
    window.removePayoutKey = async function () {
        if (!confirm('تشيل مفتاح Stripe؟ الحجوزات هترجع طلبات من غير دفع أونلاين.')) return;
        const r = await fetchJSON('/api/operator-payments/key', { method: 'DELETE' });
        toast(r.message || r.error, r.success ? 'success' : 'error');
        if (r.success) { P.closeModal('payoutModal'); loadPayout(); }
    };
    loadPayout();
    let editingWebsiteId = null;

    function addMessage(role, html) {
        const area = document.getElementById('messagesArea');
        const div = document.createElement('div');
        div.className = 'assistant-msg ' + role;
        div.innerHTML = `<div class="assistant-msg-bubble">${html}</div>`;
        area.appendChild(div);
        area.scrollTop = area.scrollHeight;
    }

    function showQuickOptions(options) {
        if (!options || !options.length) return;
        const area = document.getElementById('messagesArea');
        const div = document.createElement('div');
        div.className = 'ws-quick-options';
        div.innerHTML = options.map(opt => `<button class="p-btn outline xs" onclick="pickOption(this, '${esc(opt).replace(/'/g, "\\'")}')">${esc(opt)}</button>`).join('');
        area.appendChild(div);
        area.scrollTop = area.scrollHeight;
    }

    window.pickOption = function (btn, value) {
        // نمنع الضغط تاني على نفس الأزرار بعد الاختيار
        btn.parentElement.querySelectorAll('button').forEach(b => b.disabled = true);
        submitAnswerValue(value);
    };

    async function loadState() {
        const res = await fetchJSON('/api/website-builder/state');
        if (!res.success) return;
        document.getElementById('messagesArea').innerHTML = '';
        if (res.data.done) {
            addMessage('assistant', I18N['wb.ready_message']);
            showGenerateButton();
        } else {
            addMessage('assistant', esc(res.data.question));
            showQuickOptions(res.data.options);
        }
    }

    function showGenerateButton() {
        const area = document.getElementById('messagesArea');
        const div = document.createElement('div');
        div.className = 'assistant-msg assistant';
        div.innerHTML = `<div class="assistant-msg-bubble"><button class="p-btn primary" onclick="generateSite()">✨ ${I18N['wb.generate_button']}</button></div>`;
        area.appendChild(div);
        area.scrollTop = area.scrollHeight;
    }

    window.handleInputKeydown = function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendAnswer(); }
    };

    window.sendAnswer = async function () {
        const input = document.getElementById('messageInput');
        const text = input.value.trim();
        if (!text) return;
        input.value = '';
        await submitAnswerValue(text);
    };

    async function submitAnswerValue(text) {
        addMessage('user', esc(text));

        const res = await fetchJSON('/api/website-builder/answer', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ message: text }),
        });

        if (res.success) {
            if (res.data.done) {
                addMessage('assistant', I18N['wb.ready_message']);
                showGenerateButton();
            } else {
                addMessage('assistant', esc(res.data.question));
                showQuickOptions(res.data.options);
            }
        }
    }

    window.generateSite = async function () {
        const alertBox = document.getElementById('wbAlert');
        alertBox.style.display = 'none';
        addMessage('assistant', '⏳ ' + I18N['wb.generating']);
        document.getElementById('sendBtn').disabled = true;

        const res = await fetchJSON('/api/website-builder/generate', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        document.getElementById('sendBtn').disabled = false;

        if (res.success) {
            const w = res.data.website;
            addMessage('assistant', `✅ ${I18N['wb.generated_success']} <br><a href="/sites/${w.slug}" target="_blank" class="p-btn outline xs" style="margin-top:8px;display:inline-block;">👁 ${I18N['wb.preview']}</a> <button class="p-btn success xs" onclick="publishSite(${w.id})" style="margin-top:8px;">🚀 ${I18N['wb.publish']}</button>`);
            loadMyWebsites();
            if (window.refreshPlanUsage) window.refreshPlanUsage();
        } else {
            if (res.data && res.data.shortfall) {
                alertBox.textContent = I18N['wb.insufficient_balance'].replace('{amount}', res.data.shortfall);
            } else {
                alertBox.textContent = res.error || I18N['wb.generate_failed'];
            }
            alertBox.style.display = 'block';
        }
    };

    window.publishSite = async function (id) {
        const res = await fetchJSON('/api/website-builder/' + id + '/publish', { method: 'POST' });
        if (res.success) { toast(I18N['wb.published'], 'success'); loadMyWebsites(); }
        else toast(res.error || I18N['common.update_failed'], 'error');
    };

    window.restartWizard = async function () {
        await fetchJSON('/api/website-builder/reset', { method: 'POST' });
        loadState();
    };

    async function loadMyWebsites() {
        const res = await fetchJSON('/api/website-builder/my-websites');
        const box = document.getElementById('myWebsitesList');
        if (!res.success || !res.data.websites || !res.data.websites.length) {
            box.innerHTML = `<div class="p-cell-muted" style="padding:8px;font-size:11.5px;">${I18N['wb.no_websites']}</div>`;
            return;
        }
        box.innerHTML = res.data.websites.map(w => `
            <div class="assistant-conv-item">
                <span>${esc(w.business_name)} ${w.status === 'published' ? '✅' : '📝'}</span>
                <a href="/dashboard/sites/${w.id}" class="conv-delete" title="لوحة التحكم">⚙️</a>
                <button class="conv-delete" onclick="editWebsite(${w.id})" title="${I18N['common.edit']}">✏️</button>
            </div>`).join('');
    }

    let currentWebsiteContent = null;

    window.editWebsite = async function (id) {
        editingWebsiteId = id;
        const res = await fetchJSON('/api/website-builder/' + id);
        if (!res.success) return;
        currentWebsiteContent = res.data.content;
        const c = currentWebsiteContent;

        document.getElementById('editBusinessName').value = c.business_name || '';
        document.getElementById('editThemeColor').value = res.data.theme_color || 'gold';
        document.getElementById('editTagline').value = c.tagline || '';
        document.getElementById('editHeroHeadline').value = c.hero_headline || '';
        document.getElementById('editHeroSubtext').value = c.hero_subtext || '';
        document.getElementById('editAboutText').value = c.about_text || '';
        document.getElementById('editCtaText').value = c.cta_text || '';
        document.getElementById('editPhone').value = (c.contact && c.contact.phone) || '';
        document.getElementById('editWhatsapp').value = (c.contact && c.contact.whatsapp) || '';
        document.getElementById('editEmail').value = (c.contact && c.contact.email) || '';
        document.getElementById('editAddress').value = (c.contact && c.contact.address) || '';

        switchManageTab('general');
        renderToursManageList();
        P.openModal('editSiteModal');
    };

    window.switchManageTab = function (tab) {
        document.getElementById('mgTabGeneral').style.display = tab === 'general' ? 'block' : 'none';
        document.getElementById('mgTabTours').style.display = tab === 'tours' ? 'block' : 'none';
        document.getElementById('mgTabGeneralBtn').classList.toggle('active', tab === 'general');
        document.getElementById('mgTabToursBtn').classList.toggle('active', tab === 'tours');
    };

    function getItemsKey() {
        return (currentWebsiteContent && currentWebsiteContent.industry === 'hotel') ? 'rooms' : 'tours';
    }

    function renderToursManageList() {
        const box = document.getElementById('toursManageList');
        const itemsKey = getItemsKey();
        const items = (currentWebsiteContent && currentWebsiteContent[itemsKey]) || [];
        if (!items.length) {
            box.innerHTML = `<div class="p-cell-muted" style="padding:10px 0;">${I18N['wb.no_tours_yet']}</div>`;
            return;
        }
        box.innerHTML = items.map(t => `
            <div class="p-kv">
                <span class="k">${esc(t.name || '')} ${t.price ? '· ' + esc(t.price) : ''}</span>
                <span class="v">
                    <button class="p-btn outline xs" onclick="openEditTour('${t.slug}')">✏️ ${I18N['common.edit']}</button>
                    <button class="p-btn outline xs" onclick="deleteTour('${t.slug}')">🗑️</button>
                </span>
            </div>`).join('');
    }

    window.addNewTour = async function () {
        const desc = document.getElementById('newTourDescription').value.trim();
        if (!desc) return;
        const btn = event.target;
        btn.disabled = true;
        btn.textContent = '⏳ ' + I18N['wb.generating'];

        const res = await fetchJSON('/api/website-builder/' + editingWebsiteId + '/tours', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ description: desc }),
        });

        btn.disabled = false;
        btn.textContent = '✨ ' + I18N['wb.add_tour_button'];

        if (res.success) {
            toast(I18N['wb.tour_added'], 'success');
            document.getElementById('newTourDescription').value = '';
            const itemsKey = getItemsKey();
            currentWebsiteContent[itemsKey] = currentWebsiteContent[itemsKey] || [];
            currentWebsiteContent[itemsKey].push(res.data.tour);
            renderToursManageList();
            loadMyWebsites();
            if (window.refreshPlanUsage) window.refreshPlanUsage();
        } else {
            if (res.data && res.data.shortfall) toast(I18N['wb.insufficient_balance'].replace('{amount}', res.data.shortfall), 'error');
            else toast(res.error || I18N['wb.generate_failed'], 'error');
        }
    };

    window.deleteTour = async function (tourSlug) {
        if (!confirm(I18N['wb.delete_tour_confirm'])) return;
        const res = await fetchJSON('/api/website-builder/' + editingWebsiteId + '/tours/' + tourSlug, { method: 'DELETE' });
        if (res.success) {
            const itemsKey = getItemsKey();
            currentWebsiteContent[itemsKey] = currentWebsiteContent[itemsKey].filter(t => t.slug !== tourSlug);
            renderToursManageList();
            toast(I18N['common.deleted'], 'success');
        } else {
            toast(res.error || I18N['common.update_failed'], 'error');
        }
    };

    window.openEditTour = function (tourSlug) {
        const itemsKey = getItemsKey();
        const item = currentWebsiteContent[itemsKey].find(t => t.slug === tourSlug);
        if (!item) return;
        const newName = prompt(I18N['wb.field.business_name'] + ':', item.name || '');
        if (newName === null) return;
        const newDesc = prompt(I18N['wb.field.tagline'] + ':', item.short_description || '');
        if (newDesc === null) return;
        const newPrice = prompt(I18N['wb.field.price'] + ':', item.price || '');
        if (newPrice === null) return;

        fetchJSON('/api/website-builder/' + editingWebsiteId + '/tours/' + tourSlug, {
            method: 'PUT', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name: newName, short_description: newDesc, price: newPrice }),
        }).then(res => {
            if (res.success) {
                item.name = newName; item.short_description = newDesc; item.price = newPrice;
                renderToursManageList();
                toast(I18N['common.updated'], 'success');
            } else {
                toast(res.error || I18N['common.update_failed'], 'error');
            }
        });
    };

    window.saveSiteEdit = async function () {
        const res = await fetchJSON('/api/website-builder/' + editingWebsiteId, {
            method: 'PUT', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                theme_color: document.getElementById('editThemeColor').value,
                business_name: document.getElementById('editBusinessName').value,
                tagline: document.getElementById('editTagline').value,
                hero_headline: document.getElementById('editHeroHeadline').value,
                hero_subtext: document.getElementById('editHeroSubtext').value,
                about_text: document.getElementById('editAboutText').value,
                cta_text: document.getElementById('editCtaText').value,
                contact_phone: document.getElementById('editPhone').value,
                contact_whatsapp: document.getElementById('editWhatsapp').value,
                contact_email: document.getElementById('editEmail').value,
                contact_address: document.getElementById('editAddress').value,
            }),
        });
        if (res.success) { toast(I18N['common.updated'], 'success'); loadMyWebsites(); }
        else toast(res.error || I18N['common.update_failed'], 'error');
    };

    loadState();
    loadMyWebsites();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('website_builder', $tTitle, $tSubtitle, $body, $script);
        exit;
    }

    /** GET /api/website-builder/state */
    public function getState(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success($this->service->getCurrentState());
    }

    /** POST /api/website-builder/answer */
    public function submitAnswer(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['message' => 'required'])) {
            return $this->error('اكتب إجابة', 422);
        }
        return $this->success($this->service->submitAnswer((string) $this->get('message')));
    }

    /** POST /api/website-builder/reset */
    public function resetWizard(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $this->service->resetWizard();
        return $this->success([]);
    }

    /** POST /api/website-builder/generate */
    public function generateSite(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->service->generateSite((int) $this->user['id']);
        if (!$result['success']) {
            return $this->error($result['error'], 402, $result);
        }
        return $this->success($result);
    }

    /** GET /api/website-builder/my-websites */
    public function myWebsites(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $websites = (new GeneratedWebsite())->where(['user_id' => (int) $this->user['id']], ['created_at' => 'DESC']);
            $result = array_map(function ($w) {
                $content = $w->getContent();
                return ['id' => $w->getAttribute('id'), 'slug' => $w->getAttribute('slug'), 'status' => $w->getAttribute('status'), 'business_name' => $content['business_name'] ?? $w->getAttribute('slug')];
            }, $websites);
            return $this->success(['websites' => $result]);
        } catch (Exception $e) {
            Logger::error('myWebsites Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر الجلب', 500);
        }
    }

    /** GET /api/website-builder/{id} */
    public function getWebsite(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }
        return $this->success(['content' => $website->getContent(), 'theme_color' => $website->getAttribute('theme_color')]);
    }

    /** PUT /api/website-builder/{id} */
    public function updateWebsite(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }

        $content = $website->getContent();
        if ($this->get('theme_color') !== null && array_key_exists((string) $this->get('theme_color'), ['gold' => 1, 'blue' => 1, 'green' => 1, 'red' => 1, 'purple' => 1])) {
            $website->setAttribute('theme_color', (string) $this->get('theme_color'));
        }
        $textFields = ['business_name', 'tagline', 'hero_headline', 'hero_subtext', 'about_title', 'about_text', 'cta_text'];
        foreach ($textFields as $field) {
            if ($this->get($field) !== null) {
                $content[$field] = (string) $this->get($field);
            }
        }
        $contactFields = ['phone', 'whatsapp', 'email', 'address'];
        foreach ($contactFields as $field) {
            if ($this->get('contact_' . $field) !== null) {
                $content['contact'][$field] = (string) $this->get('contact_' . $field);
            }
        }

        $website->setAttribute('content_json', json_encode($content, JSON_UNESCAPED_UNICODE));
        $website->save();

        return $this->success(['content' => $content], 'تم الحفظ');
    }

    /** POST /api/website-builder/{id}/tours - إضافة رحلة جديدة بالذكاء الاصطناعي من وصف مختصر */
    /** POST /api/website-builder/{id}/tours - إضافة عنصر جديد (رحلة أو غرفة حسب مجال الموقع) */
    public function addTour(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }

        if (!$this->validate(['description' => 'required'])) {
            return $this->error('اكتب وصف مختصر الأول', 422);
        }

        $content = $website->getContent();
        $industry = $content['industry'] ?? 'tours';
        $itemsKey = $industry === 'hotel' ? 'rooms' : 'tours';
        $existingSlugs = array_column($content[$itemsKey] ?? [], 'slug');

        $result = $this->service->generateSingleItem(
            (int) $this->user['id'],
            $industry,
            $content['service_type'] ?? '',
            $content['language'] ?? 'ar',
            (string) $this->get('description'),
            $existingSlugs
        );

        if (!$result['success']) {
            return $this->error($result['error'], 402, $result);
        }

        $item = $result['item'];

        // نولّد صورة للعنصر الجديد كمان
        $uploadsDir = dirname(__DIR__, 2) . '/public_html/uploads/generated-sites/' . $this->user['id'] . '-' . $website->getAttribute('id');
        @mkdir($uploadsDir, 0755, true);
        $item['image_url'] = $this->service->generateTourImage($item, $content['service_type'] ?? '', $uploadsDir, $item['slug']);

        $content[$itemsKey][] = $item;
        $website->setAttribute('content_json', json_encode($content, JSON_UNESCAPED_UNICODE));
        $website->save();

        $this->syncTourToProduct($website, $item);

        return $this->success(['tour' => $item, 'items_key' => $itemsKey], 'تمت الإضافة', 201);
    }

    /** PUT /api/website-builder/{id}/tours/{tourSlug} - تعديل عنصر موجود (رحلة أو غرفة) */
    public function updateTour(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }

        $itemSlug = (string) ($params['tourSlug'] ?? '');
        $content = $website->getContent();
        $industry = $content['industry'] ?? 'tours';
        $itemsKey = $industry === 'hotel' ? 'rooms' : 'tours';
        $found = false;

        // الحقول المشتركة بين المجالين + حقول خاصة بكل مجال
        $sharedFields = ['name', 'short_description', 'price'];
        $industryFields = $industry === 'hotel' ? ['capacity', 'size'] : ['duration', 'group_size'];

        foreach ($content[$itemsKey] ?? [] as &$item) {
            if (($item['slug'] ?? '') === $itemSlug) {
                $found = true;
                foreach (array_merge($sharedFields, $industryFields) as $field) {
                    if ($this->get($field) !== null) {
                        $item[$field] = (string) $this->get($field);
                    }
                }
                foreach (['highlights' => 'highlights_text', 'includes' => 'includes_text', 'excludes' => 'excludes_text', 'amenities' => 'amenities_text'] as $key => $inputField) {
                    if ($this->get($inputField) !== null) {
                        $lines = array_filter(array_map('trim', explode("\n", (string) $this->get($inputField))));
                        $item[$key] = array_values($lines);
                    }
                }
                break;
            }
        }
        unset($item);

        if (!$found) {
            return $this->error('العنصر ده مش موجود', 404);
        }

        $website->setAttribute('content_json', json_encode($content, JSON_UNESCAPED_UNICODE));
        $website->save();

        foreach ($content[$itemsKey] ?? [] as $it) {
            if (($it['slug'] ?? '') === $itemSlug) {
                $this->syncTourToProduct($website, $it);
                break;
            }
        }

        return $this->success([], 'تم التحديث');
    }

    /** DELETE /api/website-builder/{id}/tours/{tourSlug} */
    public function deleteTour(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }

        $itemSlug = (string) ($params['tourSlug'] ?? '');
        $content = $website->getContent();
        $industry = $content['industry'] ?? 'tours';
        $itemsKey = $industry === 'hotel' ? 'rooms' : 'tours';
        $content[$itemsKey] = array_values(array_filter($content[$itemsKey] ?? [], fn ($t) => ($t['slug'] ?? '') !== $itemSlug));

        $website->setAttribute('content_json', json_encode($content, JSON_UNESCAPED_UNICODE));
        $website->save();

        $this->deactivateLinkedProduct($website, $itemSlug);

        return $this->success([], 'تم الحذف');
    }

    /** POST /api/website-builder/{id}/publish */

    public function publish(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }

        $website->setAttribute('status', 'published');
        $website->setAttribute('last_published_at', date('Y-m-d H:i:s'));
        $website->save();

        $this->syncAllSiteItems($website);

        return $this->success([], 'تم النشر');
    }

    /** POST /sites/{slug}/lead - طلب حجز/تواصل من زائر الموقع المنشور (بدون تسجيل دخول) */
    public function submitLead(array $params = []): array
    {
        $website = $this->findViewableWebsite((string) ($params['slug'] ?? ''));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }
        if ($guard = $this->siteFormGuard('lead', 8, 600, 'تم إرسال طلبك، هيتم التواصل معاك قريبًا')) {
            return $guard;
        }
        if (!$this->validate(['visitor_name' => 'required'])) {
            return $this->error('اكتب اسمك من فضلك', 422);
        }
        $leadEmail = trim((string) $this->get('email', ''));
        if ($leadEmail !== '' && !filter_var($leadEmail, FILTER_VALIDATE_EMAIL)) {
            return $this->error('الإيميل مش صحيح', 422);
        }

        $lead = new WebsiteLead();
        $lead->setAttribute('website_id', (int) $website->getAttribute('id'));
        $lead->setAttribute('item_id', (string) $this->get('item_id', ''));
        $lead->setAttribute('visitor_name', mb_substr((string) $this->get('visitor_name'), 0, 120));
        $lead->setAttribute('phone', mb_substr((string) $this->get('phone', ''), 0, 40));
        $lead->setAttribute('email', mb_substr($leadEmail, 0, 190));
        $lead->setAttribute('message', mb_substr((string) $this->get('message', ''), 0, 3000));
        $lead->setAttribute('status', 'new');
        $lead->save();

        return $this->success([], 'تم إرسال طلبك، هيتم التواصل معاك قريبًا', 201);
    }

    /**
     * 🔒 مضاد سبام لنماذج الموقع العام (حجز/تواصل/تقييم):
     *  - حقل مخفي (honeypot) البوتات بتملاه والبني آدم مش شايفه
     *  - وقت عرض النموذج موقّع: لو اتبعت في أقل من ثانيتين يبقى بوت
     *  - حد للطلبات لكل IP
     * البوت بياخد "تم" مزيفة عشان مايعرفش إنه اتمسك.
     */
    private function spamTrapHtml(): string
    {
        $t = (string) time();
        $sig = substr(hash_hmac('sha256', $t, $this->spamKey()), 0, 16);
        return '<div aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;">'
            . '<label>Website<input type="text" name="hp_website" tabindex="-1" autocomplete="off" value=""></label></div>'
            . '<input type="hidden" name="hp_t" value="' . $t . '.' . $sig . '">';
    }

    private function spamKey(): string
    {
        $k = function_exists('env') ? (string) (env('APP_KEY') ?: '') : '';
        return $k !== '' ? $k : (defined('APP_KEY') ? (string) APP_KEY : 'tourfecto-site-forms');
    }

    /** @return array|null رد جاهز لو الطلب سبام/كتير، أو null لو تمام */
    private function siteFormGuard(string $scope, int $max, int $window, string $fakeMessage): ?array
    {
        if (($rl = $this->rateLimitGuard('ip', 'site_' . $scope, $max, $window)) !== null) {
            return $rl;
        }
        $isBot = trim((string) $this->get('hp_website', '')) !== '';
        $stamp = (string) $this->get('hp_t', '');
        if (!$isBot && $stamp !== '') {
            [$t, $sig] = array_pad(explode('.', $stamp, 2), 2, '');
            $valid = ctype_digit($t) && hash_equals(substr(hash_hmac('sha256', $t, $this->spamKey()), 0, 16), $sig);
            // توقيع غلط أو اتبعت أسرع من ثانيتين من عرض الصفحة
            $isBot = !$valid || (time() - (int) $t) < 2;
        }
        if ($isBot) {
            Logger::info('Site form spam blocked', ['scope' => $scope, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
            return $this->success([], $fakeMessage, 201);
        }
        return null;
    }

    /** POST /sites/{slug}/review - تقييم من زائر الموقع المنشور (بدون تسجيل دخول، يحتاج اعتماد صاحب الموقع) */
    public function submitReview(array $params = []): array
    {
        $website = $this->findViewableWebsite((string) ($params['slug'] ?? ''));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }
        if ($guard = $this->siteFormGuard('review', 5, 600, 'شكرًا لتقييمك! هيظهر بعد مراجعة صاحب الموقع')) {
            return $guard;
        }
        if (!$this->validate(['visitor_name' => 'required', 'rating' => 'required'])) {
            return $this->error('اكتب اسمك وتقييمك من فضلك', 422);
        }
        $rating = (int) $this->get('rating');
        if ($rating < 1 || $rating > 5) {
            return $this->error('التقييم لازم يكون من 1 لـ 5', 422);
        }

        $review = new WebsiteReview();
        $review->setAttribute('website_id', (int) $website->getAttribute('id'));
        $review->setAttribute('item_id', (string) $this->get('item_id', ''));
        $review->setAttribute('visitor_name', mb_substr((string) $this->get('visitor_name'), 0, 120));
        $review->setAttribute('rating', $rating);
        $review->setAttribute('comment', mb_substr((string) $this->get('comment', ''), 0, 2000));
        $review->setAttribute('status', 'pending');
        $review->save();

        return $this->success([], 'شكرًا لتقييمك! هيظهر بعد مراجعة صاحب الموقع', 201);
    }

    /**
     * GET /sites/{slug} - الصفحة العامة للموقع المولّد (بدون تسجيل دخول)
     * قالب سياحي احترافي ثابت مبني على محتوى الـ JSON المولّد.
     */
    /**
     * دالة مشتركة: تجيب الموقع بالـ slug، وتتأكد من صلاحية العرض
     * (منشور = عام، مسوّدة = لصاحبها بس).
     */
    private function findViewableWebsite(string $slug): ?GeneratedWebsite
    {
        $rows = (new GeneratedWebsite())->where(['slug' => $slug], [], 1);
        if (empty($rows)) {
            return null;
        }
        $website = $rows[0];
        if ($website->getAttribute('status') !== 'published') {
            $isOwner = $this->isAuthenticated() && (int) $website->getAttribute('user_id') === (int) $this->user['id'];
            if (!$isOwner) {
                return null;
            }
        }
        return $website;
    }

    /** ألوان جاهزة لكل ثيم - كل موقع يقدر يختار واحد منهم فيبقى شكله مختلف فعليًا */
    private const THEMES = [
        'gold' => ['accent' => '#efb05e', 'accent_rgb' => '239,176,94'],
        'blue' => ['accent' => '#5b9bd5', 'accent_rgb' => '91,155,213'],
        'green' => ['accent' => '#4caf82', 'accent_rgb' => '76,175,130'],
        'red' => ['accent' => '#e0685c', 'accent_rgb' => '224,104,92'],
        'purple' => ['accent' => '#a084e8', 'accent_rgb' => '160,132,232'],
    ];

    /**
     * Phase 5 (Auto-Apply): بقت بتاخد description/canonical/ogImage اختياريين
     * (Backward-compatible - القيمة الافتراضية null يعني نفس السلوك القديم
     * بالظبط لو حد نادى الدالة من غير الباراميترات الجداد).
     * القيم دي هي بالظبط اللي كان الـWebsite Optimizer بيكتشف غيابها كمشكلة
     * (meta_description/canonical/open_graph) على أي موقع تاني - دلوقتي
     * مواقع الـWebsite Builder نفسها ماعادتش ناقصاها.
     */
    private function siteHeadHtml(string $title, string $themeKey = 'gold', ?string $description = null, ?string $canonicalUrl = null, ?string $ogImage = null, string $jsonLd = ''): string
    {
        $theme = self::THEMES[$themeKey] ?? self::THEMES['gold'];
        $accent = $theme['accent'];
        $accentRgb = $theme['accent_rgb'];
        $esc = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

        $metaDescription = $description ? "\n    <meta name=\"description\" content=\"" . $esc($description) . "\">" : '';
        $canonicalTag = $canonicalUrl ? "\n    <link rel=\"canonical\" href=\"" . $esc($canonicalUrl) . "\">" : '';
        $ogTags = '';
        if ($description || $canonicalUrl) {
            $ogTitle = $esc($title);
            $ogDesc = $esc($description ?: $title);
            $ogUrlTag = $canonicalUrl ? "\n    <meta property=\"og:url\" content=\"" . $esc($canonicalUrl) . "\">" : '';
            $ogImageTag = $ogImage ? "\n    <meta property=\"og:image\" content=\"" . $esc($ogImage) . "\">" : '';
            $ogTags = "\n    <meta property=\"og:title\" content=\"{$ogTitle}\">\n    <meta property=\"og:description\" content=\"{$ogDesc}\">\n    <meta property=\"og:type\" content=\"website\">{$ogUrlTag}{$ogImageTag}";
        }

        return <<<HTML
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>{$metaDescription}{$canonicalTag}{$ogTags}
    {$jsonLd}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/generated-site.css">
    <style>:root{--ws-accent:{$accent};--ws-accent-rgb:{$accentRgb};}</style>
HTML;
    }

    /** لغة واتجاه الصفحة بناءً على اللغة اللي اختارها العميل وقت الإنشاء */
    private string $translationLanguage='';
    private function internationalContent(GeneratedWebsite $website,array $params): array
    {
        $source=$website->getContent();$service=new BuilderLanguageService($this->db);
        $approved=$service->approved((int)$website->getAttribute('id'),$source);
        $language=(string)($params['language']??'');
        $base=$this->publicSiteUrl((string)$params['slug'],$website);
        $suffix=isset($params['tourSlug'])?'/tours/'.rawurlencode($params['tourSlug']):(isset($params['roomSlug'])?'/rooms/'.rawurlencode($params['roomSlug']):'');
        $content=$source;
        if($language!==''){
            $match=null;foreach($approved as $r)if($r['language']===$language)$match=$r;
            if(!$match && $language!==BuilderLanguageService::sourceLanguage($source)){http_response_code(404);echo 'Reviewed language version unavailable';exit;}
            $content=$match?BuilderLanguageService::apply($source,json_decode($match['fields_json'],true,64,JSON_THROW_ON_ERROR),$language):$source;
            $this->translationLanguage=$language;
        }
        $this->reviewedInterface=$content['_reviewed_interface']??[];
        $alternates=BuilderLanguageService::alternates($base,$suffix,$source,$approved);
        $tags=HreflangService::html($alternates);
        $switcher='';foreach($alternates as $code=>$url)if($code!=='x-default')$switcher.='<a href="'.htmlspecialchars($url,ENT_QUOTES,'UTF-8').'" lang="'.htmlspecialchars($code,ENT_QUOTES,'UTF-8').'" style="padding:8px">'.strtoupper(htmlspecialchars($code,ENT_QUOTES,'UTF-8')).'</a>';
        if($switcher!=='')$switcher='<nav aria-label="Languages" style="display:flex;justify-content:center;gap:8px">'.$switcher.'</nav>';
        $sitePath='/sites/'.rawurlencode((string)$params['slug']);
        // Keep booking/action URLs unchanged. Localize navigation links only.
        ob_start(static function(string $html)use($tags,$switcher,$language,$base,$sitePath,$suffix):string {
            if($tags!=='')$html=preg_replace('~</head>~i',$tags.'</head>',$html,1);
            if($switcher!=='')$html=preg_replace_callback('~<body\b[^>]*>~i',static fn($m)=>$m[0].$switcher,$html,1);
            if($language!==''){
                $html=preg_replace_callback('~(<a\b[^>]*\bhref=["\'])([^"\']+)(["\'])~i',static function($m)use($base,$language,$sitePath){
                    $u=$m[2];foreach([$base,$sitePath] as $prefix)if($u===$prefix||str_starts_with($u,$prefix.'/')||str_starts_with($u,$prefix.'#')){
                        $tail=substr($u,strlen($prefix));
                        if($tail===''||str_starts_with($tail,'#')||preg_match('~^/(?:tours|rooms)/[^/?#]+(?:#[^?]*)?$~',$tail))return $m[1].$prefix.'/'.$language.$tail.$m[3];
                    }return $m[0];
                },$html);
                // Room templates historically omit a canonical; supply the reviewed URL.
                if(!preg_match('~<link\b[^>]*rel=["\']canonical["\']~i',$html))$html=str_ireplace('</head>','<link rel="canonical" href="'.htmlspecialchars(rtrim($base,'/').'/'.$language.$suffix,ENT_QUOTES,'UTF-8').'">'.'</head>',$html);
            }return $html;
        });
        return $content;
    }
    private string $publicSiteLanguage='en';
    private array $reviewedInterface=[];
    private function siteUi(string $text): string {
        if($this->reviewedInterface){$safe=array_map(static fn($v)=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),$this->reviewedInterface);return $safe[$text]??strtr($text,$safe);}
        return SiteInterfaceLanguage::text($text,$this->publicSiteLanguage);
    }

    private function siteJs(string $text): string {
        $raw=$this->reviewedInterface?($this->reviewedInterface[$text]??strtr($text,$this->reviewedInterface)):SiteInterfaceLanguage::text($text,$this->publicSiteLanguage);
        return substr(json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR),1,-1);
    }

    private function siteLangAttrs(array $content): array
    {
        $lang = strtolower((string) ($content['language'] ?? 'en'));
        if (!HreflangService::language($lang)) $lang='en';
        $this->publicSiteLanguage=$lang;
        return [$lang, in_array(explode('-', $lang)[0], ['ar','he','fa','ur'], true) ? 'rtl' : 'ltr'];
    }

    /**
     * الثيم والـ layout الفعليين للموقع من إعداداته المحفوظة.
     * إصلاح: كان التخزين شغال (theme_color/template_id بيتحفظوا) لكن العرض
     * كان دايمًا 'gold' + قالب واحد ثابت - دلوقتي إعدادات العميل بتأثر فعلًا.
     */
    private function siteDesignAttrs(GeneratedWebsite $website): array
    {
        $theme = (string) $website->getAttribute('theme_color');
        if (!isset(self::THEMES[$theme])) {
            $theme = 'gold';
        }

        $layoutKey = '';
        $templateId = (int) $website->getAttribute('template_id');
        if ($templateId > 0) {
            try {
                $template = (new WebsiteTemplate())->find($templateId);
                if ($template && (string) $template->getAttribute('layout_key') !== '') {
                    $layoutKey = (string) $template->getAttribute('layout_key');
                }
            } catch (Exception $e) { /* التصميم الثابت يظل متاحًا */
            }
        }
        if ($layoutKey === '') {
            $industry = $website->getContent()['industry'] ?? 'tours';
            $layoutKey = $industry === 'hotel' ? 'render_hotel_classic' : 'render_tours_classic';
        }

        $bodyClass = 'ws-layout-classic';
        if (strpos($layoutKey, 'boutique') !== false) {
            $bodyClass = 'ws-layout-boutique';
        } elseif (strpos($layoutKey, 'luxury') !== false || strpos($layoutKey, 'pro') !== false) {
            $bodyClass = 'ws-layout-luxury';
        }

        return ['theme' => $theme, 'layout_key' => $layoutKey, 'body_class' => $bodyClass];
    }

    /**
     * قسم تقييمات الزوار على الموقع المنشور: المراجعات المعتمدة بس (من
     * website_reviews اللي كان بيتجمع من قبل من غير عرض) + نموذج تقييم جديد.
     * الـ form بيبعت لـ POST /sites/{slug}/review (موجود أصلًا).
     */
    private function siteReviewsSectionHtml(int $websiteId, string $slug, ?string $itemId = null): string
    {
        $esc = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

        $reviews = (new WebsiteReview())->approvedFor($websiteId, $itemId);

        $sum = 0;
        $cards = '';
        foreach ($reviews as $r) {
            $rating = max(1, min(5, (int) $r->getAttribute('rating')));
            $sum += $rating;
            $stars = str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
            $comment = (string) $r->getAttribute('comment');
            $commentHtml = $comment !== '' ? '<p class="ws-review-comment">' . $esc($comment) . '</p>' : '';
            $cards .= '<div class="ws-review-card">'
                . '<div class="ws-review-head"><strong>' . $esc($r->getAttribute('visitor_name')) . '</strong>'
                . '<span class="ws-review-stars">' . $stars . '</span></div>'
                . $commentHtml
                . '<span class="ws-review-date">' . $esc(date('Y-m-d', strtotime((string) $r->getAttribute('created_at')))) . '</span>'
                . '</div>';
        }

        $count = count($reviews);
        $avg = $count > 0 ? round($sum / $count, 1) : 0.0;
        $summaryHtml = $count > 0
            ? '<span class="ws-review-big">★ ' . number_format($avg, 1) . '</span>'
                . $this->siteUi('<span class="ws-review-count">بناءً على ') . $count . $this->siteUi(' تقييم') . ($count > 1 ? $this->siteUi('ات') : '') . '</span>'
            : $this->siteUi('<p class="ws-reviews-empty">لا توجد تقييمات بعد - كن أول من يقيّم!</p>');

        $itemField = $itemId !== null
            ? '<input type="hidden" name="item_id" value="' . $esc($itemId) . '">'
            : '';

        $form = <<<HTML
        <form class="ws-review-form" data-action="/sites/{$esc($slug)}/review">
            {$this->spamTrapHtml()}
            <h3 class="ws-review-form-title">{$this->siteUi('أضف تقييمك')}</h3>
            {$itemField}
            <div class="ws-review-cols">
                <label class="ws-bf-label">{$this->siteUi('اسمك')}
                    <input type="text" name="visitor_name" required class="ws-bf-input" maxlength="120">
                </label>
                <label class="ws-bf-label">{$this->siteUi('تقييمك')}
                    <select name="rating" class="ws-bf-input">
                        <option value="5">5 - {$this->siteUi('ممتاز')}</option>
                        <option value="4">4 - {$this->siteUi('جيد جدًا')}</option>
                        <option value="3">3 - {$this->siteUi('جيد')}</option>
                        <option value="2">2 - {$this->siteUi('مقبول')}</option>
                        <option value="1">1 - {$this->siteUi('ضعيف')}</option>
                    </select>
                </label>
            </div>
            <label class="ws-bf-label">{$this->siteUi('تعليقك')}
                <textarea name="comment" rows="3" maxlength="1000" class="ws-bf-input"></textarea>
            </label>
            <button type="submit" class="ws-btn">{$this->siteUi('إرسال التقييم')}</button>
            <div class="ws-booking-msg" role="status"></div>
        </form>
        <script>
        (function () {
            var f = document.querySelector('.ws-review-form');
            if (!f) return;
            var msg = f.querySelector('.ws-booking-msg');
            f.addEventListener('submit', function (e) {
                e.preventDefault();
                msg.className = 'ws-booking-msg';
                msg.textContent = '{$this->siteJs('جاري إرسال التقييم')}...';
                var payload = {};
                new FormData(f).forEach(function (value, key) { payload[key] = value; });
                fetch(f.getAttribute('data-action'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify(payload)
                }).then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                .then(function (res) {
                    if (res.success) {
                        msg.className = 'ws-booking-msg ws-ok';
                        msg.textContent = '{$this->siteJs('شكرًا لتقييمك')}! {$this->siteJs('هيظهر بعد مراجعة صاحب الموقع')}';
                        f.querySelector('textarea[name="comment"]').value = '';
                    } else {
                        msg.className = 'ws-booking-msg ws-error';
                        msg.textContent = res.error || '{$this->siteJs('حصل خطأ، جرب تاني')}';
                    }
                }).catch(function () {
                    msg.className = 'ws-booking-msg ws-error';
                    msg.textContent = '{$this->siteJs('حصل خطأ، جرب تاني')}';
                });
            });
        })();
        </script>
HTML;

        return <<<HTML
    <section class="ws-section ws-section-alt ws-reviews" id="reviews">
        <h2>{$this->siteUi('تقييمات العملاء')}</h2>
        <div class="ws-reviews-summary">{$summaryHtml}</div>
        <div class="ws-reviews-list">{$cards}</div>
        {$form}
    </section>
HTML;
    }

    /** GET /sites/{slug} - الصفحة الرئيسية: بتتفرّع حسب المجال (رحلات أو فندق) */
    public function showPublicSite(array $params = []): array
    {
        $slug = (string) ($params['slug'] ?? '');
        $website = $this->findViewableWebsite($slug);

        if (!$website) {
            header('HTTP/1.1 404 Not Found');
            echo $this->siteUi('<h1 style="text-align:center;font-family:sans-serif;margin-top:100px;">الموقع ده مش موجود أو لسه مش منشور</h1>');
            exit;
        }

        // عدّاد زيارات بسيط - يفيد لوحة التحكم (نتجاهل فشله عشان ميكسرش عرض الصفحة)
        try {
            $website->setAttribute('views_count', (int) $website->getAttribute('views_count') + 1);
            $website->save();
        } catch (Exception $e) { /* لا شيء - العرض أهم من العداد */
        }

        WhatsAppStudioService::captureQuery((int)$website->getAttribute('id'),(int)$website->getAttribute('user_id'));
        EmailPlatformBridge::captureQuery((int)$website->getAttribute('id'));
        $c = $this->internationalContent($website,$params);
        $industry = $c['industry'] ?? 'tours';

        // Phase 5 (Auto-Apply): بنمرر seo_title/seo_description الفعليين من
        // الموديل (اللي دلوقتي ممكن يتحدّثوا تلقائيًا من الـWebsite Optimizer)
        // بدل ما الـhead يعتمد بس على business_name/tagline زي الأول.
        $seoTitle = htmlspecialchars((string) (($this->translationLanguage!=='' ? ($c['hero_headline']??$c['business_name']??'') : $website->getAttribute('seo_title')) ?? ''), ENT_QUOTES, 'UTF-8');
        $seoDescription = htmlspecialchars((string) (($this->translationLanguage!=='' ? ($c['hero_subtext']??$c['tagline']??'') : $website->getAttribute('seo_description')) ?? ''), ENT_QUOTES, 'UTF-8');
        $canonicalUrl = $this->publicSiteUrl($slug, $website);
        $ogImage = (string) ($website->getAttribute('logo_url') ?? '');

        // الثيم والـ layout الفعليين من إعدادات الموقع المحفوظة (كانوا
        // بيتحفظوا من غير ما يأثروا على العرض الفعلي).
        $design = $this->siteDesignAttrs($website);

        if ($industry === 'hotel') {
            $page = $this->renderHotelHome($slug, $c, $seoTitle, $seoDescription, $canonicalUrl, $ogImage, $design['theme'], $design['body_class'], (int) $website->getAttribute('id'));
        } else {
            $page = $this->renderToursHome($slug, $c, $seoTitle, $seoDescription, $canonicalUrl, $ogImage, $design['theme'], $design['body_class'], (int) $website->getAttribute('id'));
        }
        if ($this->translationLanguage==='' && !empty($c['competitor_seo_content'])) $page=CompetitorSiteExecutionService::rewrite($page,$c['competitor_seo_content']);
        echo $page;
        exit;
    }

    /**
     * رابط الموقع المنشور الفعلي - يستخدم في canonical/OG (Phase 5 Auto-Apply).
     * لو الطلب الحالي جاي على دومين مخصص مربوط بالموقع، بيرجع رابط الدومين ده.
     */
    private function publicSiteUrl(string $slug, ?GeneratedWebsite $website = null): string
    {
        if ($website !== null) {
            $customDomain = strtolower(trim((string) $website->getAttribute('custom_domain')));
            if ($customDomain !== '') {
                $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
                $host = preg_replace('/:\d+$/', '', $host);
                $host = preg_replace('/^www\./', '', $host);
                $domain = preg_replace('/^www\./', '', $customDomain);
                if ($host !== '' && $host === $domain && CustomDomainService::isVerified((int)$website->getAttribute('id'),$customDomain)) {
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    return $scheme . '://' . $customDomain . ($this->translationLanguage!==''?'/'.$this->translationLanguage:'');
                }
            }
        }
        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
        return $base . '/sites/' . rawurlencode($slug) . ($this->translationLanguage!==''?'/'.$this->translationLanguage:'');
    }

    private function renderToursHome(string $slug, array $c, string $seoTitle = '', string $seoDescription = '', string $canonicalUrl = '', string $ogImage = '', string $themeKey = 'gold', string $bodyClass = '', int $websiteId = 0): string
    {
        $esc = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        [$lang, $dir] = $this->siteLangAttrs($c);

        $toursHtml = '';
        foreach (($c['tours'] ?? []) as $t) {
            $priceLine = !empty($t['price']) ? '<div class="ws-package-price">' . $esc($t['price']) . '</div>' : '';
            $durationLine = !empty($t['duration']) ? '<div class="ws-package-duration">' . $esc($t['duration']) . '</div>' : '';
            $imageHtml = !empty($t['image_url'])
                ? '<div class="ws-package-img" style="background-image:url(\'' . $esc($t['image_url']) . '\');"></div>'
                : '<div class="ws-package-img ws-package-img-fallback">🏝️</div>';
            $toursHtml .= '<a href="/sites/' . $esc($slug) . '/tours/' . $esc($t['slug'] ?? '') . '" class="ws-package ws-package-link">'
                . $imageHtml
                . '<div class="ws-package-body"><h3>' . $esc($t['name'] ?? '') . '</h3><p>' . $esc($t['short_description'] ?? '') . '</p>'
                . $priceLine . $durationLine . $this->siteUi('<span class="ws-view-more">شوف التفاصيل ←</span></div></a>');
        }

        $contact = $c['contact'] ?? [];
        $whatsappLink = !empty($contact['whatsapp']) ? 'https://wa.me/' . preg_replace('/[^0-9]/', '', $contact['whatsapp']) : '#';

        $businessName = $esc($c['business_name'] ?? '');
        $tagline = $esc($c['tagline'] ?? '');
        $heroHeadline = $esc($c['hero_headline'] ?? '');
        $heroSubtext = $esc($c['hero_subtext'] ?? '');
        $aboutTitle = $esc($c['about_title'] ?? '');
        $aboutText = $esc($c['about_text'] ?? '');
        $ctaText = $esc($c['cta_text'] ?? '');
        $phone = $esc($contact['phone'] ?? '');
        $email = $esc($contact['email'] ?? '');
        $address = $esc($contact['address'] ?? '');
        $head = $this->siteHeadHtml(
            $seoTitle !== '' ? $seoTitle : "{$businessName} | {$tagline}",
            $themeKey,
            $seoDescription !== '' ? $seoDescription : ($c['about_text'] ?? null),
            $canonicalUrl !== '' ? $canonicalUrl : null,
            $ogImage !== '' ? $ogImage : null,
            $this->siteSchemaTag('home', $c, $canonicalUrl, $websiteId)
        );

        $reviewsHtml = $websiteId > 0 ? $this->siteReviewsSectionHtml($websiteId, $slug) : '';

        $hasBookingProducts = false;
        if ($websiteId > 0) {
            $hasBookingProducts = !empty($this->db->query(
                'SELECT id FROM crm_products WHERE website_id = ? AND is_active = 1 LIMIT 1',
                [$websiteId]
            ));
        }

        $leadFormHtml = $this->siteLeadFormHtml($slug, $hasBookingProducts, 'tours');
        $bookCta = $hasBookingProducts
            ? $this->siteUi('<a href="#tours" class="ws-btn ws-btn-outline" style="text-decoration:none;">احجز الآن</a>')
            : '';

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}" dir="{$dir}">
<head>
{$head}
</head>
<body class="{$bodyClass}">
    <nav class="ws-nav">
        <div class="ws-nav-inner">
            <span class="ws-logo">🌍 {$businessName}</span>
            <div class="ws-nav-links">
                <a href="#about">{$this->siteUi('عننا')}</a>
                <a href="#tours">{$this->siteUi('رحلاتنا')}</a>
                <a href="#reviews">{$this->siteUi('تقييماتنا')}</a>
                <a href="#lead">{$this->siteUi('تواصل معنا')}</a>
                <a href="{$whatsappLink}" target="_blank" class="ws-nav-cta">{$this->siteUi('احجز الآن')}</a>
            </div>
        </div>
    </nav>

    <header class="ws-hero">
        <h1>{$heroHeadline}</h1>
        <p>{$heroSubtext}</p>
        <a href="{$whatsappLink}" target="_blank" class="ws-btn">{$ctaText}</a>
        {$bookCta}
    </header>

    <section class="ws-section" id="about">
        <h2>{$aboutTitle}</h2>
        <p class="ws-about-text">{$aboutText}</p>
    </section>

    <section class="ws-section ws-section-alt" id="tours">
        <h2>{$this->siteUi('رحلاتنا وبرامجنا')}</h2>
        <div class="ws-grid">{$toursHtml}</div>
    </section>

    <section class="ws-section">
        <h2>{$this->siteUi('ليه تحجز معانا')}</h2>
        <div class="ws-trust-grid">
            <div class="ws-trust-item"><div class="ws-trust-icon">🧭</div><h3>{$this->siteUi('خبرة محلية حقيقية')}</h3><p>{$this->siteUi('برامج مصمّمة من ناس عارفة المنطقة كويس')}</p></div>
            <div class="ws-trust-item"><div class="ws-trust-icon">💬</div><h3>{$this->siteUi('تواصل مباشر وسريع')}</h3><p>{$this->siteUi('رد فوري على أي استفسار عن طريق واتساب')}</p></div>
            <div class="ws-trust-item"><div class="ws-trust-icon">✅</div><h3>{$this->siteUi('تفاصيل واضحة من الأول')}</h3><p>{$this->siteUi('تعرف بالظبط إيه شامل وإيه لأ قبل ما تحجز')}</p></div>
        </div>
    </section>

    {$reviewsHtml}

    {$leadFormHtml}

    <section class="ws-section ws-section-alt ws-contact" id="contact">
        <h2>{$this->siteUi('تواصل معنا')}</h2>
        <p>📞 {$phone}</p>
        <p>✉️ {$email}</p>
        <p>📍 {$address}</p>
        <a href="{$whatsappLink}" target="_blank" class="ws-btn">{$this->siteUi('تواصل عبر واتساب')}</a>
    </section>

    <footer class="ws-footer">© {$businessName} - {$this->siteUi('صُمم بواسطة')} Tourfecto</footer>
</body>
</html>
HTML;
    }

    private function renderHotelHome(string $slug, array $c, string $seoTitle = '', string $seoDescription = '', string $canonicalUrl = '', string $ogImage = '', string $themeKey = 'gold', string $bodyClass = '', int $websiteId = 0): string
    {
        $esc = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        [$lang, $dir] = $this->siteLangAttrs($c);

        $roomsHtml = '';
        foreach (($c['rooms'] ?? []) as $r) {
            $priceLine = !empty($r['price']) ? '<div class="ws-package-price">' . $esc($r['price']) . '</div>' : '';
            $capacityLine = !empty($r['capacity']) ? '<div class="ws-package-duration">' . $esc($r['capacity']) . '</div>' : '';
            $imageHtml = !empty($r['image_url'])
                ? '<div class="ws-package-img" style="background-image:url(\'' . $esc($r['image_url']) . '\');"></div>'
                : '<div class="ws-package-img ws-package-img-fallback">🛏️</div>';
            $roomsHtml .= '<a href="/sites/' . $esc($slug) . '/rooms/' . $esc($r['slug'] ?? '') . '" class="ws-package ws-package-link">'
                . $imageHtml
                . '<div class="ws-package-body"><h3>' . $esc($r['name'] ?? '') . '</h3><p>' . $esc($r['short_description'] ?? '') . '</p>'
                . $priceLine . $capacityLine . $this->siteUi('<span class="ws-view-more">شوف التفاصيل ←</span></div></a>');
        }

        $amenitiesHtml = '';
        foreach (($c['hotel_amenities'] ?? []) as $a) {
            $amenitiesHtml .= '<div class="ws-trust-item"><div class="ws-trust-icon">✔</div><p>' . $esc($a) . '</p></div>';
        }

        $contact = $c['contact'] ?? [];
        $whatsappLink = !empty($contact['whatsapp']) ? 'https://wa.me/' . preg_replace('/[^0-9]/', '', $contact['whatsapp']) : '#';

        $businessName = $esc($c['business_name'] ?? '');
        $tagline = $esc($c['tagline'] ?? '');
        $heroHeadline = $esc($c['hero_headline'] ?? '');
        $heroSubtext = $esc($c['hero_subtext'] ?? '');
        $aboutTitle = $esc($c['about_title'] ?? '');
        $aboutText = $esc($c['about_text'] ?? '');
        $ctaText = $esc($c['cta_text'] ?? '');
        $phone = $esc($contact['phone'] ?? '');
        $email = $esc($contact['email'] ?? '');
        $address = $esc($contact['address'] ?? '');
        $head = $this->siteHeadHtml(
            $seoTitle !== '' ? $seoTitle : "{$businessName} | {$tagline}",
            $themeKey,
            $seoDescription !== '' ? $seoDescription : ($c['about_text'] ?? null),
            $canonicalUrl !== '' ? $canonicalUrl : null,
            $ogImage !== '' ? $ogImage : null,
            $this->siteSchemaTag('home', $c, $canonicalUrl, $websiteId)
        );

        $reviewsHtml = $websiteId > 0 ? $this->siteReviewsSectionHtml($websiteId, $slug) : '';

        $hasBookingProducts = false;
        if ($websiteId > 0) {
            $hasBookingProducts = !empty($this->db->query(
                'SELECT id FROM crm_products WHERE website_id = ? AND is_active = 1 LIMIT 1',
                [$websiteId]
            ));
        }

        $leadFormHtml = $this->siteLeadFormHtml($slug, $hasBookingProducts, 'rooms');
        $bookCta = $hasBookingProducts
            ? $this->siteUi('<a href="#rooms" class="ws-btn ws-btn-outline" style="text-decoration:none;">احجز الآن</a>')
            : '';

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}" dir="{$dir}">
<head>
{$head}
</head>
<body class="{$bodyClass}">
    <nav class="ws-nav">
        <div class="ws-nav-inner">
            <span class="ws-logo">🏨 {$businessName}</span>
            <div class="ws-nav-links">
                <a href="#about">{$this->siteUi('عننا')}</a>
                <a href="#rooms">{$this->siteUi('غرفنا')}</a>
                <a href="#reviews">{$this->siteUi('تقييماتنا')}</a>
                <a href="#lead">{$this->siteUi('تواصل معنا')}</a>
                <a href="{$whatsappLink}" target="_blank" class="ws-nav-cta">{$this->siteUi('احجز الآن')}</a>
            </div>
        </div>
    </nav>

    <header class="ws-hero">
        <h1>{$heroHeadline}</h1>
        <p>{$heroSubtext}</p>
        <a href="{$whatsappLink}" target="_blank" class="ws-btn">{$ctaText}</a>
        {$bookCta}
    </header>

    <section class="ws-section" id="about">
        <h2>{$aboutTitle}</h2>
        <p class="ws-about-text">{$aboutText}</p>
    </section>

    <section class="ws-section ws-section-alt" id="rooms">
        <h2>{$this->siteUi('غرفنا وأجنحتنا')}</h2>
        <div class="ws-grid">{$roomsHtml}</div>
    </section>

    <section class="ws-section">
        <h2>{$this->siteUi('مرافق الفندق')}</h2>
        <div class="ws-trust-grid">{$amenitiesHtml}</div>
    </section>

    {$reviewsHtml}

    {$leadFormHtml}

    <section class="ws-section ws-section-alt ws-contact" id="contact">
        <h2>{$this->siteUi('تواصل معنا')}</h2>
        <p>📞 {$phone}</p>
        <p>✉️ {$email}</p>
        <p>📍 {$address}</p>
        <a href="{$whatsappLink}" target="_blank" class="ws-btn">{$this->siteUi('تواصل عبر واتساب')}</a>
    </section>

    <footer class="ws-footer">© {$businessName} - {$this->siteUi('صُمم بواسطة')} Tourfecto</footer>
</body>
</html>
HTML;
    }

    /** GET /sites/{slug}/tours/{tourSlug} - صفحة تفصيل رحلة واحدة */
    /** JSON-LD لصفحات منشئ المنصة (من بيانات الموقع والتقييمات المعتمدة الحقيقية) */
    private function siteSchemaTag(string $kind, array $c, string $siteUrl, int $websiteId, ?array $tour = null): string
    {
        if ($siteUrl === '') {
            return '';
        }
        try {
            require_once __DIR__ . '/../Services/SeoExpert/SeoSchemaBuilder.php';
            $ratings = [];
            if ($websiteId > 0) {
                foreach ((new WebsiteReview())->approvedFor($websiteId, $kind === 'tour' ? (string) ($tour['slug'] ?? '') : null) as $r) {
                    $ratings[] = (int) $r->getAttribute('rating');
                }
            }
            $graph = $kind === 'tour' && $tour ? SeoSchemaBuilder::forBuilderTour($tour, $c, $siteUrl, $ratings) : SeoSchemaBuilder::forBuilderHome($c, $siteUrl, $ratings);
            return SeoSchemaBuilder::scriptTag($graph);
        } catch (Throwable $e) {
            return '';
        }
    }

    /** GET /sites/{slug}/sitemap.xml (وعلى الدومين المخصص /sitemap.xml) */
    public function siteSitemap(array $params = []): array
    {
        $slug = (string) ($params['slug'] ?? '');
        $website = $this->findViewableWebsite($slug);
        if (!$website) {
            header('HTTP/1.1 404 Not Found');
            exit;
        }
        $base = $this->publicSiteUrl($slug, $website);
        $c = $website->getContent();
        $lastmod = substr((string) ($website->getAttribute('updated_at') ?: date('Y-m-d')), 0, 10);
        $urls = [$base . '/'];
        foreach (($c['tours'] ?? []) as $t) {
            if (!empty($t['slug'])) {
                $urls[] = $base . '/tours/' . rawurlencode((string) $t['slug']);
            }
        }
        foreach (($c['rooms'] ?? []) as $t) {
            if (!empty($t['slug'])) {
                $urls[] = $base . '/rooms/' . rawurlencode((string) $t['slug']);
            }
        }
        $approved=(new BuilderLanguageService($this->db))->approved((int)$website->getAttribute('id'),$c);
        $baseUrls=$urls;
        $languageEntries=array_merge([['language'=>BuilderLanguageService::sourceLanguage($c)]],$approved);
        foreach($languageEntries as $version)foreach($baseUrls as $url)$urls[]=rtrim($base,'/').'/'.$version['language'].(substr($url,strlen($base))==='/'?'':substr($url,strlen($base)));
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach (array_unique($urls) as $u) {
            echo '<url><loc>' . htmlspecialchars($u, ENT_XML1, 'UTF-8') . '</loc><lastmod>' . htmlspecialchars($lastmod, ENT_XML1) . '</lastmod></url>';
        }
        echo '</urlset>';
        exit;
    }

    /** GET /sites/{slug}/robots.txt (على الدومين المخصص /robots.txt) - بيدل جوجل على الـ sitemap */
    public function siteRobots(array $params = []): array
    {
        $slug = (string) ($params['slug'] ?? '');
        $website = $this->findViewableWebsite($slug);
        header('Content-Type: text/plain; charset=utf-8');
        if (!$website) {
            echo "User-agent: *\nDisallow:\n";
            exit;
        }
        $base = $this->publicSiteUrl($slug, $website);
        $prefix = rtrim((string) parse_url($base, PHP_URL_PATH), '/');
        echo "User-agent: *\nAllow: /\nDisallow: " . $prefix . "/booking/\n\nSitemap: " . $base . "/sitemap.xml\n";
        exit;
    }

    public function showTourDetail(array $params = []): array
    {
        $slug = (string) ($params['slug'] ?? '');
        $tourSlug = (string) ($params['tourSlug'] ?? '');
        $website = $this->findViewableWebsite($slug);

        if (!$website) {
            header('HTTP/1.1 404 Not Found');
            echo $this->siteUi('<h1 style="text-align:center;font-family:sans-serif;margin-top:100px;">الموقع ده مش موجود أو لسه مش منشور</h1>');
            exit;
        }

        WhatsAppStudioService::captureQuery((int)$website->getAttribute('id'),(int)$website->getAttribute('user_id'));
        EmailPlatformBridge::captureQuery((int)$website->getAttribute('id'));
        $c = $this->internationalContent($website,$params);
        $esc = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        [$lang, $dir] = $this->siteLangAttrs($c);

        $tour = null;
        foreach (($c['tours'] ?? []) as $t) {
            if (($t['slug'] ?? '') === $tourSlug) {
                $tour = $t;
                break;
            }
        }

        if (!$tour) {
            header('HTTP/1.1 404 Not Found');
            echo $this->siteUi('<h1 style="text-align:center;font-family:sans-serif;margin-top:100px;">الرحلة دي مش موجودة</h1>');
            exit;
        }

        $contact = $c['contact'] ?? [];
        $whatsappNumber = preg_replace('/[^0-9]/', '', $contact['whatsapp'] ?? '');
        $bookingMessage = rawurlencode($this->siteUi('أهلاً، عايز أحجز في "') . ($tour['name'] ?? '') . $this->siteUi('" - ابعتلي التفاصيل من فضلك.'));
        $whatsappLink = $whatsappNumber ? "https://wa.me/{$whatsappNumber}?text={$bookingMessage}" : '#';

        // نموذج الحجز المباشر بيظهر بس لو العنصر مرتبط بمنتج حقيقي (crm_products)
        $bookingFormHtml = '';
        $productLinked = $this->db->query(
            'SELECT id FROM crm_products WHERE website_id = ? AND tour_slug = ? AND is_active = 1 LIMIT 1',
            [(int) $website->getAttribute('id'), $tourSlug]
        );
        if (!empty($productLinked)) {
            $bookingFormHtml = $this->siteBookingFormHtml($slug, $tourSlug, 'tour', (int)$website->getAttribute('user_id'), (int)$productLinked[0]['id']);
        }

        $highlightsHtml = '';
        foreach (($tour['highlights'] ?? []) as $h) {
            $highlightsHtml .= '<li>✔ ' . $esc($h) . '</li>';
        }

        $itineraryHtml = '';
        foreach (($tour['itinerary'] ?? []) as $day) {
            $itineraryHtml .= '<div class="ws-day"><div class="ws-day-num">' . $esc($day['day'] ?? '') . '</div><div><h4>' . $esc($day['title'] ?? '') . '</h4><p>' . $esc($day['description'] ?? '') . '</p></div></div>';
        }

        $includesHtml = '';
        foreach (($tour['includes'] ?? []) as $inc) {
            $includesHtml .= '<li>✔ ' . $esc($inc) . '</li>';
        }
        $excludesHtml = '';
        foreach (($tour['excludes'] ?? []) as $exc) {
            $excludesHtml .= '<li>✖ ' . $esc($exc) . '</li>';
        }

        $businessName = $esc($c['business_name'] ?? '');
        $tourName = $esc($tour['name'] ?? '');
        $tourDesc = $esc($tour['short_description'] ?? '');
        $duration = $esc($tour['duration'] ?? '');
        $price = $esc($tour['price'] ?? '');
        $groupSize = $esc($tour['group_size'] ?? '');
        $design = $this->siteDesignAttrs($website);
        // SEO صفحة الرحلة: وصف + رابط أساسي + صورة المشاركة + Schema الرحلة (TouristTrip)
        $siteBase = $this->publicSiteUrl($slug, $website);
        $tourDescMeta = trim((string) ($tour['short_description'] ?? '')) !== '' ? mb_substr(trim((string) $tour['short_description']), 0, 300) : null;
        $tourImage = !empty($tour['image_url']) && preg_match('#^https?://#', (string) $tour['image_url']) ? (string) $tour['image_url'] : null;
        $head = $this->siteHeadHtml("{$tourName} | {$businessName}", $design['theme'], $tourDescMeta, $siteBase . '/tours/' . rawurlencode($tourSlug), $tourImage, $this->siteSchemaTag('tour', $c, $siteBase, (int) $website->getAttribute('id'), $tour));
        $reviewsHtml = $this->siteReviewsSectionHtml((int) $website->getAttribute('id'), $slug, $tourSlug);
        $heroImageHtml = !empty($tour['image_url'])
            ? '<div class="ws-tour-hero-img" style="background-image:url(\'' . $esc($tour['image_url']) . '\');"></div>'
            : '';

        header('Content-Type: text/html; charset=utf-8');
        echo <<<HTML
<!DOCTYPE html>
<html lang="{$lang}" dir="{$dir}">
<head>
{$head}
</head>
<body class="{$design['body_class']}">
    <nav class="ws-nav">
        <div class="ws-nav-inner">
            <a href="/sites/{$slug}" class="ws-logo" style="text-decoration:none;">🌍 {$businessName}</a>
            <div class="ws-nav-links">
                <a href="/sites/{$slug}">{$this->siteUi('الرئيسية')}</a>
                <a href="/sites/{$slug}#tours">{$this->siteUi('كل الرحلات')}</a>
                <a href="{$whatsappLink}" target="_blank" class="ws-nav-cta">{$this->siteUi('اطلب حجز')}</a>
            </div>
        </div>
    </nav>

    {$heroImageHtml}
    <header class="ws-hero ws-hero-sm">
        <h1>{$tourName}</h1>
        <p>{$tourDesc}</p>
    </header>

    <section class="ws-section">
        <div class="ws-tour-meta">
            <div><span>⏱ {$this->siteUi('المدة')}</span><strong>{$duration}</strong></div>
            <div><span>💰 {$this->siteUi('السعر')}</span><strong>{$price}</strong></div>
            <div><span>👥 {$this->siteUi('حجم المجموعة')}</span><strong>{$groupSize}</strong></div>
        </div>

        <h2>{$this->siteUi('أبرز المميزات')}</h2>
        <ul class="ws-list">{$highlightsHtml}</ul>

        <h2>{$this->siteUi('برنامج الرحلة')}</h2>
        <div class="ws-itinerary">{$itineraryHtml}</div>

        <div class="ws-inex-grid">
            <div><h3>✔ {$this->siteUi('شامل')}</h3><ul class="ws-list">{$includesHtml}</ul></div>
            <div><h3>✖ {$this->siteUi('غير شامل')}</h3><ul class="ws-list">{$excludesHtml}</ul></div>
        </div>

        <div class="ws-booking-box">
            <h3>{$this->siteUi('عايز تحجز؟')}</h3>
            <p>{$this->siteUi('اختر التاريخ واكتب بياناتك وهنأكد الحجز مباشرة')}</p>
            {$bookingFormHtml}
            <div class="ws-booking-wa-divider">{$this->siteUi('أو تواصل مباشرة معنا')}</div>
            <a href="{$whatsappLink}" target="_blank" class="ws-btn ws-btn-wa">📲 {$this->siteUi('احجز عبر واتساب')}</a>
        </div>
    </section>

    {$reviewsHtml}

    <footer class="ws-footer">© {$businessName} - {$this->siteUi('صُمم بواسطة')} Tourfecto</footer>
</body>
</html>
HTML;
        exit;
    }

    /** GET /sites/{slug}/rooms/{roomSlug} - صفحة تفصيل غرفة فندقية واحدة */
    public function showRoomDetail(array $params = []): array
    {
        $slug = (string) ($params['slug'] ?? '');
        $roomSlug = (string) ($params['roomSlug'] ?? '');
        $website = $this->findViewableWebsite($slug);

        if (!$website) {
            header('HTTP/1.1 404 Not Found');
            echo $this->siteUi('<h1 style="text-align:center;font-family:sans-serif;margin-top:100px;">الموقع ده مش موجود أو لسه مش منشور</h1>');
            exit;
        }

        WhatsAppStudioService::captureQuery((int)$website->getAttribute('id'),(int)$website->getAttribute('user_id'));
        EmailPlatformBridge::captureQuery((int)$website->getAttribute('id'));
        $c = $this->internationalContent($website,$params);
        $esc = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        [$lang, $dir] = $this->siteLangAttrs($c);

        $room = null;
        foreach (($c['rooms'] ?? []) as $r) {
            if (($r['slug'] ?? '') === $roomSlug) {
                $room = $r;
                break;
            }
        }

        if (!$room) {
            header('HTTP/1.1 404 Not Found');
            echo $this->siteUi('<h1 style="text-align:center;font-family:sans-serif;margin-top:100px;">الغرفة دي مش موجودة</h1>');
            exit;
        }

        $contact = $c['contact'] ?? [];
        $whatsappNumber = preg_replace('/[^0-9]/', '', $contact['whatsapp'] ?? '');
        $bookingMessage = rawurlencode($this->siteUi('أهلاً، عايز أحجز في "') . ($room['name'] ?? '') . $this->siteUi('" - ابعتلي التفاصيل من فضلك.'));
        $whatsappLink = $whatsappNumber ? "https://wa.me/{$whatsappNumber}?text={$bookingMessage}" : '#';

        // نموذج الحجز المباشر بيظهر بس لو الغرفة مرتبطة بمنتج حقيقي (crm_products)
        $bookingFormHtml = '';
        $productLinked = $this->db->query(
            'SELECT id FROM crm_products WHERE website_id = ? AND tour_slug = ? AND is_active = 1 LIMIT 1',
            [(int) $website->getAttribute('id'), $roomSlug]
        );
        if (!empty($productLinked)) {
            $bookingFormHtml = $this->siteBookingFormHtml($slug, $roomSlug, 'room', (int)$website->getAttribute('user_id'), (int)$productLinked[0]['id']);
        }

        $highlightsHtml = '';
        foreach (($room['highlights'] ?? []) as $h) {
            $highlightsHtml .= '<li>✔ ' . $esc($h) . '</li>';
        }
        $amenitiesHtml = '';
        foreach (($room['amenities'] ?? []) as $a) {
            $amenitiesHtml .= '<li>✔ ' . $esc($a) . '</li>';
        }

        $businessName = $esc($c['business_name'] ?? '');
        $roomName = $esc($room['name'] ?? '');
        $roomDesc = $esc($room['short_description'] ?? '');
        $price = $esc($room['price'] ?? '');
        $capacity = $esc($room['capacity'] ?? '');
        $size = $esc($room['size'] ?? '');
        $design = $this->siteDesignAttrs($website);
        $head = $this->siteHeadHtml("{$roomName} | {$businessName}", $design['theme']);
        $reviewsHtml = $this->siteReviewsSectionHtml((int) $website->getAttribute('id'), $slug, $roomSlug);
        $heroImageHtml = !empty($room['image_url'])
            ? '<div class="ws-tour-hero-img" style="background-image:url(\'' . $esc($room['image_url']) . '\');"></div>'
            : '';

        header('Content-Type: text/html; charset=utf-8');
        echo <<<HTML
<!DOCTYPE html>
<html lang="{$lang}" dir="{$dir}">
<head>
{$head}
</head>
<body class="{$design['body_class']}">
    <nav class="ws-nav">
        <div class="ws-nav-inner">
            <a href="/sites/{$slug}" class="ws-logo" style="text-decoration:none;">🏨 {$businessName}</a>
            <div class="ws-nav-links">
                <a href="/sites/{$slug}">{$this->siteUi('الرئيسية')}</a>
                <a href="/sites/{$slug}#rooms">{$this->siteUi('كل الغرف')}</a>
                <a href="{$whatsappLink}" target="_blank" class="ws-nav-cta">{$this->siteUi('اطلب حجز')}</a>
            </div>
        </div>
    </nav>

    {$heroImageHtml}
    <header class="ws-hero ws-hero-sm">
        <h1>{$roomName}</h1>
        <p>{$roomDesc}</p>
    </header>

    <section class="ws-section">
        <div class="ws-tour-meta">
            <div><span>💰 {$this->siteUi('السعر لليلة')}</span><strong>{$price}</strong></div>
            <div><span>👥 {$this->siteUi('السعة')}</span><strong>{$capacity}</strong></div>
            <div><span>📐 {$this->siteUi('المساحة')}</span><strong>{$size}</strong></div>
        </div>

        <h2>{$this->siteUi('أبرز المميزات')}</h2>
        <ul class="ws-list">{$highlightsHtml}</ul>

        <h2>{$this->siteUi('مرافق الغرفة')}</h2>
        <ul class="ws-list">{$amenitiesHtml}</ul>

        <div class="ws-booking-box">
            <h3>{$this->siteUi('عايز تحجز؟')}</h3>
            <p>{$this->siteUi('اختر التاريخ واكتب بياناتك وهنأكد الحجز مباشرة')}</p>
            {$bookingFormHtml}
            <div class="ws-booking-wa-divider">{$this->siteUi('أو تواصل مباشرة معنا')}</div>
            <a href="{$whatsappLink}" target="_blank" class="ws-btn ws-btn-wa">📲 {$this->siteUi('احجز عبر واتساب')}</a>
        </div>
    </section>

    {$reviewsHtml}

    <footer class="ws-footer">© {$businessName} - {$this->siteUi('صُمم بواسطة')} Tourfecto</footer>
</body>
</html>
HTML;
        exit;
    }

    /**
     * مزامنة عنصر واحد (رحلة/غرفة) من الموقع المولّد إلى crm_products.
     * Upsert آمن: البحث الأولي بـ (website_id + tour_slug) - لو موجود
     * نحدّث بياناته، وإلا ننشئ صفًا جديدًا (من غير تكرار في أي حالة).
     * أي خطأ هنا مش بيكسّر الطلب الأصلي (متستناش - الـ sync ثانوي).
     */
    private function syncTourToProduct(GeneratedWebsite $website, array $item): void
    {
        try {
            $slug = (string) ($item['slug'] ?? '');
            if ($slug === '') {
                return;
            }
            $userId = (int) $website->getAttribute('user_id');
            $websiteId = (int) $website->getAttribute('id');
            if (!$userId || !$websiteId) {
                return;
            }

            [$price, $currency] = $this->parseItemPrice((string) ($item['price'] ?? ''));
            $name = (string) ($item['name'] ?? $slug);
            $description = (string) ($item['short_description'] ?? '');
            $sku = 'WS' . $websiteId . '-' . $slug;
            $db = $this->db;

            $existing = $db->query(
                'SELECT id FROM crm_products WHERE website_id = ? AND tour_slug = ? LIMIT 1',
                [$websiteId, $slug]
            );

            if (!empty($existing)) {
                $db->query(
                    'UPDATE crm_products
                     SET name = ?, description = ?, sku = ?, price = ?, currency = ?, is_active = 1
                     WHERE id = ?',
                    [$name, $description, $sku, $price, $currency, $existing[0]['id']]
                );
            } else {
                $db->query(
                    'INSERT INTO crm_products
                        (user_id, website_id, tour_slug, name, description, sku, price, currency, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)',
                    [$userId, $websiteId, $slug, $name, $description, $sku, $price, $currency]
                );
            }
        } catch (Exception $e) {
            Logger::warning('syncTourToProduct failed', ['website_id' => (int) $website->getAttribute('id'), 'error' => CustomerError::message($e->getMessage(), 500)]);
        }
    }

    /** مزامنة كل عناصر الموقع (رحلات أو غرف حسب المجال) - تُستخدم عند النشر */
    private function syncAllSiteItems(GeneratedWebsite $website): void
    {
        $c = $website->getContent();
        $itemsKey = ($c['industry'] ?? 'tours') === 'hotel' ? 'rooms' : 'tours';
        foreach (($c[$itemsKey] ?? []) as $item) {
            $this->syncTourToProduct($website, $item);
        }
    }

    /** تعطيل المنتج المرتبط عند حذف العنصر من الموقع (مش حذف - حماية سجل الحجوزات) */
    private function deactivateLinkedProduct(GeneratedWebsite $website, string $itemSlug): void
    {
        try {
            if ($itemSlug === '') {
                return;
            }
            $this->db->query(
                'UPDATE crm_products SET is_active = 0 WHERE website_id = ? AND tour_slug = ?',
                [(int) $website->getAttribute('id'), $itemSlug]
            );
        } catch (Exception $e) {
            Logger::warning('deactivateLinkedProduct failed', ['website_id' => (int) $website->getAttribute('id'), 'error' => CustomerError::message($e->getMessage(), 500)]);
        }
    }

    /** استخراج رقم السعر + العملة من نص حر زي "350$" / "€120" / "1500 جنيه" */
    private function parseItemPrice(string $priceText): array
    {
        $priceText = trim((string) $priceText);
        $currency = 'USD';
        if (preg_match($this->siteUi('/جنيه|ج\.م|EGP/i'), $priceText)) {
            $currency = 'EGP';
        } elseif (strpos($priceText, '€') !== false || stripos($priceText, 'euro') !== false) {
            $currency = 'EUR';
        } elseif (strpos($priceText, '£') !== false || stripos($priceText, 'gbp') !== false) {
            $currency = 'GBP';
        }
        if (preg_match('/(\d+[.,]?\d*)/', $priceText, $m)) {
            return [(float) str_replace(',', '', $m[1]), $currency];
        }
        return [0.0, $currency];
    }

    /** هل الطلب عايز JSON (fetch) ولا صفحة/redirect عادية؟ */
    private function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return strpos($accept, 'application/json') !== false;
    }

    /** سعة افتراضية لما صاحب الموقع لسه ماحددش توفر (قابلة للتغيير من .env، و0 = ماتفتحش سعة تلقائي) */
    private const WEBSITE_BOOKING_DEFAULT_CAPACITY = 15;
    private const WEBSITE_BOOKING_MAX_PARTY = 30;

    private function defaultSiteCapacity(): int
    {
        $v = function_exists('env') ? env('WEBSITE_BOOKING_DEFAULT_CAPACITY') : null;
        return ($v === null || $v === '' || !is_numeric($v)) ? self::WEBSITE_BOOKING_DEFAULT_CAPACITY : max(0, (int) $v);
    }

    /**
     * POST /sites/{slug}/tours/{tourSlug}/book - حجز مباشر من صفحة الرحلة
     * (زائر بدون تسجيل دخول). بيبني حجز عبر BookingEngine::createBooking
     * بـ source='website' وproduct_id المرتبط بـ (website_id+tour_slug).
     */
    public function bookSiteTour(array $params = []): array
    {
        return $this->bookSiteItem($params, 'tour');
    }

    /** POST /sites/{slug}/rooms/{roomSlug}/book - نفس المنطق لغرف الفندق */
    public function bookSiteRoom(array $params = []): array
    {
        return $this->bookSiteItem($params, 'room');
    }

    public function quoteSiteTour(array $params=[]): array { return $this->quoteSiteItem($params,'tour'); }
    public function quoteSiteRoom(array $params=[]): array { return $this->quoteSiteItem($params,'room'); }
    private function quoteSiteItem(array $params,string $type): array
    {
        if($guard=$this->rateLimitGuard('ip','site_booking_quote',120,60))return $guard;
        try{
            $website=$this->findViewableWebsite((string)($params['slug']??''));if(!$website)return $this->error($this->siteUi('الموقع غير متاح'),404);
            $itemSlug=(string)($params[$type==='room'?'roomSlug':'tourSlug']??'');
            $itemExists=false;foreach(($website->getContent()[$type==='room'?'rooms':'tours']??[])as$item)if(($item['slug']??'')===$itemSlug)$itemExists=true;
            if(!$itemExists)return $this->error($this->siteUi('العنصر غير متاح'),404);
            $owner=(int)$website->getAttribute('user_id');$product=$this->db->query('SELECT id,price,currency FROM crm_products WHERE user_id=? AND website_id=? AND tour_slug=? AND is_active=1 LIMIT 1',[$owner,(int)$website->getAttribute('id'),$itemSlug])[0]??null;if(!$product)return $this->error($this->siteUi('الخدمة غير متاحة'),404);
            $date=(string)$this->get('start_date','');$adults=filter_var($this->get('adults_count',1),FILTER_VALIDATE_INT);$children=filter_var($this->get('children_count',0),FILTER_VALIDATE_INT);
            if(!InventoryService::isValidDate($date)||$date<date('Y-m-d')||$adults===false||$children===false||$adults<1||$children<0||$adults+$children>self::WEBSITE_BOOKING_MAX_PARTY)return $this->error($this->siteUi('راجع تاريخ الحجز وعدد الأفراد'),422);
            $rawToken=$this->get('request_token','');$requestToken=is_string($rawToken)?$rawToken:'';
            if(preg_match('/^[a-f0-9]{32}$/D',$requestToken)){
                $previous=$this->db->query('SELECT start_date,end_date,inventory_units,adults_count,children_count,total_amount,currency,status,pricing_json FROM bookings WHERE user_id=? AND product_id=? AND public_request_token=?',[$owner,(int)$product['id'],$requestToken])[0]??null;
                if($previous && $previous['start_date']===$date && (int)$previous['adults_count']===$adults && (int)$previous['children_count']===$children && $previous['status']!=='cancelled' && ($type!=='room'||($previous['end_date']===(string)$this->get('end_date','')&&(int)$previous['inventory_units']===(int)$this->get('rooms_count',1)))){
                    $pricing=json_decode((string)$previous['pricing_json'],true)?:[];$oldIds=array_map(fn($x)=>(int)$x['id'],$pricing['extras']??[]);$submittedIds=(array)$this->get('extra_ids',[]);foreach($submittedIds as$extraId)if(filter_var($extraId,FILTER_VALIDATE_INT)===false)return $this->error($this->siteUi('إضافة غير صالحة'),422);$newIds=array_values(array_unique(array_map('intval',$submittedIds)));sort($oldIds);sort($newIds);
                    if($oldIds===$newIds)return $this->success(['total'=>(float)$previous['total_amount'],'currency'=>$previous['currency'],'adult_price'=>$pricing['adult_price']??0,'child_price'=>$pricing['child_price']??0,'extras'=>$pricing['extras']??[],'reused'=>true]);
                }
            }
            if($type==='room'){
                $departure=(string)$this->get('end_date','');$rooms=filter_var($this->get('rooms_count',1),FILTER_VALIDATE_INT);if($rooms===false)return $this->error($this->siteUi('عدد الغرف غير صالح'),422);
                $stay=HotelStayService::quote((int)$product['id'],(float)$product['price'],$date,$departure,$rooms);
                $extras=(new BookingOperationsService($this->db))->extraTotal($owner,(int)$product['id'],(array)$this->get('extra_ids',[]),$adults+$children);
                return $this->success(['total'=>round($stay['total']+$extras['total'],2),'currency'=>$product['currency'],'nights'=>$stay['nights'],'rooms_count'=>$rooms,'adult_price'=>(float)$product['price'],'child_price'=>0,'extras'=>$extras['items'],'inventory_units'=>$rooms]);
            }
            $ops=new BookingOperationsService($this->db);$settings=$ops->productSettings($owner,(int)$product['id']);$inv=$this->db->query('SELECT capacity,booked_count,price_override,is_blocked FROM inventory WHERE product_id=? AND date=?',[(int)$product['id'],$date])[0]??null;
            $units=BookingOperationsService::units($settings,$adults,$children);$capacity=$inv?(int)$inv['capacity']- (int)$inv['booked_count']:($settings['capacity_mode']==='booking'?$this->defaultSiteCapacity():0);
            if(($inv&&!empty($inv['is_blocked']))||$capacity<$units)return $this->error($this->siteUi('الأماكن المطلوبة غير متاحة في هذا التاريخ'),422);
            $price=$inv&&$inv['price_override']!==null?(float)$inv['price_override']:(float)$product['price'];$extras=$ops->extraTotal($owner,(int)$product['id'],(array)$this->get('extra_ids',[]),$adults+$children);
            return $this->success(['total'=>round(BookingOperationsService::baseTotal($price,$settings,$adults,$children)+$extras['total'],2),'currency'=>$product['currency'],'adult_price'=>$price,'child_price'=>(float)$settings['child_price'],'extras'=>$extras['items'],'inventory_units'=>$units]);
        }catch(Throwable $e){return $this->error(CustomerError::message($e->getMessage()),422);}
    }

    /**
     * منطق الحجز المشترك للرحلات والغرف.
     *
     * Fallback آمن: أي فشل (مفيش منتج مرتبط، Stripe مش مفعّل، خطأ حجز)
     * مش بيكسّر الصفحة - بيرجع برسالة واضحة + خيار واتساب.
     */
    private function bookSiteItem(array $params, string $itemType): array
    {
        $slug = (string) ($params['slug'] ?? '');
        $itemSlug = (string) ($params[$itemType === 'room' ? 'roomSlug' : 'tourSlug'] ?? '');
        $website = $this->findViewableWebsite($slug);
        if (!$website) {
            return $this->error($this->siteUi('الموقع مش موجود أو لسه مش منشور'), 404);
        }

        $c = $website->getContent();
        $itemsKey = $itemType === 'room' ? 'rooms' : 'tours';
        $item = null;
        foreach (($c[$itemsKey] ?? []) as $it) {
            if (($it['slug'] ?? '') === $itemSlug) {
                $item = $it;
                break;
            }
        }
        if (!$item) {
            return $this->error($this->siteUi('العنصر مش موجود'), 404);
        }

        if ($guard = $this->siteFormGuard('book', 6, 600, $this->siteUi('تم استلام طلب حجزك'))) {
            return $guard;
        }
        if (!$this->validate(['start_date' => 'required', 'customer_name' => 'required'])) {
            return $this->error($this->siteUi('اكتب تاريخ الحجز واسمك من فضلك'), 422);
        }
        $custEmail = trim((string) $this->get('customer_email', ''));
        if ($custEmail !== '' && !filter_var($custEmail, FILTER_VALIDATE_EMAIL)) {
            return $this->error($this->siteUi('الإيميل مش صحيح'), 422);
        }
        $startDate = (string) $this->get('start_date');
        if (strtotime($startDate) === false || strtotime($startDate) < strtotime(date('Y-m-d'))) {
            return $this->error($this->siteUi('تاريخ الحجز لازم يكون تاريخ صحيح في المستقبل'), 422);
        }
        $adults = filter_var($this->get('adults_count', 1), FILTER_VALIDATE_INT);
        $children = filter_var($this->get('children_count', 0), FILTER_VALIDATE_INT);
        if($adults===false || $children===false || $adults<1 || $children<0)return $this->error($this->siteUi('عدد المسافرين غير صالح'),422);
        if ($adults + $children > self::WEBSITE_BOOKING_MAX_PARTY) {
            return $this->error($this->siteUi('عدد الأفراد كبير على الحجز أونلاين - تواصل معانا عبر واتساب لحجز مجموعة'), 422, ['whatsapp_fallback' => true]);
        }
        if (strtotime($startDate) > strtotime('+18 months')) {
            return $this->error($this->siteUi('التاريخ بعيد أوي - اختار تاريخ خلال سنة ونص'), 422);
        }

        $websiteId = (int) $website->getAttribute('id');
        $userId = (int) $website->getAttribute('user_id');

        // 1) المنتج المرتبط بالعنصر داخل الموقع ده
        $productRow = $this->db->query(
            'SELECT id FROM crm_products WHERE website_id = ? AND tour_slug = ? AND is_active = 1 LIMIT 1',
            [$websiteId, $itemSlug]
        );
        if (empty($productRow)) {
            Logger::warning('Website booking attempt without linked product', ['website_id' => $websiteId, 'slug' => $itemSlug]);
            return $this->error($this->siteUi('هذا العنصر غير متاح للحجز المباشر حاليًا - تواصل عبر واتساب'), 422, ['whatsapp_fallback' => true]);
        }
        $productId = (int) $productRow[0]['id'];

        // 2) لو مفيش توفر مسجّل لليوم ده: نفتح سعة افتراضية صغيرة (من .env) بدل رفض الحجز،
        //    ونبلّغ صاحب النشاط إنه يحدد التوفر الحقيقي (قبل كده كانت 50 مكان من غير ما يعرف).
        try {
            if($itemType==='room'){HotelStayService::dates($startDate,(string)$this->get('end_date',''));}
            $inventory = new InventoryService();
            $avail = $inventory->checkAvailability($productId, $startDate);
            if ($itemType!=='room' && !$avail['available'] && ($avail['reason'] ?? '') === 'no_inventory_set') {
                $opsSettings=(new BookingOperationsService($this->db))->productSettings($userId,$productId);
                $cap = $opsSettings['capacity_mode']==='seat' ? 0 : $this->defaultSiteCapacity();
                if ($cap <= 0) {
                    return $this->error($this->siteUi('التاريخ ده لسه مش مفتوح للحجز أونلاين - تواصل عبر واتساب'), 422, ['whatsapp_fallback' => true]);
                }
                $inventory->setDay($userId, $productId, $startDate, $cap);
                $this->notifyAutoCapacity($userId, $productId, $startDate, $cap);
            }
        } catch (Exception $e) {
            Logger::warning('Website booking inventory init failed', ['product_id' => $productId, 'error' => CustomerError::message($e->getMessage(), 500)]);
        }

        // 3) إنشاء الحجز عبر Booking Engine (source='website'، أو source
        //    إعلاني + إسناد UTM لو الزائر جه من كليك إعلان خلال نافذة 30 يوم)
        $bookingSource = 'website';
        $attribution = null;
        try {
            if (class_exists('AdTrackingService')) {
                $attribution = (new AdTrackingService())->readAttribution();
            }
        } catch (Exception $e) {
            Logger::warning('Booking attribution read failed', ['website_id' => $websiteId, 'error' => CustomerError::message($e->getMessage(), 500)]);
        }

        if ($attribution !== null) {
            $bookingSource = match ($attribution['platform']) {
                'meta_ads' => 'ad:meta',
                'google_ads' => 'ad:google',
                default => 'ad',
            };
        }

        try {
            $bookingData = [
                'product_id' => $productId,
                'start_date' => $startDate,
                'customer_name' => (string) $this->get('customer_name'),
                'customer_phone' => (string) $this->get('customer_phone', '') ?: null,
                'customer_email' => (string) $this->get('customer_email', '') ?: null,
                'adults_count' => $adults,
                'children_count' => $children,
                'source' => $bookingSource,
                'extra_ids' => (array) $this->get('extra_ids', []),
                'expected_total' => $this->get('quoted_total'),
                'request_token' => $this->get('request_token'),
                'wa_token' => $this->get('wa_token') ?: WhatsAppStudioService::cookieToken($websiteId),
                // كليك من إيميل حملة/أتمتة (tf_em) → إسناد الحجز وإيراده للحملة (EmailPlatformBridge)
                'em_token' => is_string($this->get('em_token')) && preg_match('/^[a-f0-9]{32,64}$/D', (string) $this->get('em_token')) ? (string) $this->get('em_token') : EmailPlatformBridge::cookieToken($websiteId),
                'notes' => $this->siteUi('حجز مباشر من ') . ($itemType === 'room' ? $this->siteUi('صفحة الغرفة') : $this->siteUi('صفحة الرحلة')) . ': /sites/' . $slug . '/' . $itemsKey . '/' . $itemSlug,
            ];
            if ($attribution !== null) {
                $bookingData['attributed_utm_link_id'] = $attribution['utm_link_id'];
            }

            if($itemType==='room'){$bookingData['end_date']=(string)$this->get('end_date','');$bookingData['rooms_count']=$this->get('rooms_count',1);}
            $booking = (new BookingEngine())->createBooking($userId, $bookingData);
            if(!empty($booking['reused']))return $this->success(['booking_reference'=>$booking['booking_reference'],'reused'=>true,'checkout_url'=>null],$this->siteUi('طلبك مسجل بالفعل؛ تواصل مع الشركة بمرجع الحجز لمتابعته.'));

            // الإسناد اتحول لحجز - بنمسح الكوكي عشان الحجز الجاي مايتنسبش لنفس
            // الرابط القديم (نافذة الإسناد بتتبدأ من جديد من أول كليك إعلان جديد).
            if ($attribution !== null && class_exists('AdTrackingService')) {
                try{(new AdTrackingService())->clearAttribution();}catch(Throwable $ignored){Logger::warning('Booking attribution cookie cleanup failed',['booking_id'=>$booking['id']]);}
            }
        } catch (Exception $e) {
            Logger::warning('Website booking failed', ['website_id' => $websiteId, 'product_id' => $productId, 'error' => CustomerError::message($e->getMessage(), 500)]);
            return $this->error($this->siteUi('ما قدرناش نأكد الحجز للتاريخ ده حاليًا - جرب تاريخ تاني أو تواصل عبر واتساب'), 422, ['whatsapp_fallback' => true]);
        }

        $bookingId = (int) $booking['id'];
        $reference = (string) $booking['booking_reference'];

        // صاحب النشاط ماكانش بيتبلّغ بالحجز الجديد خالص (كان لازم يدخل يدوّر)
        try {
            $count = (int) ($this->db->query('SELECT COUNT(*) AS c FROM bookings WHERE user_id = ?', [$userId])[0]['c'] ?? 0);
            $itemName = (string) ($item['name'] ?? $item['title'] ?? $itemSlug);
            Notification::notify($userId, 'booking_new',
                $count <= 1 ? $this->siteUi('🎉 أول حجز من موقعك!') : $this->siteUi('حجز جديد من موقعك'),
                $this->get('customer_name') . $this->siteUi(' حجز "') . $itemName . $this->siteUi('" يوم ') . $startDate . ' (' . ($adults + $children) . $this->siteUi(' أفراد) - كود ') . $reference,
                '/bookings');
        } catch (Throwable $e) {
            Logger::warning('new booking notify failed', ['error' => CustomerError::message($e->getMessage(), 500)]);
        }
        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
        $successUrl = $base . '/sites/' . rawurlencode($slug) . '/booking/' . rawurlencode($reference);
        $cancelUrl = $base . '/sites/' . rawurlencode($slug) . '/' . ($itemType === 'room' ? 'rooms' : 'tours') . '/' . rawurlencode($itemSlug);

        // 4) الدفع: على حساب صاحب النشاط (Stripe بتاعه) أو طلب حجز من غير دفع أونلاين.
        //    🔒 قبل كده كان بيقبض على حساب Stripe بتاع المنصة لكل المواقع.
        try {
            $stripe = class_exists('OperatorPayments') ? OperatorPayments::stripeFor($userId) : null;
            if ($stripe !== null) {
                $session = $stripe->createCheckoutSession($userId, $bookingId, $successUrl, $cancelUrl);
                if ($this->wantsJson()) {
                    return $this->success(['booking_reference' => $reference, 'checkout_url' => $session['checkout_url']], $this->siteUi('جاري تحويلك لصفحة الدفع الآمنة...'));
                }
                header('Location: ' . $session['checkout_url']);
                exit;
            }
        } catch (Exception $e) {
            Logger::warning('Stripe checkout failed for website booking', ['booking_id' => $bookingId, 'error' => CustomerError::message($e->getMessage(), 500)]);
        }

        if ($this->wantsJson()) {
            return $this->success(
                ['booking_reference' => $reference, 'checkout_url' => null, 'whatsapp_fallback' => true],
                $this->siteUi('تم استلام طلب حجزك - كود الحجز: ') . $reference,
                201
            );
        }
        header('Location: ' . $successUrl);
        exit;
    }

    /** تنبيه لصاحب النشاط (مرة في اليوم لكل منتج) إن السعة اتفتحت تلقائي */
    private function notifyAutoCapacity(int $userId, int $productId, string $date, int $cap): void
    {
        try {
            $recent = $this->db->query(
                "SELECT id FROM notifications WHERE user_id = ? AND type = 'booking_auto_capacity' AND link = ? AND created_at > (NOW() - INTERVAL 1 DAY) LIMIT 1",
                [$userId, '/bookings?product=' . $productId]
            );
            if ($recent) {
                return;
            }
            $name = $this->db->query('SELECT name FROM crm_products WHERE id = ? LIMIT 1', [$productId]);
            Notification::notify($userId, 'booking_auto_capacity',
                $this->siteUi('حجز جديد على تاريخ مالوش توفر محدد'),
                $this->siteUi('فتحنا سعة ') . $cap . $this->siteUi(' حجز تلقائي لـ "') . ($name[0]['name'] ?? '') . $this->siteUi('" يوم ') . $date . $this->siteUi(' عشان الحجز مايضيعش. حدد السعة الحقيقية من صفحة الحجوزات عشان مايحصلش حجز زيادة.'),
                '/bookings?product=' . $productId);
        } catch (Throwable $e) {
            Logger::warning('auto capacity notify failed', ['error' => CustomerError::message($e->getMessage(), 500)]);
        }
    }

    /** GET /sites/{slug}/booking/{reference} - صفحة تأكيد الحجز للزائر */
    public function showBookingConfirmation(array $params = []): array
    {
        $slug = (string) ($params['slug'] ?? '');
        $reference = (string) ($params['reference'] ?? '');
        $website = $this->findViewableWebsite($slug);
        if (!$website) {
            header('HTTP/1.1 404 Not Found');
            echo $this->siteUi('<h1 style="text-align:center;font-family:sans-serif;margin-top:100px;">الموقع ده مش موجود أو لسه مش منشور</h1>');
            exit;
        }

        $booking = (new Booking())->findByReference($reference);
        if (!$booking
            || (int) $booking->getAttribute('user_id') !== (int) $website->getAttribute('user_id')) {
            header('HTTP/1.1 404 Not Found');
            echo $this->siteUi('<h1 style="text-align:center;font-family:sans-serif;margin-top:100px;">الحجز ده مش موجود</h1>');
            exit;
        }

        // الدفع على حساب Stripe بتاع صاحب النشاط: نتأكد منه دلوقتي (مفيش Webhook من حسابه)
        $paidNow = false;
        if ((string) $booking->getAttribute('status') === 'pending' && class_exists('OperatorPayments')) {
            if (OperatorPayments::verifyBooking((int) $booking->getAttribute('id')) === 'paid') {
                $booking = (new Booking())->findByReference($reference) ?: $booking;
                $paidNow = true;
            }
        }
        $isRequestOnly = class_exists('OperatorPayments') && OperatorPayments::effectiveMode((int) $website->getAttribute('user_id')) === 'request';

        $c = $website->getContent();
        $esc = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        [$lang, $dir] = $this->siteLangAttrs($c);

        $contact = $c['contact'] ?? [];
        $whatsappNumber = preg_replace('/[^0-9]/', '', $contact['whatsapp'] ?? '');
        $bookingMessage = rawurlencode($this->siteUi('أهلاً، بخصوص حجزي رقم "') . $reference . $this->siteUi('" - محتاج مساعدة من فضلك.'));
        $whatsappLink = $whatsappNumber ? "https://wa.me/{$whatsappNumber}?text={$bookingMessage}" : '#';

        $statusLabel = [
            'pending' => $isRequestOnly
                ? $this->siteUi('طلب حجز - هنتواصل معاك قريب لتأكيد الحجز وطريقة الدفع')
                : $this->siteUi('قيد الانتظار - هنأكد الحجز معاك خلال وقت قصير'),
            'confirmed' => $this->siteUi('مؤكد - الحجز اتعمل بنجاح'),
            'cancelled' => $this->siteUi('ملغي'),
            'completed' => $this->siteUi('مكتمل'),
            'no_show' => $this->siteUi('لم يتم الحضور'),
        ][(string) $booking->getAttribute('status')] ?? $this->siteUi('قيد المراجعة');

        $statusClass = in_array((string) $booking->getAttribute('status'), ['confirmed', 'completed'], true)
            ? 'ws-ok' : 'ws-pending';

        $businessName = $esc($c['business_name'] ?? '');
        $design = $this->siteDesignAttrs($website);
        $head = $this->siteHeadHtml("{$this->siteUi('تأكيد الحجز')} | {$businessName}", $design['theme']);

        header('Content-Type: text/html; charset=utf-8');
        echo <<<HTML
<!DOCTYPE html>
<html lang="{$lang}" dir="{$dir}">
<head>
{$head}
</head>
<body class="{$design['body_class']}">
    <nav class="ws-nav">
        <div class="ws-nav-inner">
            <a href="/sites/{$slug}" class="ws-logo" style="text-decoration:none;">🌍 {$businessName}</a>
            <div class="ws-nav-links">
                <a href="/sites/{$slug}">{$this->siteUi('الرئيسية')}</a>
                <a href="{$whatsappLink}" target="_blank" class="ws-nav-cta">{$this->siteUi('تواصل معنا')}</a>
            </div>
        </div>
    </nav>

    <header class="ws-hero ws-hero-sm">
        <h1>{$this->siteUi('استلمنا طلب حجزك')}</h1>
        <p>{$this->siteUi('احتفظ بكود الحجز ده للمراجعة أو الاستفسار')}</p>
    </header>

    <section class="ws-section" style="max-width:560px;">
        <div class="ws-booking-box">
            <div class="ws-booking-confirm {$statusClass}">
                <strong>{$this->siteUi('كود الحجز')}: {$esc($reference)}</strong>
                <p>{$esc($statusLabel)}</p>
            </div>
            <p style="margin:14px 0;">{$this->siteUi('لو عندك أي استفسار عن الحجز، ابعتلنا رسالة على واتساب مباشرة')}.</p>
            <a href="{$whatsappLink}" target="_blank" class="ws-btn">📲 {$this->siteUi('تواصل عبر واتساب')}</a>
            <p style="margin-top:12px;"><a href="/sites/{$slug}" style="color:var(--ws-accent);">{$this->siteUi('الرجوع للموقع')} ←</a></p>
        </div>
    </section>

    <footer class="ws-footer">© {$businessName} - {$this->siteUi('صُمم بواسطة')} Tourfecto</footer>
</body>
</html>
HTML;
        exit;
    }

    /**
     * نموذج الحجز المباشر المعروض في صفحة تفصيل الرحلة/الغرفة.
     * بيبعت بـ fetch لـ POST /sites/{slug}/{tours|rooms}/{itemSlug}/book
     * ويحوّل الزائر لصفحة Stripe أو يعرض كود الحجز.
     */
    /**
     * نموذج تواصل/طلب حجز خفيف للصفحة الرئيسية العامة (بند 3).
     * بيرسل لـ POST /sites/{slug}/lead (submitLead الموجود) بنفس أسماء الحقول
     * (visitor_name/phone/email/message) ونفس أسلوب fetch JSON زي نماذج الحجز.
     * بدون CSRF إضافي — submitLead لا يستدعي verifyCsrf (نفس السلوك الحالي،
     * الحماية الأساسية هي RateLimitMiddleware العام + فلترة visitor_name المطلوب).
     * لو $hasBookingProducts بيرضيف زرار "احجز الآن" يرجّع الزائر لأقرب
     * قسم جولات/غرف (لما الموقع عنده منتجات فعلية مرتبطة بـ crm_products).
     */
    private function siteLeadFormHtml(string $slug, bool $hasBookingProducts, string $itemsSectionId = 'tours'): string
    {
        $esc = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        $action = '/sites/' . rawurlencode($slug) . '/lead';
        $bookNow = $hasBookingProducts
            ? '<div style="margin-top:14px;"><a href="#' . $esc($itemsSectionId) . $this->siteUi('" class="ws-btn" style="text-decoration:none;">احجز الآن — شوف البرامج والتفاصيل</a></div>')
            : '';

        return <<<HTML
<section class="ws-section ws-lead" id="lead">
    <h2>{$this->siteUi('أرسل لنا طلبك')}</h2>
    <p style="color:#64748b;text-align:center;margin:0 0 18px;">{$this->siteUi('املأ النموذج وسنواصل معك في أقرب وقت')}.</p>
    <form id="wsLeadForm" class="ws-booking-form" data-action="{$esc($action)}">
        {$this->spamTrapHtml()}
        <div class="ws-booking-cols">
            <label class="ws-bf-label">👤 {$this->siteUi('الاسم')} *
                <input type="text" name="visitor_name" required placeholder="{$this->siteUi('اسمك الكامل')}" class="ws-bf-input">
            </label>
            <label class="ws-bf-label">📱 {$this->siteUi('الهاتف')}
                <input type="tel" name="phone" placeholder="{$this->siteUi('رقم واتساب')}/{$this->siteUi('هاتف')}" class="ws-bf-input">
            </label>
        </div>
        <label class="ws-bf-label">✉️ {$this->siteUi('الإيميل')}
            <input type="email" name="email" placeholder="{$this->siteUi('بريدك الإلكتروني')}" class="ws-bf-input">
        </label>
        <label class="ws-bf-label">💬 {$this->siteUi('رسالتك')} / {$this->siteUi('طلبك')}
            <textarea name="message" rows="3" placeholder="{$this->siteUi('أخبرنا بما تحتاجه')} ({$this->siteUi('مثال')}: {$this->siteUi('حجز رحلة النيل يوم')} 15...)" class="ws-bf-input" style="resize:vertical;"></textarea>
        </label>
        <button type="submit" class="ws-btn" style="width:100%;">{$this->siteUi('إرسال الطلب')}</button>
        <div id="wsLeadMsg" class="ws-booking-msg" role="status"></div>
    </form>
    {$bookNow}
</section>
<script>
(function () {
    var form = document.getElementById('wsLeadForm');
    if (!form) return;
    var msg = document.getElementById('wsLeadMsg');
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        msg.className = 'ws-booking-msg';
        msg.textContent = '{$this->siteJs('جاري إرسال طلبك')}...';
        var payload = {};
        new FormData(form).forEach(function (value, key) { payload[key] = value; });
        fetch(form.getAttribute('data-action'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (res) { return res.json(); }).then(function (res) {
            if (res.success) {
                msg.className = 'ws-booking-msg ws-ok';
                msg.textContent = res.message || '{$this->siteJs('تم إرسال طلبك')}';
                form.reset();
            } else {
                msg.className = 'ws-booking-msg ws-pending';
                msg.textContent = res.error || '{$this->siteJs('حصل خطأ')} - {$this->siteJs('جرب تاني أو تواصل عبر واتساب')}';
            }
        }).catch(function () {
            msg.className = 'ws-booking-msg ws-pending';
            msg.textContent = '{$this->siteJs('حصل خطأ في الاتصال')} - {$this->siteJs('جرب تاني أو تواصل عبر واتساب')}';
        });
    });
})();
</script>
HTML;
    }

    private function siteBookingFormHtml(string $slug, string $itemSlug, string $itemType, int $ownerId, int $productId): string
    {
        $esc = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        $action = '/sites/' . rawurlencode($slug) . '/' . ($itemType === 'room' ? 'rooms' : 'tours') . '/' . rawurlencode($itemSlug) . '/book';
        $today = date('Y-m-d');

        $ops=new BookingOperationsService($this->db);$settings=$ops->productSettings($ownerId,$productId);$extrasHtml='';
        foreach($ops->extras($ownerId,$productId) as $extra)if($extra['is_active'])$extrasHtml.='<label class="ws-bf-label"><input type="checkbox" name="extra_ids[]" value="'.$esc($extra['id']).'"> '.$esc($extra['name']).' — '.$esc($extra['price']).' / '.($extra['price_mode']==='person'?$this->siteUi('فرد'):$this->siteUi('حجز')).'</label>';
        $policyHtml=$this->siteUi('<p class="ws-bf-label">سعر الطفل: ').$esc($settings['child_price']).$this->siteUi('. مهلة الإلغاء: ').$esc($settings['cancellation_hours']).$this->siteUi(' ساعة. نسبة الاسترداد وفق السياسة: ').$esc($settings['refund_percent']).$this->siteUi('%. الاسترداد بعد مراجعة الطلب.</p>');

        $stayFields=$itemType==='room'?$this->siteUi('<label class="ws-bf-label">تاريخ المغادرة<input type="date" name="end_date" min="').$today.$this->siteUi('" required class="ws-bf-input"></label><label class="ws-bf-label">عدد الغرف<input type="number" name="rooms_count" min="1" max="20" value="1" required class="ws-bf-input"></label>'):'';
        return <<<HTML
<form id="wsBookingForm" class="ws-booking-form" data-action="{$esc($action)}" data-slug="{$esc($slug)}">
    {$this->spamTrapHtml()}
    <label class="ws-bf-label">📅 {$this->siteUi('تاريخ الحجز')}
        <input type="date" name="start_date" min="{$today}" required class="ws-bf-input">
    </label>
    {$stayFields}
    <div class="ws-booking-cols">
        <label class="ws-bf-label">👥 {$this->siteUi('بالغين')}
            <input type="number" name="adults_count" min="1" value="1" class="ws-bf-input">
        </label>
        <label class="ws-bf-label">🧒 {$this->siteUi('أطفال')}
            <input type="number" name="children_count" min="0" value="0" class="ws-bf-input">
        </label>
    </div>
    {$policyHtml}
    {$extrasHtml}
    <label class="ws-bf-label">👤 {$this->siteUi('الاسم')}
        <input type="text" name="customer_name" required placeholder="{$this->siteUi('اسمك الكامل')}" class="ws-bf-input">
    </label>
    <label class="ws-bf-label">📱 {$this->siteUi('الهاتف')}
        <input type="tel" name="customer_phone" placeholder="{$this->siteUi('رقم واتساب')}/{$this->siteUi('هاتف')}" class="ws-bf-input">
    </label>
    <label class="ws-bf-label">✉️ {$this->siteUi('الإيميل')}
        <input type="email" name="customer_email" placeholder="{$this->siteUi('بريدك الإلكتروني')}" class="ws-bf-input">
    </label>
    <p id="wsBookingQuote" role="status">{$this->siteUi('اختار التاريخ والأفراد لعرض السعر الإجمالي')}.</p>
    <button type="submit" class="ws-btn" style="width:100%;">{$this->siteUi('تأكيد الحجز')}</button>
    <div id="wsBookingMsg" class="ws-booking-msg" role="status"></div>
</form>
<script>
(function () {
    var form = document.getElementById('wsBookingForm');
    if (!form) return;
    var msg = document.getElementById('wsBookingMsg');
    var bookingSubmitting=false;
    var quoteEl=document.getElementById('wsBookingQuote'), quote=null, quoteVersion=0, quoteTimer;
    function quoteParams(){var p=new URLSearchParams();['start_date','end_date','rooms_count','adults_count','children_count'].forEach(function(k){if(form.elements[k])p.set(k,form.elements[k].value);});new FormData(form).forEach(function(v,k){if(k==='extra_ids[]')p.append('extra_ids[]',v);});return p.toString();}
    function updateQuote(requestToken){var version=++quoteVersion;quote=null;if(!form.elements.start_date.value)return Promise.resolve(false);quoteEl.textContent='{$this->siteJs('جاري حساب السعر…')}';var params=quoteParams();var requestParams=params+(typeof requestToken==='string'?'&request_token='+encodeURIComponent(requestToken):'');return fetch(form.getAttribute('data-action').replace(/\/book$/, '/quote')+'?'+requestParams,{headers:{Accept:'application/json'}}).then(function(r){return r.json();}).then(function(r){if(version!==quoteVersion)return false;if(!r.success||!r.data){quoteEl.textContent=r.error||'{$this->siteJs('تعذر حساب السعر')}';return false;}quote={params:params,total:r.data.total};quoteEl.textContent='{$this->siteJs('الإجمالي')}: '+r.data.total+' '+r.data.currency+' ({$this->siteJs('البالغ')} '+r.data.adult_price+'{$this->siteJs('، الطفل')} '+r.data.child_price+'{$this->siteJs('، مع الإضافات المختارة')})';return true;}).catch(function(){if(version===quoteVersion)quoteEl.textContent='{$this->siteJs('تعذر حساب السعر؛ حاول بعد التحقق من الاتصال')}.';return false;});}
    form.addEventListener('input',function(e){if(['start_date','end_date','rooms_count','adults_count','children_count','extra_ids[]'].indexOf(e.target.name)<0)return;quote=null;++quoteVersion;clearTimeout(quoteTimer);quoteTimer=setTimeout(updateQuote,300);});

    var requestStorageKey='tfBookingRequest:'+form.getAttribute('data-action');
    async function requestToken(payload){
        var cart=JSON.stringify([quoteParams(),payload.customer_name||'',payload.customer_phone||'',payload.customer_email||'']);
        var digest=cart;if(window.crypto&&window.crypto.subtle){var bytes=await window.crypto.subtle.digest('SHA-256',new TextEncoder().encode(cart));digest=Array.from(new Uint8Array(bytes)).map(function(v){return v.toString(16).padStart(2,'0');}).join('');}
        try{var old=JSON.parse(sessionStorage.getItem(requestStorageKey)||'null');if(old&&old.cart===digest&&/^[a-f0-9]{32}$/.test(old.token))return old.token;}catch(e){}
        var random=new Uint8Array(16);window.crypto.getRandomValues(random);var token=Array.from(random).map(function(v){return v.toString(16).padStart(2,'0');}).join('');
        try{sessionStorage.setItem(requestStorageKey,JSON.stringify({cart:digest,token:token}));}catch(e){}return token;
    }
    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        if(bookingSubmitting)return;bookingSubmitting=true;var submitButton=form.querySelector('button[type=submit]');submitButton.disabled=true;
        var payload = {};
        new FormData(form).forEach(function (value, key) { if(key==='extra_ids[]'){if(!payload.extra_ids)payload.extra_ids=[];payload.extra_ids.push(value);}else{payload[key]=value;} });
        try{payload.request_token=await requestToken(payload);}catch(e){bookingSubmitting=false;submitButton.disabled=false;msg.textContent='{$this->siteJs('تعذر تجهيز الطلب بأمان؛ حدّث المتصفح ثم حاول')}.';return;}
        if(!quote || quote.params!==quoteParams()){if(!await updateQuote(payload.request_token)){msg.textContent='{$this->siteJs('راجع السعر والتوفر قبل تأكيد الحجز')}.';bookingSubmitting=false;submitButton.disabled=false;return;}}
        if(!quote){bookingSubmitting=false;submitButton.disabled=false;return;}
        msg.className = 'ws-booking-msg';
        msg.textContent = '{$this->siteJs('جاري تأكيد الحجز')}...';
        payload.quoted_total=quote.total;
        var waToken=new URLSearchParams(window.location.search).get('wa_token');if(waToken&&/^[a-f0-9]{64}$/.test(waToken))payload.wa_token=waToken;
        var emToken=new URLSearchParams(window.location.search).get('tf_em');if(emToken&&/^[a-f0-9]{32,64}$/.test(emToken))payload.em_token=emToken;
        fetch(form.getAttribute('data-action'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (res) { return res.json(); }).then(function (res) {
            if(res.success){try{sessionStorage.removeItem(requestStorageKey);}catch(e){}}
            if (res.success && res.data && res.data.checkout_url) {
                window.location.href = res.data.checkout_url;
                return;
            }
            if (res.success) {
                var text = (res.message || '{$this->siteJs('تم استلام طلبك')}');
                if (res.data && res.data.booking_reference) {
                    text += ' - {$this->siteJs('كود الحجز')}: ' + res.data.booking_reference;
                }
                msg.className = 'ws-booking-msg ws-ok';
                msg.textContent = text;
            } else {
                bookingSubmitting=false;submitButton.disabled=false;
                msg.className = 'ws-booking-msg ws-pending';
                msg.textContent = res.error || '{$this->siteJs('حصل خطأ')} - {$this->siteJs('جرب تاني أو تواصل عبر واتساب')}';
            }
        }).catch(function () {
            msg.className = 'ws-booking-msg ws-pending';
            msg.textContent = '{$this->siteJs('تعذر تأكيد نتيجة الطلب')}. {$this->siteJs('راجع الحجز مع الشركة قبل تكراره')}.';
        });
    });
})();
</script>
HTML;
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
}
