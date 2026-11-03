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
pre#aeo-code,pre#aeo-html{direction:ltr;text-align:left;overflow:auto;max-height:300px;font-size:.8rem;border:1px solid var(--aeo-border);border-radius:10px;padding:10px;white-space:pre-wrap}
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
<p class="aeo-muted"><?= $t('بنفحص دفعة صغيرة (8 صفحات) من صفحات الرحلات في آخر دورة SEO Expert ونسجّل هل عليها FAQPage. النتيجة بتظهر في لوحة الظهور كدرجة AEO.', 'We scan a small batch (8 pages) of trip pages from your latest SEO Expert cycle and record whether each has FAQPage. The result feeds the AEO score in the visibility dashboard.') ?></p>
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
el('aeo-draft').onclick=e=>guard(e.currentTarget,async()=>{const u=el('aeo-url').value.trim(),w=el('aeo-site').disabled?'':el('aeo-site').value;if(!u||!w)throw Error(L('اختار الموقع واكتب رابط الصفحة.','Choose a site and enter a page URL.'));
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
  '<p class="aeo-muted">'+L('خطوتين وبيشتغلوا على أي منصة: (1) حط النص الظاهر في صفحة الرحلة نفسها، (2) حط كود الـSchema في الصفحة. لازم الاتنين يكونوا نفس الأسئلة والإجابات. النشر الفعلي يدوي بعد مراجعتك، ومفيش حاجة بتتنشر من هنا.','Two steps that work on any platform: (1) add the visible text to the trip page itself, (2) add the Schema code to the page. Both must carry the same questions and answers. Publishing is manual after your review; nothing is published from here.')+'</p>'+((d.rejected||[]).length?'<p class="aeo-err">'+L('اترفض بعض الأسئلة (رقم مش موجود في الدليل أو رابط أو فاضي): ','Some items were rejected (number not in the evidence, link, or empty): ')+d.rejected.map(r=>'#'+(r.index+1)+' '+esc(r.reason)+(r.numbers?' ['+esc(r.numbers.join(', '))+']':'')).join('; ')+'</p>':'')+'<h4>'+L('1) النص الظاهر في الصفحة','1) Visible text for the page')+'</h4><pre id="aeo-html">'+esc(d.html)+'</pre><button type="button" class="p-btn outline" data-copy="aeo-html">'+L('انسخ النص','Copy text')+'</button>'+
  '<h4>'+L('2) كود الـSchema','2) Schema code')+'</h4><pre id="aeo-code">'+esc(d.script)+'</pre><button type="button" class="p-btn outline" data-copy="aeo-code">'+L('انسخ الكود','Copy code')+'</button>'+
  '<details class="aeo-muted"><summary>'+L('إزاي أضيفهم حسب منصة موقعي؟','How do I add them on my platform?')+'</summary><ul>'+
  '<li><strong>WordPress:</strong> '+L('عدّل الصفحة، أضف بلوك "HTML مخصص" وحط فيه النص ثم الكود (أو استخدم إضافة السيو اللي بتسمح بكود في الصفحة).','edit the page, add a "Custom HTML" block and paste the text, then the code (or use your SEO plugin if it allows page-level code).')+'</li>'+
  '<li><strong>Shopify:</strong> '+L('عدّل الصفحة أو المنتج، اضغط "&lt;&gt;" لعرض HTML وحط النص ثم الكود.','edit the page or product, click "&lt;&gt;" to show HTML, then paste the text and the code.')+'</li>'+
  '<li><strong>Wix / Squarespace:</strong> '+L('أضف عنصر "Embed/Code" في الصفحة وحط النص ثم الكود (أو من إعدادات SEO للصفحة لو متاحة).','add an Embed/Code element to the page and paste the text and the code (or use the page SEO settings if available).')+'</li>'+
  '<li>'+L('موقع مخصص: حط النص في قالب الصفحة والكود قبل &lt;/body&gt;.','Custom site: put the text in the page template and the code before &lt;/body&gt;.')+'</li></ul>'+
  '<p>'+L('الخطوات دي اتكتبت من معرفة عامة بالمنصات، وواجهتها بتتغيّر؛ لو الخيار مش موجود عندك راجع دليل المنصة.','These steps are written from general knowledge of the platforms and their interfaces change; if an option is missing, check the platform guide.')+'</p></details>'+
  '<p class="aeo-muted">'+esc(d.note)+'</p>';
 el('aeo-out').querySelectorAll('[data-copy]').forEach(b=>b.onclick=async()=>{const src=el(b.dataset.copy).textContent;try{await navigator.clipboard.writeText(src);b.textContent=L('اتنسخ','Copied');}catch(_){const r=document.createRange();r.selectNodeContents(el(b.dataset.copy));const s=getSelection();s.removeAllRanges();s.addRange(r);b.textContent=L('اتحدد؛ اضغط Ctrl+C','Selected; press Ctrl+C');}});});
el('aeo-scan').onclick=e=>guard(e.currentTarget,async()=>{const w=el('aeo-site').disabled?'':el('aeo-site').value;if(!w)throw Error(L('اختار الموقع.','Choose a site.'));el('aeo-scan-out').textContent=L('جارٍ الفحص… ممكن ياخد لحد دقيقة.','Scanning… may take up to a minute.');
 const r=await post('/api/aeo/scan',{website_id:Number(w)});if(!r.success)throw Error(r.error||r.message);const d=r.data,m=d.measurement;
 el('aeo-scan-out').textContent=L('اتفحص ','Scanned ')+d.scanned_now+L(' صفحة دلوقتي. ',' page(s) now. ')+(m?L('تغطية FAQ: ','FAQ coverage: ')+m.score+'% ('+m.pages_with_faq+'/'+m.pages+L(' صفحة رحلة مفحوصة؛ المرشّحة ',' trip pages scanned; candidates ')+d.candidate_pages+L('، ثقة ','), confidence ')+m.confidence+'.':'')+(d.skipped_not_trip?L(' اتستبعد ',' Excluded ')+d.skipped_not_trip+L(' صفحة مش رحلة (قسم/مقال).',' non-trip page(s) (category/article).'):'');});
const start=async()=>{try{const r=await window.Panel.fetchJSON('/api/websites');const list=(r.data&&r.data.websites)||[],sel=el('aeo-site');
 sel.innerHTML=list.length?list.map(w=>'<option value="'+esc(w.id)+'">'+esc(w.company_name||w.brand_name||w.main_url||w.id)+'</option>').join(''):'<option value="">'+L('مفيش مواقع','No sites')+'</option>';sel.disabled=!list.length;}
 catch(e){el('aeo-site').innerHTML='<option value="">'+L('تعذر التحميل','Could not load')+'</option>';}};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
</script>
