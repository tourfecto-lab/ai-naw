<?php

/**
 * Tourfecto - Backlink Monitor Service (Item 2a)
 * @version 1.0.0
 *
 * بيراقب الباك لينكس اللي اتحصل عليها فعليًا (monitored_backlinks):
 * - checkLink(): فحص رابط واحد عبر HTTP GET آمن (SSRF-protected عبر
 *   WebsiteSnapshotFetcher) وتحديث الحالة live/lost.
 * - registerAcquiredLink(): تسجيل رابط جديد فور تحويل مرشّح لحالة
 *   link_acquired (idempotent - لا يُسجّل نفس الرابط مرتين لنفس المرشّح).
 * - monitorDue(): الفحص الدوري (أسبوعي) لكل الروابط اللي عدّى على آخر
 *   فحص لها 7 أيام أو لسه متفحصةش.
 *
 * لا يحاول أبدًا الوصول لصفحات محمية أو الالتفاف على robots — مجرد GET
 * عام للصفحة اللي فيها الرابط، بنفس منطق الأمان في WebsiteSnapshotFetcher.
 */
class BacklinkMonitorService
{
    private const CHECK_INTERVAL_DAYS = 7;

    /** @var callable|null فاتح الصفحات — حقن قابل للاختبار بدل WebsiteSnapshotFetcher الحقيقي */
    private $fetcher;

    public function __construct(?callable $fetcher = null)
    {
        $this->fetcher = $fetcher;
    }

    /**
     * فحص رابط واحد والتحقق من استجابته.
     * @param int $backlinkId
     * @return array{success:bool, backlink:?array, status:?string, http_status:?int, error:?string}
     */
    public function checkLink(int $backlinkId): array
    {
        $backlink = (new MonitoredBacklink())->find($backlinkId);
        if (!$backlink) {
            return ['success' => false, 'backlink' => null, 'status' => null, 'http_status' => null, 'error' => 'backlink_not_found'];
        }

        $url = (string) $backlink->getAttribute('link_url');
        $fetch = $this->fetchUrl($url);

        $db=Database::getInstance();
        $sites=$db->query('SELECT main_url FROM websites WHERE id=? AND user_id=?',[(int)$backlink->getAttribute('website_id'),(int)$backlink->getAttribute('user_id')]);
        if (!$sites) return ['success'=>false,'status'=>null,'error'=>'website_unavailable'];
        $evidence=self::detect((string)($fetch['html']??$fetch['body']??''),(string)($fetch['url']??$url),$sites[0]['main_url']);
        $code=(int)($fetch['http_status']??0);
        $state=!empty($fetch['success'])&&$code===200?($evidence['live']?'live':'lost'):(in_array($code,[404,410],true)?'lost':'unavailable');
        $db->transaction(function()use($db,$backlinkId,$backlink,$fetch,$evidence,$code,$state){
            $locked=$db->query('SELECT status,check_count FROM monitored_backlinks WHERE id=? FOR UPDATE',[$backlinkId])[0];$before=$locked['status'];
            $backlink->setAttribute('status',$state==='unavailable'?$before:$state);
            $backlink->setAttribute('last_checked_at',gmdate('Y-m-d H:i:s'));
            $backlink->setAttribute('check_count',(int)$locked['check_count']+1);
            $backlink->setAttribute('last_http_status',$code?:null);
            $backlink->setAttribute('last_error',$state==='unavailable'?($fetch['error']??'verification_unavailable'):null);
            if($state==='live')$backlink->setAttribute('last_seen_live_at',gmdate('Y-m-d H:i:s'));
            $backlink->save();
            $db->exec('REPLACE INTO seo_backlink_evidence(backlink_id,rel_json,anchor_text,target_url,state,checked_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())',[$backlinkId,json_encode($evidence['rel']),$evidence['anchor'],$evidence['target_url'],$state]);
            if($before==='live'&&$state==='lost')Notification::notify((int)$backlink->getAttribute('user_id'),'backlink_lost','اختفى رابط لموقعك','لم يعد الرابط موجودًا في الصفحة العامة: '.$backlink->getAttribute('link_url'),'/outreach');
        });
        return ['success'=>$state!=='unavailable','backlink'=>$backlink->toArray(),'status'=>$state,'http_status'=>$code?:null,'evidence'=>$evidence,'error'=>$fetch['error']??null];
    }

