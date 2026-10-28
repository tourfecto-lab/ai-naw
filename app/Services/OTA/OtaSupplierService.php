<?php
/** Read-only supplier observations. These records never reserve or overwrite local stock. */
final class OtaSupplierService
{
    private PDO $pdo;private $factory;
    public function __construct(?PDO $pdo=null,?callable $factory=null){$this->pdo=$pdo??Database::getInstance()->getConnection();$this->factory=$factory;}
    private function q(string $sql,array $args=[]): PDOStatement {$s=$this->pdo->prepare($sql);$s->execute($args);return $s;}
    public function owned(int $uid,int $wid): array
    {$w=$this->q('SELECT id,domain FROM websites WHERE id=? AND user_id=?',[$wid,$uid])->fetch(PDO::FETCH_ASSOC);if(!$w)throw new InvalidArgumentException('Website not found');return $w;}
    private function lock(int $wid,string $platform,callable $fn){$key='ota:'.$wid.':'.$platform;$s=$this->q('SELECT GET_LOCK(?,0)',[$key])->fetchColumn();if((int)$s!==1)throw new RuntimeException('Another OTA operation is in progress');try{return $fn();}finally{$this->q('SELECT RELEASE_LOCK(?)',[$key]);}}
    public function connection(int $uid,int $wid,string $platform): ?array
    {$this->owned($uid,$wid);OtaProviderCatalog::get($platform);$r=$this->q('SELECT * FROM ota_supplier_connections WHERE user_id=? AND website_id=? AND platform=?',[$uid,$wid,$platform])->fetch(PDO::FETCH_ASSOC);return $r?:null;}
    private function client(array $r): object
    {if(!OtaProviderCatalog::supplier($r['platform'])||empty($r['credentials_encrypted']))throw new InvalidArgumentException('Live adapter is not enabled for this platform');$credentials=json_decode((new Encryption())->decrypt($r['credentials_encrypted']),true,512,JSON_THROW_ON_ERROR);if($this->factory)return ($this->factory)($r,$credentials);return $r['platform']==='bokun'?new BokunAPI($credentials['access_key'],$credentials['secret_key'],(int)$r['account_id'],$r['environment']):new RezdyAPI($credentials['api_key'],$r['environment']);}
    private static function scalar(array $a,string $key,int $max=190): string
    {if(isset($a[$key])&&!is_scalar($a[$key]))throw new InvalidArgumentException('Invalid '.$key);$s=trim((string)($a[$key]??''));if(strlen($s)>$max||strpbrk($s,"\r\n")!==false)throw new InvalidArgumentException('Invalid '.$key);return $s;}
    public function save(int $uid,int $wid,string $platform,array $input): array
    {
        $this->owned($uid,$wid);$meta=OtaProviderCatalog::get($platform);if($meta['mode']==='legacy_partner')throw new InvalidArgumentException('Use the existing partner connection form');
        $env=self::scalar($input,'environment');if(!in_array($env,['production','sandbox'],true))throw new InvalidArgumentException('Choose production or sandbox');
        $account=self::scalar($input,'account_id');$approval=self::scalar($input,'approval_reference');$credentials=[];
        if($meta['mode']==='supplier_read'){
            foreach($platform==='bokun'?['access_key','secret_key']:['api_key'] as $k){$credentials[$k]=self::scalar($input,$k,2048);if($credentials[$k]==='')throw new InvalidArgumentException('Enter '.$k);}
            if($platform==='bokun'&&(!ctype_digit($account)||strlen($account)>18||(int)$account<1))throw new InvalidArgumentException('Enter your numeric Bókun vendor ID');
        }
        return $this->lock($wid,$platform,function()use($uid,$wid,$platform,$env,$account,$approval,$credentials,$meta){
            $encrypted=$credentials?(new Encryption())->encrypt(json_encode($credentials,JSON_THROW_ON_ERROR)):null;
            $candidate=['platform'=>$platform,'environment'=>$env,'account_id'=>$account,'credentials_encrypted'=>$encrypted];
            if($credentials){$verified=$this->client($candidate)->verifyToken();if(empty($verified['success']))throw new InvalidArgumentException($verified['error']??'Unable to verify connection');}
            $this->pdo->beginTransaction();try{
                $this->owned($uid,$wid);
                $this->q("INSERT INTO ota_supplier_connections(user_id,website_id,platform,environment,account_id,credentials_encrypted,state,verified_at,approval_reference) VALUES(?,?,?,?,?,?,?, ?,?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),environment=VALUES(environment),account_id=VALUES(account_id),credentials_encrypted=VALUES(credentials_encrypted),state=VALUES(state),verified_at=VALUES(verified_at),approval_reference=VALUES(approval_reference),auto_sync=0,last_synced_at=NULL,next_sync_at=NULL,last_error=NULL",[$uid,$wid,$platform,$env,$account,$encrypted,$credentials?'verified_read_only':'pending_integration',$credentials?gmdate('Y-m-d H:i:s'):null,$approval]);
                $row=$this->connection($uid,$wid,$platform);$this->q('DELETE FROM ota_supplier_records WHERE connection_id=?',[$row['id']]);$this->q('DELETE FROM ota_supplier_product_mappings WHERE connection_id=?',[$row['id']]);$this->pdo->commit();
            }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
            return $this->status($uid,$wid,$platform);
        });
    }
    public function status(int $uid,int $wid,string $platform): array
    {
        $meta=OtaProviderCatalog::get($platform);$r=$this->connection($uid,$wid,$platform);
        $counts=[];if($r)foreach($this->q('SELECT kind,COUNT(*) n FROM ota_supplier_records WHERE connection_id=? GROUP BY kind',[$r['id']])->fetchAll(PDO::FETCH_ASSOC) as $v)$counts[$v['kind']]=(int)$v['n'];
        return ['platform'=>$platform,'meta'=>$meta,'configured'=>$r!==null,'connection_state'=>$r['state']??'disconnected','environment'=>$r['environment']??'production','account_id'=>$r['account_id']??'','approval_reference'=>$r['approval_reference']??'','credentials_saved'=>!empty($r['credentials_encrypted']),'verified_at'=>$r['verified_at']??null,'last_synced_at'=>$r['last_synced_at']??null,'last_error'=>$r['last_error']??null,'auto_sync'=>!empty($r['auto_sync']),'snapshot_counts'=>$counts,'booking_write_enabled'=>false,'inventory_push_enabled'=>false,'live_verified'=>!empty($r['verified_at']),'sync_window'=>'Tour dates from 30 days ago to 180 days ahead. Up to 150 products and 150 bookings per run. A capped or failed run is marked partial.'];
    }
    public function toggle(int $uid,int $wid,string $platform,bool $enabled): array
    {return $this->lock($wid,$platform,function()use($uid,$wid,$platform,$enabled){$r=$this->connection($uid,$wid,$platform);if(!$r||!OtaProviderCatalog::supplier($platform)||empty($r['verified_at']))throw new InvalidArgumentException('Verify a supplier API connection first');$this->q('UPDATE ota_supplier_connections SET auto_sync=?,next_sync_at=? WHERE id=?',[$enabled?1:0,$enabled?gmdate('Y-m-d H:i:s'):null,$r['id']]);return $this->status($uid,$wid,$platform);});}
    public function disconnect(int $uid,int $wid,string $platform): void
    {$this->lock($wid,$platform,function()use($uid,$wid,$platform){$r=$this->connection($uid,$wid,$platform);if(!$r)return;$this->pdo->beginTransaction();try{foreach(['ota_supplier_records','ota_supplier_product_mappings'] as $table)$this->q('DELETE FROM '.$table.' WHERE connection_id=?',[$r['id']]);$this->q('DELETE FROM ota_supplier_connections WHERE id=?',[$r['id']]);$this->pdo->commit();}catch(Throwable $e){$this->pdo->rollBack();throw $e;}});}
    public static function items(string $platform,string $kind,array $data): array
    {
        if($platform==='rezdy'){$key=$kind==='product'?'products':'bookings';if(!isset($data[$key])||!is_array($data[$key]))throw new RuntimeException('Unexpected Rezdy response');return $data[$key];}
        if($kind==='product'){if(!isset($data['items'])||!is_array($data['items']))throw new RuntimeException('Unexpected Bókun response');return $data['items'];}
        if(isset($data['results'])&&is_array($data['results']))return $data['results'];
        if(!array_is_list($data))throw new RuntimeException('Unexpected Bókun booking response');$rows=[];foreach($data as $group){if(!is_array($group)||!isset($group['results'])||!is_array($group['results']))throw new RuntimeException('Unexpected Bókun booking group');array_push($rows,...$group['results']);}return $rows;
    }
    public static function summary(string $platform,string $kind,array $item): array
    {
        $id=(string)($platform==='bokun'?($item['id']??''):($kind==='product'?($item['productCode']??''):($item['orderNumber']??'')));
        if($id===''||strlen($id)>190)throw new RuntimeException('Provider record has no valid identity');
        $v=['external_id'=>$id,'title'=>(string)($item['title']??$item['name']??$item['product']['title']??$item['product']['name']??$item['items'][0]['productName']??''),'status'=>(string)($item['status']??''),'currency'=>$kind==='booking'?(string)($item['currency']??$item['totalCurrency']??''):'','reference'=>(string)($item['confirmationCode']??$item['orderNumber']??$id),'start_time'=>(string)($item['startDate']??$item['items'][0]['startTime']??''),'read_only'=>true];
        // Multi-item Rezdy orders retain every item reference/date; no customer contact data.
        if($platform==='rezdy'&&$kind==='booking'){$v['items']=[];foreach(($item['items']??[]) as $line){$v['items'][]=['product_code'=>(string)($line['productCode']??''),'title'=>(string)($line['productName']??''),'start_time'=>(string)($line['startTime']??''),'quantity'=>(int)($line['totalQuantity']??0)];}}
        if($platform==='bokun'&&$kind==='product')$v['vendor_id']=(string)($item['vendor']['id']??'');
        return $v;
    }
    private function store(int $connection,string $kind,string $id,array $summary): void
    {$this->q('INSERT INTO ota_supplier_records(connection_id,kind,external_id,summary_json,observed_at) VALUES(?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE summary_json=VALUES(summary_json),observed_at=VALUES(observed_at)',[$connection,$kind,$id,json_encode($summary,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);}
    public function sync(int $uid,int $wid,string $platform,?string $expectedHash=null,?int $expectedId=null): array
    {
        return $this->lock($wid,$platform,function()use($uid,$wid,$platform,$expectedHash,$expectedId){
            $r=$this->connection($uid,$wid,$platform);if($expectedHash!==null&&(!$r||(int)$r['id']!==$expectedId||!hash_equals(hash('sha256',(string)$r['credentials_encrypted']),$expectedHash)))return ['state'=>'superseded'];
            if(!$r||empty($r['verified_at']))throw new InvalidArgumentException('Verify the connection first');$client=$this->client($r);$this->q("UPDATE ota_supplier_connections SET state='syncing',last_error=NULL WHERE id=?",[$r['id']]);$count=['product'=>0,'booking'=>0];$errors=[];$capped=false;
            foreach(['product','booking'] as $kind){try{
                for($page=1;$page<=3;$page++){
                    $result=$kind==='product'?$client->products($page):$client->bookings($page,gmdate('Y-m-d',time()-30*86400),gmdate('Y-m-d',time()+180*86400));
                    if(empty($result['success']))throw new RuntimeException($result['error']??'Provider request failed');$items=self::items($platform,$kind,$result['data']);
                    foreach($items as $item){if(!is_array($item))throw new RuntimeException('Invalid provider record');$v=self::summary($platform,$kind,$item);if($platform==='bokun'&&$kind==='product'&&$v['vendor_id']!==''&&$v['vendor_id']!==$r['account_id'])throw new RuntimeException('Bókun returned another vendor product');$this->store((int)$r['id'],$kind,$v['external_id'],$v);$count[$kind]++;}
                    if(count($items)<50)break;if($page===3)$capped=true;
                }
            }catch(Throwable $e){$errors[]=$kind.': '.($e instanceof RuntimeException?$e->getMessage():'Unable to refresh provider records');}}
            if($capped)$errors[]='Page limit reached. Snapshot is partial; request a larger backfill adapter for this account.';
            $state=$errors?'partial':'synced_read_only';$this->q('UPDATE ota_supplier_connections SET state=?,last_error=?,last_synced_at=?,next_sync_at=? WHERE id=?',[$state,$errors?substr(implode(' | ',$errors),0,255):null,gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s',time()+($errors?3600:1800)),$r['id']]);
            return ['state'=>$state,'observed'=>$count,'errors'=>$errors,'complete_for_window'=>!$errors];
        });
    }
    public function records(int $uid,int $wid,string $platform,string $kind,int $page=1): array
    {if(!in_array($kind,['product','booking','availability'],true)||$page<1||$page>10000)throw new InvalidArgumentException('Invalid record page');$r=$this->connection($uid,$wid,$platform);if(!$r)return [];$rows=$this->q('SELECT external_id,summary_json,observed_at FROM ota_supplier_records WHERE connection_id=? AND kind=? ORDER BY id LIMIT 50 OFFSET '.(($page-1)*50),[$r['id'],$kind])->fetchAll(PDO::FETCH_ASSOC);foreach($rows as &$row){$row['summary']=json_decode($row['summary_json'],true);unset($row['summary_json']);}return $rows;}
    public function map(int $uid,int $wid,string $platform,string $externalId,int $product): void
    {$this->lock($wid,$platform,function()use($uid,$wid,$platform,$externalId,$product){$r=$this->connection($uid,$wid,$platform);if(!$r||!OtaProviderCatalog::supplier($platform))throw new InvalidArgumentException('Supplier connection not found');if(!$this->q('SELECT id FROM crm_products WHERE id=? AND user_id=?',[$product,$uid])->fetchColumn())throw new InvalidArgumentException('Owned product not found');if(!$this->q("SELECT id FROM ota_supplier_records WHERE connection_id=? AND kind='product' AND external_id=?",[$r['id'],$externalId])->fetchColumn())throw new InvalidArgumentException('Sync this provider product first');$this->q('INSERT INTO ota_supplier_product_mappings(connection_id,external_id,product_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE product_id=VALUES(product_id)',[$r['id'],$externalId,$product]);});}
    public function mappings(int $uid,int $wid,string $platform): array
    {$r=$this->connection($uid,$wid,$platform);if(!$r)return [];return $this->q('SELECT m.external_id,m.product_id,p.name FROM ota_supplier_product_mappings m JOIN crm_products p ON p.id=m.product_id AND p.user_id=? WHERE m.connection_id=?',[$uid,$r['id']])->fetchAll(PDO::FETCH_ASSOC);}
    public function availability(int $uid,int $wid,string $platform,string $externalId,string $from,string $to): array
    {
        if(!InventoryService::isValidDate($from)||!InventoryService::isValidDate($to)||$to<$from||(strtotime($to)-strtotime($from))/86400>31)throw new InvalidArgumentException('Choose a valid date range of at most 31 days');
        return $this->lock($wid,$platform,function()use($uid,$wid,$platform,$externalId,$from,$to){$r=$this->connection($uid,$wid,$platform);if(!$r)throw new InvalidArgumentException('Connection not found');$mapping=$this->q('SELECT p.id FROM ota_supplier_product_mappings m JOIN crm_products p ON p.id=m.product_id AND p.user_id=? WHERE m.connection_id=? AND m.external_id=?',[$uid,$r['id'],$externalId])->fetchColumn();if(!$mapping)throw new InvalidArgumentException('Map this external product to your own product first');$result=$this->client($r)->availability($externalId,$from,$to);if(empty($result['success']))throw new RuntimeException($result['error']??'Unable to load availability');
            $data=$result['data'];if($platform==='rezdy'){if(!isset($data['sessions'])||!is_array($data['sessions']))throw new RuntimeException('Unexpected Rezdy availability response');$rows=$data['sessions'];}else {if(!array_is_list($data))throw new RuntimeException('Unexpected Bókun availability response');$rows=$data;}
            $sessions=[];foreach($rows as $row){if(!is_array($row))throw new RuntimeException('Invalid provider session');$session=[];foreach($platform==='rezdy'?['startTime','endTime','startTimeLocal','endTimeLocal','seatsAvailable','seats','allDay','prices']:['date','startTimeId','startTime','availabilityCount','unlimitedAvailability','soldOut','prices'] as $key)if(array_key_exists($key,$row))$session[$key]=$row[$key];$sessions[]=$session;}
            $snapshot=['external_id'=>$externalId,'from'=>$from,'to'=>$to,'read_only'=>true,'sessions'=>$sessions];$this->store((int)$r['id'],'availability',hash('sha256',$externalId.'|'.$from.'|'.$to),$snapshot);return $snapshot;
        });
    }
    public function queue(int $uid,int $wid,string $platform): void
    {$this->lock($wid,$platform,function()use($uid,$wid,$platform){$r=$this->connection($uid,$wid,$platform);if(!$r||!OtaProviderCatalog::supplier($platform)||!$r['verified_at'])throw new InvalidArgumentException('Verify a supplier connection first');if($r['state']==='queued'||$r['state']==='syncing')return;if($r['last_synced_at']&&strtotime($r['last_synced_at'].' UTC')>time()-300)throw new InvalidArgumentException('Wait five minutes between manual synchronizations');$this->pdo->beginTransaction();try{if(!(new QueueManager())->push(OtaSupplierSyncJob::class,['user_id'=>$uid,'website_id'=>$wid,'platform'=>$platform,'connection_id'=>(int)$r['id'],'verified_at'=>$r['verified_at'],'config_hash'=>hash('sha256',(string)$r['credentials_encrypted'])],'ota'))throw new RuntimeException('Queue is unavailable');$this->q("UPDATE ota_supplier_connections SET state='queued',next_sync_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE) WHERE id=?",[$r['id']]);$this->pdo->commit();}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}});}
    public function queueDue(int $limit=10): int
    {$n=0;
        foreach($this->q("SELECT user_id,website_id,platform,id FROM ota_supplier_connections WHERE state IN ('queued','syncing') AND updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 MINUTE)")->fetchAll(PDO::FETCH_ASSOC) as $stale){try{$this->lock((int)$stale['website_id'],$stale['platform'],function()use($stale){$this->q("UPDATE ota_supplier_connections SET state='partial',last_error='Previous read was interrupted. A new read is scheduled.',next_sync_at=UTC_TIMESTAMP() WHERE id=? AND state IN ('queued','syncing') AND updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 MINUTE)",[$stale['id']]);});}catch(Throwable $e){}}
        foreach($this->q("SELECT c.* FROM ota_supplier_connections c JOIN websites w ON w.id=c.website_id AND w.user_id=c.user_id WHERE c.auto_sync=1 AND c.platform IN ('bokun','rezdy') AND c.verified_at IS NOT NULL AND c.next_sync_at<=UTC_TIMESTAMP() AND c.state NOT IN ('queued','syncing') ORDER BY c.next_sync_at LIMIT ".max(1,min(20,$limit)))->fetchAll(PDO::FETCH_ASSOC) as $r){try{$this->queue((int)$r['user_id'],(int)$r['website_id'],$r['platform']);$n++;}catch(Throwable $e){}}return $n;}
}
