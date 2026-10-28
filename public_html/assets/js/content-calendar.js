/* خطة المقالات: مقالات الشهر بمواعيدها وصورها وسبب كل مقال (موسم/بحث) + تعديل العنوان/الكلمة/التاريخ/الصورة. */
(function () {
    const root = document.getElementById('ccal');
    if (!root || !window.Panel) return;
    const P = window.Panel;
    // موقع مبني عندنا ولسه مش متضاف: زرار «فعّل الخطة» بيضيفه ويجهّز الخطة
    if (root.dataset.empty) {
        root.addEventListener('click', async e => {
            const b = e.target.closest('[data-ccal-activate]');
            if (!b) return;
            b.disabled = true;
            const res = await P.fetchJSON('/api/content-calendar/activate-built', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ generated_id: Number(b.getAttribute('data-ccal-activate')) }) });
            if (res && res.success) { P.toast(res.message, 'success'); location.reload(); }
            else { b.disabled = false; P.toast((res && res.error) || 'Error', 'error'); }
        });
        return;
    }
    const ar = (document.documentElement.lang || 'ar').toLowerCase().startsWith('ar');
    const t = (a, e) => (ar ? a : e);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    const sel = document.getElementById('ccalSite');
    const body = document.getElementById('ccalBody');
    const nf = new Intl.NumberFormat(ar ? 'ar-EG' : 'en-US');
    const df = new Intl.DateTimeFormat(ar ? 'ar-EG' : 'en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
    let D = null, building = false, autoTried = {};

    const site = () => sel.value;
    const api = (path, opt) => P.fetchJSON('/api/content-calendar' + path, opt);
    const post = (path, data) => api(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data || {}) });

    const STATE = {
        planned: ['', t('مجدول', 'Scheduled')],
        writing: ['blue', t('بيتكتب دلوقتي', 'Being written')],
        written: ['blue', t('اتكتب — جاهز للمعاينة', 'Written — ready to preview')],
        published: ['green', t('اتنشر ✓', 'Published ✓')],
        failed: ['red', t('ماتكتبش', 'Not written')],
    };
    const PUB = {
        wordpress: t('بتتنشر على موقعك (WordPress) تلقائي في يومها', 'Publishes to your site (WordPress) automatically on its day'),
        hosted: t('بتتنشر على مدونتك في Tourfecto تلقائي في يومها', 'Publishes to your Tourfecto blog automatically on its day'),
        shopify: t('بتتنشر على مدونة Shopify تلقائي', 'Publishes to your Shopify blog automatically'),
        webflow: t('بتتنشر على Webflow تلقائي', 'Publishes to Webflow automatically'),
        ghost: t('بتتنشر على Ghost تلقائي', 'Publishes to Ghost automatically'),
        webhook: t('بتتبعت لموقعك تلقائي', 'Sent to your site automatically'),
    };

    function day(d) {
        const x = new Date(d + 'T00:00:00');
        return isNaN(x) ? d : df.format(x);
    }

    function header() {
        const d = D, pt = d.publish_to || {};
        const future = d.items.filter(i => i.state === 'planned').length;
        let warn = '';
        if (pt.kind === 'none') warn = `<div class="ccal-warn">⚠️ ${t('موقعك لسه مش مربوط — المقالات هتتكتب وتستناك في «المقالات».', 'Your site isn’t connected yet — articles will be written and wait for you in “Articles”.')} <a href="/websites/${encodeURIComponent(site())}">${t('اربط موقعك', 'Connect your site')} ←</a></div>`;
        if (!d.has_plan) warn += `<div class="ccal-warn">💡 ${t('الخطة جاهزة — المقالات بتبدأ تتكتب أول ما يكون عندك باقة.', 'The plan is ready — articles start being written once you have a plan.')} <a href="/plans">${t('الباقات', 'Plans')} ←</a></div>`;
        else if (d.remaining === 0) warn += `<div class="ccal-warn">⏳ ${t('حصة مقالات الشهر خلصت — المقالات اللي يومها جه بتستنى التجديد ومابتضيعش.', 'This month’s article quota is used — due articles wait for renewal and aren’t lost.')}</div>`;
        return `<div class="p-card ccal-head">
            <div class="ccal-stats">
                <div><b>${nf.format(d.per_month)}</b><span>${t('مقال في الشهر حسب باقتك', 'articles / month on your plan')}</span></div>
                <div><b>${nf.format(future)}</b><span>${t('مقال مجدول', 'scheduled')}</span></div>
                <div><b>${nf.format(d.items.filter(i => i.state === 'published').length)}</b><span>${t('اتنشر', 'published')}</span></div>
            </div>
            <p class="ccal-pub">📤 ${esc(PUB[pt.kind] || t('بتتكتب وتستناك في «المقالات»', 'Written and waiting in “Articles”'))}${pt.url ? ` · <a href="${esc(pt.url)}" target="_blank" rel="noopener">${t('افتح المدونة', 'Open blog')} ↗</a>` : ''}</p>
            ${warn}
            <div class="ccal-acts">${future < d.per_month ? `<button type="button" class="p-btn primary sm" data-build>${D.items.length ? t('كمّل خطة الشهر', 'Fill this month’s plan') : t('جهّز خطة الشهر', 'Prepare this month’s plan')}</button>` : ''}
                <small>${t('بنختار المواضيع من رحلاتك ومواسمها وبحث جوجل الحقيقي — وتقدر تعدّل أي حاجة قبل يومها.', 'Topics come from your trips, their seasons and real Google searches — edit anything before its day.')}</small></div>
        </div>`;
    }

    function card(i) {
        const st = STATE[i.state] || STATE.planned;
        const img = i.image ? `<img src="${esc(i.image)}" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.replaceWith(Object.assign(document.createElement('span'),{className:'ph',textContent:'🖼️'}))">`
            : `<span class="ph">${i.editable && !i.image_error ? '🎨<small>' + t('الصورة بتتجهز قبل يومها', 'Image is prepared before its day') + '</small>' : '🖼️'}</span>`;
        const src = { trip: t('صورة رحلتك', 'Your trip photo'), upload: t('صورتك', 'Your photo'), ai: t('صورة بالذكاء الاصطناعي', 'AI image') }[i.image_source] || '';
        return `<article class="ccal-card ${i.state}" data-id="${i.id}">
            <div class="ccal-img">${img}${src ? `<em>${esc(src)}</em>` : ''}
                ${i.editable ? `<div class="ccal-imgact"><label class="p-btn xs">${t('ارفع صورة', 'Upload')}<input type="file" accept="image/jpeg,image/png,image/webp" data-up hidden></label><button type="button" class="p-btn xs outline" data-ai>✨ ${i.image ? t('صورة تانية', 'New AI image') : t('اعمل صورة', 'AI image')}</button></div>` : ''}</div>
            <div class="ccal-b">
                <div class="ccal-row"><span class="ccal-date">📅 ${esc(day(i.date))}</span><span class="ccal-st ${st[0]}">${st[1]}</span></div>
                <h3 dir="auto">${esc(i.title)}</h3>
                <div class="ccal-tags">${i.keyword ? `<span title="${t('الكلمة اللي المقال بيستهدفها', 'Target keyword')}">🔑 <bdi>${esc(i.keyword)}</bdi>${i.volume ? ` · ${nf.format(i.volume)}/${t('شهر', 'mo')}` : ''}</span>` : ''}
                    ${i.trip ? `<a href="${esc(i.trip_url || '#')}" target="_blank" rel="noopener" title="${t('الرحلة اللي المقال بيروّج لها', 'Trip this article promotes')}">🧭 <bdi>${esc(i.trip)}</bdi></a>` : ''}</div>
                ${i.reason ? `<p class="ccal-why">💡 ${esc(i.reason)}</p>` : ''}
                ${i.image_error && i.editable ? `<p class="ccal-err">${t('ماقدرناش نعمل الصورة — ارفع صورتك أو جرّب تاني', 'Couldn’t create the image — upload yours or try again')}</p>` : ''}
                ${i.state === 'failed' ? `<p class="ccal-err">${esc(i.error || '')}</p>` : ''}
                ${i.state === 'writing' ? `<p class="ccal-note">⏳ ${t('الكتابة بتاخد من دقيقتين لـ 10 دقايق — الصفحة بتتحدث لوحدها', 'Writing takes 2–10 minutes — this page refreshes by itself')}</p>` : ''}
                ${i.editable ? `<p class="ccal-note">✍️ ${t('بيتكتب قبل يومه بيومين وتقدر تعاينه قبل ما يتنشر', 'Written 2 days ahead — you can preview it before it goes live')}</p>` : ''}
                <div class="ccal-foot">${i.editable ? `<button type="button" class="p-btn outline xs" data-edit>✏️ ${t('تعديل', 'Edit')}</button><button type="button" class="p-btn outline xs" data-write>✍️ ${t('اكتبه دلوقتي', 'Write it now')}</button><button type="button" class="p-btn ghost xs" data-skip>${t('شيله من الخطة', 'Remove')}</button>`
                    : (i.state === 'failed' ? `<button type="button" class="p-btn primary xs" data-retry>🔁 ${t('جرّب تاني', 'Try again')}</button>` : '')
                      + (i.article_id ? `<button type="button" class="p-btn primary xs" data-preview>👁 ${t('معاينة', 'Preview')}</button>` : '')
                      + (i.url ? `<a class="p-btn outline xs" href="${esc(i.url)}" target="_blank" rel="noopener">${t('افتح المقال', 'Open article')} ↗</a>` : '')
                      + (i.article_id && !i.url ? `<a class="p-btn outline xs" href="/ai/articles">${t('عدّل في المقالات', 'Edit in Articles')}</a>` : '')}</div>
            </div></article>`;
    }

    let pollT = null;
    // مقال بيتكتب: نتابع لوحدنا كل 20 ثانية لحد ما يخلص (من غير ما نعيد رسم الصفحة كلها بـ«تحميل»)
    function watch() {
        clearTimeout(pollT);
        if (D && D.items.some(i => i.state === 'writing')) pollT = setTimeout(async () => {
            const res = await api('/' + encodeURIComponent(site()));
            if (res && res.success) { D = res.data; render(); }
            else watch();
        }, 20000);
    }

    function render() {
        if (!D) return;
        watch();
        if (!D.items.length) {
            body.innerHTML = header() + `<div class="p-card ccal-empty">${building ? `<div class="obs-loading"><span></span><span></span><span></span></div><p>${t('بنحلل رحلاتك ومواسمها وبحث جوجل ونجهّز مقالات الشهر…', 'Analysing your trips, their seasons and Google searches to plan the month…')}</p>` : `<p>${t('لسه مفيش خطة للموقع ده.', 'No plan for this site yet.')}</p>`}</div>`;
            return;
        }
        const upcoming = D.items.filter(i => i.date >= D.today || i.state !== 'published');
        const past = D.items.filter(i => !upcoming.includes(i));
        body.innerHTML = header() + `<div class="ccal-grid">${upcoming.map(card).join('')}</div>`
            + (past.length ? `<details class="ccal-past"><summary>${t('اتنشر قبل كده', 'Published earlier')} (${past.length})</summary><div class="ccal-grid">${past.map(card).join('')}</div></details>` : '');
    }

    async function load() {
        body.innerHTML = '<div class="p-loading-row">…</div>';
        const res = await api('/' + encodeURIComponent(site()));
        if (!res || !res.success) { body.innerHTML = `<div class="p-card"><div class="p-empty">${esc((res && res.error) || t('تعذر التحميل', 'Could not load'))}</div></div>`; return; }
        D = res.data;
        render();
        // أول مرة: الخطة بتتجهز لوحدها من غير ما العميل يدوّر على زرار
        if (!D.items.length && !autoTried[site()]) { autoTried[site()] = 1; build(); }
    }

    async function build() {
        if (building) return;
        building = true; render();
        const res = await post('/' + encodeURIComponent(site()) + '/build');
        building = false;
        if (res && res.success) { D = res.data; if (res.message) P.toast(res.message, 'success'); }
        else P.toast((res && res.error) || t('تعذر تجهيز الخطة', 'Could not prepare the plan'), 'error');
        render();
    }

    async function previewModal(i) {
        const m = document.createElement('div');
        m.className = 'ccal-modal';
        m.innerHTML = `<div class="ccal-dlg ccal-pv" role="dialog" aria-modal="true"><div class="p-loading-row">…</div></div>`;
        document.body.appendChild(m);
        const close = () => m.remove();
        m.addEventListener('click', e => { if (e.target === m || e.target.closest('[data-close]')) close(); });
        document.addEventListener('keydown', function esc1(e) { if (e.key === 'Escape') { close(); document.removeEventListener('keydown', esc1); } });
        const res = await api('/item/' + i.id + '/preview');
        const box = m.querySelector('.ccal-dlg');
        if (!res || !res.success) { box.innerHTML = `<p>${esc((res && res.error) || 'Error')}</p><div class="ccal-dlg-a"><button type="button" class="p-btn outline" data-close>${t('إغلاق', 'Close')}</button></div>`; return; }
        const a = res.data;
        // المحتوى HTML نظيف من السيرفر (HostedBlogService::clean)
        box.innerHTML = `${a.image ? `<img class="cover" src="${esc(a.image)}" alt="">` : ''}
            <h2 dir="${/[\u0600-\u06FF]/.test(a.title) ? 'rtl' : 'ltr'}" style="margin:4px 0 0">${esc(a.title)}</h2>
            <p class="meta">📅 ${esc(day(a.date))} · ${nf.format(a.words || 0)} ${t('كلمة', 'words')}${a.meta ? ' · ' + esc(a.meta) : ''}</p>
            <div class="body" dir="${/[\u0600-\u06FF]/.test(a.title) ? 'rtl' : 'ltr'}">${a.html}</div>
            <div class="ccal-dlg-a">${a.url ? `<a class="p-btn primary" href="${esc(a.url)}" target="_blank" rel="noopener">${t('افتح المقال', 'Open article')} ↗</a>` : `<a class="p-btn primary" href="/ai/articles">${t('عدّل في المقالات', 'Edit in Articles')}</a>`}
                <button type="button" class="p-btn outline" data-close>${t('إغلاق', 'Close')}</button></div>`;
    }

    function editModal(i) {
        const m = document.createElement('div');
        m.className = 'ccal-modal';
        m.innerHTML = `<div class="ccal-dlg" role="dialog" aria-modal="true"><h3>✏️ ${t('تعديل المقال', 'Edit article')}</h3>
            <label>${t('عنوان المقال', 'Article title')}<input class="form-control" id="ccT" maxlength="150" dir="auto" value="${esc(i.title)}"></label>
            <label>${t('الكلمة المستهدفة', 'Target keyword')}<input class="form-control" id="ccK" maxlength="100" dir="auto" value="${esc(i.keyword || '')}"></label>
            <label>${t('يوم النشر', 'Publish date')}<input class="form-control" id="ccD" type="date" min="${D.today}" value="${esc(i.date)}"></label>
            <small>${t('لو اليوم ده عليه مقال تاني، المقال ده واللي بعده بيتأخروا يوم يوم تلقائي.', 'If another article is on that day, it and the ones after it move forward one day each.')}</small>
            <div class="ccal-dlg-a"><button type="button" class="p-btn primary" data-save>${t('حفظ', 'Save')}</button><button type="button" class="p-btn outline" data-close>${t('إلغاء', 'Cancel')}</button></div></div>`;
        document.body.appendChild(m);
        const close = () => m.remove();
        m.addEventListener('click', async e => {
            if (e.target === m || e.target.closest('[data-close]')) return close();
            const s = e.target.closest('[data-save]');
            if (!s) return;
            s.disabled = true;
            const res = await post('/item/' + i.id, { title: m.querySelector('#ccT').value, keyword: m.querySelector('#ccK').value, date: m.querySelector('#ccD').value });
            s.disabled = false;
            if (res && res.success) { D = res.data; render(); close(); P.toast(res.message || t('اتحفظ', 'Saved'), 'success'); }
            else P.toast((res && res.error) || 'Error', 'error');
        });
        m.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
        m.querySelector('#ccT').focus();
    }

    body.addEventListener('click', async e => {
        if (e.target.closest('[data-build]')) return build();
        const c = e.target.closest('.ccal-card');
        if (!c) return;
        const i = D.items.find(x => String(x.id) === c.dataset.id);
        if (!i) return;
        if (e.target.closest('[data-edit]')) return editModal(i);
        if (e.target.closest('[data-preview]')) return previewModal(i);
        const rt = e.target.closest('[data-retry]');
        if (rt) {
            rt.disabled = true;
            const res = await post('/item/' + i.id + '/retry');
            if (res && res.success) { D = res.data; render(); P.toast(res.message, 'success'); }
            else { rt.disabled = false; P.toast((res && res.error) || 'Error', 'error'); }
            return;
        }
        const w = e.target.closest('[data-write]');
        if (w) {
            w.disabled = true;
            const res = await post('/item/' + i.id + '/write-now');
            if (res && res.success) { D = res.data; render(); P.toast(res.message, 'success'); }
            else { w.disabled = false; P.toast((res && res.error) || 'Error', 'error'); }
            return;
        }
        if (e.target.closest('[data-skip]')) {
            if (!confirm(t('تشيل المقال ده من الخطة؟', 'Remove this article from the plan?'))) return;
            const res = await post('/item/' + i.id + '/skip');
            if (res && res.success) { D = res.data; render(); } else P.toast((res && res.error) || 'Error', 'error');
            return;
        }
        const ai = e.target.closest('[data-ai]');
        if (ai) {
            ai.disabled = true; ai.textContent = t('بنعمل الصورة…', 'Creating…');
            const res = await post('/item/' + i.id + '/image-ai');
            if (res && res.success) { D = res.data; render(); P.toast(res.message, 'success'); }
            else { P.toast((res && res.error) || 'Error', 'error'); render(); }
        }
    });

    body.addEventListener('change', async e => {
        const inp = e.target.closest('input[data-up]');
        if (!inp || !inp.files.length) return;
        const c = inp.closest('.ccal-card');
        const fd = new FormData();
        fd.append('file', inp.files[0]); fd.append('csrf_token', csrf);
        c.classList.add('busy');
        let res = null;
        try {
            const r = await fetch('/api/content-calendar/item/' + c.dataset.id + '/image', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' }, body: fd });
            res = await r.json();
        } catch (err) { res = null; }
        c.classList.remove('busy');
        if (res && res.success) { D = res.data; render(); P.toast(res.message, 'success'); }
        else P.toast((res && res.error) || t('تعذر رفع الصورة', 'Upload failed'), 'error');
    });

    sel.addEventListener('change', load);
    load();
})();
