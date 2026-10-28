<?php
final class OpenAiVisibilityProvider extends AiVisibilityProvider
{
    public function name(): string{return 'openai';}
    public function model(): string{return 'gpt-4.1-mini';}
    protected function request(string $question,array $market): array
    {
        return ['https://api.openai.com/v1/responses',['Content-Type: application/json','Authorization: Bearer '.$this->config['key']],
            ['model'=>$this->model(),'input'=>$this->context($question,$market),'tools'=>[['type'=>'web_search','search_context_size'=>'low']+(self::countryCode($market)?['user_location'=>['type'=>'approximate','country'=>self::countryCode($market)]]:[])],
             'tool_choice'=>'required','max_tool_calls'=>1,'max_output_tokens'=>1200,'store'=>false]];
    }
    public function parse(array $response): array
    {
        $answer=[];$urls=[];$search=0;
        foreach ($response['output']??[] as $o) {
            if (($o['type']??'')==='web_search_call' && ($o['status']??'')==='completed') $search++;
            if (($o['type']??'')!=='message') continue;
            foreach ($o['content']??[] as $c) {
                if (($c['type']??'')==='output_text') $answer[]=(string)($c['text']??'');
                foreach ($c['annotations']??[] as $a) if (($a['type']??'')==='url_citation' && is_string($a['url']??null)) $urls[]=$a['url'];
            }
        }
        $usage=$response['usage']??[];
        // GPT-4.1-mini web search: tool charge plus fixed 8K search-content input block per call.
        $cost=self::cost($usage,'input_tokens','output_tokens',0.40,1.60,$search*(0.010+8000*0.40/1000000));
        return ['answer'=>implode("\n",$answer),'cited_urls'=>$urls,'model'=>(string)($response['model']??$this->model()),'grounded'=>$search>0 && ($response['status']??'')==='completed','cost'=>$cost,'usage'=>$usage+['search_calls'=>$search]];
    }
}
