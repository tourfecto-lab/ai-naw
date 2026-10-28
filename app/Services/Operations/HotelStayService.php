<?php
/** Room-night inventory and pricing; checkout is exclusive. */
class HotelStayService
{
    public static function dates(string $arrival,string $departure): array{
        if(!InventoryService::isValidDate($arrival)||!InventoryService::isValidDate($departure)||$departure<=$arrival)throw new InvalidArgumentException('تاريخ المغادرة يجب أن يكون بعد الوصول');
        $start=new DateTimeImmutable($arrival);$end=new DateTimeImmutable($departure);$n=(int)$start->diff($end)->days;if($n<1||$n>30)throw new InvalidArgumentException('الإقامة من ليلة إلى 30 ليلة');
        $days=[];for($i=0;$i<$n;$i++)$days[]=$start->modify('+'.$i.' days')->format('Y-m-d');return $days;
    }
    public static function quote(int $productId,float $basePrice,string $arrival,string $departure,int $rooms,bool $lock=false): array{
        if($rooms<1||$rooms>20)throw new InvalidArgumentException('عدد الغرف من 1 إلى 20');
        $pdo=Database::getInstance()->getConnection();$total=0;$rows=[];$days=self::dates($arrival,$departure);
        try{$q=$pdo->prepare('SELECT r.* FROM hotel_rate_rules r JOIN crm_products p ON p.id=r.product_id AND p.user_id=r.user_id WHERE r.product_id=? AND r.date_from<=? AND r.date_to>=? ORDER BY r.id');$q->execute([$productId,$days[count($days)-1],$arrival]);$rules=$q->fetchAll(PDO::FETCH_ASSOC);}catch(PDOException $e){if($e->getCode()!=='42S02')throw $e;$rules=[];}
        $breakdown=[];
        foreach($days as $day){$q=$pdo->prepare('SELECT id,date,capacity,booked_count,price_override,is_blocked FROM inventory WHERE product_id=? AND date=?'.($lock?' FOR UPDATE':''));$q->execute([$productId,$day]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row||$row['is_blocked']||(int)$row['capacity']-(int)$row['booked_count']<$rooms)throw new RuntimeException('الغرف غير متاحة في ليلة '.$day);
            $season=null;foreach($rules as $r)if($day>=$r['date_from']&&$day<=$r['date_to']){if($season)throw new RuntimeException('Overlapping room rate rules require review');$season=$r;}
            if($season && count($days)<(int)$season['min_nights'])throw new RuntimeException('Minimum stay for '.$season['name'].' is '.$season['min_nights'].' nights');
            $price=$row['price_override']!==null?(float)$row['price_override']:($season?(float)$season['nightly_price']:$basePrice);
            $breakdown[]=['date'=>$day,'nightly_price'=>$price,'rooms'=>$rooms,'rate_rule'=>$season['name']??null,'source'=>$row['price_override']!==null?'inventory_override':($season?'season_rule':'base_price')];if(!is_finite($price)||$price<0)throw new RuntimeException('سعر الليلة غير صالح');$total+=$price*$rooms;$rows[]=$row;
        }return ['total'=>round($total,2),'nights'=>count($rows),'rooms'=>$rooms,'inventory'=>$rows,'nightly_breakdown'=>$breakdown];
    }
}
