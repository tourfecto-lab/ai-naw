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
    // التنفيذ جوه الخدمة: معاينة الأول (هيحصل إيه وبيكلّف قد إيه)، وبعدين تنفيذ بموافقة صريحة
    const ACT_LABEL = { review_drafts: ['✍️ جهّز ردود التقييمات', '✍️ Draft the review replies'] };
    let act = null; // {id, preview?, result?, error?, loading?}
    function actionBox(d) {
      if (!act || act.id !== d.id) return '';
      if (act.loading) return `<div class="br-act" role="status">${t('بنجهّز…', 'Preparing…')}</div>`;
      if (act.error) return `<div class="br-act error" role="alert">${esc(act.error)}</div>`;
      const p = act.result || act.preview;
      if (act.result) return `<div class="br-act done" role="status"><b>✓ ${t(`اتكتب ${num(p.drafted)} رد`, `${num(p.drafted)} replies drafted`)}</b>${p.skipped ? ` <span class="br-muted">${t(`(${num(p.skipped)} ماتكتبوش لأن الذكاء الاصطناعي مش متاح دلوقتي)`, `(${num(p.skipped)} skipped: AI unavailable right now)`)}</span>` : ''}
        <p>${t('الردود مستنية موافقتك في صفحة التقييمات. راجع كل رد وعدّله لو حابب، وبعدين انشره. مفيش حاجة اتنشرت لوحدها.', 'The replies are waiting for your approval on the reviews page. Review or edit each one, then publish. Nothing was published.')}</p>
        <a class="p-btn xs primary" href="${esc(p.review_url)}">${t(`راجع الردود (${num(p.ready_drafts)})`, `Review replies (${num(p.ready_drafts)})`)}</a></div>`;
      if (!p.will_draft) return `<div class="br-act" role="status"><p>${p.ready_drafts ? t(`فيه ${num(p.ready_drafts)} رد جاهز مستني موافقتك.`, `${num(p.ready_drafts)} replies are ready for your approval.`) : t('مفيش تقييمات سلبية من غير رد في آخر 30 يوم.', 'No unanswered negative reviews in the last 30 days.')}</p>${p.ready_drafts ? `<a class="p-btn xs primary" href="${esc(p.review_url)}">${t('راجع الردود', 'Review replies')}</a>` : ''}</div>`;
      return `<div class="br-act"><b>${t('قبل ما نبدأ:', 'Before we start:')}</b><ul>
        <li>${t(`هنكتب ${num(p.will_draft)} رد على أسوأ التقييمات اللي من غير رد (من ${num(p.without_draft)}).`, `We will draft ${num(p.will_draft)} replies for the worst unanswered reviews (of ${num(p.without_draft)}).`)}</li>
        <li>${t(`التكلفة: لحد ${num(p.ai_calls_max)} طلب ذكاء اصطناعي من حدود حسابك. نشر الرد نفسه بيتحسب من رصيد الردود في باقتك، زي العادي.`, `Cost: up to ${num(p.ai_calls_max)} AI requests from your account limits. Publishing a reply uses your plan's reply credits as usual.`)}</li>
        <li>${t('مفيش رد هيتنشر لوحده. كل رد هيستنى موافقتك في صفحة التقييمات.', 'No reply is published automatically. Each one waits for your approval on the reviews page.')}</li></ul>
        <div class="br-actions"><button type="button" class="p-btn xs primary" data-act-run="${d.id}">${t('موافق، اكتب الردود', 'Approve, draft the replies')}</button><button type="button" class="p-btn xs outline" data-act-cancel>${t('إلغاء', 'Cancel')}</button></div></div>`;
    }

    function understanding(s) {
      const u = s.understanding || {}, gaps = [];
      if (!u.profile_confirmed) gaps.push(t('ملف الشركة مش متأكد', 'Company profile not confirmed'));
      if (u.profile_confirmed && !u.goal) gaps.push(t('الهدف', 'Goal'));
      if (u.profile_confirmed && !u.audience) gaps.push(t('الجمهور', 'Audience'));
      const marketsKnown = (u.markets?.countries || []).length || (s.market && s.market.markets_default === false);
      if (!marketsKnown) gaps.push(t('الدول المستهدفة', 'Target countries'));
      if (!s.competitors?.tracked) gaps.push(t('المنافسين', 'Competitors'));
      // الربحية: المنصة مافيهاش تكلفة الرحلات، فالعقل مابيقترحش على الربح لحد ما تتسجل
      if (s.growth_advice && !s.growth_advice.costs_known) gaps.push(t('تكلفة الرحلات (لتوصيات الربحية)', 'Trip costs (for profitability advice)'));
      const row = (k, v) => `<div class="br-fact"><span>${esc(k)}</span><p dir="auto">${v ? esc(String(v).slice(0, 220)) : `<em>${t('مش معروف لسه', 'Not known yet')}</em>`}</p></div>`;
      const known = [u.business_name || s.website?.name, u.services, u.audience, u.goal, marketsKnown, s.competitors?.tracked].filter(Boolean).length;
      return `<section class="br-card"><div class="br-head"><div><h2>${t('العقل فاهم عن شركتك إيه', 'What the brain knows about your business')}</h2>
        <p class="br-muted">${t('كل حاجة هنا من بيانات حسابك الحقيقية. اللي ناقص بيخلّي القرارات أضعف.', 'Everything here comes from your real account data. Gaps make decisions weaker.')}</p></div>
        <div class="br-meter" role="img" aria-label="${known}/6"><b>${known}/6</b><span>${t('اكتمال الفهم', 'Understanding')}</span></div></div>
        <div class="br-facts">${row(t('الشركة', 'Company'), u.business_name || s.website?.name)}${row(t('الخدمات والوجهات', 'Services & destinations'), u.services)}${row(t('الجمهور', 'Audience'), u.audience)}${row(t('الهدف', 'Goal'), u.goal)}${row(t('الأسواق', 'Markets'), (u.markets?.countries || []).length ? u.markets.countries.join('، ') : (s.market && s.market.markets_default === false ? s.market.markets.map(cn).join('، ') : ''))}${row(t('المنافسين المتابَعين', 'Tracked competitors'), s.competitors?.tracked ?? null)}</div>
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

    const CC = { EG: ['مصر', 'Egypt'], GB: ['بريطانيا', 'UK'], US: ['أمريكا', 'US'], DE: ['ألمانيا', 'Germany'], FR: ['فرنسا', 'France'], IT: ['إيطاليا', 'Italy'], ES: ['إسبانيا', 'Spain'], CA: ['كندا', 'Canada'], AU: ['أستراليا', 'Australia'], NL: ['هولندا', 'Netherlands'], PL: ['بولندا', 'Poland'], GR: ['اليونان', 'Greece'], PT: ['البرتغال', 'Portugal'], AE: ['الإمارات', 'UAE'], SA: ['السعودية', 'Saudi Arabia'], JO: ['الأردن', 'Jordan'], MA: ['المغرب', 'Morocco'], TR: ['تركيا', 'Turkey'] };
    const cn = c => esc(CC[c] ? t(...CC[c]) : c);
    const SRC = { govuk: 'GOV.UK', ecb: 'ECB', eurostat: 'Eurostat', nager: 'Nager.Date', worldbank: 'World Bank' };
    // ذكاء السوق: كل رقم بمصدره وتاريخه، والمصدر اللي فشل بيظهر كده بدل رقم
    function market(s) {
      const m = s.market;
      if (!m) return '';
      const rows = [];
      if (m.travel_advice) rows.push([t('تحذيرات السفر البريطانية عن ', 'UK travel advice for ') + cn(m.destination), esc(m.travel_advice.updated_at) + (m.travel_advice.recent ? ` <b class="br-new">${t('جديد', 'New')}</b>` : '') + (m.travel_advice.change ? `<br><span dir="ltr">${esc(m.travel_advice.change)}</span>` : ''), 'GOV.UK', m.travel_advice.url]);
      if (m.demand) rows.push([t('ليالي الزوار الأجانب في ', 'Foreign guest nights in ') + cn(m.demand.country), num(m.demand.nights) + ' (' + esc(m.demand.period) + ')' + (m.demand.change_pct != null ? ` · <b dir="ltr">${m.demand.change_pct > 0 ? '+' : ''}${m.demand.change_pct}%</b> ${t('عن السنة اللي فاتت', 'vs last year')}` : ''), 'Eurostat']);
      if (m.arrivals) rows.push([t('السياح الداخلين ', 'International arrivals to ') + cn(m.arrivals.country), num(m.arrivals.latest.value) + ' (' + esc(m.arrivals.latest.year) + ')' + (m.arrivals.previous ? ` · ${num(m.arrivals.previous.value)} (${esc(m.arrivals.previous.year)})` : ''), 'World Bank']);
      if (m.fx) rows.push([t('الدولار لكل يورو', 'USD per EUR'), `<span dir="ltr">${m.fx.latest} (${m.fx.change_pct > 0 ? '+' : ''}${m.fx.change_pct}% ${t('في 90 يوم', 'in 90 days')})</span>`, 'ECB']);
      if (m.fx_gbp) rows.push([t('الإسترليني لكل يورو', 'GBP per EUR'), `<span dir="ltr">${m.fx_gbp.latest} (${m.fx_gbp.change_pct > 0 ? '+' : ''}${m.fx_gbp.change_pct}% ${t('في 90 يوم', 'in 90 days')})</span>`, 'ECB']);
      Object.entries(m.economy || {}).forEach(([cc, e]) => rows.push([t('نمو اقتصاد ', 'GDP growth, ') + cn(cc), `<span dir="ltr">${e.gdp_growth}%</span> (${esc(e.year)})`, 'World Bank']));
      const hol = (m.holidays || []).slice(0, 6).map(h => `<li>${cn(h.country)}: ${esc(h.name)} — ${esc(h.date)} <span class="br-muted">(${t('بعد ', 'in ')}${h.days_away} ${t('يوم', 'days')})</span></li>`).join('');
      const failed = Object.values(m.sources || {}).filter(x => x.status === 'error');
      return `<section class="br-card"><div class="br-head"><div><h3>${t('ذكاء السوق', 'Market intelligence')}</h3>
        <p class="br-muted">${t('الوجهة: ', 'Destination: ') + cn(m.destination)} · ${t('الأسواق: ', 'Markets: ') + (m.markets || []).map(cn).join('، ')}${m.markets_default ? ' ' + t('(أسواق افتراضية لحد ما تحدد أسواقك)', '(default markets until you set yours)') : ''}</p></div></div>
        ${rows.length ? `<div class="p-table-scroll"><table class="p-table br-market"><tbody>${rows.map(r => `<tr><th scope="row">${r[0]}</th><td>${r[1]}</td><td class="br-src">${r[3] ? `<a href="${esc(r[3])}" target="_blank" rel="noopener">${esc(r[2])}</a>` : esc(r[2])}</td></tr>`).join('')}</tbody></table></div>` : `<p class="br-muted">${t('بنجمع بيانات السوق من المصادر الرسمية؛ بتظهر هنا بعد أول تحديث.', 'Collecting market data from official sources; it appears here after the first update.')}</p>`}
        ${hol ? `<h4>${t('إجازات رسمية قريبة في أسواقك', 'Upcoming public holidays in your markets')}</h4><ul class="br-proof">${hol}</ul>` : ''}
        ${failed.length ? `<p class="br-muted">${t('مصادر ماقدرناش نقراها آخر مرة: ', 'Sources we could not read last time: ')}${failed.map(x => esc(SRC[x.source] || x.source)).join('، ')}</p>` : ''}</section>`;
    }

    function autonomy() {
      const on = !!data.autonomy;
      return `<section class="br-card br-share ${on ? 'br-on' : ''}"><div><h3>${t('التشغيل الذاتي', 'Autopilot for the brain')}</h3>
        <p class="br-muted">${t('لما يبقى شغال، العقل بيوافق لوحده على القرارات الجديدة اللي مافيهاش صرف، ويحوّلها لمهام في CRM بمواعيد، ويبعتلك تنبيه للمهم منها، ويحطها في فرص ومخاطر اللوحة التنفيذية. أي حاجة فيها صرف فلوس (إعلانات، بيانات مدفوعة) بتفضل مستنية موافقتك.', 'When on, the brain approves new decisions that involve no spending by itself, turns them into dated CRM tasks, alerts you about the important ones and adds them to the executive opportunities and risks. Anything that spends money (ads, paid data) still waits for your approval.')}</p>
        ${on ? `<p class="br-done">✓ ${t('شغال', 'On')}</p>` : ''}</div>
        <button type="button" class="p-btn ${on ? 'outline' : 'primary'}" id="brAuto">${on ? t('وقّف التشغيل الذاتي', 'Turn off') : t('شغّل التشغيل الذاتي', 'Turn on')}</button></section>`;
    }

    function sharing() {
      const sh = data.sharing || {};
      return `<section class="br-card br-share"><div><h3>${t('اتعلّم من الشركات التانية', 'Learn from other companies')}</h3>
        <p class="br-muted">${t('لو وافقت، نتيجة قراراتك (نفع ولا لأ) بتدخل في نسب مجمّعة من غير اسمك ولا موقعك ولا أرقامك، ومقابلها بتشوف القرار نفع عند كام في المية من الشركات. أي نسبة مابتظهرش غير لو جاية من ', 'If you agree, whether your decisions worked joins anonymous totals — never your name, site or numbers — and in return you see how often each decision worked for others. A figure only appears when it comes from ')}${sh.min_companies || 5}${t(' شركات أو أكتر.', ' or more companies.')}</p>
        ${sh.opted_in ? `<p class="br-done">✓ ${t('بتشارك وبتستفيد', 'You share and benefit')}${sh.patterns ? ' · ' + sh.patterns + t(' نوع قرار ليه نتايج مجمّعة', ' decision types with pooled results') : ' · ' + t('لسه مفيش شركات كفاية للنتايج المجمّعة', 'not enough companies yet for pooled results')}</p>` : ''}</div>
        <button type="button" class="p-btn ${sh.opted_in ? 'outline' : 'primary'}" id="brShare">${sh.opted_in ? t('أوقف المشاركة', 'Stop sharing') : t('شارك واستفيد', 'Share and benefit')}</button></section>`;
    }

    function decisionCard(d) {
      const st = STATUS[d.status] || [d.status, d.status], area = AREA[d.area] || [d.area, d.area];
      const measured = d.outcome ? `<p class="br-outcome ${esc(d.outcome)}">${t('النتيجة: ', 'Result: ')}${esc(t(...(OUTCOME[d.outcome] || [d.outcome, d.outcome])))}${d.baseline != null && d.result != null ? ` <span dir="ltr">(${num(d.baseline)} → ${num(d.result)})</span>` : ''}</p>` : '';
      const buttons = d.status === 'proposed' ? `<button type="button" class="p-btn xs primary" data-set="approved" data-id="${d.id}">${t('موافق، نشتغل عليه', 'Approve')}</button><button type="button" class="p-btn xs outline" data-set="dismissed" data-id="${d.id}">${t('مش دلوقتي', 'Not now')}</button>`
        : d.status === 'approved' ? `${d.requires_spend ? '' : `<span class="br-task">✓ ${t('اتعمل مهمة في CRM', 'Added as a CRM task')}</span>`}<button type="button" class="p-btn xs primary" data-set="done" data-id="${d.id}">${t('اتنفّذ', 'Mark done')}</button>`
        : d.status === 'dismissed' ? `<button type="button" class="p-btn xs outline" data-set="proposed" data-id="${d.id}">${t('رجّعه للمقترحات', 'Restore')}</button>` : '';
      return `<li class="br-decision ${esc(d.status)}"><div class="br-dhead"><span class="br-area">${esc(t(...area))}</span><span class="br-status">${esc(t(...st))}</span>${d.requires_spend ? `<span class="br-spend">${t('فيه صرف — بموافقتك بس', 'Involves spend — only with your approval')}</span>` : ''}</div>
        <h4 dir="auto">${esc(L(d.title))}</h4><p dir="auto">${esc(L(d.why))}</p>
        ${(d.evidence || []).length ? `<ul class="br-proof">${d.evidence.map(e => `<li dir="auto">${esc(L(e))}</li>`).join('')}</ul>` : ''}${measured}
        <div class="br-actions">${d.service_action && ['proposed', 'approved'].includes(d.status) ? `<button type="button" class="p-btn xs primary" data-act="${d.id}">${esc(t(...(ACT_LABEL[d.service_action] || ['نفّذ', 'Run'])))}</button>` : ''}<a class="p-btn xs${d.service_action ? ' outline' : ''}" href="${esc(d.action_url || '#')}">${esc(L(d.action))}</a>${buttons}</div>${actionBox(d)}</li>`;
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
      if (data.disabled) { root.innerHTML = `<section class="br-card"><h3>${t('العقل الاستراتيجي متوقف مؤقتًا', 'The strategic brain is paused')}</h3><p class="br-muted">${t('الإدارة وقفته مؤقتًا. خدماتك كلها شغالة عادي.', 'It was paused by the platform team. All your services keep working.')}</p></section>`; return; }
      const sites = data.websites || [];
      if (!sites.length) { root.innerHTML = `<section class="br-card"><h3>${t('ضيف موقعك الأول', 'Add your website first')}</h3><a class="p-btn" href="/websites">${t('ضيف موقع', 'Add website')}</a></section>`; return; }
      const s = data.snapshot;
      root.innerHTML = `${sites.length > 1 ? `<label class="br-site">${t('الموقع', 'Website')} <select id="brSite" class="p-select">${sites.map(x => `<option value="${x.id}" ${Number(x.id) === Number(data.website_id) ? 'selected' : ''}>${esc(x.company_name || x.main_url)}</option>`).join('')}</select></label>` : ''}
        ${note ? `<p class="br-note ${note.error ? 'error' : ''}" role="status">${esc(note.text)}</p>` : ''}
        ${decisions(data.decisions || [], data.results || {})}${signals(s)}${market(s)}${understanding(s)}${autonomy()}${sharing()}`;
      const sel = root.querySelector('#brSite'); if (sel) sel.onchange = () => load();
      const au = root.querySelector('#brAuto'); if (au) au.onclick = async () => {
        if (busy) return; busy = true; au.disabled = true;
        try { await req('/api/brain/autonomy', { on: !data.autonomy }); await load(); return; }
        catch (e) { note = { text: e.message, error: true }; }
        finally { busy = false; }
        render();
      };
      const sh = root.querySelector('#brShare'); if (sh) sh.onclick = async () => {
        if (busy) return; busy = true; sh.disabled = true;
        try { await req('/api/brain/sharing', { on: !data.sharing?.opted_in }); await load(); return; }
        catch (e) { note = { text: e.message, error: true }; }
        finally { busy = false; }
        render();
      };
      root.querySelectorAll('[data-act]').forEach(b => { b.onclick = async () => {
        if (busy) return; const id = Number(b.dataset.act); act = { id, loading: true }; render();
        try { act = { id, preview: (await req('/api/brain/decisions/' + id + '/action?website_id=' + wid())).preview }; }
        catch (e) { act = { id, error: e.message }; }
        render();
      }; });
      const cancel = root.querySelector('[data-act-cancel]'); if (cancel) cancel.onclick = () => { act = null; render(); };
      const run = root.querySelector('[data-act-run]'); if (run) run.onclick = async () => {
        if (busy) return; busy = true; const id = Number(run.dataset.actRun); act = { id, loading: true }; render();
        try { const r = await req('/api/brain/decisions/' + id + '/action?website_id=' + wid(), { confirm: true, website_id: wid() }); data.decisions = r.decisions; data.results = r.results; act = { id, result: r.result }; }
        catch (e) { act = { id, error: e.message }; }
        finally { busy = false; render(); }
      };
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
