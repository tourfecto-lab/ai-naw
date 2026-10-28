<?php

/**
 * Tourfecto - تجهيز النص العربي للرسم على الصور (GD)
 *
 * GD بيرسم الحروف منفصلة ومن الشمال لليمين. هنا بنحوّل كل حرف لشكله
 * الصح (أول/وسط/آخر الكلمة) من Unicode Presentation Forms، وبنعكس ترتيب
 * السطر عشان يتقري من اليمين - والأرقام والكلام الإنجليزي بيفضلوا مظبوطين.
 */
class ArabicShaper
{
    /** الحرف => [معزول/آخر الكلمة في FE.., وهل بيتوصل باللي بعده] */
    private const FORMS = [
        0x0621 => [0xFE80, false], 0x0622 => [0xFE81, false], 0x0623 => [0xFE83, false], 0x0624 => [0xFE85, false],
        0x0625 => [0xFE87, false], 0x0626 => [0xFE89, true], 0x0627 => [0xFE8D, false], 0x0628 => [0xFE8F, true],
        0x0629 => [0xFE93, false], 0x062A => [0xFE95, true], 0x062B => [0xFE99, true], 0x062C => [0xFE9D, true],
        0x062D => [0xFEA1, true], 0x062E => [0xFEA5, true], 0x062F => [0xFEA9, false], 0x0630 => [0xFEAB, false],
        0x0631 => [0xFEAD, false], 0x0632 => [0xFEAF, false], 0x0633 => [0xFEB1, true], 0x0634 => [0xFEB5, true],
        0x0635 => [0xFEB9, true], 0x0636 => [0xFEBD, true], 0x0637 => [0xFEC1, true], 0x0638 => [0xFEC5, true],
        0x0639 => [0xFEC9, true], 0x063A => [0xFECD, true], 0x0641 => [0xFED1, true], 0x0642 => [0xFED5, true],
        0x0643 => [0xFED9, true], 0x0644 => [0xFEDD, true], 0x0645 => [0xFEE1, true], 0x0646 => [0xFEE5, true],
        0x0647 => [0xFEE9, true], 0x0648 => [0xFEED, false], 0x0649 => [0xFEEF, false], 0x064A => [0xFEF1, true],
    ];
    /** لام + ألف => [معزول، آخر الكلمة] */
    private const LAM_ALEF = [0x0622 => 0xFEF5, 0x0623 => 0xFEF7, 0x0625 => 0xFEF9, 0x0627 => 0xFEFB];
    private const MIRROR = ['(' => ')', ')' => '(', '[' => ']', ']' => '[', '{' => '}', '}' => '{', '<' => '>', '>' => '<', '«' => '»', '»' => '«'];

    public static function hasArabic(string $s): bool
    {
        return preg_match('/\p{Arabic}/u', $s) === 1;
    }

    /** سطر واحد جاهز لـ imagettftext */
    public static function line(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if (!self::hasArabic($text)) {
            return $text;
        }
        return self::visual(self::shape($text));
    }

    /** يوصل الحروف ببعض (من غير ما يغيّر الترتيب) */
    public static function shape(string $text): string
    {
        // التشكيل والتطويل مش بيترسموا كويس منفصلين
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);
        $cp = array_map('mb_ord', mb_str_split($text));
        $n = count($cp);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $c = $cp[$i];
            if (!isset(self::FORMS[$c])) {
                $out[] = $c;
                continue;
            }
            $prevJoins = $i > 0 && isset(self::FORMS[$cp[$i - 1]]) && self::FORMS[$cp[$i - 1]][1];
            if ($c === 0x0644 && $i + 1 < $n && isset(self::LAM_ALEF[$cp[$i + 1]])) {
                $out[] = self::LAM_ALEF[$cp[$i + 1]] + ($prevJoins ? 1 : 0);
                $i++;
                continue;
            }
            [$base, $dual] = self::FORMS[$c];
            $nextJoins = $dual && $i + 1 < $n && isset(self::FORMS[$cp[$i + 1]]);
            if ($prevJoins && $nextJoins) {
                $out[] = $base + 3;
            } elseif ($nextJoins) {
                $out[] = $base + 2;
            } elseif ($prevJoins) {
                $out[] = $base + 1;
            } else {
                $out[] = $c; // الشكل المعزول = الحرف الأصلي (موجود في كل الخطوط)
            }
        }
        return implode('', array_map('mb_chr', $out));
    }

    /** ترتيب العرض: السطر من اليمين، والأرقام/الإنجليزي بنفس اتجاههم */
    public static function visual(string $text): string
    {
        // كتل: إنجليزي/أرقام (من الشمال لليمين) وكل الباقي (من اليمين)
        preg_match_all('/[A-Za-z0-9\x{0660}-\x{0669}][A-Za-z0-9\x{0660}-\x{0669}.,:%\/+\-@&_\']*(?:\s+[A-Za-z0-9][A-Za-z0-9.,:%\/+\-@&_\']*)*|[^A-Za-z0-9\x{0660}-\x{0669}]+/u', $text, $m);
        $runs = array_reverse($m[0]);
        $out = '';
        foreach ($runs as $run) {
            if (preg_match('/^[A-Za-z0-9\x{0660}-\x{0669}]/u', $run)) {
                $out .= $run;
                continue;
            }
            $chars = array_reverse(mb_str_split($run));
            foreach ($chars as $ch) {
                $out .= self::MIRROR[$ch] ?? $ch;
            }
        }
        return $out;
    }
}
