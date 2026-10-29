<?php
/**
 * طلب السوق على كل رحلة من غير ما نستنى حجوزات العميل:
 *  - البحث: أحجام البحث الحقيقية في أسواق العميل (seo_keyword_research) للكلمات اللي فيها وجهة الرحلة
 *  - المنافسين: المنافسين المتابَعين اللي بيبيعوا رحلات لنفس الوجهة (ci_product_prices)
 *  - الموسم: الشهور العالية لكل وجهة سياحية في مصر خلال الـ 3 شهور الجايين
 * أي مصدر مش متاح بيتساب ومابيتخمّنش رقم بداله.
 */
final class SeasonMarketSignals
{
    /** وجهة => [أسماء بالعربي والإنجليزي, شهور الموسم العالي, الاسم المعروض عربي, إنجليزي] */
    public const DESTINATIONS = [
        'luxor' => [['luxor', 'karnak', 'valley of the kings', 'hatshepsut', 'الأقصر', 'الاقصر', 'الكرنك'], [10, 11, 12, 1, 2, 3, 4], 'الأقصر', 'Luxor'],
        'aswan' => [['aswan', 'abu simbel', 'philae', 'nubian', 'أسوان', 'اسوان', 'أبو سمبل', 'ابو سمبل'], [10, 11, 12, 1, 2, 3], 'أسوان', 'Aswan'],
        'nile' => [['nile cruise', 'dahabiya', 'felucca', 'كروز', 'رحلة نيلية', 'ذهبية'], [10, 11, 12, 1, 2, 3, 4], 'كروز النيل', 'Nile cruise'],
        'cairo' => [['cairo', 'giza', 'pyramid', 'pyramids', 'sakkara', 'saqqara', 'memphis', 'dahshur', 'egyptian museum', 'egyptian civilization', 'mummies', 'khan el khalili', 'citadel', 'coptic', 'islamic cairo', 'القاهرة', 'الجيزة', 'الأهرامات', 'الاهرامات', 'سقارة'], [10, 11, 12, 1, 2, 3, 4], 'القاهرة والأهرامات', 'Cairo & Giza'],
        'alexandria' => [['alexandria', 'الإسكندرية', 'الاسكندرية'], [6, 7, 8, 9], 'الإسكندرية', 'Alexandria'],
        'redsea' => [['hurghada', 'sharm', 'marsa alam', 'el gouna', 'gouna', 'dahab', 'safaga', 'makadi', 'soma bay', 'red sea', 'snorkel', 'snorkeling', 'diving', 'الغردقة', 'شرم', 'مرسى علم', 'مرسي علم', 'الجونة', 'دهب', 'سفاجا', 'البحر الأحمر'], [3, 4, 5, 6, 9, 10, 11], 'البحر الأحمر', 'Red Sea'],
        'desert' => [['siwa', 'white desert', 'black desert', 'bahariya', 'fayoum', 'wadi rayan', 'desert safari', 'سيوة', 'الصحراء البيضاء', 'الواحات', 'الفيوم', 'وادي الريان'], [10, 11, 12, 1, 2, 3], 'الصحراء والواحات', 'Desert & oases'],
        'sinai' => [['st catherine', 'saint catherine', 'mount sinai', 'سانت كاترين', 'جبل موسى'], [3, 4, 5, 10, 11], 'سانت كاترين', 'St Catherine'],
    ];
    private const STOP = ['tour', 'tours', 'trip', 'trips', 'day', 'days', 'night', 'nights', 'from', 'with', 'and', 'the', 'private', 'group', 'full', 'half', 'best', 'egypt', 'excursion', 'package', 'overnight', 'رحلة', 'رحلات', 'يوم', 'أيام', 'من', 'الى', 'إلى', 'مع', 'جولة', 'مصر', 'خاصة'];

