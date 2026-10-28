<?php
require_once __DIR__.'/../SeoExpert/SeoKeywordConflictReview.php';
/** Shared confirmed facts; status comes from actual persisted service records. */
class BusinessHubService
{
    private $db;private PDO $pdo;
    public function __construct(?Database $db=null){$this->db=$db??Database::getInstance();$this->pdo=$this->db->getConnection();}
    private function q(string $sql,array $args=[]): PDOStatement{$s=$this->pdo->prepare($sql);$s->execute($args);return $s;}
    public function has(string $table): bool{return (bool)$this->q('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?',[$table])->fetchColumn();}
    private function own(int $uid,int $wid): array{$row=$this->q('SELECT * FROM websites WHERE id=? AND user_id=?',[$wid,$uid])->fetch(PDO::FETCH_ASSOC);if(!$row)throw new InvalidArgumentException('Website unavailable');return $row;}
    public static function siteKey(string $url): string{if(!preg_match('#^https?://#i',$url))$url='https://'.$url;$p=parse_url($url);return strtolower((string)($p['host']??'')).':'.($p['port']??'').'/' . trim((string)($p['path']??''),'/');}
    public function profile(int $uid,int $wid): ?array{$this->own($uid,$wid);if(!$this->has('business_hub_profiles'))return null;$row=$this->q('SELECT version,facts_json,confirmed_at,updated_at FROM business_hub_profiles WHERE website_id=? AND user_id=?',[$wid,$uid])->fetch(PDO::FETCH_ASSOC);if(!$row)return null;$row['facts']=json_decode($row['facts_json'],true,512,JSON_THROW_ON_ERROR);unset($row['facts_json']);$site=$this->own($uid,$wid);$url=(string)($site[Website::urlColumn()]??$site['main_url']??'');$row['needs_reconfirmation']=empty($row['facts']['source_site_url'])||self::siteKey($row['facts']['source_site_url'])!==self::siteKey($url);if($row['needs_reconfirmation'])$row['facts']=[];return $row;}
    public static function validate(array $input): array{
        if(($input['confirmed']??false)!==true)throw new InvalidArgumentException('Confirm the business facts before sharing them across services');
        $facts=[];foreach(['business_name'=>150,'business_facts'=>1000,'audience'=>500,'goal'=>500,'offers'=>1500,'cta'=>300] as $key=>$max){$v=$input[$key]??'';if(!is_string($v))throw new InvalidArgumentException('Invalid '.$key);$v=trim($v);if(mb_strlen($v)>$max||(!in_array($key,['offers'],true)&&mb_strlen($v)<3)||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$v))throw new InvalidArgumentException('Invalid '.$key.' length');$facts[$key]=$v;}
        foreach(['phone'=>50,'email'=>200,'logo_url'=>500] as $key=>$max){$v=trim((string)($input[$key]??''));if(strlen($v)>$max)throw new InvalidArgumentException('Invalid '.$key);if($key==='email'&&$v!==''&&!filter_var($v,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Invalid email');if($key==='logo_url'&&$v!==''){if(!filter_var($v,FILTER_VALIDATE_URL)||parse_url($v,PHP_URL_SCHEME)!=='https'||parse_url($v,PHP_URL_USER)!==null||parse_url($v,PHP_URL_PASS)!==null)throw new InvalidArgumentException('Logo must use a public HTTPS URL without login details');}if($key==='phone'&&$v!==''&&!preg_match('/^\+?[0-9 ()-]{6,50}$/D',$v))throw new InvalidArgumentException('Invalid phone');$facts[$key]=$v;}
        $facts['language']=$input['language']??'en';if(!in_array($facts['language'],['ar','en'],true))throw new InvalidArgumentException('Choose Arabic or English');
        $facts['timezone']=$input['timezone']??'UTC';if(!is_string($facts['timezone'])||!in_array($facts['timezone'],DateTimeZone::listIdentifiers(),true))throw new InvalidArgumentException('Choose a valid timezone');return $facts;
    }
    public function save(int $uid,int $wid,array $input): array{
        if(!$this->has('business_hub_profiles'))throw new InvalidArgumentException('Install the Business Hub SQL update');$facts=self::validate($input);$version=filter_var($input['version']??0,FILTER_VALIDATE_INT);if($version===false||$version<0)throw new InvalidArgumentException('Invalid profile version');
        return $this->db->transaction(function()use($uid,$wid,$facts,$version){$this->q('SELECT id FROM users WHERE id=? FOR UPDATE',[$uid]);$site=$this->own($uid,$wid);$facts['source_site_url']=(string)($site[Website::urlColumn()]??$site['main_url']??'');$old=$this->q('SELECT version FROM business_hub_profiles WHERE website_id=? AND user_id=? FOR UPDATE',[$wid,$uid])->fetchColumn();if(($old?(int)$old:0)!==$version)throw new InvalidArgumentException('Company profile changed. Refresh and review before saving');
            if($old&&$this->profile($uid,$wid)['facts']===$facts)return $this->profile($uid,$wid);
            if($old)$this->q('UPDATE business_hub_profiles SET version=version+1,facts_json=?,confirmed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE website_id=? AND user_id=?',[json_encode($facts,JSON_THROW_ON_ERROR),$wid,$uid]);
            else $this->q('INSERT INTO business_hub_profiles(website_id,user_id,facts_json,confirmed_at,created_at,updated_at) VALUES(?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())',[$wid,$uid,json_encode($facts,JSON_THROW_ON_ERROR)]);
            return $this->profile($uid,$wid);
        });
    }
    public function assertRevision(int $uid,int $wid,array $snapshot): void{if(empty($snapshot['shared_profile']))throw new InvalidArgumentException('Legacy company identity requires reconfirmation. Save company facts and create a fresh plan.');$current=$this->profile($uid,$wid);if(!$current||!empty($current['needs_reconfirmation'])||(int)$current['version']!==(int)$snapshot['shared_profile']['version'])throw new InvalidArgumentException('Company facts changed. Create and review a new plan before further execution / بيانات الشركة تغيرت؛ أنشئ وراجع خطة جديدة قبل التنفيذ');}
    public function setup(int $uid): array{return ['installed'=>$this->has('business_hub_profiles'),'websites'=>$this->q('SELECT id,domain,company_name FROM websites WHERE user_id=? ORDER BY id DESC',[$uid])->fetchAll(PDO::FETCH_ASSOC),'csrf_token'=>Csrf::token()];}
    public static function matchesProfile(array $snapshot,?array $profile,string $url): bool{
        $brief=$snapshot['brief']??[];$shared=$brief['shared_profile']??[];
        if(!$profile||!empty($profile['needs_reconfirmation'])||empty($brief['source_site_url'])||empty($shared['version'])||empty($profile['facts']['source_site_url']))return false;
        return self::siteKey((string)$brief['source_site_url'])===self::siteKey($url)
            &&self::siteKey((string)$profile['facts']['source_site_url'])===self::siteKey($url)
            &&(int)$shared['version']===(int)$profile['version']
            &&($shared['facts']??null)===($profile['facts']??null);
    }
    public function dashboard(int $uid,int $wid): array{
        $site=$this->own($uid,$wid);$profile=$this->profile($uid,$wid);$connections=$this->q('SELECT platform,status,token_expires_at FROM platform_connections WHERE user_id=? AND website_id=?',[$uid,$wid])->fetchAll(PDO::FETCH_ASSOC);$groups=[];
        foreach($connections as $row){$expired=!empty($row['token_expires_at'])&&strtotime($row['token_expires_at'].' UTC')<=time();$state=$row['status']==='connected'&&!$expired?'saved':'needs_review';$old=$groups[$row['platform']]['saved_count']??0;$groups[$row['platform']]=['saved_count'=>$old+($state==='saved'?1:0),'live_verified'=>false];}
        $seo=[];$social=[];$tasks=[];$delivery=[];
        if($this->has('seo_expert_runs')){
            $seo=$this->q('SELECT id,status,summary,error_message,created_at,snapshot_json FROM seo_expert_runs WHERE user_id=? AND website_id=? ORDER BY id DESC LIMIT 5',[$uid,$wid])->fetchAll(PDO::FETCH_ASSOC);
            $url=(string)($site[Website::urlColumn()]??$site['main_url']??'');
            foreach($seo as &$run){$snapshot=json_decode((string)$run['snapshot_json'],true);$run['current_identity']=is_array($snapshot)&&self::matchesProfile($snapshot,$profile,$url);unset($run['snapshot_json']);}unset($run);
            // Filter before LIMIT: legacy tasks cannot crowd out current-plan tasks.
            if($profile&&!empty($profile['facts']['source_site_url'])&&empty($profile['needs_reconfirmation'])){
                $candidates=$this->q("SELECT t.id,t.run_id,t.title,t.instructions,t.field_name,t.page_url,t.status,r.snapshot_json FROM seo_expert_tasks t JOIN seo_expert_runs r ON r.id=t.run_id AND r.user_id=t.user_id AND r.website_id=t.website_id WHERE t.user_id=? AND t.website_id=? AND t.status IN ('needs_client','uncertain') AND JSON_VALID(r.snapshot_json) AND CAST(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(r.snapshot_json) THEN r.snapshot_json ELSE '{}' END,'$.brief.shared_profile.version')) AS UNSIGNED)=? ORDER BY t.id DESC",[$uid,$wid,(int)$profile['version']]);
                while($task=$candidates->fetch(PDO::FETCH_ASSOC)){$snapshot=json_decode((string)$task['snapshot_json'],true);if(!is_array($snapshot)||!self::matchesProfile($snapshot,$profile,$url))continue;$task=SeoKeywordConflictReview::forTask($task,$snapshot,($snapshot['brief']['language']??'en')==='ar');unset($task['snapshot_json']);$tasks[]=$task;if(count($tasks)>=30)break;} $candidates->closeCursor();
            }
        }
        if($this->has('social_monthly_plans')){$social=$this->q('SELECT id,month_key,status,approved_at,error_message,created_at FROM social_monthly_plans WHERE user_id=? AND website_id=? ORDER BY id DESC LIMIT 5',[$uid,$wid])->fetchAll(PDO::FETCH_ASSOC);$delivery=$this->q('SELECT t.status,COUNT(*) AS total FROM social_monthly_plan_items i JOIN social_monthly_plans p ON p.id=i.plan_id JOIN social_post_targets t ON t.id=i.target_id WHERE p.user_id=? AND p.website_id=? GROUP BY t.status',[$uid,$wid])->fetchAll(PDO::FETCH_ASSOC);}
        return ['site'=>['id'=>$wid,'name'=>$site['company_name']?:$site['domain']],'profile'=>$profile,'connections'=>$groups,'seo'=>$seo,'social'=>$social,'client_tasks'=>$tasks,'social_delivery'=>$delivery,'social_recurring'=>$this->has('social_monthly_autopilot')?($this->q('SELECT enabled,source_plan_id,max_cycle_price,next_month,next_due_at,last_error FROM social_monthly_autopilot WHERE user_id=? AND website_id=?',[$uid,$wid])->fetch(PDO::FETCH_ASSOC)?:null):null,'generation_quote'=>PlanUsage::check($uid,'marketing_tool','marketing_tool','Insufficient allowance'),'worker_ready'=>self::worker(),'modules'=>['seo'=>$this->has('seo_expert_sites'),'social'=>$this->has('social_monthly_plans')]];
    }
    private static function worker(): bool{$base=defined('TOURFECTO_STORAGE')?TOURFECTO_STORAGE:dirname(__DIR__,3).'/storage';$h=json_decode((string)@file_get_contents($base.'/cache/queue-heartbeat.json'),true);$age=time()-(int)($h['time']??0);return $age>=0&&$age<=300&&in_array($h['status']??'', ['running','completed'],true);}
}
