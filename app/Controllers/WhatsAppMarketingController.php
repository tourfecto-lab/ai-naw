<?php
final class WhatsAppMarketingController extends Controller
{
    private function actor(): int{return (int)($this->user['id']??0);}
    private function uid(): int{return $this->actor()?(new CrmPermissionService())->resolveTenantId($this->actor()):0;}
    public function handleAction(array $params=[]): array
    {
        if(!$this->uid())return $this->error('Unauthorized',401);$action=(string)($params['action']??'overview');$read=in_array($action,['overview','contacts','templates','campaign','recipients','preview','export'],true);
        if(($_SERVER['REQUEST_METHOD']??'GET')!==($read?'GET':'POST'))return $this->error('Method not allowed',405);
        if(!$read&&(!is_string($this->get('csrf_token'))||!Csrf::verify($this->get('csrf_token'))))return $this->error('Session expired. Refresh the page.',419);
        if(!$read&&($limited=$this->rateLimitGuard('user','whatsapp_marketing',30,60)))return $this->error('Too many requests. Wait a minute and try again.',429);
        $permission=($action==='export')?'export':($read?'view':(in_array($action,['launch','pause','resume','cancel','retry','create-template','sync-templates'],true)?'manage_settings':(in_array($action,['edit-campaign','tag'],true)?'edit':'create')));
        if(!(new CrmPermissionService())->can($this->actor(),$permission))return $this->error('Your team role cannot perform this campaign action.',403);
        try{$s=new WhatsAppCampaignTools();$uid=$this->uid();$wid=(int)$this->get('website_id');$id=(int)$this->get('campaign_id');
            if($action==='overview'){$pdo=Database::getInstance()->getConnection();$q=$pdo->prepare('SELECT id,domain,company_name FROM websites WHERE user_id=? ORDER BY id');$q->execute([$uid]);$sites=$q->fetchAll(PDO::FETCH_ASSOC);$data=['websites'=>$sites,'permissions'=>(new CrmPermissionService())->permissionsFor($this->actor()),'quota'=>$s->quota($uid)];if($wid){$s->owned($uid,$wid);$conn=WhatsAppCloudAPI::connectionRow($wid);$data+=['connected'=>$conn&&(int)$conn['user_id']===$uid,'audience_language'=>ContentLanguageService::forWebsite($wid,$uid),'contacts'=>$s->contacts($uid,$wid),'templates'=>$s->templates($uid,$wid),'campaigns'=>$s->campaigns($uid,$wid)];}}
            else $data=match($action){
                'contacts'=>['contacts'=>$s->audience($uid,$wid,$this->all())],
                'contact'=>['contact'=>$s->contact($uid,$wid,$this->all())],
                'tag'=>$s->tagBatch($uid,$wid,$this->get('contact_ids',[(int)$this->get('contact_id')]),(string)$this->get('tags','')),
                'import-csv'=>$s->importCsv($uid,$wid,(string)$this->get('csv',''),!empty($this->get('commit'))),
                'edit-campaign'=>['campaign'=>$s->edit($uid,$id,$this->all())],
                'preview'=>$s->preview($uid,$id,(int)$this->get('contact_id')),
                'export'=>$s->export($uid,$id),
                'import-crm'=>$s->importCrm($uid,$wid,$this->get('contact_ids',[])),
                'templates'=>['templates'=>$s->templates($uid,$wid)],
                'sync-templates'=>['templates'=>$s->syncTemplates($uid,$wid)],
                'create-template'=>$s->createTemplate($uid,$wid,$this->all()),
                'create-campaign'=>['campaign'=>$s->create($uid,$wid,$this->all())],
                'campaign'=>['campaign'=>$s->campaign($uid,$id)],
                'recipients'=>['recipients'=>$s->recipients($uid,$id,(int)$this->get('page',1))],
                'launch'=>['campaign'=>$s->launch($uid,$id,$this->get('contact_ids',[]),$this->get('scheduled_at')?:null)],
                'pause','resume','cancel','retry'=>['campaign'=>$s->action($uid,$id,$action)],
                default=>throw new InvalidArgumentException('Unsupported action'),
            };return $this->success($data);
        }catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}catch(PDOException $e){Logger::error('WhatsApp marketing storage error',['action'=>$params['action']??'','error'=>$e->getMessage()]);return $this->error('WhatsApp marketing storage is unavailable. Apply the new SQL update first.',500);}catch(RuntimeException $e){return $this->error($e->getMessage(),409);}catch(Throwable $e){Logger::error('WhatsApp marketing action failed',['action'=>$params['action']??'','error'=>$e->getMessage(),'file'=>basename($e->getFile()),'line'=>$e->getLine()]);return $this->error('Unable to complete this WhatsApp marketing action.',500);}
    }
    public function page(array $params=[]): array
    {
        if(!$this->uid())return $this->error('Unauthorized',401);$csrf=htmlspecialchars(Csrf::token(),ENT_QUOTES,'UTF-8');
        $body='<style>#whatsappMarketing label{display:block;margin:8px 0}#whatsappMarketing input:not([type=checkbox]),#whatsappMarketing select,#whatsappMarketing textarea{display:block;width:100%;max-width:640px;padding:10px;border:1px solid var(--panel-border,#334155);border-radius:8px;background:var(--panel-bg,#111827);color:inherit}#whatsappMarketing textarea{min-height:90px}#whatsappMarketing table{width:100%;border-collapse:collapse;display:block;overflow-x:auto}#whatsappMarketing td,#whatsappMarketing th{padding:10px;text-align:start;border-bottom:1px solid var(--panel-border,#334155)}#whatsappMarketing article{padding:16px 0;border-bottom:1px solid var(--panel-border,#334155)}#whatsappMarketing button{margin:6px 5px 6px 0}#whatsappMarketing p{overflow-wrap:anywhere}</style><div id="whatsappMarketing" data-csrf="'.$csrf.'"><p>Create opt-in campaigns using your website’s own WhatsApp Business account and approved marketing templates. Saving a draft does not send messages. Meta may charge your connected account.</p><nav><a class="p-btn outline" href="/chat/settings">Connect WhatsApp</a> <a class="p-btn outline" href="/chat">Customer inbox</a> <a class="p-btn outline" href="/chat/followup-settings">Automated follow-ups</a> <a class="p-btn outline" href="/crm/sequences">CRM sequences</a></nav><p><label>Website <select id="waMarketingWebsite"></select></label> <button id="waMarketingRefresh" type="button" class="p-btn outline">Refresh</button></p><p id="waMarketingStatus" role="status"></p><div id="waMarketingPanel"></div></div><script src="/assets/js/whatsapp-studio.js"></script><script src="/assets/js/whatsapp-marketing.js"></script>';
        echo $this->renderPanelPage('whatsapp_marketing','WhatsApp Marketing','Consented audiences, approved templates and campaign reports',$body,'');exit;
    }
}
