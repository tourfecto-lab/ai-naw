/**
 * Tourfecto - تبويبات صفحة Google Business
 * الصفحة كان فيها 12 قسم ورا بعض؛ دلوقتي 4 تبويبات والعميل يشوف قسم واحد في المرة.
 * التبويب بيتخفي بـ attribute منفصل عشان مايتعارضش مع إظهار/إخفاء الأقسام في كود الصفحة الأصلي.
 */
(() => {
  'use strict';
  const start = () => {
    const box = document.querySelector('.panel-content');
    if (!box || !document.getElementById('gbpConnectionCenter') || document.getElementById('gbpTabs')) return;
    const ar = document.documentElement.lang.toLowerCase().startsWith('ar'), t = (a, e) => ar ? a : e;
    const tabs = [
      ['place', t('📍 المكان والربط', '📍 Location & connection'), ['gbpSetupWizard', 'gbpConnectionCenter', 'gbpLocationCard', 'gbpLocationDashboard']],
      ['profile', t('🏢 بيانات النشاط والصور', '🏢 Profile & photos'), ['gbpProfileCard', 'gbpPhotosCard']],
      ['posts', t('📝 البوستات', '📝 Posts'), []],
      ['insights', t('📊 الأداء والتوصيات', '📊 Performance & tips'), ['gbpInsightsCard']],
    ];
    const owner = new Map();
    for (const [key, , ids] of tabs) for (const id of ids) { const n = document.getElementById(id); if (n) owner.set(n, key); }
    // الأقسام اللي مالهاش id: كارت تحليلات الذكاء الاصطناعي والتوصيات، وشريط البوستات وشبكتها
    for (const n of box.children) {
      if (owner.has(n) || /^(SCRIPT|STYLE)$/.test(n.tagName) || n.classList.contains('p-modal-overlay') || n.classList.contains('seo-hub')) continue;
      if (n.id === 'gbpGrid' || n.classList.contains('p-toolbar')) owner.set(n, 'posts');
      else if (n.classList.contains('p-grid')) owner.set(n, 'insights');
    }
    const css = document.createElement('style');
    css.textContent = '[data-gbp-off]{display:none!important}#gbpTabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 16px}@media(max-width:700px){#gbpTabs{flex-wrap:nowrap;overflow-x:auto}#gbpTabs a{white-space:nowrap}}';
    document.head.append(css);
    const nav = document.createElement('nav');
    nav.id = 'gbpTabs'; nav.setAttribute('aria-label', t('أقسام Google Business', 'Google Business sections'));
    for (const [key, label] of tabs) { const a = document.createElement('a'); a.href = '#' + key; a.dataset.tab = key; a.className = 'p-btn outline'; a.textContent = label; nav.append(a); }
    const first = [...box.children].find(n => owner.has(n));
    if (!first) return;
    box.insertBefore(nav, first);
    const show = key => {
      if (!tabs.some(x => x[0] === key)) key = 'place';
      for (const [n, k] of owner) n.toggleAttribute('data-gbp-off', k !== key);
      for (const a of nav.children) { const on = a.dataset.tab === key; a.className = 'p-btn ' + (on ? 'primary' : 'outline'); if (on) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current'); }
      try { localStorage.setItem('tf-gbp-tab', key); } catch (e) {}
    };
    nav.addEventListener('click', e => { const a = e.target.closest('a[data-tab]'); if (!a) return; e.preventDefault(); history.replaceState(null, '', '#' + a.dataset.tab); show(a.dataset.tab); });
    let saved = null; try { saved = localStorage.getItem('tf-gbp-tab'); } catch (e) {}
    show(location.hash.slice(1) || saved || 'place');
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true }); else start();
})();
