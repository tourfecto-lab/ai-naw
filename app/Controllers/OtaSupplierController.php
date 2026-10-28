<?php
final class OtaSupplierController extends Controller
{
    private function uid(): int {return (int)($this->user['id']??0);}
    private function service(): OtaSupplierService {return new OtaSupplierService();}
    public function catalog(array $params=[]): array
    {
        if(!$this->uid())return $this->error('Unauthorized',401);
        $pdo=Database::getInstance()->getConnection();$s=$pdo->prepare('SELECT id,domain,company_name FROM websites WHERE user_id=? ORDER BY id');$s->execute([$this->uid()]);$sites=$s->fetchAll(PDO::FETCH_ASSOC);
        $s=$pdo->prepare('SELECT id,name,currency FROM crm_products WHERE user_id=? AND is_active=1 ORDER BY name LIMIT 1000');$s->execute([$this->uid()]);
        return $this->success(['providers'=>OtaProviderCatalog::all(),'websites'=>$sites,'products'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
    }
    public function handleAction(array $params=[]): array
    {
        if(!$this->uid())return $this->error('Unauthorized',401);
        $action=(string)($params['action']??'status');
        $read=in_array($action,['status','records','mappings'],true);
        if(($_SERVER['REQUEST_METHOD']??'GET')!==($read?'GET':'POST'))return $this->error('Method not allowed',405);
        if(!in_array($action,['status','records','mappings'],true)){if(!is_string($this->get('csrf_token'))||!Csrf::verify($this->get('csrf_token')))return $this->error('Session expired. Refresh the page and try again.',419);}
        try{
            $s=$this->service();$uid=$this->uid();$wid=(int)$this->get('website_id');$p=(string)$this->get('platform');
            $meta=OtaProviderCatalog::get($p);if($meta['mode']==='legacy_partner')throw new InvalidArgumentException('Use the partner connection endpoint');
            $data=match($action){
                'status'=>$s->status($uid,$wid,$p),
                'save'=>$s->save($uid,$wid,$p,$this->all()),
                'sync'=>(function()use($s,$uid,$wid,$p){$s->queue($uid,$wid,$p);return ['queued'=>true];})(),
                'automatic'=>$s->toggle($uid,$wid,$p,filter_var($this->get('enabled',false),FILTER_VALIDATE_BOOLEAN)),
                'disconnect'=>(function()use($s,$uid,$wid,$p){$s->disconnect($uid,$wid,$p);return ['disconnected'=>true];})(),
                'records'=>['records'=>$s->records($uid,$wid,$p,(string)$this->get('kind','product'),(int)$this->get('page',1))],
                'map'=>(function()use($s,$uid,$wid,$p){$s->map($uid,$wid,$p,(string)$this->get('external_id'),(int)$this->get('product_id'));return ['saved'=>true];})(),
                'mappings'=>['mappings'=>$s->mappings($uid,$wid,$p)],
                'availability'=>$s->availability($uid,$wid,$p,(string)$this->get('external_id'),(string)$this->get('from'),(string)$this->get('to')),
                default=>throw new InvalidArgumentException('Unsupported OTA action'),
            };return $this->success($data);
        }catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}catch(PDOException $e){return $this->error('Unable to access OTA storage. Check the SQL installation.',500);}catch(RuntimeException $e){return $this->error($e->getMessage(),409);}catch(Throwable $e){return $this->error('Unable to complete the OTA operation. Check installation and retry.',500);}
    }
    /** Retired standalone UI. Existing bookmarks return to the unified integrations page. */
    public function page(array $params=[]): array
    {
        header('Cache-Control: no-store');
        header('Location: /integrations',true,302);
        exit;
    }

    private function integrationsRedirect(string $message): void
    {
        $_SESSION['ota_connection_notice']=$message;header('Cache-Control: no-store');header('Location: /integrations',true,303);exit;
    }
    public function customerConnect(array $params=[]): array
    {
        if(!$this->uid())return $this->error('Unauthorized',401);
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST')return $this->error('Method not allowed',405);
        if(!is_string($this->get('csrf_token'))||!Csrf::verify($this->get('csrf_token')))return $this->error('Refresh the page and try again.',419);
        try{
            $wid=(int)$this->get('website_id');$platform=(string)($params['platform']??'');$action=(string)$this->get('operation','connect');
            if($platform==='bokun'){
                if($action==='disconnect'){OtaIntegrationAdmin::disconnectBokun($this->uid(),$wid);$this->integrationsRedirect('Bókun disconnected locally. Remove the app from your Bókun account to revoke provider access.');}
                elseif($action==='connect'){$url=OtaIntegrationAdmin::beginBokun($this->uid(),$wid,(string)$this->get('domain'));header('Cache-Control: no-store');header('Location: '.$url,true,303);exit;}
                else throw new InvalidArgumentException('Unsupported operation');
            }elseif($platform==='rezdy'){
                if($action==='disconnect')$this->service()->disconnect($this->uid(),$wid,$platform);
                elseif($action==='connect')$this->service()->save($this->uid(),$wid,$platform,['environment'=>'production','account_id'=>'','api_key'=>$this->get('api_key')]);
                else throw new InvalidArgumentException('Unsupported operation');
                $this->integrationsRedirect($action==='disconnect'?'Rezdy disconnected.':'Rezdy account verified for read-only access.');
            }else throw new InvalidArgumentException('This connection is not available yet.');
        }catch(InvalidArgumentException $e){$this->integrationsRedirect($e->getMessage());}catch(Throwable $e){$this->integrationsRedirect('Unable to connect. Please try again or contact support.');}
        return [];
    }
    public function bokunCallback(array $params=[]): array
    {
        if(!$this->uid())return $this->error('Unauthorized',401);
        try{OtaIntegrationAdmin::finishBokun($this->uid(),$_GET);$this->integrationsRedirect('Bókun connected. Data synchronization is not available yet.');}
        catch(InvalidArgumentException $e){$this->integrationsRedirect($e->getMessage());}catch(Throwable $e){$this->integrationsRedirect('Bókun authorization failed. Connect again.');}
        return [];
    }

}
