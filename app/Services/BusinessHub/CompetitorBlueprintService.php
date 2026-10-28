<?php
require_once __DIR__.'/BusinessHubService.php';
require_once __DIR__.'/../SeoExpert/SeoExpertPageContext.php';
/** Read-only, bounded evidence collection; competitor facts never become client facts. */
class CompetitorBlueprintService
{
    public static function publicUrl(string $url): string{
        $url=trim($url);if(!preg_match('#^https?://#i',$url))$url='https://'.$url;
        $p=parse_url($url);if(!$p||!in_array($p['scheme']??'', ['http','https'],true)||empty($p['host'])||isset($p['user'])||isset($p['pass'])||!empty($p['query'])||!empty($p['fragment'])||strlen($url)>500)throw new InvalidArgumentException('Enter a public competitor URL without login details or query parameters');
        return $url;
    }
    public static function demo(array $page): bool{return (bool)preg_match('/travelerwp@gmail\.com|coming soon|something is happening|\(000\)\s*999/i',($page['title']??'').' '.($page['site_name']??'').' '.($page['text']??''));}
    public static function categories(array $pages): array{
        $found=[];$patterns=['tours'=>'#tour|excursion|رحلات|جولات#iu','packages'=>'#package|itinerar|برنامج|باقة#iu','cruises'=>'#cruise|nile|كروز|نيل#iu','transfers'=>'#transfer|airport|نقل|مطار#iu','faq'=>'#faq|frequently|الأسئلة#iu','about'=>'#about|من نحن#iu','contact'=>'#contact|اتصل#iu','policies'=>'#privacy|terms|cancel|refund|سياسة|شروط#iu'];
        foreach($pages as $page){$items=[['url'=>$page['final_url']??$page['url']??'','title'=>$page['title']??'']];foreach($page['links']??[] as $link)if(is_array($link))$items[]=$link;foreach($items as $item){$url=$item['url']??'';$p=parse_url($url);if(!$p||!in_array($p['scheme']??'', ['http','https'],true)||empty($p['host'])||strtolower($p['host'])!==strtolower((string)parse_url($page['final_url']??$page['url']??'',PHP_URL_HOST)))continue;$text=($p['path']??'').' '.($item['title']??$item['text']??'');foreach($patterns as $key=>$pattern)if(preg_match($pattern,$text))$found[$key][]=$url;}}
        foreach($found as &$urls)$urls=array_slice(array_values(array_unique($urls)),0,4);unset($urls);return $found;
    }
    public static function recommendations(array $observed,array $current,bool $demo): array{
        $definitions=[
            'home'=>['الصفحة الرئيسية','Home page','قدّم شركتك وخدماتك الفعلية في مصر وزر حجز واضح.','Introduce your actual Egypt services and a clear booking action.'],
            'tours'=>['صفحات الرحلات','Tour pages','لكل رحلة فعلية: برنامج، مدة، نقطة انطلاق، المشمول والمستثنى، وصور تملكها.','For each real tour: itinerary, duration, departure point, inclusions, exclusions and owned images.'],
            'packages'=>['الباقات والبرامج','Packages and itineraries','أضف البرامج التي تقدمها فعلًا، مع الأيام وتفاصيل الإقامة وشروط السعر.','List only packages you actually offer, with days, accommodation details and pricing conditions.'],
            'cruises'=>['رحلات النيل','Nile cruises','اسألك عن البرامج والسفن والتوافر الفعلي قبل كتابة هذه الصفحات.','Confirm actual programs, ships and availability before drafting these pages.'],
            'transfers'=>['النقل والاستقبال','Transfers','أكد المدن ونوع المركبات والسعة ومناطق الخدمة أولًا.','Confirm cities, vehicle types, capacity and service areas first.'],
            'about'=>['من نحن','About us','اكتب هوية شركتك ومعلومات خبرتك المؤكدة بدل بيانات المنافس.','Use your own identity and verified experience.'],
            'contact'=>['التواصل والحجز','Contact and booking','استخدم رقمك وبريدك ورابط الحجز الفعلي.','Use your own phone, email and actual booking link.'],
            'faq'=>['الأسئلة الشائعة','Frequently asked questions','أجوبة أصلية عن الحجز والمدة والإلغاء ومواعيد الاستقبال بناء على سياساتك.','Write original answers based on your own booking, timing and cancellation policies.'],
            'policies'=>['السياسات','Policies','قدم سياسة الإلغاء والخصوصية والدفع الخاصة بك؛ لا تنسخ سياسات المنافس.','Provide your own cancellation, privacy and payment policies.']
        ];$rows=[];foreach($definitions as $key=>$d){if(in_array($key,['packages','cruises','transfers'],true)&&empty($observed[$key]))continue;$rows[]=['key'=>$key,'title_ar'=>$d[0],'title_en'=>$d[1],'instructions_ar'=>$d[2],'instructions_en'=>$d[3],'state'=>$demo?'replace_demo':($key==='home'||!empty($current[$key])?'review_existing':'not_observed'),'conditional'=>in_array($key,['packages','cruises','transfers'],true),'evidence'=>$observed[$key]??[]];}return $rows;
    }
    public function scan(int $uid,int $wid,array $input): array{
        $hub=new BusinessHubService();$profile=$hub->profile($uid,$wid); // verifies ownership
        $targets=$input['competitors']??[];if(!is_array($targets)||count($targets)<1||count($targets)>3)throw new InvalidArgumentException('Provide one to three competitor URLs');
        $db=Database::getInstance();$rows=$db->query('SELECT * FROM websites WHERE id=? AND user_id=?',[$wid,$uid]);$site=$rows[0]??null;if(!$site)throw new InvalidArgumentException('Website unavailable');
        $siteUrl=self::publicUrl((string)($site[Website::urlColumn()]??$site['main_url']??''));$reader=new SeoExpertPageContext();$home=$reader->fresh($siteUrl);$results=[];$all=[];$seen=[];
        foreach($targets as $url){if(!is_string($url))throw new InvalidArgumentException('Invalid competitor URL');$url=self::publicUrl($url);$host=strtolower((string)parse_url($url,PHP_URL_HOST));if($host===strtolower((string)parse_url($siteUrl,PHP_URL_HOST)))throw new InvalidArgumentException('Competitor must be a different website');if(isset($seen[$host]))continue;$seen[$host]=true;
            try{$page=$reader->fresh($url);if(empty($page['ok'])||!WordPressConnector::hostsEqual($host,(string)parse_url($page['final_url']??'',PHP_URL_HOST)))throw new RuntimeException('Page unavailable or redirected to another host');$categories=self::categories([$page]);$results[]=['url'=>$url,'state'=>'inspected','categories'=>$categories,'checked_at'=>gmdate('c')];foreach($categories as $key=>$links)$all[$key]=array_values(array_unique(array_merge($all[$key]??[],$links)));}
            catch(Throwable $e){$results[]=['url'=>$url,'state'=>'unavailable','categories'=>[]];}
        }
        if(!array_filter($results,static fn($r)=>$r['state']==='inspected'))throw new InvalidArgumentException('Could not inspect any competitor. Check their public URLs and try again.');
        $facts=$profile['facts']??[];$questions=[];foreach(['business_name','business_facts','audience','goal','phone','email','logo_url','cta'] as $key)if(empty($facts[$key]))$questions[]=$key;
        $isDemo=self::demo($home);return ['competitors'=>$results,'site_state'=>empty($home['ok'])?'unavailable':($isDemo?'demo':'inspected'),'recommendations'=>self::recommendations($all,empty($home['ok'])?[]:self::categories([$home]),$isDemo),'questions'=>$questions,'read_only'=>true,'coverage'=>'Public home pages and their visible links only. Not a complete crawl; missing means not observed. No content is copied or published.','generated_at'=>gmdate('c')];
    }
}
