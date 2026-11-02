<?php
$ar = function_exists('current_lang') ? current_lang() === 'ar' : true;
$t = static fn (string $a, string $e): string => htmlspecialchars($ar ? $a : $e, ENT_QUOTES, 'UTF-8');
?>
<style>
#geoi-root{--gi-border:var(--panel-border,#29354b);padding:clamp(14px,3vw,26px);color:var(--panel-text,#f2f4f8);line-height:1.7}#geoi-root *{box-sizing:border-box}#geoi-root [hidden]{display:none!important}
.gi-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.gi-muted{color:var(--panel-muted,#a9b4c8);font-size:.92rem}
.gi-box{border:1px solid var(--gi-border);border-radius:14px;padding:16px;margin:16px 0}.gi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}
.gi-stat{font-size:1.8rem;font-weight:700;line-height:1.2}.gi-badge{display:inline-block;border:1px solid var(--gi-border);border-radius:999px;padding:1px 10px;font-size:.8rem;margin-inline-end:6px}
.gi-ok{color:#7ee0a1}.gi-bad{color:#ff8a80}.gi-warn{color:#f5c26b}.gi-scroll{overflow-x:auto}table.gi-t{width:100%;border-collapse:collapse;min-width:520px}.gi-t th,.gi-t td{text-align:start;padding:8px 10px;border-bottom:1px solid var(--gi-border);vertical-align:top;font-size:.9rem}
.gi-rec{border:1px solid var(--gi-border);border-radius:12px;padding:12px;margin:10px 0}.gi-q{font-weight:600}svg.gi-chart{width:100%;height:auto;max-height:240px}
#geoi-site{min-width:220px;max-width:100%}#geoi-root :focus-visible{outline:2px solid #6ea8ff;outline-offset:2px}
</style>
<section class="p-card" dir="<?= $ar ? 'rtl' : 'ltr' ?>" id="geoi-root" data-lang="<?= $ar ? 'ar' : 'en' ?>">
<div class="gi-head"><div><h2><?= $t('الأسئلة والمنافسين (GEO)', 'Prompts and competitors (GEO)') ?></h2>
<div class="gi-muted"><?= $t('مبني على إجابات الذكاء الاصطناعي المخزّنة من قياسك الأسبوعي. هي عينات API مش ترتيب تطبيقات المستهلك، فاتابع الاتجاه مش إجابة واحدة. الصفحة دي ما بتصرفش رصيد ولا بتبعت طلبات للمحركات.', 'Built from the AI answers stored by your weekly measurements. They are API samples, not consumer-app rankings, so follow the trend, not a single answer. This page spends no credit and sends no requests to AI engines.') ?></div></div>
<label class="gi-muted" for="geoi-site"><?= $t('الموقع', 'Site') ?><br><select class="p-select" id="geoi-site" disabled><option><?= $t('جارٍ التحميل…', 'Loading…') ?></option></select></label></div>
<p id="geoi-msg" role="status" aria-live="polite" class="gi-muted" hidden></p>
<div id="geoi-body" hidden>
<div class="gi-grid" id="geoi-stats"></div>
<div class="gi-box"><h3><?= $t('التطور الأسبوعي', 'Weekly trend') ?></h3><div id="geoi-chart"></div></div>
<div class="gi-box"><h3><?= $t('التوصيات حسب السؤال', 'Recommendations per question') ?></h3><div id="geoi-recs"></div></div>
<div class="gi-box"><h3><?= $t('المنافسين اللي بيظهروا في الإجابات', 'Competitors appearing in answers') ?></h3><div class="gi-scroll" id="geoi-comp"></div></div>
<div class="gi-box"><h3><?= $t('المصادر المستشهد بيها', 'Cited sources') ?></h3><div class="gi-scroll" id="geoi-cit"></div><p class="gi-muted"><a href="/ai-insights"><?= $t('تفاصيل المصادر والانطباع', 'Source and sentiment details') ?></a></p></div>
<div class="gi-box"><h3><?= $t('نتيجة كل سؤال', 'Result per question') ?></h3><div class="gi-scroll" id="geoi-prompts"></div></div>
<p class="gi-muted" id="geoi-note"></p>
</div>
</section>
<script>
(()=>{'use strict';
const root=document.getElementById('geoi-root'),ar=root.dataset.lang==='ar',el=id=>document.getElementById(id),L=(a,e)=>ar?a:e;
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const pct=v=>v===null||v===undefined?'—':Math.round(v*100)+'%';
const PN={openai:'ChatGPT',gemini:'Gemini',perplexity:'Perplexity',claude:'Claude'};
const CONF={high:L('ثقة عالية','High confidence'),medium:L('ثقة متوسطة','Medium confidence'),low:L('ثقة منخفضة','Low confidence')};
const msg=(t,err)=>{const m=el('geoi-msg');m.textContent=t||'';m.hidden=!t;m.classList.toggle('gi-warn',!!err);};
const safeUrl=u=>typeof u==='string'&&u.startsWith('/')&&!u.startsWith('//')?u:'#';
function chart(trend){if(!trend.length)return '<p class="gi-muted">'+L('مفيش بيانات أسبوعية كفاية.','Not enough weekly data.')+'</p>';
 const W=640,H=200,P=28,n=trend.length,x=i=>n===1?W/2:P+i*(W-2*P)/(n-1),y=v=>H-P-(v||0)*(H-2*P);
 const line=(k,col)=>{const pts=trend.map((w,i)=>w.all[k]===null?null:[x(i),y(w.all[k])]).filter(Boolean);return pts.length?'<polyline fill="none" stroke="'+col+'" stroke-width="2.5" points="'+pts.map(p=>p.join(',')).join(' ')+'"/>'+pts.map(p=>'<circle cx="'+p[0]+'" cy="'+p[1]+'" r="3.5" fill="'+col+'"/>').join(''):'';};
 const grid=[0,.5,1].map(v=>'<line x1="'+P+'" x2="'+(W-P)+'" y1="'+y(v)+'" y2="'+y(v)+'" stroke="currentColor" opacity=".15"/><text x="2" y="'+(y(v)+4)+'" font-size="11" fill="currentColor" opacity=".7">'+(v*100)+'%</text>').join('');
 const lab=trend.map((w,i)=>'<text x="'+x(i)+'" y="'+(H-6)+'" font-size="10" text-anchor="middle" fill="currentColor" opacity=".7">'+esc(w.week.slice(5))+'</text>').join('');
 return '<svg class="gi-chart" viewBox="0 0 '+W+' '+H+'" role="img" aria-label="'+L('نسبة الذكر ونسبة الاستشهاد أسبوعيًا','Weekly mention and citation rate')+'">'+grid+lab+line('share_of_voice','#6ea8ff')+line('citation_rate','#7ee0a1')+'</svg>'+
 '<p class="gi-muted"><span class="gi-badge" style="border-color:#6ea8ff">'+L('نسبة الذكر','Mention rate')+'</span><span class="gi-badge" style="border-color:#7ee0a1">'+L('نسبة الاستشهاد','Citation rate')+'</span></p>';}
function table(head,rows){return rows.length?'<table class="gi-t"><thead><tr>'+head.map(h=>'<th>'+esc(h)+'</th>').join('')+'</tr></thead><tbody>'+rows.join('')+'</tbody></table>':'<p class="gi-muted">'+L('مفيش بيانات.','No data.')+'</p>';}
function render(d){el('geoi-body').hidden=false;
 if(d.state==='not_measured'){el('geoi-stats').innerHTML='';['geoi-chart','geoi-recs','geoi-comp','geoi-cit','geoi-prompts'].forEach(i=>el(i).innerHTML='<p class="gi-muted">'+L('لسه مفيش إجابات مخزّنة. القياس بيحتاج إن الأدمن يفعّل مزودي الـAI وإن تعتمد أسئلتك من صفحة "ظهورك في الإجابات".','No stored answers yet. Measurement needs the admin to enable the AI providers and you to approve your questions on the "Your visibility in answers" page.')+'</p>');el('geoi-note').textContent=d.note||'';return;}
 const last=d.trend[d.trend.length-1]||{all:{}},dl=last.delta_share_of_voice;
 el('geoi-stats').innerHTML=[[L('إجابات في العينة','Answers in sample'),d.answers,CONF[d.confidence]+(d.stale?' · '+L('قديم','Stale'):'')],[L('نسبة ذكرك (آخر أسبوع)','Your mention rate (latest week)'),pct(last.all.share_of_voice),dl===null||dl===undefined?'':(dl>0?'▲ ':dl<0?'▼ ':'')+Math.round(Math.abs(dl)*100)+L(' نقطة عن الأسبوع قبله',' pts vs previous week')],[L('نسبة استشهاد موقعك','Your citation rate'),pct(last.all.citation_rate),L('على الإجابات اللي فيها بيانات استشهاد','Of answers that return citation data')],[L('آخر قياس','Last measured'),esc(String(d.as_of).slice(0,10)),'']].map(s=>'<div class="gi-box" style="margin:0"><div class="gi-muted">'+s[0]+'</div><div class="gi-stat">'+s[1]+'</div><div class="gi-muted">'+s[2]+'</div></div>').join('');
 el('geoi-chart').innerHTML=chart(d.trend);
 el('geoi-recs').innerHTML=d.recommendations.length?d.recommendations.map(r=>'<div class="gi-rec"><div class="gi-q"><span class="gi-badge">'+(r.priority==='high'?L('أولوية عالية','High'):L('متوسطة','Medium'))+'</span>'+esc(r.question)+'</div><div>'+esc(ar?r.title_ar:r.title_en)+'</div><div class="gi-muted">'+esc(ar?r.action_ar:r.action_en)+'</div><div class="gi-muted">'+L('الدليل: ذُكرت في ','Evidence: mentioned in ')+r.evidence.providers_mentioned+'/'+r.evidence.providers_measured+L(' مزوّد','  providers')+(r.evidence.competitors.length?' · '+L('منافسين: ','competitors: ')+esc(r.evidence.competitors.join(', ')):'')+'</div><a class="p-btn outline" href="'+esc(safeUrl(r.url))+'">'+L('افتح','Open')+'</a></div>').join(''):'<p class="gi-muted">'+L('مفيش توصيات: كل الأسئلة المقاسة بتذكرك، أو مفيش دليل كافي.','No recommendations: every measured question mentions you, or there is not enough evidence.')+'</p>';
 el('geoi-comp').innerHTML=table([L('المنافس','Competitor'),L('في كام إجابة','Answers'),L('نسبته','Share'),L('بدونك','Without you'),L('مسجّل عندك','Registered')],[`<tr><td><strong>${L('إنت','You')}</strong></td><td>${d.competitors.brand.answers}</td><td>${pct(d.competitors.brand.share)}</td><td>—</td><td>—</td></tr>`].concat(d.competitors.list.map(c=>'<tr><td>'+esc(c.name)+(c.domain?' <span class="gi-muted">'+esc(c.domain)+'</span>':'')+'</td><td>'+c.answers+'</td><td>'+pct(c.share)+'</td><td>'+c.mentioned_without_us+'</td><td>'+(c.registered?'✓':'—')+'</td></tr>')));
 el('geoi-cit').innerHTML=table([L('النطاق','Domain'),L('في كام إجابة','Answers'),L('النوع','Type')],d.citations.domains.map(c=>'<tr><td>'+esc(c.domain)+'</td><td>'+c.answers+' ('+pct(c.share)+')</td><td>'+(c.kind==='own'?'<span class="gi-ok">'+L('موقعك','Your site')+'</span>':c.kind==='competitor'?'<span class="gi-warn">'+L('منافس','Competitor')+'</span>':L('آخر','Other'))+'</td></tr>'));
 el('geoi-prompts').innerHTML=table([L('السؤال','Question'),'ChatGPT','Gemini','Perplexity','Claude',L('الحالة','Status')],d.prompts.map(p=>'<tr><td>'+esc(p.question)+'</td>'+['openai','gemini','perplexity','claude'].map(k=>{const x=p.providers[k];return '<td>'+(x?(x.mentioned?'<span class="gi-ok">✓'+(x.cited===false?'':'')+'</span>':'<span class="gi-bad">✗</span>')+(x.cited===true?' 🔗':''):'—')+'</td>';}).join('')+'<td>'+(p.status==='won'?L('بيذكروك','Won'):p.status==='lost'?L('مش بيذكروك','Lost'):L('جزئي','Partial'))+'</td></tr>'));
 el('geoi-note').textContent=d.note||'';}
let epoch=0;
async function load(wid){const my=++epoch;msg(L('جارٍ التحميل…','Loading…'));el('geoi-body').hidden=true;
 try{const r=await window.Panel.fetchJSON('/api/geo/insights?website_id='+encodeURIComponent(wid));if(my!==epoch)return;if(!r.success)throw Error(r.error||r.message);msg('');render(r.data);}
 catch(e){if(my!==epoch)return;msg((e&&e.message)||L('تعذر التحميل. جرّب تاني.','Could not load. Try again.'),true);}}
const start=async()=>{try{const r=await window.Panel.fetchJSON('/api/websites');const list=(r.data&&r.data.websites)||[],sel=el('geoi-site');
 if(!list.length){sel.innerHTML='<option>'+L('مفيش مواقع','No sites')+'</option>';msg(L('ضيف موقع الأول.','Add a site first.'));return;}
 sel.innerHTML=list.map(w=>'<option value="'+esc(w.id)+'">'+esc(w.company_name||w.brand_name||w.main_url||w.id)+'</option>').join('');sel.disabled=false;sel.onchange=()=>load(sel.value);load(sel.value);}
 catch(e){msg(L('تعذر تحميل المواقع.','Could not load sites.'),true);}};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
</script>
