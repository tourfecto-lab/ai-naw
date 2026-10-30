<?php

/** Tourfecto - AI Voice Agent: business hours, from the company's existing knowledge base (BusinessHoursService). No knowledge base entry = always open. */
class VoiceHours
{
    /** @param list<array> $kbRows knowledge-base rows of section business_hours */
    public static function isOpen(array $kbRows, ?string $tz, int $now): bool
    {
        $zone = $tz && in_array($tz, DateTimeZone::listIdentifiers(), true) ? new DateTimeZone($tz) : null;
        return BusinessHoursService::isOpenAt($now, BusinessHoursService::fromEntries($kbRows), $zone);
    }
}
