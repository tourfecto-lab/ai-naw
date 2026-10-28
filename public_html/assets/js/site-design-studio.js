/* استوديو التصميم (لوحة الموقع ← التصميم): ستايل + لون + لوجو + غلاف + جاليري + صور الرحلات،
 * مع معاينة حية للموقع (كمبيوتر/موبايل) بتتحدث بعد كل تغيير. */
(function () {
    const root = document.getElementById('sdStudio');
    if (!root || !window.Panel) return;
    const P = window.Panel;
    const id = root.getAttribute('data-site');
    const ar = (document.documentElement.lang || 'ar').toLowerCase().startsWith('ar');
    const t = (a, e) => (ar ? a : e);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    const API = '/api/site-dashboard/' + encodeURIComponent(id);
    // شكل كل ستايل في الكارت (خلفية + لون نص + خط) - تقريب لشكله الفعلي على الموقع
    const LOOK = {
        classic: ['#ffffff', '#0F1B2D', "'Plus Jakarta Sans',system-ui", 600],
        luxe: ['#FBF8F2', '#1C1A17', "'Cormorant Garamond',Georgia,serif", 600],
        adventure: ['#1d1f1a', '#f4efe6', "'Archivo',Impact,system-ui", 900],
        coastal: ['#eef7fb', '#0b3a4a', "'Nunito',system-ui", 800],
        heritage: ['#f5efe3', '#3a2a1a', "'Playfair Display',Georgia,serif", 700],
        minimal: ['#fafafa', '#111111', "'DM Serif Display',Georgia,serif", 400],
    };
    let D = null;
    let device = 'desktop';
    let opened = false;

    async function load() {
        const res = await P.fetchJSON(API + '/studio');
        if (!res || !res.success) { root.innerHTML = '<div class="p-card"><div class="p-empty">' + esc((res && res.error) || t('تعذر التحميل', 'Could not load')) + '</div></div>'; return; }
        D = res.data;
        render();
    }

    function previewUrl() { return '/sites/' + encodeURIComponent(D.slug) + '?studio=' + Date.now(); }
    function refreshPreview() { const f = document.getElementById('sdsFrame'); if (f) f.src = previewUrl(); }

    function imgBox(kind, url, label, hint) {
        return `<div class="sds-img">
            <div class="sds-thumb ${kind === 'logo' ? 'logo' : ''}">${url ? `<img src="${esc(url)}" alt="">` : `<span>${kind === 'logo' ? '🏷️' : '🖼️'}</span>`}</div>
            <div class="sds-img-b"><b>${esc(label)}</b><small>${esc(hint)}</small>
                <div class="sds-row"><label class="p-btn outline xs sds-up">${url ? t('تغيير', 'Change') : t('ارفع صورة', 'Upload')}<input type="file" accept="image/jpeg,image/png,image/webp" data-up="${kind}" hidden></label>
                ${url ? `<button type="button" class="p-btn outline xs" data-rm="${kind}">${t('شيل', 'Remove')}</button>` : ''}</div></div></div>`;
    }

    function render() {
        const cur = D.style || '';
        const styleCards = [{ key: '', ar: t('تلقائي', 'Automatic'), en: 'Auto', hint: t('بنختار الأنسب لنشاطك', 'We pick the best fit') + ` (${(D.styles.find(s => s.key === D.auto_style) || {})[ar ? 'ar' : 'en'] || ''})` }, ...D.styles];
        root.innerHTML = `<div class="sds">
            <div class="sds-controls">
                ${D.is_tours ? `<section class="p-card"><h4>🎨 ${t('ستايل الموقع', 'Site style')}</h4><p class="sds-sub">${t('كل ستايل له خطوط وألوان وشكل مختلف تمامًا — موقعك مايشبهش أي موقع تاني.', 'Each style has its own fonts, colours and layout — your site won’t look like anyone else’s.')}</p>
                    <div class="sds-styles">${styleCards.map(s => { const lk = LOOK[s.key || D.auto_style] || LOOK.classic; return `<button type="button" class="sds-style${cur === s.key ? ' on' : ''}" data-style="${s.key}">
                        <span class="sds-swatch" style="background:${lk[0]};color:${lk[1]};font-family:${lk[2]};font-weight:${lk[3]}">Aa<i style="background:var(--sds-acc)"></i></span>
                        <b>${esc(ar ? s.ar : s.en)}</b><small>${esc(s.hint || '')}</small></button>`; }).join('')}</div></section>` : ''}
                <section class="p-card"><h4>🖌️ ${t('اللون الأساسي', 'Main colour')}</h4>
                    <div class="sds-colors">${D.colors.map(c => `<button type="button" class="sds-color${D.theme_color === c.key ? ' on' : ''}" data-color="${c.key}" style="background:${c.hex}" aria-label="${c.key}" title="${c.key}"></button>`).join('')}</div></section>
                <section class="p-card"><h4>🏷️ ${t('الهوية', 'Branding')}</h4>
                    ${imgBox('logo', D.logo_url, t('اللوجو', 'Logo'), t('PNG بخلفية شفافة أفضل', 'PNG with transparent background works best'))}
                    ${imgBox('hero', D.hero_image, t('صورة الغلاف', 'Cover image'), t('أول حاجة الزائر بيشوفها — صورة أفقية واضحة', 'The first thing visitors see — a clear landscape photo'))}</section>
                ${D.tours.length ? `<section class="p-card"><h4>🧭 ${t('صور الرحلات', 'Tour photos')}</h4><p class="sds-sub">${t('صورك الحقيقية بتبيع أكتر من أي صورة جاهزة.', 'Your real photos sell better than stock images.')}</p>
                    <div class="sds-tours">${D.tours.map(x => `<div class="sds-tour"><div class="sds-thumb">${x.image_url ? `<img src="${esc(x.image_url)}" alt="">` : '<span>🖼️</span>'}</div><b dir="auto">${esc(x.name)}</b>
                        <label class="p-btn outline xs sds-up">${x.image_url ? t('تغيير', 'Change') : t('ارفع', 'Upload')}<input type="file" accept="image/jpeg,image/png,image/webp" data-up="tour" data-tour="${esc(x.slug)}" hidden></label></div>`).join('')}</div></section>` : ''}
                <section class="p-card"><h4>📷 ${t('الجاليري', 'Gallery')} <small class="sds-count">${D.gallery.length}/24</small></h4>
                    <div class="sds-gallery">${D.gallery.map(src => `<div class="sds-g"><img src="${esc(src)}" alt=""><button type="button" data-rm="gallery" data-src="${esc(src)}" aria-label="${t('شيل', 'Remove')}">×</button></div>`).join('')}
                        ${D.gallery.length < 24 ? `<label class="sds-g add"><span>+</span><small>${t('ضيف صور', 'Add photos')}</small><input type="file" accept="image/jpeg,image/png,image/webp" data-up="gallery" multiple hidden></label>` : ''}</div></section>
            </div>
            <div class="sds-preview">
                <div class="sds-bar"><b>👁 ${t('معاينة حية', 'Live preview')}</b>
                    <div class="sds-dev"><button type="button" data-dev="desktop" class="${device === 'desktop' ? 'on' : ''}">🖥️</button><button type="button" data-dev="mobile" class="${device === 'mobile' ? 'on' : ''}">📱</button></div>
                    <a href="/sites/${encodeURIComponent(D.slug)}" target="_blank" rel="noopener" class="p-btn outline xs">${t('افتح', 'Open')} ↗</a></div>
                <div class="sds-frame ${device}"><iframe id="sdsFrame" title="preview" src="${previewUrl()}" loading="lazy"></iframe></div>
                <div id="sdsStatus" class="sds-status" aria-live="polite"></div>
            </div>
        </div>`;
        const acc = (D.colors.find(c => c.key === D.theme_color) || {}).hex || '#efb05e';
        root.style.setProperty('--sds-acc', acc);
    }

    function status(txt, ok) { const s = document.getElementById('sdsStatus'); if (s) { s.textContent = txt; s.className = 'sds-status ' + (ok === false ? 'bad' : 'ok'); } }

    async function save(body) {
        status(t('بنحفظ…', 'Saving…'));
        const res = await P.fetchJSON(API + '/studio', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        if (res && res.success) { Object.assign(D, body); render(); status(t('اتحفظ ✓', 'Saved ✓')); }
        else status((res && res.error) || t('تعذر الحفظ', 'Could not save'), false);
    }

    async function upload(kind, file, tour) {
        const fd = new FormData();
        fd.append('file', file); fd.append('kind', kind); fd.append('csrf_token', csrf);
        if (tour) fd.append('tour_slug', tour);
        try {
            const r = await fetch(API + '/upload', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' }, body: fd });
            return await r.json();
        } catch (e) { return { success: false, error: t('تعذر الرفع', 'Upload failed') }; }
    }

    root.addEventListener('click', async e => {
        const s = e.target.closest('[data-style]');
        if (s) { save({ style: s.getAttribute('data-style') }); return; }
        const c = e.target.closest('[data-color]');
        if (c) { save({ theme_color: c.getAttribute('data-color') }); return; }
        const d = e.target.closest('[data-dev]');
        if (d) { device = d.getAttribute('data-dev'); root.querySelectorAll('[data-dev]').forEach(b => b.classList.toggle('on', b === d)); root.querySelector('.sds-frame').className = 'sds-frame ' + device; return; }
        const rm = e.target.closest('[data-rm]');
        if (rm) {
            if (!confirm(t('تشيل الصورة دي؟', 'Remove this image?'))) return;
            const res = await P.fetchJSON(API + '/media/remove', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ kind: rm.getAttribute('data-rm'), src: rm.getAttribute('data-src') || '' }) });
            if (res && res.success) { await load(); status(t('اتشالت ✓', 'Removed ✓')); } else status((res && res.error) || 'Error', false);
        }
    });
    root.addEventListener('change', async e => {
        const inp = e.target.closest('input[type=file][data-up]');
        if (!inp || !inp.files.length) return;
        const kind = inp.getAttribute('data-up');
        const files = Array.from(inp.files).slice(0, kind === 'gallery' ? 24 : 1);
        let ok = 0, err = '';
        for (let i = 0; i < files.length; i++) {
            status(t('بنرفع', 'Uploading') + (files.length > 1 ? ` ${i + 1}/${files.length}` : '') + '…');
            const r = await upload(kind, files[i], inp.getAttribute('data-tour'));
            if (r && r.success) ok++; else err = (r && r.error) || t('تعذر الرفع', 'Upload failed');
        }
        await load();
        status(err ? err : t('اترفعت ✓', 'Uploaded ✓'), err ? false : true);
    });

    window.SdStudio = { open() { if (!opened) { opened = true; load(); } else refreshPreview(); } };
    // لو التاب مفتوح أصلًا (رابط مباشر)
    if (document.getElementById('sdTabDesign') && document.getElementById('sdTabDesign').style.display !== 'none') window.SdStudio.open();
})();
