/* Tourfecto - أدمن: باقات السوشيال (الأسعار، الحدود، المنصات، الميزات، الإضافات، ربطها بباقات المنصة) */
(() => {
    'use strict';
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    let P, root, D;
    const api = async (url, body) => { const r = await P.fetchJSON(url, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...body, csrf_token: D?.csrf_token }) } : undefined); if (!r?.success) throw new Error(r?.error || 'حصلت مشكلة'); return r; };
    const msg = (m, bad) => { const el = root.querySelector('#spaMsg'); el.textContent = m; el.className = 'ps-msg' + (bad ? ' bad' : ''); el.hidden = !m; };
    const TIERS = ['free', 'basic', 'pro', 'agency'];

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
<button class="p-btn primary" id="spaSave">حفظ باقات السوشيال</button></section>`;
        root.querySelector('#spaSave').addEventListener('click', save);
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
            root.querySelectorAll('[data-map]').forEach(el => { plan_map[el.dataset.map] = el.value; });
            const builder = { enabled: root.querySelector('#spaB_enabled').checked, features: {} };
            root.querySelectorAll('[data-b]').forEach(el => { builder[el.dataset.b] = Number(el.value); });
            root.querySelectorAll('[data-bb]').forEach(el => { (builder[el.dataset.bb] = builder[el.dataset.bb] || {})[el.dataset.g] = Number(el.value); });
            root.querySelectorAll('[data-bfp]').forEach(el => { builder.features[el.dataset.bfp] = Number(el.value); });
            const platforms_enabled = [...root.querySelectorAll('[data-en]:checked')].map(el => el.dataset.en);
            const r = await api('/api/admin/social-packages', { config: { enforce: root.querySelector('#spaEnforce').checked, plan_default: root.querySelector('#spaDefault').value, tiers, addons, plan_map, builder, platforms_enabled } });
            D.config = r.data.config;
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
