<?php
/** Factual draft only; publication still uses the existing approved, reversible connector flow. */
class GeoLlmsBuilder
{
    private AiVisibilityEvidence $evidence;
    public function __construct(?AiVisibilityEvidence $evidence=null){$this->evidence=$evidence??new AiVisibilityEvidence();}
    public function build(string $url): string
    {
        $this->evidence->beginBudget(60);$home=$this->evidence->page($url);if(empty($home['ok']))throw new RuntimeException('تعذر قراءة الصفحة العامة؛ لم يتم إنشاء معلومات بديلة');
        // رد فاضي من أول طلب (كاش على الموقع أو الـCDN): نعيد مرة واحدة برابط فريد زي قارئ السيو (_tourfecto_audit) وبنشيله من الناتج
        if(self::hollow($home)){
            $fresh=$this->evidence->page($url.(str_contains($url,'?')?'&':'?').'_tourfecto_audit='.bin2hex(random_bytes(8)));
            if(!empty($fresh['ok'])&&!self::hollow($fresh)){$fresh['url']=$url;$home=$fresh;}
        }
        // صفحة اتقرت (200) لكن من غير عنوان ولا وصف ولا روابط: غالبًا الموقع بيرجّع صفحة تحقق/حجب للزاحف أو المحتوى بيتحمّل بجافاسكريبت. مسودة فاضية تضلّل العميل
        if(self::hollow($home))throw new RuntimeException('الصفحة الرئيسية رجعت من غير عنوان ولا وصف ولا روابط؛ لم يتم إنشاء مسودة (تشخيص: '.(int)($home['bytes']??0).' بايت، '.preg_replace('/[^a-z0-9\/+.\-]/i','',(string)($home['mime']??'')).'، '.preg_replace('/[^a-z0-9.\-]/i','',(string)(parse_url((string)($home['url']??''),PHP_URL_HOST)?:'')).', length '.preg_replace('/[^0-9]/','',(string)($home['clen']??'')).', encoding '.preg_replace('/[^a-z0-9\-]/i','',(string)($home['cenc']??'')).', redirects '.(int)($home['redirects']??0).', headers '.(int)($home['header_size']??0).')');
        $lines=['# '.self::line($home['title']?:AiVisibilityDetector::host($url)),''];
        // الصفحة الرئيسية من غير وصف: نسيب الملخص بره بدل سطر "> " فاضي (مش صيغة صحيحة لـ llms.txt ومفيش معلومات نختلقها)
        if(self::line($home['description'])!=='')array_push($lines,'> '.self::line($home['description']),'');
        array_push($lines,'## Website','- '.$home['url']);
        if($home['languages'])$lines[]='- Published page language: '.implode(', ',$home['languages']);
        foreach(['phone'=>'Phone','email'=>'Email'] as $key=>$label)foreach($home[$key] as $value)$lines[]='- '.$label.': '.self::line($value);
        $trips=[];$seen=[];
        foreach($home['links'] as $link){$target=$link['url'];if(count($trips)>=8)break;
            if(!DataForSeoRankingSource::matchesHost($target,$url)||isset($seen[$target])||!preg_match('#(?:tour|trip|excursion|cruise|رحل)#iu',parse_url($target,PHP_URL_PATH)??''))continue;$seen[$target]=true;
            $p=$this->evidence->page($target);if(empty($p['ok'])||!SeoSchemaBuilder::isTourPage($p)||!DataForSeoRankingSource::matchesHost($p['url'],$url)||$p['url']===$home['url']||$p['description']==='')continue;
            $trips[]='- ['.self::line($p['title']?:$link['label']).']('.$p['url'].'): '.self::line($p['description']).(($price=self::price($p))!==''?' | Published price: '.$price:'');
        }
        if($trips)$lines=array_merge($lines,['','## Published trip pages'],$trips);
        $lines=array_merge($lines,['','## Notes','Content reflects public page text fetched '.gmdate('Y-m-d').'. Confirm details on the linked pages. This file makes no ranking or citation guarantee.']);
        return mb_substr(implode("\n",$lines),0,19000);
    }
    /**
     * سعر الرحلة نفسها بس. الأفضل سعر العرض في البيانات المنظمة (Offer/AggregateOffer)؛ لو مفيش، أول سعر في النص بشرط يكون صيغة نظيفة.
     * نص الصفحة بيجمع أسعار رحلات تانية وأرقام ملزوقة في بعض، فمنطلعش منه غير سعر واضح واحد، وبدونه نسيب السعر بره بدل ما نضلّل.
     */
    private static function price(array $page): string
    {
        $found = [];
        $walk = static function ($node) use (&$walk, &$found): void {
            if (!is_array($node)) return;
            foreach (['price', 'lowPrice'] as $k) {
                if (isset($node[$k]) && is_scalar($node[$k]) && is_numeric(str_replace(',', '', (string) $node[$k])) && !empty($node['priceCurrency'])) {
                    $found[] = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $node['priceCurrency'])) . ' ' . (string) $node[$k];
                    return;
                }
            }
            foreach ($node as $v) $walk($v);
        };
        foreach ((array) ($page['schema'] ?? []) as $item) {
            foreach (is_array($item) ? [$item['offers'] ?? null] : [] as $offers) $walk($offers);
            if ($found) break;
        }
        if ($found) return self::line($found[0]);
        $first = trim((string) (($page['prices'] ?? [])[0] ?? ''));
        return preg_match('/^(?:from\s+)?[$€£]\s?\d{1,3}(?:,\d{3})*(?:\.\d{1,2})?(?:\s?per (?:person|adult))?$/i', $first) ? self::line($first) : '';
    }
    private static function hollow(array $page): bool{return trim((string)($page['title']??''))===''&&trim((string)($page['description']??''))===''&&empty($page['links']);}
    public static function line(string $text): string{return trim(preg_replace('/[<>\r\n\[\]]/u',' ',strip_tags($text)));}
}
