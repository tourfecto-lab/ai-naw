/**
 * Tourfecto - لوحة الأدمن: حماية سمعة التسويق بالبريد
 * الاستخدام:  EmailReputationAdmin.mount(document.getElementById('email-reputation-root'), {
 *                baseUrl: '/api/admin/email-reputation',   // اختياري
 *                headers: {Authorization: 'Bearer ...'},   // اختياري (لو الـ API مش بكوكي)
 *                fetchImpl: fetch                            // اختياري (للاختبار)
 *             });
 * كل النصوص القادمة من الخادم بتتعمل لها escape (لا innerHTML بقيم خام).
 * يتوقع الرد بصيغة {success:true,data:...} (نفس Controller::success).
 */
(function (global) {
    'use strict';

    var STATUS_AR = { active: 'نشط', paused: 'موقوف', suspended: 'معلّق إداريًا' };
    var EVENT_AR = {
        account_paused: 'إيقاف حساب', account_suspended: 'تعليق حساب', account_resumed: 'استئناف حساب',
        campaign_paused: 'إيقاف حملة', campaign_resumed: 'استئناف حملة', limits_changed: 'تعديل الحدود',
        review_approved: 'موافقة مراجعة', rate_warning: 'تحذير معدلات', rate_warning_account: 'تحذير معدلات الحساب',
        global_suppression_added: 'حظر عام (إضافة)', global_suppression_removed: 'حظر عام (حذف)'
    };

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function mount(root, opts) {
        opts = opts || {};
        var base = (opts.baseUrl || '/api/admin/email-reputation').replace(/\/$/, '');
        var doFetch = opts.fetchImpl || global.fetch.bind(global);
        var state = { tab: 'overview', days: 30, selected: null, msg: '' };

        function api(method, path, body) {
            var h = Object.assign({ Accept: 'application/json' }, opts.headers || {});
            var init = { method: method, headers: h, credentials: 'same-origin' };
            if (body !== undefined) { h['Content-Type'] = 'application/json'; init.body = JSON.stringify(body); }
            return doFetch(base + path, init).then(function (r) { return r.json(); }).then(function (j) {
                if (j && j.success === false) { throw new Error(j.message || j.error || 'خطأ'); }
                return j && j.data !== undefined ? j.data : j;
            });
        }

        function flash(text, bad) { state.msg = { text: text, bad: !!bad }; }
        function guarded(p) { return p.then(function () { return render(); }, function (e) { flash(e.message, true); return render(); }); }

        function badge(status) {
            return '<span class="er-badge er-' + esc(status) + '">' + esc(STATUS_AR[status] || status) + '</span>';
        }
        function rate(v, warn, bad) {
            var cls = v >= bad ? 'er-bad' : (v >= warn ? 'er-warn' : 'er-ok');
            return '<span class="er-rate ' + cls + '">' + esc(v) + '%</span>';
        }
        function msgHtml() {
            return state.msg ? '<div class="er-msg ' + (state.msg.bad ? 'er-bad' : 'er-ok') + '" role="status">' + esc(state.msg.text) + '</div>' : '';
        }
        // تنسيق الصفحة (كانت من غير أي CSS: أزرار خام وعناوين الجدول لازقة في بعض)
        var ER_CSS = '<style>.er{display:flex;flex-direction:column;gap:14px}.er-tabs{display:flex;gap:8px;flex-wrap:wrap}.er-card{padding:16px;overflow-x:auto}'
            + '.er-bar,.er-form{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px}'
            + '.er input,.er select{padding:7px 10px;border-radius:8px;border:1px solid var(--panel-border,#d0d5dd);background:var(--panel-card-bg,#fff);color:var(--panel-text,inherit);font:inherit}'
            + '.er-table{width:100%}.er h3{margin:6px 0 12px}</style>';
        function shell(inner) {
            var tabs = [['overview', 'نظرة عامة'], ['suppress', 'الحظر العام'], ['events', 'سجل التدقيق']].map(function (t) {
                return '<button class="p-btn xs ' + (state.tab === t[0] ? 'primary' : 'outline') + '" data-tab="' + t[0] + '">' + t[1] + '</button>';
            }).join('');
            root.innerHTML = ER_CSS + '<div class="er" dir="rtl"><div class="er-tabs">' + tabs + '</div>' + msgHtml() + '<div class="p-card er-card">' + inner + '</div></div>';
            state.msg = '';
            Array.prototype.forEach.call(root.querySelectorAll('[data-tab]'), function (b) {
                b.onclick = function () { state.tab = b.getAttribute('data-tab'); state.selected = null; render(); };
            });
        }

        // ------------------------------------------------ views
        function viewOverview() {
            if (state.selected) { return viewAccount(state.selected); }
            return api('GET', '/overview?days=' + state.days).then(function (d) {
                var rows = (d.accounts || []).map(function (a) {
                    return '<tr data-user="' + esc(a.user_id) + '" class="er-row">' +
                        '<td>#' + esc(a.user_id) + '</td><td>' + badge(a.status) + '</td>' +
                        '<td>' + esc(a.campaigns) + '</td><td>' + esc(a.attempted) + '</td>' +
                        '<td>' + rate(a.bounce_rate, 2, 5) + '</td><td>' + rate(a.complaint_rate, 0.1, 0.3) + '</td>' +
                        '<td>' + esc((a.usage || {}).day || 0) + '</td></tr>';
                }).join('');
                shell('<div class="er-bar"><label>الفترة (يوم) <select id="er-days">' +
                    [7, 30, 90].map(function (n) { return '<option' + (n === state.days ? ' selected' : '') + '>' + n + '</option>'; }).join('') +
                    '</select></label></div>' +
                    '<table class="p-table er-table"><thead><tr><th>العميل</th><th>الحالة</th><th>حملات</th><th>مُرسل</th><th>ارتداد</th><th>شكاوى</th><th>استهلاك اليوم</th></tr></thead><tbody>' +
                    (rows || '<tr><td colspan="7" class="er-empty">لا توجد بيانات إرسال في الفترة دي</td></tr>') + '</tbody></table>');
                var sel = root.querySelector('#er-days');
                if (sel) { sel.onchange = function () { state.days = parseInt(sel.value, 10); render(); }; }
                Array.prototype.forEach.call(root.querySelectorAll('.er-row'), function (tr) {
                    tr.onclick = function () { state.selected = parseInt(tr.getAttribute('data-user'), 10); render(); };
                });
            });
        }

        function viewAccount(uid) {
            return api('GET', '/accounts/' + uid).then(function (d) {
                var s = d.snapshot, l = s.limits, u = s.usage, rem = s.remaining;
                var ev = (d.events || []).slice(0, 10).map(function (e) {
                    return '<li><b>' + esc(EVENT_AR[e.event_type] || e.event_type) + '</b> <time>' + esc(e.created_at) + '</time></li>';
                }).join('');
                var paused = s.status !== 'active';
                shell('<button class="p-btn outline xs er-back" id="er-back">→ رجوع</button><h3>العميل #' + esc(uid) + ' ' + badge(s.status) + '</h3>' +
                    (s.pause_reason ? '<p class="er-reason">' + esc(s.pause_reason) + '</p>' : '') +
                    '<div class="er-cards">' +
                    card('اليوم', u.day, l.daily_effective, rem.day) + card('الساعة', u.hour, l.hourly, rem.hour) + card('الشهر', u.month, l.monthly, rem.month) +
                    '</div>' +
                    '<p>ارتداد: ' + rate(round((d.rates.bounced / Math.max(1, d.rates.attempted)) * 100), 2, 5) +
                    ' · شكاوى: ' + rate(round((d.rates.complaints / Math.max(1, d.rates.attempted)) * 100, 3), 0.1, 0.3) +
                    ' · عينة ' + esc(d.rates.attempted) + '</p>' +
                    '<div class="er-actions">' +
                    (paused ? '<button id="er-resume" class="p-btn primary">استئناف الإرسال</button>'
                        : '<input id="er-reason" placeholder="سبب الإيقاف (مطلوب)"><button id="er-pause" class="p-btn danger">إيقاف الإرسال</button>') +
                    '</div>' +
                    '<form id="er-limits" class="er-form"><b>تعديل الحدود</b> (فاضي = الافتراضي)' +
                    [['daily_limit', 'يومي'], ['hourly_limit', 'ساعي'], ['monthly_limit', 'شهري'], ['per_minute_limit', 'لكل دقيقة']].map(function (f) {
                        return '<label>' + f[1] + ' <input name="' + f[0] + '" type="number" min="1"></label>';
                    }).join('') + '<button class="p-btn primary">حفظ</button></form>' +
                    '<h4>آخر الأحداث</h4><ul class="er-events">' + (ev || '<li class="er-empty">لا أحداث</li>') + '</ul>');

                root.querySelector('#er-back').onclick = function () { state.selected = null; render(); };
                var pause = root.querySelector('#er-pause');
                if (pause) {
                    pause.onclick = function () {
                        var reason = root.querySelector('#er-reason').value.trim();
                        if (!reason) { flash('اكتب سبب الإيقاف', true); return render(); }
                        guarded(api('POST', '/accounts/' + uid + '/pause', { reason: reason }).then(function () { flash('تم الإيقاف'); }));
                    };
                }
                var resume = root.querySelector('#er-resume');
                if (resume) { resume.onclick = function () { guarded(api('POST', '/accounts/' + uid + '/resume', {}).then(function () { flash('تم الاستئناف'); })); }; }
                root.querySelector('#er-limits').onsubmit = function (ev2) {
                    ev2.preventDefault();
                    var body = {};
                    Array.prototype.forEach.call(ev2.target.elements, function (el) {
                        if (el.name) { body[el.name] = el.value === '' ? null : parseInt(el.value, 10); }
                    });
                    guarded(api('POST', '/accounts/' + uid + '/limits', body).then(function () { flash('تم حفظ الحدود'); }));
                };
            });
        }
        function round(v, p) { var m = Math.pow(10, p || 2); return Math.round(v * m) / m; }
        function card(t, used, cap, remaining) {
            var pct = cap > 0 ? Math.min(100, Math.round(used / cap * 100)) : 0;
            return '<div class="er-card"><b>' + esc(t) + '</b><div class="er-meter"><i style="width:' + pct + '%"></i></div>' +
                '<small>' + esc(used) + ' / ' + esc(cap) + ' (متبقي ' + esc(remaining) + ')</small></div>';
        }

        function viewSuppress() {
            return api('GET', '/global-suppressions').then(function (rows) {
                var list = (rows || []).map(function (r) {
                    return '<tr><td>' + esc(r.kind === 'domain' ? 'دومين' : 'إيميل') + '</td><td dir="ltr">' + esc(r.value) + '</td><td>' + esc(r.reason || '') +
                        '</td><td><button class="p-btn danger xs er-del" data-id="' + esc(r.id) + '">حذف</button></td></tr>';
                }).join('');
                shell('<form id="er-add" class="er-form"><select name="kind"><option value="email">إيميل</option><option value="domain">دومين</option></select>' +
                    '<input name="value" placeholder="spam@example.com أو example.com" dir="ltr" required><input name="reason" placeholder="السبب"><button class="p-btn primary">إضافة للحظر العام</button></form>' +
                    '<table class="p-table er-table"><thead><tr><th>النوع</th><th>القيمة</th><th>السبب</th><th></th></tr></thead><tbody>' +
                    (list || '<tr><td colspan="4" class="er-empty">لا يوجد</td></tr>') + '</tbody></table>');
                root.querySelector('#er-add').onsubmit = function (e) {
                    e.preventDefault();
                    var f = e.target.elements;
                    guarded(api('POST', '/global-suppressions', { kind: f.kind.value, value: f.value.value, reason: f.reason.value }).then(function () { flash('تمت الإضافة'); }));
                };
                Array.prototype.forEach.call(root.querySelectorAll('.er-del'), function (b) {
                    b.onclick = function () {
                        if (global.confirm && !global.confirm('حذف من الحظر العام؟')) { return; }
                        guarded(api('DELETE', '/global-suppressions/' + b.getAttribute('data-id')).then(function () { flash('تم الحذف'); }));
                    };
                });
            });
        }

        function viewEvents() {
            return api('GET', '/events?limit=100').then(function (rows) {
                var list = (rows || []).map(function (e) {
                    return '<tr><td>' + esc(e.created_at) + '</td><td>' + esc(EVENT_AR[e.event_type] || e.event_type) + '</td><td>' +
                        (Number(e.user_id) ? '#' + esc(e.user_id) : '—') + '</td><td>' + (e.actor_id ? 'أدمن #' + esc(e.actor_id) : 'النظام') + '</td></tr>';
                }).join('');
                shell('<table class="p-table er-table"><thead><tr><th>الوقت (UTC)</th><th>الحدث</th><th>العميل</th><th>المنفّذ</th></tr></thead><tbody>' +
                    (list || '<tr><td colspan="4" class="er-empty">لا أحداث</td></tr>') + '</tbody></table>');
            });
        }

        function render() {
            var v = state.tab === 'suppress' ? viewSuppress : (state.tab === 'events' ? viewEvents : viewOverview);
            return v().catch(function (e) { shell('<div class="er-msg er-bad">تعذر التحميل: ' + esc(e.message) + '</div>'); });
        }
        return render();
    }

    global.EmailReputationAdmin = { mount: mount, _esc: esc };
})(typeof window !== 'undefined' ? window : this);
