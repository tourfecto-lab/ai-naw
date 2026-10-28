<?php
class ServiceCenterController extends Controller
{
    private function actor(): int{return (int)($this->user['id']??0);}
    public function index(array $params=[]): array {
        if(!$this->actor())return $this->error('Unauthorized',401);$csrf=htmlspecialchars(Csrf::token(),ENT_QUOTES,'UTF-8');
        $body='<link rel="stylesheet" href="/assets/css/service-center.css"><div id="serviceCenter" data-csrf="'.$csrf.'"><div class="sc-toolbar"><label>Find a service<input id="scSearch" type="search" placeholder="Bookings, email, payments…"></label><label>Plan coverage<select id="scPlanFilter"><option value="">All services</option><option value="included">Included in my plan</option><option value="core">Included in every plan</option><option value="pay_per_use">Pay per use</option><option value="not_included">Requires an upgrade</option></select></label><button id="scRefresh" type="button">Refresh status</button></div><p id="scStatus" role="status" aria-live="polite"></p><div id="scRuntime"></div><nav id="scTabs" aria-label="Service center tools"></nav><section id="scTools"></section><div id="scServices" class="sc-grid"></div></div><script src="/assets/js/service-center.js"></script>';
        echo $this->renderPanelPage('service_center','Service center','Service readiness, delivery recovery and operation history',$body,'');exit;
    }
    /** A separate, fictional preview: no production API or external delivery is available here. */
    public function demo(array $params=[]): array {
        if(!$this->actor())return $this->error('Unauthorized',401);
        $ar=current_lang()==='ar';$lang=$ar?'ar':'en';$dir=$ar?'rtl':'ltr';
        $l=static fn($a,$e)=>$ar?$a:$e;
        $title=$l('تجربة آمنة — بيانات وهمية','Safe demo — fictional data');
        $nav='';foreach(['overview'=>['نظرة عامة','Overview'],'customers'=>['العملاء','Customers'],'bookings'=>['الحجوزات','Bookings'],'messages'=>['الرسائل','Messages'],'assistant'=>['المساعد','Assistant'],'billing'=>['الدفع والفواتير','Payments and invoices']] as $key=>$labels)$nav.='<button type="button" data-screen="'.$key.'">'.$l(...$labels).'</button>';
        header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store, private');
        header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'none'; form-action 'none'; base-uri 'none'; frame-ancestors 'none'");
        $css=htmlspecialchars(asset_v('/assets/css/customer-demo.css'),ENT_QUOTES,'UTF-8');$js=htmlspecialchars(asset_v('/assets/js/customer-demo.js'),ENT_QUOTES,'UTF-8');
        echo '<!doctype html><html lang="'.$lang.'" dir="'.$dir.'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$title.'</title><link rel="stylesheet" href="'.$css.'"></head><body><header><h1>'.$title.'</h1><a href="/dashboard">'.$l('الخروج للحساب الحقيقي','Exit to the live account').'</a><button type="button" data-action="reset">'.$l('إعادة ضبط التجربة','Reset demo').'</button><p class="demo-warning">'.$l('أنت في محاكاة منفصلة. لا يوجد إرسال أو خصم أو نشر، ولا تُستخدم بيانات حسابك. هذه معاينة تعليمية وليست اختبار تسليم فعلي.','You are in an isolated simulation. Nothing is delivered, charged or published, and no account data is used. This is an educational preview, not a live delivery test.').'</p></header><nav aria-label="Demo navigation">'.$nav.'</nav><main><p id="demoStatus" role="status" aria-live="polite"></p><section id="demoBody"></section></main><script src="'.$js.'"></script></body></html>';exit;
    }

