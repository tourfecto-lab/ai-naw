(function () {
  'use strict';
  var BOOT = window.VOICE_BOOT || {};
  var root = document.getElementById('voice-app');
  if (!root) return;
  var I18N = {
    en: {
      overview: 'Overview', numbers: 'Phone Numbers', assistants: 'AI Assistants', incoming: 'Incoming Calls', outgoing: 'Outgoing Campaigns',
      history: 'Call History', crm: 'Booking & CRM Integration', whatsapp: 'WhatsApp Integration', analytics: 'Analytics', billing: 'Billing & Usage',
      settings: 'Settings', admin: 'Voice Administration', loading: 'Loading…', save: 'Save', none: 'Nothing here yet.', failed: 'Could not load this section.',
      sandbox: 'Test mode is on: no real numbers are bought and no real calls are placed.', notEnabled: 'AI Voice Agent is not included in your plan.',
      saved: 'Saved', calls: 'Calls', booked: 'Bookings', spent: 'Spent this month', budget: 'Budget'
    },
    ar: {
      overview: 'نظرة عامة', numbers: 'أرقام الهاتف', assistants: 'المساعدون الأذكياء', incoming: 'المكالمات الواردة', outgoing: 'حملات المكالمات الصادرة',
      history: 'سجل المكالمات', crm: 'الحجوزات وإدارة العملاء', whatsapp: 'ربط واتساب', analytics: 'التحليلات', billing: 'الفواتير والاستخدام',
      settings: 'الإعدادات', admin: 'إدارة الصوت', loading: 'جارٍ التحميل…', save: 'حفظ', none: 'لا يوجد شيء بعد.', failed: 'تعذر تحميل هذا القسم.',
      sandbox: 'وضع التجربة مفعّل: لا يتم شراء أرقام حقيقية ولا إجراء مكالمات حقيقية.', notEnabled: 'ميزة المساعد الصوتي غير متضمنة في باقتك.',
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
      .then(function (j) { if (!j || j.success === false) { throw new Error((j && j.message) || t('failed')); } return j.data || {}; });
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
  function money(v) { return '$' + (Number(v) || 0).toFixed(2); }

  var TABS = [
    ['overview', function () {
      return Promise.all([api('/api/voice-agent/status'), api('/api/voice-agent/settings'), api('/api/voice-agent/analytics'), api('/api/voice-agent/costs')]).then(function (r) {
        var a = r[2], c = r[3].costs || {};
        return (r[1].settings && +r[1].settings.sandbox_mode ? '<div class="va-note">' + esc(t('sandbox')) + '</div>' : '') +
          cards([[t('calls'), (a.calls && a.calls.total) || 0], [t('booked'), (a.bookings && a.bookings.count) || 0], [t('spent'), money(c.month_usd)], [t('budget'), c.budget_usd == null ? '—' : money(c.budget_usd)]]);
      });
    }],
    ['numbers', function () {
      return api('/api/voice-agent/numbers').then(function (r) {
        return table([['Number', 'e164'], ['Country', 'country'], ['Type', 'number_type'], ['Provider', 'provider'], ['Status', function (n) { return badge(n.status); }]], r.numbers);
      });
    }],
    ['assistants', function () {
      return api('/api/voice-agent/agents').then(function (r) {
        return table([['Name', 'name'], ['Role', 'role'], ['Language', 'language'], ['Status', function (a) { return badge(a.status); }]], r.agents);
      });
    }],
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
          '<h3>Top objections</h3>' + table([['Objection', 'key'], ['Count', 'count']], a.objections) +
          '<h3>Why leads were lost</h3>' + table([['Reason', 'key'], ['Count', 'count']], a.loss_reasons);
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
      return Promise.all([api('/api/admin/voice/providers'), api('/api/admin/voice/quality'), api('/api/admin/voice/margins')]).then(function (r) {
        var q = r[1], m = r[2].total || {};
        return cards([['Calls (7d)', q.calls], ['Fallback rate', q.fallback_rate_pct == null ? '—' : q.fallback_rate_pct + '%'], ['Avg reply', q.avg_reply_ms == null ? '—' : q.avg_reply_ms + ' ms'], ['Provider errors', q.provider_errors], ['Margin', m.margin_pct == null ? '—' : m.margin_pct + '%']]) +
          '<pre class="va-muted" style="white-space:pre-wrap;overflow-wrap:anywhere">' + esc(JSON.stringify(r[0], null, 2)) + '</pre>';
      });
    }]);
  }
  function callsTable(rows) {
    return table([['When', 'started_at'], ['Direction', 'direction'], ['From', 'from_e164'], ['Outcome', function (c) { return badge(c.outcome || c.status || ''); }], ['Summary', 'summary']], rows);
  }

  var active = 0;
  function render() {
    root.innerHTML = '<div class="va-tabs" role="tablist">' + TABS.map(function (tb, i) {
      return '<button class="va-tab" role="tab" aria-selected="' + (i === active) + '" data-i="' + i + '">' + esc(t(tb[0])) + '</button>';
    }).join('') + '</div><div class="va-body" role="tabpanel"><p class="va-muted">' + esc(t('loading')) + '</p></div>';
    var body = root.querySelector('.va-body');
    TABS[active][1]().then(function (html) { body.innerHTML = html; bindSettings(body); })
      .catch(function (e) { body.innerHTML = '<p class="va-err">' + esc(e.message || t('failed')) + '</p>'; });
  }
  root.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('.va-tab') : null;
    if (b) { active = +b.getAttribute('data-i'); render(); }
  });
  if (BOOT.lang === 'ar') root.setAttribute('dir', 'rtl');
  api('/api/voice-agent/status').then(function (s) {
    if (!s.feature_enabled) { root.innerHTML = '<div class="va-note">' + esc(t('notEnabled')) + '</div>'; return; }
    render();
  }).catch(function (e) { root.innerHTML = '<p class="va-err">' + esc(e.message) + '</p>'; });
})();
