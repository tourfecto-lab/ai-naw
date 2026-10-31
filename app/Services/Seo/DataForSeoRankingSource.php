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
    /** عمر الكاش المشترك لنتايج جوجل (ساعات): أقل من فاصل الفحص اليومي، وبيغطي كل المواقع اللي بتتابع نفس الكلمة في نفس السوق في نفس التشغيلة */
    public const SERP_TTL_HOURS = 20;

    /** عمق مخفّض (صفحتين) للكلمات المستقرة في أول 10. لو اختفت منه نتحقق بالعمق الكامل قبل ما نسجّل إنها خرجت */
    public const DEPTH_STABLE = 20;

    private static bool $cacheReady = false;

    private static function cacheDb(): ?Database
    {
        try {
            $db = Database::getInstance();
            if (!self::$cacheReady) {
                $db->getConnection()->exec("CREATE TABLE IF NOT EXISTS seo_serp_cache (
                    cache_key CHAR(64) NOT NULL PRIMARY KEY, keyword VARCHAR(255) NOT NULL, location_code INT NOT NULL, language_code VARCHAR(12) NOT NULL, device VARCHAR(8) NOT NULL,
                    depth SMALLINT NOT NULL, items_json MEDIUMTEXT NOT NULL, hits INT NOT NULL DEFAULT 0, fetched_at DATETIME NOT NULL, KEY idx_fetched (fetched_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                self::$cacheReady = true;
            }
            return $db;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function serpCacheKey(string $keyword, int $location, string $language, string $device): string
    {
        return hash('sha256', 'serp|' . preg_replace('/\s+/u', ' ', mb_strtolower(trim($keyword))) . '|' . $location . '|' . $language . '|' . $device);
    }

    /** @return list<array{type:string,url:?string,domain:?string,rank_group:int}>|null */
    private function cachedItems(string $keyword, int $depth): ?array
    {
        $db = self::cacheDb();
        if (!$db) return null;
        try {
            $key = self::serpCacheKey($keyword, (int) $this->settings['location_code'], (string) $this->settings['language_code'], ($this->settings['device'] ?? 'desktop') === 'mobile' ? 'mobile' : 'desktop');
            $r = $db->query('SELECT items_json FROM seo_serp_cache WHERE cache_key = ? AND depth >= ? AND fetched_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . self::SERP_TTL_HOURS . ' HOUR)', [$key, $depth]);
            if (!$r) return null;
            $db->query('UPDATE seo_serp_cache SET hits = hits + 1 WHERE cache_key = ?', [$key]);
            $items = json_decode((string) $r[0]['items_json'], true);
            return is_array($items) ? $items : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function storeItems(string $keyword, int $depth, array $items): void
    {
        $db = self::cacheDb();
        if (!$db) return;
        try {
            // بنخزّن العضوي بس وبأقل حقول: كفاية لحساب ترتيب أي دومين
            $compact = [];
            foreach ($items as $it) {
                if (($it['type'] ?? '') !== 'organic') continue;
                $compact[] = ['type' => 'organic', 'url' => $it['url'] ?? null, 'domain' => $it['domain'] ?? null, 'rank_group' => (int) ($it['rank_group'] ?? 0)];
            }
            $device = ($this->settings['device'] ?? 'desktop') === 'mobile' ? 'mobile' : 'desktop';
            $key = self::serpCacheKey($keyword, (int) $this->settings['location_code'], (string) $this->settings['language_code'], $device);
            $db->query('REPLACE INTO seo_serp_cache (cache_key, keyword, location_code, language_code, device, depth, items_json, hits, fetched_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, UTC_TIMESTAMP())',
                [$key, mb_substr($keyword, 0, 255), (int) $this->settings['location_code'], (string) $this->settings['language_code'], $device, $depth, json_encode($compact, JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) {
            // الكاش إضافة: لو فشل نكمّل من غيره
        }
    }

    /**
     * @param array<string,int> $hints آخر ترتيب معروف لكل كلمة (مفتاح: الكلمة lowercase) - بيسمح بعمق أقل للكلمات المستقرة في أول 10
     */
    public function check(string $domain,array $keywords,array $hints=[]): array
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
            $hint = $hints[mb_strtolower(trim($keyword))] ?? null;
            $depth = ($hint !== null && $hint >= 1 && $hint <= 10) ? self::DEPTH_STABLE : self::DEPTH;
            $err = '';
            $items = $this->serpItems($keyword, $depth, $err);
            if ($items === null) return $bad($err)+['partial_rankings'=>$rankings];
            [$position,$url] = self::locate($items, $domain);
            if ($position === null && $depth < self::DEPTH) {
                // كانت في أول 10 ومش ظاهرة في أول 20: ممكن تكون وقعت لـ25 مثلًا، فنفحص العمق الكامل قبل أي تسجيل
                $items = $this->serpItems($keyword, self::DEPTH, $err);
                if ($items === null) return $bad($err)+['partial_rankings'=>$rankings];
                [$position,$url] = self::locate($items, $domain);
            }
            $rankings[]=['keyword'=>$keyword,'position'=>$position,'url'=>$url];
        }
        return ['available'=>true,'reason'=>null,'rankings'=>$rankings];
    }

    /** أعلى ترتيب عضوي للدومين في النتايج @return array{0:?int,1:?string} */
    private static function locate(array $items, string $domain): array
    {
        $position=null; $url=null;
        foreach ($items as $item) {
            if (($item['type']??'')!=='organic' || !self::matchesHost((string)($item['url']??$item['domain']??''),$domain)) continue;
            $p=(int)($item['rank_group']??0);
            if ($p>=1 && $p<=100 && ($position===null || $p<$position)) { $position=$p; $url=$item['url']??null; }
        }
        return [$position,$url];
    }

    /** نتايج جوجل للكلمة: من الكاش المشترك لو موجودة بعمق كفاية، وإلا طلب مدفوع واحد بيتخزّن. null + سبب لو فشل */
    private function serpItems(string $keyword, int $depth, string &$error): ?array
    {
        $items = $this->cachedItems($keyword, $depth);
        if ($items !== null) return $items;
        $r=$this->client->call('serp/google/organic/live/advanced',[[
            'keyword'=>$keyword,'location_code'=>(int)$this->settings['location_code'],'language_code'=>$this->settings['language_code'],
            'device'=>($this->settings['device']??'desktop')==='mobile'?'mobile':'desktop','depth'=>$depth]],$this->websiteId,1,0.10);
        if (!$r['available']) { $error = $r['reason'] ?? 'rank_source_unavailable'; return null; }
        $result=$r['response']['tasks'][0]['result'][0]??[];
        if (!array_key_exists('items',$result)) { $error = 'rank_source_invalid_response'; return null; }
        $items = $result['items'] ?? [];
        $this->storeItems($keyword, $depth, $items);
        return $items;
    }
}
