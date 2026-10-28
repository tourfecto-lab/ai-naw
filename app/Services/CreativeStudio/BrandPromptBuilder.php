<?php

/**
 * Tourfecto - Brand Prompt Builder
 * بيبني برومبت الصورة النهائي من: فكرة العميل + هوية العلامة + دروس التعلّم من تقييمات سابقة.
 * @version 1.0.0
 */
class BrandPromptBuilder
{
    public static function profileData(BrandProfile $bp): array
    {
        $d = json_decode((string) $bp->getAttribute('profile_json'), true);
        return is_array($d) ? $d : [];
    }

    public static function palette(BrandProfile $bp): array
    {
        $p = json_decode((string) $bp->getAttribute('palette_json'), true);
        return is_array($p) ? $p : [];
    }

    /** أسماء ألوان مرجعية: موديلات الصور والفيديو بتفهم "deep navy blue" أحسن بكتير من #0F4C81 */
    private const NAMED_COLORS=[
        'black'=>[0,0,0],'charcoal'=>[54,69,79],'slate gray'=>[112,128,144],'silver gray'=>[192,192,192],'white'=>[255,255,255],'ivory'=>[255,250,235],
        'navy blue'=>[20,40,100],'royal blue'=>[65,105,225],'sky blue'=>[135,206,235],'teal'=>[0,128,128],'turquoise'=>[64,224,208],'aqua'=>[0,200,220],
        'forest green'=>[34,100,34],'emerald green'=>[0,155,90],'olive green'=>[110,120,40],'sage green'=>[156,175,136],'lime green'=>[140,200,40],'mint green'=>[160,230,190],
        'golden yellow'=>[240,190,30],'mustard'=>[205,160,40],'lemon yellow'=>[250,240,90],'sand beige'=>[220,200,160],'warm beige'=>[200,175,140],
        'orange'=>[245,130,30],'burnt orange'=>[204,85,0],'terracotta'=>[200,100,70],'coral'=>[250,125,95],'peach'=>[250,200,160],
        'crimson red'=>[200,20,50],'burgundy'=>[128,0,32],'brick red'=>[170,60,45],'rose pink'=>[240,130,160],'blush pink'=>[240,195,200],'magenta'=>[200,30,150],
        'purple'=>[110,50,150],'lavender'=>[180,160,220],'plum'=>[120,60,100],'chocolate brown'=>[95,55,30],'caramel brown'=>[175,110,55],'taupe'=>[140,125,110],
    ];
    public static function colorName(string $hex): string
    {
        $hex=ltrim(trim($hex),'#');
        if(!preg_match('/^[0-9a-fA-F]{6}$/D',$hex))return '';
        $rgb=[hexdec(substr($hex,0,2)),hexdec(substr($hex,2,2)),hexdec(substr($hex,4,2))];
        $best='';$distance=PHP_INT_MAX;
        foreach(self::NAMED_COLORS as $name=>$c){
            // مسافة مرجّحة حسب حساسية العين (redmean)
            $r=($rgb[0]+$c[0])/2;$d=(2+$r/256)*($rgb[0]-$c[0])**2+4*($rgb[1]-$c[1])**2+(2+(255-$r)/256)*($rgb[2]-$c[2])**2;
            if($d<$distance){$distance=$d;$best=$name;}
        }
        $lum=(0.2126*$rgb[0]+0.7152*$rgb[1]+0.0722*$rgb[2])/255;
        $max=max($rgb);$min=min($rgb);$sat=$max===0?0:($max-$min)/$max;
        if($sat>0.15&&!in_array($best,['black','white','ivory','charcoal'],true))$best=($lum<0.25?'deep ':($lum>0.8?'pale ':'')).$best;
        return $best;
    }
    /** أسماء ألوان الهوية بدون تكرار، للاستخدام في أي prompt */
    public static function paletteNames(BrandProfile $bp): array
    {
        $pal=self::palette($bp);$out=[];
        foreach(['primary','secondary','accent'] as $k){$name=self::colorName((string)($pal[$k]??''));if($name!==''&&!in_array($name,$out,true))$out[]=$name;}
        return $out;
    }

