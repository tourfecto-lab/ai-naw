<?php
require_once __DIR__.'/SeoVerificationState.php';
/** Observable publication quality, separate from the dated proof that approved text is live. */
final class SeoPublicationAudit
{
    public static function inspect(array $task,array $ctx,array $quality,array $sitemap): array
    {
        $url=(string)$task['published_url'];$normalize=static fn($s)=>SeoVerificationState::content((string)$s);
        $checks=[];$pass=static fn(bool $ok):string=>$ok?'passed':'missing';
        $expected=SeoVerificationState::content((string)($task['expected_value']??''),true);
        preg_match_all('/<a href="([^"]+)">/',GuideContent::html((string)($task['expected_value']??'')),$inline);
        $expectedLinks=array_unique(array_map(static fn($url)=>html_entity_decode($url,ENT_QUOTES|ENT_HTML5,'UTF-8'),$inline[1]));
        $checks['inline_links']=$expectedLinks?$pass(count(array_diff($expectedLinks,$ctx['publication_links']??[]))===0):'not_required';
        $checks['approved_content']=$pass($expected!==''&&str_contains($normalize($ctx['text']??''),$expected));
        $checks['title']=$pass($normalize($task['title'])!==''&&str_contains($normalize($ctx['title']??''),$normalize($task['title'])));
        $description=(string)($quality['description']??'');$checks['meta_description']=$description!==''?$pass($normalize($ctx['description']??'')===$normalize($description)):'not_required';
        $checks['canonical']=$pass(html_entity_decode((string)($ctx['canonical']??''),ENT_QUOTES|ENT_HTML5,'UTF-8')===$url);
        $checks['indexable']=empty($ctx['ok'])?'not_measured':$pass(!preg_match('/\b(?:noindex|none)\b/i',(string)($ctx['robots']??'').' '.($ctx['robots_header']??'')));
        $nodes=[];foreach($ctx['schema']??[] as $schema){if(!is_array($schema))continue;foreach($schema['@graph']??(array_is_list($schema)?$schema:[$schema]) as $node)if(is_array($node))$nodes[]=$node;}
        $article=false;$faqNodes=[];
        foreach($nodes as $node){
            if(array_intersect(['Article','BlogPosting','NewsArticle'],(array)($node['@type']??[]))){
                $entity=$node['mainEntityOfPage']??'';$entity=is_array($entity)?($entity['@id']??''):$entity;
                if($entity===$url&&$normalize($node['headline']??'')===$normalize($task['title']))$article=true;
            }
            if(in_array('FAQPage',(array)($node['@type']??[]),true))foreach($node['mainEntity']??[] as $q)if(is_array($q))$faqNodes[]=$q;
        }
        $checks['article_schema']=$pass($article&&empty($ctx['invalid_schema']));
        $checks['site_theme']=!empty($task['external_post_id'])?'not_required':$pass(in_array($ctx['guide_layout']??'',['site','configured'],true));
        $checks['headings']=$pass(count($ctx['headings']??[])>0);
        $faqs=json_decode($quality['faqs_json']??'[]',true)?:[];$allFaqs=true;
        foreach($faqs as $faq){
            $q=$normalize($faq['question']??'');$a=$normalize($faq['answer']??'');$found=false;
            foreach($faqNodes as $node)if($q!==''&&$a!==''&&$normalize($node['name']??'')===$q&&$normalize($node['acceptedAnswer']['text']??'')===$a)$found=true;
            if(!$found||!str_contains($normalize($ctx['text']??''),$q)||!str_contains($normalize($ctx['text']??''),$a))$allFaqs=false;
        }
        $checks['faq_schema']=$faqs?$pass($allFaqs):'not_required';
        $related=$ctx['tf_related']??[];$pillar=(string)($quality['pillar_url']??'');
        $checks['internal_links']=$pillar!==''?$pass(in_array($pillar,$related,true)):$pass(count($related)>0);
        $targets=$pillar!==''?[$pillar]:array_slice($related,0,4);$linkStates=[];
        foreach($targets as $target)$linkStates[]=$ctx['related_link_checks'][$target]??'not_measured';
        $checks['link_targets']=!$targets?'not_required':(in_array('missing',$linkStates,true)?'missing':(in_array('not_measured',$linkStates,true)?'not_measured':'passed'));
        $checks['sitemap']=$sitemap['state']??'not_measured';
        return ['state'=>count(array_diff($checks,['passed','not_required']))===0?'complete':'needs_review','checks'=>$checks,'sitemap_url'=>$sitemap['url']??null];
    }
}
