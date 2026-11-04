<?php
/**
 * مواقع PHP بتحدد الصفحة بمعامل (tour.php?id=12): المعامل ده هوية الصفحة ولازم يفضل.
 * معاملات التتبع (utm_*, fbclid...) بتتشال، وأي معامل تاني (فلاتر، تواريخ، ترتيب، صفحات) معناه
 * إن الرابط نتيجة بحث أو تقويم مش صفحة، فمابيتزحفش (عشان الزحف مايتضخمش).
 */
final class SeoPageIdentity
{
    /** معاملات بتحدد صفحة بعينها */
    private const IDENTITY = ['id', 'tour_id', 'trip_id', 'tour', 'trip', 'product_id', 'package_id', 'activity_id', 'excursion_id', 'post', 'post_id', 'page_id', 'p', 'pid', 'slug', 'item', 'itemid', 'article', 'article_id', 'destination_id', 'dest', 'city_id', 'hotel_id'];

    /** تتبع وإحالة: تتشال من غير ما تغيّر الصفحة */
    public static function isTracking(string $key): bool
    {
        return (bool) preg_match('/^(?:utm_|fb_|mc_|pk_|mtm_|hsa_)/i', $key) || in_array(strtolower($key), ['fbclid', 'gclid', 'gbraid', 'wbraid', 'msclkid', 'ttclid', 'yclid', 'dclid', 'ref', 'referrer', 'source', 'campaign', '_ga', '_gl', 'igshid', 'sessionid', 'phpsessid', 'sid', '_tourfecto_audit'], true);
    }

    /**
     * @return array{ok:bool,query:string,reason:string} query = معاملات الهوية بس، مرتبة وبقيمة آمنة
     */
    public static function normalize(?string $query): array
    {
        $query = (string) $query;
        if ($query === '') return ['ok' => true, 'query' => '', 'reason' => 'none'];
        if (strlen($query) > 300) return ['ok' => false, 'query' => '', 'reason' => 'query_too_long'];
        parse_str($query, $params);
        $keep = [];
        foreach ($params as $k => $v) {
            $k = (string) $k;
            if (self::isTracking($k)) continue;
            if (!in_array(strtolower($k), self::IDENTITY, true)) return ['ok' => false, 'query' => '', 'reason' => 'filter_or_calendar_parameter'];
            if (!is_string($v) || !preg_match('/^[A-Za-z0-9._~-]{1,120}$/', $v)) return ['ok' => false, 'query' => '', 'reason' => 'identity_value_not_simple'];
            $keep[strtolower($k)] = $v;
        }
        if (count($keep) > 2) return ['ok' => false, 'query' => '', 'reason' => 'too_many_identity_parameters'];
        ksort($keep);
        return ['ok' => true, 'query' => http_build_query($keep, '', '&', PHP_QUERY_RFC3986), 'reason' => $keep ? 'identity' : 'tracking_only'];
    }

    /** الرابط بهويته فقط (من غير تتبع)، أو null لو الرابط مش صفحة. */
    public static function canonicalUrl(string $url): ?string
    {
        $p = parse_url($url);
        if (!$p || empty($p['host'])) return null;
        $n = self::normalize($p['query'] ?? '');
        if (!$n['ok']) return null;
        return ($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . ($p['path'] ?? '/') . ($n['query'] !== '' ? '?' . $n['query'] : '');
    }

    /** true لو الصفحة متحددة بمعامل (الموصل مش بيقدر يستهدفها بدقة بالمسار لوحده). */
    public static function isParameterPage(string $pathOrUrl): bool
    {
        return str_contains($pathOrUrl, '?');
    }
}
