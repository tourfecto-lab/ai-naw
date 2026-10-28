/* Tourfecto - أدمن: الباقات المقترحة، الإضافات، ابني باقتك، أسعار الصرف */
(() => {
    'use strict';
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    let P, root, D;
    const api = async (url, body) => { const r = await P.fetchJSON(url, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...body, csrf_token: D?.csrf_token }) } : undefined); if (!r?.success) throw new Error(r?.error || 'حصلت مشكلة'); return r; };
    const msg = (m, bad) => { const el = root.querySelector('#paMsg'); el.textContent = m; el.className = 'ps-msg' + (bad ? ' bad' : ''); el.hidden = !m; el.scrollIntoView({ block: 'nearest' }); };
    const run = async (b, fn) => { b.disabled = true; try { await fn(); } catch (e) { msg(e.message, true); } finally { b.disabled = false; } };
    const v = id => root.querySelector('#' + id);
    const render = () => {
        const c = D.config, R = D.rates, plans = D.plans;
        root.innerHTML = `<div id="paMsg" class="ps-msg" hidden></div>
<section class="ps-box"><h3>1) الباقات الأساسية</h3><p class="p-cell-muted">الأسعار الحالية: ${Object.entries(plans).map(([k, p]) => `<b>${esc(p.name)}</b> $${p.price_monthly}/شهر`).join(' · ')}. تقدر تطبّق التشكيلة المقترحة (معمولة لشركات السياحة) وبعدين تعدّل أي رقم من صفحة <a href="/admin/plans">الباقات</a>.</p>
<div class="ps-preset">${Object.entries(D.preset).map(([k, p]) => `<div><b>${esc(p.name)}</b> — $${p.price_monthly}/شهر · $${p.price_yearly}/سنة<br>${p.multiple_websites} موقع · ${p.chat_credits.toLocaleString()} رد شات · ${p.review_credits} رد تقييم · ${p.ai_analysis} تحليل · ${p.extra.articles} مقال · ${p.extra.media_images} صورة · ${p.extra.media_videos} فيديو${p.auto_pilot ? ' · الطيار الآلي' : ''}${p.advanced_analytics ? ' · تحليلات متقدمة' : ''}</div>`).join('')}</div>
<button class="p-btn" data-preset>طبّق الباقات المقترحة</button> <span class="p-cell-muted">المشتركين الحاليين بيكملوا بسعرهم لحد التجديد.</span></section>

<section class="ps-box ps-adm"><h3>2) التجربة المجانية</h3><p class="p-cell-muted">مرة واحدة لكل حساب جديد. كل خدمة ليها استخدام بسيط في التجربة، ولما يخلص يخلص (العميل يشترك أو يدفع من محفظته).</p><div class="ps-cur-plan"><label><input type="checkbox" id="paTrial" ${c.trial_enabled ? 'checked' : ''}> مفعّلة للحسابات الجديدة</label>
<label>المدة <input id="paTrialDays" type="number" class="p-select" min="1" max="60" value="${c.trial_days}" style="width:70px"> أيام</label>
<label>على باقة <select id="paTrialPlan" class="p-select">${Object.entries(plans).map(([k, p]) => `<option value="${k}" ${k === c.trial_plan ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select></label></div>
<div class="ps-cur-plan" style="margin-top:8px"><label><input type="checkbox" id="paTrialCard" ${c.trial_requires_card ? 'checked' : ''}> لازم العميل يأكد كارته الأول (XPay)</label>
<label>الطريقة <select id="paTrialMode" class="p-select"><option value="prepay" ${c.trial_card_mode === 'prepay' ? 'selected' : ''}>يدفع أول شهر مقدّم ← خصم تلقائي بعد التجربة</option><option value="verify" ${c.trial_card_mode === 'verify' ? 'selected' : ''}>تأكيد كارت بمبلغ صغير بس</option></select></label>
<label>مبلغ التأكيد الصغير $ <input id="paTrialCardUsd" type="number" class="p-select" min="0.5" max="20" step="0.5" value="${c.trial_card_usd}" style="width:80px"></label></div>
<p class="p-cell-muted">"أول شهر مقدّم": العميل بيدفع سعر الباقة بالكارت ويتحط في محفظته، والتجربة تبدأ. لما الأيام المجانية تخلص الاشتراك بيتخصم من المحفظة تلقائي، ولو لغى قبلها مفيش خصم والفلوس تفضل في محفظته. (XPay لسه مش بيدعم حفظ الكارت والخصم منه بعدين - لما يفعّلوه نحوّلها لخصم مباشر من الكارت.)</p>
<table><thead><tr><th>الخدمة</th><th>حد التجربة</th></tr></thead><tbody>${Object.entries(c.trial_caps || {}).map(([k, n]) => `<tr><td>${esc((D.trial_labels || {})[k] || k)}</td><td><input class="p-select" type="number" min="0" data-tc="${k}" value="${n}"></td></tr>`).join('')}</tbody></table>
<div class="xp-urls"><b>🎁 فعّل تجربة مجانية لعميل من غير كارت</b><div class="ps-cur-plan" style="margin-top:8px"><input id="paGrantEmail" class="p-select" dir="ltr" type="email" placeholder="customer@company.com" style="max-width:280px"><button class="p-btn primary" data-grant-trial>فعّل التجربة</button></div><span class="p-cell-muted">للعميل اللي مش عايز يدخل كارت: بتبدأ له نفس التجربة بنفس الحدود.</span></div></section>

<section class="ps-box ps-adm"><h3>3) إضافات حسب الطلب (مرة واحدة - 30 يوم)</h3><table><thead><tr><th>الإضافة</th><th>الكمية</th><th>السعر $</th><th>متاحة</th></tr></thead><tbody>${Object.entries(c.packs).map(([k, p]) => `<tr><td>${p.icon} ${esc(c.services[p.service].ar)}</td><td><input class="p-select" type="number" min="1" data-pq="${k}" value="${p.qty}"></td><td><input class="p-select" type="number" min="0" step="0.5" data-pp="${k}" value="${p.price}"></td><td><input type="checkbox" data-pe="${k}" ${p.enabled ? 'checked' : ''}></td></tr>`).join('')}</tbody></table></section>

<section class="ps-box ps-adm"><h3>4) ابني باقتك - سعر الوحدة شهريًا</h3><label><input type="checkbox" id="paBuilder" ${c.builder_enabled ? 'checked' : ''}> مفعّل</label><table><thead><tr><th>الخدمة</th><th>سعر الوحدة $</th><th>مثال</th><th>متاحة</th></tr></thead><tbody>${Object.entries(c.services).map(([k, s]) => `<tr><td>${esc(s.ar)}</td><td><input class="p-select" type="number" min="0" step="0.001" data-su="${k}" value="${s.unit}"></td><td class="p-cell-muted">+${s.step} = $${(s.step * s.unit).toFixed(2)}/شهر</td><td><input type="checkbox" data-se="${k}" ${s.enabled ? 'checked' : ''}></td></tr>`).join('')}</tbody></table>
<button class="p-btn" data-save-config>حفظ (التجربة + الإضافات + ابني باقتك)</button></section>

