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
    const ACT_LABEL = { review_drafts: ['✍️ جهّز ردود التقييمات', '✍️ Draft the review replies'], lead_followups: ['✍️ جهّز رسائل المتابعة', '✍️ Draft the follow-up messages'] };
    let act = null; // {id, preview?, result?, error?, loading?}
    // مسودات متابعة العملاء المحتملين: قوالب جاهزة من غير ذكاء اصطناعي، بتتحط ملاحظة على العميل في الـ CRM ومفيش حاجة بتتبعت
    function leadBox(d) {
      if (act.loading) return `<div class="br-act" role="status">${t(`بنجهّز المسودات… اتكتب ${num(act.progress?.done || 0)} من ${num(act.progress?.target || 0)}`, `Preparing drafts… ${num(act.progress?.done || 0)} of ${num(act.progress?.target || 0)}`)}</div>`;
      if (act.error) return `<div class="br-act error" role="alert">${esc(act.error)}</div>`;
      const p = act.result || act.preview;
      if (act.result) return `<div class="br-act done" role="status"><b>✓ ${t(`اتجهّز ${num(p.drafted)} مسودة متابعة`, `${num(p.drafted)} follow-up drafts prepared`)}</b>${p.skipped ? ` <span class="br-muted">${t(`(${num(p.skipped)} ماتجهّزوش)`, `(${num(p.skipped)} failed)`)}</span>` : ''}
        <p>${t('كل مسودة ملاحظة مثبّتة على العميل في الـ CRM. راجع النص وعدّله وابعته بنفسك. مفيش حاجة اتبعتت لأي عميل.', 'Each draft is a pinned note on the lead in your CRM. Review, edit and send it yourself. Nothing was sent to any customer.')}</p>
        <a class="p-btn xs primary" href="${esc(p.review_url)}">${t('افتح العملاء المحتملين', 'Open leads')}</a></div>`;
      if (!p.will_draft) return `<div class="br-act" role="status"><p>${p.ready_drafts ? t(`فيه ${num(p.ready_drafts)} مسودة جاهزة في الـ CRM.`, `${num(p.ready_drafts)} drafts are already waiting in your CRM.`) : t('مفيش عملاء متأخرين محتاجين مسودة دلوقتي.', 'No overdue leads need a draft right now.')}</p>
        <a class="p-btn xs primary" href="${esc(p.review_url)}">${t('افتح العملاء المحتملين', 'Open leads')}</a></div>`;
      return `<div class="br-act"><b>${t('قبل ما نبدأ:', 'Before we start:')}</b><ul>
        <li>${t(`هنجهّز مسودة متابعة لـ ${num(p.will_draft)} عميل متأخر، الأعلى قيمة الأول.`, `We will prepare a follow-up draft for ${num(p.will_draft)} overdue leads, highest value first.`)}</li>
        <li>${t('المسودة من قالب جاهز (من غير ذكاء اصطناعي)، فمفيش استهلاك من حدود حسابك.', 'Drafts come from a ready template (no AI), so nothing is used from your plan limits.')}</li>
        <li>${t('مفيش رسالة هتتبعت لوحدها. المسودة ملاحظة على العميل وانت اللي بتبعتها.', 'No message is sent automatically. The draft is a note on the lead and you send it.')}</li></ul>
        <div class="br-actions"><button type="button" class="p-btn xs primary" data-act-run="${d.id}">${t('موافق، جهّز المسودات', 'Approve, prepare the drafts')}</button><button type="button" class="p-btn xs outline" data-act-cancel="1">${t('لأ', 'Cancel')}</button></div></div>`;
    }

    function actionBox(d) {
      if (!act || act.id !== d.id) return '';
      if (d.service_action === 'lead_followups') return leadBox(d);
      if (act.loading) return `<div class="br-act" role="status">${act.progress ? t(`بنكتب الردود… اتكتب ${num(act.progress.done)} من ${num(act.progress.target)}`, `Drafting replies… ${num(act.progress.done)} of ${num(act.progress.target)} done`) : t('بنجهّز…', 'Preparing…')}</div>`;
      if (act.error) return `<div class="br-act error" role="alert">${esc(act.error)}</div>`;
      const p = act.result || act.preview;
      if (act.result) return `<div class="br-act done" role="status"><b>✓ ${t(`اتكتب ${num(p.drafted)} رد`, `${num(p.drafted)} replies drafted`)}</b>${p.skipped ? ` <span class="br-muted">${t(`(${num(p.skipped)} ماتكتبوش لأن الذكاء الاصطناعي مش متاح دلوقتي)`, `(${num(p.skipped)} skipped: AI unavailable right now)`)}</span>` : ''}
        <p>${t('الردود مستنية موافقتك في صفحة التقييمات. راجع كل رد وعدّله لو حابب، وبعدين انشره. مفيش حاجة اتنشرت لوحدها.', 'The replies are waiting for your approval on the reviews page. Review or edit each one, then publish. Nothing was published.')}</p>
        <a class="p-btn xs primary" href="${esc(p.review_url)}">${t(`راجع الردود (${num(p.ready_drafts)})`, `Review replies (${num(p.ready_drafts)})`)}</a></div>`;
      if (!p.will_draft) return `<div class="br-act" role="status"><p>${p.ready_drafts ? t(`فيه ${num(p.ready_drafts)} رد جاهز مستني موافقتك.`, `${num(p.ready_drafts)} replies are ready for your approval.`) : t('مفيش تقييمات سلبية من غير رد في آخر 30 يوم.', 'No unanswered negative reviews in the last 30 days.')}${p.not_on_google ? ' ' + t(`(${num(p.not_on_google)} تقييم مش موجود على صفحتك في جوجل دلوقتي، ومش هيتردّ عليه)`, `(${num(p.not_on_google)} reviews are no longer on your Google profile and cannot be answered)`) : ''}</p>${p.ready_drafts ? `<a class="p-btn xs primary" href="${esc(p.review_url)}">${t('راجع الردود', 'Review replies')}</a>` : ''}</div>`;
      return `<div class="br-act"><b>${t('قبل ما نبدأ:', 'Before we start:')}</b><ul>
        <li>${t(`هنكتب ${num(p.will_draft)} رد على أسوأ التقييمات اللي من غير رد (من ${num(p.without_draft)}).`, `We will draft ${num(p.will_draft)} replies for the worst unanswered reviews (of ${num(p.without_draft)}).`)}</li>
        ${p.not_on_google ? `<li>${t(`${num(p.not_on_google)} تقييم مش موجود على صفحتك في جوجل دلوقتي (اتمسح أو من موقع جوجل قديم)، فمش هنكتبله رد. اعمل مزامنة من محتوى Google Business.`, `${num(p.not_on_google)} reviews are no longer on your Google profile (deleted or from an older location), so we skip them. Sync from Google Business content.`)}</li>` : ''}
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

    const DAYN = { sun: ['الأحد', 'Sun'], mon: ['الاثنين', 'Mon'], tue: ['الثلاثاء', 'Tue'], wed: ['الأربعاء', 'Wed'], thu: ['الخميس', 'Thu'] };
    const GMETRIC = { 'bookings.last_30': ['حجوزات آخر 30 يوم', 'Bookings, last 30 days'], 'bookings.revenue_30': ['إيراد آخر 30 يوم', 'Revenue, last 30 days'], 'reputation.avg_rating': ['متوسط التقييمات', 'Average rating'],
      'reputation.unanswered_negative': ['تقييمات سلبية من غير رد', 'Unanswered negative reviews'], 'scores.seo_score': ['درجة الموقع', 'Site score'], 'social.published_30': ['منشورات آخر 30 يوم', 'Posts, last 30 days'], 'chat.pending_approval': ['ردود شات مستنية', 'Chat replies waiting'] };
    const GSTATUS = { achieved: ['تحقق ✓', 'Achieved ✓'], ahead: ['متقدم', 'Ahead'], on_track: ['على المسار', 'On track'], behind: ['متأخر', 'Behind'], missed: ['فات ميعاده', 'Missed'], unknown: ['مش مقاس', 'Not measured'] };
    const REASONS = [['not_relevant', 'مش مناسب لشركتي', 'Not relevant to me'], ['later', 'بعد أسبوعين', 'In two weeks'], ['done_elsewhere', 'اتعمل برّه المنصة', 'Done outside the platform']];
    let dismissFor = null, goalForm = false;

    function decisionCard(d) {
      const st = STATUS[d.status] || [d.status, d.status], area = AREA[d.area] || [d.area, d.area];
      const measured = d.outcome ? `<p class="br-outcome ${esc(d.outcome)}">${t('النتيجة: ', 'Result: ')}${esc(t(...(OUTCOME[d.outcome] || [d.outcome, d.outcome])))}${d.baseline != null && d.result != null ? ` <span dir="ltr">(${num(d.baseline)} → ${num(d.result)})</span>` : ''}</p>` : '';
      const buttons = d.status === 'proposed' ? `<button type="button" class="p-btn xs primary" data-set="approved" data-id="${d.id}">${t('موافق، نشتغل عليه', 'Approve')}</button><button type="button" class="p-btn xs outline" data-dismiss="${d.id}">${t('مش دلوقتي', 'Not now')}</button>`
        : d.status === 'approved' ? `${d.requires_spend ? '' : `<span class="br-task">✓ ${t('اتعمل مهمة في CRM', 'Added as a CRM task')}</span>`}<button type="button" class="p-btn xs primary" data-set="done" data-id="${d.id}">${t('اتنفّذ', 'Mark done')}</button>`
        : d.status === 'dismissed' ? `<button type="button" class="p-btn xs outline" data-set="proposed" data-id="${d.id}">${t('رجّعه للمقترحات', 'Restore')}</button>` : '';
      const why = dismissFor === d.id ? `<div class="br-act" role="group" aria-label="${t('ليه؟', 'Why?')}"><b>${t('ليه مش دلوقتي؟ (العقل هيفتكر ومش هيكرره)', 'Why not now? (the brain remembers)')}</b><div class="br-actions">${REASONS.map(r => `<button type="button" class="p-btn xs outline" data-reason="${r[0]}" data-id="${d.id}">${t(r[1], r[2])}</button>`).join('')}<button type="button" class="p-btn xs outline" data-reason="" data-id="${d.id}">${t('من غير سبب', 'No reason')}</button></div></div>` : '';
      return `<li class="br-decision ${esc(d.status)}"><div class="br-dhead"><span class="br-area">${esc(t(...area))}</span><span class="br-status">${esc(t(...st))}</span>${d.requires_spend ? `<span class="br-spend">${t('فيه صرف — بموافقتك بس', 'Involves spend — only with your approval')}</span>` : ''}</div>
        <h4 dir="auto">${esc(L(d.title))}</h4><p dir="auto">${esc(L(d.why))}</p>
        ${(d.evidence || []).length ? `<ul class="br-proof">${d.evidence.map(e => `<li dir="auto">${esc(L(e))}</li>`).join('')}</ul>` : ''}${measured}
        <div class="br-actions">${d.service_action && ['proposed', 'approved'].includes(d.status) ? `<button type="button" class="p-btn xs primary" data-act="${d.id}">${esc(t(...(ACT_LABEL[d.service_action] || ['نفّذ', 'Run'])))}</button>` : ''}<a class="p-btn xs${d.service_action ? ' outline' : ''}" href="${esc(d.action_url || '#')}">${esc(L(d.action))}</a>${buttons}</div>${why}${actionBox(d)}</li>`;
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

    // خطة الأسبوع: اللي العقل شايف إنه يتعمل الأسبوع ده، بحسب طاقتك وأهدافك. القرار المتأجل بيتقال ليه.
    function plan() {
      const p = data.plan; if (!p) return '';
      const byId = Object.fromEntries((data.decisions || []).map(d => [d.key, d]));
      const title = k => esc(L(byId[k]?.title) || k);
      const items = (p.items || []).map(i => `<li><span class="br-day">${esc(t(...(DAYN[i.day] || [i.day, i.day])))}</span><div><b dir="auto">${title(i.key)}</b>${i.goal_aligned ? ` <span class="br-tag">${t('بيخدم هدفك', 'Serves your goal')}</span>` : ''}${i.requires_spend ? ` <span class="br-spend">${t('محتاج موافقتك على الصرف', 'Needs spend approval')}</span>` : ''}${i.status === 'approved' ? ` <span class="br-task">${t('شغالين عليه', 'In progress')}</span>` : ''}</div><small>${t('جهد', 'Effort')} ${i.effort}</small></li>`).join('');
      const why = { capacity: t('زيادة عن طاقة الأسبوع', 'Over this week capacity'), blocked: t('لازم يتعمل قبله', 'Needs a prior step') };
      const later = (p.deferred || []).map(x => `<li><span dir="auto">${title(x.key)}</span> <small class="br-muted">— ${esc(why[x.reason] || x.reason)}${x.blocked_by ? ': ' + x.blocked_by.map(title).join(', ') : ''}</small></li>`).join('');
      const cap = data.prefs?.weekly_capacity || p.capacity;
      return `<section class="br-card"><div class="br-head"><div><h3>${t('خطة الأسبوع', 'This week plan')}</h3>
        <p class="br-muted">${t('مرتّبة بالأولوية وبما يناسب طاقتك. اللي زيادة بيتأجل للأسبوع الجاي بدل ما يتراكم عليك.', 'Ranked by priority and sized to your capacity. Extra items wait instead of piling up.')}</p></div>
        <div class="br-results"><b>${p.used}/${p.capacity}</b><span>${t('نقاط جهد', 'effort points')}</span></div></div>
        ${items ? `<ol class="br-plan">${items}</ol>` : `<p class="br-done">✓ ${t('مفيش حاجة مطلوبة الأسبوع ده.', 'Nothing needed this week.')}</p>`}
        ${later ? `<details class="br-past"><summary>${t('متأجل', 'Deferred')} (${p.deferred.length})</summary><ul class="br-proof">${later}</ul></details>` : ''}
        <label class="br-field">${t('طاقتك في الأسبوع (نقاط جهد)', 'Your weekly capacity (effort points)')}<input type="number" id="brCap" class="p-input" min="1" max="20" value="${cap}"><small>${t('قرار بسيط = 1، سيو/سوشيال/إعلانات = 3.', 'Simple = 1, SEO/social/ads = 3.')}</small></label></section>`;
    }

    function goals() {
      const list = data.goals || [], metrics = data.goal_metrics || {};
      const fmt = v => v === null || v === undefined ? '—' : Number(v).toLocaleString('en-US', { maximumFractionDigits: 2 });
      const row = g => { const pr = g.progress || {}, st = GSTATUS[pr.status] || GSTATUS.unknown, open = g.status === 'active';
        return `<li class="br-goal ${esc(pr.status || '')}"><div><b>${esc(t(...(GMETRIC[g.metric_key] || [g.metric_key, g.metric_key])))}</b> <span class="br-status">${esc(open ? t(...st) : t(...(GSTATUS[g.status] || [g.status, g.status])))}</span>
          <p class="br-muted" dir="ltr">${fmt(g.baseline)} → <b>${fmt(g.target)}</b> · ${t('دلوقتي', 'now')} ${fmt(pr.current)} · ${esc(g.deadline)}</p>
          ${pr.pct != null ? `<div class="br-bar" role="img" aria-label="${pr.pct}%"><span style="width:${pr.pct}%"></span><i style="inset-inline-start:${pr.expected_pct}%" title="${t('المفروض دلوقتي', 'Expected by now')}"></i></div>` : ''}
          ${open && pr.needed_per_week != null ? `<small class="br-muted">${t('المطلوب في الأسبوع: ', 'Needed per week: ')}${fmt(pr.needed_per_week)} · ${t('فاضل ', '')}${pr.weeks_left}${t(' أسبوع', ' weeks left')}</small>` : ''}</div>
          ${open ? `<button type="button" class="p-btn xs outline" data-goal-cancel="${g.id}">${t('إلغاء', 'Cancel')}</button>` : ''}</li>`; };
      const form = goalForm ? `<div class="br-act"><label class="br-field">${t('المقياس', 'Metric')}<select id="brGM" class="p-select">${Object.keys(metrics).map(k => `<option value="${esc(k)}">${esc(t(...(GMETRIC[k] || [k, k])))}</option>`).join('')}</select></label>
        <label class="br-field">${t('الرقم المستهدف', 'Target')}<input type="number" step="any" id="brGT" class="p-input" dir="ltr"></label>
        <label class="br-field">${t('الميعاد', 'Deadline')}<input type="date" id="brGD" class="p-input" dir="ltr"></label>
        <div class="br-actions"><button type="button" class="p-btn xs primary" id="brGSave">${t('احفظ الهدف', 'Save goal')}</button><button type="button" class="p-btn xs outline" id="brGHide">${t('إلغاء', 'Cancel')}</button></div></div>` : '';
      return `<section class="br-card"><div class="br-head"><div><h3>${t('أهدافك', 'Your goals')}</h3><p class="br-muted">${t('حدد رقم عايز توصله وميعاد. العقل بيقيس التقدم وبيرفع أولوية القرارات اللي بتخدم الهدف، وبيقولك لو اتأخرت عن المسار.', 'Set a number and a date. The brain tracks progress and raises the priority of decisions that serve the goal.')}</p></div>
        ${goalForm ? '' : `<button type="button" class="p-btn xs primary" id="brGNew">${t('+ هدف جديد', '+ New goal')}</button>`}</div>
        ${list.length ? `<ul class="br-goals">${list.map(row).join('')}</ul>` : `<p class="br-muted">${t('مفيش أهداف لسه.', 'No goals yet.')}</p>`}${form}</section>`;
    }

    // الاستقلالية لكل مجال + الملخص الأسبوعي + سجل العقل
    function control() {
      if (!data.control_available) return '';
      const pol = data.policy || {}, pr = data.prefs || {}, EV = { approved: ['وافقت', 'You approved'], auto_approved: ['العقل وافق تلقائيًا', 'Auto-approved'], dismissed: ['اتأجل/اترفض', 'Dismissed'], marked_done: ['علّمته اتنفّذ', 'Marked done'], restored: ['اترجّع', 'Restored'], measured: ['اتقاس', 'Measured'], resolved: ['اتحل', 'Resolved'], goal_set: ['هدف جديد', 'Goal set'], goal_achieved: ['هدف تحقق', 'Goal achieved'], goal_missed: ['هدف فات ميعاده', 'Goal missed'], goal_cancelled: ['هدف اتلغى', 'Goal cancelled'], policy: ['تغيير سياسة', 'Policy changed'], digest_sent: ['ملخص اتبعت', 'Digest sent'] };
      const areas = Object.keys(AREA).map(a => `<label class="br-check-row"><input type="checkbox" data-policy="${a}" ${pol[a] === 'suggest' ? '' : 'checked'}><span><b>${esc(t(...AREA[a]))}</b><small>${pol[a] === 'suggest' ? t('اقتراح بس: مفيش تنفيذ تلقائي', 'Suggest only: no auto-execution') : t('التنفيذ التلقائي مسموح (لما التشغيل الذاتي شغال)', 'Auto-execution allowed (when autopilot is on)')}</small></span></label>`).join('');
      const log = (data.audit || []).map(a => `<li><small class="br-muted" dir="ltr">${esc(String(a.created_at).slice(0, 16))}</small> ${esc(t(...(EV[a.event] || [a.event, a.event])))}${a.decision_key ? ` · <span dir="auto">${esc(L(((data.decisions || []).find(d => d.key === a.decision_key) || {}).title) || a.decision_key)}</span>` : ''}</li>`).join('');
      return `<section class="br-card"><h3>${t('التحكم والشفافية', 'Control & transparency')}</h3>
        <details class="br-past"><summary>${t('التنفيذ التلقائي لكل مجال', 'Auto-execution per area')}</summary><p class="br-muted">${t('شيل العلامة من أي مجال عشان العقل يقترح فيه بس ومايعملش حاجة لوحده. القرارات اللي فيها صرف فلوس مابتتنفّذش لوحدها أبدًا.', 'Untick an area so the brain only suggests there. Spending decisions never run on their own.')}</p>${areas}</details>
        <label class="br-check-row"><input type="checkbox" id="brDigest" ${pr.digest_enabled ? 'checked' : ''}><span><b>${t('ملخص أسبوعي بالإيميل', 'Weekly email summary')}</b><small>${t('خطتك وأهدافك ونتايج القرارات مرة في الأسبوع. مقفول لحد ما تفعّله.', 'Your plan, goals and results once a week. Off until you turn it on.')}</small></span></label>
        ${log ? `<details class="br-past"><summary>${t('سجل العقل (آخر العمليات)', 'Brain log (latest actions)')}</summary><ul class="br-proof">${log}</ul></details>` : ''}</section>`;
    }

    function render() {
      if (!data) return;
      if (data.disabled) { root.innerHTML = `<section class="br-card"><h3>${t('العقل الاستراتيجي متوقف مؤقتًا', 'The strategic brain is paused')}</h3><p class="br-muted">${t('الإدارة وقفته مؤقتًا. خدماتك كلها شغالة عادي.', 'It was paused by the platform team. All your services keep working.')}</p></section>`; return; }
      const sites = data.websites || [];
      if (!sites.length) { root.innerHTML = `<section class="br-card"><h3>${t('ضيف موقعك الأول', 'Add your website first')}</h3><a class="p-btn" href="/websites">${t('ضيف موقع', 'Add website')}</a></section>`; return; }
      const s = data.snapshot;
      root.innerHTML = `${sites.length > 1 ? `<label class="br-site">${t('الموقع', 'Website')} <select id="brSite" class="p-select">${sites.map(x => `<option value="${x.id}" ${Number(x.id) === Number(data.website_id) ? 'selected' : ''}>${esc(x.company_name || x.main_url)}</option>`).join('')}</select></label>` : ''}
        ${note ? `<p class="br-note ${note.error ? 'error' : ''}" role="status">${esc(note.text)}</p>` : ''}
        ${decisions(data.decisions || [], data.results || {})}${plan()}${goals()}${signals(s)}${market(s)}${understanding(s)}${autonomy()}${control()}${sharing()}`;
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
        if (busy) return; busy = true; const id = Number(run.dataset.actRun), target = act.preview?.will_draft || 0;
        // دفعات صغيرة لحد ما نوصل للعدد اللي اتوافق عليه، أو دفعة ماتكتبش حاجة (الذكاء الاصطناعي مش متاح)
        const total = { drafted: 0, skipped: 0 }; let last = null;
        act = { id, loading: true, progress: { done: 0, target } }; render();
        try {
          while (total.drafted < target) {
            const r = await req('/api/brain/decisions/' + id + '/action?website_id=' + wid(), { confirm: true, website_id: wid() });
            data.decisions = r.decisions; data.results = r.results; last = r.result;
            total.drafted += last.drafted; total.skipped += last.skipped;
            if (!last.drafted || !last.without_draft) break;
            act.progress.done = total.drafted; render();
          }
          act = { id, result: { ...last, drafted: total.drafted, skipped: total.skipped } };
        } catch (e) { act = { id, error: total.drafted ? e.message + ' ' + t(`(اتكتب ${num(total.drafted)} رد قبل المشكلة، ومستنيين موافقتك في صفحة التقييمات)`, `(${num(total.drafted)} replies were drafted before the problem and are waiting on the reviews page)`) : e.message }; }
        finally { busy = false; render(); }
      };
      // النتيجة بترجّع الخطة والأهداف والسجل متحدّثين
      const merge = r => { for (const k of ['decisions', 'results', 'plan', 'goals', 'policy', 'prefs', 'audit']) if (r[k] !== undefined) data[k] = r[k]; };
      const safe = fn => async (...a) => { if (busy) return; busy = true; try { await fn(...a); } catch (e) { note = { text: e.message, error: true }; } finally { busy = false; render(); } };
      root.querySelectorAll('[data-dismiss]').forEach(b => { b.onclick = () => { dismissFor = Number(b.dataset.dismiss); render(); }; });
      root.querySelectorAll('[data-reason]').forEach(b => { b.onclick = safe(async () => {
        const r = await req('/api/brain/decisions/' + b.dataset.id + '?website_id=' + wid(), { status: 'dismissed', reason: b.dataset.reason || null, website_id: wid() });
        merge(r); dismissFor = null; note = { text: t('اتحفظ. العقل هيفتكر اختيارك.', 'Saved. The brain will remember.') };
      }); });
      const gn = root.querySelector('#brGNew'); if (gn) gn.onclick = () => { goalForm = true; render(); };
      const gh = root.querySelector('#brGHide'); if (gh) gh.onclick = () => { goalForm = false; render(); };
      const gs = root.querySelector('#brGSave'); if (gs) gs.onclick = safe(async () => {
        const r = await req('/api/brain/goals?website_id=' + wid(), { metric_key: root.querySelector('#brGM').value, target: root.querySelector('#brGT').value, deadline: root.querySelector('#brGD').value, website_id: wid() });
        merge(r); goalForm = false; note = { text: t('اتحفظ الهدف.', 'Goal saved.') };
      });
      root.querySelectorAll('[data-goal-cancel]').forEach(b => { b.onclick = safe(async () => { merge(await req('/api/brain/goals/' + b.dataset.goalCancel + '/cancel?website_id=' + wid(), { website_id: wid() })); }); });
      root.querySelectorAll('[data-policy]').forEach(b => { b.onclick = safe(async () => {
        const r = await req('/api/brain/policy', { area: b.dataset.policy, mode: b.checked ? 'auto' : 'suggest' }); data.policy = r.policy;
      }); });
      const dg = root.querySelector('#brDigest'); if (dg) dg.onclick = safe(async () => { data.prefs = (await req('/api/brain/prefs', { digest_enabled: dg.checked })).prefs; });
      const cp = root.querySelector('#brCap'); if (cp) cp.onchange = safe(async () => { data.prefs = (await req('/api/brain/prefs', { weekly_capacity: Number(cp.value) })).prefs; await load(); });
      root.querySelectorAll('[data-set]').forEach(b => { b.onclick = async () => {
        if (busy) return; busy = true; b.disabled = true;
        try { const r = await req('/api/brain/decisions/' + b.dataset.id + '?website_id=' + wid(), { status: b.dataset.set, website_id: wid() }); merge(r); note = { text: t('اتحفظ ✓', 'Saved ✓') }; }
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
