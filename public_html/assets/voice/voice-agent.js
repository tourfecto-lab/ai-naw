(function () {
  'use strict';
  var BOOT = window.VOICE_BOOT || {};
  var root = document.getElementById('voice-app');
  if (!root) return;
  var I18N = {
    en: {
      overview: 'Overview', numbers: 'Phone Numbers', assistants: 'AI Assistants', incoming: 'Incoming Calls', outgoing: 'Outgoing Campaigns',
      history: 'Call History', crm: 'Booking & CRM Integration', whatsapp: 'WhatsApp Integration', analytics: 'Analytics', billing: 'Billing & Usage',
      settings: 'Settings', done: 'Done', admin: 'Voice Administration', loading: 'Loading…', save: 'Save', none: 'Nothing here yet.', failed: 'Could not load this section.',
      sandbox: 'Test mode is on: no real numbers are bought and no real calls are placed.', notEnabled: 'AI Voice Agent is not included in your plan.', upgrade: 'See plans and upgrade',
      saved: 'Saved', calls: 'Calls', booked: 'Bookings', spent: 'Spent this month', budget: 'Budget'
    },
    ar: {
      overview: 'نظرة عامة', numbers: 'أرقام الهاتف', assistants: 'المساعدون الأذكياء', incoming: 'المكالمات الواردة', outgoing: 'حملات المكالمات الصادرة',
      history: 'سجل المكالمات', crm: 'الحجوزات وإدارة العملاء', whatsapp: 'ربط واتساب', analytics: 'التحليلات', billing: 'الفواتير والاستخدام',
      settings: 'الإعدادات', done: 'تم', admin: 'إدارة الصوت', loading: 'جارٍ التحميل…', save: 'حفظ', none: 'لا يوجد شيء بعد.', failed: 'تعذر تحميل هذا القسم.',
      sandbox: 'وضع التجربة مفعّل: لا يتم شراء أرقام حقيقية ولا إجراء مكالمات حقيقية.', notEnabled: 'ميزة المساعد الصوتي غير متضمنة في باقتك.', upgrade: 'شوف الباقات وارقّي',
      saved: 'تم الحفظ', calls: 'المكالمات', booked: 'الحجوزات', spent: 'المصروف هذا الشهر', budget: 'الميزانية'
    }
  };
  var T = I18N[BOOT.lang] || I18N.en;
  function t(k) { return T[k] || I18N.en[k] || k; }
  function esc(v) { var d = document.createElement('div'); d.textContent = v == null ? '' : String(v); return d.innerHTML; }
  function csrf() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; }
  function api(path, body) {
    var opt = { credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (body !== undefined) {
      opt.method = 'POST';
      opt.headers['Content-Type'] = 'application/json';
      opt.headers['X-CSRF-Token'] = csrf();
      opt.body = JSON.stringify(body);
    }
    return fetch(path, opt).then(function (r) { return r.json().catch(function () { return { success: false, message: 'Bad response' }; }); })
      .then(function (j) { if (!j || j.success === false) { throw new Error((j && (j.error || j.message)) || t('failed')); } return j.data || {}; });
  }
  function badge(s) { var c = /^(active|ok|completed|charged|live|booked)$/.test(s) ? 'ok' : /(fail|block|exhaust|error|bad)/.test(s) ? 'bad' : 'warn'; return '<span class="va-badge ' + c + '">' + esc(s) + '</span>'; }
  function cards(items) { return '<div class="va-cards">' + items.map(function (i) { return '<div class="va-card"><span class="va-muted">' + esc(i[0]) + '</span><b>' + esc(i[1]) + '</b></div>'; }).join('') + '</div>'; }
  function table(cols, rows) {
    if (!rows || !rows.length) return '<p class="va-muted">' + esc(t('none')) + '</p>';
    return '<div class="va-tablewrap"><table><thead><tr>' + cols.map(function (c) { return '<th>' + esc(c[0]) + '</th>'; }).join('') + '</tr></thead><tbody>' +
      rows.map(function (r) { return '<tr>' + cols.map(function (c) { var v = typeof c[1] === 'function' ? c[1](r) : esc(r[c[1]]); return '<td>' + v + '</td>'; }).join('') + '</tr>'; }).join('') + '</tbody></table></div>';
  }
  function settingsForm(s, fields) {
    return '<form class="va-form" data-settings>' + fields.map(function (f) {
      var v = s[f[0]] == null ? '' : s[f[0]];
      if (f[2] === 'bool') return '<label>' + esc(f[1]) + '<select name="' + f[0] + '"><option value="1"' + (+v ? ' selected' : '') + '>On</option><option value="0"' + (+v ? '' : ' selected') + '>Off</option></select></label>';
      if (f[2] && f[2].select) return '<label>' + esc(f[1]) + '<select name="' + f[0] + '">' + f[2].select.map(function (o) { return '<option' + (o === v ? ' selected' : '') + '>' + esc(o) + '</option>'; }).join('') + '</select></label>';
      return '<label>' + esc(f[1]) + '<input name="' + f[0] + '" value="' + esc(v) + '"></label>';
    }).join('') + '<div><button class="va-btn" type="submit">' + esc(t('save')) + '</button> <span class="va-muted" data-msg></span></div></form>';
  }
  function bindSettings(el) {
    var f = el.querySelector('[data-settings]'); if (!f) return;
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var body = {}; new FormData(f).forEach(function (v, k) { body[k] = v; });
      var msg = f.querySelector('[data-msg]');
      api('/api/voice-agent/settings', body).then(function () { msg.textContent = t('saved'); msg.className = 'va-muted'; })
        .catch(function (err) { msg.textContent = err.message; msg.className = 'va-err'; });
    });
  }
  function bars(items) {
    if (!items || !items.length) return '<p class="va-muted">' + esc(t('none')) + '</p>';
    var max = Math.max.apply(null, items.map(function (i) { return +i.count || 0; })) || 1;
    return '<div class="va-bars">' + items.map(function (i) {
      return '<div class="va-bar"><span class="va-bar-l">' + esc(i.key) + '</span><span class="va-bar-t"><span class="va-bar-f" style="width:' + Math.round((+i.count || 0) / max * 100) + '%"></span></span><span class="va-bar-n">' + esc(i.count) + '</span></div>';
    }).join('') + '</div>';
  }

  function opts(list, cur) { return list.map(function (o) { var v = Array.isArray(o) ? o[0] : o, l = Array.isArray(o) ? o[1] : o; return '<option value="' + esc(v) + '"' + (String(v) === String(cur) ? ' selected' : '') + '>' + esc(l) + '</option>'; }).join(''); }
  function field(name, label, kind, val, extra) {
    var v = val == null ? '' : val;
    if (kind === 'select') return '<label>' + esc(label) + '<select name="' + name + '">' + opts(extra, v) + '</select></label>';
    if (kind === 'textarea') return '<label>' + esc(label) + '<textarea name="' + name + '" rows="3">' + esc(v) + '</textarea></label>';
    return '<label>' + esc(label) + '<input name="' + name + '" value="' + esc(v) + '"' + (extra || '') + '></label>';
  }
  function collect(f) { var b = {}; new FormData(f).forEach(function (v, k) { b[k] = v; }); return b; }
  function note(el, text, bad) { var m = el.querySelector('[data-msg]'); if (m) { m.textContent = text; m.className = bad ? 'va-err' : 'va-muted'; } }
  var ROLES = ['reception', 'sales', 'booking', 'support'], TONES = ['friendly', 'professional', 'formal', 'warm'], LANGS = ['en', 'ar', 'fr', 'de', 'es', 'it', 'ru', 'tr'], TYPES = ['local', 'national', 'toll_free', 'mobile'];

  function assistantsTab() {
    return api('/api/voice-agent/agents').then(function (r) {
      window.__vaAgents = r.agents || [];
      return '<h3>Assistants</h3>' + table([['Name', 'name'], ['Role', 'role'], ['Language', 'language'], ['Status', function (a) { return badge(a.status); }],
        ['', function (a) { return '<button class="va-btn sec" data-edit="' + (+a.id) + '">Edit</button>'; }]], r.agents) +
        '<h3 data-title>Add an assistant</h3><form class="va-form" data-agent><input type="hidden" name="id" value="">' +
        field('name', 'Name', 'text', '', ' required maxlength="120"') + field('role', 'Role', 'select', 'reception', ROLES) + field('tone', 'Tone', 'select', 'friendly', TONES) +
        field('language', 'Main language', 'select', 'en', LANGS) + field('languages', 'Other languages it may switch to (comma separated)', 'text', '') +
        field('status', 'Status', 'select', 'draft', ['draft', 'active', 'paused']) + field('opening_text', 'Opening sentence (the AI notice is added automatically)', 'textarea', '') +
        field('instructions', 'Extra instructions (prices are never invented; this only adds rules)', 'textarea', '') +
        '<div><button class="va-btn" type="submit">Save assistant</button> <span data-msg></span></div></form>';
    });
  }
  function bindAssistants(el) {
    var f = el.querySelector('[data-agent]');
    el.querySelectorAll('[data-edit]').forEach(function (b) {
      b.addEventListener('click', function () {
        var a = (window.__vaAgents || []).filter(function (x) { return +x.id === +b.getAttribute('data-edit'); })[0]; if (!a) return;
        ['id', 'name', 'role', 'tone', 'language', 'languages', 'status', 'opening_text', 'instructions'].forEach(function (k) { if (f.elements[k]) f.elements[k].value = a[k] == null ? '' : a[k]; });
        el.querySelector('[data-title]').textContent = 'Edit assistant';
        f.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var body = collect(f), id = body.id; delete body.id;
      api(id ? '/api/voice-agent/agents/' + (+id) : '/api/voice-agent/agents', body).then(function () { render(); }).catch(function (err) { note(el, err.message, true); });
    });
  }


  var OWN_FIELDS = { twilio: [['account_sid', 'Account SID'], ['auth_token', 'Auth Token']], telnyx: [['api_key', 'API key'], ['connection_id', 'Connection ID'], ['public_key', 'Webhook public key']] };
  function ownAccountPanel(allProvs) {
    var allowed = allProvs.some(function (p) { return p.own_account_allowed; });
    if (!allowed) return '<details class="va-card"><summary><b>Use my own provider account</b></summary><p class="va-muted">Your platform administrator has not enabled this. Ask them to allow own provider accounts.</p></details>';
    var first = allProvs[0].provider;
    return '<details class="va-card"><summary><b>Use my own provider account</b> (you pay the provider directly)</summary><p class="va-muted">Keys are stored encrypted and never shown again.</p>' +
      '<form class="va-form" data-own>' + field('provider', 'Provider', 'select', first, allProvs.map(function (p) { return [p.provider, p.provider]; })) +
      '<div data-own-fields class="va-form" style="margin:0"></div>' +
      '<div><button class="va-btn" type="submit">Save and test</button> <span data-own-msg class="va-muted"></span></div></form>' +
      allProvs.filter(function (p) { return p.mode === 'own'; }).map(function (p) { return '<p>' + esc(p.provider) + ': ' + badge(p.status) + ' <button class="va-btn sec" data-own-remove="' + esc(p.provider) + '">Remove my keys</button></p>'; }).join('') + '</details>';
  }
  function bindOwn(el) {
    var f = el.querySelector('[data-own]'); if (!f) return;
    var box = f.querySelector('[data-own-fields]'), sel = f.elements.provider, msg = f.querySelector('[data-own-msg]');
    function draw() { box.innerHTML = (OWN_FIELDS[sel.value] || []).map(function (x) { return '<label>' + esc(x[1]) + '<input name="' + x[0] + '" type="password" autocomplete="off" required></label>'; }).join(''); }
    sel.addEventListener('change', draw); draw();
    f.addEventListener('submit', function (e) {
      e.preventDefault(); var b = collect(f), pr = b.provider; delete b.provider; msg.textContent = '…';
      api('/api/voice-agent/providers/' + encodeURIComponent(pr) + '/credentials', b).then(function () { return api('/api/voice-agent/providers/' + encodeURIComponent(pr) + '/test', {}); })
        .then(function (d) { var r = d.result || {}; window.__vaNote = r.ok === false ? 'Saved, but the connection test failed: ' + (r.detail || 'check the keys') : 'Saved. Connection OK'; render(); }).catch(function (er) { msg.textContent = er.message; msg.className = 'va-err'; });
    });
    el.querySelectorAll('[data-own-remove]').forEach(function (b) { b.addEventListener('click', function () { if (window.confirm('Remove your provider keys?')) api('/api/voice-agent/providers/' + encodeURIComponent(b.getAttribute('data-own-remove')) + '/remove', {}).then(function () { render(); }).catch(function (e) { note(el, e.message, true); }); }); });
  }

  function numbersTab() {
    return Promise.all([api('/api/voice-agent/numbers'), api('/api/voice-agent/agents'), api('/api/voice-agent/providers'), api('/api/voice-agent/markets')]).then(function (r) {
      var agents = r[1].agents || [], allProvs = r[2].providers || [], provs = allProvs.filter(function (p) { return p.available; }), markets = r[3].markets || [];
      window.__vaProvs = provs;
      var agentOpts = [['', '— none —']].concat(agents.map(function (a) { return [a.id, a.name]; }));
      var cols = [['Number', 'e164'], ['Country', 'country'], ['Provider', 'provider'], ['Status', function (n) { return badge(n.status + (n.live_usable ? '' : '')); }],
        ['Assistant', function (n) { return '<select data-assign="' + (+n.id) + '">' + opts(agentOpts, n.agent_id || '') + '</select>'; }],
        ['', function (n) { return (n.status === 'pending_verification' && /^(forward|sip)$/.test(n.connect_method || '') ? '<button class="va-btn" data-verify="' + (+n.id) + '">Verify I own this number</button> ' : '') + '<button class="va-btn sec" data-test="' + (+n.id) + '">Test connection</button> <button class="va-btn sec" data-default="' + (+n.id) + '">Make default</button> <button class="va-btn sec" data-release="' + (+n.id) + '">Remove number</button>'; }]];
      var urls = provs.map(function (p) { return '<li><b>' + esc(p.provider) + '</b><br>Voice URL: <code>' + esc(p.webhook_urls.inbound) + '</code><br>Status URL: <code>' + esc(p.webhook_urls.status) + '</code></li>'; }).join('');
      return '<p data-msg></p><div data-info></div>' + table(cols, r[0].numbers) +
        '<details class="va-card"><summary><b>Get a new number</b> (you approve the exact price before anything is bought)</summary><form class="va-form" data-search>' +
        field('country', 'Country', 'select', markets.length ? markets[0].iso : '', markets.map(function (m) { return [m.iso, m.name]; })) + field('type', 'Type', 'select', 'local', TYPES) +
        field('provider', 'Provider', 'select', provs.length ? provs[0].provider : '', provs.map(function (p) { return [p.provider, p.provider]; })) +
        '<div><button class="va-btn" type="submit"' + (provs.length ? '' : ' disabled') + '>Search</button></div></form>' + (provs.length ? '' : '<p class="va-muted">No phone provider is available yet.</p>') + '<div data-results></div><div data-quote></div></details>' +
        '<details class="va-card"><summary><b>Use a number I already own</b></summary><form class="va-form" data-connect>' + field('number', 'Number (E.164, e.g. +201001234567)', 'text', '', ' required') +
        field('method', 'How it reaches us', 'select', 'forward', [['provider_native', 'It is already with the provider'], ['forward', 'Forward calls from my carrier'], ['sip', 'SIP trunk'], ['port', 'Port it over']]) +
        field('country', 'Country', 'select', '', [['', 'Detect from the number']].concat(markets.map(function (m) { return [m.iso, m.name]; }))) +
        field('provider', 'Provider (only if the number is in your own provider account)', 'select', '', [['', '—']].concat(provs.map(function (p) { return [p.provider, p.provider]; }))) +
        '<div><button class="va-btn" type="submit">Add number</button></div></form>' +
        (urls ? '<p class="va-muted">For a number you own at the provider, set these in the provider console:</p><ul>' + urls + '</ul>' : '') + '</details>' + (allProvs.length ? ownAccountPanel(allProvs) : '');
    });
  }
  function bindNumbers(el) {
    bindOwn(el);
    function post(path, body) { return api(path, body || {}).then(function (d) { window.__vaNote = (d.simulated ? 'Test mode: simulated, nothing was bought or changed at the provider. ' : '') + t('done'); window.__vaInfo = d.steps ? { steps: d.steps, target: d.target } : null; render(); }).catch(function (e) { note(el, e.message, true); }); }
    el.querySelectorAll('[data-assign]').forEach(function (s) { s.addEventListener('change', function () { post('/api/voice-agent/numbers/' + (+s.getAttribute('data-assign')) + '/assign', { agent_id: s.value }); }); });
    el.querySelectorAll('[data-test]').forEach(function (b) { b.addEventListener('click', function () { api('/api/voice-agent/numbers/' + (+b.getAttribute('data-test')) + '/test', {}).then(function (d) { note(el, 'Status: ' + String(d.state || d.status || 'unknown').replace(/_/g, ' ') + (d.detail ? ' - ' + d.detail : '')); }).catch(function (e) { note(el, e.message, true); }); }); });
    el.querySelectorAll('[data-default]').forEach(function (b) { b.addEventListener('click', function () { post('/api/voice-agent/numbers/' + (+b.getAttribute('data-default')) + '/default'); }); });
    el.querySelectorAll('[data-release]').forEach(function (b) { b.addEventListener('click', function () { if (window.confirm('Remove this number from your account? You will lose it.')) post('/api/voice-agent/numbers/' + (+b.getAttribute('data-release')) + '/release', { approve_release: true }); }); });
    el.querySelectorAll('[data-verify]').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = +b.getAttribute('data-verify');
        if (!window.confirm('We will call this number to read you a 6-digit code (a real call when live mode is on). Continue?')) return;
        api('/api/voice-agent/numbers/' + id + '/verify/start', { approve_call: true }).then(function (d) {
          var code = window.prompt('Enter the 6-digit code you heard on the call.' + (d.sandbox_code ? ' (Test mode: no call was placed. Your test code is ' + d.sandbox_code + ')' : ''));
          if (!code) return;
          return api('/api/voice-agent/numbers/' + id + '/verify/confirm', { code: code }).then(function () { window.__vaNote = 'Verified'; render(); });
        }).catch(function (e) { note(el, e.message, true); });
      });
    });
    var c = el.querySelector('[data-connect]');
    c.addEventListener('submit', function (e) { e.preventDefault(); post('/api/voice-agent/numbers/connect', collect(c)); });
    var s = el.querySelector('[data-search]'), res = el.querySelector('[data-results]'), qbox = el.querySelector('[data-quote]');
    s.addEventListener('submit', function (e) {
      e.preventDefault(); var b = collect(s); qbox.innerHTML = ''; res.innerHTML = '<p class="va-muted">Searching…</p>';
      api('/api/voice-agent/providers/' + encodeURIComponent(b.provider) + '/numbers?country=' + encodeURIComponent(b.country) + '&type=' + encodeURIComponent(b.type)).then(function (d) {
        res.innerHTML = table([['Number', 'number'], ['Monthly (provider)', function (n) { return n.monthly_cost == null ? 'no price' : esc(n.monthly_cost) + ' ' + esc(n.currency); }], ['', function (n) { return n.voice && n.monthly_cost != null ? '<button class="va-btn sec" data-q="' + esc(n.number) + '">Get exact quote</button>' : ''; }]], d.numbers);
        res.querySelectorAll('[data-q]').forEach(function (qb) {
          qb.addEventListener('click', function () {
            api('/api/voice-agent/numbers/quote', { provider: b.provider, number: qb.getAttribute('data-q'), country: b.country, type: b.type }).then(function (r) {
              var q = r.quote;
              qbox.innerHTML = '<div class="va-note"><b>' + esc(q.number) + '</b><br>Monthly: ' + esc(q.monthly_cost) + ' ' + esc(q.currency) + ' · Setup: ' + esc(q.setup_cost) + '<br><b>Charged now: ' + esc(q.first_charge) + ' ' + esc(q.currency) + '</b><br>' + esc(q.usage_note) +
                '<br>Valid for ' + Math.round(q.expires_in_seconds / 60) + ' minutes.' + (q.requirements && q.requirements.needs_ack ? '<br><label><input type="checkbox" data-ack> I have read the regulatory requirements for this number</label>' : '') +
                '<br><button class="va-btn" data-buy>Approve ' + esc(q.first_charge) + ' ' + esc(q.currency) + ' and get this number</button></div>';
              qbox.querySelector('[data-buy]').addEventListener('click', function () {
                var ack = qbox.querySelector('[data-ack]');
                post('/api/voice-agent/numbers/orders/' + (+q.order_id) + '/approve', { accept_total: q.first_charge, requirements_ack: ack ? ack.checked : true });
              });
            }).catch(function (er) { qbox.innerHTML = '<p class="va-err">' + esc(er.message) + '</p>'; });
          });
        });
      }).catch(function (er) { res.innerHTML = '<p class="va-err">' + esc(er.message) + '</p>'; });
    });
  }
  function money(v) { return '$' + (Number(v) || 0).toFixed(2); }

  var TABS = [
    ['overview', function () {
      return Promise.all([api('/api/voice-agent/status'), api('/api/voice-agent/settings'), api('/api/voice-agent/analytics'), api('/api/voice-agent/costs')]).then(function (r) {
        var a = r[2], c = r[3].costs || {};
        return (r[1].settings && +r[1].settings.sandbox_mode ? '<div class="va-note">' + esc(t('sandbox')) + '</div>' : '') +
          cards([[t('calls'), (a.calls && a.calls.total) || 0], [t('booked'), (a.bookings && a.bookings.count) || 0], [t('spent'), money(c.month_usd)], [t('budget'), c.budget_usd == null ? '—' : money(c.budget_usd)]]);
      });
    }],
    ['numbers', numbersTab, bindNumbers],
    ['assistants', assistantsTab, bindAssistants],
    ['incoming', function () {
      return api('/api/voice-agent/calls?direction=inbound&limit=50').then(function (r) { return callsTable(r.calls); });
    }],
    ['outgoing', function () {
      return Promise.all([api('/api/voice-agent/outbound/campaigns'), api('/api/voice-agent/outbound/jobs')]).then(function (r) {
        return '<h3>Campaigns</h3>' + table([['Name', 'name'], ['Purpose', 'purpose'], ['Status', function (c) { return badge(c.status); }]], r[0].campaigns) +
          '<h3>Queue</h3>' + table([['To', 'to_e164'], ['Purpose', 'purpose'], ['Status', function (j) { return badge(j.status + (j.blocked_reason ? ': ' + j.blocked_reason : '')); }]], r[1].jobs);
      });
    }],
    ['history', function () { return api('/api/voice-agent/calls?limit=100').then(function (r) { return callsTable(r.calls); }); }],
    ['crm', function () {
      return api('/api/voice-agent/settings').then(function (r) {
        return settingsForm(r.settings || {}, [['booking_mode', 'Booking mode', { select: ['request_only', 'create_pending', 'auto_confirm'] }], ['booking_max_amount', 'Max auto-confirmed amount'], ['payment_link_pct', 'Payment link deposit %']]);
      });
    }],
    ['whatsapp', function () {
      return api('/api/voice-agent/settings').then(function (r) {
        return settingsForm(r.settings || {}, [['post_call_whatsapp', 'WhatsApp follow-up after calls', 'bool'], ['post_call_email', 'Email fallback', 'bool'], ['post_call_wa_template', 'Approved template name'], ['post_call_wa_template_lang', 'Template language']]);
      });
    }],
    ['analytics', function () {
      return api('/api/voice-agent/analytics').then(function (a) {
        var c = a.calls || {}, b = a.bookings || {}, k = a.cost || {}, roi = a.roi || {};
        return cards([[t('calls'), c.total || 0], ['Minutes', c.minutes || 0], ['Missed rate', c.missed_rate == null ? '—' : c.missed_rate + '%'], [t('booked'), b.count || 0],
          ['Conversion', b.conversion == null ? '—' : b.conversion + '%'], ['Total cost', money(k.total_usd)]]) +
          (roi.note ? '<p class="va-muted">' + esc(roi.note) + '</p>' : '') +
          '<h3>Call outcomes</h3>' + bars(Object.keys(a.outcomes || {}).map(function (k) { return { key: k, count: a.outcomes[k] }; })) +
          '<h3>Top objections</h3>' + bars(a.objections) +
          '<h3>Why leads were lost</h3>' + bars(a.loss_reasons);
      });
    }],
    ['billing', function () {
      return api('/api/voice-agent/billing').then(function (r) {
        var s = r.statement || {}, b = r.budget || {};
        return cards([[t('spent'), money(s.total_usd)], [t('budget'), b.budget_usd == null ? '—' : money(b.budget_usd)], ['Used', b.percent == null ? '—' : b.percent + '%'], ['State', b.state || '—']]) +
          table([['Item', 'k'], ['Quantity', 'q'], ['Charge', 'u']], Object.keys(s.lines || {}).map(function (k) { return { k: k, q: s.lines[k].quantity, u: money(s.lines[k].usd) }; }));
      });
    }],
    ['settings', function () {
      return api('/api/voice-agent/settings').then(function (r) {
        return settingsForm(r.settings || {}, [['enabled', 'Voice agent enabled', 'bool'], ['default_language', 'Default language'], ['monthly_budget_usd', 'Monthly budget (USD)'], ['budget_policy', 'When budget is spent', { select: ['block', 'warn'] }],
          ['daily_minutes_cap', 'Daily minutes cap (0 = none)'], ['recording_enabled', 'Call recording (needs platform approval per country)', 'bool'], ['recording_retention_days', 'Recording retention (days)'],
          ['transcript_retention_days', 'Transcript retention (days)'], ['allowed_dial_countries', 'Countries outbound calls may reach (e.g. EG,SA)'], ['human_handoff_phone', 'Human handoff phone']]);
      });
    }]
  ];
  if (BOOT.admin) {
    TABS.push(['admin', function () {
      return Promise.resolve('<p><a class="va-btn" href="/admin/voice">Open AI Voice administration</a></p>');
    }]);
  }
  function callsTable(rows) {
    return table([['When', 'started_at'], ['Direction', 'direction'], ['From', 'from_e164'], ['Outcome', function (c) { return badge(c.outcome || c.status || ''); }], ['Summary', 'summary'], ['Audio', function (c) { return '<a href="/api/voice-agent/calls/' + (+c.id) + '/recording" target="_blank" rel="noopener">Play</a>'; }]], rows);
  }

  var active = 0;
  function render() {
    root.innerHTML = '<div class="va-tabs" role="tablist">' + TABS.map(function (tb, i) {
      return '<button class="va-tab" role="tab" aria-selected="' + (i === active) + '" data-i="' + i + '">' + esc(t(tb[0])) + '</button>';
    }).join('') + '</div><div class="va-body" role="tabpanel"><p class="va-muted">' + esc(t('loading')) + '</p></div>';
    var body = root.querySelector('.va-body');
    TABS[active][1]().then(function (html) { body.innerHTML = html; if (window.__vaNote) { var m0 = body.querySelector('[data-msg]'); if (m0) m0.textContent = window.__vaNote; window.__vaNote = null; } if (window.__vaInfo) { var inf = body.querySelector('[data-info]'); if (inf) inf.innerHTML = '<div class="va-note"><b>Next steps</b><ol>' + (Array.isArray(window.__vaInfo.steps) ? window.__vaInfo.steps : (window.__vaInfo.steps[BOOT.lang] || window.__vaInfo.steps.en || [])).map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ol>' + (window.__vaInfo.target ? '<p>Send calls to: <code>' + esc(window.__vaInfo.target) + '</code></p>' : '') + '</div>'; window.__vaInfo = null; } bindSettings(body); if (TABS[active][2]) TABS[active][2](body); })
      .catch(function (e) { body.innerHTML = '<p class="va-err">' + esc(e.message || t('failed')) + '</p>'; });
  }
  root.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('.va-tab') : null;
    if (b) { active = +b.getAttribute('data-i'); render(); }
  });
  if (BOOT.lang === 'ar') root.setAttribute('dir', 'rtl');
  api('/api/voice-agent/status').then(function (s) {
    if (!s.feature_enabled) { root.innerHTML = '<div class="va-note">' + esc(t('notEnabled')) + '<div style="margin-top:12px"><a class="p-btn primary" href="/subscription">' + esc(t('upgrade')) + '</a></div></div>'; return; }
    render();
  }).catch(function (e) { root.innerHTML = '<p class="va-err">' + esc(e.message) + '</p>'; });
})();
