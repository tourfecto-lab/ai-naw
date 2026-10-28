/**
 * Tourfecto - ترجمة النصوص العربي اللي بتترسم بالـ JavaScript في الواجهة غير العربي
 *
 * السيرفر بيترجم الصفحة وهي نازلة (app/Helpers/ui_autotranslate.php)، لكن ملفات الجافاسكريبت
 * (/assets/js) وبيانات الـ API بتترسم في المتصفح ومبتعديش عليه. هنا نفس القاموس (window.TF_EN_DICT)
 * ونفس طريقة السيرفر: كل جملة عربي (من أول حرف عربي لآخر حرف عربي) بتتترجم بس لو موجودة كاملة في
 * القاموس. أي جملة مش معروفة (بيانات العميل، أسماء صفحات، محتوى متولّد) بتفضل زي ما هي.
 */
(() => {
  'use strict';
  const dict = window.TF_EN_DICT;
  if (!dict || document.documentElement.lang.toLowerCase().startsWith('ar')) return;
  const AR = /[؀-ۿ]/;
  const RUN = /[؀-ۿݐ-ݿﭐ-﷿ﹰ-﻿](?:[؀-ۿݐ-ݿﭐ-﷿ﹰ-﻿0-9A-Za-z \t.,:;!?\-()%/+«»،؛؟…–—_’]*[؀-ۿݐ-ݿﭐ-﷿ﹰ-﻿])?/g;
  const SKIP = 'script,style,code,pre,textarea,input,[contenteditable="true"],[data-no-translate]';
  const PUNCT = { '،': ',', '؛': ';', '؟': '?' };
  const has = k => Object.prototype.hasOwnProperty.call(dict, k);

  const run = (r) => {
    if (has(r)) return dict[r];
    // علامات حوالين الجملة: «...» أو (...) أو ...:
    const m = r.match(/^([(«\-]*)(.*?)([.,:;!?،؛؟…)»(\-]*)$/u);
    if (m && m[2] && (m[1] || m[3]) && has(m[2])) return m[1] + dict[m[2]] + m[3].replace(/[،؛؟]/g, c => PUNCT[c]);
    return null;
  };
  const translate = (text) => {
    if (!text || !AR.test(text)) return null;
    let changed = false;
    const out = text.replace(RUN, (r) => { const t = run(r.replace(/\s+/g, ' ').trim()); if (t === null) return r; changed = true; return t; });
    return changed ? out : null;
  };

  const textNode = (node) => {
    const el = node.parentElement;
    if (!el || el.closest(SKIP)) return;
    const en = translate(node.nodeValue);
    if (en === null) return;
    // <option> من غير value: النص هو القيمة اللي بتتبعت - نثبّتها قبل الترجمة
    if (el.tagName === 'OPTION' && !el.hasAttribute('value')) el.setAttribute('value', el.textContent.trim());
    node.nodeValue = en;
    // الصفحة كانت مكتوبة عربي بالكامل (dir="rtl" ثابت): بعد الترجمة الاتجاه يبقى حسب النص
    const box = el.closest('[dir="rtl"]');
    if (box && box !== document.documentElement && !box.hasAttribute('data-tf-dir')) { box.setAttribute('dir', 'auto'); box.setAttribute('data-tf-dir', '1'); }
  };
  const attrs = (el) => {
    for (const name of ['placeholder', 'title', 'aria-label']) {
      const v = el.getAttribute(name);
      if (!v) continue;
      const en = translate(v);
      if (en !== null) el.setAttribute(name, en);
    }
  };
  const walk = (root) => {
    if (root.nodeType === Node.TEXT_NODE) { textNode(root); return; }
    if (root.nodeType !== Node.ELEMENT_NODE || root.closest('script,style,[data-no-translate]')) return;
    attrs(root);
    for (const el of root.querySelectorAll('[placeholder],[title],[aria-label]')) if (!el.closest('[data-no-translate]')) attrs(el);
    const w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (w.nextNode()) nodes.push(w.currentNode);
    nodes.forEach(textNode);
  };

  let queue = new Set(), scheduled = false;
  const flush = () => {
    scheduled = false;
    const items = [...queue];
    queue = new Set();
    observer.disconnect();
    items.forEach(n => { if (n.isConnected) walk(n); });
    observer.observe(document.body, { childList: true, subtree: true, characterData: true });
  };
  const observer = new MutationObserver((records) => {
    for (const r of records) {
      if (r.type === 'characterData') queue.add(r.target);
      else r.addedNodes.forEach(n => queue.add(n));
    }
    if (!scheduled && queue.size) { scheduled = true; requestAnimationFrame(flush); }
  });
  // confirm()/alert()/toast بنصوص عربي ثابتة في الجافاسكريبت
  for (const fn of ['alert', 'confirm']) {
    const orig = window[fn];
    if (typeof orig === 'function') window[fn] = (msg, ...rest) => orig.call(window, typeof msg === 'string' ? (translate(msg) ?? msg) : msg, ...rest);
  }

  const start = () => { walk(document.body); observer.observe(document.body, { childList: true, subtree: true, characterData: true }); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true }); else start();
})();
