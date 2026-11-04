<?php
/**
 * مقارنة بيانات الشركة المحفوظة في الدورة مع الإعداد الحالي بالمحتوى مش بترتيب المفاتيح.
 * الـsnapshot بيتخزن في عمود JSON (MySQL بيعيد ترتيب المفاتيح) والإعداد نص عادي، فالمقارنة المباشرة بـ !== كانت بتفشل
 * على بيانات متطابقة وتسيب الدورة واقفة في "executing".
 */
final class SeoBriefCompare
{
    public static function same($a, $b): bool
    {
        return self::canonical($a) === self::canonical($b);
    }

    private static function canonical($v)
    {
        if (!is_array($v)) return $v;
        $isList = array_is_list($v);
        $out = [];
        foreach ($v as $k => $x) $out[$k] = self::canonical($x);
        if (!$isList) ksort($out, SORT_STRING);
        return $out;
    }
}
