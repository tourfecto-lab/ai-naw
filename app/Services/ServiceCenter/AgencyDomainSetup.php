<?php
/** DNS verification and observed HTTPS routing, never automatically claims hosting or OAuth configuration. */
class AgencyDomainSetup
{
    private PDO $pdo;private $dns;private $http;
    public function __construct(?PDO $pdo=null,?callable $dns=null,?callable $http=null){$this->pdo=$pdo??Database::getInstance()->getConnection();$this->dns=$dns;$this->http=$http;}
    private function q(string $sql,array $a=[]): PDOStatement{$s=$this->pdo->prepare($sql);$s->execute($a);return $s;}
    private function agency(int $uid,int $id): array{$a=$this->q("SELECT id,name,slug,status FROM agencies WHERE id=? AND owner_user_id=? AND status='active'",[$id,$uid])->fetch(PDO::FETCH_ASSOC);if(!$a)throw new RuntimeException('Agency not found',404);return $a;}
    public function listing(int $uid): array{return $this->q('SELECT a.id,a.name,d.domain,d.dns_state,d.https_state,d.last_checked_at FROM agencies a LEFT JOIN agency_domain_setups d ON d.agency_id=a.id AND d.user_id=a.owner_user_id WHERE a.owner_user_id=? ORDER BY a.id DESC',[$uid])->fetchAll(PDO::FETCH_ASSOC);}
    public function status(int $uid,int $id): array{$this->agency($uid,$id);$r=$this->q('SELECT * FROM agency_domain_setups WHERE agency_id=? AND user_id=?',[$id,$uid])->fetch(PDO::FETCH_ASSOC);if(!$r)return ['state'=>'not_configured'];return $r+['txt_name'=>'_tourfecto-verification.'.$r['domain'],'txt_value'=>'tourfecto-agency='.$r['verification_token'],'routing_target'=>(string)parse_url(defined('APP_URL')?APP_URL:'',PHP_URL_HOST),'hosting_required'=>true,'scope'=>'Branded public agency page. Client authentication uses the canonical platform.'];}
    public function save(int $uid,int $id,string $domain): array {
        $this->agency($uid,$id);$domain=CustomDomainService::normalize($domain);if($domain==='')throw new InvalidArgumentException('Enter an agency domain');
        $lock='domain-claim:'.$domain;if(!ExternalExecution::lock($lock))throw new RuntimeException('Domain assignment is busy');
        try{
        if($this->q('SELECT generated_website_id FROM site_custom_domains WHERE domain=?',[$domain])->fetchColumn())throw new RuntimeException('This domain is already assigned to a website');
        $old=$this->status($uid,$id);if(($old['domain']??'')===$domain)return $old;
        try{$this->q("INSERT INTO agency_domain_setups(agency_id,user_id,domain,verification_token,created_at,updated_at) VALUES(?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE domain=IF(agency_id=VALUES(agency_id),VALUES(domain),domain),verification_token=IF(agency_id=VALUES(agency_id),VALUES(verification_token),verification_token),dns_state=IF(agency_id=VALUES(agency_id),'pending_dns',dns_state),https_state=IF(agency_id=VALUES(agency_id),'not_checked',https_state),verified_at=IF(agency_id=VALUES(agency_id),NULL,verified_at),updated_at=NOW()",[$id,$uid,$domain,bin2hex(random_bytes(24))]);}catch(PDOException $e){if($e->getCode()==='23000')throw new RuntimeException('Domain is already assigned');throw $e;}
        $r=$this->status($uid,$id);if(($r['domain']??'')!==$domain)throw new RuntimeException('Domain is already assigned');return $r;
        }finally{ExternalExecution::unlock($lock);}
    }
    public function verify(int $uid,int $id): array {
        $s=$this->status($uid,$id);if(empty($s['domain']))throw new RuntimeException('Save a domain first');$records=$this->dns?($this->dns)($s['txt_name']):(dns_get_record($s['txt_name'],DNS_TXT)?:[]);$ok=false;foreach($records as $r)if(hash_equals($s['txt_value'],(string)($r['txt']??'')))$ok=true;
        if(!$ok)throw new RuntimeException('The ownership TXT record has not been observed');
        $this->q("UPDATE agency_domain_setups SET dns_state='verified',verified_at=NOW(),updated_at=NOW() WHERE agency_id=? AND user_id=? AND verification_token=?",[$id,$uid,$s['verification_token']]);return $this->status($uid,$id);
    }
    public function checkHttps(int $uid,int $id): array {
        $s=$this->status($uid,$id);if(($s['dns_state']??'')!=='verified')throw new RuntimeException('Verify DNS ownership first');$url='https://'.$s['domain'].'/.well-known/tourfecto-agency';
        $r=$this->http?($this->http)($url):SafeHttp::exec($url,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: application/json']],0);
        $data=json_decode((string)($r['response']??''),true);$verified=(int)($r['code']??0)===200&&is_array($data)&&($data['agency_id']??0)===$id&&hash_equals($s['verification_token'],(string)($data['verification']??''));
        $this->q('UPDATE agency_domain_setups SET https_state=?,last_checked_at=NOW(),updated_at=NOW() WHERE agency_id=? AND user_id=? AND verification_token=?',[$verified?'routing_verified':'needs_hosting_setup',$id,$uid,$s['verification_token']]);return $this->status($uid,$id);
    }
    public static function publicAgency(string $host): ?array {
        $host=strtolower(preg_replace('/:\d+$/','',$host));if(!filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME))return null;
        $p=Database::getInstance()->getConnection();$s=$p->prepare("SELECT a.id,a.name,a.slug,d.domain,d.verification_token,b.primary_color,b.logo_path FROM agency_domain_setups d JOIN agencies a ON a.id=d.agency_id AND a.owner_user_id=d.user_id LEFT JOIN agency_branding b ON b.agency_id=a.id WHERE d.domain=? AND d.dns_state='verified' AND a.status='active' LIMIT 1");$s->execute([$host]);$a=$s->fetch(PDO::FETCH_ASSOC);return $a?:null;
    }
}
