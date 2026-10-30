<?php

/**
 * Tourfecto - AI Voice Agent: when an outbound call may ring, in the RECIPIENT's local time. Pure.
 * Multi-timezone countries without an explicit timezone must satisfy the window in every candidate zone (strictest wins).
 * Defaults are conservative guidance, not legal advice: the platform owner should review them per market.
 */
class VoiceTimeWindows
{
    private const ZONES = [
        'US' => ['America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles'],
        'EG' => ['Africa/Cairo'], 'SA' => ['Asia/Riyadh'], 'AE' => ['Asia/Dubai'], 'GB' => ['Europe/London'], 'DE' => ['Europe/Berlin'],
        'FR' => ['Europe/Paris'], 'ES' => ['Europe/Madrid'], 'IT' => ['Europe/Rome'], 'NL' => ['Europe/Amsterdam'], 'IE' => ['Europe/Dublin'],
        'PT' => ['Europe/Lisbon'], 'BE' => ['Europe/Brussels'], 'AT' => ['Europe/Vienna'], 'GR' => ['Europe/Athens'], 'TR' => ['Europe/Istanbul'],
    ];
    /** Friday morning is avoided in these markets (weekly prayer day). */
    private const FRIDAY_AVOID_BEFORE = ['EG' => 14 * 60, 'SA' => 14 * 60, 'AE' => 14 * 60];
    public const STEP = 900;

    /** @return string[] valid IANA zones to test, or [] when unknown */
    public static function zonesFor(?string $iso, ?string $explicitTz): array
    {
        if ($explicitTz !== null && $explicitTz !== '') {
            return in_array($explicitTz, DateTimeZone::listIdentifiers(), true) ? [$explicitTz] : [];
        }
        return self::ZONES[strtoupper((string) $iso)] ?? [];
    }

    /** [startMinute, endMinute) of the local day */
    public static function window(string $category): array
    {
        return $category === 'marketing' ? [9 * 60, 20 * 60] : [8 * 60, 21 * 60];
    }

    public static function isAllowed(int $ts, array $zones, string $category, ?string $iso): bool
    {
        if (!$zones) {
            return false;
        }
        [$from, $to] = self::window($category);
        foreach ($zones as $z) {
            $l = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone($z));
            $m = (int) $l->format('G') * 60 + (int) $l->format('i');
            if ($m < $from || $m >= $to) {
                return false;
            }
            $avoid = self::FRIDAY_AVOID_BEFORE[strtoupper((string) $iso)] ?? null;
            if ($avoid !== null && $l->format('N') === '5' && $m < $avoid) {
                return false;
            }
        }
        return true;
    }

    /** Earliest allowed instant >= $ts (15-minute resolution, looks 8 days ahead), or null. */
    public static function nextAllowed(int $ts, array $zones, string $category, ?string $iso): ?int
    {
        $t = (int) (ceil($ts / self::STEP) * self::STEP);
        if (self::isAllowed($ts, $zones, $category, $iso)) {
            return $ts;
        }
        for ($i = 0; $i < 8 * 96; $i++, $t += self::STEP) {
            if (self::isAllowed($t, $zones, $category, $iso)) {
                return $t;
            }
        }
        return null;
    }
}
