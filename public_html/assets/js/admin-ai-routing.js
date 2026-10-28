window.AdminAiRouting = (() => {
    'use strict';
    const groups={all:'كل الخدمات',content:'المحتوى والمواقع',marketing:'التسويق والإبداع',business:'التحليل وإدارة الأعمال',support:'التواصل والعملاء',media:'الصور والفيديو'};
    const clone=x=>JSON.parse(JSON.stringify(x));
    const equal=(a,b)=>JSON.stringify(a)===JSON.stringify(b);
    function validateDraft(entry,route,providers){
        if(route===null)return '';
        if(typeof route.enabled!=='boolean'||!Array.isArray(route.chain)||!route.chain.length||route.chain.length>3||new Set(route.chain).size!==route.chain.length)return 'اختر مزودًا أساسيًا وبدائل مختلفة.';
        for(const p of route.chain){
            if(!entry.providers.includes(p))return 'المزود غير مدعوم لهذه الخدمة.';
            if(route.enabled&&!providers[p]?.configured)return 'مفتاح '+providers[p]?.name+' غير موجود. أضفه من التكاملات.';
            if(!/^[A-Za-z0-9._-]{1,120}$/.test(route.models[p]||''))return 'اكتب معرف نموذج صالح بدون رابط أو مسافات.';
        }
        return '';
    }
    function init(P){
        const $=id=>document.getElementById(id),esc=P.esc;
        let data=null,draft={},base={},group='all',saving=false,blocked=false,loading=false;
        const status=$('airStatus'),search=$('airSearch'),cards=$('airCards'),dialog=$('airDialog');
        const dirtyIds=()=>Object.keys(draft).filter(id=>!equal(draft[id],base[id]));
        const providerOptions=(entry,selected,exclude=[],allowNone=false)=>(allowNone?'<option value="">بدون بديل</option>':'')+entry.providers.filter(p=>p===selected||!exclude.includes(p)).map(p=>`<option value="${esc(p)}" ${p===selected?'selected':''}>${esc(data.providers[p].name)}${data.providers[p].configured?'':' — مفتاح ناقص'}</option>`).join('');
        function effective(entry){return draft[entry.id]||(entry.kind==='text'?draft.default:null);}
        function missing(entry){const r=effective(entry);return !!r?.enabled&&r.chain.some(p=>!data.providers[p]?.configured);}
        function visible(){return data.catalog.filter(e=>{
            if(group!=='all'&&e.group!==group)return false;
            if(search.value.trim()&&!`${e.title} ${e.id} ${(draft[e.id]?.chain||[]).join(' ')}`.toLowerCase().includes(search.value.trim().toLowerCase()))return false;
            const r=draft[e.id];
            return status.value==='all'||(status.value==='custom'&&r!==null)||(status.value==='inherited'&&r===null)||(status.value==='disabled'&&effective(e)?.enabled===false)||(status.value==='missing'&&missing(e))||(status.value==='dirty'&&!equal(r,base[e.id]));
        });}
        function seed(entry){const current=effective(entry);if(current)return clone(current);const p=entry.kind==='video'?'veo':entry.kind==='image'?(entry.providers.includes('openai')&&data.providers.openai.configured?'openai':'gemini'):'gemini';return {enabled:true,chain:[p],models:{[p]:data.providers[p].models[entry.kind]}};}
        function summary(r){if(!r)return 'الإعداد السابق للخدمة';return (r.enabled?'':'متوقفة · ')+r.chain.map(p=>`${data.providers[p]?.name||p} / ${r.models[p]}`).join(' ← ');}
        function dirtyUI(){const n=dirtyIds().length;$('airDirty').textContent=n?`${n} خدمة بتغييرات غير محفوظة`:'كل الإعدادات محفوظة';$('airReview').disabled=!n||saving||loading||blocked||!data?.ready;$('airDiscard').disabled=!n||saving||loading;$('airReload').disabled=saving||loading;}
        function render(){
            if(!data)return;
            const entries=visible();$('airCount').textContent=`${entries.length} خدمة ظاهرة من ${data.catalog.length} · إصدار الإعدادات ${data.revision}`;
            const custom=Object.values(draft).filter(Boolean).length,off=data.catalog.filter(e=>effective(e)?.enabled===false).length,missingN=data.catalog.filter(missing).length;
            $('airSummary').innerHTML=[[data.catalog.length,'خدمة يمكن إدارتها'],[custom,'مسار مخصص'],[off,'خدمة متوقفة'],[missingN,'مسار يحتاج مفتاحًا']].map(([n,t])=>`<div class="air-stat"><strong>${n}</strong><span>${esc(t)}</span></div>`).join('');
            cards.innerHTML=entries.length?entries.map(entry=>{
                const route=draft[entry.id],r=route||seed(entry),mode=route?(route.enabled?'custom':'disabled'):'inherit',dirty=!equal(route,base[entry.id]);
                const slots=[r.chain[0],r.chain[1]||'',r.chain[2]||''];
                const form=slots.slice(0,entry.kind==='video'?1:3).map((p,i)=>`<div class="air-order">${i===0?'01 · المزود الأساسي':`0${i+1} · البديل ${i}`}</div><div class="air-route"><label>الشركة<select data-field="provider" data-slot="${i}" ${!route?'disabled':''}>${providerOptions(entry,p,slots.filter((x,j)=>j!==i&&x),i>0)}</select></label><label>معرف النموذج<input data-field="model" data-slot="${i}" value="${esc(p?r.models[p]||'':'')}" maxlength="120" autocomplete="off" spellcheck="false" ${!route||!p?'disabled':''}></label></div>`).join('');
                return `<article class="air-card ${dirty?'dirty':''}" data-id="${esc(entry.id)}"><div class="air-card-header"><span class="air-kind">${entry.kind==='text'?'TEXT INTELLIGENCE':entry.kind==='image'?'IMAGE GENERATION':'VIDEO GENERATION'}</span><h3>${esc(entry.title)}</h3><div class="air-tags"><span class="air-badge ${mode==='disabled'?'off':''}">${mode==='inherit'?'إعداد سابق / عام':mode==='disabled'?'متوقفة':'مسار مخصص'}</span>${missing(entry)?'<span class="air-badge warn">مفتاح ناقص</span>':''}${dirty?'<span class="air-badge warn">غير محفوظ</span>':''}</div></div><div class="air-card-body"><label class="air-mode">طريقة التشغيل<select data-field="mode"><option value="inherit" ${mode==='inherit'?'selected':''}>استخدام الإعداد السابق / العام</option><option value="custom" ${mode==='custom'?'selected':''}>مسار مخصص ومفعّل</option><option value="disabled" ${mode==='disabled'?'selected':''}>إيقاف استدعاءات هذه الخدمة</option></select></label>${form}<div class="air-effective"><b>${route?'المسودة الحالية':'المسار الموروث'}:</b> ${esc(summary(effective(entry)))}${!route&&!effective(entry)?'<br>الحفظ لا يغيّر هذا المسار ما لم تختَر إعدادًا مخصصًا.':''}</div>${entry.kind==='video'?'<p class="air-count">Veo هو مزود الفيديو المدمج حاليًا. لا يوجد بديل فيديو تلقائي.</p>':entry.id==='creative_studio_edit'?'<p class="air-count">تعديل الصورة المرجعية مدمج مع Gemini حاليًا.</p>':''}</div><div class="air-card-footer"><button class="p-btn outline xs" data-action="check" ${!route?'disabled':''}>فحص الإعداد</button><button class="p-btn outline xs" data-action="reset" ${!dirty?'disabled':''}>تراجع</button></div><p class="air-card-message" data-message></p></article>`;
            }).join(''):'<div class="air-empty">لا توجد خدمات تطابق الفلتر. غيّر البحث أو الفئة.</div>';
            cards.setAttribute('aria-busy','false');dirtyUI();
        }
        function setChain(entry,index,p){
            const r=draft[entry.id],chain=r.chain.slice();if(index===0&&!p)return;if(p)chain[index]=p;else chain.splice(index,1);
            const compact=chain.filter(Boolean);r.chain=[...new Set(compact)];const models={};for(const name of r.chain)models[name]=r.models[name]||data.providers[name].models[entry.kind];r.models=models;
        }
        async function load(){
            if(loading||saving)return;loading=true;dirtyUI();
            cards.setAttribute('aria-busy','true');$('airMessage').textContent='جاري قراءة الإعدادات…';
            const res=await P.fetchJSON('/api/admin/ai-routing');if(!res.success){loading=false;dirtyUI();$('airMessage').textContent=res.error||'تعذر التحميل. اضغط تحديث.';cards.setAttribute('aria-busy','false');return;}
            loading=false;data=res.data;draft={};for(const e of data.catalog)draft[e.id]=clone(e.route);base=clone(draft);blocked=false;
            $('airProviders').innerHTML=Object.entries(data.providers).map(([id,p])=>`<div class="air-provider"><i class="air-dot ${p.configured?'ready':''}" aria-hidden="true"></i><strong>${esc(p.name)}</strong><small>${p.configured?'مفتاح موجود':'يحتاج إعداد'}</small></div>`).join('');
            $('airGroups').innerHTML=Object.entries(groups).map(([id,title])=>`<button type="button" data-group="${id}" aria-pressed="${group===id}">${esc(title)}</button>`).join('');
            $('airBulkProvider').innerHTML=['gemini','anthropic','openai','deepseek','kimi'].map(p=>`<option value="${p}">${esc(data.providers[p].name)}</option>`).join('');
            $('airAudit').innerHTML=data.audit.length?data.audit.map(a=>`<div class="air-audit-row"><strong>إصدار ${Number(a.revision)}</strong> · ${esc(a.created_at)} · مسؤول #${Number(a.user_id)}<br>${Object.keys(a.changes).map(id=>esc(data.catalog.find(e=>e.id===id)?.title||id)).join('، ')}</div>`).join(''):'<p class="air-count">لا توجد تغييرات محفوظة من هذه الصفحة حتى الآن.</p>';
            $('airMessage').textContent=data.ready?(data.updated_at?'آخر حفظ: '+data.updated_at:'اختر خدمة وعدّل مسارها، ثم راجع واحفظ.'):'نفّذ ملف SQL الخاص بتوزيع الخدمات أولًا. الحفظ متوقف حتى اكتمال التركيب.';render();
        }
        cards.addEventListener('change',e=>{
            const input=e.target,card=input.closest('[data-id]');if(!card||saving||loading||blocked)return;const entry=data.catalog.find(x=>x.id===card.dataset.id),field=input.dataset.field;
            if(field==='mode'){if(input.value==='inherit')draft[entry.id]=null;else{draft[entry.id]=draft[entry.id]||seed(entry);draft[entry.id].enabled=input.value==='custom';}}
            else if(field==='provider')setChain(entry,Number(input.dataset.slot),input.value);
            else if(field==='model'){const p=draft[entry.id]?.chain[Number(input.dataset.slot)];if(p)draft[entry.id].models[p]=input.value.trim();}
            render();
        });
        cards.addEventListener('input',e=>{const el=e.target;if(el.dataset.field!=='model'||saving||loading||blocked)return;const card=el.closest('[data-id]'),r=draft[card.dataset.id],p=r?.chain[Number(el.dataset.slot)];if(p){r.models[p]=el.value.trim();card.classList.toggle('dirty',!equal(r,base[card.dataset.id]));dirtyUI();}});
        cards.addEventListener('click',async e=>{
            const b=e.target.closest('[data-action]');if(!b||saving||loading||blocked)return;const card=b.closest('[data-id]'),id=card.dataset.id;
            if(b.dataset.action==='reset'){draft[id]=clone(base[id]);render();return;}
            const entry=data.catalog.find(x=>x.id===id),r=draft[id],message=card.querySelector('[data-message]'),error=validateDraft(entry,r,data.providers);if(error){message.textContent=error;return;}
            b.disabled=true;message.textContent='فحص صيغة الإعداد والمفتاح فقط…';try{const res=await P.fetchJSON('/api/admin/ai-routing/check',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({task:id,route:r,csrf_token:data.csrf_token})});message.textContent=res.success?res.data.note:res.error||'تعذر الفحص.';}finally{b.disabled=false;}
        });
        $('airGroups').addEventListener('click',e=>{const b=e.target.closest('[data-group]');if(!b)return;group=b.dataset.group;for(const node of $('airGroups').querySelectorAll('[data-group]'))node.setAttribute('aria-pressed',String(node.dataset.group===group));render();});
        search.addEventListener('input',render);status.addEventListener('change',render);
        $('airBulkApply').onclick=()=>{if(saving||loading||blocked||!data)return;const p=$('airBulkProvider').value;if(!data.providers[p].configured){$('airMessage').textContent='أضف مفتاح المزود في التكاملات أولًا.';return;}const entries=visible().filter(x=>x.kind==='text');for(const entry of entries)draft[entry.id]={enabled:true,chain:[p],models:{[p]:data.providers[p].models.text}};$('airMessage').textContent=`تم تعديل مسودة ${entries.length} خدمة نصية؛ راجع ثم احفظ.`;render();};
        $('airDiscard').onclick=()=>{draft=clone(base);render();$('airMessage').textContent='تم التراجع عن المسودة.';};
        $('airReload').onclick=()=>{if(dirtyIds().length&&!confirm('تحديث البيانات هيلغي التغييرات غير المحفوظة. تكمل؟'))return;load();};
        $('airReview').onclick=()=>{
            const ids=dirtyIds();for(const id of ids){const error=validateDraft(data.catalog.find(e=>e.id===id),draft[id],data.providers);if(error){$('airMessage').textContent=data.catalog.find(e=>e.id===id).title+': '+error;return;}}
            $('airDiff').innerHTML=ids.map(id=>`<div class="air-diff"><strong>${esc(data.catalog.find(e=>e.id===id).title)}</strong><br>الحالي: ${esc(summary(base[id]))}<br>بعد الحفظ: ${esc(summary(draft[id]))}</div>`).join('');$('airDialogMessage').textContent='';dialog.showModal();
        };
        $('airDialogClose').onclick=()=>{if(!saving)dialog.close();};dialog.addEventListener('cancel',e=>{if(saving)e.preventDefault();});
        $('airSave').onclick=async()=>{
            if(saving||loading||blocked)return;saving=true;$('airSave').disabled=true;$('airDialogClose').disabled=true;dirtyUI();const changes={};for(const id of dirtyIds())changes[id]=clone(draft[id]);
            try{const res=await P.fetchJSON('/api/admin/ai-routing',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({revision:data.revision,changes,csrf_token:data.csrf_token})});
                if(!res.success){if(res.network_error||res.retry_safe===false||/جلسة أخرى|تأكيد الحفظ/.test(res.error||''))blocked=true;$('airDialogMessage').textContent=(res.error||'تعذر الحفظ.')+(blocked?' أغلق المراجعة واضغط تحديث قبل حفظ جديد.':'');return;}
                data.revision=res.data.revision;base=clone(draft);dialog.close();$('airMessage').textContent='تم حفظ الإعدادات وتطبيقها على الاستدعاءات الجديدة.';render();
            }finally{saving=false;$('airSave').disabled=blocked;$('airDialogClose').disabled=false;dirtyUI();}
        };
        window.addEventListener('beforeunload',e=>{if(dirtyIds().length){e.preventDefault();e.returnValue='';}});load();
    }
    return {init,validateDraft};
})();
