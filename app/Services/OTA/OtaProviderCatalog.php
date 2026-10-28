<?php
final class OtaProviderCatalog
{
    public static function all(): array
    {
        return [
            'getyourguide'=>['label'=>'GetYourGuide','mode'=>'legacy_partner','ready'=>true,'fields'=>['credential','partner_id'],'docs'=>'https://partner.getyourguide.com/','description'=>'Existing partner catalog access. Supplier booking and inventory synchronization are not enabled.'],
            'viator'=>['label'=>'Viator','mode'=>'legacy_partner','ready'=>true,'fields'=>['credential','partner_id'],'docs'=>'https://docs.viator.com/partner-api/','description'=>'Existing partner catalog access. Supplier booking and inventory synchronization are not enabled.'],
            'bokun'=>['label'=>'Bókun','mode'=>'supplier_read','ready'=>true,'fields'=>['access_key','secret_key','account_id'],'docs'=>'https://bokun.dev/','description'=>'Authenticated supplier products, booking snapshots and availability lookup. Enter your numeric vendor ID and channel API keys. Read-only; no bookings or inventory are pushed.'],
            'rezdy'=>['label'=>'Rezdy','mode'=>'supplier_read','ready'=>true,'fields'=>['api_key','account_id'],'docs'=>'https://developers.rezdy.com/rezdyapi/index-supplier.html','description'=>'Supplier products, booking snapshots and availability lookup. Read-only; no bookings or inventory are pushed.'],
            'booking'=>['label'=>'Booking.com','mode'=>'approved_connectivity','ready'=>false,'fields'=>['account_id','approval_reference'],'docs'=>'https://developers.booking.com/connectivity/docs','description'=>'Hotel connectivity setup profile. Requires an approved connectivity provider, machine-account token authentication, room/rate mappings and a certified adapter. No live API adapter is enabled in this release.'],
            'expedia'=>['label'=>'Expedia','mode'=>'approved_connectivity','ready'=>false,'fields'=>['account_id','approval_reference'],'docs'=>'https://developers.expediagroup.com/supply/lodging','description'=>'Hotel connectivity setup profile. Requires Connectivity Partner Program approval and certified reservation, availability and rate adapters. No live API adapter is enabled in this release.'],
            'klook'=>['label'=>'Klook','mode'=>'inbound_octo','ready'=>false,'fields'=>['account_id','approval_reference'],'docs'=>'https://klook.gitbook.io/openapi/getting-started/integration-process','description'=>'Supplier OCTO integration setup profile. Tourfecto must expose a tested booking API and issue credentials to Klook. The OCTO booking adapter is not enabled in this release; partner approval alone does not activate it.'],
        ];
    }
    public static function get(string $platform): array
    { $v=self::all()[$platform]??null; if(!$v)throw new InvalidArgumentException('Unsupported OTA platform');return $v; }
    public static function supplier(string $platform): bool { return self::get($platform)['mode']==='supplier_read'; }
}
