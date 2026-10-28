<?php
/** Market-specific organic ranks. Source batches cap at 20; Live accepts one task per HTTP request. */
class DataForSeoRankingSource implements KeywordRankingSourceInterface
{
    /** أول 30 نتيجة (3 صفحات جوجل) بدل 100: بيغطي كل الكلمات اللي ليها قيمة وتكلفته حوالي ربع العمق 100 */
    public const DEPTH = 30;
    private array $settings;
    private DataForSeoClient $client;
    private int $websiteId;
    public function __construct(?array $settings=null,?callable $transport=null,?DataForSeoClient $client=null,int $websiteId=0)
    {
        if ($settings===null) {
            $s=new SystemSettingsService(); $settings=[];
            foreach (['enabled','login','password','location_code','language_code','device','monthly_budget_usd'] as $k) $settings[$k]=$s->get('serp_'.$k);
        }
        $this->settings=$settings; $this->websiteId=$websiteId;
        $this->client=$client??new DataForSeoClient($settings,$transport);
    }
    public function forMarket(array $market,int $websiteId): self
    {
        $settings=$this->settings; $settings['location_code']=$market['location_code']; $settings['language_code']=$market['language_code'];
        return new self($settings,null,$this->client,$websiteId);
    }
    public function isConfigured(): bool { return $this->client->isConfigured(); }
    public function sourceName(): string { return 'dataforseo_google_organic'; }
    /** Strip only the legacy AI report's explicit (intent, priority) annotation.
     * Parentheses containing real query terms remain part of the phrase. */
    public static function keywordPhrase(string $value): string
    {
        $intent='(?:informational|navigational|commercial|transactional|معلوماتية|معلوماتي|تجارية|تجاري|معاملاتية|معاملاتي|تنقلية|تنقلي)';
        $priority='(?:high|medium|low|عالية|متوسطة|منخفضة|عالي|متوسط|منخفض)';
        return trim((string)preg_replace('~\s*\(\s*'.$intent.'(?:\s*/\s*'.$intent.')*\s*[,،]\s*'.$priority.'\s*\)\s*$~iu','',trim($value)));
    }
    public static function normalizeHost(string $value): string
    {
        $host=parse_url(preg_match('#^https?://#i',$value)?$value:'https://'.$value,PHP_URL_HOST);
        $host=preg_replace('/^www\./i','',strtolower(rtrim((string)$host,'.')));
        return filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)?$host:'';
    }
    public static function matchesHost(string $candidate,string $target): bool
    {
        $a=self::normalizeHost($candidate); $b=self::normalizeHost($target);
        return $a!=='' && $b!=='' && ($a===$b || str_ends_with($a,'.'.$b));
    }
    public function check(string $domain,array $keywords): array
    {
        $bad=fn($reason)=>['available'=>false,'reason'=>$reason,'rankings'=>[]];
        if (!$this->isConfigured()) return $bad('no_keyword_ranking_source_configured');
        $domain=self::normalizeHost($domain); if (!$domain) return $bad('invalid_domain');
        if ((int)($this->settings['location_code']??0)<1 || !preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D',(string)($this->settings['language_code']??''))) return $bad('invalid_market');
        $keywords=array_values(array_unique(array_filter(array_map('trim',$keywords))));
        if (count($keywords)>20) return $bad('rank_batch_limit_20');
        foreach ($keywords as $keyword) {
            if (mb_strlen($keyword)>255) return $bad('invalid_keyword');
            if (self::keywordPhrase($keyword)!==$keyword) return $bad('keyword_annotations_require_review');
            // Advanced operators multiply provider charges; rank tracking accepts plain keywords only.
            if (preg_match('/\b(?:site|inurl|intitle|intext|filetype|link|define|id|info|inanchor|allinurl|allintitle|allintext|allinanchor):/i',$keyword))
                return $bad('search_operators_not_supported');
        }
        $rankings=[];
        foreach ($keywords as $keyword) {
            $r=$this->client->call('serp/google/organic/live/advanced',[[
                'keyword'=>$keyword,'location_code'=>(int)$this->settings['location_code'],'language_code'=>$this->settings['language_code'],
                'device'=>($this->settings['device']??'desktop')==='mobile'?'mobile':'desktop','depth'=>self::DEPTH]],$this->websiteId,1,0.10);
            if (!$r['available']) return $bad($r['reason']??'rank_source_unavailable')+['partial_rankings'=>$rankings];
            $result=$r['response']['tasks'][0]['result'][0]??[];
            if (!array_key_exists('items',$result)) return $bad('rank_source_invalid_response');
            $position=null; $url=null;
            foreach ($result['items']??[] as $item) {
                if (($item['type']??'')!=='organic' || !self::matchesHost((string)($item['url']??$item['domain']??''),$domain)) continue;
                $p=(int)($item['rank_group']??0);
                if ($p>=1 && $p<=100 && ($position===null || $p<$position)) { $position=$p; $url=$item['url']??null; }
            }
            $rankings[]=['keyword'=>$keyword,'position'=>$position,'url'=>$url];
        }
        return ['available'=>true,'reason'=>null,'rankings'=>$rankings];
    }
}
