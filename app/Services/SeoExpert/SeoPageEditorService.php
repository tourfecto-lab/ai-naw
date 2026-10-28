<?php
/** Site-bound authenticated relay. WordPress retains the durable edit/restore history. */
final class SeoPageEditorService {
    private Database $db;
    public function __construct(?Database $db=null) { $this->db=$db??Database::getInstance(); }
    public function call(int $uid,int $wid,string $op,array $input): array {
        if(!in_array($op,['list','read','resolve','preview','apply','history','restore','capabilities','block-preview','traveler-preview','workspace-preview','verify','status','tour-calendar-read','tour-create-preview','tour-create','tour-create-status','tour-settings-read','tour-settings-preview','tour-settings-apply','tour-settings-status','tour-settings-history','tour-settings-restore'],true))throw new InvalidArgumentException('Unsupported editor operation.');
        $rows=$this->db->query('SELECT * FROM websites WHERE id=? AND user_id=?',[$wid,$uid]);$site=$rows[0]??null;
        if(!$site)throw new InvalidArgumentException('Website unavailable.');
        $rows=$this->db->query("SELECT * FROM platform_connections WHERE user_id=? AND website_id=? AND platform='wordpress' AND status='connected' ORDER BY id DESC LIMIT 1",[$uid,$wid]);$c=$rows[0]??null;
        if(!$c)throw new InvalidArgumentException('Connect WordPress publishing for this website first.');
        $target=rtrim((string)$c['external_location_id'],'/');$url=(string)($site[Website::urlColumn()]??$site['main_url']??'');
        if(!preg_match('#^https?://#i',$url))$url='https://'.$url;
        if(!str_starts_with($target,'https://') || !WordPressConnector::hostsEqual((string)parse_url($target,PHP_URL_HOST),(string)parse_url($url,PHP_URL_HOST)) || parse_url($target,PHP_URL_USER) || parse_url($target,PHP_URL_QUERY))throw new InvalidArgumentException('WordPress publishing target does not match this website.');
        $credentials=(new Encryption())->decrypt($c['access_token']);if(!str_contains($credentials,':'))throw new InvalidArgumentException('WordPress credentials unavailable.');
        $payload=$input;unset($payload['csrf_token']);
        if(in_array($op,['preview','workspace-preview','block-preview','traveler-preview','apply','restore','tour-create-preview','tour-create','tour-settings-preview','tour-settings-apply','tour-settings-restore'],true)) {
            if(!ExternalExecution::lock('seo-expert-site:'.$wid))throw new InvalidArgumentException('SEO operation is running. Try again after it finishes.');
            try {
                if($op==='restore') {
                    $result=$this->request($target,$credentials,$op,$payload);
                    $this->checkRules($wid,'apply',['preview_token'=>$result['preview_token']??'']);
                    return $result;
                }
                $this->checkRules($wid,$op,$payload);$result=$this->request($target,$credentials,$op,$payload);
                if($op==='apply'&&($result['state']??'')==='saved'&&($result['page']['status']??'publish')==='publish') {
                    try{require_once __DIR__.'/../SeoImpact/SeoImpactService.php';(new SeoImpactService($this->db))->recordEditorChange($uid,$wid,$result);$result['measurement_note']='Stored edit event and automatic 7/14/28-day impact monitors recorded when measurement SQL is installed.';}
                    catch(Throwable $e){$result['measurement_note']='Page saved; measurement-event recording is unavailable.';}
                }
                return $result;
            }
            finally { ExternalExecution::unlock('seo-expert-site:'.$wid); }
        }
        if($op==='verify'){
            $result=$this->request($target,$credentials,$op,$payload);
            require_once __DIR__.'/SeoEditorVerification.php';
            $result['verification']=(new SeoEditorVerification())->check($target,$result);unset($result['expectations']);return $result;
        }
        return $this->request($target,$credentials,$op,$payload);
    }
    private function checkRules(int $wid,string $op,array $input): void {
        // Preview/apply/restore require SEO metadata to have a single writer.
        // Block overlapping legacy rules conservatively; content-only edits are unaffected.
        $fields=array_keys(is_array($input['changes']??null)?$input['changes']:[]);
        if($op==='apply') {
            $raw=explode('.',(string)($input['preview_token']??''))[0];
            $plan=json_decode((string)base64_decode(strtr($raw,'-_','+/'),true),true);
            $fields=is_array($plan['fields']??null)?$plan['fields']:[];
            // This decoded data only decides whether to block. WordPress verifies the signature.
        }
        if(!array_intersect($fields,['seo_title','seo_description']))return;
        $exists=$this->db->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('seo_expert_tasks','auto_seo_applied_fixes')");
        foreach($exists as $r) {
            $table=$r['table_name']??$r['TABLE_NAME']??'';
            $sql=$table==='seo_expert_tasks'?"SELECT id FROM seo_expert_tasks WHERE website_id=? AND kind='metadata' AND is_active=1 AND field_name IN ('title','description') LIMIT 1":($table==='auto_seo_applied_fixes'?"SELECT id FROM auto_seo_applied_fixes WHERE website_id=? AND is_active=1 AND field_name IN ('title','description') LIMIT 1":null);
            if($sql && $this->db->query($sql,[$wid]))throw new InvalidArgumentException('Existing Tourfecto title/description rules are active. Revert those rules before editing SEO plugin fields to avoid conflicting writers. Content-only edits can continue.');
        }
    }
    private function request(string $target,string $credentials,string $op,array $payload): array {
        $body='';$r=SafeHttp::exec($target.'/wp-json/tourfecto-editor/v1/'.$op,[
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>'POST',CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>45,CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/json','Authorization: Basic '.base64_encode($credentials)],
            CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR),
            CURLOPT_WRITEFUNCTION=>static function($c,$chunk) use (&$body) { $body.=$chunk;return strlen($body)>1500000?0:strlen($chunk); }
        ],0);
        if($r['error']!=='' || (int)$r['code']>=500)throw new RuntimeException($op==='apply'?'Save outcome is unknown. Read the page and its history before attempting another edit.':'WordPress editor temporarily unavailable.');
        $j=json_decode($body,true);
        if((int)$r['code']===404)throw new InvalidArgumentException('Install or update Tourfecto Connect on this WordPress site.');
        if((int)$r['code']<200 || (int)$r['code']>=300 || !is_array($j))throw new InvalidArgumentException(is_string($j['message']??null)?$j['message']:'WordPress rejected this operation. Review permissions and plugin activation.');
        $home=(string)($j['site_url']??'');
        if(!WordPressConnector::hostsEqual((string)parse_url($target,PHP_URL_HOST),(string)parse_url($home,PHP_URL_HOST)) || rtrim((string)parse_url($home,PHP_URL_PATH),'/')!==rtrim((string)parse_url($target,PHP_URL_PATH),'/'))throw new RuntimeException('WordPress site identity response mismatch.');
        return $j;
    }
}
