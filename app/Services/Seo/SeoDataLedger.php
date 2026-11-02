<?php
/** Global provider budget reservation, serialized across workers; user billing is atomic. */
class SeoDataLedger
{
    private Database $db;
    private ?float $cap=null;
    private float $walletSpent=0;
    public function __construct(?Database $db = null,private ?float $walletLimit=null) {
        if ($walletLimit!==null && (!is_finite($walletLimit)||$walletLimit<0)) throw new InvalidArgumentException('Invalid wallet limit');
        $this->db = $db ?? Database::getInstance();
    }
    public function walletSpent(): float {return $this->walletSpent;}

    /**
     * الحجوزات المعلقة: طلب "unknown" (انقطع الاتصال) أو "pending" (العملية وقفت في النص) أو خطأ من غير تكلفة كان بيفضل حاجز
     * السقف كله (0.10$ لبحث جوجل) طول الشهر، فالميزانية بتخلص أسرع من الصرف الحقيقي بكتير (فحص AI CEO: 1.12$ من 3.94$ محجوزين).
     * بعد ساعة بنسجله بمتوسط التكلفة الفعلية لنفس الخدمة آخر 60 يوم (status=estimated)، ومن غير ما يعدّي الحجز الأصلي.
     * لو مفيش تاريخ للخدمة دي بيفضل الحجز زي ما هو (أحوط). محفظة العميل مش بتتلمس هنا.
     * @return int عدد الطلبات اللي اتسوّت
     */
    public function reconcileStale(int $olderThanMinutes = 60): int
    {
        SeoDataSchema::ensureSchema($this->db);
        $s = $this->db->getConnection()->prepare("UPDATE dataforseo_calls d
            JOIN (SELECT endpoint, AVG(cost) AS avg_cost FROM dataforseo_calls WHERE status = 'complete' AND cost > 0 AND created_at >= UTC_TIMESTAMP() - INTERVAL 60 DAY GROUP BY endpoint) a ON a.endpoint = d.endpoint
            SET d.cost = LEAST(d.reserved_cost, a.avg_cost), d.reserved_cost = 0, d.status = 'estimated'
            WHERE d.status IN ('unknown', 'pending', 'provider_error') AND d.reserved_cost > 0 AND d.created_at < UTC_TIMESTAMP() - INTERVAL ? MINUTE");
        $s->execute([max(5, $olderThanMinutes)]);
        return $s->rowCount();
    }

    public function reserve(string $endpoint, int $websiteId, int $units, float $ceiling, float $cap): int
    {
        $this->cap=$cap;
        SeoDataSchema::ensureSchema($this->db);
        $pdo = $this->db->getConnection();
        $lock = $pdo->query("SELECT GET_LOCK('tourfecto:dataforseo:budget',10)")->fetchColumn();
        if ((int)$lock !== 1) throw new RuntimeException('seo_budget_busy');
        try {
            $this->reconcileStale();
            $spent = (float)$pdo->query("SELECT COALESCE(SUM(GREATEST(cost,reserved_cost)),0) FROM dataforseo_calls WHERE created_at >= DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-01')")->fetchColumn();
            if (!is_finite($cap) || $cap <= 0 || round($spent + $ceiling,6) > round($cap,6)) {
                $this->alert();
                throw new RuntimeException('seo_monthly_budget_reached');
            }
            $this->assertDailyBudget($pdo,$ceiling);
            $site=$pdo->prepare('SELECT user_id FROM websites WHERE id=?'); $site->execute([$websiteId]);
            $owner=$site->fetchColumn();
            if (!$owner) throw new RuntimeException('website_not_found');
            // Initialize PlanUsage's schema check before opening the billing transaction.
            PlanUsage::quota((int)$owner,'seo_data');
            $automatic=$this->walletLimit===null;
            $limit=$this->walletLimit;
            $spent=$automatic?0.0:$this->walletSpent;
            $note='DataForSEO '.($automatic?'auto':'manual').' website:'.$websiteId.' '.$endpoint;
            if($automatic){
                $s=$pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='seo_expert_sites'");$s->execute();
                $policy=[];if($s->fetchColumn()){$s=$pdo->prepare('SELECT policy_json FROM seo_expert_sites WHERE website_id=? AND user_id=?');$s->execute([$websiteId,$owner]);$policy=json_decode((string)$s->fetchColumn(),true)?:[];}
                $value=$policy['max_data_wallet_monthly']??0;
                $limit=is_numeric($value)&&is_finite((float)$value)&&$value>=0?round((float)$value,2):0.0;
                // Only charges carrying this explicit policy marker count. Old unscoped notes are preserved.
                if($limit>0){$s=$pdo->prepare("SELECT COALESCE(-SUM(amount),0) FROM wallet_transactions WHERE user_id=? AND feature_key='seo_data' AND status='completed' AND currency='USD' AND amount<0 AND reference_note LIKE ? AND created_at>=DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-01')");$s->execute([$owner,'DataForSEO auto website:'.$websiteId.' %']);$limit=max(0,round($limit-(float)$s->fetchColumn(),2));}
            }
            $id=$this->db->transaction(function () use ($endpoint,$websiteId,$units,$ceiling,$owner,$pdo,&$spent,$automatic,$limit,$note) {
                for ($i=0; $i<$units; $i++) {
                    if (PlanUsage::consume((int)$owner,'seo_data',$note)) continue;
                    $s=$pdo->prepare("SELECT price,currency,is_active FROM pay_per_use_pricing WHERE feature_key='seo_data' FOR UPDATE");$s->execute();$price=$s->fetch(PDO::FETCH_ASSOC);
                    $amount=(float)($price['price']??0);
                    if (!$price || !(int)$price['is_active'] || $price['currency']!=='USD' || !is_finite($amount) || $amount<=0 || abs($amount-round($amount,2))>0.000001)
                        throw new RuntimeException('seo_wallet_price_unavailable');
                    if (round($spent+$amount,2)>round($limit,2)) throw new RuntimeException($automatic?'seo_auto_wallet_limit_reached':'seo_wallet_limit_reached');
                    if (!(new WalletService())->chargeAmount((int)$owner,$amount,'seo_data',$note)) throw new RuntimeException('seo_quota_and_wallet_exhausted');
                    $spent=round($spent+$amount,2);
                }
                $s=$pdo->prepare('INSERT INTO dataforseo_calls (endpoint,cost,reserved_cost,website_id,status,created_at) VALUES (?,0,?,?,\'pending\',UTC_TIMESTAMP())');
                $s->execute([$endpoint,$ceiling,$websiteId]);
                return (int)$pdo->lastInsertId();
            });
            $this->walletSpent=$automatic?round($this->walletSpent+$spent,2):$spent;
            return $id;
        } finally { $pdo->query("SELECT RELEASE_LOCK('tourfecto:dataforseo:budget')"); }
    }

    /**
     * مصروف على حساب المنصة (التحليل الذكي قبل التسجيل): مفيش موقع ولا محفظة عميل.
     * بيحترم سقف الشهر العام + سقف يومي خاص بيه عشان محدش يستنزف الميزانية.
     */
    public function reservePlatform(string $endpoint, float $ceiling, float $cap, float $dailyCap): int
    {
        $this->cap=$cap;
        SeoDataSchema::ensureSchema($this->db);
        $pdo=$this->db->getConnection();
        $lock=$pdo->query("SELECT GET_LOCK('tourfecto:dataforseo:budget',10)")->fetchColumn();
        if ((int)$lock!==1) throw new RuntimeException('seo_budget_busy');
        try {
            $this->reconcileStale();
            $spent=(float)$pdo->query("SELECT COALESCE(SUM(GREATEST(cost,reserved_cost)),0) FROM dataforseo_calls WHERE created_at >= DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-01')")->fetchColumn();
            if (!is_finite($cap) || $cap<=0 || round($spent+$ceiling,6)>round($cap,6)) { $this->alert(); throw new RuntimeException('seo_monthly_budget_reached'); }
            $today=(float)$pdo->query("SELECT COALESCE(SUM(GREATEST(cost,reserved_cost)),0) FROM dataforseo_calls WHERE website_id=0 AND created_at >= UTC_DATE()")->fetchColumn();
            if ($dailyCap<=0 || round($today+$ceiling,6)>round($dailyCap,6)) throw new RuntimeException('seo_signup_daily_cap_reached');
            $st=$pdo->prepare("INSERT INTO dataforseo_calls (endpoint,cost,reserved_cost,website_id,status,created_at) VALUES (?,0,?,0,'pending',UTC_TIMESTAMP())");
            $st->execute([$endpoint,$ceiling]);
            return (int)$pdo->lastInsertId();
        } finally { $pdo->query("SELECT RELEASE_LOCK('tourfecto:dataforseo:budget')"); }
    }

    /** سقف يومي اختياري (serp_daily_budget_usd) على كل صرف اليوم: بيمنع يوم واحد ياكل ميزانية الشهر. 0 أو فاضي = مفيش سقف. */
    private function assertDailyBudget(PDO $pdo,float $ceiling): void
    {
        try { $daily=(float)(new SystemSettingsService())->get('serp_daily_budget_usd',''); } catch (Throwable $e) { return; }
        if (!is_finite($daily) || $daily<=0) return;
        $today=(float)$pdo->query("SELECT COALESCE(SUM(GREATEST(cost,reserved_cost)),0) FROM dataforseo_calls WHERE created_at >= UTC_DATE()")->fetchColumn();
        if (round($today+$ceiling,6)>round($daily,6)) throw new RuntimeException('seo_daily_budget_reached');
    }

    public function settle(int $id, ?float $cost, string $status): void
    {
        // Unknown network outcome keeps the reservation: a timeout does not prove no charge.
        $s=$this->db->getConnection()->prepare('UPDATE dataforseo_calls SET cost=?, reserved_cost=IF(? IS NULL,reserved_cost,0), status=? WHERE id=?');
        $s->execute([$cost ?? 0,$cost,$status,$id]);
        if ($this->cap!==null && $this->cap>0) {
            $spent=(float)$this->db->getConnection()->query("SELECT COALESCE(SUM(GREATEST(cost,reserved_cost)),0) FROM dataforseo_calls WHERE created_at>=DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-01')")->fetchColumn();
            if (round($spent,6)>=round($this->cap,6)) $this->alert();
        }
    }

    private function alert(): void
    {
        $pdo=$this->db->getConnection();
        $s=$pdo->prepare('INSERT IGNORE INTO seo_provider_alerts (alert_key,created_at) VALUES (?,UTC_TIMESTAMP())');
        $s->execute(['budget:'.gmdate('Y-m')]);
        if (!$s->rowCount()) return;
        foreach ($this->db->query("SELECT id FROM users WHERE role IN ('admin','super_admin')") as $u)
            Notification::notify((int)$u['id'],'seo_budget','ميزانية DataForSEO خلصت','وقفنا الطلبات المدفوعة. راجع سقف الميزانية وسجل التكاليف.','/admin/settings');
    }
}
