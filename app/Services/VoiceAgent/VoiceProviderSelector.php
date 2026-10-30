<?php

/** Tourfecto - AI Voice Agent: which provider supplies a number when the company did not pick one: cheapest for the company among healthy providers that really have it. Pure. */
class VoiceProviderSelector
{
    public const UNHEALTHY_ERRORS_PER_HOUR = 5;

    public static function healthy(int $errorsLastHour): bool
    {
        return $errorsLastHour < self::UNHEALTHY_ERRORS_PER_HOUR;
    }

    /**
     * @param list<array{provider:string, price:?float, healthy:bool}> $candidates providers that listed the number (price null = no published price)
     * @param string[] $order tie-break order (the registry order)
     * @return array{provider:string, reason:string}|null
     */
    public static function choose(array $candidates, array $order): ?array
    {
        $priced = array_values(array_filter($candidates, static fn ($c) => $c['price'] !== null));
        if (!$priced) {
            return null; // nobody can quote it honestly
        }
        $healthy = array_values(array_filter($priced, static fn ($c) => $c['healthy']));
        $pool = $healthy ?: $priced;
        usort($pool, static fn ($a, $b) => [$a['price'], (int) array_search($a['provider'], $order, true)] <=> [$b['price'], (int) array_search($b['provider'], $order, true)]);
        $reason = count($priced) === 1 ? 'only_option' : ($healthy ? 'cheapest_healthy' : 'degraded_all_unhealthy');
        return ['provider' => $pool[0]['provider'], 'reason' => $reason];
    }
}
