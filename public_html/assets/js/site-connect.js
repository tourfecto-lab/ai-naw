/**
 * Tourfecto - Site Connect Wizard (صفحة الموقع /websites/{id})
 * بيعرض: خطوات الربط، التعرف التلقائي على WordPress، "الربط بضغطة"، الربط اليدوي بشرح تفصيلي،
 * وحالة الاتصال (اختبار/فصل). الاتصال بالخادم عبر /api/websites/{id}/platform و /wordpress/*.
 * ما بيعتمد على Panel عشان يشتغل حتى لو اتحمّل قبله.
 */
(function () {
    'use strict';
    var cfg = window.SC_CFG;
    var root = document.getElementById('siteConnect');
    if (!cfg || !root) return;
    var T = cfg.i18n || {};
    var state = { platform: null, forceWp: false };

    function t(k, vars) {
        var s = T[k] != null ? String(T[k]) : k;
        if (vars) Object.keys(vars).forEach(function (v) { s = s.replace('{' + v + '}', vars[v]); });
        return s;
    }
    function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function $(s, r) { return (r || document).querySelector(s); }
    function rich(v) { return esc(v).replace(/(\.htaccess|wp-admin|yoursite\.com)/g, '<bdi>$1</bdi>'); }

    async function api(url, method, body) {
        var opt = { method: method || 'GET', credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
        if (body !== undefined) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
        var ctl = new AbortController(), timer = setTimeout(function () { ctl.abort(); }, 45000);
        opt.signal = ctl.signal;
        try {
            var res = await fetch(url, opt);
            var j; try { j = await res.json(); } catch (e) { j = { success: false, error: 'invalid_response' }; }
            if (res.status === 401) {
                j = { success: false, error: 'انتهت جلستك. سجّل الدخول مرة أخرى.' };
                if (window.Panel && window.Panel.sessionExpired) window.Panel.sessionExpired();
            }
            return j;
        } catch (e) { return { success: false, error: 'network' }; } finally { clearTimeout(timer); }
    }
    function toast(msg, type) { if (window.Panel && window.Panel.toast) window.Panel.toast(msg, type); }

    // ------------------------------------------------------------------ مكوّنات
    var ICON = {
        wp: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M3.5 9 8 20.5M8 20.5l3-9M11 11.5 14.5 20.5 18 9M9 6h9"/></svg>',
        check: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5 9-10"/></svg>',
        copy: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>'
    };

    function stepper(verified, connected) {
        var s2 = verified ? 'done' : 'current';
        var s3 = verified ? (connected ? 'done' : 'current') : '';
        function item(n, label, cls) {
            return '<li class="sc-step ' + cls + '"><span class="sc-dot">' + (cls === 'done' ? ICON.check : n) + '</span><span class="sc-label">' + esc(label) + '</span></li>';
        }
        return '<ol class="sc-stepper" aria-label="' + esc(t('sc.title')) + '">' + item(1, t('sc.step1'), 'done') + item(2, t('sc.step2'), s2) + item(3, t('sc.step3'), s3) + '</ol>';
    }

    function banner(kind, html) { return '<div class="sc-banner sc-' + kind + '" role="status">' + html + '</div>'; }

    function howTo() {
        var steps = [1, 2, 3, 4, 5].map(function (n, i) {
            return '<li><span class="sc-n">' + (i + 1) + '</span><span>' + rich(t('sc.howto_' + n)) + '</span></li>';
        }).join('');
        return '<details class="sc-details"><summary>' + esc(t('sc.howto_title')) + '</summary><ol class="sc-howto">' + steps + '</ol>' +
            '<p class="sc-hint">' + esc(t('sc.howto_missing')) + '</p></details>' +
            '<details class="sc-details"><summary>' + esc(t('sc.trouble_title')) + '</summary><p class="sc-sub">' + rich(t('sc.trouble_body')) + '</p>' +
            '<pre class="sc-code" dir="ltr">RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]</pre></details>';
    }

    function manualForm() {
        return '<details class="sc-details" id="scManualForm"' + (state.openManual ? ' open' : '') + '><summary>' + esc(t('sc.advanced_title')) + '</summary>' +
            '<div class="sc-form"><label>' + esc(t('sc.field_user')) + '<input type="text" id="scUser" autocomplete="off" spellcheck="false" dir="ltr"></label>' +
            '<label>' + esc(t('sc.field_pass')) + '<input type="password" id="scPass" autocomplete="new-password" spellcheck="false" dir="ltr" placeholder="abcd efgh ijkl mnop qrst uvwx"></label>' +
            '<p class="sc-hint">' + esc(t('sc.field_pass_hint')) + '</p>' +
            '<button type="button" class="p-btn primary" id="scConnectBtn">' + esc(t('sc.btn_connect')) + '</button>' +
            '<div id="scManualResult" class="sc-result" hidden></div></div>' + howTo() + '</details>';
    }

    function easySnippet(open) {
        var steps = [1, 2, 3, 4, 5].map(function (n, i) {
            return '<li><span class="sc-n">' + (i + 1) + '</span><span>' + rich(t('sc.easy_' + n)) + '</span></li>';
        }).join('');
        var body = '<p class="sc-sub">' + esc(t('sc.easy_desc')) + '</p><ol class="sc-howto">' + steps + '</ol>' +
            '<div class="sc-copywrap sc-mt"><pre class="sc-code" dir="ltr" id="scSnippet">' + esc(cfg.snippet || '') + '</pre></div>' +
            '<div class="sc-actions sc-mt"><button type="button" class="p-btn primary" id="scVerifyBtn">' + esc(t('sc.btn_verify')) + '</button></div>' +
            '<div id="scVerifyResult" class="sc-result" hidden></div><p class="sc-hint sc-mt">' + esc(t('sc.no_plugin')) + '</p>';
        if (open) return '<div class="sc-primary"><h4 class="sc-h">' + esc(t('sc.easy_title')) + '</h4>' + body + '</div>';
        return '<details class="sc-details"><summary>' + esc(t('sc.easy_alt_title')) + '</summary>' + body + '</details>';
    }

    function appPasswordsWhy() {
        return '<details class="sc-details"><summary>' + esc(t('sc.apdisabled_title')) + '</summary><p class="sc-sub">' + esc(t('sc.apdisabled_body')) + '</p></details>';
    }

    function publishOptional() {
        return '<div class="sc-primary sc-muted"><h4 class="sc-h">' + esc(t('sc.pub_title')) + '</h4><p class="sc-sub">' + esc(t('sc.pub_desc')) + '</p>' +
            '<div class="sc-copywrap"><pre class="sc-code" dir="ltr" id="scAppSnippet">' + esc(cfg.appSnippet || '') + '</pre></div>' +
            '<p class="sc-hint sc-mt">⚠️ ' + esc(t('sc.pub_warn')) + '</p>' +
            '<button type="button" class="p-btn outline xs" id="scRecheck">' + esc(t('sc.btn_recheck')) + '</button></div>';
    }

    function safeNote() { return '<p class="sc-safe">🔒 ' + esc(t('sc.safe_note')) + '</p>'; }

    // ------------------------------------------------------------------ العرض حسب الحالة
    function render() {
        var p = state.platform;
        if (!p) { root.innerHTML = '<div class="sc-card"><p class="sc-hint">' + esc(t('sc.detecting')) + '</p><div class="ap-skel" style="height:64px"></div></div>'; return; }
        var verified = !!p.verified, connected = !!p.connected, isWp = !!p.is_wordpress || state.forceWp;
        var html = '<div class="sc-card">' + stepper(verified, connected);

        if (verified && connected) {
            html += banner('ok', '<strong>' + esc(t('sc.verified_title')) + '</strong> · ' + esc(t('sc.connected_title')) + '<br><span class="sc-sub">' + esc(t('sc.connected_as')) + ' <bdi>' + esc((p.connection && p.connection.account) || '') + '</bdi> · <bdi>' + esc((p.connection && p.connection.site_url) || '') + '</bdi></span>') +
                '<div class="sc-actions"><button type="button" class="p-btn outline xs" id="scTest">' + esc(t('sc.btn_test')) + '</button> <button type="button" class="p-btn danger xs" id="scDisc">' + esc(t('sc.btn_disconnect')) + '</button></div><div id="scTestResult" class="sc-result" hidden></div>';
        } else if (connected && !verified) {
            html += banner('warn', esc(t('sc.result_ok_unverified')));
        } else if (verified && !connected && isWp) {
            html += banner('ok', '<strong>' + esc(t('sc.verified_title')) + '</strong>');
            html += p.authorize_url
                ? '<h4 class="sc-h">' + esc(t('sc.connect_wp_title')) + '</h4><p class="sc-sub">' + esc(t('sc.connect_wp_desc')) + '</p>' + oneClick(p)
                : publishOptional();
        } else if (verified) {
            html += banner('ok', '<strong>' + esc(t('sc.verified_title')) + '</strong>');
        } else if (isWp) {
            html += '<div class="sc-detected">' + ICON.wp + '<span>' + esc(t('sc.wp_detected')) + (p.site_name ? ' · <bdi>' + esc(p.site_name) + '</bdi>' : '') + '</span></div>';
            if (p.authorize_url) {
                html += oneClick(p) + easySnippet(false);
            } else {
                // Application Passwords مقفول: الطريقة الأولى كود واحد (من غير باسورد)، والباقي تحتها
                html += easySnippet(true) + appPasswordsWhy();
            }
        } else {
            html += banner('info', esc(t('sc.not_wp')) + '<br><span class="sc-sub">' + esc(t('sc.not_wp_hint')) + '</span>') +
                '<button type="button" class="p-btn outline xs" id="scForceWp">' + esc(t('sc.btn_force_wp')) + '</button>';
        }
        html += ((isWp && !verified) || (verified && !connected && isWp)) && p.authorize_url ? safeNote() : '';
        html += '</div>';
        root.innerHTML = html;
        bind();
        addCopyButtons(root);

        // الطرق اليدوية (ملف / DNS / وسم) تفضل في الصفحة: نفتحها تلقائيًا لو مش WordPress
        var manual = document.getElementById('scManual');
        if (manual && !isWp && !verified) manual.open = true;
    }

    function oneClick(p) {
        if (p.authorize_url) {
            return '<div class="sc-primary"><h4 class="sc-h">' + esc(t('sc.oneclick_title')) + '</h4><p class="sc-sub">' + esc(t('sc.oneclick_desc')) + '</p>' +
                '<a class="p-btn primary sc-cta" id="scOneClick" href="' + esc(p.authorize_url) + '">' + ICON.wp + ' ' + esc(t('sc.btn_oneclick')) + '</a></div>';
        }
        var why = !p.https ? t('sc.err.not_https') : t('sc.oneclick_unavailable');
        return '<div class="sc-primary sc-muted"><p class="sc-sub">' + esc(why) + '</p></div>';
    }

    // ------------------------------------------------------------------ أحداث
    function showResult(el, ok, text) {
        el.hidden = false; el.className = 'sc-result ' + (ok ? 'sc-ok' : 'sc-err'); el.innerHTML = text;
    }
    function reasonText(reason) {
        var key = 'sc.err.' + reason;
        return T[key] != null ? t(key) : t('sc.err.unknown', { code: reason || '—' });
    }
    // سطر تقني صغير (كود HTTP + كود خطأ WordPress): مفيد في التشخيص ومفيهوش أي بيانات سرية
    function techLine(http, code) {
        if (!http && !code) return '';
        return '<div class="sc-tech" dir="ltr">' + esc(t('sc.err.tech')) + ': HTTP ' + esc(http || '—') + (code ? ' · ' + esc(code) : '') + '</div>';
    }

    async function doVerify() {
        var btn = $('#scVerifyBtn', root), out = $('#scVerifyResult', root);
        btn.disabled = true; var old = btn.textContent; btn.textContent = t('sc.verifying');
        var r = await api('/api/websites/' + cfg.id + '/verify', 'POST', {});
        if (r.success) {
            showResult(out, true, esc(t('websites.verified_msg')));
            setTimeout(function () { location.href = location.pathname; }, 1200);
            return;
        }
        btn.disabled = false; btn.textContent = old;
        var checks = (r.details && r.details.checks) || {}, lines = [], seen = {};
        var map = { fetch_blocked: 'websites.verify_reason_blocked', fetch_failed: 'websites.verify_reason_fetch', redirect_blocked: 'websites.verify_reason_fetch',
            host_not_public: 'websites.verify_reason_fetch', tag_not_found: 'websites.verify_reason_tag', file_not_found: 'websites.verify_reason_file', dns_not_found: 'websites.verify_reason_dns' };
        ['meta_tag', 'file', 'dns_txt'].forEach(function (m) {
            var c = checks[m]; if (!c || !c.reason || !map[c.reason] || seen[map[c.reason]]) return;
            seen[map[c.reason]] = 1; lines.push('<li>' + esc(t(map[c.reason], { code: c.http || '—' })) + '</li>');
        });
        showResult(out, false, '<strong>' + esc(t('sc.verify_failed_title')) + '</strong>' + (lines.length ? '<ul class="sc-list">' + lines.join('') + '</ul>' : '<br>' + esc(reasonText(r.error || 'unknown'))));
    }

    async function recheck() {
        var b = $('#scRecheck', root); if (b) { b.disabled = true; b.textContent = t('sc.detecting'); }
        var r = await api('/api/websites/' + cfg.id + '/platform');
        if (r.success) { state.platform = r.data; }
        render();
    }

    function bind() {
        var vb = $('#scVerifyBtn', root); if (vb) vb.onclick = doVerify;
        var rc = $('#scRecheck', root); if (rc) rc.onclick = recheck;
        var fw = $('#scForceWp', root); if (fw) fw.onclick = function () { state.forceWp = true; state.openManual = true; render(); };
        var cb = $('#scConnectBtn', root);
        if (cb) cb.onclick = async function () {
            var u = $('#scUser', root).value.trim(), pw = $('#scPass', root).value.trim(), out = $('#scManualResult', root);
            if (!u || !pw) { showResult(out, false, esc(t('sc.err.missing_fields'))); return; }
            cb.disabled = true; var old = cb.textContent; cb.textContent = t('sc.connecting');
            var r = await api('/api/websites/' + cfg.id + '/wordpress/connect', 'POST', { username: u, app_password: pw });
            $('#scPass', root).value = '';   // مانسيبش الباسورد في الصفحة
            if (r.success) {
                showResult(out, true, esc(r.data.verified ? t('sc.result_ok') : t('sc.result_ok_unverified')));
                setTimeout(function () { location.href = location.pathname; }, 1400);
            } else {
                cb.disabled = false; cb.textContent = old;
                var d = r.details || {}, reason = d.reason || r.error || 'unknown';
                var extra = '';
                if (reason === 'app_passwords_disabled') {
                    // المشكلة في الموقع نفسه مش في بيانات العميل: نعرض كود التفعيل هنا مباشرة
                    extra = '<p class="sc-sub sc-mt">' + esc(t('sc.err.enable_with_snippet')) + '</p><div class="sc-copywrap"><pre class="sc-code" dir="ltr">' + esc(cfg.appSnippet || '') + '</pre></div>' +
                        '<p class="sc-hint sc-mt">⚠️ ' + esc(t('sc.pub_warn')) + '</p>';
                }
                showResult(out, false, esc(reasonText(reason)) + extra + techLine(d.http, d.wp_code));
                addCopyButtons(out);
            }
        };
        var tb = $('#scTest', root);
        if (tb) tb.onclick = async function () {
            tb.disabled = true; var out = $('#scTestResult', root);
            var r = await api('/api/websites/' + cfg.id + '/wordpress/status?test=1');
            tb.disabled = false;
            var ok = r.success && r.data && r.data.test && r.data.test.ok;
            showResult(out, !!ok, esc(ok ? t('sc.test_ok') : reasonText((r.data && r.data.test && r.data.test.reason) || 'unknown')));
        };
        var db = $('#scDisc', root);
        if (db) db.onclick = async function () {
            if (!window.confirm(t('sc.disconnect_confirm'))) return;
            var r = await api('/api/websites/' + cfg.id + '/wordpress/disconnect', 'POST', {});
            if (r.success) { location.reload(); } else { toast(reasonText('unknown'), 'error'); }
        };
    }

    // ------------------------------------------------------------------ نسخ الأكواد في طرق التحقق اليدوية
    function addCopyButtons(scope) {
        scope = scope || document.getElementById('scManual'); if (!scope) return;
        Array.prototype.forEach.call(scope.querySelectorAll('code, pre'), function (el) {
            if (el.dataset.copy) return; el.dataset.copy = '1';
            var wrap = el.parentNode.classList && el.parentNode.classList.contains('sc-copywrap') ? el.parentNode : null;
            if (!wrap) { wrap = document.createElement('div'); wrap.className = 'sc-copywrap'; el.parentNode.insertBefore(wrap, el); wrap.appendChild(el); }
            // الأكواد عليها padding في style مباشر بيغلب الـ CSS، فزرار "نسخ" كان بيغطي أول الكود
            el.style.paddingRight = '84px';
            var b = document.createElement('button'); b.type = 'button'; b.className = 'sc-copy'; b.innerHTML = ICON.copy + ' <span>' + esc(t('sc.copy')) + '</span>';
            b.onclick = function () {
                var text = el.textContent;
                var done = function () { b.querySelector('span').textContent = t('sc.copied'); setTimeout(function () { b.querySelector('span').textContent = t('sc.copy'); }, 1500); };
                if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done, function () { fallback(text); done(); });
                else { fallback(text); done(); }
            };
            wrap.appendChild(b);
        });
    }
    function fallback(text) {
        var ta = document.createElement('textarea'); ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch (e) { /* ignore */ } ta.remove();
    }

    // ------------------------------------------------------------------ رد WordPress بعد الموافقة (?wp=ok|ok_unverified|rejected|error&reason=)
    function handleReturn() {
        var q = new URLSearchParams(location.search), wp = q.get('wp');
        if (!wp) return;
        var kind = 'info', msg = '';
        if (wp === 'ok') { kind = 'ok'; msg = t('sc.result_ok'); }
        else if (wp === 'ok_unverified') { kind = 'warn'; msg = t('sc.result_ok_unverified'); }
        else if (wp === 'rejected') { kind = 'warn'; msg = t('sc.result_rejected'); }
        else { kind = 'err'; msg = reasonText(q.get('reason') || 'unknown'); }
        var techHttp = q.get('http'), techCode = q.get('code');
        var holder = document.createElement('div'); holder.className = 'sc-returnbanner'; holder.innerHTML = banner(kind, esc(msg) + (kind === 'err' ? techLine(techHttp, techCode) : ''));
        root.parentNode.insertBefore(holder, root);
        if (wp === 'error') state.openManual = true;
        try { history.replaceState(null, '', location.pathname); } catch (e) { /* ignore */ }
    }

    async function init() {
        handleReturn();
        addCopyButtons();
        render();
        var r = await api('/api/websites/' + cfg.id + '/platform');
        state.platform = r.success ? r.data : { verified: !!cfg.verified, connected: false, is_wordpress: false, https: true, authorize_url: null };
        if (!r.success) state.platform.verified = !!cfg.verified;
        render();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
