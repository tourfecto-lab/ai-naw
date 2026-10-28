<?php

/**
 * Tourfecto - Competitor Intelligence: Product Price Tracker Service (G7)
 * @version 1.0.0
 *
 * تتبع سعر لكل منتج/SKU بجدولة منتظمة - بيغلق فجوة "الاستخراج
 * الانتهازي" القديم (أول سعر واحد عند تغيّر الصفحة فقط) بجمع كل
 * الأسعار الواضحة من لقطات صفحات التّسعير/المنتجات/العروض وتخزينها
 * بسجل زمني في ci_product_prices.
 *
 * - trackFromSnapshot(): يُستدعى من MonitoringEngine بعد كل لقطة
 *   ناجحة لصفحة pricing/products/offers - يعمل "الجدولة المنتظمة".
 * - recordPrice(): تسجيل يدوي/برمجي لسعر منتج معين.
 * - listProducts(): آخر سعر لكل منتج + عدد التغييرات + الاتجاه.
 * - history(): السلسلة الزمنية لسعر منتج واحد.
 *
 * المبادئ: يستخدم PriceExtractor::extractAll() (قواعد شفافة - لا
 * تخمين)، ويربط اسم المنتج بنص قبل السعر في نفس الصفحة كـ heuristic
 * واضح، ولا يدّعي دقة تسمية كاملة - يخزن المصدر ووقت الرصد كأدلة.
 */
class ProductPriceTrackerService
{
    /** أنواع الصفحات اللي فيها أسعار منتجات - تُفعّل الجمع الآلي */
    private const PRICE_PAGE_TYPES = ['pricing', 'products', 'offers'];

    /**
     * يستخرج كل أسعار المنتجات من لقطة صفحة تسعير/منتجات/عروض ويخزنها.
     * لازم تُستدعى بعد نجاح اللقطة فقط (fetch_status = ok).
     *
     * @return array{page_type:string, extracted:int, saved:int, source_url:?string}
     */
    public function trackFromSnapshot(CiSnapshot $snapshot): array
    {
        $pageType = (string) $snapshot->getAttribute('page_type');
        if (!in_array($pageType, self::PRICE_PAGE_TYPES, true)) {
            return ['page_type' => $pageType, 'extracted' => 0, 'saved' => 0, 'source_url' => (string) $snapshot->getAttribute('url')];
        }
        if ((string) $snapshot->getAttribute('fetch_status') === 'failed') {
            return ['page_type' => $pageType, 'extracted' => 0, 'saved' => 0, 'source_url' => (string) $snapshot->getAttribute('url')];
        }

        $text = (string) $snapshot->getAttribute('normalized_excerpt');
        $prices = PriceExtractor::extractAll($text, 50);
        if (empty($prices)) {
            return ['page_type' => $pageType, 'extracted' => 0, 'saved' => 0, 'source_url' => (string) $snapshot->getAttribute('url')];
        }

        $saved = 0;
        foreach ($prices as $p) {
            $productName = $this->normalizeProductName((string) ($p['label'] ?? ''));
            $recorded = $this->recordPrice(
                (int) $snapshot->getAttribute('competitor_id'),
                $productName,
                (float) $p['amount'],
                (string) $p['currency'],
                (string) $snapshot->getAttribute('url'),
                $pageType,
                (string) $snapshot->getAttribute('captured_at') ?: date('Y-m-d H:i:s')
            );
            if ($recorded['success']) {
                $saved++;
            }
        }

        return ['page_type' => $pageType, 'extracted' => count($prices), 'saved' => $saved, 'source_url' => (string) $snapshot->getAttribute('url')];
    }

