<?php
$ar = function_exists('current_lang') ? current_lang() === 'ar' : true;
$t = static fn (string $a, string $e): string => htmlspecialchars($ar ? $a : $e, ENT_QUOTES, 'UTF-8');
?>
<style>
#sch-root{--sch-border:var(--panel-border,#29354b);padding:clamp(14px,3vw,26px);color:var(--panel-text,#f2f4f8);line-height:1.7}#sch-root *{box-sizing:border-box}#sch-root [hidden]{display:none!important}
.sch-muted{color:var(--panel-muted,#a9b4c8);font-size:.92rem}.sch-box{border:1px solid var(--sch-border);border-radius:14px;padding:16px;margin:16px 0}
#sch-json{width:100%;min-height:200px;font-family:ui-monospace,Consolas,monospace;font-size:.85rem;direction:ltr;text-align:left}
.sch-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}.sch-row label{flex:1 1 220px}.sch-row input,.sch-row select{width:100%}
.sch-badge{display:inline-block;border:1px solid var(--sch-border);border-radius:999px;padding:1px 10px;font-size:.8rem;margin-inline-end:6px}
.sch-err{color:#ff8a80}.sch-warn{color:#f5c26b}.sch-ok{color:#7ee0a1}.sch-list{list-style:none;margin:8px 0 0;padding:0;display:grid;gap:8px}.sch-list li{border:1px solid var(--sch-border);border-radius:10px;padding:8px 12px}
.sch-list code{direction:ltr;unicode-bidi:embed;font-size:.8rem}#sch-root :focus-visible{outline:2px solid #6ea8ff;outline-offset:2px}
pre#sch-proposed{direction:ltr;text-align:left;overflow:auto;max-height:340px;font-size:.8rem;border:1px solid var(--sch-border);border-radius:10px;padding:10px}
</style>
<section class="p-card" dir="<?= $ar ? 'rtl' : 'ltr' ?>" id="sch-root" data-lang="<?= $ar ? 'ar' : 'en' ?>" data-csrf="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
<h2><?= $t('فحص ومعاينة Schema', 'Schema check and preview') ?></h2>
<p class="sch-muted"><?= $t('الفحص بيراجع البنية والخصائص المطلوبة لأنواع السياحة (رحلات، فنادق، أسئلة شائعة، تقييمات…). النجاح هنا مش ضمان إن جوجل يعرض نتيجة مميزة. مفيش نشر من الصفحة دي.', 'The check reviews structure and required properties for tourism types (trips, hotels, FAQ, reviews…). Passing is not a guarantee that Google shows a rich result. Nothing is published from this page.') ?></p>

<div class="sch-box"><h3><?= $t('١. اتحقق من JSON-LD', '1. Validate JSON-LD') ?></h3>
<label for="sch-json" class="sch-muted"><?= $t('الصق الكود (ممكن يكون جوه وسم script)', 'Paste the code (may include the script tag)') ?></label>
<textarea id="sch-json" class="p-input" spellcheck="false" maxlength="200000"></textarea>
<div class="sch-row"><button type="button" class="p-btn" id="sch-validate"><?= $t('افحص', 'Validate') ?></button></div>
<div id="sch-result" role="status" aria-live="polite"></div></div>

<div class="sch-box"><h3><?= $t('٢. معاينة المقترح لصفحة من موقعك', '2. Preview the proposal for a page on your site') ?></h3>
<div class="sch-row"><label class="sch-muted"><?= $t('الموقع', 'Site') ?><select class="p-select" id="sch-site" disabled><option><?= $t('جارٍ التحميل…', 'Loading…') ?></option></select></label>
<label class="sch-muted"><?= $t('رابط الصفحة (https ومن نفس الموقع)', 'Page URL (https, same site)') ?><input class="p-input" id="sch-url" type="url" inputmode="url" placeholder="https://"></label>
<button type="button" class="p-btn" id="sch-preview"><?= $t('عاين', 'Preview') ?></button></div>
<div id="sch-preview-out" role="status" aria-live="polite"></div></div>
</section>
<script>
(()=>{'use strict';
const root=document.getElementById('sch-root'),ar=root.dataset.lang==='ar',el=id=>document.getElementById(id),L=(a,e)=>ar?a:e,csrf=root.dataset.csrf;
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const post=(path,body)=>window.Panel.fetchJSON(path,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...body,csrf_token:csrf})});
function report(v){const head=v.valid?'<strong class="sch-ok">'+L('صالح للنشر','Valid for publishing')+'</strong>':'<strong class="sch-err">'+L('فيه أخطاء لازم تتصلح','Errors must be fixed')+'</strong>';
 const types=(v.types||[]).map(t=>'<span class="sch-badge">'+esc(t)+'</span>').join('');
 const li=(v.issues||[]).map(i=>'<li><span class="'+(i.severity==='error'?'sch-err':'sch-warn')+'">'+(i.severity==='error'?L('خطأ','Error'):L('تنبيه','Warning'))+'</span> '+esc(ar?i.message_ar:i.message_en)+(i.detail?' <code>'+esc(i.detail)+'</code>':'')+' <code>'+esc(i.path)+'</code></li>').join('');
 return '<p>'+head+' · '+v.errors+' '+L('أخطاء','errors')+' · '+v.warnings+' '+L('تنبيهات','warnings')+'</p><p>'+types+'</p><ul class="sch-list">'+li+'</ul>';}
async function run(btn,out,fn){btn.disabled=true;out.innerHTML='<p class="sch-muted">'+L('جارٍ الفحص…','Checking…')+'</p>';
 try{out.innerHTML=await fn();}catch(e){out.innerHTML='<p class="sch-err">'+esc((e&&e.message)||L('تعذر إتمام الطلب.','Request failed.'))+'</p>';}finally{btn.disabled=false;}}
el('sch-validate').onclick=e=>run(e.currentTarget,el('sch-result'),async()=>{const t=el('sch-json').value.trim();if(!t)throw Error(L('الصق كود JSON-LD الأول.','Paste JSON-LD first.'));
 const r=await post('/api/seo/schema/validate',{jsonld:t});if(!r.success)throw Error(r.error||r.message);return report(r.data);});
el('sch-preview').onclick=e=>run(e.currentTarget,el('sch-preview-out'),async()=>{const u=el('sch-url').value.trim(),w=el('sch-site').value;if(!u||!w)throw Error(L('اختار الموقع واكتب رابط الصفحة.','Choose a site and enter a page URL.'));
 const r=await post('/api/seo/schema/preview',{website_id:Number(w),url:u});if(!r.success)throw Error(r.error||r.message);const d=r.data;
 const ex=(d.existing_types||[]).map(t=>'<span class="sch-badge">'+esc(t)+'</span>').join('')||'<span class="sch-muted">'+L('مفيش Schema موجود','No schema found')+'</span>';
 let h='<p><strong>'+L('الموجود حاليًا','Currently on the page')+':</strong> '+ex+'</p>';
 h+=(d.existing_invalid_blocks?'<p class="sch-err">'+d.existing_invalid_blocks+' '+L('كتلة JSON-LD على الصفحة مش صالحة (JSON مكسور).','JSON-LD block(s) on the page are not valid JSON.')+'</p>':'')+(d.existing_validation||[]).map((v,i)=>'<h4>'+L('فحص الموجود فعلًا على الصفحة','Check of the markup already on the page')+' #'+(i+1)+'</h4>'+report(v)).join('');
 h+=d.proposed?'<p><strong>'+L('المقترح (معاينة فقط)','Proposed (preview only)')+'</strong></p>'+report(d.proposed.validation)+'<pre id="sch-proposed">'+esc(JSON.stringify(d.proposed.jsonld,null,2))+'</pre>':'<p class="sch-muted">'+L('مفيش إضافة مقترحة للصفحة دي (الموجود كافي أو الصفحة مش مناسبة).','No addition proposed for this page (existing markup is enough or the page does not fit).')+'</p>';
 return h+'<p class="sch-muted">'+esc(d.note)+'</p>';});
const start=async()=>{try{const r=await window.Panel.fetchJSON('/api/websites');const list=(r.data&&r.data.websites)||[],sel=el('sch-site');
 sel.innerHTML=list.length?list.map(w=>'<option value="'+esc(w.id)+'">'+esc(w.company_name||w.brand_name||w.main_url||w.id)+'</option>').join(''):'<option value="">'+L('مفيش مواقع','No sites')+'</option>';sel.disabled=!list.length;}
 catch(e){el('sch-site').innerHTML='<option value="">'+L('تعذر التحميل','Could not load')+'</option>';}};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
</script>
