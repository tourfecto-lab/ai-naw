<?php

/**
 * Tourfecto - Competitor Intelligence: Price Extractor
 * @version 1.5.1
 *
 * استخراج سعر مهيكل (رقم + عملة) من نص صفحة منافس وقت رصد تغيير
 * pricing/offers. ميزة "تاريخ الأسعار" اللي بتميز منصات تسعير مخصصة
 * (Prisync وغيرها) - بدل تسجيل "النص اتغير" فقط، بنحاول نحول الاختلاف
 * لمتغير قابل للمقارنة والرسم في الواجهة.
 *
 * المنطق قائم على قواعد شفافة، مفيش تخمين: لازم يظهر رمز/كود/كلمة
 * عملة واضحة جنب الرقم، وإلا نرجّع null (بنفضّل عدم التقاط أرقام
 * عشوائية زي تواريخ أو كميات كـ "أسعار").
 *
 * الاستخدام: PriceExtractor::extract('Package costs $1,299.00 /month')
 *   => ['amount' => 1299.0, 'currency' => 'USD']
 */
class PriceExtractor
{
    private const CURRENCY_CODES = [
        'AED', 'AUD', 'BHD', 'CAD', 'CHF', 'CNY', 'EGP', 'EUR', 'GBP',
        'INR', 'JOD', 'JPY', 'KWD', 'OMR', 'QAR', 'SAR', 'TRY', 'USD',
    ];

    private const SYMBOL_CURRENCIES = [
        '$' => 'USD', '€' => 'EUR', '£' => 'GBP', '₹' => 'INR', '¥' => 'JPY',
    ];

    private const ARABIC_WORD_CURRENCIES = [
        'ريال' => 'SAR', 'جنيه' => 'EGP', 'درهم' => 'AED',
        'دينار' => 'KWD', 'ليرة' => 'TRY', 'ليره' => 'TRY',
    ];

    /**
     * يستخرج أول سعر واضح من النص.
     * @return array{amount:float, currency:string}|null
     */
    public static function extract(string $text): ?array
    {
        $normalized = self::normalizeDigits($text);
        if ($normalized === '') {
            return null;
        }

        $codes = implode('|', self::CURRENCY_CODES);
        $number = '\d+(?:[\s\x{00A0},]\d+)*(?:\.\d{1,2})?';

        $patterns = [
            // "USD 49.99" / "AED 1,500" (رمز قبل الرقم)
            '/\b(?<cur>' . $codes . ')\s+(?<num>' . $number . ')\b/iu',
            // "49.99 USD" / "1,500 EGP" (رمز بعد الرقم)
            '/\b(?<num>' . $number . ')\s+(?<cur>' . $codes . ')\b/iu',
            // "$49.99" / "€25" (رمز عملة قبل الرقم)
            '/(?<sym>[$€£₹¥])\s*(?<num>' . $number . ')/u',
            // "150 ريال" / "4,900 جنيه" (كلمة عملة عربية بعد الرقم)
            '/\b(?<num>' . $number . ')\s*(?<ar>ريال|جنيه|درهم|دينار|ليرة|ليره)(?![\p{L}\p{N}])/u',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $normalized, $m)) {
                continue;
            }

            $amount = self::parseAmount((string) ($m['num'] ?? ''));
            if ($amount === null) {
                continue;
            }

            $currency = self::resolveCurrency(
                (string) ($m['cur'] ?? ''),
                (string) ($m['sym'] ?? ''),
                (string) ($m['ar'] ?? '')
            );
            if ($currency === null) {
                continue;
            }

