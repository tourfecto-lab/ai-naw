<?php

use PHPUnit\Framework\TestCase;

/** Booking coupons: the discount is computed server-side inside the booking, counted atomically, and scoped to one site. */
final class SiteCouponsTest extends TestCase
{
    private const OWNER = 999980;
    private const SITE = 999980;
    private const OTHER_SITE = 999981;
    private const PRODUCT = 999980;
    private const OTHER_PRODUCT = 999981;
    private const PRICE = 100.00;

    private PDO $pdo;
    private SiteCouponService $svc;
    private string $date;

    protected function setUp(): void
    {
        $this->pdo = Database::getInstance()->getConnection();
        $this->svc = new SiteCouponService();
        $this->date = date('Y-m-d', strtotime('+20 days'));
        $this->cleanup();
        $p = $this->pdo;
        $p->exec("INSERT INTO users (id, email, password, company_name, created_at) VALUES (" . self::OWNER . ", 'coupons@tourfecto.test', 'x', 'T', NOW()) ON DUPLICATE KEY UPDATE email = email");
        foreach ([[self::SITE, 'cp-a', self::PRODUCT], [self::OTHER_SITE, 'cp-b', self::OTHER_PRODUCT]] as [$sid, $slug, $pid]) {
            $p->exec("INSERT INTO generated_websites (id, user_id, slug, status, content_json, created_at) VALUES ($sid, " . self::OWNER . ", '$slug', 'published', '{}', NOW()) ON DUPLICATE KEY UPDATE user_id = user_id");
            $p->exec("INSERT INTO crm_products (id, user_id, website_id, name, sku, price, currency, is_active, tour_slug) VALUES ($pid, " . self::OWNER . ", $sid, 'Felucca', 'CP-$pid', " . self::PRICE . ", 'USD', 1, 'felucca') ON DUPLICATE KEY UPDATE website_id = $sid, price = " . self::PRICE);
            (new InventoryService())->setDay(self::OWNER, $pid, $this->date, 20);
        }
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $p = $this->pdo;
        $p->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $p->exec('DELETE FROM booking_status_history WHERE booking_id IN (SELECT id FROM bookings WHERE user_id = ' . self::OWNER . ')');
            $p->exec('DELETE FROM bookings WHERE user_id = ' . self::OWNER);
            $p->exec('DELETE FROM inventory WHERE user_id = ' . self::OWNER);
            $p->exec('DELETE FROM crm_products WHERE id IN (' . self::PRODUCT . ',' . self::OTHER_PRODUCT . ')');
            $p->exec('DELETE FROM site_coupons WHERE website_id IN (' . self::SITE . ',' . self::OTHER_SITE . ')');
            $p->exec('DELETE FROM generated_websites WHERE id IN (' . self::SITE . ',' . self::OTHER_SITE . ')');
            $p->exec('DELETE FROM users WHERE id = ' . self::OWNER);
        } finally {
            $p->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function coupon(array $over = [], int $site = self::SITE): int
    {
        $r = $this->svc->create(self::OWNER, $site, $over + ['code' => 'SUMMER10', 'type' => 'percent', 'value' => 10]);
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        return $r['id'];
    }

    private function book(array $extra = [], int $product = self::PRODUCT): array
    {
        return (new BookingEngine())->createBooking(self::OWNER, $extra + [
            'product_id' => $product, 'start_date' => $this->date, 'customer_name' => 'Amira', 'adults_count' => 2, 'children_count' => 0, 'source' => 'website',
        ]);
    }

    private function used(int $id): int
    {
        return (int) $this->pdo->query('SELECT used_count FROM site_coupons WHERE id = ' . $id)->fetchColumn();
    }

    public function testPercentDiscountIsAppliedToTheStoredTotalAndCounted(): void
    {
        $id = $this->coupon();
        $b = $this->book(['coupon_code' => 'summer10']);
        $this->assertEqualsWithDelta(180.0, $b['total_amount'], 0.001, '2 adults x 100 = 200, minus 10%');
        $row = $this->pdo->query('SELECT total_amount, pricing_json FROM bookings WHERE id = ' . (int) $b['id'])->fetch(PDO::FETCH_ASSOC);
        $this->assertEqualsWithDelta(180.0, (float) $row['total_amount'], 0.001, 'payments read this column');
        $this->assertSame('SUMMER10', json_decode($row['pricing_json'], true)['coupon']['code']);
        $this->assertSame(1, $this->used($id));
    }

    public function testFixedDiscountAndTheNinetyPercentCap(): void
    {
        $this->coupon(['code' => 'MINUS50', 'type' => 'fixed', 'value' => 50]);
        $this->assertEqualsWithDelta(150.0, $this->book(['coupon_code' => 'MINUS50'])['total_amount'], 0.001);
        $this->coupon(['code' => 'HUGE', 'type' => 'fixed', 'value' => 100000]);
        $this->assertEqualsWithDelta(20.0, $this->book(['coupon_code' => 'HUGE'])['total_amount'], 0.001, 'a booking is never free: at most 90% off');
    }

    public function testQuotedTotalMustMatchTheDiscountedTotal(): void
    {
        $id = $this->coupon();
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->book(['coupon_code' => 'SUMMER10', 'expected_total' => 200]);
        } finally {
            $this->assertSame(0, $this->used($id), 'a rejected booking does not burn a use');
        }
    }

