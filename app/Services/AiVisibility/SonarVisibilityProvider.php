<?php
/** Perplexity measurement via the Agent API (/v1/agent): the old /v1/sonar route returned 403 for new accounts. */
final class SonarVisibilityProvider extends AiVisibilityProvider
{
    public function name(): string{return 'perplexity';}
    public function model(): string{return 'perplexity-agent-fast';}
    protected function request(string $question,array $market): array
    {
        $search=['type'=>'web_search','max_tokens'=>4000,'max_tokens_per_page'=>1000];
        if (self::countryCode($market)) $search['user_location']=['country'=>self::countryCode($market)];
        return ['https://api.perplexity.ai/v1/agent',['Content-Type: application/json','Authorization: Bearer '.$this->config['key']],
            ['effort'=>'fast','input'=>$this->context($question,$market),'max_output_tokens'=>1200,'tools'=>[$search]]];
    }
    public function parse(array $response): array
    {
        $text=[];$byId=[];$cited=[];$hasResults=false;
        foreach ($response['output']??[] as $item) {
            $type=$item['type']??'';
            if ($type==='message') foreach ($item['content']??[] as $part) {
                if (($part['type']??'')!=='output_text') continue;
                $text[]=(string)($part['text']??'');
                foreach ($part['annotations']??[] as $a) if (($a['type']??'')==='url_citation' && is_string($a['url']??null)) $cited[]=$a['url'];
            }
            if ($type==='search_results') { $hasResults=true;foreach ($item['results']??[] as $r) if (isset($r['id'],$r['url'])) $byId[(int)$r['id']]=(string)$r['url']; }
        }
        $answer=implode("\n",$text);
        // لو مفيش annotations، علامات [n] داخل النص بتشاور على نتائج البحث؛ نقتبس اللي اتذكر فعلًا بس (مش كل النتائج).
        if (!$cited && preg_match_all('/\[(\d{1,3})\]/',$answer,$m)) foreach (array_unique($m[1]) as $n) if (isset($byId[(int)$n])) $cited[]=$byId[(int)$n];
        $usage=is_array($response['usage']??null)?$response['usage']:[];
        $cost=isset($usage['cost']['total_cost']) && is_numeric($usage['cost']['total_cost'])?(float)$usage['cost']['total_cost']:null;
        if ($cost!==null && (!is_finite($cost)||$cost<0)) $cost=null;
        return ['answer'=>$answer,'cited_urls'=>array_values(array_unique($cited)),'model'=>(string)($response['model']??$this->model()),
            'grounded'=>($response['status']??'')==='completed' && $hasResults,
            'citation_complete'=>$hasResults,'cost'=>$cost,'cost_basis'=>$cost===null?'unknown':'provider_reported','usage'=>$usage];
    }
}
