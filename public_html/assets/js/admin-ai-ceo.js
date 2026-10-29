/**
 * TOURFECTO AI CEO — AI Mission Control (admin). English UI; the chat accepts and answers Arabic.
 * Every number comes from /api/admin/ai-ceo; nothing is computed or invented here.
 */
(() => {
  'use strict';
  const start = () => {
    const root = document.getElementById('aiCeo'), P = window.Panel;
    if (!root || !P) return;
    const esc = P.esc;
    let data = null, note = null, busy = false, chatBusy = false, costs = null, costsError = null;
    // تبويبات الصفحة (الرابط بيحفظ التبويب: #chat، #approvals، ...)
    const TABS = [['overview', 'Overview'], ['chat', 'AI CEO Chat'], ['approvals', 'Approvals & Tasks'], ['costs', 'AI Costs'], ['memory', 'Memory'], ['log', 'Audit Log']];
    let tab = TABS.some(t => t[0] === location.hash.slice(1)) ? location.hash.slice(1) : 'overview';
    const num = v => v === null || v === undefined ? '—' : Number(v).toLocaleString('en-US', { maximumFractionDigits: 2 });
    const req = async (url, body) => {
      const token = data?.csrf_token || root.dataset.csrf || '';
      const r = await P.fetchJSON(url, body ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token }, body: JSON.stringify({ ...body, csrf_token: token }) } : undefined);
      if (!r?.success) throw new Error(r?.error || 'Something went wrong.');
      return r.data;
    };
    const L = o => o ? (o.en || o.ar || '') : '';
    const RISK = { low: 'Low risk', medium: 'Needs approval', high: 'High risk — explicit approval' };
    const STATUS = { proposed: 'Proposed', approved: 'In progress', done: 'Done', resolved: 'Resolved', dismissed: 'Not now' };
    const QUICK = ['إيه اللي حصل النهارده؟', 'إيه أهم مشكلة عندنا؟', 'ليه الاشتراكات قلّت؟', 'إيه الخدمات اللي محتاجة تطوير؟', 'إيه أفضل دولة نستهدفها؟', 'إيه المطلوب مني دلوقتي؟'];
    const tile = (v, label, sub) => `<div class="br-tile"><b>${v}</b><span>${esc(label)}</span>${sub ? `<small>${esc(sub)}</small>` : ''}</div>`;

    function overview(s) {
      const r = s.revenue, su = s.signups, tr = s.trials, p = s.platform, e = s.errors, ai = s.ai_cost, o = s.outreach, c = s.customers;
      return `<section class="br-card"><div class="br-head"><div><h3>Executive Overview</h3><p class="br-muted">Snapshot ${esc(s.generated_at || '')} UTC · "—" means the source is unavailable, not zero.</p></div>
        <button type="button" class="p-btn outline xs" id="acRefresh">Refresh data</button></div><div class="br-tiles">
        ${tile(r ? '$' + num(r.mrr.now) : '—', 'MRR', r ? '30 days ago $' + num(r.mrr.prev) : '')}
        ${tile(r ? num(r.paying.now) : '—', 'Paying customers', r ? '30 days ago ' + num(r.paying.prev) : '')}
        ${tile(r ? num(r.logo_churn.now) + '%' : '—', 'Churn (30 days)', r ? 'before ' + num(r.logo_churn.prev) + '%' : '')}
        ${tile(su ? num(su.last_7d) : '—', 'Sign-ups (7 days)', su ? 'today ' + num(su.today) + ' · prior week ' + num(su.prev_7d) : '')}
        ${tile(tr ? num(tr.active) : '—', 'Active trials', tr ? num(tr.ending_24h) + ' end within 24h' : '')}
        ${tile(c ? num(c.paying_at_risk) : '—', 'Paying customers at risk', c ? '$' + num(c.mrr_at_risk) + ' MRR' : '')}
        ${tile(p ? esc(String(p.overall || '').toUpperCase()) : '—', 'Platform health', p ? num(p.counts?.fail || 0) + ' failing · ' + num(p.counts?.warn || 0) + ' warnings' : '')}
        ${tile(e ? num(e.errors_24h) : '—', 'Errors (24h)', e ? num(e.errors_last_hour) + ' in the last hour' : '')}
        ${tile(ai ? '$' + num(ai.today_usd) : '—', 'AI cost today', ai ? 'month $' + num(ai.month_usd) : '')}
        ${tile(o ? num(o.total) : '—', 'Outreach companies', o ? num(o.awaiting_approval) + ' await approval · ' + num(o.sent) + ' emails sent' : '')}
      </div></section>`;
    }

    function controls() {
      const st = data.settings, owner = data.owner;
      return `<section class="br-card"><div class="br-head"><div><h3>AI Mission Control</h3>
        <p class="br-muted">${st.paused ? '<b style="color:var(--panel-danger,#dc2626)">EMERGENCY STOP is on</b> — no automatic actions, no scheduled cycle, no AI calls from chat.' : st.mode === 'auto' ? 'AUTO MODE — low-risk internal decisions are approved automatically. Anything involving money, pricing, permissions, outside contact or production always waits for you.' : 'APPROVAL MODE — the AI CEO prepares decisions and waits for your approval.'}</p></div>
        <div class="br-actions">
          ${owner ? `<button type="button" class="p-btn xs ${st.mode === 'approval' ? 'primary' : 'outline'}" data-mode="approval">Approval mode</button><button type="button" class="p-btn xs ${st.mode === 'auto' ? 'primary' : 'outline'}" data-mode="auto">Auto mode</button>` : '<span class="br-muted">Only the platform owner can change the mode or approve.</span>'}
          ${st.paused ? (owner ? '<button type="button" class="p-btn xs primary" id="acResume">Resume AI CEO</button>' : '') : '<button type="button" class="p-btn xs" style="background:var(--panel-danger,#dc2626);color:#fff" id="acStop">Emergency stop</button>'}
        </div></div></section>`;
    }

    function chat() {
      const msgs = (data.chat || []).map(m => `<div class="ac-q" dir="auto">${esc(m.title)}</div><div class="ac-a" dir="auto">${esc(m.body || '')}</div>`).join('');
      return `<section class="br-card"><h3>AI CEO Chat</h3><p class="br-muted">Ask in Arabic or English. Core questions are answered straight from the numbers (no AI cost); other questions use the platform's AI with the same data.</p>
        <div class="ac-chat" id="acChatLog">${msgs || '<p class="br-muted">No questions yet.</p>'}</div>
        <div class="br-actions">${QUICK.map(q => `<button type="button" class="p-btn xs outline" data-q="${esc(q)}" dir="rtl">${esc(q)}</button>`).join('')}</div>
        <form id="acChatForm" class="ac-form"><input class="p-select" id="acMsg" dir="auto" maxlength="1000" placeholder="اسأل AI CEO… / Ask the AI CEO…" autocomplete="off"><button class="p-btn primary" type="submit" ${chatBusy ? 'disabled' : ''}>${chatBusy ? 'Thinking…' : 'Ask'}</button></form></section>`;
    }

    function decisionCard(d) {
      const owner = data.owner, sc = d.scores || {};
      const btns = !owner ? '' : d.status === 'proposed' ? `<button type="button" class="p-btn xs primary" data-set="approved" data-id="${d.id}">Approve</button><button type="button" class="p-btn xs outline" data-set="dismissed" data-id="${d.id}">Not now</button>`
        : d.status === 'approved' ? `<button type="button" class="p-btn xs primary" data-set="done" data-id="${d.id}">Mark done</button>`
        : d.status === 'dismissed' ? `<button type="button" class="p-btn xs outline" data-set="proposed" data-id="${d.id}">Restore</button>` : '';
      return `<li class="br-decision ${esc(d.status)}"><div class="br-dhead"><span class="br-area">${esc(d.area)}</span><span class="br-status">${esc(STATUS[d.status] || d.status)}</span>
        <span class="br-${d.risk === 'low' ? 'area' : 'spend'}">${esc(RISK[d.risk] || d.risk)}</span><span class="br-muted">priority ${num(d.priority)}</span></div>
        <h4>${esc(L(d.title))}</h4><p>${esc(L(d.why))}</p>
        ${(d.evidence || []).length ? `<ul class="br-proof">${d.evidence.map(e => `<li dir="auto">${esc(L(e))}</li>`).join('')}</ul>` : ''}
        <p class="br-muted">Impact ${num(sc.impact)} · ROI ${num(sc.roi)} · Urgency ${num(sc.urgency)} · Confidence ${num(sc.confidence)} · Risk ${num(sc.risk)} · Cost ${num(sc.cost)} · Effort ${num(sc.effort)}${(d.dependencies || []).length ? ' · depends on: ' + esc(d.dependencies.join(', ')) : ''}${d.decided_by === null && d.status === 'approved' ? ' · auto-approved' : ''}</p>
        <div class="br-actions"><a class="p-btn xs" href="${esc(d.action_url || '#')}">${esc(L(d.action))}</a>${btns}</div></li>`;
    }

    function radar(list) {
      const open = list.filter(d => ['proposed', 'approved'].includes(d.status)), closed = list.filter(d => ['done', 'resolved'].includes(d.status)), later = list.filter(d => d.status === 'dismissed');
      const waiting = open.filter(d => d.status === 'proposed').length;
      return `<section class="br-card"><h3>Strategic Radar · Tasks & Approvals</h3>
        <p class="br-muted">${num(open.length)} open · ${num(waiting)} waiting for a decision. Priority = impact, ROI, urgency and confidence, lowered by risk, cost and effort (see docs/ai-ceo).</p>
        ${open.length ? `<ol class="br-list">${open.map(decisionCard).join('')}</ol>` : '<p class="br-done">✓ No open decisions from the current data.</p>'}
        ${closed.length ? `<details class="br-past"><summary>Done / resolved (${closed.length})</summary><ol class="br-list">${closed.map(decisionCard).join('')}</ol></details>` : ''}
        ${later.length ? `<details class="br-past"><summary>Not now (${later.length})</summary><ol class="br-list">${later.map(decisionCard).join('')}</ol></details>` : ''}</section>`;
    }

    // AI Cost Optimization (phase 2): spend, projection, budgets, anomalies, routing review, measured savings
    function costSection() {
      if (costsError) return `<section class="br-card" id="costs"><h3>AI Cost Optimization</h3><p class="br-muted">${esc(costsError)}</p></section>`;
      if (!costs) return '<section class="br-card" id="costs"><h3>AI Cost Optimization</h3><p class="br-muted">Loading AI costs…</p></section>';
      const c = costs, t = c.totals, pb = c.platform_budget, a = c.aiceo, sv = c.savings, owner = c.owner, b = c.budgets || {};
      const usd = v => v === null || v === undefined ? '—' : '$' + num(v);
      const table = (head, rows) => rows.length ? `<div class="p-table-scroll"><table class="p-table"><thead><tr>${head.map(h => `<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody></table></div>` : '<p class="br-muted">No data.</p>';
      const field = (k, label, hint) => `<label class="br-field">${esc(label)}<input class="p-select" type="number" min="0" step="any" data-budget="${k}" value="${b[k] ?? ''}" ${owner ? '' : 'disabled'}><small>${esc(hint)}</small></label>`;
      return `<section class="br-card" id="costs"><h3>AI Cost Optimization</h3>
        <p class="br-muted">From api_usage_logs (every provider call the platform makes). Goal: the lowest cost that keeps the required quality.</p>
        <div class="br-tiles">
          ${tile(usd(t.today), 'AI spend today', t.requests_today + ' requests · usual day ' + usd(t.avg_day_7d))}
          ${tile(usd(t.month), 'This month', t.requests_month + ' requests')}
          ${tile(usd(t.projected_month), 'Projected month-end', pb ? num(pb.projected_pct) + '% of the ' + usd(pb.limit) + ' budget' : 'no platform budget set')}
          ${tile(usd(a.month), 'AI CEO spend (month)', 'today ' + usd(a.day) + ' / ' + usd(a.day_limit) + ' · month limit ' + usd(a.month_limit) + (a.blocked ? ' · BLOCKED (' + a.blocked + ')' : ''))}
          ${tile(usd(sv.aiceo_saved_usd_month), 'Measured savings (month)', num(sv.aiceo_avoided_calls_month) + ' AI CEO answers without an AI call' + (sv.aiceo_avg_call_usd === null ? ' · no paid AI CEO call yet to price them' : ''))}
        </div>
        <h4 style="margin-top:16px">Unusual usage today</h4>
        ${c.anomalies.length ? `<ul class="br-proof">${c.anomalies.map(x => `<li>${esc(x.type)} <b>${esc(x.name)}</b>: ${usd(x.today)} today vs ${usd(x.avg_day)} a usual day</li>`).join('')}</ul>` : '<p class="br-muted">Nothing unusual: no task or account above 3× its usual daily spend.</p>'}
        <h4 style="margin-top:16px">Model routing review</h4>
        ${c.routing_review.length ? `<ul class="br-proof">${c.routing_review.map(x => `<li><b>${esc(x.feature)}</b>: ${esc(x.expensive_model)} ${usd(x.expensive_cpr)}/request (${num(x.expensive_requests)} requests) vs ${esc(x.cheap_model)} ${usd(x.cheap_cpr)}/request. Test quality, then adjust in <a href="/admin/ai-routing">AI routing</a>.</li>`).join('')}</ul>` : '<p class="br-muted">No task runs on a model more than 3× costlier than another model already serving it.</p>'}
        <details class="br-past"><summary>Cost by task (last 8 days)</summary>${table(['Task', 'Today', 'Usual day', 'Requests today', '8-day cost'], c.features.map(f => `<tr><td dir="ltr">${esc(f.feature)}</td><td>${usd(f.today)}</td><td>${usd(f.avg_day)}</td><td>${num(f.requests_today)}</td><td>${usd(f.cost_8d)}</td></tr>`))}</details>
        <details class="br-past"><summary>Cost and speed by model (this month)</summary>${table(['Model', 'Provider', 'Requests', 'Cost', 'Per request', 'Avg tokens', 'Avg ms'], c.models.map(m => `<tr><td dir="ltr">${esc(m.model)}</td><td>${esc(m.provider)}</td><td>${num(m.requests)}</td><td>${usd(m.cost)}</td><td>${usd(m.cost_per_request)}</td><td>${num(m.avg_tokens)}</td><td>${num(m.avg_ms)}</td></tr>`))}</details>
        <details class="br-past"><summary>Top accounts by AI cost (this month)</summary>${table(['Account', 'Requests', 'Month', 'Today'], c.customers.map(u => `<tr><td>user #${num(u.user_id)}</td><td>${num(u.requests)}</td><td>${usd(u.cost)}</td><td>${usd(u.today)}</td></tr>`))}<p class="br-muted">Per-account limits are set in <a href="/admin/brain">Strategic brain admin</a>.</p></details>
        <h4 style="margin-top:16px">Budgets</h4>
        <div class="br-facts">${field('ai_platform_budget_usd_month', 'Platform AI budget per month (USD)', 'Alert threshold for all AI spend. Empty = not set.')}
          ${field('aiceo_ai_budget_usd_day', 'AI CEO budget per day (USD)', 'Separate from customers. Chat stops calling AI when reached.')}
          ${field('aiceo_ai_budget_usd_month', 'AI CEO budget per month (USD)', 'Default 20.')}</div>
        ${owner ? '<div class="br-actions"><button type="button" class="p-btn primary" id="acBudgets">Save budgets</button></div>' : '<p class="br-muted">Only the platform owner can change budgets.</p>'}
        <p class="br-muted" style="margin-top:10px">${(c.limits || []).map(esc).join(' ')}</p></section>`;
    }

    function memory() {
      const rows = (data.memory || []).filter(m => m.kind !== 'chat').map(m => `<li><span class="br-area">${esc(m.kind)}</span> <b dir="auto">${esc(m.title)}</b>${m.body ? `<div class="br-muted" dir="auto">${esc(m.body)}</div>` : ''}<small class="br-muted"> · ${esc(m.created_at)} UTC</small></li>`).join('');
      return `<section class="br-card"><h3>Institutional Memory</h3><p class="br-muted">Decisions, plans and results the AI CEO keeps so it does not repeat mistakes.</p>
        ${rows ? `<ul class="ac-mem">${rows}</ul>` : '<p class="br-muted">Nothing saved yet.</p>'}
        <form id="acMemForm" class="ac-form"><select class="p-select" id="acMemKind" style="max-width:120px"><option value="note">Note</option><option value="plan">Plan</option></select>
        <input class="p-select" id="acMemTitle" dir="auto" maxlength="255" placeholder="Title"><input class="p-select" id="acMemBody" dir="auto" placeholder="Details (optional)"><button class="p-btn outline" type="submit">Save</button></form></section>`;
    }

    function activity() {
      const rows = (data.activity || []).map(a => `<tr><td>${esc(a.created_at)}</td><td>${a.actor_id ? 'user #' + a.actor_id : 'AI CEO'}</td><td>${esc(a.action)}</td><td dir="auto" class="br-muted">${esc(a.detail ? JSON.stringify(a.detail) : '')}</td></tr>`).join('');
      return `<section class="br-card"><h3>Audit Log</h3>${rows ? `<div class="p-table-scroll"><table class="p-table"><thead><tr><th>Time (UTC)</th><th>By</th><th>Action</th><th>Detail</th></tr></thead><tbody>${rows}</tbody></table></div>` : '<p class="br-muted">No activity yet.</p>'}</section>`;
    }

    function tabsBar() {
      const waiting = (data.decisions || []).filter(d => d.status === 'proposed').length;
      return `<nav class="ac-tabs" role="tablist">${TABS.map(([id, label]) => `<button type="button" role="tab" class="p-tab${tab === id ? ' active' : ''}" aria-selected="${tab === id}" data-tab="${id}">${esc(label)}${id === 'approvals' && waiting ? ` <span class="ac-badge">${waiting}</span>` : ''}</button>`).join('')}</nav>`;
    }

    // نظرة عامة: الأرقام + أهم 3 قرارات مفتوحة
    function overviewTab() {
      const open = (data.decisions || []).filter(d => ['proposed', 'approved'].includes(d.status));
      return `${overview(data.snapshot || {})}<section class="br-card"><div class="br-head"><div><h3>Top priorities</h3><p class="br-muted">${num(open.length)} open decisions. The three most important:</p></div>
        <button type="button" class="p-btn outline xs" data-tab="approvals">See all approvals & tasks</button></div>
        ${open.length ? `<ol class="br-list">${open.slice(0, 3).map(decisionCard).join('')}</ol>` : '<p class="br-done">✓ No open decisions from the current data.</p>'}</section>`;
    }

    function body() {
      switch (tab) {
        case 'chat': return chat();
        case 'approvals': return radar(data.decisions || []);
        case 'costs': return costSection();
        case 'memory': return memory();
        case 'log': return activity();
        default: return overviewTab();
      }
    }

    function render() {
      if (!data) return;
      root.innerHTML = `${note ? `<p class="br-note ${note.error ? 'error' : ''}" role="status">${esc(note.text)}</p>` : ''}${controls()}${tabsBar()}${body()}`;
      root.querySelectorAll('[data-tab]').forEach(b => b.onclick = () => { tab = b.dataset.tab; note = null; history.replaceState(null, '', '#' + tab); render(); window.scrollTo({ top: 0 }); });
      const log = root.querySelector('#acChatLog'); if (log) log.scrollTop = log.scrollHeight;
      const act = async (fn, okText) => { if (busy) return; busy = true; try { await fn(); note = okText ? { text: okText } : null; } catch (e) { note = { text: e.message, error: true }; } finally { busy = false; render(); } };
      root.querySelector('#acRefresh')?.addEventListener('click', () => load(true));
      root.querySelectorAll('[data-mode]').forEach(b => b.onclick = () => act(async () => { const r = await req('/api/admin/ai-ceo/mode', { mode: b.dataset.mode }); Object.assign(data, r); }, 'Mode saved ✓'));
      root.querySelector('#acStop')?.addEventListener('click', () => { const reason = prompt('Emergency stop: why? (optional)') ?? null; if (reason === null) return; act(async () => { const r = await req('/api/admin/ai-ceo/pause', { on: true, reason }); Object.assign(data, r); }, 'Emergency stop is on'); });
      root.querySelector('#acResume')?.addEventListener('click', () => act(async () => { const r = await req('/api/admin/ai-ceo/pause', { on: false }); Object.assign(data, r); }, 'AI CEO resumed ✓'));
      root.querySelectorAll('[data-set]').forEach(b => b.onclick = () => act(async () => { const r = await req('/api/admin/ai-ceo/decisions/' + b.dataset.id, { status: b.dataset.set }); Object.assign(data, r); }, 'Saved ✓'));
      const ask = async q => {
        if (chatBusy || !q.trim()) return; chatBusy = true; render();
        try { const r = await req('/api/admin/ai-ceo/chat', { message: q }); data.chat = [...(data.chat || []), { title: q, body: r.answer }].slice(-12); note = null; }
        catch (e) { note = { text: e.message, error: true }; }
        finally { chatBusy = false; render(); root.querySelector('#acMsg')?.focus(); }
      };
      root.querySelectorAll('[data-q]').forEach(b => b.onclick = () => ask(b.dataset.q));
      root.querySelector('#acBudgets')?.addEventListener('click', () => act(async () => {
        const budgets = {}; root.querySelectorAll('[data-budget]').forEach(el => { budgets[el.dataset.budget] = el.value.trim(); });
        await req('/api/admin/ai-ceo/budgets', { budgets }); await loadCosts(); }, 'Budgets saved ✓'));
      root.querySelector('#acChatForm')?.addEventListener('submit', ev => { ev.preventDefault(); ask(root.querySelector('#acMsg').value); });
      root.querySelector('#acMemForm')?.addEventListener('submit', ev => { ev.preventDefault(); act(async () => {
        const r = await req('/api/admin/ai-ceo/memory', { kind: root.querySelector('#acMemKind').value, title: root.querySelector('#acMemTitle').value, body: root.querySelector('#acMemBody').value }); data.memory = r.memory; }, 'Saved to memory ✓'); });
    }

    async function loadCosts() {
      try { costs = await req('/api/admin/ai-ceo/costs'); costsError = null; } catch (e) { costsError = e.message; }
      render();
    }

    async function load(refresh) {
      if (!data) root.innerHTML = '<section class="br-card"><p>Loading AI Mission Control…</p></section>';
      try { data = await req('/api/admin/ai-ceo' + (refresh ? '?refresh=1' : '')); note = null; render(); }
      catch (e) { root.innerHTML = `<section class="br-card"><p>${esc(e.message)}</p></section>`; }
    }
    // روابط القرارات زي /admin/ai-ceo#costs بتغيّر التبويب من غير ما الصفحة تتحمل تاني
    window.addEventListener('hashchange', () => { const h = location.hash.slice(1); if (TABS.some(t => t[0] === h) && h !== tab) { tab = h; render(); } });
    load(false).then(loadCosts);
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true }); else start();
})();
