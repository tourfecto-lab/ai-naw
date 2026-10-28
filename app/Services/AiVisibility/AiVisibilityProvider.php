<?php
/** Isolated measurement clients: no orchestration fallback, no branded context, no automatic retries. */
abstract class AiVisibilityProvider
{
    protected array $config;
    protected $transport;
    abstract public function name(): string;
    abstract public function model(): string;
    abstract protected function request(string $question,array $market): array;
    abstract public function parse(array $response): array;
    public function __construct(?array $config=null,?callable $transport=null)
    {
        if ($config===null) {
            $s=new SystemSettingsService();$name=$this->name();
            $key=$s->get('geo_'.$name.'_api_key');
            if ($key==='' && in_array($name,['openai','gemini'],true) && class_exists('AiServiceRouting')) $key=AiServiceRouting::apiKey($name);
            $config=['enabled'=>$s->get('geo_enabled'),'provider_enabled'=>$s->get('geo_'.$name.'_enabled'),'key'=>$key];
        }
        $this->config=$config;$this->transport=$transport;
    }
    public function configured(): bool { return ($this->config['enabled']??'')==='1' && ($this->config['provider_enabled']??'')==='1' && trim((string)($this->config['key']??''))!==''; }
    public function ask(string $question,array $market): array
    {
        if (!$this->configured()) return ['available'=>false,'reason'=>'not_measured','cost'=>null];
        if (mb_strlen($question)>300 || !preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D',(string)($market['language_code']??''))) throw new InvalidArgumentException('Invalid measurement request');
        [$url,$headers,$payload]=$this->request($question,$market);
        try {
            if ($this->transport) $body=($this->transport)($url,$headers,$payload);
            else {
                $raw='';$ch=curl_init($url);
                curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_WRITEFUNCTION=>static function($ch,$chunk)use(&$raw){$raw.=$chunk;return strlen($raw)>2000000?0:strlen($chunk);},CURLOPT_TIMEOUT=>90,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
                $executed=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
                if ($executed===false || $code!==200) return ['available'=>false,'reason'=>'provider_request_failed','cost'=>null];
                $body=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
            }
            if (!is_array($body)) throw new RuntimeException('Malformed response');
            $r=$this->parse($body);
            if (empty($r['grounded']) || trim((string)($r['answer']??''))==='') return ['available'=>false,'reason'=>'grounded_answer_unavailable','cost'=>$r['cost']??null,'cost_basis'=>$r['cost_basis']??'unknown'];
            $r['cited_urls']=array_values(array_unique(array_filter(array_map([AiVisibilityDetector::class,'safeUrl'],$r['cited_urls']??[]))));
            return $r+['available'=>true,'provider'=>$this->name(),'model'=>$this->model(),'cost_basis'=>'estimated_list_price_reported_usage'];
        } catch (Throwable $e) { return ['available'=>false,'reason'=>'provider_response_unavailable','cost'=>null]; }
    }
    /** كود السوق (DataForSEO) => [اسم الدولة، كود الدولة]: الإجابة بتتقاس كأن المسافر بيسأل من جوه بلده */
    public const LOCATIONS=[2840=>['United States','US'],2826=>['United Kingdom','GB'],2124=>['Canada','CA'],2036=>['Australia','AU'],2372=>['Ireland','IE'],2554=>['New Zealand','NZ'],2356=>['India','IN'],2710=>['South Africa','ZA'],2702=>['Singapore','SG'],2276=>['Germany','DE'],2040=>['Austria','AT'],2756=>['Switzerland','CH'],2250=>['France','FR'],2056=>['Belgium','BE'],2528=>['Netherlands','NL'],2442=>['Luxembourg','LU'],2724=>['Spain','ES'],2620=>['Portugal','PT'],2380=>['Italy','IT'],2300=>['Greece','GR'],2616=>['Poland','PL'],2203=>['Czechia','CZ'],2348=>['Hungary','HU'],2642=>['Romania','RO'],2752=>['Sweden','SE'],2578=>['Norway','NO'],2208=>['Denmark','DK'],2246=>['Finland','FI'],2643=>['Russia','RU'],2804=>['Ukraine','UA'],2792=>['Turkey','TR'],2376=>['Israel','IL'],2682=>['Saudi Arabia','SA'],2784=>['United Arab Emirates','AE'],2414=>['Kuwait','KW'],2634=>['Qatar','QA'],2048=>['Bahrain','BH'],2512=>['Oman','OM'],2400=>['Jordan','JO'],2818=>['Egypt','EG'],2504=>['Morocco','MA'],2156=>['China','CN'],2344=>['Hong Kong','HK'],2158=>['Taiwan','TW'],2392=>['Japan','JP'],2410=>['South Korea','KR'],2458=>['Malaysia','MY'],2360=>['Indonesia','ID'],2764=>['Thailand','TH'],2704=>['Vietnam','VN'],2608=>['Philippines','PH'],2076=>['Brazil','BR'],2484=>['Mexico','MX'],2032=>['Argentina','AR'],2170=>['Colombia','CO'],2152=>['Chile','CL']];
    public static function countryCode(array $market): ?string{return self::LOCATIONS[(int)($market['location_code']??0)][1]??null;}
    protected function context(string $question,array $market): string
    {
        // Deliberately no customer identity, domain, competitors or company facts.
        $countries=array_map(static fn($c)=>$c[0],self::LOCATIONS);
        $country=$countries[(int)($market['location_code']??0)]??('DataForSEO location code '.(int)($market['location_code']??0));
        return 'Answer this traveler question using web search and cite sources. Do not invent prices or reviews. Reply in language '.$market['language_code'].'. Traveler market: '.$country.'. Question: '.$question;
    }
    protected static function cost(array $usage,string $input,string $output,float $inRate,float $outRate,float $search): ?float
    {
        if (!isset($usage[$input],$usage[$output]) || !is_numeric($usage[$input]) || !is_numeric($usage[$output]) || $usage[$input]<0 || $usage[$output]<0) return null;
        return round(($usage[$input]*$inRate+$usage[$output]*$outRate)/1000000+$search,6);
    }
}
