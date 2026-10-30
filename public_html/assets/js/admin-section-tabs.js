/**
 * تبويبات للصفحات الطويلة في الأدمن: بدل ما الصفحة تبقى 10 آلاف بيكسل، كل قسم (فيه عنوان) بيبقى تبويب
 * وبيظهر قسم واحد بس. الأقسام اللي مالهاش عنوان (الكروت والملخص فوق) بتفضل ظاهرة دايمًا.
 *
 * الاستخدام:
 *   - صفحة بتتبني من السيرفر: <div data-section-tabs="key" data-section-selector=".card"> ... </div>
 *   - أقسام بتتملي بعد التحميل: حط العنوان على القسم نفسه data-st-title="..."
 *   - صفحة بتتبني بالجافاسكربت: SectionTabs.apply(root, { key: 'growth', selector: 'section.gr-box' }) بعد كل render.
 * التبويب المختار بيتحفظ في الرابط (#tab-key=N) عشان الريفريش والرجوع يفضلوا على نفس القسم.
 */
(() => {
  'use strict';
  const STYLE = `.st-nav{display:flex;flex-wrap:wrap;gap:6px;margin:12px 0 16px;position:sticky;top:0;z-index:5;padding:8px 0;background:var(--panel-bg,var(--bg,#fff))}
.st-nav button{border:1px solid var(--panel-border,#e4e7ec);background:var(--panel-card,#fff);color:inherit;border-radius:999px;padding:6px 14px;font:inherit;font-size:13px;cursor:pointer;line-height:1.4}
.st-nav button[aria-selected=true]{background:var(--panel-primary,#2563eb);border-color:var(--panel-primary,#2563eb);color:#fff;font-weight:600}
.st-nav button:focus-visible{outline:2px solid var(--panel-primary,#2563eb);outline-offset:2px}
[data-st-hidden]{display:none!important}`;
  const addStyle = () => {
    if (document.getElementById('stStyle')) return;
    const s = document.createElement('style');
    s.id = 'stStyle';
    s.textContent = STYLE;
    document.head.appendChild(s);
  };
  const title = el => {
    if (el.dataset.stTitle) return el.dataset.stTitle;
    const h = el.querySelector('h1,h2,h3,.card-title,.p-card-title,legend,summary');
    return h ? h.textContent.replace(/\s+/g, ' ').trim().slice(0, 40) : '';
  };
  const readHash = key => {
    const m = location.hash.match(new RegExp('tab-' + key + '=(\\d+)'));
    return m ? Number(m[1]) : null;
  };
  const writeHash = (key, i) => {
    const rest = location.hash.replace(/^#/, '').split('&').filter(p => p && !p.startsWith('tab-' + key + '='));
    history.replaceState(null, '', '#' + [...rest, 'tab-' + key + '=' + i].join('&'));
  };

  function apply(container, opts) {
    if (!container) return;
    const key = String(opts.key || 'page').replace(/[^a-z0-9_-]/gi, '');
    const sections = [...container.querySelectorAll(opts.selector)].filter(s => s.parentElement && !s.parentElement.closest(opts.selector) && title(s));
    if (sections.length < (opts.min || 3)) return;
    addStyle();
    container.querySelectorAll(':scope .st-nav[data-st-key="' + key + '"]').forEach(n => n.remove());
    let active = readHash(key);
    if (active === null || active >= sections.length) active = 0;
    const nav = document.createElement('nav');
    nav.className = 'st-nav';
    nav.dataset.stKey = key;
    nav.setAttribute('role', 'tablist');
    nav.setAttribute('aria-label', opts.label || 'Sections');
    const show = i => {
      sections.forEach((s, j) => { if (j === i) s.removeAttribute('data-st-hidden'); else s.setAttribute('data-st-hidden', ''); });
      [...nav.children].forEach((b, j) => b.setAttribute('aria-selected', String(j === i)));
    };
    sections.forEach((s, i) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.setAttribute('role', 'tab');
      b.textContent = title(s);
      b.addEventListener('click', () => { show(i); writeHash(key, i); nav.scrollIntoView({ block: 'nearest' }); });
      nav.appendChild(b);
    });
    sections[0].parentElement.insertBefore(nav, sections[0]);
    show(active);
  }

  window.SectionTabs = { apply };
  const auto = () => document.querySelectorAll('[data-section-tabs]').forEach(el => apply(el, { key: el.dataset.sectionTabs, selector: el.dataset.sectionSelector || 'section', min: Number(el.dataset.sectionMin || 3) }));
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', auto, { once: true }); else auto();
})();
