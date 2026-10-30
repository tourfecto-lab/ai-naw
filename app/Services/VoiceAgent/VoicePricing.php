<?php

/** Tourfecto - AI Voice Agent: what the customer pays versus what the provider charges Tourfecto. Pure apart from reading one platform setting. */
class VoicePricing
{
    public const MAX_MARKUP_PCT = 500.0;

    /** Platform markup on provider prices, percent. 0 until the platform owner sets it (voice_markup_pct). */
    public static function markupPct(): float
    {
        return self::clamp((float) (new SystemSettingsService())->get('voice_markup_pct', '0'));
    }

    public static function clamp(float $pct): float
    {
        return max(0.0, min(self::MAX_MARKUP_PCT, $pct));
    }

    /** Customer price: provider price plus markup, rounded UP to the cent so margin is never rounded away. Null stays null (no published price). */
    public static function apply(?float $providerPrice, float $markupPct): ?float
    {
        if ($providerPrice === null) {
            return null;
        }
        return ceil(round($providerPrice * (100 + self::clamp($markupPct)), 4)) / 100;
    }

    /** @param array{inbound_per_min:?float, outbound_per_min:?float, currency:string}|null $rates */
    public static function applyRates(?array $rates, float $markupPct): ?array
    {
        if ($rates === null) {
            return null;
        }
        return ['inbound_per_min' => self::apply($rates['inbound_per_min'], $markupPct), 'outbound_per_min' => self::apply($rates['outbound_per_min'], $markupPct), 'currency' => $rates['currency']];
    }
}