    /** GET /api/customer-readiness — no schema creation or remote calls. */
    public function readiness(array $params=[]): array {
        header('Cache-Control: no-store, private');
        if(!$this->actor())return $this->error('Unauthorized',401);
        $raw=$this->get('website_id');
        if($raw!==null&&$raw!==''&&(!ctype_digit((string)$raw)||(int)$raw<1))return $this->error('Invalid website',422);
        try {
            require_once __DIR__.'/../Services/CustomerReadinessService.php';
            $scope=(new ServiceCenterService())->scope($this->actor());
            return $this->success((new CustomerReadinessService())->report($scope['owner_id'],$raw?(int)$raw:null));
        } catch(Throwable $e) {
            $status=in_array($e->getCode(),[401,403,404],true)?$e->getCode():503;
            return $this->error($status===404?'Website not found':'Could not load readiness.',$status);
        }
    }
    public function action(array $params=[]): array {
        if(!$this->actor())return $this->error('Unauthorized',401);$a=(string)($params['action']??'overview');$reads=['overview','sessions','usage','operations','deliveries','refund-quote','refund-requests','rates','agency-domains','agency-domain','wallet-statement','payment-timeline','customer-timeline'];
        $read=in_array($a,$reads,true);if(($_SERVER['REQUEST_METHOD']??'GET')!==($read?'GET':'POST'))return $this->error('Method not allowed',405);
        if(!$read&&($e=$this->verifyCsrf()))return $e;if($e=$this->rateLimitGuard('user','service_center',30,60))return $e;
        try{$svc=new ServiceCenterService();$scope=$svc->scope($this->actor(),!$read&&!in_array($a,['revoke-session'],true),in_array($a,['usage','refund-quote','refund-requests','wallet-statement','payment-timeline'],true));$uid=$scope['owner_id'];$actor=$this->actor();
            $data=match($a){
                'overview'=>$svc->overview($actor),
                'sessions'=>['sessions'=>$svc->sessions($actor)],
                'revoke-session'=>$svc->revokeSession($actor,(int)$this->get('id')),
                'usage'=>$svc->usage($actor),
                'payment-timeline'=>$svc->paymentTimeline($actor,(int)$this->get('booking_id')),
                'customer-timeline'=>$svc->customerTimeline($actor,(int)$this->get('contact_id')),
                'operations'=>['operations'=>$svc->operations($actor)],
                'record-check'=>$svc->recordCheck($actor,(string)$this->get('service'),(string)$this->get('check'),(string)$this->get('evidence',''),filter_var($this->get('remove',false),FILTER_VALIDATE_BOOLEAN)),
                'deliveries'=>['replies'=>(new ApprovedReplyDelivery())->listing($uid)],
                'retry-reply'=>(new ApprovedReplyDelivery())->approve((int)$this->get('id'),$uid),
                'resolve-reply'=>(new ApprovedReplyDelivery())->resolve($uid,(int)$this->get('id'),(string)$this->get('outcome'),(string)$this->get('evidence')),
                'refund-quote'=>(new BookingRefundPolicy())->quote($uid,(int)$this->get('booking_id')),
                'refund-requests'=>['requests'=>(new BookingRefundPolicy())->listing($uid)],
                'request-refund'=>(new BookingRefundPolicy())->request($uid,$actor,$this->all()),
                'review-refund'=>(new BookingRefundPolicy())->review($uid,$actor,(int)$this->get('id'),$this->get('approve')===true),
                'rates'=>$svc->rates($actor),
                'save-rate'=>$svc->saveRate($actor,$this->all()),
                'delete-rate'=>$svc->deleteRate($actor,(int)$this->get('id')),
                'agency-domains'=>['agencies'=>(new AgencyDomainSetup())->listing($uid)],
                'agency-domain'=>(new AgencyDomainSetup())->status($uid,(int)$this->get('agency_id')),
                'save-agency-domain'=>(new AgencyDomainSetup())->save($uid,(int)$this->get('agency_id'),(string)$this->get('domain')),
                'verify-agency-domain'=>(new AgencyDomainSetup())->verify($uid,(int)$this->get('agency_id')),
                'check-agency-https'=>(new AgencyDomainSetup())->checkHttps($uid,(int)$this->get('agency_id')),
                'scan-ticket'=>$this->scanTicket($uid,$svc,(string)$this->get('ticket')),
                'wallet-statement'=>$this->statement($uid,$svc),
                default=>throw new InvalidArgumentException('Unknown service center action'),
            };return $this->success($data);
        }catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}catch(PDOException $e){Logger::error('Service center storage unavailable');return $this->error('Service data is unavailable. Check the new SQL installation.',503);}catch(Throwable $e){$code=in_array($e->getCode(),[401,403,404],true)?$e->getCode():409;return $this->error(CustomerError::message($e->getMessage(),$code),$code);}
    }
    private function scanTicket(int $uid,ServiceCenterService $svc,string $ticket): array {
        $ticket=trim($ticket);if(preg_match('~^https?://~i',$ticket)){$ticket=basename((string)parse_url($ticket,PHP_URL_PATH));}
        if(!preg_match('/^[a-f0-9]{32}$/D',$ticket))throw new InvalidArgumentException('Scan or paste a Tourfecto ticket code');
        $id=$svc->q('SELECT id FROM bookings WHERE ticket_token=? AND user_id=?',[$ticket,$uid])->fetchColumn();if(!$id)throw new RuntimeException('Ticket not found in this workspace',404);
        return (new TicketService())->checkIn($uid,(int)$id);
    }
    private function statement(int $uid,ServiceCenterService $svc): array {
        $rows=$svc->q('SELECT id,type,amount,currency,status,created_at FROM wallet_transactions WHERE user_id=? ORDER BY id DESC LIMIT 5000',[$uid])->fetchAll(PDO::FETCH_ASSOC);
        return ['transactions'=>$rows,'limit'=>5000,'note'=>'Most recent 5,000 wallet movements. Currency and status are preserved.'];
    }
    public function domainProof(array $params=[]): array {
        $a=AgencyDomainSetup::publicAgency((string)($_SERVER['HTTP_HOST']??''));if(!$a)return $this->error('Domain not verified',404);
        header('Cache-Control: no-store');return ['agency_id'=>(int)$a['id'],'verification'=>$a['verification_token']];
    }
    public static function agencyLanding(array $a): void {
        $e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$name=$e($a['name']);$color=preg_match('/^#[a-f0-9]{6}$/i',(string)$a['primary_color'])?$a['primary_color']:'#b9974b';$base=rtrim(defined('APP_URL')?(string)APP_URL:'','/');$login=$e($base.'/login');
        header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');
        echo '<!doctype html><html lang="en" dir="ltr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$name.'</title><style>body{margin:0;background:#07101f;color:#fff;font:18px system-ui;display:grid;place-items:center;min-height:100vh}main{max-width:620px;padding:40px;text-align:center}a{display:inline-block;background:'.$color.';color:#07101f;padding:14px 22px;border-radius:12px;text-decoration:none}p{color:#c0cad8;line-height:1.7}</style><main><h1>'.$name.'</h1><p>Tourism services and client workspace</p><a href="'.$login.'">Client sign in</a><p>Your account opens securely on the platform.</p></main></html>';exit;
    }
}
