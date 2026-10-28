/**
 * Tourfecto - عدّاد استهلاك الإرسال للعميل (يقرأ GET /api/email-marketing/quota)
 * EmailQuotaWidget.mount(el, {baseUrl:'/api/email-marketing', headers:{}, fetchImpl})
 * يعرض: حالة الحساب (موقوف + السبب)، استهلاك اليوم/الشهر، وتنبيه warm-up لو شغّال.
 */
(function (global) {
    'use strict';
    function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function bar(label, used, cap) {
        var pct = cap > 0 ? Math.min(100, Math.round(used / cap * 100)) : 0;
        var cls = pct >= 90 ? 'eq-bad' : (pct >= 70 ? 'eq-warn' : 'eq-ok');
        return '<div class="eq-row"><span>' + esc(label) + '</span><div class="eq-meter ' + cls + '"><i style="width:' + pct + '%"></i></div><small>' + esc(used) + ' / ' + esc(cap) + '</small></div>';
    }
    function mount(root, opts) {
        opts = opts || {};
        var base = (opts.baseUrl || '/api/email-marketing').replace(/\/$/, '');
        var f = opts.fetchImpl || global.fetch.bind(global);
        return f(base + '/quota', { headers: Object.assign({ Accept: 'application/json' }, opts.headers || {}), credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                var d = j.data || j, l = d.limits || {}, u = d.usage || {};
                var html = '<div class="eq" dir="rtl">';
                if (d.status && d.status !== 'active') {
                    html += '<div class="eq-banner" role="alert">الإرسال موقوف لحسابك' + (d.pause_reason ? ': ' + esc(d.pause_reason) : '') + '. تواصل مع الدعم لمراجعة الحساب.</div>';
                }
                html += bar('اليوم', u.day || 0, l.daily_effective || l.daily || 0) + bar('هذا الشهر', u.month || 0, l.monthly || 0);
                if (l.warmup_cap) {
                    html += '<p class="eq-note">حسابك في فترة تسخين (Warm-up): الحد اليومي الحالي ' + esc(l.warmup_cap) + ' رسالة ويزيد تدريجيًا لحماية سمعة الإرسال.</p>';
                }
                root.innerHTML = html + '</div>';
            }).catch(function () { root.innerHTML = ''; });
    }
    global.EmailQuotaWidget = { mount: mount };
})(typeof window !== 'undefined' ? window : this);