    /** أحدث/أثقل الدروس: ['do'=>[], 'avoid'=>[]] */
    public static function learnings(int $brandId, int $limit = 5): array
    {
        $out = ['do' => [], 'avoid' => []];
        try {
            $rows = Database::getInstance()->query(
                'SELECT kind, lesson FROM brand_learnings WHERE brand_profile_id = :b ORDER BY weight DESC, id DESC LIMIT 40',
                [':b' => $brandId]
            );
            foreach ((array) $rows as $r) {
                if (count($out[$r['kind']]) < $limit) {
                    $out[$r['kind']][] = $r['lesson'];
                }
            }
        } catch (Throwable $e) {
            // التعلّم مش لازم يكسر التوليد
        }
        return $out;
    }

    public static function build(string $idea, string $style, ?BrandProfile $bp, string $aspectRatio): string
    {
        $base = trim($idea);
        $styleLine = MediaGenerationService::stylePresets()[$style] ?? MediaGenerationService::stylePresets()['photo'];
        if (!$bp) {
            return $base . '. Style: ' . $styleLine . '.';
        }
        $d = self::profileData($bp);
        $learn = self::learnings((int) $bp->getAttribute('id'));

        $parts = [$base . '.'];
        // اسم العلامة مابيدخلش الوصف: موديلات الصور بترسمه ككتابة على الصورة (اتشاف "TOURFECTO TRAVEL" مرسوم على شراع فلوكة)
        $parts[] = 'Made for a ' . mb_substr((string) ($d['business_type'] ?? 'travel business'), 0, 80) . '. Do not write any brand name, words or letters anywhere in the scene (no signage, sails, shirts or boats with text).';
        if (!in_array($style, ['photo', 'product'], true) && !empty($d['visual_style'])) {
            $parts[] = 'Art direction: ' . $d['visual_style'] . '.';
        }
        if (!empty($d['mood_keywords']) && is_array($d['mood_keywords'])) {
            $parts[] = 'Mood: ' . implode(', ', array_slice($d['mood_keywords'], 0, 6)) . '.';
        }
        $colors = self::paletteNames($bp);
        if ($colors) {
            $parts[] = 'Color grading should harmonize with the brand palette (' . implode(', ', $colors) . '); use these colors as subtle accents in props, wardrobe, light or environment and keep natural realism.';
        }
        $parts[] = 'Photographic style: ' . $styleLine . '.';
        if(in_array($style,['photo','cinematic','product','luxury'],true))$parts[] = 'Technical look: photorealistic editorial travel/lifestyle photograph, shot on a full-frame camera with a prime lens, natural light, rich but natural color, crisp detail, authentic textures, believable proportions and anatomy; people look candid and natural; preserve the requested view and pose. Brand accents must not change the natural colors of skin, water or landmarks. A real destination must not be represented with invented landmark details; use a supplied reference for precise location fidelity.';
        $parts[] = 'Composition for ' . $aspectRatio . ': one clear focal subject, strong depth and an uncluttered frame.';
        foreach ((array) ($d['do'] ?? []) as $x) {
            $parts[] = 'Do: ' . $x . '.';
        }
        foreach ($learn['do'] as $x) {
            $parts[] = 'Client liked: ' . $x . '.';
        }
        $avoid = array_merge(['any text, letters, watermarks, logos or captions inside the image', 'distorted hands or faces', 'generic stock-photo look', 'unintentional oversaturation'], (array) ($d['avoid'] ?? []), $learn['avoid']);
        $parts[] = 'Avoid: ' . implode('; ', array_slice($avoid, 0, 10)) . '.';
        return implode(' ', $parts);
    }
}
