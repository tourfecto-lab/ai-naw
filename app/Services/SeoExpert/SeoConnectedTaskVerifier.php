<?php
require_once __DIR__.'/SeoPagePolicy.php';
/** Read-only Google observations, scoped to the task's website and property. */
final class SeoConnectedTaskVerifier
{
    public static function kind(array $task): ?string {
        if(($task['kind']??'')!=='manual'||!in_array($task['status']??'',['pending','needs_client','verified'],true))return null;
        $field=$task['field_name']??'';$title=(string)($task['title']??'');
        if($field==='gsc_sitemap'||in_array($title,['Submit your sitemap in Search Console','سجّل خريطة الموقع (sitemap) في Search Console'],true))return 'sitemap';
        if(($field==='gsc_indexing'||str_starts_with($title,'Not indexed: ')||str_starts_with($title,'مش ظاهرة في جوجل: '))&&SeoPagePolicy::route((string)($task['page_url']??''))['eligible'])return 'indexing';
        return null;
    }
    private static function url(string $url): ?array {
        $p=parse_url($url);
        return $p&&in_array($p['scheme']??'',['https','http'],true)&&!empty($p['host'])&&!isset($p['user'])&&!isset($p['pass'])&&!isset($p['fragment'])&&!preg_match('/[\x00-\x20\\\\]/',$url)?$p:null;
    }
    public static function covers(string $site,string $property,string $url): bool {
        $s=self::url($site);$u=self::url($url);if(!$s||!$u)return false;
        if(strtolower(preg_replace('/^www\./i','',$s['host']))!==strtolower(preg_replace('/^www\./i','',$u['host'])))return false;
        if(($s['port']??($s['scheme']==='https'?443:80))!==($u['port']??($u['scheme']==='https'?443:80)))return false;
        if(str_starts_with($property,'sc-domain:')){$domain=strtolower(substr($property,10));$host=strtolower($u['host']);return $domain!==''&&($host===$domain||str_ends_with($host,'.'.$domain));}
        $p=self::url($property);if(!$p||isset($p['query'])||$p['scheme']!==$u['scheme']||strtolower($p['host'])!==strtolower($u['host'])||($p['port']??($p['scheme']==='https'?443:80))!==($u['port']??($u['scheme']==='https'?443:80)))return false;
        $prefix=rtrim($p['path']??'/','/');$path=$u['path']??'/';return $prefix===''||$path===$prefix||str_starts_with($path,$prefix.'/');
    }
    public static function check(array $task,string $site,$api,string $property): array {
        $kind=self::kind($task);$out=['kind'=>$kind,'state'=>'not_measured','reason_code'=>'connection_unavailable','reason'=>'Google check unavailable. Reconnect Search Console and retry. / تعذر فحص Google؛ راجع الربط وأعد المحاولة.','checked_at'=>gmdate('c'),'observed'=>[]];
        if(!$kind){$out['reason_code']='unsupported_task';return $out;}
        $url=(string)($task['page_url']??'');
        if($kind==='sitemap'){$url=(string)($task['expected_value']??'');if($url===''&&preg_match('~(?:URL|الرابط):\s*(https?://[^\s<>]+)~u',(string)($task['instructions']??''),$m))$url=$m[1];}
        if(!$api)return $out;
        if(!self::covers($site,$property,$url)){$out['reason_code']='property_mismatch';$out['reason']='Task URL is outside the connected website/property. / الرابط خارج الموقع أو خاصية Google المتصلة.';return $out;}
        try {
            if($kind==='sitemap'){
                $r=$api->listSitemaps($property);if(empty($r['ok']))return $out;
                $found=null;foreach($r['sitemaps']??[] as $map)if(($map['path']??'')===$url){$found=$map;break;}
                $out['observed']=$found?array_intersect_key($found,array_flip(['path','errors','warnings','pending','last_downloaded'])):['path'=>$url,'listed'=>false];
                $verified=$found!==null&&(int)($found['errors']??0)===0;
                $out['state']=$verified?'verified':'needs_review';$out['reason_code']=$verified?'sitemap_submitted':($found?'sitemap_errors':'sitemap_not_listed');
                $out['reason']=$verified?'Google lists this exact sitemap with no reported errors. Submission is confirmed; page indexing is not guaranteed. / Google يسجل الخريطة دون أخطاء معلنة؛ هذا يؤكد التسجيل ولا يضمن فهرسة الصفحات.':($found?'Google reports sitemap errors. / Google يبلغ عن أخطاء في الخريطة.':'Google does not list this sitemap. / الخريطة غير مسجلة في نتيجة Google الحالية.');
            }else{
                $r=$api->inspectUrl($property,$url);if(empty($r['ok']))return $out;
                if(!in_array($r['verdict']??'',['PASS','FAIL','NEUTRAL'],true))return $out;
                $out['observed']=array_intersect_key($r,array_flip(['url','indexed','verdict','coverage','reason','last_crawl','google_canonical','user_canonical','robots','fetch']));
                $out['state']=!empty($r['indexed'])?'verified':'needs_review';$out['reason_code']=!empty($r['indexed'])?'indexed':(($r['reason']??'')?:'not_indexed');
                $out['reason']=!empty($r['indexed'])?'Google currently reports this URL indexed. / Google يبلغ حاليًا أن الرابط مفهرس.':'Google has not confirmed this URL indexed. See its current coverage and last crawl below. / Google لم يؤكد فهرسة الرابط؛ راجع الحالة وآخر زحف أدناه.';
            }
        }catch(Throwable $e){return $out;}
        return $out;
    }
}
