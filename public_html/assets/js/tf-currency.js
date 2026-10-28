/* زر العملة: في هيدر الموقع والموبايل والفوتر ولوحة العميل، وأي مكان فيه data-tf-currency */
(function () {
    'use strict';
    var root = document.documentElement, isAr = (root.lang || 'en').slice(0, 2) === 'ar';
    var LABEL = isAr ? 'العملة' : 'Currency';
    function css() {
        if (document.getElementById('tfCurCss')) return;
        var s = document.createElement('style'); s.id = 'tfCurCss';
        s.textContent = '.tf-cur{display:inline-flex;align-items:center;gap:6px;font-size:13px;white-space:nowrap}'
            + '.tf-cur select{font:inherit;font-size:13px;color:inherit;background:transparent;border:1px solid rgba(127,127,127,.35);border-radius:8px;padding:5px 8px;cursor:pointer;max-width:150px}'
            + '.tf-cur select:focus-visible{outline:2px solid #3b82f6;outline-offset:1px}.tf-cur option{color:#111;background:#fff}'
            + '.tf-cur.compact .tf-cur-l{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}'
            + '@media(max-width:640px){.topnav .tf-cur.compact{display:none}}.mobile-menu .tf-cur{padding:10px 0}.tf-cur-foot{display:flex;justify-content:center;padding:14px 0 4px}';
        document.head.appendChild(s);
    }
    function build(data, compact) {
        var w = document.createElement('label'); w.className = 'tf-cur' + (compact ? ' compact' : '');
        var l = document.createElement('span'); l.className = 'tf-cur-l'; l.textContent = LABEL;
        var sel = document.createElement('select'); sel.setAttribute('aria-label', LABEL);
        data.list.forEach(function (c) {
            var o = document.createElement('option'); o.value = c.code;
            o.textContent = compact ? c.code + ' ' + c.symbol : c.name + ' (' + c.symbol + ')';
            if (c.code === data.current) o.selected = true;
            sel.appendChild(o);
        });
        sel.addEventListener('change', function () {
            sel.disabled = true;
            fetch('/api/currency', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify({ code: sel.value }) })
                .then(function () { location.reload(); }, function () { sel.disabled = false; });
        });
        w.appendChild(l); w.appendChild(sel);
        return w;
    }
    function mount(data) {
        if (!data || !data.list || data.list.length < 2) return;
        css();
        var top = document.querySelector('.panel-topbar-actions') || document.querySelector('.nav-right');
        if (top && !top.querySelector('.tf-cur')) top.insertBefore(build(data, true), top.firstChild);
        var mm = document.getElementById('mobileMenu');
        if (mm && !mm.querySelector('.tf-cur')) mm.appendChild(build(data, false));
        var ft = document.querySelector('.site-footer-full');
        if (ft && !ft.querySelector('.tf-cur')) { var d = document.createElement('div'); d.className = 'tf-cur-foot'; d.appendChild(build(data, false)); ft.appendChild(d); }
        Array.prototype.forEach.call(document.querySelectorAll('[data-tf-currency]'), function (h) { if (!h.querySelector('.tf-cur')) h.appendChild(build(data, h.getAttribute('data-tf-currency') === 'compact')); });
    }
    function start() {
        if (window.TFCurrencyLoaded) return;
        window.TFCurrencyLoaded = true;
        if (!window.fetch || document.querySelector('.admin-shell, body.admin')) return;
        fetch('/api/currency', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) { mount(j && (j.data || j)); }, function () {});
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
