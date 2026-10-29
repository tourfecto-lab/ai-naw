/**
 * Tourfecto - مسار السيو البسيط
 * أول حاجة العميل بيشوفها: 4 خطوات واضحة بزرار واحد لكل خطوة، وبعدها ملخص الأسبوع.
 * الأدوات المتقدمة (الخطة، المحرر، النتائج...) متاحة تحت زرار "للخبراء".
 */
(() => {
  'use strict';
  const start = async () => {
    const root = document.getElementById('seoGuideRoot'), advanced = document.getElementById('seoAdvanced'), P = window.Panel;
    if (!root || !advanced || !P) return;
    const ar = document.documentElement.lang.toLowerCase().startsWith('ar'), t = (a, e) => ar ? a : e, esc = P.esc, base = '/api/seo-expert';
    const params = new URLSearchParams(location.search);
    let setup = null, overview = null, readiness = null, health = null, run = null, markets = null, busy = false, timer = null, alertHandlers = [], factsDraft = null, factsLoading = false, autoTried = false;

    const store = { get: k => { try { return localStorage.getItem(k); } catch (e) { return null; } }, set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) {} } };
    const req = async (url, body) => {
      const r = await P.fetchJSON(url, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...body, csrf_token: setup?.csrf_token }) } : undefined);
      if (!r?.success) throw new Error(r?.error || t('حصلت مشكلة. جرّب تاني بعد شوية.', 'Something went wrong. Please try again shortly.'));
      return r.data;
    };
    const wid = () => Number(root.querySelector('#sgSite')?.value || 0);
    let keywords = null, schedules = [];

    // الأدوات المتقدمة: مخفية افتراضيًا، وبتفتح لو العميل جاي من رابط لصفحة معينة أو اختارها قبل كده
    const setAdvanced = (open, focusPage, remember = true) => {
      advanced.hidden = !open; if (remember) store.set('tf-seo-advanced', open ? '1' : '0');
      const btn = root.querySelector('#sgAdvancedToggle'); if (btn) btn.textContent = open ? t('إخفاء الأدوات المتقدمة', 'Hide advanced tools') : t('الأدوات المتقدمة (للخبراء)', 'Advanced tools (for experts)');
      if (open && focusPage) { const u = new URL(location.href); u.searchParams.set('page', focusPage); if (wid()) u.searchParams.set('website_id', wid()); location.href = u.toString(); }
    };

    const runText = s => ({
      queued: [t('المنصة بتجهز لفحص موقعك…', 'Getting ready to check your website…'), 'busy'],
      scanning: [t('المنصة بتفحص صفحات موقعك دلوقتي (بياخد كام دقيقة).', 'Checking your website pages now (takes a few minutes).'), 'busy'],
      scanned: [t('خلصنا الفحص، وبنجهز التحسينات…', 'Check finished, preparing improvements…'), 'busy'],
      planning: [t('بنجهز التحسينات المناسبة لموقعك…', 'Preparing the right improvements for your website…'), 'busy'],
      draft: [t('التحسينات جاهزة.', 'Improvements are ready.'), 'ok'],
      executing: [t('المنصة بتطبّق التحسينات على موقعك دلوقتي.', 'Applying improvements to your website now.'), 'busy'],
      monitoring: [t('اتطبقت التحسينات، وبنتأكد إنها ظاهرة على موقعك.', 'Improvements applied; confirming they appear on your site.'), 'busy'],
      completed: [t('الدورة خلصت.', 'This cycle is finished.'), 'ok'],
      failed: [t('آخر فحص ماكملش', 'The last check did not finish'), 'bad'],
    }[s] || [s, 'ok']);

    const steps = () => {
      const c = health?.connector?.state, pub = health?.publishing?.state, facts = (readiness?.checks || []).find(x => x.id === 'facts');
      const cfg = overview?.config, gscSaved = !!overview?.connections?.google_search_console?.saved;
      const siteDone = c === 'publicly_observed' || pub === 'authenticated';
      const factsDone = facts ? ['recorded', 'observed'].includes(facts.state) : false;
      const googleDone = gscSaved && health?.metrics?.gsc?.state !== 'needs_connection_review';
      const busyRun = (overview?.runs || []).some(r => ['queued', 'scanning', 'scanned', 'planning', 'executing', 'monitoring'].includes(r.status));
      const autoDone = !!(cfg && (cfg.policy?.autopilot || cfg.policy?.auto_missing_metadata) && (cfg.status === 'active' || busyRun || (overview?.runs || []).length));
      return [
        { key: 'site', done: siteDone, checking: health === null && !siteDone, title: t('اربط موقعك', 'Connect your website'), text: t('عشان المنصة تقدر تحسّن صفحات موقعك بنفسها. لو موقعك ووردبريس نزّل الإضافة، ولو مبرمج خاص المنصة بتركّب الربط لوحدها.', 'So the platform can improve your pages itself. WordPress: install the plugin. Custom site: the platform installs the connection for you.'), action: t('اربط الموقع', 'Connect website'), run: () => setAdvanced(true, 'settings') },
        { key: 'facts', done: factsDone, title: t('أكد بيانات شركتك', 'Confirm your company details'), text: t('اسم الشركة وخدماتها والبلد اللي عملاءك منه. المنصة مابتنشرش أي معلومة عن شركتك من غير ما تأكدها.', 'Company name, services and where your customers come from. Nothing about your company is published unless you confirm it.'), action: t('البيانات صح، أكدها', 'Details are correct, confirm'), run: saveFacts, form: true },
        { key: 'google', done: googleDone, title: t('اربط حساب جوجل', 'Connect Google'), text: t('عشان نشوف ترتيبك وزياراتك الحقيقية، ونبعت صفحاتك الجديدة لجوجل أول بأول. هتوافق على الصلاحيات مرة واحدة.', 'So we can see your real rankings and visits, and send new pages to Google. You approve access once.'), action: t('اربط جوجل', 'Connect Google'), run: () => { location.href = '/search-console/connect/' + wid() + '?return_to=%2Fseo-expert'; } },
        { key: 'auto', done: autoDone, title: t('شغّل الطيار الآلي', 'Turn on autopilot'), text: t('المنصة هتفحص موقعك كل أسبوع وتطبّق التحسينات لوحدها، وأي معلومة جديدة عن شركتك هتستنى موافقتك. تقدر توقفه أو ترجع في أي تعديل وقت ما تحب.', 'The platform checks your site every week and applies improvements itself; any new fact about your company waits for your approval. Pause or undo anytime.'), action: t('شغّل الطيار الآلي', 'Turn on autopilot'), run: turnOnAutopilot },
      ];
    };

    // بيانات الشركة: المنصة بتقراها من الموقع (ببلاش) وتعرضها هنا، والعميل يصحح لو محتاج ويأكد
    const FACT_FIELDS = [['business_name', t('اسم الشركة', 'Company name'), 150, 1], ['business_facts', t('خدماتك ووجهاتك', 'Your services and destinations'), 1000, 4], ['audience', t('عملاءك منين؟ (الدول أو نوع العملاء)', 'Who are your customers? (countries or type)'), 500, 2], ['goal', t('عايز توصل لإيه؟', 'What do you want to achieve?'), 500, 2], ['cta', t('رابط الحجز أو طريقة التواصل', 'Booking link or contact method'), 300, 1]];
    async function loadFacts() {
      if (factsDraft || factsLoading || !wid()) return;
      factsLoading = true; render();
      try {
        const p = (await req('/api/business-hub/sites/' + wid() + '/profile').catch(() => null))?.profile;
        const saved = (p && !p.needs_reconfirmation ? p.facts : null) || {};
        let found = {}, error = '';
        if (FACT_FIELDS.some(([k]) => !String(saved[k] || '').trim())) found = await req('/api/business-hub/sites/' + wid() + '/import', { language: ar ? 'ar' : 'en' }).catch(e => { error = e.message; return {}; });
        factsDraft = { version: Number(p?.version || 0), saved, error };
        FACT_FIELDS.forEach(([k, , max]) => { factsDraft[k] = String(saved[k] || found[k] || '').slice(0, max); });
      } finally { factsLoading = false; render(); }
    }
    function factsForm() {
      if (!factsDraft) return `<div class="sg-progress"><span class="sg-bar"></span><p>${t('بنقرا بيانات شركتك من موقعك…', 'Reading your company details from your website…')}</p></div>`;
      return `<p class="sg-muted">${factsDraft.error ? t('ماقدرناش نقرا موقعك دلوقتي، اكتب البيانات بنفسك.', 'We could not read your website right now; please type the details.') : t('جبنا البيانات دي من موقعك. صحّح أي حاجة مش مظبوطة وأكد.', 'We pulled these from your website. Fix anything that is wrong, then confirm.')}</p>
        <div class="sg-facts">${FACT_FIELDS.map(([k, label, max, rows]) => `<label>${esc(label)}<textarea class="p-select" dir="auto" data-fact="${k}" maxlength="${max}" rows="${rows}">${esc(factsDraft[k] || '')}</textarea></label>`).join('')}</div>`;
    }
    async function saveFacts() {
      root.querySelectorAll('[data-fact]').forEach(el => { factsDraft[el.dataset.fact] = el.value; });
      const missing = FACT_FIELDS.find(([k]) => String(factsDraft[k] || '').trim().length < 3);
      if (missing) throw new Error(t('اكتب: ', 'Please fill in: ') + missing[1]);
      const s = factsDraft.saved || {};
      let tz = 'UTC'; try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'; } catch (e) {}
      await req('/api/business-hub/sites/' + wid() + '/profile', { ...Object.fromEntries(FACT_FIELDS.map(([k]) => [k, String(factsDraft[k]).trim()])), offers: s.offers || '', phone: s.phone || '', email: s.email || '', logo_url: s.logo_url || '', language: ['ar', 'en'].includes(s.language) ? s.language : (ar ? 'ar' : 'en'), timezone: s.timezone || tz, version: factsDraft.version, confirmed: true });
      factsDraft = null;
      readiness = await req(base + '/sites/' + wid() + '/readiness').catch(() => readiness);
      message(t('تم ✓ بيانات شركتك اتأكدت.', 'Done ✓ Your company details are confirmed.'));
    }
    // عميل جديد خلّص الربط وباقته فيها الفحوصات: الطيار الآلي بيشتغل لوحده (من غير خصم من المحفظة)
    function maybeAutoStart() {
      if (autoTried || busy || !wid() || overview?.config) return;
      const list = steps(), q = overview?.quote;
      if (!list[0].done || !list[1].done || !list[2].done || list[3].done || !q?.ok || q.source === 'wallet') return;
      autoTried = true; act(() => turnOnAutopilot(true))();
    }

    async function turnOnAutopilot(silent = false) {
      const q = overview?.quote || setup?.quote;
      if (!q?.ok) throw new Error(t('رصيدك أو باقتك مش كفاية لدورة فحص. اشحن الرصيد أو رقّي الباقة الأول.', 'Your plan or balance is not enough for a cycle. Top up or upgrade first.'));
      const price = q.source === 'wallet' ? Number(q.price || 0) : 0, cycles = Number(overview?.plan_limits?.cycles || 4);
      const cost = price > 0 ? t('كل دورة أسبوعية بتتكلف ', 'Each weekly cycle costs ') + price.toFixed(2) + ' $' : t('الدورات الأسبوعية من ضمن باقتك.', 'Weekly cycles are included in your plan.');
      if (!silent && !confirm(t('تشغيل الطيار الآلي؟\n\n', 'Turn on autopilot?\n\n') + cost + t('\nبحد أقصى ' + cycles + ' دورة في الشهر. بتأكد إن بيانات شركتي صحيحة.', '\nUp to ' + cycles + ' cycles a month. I confirm my company details are accurate.'))) return;
      let cfg = overview?.config;
      if (cfg?.status === 'active') { await req(base + '/sites/' + wid() + '/pause', {}); }
      const brief = cfg?.brief || (await req('/api/business-hub/sites/' + wid() + '/profile'))?.profile?.facts || {};
      const policy = cfg?.policy || {};
      overview = await req(base + '/sites/' + wid() + '/configure', {
        business_name: brief.business_name || '', business_facts: brief.business_facts || '', audience: brief.audience || '', goal: brief.goal || '',
        language: ['ar', 'en'].includes(brief.language) ? brief.language : (ar ? 'ar' : 'en'), max_pages: Number(policy.max_pages || overview?.plan_limits?.pages || 100), max_cycles: cycles,
        max_cycle_price: Math.max(Number(policy.max_cycle_price || 0), price), max_data_wallet_monthly: Number(policy.max_data_wallet_monthly || 0),
        auto_missing_metadata: true, autopilot: true, facts_confirmed: true,
      });
      const busyRun = (overview.runs || []).find(r => ['queued', 'scanning', 'scanned', 'planning', 'executing'].includes(r.status));
      if (!busyRun) await req(base + '/sites/' + wid() + '/start', { request_key: 'guide_' + wid() + '_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10), quote_hash: overview.quote.hash, research_wallet_cap: 0 });
      await load();
      message(silent ? t('خلصت الإعداد ✓ الطيار الآلي اشتغل لوحده (الفحوصات من ضمن باقتك) والمنصة بدأت تفحص موقعك. هيوصلك إيميل بالملخص بعد كل دورة، وتقدر توقفه من الإعدادات.', 'Setup complete ✓ Autopilot turned on (checks are included in your plan) and the platform started checking your website. You will get an email summary after each cycle; pause anytime in settings.') : t('الطيار الآلي اشتغل ✓ المنصة بدأت تفحص موقعك دلوقتي، وهتشوف النتيجة هنا خلال دقايق.', 'Autopilot is on ✓ The platform started checking your website; results appear here in a few minutes.'));
    }

    const usage = () => { const cfg = overview?.config; if (!cfg) return null; const month = new Date().toISOString().slice(0, 7); const used = cfg.budget_month === month ? Number(cfg.cycles_used || 0) : 0; const max = Number(cfg.policy?.max_cycles || 0), now = new Date(); return { used, max, exhausted: !!(max && used >= max), resumes: new Date(now.getFullYear(), now.getMonth() + 1, 1) }; };
    const longDate = d => d.toLocaleDateString(ar ? 'ar-EG' : 'en-GB', { weekday: 'long', day: 'numeric', month: 'long' });
    async function retryCycle() {
      overview = await req(base + '/sites/' + wid());
      await req(base + '/sites/' + wid() + '/start', { request_key: 'retry_' + wid() + '_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10), quote_hash: overview.quote.hash, research_wallet_cap: 0 });
      await load(); message(t('بدأنا فحص جديد ✓', 'A new check has started ✓'));
    }
    // تنبيه واحد لكل مشكلة، ومعاه الحل في زرار
    function alerts() {
      const out = [], u = usage(), snap = run?.run?.snapshot || {}, failed = run?.run?.status === 'failed';
      const planCycles = Number(overview?.plan_limits?.cycles || 0);
      if (u && u.max && u.used >= u.max && planCycles && u.max >= planCycles) out.push([t('مش عايز تستنى الشهر الجاي؟ باقات السيو الأعلى فيها فحص كل أسبوع وأسواق أكتر.', 'Don\'t want to wait until next month? Higher SEO plans include weekly checks and more markets.'), t('شوف باقات السيو', 'See SEO plans'), () => { location.href = '/plans#seo'; }]);
      else if (u && u.max && u.used >= u.max) out.push([t('وصلت لحد الفحوصات اللي اخترته (' + u.used + ' من ' + u.max + '). الطيار الآلي هيكمل أول الشهر الجاي، أو زوّد الحد من الإعدادات.', 'You reached the check limit you chose (' + u.used + ' of ' + u.max + '). Autopilot resumes next month, or raise it in settings.'), t('زوّد الحد', 'Raise limit'), () => setAdvanced(true, 'settings', false)]);
      else if (failed) out.push([t('آخر فحص ماكملش. ده مش بيتخصم منك، وتقدر تجرب تاني دلوقتي.', 'The last check did not finish. It was not charged; you can try again now.'), t('حاول تاني', 'Try again'), act(retryCycle)]);
      if (snap.tech?.sitemap_auto?.state === 'permission_required') out.push([t('اسمح للمنصة تبعت صفحاتك الجديدة لجوجل أول بأول: اربط جوجل تاني ووافق على صلاحية "خريطة الموقع".', 'Let the platform send your new pages to Google: reconnect Google and allow sitemap access.'), t('اربط جوجل', 'Reconnect Google'), () => { location.href = '/search-console/connect/' + wid() + '?return_to=%2Fseo-expert'; }]);
      if (snap.content_brief_state === 'research_budget_or_source_review') {
        const dq = overview?.data_quota;
        // لسه فيه رصيد بيانات في الباقة: المشكلة مؤقتة من مصدر البيانات، والمنصة هتحاول تاني لوحدها
        if (dq?.covered) out.push([t('مقال الأسبوع ده اتأجل لأن مصدر بيانات الكلمات مكانش متاح وقت الفحص. المنصة هتحاول تاني الدورة الجاية، ومش مطلوب منك حاجة.', 'This week\'s article was postponed because the keyword data source was unavailable. The platform retries next cycle; nothing is needed from you.'), null, null]);
        else out.push([t('رصيد بيانات الكلمات في باقتك خلص الشهر ده، فالمقالات الأسبوعية متوقفة. ضيف 1000 وحدة بـ 15$ من رصيدك وترجع تشتغل على طول.', 'Your plan\'s keyword data is used up this month, so weekly articles are paused. Add 1,000 units for $15 from your balance and they resume right away.'), t('ضيف رصيد بيانات', 'Add keyword data'), act(buyData)]);
      }
      alertHandlers = out.map(a => a[2]).filter(Boolean);
      let n = 0;
      return out.length ? `<section class="sg-card sg-alerts">${out.map(a => `<div class="sg-alert"><p>${esc(a[0])}</p>${a[2] ? `<button type="button" class="p-btn" data-alert="${n++}">${esc(a[1])}</button>` : ''}</div>`).join('')}</section>` : '';
    }
    async function buyData() {
      if (!confirm(t('هيتخصم 15$ من رصيدك مقابل 1000 وحدة بيانات كلمات (صالحة 30 يوم). موافق؟', '$15 will be charged from your balance for 1,000 keyword data units (valid 30 days). Continue?'))) return;
      await req('/api/plans/addon', { pack: 'seo_data_1000', request_key: 'seo_data_' + wid() + '_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10) });
      await load(); message(t('تم ✓ رصيد البيانات اتضاف، والمقال هيتكتب في الدورة الجاية.', 'Done ✓ Keyword data added; the article is written in the next cycle.'));
    }
    function marketsCard() {
      const list = markets?.markets || [], limit = Number(markets?.limit || 0), u = usage();
      if (!list.length && !u) return '';
      return `<section class="sg-card sg-plan"><div><h4>${t('أسواقك', 'Your markets')}</h4>${list.length ? `<div class="sg-chips">${list.map(m => `<span dir="auto">${esc(m.label)}</span>`).join('')}</div>` : '<p>—</p>'}${limit > list.length ? `<a class="sg-link" href="/ai/keywords?website_id=${wid()}">+ ${t('أضف سوق', 'Add a market')} (${list.length} ${t('من', 'of')} ${limit})</a>` : `<span class="sg-muted">${t('كل الأسواق المتاحة في باقتك مستخدمة', 'All markets in your plan are in use')} (${list.length}/${limit || list.length})</span>`}</div>
        ${u ? `<div><h4>${t('الفحوصات الشهر ده', 'Checks this month')}</h4><div class="sg-meter" role="img" aria-label="${u.used} / ${u.max}"><span style="width:${u.max ? Math.min(100, Math.round(u.used / u.max * 100)) : 0}%"></span></div><p>${u.used} ${t('من', 'of')} ${u.max}</p></div>` : ''}</section>`;
    }
    // الرسالة بتتحفظ عشان إعادة الرسم بعد العملية ماتمسحهاش
    let note = null;
    const showNote = () => { const m = root.querySelector('#sgMessage'); if (!m) return; m.textContent = note?.text || ''; m.hidden = !note?.text; m.className = 'sg-message' + (note?.error ? ' error' : ''); };
    const message = (text, error = false) => { note = text ? { text, error } : null; showNote(); };
    const act = fn => async () => { if (busy) return; busy = true; note = null; render(); try { await fn(); } catch (e) { message(e.message, true); } finally { busy = false; render(); } };

    // المطلوب من العميل: المهام اللي من نفس النوع بتتجمع في بند واحد، بشرح بسيط بلغة الواجهة وزرار للخطوة الجاية
    // (المهام نفسها بتتكتب بلغة الموقع، فالعميل العربي على موقع إنجليزي كان بيشوف شرح تقني بالإنجليزي)
    const TODO = {
      keyword_conflict: { title: n => n > 1 ? t('فيه ' + n + ' كلمات بحث بتتنافس عليها أكتر من صفحة في موقعك', n + ' searches have more than one of your pages competing') : t('فيه كلمة بحث بتتنافس عليها أكتر من صفحة في موقعك', 'One search has more than one of your pages competing'), text: t('ده مش غلط في حد ذاته. افتح التفاصيل وشوف هل كل صفحة بتخدم بحث مختلف؛ لو لأ، اختار صفحة واحدة تبقى الأساسية للكلمة دي. المنصة مش بتدمج أو تمسح صفحات لوحدها.', 'This is not a problem by itself. Open the details and check whether each page serves a different search; if not, pick one main page for that search. The platform never merges or deletes pages on its own.'), go: 'plan', action: t('راجع الصفحات', 'Review pages') },
      gsc_sitemap: { title: () => t('ابعت خريطة موقعك لجوجل', 'Submit your sitemap to Google'), text: t('عشان جوجل يلاقي صفحاتك الجديدة بسرعة. اربط جوجل تاني ووافق على صلاحية "خريطة الموقع"، والمنصة هتبعتها وتتابعها لوحدها.', 'So Google finds your new pages quickly. Reconnect Google and allow sitemap access; the platform submits and tracks it for you.'), gsc: true, action: t('اربط جوجل', 'Reconnect Google') },
      gsc_indexing: { title: n => n > 1 ? t(n + ' صفحات لسه جوجل ما ضافهاش للبحث', n + ' pages are not on Google yet') : t('صفحة لسه جوجل ما ضافهاش للبحث', 'A page is not on Google yet'), text: t('افتح التفاصيل وشوف الصفحات، واطلب فهرستها من Google Search Console.', 'Open the details to see the pages, then request indexing in Google Search Console.'), go: 'plan', action: t('افتح التفاصيل', 'Open details') },
      image_alt: { title: n => n > 1 ? t(n + ' صفحات فيها صور من غير وصف', n + ' pages have images without a description') : t('صفحة فيها صور من غير وصف', 'A page has images without a description'), text: t('جوجل مابيشوفش الصورة، بيقرا وصفها. اكتب جملة قصيرة توصف كل صورة (زي "رحلة نيلية في الأقصر عند الغروب").', 'Google reads an image\'s description, not the image. Add a short sentence describing each one (e.g. "Nile cruise in Luxor at sunset").'), go: 'plan', action: t('شوف الصور', 'See the images') },
      broken_link: { title: n => n > 1 ? t(n + ' روابط مكسورة في موقعك', n + ' broken links on your site') : t('رابط مكسور في موقعك', 'A broken link on your site'), text: t('الزائر اللي بيدوس عليها بيوصل لصفحة مش موجودة. افتح التفاصيل وشوف الرابط الصح المقترح لكل واحد.', 'Visitors who click them land on a missing page. Open the details to see the suggested correct link for each.'), go: 'plan', action: t('صلّح الروابط', 'Fix the links') },
      link_fix: { title: n => n > 1 ? t(n + ' روابط محتاجة تتحدّث', n + ' links need updating') : t('رابط محتاج يتحدّث', 'A link needs updating'), text: t('حدّث ملف الربط (نزّله تاني من الإعدادات) والمنصة هتصلح الروابط دي لوحدها، أو غيّرها بنفسك من التفاصيل.', 'Update the connector (download it again from settings) and the platform fixes these links itself, or change them yourself from the details.'), go: 'settings', action: t('افتح الإعدادات', 'Open settings') },
    };
    const todoGroups = list => {
      const groups = [];
      list.forEach(x => {
        // مهام الخطة اللي الذكاء الاصطناعي كتبها مالهاش نوع؛ اللي بتتكلم عن خريطة الموقع ليها نفس الحل
        const field = TODO[x.field_name] ? x.field_name : (/sitemap/i.test(String(x.title || '')) ? 'gsc_sitemap' : null);
        const key = field || 'task:' + x.id;
        let g = groups.find(y => y.key === key);
        if (!g) groups.push(g = { key, def: field ? TODO[field] : null, items: [] });
        g.items.push(x);
      });
      return groups;
    };
    const firstLine = s => String(s || '').split('\n')[0];
    function todoItem(g) {
      const d = g.def, n = g.items.length;
      // اسم الكلمة أو الصفحة اللي البند بيتكلم عنها
      const label = x => x.field_name === 'keyword_conflict' ? String(x.title || '').replace(/^[^:]*:\s*/, '') : (x.page_path || x.title);
      const chips = d && !d.gsc && n ? `<div class="sg-chips">${g.items.slice(0, 8).map(x => `<span dir="auto">${esc(label(x))}</span>`).join('')}${n > 8 ? `<span>+${n - 8}</span>` : ''}</div>` : '';
      const btn = d?.gsc ? `<a class="p-btn xs" href="/search-console/connect/${wid()}?return_to=%2Fseo-expert">${esc(d.action)}</a>` : `<button type="button" class="p-btn xs" data-go="${d?.go || 'plan'}">${esc(d?.action || t('افتح التفاصيل', 'Open details'))}</button>`;
      const title = d ? d.title(n) : g.items[0].title, text = d ? d.text : firstLine(g.items[0].instructions);
      return `<li class="sg-task"><div><b dir="auto">${esc(title)}</b>${text ? `<p dir="auto">${esc(text)}</p>` : ''}${chips}</div>${btn}</li>`;
    }

    function summary() {
      if (!run) return '';
      const tasks = run.tasks || [], [text, tone] = runText(run.run?.status);
      // دورة ماكملتش ومافيهاش مهام: مفيش أرقام نعرضها، والتنبيه فوق فيه الحل
      if (tone === 'bad' && !tasks.length) return `<section class="sg-card sg-week"><div class="sg-week-head"><h3>${t('الأسبوع ده', 'This week')}</h3><span class="sg-pill bad">${esc(text)}</span></div><div class="sg-links"><button type="button" class="p-btn outline" data-go="results">${t('شوف النتايج والزيارات', 'See results and visits')}</button><a class="p-btn outline" href="/ai-visibility">${t('ظهورك في ChatGPT', 'Your visibility in ChatGPT')}</a></div></section>`;
      const auto = tasks.filter(x => ['approved', 'active', 'verified', 'publishing'].includes(x.status)).length;
      const pendingList = tasks.filter(x => x.status === 'pending'), approve = pendingList.length;
      const mine = tasks.filter(x => x.status === 'needs_client'), todo = todoGroups(mine);
      const u = usage(), paused = overview?.config?.status && overview.config.status !== 'active';
      // الفحوصات خلصت: الفحص الجاي أول الشهر الجاي مش الموعد الأسبوعي المحفوظ؛ والطيار المتوقف مالوش فحص جاي
      const next = paused ? '' : u?.exhausted ? longDate(u.resumes) : overview?.config?.next_due_at ? longDate(new Date(String(overview.config.next_due_at).replace(' ', 'T') + 'Z')) : '';
      return `<section class="sg-card sg-week"><div class="sg-week-head"><h3>${t('الأسبوع ده', 'This week')}</h3><span class="sg-pill ${tone}">${esc(text)}</span></div>
        ${tone === 'busy' && !tasks.length ? `<div class="sg-progress"><span class="sg-bar"></span><p>${t('المنصة شغالة على موقعك دلوقتي. الصفحة دي بتتحدث لوحدها، ومش محتاج تفضل فاتحها.', 'The platform is working on your website now. This page updates itself; you do not need to keep it open.')}</p></div>` : `
        <div class="sg-stats"><div><b>${auto}</b><span>${t('تحسين المنصة عملته لوحدها', 'improvements done automatically')}</span></div>
        <div class="${approve ? 'warn' : ''}"><b>${approve}</b><span>${t('محتاج موافقتك (فيه معلومة عن شركتك)', 'need your approval (company facts)')}</span></div>
        <div class="${todo.length ? 'warn' : ''}"><b>${todo.length}</b><span>${t('محتاج منك خطوة', 'need a step from you')}</span></div></div>`}
        ${approve ? `<div class="sg-row"><p>${t('فيه تعديلات بتذكر معلومات عن شركتك (زي سعر أو سياسة). راجعها ووافق على الصح بس.', 'Some changes mention facts about your company (like a price or policy). Review and approve only what is correct.')}</p><button type="button" class="p-btn" data-go="plan">${t('راجع ووافق', 'Review and approve')}</button></div>
        <h4>${t('مستني موافقتك', 'Waiting for your approval')}</h4><ol class="sg-todo">${pendingList.slice(0, 5).map(x => `<li><b>${esc(x.title)}</b>${x.page_path ? `<span dir="ltr">${esc(x.page_path)}</span>` : ''}</li>`).join('')}</ol>${approve > 5 ? `<button type="button" class="p-btn outline" data-go="plan">${t('عرض الكل', 'Show all')} (${approve})</button>` : ''}` : ''}
        ${todo.length ? `<h4>${t('المطلوب منك', 'What we need from you')}</h4><ol class="sg-tasks">${todo.map(todoItem).join('')}</ol>` : (!approve && tone !== 'busy' ? `<p class="sg-done">✓ ${t('مش مطلوب منك أي حاجة الأسبوع ده.', 'Nothing is needed from you this week.')}</p>` : '')}
        ${next ? `<p class="sg-muted">${t('الفحص الجاي: ', 'Next check: ')}${esc(next)}</p>` : ''}
        <div class="sg-links"><button type="button" class="p-btn outline" data-go="results">${t('شوف النتايج والزيارات', 'See results and visits')}</button><a class="p-btn outline" href="/ai-visibility">${t('ظهورك في ChatGPT', 'Your visibility in ChatGPT')}</a></div></section>`;
    }

    function render() {
      const list = steps(), sites = setup?.websites || [], firstOpen = list.findIndex(s => !s.done), allDone = firstOpen === -1, alertsHtml = sites.length ? alerts() : '';
      root.innerHTML = `<section class="sg-card sg-hero ${allDone && !alertHandlers.length ? 'ok' : ''}"><div><h2>${allDone && usage()?.exhausted ? t('الطيار الآلي واقف لحد ', 'Autopilot is paused until ') + esc(longDate(usage().resumes)) : allDone && alertHandlers.length ? t('الطيار الآلي متفعّل، وفيه حاجة محتاجة منك تحت', 'Autopilot is on; something below needs you') : allDone ? t('خدمة السيو شغالة لوحدها ✓', 'Your SEO runs on its own ✓') : t('فاضل ', '') + (list.length - list.filter(s => s.done).length) + t(' خطوة وخدمة السيو تشتغل لوحدها', ' step(s) left until your SEO runs on its own')}</h2>
          <p>${allDone && usage()?.exhausted ? t('استخدمت كل فحوصات الشهر ده. التحسينات اللي اتطبقت لسه شغالة على موقعك، والفحص بيرجع لوحده أول الشهر.', 'You used all checks this month. Applied improvements stay live on your site, and checks restart automatically next month.') : allDone ? t('المنصة بتفحص موقعك وتحسّنه كل أسبوع. هنبلغك لو احتجنا منك حاجة.', 'The platform checks and improves your site every week. We will tell you if we need anything.') : t('كل خطوة بتاخد دقيقة. خلّصها بالترتيب، وبعدها المنصة بتكمل الباقي.', 'Each step takes a minute. Finish them in order and the platform does the rest.')}</p></div>
          ${sites.length > 1 ? `<label class="sg-site">${t('الموقع', 'Website')} <select id="sgSite" class="p-select">${sites.map(s => `<option value="${s.id}">${esc(s.domain || s.company_name || s.id)}</option>`).join('')}</select></label>` : `<input type="hidden" id="sgSite" value="${sites[0]?.id || ''}">`}</section>
        <p id="sgMessage" class="sg-message" role="status" hidden></p>
        ${!sites.length ? `<section class="sg-card"><h3>${t('ضيف موقعك الأول', 'Add your website first')}</h3><p>${t('اكتب رابط موقعك عشان نبدأ.', 'Enter your website link to start.')}</p><a class="p-btn" href="/websites">${t('ضيف موقع', 'Add website')}</a></section>` : `
        ${allDone ? `<details class="sg-card sg-setup"><summary>✓ ${t('كل خطوات الإعداد خلصت', 'All setup steps are done')}</summary>` : ''}<ol class="sg-steps">${list.map((s, i) => `<li class="sg-step ${s.done ? 'done' : (i === firstOpen ? 'current' : 'later')}"><span class="sg-num">${s.done ? '✓' : i + 1}</span><div><h3>${esc(s.title)}</h3>${s.done ? '' : `<p>${esc(s.text)}</p>`}${s.form && !s.done && i === firstOpen ? factsForm() : ''}
          ${s.checking ? `<span class="sg-muted">${t('بنتأكد من ربط موقعك…', 'Checking your website connection…')}</span>` : ''}${!s.done && !s.checking && i === firstOpen && !(s.form && !factsDraft) ? `<button type="button" class="p-btn" data-step="${s.key}" ${busy ? 'disabled' : ''}>${busy ? t('لحظة…', 'One moment…') : esc(s.action)}</button>` : ''}${s.done ? `<span class="sg-ok">${t('تم', 'Done')}</span>` : ''}</div></li>`).join('')}</ol>${allDone ? '</details>' : ''}
        ${alertsHtml}${rankCard()}${summary()}${reportCard()}${marketsCard()}`}`;
      const sel = root.querySelector('#sgSite'); if (sel && sites.length) { const want = String(params.get('website_id') || store.get('tf-seo-site') || ''); if (sites.some(s => String(s.id) === want)) sel.value = want; sel.onchange = () => { store.set('tf-seo-site', sel.value); const legacy = document.getElementById('seWebsite'); if (legacy) { legacy.value = sel.value; legacy.dispatchEvent(new Event('change')); } health = null; run = null; factsDraft = null; autoTried = false; note = null; load(); }; }
      showNote();
      root.querySelectorAll('[data-fact]').forEach(el => { el.oninput = () => { if (factsDraft) factsDraft[el.dataset.fact] = el.value; }; });
      if (sites.length && list[firstOpen]?.form && !factsDraft) loadFacts();
      maybeAutoStart();
      root.querySelectorAll('[data-step]').forEach(b => { const s = list.find(x => x.key === b.dataset.step); b.onclick = act(async () => s.run()); });
      root.querySelectorAll('[data-go]').forEach(b => { b.onclick = () => setAdvanced(true, b.dataset.go, false); });
      root.querySelectorAll('[data-alert]').forEach(b => { b.onclick = () => alertHandlers[Number(b.dataset.alert)]?.(); });
      const rep = root.querySelector('#sgReportSend'); if (rep) rep.onclick = act(saveReport);
    }

    // تنبيه لما ترتيب كلمة متابَعة ينزل 3 مراكز أو أكتر خلال آخر 30 يوم، أو تخرج من أول 30 (عمق القياس)
    function rankCard() {
      // من غير مزوّد القياس الترتيب الحالي بيرجع فاضي، فمانقولش إن كلمة "خرجت" وهي ماتقاستش
      if (!keywords?.status?.available) return '';
      // "خرجت من أول 30": آخر قياس فاضي وقبله كان ليها ترتيب جوه الـ30 (القياسات القديمة كانت لحد 100)
      const dropped = k => { const h = k.trend_30_days || []; return h.length > 1 && h[h.length - 1].position === null && h.some(p => p.position !== null && Number(p.position) <= 30); };
      const rows = (keywords.keywords || []).filter(k => (k.trend !== null && k.trend !== undefined && Number(k.trend) <= -3) || dropped(k));
      if (!rows.length) return '';
      return `<section class="sg-card sg-alerts"><h4>⚠️ ${t('كلمات ترتيبها نزل', 'Keywords that dropped')}</h4><ol class="sg-todo">${rows.slice(0, 5).map(k => `<li><b dir="auto">${esc(k.keyword)}</b><span>${dropped(k) ? t('خرجت من أول 30 نتيجة في جوجل', 'Dropped out of the top 30 on Google') : t('نزلت ', 'Down ') + Math.abs(Number(k.trend)) + t(' مركز (دلوقتي رقم ', ' places (now #') + Number(k.current_position) + ')'}</span></li>`).join('')}</ol>
        <div class="sg-links"><a class="p-btn" href="/ai/keywords?website_id=${wid()}">${t('شوف الكلمات', 'See keywords')}</a><button type="button" class="p-btn outline" data-go="plan">${t('افتح خطة التحسين', 'Open the plan')}</button></div></section>`;
    }

    // التقرير الشهري: يتفتح ويتطبع PDF، أو يتبعت بالإيميل أول كل شهر
    function reportCard() {
      const monthly = (schedules || []).find(s => s.frequency === 'monthly' && Number(s.is_active) === 1);
      const open = '/index.php?__tf_api=%2Fapi%2Flocal-seo%2Freport&website_id=' + wid();
      return `<section class="sg-card sg-plan"><div><h4>📄 ${t('التقرير الشهري', 'Monthly report')}</h4><p class="sg-muted">${t('الزيارات والترتيب واللي المنصة عملته على موقعك الشهر ده. افتحه واطبعه PDF.', 'Visits, rankings and what the platform did this month. Open it and print to PDF.')}</p><a class="p-btn outline" href="${open}" target="_blank" rel="noopener">${t('افتح التقرير', 'Open report')}</a></div>
        <div><h4>${t('يوصلك بالإيميل', 'Email it to me')}</h4>${monthly ? `<p class="sg-done">✓ ${t('بيتبعت كل شهر على ', 'Sent every month to ')}<span dir="ltr">${esc(monthly.recipient_email)}</span></p>` : `<input type="email" class="p-select" id="sgReportEmail" dir="ltr" value="${esc(root.dataset.email || '')}" aria-label="${t('الإيميل', 'Email')}"><button type="button" class="p-btn" id="sgReportSend" ${busy ? 'disabled' : ''}>${t('ابعته كل شهر', 'Send monthly')}</button>`}</div></section>`;
    }
    async function saveReport() {
      const email = String(root.querySelector('#sgReportEmail')?.value || '').trim();
      if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) throw new Error(t('اكتب إيميل صحيح.', 'Enter a valid email.'));
      await req('/api/seo/report/schedules', { website_id: wid(), frequency: 'monthly', hour: 8, weekday: '', recipient_email: email, is_active: 1 });
      schedules = (await req('/api/seo/report/schedules?website_id=' + wid()).catch(() => ({ schedules })))?.schedules || schedules;
      message(t('تم ✓ التقرير هيوصلك أول كل شهر.', 'Done ✓ The report will arrive at the start of every month.'));
    }

    async function load() {
      if (!wid()) { render(); return; }
      const id = wid();
      const [ov, rd, mk, kw, sc] = await Promise.allSettled([req(base + '/sites/' + id), req(base + '/sites/' + id + '/readiness'), req('/api/seo/data/markets?website_id=' + id), req('/api/seo/data/keywords?website_id=' + id), req('/api/seo/report/schedules?website_id=' + id)]);
      overview = ov.status === 'fulfilled' ? ov.value : null; readiness = rd.status === 'fulfilled' ? rd.value : null; markets = mk.status === 'fulfilled' ? mk.value : null;
      keywords = kw.status === 'fulfilled' ? kw.value : null; schedules = sc.status === 'fulfilled' ? (sc.value?.schedules || []) : [];
      const last = overview?.runs?.[0];
      run = last ? await req(base + '/runs/' + last.id).catch(() => null) : null;
      render();
      // فحص الربط الحقيقي بياخد ثواني؛ بنعمله في الخلفية ونحدّث الخطوات لما يخلص
      if (!health) req(base + '/sites/' + id + '/probe', {}).then(h => { health = h; render(); }).catch(() => { health = {}; render(); });
      clearTimeout(timer);
      if (last && ['queued', 'scanning', 'scanned', 'planning', 'executing', 'monitoring'].includes(last.status)) timer = setTimeout(() => load().catch(() => {}), 20000);
    }

    // صفحة واحدة في المرة: الملخص البسيط، أو صفحة الخبير اللي اتفتحت من تبويبات مركز السيو (?page=...)
    // (قبل كده الاتنين كانوا بيظهروا مع بعض فالرسايل بتتناقض)
    const page = params.get('page');
    if (page && page !== 'overview') { advanced.hidden = false; root.hidden = true; return; }
    advanced.hidden = true;
    root.innerHTML = `<section class="sg-card"><p>${t('جارٍ التحميل…', 'Loading…')}</p></section>`;
    try { setup = await req(base + '/setup'); render(); await load(); }
    catch (e) { root.innerHTML = `<section class="sg-card"><p>${esc(e.message)}</p><button type="button" class="p-btn" onclick="location.reload()">${t('حاول تاني', 'Try again')}</button></section>`; }
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true }); else start();
})();
