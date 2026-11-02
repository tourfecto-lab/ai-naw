<?php
$ar = function_exists('current_lang') ? current_lang() === 'ar' : true;
$t = static fn (string $a, string $e): string => htmlspecialchars($ar ? $a : $e, ENT_QUOTES, 'UTF-8');
?>
<style>
#vis-root{--vis-border:var(--panel-border,#29354b);padding:clamp(14px,3vw,26px);color:var(--panel-text,#f2f4f8);line-height:1.7}#vis-root *{box-sizing:border-box}#vis-root [hidden]{display:none!important}
.vis-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.vis-muted{color:var(--panel-muted,#a9b4c8);font-size:.92rem}
.vis-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;margin:18px 0}.vis-card{border:1px solid var(--vis-border);border-radius:14px;padding:16px}
.vis-score{font-size:2.4rem;font-weight:700;line-height:1.1}.vis-badge{display:inline-block;border:1px solid var(--vis-border);border-radius:999px;padding:1px 10px;font-size:.8rem;margin-inline-end:6px}
.vis-warn{color:#f5c26b}.vis-list{list-style:none;margin:0;padding:0;display:grid;gap:10px}.vis-list li{border:1px solid var(--vis-border);border-radius:12px;padding:12px;display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap}
#vis-site{min-width:220px;max-width:100%}#vis-root a:focus-visible,#vis-root select:focus-visible{outline:2px solid #6ea8ff;outline-offset:2px}
</style>
<section class="p-card" dir="<?= $ar ? 'rtl' : 'ltr' ?>" id="vis-root" data-lang="<?= $ar ? 'ar' : 'en' ?>" data-csrf="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
<div class="vis-head"><div><h2><?= $t('ظهورك في البحث والإجابات', 'Your search and answer visibility') ?></h2><div class="vis-muted"><?= $t('درجة SEO وAEO وGEO لموقعك، وأهم الإجراءات. كل رقم ليه مصدر وتاريخ ومستوى ثقة، وأي محور مش مقاس بيظهر "مش مقاس" بدل رقم تقديري.', 'SEO, AEO and GEO scores for your site, plus the top actions. Every number shows its date and confidence; an unmeasured axis says "not measured" instead of a guess.') ?></div></div>
<label class="vis-muted" for="vis-site"><?= $t('الموقع', 'Site') ?><br><select class="p-select" id="vis-site" disabled><option><?= $t('جارٍ التحميل…', 'Loading…') ?></option></select></label></div>
<p id="vis-msg" role="status" aria-live="polite" class="vis-muted" hidden></p>
<div id="vis-cards" class="vis-grid" hidden></div>
<div id="vis-impact" class="vis-card" hidden></div>
<div id="vis-share" class="vis-card" hidden><div class="vis-muted"><?= $t('مشاركة تقرير مع العميل', 'Share a report with your client') ?></div>
<p class="vis-muted"><?= $t('رابط للقراءة فقط (أرقام وملخصات بدون تفاصيل صفحاتك)، صالح 30 يوم وتقدر تلغيه. الصفحة قابلة للحفظ كـPDF.', 'A read-only link (numbers and summaries, no page-level detail), valid 30 days and revocable. The page can be saved as PDF.') ?></p>
<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><label class="vis-muted" for="vis-share-lang"><?= $t('لغة التقرير', 'Report language') ?></label><select class="p-select" id="vis-share-lang"><option value="ar">العربية</option><option value="en">English</option></select>
<button type="button" class="p-btn outline" id="vis-share-create"><?= $t('أنشئ رابط', 'Create link') ?></button></div>
<div id="vis-share-out" role="status" aria-live="polite" class="vis-muted"></div><ul id="vis-share-list" class="vis-list"></ul></div>
<h3 id="vis-actions-h" hidden><?= $t('أهم الإجراءات', 'Top actions') ?></h3>
<ul id="vis-actions" class="vis-list" hidden></ul>
</section>
<script>
(()=>{'use strict';
const root=document.getElementById('vis-root'),ar=root.dataset.lang==='ar',el=id=>document.getElementById(id),L=(a,e)=>ar?a:e;
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const AX={seo:['SEO',L('فحص الموقع الأخير','Latest site audit')],aeo:['AEO',L('تغطية الإجابات المباشرة','Direct-answer coverage')],geo:['GEO',L('عينة إجابات الذكاء الاصطناعي','AI answer sample')]};
const CONF={high:L('ثقة عالية','High confidence'),medium:L('ثقة متوسطة','Medium confidence'),low:L('ثقة منخفضة','Low confidence')};
const msg=(t,err)=>{const m=el('vis-msg');m.textContent=t||'';m.hidden=!t;m.classList.toggle('vis-warn',!!err);};
function safeUrl(u){return typeof u==='string'&&u.startsWith('/')&&!u.startsWith('//')?u:'#';}
function card(k,s){const n=AX[k][0],d=AX[k][1],m=s.score!==null&&s.score!==undefined;
 return '<article class="vis-card"><div class="vis-muted">'+esc(n)+' · '+esc(d)+'</div>'+(m
  ?'<div class="vis-score">'+s.score+'<small>/100</small></div><div><span class="vis-badge">'+esc(CONF[s.confidence]||'')+'</span>'+(s.stale?'<span class="vis-badge vis-warn">'+L('قديم','Stale')+'</span>':'')+'</div><div class="vis-muted">'+L('بتاريخ','As of')+' '+esc(s.as_of||'-')+'</div>'
  :'<div class="vis-score" aria-label="'+L('مش مقاس','Not measured')+'">—</div><div class="vis-muted">'+L('مش مقاس لسه','Not measured yet')+'</div>')+'</article>';}
function impact(i){const b=el('vis-impact');if(!i||i.state!=='measured'){b.innerHTML='<div class="vis-muted">'+L('أثر التعديلات (قبل/بعد من Search Console)','Impact of changes (before/after from Search Console)')+'</div><div class="vis-muted">'+L('لسه مفيش مقارنات اتجمّعت. بتظهر بعد 7 و14 و28 يوم من تطبيق التعديلات.','No comparisons collected yet. They appear 7, 14 and 28 days after changes are applied.')+'</div>';b.hidden=false;return;}
 const pc=v=>v===null?'':' ('+(v>0?'+':'')+v+'%)',pos=i.avg_position_change;
 b.innerHTML='<div class="vis-muted">'+L('أثر التعديلات (قبل/بعد من Search Console)','Impact of changes (before/after from Search Console)')+'</div>'+
  '<div class="vis-score" style="font-size:1.6rem">'+L('النقرات: ','Clicks: ')+i.clicks.before+' → '+i.clicks.after+esc(pc(i.clicks.change_pct))+'</div>'+
  '<div>'+L('الظهور: ','Impressions: ')+i.impressions.before+' → '+i.impressions.after+esc(pc(i.impressions.change_pct))+'</div>'+
  '<div>'+L('صفحات زادت نقراتها ','Pages with more clicks ')+i.pages_up+' · '+L('قلّت ','fewer ')+i.pages_down+' · '+L('ثابتة ','flat ')+i.pages_flat+(pos===null?'':' · '+L('متوسط تغيّر الترتيب ','avg position change ')+(pos>0?'+':'')+pos+L(' (الأقل أفضل)',' (lower is better)'))+'</div>'+
  '<div><span class="vis-badge">'+esc(CONF[i.confidence]||'')+'</span>'+i.pages+L(' صفحة قارنّاها · بتاريخ ',' pages compared · as of ')+esc(String(i.as_of||'-').slice(0,10))+'</div>'+
  (i.low_traffic?'<div class="vis-warn">'+L('الزيارات قليلة جدًا، فالفروق دي ممكن تكون ضوضاء.','Traffic is very low, so these differences may be noise.')+'</div>':'')+
  '<div class="vis-muted">'+L('ده فرق قبل/بعد على نفس الصفحات، مش إثبات إن التعديل هو السبب: الموسم وتغييرات تانية بتأثر.','This is a before/after difference on the same pages, not proof the changes caused it: seasonality and other changes also move these numbers.')+'</div>'+
  '<a class="p-btn outline" href="/seo-expert">'+L('تفاصيل النتائج','Result details')+'</a>';b.hidden=false;}
let curWid=0;
const csrf=root.dataset.csrf,post=(p,b)=>window.Panel.fetchJSON(p,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...b,csrf_token:csrf})});
async function shares(){const o=el('vis-share-out');el('vis-share').hidden=false;try{const r=await window.Panel.fetchJSON('/api/seo/report-share?website_id='+encodeURIComponent(curWid));if(!r.success)throw Error(r.error);const d=r.data;
 el('vis-share-create').disabled=!d.ready;if(!d.ready){o.textContent=L('مشاركة التقارير محتاجة تفعيل جدول جديد من الأدمن.','Report sharing needs a new table enabled by the admin.');el('vis-share-list').innerHTML='';return;}
 el('vis-share-list').innerHTML=d.links.map(l=>'<li><span>'+(l.active?L('فعّال','Active'):(l.revoked_at?L('ملغي','Revoked'):L('منتهي','Expired')))+' · '+esc(l.lang)+' · '+L('ينتهي ','expires ')+esc(String(l.expires_at).slice(0,10))+' · '+L('مشاهدات ','views ')+l.view_count+'</span>'+(l.active?'<button type="button" class="p-btn outline" data-rev="'+l.id+'">'+L('إلغاء','Revoke')+'</button>':'')+'</li>').join('');
 el('vis-share-list').querySelectorAll('[data-rev]').forEach(b=>b.onclick=async()=>{b.disabled=true;try{const r=await post('/api/seo/report-share/'+b.dataset.rev+'/revoke',{});if(!r.success)throw Error(r.error);shares();}catch(e){o.textContent=e.message;b.disabled=false;}});
 }catch(e){o.textContent=(e&&e.message)||L('تعذر التحميل.','Could not load.');}}
el('vis-share-create').onclick=async e=>{const b=e.currentTarget,o=el('vis-share-out');b.disabled=true;try{const r=await post('/api/seo/report-share',{website_id:Number(curWid),lang:el('vis-share-lang').value});if(!r.success)throw Error(r.error||r.message);
 o.innerHTML=L('انسخ الرابط دلوقتي (بيظهر مرة واحدة): ','Copy the link now (shown once): ')+'<br><input class="p-input" readonly style="width:100%;direction:ltr" value="'+esc(r.data.url)+'" onfocus="this.select()">';shares();}
 catch(err){o.textContent=(err&&err.message)||L('تعذر الإنشاء.','Could not create.');}finally{b.disabled=false;}};
function render(d){el('vis-cards').innerHTML=['seo','aeo','geo'].map(k=>card(k,d[k])).join('');el('vis-cards').hidden=false;impact(d.impact);shares();
 const a=d.actions||[],ul=el('vis-actions');el('vis-actions-h').hidden=false;ul.hidden=false;
 ul.innerHTML=a.length?a.map(x=>'<li><span><span class="vis-badge">'+esc(String(x.area).toUpperCase())+'</span>'+esc(x.title)+'<br><small class="vis-muted">'+esc(x.detail||'')+'</small></span><a class="p-btn outline" href="'+esc(safeUrl(x.url))+'">'+L('افتح ونفّذ','Open and apply')+'</a></li>').join('')
  :'<li class="vis-muted">'+L('مفيش إجراءات معلّقة دلوقتي.','No pending actions right now.')+'</li>';}
let epoch=0;
async function load(wid){const my=++epoch;curWid=wid;msg(L('جارٍ التحميل…','Loading…'));el('vis-cards').hidden=true;el('vis-impact').hidden=true;
 try{const r=await window.Panel.fetchJSON('/api/visibility/overview?website_id='+encodeURIComponent(wid));if(my!==epoch)return;if(!r.success)throw Error(r.error||r.message);msg('');render(r.data);}
 catch(e){if(my!==epoch)return;msg((e&&e.message)||L('تعذر التحميل. جرّب تاني.','Could not load. Try again.'),true);}}
const start=async()=>{try{const r=await window.Panel.fetchJSON('/api/websites');const list=(r.data&&r.data.websites)||r.data||r.websites||[],sel=el('vis-site');
 if(!Array.isArray(list)||!list.length){sel.innerHTML='<option>'+L('مفيش مواقع','No sites')+'</option>';msg(L('ضيف موقع الأول من صفحة المواقع.','Add a site first from the Websites page.'));return;}
 sel.innerHTML=list.map(w=>'<option value="'+esc(w.id)+'">'+esc(w.company_name||w.brand_name||w.main_url||w.id)+'</option>').join('');sel.disabled=false;sel.onchange=()=>load(sel.value);load(sel.value);}
 catch(e){msg(L('تعذر تحميل المواقع.','Could not load sites.'),true);}};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
</script>
