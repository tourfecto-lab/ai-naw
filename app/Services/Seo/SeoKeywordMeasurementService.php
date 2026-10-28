<?php
/** Customer-approved small batches; the wallet limit covers both provider requests. */
final class SeoKeywordMeasurementService
{
    public function __construct(private Database $db,private $clientFactory=null) {}
    private function rows(string $sql,array $args=[]): array {$s=$this->db->getConnection()->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);}
    public function quote(int $uid,int $wid,int $mid=0,string $operation='demand'): array
    {
        if(!in_array($operation,['demand','rank','discovery','gap'],true))throw new InvalidArgumentException('عملية قياس غير صالحة');
        $market=(new SeoMarketService($this->db))->resolve($wid,$uid,$mid);
        if($operation==='gap'&&empty($market['is_primary']))throw new InvalidArgumentException('مقارنة المنافسين تستخدم السوق الرئيسي؛ اختَره ثم راجع العرض');
        $rows=$this->rows("SELECT k.id,k.keyword FROM tracked_keywords k LEFT JOIN seo_keyword_research r ON r.website_id=k.website_id AND r.market_id=k.market_id AND r.keyword COLLATE utf8mb4_unicode_ci=k.keyword COLLATE utf8mb4_unicode_ci AND r.source='dataforseo' AND r.fetched_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY) WHERE k.website_id=? AND k.user_id=? AND k.market_id=? AND r.id IS NULL ORDER BY k.id LIMIT 5",[$wid,$uid,$market['id']]);
        $tracked=(int)($this->rows('SELECT COUNT(*) AS n FROM tracked_keywords WHERE website_id=? AND user_id=? AND market_id=?',[$wid,$uid,$market['id']])[0]['n']??0);
        if($operation==='rank')$rows=$this->rows('SELECT id,keyword FROM tracked_keywords WHERE website_id=? AND user_id=? AND market_id=? AND (last_checked_at IS NULL OR last_checked_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)) ORDER BY last_checked_at,id LIMIT 5',[$wid,$uid,$market['id']]);
        $competitors=$operation==='gap'?$this->rows('SELECT id,competitor_domain FROM competitors WHERE website_id=? AND user_id=? AND is_active=1 AND monitoring_paused=0 ORDER BY id LIMIT 5',[$wid,$uid]):[];
        if(in_array($operation,['discovery','gap'],true))$rows=[];
        $discovery=$operation==='discovery'||($operation==='demand'&&$tracked===0);
        $site=$this->rows('SELECT main_url FROM websites WHERE id=? AND user_id=?',[$wid,$uid])[0]??[];
        $invalid=[];foreach($rows as $row)if(DataForSeoRankingSource::keywordPhrase($row['keyword'])!==trim($row['keyword']))$invalid[]=(int)$row['id'];
        $units=$operation==='gap'?($competitors?count($competitors)+50:0):($operation==='rank'?count($rows):($discovery?6:count($rows)*2));$quota=PlanUsage::quota($uid,'seo_data');$remaining=$quota['remaining'];
        $walletUnits=$quota['covered']&&$remaining===null?0:max(0,$units-(int)$remaining);
        $price=$this->rows("SELECT price,currency,is_active FROM pay_per_use_pricing WHERE feature_key='seo_data' LIMIT 1")[0]??null;
        $amount=(float)($price['price']??0);$priced=$price&&(int)$price['is_active']&&$price['currency']==='USD'&&is_finite($amount)&&$amount>0&&abs($amount-round($amount,2))<0.000001;
        $configuredClient=new DataForSeoClient();$provider=$configuredClient->isConfigured();$budget=$configuredClient->budgetAvailability($this->db);
        $out=['website_id'=>$wid,'market_id'=>(int)$market['id'],'market_label'=>$market['label'],'location_code'=>(int)$market['location_code'],'language_code'=>$market['language_code'],'operation'=>in_array($operation,['rank','gap'],true)?$operation:($discovery?'discover_site':'measure_tracked'),'target_host'=>DataForSeoRankingSource::normalizeHost((string)($site['main_url']??'')),'keywords'=>$rows,'competitors'=>$competitors,'max_units'=>$units,'plan_remaining'=>$remaining,'wallet_unit_price'=>$priced?$amount:null,'wallet_max'=>$walletUnits===0?0:($priced?round($walletUnits*$amount,2):null),'currency'=>'USD','provider_configured'=>$provider,'provider_budget_available'=>$budget['available'],'provider_budget_reason'=>$budget['reason'],'invalid_keyword_ids'=>$invalid,'already_measured'=>$units===0];
        $out['wallet_sufficient']=$walletUnits===0||($priced&&(new WalletService())->getBalance($uid)>=$out['wallet_max']);
        $out['available']=$provider&&$out['target_host']!==''&&($units===0||$budget['available'])&&!$invalid&&($walletUnits===0||$priced)&&$out['wallet_sufficient'];
        $out['quote_hash']=hash('sha256',json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
        return $out;
    }
    public function measure(int $uid,int $wid,int $mid,array $input): array
    {
        if(($input['reviewed']??false)!==true)throw new InvalidArgumentException('راجع الكلمات والاستخدام وحد المحفظة ثم أكد القياس');
        $limit=$input['max_wallet_charge']??null;
        if(!is_numeric($limit)||!is_finite((float)$limit)||(float)$limit<0||(float)$limit>100||abs((float)$limit-round((float)$limit,2))>0.000001)throw new InvalidArgumentException('حدد سقف خصم المحفظة بالدولار، أو صفرًا لاستخدام الباقة فقط');
        $operation=$input['operation']??'measure_tracked';if(!is_string($operation)||!in_array($operation,['rank','gap','discover_site','measure_tracked'],true))throw new InvalidArgumentException('عملية قياس غير صالحة');
        $quote=$this->quote($uid,$wid,$mid,['rank'=>'rank','gap'=>'gap','discover_site'=>'discovery','measure_tracked'=>'demand'][$operation]);
        if(!is_string($input['quote_hash']??null)||!hash_equals($quote['quote_hash'],$input['quote_hash']))throw new InvalidArgumentException('تغيرت الكلمات أو الحصة أو التكلفة؛ راجع العرض الجديد قبل البدء');
        if(!$quote['available'])throw new InvalidArgumentException('القياس غير متاح؛ راجع تنقية الكلمات والربط والتسعير');
        if($quote['already_measured'])return ['available'=>true,'already_measured'=>true,'data'=>[],'wallet_charged'=>0];
        if($quote['wallet_max']>(float)$limit)throw new InvalidArgumentException('الاستخدام المتوقع يتجاوز حد المحفظة الذي وافقت عليه');
        $ledger=new SeoDataLedger($this->db,round((float)$limit,2));
        $client=$this->clientFactory?($this->clientFactory)($ledger):new DataForSeoClient(null,null,$ledger);
        $discovery=$quote['operation']==='discover_site';
        $research=new DataForSeoResearchService($this->db,$client);
        if($operation==='rank'){$ranker=new RankTrackingService(new DataForSeoRankingSource(null,null,$client,$wid));$result=$ranker->checkWebsite($this->db,$wid,$uid,$quote['market_id'],array_column($quote['keywords'],'keyword'));$result['data']=$result['results']??[];}
        elseif($operation==='gap'){
            $ranker=new RankTrackingService(new DataForSeoRankingSource(null,null,$client,$wid));$result=SeoKeywordGapService::refresh($this->db,$wid,$uid,$research,$ranker);$result['data']=$result['gaps']??[];
        }else $result=$research->research($wid,$uid,$quote['market_id'],array_column($quote['keywords'],'keyword'),!$discovery,5);
        if($discovery&&!empty($result['available']))$this->db->transaction(function()use($result,$uid,$wid,$quote){$s=$this->db->getConnection()->prepare('INSERT IGNORE INTO tracked_keywords(website_id,user_id,market_id,keyword) VALUES(?,?,?,?)');foreach($result['data'] as $row)$s->execute([$wid,$uid,$quote['market_id'],$row['keyword']]);});
        return $result+['wallet_charged'=>$ledger->walletSpent(),'max_units'=>$quote['max_units'],'currency'=>'USD'];
    }
}
