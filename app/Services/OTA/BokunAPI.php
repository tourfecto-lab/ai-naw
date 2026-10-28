<?php
final class BokunAPI
{
    private string $base;private $transport;
    public function __construct(private string $accessKey,private string $secretKey,private int $vendorId,string $environment='production',?callable $transport=null)
    {if(!in_array($environment,['production','sandbox'],true)||$vendorId<1)throw new InvalidArgumentException('Bókun requires a numeric vendor ID and a valid environment');$this->base=$environment==='sandbox'?'https://api.bokuntest.com':'https://api.bokun.io';$this->transport=$transport;}
    public static function signature(string $date,string $key,string $secret,string $method,string $path): string
    {return base64_encode(hash_hmac('sha1',$date.$key.strtoupper($method).$path,$secret,true));}
    private function request(string $method,string $path,?array $body=null): array
    {$date=gmdate('Y-m-d H:i:s');return OtaSupplierHttp::request($method,$this->base.$path,['Accept: application/json','Content-Type: application/json','X-Bokun-Date: '.$date,'X-Bokun-AccessKey: '.$this->accessKey,'X-Bokun-Signature: '.self::signature($date,$this->accessKey,$this->secretKey,$method,$path)],$body===null?null:json_encode($body,JSON_THROW_ON_ERROR),$this->transport);}
    public function products(int $page=1): array
    {if($page<1)throw new InvalidArgumentException('Invalid page');return $this->request('POST','/activity.json/search?lang=EN&currency=USD',['vendorId'=>$this->vendorId,'page'=>$page,'pageSize'=>50]);}
    public function bookings(int $page,string $from,string $to): array
    {return $this->request('POST','/booking.json/product-booking-search',['bookingRole'=>'SUPPLIER','page'=>$page,'pageSize'=>50,'startDateRange'=>['from'=>$from.'T00:00:00Z','to'=>$to.'T23:59:59Z','includeLower'=>true,'includeUpper'=>true]]);}
    public function availability(string $id,string $from,string $to): array
    {if(!ctype_digit($id))throw new InvalidArgumentException('Invalid Bókun activity ID');return $this->request('GET','/activity.json/'.$id.'/availabilities?'.http_build_query(['start'=>$from,'end'=>$to,'lang'=>'EN']));}
    public function verifyToken(): array
    {$r=$this->products();if($r['success']&&!isset($r['data']['items']))return ['success'=>false,'error'=>'Unexpected Bókun product response','http_code'=>$r['http_code'],'data'=>null];return $r;}
}
