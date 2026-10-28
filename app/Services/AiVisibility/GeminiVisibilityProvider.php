<?php
final class GeminiVisibilityProvider extends AiVisibilityProvider
{
    public function name(): string{return 'gemini';}
    public function model(): string{return 'gemini-2.5-flash';}
    protected function request(string $question,array $market): array
    {
        return ['https://generativelanguage.googleapis.com/v1beta/models/'.$this->model().':generateContent',
            ['Content-Type: application/json','x-goog-api-key: '.$this->config['key']],
            ['contents'=>[['role'=>'user','parts'=>[['text'=>$this->context($question,$market)]]]],'tools'=>[['google_search'=>(object)[]]],
             'generationConfig'=>['maxOutputTokens'=>1200,'temperature'=>0.2,'thinkingConfig'=>['thinkingBudget'=>0]]]];
    }
    public function parse(array $response): array
    {
        $c=$response['candidates'][0]??[];$g=$c['groundingMetadata']??[];$urls=[];$text=[];
        foreach ($c['content']['parts']??[] as $p) if (isset($p['text']) && empty($p['thought'])) $text[]=$p['text'];
        $indices=[];foreach ($g['groundingSupports']??[] as $support) foreach ($support['groundingChunkIndices']??[] as $index) $indices[(int)$index]=true;
        foreach (array_keys($indices) as $index) { $uri=$g['groundingChunks'][$index]['web']['uri']??null;if(is_string($uri))$urls[]=$uri; }
        $grounded=!empty($g['webSearchQueries']) || !empty($g['groundingChunks']);$usage=$response['usageMetadata']??[];
        $billed=$usage;
        if (isset($usage['candidatesTokenCount'])) $billed['output_with_thoughts']=$usage['candidatesTokenCount']+($usage['thoughtsTokenCount']??0);
        return ['answer'=>implode("\n",$text),'cited_urls'=>$urls,'model'=>(string)($response['modelVersion']??$this->model()),'grounded'=>$grounded && ($c['finishReason']??'')==='STOP',
            'cost'=>self::cost($billed,'promptTokenCount','output_with_thoughts',0.30,2.50,$grounded?0.035:0),'usage'=>$usage+['grounded_queries'=>count($g['webSearchQueries']??[])]];
    }
}
