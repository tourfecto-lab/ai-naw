<?php
class InternationalSettingsController extends Controller
{
    private function uid(): int {return (int)($this->user['id']??($_SESSION['user_id']??0));}
    public static function options(array $items,string $selected=''): string { $html='';foreach($items as $k=>$v){$html.='<option value="'.htmlspecialchars($k,ENT_QUOTES,'UTF-8').'"'.($k===$selected?' selected':'').'>'.htmlspecialchars($v,ENT_QUOTES,'UTF-8').'</option>';}return $html; }
    public static function registrationFields(): string {
        $ar=function_exists('current_lang')&&current_lang()==='ar';$countries=self::options(InternationalSettingsService::countries());$langs=self::options(['en'=>'English','ar'=>'العربية','fr'=>'Français','de'=>'Deutsch'],function_exists('current_lang')?current_lang():'en');
        return '<div class="form-group"><label>'.($ar?'بلد الشركة':'Company country').'</label><select name="country_code" class="form-control"><option value="">'.($ar?'اختر بلدك':'Select your country').'</option>'.$countries.'</select></div><div class="form-group"><label>'.($ar?'لغة لوحة الإدارة':'Dashboard language').'</label><select name="language" class="form-control">'.$langs.'</select></div><input type="hidden" name="timezone" value="UTC"><p>'.($ar?'لغة جمهور الموقع تضبط منفصلة أثناء الإعداد.':'Website audience language is configured separately during setup.').'</p>'
            // أسماء الدول بلغة الواجهة كلها (كانت إنجليزي ومعاها كام اسم عربي من الترجمة التلقائية) ومرتبة أبجديًا
            .'<script>document.addEventListener("DOMContentLoaded",function(){var el=document.querySelector("input[name=timezone]");if(el)el.value=Intl.DateTimeFormat().resolvedOptions().timeZone||"UTC";'
            .'var sel=document.querySelector("select[name=country_code]"),lang=(document.documentElement.lang||"en").slice(0,2);if(!sel||!window.Intl||!Intl.DisplayNames)return;'
            .'try{var dn=new Intl.DisplayNames([lang],{type:"region"}),first=sel.options[0],cur=sel.value,opts=Array.prototype.slice.call(sel.options,1);'
            .'opts.forEach(function(o){var n="";try{n=dn.of(o.value);}catch(e){}if(n&&n!==o.value)o.textContent=n;});'
            .'opts.sort(function(a,b){return a.textContent.localeCompare(b.textContent,lang);});opts.forEach(function(o){sel.appendChild(o);});sel.insertBefore(first,sel.firstChild);sel.value=cur;}catch(e){}'
            .'});</script>';
    }
    public function fetchSettings(array $params=[]): array {
        $uid=$this->uid();if(!$uid)return $this->error('Unauthorized',401);
        $pdo=Database::getInstance()->getConnection();$q=$pdo->prepare('SELECT country_code,language,timezone,currency FROM users WHERE id=?');$q->execute([$uid]);$account=$q->fetch(PDO::FETCH_ASSOC);
        $q=$pdo->prepare('SELECT id,domain,company_name FROM websites WHERE user_id=? ORDER BY id');$q->execute([$uid]);$websites=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach($websites as &$w){$w['audience']=InternationalSettingsService::audience($uid,(int)$w['id']);$w['resolved_language']=ContentLanguageService::forWebsite((int)$w['id'],$uid);}unset($w);
        return $this->success(['account'=>$account,'websites'=>$websites,'countries'=>InternationalSettingsService::countries(),'content_languages'=>InternationalSettingsService::languages(),'dashboard_languages'=>['en'=>'English','ar'=>'العربية','fr'=>'Français','de'=>'Deutsch'],'currencies'=>InternationalSettingsService::currencies(),'timezones'=>DateTimeZone::listIdentifiers(DateTimeZone::ALL)]);
    }
    public function saveAccount(array $params=[]): array {
        if(!$this->uid())return $this->error('Unauthorized',401);if($e=$this->verifyCsrf())return $e;
        try{$input=[];foreach(['country_code','language','timezone','currency'] as $k)if($this->has($k))$input[$k]=$this->get($k);$v=InternationalSettingsService::accountValues($input);$u=(new User())->find($this->uid());foreach($v as $k=>$value)$u->setAttribute($k,$value);if(!$u->save())throw new RuntimeException('Unable to save account');$_SESSION['user']=$u->toArray();if(isset($v['language'])){$_COOKIE['tf_lang']=$v['language'];if(!headers_sent())setcookie('tf_lang',$v['language'],['expires'=>time()+31536000,'path'=>'/','samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off']);}return $this->success($v);}
        catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}catch(Throwable $e){return $this->error('Unable to save account settings',409);}
    }
    public function saveAudience(array $params=[]): array {
        if(!$this->uid())return $this->error('Unauthorized',401);if($e=$this->verifyCsrf())return $e;
        try{return $this->success(InternationalSettingsService::saveAudience($this->uid(),(int)$params['id'],['target_countries'=>$this->get('target_countries',[]),'target_languages'=>$this->get('target_languages',[]),'selling_currency'=>$this->get('selling_currency','USD')]));}
        catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}catch(Throwable $e){return $this->error('Unable to save this website audience',409);}
    }
    public function page(array $params=[]): array {
        if(!$this->uid())return $this->error('Unauthorized',401);$token=htmlspecialchars(Csrf::token(),ENT_QUOTES,'UTF-8');
        $body='<div id="internationalSettings" data-csrf="'.$token.'"><p>Company location, dashboard language and website audience are separate settings. Empty audience language uses English. The first language is used for generation; other languages describe additional audiences.</p><div id="internationalStatus" role="status"></div><form id="internationalAccount" class="p-card"></form><form id="internationalAudience" class="p-card"></form><p>Selling currency applies to new content guidance. Existing product prices, bookings, invoices and subscription billing keep their actual currency. There is no automatic exchange-rate conversion.</p></div>';
        echo $this->renderPanelPage('_profile','International settings','Company locale and website audience',$body,'');exit;
    }
}
