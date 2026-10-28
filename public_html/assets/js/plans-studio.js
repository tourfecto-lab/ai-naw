/* Tourfecto - الباقات المرنة للعميل: التجربة المجانية، الإضافات، ابني باقتك، العملة */
(() => {
    'use strict';
    const root = document.getElementById('plansStudio');
    if (!root || !window.Panel) return;
    const P = window.Panel;
    const ar = !(document.documentElement.lang || 'ar').toLowerCase().startsWith('en');
    const t = (a, e) => ar ? a : e;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    let D = null, base = null, cycle = 'monthly', qty = {}, busy = false;

    const api = async (url, body) => {
        const r = await P.fetchJSON(url, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...body, csrf_token: D?.csrf_token }) } : undefined);
        if (!r?.success) { const e = new Error(r?.error || t('حصلت مشكلة', 'Something went wrong')); e.data = r; throw e; }
        return r.data;
    };
    const say = (msg, bad, wallet) => {
        const el = root.querySelector('#psMsg');
        if (!el) { root.innerHTML = '<div id="psMsg" class="ps-msg" role="status"></div>'; return say(msg, bad, wallet); }
        el.innerHTML = esc(msg) + (wallet ? ` <a class="p-btn sm" href="/subscription#wallet">${t('اشحن المحفظة', 'Top up wallet')}</a>` : '');
        el.className = 'ps-msg' + (bad ? ' bad' : ''); el.hidden = !msg;
        if (msg) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    };
    const fmtLocal = usd => {
        const c = D.currencies.find(x => x.code === D.currency) || { symbol: '$', decimals: 0 };
        let v = usd * D.rate; if (D.currency !== 'USD' && c.decimals === 0 && v >= 100) v = Math.round(v / 5) * 5; // الدولار هو عملة الخصم: يظهر بالظبط
        const n = v.toLocaleString('en-US', { maximumFractionDigits: v >= 100 ? 0 : 2 });
        return D.currency === 'USD' ? '$' + n : (ar ? n + ' ' + c.symbol : c.symbol + ' ' + n);
    };
    const money = m => `<b>${esc(m.label)}</b>${D.currency !== 'USD' ? `<small>${esc(m.usd_label)}</small>` : (m.egp ? `<small>≈ ${esc(m.egp)}</small>` : '')}`;
    const trialSummary = () => { const c = D.trial.caps || {}; return [[c.article_generation, t('مقالات', 'articles')], [c.media_image, t('صور', 'images')], [c.media_video, t('فيديو', 'video')], [c.chat, t('رد شات', 'chat replies')], [c.ai_tool, t('أداة AI', 'AI tools')]].filter(([v]) => v > 0).map(([v, l]) => v + ' ' + l).join(t('، ', ', ')); };
    const PLAN_NOTE = { starter: t('لشركة بتبدأ أونلاين', 'For companies getting started'), professional: t('للشركات اللي بتكبر', 'For growing companies'), enterprise: t('للشركات الكبيرة والوكالات', 'For larger companies & agencies') };

    const render = () => {
        const cur = D.current;
        const plan = key => D.plans.find(p => p.key === key);
        base = base || (cur?.custom?.base_plan) || (cur?.plan && plan(cur.plan) ? cur.plan : 'professional');
        if (!plan(base) && D.plans[0]) base = D.plans[0].key;
        const services = D.builder.services;
        root.innerHTML = `
<div class="ps-top"><div><b>${t('الباقات المرنة', 'Flexible plans')}</b><span class="p-cell-muted">${t('ادفع على قد اللي محتاجه بالظبط', 'Pay for exactly what you need')}</span></div>
<label class="ps-cur">${t('العملة', 'Currency')} <select id="psCur" class="p-select">${D.currencies.map(c => `<option value="${c.code}" ${c.code === D.currency ? 'selected' : ''}>${esc(c.name)} (${esc(c.symbol)})</option>`).join('')}</select></label></div>
<div id="psMsg" class="ps-msg" hidden></div>
${D.currency !== 'USD' ? `<p class="ps-note">${t('الأسعار محسوبة بسعر الصرف النهارده (1$ = ', 'Prices use today’s exchange rate ($1 = ')}${esc(fmtLocal(1))})${t('. المحفظة والفواتير بالدولار، والدفع بعملتك بنفس السعر.', '. Wallet and invoices are in USD; you can pay in your currency at this rate.')}</p>` : ''}
${D.trial.blocked ? `<section class="ps-trial" id="trial"><div><h3>🎁 ${t('التجربة المجانية', 'Free trial')}</h3><p>${esc(D.trial.blocked)}</p></div><a class="p-btn" href="/plans">${t('اختار باقتك', 'Choose a plan')}</a></section>` : D.trial.eligible ? `<section class="ps-trial" id="trial"><div><h3>🎁 ${t('جرّب باقة ' + esc(plan(D.trial.plan)?.name || '') + ' مجانًا ' + D.trial.days + (D.trial.days <= 10 ? ' أيام' : ' يوم'), 'Try ' + esc(plan(D.trial.plan)?.name || '') + ' free for ' + D.trial.days + ' days')}</h3><p>${t('جرّب كل خدمات المنصة بنفسك باستخدام بسيط لكل خدمة (' + trialSummary() + ')، ولما الحد يخلص يخلص.', 'Try every service with a small allowance each (' + trialSummary() + '); when it runs out, it stops.')}${D.trial.needs_card ? '<br><b>' + (D.trial.card_mode === 'prepay'
            ? t('بتربط كارتك وتدفع أول شهر (' + esc(D.trial.card_money.label) + ') بيتحط في محفظتك. أول ' + D.trial.days + ' أيام مجانًا، وبعدها الاشتراك بيتخصم تلقائي من المحفظة. لو لغيت قبل ما التجربة تخلص مفيش خصم والمبلغ يفضل في محفظتك.', 'Link your card and pay the first month (' + esc(D.trial.card_money.label) + ') into your wallet. The first ' + D.trial.days + ' days are free, then the plan is charged automatically from the wallet. Cancel before the trial ends and nothing is charged.')
            : t('بنأكد كارتك الأول بـ ' + esc(D.trial.card_money.label) + ' بيتشحنوا في محفظتك (مش رسوم).', 'We verify your card with ' + esc(D.trial.card_money.label) + ', added to your wallet (not a fee).')) + '</b>' : ''}</p></div><button class="p-btn" data-trial>${D.trial.needs_card ? (D.trial.card_mode === 'prepay' ? t('اربط كارتك وابدأ التجربة', 'Link card & start trial') : t('أكّد كارتك وابدأ التجربة', 'Verify card & start trial')) : t('ابدأ التجربة المجانية', 'Start free trial')}</button></section>` : ''}
${cur ? `<section class="ps-box"><h3>${t('باقتك دلوقتي', 'Your current plan')}</h3><div class="ps-cur-plan"><span class="ps-badge">${esc(plan(cur.plan)?.name || cur.plan)}${cur.custom ? ' + ' + t('مخصصة', 'custom') : ''}</span>
<span>${cur.status === 'trialing' ? '🎁 ' + t('تجربة مجانية لحد ', 'Free trial until ') + esc((cur.trial_ends_at || '').slice(0, 16).replace('T', ' ')) : t('بتتجدد ', 'Renews ') + esc((cur.period_end || '').slice(0, 10))}</span>
${cur.status === 'trialing' && cur.after_trial && cur.after_trial_covered === false ? `<span>${t('عشان تكمّل بعد التجربة: اختار باقتك وكلمنا على الواتساب أو الإيميل ونفعّلهالك في نفس اليوم', 'To continue after the trial, pick your plan and message us on WhatsApp or email - we activate it the same day')}</span><a class="p-btn sm" href="/plans">${t('فعّل باقتك', 'Activate your plan')}</a>` : ''}
${cur.status === 'trialing' && cur.after_trial && cur.after_trial_covered !== false ? `<span>${cur.auto_renew ? t('بعد التجربة هيتخصم ', 'After the trial ') + money(cur.after_trial) + t(' تلقائي من المحفظة', ' is charged from your wallet') : t('التجديد ملغي: مفيش خصم بعد التجربة', 'Renewal cancelled: no charge after the trial')}</span><button class="p-btn outline sm" data-trial-renew="${cur.auto_renew ? '0' : '1'}">${cur.auto_renew ? t('إلغاء التجديد', 'Cancel renewal') : t('رجّع التجديد', 'Resume renewal')}</button>` : ''}
<span>${t('رصيد المحفظة: ', 'Wallet: ')}${money(D.wallet)}</span></div>
${cur.extras?.length ? `<div class="ps-extras">${cur.extras.map(x => `<span>+${Number(x.amount).toLocaleString()} ${esc((services.find(s => s.key === x.feature) || {}).label || x.feature)} <small>${x.source === 'custom' ? t('شهريًا', 'monthly') : t('لحد ', 'until ') + esc(x.expires_at.slice(0, 10))}</small></span>`).join('')}</div>` : ''}</section>` : ''}

${D.packs.length ? `<section class="ps-box"><h3>⚡ ${t('إضافات حسب الطلب', 'On-demand add-ons')}</h3><p class="p-cell-muted">${t('خلصت حصة خدمة معينة؟ زوّدها على طول من رصيدك - صالحة 30 يوم، من غير ما تغيّر باقتك.', 'Ran out of something? Top it up from your wallet - valid 30 days, no plan change.')}</p>
<div class="ps-packs">${D.packs.map(p => `<div class="ps-pack"><div class="ps-ico">${p.icon}</div><div class="ps-q">+${Number(p.qty).toLocaleString()}</div><div class="ps-l">${esc(p.label)}</div><div class="ps-p">${money(p.price)}</div><button class="p-btn outline sm" data-pack="${esc(p.key)}" ${cur ? '' : 'disabled title="' + t('محتاج باقة شغالة', 'Needs an active plan') + '"'}>${t('أضف', 'Add')}</button></div>`).join('')}</div>${cur ? '' : `<p class="p-cell-muted">${t('الإضافات بتتضاف على باقة شغالة. اشترك أو ابدأ التجربة المجانية الأول.', 'Add-ons apply to an active plan. Subscribe or start the free trial first.')}</p>`}</section>` : ''}

${D.builder.enabled && services.length ? `<section class="ps-box"><h3>🧩 ${t('ابني باقتك', 'Build your plan')}</h3><p class="p-cell-muted">${t('اختار باقة أساسية، وزوّد بس الخدمات اللي بتستخدمها كتير. السعر بيتحسب لوحده، وبتتجدد بنفس الزيادات.', 'Pick a base plan and add only the services you use most. Priced instantly, and renews with the same extras.')}</p>
<div class="ps-cycle"><button class="p-btn sm ${cycle === 'monthly' ? '' : 'outline'}" data-cycle="monthly">${t('شهري', 'Monthly')}</button><button class="p-btn sm ${cycle === 'yearly' ? '' : 'outline'}" data-cycle="yearly">${t('سنوي (شهرين مجانًا)', 'Yearly (2 months free)')}</button></div>
<div class="ps-bases">${D.plans.map(p => `<label class="ps-base ${p.key === base ? 'on' : ''}"><input type="radio" name="psBase" value="${esc(p.key)}" ${p.key === base ? 'checked' : ''}><b>${esc(p.name)}</b><span>${money(p[cycle])}<small>${cycle === 'monthly' ? t('/ شهر', '/ mo') : t('/ سنة', '/ yr')}</small></span><em>${esc(PLAN_NOTE[p.key] || '')}</em></label>`).join('')}</div>
<div class="ps-svcs">${services.map(s => { const q = qty[s.key] || 0; const inc = (plan(base)?.features || {})[{ article_generation: 'articles', media_image: 'media_images', media_video: 'media_videos', marketing_tool: 'marketing_tools', ai_assistant_message: 'ai_assistant_messages', chat: 'chat_credits', review: 'review_credits', ai_analysis: 'ai_analysis', competitor: 'competitor_analysis' }[s.key]];
            return `<div class="ps-svc"><div><b>${esc(s.label)}</b><span class="p-cell-muted">${inc !== undefined ? t('في الباقة: ', 'Included: ') + (inc < 0 ? t('بلا حد', 'unlimited') : Number(inc).toLocaleString()) + t(' / شهر', ' / mo') : ''}</span></div>
<div class="ps-step"><button type="button" data-dec="${s.key}" ${q ? '' : 'disabled'}>−</button><span>${q ? '+' + q.toLocaleString() : '0'}</span><button type="button" data-inc="${s.key}">+</button></div><div class="ps-line">${q ? fmtLocal(q * s.unit * (cycle === 'yearly' ? 10 : 1)) : '—'}</div></div>`; }).join('')}</div>
<div class="ps-total"><div>${t('الإجمالي', 'Total')}: <b id="psTotal">…</b> <small id="psTotalUsd"></small></div><button class="p-btn" data-buy ${Object.values(qty).some(Boolean) ? '' : 'disabled'}>${t('اشترك في باقتي', 'Get my plan')}</button></div></section>` : ''}`;
        total();
    };

    const total = () => {
        const el = root.querySelector('#psTotal'); if (!el) return;
        const p = D.plans.find(x => x.key === base); if (!p) return;
        const extras = D.builder.services.reduce((s, x) => s + (qty[x.key] || 0) * x.unit, 0) * (cycle === 'yearly' ? 10 : 1);
        const usd = p[cycle].usd + extras;
        el.textContent = fmtLocal(usd) + (cycle === 'monthly' ? t(' / شهر', ' / mo') : t(' / سنة', ' / yr'));
        root.querySelector('#psTotalUsd').textContent = D.currency !== 'USD' ? '$' + usd.toFixed(2) : '';
    };

    const run = async (btn, fn) => { if (busy) return; busy = true; if (btn) btn.disabled = true; try { await fn(); } catch (e) { say(e.message, true, e.data?.details?.shortfall > 0 || /رصيد|balance|wallet/i.test(e.message)); } finally { busy = false; if (btn) btn.disabled = false; } };
    const reload = async () => { D = await api('/api/plans/overview'); render(); };

    root.addEventListener('click', e => {
        const b = e.target.closest('button'); if (!b) return;
        if (b.dataset.trialRenew !== undefined) return run(b, async () => {
            const cancel = b.dataset.trialRenew === '0';
            if (cancel && !confirm(t('متأكد؟ التجربة هتكمل لآخرها، وبعدها مفيش خصم والخدمات هتقف. رصيدك يفضل في محفظتك.', 'Sure? The trial continues to its end, then nothing is charged and services stop. Your balance stays in the wallet.'))) return;
            const r = await P.fetchJSON('/api/plans/trial/renewal', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ cancel, csrf_token: D?.csrf_token }) });
            if (!r?.success) throw new Error(r?.error || t('حصلت مشكلة', 'Something went wrong'));
            await reload(); say(r.message);
        });
        if (b.dataset.trial !== undefined) return run(b, async () => {
            try {
                const r = await api('/api/plans/trial', {}); await reload();
                say(t('🎉 التجربة المجانية بدأت لحد ', '🎉 Free trial started until ') + r.ends_at.slice(0, 10) + t('. كل الخدمات مفتوحة دلوقتي.', '. Everything is unlocked now.'));
            } catch (e) {
                if (!e.data?.details?.card_required && !e.data?.card_required) throw e;
                const d = e.data.details || e.data;
                if (!d.card_ready || !window.TFXPay) throw e;
                if (!confirm(D.trial.card_mode === 'prepay'
                    ? t('هنفتحلك صفحة الدفع الآمنة: هتدفع ' + D.trial.card_money.label + ' (أول شهر) بيتحطوا في محفظتك، والتجربة المجانية تبدأ على طول. بعد ' + D.trial.days + ' أيام الاشتراك بيتخصم تلقائي من المحفظة، وتقدر تلغي قبلها. تكمّل؟', 'We will open the secure payment page: you pay ' + D.trial.card_money.label + ' (first month) into your wallet and the free trial starts. After ' + D.trial.days + ' days the plan is charged from the wallet unless you cancel. Continue?')
                    : t('هنفتحلك صفحة الدفع الآمنة عشان تأكد كارتك بـ ' + D.trial.card_money.label + '. المبلغ بيتشحن في محفظتك وتقدر تستخدمه، والتجربة بتبدأ على طول بعد التأكيد. تكمّل؟', 'We will open the secure payment page to verify your card with ' + D.trial.card_money.label + '. It is added to your wallet and the trial starts right after. Continue?'))) return;
                await window.TFXPay.pay({ purpose: 'trial_card' });
            }
        });
        if (b.dataset.pack) return run(b, async () => {
            const p = D.packs.find(x => x.key === b.dataset.pack);
            if (!confirm(t('تضيف ', 'Add ') + '+' + p.qty + ' ' + p.label + t(' بـ ', ' for ') + p.price.label + t(' من رصيد المحفظة؟', ' from your wallet?'))) return;
            await api('/api/plans/addon', { pack: p.key, request_key: crypto.randomUUID() }); await reload(); say(t('✅ اتضافت لباقتك: +', '✅ Added: +') + p.qty + ' ' + p.label);
        });
        if (b.dataset.cycle) { cycle = b.dataset.cycle; render(); return; }
        if (b.dataset.inc || b.dataset.dec) {
            const k = b.dataset.inc || b.dataset.dec, s = D.builder.services.find(x => x.key === k);
            qty[k] = Math.max(0, Math.min(s.max, (qty[k] || 0) + (b.dataset.inc ? s.step : -s.step))); render(); return;
        }
        if (b.dataset.buy !== undefined) return run(b, async () => {
            const extras = Object.fromEntries(Object.entries(qty).filter(([, v]) => v > 0));
            const q = await api('/api/plans/custom/quote', { base, cycle, extras });
            const lines = q.items.map(i => '• +' + i.qty + ' ' + (ar ? i.ar : i.en)).join('\n');
            if (!confirm(t('باقتك: ', 'Your plan: ') + q.base_name + '\n' + lines + '\n\n' + t('الإجمالي: ', 'Total: ') + q.total_money.label + (cycle === 'monthly' ? t(' / شهر', ' / month') : t(' / سنة', ' / year')) + '\n' + t('هيتخصم من رصيد المحفظة. تكمّل؟', 'Charged from your wallet. Continue?'))) return;
            await api('/api/plans/custom/buy', { base, cycle, extras, request_key: crypto.randomUUID() });
            qty = {}; await reload(); say(t('✅ باقتك المخصصة اشتغلت، والزيادات اتضافت.', '✅ Your custom plan is active with its extras.'));
        });
    });
    root.addEventListener('change', e => {
        if (e.target.name === 'psBase') { base = e.target.value; render(); }
        if (e.target.id === 'psCur') run(null, async () => { await api('/api/currency', { code: e.target.value }); location.reload(); });
    });

    // جاي من «ابدأ التجربة» في صفحة مقفولة: انزل على كارت التجربة بعد ما يترسم
    run(null, async () => { await reload(); if (location.hash === '#trial') document.getElementById('trial')?.scrollIntoView({ behavior: 'smooth', block: 'center' }); });
})();