    public static function destinations(string $text): array
    {
        // مكان القيام مش هو الوجهة: «Luxor trip from Marsa Alam» = الأقصر
        $place = preg_replace('/(?:^|\s)(?:from|departing|pick-?up in|من)\s+.*?(?=\s+(?:to|إلى|الى|ل)\s|[,|(–—]|$)/iu', ' ', $text);
        if (trim((string) $place) !== '' && $place !== $text) {
            $found = self::destinations((string) $place);
            if ($found) return $found;
        }
        $t = ' ' . mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text)) . ' ';
        $out = [];
        foreach (self::DESTINATIONS as $key => [$names]) {
            foreach ($names as $n) {
                if (str_contains($t, ' ' . mb_strtolower($n) . ' ')) { $out[] = $key; break; }
            }
        }
        return $out;
    }

    /** كلمات مميزة من اسم الرحلة (للمطابقة مع البحث والمنافسين لما مفيش وجهة معروفة) */
    public static function tokens(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];
        $words = array_filter($words, static fn ($w) => mb_strlen($w) >= 4 && !in_array($w, self::STOP, true) && !ctype_digit($w));
        // مفرد/جمع إنجليزي واحد: temples = temple، cruises = cruise
        $words = array_map(static fn ($w) => preg_match('/^[a-z]{5,}s$/', $w) && !str_ends_with($w, 'ss') ? substr($w, 0, -1) : $w, $words);
        return array_values(array_unique($words));
    }

    private static function matches(string $hay, array $dest, array $tokens): bool
    {
        $hay = ' ' . mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $hay)) . ' ';
        foreach ($dest as $d) foreach (self::DESTINATIONS[$d][0] as $n) if (str_contains($hay, ' ' . mb_strtolower($n) . ' ')) return true;
        if (!$dest) foreach ($tokens as $w) if (str_contains($hay, ' ' . $w . ' ')) return true;
        return false;
    }

    /**
     * @param array $trips [{name, description}]
     * @param array $measured name_hash => {keyword, volume, market} من SeasonTripVolumes (البحث الحقيقي للرحلة بالاسم)
     * @param array $rivals [{product_name, competitor}]
     * @param int[] $months أرقام الشهور الجاية (1-12)
     * @return array<int,array{score:float,reasons:string[],search:?int,competitors:int,peak:bool}>
     */
    public static function score(array $trips, array $measured, array $rivals, array $months, bool $ar): array
    {
        $out = [];
        foreach ($trips as $i => $t) {
            $name = (string) ($t['name'] ?? '');
            $dest = self::destinations($name) ?: self::destinations($name . ' ' . (string) ($t['description'] ?? ''));
            $tokens = self::tokens($name);
            // البحث: رقم الرحلة نفسها بس (اتقاس بكلمة من اسمها). مفيش قياس = مفيش رقم، ومش بنستلف رقم كلمة تانية.
            $m = $measured[SeasonTripVolumes::nameHash($name)] ?? null;
            $vol = $m && $m['volume'] !== null ? (int) $m['volume'] : null;
            $comp = [];
            foreach ($rivals as $r) {
                if (self::matches((string) $r['product_name'], $dest, $tokens)) $comp[(string) $r['competitor']] = true;
            }
            $peak = false;
            $peakName = '';
            foreach ($dest as $d) {
                if (array_intersect($months, self::DESTINATIONS[$d][1])) { $peak = true; $peakName = $ar ? self::DESTINATIONS[$d][2] : self::DESTINATIONS[$d][3]; break; }
            }
            $score = ($vol > 0 ? min(6, log10($vol + 1) * 1.5) : 0) + min(3, count($comp)) + ($peak ? 1.5 : 0);
            $reasons = [];
            if ($m && $vol !== null) {
                $market = $m['market'] !== '' ? $m['market'] : ($ar ? 'سوقك الأساسي' : 'your main market');
                $reasons[] = $vol > 0
                    ? ($ar ? number_format($vol) . ' بحث في الشهر عن «' . $m['keyword'] . '» في ' . $market : number_format($vol) . ' monthly searches for "' . $m['keyword'] . '" in ' . $market)
                    : ($ar ? 'مفيش بحث ملحوظ عن «' . $m['keyword'] . '» في ' . $market : 'No measurable searches for "' . $m['keyword'] . '" in ' . $market);
            }
            if ($comp) {
                $names = array_slice(array_keys($comp), 0, 3);
                $where = $dest ? ($ar ? self::DESTINATIONS[$dest[0]][2] : self::DESTINATIONS[$dest[0]][3]) : '';
                $reasons[] = $ar ? count($comp) . ' من منافسينك بيبيعوا رحلات ' . ($where !== '' ? 'لـ' . $where : 'مشابهة') . ' (' . implode('، ', $names) . ')' : count($comp) . ' competitors sell ' . ($where !== '' ? $where . ' trips' : 'similar trips') . ' (' . implode(', ', $names) . ')';
            }
            if ($peak) {
                $reasons[] = $ar ? $peakName . ' في موسمها العالي في الشهور الجاية' : $peakName . ' is in high season in the coming months';
            }
            $out[$i] = ['score' => round($score, 2), 'reasons' => $reasons, 'search' => $vol, 'keyword' => $m['keyword'] ?? null, 'competitors' => count($comp), 'peak' => $peak];
        }
        return $out;
    }

    /** بيجيب البيانات من قاعدة البيانات؛ أي جدول مش موجود = المصدر ده مش متاح */
    public static function load(Database $db, int $uid): array
    {
        // البحث بقى بيتقاس لكل رحلة بالاسم (SeasonTripVolumes)، مش من كلمات الحساب العامة
        $kw = [];
        $rivals = [];
        try {
            $rivals = $db->query("SELECT DISTINCT p.product_name, COALESCE(NULLIF(c.competitor_name, ''), c.competitor_domain) AS competitor FROM ci_product_prices p JOIN competitors c ON c.id = p.competitor_id WHERE c.user_id = ? AND p.product_name IS NOT NULL AND p.detected_at >= DATE_SUB(NOW(), INTERVAL 180 DAY) LIMIT 3000", [$uid]) ?: [];
        } catch (Throwable $e) {error_log('[SeasonMarketSignals] ignored error: ' . $e->getMessage());}
        return [$kw, $rivals];
    }
}
