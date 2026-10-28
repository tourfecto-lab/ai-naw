<?php
/** Ownership verification only; webserver bindings and TLS remain hosting operations. */
class CustomDomainService
{
    public static function normalize(string $domain): string{
        $domain=strtolower(rtrim(trim($domain),'.'));if($domain==='')return '';
        if(strlen($domain)>253||!str_contains($domain,'.')||!filter_var($domain,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)||filter_var($domain,FILTER_VALIDATE_IP))throw new InvalidArgumentException('اكتب اسم نطاق فقط بدون بروتوكول أو مسار');
        $platform=strtolower((string)parse_url(defined('APP_URL')?APP_URL:'',PHP_URL_HOST));if($domain===$platform)throw new InvalidArgumentException('النطاق الرئيسي محجوز للمنصة');return $domain;
    }
    public static function configure(int $siteId,int $userId,string $domain): array{
        $domain=self::normalize($domain);$pdo=Database::getInstance()->getConnection();
        if($domain===''){$q=$pdo->prepare('DELETE FROM site_custom_domains WHERE generated_website_id=? AND user_id=?');$q->execute([$siteId,$userId]);return ['state'=>'not_configured'];}
        $lock='domain-claim:'.$domain;if(!ExternalExecution::lock($lock))throw new RuntimeException('Domain assignment is busy');
        try {
        $a=$pdo->prepare('SELECT agency_id FROM agency_domain_setups WHERE domain=?');$a->execute([$domain]);if($a->fetchColumn())throw new RuntimeException('Domain is already assigned to an agency');
        $q=$pdo->prepare('SELECT * FROM site_custom_domains WHERE generated_website_id=? AND user_id=?');$q->execute([$siteId,$userId]);$old=$q->fetch(PDO::FETCH_ASSOC);
        if($old&&$old['domain']===$domain)return self::status($siteId,$userId);
        $token=bin2hex(random_bytes(24));$q=$pdo->prepare("INSERT INTO site_custom_domains(generated_website_id,user_id,domain,verification_token,state) VALUES(?,?,?,?,'pending_dns') ON DUPLICATE KEY UPDATE domain=IF(generated_website_id=VALUES(generated_website_id),VALUES(domain),domain),verification_token=IF(generated_website_id=VALUES(generated_website_id),VALUES(verification_token),verification_token),state=IF(generated_website_id=VALUES(generated_website_id),'pending_dns',state),verified_at=IF(generated_website_id=VALUES(generated_website_id),NULL,verified_at)");$q->execute([$siteId,$userId,$domain,$token]);
        $current=self::status($siteId,$userId);if(($current['domain']??'')!==$domain)throw new RuntimeException('النطاق مربوط بموقع آخر');return $current;
        }finally{ExternalExecution::unlock($lock);}
    }
    public static function status(int $siteId,int $userId): array{
        $q=Database::getInstance()->getConnection()->prepare('SELECT domain,verification_token,state,verified_at FROM site_custom_domains WHERE generated_website_id=? AND user_id=?');$q->execute([$siteId,$userId]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)return ['state'=>'not_configured'];
        return $row+['txt_name'=>'_tourfecto-verification.'.$row['domain'],'txt_value'=>'tourfecto='.$row['verification_token'],'routing_target'=>(string)parse_url(defined('APP_URL')?APP_URL:'',PHP_URL_HOST),'hosting_required'=>true];
    }
    public static function verify(int $siteId,int $userId): array{
        $status=self::status($siteId,$userId);if(empty($status['domain']))throw new RuntimeException('أضف نطاقًا أولًا');
        $verified=false;foreach((dns_get_record($status['txt_name'],DNS_TXT)?:[]) as $record)if(hash_equals($status['txt_value'],(string)($record['txt']??'')))$verified=true;
        if(!$verified)throw new RuntimeException('سجل TXT غير موجود أو لم ينتشر بعد');
        $q=Database::getInstance()->getConnection()->prepare("UPDATE site_custom_domains SET state='verified',verified_at=NOW() WHERE generated_website_id=? AND user_id=? AND verification_token=?");$q->execute([$siteId,$userId,$status['verification_token']]);return self::status($siteId,$userId);
    }
    public static function isVerified(int $siteId,string $domain): bool{
        $q=Database::getInstance()->getConnection()->prepare("SELECT COUNT(*) FROM site_custom_domains WHERE generated_website_id=? AND domain=? AND state='verified'");$q->execute([$siteId,strtolower($domain)]);return (int)$q->fetchColumn()===1;
    }
}
