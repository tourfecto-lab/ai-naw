<?php

/**
 * Tourfecto - Ads Geo & Language Targeting Helper
 * @version 1.0.0
 *
 * مصدر واحد لتحويل أسماء الدول (عربي/إنجليزي/أكواد) لأكواد ISO، ومنها
 * لمعرّفات الاستهداف الرسمية في Google Ads (geoTargetConstants) ولغات
 * الإعلان (languageConstants). قبل كده Meta كانت عندها جدول صغير لوحدها
 * وGoogle مكانش عندها أي استهداف جغرافي خالص (الحملة كانت بتطلع للعالم كله).
 */
class AdGeo
{
    /**
     * ISO 3166-1 alpha-2 => ISO 3166-1 numeric.
     * معرّف الدولة في Google Ads = 2000 + الكود الرقمي (مثلًا مصر 818 => 2818).
     */
    private const ISO_NUMERIC = [
        'EG' => 818, 'SA' => 682, 'AE' => 784, 'KW' => 414, 'QA' => 634, 'BH' => 48, 'OM' => 512,
        'JO' => 400, 'LB' => 422, 'MA' => 504, 'TN' => 788, 'DZ' => 12, 'IQ' => 368, 'LY' => 434,
        'SD' => 729, 'PS' => 275, 'YE' => 887, 'TR' => 792, 'GB' => 826, 'DE' => 276, 'FR' => 250,
        'US' => 840, 'IT' => 380, 'ES' => 724, 'RU' => 643, 'NL' => 528, 'BE' => 56, 'AT' => 40,
        'CH' => 756, 'PL' => 616, 'CZ' => 203, 'SK' => 703, 'HU' => 348, 'RO' => 642, 'BG' => 100,
        'RS' => 688, 'HR' => 191, 'SI' => 705, 'UA' => 804, 'BY' => 112, 'KZ' => 398, 'LT' => 440,
        'LV' => 428, 'EE' => 233, 'CN' => 156, 'HK' => 344, 'TW' => 158, 'JP' => 392, 'KR' => 410,
        'IN' => 356, 'PK' => 586, 'BD' => 50, 'MY' => 458, 'ID' => 360, 'SG' => 702, 'TH' => 764,
        'PH' => 608, 'VN' => 704, 'CA' => 124, 'MX' => 484, 'BR' => 76, 'AR' => 32, 'CL' => 152,
        'CO' => 170, 'AU' => 36, 'NZ' => 554, 'SE' => 752, 'NO' => 578, 'DK' => 208, 'FI' => 246,
        'IS' => 352, 'IE' => 372, 'PT' => 620, 'GR' => 300, 'CY' => 196, 'MT' => 470, 'LU' => 442,
        'NG' => 566, 'ZA' => 710, 'KE' => 404,
    ];

