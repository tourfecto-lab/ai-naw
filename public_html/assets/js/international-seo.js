(async()=>{
'use strict';
const el=id=>document.getElementById(id),json=x=>JSON.stringify(x,null,2);let current=null,sites=[],busy=false;
async function api(op,data,query=''){const r=await window.Panel.fetchJSON('/api/international-seo/'+op+query,data?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)}:{});if(!r.success)throw Error(r.error||r.message||'تعذر الطلب');return r.data;}
async function work(fn){if(busy)return;busy=true;for(const b of document.querySelectorAll('#internationalSeo button'))b.disabled=true;try{await fn();}catch(e){el('intlStatus').textContent=e.message;}finally{busy=false;for(const b of document.querySelectorAll('#internationalSeo button'))b.disabled=false;}}
function version(){el('intlReviewed').checked=false;const v=current?.versions.find(v=>v.language===el('intlLanguage').value);el('intlFields').value=v?v.fields_json:json(current?.fields||{});}
async function load(){current=await api('data',null,'?generated_website_id='+Number(el('intlBuilder').value));el('intlSource').textContent=json(current.source);el('intlVersions').textContent=current.versions.map(v=>v.language+': '+v.status+' · '+(v.current?'الأصل لم يتغير':'تحتاج مراجعة الأصل الجديد')).join('\n');el('intlConfirmed').checked=false;version();}
function payload(){if(!current)throw Error('حمّل المحتوى أولًا');if(!el('intlConfirmed').checked)throw Error('أكّد المحتوى الأصلي أولًا');return {generated_website_id:Number(el('intlBuilder').value),source_hash:current.source_hash,language:el('intlLanguage').value,confirmed:true};}
async function save(publish){const p=payload();if(publish&&!el('intlReviewed').checked)throw Error('المراجعة البشرية مطلوبة');await api(publish?'publish':'draft',{...p,fields:JSON.parse(el('intlFields').value),reviewed:el('intlReviewed').checked});await load();el('intlStatus').textContent=publish?'نُشرت النسخة المُراجعة وأضيفت لخريطة الموقع':'حُفظت المسودة دون نشر';}
function site(){return Number(el('intlSite').value);}
async function sitemap(){const r=await api('sitemapData',null,'?website_id='+site());el('intlSitemapResult').textContent=json(r);el('intlSitemap').value=r.permission?.sitemap_url||String(sites.find(s=>Number(s.id)===site())?.main_url||'').replace(/\/$/,'')+'/sitemap.xml';el('intlReconnect').action='/search-console/allow-sitemap-submit/'+site();}
el('intlLoad').onclick=()=>work(load);el('intlBuilder').onchange=()=>work(load);el('intlLanguage').onchange=version;
el('intlFields').oninput=()=>{el('intlReviewed').checked=false;};
el('intlGenerate').onclick=()=>work(async()=>{el('intlFields').value=json((await api('generate',payload())).fields);el('intlReviewed').checked=false;el('intlStatus').textContent='المسودة جاهزة للمراجعة؛ لم تُنشر';});
el('intlDraft').onclick=()=>work(()=>save(false));el('intlPublish').onclick=()=>work(()=>save(true));
el('intlSite').onchange=()=>work(sitemap);el('intlSitemapLoad').onclick=()=>work(sitemap);
el('intlSubmit').onclick=()=>work(async()=>{await api('submit',{website_id:site(),sitemap_url:el('intlSitemap').value});await sitemap();});
el('intlDisable').onclick=()=>work(async()=>{await api('disable',{website_id:site()});await sitemap();});
el('intlCsrf').value=document.querySelector('meta[name="csrf-token"]')?.content||'';
await work(async()=>{const r=await api('sites');sites=r.sites;for(const s of r.builders)el('intlBuilder').add(new Option(s.slug+' · '+s.status,s.id));for(const s of sites)el('intlSite').add(new Option(s.main_url,s.id));if(r.builders.length)await load();if(sites.length)await sitemap();});
})();
