<?php

/**
 * Tourfecto - AI Voice Agent: the platform owner's switches and limits (stored in system_settings). Pure validation here; VoiceAdminController saves.
 * Turning anything on that spends money or contacts people ("danger") needs the admin to type the confirmation phrase.
 */
class VoicePlatformSettings
{
    public const CONFIRM_PHRASE = 'I UNDERSTAND';

    /** key => [type, default, label, danger] */
    public const DEFINITIONS = [
        'voice_live_operations_enabled' => ['bool', '0', 'Allow live operations (real number purchases and calls) - each company must also leave test mode and approve each action', true],
        'voice_billing_enabled' => ['bool', '0', 'Charge companies\' wallets for voice usage at month end', true],
        'voice_allow_own_provider_accounts' => ['bool', '0', 'Let companies bring their own Twilio / Telnyx account', false],
        'voice_marketing_countries' => ['iso', '', 'Countries where marketing calls may be queued (after legal review)', true],
        'voice_recording_countries' => ['iso', '', 'Countries where call recording may be enabled (after legal review)', false],
        'voice_markup_pct' => ['float', '0', 'Markup on provider prices, percent (0-500)', false],
        'voice_platform_max_concurrent' => ['int', '20', 'Platform-wide maximum simultaneous calls (1-1000)', false],
        'voice_rates_json' => ['rates', '', 'Estimated provider cost per minute, JSON (inbound_per_min, outbound_per_min, speech_per_min)', false],
        'voice_public_base_url' => ['https_origin', '', 'Public address providers call for voice webhooks, e.g. https://tourfectoai.pro (leave empty to use the site address). Does not change OAuth or any other link', false],
        'voice_stream_url' => ['wss', '', 'Realtime bridge address (wss://). Leave empty to use turn mode', false],
        'voice_sip_domain' => ['host', '', 'SIP domain for forwarding calls', false],
    ];

    /** @param array<string,mixed> $in only known keys are read @return array<string,string> normalized values to store */
    public static function sanitize(array $in): array
    {
        $out = [];
        foreach (self::DEFINITIONS as $key => [$type]) {
            if (!array_key_exists($key, $in)) {
                continue;
            }
            $v = is_string($in[$key]) ? trim($in[$key]) : $in[$key];
            switch ($type) {
                case 'bool':
                    $out[$key] = in_array($v, [1, '1', true, 'true', 'on'], true) ? '1' : '0';
                    break;
                case 'iso':
                    $list = is_array($v) ? $v : explode(',', (string) $v);
                    $known = array_column(VoiceMarkets::targets(), 'iso');
                    $clean = [];
                    foreach ($list as $c) {
                        $c = strtoupper(trim((string) $c));
                        if ($c === '') {
                            continue;
                        }
                        if (!in_array($c, $known, true)) {
                            throw new InvalidArgumentException("$key: unsupported country $c (supported: " . implode(', ', $known) . ')');
                        }
                        $clean[$c] = $c;
                    }
                    $out[$key] = implode(',', $clean);
                    break;
                case 'float':
                    if (!is_numeric($v) || (float) $v < 0 || (float) $v > VoicePricing::MAX_MARKUP_PCT) {
                        throw new InvalidArgumentException("$key must be between 0 and " . VoicePricing::MAX_MARKUP_PCT);
                    }
                    $out[$key] = (string) round((float) $v, 2);
                    break;
                case 'int':
                    if (!is_numeric($v) || (int) $v < 1 || (int) $v > 1000) {
                        throw new InvalidArgumentException("$key must be between 1 and 1000");
                    }
                    $out[$key] = (string) (int) $v;
                    break;
                case 'rates':
                    if ($v === '' || $v === null) {
                        $out[$key] = '';
                        break;
                    }
                    $j = is_array($v) ? json_encode($v) : (string) $v;
                    $rates = VoiceCostModel::rates($j);
                    $given = json_decode($j, true);
                    if (!is_array($given) || !$rates || count($rates) !== count(array_intersect_key($given, array_flip(VoiceCostModel::RATE_KEYS)))) {
                        throw new InvalidArgumentException("$key must be JSON with numeric rates between 0 and 100 for: " . implode(', ', VoiceCostModel::RATE_KEYS));
                    }
                    $out[$key] = json_encode($rates);
                    break;
                case 'https_origin':
                    if ($v !== '' && !VoicePublicUrl::valid((string) $v)) {
                        throw new InvalidArgumentException("$key must be like https://yourdomain.com (https, no path)");
                    }
                    $out[$key] = rtrim((string) $v, '/');
                    break;
                case 'wss':
                    if ($v !== '' && !preg_match('#^wss://[A-Za-z0-9.-]+(:\d+)?(/[^\s]*)?$#', (string) $v)) {
                        throw new InvalidArgumentException("$key must start with wss://");
                    }
                    $out[$key] = (string) $v;
                    break;
                case 'host':
                    if ($v !== '' && !preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/', (string) $v)) {
                        throw new InvalidArgumentException("$key is not a valid host name");
                    }
                    $out[$key] = (string) $v;
                    break;
            }
        }
        return $out;
    }

    /** Which of the requested changes need the confirmation phrase: a danger switch going 0 -> 1, or a danger country list gaining a country. @return string[] keys */
    public static function dangerousChanges(array $clean, array $current): array
    {
        $keys = [];
        foreach ($clean as $key => $new) {
            $def = self::DEFINITIONS[$key];
            if (!$def[3]) {
                continue;
            }
            $old = (string) ($current[$key] ?? $def[1]);
            if ($def[0] === 'bool' && $new === '1' && $old !== '1') {
                $keys[] = $key;
            } elseif ($def[0] === 'iso' && array_diff(array_filter(explode(',', $new)), array_filter(explode(',', $old)))) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    public static function current(): array
    {
        $s = new SystemSettingsService();
        $out = [];
        foreach (self::DEFINITIONS as $key => [, $default]) {
            $out[$key] = (string) $s->get($key, $default);
        }
        return $out;
    }
}
