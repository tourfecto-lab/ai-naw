/* الدفع اليدوي (تحويل بنكي / PayPal / InstaPay / محفظة موبايل):
 * العميل بيشوف بيانات الدفع اللي الأدمن كتبها، يحوّل، ويضغط «حوّلت».
 * لو التحويل لباقة: أول ما الأدمن يوافق الباقة بتتفعّل لوحدها. */
(function () {
    const P = window.Panel || {};
    const ar = (document.documentElement.lang || 'ar').toLowerCase().startsWith('ar');
    const t = (a, e) => (ar ? a : e);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const FIELD_EN = { 'البنك': 'Bank', 'اسم صاحب الحساب': 'Account name', 'رقم المحفظة': 'Wallet number', 'الاسم': 'Name' };

    let info = null;
    let state = { method: null, plan: null, cycle: null, amount: null, name: '' };

    async function loadInfo() {
        if (info) return info;
        try {
            const res = await P.fetchJSON('/api/wallet/balance');
            info = (res && res.success && res.data.payment_info) || {};
        } catch (e) { info = {}; }
        return info;
    }

    function waNumber() { return String((info && info.whatsapp_number) || '').replace(/\D/g, ''); }

    function render() {
        const box = document.getElementById('mpBody');
        if (!box) return;
        const methods = (info && info.methods) || [];
        if (!state.method || !methods.some(m => m.key === state.method)) state.method = methods.length ? methods[0].key : null;
        const planLine = state.plan
            ? `<div class="mp-plan"><span>${t('الباقة', 'Plan')}</span><b>${esc(state.name)} · ${state.cycle === 'yearly' ? t('سنوي', 'Yearly') : t('شهري', 'Monthly')}</b><b dir="ltr">$${Number(state.amount).toFixed(2)}</b></div>`
            : `<label class="form-label">${t('المبلغ بالدولار', 'Amount (USD)')}</label><input type="number" id="mpAmount" class="form-control" min="1" step="0.01" value="${state.amount ? esc(state.amount) : ''}" style="margin-bottom:12px">`;
        if (!methods.length) {
            const wa = waNumber();
            box.innerHTML = planLine + `<div class="mp-empty">${t('بيانات التحويل بتتبعت لك مباشرة.', 'We will send you the transfer details directly.')}
                ${wa ? `<a class="p-btn primary" target="_blank" rel="noopener" href="https://wa.me/${wa}?text=${encodeURIComponent(t('أهلاً، عايز أدفع ', 'Hi, I want to pay for ') + (state.plan ? state.name + ' (' + state.cycle + ')' : t('شحن رصيد', 'a wallet top-up')))}">💬 ${t('ابعتلنا على واتساب', 'Message us on WhatsApp')}</a>` : ''}</div>`;
            document.getElementById('mpSubmit').style.display = 'none';
            return;
        }
        document.getElementById('mpSubmit').style.display = '';
        const m = methods.find(x => x.key === state.method);
        const tabs = methods.length > 1
            ? `<div class="p-tabs mp-tabs" role="tablist">${methods.map(x => `<button type="button" role="tab" class="p-tab${x.key === state.method ? ' active' : ''}" data-mp="${esc(x.key)}">${x.icon} ${esc(ar ? x.label : x.label_en)}</button>`).join('')}</div>`
            : `<div class="mp-one">${m.icon} ${esc(ar ? m.label : m.label_en)}</div>`;
        const fields = m.fields.map(f => `<div class="mp-row"><span class="k">${esc(ar ? f[0] : (FIELD_EN[f[0]] || f[0]))}</span><span class="v"${f[2] ? ' dir="ltr"' : ''}>${esc(f[1])}</span><button type="button" class="p-btn outline xs" data-copy="${esc(f[1])}">${t('نسخ', 'Copy')}</button></div>`).join('')
            + (m.link ? `<a class="p-btn primary btn-block" style="margin-top:8px" target="_blank" rel="noopener" href="${esc(m.link)}${state.amount ? '/' + Number(state.amount).toFixed(2) + 'USD' : ''}">${t('ادفع على PayPal', 'Pay with PayPal')} ↗</a>` : '');
        const note = info.instructions ? `<div class="mp-note">${esc(info.instructions)}</div>` : '';
        box.innerHTML = planLine
            + `<ol class="mp-steps"><li><b>1</b>${t('حوّل المبلغ على أي طريقة من دول', 'Send the amount using any method below')}</li><li><b>2</b>${t('اضغط «حوّلت»', 'Tap “I\'ve paid”')}</li><li><b>3</b>${state.plan ? t('اشتراكك بيتفعّل أول ما نأكد التحويل', 'Your plan activates as soon as we confirm it') : t('الرصيد بيتضاف أول ما نأكد التحويل', 'Balance is added once we confirm it')}</li></ol>`
            + tabs + `<div class="mp-fields">${fields}</div>` + note
            + `<label class="form-label" style="margin-top:12px">${t('رقم العملية أو اسم المحوِّل (يسرّع التأكيد)', 'Transfer reference or sender name (speeds up confirmation)')}</label>
               <input type="text" id="mpNote" class="form-control" maxlength="200">
               <div id="mpAlert" class="alert alert-danger" style="display:none;margin-top:10px"></div>`;
    }

    async function open(opts) {
        state = Object.assign({ method: state.method, plan: null, cycle: null, amount: null, name: '' }, opts || {});
        if (P.openModal) P.openModal('depositModal');
        const box = document.getElementById('mpBody');
        if (box && !info) box.innerHTML = `<div class="p-empty">${t('جارِ التحميل...', 'Loading...')}</div>`;
        await loadInfo();
        render();
    }

    async function submit() {
        const btn = document.getElementById('mpSubmit');
        const alertBox = document.getElementById('mpAlert');
        if (alertBox) alertBox.style.display = 'none';
        const amount = state.plan ? state.amount : Number((document.getElementById('mpAmount') || {}).value || 0);
        if (!amount || amount <= 0) {
            if (alertBox) { alertBox.textContent = t('اكتب المبلغ اللي حوّلته', 'Enter the amount you sent'); alertBox.style.display = 'block'; }
            return;
        }
        btn.disabled = true;
        const body = { amount, payment_method: state.method, note: (document.getElementById('mpNote') || {}).value || '' };
        if (state.plan) body.plan = state.plan + ':' + state.cycle;
        let res;
        try {
            res = await P.fetchJSON('/api/wallet/deposit', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        } catch (e) { res = { success: false }; }
        btn.disabled = false;
        if (res && res.success) {
            document.getElementById('mpBody').innerHTML = `<div class="mp-done"><div class="mp-done-ic">✓</div><b>${t('وصلنا إشعار التحويل', 'We got your payment notice')}</b><p>${state.plan ? t('أول ما نأكد استلام التحويل اشتراكك هيتفعّل لوحده وهتوصلك رسالة.', 'As soon as we confirm the transfer, your plan activates automatically and we will notify you.') : t('أول ما نأكد استلام التحويل الرصيد هيتضاف لمحفظتك.', 'Once we confirm the transfer, the balance is added to your wallet.')}</p>
                ${waNumber() ? `<a class="p-btn outline" target="_blank" rel="noopener" href="https://wa.me/${waNumber()}?text=${encodeURIComponent(t('أهلاً، حوّلت ', 'Hi, I sent ') + '$' + Number(amount).toFixed(2) + (state.plan ? ' - ' + state.name + ' (' + state.cycle + ')' : '') + t(' - هبعتلك صورة التحويل', ' - sending the receipt'))}">📎 ${t('ابعت صورة التحويل على واتساب (اختياري)', 'Send the receipt on WhatsApp (optional)')}</a>` : ''}</div>`;
            btn.style.display = 'none';
            if (typeof window.loadWalletHistory === 'function') window.loadWalletHistory();
        } else if (alertBox) {
            alertBox.textContent = (res && res.error) || t('ماقدرناش نسجّل الطلب، جرّب تاني', 'Could not save, please try again');
            alertBox.style.display = 'block';
        }
    }

    document.addEventListener('click', function (e) {
        const tab = e.target.closest('[data-mp]');
        if (tab) { state.method = tab.getAttribute('data-mp'); render(); return; }
        const cp = e.target.closest('[data-copy]');
        if (cp) {
            const v = cp.getAttribute('data-copy');
            (navigator.clipboard ? navigator.clipboard.writeText(v) : Promise.reject()).then(
                () => { cp.textContent = t('اتنسخ ✓', 'Copied ✓'); setTimeout(() => { cp.textContent = t('نسخ', 'Copy'); }, 1500); },
                () => { if (P.toast) P.toast(v, 'info'); });
            return;
        }
        const pay = e.target.closest('[data-manual-pay]');
        if (pay) {
            e.preventDefault();
            open({ plan: pay.getAttribute('data-plan') || null, cycle: pay.getAttribute('data-cycle') || null, amount: Number(pay.getAttribute('data-amount') || 0) || null, name: pay.getAttribute('data-name') || '' });
        }
    });

    window.ManualPay = { open, submit, setInfo: function (i) { info = i || {}; } };
})();
