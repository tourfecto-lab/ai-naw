<?php
/** Rates use completed grounded answers, never failed calls or zero-test denominators. */
final class AiVisibilityMetrics
{
    public static function summarize(array $rows,int $expected=0,?string $now=null): array
    {
        $today=new DateTimeImmutable($now??'now',new DateTimeZone('UTC'));$monday=$today->modify('monday this week')->format('Y-m-d');
        $groups=[];$seen=[];
        foreach ($rows as $r) {
            $p=$r['provider'];$week=$r['week_start'];$key=$p.'|'.$week.'|'.($r['question_hash']??hash('sha256',$r['question']));
            if (isset($seen[$key])) continue;$seen[$key]=true;
            $g=$groups[$p][$week]??['questions'=>0,'mentions'=>0,'citations'=>0,'citation_questions'=>0,'competitors'=>[],'set_versions'=>[]];$g['questions']++;
            $g['mentions']+=(int)(bool)$r['mentioned_brand'];$g['citations']+=(int)(bool)$r['cited_domain'];if ($r['cited_domain']!==null) $g['citation_questions']++;$g['set_versions'][(string)($r['set_version']??1)]=true;
            if (!$r['mentioned_brand']) foreach (is_string($r['competitors_mentioned'])?json_decode($r['competitors_mentioned'],true):$r['competitors_mentioned'] as $c) {
                $name=$c['name']??$c['domain'];$g['competitors'][$name]=($g['competitors'][$name]??0)+1;
            }
            $groups[$p][$week]=$g;
        }
        $providers=[];
        foreach (['openai','gemini','perplexity','claude'] as $p) {
            $weeks=$groups[$p]??[]; krsort($weeks);$latest=$weeks?array_key_first($weeks):null;$last=$latest?$weeks[$latest]:null;$trend=[];
            for ($i=7;$i>=0;$i--) {
                $week=(new DateTimeImmutable($monday,new DateTimeZone('UTC')))->modify('-'.$i.' weeks')->format('Y-m-d');$g=$weeks[$week]??null;
                $trend[]=['week'=>$week,'questions'=>$g['questions']??0,'share_of_voice'=>$g?$g['mentions']/$g['questions']:null,'citation_rate'=>$g && $g['citation_questions']?$g['citations']/$g['citation_questions']:null,'set_versions'=>$g?array_keys($g['set_versions']):[]];
            }
            $competitors=$last['competitors']??[];arsort($competitors);
            $providers[$p]=['state'=>$last?'measured_sample':'not_measured','week_start'=>$latest,'stale'=>$latest!==null&&$latest!==$monday,'questions'=>$last['questions']??0,'expected_questions'=>$expected,
                'partial'=>$last && $expected>0 && $last['questions']<$expected,'share_of_voice'=>$last?$last['mentions']/$last['questions']:null,'citation_rate'=>$last && $last['citation_questions']?$last['citations']/$last['citation_questions']:null,
                'citation_questions'=>$last['citation_questions']??0,'top_competitors'=>$competitors,'trend'=>$trend,'sample_note'=>'API samples with web search; not consumer app rankings. Changes in question cohorts are not controlled experiments.'];
        }
        return ['state'=>$rows?'measured_sample':'not_measured','providers'=>$providers,'position_basis'=>'first mention among configured entities'];
    }
}
