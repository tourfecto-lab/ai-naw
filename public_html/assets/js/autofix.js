/**
 * Tourfecto - AutoFix (صفحة محسّن الموقع)
 * بيضيف على كروت الإصلاحات: "نفّذ تلقائياً" (بمعاينة القيمة الحقيقية قبل التطبيق)، "تراجع"،
 * و"نفّذ كل الإصلاحات الآمنة"، ولوحة الاتصال بكود Tourfecto Edge (WordPress).
 * الكروت بتتحقن بـ renderFixes في الصفحة (data-check-key / data-fix-id / data-status).
 */
(function () {
    'use strict';
    var list = document.getElementById('woFixesList');
    var siteSel = document.getElementById('woWebsiteSelect');
    if (!list) return;

    var AR = false; // Customer installation instructions use English.
    var D = {
        ar: {
            auto: '⚡ نفّذ تلقائياً', undo: '↩ تراجع', auto_applied: 'اتنفّذ تلقائياً', not_wp: 'التنفيذ التلقائي بيشتغل على مواقع WordPress. باقي المواقع بتنفّذ الكود الجاهز يدوياً.',
            bar_title: 'التنفيذ التلقائي', connected: 'متصل ✓', not_connected: 'غير متصل', connect_btn: 'تثبيت كود Tourfecto Edge', check_btn: 'تحقق من الاتصال',
            run_all: 'نفّذ كل الإصلاحات الآمنة', run_all_n: 'نفّذ الآمنة ({n})', confirm_all: 'هنجهّز قيم حقيقية من صفحتك وننفّذها. HSTS مش بيتنفّذ هنا (له تأكيد لوحده). تكمّل؟',
            preview_title: 'القيمة اللي هتتنفّذ', editable: 'تقدر تعدّلها قبل التنفيذ.', apply: 'تنفيذ', cancel: 'إلغاء', loading: 'بنجهّز القيمة من صفحتك…',
            risk_title: 'تنبيه', hsts_ack: 'فاهم إن HSTS بيخلّي المتصفحات تفتح موقعي بـ HTTPS بس لمدة 30 يوم.', applied_ok: 'اتنفّذ. بيظهر على موقعك خلال دقايق (بعد ما الكاش يتمسح).',
            applied_wait: 'اتحفظ. هيظهر على موقعك أول ما تثبّت كود Tourfecto Edge.', undone: 'اتلغى التنفيذ', all_done: 'اتنفّذ {n} إصلاح',
            snippet_title: 'تثبيت كود Tourfecto Edge (مرة واحدة)', snippet_desc: 'الكود ده بيسحب القيم من Tourfecto وينفّذها على موقعك. بيانات فقط، مفيش تنفيذ كود من برا، وبيشتغل حتى لو Tourfecto وقف.',
            s1: 'انسخ الكود.', s2: 'في WordPress افتح Code Snippets ← Add New (أو WPCode ← Add Snippet ← Custom Code).', s3: 'الصق الكود، النوع PHP، فعّله (Active) وادوس Save and Activate.',
            plugin_title: 'الأسهل: إضافة WordPress جاهزة', p1: 'نزّل الإضافة (ملف zip).', p2: 'في WordPress: Plugins ← Add New ← Upload Plugin، اختار الملف وادوس Install ثم Activate.',
            plugin_btn: 'نزّل إضافة Tourfecto SEO', or_snippet: 'أو الصق الكود يدويًا (Code Snippets / WPCode)',
            s4: 'ارجع هنا وادوس "تحقق من الاتصال".', copy: 'نسخ', copied: 'اتنسخ ✓', conn_ok: 'اتأكدنا: الكود شغّال على موقعك ✓', conn_wait: 'لسه ما ظهرش. افتح أي صفحة من موقعك مرة وجرّب تاني.',
            seo_note: 'لو عندك Yoast أو Rank Math أو AIOSEO، الكود بيسيب العنوان والوصف والـ og والـ canonical لهم (عشان ما يتكررش)، وبينفّذ الباقي.',
            w_no_image_found: 'ما لقيناش صورة حقيقية في صفحتك، فمش هنحط og:image مخمّنة.', w_no_logo_found: 'ما لقيناش شعار حقيقي، فمش هنحطه في الـ Schema.',
            w_no_social_found: 'ما لقيناش روابط سوشيال في صفحتك، فمش هنحط sameAs.', w_hsts_conservative: 'هنفعّلها 30 يوم من غير includeSubDomains ولا preload (الأأمن). تقدر ترفعها بعدين.',
            e_page_unreachable: 'ما قدرناش نفتح صفحتك لتجهيز القيمة.', e_no_real_value: 'ما قدرناش نطلّع قيمة حقيقية لده. نفّذه يدوياً.', e_site_not_https: 'الموقع لازم يكون HTTPS الأول.',
            e_not_automatable: 'ده الإصلاح محتاج وصول للسيرفر أو تعديل محتوى، فهو يدوي.', e_invalid_value: 'القيمة غير صالحة.', e_placeholder_value: 'القيمة فيها كلام قالب (زي "اسم البراند"). اكتب القيمة الحقيقية.',
            e_risk_not_confirmed: 'لازم تأكّد التنبيه الأول.', e_website_missing: 'الموقع مش مربوط بحسابك.', e_default: 'حصلت مشكلة. جرّب تاني.', after_hint: 'بعد دقيقتين اعمل تدقيق جديد وشوف الدرجة.'
        },
        en: {
            auto: '⚡ Apply automatically', undo: '↩ Undo', auto_applied: 'Saved — not verified', not_wp: 'Automatic apply works on WordPress sites. Other sites apply the ready code manually.',
            bar_title: 'Automatic apply', connected: 'Connected ✓', not_connected: 'Not connected', connect_btn: 'Install Tourfecto Edge code', check_btn: 'Check connection',
            run_all: 'Apply all safe fixes', run_all_n: 'Apply safe fixes ({n})', confirm_all: 'We will prepare real values from your page and apply them. HSTS is not applied here (it has its own confirmation). Continue?',
            preview_title: 'The value that will be applied', editable: 'You can edit it before applying.', apply: 'Apply', cancel: 'Cancel', loading: 'Preparing the value from your page…',
            risk_title: 'Heads up', hsts_ack: 'I understand HSTS makes browsers open my site over HTTPS only for 30 days.', applied_ok: 'Saved for your website. Use Verify on website after the page cache is refreshed.',
            applied_wait: 'Saved. It will show on your site as soon as you install the Tourfecto Edge code.', undone: 'Undone', all_done: '{n} fixes applied',
            snippet_title: 'Install the Tourfecto Edge code (one time)', snippet_desc: 'This code pulls values from Tourfecto and applies them on your site. Data only, no remote code execution, and it keeps working if Tourfecto is down.',
            s1: 'Copy the code.', s2: 'In WordPress open Code Snippets → Add New (or WPCode → Add Snippet → Custom Code).', s3: 'Paste it as PHP without an opening PHP tag. Select Auto Insert > Run Everywhere, make it Active and press Save and Activate.',
            plugin_title: 'Easiest: a ready WordPress plugin', p1: 'Download the plugin (zip file).', p2: 'In WordPress: Plugins → Add New → Upload Plugin, pick the file, Install, then Activate.',
            plugin_btn: 'Download Tourfecto SEO plugin', or_snippet: 'Or paste the code manually (Code Snippets / WPCode)',
            s4: 'Come back here and press "Check connection".', copy: 'Copy', copied: 'Copied ✓', conn_ok: 'Confirmed: the code is running on your site ✓', conn_wait: 'Not connected yet. Set WPCode to Auto Insert > Run Everywhere, save the active snippet, then reload your WordPress dashboard and homepage. Clear page/CDN cache and check again. If a Tourfecto warning appears in WordPress, contact support with that message.',
            seo_note: 'If you use Yoast, Rank Math or AIOSEO, the code leaves title, description, og and canonical to them (to avoid duplicates) and applies the rest.',
            w_no_image_found: 'We found no real image on your page, so we will not add a guessed og:image.', w_no_logo_found: 'We found no real logo, so it is left out of the Schema.',
            w_no_social_found: 'We found no social links on your page, so sameAs is left out.', w_hsts_conservative: 'Enabled for 30 days without includeSubDomains or preload (safest). You can raise it later.',
            e_page_unreachable: 'We could not open your page to prepare the value.', e_no_real_value: 'We could not produce a real value for this. Apply it manually.', e_site_not_https: 'The site must be on HTTPS first.',
            e_not_automatable: 'This fix needs server access or content edits, so it is manual.', e_invalid_value: 'The value is not valid.', e_placeholder_value: 'The value contains template text (like "brand name"). Enter the real value.',
            e_risk_not_confirmed: 'Please confirm the warning first.', e_website_missing: 'The site is not linked to your account.', e_default: 'Something went wrong. Try again.', after_hint: 'In two minutes run a new audit and check the score.'
        }
    };
    var T = AR ? D.ar : D.en;
    function t(k, v) { var s = T[k] != null ? T[k] : k; if (v) Object.keys(v).forEach(function (x) { s = s.replace('{' + x + '}', v[x]); }); return s; }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function toast(m, type) { if (window.Panel && window.Panel.toast) window.Panel.toast(m, type); else if (type === 'error') alert(m); }

    var state = { caps: null, connected: false, snippet: '', siteId: '', loaded: false };

    async function api(url, method, body) {
        if (body !== undefined && state.csrf) body.csrf_token = state.csrf;
        var opt = { method: method || 'GET', credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
        if (body !== undefined) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
        var ctl = new AbortController(), timer = setTimeout(function () { ctl.abort(); }, 60000);
        opt.signal = ctl.signal;
        try {
            var r = await fetch(url, opt), j;
            try { j = await r.json(); } catch (e) { j = { success: false, error: 'invalid_response' }; }
            return j;
        } catch (e) { return { success: false, error: 'network' }; } finally { clearTimeout(timer); }
    }
    function errText(r) { var reason = (r.details && r.details.reason) || r.error || 'default'; return T['e_' + reason] || T.e_default; }

    // ------------------------------------------------------------------ حالة الموقع
    async function loadStatus() {
        var id = siteSel ? siteSel.value : '';
        if (!id) return;
        if (state.loaded && state.siteId === id) return;
        var r = await api('/api/website-optimizer/edge/status?website_id=' + encodeURIComponent(id));
        if (!r.success) return;
        state = { caps: r.data.capabilities, connected: !!r.data.connected, snippet: r.data.snippet || '', pluginUrl: r.data.plugin_url || '', csrf: r.data.csrf_token || '', siteId: id, loaded: true };
        decorate();
    }

    // ------------------------------------------------------------------ تزيين الكروت
    function cards() { return Array.prototype.slice.call(list.querySelectorAll('.wo-fix-card')); }

    function decorate() {
        if (!state.caps) return;
        var n = 0;
        cards().forEach(function (card) {
            var key = card.dataset.checkKey, cap = state.caps[key];
            var actions = card.querySelector('.wo-fix-actions');
            if (!actions || !cap) return;
            var old = actions.querySelector('.af-btn'); if (old) old.remove();
            var applied = card.dataset.status === 'applied';
            var byEdge = card.dataset.appliedBy === 'edge';
            var b = document.createElement('button'); b.type = 'button'; b.className = 'p-btn xs af-btn';
            if (applied && byEdge) { b.classList.add('outline'); b.textContent = t('undo'); b.onclick = function () { undo(card); }; }
            else if (!applied) { b.classList.add('primary'); b.textContent = t('auto'); b.onclick = function () { openPreview(card); }; if (cap.risk === 'low' && cap.field !== 'robots') n++; }
            else return;
            actions.insertBefore(b, actions.firstChild);
            if (applied && byEdge) {
                var template = card.querySelector('.wo-fix-code'); if (template) template.hidden = true;
                var pill = card.querySelector('[data-fix-status]'); if (pill && !card.dataset.verificationState) { pill.className = 'pill orange'; pill.textContent = 'Saved — not verified'; }
                if (!actions.querySelector('.af-verify')) { var verify = document.createElement('button'); verify.type='button'; verify.className='p-btn xs outline af-verify'; verify.textContent='Verify on website'; verify.onclick=function(){ verifyCard(card,verify); }; actions.appendChild(verify); }
            } else { var template = card.querySelector('.wo-fix-code'); if(template)template.hidden=false; var verify=actions.querySelector('.af-verify');if(verify)verify.remove();var report=card.querySelector('.af-report');if(report)report.remove();delete card.dataset.verificationState; }

        });
        renderBar(n);
        loadReport();
    }

    function renderBar(n) {
        var bar = document.getElementById('afBar');
        if (!bar) { bar = document.createElement('div'); bar.id = 'afBar'; bar.className = 'af-bar'; list.parentNode.insertBefore(bar, list); }
        if (!cards().length) { bar.innerHTML = ''; return; }
        bar.innerHTML = '<div class="af-bar-main"><strong>⚡ ' + esc(t('bar_title')) + '</strong>' +
            '<span class="af-pill ' + (state.connected ? 'ok' : 'warn') + '">' + esc(state.connected ? t('connected') : t('not_connected')) + '</span>' +
            '<span class="af-spacer"></span>' +
            (n ? '<button type="button" class="p-btn xs primary" id="afAll">' + esc(t('run_all_n', { n: n })) + '</button>' : '') +
            '<button type="button" class="p-btn xs outline" id="afHistory">Execution report</button><button type="button" class="p-btn xs outline" id="afConn">' + esc(state.connected ? t('check_btn') : t('connect_btn')) + '</button></div>';
        var all = document.getElementById('afAll'); if (all) all.onclick = runAll;
        document.getElementById('afConn').onclick = openConnect;
        document.getElementById('afHistory').onclick = openHistory;
    }

    // ------------------------------------------------------------------ نوافذ
    function modal(html, onClose) {
        var bg = document.createElement('div'); bg.className = 'af-modal-bg';
        bg.innerHTML = '<div class="af-modal" role="dialog" aria-modal="true">' + html + '</div>';
        document.body.appendChild(bg);
        var close = function () { bg.remove(); if (onClose) onClose(); };
        bg.addEventListener('mousedown', function (e) { if (e.target === bg) close(); });
        bg.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
        return { el: bg, close: close };
    }

    function connectBlock() {
        var plug = state.pluginUrl ? '<details style="margin-top:14px;"><summary>Alternative: install the WordPress plugin</summary>' +
            '<p class="af-sub">Use one installation method only. If you already use WPCode, keep using the code above.</p>' +
            '<ol class="af-steps"><li>' + esc(t('p1')) + '</li><li>' + esc(t('p2')) + '</li></ol>' +
            '<a class="p-btn outline xs" href="' + esc(state.pluginUrl) + '" download>' + esc(t('plugin_btn')) + '</a></details>' : '';
        return '<div dir="ltr" style="text-align:left;"><h3>' + esc(t('snippet_title')) + '</h3><p class="af-sub">' + esc(t('snippet_desc')) + '</p>' +
            '<ol class="af-steps"><li>' + esc(t('s1')) + '</li><li>' + esc(t('s2')) + '</li><li>' + esc(t('s3')) + '</li><li>' + esc(t('s4')) + '</li></ol>' +
            '<div class="af-code"><pre dir="ltr" id="afSnippet" style="text-align:left;user-select:text;">' + esc(state.snippet) + '</pre><button type="button" class="af-copy" id="afCopy">' + esc(t('copy')) + '</button></div>' +
            '<p class="af-sub">' + esc(t('seo_note')) + '</p>' + plug + '<div id="afConnMsg" class="af-msg" hidden></div></div>';
    }
    function wireConnect(m) {
        var cp = m.el.querySelector('#afCopy');
        if (cp) cp.onclick = function () {
            var txt = state.snippet, done = function () { cp.textContent = t('copied'); setTimeout(function () { cp.textContent = t('copy'); }, 1500); };
            var failed = function () { toast('Could not copy automatically. Select the code and copy it manually.', 'error'); };
            if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(txt).then(done, failed); else failed();
        };
    }

    async function recheck(msgEl) {
        state.loaded = false; await loadStatus();
        if (msgEl) { msgEl.hidden = false; msgEl.className = 'af-msg ' + (state.connected ? 'ok' : 'warn'); msgEl.textContent = state.connected ? t('conn_ok') : t('conn_wait'); }
    }

    function openConnect() {
        var m = modal(connectBlock() + '<div class="af-actions"><button type="button" class="p-btn outline" data-x>' + esc(t('cancel')) + '</button><button type="button" class="p-btn primary" id="afCheck">' + esc(t('check_btn')) + '</button></div>');
        wireConnect(m);
        m.el.querySelector('[data-x]').onclick = m.close;
        m.el.querySelector('#afCheck').onclick = function () { recheck(m.el.querySelector('#afConnMsg')); };
    }

    // ------------------------------------------------------------------ معاينة ثم تنفيذ
    async function openPreview(card) {
        var id = card.dataset.fixId;
        var m = modal('<p class="af-sub">' + esc(t('loading')) + '</p><div class="ap-skel" style="height:80px"></div>');
        var r = await api('/api/website-optimizer/fixes/' + id + '/edge-preview', 'POST', {});
        if (!r.success) {
            m.el.querySelector('.af-modal').innerHTML = '<div class="af-msg err">' + esc(errText(r)) + '</div><div class="af-actions"><button type="button" class="p-btn outline" data-x>' + esc(t('cancel')) + '</button></div>';
            m.el.querySelector('[data-x]').onclick = m.close; return;
        }
        var d = r.data, isJson = d.field === 'og' || d.field === 'jsonld', val = d.value;
        if (isJson) { try { val = JSON.stringify(JSON.parse(d.value), null, 2); } catch (e) { /* نسيبها */ } }
        var warns = (d.warnings || []).map(function (w) { return '<li>' + esc(t('w_' + w)) + '</li>'; }).join('');
        var hsts = d.risk === 'high';
        var html = '<h3>' + esc(d.label) + '</h3><p class="af-sub">' + esc(t('preview_title')) + ' · ' + esc(t('editable')) + '</p>' +
            '<textarea id="afValue" rows="' + (isJson || d.field === 'llms_txt' ? 9 : 3) + '" dir="auto" spellcheck="false">' + esc(val) + '</textarea>' +
            (warns ? '<ul class="af-warn">' + warns + '</ul>' : '') +
            (hsts ? '<label class="af-ack"><input type="checkbox" id="afAck"> ' + esc(t('hsts_ack')) + '</label>' : '') +
            (!state.connected ? '<div class="af-msg warn">' + esc(t('not_connected')) + ' — ' + esc(t('applied_wait')) + '</div>' : '') +
            '<div id="afErr" class="af-msg err" hidden></div>' +
            '<div class="af-actions"><button type="button" class="p-btn outline" data-x>' + esc(t('cancel')) + '</button><button type="button" class="p-btn primary" id="afGo"' + (hsts ? ' disabled' : '') + '>' + esc(t('apply')) + '</button></div>';
        m.el.querySelector('.af-modal').innerHTML = html;
        m.el.querySelector('[data-x]').onclick = m.close;
        var go = m.el.querySelector('#afGo');
        if (hsts) m.el.querySelector('#afAck').onchange = function (e) { go.disabled = !e.target.checked; };
        go.onclick = async function () {
            go.disabled = true;
            var v = m.el.querySelector('#afValue').value;
            if (isJson) { try { v = JSON.stringify(JSON.parse(v)); } catch (e) { var er = m.el.querySelector('#afErr'); er.hidden = false; er.textContent = t('e_invalid_value'); go.disabled = false; return; } }
            var res = await api('/api/website-optimizer/fixes/' + id + '/edge-apply', 'POST', { value: v, confirm_risk: hsts ? true : undefined });
            if (!res.success) { var e2 = m.el.querySelector('#afErr'); e2.hidden = false; e2.textContent = errText(res); go.disabled = false; return; }
            markApplied(card); state.connected = !!res.data.connected; renderBar(countPending());
            toast(state.connected ? t('applied_ok') : t('applied_wait'), 'success'); m.close();
        };
    }

    function markApplied(card) {
        reportRevision++; delete card.dataset.verificationState;
        card.dataset.status = 'applied'; card.dataset.appliedBy = 'edge';
        var pill = card.querySelector('[data-fix-status]'); if (pill) { pill.className = 'pill green'; pill.textContent = t('auto_applied'); }
        decorate();
    }
    function markPending(card) {
        reportRevision++;
        card.dataset.status = 'pending'; card.dataset.appliedBy = '';
        var pill = card.querySelector('[data-fix-status]'); if (pill) { pill.className = 'pill orange'; pill.textContent = '…'; }
        decorate();
    }
    function countPending() { return cards().filter(function (c) { var cap = state.caps && state.caps[c.dataset.checkKey]; return cap && cap.risk === 'low' && cap.field !== 'robots' && c.dataset.status !== 'applied'; }).length; }

    async function undo(card) {
        var r = await api('/api/website-optimizer/fixes/' + card.dataset.fixId + '/edge-rollback', 'POST', {});
        if (!r.success) { toast(errText(r), 'error'); return; }
        if(r.data.status==='applied')markApplied(card);else markPending(card); toast('Approval rolled back. Verify the public page after cache refresh.', 'success');
    }


    var reportLoading=false, reportSite='', reportRows=[], reportRevision=0;
    function formatted(v) { return v == null ? 'Not captured for this older approval' : (typeof v === 'string' ? v : JSON.stringify(v,null,2)); }
    function reportMarkup(row) {
        return '<div dir="ltr" style="text-align:left;"><p>'+esc(row.benefit || '')+'</p><p><strong>Status:</strong> '+esc(row.state)+' · '+esc(row.checked_at || 'Not checked yet')+'</p><p>'+esc(row.reason || 'Saved values must be verified on the public website.')+'</p><details><summary>Before, approved value and public result</summary><p>Before</p><pre style="white-space:pre-wrap;overflow-wrap:anywhere;">'+esc(formatted(row.before))+'</pre><p>Approved value</p><pre style="white-space:pre-wrap;overflow-wrap:anywhere;">'+esc(formatted(row.expected_value))+'</pre><p>Last public result</p><pre style="white-space:pre-wrap;overflow-wrap:anywhere;">'+esc(row.checked_at ? formatted(row.observed) : 'Not checked yet')+'</pre></details></div>';
    }
    async function loadReport() {
        if(reportLoading || !state.siteId)return;
        reportLoading=true;var site=state.siteId, revision=reportRevision;
        var response=await api('/api/website-optimizer/edge/report?website_id='+encodeURIComponent(site));reportLoading=false;
        if(revision!==reportRevision || site!==state.siteId){loadReport();return;}
        if(!response.success || site!==state.siteId)return;
        reportSite=site;reportRows=response.data.changes || [];
        cards().forEach(function(card){
            if(card.dataset.appliedBy!=='edge' || card.dataset.status!=='applied')return;
            var row=reportRows.find(function(r){return String(r.fix_id)===card.dataset.fixId && +r.is_active===1;});if(!row)return;
            var panel=card.querySelector('.af-report');if(!panel){panel=document.createElement('div');panel.className='af-report';card.appendChild(panel);}panel.innerHTML=reportMarkup(row);
            var pill=card.querySelector('[data-fix-status]');card.dataset.verificationState=row.state;
            if(pill){pill.className='pill '+(row.state==='verified'?'green':'orange');pill.textContent=row.state==='verified'?'Verified on website':row.state==='saved'?'Saved — not verified':row.state;}
        });
    }
    async function verifyCard(card,button) {
        button.disabled=true;
        var r=await api('/api/website-optimizer/fixes/'+card.dataset.fixId+'/edge-verify','POST',{});
        button.disabled=false;
        if(!r.success){toast(errText(r),'error');return;}
        toast(r.data.reason,r.data.state==='verified'?'success':'warning');await loadReport();
    }
    async function openHistory() {
        await loadReport();var content=reportSite===state.siteId?reportRows:[];
        var m=modal('<div dir="ltr" style="text-align:left;"><h3>Execution report</h3><p>These are approved changes. Only Verified confirms a match on the public page at the displayed time. Ranking improvements are not guaranteed.</p>'+ (content.length?content.map(function(row){return '<details style="margin:12px 0;"><summary>'+esc(row.field)+' — '+esc(row.state)+'</summary>'+reportMarkup(row)+'</details>';}).join(''):'<p>No approved changes found, or the report could not be loaded.</p>')+'</div><div class="af-actions"><button type="button" class="p-btn outline" data-x>Close</button></div>');m.el.querySelector('[data-x]').onclick=m.close;
    }
    T.e_coming_soon_page='The public page is a coming-soon page. Publish the real page and run a new audit before applying fixes.';
    T.e_indexing_restricted='This page requests no indexing. Review indexing settings before changing them.';
    T.e_operation_in_progress='Another operation is updating this field. Please retry.';

    // ------------------------------------------------------------------ تنفيذ الكل
    async function runAll() {
        if (!window.confirm(t('confirm_all'))) return;
        var first = cards().find(function (c) { return c.dataset.auditId; });
        var auditId = first ? +first.dataset.auditId : 0;
        var btn = document.getElementById('afAll'); if (btn) { btn.disabled = true; btn.textContent = t('loading'); }
        if (!auditId) {
            // كروت اترسمت من تدقيق جديد من غير audit_id: نجيبه من أي إصلاح بدل ما نقف بصمت
            var anyCard = cards()[0];
            if (anyCard && siteSel && siteSel.value) {
                var fr = await api('/api/website-optimizer/fixes?website_id=' + encodeURIComponent(siteSel.value));
                var f0 = fr && fr.success && fr.data && (fr.data.fixes || [])[0];
                if (f0 && f0.audit_id) auditId = +f0.audit_id;
            }
        }
        if (!auditId) { toast(T.e_default, 'error'); renderBar(countPending()); return; }
        var r = await api('/api/website-optimizer/edge-apply-all', 'POST', { audit_id: auditId });
        if (!r.success) { toast(errText(r), 'error'); renderBar(countPending()); return; }
        var failed = [];
        (r.data.results || []).forEach(function (x) {
            if (x.ok) { var c = list.querySelector('.wo-fix-card[data-fix-id="' + x.id + '"]'); if (c) markApplied(c); }
            else failed.push(x);
        });
        state.connected = !!r.data.connected;
        renderBar(countPending());
        if (r.data.applied > 0) toast(t('all_done', { n: r.data.applied }) + '. ' + t('after_hint'), 'success');
        if (failed.length) {
            var msgs = {}; failed.forEach(function (x) { var m = T['e_' + x.reason] || T.e_default; msgs[m] = (msgs[m] || 0) + 1; });
            toast(Object.keys(msgs).map(function (m) { return m + ' (' + msgs[m] + ')'; }).join(' | '), r.data.applied > 0 ? 'warning' : 'error');
        } else if (!r.data.applied) { toast(T.e_default, 'error'); }
        if (!state.connected) openConnect();
    }

    // ------------------------------------------------------------------ مراقبة رسم الكروت
    var timer = null;
    new MutationObserver(function () { clearTimeout(timer); timer = setTimeout(function () { if (state.caps) decorate(); else loadStatus(); }, 60); }).observe(list, { childList: true });
    if (siteSel) siteSel.addEventListener('change', function () { state.loaded = false; state.caps = null; });
    if (cards().length) loadStatus();
})();
