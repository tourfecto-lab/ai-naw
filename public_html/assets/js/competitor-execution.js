(function () {
    'use strict';
    const P=window.Panel, esc=P.esc, api=P.fetchJSON;
    const ar=(document.documentElement.lang||'').startsWith('ar');
    const t=(en,arabic)=>ar?arabic:en;
    const names={queued:t('Queued','في انتظار العامل'),running:t('Preparing improvements','تجهيز التحسينات'),prepared:t('Ready to apply','جاهز للتطبيق'),applied:t('Applied to your site connection','تم التطبيق على ربط موقعك'),failed:t('Not applied','لم يتم التطبيق'),uncertain:t('Needs operation review','يحتاج مراجعة العملية'),rolled_back:t('Rolled back','تم التراجع')};
    const watching=new Set();
    const headers={'Content-Type':'application/json'};
    window.renderCompetitorExecution=function(c) {
        const p=c.content_policy||{}, id=Number(c.id),wid=Number(c.website_id);
        return `<div class="p-card" style="margin-top:12px;padding:12px" id="comp-exec-${id}" data-website-id="${wid}">
            <label>${t('Content language / target audience','لغة المحتوى / الجمهور المستهدف')}</label>
            <select class="form-control" id="comp-lang-${id}">
                <option value="auto" ${p.output_language==='auto'?'selected':''}>${t('Audience settings; English by default','حسب الجمهور؛ إنجليزي افتراضيًا')}</option>
                ${Object.entries(p.content_languages||{en:'English',ar:'العربية'}).map(([code,name])=>`<option value="${esc(code)}" ${p.output_language===code?'selected':''}>${esc(name)}</option>`).join('')}
            </select>
            <label style="display:block;margin:10px 0"><input type="checkbox" id="comp-auto-${id}" ${p.competitor_auto_apply?'checked':''}> ${t('Apply after future analyses','تطبيق تلقائي بعد التحليلات القادمة')}</label>
            <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="p-btn outline" onclick="saveCompetitorPolicy(${id},${wid})">${t('Save settings','حفظ الإعدادات')}</button><button class="p-btn" id="comp-apply-${id}" onclick="executeCompetitorImprovements(${id})">${t('Apply improvements','تنفيذ التحسينات تلقائيًا')}</button></div>
            <p class="p-cell-muted" style="font-size:12px">${t('Improves your homepage title, description, H1, factual text and WebPage schema. Uses one content-generation allowance or the configured AI-analysis usage price. External sites need an Auto SEO connection; script changes appear in the browser, while proxy changes are served in HTML.','يحسّن عنوان ووصف وH1 ونص ومخطط صفحتك الرئيسية. يستخدم رصيد توليد محتوى واحدًا أو سعر تحليل AI المهيأ. الموقع الخارجي يحتاج ربط Auto SEO؛ السكربت يطبق في المتصفح والـProxy يقدم HTML محسّنًا.')}</p>
            <div id="comp-exec-status-${id}" aria-live="polite"></div>
        </div>`;
    };
    window.saveCompetitorPolicy=async function(id,wid) {
        const res=await api(`/api/websites/${wid}/content-policy`,{method:'PUT',headers,body:JSON.stringify({output_language:document.getElementById('comp-lang-'+id).value,competitor_auto_apply:document.getElementById('comp-auto-'+id).checked})});
        if(!res.success){P.toast(res.error||t('Unable to save','تعذر الحفظ'),'error');return false;}
        document.querySelectorAll(`[data-website-id="${wid}"]`).forEach(box=>{const cid=box.id.replace('comp-exec-','');document.getElementById('comp-lang-'+cid).value=res.data.output_language;document.getElementById('comp-auto-'+cid).checked=res.data.competitor_auto_apply;});
        P.toast(t('Audience and automation saved','تم حفظ الجمهور والأتمتة'),'success');return true;
    };
    function show(id,r) {
        const box=document.getElementById('comp-exec-status-'+id);if(!box)return;
        box.innerHTML=`<p><b>${esc(names[r.status]||r.status)}</b> · ${esc(r.output_language||'')} · #${Number(r.id)}</p>${r.error?`<p>${esc(r.error)}</p>`:''}${r.changes?`<details><summary>${t('Applied content','المحتوى المنفذ')}</summary><p dir="auto">${esc(r.changes.seo_title)}</p><p dir="auto">${esc(r.changes.seo_description)}</p><p dir="auto">${esc(r.changes.h1)}</p><p dir="auto">${esc(r.changes.content_text)}</p></details>`:''}${r.can_rollback?`<button class="p-btn outline" onclick="rollbackCompetitorImprovements(${id},${Number(r.id)})">${t('Undo these changes','التراجع عن التغييرات')}</button>`:''}`;
        const btn=document.getElementById('comp-apply-'+id);if(btn)btn.disabled=['queued','running','prepared'].includes(r.status);
    }
    window.watchCompetitorExecution=async function(cid,r) {
        show(cid,r);
        if(!['queued','running','prepared'].includes(r.status)||watching.has(r.id))return;
        watching.add(r.id);
        try {for(let n=0;n<120;n++){await new Promise(resolve=>setTimeout(resolve,3000));if(!document.getElementById('comp-exec-status-'+cid))break;const res=await api(`/api/ai/competitor-executions/${r.id}`);if(!res.success)break;r=res.data;show(cid,r);if(!['queued','running','prepared'].includes(r.status))break;}}
        finally{watching.delete(r.id);}
    };
    window.executeCompetitorImprovements=async function(cid) {
        const box=document.getElementById('comp-exec-'+cid);if(!box)return;
        if(!await saveCompetitorPolicy(cid,Number(box.dataset.websiteId)))return;
        const btn=document.getElementById('comp-apply-'+cid);btn.disabled=true;
        try{const res=await api(`/api/ai/competitors/${cid}/execute`,{method:'POST',headers,body:'{}'});if(res.success)watchCompetitorExecution(cid,res.data);else{document.getElementById('comp-exec-status-'+cid).textContent=res.error||t('Unable to execute','تعذر التنفيذ');btn.disabled=false;}}
        catch(e){btn.disabled=false;P.toast(t('Request interrupted; reload to check operation status','الطلب انقطع؛ حدّث الصفحة لمعرفة حالة العملية'),'error');}
    };
    window.rollbackCompetitorImprovements=async function(cid,id) {
        const res=await api(`/api/ai/competitor-executions/${id}/rollback`,{method:'POST',headers,body:'{}'});if(res.success)show(cid,res.data);else P.toast(res.error||t('Unable to undo','تعذر التراجع'),'error');
    };
})();
