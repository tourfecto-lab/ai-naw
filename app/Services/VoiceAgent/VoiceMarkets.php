<?php

/**
 * Tourfecto - AI Voice Agent: target markets and indicative number requirements. Pure data, no DB.
 * Requirement levels are INDICATIVE guidance for the customer; the provider's answer at order time is authoritative
 * and anything other than "none_typical" makes the customer acknowledge it before a purchase.
 */
class VoiceMarkets
{
    /** iso => [name, calling code, requirement level] */
    private const TARGETS = [
        'US' => ['United States', '1', 'none_typical'],
        'EG' => ['Egypt', '20', 'likely_business_documents'],
        'SA' => ['Saudi Arabia', '966', 'likely_business_documents'],
        'AE' => ['United Arab Emirates', '971', 'likely_business_documents'],
        'GB' => ['United Kingdom', '44', 'likely_address_or_bundle'],
        'DE' => ['Germany', '49', 'likely_address_or_bundle'],
        'FR' => ['France', '33', 'likely_address_or_bundle'],
        'ES' => ['Spain', '34', 'likely_address_or_bundle'],
        'IT' => ['Italy', '39', 'likely_address_or_bundle'],
        'NL' => ['Netherlands', '31', 'likely_address_or_bundle'],
        'IE' => ['Ireland', '353', 'likely_address_or_bundle'],
        'PT' => ['Portugal', '351', 'likely_address_or_bundle'],
        'BE' => ['Belgium', '32', 'likely_address_or_bundle'],
        'AT' => ['Austria', '43', 'likely_address_or_bundle'],
        'GR' => ['Greece', '30', 'likely_address_or_bundle'],
        'TR' => ['Turkey', '90', 'likely_business_documents'],
    ];

    public const NONE = 'none_typical';

    /** @return list<array{iso:string,name:string,calling_code:string,requirements:string}> */
    public static function targets(): array
    {
        $o = [];
        foreach (self::TARGETS as $iso => [$name, $cc, $lvl]) {
            $o[] = ['iso' => $iso, 'name' => $name, 'calling_code' => $cc, 'requirements' => $lvl];
        }
        return $o;
    }

    /** Level for a country; unknown countries are "unknown" (treated as needing acknowledgement). */
    public static function requirementLevel(string $iso): string
    {
        return self::TARGETS[strtoupper($iso)][2] ?? 'unknown';
    }

    /** Longest calling-code match of an E.164 number; "+1" resolves to US (callers may override: CA/Caribbean share it). */
    public static function countryOf(string $e164): ?string
    {
        $d = ltrim($e164, '+');
        $best = null;
        $bestLen = 0;
        foreach (self::TARGETS as $iso => [, $cc]) {
            if (strpos($d, $cc) === 0 && strlen($cc) > $bestLen) {
                $best = $iso;
                $bestLen = strlen($cc);
            }
        }
        return $best;
    }

    public static function callingCode(string $iso): ?string
    {
        return self::TARGETS[strtoupper($iso)][1] ?? null;
    }
}
