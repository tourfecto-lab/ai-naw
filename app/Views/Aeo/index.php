<?php
$ar = function_exists('current_lang') ? current_lang() === 'ar' : true;
$t = static fn (string $a, string $e): string => htmlspecialchars($ar ? $a : $e, ENT_QUOTES, 'UTF-8');
?>
<style>
#aeo-root{--aeo-border:var(--panel-border,#29354b);padding:clamp(14px,3vw,26px);color:var(--panel-text,#f2f4f8);line-height:1.7}#aeo-root *{box-sizing:border-box}#aeo-root [hidden]{display:none!important}
.aeo-muted{color:var(--panel-muted,#a9b4c8);font-size:.92rem}.aeo-box{border:1px solid var(--aeo-border);border-radius:14px;padding:16px;margin:16px 0}
.aeo-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}.aeo-row label{flex:1 1 220px}.aeo-row input,.aeo-row select{width:100%}
.aeo-pair{border:1px solid var(--aeo-border);border-radius:12px;padding:12px;margin:10px 0;display:grid;gap:6px}.aeo-pair input[type=text],.aeo-pair textarea{width:100%}
.aeo-ev{font-size:.82rem;color:var(--panel-muted,#a9b4c8)}.aeo-ev code{direction:ltr;unicode-bidi:embed}.aeo-err{color:#ff8a80}.aeo-ok{color:#7ee0a1}.aeo-warn{color:#f5c26b}
pre#aeo-code{direction:ltr;text-align:left;overflow:auto;max-height:300px;font-size:.8rem;border:1px solid var(--aeo-border);border-radius:10px;padding:10px;white-space:pre-wrap}
#aeo-root :focus-visible{outline:2px solid #6ea8ff;outline-offset:2px}
</style>
<section class="p-card" dir="<?= $ar ? 'rtl' : 'ltr' ?>" id="aeo-root" data-lang="<?= $ar ? 'ar' : 'en' ?>" data-csrf="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
<h2><?= $t('مولّد الإجابات (AEO)', 'Answer generator (AEO)') ?></h2>
<p class="aeo-muted"><?= $t('بنقرأ صفحة رحلة من موقعك ونطلّع أسئلة وإجابات من الحقائق المكتوبة فيها فعلًا (مدة، سعر، إلغاء، استقبال، مرشد). كل إجابة معاها الدليل من الصفحة، ومفيش حاجة بتتخترع. أنت اللي بتراجع وبتوافق، ومفيش نشر تلقائي. الاستخراج للصفحات الإنجليزية والعربية (العربي لسه ما اتجرّب على صفحة حقيقية)، وبدون تكلفة.', 'We read a trip page on your site and draft questions and answers from facts actually written on it (duration, price, cancellation, pick-up, guide). Each answer shows its evidence from the page; nothing is invented. You review and approve; nothing is published automatically. Extraction supports English and Arabic pages (Arabic is not yet validated on a real page), at no cost.') ?></p>
<div class="aeo-box"><div class="aeo-row"><label class="aeo-muted"><?= $t('الموقع', 'Site') ?><select class="p-select" id="aeo-site" disabled><option><?= $t('جارٍ التحميل…', 'Loading…') ?></option></select></label>
<label class="aeo-muted"><?= $t('رابط صفحة الرحلة (https ومن نفس الموقع)', 'Trip page URL (https, same site)') ?><input class="p-input" id="aeo-url" type="url" inputmode="url" placeholder="https://"></label>
<button type="button" class="p-btn" id="aeo-draft"><?= $t('طلّع مسودة', 'Draft answers') ?></button></div>
<div id="aeo-status" role="status" aria-live="polite" class="aeo-muted"></div></div>
<div class="aeo-box"><h3><?= $t('تغطية صفحات الرحلات', 'Trip pages coverage') ?></h3>
<p class="aeo-muted"><?= $t('بنفحص دفعة صغيرة (5 صفحات) من صفحات الرحلات في آخر دورة SEO Expert ونسجّل هل عليها FAQPage. النتيجة بتظهر في لوحة الظهور كدرجة AEO.', 'We scan a small batch (5 pages) of trip pages from your latest SEO Expert cycle and record whether each has FAQPage. The result feeds the AEO score in the visibility dashboard.') ?></p>
<div class="aeo-row"><button type="button" class="p-btn outline" id="aeo-scan"><?= $t('افحص الدفعة الجاية', 'Scan next batch') ?></button></div>
<div id="aeo-scan-out" role="status" aria-live="polite" class="aeo-muted"></div></div>
<div id="aeo-review" hidden><h3><?= $t('راجع وعدّل ووافق', 'Review, edit and approve') ?></h3><div id="aeo-pairs"></div><div id="aeo-missing" class="aeo-muted"></div>
<div class="aeo-row"><button type="button" class="p-btn" id="aeo-build"><?= $t('ابنِ FAQ Schema من الموافَق عليه', 'Build FAQ schema from approved') ?></button></div>
<div id="aeo-out" role="status" aria-live="polite"></div></div>
</section>
<script>
(()=>{'use strict';
const root=document.getElementById('aeo-root'),ar=root.dataset.lang==='ar',el=id=>document.getElementById(id),L=(a,e)=>ar?a:e,csrf=root.dataset.csrf;
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const post=(path,body)=>window.Panel.fetchJSON(path,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...body,csrf_token:csrf})});
const STATE={unsupported_language:L('لغة الصفحة مش مدعومة (الإنجليزي والعربي بس).','Unsupported page language (English and Arabic only).'),
 no_trip_found:L('مش لاقيين رحلة (TouristTrip/Product) في Schema الصفحة دي.','No trip (TouristTrip/Product) found in this page\'s schema.'),
 no_stated_facts:L('مفيش حقائق مكتوبة بوضوح نقدر نطلّع منها إجابات (مدة، سعر، إلغاء…).','No clearly stated facts to draft answers from (duration, price, cancellation…).')};
const status=(t,cls)=>{const s=el('aeo-status');s.className='aeo-muted '+(cls||'');s.textContent=t||'';};
async function guard(btn,fn){btn.disabled=true;try{await fn();}catch(e){status((e&&e.message)||L('تعذر إتمام الطلب.','Request failed.'),'aeo-err');}finally{btn.disabled=false;}}
el('aeo-draft').onclick=e=>guard(e.currentTarget,async()=>{const u=el('aeo-url').value.trim(),w=el('aeo-site').value;if(!u||!w)throw Error(L('اختار الموقع واكتب رابط الصفحة.','Choose a site and enter a page URL.'));
 status(L('جارٍ قراءة الصفحة…','Reading the page…'));el('aeo-review').hidden=true;el('aeo-out').innerHTML='';el('aeo-missing').innerHTML='';
 const r=await post('/api/aeo/draft',{website_id:Number(w),url:u});if(!r.success)throw Error(r.error||r.message);const d=r.data;
 const rd=d.readiness||{};status((rd.has_faq_schema?L('الصفحة فيها FAQPage بالفعل. ','The page already has FAQPage. '):'')+(d.state==='drafted'?'':(STATE[d.state]||'')),d.state==='drafted'?'':'aeo-warn');
 if(d.state!=='drafted'){el('aeo-pairs').innerHTML='';el('aeo-missing').innerHTML=(d.missing||[]).length?'<h4>'+L('بيانات ناقصة','Missing data')+'</h4><ul>'+d.missing.map(m=>'<li class="aeo-warn">'+esc(ar?m.add_ar:m.add_en)+'</li>').join('')+'</ul>':'';el('aeo-review').hidden=!(d.missing||[]).length;el('aeo-build').hidden=true;return;}
 el('aeo-build').hidden=false;
 el('aeo-pairs').innerHTML=d.pairs.map((p,i)=>'<div class="aeo-pair"><label><input type="checkbox" data-i="'+i+'" checked> '+L('موافق','Approve')+'</label>'+
  '<input type="text" data-q="'+i+'" value="'+esc(p.question)+'" maxlength="160" aria-label="'+L('السؤال','Question')+'">'+
  '<textarea data-a="'+i+'" rows="2" maxlength="600" aria-label="'+L('الإجابة','Answer')+'">'+esc(p.answer)+'</textarea>'+
  '<div class="aeo-ev">'+L('المصدر','Source')+': <code>'+esc(p.source)+'</code><br>'+L('الدليل من الصفحة','Evidence from the page')+': <code data-ev="'+i+'">'+esc(p.evidence)+'</code></div></div>').join('');
 el('aeo-missing').innerHTML=(d.missing||[]).length?'<h4>'+L('بيانات ناقصة (ما اتولّدش ليها إجابة)','Missing data (no answer generated)')+'</h4><ul>'+d.missing.map(m=>'<li class="aeo-warn">'+esc(ar?m.add_ar:m.add_en)+'</li>').join('')+'</ul>':'';
 el('aeo-review').hidden=false;});
el('aeo-build').onclick=e=>guard(e.currentTarget,async()=>{const pairs=[...document.querySelectorAll('#aeo-pairs .aeo-pair')].filter(x=>x.querySelector('input[type=checkbox]').checked).map(x=>({question:x.querySelector('input[type=text]').value,answer:x.querySelector('textarea').value,evidence:x.querySelector('code[data-ev]').textContent}));
 if(!pairs.length)throw Error(L('وافق على سؤال واحد على الأقل.','Approve at least one question.'));status('');
 const r=await post('/api/aeo/build',{pairs});if(!r.success)throw Error(r.error||r.message);const d=r.data,v=d.validation||{};
 el('aeo-out').innerHTML='<p>'+(v.valid?'<strong class="aeo-ok">'+L('صالح','Valid')+'</strong>':'<strong class="aeo-err">'+L('فيه أخطاء','Has errors')+'</strong>')+' · '+d.count+' '+L('سؤال','questions')+'</p>'+
  '<p class="aeo-muted">'+L('انسخ الكود وضيفه في صفحتك، وانشر نفس الأسئلة والإجابات ظاهرة في نص الصفحة. النشر الفعلي بزر صريح لسه مش متاح من هنا، والنسخ يدوي بعد مراجعتك.','Copy the code into your page and publish the same questions and answers visibly in the page text. One-click publishing is not available here yet; copying is manual after your review.')+'</p>'+((d.rejected||[]).length?'<p class="aeo-err">'+L('اترفض بعض الأسئلة (رقم مش موجود في الدليل أو رابط أو فاضي): ','Some items were rejected (number not in the evidence, link, or empty): ')+d.rejected.map(r=>'#'+(r.index+1)+' '+esc(r.reason)+(r.numbers?' ['+esc(r.numbers.join(', '))+']':'')).join('; ')+'</p>':'')+'<pre id="aeo-code">'+esc(d.script)+'</pre><p class="aeo-muted">'+esc(d.note)+'</p>';});
el('aeo-scan').onclick=e=>guard(e.currentTarget,async()=>{const w=el('aeo-site').value;if(!w)throw Error(L('اختار الموقع.','Choose a site.'));el('aeo-scan-out').textContent=L('جارٍ الفحص… ممكن ياخد لحد دقيقة.','Scanning… may take up to a minute.');
 const r=await post('/api/aeo/scan',{website_id:Number(w)});if(!r.success)throw Error(r.error||r.message);const d=r.data,m=d.measurement;
 el('aeo-scan-out').textContent=L('اتفحص ','Scanned ')+d.scanned_now+L(' صفحة دلوقتي. ',' page(s) now. ')+(m?L('تغطية FAQ: ','FAQ coverage: ')+m.score+'% ('+m.pages_with_faq+'/'+m.pages+L(' صفحة رحلة مفحوصة؛ المرشّحة ',' trip pages scanned; candidates ')+d.candidate_pages+L('، ثقة ','), confidence ')+m.confidence+'.':'')+(d.skipped_not_trip?L(' اتستبعد ',' Excluded ')+d.skipped_not_trip+L(' صفحة مش رحلة (قسم/مقال).',' non-trip page(s) (category/article).'):'');});
const start=async()=>{try{const r=await window.Panel.fetchJSON('/api/websites');const list=(r.data&&r.data.websites)||[],sel=el('aeo-site');
 sel.innerHTML=list.length?list.map(w=>'<option value="'+esc(w.id)+'">'+esc(w.company_name||w.brand_name||w.main_url||w.id)+'</option>').join(''):'<option value="">'+L('مفيش مواقع','No sites')+'</option>';sel.disabled=!list.length;}
 catch(e){el('aeo-site').innerHTML='<option value="">'+L('تعذر التحميل','Could not load')+'</option>';}};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
</script>
