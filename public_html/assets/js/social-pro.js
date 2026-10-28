/**
 * Tourfecto - مركز السوشيال ميديا الاحترافي (/social)
 * تبويبات: نظرة عامة / المنشورات والتقويم / صندوق التعليقات / الخطة الشهرية / الحسابات
 * محرر منشور: منشور أو ستوري، كاتب ذكي بـ 3 نسخ، هاشتاجات، أول تعليق، UTM، معاينة حية، فحص جودة، أفضل ميعاد.
 * كل النصوص ثنائية اللغة عبر T(ar, en) حسب لغة اللوحة.
 */
(function () {
    'use strict';
    const P = window.Panel;
    if (!P || !document.getElementById('spRoot')) return;
    const esc = P.esc;
    // أي رد "خارج الباقة" (402 + upgrade) بيظهر شريط بزرار يفتح تبويب الباقة
    const fetchJSON = async (url, opts) => {
        const r = await P.fetchJSON(url, opts);
        if (r && r.success === false && r.details && r.details.upgrade) upgradeBar(r.error);
        return r;
    };
    function upgradeBar(msg) {
        const bar = document.getElementById('spUpgradeBar');
        if (!bar) return;
        bar.innerHTML = '<span>' + IC('gem') + ' ' + esc(msg || '') + '</span><button type="button" class="p-btn primary sm" id="spUpBtn">' + (AR_ ? 'شوف الباقات' : 'See plans') + '</button><button type="button" class="p-btn ghost xs" id="spUpX" aria-label="x">×</button>';
        bar.hidden = false;
        document.getElementById('spUpBtn').onclick = () => { location.href = '/subscription#social'; };
        document.getElementById('spUpX').onclick = () => { bar.hidden = true; };
    }
    const AR = (document.documentElement.lang || 'ar').slice(0, 2) === 'ar';
    const AR_ = AR;
    const T = (a, e) => (AR ? a : e);
    const $ = id => document.getElementById(id);
    // صلاحيات الدور في فريق الحساب
    const CAN = new Set(((document.getElementById('spRoot') || {}).dataset || {}).can ? document.getElementById('spRoot').dataset.can.split(',') : ['view', 'draft', 'publish', 'inbox', 'manage']);
    const can = p => CAN.has(p);
    const jsonPost = (url, body) => fetchJSON(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) });
    const nf = n => Number(n || 0).toLocaleString(AR ? 'ar-EG' : 'en-GB');
    const spLocal = s => (P.formatDate ? P.formatDate(s) : (s || '-'));
    const utc = s => new Date(String(s).replace(' ', 'T') + 'Z');
    const isVid = p => /\.(mp4|mov)$/i.test(p || '');
    // روابط جاية من المنصات: https بس (ما نحطش javascript: أو روابط غريبة في href)
    const safeUrl = u => (/^https:\/\//i.test(String(u || '')) ? String(u) : '');

    const IC = (n, sz) => (window.SPI ? window.SPI.i(n, sz || 15) : '');
    const BR = (p, sz) => (window.SPI ? window.SPI.brand(p, sz || 16) : '');
    // صورة الصفحة (لو موجودة) + شعار المنصة صغير في الركن؛ لو الصورة ما اتحملتش بيظهر أول حرف
    const av = (c, size) => {
        const sz = size || 40, name = (c && (c.name || c.username)) || '?';
        return '<span class="sp-avt" style="width:' + sz + 'px;height:' + sz + 'px;font-size:' + Math.round(sz * .42) + 'px" data-p="' + esc((c && c.platform) || '') + '"><span class="ini">' + esc(name.trim().charAt(0).toUpperCase()) + '</span>'
            + (c && c.avatar && /^https:\/\//.test(c.avatar) ? '<img src="' + esc(c.avatar) + '" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()">' : '')
            + '<span class="bdg">' + BR(c && c.platform, Math.max(14, Math.round(sz * .42))) + '</span></span>';
    };
    const fmtK = n => (n === null || n === undefined) ? '' : (n >= 1e6 ? (n / 1e6).toFixed(1).replace(/\.0$/, '') + 'M' : n >= 1e3 ? (n / 1e3).toFixed(1).replace(/\.0$/, '') + 'K' : String(n));
    const PF = { facebook: BR('facebook'), instagram: BR('instagram'), tiktok: BR('tiktok'), youtube: BR('youtube'), google_business: BR('google_business'), threads: BR('threads'), linkedin: BR('linkedin') };
    const PN = { facebook: 'Facebook', instagram: 'Instagram', tiktok: 'TikTok', youtube: 'YouTube', google_business: 'Google Business', threads: 'Threads', linkedin: 'LinkedIn' };
    const DAYS = AR ? ['الأحد', 'الاتنين', 'التلات', 'الأربع', 'الخميس', 'الجمعة', 'السبت'] : ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    const ST = {
        draft: ['pill', T('مسودة', 'Draft')], scheduled: ['pill', T('مجدول', 'Scheduled')], pending: ['pill', T('مجدول', 'Scheduled')],
        publishing: ['pill orange', T('بيتنشر…', 'Publishing…')], partial: ['pill orange', T('اتنشر جزئيًا', 'Partially published')],
        uncertain: ['pill orange', T('محتاج مراجعة', 'Needs review')], published: ['pill green', T('✔ اتنشر', '✔ Published')], failed: ['pill red', T('فشل', 'Failed')]
    };
    const pill = s => ST[s] ? '<span class="' + ST[s][0] + '">' + ST[s][1] + '</span>' : esc(s || '');
    // حدود النص لكل منصة (كابشن)
    const LIMITS = { facebook: 63206, instagram: 2200, tiktok: 2200, youtube: 5000, google_business: 1500, threads: 500, linkedin: 3000 };
    const NEEDS_MEDIA = ['instagram', 'tiktok', 'youtube'];

    // ================= التبويبات =================
    const tabs = document.querySelectorAll('.sp-tabs .p-tab');
    const loaded = {};
    function showTab(name) {
        if (!document.querySelector('.sp-panel[data-panel="' + name + '"]')) name = 'overview';
        tabs.forEach(b => { const on = b.dataset.tab === name; b.classList.toggle('active', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
        document.querySelectorAll('.sp-panel').forEach(p => { p.hidden = p.dataset.panel !== name; });
        if (!loaded[name]) {
            loaded[name] = true;
            if (name === 'posts') { load(); loadCalendar(); }
            if (name === 'inbox') { loadInbox(); loadInboxSettings(); }
            if (name === 'accounts') loadAccounts();
            if (name === 'competitors') loadCompetitors();
            if (name === 'plan' && $('shPlan')) loadShare();
            if (name === 'package') loadPackage();
        }
        if (location.hash.slice(1) !== name) history.replaceState(null, '', '#' + name);
        // القسم الحالي في القايمة اللي بتنزل من الهيدر
        document.querySelectorAll('.tf-dd').forEach(d => { let cur = ''; d.querySelectorAll('.tf-dd-item').forEach(x => { const on = x.pathname === location.pathname && x.dataset.sec === name; x.classList.toggle('on', on); if (on) cur = x.textContent; }); const c = d.querySelector('.tf-dd-cur'); if (c) c.textContent = cur ? '· ' + cur : ''; });
    }
    tabs.forEach(b => b.addEventListener('click', () => showTab(b.dataset.tab)));
    // التنقل من القايمة اللي بتنزل من زرار السوشيال في الهيدر (روابط /social#inbox ...)
    window.addEventListener('hashchange', () => { const h = (location.hash || '').slice(1); if (h && document.querySelector('.sp-panel[data-panel="' + h + '"]')) { showTab(h); window.scrollTo({ top: 0, behavior: 'smooth' }); } });

    // ================= المحرر =================
    let media = null;          // {id, file_path, is_video}
    let libLoaded = false;
    let albumMode = false, album = [];
    let connections = [];
    const format = () => ((document.querySelector('input[name=spFormat]:checked') || {}).value === 'story' ? 'story' : 'feed');
    const checkedTargets = () => Array.from(document.querySelectorAll('.targetCheckbox:checked'));
    const selectedPlatforms = () => [...new Set(checkedTargets().map(cb => cb.dataset.platform))];

    window.openNewPost = function (fmt) {
        const r = document.querySelector('input[name=spFormat][value="' + (fmt === 'story' ? 'story' : 'feed') + '"]');
        if (r) r.checked = true;
        if (!$('utmCampaign').value) { const d = new Date(); $('utmCampaign').value = 'social-' + d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0'); }
        $('newPostModal').classList.add('open');
        applyFormat();
        loadTargets();
    };
    window.closeComposer = () => $('newPostModal').classList.remove('open');
    window.refreshSocialTargets = async function () { await loadTargets(); P.toast(T('تم تحديث قائمة الحسابات', 'Accounts refreshed'), 'success'); };

    function applyFormat() {
        const story = format() === 'story';
        $('spComposerTitle').textContent = story ? T('ستوري جديدة', 'New story') : T('منشور جديد', 'New post');
        document.querySelectorAll('.sp-feed-only').forEach(el => { el.hidden = story; });
        document.querySelectorAll('.sp-story-only').forEach(el => { el.hidden = !story; });
        $('spWhenNum').textContent = story ? '3' : '4';
        $('spAlbumBtn').hidden = story;
        if (story && albumMode) { albumMode = false; setMedia(album[0] || media); }
        document.querySelectorAll('.targetCheckbox').forEach(cb => {
            const off = story && !['facebook', 'instagram'].includes(cb.dataset.platform);
            cb.disabled = off; if (off) cb.checked = false;
            cb.closest('label').classList.toggle('off', off);
        });
        refreshComposer();
    }

    function submitLabel() {
        const later = (document.querySelector('input[name=spWhen]:checked') || {}).value === 'later';
        const any = checkedTargets().length > 0;
        $('spSubmit').textContent = !any ? T('حفظ كمسودة', 'Save as draft') : (!can('publish') ? T('إرسال للمدير للاعتماد', 'Send for approval') : (later ? T('جدولة', 'Schedule') : T('انشر دلوقتي', 'Publish now')));
    }

    document.addEventListener('change', e => {
        const el = e.target; if (!el) return;
        if (el.name === 'spFormat') applyFormat();
        if (el.name === 'spWhen') {
            const later = el.value === 'later';
            $('spAt').hidden = !later; $('spBestBtn').hidden = !later;
            if (later && !$('spAt').value) { const d = new Date(Date.now() + 3600e3); d.setMinutes(0, 0, 0); $('spAt').value = new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16); }
        }
        if (el.classList && el.classList.contains('targetCheckbox')) { renderTikTok(); }
        if (el.name === 'spWhen' || (el.classList && el.classList.contains('targetCheckbox'))) refreshComposer();
    });
    ['contentInput', 'hashtagsInput'].forEach(id => $(id).addEventListener('input', refreshComposer));

    // ---- الوسائط
    function paintMedia() {
        const prev = $('spPrev');
        prev.innerHTML = (!media ? '' + IC('image', 28) + '' : (media.is_video ? '<video src="' + esc(media.file_path) + '" muted playsinline></video>' : '<img src="' + esc(media.file_path) + '" alt="">'))
            + (album.length > 1 ? '<span class="sp-count">' + album.length + '</span>' : '');
        const strip = $('spAlbumStrip');
        strip.innerHTML = album.map((m, i) => '<span class="sp-alb"><img src="' + esc(m.file_path) + '" alt=""><b>' + (i + 1) + '</b><i data-i="' + i + '" title="' + T('شيل', 'Remove') + '">×</i></span>').join('');
        strip.hidden = !album.length;
        $('spClear').hidden = !(media || album.length);
        $('spAlbumBtn').classList.toggle('on', albumMode);
        document.querySelectorAll('#spLib button').forEach(b => b.classList.toggle('on', album.length ? album.some(a => a.id === +b.dataset.id) : (!!media && +b.dataset.id === media.id)));
        refreshComposer();
    }
    function setMedia(m) { media = m; album = []; paintMedia(); }
    function setAlbum(list) { album = list.slice(0, 10); media = album[0] || null; paintMedia(); }
    function toggleAlbumItem(item) {
        if (item.is_video) { P.toast(T('الصور المتعددة للصور بس - الفيديو بيتنشر لوحده', 'Multiple photos are images only — videos publish alone'), 'error'); return; }
        const i = album.findIndex(a => a.id === item.id);
        if (i >= 0) setAlbum(album.filter((_, k) => k !== i));
        else if (album.length >= 10) P.toast(T('الحد الأقصى 10 صور', 'Maximum 10 photos'), 'error');
        else setAlbum(album.concat([item]));
    }
    window.toggleAlbumMode = function () {
        albumMode = !albumMode;
        if (albumMode) { album = media && !media.is_video ? [media] : []; media = album[0] || null; }
        else { media = album[0] || media; album = []; }
        paintMedia();
        if (albumMode && $('spLib').hidden) window.toggleLib();
    };
    window.clearMedia = function () { albumMode = false; setMedia(null); $('spFile').value = ''; };
    document.addEventListener('click', e => {
        const x = e.target && e.target.closest ? e.target.closest('#spAlbumStrip i') : null;
        if (x) setAlbum(album.filter((_, k) => k !== +x.dataset.i));
    });
    $('spFile').addEventListener('change', async e => {
        const f = e.target.files && e.target.files[0];
        if (!f) return;
        const note = $('spMediaNote'), old = note.textContent;
        note.textContent = T('جارِ الرفع…', 'Uploading…');
        const fd = new FormData(); fd.append('file', f);
        const res = await fetchJSON('/api/social/media', { method: 'POST', body: fd });
        note.textContent = old;
        if (res.success) { const it = res.data.item; (albumMode && !it.is_video) ? toggleAlbumItem(it) : setMedia(it); P.toast(T('اترفعت ✓', 'Uploaded ✓'), 'success'); libLoaded = false; }
        else P.toast(res.error || T('فشل الرفع', 'Upload failed'), 'error');
    });
    window.toggleLib = async function () {
        const box = $('spLib');
        if (!box.hidden) { box.hidden = true; return; }
        box.hidden = false;
        if (libLoaded) return;
        box.innerHTML = '<span class="p-cell-muted">' + T('جارِ التحميل…', 'Loading…') + '</span>';
        const res = await fetchJSON('/api/creative-studio/media');
        const items = ((res.success && res.data.items) || []).filter(i => i.status === 'completed' && i.file_path);
        libLoaded = true;
        box.innerHTML = items.length ? items.map(i => '<button type="button" data-id="' + i.id + '" data-path="' + esc(i.file_path) + '" title="' + esc(i.prompt || '') + '">' + (isVid(i.file_path) || i.type === 'short_video' ? '<video src="' + esc(i.file_path) + '" muted></video>' : '<img loading="lazy" src="' + esc(i.thumbnail_path || i.file_path) + '" alt="">') + '</button>').join('')
            : '<span class="p-cell-muted">' + T('مفيش صور في المكتبة لسه. <a href="/creative-studio">صمّم من Creative Studio</a> أو ارفع من جهازك.', 'Your library is empty. <a href="/creative-studio">Create in Creative Studio</a> or upload.') + '</span>';
        box.querySelectorAll('button').forEach(b => b.addEventListener('click', () => { const it = { id: +b.dataset.id, file_path: b.dataset.path, is_video: isVid(b.dataset.path) || !!b.querySelector('video') }; albumMode ? toggleAlbumItem(it) : setMedia(it); }));
        paintMedia();
    };

    // ---- الصفحات
    async function loadTargets() {
        const box = $('targetsList');
        box.textContent = T('جارِ تحديث الصفحات المربوطة…', 'Refreshing connected pages…');
        const res = await fetchJSON('/api/social/connections?_=' + Date.now(), { cache: 'no-store' });
        if (!res.success) { box.innerHTML = '<div class="alert alert-danger">' + T('تعذر تحميل الصفحات: ', 'Could not load pages: ') + esc(res.error || '') + '</div>'; return; }
        connections = res.data.connections || [];
        const off = res.data.disconnected || [];
        const offHtml = off.length ? '<div class="alert alert-warning sp-small">' + IC('alert') + ' ' + T('صفحات محتاجة إعادة ربط: ', 'Pages that need reconnecting: ') + off.map(c => (PF[c.platform] || '') + ' <b>' + esc(c.name || c.platform) + '</b>').join(', ') + ' — <a href="/ads/connect/meta?for=social">' + T('اربط تاني', 'Reconnect') + '</a></div>' : '';
        if (connections.length) {
            const lockedTxt = c => c.locked === 'platform' ? T('المنصة دي مش ضمن باقتك', 'This platform is not in your plan') : T('فوق عدد الحسابات في باقتك', 'Over your plan\'s account limit');
            const usable = connections.filter(c => c.active !== false && c.locked !== 'inactive');
            const paused = connections.length - usable.length;
            box.innerHTML = '<div class="sp-pick-bar"><button type="button" class="p-btn ghost xs" id="spPickAll">' + IC('check') + ' ' + T('اختار الكل', 'Select all') + '</button><button type="button" class="p-btn ghost xs" id="spPickNone">' + T('إلغاء الاختيار', 'Clear') + '</button>'
                + (paused ? '<span class="p-cell-muted sp-small">' + nf(paused) + T(' صفحة موقوفة - شغّلها من تبويب «الحسابات»', ' paused page(s) — enable them in the Accounts tab') + '</span>' : '') + '</div>'
                + '<div class="sp-pick">' + usable.map(c => '<label class="sp-pick-it' + (c.locked ? ' locked' : '') + '"' + (c.locked ? ' title="' + esc(lockedTxt(c)) + '"' : '') + '><input type="checkbox" class="targetCheckbox" value="' + c.id + '" data-platform="' + esc(c.platform) + '" data-name="' + esc(c.name || '') + '" data-avatar="' + esc(c.avatar || '') + '"' + (c.locked ? ' disabled' : '') + '>'
                    + av(c, 38) + '<span class="sp-pick-tx"><b>' + esc(c.name || PN[c.platform] || c.platform) + '</b><small>' + esc(PN[c.platform] || c.platform) + (c.username ? ' · @' + esc(c.username) : '') + '</small></span>'
                    + (c.locked ? '<span class="sp-pick-ck lk">' + IC('lock', 13) + '</span>' : '<span class="sp-pick-ck">' + IC('check', 13) + '</span>') + '</label>').join('') + '</div>'
                + (usable.some(c => c.locked) ? '<div class="sp-small p-cell-muted">' + IC('lock') + ' ' + T('حسابات مقفولة بسبب حدود الباقة - ', 'Accounts locked by your plan limits — ') + '<a href="/subscription#social">' + T('رقّي الباقة', 'Upgrade') + '</a></div>' : '') + offHtml;
            const setAll = on => { box.querySelectorAll('.targetCheckbox:not(:disabled)').forEach(cb => { cb.checked = on; }); box.querySelector('.targetCheckbox') && box.querySelector('.targetCheckbox').dispatchEvent(new Event('change', { bubbles: true })); };
            $('spPickAll').addEventListener('click', () => setAll(true));
            $('spPickNone').addEventListener('click', () => setAll(false));
            
        } else {
            box.innerHTML = '<div class="alert alert-warning">' + T('مفيش صفحات قابلة للنشر لسه. اربط حساباتك وانت بتختار الصفحات وبتوافق على كل الصلاحيات.', 'No publishable pages yet. Connect your accounts, select the pages and accept all permissions.') + '</div><div class="sp-row"><a class="p-btn primary sm" href="/ads/connect/meta?for=social">Facebook + Instagram</a><a class="p-btn outline sm" href="/social/connect/tiktok">TikTok</a><a class="p-btn outline sm" href="/social/connect/youtube">YouTube</a><a class="p-btn outline sm" href="/social/connect/threads">Threads</a></div>' + offHtml;
        }
        applyFormat();
    }

    // ---- الكاتب الذكي
    let variants = [];
    window.generateCaption = async function () {
        const topic = $('topicInput').value.trim();
        if (!topic) { P.toast(T('اكتب موضوع المنشور الأول', 'Write the post topic first'), 'error'); $('topicInput').focus(); return; }
        const btn = $('aiCapBtn'); btn.disabled = true; const lbl = btn.textContent; btn.textContent = T('بيكتب…', 'Writing…');
        const res = await jsonPost('/api/social/generate-caption', { topic, platforms: selectedPlatforms(), tone: $('aiTone').value, goal: $('aiGoal').value, format: format(), website_id: P.getCurrentWebsiteId ? P.getCurrentWebsiteId() : null });
        btn.disabled = false; btn.textContent = lbl;
        if (!res.success) { P.toast(res.error || T('فشل التوليد', 'Generation failed'), 'error'); return; }
        variants = res.data.variants || (res.data.content ? [{ angle: '', content: res.data.content, hashtags: res.data.hashtags || [], first_comment: '' }] : []);
        const box = $('aiVariants');
        box.hidden = !variants.length;
        box.innerHTML = variants.map((v, i) => '<div class="sp-var"><div class="sp-var-h"><b>' + T('نسخة ', 'Version ') + (i + 1) + '</b>' + (v.angle ? ' · <span class="p-cell-muted">' + esc(v.angle) + '</span>' : '') + '<button type="button" class="p-btn primary xs" data-use="' + i + '">' + T('استخدم', 'Use') + '</button></div><div class="sp-var-t">' + esc(v.content) + '</div>' + (v.hashtags && v.hashtags.length ? '<div class="sp-var-tags">' + v.hashtags.map(h => '#' + esc(h)).join(' ') + '</div>' : '') + '</div>').join('');
        box.querySelectorAll('[data-use]').forEach(b => b.addEventListener('click', () => useVariant(+b.dataset.use)));
        if (!$('contentInput').value.trim()) useVariant(0, true);
    };
    function useVariant(i, silent) {
        const v = variants[i]; if (!v) return;
        $('contentInput').value = v.content;
        $('hashtagsInput').value = (v.hashtags || []).map(h => '#' + h).join(' ');
        if (v.first_comment && !$('firstCommentInput').value.trim()) $('firstCommentInput').value = v.first_comment;
        document.querySelectorAll('.sp-var').forEach((el, k) => el.classList.toggle('on', k === i));
        refreshComposer();
        if (!silent) P.toast(T('اتحطت النسخة - عدّل براحتك', 'Version applied — edit freely'), 'success');
    }

    // ---- هاشتاجات
    function hashtagList() {
        return [...new Set(($('hashtagsInput').value || '').split(/[\s,،]+/).map(h => h.replace(/^#+/, '').trim()).filter(h => /^[\p{L}\p{N}_]{1,60}$/u.test(h)))].slice(0, 30);
    }
    function finalText() {
        const tags = hashtagList();
        const c = $('contentInput').value.trim();
        return tags.length ? c + '\n\n' + tags.map(h => '#' + h).join(' ') : c;
    }

    // ---- المعاينة + فحص الجودة
    let previewPlatform = null;
    const CTA = /(احجز|حجز|تواصل|واتس|راسلنا|كلمنا|الخاص|اسأل|رابط|اللينك|سجّل|اطلب|اشترك|علّق|شارك|احفظ|book|reserve|contact|whatsapp|message|dm|link|call|comment|share|save|tag|sign up|learn more|visit)/i;
    function refreshComposer() {
        submitLabel();
        const story = format() === 'story';
        const plats = selectedPlatforms();
        const shown = plats.length ? plats : ['facebook', 'instagram'];
        if (!previewPlatform || !shown.includes(previewPlatform)) previewPlatform = shown[0];
        $('spPrevTabs').innerHTML = shown.map(p => '<button type="button" class="' + (p === previewPlatform ? 'on' : '') + '" data-pp="' + p + '">' + (PF[p] || '') + ' ' + esc(PN[p] || p) + '</button>').join('');
        $('spPrevTabs').querySelectorAll('[data-pp]').forEach(b => b.addEventListener('click', () => { previewPlatform = b.dataset.pp; refreshComposer(); }));

        const cb = checkedTargets().find(x => x.dataset.platform === previewPlatform);
        const name = cb ? cb.dataset.name : T('صفحتك', 'Your page');
        const pav = cb && cb.dataset.avatar ? '<span class="sp-av"><img src="' + esc(cb.dataset.avatar) + '" alt="" referrerpolicy="no-referrer" onerror="this.remove()">' + esc((name || '?').charAt(0)) + '</span>' : '<span class="sp-av">' + esc((name || '?').charAt(0)) + '</span>';
        const text = finalText();
        const mediaHtml = album.length > 1
            ? '<div class="sp-mock-media"><img src="' + esc(album[0].file_path) + '" alt=""><span class="sp-count">1/' + album.length + '</span></div>'
            : (media ? '<div class="sp-mock-media' + (story ? ' story' : '') + '">' + (media.is_video ? '<video src="' + esc(media.file_path) + '" muted playsinline loop autoplay></video>' : '<img src="' + esc(media.file_path) + '" alt="">') + '</div>' : '');
        let body;
        if (story) {
            body = '<div class="sp-mock story-frame">' + (mediaHtml || '<div class="sp-mock-empty">9:16</div>') + '<div class="sp-mock-story-head">' + pav + '' + esc(name) + '</div></div>';
        } else {
            const cut = previewPlatform === 'instagram' ? 125 : (previewPlatform === 'facebook' ? 480 : 150);
            const shortT = text.length > cut ? esc(text.slice(0, cut)) + '… <span class="p-cell-muted">' + T('المزيد', 'more') + '</span>' : esc(text);
            const caption = '<div class="sp-mock-text">' + (previewPlatform === 'instagram' ? '<b>' + esc(name) + '</b> ' : '') + (shortT || '<span class="p-cell-muted">' + T('النص هيظهر هنا', 'Your caption appears here') + '</span>') + '</div>';
            body = '<div class="sp-mock ' + previewPlatform + '"><div class="sp-mock-head">' + pav + '<div><b>' + esc(name) + '</b><small>' + T('دلوقتي', 'Just now') + '</small></div></div>'
                + (previewPlatform === 'instagram' || previewPlatform === 'tiktok' || previewPlatform === 'youtube' ? mediaHtml + caption : caption + mediaHtml) + '</div>';
        }
        $('spMock').innerHTML = body;

        // فحص الجودة
        const checks = [];
        const add = (ok, a, e) => checks.push('<li class="' + (ok === true ? 'ok' : ok === false ? 'bad' : 'warn') + '">' + (ok === true ? '✓' : ok === false ? '✕' : '!') + ' ' + T(a, e) + '</li>');
        const needsMedia = story || plats.some(p => NEEDS_MEDIA.includes(p));
        add(media ? true : (needsMedia ? false : null), 'صورة أو فيديو' + (needsMedia ? ' (إجباري هنا)' : ' (بتضاعف التفاعل)'), 'Image or video' + (needsMedia ? ' (required here)' : ' (boosts engagement)'));
        if (plats.some(p => p === 'tiktok' || p === 'youtube')) add(!!(media && media.is_video), 'تيك توك ويوتيوب فيديو بس', 'TikTok & YouTube need a video');
        if (!story) {
            const c = $('contentInput').value.trim();
            const first = c.split('\n')[0] || '';
            add(c.length > 0 ? (first.length <= 125 ? true : null) : false, 'أول سطر جذاب وقصير (≤125 حرف)', 'Short, catchy first line (≤125 chars)');
            add(CTA.test(c) ? true : null, 'دعوة واضحة لإجراء (احجز/تواصل/علّق)', 'Clear call to action (book/contact/comment)');
            const tags = hashtagList().length;
            if (plats.includes('instagram') || !plats.length) add(tags >= 3 && tags <= 15 ? true : null, 'هاشتاجات إنستجرام (3–8 مثالي): ' + tags, 'Instagram hashtags (3–8 ideal): ' + tags);
            const over = plats.filter(p => text.length > LIMITS[p]);
            add(over.length ? false : true, over.length ? 'النص أطول من المسموح على ' + over.map(p => PN[p]).join('، ') : 'الطول مناسب لكل المنصات', over.length ? 'Too long for ' + over.map(p => PN[p]).join(', ') : 'Length fits every platform');
            const counter = plats.length ? plats.map(p => (PF[p] || '') + ' ' + nf(text.length) + '/' + nf(LIMITS[p])).join(' · ') : nf(text.length) + ' ' + T('حرف', 'chars');
            $('spCounter').innerHTML = counter;
        } else if (album.length > 1) add(false, 'الستوري صورة أو فيديو واحد بس', 'A story takes a single image or video');
        add(plats.length ? true : null, plats.length ? 'هيتنشر على ' + plats.length + ' منصة' : 'مختارتش صفحة - هيتحفظ مسودة', plats.length ? 'Publishing to ' + plats.length + ' platform(s)' : 'No page selected — saves as draft');
        $('spChecklist').innerHTML = checks.join('');
    }

    // ---- أفضل ميعاد
    let bestCache = null;
    async function bestTimesData() {
        if (bestCache) return bestCache;
        const res = await fetchJSON('/api/social/best-times');
        if (res.success) bestCache = res.data;
        return bestCache;
    }
    window.pickBestTime = async function () {
        const d = await bestTimesData();
        if (!d) { P.toast(T('تعذر حساب أفضل ميعاد', 'Could not compute the best time'), 'error'); return; }
        const tz = d.timezone || 'UTC';
        const fmt = new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23', weekday: 'short' });
        const wd = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };
        const parts = date => Object.fromEntries(fmt.formatToParts(date).map(p => [p.type, p.value]));
        const nowP = parts(new Date(Date.now() + 30 * 60e3));
        const nowKey = nowP.year + '-' + nowP.month + '-' + nowP.day + 'T' + nowP.hour + ':' + nowP.minute;
        for (let i = 0; i < 8; i++) {
            const p = parts(new Date(Date.now() + i * 86400e3));
            const hm = (d.by_day || {})[wd[p.weekday]] || '19:00';
            const cand = p.year + '-' + p.month + '-' + p.day + 'T' + hm;
            if (cand > nowKey) {
                $('spAt').value = cand;
                P.toast((d.source === 'measured' ? T('أفضل ميعاد من نتايجك: ', 'Best slot from your results: ') : T('ميعاد مرجعي لقطاع السياحة: ', 'Tourism benchmark slot: ')) + DAYS[wd[p.weekday]] + ' ' + hm, 'success');
                return;
            }
        }
    };

    // ---- تيك توك (شروط TikTok Content Posting API)
    let tt = null;
    const TT_PRIV = { PUBLIC_TO_EVERYONE: T('الكل (Public)', 'Everyone (Public)'), MUTUAL_FOLLOW_FRIENDS: T('الأصدقاء', 'Friends'), FOLLOWER_OF_CREATOR: T('المتابعين', 'Followers'), SELF_ONLY: T('أنا بس (Private)', 'Only me (Private)') };
    async function renderTikTok() {
        const panel = $('ttPanel');
        const cb = document.querySelector('.targetCheckbox[data-platform="tiktok"]:checked');
        if (!cb) { panel.hidden = true; tt = null; return; }
        panel.hidden = false;
        if (tt && tt.cid === cb.value) return;
        panel.innerHTML = '<span class="p-cell-muted">' + T('بنجيب بيانات حساب تيك توك…', 'Loading TikTok account…') + '</span>';
        const res = await fetchJSON('/api/social/tiktok/creator-info?connection_id=' + encodeURIComponent(cb.value));
        if (!res.success) { panel.innerHTML = '<div class="alert alert-danger">' + esc(res.error || T('تعذر الوصول لتيك توك', 'Could not reach TikTok')) + ' <a href="/social/connect/tiktok">' + T('اربط تيك توك تاني', 'Reconnect TikTok') + '</a></div>'; tt = null; return; }
        tt = Object.assign({ cid: cb.value }, res.data);
        const opt = (k, label, disabled) => '<label class="' + (disabled ? 'off' : '') + '"><input type="checkbox" id="tt_' + k + '"' + (disabled ? ' disabled' : '') + '> ' + label + (disabled ? ' <span class="p-cell-muted">' + T('(مقفولة من إعدادات حسابك)', '(disabled in your account settings)') + '</span>' : '') + '</label>';
        panel.innerHTML = '<div class="tt-head">' + (tt.avatar ? '<img src="' + esc(tt.avatar) + '" alt="" referrerpolicy="no-referrer">' : '' + BR('tiktok', 18) + '') + '<div><b>' + T('هيتنشر على تيك توك: ', 'Posting to TikTok: ') + esc(tt.nickname) + '</b>' + (tt.username ? '<div class="p-cell-muted" dir="ltr">@' + esc(tt.username) + '</div>' : '') + '</div></div>'
            + '<label class="sp-field">' + T('مين يقدر يشوف الفيديو؟', 'Who can view this video?') + '<select id="tt_privacy" class="p-select"><option value="">' + T('اختار…', 'Select…') + '</option>' + tt.privacy_level_options.map(o => '<option value="' + esc(o) + '">' + esc(TT_PRIV[o] || o) + '</option>').join('') + '</select></label>'
            + '<div class="tt-row">' + opt('comment', T('السماح بالتعليقات', 'Allow comments'), tt.comment_disabled) + opt('duet', T('السماح بالـ Duet', 'Allow Duet'), tt.duet_disabled) + opt('stitch', T('السماح بالـ Stitch', 'Allow Stitch'), tt.stitch_disabled) + '</div>'
            + '<label class="sp-check"><input type="checkbox" id="tt_commercial"> ' + T('الفيديو ده بيروّج لبراند أو منتج أو خدمة (Disclose commercial content)', 'This video promotes a brand, product or service (Disclose commercial content)') + '</label>'
            + '<div id="tt_comm_box" class="tt-sub" hidden><label class="sp-check"><input type="checkbox" id="tt_brand_organic"> ' + T('علامتك التجارية (Your brand) — هيظهر عليه "Promotional content"', 'Your brand — labelled "Promotional content"') + '</label><label class="sp-check"><input type="checkbox" id="tt_brand_content"> ' + T('محتوى مدفوع لبراند تاني (Branded content) — هيظهر عليه "Paid partnership"', 'Branded content — labelled "Paid partnership"') + '</label></div>'
            + '<div class="tt-note p-cell-muted" id="tt_consent"></div>'
            + (tt.max_video_post_duration_sec ? '<div class="tt-note p-cell-muted">' + T('أقصى مدة للفيديو على حسابك: ', 'Max video length on your account: ') + Math.floor(tt.max_video_post_duration_sec / 60) + T(' دقيقة. ممكن ياخد كام دقيقة لحد ما يظهر على البروفايل.', ' min. It may take a few minutes to appear on your profile.') + '</div>' : '');
        ttConsent();
    }
    function ttConsent() {
        const box = $('tt_consent'); if (!box) return;
        const bc = $('tt_brand_content') && $('tt_brand_content').checked;
        box.innerHTML = T('بالنشر انت موافق على ', 'By posting, you agree to TikTok\'s ') + (bc ? '<a href="https://www.tiktok.com/legal/page/global/bc-policy/en" target="_blank" rel="noopener">Branded Content Policy</a> ' + T('و', 'and ') : '') + '<a href="https://www.tiktok.com/legal/page/global/music-usage-confirmation/en" target="_blank" rel="noopener">Music Usage Confirmation</a>' + T(' الخاصة بتيك توك.', '.');
        const priv = $('tt_privacy');
        if (priv) { const self = [...priv.options].find(o => o.value === 'SELF_ONLY'); if (self) { self.disabled = !!bc; self.textContent = TT_PRIV.SELF_ONLY + (bc ? T(' — مش متاح مع المحتوى المدفوع', ' — not available for branded content') : ''); if (bc && priv.value === 'SELF_ONLY') priv.value = ''; } }
    }
    document.addEventListener('change', e => {
        const el = e.target; if (!el) return;
        if (el.id === 'tt_commercial') { $('tt_comm_box').hidden = !el.checked; if (!el.checked) { $('tt_brand_organic').checked = false; $('tt_brand_content').checked = false; } ttConsent(); }
        if (el.id === 'tt_brand_content' || el.id === 'tt_brand_organic') ttConsent();
    });
    function tikTokOptions() {
        if (!document.querySelector('.targetCheckbox[data-platform="tiktok"]:checked')) return { ok: true, value: null };
        if (!tt) return { ok: false, error: T('استنى لحد ما بيانات حساب تيك توك تظهر', 'Wait for the TikTok account details to load') };
        if (!media || !media.is_video) return { ok: false, error: T('تيك توك بيقبل فيديو بس - اختار فيديو', 'TikTok accepts video only — pick a video') };
        if (!$('tt_privacy').value) return { ok: false, error: T('اختار مين يقدر يشوف الفيديو على تيك توك', 'Choose who can view the video on TikTok') };
        const commercial = $('tt_commercial').checked;
        if (commercial && !$('tt_brand_organic').checked && !$('tt_brand_content').checked) return { ok: false, error: T('حدد نوع المحتوى التجاري، أو شيل علامة "بيروّج لبراند"', 'Choose the commercial content type, or untick "promotes a brand"') };
        const prev = document.querySelector('#spPrev video');
        if (prev && tt.max_video_post_duration_sec && prev.duration && prev.duration > tt.max_video_post_duration_sec) return { ok: false, error: T('الفيديو أطول من المسموح على حسابك في تيك توك', 'The video is longer than your TikTok account allows') };
        return { ok: true, value: { privacy_level: $('tt_privacy').value, allow_comment: !!($('tt_comment') || {}).checked, allow_duet: !!($('tt_duet') || {}).checked, allow_stitch: !!($('tt_stitch') || {}).checked, commercial, brand_organic: $('tt_brand_organic').checked, brand_content: $('tt_brand_content').checked } };
    }

    // ---- الإرسال
    window.createPost = async function () {
        const story = format() === 'story';
        const content = $('contentInput').value.trim();
        if (!story && !content) { P.toast(T('اكتب نص المنشور', 'Write the post text'), 'error'); $('contentInput').focus(); return; }
        const later = (document.querySelector('input[name=spWhen]:checked') || {}).value === 'later';
        const atVal = $('spAt').value;
        if (later && !atVal) { P.toast(T('اختار ميعاد النشر', 'Pick a publish time'), 'error'); return; }
        const checked = checkedTargets();
        const albumIds = !story && album.length >= 2 ? album.map(a => a.id) : null;
        if (story && !media) { P.toast(T('الستوري محتاجة صورة أو فيديو', 'A story needs an image or video'), 'error'); return; }
        if (albumIds && checked.some(cb => ['tiktok', 'youtube'].includes(cb.dataset.platform))) { P.toast(T('الصور المتعددة لفيسبوك وإنستجرام بس - شيل تيك توك/يوتيوب أو اختار صورة واحدة', 'Multiple photos are for Facebook & Instagram only — untick TikTok/YouTube or pick one image'), 'error'); return; }
        if (!media && checked.some(cb => NEEDS_MEDIA.includes(cb.dataset.platform))) { P.toast(T('إنستجرام وتيك توك ويوتيوب محتاجين صورة أو فيديو', 'Instagram, TikTok and YouTube need an image or video'), 'error'); return; }
        if (media && media.is_video && checked.some(cb => ['google_business', 'linkedin'].includes(cb.dataset.platform))) { P.toast(T('Google Business وLinkedIn بيقبلوا صور بس من هنا - شيلهم أو اختار صورة', 'Google Business and LinkedIn accept images only here — untick them or pick an image'), 'error'); return; }
        if (checked.some(cb => ['tiktok', 'youtube'].includes(cb.dataset.platform)) && !(media && media.is_video)) { P.toast(T('تيك توك ويوتيوب فيديو بس', 'TikTok and YouTube accept video only'), 'error'); return; }
        const ttOpts = story ? { ok: true, value: null } : tikTokOptions();
        if (!ttOpts.ok) { P.toast(ttOpts.error, 'error'); return; }
        const targets = checked.map(cb => ({ platform_connection_id: parseInt(cb.value, 10), scheduled_at: later ? atVal : null }));
        const body = {
            content: story ? (content || 'Story') : content, targets, post_format: format(),
            media_item_id: media ? media.id : null, media_item_ids: albumIds, tiktok: ttOpts.value,
            hashtags: story ? null : hashtagList(), first_comment: story ? null : $('firstCommentInput').value.trim(),
            utm_campaign: !story && $('utmOn').checked ? $('utmCampaign').value.trim() : '',
            track_links: !story && $('trackOn').checked,
            website_id: P.getCurrentWebsiteId ? P.getCurrentWebsiteId() : null
        };
        const btn = $('spSubmit'); btn.disabled = true; const lbl = btn.textContent; btn.textContent = targets.length && !later ? T('بينشر…', 'Publishing…') : T('بيحفظ…', 'Saving…');
        const res = await jsonPost('/api/social/posts', body);
        btn.disabled = false; btn.textContent = lbl;
        if (!res.success) { P.toast(res.error || T('فشل الحفظ', 'Save failed'), 'error'); return; }
        // منشور جاي من «رحلات الموسم»: نعلّمه إنه اتستخدم
        if (seasonDraftId) { jsonPost('/api/revenue-intelligence/season/posts/' + seasonDraftId + '/state', { state: 'used', social_post_id: res.data.post && res.data.post.id }); seasonDraftId = null; }
        closeComposer();
        if (res.data.pending_approval) {
            P.toast(T('اتبعت للمدير يعتمده ✔ هتوصلك إشعار لما يتنشر', 'Sent for approval ✔ you will be notified when it is published'), 'success');
            ['contentInput', 'topicInput', 'hashtagsInput', 'firstCommentInput'].forEach(id => { $(id).value = ''; });
            clearMedia(); loadStats(); if (!document.querySelector('.sp-panel[data-panel="posts"]').hidden) load();
            return;
        }
        const st = res.data.post && res.data.post.status;
        P.toast(!targets.length ? T('اتحفظ كمسودة', 'Saved as draft') : (later ? T('اتجدول ✔', 'Scheduled ✔') : st === 'published' ? T('اتنشر ✔', 'Published ✔') : st === 'failed' ? T('النشر فشل - شوف السبب في جدول المنشورات', 'Publishing failed — see the reason in Posts') : T('بيتنشر… هتلاقي النتيجة في جدول المنشورات', 'Publishing… check the result in Posts')), st === 'failed' ? 'error' : 'success');
        ['contentInput', 'topicInput', 'hashtagsInput', 'firstCommentInput'].forEach(id => { $(id).value = ''; });
        $('aiVariants').hidden = true; variants = [];
        $('spLib').hidden = true; libLoaded = false;
        clearMedia();
        loaded.posts = false; loadStats();
        if (!document.querySelector('.sp-panel[data-panel="posts"]').hidden) { load(); loadCalendar(); }
    };

    // ================= المنشورات =================
    let postsById = {};
    let autoRefreshed = false;
    // عملة الحجز نفسها (مش عملة اللوحة) عشان الإيراد يطلع بالرقم الحقيقي
    const money = (v, cur) => { try { return new Intl.NumberFormat('en-US', { style: 'currency', currency: cur || window.TF_CURRENCY || 'USD', maximumFractionDigits: 0 }).format(+v || 0); } catch (e) { return nf(v) + (cur ? ' ' + cur : ''); } };
    function roiLine(a) {
        if (!a || (!a.clicks && !a.bookings)) return '';
        return '<div class="sp-roi-line">' + IC('link') + ' ' + nf(a.clicks) + T(' نقرة', ' clicks') + (a.bookings ? ' · ' + IC('receipt') + ' <b>' + nf(a.bookings) + T(' حجز', ' bookings') + '</b>' + (a.revenue ? ' · ' + esc(money(a.revenue, a.currency)) : '') : '') + '</div>';
    }
    function engCell(p) {
        const t = (p.targets || []).filter(x => x.status === 'published');
        if (!t.length) return '<span class="p-cell-muted">-</span>' + roiLine(p.attribution);
        return '<div class="sp-eng">' + roiLine(p.attribution) + t.map(x => {
            if (x.metrics_state === 'ok') return (PF[x.platform] || '•') + ' ' + IC('thumb') + ' ' + nf(x.likes) + ' · ' + IC('chat') + ' ' + nf(x.comments) + ' · ' + IC('share') + ' ' + nf(x.shares) + (x.reach !== null && x.reach !== undefined ? ' · ' + IC('eye') + ' ' + nf(x.reach) : '') + (x.saves ? ' · ' + IC('bookmark') + ' ' + nf(x.saves) : '') + (x.views ? ' · ▶️ ' + nf(x.views) : '');
            if (x.metrics_state === 'unavailable') return (PF[x.platform] || '•') + ' <span class="p-cell-muted" title="' + esc(x.metrics_note || '') + '">' + T('غير متاح', 'Unavailable') + '</span>';
            return (PF[x.platform] || '•') + ' <span class="p-cell-muted">' + (x.platform === 'facebook' || x.platform === 'instagram' ? T('بيتحدّث…', 'Updating…') : '-') + '</span>';
        }).join('<br>') + '</div>';
    }
    function targetsCell(p) {
        const t = p.targets || [];
        if (!t.length) return '<span class="p-cell-muted">' + T('مسودة', 'Draft') + '</span>';
        return t.map(x => '<div><span class="sp-tgt">' + (PF[x.platform] || '•') + ' ' + esc(x.name || x.platform) + ' ' + pill(x.status)
            + (x.status === 'published' && safeUrl(x.permalink) ? ' <a href="' + esc(safeUrl(x.permalink)) + '" target="_blank" rel="noopener">' + T('افتح ' + IC('external') + '', 'Open ' + IC('external') + '') + '</a>' : '')
            + (x.scheduled_at && x.status !== 'published' ? ' <span class="p-cell-muted">' + esc(spLocal(x.scheduled_at)) + '</span>' : '')
            + '</span>' + ((['failed', 'uncertain'].includes(x.status) || x.next_retry_at) && x.last_error ? '<div class="sp-err">' + esc(x.last_error) + '</div>' : '') + '</div>').join('');
    }
    function filterQS() {
        const q = new URLSearchParams();
        if ($('spQ').value.trim()) q.set('q', $('spQ').value.trim());
        if ($('spFStatus').value) q.set('status', $('spFStatus').value);
        if ($('spFPlat').value) q.set('platform', $('spFPlat').value);
        return q.toString();
    }
    let loadTimer = null;
    const PAGE = 12;
    let allPosts = [], shown = PAGE, renderCards = () => {};
    async function load() {
        clearTimeout(loadTimer);
        const res = await fetchJSON('/api/social/posts?' + filterQS());
        const list = $('postsList');
        if (res.success && res.data.posts && res.data.posts.length) {
            postsById = {};
            allPosts = res.data.posts;
            shown = Math.max(shown, PAGE);
            renderCards = () => { list.innerHTML = allPosts.slice(0, shown).map(p => {
                postsById[p.id] = p;
                const ts = p.targets || [];
                const failed = ts.some(x => x.status === 'failed'), uncertain = ts.some(x => x.status === 'uncertain'), busy = ts.some(x => x.status === 'publishing');
                const story = p.post_format === 'story';
                const editable = !story && ((['draft', 'scheduled', 'failed'].includes(p.status) && !busy) || ts.some(x => x.status === 'published' && x.platform === 'facebook'));
                const thumb = p.media_path ? ((p.media_type === 'short_video' || isVid(p.media_path)) ? '' + IC('video') + ' ' : '<img src="' + esc(p.media_path) + '" alt="" class="sp-thumb">') : '';
                const pending = p.approval_state === 'pending';
                const approval = pending ? '<div class="sp-appr"><span class="pill orange">' + IC('hourglass') + ' ' + T('منتظر الاعتماد', 'Awaiting approval') + '</span>' + (p.author ? ' <span class="p-cell-muted sp-small">' + T('من ', 'by ') + esc(p.author) + '</span>' : '') + (can('publish') ? ' <button class="p-btn primary xs" onclick="reviewPost(' + p.id + ', \'approve\')">✔ ' + T('اعتماد ونشر', 'Approve & publish') + '</button> <button class="p-btn ghost xs" onclick="reviewPost(' + p.id + ', \'reject\')">' + IC('undo') + ' ' + T('رجّعه بملاحظة', 'Send back') + '</button>' : '') + '</div>'
                    : (p.approval_state === 'rejected' ? '<div class="sp-appr"><span class="pill red">' + IC('undo') + ' ' + T('محتاج تعديل', 'Needs changes') + '</span>' + (p.review_note ? ' <span class="sp-small">' + esc(p.review_note) + '</span>' : '') + '</div>' : '');
                return '<article class="sp-post"><div class="sp-post-media">' + (thumb || IC(story ? 'story' : 'posts', 22)) + '</div><div class="sp-post-body"><div class="sp-post-top">' + pill(p.status) + (story ? ' <span class="pill">' + IC('story', 13) + ' ' + T('ستوري', 'Story') + '</span>' : '') + '<span class="p-cell-muted sp-post-date">' + IC('clock', 13) + ' ' + esc(spLocal(p.created_at)) + '</span></div>'
                    + (story ? '' : '<div class="sp-post-text">' + esc(p.content || '') + '</div>') + approval + '<div class="sp-post-tg">' + targetsCell(p) + '</div><div class="sp-post-eng">' + engCell(p) + '</div></div><div class="sp-actions">'
                    + (failed && can('publish') ? '<button class="p-btn outline xs" onclick="retryPost(' + p.id + ', this)">' + IC('refresh') + ' ' + T('إعادة', 'Retry') + '</button> ' : '')
                    + (uncertain && can('publish') ? '<button class="p-btn outline xs" onclick="reconcilePost(' + p.id + ', this)" title="' + T('نسأل المنصة: المنشور اتنشر فعلًا؟', 'Ask the platform whether it was published') + '">' + IC('search') + ' ' + T('تحقق', 'Verify') + '</button> <button class="p-btn ghost xs" onclick="resolvePost(' + p.id + ')" title="' + T('حسم يدوي', 'Resolve manually') + '">' + IC('hand') + '</button> ' : '')
                    + (editable && can('publish') ? '<button class="p-btn ghost xs" onclick="editPost(' + p.id + ')" title="' + T('تعديل', 'Edit') + '">' + IC('edit') + '</button>' : '')
                    + (can('draft') ? '<button class="p-btn ghost xs" onclick="duplicatePost(' + p.id + ')" title="' + T('نسخة كمسودة', 'Duplicate as draft') + '">' + IC('copy') + '</button>' : '')
                    + (!busy && can('publish') ? '<button class="p-btn ghost xs" onclick="deletePost(' + p.id + ')" title="' + T('مسح', 'Delete') + '">' + IC('trash') + '</button>' : '') + '</div></article>';
            }).join(''); $('spMore').hidden = allPosts.length <= shown; };
            renderCards();
            if (res.data.posts.some(p => p.status === 'publishing')) loadTimer = setTimeout(load, 8000);
            const needs = res.data.posts.some(p => (p.targets || []).some(x => x.status === 'published' && (x.platform === 'facebook' || x.platform === 'instagram') && !x.metrics_at));
            if (needs && !autoRefreshed) { autoRefreshed = true; refreshMetrics(false); }
        } else {
            $('spMore').hidden = true;
            list.innerHTML = '<div class="p-empty">' + (filterQS() ? T('مفيش نتايج بالفلتر ده', 'No results for this filter') : T('لسه مفيش منشورات. ابدأ بأول منشور من الزرار فوق.', 'No posts yet. Create your first one from the button above.')) + '</div>';
        }
    }
    let qTimer = null;
    let seasonDraftId = null;
    $('spMore').addEventListener('click', () => { shown += PAGE; renderCards(); });
    ['spFStatus', 'spFPlat'].forEach(id => $(id).addEventListener('change', load));
    $('spQ').addEventListener('input', () => { clearTimeout(qTimer); qTimer = setTimeout(load, 350); });

    async function loadCalendar() {
        const res = await fetchJSON('/api/social/calendar');
        const box = $('calendarGrid');
        const rows = (res.success && res.data.items) || [];
        const today = new Date(); today.setHours(0, 0, 0, 0);
        const start = new Date(today); start.setDate(start.getDate() - 7);
        start.setDate(start.getDate() - ((start.getDay() + 1) % 7)); // الأسبوع يبدأ السبت
        const key = d => d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate();
        const byDay = {};
        rows.forEach(r => { const d = utc(r.scheduled_at); if (!isNaN(d)) (byDay[key(d)] = byDay[key(d)] || []).push({ d, r }); });
        const order = [6, 0, 1, 2, 3, 4, 5];
        let html = order.map(n => '<div class="h">' + DAYS[n] + '</div>').join('');
        for (let i = 0; i < 42; i++) {
            const d = new Date(start); d.setDate(start.getDate() + i);
            const ev = (byDay[key(d)] || []).sort((a, b) => a.d - b.d);
            html += '<div class="d' + (+d === +today ? ' today' : '') + (d < today ? ' out' : '') + '"><div class="n">' + d.getDate() + (d.getDate() === 1 ? ' / ' + (d.getMonth() + 1) : '') + '</div>'
                + ev.slice(0, 3).map(x => '<span class="e ' + esc(x.r.status) + '" title="' + esc((x.r.content || '').slice(0, 120)) + '">' + (PF[x.r.platform] || '•') + ' ' + x.d.toLocaleTimeString(AR ? 'ar-EG-u-nu-latn' : 'en-GB', { hour: 'numeric', minute: '2-digit' }) + ' ' + esc((x.r.content || '').slice(0, 14)) + '</span>').join('')
                + (ev.length > 3 ? '<span class="p-cell-muted">+' + (ev.length - 3) + '</span>' : '') + '</div>';
        }
        box.innerHTML = html;
    }

    // ---- تعديل / نسخ / إعادة / حذف / مصالحة
    let editId = null, editMode = 'draft';
    window.editPost = function (id) {
        const p = postsById[id]; if (!p) return;
        editId = id;
        const ts = p.targets || [];
        editMode = ['draft', 'scheduled', 'failed'].includes(p.status) && !ts.some(x => x.status === 'published') ? 'draft' : 'published';
        $('editContent').value = p.content || '';
        $('editAt').value = '';
        $('editWhenBox').hidden = !(editMode === 'draft' && ts.some(x => x.status === 'scheduled'));
        $('editNote').textContent = editMode === 'published'
            ? T('التعديل هيتطبق على فيسبوك مباشرة (إنستجرام وتيك توك ما بيسمحوش بتعديل النص بعد النشر).', 'The edit is applied on Facebook directly (Instagram & TikTok do not allow editing after publishing).')
            : T('التعديل قبل النشر. المواعيد بتوقيت حسابك.', 'Editing before publishing. Times use your account timezone.');
        $('editPostModal').classList.add('open');
    };
    window.saveEdit = async function () {
        const content = $('editContent').value.trim();
        if (!content) { P.toast(T('اكتب نص المنشور', 'Write the post text'), 'error'); return; }
        const btn = $('editSubmit'); btn.disabled = true;
        const res = editMode === 'published'
            ? await jsonPost('/api/social/posts/' + editId + '/edit-published', { content })
            : await jsonPost('/api/social/posts/' + editId + '/update', { content, scheduled_at: $('editAt').value || '' });
        btn.disabled = false;
        if (res.success) { $('editPostModal').classList.remove('open'); P.toast(T('اتحفظ التعديل ✔', 'Saved ✔'), 'success'); load(); loadCalendar(); }
        else P.toast(res.error || T('تعذر الحفظ', 'Could not save'), 'error');
    };
    window.duplicatePost = async function (id) {
        const res = await jsonPost('/api/social/posts/' + id + '/duplicate');
        if (res.success) { P.toast(T('اتعملت نسخة كمسودة - عدّلها وانشرها', 'Duplicated as a draft'), 'success'); load(); loadStats(); } else P.toast(res.error || T('تعذر', 'Failed'), 'error');
    };
    window.retryPost = async function (id, btn) {
        if (btn) { btn.disabled = true; btn.textContent = T('بينشر…', 'Publishing…'); }
        const res = await jsonPost('/api/social/posts/' + id + '/retry');
        if (res.success) P.toast(res.data.status === 'published' ? T('اتنشر ✔', 'Published ✔') : res.data.status === 'failed' ? T('لسه فاشل - شوف السبب', 'Still failing — see the reason') : T('بيتنشر…', 'Publishing…'), res.data.status === 'failed' ? 'error' : 'success');
        else P.toast(res.error || T('تعذر', 'Failed'), 'error');
        load();
    };
    window.deletePost = async function (id) {
        const p = postsById[id] || {};
        const onFb = (p.targets || []).some(x => x.status === 'published' && x.platform === 'facebook');
        let remote = false;
        if (onFb) {
            remote = confirm(T('المنشور منشور على فيسبوك.\nOK = امسحه من فيسبوك ومن المنصة\nCancel = خيارات تانية', 'This post is live on Facebook.\nOK = delete it from Facebook and here\nCancel = more options'));
            if (!remote && !confirm(T('تمسحه من المنصة بس (هيفضل ظاهر على فيسبوك)؟', 'Delete it here only (it stays on Facebook)?'))) return;
        } else if (!confirm(T('مسح المنشور من المنصة؟ (لو اتنشر على إنستجرام هيفضل هناك - Meta ما بتسمحش بحذفه من هنا)', 'Delete this post here? (If it is on Instagram it stays there — Meta does not allow deleting it from here)'))) return;
        const res = await jsonPost('/api/social/posts/' + id + '/delete', { remote });
        if (res.success) { P.toast(T('اتمسح', 'Deleted'), 'success'); load(); loadCalendar(); loadStats(); } else P.toast(res.error || T('تعذر', 'Failed'), 'error');
    };
    window.reviewPost = async function (id, decision) {
        let note = '';
        if (decision === 'reject') { note = prompt(T('اكتب للعضو إيه المطلوب تعديله:', 'Tell the team member what to change:')) || ''; if (!note.trim()) return; }
        else if (!confirm(T('اعتماد المنشور؟ هيتنشر على الصفحات وفي المواعيد اللي اختارها العضو.', 'Approve this post? It will publish to the pages and times the member chose.'))) return;
        const res = await jsonPost('/api/social/posts/' + id + '/review', { decision, note });
        if (res.success) { P.toast(decision === 'approve' ? T('اتعتمد ✔', 'Approved ✔') : T('اترجع للعضو', 'Sent back'), 'success'); load(); loadStats(); loadCalendar(); }
        else P.toast(res.error || T('تعذر', 'Failed'), 'error');
    };
    window.reconcilePost = async function (id, btn) {
        if (btn) { btn.disabled = true; btn.textContent = '…'; }
        const res = await jsonPost('/api/social/posts/' + id + '/reconcile');
        if (res.success) { const r = (res.data.results || [])[0] || {}; P.toast(r.message || T('تم التحقق', 'Verified'), r.outcome === 'still_uncertain' ? 'error' : 'success'); }
        else P.toast(res.error || T('تعذر', 'Failed'), 'error');
        load();
    };
    window.resolvePost = async function (id) {
        const a = prompt(T('المنشور ظاهر على الصفحة؟\n1 = أيوه اتنشر\n2 = لأ ماتنشرش (هيتسمح بإعادة النشر)', 'Is the post visible on the page?\n1 = Yes, it was published\n2 = No (allows publishing again)'));
        const resolution = a === '1' ? 'published' : (a === '2' ? 'not_published' : null);
        if (!resolution) return;
        const res = await jsonPost('/api/social/posts/' + id + '/resolve', { resolution });
        if (res.success) P.toast(T('تم الحسم', 'Resolved'), 'success'); else P.toast(res.error || T('تعذر', 'Failed'), 'error');
        load();
    };

    // ================= نظرة عامة =================
    const HP_ERR = { token: T('ربط/توكن', 'Token'), permission: T('صلاحيات', 'Permissions'), rate_limit: T('حد استخدام', 'Rate limit'), blocked: T('حظر مؤقت', 'Temporarily blocked'), media: T('وسائط', 'Media'), param: T('بيانات المنشور', 'Post data'), transient: T('عطل مؤقت', 'Transient'), unknown: T('غير معروف', 'Unknown') };
    function healthHtml(pages, connections) {
        if (!pages || !pages.length) return '';
        const current = new Map((connections || []).map(c => [String(c.id), c]));
        const relink = ' <a href="/ads/connect/meta?for=social">' + T('اربط تاني', 'Reconnect') + '</a>';
        const active = [], inactive = [];
        pages.forEach(p => {
            const account = current.get(String(p.connection_id));
            // A connection absent from the current account roster must not be reported as active.
            const enabled = !!(account && account.active !== false && !account.locked);
            const h = p.connection_health || {};
            const rate = p.success_rate;
            const cls = rate == null ? '' : (rate >= 95 ? 'ok' : (rate >= 80 ? 'warn' : 'bad'));
            const state = h.state || '';
            let conn = '';
            if (enabled) {
                if (state === 'expired' || state === 'invalid') conn = '<span class="sp-hp bad">' + IC('x') + ' ' + T('محتاج إعادة ربط', 'Needs reconnecting') + '</span>' + relink;
                else if (state === 'missing_permissions') conn = '<span class="sp-hp bad">' + IC('alert') + ' ' + T('صلاحيات ناقصة: ', 'Missing permissions: ') + esc(h.missing_scopes || '') + '</span>' + relink;
                else if (state === 'expiring') conn = '<span class="sp-hp warn">' + IC('hourglass') + ' ' + T('الربط قرّب ينتهي', 'Connection expiring soon') + '</span>' + relink;
                else if (state === 'ok') conn = '<span class="sp-hp ok">' + IC('check') + ' ' + T('الربط سليم', 'Connection healthy') + '</span>';
            } else {
                conn = '<span class="sp-hp">' + (account && account.active === false ? T('متوقف من Accounts', 'Paused in Accounts') : T('غير نشط / قديم', 'Inactive / historical')) + '</span>';
            }
            const errs = Object.entries(p.top_errors || {}).slice(0, 3).map(([k, v]) => esc(HP_ERR[k] || k) + ' ×' + (+v)).join(AR ? '، ' : ', ');
            const stats = p.attempts > 0
                ? '<span class="sp-hp ' + cls + '">' + rate + '% ' + T('نجاح', 'success') + '</span> <span class="p-cell-muted">(' + (+p.attempts) + ' ' + T('محاولة تاريخية', 'historical attempts') + ')</span>'
                : '<span class="p-cell-muted">' + T('مفيش محاولات نشر في الفترة دي', 'No publishing attempts in this period') + '</span>';
            const row = '<div class="sp-hrow' + (enabled && p.alert ? ' alert' : '') + '"><span class="nm">' + (enabled && p.alert ? IC('alert') + ' ' : '') + esc(PN[p.platform] || p.platform || '') + ' · ' + esc(p.name || '') + '</span>' + stats + (errs ? ' <span class="p-cell-muted">' + T('أخطاء سابقة: ', 'Past errors: ') + errs + '</span>' : '') + ' ' + conn + '</div>';
            (enabled ? active : inactive).push(row);
        });
        return '<div class="sp-pad sp-health-groups"><b>' + IC('pulse') + ' ' + T('صحة النشر', 'Publishing health') + '</b>'
            + '<div class="sp-health-section-label">' + T('الحسابات النشطة', 'Active accounts') + ' · ' + active.length + '</div>'
            + (active.join('') || '<p class="p-cell-muted">' + T('لا توجد حسابات نشطة', 'No active accounts') + '</p>')
            + (inactive.length ? '<details class="sp-health-inactive"><summary>' + T('الحسابات المتوقفة والسجل القديم', 'Paused accounts & historical records') + ' (' + inactive.length + ')</summary><p class="p-cell-muted sp-small">' + T('الأخطاء القديمة محفوظة للرجوع إليها وليست تنبيهات حالية.', 'Historical errors are retained for reference, not treated as current alerts.') + '</p>' + inactive.join('') + '</details>' : '')
            + '</div>';
    }
    async function loadHealth() {
        const box = $('spHealth');
        try {
            const [res, accountRes] = await Promise.all([
                fetchJSON('/api/social/health?days=' + (+$('spDays').value || 30)),
                fetchJSON('/api/social/connections?_=' + Date.now(), { cache: 'no-store' })
            ]);
            // Fail closed: never flag paused accounts as current incidents if their current state could not be checked.
            if (!accountRes.success) {
                box.innerHTML = '<div class="sp-pad p-cell-muted">' + T('تعذر التحقق من حالة الحسابات الحالية. حاول تحديث الصفحة.', 'Could not verify current account status. Refresh to retry.') + '</div>';
                box.hidden = false;
                return;
            }
            const html = res.success ? healthHtml(res.data.pages, accountRes.data.connections) : '';
            box.innerHTML = html; box.hidden = !html;
        } catch (e) { box.hidden = true; }
    }
    async function loadBest() {
        const d = await bestTimesData();
        const box = $('spBest');
        if (!d) { box.textContent = '-'; return; }
        $('spBestSub').textContent = d.source === 'measured' ? T('من تفاعل منشوراتك الفعلي', 'From your real engagement') + ' (' + d.timezone + ')' : T('مواعيد مرجعية لحد ما يتجمع ' + d.min_measured + ' منشورات متقاسة', 'Benchmarks until ' + d.min_measured + ' measured posts');
        if (d.source === 'measured' && d.top && d.top.length) {
            box.innerHTML = d.top.map((x, i) => '<div class="sp-prow"><span class="sp-medal">' + ['🥇', '🥈', '🥉'][i] + '</span><b>' + DAYS[x.dow] + '</b><span>' + String(x.hour).padStart(2, '0') + ':00</span><span class="p-cell-muted sp-end">' + T('متوسط تفاعل ', 'avg ') + nf(x.avg) + ' · ' + nf(x.n) + T(' منشور', ' posts') + '</span></div>').join('');
        } else {
            const by = d.by_day || {};
            box.innerHTML = '<div class="sp-week">' + [0, 1, 2, 3, 4, 5, 6].map(i => '<span><b>' + DAYS[i] + '</b>' + esc(by[i] || '') + '</span>').join('') + '</div><p class="p-cell-muted sp-small">' + T('فعّل "الجدولة الذكية" في الخطة الشهرية عشان تتنشر في المواعيد دي تلقائيًا.', 'Turn on Smart timing in the monthly plan to publish at these times automatically.') + '</p>';
        }
    }
    async function loadStats() {
        loadHealth();
        loadGrowth();
        const days = +$('spDays').value || 30;
        const res = await fetchJSON('/api/social/stats?days=' + days);
        const set = (id, v) => { const el = $(id); if (el) el.textContent = v; };
        if (!res.success) {
            ['stTotal', 'stPub', 'stSched', 'stFail', 'stReach', 'stLikes', 'stComments', 'stShares'].forEach(i => set(i, '–'));
            $('spPlat').innerHTML = '<span class="p-cell-muted">' + esc(res.error || T('تعذر تحميل الإحصائيات', 'Could not load statistics')) + '</span>';
            $('spTop').innerHTML = '';
            return;
        }
        const d = res.data, st = d.by_status || {}, e = d.engagement || {};
        set('stTotal', nf(d.total_posts)); set('stPub', nf(st.published)); set('stRate', d.success_rate !== null ? '(' + nf(d.success_rate) + '%)' : '');
        set('stSched', nf((st.scheduled || 0) + (st.publishing || 0)) + ' / ' + nf(st.draft)); set('stFail', nf(st.failed));
        const hasM = (e.measured || 0) > 0;
        set('stReach', hasM ? nf(e.reach) : '–'); set('stLikes', hasM ? nf(e.likes) : '–'); set('stComments', hasM ? nf(e.comments) : '–'); set('stShares', hasM ? nf(e.shares) : '–');
        $('spMetricsNote').textContent = e.last_fetch ? T('آخر تحديث للتفاعل: ', 'Engagement last updated: ') + spLocal(e.last_fetch) : T('إنستجرام لازم صورة أو فيديو. فيسبوك ينفع نص بس.', 'Instagram needs an image or video. Facebook accepts text only.');
        const ser = d.daily || [], max = Math.max(1, ...ser.map(x => x.count));
        $('spDaily').innerHTML = ser.map(x => '<i class="' + (x.count ? '' : 'z') + '" style="height:' + Math.max(2, Math.round(x.count / max * 100)) + '%" title="' + esc(x.date) + ': ' + x.count + '"></i>').join('');
        $('spDailyAxis').innerHTML = ser.length ? '<span>' + esc(ser[0].date.slice(5)) + '</span><span>' + esc(ser[ser.length - 1].date.slice(5)) + '</span>' : '';
        $('spDailySub').textContent = nf(ser.reduce((a, x) => a + x.count, 0)) + T(' نشر', ' published');
        const pl = Object.entries(d.by_platform || {});
        $('spPlat').innerHTML = pl.length ? pl.map(([k, v]) => {
            const tot = Math.max(1, v.total);
            return '<div class="sp-prow"><span class="nm">' + (PF[k] || '•') + ' ' + esc(PN[k] || k) + '</span><div class="sp-meter" title="' + T('اتنشر ', 'Published ') + v.published + ' · ' + T('فشل ', 'Failed ') + v.failed + ' · ' + T('مجدول ', 'Scheduled ') + v.scheduled + '"><b style="width:' + v.published / tot * 100 + '%;background:#22c55e"></b><b style="width:' + v.scheduled / tot * 100 + '%;background:#6366f1"></b><b style="width:' + v.failed / tot * 100 + '%;background:#ef4444"></b></div><span class="sp-num-r">' + nf(v.total) + '</span></div>';
        }).join('') + '<div class="p-cell-muted sp-small">🟢 ' + T('اتنشر', 'Published') + ' &nbsp;🟣 ' + T('مجدول', 'Scheduled') + ' &nbsp;🔴 ' + T('فشل', 'Failed') + '</div>' : '<span class="p-cell-muted">' + T('مفيش بيانات في الفترة دي', 'No data in this period') + '</span>';
        // العائد من السوشيال: نقرات روابط التتبع والحجوزات اللي جت منها
        const bk = d.bookings || {};
        const roi = $('spRoi');
        if (bk.clicks || bk.bookings) {
            roi.hidden = false;
            roi.innerHTML = '<div class="sp-roi-grid"><div><span>' + IC('link') + '</span><b>' + nf(bk.clicks) + '</b><small>' + T('نقرة على روابط منشوراتك', 'clicks on your post links') + '</small></div><div><span>' + IC('receipt') + '</span><b>' + nf(bk.bookings) + '</b><small>' + T('حجز جه من السوشيال', 'bookings from social') + '</small></div><div><span>' + IC('wallet') + '</span><b>' + esc(money(bk.revenue || 0, bk.currency)) + '</b><small>' + T('إيراد مؤكد من السوشيال', 'confirmed social revenue') + '</small></div>'
                + (bk.clicks ? '<div><span>' + IC('trend') + '</span><b>' + (bk.clicks ? (Math.round(bk.bookings / bk.clicks * 1000) / 10) : 0) + '%</b><small>' + T('نسبة التحويل', 'conversion rate') + '</small></div>' : '') + '</div>';
        } else roi.hidden = true;
        const top = d.top_posts || [];
        $('spTop').innerHTML = top.length ? top.map(x => '<div class="sp-prow"><span>' + (PF[x.platform] || '•') + '</span><span class="sp-ellipsis">' + esc(x.content) + '</span><span class="sp-eng">' + IC('thumb') + ' ' + nf(x.likes) + ' · ' + IC('chat') + ' ' + nf(x.comments) + ' · ' + IC('share') + ' ' + nf(x.shares) + '</span></div>').join('') : '<span class="p-cell-muted">' + T('لسه مفيش بيانات تفاعل. اضغط «تحديث التفاعل من Meta».', 'No engagement data yet. Press "Refresh engagement from Meta".') + '</span>';
    }
    window.refreshMetrics = async function (manual) {
        const btn = $('spMetricsBtn'); const lbl = btn.textContent;
        if (manual) { btn.disabled = true; btn.textContent = T('جارِ السحب من Meta…', 'Fetching from Meta…'); }
        const res = await jsonPost('/api/social/metrics/refresh', { force: manual ? 1 : 0 });
        if (manual) { btn.disabled = false; btn.textContent = lbl; }
        if (res.success) {
            if (manual) P.toast(res.data.checked ? T('اتحدّث تفاعل ', 'Updated engagement for ') + res.data.updated + T(' منشور', ' posts') + (res.data.unavailable ? T(' (' + res.data.unavailable + ' غير متاح - غالبًا صلاحية read_insights ناقصة)', ' (' + res.data.unavailable + ' unavailable — usually a missing read_insights permission)') : '') : T('مفيش منشورات على فيسبوك/إنستجرام للتحديث', 'No Facebook/Instagram posts to refresh'), res.data.unavailable ? 'error' : 'success');
            loadStats(); if (loaded.posts) load();
        } else if (manual) P.toast(res.error || T('تعذر التحديث', 'Could not refresh'), 'error');
    };
    $('spDays').addEventListener('change', loadStats);

    // ================= نمو المتابعين =================
    function spark(points) {
        if (!points || points.length < 2) return '';
        const w = 220, h = 46, vals = points.map(p => p.followers), min = Math.min(...vals), max = Math.max(...vals), span = Math.max(1, max - min);
        const xy = points.map((p, i) => (i / (points.length - 1) * w).toFixed(1) + ',' + (h - 4 - (p.followers - min) / span * (h - 8)).toFixed(1)).join(' ');
        return '<svg class="sp-spark" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" aria-hidden="true"><polyline points="' + xy + '" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>';
    }
    async function loadGrowth() {
        const res = await fetchJSON('/api/social/growth?days=' + (+$('spDays').value || 30));
        const card = $('spGrowthCard');
        if (res.success && res.data.locked) {
            card.hidden = false;
            $('spGrowth').innerHTML = '<div class="p-empty">' + IC('lock') + ' ' + T('تحليلات نمو المتابعين متاحة في باقة «احترافي» وأعلى.', 'Follower growth analytics are available on the Pro plan and up.') + ' <a href="/subscription#social">' + T('شوف الباقات', 'See plans') + '</a></div>';
            
            return;
        }
        if (!res.success || !res.data.installed || !(res.data.accounts || []).length) { card.hidden = true; return; }
        card.hidden = false;
        $('spGrowth').innerHTML = res.data.accounts.map(a => {
            const ch = a.change;
            const cls = ch > 0 ? 'up' : (ch < 0 ? 'down' : '');
            return '<div class="sp-gacc"><div class="sp-gh">' + (PF[a.platform] || '•') + ' <b>' + esc(a.name || '') + '</b></div><div class="sp-gv">' + (a.current !== null ? nf(a.current) : '–') + ' <small>' + T('متابع', 'followers') + '</small></div>'
                + '<div class="sp-gd ' + cls + '">' + (ch === null ? '' : (ch > 0 ? '▲ +' : ch < 0 ? '▼ ' : '') + nf(ch) + (a.change_pct !== null ? ' (' + a.change_pct + '%)' : '')) + '</div>'
                + (a.points.length >= 2 ? spark(a.points) : '<div class="p-cell-muted sp-small">' + T('المنحنى بيظهر بعد يومين من اللقطات اليومية', 'The curve appears after two daily snapshots') + '</div>') + '</div>';
        }).join('');
    }

    // ================= المنافسين =================
    const MIX = { reels: T('ريلز', 'Reels'), photo: T('صور', 'Photos'), carousel: T('كاروسيل', 'Carousels'), other: T('أخرى', 'Other') };
    function renderCompetitors(d) {
        const list = $('cpList');
        if (!d.installed) { list.innerHTML = '<div class="alert alert-warning">' + T('شغّل ملف قاعدة البيانات 2026_10_21_000001_social_growth.sql الأول.', 'Run the 2026_10_21_000001_social_growth.sql migration first.') + '</div>'; return; }
        $('cpInsights').innerHTML = (d.insights || []).length ? '<ul class="cp-ins">' + d.insights.map(x => '<li>' + esc(AR ? x[0] : x[1]) + '</li>').join('') + '</ul>' : '';
        const me = d.me;
        const head = '<tr><th>' + T('الحساب', 'Account') + '</th><th>' + T('المتابعين', 'Followers') + '</th><th>' + T('منشور/أسبوع', 'Posts/week') + '</th><th>' + T('متوسط التفاعل', 'Avg engagement') + '</th><th>' + T('أنواع المحتوى', 'Content mix') + '</th><th></th></tr>';
        const meRow = me ? '<tr class="cp-me"><td><b>⭐ @' + esc(me.username || '') + '</b> <span class="p-cell-muted">' + T('(انت)', '(you)') + '</span></td><td>' + (me.followers !== null ? nf(me.followers) : '–') + '</td><td>' + (me.posts_per_week ?? '–') + '</td><td>' + (me.avg_engagement ?? '–') + '</td><td></td><td></td></tr>' : '';
        const rows = (d.competitors || []).map(c => {
            const mix = Object.entries(c.mix || {}).map(([k, v]) => esc(MIX[k] || k) + ' ' + v).join(' · ');
            const ch = c.followers_change_30d;
            return '<tr><td><a href="https://instagram.com/' + encodeURIComponent(c.username) + '" target="_blank" rel="noopener">@' + esc(c.username) + '</a>' + (c.display_name ? '<div class="p-cell-muted sp-small">' + esc(c.display_name) + '</div>' : '') + (c.last_error ? '<div class="sp-err">' + esc(c.last_error) + '</div>' : '') + '</td>'
                + '<td>' + (c.followers !== null ? nf(c.followers) : '–') + (ch ? ' <span class="sp-gd ' + (ch > 0 ? 'up' : 'down') + '">' + (ch > 0 ? '+' : '') + nf(ch) + '</span>' : '') + '</td><td>' + (c.posts_per_week ?? '–') + '</td><td>' + (c.avg_engagement ?? '–') + '</td><td class="sp-small">' + mix + '</td>'
                + '<td><button class="p-btn ghost xs" data-cpdel="' + c.id + '" title="' + T('شيل', 'Remove') + '">' + IC('trash') + '</button></td></tr>'
                + ((c.top || []).length ? '<tr class="cp-top"><td colspan="6">' + T('' + IC('trophy') + ' أنجح منشوراتهم: ', '' + IC('trophy') + ' Their top posts: ') + c.top.map(tp => '<span class="cp-post">' + (safeUrl(tp.permalink) ? '<a href="' + esc(safeUrl(tp.permalink)) + '" target="_blank" rel="noopener">' : '') + esc((tp.caption || '').slice(0, 70) || '—') + (safeUrl(tp.permalink) ? '</a>' : '') + ' <b>' + nf(tp.engagement) + '</b></span>').join('') + ((c.hashtags || []).length ? '<div class="p-cell-muted sp-small">#' + c.hashtags.map(esc).join(' #') + '</div>' : '') + '</td></tr>' : '');
        }).join('');
        list.innerHTML = (rows || meRow) ? '<div class="p-table-scroll"><table class="p-table">' + head + meRow + rows + '</table></div>' + (!rows ? '<p class="p-cell-muted">' + T('لسه ما ضفتش منافسين.', 'No competitors added yet.') + '</p>' : '') : '<p class="p-cell-muted">' + T('لسه ما ضفتش منافسين.', 'No competitors added yet.') + '</p>';
    }
    async function loadCompetitors() {
        const res = await fetchJSON('/api/social/competitors');
        if (res.success) renderCompetitors(res.data); else $('cpList').textContent = res.error || T('تعذر التحميل', 'Could not load');
    }
    $('cpForm').addEventListener('submit', async () => {
        const u = $('cpUser').value.trim(); if (!u) return;
        const btn = $('cpAdd'); btn.disabled = true;
        const res = await jsonPost('/api/social/competitors', { username: u });
        btn.disabled = false;
        if (res.success) { $('cpUser').value = ''; renderCompetitors(res.data); P.toast(T('اتضاف ✔', 'Added ✔'), 'success'); } else P.toast(res.error || T('تعذر', 'Failed'), 'error');
    });
    $('cpRefresh').addEventListener('click', async () => {
        const btn = $('cpRefresh'); btn.disabled = true;
        const res = await jsonPost('/api/social/competitors/refresh');
        btn.disabled = false;
        if (res.success) { renderCompetitors(res.data); P.toast(T('اتحدّث ✔', 'Refreshed ✔'), 'success'); } else P.toast(res.error || T('تعذر', 'Failed'), 'error');
    });
    $('cpList').addEventListener('click', async e => {
        const b = e.target.closest('[data-cpdel]'); if (!b) return;
        if (!confirm(T('تشيل المنافس ده؟', 'Remove this competitor?'))) return;
        const res = await jsonPost('/api/social/competitors/' + b.dataset.cpdel + '/delete');
        if (res.success) renderCompetitors(res.data);
    });

    // ================= موافقة العميل =================
    const PLAN_ST = { draft: T('مسودة', 'Draft'), active: T('شغالة', 'Active'), paused: T('متوقفة', 'Paused'), completed: T('خلصت', 'Completed'), queued: T('بتتجهز', 'Preparing'), generating: T('بتتجهز', 'Preparing'), failed: T('فشلت', 'Failed'), cancelled: T('ملغية', 'Cancelled') };
    async function loadShare() {
        const res = await fetchJSON('/api/social/monthly-plans');
        const sel = $('shPlan');
        // الخطط اللي ينفع تتبعت للعميل: مسودة جاهزة للمراجعة أو شغالة (الملغية/الفاشلة ملهاش لازمة هنا)
        const plans = ((res.success && res.data.plans) || []).filter(p => ['draft', 'active', 'paused', 'completed'].includes(p.status));
        const keep = sel.value;
        sel.innerHTML = plans.length ? plans.map(p => '<option value="' + p.id + '">' + esc(p.month_key) + ' · ' + esc(PLAN_ST[p.status] || p.status) + '</option>').join('') : '<option value="">' + T('مفيش خطة جاهزة للمراجعة - جهّز خطة الأول', 'No plan ready for review — prepare a plan first') + '</option>';
        if (keep && plans.some(p => String(p.id) === keep)) sel.value = keep;
        $('shCreate').disabled = !plans.length;
        loadShareLinks();
    }
    async function loadShareLinks() {
        const id = $('shPlan').value;
        if (!id) { $('shLinks').innerHTML = $('shFeedback').innerHTML = ''; return; }
        const res = await fetchJSON('/api/social/monthly-plans/' + id + '/share');
        if (!res.success) return;
        const d = res.data;
        if (!d.installed) { $('shLinks').innerHTML = '<div class="alert alert-warning sp-small">' + T('شغّل ملف قاعدة البيانات 2026_10_21_000001_social_growth.sql الأول.', 'Run the 2026_10_21_000001_social_growth.sql migration first.') + '</div>'; return; }
        $('shLinks').innerHTML = (d.links || []).length ? '<div class="sp-small sp-gap-s"><b>' + T('الروابط', 'Links') + '</b>' + d.links.map(l => '<div class="sp-hrow">' + (+l.active ? '🟢' : '⚪') + ' ' + esc(l.client_name || T('عميل', 'Client')) + ' · ' + (+l.can_approve ? T('مراجعة واعتماد', 'review & approve') : T('مراجعة بس', 'review only')) + ' · ' + T('لحد ', 'until ') + esc(spLocal(l.expires_at)) + (l.last_viewed_at ? ' · ' + IC('eye') + ' ' + T('اتفتح ', 'opened ') + esc(spLocal(l.last_viewed_at)) : '') + (+l.active ? ' <button class="p-btn ghost xs" data-revoke="' + l.id + '">' + T('إلغاء', 'Revoke') + '</button>' : '') + '</div>').join('') + '</div>' : '';
        const FB = { approved: '' + IC('check') + ' ' + T('اعتمد', 'Approved'), changes: '' + IC('edit') + ' ' + T('طلب تعديلات', 'Requested changes'), comment: '' + IC('chat') + ' ' + T('ملاحظة', 'Comment') };
        $('shFeedback').innerHTML = (d.feedback || []).length ? '<div class="sp-small sp-gap-s"><b>' + T('ردود العميل', 'Client responses') + '</b>' + d.feedback.map(f => '<div class="sp-hrow"><span>' + (FB[f.decision] || '') + '</span><b>' + esc(f.author_name || '') + '</b>' + (f.item_id ? '<span class="p-cell-muted">' + T('على منشور #', 'on post #') + f.item_id + '</span>' : '') + '<span>' + esc(f.message || '') + '</span><span class="p-cell-muted">' + esc(spLocal(f.created_at)) + '</span></div>').join('') + '</div>' : '';
    }
    if ($('shPlan')) $('shPlan').addEventListener('change', loadShareLinks);
    if ($('shCreate')) $('shCreate').addEventListener('click', async () => {
        const id = $('shPlan').value; if (!id) return;
        const btn = $('shCreate'); btn.disabled = true;
        const res = await jsonPost('/api/social/monthly-plans/' + id + '/share', { can_approve: $('shApprove').checked, client_name: $('shClient').value.trim(), days: +$('shDays').value });
        btn.disabled = false;
        if (!res.success) { P.toast(res.error || T('تعذر', 'Failed'), 'error'); return; }
        $('shNew').innerHTML = '<div class="alert alert-success sp-gap-s"><div class="sp-small">' + T('ابعت الرابط ده للعميل (بيظهر مرة واحدة بس - احفظه):', 'Send this link to your client (shown once — keep it):') + '</div><div class="sp-row"><input class="p-select sp-grow" readonly dir="ltr" id="shUrl" value="' + esc(res.data.url) + '"><button type="button" class="p-btn outline sm" id="shCopy">' + IC('copy') + ' ' + T('نسخ', 'Copy') + '</button><a class="p-btn outline sm" target="_blank" rel="noopener" href="' + esc(res.data.url) + '">' + T('معاينة ' + IC('external') + '', 'Preview ' + IC('external') + '') + '</a><a class="p-btn outline sm" target="_blank" rel="noopener" href="https://wa.me/?text=' + encodeURIComponent(res.data.url) + '">WhatsApp</a></div></div>';
        $('shCopy').onclick = () => { $('shUrl').select(); try { navigator.clipboard.writeText($('shUrl').value); } catch (e) { document.execCommand('copy'); } P.toast(T('اتنسخ ✔', 'Copied ✔'), 'success'); };
        loadShareLinks();
    });
    if ($('shLinks')) $('shLinks').addEventListener('click', async e => {
        const b = e.target.closest('[data-revoke]'); if (!b) return;
        if (!confirm(T('إلغاء الرابط؟ العميل مش هيقدر يفتحه تاني.', 'Revoke this link? The client will no longer be able to open it.'))) return;
        const res = await jsonPost('/api/social/monthly-plans/' + $('shPlan').value + '/share/' + b.dataset.revoke + '/revoke');
        if (res.success) loadShareLinks(); else P.toast(res.error || T('تعذر', 'Failed'), 'error');
    });

    // ================= الحسابات =================
    async function loadAccounts() {
        const box = $('spAccounts');
        const res = await fetchJSON('/api/social/connections?_=' + Date.now(), { cache: 'no-store' });
        if (!res.success) { box.textContent = res.error || T('تعذر التحميل', 'Could not load'); return; }
        const rank = c => (c.active === false ? 2 : c.locked ? 1 : 0);
        const on = (res.data.connections || []).slice().sort((a, b) => rank(a) - rank(b)), off = res.data.disconnected || [];
        const st = c => c.active === false ? ['off', T('موقوفة', 'Paused')] : c.locked === 'platform' ? ['lock', T('مش ضمن باقتك', 'Not in your plan')] : c.locked === 'accounts' ? ['lock', T('فوق حد الباقة', 'Over plan limit')] : ['ok', T('شغالة', 'Active')];
        const card = c => {
            const s2 = st(c);
            return '<div class="sp-acc ' + s2[0] + '">' + av(c, 52)
                + '<div class="sp-acc-tx"><b>' + esc(c.name || PN[c.platform] || c.platform) + '</b><small>' + esc(PN[c.platform] || c.platform) + (c.username ? ' · @' + esc(c.username) : '') + '</small>'
                + '<div class="sp-acc-meta">' + (c.followers !== null && c.followers !== undefined ? '<span>' + IC('users', 13) + ' ' + fmtK(c.followers) + T(' متابع', ' followers') + '</span>' : '') + '<span class="sp-acc-st ' + s2[0] + '">' + esc(s2[1]) + '</span></div></div>'
                + (can('manage') ? '<label class="sp-switch" title="' + esc(T('مستخدمة في النشر', 'Used for publishing')) + '"><input type="checkbox" data-acc="' + c.id + '"' + (c.active !== false ? ' checked' : '') + '><span></span></label>' : '') + '</div>';
        };
        box.innerHTML = (on.length ? on.map(card).join('') : '<div class="p-empty">' + IC('plug', 22) + '<div>' + T('مفيش حسابات متصلة لسه. اربط حساباتك من تحت.', 'No connected accounts yet. Connect below.') + '</div></div>')
            + off.map(c => '<div class="sp-acc bad" title="' + esc(c.last_error || '') + '">' + av(c, 52) + '<div class="sp-acc-tx"><b>' + esc(c.name || c.platform) + '</b><small>' + esc(PN[c.platform] || c.platform) + '</small><div class="sp-acc-meta"><span class="sp-acc-st bad">' + T('محتاج إعادة ربط', 'Needs reconnecting') + '</span></div></div><a class="p-btn outline xs" href="' + ({ facebook: '/ads/connect/meta?for=social', instagram: '/ads/connect/meta?for=social', google_business: '/reputation/platforms' }[c.platform] || '/social/connect/' + esc(c.platform)) + '">' + IC('refresh', 13) + ' ' + T('اربط تاني', 'Reconnect') + '</a></div>').join('');
        const activeN = on.filter(c => c.active !== false && c.locked !== 'platform').length;
        const lim = PKG && PKG.limits ? PKG.limits.accounts : null;
        $('spSlots').innerHTML = lim === null || lim === undefined ? '' : (T('مستخدم ', 'Using ') + '<b>' + nf(Math.min(activeN, lim < 0 ? activeN : lim)) + '</b>' + T(' من ', ' of ') + '<b>' + (lim < 0 ? '∞' : nf(lim)) + '</b>' + T(' حساب في باقتك', ' accounts in your plan'));
        box.querySelectorAll('[data-acc]').forEach(cb => cb.addEventListener('change', async () => {
            cb.disabled = true;
            const r = await jsonPost('/api/social/connections/' + cb.dataset.acc + '/active', { active: cb.checked });
            cb.disabled = false;
            if (!r.success) { cb.checked = !cb.checked; P.toast(r.error || T('تعذر', 'Failed'), 'error'); return; }
            P.toast(r.message || T('تم', 'Done'), 'success');
            fetchPackage(); loadAccounts();
        }));
        // كروت الربط: المنصات اللي مش في الباقة عليها علامة
        document.querySelectorAll('.sp-conn[data-plats]').forEach(a => {
            const ok = !PKG || !PKG.platforms || a.dataset.plats.split(',').some(p => PKG.platforms.includes(p));
            a.classList.toggle('locked', !ok);
            if (!ok && !a.querySelector('.sp-conn-lock')) a.insertAdjacentHTML('beforeend', '<i class="sp-conn-lock">' + IC('lock', 12) + ' ' + T('مش في باقتك', 'Not in your plan') + '</i>');
        });
    }

    // ================= صندوق التعليقات =================
    const INTENT = {
        price: ['' + IC('coins') + '', T('سعر', 'Price'), 'hot'], booking: ['' + IC('calendar') + '', T('حجز', 'Booking'), 'hot'], question: ['' + IC('info') + '', T('سؤال', 'Question'), 'warm'],
        complaint: ['' + IC('alert') + '', T('شكوى', 'Complaint'), 'bad'], praise: ['' + IC('heart') + '', T('مدح', 'Praise'), 'ok'], spam: ['' + IC('x') + '', T('سبام', 'Spam'), 'mute'], other: ['' + IC('chat') + '', T('تعليق', 'Comment'), '']
    };
    async function loadInbox() {
        const list = $('ibList');
        const qs = new URLSearchParams({ status: $('ibStatus').value, intent: $('ibIntent').value, page: $('ibPage').value });
        const res = await fetchJSON('/api/social/inbox?' + qs);
        if (!res.success) { list.innerHTML = '<div class="alert alert-danger">' + esc(res.error || T('تعذر التحميل', 'Could not load')) + '</div>'; return; }
        const d = res.data;
        paintBadge(d.counts);
        if (d.pages) { IB.pages = d.pages; paintInboxPages(); }
        if (!d.installed) {
            list.innerHTML = '<div class="alert alert-warning">' + T('صندوق التعليقات محتاج تشغيل ملف قاعدة البيانات database/migrations/2026_10_20_000001_social_pro.sql مرة واحدة.', 'The comments inbox needs database/migrations/2026_10_20_000001_social_pro.sql to be run once.') + '</div>';
            return;
        }
        $('ibCounts').textContent = T('محتاجة رد: ', 'Need reply: ') + nf(d.counts.open) + (d.counts.hot ? T(' · منها عملاء محتملين/أسئلة: ', ' · leads/questions: ') + nf(d.counts.hot) : '');
        if (!d.comments.length) {
            list.innerHTML = '<div class="p-empty">' + ($('ibStatus').value === 'open' ? T('مفيش تعليقات محتاجة رد 🎉 اضغط «اسحب التعليقات الجديدة» للتأكد.', 'No comments waiting 🎉 Press "Fetch new comments" to check.') : T('مفيش تعليقات هنا.', 'No comments here.')) + '</div>';
            return;
        }
        list.innerHTML = d.comments.map(c => {
            const it = INTENT[c.intent] || INTENT.other;
            const done = c.status !== 'new';
            return '<article class="ib-item ' + (done ? 'done' : '') + '" data-id="' + c.id + '">'
                + '<div class="ib-head"><span class="ib-tag ' + it[2] + '">' + it[0] + ' ' + it[1] + '</span><b>' + esc(c.author_name || T('مستخدم', 'User')) + '</b><span class="p-cell-muted ib-on">' + (pageOf(c.page_id) ? av(pageOf(c.page_id), 20) : (PF[c.platform] || '')) + ' ' + esc(c.page_name || '') + ' · ' + esc(c.commented_at ? spLocal(c.commented_at) : '') + '</span>' + (c.like_count ? '<span class="p-cell-muted">' + IC('thumb') + ' ' + nf(c.like_count) + '</span>' : '') + '</div>'
                + '<div class="ib-msg">' + esc(c.message) + '</div>'
                + (c.post_content ? '<div class="ib-post p-cell-muted">↳ ' + esc(c.post_content) + (safeUrl(c.permalink) ? ' <a href="' + esc(safeUrl(c.permalink)) + '" target="_blank" rel="noopener">' + T('افتح المنشور ' + IC('external') + '', 'Open post ' + IC('external') + '') + '</a>' : '') + '</div>' : '')
                + (c.private_replied_at ? '<div class="ib-reply-done">' + IC('mail') + ' ' + (+c.auto_handled ? T('اتبعتله رسالة خاصة تلقائيًا: ', 'Auto private message sent: ') : T('اتبعتله رسالة خاصة: ', 'Private message sent: ')) + esc((c.private_reply_text || '').slice(0, 160)) + '</div>' : '')
                + (c.status === 'replied' && c.reply_text ? '<div class="ib-reply-done">✔ ' + T('ردّك: ', 'Your reply: ') + esc(c.reply_text) + '</div>' : '')
                + (c.status === 'new' && can('inbox') ? '<div class="ib-compose"><textarea class="p-select" rows="2" maxlength="2000" placeholder="' + T('اكتب ردك…', 'Write your reply…') + '">' + esc(c.ai_suggestion || '') + '</textarea><div class="sp-row">'
                    + '<button type="button" class="p-btn outline xs" data-ib="suggest">' + IC('sparkles') + ' ' + T('اقترح رد', 'Suggest reply') + '</button>'
                    + '<button type="button" class="p-btn primary xs" data-ib="reply">' + T('إرسال الرد', 'Send reply') + '</button>'
                    + (!c.private_replied_at && ['facebook', 'instagram'].includes(c.platform) ? '<button type="button" class="p-btn outline xs" data-ib="private" title="' + T('رسالة خاصة (ماسنجر/دايركت) بالنص اللي فوق، أو بقالب الرد الخاص لو فاضي', 'Private message (Messenger/Direct) with the text above, or your private template if empty') + '">' + IC('mail') + ' ' + T('رد في الخاص', 'Reply privately') + '</button>' : '')
                    + '<button type="button" class="p-btn ghost xs" data-ib="hide" title="' + T('إخفاء التعليق من الجمهور (سبام/إساءة)', 'Hide from the public (spam/abuse)') + '">' + IC('eyeOff') + ' ' + T('إخفاء', 'Hide') + '</button>'
                    + '<button type="button" class="p-btn ghost xs" data-ib="done" title="' + T('اتعامل معاه (مثلًا رديت في الخاص)', 'Handled (e.g. answered privately)') + '">✓ ' + T('تم', 'Done') + '</button></div></div>' : '')
                + '</article>';
        }).join('');
    }
    // ---- الصفحات اللي الصندوق شغال عليها (بيسحب تعليقاتها ويرد عليها)
    const IB = { pages: [], auto: false };
    const pageOf = id => IB.pages.find(p => +p.id === +id);
    const PSTATE = {
        reconnect: [T('محتاجة إعادة ربط', 'Needs reconnecting'), 'bad'],
        paused: [T('موقوفة من «الحسابات»', 'Paused in Accounts'), 'mute'],
        locked: [T('فوق حد باقتك', 'Over your plan limit'), 'warm']
    };
    const pgName = p => p.name || p.username || PN[p.platform];
    function paintInboxPages() {
        const box = $('ibPages'), sel = $('ibPage');
        const pages = IB.pages;
        box.hidden = false;
        if (!pages.length) {
            box.innerHTML = '<div class="alert alert-warning sp-small">' + IC('alert') + ' ' + T('مفيش صفحة فيسبوك أو حساب إنستجرام مربوط، فالصندوق مش بيسحب تعليقات من أي مكان.', 'No Facebook page or Instagram account is connected, so the inbox has nowhere to fetch comments from.') + ' <a href="#accounts">' + T('اربط صفحاتك', 'Connect your pages') + '</a></div>';
            sel.hidden = true;
            return;
        }
        const live = pages.filter(p => !p.state);
        const autoN = live.filter(p => p.auto_reply).length;
        box.innerHTML = '<div class="ib-pages-h"><b>' + IC('chat') + ' ' + T('الصفحات اللي الصندوق شغال عليها', 'Pages the inbox works on') + '</b> <span class="p-cell-muted sp-small">'
            + nf(live.length) + T(' من ', ' of ') + nf(pages.length) + T(' بتتسحب تعليقاتها', ' are being read')
            + (IB.auto ? ' · ' + IC('bot', 13) + ' ' + T('الرد التلقائي شغال على ', 'Auto-reply on ') + nf(autoN) : '') + '</span></div>'
            + '<div class="ib-pg-list">' + pages.map(p => {
                const st = p.state && PSTATE[p.state];
                const on = $('ibPage').value === String(p.id);
                const tip = st ? st[0] : (p.last_error ? p.last_error : (p.last_sync_at ? T('آخر سحب: ', 'Last fetched: ') + spLocal(p.last_sync_at) : T('لسه ما اتسحبتش - اضغط «اسحب التعليقات الجديدة»', 'Not fetched yet - press "Fetch new comments"')));
                const badge = st ? '<span class="ib-st ' + st[1] + '">' + st[0] + '</span>'
                    : p.last_error ? '<span class="ib-st bad">' + IC('alert', 12) + ' ' + T('خطأ في السحب', 'Fetch error') + '</span>'
                    : p.open ? '<span class="ib-st hot">' + nf(p.open) + ' ' + T('محتاجة رد', 'need reply') + '</span>'
                    : '<span class="ib-st ok">' + IC('check', 12) + ' ' + T('شغالة', 'Active') + '</span>';
                const bot = !st && IB.auto && p.auto_reply ? ' <span class="ib-st bot" title="' + T('الرد الخاص التلقائي شغال على الصفحة دي', 'Automatic private reply is on for this page') + '">' + IC('bot', 12) + ' ' + T('رد تلقائي', 'Auto') + '</span>' : '';
                return '<button type="button" class="ib-pg' + (on ? ' on' : '') + (st ? ' off' : '') + '" data-pg="' + p.id + '" title="' + esc(tip) + '" aria-pressed="' + on + '">'
                    + av(p, 30) + '<span class="ib-pg-t"><b>' + esc(pgName(p)) + '</b><small>' + badge + bot + '</small></span></button>';
            }).join('') + '</div>'
            + '<div class="p-cell-muted sp-small">' + T('بيسحب تعليقات آخر 30 يوم من المنشورات اللي اتنشرت من هنا ومن آخر منشورات كل صفحة على فيسبوك/إنستجرام نفسه. اضغط على صفحة عشان تشوف تعليقاتها بس.', 'Fetches the last 30 days of comments from posts published here and from each page\'s latest posts on Facebook/Instagram. Click a page to see only its comments.')
            + (pages.some(p => p.state) ? ' ' + T('الصفحات الباهتة مش بيتسحب منها ولا بيترد عليها: ', 'Faded pages are not read or replied on: ') + '<a href="#accounts">' + T('إدارة الصفحات', 'Manage pages') + '</a>' : '') + '</div>';
        const cur = sel.value;
        sel.innerHTML = '<option value="">' + T('كل الصفحات', 'All pages') + '</option>' + pages.map(p => '<option value="' + p.id + '">' + esc((p.platform === 'instagram' ? 'IG · ' : 'FB · ') + pgName(p)) + (p.open ? ' (' + nf(p.open) + ')' : '') + '</option>').join('');
        sel.value = pages.some(p => String(p.id) === cur) ? cur : '';
        sel.hidden = pages.length < 2;
    }
    $('ibPages').addEventListener('click', e => {
        const b = e.target.closest('[data-pg]'); if (!b) return;
        const p = pageOf(b.dataset.pg);
        if (p && p.state) { location.hash = '#accounts'; return; }
        $('ibPage').value = $('ibPage').value === b.dataset.pg ? '' : b.dataset.pg;
        loadInbox();
    });
    $('ibPage').addEventListener('change', loadInbox);
    function paintAutoPages() {
        const pages = IB.pages;
        $('ibAutoPagesWrap').hidden = !pages.length;
        $('ibAutoPages').innerHTML = pages.map(p => {
            const st = p.state && PSTATE[p.state];
            return '<label class="ib-ap-it' + (st ? ' off' : '') + '"><input type="checkbox" value="' + p.id + '"' + (p.auto_reply && !st ? ' checked' : '') + (st ? ' disabled' : '') + '>'
                + av(p, 26) + '<span><b>' + esc(pgName(p)) + '</b><small class="p-cell-muted">' + (st ? st[0] : PN[p.platform]) + '</small></span></label>';
        }).join('');
    }
    document.querySelectorAll('[data-ap-all]').forEach(b => b.addEventListener('click', () => {
        document.querySelectorAll('#ibAutoPages input:not(:disabled)').forEach(cb => { cb.checked = b.dataset.apAll === '1'; });
    }));
    function paintBadge(counts) {
        const b = $('spInboxBadge');
        const n = counts ? +counts.open : 0;
        b.hidden = !n; b.textContent = n > 99 ? '99+' : String(n);
        b.classList.toggle('hot', !!(counts && counts.hot));
    }
    $('ibList').addEventListener('click', async e => {
        const btn = e.target.closest('[data-ib]'); if (!btn) return;
        const item = btn.closest('.ib-item'); const id = item.dataset.id; const ta = item.querySelector('textarea');
        const act = btn.dataset.ib;
        btn.disabled = true;
        let res;
        if (act === 'suggest') {
            const lbl = btn.textContent; btn.textContent = T('بيفكر…', 'Thinking…');
            res = await jsonPost('/api/social/inbox/' + id + '/suggest');
            btn.textContent = lbl; btn.disabled = false;
            if (res.success) { ta.value = res.data.reply; ta.focus(); } else P.toast(res.error || T('تعذر', 'Failed'), 'error');
            return;
        }
        if (act === 'private') {
            if (!confirm(T('هيتبعت رسالة خاصة واحدة لصاحب التعليق' + (ta.value.trim() ? ' بالنص المكتوب' : ' بقالب الرد الخاص') + '، ومعاها رد علني قصير إنك بعتله. نكمّل؟', 'Send one private message to the commenter' + (ta.value.trim() ? ' with the text above' : ' using your private template') + ', plus a short public reply saying so. Continue?'))) { btn.disabled = false; return; }
            res = await jsonPost('/api/social/inbox/' + id + '/private', { text: ta.value.trim() || null, ack: true });
            btn.disabled = false;
            if (res.success) { P.toast(T('اتبعت الرد الخاص ✔', 'Private reply sent ✔'), 'success'); loadInbox(); } else P.toast(res.error || T('تعذر', 'Failed'), 'error');
            return;
        }
        if (act === 'reply') {
            if (!ta.value.trim()) { btn.disabled = false; P.toast(T('اكتب الرد الأول', 'Write a reply first'), 'error'); return; }
            res = await jsonPost('/api/social/inbox/' + id + '/reply', { text: ta.value.trim() });
        } else if (act === 'hide') {
            if (!confirm(T('إخفاء التعليق؟ هيفضل ظاهر لصاحبه بس.', 'Hide this comment? Only its author will still see it.'))) { btn.disabled = false; return; }
            res = await jsonPost('/api/social/inbox/' + id + '/hide');
        } else {
            res = await jsonPost('/api/social/inbox/' + id + '/done');
        }
        btn.disabled = false;
        if (res.success) { P.toast(act === 'reply' ? T('اتبعت الرد ✔', 'Reply sent ✔') : act === 'hide' ? T('اتخفى', 'Hidden') : T('اتقفل', 'Closed'), 'success'); loadInbox(); }
        else P.toast(res.error || T('تعذر', 'Failed'), 'error');
    });
    ['ibStatus', 'ibIntent'].forEach(id => $(id).addEventListener('change', loadInbox));
    // ---- إعدادات الرد الخاص التلقائي
    async function loadInboxSettings() {
        const res = await fetchJSON('/api/social/inbox-settings');
        if (!res.success) return;
        const d = res.data;
        $('ibAuto').checked = !!d.auto_private;
        document.querySelectorAll('#ibIntents input').forEach(cb => { cb.checked = (d.auto_intents || []).includes(cb.value); });
        $('ibTemplate').value = d.private_template || '';
        $('ibAck').value = d.public_ack || '';
        IB.auto = !!d.auto_private && d.allowed !== false;
        if (d.pages) IB.pages = d.pages;
        paintAutoPages();
        paintInboxPages();
        const st = $('ibAutoState');
        const autoN = IB.pages.filter(p => !p.state && p.auto_reply).length;
        st.textContent = IB.auto ? T('مفعّل على ', 'On for ') + nf(autoN) + T(' صفحة', autoN === 1 ? ' page' : ' pages') : d.auto_private ? T('مش في باقتك', 'Not in your plan') : T('مقفول', 'Off');
        st.classList.toggle('hot', IB.auto);
        if (!d.installed) $('ibSaveSettings').disabled = true;
    }
    $('ibSaveSettings').addEventListener('click', async () => {
        const btn = $('ibSaveSettings'); btn.disabled = true;
        const res = await jsonPost('/api/social/inbox-settings', { auto_private: $('ibAuto').checked, auto_intents: [...document.querySelectorAll('#ibIntents input:checked')].map(cb => cb.value), private_template: $('ibTemplate').value, public_ack: $('ibAck').value, auto_pages: IB.pages.length ? [...document.querySelectorAll('#ibAutoPages input:checked')].map(cb => +cb.value) : undefined });
        btn.disabled = false;
        if (res.success) { P.toast(T('اتحفظت الإعدادات ✔', 'Settings saved ✔'), 'success'); loadInboxSettings(); } else P.toast(res.error || T('تعذر الحفظ', 'Could not save'), 'error');
    });
    $('ibSync').addEventListener('click', async () => {
        const btn = $('ibSync'); const lbl = btn.textContent; btn.disabled = true; btn.textContent = T('بيسحب من Meta…', 'Fetching from Meta…');
        const res = await jsonPost('/api/social/inbox/sync');
        btn.disabled = false; btn.textContent = lbl;
        const note = $('ibNotice');
        if (!res.success) { P.toast(res.error || T('تعذر', 'Failed'), 'error'); return; }
        const r = res.data;
        note.innerHTML = r.needs_permission
            ? '<div class="alert alert-warning">' + T('Meta رفضت قراءة التعليقات: الصلاحيات ناقصة. شوف تبويب «الحسابات» ← «ليه صندوق التعليقات مش شغال؟».', 'Meta refused to read comments: missing permissions. See Accounts → "Why don\'t the comments inbox… work?".') + (Object.values(r.errors || {})[0] ? '<div class="p-cell-muted sp-small">' + esc(Object.values(r.errors)[0]) + '</div>' : '') + '</div>'
            : (Object.keys(r.errors || {}).length ? '<div class="alert alert-warning sp-small">' + esc(Object.values(r.errors).join(' | ')) + '</div>' : '');
        P.toast(T('اتفحص ', 'Checked ') + nf(r.checked) + T(' منشور على ', ' posts on ') + nf(r.pages || 0) + T(' صفحة · ', ' pages · ') + nf(r.new) + T(' تعليق جديد', ' new comments') + (r.auto_private ? T(' · اتبعت ', ' · ') + nf(r.auto_private) + T(' رد خاص تلقائي', ' auto private replies') : ''), 'success');
        loadInbox();
    });

    // ================= إعدادات تيك توك عند اعتماد الخطة الشهرية =================
    // تيك توك بيشترط إن صاحب الحساب يختار الخصوصية وإعدادات التفاعل بنفسه ويوافق على سياسة الموسيقى قبل النشر.
    window.TFSocialTikTokOptions = function (connectionId) {
        return new Promise(async resolve => {
            const res = await fetchJSON('/api/social/tiktok/creator-info?connection_id=' + encodeURIComponent(connectionId));
            if (!res.success) { P.toast(res.error || T('تعذر الوصول لتيك توك - اربطه تاني', 'Could not reach TikTok — reconnect it'), 'error'); resolve(null); return; }
            const c = res.data;
            const ov = document.createElement('div');
            ov.className = 'p-modal-overlay open';
            const box = (k, label, off) => '<label class="sp-radio' + (off ? ' off' : '') + '"><input type="checkbox" data-k="' + k + '"' + (off ? ' disabled' : ' checked') + '> ' + label + '</label>';
            ov.innerHTML = '<div class="p-modal" role="dialog" aria-modal="true"><div class="p-modal-head"><h3>' + BR('tiktok', 18) + ' ' + T('إعدادات النشر على تيك توك', 'TikTok posting settings') + '</h3></div><div class="p-modal-body">'
                + '<p class="sp-small">' + T('هتتطبق على كل فيديوهات تيك توك في الخطة دي، على حساب: ', 'Applies to every TikTok video in this plan, on account: ') + '<b>' + esc(c.nickname || '') + '</b></p>'
                + '<label class="sp-field">' + T('مين يقدر يشوف الفيديوهات؟', 'Who can view the videos?') + '<select class="p-select" data-k="privacy"><option value="">' + T('اختار…', 'Select…') + '</option>' + (c.privacy_level_options || []).map(o => '<option value="' + esc(o) + '">' + esc(TT_PRIV[o] || o) + '</option>').join('') + '</select></label>'
                + '<div class="sp-row sp-gap-s">' + box('comment', T('السماح بالتعليقات', 'Allow comments'), c.comment_disabled) + box('duet', 'Duet', c.duet_disabled) + box('stitch', 'Stitch', c.stitch_disabled) + '</div>'
                + '<label class="sp-check"><input type="checkbox" data-k="brand_organic"> ' + T('الفيديوهات بتروّج لنشاطك (Promotional content)', 'Videos promote your own business (Promotional content)') + '</label>'
                + '<p class="p-cell-muted sp-small">' + T('بالاعتماد انت موافق على ', 'By approving, you agree to TikTok\'s ') + '<a href="https://www.tiktok.com/legal/page/global/music-usage-confirmation/en" target="_blank" rel="noopener">Music Usage Confirmation</a>' + T(' الخاصة بتيك توك.', '.') + '</p>'
                + '</div><div class="p-modal-foot"><button type="button" class="p-btn outline" data-x="cancel">' + T('إلغاء', 'Cancel') + '</button><button type="button" class="p-btn primary" data-x="ok">' + T('اعتماد', 'Approve') + '</button></div></div>';
            document.body.appendChild(ov);
            const q = k => ov.querySelector('[data-k="' + k + '"]');
            ov.addEventListener('click', e => {
                const x = e.target.closest('[data-x]'); if (!x) return;
                if (x.dataset.x === 'cancel') { ov.remove(); resolve(null); return; }
                if (!q('privacy').value) { P.toast(T('اختار مين يقدر يشوف الفيديوهات', 'Choose who can view the videos'), 'error'); return; }
                const org = q('brand_organic').checked;
                ov.remove();
                resolve({ privacy_level: q('privacy').value, allow_comment: q('comment').checked, allow_duet: q('duet').checked, allow_stitch: q('stitch').checked, commercial: org, brand_organic: org, brand_content: false });
            });
        });
    };

    // ================= إخفاء اللي مش مسموح للدور =================
    if (!can('draft')) document.querySelectorAll('.sp-hero-actions .p-btn:not(#spReportBtn)').forEach(b => { b.hidden = true; });
    if (!can('manage')) {
        ['ibSettingsBox', 'shareCard', 'cpForm'].forEach(id => { const el = $(id); if (el) el.hidden = true; });
        document.querySelectorAll('.sp-connect, #cpRefresh').forEach(el => { el.hidden = true; });
    }
    if (!can('inbox')) $('ibSync').hidden = true;

    // ================= الباقة =================
    const LIM_ICON = { accounts: '' + IC('link') + '', posts: '' + IC('calendar') + '', monthly_plans: '' + IC('planCheck') + '', competitors: '' + IC('target') + '', ai_captions: '' + IC('sparkles') + '' };
    let PKG = null, pkCycle = 'monthly';
    const lab = k => (PKG && PKG.labels && PKG.labels[k]) ? (AR ? PKG.labels[k][0] : PKG.labels[k][1]) : k;
    const limTxt = v => (v < 0 ? '∞' : nf(v));
    const reqKey = () => 'pk' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
    async function fetchPackage() {
        const r = await fetchJSON('/api/social/package');
        if (r.success) { PKG = r.data; paintChip(); applyPkgGates(); }
        return r;
    }
    function paintChip() {
        const chip = $('spPkgChip'); if (!chip || !PKG) return;
        const lim = PKG.limits.posts, used = PKG.usage.posts;
        chip.innerHTML = '' + IC('gem') + ' <b>' + esc(AR ? PKG.tier_name : (PKG.tier_name_en || PKG.tier_name)) + '</b> <span>' + nf(used) + '/' + limTxt(lim) + ' ' + T('منشور', 'posts') + '</span>';
        chip.classList.toggle('warn', lim >= 0 && used >= lim * 0.8);
        chip.hidden = !PKG.enforced;
        chip.onclick = () => showTab('package');
    }
    function applyPkgGates() {
        const f = PKG.features || {};
        const track = $('trackOn');
        if (track && !f.attribution) { track.checked = false; track.disabled = true; track.parentElement.title = T('متاحة من باقة «احترافي»', 'Available from the Pro plan'); track.parentElement.classList.add('locked'); }
        const auto = $('ibAuto');
        if (auto && !f.auto_private) { auto.disabled = true; auto.parentElement && (auto.parentElement.title = T('الرد الخاص التلقائي متاح من باقة «احترافي»', 'Auto private reply is available from the Pro plan')); }
    }
    function meter(k) {
        const lim = PKG.limits[k], used = PKG.usage[k] || 0;
        const pct = lim > 0 ? Math.min(100, Math.round(used * 100 / lim)) : 0;
        const cls = lim === 0 ? 'off' : (pct >= 100 ? 'full' : (pct >= 80 ? 'warn' : ''));
        return '<div class="pk-meter ' + cls + '"><div class="pk-m-h"><span>' + LIM_ICON[k] + ' ' + esc(lab(k)) + '</span><b>' + (lim === 0 ? T('مش مشمول', 'Not included') : nf(used) + ' / ' + limTxt(lim)) + '</b></div><div class="pk-bar"><i style="width:' + (lim < 0 ? 4 : pct) + '%"></i></div></div>';
    }
    // تبويب «الباقة» هنا ملخص بس: الشراء والترقية والإضافات من صفحة الاشتراكات (مع باقي باقات المنصة)
    async function loadPackage() {
        const root = $('pkRoot');
        const r = await fetchPackage();
        if (!r.success) { root.innerHTML = '<div class="alert alert-danger">' + esc(r.error || T('تعذر التحميل', 'Could not load')) + '</div>'; return; }
        const src = { plan: T('من باقة المنصة', 'from your platform plan'), social: T('اشتراك سوشيال', 'social subscription'), free: T('الباقة المجانية', 'free tier') }[PKG.source] || '';
        const sub = PKG.subscription && PKG.subscription.status === 'active' ? PKG.subscription : null;
        const lockedN = Object.keys(PKG.locked || {}).filter(k => PKG.locked[k] !== 'inactive').length;
        const feats = Object.keys(PKG.features || {});
        root.innerHTML = '<div class="p-card pk-head"><div><div class="p-cell-muted sp-small">' + T('باقتك في السوشيال', 'Your social plan') + '</div><h3 class="pk-tier">' + IC('gem') + ' ' + esc(AR ? PKG.tier_name : (PKG.tier_name_en || PKG.tier_name)) + ' <small>· ' + esc(src) + '</small></h3>'
            + (sub ? '<div class="sp-small">' + (sub.auto_renew ? T('بتتجدد تلقائي يوم ', 'Renews on ') : T('شغالة لحد ', 'Active until ')) + '<b>' + esc(spLocal(sub.period_end + ' UTC')) + '</b></div>' : '')
            + '<div class="pk-feat-row">' + feats.map(k => '<span class="pk-fchip' + (PKG.features[k] ? '' : ' no') + '">' + (PKG.features[k] ? IC('check', 13) : IC('lock', 13)) + ' ' + esc(lab(k)) + '</span>').join('') + '</div>'
            + '<div class="sp-row sp-gap-s"><a class="p-btn primary sm" href="/subscription#social">' + IC('gem', 14) + ' ' + (PKG.can_buy ? T('رقّي أو غيّر باقتك', 'Upgrade or change plan') : T('شوف الباقات', 'See plans')) + '</a><a class="p-btn ghost sm" href="/subscription#wallet">' + IC('wallet', 14) + ' ' + T('المحفظة', 'Wallet') + '</a></div></div>'
            + '<div class="pk-meters">' + ['posts', 'accounts', 'ai_captions', 'monthly_plans', 'competitors'].map(meter).join('') + '</div></div>'
            + (lockedN ? '<div class="alert alert-warning sp-small">' + IC('lock') + ' ' + nf(lockedN) + T(' حساب مقفول للنشر بسبب حدود الباقة. ', ' account(s) locked by plan limits. ') + '<a href="/subscription#social">' + T('زوّد الحسابات', 'Add accounts') + '</a></div>' : '');
    }
    // ================= ابدأ من هنا (خط سير العميل الجديد) =================
    async function loadStart() {
        const box = $('spStart'); if (!box) return;
        const r = await fetchJSON('/api/social/onboarding');
        if (!r.success) return;
        const d = r.data;
        let hidden = false; try { hidden = localStorage.getItem('tf_social_start_hidden') === '1'; } catch (e) {}
        if (d.done >= d.total || (hidden && d.done >= 3)) { box.hidden = true; return; }
        const pct = Math.round(d.done * 100 / d.total);
        box.hidden = false;
        box.innerHTML = '<div class="sp-start-head"><div><h3>' + IC('sparkles', 18) + ' ' + T('ابدأ من هنا', 'Start here') + '</h3><p class="p-cell-muted">' + T('خطوات بسيطة بالترتيب عشان السوشيال يشتغل لوحده ويجيبلك حجوزات.', 'A few simple steps, in order, so social runs itself and brings bookings.') + '</p></div>'
            + '<div class="sp-start-prog"><b>' + nf(d.done) + ' / ' + nf(d.total) + '</b><div class="pk-bar"><i style="width:' + pct + '%"></i></div></div>'
            + (d.done >= 3 ? '<button type="button" class="p-btn ghost xs" id="spStartHide" aria-label="' + esc(T('إخفاء', 'Hide')) + '">' + IC('x', 14) + '</button>' : '') + '</div>'
            + '<ol class="sp-steps">' + d.steps.map((st, i) => {
                const next = st.key === d.next;
                const cta = st.action === 'new_post' ? '<button type="button" class="p-btn ' + (next ? 'primary' : 'outline') + ' sm" data-start-post>' + esc(st.cta) + '</button>' : '<a class="p-btn ' + (next ? 'primary' : 'outline') + ' sm" href="' + esc(st.href) + '">' + esc(st.cta) + '</a>';
                return '<li class="sp-step-it' + (st.done ? ' done' : '') + (next ? ' next' : '') + '"><span class="sp-step-n">' + (st.done ? IC('check', 14) : nf(i + 1)) + '</span><div class="sp-step-tx"><b>' + IC(st.icon, 15) + ' ' + esc(st.title) + (next ? ' <span class="sp-next">' + T('الخطوة الجاية', 'Next step') + '</span>' : '') + '</b><small>' + esc(st.text) + (st.needs && !st.done ? ' <em>(' + esc(st.needs) + ')</em>' : '') + '</small></div>' + (st.done ? '<span class="sp-step-ok">' + T('تمت', 'Done') + '</span>' : cta) + '</li>';
            }).join('') + '</ol>';
        box.querySelectorAll('[data-start-post]').forEach(b => b.addEventListener('click', () => openNewPost()));
        const h = $('spStartHide'); if (h) h.addEventListener('click', () => { try { localStorage.setItem('tf_social_start_hidden', '1'); } catch (e) {} box.hidden = true; });
    }

    // ================= البداية =================
    loadStats();
    loadBest();
    fetchPackage();
    loadStart();
    fetchJSON('/api/social/inbox?status=open').then(r => { if (r.success) paintBadge(r.data.counts); });
    const startTab = (location.hash || '').slice(1);
    showTab(startTab || 'overview');
    const qp = new URLSearchParams(location.search);
    if (qp.get('meta') === 'connected') { showTab('accounts'); P.toast(T('تم ربط الحسابات ✓', 'Accounts connected ✓'), 'success'); }
    if (qp.get('new') === 'story' || qp.get('new') === 'post') openNewPost(qp.get('new') === 'story' ? 'story' : 'feed');
    // فتح منشور جاهز من «رحلات الموسم» (ذكاء الإيرادات): النص والهاشتاجات وأول تعليق والصورة والرابط
    if (/^\d+$/.test(qp.get('season') || '')) {
        fetchJSON('/api/revenue-intelligence/season/posts/' + qp.get('season')).then(r => {
            if (!r.success) { P.toast(r.error || T('المنشور مش موجود', 'Post not found'), 'error'); return; }
            const d = r.data.post;
            openNewPost('feed');
            seasonDraftId = d.id;
            $('contentInput').value = d.content || '';
            $('hashtagsInput').value = (d.hashtags || []).map(h => '#' + h).join(' ');
            $('firstCommentInput').value = d.first_comment || '';
            $('utmCampaign').value = 'season-' + new Date().getFullYear() + '-' + String(new Date().getMonth() + 1).padStart(2, '0');
            if ($('trackOn') && !$('trackOn').disabled) $('trackOn').checked = true;
            if (d.media && d.media.url) setMedia({ id: d.media.id, file_path: d.media.url, is_video: false });
            refreshComposer();
            P.toast(T('المنشور جاهز - اختار الصفحات والميعاد وانشر', 'Post loaded — pick pages and time, then publish'), 'success');
            history.replaceState(null, '', location.pathname + location.hash);
        });
    }
})();
