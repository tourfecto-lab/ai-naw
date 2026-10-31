/* Tourfecto - أدمن: باقات السوشيال (الأسعار، الحدود، المنصات، الميزات، الإضافات، ربطها بباقات المنصة) */
(() => {
    'use strict';
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    let P, root, D;
    const api = async (url, body) => { const r = await P.fetchJSON(url, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...body, csrf_token: D?.csrf_token }) } : undefined); if (!r?.success) throw new Error(r?.error || 'حصلت مشكلة'); return r; };
    const msg = (m, bad) => { const el = root.querySelector('#spaMsg'); el.textContent = m; el.className = 'ps-msg' + (bad ? ' bad' : ''); el.hidden = !m; };
    const TIERS = ['free', 'basic', 'pro', 'agency', 'enterprise'];

    const pct = v => (Number(v) || 0).toFixed(1) + '%';
    const extras = c => {
        const eco = D.economics || {}, cs = c.costs, costLabels = { ai_caption: 'تكلفة كابشن AI $', post: 'تكلفة المنشور $', channel_month: 'تكلفة الحساب/شهر $', competitor_month: 'تكلفة منافس/شهر $', monthly_plan: 'تكلفة خطة شهرية $', unlimited_posts_assumed: 'منشورات «غير محدود» (افتراض)', gateway_fee_pct: 'رسوم بوابة الدفع %' };
        const fld = (label, inner) => `<label style="display:flex;flex-direction:column;gap:4px;min-width:110px;flex:1 1 110px"><span class="p-cell-muted" style="font-size:12px">${label}</span>${inner}</label>`;
        const promoRows = (c.promos || []).map((p, i) => `<div class="spa-promo" data-promo="${i}" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;padding:12px;border:1px solid var(--border,rgba(128,128,128,.3));border-radius:12px;margin-bottom:10px">
${fld('الكود', `<input class="p-select" data-pf="code" value="${esc(p.code)}" placeholder="LAUNCH20" maxlength="24" style="text-transform:uppercase">`)}
${fld('خصم %', `<input class="p-select" type="number" min="1" max="100" step="1" data-pf="percent" value="${esc(p.percent)}">`)}
${fld('أقصى استخدام (0 = بلا حد)', `<input class="p-select" type="number" min="0" data-pf="max_uses" value="${esc(p.max_uses)}">`)}
${fld('من', `<input class="p-select" type="date" data-pf="starts" value="${esc(p.starts)}">`)}
${fld('إلى', `<input class="p-select" type="date" data-pf="ends" value="${esc(p.ends)}">`)}
<div style="flex:1 1 100%;display:flex;flex-wrap:wrap;gap:12px;align-items:center"><span class="p-cell-muted" style="font-size:12px">الباقات (من غير اختيار = الكل):</span>${['basic', 'pro', 'agency', 'enterprise', 'custom'].map(t => `<label style="white-space:nowrap"><input type="checkbox" data-ptier="${t}" ${(p.tiers || []).includes(t) ? 'checked' : ''}> ${esc(c.tiers[t]?.name || 'مفصّلة')}</label>`).join('')}
<span class="p-cell-muted" style="font-size:12px">الدورة:</span>${['monthly', 'yearly'].map(t => `<label style="white-space:nowrap"><input type="checkbox" data-pcycle="${t}" ${(p.cycles || []).includes(t) ? 'checked' : ''}> ${t === 'monthly' ? 'شهري' : 'سنوي'}</label>`).join('')}
<label style="white-space:nowrap"><input type="checkbox" data-pf="enabled" ${p.enabled ? 'checked' : ''}> فعّال</label>
<button type="button" class="p-btn" data-promo-del="${i}" aria-label="حذف الكود" style="margin-inline-start:auto">✕ حذف</button></div></div>`).join('');
        const audit = (D.audit || []).map(a => `<tr><td>${esc(a.at)}</td><td>${esc(a.actor)}</td><td>${Object.entries(a.changes).map(([k, v]) => `<div><code>${esc(k)}</code>: ${esc(v.old ?? '—')} ← ${esc(v.new ?? '—')}</div>`).join('')}</td></tr>`).join('');
        const lim = (n, m) => m < 0 ? `${n} / ∞` : `${n} / ${m}`;
        const usageRows = (D.usage_report || []).length ? (D.usage_report || []).map(u => `<div style="display:flex;flex-wrap:wrap;gap:8px 16px;padding:8px 0;border-bottom:1px solid var(--border,rgba(128,128,128,.25))"><b style="flex:1 1 200px;word-break:break-all">${u.alert ? '⚠️ ' : ''}${esc(u.email)}</b><span>${esc(c.tiers[u.tier]?.name || u.tier)}</span><span>منشورات ${lim(u.posts, u.posts_limit)}</span><span>كابشن ${lim(u.ai, u.ai_limit)}</span><span>تكلفة ≈ $${u.est_cost}</span>${u.ai_cost_actual != null ? `<span>AI فعلي $${u.ai_cost_actual}</span>` : ''}</div>`).join('') : '<p class="p-cell-muted">لسه مفيش استهلاك مسجّل الشهر ده.</p>';
        const I = D.intel || {};
        const tile = (label, value, sub) => `<div style="padding:12px;border:1px solid var(--border,rgba(128,128,128,.3));border-radius:12px"><div class="p-cell-muted" style="font-size:12px">${label}</div><b style="font-size:20px">${value}</b>${sub ? `<div class="p-cell-muted" style="font-size:12px">${sub}</div>` : ''}</div>`;
        const kv = (obj, map) => Object.entries(obj || {}).map(([k, n]) => `<li>${esc((map && map[k]) || k)} <b>× ${n}</b></li>`).join('') || '<li class="p-cell-muted">لسه مفيش</li>';
        const errMap = { facebook: 'لينك فيسبوك غلط', instagram: 'لينك إنستجرام غلط', competitor: 'لينك منافس غلط', competitors: 'أكتر من 3 منافسين', own: 'ما كتبش أي صفحة' };
        const srcMap = { google_data: 'بحث جوجل (مدفوع)', cache: 'كاش العميل', global_cache: 'كاش مشترك', ci_candidates: 'مرشحي الحساب', directory: 'دليل السوق', none: 'مفيش نتيجة', monthly_budget_reached: 'وصل السقف', search_disabled: 'مقفول' };
        const bud = Number(I.budget);
        const budTxt = bud < 0 ? 'من غير سقف' : bud === 0 ? 'البحث المدفوع مقفول' : 'سقف $' + bud;
        const intelHtml = `<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px">
${tile('فحوصات عامة (30 يوم)', I.checks_30d || 0, 'كمّل وسجّل: ' + (I.converted_30d || 0) + (I.checks_30d ? ' (' + Math.round((I.converted_30d || 0) * 100 / I.checks_30d) + '%)' : ''))}
${tile('بحث المنافسين (30 يوم)', I.runs_30d || 0, I.cache_hit_pct == null ? '' : 'من غير تكلفة: ' + I.cache_hit_pct + '%')}
${tile('إنفاق جوجل الشهر ده', '$' + (I.spent_month || 0), budTxt + ' · آخر 30 يوم $' + (I.cost_30d || 0))}
${tile('دليل المنافسين', I.directory_size || 0, 'شركة محفوظة حسب السوق')}</div>
<label style="display:flex;gap:8px;align-items:center;margin-top:10px;flex-wrap:wrap"><input type="checkbox" id="spaTopup" ${c.auto_topup ? 'checked' : ''}> <span>السماح للعملاء بتفعيل «شحن المنشورات تلقائيًا» (استخدام زائد بحزمة الإضافات من محفظتهم وبسقفهم الشهري)</span></label>
<label style="display:flex;gap:8px;align-items:center;margin-top:10px;flex-wrap:wrap"><span>سقف الإنفاق الشهري على «اكتشف منافسيني» ($)</span><input class="p-select" type="number" min="-1" step="1" id="spaFinderBudget" value="${esc(c.finder_budget_usd)}" style="max-width:110px"><span class="p-cell-muted" style="font-size:12px">-1 من غير سقف · 0 مقفول</span></label>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-top:10px">
<div><b>مصادر البحث</b><ul style="margin:6px 0;padding-inline-start:18px">${kv(I.by_source, srcMap)}</ul></div>
<div><b>أكتر منافسين بيتكرروا</b><ul dir="ltr" style="margin:6px 0;padding-inline-start:18px;text-align:left">${(I.directory_top || []).map(d => `<li>${esc(d.domain)} <small>(${esc(d.market)})</small> <b>× ${d.seen}</b></li>`).join('') || '<li class="p-cell-muted">لسه مفيش</li>'}</ul></div>
<div><b>أكتر حاجة بتغلّط الزوار</b><ul style="margin:6px 0;padding-inline-start:18px">${kv(I.top_errors, errMap)}</ul></div>
<div><b>أسباب فشل متابعة المنافسين</b><ul style="margin:6px 0;padding-inline-start:18px">${kv(I.blockers)}</ul></div></div>
<div style="max-height:260px;overflow:auto;margin-top:8px"><b>آخر المهتمين</b>${(I.recent || []).map(r => `<div dir="ltr" style="display:flex;flex-wrap:wrap;gap:6px 14px;padding:6px 0;border-bottom:1px solid var(--border,rgba(128,128,128,.25))"><span>${r.fb ? 'fb: ' + esc(r.fb) : ''}</span><span>${r.ig ? 'ig: ' + esc(r.ig) : ''}</span><span>${r.n_comp} comp</span><span class="p-cell-muted">${esc(r.at)}</span><span>${r.converted ? '✅ سجّل' : ''}</span></div>`).join('') || '<p class="p-cell-muted">لسه مفيش</p>'}</div>`;
        return `
<h4>💰 التكلفة والهامش (أسوأ حالة: العميل استهلك الحدود كلها)</h4>
<p class="p-cell-muted">الأرقام دي تقديرات تشغيل لازم تعدّلها بتكلفتك الفعلية (فاتورة Gemini/Meta/الاستضافة). الهامش بعد رسوم بوابة الدفع.</p>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px">
${TIERS.map(t => { const e = eco[t] || {}; const free = t === 'free'; const low = !free && (e.margin_pct_monthly < 40 || e.margin_pct_yearly < 40); const col = v => v < 40 ? 'var(--danger,#e5484d)' : 'inherit';
    return `<div style="padding:12px;border:1px solid var(--border,rgba(128,128,128,.3));border-radius:12px"><b>${esc(c.tiers[t].name)}${low ? ' ⚠️' : ''}</b>
<div class="p-cell-muted" style="font-size:12px">التكلفة/شهر: <b>$${e.cost}</b></div>
${free ? '<div class="p-cell-muted" style="font-size:12px">باقة مجانية: تكلفة بالكامل</div>' : `<div class="p-cell-muted" style="font-size:12px">السعر الشهري: <b>$${e.price_monthly}</b></div>
<div style="font-size:13px;color:${col(e.margin_pct_monthly)}">هامش شهري: <b>${pct(e.margin_pct_monthly)}</b> ($${e.margin_monthly})</div>
<div style="font-size:13px;color:${col(e.margin_pct_yearly)}">هامش سنوي: <b>${pct(e.margin_pct_yearly)}</b></div>`}</div>`; }).join('')}</div>
<table><tbody>${Object.entries(costLabels).map(([k, l]) => `<tr><td>${l}</td><td><input class="p-select" type="number" min="0" step="0.001" data-cost="${k}" value="${esc(cs[k])}"></td></tr>`).join('')}</tbody></table>

<h4>🏷️ أكواد الخصم والعروض</h4>
<p class="p-cell-muted">الخصم بيتطبق على أول دورة بس (التجديد بالسعر العادي)، وكل عميل يستخدم الكود مرة واحدة.</p>
<div>${promoRows || '<p class="p-cell-muted">مفيش أكواد. اضغط «إضافة كود».</p>'}</div>
<button type="button" class="p-btn" id="spaPromoAdd">➕ إضافة كود</button>

<h4>👥 لو غيّرت السعر: المشتركين الحاليين</h4>
<label><input type="radio" name="spaPolicy" value="at_renewal" ${c.renewal_policy !== 'grandfather' ? 'checked' : ''}> يتجددوا بالسعر الجديد (عند أول تجديد)</label><br>
<label><input type="radio" name="spaPolicy" value="grandfather" ${c.renewal_policy === 'grandfather' ? 'checked' : ''}> يفضلوا على سعرهم القديم لو السعر الجديد أغلى (نفس الباقة والدورة)</label>
<p class="p-cell-muted">مفيش تغيير سعر بيتطبق على دورة شغالة بالفعل في الحالتين، والتغيير بيبان للمشترك قبل التجديد بتذكير 3 أيام.</p>

<h4>📊 الاستهلاك الشهر ده (أعلى 30 حساب)</h4>
<p class="p-cell-muted">التكلفة تقديرية من أسعار الوحدة فوق. ⚠️ = وصل 80% أو أكتر من حد المنشورات أو الكابشن.</p>
<div style="overflow-x:auto;max-height:340px">${usageRows}</div>

<h4>🧠 اللي بنتعلمه من الفحوصات</h4>
<p class="p-cell-muted">كل فحص بيتحوّل لمعلومة: مين مهتم ومين كمّل، وأي منافسين بيتكرروا في كل سوق (بنوفّر بيهم تكلفة بحث جوجل لعملاء جايين)، وإيه اللي بيعطّل العملاء. بنسجّل أسماء الصفحات العامة بس، من غير IP ولا بيانات شخصية.</p>
${intelHtml}
<h4>🧾 سجل التعديلات (آخر 30)</h4>
<div style="overflow-x:auto;max-height:320px"><table><thead><tr><th>الوقت (UTC)</th><th>الأدمن</th><th>التغيير</th></tr></thead><tbody>${audit || '<tr><td colspan="3" class="p-cell-muted">لسه مفيش تعديلات مسجّلة.</td></tr>'}</tbody></table></div>`;
    };

    const collectPromos = () => {
        D.config.promos = [...root.querySelectorAll('[data-promo]')].map(tr => {
            const g = k => tr.querySelector(`[data-pf="${k}"]`);
            return { code: g('code').value.trim().toUpperCase(), label: '', percent: Number(g('percent').value), starts: g('starts').value, ends: g('ends').value, max_uses: Number(g('max_uses').value), enabled: g('enabled').checked,
                tiers: [...tr.querySelectorAll('[data-ptier]:checked')].map(x => x.dataset.ptier), cycles: [...tr.querySelectorAll('[data-pcycle]:checked')].map(x => x.dataset.pcycle) };
        });
    };

    const render = () => {
        const c = D.config, L = D.labels, st = D.stats || {};
        const lim = k => (L[k] ? L[k][0] : k);
        const row = (label, cell) => `<tr><td>${label}</td>${TIERS.map(cell).join('')}</tr>`;
        const num = (t, k, attrs) => `<td><input class="p-select" type="number" data-t="${t}" data-k="${k}" value="${esc(c.tiers[t][k])}" ${attrs || ''}></td>`;
        root.innerHTML = `<div id="spaMsg" class="ps-msg" hidden></div>
<section class="ps-box ps-adm"><h3>📱 باقات السوشيال ميديا</h3>
<p class="p-cell-muted">المستوى الفعلي لكل عميل = الأعلى بين باقة المنصة (حسب الربط تحت) واشتراك السوشيال المنفصل اللي بيدفعه من المحفظة. -1 = غير محدود، 0 = مش مشمول. كل منصة في المنشور بتتحسب منشور. الحسابات اللي فوق الحد بتتقفل للنشر بس (مش بتتمسح).</p>
${D.ready ? '' : '<div class="ps-msg bad">جداول الباقات مش موجودة: شغّل migration ‏2026_10_22_000001_social_packages.sql.</div>'}
<p><b>اشتراكات سوشيال شغالة:</b> ${st.active || 0} · <b>إيراد شهري متكرر تقريبي:</b> $${st.mrr || 0} ${Object.entries(st.by_tier || {}).map(([k, n]) => '· ' + esc(c.tiers[k]?.name || k) + ': ' + n).join(' ')}</p>
<label><input type="checkbox" id="spaEnforce" ${c.enforce ? 'checked' : ''}> تطبيق حدود الباقات (لو اتقفل: كل العملاء من غير حدود زي الأول)</label>
<div style="overflow-x:auto"><table><thead><tr><th></th>${TIERS.map(t => `<th>${esc(c.tiers[t].name)}</th>`).join('')}</tr></thead><tbody>
${row('الاسم (عربي)', t => `<td><input class="p-select" data-t="${t}" data-k="name" value="${esc(c.tiers[t].name)}"></td>`)}
${row('الاسم (إنجليزي)', t => `<td><input class="p-select" data-t="${t}" data-k="name_en" value="${esc(c.tiers[t].name_en)}"></td>`)}
${row('السعر الشهري $', t => t === 'free' ? '<td class="p-cell-muted">مجاني</td>' : num(t, 'price_monthly', 'min="0" step="0.5"'))}
${row('السعر السنوي $', t => t === 'free' ? '<td class="p-cell-muted">مجاني</td>' : num(t, 'price_yearly', 'min="0" step="1"'))}
${row('متاحة للبيع', t => t === 'free' ? '<td class="p-cell-muted">—</td>' : `<td><input type="checkbox" data-t="${t}" data-k="for_sale" ${c.tiers[t].for_sale ? 'checked' : ''}></td>`)}
${D.limit_keys.map(k => row(esc(lim(k)), t => num(t, k, 'min="-1"'))).join('')}
${D.feature_keys.map(k => row(esc(lim(k)), t => `<td><input type="checkbox" data-t="${t}" data-k="${k}" ${c.tiers[t][k] ? 'checked' : ''}></td>`)).join('')}
${row('المنصات', t => `<td>${Object.entries(D.platform_names).map(([p, n]) => `<label style="display:block;white-space:nowrap"><input type="checkbox" data-t="${t}" data-plat="${p}" ${c.tiers[t].platforms.includes(p) ? 'checked' : ''}> ${esc(n)}</label>`).join('')}</td>`)}
</tbody></table></div>

<h4>🌐 المنصات المتاحة على المنصة كلها</h4>
<p class="p-cell-muted">المنصة المقفولة بتختفي من الربط والنشر والباقات لكل العملاء (LinkedIn مقفولة افتراضيًا).</p>
<div>${Object.entries(D.platform_names).map(([p, n]) => `<label style="margin-inline-end:14px;white-space:nowrap"><input type="checkbox" data-en="${p}" ${c.platforms_enabled.includes(p) ? 'checked' : ''}> ${esc(n)}</label>`).join('')}</div>

<h4>🧩 فصّل باقتك - أسعار الوحدة شهريًا $</h4>
<label><input type="checkbox" id="spaB_enabled" ${c.builder.enabled ? 'checked' : ''}> متاحة للعملاء</label>
<table><tbody>
<tr><td>سعر الحساب الواحد</td><td><input class="p-select" type="number" min="0" step="0.5" data-b="channel" value="${c.builder.channel}"></td><td class="p-cell-muted">السوق: Buffer ‏$6، Publer ‏$4-5</td></tr>
<tr><td>منشورات مع كل حساب</td><td><input class="p-select" type="number" min="0" data-b="posts_per_channel" value="${c.builder.posts_per_channel}"></td><td></td></tr>
<tr><td>كابشن AI مع كل حساب</td><td><input class="p-select" type="number" min="0" data-b="ai_per_channel" value="${c.builder.ai_per_channel}"></td><td></td></tr>
<tr><td>أقصى عدد حسابات</td><td><input class="p-select" type="number" min="1" data-b="max_channels" value="${c.builder.max_channels}"></td><td></td></tr>
<tr><td>خطة شهرية (للخطة)</td><td><input class="p-select" type="number" min="0" step="0.5" data-b="monthly_plan" value="${c.builder.monthly_plan}"></td><td></td></tr>
${['posts_block', 'ai_block', 'competitors_block'].map(k => `<tr><td>${{ posts_block: 'بلوك منشورات', ai_block: 'بلوك كابشن', competitors_block: 'بلوك منافسين' }[k]}</td><td>الكمية <input class="p-select" type="number" min="1" data-bb="${k}" data-g="qty" value="${c.builder[k].qty}"></td><td>السعر <input class="p-select" type="number" min="0" step="0.5" data-bb="${k}" data-g="price" value="${c.builder[k].price}"></td></tr>`).join('')}
${D.feature_keys.map(k => `<tr><td>${esc(lim(k))}</td><td><input class="p-select" type="number" min="0" step="0.5" data-bfp="${k}" value="${c.builder.features[k]}"></td><td></td></tr>`).join('')}
<tr><td>السنوي = كام شهر</td><td><input class="p-select" type="number" min="1" max="12" data-b="yearly_months" value="${c.builder.yearly_months}"></td><td class="p-cell-muted">10 = شهرين ببلاش</td></tr>
</tbody></table>

<h4>➕ الإضافات (مرة واحدة - 30 يوم)</h4>
<table><thead><tr><th>الإضافة</th><th>الكمية</th><th>السعر $</th><th>متاحة</th></tr></thead><tbody>${Object.entries(c.addons).map(([k, a]) => `<tr><td>${a.icon} ${esc(lim(a.kind))}</td><td><input class="p-select" type="number" min="1" data-aq="${k}" value="${a.qty}"></td><td><input class="p-select" type="number" min="0.5" step="0.5" data-ap="${k}" value="${a.price}"></td><td><input type="checkbox" data-ae="${k}" ${a.enabled ? 'checked' : ''}></td></tr>`).join('')}</tbody></table>

<h4>🔗 باقة المنصة ← مستوى السوشيال</h4>
<table><thead><tr><th>باقة المنصة</th><th>بتدي سوشيال</th></tr></thead><tbody>${Object.entries(Object.assign({}, D.platform_plans, Object.fromEntries(Object.keys(c.plan_map).map(k => [k, D.platform_plans[k] || k])))).map(([k, n]) => `<tr><td>${esc(n)} <span class="p-cell-muted">(${esc(k)})</span></td><td><select class="p-select" data-map="${esc(k)}">${TIERS.map(t => `<option value="${t}" ${(c.plan_map[k] || c.plan_default) === t ? 'selected' : ''}>${esc(c.tiers[t].name)}</option>`).join('')}</select></td></tr>`).join('')}
<tr><td>أي باقة تانية (مخصصة)</td><td><select class="p-select" id="spaDefault">${TIERS.map(t => `<option value="${t}" ${c.plan_default === t ? 'selected' : ''}>${esc(c.tiers[t].name)}</option>`).join('')}</select></td></tr></tbody></table>
<p class="p-cell-muted">لعميل بعينه: حط "social_tier": "agency" (مثلًا) في استثناءات ميزات العميل من صفحة العملاء.</p>
${extras(c)}
<button class="p-btn primary" id="spaSave">حفظ باقات السوشيال</button></section>`;
        root.querySelector('#spaSave').addEventListener('click', save);
        root.querySelector('#spaPromoAdd').addEventListener('click', () => { collectPromos(); D.config.promos.push({ code: '', label: '', percent: 10, tiers: [], cycles: [], starts: '', ends: '', max_uses: 0, enabled: true }); render(); });
        root.querySelectorAll('[data-promo-del]').forEach(b => b.addEventListener('click', () => { collectPromos(); D.config.promos.splice(Number(b.dataset.promoDel), 1); render(); }));
    };

    const save = async (e) => {
        const b = e.currentTarget; b.disabled = true;
        try {
            const tiers = {};
            TIERS.forEach(t => { tiers[t] = { platforms: [] }; });
            root.querySelectorAll('[data-t][data-k]').forEach(el => {
                const t = el.dataset.t, k = el.dataset.k;
                tiers[t][k] = el.type === 'checkbox' ? el.checked : (el.type === 'number' ? Number(el.value) : el.value);
            });
            root.querySelectorAll('[data-t][data-plat]').forEach(el => { if (el.checked) tiers[el.dataset.t].platforms.push(el.dataset.plat); });
            const addons = {};
            Object.keys(D.config.addons).forEach(k => {
                addons[k] = { qty: Number(root.querySelector(`[data-aq="${k}"]`).value), price: Number(root.querySelector(`[data-ap="${k}"]`).value), enabled: root.querySelector(`[data-ae="${k}"]`).checked };
            });
            const plan_map = {};
            const def = root.querySelector('#spaDefault').value;
            // بنبعت بس الباقات اللي اتغيّرت أو كانت متظبّطة قبل كده (من غير ما نثبّت الافتراضي على كل باقة)
            root.querySelectorAll('[data-map]').forEach(el => { if (el.dataset.map in D.config.plan_map || el.value !== def) plan_map[el.dataset.map] = el.value; });
            const builder = { enabled: root.querySelector('#spaB_enabled').checked, features: {} };
            root.querySelectorAll('[data-b]').forEach(el => { builder[el.dataset.b] = Number(el.value); });
            root.querySelectorAll('[data-bb]').forEach(el => { (builder[el.dataset.bb] = builder[el.dataset.bb] || {})[el.dataset.g] = Number(el.value); });
            root.querySelectorAll('[data-bfp]').forEach(el => { builder.features[el.dataset.bfp] = Number(el.value); });
            const platforms_enabled = [...root.querySelectorAll('[data-en]:checked')].map(el => el.dataset.en);
            collectPromos();
            const costs = {}; root.querySelectorAll('[data-cost]').forEach(el => { costs[el.dataset.cost] = Number(el.value); });
            const renewal_policy = root.querySelector('input[name="spaPolicy"]:checked')?.value || 'at_renewal';
            const finder_budget_usd = Number(root.querySelector('#spaFinderBudget')?.value ?? 5);
            const r = await api('/api/admin/social-packages', { config: { promos: D.config.promos, costs, renewal_policy, finder_budget_usd, auto_topup: !!root.querySelector('#spaTopup')?.checked, enforce: root.querySelector('#spaEnforce').checked, plan_default: root.querySelector('#spaDefault').value, tiers, addons, plan_map, builder, platforms_enabled } });
            D.config = r.data.config; D.economics = r.data.economics; D.audit = r.data.audit;
            render();
            msg('اتحفظت باقات السوشيال ✓');
        } catch (err) {
            msg(err.message, true);
        } finally {
            b.disabled = false;
        }
    };

    window.SocialPackagesAdmin = {
        init: async (panel) => {
            P = panel;
            root = document.getElementById('socialPkgAdmin');
            if (!root) return;
            try {
                D = (await api('/api/admin/social-packages')).data;
                render();
            } catch (e) {
                root.innerHTML = '<div class="ps-msg bad">' + esc(e.message) + '</div>';
            }
        }
    };
})();
