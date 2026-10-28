<?php
/** Factual draft only; publication still uses the existing approved, reversible connector flow. */
class GeoLlmsBuilder
{
    private AiVisibilityEvidence $evidence;
    public function __construct(?AiVisibilityEvidence $evidence=null){$this->evidence=$evidence??new AiVisibilityEvidence();}
    public function build(string $url): string
    {
        $this->evidence->beginBudget(60);$home=$this->evidence->page($url);if(empty($home['ok']))throw new RuntimeException('تعذر قراءة الصفحة العامة؛ لم يتم إنشاء معلومات بديلة');
        $lines=['# '.self::line($home['title']?:AiVisibilityDetector::host($url)),'','> '.self::line($home['description']),'','## Website','- '.$home['url']];
        if($home['languages'])$lines[]='- Published page language: '.implode(', ',$home['languages']);
        foreach(['phone'=>'Phone','email'=>'Email'] as $key=>$label)foreach($home[$key] as $value)$lines[]='- '.$label.': '.self::line($value);
        $trips=[];$seen=[];
        foreach($home['links'] as $link){$target=$link['url'];if(count($trips)>=8)break;
            if(!DataForSeoRankingSource::matchesHost($target,$url)||isset($seen[$target])||!preg_match('#(?:tour|trip|excursion|cruise|رحل)#iu',parse_url($target,PHP_URL_PATH)??''))continue;$seen[$target]=true;
            $p=$this->evidence->page($target);if(empty($p['ok'])||!SeoSchemaBuilder::isTourPage($p)||!DataForSeoRankingSource::matchesHost($p['url'],$url)||$p['url']===$home['url']||$p['description']==='')continue;
            $trips[]='- ['.self::line($p['title']?:$link['label']).']('.$p['url'].'): '.self::line($p['description']).($p['prices']?' | Published price text: '.implode('; ',array_map([self::class,'line'],$p['prices'])):'');
        }
        if($trips)$lines=array_merge($lines,['','## Published trip pages'],$trips);
        $lines=array_merge($lines,['','## Notes','Content reflects public page text fetched '.gmdate('Y-m-d').'. Confirm details on the linked pages. This file makes no ranking or citation guarantee.']);
        return mb_substr(implode("\n",$lines),0,19000);
    }
    public static function line(string $text): string{return trim(preg_replace('/[<>\r\n\[\]]/u',' ',strip_tags($text)));}
}
