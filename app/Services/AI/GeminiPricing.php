<?php

/**
 * Gemini price table used to record the real cost of every call (api_usage_logs.cost_in_usd).
 * Prices are USD per 1M text tokens [input, output including thinking tokens] from
 * https://ai.google.dev/gemini-api/docs/pricing, verified 2026-10-31. Flash 3.6/3.8 are introductory prices that double
 * on 2027-01-01 (0.75/3.75 become 1.50/7.50): this class switches by date, nothing has to be edited on that day.
 * A model that is not in the table is priced at the highest verified rate (so cost is never understated) and reported
 * as not known. Update this table when Google changes a price or the platform changes model.
 */
class GeminiPricing
{
    public const VERIFIED_ON = '2026-10-31';
    private const CHANGE_DATE = '2027-01-01 00:00:00';

    /** longest prefix first, so "flash-lite" is matched before "flash" */
    private static function table(int $now): array
    {
        $flash = $now >= strtotime(self::CHANGE_DATE . ' UTC') ? [1.50, 7.50] : [0.75, 3.75];
        return [
            'gemini-3.8-flash' => $flash,
            'gemini-3.6-flash' => $flash,
            'gemini-3.5-flash-lite' => [0.30, 2.50],
            'gemini-3.1-flash-lite' => [0.25, 1.50],
            'gemini-2.5-flash-lite' => [0.10, 0.40],
            'gemini-2.5-flash' => [0.30, 2.50],
        ];
    }

    /** @return array{0:float,1:float,2:bool} input rate, output rate, known */
    public static function rates(string $model, ?int $now = null): array
    {
        $now = $now ?? time();
        $table = self::table($now);
        $model = strtolower($model);
        foreach ($table as $prefix => $rate) {
            if (strpos($model, $prefix) === 0) {
                return [$rate[0], $rate[1], true];
            }
        }
        $in = max(array_column($table, 0));
        $out = max(array_column($table, 1));
        return [$in, $out, false];
    }

    /** Cost in USD of one call. Output tokens must include thinking tokens. */
    public static function cost(string $model, int $inputTokens, int $outputTokens, ?int $now = null): float
    {
        [$in, $out] = self::rates($model, $now);
        return round(max(0, $inputTokens) / 1000000 * $in + max(0, $outputTokens) / 1000000 * $out, 6);
    }
}
