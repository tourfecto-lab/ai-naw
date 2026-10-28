<?php
class ServiceCenterService
{
    private PDO $pdo;
    public function __construct(?PDO $pdo=null){$this->pdo=$pdo??Database::getInstance()->getConnection();}
    public function q(string $sql,array $args=[]): PDOStatement{$s=$this->pdo->prepare($sql);$s->execute($args);return $s;}
    private function has(string $table): bool{return (bool)$this->q('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',[$table])->fetchColumn();}
    public function scope(int $actor,bool $write=false,bool $finance=false): array {
        $u=$this->q('SELECT id,owner_user_id,workspace_role FROM users WHERE id=?',[$actor])->fetch(PDO::FETCH_ASSOC);if(!$u)throw new RuntimeException('Unauthorized',401);
        $owner=(int)($u['owner_user_id']?:$actor);$role=$owner===$actor?'owner':(string)$u['workspace_role'];
        $manage=in_array($role,['owner','admin','manager'],true);
        if(($write||$finance)&&!$manage)throw new RuntimeException('This action requires the workspace owner, admin or manager',403);
        return ['owner_id'=>$owner,'actor_id'=>$actor,'role'=>$role,'can_manage'=>$manage];
    }
    public function overview(int $actor): array {
        $scope=$this->scope($actor);$uid=$scope['owner_id'];$sites=$this->q('SELECT id,domain,company_name FROM websites WHERE user_id=? ORDER BY id',[$uid])->fetchAll(PDO::FETCH_ASSOC);
        $connections=$this->has('platform_connections')?$this->q('SELECT website_id,platform,status,token_expires_at,last_synced_at FROM platform_connections WHERE user_id=? ORDER BY platform',[$uid])->fetchAll(PDO::FETCH_ASSOC):[];
        $checks=$this->q('SELECT service_key,check_key,evidence,checked_at,checked_by FROM service_center_checks WHERE user_id=?',[$uid])->fetchAll(PDO::FETCH_ASSOC);
        $plan=self::planView($uid);$services=array_values(PlatformServiceRegistry::services());foreach($services as &$s){$s['plan']=$plan['services'][$s['key']]??null;$s['verification']=['state'=>'needs_validation','checks'=>[]];foreach($checks as $c)if($c['service_key']===$s['key'])$s['verification']['checks'][]=$c;$s['verification']['state']=count($s['verification']['checks'])===5?'recorded_verified':'needs_validation';$s['verification']['note']='Recorded evidence confirms the tested workflow only. Current provider permissions can change.';}unset($s);
        return ['scope'=>$scope,'services'=>$services,'websites'=>$sites,'connections'=>$connections,'runtime'=>self::runtime(),'ota_capabilities'=>OtaProviderCatalog::all(),'subscription'=>$plan['subscription']];
    }
    /** Services × current plan: entitlement, usage and the lowest upgrade. Billing failures degrade to unknown, never to a promise. */
    public static function planView(int $owner): array {
        try{$plans=BillingPlanCatalog::read();}catch(Throwable $e){$plans=[];}
        try{$resolved=FeatureResolver::resolve($owner);}catch(Throwable $e){$resolved=['features'=>[],'plan_name'=>null];}
        $active=class_exists('Subscription')?Subscription::activeSubscriptionRow($owner):null;$features=$active?($resolved['features']??[]):[];
        try{$usage=PlanUsage::summary($owner);}catch(Throwable $e){$usage=[];}
        $current=$active?(string)($resolved['plan_name']??''):null;$tier=$current!==null?preg_replace('/_(monthly|yearly)$/D','',$current):null;
        $names=[];foreach($plans as $k=>$p)$names[$k]=(string)$p['name'];
        return ['services'=>PlatformServiceRegistry::accountView($features,$usage,$plans,$current),'subscription'=>['active'=>(bool)$active,'plan'=>$tier,'plan_name'=>$tier!==null?($names[$tier]??$tier):null,'plans'=>$names,'plans_url'=>'/plans']];
    }
    public static function runtime(): array {
        $path=dirname(__DIR__,3).'/storage/cron/heartbeat';$hb=is_file($path)?trim((string)file_get_contents($path)):null;$stamp=$hb?strtotime($hb):false;$age=$stamp!==false?max(0,time()-$stamp):null;
        return ['scheduler'=>['state'=>$age===null?'not_observed':($age>180?'stale':'observed'),'last_heartbeat'=>$hb,'age_seconds'=>$age],'documents'=>KnowledgeDocumentReader::status(),'video_editing'=>VideoEditing::available(),'notes'=>['Provider credentials must be tested using their service screen.','Automatic jobs require the scheduler and queue worker on the hosting server.','HTTPS and webserver domain bindings are hosting operations.']];
    }
    public function recordCheck(int $actor,string $service,string $check,string $evidence,bool $remove=false): array {
        $scope=$this->scope($actor,true);$meta=ServiceCenterCatalog::get($service);if(!array_key_exists($check,$meta['acceptance_checks']))throw new InvalidArgumentException('Unknown verification check');
        if($remove){$this->q('DELETE FROM service_center_checks WHERE user_id=? AND service_key=? AND check_key=?',[$scope['owner_id'],$service,$check]);return ['removed'=>true];}
        $evidence=trim($evidence);if(mb_strlen($evidence)<12||mb_strlen($evidence)>500)throw new InvalidArgumentException('Provide verification evidence (12–500 characters).');
        $this->q('INSERT INTO service_center_checks(user_id,service_key,check_key,evidence,checked_by,checked_at) VALUES(?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE evidence=VALUES(evidence),checked_by=VALUES(checked_by),checked_at=NOW()',[$scope['owner_id'],$service,$check,$evidence,$actor]);return ['recorded'=>true];
    }
    public function sessions(int $actor): array {
        $hash=$_SESSION['browser_session_hash']??hash('sha256',(string)($_COOKIE['auth_token']??''));$rows=$this->q('SELECT id,token_hash,created_at,last_used_at,expires_at,revoked_at FROM browser_sessions WHERE user_id=? ORDER BY created_at DESC LIMIT 50',[$actor])->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$r){$r['current']=hash_equals((string)$hash,(string)$r['token_hash']);unset($r['token_hash']);}unset($r);return $rows;
    }
    public function revokeSession(int $actor,int $id): array {
        $row=$this->q('SELECT token_hash,refresh_token_id FROM browser_sessions WHERE id=? AND user_id=?',[$id,$actor])->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Session not found',404);
        $this->pdo->beginTransaction();try{$this->q('UPDATE browser_sessions SET revoked_at=COALESCE(revoked_at,NOW()) WHERE id=? AND user_id=?',[$id,$actor]);if($row['refresh_token_id'])$this->q('UPDATE user_refresh_tokens SET revoked_at=COALESCE(revoked_at,NOW()) WHERE id=? AND user_id=?',[$row['refresh_token_id'],$actor]);$this->pdo->commit();}catch(Throwable $e){$this->pdo->rollBack();throw $e;}
        $current=hash_equals((string)($_SESSION['browser_session_hash']??''),(string)$row['token_hash']);return ['revoked'=>true,'current_session'=>$current];
    }
    public function usage(int $actor): array {
        $s=$this->scope($actor,false,true);$uid=$s['owner_id'];$allowances=PlanUsage::summary($uid);
        foreach($allowances as $feature=>&$quota){$held=0;$creative=0;if($quota['subscription_id']){$held=(int)$this->q("SELECT COUNT(*) FROM service_usage_reservations r JOIN plan_usage_events e ON e.id=r.usage_event_id WHERE r.owner_user_id=? AND r.source='plan' AND r.state IN ('reserved','uncertain') AND e.subscription_id=? AND e.feature=? AND e.created_at>=?",[$uid,$quota['subscription_id'],$feature,$quota['period_start']?:'1970-01-01'])->fetchColumn();$creative=class_exists('CreativeReservations')?CreativeReservations::planHeld((int)$quota['subscription_id'],$feature):0;}$quota['consumed']=max(0,$quota['used']-$held);$quota['reserved']=$held+$creative;}unset($quota);
        return ['allowances'=>$allowances,'reservations'=>$this->q('SELECT token operation_id,feature_key,source,amount,state,created_at,updated_at FROM service_usage_reservations WHERE owner_user_id=? ORDER BY id DESC LIMIT 100',[$uid])->fetchAll(PDO::FETCH_ASSOC),'note'=>'Plan usage and reserved credit are separate from gateway payments.'];
    }
    public function operations(int $actor): array {
        $scope=$this->scope($actor);$uid=$scope['owner_id'];$rows=$this->q('SELECT operation_key operation_id,operation_label,state,created_at,updated_at FROM external_execution_attempts WHERE user_id=? ORDER BY updated_at DESC LIMIT 100',[$uid])->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$r){$r['operation_label']=preg_replace('/[a-f0-9]{32,}/','[reference]',(string)$r['operation_label']);}unset($r);return $rows;
    }
    public function paymentTimeline(int $actor,int $bookingId): array {
        $scope=$this->scope($actor,false,true);$uid=$scope['owner_id'];$booking=$this->q('SELECT id,booking_reference,status,total_amount,currency FROM bookings WHERE id=? AND user_id=?',[$bookingId,$uid])->fetch(PDO::FETCH_ASSOC);if(!$booking)throw new RuntimeException('Booking not found',404);
        $payments=$this->q('SELECT id,amount,currency,gateway,status,created_at,updated_at FROM payment_transactions WHERE booking_id=? AND user_id=? ORDER BY created_at,id LIMIT 200',[$bookingId,$uid])->fetchAll(PDO::FETCH_ASSOC);
        $refunds=$this->q('SELECT r.id,r.payment_transaction_id,r.amount,r.currency,r.status,r.created_at,r.updated_at FROM refunds r JOIN payment_transactions t ON t.id=r.payment_transaction_id AND t.user_id=r.user_id WHERE t.booking_id=? AND r.user_id=? ORDER BY r.created_at,r.id LIMIT 200',[$bookingId,$uid])->fetchAll(PDO::FETCH_ASSOC);
        return ['booking'=>$booking,'payments'=>$payments,'refunds'=>$refunds,'policy_requests'=>$this->q('SELECT id,amount,currency,state,refund_id,created_at,updated_at FROM booking_refund_requests WHERE booking_id=? AND user_id=? ORDER BY created_at,id LIMIT 200',[$bookingId,$uid])->fetchAll(PDO::FETCH_ASSOC),'status_history'=>$this->q('SELECT from_status,to_status,created_at FROM booking_status_history WHERE booking_id=? ORDER BY created_at,id LIMIT 200',[$bookingId])->fetchAll(PDO::FETCH_ASSOC),'note'=>'Booking value, collected payments and refunds are separate. Amounts retain their original currencies.'];
    }
    public function customerTimeline(int $actor,int $contactId): array {
        $scope=$this->scope($actor);$uid=$scope['owner_id'];$contact=$this->q('SELECT id,name,phone FROM crm_contacts WHERE id=? AND user_id=?',[$contactId,$uid])->fetch(PDO::FETCH_ASSOC);if(!$contact)throw new RuntimeException('CRM contact not found',404);
        $events=[];
        foreach($this->q('SELECT id,booking_reference,status,created_at,updated_at FROM bookings WHERE customer_id=? AND user_id=? ORDER BY updated_at DESC LIMIT 100',[$contactId,$uid])->fetchAll(PDO::FETCH_ASSOC) as $r)$events[]=['kind'=>'booking','id'=>$r['id'],'title'=>$r['booking_reference'],'state'=>$r['status'],'at'=>$r['updated_at']];
        foreach($this->q('SELECT id,title,status,updated_at FROM crm_deals WHERE contact_id=? AND owner_user_id=? ORDER BY updated_at DESC LIMIT 100',[$contactId,$uid])->fetchAll(PDO::FETCH_ASSOC) as $r)$events[]=['kind'=>'deal','id'=>$r['id'],'title'=>$r['title'],'state'=>$r['status'],'at'=>$r['updated_at']];
        $phone=preg_replace('/[^0-9]/','',(string)$contact['phone']);$conversationNote='Conversation matching requires a unique phone number in this workspace.';
        if(strlen($phone)>=7){$matching=$this->q("SELECT COUNT(*) FROM crm_contacts WHERE user_id=? AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-',''),'(',''),')','')=?",[$uid,$phone])->fetchColumn();
            if((int)$matching===1){foreach($this->q("SELECT m.id,m.website_id,m.message_direction,m.platform,m.bot_status,m.created_at FROM chat_messages m JOIN websites w ON w.id=m.website_id AND w.user_id=m.user_id WHERE m.user_id=? AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(m.customer_phone,'+',''),' ',''),'-',''),'(',''),')','')=? ORDER BY m.created_at DESC LIMIT 100",[$uid,$phone])->fetchAll(PDO::FETCH_ASSOC) as $r)$events[]=['kind'=>'conversation','id'=>$r['id'],'title'=>$r['platform'].' · '.$r['message_direction'],'state'=>$r['bot_status'],'at'=>$r['created_at']];$conversationNote='Conversations matched using this unique workspace phone. No fuzzy identity matching.';}
        }
        usort($events,fn($a,$b)=>strcmp($b['at'],$a['at']));unset($contact['phone']);return ['contact'=>$contact,'events'=>array_slice($events,0,200),'note'=>$conversationNote];
    }
    public function rates(int $actor): array {$s=$this->scope($actor);return ['products'=>$this->q('SELECT id,name,currency,price FROM crm_products WHERE user_id=? AND is_active=1 ORDER BY name LIMIT 200',[$s['owner_id']])->fetchAll(PDO::FETCH_ASSOC),'rules'=>$this->q('SELECT * FROM hotel_rate_rules WHERE user_id=? ORDER BY date_from,id',[$s['owner_id']])->fetchAll(PDO::FETCH_ASSOC)];}
    public function saveRate(int $actor,array $input): array {
        $scope=$this->scope($actor,true);$uid=$scope['owner_id'];$pid=(int)($input['product_id']??0);$from=(string)($input['date_from']??'');$to=(string)($input['date_to']??'');$price=$input['nightly_price']??null;$n=filter_var($input['min_nights']??1,FILTER_VALIDATE_INT);$name=trim((string)($input['name']??''));
        if(!InventoryService::isValidDate($from)||!InventoryService::isValidDate($to)||$to<$from||!is_numeric($price)||!is_finite((float)$price)||(float)$price<0||$n===false||$n<1||$n>30||mb_strlen($name)<2||mb_strlen($name)>100)throw new InvalidArgumentException('Provide a valid season, nightly price, name and minimum stay (1–30 nights).');
        $this->pdo->beginTransaction();try{if(!$this->q('SELECT id FROM crm_products WHERE id=? AND user_id=? FOR UPDATE',[$pid,$uid])->fetchColumn())throw new RuntimeException('Product not found',404);
            if($this->q('SELECT id FROM hotel_rate_rules WHERE user_id=? AND product_id=? AND date_from<=? AND date_to>=? LIMIT 1',[$uid,$pid,$to,$from])->fetchColumn())throw new RuntimeException('Season dates overlap an existing rule. Remove or adjust that rule first.');
            $this->q('INSERT INTO hotel_rate_rules(user_id,product_id,name,date_from,date_to,nightly_price,min_nights,created_at) VALUES(?,?,?,?,?,?,?,NOW())',[$uid,$pid,$name,$from,$to,(float)$price,$n]);$id=(int)$this->pdo->lastInsertId();$this->pdo->commit();return ['id'=>$id];
        }catch(Throwable $e){$this->pdo->rollBack();throw $e;}
    }
    public function deleteRate(int $actor,int $id): array{$s=$this->scope($actor,true);$this->q('DELETE FROM hotel_rate_rules WHERE id=? AND user_id=?',[$id,$s['owner_id']]);return ['removed'=>true];}
}
