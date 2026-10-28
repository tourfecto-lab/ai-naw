<?php
require_once __DIR__.'/SeoPlatformBookingService.php';
/** Reviewed catalog additions only. Imports never create bookings or payment evidence. */
final class SeoPlatformProductService
{
    public function __construct(private Database $db,private $fetch=null) {}

    private function site(int $uid,int $wid): array {
        $site=$this->db->query('SELECT * FROM websites WHERE id=? AND user_id=?',[$wid,$uid])[0]??null;
        if(!$site)throw new InvalidArgumentException('Website unavailable. / الموقع غير متاح.');
        return $site;
    }
    public static function sourceUrl(string $url,string $home): string {
        $url=trim($url);$p=parse_url($url);$base=parse_url(str_contains($home,'://')?$home:'https://'.$home);
        $host=static fn($h)=>preg_replace('/^www\./i','',strtolower((string)$h));
        if(strlen($url)>1800||!filter_var($url,FILTER_VALIDATE_URL)||!$p||($p['scheme']??'')!=='https'||!$base||$host($p['host']??'')!==$host($base['host']??'')||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])||($p['port']??443)!==443||preg_match('/[\x00-\x20\\\\]|%0[ad]/i',$url))
            throw new InvalidArgumentException('Use a public HTTPS tour page on the selected website. / أدخل صفحة رحلة HTTPS على الموقع المختار.');
        // Tracking parameters are not product identities; keep genuine routing parameters.
        $query=[];foreach(explode('&',$p['query']??'') as $part){if($part===''||preg_match('/^(utm_[^=]*|gclid|fbclid)=/i',$part))continue;$query[]=$part;}
        sort($query,SORT_STRING);
        return 'https://'.strtolower($p['host']).(($p['path']??'')?:'/').($query?'?'.implode('&',$query):'');
    }
    public static function price($value): string {
        if(!is_string($value)&&!is_int($value)&&!is_float($value))throw new InvalidArgumentException('Confirm the price. / أدخل السعر المؤكد.');
        $s=trim((string)$value);
        if(!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D',$s))throw new InvalidArgumentException('Use a nonnegative price with at most two decimal places. / أدخل سعرًا صحيحًا بحد أقصى منزلتين عشريتين.');
        [$whole,$fraction]=array_pad(explode('.',$s,2),2,'');
        return (ltrim($whole,'0')?:'0').'.'.str_pad($fraction,2,'0');
    }
    private static function text($value,int $limit): string {
        return is_scalar($value)?mb_substr(trim(preg_replace('/\s+/u',' ',html_entity_decode(strip_tags((string)$value),ENT_QUOTES|ENT_HTML5,'UTF-8'))),0,$limit):'';
    }
    public static function parse(string $html): array {
        if($html===''||strlen($html)>750000)throw new InvalidArgumentException('Page unavailable or too large. / الصفحة غير متاحة أو كبيرة جدًا.');
        $dom=new DOMDocument();$old=libxml_use_internal_errors(true);
        try{$dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}finally{libxml_clear_errors();libxml_use_internal_errors($old);}
        $xp=new DOMXPath($dom);$candidates=[];$seen=[];$visited=0;
        $walk=function($node)use(&$walk,&$candidates,&$seen,&$visited):void{
            if(!is_array($node)||++$visited>2000||count($candidates)>=20)return;
            $types=array_map(static fn($type)=>is_string($type)?preg_replace('~^https?://schema.org/~','',$type):'',(array)($node['@type']??[]));
            if(array_intersect($types,['Product','Service','TouristTrip'])){
                $name=self::text($node['name']??'',255);
                if($name!==''){
                    $offers=$node['offers']??[];if(is_array($offers)&&!array_is_list($offers))$offers=[$offers];
                    $price='';$currency='';
                    // Ranges, alternate prices and AggregateOffer lows are not a confirmed sale price.
                    if(is_array($offers)&&count($offers)===1&&is_array($offers[0])&&in_array($offers[0]['@type']??'Offer',['Offer','https://schema.org/Offer'],true)){
                        try{$price=self::price($offers[0]['price']??null);}catch(InvalidArgumentException $e){}
                        $code=$offers[0]['priceCurrency']??'';if(is_string($code)&&preg_match('/^[A-Z]{3}$/D',$code))$currency=$code;
                    }
                    $candidate=['name'=>$name,'description'=>self::text($node['description']??'',3000),'price'=>$price,'currency'=>$currency];
                    $key=hash('sha256',json_encode($candidate));if(!isset($seen[$key])){$seen[$key]=true;$candidates[]=$candidate;}
                }
            }
            foreach($node as $value)if(is_array($value))$walk($value);
        };
        foreach($xp->query('//script') as $script){if(strtolower(trim($script->getAttribute('type')))!=='application/ld+json')continue;$walk(json_decode($script->textContent,true,64));}
        $name=self::text($xp->query('//h1')->item(0)?->textContent??$xp->query('//title')->item(0)?->textContent??'',255);
        $description='';foreach($xp->query('//meta') as $meta)if(strtolower($meta->getAttribute('name'))==='description'){$description=self::text($meta->getAttribute('content'),3000);break;}
        $blank=['name'=>$name,'description'=>$description,'price'=>'','currency'=>''];
        return ['draft'=>count($candidates)===1?$candidates[0]:(count($candidates)>1?['name'=>'','description'=>'','price'=>'','currency'=>'']:$blank),'candidates'=>$candidates,'requires_review'=>true];
    }
    public function preview(int $uid,int $wid,string $url): array {
        $site=$this->site($uid,$wid);$url=self::sourceUrl($url,(string)($site[Website::urlColumn()]??$site['main_url']??''));
        if($this->fetch)$html=($this->fetch)($url);
        else{
            $html='';$r=SafeHttp::exec($url,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'TourfectoCatalogPreview/1.0',CURLOPT_WRITEFUNCTION=>static function($ch,$chunk)use(&$html){if(strlen($html)+strlen($chunk)>750000)return 0;$html.=$chunk;return strlen($chunk);}],0);
            if($r['error']!==''||$r['code']!==200)throw new InvalidArgumentException('Cannot read this page. Use its final public URL or enter the product manually. / تعذرت قراءة الصفحة؛ استخدم رابطها النهائي أو أضف الرحلة يدويًا.');
        }
        if(!is_string($html))throw new InvalidArgumentException('Page unavailable. / الصفحة غير متاحة.');
        return self::parse($html)+['source_url'=>$url];
    }
    public function create(int $uid,int $wid,array $input): array {
        $site=$this->site($uid,$wid);
        if(($input['confirmed']??false)!==true)throw new InvalidArgumentException('Review the name, price, currency and selected website before saving. / راجع الاسم والسعر والعملة والموقع وأكدهم قبل الحفظ.');
        $name=$input['name']??null;$description=$input['description']??'';$currency=$input['currency']??null;$nonce=$input['request_key']??'';
        if(!is_string($name)||trim($name)===''||mb_strlen($name)>255||!is_string($description)||mb_strlen($description)>3000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$name.$description))throw new InvalidArgumentException('Enter a product name (255 characters maximum) and description (3000 maximum). / راجع اسم الرحلة ووصفها.');
        $price=self::price($input['price']??null);
        if(!is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency))throw new InvalidArgumentException('Confirm the three-letter currency code. / أكد رمز العملة المكوّن من ثلاثة أحرف مثل USD.');
        if(!is_string($nonce)||!preg_match('/^[a-f0-9]{32}$/D',$nonce))throw new InvalidArgumentException('Reload the product form. / أعد فتح نموذج الرحلة.');
        $source=$input['source_url']??'';if(!is_string($source))throw new InvalidArgumentException('Invalid source URL.');
        if($source!=='')$source=self::sourceUrl($source,(string)($site[Website::urlColumn()]??$site['main_url']??''));
        // SKU is internal provenance/idempotency, not a Builder website foreign key.
        // One source URL represents one tour; repeated imports never overwrite existing facts.
        $sku='tfseo-'.$wid.'-'.hash('sha256',$source!==''?'url:'.preg_replace('~^https://www\.~','https://',$source):'manual:'.$nonce);
        if(!(new SeoPlatformBookingService($this->db))->ready())throw new InvalidArgumentException('Platform booking measurement is unavailable.');
        return $this->db->transaction(function()use($uid,$wid,$name,$description,$price,$currency,$source,$sku){
            $this->db->query('SELECT id FROM websites WHERE user_id=? ORDER BY id FOR UPDATE',[$uid]);
            $product=$this->db->query('SELECT id,name,price,currency FROM crm_products WHERE user_id=? AND sku=? FOR UPDATE',[$uid,$sku])[0]??null;
            $created=!$product;
            if(!$product){
                $id=(int)$this->db->query('INSERT INTO crm_products(user_id,name,description,sku,price,currency,is_active,created_at,updated_at) VALUES(?,?,?,?,?,?,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())',[$uid,trim($name),trim($description),$sku,$price,$currency]);
                $product=['id'=>$id,'name'=>trim($name),'price'=>$price,'currency'=>$currency];
            }
            $id=(int)$product['id'];$map=$this->db->query('SELECT website_id,user_id FROM seo_platform_booking_products WHERE product_id=? FOR UPDATE',[$id])[0]??null;
            if($map&&((int)$map['website_id']!==$wid||(int)$map['user_id']!==$uid))throw new InvalidArgumentException('This product is assigned to another website. / الرحلة مرتبطة بموقع آخر.');
            if(!$map){
                $count=(int)($this->db->query('SELECT COUNT(*) n FROM seo_platform_booking_products WHERE user_id=? AND website_id=?',[$uid,$wid])[0]['n']??0);
                if($count>=500)throw new InvalidArgumentException('Maximum 500 products per measurement website. / الحد الأقصى 500 رحلة مرتبطة بالموقع.');
                $this->db->query('INSERT INTO seo_platform_booking_products(product_id,website_id,user_id,confirmed_at) VALUES(?,?,?,UTC_TIMESTAMP())',[$id,$wid,$uid]);
            }
            return ['created'=>$created,'product'=>['id'=>$id,'name'=>$product['name'],'price'=>$product['price'],'currency'=>$product['currency']],'source_url'=>$source];
        });
    }
}
