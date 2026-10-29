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
        $caps = json_encode(['bookings' => SiteManagerService::can($role, 'bookings'), 'leads' => SiteManagerService::can($role, 'leads'), 'reviews' => SiteManagerService::can($role, 'reviews')]);
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
<header><div><h1>{$name}</h1><small>{$who} · {$roleLabel}</small></div><button id="out">خروج</button></header>
<nav id="tabs"></nav><main id="main"></main>
<script>
(function(){
var CAPS={$caps},csrf=document.querySelector('meta[name=csrf]').content,main=document.getElementById('main'),tab='bookings';
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
async function api(url,opt){opt=opt||{};opt.credentials='same-origin';opt.headers=Object.assign({'Content-Type':'application/json','X-CSRF-Token':csrf},opt.headers||{});
var r=await fetch(url,opt);if(r.status===401){location.href='/site-admin/login';return{success:false};}return r.json().catch(function(){return{success:false};});}
var ST={pending:'قيد الانتظار',confirmed:'مؤكد',cancelled:'ملغي',completed:'مكتمل',no_show:'لم يحضر',new:'جديد',contacted:'تم التواصل',closed:'مغلق',approved:'معتمد',rejected:'مرفوض'};
function tabs(){var t=document.getElementById('tabs');t.innerHTML='';[['bookings','الحجوزات'],['leads','طلبات التواصل'],['reviews','التقييمات']].forEach(function(x){var b=document.createElement('button');b.textContent=x[1];b.className=tab===x[0]?'on':'';b.onclick=function(){tab=x[0];tabs();load();};t.appendChild(b);});}
async function load(){main.innerHTML='<p class="muted">جاري التحميل...</p>';if(tab==='bookings')return bookings();if(tab==='leads')return leads();return reviews();}
async function bookings(){var q=(window.__bq||'');var r=await api('/api/site-admin/bookings?q='+encodeURIComponent(q));if(!r.success){main.innerHTML='<p>تعذّر التحميل</p>';return;}
var s=r.data.stats,h='<div class="stats"><div class="card">قيد الانتظار<b>'+s.pending+'</b></div><div class="card">مؤكدة<b>'+s.confirmed+'</b></div><div class="card">الإيراد المؤكد<b dir="ltr">'+esc(s.revenue)+'</b></div></div>';
h+='<div class="card row"><input id="q" type="search" placeholder="بحث بالاسم / الهاتف / رقم الحجز" value="'+esc(q)+'"><a class="muted" href="/api/site-admin/bookings/export" >تصدير CSV</a></div>';
h+=r.data.bookings.length?r.data.bookings.map(function(b){return '<div class="card"><div class="row"><b>'+esc(b.customer_name)+'</b><span class="badge '+(b.status==='pending'?'gold':'')+'">'+esc(ST[b.status]||b.status)+'</span></div><p>'+esc(b.product_name)+' · '+esc(b.start_date)+' · '+b.adults_count+' بالغ'+(b.children_count>0?' + '+b.children_count+' طفل':'')+' · '+esc(b.total_amount)+' '+esc(b.currency)+'</p><p dir="ltr" style="text-align:right">'+esc(b.customer_phone||'')+(b.customer_email?' - '+esc(b.customer_email):'')+' · #'+esc(b.booking_reference)+'</p>'+(CAPS.bookings?(b.status==='pending'?'<button class="act" data-a="confirm" data-id="'+b.id+'">تأكيد</button>':'')+((b.status==='pending'||b.status==='confirmed')?'<button class="act out" data-a="cancel" data-id="'+b.id+'">إلغاء</button>':''):'')+'</div>';}).join(''):'<p class="muted">لا توجد حجوزات بعد</p>';
main.innerHTML=h;var qi=document.getElementById('q');if(qi){qi.oninput=function(){clearTimeout(window.__bt);window.__bt=setTimeout(function(){window.__bq=qi.value;bookings();},300);};}
main.querySelectorAll('button[data-a]').forEach(function(b){b.onclick=async function(){if(b.dataset.a==='cancel'&&!confirm('إلغاء الحجز ده؟'))return;var x=await api('/api/site-admin/bookings/'+b.dataset.id,{method:'PUT',body:JSON.stringify({action:b.dataset.a})});if(!x.success)alert(x.error||x.message||'حصل خطأ');load();};});}
async function leads(){var r=await api('/api/site-admin/leads');if(!r.success){main.innerHTML='<p>تعذّر التحميل</p>';return;}
main.innerHTML=r.data.leads.length?r.data.leads.map(function(l){return '<div class="card"><div class="row"><b>'+esc(l.visitor_name)+'</b><span class="badge">'+esc(ST[l.status]||l.status)+'</span></div><p dir="ltr" style="text-align:right">'+esc(l.phone||'')+(l.email?' - '+esc(l.email):'')+'</p><p>'+esc(l.message||'')+'</p>'+(CAPS.leads?'<select data-id="'+l.id+'"><option value="new">جديد</option><option value="contacted">تم التواصل</option><option value="closed">مغلق</option></select>':'')+'</div>';}).join(''):'<p class="muted">لا توجد طلبات بعد</p>';
main.querySelectorAll('select[data-id]').forEach(function(s,i){s.value=r.data.leads[i].status;s.onchange=async function(){var x=await api('/api/site-admin/leads/'+s.dataset.id,{method:'PUT',body:JSON.stringify({status:s.value})});if(!x.success)alert(x.error||'حصل خطأ');};});}
async function reviews(){var r=await api('/api/site-admin/reviews');if(!r.success){main.innerHTML='<p>تعذّر التحميل</p>';return;}
main.innerHTML=r.data.reviews.length?r.data.reviews.map(function(v){return '<div class="card"><div class="row"><b>'+esc(v.visitor_name)+' · '+'★'.repeat(Math.max(0,Math.min(5,v.rating)))+'</b><span class="badge">'+esc(ST[v.status]||v.status)+'</span></div><p>'+esc(v.comment||'')+'</p>'+(CAPS.reviews?'<button class="act" data-s="approved" data-id="'+v.id+'">اعتماد</button><button class="act out" data-s="rejected" data-id="'+v.id+'">رفض</button>':'')+'</div>';}).join(''):'<p class="muted">لا توجد تقييمات بعد</p>';
main.querySelectorAll('button[data-s]').forEach(function(b){b.onclick=async function(){var x=await api('/api/site-admin/reviews/'+b.dataset.id,{method:'PUT',body:JSON.stringify({status:b.dataset.s})});if(!x.success)alert(x.error||'حصل خطأ');load();};});}
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
        return $this->allowed('bookings') ?? parent::updateBooking($this->pinned(['bookingId' => (int) ($params['bookingId'] ?? 0)]));
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
        return parent::updateLead($this->pinned(['leadId' => (int) ($params['leadId'] ?? 0)]));
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
        return parent::updateReview($this->pinned(['reviewId' => (int) ($params['reviewId'] ?? 0)]));
    }
}
