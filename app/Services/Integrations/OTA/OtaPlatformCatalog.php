<?php
/**
 * Additive OTA capability registry for Tourfecto.
 * Capabilities describe intended integration scope, not guaranteed API access.
 */
final class OtaPlatformCatalog
{
    private const PLATFORMS = [
        'booking_com' => ['name'=>'Booking.com','category'=>'lodging','channels'=>['reservations','rates_availability','content','photos','reviews','messaging','reporting'],'access'=>'connectivity_partner'],
        'expedia_group' => ['name'=>'Expedia Group','category'=>'lodging','channels'=>['reservations','rates_availability','content','photos','reviews','messaging','reporting'],'access'=>'connectivity_partner'],
        'agoda' => ['name'=>'Agoda','category'=>'lodging','channels'=>['reservations','rates_availability','content'],'access'=>'partner_api_or_channel_manager'],
        'airbnb' => ['name'=>'Airbnb','category'=>'vacation_rental','channels'=>['reservations','availability','rates','messaging','listing_content'],'access'=>'approved_software_partner_or_channel_manager'],
        'vrbo' => ['name'=>'Vrbo','category'=>'vacation_rental','channels'=>['reservations','availability','rates','messaging','reviews'],'access'=>'approved_connectivity_partner'],
        'trip_com' => ['name'=>'Trip.com','category'=>'lodging','channels'=>['reservations','rates_availability','content'],'access'=>'partner_api_or_channel_manager'],
        'viator' => ['name'=>'Viator','category'=>'activities','channels'=>['products','pricing','availability','booking','cancellation','reviews_read'],'access'=>'affiliate_or_merchant_partner'],
        'getyourguide' => ['name'=>'GetYourGuide','category'=>'activities','channels'=>['products','pricing','availability','booking','cancellation','reviews_read'],'access'=>'approved_partner_api_access'],
        'klook' => ['name'=>'Klook','category'=>'activities','channels'=>['products','availability','booking'],'access'=>'commercial_partner_access'],
        'civitatis' => ['name'=>'Civitatis','category'=>'activities','channels'=>['products','availability','booking'],'access'=>'connectivity_partner'],
        'tiqets' => ['name'=>'Tiqets','category'=>'activities','channels'=>['products','availability','booking'],'access'=>'partner_api_access'],
        'musement' => ['name'=>'Musement','category'=>'activities','channels'=>['products','availability','booking'],'access'=>'partner_api_access'],
        'headout' => ['name'=>'Headout','category'=>'activities','channels'=>['products','availability','booking'],'access'=>'commercial_partner_access'],
        'google_business' => ['name'=>'Google Business Profile','category'=>'reputation','channels'=>['locations','reviews_read','review_replies'],'access'=>'oauth_and_api_approval'],
        'tripadvisor' => ['name'=>'Tripadvisor','category'=>'reputation','channels'=>['listing_lookup','approved_content'],'access'=>'approved_api_access'],
        'siteminder' => ['name'=>'SiteMinder','category'=>'channel_manager','channels'=>['multi_channel_inventory','reservations','rates_availability'],'access'=>'partner_or_customer_account'],
        'cloudbeds' => ['name'=>'Cloudbeds','category'=>'pms_channel_manager','channels'=>['reservations','inventory','rates_availability','guest_data'],'access'=>'marketplace_api_access'],
        'd_edge' => ['name'=>'D-EDGE','category'=>'channel_manager','channels'=>['multi_channel_inventory','reservations','rates_availability'],'access'=>'partner_or_customer_account'],
    ];

    public static function all(): array { return self::PLATFORMS; }
    public static function get(string $platform): ?array { return self::PLATFORMS[self::normalizeKey($platform)] ?? null; }
    public static function normalizeKey(string $platform): string
    {
        $key = strtolower(trim($platform));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key);
        return trim((string)$key, '_');
    }
    public static function supports(string $platform, string $capability): bool
    {
        $entry = self::get($platform);
        return $entry !== null && in_array($capability, $entry['channels'], true);
    }
    /** Every capability is off until the connection's granted scopes are verified. */
    public static function effectiveCapabilities(string $platform, array $grantedCapabilities, bool $connectionVerified): array
    {
        $entry = self::get($platform);
        if (!$entry) return [];
        $out = [];
        foreach ($entry['channels'] as $capability) {
            $out[$capability] = $connectionVerified && in_array($capability, $grantedCapabilities, true);
        }
        return $out;
    }
}
