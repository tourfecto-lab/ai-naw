<?php
class TicketService
{
    private Database $db;
    public function __construct(?Database $db=null) { $this->db=$db ?: Database::getInstance(); }
    public function issue(int $ownerId,int $bookingId): array
    {
        return $this->db->transaction(function()use($ownerId,$bookingId){
            $row=$this->db->query('SELECT * FROM bookings WHERE id=? AND user_id=? FOR UPDATE',[$bookingId,$ownerId])[0]??null;
            if(!$row || !in_array($row['status'],['confirmed','completed'],true)) throw new RuntimeException('التذكرة متاحة للحجز المؤكد فقط');
            $token=$row['ticket_token']??null;
            if(!$token){$token=bin2hex(random_bytes(16));$this->db->exec('UPDATE bookings SET ticket_token=? WHERE id=?',[$token,$bookingId]);}
            $base=rtrim((string)(defined('APP_URL')?APP_URL:(getenv('APP_URL')?:'https://tourfecto.pro')),'/');
            if(!preg_match('~^https?://[^\s]+$~D',$base))throw new RuntimeException('رابط المنصة غير صالح');
            $url=$base.'/ticket/'.$token;
            return ['url'=>$url,'qr_svg'=>QrCodeService::svg($url),'reference'=>$row['booking_reference']];
        });
    }
    public function publicTicket(string $token): ?array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$token))return null;
        $row=$this->db->query('SELECT b.id,b.booking_reference,b.start_date,b.start_time,b.adults_count,b.children_count,b.status,b.checked_in_at,p.name AS product_name,p.website_id FROM bookings b JOIN crm_products p ON p.id=b.product_id AND p.user_id=b.user_id WHERE b.ticket_token=? LIMIT 1',[$token])[0]??null;
        if($row){$row['language']=ContentLanguageService::forWebsite((int)$row['website_id']);unset($row['website_id']);}return $row;
    }
    public function checkIn(int $ownerId,int $bookingId): array
    {
        return $this->db->transaction(function()use($ownerId,$bookingId){
            $row=$this->db->query('SELECT id,status,checked_in_at FROM bookings WHERE id=? AND user_id=? FOR UPDATE',[$bookingId,$ownerId])[0]??null;
            if(!$row || $row['status']!=='confirmed')throw new RuntimeException('لا يمكن تسجيل حضور هذا الحجز');
            if($row['checked_in_at'])return ['already_checked_in'=>true,'checked_in_at'=>$row['checked_in_at']];
            $this->db->exec('UPDATE bookings SET checked_in_at=NOW() WHERE id=?',[$bookingId]);
            return ['already_checked_in'=>false,'checked_in_at'=>$this->db->query('SELECT checked_in_at FROM bookings WHERE id=?',[$bookingId])[0]['checked_in_at']];
        });
    }
}
