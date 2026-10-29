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
    const TABS = [['overview', 'Overview'], ['chat', 'AI CEO Chat'], ['approvals', 'Approvals & Tasks'], ['companies', 'Tourism Companies'], ['sales', 'Leads & Sales'], ['brand', 'Brand Growth'], ['success', 'Customer Success'], ['ops', 'Platform & Dev Advisor'], ['costs', 'AI Costs'], ['memory', 'Memory'], ['log', 'Audit Log']];
    // قاعدة شركات السياحة (المرحلة 3)
    let sales = null, salesError = null, salesLoading = false;
    // نمو علامة Tourfecto (المرحلة 5)
    let brand = null, brandError = null, brandLoading = false, brandPlan = null;
    // نجاح العملاء والإيرادات (المرحلة 6)
    let cs = null, csError = null, csLoading = false;
    // مراقبة المنصة ومستشار التطوير (المرحلة 7)
    let ops = null, opsError = null, opsLoading = false, opsIssue = null;
    let co = null, coError = null, coFilters = { page: 1 }, coProfile = null, coDups = null, coLoading = false;
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

    function companiesTab() {
      if (coError) return `<section class="br-card"><h3>Tourism Companies</h3><p class="br-muted">${esc(coError)}</p></section>`;
      if (!co) return '<section class="br-card"><h3>Tourism Companies</h3><p class="br-muted">Loading the company database…</p></section>';
      const s = co.stats, r = co.results, f = coFilters, owner = co.owner;
      const opts = (obj, cur) => '<option value="">All</option>' + Object.entries(obj || {}).map(([k, n]) => `<option value="${esc(k)}" ${cur === k ? 'selected' : ''}>${esc(k)} (${num(n)})</option>`).join('');
      const rows = r.items.map(x => `<tr data-co="${x.id}" style="cursor:pointer"><td dir="auto"><b>${esc(x.company || '—')}</b><div class="br-muted" dir="ltr">${esc(x.domain || '')}</div></td><td>${esc(x.country || '?')}${x.city ? ' · ' + esc(x.city) : ''}</td>
        <td>${esc(x.status)}</td><td>${x.score === null ? '—' : num(x.score)}</td><td>${x.services.map(v => esc(v.label)).join(', ') || '—'}</td><td>${x.has_email == 1 ? '✓' : '—'}</td><td>${num(x.messages)}</td><td>${esc((x.created_at || '').slice(0, 10))} · ${esc(x.source || '')}</td></tr>`).join('');
      const pages = Math.max(1, Math.ceil(r.total / r.per));
      return `<section class="br-card"><h3>Tourism Companies</h3>
        <p class="br-muted">Every tourism business discovered or imported (Apify, CSV/JSON), kept with its analysis history, contact history and consent log. Separate from platform customers. Published contact details are <b>not</b> marketing consent.</p>
        <div class="br-tiles">${tile(num(s.total), 'Companies')}${tile(num(s.with_email), 'With email')}${tile(num(s.analysed), 'Analysed')}${tile(num(s.converted), 'Became customers')}${tile(num(s.do_not_contact), 'Do not contact')}${tile(num(s.reports), 'Saved reports', num(s.suppressed) + ' erased & blocked from re-import')}</div>
        <form id="coForm" class="ac-form"><input class="p-select" name="q" dir="auto" placeholder="Company, domain or city" value="${esc(f.q || '')}">
          <select class="p-select" name="country">${opts(s.countries, f.country)}</select><select class="p-select" name="status">${opts(s.statuses, f.status)}</select><select class="p-select" name="track">${opts(s.tracks, f.track)}</select>
          <input class="p-select" name="min_score" type="number" min="0" max="100" placeholder="Min score" value="${esc(f.min_score || '')}" style="max-width:110px">
          <label class="br-muted"><input type="checkbox" name="has_email" ${f.has_email ? 'checked' : ''}> has email</label><label class="br-muted"><input type="checkbox" name="converted" ${f.converted ? 'checked' : ''}> became customer</label>
          <button class="p-btn primary" type="submit">Search</button></form>
        <p class="br-muted">${num(r.total)} companies · page ${r.page} of ${pages}</p>
        ${rows ? `<div class="p-table-scroll"><table class="p-table"><thead><tr><th>Company</th><th>Place</th><th>Status</th><th>Lead score</th><th>Likely needs</th><th>Email</th><th>Messages</th><th>Added · source</th></tr></thead><tbody>${rows}</tbody></table></div>` : '<p class="br-muted">No companies match.</p>'}
        <div class="br-actions">${r.page > 1 ? '<button type="button" class="p-btn xs outline" data-co-page="-1">Previous</button>' : ''}${r.page < pages ? '<button type="button" class="p-btn xs outline" data-co-page="1">Next</button>' : ''}</div></section>
        ${coProfile ? profileCard(coProfile, owner) : ''}
        <section class="br-card"><div class="br-head"><div><h3>Possible duplicates</h3><p class="br-muted">Same domain, same phone, or same name in the same city. Merging fills the primary's empty fields and pauses the duplicate; it can be undone.</p></div>
          <button type="button" class="p-btn xs outline" id="coDupsBtn">${coDups ? 'Refresh' : 'Find duplicates'}</button></div>
          ${coDups ? (coDups.length ? `<ul class="br-proof">${coDups.map(g => `<li>${esc(g.reason)} <span dir="ltr">${esc(g.key)}</span>: ${g.ids.map(i => `<a href="#companies" data-co="${i}">#${i}</a>`).join(', ')}
            ${owner ? g.ids.slice(1).map(i => `<button type="button" class="p-btn xs outline" data-merge="${g.ids[0]}:${i}:${esc(g.reason)}">Merge #${i} into #${g.ids[0]}</button>`).join('') : ''}</li>`).join('')}</ul>` : '<p class="br-muted">No duplicates found.</p>') : ''}</section>`;
    }

    const REGION = { us: 'United States', ca: 'Canada', eu: 'Europe (EU/EEA/UK/CH)', gcc: 'Gulf (GCC)', other: 'Rest of world' };
    const BASIS_TEXT = {
      us: 'CAN-SPAM: business email allowed with a clear unsubscribe link, honest subject and the company postal address.',
      eu: 'GDPR + ePrivacy: only with a documented legitimate-interest assessment, and only to generic company addresses (info@, booking@…). Germany and Austria always need opt-in.',
      gcc: 'UAE/Saudi PDPL and similar: confirm your basis; only generic company addresses are used.',
      other: 'Other countries (e.g. Egypt PDPL): confirm your basis per your legal advice; only generic company addresses are used.',
    };
    function salesTab() {
      if (salesError) return `<section class="br-card"><h3>Leads & Sales</h3><p class="br-muted">${esc(salesError)}</p></section>`;
      if (!sales) return '<section class="br-card"><h3>Leads & Sales</h3><p class="br-muted">Loading…</p></section>';
      const s = sales.settings, pl = sales.pipeline, q = sales.queue, owner = sales.owner, t = sales.today;
      const st = pl.stages, stagesHtml = Object.entries(st).map(([k, v]) => tile(num(v), k.replace('_', ' '))).join('');
      const regRows = Object.entries(pl.by_region).map(([r, v]) => `<tr><td>${esc(REGION[r] || r)}</td>${Object.values(v).map(n => `<td>${num(n)}</td>`).join('')}</tr>`);
      const srcRows = Object.entries(pl.by_source).map(([r, v]) => `<tr><td dir="ltr">${esc(r)}</td>${Object.values(v).map(n => `<td>${num(n)}</td>`).join('')}</tr>`);
      const head = '<tr><th></th>' + Object.keys(st).map(k => `<th>${esc(k.replace('_', ' '))}</th>`).join('') + '</tr>';
      const reasons = Object.entries(q.counts.reasons || {}).map(([r, n]) => `<li>${esc(r)}: ${num(n)}</li>`).join('');
      const qRows = q.items.map(i => `<tr><td dir="auto">${esc(i.company || '—')}</td><td>${esc(i.country || '?')} · ${esc(i.verdict.region)}</td><td>${num(i.icp_fit)}</td><td>${i.score ?? '—'}</td><td>${esc(i.track || '')}</td>
        <td>${i.verdict.ok ? `✓ ${esc(i.verdict.basis)}` : `<span class="br-muted">${i.verdict.reason_texts.map(esc).join('; ')}</span>`}</td><td>${owner && i.verdict.ok ? `<button type="button" class="p-btn xs outline" data-sapprove="${i.id}">Approve</button>` : ''}</td></tr>`);
      return `<section class="br-card"><h3>Lawful basis & targeting</h3>
        <p class="br-muted">No first message is approved unless it passes this gate. This is a conservative safeguard, not legal advice. When in doubt, keep a region off and ask your lawyer.</p>
        <p>${s.has_address ? '✓ Company postal address is set in outreach emails.' : '⚠️ <b>Postal address missing</b>: add the full company address in <a href="/admin/growth">platform outreach settings</a>. Until then nothing is approved.'}</p>
        ${['us', 'eu', 'gcc', 'other'].map(r => `<label class="br-check-row"><input type="checkbox" data-basis="${r}" ${s.basis[r] ? 'checked' : ''} ${owner ? '' : 'disabled'}> <span><b>${esc(REGION[r])}: I confirm a lawful basis</b><small>${esc(BASIS_TEXT[r])}</small></span></label>`).join('')}
        <p class="br-muted">Canada (CASL) always needs a recorded opt-in.</p>
        <div class="br-facts"><label class="br-field">Target markets<span>${['us', 'eu', 'gcc', 'other', 'ca'].map(r => `<label class="br-muted"><input type="checkbox" data-target="${r}" ${s.targets.includes(r) ? 'checked' : ''} ${owner ? '' : 'disabled'}> ${esc(REGION[r])}</label>`).join(' ')}</span></label>
          <label class="br-field">Sales & marketing spend per month (USD)<input class="p-select" type="number" min="0" step="any" id="slSpend" value="${s.spend_usd_month ?? ''}" ${owner ? '' : 'disabled'}><small>Used only to compute CAC.</small></label></div>
        ${owner ? '<div class="br-actions"><button type="button" class="p-btn primary" id="slSave">Save</button></div>' : ''}</section>

        <section class="br-card"><h3>Sales pipeline</h3><div class="br-tiles">${stagesHtml}</div>
        <p class="br-muted">Contacted → engaged ${num(pl.conversion.contacted_to_engaged)}% · → interested ${num(pl.conversion.contacted_to_interested)}% · → signed up ${num(pl.conversion.contacted_to_signed_up)}% · signed up → paying ${num(pl.conversion.signed_up_to_paying)}% · CAC ${pl.cac_usd === null ? '—' : '$' + num(pl.cac_usd)} (${esc(pl.cac_note)})</p>
        <details class="br-past"><summary>By region</summary><div class="p-table-scroll"><table class="p-table"><thead>${head}</thead><tbody>${regRows.join('')}</tbody></table></div></details>
        <details class="br-past"><summary>By source / channel</summary><div class="p-table-scroll"><table class="p-table"><thead>${head}</thead><tbody>${srcRows.join('')}</tbody></table></div></details></section>

        <section class="br-card"><h3>Today & reports</h3><p>Emails sent ${num(t.sent)} (opened ${num(t.opened)}, clicked ${num(t.clicked)}) · report views ${num(t.report_views)} · WhatsApp clicks ${num(t.whatsapp_clicks)} · interested ${num(t.interested)} · signed up ${num(t.signed_up)} · unsubscribed ${num(t.unsubscribed)}</p>
        ${sales.reports.length ? `<details class="br-past"><summary>Daily & weekly reports (${sales.reports.length})</summary>${sales.reports.map(r => `<p><b>${esc(r.title)}</b><br><span style="white-space:pre-wrap">${esc(r.body || '')}</span></p>`).join('')}</details>` : '<p class="br-muted">Reports are written by the hourly AI CEO cron (daily, and weekly on Mondays).</p>'}</section>

        <section class="br-card"><div class="br-head"><div><h3>First-message approvals</h3><p class="br-muted">${num(q.counts.ok)} may be contacted · ${num(q.counts.blocked)} blocked by the gate. Sorted by ideal-customer fit.</p></div>
          <div class="br-actions">${owner && q.counts.ok ? `<button type="button" class="p-btn primary xs" id="slApproveAll">Approve all ${num(q.counts.ok)} compliant</button>` : ''}<button type="button" class="p-btn outline xs" id="slReeval">Re-check approved queue</button></div></div>
        ${reasons ? `<details class="br-past"><summary>Why companies are blocked</summary><ul class="br-proof">${reasons}</ul></details>` : ''}
        ${qRows.length ? `<div class="p-table-scroll"><table class="p-table"><thead><tr><th>Company</th><th>Country · region</th><th>ICP fit</th><th>Score</th><th>Need</th><th>Gate</th><th></th></tr></thead><tbody>${qRows.join('')}</tbody></table></div>` : '<p class="br-muted">No companies waiting for approval.</p>'}</section>

        ${sales.active_noncompliant.length ? `<section class="br-card"><h3>Running sequences the gate now rejects (${sales.active_noncompliant.length})</h3><ul class="br-proof">${sales.active_noncompliant.slice(0, 30).map(x => `<li>#${x.id} ${esc(x.company || '')} · ${esc(x.country || '?')} · step ${x.step}: ${x.reasons.map(esc).join('; ')}</li>`).join('')}</ul>
          ${owner ? `<button type="button" class="p-btn xs" style="background:var(--panel-danger,#dc2626);color:#fff" id="slPause">Pause these ${sales.active_noncompliant.length}</button>` : ''}</section>` : ''}

        <section class="br-card"><h3>CRM handoff</h3><p>${num(pl.crm_pending)} replied or interested companies are not in the CRM yet. Moving creates a contact and a deal with a follow-up tomorrow in the platform owner's CRM.</p>
          ${owner && pl.crm_pending ? '<button type="button" class="p-btn primary xs" id="slCrm">Move to CRM</button>' : ''}<p class="br-muted">Not built yet (needs your decision): reading inbound replies by email, A/B tests of templates, a referral program and partnership discovery.</p></section>`;
    }

    const SEV = { critical: '🔴', warn: '🟠', info: '⚪' };
    const convTable = (rows, label) => rows.length ? `<div class="p-table-scroll"><table class="p-table"><thead><tr><th>${esc(label)}</th><th>Visitors</th><th>Sign-ups</th><th>Conversion</th></tr></thead><tbody>${rows.map(r => `<tr><td dir="ltr">${esc(r.key)}</td><td>${num(r.visitors)}</td><td>${num(r.signups)}</td><td>${r.conversion === null ? '—' : num(r.conversion) + '%'}</td></tr>`).join('')}</tbody></table></div>` : '<p class="br-muted">No data in the last 30 days.</p>';
    function planView(p, warnings) {
      if (!p) return '';
      const w = warnings || p._warnings || [];
      return `${w.length ? `<p class="br-note error">Remove before publishing: ${w.map(esc).join('; ')}</p>` : ''}${p.positioning ? `<p><b>Positioning:</b> ${esc(p.positioning)}</p>` : ''}
        ${(p.markets || []).map(m => `<details class="br-past"><summary>${esc(String(m.market || '').toUpperCase())} · ${esc(m.language || '')} · ${(m.posts || []).length} posts, ${(m.articles || []).length} articles, ${(m.videos || []).length} videos</summary>
          <ol>${(m.posts || []).map(x => `<li dir="auto"><b>${esc(x.channel || '')} · ${esc(x.format || '')}</b> — ${esc(x.topic || '')}<br><small>${esc(x.hook || '')} · feature: ${esc(x.feature || '')} · ${esc(x.landing_page || '')}</small></li>`).join('')}</ol>
          ${(m.articles || []).map(a => `<p dir="auto"><b>Article:</b> ${esc(a.title || '')} <small>(${esc(a.keyword || '')})</small></p>`).join('')}
          ${(m.videos || []).map(v => `<p dir="auto"><b>Video:</b> ${esc(v.title || '')} <small>${(v.script_outline || []).map(esc).join(' → ')}</small></p>`).join('')}</details>`).join('')}
        ${p.ad_drafts ? `<details class="br-past"><summary>Ad drafts (not running; paid ads are off)</summary>${(p.ad_drafts.google || []).map(g => `<p dir="auto"><b>Google · ${esc(g.market || '')}</b>: ${(g.headlines || []).map(esc).join(' | ')}<br><small>${(g.keywords || []).map(esc).join(', ')} → ${esc(g.landing_page || '')}</small></p>`).join('')}
          ${(p.ad_drafts.meta || []).map(g => `<p dir="auto"><b>Meta · ${esc(g.market || '')}</b> (${esc(g.audience || '')}): ${esc(g.primary_text || '')} → ${esc(g.landing_page || '')}</p>`).join('')}</details>` : ''}
        ${(p.kpis || []).length ? `<p class="br-muted">KPIs: ${p.kpis.map(esc).join(' · ')}</p>` : ''}`;
    }
    function brandTab() {
      if (brandError) return `<section class="br-card"><h3>Brand Growth</h3><p class="br-muted">${esc(brandError)}</p></section>`;
      if (!brand) return '<section class="br-card"><h3>Brand Growth</h3><p class="br-muted">Loading…</p></section>';
      const a = brand.audit, acq = brand.acquisition, pr = brand.presence, owner = brand.owner;
      const delta = a && a.previous_score !== null ? ` (${a.score - a.previous_score >= 0 ? '+' : ''}${a.score - a.previous_score} since last)` : '';
      const issueRows = a ? Object.entries(a.summary.issue_counts || {}).sort((x, y) => y[1] - x[1]).map(([k, n]) => `<li>${esc(k.replace(/_/g, ' '))}: ${num(n)}</li>`).join('') : '';
      const pageRows = a ? (a.pages || []).filter(p => p.issues.length).sort((x, y) => y.issues.filter(i => i[0] === 'critical').length - x.issues.filter(i => i[0] === 'critical').length).slice(0, 40)
        .map(p => `<tr><td dir="ltr"><a href="${esc(p.url)}" target="_blank" rel="noopener">${esc(p.url.replace(/^https?:\/\/[^/]+/, '') || '/')}</a></td><td>${p.code}</td><td>${num(p.ms)} ms</td><td>${p.issues.map(i => `${SEV[i[0]] || ''} ${esc(i[2])}`).join('<br>')}</td></tr>`).join('') : '';
      const conns = pr.website ? (pr.connections === null ? '<p class="br-muted">Connections unavailable.</p>' : `<p>Connected: ${pr.connections.length ? pr.connections.map(c => `${esc(c.platform)} (${esc(c.status)})`).join(' · ') : 'nothing yet'}</p>`) : '';
      return `<section class="br-card"><div class="br-head"><div><h3>Tourfecto website SEO / AEO</h3><p class="br-muted">${esc(brand.site_url)} · free check of up to 40 pages from the sitemap (titles, descriptions, indexing, canonical, structured data, speed, llms.txt). It runs weekly from the cron.</p></div>
          <div class="br-actions"><button type="button" class="p-btn primary xs" id="bgAudit">Run audit now</button></div></div>
        ${a ? `<div class="br-tiles">${tile(a.score + '/100', 'SEO score', delta)}${tile(num(a.summary.pages), 'Pages checked', num(a.summary.ok_pages) + ' OK')}${tile(num(a.summary.critical), 'Critical issues')}${tile(a.summary.avg_ms === null ? '—' : num(a.summary.avg_ms) + ' ms', 'Avg load')}</div>
          <p class="br-muted">Last run ${esc(a.run_at)} UTC</p>
          ${(a.summary.site_issues || []).length ? `<ul class="br-proof">${a.summary.site_issues.map(i => `<li>${SEV[i[0]] || ''} ${esc(i[2])}</li>`).join('')}</ul>` : ''}
          ${issueRows ? `<details class="br-past"><summary>Issues by type</summary><ul class="br-proof">${issueRows}</ul></details>` : ''}
          ${pageRows ? `<details class="br-past"><summary>Pages with issues</summary><div class="p-table-scroll"><table class="p-table"><thead><tr><th>Page</th><th>HTTP</th><th>Load</th><th>Issues</th></tr></thead><tbody>${pageRows}</tbody></table></div></details>` : '<p class="br-done">✓ No page issues.</p>'}`
        : '<p class="br-muted">No audit yet. Run one now (free, about a minute).</p>'}</section>

        <section class="br-card"><h3>Where visitors and sign-ups come from (30 days)</h3>
        <div class="br-tiles">${tile(num(acq.visitors), 'Visitors (public pages)')}${tile(num(acq.signups), 'Customer sign-ups')}${tile(num(acq.signups_unattributed), 'Sign-ups without a tracked visit')}</div>
        <p class="br-muted">A sign-up is credited to the first page and referrer of the visitor who signed up. From the platform's own visitor log; logged-in, admin and API pages are excluded.</p>
        <details class="br-past" open><summary>Landing pages</summary>${convTable(acq.landing_pages, 'Page')}</details>
        <details class="br-past"><summary>Referrers</summary>${convTable(acq.referrers, 'Source')}</details>
        <details class="br-past"><summary>Countries</summary>${convTable(acq.countries, 'Country')}</details></section>

        <section class="br-card"><h3>Tourfecto on its own platform</h3>
        ${pr.website ? `<p>Website #${pr.website.id} <b dir="auto">${esc(pr.website.name || '')}</b> (${esc(pr.website.url)}) in the owner account.</p>${conns}
          ${pr.reviews ? `<p>Google reviews ${num(pr.reviews.total)} · average ${pr.reviews.avg_rating ?? '—'} · unanswered 1–3★ ${num(pr.reviews.unanswered_negative)}</p>` : ''}
          ${pr.social ? `<p>Social posts: ${num(pr.social.scheduled)} scheduled · ${num(pr.social.published_30d)} published in 30 days</p>` : ''}`
        : `<p>⚠️ ${esc(pr.note)}</p><a class="p-btn outline xs" href="/websites">Add the website</a>`}</section>

        <section class="br-card"><div class="br-head"><div><h3>Monthly content & ads plan</h3><p class="br-muted">AI writes posts, articles, a video and ad drafts per market, using only the ${num(brand.features)} features on the public feature pages and the sign-up data above. Uses the AI CEO budget. Nothing is published and no ad money is spent.</p></div></div>
        ${owner ? `<div class="br-facts"><label class="br-field">Month<input class="p-select" type="month" id="bgMonth" value="${esc(new Date(new Date().getFullYear(), new Date().getMonth() + 1, 1).toISOString().slice(0, 7))}"></label>
          <label class="br-field">Markets<span>${['us', 'eu', 'gcc', 'other'].map(r => `<label class="br-muted"><input type="checkbox" data-bgm="${r}" ${r !== 'other' ? 'checked' : ''}> ${esc(REGION[r])}</label>`).join(' ')}</span></label></div>
          <div class="br-actions"><button type="button" class="p-btn primary" id="bgPlan">Write the plan</button></div>` : '<p class="br-muted">Only the platform owner can create a plan.</p>'}
        ${brandPlan ? `<h4>${esc(brandPlan.title)}</h4>${planView(brandPlan.plan, brandPlan.warnings)}` : ''}
        ${brand.plans.length ? `<details class="br-past"><summary>Saved plans (${brand.plans.length})</summary>${brand.plans.map(x => `<h4>${esc(x.title)} <small class="br-muted">${esc(x.created_at)}</small></h4>${planView(x.plan)}`).join('')}</details>` : ''}</section>`;
    }

    const money = v => v === null || v === undefined ? '—' : '$' + num(v);
    const STEP = { signed_up: 'Signed up', website: 'Added website', connected: 'Connected a service', used: 'Used a service', trial: 'Trial', paying: 'Paying' };
    const unavailable = '<p class="br-muted">Not available (the data source is missing on this server).</p>';
    function successTab() {
      if (csError) return `<section class="br-card"><h3>Customer Success</h3><p class="br-muted">${esc(csError)}</p></section>`;
      if (!cs) return '<section class="br-card"><h3>Customer Success</h3><p class="br-muted">Loading…</p></section>';
      const r = cs.report, m = r.metrics, f = r.funnel, p = r.payments, rn = r.renewals, c = r.cancellations, sp = r.support, o = r.opportunities, sv = r.services, fc = r.forecast, owner = cs.owner, st = r.settings;
      const custLink = x => `<a href="/admin/users/${x.user_id}" dir="auto">${esc(x.company || '#' + x.user_id)}</a>`;
      return `<section class="br-card"><h3>Revenue & unit economics</h3><div class="br-tiles">${tile(money(m.mrr), 'MRR', m.mrr_prev !== null ? '30 days ago ' + money(m.mrr_prev) : '')}${tile(money(m.arr), 'ARR')}${tile(num(m.paying), 'Paying customers')}${tile(money(m.arpu), 'ARPU')}
          ${tile(m.logo_churn === null ? '—' : num(m.logo_churn) + '%', 'Churn (30 days)', m.revenue_churn === null ? '' : 'revenue ' + num(m.revenue_churn) + '%')}${tile(m.retention === null ? '—' : num(m.retention) + '%', 'Retention')}${tile(money(m.ltv), 'LTV', m.ltv === null ? 'needs churn history' : '')}${tile(money(m.cac), 'CAC', m.cac === null ? 'enter spend in Leads & Sales' : '')}${tile(m.ltv_cac === null ? '—' : num(m.ltv_cac) + '×', 'LTV : CAC')}${tile(m.trial_conversion === null ? '—' : num(m.trial_conversion) + '%', 'Trial → paid')}</div>
        ${fc ? `<h4>Revenue forecast (MRR)</h4><div class="p-table-scroll"><table class="p-table"><thead><tr><th>Case</th>${Object.keys(fc.cases.expected).map(h => `<th>${h} months</th>`).join('')}</tr></thead><tbody>${Object.entries(fc.cases).map(([k, v]) => `<tr><td>${esc(k)}</td>${Object.values(v).map(x => `<td>${money(x)}</td>`).join('')}</tr>`).join('')}</tbody></table></div>
          <p class="br-muted">From ${money(fc.mrr_now)} today, churn ${num(fc.assumptions.monthly_churn_pct)}%/month and ${money(fc.assumptions.new_mrr_per_month)} new MRR/month (90-day average). Conservative: ${esc(fc.assumptions.conservative)}; optimistic: ${esc(fc.assumptions.optimistic)}. Confidence: ${esc(fc.confidence)}. ${esc(fc.note)}</p>` : ''}</section>

        <section class="br-card"><h3>Customer journey (sign-ups in the last 90 days)</h3>${f ? `<div class="br-tiles">${Object.entries(f.stages).map(([k, v]) => tile(num(v), STEP[k] || k)).join('')}</div>
          ${f.biggest_drop ? `<p>Biggest drop: <b>${esc(STEP[f.biggest_drop.from])} → ${esc(STEP[f.biggest_drop.to])}</b>: ${num(f.biggest_drop.lost)} customers (${num(f.biggest_drop.rate)}% continue).</p>` : ''}
          <details class="br-past"${f.stuck_count ? ' open' : ''}><summary>Onboarding assistant: ${num(f.stuck_count)} customers stuck for 3+ days</summary><p class="br-muted">Suggested messages for the next step. Nothing is sent automatically: copy, review and send them yourself (email or WhatsApp with their consent).</p>
          ${f.stuck.length ? `<div class="p-table-scroll"><table class="p-table"><thead><tr><th>Customer</th><th>Signed up</th><th>Next step</th><th>Suggested message</th></tr></thead><tbody>${f.stuck.map(x => `<tr><td>${custLink(x)}<br><small dir="ltr">${esc(x.email || '')}</small></td><td>${esc((x.signed_up || '').slice(0, 10))}</td><td>${esc(x.next_step_label)}</td><td><p dir="rtl" lang="ar">${esc(x.message.ar)}</p><p dir="ltr" class="br-muted">${esc(x.message.en)}</p></td></tr>`).join('')}</tbody></table></div>` : '<p class="br-done">✓ Nobody is stuck.</p>'}</details>` : unavailable}</section>

        <section class="br-card"><h3>Payments, renewals and cancellations</h3>
        ${p ? `<p>Last 30 days: ${Object.entries(p.by_status).map(([k, v]) => `${esc(k)} ${num(v.count)} (${money(v.amount)})`).join(' · ') || 'no payment attempts'}${p.failure_rate !== null ? ` · failure rate ${num(p.failure_rate)}%` : ''} · stuck over 1 hour ${num(p.stuck_pending)}</p>
          ${p.past_due.length ? `<p><b>Past due:</b> ${p.past_due.map(x => `${custLink(x)} (${money(x.mrr)})`).join(' · ')}</p>` : ''}` : unavailable}
        ${rn ? `<p>Renewals in 30 days: ${num(rn.count)} (${money(rn.mrr)} MRR) · <b>${num(rn.cancelling)} set to cancel</b> (${money(rn.mrr_cancelling)})</p>
          ${rn.items.length ? `<details class="br-past"><summary>Upcoming renewals</summary><ul class="br-proof">${rn.items.map(x => `<li>${custLink(x)} · ${esc(x.plan || '')} · ${money(x.mrr)} · ${esc((x.renews_at || '').slice(0, 10))}${x.cancelling ? ' · ⚠️ cancelling' : ''}</li>`).join('')}</ul></details>` : ''}` : ''}
        ${c ? `<p>Cancellations in 180 days: ${num(c.total)}</p>${c.total ? `<details class="br-past"><summary>Why customers cancel</summary><ul class="br-proof">${Object.entries(c.reasons).map(([k, v]) => `<li dir="auto">${esc(k)}: ${num(v)}</li>`).join('')}</ul>
          ${Object.keys(c.themes).length ? `<p class="br-muted">Themes: ${Object.entries(c.themes).map(([k, v]) => `${esc(k.replace('_', ' / '))} ${v}`).join(' · ')}</p>` : ''}</details>` : ''}` : ''}</section>

        <section class="br-card"><h3>Health, upsell and cross-sell</h3>${o ? `<div class="br-tiles">${tile(num(o.health.healthy), 'Healthy')}${tile(num(o.health.watch), 'Watch')}${tile(num(o.health.at_risk), 'At risk')}${tile(money(o.mrr_at_risk), 'MRR at risk')}</div>
          <p class="br-muted">Health score 0–100 per customer (activity 30, connections 20, usage 20, payment 20, setup 10). Full list in <a href="/admin/customers-health">Customer health</a>.</p>
          ${o.at_risk.length ? `<details class="br-past" open><summary>Paying customers at risk (${o.at_risk.length})</summary><ul class="br-proof">${o.at_risk.map(x => `<li>${custLink(x)} · ${x.score}/100 · ${money(x.mrr)} · ${(x.reasons || []).map(esc).join('، ')}</li>`).join('')}</ul></details>` : ''}
          <details class="br-past"><summary>Upsell candidates (${o.upsell.length})</summary>${o.upsell.length ? `<ul class="br-proof">${o.upsell.map(x => `<li>${custLink(x)} · ${esc(x.plan || '')}: ${esc(x.why)}</li>`).join('')}</ul>` : '<p class="br-muted">None right now.</p>'}</details>
          <details class="br-past"><summary>Cross-sell: help them connect services (${o.cross_sell.length})</summary>${o.cross_sell.length ? `<ul class="br-proof">${o.cross_sell.map(x => `<li>${custLink(x)}: ${esc(x.why)}</li>`).join('')}</ul>` : '<p class="br-muted">None right now.</p>'}</details>
          ${o.profit ? `<p>Last 30 days: revenue ${money(o.profit.revenue_30d)} − AI cost ${money(o.profit.ai_cost_30d)} = <b>${money(o.profit.margin_30d)}</b>${o.profit.margin_pct !== null ? ` (${num(o.profit.margin_pct)}%)` : ''}${sp && sp.cost_usd !== null ? ` · support cost ${money(sp.cost_usd)}` : ''}</p>` : ''}` : unavailable}</section>

        <section class="br-card"><h3>Services & plans</h3>
        ${sv ? `<div class="p-table-scroll"><table class="p-table"><thead><tr><th>Service (AI task)</th><th>Uses</th><th>Customers</th><th>Paying adoption</th><th>AI cost</th><th>Cost / customer</th></tr></thead><tbody>${sv.items.slice(0, 25).map(x => `<tr><td dir="ltr">${esc(x.feature)}</td><td>${num(x.calls)}</td><td>${num(x.users)}</td><td>${x.adoption_pct === null ? '—' : num(x.adoption_pct) + '%'}</td><td>${money(x.cost_usd)}</td><td>${money(x.cost_per_user)}</td></tr>`).join('')}</tbody></table></div>` : unavailable}
        ${o && o.profit && o.profit.by_plan.length ? `<h4>Plans</h4><div class="p-table-scroll"><table class="p-table"><thead><tr><th>Plan</th><th>Customers</th><th>Price / month</th><th>AI cost / customer</th><th>Margin</th></tr></thead><tbody>${o.profit.by_plan.map(x => `<tr><td dir="auto">${esc(x.plan)}</td><td>${num(x.customers)}</td><td>${money(x.price)}</td><td>${money(x.avg_ai_cost)}</td><td>${x.margin_pct === null ? '—' : num(x.margin_pct) + '%'}</td></tr>`).join('')}</tbody></table></div>` : ''}
        ${r.pricing.length ? `<ul class="br-proof">${r.pricing.map(x => `<li>${esc(x.text)}</li>`).join('')}</ul>` : '<p class="br-muted">No pricing suggestions from the current data.</p>'}
        <p class="br-muted">Suggestions only: prices, plans and services are never changed automatically.</p></section>

        <section class="br-card"><h3>Support</h3>${sp ? `<p>${num(sp.total)} messages in 90 days · <b>${num(sp.unanswered)} unanswered</b>${sp.oldest_unanswered ? ` (oldest ${esc(sp.oldest_unanswered.slice(0, 10))})` : ''} · ${num(sp.from_paying)} from paying customers · <a href="/admin/contact-messages">Open messages</a></p>
          ${Object.keys(sp.themes).length ? `<p>Topics: ${Object.entries(sp.themes).map(([k, v]) => `${sp.recurring.includes(k) ? '<b>' : ''}${esc(k.replace('_', ' / '))} ${v}${sp.recurring.includes(k) ? '</b>' : ''}`).join(' · ')}</p><p class="br-muted">Topics with 3+ messages become a development decision in Approvals.</p>` : ''}
          <p class="br-muted">${esc(sp.cost_note)}${sp.cost_per_customer_usd !== null ? ` Cost per customer: ${money(sp.cost_per_customer_usd)}.` : ''}</p>` : unavailable}
        ${owner ? `<div class="br-facts"><label class="br-field">Minutes per support message<input class="p-select" type="number" min="0" step="any" id="csMin" value="${st.support_minutes ?? ''}"></label>
          <label class="br-field">Support cost per hour (USD)<input class="p-select" type="number" min="0" step="any" id="csHour" value="${st.support_cost_hour ?? ''}"></label>
          <label class="br-field">Market median plan price (USD/month)<input class="p-select" type="number" min="0" step="any" id="csMarket" value="${st.market_median_price ?? ''}"><small>From your competitor research; used for pricing suggestions.</small></label></div>
          <div class="br-actions"><button type="button" class="p-btn primary xs" id="csSave">Save</button></div>` : ''}</section>`;
    }

    const SEVC = { critical: '🔴 Critical', high: '🟠 High', medium: '🟡 Medium', low: '⚪ Low' };
    const STC = { ok: '✓', fail: '✗', warn: '!', slow: '⏱' };
    function opsTab() {
      if (opsError) return `<section class="br-card"><h3>Platform & Dev Advisor</h3><p class="br-muted">${esc(opsError)}</p></section>`;
      if (!ops) return '<section class="br-card"><h3>Platform & Dev Advisor</h3><p class="br-muted">Loading…</p></section>';
      const last = ops.checks[0], owner = ops.owner, st = ops.settings, ab = ops.abuse, bk = ops.backups;
      const trend = ops.checks.slice().reverse().map(c => c.failed ? '✗' : (c.slow ? '⏱' : '✓')).join('');
      const bRow = b => `<li class="br-past" style="list-style:none"><details><summary><b>${b.priority.toFixed(1)}</b> · ${esc(SEVC[b.severity])} · <b dir="auto">[${esc(b.service)}]</b> <span dir="auto">${esc(b.problem)}</span> · cost ${esc(b.cost_estimate)} · ${esc(b.status.replace('_', ' '))}${b.needs_approval ? ' · needs approval' : ''}</summary>
        <dl class="br-facts"><dt>Evidence</dt><dd dir="auto">${esc(b.evidence || '—')}</dd><dt>Improvement</dt><dd dir="auto">${esc(b.improvement || '—')}</dd><dt>Benefit</dt><dd dir="auto">${esc(b.benefit || '—')}</dd><dt>How to test</dt><dd dir="auto">${esc(b.test_method || '—')}</dd>
        <dt>Before</dt><dd>${esc(b.metric_before || '—')}</dd>${b.metric_after ? `<dt>After</dt><dd>${esc(b.metric_after)}</dd>` : ''}<dt>Source</dt><dd>${esc(b.source)}</dd></dl>
        ${b.issue_url ? `<p><a href="${esc(b.issue_url)}" target="_blank" rel="noopener">GitHub issue ↗</a></p>` : ''}
        ${owner ? `<div class="br-actions">${['approved', 'in_progress', 'done', 'rejected'].filter(x => x !== b.status).map(x => `<button type="button" class="p-btn outline xs" data-bstatus="${x}" data-bid="${b.id}">${esc(x.replace('_', ' '))}</button>`).join('')}
          <button type="button" class="p-btn outline xs" data-bissue="${b.id}">${st.github_ready ? 'Create GitHub issue' : 'Copy issue text'}</button></div>` : ''}</details></li>`;
      return `<section class="br-card"><div class="br-head"><div><h3>Page checks</h3><p class="br-muted">Every cron cycle the platform's own pages are opened without login: public pages must answer 200, protected pages must redirect to login. No accounts or customer data are used. ${last ? esc(last.base_url) : ''}</p></div>
          <div class="br-actions"><button type="button" class="p-btn primary xs" id="opRun">Run checks now</button></div></div>
        ${last ? `<p>Last run ${esc(last.run_at)} UTC · ${num(last.checked)} pages · <b>${num(last.failed)} down</b> · ${num(last.slow)} slow · last ${ops.checks.length} runs: <span dir="ltr">${trend}</span></p>
          <div class="p-table-scroll"><table class="p-table"><thead><tr><th>Page</th><th>Type</th><th>HTTP</th><th>Time</th><th>Result</th></tr></thead><tbody>${last.results.map(r => `<tr><td dir="ltr">${esc(r.path)}</td><td>${esc(r.kind)}</td><td>${r.code}</td><td>${num(r.ms)} ms</td><td>${STC[r.status] || ''} ${esc(r.note || r.status)}</td></tr>`).join('')}</tbody></table></div>` : '<p class="br-muted">No checks yet. Run them now or wait for the hourly cron.</p>'}</section>

        <section class="br-card"><h3>Incidents (${ops.incidents.length} open)</h3><p class="br-muted">Opened automatically from failed checks, platform health failures, errors repeated 20+ times a day, failing providers and stale backups; closed automatically when the problem is gone.</p>
        ${ops.incidents.length ? `<ul class="br-proof">${ops.incidents.map(i => `<li>${esc(SEVC[i.severity])} · <b>${esc(i.service)}</b>: <span dir="auto">${esc(i.title)}</span> · ${(i.evidence || []).map(esc).join('; ')} · since ${esc(i.first_seen)} UTC${i.occurrence > 1 ? ` · <b>${i.occurrence}th time</b>` : ''}
          <form class="br-actions" data-rc="${i.id}"><input class="p-select" name="text" placeholder="Root cause (optional)" value="${esc(i.root_cause || '')}" aria-label="Root cause"><button class="p-btn outline xs">Save</button></form></li>`).join('')}</ul>` : '<p class="br-done">✓ No open incidents.</p>'}
        ${ops.recurring.length ? `<details class="br-past" open><summary>Recurring failures (3+ times in 30 days)</summary><ul class="br-proof">${ops.recurring.map(r => `<li><b>${esc(r.service)}</b>: <span dir="auto">${esc(r.title)}</span> · ${r.times} times · last ${esc(r.last_seen)}</li>`).join('')}</ul></details>` : ''}
        ${ops.resolved.length ? `<details class="br-past"><summary>Recently resolved (${ops.resolved.length})</summary><ul class="br-proof">${ops.resolved.map(i => `<li>${esc(i.service)}: <span dir="auto">${esc(i.title)}</span> · ${esc(i.first_seen)} → ${esc(i.resolved_at || '')}${i.root_cause ? ` · cause: <span dir="auto">${esc(i.root_cause)}</span>` : ''}</li>`).join('')}</ul></details>` : ''}</section>

        <section class="br-card"><h3>Development backlog (${ops.backlog.length})</h3><p class="br-muted">Each item: service, problem, evidence, severity, improvement, priority (out of 50), cost (S/M/L), benefit, how to test, and whether it needs approval. Built from recurring incidents, recurring support topics and the Tourfecto site audit, or added by you. High-risk fixes are never deployed to production without your approval.</p>
        ${ops.backlog.length ? `<ul class="br-list">${ops.backlog.map(bRow).join('')}</ul>` : '<p class="br-done">✓ Nothing in the backlog.</p>'}
        ${opsIssue ? `<details class="br-past" open><summary>Issue text (copy into GitHub)</summary><textarea class="p-select" rows="10" readonly style="width:100%" dir="ltr">${esc(opsIssue)}</textarea></details>` : ''}
        ${ops.done.length ? `<details class="br-past"><summary>Done — before / after (${ops.done.length})</summary><ul class="br-proof">${ops.done.map(b => `<li dir="auto">[${esc(b.service)}] ${esc(b.problem)} · before: ${esc(b.metric_before || '—')} · after: ${esc(b.metric_after || '—')}</li>`).join('')}</ul></details>` : ''}
        ${owner ? `<details class="br-past"><summary>Add an item</summary><form id="opAdd" class="br-facts">
          <label class="br-field">Service<input class="p-select" name="service" required maxlength="80"></label><label class="br-field">Problem<input class="p-select" name="problem" required maxlength="500"></label>
          <label class="br-field">Evidence<input class="p-select" name="evidence"></label><label class="br-field">Severity<select class="p-select" name="severity">${['critical', 'high', 'medium', 'low'].map(x => `<option${x === 'medium' ? ' selected' : ''}>${x}</option>`).join('')}</select></label>
          <label class="br-field">Improvement<input class="p-select" name="improvement"></label><label class="br-field">Cost<select class="p-select" name="cost_estimate"><option>S</option><option selected>M</option><option>L</option></select></label>
          <label class="br-field">Benefit<input class="p-select" name="benefit"></label><label class="br-field">How to test<input class="p-select" name="test_method"></label>
          <label class="br-field">Before (metric)<input class="p-select" name="metric_before"></label><div class="br-actions"><button class="p-btn primary xs">Add</button></div></form></details>` : ''}</section>

        <section class="br-card"><h3>Providers & integrations (24 hours)</h3>${ops.providers && ops.providers.length ? `<div class="p-table-scroll"><table class="p-table"><thead><tr><th>Provider</th><th>Calls</th><th>Errors</th><th>Avg time</th><th>Status</th><th>If it fails</th></tr></thead><tbody>${ops.providers.map(p => `<tr><td>${esc(p.provider)}</td><td>${num(p.calls)}</td><td>${num(p.errors)} (${num(p.error_rate)}%)</td><td>${num(p.avg_ms)} ms</td><td>${STC[p.status] || ''} ${esc(p.status)}</td><td>${esc(p.alternative || '—')}</td></tr>`).join('')}</tbody></table></div>` : '<p class="br-muted">No API calls logged in 24 hours.</p>'}
        ${ops.health ? `<details class="br-past"><summary>Platform health: ${esc(ops.health.overall || '?')} (cron, integrations, mail, queue, payments, server, database)</summary>${Object.entries(ops.health.sections).map(([k, sec]) => `<p><b>${esc(k)}</b></p><ul class="br-proof">${sec.checks.map(c => `<li>${STC[c.status] || esc(c.status)} ${esc(c.label)}: ${esc(c.message)}${c.action ? ` <small class="br-muted">→ ${esc(c.action)}</small>` : ''}</li>`).join('')}</ul>`).join('')}</details>` : ''}</section>

        <section class="br-card"><h3>Abuse & security</h3><p class="br-muted">${esc(ab.note)}</p>
          <p>AI usage over ${num(ab.ai_threshold)} actions in 24 hours: ${ab.ai_heavy_users.length ? ab.ai_heavy_users.map(u => `<a href="/admin/users/${u.user_id}" dir="auto">${esc(u.company)}</a> (${num(u.calls)}, $${num(u.cost)})`).join(' · ') : 'none'}</p>
          <p>IPs with 20+ failed logins in the last hour: ${ab.login_attack_ips.length ? ab.login_attack_ips.map(i => `<span dir="ltr">${esc(i.ip)}</span> (${i.failed} tries, ${i.emails} emails)`).join(' · ') : (ab.login_data ? 'none' : 'login history unavailable')}</p></section>

        <section class="br-card"><h3>Backups</h3><p>${STC[bk.status] || ''} ${esc(bk.message)}</p>
        ${owner ? `<div class="br-facts"><label class="br-field">Backup folder (absolute path on the server)<input class="p-select" id="opBackup" dir="ltr" value="${esc(st.backup_dir)}" placeholder="/www/backups/tourfecto"></label>
          <label class="br-field">Address to check (platform domain only)<input class="p-select" id="opBase" dir="ltr" value="${esc(st.base_url)}" placeholder="${esc(last ? last.base_url : '')}"></label>
          <label class="br-check-row"><input type="checkbox" id="opGh" ${st.github_issues ? 'checked' : ''}> <span><b>Allow creating GitHub issues</b><small>Also needs GITHUB_ISSUES_TOKEN and GITHUB_ISSUES_REPO in .env. ${st.github_ready ? 'Ready.' : 'Not ready.'}</small></span></label></div>
          <div class="br-actions"><button type="button" class="p-btn primary xs" id="opSave">Save</button></div>` : ''}</section>`;
    }

    async function loadOps() {
      opsLoading = true;
      try { ops = await req('/api/admin/ai-ceo/ops'); opsError = null; } catch (e) { opsError = e.message; } finally { opsLoading = false; }
      render();
    }

    async function loadSuccess() {
      csLoading = true;
      try { cs = await req('/api/admin/ai-ceo/success'); csError = null; } catch (e) { csError = e.message; } finally { csLoading = false; }
      render();
    }

    async function loadBrand() {
      brandLoading = true;
      try { brand = await req('/api/admin/ai-ceo/brand'); brandError = null; } catch (e) { brandError = e.message; } finally { brandLoading = false; }
      render();
    }

    async function loadSales() {
      salesLoading = true;
      try { sales = await req('/api/admin/ai-ceo/sales'); salesError = null; } catch (e) { salesError = e.message; } finally { salesLoading = false; }
      render();
    }

    function profileCard(p, owner) {
      const c = p.company, j = v => v ? `<pre class="br-muted" style="white-space:pre-wrap;max-height:180px;overflow:auto" dir="ltr">${esc(typeof v === 'string' ? v : JSON.stringify(v, null, 1))}</pre>` : '—';
      return `<section class="br-card" id="coProfile"><div class="br-head"><div><h3 dir="auto">${esc(c.company || '—')} <span class="br-muted">#${c.id}</span></h3>
        <p class="br-muted">${esc(c.country || '?')} · ${esc(c.city || '')} · ${esc(c.category || '')} · status ${esc(c.status)} · score ${c.score ?? '—'} · source ${esc(c.source)} · added ${esc(c.created_at)}</p></div>
        <button type="button" class="p-btn xs outline" id="coClose">Close</button></div>
        <p>${c.website ? `<a href="${esc(c.website)}" target="_blank" rel="noopener" dir="ltr">${esc(c.website)}</a> · ` : ''}${c.email ? '✉ ' + esc(c.email) + ' · ' : ''}${c.phone ? '☎ ' + esc(c.phone) : ''}${c.rating ? ' · ★ ' + esc(c.rating) + ' (' + esc(c.reviews) + ')' : ''}</p>
        <p><b>Social:</b> ${Object.keys(p.socials).length ? Object.entries(p.socials).map(([k, v]) => typeof v === 'string' && v.startsWith('http') ? `<a href="${esc(v)}" target="_blank" rel="noopener">${esc(k)}</a>` : esc(k)).join(' · ') : '—'}</p>
        <p><b>Likely needs:</b> ${p.services.map(v => esc(v.label)).join(', ') || '—'} · <b>Platform customer:</b> ${p.customer ? 'yes (user #' + p.customer.user_id + (p.customer.subscription ? ', ' + esc(p.customer.subscription.status) + (p.customer.subscription.paid ? ', paid' : '') : '') + ')' : 'no'}</p>
        <details class="br-past"><summary>Opportunities (${p.opportunities.length})</summary>${j(p.opportunities)}</details>
        <details class="br-past"><summary>Latest analysis</summary>${j(p.audit)}</details>
        <details class="br-past"><summary>Saved reports (${p.reports.length})</summary>${p.reports.length ? `<ul class="br-proof">${p.reports.map(x => `<li>${esc(x.captured_at)} · ${esc(x.kind)} · score ${x.score ?? '—'}</li>`).join('')}</ul>` : '—'}</details>
        <details class="br-past"><summary>Contact history (${p.messages.length})</summary>${p.messages.length ? `<ul class="br-proof">${p.messages.map(m => `<li>${esc(m.sent_at)} · step ${esc(m.step)} · ${esc(m.status)} · <span dir="auto">${esc(m.subject)}</span>${m.opened_at ? ' · opened' : ''}${m.clicked_at ? ' · clicked' : ''}</li>`).join('')}</ul>` : '—'}</details>
        <details class="br-past" open><summary>Consent & objections (${p.consent.length})</summary>${p.consent.length ? `<ul class="br-proof">${p.consent.map(e => `<li>${esc(e.created_at)} · <b>${esc(e.event)}</b> · ${esc(e.channel)} · ${esc(e.source)}${e.note ? ' · ' + esc(e.note) : ''}</li>`).join('')}</ul>` : '<p class="br-muted">No consent recorded. Do not assume consent from published contact details.</p>'}
          <form id="coConsent" class="ac-form"><select class="p-select" name="event"><option value="objection">Objection</option><option value="do_not_contact">Do not contact</option><option value="unsubscribe">Unsubscribe</option><option value="opt_in">Explicit opt-in</option></select>
          <select class="p-select" name="channel"><option value="all">All channels</option><option value="email">Email</option><option value="whatsapp">WhatsApp</option><option value="phone">Phone</option></select>
          <input class="p-select" name="note" dir="auto" placeholder="Note (how it was received)"><button class="p-btn outline" type="submit">Record</button></form></details>
        ${p.merges.length ? `<details class="br-past"><summary>Merges (${p.merges.length})</summary><ul class="br-proof">${p.merges.map(m => `<li>#${m.duplicate_id} → #${m.primary_id} · ${esc(m.reason)} · ${esc(m.merged_at)}${m.undone_at ? ' · undone' : owner ? ` <button type="button" class="p-btn xs outline" data-undo="${m.id}">Undo</button>` : ''}</li>`).join('')}</ul></details>` : ''}
        ${owner ? '<div class="br-actions"><button type="button" class="p-btn xs" style="background:var(--panel-danger,#dc2626);color:#fff" id="coErase">Erase on request</button></div>' : ''}</section>`;
    }

    async function loadCompanies() {
      const qs = new URLSearchParams(Object.entries(coFilters).filter(([, v]) => v !== '' && v !== false && v !== undefined).map(([k, v]) => [k, v === true ? '1' : String(v)]));
      coLoading = true;
      try { co = await req('/api/admin/ai-ceo/companies?' + qs.toString()); coError = null; } catch (e) { coError = e.message; }
      finally { coLoading = false; }
      render();
    }
    async function openCompany(id) {
      try { coProfile = await req('/api/admin/ai-ceo/companies/' + id); } catch (e) { note = { text: e.message, error: true }; }
      render(); root.querySelector('#coProfile')?.scrollIntoView({ behavior: 'smooth' });
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
        case 'companies': if (!co && !coError && !coLoading) loadCompanies(); return companiesTab();
        case 'sales': if (!sales && !salesError && !salesLoading) loadSales(); return salesTab();
        case 'brand': if (!brand && !brandError && !brandLoading) loadBrand(); return brandTab();
        case 'success': if (!cs && !csError && !csLoading) loadSuccess(); return successTab();
        case 'ops': if (!ops && !opsError && !opsLoading) loadOps(); return opsTab();
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
      const sAct = (action, body, okText) => act(async () => { const r = await req('/api/admin/ai-ceo/sales/' + action, body); await loadSales(); return r; }, okText);
      root.querySelector('#slSave')?.addEventListener('click', () => { const settings = { targets: [...root.querySelectorAll('[data-target]:checked')].map(x => x.dataset.target), spend_usd_month: root.querySelector('#slSpend').value.trim() };
        root.querySelectorAll('[data-basis]').forEach(x => { settings['basis_' + x.dataset.basis] = x.checked; }); sAct('settings', { settings }, 'Saved ✓'); });
      root.querySelector('#slApproveAll')?.addEventListener('click', () => { if (confirm('Approve every company that passes the gate? Their email sequence starts within the daily limit.')) sAct('approve', { all: true }, 'Approved ✓'); });
      root.querySelectorAll('[data-sapprove]').forEach(b => b.onclick = () => sAct('approve', { ids: [Number(b.dataset.sapprove)] }, 'Approved ✓'));
      root.querySelector('#slReeval')?.addEventListener('click', () => sAct('reevaluate', {}, 'Re-checked ✓'));
      root.querySelector('#slPause')?.addEventListener('click', () => { if (confirm('Pause these sequences? No more emails go to them.')) sAct('pause', { ids: sales.active_noncompliant.map(x => x.id) }, 'Paused ✓'); });
      const oAct = (action, body, okText) => act(async () => { const r = await req('/api/admin/ai-ceo/ops/' + action, body); await loadOps(); return r; }, okText);
      root.querySelector('#opRun')?.addEventListener('click', () => { note = { text: 'Checking the pages… about a minute.' }; render(); act(async () => { await req('/api/admin/ai-ceo/ops/run', {}); await loadOps(); }, 'Checks done ✓'); });
      root.querySelectorAll('[data-bstatus]').forEach(b => b.onclick = () => { const s = b.dataset.bstatus; const after = s === 'done' ? prompt('Result after the change (optional, e.g. "0 incidents in 14 days"):') : null; if (s === 'done' && after === null) return;
        oAct('backlog-status', { id: Number(b.dataset.bid), status: s, metric_after: after || null }, 'Saved ✓'); });
      root.querySelectorAll('[data-bissue]').forEach(b => b.onclick = () => { const it = ops.backlog.find(x => x.id === Number(b.dataset.bissue));
        if (!ops.settings.github_ready) { opsIssue = `${'[' + it.service + '] ' + it.problem}\n\nService: ${it.service}\nProblem: ${it.problem}\nEvidence: ${it.evidence || ''}\nSeverity: ${it.severity}\nImprovement: ${it.improvement || ''}\nPriority: ${it.priority}/50\nCost: ${it.cost_estimate}\nBenefit: ${it.benefit || ''}\nHow to test: ${it.test_method || ''}\nNeeds approval: ${it.needs_approval ? 'yes' : 'no'}`; render(); return; }
        if (confirm('Create a GitHub issue for this item?')) oAct('issue', { id: it.id }, 'Issue created ✓'); });
      root.querySelectorAll('[data-rc]').forEach(f => f.onsubmit = ev => { ev.preventDefault(); oAct('root-cause', { id: Number(f.dataset.rc), text: new FormData(f).get('text') }, 'Saved ✓'); });
      root.querySelector('#opAdd')?.addEventListener('submit', ev => { ev.preventDefault(); oAct('backlog-add', { item: Object.fromEntries(new FormData(ev.target).entries()) }, 'Added ✓'); });
      root.querySelector('#opSave')?.addEventListener('click', () => oAct('settings', { settings: { backup_dir: root.querySelector('#opBackup').value.trim(), base_url: root.querySelector('#opBase').value.trim(), github_issues: root.querySelector('#opGh').checked } }, 'Saved ✓'));
      root.querySelector('#csSave')?.addEventListener('click', () => act(async () => { await req('/api/admin/ai-ceo/success/settings', { settings: { support_minutes: root.querySelector('#csMin').value.trim(), support_cost_hour: root.querySelector('#csHour').value.trim(), market_median_price: root.querySelector('#csMarket').value.trim() } }); await loadSuccess(); }, 'Saved ✓'));
      root.querySelector('#bgAudit')?.addEventListener('click', () => { note = { text: 'Auditing the site… this can take a minute.' }; render(); act(async () => { await req('/api/admin/ai-ceo/brand/audit', {}); await loadBrand(); }, 'Audit done ✓'); });
      root.querySelector('#bgPlan')?.addEventListener('click', () => { const markets = [...root.querySelectorAll('[data-bgm]:checked')].map(x => x.dataset.bgm), month = root.querySelector('#bgMonth').value;
        note = { text: 'Writing the plan… this can take a minute.' }; render(); act(async () => { brandPlan = await req('/api/admin/ai-ceo/brand/plan', { month, markets }); await loadBrand(); }, 'Plan saved to memory ✓'); });
      root.querySelector('#slCrm')?.addEventListener('click', () => sAct('crm', {}, 'Moved to CRM ✓'));
      root.querySelector('#coForm')?.addEventListener('submit', ev => { ev.preventDefault(); const fd = new FormData(ev.target); coFilters = { page: 1 };
        for (const [k, v] of fd.entries()) coFilters[k] = k === 'has_email' || k === 'converted' ? true : v; loadCompanies(); });
      root.querySelectorAll('[data-co-page]').forEach(b => b.onclick = () => { coFilters.page = (co?.results.page || 1) + Number(b.dataset.coPage); loadCompanies(); });
      root.querySelectorAll('[data-co]').forEach(el => el.onclick = ev => { ev.preventDefault(); openCompany(el.dataset.co); });
      root.querySelector('#coClose')?.addEventListener('click', () => { coProfile = null; render(); });
      root.querySelector('#coDupsBtn')?.addEventListener('click', () => act(async () => { coDups = (await req('/api/admin/ai-ceo/companies-duplicates')).groups; }));
      root.querySelectorAll('[data-merge]').forEach(b => b.onclick = () => { const [pid, did, reason] = b.dataset.merge.split(':'); if (!confirm(`Merge #${did} into #${pid}? The duplicate is paused (not deleted) and this can be undone.`)) return;
        act(async () => { coDups = (await req('/api/admin/ai-ceo/companies-merge', { primary_id: Number(pid), duplicate_id: Number(did), reason })).groups; await loadCompanies(); }, 'Merged ✓'); });
      root.querySelectorAll('[data-undo]').forEach(b => b.onclick = () => act(async () => { await req('/api/admin/ai-ceo/companies-merge', { undo: Number(b.dataset.undo) }); if (coProfile) await openCompany(coProfile.company.id); }, 'Merge undone ✓'));
      root.querySelector('#coConsent')?.addEventListener('submit', ev => { ev.preventDefault(); const fd = new FormData(ev.target);
        act(async () => { await req('/api/admin/ai-ceo/companies/' + coProfile.company.id + '/consent', Object.fromEntries(fd.entries())); await openCompany(coProfile.company.id); }, 'Recorded ✓'); });
      root.querySelector('#coErase')?.addEventListener('click', () => { const why = prompt('Erase this company\'s contact and personal data on their request? Only a hash is kept so it is not imported again. Type the request reference to confirm:'); if (!why) return;
        act(async () => { await req('/api/admin/ai-ceo/companies/' + coProfile.company.id + '/erase', { note: why }); coProfile = null; await loadCompanies(); }, 'Erased ✓'); });
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
