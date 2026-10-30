<?php

/**
 * Tourfecto - AI Voice Agent: validates what the model returns before anything is spoken or executed.
 * Model output is untrusted: only whitelisted actions and sanitised parameters get through, and prices the model
 * speaks must have come from a tool result in this call.
 */
class VoiceReplyParser
{
    public const READ_ACTIONS = ['search_trips', 'check_availability', 'verify_identity'];
    public const ACTIONS = ['none', 'search_trips', 'check_availability', 'verify_identity', 'qualify_lead', 'request_booking', 'transfer_human', 'end_call'];
    public const OUTCOMES = ['resolved', 'lead_captured', 'callback_requested', 'not_interested', 'other'];
    public const MAX_SAY = 420;

    private static function str($v, int $max): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $v)));
        return mb_substr($s, 0, $max);
    }

    /** @return array{say:string, language:?string, action:array}|null null = unusable output */
    public static function parse(string $raw): ?array
    {
        $raw = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($raw)));
        $j = json_decode($raw, true);
        if (!is_array($j) || !isset($j['say']) || !is_string($j['say'])) {
            return null;
        }
        $a = is_array($j['action'] ?? null) ? $j['action'] : ['type' => 'none'];
        $type = (string) ($a['type'] ?? 'none');
        if (!in_array($type, self::ACTIONS, true)) {
            $type = 'none';
        }
        $action = ['type' => $type];
        switch ($type) {
            case 'search_trips':
                $action['query'] = self::str($a['query'] ?? '', 100);
                break;
            case 'check_availability':
                $action['product_id'] = (int) ($a['product_id'] ?? 0);
                $action['date'] = self::str($a['date'] ?? '', 10);
                $action['guests'] = max(1, min(50, (int) ($a['guests'] ?? 1)));
                break;
            case 'verify_identity':
                $action['answer'] = self::str($a['answer'] ?? '', 100);
                break;
            case 'qualify_lead':
                foreach (['name', 'email', 'party_size', 'travel_dates', 'budget', 'interest'] as $k) {
                    if (isset($a[$k]) && (is_scalar($a[$k]))) {
                        $action[$k] = self::str($a[$k], 200);
                    }
                }
                $lvl = (string) ($a['intent_level'] ?? '');
                if (in_array($lvl, ['hot', 'warm', 'cold'], true)) {
                    $action['intent_level'] = $lvl;
                }
                break;
            case 'request_booking':
                $action['product_id'] = (int) ($a['product_id'] ?? 0);
                $action['date'] = self::str($a['date'] ?? '', 10);
                $action['guests'] = max(1, min(50, (int) ($a['guests'] ?? 1)));
                $action['name'] = self::str($a['name'] ?? '', 120);
                break;
            case 'transfer_human':
                $action['reason'] = self::str($a['reason'] ?? '', 200);
                break;
            case 'end_call':
                $o = (string) ($a['outcome'] ?? 'other');
                $action['outcome'] = in_array($o, self::OUTCOMES, true) ? $o : 'other';
                break;
        }
        $lang = isset($j['language']) && is_string($j['language']) ? VoiceLanguages::normalize($j['language'], '') : '';
        return ['say' => self::str($j['say'], self::MAX_SAY), 'language' => $lang !== '' ? $lang : null, 'action' => $action];
    }

    private static function westernDigits(string $s): string
    {
        return strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', '٬' => ',']);
    }

    /** Amounts in $say that are written next to a currency but are not in $allowed. @return float[] */
    public static function ungroundedPrices(string $say, array $allowed): array
    {
        $t = self::westernDigits($say);
        $cur = '(?:usd|eur|egp|sar|aed|gbp|try|dollars?|euros?|pounds?|riyals?|dirhams?|lira|\$|€|£|دولار|يورو|جنيه|ريال|درهم|ليرة)';
        $found = [];
        foreach ([ '/(\d[\d,]*(?:\.\d+)?)\s*' . $cur . '/iu', '/' . $cur . '\s*(\d[\d,]*(?:\.\d+)?)/iu'] as $re) {
            if (preg_match_all($re, $t, $m)) {
                foreach ($m[1] as $n) {
                    $found[] = (float) str_replace(',', '', $n);
                }
            }
        }
        $bad = [];
        foreach ($found as $f) {
            $ok = false;
            foreach ($allowed as $a) {
                $ok = $ok || abs($f - (float) $a) < 0.005;
            }
            if (!$ok) {
                $bad[] = $f;
            }
        }
        return $bad;
    }
}
