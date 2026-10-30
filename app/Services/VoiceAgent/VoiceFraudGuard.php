<?php

/**
 * Tourfecto - AI Voice Agent: protection against toll fraud and call floods. Pure.
 * Outbound calls only go to the countries the company allowed (default: the supported markets) and never to premium-rate or satellite prefixes;
 * inbound callers who ring in a burst are refused. The prefix list is a conservative starting set, not exhaustive.
 */
class VoiceFraudGuard
{
    private const BLOCKED_PREFIXES = ['+1900', '+1976', '+449', '+3389', '+49900', '+49137', '+49118', '+4390', '+870', '+881', '+882', '+883', '+979', '+808'];
    public const FLOOD_CALLS_PER_10_MIN = 8;
    public const DIALS_PER_10_MIN = 30;

    public static function premiumPrefix(string $e164): bool
    {
        foreach (self::BLOCKED_PREFIXES as $p) {
            if (strpos($e164, $p) === 0) {
                return true;
            }
        }
        return false;
    }

    /** @param string[]|null $allowedIsos null/empty = the supported markets */
    public static function destinationAllowed(string $e164, ?array $allowedIsos): bool
    {
        if (self::premiumPrefix($e164)) {
            return false;
        }
        $iso = VoiceMarkets::countryOf($e164);
        if ($iso === null) {
            return false; // a country we do not serve is never reached by accident
        }
        $allowed = $allowedIsos ? array_map('strtoupper', $allowedIsos) : array_column(VoiceMarkets::targets(), 'iso');
        return in_array($iso, $allowed, true);
    }

    public static function isFlood(int $callsFromSameNumberLast10Min): bool
    {
        return $callsFromSameNumberLast10Min >= self::FLOOD_CALLS_PER_10_MIN;
    }

    public static function dialRateExceeded(int $dialsLast10Min): bool
    {
        return $dialsLast10Min >= self::DIALS_PER_10_MIN;
    }
}
