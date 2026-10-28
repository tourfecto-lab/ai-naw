<?php
require_once __DIR__.'/SeoImpactRules.php';
require_once __DIR__.'/SeoImpactSources.php';
require_once __DIR__.'/SeoPlatformBookingService.php';
final class SeoImpactService {
    private Database $db;
    public function __construct(?Database $db=null){$this->db=$db??Database::getInstance();}
    public function ready(): bool { $r=$this->db->query("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('seo_impact_monitors','seo_impact_changes','seo_booking_sources','seo_paid_booking_events','seo_ai_observations')");return (int)($r[0]['n']??0)===5; }
    private function requireReady(): void {if(!$this->ready())throw new InvalidArgumentException('Run the Phase 4 SQL migration first.');}
    private function site(int $uid,int $wid): array {$r=$this->db->query('SELECT * FROM websites WHERE id=? AND user_id=?',[$wid,$uid]);$s=$r[0]??null;if(!$s)throw new InvalidArgumentException('Website unavailable.');$s['url']=(string)($s[Website::urlColumn()]??$s['main_url']??'');if(!preg_match('#^https?://#i',$s['url']))$s['url']='https://'.$s['url'];return $s;}
    private function monitor(int $uid,int $id): array {$r=$this->db->query('SELECT * FROM seo_impact_monitors WHERE id=? AND user_id=?',[$id,$uid]);if(!$r)throw new InvalidArgumentException('Monitor unavailable.');return $r[0];}
    public static function automaticWindows(string $date,?string $today=null): array {
        $out=[];foreach([7,14,28] as $days){$p=SeoImpactRules::periods($date,$days,$today);$out[]=['window_days'=>$days,'due_at'=>$p['due_at']];}return $out;
    }
    /** Every applied task has three durable mappings; same-page/day comparisons are shared, not falsely causal. */
    public function recordAppliedTask(int $uid,int $wid,int $taskId): array {
        if(!$this->ready()||!$this->db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='seo_auto_impact_monitors'"))return [];
        $site=$this->site($uid,$wid);
        return $this->db->transaction(function()use($uid,$wid,$taskId,$site){
            $this->db->query('SELECT id FROM websites WHERE id=? AND user_id=? FOR UPDATE',[$wid,$uid]);
            $rows=$this->db->query("SELECT * FROM seo_expert_tasks WHERE id=? AND website_id=? AND user_id=? AND status IN ('active','verified')",[$taskId,$wid,$uid]);if(!$rows)return [];$task=$rows[0];
            $url=$task['kind']==='content'?($task['published_url']??''):$task['page_url'];if($url==='')return [];$page=SeoImpactRules::page($url,$site['url']);
            $date=substr((string)($task['checked_at']??$task['updated_at']??gmdate('Y-m-d')),0,10);SeoImpactRules::periods($date,7);
            $key='seo_task_'.$taskId;
            $this->db->query("INSERT IGNORE INTO seo_impact_changes(user_id,website_id,external_key,page_url,change_date,source,created_at) VALUES(?,?,?,?,?,'seo_expert_applied',UTC_TIMESTAMP())",[$uid,$wid,$key,$page['url'],$date]);
            $change=$this->db->query('SELECT change_date,page_url FROM seo_impact_changes WHERE website_id=? AND user_id=? AND external_key=?',[$wid,$uid,$key]);$date=$change[0]['change_date'];$ids=[];
            // Verification may switch a PHP publication from /guides/ to its working query URL.
            // Preserve the original change day and old results; only move this task's mappings.
            $old=$this->db->query('SELECT monitor_id FROM seo_auto_impact_monitors WHERE website_id=? AND user_id=? AND task_id=?',[$wid,$uid,$taskId]);
            if($change[0]['page_url']!==$page['url'])$this->db->query('UPDATE seo_impact_changes SET page_url=? WHERE website_id=? AND user_id=? AND external_key=?',[$page['url'],$wid,$uid,$key]);
            foreach(self::automaticWindows($date) as $window){$days=$window['window_days'];
                $this->db->query("INSERT IGNORE INTO seo_impact_monitors(user_id,website_id,page_url,page_path,page_hash,change_date,anchor_source,window_days,goal_event,channel,status,next_due_at,created_at,updated_at) VALUES(?,?,?,?,?,?,'seo_expert_applied',?,'','organic','queued',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())",[$uid,$wid,$page['url'],$page['path'],$page['hash'],$date,$days,$window['due_at']]);
                $m=$this->db->query("SELECT id FROM seo_impact_monitors WHERE website_id=? AND user_id=? AND page_hash=? AND change_date=? AND window_days=? AND goal_event='' AND channel='organic'",[$wid,$uid,$page['hash'],$date,$days]);$id=(int)$m[0]['id'];
                $this->db->exec('INSERT INTO seo_auto_impact_monitors(website_id,user_id,task_id,window_days,monitor_id) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE monitor_id=VALUES(monitor_id)',[$wid,$uid,$taskId,$days,$id]);
                $this->db->query("UPDATE seo_impact_monitors SET status='queued',updated_at=UTC_TIMESTAMP() WHERE id=? AND status='superseded'",[$id]);
                $this->enqueue($id,max(0,strtotime($window['due_at'].' UTC')-time()));$ids[]=$id;
            }
            foreach(array_unique(array_column($old,'monitor_id')) as $oldId){
                if(in_array((int)$oldId,$ids,true))continue;
                $this->db->query("UPDATE seo_impact_monitors SET status='superseded',next_due_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=? AND user_id=? AND website_id=? AND anchor_source='seo_expert_applied' AND NOT EXISTS (SELECT 1 FROM seo_auto_impact_monitors WHERE monitor_id=?)",[$oldId,$uid,$wid,$oldId]);
            }
            return $ids;
        });
    }
    public function syncAutomatic(?int $runId=null,int $limit=100): int {
        if(!$this->ready()||!$this->db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='seo_auto_impact_monitors'"))return 0;
        $sql="SELECT t.id,t.user_id,t.website_id FROM seo_expert_tasks t JOIN websites w ON w.id=t.website_id AND w.user_id=t.user_id WHERE t.status IN ('active','verified') AND t.updated_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 365 DAY) AND ((SELECT COUNT(*) FROM seo_auto_impact_monitors a WHERE a.website_id=t.website_id AND a.user_id=t.user_id AND a.task_id=t.id)<3 OR (t.kind='content' AND t.published_url<>'' AND EXISTS (SELECT 1 FROM seo_auto_impact_monitors a LEFT JOIN seo_impact_monitors m ON m.id=a.monitor_id AND m.user_id=a.user_id AND m.website_id=a.website_id WHERE a.website_id=t.website_id AND a.user_id=t.user_id AND a.task_id=t.id AND (m.id IS NULL OR BINARY m.page_url<>BINARY t.published_url))))";
        $args=[];if($runId!==null){$sql.=' AND t.run_id=?';$args[]=$runId;}$sql.=' ORDER BY t.id LIMIT '.max(1,min(500,$limit));$count=0;
        foreach($this->db->query($sql,$args) as $t){try{$count+=count($this->recordAppliedTask((int)$t['user_id'],(int)$t['website_id'],(int)$t['id']));}catch(Throwable $e){Logger::warning('Automatic impact monitor needs review',['task_id'=>$t['id']]);}}return $count;
    }
    public function overview(int $uid,int $wid): array {
        $site=$this->site($uid,$wid);if(!$this->ready())return ['installed'=>false,'monitors'=>[],'changes'=>[],'ai'=>[]];
        $monitors=$this->db->query('SELECT * FROM seo_impact_monitors WHERE user_id=? AND website_id=? ORDER BY id DESC LIMIT 20',[$uid,$wid]);foreach($monitors as &$m){$m['periods']=null;try{$m['periods']=SeoImpactRules::periods($m['change_date'],(int)$m['window_days']);}catch(InvalidArgumentException $e){/* Historic comparisons remain readable beyond the creation window. */}$m['result']=json_decode((string)$m['result_json'],true);unset($m['result_json']);}unset($m);
        $changes=$this->db->query('SELECT id,page_url,change_date,source FROM seo_impact_changes WHERE user_id=? AND website_id=? ORDER BY id DESC LIMIT 50',[$uid,$wid]);
        $sources=$this->db->query('SELECT enabled,source_status,tracking_started_at,last_received_at FROM seo_booking_sources WHERE user_id=? AND website_id=?',[$uid,$wid]);
        $ai=$this->db->query('SELECT provider,page_path,date_start,date_end,citations,source_kind,evidence_url FROM seo_ai_observations WHERE user_id=? AND website_id=? ORDER BY id DESC LIMIT 30',[$uid,$wid]);
        $summary=$this->db->query("SELECT COUNT(*) history_count,
            COALESCE(SUM(status<>'superseded'),0) active_comparisons,
            COUNT(DISTINCT CASE WHEN status<>'superseded' THEN page_hash END) monitored_pages,
            COALESCE(SUM(status='reported' AND result_json IS NOT NULL),0) collected_comparisons,
            COALESCE(SUM(status IN ('queued','waiting_period','waiting_data')),0) waiting_comparisons,
            COALESCE(SUM(status NOT IN ('superseded','reported','queued','waiting_period','waiting_data')),0) needs_review_comparisons,
            MIN(CASE WHEN status IN ('queued','waiting_period','waiting_data') THEN COALESCE(next_due_at,TIMESTAMP(DATE_ADD(change_date,INTERVAL (window_days+3) DAY),'04:00:00')) END) next_collection_at
            FROM seo_impact_monitors WHERE user_id=? AND website_id=?",[$uid,$wid])[0];
        foreach(['history_count','active_comparisons','monitored_pages','collected_comparisons','waiting_comparisons','needs_review_comparisons'] as $key)$summary[$key]=(int)$summary[$key];
        return ['installed'=>true,'summary'=>$summary,'measurement_sources'=>(new SeoImpactSources($this->db))->readiness($uid,$wid,$site['url']),'platform_bookings'=>(new SeoPlatformBookingService($this->db))->overview($uid,$wid),'monitors'=>$monitors,'changes'=>$changes,'booking_source'=>$sources[0]??null,'ai'=>$ai,'note'=>'Before/after is observational; seasons, ads, other edits and tracking changes can affect results.'];
    }
    public function create(int $uid,int $wid,array $in): array {
        $this->requireReady();$site=$this->site($uid,$wid);$anchorSource='client_declared_date';
        if(!empty($in['change_id'])){$r=$this->db->query('SELECT * FROM seo_impact_changes WHERE id=? AND user_id=? AND website_id=?',[(int)$in['change_id'],$uid,$wid]);if(!$r)throw new InvalidArgumentException('Change unavailable.');$in['page_url']=$r[0]['page_url'];$in['change_date']=$r[0]['change_date'];$anchorSource=$r[0]['source'];}
        elseif(!empty($in['task_id'])){$r=$this->db->query("SELECT page_url,checked_at FROM seo_expert_tasks WHERE id=? AND user_id=? AND website_id=? AND status='verified'",[(int)$in['task_id'],$uid,$wid]);if(!$r||empty($r[0]['checked_at']))throw new InvalidArgumentException('Verified task unavailable.');$in['page_url']=$r[0]['page_url'];$in['change_date']=substr($r[0]['checked_at'],0,10);$anchorSource='task_verification_date';}
        elseif(($in['date_confirmed']??false)!==true)throw new InvalidArgumentException('Confirm the declared change date.');
        $page=SeoImpactRules::page((string)($in['page_url']??''),$site['url']);$days=(int)($in['window_days']??28);$date=(string)($in['change_date']??'');SeoImpactRules::periods($date,$days);
        $goal=(string)($in['goal_event']??'');if($goal!==''&&!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D',$goal))throw new InvalidArgumentException('Invalid GA4 key-event name.');$channel=$in['channel']??'organic';if(!in_array($channel,['organic','all'],true))throw new InvalidArgumentException('Invalid channel.');
        if(!ExternalExecution::lock('seo-impact-site:'.$wid))throw new InvalidArgumentException('Measurement setup busy.');
        try {
            $r=$this->db->query('SELECT id FROM seo_impact_monitors WHERE website_id=? AND user_id=? AND page_hash=? AND change_date=? AND window_days=? AND goal_event=? AND channel=?',[$wid,$uid,$page['hash'],$date,$days,$goal,$channel]);
            if(!$r){$count=$this->db->query('SELECT COUNT(*) n FROM seo_impact_monitors WHERE website_id=? AND user_id=? AND anchor_source NOT IN (\'seo_expert_applied\',\'wordpress_auto_applied\')',[$wid,$uid]);if((int)($count[0]['n']??0)>=20)throw new InvalidArgumentException('Maximum 20 monitored comparisons per website.');
                $id=(int)$this->db->query("INSERT INTO seo_impact_monitors(user_id,website_id,page_url,page_path,page_hash,change_date,anchor_source,window_days,goal_event,channel,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,'queued',UTC_TIMESTAMP(),UTC_TIMESTAMP())",[$uid,$wid,$page['url'],$page['path'],$page['hash'],$date,$anchorSource,$days,$goal,$channel]);
            }else $id=(int)$r[0]['id'];$this->enqueue($id);return $this->overview($uid,$wid);
        }finally{ExternalExecution::unlock('seo-impact-site:'.$wid);}
    }
    private function enqueue(int $id,int $delay=0): void {
        $payload=json_encode(['monitor_id'=>$id]);$r=$this->db->query("SELECT id FROM jobs WHERE job_class='SeoImpactCollectJob' AND payload=? AND status IN ('pending','processing') LIMIT 1",[$payload]);if($r)return;
        if(!(new QueueManager($this->db))->push(SeoImpactCollectJob::class,['monitor_id'=>$id],'seo',$delay))throw new RuntimeException('Measurement queue unavailable.');
    }
    public function refresh(int $uid,int $id): array {
        $this->requireReady();$m=$this->monitor($uid,$id);$this->site($uid,(int)$m['website_id']);
        if($m['status']==='superseded')throw new InvalidArgumentException('This historic comparison was replaced after the publication URL changed.');
        if(!empty($m['last_collected_at'])&&strtotime($m['last_collected_at'].' UTC')>time()-900)throw new InvalidArgumentException('Wait 15 minutes between provider refreshes.');
        // Refresh replaces a future pending job only; it never interrupts a processing job.
        $this->db->query("UPDATE jobs SET available_at=UTC_TIMESTAMP() WHERE job_class='SeoImpactCollectJob' AND payload=? AND status='pending'",[json_encode(['monitor_id'=>$id])]);$this->enqueue($id);return $this->overview($uid,(int)$m['website_id']);
    }
    public function collect(int $id): void {
        $this->requireReady();$r=$this->db->query('SELECT * FROM seo_impact_monitors WHERE id=?',[$id]);if(!$r||$r[0]['status']==='superseded')return;$m=$r[0];$uid=(int)$m['user_id'];$wid=(int)$m['website_id'];$site=$this->site($uid,$wid);SeoImpactRules::page($m['page_url'],$site['url']);
        if(!ExternalExecution::lock('seo-impact-monitor:'.$id))throw new RuntimeException('Monitor busy.');
        try {
            $periods=SeoImpactRules::periods($m['change_date'],(int)$m['window_days']);$source=new SeoImpactSources($this->db);$search=$source->search($m,$periods);$analytics=$source->analytics($m,$periods);
            $out=['periods'=>$periods,'search'=>$search,'analytics'=>$analytics,'bookings'=>$this->bookingReport($m,$periods),'ai'=>$this->aiReport($m,$periods),'differences'=>[],'causality_proven'=>false,'collected_at'=>gmdate('c')];
            foreach(['search'=>['clicks','impressions','ctr','position'],'analytics'=>['sessions','goal_session_rate','key_events','purchase_events']] as $name=>$fields)foreach($fields as $field)$out['differences'][$name][$field]=SeoImpactRules::difference($out[$name]['before'][$field]??null,$out[$name]['after'][$field]??null,in_array($field,['ctr','goal_session_rate'],true));
            $hasComparable=false;foreach($out['differences'] as $diffs)foreach($diffs as $diff)if($diff['state']!=='not_comparable')$hasComparable=true;
            $status=!$periods['ready']?'waiting_period':($hasComparable?'reported':'sources_unavailable');
            $this->db->query("UPDATE seo_impact_monitors SET status=?,result_json=?,last_collected_at=UTC_TIMESTAMP(),next_due_at=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND status<>'superseded'",[$status,json_encode($out,JSON_THROW_ON_ERROR),!$periods['ready']?$periods['due_at']:null,$id]);
            if(!$periods['ready']){
                // The current job is still processing, so schedule its one future successor explicitly.
                $exists=$this->db->query("SELECT id FROM jobs WHERE job_class='SeoImpactCollectJob' AND payload=? AND status='pending' LIMIT 1",[json_encode(['monitor_id'=>$id])]);
                if(!$exists&&(new QueueManager($this->db))->push(SeoImpactCollectJob::class,['monitor_id'=>$id],'seo',max(60,strtotime($periods['due_at'].' UTC')-time()))===false)throw new RuntimeException('Future measurement queue unavailable.');
            }
        }finally{ExternalExecution::unlock('seo-impact-monitor:'.$id);}
    }
    private function bookingReport(array $m,array $periods): array {
        $r=$this->db->query('SELECT enabled,source_status,tracking_started_at,last_received_at FROM seo_booking_sources WHERE website_id=? AND user_id=?',[$m['website_id'],$m['user_id']]);if(!$r||!$r[0]['enabled'])return ['state'=>'not_connected'];$s=$r[0];if(!in_array($s['source_status'],['connected','receiving'],true))return ['state'=>'configuration_unconfirmed'];$out=['state'=>'received_events_only','tracking_started_at'=>$s['tracking_started_at'],'last_received_at'=>$s['last_received_at'],'traffic_channel'=>'not_inferred'];
        foreach(['before','after'] as $p){if($p==='after'&&!$periods['ready']){$out[$p]=['state'=>'waiting_period'];continue;}
            $rows=$this->db->query("SELECT currency,COUNT(*) paid_orders,SUM(amount) net_active_order_value FROM seo_paid_booking_events WHERE website_id=? AND user_id=? AND page_path=? AND paid_date BETWEEN ? AND ? AND status='paid' GROUP BY currency",[$m['website_id'],$m['user_id'],$m['page_path'],$periods[$p]['start'],$periods[$p]['end']]);
            $unknown=$this->db->query('SELECT COUNT(*) n FROM seo_paid_booking_events WHERE website_id=? AND user_id=? AND page_path IS NULL AND paid_date BETWEEN ? AND ?',[$m['website_id'],$m['user_id'],$periods[$p]['start'],$periods[$p]['end']]);
            $out[$p]=['state'=>$periods[$p]['start']<substr($s['tracking_started_at'],0,10)?'not_fully_covered':'received_records','currencies'=>$rows,'unattributed_orders'=>(int)($unknown[0]['n']??0),'note'=>'Received active paid orders, not guaranteed complete store totals. Historic orders before setup are not backfilled.'];
        }return $out;
    }
    private function aiReport(array $m,array $periods): array {
        $out=['state'=>'user_supplied_evidence_only','before'=>[],'after'=>[]];foreach(['before','after'] as $p)$out[$p]=$this->db->query('SELECT provider,citations,source_kind,evidence_url FROM seo_ai_observations WHERE website_id=? AND user_id=? AND page_hash=? AND date_start=? AND date_end=?',[$m['website_id'],$m['user_id'],$m['page_hash'],$periods[$p]['start'],$periods[$p]['end']]);return $out;
    }
    public function importAi(int $uid,int $wid,array $in): array {
        $this->requireReady();$site=$this->site($uid,$wid);$rows=$in['rows']??null;if(!is_array($rows)||!$rows||count($rows)>100)throw new InvalidArgumentException('Import 1–100 citation rows.');$validated=[];
        foreach($rows as $r){$p=SeoImpactRules::page((string)($r['page_url']??''),$site['url']);$provider=$r['provider']??'';if(!in_array($provider,['bing_copilot','google_ai','chatgpt','perplexity','claude'],true))throw new InvalidArgumentException('Invalid AI provider.');
            $start=SeoImpactRules::date((string)($r['date_start']??''));$end=SeoImpactRules::date((string)($r['date_end']??''));if($start>$end||$end>SeoImpactRules::date(gmdate('Y-m-d'))||$start->diff($end)->days>93)throw new InvalidArgumentException('Invalid citation period.');
            $n=filter_var($r['citations']??null,FILTER_VALIDATE_INT);if($n===false||$n<0||$n>1000000000)throw new InvalidArgumentException('Invalid citation count.');$kind=$r['source_kind']??'manual_sample';if(!in_array($kind,['user_supplied_export','manual_sample'],true))throw new InvalidArgumentException('Invalid evidence kind.');
            $evidence=(string)($r['evidence_url']??'');if(strlen($evidence)>1800||($evidence!==''&&(!filter_var($evidence,FILTER_VALIDATE_URL)||parse_url($evidence,PHP_URL_SCHEME)!=='https'||parse_url($evidence,PHP_URL_USER)||parse_url($evidence,PHP_URL_PASS))))throw new InvalidArgumentException('Use a safe HTTPS evidence URL.');$validated[]=[$wid,$uid,$provider,$p['path'],$p['hash'],$start->format('Y-m-d'),$end->format('Y-m-d'),$n,$kind,$evidence];
        }
        $this->db->transaction(function()use($validated){foreach($validated as $v)$this->db->query('INSERT INTO seo_ai_observations(website_id,user_id,provider,page_path,page_hash,date_start,date_end,citations,source_kind,evidence_url,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE citations=VALUES(citations),evidence_url=VALUES(evidence_url),created_at=UTC_TIMESTAMP()',$v);});return $this->overview($uid,$wid);
    }
    public function configureBookings(int $uid,int $wid,bool $enabled): array {
        $this->requireReady();$site=$this->site($uid,$wid);if(!$enabled){$this->db->query('UPDATE seo_booking_sources SET enabled=0 WHERE website_id=? AND user_id=?',[$wid,$uid]);return $this->overview($uid,$wid);}
        $base=defined('APP_URL')?APP_URL:'';if(!str_starts_with((string)$base,'https://'))throw new InvalidArgumentException('Configure HTTPS APP_URL before linking booking receipts.');
        $source=new SeoImpactSources($this->db);$source->assertWordPressTarget($uid,$wid,$site['url']);
        $r=$this->db->query('SELECT * FROM seo_booking_sources WHERE website_id=? AND user_id=?',[$wid,$uid]);$secret=$r?(new Encryption())->decrypt($r[0]['secret_enc']):bin2hex(random_bytes(32));
        $this->db->query("INSERT INTO seo_booking_sources(website_id,user_id,secret_enc,source_url,enabled,tracking_started_at) VALUES(?,?,?,?,1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE enabled=1,source_url=VALUES(source_url),user_id=VALUES(user_id),secret_enc=VALUES(secret_enc),source_status='pending'",[$wid,$uid,(new Encryption())->encrypt($secret),$site['url']]);
        $source->wordpress($uid,$wid,$site['url'],['endpoint'=>rtrim($base,'/').'/api/seo-impact/booking/'.$wid,'secret'=>$secret]);$this->db->query("UPDATE seo_booking_sources SET source_status='connected' WHERE website_id=? AND user_id=?",[$wid,$uid]);return $this->overview($uid,$wid);
    }
    public function ingest(int $wid,string $secret,array $in): void {
        $this->requireReady();if(!preg_match('/^[a-f0-9]{64}$/D',$secret))throw new InvalidArgumentException('Unauthorized booking source.');$r=$this->db->query('SELECT * FROM seo_booking_sources WHERE website_id=? AND enabled=1',[$wid]);if(!$r||!hash_equals((new Encryption())->decrypt($r[0]['secret_enc']),$secret))throw new InvalidArgumentException('Unauthorized booking source.');
        $s=$r[0];$site=$this->site((int)$s['user_id'],$wid);if(!WordPressConnector::hostsEqual((string)parse_url($site['url'],PHP_URL_HOST),(string)parse_url($s['source_url'],PHP_URL_HOST)))throw new InvalidArgumentException('Booking source website changed.');$event=SeoImpactRules::booking($in,$site['url']);
        $this->db->transaction(function()use($s,$event,$wid,$secret){
            $locked=$this->db->query('SELECT * FROM seo_booking_sources WHERE website_id=? FOR UPDATE',[$wid]);if(!$locked||!$locked[0]['enabled']||(int)$locked[0]['user_id']!==(int)$s['user_id']||!hash_equals((new Encryption())->decrypt($locked[0]['secret_enc']),$secret))throw new InvalidArgumentException('Booking source changed or disabled.');$old=$this->db->query('SELECT source_version,status FROM seo_paid_booking_events WHERE website_id=? AND booking_key=?',[$wid,$event['booking_key']]);
            if($old&&((int)$old[0]['source_version']>$event['source_version']||((int)$old[0]['source_version']===$event['source_version']&&$old[0]['status']!=='paid'&&$event['status']==='paid')))return;
            $this->db->query('INSERT INTO seo_paid_booking_events(website_id,user_id,booking_key,source_version,page_path,paid_date,amount,currency,status,received_at) VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),source_version=VALUES(source_version),page_path=VALUES(page_path),paid_date=VALUES(paid_date),amount=VALUES(amount),currency=VALUES(currency),status=VALUES(status),received_at=UTC_TIMESTAMP()',[$wid,$s['user_id'],$event['booking_key'],$event['source_version'],$event['page_path'],$event['paid_date'],$event['amount'],$event['currency'],$event['status']]);
            $this->db->query("UPDATE seo_booking_sources SET last_received_at=UTC_TIMESTAMP(),source_status='receiving' WHERE website_id=?",[$wid]);
        });
    }
    public function recordEditorChange(int $uid,int $wid,array $result): void {
        if(($result['state']??'')!=='saved'||empty($result['operation_id'])||!$this->ready())return;$site=$this->site($uid,$wid);$page=SeoImpactRules::page((string)($result['page']['url']??''),$site['url']);
        $key='wp_'.preg_replace('/[^a-zA-Z0-9_-]/','',(string)$result['operation_id']);if(strlen($key)>100)return;
        $this->db->query("INSERT IGNORE INTO seo_impact_changes(user_id,website_id,external_key,page_url,change_date,source,created_at) VALUES(?,?,?,?,UTC_DATE(),'wordpress_stored_verification',UTC_TIMESTAMP())",[$uid,$wid,$key,$page['url']]);
        $date=$this->db->query('SELECT change_date FROM seo_impact_changes WHERE website_id=? AND user_id=? AND external_key=?',[$wid,$uid,$key])[0]['change_date'];
        foreach(self::automaticWindows($date) as $window){
            $this->db->query("INSERT IGNORE INTO seo_impact_monitors(user_id,website_id,page_url,page_path,page_hash,change_date,anchor_source,window_days,goal_event,channel,status,next_due_at,created_at,updated_at) VALUES(?,?,?,?,?,?,'wordpress_auto_applied',?,'','organic','queued',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())",[$uid,$wid,$page['url'],$page['path'],$page['hash'],$date,$window['window_days'],$window['due_at']]);
            $m=$this->db->query("SELECT id FROM seo_impact_monitors WHERE website_id=? AND user_id=? AND page_hash=? AND change_date=? AND window_days=? AND goal_event='' AND channel='organic'",[$wid,$uid,$page['hash'],$date,$window['window_days']]);
            $this->enqueue((int)$m[0]['id'],max(0,strtotime($window['due_at'].' UTC')-time()));
        }
    }
}
