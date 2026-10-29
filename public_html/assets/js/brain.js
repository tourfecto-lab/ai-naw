/**
 * Tourfecto - العقل الاستراتيجي (واجهة العميل)
 * بيعرض: اللي العقل فاهمه عن الشركة، والمؤشرات الحقيقية، والقرارات المرتبة بأدلتها،
 * وحالة كل قرار ونتيجته قبل وبعد. كل أرقام الصفحة جاية من /api/brain ومفيش رقم بيتحسب هنا.
 */
(() => {
  'use strict';
  const start = async () => {
    const root = document.getElementById('brainRoot'), P = window.Panel;
    if (!root || !P) return;
    const ar = document.documentElement.lang.toLowerCase().startsWith('ar'), t = (a, e) => ar ? a : e, esc = P.esc, L = o => o ? (ar ? o.ar : o.en) || '' : '';
    const params = new URLSearchParams(location.search);
    let data = null, busy = false, note = null;

    const req = async (url, body) => {
      const r = await P.fetchJSON(url, body ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': root.dataset.csrf || '' }, body: JSON.stringify({ ...body, csrf_token: root.dataset.csrf || '' }) } : undefined);
      if (!r?.success) throw new Error(r?.error || t('حصلت مشكلة. جرّب تاني.', 'Something went wrong. Please try again.'));
      return r.data;
    };
    const wid = () => Number(root.querySelector('#brSite')?.value || data?.website_id || params.get('website_id') || 0);
    const AREA = { data: ['فهم الشركة', 'Business data'], seo: ['السيو', 'SEO'], reputation: ['السمعة', 'Reputation'], whatsapp: ['الشات وواتساب', 'Chat & WhatsApp'], social: ['السوشيال', 'Social'], ads: ['الإعلانات', 'Ads'], content: ['المحتوى', 'Content'], acquisition: ['جلب عملاء', 'Acquisition'], market: ['السوق والمنافسين', 'Market & competitors'] };
    const STATUS = { proposed: ['مقترح', 'Suggested'], approved: ['شغالين عليه', 'In progress'], done: ['اتنفّذ — بنقيس النتيجة', 'Done — measuring'], resolved: ['اتحل', 'Resolved'], dismissed: ['مش دلوقتي', 'Not now'] };
    const OUTCOME = { improved: ['✓ اتحسّن', '✓ Improved'], unchanged: ['ماتغيرش', 'No change'], worse: ['اتراجع', 'Got worse'] };
    const num = v => v === null || v === undefined ? '—' : Number(v).toLocaleString('en-US');

    function understanding(s) {
      const u = s.understanding || {}, gaps = [];
      if (!u.profile_confirmed) gaps.push(t('ملف الشركة مش متأكد', 'Company profile not confirmed'));
      if (u.profile_confirmed && !u.goal) gaps.push(t('الهدف', 'Goal'));
      if (u.profile_confirmed && !u.audience) gaps.push(t('الجمهور', 'Audience'));
      if (!(u.markets?.countries || []).length) gaps.push(t('الدول المستهدفة', 'Target countries'));
      if (!s.competitors?.tracked) gaps.push(t('المنافسين', 'Competitors'));
      const row = (k, v) => `<div class="br-fact"><span>${esc(k)}</span><p dir="auto">${v ? esc(String(v).slice(0, 220)) : `<em>${t('مش معروف لسه', 'Not known yet')}</em>`}</p></div>`;
      const known = [u.business_name || s.website?.name, u.services, u.audience, u.goal, (u.markets?.countries || []).length, s.competitors?.tracked].filter(Boolean).length;
      return `<section class="br-card"><div class="br-head"><div><h2>${t('العقل فاهم عن شركتك إيه', 'What the brain knows about your business')}</h2>
        <p class="br-muted">${t('كل حاجة هنا من بيانات حسابك الحقيقية. اللي ناقص بيخلّي القرارات أضعف.', 'Everything here comes from your real account data. Gaps make decisions weaker.')}</p></div>
        <div class="br-meter" role="img" aria-label="${known}/6"><b>${known}/6</b><span>${t('اكتمال الفهم', 'Understanding')}</span></div></div>
        <div class="br-facts">${row(t('الشركة', 'Company'), u.business_name || s.website?.name)}${row(t('الخدمات والوجهات', 'Services & destinations'), u.services)}${row(t('الجمهور', 'Audience'), u.audience)}${row(t('الهدف', 'Goal'), u.goal)}${row(t('الأسواق', 'Markets'), (u.markets?.countries || []).join('، '))}${row(t('المنافسين المتابَعين', 'Tracked competitors'), s.competitors?.tracked ?? null)}</div>
        ${gaps.length ? `<p class="br-gaps">${t('ناقص: ', 'Missing: ')}${gaps.map(g => `<span>${esc(g)}</span>`).join('')} <a href="/business-hub">${t('كمّل بيانات الشركة', 'Complete company details')}</a></p>` : ''}</section>`;
    }

    function signals(s) {
      const sc = s.scores || {}, g = s.growth || {}, tile = (v, label, hint) => `<div class="br-tile"><b>${v}</b><span>${esc(label)}</span>${hint ? `<small>${esc(hint)}</small>` : ''}</div>`;
      const season = { peak: t('موسم عالي', 'Peak season'), shoulder: t('موسم متوسط', 'Shoulder season'), low: t('موسم هادي', 'Low season'), unknown: t('مش معروف (حجوزات قليلة)', 'Unknown (few bookings)') }[g.season || 'unknown'];
      return `<section class="br-card"><h3>${t('الصورة دلوقتي', 'Where you stand now')}</h3><div class="br-tiles">
        ${tile(num(s.bookings?.last_30), t('حجوزات آخر 30 يوم', 'Bookings, last 30 days'), s.bookings ? t('آخر 90 يوم: ', 'Last 90 days: ') + num(s.bookings.last_90) : '')}
        ${tile(esc(season), t('الشهر ده', 'This month'), g.lead_time_days != null ? t('العملاء بيحجزوا قبلها بـ ', 'Guests book ') + g.lead_time_days + t(' يوم', ' days ahead') : '')}
        ${tile(sc.seo_score != null ? num(sc.seo_score) : '—', t('درجة الموقع', 'Site score'), '')}
        ${tile(s.reputation?.avg_rating != null ? s.reputation.avg_rating + ' ★' : '—', t('التقييمات (30 يوم)', 'Reviews (30 days)'), s.reputation ? num(s.reputation.reviews_30) + t(' تقييم', ' reviews') : '')}
        ${tile(num(s.chat?.pending_approval), t('ردود شات مستنية', 'Chat replies waiting'), s.chat ? num(s.chat.messages_30) + t(' رسالة في 30 يوم', ' messages in 30 days') : '')}
        ${tile(s.seo?.autopilot ? t('شغال', 'On') : t('متوقف', 'Off'), t('الطيار الآلي للسيو', 'SEO autopilot'), '')}
      </div>${(g.evidence || []).length ? `<details class="br-evidence"><summary>${t('إشارات الحجوزات اللي العقل شايفها', 'Booking signals the brain sees')}</summary><ul>${g.evidence.map(e => `<li dir="ltr">${esc(e)}</li>`).join('')}</ul></details>` : ''}</section>`;
    }

    function decisionCard(d) {
      const st = STATUS[d.status] || [d.status, d.status], area = AREA[d.area] || [d.area, d.area];
      const measured = d.outcome ? `<p class="br-outcome ${esc(d.outcome)}">${t('النتيجة: ', 'Result: ')}${esc(t(...(OUTCOME[d.outcome] || [d.outcome, d.outcome])))}${d.baseline != null && d.result != null ? ` <span dir="ltr">(${num(d.baseline)} → ${num(d.result)})</span>` : ''}</p>` : '';
      const buttons = d.status === 'proposed' ? `<button type="button" class="p-btn xs primary" data-set="approved" data-id="${d.id}">${t('موافق، نشتغل عليه', 'Approve')}</button><button type="button" class="p-btn xs outline" data-set="dismissed" data-id="${d.id}">${t('مش دلوقتي', 'Not now')}</button>`
        : d.status === 'approved' ? `<button type="button" class="p-btn xs primary" data-set="done" data-id="${d.id}">${t('اتنفّذ', 'Mark done')}</button>`
        : d.status === 'dismissed' ? `<button type="button" class="p-btn xs outline" data-set="proposed" data-id="${d.id}">${t('رجّعه للمقترحات', 'Restore')}</button>` : '';
      return `<li class="br-decision ${esc(d.status)}"><div class="br-dhead"><span class="br-area">${esc(t(...area))}</span><span class="br-status">${esc(t(...st))}</span>${d.requires_spend ? `<span class="br-spend">${t('فيه صرف — بموافقتك بس', 'Involves spend — only with your approval')}</span>` : ''}</div>
        <h4 dir="auto">${esc(L(d.title))}</h4><p dir="auto">${esc(L(d.why))}</p>
        ${(d.evidence || []).length ? `<ul class="br-proof">${d.evidence.map(e => `<li dir="auto">${esc(L(e))}</li>`).join('')}</ul>` : ''}${measured}
        <div class="br-actions"><a class="p-btn xs" href="${esc(d.action_url || '#')}">${esc(L(d.action))}</a>${buttons}</div></li>`;
    }

    function decisions(list, results) {
      const active = list.filter(d => ['approved', 'proposed'].includes(d.status)), past = list.filter(d => ['done', 'resolved'].includes(d.status)), later = list.filter(d => d.status === 'dismissed');
      return `<section class="br-card"><div class="br-head"><div><h3>${t('قرارات العقل لشركتك', 'The brain\'s decisions for your business')}</h3>
        <p class="br-muted">${t('مرتّبة حسب الأثر وهدفك ونتيجة قراراتك اللي فاتت. كل قرار بيوديك للخدمة اللي بتنفّذه، وبنقيس النتيجة بعد أسبوع من تنفيذه.', 'Ranked by impact, your goal and past results. Each decision opens the service that carries it out; we measure the result a week after it is done.')}</p></div>
        <div class="br-results"><b>${num(results.improved)}</b><span>${t('قرار نفع', 'decisions worked')}</span><small>${t('من ', 'of ')}${num(results.completed)} ${t('اتنفّذ', 'completed')}</small></div></div>
        ${active.length ? `<ol class="br-list">${active.map(decisionCard).join('')}</ol>` : `<p class="br-done">✓ ${t('مفيش قرارات مفتوحة دلوقتي.', 'No open decisions right now.')}</p>`}
        ${past.length ? `<details class="br-past"><summary>${t('قرارات اتنفّذت', 'Completed decisions')} (${past.length})</summary><ol class="br-list">${past.map(decisionCard).join('')}</ol></details>` : ''}
        ${later.length ? `<details class="br-past"><summary>${t('مؤجلة', 'Postponed')} (${later.length})</summary><ol class="br-list">${later.map(decisionCard).join('')}</ol></details>` : ''}</section>`;
    }

    function render() {
      if (!data) return;
      const sites = data.websites || [];
      if (!sites.length) { root.innerHTML = `<section class="br-card"><h3>${t('ضيف موقعك الأول', 'Add your website first')}</h3><a class="p-btn" href="/websites">${t('ضيف موقع', 'Add website')}</a></section>`; return; }
      const s = data.snapshot;
      root.innerHTML = `${sites.length > 1 ? `<label class="br-site">${t('الموقع', 'Website')} <select id="brSite" class="p-select">${sites.map(x => `<option value="${x.id}" ${Number(x.id) === Number(data.website_id) ? 'selected' : ''}>${esc(x.company_name || x.main_url)}</option>`).join('')}</select></label>` : ''}
        ${note ? `<p class="br-note ${note.error ? 'error' : ''}" role="status">${esc(note.text)}</p>` : ''}
        ${decisions(data.decisions || [], data.results || {})}${signals(s)}${understanding(s)}`;
      const sel = root.querySelector('#brSite'); if (sel) sel.onchange = () => load();
      root.querySelectorAll('[data-set]').forEach(b => { b.onclick = async () => {
        if (busy) return; busy = true; b.disabled = true;
        try { const r = await req('/api/brain/decisions/' + b.dataset.id + '?website_id=' + wid(), { status: b.dataset.set, website_id: wid() }); data.decisions = r.decisions; data.results = r.results; note = { text: t('اتحفظ ✓', 'Saved ✓') }; }
        catch (e) { note = { text: e.message, error: true }; }
        finally { busy = false; render(); }
      }; });
    }

    async function load() {
      root.innerHTML = `<section class="br-card"><p>${t('العقل بيجمع صورة شركتك…', 'The brain is gathering your business picture…')}</p></section>`;
      try { data = await req('/api/brain?website_id=' + wid()); note = null; render(); }
      catch (e) { root.innerHTML = `<section class="br-card"><p>${esc(e.message)}</p><button type="button" class="p-btn" onclick="location.reload()">${t('حاول تاني', 'Try again')}</button></section>`; }
    }
    load();
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true }); else start();
})();
