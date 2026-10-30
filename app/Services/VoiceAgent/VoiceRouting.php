<?php

/** Tourfecto - AI Voice Agent: smart routing of an incoming call to one of the company's assistants. Pure. First matching rule wins; otherwise the number's own assistant. */
class VoiceRouting
{
    public const MAX_RULES = 10;
    private const CONDITIONS = ['known_customer', 'has_booking', 'after_hours', 'caller_country'];

    /**
     * @param list<array{when:array, agent_id:int}> $rules
     * @param array{known_customer?:bool, has_booking?:bool, after_hours?:bool, caller_country?:?string} $facts
     */
    public static function choose(array $rules, ?int $defaultAgentId, array $facts): ?int
    {
        foreach ($rules as $r) {
            $when = (array) ($r['when'] ?? []);
            if (!$when) {
                continue;
            }
            $ok = true;
            foreach ($when as $k => $want) {
                if ($k === 'caller_country') {
                    $ok = $ok && strtoupper((string) ($facts['caller_country'] ?? '')) === strtoupper((string) $want);
                } elseif (in_array($k, self::CONDITIONS, true)) {
                    $ok = $ok && (bool) ($facts[$k] ?? false) === (bool) $want;
                } else {
                    $ok = false; // an unknown condition never matches
                }
            }
            if ($ok && !empty($r['agent_id'])) {
                return (int) $r['agent_id'];
            }
        }
        return $defaultAgentId;
    }

    /**
     * Validate rules coming from the UI.
     * @param callable(int):bool $agentExists true when the agent belongs to this company
     * @return list<array{when:array, agent_id:int}>
     */
    public static function sanitize(array $in, callable $agentExists): array
    {
        if (count($in) > self::MAX_RULES) {
            throw new InvalidArgumentException('At most ' . self::MAX_RULES . ' routing rules');
        }
        $out = [];
        foreach ($in as $r) {
            $agent = (int) ($r['agent_id'] ?? 0);
            if ($agent < 1 || !$agentExists($agent)) {
                throw new InvalidArgumentException('Routing rule points to an unknown assistant');
            }
            $when = [];
            foreach ((array) ($r['when'] ?? []) as $k => $v) {
                if (!in_array($k, self::CONDITIONS, true)) {
                    throw new InvalidArgumentException('Unknown routing condition: ' . $k);
                }
                if ($k === 'caller_country') {
                    if (!preg_match('/^[A-Za-z]{2}$/', (string) $v)) {
                        throw new InvalidArgumentException('caller_country must be a 2-letter code');
                    }
                    $when[$k] = strtoupper((string) $v);
                } else {
                    $when[$k] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
                }
            }
            if (!$when) {
                throw new InvalidArgumentException('Each routing rule needs at least one condition');
            }
            $out[] = ['when' => $when, 'agent_id' => $agent];
        }
        return $out;
    }
}
