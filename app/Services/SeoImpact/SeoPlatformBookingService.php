<?php
/** Read-only native booking cohorts; payment statuses, never manual booking confirmation, establish receipts. */
final class SeoPlatformBookingService
{
    public function __construct(private Database $db) {}
    private function site(int $uid,int $wid): void {
        if(!$this->db->query('SELECT id FROM websites WHERE id=? AND user_id=?',[$wid,$uid]))throw new InvalidArgumentException('Website unavailable.');
    }
    public function ready(): bool {return $this->missingRequirements()===[];}
    private function missingRequirements(): array {
        $required=['seo_platform_booking_products'=>['product_id','website_id','user_id','confirmed_at'],'crm_products'=>['id','user_id','name','currency','is_active'],'bookings'=>['id','user_id','product_id','status','created_at'],'payment_transactions'=>['id','user_id','booking_id','amount','currency','status','gateway'],'refunds'=>['payment_transaction_id','user_id','amount','currency','status']];
        $rows=$this->db->query("SELECT TABLE_NAME AS tf_table,COLUMN_NAME AS tf_column FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name IN ('seo_platform_booking_products','crm_products','bookings','payment_transactions','refunds')");
        // MySQL and MariaDB can expose unaliased information_schema labels with
        // different case; PDO ATTR_CASE can also uppercase explicit aliases.
        $found=[];foreach($rows as $r){
            $r=array_change_key_case($r,CASE_LOWER);
            $table=$r['tf_table']??$r['table_name']??null;$column=$r['tf_column']??$r['column_name']??null;
            if(is_string($table)&&is_string($column))$found[$table][]=$column;
        }
        $missing=[];foreach($required as $table=>$cols)foreach(array_diff($cols,$found[$table]??[]) as $column)$missing[]=$table.'.'.$column;
        return $missing;
    }
    public static function periods(?string $today=null): array {
        $end=SeoImpactRules::date($today??gmdate('Y-m-d'))->modify('-1 day');$start=$end->modify('-27 days');
        return ['current'=>['start'=>$start->format('Y-m-d'),'end'=>$end->format('Y-m-d')],'previous'=>['start'=>$start->modify('-28 days')->format('Y-m-d'),'end'=>$start->modify('-1 day')->format('Y-m-d')]];
    }
    public function overview(int $uid,int $wid,?string $today=null): array {
        $this->site($uid,$wid);$missing=$this->missingRequirements();if($missing)return ['available'=>false,'state'=>'unavailable','missing_requirements'=>$missing];
        $count=(int)($this->db->query('SELECT COUNT(*) n FROM seo_platform_booking_products m JOIN crm_products p ON p.id=m.product_id AND p.user_id=m.user_id WHERE m.user_id=? AND m.website_id=?',[$uid,$wid])[0]['n']??0);
        $out=['available'=>true,'state'=>$count?'configured':'not_configured','mapped_products'=>$count,'periods'=>self::periods($today),'cohort_basis'=>'booking_created_at_utc','valuation'=>'current_captured_payments_net_of_succeeded_refunds','page_attribution'=>'not_inferred','traffic_channel'=>'not_inferred'];
        if(!$count)return $out;
        foreach($out['periods'] as $key=>$period)$out[$key]=$this->period($uid,$wid,$period['start'],$period['end']);
        return $out;
    }
    public function products(int $uid,int $wid,string $search='',int $cursor=0): array {
        $this->site($uid,$wid);if(!$this->ready())throw new InvalidArgumentException('Platform booking measurement is not available yet.');
        if(mb_strlen($search)>120||$cursor<0)throw new InvalidArgumentException('Invalid product search.');
        $params=[$uid,$cursor];$where='';if($search!==''){$where=' AND p.name LIKE ?';$params[]='%'.strtr($search,['\\'=>'\\\\','%'=>'\\%','_'=>'\\_']).'%';}
        $rows=$this->db->query('SELECT p.id,p.name,p.currency,p.is_active,m.website_id mapped_website_id FROM crm_products p LEFT JOIN seo_platform_booking_products m ON m.product_id=p.id AND m.user_id=p.user_id WHERE p.user_id=? AND p.id>?'.$where.' ORDER BY p.id LIMIT 101',$params);
        $more=count($rows)>100;$rows=array_slice($rows,0,100);
        $selected=$this->db->query('SELECT m.product_id,p.name FROM seo_platform_booking_products m JOIN crm_products p ON p.id=m.product_id AND p.user_id=m.user_id WHERE m.user_id=? AND m.website_id=? ORDER BY p.id',[$uid,$wid]);
        return ['products'=>array_map(static fn($p)=>['id'=>(int)$p['id'],'name'=>$p['name'],'currency'=>$p['currency'],'active'=>(bool)$p['is_active'],'selected'=>(int)($p['mapped_website_id']??0)===$wid,'bound_elsewhere'=>!empty($p['mapped_website_id'])&&(int)$p['mapped_website_id']!==$wid],$rows),'selected'=>$selected,'next_cursor'=>$more?(int)end($rows)['id']:null];
    }
    public function configure(int $uid,int $wid,array $ids,bool $confirmed): array {
        $this->site($uid,$wid);if(!$this->ready())throw new InvalidArgumentException('Platform booking measurement is not available yet.');
        if(!$confirmed||count($ids)>500)throw new InvalidArgumentException('Review and confirm the products belonging to this website.');
        foreach($ids as $id)if(!is_int($id)||$id<1)throw new InvalidArgumentException('Invalid product selection.');$ids=array_values(array_unique($ids));sort($ids);
        $this->db->transaction(function()use($uid,$wid,$ids){
            // Serialize a customer's site selections to prevent concurrent cross-site moves.
            $this->db->query('SELECT id FROM websites WHERE user_id=? ORDER BY id FOR UPDATE',[$uid]);
            foreach($ids as $id){
                if(!$this->db->query('SELECT id FROM crm_products WHERE id=? AND user_id=? FOR UPDATE',[$id,$uid]))throw new InvalidArgumentException('A selected product is not owned by this account.');
                $map=$this->db->query('SELECT website_id,user_id FROM seo_platform_booking_products WHERE product_id=? FOR UPDATE',[$id])[0]??null;
                if($map&&((int)$map['website_id']!==$wid||(int)$map['user_id']!==$uid))throw new InvalidArgumentException('A selected product is already assigned to another website. Remove its previous assignment first.');
            }
            // Remove this site's selection only; no product, booking or payment is changed.
            $this->db->query('DELETE FROM seo_platform_booking_products WHERE user_id=? AND website_id=?',[$uid,$wid]);
            foreach($ids as $id)$this->db->query('INSERT INTO seo_platform_booking_products(product_id,website_id,user_id,confirmed_at) VALUES(?,?,?,UTC_TIMESTAMP())',[$id,$wid,$uid]);
        });
        return $this->overview($uid,$wid);
    }
    private function period(int $uid,int $wid,string $start,string $end): array {
        // A booking may have several captured payments and refunds. Aggregate refunds per
        // transaction first, then aggregate payments per booking/currency; never multiply rows.
        $rows=$this->db->query("SELECT b.id booking_id,b.status booking_status,t.id transaction_id,t.gateway,t.status payment_status,t.currency,t.amount,
            COALESCE(SUM(CASE WHEN r.status='succeeded' THEN r.amount ELSE 0 END),0) succeeded_refunds,
            COALESCE(SUM(CASE WHEN r.status IN ('pending','processing') THEN r.amount ELSE 0 END),0) pending_refunds
            FROM seo_platform_booking_products m
            JOIN crm_products p ON p.id=m.product_id AND p.user_id=m.user_id
            JOIN bookings b ON b.product_id=p.id AND b.user_id=m.user_id
            JOIN payment_transactions t ON t.booking_id=b.id AND t.user_id=b.user_id
            LEFT JOIN refunds r ON r.payment_transaction_id=t.id AND r.user_id=t.user_id AND r.currency=t.currency
            WHERE m.user_id=? AND m.website_id=? AND b.created_at>=? AND b.created_at<?
              AND t.status IN ('succeeded','partially_refunded','refunded') AND t.amount>0
              AND t.gateway IN ('stripe','stripe_operator','paymob','wallet')
            GROUP BY b.id,b.status,t.id,t.gateway,t.status,t.currency,t.amount ORDER BY b.id,t.id",[$uid,$wid,$start.' 00:00:00',SeoImpactRules::date($end)->modify('+1 day')->format('Y-m-d').' 00:00:00']);
        $currencies=[];$bookings=[];$cancelled=[];$mismatches=0;
        foreach($rows as $r){
            $code=(string)$r['currency'];if(!preg_match('/^[A-Z]{3}$/D',$code)){$mismatches++;continue;}
            $gross=(int)round((float)$r['amount']*100);$refund=(int)round((float)$r['succeeded_refunds']*100);$pending=(int)round((float)$r['pending_refunds']*100);
            // Inconsistent ledger states are visible rather than invented refund amounts.
            if($refund<0||$pending<0||$refund>$gross||($r['payment_status']==='refunded'&&$refund!==$gross)||($r['payment_status']==='partially_refunded'&&($refund<=0||$refund>=$gross))){$mismatches++;continue;}
            $c=&$currencies[$code];if(!$c)$c=['currency'=>$code,'booking_ids'=>[],'gross_minor'=>0,'refund_minor'=>0,'pending_minor'=>0,'net_minor'=>0];
            $c['booking_ids'][(int)$r['booking_id']]=true;$bookings[(int)$r['booking_id']]=true;if($r['booking_status']==='cancelled')$cancelled[(int)$r['booking_id']]=true;
            $c['gross_minor']+=$gross;$c['refund_minor']+=$refund;$c['pending_minor']+=$pending;$c['net_minor']+=$gross-$refund;unset($c);
        }
        ksort($currencies);$out=[];foreach($currencies as $c)$out[]=['currency'=>$c['currency'],'paid_bookings'=>count($c['booking_ids']),'gross_captured'=>number_format($c['gross_minor']/100,2,'.',''),'succeeded_refunds'=>number_format($c['refund_minor']/100,2,'.',''),'pending_refunds'=>number_format($c['pending_minor']/100,2,'.',''),'net_captured'=>number_format($c['net_minor']/100,2,'.','')];
        return ['state'=>$mismatches?'needs_review':'reported','paid_bookings'=>count($bookings),'cancelled_bookings_with_payments'=>count($cancelled),'currencies'=>$out,'excluded_inconsistent_payments'=>$mismatches];
    }
}