    public function testMatchingQuotedTotalPasses(): void
    {
        $this->coupon();
        $b = $this->book(['coupon_code' => 'SUMMER10', 'expected_total' => 180]);
        $this->assertEqualsWithDelta(180.0, $b['total_amount'], 0.001);
    }

    public function testMaxUsesIsEnforced(): void
    {
        $id = $this->coupon(['code' => 'ONCE', 'max_uses' => 1]);
        $this->book(['coupon_code' => 'ONCE']);
        $this->assertSame(1, $this->used($id));
        $this->expectException(InvalidArgumentException::class);
        $this->book(['coupon_code' => 'ONCE', 'customer_name' => 'Second']);
    }

    public function testCancellingABookingGivesTheCouponUseBackOnce(): void
    {
        $id = $this->coupon(['code' => 'ONCE', 'max_uses' => 1]);
        $b = $this->book(['coupon_code' => 'ONCE']);
        $this->assertSame(1, $this->used($id));
        $engine = new BookingEngine();
        $engine->cancelBooking(self::OWNER, (int) $b['id']);
        $this->assertSame(0, $this->used($id), 'a cancelled booking frees its use');
        $pricing = json_decode((string) $this->pdo->query('SELECT pricing_json FROM bookings WHERE id = ' . (int) $b['id'])->fetchColumn(), true);
        $this->assertTrue($pricing['coupon']['released']);
        $second = $this->book(['coupon_code' => 'ONCE', 'customer_name' => 'Second']);
        $this->assertSame(1, $this->used($id), 'the freed use can be redeemed again');
        $this->assertEqualsWithDelta(180.0, $second['total_amount'], 0.001);
    }

    public function testCancellingABookingWithoutACouponLeavesCountersAlone(): void
    {
        $id = $this->coupon();
        $this->book(['coupon_code' => 'SUMMER10']);
        $plain = $this->book(['customer_name' => 'Plain']);
        (new BookingEngine())->cancelBooking(self::OWNER, (int) $plain['id']);
        $this->assertSame(1, $this->used($id));
    }

    public function testCouponsAreScopedToTheirOwnSite(): void
    {
        $this->coupon(['code' => 'ONLYA']);
        $this->expectException(InvalidArgumentException::class);
        $this->book(['coupon_code' => 'ONLYA'], self::OTHER_PRODUCT);
    }

