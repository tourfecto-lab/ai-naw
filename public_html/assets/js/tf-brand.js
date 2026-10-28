/**
 * Tourfecto brand: اللوجو المتحرك في كل مكان العميل بيستنى فيه
 * - بين الصفحات: لما يدوس على لينك جوه المنصة (لو الصفحة اتأخرت أكتر من 150ms)
 * - أي طلب بياخد وقت (توليد، تحليل، حفظ): لوجو صغير في الركن بعد 600ms
 * - نصوص "جارِ التحميل…" و"جاري التوليد…" بتاخد اللوجو المتحرك جنبها
 * مابيلمسش منطق الصفحات: بس بيضيف عناصر عرض، وبيحترم "تقليل الحركة".
 */
(() => {
  'use strict';
  if (window.TFBrand) return;

  const mark = (animated = true, cls = '') =>
    `<svg class="tf-mark${animated ? ' is-anim' : ''}${cls ? ' ' + cls : ''}" viewBox="0 0 64 64" aria-hidden="true" focusable="false">`
    + '<path class="tf-arc" d="M14.5 24.5 C23 18.5 41 18.5 49.5 24.5"/>'
    + '<path class="tf-stem" d="M32 21 V49.5"/>'
    + '<path class="tf-star" d="M50.5 8.5 l1.5 3.8 3.8 1.5 -3.8 1.5 -1.5 3.8 -1.5 -3.8 -3.8 -1.5 3.8 -1.5 z"/></svg>';

  const ar = (document.documentElement.lang || '').toLowerCase().startsWith('ar');

  // ---------- بين الصفحات ----------
  let pageTimer = null;
  const pageLoader = () => {
    let el = document.getElementById('tfPageLoader');
    if (!el) {
      el = document.createElement('div');
      el.id = 'tfPageLoader';
      el.setAttribute('role', 'status');
      el.setAttribute('aria-label', ar ? 'جارِ التحميل' : 'Loading');
      el.innerHTML = '<div class="tf-pl-card">' + mark(true) + '</div>';
      document.body.appendChild(el);
    }
    return el;
  };
  const showPage = () => { clearTimeout(pageTimer); pageTimer = setTimeout(() => pageLoader().classList.add('on'), 150); };
  const hidePage = () => { clearTimeout(pageTimer); const el = document.getElementById('tfPageLoader'); if (el) el.classList.remove('on'); };

  document.addEventListener('click', (e) => {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const a = e.target.closest && e.target.closest('a[href]');
    if (!a || a.target && a.target !== '_self' || a.hasAttribute('download') || a.dataset.noLoader !== undefined) return;
    const href = a.getAttribute('href') || '';
    if (href === '' || href.startsWith('#') || /^(javascript|mailto|tel|whatsapp|sms):/i.test(href)) return;
    let url;
    try { url = new URL(a.href, location.href); } catch (err) { return; }
    if (url.origin !== location.origin) return;
    if (url.pathname === location.pathname && url.search === location.search && url.hash) return; // نفس الصفحة (تبويب)
    if (/\.(pdf|csv|zip|xlsx?|docx?|png|jpe?g|webp|svg|mp4)$/i.test(url.pathname) || /\/(export|download)/i.test(url.pathname)) return;
    // الصفحة ممكن تكون مانعة التنقل (فيه تعديلات مش محفوظة): نستنى ونشوف
    setTimeout(() => { if (!e.defaultPrevented) showPage(); }, 0);
  }, false);
  document.addEventListener('submit', (e) => {
    const f = e.target;
    if (e.defaultPrevented || !f || f.target === '_blank' || f.dataset.noLoader !== undefined) return;
    setTimeout(() => { if (!e.defaultPrevented) showPage(); }, 0);
  });
  window.addEventListener('pageshow', hidePage);
  window.addEventListener('pagehide', () => clearTimeout(pageTimer));
  // لو التنقل اتلغى (مثلًا "Leave site?" والعميل رفض) مانسيبش الطبقة
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') setTimeout(hidePage, 4000); });

  // ---------- أي طلب بياخد وقت ----------
  // طلبات في الخلفية (إشعارات، تتبع، نبض) مابتظهرش اللوجو
  const SILENT = /notifications|heartbeat|ping|track|analytics|presence|unread|poll|\/api\/currency|wallet\/balance|subscription\/current|login-history|smart-insights/i;
  let busy = 0, busyTimer = null;
  const busyEl = () => {
    let el = document.getElementById('tfBusy');
    if (!el) {
      el = document.createElement('div');
      el.id = 'tfBusy';
      el.setAttribute('role', 'status');
      el.setAttribute('aria-live', 'polite');
      el.innerHTML = mark(true) + '<span>' + (ar ? 'ثانية واحدة…' : 'One moment…') + '</span>';
      document.body.appendChild(el);
    }
    return el;
  };
  const start = () => { busy++; if (busy === 1) { clearTimeout(busyTimer); busyTimer = setTimeout(() => { if (busy > 0 && document.body) busyEl().classList.add('on'); }, 600); } };
  const end = () => { busy = Math.max(0, busy - 1); if (busy === 0) { clearTimeout(busyTimer); const el = document.getElementById('tfBusy'); if (el) el.classList.remove('on'); } };
  const tracked = (input) => {
    try {
      const u = new URL(typeof input === 'string' ? input : (input && input.url) || '', location.href);
      return u.origin === location.origin && !SILENT.test(decodeURIComponent(u.pathname + u.search));
    } catch (e) { return false; }
  };
  if (window.fetch) {
    const orig = window.fetch.bind(window);
    window.fetch = function (input, init) {
      if (!tracked(input) || (init && init.headers && (init.headers['X-TF-Silent'] || (init.headers.get && init.headers.get('X-TF-Silent'))))) return orig(input, init);
      start();
      return orig(input, init).finally(end);
    };
  }
  if (window.XMLHttpRequest) {
    const open = XMLHttpRequest.prototype.open, send = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function (m, u) { this.__tfTrack = tracked(u); return open.apply(this, arguments); };
    XMLHttpRequest.prototype.send = function () {
      if (this.__tfTrack) { start(); this.addEventListener('loadend', end, { once: true }); }
      return send.apply(this, arguments);
    };
  }

  // ---------- نصوص التحميل والتوليد ----------
  const LOADING = /^\s*[⏳⌛🔄]?\s*(جار[ِيٍ]?\s*(التحميل|تحميل|الفحص|التوليد|الحفظ|الإرسال|التحليل|المعالجة|التحقق|الاتصال|البحث|التحديث|الكتابة|التنفيذ|تجهيز|التجهيز)[^\n]{0,30}|بنحمّل[^\n]{0,30}|بيحمّل[^\n]{0,30}|بنفحص[^\n]{0,30}|بنولّد[^\n]{0,30}|بيولّد[^\n]{0,30}|بنجهّز[^\n]{0,30}|Loading[^\n]{0,30}|Generating[^\n]{0,30}|Analy[sz]ing[^\n]{0,30}|Saving[^\n]{0,30}|Processing[^\n]{0,30}|Please wait[^\n]{0,20})\s*$/i;
  const decorate = (el) => {
    // [data-tf-loader]: العنصر نفسه أو أي أب ليه اتزيّن قبل كده (النص اللي جوه اللوجو مايتزيّنش تاني)
    if (!el || el.nodeType !== 1 || el.closest('[data-tf-loader],#tfPageLoader,#tfBusy,svg,script,style,textarea,select,option,[data-no-loader]')) return;
    if (el.children.length) return; // نص بس: مانلمسش عناصر فيها محتوى تاني
    const t = (el.textContent || '').trim();
    if (!t || t.length > 60 || !LOADING.test(t)) return;
    el.dataset.tfLoader = '1';
    const block = !/^(BUTTON|A|SPAN|SMALL|B|STRONG|LABEL|EM)$/.test(el.tagName) && el.parentElement && el.parentElement.children.length === 1;
    el.innerHTML = mark(true) + '<span data-no-loader>' + t.replace(/[<>&]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c])) + '</span>';
    el.classList.add(block ? 'tf-loading-block' : 'tf-loading-inline');
  };
  const scan = (root) => {
    if (!root || root.nodeType !== 1) return;
    decorate(root);
    root.querySelectorAll('div,p,span,td,li,button,small,strong,b,h3,h4,em,label').forEach(decorate);
  };
  const clean = (el) => { // النص اتغيّر (خلص التحميل): نشيل الشكل
    if (el && el.dataset && el.dataset.tfLoader && !el.querySelector('.tf-mark')) { delete el.dataset.tfLoader; el.classList.remove('tf-loading-block', 'tf-loading-inline'); }
  };
  let queued = new Set(), raf = false;
  const flush = () => { raf = false; const items = [...queued]; queued = new Set(); items.forEach((n) => { clean(n); scan(n); }); };
  const start2 = () => {
    scan(document.body);
    new MutationObserver((recs) => {
      for (const r of recs) {
        if (r.type === 'characterData') queued.add(r.target.parentElement);
        else { if (r.target.nodeType === 1) queued.add(r.target); r.addedNodes.forEach((n) => { if (n.nodeType === 1) queued.add(n); else if (n.parentElement) queued.add(n.parentElement); }); }
      }
      if (!raf && queued.size) { raf = true; requestAnimationFrame(flush); }
    }).observe(document.body, { childList: true, subtree: true, characterData: true });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start2, { once: true }); else start2();

  window.TFBrand = { mark, showPage, hidePage, busyStart: start, busyEnd: end };
})();
