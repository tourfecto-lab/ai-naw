<?php

/** Tourfecto - AI Voice Agent: how many calls may be answered at once. Pure. */
class VoiceCapacity
{
    public const DEFAULT_PLATFORM_CAP = 50;

    /** @return array{allowed:bool, reason:?string} */
    public static function decide(int $companyActive, int $companyCap, int $platformActive, int $platformCap): array
    {
        if ($companyActive >= max(1, $companyCap)) {
            return ['allowed' => false, 'reason' => 'company_capacity'];
        }
        if ($platformActive >= max(1, $platformCap)) {
            return ['allowed' => false, 'reason' => 'platform_capacity'];
        }
        return ['allowed' => true, 'reason' => null];
    }

    public static function dailyCapReached(int $attemptsLast24h, int $cap): bool
    {
        return $attemptsLast24h >= max(1, $cap);
    }
}
