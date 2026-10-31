(function () {
  'use strict';
  var root = document.getElementById('rep-limits-root');
  if (!root) return;
  var P = window.Panel;
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var state = null;
  var NAMES = { free: 'No subscription', starter: 'Starter', professional: 'Growth', enterprise: 'Leadership' };

  function api(url, opts) { return (P && P.fetchJSON ? P.fetchJSON(url, opts) : fetch(url, opts).then(function (r) { return r.json(); })).catch(function () { return { success: false, error: 'The server sent an unexpected answer. Refresh the page and try again.' }; }); }
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

    var usage = d.usage.length ? '<div class="p-table-scroll"><table class="p-table"><thead><tr><th>Account</th><th>Plan</th><th>Requests this month</th><th>Limit</th><th>AI calls</th><th>AI cost (USD)</th></tr></thead><tbody>' + d.usage.map(function (u) {
      var over = u.limit >= 0 && u.requests >= u.limit;
      return '<tr><td>' + esc(u.name) + '</td><td>' + esc(NAMES[u.plan] || u.plan) + '</td><td' + (over ? ' style="color:#b91c1c;font-weight:700"' : '') + '>' + u.requests + '</td><td>' + (u.limit < 0 ? 'Unlimited' : u.limit) + '</td><td>' + Number(u.ai_calls || 0) + '</td><td>$' + Number(u.ai_cost || 0).toFixed(4) + '</td></tr>';
    }).join('') + '</tbody></table></div><p class="p-cell-muted" style="font-size:12.5px;margin:8px 0 0">AI cost is the real cost of this month\'s review replies, sentiment checks and request-message drafts, from the AI call log (priced by input and output tokens of the model that answered). Calls made before 2026-10-31 were priced with a lower flat rate and some had no company attached.</p>' : '<p class="p-cell-muted">No review requests or reputation AI calls this month.</p>';

    root.innerHTML =
      '<div class="p-card"><div class="p-card-head"><h3>Limits per plan</h3></div>' +
      '<p class="p-cell-muted" style="margin:0 0 10px">Prices and AI reply credits are managed in <a href="/admin/plans-studio">Plans Studio</a>. Defaults are unlimited, so nothing changes for customers until you set a number. ' +
      'A change applies immediately to everyone on that plan: customers already above a new limit keep their data but cannot create new requests until next month (or until you raise it). ' +
      'For one customer only, use "Exceptions for one customer" below.</p>' +
      '<style>#rep-limits-root table.rl{min-width:640px}#rep-limits-root table.rl th[scope=row]{position:sticky;inset-inline-start:0;background:var(--panel-card,#fff);min-width:150px;max-width:210px;white-space:normal;z-index:1}' +
      '#rep-limits-root input[type=number]{min-height:40px}#rep-limits-root input[type=checkbox]{width:24px;height:24px}</style>' +
      '<p class="p-cell-muted rl-hint" style="margin:0 0 6px;font-size:12.5px">Scroll sideways to see every plan.</p>' +
      '<div class="p-table-scroll" tabindex="0" aria-label="Limits table, scrolls sideways"><table class="p-table rl">' + head + rows + '</table></div>' +
      '<div style="margin-top:12px"><button class="p-btn primary" id="rlSave">Save changes</button> <span id="rlMsg" role="status" aria-live="polite" class="p-cell-muted"></span></div></div>' +
      '<div class="p-card" style="margin-top:16px"><div class="p-card-head"><h3>Exceptions for one customer</h3></div>' +
      '<p class="p-cell-muted" style="margin:0 0 10px">Give one customer its own limits instead of its plan\'s (a gift, a pilot, or a restriction). Leave a field empty to keep the plan\'s value; use the Remove column to go back to the plan. Team members follow their account owner.</p>' +
      '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end"><label style="display:grid;gap:4px;font-size:13px;min-width:220px">Customer e-mail<input id="exEmail" type="email" class="p-select" autocomplete="off"></label>' +
      d.number_keys.map(function (k) { return '<label style="display:grid;gap:4px;font-size:13px">' + esc(d.labels[k]) + '<input type="number" min="-1" max="1000000" step="1" class="p-select" style="width:130px" data-ex="' + k + '" placeholder="plan"></label>'; }).join('') +
      d.bool_keys.map(function (k) { return '<label style="display:grid;gap:4px;font-size:13px">' + esc(d.labels[k]) + '<select class="p-select" data-ex="' + k + '"><option value="">plan</option><option value="1">On</option><option value="0">Off</option></select></label>'; }).join('') +
      '<button class="p-btn primary" id="exSave">Save exception</button></div><span id="exMsg" role="status" aria-live="polite" class="p-cell-muted"></span>' +
      (d.overrides.length ? '<div class="p-table-scroll" tabindex="0" aria-label="Customer exceptions"><table class="p-table" style="margin-top:10px"><thead><tr><th>Customer</th><th>Plan</th><th>Exceptions</th><th></th></tr></thead><tbody>' + d.overrides.map(function (o) {
        return '<tr><td>' + esc(o.email) + '</td><td>' + esc(NAMES[o.plan] || o.plan) + '</td><td>' + Object.keys(o.values).map(function (k) { return esc((d.labels[k] || k) + ': ' + (o.values[k] === true ? 'On' : o.values[k] === false ? 'Off' : o.values[k] < 0 ? 'Unlimited' : o.values[k])); }).join('; ') + '</td><td><button class="p-btn outline xs" data-rm="' + esc(o.email) + '" data-keys="' + esc(Object.keys(o.values).join(',')) + '">Remove</button></td></tr>';
      }).join('') + '</tbody></table></div>' : '<p class="p-cell-muted" style="margin-top:10px">No exceptions.</p>') + '</div>' +
      standaloneCard(d) +
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
    document.getElementById('exSave').onclick = function () {
      var vals = {};
      root.querySelectorAll('[data-ex]').forEach(function (el) { if (el.value !== '') vals[el.dataset.ex] = el.tagName === 'SELECT' ? el.value === '1' : el.value; });
      sendOverride(document.getElementById('exEmail').value, vals);
    };
    root.querySelectorAll('[data-rm]').forEach(function (b) {
      b.onclick = function () { var vals = {}; b.dataset.keys.split(',').forEach(function (k) { vals[k] = null; }); sendOverride(b.dataset.rm, vals); };
    });
  }

  function standaloneCard(d) {
    var sa = d.standalone;
    if (!sa) return '';
    if (!sa.ready) return '<div class="p-card" style="margin-top:16px"><div class="p-card-head"><h3>Stand-alone reputation plans</h3></div><p class="p-cell-muted">Not available until the database update 2026_10_31_000004 (table reputation_subscriptions) is applied.</p></div>';
    var rows = sa.plans.map(function (p) {
      var f = function (field, val, extra) { return '<input class="p-select" data-sa-plan="' + p.key + '" data-sa-field="' + field + '" value="' + esc(val) + '" ' + (extra || '') + '>'; };
      var lim = d.number_keys.map(function (k) { return '<td><input type="number" min="-1" max="1000000" step="1" class="p-select" style="width:100px" data-sa-plan="' + p.key + '" data-sa-limit="' + k + '" value="' + Number(p.limits[k]) + '" aria-label="' + esc(d.labels[k] + ' - ' + p.name) + '"></td>'; }).join('') +
        d.bool_keys.map(function (k) { return '<td><input type="checkbox" data-sa-plan="' + p.key + '" data-sa-limit="' + k + '"' + (p.limits[k] ? ' checked' : '') + ' aria-label="' + esc(d.labels[k] + ' - ' + p.name) + '"></td>'; }).join('');
      return '<tr><td>' + f('name', p.name, 'maxlength="40" style="width:110px" aria-label="Plan name"') + '</td><td>' + f('price_monthly', p.price_monthly, 'type="number" min="0" step="0.01" style="width:90px" aria-label="Monthly price USD"') + '</td><td>' + f('price_yearly', p.price_yearly, 'type="number" min="0" step="0.01" style="width:90px" aria-label="Yearly price USD"') +
        '</td><td><input type="checkbox" data-sa-plan="' + p.key + '" data-sa-field="for_sale"' + (p.for_sale ? ' checked' : '') + ' aria-label="On sale - ' + esc(p.name) + '"></td>' + lim + '<td>' + p.subs + '</td><td>$' + Number(p.mrr).toFixed(2) + '</td></tr>';
    }).join('');
    return '<div class="p-card" style="margin-top:16px"><div class="p-card-head"><h3>Stand-alone reputation plans</h3></div>' +
      '<p class="p-cell-muted" style="margin:0 0 10px">Plans a customer buys from the wallet for the reputation tools only. Off by default: nothing is sold until you turn selling on <b>and</b> tick "On sale" for a plan. A customer with a platform plan too gets the more generous limit of the two, never both added. Stopping a plan\'s sale never cancels its subscribers; a price change applies at the next renewal, not to the period already paid; a renewal the wallet cannot cover ends the plan. AI replies are not part of these plans (review credits or wallet usage prices, as today). Prices are in USD; the figures below are proposals until you approve them.</p>' +
      '<label style="display:flex;gap:8px;align-items:center;margin-bottom:10px;font-weight:600"><input type="checkbox" id="saEnabled"' + (sa.enabled ? ' checked' : '') + '> Selling is on</label>' +
      '<div class="p-table-scroll" tabindex="0" aria-label="Stand-alone plans, scrolls sideways"><table class="p-table" style="min-width:900px"><thead><tr><th>Name</th><th>Monthly $</th><th>Yearly $</th><th>On sale</th>' + d.number_keys.concat(d.bool_keys).map(function (k) { return '<th>' + esc(d.labels[k]) + '</th>'; }).join('') + '<th>Subscribers</th><th>Monthly revenue</th></tr></thead><tbody>' + rows + '</tbody></table></div></div>';
  }

  function collectStandalone() {
    var out = { plans: {} };
    var en = document.getElementById('saEnabled');
    if (en) out.enabled = en.checked;
    root.querySelectorAll('[data-sa-plan]').forEach(function (el) {
      var k = el.dataset.saPlan;
      out.plans[k] = out.plans[k] || { limits: {} };
      if (el.dataset.saLimit) out.plans[k].limits[el.dataset.saLimit] = el.type === 'checkbox' ? el.checked : el.value;
      else if (el.dataset.saField) out.plans[k][el.dataset.saField] = el.type === 'checkbox' ? el.checked : el.value;
    });
    return out;
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
    api('/api/admin/reputation-limits', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf_token || '' }, body: JSON.stringify({ limits: collect(), sources: collectSources(), standalone: collectStandalone(), csrf_token: state.csrf_token || '' }) })
      .then(function (r) {
        btn.disabled = false;
        if (r.success) { toast(r.message || 'Saved', 'success'); load(); } else { msg.textContent = r.error || 'Could not save'; toast(r.error || 'Could not save', 'error'); }
      });
  }

  function sendOverride(email, values) {
    var msg = document.getElementById('exMsg');
    if (!email) { msg.textContent = 'Enter the customer e-mail'; return; }
    if (!Object.keys(values).length) { msg.textContent = 'Fill at least one value'; return; }
    msg.textContent = 'Saving...';
    api('/api/admin/reputation-limits/override', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf_token || '' }, body: JSON.stringify({ email: email, values: values, csrf_token: state.csrf_token || '' }) })
      .then(function (r) { if (r.success) { toast(r.message || 'Saved', 'success'); load(); } else { msg.textContent = r.error || 'Could not save'; toast(r.error || 'Could not save', 'error'); } });
  }

  function load() {
    api('/api/admin/reputation-limits').then(function (r) {
      if (!r.success) { root.innerHTML = '<p>' + esc(r.error || 'Could not load') + '</p>'; return; }
      state = r.data; render();
    });
  }
  load();
})();
