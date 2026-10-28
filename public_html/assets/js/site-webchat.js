/* كارت «شات الموقع» في صفحة الموقع: تشغيل/إيقاف + كود التضمين للمواقع الخارجية + أرقام آخر 7 أيام. */
(function () {
    const root = document.getElementById('siteWebChat');
    if (!root || !window.Panel) return;
    const P = window.Panel;
    const id = root.getAttribute('data-site');
    const ar = (document.documentElement.lang || 'ar').toLowerCase().startsWith('ar');
    const t = (a, e) => (ar ? a : e);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const API = '/api/websites/' + encodeURIComponent(id) + '/webchat';

    function render(d) {
        const state = !d.on ? ['#6b7280', t('مقفول', 'Off')] : d.live ? ['#16a34a', t('شغال', 'Live')] : ['#d97706', t('مستني باقة نشطة', 'Needs an active plan')];
        root.innerHTML = `<div class="p-card" style="margin-top:16px;">
            <div class="p-card-head"><h3>💬 ${t('شات الموقع', 'Website chat')}</h3>
                <span class="p-card-sub">${t('فقاعة شات على موقعك بيرد فيها نفس مساعد الواتساب الذكي — والمحادثات بتوصلك في صندوق الرسائل.', 'A chat bubble on your site answered by the same AI assistant as WhatsApp — conversations land in your inbox.')}</span></div>
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
                <span style="display:inline-flex;align-items:center;gap:6px;font-weight:600;"><i style="width:9px;height:9px;border-radius:50%;background:${state[0]};display:inline-block"></i>${state[1]}</span>
                <button type="button" class="p-btn ${d.on ? 'outline' : ''} sm" data-wc-toggle="${d.on ? 0 : 1}">${d.on ? t('اقفل الشات', 'Turn off') : t('شغّل الشات', 'Turn on')}</button>
                ${d.on ? `<span class="p-cell-muted">${t('آخر 7 أيام', 'Last 7 days')}: <b>${d.week_visitors}</b> ${t('زائر', 'visitors')} · <b>${d.week_messages}</b> ${t('رسالة', 'messages')}</span>` : ''}
                <a href="/chat" class="p-btn outline sm" style="margin-inline-start:auto">${t('صندوق الرسائل', 'Open inbox')} ↗</a>
            </div>
            ${d.has_built_site ? `<p class="p-cell-muted" style="margin:0 0 10px">✅ ${t('موقعك المبني على Tourfecto عليه الشات تلقائي — مش محتاج تعمل حاجة.', 'Your Tourfecto-built site gets the chat automatically — nothing to install.')}</p>` : ''}
            <label style="font-weight:600;display:block;margin-bottom:6px">${t('لموقع تاني (WordPress، Wix، Shopify…): الصق السطر ده قبل &lt;/body&gt;', 'For any other site (WordPress, Wix, Shopify…): paste this line before &lt;/body&gt;')}</label>
            <div style="display:flex;gap:8px;align-items:stretch">
                <code dir="ltr" style="flex:1;display:block;padding:10px 12px;border-radius:10px;background:rgba(127,127,127,.14);color:inherit;border:1px solid rgba(127,127,127,.25);font-size:12px;overflow-x:auto;white-space:nowrap">${esc(d.snippet)}</code>
                <button type="button" class="p-btn outline sm" data-wc-copy>${t('نسخ', 'Copy')}</button>
            </div>
            <p class="p-cell-muted" style="margin:8px 0 0;font-size:12px">${t('الشات بيتكلم بلغة صفحة الزائر، ولو البوت محتاج موافقتك أو حوّلت المحادثة لموظف، الزائر بيستلم الرد في نفس الفقاعة.', 'The chat speaks the visitor’s page language. If replies need your approval or you take over, the visitor gets your reply in the same bubble.')}</p>
        </div>`;
        root._d = d;
    }

    async function load() {
        const res = await P.fetchJSON(API);
        if (res && res.success) render(res.data);
    }

    root.addEventListener('click', async e => {
        const tg = e.target.closest('[data-wc-toggle]');
        if (tg) {
            tg.disabled = true;
            const res = await P.fetchJSON(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ on: tg.getAttribute('data-wc-toggle') === '1' }) });
            if (res && res.success) render(res.data); else { tg.disabled = false; alert((res && res.error) || 'Error'); }
            return;
        }
        if (e.target.closest('[data-wc-copy]') && root._d) {
            try { await navigator.clipboard.writeText(root._d.snippet); e.target.textContent = t('اتنسخ ✓', 'Copied ✓'); } catch (err) { /* المتصفح منع النسخ */ }
        }
    });
    load();
})();
