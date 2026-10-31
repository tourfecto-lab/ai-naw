(function () {
  'use strict';
  var root = document.getElementById('rep-limits-root');
  if (!root) return;
  var P = window.Panel;
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var state = null;
  var NAMES = { free: 'No subscription', starter: 'Starter', professional: 'Growth', enterprise: 'Leadership' };

  function api(url, opts) { return (P && P.fetchJSON ? P.fetchJSON(url, opts) : fetch(url, opts).then(function (r) { return r.json(); })); }
  function toast(m, t) { if (P && P.toast) P.toast(m, t); }

  function render() {
    var d = state;
    var head = '<tr><th scope="col">Setting</th>' + d.tiers.map(function (t) { return '<th scope="col">' + esc(NAMES[t] || t) + '</th>'; }).join('') + '</tr>';
    var rows = d.number_keys.map(function (k) {
      return '<tr><th scope="row">' + esc(d.labels[k]) + '<div class="p-cell-muted" style="font-weight:400;font-size:12px">-1 = unlimited, 0 = not allowed</div></th>' + d.tiers.map(function (t) {
        return '<td><input type="number" min="-1" max="1000000" step="1" class="p-select" style="width:110px" data-tier="' + t + '" data-key="' + k + '" value="' + Number(d.config[t][k]) + '" aria-label="' + esc(d.labels[k] + ' - ' + (NAMES[t] || t)) + '"></td>';
      }).join('') + '</tr>';
    }).join('') + d.bool_keys.map(function (k) {
      return '<tr><th scope="row">' + esc(d.labels[k]) + '</th>' + d.tiers.map(function (t) {
        return '<td><input type="checkbox" data-tier="' + t + '" data-key="' + k + '"' + (d.config[t][k] ? ' checked' : '') + ' aria-label="' + esc(d.labels[k] + ' - ' + (NAMES[t] || t)) + '"></td>';
      }).join('') + '</tr>';
    }).join('');

    var audit = d.audit.length ? '<ul style="margin:0;padding-inline-start:18px">' + d.audit.map(function (a) {
      return '<li>' + esc(a.at) + ' UTC · ' + esc(a.actor) + ': ' + a.changes.map(function (c) { return esc((NAMES[c.plan] || c.plan) + ' / ' + (d.labels[c.key] || c.key) + ': ' + c.from + ' → ' + c.to); }).join('; ') + '</li>';
    }).join('') + '</ul>' : '<p class="p-cell-muted">No changes yet.</p>';

    var usage = d.usage.length ? '<div class="p-table-scroll"><table class="p-table"><thead><tr><th>Account</th><th>Plan</th><th>Requests this month</th><th>Limit</th></tr></thead><tbody>' + d.usage.map(function (u) {
      var over = u.limit >= 0 && u.requests >= u.limit;
      return '<tr><td>' + esc(u.name) + '</td><td>' + esc(NAMES[u.plan] || u.plan) + '</td><td' + (over ? ' style="color:#b91c1c;font-weight:700"' : '') + '>' + u.requests + '</td><td>' + (u.limit < 0 ? 'Unlimited' : u.limit) + '</td></tr>';
    }).join('') + '</tbody></table></div>' : '<p class="p-cell-muted">No review requests sent this month.</p>';

    root.innerHTML =
      '<div class="p-card"><div class="p-card-head"><h3>Limits per plan</h3></div>' +
      '<p class="p-cell-muted" style="margin:0 0 10px">Prices and AI reply credits are managed in <a href="/admin/plans-studio">Plans Studio</a>. Defaults are unlimited, so nothing changes for customers until you set a number. ' +
      'A change applies immediately to everyone on that plan: customers already above a new limit keep their data but cannot create new requests until next month (or until you raise it). ' +
      'For one customer only, use a feature override with the keys reputation_review_requests_monthly, reputation_sms_requests_monthly, reputation_locations, reputation_team_assignment, reputation_csv_import.</p>' +
      '<style>#rep-limits-root table.rl{min-width:640px}#rep-limits-root table.rl th[scope=row]{position:sticky;inset-inline-start:0;background:var(--panel-card,#fff);min-width:150px;max-width:210px;white-space:normal;z-index:1}' +
      '#rep-limits-root input[type=number]{min-height:40px}#rep-limits-root input[type=checkbox]{width:22px;height:22px}</style>' +
      '<p class="p-cell-muted rl-hint" style="margin:0 0 6px;font-size:12.5px">Scroll sideways to see every plan.</p>' +
      '<div class="p-table-scroll" tabindex="0" aria-label="Limits table, scrolls sideways"><table class="p-table rl">' + head + rows + '</table></div>' +
      '<div style="margin-top:12px"><button class="p-btn primary" id="rlSave">Save changes</button> <span id="rlMsg" role="status" aria-live="polite" class="p-cell-muted"></span></div></div>' +
      '<div class="p-card" style="margin-top:16px"><div class="p-card-head"><h3>Review sources</h3></div>' +
      '<p class="p-cell-muted" style="margin:0 0 10px">Choose, for each outside review site, how reviews may come in. Both can be on together. Automatic sync can only be switched on where the platform has a connector (today TripAdvisor). For the other sites CSV import is the way in until a connector is built; the note on each row says what that site requires.</p>' +
      '<div class="p-table-scroll" tabindex="0" aria-label="Review sources, scrolls sideways"><table class="p-table"><thead><tr><th scope="col">Site</th><th scope="col">CSV import by the customer</th><th scope="col">Automatic sync</th></tr></thead><tbody>' +
      d.sources.map(function (x) {
        return '<tr><th scope="row" style="font-weight:600">' + esc(x.label) + '</th>' +
          '<td>' + (x.csv_available ? '<input type="checkbox" data-source="' + x.key + '" data-flag="csv_import"' + (x.csv_import ? ' checked' : '') + ' aria-label="CSV import - ' + esc(x.label) + '">' : '<span class="p-cell-muted">Not offered</span>') + '</td>' +
          '<td>' + (x.auto_available ? '<input type="checkbox" data-source="' + x.key + '" data-flag="auto_sync"' + (x.auto_sync ? ' checked' : '') + ' aria-label="Automatic sync - ' + esc(x.label) + '"> <span class="p-cell-muted" style="font-size:12px">' + esc(x.auto_note) + '</span>' : '<span class="p-cell-muted" style="font-size:12.5px">' + esc(x.auto_note) + '</span>') + '</td></tr>';
      }).join('') + '</tbody></table></div></div>' +
      '<div class="p-card" style="margin-top:16px"><div class="p-card-head"><h3>Usage this month</h3></div>' + usage + '</div>' +
      '<div class="p-card" style="margin-top:16px"><div class="p-card-head"><h3>Change history</h3></div>' + audit + '</div>';

    document.getElementById('rlSave').onclick = save;
  }

  function collectSources() {
    var out = {};
    root.querySelectorAll('input[data-source]').forEach(function (el) {
      out[el.dataset.source] = out[el.dataset.source] || {};
      out[el.dataset.source][el.dataset.flag] = el.checked;
    });
    return out;
  }

  function collect() {
    var out = {};
    root.querySelectorAll('input[data-tier]').forEach(function (el) {
      var t = el.dataset.tier, k = el.dataset.key;
      out[t] = out[t] || {};
      out[t][k] = el.type === 'checkbox' ? el.checked : el.value;
    });
    return out;
  }

  function save() {
    var btn = document.getElementById('rlSave'), msg = document.getElementById('rlMsg');
    btn.disabled = true; msg.textContent = 'Saving...';
    api('/api/admin/reputation-limits', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf_token || '' }, body: JSON.stringify({ limits: collect(), sources: collectSources(), csrf_token: state.csrf_token || '' }) })
      .then(function (r) {
        btn.disabled = false;
        if (r.success) { toast(r.message || 'Saved', 'success'); load(); } else { msg.textContent = r.error || 'Could not save'; toast(r.error || 'Could not save', 'error'); }
      });
  }

  function load() {
    api('/api/admin/reputation-limits').then(function (r) {
      if (!r.success) { root.innerHTML = '<p>' + esc(r.error || 'Could not load') + '</p>'; return; }
      state = r.data; render();
    });
  }
  load();
})();
