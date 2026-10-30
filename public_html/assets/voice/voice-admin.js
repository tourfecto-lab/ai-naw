(function () {
  'use strict';
  var root = document.getElementById('voice-admin');
  if (!root) return;
  function esc(v) { var d = document.createElement('div'); d.textContent = v == null ? '' : String(v); return d.innerHTML; }
  function csrf() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; }
  function api(path, body) {
    var opt = { credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (body !== undefined) { opt.method = 'POST'; opt.headers['Content-Type'] = 'application/json'; opt.headers['X-CSRF-Token'] = csrf(); opt.body = JSON.stringify(body); }
    return fetch(path, opt).then(function (r) { return r.json().catch(function () { return { success: false, message: 'Bad response' }; }); })
      .then(function (j) { if (!j || j.success === false) throw new Error((j && j.message) || 'Request failed'); j.data = j.data || {}; j.data._message = j.message; return j.data; });
  }
  function badge(t, c) { return '<span class="va-badge ' + (c || '') + '">' + esc(t) + '</span>'; }
  function cards(items) { return '<div class="va-cards">' + items.map(function (i) { return '<div class="va-card"><span class="va-muted">' + esc(i[0]) + '</span><b>' + esc(i[1]) + '</b></div>'; }).join('') + '</div>'; }
  function table(cols, rows) {
    if (!rows || !rows.length) return '<p class="va-muted">Nothing here yet.</p>';
    return '<div class="va-tablewrap"><table><thead><tr>' + cols.map(function (c) { return '<th>' + esc(c[0]) + '</th>'; }).join('') + '</tr></thead><tbody>' +
      rows.map(function (r) { return '<tr>' + cols.map(function (c) { return '<td>' + (typeof c[1] === 'function' ? c[1](r) : esc(r[c[1]])) + '</td>'; }).join('') + '</tr>'; }).join('') + '</tbody></table></div>';
  }
  function say(el, text, bad) { var m = el.querySelector('[data-msg]'); if (m) { m.textContent = text; m.className = bad ? 'va-err' : 'va-muted'; } }
  function money(v) { return '$' + (Number(v) || 0).toFixed(2); }

  var TABS = [
    ['Status', function () {
      return Promise.all([api('/api/admin/voice/providers'), api('/api/admin/voice/settings'), api('/api/admin/voice/quality')]).then(function (r) {
        var live = r[1].values.voice_live_operations_enabled === '1', q = r[2];
        return (live ? '<div class="va-note"><b>Live operations are ON.</b> Companies that left test mode and approve each action can buy numbers and place calls.</div>'
                     : '<div class="va-note">Live operations are OFF. Everything is simulated.</div>') +
          '<p><button class="va-btn" data-stop style="background:var(--va-bad);border-color:var(--va-bad)">Emergency stop</button> <span class="va-muted">Turns live operations off and cancels all queued outbound calls.</span> <span data-msg></span></p>' +
          cards([['Calls (7d)', q.calls], ['Fallback rate', q.fallback_rate_pct == null ? '—' : q.fallback_rate_pct + '%'], ['Avg reply', q.avg_reply_ms == null ? '—' : q.avg_reply_ms + ' ms'], ['Provider errors', q.provider_errors]]) +
          '<h3>Providers</h3><p class="va-muted">Keys are saved in Admin &rarr; Integrations (Twilio / Telnyx AI Voice).</p>' +
          table([['Provider', 'provider'], ['Keys', function (p) { return p.configured ? badge('configured', 'ok') : badge('missing: ' + p.missing_keys.join(', '), 'warn'); }], ['Errors 24h', 'errors_24h'],
            ['Test', function (p) { return p.configured ? '<button class="va-btn sec" data-test="' + esc(p.provider) + '">Test (read-only)</button>' : ''; }]], r[0].providers);
      });
    }, function (el) {
      var stop = el.querySelector('[data-stop]');
      if (stop) stop.addEventListener('click', function () {
        if (!window.confirm('Turn live operations off and cancel all queued outbound calls?')) return;
        api('/api/admin/voice/emergency-stop', {}).then(function (d) { say(el, d._message + ' (' + d.cancelled_jobs + ' cancelled)'); show(0); }).catch(function (e) { say(el, e.message, true); });
      });
      el.querySelectorAll('[data-test]').forEach(function (b) {
        b.addEventListener('click', function () { b.disabled = true; api('/api/admin/voice/providers/' + encodeURIComponent(b.getAttribute('data-test')) + '/test', {}).then(function (d) { b.textContent = d._message || 'OK'; }).catch(function (e) { b.textContent = e.message; }); });
      });
    }],
    ['Platform settings', function () {
      return api('/api/admin/voice/settings').then(function (r) {
        return '<form class="va-form" data-settings>' + r.definitions.map(function (d) {
          var v = r.values[d.key];
          var input = d.type === 'bool'
            ? '<select name="' + esc(d.key) + '"><option value="1"' + (v === '1' ? ' selected' : '') + '>On</option><option value="0"' + (v === '1' ? '' : ' selected') + '>Off</option></select>'
            : '<input name="' + esc(d.key) + '" value="' + esc(v) + '">';
          return '<label>' + esc(d.label) + (d.danger ? ' ' + badge('needs confirmation', 'warn') : '') + input + '</label>';
        }).join('') + '<label>Type <b>' + esc(r.confirm_phrase) + '</b> to turn on anything that spends money or contacts people<input name="confirm" autocomplete="off"></label>' +
          '<div><button class="va-btn" type="submit">Save</button> <span data-msg></span></div></form>';
      });
    }, function (el) {
      var f = el.querySelector('[data-settings]');
      f.addEventListener('submit', function (e) {
        e.preventDefault();
        var body = {}; new FormData(f).forEach(function (v, k) { body[k] = v; });
        api('/api/admin/voice/settings', body).then(function () { say(el, 'Saved'); }).catch(function (err) { say(el, err.message, true); });
      });
    }],
    ['Companies', function () {
      return api('/api/admin/voice/companies').then(function (r) {
        return '<p class="va-muted">Stop disables the company and cancels its queued calls. Taking a company out of test mode needs the confirmation phrase.</p><p data-msg></p>' +
          table([['Company', function (c) { return esc(c.company_name || c.email || c.company); }], ['Mode', function (c) { return +c.enabled ? (+c.sandbox_mode ? badge('test mode', 'warn') : badge('live', 'ok')) : badge('off', 'bad'); }],
            ['Outbound', function (c) { return +c.outbound_enabled ? 'on' : 'off'; }], ['Numbers', 'active_numbers'], ['Calls (month)', 'month_calls'], ['Charge (month)', function (c) { return money(c.month_price_usd); }],
            ['Budget', function (c) { return c.monthly_budget_usd == null ? '—' : money(c.monthly_budget_usd); }],
            ['Actions', function (c) {
              return '<button class="va-btn sec" data-stop="' + (+c.company) + '">Stop</button> ' + (+c.sandbox_mode
                ? '<button class="va-btn sec" data-live="' + (+c.company) + '">Take out of test mode</button>'
                : '<button class="va-btn sec" data-test-mode="' + (+c.company) + '">Back to test mode</button>');
            }]], r.companies);
      });
    }, function (el) {
      function act(attr, body, ask) {
        el.querySelectorAll('[' + attr + ']').forEach(function (b) {
          b.addEventListener('click', function () {
            var b2 = typeof body === 'function' ? body() : body;
            if (b2 === null) return;
            api('/api/admin/voice/companies/' + (+b.getAttribute(attr)) + '/control', b2).then(function () { show(2); }).catch(function (e) { say(el, e.message, true); });
          });
        });
      }
      act('data-stop', function () { return window.confirm('Stop this company and cancel its queued calls?') ? { stop: 1 } : null; });
      act('data-live', function () { var c = window.prompt('Type I UNDERSTAND to take this company out of test mode. Real numbers and calls become possible (each still needs approval).'); return c === 'I UNDERSTAND' ? { sandbox_mode: 0, confirm: c } : null; });
      act('data-test-mode', function () { return { sandbox_mode: 1 }; });
    }],
    ['User access', function () {
      return Promise.resolve('<p class="va-muted">Turn the AI Voice Agent on for specific accounts. This overrides the plan for that account (on or off); "Back to plan" removes the override. Staff members follow their account owner.</p>' +
        '<form class="va-form" data-search><label>Search by email or company<input name="q" placeholder="name@example.com" minlength="2" required></label><div><button class="va-btn" type="submit">Search</button> <span data-msg></span></div></form><div data-results></div>');
    }, function (el) {
      var f = el.querySelector('[data-search]'), out = el.querySelector('[data-results]');
      function load() {
        api('/api/admin/voice/access?q=' + encodeURIComponent(f.q.value)).then(function (r) {
          out.innerHTML = table([['Account', function (u) { return esc(u.company_name || u.email) + '<br><span class="va-muted">' + esc(u.email) + ' #' + (+u.id) + '</span>'; }],
            ['Voice agent', function (u) { return (u.voice_enabled ? badge('on · up to ' + u.voice_max_numbers + ' numbers', 'ok') : badge('off')) + ' <span class="va-muted">set by ' + esc(u.source) + '</span>'; }],
            ['', function (u) {
              if (u.is_staff_of) return '<span class="va-muted">staff of #' + (+u.is_staff_of) + '</span>';
              return (u.voice_enabled ? '<button class="va-btn sec" data-off="' + (+u.id) + '">Turn off</button>' : '<button class="va-btn" data-on="' + (+u.id) + '">Turn on</button>') +
                (u.source === 'admin' ? ' <button class="va-btn sec" data-plan="' + (+u.id) + '">Back to plan</button>' : '');
            }]], r.users);
          out.querySelectorAll('[data-on]').forEach(function (b) { b.addEventListener('click', function () { set(+b.getAttribute('data-on'), 1); }); });
          out.querySelectorAll('[data-plan]').forEach(function (b) { b.addEventListener('click', function () { set(+b.getAttribute('data-plan'), 'plan'); }); });
          out.querySelectorAll('[data-off]').forEach(function (b) { b.addEventListener('click', function () { set(+b.getAttribute('data-off'), 0); }); });
        }).catch(function (e) { say(el, e.message, true); });
      }
      function set(id, on) { api('/api/admin/voice/access/' + id, { enabled: on, max_numbers: on === 1 ? 3 : null }).then(function () { say(el, 'Saved'); load(); }).catch(function (e) { say(el, e.message, true); }); }
      f.addEventListener('submit', function (e) { e.preventDefault(); load(); });
    }],
    ['Quality & margins', function () {
      return Promise.all([api('/api/admin/voice/quality'), api('/api/admin/voice/margins')]).then(function (r) {
        var m = r[1];
        return cards([['Total cost', money(m.total.cost_usd)], ['Charged', money(m.total.price_usd)], ['Margin', m.total.margin_pct == null ? '—' : m.total.margin_pct + '%']]) +
          table([['Company', 'company'], ['Cost', function (c) { return money(c.cost_usd); }], ['Charged', function (c) { return money(c.price_usd); }], ['Margin %', function (c) { return c.margin_pct == null ? '—' : c.margin_pct + '%'; }], ['Unknown-cost rows', 'unknown_cost_rows']], m.companies);
      });
    }],
    ['Errors', function () {
      return api('/api/admin/voice/errors').then(function (r) {
        return table([['When', 'created_at'], ['Company', 'tenant_user_id'], ['Provider', 'provider'], ['Operation', 'operation'], ['HTTP', 'http_code'], ['Message', 'message']], r.errors);
      });
    }],
    ['Do-not-call (platform)', function () {
      return api('/api/admin/voice/dnc').then(function (r) {
        return '<form class="va-form" data-dnc><label>Phone (E.164)<input name="phone" placeholder="+201001234567" required></label><label>Reason<input name="reason"></label><div><button class="va-btn" type="submit">Block for every company</button> <span data-msg></span></div></form>' +
          table([['Phone', 'e164'], ['Reason', 'reason'], ['Added', 'created_at'], ['', function (e) { return '<button class="va-btn sec" data-rm="' + esc(e.e164) + '">Remove</button>'; }]], r.entries);
      });
    }, function (el) {
      var f = el.querySelector('[data-dnc]');
      f.addEventListener('submit', function (e) { e.preventDefault(); var b = {}; new FormData(f).forEach(function (v, k) { b[k] = v; }); api('/api/admin/voice/dnc', b).then(function () { show(6); }).catch(function (er) { say(el, er.message, true); }); });
      el.querySelectorAll('[data-rm]').forEach(function (b) { b.addEventListener('click', function () { api('/api/admin/voice/dnc/remove', { phone: b.getAttribute('data-rm') }).then(function () { show(6); }).catch(function (er) { say(el, er.message, true); }); }); });
    }]
  ];
  var active = 0;
  function show(i) {
    active = i;
    root.innerHTML = '<div class="va-tabs" role="tablist">' + TABS.map(function (t, k) { return '<button class="va-tab" role="tab" aria-selected="' + (k === i) + '" data-i="' + k + '">' + esc(t[0]) + '</button>'; }).join('') + '</div><div class="va-body" role="tabpanel"><p class="va-muted">Loading…</p></div>';
    var body = root.querySelector('.va-body');
    TABS[i][1]().then(function (html) { body.innerHTML = html; if (TABS[i][2]) TABS[i][2](body); }).catch(function (e) { body.innerHTML = '<p class="va-err">' + esc(e.message) + '</p>'; });
  }
  root.addEventListener('click', function (e) { var b = e.target.closest ? e.target.closest('.va-tab') : null; if (b) show(+b.getAttribute('data-i')); });
  show(0);
})();
