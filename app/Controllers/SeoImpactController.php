<?php
require_once __DIR__.'/../Services/SeoImpact/SeoImpactService.php';
require_once __DIR__.'/../Services/SeoImpact/SeoPlatformProductService.php';
final class SeoImpactController extends Controller {
    private function action(string $op,array $p): array {
        header('Cache-Control: no-store, private');if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        if(!in_array($op,['overview','platform_products'],true)){if($e=$this->verifyCsrf())return $e;if($e=$this->rateLimitGuard('user','seo_impact_write',10,60))return $e;}
        $uid=(int)$this->user['id'];$wid=(int)($p['website_id']??0);$s=new SeoImpactService();
        try {
            if($op==='overview')return $this->success($s->overview($uid,$wid));
            if($op==='platform_products')return $this->success((new SeoPlatformBookingService($this->db))->products($uid,$wid,(string)$this->get('q',''),(int)$this->get('cursor',0)));
            if($op==='platform_product_preview'){
                $url=$this->data['source_url']??null;if(!is_string($url))throw new InvalidArgumentException('Enter a product page URL. / أدخل رابط صفحة الرحلة.');
                return $this->success((new SeoPlatformProductService($this->db))->preview($uid,$wid,$url));
            }
            if($op==='platform_product_create'){
                if(!(new CrmPermissionService())->can($uid,'create'))return $this->error('Product creation is not permitted. / ليس لديك صلاحية إضافة رحلة.',403);
                $result=(new SeoPlatformProductService($this->db))->create($uid,$wid,$this->data);
                return $this->success($result+['overview'=>$s->overview($uid,$wid)]);
            }
            if($op==='platform_bookings'){
                $ids=$this->data['product_ids']??null;if(!is_array($ids))throw new InvalidArgumentException('Choose the products belonging to this website.');
                (new SeoPlatformBookingService($this->db))->configure($uid,$wid,$ids,($this->data['confirmed']??false)===true);
                return $this->success($s->overview($uid,$wid));
            }
            if($op==='create')return $this->success($s->create($uid,$wid,$this->data));
            if($op==='refresh')return $this->success($s->refresh($uid,(int)($p['id']??0)));
            if($op==='ai')return $this->success($s->importAi($uid,$wid,$this->data));
            if($op==='bookings')return $this->success($s->configureBookings($uid,$wid,($this->data['enabled']??false)===true));
            return $this->error('Unsupported operation',422);
        }catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}
        catch(Throwable $e){Logger::warning('SEO measurement needs review',['user_id'=>$uid,'website_id'=>$wid,'operation'=>$op]);return $this->error('Measurement is temporarily unavailable. Your saved work is retained; retry or contact support. / القياس غير متاح مؤقتًا. عملك محفوظ؛ أعد المحاولة أو تواصل مع الدعم.',503);}
    }
    public function overview(array $p=[]): array{return $this->action('overview',$p);}
    public function create(array $p=[]): array{return $this->action('create',$p);}
    public function refresh(array $p=[]): array{return $this->action('refresh',$p);}
    public function ai(array $p=[]): array{return $this->action('ai',$p);}
    public function bookings(array $p=[]): array{return $this->action('bookings',$p);}
    public function platformProducts(array $p=[]): array{return $this->action('platform_products',$p);}
    public function platformBookings(array $p=[]): array{return $this->action('platform_bookings',$p);}
    public function platformProductPreview(array $p=[]): array{return $this->action('platform_product_preview',$p);}
    public function platformProductCreate(array $p=[]): array{return $this->action('platform_product_create',$p);}
    public function ingest(array $p=[]): array {
        header('Cache-Control: no-store');if($e=$this->rateLimitGuard('ip','seo_paid_webhook',60,60))return $e;
        try{(new SeoImpactService())->ingest((int)($p['website_id']??0),(string)($_SERVER['HTTP_X_TOURFECTO_SECRET']??''),$this->data);return $this->success(['received'=>true]);}
        catch(InvalidArgumentException $e){return $this->error('Booking source or payload rejected.',403);}
        catch(Throwable $e){return $this->error('Receipt could not be confirmed. Retry the same booking key later.',503);}
    }
}
