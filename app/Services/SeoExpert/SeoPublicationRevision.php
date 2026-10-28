<?php
require_once __DIR__.'/SeoClaimEvidence.php';
/** Same-URL revisions of connector-owned articles; no provider publication or AI call. */
final class SeoPublicationRevision
{
    public static function eligible(array $task,string $site): bool
    {
        if(($task['kind']??'')!=='content'||empty($task['is_active'])||!empty($task['external_post_id'])||!in_array($task['status']??'',['active','verified','needs_client'],true))return false;
        $p=parse_url((string)($task['published_url']??''));$s=parse_url($site);
        if(!$p||!$s||($p['scheme']??'')!==($s['scheme']??'')||strtolower($p['host']??'')!==strtolower($s['host']??'')||($p['port']??null)!==($s['port']??null)||isset($p['user'])||isset($p['pass'])||isset($p['fragment']))return false;
        return (!isset($p['query'])&&preg_match('~^/guides/[a-z0-9][a-z0-9-]{0,120}$~D',$p['path']??''))
            ||(in_array($p['path']??'/',['/','/index.php'],true)&&preg_match('/^tf_article=[a-z0-9][a-z0-9-]{0,120}$/D',$p['query']??''));
    }
    public static function hash(array $data): string {return hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}
    private static function key(): string {
        if(!defined('ENCRYPTION_KEY')||strlen((string)ENCRYPTION_KEY)<16)throw new RuntimeException('Configure the application signing key before publication revisions.');
        return hash_hmac('sha256','tourfecto:publication-revision:v1',(string)ENCRYPTION_KEY,true);
    }
    public static function token(array $binding,?int $now=null): string {
        $payload=rtrim(strtr(base64_encode(json_encode($binding+['expires'=>($now??time())+900],JSON_THROW_ON_ERROR)),'+/','-_'),'=');
        return $payload.'.'.hash_hmac('sha256',$payload,self::key());
    }
    public static function verify(string $token,array $binding,?int $now=null): void {
        $parts=explode('.',$token);$now??=time();
        if(count($parts)!==2||strlen($token)>2048||!hash_equals(hash_hmac('sha256',$parts[0],self::key()),$parts[1]))throw new InvalidArgumentException('Revision preview is invalid. Preview again.');
        $data=json_decode((string)base64_decode(strtr($parts[0],'-_','+/'),true),true);
        if(!is_array($data)||($data['expires']??0)<=$now||($data['expires']??0)>$now+900||array_diff_assoc($binding,$data))throw new InvalidArgumentException('Revision preview expired or the article changed. Preview again.');
    }
    public static function prepare(array $task,array $snapshot,array $quality,array $input): array {
        $value=$input['value']??null;
        if(!is_string($value)||mb_strlen(trim($value))<150||mb_strlen($value)>9000||preg_match('/<[^>]+>|[\x00-\x08\x0b\x0c\x0e-\x1f]/u',$value))throw new InvalidArgumentException('Use 150–9000 characters of plain text or Markdown for the revised article.');
        $value=trim($value);$oldFaqs=json_decode($quality['faqs_json']??'[]',true)?:[];
        $faqs=ContentQualityService::visibleFaqs($oldFaqs,$value);
        $description=trim((string)($input['meta_description']??$quality['description']??''));
        if(!$quality)$description=mb_substr(trim(preg_replace('/\s+/u',' ',preg_replace('/\*\*/','',$value))),0,155);
        if(mb_strlen($description)>500||preg_match('/[<>\x00-\x08\x0b\x0c\x0e-\x1f]/',$description))throw new InvalidArgumentException('Use a plain-text article description.');
        $brief=$quality?json_decode($quality['brief_json'],true,512,JSON_THROW_ON_ERROR):['keyword'=>$task['title'],'profile_version'=>$snapshot['brief']['shared_profile']['version']??0,'confirmed_facts'=>SeoClaimEvidence::snapshotFacts($snapshot),'revision_source'=>'existing_publication_not_new_keyword_research','verified_internal_urls'=>array_values(array_unique(array_merge(array_column($snapshot['pages']??[],'url'),[(string)($snapshot['brief']['source_site_url']??'')])) )];
        $error=ArticleGenerator::validateBriefOutput(['title'=>$task['title'],'content'=>$value,'meta_description'=>$description,'faqs'=>$faqs],$brief);
        if($error!==null)throw new InvalidArgumentException($error);
        if($value===$task['expected_value']&&$description===($quality['description']??$description)&&$faqs===$oldFaqs)throw new InvalidArgumentException('The article has no changes to preview.');
        return ['value'=>$value,'meta_description'=>$description,'faqs'=>$faqs,'removed_faqs'=>count($oldFaqs)-count($faqs),'brief'=>$brief];
    }
}