    /** HTTP 200 alone is not evidence: require a real anchor resolved to the owned host. */
    public static function detect(string $html,string $sourceUrl,string $customerUrl): array
    {
        $empty=['live'=>false,'anchor'=>'','rel'=>[],'target_url'=>null];
        $host=DataForSeoRankingSource::normalizeHost($customerUrl);if($host==='')return $empty;
        $dom=new DOMDocument();$old=libxml_use_internal_errors(true);try{$dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR);}finally{libxml_clear_errors();libxml_use_internal_errors($old);}
        foreach($dom->getElementsByTagName('a') as $a){
            $href=trim(html_entity_decode($a->getAttribute('href'),ENT_QUOTES|ENT_HTML5,'UTF-8'));
            if(str_starts_with($href,'//'))$href=(parse_url($sourceUrl,PHP_URL_SCHEME)?:'https').':'.$href;
            elseif(str_starts_with($href,'/'))$href=(parse_url($sourceUrl,PHP_URL_SCHEME)?:'https').'://'.parse_url($sourceUrl,PHP_URL_HOST).$href;
            if(!in_array(parse_url($href,PHP_URL_SCHEME),['http','https'],true)||parse_url($href,PHP_URL_USER)!==null||parse_url($href,PHP_URL_PASS)!==null||!DataForSeoRankingSource::matchesHost($href,$host))continue;
            return ['live'=>true,'anchor'=>mb_substr(trim(preg_replace('/\s+/u',' ',$a->textContent)),0,1000),
                'rel'=>array_values(array_intersect(['nofollow','sponsored','ugc'],preg_split('/\s+/',strtolower(trim($a->getAttribute('rel')))))),'target_url'=>$href];
        }return $empty;
    }

    /**
     * تسجيل رابط جديد بعد الحصول عليه فعليًا (idempotent).
     * لو نفس المرشّح/الرابط مسجّل قبل كده، نرجّع السجل الموجود (لا نكرر).
     */
    public function registerAcquiredLink(int $userId, int $websiteId, int $prospectId, string $linkUrl, string $domain = ''): MonitoredBacklink
    {
        $db = Database::getInstance();
        if (!$db->query('SELECT id FROM websites WHERE id=? AND user_id=?',[$websiteId,$userId])) throw new InvalidArgumentException('Website unavailable');
        $existing = $db->query(
            'SELECT * FROM monitored_backlinks WHERE prospect_id = ? AND link_url = ? AND user_id=? AND website_id=? LIMIT 1',
            [$prospectId, $linkUrl,$userId,$websiteId]
        );
        if (!empty($existing)) {
            return new MonitoredBacklink($existing[0]);
        }

        if ($domain === '') {
            $host = parse_url($linkUrl, PHP_URL_HOST);
            $domain = $host !== null && $host !== false ? (string) $host : '';
        }

        $backlink = new MonitoredBacklink([
            'user_id' => $userId,
            'website_id' => $websiteId,
            'prospect_id' => $prospectId,
            'link_url' => $linkUrl,
            'domain' => $domain,
            'status' => 'pending',
            'check_count' => 0,
        ]);
        $backlink->save();
        return $backlink;
    }

    /**
     * الروابط المستحقة للفحص: لسه متفحصةش، أو عدّى 7 أيام على آخر فحص.
     */
    public function dueBacklinks(int $limit = 200): array
    {
        $db = Database::getInstance();
        return $db->query(
            'SELECT * FROM monitored_backlinks
             WHERE last_checked_at IS NULL
                OR last_checked_at <= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY last_checked_at ASC
             LIMIT ?',
            [self::CHECK_INTERVAL_DAYS, $limit]
        );
    }

    /**
     * الفحص الدوري لكل الروابط المستحقة (يستدعي من cron أسبوعي).
     * @return array{scanned:int, live:int, lost:int, failed:int}
     */
    public function monitorDue(int $limit = 200): array
    {
        $due = $this->dueBacklinks($limit);
        $stats = ['scanned' => count($due), 'live' => 0, 'lost' => 0, 'failed' => 0];
        foreach ($due as $row) {
            try {
                $res = $this->checkLink((int) $row['id']);
                if ($res['status'] === 'live') {
                    $stats['live']++;
                } elseif ($res['status']==='lost') {
                    $stats['lost']++;
                } else {
                    $stats['failed']++;
                }
            } catch (Throwable $e) {
                $stats['failed']++;
                if (class_exists('Logger')) {
                    Logger::warning('Backlink monitor: check failed', ['backlink_id' => $row['id'], 'error' => $e->getMessage()]);
                }
            }
        }
        return $stats;
    }

    /**
     * ملخص حالة الباك لينكس لموقع معيّن (لتقرير الأداء).
     * @return array{total:int, pending:int, live:int, lost:int}
     */
    public function summaryForWebsite(int $userId, int $websiteId): array
    {
        $db = Database::getInstance();
        $rows = $db->query(
            'SELECT status, COUNT(*) AS cnt FROM monitored_backlinks
             WHERE user_id = ? AND website_id = ? GROUP BY status',
            [$userId, $websiteId]
        );
        $summary = ['total' => 0, 'pending' => 0, 'live' => 0, 'lost' => 0];
        foreach ($rows as $row) {
            $status = $row['status'];
            if (array_key_exists($status, $summary)) {
                $summary[$status] = (int) $row['cnt'];
                $summary['total'] += (int) $row['cnt'];
            }
        }
        return $summary;
    }

    /**
     * جلب صفحة بأمان (SSRF-protected). يستخدم حقنة الاختبار لو موجودة،
     * وإلا WebsiteSnapshotFetcher (اللي بيتحقق من SSRF لكل قفزة redirect).
     */
    private function fetchUrl(string $url): array
    {
        if ($this->fetcher !== null) {
            $res = call_user_func($this->fetcher, $url);
            return is_array($res) ? $res : ['success' => false, 'http_status' => null, 'error' => 'invalid_fetcher_result'];
        }

        return (new SeoPublicPageReader())->fetch($url);
    }
}