<section class="ps-box ps-adm"><h3>5) العملات وأسعار الصرف</h3><p class="p-cell-muted">المنصة بالدولار للكل (العملاء أجانب). الجنيه بيظهر صغير جنب السعر (شرط البنك)، وأي عميل يقدر يختار عملته بنفسه = السعر بالدولار × سعر الصرف (+ الهامش). لو البنك (XPay) محدد سعر، اكتبه واقفله.</p>
<label style="display:block;margin:8px 0"><input type="checkbox" id="paAutoLocal" ${D.auto_local ? 'checked' : ''}> اعرض للزائر عملة بلده تلقائي (مقفولة = الدولار للكل)</label>
<div class="xp-urls" style="margin-top:6px"><b>🏦 ربط الجنيه بسعر البنك لحظي</b>${(() => { const A = D.fx_auto || {}; const SRC = { cbe: 'البنك المركزي المصري', market: 'سعر السوق' }; return `
<div class="ps-cur-plan" style="margin-top:8px"><label><input type="checkbox" id="fxOn" ${A.enabled ? 'checked' : ''}> مربوط تلقائي</label>
<label>المصدر <select id="fxSrc" class="p-select"><option value="cbe" ${A.source === 'cbe' ? 'selected' : ''}>البنك المركزي المصري (CBE)</option><option value="market" ${A.source === 'market' ? 'selected' : ''}>سعر السوق العالمي</option></select></label>
<label>السعر <select id="fxSide" class="p-select"><option value="sell" ${A.side === 'sell' ? 'selected' : ''}>سعر البيع</option><option value="mid" ${A.side === 'mid' ? 'selected' : ''}>المتوسط</option><option value="buy" ${A.side === 'buy' ? 'selected' : ''}>سعر الشراء</option></select></label>
<label>يتحدّث كل <select id="fxEvery" class="p-select">${[5, 15, 30, 60, 180].map(m => `<option value="${m}" ${+A.every === m ? 'selected' : ''}>${m < 60 ? m + ' دقيقة' : m / 60 + ' ساعة'}</option>`).join('')}</select></label>
<button class="p-btn primary" data-save-auto>حفظ الربط</button></div>
<div class="p-cell-muted">${A.last_run ? `آخر تحديث: ${esc(A.last_run.slice(0, 16))} من ${esc(SRC[A.last_source] || '-')} · سعر الدولار دلوقتي: <b>${R.EGP.base} ج.م</b>${R.EGP.margin ? ` (+${R.EGP.margin}% هامش = ${R.EGP.rate})` : ''}` : 'لسه ماتحدّثش'}${A.last_error ? `<br><span style="color:#b45309">⚠️ ${esc(A.last_error)}</span>` : ''}</div>
<div class="p-cell-muted">لو بتدفع في XPay بالدولار، XPay بيحوّل بسعر البنك بتاعه وقت الدفع. الرقم هنا للعرض وللدفع بالجنيه.</div>`; })()}</div>
<table><thead><tr><th>العملة</th><th>سعر 1$</th><th>هامش %</th><th>السعر النهائي</th><th>مقفول (يدوي)</th><th>تظهر للعملاء</th><th>آخر تحديث</th></tr></thead><tbody>${Object.entries(R).filter(([k]) => k !== 'USD').map(([k, r]) => `<tr><td><b>${k}</b> ${esc(D.currencies[k].ar)}</td><td><input class="p-select" type="number" step="0.0001" min="0" data-rb="${k}" value="${r.base}"></td><td><input class="p-select" type="number" step="0.1" min="0" max="20" data-rm="${k}" value="${r.margin}" style="max-width:70px"></td><td>${r.rate}</td><td><input type="checkbox" data-rl="${k}" ${r.locked ? 'checked' : ''}></td><td><input type="checkbox" data-re="${k}" ${r.enabled ? 'checked' : ''}></td><td class="p-cell-muted">${esc(r.updated_at || (r.source === 'default' ? 'افتراضي - حدّثه' : ''))}</td></tr>`).join('')}</tbody></table>
<button class="p-btn" data-save-rates>حفظ أسعار الصرف</button> <button class="p-btn outline" data-refresh>حدّث دلوقتي من البنك</button></section>`;
    };
    const bind = () => root.addEventListener('click', e => {
        const b = e.target.closest('button'); if (!b) return;
        if (b.dataset.preset !== undefined) return run(b, async () => { if (!confirm('هيتغيّر اسم وسعر وحدود الباقات التلاتة للتشكيلة المقترحة. تكمّل؟')) return; const r = await api('/api/admin/plans-studio/preset', {}); D.plans = r.data.plans; render(); msg('✅ اتطبقت: ' + r.data.applied.join('، ')); });
        if (b.dataset.grantTrial !== undefined) return run(b, async () => { const email = v('paGrantEmail').value.trim(); if (!email) throw new Error('اكتب إيميل العميل'); if (!confirm('تفعّل تجربة مجانية ' + D.config.trial_days + ' أيام لـ ' + email + ' من غير كارت؟')) return; const r = await api('/api/admin/plans-studio/grant-trial', { email }); v('paGrantEmail').value = ''; msg('✅ ' + (r.message || 'اتفعّلت')); });
        if (b.dataset.saveConfig !== undefined) return run(b, async () => {
            const packs = {}, services = {};
            root.querySelectorAll('[data-pq]').forEach(i => { const k = i.dataset.pq; packs[k] = { qty: +i.value, price: +root.querySelector(`[data-pp="${k}"]`).value, enabled: root.querySelector(`[data-pe="${k}"]`).checked }; });
            root.querySelectorAll('[data-su]').forEach(i => { const k = i.dataset.su; services[k] = { unit: +i.value, enabled: root.querySelector(`[data-se="${k}"]`).checked }; });
            const r = await api('/api/admin/plans-studio/config', { config: { trial_enabled: v('paTrial').checked, trial_days: +v('paTrialDays').value, trial_requires_card: v('paTrialCard').checked, trial_card_mode: v('paTrialMode').value, trial_card_usd: +v('paTrialCardUsd').value, trial_caps: Object.fromEntries([...root.querySelectorAll('[data-tc]')].map(i => [i.dataset.tc, +i.value])), trial_plan: v('paTrialPlan').value, builder_enabled: v('paBuilder').checked, packs, services } });
            D.config = r.data.config; render(); msg('✅ اتحفظ');
        });
        if (b.dataset.saveRates !== undefined) return run(b, async () => {
            const rates = {}; root.querySelectorAll('[data-rb]').forEach(i => { const k = i.dataset.rb; rates[k] = { base: +i.value, margin: +root.querySelector(`[data-rm="${k}"]`).value, locked: root.querySelector(`[data-rl="${k}"]`).checked, enabled: root.querySelector(`[data-re="${k}"]`).checked }; });
            const r = await api('/api/admin/currency/rates', { rates, auto_local: v('paAutoLocal').checked });D.auto_local = v('paAutoLocal').checked; D.rates = r.data.rates; render(); msg('✅ اتحفظت أسعار الصرف');
        });
        if (b.dataset.refresh !== undefined) return run(b, async () => { const r = await api('/api/admin/currency/refresh', {}); D.rates = r.data.rates; D.fx_auto = r.data.fx_auto; render(); msg('✅ ' + (r.message || 'اتحدّثت')); });
        if (b.dataset.saveAuto !== undefined) return run(b, async () => {
            const r = await api('/api/admin/currency/auto', { fx_auto: { enabled: v('fxOn').checked, source: v('fxSrc').value, side: v('fxSide').value, every: +v('fxEvery').value } });
            D.fx_auto = r.data.fx_auto; D.rates = r.data.rates; render();
            msg((r.data.refresh && r.data.refresh.error ? '⚠️ ' + r.data.refresh.error : '✅ ' + (r.message || 'اتحفظ')), !!(r.data.refresh && r.data.refresh.error));
        });
    });
    window.PlansAdmin = { init: async panel => { P = panel; root = document.getElementById('plansAdmin'); if (!root) return; try { const r = await api('/api/admin/plans-studio'); D = r.data; bind(); render(); } catch (e) { root.innerHTML = '<p class="ps-msg bad">' + esc(e.message) + '</p>'; } } };
})();