            return ['amount' => $amount, 'currency' => $currency];
        }

        return null;
    }

    /**
     * يستخرج كل الأسعار الواضحة من النص بدل أول سعر فقط (G7 - تتبع
     * سعر لكل منتج/SKU). لكل سعر يحاول اشتقاق "اسم/سياق" للمنتج من
     * النص اللي قبل الرقم مباشرة (آخر جملة/سطر غير فارغ) كـ heuristic
     * شفاف - مش تخمين بيانات، مجرد تسمية سياقية للعرض والتجميع.
     *
     * @param int $limit سقف عدد النتائج لمنع صفحات تسعير ضخمة
     * @return array<int, array{amount:float, currency:string, label:?string}>
     */
    public static function extractAll(string $text, int $limit = 20): array
    {
        $normalized = self::normalizeDigits($text);
        if ($normalized === '') {
            return [];
        }

        $codes = implode('|', self::CURRENCY_CODES);
        $number = '\d+(?:[\s\x{00A0},]\d+)*(?:\.\d{1,2})?';

        $patterns = [
            '/\b(?<cur>' . $codes . ')\s+(?<num>' . $number . ')\b/iu',
            '/\b(?<num>' . $number . ')\s+(?<cur>' . $codes . ')\b/iu',
            '/(?<sym>[$€£₹¥])\s*(?<num>' . $number . ')/u',
            '/\b(?<num>' . $number . ')\s*(?<ar>ريال|جنيه|درهم|دينار|ليرة|ليره)(?![\p{L}\p{N}])/u',
        ];

        $results = [];
        $seen = [];

        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $normalized, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $count = count($matches[0]);
            for ($i = 0; $i < $count; $i++) {
                $amount = self::parseAmount((string) ($matches['num'][$i][0] ?? ''));
                if ($amount === null) {
                    continue;
                }
                $currency = self::resolveCurrency(
                    (string) ($matches['cur'][$i][0] ?? ''),
                    (string) ($matches['sym'][$i][0] ?? ''),
                    (string) ($matches['ar'][$i][0] ?? '')
                );
                if ($currency === null) {
                    continue;
                }

                $offset = (int) ($matches[0][$i][1] ?? 0);
                $label = self::deriveLabel($normalized, $offset);
                $key = $currency . '|' . $amount . '|' . ($label ?? '');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $results[] = ['amount' => $amount, 'currency' => $currency, 'label' => $label];
                if (count($results) >= $limit) {
                    return $results;
                }
            }
        }

        return $results;
    }

    /**
     * يشتق اسمًا/سياقًا تقديريًا للمنتج من النص قبل موضع السعر: ياخد
     * آخر سطر/جملة غير فارغة قبل الرقم (حتى 70 حرفًا) وينظّفه من
     * رموز الترقيم الزائدة. يرجع null لو النص قبل الرقم فاضي/رقمي.
     */
    private static function deriveLabel(string $normalized, int $offset): ?string
    {
        $before = mb_substr($normalized, 0, $offset);
        if ($before === '') {
            return null;
        }

        // نقسم على فواصل الجمل/الأسطر وناخد آخر قطعة غير فارغة
        $chunks = preg_split('/[\n\r;:|•●»]|(?<=[.!?])\s+/u', $before);
        $candidate = '';
        if (is_array($chunks)) {
            for ($i = count($chunks) - 1; $i >= 0; $i--) {
                $chunk = trim((string) ($chunks[$i] ?? ''));
                if ($chunk === '') {
                    continue;
                }
                $candidate = $chunk;
                break;
            }
        }

        $candidate = preg_replace('/[\[\](){}«»"]/u', '', $candidate);
        $candidate = trim($candidate);
        $candidate = mb_substr($candidate, 0, 70);

        if ($candidate === '' || preg_match('/^[\d\s.,\-–]+$/u', $candidate)) {
            return null;
        }
        return $candidate;
    }

    /**
     * يحوّل نص رقم إلى float مع معالجة فواصل الآلاف والفاصلة العشرية
     * بالاتجاهين ("1,299.00" و"1.299,00") والأرقام العربية-الهندية.
     * @return float|null null لو النص مش رقم سعر صالح
     */
    public static function parseAmount(string $raw): ?float
    {
        $s = trim(self::normalizeDigits($raw));
        if ($s === '' || !preg_match('/^\d[\d\s,.]*$/', $s)) {
            return null;
        }

        // إزالة المسافات والفاصلة غير القابلة للكسر (قد تظهر كفواصل آلاف)
        $s = str_replace([" ", "\u{00A0}"], '', $s);

        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // اللي في الآخر = الفاصل العشري ("1,299.00" أو "1.299,00")
            if ($lastComma > $lastDot) {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($lastComma !== false) {
            // فاصلة واحدة في الآخر بعيدة واحدة أو رقمين = فاصلة عشرية أوروبية
            if (preg_match('/,\d{1,2}$/', $s)) {
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s); // فواصل آلاف
            }
        } elseif ($lastDot !== false) {
            // نقطة مفردة تليها 1-2 رقم = فاصلة عشرية؛ غير ذلك فواصل آلاف
            if (substr_count($s, '.') === 1 && preg_match('/\.\d{1,2}$/', $s)) {
                // تبقى كعشرية
            } else {
                $s = str_replace('.', '', $s);
            }
        }

        // حماية من مدخلات فاسدة ("1,2,3" مثلًا) - أكتر من نقطة عشرية واحدة
        // بعد المعالجة = رقم غير صالح.
        if (substr_count($s, '.') > 1) {
            return null;
        }

        $amount = (float) $s;
        if (!is_finite($amount) || $amount <= 0) {
            return null;
        }

        return round($amount, 2);
    }

    private static function resolveCurrency(string $code, string $symbol, string $arabic): ?string
    {
        if ($code !== '') {
            return strtoupper($code);
        }
        if ($symbol !== '' && isset(self::SYMBOL_CURRENCIES[$symbol])) {
            return self::SYMBOL_CURRENCIES[$symbol];
        }
        if ($arabic !== '' && isset(self::ARABIC_WORD_CURRENCIES[$arabic])) {
            return self::ARABIC_WORD_CURRENCIES[$arabic];
        }
        return null;
    }

    /**
     * الأرقام العربية-الهندية (٠-٩) والعربية الشرقية (۰-۹) إلى ASCII،
     * مع تطبيع الحروف الفارسية الشائعة (ی→ي، ک→ك) عشان كلمات العملة
     * تتبقى مُطابقة بغض النظر عن الأسلوب.
     */
    private static function normalizeDigits(string $text): string
    {
        $map = [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            'ی' => 'ي', 'ک' => 'ك',
        ];
        return strtr($text, $map);
    }
}
