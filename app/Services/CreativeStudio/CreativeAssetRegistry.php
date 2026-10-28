<?php
/**
 * Tourfecto - سجل موحّد للأصول البصرية (الاستوديو + المقالات + المواقع + السوشيال)
 * - بصمة بصرية (phash) لكل صورة عشان نكشف التكرار بين كل الأقسام.
 * - تسجيل صور الأقسام التانية في مكتبة الاستوديو عشان أي قسم يقدر يعيد استخدامها.
 * - معرفة الصور اللي اتنشرت مؤخرًا عشان الخطط ماتكررهاش.
 */
class CreativeAssetRegistry
{
    /** فرق البتات اللي تحته الصورتين "نفس الصورة تقريبًا" */
    public const DUPLICATE_BITS=4;
    /** فرق البتات اللي تحته الصورتين "مشابهتين بشكل ملحوظ" */
    public const SIMILAR_BITS=8;

    public static function fingerprint(string $binary): ?string
    {
        if(!class_exists('SiteImageImporter'))require_once dirname(__DIR__).'/SocialMedia/SiteImageImporter.php';
        return SiteImageImporter::phash($binary);
    }

    public static function distance(string $a,string $b): int
    {
        if(strlen($a)!==16||strlen($b)!==16||!ctype_xdigit($a.$b))return 64;
        $d=0;foreach([0,8] as $o){$x=hexdec(substr($a,$o,8))^hexdec(substr($b,$o,8));$d+=substr_count(decbin($x),'1');}
        return $d;
    }

    /** @return array{id:int,distance:int}|null أقرب أصل للعميل بالبصمة دي */
    /**
     * @param float|null $aspect عرض/ارتفاع الصورة: البصمة (8x8) مابتشوفش الألوان ولا النسبة، فبنشترط نسبة متقاربة (±3%)
     *                           عشان صورتين مختلفتين بنفس التكوين العام ماتتحسبوش نسخة واحدة
     */
    public static function nearest(PDO $pdo,int $userId,string $phash,int $excludeId=0,int $maxBits=self::SIMILAR_BITS,int $scan=600,?float $aspect=null): ?array
    {
        $s=$pdo->prepare("SELECT id,width,height,generation_meta FROM media_items WHERE user_id=? AND id<>? AND deleted_at IS NULL AND status='completed' AND generation_meta LIKE '%\"phash\"%' ORDER BY id DESC LIMIT ".max(1,min(3000,$scan)));
        $s->execute([$userId,$excludeId]);$best=null;
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){
            $h=json_decode((string)$r['generation_meta'],true)['phash']??null;if(!is_string($h))continue;
            if($aspect!==null&&$aspect>0){$w=(int)$r['width'];$hh=(int)$r['height'];if($w<1||$hh<1||abs(($w/$hh)/$aspect-1)>0.03)continue;}
            $d=self::distance($h,$phash);if($d<=$maxBits&&($best===null||$d<$best['distance']))$best=['id'=>(int)$r['id'],'distance'=>$d];
            if($best&&$best['distance']===0)break;
        }
        return $best;
    }

    /** أقرب نسبة أبعاد مدعومة للصورة (بدل ما كل الصور المرفوعة تتسجل 1:1) */
    public static function closestRatio(int $width,int $height): string
    {
        if($width<1||$height<1)return '1:1';
        $best='1:1';$diff=INF;
        foreach(GenerationOptions::IMAGE_RATIOS as $r){[$a,$b]=array_map('intval',explode(':',$r));$d=abs(log(($width/$height)/($a/$b)));if($d<$diff){$diff=$d;$best=$r;}}
        return $best;
    }

    /**
     * يسجّل صورة من قسم تاني (مقال/موقع) في مكتبة الاستوديو الخاصة بالعميل.
     * لو نفس الصورة موجودة بيرجّع رقمها بدل ما يكررها. @return int|null رقم الأصل
     */
    public static function register(int $userId,string $binary,string $source,string $title,array $extra=[]): ?int
    {
        if($userId<1||!in_array($source,['article','website','ad'],true))return null;
        try{
            $image=GeneratedMediaFiles::image(base64_encode($binary));
            $pdo=Database::getInstance()->getConnection();
            $phash=self::fingerprint($image['data']);
            if($phash&&($dup=self::nearest($pdo,$userId,$phash,0,self::DUPLICATE_BITS,600,$image['width']/$image['height'])))return $dup['id'];
            $meta=['source'=>$source,'phash'=>$phash,'billing'=>['source'=>'upload','amount'=>0,'currency'=>'USD','settled'=>true]]+array_intersect_key($extra,array_flip(['article_id','website_id','page','alt']));
            $item=new MediaItem(['user_id'=>$userId,'type'=>'marketing_image','prompt'=>mb_substr($title,0,500),'aspect_ratio'=>self::closestRatio($image['width'],$image['height']),'status'=>'completed','generation_stage'=>'completed','usage_charged'=>1,'width'=>$image['width'],'height'=>$image['height'],'generation_meta'=>json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            GenerationLifecycle::save($item);$id=(int)$item->getAttribute('id');
            $path=GeneratedMediaFiles::write($id,$image['data'],$image['extension']);
            $thumb=null;try{$thumb=GeneratedMediaFiles::thumbnail($path,$id);}catch(Throwable $e){}
            $item->setAttribute('file_path',$path);$item->setAttribute('thumbnail_path',$thumb?:$path);GenerationLifecycle::save($item);
            return $id;
        }catch(Throwable $e){
            if(class_exists('Logger'))Logger::warning('Creative registry import skipped',['source'=>$source,'error'=>mb_substr($e->getMessage(),0,200)]);
            return null;
        }
    }

    /** أرقام الأصول اللي اتنشرت/اتجدولت للموقع ده في آخر N يوم (عشان الخطط ماتكررهاش) */
    public static function recentlyUsed(PDO $pdo,int $userId,int $websiteId,int $days=60): array
    {
        try{
            $s=$pdo->prepare('SELECT DISTINCT media_item_id FROM social_posts WHERE user_id=? AND website_id=? AND media_item_id IS NOT NULL AND created_at>=DATE_SUB(NOW(),INTERVAL '.max(1,min(365,$days)).' DAY)');
            $s->execute([$userId,$websiteId]);return array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
        }catch(Throwable $e){return [];}
    }
}
