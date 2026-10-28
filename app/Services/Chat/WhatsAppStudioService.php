<?php
/** Media, explicit public opt-in, tracked booking links and split campaign experiments. */
final class WhatsAppStudioService extends WhatsAppMarketingService
{
    private function connection(int $uid,int $wid): array
    {
        $this->owned($uid,$wid);$r=WhatsAppCloudAPI::connectionRow($wid);
        if(!$r||(int)$r['user_id']!==$uid)throw new InvalidArgumentException('Connect this website to WhatsApp first');return $r;
    }
    private static function connectionHash(array $r): string{return hash('sha256',$r['external_account_id'].'|'.$r['external_location_id'].'|'.$r['access_token']);}
    private static function directory(): string{return dirname(__DIR__,3).'/storage/whatsapp-studio';}
    public static function inspectFile(string $path): array
    {
        $size=is_file($path)?filesize($path):0;if($size<1||$size>16*1024*1024)throw new InvalidArgumentException('Upload a file up to 16 MB');
        if(!class_exists('finfo'))throw new RuntimeException('Enable PHP fileinfo to upload media');$mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
        if(!in_array($mime,['image/jpeg','image/png','video/mp4','application/pdf'],true))throw new InvalidArgumentException('Use a JPEG, PNG, MP4 video or PDF document');
        if(str_starts_with($mime,'image/')&&($size>5*1024*1024||!@getimagesize($path)))throw new InvalidArgumentException('Images must be valid JPEG/PNG files up to 5 MB');return ['mime'=>$mime,'bytes'=>$size];
    }
    public function upload(int $uid,int $wid,array $file,bool $example=false): array
    {
        $r=$this->connection($uid,$wid);if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??''))throw new InvalidArgumentException('Choose a file; check your server upload size limit');
        return $this->storeMedia($uid,$wid,(string)$file['tmp_name'],(string)($file['name']??'Media'),$example);
    }
    /** Kept separate for real byte validation tests, with no bypass on the upload endpoint. */
    public function storeMedia(int $uid,int $wid,string $path,string $name,bool $example=false): array
    {
        $key='wa_media:'.$uid;if(!ExternalExecution::lock($key))throw new RuntimeException('Another media upload is in progress');try{return $this->storeMediaUnlocked($uid,$wid,$path,$name,$example);}finally{ExternalExecution::unlock($key);}
    }
    private function storeMediaUnlocked(int $uid,int $wid,string $path,string $name,bool $example): array
    {
        $r=$this->connection($uid,$wid);$info=self::inspectFile($path);$dir=self::directory();if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Media storage is unavailable');file_put_contents($dir.'/.htaccess',"Require all denied\n");
        $usage=$this->q('SELECT COUNT(*) AS files,COALESCE(SUM(byte_size),0) AS bytes FROM wa_studio_media WHERE user_id=?',[$uid])->fetch(PDO::FETCH_ASSOC);if((int)$usage['files']>=200||(int)$usage['bytes']+$info['bytes']>500*1024*1024)throw new InvalidArgumentException('Media storage limit reached: 200 files or 500 MB per account');
        $count=(int)$this->q('SELECT COUNT(*) FROM wa_studio_media WHERE user_id=? AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)',[$uid])->fetchColumn();if($count>=100)throw new InvalidArgumentException('Daily upload limit reached');
        $api=WhatsAppCloudAPI::forWebsite($wid);if(!$api)throw new RuntimeException('Reconnect WhatsApp');$key=bin2hex(random_bytes(24));$dest=$dir.'/'.$key;
        if(!copy($path,$dest))throw new RuntimeException('Unable to store the uploaded file');chmod($dest,0600);
        try{$result=$api->uploadMedia($dest,$info['mime']);if(empty($result['ok']))throw new RuntimeException($result['error']??'Upload not confirmed');$handle=null;
            if($example){$ex=$api->uploadMedia($dest,$info['mime'],true);if(!empty($ex['ok']))$handle=$ex['value'];}
            if(self::connectionHash($this->connection($uid,$wid))!==self::connectionHash($r))throw new RuntimeException('Sender changed during upload; upload again for the new sender');
            $this->q('INSERT INTO wa_studio_media(user_id,website_id,connection_hash,media_id,example_handle,mime,byte_size,file_key,original_name) VALUES(?,?,?,?,?,?,?,?,?)',[$uid,$wid,self::connectionHash($r),$result['value'],$handle,$info['mime'],$info['bytes'],$key,mb_substr(basename($name),0,190)]);
            $media=$this->mediaRow($uid,(int)$this->pdo->lastInsertId());unset($media['file_key'],$media['connection_hash'],$media['example_handle']);return ['media'=>$media,'example_ready'=>$handle!==null,'message'=>$example&&$handle===null?'Media uploaded for sending. The template example upload was not confirmed; check your Meta application settings.':'Media uploaded'];
        }catch(Throwable $e){@unlink($dest);throw $e;}
    }
    private function mediaRow(int $uid,int $id): array
    {
        $r=$this->q('SELECT * FROM wa_studio_media WHERE id=? AND user_id=?',[$id,$uid])->fetch(PDO::FETCH_ASSOC);if(!$r)throw new InvalidArgumentException('Media not found');$r['preview_url']='/api/whatsapp-studio/media-file?media_id='.$r['id'];return $r;
    }
    public function media(int $uid,int $wid): array
    {
        $r=$this->connection($uid,$wid);$rows=$this->q('SELECT id,media_id,mime,byte_size,original_name,example_handle FROM wa_studio_media WHERE user_id=? AND website_id=? AND connection_hash=? ORDER BY id DESC LIMIT 100',[$uid,$wid,self::connectionHash($r)])->fetchAll(PDO::FETCH_ASSOC);foreach($rows as &$row){$row['preview_url']='/api/whatsapp-studio/media-file?media_id='.$row['id'];$row['example_ready']=!empty($row['example_handle']);unset($row['example_handle']);}return $rows;
    }
    public function mediaFile(int $uid,int $id): array
    {
        $r=$this->mediaRow($uid,$id);$this->owned($uid,(int)$r['website_id']);$path=self::directory().'/'.$r['file_key'];if(!is_file($path))throw new InvalidArgumentException('Media file is no longer available');return ['path'=>$path,'mime'=>$r['mime'],'bytes'=>$r['byte_size']];
    }
    public function deleteMedia(int $uid,int $id): array
    {
        $m=$this->mediaRow($uid,$id);$this->owned($uid,(int)$m['website_id']);$rows=$this->q("SELECT extra_parameters_json FROM wa_marketing_campaigns WHERE user_id=? AND website_id=? AND state IN ('draft','scheduled','running','paused','blocked')",[$uid,$m['website_id']])->fetchAll(PDO::FETCH_ASSOC);foreach($rows as $r)if(in_array($m['media_id'],json_decode($r['extra_parameters_json']??'[]',true)?:[],true))throw new InvalidArgumentException('This asset is used by an active campaign');$path=self::directory().'/'.$m['file_key'];if(is_file($path)&&!unlink($path))throw new RuntimeException('Unable to remove stored media');$this->q('DELETE FROM wa_studio_media WHERE id=? AND user_id=?',[$id,$uid]);return ['deleted'=>true];
    }
    public function previewMedia(int $uid,int $campaignId): array
    {
        $c=$this->campaign($uid,$campaignId);$extras=json_decode($c['extra_parameters_json']??'[]',true)?:[];$all=$this->media($uid,(int)$c['website_id']);$out=[];foreach($extras as $key=>$value)if(str_ends_with($key,'header_media'))foreach($all as $m)if($m['media_id']===$value){$out[$key]=$m;break;}return $out;
    }
    /** Friendly builder definitions, including optional carousel cards and published Flow buttons. */
    public function componentPreview(int $uid,int $id): array
    {
        $c=$this->campaign($uid,$id);return ['media'=>$this->previewMedia($uid,$id),'components'=>json_decode($c['components_json']??'[]',true)?:[],'extras'=>json_decode($c['extra_parameters_json']??'[]',true)?:[]];
    }
    public function submitTemplate(int $uid,int $wid,array $in): array
    {
        $r=$this->connection($uid,$wid);$name=trim((string)($in['name']??''));$lang=trim((string)($in['language']??'en'));
        if(!preg_match('/^[a-z][a-z0-9_]{0,127}$/D',$name)||!preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/D',$lang))throw new InvalidArgumentException('Use a lowercase template name and valid language code');
        $body=trim((string)($in['body']??''));if($body===''||mb_strlen($body)>1024||!preg_match('/\bSTOP\b/i',$body))throw new InvalidArgumentException('Write a message up to 1024 characters including Reply STOP to opt out');
        $names=WhatsAppTemplateComponents::variables($body);$named=$names&&!ctype_digit($names[0]);$examples=$in['examples']??[];if(!is_array($examples)||count($examples)!==count($names))throw new InvalidArgumentException('Provide one example for each message variable');
        $b=['type'=>'BODY','text'=>$body];if($names){$samples=[];foreach($examples as $x){if(!is_string($x)||trim($x)===''||mb_strlen($x)>500)throw new InvalidArgumentException('Fill every sample value');$samples[]=trim($x);}if($named){$params=[];foreach($names as $i=>$n)$params[]=['param_name'=>$n,'example'=>$samples[$i]];$b['example']=['body_text_named_params'=>$params];}else $b['example']=['body_text'=>[$samples]];}
        $components=[$b];$header=$this->templateHeader($uid,$wid,$in);if($header)array_unshift($components,$header);
        $footer=trim((string)($in['footer']??''));if(mb_strlen($footer)>60||str_contains($footer,'{{'))throw new InvalidArgumentException('Footer must be static text up to 60 characters');if($footer!=='')$components[]=['type'=>'FOOTER','text'=>$footer];
        $buttons=$this->templateButtons($in['buttons']??[]);if($buttons)$components[]=['type'=>'BUTTONS','buttons'=>$buttons];
        $cards=$in['cards']??[];if($cards){if(!is_array($cards)||count($cards)<2||count($cards)>10||$header||$buttons)throw new InvalidArgumentException('Use 2–10 carousel cards without top-level header or buttons');$out=[];$layout=null;foreach($cards as $card){if(!is_array($card))throw new InvalidArgumentException('Invalid carousel card');$h=$this->templateHeader($uid,$wid,$card);if(!$h||!in_array($h['format'],['IMAGE','VIDEO'],true))throw new InvalidArgumentException('Each card needs an uploaded image or video example');$text=trim((string)($card['body']??''));if($text===''||mb_strlen($text)>160||WhatsAppTemplateComponents::variables($text))throw new InvalidArgumentException('Use static card text up to 160 characters in the builder');$btn=$this->templateButtons($card['buttons']??[]);if(!$btn||count($btn)>2)throw new InvalidArgumentException('Each card needs 1–2 buttons');$signature=json_encode([$h['format'],array_column($btn,'type')]);if($layout!==null&&$layout!==$signature)throw new InvalidArgumentException('Use the same media format and button types on every card');$layout=$signature;$out[]=['components'=>[$h,['type'=>'BODY','text'=>$text],['type'=>'BUTTONS','buttons'=>$btn]]];}$components[]=['type'=>'CAROUSEL','cards'=>$out];}
        $t=['name'=>$name,'language'=>$lang,'category'=>'MARKETING','parameter_format'=>$named?'NAMED':'POSITIONAL','components'=>$components];$v=self::inspectTemplate($t);if(!$v['supported'])throw new InvalidArgumentException($v['reason']);$api=WhatsAppCloudAPI::forWebsite($wid);if(!$api)throw new RuntimeException('Reconnect WhatsApp');
        $key='wa_studio_template:'.$uid.':'.$wid.':'.hash('sha256',json_encode($t));$result=ExternalExecution::run($key,$uid,function()use($api,$t){$x=$api->createTemplate($t);return ['success'=>!empty($x['ok']),'status'=>$x['status']??'PENDING','http_code'=>$x['http_code']??0,'error'=>empty($x['ok'])?'Template submission was not confirmed. Check WhatsApp Manager before retrying.':null];});if(empty($result['success']))throw new RuntimeException($result['error']);return ['message'=>'Submitted for Meta approval. Refresh templates after review.','status'=>$result['status']??'PENDING'];
    }
    private function templateHeader(int $uid,int $wid,array $in): ?array
    {
        $type=strtoupper($in['header_type']??'NONE');if($type==='NONE')return null;
        if($type==='TEXT'){$text=trim((string)($in['header_text']??''));if(!$text||mb_strlen($text)>60||WhatsAppTemplateComponents::variables($text))throw new InvalidArgumentException('Builder text headers must be static, up to 60 characters');return ['type'=>'HEADER','format'=>'TEXT','text'=>$text];}
        if(!in_array($type,['IMAGE','VIDEO','DOCUMENT'],true))throw new InvalidArgumentException('Invalid header type');$m=$this->mediaRow($uid,(int)($in['media_id']??0));$r=$this->connection($uid,$wid);$format=match($m['mime']){'image/png','image/jpeg'=>'IMAGE','video/mp4'=>'VIDEO','application/pdf'=>'DOCUMENT',default=>''};
        if((int)$m['website_id']!==$wid||$m['connection_hash']!==self::connectionHash($r)||!$m['example_handle']||$format!==$type)throw new InvalidArgumentException('Choose a matching uploaded template example for this sender');return ['type'=>'HEADER','format'=>$type,'example'=>['header_handle'=>[$m['example_handle']]]];
    }
    private function templateButtons(array $buttons): array
    {
        if(count($buttons)>10)throw new InvalidArgumentException('Use at most ten buttons');$out=[];foreach($buttons as $b){$type=strtoupper($b['type']??'');$text=trim((string)($b['text']??''));if(!$text||mb_strlen($text)>25)throw new InvalidArgumentException('Button labels need 1–25 characters');$v=['type'=>$type,'text'=>$text];
            if($type==='URL'){$url=trim((string)($b['url']??''));if(strlen($url)>2000||!filter_var($url,FILTER_VALIDATE_URL)||parse_url($url,PHP_URL_SCHEME)!=='https'||parse_url($url,PHP_URL_USER)!==null)throw new InvalidArgumentException('Use a valid HTTPS button URL');$vars=WhatsAppTemplateComponents::variables($url);if($vars&&($vars!==['1']||!str_ends_with($url,'{{1}}')))throw new InvalidArgumentException('Put a single {{1}} at the end of a dynamic button URL');$v['url']=$url;if($vars){$sample=trim((string)($b['example']??''));if(!$sample||!filter_var(str_replace('{{1}}',$sample,$url),FILTER_VALIDATE_URL))throw new InvalidArgumentException('Provide a dynamic URL suffix example');$v['example']=[str_replace('{{1}}',$sample,$url)];}}
            elseif($type==='PHONE_NUMBER')$v['phone_number']='+'.self::phone((string)($b['phone_number']??''));
            elseif($type==='FLOW'){if(!preg_match('/^[0-9]{1,64}$/D',(string)($b['flow_id']??'')))throw new InvalidArgumentException('Enter a published Flow ID');$v['flow_id']=$b['flow_id'];$v['flow_action']='navigate';$screen=trim((string)($b['navigate_screen']??''));if(!preg_match('/^[a-zA-Z0-9_]{1,80}$/D',$screen))throw new InvalidArgumentException('Enter the Flow start screen');$v['navigate_screen']=$screen;}
            elseif($type!=='QUICK_REPLY')throw new InvalidArgumentException('Unsupported button type');$out[]=$v;
        }return $out;
    }
    public function forms(int $uid,int $wid): array{$this->owned($uid,$wid);return $this->q('SELECT id,token,title,language,privacy_url,active FROM wa_signup_forms WHERE user_id=? AND website_id=? ORDER BY id DESC LIMIT 50',[$uid,$wid])->fetchAll(PDO::FETCH_ASSOC);}
    public function createForm(int $uid,int $wid,array $in): array
    {
        $r=$this->connection($uid,$wid);$display=json_decode($r['meta_json']??'{}',true)['display_phone']??'';self::phone($display);$title=trim((string)($in['title']??'Travel updates'));$lang=trim((string)($in['language']??'en'));$privacy=trim((string)($in['privacy_url']??''));
        if(!$title||mb_strlen($title)>190||!in_array($lang,['en','ar'],true)||!filter_var($privacy,FILTER_VALIDATE_URL)||parse_url($privacy,PHP_URL_SCHEME)!=='https')throw new InvalidArgumentException('Provide a title, English or Arabic form language and an HTTPS privacy policy URL');
        if(count($this->forms($uid,$wid))>=50)throw new InvalidArgumentException('Form limit reached');$token=bin2hex(random_bytes(32));$this->q('INSERT INTO wa_signup_forms(user_id,website_id,token,title,language,privacy_url) VALUES(?,?,?,?,?,?)',[$uid,$wid,$token,$title,$lang,$privacy]);return ['token'=>$token,'path'=>'/wa/signup/'.$token];
    }
    public function disableForm(int $uid,int $wid,int $id): array{$this->owned($uid,$wid);$this->q('UPDATE wa_signup_forms SET active=0 WHERE id=? AND user_id=? AND website_id=?',[$id,$uid,$wid]);return ['saved'=>true];}
    public function publicForm(string $token): array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new InvalidArgumentException('Signup form unavailable');$f=$this->q('SELECT f.*,w.company_name FROM wa_signup_forms f JOIN websites w ON w.id=f.website_id AND w.user_id=f.user_id WHERE f.token=? AND f.active=1',[$token])->fetch(PDO::FETCH_ASSOC);if(!$f)throw new InvalidArgumentException('Signup form unavailable');$r=$this->connection((int)$f['user_id'],(int)$f['website_id']);$f['sender_phone']=self::phone(json_decode($r['meta_json']??'{}',true)['display_phone']??'');return $f;
    }
    public function requestSignup(string $token,array $in): array
    {
        $f=$this->publicForm($token);$phone=self::phone((string)($in['phone']??''));$name=trim((string)($in['name']??''));$lang=trim((string)($in['language']??$f['language']));if(!$name||mb_strlen($name)>190||!preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/D',$lang)||($in['consent']??'')!=='yes')throw new InvalidArgumentException('Fill your name, international number, language and explicit consent');
        $n=(int)$this->q('SELECT COUNT(*) FROM wa_signup_requests WHERE website_id=? AND phone=? AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR)',[$f['website_id'],$phone])->fetchColumn();if($n>=3)throw new InvalidArgumentException('Please wait before requesting another confirmation');$code=bin2hex(random_bytes(32));$evidence='Public signup: '.$f['id'].'; explicit marketing checkbox; confirm by sending JOIN from the submitted WhatsApp number';
        $this->q('INSERT INTO wa_signup_requests(token,form_id,user_id,website_id,phone,name,language,evidence,expires_at) VALUES(?,?,?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE))',[$code,$f['id'],$f['user_id'],$f['website_id'],$phone,$name,$lang,$evidence]);return ['confirmation_url'=>'https://wa.me/'.$f['sender_phone'].'?text='.rawurlencode('JOIN '.$code),'message'=>'Send the prepared WhatsApp message from the number you entered to confirm your subscription. The request expires in 30 minutes.'];
    }
    /** Called only after the signed webhook is routed to its verified owner and sender. */
    public static function confirmSignup(int $uid,int $wid,array $payload): bool
    {
        if(!preg_match('/^JOIN ([a-f0-9]{64})$/D',trim((string)($payload['message']??'')),$match))return false;$s=new self();$s->owned($uid,$wid);$phone=self::phone((string)($payload['phone_number']??''));
        try{$s->q('SELECT token FROM wa_signup_requests LIMIT 0');}catch(PDOException $e){return false;}
        $s->pdo->beginTransaction();try{$r=$s->q('SELECT r.* FROM wa_signup_requests r JOIN wa_signup_forms f ON f.id=r.form_id AND f.active=1 WHERE r.token=? AND r.user_id=? AND r.website_id=? AND r.phone=? AND (r.confirmed_at IS NOT NULL OR r.expires_at>UTC_TIMESTAMP()) FOR UPDATE',[$match[1],$uid,$wid,$phone])->fetch(PDO::FETCH_ASSOC);if(!$r){$s->pdo->rollBack();return false;}
            if(!$r['confirmed_at']){$evidence=$r['evidence'].'; verified incoming message '.mb_substr((string)($payload['platform_message_id']??''),0,150);$s->q("INSERT INTO wa_marketing_contacts(user_id,website_id,phone,name,language,consent_state,consent_note,consented_at,opted_out_at) VALUES(?,?,?,?,?,'opted_in',?,UTC_TIMESTAMP(),NULL) ON DUPLICATE KEY UPDATE name=VALUES(name),language=VALUES(language),consent_state='opted_in',consent_note=VALUES(consent_note),consented_at=UTC_TIMESTAMP(),opted_out_at=NULL",[$uid,$wid,$phone,$r['name'],$r['language'],$evidence]);$id=$s->q('SELECT id FROM wa_marketing_contacts WHERE user_id=? AND website_id=? AND phone=?',[$uid,$wid,$phone])->fetchColumn();$s->q("INSERT INTO wa_marketing_consent_events(contact_id,user_id,website_id,state,evidence) VALUES(?,?,?,'opted_in',?)",[$id,$uid,$wid,$evidence]);$s->q('UPDATE wa_signup_requests SET confirmed_at=UTC_TIMESTAMP() WHERE token=?',[$match[1]]);}$s->pdo->commit();return true;
        }catch(Throwable $e){if($s->pdo->inTransaction())$s->pdo->rollBack();throw $e;}
    }
    public static function storeFlowResponse(int $uid,int $wid,array $payload): void
    {
        $s=new self();$s->owned($uid,$wid);$phone=self::phone((string)($payload['phone_number']??''));$wamid=(string)($payload['platform_message_id']??'');$response=$payload['flow_response']??null;if($wamid===''||strlen($wamid)>255||!is_array($response))throw new InvalidArgumentException('Invalid Flow response');$json=json_encode($response,JSON_THROW_ON_ERROR);if(strlen($json)>25000)throw new InvalidArgumentException('Flow response is too large');
        $r=$s->q('SELECT r.id,c.components_json FROM wa_marketing_recipients r JOIN wa_marketing_campaigns c ON c.id=r.campaign_id WHERE c.user_id=? AND c.website_id=? AND r.phone=? AND BINARY r.message_id=? LIMIT 1',[$uid,$wid,$phone,(string)($payload['flow_reply_to']??'')])->fetch(PDO::FETCH_ASSOC);$recipient=null;
        if($r){foreach(WhatsAppTemplateComponents::requirements(json_decode($r['components_json']??'[]',true)?:[]) as $req){if($req['kind']==='json'&&preg_match('/^(.*)flow_([0-9]+)$/D',$req['key'],$m)&&hash_equals(hash('sha256',$r['id'].':'.$m[1].$m[2]),(string)($response['flow_token']??''))){$recipient=$r['id'];break;}}}
        $s->q('INSERT IGNORE INTO wa_flow_responses(user_id,website_id,phone,wamid,recipient_id,response_enc) VALUES(?,?,?,?,?,?)',[$uid,$wid,$phone,$wamid,$recipient,(new Encryption())->encrypt($json)]);
    }
    public function flowResponses(int $uid,int $wid): array
    {
        $this->owned($uid,$wid);$rows=$this->q('SELECT id,phone,recipient_id,response_enc,created_at FROM wa_flow_responses WHERE user_id=? AND website_id=? ORDER BY id DESC LIMIT 100',[$uid,$wid])->fetchAll(PDO::FETCH_ASSOC);foreach($rows as &$r){$r['response']=json_decode((new Encryption())->decrypt($r['response_enc']),true,512,JSON_THROW_ON_ERROR);unset($r['response_enc']);}return $rows;
    }
    public function trackingLink(int $uid,int $id,string $url): array
    {
        $c=$this->campaign($uid,$id);if(strlen($url)>2000||!filter_var($url,FILTER_VALIDATE_URL)||parse_url($url,PHP_URL_SCHEME)!=='https'||parse_url($url,PHP_URL_USER)!==null||parse_url($url,PHP_URL_PASS)!==null)throw new InvalidArgumentException('Use an HTTPS destination URL');
        // Only this website's published destination. No open redirect or arbitrary campaign domain.
        $domain=$this->q('SELECT domain,main_url FROM websites WHERE id=? AND user_id=?',[$c['website_id'],$uid])->fetch(PDO::FETCH_ASSOC);$domainValue=(string)($domain['domain']??'');$host=parse_url(str_contains($domainValue,'://')?$domainValue:'https://'.$domainValue,PHP_URL_HOST);$base=self::baseUrl();$baseHost=parse_url($base,PHP_URL_HOST);$targetHost=strtolower((string)parse_url($url,PHP_URL_HOST));$path=parse_url($url,PHP_URL_PATH)?:'/';$generated=null;
        if($targetHost===strtolower((string)$baseHost)&&preg_match('#^/sites/([^/]+)(?:/|$)#',$path,$match)){$generated=$this->q("SELECT id FROM generated_websites WHERE slug=? AND user_id=? AND status='published'",[rawurldecode($match[1]),$uid])->fetchColumn();if(!$generated)throw new InvalidArgumentException('Destination website is not published under your account');}
        elseif($targetHost!==strtolower((string)$baseHost)){$matches=$this->q("SELECT id FROM generated_websites WHERE user_id=? AND status='published' AND LOWER(custom_domain)=? LIMIT 2",[$uid,$targetHost])->fetchAll(PDO::FETCH_ASSOC);if(count($matches)>1)throw new InvalidArgumentException('The destination domain is assigned to multiple generated websites');$generated=$matches[0]['id']??null;}
        $sameSite=$targetHost===strtolower((string)$host)&&$host&&$targetHost!==strtolower((string)$baseHost);
        if(!$sameSite&&!$generated)throw new InvalidArgumentException('Destination must be your campaign website or a published generated website under your account');$token=bin2hex(random_bytes(32));$this->q('INSERT INTO wa_campaign_links(user_id,website_id,campaign_id,generated_website_id,token,target_url) VALUES(?,?,?,?,?,?)',[$uid,$c['website_id'],$id,$generated?:null,$token,$url]);return ['url'=>$base.'/wa/go/'.$token,'path'=>'/wa/go/'.$token];
    }
    public static function baseUrl(): string
    {
        $base=function_exists('env')?(string)(env('APP_URL')?:env('SITE_URL')?:(defined('APP_URL')?APP_URL:'')):(defined('APP_URL')?APP_URL:'');
        if(!filter_var($base,FILTER_VALIDATE_URL)||!in_array(parse_url($base,PHP_URL_SCHEME),['http','https'],true))throw new RuntimeException('Configure APP_URL before creating public campaign links');return rtrim($base,'/');
    }
    public function followLink(string $token): array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new InvalidArgumentException('Campaign link not found');$r=$this->q('SELECT l.* FROM wa_campaign_links l JOIN websites w ON w.id=l.website_id AND w.user_id=l.user_id JOIN wa_marketing_campaigns c ON c.id=l.campaign_id AND c.user_id=l.user_id WHERE l.token=?',[$token])->fetch(PDO::FETCH_ASSOC);if(!$r)throw new InvalidArgumentException('Campaign link not found');$this->q('UPDATE wa_campaign_links SET clicks=clicks+1,last_clicked_at=UTC_TIMESTAMP() WHERE id=?',[$r['id']]);$url=$r['target_url'];$fragment='';if(str_contains($url,'#')){[$url,$fragment]=explode('#',$url,2);$fragment='#'.$fragment;}$url.=(str_contains($url,'?')?'&':'?').'wa_token='.$token;return ['url'=>$url.$fragment,'token'=>$token,'website_id'=>$r['generated_website_id']?:$r['website_id']];
    }
    public static function attribute(PDO $pdo,int $uid,int $wid,int $bookingId,string $token): void
    {
        if(!$token||!preg_match('/^[a-f0-9]{64}$/D',$token))return;
        // Old installations without the additive migration keep normal booking behavior.
        try{$q=$pdo->prepare('SELECT id,campaign_id,website_id,generated_website_id FROM wa_campaign_links WHERE token=? AND user_id=? AND ((generated_website_id IS NULL AND website_id=?) OR generated_website_id=?) AND last_clicked_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)');$q->execute([$token,$uid,$wid,$wid]);$r=$q->fetch(PDO::FETCH_ASSOC);}catch(PDOException $e){if(in_array($e->getCode(),['42S02','42S22'],true))return;throw $e;}
        if(!$r)return;$q=$pdo->prepare('INSERT IGNORE INTO wa_booking_attribution(booking_id,user_id,website_id,campaign_id,link_id) SELECT b.id,?,?,?,? FROM bookings b JOIN crm_products p ON p.id=b.product_id AND p.user_id=b.user_id WHERE b.id=? AND b.user_id=? AND p.website_id=? AND (? IS NULL OR p.tour_slug IS NOT NULL)');$q->execute([$uid,$r['website_id'],$r['campaign_id'],$r['id'],$bookingId,$uid,$wid,$r['generated_website_id']]);
    }
    public static function captureQuery(int $wid,int $uid): void
    {
        $token=$_GET['wa_token']??'';if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))return;
        try{$pdo=Database::getInstance()->getConnection();$q=$pdo->prepare('SELECT l.id FROM wa_campaign_links l JOIN websites w ON w.id=l.website_id AND w.user_id=l.user_id WHERE l.token=? AND l.generated_website_id=? AND l.user_id=? AND l.last_clicked_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)');$q->execute([$token,$wid,$uid]);if($q->fetchColumn())self::setAttributionCookie($wid,$token);}catch(PDOException $e){if(!in_array($e->getCode(),['42S02','42S22'],true))throw $e;}
    }
    private static function signingKey(): string
    {
        return function_exists('env')?(string)(env('APP_KEY')?:env('ENCRYPTION_KEY')?:(defined('ENCRYPTION_KEY')?ENCRYPTION_KEY:'')):(defined('ENCRYPTION_KEY')?ENCRYPTION_KEY:'');
    }
    public static function cookieToken(int $wid): string
    {
        $v=$_COOKIE['tf_wa_'.$wid]??'';if(!is_string($v))return '';[$token,$time,$sig]=array_pad(explode('.',$v),3,'');$key=self::signingKey();
        if(!$key||!ctype_digit($time)||(int)$time<time()-30*86400||(int)$time>time()+60||!hash_equals(hash_hmac('sha256',$wid.'.'.$token.'.'.$time,$key),$sig))return '';return $token;
    }
    public static function setAttributionCookie(int $wid,string $token): void
    {
        $key=self::signingKey();if(!$key)return;$time=(string)time();$sig=hash_hmac('sha256',$wid.'.'.$token.'.'.$time,$key);setcookie('tf_wa_'.$wid,$token.'.'.$time.'.'.$sig,['expires'=>time()+30*86400,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
    }
    public function analytics(int $uid,int $id): array
    {
        $c=$this->campaign($uid,$id);$delivery=$this->q('SELECT r.state,COALESCE(d.status,\'unknown\') AS delivery,COUNT(*) AS recipients FROM wa_marketing_recipients r LEFT JOIN whatsapp_delivery_statuses d ON d.user_id=? AND d.website_id=? AND BINARY d.wamid=r.message_id WHERE r.campaign_id=? GROUP BY r.state,d.status',[$uid,$c['website_id'],$id])->fetchAll(PDO::FETCH_ASSOC);
        $money=$this->q("SELECT b.currency,COUNT(*) AS bookings,COALESCE(SUM(b.total_amount),0) AS booking_value,COALESCE(SUM(b.amount_paid),0) AS collected_amount FROM wa_booking_attribution a JOIN bookings b ON b.id=a.booking_id AND b.user_id=a.user_id JOIN wa_campaign_links l ON l.id=a.link_id AND l.user_id=a.user_id JOIN crm_products p ON p.id=b.product_id AND p.user_id=b.user_id AND p.website_id=COALESCE(l.generated_website_id,a.website_id) WHERE a.user_id=? AND a.campaign_id=? AND b.status IN ('confirmed','completed') GROUP BY b.currency",[$uid,$id])->fetchAll(PDO::FETCH_ASSOC);
        $links=$this->q('SELECT id,token,target_url,clicks FROM wa_campaign_links WHERE user_id=? AND campaign_id=? ORDER BY id',[$uid,$id])->fetchAll(PDO::FETCH_ASSOC);return ['campaign_id'=>$id,'name'=>$c['name'],'state'=>$c['state'],'delivery'=>$delivery,'currency_totals'=>$money,'links'=>$links,'note'=>'Clicks count requests, including repeat visits. Attribution uses campaign links for 30 days; booking value and collected amount are separate and never combine currencies.'];
    }
    public function createExperiment(int $uid,int $wid,array $in): array
    {
        $key='wa_experiment_create:'.$uid;if(!ExternalExecution::lock($key))throw new RuntimeException('Another experiment is being prepared');try{return $this->createExperimentUnlocked($uid,$wid,$in);}finally{ExternalExecution::unlock($key);}
    }
    private function createExperimentUnlocked(int $uid,int $wid,array $in): array
    {
        $this->owned($uid,$wid);$a=$this->campaign($uid,(int)($in['campaign_a']??0));$b=$this->campaign($uid,(int)($in['campaign_b']??0));$ids=array_values(array_unique(array_map('intval',$in['contact_ids']??[])));$name=trim((string)($in['name']??'A/B campaign'));
        if(!$name||mb_strlen($name)>190||$a['id']===$b['id']||(int)$a['website_id']!==$wid||(int)$b['website_id']!==$wid||$a['state']!=='draft'||$b['state']!=='draft'||$a['language']!==$b['language']||count($ids)<2||count($ids)>500)throw new InvalidArgumentException('Choose two different draft campaigns for the same website and language, and 2–500 contacts');
        if($this->q('SELECT id FROM wa_campaign_experiments WHERE user_id=? AND (campaign_a IN (?,?) OR campaign_b IN (?,?)) LIMIT 1',[$uid,$a['id'],$b['id'],$a['id'],$b['id']])->fetchColumn())throw new InvalidArgumentException('These drafts are already assigned to an experiment');
        foreach($ids as $id){$r=$this->q("SELECT language FROM wa_marketing_contacts WHERE id=? AND user_id=? AND website_id=? AND consent_state='opted_in'",[$id,$uid,$wid])->fetch(PDO::FETCH_ASSOC);if(!$r||!self::compatible($r['language'],$a['language']))throw new InvalidArgumentException('Every selected contact must have matching language and explicit permission');}
        $seed=bin2hex(random_bytes(16));usort($ids,fn($x,$y)=>strcmp(hash('sha256',$seed.':'.$x),hash('sha256',$seed.':'.$y)));$split=['a'=>array_slice($ids,0,(int)ceil(count($ids)/2)),'b'=>array_slice($ids,(int)ceil(count($ids)/2))];$this->q('INSERT INTO wa_campaign_experiments(user_id,website_id,name,campaign_a,campaign_b,split_json) VALUES(?,?,?,?,?,?)',[$uid,$wid,$name,$a['id'],$b['id'],json_encode($split)]);return ['experiment_id'=>(int)$this->pdo->lastInsertId(),'variant_a_recipients'=>count($split['a']),'variant_b_recipients'=>count($split['b']),'message'=>'Split saved. Neither campaign has been scheduled.'];
    }
    public function experiments(int $uid,int $wid): array
    {$this->owned($uid,$wid);$out=$this->q('SELECT id,name,state,campaign_a,campaign_b FROM wa_campaign_experiments WHERE user_id=? AND website_id=? ORDER BY id DESC LIMIT 50',[$uid,$wid])->fetchAll(PDO::FETCH_ASSOC);foreach($out as &$r){$r['a']=$this->analytics($uid,(int)$r['campaign_a']);$r['b']=$this->analytics($uid,(int)$r['campaign_b']);}return $out;}
    public function launchExperiment(int $uid,int $id,string $scheduled): array
    {
        $key='wa_experiment:'.$id;if(!ExternalExecution::lock($key))throw new RuntimeException('Experiment is already being scheduled');
        $tools=new WhatsAppCampaignTools($this->pdo);$started=[];
        try{$e=$this->q('SELECT * FROM wa_campaign_experiments WHERE id=? AND user_id=?',[$id,$uid])->fetch(PDO::FETCH_ASSOC);if(!$e)throw new InvalidArgumentException('Experiment not found');if($e['state']==='scheduled')return ['scheduled'=>true,'message'=>'Both variants have already been scheduled.'];if($e['state']!=='draft')throw new InvalidArgumentException('Cancel this incomplete experiment and prepare two new drafts');$ts=InternationalSettingsService::scheduledTimestamp($uid,$scheduled);if($ts<time()+3600)throw new InvalidArgumentException('Schedule an experiment at least one hour ahead so both variants can be prepared safely');$split=json_decode($e['split_json'],true,512,JSON_THROW_ON_ERROR);
            foreach(['a','b'] as $v){$cid=(int)$e['campaign_'.$v];if($tools->campaign($uid,$cid)['state']!=='draft')throw new InvalidArgumentException('Both variants must be unscheduled drafts');}
            $this->q("UPDATE wa_campaign_experiments SET state='preparing' WHERE id=? AND user_id=?",[$id,$uid]);
            foreach(['a','b'] as $v){$cid=(int)$e['campaign_'.$v];$tools->launchExperimentVariant($uid,$cid,$split[$v],$scheduled,$id);$started[]=$cid;}
            $this->q("UPDATE wa_campaign_experiments SET state='scheduled' WHERE id=? AND user_id=?",[$id,$uid]);
            return ['scheduled'=>true,'message'=>'Both variants scheduled for the same time.'];
        }catch(Throwable $error){$this->q("UPDATE wa_campaign_experiments SET state='failed' WHERE id=? AND user_id=? AND state='preparing'",[$id,$uid]);foreach($started as $cid){try{$tools->action($uid,$cid,'cancel');}catch(Throwable $ignored){throw new RuntimeException('Experiment could not be prepared. Pause its campaigns and inspect their states before retrying.');}}throw $error;}finally{ExternalExecution::unlock($key);}
    }
    public function cancelExperiment(int $uid,int $id): array
    {
        $key='wa_experiment:'.$id;if(!ExternalExecution::lock($key))throw new RuntimeException('Experiment is being prepared');try{$e=$this->q('SELECT * FROM wa_campaign_experiments WHERE id=? AND user_id=?',[$id,$uid])->fetch(PDO::FETCH_ASSOC);if(!$e)throw new InvalidArgumentException('Experiment not found');$tools=new WhatsAppCampaignTools($this->pdo);foreach(['a','b'] as $v){$cid=(int)$e['campaign_'.$v];$state=$tools->campaign($uid,$cid)['state'];if(!in_array($state,['completed','cancelled'],true))$tools->action($uid,$cid,'cancel');}$this->q("UPDATE wa_campaign_experiments SET state='cancelled' WHERE id=? AND user_id=?",[$id,$uid]);return ['cancelled'=>true];}finally{ExternalExecution::unlock($key);}
    }

}
