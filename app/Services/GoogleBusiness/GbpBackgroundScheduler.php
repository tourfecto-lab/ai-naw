<?php
/** Durable six-hour scheduling claims plus pending-job deduplication. */
class GbpBackgroundScheduler
{
    public function __construct(private Database $db,private $queue=null) {}
    public function enqueueSite(int $uid,int $wid,?int $time=null): bool
    {
        $key='gbp-sync:'.$wid.':'.intdiv($time??time(),21600);$payload=['website_id'=>$wid,'user_id'=>$uid];
        return $this->db->transaction(function()use($uid,$wid,$key,$payload){
            $pdo=$this->db->getConnection();$s=$pdo->prepare('SELECT id FROM websites WHERE id=? AND user_id=? FOR UPDATE');$s->execute([$wid,$uid]);if(!$s->fetchColumn())return false;
            if ($this->db->query("SELECT id FROM jobs WHERE job_class='GbpBackgroundSyncJob' AND payload=? AND status IN ('pending','processing') LIMIT 1",[json_encode($payload)])) return false;
            $s=$pdo->prepare('INSERT IGNORE INTO seo_phase5_claims(claim_key,created_at) VALUES(?,UTC_TIMESTAMP())');$s->execute([$key]);if(!$s->rowCount())return false;
            $id=$this->queue?($this->queue)($payload):(new QueueManager($this->db))->push(GbpBackgroundSyncJob::class,$payload,'gbp_sync');
            if (!$id) throw new RuntimeException('GBP queue unavailable');return true;
        });
    }
    public function run(): array
    {
        $rows=$this->db->query("SELECT DISTINCT c.website_id,c.user_id FROM platform_connections c JOIN websites w ON w.id=c.website_id AND w.user_id=c.user_id WHERE c.platform='google_business' AND c.status='connected'");$out=['enqueued'=>0,'skipped'=>0];
        foreach($rows as $r) $out[$this->enqueueSite((int)$r['user_id'],(int)$r['website_id'])?'enqueued':'skipped']++;
        return $out;
    }
}
