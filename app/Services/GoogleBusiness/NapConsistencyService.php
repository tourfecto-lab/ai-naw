<?php
class NapConsistencyService
{
    public static function text($value): string {
        if(is_array($value))$value=implode(' ',array_filter(array_merge(is_array($value['addressLines']??null)?$value['addressLines']:[],array_map(static fn($k)=>is_scalar($value[$k]??null)?(string)$value[$k]:'', ['locality','administrativeArea','postalCode','regionCode']))));
        $s=mb_strtolower(html_entity_decode(strip_tags((string)$value),ENT_QUOTES|ENT_HTML5,'UTF-8'));
        if(class_exists('Normalizer'))$s=Normalizer::normalize($s,Normalizer::FORM_KD)?:$s;
        return trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',preg_replace('/\p{Mn}+/u','',$s)));
    }
    public static function normalize(string $field,$value,string $country=''): string {
        if($field==='website')return DataForSeoRankingSource::normalizeHost((string)$value).rtrim((string)parse_url((string)$value,PHP_URL_PATH),'/');
        if($field==='phone'){
            $s=preg_replace('/\D/','',strtr((string)$value,['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']));
            if(str_starts_with($s,'00'))$s=substr($s,2);
            $codes=['EG'=>'20','GB'=>'44','US'=>'1','SA'=>'966','AE'=>'971'];
            if(str_starts_with($s,'0')&&isset($codes[$country]))$s=$codes[$country].substr($s,1);
            return $s;
        }return self::text($value);
    }
    private static function addressHasDetails($address): bool {
        if(!is_array($address))return self::text($address)!=='';
        unset($address['regionCode'],$address['languageCode']);
        return self::text($address)!=='';
    }
    private static function addressState($official,$observed): string {
        if(!self::addressHasDetails($official)||!self::addressHasDetails($observed))return 'unknown';
        if(is_array($official)&&is_array($observed)){
            $missing=false;
            foreach(['addressLines','locality','administrativeArea','postalCode','regionCode'] as $key){
                $a=$official[$key]??'';$b=$observed[$key]??'';
                $a=self::text(is_array($a)?implode(' ',$a):$a);$b=self::text(is_array($b)?implode(' ',$b):$b);
                if($a!==''&&$b!==''&&$a!==$b)return 'mismatch';
                if(($a==='')!==($b===''))$missing=true;
            }
            return $missing?'unknown':'match';
        }
        if(self::text($official)===self::text($observed))return 'match';
        // Unstructured footer text cannot establish which structured field differs.
        return is_array($official)||is_array($observed)?'unknown':'mismatch';
    }
    public static function compare(array $official,array $observed): array {
        $out=[];$country=(string)($official['address']['regionCode']??'');
        foreach(['name','phone','address','website'] as $field){
            $a=self::normalize($field,$official[$field]??'',$country);$b=self::normalize($field,$observed[$field]??'',$country);
            $state=$field==='address'?self::addressState($official[$field]??null,$observed[$field]??null):($a===''||$b===''?'unknown':($a===$b?'match':'mismatch'));
            $out[$field]=['state'=>$state,'expected'=>$official[$field]??null,'observed'=>$observed[$field]??null];
        }return $out;
    }
    public static function extract(string $html,string $customerDomain): array {
        $dom=new DOMDocument();$old=libxml_use_internal_errors(true);try{$dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR);}finally{libxml_clear_errors();libxml_use_internal_errors($old);}$xp=new DOMXPath($dom);$orgs=[];
        $walk=static function($node)use(&$walk,&$orgs,$customerDomain){if(!is_array($node))return;
            $types=(array)($node['@type']??[]);
            if(array_intersect($types,['Organization','LocalBusiness','TravelAgency','LodgingBusiness','TouristInformationCenter']) && is_string($node['url']??null) && DataForSeoRankingSource::matchesHost($node['url'],$customerDomain))$orgs[]=$node;
            foreach($node as $v)if(is_array($v))$walk($v);
        };
        foreach($xp->query('//script[@type="application/ld+json"]') as $script){$j=json_decode($script->textContent,true);if(is_array($j))$walk($j);}
        $out=[];if(count($orgs)===1){$o=$orgs[0];$address=$o['address']??'';
            if(is_array($address))$address=['addressLines'=>[$address['streetAddress']??''],'locality'=>$address['addressLocality']??'','administrativeArea'=>$address['addressRegion']??'','postalCode'=>$address['postalCode']??'','regionCode'=>is_array($address['addressCountry']??null)?($address['addressCountry']['name']??''):($address['addressCountry']??'')];
            $out=['name'=>is_string($o['name']??null)?$o['name']:null,'phone'=>is_string($o['telephone']??null)?$o['telephone']:null,'address'=>is_array($address)||is_string($address)?$address:null,'website'=>$o['url']??null];
        }
        if(empty($out['phone'])){foreach($xp->query('//footer//a[starts-with(@href,"tel:")]') as $tel){$out['phone']=substr($tel->getAttribute('href'),4);break;}}
        if(!self::addressHasDetails($out['address']??null)){foreach($xp->query('//footer//address') as $address){$out['address']=trim($address->textContent);break;}}
        return $out;
    }
    public function __construct(private Database $db,private ?SeoPublicPageReader $reader=null,private $official=null) {}
    public function check(int $uid,int $wid): array {
        $site=(new SeoMarketService($this->db))->site($wid,$uid);$rows=$this->db->query('SELECT profile_json FROM seo_local_profiles WHERE website_id=? AND user_id=?',[$wid,$uid]);if(!$rows)throw new InvalidArgumentException('Confirm the GBP profile first');
        $p=json_decode($rows[0]['profile_json'],true,512,JSON_THROW_ON_ERROR);
        if($this->official)$fresh=($this->official)($uid,$wid);
        else {
            $connection=(new GbpSyncService())->findConnection($wid,$uid);
            // A review-sync error can coexist with working profile access. Verify
            // the token and current profile instead of blocking on the saved flag.
            if(!$connection||!in_array($connection->getAttribute('status'),['connected','error'],true))throw new InvalidArgumentException('Reconnect Google Business before comparing citations');
            $token=(new GoogleReviewSyncService())->getValidAccessToken($connection);
            $r=(new GoogleBusinessAPI($token,$connection->getAttribute('external_account_id'),$connection->getAttribute('external_location_id')))->getLocation();
            if(empty($r['success']))throw new RuntimeException('Current GBP profile unavailable');$fresh=$r['location'];
        }
        if(!is_array($fresh))throw new RuntimeException('GBP profile unavailable');
        if(!empty($p['place_id'])&&($fresh['place_id']??'')!==$p['place_id'])throw new InvalidArgumentException('GBP identity changed. Reconfirm the branch first');
        $sources=array_merge([$site['main_url']],$p['directory_profiles']??[]);$observations=[];
        foreach(array_unique($sources) as $url){$page=($this->reader??new SeoPublicPageReader())->fetch($url);
            $observed=!empty($page['success'])?self::extract((string)$page['html'],DataForSeoRankingSource::normalizeHost($site['main_url'])):[];
            $checks=self::compare($fresh,$observed);$evidence=['source_url'=>$url,'checked_at'=>gmdate('c'),'fetch_state'=>!empty($page['success'])?'observed':'unavailable','checks'=>$checks];$observations[]=$evidence;
            $mismatch=array_filter($checks,static fn($r)=>$r['state']==='mismatch');
            $unknown=array_filter($checks,static fn($r)=>$r['state']==='unknown');
            $hash=hash('sha256',json_encode([$url,$checks],JSON_THROW_ON_ERROR));
            if(!empty($page['success'])){
                // Keep history, but only the current observed evidence is actionable.
                // Unknown replacement fields do not mean the old issue was fixed.
                $priorState=$mismatch||$unknown?'superseded':'resolved';
                $this->db->exec("UPDATE seo_citation_tasks SET status=? WHERE website_id=? AND user_id=? AND source_url=? AND status='pending' AND evidence_hash<>?",[$priorState,$wid,$uid,$url,$hash]);
            }
            if(!$unknown) {
                $this->db->exec("UPDATE seo_citation_tasks SET status='resolved' WHERE website_id=? AND user_id=? AND source_url=? AND status='pending'",[$wid,$uid,$url]);
            }
            if(!$mismatch)continue;
            $this->db->exec("INSERT INTO seo_citation_tasks(website_id,user_id,source_url,evidence_hash,evidence_json,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE status='pending',evidence_json=VALUES(evidence_json)",[$wid,$uid,$url,$hash,json_encode($evidence,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        }return ['observations'=>$observations,'note'=>'Missing or ambiguous markup is unknown. Review formatting/branch differences before changing a directory.'];
    }
}
