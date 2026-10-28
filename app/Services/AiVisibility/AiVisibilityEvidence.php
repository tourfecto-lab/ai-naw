<?php
/** Read-only, bounded public evidence. Page content never becomes an instruction or an automatic edit. */
class AiVisibilityEvidence
{
    private $transport; private array $cache=[]; private ?float $deadline=null;
    public function beginBudget(int $seconds): void{$this->deadline=microtime(true)+max(1,$seconds);}
    public function __construct(?callable $transport=null){$this->transport=$transport;}
    public function page(string $url): array
    {
        if(isset($this->cache[$url]))return $this->cache[$url];
        if($this->deadline!==null&&microtime(true)>$this->deadline)return ['ok'=>false,'url'=>$url];
        if(!AiVisibilityDetector::safeUrl($url))return ['ok'=>false,'url'=>$url];
        try {
            if($this->transport)$r=($this->transport)($url,'GET');
            else {
                $body='';$mime='';$r=SafeHttp::exec($url,[CURLOPT_TIMEOUT=>6,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_ENCODING=>'',CURLOPT_USERAGENT=>'TourfectoEvidence/1.0',CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml'],CURLOPT_HEADERFUNCTION=>static function($c,$line)use(&$body,&$mime){if(preg_match('#^HTTP/#i',$line)){$body='';$mime='';}elseif(stripos($line,'Content-Type:')===0)$mime=trim(substr($line,13));return strlen($line);},CURLOPT_WRITEFUNCTION=>static function($c,$chunk)use(&$body){$body.=$chunk;return strlen($body)>1000000?0:strlen($chunk);}],2);$r['body']=$body;if($mime!==''&&!preg_match('#^text/html|^application/xhtml\+xml#i',$mime))return ['ok'=>false,'url'=>$url];
            }
            if(($r['code']??0)!==200 || !empty($r['error']) || strlen($r['body']??'')>1000000)return ['ok'=>false,'url'=>$url];
            $final=$r['url']??$url;if(!AiVisibilityDetector::safeUrl($final))return ['ok'=>false,'url'=>$url];
            return $this->cache[$url]=self::parse($r['body'],$final);
        } catch(Throwable $e){return ['ok'=>false,'url'=>$url];}
    }
    public static function parse(string $html,string $url): array
    {
        $doc=new DOMDocument();$old=libxml_use_internal_errors(true);
        try{$ok=$doc->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}finally{libxml_clear_errors();libxml_use_internal_errors($old);}
        if(!$ok)return ['ok'=>false,'url'=>$url];$xp=new DOMXPath($doc);$schema=[];
        foreach($xp->query('//script[@type="application/ld+json"]') as $s){$v=json_decode($s->textContent,true);if(is_array($v))$schema[]=$v;}
        foreach($xp->query('//script|//style|//noscript|//nav') as $n)$n->parentNode->removeChild($n);
        $text=trim(preg_replace('/\s+/u',' ',$doc->textContent));$title=trim($xp->evaluate('string(//title)'));$desc=trim($xp->evaluate('string(//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]/@content)'));
        $prices=[];preg_match_all('/(?:(?:from\s+|من\s+)?(?:[$€£]|USD|EUR|GBP|EGP)\s?\d[\d,.]*(?:\s?(?:per person|per adult|للشخص))?|\d[\d,.]*\s?(?:USD|EUR|GBP|EGP|دولار|جنيه|يورو)\b)/ui',$text,$m);$prices=array_slice(array_values(array_unique($m[0])),0,8);
        $links=[];foreach($xp->query('//a[@href]') as $a){$href=html_entity_decode($a->getAttribute('href'),ENT_QUOTES,'UTF-8');$absolute=self::absolute($url,$href);if($absolute && AiVisibilityDetector::safeUrl($absolute))$links[]=['url'=>$absolute,'label'=>trim(preg_replace('/\s+/u',' ',$a->textContent))];}
        $emails=[];$phones=[];foreach($xp->query('//a[@href]') as $a){$h=$a->getAttribute('href');if(str_starts_with($h,'mailto:'))$emails[]=explode('?',substr($h,7))[0];if(str_starts_with($h,'tel:'))$phones[]=substr($h,4);}
        $json=json_encode($schema,JSON_UNESCAPED_UNICODE);$lang=trim($xp->evaluate('string(//html/@lang)'));$languages=$lang!==''?[$lang]:[];
        foreach($xp->query('//link[@rel="alternate"][@hreflang]') as $alternate){$code=$alternate->getAttribute('hreflang');if(preg_match('/^[a-z]{2,3}(?:-[A-Za-z]{2,4})?$/D',$code))$languages[]=$code;}
        $languages=array_slice(array_values(array_unique($languages)),0,20);
        return ['ok'=>true,'url'=>$url,'h1'=>trim($xp->evaluate('string(//h1)')),'title'=>mb_substr($title,0,250),'description'=>mb_substr($desc,0,400),'text'=>mb_substr($text,0,30000),'prices'=>$prices,'schema'=>$schema,'has_schema'=>(bool)$schema,'has_faq'=>stripos($json,'FAQPage')!==false||preg_match('/\bFAQ\b|أسئلة شائعة|الأسئلة الشائعة/iu',$text)===1,'has_reviews'=>stripos($json,'aggregateRating')!==false||stripos($json,'"Review"')!==false,'links'=>array_slice($links,0,150),'phone'=>array_values(array_unique($phones)),'email'=>array_values(array_unique($emails)),'languages'=>$languages,'fetched_at'=>gmdate('c')];
    }
    private static function absolute(string $base,string $path): ?string
    {
        if(preg_match('#^https?://#i',$path))return $path;
        if($path===''||$path[0]==='#'||preg_match('#^[a-z]+:#i',$path))return null;
        $p=parse_url($base);$origin=$p['scheme'].'://'.$p['host'].(isset($p['port'])?':'.$p['port']:'');
        if(str_starts_with($path,'//'))return $p['scheme'].':'.$path;
        return $origin.($path[0]==='/'?$path:rtrim(dirname($p['path']??'/'),'/').'/'.$path);
    }
    public function resolveCitations(array $urls): array
    {
        $out=[];$complete=true;$resolutions=0;$deadline=microtime(true)+20;
        foreach(array_slice($urls,0,30) as $url){
            if(!is_string($url)||!AiVisibilityDetector::safeUrl($url)){$complete=false;continue;}
            $host=AiVisibilityDetector::host($url);
            if(in_array($host,['vertexaisearch.cloud.google.com','vertexaisearch.cloud.google.com.'],true)){
                if($resolutions>=5||microtime(true)>$deadline){$complete=false;$out[]=$url;continue;}$resolutions++;
                try{
                    $r=$this->transport?($this->transport)($url,'HEAD'):SafeHttp::exec($url,[CURLOPT_NOBODY=>true,CURLOPT_TIMEOUT=>4,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2],2);
                    $final=$r['url']??'';
                    if(($r['code']??0)<200||($r['code']??0)>=400||!empty($r['error'])||!AiVisibilityDetector::safeUrl($final)||AiVisibilityDetector::host($final)===$host){$complete=false;$out[]=$url;continue;}
                    $url=$final;
                }catch(Throwable $e){$complete=false;$out[]=$url;continue;}
            }
            $out[]=$url;
        }
        if(count($urls)>30)$complete=false;
        return [array_values(array_unique($out)),$complete];
    }
    public function tasks(array $context,array $answers,array $competitors): array
    {
        $this->beginBudget(45);$tasks=[];$own=$this->page($context['url']);$fetched=0;$customerPages=0;$seen=[];
        foreach($answers as $answer){$urls=json_decode($answer['cited_urls']??'[]',true)?:[];
            foreach($urls as $url){if(!is_string($url)||isset($seen[$url]))continue;$seen[$url]=true;$host=AiVisibilityDetector::host($url);$competitor=null;
                foreach($competitors as $c)if(DataForSeoRankingSource::matchesHost($url,$c['domain'])){$competitor=$c;break;}
                $third=null;foreach(['tripadvisor.com','getyourguide.com','viator.com','google.com','maps.google.com'] as $domain)if($host===$domain||str_ends_with($host,'.'.$domain)){$third=$domain;break;}
                if($third&&!empty(json_decode($answer['competitors_mentioned']??'[]',true))){$known=false;foreach($context['profiles'] as $profile)if(AiVisibilityDetector::host($profile)===$host)$known=true;
                    if(!$known)$tasks[]=$this->task('third_party_presence','راجع حضور الشركة على '.$third,'ظهر المصدر ده في إجابة فعلية. راجع هل الصفحة بتذكر منافس، وهل عندك ملف حقيقي ومعلومات ومراجعات عملاء على المنصة. أضف رابط شركتك بنفسك بعد التحقق؛ لا ننشئ مراجعات أو ندّعي إن حسابك مش موجود.',['source_url'=>$url,'provider'=>$answer['provider'],'checked_at'=>$answer['checked_at'],'question'=>$answer['question'],'competitors_mentioned'=>json_decode($answer['competitors_mentioned']??'[]',true)]);
                }
                if(!$competitor||$fetched>=5||empty($own['ok']))continue;$fetched++;$page=$this->page($url);if(empty($page['ok']))continue;
                $customer=$own;
                if($customerPages<3){$target=$this->relatedCustomerUrl($own,$answer['question']);if($target){$customerPages++;$candidate=$this->page($target);if(!empty($candidate['ok'])&&DataForSeoRankingSource::matchesHost($candidate['url'],$context['url']))$customer=$candidate;}}
                $missing=[];foreach(['prices'=>'أسعار منشورة','has_faq'=>'أسئلة وإجابات','has_schema'=>'بيانات منظمة','has_reviews'=>'ترميز مراجعات'] as $key=>$label)if(!empty($page[$key])&&empty($customer[$key]))$missing[]=$label;
                foreach(['pickup'=>'/pickup|pick.up|الانتقال|التوصيل/iu','cancellation'=>'/cancell?ation|refund|إلغاء|استرداد/iu','duration'=>'/\b\d+\s*(?:hours?|days?|nights?)\b|المدة|ساعات|أيام/iu','inclusions'=>'/inclusions?|included|يشمل|شامل/iu'] as $key=>$pattern)if(preg_match($pattern,$page['text'])&&!preg_match($pattern,$customer['text']))$missing[]=$key;
                $facts=trim((string)($context['facts']['business_facts']??'').' '.(string)($context['facts']['offers']??''));$unobserved=[];
                foreach(preg_split('/[\n;]+/u',$facts) as $fact)if(mb_strlen(trim($fact))>=12&&!str_contains(AiVisibilityDetector::normalize($customer['text']),AiVisibilityDetector::normalize(trim($fact))))$unobserved[]=mb_substr(trim($fact),0,400);
                $tasks[]=$this->task('cited_page_comparison','راجع صفحة '.$competitor['name'].' اللي اتذكرت كمصدر','قارن الصفحة المذكورة بصفحة خدمتك المناسبة. '.($missing?'في العينة: صفحة المنافس فيها '.implode('، ',$missing).' ولم نرصدها في صفحة العميل المأخوذة كعينة. ':'راجع وضوح تفاصيل الخدمة وشروطها والأسئلة الشرائية. ').'راجع كمان الحقائق المؤكدة المرفقة اللي لم نرصد نصها في العينة. أكملها بعد التأكد من ارتباطها بالرحلة دي، وانشر الأسعار والشروط الحقيقية فقط. المراجعات والترميز لازم يطابقوا محتوى ظاهر. المقارنة محدودة بالصفحتين؛ لا تثبت غياب البيانات من الموقع كله.',['source_url'=>$url,'customer_url'=>$customer['url'],'provider'=>$answer['provider'],'question'=>$answer['question'],'checked_at'=>$answer['checked_at'],'fetched_at'=>$page['fetched_at'],'missing_in_customer_sample'=>$missing,'confirmed_fact_text_not_observed'=>array_slice($unobserved,0,5),'competitor_excerpt'=>mb_substr($page['text'],0,1600),'customer_excerpt'=>mb_substr($customer['text'],0,1200),'confirmed_facts'=>mb_substr($facts,0,1600),'review'=>'Confirm missing itinerary, duration, inclusions, pickup, price conditions and cancellation facts for the same service. Text matching is not semantic proof of absence. Do not copy competitor claims.']);
            }
        }
        if(!empty($own['ok']) && !empty($context['profiles'])){
            foreach(array_slice($context['profiles'],0,3) as $url){$p=$this->page($url);if(empty($p['ok']))continue;$expected=['name'=>$context['facts']['business_name'],'phone'=>$context['facts']['phone']??'','address'=>$context['entity']['address']??''];$checks=[];
                foreach($expected as $key=>$v)if($v!==''){$needle=$key==='phone'?preg_replace('/\D/','',$v):AiVisibilityDetector::normalize($v);$hay=$key==='phone'?preg_replace('/\D/','',$p['text']):AiVisibilityDetector::normalize($p['text']);$checks[$key]=['confirmed'=>$v,'seen_in_public_sample'=>$needle!==''&&str_contains($hay,$needle)];}
                $tasks[]=$this->task('entity_consistency','راجع تطابق بيانات الشركة وحساباتها','طابق الاسم ورقم التليفون والعنوان المؤكد مع النص الظاهر في الموقع والملف. عدم رصد المعلومة في عينة عامة لا يثبت أنها خطأ. استخدم روابط الحسابات اللي أدخلتها بنفسك في Organization sameAs بعد مراجعتها.',['source_url'=>$url,'customer_url'=>$own['url'],'profile_checks'=>$checks,'site_checks'=>$this->identityChecks($expected,$own['text']),'fetched_at'=>$p['fetched_at']]);
            }
        }
        $unique=[];foreach($tasks as $t)$unique[$t['evidence_hash']]=$t;return array_slice(array_values($unique),0,30);
    }
    private function relatedCustomerUrl(array $home,string $question): ?string
    {
        $words=array_filter(explode(' ',AiVisibilityDetector::normalize($question)),fn($w)=>mb_strlen($w)>=4&&!in_array($w,['what','should','before','booking','included','where','which','offer','tours','tour','from','price','prices','traveler'],true));$best=null;$score=0;
        foreach($home['links']??[] as $link){if(!DataForSeoRankingSource::matchesHost($link['url'],$home['url'])||!preg_match('#/(?:tours?|trips?|excursions?|cruises?)/[^/?]+#i',parse_url($link['url'],PHP_URL_PATH)??''))continue;$text=AiVisibilityDetector::normalize($link['label'].' '.urldecode($link['url']));$n=0;foreach($words as $w)if(str_contains($text,$w))$n++;if($n>$score){$score=$n;$best=$link['url'];}}
        return $score>0?$best:null;
    }
    private function identityChecks(array $expected,string $text): array{$out=[];foreach($expected as $key=>$v)if($v!=='')$out[$key]=['confirmed'=>$v,'seen_in_public_sample'=>$key==='phone'?str_contains(preg_replace('/\D/','',$text),preg_replace('/\D/','',$v)):str_contains(AiVisibilityDetector::normalize($text),AiVisibilityDetector::normalize($v))];return $out;}
    private function task(string $kind,string $title,string $instructions,array $evidence): array{return ['kind'=>$kind,'title'=>$title,'instructions'=>$instructions,'evidence'=>$evidence,'evidence_hash'=>hash('sha256',$kind.'|'.($evidence['source_url']??'').'|'.($evidence['question']??''))];}
}