    /**
     * يسجل سعر منتج واحد (يدويًا أو آليًا من لقطة). يرجّع success=false
     * لو الاسم/السعر غير صالحين.
     *
     * @return array{success:bool, error?:string, product_price?:array}
     */
    public function recordPrice(
        int $competitorId,
        string $productName,
        float $price,
        string $currency = 'USD',
        ?string $sourceUrl = null,
        ?string $pageType = null,
        ?string $detectedAt = null
    ): array {
        $productName = $this->normalizeProductName($productName);
        if ($productName === '') {
            return ['success' => false, 'error' => 'invalid_product_name'];
        }
        if (!is_finite($price) || $price <= 0) {
            return ['success' => false, 'error' => 'invalid_price'];
        }
        $currency = trim($currency);
        if ($currency === '' || mb_strlen($currency) > 8) {
            return ['success' => false, 'error' => 'invalid_currency'];
        }

        $alerts = class_exists('PriceChangeAlertService') ? new PriceChangeAlertService() : null;
        $prev = $alerts ? $alerts->previousPrice($competitorId, $productName) : null;

        $row = new CiProductPrice([
            'competitor_id' => $competitorId,
            'product_name' => $productName,
            'price' => round($price, 2),
            'currency' => $currency,
            'source_url' => $sourceUrl ?: null,
            'page_type' => $pageType ?: null,
            'detected_at' => $detectedAt ?: date('Y-m-d H:i:s'),
        ]);
        $row->save();

        // تنبيه لو سعر المنتج ده بالذات اتغير ≥ 5% عن آخر قراءة (+ مقارنة بسعرك)
        $alert = null;
        if ($alerts) {
            try {
                $alert = $alerts->check($competitorId, $productName, $prev, round($price, 2), $currency, $sourceUrl);
            } catch (Throwable $e) {
                $alert = null;
            }
        }

        return ['success' => true, 'product_price' => $row->toArray(), 'price_alert' => $alert];
    }

    /**
     * آخر سعر لكل منتج للمنافس + أول سعر (لحساب تغيّر السعر) + عدد
     * القراءات. الترتيب أبجدي حسب اسم المنتج.
     *
     * @return array<int, array{product_name:string, latest_price:?float,
     *                           first_price:?float, currency:?string, readings:int,
     *                           last_detected_at:?string, page_type:?string}>
     */
    public function listProducts(int $competitorId, int $limit = 200): array
    {
        $rows = Database::getInstance()->query(
            "SELECT product_name, price, currency, page_type, detected_at
             FROM ci_product_prices
             WHERE competitor_id = ?
             ORDER BY detected_at ASC, id ASC
             LIMIT 10000",
            [$competitorId]
        );

        $latest = [];
        $first = [];
        $counts = [];
        foreach ($rows as $row) {
            $name = (string) $row['product_name'];
            $latest[$name] = $row; // آخر صف (زمنيًا)
            $first[$name] = $first[$name] ?? $row;
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        $out = [];
        foreach ($latest as $name => $row) {
            $out[] = [
                'product_name' => $name,
                'latest_price' => (float) $row['price'],
                'first_price' => (float) $first[$name]['price'],
                'currency' => $row['currency'],
                'readings' => $counts[$name] ?? 1,
                'last_detected_at' => $row['detected_at'],
                'page_type' => $row['page_type'],
            ];
        }

        usort($out, static fn ($a, $b) => strcasecmp($a['product_name'], $b['product_name']));
        return array_slice($out, 0, $limit);
    }

    /**
     * السلسلة الزمنية لسعر منتج واحد (للرسم البياني).
     *
     * @return array<int, array{price:float, currency:string, source_url:?string,
     *                           page_type:?string, detected_at:string}>
     */
    public function history(int $competitorId, string $productName, int $limit = 100): array
    {
        $productName = $this->normalizeProductName($productName);
        if ($productName === '') {
            return [];
        }
        return Database::getInstance()->query(
            "SELECT price, currency, source_url, page_type, detected_at
             FROM ci_product_prices
             WHERE competitor_id = ? AND product_name = ?
             ORDER BY detected_at ASC, id ASC
             LIMIT ?",
            [$competitorId, $productName, $limit]
        );
    }

    /**
     * يطبّع اسم المنتج: trim + ضغط مسافات + حد أقصى للطول، ويخلّيه
     * صغير الحروف للمطابقة الموحّدة عبر اللقطات. اسم فاضي يرجع ''.
     */
    private function normalizeProductName(string $name): string
    {
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
        $name = mb_strtolower($name);
        return mb_substr($name, 0, 255);
    }
}
