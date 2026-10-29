/** Tourfecto - لوحة الأدمن للعقل الاستراتيجي (عربي؛ صفحات الأدمن عربي زي باقي الأدمن) */
(() => {
  'use strict';
  const start = async () => {
    const root = document.getElementById('adminBrain'), P = window.Panel;
    if (!root || !P) return;
    const esc = P.esc, num = v => Number(v || 0).toLocaleString('en-US');
    let data = null, note = null;
    const req = async (url, body) => {
      const r = await P.fetchJSON(url, body ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': data?.csrf_token || root.dataset.csrf }, body: JSON.stringify({ ...body, csrf_token: data?.csrf_token || root.dataset.csrf }) } : undefined);
      if (!r?.success) throw new Error(r?.error || 'حصلت مشكلة');
      return r.data;
    };
    const STATUS = { proposed: 'مقترح', approved: 'شغالين عليه', done: 'اتنفّذ', resolved: 'اتحل', dismissed: 'مؤجل' };
    const AREA = { data: 'فهم الشركة', seo: 'السيو', reputation: 'السمعة', whatsapp: 'الشات', social: 'السوشيال', ads: 'الإعلانات', content: 'المحتوى', acquisition: 'جلب عملاء', market: 'السوق' };
    const tile = (v, l, s) => `<div class="br-tile"><b>${v}</b><span>${esc(l)}</span>${s ? `<small>${esc(s)}</small>` : ''}</div>`;

    function render() {
      const d = data, st = d.settings || {}, ai = d.ai_limits || {};
      const sources = (d.sources || []).map(s => `<tr><td dir="ltr">${esc(s.source)}</td><td>${s.status === 'ok' ? '✓ شغال' : s.status === 'empty' ? 'مفيش بيانات' : '✗ خطأ'}</td><td>${num(s.rows_saved)}</td><td dir="ltr">${esc(s.fetched_at)}</td><td dir="ltr" class="br-muted">${esc(s.error || '')}</td></tr>`).join('');
      const areas = (d.areas || []).map(a => `<tr><td>${esc(AREA[a.area] || a.area)}</td><td>${num(a.total)}</td><td>${num(a.improved)}</td><td>${num(a.worse)}</td></tr>`).join('');
      const check = (k, label, hint) => `<label class="br-check-row"><input type="checkbox" data-brain="${k}" ${st[k] ? 'checked' : ''}> <span><b>${esc(label)}</b><small>${esc(hint)}</small></span></label>`;
      const aiField = (k, label, val, hint) => `<label class="br-field">${esc(label)}<input class="p-select" type="number" min="0" step="any" data-ai="${k}" value="${val ?? ''}" placeholder="من .env"><small>${esc(hint)}</small></label>`;
      root.innerHTML = `${note ? `<p class="br-note ${note.error ? 'error' : ''}">${esc(note.text)}</p>` : ''}
        <section class="br-card"><h3>الحالة على المنصة</h3><div class="br-tiles">
          ${tile(num(d.companies), 'شركات عندها قرارات')}${tile(num(d.autonomy_on), 'فاتحين التشغيل الذاتي')}${tile(num(d.sharing_on), 'مشاركين في الذكاء المشترك', num(d.shared_patterns) + ' نوع قرار وصل لـ 5 شركات')}
          ${tile(num(d.executed_today), 'قرارات اتنفّذت النهارده')}${tile(num(d.outcomes?.improved), 'قرارات نفعت', num(d.outcomes?.worse) + ' اتراجعت · ' + num(d.outcomes?.unchanged) + ' ماتغيرتش')}
          ${tile('$' + num(d.ai_month?.cost), 'تكلفة الذكاء الاصطناعي الشهر ده', num(d.ai_month?.calls) + ' طلب')}</div>
          <p class="br-muted" style="margin-top:10px">${Object.entries(d.status || {}).map(([k, v]) => esc(STATUS[k] || k) + ': ' + num(v)).join(' · ') || 'لسه مفيش قرارات'}</p>
          ${areas ? `<div class="p-table-scroll"><table class="p-table"><thead><tr><th>المجال</th><th>قرارات</th><th>نفعت</th><th>اتراجعت</th></tr></thead><tbody>${areas}</tbody></table></div>` : ''}</section>

        <section class="br-card"><h3>إعدادات العقل</h3>
          ${check('brain_enabled', 'العقل شغال', 'لو اتقفل، صفحة العقل عند كل الشركات بتقول إنه متوقف مؤقتًا، وكل الخدمات التانية بتشتغل عادي.')}
          ${check('brain_autonomy_allowed', 'السماح بالتشغيل الذاتي', 'لو اتقفل، مفيش تنفيذ تلقائي عند أي شركة حتى لو فاتحاه. القرارات اللي فيها صرف عمرها ما بتتنفّذ تلقائي.')}
          ${check('brain_market_enabled', 'جلب بيانات السوق', 'GOV.UK وECB وEurostat وNager.Date وWorld Bank — مجانية ومن غير مفاتيح.')}
          <label class="br-field">أقصى عدد قرارات بتتنفّذ تلقائيًا لكل شركة في اليوم<input class="p-select" type="number" min="0" max="200" data-brain="brain_auto_actions_per_day" value="${st.brain_auto_actions_per_day ?? 10}"></label>
          <h3 style="margin-top:18px">حدود الذكاء الاصطناعي لكل شركة</h3>
          <p class="br-muted">بتسبق إعدادات .env. سيب الخانة فاضية عشان يرجع لـ .env. صفر = من غير حد. الأدمن مستثنى. الحالي: ${ai.enabled ? 'مفعّل' : 'متوقف من .env'}.</p>
          <div class="br-facts">${aiField('ai_limit_per_minute', 'طلبات في الدقيقة', ai.per_minute, 'الحالي: ' + ai.per_minute)}${aiField('ai_limit_per_day', 'طلبات في اليوم', ai.per_day, 'الحالي: ' + ai.per_day)}
            ${aiField('ai_limit_usd_per_day', 'دولار في اليوم', ai.usd_per_day, 'الحالي: ' + ai.usd_per_day)}${aiField('ai_limit_usd_per_month', 'دولار في الشهر', ai.usd_per_month, 'الحالي: ' + ai.usd_per_month)}</div>
          <div class="br-actions"><button type="button" class="p-btn primary" id="abSave">احفظ الإعدادات</button></div></section>

        <section class="br-card"><div class="br-head"><div><h3>مصادر السوق</h3><p class="br-muted">آخر جلب لكل مصدر ونطاق (الوجهة والأسواق).</p></div><button type="button" class="p-btn outline" id="abRefresh">حدّث مصادر مصر دلوقتي</button></div>
          ${sources ? `<div class="p-table-scroll"><table class="p-table"><thead><tr><th>المصدر:النطاق</th><th>الحالة</th><th>صفوف</th><th>آخر جلب</th><th>الخطأ</th></tr></thead><tbody>${sources}</tbody></table></div>` : '<p class="br-muted">لسه ماتجابتش بيانات.</p>'}</section>

        <section class="br-card"><h3>باقي التحكم (صفحات موجودة)</h3><div class="br-actions">
          <a class="p-btn outline" href="/admin/ai-routing">مزوّدي الذكاء الاصطناعي ونماذجه</a><a class="p-btn outline" href="/admin/ai-usage">تكلفة واستهلاك الذكاء الاصطناعي</a>
          <a class="p-btn outline" href="/admin/plans-studio">الباقات والأسعار</a><a class="p-btn outline" href="/admin/seo-data-costs">ميزانية بيانات السيو (DataForSEO)</a>
          <a class="p-btn outline" href="/admin/team">الصلاحيات والفريق</a></div></section>`;
      root.querySelector('#abSave').onclick = async () => {
        const brain = {}, ai = {};
        root.querySelectorAll('[data-brain]').forEach(el => { brain[el.dataset.brain] = el.type === 'checkbox' ? el.checked : Number(el.value || 0); });
        root.querySelectorAll('[data-ai]').forEach(el => { ai[el.dataset.ai] = el.value.trim(); });
        try { await req('/api/admin/brain/settings', { brain, ai }); note = { text: 'اتحفظ ✓' }; await load(); } catch (e) { note = { text: e.message, error: true }; render(); }
      };
      root.querySelector('#abRefresh').onclick = async (ev) => {
        ev.target.disabled = true;
        try { const r = await req('/api/admin/brain/market-refresh', { destination_text: 'Egypt' }); note = { text: 'اتحدث: ' + Object.entries(r.rows).map(([k, v]) => k + ' ' + v).join('، ') }; await load(); }
        catch (e) { note = { text: e.message, error: true }; render(); }
      };
    }
    async function load() {
      try { data = await req('/api/admin/brain'); render(); }
      catch (e) { root.innerHTML = `<section class="br-card"><p>${esc(e.message)}</p></section>`; }
    }
    root.innerHTML = '<section class="br-card"><p>جارٍ التحميل…</p></section>';
    load();
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true }); else start();
})();
