<?php
/**
 * تقدير تكلفة استدعاء نص بالدولار من التوكنز: تقدير تشغيلي للإدارة، مش مصدر فوترة.
 * الأسعار لكل مليون توكن (دخل/خرج) تقريبية وبتتحدّث يدويًا؛ الموديل المجهول = null (يعني "غير مقدّر"، مش صفر).
 */
final class AiCostEstimate
{
    /** [input, output] USD per 1M tokens */
    private const PRICES = [
        'gpt-4o-mini' => [0.15, 0.60],
        'gpt-4o' => [2.50, 10.00],
        'gemini-flash-latest' => [0.30, 2.50],
        'gemini-2.5-flash' => [0.30, 2.50],
        'gemini-2.5-pro' => [1.25, 10.00],
        'deepseek-chat' => [0.27, 1.10],
        'claude-sonnet-4-5' => [3.00, 15.00],
    ];

    /** @return float|null null = سعر الموديل مش معروف */
    public static function usd(string $model, int $tokensTotal, ?int $tokensIn = null, ?int $tokensOut = null): ?float
    {
        $key = strtolower(trim($model));
        if (!isset(self::PRICES[$key]) || $tokensTotal <= 0) return null;
        [$in, $out] = self::PRICES[$key];
        if ($tokensIn !== null && $tokensOut !== null) {
            return round(($tokensIn * $in + $tokensOut * $out) / 1_000_000, 6);
        }
        // لو التقسيم مش معروف: 70% دخل و30% خرج، تقدير متحفظ لخطط السيو
        return round(($tokensTotal * 0.7 * $in + $tokensTotal * 0.3 * $out) / 1_000_000, 6);
    }
}