    /** أسماء شائعة (بعد التطبيع) => ISO2 */
    private const NAMES = [
        'مصر' => 'EG', 'egypt' => 'EG',
        'السعوديه' => 'SA', 'المملكه العربيه السعوديه' => 'SA', 'saudi arabia' => 'SA', 'saudi' => 'SA', 'ksa' => 'SA',
        'الامارات' => 'AE', 'الامارات العربيه المتحده' => 'AE', 'uae' => 'AE', 'united arab emirates' => 'AE', 'دبي' => 'AE', 'dubai' => 'AE',
        'الكويت' => 'KW', 'kuwait' => 'KW', 'قطر' => 'QA', 'qatar' => 'QA', 'البحرين' => 'BH', 'bahrain' => 'BH',
        'عمان' => 'OM', 'سلطنه عمان' => 'OM', 'oman' => 'OM', 'الاردن' => 'JO', 'jordan' => 'JO',
        'لبنان' => 'LB', 'lebanon' => 'LB', 'المغرب' => 'MA', 'morocco' => 'MA', 'تونس' => 'TN', 'tunisia' => 'TN',
        'الجزائر' => 'DZ', 'algeria' => 'DZ', 'العراق' => 'IQ', 'iraq' => 'IQ', 'ليبيا' => 'LY', 'libya' => 'LY',
        'السودان' => 'SD', 'sudan' => 'SD', 'فلسطين' => 'PS', 'palestine' => 'PS', 'اليمن' => 'YE', 'yemen' => 'YE',
        'تركيا' => 'TR', 'turkey' => 'TR', 'turkiye' => 'TR',
        'المملكه المتحده' => 'GB', 'بريطانيا' => 'GB', 'انجلترا' => 'GB', 'uk' => 'GB', 'united kingdom' => 'GB', 'great britain' => 'GB', 'england' => 'GB',
        'المانيا' => 'DE', 'germany' => 'DE', 'deutschland' => 'DE', 'فرنسا' => 'FR', 'france' => 'FR',
        'الولايات المتحده' => 'US', 'الولايات المتحده الامريكيه' => 'US', 'امريكا' => 'US', 'usa' => 'US', 'us' => 'US', 'united states' => 'US', 'america' => 'US',
        'ايطاليا' => 'IT', 'italy' => 'IT', 'اسبانيا' => 'ES', 'spain' => 'ES', 'روسيا' => 'RU', 'russia' => 'RU',
        'هولندا' => 'NL', 'netherlands' => 'NL', 'holland' => 'NL', 'بلجيكا' => 'BE', 'belgium' => 'BE',
        'النمسا' => 'AT', 'austria' => 'AT', 'سويسرا' => 'CH', 'switzerland' => 'CH', 'بولندا' => 'PL', 'poland' => 'PL',
        'التشيك' => 'CZ', 'czech republic' => 'CZ', 'czechia' => 'CZ', 'سلوفاكيا' => 'SK', 'slovakia' => 'SK',
        'المجر' => 'HU', 'hungary' => 'HU', 'رومانيا' => 'RO', 'romania' => 'RO', 'بلغاريا' => 'BG', 'bulgaria' => 'BG',
        'صربيا' => 'RS', 'serbia' => 'RS', 'كرواتيا' => 'HR', 'croatia' => 'HR', 'اوكرانيا' => 'UA', 'ukraine' => 'UA',
        'بيلاروسيا' => 'BY', 'belarus' => 'BY', 'كازاخستان' => 'KZ', 'kazakhstan' => 'KZ',
        'الصين' => 'CN', 'china' => 'CN', 'هونج كونج' => 'HK', 'hong kong' => 'HK', 'تايوان' => 'TW', 'taiwan' => 'TW',
        'اليابان' => 'JP', 'japan' => 'JP', 'كوريا الجنوبيه' => 'KR', 'كوريا' => 'KR', 'south korea' => 'KR', 'korea' => 'KR',
        'الهند' => 'IN', 'india' => 'IN', 'باكستان' => 'PK', 'pakistan' => 'PK', 'بنجلاديش' => 'BD', 'bangladesh' => 'BD',
        'ماليزيا' => 'MY', 'malaysia' => 'MY', 'اندونيسيا' => 'ID', 'indonesia' => 'ID', 'سنغافوره' => 'SG', 'singapore' => 'SG',
        'تايلاند' => 'TH', 'thailand' => 'TH', 'الفلبين' => 'PH', 'philippines' => 'PH', 'فيتنام' => 'VN', 'vietnam' => 'VN',
        'كندا' => 'CA', 'canada' => 'CA', 'المكسيك' => 'MX', 'mexico' => 'MX', 'البرازيل' => 'BR', 'brazil' => 'BR',
        'الارجنتين' => 'AR', 'argentina' => 'AR', 'تشيلي' => 'CL', 'chile' => 'CL', 'كولومبيا' => 'CO', 'colombia' => 'CO',
        'استراليا' => 'AU', 'australia' => 'AU', 'نيوزيلندا' => 'NZ', 'new zealand' => 'NZ',
        'السويد' => 'SE', 'sweden' => 'SE', 'النرويج' => 'NO', 'norway' => 'NO', 'الدنمارك' => 'DK', 'denmark' => 'DK',
        'فنلندا' => 'FI', 'finland' => 'FI', 'ايسلندا' => 'IS', 'iceland' => 'IS', 'ايرلندا' => 'IE', 'ireland' => 'IE',
        'البرتغال' => 'PT', 'portugal' => 'PT', 'اليونان' => 'GR', 'greece' => 'GR', 'قبرص' => 'CY', 'cyprus' => 'CY',
        'مالطا' => 'MT', 'malta' => 'MT', 'لوكسمبورج' => 'LU', 'luxembourg' => 'LU',
        'نيجيريا' => 'NG', 'nigeria' => 'NG', 'جنوب افريقيا' => 'ZA', 'south africa' => 'ZA', 'كينيا' => 'KE', 'kenya' => 'KE',
    ];

    /** مناطق شائعة في وصف العملاء => قائمة دول */
    private const REGIONS = [
        'الخليج' => ['SA', 'AE', 'KW', 'QA', 'BH', 'OM'], 'دول الخليج' => ['SA', 'AE', 'KW', 'QA', 'BH', 'OM'],
        'gulf' => ['SA', 'AE', 'KW', 'QA', 'BH', 'OM'], 'gcc' => ['SA', 'AE', 'KW', 'QA', 'BH', 'OM'],
        'اوروبا' => ['DE', 'GB', 'FR', 'IT', 'ES', 'NL', 'BE', 'AT', 'CH', 'PL'], 'europe' => ['DE', 'GB', 'FR', 'IT', 'ES', 'NL', 'BE', 'AT', 'CH', 'PL'],
        'اسكندنافيا' => ['SE', 'NO', 'DK', 'FI'], 'scandinavia' => ['SE', 'NO', 'DK', 'FI'],
        'شمال امريكا' => ['US', 'CA'], 'north america' => ['US', 'CA'],
    ];

