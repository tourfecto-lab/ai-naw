/* Social autopilot: next month's plan prepared from bookings, season and active trips. Server values use esc()/textContent. */
(() => {
    'use strict';
    const start = async () => {
        const root=document.getElementById('socialAutopilotRoot');if(!root||!window.Panel)return;
        const P=window.Panel,esc=P.esc,ar=document.documentElement.lang.toLowerCase().startsWith('ar'),t=(a,e)=>ar?a:e;
        let sites=[],data=null,busy=false;
        const seasons={peak:t('موسم عالي','High season'),shoulder:t('موسم متوسط','Shoulder season'),low:t('موسم هادي','Low season'),unknown:t('مفيش حجوزات كفاية لتحديد الموسم','Not enough bookings to detect the season')};
        const reasons={rising_demand:t('طلبها بيزيد','Rising demand'),top_seller:t('الأكثر حجزًا','Top seller'),needs_bookings_in_peak:t('محتاجة حجوزات في الموسم','Needs bookings in season'),awareness:t('محتاجة تعريف','Needs awareness'),steady:t('طلب ثابت','Steady')};
        const say=(text,error=false)=>{const b=root.querySelector('#sapMsg');if(!b)return;b.textContent=text;b.hidden=!text;b.className='sap-msg'+(error?' error':'');};
        const req=async(url,body)=>{const r=await P.fetchJSON(url,body?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...body,csrf_token:data?.csrf_token})}:undefined);if(!r?.success)throw new Error(r?.error||t('تعذر الاتصال','Request failed'));return r.data;};
        const wid=()=>Number(root.querySelector('#sapSite')?.value||0);
        function render(){
            const s=data.settings||{},g=data.signals,on=Number(s.enabled)===1;
            const trips=(g?.focus_trips||[]).map(x=>`<li><b>${esc(x.name)}</b> <span class="sap-tag">${esc(reasons[x.reason]||x.reason)}</span> <span class="sap-muted">${esc(String(x.posts))} ${t('بوست','posts')}</span>${x.url?` · <a href="${esc(x.url)}" target="_blank" rel="noopener">${t('صفحة الرحلة','Trip page')}</a>`:''}</li>`).join('');
            const evidence=(g?.evidence||[]).map(e=>`<li>${esc(e)}</li>`).join('');
            const status=s.last_month_key?`<p class="sap-muted">${t('آخر تشغيل','Last run')}: ${esc(s.last_month_key)} — ${esc(s.last_status||'')}${s.last_error?' · '+esc(s.last_error):''}</p>`:'';
            root.querySelector('#sapBody').innerHTML=`
              <div class="sap-grid">
                <div class="sap-box"><h4>${t('إشارات','Signals for')} ${esc(data.next_month)}</h4>
                  ${g?`<p><span class="sap-season sap-${esc(g.season)}">${esc(seasons[g.season]||g.season)}</span> <span class="sap-muted">${t('الثقة','Confidence')}: ${esc(g.confidence)}</span></p>
                  <p>${esc(g.goal||'')}</p>${g.promote_departures_from?`<p class="sap-muted">${t('روّج للرحلات من','Promote departures from')} ${esc(g.promote_departures_from)}</p>`:''}
                  ${trips?`<ul class="sap-trips">${trips}</ul>`:`<p class="sap-muted">${t('مفيش رحلات نشطة. أضف رحلاتك من منشئ المواقع عشان البوستات تروّج لها.','No active trips yet. Add trips in the website builder so posts can promote them.')}</p>`}
                  ${evidence?`<details><summary>${t('البيانات اللي اتبنى عليها القرار','Data behind this decision')}</summary><ul class="sap-ev">${evidence}</ul></details>`:''}`:`<p class="sap-muted">${t('الإشارات غير متاحة حاليًا.','Signals unavailable right now.')}</p>`}
                </div>
                <form class="sap-box sap-form" id="sapForm"><h4>${t('الإعدادات','Settings')}</h4>
                  <label class="sap-switch"><input type="checkbox" name="enabled" ${on?'checked':''}> ${t('جهّز خطة الشهر الجاي أوتوماتيك','Prepare next month\'s plan automatically')}</label>
                  <label>${t('يوم التجهيز في الشهر','Preparation day of month')}<input class="p-select" type="number" name="run_day" min="10" max="26" value="${esc(String(s.run_day||20))}"></label>
                  <label>${t('عدد البوستات لكل صفحة','Posts per page')}<select class="p-select" name="post_count">${[4,8,12,16,20].map(n=>`<option ${Number(s.post_count||12)===n?'selected':''}>${n}</option>`).join('')}</select></label>
                  <label>${t('ميعاد النشر','Publish time')}<input class="p-select" type="time" name="publish_time" value="${esc(s.publish_time||'18:00')}"></label>
                  <label>${t('المنطقة الزمنية','Timezone')}<input class="p-select" name="timezone" value="${esc(s.timezone||Intl.DateTimeFormat().resolvedOptions().timeZone||'UTC')}"></label>
                  <label>${t('لغة البوستات','Post language')}<select class="p-select" name="language"><option value="en" ${s.language!=='ar'?'selected':''}>English</option><option value="ar" ${s.language==='ar'?'selected':''}>العربية</option></select></label>
                  <label>${t('شكل البوستات','Post style')}<select class="p-select" name="mix"><option value="mix">${t('مزيج احترافي','Professional mix')}</option><option value="photo" ${s.mix==='photo'?'selected':''}>${t('صور حقيقية','Real photos')}</option><option value="design" ${s.mix==='design'?'selected':''}>${t('تصميمات بالهوية','Branded designs')}</option></select></label>
                  <label class="sap-switch"><input type="checkbox" name="auto_approve" ${Number(s.auto_approve)===1?'checked':''} ${data.can_auto_approve?'':'disabled'}> ${t('اعتمد وانشر من غير مراجعة','Approve and publish without review')}${data.can_auto_approve?'':` <span class="sap-muted">(${t('متاح في باقات الطيار الآلي','available on Autopilot plans')})</span>`}</label>
                  <p class="sap-muted">${t('صفحات مربوطة','Connected pages')}: ${esc(String(data.pages.length))} · ${t('صور جاهزة','Ready photos')}: ${esc(String(data.photos))}${data.photos?'':' — '+t('ارفع صور رحلاتك الأول','upload trip photos first')}</p>
                  <button class="p-btn primary" type="submit">${t('حفظ','Save')}</button>
                </form>
              </div>${status}`;
            root.querySelector('#sapForm').addEventListener('submit',async e=>{e.preventDefault();if(busy)return;busy=true;const f=new FormData(e.target);
                try{data={...await req('/api/social/autopilot',{website_id:wid(),enabled:f.get('enabled')==='on',auto_approve:f.get('auto_approve')==='on',run_day:Number(f.get('run_day')),post_count:Number(f.get('post_count')),publish_time:f.get('publish_time'),timezone:f.get('timezone'),language:f.get('language'),mix:f.get('mix'),connection_ids:[]}),csrf_token:data.csrf_token};render();say(t('اتحفظ. الخطة هتتجهز في اليوم المحدد من بيانات حجوزاتك ورحلاتك.','Saved. The plan will be prepared on the chosen day from your bookings and trips.'));}
                catch(err){say(err.message,true);}finally{busy=false;}});
        }
        async function load(){say('');root.querySelector('#sapBody').innerHTML=`<p class="sap-muted">${t('جارٍ التحميل…','Loading…')}</p>`;try{data=await req('/api/social/autopilot?website_id='+wid());render();}catch(e){root.querySelector('#sapBody').innerHTML='';say(e.message,true);}}
        root.innerHTML=`<div class="sap-head"><div><h3>${t('الطيار الآلي للسوشيال','Social autopilot')}</h3><p class="sap-muted">${t('بيقرا الموسم من حجوزاتك ويختار الرحلات اللي محتاجة ترويج، ويجهّز بوستات الشهر الجاي بروابط صفحات الرحلات.','Reads your season from bookings, picks the trips that need promotion and prepares next month\'s posts with links to trip pages.')}</p></div><select id="sapSite" class="p-select" aria-label="${t('الموقع','Website')}"></select></div><p id="sapMsg" class="sap-msg" role="status" hidden></p><div id="sapBody"></div>`;
        try{const r=await P.fetchJSON('/api/websites');sites=(r?.data?.websites||r?.data?.items||r?.data||[]);}catch(e){sites=[];}
        if(!Array.isArray(sites)||!sites.length){root.querySelector('#sapBody').innerHTML=`<p class="sap-muted">${t('أضف موقعك الأول.','Add your website first.')}</p>`;return;}
        const sel=root.querySelector('#sapSite');sites.forEach(s=>sel.append(new Option(s.name||s.domain||s.company_name||s.main_url||('#'+s.id),s.id)));sel.addEventListener('change',load);await load();
    };
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
