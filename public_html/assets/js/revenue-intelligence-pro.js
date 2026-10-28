/**
 * Tourfecto - Revenue Intelligence PRO tabs (v2.0)
 * التحصيل · مؤشرات السياحة والموسم · الربحية · احتمالية الفوز · الإعدادات
 *
 * ملف منفصل بدل ما الـ JavaScript كله يفضل جوّه الـ Controller.
 * الصفحة الأساسية بتنادي RevaiPro.renderers(ctx) وتضيف التبويبات دي لنفس
 * نظام التبويبات (نفس helpers: fetchJSON/esc/fmt/charts).
 */
(function (global) {
    'use strict';

    function t(key, fallback) {
        return (global.I18N && global.I18N[key]) || fallback || key;
    }

    function renderers(ctx) {
        const panel = ctx.panel, periodSelect = ctx.periodSelect, esc = ctx.esc, fetchJSON = ctx.fetchJSON,
            toast = ctx.toast, fmt = ctx.fmt, badge = ctx.badge, loadingHtml = ctx.loadingHtml,
            emptyHtml = ctx.emptyHtml, lineChart = ctx.lineChart, barChart = ctx.barChart;

        function tile(icon, color, value, label, sub) {
            return `<div class="p-card stat-tile"><div class="stat-icon ${color}">${icon}</div><div class="stat-info"><div class="stat-value">${value}</div><div class="stat-label">${esc(label)}</div>${sub ? `<div style="font-size:12px;opacity:.75;margin-top:2px;">${sub}</div>` : ''}</div></div>`;
        }
        function pctTxt(v, invert) {
            if (v === null || v === undefined) return '';
            const good = invert ? v < 0 : v > 0;
            const color = v === 0 ? '#888' : (good ? '#22C55E' : '#EF4444');
            return `<span style="color:${color};font-weight:600;">${v > 0 ? '+' : ''}${v}%</span> ${esc(t('revai.pro.vs_prev', 'vs previous period'))}`;
        }
        function num(v, suffix) { return v === null || v === undefined ? '-' : esc(String(v)) + (suffix || ''); }
        function warnCurrencies(list) {
            if (!list || !list.length) return '';
            return `<div class="p-card" style="border-inline-start:4px solid #F59E0B;margin-bottom:14px;font-size:13px;">⚠️ ${esc(t('revai.pro.unconverted', 'Some amounts are excluded because no exchange rate is available for:'))} <b>${list.map(esc).join(', ')}</b> — ${esc(t('revai.pro.add_rate_hint', 'add a rate in Settings.'))}</div>`;
        }
        const bucketLabel = {
            overdue: t('revai.pro.bucket.overdue', 'Overdue (trip started)'),
            due_7_days: t('revai.pro.bucket.due_7', 'Trip within 7 days'),
            due_30_days: t('revai.pro.bucket.due_30', 'Trip within 30 days'),
            later: t('revai.pro.bucket.later', 'Later'),
        };

        // ------------------------------------------------ التحصيل
        async function renderCollections() {
            panel.innerHTML = loadingHtml();
            const res = await fetchJSON('/api/revenue-intelligence/collections?period=' + periodSelect.value);
            if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
            const d = res.data, p = d.period || {};
            if (!d.has_data && !p.bookings_count) { panel.innerHTML = emptyHtml(t('revai.pro.no_bookings', 'No confirmed bookings yet.')); return; }
            panel.innerHTML = `
                ${warnCurrencies([].concat(Object.keys(d.unconverted || {}), p.unconverted_currencies || []))}
                <div class="p-grid cols-4" style="margin-bottom:18px;">
                    ${tile('🧾', 'blue', fmt(p.booked), t('revai.pro.booked', 'Booked (this period)'))}
                    ${tile('💵', 'green', fmt(p.collected), t('revai.pro.collected', 'Collected'), p.collection_rate !== null ? esc(p.collection_rate + '% ' + t('revai.pro.collection_rate', 'collection rate')) : '')}
                    ${tile('⏳', 'orange', fmt(d.outstanding_total), t('revai.pro.outstanding_all', 'All outstanding balances'))}
                    ${tile('🚨', 'purple', fmt(d.aging.overdue), t('revai.pro.overdue', 'Overdue'), esc((d.aging_counts.overdue || 0) + ' ' + t('revai.pro.bookings', 'bookings')))}
                </div>
                <div class="p-grid cols-2" style="margin-bottom:18px;">
                    <div class="p-card"><h3 style="margin:0 0 10px;">${esc(t('revai.pro.aging', 'Outstanding by trip date'))}</h3><div style="height:220px;"><canvas id="revaiAgingChart"></canvas></div></div>
                    <div class="p-card"><h3 style="margin:0 0 10px;">${esc(t('revai.pro.cash_in', 'Cash collected per day'))}</h3><div style="height:220px;"><canvas id="revaiCashChart"></canvas></div></div>
                </div>
                <div class="p-card"><h3 style="margin:0 0 10px;">${esc(t('revai.pro.to_collect', 'Collect these first'))}</h3>
                    ${(d.at_risk || []).length ? `<div class="p-table-scroll"><table class="p-table"><thead><tr>
                        <th>${esc(t('revai.pro.booking', 'Booking'))}</th><th>${esc(t('revai.pro.customer', 'Customer'))}</th><th>${esc(t('revai.pro.product', 'Product'))}</th>
                        <th>${esc(t('revai.pro.trip_date', 'Trip date'))}</th><th>${esc(t('revai.pro.outstanding', 'Outstanding'))}</th><th></th></tr></thead>
                        <tbody>${d.at_risk.map(r => `<tr>
                            <td>${esc(r.booking_reference)}</td><td>${esc(r.customer_name)}${r.customer_phone ? `<div style="font-size:11px;opacity:.7;" dir="ltr">${esc(r.customer_phone)}</div>` : ''}</td>
                            <td>${esc(r.product_name || '-')}</td><td>${esc(r.start_date)}</td><td><b>${fmt(r.outstanding)}</b></td>
                            <td>${badge(bucketLabel[r.bucket] || r.bucket, r.bucket === 'overdue' ? '#EF4444' : '#F59E0B')} <a class="p-btn xs" href="/bookings">${esc(t('revai.pro.send_link', 'Send payment link'))}</a></td></tr>`).join('')}
                        </tbody></table></div>` : `<div class="p-empty">${esc(t('revai.pro.nothing_overdue', 'No overdue balances 🎉'))}</div>`}
                </div>`;
            const keys = ['overdue', 'due_7_days', 'due_30_days', 'later'];
            barChart('revaiAgingChart', keys.map(k => bucketLabel[k]), [{ label: t('revai.pro.outstanding', 'Outstanding'), data: keys.map(k => d.aging[k]), backgroundColor: ['#EF4444', '#F59E0B', '#6366F1', '#94A3B8'] }]);
            const s = p.cash_in_series || [];
            lineChart('revaiCashChart', s.map(x => x.date), [{ label: t('revai.pro.collected', 'Collected'), data: s.map(x => x.amount), borderColor: '#22C55E', backgroundColor: 'rgba(34,197,94,0.12)', fill: true, tension: 0.3, pointRadius: 0 }]);
        }

        // ------------------------------------------------ مؤشرات السياحة + الموسم
        async function renderTourism() {
            panel.innerHTML = loadingHtml();
            const [res, paceRes] = await Promise.all([
                fetchJSON('/api/revenue-intelligence/tourism-kpis?period=' + periodSelect.value),
                fetchJSON('/api/revenue-intelligence/pace?days=90'),
            ]);
            if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
            const k = res.data, c = k.comparison || {};
            const pace = paceRes.success ? paceRes.data : null;
            const paceColor = { ahead: '#22C55E', on_pace: '#6366F1', behind: '#EF4444', no_history: '#94A3B8' };
            const paceLabel = { ahead: t('revai.pro.pace.ahead', 'Ahead of last year'), on_pace: t('revai.pro.pace.on', 'On pace'), behind: t('revai.pro.pace.behind', 'Behind last year'), no_history: t('revai.pro.pace.none', 'No last-year data') };
            const paceHtml = pace && pace.has_data ? `
                <div class="p-card" style="margin-bottom:18px;border-inline-start:4px solid ${paceColor[pace.pace_status]};">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                        <h3 style="margin:0;">📅 ${esc(t('revai.pro.pace.title', 'Next 90 days vs same point last year'))}</h3>
                        ${badge(paceLabel[pace.pace_status], paceColor[pace.pace_status])}
                    </div>
                    <div class="p-grid cols-4" style="margin-top:12px;">
                        <div><div style="font-size:12px;opacity:.7;">${esc(t('revai.pro.pace.otb', 'On the books now'))}</div><div style="font-size:20px;font-weight:700;">${fmt(pace.on_the_books.revenue)}</div><div style="font-size:12px;">${pace.on_the_books.bookings} ${esc(t('revai.pro.bookings', 'bookings'))} · ${pace.on_the_books.travelers} ${esc(t('revai.pro.travelers', 'travelers'))}</div></div>
                        <div><div style="font-size:12px;opacity:.7;">${esc(t('revai.pro.pace.ly', 'Same point last year'))}</div><div style="font-size:20px;font-weight:700;">${fmt(pace.last_year_same_point.revenue)}</div></div>
                        <div><div style="font-size:12px;opacity:.7;">${esc(t('revai.pro.pace.pct', 'Pace'))}</div><div style="font-size:20px;font-weight:700;color:${paceColor[pace.pace_status]};">${pace.pace_percent !== null ? (pace.pace_percent > 0 ? '+' : '') + pace.pace_percent + '%' : '-'}</div></div>
                        <div><div style="font-size:12px;opacity:.7;">${esc(t('revai.pro.pace.projected', 'Projected finish'))}</div><div style="font-size:20px;font-weight:700;">${pace.projected_final_revenue !== null ? fmt(pace.projected_final_revenue) : '-'}</div></div>
                    </div>
                    <p style="font-size:11.5px;opacity:.65;margin:10px 0 0;">${esc(t('revai.pro.pace.note', pace.note))}</p>
                </div>` : '';
            if (!k.has_data) { panel.innerHTML = paceHtml + emptyHtml(t('revai.pro.no_bookings_period', 'No bookings in this period.')); return; }
            panel.innerHTML = `
                ${warnCurrencies(k.unconverted_currencies)}
                ${paceHtml}
                <div class="p-grid cols-4" style="margin-bottom:18px;">
                    ${tile('🧳', 'blue', fmt(k.gross_booking_value), t('revai.pro.gbv', 'Booking value'), pctTxt(c.gross_booking_value))}
                    ${tile('👥', 'green', num(k.travelers), t('revai.pro.travelers_cap', 'Travelers'), pctTxt(c.travelers))}
                    ${tile('💳', 'purple', fmt(k.revenue_per_traveler), t('revai.pro.rev_per_traveler', 'Revenue per traveler'), pctTxt(c.revenue_per_traveler))}
                    ${tile('🎫', 'orange', fmt(k.avg_booking_value), t('revai.pro.avg_booking', 'Average booking value'), pctTxt(c.avg_booking_value))}
                    ${tile('❌', 'purple', num(k.cancellation_rate, '%'), t('revai.pro.cancel_rate', 'Cancellation rate'), c.cancellation_rate !== null && c.cancellation_rate !== undefined ? `<span style="color:${c.cancellation_rate > 0 ? '#EF4444' : '#22C55E'};font-weight:600;">${c.cancellation_rate > 0 ? '+' : ''}${c.cancellation_rate} pts</span>` : '')}
                    ${tile('⏱️', 'blue', num(k.avg_lead_time_days, ' ' + t('revai.pro.days', 'days')), t('revai.pro.lead_time', 'Avg. booking lead time'), k.median_lead_time_days !== null ? esc(t('revai.pro.median', 'median') + ' ' + k.median_lead_time_days) : '')}
                    ${tile('👨‍👩‍👧', 'green', num(k.avg_group_size), t('revai.pro.group_size', 'Average group size'))}
                    ${tile('🏷️', 'orange', fmt(k.net_revenue_after_commission), t('revai.pro.net_after_comm', 'Net after channel commission'), k.commission_complete ? '' : esc(t('revai.pro.comm_missing', 'Set OTA commission % in Settings')))}
                </div>
                <div class="p-card" style="margin-bottom:18px;"><h3 style="margin:0 0 10px;">${esc(t('revai.pro.channels', 'Channels'))}</h3>
                    <div class="p-table-scroll"><table class="p-table"><thead><tr><th>${esc(t('revai.pro.channel', 'Channel'))}</th><th>${esc(t('revai.pro.bookings_cap', 'Bookings'))}</th><th>${esc(t('revai.col.revenue', 'Revenue'))}</th><th>%</th><th>${esc(t('revai.pro.commission', 'Commission'))}</th><th>${esc(t('revai.pro.net', 'Net'))}</th><th>${esc(t('revai.pro.avg_booking', 'Avg booking'))}</th></tr></thead>
                    <tbody>${k.channels.map(ch => `<tr><td><b>${esc(ch.channel)}</b></td><td>${ch.bookings}</td><td>${fmt(ch.revenue)}</td><td>${num(ch.share_percent, '%')}</td><td>${ch.commission_percent !== null ? esc(ch.commission_percent + '%') + ' · ' + fmt(ch.commission) : '-'}</td><td><b>${fmt(ch.net_revenue)}</b></td><td>${fmt(ch.avg_booking_value)}</td></tr>`).join('')}</tbody></table></div>
                </div>
                <div class="p-grid cols-2" style="margin-bottom:18px;">
                    <div class="p-card"><h3 style="margin:0 0 10px;">${esc(t('revai.pro.dow', 'Demand by trip weekday'))}</h3><div style="height:220px;"><canvas id="revaiDowChart"></canvas></div></div>
                    <div class="p-card"><h3 style="margin:0 0 10px;">${esc(t('revai.pro.lead_dist', 'How early customers book'))}</h3><div style="height:220px;"><canvas id="revaiLeadChart"></canvas></div></div>
                </div>
                <div class="p-card"><h3 style="margin:0 0 10px;">${esc(t('revai.pro.top_products', 'Top trips / products'))}</h3>
                    <div class="p-table-scroll"><table class="p-table"><thead><tr><th>${esc(t('revai.pro.product', 'Product'))}</th><th>${esc(t('revai.pro.bookings_cap', 'Bookings'))}</th><th>${esc(t('revai.pro.travelers_cap', 'Travelers'))}</th><th>${esc(t('revai.col.revenue', 'Revenue'))}</th><th>${esc(t('revai.pro.rev_per_traveler', 'Per traveler'))}</th></tr></thead>
                    <tbody>${k.top_products.map(p => `<tr><td>${esc(p.product_name)}</td><td>${p.bookings}</td><td>${p.travelers}</td><td>${fmt(p.revenue)}</td><td>${fmt(p.revenue_per_traveler)}</td></tr>`).join('')}</tbody></table></div>
                </div>`;
            const dayLbl = { sun: t('revai.pro.d.sun', 'Sun'), mon: t('revai.pro.d.mon', 'Mon'), tue: t('revai.pro.d.tue', 'Tue'), wed: t('revai.pro.d.wed', 'Wed'), thu: t('revai.pro.d.thu', 'Thu'), fri: t('revai.pro.d.fri', 'Fri'), sat: t('revai.pro.d.sat', 'Sat') };
            barChart('revaiDowChart', k.day_of_week.map(x => dayLbl[x.day]), [{ label: t('revai.col.revenue', 'Revenue'), data: k.day_of_week.map(x => x.revenue), backgroundColor: '#6366F1' }]);
            const lb = k.lead_time_buckets;
            barChart('revaiLeadChart', ['0-7', '8-30', '31-90', '90+'].map(x => x + ' ' + t('revai.pro.days', 'days')), [{ label: t('revai.pro.bookings_cap', 'Bookings'), data: [lb['0_7'], lb['8_30'], lb['31_90'], lb['90_plus']], backgroundColor: '#22C55E' }]);
        }

        // ------------------------------------------------ الربحية
        async function renderProfitability() {
            panel.innerHTML = loadingHtml();
            const res = await fetchJSON('/api/revenue-intelligence/profitability?period=' + periodSelect.value);
            if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
            const d = res.data, tt = d.totals;
            const typeLbl = { per_traveler: t('revai.pro.cost.per_traveler', 'per traveler'), per_booking: t('revai.pro.cost.per_booking', 'per booking'), percent_of_revenue: t('revai.pro.cost.percent', '% of revenue') };
            const productOpts = (d.products_catalog || []).map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('');
            panel.innerHTML = `
                ${warnCurrencies(d.unconverted_currencies)}
                <div class="p-grid cols-4" style="margin-bottom:18px;">
                    ${tile('💰', 'blue', fmt(tt.revenue), t('revai.col.revenue', 'Revenue'))}
                    ${tile('🏨', 'orange', fmt(tt.direct_costs), t('revai.pro.direct_costs', 'Direct costs'))}
                    ${tile('🏷️', 'purple', fmt(tt.commission), t('revai.pro.commission', 'Commission'))}
                    ${tile('📈', 'green', tt.net_profit !== null ? fmt(tt.net_profit) : '-', t('revai.pro.net_profit', 'Net profit'), tt.margin_percent !== null ? esc(tt.margin_percent + '% ' + t('revai.pro.margin', 'margin')) : esc(t('revai.pro.add_costs', 'Add costs below')))}
                </div>
                ${d.coverage_percent !== null && d.coverage_percent < 100 ? `<div class="p-card" style="margin-bottom:14px;font-size:13px;">ℹ️ ${esc(t('revai.pro.coverage', 'Costs recorded for'))} <b>${d.coverage_percent}%</b> ${esc(t('revai.pro.coverage2', 'of revenue. Products without costs show no margin (never assumed zero).'))}</div>` : ''}
                <div class="p-card" style="margin-bottom:18px;"><h3 style="margin:0 0 10px;">${esc(t('revai.pro.by_product', 'Profit by trip / product'))}</h3>
                    ${d.products.length ? `<div class="p-table-scroll"><table class="p-table"><thead><tr><th>${esc(t('revai.pro.product', 'Product'))}</th><th>${esc(t('revai.pro.travelers_cap', 'Travelers'))}</th><th>${esc(t('revai.col.revenue', 'Revenue'))}</th><th>${esc(t('revai.pro.direct_costs', 'Costs'))}</th><th>${esc(t('revai.pro.commission', 'Commission'))}</th><th>${esc(t('revai.pro.net_profit', 'Net profit'))}</th><th>${esc(t('revai.pro.margin', 'Margin'))}</th><th>${esc(t('revai.pro.profit_per_traveler', 'Profit / traveler'))}</th></tr></thead>
                    <tbody>${d.products.map(p => `<tr><td>${esc(p.product_name)}</td><td>${p.travelers}</td><td>${fmt(p.revenue)}</td><td>${p.has_costs ? fmt(p.direct_costs) : '<span class="p-cell-muted">—</span>'}</td><td>${fmt(p.commission)}</td>
                        <td>${p.net_profit !== null ? `<b style="color:${p.net_profit < 0 ? '#EF4444' : '#22C55E'};">${fmt(p.net_profit)}</b>` : '-'}</td><td>${num(p.margin_percent, '%')}</td><td>${fmt(p.profit_per_traveler)}</td></tr>`).join('')}</tbody></table></div>` : emptyHtml(t('revai.pro.no_bookings_period', 'No bookings in this period.'))}
                </div>
                <div class="p-card"><h3 style="margin:0 0 10px;">${esc(t('revai.pro.costs_title', 'Product costs'))}</h3>
                    <div class="p-grid cols-4" style="align-items:end;margin-bottom:12px;">
                        <div><label class="form-label">${esc(t('revai.pro.product', 'Product'))}</label><select id="revaiCostProduct" class="p-select">${productOpts}<option value="0">${esc(t('revai.pro.other_product', 'Other (type name)'))}</option></select><input id="revaiCostProductName" class="p-input" style="margin-top:6px;display:${productOpts ? 'none' : 'block'};" placeholder="${esc(t('revai.pro.product', 'Product'))}"></div>
                        <div><label class="form-label">${esc(t('revai.pro.cost_label', 'Cost item'))}</label><input id="revaiCostLabel" class="p-input" placeholder="${esc(t('revai.pro.cost_label_ph', 'Hotel / guide / transport'))}"></div>
                        <div><label class="form-label">${esc(t('revai.pro.cost_type', 'Type'))}</label><select id="revaiCostType" class="p-select"><option value="per_traveler">${typeLbl.per_traveler}</option><option value="per_booking">${typeLbl.per_booking}</option><option value="percent_of_revenue">${typeLbl.percent_of_revenue}</option></select></div>
                        <div style="display:flex;gap:6px;"><div style="flex:1;"><label class="form-label">${esc(t('revai.pro.amount', 'Amount'))}</label><input id="revaiCostAmount" type="number" min="0" step="0.01" class="p-input"></div><div style="width:80px;"><label class="form-label">${esc(t('revai.pro.currency', 'Currency'))}</label><input id="revaiCostCurrency" class="p-input" maxlength="3" value="${esc(d.currency)}" dir="ltr"></div></div>
                    </div>
                    <button class="p-btn primary" id="revaiCostAdd">+ ${esc(t('revai.pro.add_cost', 'Add cost'))}</button>
                    <div style="margin-top:14px;">${(d.costs || []).map(c => `<div style="display:flex;justify-content:space-between;gap:8px;padding:6px 0;border-bottom:1px solid var(--border,#eee);font-size:13px;"><span><b>${esc(((d.products_catalog || []).find(p => +p.id === +c.product_id) || {}).name || c.product_name || '-')}</b> · ${esc(c.label)} · ${esc(c.amount)} ${c.cost_type === 'percent_of_revenue' ? '%' : esc(c.currency || d.currency)} ${esc(typeLbl[c.cost_type] || '')}</span><button class="p-btn xs" data-del-cost="${c.id}">✕</button></div>`).join('')}</div>
                </div>`;
            const sel = document.getElementById('revaiCostProduct'), nameInput = document.getElementById('revaiCostProductName');
            if (sel) sel.addEventListener('change', () => { nameInput.style.display = sel.value === '0' ? 'block' : 'none'; });
            document.getElementById('revaiCostAdd').addEventListener('click', async () => {
                const body = {
                    product_id: sel && sel.value !== '0' ? sel.value : 0, product_name: nameInput.value,
                    label: document.getElementById('revaiCostLabel').value, cost_type: document.getElementById('revaiCostType').value,
                    amount: document.getElementById('revaiCostAmount').value, currency: document.getElementById('revaiCostCurrency').value.toUpperCase(),
                };
                const r = await fetchJSON('/api/revenue-intelligence/product-costs', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
                if (r.success) { toast(t('revai.pro.saved', 'Saved'), 'success'); renderProfitability(); } else { toast(r.error, 'error'); }
            });
            panel.querySelectorAll('[data-del-cost]').forEach(btn => btn.addEventListener('click', async () => {
                const r = await fetchJSON('/api/revenue-intelligence/product-costs/delete', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: btn.getAttribute('data-del-cost') }) });
                if (r.success) renderProfitability(); else toast(r.error, 'error');
            }));
        }

        // ------------------------------------------------ احتمالية الفوز المتعلّمة
        async function renderWinModel() {
            panel.innerHTML = loadingHtml();
            const res = await fetchJSON('/api/revenue-intelligence/win-model?per_page=50');
            if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
            const d = res.data, m = d.model || {};
            if (!d.has_data) { panel.innerHTML = emptyHtml(d.message); return; }
            const ev = m.evaluation || {};
            panel.innerHTML = `
                <div class="p-grid cols-4" style="margin-bottom:18px;">
                    ${tile('🧠', 'purple', num(m.training_size), t('revai.pro.win.trained_on', 'Closed deals learned from'))}
                    ${tile('🏆', 'green', num(Math.round((m.global_win_rate || 0) * 1000) / 10, '%'), t('revai.pro.win.rate', 'Historical win rate'))}
                    ${tile('📊', 'blue', fmt(d.weighted_pipeline_learned), t('revai.pro.win.weighted_learned', 'Pipeline (learned)'), esc(t('revai.pro.win.stage_based', 'stage-based') + ': ') + fmt(d.weighted_pipeline_stage))}
                    ${tile('🎯', 'orange', ev.evaluated ? (ev.beats_baseline ? '✅' : '➖') : '-', t('revai.pro.win.quality', 'Beats simple average?'), ev.evaluated ? esc('Brier ' + ev.brier_model + ' vs ' + ev.brier_baseline) : '')}
                </div>
                <div class="p-card"><h3 style="margin:0 0 10px;">${esc(t('revai.pro.win.deals', 'Open deals — learned vs stage probability'))}</h3>
                    <div class="p-table-scroll"><table class="p-table"><thead><tr><th>${esc(t('revai.pro.win.deal', 'Deal'))}</th><th>${esc(t('revai.col.revenue', 'Value'))}</th><th>${esc(t('revai.pro.win.age', 'Age (days)'))}</th><th>${esc(t('revai.pro.win.stage', 'Stage'))}</th><th>${esc(t('revai.pro.win.stage_p', 'Stage %'))}</th><th>${esc(t('revai.pro.win.learned_p', 'Learned %'))}</th><th></th></tr></thead>
                    <tbody>${(d.deals || []).map(x => `<tr><td>${esc(x.title)}<div style="font-size:11px;opacity:.7;">${esc(x.contact_name)}</div></td><td>${fmt(x.value)}</td><td>${x.age_days}</td><td>${esc(x.stage)}</td><td>${x.stage_probability}%</td>
                        <td><b style="color:${x.delta_points < -5 ? '#EF4444' : (x.delta_points > 5 ? '#22C55E' : 'inherit')};">${x.learned_probability}%</b></td><td>${x.stalled ? badge(t('revai.pro.win.stalled', 'Stalled'), '#EF4444') : ''}</td></tr>`).join('')}</tbody></table></div>
                    <p style="font-size:11.5px;opacity:.65;margin:10px 0 0;">${esc(t('revai.pro.win.note', 'Learned from your own won/lost deals by value band and deal age, smoothed toward your overall win rate, then blended with the stage probability.'))}</p>
                </div>`;
        }

        // ------------------------------------------------ الإعدادات
        async function renderSettings() {
            panel.innerHTML = loadingHtml();
            const [res, stripeRes] = await Promise.all([fetchJSON('/api/revenue-intelligence/settings'), fetchJSON('/api/revenue-intelligence/stripe/settings')]);
            if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
            const s = res.data, st = stripeRes.success ? stripeRes.data : null;
            const comm = Object.entries(s.channel_commissions || {}).map(([k, v]) => k + '=' + v).join('\n');
            const rates = Object.entries(s.fx_rates || {}).filter(([k]) => k !== 'USD').sort().map(([k, v]) => `<span style="display:inline-block;margin:2px 6px 2px 0;padding:2px 8px;border-radius:12px;background:var(--panel-bg-soft,#f3f4f6);font-size:12px;" dir="ltr">1 USD = ${(+v).toFixed(4)} ${esc(k)}</span>`).join('');
            panel.innerHTML = `
                <div class="p-grid cols-2" style="margin-bottom:18px;">
                    <div class="p-card"><h3 style="margin:0 0 10px;">💱 ${esc(t('revai.pro.set.currency_title', 'Reporting currency & exchange rates'))}</h3>
                        <label class="form-label">${esc(t('revai.pro.set.base', 'Report all revenue in'))}</label>
                        <input id="revaiBaseCur" class="p-input" maxlength="3" dir="ltr" value="${esc(s.base_currency)}" style="max-width:120px;">
                        <p style="font-size:12px;opacity:.7;">${esc(t('revai.pro.set.base_hint', 'Bookings in other currencies are converted using the latest daily rate (or your manual rate below).'))}</p>
                        <div style="margin:8px 0;max-height:110px;overflow:auto;">${rates || `<span class="p-cell-muted">${esc(t('revai.pro.set.no_rates', 'No market rates loaded yet.'))}</span>`}</div>
                        <div style="display:flex;gap:6px;align-items:end;flex-wrap:wrap;">
                            <div><label class="form-label">${esc(t('revai.pro.currency', 'Currency'))}</label><input id="revaiFxCur" class="p-input" maxlength="3" dir="ltr" style="width:80px;"></div>
                            <div><label class="form-label">${esc(t('revai.pro.set.per_usd', 'Units per 1 USD'))}</label><input id="revaiFxRate" type="number" step="0.0001" min="0" class="p-input" dir="ltr" style="width:140px;"></div>
                            <button class="p-btn" id="revaiFxSave">${esc(t('revai.pro.set.manual_rate', 'Set manual rate'))}</button>
                        </div>
                    </div>
                    <div class="p-card"><h3 style="margin:0 0 10px;">🏷️ ${esc(t('revai.pro.set.comm_title', 'Channel commissions (%)'))}</h3>
                        <p style="font-size:12px;opacity:.7;margin-top:0;">${esc(t('revai.pro.set.comm_hint', 'One per line: channel=percent. Use ota:* as default for all OTAs. Example:'))} <code dir="ltr">ota:booking=15</code> · <code dir="ltr">ota:viator=20</code> · <code dir="ltr">ota:*=18</code></p>
                        <textarea id="revaiComm" class="p-input" rows="6" dir="ltr" style="font-family:monospace;">${esc(comm)}</textarea>
                    </div>
                </div>
                <div class="p-card" style="margin-bottom:18px;"><h3 style="margin:0 0 10px;">🔔 ${esc(t('revai.pro.set.alerts_title', 'External alerts'))}</h3>
                    <div class="p-grid cols-3">
                        <div><label class="form-label">Slack Webhook ${s.slack_configured ? badge(t('revai.pro.set.connected', 'connected'), '#22C55E') : ''}</label><input id="revaiSlack" class="p-input" dir="ltr" placeholder="https://hooks.slack.com/services/..."></div>
                        <div><label class="form-label">Webhook (Zapier / Make / n8n) ${s.webhook_configured ? badge(t('revai.pro.set.connected', 'connected'), '#22C55E') : ''}</label><input id="revaiWebhook" class="p-input" dir="ltr" placeholder="https://..."><input id="revaiWebhookSecret" class="p-input" dir="ltr" style="margin-top:6px;" placeholder="${esc(t('revai.pro.set.secret_ph', 'Signing secret (optional)'))}"></div>
                        <div>
                            <label class="form-label">${esc(t('revai.pro.set.min_sev', 'Send alerts for'))}</label>
                            <select id="revaiMinSev" class="p-select"><option value="high" ${s.alert_min_severity === 'high' ? 'selected' : ''}>${esc(t('revai.pro.set.sev_high', 'High severity only'))}</option><option value="medium" ${s.alert_min_severity === 'medium' ? 'selected' : ''}>${esc(t('revai.pro.set.sev_medium', 'High + medium'))}</option></select>
                            <label style="display:flex;gap:6px;align-items:center;margin-top:10px;"><input type="checkbox" id="revaiAlertEmail" ${s.alert_email ? 'checked' : ''}> ${esc(t('revai.pro.set.email', 'Also send by email'))}</label>
                        </div>
                    </div>
                    <p style="font-size:12px;opacity:.7;">${esc(t('revai.pro.set.alerts_hint', 'Leave a URL field empty to keep the saved one. Webhook requests are signed with header X-Tourfecto-Signature (t=…,v1=HMAC-SHA256).'))}</p>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button class="p-btn primary" id="revaiSettingsSave">${esc(t('revai.pro.set.save', 'Save settings'))}</button>
                        <button class="p-btn" id="revaiTestAlert">${esc(t('revai.pro.set.test', 'Send test alert'))}</button>
                        ${s.slack_configured ? `<button class="p-btn" id="revaiSlackClear">${esc(t('revai.pro.set.clear_slack', 'Disconnect Slack'))}</button>` : ''}
                        ${s.webhook_configured ? `<button class="p-btn" id="revaiWebhookClear">${esc(t('revai.pro.set.clear_webhook', 'Disconnect webhook'))}</button>` : ''}
                    </div>
                </div>
                ${st ? `<div class="p-card"><h3 style="margin:0 0 10px;">💳 Stripe</h3>
                    <p style="font-size:13px;margin:0 0 6px;">${esc(t('revai.pro.set.stripe_url', 'Webhook endpoint'))}: <code dir="ltr">${esc(st.webhook_url)}</code></p>
                    <p style="font-size:13px;margin:0 0 6px;">${esc(t('revai.pro.set.stripe_events', 'Enable these events in your Stripe dashboard'))}:</p>
                    <div dir="ltr" style="font-family:monospace;font-size:12px;">${(st.supported_events || []).map(esc).join('<br>')}</div>
                    <p style="font-size:12px;opacity:.7;margin:8px 0 0;">${st.last_event_at ? esc(t('revai.pro.set.stripe_last', 'Last event') + ': ' + st.last_event_type + ' @ ' + st.last_event_at) : esc(t('revai.pro.set.stripe_none', 'No Stripe events received yet.'))}</p></div>` : ''}`;

            function parseComm(text) {
                const out = {};
                text.split(/\n|,/).forEach(line => { const m = line.trim().match(/^([^=\s]+)\s*=\s*([\d.]+)$/); if (m) out[m[1].toLowerCase()] = parseFloat(m[2]); });
                return out;
            }
            async function save(extra) {
                const body = Object.assign({
                    base_currency: document.getElementById('revaiBaseCur').value.toUpperCase(),
                    channel_commissions: parseComm(document.getElementById('revaiComm').value),
                    alert_min_severity: document.getElementById('revaiMinSev').value,
                    alert_email: document.getElementById('revaiAlertEmail').checked,
                }, extra || {});
                const slack = document.getElementById('revaiSlack').value.trim(), hook = document.getElementById('revaiWebhook').value.trim(), sec = document.getElementById('revaiWebhookSecret').value.trim();
                if (slack) body.slack_url = slack;
                if (hook) body.webhook_url = hook;
                if (sec) body.webhook_secret = sec;
                const r = await fetchJSON('/api/revenue-intelligence/settings', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
                if (r.success) { toast(t('revai.pro.saved', 'Saved'), 'success'); renderSettings(); } else { toast(r.error, 'error'); }
            }
            document.getElementById('revaiSettingsSave').addEventListener('click', () => save());
            const sc = document.getElementById('revaiSlackClear'); if (sc) sc.addEventListener('click', () => save({ slack_url: '' }));
            const wc = document.getElementById('revaiWebhookClear'); if (wc) wc.addEventListener('click', () => save({ webhook_url: '' }));
            document.getElementById('revaiTestAlert').addEventListener('click', async () => {
                const r = await fetchJSON('/api/revenue-intelligence/settings/test-alert', { method: 'POST' });
                if (r.success) { toast(Object.entries(r.data.results).map(([k, v]) => k + ': ' + v).join(' · '), Object.values(r.data.results).every(v => v === 'sent') ? 'success' : 'error'); } else { toast(r.error, 'error'); }
            });
            document.getElementById('revaiFxSave').addEventListener('click', async () => {
                const r = await fetchJSON('/api/revenue-intelligence/settings/fx-rate', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ currency: document.getElementById('revaiFxCur').value.toUpperCase(), units_per_usd: document.getElementById('revaiFxRate').value }) });
                if (r.success) { toast(t('revai.pro.saved', 'Saved'), 'success'); renderSettings(); } else { toast(r.error, 'error'); }
            });
        }

        return {
            collections: renderCollections,
            tourism: renderTourism,
            profitability: renderProfitability,
            winmodel: renderWinModel,
            settings: renderSettings,
        };
    }

    /** بطاقة النموذج المختار في تبويب التنبؤ. */
    function forecastModelCard(d, esc, fmt) {
        if (!d || !d.backtest) return '';
        const b = d.backtest;
        const models = Object.entries(b.models_compared || {}).map(([m, w]) => `<span style="display:inline-block;margin:2px 6px 2px 0;font-size:12px;${m === (d.method || '').replace('engine_', '') ? 'font-weight:700;' : 'opacity:.75;'}">${esc(m)}: ${Math.round((1 - w) * 1000) / 10}%</span>`).join('');
        return `<div class="p-card" style="margin-bottom:18px;border-inline-start:4px solid #6366F1;">
            <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;align-items:center;">
                <b>🧠 ${esc(t('revai.pro.fc.model', 'Model selected automatically'))}: ${esc(d.model_label || d.method)}</b>
                <span style="font-size:13px;">${esc(t('revai.pro.fc.accuracy', 'Backtest accuracy'))}: <b>${b.accuracy_percent}%</b> (${esc(t('revai.pro.fc.last', 'last'))} ${b.holdout_days} ${esc(t('revai.pro.days', 'days'))})</span>
            </div>
            <div style="margin-top:6px;">${models}</div>
            ${d.baseline && d.baseline.expected_revenue !== null ? `<p style="font-size:12px;opacity:.7;margin:6px 0 0;">${esc(t('revai.pro.fc.baseline', 'Simple linear forecast (old method)'))}: ${fmt(d.baseline.expected_revenue)}</p>` : ''}
        </div>`;
    }

    global.RevaiPro = { renderers: renderers, forecastModelCard: forecastModelCard };
})(window);
