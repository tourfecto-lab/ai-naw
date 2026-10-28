<?php

/** Preserve two weeks of history and include every day of the following month. */
final class SocialCalendarWindow
{
    public static function bounds(?DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        return [
            'from' => $now->modify('-14 days')->format('Y-m-d H:i:s'),
            'until' => $now->modify('first day of this month')->modify('+2 months')->setTime(0, 0)->format('Y-m-d H:i:s'),
        ];
    }
}
