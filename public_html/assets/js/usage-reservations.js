(function () {
    'use strict';
    const list = document.getElementById('urList'), notice = document.getElementById('urNotice');
    if (!list) return;
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    let busy = false;
    async function request(url, body) {
        const r = await fetch(url, {method: body ? 'POST' : 'GET', credentials:'same-origin',
            headers:{'Accept':'application/json', ...(body ? {'Content-Type':'application/json'} : {})},
            ...(body ? {body:JSON.stringify(body)} : {})});
        const data = await r.json();
        if (!r.ok || !data.success) throw new Error(data.error || 'تعذر تنفيذ الطلب');
        return data.data;
    }
    async function load() {
        const d = await request('/api/admin/usage-reservations');
        list.innerHTML = [...(d.reservations || []).map(r=>({...r,kind:'billing'})),...(d.service_reservations||[]).map(r=>({...r,kind:'service'}))].map(r => '<article style="border-bottom:1px solid #8884;padding:16px 0">'
            + '<strong>#' + esc(r.user_id) + ' · ' + esc(r.feature) + ' · ' + esc(r.state) + '</strong>'
            + '<p>' + esc(r.source) + ' · ' + esc(r.amount) + ' USD · ' + esc(r.quantity) + ' units · ' + esc(r.created_at) + '</p>'
            + '<code>' + esc(r.token) + '</code>'
            + (d.can_resolve ? '<form data-kind="'+esc(r.kind)+'" data-token="' + esc(r.token) + '"><p><label>نتيجة مؤكدة <select name="outcome" required><option value="">اختر النتيجة</option><option value="completed">تم التنفيذ — احتساب الاستخدام</option><option value="released">لم ينفذ — إعادة الرصيد/الحصة</option></select></label></p>'
                + '<label>دليل المراجعة / مرجع الطلب <textarea name="evidence" minlength="12" maxlength="1000" required style="display:block;width:100%;max-width:640px"></textarea></label><button class="p-btn" type="submit">تأكيد التسوية</button></form>' : '') + '</article>').join('') || '<p>لا يوجد استخدام مالي معلق.</p>';
        document.getElementById('urWhatsApp').innerHTML = (d.whatsapp || []).map(r => '<p>#' + esc(r.id) + ' · site ' + esc(r.website_id) + ' · ' + esc(r.kind) + ' · ' + esc(r.state) + ' · attempts ' + esc(r.attempts) + ' · job #' + esc(r.job_id) + ' · ' + esc(r.last_error) + '</p>').join('') || '<p>لا توجد أحداث واتساب معلقة.</p>';
    }
    list.addEventListener('submit', async e => {
        e.preventDefault(); if (busy) return;
        const form = e.target; if (!form.matches('form[data-token]') || !form.reportValidity()) return;
        busy = true; const button = form.querySelector('button'); button.disabled = true;
        try {
            const data = new FormData(form);
            await request('/api/admin/usage-reservations/' + encodeURIComponent(form.dataset.token) + '/resolve',
                {kind:form.dataset.kind,outcome:data.get('outcome'), evidence:data.get('evidence'), csrf_token:notice.dataset.csrf});
            notice.textContent = 'تمت التسوية.'; await load();
        } catch (err) {notice.textContent = err.message; button.disabled = false;}
        finally {busy = false;}
    });
    load().catch(err => { notice.textContent = err.message; });
})();
