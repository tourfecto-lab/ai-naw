<?php
/** Claude with server-side web search. Same isolated measurement contract as the other providers. */
final class ClaudeVisibilityProvider extends AiVisibilityProvider
{
    /** USD per million tokens [input, output]; unknown models keep cost unknown instead of guessing. */
    private const RATES=['claude-opus-5-5'=>[4.00,20.00],'claude-opus-5'=>[5.00,25.00],'claude-sonnet-5-5'=>[2.00,10.00],'claude-haiku-5-5'=>[0.10,0.50],'claude-fable-5-1'=>[10.00,50.00]];
    /** Models that accept the server-side refusal fallback ("default" routing). */
    private const FALLBACK_MODELS=['claude-opus-5-5','claude-opus-5','claude-sonnet-5-5','claude-fable-5-1'];
    private const SEARCH_PRICE=0.010;
    public function __construct(?array $config=null,?callable $transport=null)
    {
        if ($config===null) {
            $s=new SystemSettingsService();
            // The platform's encrypted Anthropic integration key; GEO stores only the switch and model.
            $key=class_exists('AiServiceRouting')?AiServiceRouting::apiKey('anthropic'):'';
            $config=['enabled'=>$s->get('geo_enabled'),'provider_enabled'=>$s->get('geo_claude_enabled'),'key'=>$key,'model'=>$s->get('geo_claude_model')];
        }
        parent::__construct($config,$transport);
    }
    public function name(): string{return 'claude';}
    public function model(): string
    {
        $m=trim((string)($this->config['model']??''));
        return preg_match('/^claude-[a-z0-9-]{3,60}$/D',$m)?$m:'claude-opus-5-5';
    }
    protected function request(string $question,array $market): array
    {
        $model=$this->model();
        // Dynamic-filtering search needs a 4.6+ Opus/Sonnet; Haiku keeps the basic tool version.
        $tool=str_starts_with($model,'claude-haiku')?'web_search_20250305':'web_search_20260209';
        $headers=['Content-Type: application/json','x-api-key: '.$this->config['key'],'anthropic-version: 2023-06-01'];
        $payload=['model'=>$model,'max_tokens'=>4000,'messages'=>[['role'=>'user','content'=>$this->context($question,$market)]],
            'tools'=>[['type'=>$tool,'name'=>'web_search','max_uses'=>1]]];
        if (in_array($model,self::FALLBACK_MODELS,true)) { $headers[]='anthropic-beta: server-side-fallback-2026-07-01';$payload['fallbacks']='default'; }
        return ['https://api.anthropic.com/v1/messages',$headers,$payload];
    }
    public function parse(array $response): array
    {
        $answer=[];$urls=[];
        foreach ($response['content']??[] as $block) {
            if (($block['type']??'')!=='text') continue;
            $answer[]=(string)($block['text']??'');
            foreach ($block['citations']??[] as $c) if (($c['type']??'')==='web_search_result_location' && is_string($c['url']??null)) $urls[]=$c['url'];
        }
        $usage=$response['usage']??[];$search=(int)($usage['server_tool_use']['web_search_requests']??0);
        $model=(string)($response['model']??$this->model());$rate=self::RATES[$model]??null;
        $billed=$usage;
        if (isset($usage['input_tokens'])) $billed['input_all']=$usage['input_tokens']+($usage['cache_creation_input_tokens']??0)+($usage['cache_read_input_tokens']??0);
        $cost=$rate?self::cost($billed,'input_all','output_tokens',$rate[0],$rate[1],$search*self::SEARCH_PRICE):null;
        // A refusal, a paused server loop or a truncated answer is not a completed grounded sample.
        return ['answer'=>implode("\n",$answer),'cited_urls'=>$urls,'model'=>$model,'grounded'=>$search>0 && ($response['stop_reason']??'')==='end_turn',
            'cost'=>$cost,'cost_basis'=>$cost===null?'unknown_model_rate':'estimated_list_price_reported_usage','usage'=>$usage+['search_calls'=>$search]];
    }
}
