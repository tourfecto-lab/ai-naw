/* Tourfecto creative workspace. Provider calls remain on the server. */
window.CreativeStudioPro = (() => {
    'use strict';
    const en = () => !document.documentElement.lang.toLowerCase().startsWith('ar');
    const t = (ar, english) => en() ? english : ar;
    const ratioOk = value => ['1:1','4:5','4:3','3:4','16:9','9:16'].includes(value);
    const safePath = path => typeof path === 'string' && /^\/api\/creative-studio\/media\/[1-9][0-9]*\/content(?:\?thumbnail=1)?$/.test(path);
    const templates = [
        ['candid','تجربة سفر طبيعية','Candid travel','صورة فوتوغرافية سياحية طبيعية لمجموعة صغيرة تستكشف سوقًا محليًا مع مرشد، تعبيرات عفوية وخامات حقيقية وضوء نهاري، بدون نص أو شعار','Authentic editorial travel photograph of a small group exploring a local market with a guide, candid expressions, realistic skin and fabric textures, coherent daylight, natural colors, no text or logo'],
        ['offer','صورة لعرض سياحي','Travel offer visual','صورة فوتوغرافية واقعية لشاطئ ومكان استرخاء مع مساحة هادئة في أعلى الصورة لإضافة العرض لاحقًا، بدون أسعار أو كتابة أو شعار','Realistic travel campaign photograph of a peaceful beach and inviting seating, natural light and a clear focal subject, clean negative space at the top for an offer to be added later, no invented prices, no text or logo'],
        ['family','رحلة عائلية','Family experience','صورة سياحية واقعية لعائلة تستمتع بنشاط على الشاطئ، تعبيرات طبيعية ووضعيات آمنة وظلال متناسقة، بدون نص أو شعار','Candid travel photograph of a family enjoying a safe beach activity, natural expressions, realistic hands and proportions, consistent shadows and lifelike colors, no text or logo'],
        ['beach','شاطئ فاخر','Luxury beach','شاطئ هادئ وقت الغروب، مظلات أنيقة، مياه فيروزية ومساحة نظيفة للعناوين','An elegant quiet beach at sunset, turquoise water, refined umbrellas and clean space for a campaign headline'],
        ['hotel','فندق ومنتجع','Hotel & resort','جناح فندقي أنيق بإطلالة بحرية، خامات طبيعية وإضاءة صباحية ناعمة','An elegant hotel suite with sea views, natural materials and soft morning light'],
        ['desert','سفاري صحراوي','Desert safari','مركبة سفاري على كثبان رملية وقت الغروب، آثار حركة خفيفة وتكوين سينمائي واقعي','A safari vehicle on golden desert dunes at sunset, subtle motion and a realistic cinematic composition'],
        ['cruise','رحلة بحرية','Cruise','يخت أنيق في خليج هادئ، أفق مفتوح وتفاصيل واقعية بدون نصوص أو شعارات','An elegant yacht in a calm bay, an open horizon and realistic detail without text or logos'],
        ['city','وجهة سياحية','City destination','مشهد شارع سياحي نابض بالحياة، عمارة مميزة وزائران من الخلف بإضاءة طبيعية','A lively travel destination street, distinctive architecture and two visitors seen from behind in natural light']
    ];
    const failureEn={
        safety:'The provider\'s safety filters blocked this result and nothing was charged. Adjust the brief (avoid real public figures, minors, brands and sensitive content) and try again.',
        quota:'The generation service reached its quota or needs billing enabled; nothing was charged. Contact support with the request number; no need to change your brief.',
        auth:'The generation service needs its key or account permissions reviewed by the admin; nothing was charged. Contact support with the request number.',
        model:'The generation model is currently unavailable and nothing was charged. The admin needs to review the model setting.',
        uncertain:'The result could not be confirmed and nothing was charged. Contact support with the request number before retrying.',
        stalled:'The request stopped progressing for too long, so it was cancelled and the reserved credit was fully returned. You can try again now.',
        video_expired:'The video could not be downloaded before the provider link expired; nothing was charged. Create the request again.',
        quality:'The result did not pass the automatic quality check after a correction attempt; nothing was charged. Try a simpler brief with one main subject.',
        generic:'The generation did not complete and nothing was charged. Contact support with the request number to review the cause.'
    };
    const quoteMatches=(quote,data)=>Boolean(quote&&quote.ok&&typeof quote.hash==='string'&&/^[a-f0-9]{64}$/.test(quote.hash)&&quote.fingerprint===JSON.stringify(data));
    function init(P, userId) {
        const esc = P.esc;
        if(en()) {
            document.querySelector('[data-cs-title]').textContent='From an idea to a visual campaign';
            document.querySelector('[data-cs-intro]').textContent='Create images and short videos for your destination and brand. Direct the lighting and composition, follow generation stages, then download or publish the original.';
            document.querySelectorAll('[data-cs-en]').forEach(el=>{el.textContent=el.dataset.csEn;});
            const labels={'🎯 هوية العلامة والخطة':'Brand & plan','📊 الإحصائيات':'Statistics','📝 سكربتات الفيديو':'Video scripts','🎨 توليد صورة':'Create image','🎥 توليد فيديو قصير':'Create short video','📝 سكربت فيديو':'Video script','🖼️ الصور والفيديوهات':'Images & videos','توليد صورة تسويقية احترافية':'Create a marketing image','توليد فيديو قصير بالذكاء الاصطناعي':'Create an AI short video','النوع':'Format','الأسلوب البصري':'Visual style','الوصف':'Your idea','الوصف (المشهد اللي عايز الفيديو يوريه)':'Describe the scene you want to create','المنصة (بيحدد شكل الفيديو)':'Platform / orientation','المدة':'Duration','✨ تحسين الوصف بالذكاء الاصطناعي':'Enhance prompt with AI','توليد الصورة':'Generate image','توليد الفيديو':'Generate video','وقت التوليد متغير حسب الضغط على الخدمة. تابع المراحل من المكتبة ويمكنك العودة لاحقًا.':'Generation time varies with service demand. Follow the stages in your library and return later.'};
            document.querySelectorAll('#mediaModal h3,#genVideoModal h3,#mediaModal label,#genVideoModal label,#mediaModal button,#genVideoModal button,#genVideoModal p,.p-toolbar button,#csTabs button').forEach(el=>{const text=el.textContent.trim();if(labels[text])el.textContent=labels[text];});
            const optionLabels={social_image:'Social image (1:1)',marketing_image:'Marketing image (4:3)',instagram_post:'Instagram post (1:1)',facebook_cover:'Facebook cover (16:9)',youtube_thumbnail:'YouTube thumbnail (16:9)',story:'Story (9:16)',reels_cover:'Reels cover (9:16)',photo:'Professional photography',cinematic:'Cinematic',product:'Studio product',illustration:'Illustration',minimal:'Minimal',tiktok:'TikTok (portrait)',instagram_reels:'Instagram Reels (portrait)',youtube_shorts:'YouTube Shorts (portrait)',general_landscape:'Landscape (16:9)'};
            document.querySelectorAll('#mediaModal option,#genVideoModal option').forEach(el=>{if(optionLabels[el.value])el.textContent=optionLabels[el.value];else if(['4','6','8'].includes(el.value))el.textContent=el.value+' seconds';});
            document.getElementById('mediaPrompt').placeholder='Describe a subject, destination, mood and campaign idea…';
            document.getElementById('genVideoPrompt').placeholder='Describe one coherent scene and the movement you want…';
        }
        let items = [], nextCursor = null, timer = null, loading = false, stopped = false;
        let currentFilter = 'all', epoch = 0, search='',folder='',folders=[];
        let searchTimer=null;
        async function loadFolders(){try{const r=await P.fetchJSON('/api/creative-studio/folders');if(!r.success)return;folders=r.data.folders;const select=document.getElementById('csFolderFilter');const selected=select.value;select.replaceChildren(new Option(t('كل المجلدات','All folders'),''),new Option(t('بدون مجلد','No folder'),'0'));folders.forEach(f=>select.add(new Option(f.name,String(f.id))));select.value=selected;render();}catch(e){P.toast(t('تعذر تحميل المجلدات','Could not load folders'),'error');}}
        async function organize(id,body){try{const r=await P.fetchJSON('/api/creative-studio/media/'+id+'/organize',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});if(!r.success)throw new Error('organize');references.delete(id);epoch++;loading=false;items=[];nextCursor=null;await load();}catch(e){P.toast(t('لم ينفذ التعديل؛ راجع ملكية المجلد وانتظر التوليد أو النشر الجاري.','Update not applied; check folder ownership and wait for active generation or publishing.'),'error');}}
        const pending = {image:false,video:false};
        const tokens = {image:null,video:null};
        const quotes={image:null,video:null},quoteTimers={},quoteVersions={image:0,video:0},references=new Map();
        function prefixFor(kind){return kind==='image'?'csImage':'csVideo';}
        function syncReferences(){
            items.filter(m=>!m.deleted_at&&m.status==='completed'&&m.type!=='short_video'&&safePath(m.file_path)).forEach(m=>references.set(Number(m.id),m));
            ['image','video'].forEach(kind=>{const select=document.getElementById(prefixFor(kind)+'-reference_id');if(!select)return;const selected=select.value;select.replaceChildren(new Option(t('بدون صورة مرجعية','No reference image'),'0'));references.forEach(m=>select.add(new Option('#'+m.id+' · '+(m.prompt||'').slice(0,50),String(m.id))));if(references.has(Number(selected)))select.value=selected;});
        }
        function invalidateQuote(kind){quotes[kind]=null;quoteVersions[kind]++;const checkbox=document.getElementById(prefixFor(kind)+'Confirm');if(checkbox)checkbox.checked=false;clearTimeout(quoteTimers[kind]);quoteTimers[kind]=setTimeout(()=>refreshQuote(kind),450);}
        async function refreshQuote(kind){
            const prefix=prefixFor(kind),version=++quoteVersions[kind],el=document.getElementById(prefix+'Billing'),d=read(kind);if(!el)return;
            el.classList.remove('cs-cost-alert');el.textContent=t('جارِ حساب التكلفة…','Calculating cost…');
            const topUp=`<a href="/subscription">${t('اشحن المحفظة أو اشترك في باقة','Top up your wallet or choose a plan')}</a>`;
            try{const r=await P.fetchJSON('/api/creative-studio/quote',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({kind,count:d.count||1,options:d.options,duration_seconds:d.duration_seconds||8})});if(version!==quoteVersions[kind])return;
                if(!r.success){quotes[kind]=null;el.classList.add('cs-cost-alert');const known=r.details?.reason;el.innerHTML=esc(known?r.error:t('تعذر حساب التكلفة الآن. اضغط "تحديث التكلفة" أو جرّب تاني بعد لحظة.','Could not calculate the cost right now. Select "Refresh quote" or try again in a moment.'))+(known==='pricing_unavailable'?' '+topUp:'');return;}
                if(!r.data?.ok){quotes[kind]=null;el.classList.add('cs-cost-alert');el.innerHTML=`${t('رصيدك','Your balance is')} <b><bdi dir="ltr">${Number(r.data.balance||0).toFixed(2)} USD</bdi></b> ${t('والتكلفة','and this costs')} <b><bdi dir="ltr">${Number(r.data.total).toFixed(2)} USD</bdi></b> — ${t('ناقصك','you need')} <bdi dir="ltr">${Number(r.data.shortfall||0).toFixed(2)} USD</bdi>. ${topUp}`;return;}
                quotes[kind]={...r.data,fingerprint:JSON.stringify(d)};
                const total=Number(r.data.total);
                el.innerHTML=total>0?`${t('التكلفة','Cost')}: <bdi dir="ltr">${total.toFixed(2)} USD</bdi> ${t('من المحفظة','from wallet')}${r.data.plan_units?` + ${r.data.plan_units} ${t('من الباقة','from plan')}`:''} · ${t('الخصم بعد النجاح بس','charged only on success')}`:`${t('مجانًا من باقتك','Included in your plan')} (${r.data.plan_units})`;
                const confirm=document.getElementById(prefix+'Confirm')?.closest('label');if(confirm)confirm.hidden=!(total>0);
            }catch(error){if(version===quoteVersions[kind]){quotes[kind]=null;el.classList.add('cs-cost-alert');el.textContent=t('تعذر الاتصال لحساب التكلفة. جرّب تاني.','Connection problem while calculating the cost. Try again.');}}
        }
        async function uploadReference(kind,file){
            const prefix=prefixFor(kind),feedback=document.getElementById(prefix+'Error');
            if(!file||file.size>10*1024*1024||!['image/png','image/jpeg','image/webp'].includes(file.type)){feedback.textContent=t('اختر PNG أو JPEG أو WebP حتى 10 ميجابايت.','Choose PNG, JPEG or WebP up to 10 MB.');feedback.style.display='block';return;}
            const form=new FormData();form.append('image',file);const input=document.getElementById(prefix+'Upload');input.disabled=true;
            try{const r=await P.fetchJSON('/api/creative-studio/reference',{method:'POST',body:form});if(!r.success||!r.data?.item)throw new Error('upload');feedback.style.display='none';references.set(Number(r.data.item.id),r.data.item);syncReferences();document.getElementById(prefix+'-reference_id').value=String(r.data.item.id);document.getElementById(prefix+'-reference_id').dispatchEvent(new Event('change',{bubbles:true}));load();}catch(error){feedback.textContent=t('تعذر رفع الصورة. جرّب ملفًا أصغر أو أعد المحاولة.','Upload failed. Try a smaller image or retry.');feedback.style.display='block';}finally{input.disabled=false;input.value='';}
        }

        const draftKey = 'tourfecto-studio-draft-' + userId;
        const stages = {
            queued:t('في انتظار التنفيذ','Queued'), starting:t('بدء التوليد','Starting generation'), generating:t('جارِ التوليد','Generating'),
            processing:t('تجهيز الملف','Preparing the file'), billing:t('تأكيد الاستخدام','Confirming usage'), completed:t('جاهزة','Ready'), failed:t('لم يكتمل التوليد','Generation unsuccessful')
        };
        const grid = document.getElementById('mediaGrid');
        grid.classList.add('cs-library-grid');
        const bar = document.createElement('div'); bar.className = 'cs-library-bar cs-media-toolbar';
        bar.innerHTML = `<label for="csFilter">${t('المكتبة','Library')}</label><select class="p-select" id="csFilter">${[['all','الكل','All'],['images','الصور','Images'],['videos','الفيديوهات','Videos'],['generating','قيد التنفيذ','In progress'],['completed','الجاهزة','Ready'],['failed','غير المكتملة','Unsuccessful'],['favorites','المفضلة','Favorites'],['trash','سلة المهملات','Trash']].map(x=>`<option value="${x[0]}">${t(x[1],x[2])}</option>`).join('')}</select><button class="p-btn outline xs" id="csRefresh">${t('تحديث','Refresh')}</button><span class="cs-library-count" id="csCount" aria-live="polite"></span>`;
        bar.insertAdjacentHTML('beforeend',`<label for="csSearch">${t('بحث','Search')}</label><input class="p-select" id="csSearch" type="search" maxlength="120" placeholder="${t('ابحث في وصف الصور والفيديو','Search image and video briefs')}"><select class="p-select" id="csFolderFilter" aria-label="${t('المجلد','Folder')}"><option value="">${t('كل المجلدات','All folders')}</option></select><button class="p-btn outline xs" id="csCreateFolder">${t('مجلد جديد','New folder')}</button>`);
        grid.before(bar);
        // صور العميل الحقيقية: رفع من الجهاز أو سحب من موقعه - بتتخزن هنا ومش بتتكرر
        const own=document.createElement('div');own.className='cs-library-bar cs-media-toolbar cs-own-photos';
        own.innerHTML=`<label class="p-btn sm">${t('⬆ ارفع صورك','⬆ Upload your photos')}<input type="file" id="csOwnUpload" multiple hidden accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime"></label><button type="button" class="p-btn outline sm" id="csImportSite">${t('🌐 اسحب الصور من موقعي','🌐 Get photos from my website')}</button><span class="p-cell-muted" id="csOwnStatus" style="font-size:13px"></span>`;
        bar.before(own);
        const ownStatus=document.getElementById('csOwnStatus');
        const ownPost=async(url,body)=>{const r=await P.fetchJSON(url,body instanceof FormData?{method:'POST',body}:{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body||{})});return r||{};};
        const ownLearn=async(ids,done='')=>{for(const [n,id] of ids.entries()){ownStatus.textContent=done+t('✨ الذكاء الصناعي بيتعلم منها دلوقتي ','✨ The AI is learning from them now ')+(n+1)+' / '+ids.length+'…';try{await ownPost('/api/social/media/'+id+'/learn');}catch(e){}}};
        const ownBusy=on=>{document.getElementById('csImportSite').disabled=on;document.getElementById('csOwnUpload').disabled=on;};
        document.getElementById('csOwnUpload').addEventListener('change',async e=>{const files=[...e.target.files].slice(0,30);e.target.value='';if(!files.length)return;ownBusy(true);const ids=[];let dup=0,failed=0,lastError='';
            try{for(const [n,f] of files.entries()){ownStatus.textContent=t('بنرفع ','Uploading ')+(n+1)+' / '+files.length+'…';const form=new FormData();form.append('file',f);const r=await ownPost('/api/social/media',form);if(!r.success){failed++;lastError=r.error||'';continue;}if(r.data?.duplicate)dup++;else ids.push(Number(r.data.item.id));}
                if(ids.length)resetLibrary();await ownLearn(ids,t('✅ اترفع ','✅ Uploaded ')+ids.length+t(' — ',' — '));ownStatus.textContent=t('✅ اترفع ','✅ Uploaded ')+ids.length+(ids.length?t(' والذكاء الصناعي اتعلم منها',' and the AI learned from them'):'')+(dup?t(' — و',' — ')+dup+t(' صورة كانت موجودة قبل كده فماتكررتش',' already in your library (not duplicated)'):'')+(failed?t(' — ',' — ')+failed+t(' مااترفعتش ',' failed ')+lastError:'');resetLibrary();}
            finally{ownBusy(false);}});
        document.getElementById('csImportSite').addEventListener('click',async()=>{if(window.TFSitePicker){window.TFSitePicker.open({onDone:(ids,msg)=>{if(ids.length)resetLibrary();if(msg)ownStatus.textContent=msg;}});return;}ownBusy(true);ownStatus.textContent=t('🌐 بندخل على موقعك ونجمع صور الرحلات والفنادق… (حوالي نص دقيقة)','🌐 Visiting your website to collect photos… (about half a minute)');
            try{const r=await ownPost('/api/social/media/import-site',{website_id:0});if(!r.success)throw new Error(r.error||'');const ids=(r.data.added||[]).map(Number);if(ids.length)resetLibrary();await ownLearn(ids,'✅ '+r.data.message+' ');ownStatus.textContent='✅ '+r.data.message+(ids.length?t(' والذكاء الصناعي اتعلم منها كلها.',' The AI learned from all of them.'):'');}
            catch(err){ownStatus.textContent=err.message||t('مقدرناش نسحب الصور دلوقتي، جرّب تاني.','Could not get photos now, try again.');}finally{ownBusy(false);}});
        const resetLibrary=()=>{epoch++;items=[];nextCursor=null;loading=false;load();};
        document.getElementById('csSearch').addEventListener('input',e=>{clearTimeout(searchTimer);searchTimer=setTimeout(()=>{search=e.target.value.trim();resetLibrary();},350);});
        document.getElementById('csFolderFilter').addEventListener('change',e=>{folder=e.target.value;resetLibrary();});
        document.getElementById('csCreateFolder').addEventListener('click',async()=>{const name=prompt(t('اسم المجلد الجديد (حتى 100 حرف)','New folder name (up to 100 characters)'));if(name===null||!name.trim())return;try{const r=await P.fetchJSON('/api/creative-studio/folders',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name:name.trim()})});if(!r.success)throw new Error('folder');await loadFolders();}catch(e){P.toast(t('تعذر إنشاء المجلد؛ راجع الاسم وحد المجلدات.','Could not create folder; check its name and folder limit.'),'error');}});
        const more = document.createElement('div'); more.className='cs-more cs-media-toolbar'; more.hidden=true;
        more.innerHTML=`<button class="p-btn outline" id="csMore">${t('تحميل المزيد','Load more')}</button>`;grid.after(more);
        document.getElementById('csFilter').addEventListener('change', e => { currentFilter=e.target.value; epoch++; items=[];nextCursor=null;loading=false;load(); });
        document.getElementById('csRefresh').addEventListener('click',()=>load());
        document.getElementById('csMore').addEventListener('click',()=>load(true));
        document.querySelectorAll('#csTabs .p-tab').forEach(btn=>btn.addEventListener('click',()=>document.querySelectorAll('.cs-media-toolbar').forEach(el=>el.hidden=btn.dataset.tab!=='media'||(el===more&&!nextCursor))));
        const dialog = document.createElement('dialog');dialog.className='cs-preview-dialog';dialog.setAttribute('aria-label',t('معاينة الصورة','Image preview'));document.body.append(dialog);
        dialog.addEventListener('click',e=>{if(e.target===dialog||e.target.closest('[data-close]'))dialog.close();});
        function summary() { document.getElementById('csCount').textContent=`${items.length} ${t('نتيجة معروضة','results shown')} · ${items.filter(x=>x.status==='generating').length} ${t('قيد التنفيذ','in progress')}`; }
        // شارة فحص الجودة + تنبيه التشابه مع صورة قديمة في المكتبة
        function qaBadge(m) {
            const st=m.settings||{},qa=st.qa,parts=[];
            if(m.status==='completed'&&qa&&qa.checked&&Number.isFinite(Number(qa.score)))parts.push(`<span class="pill ${qa.passed?'green':'orange'}" title="${esc((qa.issues||[]).join(' · '))}">${t('فحص الجودة','Quality check')} ${Number(qa.score)}/10${Number(qa.attempts)>1?' · '+t('اتصحّحت تلقائيًا','auto-corrected'):''}</span>`);
            if(m.status==='completed'&&st.output&&st.output.upscaled)parts.push(`<span class="pill" title="${esc(t('اتكبّرت لمقاس المنصة','Upscaled to platform size'))}"><bdi dir="ltr">${Number(st.output.width)}×${Number(st.output.height)}</bdi></span>`);
            if(m.status==='completed'&&st.similar_to&&Number(st.similar_to.id))parts.push(`<small class="p-cell-muted">${t('⚠ قريبة جدًا من التصميم','⚠ Very close to asset')} #${Number(st.similar_to.id)} — ${t('غيّر الزاوية أو المشهد عشان المحتوى مايتكررش.','change the angle or scene to keep content fresh.')}</small>`);
            const src={article:t('من المقالات','From articles'),website:t('من الموقع','From website'),storyboard_render:t('فيديو مجمّع من سكربت','Storyboard video'),site_import:t('من موقعك','From your site'),upload:t('مرفوعة','Uploaded'),brand_editor:t('تصميم يدوي','Manual layout'),brand_design:t('تصميم بهوية الشركة','Brand design')}[st.source];
            if(src)parts.push(`<span class="pill">${esc(src)}</span>`);
            if(st.storyboard&&st.storyboard.scene)parts.push(`<span class="pill">${t('مشهد','Scene')} ${Number(st.storyboard.scene)}/${Number(st.storyboard.total)}</span>`);
            return parts.length?`<div class="cs-card-actions">${parts.join(' ')}</div>`:'';
        }
        function card(m) {
            const id=Number(m.id);if(!Number.isSafeInteger(id)||id<1)return '';
            const video=m.type==='short_video', ready=m.status==='completed'&&safePath(m.file_path);
            const stage=m.generation_stage==='download_failed'?t('الفيديو جاهز؛ تعذر تنزيله','Video created; download interrupted'):(stages[m.generation_stage]||stages.generating);
            const preview=ready?(video?`<video src="${esc(m.file_path)}" ${safePath(m.thumbnail_path)?`poster="${esc(m.thumbnail_path)}"`:''} controls playsinline preload="none" aria-label="${esc(t('فيديو مولّد','Generated video'))}"></video>`:`<button style="padding:0;border:0;background:none;width:100%;height:100%;cursor:zoom-in" data-preview="${id}" aria-label="${esc(t('عرض الصورة كاملة','View full image'))}"><img src="${esc(safePath(m.thumbnail_path)?m.thumbnail_path:m.file_path)}" loading="lazy" decoding="async" alt="${esc((m.prompt||'').slice(0,180))}"></button>`):`<div class="cs-card-stage">${m.status==='failed'?'⚠': '<span class="cs-spinner" aria-hidden="true"></span>'}<span>${esc(stage)}</span><small>#${id}</small></div>`;
            const meta=video?`${esc(m.aspect_ratio||'')} · ${Number(m.duration_seconds)||8} ${t('ثوانٍ','seconds')}`:`<bdi dir="ltr">${Number(m.width)||'—'}×${Number(m.height)||'—'}</bdi> · <bdi dir="ltr">${esc(m.aspect_ratio||'')}</bdi>`;
            const step=['queued','starting','generating','processing','billing','completed'].indexOf(m.generation_stage);
            return `<article class="cs-card p-card no-pad" data-media-id="${id}"><div class="cs-media-frame">${preview}</div><div class="cs-card-body"><span class="pill ${ready?'green':m.status==='failed'?'red':'orange'}">${esc(stage)}</span>${m.generation_stage==='download_failed'?`<button class="p-btn outline" data-retry-download="${id}">${t('استعادة نفس الفيديو','Recover this video')}</button>`:''}<div class="cs-card-meta">${video?t('فيديو','Video'):t('صورة','Image')} · ${meta} · #${id}</div><p>${esc((m.prompt||'').replace(/^(تصميم بهوية الشركة|من موقعك):\s*/,'').slice(0,180))}</p>${m.status==='generating'?`<div class="cs-steps" aria-label="${esc(stage)}">${Array.from({length:6},(_,i)=>`<span class="${i<=step?'active':''}"></span>`).join('')}</div><small>${t('يمكنك مغادرة الصفحة والعودة لاحقًا.','You can leave this page and return later.')}</small>`:''}${m.status==='failed'?`<p>${esc(en()&&failureEn[m.error_code]?failureEn[m.error_code]:(m.error_message||t('لم تكتمل النتيجة ولم يُخصم أي رصيد.','The result could not be completed and nothing was charged.')))}</p>`:''}${qaBadge(m)}<small>${m.usage&&m.status==='completed'?esc(m.usage.source==='plan'?t('استخدام من الباقة','Plan usage'):m.usage.amount===null?t('استخدام مؤكَّد','Usage recorded'):Number(m.usage.amount).toFixed(2)+' USD · '+(['upload','edit'].includes(m.usage.source)?t('رفع / تصميم يدوي','Upload / layout'):t('المحفظة','Wallet'))):''}</small><div class="cs-card-actions">${ready?`<a class="p-btn outline xs" href="/api/creative-studio/media/${id}/download">${t('تنزيل الأصل','Download original')}</a><button class="p-btn outline xs" data-publish="${id}">${t('نشر / جدولة','Publish / schedule')}</button>`:''}${ready&&video?`<button class="p-btn outline xs" data-video-edit="${id}">${t('قص ونص وشعار','Trim, text & logo')}</button>`:''}<button class="p-btn outline xs" data-reuse="${id}">${t('استخدام الإعدادات','Reuse settings')}</button></div>${ready&&!video?`<div class="cs-card-actions"><button class="p-btn outline xs" data-brand="${id}">${t('النص والشعار','Text & logo')}</button><button class="p-btn outline xs" data-rate="${id}" data-value="1" aria-label="${esc(t('تقييم إيجابي','Positive rating'))}">👍</button><button class="p-btn outline xs" data-rate="${id}" data-value="-1" aria-label="${esc(t('تقييم سلبي','Negative rating'))}">👎</button></div>`:''}${m.deleted_at?`<div class="cs-card-actions"><button class="p-btn outline xs" data-restore-item="${id}">${t('استعادة','Restore')}</button></div>`:`<details class="cs-more-actions"><summary>⋯ ${t('المزيد','More')}</summary>${ready&&!video?`<div class="cs-card-actions"><button class="p-btn outline xs" data-edit="${id}">${t('تعديل بالذكاء الاصطناعي','AI edit')}</button><button class="p-btn outline xs" data-animate="${id}">${t('تحويل لفيديو','Animate')}</button></div>`:''}<div class="cs-card-actions"><button class="p-btn outline xs" data-favorite="${id}">${m.is_favorite?'★':'☆'} ${t('مفضلة','Favorite')}</button><select class="p-select" data-folder-item="${id}" aria-label="${t('نقل إلى مجلد','Move to folder')}"><option value="0">${t('بدون مجلد','No folder')}</option>${folders.map(f=>`<option value="${Number(f.id)}" ${Number(m.folder_id)===Number(f.id)?'selected':''}>${esc(f.name)}</option>`).join('')}</select>${m.status!=='generating'?`<button class="p-btn outline xs" data-trash="${id}">${t('إلى سلة المهملات','Move to trash')}</button>`:''}</div></details>`}</div></article>`;
        }
        function render() {
            if(!items.length){syncReferences();grid.innerHTML=`<div class="p-empty">${t('لا توجد نتائج في هذا الفلتر. ابدأ بفكرة أو اختر قالبًا.','No results in this filter. Start with an idea or choose a template.')}</div>`;summary();return;}
            grid.querySelectorAll('.p-empty').forEach(x=>x.remove());
            const known=new Set(items.map(x=>String(x.id)));
            grid.querySelectorAll('[data-media-id]').forEach(x=>{if(!known.has(x.dataset.mediaId))x.remove();});
            items.forEach(m=>{const signature=JSON.stringify([m,folders]);let node=grid.querySelector(`[data-media-id="${Number(m.id)}"]`);if(node&&node.dataset.signature===signature)return;const fragment=document.createElement('template');fragment.innerHTML=card(m);const fresh=fragment.content.firstElementChild;if(!fresh)return;fresh.dataset.signature=signature;if(node)node.replaceWith(fresh);else grid.append(fresh);fresh.querySelectorAll('video[poster]').forEach(v=>{const src=v.getAttribute('poster');v.removeAttribute('poster');v.setAttribute('poster',src);});});
            items.forEach((m,index)=>{const node=grid.querySelector(`[data-media-id="${Number(m.id)}"]`),at=grid.children[index];if(node&&node!==at)grid.insertBefore(node,at||null);});
            summary();syncReferences();
        }
        async function load(append=false) {
            if(loading||stopped)return;loading=true;clearTimeout(timer);const version=epoch;const wasGenerating=new Set(items.filter(x=>x.status==='generating').map(x=>Number(x.id)));
            try{
                const cursor=append?nextCursor:null;
                const r=await P.fetchJSON(`/api/creative-studio/media?filter=${encodeURIComponent(currentFilter)}&q=${encodeURIComponent(search)}&folder=${encodeURIComponent(folder)}${cursor?'&cursor='+Number(cursor):''}`);
                if(version!==epoch)return;
                if(!r.success||!Array.isArray(r.data?.items))throw new Error('load failed');
                const fetched=r.data.items;
                const olderPending=items.filter(x=>x.status==='generating'&&!fetched.some(y=>y.id===x.id)).slice(0,24);
                if(!append&&olderPending.length){
                    const snapshots=await P.fetchJSON('/api/creative-studio/media?ids='+olderPending.map(x=>Number(x.id)).join(','));
                    if(version!==epoch)return;
                    if(snapshots.success&&Array.isArray(snapshots.data?.items))items=items.map(x=>snapshots.data.items.find(y=>y.id===x.id)||x);
                }
                // Refresh page one while retaining already loaded older pages.
                if(append)items=[...items,...fetched.filter(x=>!items.some(y=>y.id===x.id))];
                else {const firstIds=new Set(fetched.map(x=>x.id));const cutoff=fetched.length?Number(fetched[fetched.length-1].id):Infinity;items=[...fetched,...items.filter(x=>Number(x.id)<cutoff&&!firstIds.has(x.id)&&(!['completed','generating','failed'].includes(currentFilter)||x.status===currentFilter))];}
                if(append||items.length<=24)nextCursor=r.data.next_cursor;
                more.hidden=!nextCursor||document.querySelector('#csTabs .active')?.dataset.tab!=='media';render();
                // طلب خلص (نجاح = خصم، فشل = رجوع الحجز): نحدّث الرصيد اللي فوق
                if(items.some(x=>wasGenerating.has(Number(x.id))&&x.status!=='generating'))window.refreshWalletBadge?.();
                if(items.some(x=>x.status==='generating'))timer=setTimeout(()=>load(),8000);
            }catch(e){if(version===epoch){document.getElementById('csCount').textContent=t('تعذر التحديث؛ نتائجك الحالية محفوظة.','Refresh failed; your current results are retained.');if(!items.length)grid.innerHTML=`<div class="p-empty">${t('تعذر تحميل المكتبة. اضغط تحديث للمحاولة.','Could not load the library. Select Refresh to retry.')}</div>`;timer=setTimeout(()=>load(),20000);}}
            finally{if(version===epoch)loading=false;}
        }
        grid.addEventListener('click',async e=>{
            const recovery=e.target.closest('[data-retry-download]');if(recovery){recovery.disabled=true;try{const r=await P.fetchJSON('/api/creative-studio/media/'+Number(recovery.dataset.retryDownload)+'/retry-download',{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'});if(!r.success)throw new Error(r.error);await load();}catch(error){P.toast(error.message,'error');}finally{recovery.disabled=false;}return;}
            const button=e.target.closest('button');if(!button)return;
            const id=Number(button.dataset.preview||button.dataset.publish||button.dataset.reuse||button.dataset.rate||button.dataset.edit||button.dataset.animate||button.dataset.brand||button.dataset.favorite||button.dataset.trash||button.dataset.restoreItem||button.dataset.videoEdit);const m=items.find(x=>Number(x.id)===id);if(!m)return;
            if(button.dataset.preview&&safePath(m.file_path)){dialog.innerHTML=`<button class="p-btn outline xs" data-close>${t('إغلاق','Close')}</button><img src="${esc(m.file_path)}" alt="${esc(m.prompt||'')}"><p>${esc(m.prompt||'')}</p><a class="p-btn" href="/api/creative-studio/media/${id}/download">${t('تنزيل الأصل','Download original')}</a>`;dialog.showModal();}
            if(button.dataset.publish)window.openPublish?.(id,m.prompt||'');
            if(button.dataset.reuse){const kind=m.type==='short_video'?'video':'image';fill(kind,m);tokens[kind]=null;document.getElementById(kind==='image'?'mediaModal':'genVideoModal').classList.add('open');}
            if(button.dataset.edit||button.dataset.animate){const kind=button.dataset.animate?'video':'image';references.set(id,m);syncReferences();fill(kind,m);if(kind==='video'){document.getElementById('genVideoDuration').value='8';const landscape=Number(m.width)>=Number(m.height);document.getElementById('genVideoPlatform').value=landscape?'general_landscape':'instagram_reels';document.getElementById('csVideo-aspect_ratio').value=landscape?'16:9':'9:16';}document.getElementById(prefixFor(kind)+'-reference_id').value=String(id);updatePreview(kind);invalidateQuote(kind);document.getElementById(kind==='image'?'mediaModal':'genVideoModal').classList.add('open');}
            if(button.dataset.favorite)organize(id,{is_favorite:m.is_favorite?0:1});
            if(button.dataset.trash&&confirm(t('نقل الملف إلى سلة المهملات؟ يمكنك استعادته لاحقًا.','Move this asset to trash? You can restore it later.')))organize(id,{action:'trash'});
            if(button.dataset.restoreItem)organize(id,{action:'restore'});
            if(button.dataset.videoEdit)window.CreativeVideoEditor?.open(P,m,()=>load());
            if(button.dataset.brand)window.CreativeBrandEditor?.open(P,m,()=>load());
            if(button.dataset.rate)window.rateMedia?.(id,Number(button.dataset.value));
        });
        grid.addEventListener('change',e=>{if(e.target.dataset.folderItem)organize(Number(e.target.dataset.folderItem),{folder_id:Number(e.target.value)});});
        grid.addEventListener('error',e=>{
            if(!e.target.matches?.('img,video'))return;
            const frame=e.target.closest('.cs-media-frame');
            if(frame){const message=document.createElement('div');message.className='cs-card-stage';message.textContent=t('تعذر عرض الملف. جرّب تنزيل الأصل أو تواصل مع الدعم برقم الطلب.','Preview unavailable. Try downloading the original or contact support with the request number.');frame.replaceChildren(message);}
        },true);
        dialog.addEventListener('error',e=>{if(e.target.matches?.('img')){const message=document.createElement('p');message.textContent=t('تعذر تحميل المعاينة.','The preview could not be loaded.');e.target.replaceWith(message);}},true);
        function field(prefix,key,label,english,values=null) {
            const id=prefix+'-'+key;
            return `<label class="cs-field" for="${id}">${t(label,english)}${values?`<select class="p-select" id="${id}">${values.map(x=>`<option value="${x[0]}">${t(x[1],x[2])}</option>`).join('')}</select>`:`<input class="p-select" id="${id}" maxlength="${key==='avoid'?400:key==='subject'?300:200}" type="text">`}</label>`;
        }
        function setup(kind) {
            const image=kind==='image',prefix=image?'csImage':'csVideo',modal=document.getElementById(image?'mediaModal':'genVideoModal'),body=modal.querySelector('.p-modal-body');
            modal.setAttribute('role','dialog');modal.setAttribute('aria-modal','true');
            modal.querySelector('.p-modal-close').setAttribute('aria-label',t('إغلاق','Close'));
            let opener=null;
            const title=modal.querySelector('h3');title.id=prefix+'Title';modal.setAttribute('aria-labelledby',title.id);
            const prompt=document.getElementById(image?'mediaPrompt':'genVideoPrompt');prompt.maxLength=2000;prompt.setAttribute('aria-label',t('وصف الفكرة','Describe your idea'));
            const presets=document.createElement('div');presets.className='cs-presets';presets.innerHTML=templates.map(x=>`<button type="button" class="p-btn outline xs" data-template="${x[0]}">${t(x[1],x[2])}</button>`).join('');body.prepend(presets);
            presets.addEventListener('click',e=>{const key=e.target.closest('[data-template]')?.dataset.template;const x=templates.find(x=>x[0]===key);if(x){if(image&&['candid','offer','family'].includes(key)){document.getElementById('mediaStyle').value='photo';}prompt.value=t(x[3],x[4]);prompt.dispatchEvent(new Event('input'));prompt.focus();}});
            const meter=document.createElement('div');meter.className='cs-prompt-meter';meter.id=prefix+'Meter';prompt.after(meter);prompt.addEventListener('input',()=>{meter.textContent=`${Array.from(prompt.value).length}/2000`;tokens[kind]=null;});prompt.dispatchEvent(new Event('input'));
            const styles=document.getElementById(image?'mediaStyle':'genVideoStyle');if(!styles.querySelector('[value="luxury"]'))styles.add(new Option(t('حملة سياحية فاخرة','Luxury travel campaign'),'luxury'));
            const panel=document.createElement('details');panel.className='cs-advanced';
            const ratios=(image?['','1:1','4:5','4:3','3:4','16:9','9:16']:['','9:16','16:9']).map(v=>[v,v||'تلقائي حسب النوع',v||'Auto for format']);
            panel.innerHTML=`<summary>${t('إخراج فني وإعدادات متقدمة','Art direction & advanced settings')}</summary><div class="cs-form-grid">${field(prefix,'aspect_ratio','نسبة الأبعاد','Aspect ratio',ratios)}${field(prefix,'lighting','الإضاءة','Lighting',[['auto','تلقائي حسب الوصف','Auto from your brief'],['natural','طبيعية','Natural'],['golden_hour','الساعة الذهبية','Golden hour'],['studio','استوديو','Studio'],['night','ليلية','Night']])}${field(prefix,'subject','العنصر الرئيسي','Main subject')}${field(prefix,'location','المكان / الوجهة','Setting / destination')}${field(prefix,'audience','الجمهور المستهدف','Target audience')}${field(prefix,'composition','التكوين','Composition',[['auto','تلقائي حسب الوصف','Auto from your brief'],['balanced','متوازن','Balanced'],['negative_space','مساحة للعناوين لاحقًا','Space for a later headline'],['closeup','لقطة قريبة','Close-up'],['wide','لقطة واسعة','Wide']])}${image?'':field(prefix,'camera','حركة الكاميرا','Camera movement',[['auto','تلقائي حسب الوصف','Auto from your brief'],['steady','ثابتة','Steady'],['dolly','حركة بطيئة للأمام','Slow dolly'],['orbit','دوران هادئ','Gentle orbit'],['aerial','لقطة جوية','Aerial']])+field(prefix,'audio','التوجيه الصوتي','Audio direction',[['auto','تلقائي حسب الوصف','Auto from your brief'],['ambient','أصوات المكان دون حوار','Ambient, no dialogue'],['music','موسيقى دون حوار','Music, no dialogue'],['silent','طلب بدون صوت','Request no audio']])}${field(prefix,'avoid','عناصر تريد تجنبها','Elements to avoid')}</div>`;body.append(panel);
            const source=document.createElement('div');source.className='cs-form-grid';source.innerHTML=field(prefix,'reference_id','الصورة المرجعية من مكتبتك','Reference from your library',[['0','بدون صورة مرجعية','No reference image']])+`<div class="cs-field">${t('رفع مرجع جديد (حتى 10 ميجابايت)','Upload reference (up to 10 MB)')}<label class="p-btn outline xs cs-file-btn" for="${prefix}Upload">⬆ ${t('اختار صورة من جهازك','Choose an image')}<input id="${prefix}Upload" type="file" accept="image/png,image/jpeg,image/webp"></label></div>`+(image?field(prefix,'count','عدد النسخ بنفس الإعدادات والهوية','Variants with the same brief and brand',[['1','نسخة واحدة','One variant'],['2','نسختان','Two variants']]):field(prefix,'resolution','جودة الفيديو','Video resolution',[['720p','720p — أساسية','720p — standard'],['1080p','1080p — سعر مستقل','1080p — separate price'],['4k','4K — سعر مستقل','4K — separate price']])+field(prefix,'scene','قالب المشهد','Scene template',[['custom','حسب وصفك','Your brief'],['arrival','وصول وترحيب','Welcome arrival'],['room','استعراض غرفة','Room reveal'],['experience','تجربة سياحية','Travel experience'],['destination','استعراض وجهة','Destination reveal']]));body.append(source);
            document.getElementById(prefix+'Upload').addEventListener('change',e=>uploadReference(kind,e.target.files[0]));
            const preview=document.createElement('div');preview.className='cs-brief-preview';preview.innerHTML=`<div class="cs-ratio" id="${prefix}Ratio" aria-hidden="true"></div><span id="${prefix}Summary"></span>`;body.append(preview);
            const note=document.createElement('p');note.className='cs-brand-note';note.textContent=t('ألوان الهوية المحددة توجه التوليد. أضف النص والشعار بدقة من محرر الصور بعد اكتمال النتيجة.','Your selected brand supplies color direction. Add exact text and logo placement in the image editor after generation.');body.append(note);
            if(image){const ratioNote=document.createElement('p');ratioNote.className='cs-brand-note';ratioNote.textContent=t('اختَر النسبة من الإعدادات؛ كتابتها في الوصف لا تغيّر المقاس. قد تُقص أطراف الصورة لتطابق النسبة. التصوير الواقعي لا يضمن دقة معالم الوجهة؛ استخدم صورة مرجعية عند الحاجة.','Select the ratio in settings; writing it in the prompt does not change the output size. Edges may be cropped to match. For precise destination landmarks, supply a reference image.');body.append(ratioNote);}
            const billing=document.createElement('p');billing.className='cs-screen-note';billing.id=prefix+'Billing';billing.setAttribute('aria-live','polite');billing.textContent=t('الحساب حسب الباقة والرصيد وقت التنفيذ؛ نتيجة واحدة لكل طلب.','Usage depends on your quota and balance at execution; one result per request.');body.append(billing);
            const confirm=document.createElement('label');confirm.className='cs-cost-confirm';confirm.innerHTML=`<input type="checkbox" id="${prefix}Confirm"> ${t('أوافق على تكلفة الإعدادات المعروضة لكل نتيجة ناجحة.','I accept the displayed cost for each successful result.')}`;body.append(confirm);
            // التكلفة والموافقة جنب زرار التوليد مباشرة (كانوا في آخر النافذة بعيد عن الزرار)
            const costBar=document.createElement('div');costBar.className='cs-cost-bar';costBar.append(billing,confirm);modal.querySelector('.p-modal-foot').prepend(costBar);
            const refresh=document.createElement('button');refresh.type='button';refresh.className='p-btn outline xs';refresh.textContent=t('تحديث التكلفة','Refresh quote');refresh.addEventListener('click',()=>{document.getElementById(prefix+'Confirm').checked=false;refreshQuote(kind);});body.append(refresh);
            const subscription=document.createElement('a');subscription.href='/subscription';subscription.className='p-btn outline xs';subscription.textContent=t('الباقة والرصيد','Plan & credits');body.append(subscription);
            const tools=document.createElement('div');tools.className='cs-modal-tools';tools.innerHTML=`<button type="button" class="p-btn outline xs" data-save>${t('حفظ مسودة','Save draft')}</button><button type="button" class="p-btn outline xs" data-restore>${t('استعادة المسودة','Restore draft')}</button><button type="button" class="p-btn outline xs" data-clear>${t('مسح المسودة','Clear draft')}</button>`;body.append(tools);
            tools.addEventListener('click',e=>{try{if(e.target.closest('[data-save]')){const all=JSON.parse(localStorage.getItem(draftKey)||'{}');all[kind]={...read(kind),saved_at:Date.now()};localStorage.setItem(draftKey,JSON.stringify(all));P.toast(t('حُفظت على هذا الجهاز','Saved on this device'),'success');}if(e.target.closest('[data-restore]')){const draft=JSON.parse(localStorage.getItem(draftKey)||'{}')[kind];if(draft)fill(kind,{prompt:draft.prompt,type:draft.type,duration_seconds:draft.duration_seconds,settings:draft});}if(e.target.closest('[data-clear]')){localStorage.removeItem(draftKey);P.toast(t('تم مسح المسودة','Draft cleared'),'success');}}catch(error){P.toast(t('تعذر الوصول للمسودة على هذا الجهاز','Draft storage is unavailable'),'error');}});
            const feedback=document.createElement('div');feedback.id=prefix+'Error';feedback.className='cs-form-feedback';feedback.setAttribute('role','alert');body.append(feedback);
            // حدث "change" بيتبعت لما العميل يسيب مربع الوصف ويضغط "توليد"؛ لو الإعدادات ماتغيرتش فعلًا مانلغيش التكلفة المحسوبة
            // (كان أول ضغطة على "توليد" بتفشل دايمًا وتظهر "جارِ حساب التكلفة")
            const changed=e=>{if(e.target.id===prefix+'Confirm'||e.target.id===prefix+'Upload')return;updatePreview(kind);const fp=JSON.stringify(read(kind));if(quotes[kind]&&quotes[kind].fingerprint===fp)return;tokens[kind]=null;invalidateQuote(kind);};modal.addEventListener('input',changed);modal.addEventListener('change',changed);
            modal.addEventListener('keydown',e=>{if(e.key==='Escape')modal.classList.remove('open');if(e.key==='Tab'){const list=Array.from(modal.querySelectorAll('button,input,select,textarea,summary')).filter(x=>!x.disabled&&x.getClientRects().length);const first=list[0],last=list[list.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();last?.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first?.focus();}}});
            new MutationObserver(()=>{if(modal.classList.contains('open')){if(!modal.contains(document.activeElement))opener=document.activeElement;updatePreview(kind);syncReferences();if(!tokens[kind])invalidateQuote(kind);prompt.focus();}else opener?.focus();}).observe(modal,{attributes:true,attributeFilter:['class']});
            updatePreview(kind);
        }
        function read(kind) {
            const image=kind==='image',prefix=image?'csImage':'csVideo';const options={};
            ['aspect_ratio','lighting','subject','location','audience','composition','avoid','camera','audio','reference_id','resolution','scene'].forEach(key=>{const el=document.getElementById(prefix+'-'+key);if(el)options[key]=el.value;});
            const data={prompt:document.getElementById(image?'mediaPrompt':'genVideoPrompt').value.trim(),style:document.getElementById(image?'mediaStyle':'genVideoStyle').value,options};
            options.reference_id=Number(options.reference_id||0);if(image){data.type=document.getElementById('mediaType').value;data.count=Number(document.getElementById(prefix+'-count').value);}else{data.platform=document.getElementById('genVideoPlatform').value;data.duration_seconds=Number(document.getElementById('genVideoDuration').value);}
            return data;
        }
        function fill(kind,m) {
            const image=kind==='image',prefix=image?'csImage':'csVideo',settings=m.settings||{};
            document.getElementById(image?'mediaPrompt':'genVideoPrompt').value=typeof m.prompt==='string'?m.prompt.slice(0,2000):'';
            const set=(id,value)=>{const el=document.getElementById(id);if(el&&typeof value!=='undefined'&&(!el.options||Array.from(el.options).some(x=>x.value===String(value))))el.value=value;};
            set(image?'mediaType':'genVideoPlatform',image?m.type:settings.platform);set(image?'mediaStyle':'genVideoStyle',settings.style);if(!image)set('genVideoDuration',m.duration_seconds);
            ['aspect_ratio','lighting','subject','location','audience','composition','avoid','camera','audio','reference_id','resolution','scene'].forEach(k=>{const value=settings.options?.[k]??(k==='aspect_ratio'?m.aspect_ratio:undefined);set(prefix+'-'+k,value);});
            document.getElementById(image?'mediaPrompt':'genVideoPrompt').dispatchEvent(new Event('input'));updatePreview(kind);invalidateQuote(kind);
        }
        function updatePreview(kind) {
            const d=read(kind),prefix=kind==='image'?'csImage':'csVideo';
            const defaults={social_image:'1:1',marketing_image:'4:3',instagram_post:'1:1',facebook_cover:'16:9',youtube_thumbnail:'16:9',story:'9:16',reels_cover:'9:16'};
            const ratio=d.options.aspect_ratio||(kind==='image'?defaults[d.type]:(d.platform==='general_landscape'?'16:9':'9:16'));
            document.getElementById(prefix+'Ratio').style.aspectRatio=ratioOk(ratio)?ratio.replace(':','/'):'1';
            document.getElementById(prefix+'Summary').textContent=kind==='image'?`${ratio} · ${d.count} ${t('نتيجة، مع بقاء الهوية والإعدادات ثابتة','results with the same brief and brand')}`:`${ratio} · ${d.duration_seconds} s · ${d.options.resolution} · ${t('صوت وإخراج حسب استجابة النموذج','Audio and direction depend on the model')}`;
        }
        async function request(kind,brandId=0) {
            if(pending[kind])return;const prefix=kind==='image'?'csImage':'csVideo',d=read(kind),feedback=document.getElementById(prefix+'Error');
            const message=text=>{feedback.textContent=text;feedback.style.display='block';};feedback.style.display='none';
            if(Array.from(d.prompt).length<8)return message(t('اكتب وصفًا واضحًا من 8 أحرف على الأقل.','Enter a clear prompt of at least 8 characters.'));
            // التكلفة لسه بتتحسب (العميل ضغط بسرعة): نستناها بدل ما نطلع رسالة
            // ننتظر حساب التكلفة الحالي بدل ما نفشل لو العميل ضغط بسرعة (على الاستضافة الحساب بياخد ثانية أو اتنين)
            if(!quoteMatches(quotes[kind],d)){clearTimeout(quoteTimers[kind]);await refreshQuote(kind);}
            for(let i=0;i<2&&!quoteMatches(quotes[kind],d)&&!document.getElementById(prefix+'Billing')?.classList.contains('cs-cost-alert');i++){clearTimeout(quoteTimers[kind]);await refreshQuote(kind);}
            const quote=quotes[kind];if(!quoteMatches(quote,d)){const why=document.getElementById(prefix+'Billing');why?.scrollIntoView({block:'nearest'});return message(why?.textContent||t('تعذر حساب التكلفة.','Could not calculate the cost.'));}
            // الموافقة مطلوبة بس لو فيه خصم من المحفظة - من الباقة مفيش داعي
            if(Number(quote.total)>0&&!document.getElementById(prefix+'Confirm').checked){document.getElementById(prefix+'Confirm').focus();return message(t('الطلب ده هيتخصم من محفظتك ('+Number(quote.total).toFixed(2)+' USD). علّم على الموافقة وكمّل.','This request uses your wallet ('+Number(quote.total).toFixed(2)+' USD). Tick the approval and continue.'));}
            const btn=document.getElementById(kind==='image'?'genImageBtn':'genVideoBtn');pending[kind]=true;btn.disabled=true;const label=btn.textContent;btn.textContent=t('جارِ إرسال الطلب…','Submitting…');
            const fingerprint=JSON.stringify({...d,brand_id:brandId});
            try{
                if(!tokens[kind]||tokens[kind].fingerprint!==fingerprint){const bytes=new Uint8Array(18);crypto.getRandomValues(bytes);tokens[kind]={fingerprint,key:Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('')};}
                const r=await P.fetchJSON(kind==='image'?'/api/creative-studio/media':'/api/creative-studio/video',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...d,brand_id:brandId,request_key:tokens[kind].key,quote_hash:quote.hash})});
                if(!r.success){message(t('لم يُقبل الطلب. راجع الرصيد والإعدادات وانتظر إذا لديك طلبان جاريان.','Request not accepted. Check credits and settings, and wait if two jobs are already in progress.'));return;}
                tokens[kind]=null;document.getElementById(kind==='image'?'mediaModal':'genVideoModal').classList.remove('open');P.toast(t('تم استلام طلبك؛ تابع المراحل في المكتبة.','Request received; follow its stages in the library.'),'success');
                currentFilter='all';document.getElementById('csFilter').value='all';epoch++;loading=false;items=[];nextCursor=null;load();window.refreshPlanUsage?.();window.refreshWalletBadge?.();
            }catch(e){message(t('انقطع الاتصال. أعد الإرسال بنفس الإعدادات للتحقق من الطلب دون إنشاء نسخة مكررة.','Connection interrupted. Submit the same settings again to recover the request without a duplicate.'));}
            finally{pending[kind]=false;btn.disabled=false;btn.textContent=label;}
        }
        setup('image');setup('video');loadFolders();
        document.addEventListener('visibilitychange',()=>{clearTimeout(timer);stopped=document.hidden;if(!stopped)load();});
        window.addEventListener('pagehide',()=>{stopped=true;clearTimeout(timer);});
        // فتح نافذة التوليد بإعدادات جاهزة (من خطة المحتوى مثلًا) — التكلفة والموافقة بتمشي بنفس خطوات أي طلب
        function prefill(kind,m){fill(kind,m);tokens[kind]=null;document.getElementById(kind==='image'?'mediaModal':'genVideoModal').classList.add('open');}
        return {load,request,prefill};
    }
    return {init,safePath,ratioOk,quoteMatches};
})();