    public function testUnknownInactiveExpiredAndTooSmallCouponsAreRefused(): void
    {
        $inactive = $this->coupon(['code' => 'OFFLINE']);
        $this->svc->setActive(self::OWNER, self::SITE, $inactive, false);
        $this->coupon(['code' => 'OLD', 'valid_until' => date('Y-m-d', strtotime('-1 day'))]);
        $this->coupon(['code' => 'LATER', 'valid_from' => date('Y-m-d', strtotime('+5 days'))]);
        $this->coupon(['code' => 'BIGONLY', 'min_total' => 500]);
        foreach (['NOPE', 'OFFLINE', 'OLD', 'LATER', 'BIGONLY'] as $code) {
            try {
                $this->book(['coupon_code' => $code]);
                $this->fail("$code should be refused");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('كود الخصم', $e->getMessage(), $code);
            }
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM bookings WHERE user_id = ' . self::OWNER)->fetchColumn());
    }

    public function testBookingWithoutACouponIsUnchanged(): void
    {
        $this->coupon();
        $this->assertEqualsWithDelta(200.0, $this->book()['total_amount'], 0.001);
    }

    public function testValidationRules(): void
    {
        $bad = [
            ['code' => 'a', 'value' => 10], ['code' => 'GOOD1', 'value' => 0], ['code' => 'GOOD2', 'value' => 95], ['code' => 'GOOD3', 'value' => 'abc'],
            ['code' => 'GOOD4', 'value' => 10, 'type' => 'weird'], ['code' => 'GOOD5', 'value' => 10, 'max_uses' => 0],
            ['code' => 'GOOD6', 'value' => 10, 'valid_from' => '2030-02-01', 'valid_until' => '2030-01-01'], ['code' => 'bad code!', 'value' => 10],
        ];
        foreach ($bad as $in) {
            $this->assertFalse(SiteCouponService::validate($in + ['type' => 'percent'])['ok'], json_encode($in));
        }
        $this->assertTrue(SiteCouponService::validate(['code' => 'ok-1', 'type' => 'percent', 'value' => 90])['ok']);
    }

    public function testOnlyTheOwnerManagesCoupons(): void
    {
        $id = $this->coupon();
        $this->assertFalse($this->svc->create(999999, self::SITE, ['code' => 'STEAL', 'type' => 'percent', 'value' => 5])['ok']);
        $this->assertFalse($this->svc->setActive(999999, self::SITE, $id, false));
        $this->assertFalse($this->svc->delete(999999, self::SITE, $id));
        $this->assertSame([], $this->svc->listFor(999999, self::SITE));
        $this->assertFalse($this->svc->create(self::OWNER, self::SITE, ['code' => 'SUMMER10', 'type' => 'percent', 'value' => 5])['ok'], 'codes are unique per site');
    }

    public function testQuoteEvaluationMatchesTheBookingDiscount(): void
    {
        $this->coupon();
        $r = $this->svc->evaluate(self::SITE, 'summer10', 200.0);
        $this->assertTrue($r['ok']);
        $this->assertEqualsWithDelta(20.0, $r['discount'], 0.001);
        $this->assertFalse($this->svc->evaluate(self::OTHER_SITE, 'SUMMER10', 200.0)['ok']);
        $this->assertTrue($this->svc->hasActive(self::SITE));
        $this->assertFalse($this->svc->hasActive(self::OTHER_SITE));
    }

    private function controller(array $input = []): WebsiteBuilderController
    {
        $c = (new ReflectionClass(WebsiteBuilderController::class))->newInstanceWithoutConstructor();
        $db = new ReflectionProperty(Controller::class, 'db');
        $db->setAccessible(true);
        $db->setValue($c, Database::getInstance());
        $data = new ReflectionProperty(Controller::class, 'data');
        $data->setAccessible(true);
        $data->setValue($c, $input);
        return $c;
    }

    public function testBookingFormShowsTheCouponFieldOnlyWhenTheSiteHasAnActiveCoupon(): void
    {
        $form = new ReflectionMethod(WebsiteBuilderController::class, 'siteBookingFormHtml');
        $form->setAccessible(true);
        $c = $this->controller();
        $this->assertStringNotContainsString('name="coupon_code"', $form->invoke($c, 'cp-a', 'felucca', 'tour', self::OWNER, self::PRODUCT));
        $this->coupon();
        $this->assertStringContainsString('name="coupon_code"', $form->invoke($c, 'cp-a', 'felucca', 'tour', self::OWNER, self::PRODUCT));
        $this->assertStringNotContainsString('name="coupon_code"', $form->invoke($c, 'cp-b', 'felucca', 'tour', self::OWNER, self::OTHER_PRODUCT), 'the other site has no coupon');
    }

    public function testPublicQuoteShowsTheDiscountedTotalOrAFriendlyError(): void
    {
        $this->coupon();
        $m = new ReflectionMethod(WebsiteBuilderController::class, 'withCoupon');
        $m->setAccessible(true);
        $site = (new GeneratedWebsite())->find(self::SITE);
        $quote = ['total' => 200.0, 'currency' => 'USD'];

        $ok = $m->invoke($this->controller(['coupon_code' => 'summer10']), $site, $quote);
        $this->assertEqualsWithDelta(180.0, $ok['total'], 0.001);
        $this->assertEqualsWithDelta(200.0, $ok['total_before_discount'], 0.001);
        $this->assertSame('SUMMER10', $ok['coupon']['code']);

        $bad = $m->invoke($this->controller(['coupon_code' => 'NOPE']), $site, $quote);
        $this->assertEqualsWithDelta(200.0, $bad['total'], 0.001, 'an invalid code never changes the price');
        $this->assertArrayHasKey('coupon_error', $bad);

        $none = $m->invoke($this->controller([]), $site, $quote);
        $this->assertSame($quote, $none);
    }
}
