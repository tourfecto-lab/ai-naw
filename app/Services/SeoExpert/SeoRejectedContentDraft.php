<?php
/** Only a rejected, unpublished draft already saved in this same owned plan can be reopened. */
final class SeoRejectedContentDraft
{
    public static function candidate(array $task,array $plan,array $brief): ?array
    {
        if(($task['kind']??'')!=='manual'||($task['title']??'')!=='Confirm article facts before writing'||($task['status']??'')!=='needs_client'||!empty($task['field_name'])||!empty($task['is_active'])||!empty($task['published_url'])||!empty($task['external_post_id'])||empty($brief['keyword']))return null;
        foreach($plan['content_drafts']??[] as $draft){
            if(!is_array($draft)||!is_string($draft['title']??null)||!is_string($draft['body']??null)||!str_ends_with((string)($task['instructions']??''),'Review the draft topic: '.$draft['title']))continue;
            if(mb_strlen($draft['title'])<10||mb_strlen($draft['title'])>150||mb_strlen($draft['body'])<150||mb_strlen($draft['body'])>9000||!is_string($draft['meta_description']??'' )||mb_strlen($draft['meta_description']??'')>500)continue;
            $faqs=[];foreach(array_slice(is_array($draft['faqs']??null)?$draft['faqs']:[],0,8) as $faq)if(is_array($faq)&&is_string($faq['question']??null)&&is_string($faq['answer']??null))$faqs[]=['question'=>strip_tags($faq['question']),'answer'=>strip_tags($faq['answer'])];
            return ['title'=>$draft['title'],'body'=>$draft['body'],'meta_description'=>$draft['meta_description']??'','faqs'=>$faqs,'category'=>in_array($draft['category']??'', ['aeo','geo'],true)?$draft['category']:'aeo'];
        }
        return null;
    }
    public static function preview(array $draft,array $brief): array
    {
        $parts=['title'=>$draft['title'],'meta_description'=>$draft['meta_description'],'content'=>$draft['body']];foreach($draft['faqs'] as $i=>$faq)$parts['faq_answer_'.$i]=$faq['answer'];
        return ['title'=>$draft['title'],'validation_error'=>ArticleGenerator::validateBriefOutput(['title'=>$draft['title'],'meta_description'=>$draft['meta_description'],'content'=>$draft['body'],'faqs'=>$draft['faqs']],$brief),'claim_review'=>SeoClaimEvidence::review($parts,$brief['confirmed_facts']??[])];
    }
}
