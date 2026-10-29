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
    let data = null, note = null, busy = false, chatBusy = false;
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

    function render() {
      if (!data) return;
      root.innerHTML = `${note ? `<p class="br-note ${note.error ? 'error' : ''}" role="status">${esc(note.text)}</p>` : ''}${controls()}${overview(data.snapshot || {})}${chat()}${radar(data.decisions || [])}${memory()}${activity()}`;
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
      root.querySelector('#acChatForm')?.addEventListener('submit', ev => { ev.preventDefault(); ask(root.querySelector('#acMsg').value); });
      root.querySelector('#acMemForm')?.addEventListener('submit', ev => { ev.preventDefault(); act(async () => {
        const r = await req('/api/admin/ai-ceo/memory', { kind: root.querySelector('#acMemKind').value, title: root.querySelector('#acMemTitle').value, body: root.querySelector('#acMemBody').value }); data.memory = r.memory; }, 'Saved to memory ✓'); });
    }

    async function load(refresh) {
      if (!data) root.innerHTML = '<section class="br-card"><p>Loading AI Mission Control…</p></section>';
      try { data = await req('/api/admin/ai-ceo' + (refresh ? '?refresh=1' : '')); note = null; render(); }
      catch (e) { root.innerHTML = `<section class="br-card"><p>${esc(e.message)}</p></section>`; }
    }
    load(false);
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true }); else start();
})();
