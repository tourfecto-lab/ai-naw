<?php
/** Campaign editing, filtered audiences, safe imports/exports and subscription reservations. */
final class WhatsAppCampaignTools extends WhatsAppMarketingService
{
    private bool $experimentLaunch=false;
    public function templates(int $uid,int $wid): array
    { $rows=parent::templates($uid,$wid);foreach($rows as &$r){try{$r['requirements']=WhatsAppTemplateComponents::requirements(json_decode($r['components_json']??'[]',true)?:[]);}catch(InvalidArgumentException $e){$r['requirements']=[];}}unset($r);return $rows;}
    public function quota(int $uid): array
    {
        $sub=Subscription::activeSubscriptionRow($uid);$features=FeatureResolver::resolve($uid)['features'];$limit=$features['whatsapp_marketing_messages']??match($sub['plan_name']??''){ 'starter'=>500,'professional'=>2000,'enterprise'=>10000,default=>0};
        $limit=filter_var($limit,FILTER_VALIDATE_INT);if($limit===false||$limit < -1||$limit>2147483647)$limit=0;
        $enabled=$sub!==null&&in_array($features['whatsapp_marketing']??true,[true,1,'1'],true)&&$limit!==0;
        $start=$sub?SubscriptionPeriod::quotaStart((string)($sub['current_period_start']??gmdate('Y-m-01 00:00:00'))):gmdate('Y-m-01 00:00:00');
        $used=(int)$this->q("SELECT COALESCE(SUM(q.units),0) FROM wa_marketing_quota_reservations q JOIN wa_marketing_campaigns c ON c.id=q.campaign_id WHERE q.user_id=? AND (q.period_start>=? OR c.state IN ('scheduled','running','paused','blocked'))",[$uid,$start])->fetchColumn();
        return ['enabled'=>$enabled,'limit'=>$limit,'reserved'=>$used,'remaining'=>$limit===-1?null:max(0,$limit-$used),'period_start'=>$start,'subscription_id'=>$sub['id']??null];
    }
    public function ensureAllowance(int $uid,int $id): bool
    {
        $key='wa_quota:'.$uid;if(!ExternalExecution::lock($key))throw new RuntimeException('Campaign allowance is busy. Try again.');
        try{$c=$this->campaign($uid,$id);$quota=$this->quota($uid);if(!$quota['enabled']||($quota['limit']!==-1&&$quota['reserved']>$quota['limit']))return false;
            if($this->q('SELECT campaign_id FROM wa_marketing_quota_reservations WHERE campaign_id=? AND user_id=?',[$id,$uid])->fetchColumn())return true;
            $units=(int)$this->q('SELECT COUNT(*) FROM wa_marketing_recipients WHERE campaign_id=?',[$id])->fetchColumn();if($quota['remaining']!==null&&$units>$quota['remaining'])return false;
            $this->q('INSERT INTO wa_marketing_quota_reservations(campaign_id,user_id,subscription_id,period_start,units) VALUES(?,?,?,?,?)',[$id,$uid,$quota['subscription_id'],$quota['period_start'],$units]);return true;
        }finally{ExternalExecution::unlock($key);}
    }
    public function launch(int $uid,int $id,array $ids,?string $scheduled): array
    {
        if(!$this->experimentLaunch){try{if($this->q('SELECT id FROM wa_campaign_experiments WHERE user_id=? AND (campaign_a=? OR campaign_b=?) LIMIT 1',[$uid,$id,$id])->fetchColumn())throw new InvalidArgumentException('Schedule both variants from the A/B experiment section');}catch(PDOException $e){if($e->getCode()!=='42S02')throw $e;}}
        $key='wa_quota:'.$uid;if(!ExternalExecution::lock($key))throw new RuntimeException('Another campaign is reserving quota. Try again.');
        try{$c=$this->campaign($uid,$id);if($c['state']!=='draft')return $c;$quota=$this->quota($uid);$units=count(array_unique($ids));if(!$quota['enabled']||($quota['remaining']!==null&&$units>$quota['remaining']))throw new InvalidArgumentException('Your subscription does not have enough WhatsApp marketing allowance.');
            $this->q('INSERT INTO wa_marketing_quota_reservations(campaign_id,user_id,subscription_id,period_start,units) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE subscription_id=VALUES(subscription_id),period_start=VALUES(period_start),units=VALUES(units)',[$id,$uid,$quota['subscription_id'],$quota['period_start'],$units]);
            try{return parent::launch($uid,$id,$ids,$scheduled);}catch(Throwable $e){if($this->campaign($uid,$id)['state']==='draft')$this->q('DELETE FROM wa_marketing_quota_reservations WHERE campaign_id=?',[$id]);throw $e;}
        }finally{ExternalExecution::unlock($key);}
    }
    public function launchExperimentVariant(int $uid,int $id,array $ids,string $scheduled,int $experimentId): array
    {
        $e=$this->q('SELECT * FROM wa_campaign_experiments WHERE id=? AND user_id=?',[$experimentId,$uid])->fetch(PDO::FETCH_ASSOC);if(!$e||!in_array($id,[(int)$e['campaign_a'],(int)$e['campaign_b']],true))throw new InvalidArgumentException('Experiment variant not found');
        $split=json_decode($e['split_json'],true,512,JSON_THROW_ON_ERROR);$expected=$split[$id===(int)$e['campaign_a']?'a':'b'];sort($expected);$actual=$ids;sort($actual);if($actual!==$expected)throw new InvalidArgumentException('Experiment audience cannot be changed');
        $this->experimentLaunch=true;try{return $this->launch($uid,$id,$ids,$scheduled);}finally{$this->experimentLaunch=false;}
    }
    public function action(int $uid,int $id,string $action): array
    {
        if(in_array($action,['retry','resume'],true)&&!$this->quota($uid)['enabled'])throw new InvalidArgumentException('An active subscription with WhatsApp marketing is required.');
        $r=parent::action($uid,$id,$action);
        // Reservations are conservative: failed/unknown sends remain charged; cancel frees requests never attempted.
        if($action==='cancel')$this->q('UPDATE wa_marketing_quota_reservations SET units=(SELECT COUNT(*) FROM wa_marketing_recipients WHERE campaign_id=? AND attempt_count>0) WHERE campaign_id=?',[$id,$id]);
        return $r;
    }
    public function audience(int $uid,int $wid,array $filters=[]): array
    {
        $this->owned($uid,$wid);$page=max(1,min(10000,(int)($filters['page']??1)));$where='user_id=? AND website_id=?';$args=[$uid,$wid];
        $search=trim((string)($filters['search']??''));if(mb_strlen($search)>100)throw new InvalidArgumentException('Search is too long');if($search!==''){$where.=' AND (name LIKE ? OR phone LIKE ?)';$args[]='%'.$search.'%';$args[]='%'.$search.'%';}
        foreach(['language','consent_state'] as $field){if(!empty($filters[$field])){$where.=' AND '.$field.'=?';$args[]=(string)$filters[$field];}}
        if(!empty($filters['tag'])){$where.=' AND FIND_IN_SET(?,tags)>0';$args[]=(string)$filters['tag'];}
        return $this->q('SELECT id,name,phone,language,consent_state,consent_note,tags FROM wa_marketing_contacts WHERE '.$where.' ORDER BY id DESC LIMIT 100 OFFSET '.(($page-1)*100),$args)->fetchAll(PDO::FETCH_ASSOC);
    }
    public function tag(int $uid,int $wid,int $id,string $tags): array
    {
        $this->owned($uid,$wid);$values=array_values(array_unique(array_filter(array_map('trim',explode(',',$tags)))));foreach($values as $v)if(!preg_match('/^[a-zA-Z0-9 _-]{1,40}$/D',$v))throw new InvalidArgumentException('Use short alphanumeric tags separated by commas');$value=implode(',',$values);if(strlen($value)>250)throw new InvalidArgumentException('Too many tags');
        if(!$this->q('SELECT id FROM wa_marketing_contacts WHERE id=? AND user_id=? AND website_id=?',[$id,$uid,$wid])->fetchColumn())throw new InvalidArgumentException('Contact not found');$this->q('UPDATE wa_marketing_contacts SET tags=? WHERE id=?',[$value,$id]);return ['saved'=>true];
    }
    public function tagBatch(int $uid,int $wid,array $ids,string $tags): array
    {
        $this->owned($uid,$wid);if(!$ids||count($ids)>500)throw new InvalidArgumentException('Select 1–500 contacts');$ids=array_values(array_unique(array_map('intval',$ids)));if(min($ids)<1)throw new InvalidArgumentException('Invalid contact selection');
        $values=array_values(array_unique(array_filter(array_map('trim',explode(',',$tags)))));foreach($values as $v)if(!preg_match('/^[a-zA-Z0-9 _-]{1,40}$/D',$v))throw new InvalidArgumentException('Use short alphanumeric tags separated by commas');$value=implode(',',$values);if(strlen($value)>250)throw new InvalidArgumentException('Too many tags');
        $placeholders=implode(',',array_fill(0,count($ids),'?'));$args=array_merge([$uid,$wid],$ids);$count=(int)$this->q('SELECT COUNT(*) FROM wa_marketing_contacts WHERE user_id=? AND website_id=? AND id IN ('.$placeholders.')',$args)->fetchColumn();if($count!==count($ids))throw new InvalidArgumentException('Every selected contact must belong to this website. Nothing was changed.');
        $this->q('UPDATE wa_marketing_contacts SET tags=? WHERE user_id=? AND website_id=? AND id IN ('.$placeholders.')',array_merge([$value],$args));return ['saved'=>$count];
    }
    public function edit(int $uid,int $id,array $input): array
    {
        $c=$this->campaign($uid,$id);if($c['state']!=='draft')throw new InvalidArgumentException('Only drafts can be edited');$params=$input['parameters']??[];$existing=json_decode($c['parameters_json'],true);$name=trim((string)($input['name']??$c['name']));if(!$name||mb_strlen($name)>190||!is_array($params)||!array_is_list($params)||count($params)!==count($existing))throw new InvalidArgumentException('Fill the campaign name and every template variable');foreach($params as $p)if(!is_string($p)||trim($p)===''||mb_strlen($p)>1000)throw new InvalidArgumentException('Invalid template variable');
        $components=json_decode($c['components_json']??'[]',true)?:[];$extras=WhatsAppTemplateComponents::validate($components,$input['extra_parameters']??json_decode($c['extra_parameters_json']??'[]',true)?:[]);
        $this->q("UPDATE wa_marketing_campaigns SET name=?,parameters_json=?,extra_parameters_json=? WHERE id=? AND user_id=? AND state='draft'",[$name,json_encode($params),json_encode($extras),$id,$uid]);return $this->campaign($uid,$id);
    }
    public function preview(int $uid,int $id,int $contactId): array
    {
        $c=$this->campaign($uid,$id);$contact=$this->q('SELECT * FROM wa_marketing_contacts WHERE id=? AND user_id=? AND website_id=?',[$contactId,$uid,$c['website_id']])->fetch(PDO::FETCH_ASSOC);if(!$contact)throw new InvalidArgumentException('Choose a contact from this website');$w=$this->owned($uid,(int)$c['website_id']);$params=self::parameters($c,$contact,$w['company_name']);$map=[];foreach($params as $i=>$p)$map['{{'.(is_int($i)?$i+1:$i).'}}']=$p;
        return ['text'=>strtr($c['template_body'],$map),'language'=>$c['language'],'sender'=>$w['company_name'],'phone'=>$contact['phone'],'eligible'=>$contact['consent_state']==='opted_in'&&self::compatible($contact['language'],$c['language']),'components'=>json_decode($c['components_json']??'[]',true)?:[],'extras'=>json_decode($c['extra_parameters_json']??'[]',true)?:[]];
    }
    public function importCsv(int $uid,int $wid,string $csv,bool $commit): array
    {
        $this->owned($uid,$wid);if(strlen($csv)>500000)throw new InvalidArgumentException('CSV must be under 500 KB');$f=fopen('php://temp','r+');fwrite($f,preg_replace('/^\xEF\xBB\xBF/','',$csv));rewind($f);$header=fgetcsv($f);if(!$header||count($header)!==count(array_unique($header))||!in_array('phone',$header,true)||!in_array('name',$header,true))throw new InvalidArgumentException('CSV needs unique name,phone columns; language is optional');$rows=[];$seen=[];$errors=[];$line=1;
        while(($cells=fgetcsv($f))!==false){$line++;if($line>501)throw new InvalidArgumentException('Import at most 500 rows');if($cells===[null])continue;try{if(count($cells)!==count($header))throw new InvalidArgumentException('Column count mismatch');$row=array_combine($header,$cells);$phone=self::phone((string)$row['phone']);$name=trim((string)$row['name']);if(!$name||mb_strlen($name)>190)throw new InvalidArgumentException('Invalid contact name');$lang=trim($row['language']??'')?:ContentLanguageService::forWebsite($wid,$uid);if(!preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/D',$lang))throw new InvalidArgumentException('Invalid language');if(isset($seen[$phone]))throw new InvalidArgumentException('Duplicate number in CSV');$seen[$phone]=true;$exists=(bool)$this->q('SELECT id FROM wa_marketing_contacts WHERE user_id=? AND website_id=? AND phone=?',[$uid,$wid,$phone])->fetchColumn();$rows[]=['name'=>$name,'phone'=>$phone,'language'=>$lang,'existing'=>$exists];}catch(InvalidArgumentException $e){$errors[]=['line'=>$line,'error'=>$e->getMessage()];}}
        fclose($f);if(!$rows&&!$errors)throw new InvalidArgumentException('CSV is empty');if($commit&&$errors)throw new InvalidArgumentException('Fix CSV errors before importing. Nothing was saved.');$imported=0;
        if($commit)foreach($rows as $row)if(!$row['existing']){$this->contact($uid,$wid,$row+['consent_state'=>'pending','consent_note'=>'CSV import; marketing permission not inferred']);$imported++;}
        return ['rows'=>$rows,'errors'=>$errors,'imported'=>$imported,'message'=>'Existing contacts are unchanged; new contacts require explicit marketing permission'];
    }
    public function export(int $uid,int $id): array
    {
        $c=$this->campaign($uid,$id);$rows=$this->q('SELECT r.name,r.phone,r.language,r.state,r.message_id,d.status AS delivery_status,r.last_error FROM wa_marketing_recipients r LEFT JOIN whatsapp_delivery_statuses d ON d.user_id=? AND d.website_id=? AND BINARY d.wamid=r.message_id WHERE r.campaign_id=? ORDER BY r.id',[$uid,$c['website_id'],$id])->fetchAll(PDO::FETCH_ASSOC);$f=fopen('php://temp','r+');fputcsv($f,['Name','Phone','Language','Send state','Message ID','Delivery','Note']);foreach($rows as $row){$safe=array_map(static function($v){$s=(string)($v??'');return preg_match('/^[=+@\-\t\r\n]/',$s)?"'".$s:$s;},array_values($row));fputcsv($f,$safe);}rewind($f);$csv=stream_get_contents($f);fclose($f);return ['filename'=>'whatsapp-campaign-'.$id.'.csv','csv'=>$csv];
    }
}
