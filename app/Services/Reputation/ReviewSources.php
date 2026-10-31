<?php

/**
 * Reputation: which ways of bringing reviews in from each outside site are turned on, decided by the admin.
 *   csv_import  the customer uploads the CSV exported from the site's dashboard (Booking.com, Expedia, Trustpilot, other)
 *   auto_sync   the platform reads the reviews through the site's API
 * Both can be on at the same time. Automatic sync can only be switched on for a source that has a connector in the
 * code (today TripAdvisor, limited by TripAdvisor to the latest 5 reviews). Booking.com (Connectivity Partners only),
 * Expedia and Trustpilot (public reviews API with an API key) have no connector built yet; see
 * docs/reputation/PARTNER_API_APPLICATIONS.md.
 * Defaults keep today's behaviour: CSV import on for the CSV sources, TripAdvisor sync on, nothing else.
 */
class ReviewSources
{
    public const SOURCES = ['booking' => 'Booking.com', 'expedia' => 'Expedia', 'trustpilot' => 'Trustpilot', 'tripadvisor' => 'TripAdvisor', 'custom' => 'Other sites'];
    /** sources the CSV importer accepts */
    public const CSV_SOURCES = ['booking', 'expedia', 'trustpilot', 'custom'];
    /** sources that have an automatic sync connector in the code */
    public const CONNECTORS = ['tripadvisor'];
    private const SETTING = 'reputation_sources';

    public static function defaults(): array
    {
        $out = [];
        foreach (array_keys(self::SOURCES) as $s) {
            $out[$s] = ['csv_import' => in_array($s, self::CSV_SOURCES, true), 'auto_sync' => in_array($s, self::CONNECTORS, true)];
        }
        return $out;
    }

    public static function config(?Database $db = null): array
    {
        $cfg = self::defaults();
        try {
            $db = $db ?: Database::getInstance();
            $r = $db->query('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1', [self::SETTING]);
            $stored = json_decode((string) ($r[0]['setting_value'] ?? ''), true);
        } catch (Throwable $e) {
            $stored = null;
        }
        if (is_array($stored)) {
            foreach ($cfg as $s => $flags) {
                foreach (['csv_import', 'auto_sync'] as $f) {
                    if (isset($stored[$s][$f]) && self::canEnable($s, $f)) {
                        $cfg[$s][$f] = (bool) $stored[$s][$f];
                    }
                }
            }
        }
        return $cfg;
    }

    public static function canEnable(string $source, string $flag): bool
    {
        return $flag === 'csv_import' ? in_array($source, self::CSV_SOURCES, true) : in_array($source, self::CONNECTORS, true);
    }

    /** Fails open to the default if the setting cannot be read, so a settings problem never blocks customers. */
    public static function allowed(string $source, string $flag, ?Database $db = null): bool
    {
        if (!isset(self::SOURCES[$source]) || !in_array($flag, ['csv_import', 'auto_sync'], true) || !self::canEnable($source, $flag)) {
            return false;
        }
        return (bool) (self::config($db)[$source][$flag] ?? false);
    }

    /** @return array{config:array,changes:array} */
    public static function save(array $in, int $actorId, ?Database $db = null): array
    {
        $db = $db ?: Database::getInstance();
        $before = self::config($db);
        $after = $before;
        foreach ($in as $source => $flags) {
            if (!isset(self::SOURCES[$source]) || !is_array($flags)) {
                throw new InvalidArgumentException('Unknown source');
            }
            foreach ($flags as $flag => $value) {
                if (!in_array($flag, ['csv_import', 'auto_sync'], true)) {
                    throw new InvalidArgumentException('Unknown setting');
                }
                $on = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                if ($on && !self::canEnable($source, $flag)) {
                    throw new InvalidArgumentException(self::SOURCES[$source] . ': ' . ($flag === 'auto_sync' ? 'automatic sync has no connector yet (it needs API access from the site)' : 'CSV import is not offered for this source'));
                }
                $after[$source][$flag] = $on;
            }
        }
        $changes = [];
        foreach ($after as $s => $flags) {
            foreach ($flags as $f => $v) {
                if ($before[$s][$f] !== $v) {
                    $changes[] = ['plan' => 'source:' . $s, 'key' => $f, 'from' => $before[$s][$f], 'to' => $v];
                }
            }
        }
        if ($changes) {
            $db->exec(
                "INSERT INTO system_settings (setting_key, setting_value, is_secret, category) VALUES (?, ?, 0, 'reputation') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                [self::SETTING, json_encode($after)]
            );
            ReputationLimits::recordAudit($changes, $actorId, $db);
        }
        return ['config' => $after, 'changes' => $changes];
    }

    private static function noConnectorNote(string $key, string $label): string
    {
        if ($key === 'trustpilot') {
            return 'No connector built yet. Needs the "API module" add-on on a Trustpilot for Business plan (included in Enterprise) plus an API key and the business unit id.';
        }
        if ($key === 'booking') {
            return 'No connector yet. Booking.com gives review access to approved Connectivity Partners only (application needed).';
        }
        return 'No connector yet: needs API access from ' . $label . '.';
    }

    /** For the admin and customer screens. */
    public static function view(?Database $db = null): array
    {
        $cfg = self::config($db);
        $out = [];
        foreach (self::SOURCES as $key => $label) {
            $out[] = [
                'key' => $key, 'label' => $label,
                'csv_import' => $cfg[$key]['csv_import'], 'csv_available' => self::canEnable($key, 'csv_import'),
                'auto_sync' => $cfg[$key]['auto_sync'], 'auto_available' => self::canEnable($key, 'auto_sync'),
                'auto_note' => $key === 'tripadvisor' ? 'Limited by TripAdvisor to the latest 5 reviews per location' : (self::canEnable($key, 'auto_sync') ? '' : self::noConnectorNote($key, $label)),
            ];
        }
        return $out;
    }
}
