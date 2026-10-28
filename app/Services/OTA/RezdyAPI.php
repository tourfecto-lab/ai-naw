<?php
final class RezdyAPI
{
    private string $base;private $transport;
    public function __construct(private string $apiKey,string $environment='production',?callable $transport=null)
    {if(!in_array($environment,['production','sandbox'],true))throw new InvalidArgumentException('Invalid environment');$this->base=$environment==='sandbox'?'https://api.rezdy-staging.com/v1':'https://api.rezdy.com/v1';$this->transport=$transport;}
    private function request(string $path,array $query=[]): array
    {$r=OtaSupplierHttp::request('GET',$this->base.$path.($query?'?'.http_build_query($query):''),['Accept: application/json','Content-Type: application/json; charset=utf-8','apiKey: '.$this->apiKey],null,$this->transport);if($r['success']&&($r['data']['requestStatus']['success']??null)!==true)return ['success'=>false,'error'=>'Rezdy rejected the request','data'=>null,'http_code'=>$r['http_code']];return $r;}
    public function products(int $page=1): array
    {if($page<1)throw new InvalidArgumentException('Invalid page');return $this->request('/products',['limit'=>50,'offset'=>($page-1)*50]);}
    public function bookings(int $page,string $from,string $to): array
    {return $this->request('/bookings',['limit'=>50,'offset'=>($page-1)*50,'minDateCreated'=>'2000-01-01T00:00:00Z','minTourStartTime'=>$from.'T00:00:00Z','maxTourStartTime'=>$to.'T23:59:59Z']);}
    public function availability(string $id,string $from,string $to): array
    {if(!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$id))throw new InvalidArgumentException('Invalid Rezdy product code');return $this->request('/availability',['productCode'=>$id,'startTime'=>$from.'T00:00:00Z','endTime'=>$to.'T23:59:59Z']);}
    public function verifyToken(): array
    {$r=$this->products();if($r['success']&&!isset($r['data']['products']))return ['success'=>false,'error'=>'Unexpected Rezdy product response','http_code'=>$r['http_code'],'data'=>null];return $r;}
}
