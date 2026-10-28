<?php
/**
 * Visits from AI crawlers to the customer's website, from an uploaded access log or the live server snippet.
 * Identification is by user-agent only (not IP-verified), so a spoofed agent would be counted too.
 */
final class AiCrawlerService
{
    /** user-agent token => [label, owner, purpose]. purpose: training | search | user (fetched live for a person's question). */
    public const BOTS=[
        'OAI-SearchBot'=>['OAI-SearchBot','OpenAI','search'],'ChatGPT-User'=>['ChatGPT-User','OpenAI','user'],'GPTBot'=>['GPTBot','OpenAI','training'],
        'Claude-SearchBot'=>['Claude-SearchBot','Anthropic','search'],'Claude-User'=>['Claude-User','Anthropic','user'],'ClaudeBot'=>['ClaudeBot','Anthropic','training'],'anthropic-ai'=>['anthropic-ai','Anthropic','training'],
        'Perplexity-User'=>['Perplexity-User','Perplexity','user'],'PerplexityBot'=>['PerplexityBot','Perplexity','search'],
        'meta-externalfetcher'=>['meta-externalfetcher','Meta','user'],'meta-externalagent'=>['meta-externalagent','Meta','training'],
        'DuckAssistBot'=>['DuckAssistBot','DuckDuckGo','user'],'MistralAI-User'=>['MistralAI-User','Mistral','user'],'cohere-ai'=>['cohere-ai','Cohere','user'],
        'YouBot'=>['YouBot','You.com','search'],'Amazonbot'=>['Amazonbot','Amazon','search'],'Applebot'=>['Applebot','Apple','search'],
        'Bytespider'=>['Bytespider','ByteDance','training'],'CCBot'=>['CCBot','Common Crawl','training'],
    ];
    private const MAX_LOG_BYTES=25000000;
    private Database $db;
    public function __construct(?Database $db=null){$this->db=$db??Database::getInstance();}
    private function q(string $sql,array $args=[]): PDOStatement{$s=$this->db->getConnection()->prepare($sql);$s->execute($args);return $s;}
    public static function identify(string $ua): ?array
    {
        if ($ua==='') return null;
        foreach (self::BOTS as $token=>$meta) if (stripos($ua,$token)!==false) return ['bot'=>$meta[0],'owner'=>$meta[1],'purpose'=>$meta[2]];
        return null;
    }
    public static function cleanPath(string $raw): string
    {
        $p=parse_url($raw,PHP_URL_PATH);$p=is_string($p)&&$p!==''?$p:'/';
        $p=preg_replace('/[\x00-\x1f\x7f]+/','',rawurldecode($p));
        return mb_substr($p[0]==='/'?$p:'/'.$p,0,255);
    }
    /** Parse Apache/Nginx "combined" lines; returns [aggregates keyed day|bot|path, stats]. */
    public static function parseLog(iterable $lines): array
    {
        $agg=[];$lines_read=0;$matched=0;$unparsed=0;
        foreach ($lines as $line) {
            $lines_read++;
            if (!preg_match('/^\S+ \S+ \S+ \[([^\]]+)\] "[A-Z]+ (\S+)[^"]*" (\d{3}) \S+ "[^"]*" "([^"]*)"/',(string)$line,$m)) {$unparsed++;continue;}
            $bot=self::identify($m[4]);if(!$bot)continue;
            $t=DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O',$m[1]);if(!$t){$unparsed++;continue;}
            $t=$t->setTimezone(new DateTimeZone('UTC'));$path=self::cleanPath($m[2]);$key=$t->format('Y-m-d').'|'.$bot['bot'].'|'.$path;
            $row=$agg[$key]??['day'=>$t->format('Y-m-d'),'bot'=>$bot['bot'],'purpose'=>$bot['purpose'],'path'=>$path,'hits'=>0,'last_seen'=>$t->format('Y-m-d H:i:s')];
            $row['hits']++;if($t->format('Y-m-d H:i:s')>$row['last_seen'])$row['last_seen']=$t->format('Y-m-d H:i:s');$agg[$key]=$row;$matched++;
        }
        return [$agg,['lines'=>$lines_read,'ai_hits'=>$matched,'unparsed'=>$unparsed]];
    }
    private function owned(int $uid,int $wid): void
    {
        if (!$this->q('SELECT id FROM websites WHERE id=? AND user_id=?',[$wid,$uid])->fetchColumn()) throw new InvalidArgumentException('الموقع مش موجود');
        AiInsightsSchema::ensureSchema($this->db);
    }
    /** Re-importing a log replaces that log's days, so uploading the same file twice never double counts. */
    public function importLog(int $uid,int $wid,string $file): array
    {
        $this->owned($uid,$wid);
        if (!is_file($file) || filesize($file)>self::MAX_LOG_BYTES) throw new InvalidArgumentException('ارفع ملف سجل أصغر من 25 ميجا');
        $gz=str_starts_with((string)file_get_contents($file,false,null,0,2),"\x1f\x8b");$h=$gz?gzopen($file,'rb'):fopen($file,'rb');
        if (!$h) throw new InvalidArgumentException('تعذر قراءة الملف');
        $gen=(function()use($h,$gz){while(($l=$gz?gzgets($h,8192):fgets($h,8192))!==false)yield $l;$gz?gzclose($h):fclose($h);})();
        [$agg,$stats]=self::parseLog($gen);
        if ($stats['lines']>0 && $stats['unparsed']===$stats['lines']) throw new InvalidArgumentException('صيغة السجل مش مدعومة؛ المطلوب access log بصيغة combined من Apache أو Nginx');
        $days=array_values(array_unique(array_column($agg,'day')));
        $this->db->transaction(function()use($uid,$wid,$agg,$days){
            foreach (array_chunk($days,200) as $chunk) $this->q('DELETE FROM seo_ai_crawler_hits WHERE website_id=? AND source=\'log\' AND day IN ('.implode(',',array_fill(0,count($chunk),'?')).')',array_merge([$wid],$chunk));
            foreach ($agg as $r) $this->q('INSERT INTO seo_ai_crawler_hits(website_id,user_id,day,bot,purpose,path_hash,path,source,hits,last_seen) VALUES (?,?,?,?,?,?,?,\'log\',?,?)',[$wid,$uid,$r['day'],$r['bot'],$r['purpose'],sha1($r['path']),$r['path'],$r['hits'],$r['last_seen']]);
        });
        return $stats+['days'=>count($days)];
    }
    /** Returns the plaintext token once; only its hash is stored. */
    public function rotateToken(int $uid,int $wid): string
    {
        $this->owned($uid,$wid);$token='tfc_'.bin2hex(random_bytes(24));
        $this->q('INSERT INTO seo_ai_crawler_tokens(website_id,user_id,token_hash,created_at) VALUES (?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),token_hash=VALUES(token_hash),created_at=VALUES(created_at)',[$wid,$uid,hash('sha256',$token)]);
        return $token;
    }
    public function hasToken(int $uid,int $wid): bool{$this->owned($uid,$wid);return (bool)$this->q('SELECT 1 FROM seo_ai_crawler_tokens WHERE website_id=? AND user_id=?',[$wid,$uid])->fetchColumn();}
    /** Live hit from the customer's server snippet. Unknown agents are dropped silently. */
    public function ingest(string $token,string $ua,string $path): bool
    {
        if (!preg_match('/^tfc_[a-f0-9]{48}$/D',$token)) return false;
        AiInsightsSchema::ensureSchema($this->db);
        $site=$this->q('SELECT website_id,user_id FROM seo_ai_crawler_tokens WHERE token_hash=?',[hash('sha256',$token)])->fetch(PDO::FETCH_ASSOC);
        if (!$site) return false;
        $bot=self::identify(mb_substr($ua,0,500));if(!$bot)return true;$path=self::cleanPath($path);
        $this->q("INSERT INTO seo_ai_crawler_hits(website_id,user_id,day,bot,purpose,path_hash,path,source,hits,last_seen) VALUES (?,?,UTC_DATE(),?,?,?,?,'live',1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE hits=hits+1,last_seen=UTC_TIMESTAMP()",[$site['website_id'],$site['user_id'],$bot['bot'],$bot['purpose'],sha1($path),$path]);
        return true;
    }
    public function summary(int $uid,int $wid,int $days=30): array
    {
        $this->owned($uid,$wid);$args=[$wid,$uid,$days];$w='website_id=? AND user_id=? AND day>=DATE_SUB(UTC_DATE(),INTERVAL ? DAY)';
        $bots=$this->q("SELECT bot,purpose,SUM(hits) hits,COUNT(DISTINCT path_hash) pages,MAX(last_seen) last_seen FROM seo_ai_crawler_hits WHERE $w GROUP BY bot,purpose ORDER BY hits DESC",$args)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($bots as &$b) { $b['owner']=self::BOTS[$b['bot']][1]??''; $b['hits']=(int)$b['hits'];$b['pages']=(int)$b['pages']; } unset($b);
        $daily=$this->q("SELECT day,purpose,SUM(hits) hits FROM seo_ai_crawler_hits WHERE $w GROUP BY day,purpose ORDER BY day",$args)->fetchAll(PDO::FETCH_ASSOC);
        $pages=$this->q("SELECT path,SUM(hits) hits,COUNT(DISTINCT bot) bots FROM seo_ai_crawler_hits WHERE $w GROUP BY path_hash,path ORDER BY hits DESC LIMIT 25",$args)->fetchAll(PDO::FETCH_ASSOC);
        $purpose=['training'=>0,'search'=>0,'user'=>0];foreach($bots as $b)$purpose[$b['purpose']]=($purpose[$b['purpose']]??0)+$b['hits'];
        $sources=$this->q("SELECT source,MAX(last_seen) last_seen FROM seo_ai_crawler_hits WHERE website_id=? AND user_id=? GROUP BY source",[$wid,$uid])->fetchAll(PDO::FETCH_KEY_PAIR);
        return ['days'=>$days,'bots'=>$bots,'daily'=>$daily,'pages'=>$pages,'purpose'=>$purpose,'sources'=>$sources,'token_set'=>$this->hasToken($uid,$wid),
            'basis'=>'Identified by user-agent; requests are not IP-verified.'];
    }
    public static function snippet(string $token,string $endpoint): string
    {
        $pattern=implode('|',array_map(fn($t)=>preg_quote($t,'/'),array_keys(self::BOTS)));
        return "<?php\n// Tourfecto: AI crawler visits. Put this at the top of your site's index.php (or a WordPress mu-plugin).\n"
            ."(function(){\$ua=\$_SERVER['HTTP_USER_AGENT']??'';if(!preg_match('/".$pattern."/i',\$ua)||!function_exists('curl_init'))return;\n"
            ." \$ch=curl_init(".var_export($endpoint,true).");\n"
            ." curl_setopt_array(\$ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['ua'=>substr(\$ua,0,500),'path'=>substr(\$_SERVER['REQUEST_URI']??'/',0,500)]),\n"
            ."  CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Tourfecto-Token: ".$token."'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT_MS=>800,CURLOPT_CONNECTTIMEOUT_MS=>400]);\n"
            ." @curl_exec(\$ch);curl_close(\$ch);})();\n";
    }
}
