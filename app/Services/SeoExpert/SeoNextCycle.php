<?php
/**
 * موعد الدورة الجاية بيتحدد من الباقة والحد المتبقي: لو فحوصات الشهر خلصت، الموعد أول الشهر الجاي (UTC)،
 * مش "بعد 7 أيام" وبعدين رسالة "الحد وصل" كل أسبوع.
 */
final class SeoNextCycle
{
    /** @return array{due_at:string,reason:string,remaining:int} */
    public static function dueAt(int $now, string $budgetMonth, int $cyclesUsed, int $maxCycles, int $planCycles, int $frequencyDays = 7): array
    {
        $month = gmdate('Y-m', $now);
        $used = $budgetMonth === $month ? $cyclesUsed : 0;
        $limit = max(0, min($maxCycles, $planCycles));
        $remaining = max(0, $limit - $used);
        $weekly = $now + max(1, $frequencyDays) * 86400;
        $nextMonth = gmmktime(0, 5, 0, (int) gmdate('n', $now) + 1, 1, (int) gmdate('Y', $now));
        if ($remaining === 0) {
            return ['due_at' => gmdate('Y-m-d H:i:s', $nextMonth), 'reason' => 'monthly_limit_reached', 'remaining' => 0];
        }
        // آخر دورة متاحة في الشهر ما تتأخرش لحد ما الشهر يخلص: لو الأسبوع الجاي بعد نهاية الشهر، الحد بيتجدد فالدورة هتشتغل برضه
        return ['due_at' => gmdate('Y-m-d H:i:s', $weekly), 'reason' => 'weekly', 'remaining' => $remaining];
    }
}
