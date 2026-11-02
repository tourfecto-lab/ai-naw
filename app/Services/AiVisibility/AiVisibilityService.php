<?php
require_once __DIR__.'/../BusinessHub/BusinessHubService.php';
/** Tenant-scoped orchestration for approved, unbranded weekly GEO samples. */
class AiVisibilityService
{
    private Database $db; private $providers; private $evidence;
    public function __construct(?Database $db=null,?array $providers=null,?AiVisibilityEvidence $evidence=null){$this->db=$db??Database::getInstance();$this->providers=$providers;$this->evidence=$evidence;}
    private function q(string $sql,array $args=[]): PDOStatement{$s=$this->db->getConnection()->prepare($sql);$s->execute($args);return $s;}
    public function providers(): array{return $this->providers??[new OpenAiVisibilityProvider(),new GeminiVisibilityProvider(),new SonarVisibilityProvider(),new ClaudeVisibilityProvider()];}
    public function site(int $uid,int $wid): array
    {
        $site=$this->q('SELECT * FROM websites WHERE id=? AND user_id=?',[$wid,$uid])->fetch(PDO::FETCH_ASSOC);
        if(!$site)throw new InvalidArgumentException('الموقع مش موجود');
        AiVisibilitySchema::ensureSchema($this->db);return $site;
    }
    public function context(int $uid,int $wid,int $mid=0): array
    {
        $site=$this->site($uid,$wid);$market=(new SeoMarketService($this->db))->resolve($wid,$uid,$mid);
        $r=$this->q('SELECT version,facts_json,confirmed_at FROM business_hub_profiles WHERE website_id=? AND user_id=?',[$wid,$uid])->fetch(PDO::FETCH_ASSOC);
        $facts=$r?json_decode($r['facts_json'],true,512,JSON_THROW_ON_ERROR):[];
        $url=(string)($site['main_url']??$site['domain']??'');
        if (!$r || !$r['confirmed_at'] || empty($facts['business_name']) || empty($facts['source_site_url']) || BusinessHubService::siteKey($facts['source_site_url'])!==BusinessHubService::siteKey($url)) throw new InvalidArgumentException('أكد بيانات الشركة الحالية في Business Hub الأول');
        $entity=$this->q('SELECT * FROM seo_ai_entities WHERE website_id=? AND user_id=?',[$wid,$uid])->fetch(PDO::FETCH_ASSOC);
        $aliases=$entity?json_decode($entity['aliases_json'],true):[];$profiles=$entity?json_decode($entity['profiles_json'],true):[];
        $brand=['name'=>$facts['business_name'],'domain'=>DataForSeoRankingSource::normalizeHost($url),'aliases'=>$aliases?:[]];
        $forbidden=AiVisibilityDetector::aliases($brand['name'],$brand['aliases'],$brand['domain']);
        foreach($this->q('SELECT competitor_name,competitor_domain FROM competitors WHERE website_id=? AND user_id=? AND is_active=1',[$wid,$uid])->fetchAll(PDO::FETCH_ASSOC) as $comp)$forbidden=array_merge($forbidden,AiVisibilityDetector::aliases((string)$comp['competitor_name'],[],(string)$comp['competitor_domain']));
        return ['site'=>$site,'url'=>$url,'market'=>$market,'facts'=>$facts,'profile_version'=>(int)$r['version'],'facts_hash'=>hash('sha256',$r['facts_json']),'entity'=>$entity?:null,'profiles'=>$profiles?:[],'brand'=>$brand,'aliases'=>array_values(array_unique($forbidden))];
    }
    public function set(int $uid,int $wid,int $mid): ?array
    {
        $this->site($uid,$wid);$row=$this->q('SELECT * FROM seo_ai_prompt_sets WHERE website_id=? AND user_id=? AND market_id=?',[$wid,$uid,$mid])->fetch(PDO::FETCH_ASSOC);if(!$row)return null;
        $row['questions']=json_decode($row['questions_json'],true,512,JSON_THROW_ON_ERROR);unset($row['questions_json']);return $row;
    }
    public function generate(int $uid,int $wid,int $mid,int $count=20): array
    {
        $c=$this->context($uid,$wid,$mid);
        $rows=$this->q("SELECT keyword,volume,source,fetched_at FROM seo_keyword_research WHERE website_id=? AND market_id=? AND source='dataforseo' AND fetched_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY) ORDER BY volume DESC LIMIT 30",[$wid,$c['market']['id']])->fetchAll(PDO::FETCH_ASSOC);
        return ['questions'=>AiVisibilityQuestions::generate($c['facts'],$rows,$c['market']['language_code'],$c['aliases'],$count),'market'=>$c['market'],'profile_version'=>$c['profile_version'],'approved'=>false];
    }
    public function save(int $uid,int $wid,int $mid,array $questions,int $version,bool $approve): array
    {
        $c=$this->context($uid,$wid,$mid);$questions=AiVisibilityQuestions::validate($questions,$c['aliases']);
        $this->db->transaction(function()use($uid,$wid,$c,$questions,$version,$approve){
            $this->q('SELECT id FROM websites WHERE id=? AND user_id=? FOR UPDATE',[$wid,$uid]);
            $old=$this->q('SELECT version FROM seo_ai_prompt_sets WHERE website_id=? AND market_id=? FOR UPDATE',[$wid,$c['market']['id']])->fetchColumn();
            if (($old?(int)$old:0)!==$version)throw new InvalidArgumentException('الأسئلة اتغيّرت؛ حدّث الصفحة وراجعها تاني');
            $args=[$uid,$c['profile_version'],$c['facts_hash'],json_encode($questions,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$approve?gmdate('Y-m-d H:i:s'):null,$wid,$c['market']['id']];
            if ($old)$this->q('UPDATE seo_ai_prompt_sets SET user_id=?,profile_version=?,facts_hash=?,questions_json=?,approved_at=?,version=version+1,updated_at=UTC_TIMESTAMP() WHERE website_id=? AND market_id=?',$args);
            else $this->q('INSERT INTO seo_ai_prompt_sets(user_id,profile_version,facts_hash,questions_json,approved_at,website_id,market_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())',$args);
        });return $this->set($uid,$wid,(int)$c['market']['id']);
    }
    public function entities(int $uid,int $wid,array $aliases,array $profiles,string $address,int $version,bool $confirmed): array
    {
        $this->site($uid,$wid);
        if (!$confirmed || count($aliases)>10 || count($profiles)>15 || mb_strlen($address)>500)throw new InvalidArgumentException('راجع وأكد الأسماء وروابط الحسابات والعنوان');
        foreach($aliases as $alias)if(!is_string($alias)||mb_strlen(trim($alias))<3||mb_strlen($alias)>150||preg_match('/[<>\x00-\x1f]/u',$alias))throw new InvalidArgumentException('اسم بديل غير صالح');
        $urls=[];foreach($profiles as $url){if(!is_string($url)||!AiVisibilityDetector::safeUrl($url)||parse_url($url,PHP_URL_SCHEME)!=='https')throw new InvalidArgumentException('أدخل رابط HTTPS حقيقي لحساب شركتك');$urls[]=$url;}
        $this->db->transaction(function()use($uid,$wid,$aliases,$urls,$address,$version){
            $this->q('SELECT id FROM websites WHERE id=? AND user_id=? FOR UPDATE',[$wid,$uid]);$old=$this->q('SELECT version FROM seo_ai_entities WHERE website_id=? FOR UPDATE',[$wid])->fetchColumn();
            if(($old?(int)$old:0)!==$version)throw new InvalidArgumentException('بيانات الحسابات اتغيّرت؛ حدّث الصفحة');
            $args=[$uid,json_encode(array_values($aliases),JSON_THROW_ON_ERROR),json_encode(array_values(array_unique($urls)),JSON_THROW_ON_ERROR),trim($address),$wid];
            if($old)$this->q('UPDATE seo_ai_entities SET user_id=?,aliases_json=?,profiles_json=?,address=?,version=version+1,confirmed_at=UTC_TIMESTAMP() WHERE website_id=?',$args);
            else $this->q('INSERT INTO seo_ai_entities(user_id,aliases_json,profiles_json,address,website_id,confirmed_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())',$args);
            // New aliases can expose a branded question. Every list must be reviewed again.
            $this->q('UPDATE seo_ai_prompt_sets SET approved_at=NULL,version=version+1 WHERE website_id=? AND user_id=?',[$wid,$uid]);
        });return ['saved'=>true];
    }
    public function metrics(int $uid,int $wid,int $mid=0): array
    {
        $this->site($uid,$wid);$where=$mid?' AND market_id=?':'';$args=$mid?[$wid,$uid,$mid]:[$wid,$uid];
        $rows=$this->q('SELECT * FROM seo_ai_answers WHERE website_id=? AND user_id=?'.$where.' AND week_start>=DATE_SUB(UTC_DATE(),INTERVAL 8 WEEK) ORDER BY checked_at DESC,id DESC',$args)->fetchAll(PDO::FETCH_ASSOC);
        // Aggregate each market separately; the same question in two markets is two observations.
        foreach($rows as &$r)$r['question_hash']=$r['market_id'].'|'.$r['question_hash'];unset($r);
        $expected=0;$sets=$this->q('SELECT questions_json FROM seo_ai_prompt_sets WHERE website_id=? AND user_id=?'.$where.' AND approved_at IS NOT NULL',$args)->fetchAll(PDO::FETCH_ASSOC);
        foreach($sets as $s)$expected+=count(json_decode($s['questions_json'],true));
        $out=AiVisibilityMetrics::summarize($rows,$expected);$out['source']='scheduled_grounded_api_samples';$out['manual_imports_included']=false;
        $out['evidence']=array_map(static fn($r)=>array_intersect_key($r,array_flip(['market_id','provider','question','mentioned_brand','cited_domain','cited_urls','competitors_mentioned','checked_at'])),array_slice($rows,0,12));
        $out['improvement_tasks']=$this->q('SELECT market_id,title,instructions,evidence_json,created_at FROM seo_ai_tasks WHERE website_id=? AND user_id=? ORDER BY id DESC LIMIT 8',[$wid,$uid])->fetchAll(PDO::FETCH_ASSOC);
        return $out;
    }
    public function overview(int $uid,int $wid,int $mid=0): array
    {
        $site=$this->site($uid,$wid);$market=(new SeoMarketService($this->db))->resolve($wid,$uid,$mid);$status=[];$any=false;
        foreach($this->providers() as $provider){$on=$provider->configured();$status[$provider->name()]=['configured'=>$on,'model'=>$provider->model()];$any=$any||$on;}
        $metrics=$any?$this->metrics($uid,$wid,(int)$market['id']):AiVisibilityMetrics::summarize([]);
        $profileState='confirmed';$entity=null;
        try{$c=$this->context($uid,$wid,(int)$market['id']);$entity=$c['entity'];if($entity){$entity['aliases']=json_decode($entity['aliases_json'],true);$entity['profiles']=json_decode($entity['profiles_json'],true);unset($entity['aliases_json'],$entity['profiles_json']);}}
        catch(Throwable $e){$profileState='needs_confirmed_business_profile';}
        return ['state'=>$any?$metrics['state']:'not_measured','setup_steps'=>['الأدمن: الإعدادات ← إعدادات النظام والربط (API) ← قياس ظهور AI؛ فعّل المزودين وأدخل مفاتيحهم وميزانية موجبة','أكد بيانات الشركة، واختار السوق، وراجع ووافق على 10–30 سؤال من غير البراند','شغّل المجدول المركزي؛ القياس أسبوعي وسؤال × مزوّد بيستهلك وحدة'],
            'providers'=>$status,'metrics'=>$metrics,'market'=>$market,'profile_state'=>$profileState,'entities'=>$entity,'prompt_set'=>$this->set($uid,$wid,(int)$market['id']),
            'answers'=>$this->q('SELECT id,provider,question,mentioned_brand,brand_position,cited_domain,cited_urls,competitors_mentioned,answer_excerpt,model,cost,cost_basis,checked_at FROM seo_ai_answers WHERE website_id=? AND user_id=? AND market_id=? ORDER BY checked_at DESC,id DESC LIMIT 270',[$wid,$uid,$market['id']])->fetchAll(PDO::FETCH_ASSOC),
            'tasks'=>$this->q('SELECT * FROM seo_ai_tasks WHERE website_id=? AND user_id=? AND market_id=? ORDER BY id DESC LIMIT 50',[$wid,$uid,$market['id']])->fetchAll(PDO::FETCH_ASSOC)];
    }
    /** Customer page payload: every market of the site, so switching markets needs no reload. */
    public function customerOverview(int $uid,int $wid): array
    {
        $site=$this->site($uid,$wid);$markets=(new SeoMarketService($this->db))->markets($wid,$uid);$status=[];$any=false;
        foreach($this->providers() as $provider){$on=$provider->configured();$status[$provider->name()]=['configured'=>$on];$any=$any||$on;}
        $out=['state'=>'not_measured','markets'=>[],'question_sets'=>[],'questions'=>[],'metrics'=>[],'trend'=>[],'answers'=>[],'providers'=>$status];$weeks=[];
        foreach($markets as $m){
            $mid=(int)$m['id'];$out['markets'][]=['id'=>$mid,'label'=>(string)$m['label']];
            $set=$this->set($uid,$wid,$mid);$approved=$set&&$set['approved_at']?1:0;
            $out['question_sets'][]=['market_id'=>$mid,'version'=>$set?(int)$set['version']:0,'approved'=>$approved];
            foreach($set['questions']??[] as $i=>$q)$out['questions'][]=['id'=>$i+1,'market_id'=>$mid,'question'=>$q,'approved'=>$approved];
            if(!$any)continue;
            $summary=$this->metrics($uid,$wid,$mid);if($summary['state']==='measured_sample')$out['state']='measured_sample';
            foreach($summary['providers'] as $name=>$p){
                if($p['state']==='measured_sample')$out['metrics'][]=['provider'=>$name,'market_id'=>$mid,'questions'=>$p['questions'],'citation_questions'=>$p['citation_questions'],'share_of_voice'=>$p['share_of_voice'],'citation_rate'=>$p['citation_rate']];
                foreach($p['trend'] as $w)if($w['questions']>0)$weeks[$w['week']][]=['provider'=>$name,'market_id'=>$mid,'questions'=>$w['questions'],'share_of_voice'=>$w['share_of_voice'],'citation_rate'=>$w['citation_rate']];
            }
        }
        krsort($weeks);foreach($weeks as $week=>$rows)$out['trend'][]=['week'=>$week,'providers'=>$rows];
        $out['answers']=$this->q('SELECT market_id,provider,question,mentioned_brand,brand_position,cited_domain,cited_urls,competitors_mentioned,answer_excerpt,checked_at FROM seo_ai_answers WHERE website_id=? AND user_id=? ORDER BY checked_at DESC,id DESC LIMIT 270',[$wid,$uid])->fetchAll(PDO::FETCH_ASSOC);
        $out['tasks']=$this->q('SELECT * FROM seo_ai_tasks WHERE website_id=? AND user_id=? ORDER BY id DESC LIMIT 50',[$wid,$uid])->fetchAll(PDO::FETCH_ASSOC);
        $entity=$this->q('SELECT version,aliases_json,profiles_json FROM seo_ai_entities WHERE website_id=? AND user_id=?',[$wid,$uid])->fetch(PDO::FETCH_ASSOC);
        $facts=$this->q('SELECT facts_json FROM business_hub_profiles WHERE website_id=? AND user_id=?',[$wid,$uid])->fetchColumn();$facts=$facts?(json_decode($facts,true)?:[]):[];
        // The page treats the first alias as the brand name and shows the rest as editable aliases.
        $out['aliases']=array_merge([(string)($facts['business_name']??$site['name']??'')],$entity?(json_decode($entity['aliases_json'],true)?:[]):[]);
        $out['profile_urls']=$entity?(json_decode($entity['profiles_json'],true)?:[]):[];$out['identity_version']=$entity?(int)$entity['version']:0;
        return $out;
    }
    /** Generated questions are stored as an unapproved draft so the customer can review them. */
    public function generateDraft(int $uid,int $wid,int $mid): array
    {
        $g=$this->generate($uid,$wid,$mid);$set=$this->set($uid,$wid,(int)$g['market']['id']);
        return $this->save($uid,$wid,(int)$g['market']['id'],$g['questions'],$set?(int)$set['version']:0,false);
    }
    public function identity(int $uid,int $wid,array $aliases,array $profiles,int $version): array
    {
        $aliases=array_values(array_filter(array_map(static fn($a)=>is_string($a)?trim($a):$a,$aliases),static fn($a)=>$a!==''));
        $address=(string)($this->q('SELECT address FROM seo_ai_entities WHERE website_id=? AND user_id=?',[$wid,$uid])->fetchColumn()?:'');
        return $this->entities($uid,$wid,$aliases,array_values(array_filter($profiles,static fn($u)=>trim((string)$u)!=='')),$address,$version,true);
    }
    public function run(int $uid,int $wid,int $mid,int $maxChecks=2): array
    {
        $active=array_values(array_filter($this->providers(),fn($p)=>$p->configured()));
        if (!$active)return ['state'=>'not_measured','checked'=>0,'reason'=>'no_grounded_provider_configured'];
        $c=$this->context($uid,$wid,$mid);$set=$this->set($uid,$wid,(int)$c['market']['id']);
        if (!$set || !$set['approved_at'] || (int)$set['profile_version']!==$c['profile_version'] || $set['facts_hash']!==$c['facts_hash']) return ['state'=>'not_measured','checked'=>0,'reason'=>'questions_need_current_approval'];
        $questions=AiVisibilityQuestions::validate($set['questions'],$c['aliases']);$week=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('monday this week')->format('Y-m-d');
        $cap=(float)(new SystemSettingsService())->get('geo_monthly_budget_usd','0');$ledger=new AiVisibilityLedger($this->db);$checked=0;$skipped=0;$failed=0;$errors=[];
        $competitors=$this->q('SELECT competitor_name name,competitor_domain domain FROM competitors WHERE website_id=? AND user_id=? AND is_active=1 LIMIT 30',[$wid,$uid])->fetchAll(PDO::FETCH_ASSOC);
        $evidence=$this->evidence??new AiVisibilityEvidence();
        $started=microtime(true);$attempted=0;
        foreach($active as $provider)foreach($questions as $question){
            if($attempted>=max(1,$maxChecks)||microtime(true)-$started>130)return compact('checked','skipped','failed')+['state'=>'pending','more'=>true];
            // Re-read kill switches and identity between checks; injected providers stay offline-testable.
            if($this->providers===null && !$this->freshEnabled($provider->name()))return compact('checked','skipped','failed')+['state'=>'stopped','reason'=>'disabled'];
            $fresh=$this->context($uid,$wid,$mid);if($fresh['facts_hash']!==$c['facts_hash'] || $fresh['aliases']!==$c['aliases'])return compact('checked','skipped','failed')+['state'=>'stopped','reason'=>'identity_changed'];
            try{$id=$ledger->reserve($set,$provider->name(),$question,$week,$cap);}catch(RuntimeException $e){return compact('checked','skipped','failed')+['state'=>'blocked','reason'=>$e->getMessage()];}
            if($id===null){$skipped++;continue;}
            $attempted++;$result=$provider->ask($question,$c['market']);$analysis=null;
            // أي استثناء بعد الحجز كان بيسيب الفحص "unknown" بلا سبب ويحجز الميزانية؛ دلوقتي بنقفله بسبب واضح وبنكمّل.
            try{
                if(!empty($result['available'])){
                    [$urls,$complete]=$evidence->resolveCitations($result['cited_urls']);$analysis=AiVisibilityDetector::analyze($result['answer'],$urls,$c['brand'],$competitors);
                    if((!$complete || ($result['citation_complete']??true)===false) && !$analysis['cited_domain'])$analysis['cited_domain']=null;
                }
                $ledger->finish($id,$result,$analysis,$cap);
            }catch(Throwable $e){
                error_log('GEO post-processing failed ('.$provider->name().'): '.get_class($e).': '.$e->getMessage());
                $analysis=null;$result['available']=false;$result['reason']='post_processing_failed';
                try{
                    // لو finish اتكتب فعلًا وفشل بعده (تنبيه الميزانية مثلًا) منرجّعش الفحص لحالة فاشل.
                    if($this->q("SELECT status FROM seo_ai_calls WHERE id=?",[$id])->fetchColumn()==='pending')$ledger->finish($id,$result,null,$cap);
                    else $analysis=['recorded'=>true];
                }catch(Throwable $e2){error_log('GEO finish failed: '.$e2->getMessage());}
            }
            if($analysis!==null)$checked++;else{$failed++;$errors[]=$provider->name().':'.($result['reason']??'unavailable');}
            // طلب مرفوض (400/401/403/404: مفتاح أو موديل) = مشكلة إعداد؛ التكرار بيحرق الحصة من غير فايدة، فنوقف الدفعة ومفيش continuation.
            if($analysis===null && in_array((int)($result['http']??0),[400,401,403,404],true))return compact('checked','skipped','failed','errors')+['state'=>'stopped','reason'=>'provider_auth_failed:'.$provider->name()];
        }
        $this->generateTasks($uid,$wid,$mid);
        return compact('checked','skipped','failed','errors')+['state'=>$checked?'measured_sample':($failed?'unavailable':'already_checked')];
    }
    public function enqueue(int $uid,int $wid,int $mid): array
    {
        $c=$this->context($uid,$wid,$mid);$set=$this->set($uid,$wid,(int)$c['market']['id']);
        if(!$set || !$set['approved_at'])throw new InvalidArgumentException('وافق على الأسئلة الأول');
        if(!array_filter($this->providers(),fn($p)=>$p->configured()))return ['state'=>'not_measured'];
        $week=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('monday this week')->format('Y-m-d');
        $queue=new QueueManager($this->db);if(!$queue->isReady())throw new RuntimeException('ثبّت جدول jobs وشغّل طابور المهام الأول');
        $id=$queue->push(AiVisibilityCheckJob::class,['user_id'=>$uid,'website_id'=>$wid,'market_id'=>(int)$c['market']['id'],'week'=>$week,'version'=>(int)$set['version']],'low');
        if(!$id)throw new RuntimeException('تعذر إضافة القياس للطابور');return ['state'=>'queued'];
    }
    public function generateTasks(int $uid,int $wid,int $mid): array
    {
        $c=$this->context($uid,$wid,$mid);
        $answers=$this->q('SELECT * FROM seo_ai_answers WHERE website_id=? AND user_id=? AND market_id=? AND checked_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 8 DAY) ORDER BY id DESC LIMIT 90',[$wid,$uid,$c['market']['id']])->fetchAll(PDO::FETCH_ASSOC);
        if(!$answers)return [];
        $competitors=$this->q('SELECT competitor_name name,competitor_domain domain FROM competitors WHERE website_id=? AND user_id=? AND is_active=1 LIMIT 30',[$wid,$uid])->fetchAll(PDO::FETCH_ASSOC);
        $tasks=($this->evidence??new AiVisibilityEvidence())->tasks($c,$answers,$competitors);
        foreach($tasks as $t)$this->q('INSERT INTO seo_ai_tasks(website_id,user_id,market_id,evidence_hash,kind,title,instructions,evidence_json,created_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE evidence_json=VALUES(evidence_json),instructions=VALUES(instructions)',[$wid,$uid,$c['market']['id'],$t['evidence_hash'],$t['kind'],$t['title'],$t['instructions'],json_encode($t['evidence'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        return $tasks;
    }
    public function llmsDraft(int $uid,int $wid): array{$site=$this->site($uid,$wid);return ['draft'=>(new GeoLlmsBuilder($this->evidence))->build($site['main_url']),'publication'=>'راجع المسودة وانشرها من إصلاح llms.txt في تدقيق الموقع؛ يتطلب موافقة وتحقق ويمكن التراجع عنه.'];}
    public static function customerProfiles(int $uid,int $wid): array
    {
        if($uid<=0||$wid<=0)return [];$db=Database::getInstance();AiVisibilitySchema::ensureSchema($db);
        $s=$db->getConnection()->prepare('SELECT e.profiles_json FROM seo_ai_entities e JOIN websites w ON w.id=e.website_id AND w.user_id=e.user_id WHERE e.website_id=? AND e.user_id=? AND e.confirmed_at IS NOT NULL');$s->execute([$wid,$uid]);$json=$s->fetchColumn();return $json?array_values(array_filter(json_decode($json,true)?:[],[AiVisibilityDetector::class,'safeUrl'])):[];
    }
    private function freshEnabled(string $provider): bool{$s=new SystemSettingsService();return $s->get('geo_enabled')==='1' && $s->get('geo_'.$provider.'_enabled')==='1';}
    public function approveTask(int $uid,int $wid,int $id): void{$this->site($uid,$wid);$this->q("UPDATE seo_ai_tasks SET status='approved' WHERE id=? AND website_id=? AND user_id=?",[$id,$wid,$uid]);}
}
