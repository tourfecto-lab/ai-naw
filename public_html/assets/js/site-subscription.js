/* اشتراك الموقع (منشئ المواقع): الحالة، السعر (وخصم مشتركين المنصة)، الدفع من الرصيد أو بالتحويل */
(function () {
    const P = window.Panel || {};
    const ar = (document.documentElement.lang || 'ar').toLowerCase().startsWith('ar');
    const t = (a, e) => (ar ? a : e);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const money = v => '$' + Number(v || 0).toFixed(Number(v) % 1 ? 2 : 0);
    const day = v => (v ? String(v).slice(0, 10) : '');

    let cur = null;
    let cycle = 'monthly';

    function pill(w) {
        const s = w && w.site_sub;
        if (!s || s.state === 'off') return '';
        const map = {
            active: ['green', t('شغال لحد ', 'Live until ') + day(s.until)],
            grace: ['orange', t('جدّد قبل ', 'Renew by ') + day(s.until)],
            expired: ['red', t('متوقف', 'Paused')],
            none: ['gray', t('من غير اشتراك', 'No plan')],
        };
        const m = map[s.state] || map.none;
        return ` <span class="ss-pill ${m[0]}">${esc(m[1])}</span>`;
    }

    function stateLine(d) {
        if (d.state === 'active') return `<div class="ss-state ok">✅ ${t('الموقع شغال لحد', 'Your site is live until')} <b dir="ltr">${day(d.live_until)}</b>${d.source === 'grandfathered' ? ' · ' + t('شهر مجاني هدية', 'free gift month') : ''}</div>`;
        if (d.state === 'grace') return `<div class="ss-state warn">⏳ ${t('الاشتراك خلص - الموقع شغال لحد', 'Plan ended - your site stays live until')} <b dir="ltr">${day(d.live_until)}</b>. ${t('جدّد عشان مايقفش.', 'Renew to keep it online.')}</div>`;
        if (d.state === 'expired') return `<div class="ss-state bad">⛔ ${t('الموقع متوقف والزوار بيشوفوا صفحة «متوقف مؤقتًا». جدّد ويرجع فورًا بكل بياناته.', 'Your site is paused - visitors see a “temporarily unavailable” page. Renew and it is back instantly with all its data.')}</div>`;
        return `<div class="ss-state">${t('موقعك جاهز كمعاينة. فعّل الاشتراك عشان تنشره للزوار وتستقبل حجوزات.', 'Your site is ready as a preview. Activate a plan to publish it and take bookings.')}</div>`;
    }

    function render() {
        const d = cur;
        const box = document.getElementById('ssBody');
        if (!box || !d) return;
        const p = d.prices;
        const price = p[cycle];
        const list = p['list_' + cycle];
        const yearlySave = p.list_monthly > 0 ? Math.max(0, Math.round((1 - p.list_yearly / (p.list_monthly * 12)) * 100)) : 0;
        const canWallet = Number(d.balance) >= price;
        const inc = [
            t('موقع كامل متعدد الصفحات بتصميم احترافي', 'Full multi-page website with a professional design'),
            t('حجز أونلاين واستقبال طلبات الحجز', 'Online booking and booking requests'),
            t('دومين خاص بيك + SSL', 'Your own custom domain + SSL'),
            t('صفحة لكل رحلة + خريطة موقع لجوجل', 'A page per tour + Google sitemap'),
            t('رحلات وتعديلات بلا حدود', 'Unlimited tours and edits'),
        ];
        // خدمات المنصة اللي شغالة فعلًا لصاحب الموقع (الشات الذكي على الموقع لسه مش مربوط - مابنوعدش بيه)
        const plat = [
            t('محتوى SEO أسبوعي لنشاطك', 'Weekly SEO content for your business'),
            t('رد تلقائي على مراجعات جوجل', 'Automatic Google review replies'),
        ];
        box.innerHTML = `
            <div class="ss-name">${esc(d.name)}</div>
            ${stateLine(d)}
            <div class="p-tabs ss-cycle" role="tablist">
                <button type="button" class="p-tab${cycle === 'monthly' ? ' active' : ''}" data-ss-cycle="monthly">${t('شهري', 'Monthly')}</button>
                <button type="button" class="p-tab${cycle === 'yearly' ? ' active' : ''}" data-ss-cycle="yearly">${t('سنوي', 'Yearly')}${yearlySave ? ` <span class="ss-save">-${yearlySave}%</span>` : ''}</button>
            </div>
            <div class="ss-price"><b dir="ltr">${money(price)}</b><span>/${cycle === 'yearly' ? t('سنة', 'year') : t('شهر', 'month')}</span>${p.discount ? `<s dir="ltr">${money(list)}</s><em>${t('خصم مشتركين المنصة', 'Platform subscriber discount')} ${p.discount}%</em>` : ''}</div>
            ${!p.discount && p.platform_discount ? `<div class="ss-hint">💡 ${t('مشتركين المنصة بياخدوا خصم ' + p.platform_discount + '% على الموقع.', 'Platform subscribers get ' + p.platform_discount + '% off the website.')} <a href="/plans">${t('شوف الباقات', 'See plans')}</a></div>` : ''}
            <ul class="ss-list">${inc.map(x => `<li class="y">✓ ${esc(x)}</li>`).join('')}
                ${plat.map(x => `<li class="${d.platform ? 'y' : 'n'}">${d.platform ? '✓' : '＋'} ${esc(x)}${d.platform ? '' : ` <small>${t('مع اشتراك المنصة', 'with a platform plan')}</small>`}</li>`).join('')}</ul>
            <div class="ss-actions">
                ${canWallet ? `<button type="button" class="p-btn success btn-block" id="ssWalletBtn">💰 ${t('ادفع من رصيدك', 'Pay from your balance')} (${money(d.balance)} ${t('متاح', 'available')})</button>` : ''}
                <button type="button" class="p-btn ${canWallet ? 'outline' : 'primary'} btn-block" id="ssTransferBtn">💸 ${t('ادفع بالتحويل (بنك / PayPal)', 'Pay by transfer (bank / PayPal)')}</button>
            </div>
            ${d.auto_renew !== null && d.state !== 'none' ? `<label class="ss-auto"><input type="checkbox" id="ssAuto"${d.auto_renew ? ' checked' : ''}> ${t('جدّد تلقائي من رصيد المحفظة في ميعاده', 'Auto-renew from my wallet balance on the due date')}</label>` : ''}
            <div id="ssAlert" class="alert alert-danger" style="display:none;margin-top:10px"></div>`;
    }

    async function open(id, fromPublish) {
        if (P.openModal) P.openModal('siteSubModal');
        const box = document.getElementById('ssBody');
        box.innerHTML = `<div class="p-empty">${t('جارِ التحميل...', 'Loading...')}</div>`;
        const res = await P.fetchJSON('/api/website-builder/' + id + '/subscription');
        if (!res || !res.success) { box.innerHTML = `<div class="alert alert-danger">${esc((res && res.error) || t('تعذر التحميل', 'Could not load'))}</div>`; return; }
        cur = Object.assign({ id }, res.data);
        cur.fromPublish = !!fromPublish;
        cycle = cur.cycle === 'yearly' ? 'yearly' : 'monthly';
        render();
    }

    async function payWallet(btn) {
        btn.disabled = true;
        const key = (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random()).replace(/[^a-zA-Z0-9]/g, '').slice(0, 40);
        const res = await P.fetchJSON('/api/website-builder/' + cur.id + '/subscription', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ cycle, idempotency_key: key }) });
        btn.disabled = false;
        if (res && res.success) {
            if (P.toast) P.toast(res.message || t('اتفعّل', 'Activated'), 'success');
            if (cur.fromPublish && window.publishSite) { P.closeModal('siteSubModal'); window.publishSite(cur.id); return; }
            await open(cur.id);
            if (window.loadMyWebsites) window.loadMyWebsites();
        } else {
            const a = document.getElementById('ssAlert');
            a.textContent = (res && res.error) || t('تعذر الدفع', 'Payment failed');
            a.style.display = 'block';
        }
    }

    document.addEventListener('click', function (e) {
        const c = e.target.closest('[data-ss-cycle]');
        if (c) { cycle = c.getAttribute('data-ss-cycle'); render(); return; }
        if (e.target.closest('#ssWalletBtn')) { payWallet(e.target.closest('#ssWalletBtn')); return; }
        if (e.target.closest('#ssTransferBtn') && cur) {
            P.closeModal('siteSubModal');
            if (window.ManualPay) ManualPay.open({ plan: 'site-' + cur.id, cycle, amount: cur.prices[cycle], name: t('موقع ', 'Website ') + cur.name });
        }
    });
    document.addEventListener('change', async function (e) {
        if (e.target.id !== 'ssAuto' || !cur) return;
        const res = await P.fetchJSON('/api/website-builder/' + cur.id + '/subscription/auto-renew', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ on: e.target.checked }) });
        if (P.toast) P.toast((res && (res.message || res.error)) || '', res && res.success ? 'success' : 'error');
    });

    window.SiteSub = { open, pill };
})();
