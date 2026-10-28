<?php
/** Customer API projection: internal configuration and provider spending never leave the server. */
final class AiVisibilityPresentation
{
    private const LABELS=['openai'=>'ChatGPT','gemini'=>'Gemini','perplexity'=>'Perplexity','claude'=>'Claude'];
    private static function objectArray($value): array
    {
        if(is_string($value))$value=json_decode($value,true);
        return is_array($value)?$value:[];
    }
    private static function rows($value): array
    {
        $rows=[];
        foreach(self::objectArray($value) as $row){
            $row=self::objectArray($row);
            if($row!==[])$rows[]=$row;
        }
        return $rows;
    }
    public static function customer(array $data): array
    {
        foreach(['metrics','trend','answers','questions','question_sets','tasks','markets'] as $key)$data[$key]=self::rows($data[$key]??[]);
        $data['providers']=self::objectArray($data['providers']??[]);
        $out=array_intersect_key($data,array_flip(['state','markets','question_sets','questions','metrics','trend','aliases','profile_urls','identity_version','position_basis']));
        $projectMetric=static fn($m)=>array_intersect_key($m,array_flip(['provider','market_id','questions','mentions','citations','citation_questions','share_of_voice','citation_rate','competitors']));
        $out['metrics']=array_map($projectMetric,$data['metrics']??[]);
        $out['trend']=array_map(static fn($w)=>['week'=>$w['week']??'','providers'=>array_map($projectMetric,self::rows($w['providers']??[]))],$data['trend']??[]);
        $out['setup']='أكد بيانات شركتك، واختار السوق، وبعدها راجع أسئلة عملائك ووافق على الأسئلة اللي تحب تتابع ظهور شركتك فيها. النتائج هتظهر بعد أول قياس.';
        $out['providers']=[];foreach(array_unique(array_merge(array_keys($data['providers']??[]),array_column($data['metrics']??[],'provider'))) as $provider)if(isset(self::LABELS[$provider]))$out['providers'][$provider]=['label'=>self::LABELS[$provider]];
        $out['answers']=array_map(static fn($row)=>array_intersect_key($row,array_flip(['provider','market_id','question','mentioned_brand','cited_domain','brand_position','cited_urls','competitors_mentioned','answer_excerpt','checked_at'])), $data['answers']??[]);
        $out['questions']=array_map(static fn($row)=>array_intersect_key($row,array_flip(['id','market_id','question','approved'])), $data['questions']??[]);
        $out['tasks']=array_map(static function($row){$safe=array_intersect_key($row,array_flip(['title','instructions','status']));$e=self::objectArray($row['evidence_json']??[]);$safe['evidence_json']=json_encode(array_intersect_key($e,array_flip(['url','customer_url','kind','checked_at','observed_differences'])),JSON_UNESCAPED_UNICODE);return $safe;},$data['tasks']??[]);
        return $out;
    }
}