/* الدفع بالكارت عن طريق XPay: جوه نافذة شحن المحفظة + رسالة بعد الرجوع من الدفع */
(() => {
    'use strict';
    if (!window.fetch) return;
    const ar = !(document.documentElement.lang || 'ar').toLowerCase().startsWith('en');
    const t = (a, e) => ar ? a : e;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const qs = new URLSearchParams(location.search), back = qs.get('topup');
    if (back) {
        const host = document.querySelector('#plansStudio') || document.querySelector('.panel-content, main');
        if (host) {
            const d = document.createElement('div');
            d.className = 'xp-banner ' + (back === 'ok' ? 'ok' : 'wait');
            d.textContent = back === 'ok' && qs.get('trial') ? t('🎉 كارتك اتأكد والتجربة المجانية بدأت! المبلغ اتشحن في محفظتك.', '🎉 Card verified and your free trial has started! The amount was added to your wallet.')
                : back === 'ok' ? t('✅ الدفع نجح ورصيد محفظتك اتشحن.', '✅ Payment received - your wallet is topped up.')
                : back === 'cancelled' ? t('عملية الدفع اتلغت أو انتهى وقتها. تقدر تحاول تاني.', 'The payment was cancelled or expired. You can try again.')
                : t('⏳ بنأكد الدفع مع البنك... الرصيد هيتشحن أول ما يتأكد (عادةً دقيقة).', '⏳ Confirming your payment... your balance updates as soon as it clears.');
            host.parentNode.insertBefore(d, host);
        }
        history.replaceState(null, '', location.pathname + location.hash);
    }
    const modal = document.querySelector('#depositModal .p-modal-body');
    if (!modal) return;
    fetch('/api/xpay/status', { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(r => r.ok ? r.json() : null).then(j => {
        const S = j && j.success && j.data;
        if (!S || !S.enabled) return;
        const box = document.createElement('div');
        box.className = 'xp-pay';
        box.innerHTML = `<b>⚡ ${t('ادفع بالكارت دلوقتي', 'Pay by card now')}</b><small>${t('فيزا، ماستركارد، ميزة ومحافظ الموبايل - الرصيد بيتشحن على طول من غير انتظار', 'Visa, Mastercard and wallets - your balance is added instantly')}${S.test_mode ? ' · <b style="display:inline;color:#b45309">' + t('وضع تجريبي', 'Test mode') + '</b>' : ''}</small>
<div class="xp-row"><button type="button" class="p-btn primary" id="xpPayBtn">${t('ادفع بالكارت', 'Pay by card')}</button><span id="xpEq" class="p-cell-muted"></span></div><div id="xpErr" class="alert alert-danger" style="display:none;margin-top:8px"></div>
<div class="p-cell-muted" style="margin-top:10px">${t('أو حوّل يدوي (البنك / PayPal) من تحت وابعت الإيصال:', 'Or pay manually (bank / PayPal) below and send the receipt:')}</div>`;
        modal.insertBefore(box, modal.querySelector('#depositAmount')?.nextSibling || modal.firstChild);
        const amt = document.getElementById('depositAmount'), eq = box.querySelector('#xpEq'), btn = box.querySelector('#xpPayBtn'), err = box.querySelector('#xpErr');
        const upd = () => {
            const v = +(amt?.value || 0);
            btn.textContent = v > 0 ? t('ادفع $', 'Pay $') + v.toFixed(2) + t(' بالكارت', ' by card') : t('ادفع بالكارت', 'Pay by card');
            eq.textContent = v > 0 && S.charge_currency === 'EGP' ? '≈ ' + (v * S.egp_rate).toLocaleString('en-US', { maximumFractionDigits: 2 }) + t(' جنيه', ' EGP') : '';
        };
        amt && amt.addEventListener('input', upd); upd();
        const fail = m => { err.textContent = m; err.style.display = ''; btn.disabled = false; };
        btn.addEventListener('click', async () => {
            const v = +(amt?.value || 0);
            err.style.display = 'none';
            if (!(v >= S.min_usd && v <= S.max_usd)) return fail(t('اكتب مبلغ بين $', 'Enter an amount between $') + S.min_usd + t(' و $', ' and $') + S.max_usd);
            btn.disabled = true;
            try { await window.TFXPay.pay({ amount: v }); btn.disabled = false; } catch (e) { fail(e.message); }
        });
    }).catch(() => {});
})();

/* فتح دفع XPay (شحن محفظة أو تأكيد كارت التجربة) - مشترك */
(() => {
    'use strict';
    const ar = !(document.documentElement.lang || 'ar').toLowerCase().startsWith('en');
    const t = (a, e) => ar ? a : e;
    const pay = async (body) => {
        const st = await fetch('/api/xpay/status', { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(r => r.json()).catch(() => null);
        const S = st && st.success && st.data;
        if (!S || !S.enabled) throw new Error(t('الدفع بالكارت مش متاح دلوقتي.', 'Card payments are unavailable right now.'));
        const r = await fetch('/api/xpay/topup', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': S.csrf_token }, body: JSON.stringify({ ...body, csrf_token: S.csrf_token }) }).then(x => x.json());
        if (!r.success) throw new Error(r.error || t('حصلت مشكلة', 'Something went wrong'));
        const d = r.data;
        if (d.checkout_url) { location.href = d.checkout_url; return; }
        if (!d.client_secret || !d.public_key) throw new Error(t('XPay مرجعش رابط دفع. كلّم الدعم.', 'XPay returned no payment link.'));
        await new Promise((resolve, reject) => {
            const go = () => { try { window.XPay(d.public_key).checkout({ clientSecret: d.client_secret, mode: 'modal', onComplete: () => { location.href = '/wallet/xpay/return?session_id=' + encodeURIComponent(d.session_id); }, onClose: () => resolve() }).open(); } catch (e) { reject(e); } };
            if (window.XPay) return go();
            const s = document.createElement('script'); s.src = 'https://checkout.xpay.app/v1/sdk.js';
            s.onload = go; s.onerror = () => reject(new Error(t('مقدرناش نفتح صفحة الدفع، جرّب تاني.', 'Could not open the payment page.')));
            document.head.appendChild(s);
        });
    };
    window.TFXPay = { pay };
})();
