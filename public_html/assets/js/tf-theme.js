/**
 * Tourfecto - Theme toggle (لوحة العميل + الموقع العام)
 * الوضع الفاتح/الداكن بيتحفظ في localStorage تحت نفس مفتاح الأدمن (ap.theme)
 * عشان الاختيار يمشي مع المستخدم في كل الموقع.
 */
(function () {
    'use strict';
    var KEY = 'ap.theme', root = document.documentElement;
    function read() { try { var v = JSON.parse(localStorage.getItem(KEY)); return v === 'dark' || v === 'light' ? v : null; } catch (e) { return null; } }
    function write(v) { try { localStorage.setItem(KEY, JSON.stringify(v)); } catch (e) { /* خصوصية/امتلاء */ } }
    if (!root.getAttribute('data-theme')) {
        root.setAttribute('data-theme', read() || ((window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light'));
    }
    var SUN = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';
    var MOON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>';
    var isAr = (root.lang || 'en').slice(0, 2) === 'ar';
    var LABEL = isAr ? 'تبديل الوضع الفاتح / الداكن' : 'Switch light / dark theme';
    function toggle() {
        var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next); write(next);
        var m = document.querySelector('meta[name="theme-color"]'); if (m) m.setAttribute('content', next === 'dark' ? '#090E1A' : '#F4F5F8');
        document.dispatchEvent(new CustomEvent('tf:theme', { detail: next }));
    }
    function mount() {
        if (document.getElementById('tfThemeBtn')) return;
        var host = document.querySelector('.panel-topbar-actions') || document.querySelector('.nav-right');
        if (!host) return;
        var b = document.createElement('button');
        b.type = 'button'; b.id = 'tfThemeBtn'; b.className = 'ap-icon-btn ap-theme-btn'; b.title = LABEL; b.setAttribute('aria-label', LABEL);
        b.innerHTML = '<span class="sun">' + SUN + '</span><span class="moon">' + MOON + '</span>';
        b.addEventListener('click', toggle);
        host.insertBefore(b, host.firstChild);
    }
    window.TFTheme = { toggle: toggle };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount); else mount();
})();

/* لوحة العميل: البحث في القائمة يفتح "كل الأدوات" تلقائيًا عشان النتايج ما تتخبّاش */
(function () {
    'use strict';
    function bind() {
        var input = document.getElementById('panelNavSearch'), more = document.querySelector('.panel-more');
        if (!input || !more) return;
        var wasOpen = more.open;
        input.addEventListener('input', function () {
            if (input.value.trim()) { more.open = true; } else { more.open = wasOpen; }
        });
        more.addEventListener('toggle', function () { if (!input.value.trim()) wasOpen = more.open; });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind); else bind();
})();

/* زر العملة (ملف لوحده عشان صفحات تانية تستخدمه من غير زر الوضع الداكن) */
(function () {
    if (window.TFCurrencyLoaded || document.querySelector('script[src*="tf-currency.js"]')) return;
    var s = document.createElement('script'); s.src = '/assets/js/tf-currency.js?v=20261016'; s.defer = true;
    (document.head || document.documentElement).appendChild(s);
})();
