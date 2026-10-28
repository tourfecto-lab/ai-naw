<?php
/** Monthly, plan-limited 5x5 Maps measurements. Uncertain calls are never automatically replayed. */
class LocalRankGridService
{
    public function __construct(private Database $db,private ?DataForSeoClient $client=null) {}
    public static function grid(float $lat,float $lng,float $spacingKm=1): array
    {
        if(!is_finite($lat)||!is_finite($lng)||abs($lat)>85||abs($lng)>180||!is_finite($spacingKm)||$spacingKm<0.25||$spacingKm>5)throw new InvalidArgumentException('Invalid business coordinates or grid spacing');
        $out=[];for($row=0;$row<5;$row++)for($col=0;$col<5;$col++){
            $y=$lat+(2-$row)*$spacingKm/111.32;$x=$lng+($col-2)*$spacingKm/(111.32*cos(deg2rad($lat)));
            if(abs($y)>85)throw new InvalidArgumentException('Grid exceeds supported latitude');
            $x=fmod($x+540,360)-180;$out[]=['row'=>$row,'col'=>$col,'latitude'=>round($y,6),'longitude'=>round($x,6)];
        }return $out;
    }
    /** A confirmed NAP profile may have no Maps coordinates; never cast missing values to zero. */
    public static function gridForProfile(array $profile,float $spacingKm=1): array
    {
        $lat=$profile['latitude']??null;$lng=$profile['longitude']??null;
        if(!is_numeric($lat)||!is_numeric($lng))throw new InvalidArgumentException('GBP location has no confirmed coordinates for Maps measurement');
        return self::grid((float)$lat,(float)$lng,$spacingKm);
    }
    public static function rank(array $items,array $identity): ?int
    {
        foreach($items as $item){
            if(isset($item['type'])&&$item['type']!=='maps_search')continue;
            $same=(!empty($identity['place_id'])&&($item['place_id']??'')===$identity['place_id'])||(!empty($identity['cid'])&&(string)($item['cid']??'')===(string)$identity['cid']);
            if($same && (int)($item['rank_group']??0)>0)return (int)$item['rank_group'];
        }return null;
    }
    public static function limits(string $tier): array
    {
        return ['starter'=>['keywords'=>2,'runs'=>1],'professional'=>['keywords'=>3,'runs'=>3],'enterprise'=>['keywords'=>3,'runs'=>6]][$tier]??['keywords'=>2,'runs'=>1];
    }
    public function start(int $uid,int $wid,array $keywords,float $spacing=1): array
    {
        (new SeoMarketService($this->db))->site($wid,$uid);
        if (!($this->client??new DataForSeoClient())->endpointEnabled('serp/google/maps/live/advanced')) throw new InvalidArgumentException('Enable DataForSEO Maps in admin settings first');
        $profile=$this->db->query('SELECT profile_json FROM seo_local_profiles WHERE website_id=? AND user_id=?',[$wid,$uid]);if(!$profile)throw new InvalidArgumentException('Confirm a connected GBP location first');
        $profile=json_decode($profile[0]['profile_json'],true,512,JSON_THROW_ON_ERROR);$market=(new SeoMarketService($this->db))->resolve($wid,$uid);
        $subscription=Subscription::activeSubscriptionRow($uid);$limit=self::limits((string)($subscription['plan_name']??'starter'));
        foreach($keywords as $keyword) if(!is_string($keyword))throw new InvalidArgumentException('Keywords must be text');
        $keywords=array_values(array_unique(array_map('trim',$keywords)));if(count($keywords)<2||count($keywords)>$limit['keywords'])throw new InvalidArgumentException('Choose 2–'.$limit['keywords'].' keywords for this plan');
        foreach($keywords as $k)if($k===''||mb_strlen($k)>255||preg_match('/\b(?:site|inurl|intitle|filetype):/i',$k))throw new InvalidArgumentException('Invalid plain keyword');
        if(empty($profile['place_id'])&&empty($profile['cid']))throw new InvalidArgumentException('GBP must provide a stable Maps place ID/CID; names alone cannot identify a branch');
        $points=self::gridForProfile($profile,$spacing);
        $config=['profile'=>$profile,'market'=>$market,'keywords'=>$keywords,'points'=>$points];$hash=hash('sha256',json_encode($config,JSON_THROW_ON_ERROR));
        $id=$this->db->transaction(function()use($uid,$wid,$config,$hash,$limit){
            // Account-level lock and allowance shared across websites.
            $this->db->query('SELECT id FROM users WHERE id=? FOR UPDATE',[$uid]);
            $old=$this->db->query('SELECT id FROM seo_local_grid_runs WHERE website_id=? AND month_start=? AND request_hash=?',[$wid,gmdate('Y-m-01'),$hash]);if($old)return (int)$old[0]['id'];
            $used=$this->db->query('SELECT COUNT(*) n FROM seo_local_grid_runs WHERE user_id=? AND month_start=?',[$uid,gmdate('Y-m-01')]);if((int)$used[0]['n']>=$limit['runs'])throw new InvalidArgumentException('Monthly local-grid allowance exhausted');
            $id=(int)$this->db->query('INSERT INTO seo_local_grid_runs(website_id,user_id,month_start,request_hash,config_json,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())',[$wid,$uid,gmdate('Y-m-01'),$hash,json_encode($config,JSON_THROW_ON_ERROR)]);
            if(!(new QueueManager($this->db))->push(LocalRankGridJob::class,['run_id'=>$id],'seo'))throw new RuntimeException('Grid queue unavailable');return $id;
        });return ['run_id'=>$id,'maximum_paid_requests'=>count($keywords)*25,'limits'=>$limit];
    }
    public function step(int $id,int $batch=2): void
    {
        if(!ExternalExecution::lock('local-grid:'.$id))return;
        try{
            $rows=$this->db->query('SELECT r.* FROM seo_local_grid_runs r JOIN websites w ON w.id=r.website_id AND w.user_id=r.user_id WHERE r.id=?',[$id]);if(!$rows)return;$run=$rows[0];$c=json_decode($run['config_json'],true,512,JSON_THROW_ON_ERROR);
            if(in_array($run['status'],['complete','blocked'],true))return;
            $attempts=0;
            foreach($c['keywords'] as $ki=>$keyword)foreach($c['points'] as $pi=>$point){
                $s=$this->db->getConnection()->prepare("INSERT IGNORE INTO seo_local_grid_points(run_id,keyword_index,point_index,state,checked_at) VALUES(?,?,?,'attempted',UTC_TIMESTAMP())");$s->execute([$id,$ki,$pi]);if(!$s->rowCount())continue;
                $r=($this->client??new DataForSeoClient())->call('serp/google/maps/live/advanced',[[
                    'keyword'=>$keyword,'language_code'=>$c['market']['language_code'],'location_coordinate'=>$point['latitude'].','.$point['longitude'].',15z','search_this_area'=>true,'depth'=>100]],(int)$run['website_id'],1,0.10);
                $items=$r['response']['tasks'][0]['result'][0]['items']??null;$valid=!empty($r['available'])&&is_array($items);
                $this->db->exec('UPDATE seo_local_grid_points SET state=?,rank_position=?,evidence_json=?,checked_at=UTC_TIMESTAMP() WHERE run_id=? AND keyword_index=? AND point_index=?',[$valid?'measured':'unavailable',$valid?self::rank($items,$c['profile']):null,json_encode(['reason'=>$valid?null:($r['reason']??'invalid_maps_response'),'cost'=>$r['cost']??null]),$id,$ki,$pi]);
                if(!$valid){$this->db->exec("UPDATE seo_local_grid_runs SET status='blocked' WHERE id=?",[$id]);return;}
                if(++$attempts>=max(1,min(5,$batch))){if(!(new QueueManager($this->db))->push(LocalRankGridJob::class,['run_id'=>$id],'seo',2))throw new RuntimeException('Grid continuation unavailable');return;}
            }
            $this->db->exec("UPDATE seo_local_grid_runs SET status='complete' WHERE id=?",[$id]);
        }finally{ExternalExecution::unlock('local-grid:'.$id);}
    }
    public function history(int $uid,int $wid): array
    {
        (new SeoMarketService($this->db))->site($wid,$uid);$rows=$this->db->query('SELECT * FROM seo_local_grid_runs WHERE website_id=? AND user_id=? ORDER BY id DESC LIMIT 36',[$wid,$uid]);
        foreach($rows as &$r){$r['config']=json_decode($r['config_json'],true);unset($r['config_json']);$r['points']=$this->db->query('SELECT keyword_index,point_index,state,rank_position,checked_at FROM seo_local_grid_points WHERE run_id=? ORDER BY keyword_index,point_index',[$r['id']]);}unset($r);return $rows;
    }
}
