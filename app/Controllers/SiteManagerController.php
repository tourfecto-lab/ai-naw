<?php

/**
 * Tourfecto - /site-admin: the dashboard of ONE generated website for a person who has a site-manager login
 * (client / agency / employee), without a Tourfecto account.
 *
 * Isolation: the login lives in $_SESSION['site_manager'] only; $_SESSION['user'] is never set, so no other part of the
 * platform accepts it. Only the handlers routed below are reachable, each re-reads the account (active? role?) from the
 * database and is pinned to the account's own website id (an id sent by the browser is ignored).
 */
class SiteManagerController extends SiteDashboardController
{
    private const IDLE_SECONDS = 28800; // 8 h without activity

    private ?array $account = null;

    protected function loadAuthenticatedUser(): void
    {
        $s = $_SESSION['site_manager'] ?? null;
        if (!is_array($s) || empty($s['id']) || empty($s['website_id']) || (time() - (int) ($s['at'] ?? 0)) > self::IDLE_SECONDS) {
            unset($_SESSION['site_manager']);
            return;
        }
        $acc = (new SiteManagerService())->activeAccount((int) $s['id'], (int) $s['website_id']);
        if (!$acc) {
            unset($_SESSION['site_manager']);
            return;
        }
        $_SESSION['site_manager']['at'] = time();
        $this->account = $acc;
        // the inherited handlers scope everything by the owner's id; it is the site owner's data this account may see
        $this->user = ['id' => (int) $acc['owner_user_id'], 'site_manager' => true];
        $this->authenticated = true;
    }

    private function allowed(string $action): ?array
    {
        if (!$this->account) {
            return $this->error('Unauthorized', 401);
        }
        if (!SiteManagerService::can((string) $this->account['role'], $action)) {
            return $this->error('مفيش صلاحية للإجراء ده', 403);
        }
        return null;
    }

    private function pinned(array $extra = []): array
    {
        return ['id' => (int) ($this->account['website_id'] ?? 0)] + $extra;
    }

    // ------------------------------------------------------------ login / logout

