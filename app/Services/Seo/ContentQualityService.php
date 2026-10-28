<?php
/** Publication evidence and connector rules shared by manual, scheduled and SEO Expert publishing. */
class ContentQualityService
{
    public function __construct(private Database $db) {}
    public function ready(): bool
    {
        return (bool)$this->db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='seo_content_quality'");
    }
    public function knownPages(int $uid,int $wid): array
    {
        if (!$this->db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='seo_expert_runs'")) return [];
        $rows=$this->db->query("SELECT snapshot_json FROM seo_expert_runs WHERE website_id=? AND user_id=? AND snapshot_json IS NOT NULL ORDER BY id DESC LIMIT 1",[$wid,$uid]);
        if (!$rows) return [];
        $snapshot=json_decode($rows[0]['snapshot_json'],true)?:[];
        return array_values(array_filter($snapshot['pages']??[],static fn($p)=>!empty($p['url'])&&empty($p['error'])&&(!isset($p['ok'])||$p['ok'])));
    }
    public static function cluster(string $keyword,array $pages,string $siteUrl): ?array
    {
        $words=static function(string $s): array {preg_match_all('/[\p{L}\p{N}]{3,}/u',mb_strtolower($s),$m);return array_values(array_diff(array_unique($m[0]),['the','and','from','with','tour','tours','trip','trips','guide','best','رحلة','رحلات']));};
        $transportPattern='/\b(?:transfers?|transport(?:ation)?|shuttles?|airports?)\b|انتقالات|انتقال|توصيل|نقل|مطار/iu';
        $transportIntent=preg_match($transportPattern,$keyword)===1;
        $target=$words($keyword);$best=null;$score=0;$host=strtolower((string)parse_url($siteUrl,PHP_URL_HOST));
        foreach ($pages as $p) {
            if (preg_match('/\b(?:noindex|none)\b/i',(string)($p['robots']??''))) continue;
            $url=(string)($p['url']??$p['path']??'');
            if (str_starts_with($url,'/') && !str_starts_with($url,'//')) $url=rtrim($siteUrl,'/').$url;
            if (class_exists('SeoPagePolicy') && !SeoPagePolicy::classify(array_replace($p,['url'=>$url]))['eligible']) continue;
            if (!self::pagePath($url,$host) || !preg_match('~/(?:tours?|trips?|destinations?|locations?|excursions?|packages?)/~i',(string)parse_url($url,PHP_URL_PATH))) continue;
            // A transport category lists many cities and can falsely outscore a destination guide.
            // Keep it available for explicit transfer/airport intent, not general sightseeing content.
            if(!$transportIntent && preg_match($transportPattern,rawurldecode((string)parse_url($url,PHP_URL_PATH))))continue;
            $hits=count(array_intersect($target,$words(($p['title']??'').' '.rawurldecode((string)parse_url($url,PHP_URL_PATH)))));
            if ($hits>$score) {$score=$hits;$best=['url'=>$url,'title'=>mb_substr((string)($p['title']??$keyword),0,90)];}
        }
        return $best;
    }
    public static function pagePath(string $url,string $host): ?string
    {
        $p=parse_url($url);
        if (!$p || !in_array($p['scheme']??'', ['http','https'],true) || strtolower($p['host']??'')!==strtolower($host) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) return null;
        $path=$p['path']??'/';
        if (preg_match('/[\x00-\x20\\\\]/',$path) || preg_match('/%(?:2f|5c|00)/i',$path) || preg_match('~(?:^|/)\.\.?(?:/|$)~',rawurldecode($path))) return null;
        return rtrim($path,'/')?:'/';
    }
    /** Only the connector's exact article query is allowed; arbitrary query URLs remain forbidden. */
    public static function publicationPath(string $url,string $host): ?string
    {
        $path=self::pagePath($url,$host);
        if($path!==null)return $path;
        $p=parse_url($url);
        if(!$p||strtolower($p['host']??'')!==strtolower($host)||!in_array($p['scheme']??'',['https','http'],true)||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])||!in_array($p['path']??'/',['/','/index.php'],true))return null;
        if(!preg_match('/^tf_article=([a-z0-9][a-z0-9-]{0,120})$/D',$p['query']??'',$m))return null;
        return '/?tf_article='.$m[1];
    }
    public function remember(int $uid,int $wid,string $key,array $brief,array $data,?int $articleId=null,?int $taskId=null,array $pages=[],string $siteUrl=''): void
    {
        if (!$this->ready()) throw new RuntimeException('Apply the Phase 4 content quality migration first.');
        if (!$this->db->query('SELECT id FROM websites WHERE id=? AND user_id=?',[$wid,$uid])) throw new InvalidArgumentException('Website unavailable');
        $pillar=self::cluster((string)$brief['keyword'],$pages,$siteUrl);
        $this->db->exec('INSERT INTO seo_content_quality(website_id,user_id,source_key,article_id,task_id,brief_json,faqs_json,title,description,pillar_url,pillar_title,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE brief_json=VALUES(brief_json),faqs_json=VALUES(faqs_json),title=VALUES(title),description=VALUES(description),pillar_url=VALUES(pillar_url),pillar_title=VALUES(pillar_title),updated_at=UTC_TIMESTAMP()',
            [$wid,$uid,$key,$articleId,$taskId,json_encode($brief,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($data['faqs']??[],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$data['title'],$data['meta_description']??'',$pillar['url']??null,$pillar['title']??null]);
    }
    public function published(int $uid,int $wid,string $key,string $url,?string $content=null): void
    {
        $site=$this->db->query('SELECT `'.WebsiteSchema::url().'` AS main_url FROM websites WHERE id=? AND user_id=?',[$wid,$uid]);
        if (!$site || !self::publicationPath($url,(string)parse_url($site[0]['main_url'],PHP_URL_HOST))) throw new InvalidArgumentException('Publication URL must be a same-site page or an exact connector article URL.');
        if ($content!==null) {
            $rows=$this->db->query('SELECT faqs_json FROM seo_content_quality WHERE website_id=? AND user_id=? AND source_key=?',[$wid,$uid,$key]);
            if ($rows) $this->db->exec('UPDATE seo_content_quality SET faqs_json=? WHERE website_id=? AND user_id=? AND source_key=?',[json_encode(self::visibleFaqs(json_decode($rows[0]['faqs_json'],true)?:[],$content),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$wid,$uid,$key]);
        }
        $this->db->exec('UPDATE seo_content_quality SET page_url=?,published_at=COALESCE(published_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP() WHERE website_id=? AND user_id=? AND source_key=?',[$url,$wid,$uid,$key]);
    }
    public static function visibleFaqs(array $faqs,string $content): array
    {
        $normalize=static fn($s)=>trim(preg_replace('/\s+/u',' ',html_entity_decode(strip_tags($s),ENT_QUOTES|ENT_HTML5,'UTF-8')));
        $visible=$normalize($content);
        return array_values(array_filter($faqs,static fn($faq)=>is_array($faq)&&!empty($faq['question'])&&!empty($faq['answer'])&&str_contains($visible,$normalize($faq['question']))&&str_contains($visible,$normalize($faq['answer']))));
    }
    public static function schema(array $row): array
    {
        $graph=[['@type'=>'Article','@id'=>$row['page_url'].'#article','headline'=>$row['title'],'description'=>$row['description']??'', 'mainEntityOfPage'=>$row['page_url']]];
        if (!empty($row['published_at'])) $graph[0]['datePublished']=gmdate('c',strtotime($row['published_at'].' UTC'));
        $faqs=json_decode($row['faqs_json']??'[]',true)?:[];$questions=[];
        foreach (array_slice($faqs,0,8) as $f) if (is_array($f)&&!empty($f['question'])&&!empty($f['answer'])) $questions[]=['@type'=>'Question','name'=>strip_tags($f['question']),'acceptedAnswer'=>['@type'=>'Answer','text'=>strip_tags($f['answer'])]];
        if ($questions) $graph[]=['@type'=>'FAQPage','@id'=>$row['page_url'].'#faq','mainEntity'=>$questions];
        return ['@context'=>'https://schema.org','@graph'=>$graph];
    }
    /** Preserve prior schema nodes and links; connector supports four related links per page. */
    public static function rules(array $rows,string $host,array $existing=[]): array
    {
        $rules=$existing;
        foreach ($rows as $row) {
            $path=self::publicationPath((string)($row['page_url']??''),$host); if (!$path || empty($row['published_at'])) continue;
            $old=json_decode($rules[$path]['jsonld']??'{}',true)?:[];$graph=$old['@graph']??(isset($old['@type'])?[$old]:[]);
            $new=self::schema($row)['@graph'];$ids=array_column($new,'@id');
            $graph=array_values(array_filter($graph,static fn($node)=>!in_array($node['@id']??null,$ids,true)));
            $encoded=json_encode(['@context'=>'https://schema.org','@graph'=>array_merge($graph,$new)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if (strlen($encoded)<=20000) $rules[$path]['jsonld']=$encoded;
            $pillar=self::pagePath((string)($row['pillar_url']??''),$host);
            if (!$pillar || $pillar===$path) continue;
            foreach ([[$path,$row['pillar_url'],$row['pillar_title']],[$pillar,$row['page_url'],$row['title']]] as [$from,$url,$title]) {
                $links=$rules[$from]['related_links']??[];
                if (!in_array($url,array_column($links,'url'),true) && count($links)<4) $links[]=['url'=>$url,'title'=>mb_substr(strip_tags((string)$title),0,90)];
                $rules[$from]['related_links']=$links;
            }
        }
        return $rules;
    }
    public function assertCurrent(array $article,int $wid): void
    {
        if (!$this->ready()) return; // Legacy installations keep their existing publishing path.
        $uid=(int)$article['user_id'];
        $rows=$this->db->query('SELECT brief_json FROM seo_content_quality WHERE website_id=? AND user_id=? AND source_key=?',[$wid,$uid,'article:'.(int)$article['id']]);
        if (!$rows) return;
        $brief=json_decode($rows[0]['brief_json'],true,512,JSON_THROW_ON_ERROR);
        $profile=(new BusinessHubService($this->db))->profile($uid,$wid);
        if (!$profile || empty($profile['confirmed_at']) || !empty($profile['needs_reconfirmation']) || (int)$profile['version']!==(int)$brief['profile_version']) throw new RuntimeException('Customer facts changed. Rebuild and review the content brief before publishing.');
        $error=ArticleGenerator::validateBriefOutput(['content'=>$article['content']??'','faqs'=>json_decode($article['faqs_json']??'[]',true)?:[]],$brief);
        if ($error!==null) throw new RuntimeException($error);
    }
    /** Called inside the task-edit transaction; visible article and schema evidence change together. */
    public function editTaskDraft(int $uid,int $wid,array $task,array $input): void
    {
        if(!$this->ready())return;
        $rows=$this->db->query('SELECT * FROM seo_content_quality WHERE website_id=? AND user_id=? AND source_key=? FOR UPDATE',[$wid,$uid,'task:'.(int)$task['id']]);
        if(!$rows)return;
        $row=$rows[0];if(!empty($row['published_at']))throw new InvalidArgumentException('Published article evidence cannot be changed through a draft edit.');$brief=json_decode($row['brief_json'],true,512,JSON_THROW_ON_ERROR);
        $profile=(new BusinessHubService($this->db))->profile($uid,$wid);
        if(!$profile||empty($profile['confirmed_at'])||!empty($profile['needs_reconfirmation'])||(int)$profile['version']!==(int)$brief['profile_version'])throw new InvalidArgumentException('Customer facts changed. Review the content brief first.');
        $description=array_key_exists('meta_description',$input)?$input['meta_description']:($row['description']??'');
        if(!is_string($description)||mb_strlen($description)>500||preg_match('/[<>\x00-\x08\x0b\x0c\x0e-\x1f]/',$description))throw new InvalidArgumentException('Use a plain-text article description of at most 500 characters.');
        $faqs=array_key_exists('faqs',$input)?$input['faqs']:json_decode($row['faqs_json'],true,512,JSON_THROW_ON_ERROR);
        if(!is_array($faqs)||!array_is_list($faqs)||count($faqs)>8)throw new InvalidArgumentException('Use up to eight visible questions and answers.');
        $clean=[];foreach($faqs as $faq){
            if(!is_array($faq)||!is_string($faq['question']??null)||!is_string($faq['answer']??null))throw new InvalidArgumentException('Each FAQ needs a question and answer.');
            $question=trim($faq['question']);$answer=trim($faq['answer']);
            if($question===''||$answer===''||mb_strlen($question)>300||mb_strlen($answer)>2000||preg_match('/[<>\x00-\x08\x0b\x0c\x0e-\x1f]/',$question.$answer))throw new InvalidArgumentException('Use plain-text FAQ questions and answers within the field limits.');
            $clean[]=['question'=>$question,'answer'=>$answer];
        }
        if(count(self::visibleFaqs($clean,(string)$task['expected_value']))!==count($clean))throw new InvalidArgumentException('Every FAQ question and answer must also appear in the visible article body.');
        $error=ArticleGenerator::validateBriefOutput(['title'=>$task['title'],'meta_description'=>$description,'content'=>$task['expected_value'],'faqs'=>$clean],$brief);
        if($error!==null)throw new InvalidArgumentException($error);
        $this->db->exec('UPDATE seo_content_quality SET title=?,description=?,faqs_json=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?',[$task['title'],trim($description),json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$row['id'],$uid]);
    }
    public function assertTaskCurrent(int $uid,int $wid,array $task): void
    {
        require_once dirname(__DIR__).'/BusinessHub/BusinessHubService.php';
        require_once dirname(__DIR__).'/AI/ArticleGenerator.php';
        if(!$this->ready())return;
        $rows=$this->db->query('SELECT * FROM seo_content_quality WHERE website_id=? AND user_id=? AND source_key=?',[$wid,$uid,'task:'.(int)$task['id']]);
        if(!$rows)return; // Existing explicitly approved legacy drafts keep their publication path.
        $row=$rows[0];$brief=json_decode($row['brief_json'],true,512,JSON_THROW_ON_ERROR);
        $profile=(new BusinessHubService($this->db))->profile($uid,$wid);
        if(!$profile||empty($profile['confirmed_at'])||!empty($profile['needs_reconfirmation'])||(int)$profile['version']!==(int)$brief['profile_version'])throw new RuntimeException('Customer facts changed. Rebuild and review this article before publishing.');
        $error=ArticleGenerator::validateBriefOutput(['title'=>$task['title'],'meta_description'=>$row['description'],'content'=>$task['expected_value'],'faqs'=>json_decode($row['faqs_json'],true)?:[]],$brief);
        if($error!==null)throw new RuntimeException($error);
        $this->db->exec('UPDATE seo_content_quality SET title=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?',[$task['title'],$row['id'],$uid]);
    }
    public function syncWordPress(array $article,WordPressPublisher $publisher,string $siteUrl,string $username,string $password,int $postId,string $url,bool $draft=false): array
    {
        $wid=(int)($article['website_id']??0);$uid=(int)($article['user_id']??0);$key='article:'.(int)$article['id'];
        if (!$this->ready()) return ['success'=>false,'error'=>'Phase 4 migration required'];
        $rows=$this->db->query('SELECT * FROM seo_content_quality WHERE website_id=? AND user_id=? AND source_key=?',[$wid,$uid,$key]);
        if (!$rows) return ['success'=>true,'skipped'=>'legacy_article_without_brief'];
        $this->db->exec('UPDATE seo_content_quality SET title=?,description=? WHERE id=?',[(string)$article['title'],(string)($article['meta_description']??''),$rows[0]['id']]);
        $brief=json_decode($rows[0]['brief_json'],true,512,JSON_THROW_ON_ERROR);
        $r=$publisher->setSeoFields($siteUrl,$username,$password,$postId,['seo_title'=>(string)$article['title'],'seo_description'=>(string)($article['meta_description']??''),'seo_keyword'=>(string)$brief['keyword']]);
        $this->db->exec('UPDATE seo_content_quality SET seo_state=?,seo_error=?,updated_at=UTC_TIMESTAMP() WHERE id=?',[$r['success']?'applied':'needs_plugin_update',$r['success']?null:mb_substr($r['error']??'SEO metadata unavailable',0,500),$rows[0]['id']]);
        if (!$draft && $url!=='') $this->published($uid,$wid,$key,$url,(string)($article['content']??''));
        return $r;
    }
}
