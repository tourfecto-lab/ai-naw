/* Historical Meta posts: read-only authenticated preview, not a database import. */
(() => {
  'use strict';
  const select = document.getElementById('metaHistoryAccount');
  const button = document.getElementById('metaHistoryFetch');
  const results = document.getElementById('metaHistoryResults');
  const notice = document.getElementById('metaHistoryNotice');
  const importButton = document.getElementById('metaHistoryImport');
  const savedButton = document.getElementById('metaHistorySaved');
  if (!select || !button || !results || !notice) return;
  const ar = (document.documentElement.lang || '').startsWith('ar');
  const tr = (a, e) => ar ? a : e;
  const api = async (url) => {
    const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: {'Accept':'application/json'} });
    if (!response.ok) throw Error(tr('خطأ اتصال HTTP: ', 'HTTP error: ') + response.status);
    const json = await response.json();
    if (!json || json.success === false) throw Error((json && (json.error || json.message)) || tr('تعذر جلب البيانات.', 'Could not retrieve data.'));
    return json.data || json;
  };
  const value = (node, text) => { node.textContent = text == null ? '' : String(text); };
  let accountsLoaded = false;
  async function loadAccounts() {
    if (accountsLoaded) return;
    try {
      const data = await api('/api/social/connections?_=' + Date.now());
      const connections = (data.connections || []).filter(x => (x.platform === 'facebook' || x.platform === 'instagram') && Number(x.id) > 0);
      select.replaceChildren();
      let placeholder = document.createElement('option'); placeholder.value = ''; value(placeholder, tr('اختر صفحة أو حسابًا', 'Select a Page or account')); select.append(placeholder);
      for (const c of connections) {
        const opt = document.createElement('option'); opt.value = String(c.id); value(opt, `${c.platform === 'facebook' ? 'Facebook' : 'Instagram'} · ${c.name || c.username || ('#' + c.id)}`); select.append(opt);
      }
      accountsLoaded = true;
      if (!connections.length) value(notice, tr('لا يوجد حساب Facebook أو Instagram متصل.', 'No connected Facebook or Instagram account.'));
    } catch (err) { value(notice, err.message); }
  }
  const number = n => n === null || n === undefined || n === '' ? '—' : Number.isFinite(Number(n)) ? new Intl.NumberFormat(ar ? 'ar-EG' : 'en-US', {notation:'compact', maximumFractionDigits:1}).format(Number(n)) : '—';
  function el(tag, cls, text) {
    const x = document.createElement(tag); if(cls) x.className = cls;
    if (text !== undefined) value(x, text); return x;
  }
  function drawPosts(posts, platform) {
    results.replaceChildren();
    if (!posts.length) { results.append(el('div','mh-empty',tr('لا توجد منشورات لعرضها.','No posts to display.'))); return; }
    const fragment = document.createDocumentFragment();
    for (const p of posts) {
      const item = el('article', 'mh-card');
      const header = el('div','mh-header');
      const badge = el('span','mh-badge',String(p.platform || platform).toLowerCase().includes('instagram') ? 'Instagram' : String(p.platform || platform).toLowerCase().includes('facebook') ? 'Facebook' : 'Meta');
      const date = p.published_at && !Number.isNaN(Date.parse(p.published_at.replace(' ', 'T'))) ? new Intl.DateTimeFormat(ar ? 'ar-EG' : 'en-US', {day:'numeric',month:'short',year:'numeric'}).format(new Date(p.published_at.replace(' ', 'T'))) : tr('التاريخ غير متاح','Date unavailable');
      header.append(badge, el('span','mh-date',date));
      item.append(header);
      const url = typeof p.media_url === 'string' ? p.media_url : '';
      if (/^https:\/\/[^\s]+$/i.test(url)) {
        const media = el('div','mh-media');
        const isVideo = /\.(?:mp4|webm|mov)(?:[?#]|$)/i.test(url) || /video/i.test(p.media_type || '');
        if (isVideo) {
          const video = document.createElement('video'); video.src=url; video.controls=true; video.preload='none'; video.playsInline=true; video.referrerPolicy='no-referrer'; media.append(video);
        } else {
          const img = document.createElement('img'); img.src=url; img.loading='lazy'; img.alt=tr('صورة المنشور','Post image'); img.referrerPolicy='no-referrer'; img.addEventListener('error',()=>{media.remove();},{once:true}); media.append(img);
        }
        item.append(media);
      }
      const rawText = (p.text || '').trim();
      const body = el('div','mh-body',rawText || tr('منشور بدون نص','Post without text'));
      const isLong = rawText.length > 260 || rawText.split('\n').length > 6;
      if (isLong) {
        body.classList.add('mh-clamped');
        const toggle = el('button','mh-expand',tr('قراءة المزيد','Read more')); toggle.type='button'; toggle.setAttribute('aria-expanded','false');
        toggle.addEventListener('click',()=>{const expanded=body.classList.toggle('mh-expanded'); toggle.setAttribute('aria-expanded',String(expanded)); value(toggle,expanded?tr('عرض أقل','Show less'):tr('قراءة المزيد','Read more'));});
        item.append(body,toggle);
      } else item.append(body);
      const stats = el('div','mh-stats');
      for (const [icon, label, n] of [['♥',tr('إعجابات','Likes'),p.likes],['◉',tr('تعليقات','Comments'),p.comments],['↗',tr('مشاركات','Shares'),p.shares]]) {
        const stat = el('span','mh-stat'); stat.title=label; stat.append(el('span','mh-stat-icon',icon),el('strong','',number(n)),el('span','mh-stat-label',label)); stats.append(stat);
      }
      item.append(stats);
      const footer=el('div','mh-footer');
      footer.append(el('span','mh-historical',tr('منشور سابق · للعرض فقط','Historical · read-only')));
      if (typeof p.permalink === 'string' && /^https:\/\/[^\s]+$/i.test(p.permalink)) { const link=el('a','mh-link',tr('فتح المنشور ↗','View original ↗')); link.href=p.permalink; link.target='_blank'; link.rel='noopener noreferrer'; footer.append(link); }
      item.append(footer); fragment.append(item);
    }
    results.append(fragment);
  }
  button.addEventListener('click', async () => {
    const id = Number(select.value);
    if (!Number.isSafeInteger(id) || id <= 0) { value(notice, tr('اختر حسابًا متصلًا أولًا.', 'Select a connected account first.')); return; }
    button.disabled = true; value(notice, tr('جارِ قراءة المنشورات من Meta…', 'Reading posts from Meta…')); results.replaceChildren();
    try {
      const data = await api('/api/social/history?connection_id=' + encodeURIComponent(id) + '&limit=30');
      const posts = Array.isArray(data.posts) ? data.posts : [];
      value(notice, tr('تمت قراءة ', 'Loaded ') + posts.length + tr(' منشورًا من Meta (قراءة فقط، بدون تخزين أو إعادة نشر).', ' Meta posts (read-only, no import or republication).'));
      drawPosts(posts, data.platform || 'Meta');
      if (!posts.length) value(notice, tr('لا توجد منشورات متاحة أو ليس لدى الحساب صلاحية قراءتها.', 'No available posts, or this account lacks read permissions.'));
    } catch (err) { value(notice, err.message); } finally { button.disabled = false; }
  });
  if (importButton) importButton.addEventListener('click', async () => {
    if (!select.value) return;
    if (!results.children.length) { value(notice, tr('اعرض المنشورات أولًا.', 'Load posts first.')); return; }
    importButton.disabled = true;
    try {
      const response = await fetch('/api/social/history/import?connection_id=' + encodeURIComponent(select.value) + '&limit=30', {method:'POST', credentials:'same-origin', headers:{'Accept':'application/json','Content-Type':'application/json'}, body:'{}'});
      const json = await response.json(); if (!response.ok || !json.success) throw Error(json.message || json.error || 'Import failed');
      value(notice, tr('تم حفظ/تحديث ', 'Saved/updated ') + json.data.processed + tr(' منشورًا بدون إعادة نشر.', ' posts without republication.'));
    } catch(err) {value(notice, err.message);} finally {importButton.disabled = false;}
  });
  if (savedButton) savedButton.addEventListener('click', async () => {
    if (!select.value) return; savedButton.disabled = true;
    try { const data = await api('/api/social/history/saved?connection_id=' + encodeURIComponent(select.value)); drawPosts(data.posts || [], 'Meta'); value(notice, tr('المنشورات المحفوظة: ', 'Saved posts: ') + data.count); }
    catch(err){value(notice, err.message);} finally{savedButton.disabled = false;}
  });
  loadAccounts();
})();
