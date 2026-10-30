<?php

/**
 * Tourfecto - AI Voice Agent: ad attribution for phone calls. A call is attributed to an ad ONLY when it came in on a dedicated tracking number
 * that the company tied to one of its own ad links. There is no guessing from timing, caller location or "probably from the ad".
 */
class VoiceAttribution
{
    /** Ad platform of the campaign -> the source label used in reports. */
    public static function sourceFor(?string $platform): string
    {
        $p = strtolower(trim((string) $platform));
        if (in_array($p, ['google', 'google_ads', 'googleads'], true)) {
            return 'google_ads';
        }
        if (in_array($p, ['meta', 'meta_ads', 'facebook', 'instagram'], true)) {
            return 'meta_ads';
        }
        if ($p === 'tiktok') {
            return 'tiktok_ads';
        }
        return $p === '' ? 'ads' : 'other_ads';
    }

    public static function isTracked(array $number): bool
    {
        return (int) ($number['utm_link_id'] ?? 0) > 0;
    }
}
