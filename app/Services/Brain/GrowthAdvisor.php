<?php
/**
 * Tourfecto - العقل الاستراتيجي: بيانات توصيات النمو
 *
 * بيجمع من بيانات الشركة الحقيقية (كلها مقيّدة بـ user_id):
 * - العملاء المحتملين اللي متابعتهم اتأخرت (crm_leads عن طريق crm_contacts).
 * - بلاد العملاء المحتملين في آخر 180 يوم (crm_contacts.country) — عشان نلاقي أسواق بتجيب طلب ومش مستهدفة.
 * - الرحلات اللي نفس العميل بيحجزها مع بعض (bookings) — مرشحة لباقة.
 * - أسعار رحلات المنافسين اللي المراقبة لقطتها (ci_product_prices) قدام أسعار رحلاتك بنفس الاسم تقريبًا ونفس العملة.
 * أي مصدر مش متاح بيرجع null. الربحية محتاجة تكلفة الرحلات، ودي مش متسجلة في المنصة، فمفيش قرار عنها.
 */
final class GrowthAdvisor
{
    private const STOP = ['tour', 'tours', 'trip', 'trips', 'day', 'days', 'from', 'private', 'with', 'and', 'the', 'full', 'half', 'excursion', 'excursions', 'visit', 'package', 'night', 'nights', 'رحلة', 'جولة', 'يوم', 'من', 'الى', 'إلى', 'مع'];
    /** أسماء/أكواد البلاد الشائعة في crm_contacts.country → ISO2 */
    private const COUNTRY = ['united kingdom' => 'GB', 'uk' => 'GB', 'england' => 'GB', 'great britain' => 'GB', 'united states' => 'US', 'usa' => 'US', 'us' => 'US', 'america' => 'US', 'germany' => 'DE',
        'deutschland' => 'DE', 'france' => 'FR', 'italy' => 'IT', 'italia' => 'IT', 'spain' => 'ES', 'españa' => 'ES', 'canada' => 'CA', 'australia' => 'AU', 'netherlands' => 'NL', 'poland' => 'PL',
        'russia' => 'RU', 'china' => 'CN', 'japan' => 'JP', 'india' => 'IN', 'saudi arabia' => 'SA', 'united arab emirates' => 'AE', 'uae' => 'AE', 'egypt' => 'EG', 'mexico' => 'MX', 'brazil' => 'BR',
        'switzerland' => 'CH', 'austria' => 'AT', 'belgium' => 'BE', 'ireland' => 'IE', 'portugal' => 'PT', 'greece' => 'GR', 'sweden' => 'SE', 'norway' => 'NO', 'denmark' => 'DK', 'turkey' => 'TR',
        'بريطانيا' => 'GB', 'المملكة المتحدة' => 'GB', 'أمريكا' => 'US', 'امريكا' => 'US', 'الولايات المتحدة' => 'US', 'ألمانيا' => 'DE', 'المانيا' => 'DE', 'فرنسا' => 'FR', 'إيطاليا' => 'IT', 'ايطاليا' => 'IT',
        'إسبانيا' => 'ES', 'اسبانيا' => 'ES', 'كندا' => 'CA', 'أستراليا' => 'AU', 'استراليا' => 'AU', 'السعودية' => 'SA', 'الإمارات' => 'AE', 'الامارات' => 'AE', 'مصر' => 'EG', 'روسيا' => 'RU', 'الصين' => 'CN'];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function forCompany(int $uid): array
    {
        $overdue = $this->first("SELECT COUNT(*) AS c FROM crm_leads l JOIN crm_contacts c ON c.id = l.contact_id
            WHERE c.user_id = ? AND l.status IN ('new','nurturing','qualified')
              AND ((l.next_follow_up_at IS NOT NULL AND l.next_follow_up_at < NOW())
                OR (l.next_follow_up_at IS NULL AND COALESCE(l.last_engagement_at, l.created_at) < DATE_SUB(NOW(), INTERVAL 3 DAY)))", [$uid]);
        $countries = $this->rows("SELECT country, COUNT(*) AS c FROM crm_contacts WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 180 DAY) AND country IS NOT NULL AND country <> '' GROUP BY country", [$uid]);
        $pairs = $this->rows("SELECT b1.product_id AS a, b2.product_id AS b, COUNT(DISTINCT b1.customer_id) AS customers
            FROM bookings b1 JOIN bookings b2 ON b2.user_id = b1.user_id AND b2.customer_id = b1.customer_id AND b2.product_id > b1.product_id
              AND ABS(DATEDIFF(b2.start_date, b1.start_date)) <= 21
            WHERE b1.user_id = ? AND b1.customer_id IS NOT NULL AND b1.status IN ('confirmed','completed') AND b2.status IN ('confirmed','completed')
              AND b1.created_at > DATE_SUB(NOW(), INTERVAL 365 DAY)
            GROUP BY b1.product_id, b2.product_id HAVING customers >= 3 ORDER BY customers DESC LIMIT 5", [$uid]);
        $products = $this->rows('SELECT id, name, price, currency FROM crm_products WHERE user_id = ? AND is_active = 1 LIMIT 200', [$uid]) ?? [];
        $competitorPrices = $this->rows("SELECT p.product_name, p.price, p.currency, p.detected_at, c.competitor_domain
            FROM ci_product_prices p JOIN competitors c ON c.id = p.competitor_id
            WHERE c.user_id = ? AND p.price IS NOT NULL AND p.price > 0 AND p.detected_at > DATE_SUB(NOW(), INTERVAL 120 DAY)
            ORDER BY p.detected_at DESC LIMIT 500", [$uid]);

        $byId = [];
        foreach ($products as $p) $byId[(int) $p['id']] = $p;
        return [
            'overdue_leads' => $overdue === null ? null : (int) ($overdue['c'] ?? 0),
            'market_mix' => $countries === null ? null : self::marketMix($countries),
            'bundles' => $pairs === null ? null : array_values(array_filter(array_map(static function ($r) use ($byId) {
                $a = $byId[(int) $r['a']] ?? null; $b = $byId[(int) $r['b']] ?? null;
                if (!$a || !$b) return null;
                return ['a' => $a['name'], 'b' => $b['name'], 'customers' => (int) $r['customers'],
                    'price' => ($a['price'] !== null && $b['price'] !== null && $a['currency'] === $b['currency']) ? round((float) $a['price'] + (float) $b['price'], 2) : null, 'currency' => $a['currency']];
            }, $pairs))),
            'pricing' => $competitorPrices === null ? null : self::pricing($products, $competitorPrices),
            'costs_known' => false,
        ];
    }

    /** نصيب كل بلد من العملاء المحتملين (ISO2) */
    public static function marketMix(array $rows): array
    {
        $by = [];
        $total = 0;
        foreach ($rows as $r) {
            $raw = trim(mb_strtolower((string) $r['country']));
            $cc = preg_match('/^[a-z]{2}$/', $raw) ? strtoupper($raw) : (self::COUNTRY[$raw] ?? null);
            if ($cc === 'UK') $cc = 'GB';
            if (!$cc) continue;
            $by[$cc] = ($by[$cc] ?? 0) + (int) $r['c'];
            $total += (int) $r['c'];
        }
        arsort($by);
        $out = [];
        foreach ($by as $cc => $n) $out[] = ['country' => $cc, 'contacts' => $n, 'share' => $total ? round($n / $total, 2) : 0.0];
        return $out;
    }

    /** كلمات الاسم المميزة (من غير كلمات عامة زي tour/day/private) */
    public static function tokens(string $name): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique(array_filter($words, static fn ($w) => mb_strlen($w) >= 4 && !in_array($w, self::STOP, true))));
    }

    /**
     * رحلاتك قدام رحلات المنافسين بنفس الاسم تقريبًا (كلمتين مميزتين مشتركتين أو أكتر) ونفس العملة
     * @return array<int,array{product:string,my_price:float,currency:string,competitor_median:float,diff_pct:float,matches:array}>
     */
    public static function pricing(array $products, array $competitorPrices): array
    {
        $out = [];
        foreach ($products as $p) {
            if ($p['price'] === null || (float) $p['price'] <= 0) continue;
            $mine = self::tokens((string) $p['name']);
            if (count($mine) < 2) continue;
            $matches = [];
            foreach ($competitorPrices as $c) {
                if (strtoupper((string) $c['currency']) !== strtoupper((string) $p['currency'])) continue;
                if (count(array_intersect($mine, self::tokens((string) $c['product_name']))) < 2) continue;
                $matches[(string) $c['competitor_domain'] . '|' . (string) $c['product_name']] ??= ['domain' => (string) $c['competitor_domain'], 'name' => (string) $c['product_name'], 'price' => (float) $c['price'], 'date' => substr((string) $c['detected_at'], 0, 10)];
            }
            if (count($matches) < 2) continue;
            $prices = array_column($matches, 'price');
            sort($prices);
            $n = count($prices);
            $median = $n % 2 ? $prices[intdiv($n, 2)] : ($prices[$n / 2 - 1] + $prices[$n / 2]) / 2;
            $out[] = ['product' => (string) $p['name'], 'product_id' => (int) $p['id'], 'my_price' => (float) $p['price'], 'currency' => strtoupper((string) $p['currency']), 'competitor_median' => round($median, 2),
                'diff_pct' => round(((float) $p['price'] / $median - 1) * 100, 1), 'matches' => array_slice(array_values($matches), 0, 5)];
        }
        return $out;
    }

    private function rows(string $sql, array $params): ?array
    {
        try { $r = $this->db->query($sql, $params); return is_array($r) ? $r : null; } catch (Throwable $e) { return null; }
    }

    private function first(string $sql, array $params): ?array
    {
        $r = $this->rows($sql, $params);
        return $r === null ? null : ($r[0] ?? []);
    }
}
