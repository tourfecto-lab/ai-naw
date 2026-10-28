(() => {
 'use strict';
 const start=()=>{
  const root=document.getElementById('seoExpertRoot');
  const core=document.getElementById('seWorkspace');
  if(!root||!core||document.getElementById('seNavigation'))return;
  const ar=document.documentElement.lang.toLowerCase().startsWith('ar');
  const t=(a,e)=>ar?a:e;
  const pages=[
   ['overview',t('نظرة عامة','Overview'),t('حالة الخدمة والخطوة التالية لموقعك.','Your service state and next step.')],
   ['scan',t('الفحص','Research'),t('ابدأ الفحص، ثم انتقل للخطة لمراجعة المقترحات.','Start an inspection, then review the proposals in Plan.')],
   ['plan',t('الخطة','Plan'),t('اختر دورة، راجع المهام والحقائق، واعتمد التعديلات المناسبة.','Choose a cycle, review tasks and facts, then approve appropriate changes.')],
   ['execute',t('محرر WordPress','WordPress editor'),t('أداة إضافية لتحرير صفحات WordPress المتصلة. تنفيذ مهام خطة SEO يبدأ من اعتمادها داخل الخطة.','Optional editing for connected WordPress pages. SEO plan execution starts when you approve tasks in Plan.')],
   ['results',t('النتائج','Results'),t('راجع نتائج الدورات، وقارن الأداء بالبيانات المتاحة.','Review cycle outcomes and compare performance using available data.')],
   ['settings',t('الإعدادات','Settings'),t('إدارة الربط، وبيانات الشركة، وحدود العمل وتكلفته.','Manage connections, company facts, work limits and cost.')],
   ['tours',t('رحلات WordPress','WordPress tours'),t('أداة إضافية لمسودات رحلات Traveler على WordPress؛ راجع بيانات الحجز قبل نشرها.','Optional Traveler tour drafts on WordPress; review booking facts before publishing.')]
  ];
  const allowed=new Set(pages.map(p=>p[0]));
  const selected=()=>Number(document.getElementById('seWebsite')?.value||0);
  const pageFromURL=()=>{const p=new URL(location.href).searchParams.get('page');return allowed.has(p)?p:'overview';};
  let active=pageFromURL(),state=null,frame=0;
  const make=(tag,cls,text)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(text)n.textContent=text;return n;};
  const urlFor=page=>{const u=new URL(location.href);u.searchParams.set('page',page);const id=selected();if(id)u.searchParams.set('website_id',String(id));else u.searchParams.delete('website_id');u.hash='';return u.pathname+u.search;};
  const link=(page,text)=>{const a=make('a','p-btn outline',text);a.dataset.sePage=page;a.href=urlFor(page);return a;};
  const navigation=make('nav','se-navigation');navigation.id='seNavigation';navigation.setAttribute('aria-label',t('صفحات خدمة SEO وإدارة الرحلات','SEO service pages and tour management'));
  const navMain=make('div','se-navigation-main');
  for(const [key,label] of pages){const a=link(key,label);a.className='se-navigation-link';navMain.append(a);}
  navigation.append(navMain);
  // تبويبات مركز السيو فوق الصفحة بتغني عن تبويبات الخبير الداخلية (كانوا بيتكرروا)
  if(document.querySelector('.seo-hub'))navMain.hidden=true;
  const head=root.querySelector('.se-head');if(head)head.after(navigation);else root.prepend(navigation);
  // عنوان واحد بس: عنوان الصفحة فوق كفاية، فبنخفي هيدر الخدمة المكرر ونحط زرار التحديث جنب التبويبات
  if(head){const refresh=document.getElementById('seRefresh');if(refresh){refresh.classList.add('se-navigation-refresh');navigation.append(refresh);}head.hidden=true;}
  const pageHead=make('div','se-page-heading');
  const heading=make('h2');heading.id='sePageHeading';heading.tabIndex=-1;
  const description=make('p');pageHead.append(heading,description);navigation.after(pageHead);
  const corePages=make('div');corePages.id='seCorePages';core.append(corePages);
  // Keep add-on modules outside seWorkspace: its legacy busy gate must not disable their independent controls.
  const addonPages=make('div');addonPages.id='seAddonPages';core.after(addonPages);
  const panels=new Map();
  for(const [key,label] of pages){const pane=make('section','se-workspace-page');pane.id='sePage-'+key;pane.dataset.sePanel=key;pane.setAttribute('aria-label',label);panels.set(key,pane);(key==='execute'||key==='results'||key==='tours'?addonPages:corePages).append(pane);}
  const move=(id,page)=>{const n=document.getElementById(id),dest=panels.get(page);if(n&&!dest.contains(n))dest.append(n);};
  move('seWorker','overview');move('seScope','settings');
  for(const id of ['seManageConnections','seConnectionGrid','seProbe','seProbeResults','seConfig'])move(id,'settings');
  move('seQuote','scan');move('seResearchBudget','scan');
  const confirm=document.getElementById('seGenerateConfirm')?.closest('label');if(confirm)panels.get('scan').append(confirm);
  const scanActions=make('div','se-actions');panels.get('scan').append(scanActions);
  const settingsActions=make('div','se-actions');panels.get('settings').append(settingsActions);
  for(const id of ['seStart','sePause','seResume']){const n=document.getElementById(id);if(n)(id==='seStart'?scanActions:settingsActions).append(n);}
  for(const id of ['seRuns','seDetail'])move(id,'plan');
  // Remove only the now-empty former action wrapper; never replace a form or attached handlers.
  for(const n of [...core.children])if(n.classList.contains('se-actions')&&!n.children.length)n.remove();
  const manage=link('settings',t('إعدادات الربط','Connection settings'));manage.className='se-connection-link';
  document.getElementById('seConnectionSummary')?.after(manage);
  const overviewCard=make('article','se-card se-overview-card');
  const nextHeading=make('h3','',t('الخطوة التالية','Next step'));const nextText=make('p');nextText.id='seNextStep';
  const nextActions=make('div','se-actions');overviewCard.append(nextHeading,nextText,nextActions);panels.get('overview').prepend(overviewCard);
  const journey=make('article','se-card se-journey');journey.id='seJourney';
  journey.append(make('h3','',t('خطواتك مع المنصة','Your steps with the platform')));
  const journeySteps=make('ol');journeySteps.id='seJourneySteps';journey.append(journeySteps);
  const responsibility=make('p','se-note',t('دورك: تأكيد معلومات نشاطك، ربط حساباتك، ومراجعة التغييرات قبل اعتمادها. المنصة تجمع الصفحات والبيانات المتاحة، تعد الخطة، تنفذ المعتمد وتتحقق من ظهوره، وتجدول قياس الأثر. الحقائق الناقصة والإجراءات خارج صلاحيات الربط تظهر لك كمهام محددة.','You confirm business facts, connect accounts and approve reviewed changes. The platform collects available pages and data, prepares the plan, executes approved work, verifies publication and schedules measurement. Missing facts and actions beyond connected permissions appear as explicit tasks.'));
  journey.append(responsibility);overviewCard.after(journey);
  let latestTasks=null;
  const renderJourney=()=>{
   journeySteps.replaceChildren();
   const matching=state&&state.websiteId===selected(),connected=matching&&state.connections?.connector==='publicly_observed'&&state.connections?.gsc==='measured'&&state.connections?.analytics==='measured',working=matching&&state.runs.some(r=>['queued','scanning','scanned','planning','executing','monitoring'].includes(r.status));
   const steps=[
    [t('أكد بيانات شركتك وحدود التكلفة','Confirm facts and cost limits'),matching&&state.configured?'done':'action',link('settings',t('بيانات النشاط والتكلفة','Business facts and cost'))],
    [t('اربط موقعك ومصادر القياس','Connect your site and measurement sources'),connected?'done':'action',link('settings',t('مراجعة الربط','Review connections'))],
    [t('راجع الخطة واعتمد التغييرات المناسبة','Review the plan and approve suitable changes'),matching&&state.runs.length?'action':'waiting',matching&&state.runs.length?runLink(state.runs[0]):link('scan',t('بدء الفحص والخطة','Start inspection and planning'))],
    [t('تابع التنفيذ والنتيجة','Follow execution and measurement'),working?'working':matching&&state.runs.length?'available':'waiting',link('results',t('عرض النتائج والمتابعة','View results and follow-up'))]
   ];
   for(const [label,status,action] of steps){const row=make('li');row.dataset.stepState=status;row.append(make('strong','',label),make('span','se-step-state',({done:t('مكتمل الإعداد','Setup complete'),action:t('راجع المطلوب منك','Review your action'),working:t('المنصة تعمل في الخلفية','The platform is working in the background'),available:t('افتح النتيجة وحالة القياس','Open outcomes and measurement status'),waiting:t('المنصة تتابع بعد اكتمال الخطوة السابقة','The platform follows after the preceding step')})[status]),action);journeySteps.append(row);}
  };
  // بدل 4 كروت بتكرر التبويبات: ملخص باللي المنصة عملته فعلًا على الموقع
  const results=make('article','se-card se-results-card');
  const resultsTitle=make('h3','',t('اللي اتعمل على موقعك','What was done on your website'));
  const stats=make('div','se-stats');const recent=make('ul','se-recent');
  const statBox=(id,label)=>{const box=make('div','se-stat');const v=make('strong','','—');v.id=id;box.append(v,make('span','',label));stats.append(box);};
  statBox('seStatChanges',t('تعديلات اتطبقت واتأكد منها','Previously verified changes'));
  statBox('seStatArticles',t('مقالات منشورة','Previously verified articles'));
  statBox('seStatPages',t('صفحات اتفحصت في آخر فحص','Pages checked in last scan'));
  results.append(resultsTitle,stats,recent);panels.get('overview').append(results);
  let summaryFor='';
  const siteUrl=path=>{const o=document.getElementById('seWebsite')?.selectedOptions?.[0];const host=(o?.textContent||'').trim();return /^[a-z0-9.-]+\.[a-z]{2,}$/i.test(host)?'https://'+host+(path||'/'):'';};
  const loadSummary=async()=>{
   if(!state||state.websiteId!==selected())return;const key=state.websiteId+':'+state.runs.map(r=>r.id+r.status+':'+(r.revision||'')).join(',');if(key===summaryFor)return;summaryFor=key;
   const ids=state.runs.slice(0,5).map(r=>r.id);let changes=0,articles=0,pagesScanned=null;const items=[];
   for(const id of ids){
    let d;try{d=await (await fetch('/api/seo-expert/runs/'+id,{credentials:'same-origin',headers:{Accept:'application/json'}})).json();}catch(e){continue;}
    if(!d||!d.success)continue;const run=d.data.run||{},tasks=d.data.tasks||[];
    if(key!==summaryFor)return;
    if(Number(id)===Number(state.runs[0]?.id)){latestTasks=tasks;renderNext();}
    if(pagesScanned===null){let snap=run.snapshot;if(typeof snap==='string'){try{snap=JSON.parse(snap);}catch(e){snap=null;}}if(snap&&Array.isArray(snap.pages))pagesScanned=snap.pages.length;}
    for(const task of tasks){
     const done=task.status==='verified';
     if(!done||task.evidence?.superseded_by_task_id)continue;
     if(task.kind==='content'){articles++;items.push({text:t('مقال — تحقق سابق: ','Article — previously verified: ')+task.title,url:task.published_url});}
     else if(task.kind==='metadata'){changes++;items.push({text:({title:t('عنوان','Title'),description:t('وصف','Description')}[task.field_name]||task.field_name)+' — '+(task.page_path||'/'),url:task.page_url||siteUrl(task.page_path)});}
    }
   }
   if(key!==summaryFor)return;
   document.getElementById('seStatChanges').textContent=String(changes);
   document.getElementById('seStatArticles').textContent=String(articles);
   document.getElementById('seStatPages').textContent=pagesScanned===null?'—':String(pagesScanned);
   recent.replaceChildren();
   for(const item of items.slice(0,6)){const li=make('li');if(item.url&&/^https:\/\//.test(item.url)){const a=make('a','',item.text);a.href=item.url;a.target='_blank';a.rel='noopener noreferrer';li.append(a);}else li.textContent=item.text;recent.append(li);}
   if(!items.length)recent.append(make('li','p-cell-muted',t('لسه مفيش تعديلات اتطبقت. ابدأ فحص واعتمد الخطة.','No changes applied yet. Start an inspection and approve the plan.')));
  };
  const research=make('article','se-card');research.append(make('h3','',t('بحث المنافسين والكلمات','Competitor & keyword research')),make('p','',t('افتح أدوات البحث الموجودة في المنصة. بدء تحليل جديد يتبع التكلفة والموافقة المعروضة في أداته.','Open the existing research tools. New analysis follows the cost and approval shown by each tool.')));
  const researchLinks=make('div','se-actions');
  for(const [href,label] of [['/ai/competitors',t('تحليل المنافسين','Competitor analysis')],['/ai/keywords',t('بحث الكلمات','Keyword research')]]){const a=make('a','p-btn outline',label);a.href=href;if(href==='/ai/keywords')a.dataset.seResearchKeywords='1';a.target='_blank';a.rel='noopener noreferrer';researchLinks.append(a);}
  research.append(researchLinks);// روابط البحث بقت تبويب "الكلمات والمنافسين" في مركز السيو
  const cycleChoices=make('article','se-card');cycleChoices.append(make('h3','',t('الفحوصات المحفوظة','Stored inspections')));const scanRuns=make('div','se-actions');scanRuns.id='seScanRuns';cycleChoices.append(scanRuns);// قايمة الدورات بتظهر مرة واحدة بس (في الخطة)
  const planHint=make('p','se-note',t('آخر خطة بتتفتح لوحدها. عايز تشوف خطة أقدم؟ اختارها من القايمة.','The latest plan opens automatically. Pick an older one from the list.'));panels.get('plan').prepend(planHint);
  const reports=make('article','se-card');reports.append(make('h3','',t('سجل دورات التحسين','Improvement cycle history')));const resultRuns=make('div','se-actions');resultRuns.id='seResultRuns';reports.append(resultRuns,make('p','p-cell-muted',t('حالة الدورة وحدها لا تثبت نجاح كل مهامها. افتحها لقراءة التحقق والمهام التي تحتاج مراجعة.','A cycle state alone does not prove every task succeeded. Open it to review verification and unresolved tasks.')),link('execute',t('سجل تعديلات صفحة: افتح الصفحة ثم اضغط سجل التعديلات','Page edit history: open a page, then choose Edit history')));// سجل الدورات موجود في الخطة؛ مش بنكرره هنا
  const resultHelp=make('p','se-note',t('التغييرات المحفوظة وظهورها تُراجع داخل المحرر. قياس الزيارات والنتائج يظهر أدناه حسب الربط والبيانات المتاحة.','Review saved changes and rendering in the editor. Traffic and outcome measurement appears below when connections and data are available.'));panels.get('results').append(resultHelp);
  const placeholders=new Map();
  for(const [page,label] of [['execute',t('المحرر غير متاح. راجع تحميل ملفات التحديث ثم أعد فتح الصفحة.','Editor unavailable. Check update files and reload the page.')],['results',t('لوحة قياس النتائج غير متاحة في هذه النسخة.','Measurement dashboard is unavailable in this version.')],['tours',t('أداة إنشاء مسودة الرحلة غير متاحة. راجع تحديث محرر الرحلات.','Tour draft creation is unavailable. Check the tour editor update.')]]){const n=make('p','se-note',label);placeholders.set(page,n);panels.get(page).append(n);}
  const adoptModules=()=>{
   for(const [id,page] of [['seoPageEditor','execute'],['seoImpact','results'],['tfTourDraft','tours']]){const node=document.getElementById(id);if(node){move(id,page);placeholders.get(page).hidden=true;}}
  };
  const updateLinks=()=>{for(const a of root.querySelectorAll('[data-se-research-keywords]'))a.href='/ai/keywords'+(selected()?'?website_id='+selected():'');for(const a of root.querySelectorAll('a[data-se-page]')){a.href=urlFor(a.dataset.sePage);if(a.closest('#seNavigation')){if(a.dataset.sePage===active)a.setAttribute('aria-current','page');else a.removeAttribute('aria-current');}}};
  const renderNext=()=>{
   nextActions.replaceChildren();
   if(!selected()){nextText.textContent=t('أضف موقعك أو اختَر موقعًا من القائمة لبدء الخدمة.','Add or choose a website to begin.');const a=make('a','p-btn',t('إدارة المواقع','Manage websites'));a.href='/websites';nextActions.append(a);return;}
   if(!state||state.websiteId!==selected()){nextText.textContent=t('جارٍ تحميل حالة الموقع. لو استمر الانتظار، اضغط تحديث الحالة.','Loading website state. If this continues, choose Refresh status.');return;}
   if(!state.configured){nextText.textContent=t('أكمل بيانات الشركة وحدود العمل، ثم ابدأ أول فحص.','Complete company facts and work limits, then start your first inspection.');nextActions.append(link('settings',t('أكمل الإعداد','Complete setup')));}
   else if(!state.workerReady){nextText.textContent=t('المتابعة غير متاحة من المنصة حاليًا. عملك السابق محفوظ؛ تواصل مع الدعم لو استمر التنبيه.','Background processing is unavailable from the platform. Existing work is preserved; contact support if this continues.');nextActions.append(link('results',t('راجع العمل المحفوظ','Review saved work')));}
   else if(state.runs.some(r=>['queued','scanning','scanned','planning','executing','monitoring'].includes(r.status))){const run=state.runs.find(r=>['queued','scanning','scanned','planning','executing','monitoring'].includes(r.status));nextText.textContent=t('المنصة تعمل على دورتك في الخلفية. افتحها لمتابعة الحالة تلقائيًا؛ لا تحتاج بدء دورة أخرى أو إبقاء الصفحة مفتوحة.','The platform is processing your existing cycle. Open it for automatic status updates; no new cycle or open browser is required.');nextActions.append(runLink(run));}
   else if(state.runs.length&&(latestTasks||[]).some(x=>['pending','needs_client'].includes(x.status)&&!['excluded','needs_evidence'].includes(x.recommendation_review?.state)&&!['waiting_google','historical'].includes(x.follow_up?.state))){nextText.textContent=t('عندك خطة محفوظة تحتاج مراجعتك. افتح المهام والحقائق؛ مراجعتها لا تحتاج شراء دورة جديدة، حتى لو وصلت لحد الدورات.','Your saved plan needs review. Open its tasks and facts; reviewing it needs no new paid cycle, even at the cycle limit.');nextActions.append(runLink(state.runs[0]),link('results',t('النتائج','Results')));}
   else if(state.cycleBlock&&/limit|الحد/i.test(state.cycleBlock)){nextText.textContent=t('خلصت الدورات المسموحة الشهر ده. التعديلات المتحققة سابقًا معروضة تحت؛ أعد التحقق من حالتها الحالية. لو عايز فحص جديد دلوقتي علّي الحد من الإعدادات، وإلا هتتجدد الشهر الجاي.','You used this month’s cycles. Previously verified changes are listed below; recheck their current state. To scan again now, raise the limit in settings; otherwise it renews next month.');nextActions.append(link('settings',t('علّي الحد','Raise the limit')),link('results',t('النتائج','Results')));}
   else if(state.cycleBlock){nextText.textContent=state.cycleBlock;nextActions.append(link('plan',t('تابع الدورة الحالية','Follow the current cycle')));}
   else if(state.runs.length){const tasks=latestTasks||[],excluded=x=>['excluded','needs_evidence'].includes(x.recommendation_review?.state),pending=tasks.filter(x=>x.status==='pending'&&!excluded(x)).length,client=tasks.filter(x=>x.status==='needs_client'&&!excluded(x)&&x.follow_up?.state!=='waiting_google').length;nextText.textContent=pending?t('الخطة جاهزة. راجع واعتمد المقترحات المناسبة؛ المنصة تنفذ المعتمد وتتحقق من ظهوره. مقترحات للمراجعة: ','Your plan is ready. Review suitable proposals; the platform executes approved work and verifies it. Proposals to review: ')+pending:client?t('المطلوب منك الآن معلومات أو إجراءات محددة داخل الخطة. مهام للمراجعة: ','Your plan needs specific information or external actions. Tasks to review: ')+client:t('تابع آخر دورة ونتائجها. القياس يحتاج اكتمال فترته؛ وجود تعديل على الموقع لا يثبت تحسن الترتيب.','Review your latest cycle and results. Measurement needs a complete window; a visible edit does not establish ranking improvement.');nextActions.append(runLink(state.runs[0]),link('results',t('النتائج','Results')));}
   else {nextText.textContent=t('إعداداتك محفوظة. افتح الفحص وراجع الاستخدام المعروض قبل البدء.','Setup is saved. Open inspection and review the displayed usage before starting.');nextActions.append(link('scan',t('ابدأ الفحص','Start inspection')));}
  };
  const runLink=run=>{const source=root.querySelector('#seRuns [data-run="'+run.id+'"]');const a=link('plan',source?.textContent||'#'+run.id);a.dataset.seOpenRun=String(run.id);return a;};
  const renderRuns=()=>{
   for(const dest of [scanRuns,resultRuns]){dest.replaceChildren();if(!state||state.websiteId!==selected()||!state.runs.length){dest.append(make('p','',t('لا توجد دورة معروضة لهذا الموقع. حدّث الحالة أو ابدأ فحصًا جديدًا.','No cycle is available for this website. Refresh status or start an inspection.')));continue;}for(const run of state.runs)dest.append(runLink(run));}
  };
  const show=(page,focus=false)=>{
   active=allowed.has(page)?page:'overview';const info=pages.find(p=>p[0]===active);
   for(const [key,pane] of panels)pane.hidden=key!==active;
   heading.textContent=info[1];description.textContent=info[2];pageHead.hidden=active==='overview';root.dataset.sePage=active;
   document.title=info[1]+' | Tourfecto';updateLinks();adoptModules();
   // الصفحة بتتغير من غير تحميل، فنحدّث الزرار المظلل في تبويبات مركز السيو
   for(const a of document.querySelectorAll('.seo-hub-sub a[href^="/seo-expert"]')){const on=(new URL(a.href,location.href).searchParams.get('page')||'overview')===active;a.classList.toggle('primary',on);a.classList.toggle('outline',!on);if(on)a.setAttribute('aria-current','page');else a.removeAttribute('aria-current');}
   window.dispatchEvent(new CustomEvent('tourfecto-seo-page',{detail:{page:active,websiteId:selected()}}));
   if(focus)heading.focus({preventScroll:true});
   if(active==='plan')openLatest();
  };
  const openLatest=()=>{setTimeout(()=>{if(active!=='plan'||!state||state.websiteId!==selected()||document.getElementById('seDetail')?.children.length||root.hasAttribute('aria-busy'))return;const b=root.querySelector('#seRuns [data-run="'+Number(state.runs[0]?.id)+'"]');if(b&&!b.disabled)b.click();},0);};
  const go=page=>{const next=allowed.has(page)?page:'overview';if(next!==active)history.pushState(null,'',urlFor(next));show(next,true);};
  root.addEventListener('click',event=>{
   const a=event.target.closest('a[data-se-page]');if(!a||event.defaultPrevented||event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;
   event.preventDefault();if(a.dataset.seOpenRun){const b=root.querySelector('#seRuns [data-run="'+Number(a.dataset.seOpenRun)+'"]');if(b&&!b.disabled)b.click();}go(a.dataset.sePage);
  });
  window.addEventListener('popstate',()=>{const page=pageFromURL();history.replaceState(null,'',urlFor(page));show(page,true);});
  window.addEventListener('tourfecto-seo-overview',event=>{const d=event.detail;if(!d||d.websiteId!==selected())return;if(state?.runs[0]?.id!==d.runs[0]?.id)latestTasks=null;state=d;const worker=document.getElementById('seWorker');if(worker)worker.hidden=!!d.workerReady;renderNext();renderRuns();renderJourney();updateLinks();loadSummary();openLatest();history.replaceState(null,'',urlFor(active));});
  document.getElementById('seWebsite').addEventListener('change',()=>{state=null;latestTasks=null;renderNext();renderRuns();renderJourney();updateLinks();history.replaceState(null,'',urlFor(active));});
  // Existing transfers keep their content and permissions; navigation only reveals the destination.
  for(const name of ['tourfecto-editor-open','tourfecto-editor-draft','tourfecto-editor-task'])window.addEventListener(name,()=>go('execute'));
  window.addEventListener('tourfecto-seo-cycle-started',()=>go('plan'));
  const observer=new MutationObserver(()=>{if(!frame)frame=requestAnimationFrame(()=>{frame=0;adoptModules();updateLinks();});});
  observer.observe(root.parentElement,{childList:true,subtree:true});
  window.addEventListener('pagehide',event=>{if(!event.persisted){observer.disconnect();if(frame)cancelAnimationFrame(frame);}},{once:true});
  renderNext();renderRuns();renderJourney();show(active);
 };
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
