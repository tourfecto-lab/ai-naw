/**
 * Tourfecto - اللغات والفهرسة
 * ترجمة موقع المنشئ لغة لغة (مسودة ← مراجعة ← نشر) وإرسال خريطة الموقع لجوجل.
 * كانت الصفحة بتعرض الترجمة كـ JSON ونتيجة جوجل كـ كود؛ دلوقتي كل نص في خانة لوحده جنب الأصل،
 * والحالة بتتعرض بكلام مفهوم. الـ API زي ما هو.
 */
(async () => {
  'use strict';
  const root = document.getElementById('internationalSeo'), P = window.Panel;
  if (!root || !P) return;
  const ar = document.documentElement.lang.toLowerCase().startsWith('ar'), t = (a, e) => ar ? a : e, esc = P.esc;
  const LANGS = [['en', 'English', 'الإنجليزية'], ['de', 'Deutsch', 'الألمانية'], ['fr', 'Français', 'الفرنسية'], ['es', 'Español', 'الإسبانية'], ['it', 'Italiano', 'الإيطالية'], ['ru', 'Русский', 'الروسية'], ['pt', 'Português', 'البرتغالية'], ['nl', 'Nederlands', 'الهولندية'], ['pl', 'Polski', 'البولندية'], ['tr', 'Türkçe', 'التركية'], ['zh', '中文', 'الصينية'], ['ja', '日本語', 'اليابانية'], ['ko', '한국어', 'الكورية'], ['ar', 'العربية', 'العربية']];
  const langName = c => { const l = LANGS.find(x => x[0] === c); return l ? (ar ? l[2] : l[1]) : c; };
  let builders = [], sites = [], current = null, draft = {}, lang = '', confirmed = false, reviewed = false, sitemap = null, busy = false, note = null;

  async function api(op, data, query = '') {
    const r = await P.fetchJSON('/api/international-seo/' + op + query, data ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) } : {});
    if (!r.success) throw Error(r.error || r.message || t('تعذر تنفيذ الطلب', 'Request failed'));
    return r.data;
  }
  const say = (text, error = false) => { note = text ? { text, error } : null; };
  const work = fn => async () => { if (busy) return; busy = true; say(null); render(); try { await fn(); } catch (e) { say(e.message, true); } finally { busy = false; render(); } };

  // اسم مفهوم لكل خانة بدل tours.0.itinerary.1.title
  const FIELD = { tagline: ['الشعار', 'Tagline'], hero_headline: ['العنوان الرئيسي', 'Main headline'], hero_subtext: ['النص تحت العنوان', 'Text under the headline'], about_title: ['عنوان "عن الشركة"', '"About" title'], about_text: ['نص "عن الشركة"', '"About" text'], cta_text: ['زرار الحجز', 'Booking button'], name: ['الاسم', 'Name'], short_description: ['وصف قصير', 'Short description'], description: ['الوصف', 'Description'], title: ['العنوان', 'Title'], highlights: ['المميزات', 'Highlights'], includes: ['المشمول', 'Included'], excludes: ['غير المشمول', 'Not included'], itinerary: ['البرنامج', 'Itinerary'], amenities: ['المرافق', 'Amenities'], hotel_amenities: ['مرافق الفندق', 'Hotel amenities'] };
  const GROUP = { tours: ['رحلة', 'Tour'], rooms: ['غرفة', 'Room'] };
  function describe(path) {
    const parts = path.split('.');
    if (parts[0] === '__interface') return [t('نصوص ثابتة في الموقع (أزرار وعناوين)', 'Fixed site texts (buttons and headings)'), t('نص', 'Text') + ' ' + (Number(parts[1]) + 1)];
    if (GROUP[parts[0]] && /^\d+$/.test(parts[1] || '')) {
      const group = t(GROUP[parts[0]][0], GROUP[parts[0]][1]) + ' ' + (Number(parts[1]) + 1), rest = parts.slice(2), out = [];
      for (let i = 0; i < rest.length; i++) { const f = FIELD[rest[i]]; if (f) out.push(t(f[0], f[1]) + (/^\d+$/.test(rest[i + 1] || '') ? ' ' + (Number(rest[++i]) + 1) : '')); }
      return [group, out.join(' · ') || path];
    }
    const f = FIELD[parts[0]];
    return [t('الصفحة الرئيسية', 'Home page'), f ? t(f[0], f[1]) : path];
  }

  const sourceLang = () => String(current?.source?.language || 'en').toLowerCase();
  const versionOf = code => (current?.versions || []).find(v => v.language === code);
  function pickLanguage(code) {
    lang = code; reviewed = false;
    const v = versionOf(code); let saved = {};
    try { saved = v ? JSON.parse(v.fields_json || '{}') : {}; } catch (e) { saved = {}; }
    draft = {}; Object.keys(current?.fields || {}).forEach(k => { draft[k] = typeof saved[k] === 'string' ? saved[k] : ''; });
  }
  async function loadBuilder() {
    const id = Number(root.querySelector('#intlBuilder')?.value || builders[0]?.id || 0); if (!id) return;
    current = await api('data', null, '?generated_website_id=' + id); current.__id = id; confirmed = false;
    const first = LANGS.map(l => l[0]).find(c => c !== sourceLang() && versionOf(c)) || LANGS.map(l => l[0]).find(c => c !== sourceLang());
    pickLanguage(lang && lang !== sourceLang() ? lang : first);
  }
  function payload() {
    if (!current) throw Error(t('اختار الموقع الأول', 'Choose a website first'));
    if (!confirmed) throw Error(t('أكد إن المحتوى الأصلي صح الأول (خطوة 1)', 'Confirm the original content first (step 1)'));
    return { generated_website_id: Number(root.querySelector('#intlBuilder').value), source_hash: current.source_hash, language: lang, confirmed: true };
  }
  function collect() { root.querySelectorAll('[data-field]').forEach(x => { draft[x.dataset.field] = x.value; }); }
  async function save(publish) {
    collect(); const p = payload();
    const empty = Object.keys(draft).filter(k => !String(draft[k]).trim()).length;
    if (publish && empty) throw Error(t('فيه ' + empty + ' خانة لسه فاضية. ترجمها كلها قبل النشر.', empty + ' fields are still empty. Translate them all before publishing.'));
    if (publish && !reviewed) throw Error(t('علّم إنك راجعت الترجمة كلها قبل النشر', 'Tick that you reviewed the whole translation before publishing'));
    await api(publish ? 'publish' : 'draft', { ...p, fields: draft, reviewed: publish && reviewed });
    const keep = lang; await loadBuilder(); pickLanguage(keep); confirmed = true;
    say(publish ? t('تم ✓ نسخة ' + langName(keep) + ' اتنشرت على موقعك واتضافت لخريطة الموقع.', 'Done ✓ The ' + langName(keep) + ' version is live and added to your sitemap.') : t('تم ✓ المسودة اتحفظت ومش منشورة.', 'Done ✓ Draft saved, not published.'));
  }
  async function generate() {
    const p = payload();
    if (!confirm(t('الترجمة التلقائية بتتحسب من حصة توليد المواقع في باقتك أو من رصيدك. نكمّل؟', 'Automatic translation uses your plan\'s website generation allowance or your balance. Continue?'))) return;
    const r = await api('generate', p); draft = { ...draft, ...(r.fields || {}) }; reviewed = false;
    say(t('الترجمة جاهزة ✓ راجع كل خانة وصحّح أي حاجة قبل النشر. لسه ما اتنشرتش.', 'Translation ready ✓ Review every field and fix anything before publishing. Nothing is published yet.'));
  }

  const siteId = () => Number(root.querySelector('#intlSite')?.value || sites[0]?.id || 0);
  async function loadSitemap() { if (siteId()) sitemap = await api('sitemapData', null, '?website_id=' + siteId()); }
  function sitemapState() {
    const r = sitemap?.result || {}, perm = sitemap?.permission, enabled = Number(perm?.enabled || 0) === 1;
    const map = (r.sitemaps || [])[0] || null, when = v => { if (!v) return ''; const d = new Date(String(v).replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(v) ? '' : 'Z')); return isNaN(d) ? v : d.toLocaleString(ar ? 'ar-EG-u-nu-latn' : 'en-GB', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }); };
    if (!perm || r.state === 'permission_required') return { tone: 'warn', title: t('المنصة مش مسموح لها تبعت خريطة موقعك لجوجل لسه', 'The platform is not allowed to submit your sitemap yet'), text: t('اسمح بالصلاحية مرة واحدة من جوجل، وبعدها المنصة بتبعت الصفحات الجديدة والترجمات لوحدها.', 'Allow access once in Google; after that the platform submits new pages and translations itself.') };
    if (!enabled) return { tone: 'off', title: t('الإرسال التلقائي واقف', 'Automatic submission is stopped'), text: t('جوجل مش هيعرف بصفحاتك الجديدة لحد ما تبعت الخريطة تاني.', 'Google will not hear about new pages until you submit the sitemap again.') };
    if (r.error || r.listing_error || (map && Number(map.errors) > 0)) return { tone: 'bad', title: t('جوجل لقى مشكلة في خريطة موقعك', 'Google found a problem with your sitemap'), text: String(r.error || r.listing_error || t(map.errors + ' أخطاء في الخريطة. راجعها في Search Console.', map.errors + ' errors in the sitemap. Check it in Search Console.')) };
    if (r.state === 'submitted' && map) return { tone: 'ok', title: t('✓ خريطة موقعك عند جوجل وشغالة', '✓ Your sitemap is with Google and working'), text: [map.pending ? t('جوجل لسه بيقراها', 'Google is still reading it') : (map.last_downloaded ? t('آخر مرة جوجل قراها: ', 'Last read by Google: ') + when(map.last_downloaded) : ''), t('أخطاء: ', 'Errors: ') + Number(map.errors || 0), t('تحذيرات: ', 'Warnings: ') + Number(map.warnings || 0)].filter(Boolean).join(' · ') };
    return { tone: 'ok', title: t('الإرسال مفعّل', 'Submission is on'), text: perm.checked_at ? t('آخر فحص: ', 'Last checked: ') + when(perm.checked_at) : '' };
  }

  function translationCard() {
    if (!builders.length) return `<section class="intl-card"><h3>${t('ترجمة موقعك', 'Translate your website')}</h3><p class="intl-muted">${t('الترجمة متاحة للمواقع اللي اتعملت بمنشئ المواقع في المنصة. لو موقعك ووردبريس، استخدم إضافة ترجمة على ووردبريس، والمنصة بتربط اللغات بجوجل من خطوة خريطة الموقع تحت.', 'Translation is available for websites made with the platform\'s website builder. For WordPress, use a translation plugin there; the platform still links your languages to Google in the sitemap step below.')}</p><a class="p-btn outline" href="/website-builder">${t('افتح منشئ المواقع', 'Open the website builder')}</a></section>`;
    const fields = current?.fields || {}, keys = Object.keys(fields), filled = keys.filter(k => String(draft[k] || '').trim()).length;
    const groups = []; keys.forEach(k => { const [g, label] = describe(k); let x = groups.find(y => y.g === g); if (!x) groups.push(x = { g, rows: [] }); x.rows.push([k, label]); });
    const chips = LANGS.filter(l => l[0] !== sourceLang()).map(([c]) => { const v = versionOf(c), st = !v ? '' : !v.current ? 'stale' : v.status === 'published' ? 'live' : 'draft';
      return `<button type="button" class="intl-lang ${c === lang ? 'on' : ''} ${st}" data-lang="${c}" aria-pressed="${c === lang}">${esc(langName(c))}${st ? `<small>${st === 'live' ? t('منشورة', 'Live') : st === 'draft' ? t('مسودة', 'Draft') : t('محتاجة مراجعة', 'Needs review')}</small>` : ''}</button>`; }).join('');
    const v = versionOf(lang);
    return `<section class="intl-card">
      <div class="intl-head"><div><h3>${t('ترجمة موقعك', 'Translate your website')}</h3><p class="intl-muted">${t('كل لغة بتبدأ مسودة، وتتنشر بس بعد ما تراجعها. الأسعار والأرقام وبيانات التواصل بتفضل زي الأصل ومش بتتترجم.', 'Each language starts as a draft and goes live only after you review it. Prices, numbers and contact details stay as in the original.')}</p></div>
        ${builders.length > 1 ? `<label class="intl-field">${t('الموقع', 'Website')}<select id="intlBuilder" class="p-select">${builders.map(b => `<option value="${b.id}" ${Number(b.id) === Number(current?.__id) ? 'selected' : ''}>${esc(b.slug)}</option>`).join('')}</select></label>` : `<input type="hidden" id="intlBuilder" value="${builders[0].id}">`}</div>
      <p class="intl-label">${t('اختار اللغة', 'Choose a language')} <span class="intl-muted">(${t('لغة الموقع الأصلية: ', 'original language: ') + esc(langName(sourceLang()))})</span></p>
      <div class="intl-langs">${chips}</div>
      ${v && !v.current ? `<p class="intl-alert">${t('المحتوى الأصلي اتغير بعد آخر ترجمة لـ' + langName(lang) + '. راجع الخانات وانشرها تاني عشان تفضل مطابقة.', 'The original content changed after the last ' + langName(lang) + ' translation. Review the fields and publish again to keep them in sync.')}</p>` : ''}
      <ol class="intl-steps">
        <li class="${confirmed ? 'done' : 'now'}"><h4>${t('أكد المحتوى الأصلي', 'Confirm the original content')}</h4><p class="intl-muted">${t('الترجمة بتنقل اللي مكتوب في موقعك بالظبط، فلازم يكون صح الأول.', 'The translation copies exactly what your site says, so it must be correct first.')}</p>
          <label class="intl-check"><input type="checkbox" id="intlConfirmed" ${confirmed ? 'checked' : ''}> ${t('راجعت المحتوى الأصلي (الأسعار والخدمات والمعلومات) وهو صحيح', 'I reviewed the original content (prices, services and facts) and it is correct')}</label></li>
        <li class="${confirmed ? 'now' : 'later'}"><h4>${t('ترجم الخانات', 'Translate the fields')} <span class="intl-count">${filled} / ${keys.length}</span></h4>
          <div class="intl-actions"><button type="button" class="p-btn" id="intlGenerate" ${busy || !confirmed ? 'disabled' : ''}>${t('ترجم تلقائيًا', 'Translate automatically')}</button><span class="intl-muted">${t('أو اكتب الترجمة بنفسك في الخانات تحت (ببلاش).', 'or type the translation yourself below (free).')}</span></div>
          ${groups.map((g, i) => `<details class="intl-group" ${i === 0 ? 'open' : ''}><summary>${esc(g.g)} <span class="intl-muted">(${g.rows.filter(([k]) => String(draft[k] || '').trim()).length}/${g.rows.length})</span></summary>
            ${g.rows.map(([k, label]) => `<div class="intl-row"><div class="intl-src"><span>${esc(label)}</span><p dir="auto">${esc(fields[k])}</p></div><textarea class="p-select" dir="auto" data-field="${esc(k)}" rows="${Math.min(6, Math.max(1, Math.ceil(String(fields[k]).length / 70)))}" placeholder="${esc(t('الترجمة بـ', 'Translation in ') + langName(lang))}" ${confirmed ? '' : 'disabled'}>${esc(draft[k] || '')}</textarea></div>`).join('')}</details>`).join('')}</li>
        <li class="${confirmed && filled === keys.length && keys.length ? 'now' : 'later'}"><h4>${t('راجع وانشر', 'Review and publish')}</h4>
          <label class="intl-check"><input type="checkbox" id="intlReviewed" ${reviewed ? 'checked' : ''} ${confirmed ? '' : 'disabled'}> ${t('راجعت الترجمة كلها، وهي بتقول نفس المعلومات والأسعار والخدمات من غير إضافات', 'I reviewed the whole translation; it states the same facts, prices and services with nothing added')}</label>
          <div class="intl-actions"><button type="button" class="p-btn primary" id="intlPublish" ${busy || !confirmed ? 'disabled' : ''}>${t('انشر نسخة ', 'Publish the ') + esc(langName(lang)) + t('', ' version')}</button><button type="button" class="p-btn outline" id="intlDraft" ${busy || !confirmed ? 'disabled' : ''}>${t('احفظ مسودة', 'Save draft')}</button></div></li>
      </ol></section>`;
  }
  function sitemapCard() {
    if (!sites.length) return '';
    const s = sitemapState(), perm = sitemap?.permission, url = perm?.sitemap_url || String(sites.find(x => Number(x.id) === siteId())?.main_url || '').replace(/\/$/, '') + '/sitemap.xml';
    const csrf = esc(root.dataset.csrf || '');
    return `<section class="intl-card"><div class="intl-head"><div><h3>${t('خريطة الموقع في جوجل', 'Your sitemap on Google')}</h3><p class="intl-muted">${t('خريطة الموقع بتعرّف جوجل بكل صفحاتك ولغاتك عشان يضيفها للبحث أسرع. ده مش ضمان إن كل صفحة تظهر، لكنه بيسرّع الموضوع.', 'The sitemap tells Google about all your pages and languages so it adds them to search faster. It does not guarantee every page appears, but it speeds things up.')}</p></div>
      ${sites.length > 1 ? `<label class="intl-field">${t('الموقع', 'Website')}<select id="intlSite" class="p-select">${sites.map(x => `<option value="${x.id}" ${Number(x.id) === siteId() ? 'selected' : ''}>${esc(x.main_url)}</option>`).join('')}</select></label>` : `<input type="hidden" id="intlSite" value="${sites[0].id}">`}</div>
      <div class="intl-status ${s.tone}"><b>${esc(s.title)}</b>${s.text ? `<p>${esc(s.text)}</p>` : ''}</div>
      ${s.tone === 'warn' ? `<form method="post" action="/search-console/allow-sitemap-submit/${siteId()}" class="intl-actions"><input type="hidden" name="csrf_token" value="${csrf}"><input type="hidden" name="return_to" value="/international-seo"><input type="hidden" name="allow_sitemap_submit" value="1"><button class="p-btn primary">${t('اسمح من جوجل', 'Allow in Google')}</button><span class="intl-muted">${t('هتتنقل لجوجل توافق، واختار نفس الموقع المؤكد.', 'You will go to Google to approve; pick the same verified site.')}</span></form>` : `
      <details class="intl-group"><summary>${t('عنوان خريطة الموقع', 'Sitemap address')}</summary><label class="intl-field">${t('لازم يكون على نفس دومين موقعك ويبدأ بـ https', 'Must be on your own domain and start with https')}<input id="intlSitemap" class="p-select" type="url" dir="ltr" value="${esc(url)}"></label></details>
      <div class="intl-actions"><button type="button" class="p-btn primary" id="intlSubmit" ${busy ? 'disabled' : ''}>${t('ابعت الخريطة لجوجل دلوقتي', 'Submit the sitemap now')}</button><button type="button" class="p-btn outline" id="intlRefresh" ${busy ? 'disabled' : ''}>${t('حدّث الحالة', 'Refresh status')}</button>${Number(perm?.enabled || 0) === 1 ? `<button type="button" class="p-btn outline danger" id="intlDisable" ${busy ? 'disabled' : ''}>${t('وقّف الإرسال التلقائي', 'Stop automatic submission')}</button>` : ''}</div>`}</section>`;
  }
  function render() {
    root.innerHTML = `${note ? `<p class="intl-note ${note.error ? 'error' : ''}" role="status">${esc(note.text)}</p>` : ''}${translationCard()}${sitemapCard()}`;
    const on = (id, ev, fn) => { const x = root.querySelector('#' + id); if (x) x[ev] = fn; };
    on('intlBuilder', 'onchange', work(async () => { lang = ''; await loadBuilder(); }));
    on('intlConfirmed', 'onchange', e => { collect(); confirmed = e.target.checked; render(); });
    on('intlReviewed', 'onchange', e => { reviewed = e.target.checked; });
    root.querySelectorAll('[data-field]').forEach(x => { x.oninput = () => { draft[x.dataset.field] = x.value; if (reviewed) { reviewed = false; const r = root.querySelector('#intlReviewed'); if (r) r.checked = false; } }; });
    root.querySelectorAll('[data-lang]').forEach(b => { b.onclick = () => { collect(); if (Object.values(draft).some(v => String(v).trim()) && b.dataset.lang !== lang && !versionOf(lang) && !confirm(t('الترجمة اللي كتبتها للغة دي مش محفوظة. تسيبها؟', 'Your unsaved translation for this language will be lost. Continue?'))) return; pickLanguage(b.dataset.lang); render(); }; });
    on('intlGenerate', 'onclick', work(generate));
    on('intlDraft', 'onclick', work(() => save(false)));
    on('intlPublish', 'onclick', work(() => save(true)));
    on('intlSite', 'onchange', work(loadSitemap));
    on('intlRefresh', 'onclick', work(async () => { await loadSitemap(); say(t('الحالة اتحدثت ✓', 'Status refreshed ✓')); }));
    on('intlSubmit', 'onclick', work(async () => { await api('submit', { website_id: siteId(), sitemap_url: root.querySelector('#intlSitemap').value.trim() }); await loadSitemap(); say(t('اتبعتت لجوجل ✓ جوجل بياخد من ساعات لأيام عشان يقراها.', 'Submitted to Google ✓ Google takes hours to days to read it.')); }));
    on('intlDisable', 'onclick', work(async () => { if (!confirm(t('توقف الإرسال التلقائي؟ جوجل مش هيعرف بصفحاتك الجديدة لحد ما تشغله تاني.', 'Stop automatic submission? Google will not hear about new pages until you turn it back on.'))) return; await api('disable', { website_id: siteId() }); await loadSitemap(); }));
  }

  root.innerHTML = `<section class="intl-card"><p>${t('جارٍ التحميل…', 'Loading…')}</p></section>`;
  try {
    const r = await api('sites'); builders = r.builders || []; sites = r.sites || [];
    await Promise.all([builders.length ? loadBuilder() : null, sites.length ? loadSitemap() : null]);
  } catch (e) { say(e.message, true); }
  render();
})();
