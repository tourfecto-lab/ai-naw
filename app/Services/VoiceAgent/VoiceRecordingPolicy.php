<?php

/**
 * Tourfecto - AI Voice Agent: whether a call may be recorded. Pure. Recording is off everywhere until the platform owner lists a country after legal
 * review AND the company turned recording on; the call opening always announces it (VoicePromptBuilder::greeting). Retention is bounded.
 */
class VoiceRecordingPolicy
{
    /** @param string[] $platformCountries ISO codes from voice_recording_countries @return array{record:bool, reason:string} */
    public static function decide(?string $iso, array $settings, array $platformCountries): array
    {
        if (empty($settings['recording_enabled'])) {
            return ['record' => false, 'reason' => 'company_recording_off'];
        }
        $iso = strtoupper((string) $iso);
        if ($iso === '' || !in_array($iso, array_map('strtoupper', $platformCountries), true)) {
            return ['record' => false, 'reason' => 'country_not_enabled_for_recording'];
        }
        return ['record' => true, 'reason' => 'allowed'];
    }

    public static function retentionUntil(int $days, ?int $now = null): string
    {
        return gmdate('Y-m-d H:i:s', ($now ?? time()) + max(1, min(365, $days)) * 86400);
    }

    /** Parse "EG, US" style platform setting. @return string[] */
    public static function countries(string $csv): array
    {
        return array_values(array_unique(array_filter(array_map(static fn ($c) => strtoupper(trim($c)), explode(',', $csv)), static fn ($c) => preg_match('/^[A-Z]{2}$/', $c) === 1)));
    }
}