    /** GET /site-admin/login */
    public function loginPage(array $params = []): array
    {
        if ($this->account) {
            header('Location: /site-admin');
            exit;
        }
        $csrf = htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8');
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex, nofollow');
        echo <<<HTML
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><meta name="csrf" content="{$csrf}"><title>دخول مدير الموقع</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0f172a;font-family:system-ui,'IBM Plex Sans Arabic',sans-serif;color:#e2e8f0}
form{background:#1e293b;padding:28px;border-radius:16px;width:min(92vw,380px);box-shadow:0 20px 50px rgba(0,0,0,.4)}
h1{margin:0 0 4px;font-size:22px}p{margin:0 0 18px;color:#94a3b8;font-size:14px}
label{display:block;font-size:13px;margin:12px 0 6px;color:#cbd5e1}
input{width:100%;padding:12px;border-radius:10px;border:1px solid #334155;background:#0f172a;color:#fff;font-size:16px}
button{margin-top:18px;width:100%;padding:13px;border:0;border-radius:10px;background:#d4a437;color:#111;font-weight:700;font-size:16px;cursor:pointer}
button:disabled{opacity:.6}#err{color:#fca5a5;font-size:14px;min-height:20px;margin-top:10px}
</style></head><body>
<form id="f" autocomplete="on"><h1>دخول مدير الموقع</h1><p>ادخل بالبيانات اللي صاحب الموقع بعتهالك.</p>
<label for="u">اسم المستخدم</label><input id="u" name="username" autocomplete="username" autocapitalize="none" required dir="ltr">
<label for="p">كلمة السر</label><input id="p" name="password" type="password" autocomplete="current-password" required dir="ltr">
<div id="err" role="alert"></div><button id="b" type="submit">دخول</button></form>
<script>
document.getElementById('f').addEventListener('submit',async function(e){e.preventDefault();
var b=document.getElementById('b'),er=document.getElementById('err');b.disabled=true;er.textContent='';
try{var r=await fetch('/api/site-admin/login',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name=csrf]').content},credentials:'same-origin',
body:JSON.stringify({username:document.getElementById('u').value,password:document.getElementById('p').value})});var j=await r.json().catch(function(){return{}});
if(j.success){location.href='/site-admin';return;}er.textContent=j.error||j.message||'تعذّر الدخول';}catch(x){er.textContent='مشكلة في الاتصال، جرّب تاني';}b.disabled=false;});
</script></body></html>
HTML;
        exit;
    }

    /** POST /api/site-admin/login */
    public function login(array $params = []): array
    {
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        if ($e = $this->rateLimitGuard('ip', 'site_manager_login', 20, 600)) {
            return $e;
        }
        $result = (new SiteManagerService())->authenticate((string) $this->get('username', ''), (string) $this->get('password', ''), (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if (!$result['ok']) {
            return $this->error($result['error'], 401);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['site_manager'] = ['id' => (int) $result['account']['id'], 'website_id' => (int) $result['account']['website_id'], 'at' => time()];
        (new SiteManagerService())->log((int) $result['account']['id'], (int) $result['account']['website_id'], 'login');
        return $this->success(['redirect' => '/site-admin'], 'تم الدخول');
    }

    /** POST /api/site-admin/logout */
    public function logout(array $params = []): array
    {
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        unset($_SESSION['site_manager']);
        return $this->success([], 'تم الخروج');
    }

    // ------------------------------------------------------------ email invitation (choose your own password)

    /** GET /site-admin/invite/{token} */
    public function invitePage(array $params = []): array
    {
        $token = (string) ($params['token'] ?? '');
        $info = (new SiteManagerService())->inviteInfo($token);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        $csrf = htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8');
        $who = htmlspecialchars((string) ($info['display_name'] ?? '') !== '' ? $info['display_name'] : (string) ($info['username'] ?? ''), ENT_QUOTES, 'UTF-8');
        $user = htmlspecialchars((string) ($info['username'] ?? ''), ENT_QUOTES, 'UTF-8');
        $tok = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
        $body = $info
            ? <<<HTML
<form id="f"><h1>أهلاً {$who}</h1><p>اختار كلمة سر لدخولك للوحة الموقع. اسم المستخدم بتاعك: <b dir="ltr">{$user}</b></p>
<label for="p1">كلمة السر (10 حروف على الأقل، فيها حروف وأرقام)</label><input id="p1" type="password" autocomplete="new-password" required dir="ltr">
<label for="p2">تأكيد كلمة السر</label><input id="p2" type="password" autocomplete="new-password" required dir="ltr">
<div id="err" role="alert"></div><button id="b" type="submit">حفظ والدخول</button></form>
<script>
document.getElementById('f').addEventListener('submit',async function(e){e.preventDefault();var er=document.getElementById('err'),b=document.getElementById('b');er.textContent='';
var p1=document.getElementById('p1').value;if(p1!==document.getElementById('p2').value){er.textContent='التأكيد مش مطابق';return;}
b.disabled=true;try{var r=await fetch('/api/site-admin/invite/accept',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name=csrf]').content},credentials:'same-origin',body:JSON.stringify({token:'{$tok}',password:p1})});
var j=await r.json().catch(function(){return{}});if(j.success){location.href='/site-admin/login';return;}er.textContent=j.error||j.message||'تعذّر الحفظ';}catch(x){er.textContent='مشكلة في الاتصال، جرّب تاني';}b.disabled=false;});
</script>
HTML
            : '<div id="f"><h1>الرابط غير صالح</h1><p>رابط الدعوة منتهي أو اتستخدم قبل كده. اطلب من صاحب الموقع يبعتلك دعوة جديدة.</p></div>';
        echo <<<HTML
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><meta name="csrf" content="{$csrf}"><title>دعوة لإدارة موقع</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0f172a;font-family:system-ui,'IBM Plex Sans Arabic',sans-serif;color:#e2e8f0}
#f{background:#1e293b;padding:28px;border-radius:16px;width:min(92vw,400px);box-shadow:0 20px 50px rgba(0,0,0,.4)}
h1{margin:0 0 6px;font-size:22px}p{margin:0 0 16px;color:#94a3b8;font-size:14px;line-height:1.7}
label{display:block;font-size:13px;margin:12px 0 6px;color:#cbd5e1}
input{width:100%;padding:12px;border-radius:10px;border:1px solid #334155;background:#0f172a;color:#fff;font-size:16px}
button{margin-top:18px;width:100%;padding:13px;border:0;border-radius:10px;background:#d4a437;color:#111;font-weight:700;font-size:16px;cursor:pointer}
button:disabled{opacity:.6}#err{color:#fca5a5;font-size:14px;min-height:20px;margin-top:10px}
</style></head><body>{$body}</body></html>
HTML;
        exit;
    }

    /** POST /api/site-admin/invite/accept */
    public function inviteAccept(array $params = []): array
    {
        if (($e = $this->verifyCsrf()) || ($e = $this->rateLimitGuard('ip', 'site_manager_invite', 20, 600))) {
            return $e;
        }
        $r = (new SiteManagerService())->acceptInvite((string) $this->get('token', ''), (string) $this->get('password', ''));
        if (!$r['ok']) {
            return $this->error($r['error'], 422);
        }
        // the person just proved they hold the one-time link and chose their password: sign them in, as the button says
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['site_manager'] = ['id' => $r['account_id'], 'website_id' => $r['website_id'], 'at' => time()];
        (new SiteManagerService())->log($r['account_id'], $r['website_id'], 'login');
        return $this->success(['redirect' => '/site-admin'], 'تم حفظ كلمة السر');
    }

    // ------------------------------------------------------------ the dashboard page

    /** GET /site-admin */
    public function page(array $params = []): array
    {
        if (!$this->account) {
            header('Location: /site-admin/login');
            exit;
        }
        $site = $this->ownedWebsite((int) $this->account['website_id']);
        if (!$site) {
            unset($_SESSION['site_manager']);
            header('Location: /site-admin/login');
            exit;
        }
        $c = $site->getContent();
        $name = htmlspecialchars((string) ($c['business_name'] ?? $site->getAttribute('slug')), ENT_QUOTES, 'UTF-8');
        $who = htmlspecialchars((string) ($this->account['display_name'] ?: $this->account['username']), ENT_QUOTES, 'UTF-8');
        $role = (string) $this->account['role'];
        $caps = json_encode(['content' => SiteManagerService::can($role, 'content'), 'bookings' => SiteManagerService::can($role, 'bookings'), 'leads' => SiteManagerService::can($role, 'leads'), 'reviews' => SiteManagerService::can($role, 'reviews')]);
        $roleLabel = ['manager' => 'مدير', 'editor' => 'محرر', 'viewer' => 'مشاهدة فقط'][$role] ?? $role;
        $csrf = htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8');
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex, nofollow');
        echo <<<HTML
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><meta name="csrf" content="{$csrf}"><title>{$name} - لوحة الموقع</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f1f5f9;font-family:system-ui,'IBM Plex Sans Arabic',sans-serif;color:#0f172a}
header{background:#0f172a;color:#fff;padding:14px 18px;display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap}
header h1{margin:0;font-size:18px}header small{color:#94a3b8}header button{background:#334155;color:#fff;border:0;border-radius:8px;padding:8px 14px;cursor:pointer}
nav{display:flex;gap:6px;padding:12px 18px;overflow-x:auto;background:#fff;border-bottom:1px solid #e2e8f0}
nav button{border:0;background:#e2e8f0;border-radius:999px;padding:9px 16px;cursor:pointer;white-space:nowrap;font-size:14px}nav button.on{background:#d4a437;font-weight:700}
main{padding:16px 18px;max-width:980px;margin:0 auto}
.card{background:#fff;border-radius:14px;padding:14px;margin-bottom:10px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.row{display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:center}
.badge{background:#e2e8f0;border-radius:999px;padding:3px 10px;font-size:12px}.badge.gold{background:#fde68a}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:12px}.stats b{display:block;font-size:22px}
button.act{border:0;border-radius:8px;padding:8px 14px;cursor:pointer;background:#0f172a;color:#fff;margin-inline-end:6px}button.act.out{background:#e2e8f0;color:#0f172a}
input,select{padding:9px;border-radius:8px;border:1px solid #cbd5e1;font-size:14px}p{margin:6px 0;color:#334155;word-break:break-word}.muted{color:#64748b;font-size:13px}
</style></head><body>
<header><div><h1>{$name}</h1><small>{$who} · {$roleLabel}</small></div><div><button id="pw">كلمة السر</button> <button id="out">خروج</button></div></header>
<nav id="tabs"></nav><main id="main"></main>
<script>
(function(){
var CAPS={$caps},csrf=document.querySelector('meta[name=csrf]').content,main=document.getElementById('main'),tab='bookings';
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
async function api(url,opt){opt=opt||{};opt.credentials='same-origin';opt.headers=Object.assign({'Content-Type':'application/json','X-CSRF-Token':csrf},opt.headers||{});
var r=await fetch(url,opt);if(r.status===401){location.href='/site-admin/login';return{success:false};}return r.json().catch(function(){return{success:false};});}
var ST={pending:'قيد الانتظار',confirmed:'مؤكد',cancelled:'ملغي',completed:'مكتمل',no_show:'لم يحضر',new:'جديد',contacted:'تم التواصل',closed:'مغلق',approved:'معتمد',rejected:'مرفوض'};
function tabs(){var t=document.getElementById('tabs');t.innerHTML='';[['bookings','الحجوزات'],['leads','طلبات التواصل'],['reviews','التقييمات'],['analytics','الزيارات']].concat(CAPS.content?[['content','محتوى الموقع']]:[]).forEach(function(x){var b=document.createElement('button');b.textContent=x[1];b.className=tab===x[0]?'on':'';b.onclick=function(){tab=x[0];tabs();load();};t.appendChild(b);});}
async function load(){main.innerHTML='<p class="muted">جاري التحميل...</p>';if(tab==='bookings')return bookings();if(tab==='leads')return leads();if(tab==='analytics')return analytics();if(tab==='content')return content();return reviews();}
async function bookings(){var q=(window.__bq||'');var r=await api('/api/site-admin/bookings?q='+encodeURIComponent(q));if(!r.success){main.innerHTML='<p>تعذّر التحميل</p>';return;}
var s=r.data.stats,h='<div class="stats"><div class="card">قيد الانتظار<b>'+s.pending+'</b></div><div class="card">مؤكدة<b>'+s.confirmed+'</b></div><div class="card">الإيراد المؤكد<b dir="ltr">'+esc(s.revenue)+'</b></div></div>';
h+='<div class="card row"><input id="q" type="search" placeholder="بحث بالاسم / الهاتف / رقم الحجز" value="'+esc(q)+'"><a class="muted" href="/api/site-admin/bookings/export" >تصدير CSV</a></div>';
h+=r.data.bookings.length?r.data.bookings.map(function(b){return '<div class="card"><div class="row"><b>'+esc(b.customer_name)+'</b><span class="badge '+(b.status==='pending'?'gold':'')+'">'+esc(ST[b.status]||b.status)+'</span></div><p>'+esc(b.product_name)+' · '+esc(b.start_date)+' · '+b.adults_count+' بالغ'+(b.children_count>0?' + '+b.children_count+' طفل':'')+' · '+esc(b.total_amount)+' '+esc(b.currency)+'</p><p dir="ltr" style="text-align:right">'+esc(b.customer_phone||'')+(b.customer_email?' - '+esc(b.customer_email):'')+' · #'+esc(b.booking_reference)+'</p>'+(CAPS.bookings?(b.status==='pending'?'<button class="act" data-a="confirm" data-id="'+b.id+'">تأكيد</button>':'')+((b.status==='pending'||b.status==='confirmed')?'<button class="act out" data-a="cancel" data-id="'+b.id+'">إلغاء</button>':''):'')+'</div>';}).join(''):'<p class="muted">لا توجد حجوزات بعد</p>';
main.innerHTML=h;var qi=document.getElementById('q');if(qi){qi.oninput=function(){clearTimeout(window.__bt);window.__bt=setTimeout(function(){window.__bq=qi.value;bookings();},300);};}
main.querySelectorAll('button[data-a]').forEach(function(b){b.onclick=async function(){if(b.dataset.a==='cancel'&&!confirm('إلغاء الحجز ده؟'))return;var x=await api('/api/site-admin/bookings/'+b.dataset.id,{method:'PUT',body:JSON.stringify({action:b.dataset.a})});if(!x.success)alert(x.error||x.message||'حصل خطأ');load();};});}
async function analytics(){var r=await api('/api/site-admin/analytics?days=30');if(!r.success){main.innerHTML='<p>تعذّر التحميل</p>';return;}var d=r.data,mx=Math.max.apply(null,[1].concat(d.daily.map(function(x){return x.views;})));
var SL={direct:'مباشر',search:'بحث',social:'سوشيال',ads:'إعلانات',other:'مواقع أخرى'},tot=d.sources.reduce(function(a,x){return a+x.views;},0)||1;
main.innerHTML='<div class="stats"><div class="card">المشاهدات (30 يوم)<b>'+d.views+'</b></div><div class="card">الزوار<b>'+d.visitors+'</b></div><div class="card">طلبات + حجوزات<b>'+(d.leads+d.bookings)+'</b></div></div>'
+'<div class="card"><div style="display:flex;align-items:flex-end;gap:2px;height:110px">'+d.daily.map(function(x){return '<div title="'+esc(x.day)+': '+x.views+'" style="flex:1;min-width:2px;background:#d4a437;height:'+Math.max(2,Math.round(x.views/mx*100))+'%"></div>';}).join('')+'</div></div>'
+'<div class="card"><b>مصادر الزيارات</b>'+(d.sources.length?d.sources.map(function(x){return '<div class="row"><span>'+esc(SL[x.source]||x.source)+'</span><b>'+Math.round(x.views/tot*100)+'%</b></div>';}).join(''):'<p class="muted">لا توجد بيانات بعد</p>')+'</div>';}
async function content(){var r=await api('/api/site-admin/content');if(!r.success){main.innerHTML='<p>تعذّر التحميل</p>';return;}var d=r.data;
function f(id,l,v,t){return '<label class="muted" for="'+id+'">'+l+'</label>'+(t==='ta'?'<textarea id="'+id+'" rows="4" style="width:100%;padding:9px;border-radius:8px;border:1px solid #cbd5e1">'+esc(v)+'</textarea>':'<input id="'+id+'" style="width:100%" value="'+esc(v)+'">');}
var h='<div class="card">'+f('c_business_name','اسم النشاط',d.business_name)+f('c_tagline','الشعار / العبارة التعريفية',d.tagline)+f('c_hero_headline','عنوان الصفحة الرئيسية',d.hero_headline)+f('c_hero_subtext','النص تحت العنوان',d.hero_subtext,'ta')+f('c_about','من نحن',d.about,'ta')+'</div>'
+'<div class="card"><b>بيانات التواصل</b>'+f('c_whatsapp','واتساب (مع كود الدولة)',d.contact.whatsapp)+f('c_phone','الهاتف',d.contact.phone)+f('c_email','الإيميل',d.contact.email)+f('c_address','العنوان',d.contact.address)+'</div>'
+'<div class="card"><b>الرحلات / الغرف</b><p class="muted">الأسعار بيغيّرها صاحب الحساب الأساسي.</p>'+d.items.map(function(t,i){return '<div style="border-top:1px solid #e2e8f0;padding-top:8px;margin-top:8px"><b>'+esc(t.name)+'</b>'+f('t_d'+i,'وصف قصير',t.short_description,'ta')+f('t_h'+i,'أهم المميزات (سطر لكل ميزة)',(t.highlights||[]).join(String.fromCharCode(10)),'ta')+'</div>';}).join('')+'</div>'
+'<button class="act" id="savec">حفظ التعديلات</button> <span id="savemsg" class="muted"></span>';
main.innerHTML=h;
document.getElementById('savec').onclick=async function(){var v=function(id){return document.getElementById(id).value;};
var body={business_name:v('c_business_name'),tagline:v('c_tagline'),hero_headline:v('c_hero_headline'),hero_subtext:v('c_hero_subtext'),about:v('c_about'),contact:{whatsapp:v('c_whatsapp'),phone:v('c_phone'),email:v('c_email'),address:v('c_address')},items:d.items.map(function(t,i){return {slug:t.slug,short_description:v('t_d'+i),highlights:v('t_h'+i)};})};
var x=await api('/api/site-admin/content',{method:'PUT',body:JSON.stringify(body)});document.getElementById('savemsg').textContent=x.success?'اتحفظ ✅':(x.error||x.message||'حصل خطأ');};}
async function leads(){var r=await api('/api/site-admin/leads');if(!r.success){main.innerHTML='<p>تعذّر التحميل</p>';return;}
main.innerHTML=r.data.leads.length?r.data.leads.map(function(l){return '<div class="card"><div class="row"><b>'+esc(l.visitor_name)+'</b><span class="badge">'+esc(ST[l.status]||l.status)+'</span></div><p dir="ltr" style="text-align:right">'+esc(l.phone||'')+(l.email?' - '+esc(l.email):'')+'</p><p>'+esc(l.message||'')+'</p>'+(CAPS.leads?'<select data-id="'+l.id+'"><option value="new">جديد</option><option value="contacted">تم التواصل</option><option value="closed">مغلق</option></select>':'')+'</div>';}).join(''):'<p class="muted">لا توجد طلبات بعد</p>';
main.querySelectorAll('select[data-id]').forEach(function(s,i){s.value=r.data.leads[i].status;s.onchange=async function(){var x=await api('/api/site-admin/leads/'+s.dataset.id,{method:'PUT',body:JSON.stringify({status:s.value})});if(!x.success)alert(x.error||'حصل خطأ');};});}
async function reviews(){var r=await api('/api/site-admin/reviews');if(!r.success){main.innerHTML='<p>تعذّر التحميل</p>';return;}
main.innerHTML=r.data.reviews.length?r.data.reviews.map(function(v){return '<div class="card"><div class="row"><b>'+esc(v.visitor_name)+' · '+'★'.repeat(Math.max(0,Math.min(5,v.rating)))+'</b><span class="badge">'+esc(ST[v.status]||v.status)+'</span></div><p>'+esc(v.comment||'')+'</p>'+(CAPS.reviews?'<button class="act" data-s="approved" data-id="'+v.id+'">اعتماد</button><button class="act out" data-s="rejected" data-id="'+v.id+'">رفض</button>':'')+'</div>';}).join(''):'<p class="muted">لا توجد تقييمات بعد</p>';
main.querySelectorAll('button[data-s]').forEach(function(b){b.onclick=async function(){var x=await api('/api/site-admin/reviews/'+b.dataset.id,{method:'PUT',body:JSON.stringify({status:b.dataset.s})});if(!x.success)alert(x.error||'حصل خطأ');load();};});}
document.getElementById('pw').onclick=function(){main.innerHTML='<div class="card"><b>تغيير كلمة السر</b><p class="muted">10 حروف على الأقل، فيها حروف وأرقام.</p>'
+'<label class="muted" for="pw0">كلمة السر الحالية</label><input id="pw0" type="password" autocomplete="current-password" style="width:100%" dir="ltr">'
+'<label class="muted" for="pw1">الجديدة</label><input id="pw1" type="password" autocomplete="new-password" style="width:100%" dir="ltr">'
+'<label class="muted" for="pw2">تأكيد الجديدة</label><input id="pw2" type="password" autocomplete="new-password" style="width:100%" dir="ltr">'
+'<p><button class="act" id="pws">حفظ</button> <span id="pwm" class="muted"></span></p></div>';
document.getElementById('pws').onclick=async function(){var m=document.getElementById('pwm'),a=document.getElementById('pw0').value,b=document.getElementById('pw1').value;
if(b!==document.getElementById('pw2').value){m.textContent='التأكيد مش مطابق';return;}
var x=await api('/api/site-admin/password',{method:'POST',body:JSON.stringify({current_password:a,new_password:b})});m.textContent=x.success?'تم التغيير ✅':(x.error||x.message||'حصل خطأ');if(x.success){document.getElementById('pw0').value='';document.getElementById('pw1').value='';document.getElementById('pw2').value='';}};};
document.getElementById('out').onclick=async function(){await api('/api/site-admin/logout',{method:'POST',body:'{}'});location.href='/site-admin/login';};
tabs();load();
})();
</script></body></html>
HTML;
        exit;
    }

    // ------------------------------------------------------------ APIs (whitelist; each pinned to the account's own site)

    public function bookingsList(array $params = []): array
    {
        return $this->allowed('view') ?? parent::bookings($this->pinned());
    }

    public function bookingsExport(array $params = []): array
    {
        return $this->allowed('view') ?? parent::exportBookings($this->pinned());
    }

    public function bookingAction(array $params = []): array
    {
        if ($e = $this->allowed('bookings')) {
            return $e;
        }
        $bookingId = (int) ($params['bookingId'] ?? 0);
        $r = parent::updateBooking($this->pinned(['bookingId' => $bookingId]));
        $this->record($r, 'booking_' . ((string) $this->get('action') === 'cancel' ? 'cancel' : 'confirm'), '#' . $bookingId);
        return $r;
    }

    /** يسجّل الإجراء الناجح في سجل النشاط اللي صاحب الموقع بيشوفه */
    private function record(array $result, string $action, string $detail = ''): void
    {
        if ($this->account && !empty($result['success'])) {
            (new SiteManagerService())->log((int) $this->account['id'], (int) $this->account['website_id'], $action, $detail);
        }
    }

    /** POST /api/site-admin/password - the manager changes their own password */
    public function passwordChange(array $params = []): array
    {
        if (!$this->account) {
            return $this->error('Unauthorized', 401);
        }
        if (($e = $this->verifyCsrf()) || ($e = $this->rateLimitGuard('ip', 'site_manager_password', 10, 600))) {
            return $e;
        }
        $r = (new SiteManagerService())->changeOwnPassword((int) $this->account['id'], (int) $this->account['website_id'], (string) $this->get('current_password', ''), (string) $this->get('new_password', ''));
        if (!$r['ok']) {
            return $this->error($r['error'], 422);
        }
        $out = $this->success([], 'تم تغيير كلمة السر');
        $this->record($out, 'password_change');
        return $out;
    }

    public function analyticsData(array $params = []): array
    {
        if ($e = $this->allowed('view')) {
            return $e;
        }
        $site = $this->ownedWebsite((int) $this->account['website_id']);
        return $site ? $this->success(SiteAnalytics::report((int) $site->getAttribute('id'), (int) $site->getAttribute('user_id'), (int) $this->get('days', 30))) : $this->error('غير موجود', 404);
    }

    // ------------------------------------------------------------ content editing (manager role only)

    private static function clean($v, int $max): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $v))), 0, $max);
    }

    private static function cleanMultiline($v, int $max): string
    {
        $lines = array_map(fn ($l) => trim(strip_tags($l)), preg_split('/\R/u', (string) $v));
        return mb_substr(trim(implode("\n", $lines)), 0, $max);
    }

    /** GET /api/site-admin/content */
    public function contentGet(array $params = []): array
    {
        if ($e = $this->allowed('content')) {
            return $e;
        }
        $site = $this->ownedWebsite((int) $this->account['website_id']);
        if (!$site) {
            return $this->error('غير موجود', 404);
        }
        $c = $site->getContent();
        $itemsKey = ($c['industry'] ?? 'tours') === 'hotel' ? 'rooms' : 'tours';
        $items = [];
        foreach ((array) ($c[$itemsKey] ?? []) as $t) {
            $items[] = ['slug' => (string) ($t['slug'] ?? ''), 'name' => (string) ($t['name'] ?? ''), 'short_description' => (string) ($t['short_description'] ?? ''), 'highlights' => array_values((array) ($t['highlights'] ?? ($t['amenities'] ?? [])))];
        }
        $ct = (array) ($c['contact'] ?? []);
        return $this->success([
            'business_name' => (string) ($c['business_name'] ?? ''), 'tagline' => (string) ($c['tagline'] ?? ''), 'hero_headline' => (string) ($c['hero_headline'] ?? ''),
            'hero_subtext' => (string) ($c['hero_subtext'] ?? ''), 'about' => (string) ($c['about'] ?? ''),
            'contact' => ['whatsapp' => (string) ($ct['whatsapp'] ?? ''), 'phone' => (string) ($ct['phone'] ?? ''), 'email' => (string) ($ct['email'] ?? ''), 'address' => (string) ($ct['address'] ?? '')],
            'items' => $items,
        ]);
    }

    /** PUT /api/site-admin/content - texts and contact details only: never prices, slugs, design or publishing */
    public function contentSave(array $params = []): array
    {
        if (($e = $this->allowed('content')) || ($e = $this->verifyCsrf()) || ($e = $this->rateLimitGuard('ip', 'site_manager_content', 60, 3600))) {
            return $e;
        }
        $site = $this->ownedWebsite((int) $this->account['website_id']);
        if (!$site) {
            return $this->error('غير موجود', 404);
        }
        $c = $site->getContent();
        $in = $this->get('business_name');
        if ($in !== null) {
            $name = self::clean($in, 120);
            if ($name === '') {
                return $this->error('اسم النشاط مطلوب', 422);
            }
            $c['business_name'] = $name;
        }
        foreach (['tagline' => 200, 'hero_headline' => 160] as $k => $max) {
            if ($this->get($k) !== null) {
                $c[$k] = self::clean($this->get($k), $max);
            }
        }
        foreach (['hero_subtext' => 500, 'about' => 3000] as $k => $max) {
            if ($this->get($k) !== null) {
                $c[$k] = self::cleanMultiline($this->get($k), $max);
            }
        }
        $ct = $this->get('contact');
        if (is_array($ct)) {
            $c['contact'] = (array) ($c['contact'] ?? []);
            if (isset($ct['whatsapp'])) {
                $wa = preg_replace('/[^0-9+]/', '', (string) $ct['whatsapp']);
                if ($wa !== '' && !preg_match('/^\+?[0-9]{7,16}$/', $wa)) {
                    return $this->error('رقم الواتساب غير صحيح', 422);
                }
                $c['contact']['whatsapp'] = $wa;
            }
            if (isset($ct['phone'])) {
                $c['contact']['phone'] = self::clean($ct['phone'], 40);
            }
            if (isset($ct['email'])) {
                $em = self::clean($ct['email'], 120);
                if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) {
                    return $this->error('الإيميل غير صحيح', 422);
                }
                $c['contact']['email'] = $em;
            }
            if (isset($ct['address'])) {
                $c['contact']['address'] = self::clean($ct['address'], 200);
            }
        }
        $items = $this->get('items');
        if (is_array($items)) {
            $itemsKey = ($c['industry'] ?? 'tours') === 'hotel' ? 'rooms' : 'tours';
            $bySlug = [];
            foreach ($items as $it) {
                if (is_array($it) && isset($it['slug'])) {
                    $bySlug[(string) $it['slug']] = $it;
                }
            }
            foreach ($c[$itemsKey] ?? [] as $i => $t) {
                $upd = $bySlug[(string) ($t['slug'] ?? '')] ?? null;
                if (!$upd) {
                    continue;
                }
                if (isset($upd['short_description'])) {
                    $c[$itemsKey][$i]['short_description'] = self::clean($upd['short_description'], 400);
                }
                if (isset($upd['highlights'])) {
                    $lines = array_values(array_filter(array_map(fn ($l) => self::clean($l, 120), preg_split('/\R/u', (string) $upd['highlights']))));
                    $c[$itemsKey][$i][$itemsKey === 'rooms' ? 'amenities' : 'highlights'] = array_slice($lines, 0, 8);
                }
            }
        }
        $this->saveContent($site, $c);
        $out = $this->success([], 'اتحفظ');
        $this->record($out, 'content_save');
        return $out;
    }

    public function leadsList(array $params = []): array
    {
        return $this->allowed('view') ?? parent::leads($this->pinned());
    }

    public function leadUpdate(array $params = []): array
    {
        if ($e = $this->allowed('leads') ?? $this->verifyCsrf()) {
            return $e;
        }
        $leadId = (int) ($params['leadId'] ?? 0);
        $r = parent::updateLead($this->pinned(['leadId' => $leadId]));
        $this->record($r, 'lead_update', '#' . $leadId . ' → ' . mb_substr((string) $this->get('status'), 0, 20));
        return $r;
    }

    public function reviewsList(array $params = []): array
    {
        return $this->allowed('view') ?? parent::reviews($this->pinned());
    }

    public function reviewUpdate(array $params = []): array
    {
        if ($e = $this->allowed('reviews') ?? $this->verifyCsrf()) {
            return $e;
        }
        $reviewId = (int) ($params['reviewId'] ?? 0);
        $r = parent::updateReview($this->pinned(['reviewId' => $reviewId]));
        $this->record($r, 'review_update', '#' . $reviewId . ' → ' . mb_substr((string) $this->get('status'), 0, 20));
        return $r;
    }
}
