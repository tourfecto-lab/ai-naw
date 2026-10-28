<?php
/** Workspace operations; financial mutations are restricted to the owner, workspace admin or manager. */
class OperationsController extends Controller
{
    private function rows(string $sql,array $params=[]): array
    {
        // This screen must never present an SQL failure as an empty connection list or zero cash.
        if($this->db->getConnection()->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')return $this->db->query($sql,$params);
        $statement=$this->db->getConnection()->prepare($sql);$statement->execute($params);return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    private function scope(bool $write=false,bool $finance=false): int
    {
        if(!$this->isAuthenticated())throw new RuntimeException('سجل الدخول أولًا',401);
        $row=$this->rows('SELECT id,owner_user_id,workspace_role FROM users WHERE id=?',[(int)$this->user['id']])[0]??null;
        if(!$row)throw new RuntimeException('تعذر التحقق من صلاحيتك',403);
        $owner=(int)($row['owner_user_id']??0);
        if($owner>0 && (($finance && !in_array($row['workspace_role'],['admin','manager'],true)) || ($write && !in_array($row['workspace_role'],['admin','manager','sales','support'],true))))throw new RuntimeException('ليس لديك صلاحية لهذه العملية',403);
        return $owner>0?$owner:(int)$row['id'];
    }
    private function action(callable $fn,bool $write=false,bool $finance=false): array
    {
        try{$result=$fn($this->scope($write,$finance));if(isset($result['error']))$result['error']=CustomerError::message((string)$result['error']);return $this->success($result);}
        catch(Throwable $e){return $this->error(CustomerError::message($e->getMessage(),in_array($e->getCode(),[401,403,404],true)?$e->getCode():422),in_array($e->getCode(),[401,403,404],true)?$e->getCode():422);}
    }
    public function overview(array $params=[]): array
    {
        return $this->action(function($owner){
            $ops=new BookingOperationsService($this->db);$products=$this->rows('SELECT id,name,price,currency FROM crm_products WHERE user_id=? ORDER BY name LIMIT 200',[$owner]);
            foreach($products as &$p){$p['settings']=$ops->productSettings($owner,(int)$p['id']);$p['extras']=$ops->extras($owner,(int)$p['id']);}unset($p);
            $connections=$this->rows('SELECT id,website_id,platform,status,token_expires_at,last_synced_at,refresh_token FROM platform_connections WHERE user_id=? ORDER BY platform,website_id',[$owner]);
            foreach($connections as &$c){$expired=!$c['token_expires_at'] || strtotime($c['token_expires_at'])<=time()+300;$c['health']=$c['status']!=='connected'?'needs_review':($expired?(empty($c['refresh_token'])?'reconnect':'refresh_due'):'stored_valid');unset($c['refresh_token']);}unset($c);
            return ['products'=>$products,'timezone'=>$ops->timezone($owner),'timezones'=>DateTimeZone::listIdentifiers(),'connections'=>$connections,'bookings'=>$this->rows('SELECT id,booking_reference,customer_name,start_date,start_time,status,checked_in_at,reminder_status,reminder_attempts FROM bookings WHERE user_id=? ORDER BY start_date DESC,id DESC LIMIT 100',[$owner])];
        });
    }
    public function financial(array $params=[]): array
    {
        return $this->action(function($owner){
            return ['booking_values'=>$this->rows("SELECT currency,SUM(CASE WHEN status<>'cancelled' THEN total_amount ELSE 0 END) active_value,SUM(CASE WHEN status='cancelled' THEN total_amount ELSE 0 END) cancelled_value FROM bookings WHERE user_id=? GROUP BY currency",[$owner]),
            'collections'=>$this->rows("SELECT currency,SUM(amount) gross_collected FROM payment_transactions WHERE user_id=? AND booking_id IS NOT NULL AND status IN ('succeeded','partially_refunded','refunded') GROUP BY currency",[$owner]),
            'refund_totals'=>$this->rows("SELECT r.currency,SUM(CASE WHEN r.status='succeeded' THEN r.amount ELSE 0 END) cash_refunded,SUM(CASE WHEN r.status='processing' THEN r.amount ELSE 0 END) reserved_refunds FROM refunds r JOIN payment_transactions t ON t.id=r.payment_transaction_id WHERE r.user_id=? AND t.booking_id IS NOT NULL GROUP BY r.currency",[$owner]),
            'transactions'=>$this->rows("SELECT t.id,t.amount,t.currency,t.gateway,t.status,b.booking_reference,b.status booking_status FROM payment_transactions t JOIN bookings b ON b.id=t.booking_id WHERE t.user_id=? ORDER BY t.id DESC LIMIT 100",[$owner]),
            'refunds'=>$this->rows('SELECT r.id,r.amount,r.currency,r.status,r.payment_transaction_id,r.gateway_refund_reference,t.gateway FROM refunds r JOIN payment_transactions t ON t.id=r.payment_transaction_id WHERE r.user_id=? AND t.booking_id IS NOT NULL ORDER BY r.id DESC LIMIT 100',[$owner])];
        },false,true);
    }
    public function saveProduct(array $params=[]): array{return $this->action(fn($owner)=>['settings'=>(new BookingOperationsService($this->db))->saveProduct($owner,(int)$params['id'],$this->input)],true,true);}
    public function saveTimezone(array $params=[]): array{return $this->action(function($owner){(new BookingOperationsService($this->db))->saveTimezone($owner,(string)$this->get('timezone',''));return [];},true,true);}
    public function addExtra(array $params=[]): array{return $this->action(function($owner)use($params){(new BookingOperationsService($this->db))->saveExtra($owner,(int)$params['id'],$this->input);return [];},true,true);}
    public function disableExtra(array $params=[]): array{return $this->action(function($owner)use($params){(new BookingOperationsService($this->db))->disableExtra($owner,(int)$params['id']);return [];},true,true);}
    public function ticket(array $params=[]): array{return $this->action(fn($owner)=>(new TicketService($this->db))->issue($owner,(int)$params['id']),true);}
    public function checkIn(array $params=[]): array{return $this->action(fn($owner)=>(new TicketService($this->db))->checkIn($owner,(int)$params['id']),true);}
    public function refund(array $params=[]): array
    {
        return $this->action(function($owner)use($params){
            $tx=$this->rows('SELECT id FROM payment_transactions WHERE id=? AND user_id=? AND booking_id IS NOT NULL',[(int)$params['id'],$owner]);if(!$tx)throw new RuntimeException('المعاملة غير موجودة',404);
            return (new RefundService($this->db))->createRefund((int)$params['id'],(float)$this->get('amount',0),mb_substr(trim((string)$this->get('reason','')),0,255),(int)$this->user['id']);
        },true,true);
    }
    public function recordManualRefund(array $params=[]): array
    {
        return $this->action(function($owner)use($params){
            if($this->get('already_paid',false)!==true)throw new RuntimeException('أكد أن الأموال أُعيدت بالفعل خارج المنصة');
            $id=(int)$params['id'];if(!$this->rows("SELECT id FROM payment_transactions WHERE id=? AND user_id=? AND booking_id IS NOT NULL AND gateway='manual'",[$id,$owner]))throw new RuntimeException('المعاملة غير موجودة',404);
            return (new RefundService($this->db))->recordManualRefund($id,(float)$this->get('amount',0),mb_substr((string)$this->get('reason',''),0,255),(int)$this->user['id'],(string)$this->get('receipt',''));
        },true,true);
    }
    public function reconcile(array $params=[]): array
    {
        return $this->action(function($owner)use($params){
            $row=$this->rows('SELECT t.gateway FROM refunds r JOIN payment_transactions t ON t.id=r.payment_transaction_id WHERE r.id=? AND r.user_id=?',[(int)$params['id'],$owner])[0]??null;if(!$row)throw new RuntimeException('الاسترجاع غير موجود',404);
            $svc=new RefundService($this->db);return $row['gateway']==='paymob'?$svc->reconcilePaymobRefund((int)$params['id']):$svc->reconcileStripeRefund((int)$params['id']);
        },true,true);
    }
    public function attachRefundReference(array $params=[]): array
    {
        return $this->action(function($owner)use($params){
            $id=(int)$params['id'];$reference=trim((string)$this->get('reference',''));
            $row=$this->rows("SELECT r.*,t.gateway,t.gateway_transaction_id FROM refunds r JOIN payment_transactions t ON t.id=r.payment_transaction_id WHERE r.id=? AND r.user_id=? AND t.gateway='paymob'",[$id,$owner])[0]??null;
            if(!$row || $row['status']!=='processing')throw new RuntimeException('طلب الاسترداد غير متاح للمصالحة',404);
            // Verify the operator-supplied dashboard reference remotely before persisting it.
            (new PaymobGateway($this->db))->retrieveRefund($reference,(string)$row['gateway_transaction_id'],(float)$row['amount'],(string)$row['currency']);
            $this->db->transaction(function()use($row,$reference,$id){
                $this->rows('SELECT id FROM payment_transactions WHERE id=? FOR UPDATE',[(int)$row['payment_transaction_id']]);
                $fresh=$this->rows('SELECT status,gateway_refund_reference FROM refunds WHERE id=? FOR UPDATE',[$id])[0]??null;
                if(!$fresh || $fresh['status']!=='processing' || ($fresh['gateway_refund_reference']&&$fresh['gateway_refund_reference']!==$reference))throw new RuntimeException('حالة الاسترداد تغيرت؛ حدّث الصفحة');
                $this->db->exec('UPDATE refunds SET gateway_refund_reference=? WHERE id=?',[$reference,$id]);
            });
            return (new RefundService($this->db))->reconcilePaymobRefund($id);
        },true,true);
    }

    public function resolveReminder(array $params=[]): array
    {
        return $this->action(function($owner)use($params){
            $outcome=(string)$this->get('outcome','');if(!in_array($outcome,['delivered','not_delivered'],true))throw new RuntimeException('حدد نتيجة التسليم بعد مراجعتها');
            $this->db->transaction(function()use($owner,$params,$outcome){
                $row=$this->rows('SELECT id,reminder_status FROM bookings WHERE id=? AND user_id=? FOR UPDATE',[(int)$params['id'],$owner])[0]??null;
                if(!$row || !in_array($row['reminder_status'],['processing','manual_review','failed'],true))throw new RuntimeException('التذكير لا يحتاج مصالحة');
                // Explicit human verification is required before releasing an uncertain claim.
                $this->db->exec($outcome==='delivered'?"UPDATE bookings SET reminder_status='sent_verified',reminder_sent_at=NOW(),reminder_claimed_at=NULL,reminder_error=NULL WHERE id=?":"UPDATE bookings SET reminder_status='failed',reminder_sent_at=NULL,reminder_claimed_at=NULL,reminder_next_attempt_at=NOW(),reminder_attempts=0,reminder_error=NULL WHERE id=?",[(int)$params['id']]);
                if(class_exists('ActivityLog'))ActivityLog::record('bookings','booking.reminder_reconciled',['user_id'=>(int)$this->user['id'],'subject_type'=>'bookings','subject_id'=>(int)$params['id'],'meta'=>['outcome'=>$outcome]]);
            });return [];
        },true,true);
    }
    public function publicTicket(array $params=[]): array
    {
        $row=(new TicketService($this->db))->publicTicket((string)($params['token']??''));
        header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
        if(!$row){http_response_code(404);echo '<h1>التذكرة غير متاحة</h1>';exit;}
        $e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$valid=in_array($row['status'],['confirmed','completed'],true);
        $state=$row['checked_in_at']?'تم تسجيل الحضور':($valid?'حجز مؤكد':'التذكرة غير صالحة للاستخدام');
        echo '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>تذكرة الحجز</title><body style="font-family:system-ui;background:#f3f6fa;padding:24px"><main style="max-width:520px;margin:auto;background:white;padding:24px;border-radius:16px"><h1>تذكرة الحجز</h1><h2>'.$e($row['product_name']).'</h2><p>'.$e($row['booking_reference']).'</p><p>'.$e($row['start_date']).' '.$e($row['start_time']).'</p><p>بالغين: '.$e($row['adults_count']).' — أطفال: '.$e($row['children_count']).'</p><strong>'.$e($state).'</strong><p>تسجيل الحضور يتم بواسطة موظف مصرح له.</p>';
        if($valid && $this->isAuthenticated())echo '<p><a href="/operations?booking='.$e($row['id']).'">فتح إدارة الحضور</a></p>';
        if($valid){try{$base=rtrim((string)(defined('APP_URL')?APP_URL:(getenv('APP_URL')?:'https://tourfecto.pro')),'/');echo QrCodeService::svg($base.'/ticket/'.(string)$params['token']);}catch(Throwable $ignored){}}
        echo '<button onclick="window.print()">طباعة</button></main></body></html>';exit;
    }
    public function page(array $params=[]): array
    {
        if(!$this->isAuthenticated()){header('Location: /login');exit;}
        header('Content-Type: text/html; charset=utf-8');echo $this->renderPanelPage('operations','مركز العمليات','المالية، إعدادات الخدمات، التكاملات والتذاكر',self::HTML,self::JS);exit;
    }
    private const HTML=<<<'HTML'
<style>.op-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}.op-card{padding:18px;border:1px solid #d8dee8;border-radius:12px;margin:12px 0}.op-card label{display:block;margin:10px 0}.op-card input,.op-card select{padding:8px;max-width:100%;border:1px solid #bcc5d2;border-radius:6px}.op-table{width:100%;border-collapse:collapse}.op-table td,.op-table th{padding:10px;text-align:start;border-bottom:1px solid #dde3ea}.op-scroll{overflow:auto}.op-card button{margin:5px;padding:8px 14px;cursor:pointer}.op-status{min-height:24px}</style>
<div class="op-status" id="op-msg" role="status"></div><nav><a href="#op-money">المالية</a> · <a href="#op-services">الخدمات والإضافات</a> · <a href="#op-integrations">التكاملات</a> · <a href="#op-tickets">الحضور والتذكيرات</a> · <a href="/bookings">الحجوزات والتوفر</a></nav>
<section id="op-money"><h2>المالية حسب العملة</h2><p>قيمة الحجز منفصلة عن التحصيل الفعلي. إلغاء الحجز لا يعني رجوع الأموال. الأرقام من معاملات دفع الحجوزات المسجلة.</p><div id="op-finance">جاري التحميل…</div></section>
<section id="op-services"><h2>الخدمات والإضافات</h2><p>تغيير وحدة السعة يتطلب عدم وجود حجوزات مستقبلية قائمة، ثم ضبط التوفر من صفحة الحجوزات. سياسة الإلغاء تُعرض للعميل، والاسترداد يحتاج تنفيذًا ماليًا منفصلًا.</p><form id="op-tz" class="op-card"><label>المنطقة الزمنية للتذكيرات <select name="timezone"></select></label><button>حفظ المنطقة الزمنية</button></form><div id="op-products" class="op-grid"></div></section>
<section id="op-integrations"><h2>حالة التكاملات</h2><p>الحالة مبنية على البيانات المسجلة؛ راجع شاشة التكامل لاختبار الاتصال بالخدمة الخارجية.</p><div id="op-connections"></div><a href="/dashboard/analytics">التحليلات</a> · <a href="/reputation/overview">التقييمات</a> · <a href="/ads/connections">الإعلانات</a></section>
<section id="op-tickets"><h2>التذاكر والحضور والتذكيرات</h2><p>التذكرة للحجز المؤكد. لا تؤكد نتيجة التذكير إلا بعد مراجعة التسليم لدى مزود الرسائل.</p><label>بحث عن حجز <input id="op-search" placeholder="مرجع الحجز أو الاسم"></label><div id="op-bookings" class="op-scroll"></div><div id="op-ticket"></div></section>
HTML;
    private const JS=<<<'JS'
(function(){
const $=s=>document.querySelector(s),e=Panel.esc;let bookings=[];
const labels={confirmed:'مؤكد',pending:'منتظر',cancelled:'ملغي',completed:'مكتمل',processing:'قيد المعالجة',succeeded:'نجح',failed:'فشل',refunded:'استرداد كامل',partially_refunded:'استرداد جزئي',manual_review:'مراجعة التسليم',connected:'متصل',reconnect:'أعد الربط',refresh_due:'يحتاج تحديث الرمز',stored_valid:'رمز مسجل ساري',needs_review:'يحتاج مراجعة'};
const l=v=>e(labels[v]||v||'—');
function message(text){$('#op-msg').textContent=text||'';}
async function call(url,body){const r=await Panel.fetchJSON(url,body===undefined?{}:{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});if(!r.success)throw Error(r.error||'تعذر تنفيذ الطلب');return r.data||{};}
function table(head,rows){return '<table class="op-table"><thead><tr>'+head.map(x=>'<th>'+e(x)+'</th>').join('')+'</tr></thead><tbody>'+rows.map(r=>'<tr>'+r.map(x=>'<td>'+x+'</td>').join('')+'</tr>').join('')+'</tbody></table>';}
function renderBookings(){let term=$('#op-search').value.trim().toLowerCase();$('#op-bookings').innerHTML=table(['الحجز','العميل','التاريخ','الحالة','التذكير','الإجراء'],bookings.filter(b=>!term||(b.booking_reference+' '+b.customer_name).toLowerCase().includes(term)).map(b=>[e(b.booking_reference),e(b.customer_name),e(b.start_date),l(b.status)+(b.checked_in_at?' · حضر':''),l(b.reminder_status)+' ('+e(b.reminder_attempts)+')','<button data-action="ticket" data-id="'+b.id+'">تذكرة</button><button data-action="check-in" data-id="'+b.id+'">حضور</button>'+(['manual_review','processing','failed'].includes(b.reminder_status)?'<button data-action="reminder" data-id="'+b.id+'">مراجعة التسليم</button>':'')]));}
async function load(){
try{const d=await call('/api/operations');bookings=d.bookings;const tz=$('#op-tz select');tz.innerHTML=d.timezones.map(t=>'<option>'+e(t)+'</option>').join('');tz.value=d.timezone;
$('#op-products').innerHTML=d.products.map(p=>{const s=p.settings;return '<article class="op-card"><h3>'+e(p.name)+'</h3><p>سعر البالغ: '+e(p.price)+' '+e(p.currency)+'</p><form data-product="'+p.id+'"><label>السعة <select name="capacity_mode"><option value="booking" '+(s.capacity_mode==='booking'?'selected':'')+'>عدد الحجوزات</option><option value="seat" '+(s.capacity_mode==='seat'?'selected':'')+'>عدد المقاعد</option></select></label><label>سعر الطفل <input name="child_price" type="number" step="0.01" min="0" value="'+e(s.child_price)+'" required></label><label><input type="checkbox" name="children_use_seat" '+(Number(s.children_use_seat)?'checked':'')+'> الطفل يشغل مقعدًا</label><label>مهلة الإلغاء بالساعات <input name="cancellation_hours" type="number" min="0" max="8760" value="'+e(s.cancellation_hours)+'" required></label><label>نسبة الاسترداد المعروضة % <input name="refund_percent" type="number" step="0.01" min="0" max="100" value="'+e(s.refund_percent)+'" required></label><button>حفظ الخدمة</button></form><h4>الإضافات</h4>'+p.extras.filter(x=>Number(x.is_active)).map(x=>'<p>'+e(x.name)+' — '+e(x.price)+' '+e(p.currency)+' / '+(x.price_mode==='person'?'فرد':'حجز')+' <button data-action="disable-extra" data-id="'+x.id+'">تعطيل</button></p>').join('')+'<form data-extra="'+p.id+'"><label>الاسم <input name="name" maxlength="150" required></label><label>السعر <input name="price" type="number" min="0" step="0.01" required></label><label>الحساب <select name="price_mode"><option value="booking">لكل حجز</option><option value="person">لكل فرد</option></select></label><button>إضافة</button></form></article>';}).join('')||'<p>أضف خدمات من إدارة المنتجات أولًا.</p>';
$('#op-connections').innerHTML=table(['المنصة','الموقع','الحالة','آخر مزامنة'],d.connections.map(c=>[e(c.platform),e(c.website_id),l(c.health),e(c.last_synced_at||'لم تزامن')]))||'لا توجد تكاملات';renderBookings();
}catch(err){message(err.message);}
try{const d=await call('/api/operations/financial');const curr=new Set([...d.booking_values,...d.collections,...d.refund_totals].map(x=>x.currency));$('#op-finance').innerHTML='<div class="op-scroll">'+table(['العملة','قيمة الحجوزات القائمة','قيمة الملغي','التحصيل','المسترد فعليًا','الصافي','استرداد تحت المراجعة'],Array.from(curr).map(c=>{const b=d.booking_values.find(x=>x.currency===c)||{},t=d.collections.find(x=>x.currency===c)||{},r=d.refund_totals.find(x=>x.currency===c)||{};return [e(c),e(b.active_value||0),e(b.cancelled_value||0),e(t.gross_collected||0),e(r.cash_refunded||0),e((Number(t.gross_collected||0)-Number(r.cash_refunded||0)).toFixed(2)),e(r.reserved_refunds||0)];}))+'<h3>آخر معاملات التحصيل</h3>'+table(['رقم','حجز','مبلغ','بوابة','حالة','إجراء'],d.transactions.map(t=>[e(t.id),e(t.booking_reference)+(t.booking_status==='cancelled'?' · ملغي':''),e(t.amount)+' '+e(t.currency),e(t.gateway),l(t.status),['succeeded','partially_refunded'].includes(t.status)&&['stripe','stripe_operator','paymob','wallet','manual'].includes(t.gateway)?'<button data-action="'+(t.gateway==='manual'?'manual-refund':'refund')+'" data-id="'+t.id+'">'+(t.gateway==='manual'?'تسجيل استرداد تم خارج المنصة':'استرداد')+'</button>':'—']))+'<h3>طلبات الاسترداد</h3>'+table(['رقم','مبلغ','حالة','إجراء'],d.refunds.map(r=>[e(r.id),e(r.amount)+' '+e(r.currency),l(r.status),r.status==='processing'?'<button data-action="'+(r.gateway==='paymob'&&!r.gateway_refund_reference?'attach-refund':'reconcile')+'" data-id="'+r.id+'">'+(r.gateway==='paymob'&&!r.gateway_refund_reference?'ربط مرجع من Paymob':'تحقق من النتيجة')+'</button>':'—']))+'</div>';}catch(err){$('#op-finance').textContent=err.message;}
}
$('#op-search').addEventListener('input',renderBookings);
document.addEventListener('submit',async ev=>{const f=ev.target;if(!f.closest('#op-services'))return;ev.preventDefault();const data=Object.fromEntries(new FormData(f));if(f.dataset.product)data.children_use_seat=f.elements.children_use_seat.checked;try{await call(f.id==='op-tz'?'/api/operations/timezone':f.dataset.product?'/api/operations/products/'+f.dataset.product:'/api/operations/products/'+f.dataset.extra+'/extras',data);message('تم الحفظ');await load();}catch(err){message(err.message);}});
document.addEventListener('click',async ev=>{const b=ev.target.closest('[data-action]');if(!b)return;const id=b.dataset.id,a=b.dataset.action;let url='',body={};if(a==='manual-refund'){const amount=prompt('المبلغ الذي أُعيد للعميل بالفعل خارج المنصة');if(amount===null)return;const receipt=prompt('مرجع إيصال أو تحويل الاسترداد الفعلي (مرجع فريد)');if(!receipt)return;if(!confirm('أؤكد أن المبلغ أُعيد بالفعل. هذه العملية تسجل الاسترداد ولا ترسل أموالًا.'))return;url='/api/operations/transactions/'+id+'/manual-refund';body={amount,receipt,already_paid:true,reason:'استرداد موثق بمرجع خارجي'};}else if(a==='refund'){const amount=prompt('المبلغ المطلوب استرداده');if(amount===null)return;const reason=prompt('سبب الاسترداد');if(reason===null)return;if(!confirm('تنفيذ استرداد فعلي إلى وسيلة الدفع الأصلية؟'))return;url='/api/operations/transactions/'+id+'/refund';body={amount,reason};}else if(a==='attach-refund'){const reference=prompt('بعد مراجعة Paymob: أدخل رقم معاملة الاسترداد. سيتم التحقق منه دون إرسال طلب استرداد جديد.');if(!reference)return;url='/api/operations/refunds/'+id+'/reference';body={reference};}else if(a==='reconcile')url='/api/operations/refunds/'+id+'/reconcile';else if(a==='disable-extra')url='/api/operations/extras/'+id+'/disable';else if(a==='reminder'){const delivered=confirm('هل تحققت أن الرسالة تم تسليمها؟ موافق = تم التسليم، إلغاء = اختيار لم تُسلّم.');if(!delivered&&!confirm('هل راجعت المزود وتأكدت أنها لم تُسلّم؟'))return;url='/api/operations/bookings/'+id+'/reminder/resolve';body={outcome:delivered?'delivered':'not_delivered'};}else{if(a==='check-in'&&!confirm('تسجيل حضور الحجز بالكامل؟'))return;url='/api/operations/bookings/'+id+'/'+a;}
b.disabled=true;try{const r=await call(url,body);if(a==='ticket'){$('#op-ticket').innerHTML=r.qr_svg+'<p><a target="_blank" rel="noopener noreferrer" href="'+e(r.url)+'">فتح وطباعة التذكرة '+e(r.reference)+'</a></p>';}else{message(r.pending?(r.error||'النتيجة قيد المراجعة؛ لا تكرر الطلب'):r.success===false?(r.error||'تعذر الاسترداد'):'تم تنفيذ العملية');await load();}}catch(err){message(err.message);}finally{b.disabled=false;}});
load();
})();
JS;
}
