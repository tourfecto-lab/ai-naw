<?php

/** Tourfecto - AI Voice Agent: which person can take a handed-over call right now. Pure. */
class VoiceStaffRouting
{
    public const TOPICS = ['sales', 'booking', 'support', 'billing', 'other'];

    public static function validTime(string $t): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
    }

    /** Is this person on shift at $now (company time zone)? Supports overnight shifts (from > to). */
    public static function onShift(array $s, int $now, ?string $tz): bool
    {
        if (empty($s['active'])) {
            return false;
        }
        $zone = new DateTimeZone($tz && in_array($tz, DateTimeZone::listIdentifiers(), true) ? $tz : 'UTC');
        $l = (new DateTimeImmutable('@' . $now))->setTimezone($zone);
        $days = array_filter(array_map('intval', explode(',', (string) ($s['days'] ?? '1,2,3,4,5,6,7'))));
        $m = (int) $l->format('G') * 60 + (int) $l->format('i');
        [$fh, $fm] = array_map('intval', explode(':', (string) ($s['from_time'] ?? '00:00')));
        [$th, $tm] = array_map('intval', explode(':', (string) ($s['to_time'] ?? '23:59')));
        $from = $fh * 60 + $fm;
        $to = $th * 60 + $tm;
        $dow = (int) $l->format('N');
        if ($from <= $to) {
            return in_array($dow, $days, true) && $m >= $from && $m <= $to;
        }
        // overnight: the shift belongs to the day it starts on
        return ($m >= $from && in_array($dow, $days, true)) || ($m <= $to && in_array((($dow + 5) % 7) + 1, $days, true));
    }

    private static function handles(array $s, ?string $topic): bool
    {
        $topics = array_filter(array_map('trim', explode(',', (string) ($s['topics'] ?? 'any'))));
        return !$topics || in_array('any', $topics, true) || ($topic !== null && in_array($topic, $topics, true));
    }

    /** People who could take the call now, best first (matching topic before generalists, then priority). @return list<array> */
    public static function available(array $staff, int $now, ?string $tz, ?string $topic): array
    {
        $ok = array_values(array_filter($staff, static fn ($s) => self::onShift($s, $now, $tz) && self::handles($s, $topic)));
        usort($ok, static function ($a, $b) use ($topic) {
            $sa = $topic !== null && in_array($topic, array_map('trim', explode(',', (string) $a['topics'])), true) ? 0 : 1;
            $sb = $topic !== null && in_array($topic, array_map('trim', explode(',', (string) $b['topics'])), true) ? 0 : 1;
            return [$sa, (int) $a['priority'], (int) $a['id']] <=> [$sb, (int) $b['priority'], (int) $b['id']];
        });
        return $ok;
    }

    public static function pick(array $staff, int $now, ?string $tz, ?string $topic): ?array
    {
        return self::available($staff, $now, $tz, $topic)[0] ?? null;
    }
}
