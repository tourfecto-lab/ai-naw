<?php
final class WhatsAppStudioController extends Controller
{
    private function actor(): int{return (int)($this->user['id']??0);}
    private function uid(): int{return $this->actor()?(new CrmPermissionService())->resolveTenantId($this->actor()):0;}
    public function handleAction(array $params=[]): array
    {
        if(!$this->uid())return $this->error('Unauthorized',401);$action=(string)($params['action']??'');$read=in_array($action,['media','media-file','preview-media','forms','analytics','experiments','flow-responses'],true);
        if(($_SERVER['REQUEST_METHOD']??'GET')!==($read?'GET':'POST'))return $this->error('Method not allowed',405);
        if(!$read&&(!is_string($this->get('csrf_token'))||!Csrf::verify($this->get('csrf_token'))))return $this->error('Session expired. Refresh the page.',419);
        if(!$read&&$this->rateLimitGuard('user','whatsapp_studio',20,60))return $this->error('Too many requests. Try again shortly.',429);
        $permission=$read?'view':'manage_settings';if(!(new CrmPermissionService())->can($this->actor(),$permission))return $this->error('Your team role cannot perform this action.',403);
        try{$s=new WhatsAppStudioService();$uid=$this->uid();$wid=(int)$this->get('website_id');$id=(int)$this->get('campaign_id');
            if($action==='media-file'){$file=$s->mediaFile($uid,(int)$this->get('media_id'));header('Content-Type: '.$file['mime']);header('Content-Length: '.$file['bytes']);header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'none'; sandbox");readfile($file['path']);exit;}
            $data=match($action){
                'media'=>['media'=>$s->media($uid,$wid)],
                'upload'=>$s->upload($uid,$wid,$_FILES['file']??[],!empty($this->get('template_example'))),
                'delete-media'=>$s->deleteMedia($uid,(int)$this->get('media_id')),
                'preview-media'=>$s->componentPreview($uid,$id),
                'submit-template'=>$s->submitTemplate($uid,$wid,$this->all()),
                'flow-responses'=>['responses'=>$s->flowResponses($uid,$wid)],
                'forms'=>['forms'=>$s->forms($uid,$wid)],
                'create-form'=>$s->createForm($uid,$wid,$this->all()),
                'disable-form'=>$s->disableForm($uid,$wid,(int)$this->get('form_id')),
                'tracking-link'=>$s->trackingLink($uid,$id,(string)$this->get('url','')),
                'analytics'=>$s->analytics($uid,$id),
                'experiments'=>['experiments'=>$s->experiments($uid,$wid)],
                'create-experiment'=>$s->createExperiment($uid,$wid,$this->all()),
                'cancel-experiment'=>$s->cancelExperiment($uid,(int)$this->get('experiment_id')),
                'launch-experiment'=>$s->launchExperiment($uid,(int)$this->get('experiment_id'),(string)$this->get('scheduled_at','')),
                default=>throw new InvalidArgumentException('Unsupported action'),
            };return $this->success($data);
        }catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}catch(PDOException $e){return $this->error('Apply the WhatsApp Studio SQL update before using these tools.',500);}catch(RuntimeException $e){return $this->error($e->getMessage(),409);}catch(Throwable $e){return $this->error('Unable to complete this action.',500);}
    }
    public function signup(array $params=[]): array
    {
        try{$f=(new WhatsAppStudioService())->publicForm((string)($params['token']??''));}catch(Throwable $e){return $this->error('Signup form unavailable',404);}
        $ar=$f['language']==='ar';$h=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$title=$h($f['title']);$company=$h($f['company_name']);$csrf=$h(Csrf::token());$url=$h('/wa/signup/'.$f['token']);$privacy=$h($f['privacy_url']);$started=(string)time();
        $labels=$ar?['اسمك','رقم واتساب دولي','لغة الرسائل','أوافق على تلقي عروض وتحديثات تسويقية عبر واتساب من '.$company.'. يمكنني إلغاء الاشتراك بإرسال STOP في أي وقت.','سياسة الخصوصية','تابع للتأكيد عبر واتساب','التسجيل يحتاج رسالة تأكيد من رقمك. لن يتم تفعيل الاشتراك بمجرد إرسال هذا النموذج.']:['Your name','International WhatsApp number','Message language','I agree to receive marketing offers and updates on WhatsApp from '.$company.'. I can opt out by replying STOP at any time.','Privacy policy','Continue to WhatsApp confirmation','Subscription requires confirmation from your number. Submitting this form alone does not activate marketing permission.'];
        header('Content-Type: text/html; charset=UTF-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        echo '<!doctype html><html lang="'.($ar?'ar':'en').'" dir="'.($ar?'rtl':'ltr').'"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$title.'</title><style>body{font:17px system-ui;background:#eef2ff;color:#172554;margin:0;padding:24px}main{max-width:540px;margin:32px auto;padding:28px;background:white;border-radius:18px}label{display:block;margin:18px 0}input:not([type=checkbox]),select,button{box-sizing:border-box;width:100%;padding:12px;font:inherit;border:1px solid #94a3b8;border-radius:8px}button{background:#1d4ed8;color:white}.trap{position:absolute;left:-10000px}</style><main><h1>'.$title.'</h1><p>'.$company.'</p><p>'.$labels[6].'</p><form method="post" action="'.$url.'"><input type="hidden" name="csrf_token" value="'.$csrf.'"><input type="hidden" name="started_at" value="'.$started.'"><label class="trap" aria-hidden="true">Leave empty<input name="company_url" tabindex="-1" autocomplete="off"></label><label>'.$labels[0].'<input name="name" maxlength="190" autocomplete="name" required></label><label>'.$labels[1].'<input name="phone" type="tel" placeholder="+44 20 7946 0000" maxlength="60" autocomplete="tel" required></label><label>'.$labels[2].'<select name="language">';
        foreach(InternationalSettingsService::languages() as $code=>$name)echo '<option value="'.$h($code).'"'.($code===$f['language']?' selected':'').'>'.$h($name).'</option>';
        echo '</select></label><label><input type="checkbox" name="consent" value="yes" required> '.$labels[3].'</label><p><a href="'.$privacy.'" rel="noopener noreferrer" target="_blank">'.$labels[4].'</a></p><button>'.$labels[5].'</button></form></main></html>';exit;
    }
    public function submitSignup(array $params=[]): array
    {
        if(!is_string($this->get('csrf_token'))||!Csrf::verify($this->get('csrf_token')))return $this->error('Session expired. Reload the signup form.',419);
        if($this->rateLimitGuard('ip','wa_public_signup',10,3600))return $this->error('Too many requests. Try again later.',429);
        $started=(int)$this->get('started_at');if($this->get('company_url')||$started<time()-3600||$started>time()-2)return $this->error('Reload the form and try again.',422);
        try{$s=new WhatsAppStudioService();$f=$s->publicForm((string)($params['token']??''));$r=$s->requestSignup($f['token'],$this->all());$ar=$f['language']==='ar';$h=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');header('Content-Type: text/html; charset=UTF-8');header('Cache-Control: no-store');echo '<!doctype html><html lang="'.($ar?'ar':'en').'" dir="'.($ar?'rtl':'ltr').'"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Confirm WhatsApp subscription</title><main style="max-width:540px;margin:70px auto;padding:24px;font:18px system-ui"><h1>'.($ar?'أكد اشتراكك عبر واتساب':'Confirm your WhatsApp subscription').'</h1><p>'.($ar?'أرسل الرسالة الجاهزة من نفس الرقم الذي أدخلته. الطلب صالح لمدة 30 دقيقة.':$h($r['message'])).'</p><p><a href="'.$h($r['confirmation_url']).'">'.($ar?'افتح واتساب وأرسل رسالة التأكيد':'Open WhatsApp and send confirmation').'</a></p></main></html>';exit;
        }catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}catch(Throwable $e){return $this->error('Signup is temporarily unavailable.',503);}
    }
    public function redirect(array $params=[]): array
    {
        if($this->rateLimitGuard('ip','wa_campaign_click',120,60))return $this->error('Too many requests',429);
        try{$r=(new WhatsAppStudioService())->followLink((string)($params['token']??''));WhatsAppStudioService::setAttributionCookie((int)$r['website_id'],$r['token']);header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('Location: '.$r['url'],true,302);exit;}catch(Throwable $e){return $this->error('Campaign link unavailable',404);}
    }
}