    /** كود اللغة => Google Ads languageConstant */
    private const GOOGLE_LANGUAGES = [
        'en' => 1000, 'de' => 1001, 'fr' => 1002, 'es' => 1003, 'it' => 1004, 'ja' => 1005, 'da' => 1009,
        'nl' => 1010, 'fi' => 1011, 'ko' => 1012, 'no' => 1013, 'pt' => 1014, 'sv' => 1015, 'zh' => 1017,
        'ar' => 1019, 'cs' => 1021, 'el' => 1022, 'hi' => 1023, 'hu' => 1024, 'pl' => 1030, 'ru' => 1031,
        'tr' => 1037, 'ro' => 1032, 'uk' => 1036,
    ];

    /**
     * يحوّل قائمة دول بأي صيغة (أسماء عربي/إنجليزي، أكواد ISO2، مناطق زي "الخليج")
     * لأكواد ISO2 فريدة. لو مفيش أي تطابق بيرجّع $default (لو اتبعت).
     * @param array<int, mixed> $locations
     * @return string[]
     */
    public static function toIso2(array $locations, array $default = []): array
    {
        $codes = [];
        foreach ($locations as $loc) {
            $raw = trim((string) (is_array($loc) ? ($loc['code'] ?? $loc['country'] ?? $loc['name'] ?? '') : $loc));
            if ($raw === '') {
                continue;
            }
            if (preg_match('/^[A-Za-z]{2}$/', $raw) && isset(self::ISO_NUMERIC[strtoupper($raw)])) {
                $codes[] = strtoupper($raw);
                continue;
            }
            $key = self::normalize($raw);
            if (isset(self::NAMES[$key])) {
                $codes[] = self::NAMES[$key];
            } elseif (isset(self::REGIONS[$key])) {
                array_push($codes, ...self::REGIONS[$key]);
            }
        }
        $codes = array_values(array_unique($codes));
        return !empty($codes) ? $codes : array_values(array_unique($default));
    }

    /** معرّف الدولة في Google Ads (geoTargetConstants/{id}) أو null لو الدولة مش معروفة */
    public static function googleGeoTargetId(string $iso2): ?int
    {
        $n = self::ISO_NUMERIC[strtoupper($iso2)] ?? null;
        return $n === null ? null : 2000 + $n;
    }

    /** معرّف اللغة في Google Ads (languageConstants/{id}) */
    public static function googleLanguageId(string $language): ?int
    {
        return self::GOOGLE_LANGUAGES[strtolower(substr($language, 0, 2))] ?? null;
    }

    /**
     * يستنتج لغة نص الإعلان (لاستهداف اللغة في Google) - النص العربي مينفعش
     * يتعرض لمستخدم لغته إنجليزي والعكس.
     */
    public static function detectLanguage(string $text, string $fallback = 'en'): string
    {
        $letters = preg_match_all('/\p{L}/u', $text);
        if (!$letters) {
            return $fallback;
        }
        if (preg_match_all('/\p{Arabic}/u', $text) / $letters > 0.3) {
            return 'ar';
        }
        if (preg_match_all('/\p{Cyrillic}/u', $text) / $letters > 0.3) {
            return 'ru';
        }
        if (preg_match_all('/\p{Han}/u', $text) / $letters > 0.3) {
            return 'zh';
        }
        $lower = ' ' . mb_strtolower($text) . ' ';
        $hints = [
            'de' => [' und ', ' der ', ' die ', ' das ', ' mit ', ' für ', ' jetzt ', ' buchen ', 'ä', 'ö', 'ü', 'ß'],
            'fr' => [' le ', ' la ', ' les ', ' des ', ' avec ', ' pour ', ' réservez ', 'é', 'è', 'ê', 'ç'],
            'it' => [' il ', ' gli ', ' della ', ' con ', ' per ', ' prenota ', ' viaggio '],
            'es' => [' el ', ' los ', ' las ', ' con ', ' para ', ' reserva ', ' viaje ', 'ñ', '¡'],
        ];
        $best = $fallback;
        $bestScore = 1;
        foreach ($hints as $lang => $words) {
            $score = 0;
            foreach ($words as $w) {
                $score += substr_count($lower, $w);
            }
            if ($score > $bestScore) {
                $best = $lang;
                $bestScore = $score;
            }
        }
        return $best;
    }

    /** توحيد كتابة الاسم: حروف صغيرة، بدون تشكيل، وهمزات/تاء مربوطة موحّدة */
    private static function normalize(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $s);
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim((string) $s, " .,-");
    }
}
