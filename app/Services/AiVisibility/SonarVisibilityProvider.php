<?php
final class SonarVisibilityProvider extends AiVisibilityProvider
{
    public function name(): string{return 'perplexity';}
    public function model(): string{return 'sonar';}
    protected function request(string $question,array $market): array
    {
        return ['https://api.perplexity.ai/v1/sonar',['Content-Type: application/json','Authorization: Bearer '.$this->config['key']],
            ['model'=>$this->model(),'messages'=>[['role'=>'user','content'=>$this->context($question,$market)]],'max_tokens'=>1200,'web_search_options'=>['search_context_size'=>'low']+(self::countryCode($market)?['user_location'=>['country'=>self::countryCode($market)]]:[])]];
    }
    public function parse(array $response): array
    {
        // Official compatibility route: synchronous Sonar requests can be reformulated as Agent API calls.
        // Do not price the returned model using the retired Sonar rate card.
        $choice=$response['choices'][0]??[];$usage=$response['usage']??[];$urls=$response['citations']??[];
        // Search results alone are retrieval, not proof that the answer cites every result.
        $citationKnown=array_key_exists('citations',$response);
        $cost=isset($usage['cost']['total_cost']) && is_numeric($usage['cost']['total_cost'])?(float)$usage['cost']['total_cost']:null;
        if ($cost!==null && (!is_finite($cost)||$cost<0)) $cost=null;
        return ['answer'=>(string)($choice['message']['content']??''),'cited_urls'=>$urls,'model'=>(string)($response['model']??$this->model()),
            'grounded'=>(array_key_exists('citations',$response)||array_key_exists('search_results',$response)) && ($choice['finish_reason']??'')==='stop',
            'citation_complete'=>$citationKnown,'cost'=>$cost,'cost_basis'=>$cost===null?'unknown_after_sonar_migration':'provider_reported','usage'=>$usage];
    }
}
