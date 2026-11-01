(function () {
  'use strict';
  var root = document.getElementById('wa-admin-root');
  if (!root) return;
  var P = window.Panel;
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var state = null, cstate = { state: '', q: '', page: 1 }, camp = null;
  var FLAGS = { high_failure_rate: 'High failure rate', high_opt_out_rate: 'High opt-out rate', uncertain_sends: 'Uncertain sends', blocked: 'Blocked' };

  function api(url, opts) {
    return (P && P.fetchJSON ? P.fetchJSON(url, opts) : fetch(url, opts).then(function (r) { return r.json(); }))
      .catch(function () { return { success: false, error: 'The server sent an unexpected answer. Refresh the page and try again.' }; });
  }
  function toast(m, t) { if (P && P.toast) P.toast(m, t); }
  function post(url, body) {
    return api(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf_token || '' }, body: JSON.stringify(Object.assign({ csrf_token: state.csrf_token || '' }, body)) });
  }
  function tile(v, l) { return '<div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">' + esc(v) + '</div><div class="stat-label">' + esc(l) + '</div></div></div>'; }

  function hubCard(h) {
    if (!h.website_id) return '<div class="p-card"><div class="p-card-head"><h3>Platform WhatsApp marketing</h3></div><p class="p-cell-muted">Your account has no website yet. Add one, then connect it to the official WhatsApp Cloud API from the WhatsApp settings.</p></div>';
    var sel = '<select id="waHubSite" class="p-select" aria-label="Website">' + h.websites.map(function (w) { return '<option value="' + w.id + '"' + (w.id === h.website_id ? ' selected' : '') + '>' + esc(w.name) + '</option>'; }).join('') + '</select>';
    var tpl = Object.keys(h.templates).length ? Object.keys(h.templates).map(function (k) { return esc(k) + ': <b>' + h.templates[k] + '</b>'; }).join(' · ') : 'No templates yet';
    var forms = h.forms.length ? '<ul style="margin:6px 0 0;padding-inline-start:18px">' + h.forms.map(function (f) { return '<li>' + esc(f.title) + ' (' + esc(f.language) + '): <input class="p-select" readonly style="width:min(420px,100%)" value="' + esc(f.url) + '" onclick="this.select()"></li>'; }).join('') + '</ul>' : '<p class="p-cell-muted">No opt-in form yet. Create one in the campaign tool (it needs your privacy policy URL).</p>';
    var rows = h.campaigns.length ? h.campaigns.map(function (c) { var n = c.counts || {}; return '<tr><td>' + esc(c.name) + '</td><td>' + esc(c.state) + '</td><td>' + (n.accepted || 0) + '</td><td>' + (n.failed || 0) + '</td><td>' + (n.pending || 0) + '</td></tr>'; }).join('') : '<tr><td colspan="5" class="p-cell-muted">No campaigns yet.</td></tr>';
    return '<div class="p-card"><div class="p-card-head"><h3>Platform WhatsApp marketing (your account)</h3>' + sel + '</div>' +
      '<p class="p-cell-muted" style="margin:0 0 10px">The platform markets itself with the same engine customers use, under your own account, so consent, approved templates, quotas and opt-outs are the same. Only people who opted in can be messaged: they join through your opt-in form, never from a scraped list. This page sends nothing.</p>' +
      '<div class="p-kv"><span class="k">Sender (official Cloud API)</span><span class="v">' + (h.connected ? '<span class="pill green">Connected</span> ' + esc(h.sender) : '<span class="pill gray">Not connected</span>') + '</span></div>' +
      (h.suspended ? '<div class="p-kv"><span class="k">Status</span><span class="v"><span class="pill gray">Suspended by the platform</span></span></div>' : '') +
      '<div class="p-kv"><span class="k">Templates</span><span class="v">' + tpl + '</span></div>' +
      '<div class="p-kv"><span class="k">Contacts</span><span class="v">Opted in <b>' + h.contacts.opted_in + '</b> · Opted out <b>' + h.contacts.opted_out + '</b> · Other <b>' + h.contacts.other + '</b></span></div>' +
      '<div style="margin:10px 0 4px;font-weight:600">Opt-in forms to share</div>' + forms +
      '<div class="p-table-scroll" tabindex="0" aria-label="Your campaigns"><table class="p-table" style="margin-top:12px"><thead><tr><th>Campaign</th><th>State</th><th>Accepted</th><th>Failed</th><th>Pending</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
      '<p style="margin-top:12px"><a class="p-btn primary" href="/whatsapp-marketing">Open the campaign tool</a></p></div>';
  }

  function campaignRows(list) {
    if (!list.rows.length) return '<tr><td colspan="7" class="p-cell-muted">No campaigns match.</td></tr>';
    return list.rows.map(function (c) {
      var n = c.counts || {};
      var flags = c.flags.map(function (f) { return '<span class="pill" style="background:#fee2e2;color:#991b1b">' + esc(FLAGS[f] || f) + '</span>'; }).join(' ');
      var can = ['running', 'scheduled'].indexOf(c.state) >= 0;
      var act = (can ? '<button class="p-btn outline xs" data-act="pause" data-id="' + c.id + '">Pause</button> ' : '') + (['completed', 'cancelled'].indexOf(c.state) < 0 ? '<button class="p-btn outline xs" data-act="cancel" data-id="' + c.id + '">Cancel</button> ' : '') +
        (c.account_suspended ? '<span class="pill gray">Account suspended</span>' : '<button class="p-btn danger xs" data-susp="' + esc(c.email) + '">Suspend account</button>');
      return '<tr><td><b>' + esc(c.customer) + '</b><div class="p-cell-muted" style="font-size:12px">' + esc(c.email) + '</div></td><td>' + esc(c.name) + '<div class="p-cell-muted" style="font-size:12px">' + esc(c.template_name) + ' · ' + esc(c.language) + '</div></td><td>' + esc(c.state) + (c.last_error ? '<div class="p-cell-muted" style="font-size:12px">' + esc(c.last_error) + '</div>' : '') + '</td>' +
        '<td>' + (n.accepted || 0) + ' / ' + (n.failed || 0) + ' / ' + (n.skipped || 0) + ' / ' + ((n.pending || 0) + (n.processing || 0)) + (c.opt_outs ? '<div class="p-cell-muted" style="font-size:12px">' + c.opt_outs + ' STOP' + (n.accepted ? ' (' + Math.round(c.opt_outs / n.accepted * 1000) / 10 + '%)' : '') + '</div>' : '') + '</td><td>' + flags + '</td><td>' + esc(c.created_at) + '</td><td style="white-space:nowrap">' + act + '</td></tr>';
    }).join('');
  }

  function render() {
    var d = state, k = d.kpis;
    var susp = d.suspended.length ? '<div class="p-table-scroll" tabindex="0" aria-label="Suspended accounts"><table class="p-table"><thead><tr><th>Customer</th><th>Reason</th><th>Since (UTC)</th><th></th></tr></thead><tbody>' + d.suspended.map(function (s) { return '<tr><td>' + esc(s.email) + '</td><td>' + esc(s.reason) + '</td><td>' + esc(s.at) + '</td><td><button class="p-btn outline xs" data-resume="' + esc(s.email) + '">Resume</button></td></tr>'; }).join('') + '</tbody></table></div>' : '<p class="p-cell-muted">No suspended accounts.</p>';
    var audit = d.audit.length ? '<ul style="margin:0;padding-inline-start:18px">' + d.audit.map(function (a) { return '<li>' + esc(a.at) + ' UTC · ' + esc(a.actor) + ': ' + esc(a.action.replace('_', ' ')) + ' ' + esc(a.customer || a.name || ('#' + (a.campaign_id || ''))) + (a.reason ? ' (' + esc(a.reason) + ')' : '') + '</li>'; }).join('') + '</ul>' : '<p class="p-cell-muted">No actions yet.</p>';
    root.innerHTML =
      '<div class="p-grid cols-4">' + tile(k.campaigns, 'Campaigns this month') + tile(k.accepted, 'Messages accepted') + tile(k.failed + k.uncertain, 'Failed or uncertain') + tile(k.opt_outs, 'Opt-outs this month') + '</div>' +
      '<div style="margin-top:14px">' + hubCard(d.hub) + '</div>' +
      '<div class="p-card" style="margin-top:16px"><div class="p-card-head"><h3>Customer campaigns</h3></div>' +
      '<p class="p-cell-muted" style="margin:0 0 8px">Counts are accepted / failed / skipped / pending. A campaign is flagged when at least ' + d.min_sent_for_flags + ' messages were tried and ' + d.failure_flag_pct + '% or more failed, when ' + d.opt_out_flag_pct + '% or more of the accepted messages were followed by a STOP within ' + d.opt_out_days + ' days, when any send is uncertain, or when it is blocked. Pausing or cancelling needs a written reason, the customer sees it in the campaign. Suspending an account pauses its campaigns and stops launches and sending until you resume it.</p>' +
      '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px"><select id="waState" class="p-select" aria-label="State"><option value="">All states</option>' + ['draft', 'scheduled', 'running', 'paused', 'blocked', 'completed', 'partial', 'cancelled'].map(function (s) { return '<option value="' + s + '"' + (cstate.state === s ? ' selected' : '') + '>' + s + '</option>'; }).join('') + '</select><input id="waQ" class="p-select" placeholder="Customer or campaign" value="' + esc(cstate.q) + '" aria-label="Search"><button class="p-btn outline xs" id="waGo">Search</button></div>' +
      '<div class="p-table-scroll" tabindex="0" aria-label="Customer campaigns, scrolls sideways"><table class="p-table" style="min-width:860px"><thead><tr><th>Customer</th><th>Campaign</th><th>State</th><th>Accepted / failed / skipped / pending</th><th>Flags</th><th>Created</th><th></th></tr></thead><tbody id="waRows"><tr><td colspan="7">Loading...</td></tr></tbody></table></div>' +
      '<div style="display:flex;gap:10px;justify-content:center;padding:10px" id="waPager"></div></div>' +
      '<div class="p-card" style="margin-top:16px"><div class="p-card-head"><h3>Suspended accounts</h3></div>' + susp + '</div>' +
      '<div class="p-card" style="margin-top:16px"><div class="p-card-head"><h3>History</h3></div>' + audit + '</div>';
    var site = document.getElementById('waHubSite');
    if (site) site.onchange = function () { load(site.value); };
    document.getElementById('waGo').onclick = function () { cstate.state = document.getElementById('waState').value; cstate.q = document.getElementById('waQ').value.trim(); cstate.page = 1; loadCampaigns(); };
    root.querySelectorAll('[data-resume]').forEach(function (b) { b.onclick = function () { post('/api/admin/whatsapp-marketing/resume', { email: b.dataset.resume }).then(done); }; });
    loadCampaigns();
  }

  function done(r) { if (r.success) { toast(r.message || 'Saved', 'success'); load(); } else { toast(r.error || 'Could not save', 'error'); } }

  function loadCampaigns() {
    api('/api/admin/whatsapp-marketing/campaigns?state=' + encodeURIComponent(cstate.state) + '&q=' + encodeURIComponent(cstate.q) + '&page=' + cstate.page).then(function (r) {
      var tb = document.getElementById('waRows');
      if (!r.success) { tb.innerHTML = '<tr><td colspan="7">' + esc(r.error || 'Could not load') + '</td></tr>'; return; }
      camp = r.data;
      tb.innerHTML = campaignRows(camp);
      var pg = document.getElementById('waPager');
      pg.innerHTML = '<button class="p-btn outline xs" id="waPrev"' + (cstate.page <= 1 ? ' disabled' : '') + '>Previous</button><span class="p-cell-muted">' + cstate.page + ' / ' + Math.max(1, camp.pages) + '</span><button class="p-btn outline xs" id="waNext"' + (cstate.page >= camp.pages ? ' disabled' : '') + '>Next</button>';
      document.getElementById('waPrev').onclick = function () { cstate.page--; loadCampaigns(); };
      document.getElementById('waNext').onclick = function () { cstate.page++; loadCampaigns(); };
      tb.querySelectorAll('[data-act]').forEach(function (b) {
        b.onclick = function () {
          var reason = window.prompt('Reason (the customer will see it):');
          if (!reason) return;
          post('/api/admin/whatsapp-marketing/campaigns/' + b.dataset.id + '/action', { action: b.dataset.act, reason: reason }).then(done);
        };
      });
      tb.querySelectorAll('[data-susp]').forEach(function (b) {
        b.onclick = function () {
          var reason = window.prompt('Reason for suspending ' + b.dataset.susp + ':');
          if (!reason) return;
          post('/api/admin/whatsapp-marketing/suspend', { email: b.dataset.susp, reason: reason }).then(done);
        };
      });
    });
  }

  function load(websiteId) {
    api('/api/admin/whatsapp-marketing' + (websiteId ? '?website_id=' + encodeURIComponent(websiteId) : '')).then(function (r) {
      if (!r.success) { root.innerHTML = '<p>' + esc(r.error || 'Could not load') + '</p>'; return; }
      state = r.data; render();
    });
  }
  load();
})();
