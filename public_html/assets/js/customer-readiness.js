(() => {
  'use strict';
  const ar = (document.documentElement.lang || '').startsWith('ar');
  const t = text => ar ? (window.TF_AR_DICT?.[text] || text) : text;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, x => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[x]));
  const states = {configured: ar ? 'الإعداد محفوظ' : 'Configured', observed:ar?'نبضة حديثة':'Recent heartbeat', action_required:ar?'محتاج خطوة':'Action needed', waiting_approval:ar?'مستني موافقة':'Awaiting approval', scheduled:ar?'مستني الموعد':'Scheduled', not_connected:ar?'مش مربوط':'Not connected', not_configured:ar?'اختياري — غير مجهّز':'Optional — not set up', needs_check:ar?'محتاج تحقق':'Needs a check', unknown:ar?'تعذر التحقق':'Unknown'};
  const date = v => { if (!v) return ''; const d = new Date(/[zZ]|[+]\d\d:\d\d$/.test(v) ? v : v.replace(' ','T')+'Z'); return Number.isNaN(d.getTime()) ? '' : d.toLocaleString(ar?'ar-EG':'en-GB',{dateStyle:'short',timeStyle:'short'}); };
  async function load(root, requested) {
    const sequence=(root._readinessSequence||0)+1;root._readinessSequence=sequence;
    const controller=new AbortController(), timer=setTimeout(()=>controller.abort(),20000);
    root.setAttribute('aria-busy','true');
    const detail = root.querySelector('details')?.open || root.dataset.compact === 'false';
    const wid = requested === null ? '' : (requested ?? window.Panel?.getCurrentWebsiteId?.() ?? '');
    try {
      const r = await window.Panel.fetchJSON('/api/customer-readiness'+(wid?'?website_id='+encodeURIComponent(wid):''),{signal:controller.signal});
      if(root._readinessSequence!==sequence)return;
      if (!r.success) throw new Error(r.error || t('Could not load readiness.'));
      const d = r.data, selected = d.website?.id;
      // حساب لسه مالوش موقع: كارت «طريقك لأول حجز» بيغطي الخطوة دي، فمانكررش كارت فاضي فوقه
      const noSite = !(d.websites || []).length;
      root.style.display = noSite ? 'none' : '';
      if (noSite) return;
      const needed = d.checks.filter(x => ['action_required','not_connected','waiting_approval','needs_check','unknown'].includes(x.state)).length;
      const title = ar?'جاهزية موقعك والخطوة الجاية':'Your website readiness and next step';
      const options = d.websites.map(x=>`<option value="${+x.id}" ${+x.id===+selected?'selected':''}>${esc(x.company_name||x.main_url)}</option>`).join('');
      root.innerHTML = `<details ${detail?'open':''}><summary><strong>${title}</strong><span>${needed ? (ar?`${needed} نقطة للمراجعة`:`${needed} items to review`) : (ar?'الإعدادات متاحة للمراجعة':'Configuration available')}</span></summary><div class="cr-toolbar"><label>${ar?'الموقع':'Website'} <select data-cr-site>${options||'<option value="">—</option>'}</select></label><button type="button" data-cr-refresh>${ar?'تحديث الفحص':'Refresh checks'}</button><a href="/customer-demo">${ar?'جرّب ببيانات وهمية':'Try the safe demo'}</a></div><p>${esc(t(d.scope))} · ${esc(date(d.generated_at))}</p><div class="cr-grid">${d.checks.map(c=>`<article class="cr-check" data-state="${esc(c.state)}"><strong>${esc(t(c.label))}</strong><span>${esc(states[c.state]||states.unknown)}</span><p>${esc(t(c.message))}</p>${c.last_success?`<p>${ar?'آخر مزامنة مسجلة':'Last recorded sync'}: ${esc(date(c.last_success))}</p>`:''}${c.next_run?`<p>${ar?'الموعد التالي':'Next scheduled time'}: ${esc(date(c.next_run))}</p>`:''}<a href="${esc(c.href)}">${ar?'افتح التفاصيل والإجراء':'Open details and next action'}</a></article>`).join('')}</div><details class="cr-allowances"><summary>${ar?'الحصص والتكلفة: الفرق بين الأنواع':'Allowances and cost: how the units differ'}</summary><p>${ar?'تحليل AI فوري، ودورة الطيار الآلي الشهرية، وقياس كلمة بحث وحدات مختلفة. امتلاء حصة نوع لا يعني انتهاء كل حصصك. راجع الرصيد والتكلفة الخاصة بالفعل قبل التنفيذ. تجهيز الخطة مختلف عن نشرها، والربط المحفوظ مختلف عن نجاح الإرسال.':'An instant AI analysis, a monthly autopilot cycle and a keyword measurement use different allowances. One exhausted allowance does not exhaust all features. Review the action-specific quota and cost before running it. Preparing a plan differs from publishing it; a saved connection differs from successful delivery.'}</p><a href="/subscription">${ar?'الباقة والاستخدام':'Plan and usage'}</a> · <a href="/business-hub">${ar?'ملف الشركة المؤكد':'Confirmed company profile'}</a></details></details>`;
      root.querySelector('[data-cr-site]').addEventListener('change', e => { if(window.Panel?.setCurrentWebsiteId) window.Panel.setCurrentWebsiteId(e.target.value); else load(root,e.target.value); });
      root.querySelector('[data-cr-refresh]').addEventListener('click', () => load(root, root.querySelector('[data-cr-site]').value));
      if(requested===null && selected && String(window.Panel?.getCurrentWebsiteId?.())!==String(selected)) window.Panel?.setCurrentWebsiteId?.(selected);
      // Only the selected, owned website has been returned; this does not change another service's settings.
    } catch (e) {
      if(root._readinessSequence!==sequence)return;
      root.innerHTML = `<p role="alert">${esc(e.message)}</p><button type="button" data-cr-retry>${ar?'إعادة المحاولة':'Try again'}</button> <button type="button" data-cr-reset>${ar?'اختيار أول موقع في حسابي':'Use the first website in my account'}</button>`;
      root.querySelector('[data-cr-retry]').onclick=()=>load(root,requested);
      root.querySelector('[data-cr-reset]').onclick=()=>load(root,null);
    } finally { clearTimeout(timer);if(root._readinessSequence===sequence)root.setAttribute('aria-busy','false'); }
  }
  document.querySelectorAll('[data-customer-readiness]').forEach(root=>{
    load(root);
    window.addEventListener('tourfecto:website-changed',e=>load(root,e.detail.websiteId));
  });
})();
