<?php
class BookingOperationsService
{
    private Database $db;
    public function __construct(?Database $db = null) { $this->db = $db ?: Database::getInstance(); }
    public function productSettings(int $ownerId, int $productId): array
    {
        $row = $this->db->query('SELECT * FROM booking_product_settings WHERE product_id = ? AND user_id = ?', [$productId, $ownerId])[0] ?? [];
        return $row + ['capacity_mode'=>'booking','child_price'=>0,'children_use_seat'=>1,'cancellation_hours'=>24,'refund_percent'=>0];
    }
    public static function units(array $settings, int $adults, int $children): int
    {
        return ($settings['capacity_mode'] ?? 'booking') === 'seat' ? $adults + (!empty($settings['children_use_seat']) ? $children : 0) : 1;
    }
    public static function baseTotal(float $adultPrice, array $settings, int $adults, int $children): float
    {
        $childPrice = (float) ($settings['child_price'] ?? 0);
        if (!is_finite($adultPrice) || !is_finite($childPrice) || min($adultPrice, $childPrice) < 0 || $adults < 1 || $children < 0) { throw new InvalidArgumentException('أسعار أو أعداد غير صالحة'); }
        return round($adultPrice * $adults + $childPrice * $children, 2);
    }
    public function saveProduct(int $ownerId, int $productId, array $input): array
    {
        $mode = $input['capacity_mode'] ?? 'booking'; $child = $input['child_price'] ?? 0;
        $hours = filter_var($input['cancellation_hours'] ?? 24, FILTER_VALIDATE_INT);
        $pct = $input['refund_percent'] ?? 0;
        if (!in_array($mode,['booking','seat'],true) || !is_numeric($child) || !is_finite((float)$child) || (float)$child < 0 || $hours === false || $hours < 0 || $hours > 8760 || !is_numeric($pct) || !is_finite((float)$pct) || (float)$pct < 0 || (float)$pct > 100) { throw new InvalidArgumentException('إعدادات الخدمة غير صالحة'); }
        $seats = filter_var($input['children_use_seat'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($seats === null) { throw new InvalidArgumentException('اختيار مقاعد الأطفال غير صالح'); }
        return $this->db->transaction(function() use($ownerId,$productId,$mode,$child,$hours,$pct,$seats) {
            if (!$this->db->query('SELECT id FROM crm_products WHERE id = ? AND user_id = ? FOR UPDATE',[$productId,$ownerId])) { throw new RuntimeException('الخدمة غير موجودة'); }
            $old = $this->productSettings($ownerId,$productId);
            if ($mode !== $old['capacity_mode'] || (int)$seats !== (int)$old['children_use_seat']) {
                $busy = $this->db->query("SELECT id FROM bookings WHERE product_id = ? AND status IN ('pending','confirmed') AND start_date >= CURDATE() LIMIT 1",[$productId]);
                if ($busy) { throw new RuntimeException('لا يمكن تغيير وحدة السعة مع حجوزات قائمة؛ أغلق أو أكمل الحجوزات أولًا ثم أعد ضبط السعة بالأماكن الفعلية'); }
                // No legacy counts are reinterpreted. The owner must explicitly set capacities in the new unit.
                $this->db->exec('UPDATE inventory SET capacity = 0, booked_count = 0, is_blocked = 1 WHERE product_id = ? AND date >= CURDATE()',[$productId]);
            }
            $this->db->exec('INSERT INTO booking_product_settings(product_id,user_id,capacity_mode,child_price,children_use_seat,cancellation_hours,refund_percent) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE capacity_mode=VALUES(capacity_mode),child_price=VALUES(child_price),children_use_seat=VALUES(children_use_seat),cancellation_hours=VALUES(cancellation_hours),refund_percent=VALUES(refund_percent)',[$productId,$ownerId,$mode,round((float)$child,2),(int)$seats,$hours,(float)$pct]);
            return $this->productSettings($ownerId,$productId);
        });
    }
    public function extras(int $ownerId,int $productId): array { return $this->db->query('SELECT id,name,price,price_mode,is_active FROM booking_product_extras WHERE user_id=? AND product_id=? ORDER BY id',[$ownerId,$productId]); }
    public function extraTotal(int $ownerId,int $productId,array $ids,int $people): array
    {
        foreach($ids as $id) { if(filter_var($id,FILTER_VALIDATE_INT) === false || (int)$id < 1)throw new InvalidArgumentException('إضافة غير صالحة'); }
        $ids=array_values(array_unique(array_map('intval',$ids)));
        if(count($ids)>20)throw new InvalidArgumentException('إضافات كثيرة');
        $selected=[];$total=0.0;
        foreach($this->extras($ownerId,$productId) as $extra) {
            if(in_array((int)$extra['id'],$ids,true)&&!empty($extra['is_active'])) {
                $line=round((float)$extra['price']*($extra['price_mode']==='person'?$people:1),2);
                $selected[]=['id'=>(int)$extra['id'],'name'=>$extra['name'],'amount'=>$line];$total+=$line;
            }
        }
        if(count($ids)!==count($selected))throw new InvalidArgumentException('إضافة غير متاحة');
        return ['total'=>round($total,2),'items'=>$selected];
    }
    public function saveExtra(int $ownerId,int $productId,array $input): void
    {
        $name=trim((string)($input['name']??''));$price=$input['price']??null;$mode=$input['price_mode']??'booking';
        if($name==='' || mb_strlen($name)>150 || !is_numeric($price) || !is_finite((float)$price) || (float)$price<0 || !in_array($mode,['booking','person'],true))throw new InvalidArgumentException('إضافة غير صالحة');
        $this->db->transaction(function()use($ownerId,$productId,$name,$price,$mode){
            if(!$this->db->query('SELECT id FROM crm_products WHERE id=? AND user_id=? FOR UPDATE',[$productId,$ownerId]))throw new RuntimeException('الخدمة غير موجودة');
            if(count($this->extras($ownerId,$productId))>=20)throw new RuntimeException('الحد الأقصى 20 إضافة لكل خدمة');
            $this->db->exec('INSERT INTO booking_product_extras(product_id,user_id,name,price,price_mode) VALUES(?,?,?,?,?)',[$productId,$ownerId,$name,round((float)$price,2),$mode]);
        });
    }
    public function disableExtra(int $ownerId,int $extraId): void { $this->db->exec('UPDATE booking_product_extras SET is_active=0 WHERE id=? AND user_id=?',[$extraId,$ownerId]); }
    public function timezone(int $ownerId): string
    {
        $tz=(string)($this->db->query('SELECT timezone FROM workspace_operations_settings WHERE user_id=?',[$ownerId])[0]['timezone']??'Africa/Cairo');
        return in_array($tz,DateTimeZone::listIdentifiers(),true)?$tz:'Africa/Cairo';
    }
    public function saveTimezone(int $ownerId,string $timezone): void
    {
        if(!in_array($timezone,DateTimeZone::listIdentifiers(),true))throw new InvalidArgumentException('منطقة زمنية غير صالحة');
        $this->db->exec('INSERT INTO workspace_operations_settings(user_id,timezone) VALUES(?,?) ON DUPLICATE KEY UPDATE timezone=VALUES(timezone)',[$ownerId,$timezone]);
    }
}
