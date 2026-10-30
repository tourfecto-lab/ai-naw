<?php

/** In-memory VoiceDataSource for AI Voice Agent tests (no database). */
final class VoiceMemoryData implements VoiceDataSource
{
    public array $calls = [];
    public array $turns = [];
    public array $trips = [];
    public array $availability = [];
    public array $context = ['known' => false, 'contact_id' => null, 'bookings' => [], 'previous' => []];
    public array $factors = ['email' => null, 'refs' => []];

    public function __construct(array $call = [])
    {
        $this->calls[1] = $call + ['id' => 1, 'tenant_user_id' => 7, 'from_e164' => '+201001234567', 'language' => 'en', 'identity_verified' => 0, 'verify_attempts' => 0, 'contact_id' => null, 'qualification' => null, 'booking_request' => null, 'turns' => 0, 'ai_cost_usd' => 0.0];
    }

    public function getCall(int $t, int $id): ?array
    {
        return isset($this->calls[$id]) && $this->calls[$id]['tenant_user_id'] === $t ? $this->calls[$id] : null;
    }

    public function updateCall(int $t, int $id, array $f): void
    {
        foreach ($f as $k => $v) {
            if ($k === 'ai_cost_usd_add') {
                $this->calls[$id]['ai_cost_usd'] += $v;
            } elseif ($k === 'verify_attempts_add') {
                $this->calls[$id]['verify_attempts'] += $v;
            } else {
                $this->calls[$id][$k] = $v;
            }
        }
    }

    public function addTurn(int $t, int $c, int $seq, string $role, string $text, array $meta = []): void
    {
        foreach ($this->turns as $x) {
            if ($x['seq'] === $seq && $x['role'] === $role) {
                return;
            }
        }
        $this->turns[] = ['seq' => $seq, 'role' => $role, 'text' => $text, 'meta' => $meta];
        usort($this->turns, static fn ($a, $b) => $a['seq'] <=> $b['seq']);
    }

    public function recentTurns(int $t, int $c, int $limit): array
    {
        return array_slice($this->turns, -$limit);
    }

    public function searchTrips(int $t, string $q, int $limit = 4): array
    {
        return $this->trips;
    }

    public function tripAvailability(int $t, int $pid, string $date, int $guests): array
    {
        return $this->availability[$pid] ?? ['found' => false, 'reason' => 'unknown_trip'];
    }

    public function customerContext(int $t, string $from): array
    {
        return $this->context;
    }

    public function identityFactors(int $t, int $cid): array
    {
        return $this->factors;
    }

    public string $knowledge = '';

    public function knowledgeContext(int $t, string $lang, string $query): string
    {
        return $this->knowledge;
    }

    public function businessName(int $t): string
    {
        return 'Nile Tours';
    }
}
