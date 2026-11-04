<?php
/**
 * فحوصات حتمية لعينة جودة الموديلات على مهام سياحية: بدون موديل ثاني يحكم، وبدون شبكة.
 * بتقيس اللي يهم للسيو: الطول، لغة الناتج، الحفاظ على الأرقام الحقيقية، وعدم اختراع وعود أو سياسات.
 */
final class AiSampleChecks
{
    public static function digitsNormalized(string $text): string
    {
        return strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    }

    public static function lengthBetween(string $text, int $min, int $max): bool
    {
        $n = mb_strlen(trim($text));
        return $n >= $min && $n <= $max;
    }

    /** نسبة الحروف العربية من كل الحروف */
    public static function arabicRatio(string $text): float
    {
        preg_match_all('/\p{L}/u', $text, $all);
        preg_match_all('/[\x{0600}-\x{06FF}]/u', $text, $ar);
        $total = count($all[0]);
        return $total === 0 ? 0.0 : count($ar[0]) / $total;
    }

    /** كل الأرقام في الناتج لازم تكون من الأرقام المسموح بها (حقائق الشركة)، مفيش رقم مخترع. */
    public static function numbersSubset(string $text, array $allowed): bool
    {
        preg_match_all('/\d+(?:[.,]\d+)?/', self::digitsNormalized($text), $m);
        $allowed = array_map('strval', $allowed);
        foreach ($m[0] as $n) {
            if (!in_array($n, $allowed, true)) return false;
        }
        return true;
    }

    public static function containsAll(string $text, array $needles): bool
    {
        $t = mb_strtolower(self::digitsNormalized($text));
        foreach ($needles as $n) {
            if (mb_strpos($t, mb_strtolower(self::digitsNormalized((string) $n))) === false) return false;
        }
        return true;
    }

    public static function containsNone(string $text, array $forbidden): bool
    {
        $t = mb_strtolower($text);
        foreach ($forbidden as $f) {
            if (mb_strpos($t, mb_strtolower((string) $f)) !== false) return false;
        }
        return true;
    }

    public static function validJson(string $text): bool
    {
        $t = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text)));
        json_decode($t, true);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /** @param array<string,bool> $checks @return array{passed:int,total:int,score:float,failed:string[]} */
    public static function summarize(array $checks): array
    {
        $failed = array_keys(array_filter($checks, static fn ($ok) => !$ok));
        $total = count($checks);
        return ['passed' => $total - count($failed), 'total' => $total, 'score' => $total ? round(($total - count($failed)) / $total, 3) : 0.0, 'failed' => $failed];
    }
}
