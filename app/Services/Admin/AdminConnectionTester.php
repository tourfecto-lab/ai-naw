<?php
/** Saved admin bindings only. Tests never send mail, create posts or generate paid content. */
final class AdminConnectionTester
{
    private $get; private $http; private $legacy;
    public function __construct(?callable $get=null,?callable $http=null,?callable $legacy=null)
    {
        $this->get=$get??static function($key){$s=new SystemSettingsService();return $s->getConnectionValue($key);};
        $this->http=$http;$this->legacy=$legacy??static fn($key)=>(new IntegrationsController())->testSavedSystemConnection($key);
    }
    public static function groups(): array
    {
        return [
            'gemini'=>['label'=>'Gemini','keys'=>['gemini_api_key'],'legacy'=>'gemini'],
            'google'=>['label'=>'Google OAuth','keys'=>['google_client_id','google_client_secret'],'legacy'=>'google_search_console'],
            'google_maps'=>['label'=>'Google Maps','keys'=>['google_maps_api_key'],'legacy'=>'google_maps'],
            'meta'=>['label'=>'Meta','keys'=>['meta_app_id','meta_app_secret'],'legacy'=>'meta_ads'],
            'microsoft'=>['label'=>'Microsoft Login','keys'=>['oauth_microsoft_client_id','oauth_microsoft_client_secret','oauth_microsoft_tenant']],
            'apple'=>['label'=>'Apple Login','keys'=>['oauth_apple_client_id','oauth_apple_team_id','oauth_apple_key_id','oauth_apple_private_key']],
            'smtp'=>['label'=>'SMTP','keys'=>['mail_host','mail_port','mail_username','mail_password'],'legacy'=>'smtp'],
            'dataforseo'=>['label'=>'DataForSEO','keys'=>['serp_login','serp_password']],
            'geo_openai'=>['label'=>'قياس الظهور — OpenAI','keys'=>['geo_openai_key','geo_openai_model']],
            'geo_gemini'=>['label'=>'قياس الظهور — Gemini','keys'=>['geo_gemini_key','geo_gemini_model']],
            'geo_perplexity'=>['label'=>'قياس الظهور — Perplexity','keys'=>['geo_perplexity_key','geo_perplexity_model']],
        ];
    }
    public static function fieldKeys(): array {return array_values(array_unique(array_merge(...array_column(self::groups(),'keys'))));}
    private function result(string $status,string $message,?int $code=null): array {return ['ok'=>in_array($status,['verified','partial'],true),'status'=>$status,'message'=>$message,'http_code'=>$code,'checked_at'=>gmdate('c')];}
    private function request(string $url,array $headers): array
    {
        if($this->http)return ($this->http)($url,$headers);
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false]);$body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);return ['http'=>$code,'body'=>$body===false?'':$body];
    }
    public function test(string $key): array
    {
        $meta=self::groups()[$key]??null;if(!$meta)return $this->result('unsupported','الربط ده مش مسجل للاختبار.');
        $v=[];foreach($meta['keys'] as $name){$v[$name]=trim((string)($this->get)($name));if($v[$name]===''&&$name!=='oauth_microsoft_tenant')return $this->result('missing','احفظ بيانات '.$meta['label'].' المطلوبة الأول، وبعدها اختبر.');}
        try {
            if($key==='apple'){
                $private=openssl_pkey_get_private($v['oauth_apple_private_key']);$details=$private?openssl_pkey_get_details($private):false;
                if(!$details||($details['type']??null)!==OPENSSL_KEYTYPE_EC||($details['ec']['curve_name']??'')!=='prime256v1')return $this->result('failed','مفتاح Apple لازم يكون مفتاح EC P-256 صالح بصيغة p8.');
                foreach(['oauth_apple_team_id','oauth_apple_key_id'] as $name)if(!preg_match('/^[A-Z0-9]{10}$/D',$v[$name]))return $this->result('failed','راجع Team ID وKey ID في Apple.');
                return $this->result('partial','صيغة الإعدادات والمفتاح صحيحة محليًا. ملكية التطبيق ونجاح الدخول يلزم تأكيدهم بتجربة تسجيل دخول Apple؛ لم يتم التحقق منهم بالاتصال.');
            }
            if($key==='microsoft'){
                $tenant=$v['oauth_microsoft_tenant']?:'common';if(!preg_match('/^[a-zA-Z0-9.-]{1,255}$/D',$tenant))return $this->result('failed','Tenant غير صالح.');
                $r=$this->request('https://login.microsoftonline.com/'.rawurlencode($tenant).'/v2.0/.well-known/openid-configuration',[]);$j=json_decode($r['body'],true);
                return $r['http']===200&&!empty($j['authorization_endpoint'])?$this->result('partial','تم الوصول لخدمة Microsoft. صحة Client Secret وصلاحية تسجيل الدخول تحتاج تجربة OAuth كاملة؛ لم يتم تأكيدهما.',$r['http']):$this->result('failed','تعذر قراءة إعدادات الدخول من Microsoft. راجع Tenant والاتصال.',$r['http']);
            }
            if($key==='google_maps')return $this->result('partial','مفتاح Maps محفوظ. لم يُنفَّذ طلب Geocoding مدفوع؛ صلاحية خدمات الخرائط مش مؤكدة بالاختبار ده.');
            if($key==='geo_perplexity')return $this->result('partial','المفتاح والموديل محفوظين. لا يتوفر فحص مصادقة مجاني هنا؛ لم يتم تنفيذ توليد مدفوع أو تأكيد صلاحية المفتاح.');
            if(isset($meta['legacy'])){
                $r=($this->legacy)($meta['legacy']);if(empty($r['ok']))return $this->result('failed','فشل اختبار '.$meta['label'].'. راجع القيم المحفوظة والصلاحيات والاتصال.');
                return $this->result($key==='google'?'partial':'verified',$key==='google'?'تم فحص بيانات تطبيق Google؛ اكتمال الصلاحيات ورابط الرجوع يحتاج تسجيل دخول OAuth فعلي.':'نجح اختبار '.$meta['label'].'. الاختبار لا يؤكد الخدمات أو الصلاحيات الأخرى.');
            }
            if($key==='dataforseo'){$url='https://api.dataforseo.com/v3/appendix/user_data';$headers=['Authorization: Basic '.base64_encode($v['serp_login'].':'.$v['serp_password'])];}
            elseif($key==='geo_openai'){$url='https://api.openai.com/v1/models/'.rawurlencode($v['geo_openai_model']);$headers=['Authorization: Bearer '.$v['geo_openai_key']];}
            else {$url='https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($v['geo_gemini_model']);$headers=['x-goog-api-key: '.$v['geo_gemini_key']];}
            $r=$this->request($url,$headers);$j=json_decode($r['body'],true);
            $valid=$r['http']===200&&is_array($j)&&($key==='dataforseo'?($j['status_code']??0)===20000&&($j['tasks'][0]['status_code']??0)===20000&&isset($j['tasks'][0]['result']):($key==='geo_openai'?($j['id']??'')===$v['geo_openai_model']:($j['name']??'')==='models/'.$v['geo_gemini_model']));
            return $valid?$this->result('verified','نجح اختبار الوصول للحساب أو الموديل. ده لا يؤكد توليد إجابات بالبحث؛ راجع تفعيل الخدمة والميزانية وأسعارها في الأدمن.',$r['http']):$this->result('failed','المزود لم يؤكد صلاحية الربط المحفوظ. راجع المفتاح واسم الموديل والصلاحيات؛ لم يتم تنفيذ طلب توليد.',$r['http']);
        }catch(Throwable $e){return $this->result('failed','تعذر إكمال الاختبار. راجع إعدادات الربط والاتصال؛ لم تُعرض أي بيانات سرية.');}
    }
}
