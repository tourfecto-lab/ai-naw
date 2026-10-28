(() => {
  'use strict';
  const overview = document.getElementById('metaHistoryOverviewBody');
  const calendar = document.getElementById('metaHistoryCalendarBody');
  if (!overview && !calendar) return;
  const isAr = document.documentElement.lang?.startsWith('ar');
  const msg = (en,ar) => isAr ? ar : en;
  const make = (tag, cls, txt) => {const n=document.createElement(tag); if(cls)n.className=cls; if(txt!==undefined)n.textContent=String(txt); return n;};
  const fmt = n => new Intl.NumberFormat(isAr?'ar-EG':'en-US').format(Number(n)||0);
  const draw = data => {
    if (overview) {
      overview.replaceChildren();
      const totals=data.totals||{}, available=data.available_counts||{};
      const stats=make('div','sh-stats');
      [['posts',msg('Saved posts','المنشورات المحفوظة')],['likes',msg('Likes','الإعجابات')],['comments',msg('Comments','التعليقات')],['shares',msg('Shares','المشاركات')]].forEach(([key,title])=>{
        const tile=make('div','sh-stat');tile.append(make('strong','',key==='posts'||available[key]>0?fmt(totals[key]):'—'),make('span','',title));
        if(key!=='posts'&&available[key]===0)tile.title=msg('Metric unavailable for saved records','البيانات غير متاحة للمنشورات المحفوظة');
        stats.append(tile);
      });
      overview.append(stats);
      const foot=make('p','sh-muted',msg(`Last ${data.days} days · Saved posts only; not added to publishing metrics. Reach unavailable.`,`آخر ${data.days} يوم · المنشورات المحفوظة فقط، ولا تُضاف لإحصائيات النشر. الوصول غير متاح.`));overview.append(foot);
      if(data.truncated)overview.append(make('p','sh-warning',msg('More than 5,000 saved rows: report is partial.','أكثر من 5000 صف محفوظ: التقرير جزئي.')));
    }
    if(calendar){
      calendar.replaceChildren();
      const days=(data.calendar||[]);
      historicalDays=days; schedulePaint();
      if(!days.length){calendar.append(make('p','sh-muted',msg('No saved historical posts in the selected period.','لا توجد منشورات قديمة محفوظة خلال الفترة المحددة.')));return;}
      const wrap=make('div','sh-days');
      for(const day of days){
        const section=make('details','sh-day');
        const date=make('summary','',`${day.date} · ${fmt(day.count)} ${msg('posts','منشورات')}`);section.append(date);
        const list=make('ul','sh-post-list');
        for(const p of (day.posts||[])){
          const li=make('li','');li.append(make('strong','',p.platform+' · '));
          const short=(p.text||'').trim();li.append(make('span','',short.slice(0,110)||msg('Post without text','منشور بدون نص')));
          if(typeof p.permalink==='string'&&/^https:\/\/[^\s]+$/i.test(p.permalink)){const link=make('a','',msg(' View original',' فتح المنشور'));link.href=p.permalink;link.target='_blank';link.rel='noopener noreferrer';li.append(link);}
          list.append(li);
        }
        section.append(list);wrap.append(section);
      }
      calendar.append(wrap);
    }
  };
  // Read-only historical posts overlay on the native publishing calendar.
  // The native calendar retains sole ownership of scheduled/publishing entries.
  let historicalDays = [];
  let paintQueued = false;
  const grid = document.getElementById('calendarGrid');
  const showDetails = (post, date) => {
    document.getElementById('sh-history-dialog')?.remove();
    const overlay = make('div','sh-history-overlay');overlay.id='sh-history-dialog';
    overlay.setAttribute('role','presentation');
    const dialog=make('section','sh-history-dialog');dialog.setAttribute('role','dialog');dialog.setAttribute('aria-modal','true');
    dialog.setAttribute('aria-label',msg('Historical post details','تفاصيل المنشور القديم'));
    const head=make('div','sh-dialog-head');
    head.append(make('strong','',`${post.platform} · ${date}`));
    const close=make('button','sh-dialog-close','×');close.type='button';close.setAttribute('aria-label',msg('Close','إغلاق'));close.addEventListener('click',()=>overlay.remove());head.append(close);
    dialog.append(head,make('p','sh-dialog-content',post.text||msg('No text available','لا يوجد نص متاح')));
    if(typeof post.permalink==='string'&&/^https:\/\/[^\s]+$/i.test(post.permalink)){
      const link=make('a','sh-dialog-link',msg('Open original post','فتح المنشور الأصلي'));link.href=post.permalink;link.target='_blank';link.rel='noopener noreferrer';dialog.append(link);
    }
    dialog.append(make('p','sh-muted',msg('Imported history · read-only · never scheduled for publishing','منشور مستورد · للقراءة فقط · لن تتم جدولة نشره')));
    overlay.append(dialog);overlay.addEventListener('click',e=>{if(e.target===overlay)overlay.remove();});
    document.addEventListener('keydown',function onEscape(e){if(e.key==='Escape'){overlay.remove();document.removeEventListener('keydown',onEscape);}}, {once:false});
    document.body.append(overlay);close.focus();
  };
  const localKey = d => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
  const paintCalendar = () => {
    if(!grid || !historicalDays.length)return;
    const cells=[...grid.querySelectorAll(':scope > .d')];
    if(cells.length!==42)return;
    // Use the same 42-day start calculation as social-pro.js.
    const today=new Date();today.setHours(0,0,0,0);
    const start=new Date(today);start.setDate(start.getDate()-7);
    start.setDate(start.getDate()-((start.getDay()+1)%7));
    const lookup=new Map(historicalDays.map(day=>[day.date,day]));
    cells.forEach((cell,i)=>{
      cell.querySelectorAll('.sh-grid-history').forEach(el=>el.remove());
      const d=new Date(start);d.setDate(start.getDate()+i);
      const date=localKey(d), day=lookup.get(date);
      if(!day)return;
      const holder=make('div','sh-grid-history');
      (day.posts||[]).slice(0,2).forEach(post=>{
        const btn=make('button','sh-grid-event',`${post.platform==='instagram'?'◎':'f'} · ${(post.text||msg('Historical post','منشور سابق')).slice(0,25)}`);
        btn.type='button';btn.title=msg('View historical post (read-only)','عرض المنشور القديم (قراءة فقط)');
        btn.addEventListener('click',e=>{e.stopPropagation();showDetails(post,date);});holder.append(btn);
      });
      if(day.count>2)holder.append(make('span','sh-grid-more',`+${day.count-2} ${msg('older posts','منشورات قديمة')}`));
      cell.append(holder);
    });
  };
  const schedulePaint = () => {if(paintQueued)return;paintQueued=true;queueMicrotask(()=>{paintQueued=false;paintCalendar();});};
  if(grid){
    new MutationObserver(changes=>{
      // Ignore our own inserted historical entries: only react to native calendar rebuilds.
      if(changes.some(change=>[...change.addedNodes].some(node=>node.nodeType===1&&!node.classList?.contains('sh-grid-history')&&!node.classList?.contains('sh-grid-event'))))schedulePaint();
    }).observe(grid,{childList:true});
  }

  let busy=false;
  async function refresh(){
    if(busy)return;busy=true;
    try{
      const days=Math.min(365, Math.max(30,Number(document.getElementById('spDays')?.value||30)));
      const res=await fetch(`/api/social/history/summary?days=${days}`,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
      const json=await res.json();if(!res.ok||json.success===false)throw Error(json.message||json.error||`HTTP ${res.status}`);
      draw(json.data||json);
    }catch(err){if(overview)overview.textContent=err.message;if(calendar)calendar.textContent=err.message;}finally{busy=false;}
  }
  document.getElementById('spDays')?.addEventListener('change',refresh);
  document.getElementById('metaHistoryImport')?.addEventListener('click',()=>setTimeout(refresh,1500));
  document.getElementById('metaHistorySaved')?.addEventListener('click',()=>setTimeout(refresh,500));
  refresh();
})();
