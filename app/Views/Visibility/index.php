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
<section class="p-card" dir="<?= $ar ? 'rtl' : 'ltr' ?>" id="vis-root" data-lang="<?= $ar ? 'ar' : 'en' ?>">
<div class="vis-head"><div><h2><?= $t('ظهورك في البحث والإجابات', 'Your search and answer visibility') ?></h2><div class="vis-muted"><?= $t('درجة SEO وAEO وGEO لموقعك، وأهم الإجراءات. كل رقم ليه مصدر وتاريخ ومستوى ثقة، وأي محور مش مقاس بيظهر "مش مقاس" بدل رقم تقديري.', 'SEO, AEO and GEO scores for your site, plus the top actions. Every number shows its date and confidence; an unmeasured axis says "not measured" instead of a guess.') ?></div></div>
<label class="vis-muted" for="vis-site"><?= $t('الموقع', 'Site') ?><br><select class="p-select" id="vis-site" disabled><option><?= $t('جارٍ التحميل…', 'Loading…') ?></option></select></label></div>
<p id="vis-msg" role="status" aria-live="polite" class="vis-muted" hidden></p>
<div id="vis-cards" class="vis-grid" hidden></div>
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
function render(d){el('vis-cards').innerHTML=['seo','aeo','geo'].map(k=>card(k,d[k])).join('');el('vis-cards').hidden=false;
 const a=d.actions||[],ul=el('vis-actions');el('vis-actions-h').hidden=false;ul.hidden=false;
 ul.innerHTML=a.length?a.map(x=>'<li><span><span class="vis-badge">'+esc(String(x.area).toUpperCase())+'</span>'+esc(x.title)+'<br><small class="vis-muted">'+esc(x.detail||'')+'</small></span><a class="p-btn outline" href="'+esc(safeUrl(x.url))+'">'+L('افتح ونفّذ','Open and apply')+'</a></li>').join('')
  :'<li class="vis-muted">'+L('مفيش إجراءات معلّقة دلوقتي.','No pending actions right now.')+'</li>';}
let epoch=0;
async function load(wid){const my=++epoch;msg(L('جارٍ التحميل…','Loading…'));el('vis-cards').hidden=true;
 try{const r=await window.Panel.fetchJSON('/api/visibility/overview?website_id='+encodeURIComponent(wid));if(my!==epoch)return;if(!r.success)throw Error(r.error||r.message);msg('');render(r.data);}
 catch(e){if(my!==epoch)return;msg((e&&e.message)||L('تعذر التحميل. جرّب تاني.','Could not load. Try again.'),true);}}
const start=async()=>{try{const r=await window.Panel.fetchJSON('/api/websites');const list=(r.data&&r.data.websites)||r.data||r.websites||[],sel=el('vis-site');
 if(!Array.isArray(list)||!list.length){sel.innerHTML='<option>'+L('مفيش مواقع','No sites')+'</option>';msg(L('ضيف موقع الأول من صفحة المواقع.','Add a site first from the Websites page.'));return;}
 sel.innerHTML=list.map(w=>'<option value="'+esc(w.id)+'">'+esc(w.company_name||w.brand_name||w.main_url||w.id)+'</option>').join('');sel.disabled=false;sel.onchange=()=>load(sel.value);load(sel.value);}
 catch(e){msg(L('تعذر تحميل المواقع.','Could not load sites.'),true);}};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
</script>
