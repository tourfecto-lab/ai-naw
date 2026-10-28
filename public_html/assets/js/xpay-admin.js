/* Tourfecto - أدمن: ربط XPay (الدفع بالكارت لشحن محفظة العميل) */
(() => {
    'use strict';
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    let P, root, D;
    const api = async (url, body) => { const r = await P.fetchJSON(url, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...body, csrf_token: D?.csrf_token }) } : undefined); if (!r?.success) throw new Error(r?.error || 'حصلت مشكلة'); return r; };
    const msg = (m, bad) => { const el = root.querySelector('#xpMsg'); el.textContent = m; el.className = 'ps-msg' + (bad ? ' bad' : ''); el.hidden = !m; };
    const run = async (b, fn) => { b.disabled = true; try { await fn(); } catch (e) { msg(e.message, true); } finally { b.disabled = false; } };
    const ST = { pending: 'مستني الدفع', processing: 'محتاج مراجعة', succeeded: 'اتدفع ✅', failed: 'فشل', cancelled: 'اتلغى/انتهى' };
    const copyBtn = v => `<button type="button" class="p-btn sm" data-copy="${esc(v)}">نسخ</button>`;

    const render = () => {
        const c = D.config, lt = c.last_test;
        const state = !c.has_secret ? '<span class="xp-pill off">مش متربط</span>' : c.enabled ? `<span class="xp-pill on">شغّال ${c.mode === 'live' ? '(حقيقي)' : '(تجريبي)'}</span>` : '<span class="xp-pill">متربط بس مقفول</span>';
        root.innerHTML = `<h3>💳 ربط XPay (الدفع بالكارت) ${state}</h3>
<p class="p-cell-muted">العميل بيشحن محفظته بالكارت أو المحافظ عن طريق XPay، والرصيد بيتشحن على طول من غير موافقتك. المفاتيح بتجيبها من لوحة XPay ← Developers ← API keys. ابدأ بمفاتيح <b>test</b> وجرّب، وبعدين حط مفاتيح <b>live</b>.</p>
<div id="xpMsg" class="ps-msg" hidden></div>
<div class="xp-grid">
  <label>المفتاح العام (Publishable key)<input id="xpPk" class="p-select" dir="ltr" placeholder="pk_test_..." value="${esc(c.public_key)}" autocomplete="off"></label>
  <label>المفتاح السري (Secret key)<input id="xpSk" class="p-select" dir="ltr" type="password" placeholder="${c.has_secret ? 'محفوظ: ' + esc(c.secret_hint) + ' - اكتب جديد لو عايز تغيّره' : 'sk_test_...'}" autocomplete="new-password"></label>
  <label>العميل يدفع بـ<select id="xpCur" class="p-select"><option value="EGP" ${c.charge_currency === 'EGP' ? 'selected' : ''}>الجنيه المصري</option><option value="USD" ${c.charge_currency === 'USD' ? 'selected' : ''} ${c.account_currency === 'EGP' ? 'disabled' : ''}>الدولار${c.account_currency === 'EGP' ? ' (حسابك في XPay بالجنيه بس)' : ' (لو XPay فاتحه لك)'}</option></select></label>
  <label>أقل مبلغ شحن $<input id="xpMin" class="p-select" type="number" min="1" value="${c.min_usd}"></label>
  <label>أكبر مبلغ شحن $<input id="xpMax" class="p-select" type="number" min="10" max="10000" value="${c.max_usd}"></label>
  <label>مفتاح الـ Webhook (Signing secret)<input id="xpWh" class="p-select" dir="ltr" type="password" placeholder="${c.has_webhook_secret ? 'محفوظ ✓ - اكتب جديد لو اتغيّر' : 'whsec_... (اللي XPay بيطلعه بعد ما تضيف الـ Webhook)'}" autocomplete="new-password"></label>
  <label>عنوان الـ API (سيبه زي ما هو إلا لو XPay قالك غير كده)<input id="xpApi" class="p-select" dir="ltr" value="${esc(c.api_base)}" placeholder="https://api.xpay.app"></label>
  <label class="xp-check"><input type="checkbox" id="xpOn" ${c.enabled ? 'checked' : ''}> فعّل الدفع بالكارت للعملاء</label>
</div>
<p class="p-cell-muted">مثال: شحن $50 ← العميل هيدفع <b dir="ltr">${esc(D.example.label)}</b>. المحفظة دايمًا بتتشحن بالدولار.</p>
<button class="p-btn primary" data-xp-save>حفظ</button> <button class="p-btn" data-xp-test ${c.has_secret ? '' : 'disabled'}>اختبار الاتصال</button>
${lt ? `<span class="p-cell-muted"> آخر اختبار: ${lt.ok ? '✅ شغال' : '❌ فشل'} (${esc(lt.at)})</span>` : ''}
${c.last_error ? `<div class="ps-msg bad" style="margin-top:10px"><b>آخر مشكلة من XPay (${esc(c.last_error.at)} - ${esc(c.last_error.stage || '')}):</b><br><code dir="ltr" style="white-space:pre-wrap;word-break:break-word">${esc(c.last_error.detail || '')}</code><br><span class="p-cell-muted">ابعت السطر ده للدعم الفني بتاع XPay أو لينا.</span></div>` : ''}
${lt && lt.detail ? `<div class="p-cell-muted" style="margin-top:6px">رد XPay على الاختبار: <code dir="ltr">${esc(lt.detail)}</code></div>` : ''}
<div class="xp-urls"><b>حط الرابط ده في لوحة XPay ← Webhooks</b> (الأحداث: checkout.session.completed و checkout.session.expired):
<div class="xp-url"><code dir="ltr">${esc(D.webhook_url)}</code>${copyBtn(D.webhook_url)}</div>
<span class="p-cell-muted">بعد ما تضيفه، XPay هيطلعلك <b>Signing secret</b> (بيبدأ غالبًا بـ whsec_): انسخه وحطه في خانة "مفتاح الـ Webhook" فوق ودوس حفظ.</span><br>
<span class="p-cell-muted">رابط رجوع العميل بعد الدفع (بيتبعت لوحده مع كل عملية): <code dir="ltr">${esc(D.return_url)}</code></span>
<div class="p-cell-muted" style="margin-top:6px">${c.last_webhook ? `آخر Webhook وصل: ${esc(c.last_webhook.at)} · ${esc(c.last_webhook.type || '-')} · التوقيع: ${({ ok: '✅ مظبوط', mismatch: '⚠️ مش مطابق - راجع المفتاح', none: 'مفيش توقيع', no_secret: 'حط مفتاح الـ Webhook' })[c.last_webhook.signature] || '-'}` : 'لسه مفيش Webhook وصل من XPay.'}</div></div>
${D.recent.length ? `<table><thead><tr><th>#</th><th>العميل</th><th>شحن</th><th>اتدفع</th><th>الحالة</th><th>الوقت</th></tr></thead><tbody>${D.recent.map(r => `<tr><td>${r.id}</td><td>${esc(r.company_name || r.user_id)}</td><td>$${esc(r.amount)}</td><td dir="ltr">${esc(r.charged)}</td><td>${esc(ST[r.status] || r.status)}</td><td>${esc(String(r.created_at).slice(0, 16))}</td></tr>`).join('')}</tbody></table>` : '<p class="p-cell-muted">لسه مفيش عمليات دفع.</p>'}`;
    };

    const bind = () => root.addEventListener('click', e => {
        const b = e.target.closest('button'); if (!b) return;
        if (b.dataset.copy) { navigator.clipboard?.writeText(b.dataset.copy); b.textContent = 'اتنسخ ✓'; setTimeout(() => { b.textContent = 'نسخ'; }, 1500); return; }
        if (b.dataset.xpSave !== undefined) return run(b, async () => {
            const body = { public_key: root.querySelector('#xpPk').value.trim(), charge_currency: root.querySelector('#xpCur').value, min_usd: +root.querySelector('#xpMin').value, max_usd: +root.querySelector('#xpMax').value, api_base: root.querySelector('#xpApi').value.trim(), enabled: root.querySelector('#xpOn').checked };
            const sk = root.querySelector('#xpSk').value.trim(); if (sk) body.secret_key = sk;
            const wh = root.querySelector('#xpWh').value.trim(); if (wh) body.webhook_secret = wh;
            await api('/api/admin/xpay/save', body);
            D = (await api('/api/admin/xpay')).data; render(); msg('✅ اتحفظت إعدادات XPay' + (sk ? ' - دوس "اختبار الاتصال" عشان تتأكد من المفتاح' : ''));
        });
        if (b.dataset.xpTest !== undefined) return run(b, async () => { const r = await api('/api/admin/xpay/test', {}); D = (await api('/api/admin/xpay')).data; render(); msg((r.data && r.data.ok ? '' : '❌ ') + (r.message || ''), !(r.data && r.data.ok)); });
    });

    window.XPayAdmin = { init: async panel => { P = panel; root = document.getElementById('xpayAdmin'); if (!root) return; try { D = (await api('/api/admin/xpay')).data; bind(); render(); if (location.hash === '#xpay') root.scrollIntoView(); } catch (e) { root.innerHTML = '<p class="ps-msg bad">' + esc(e.message) + '</p>'; } } };
})();
