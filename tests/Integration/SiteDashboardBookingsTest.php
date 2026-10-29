<?php

use PHPUnit\Framework\TestCase;

/** The site dashboard's Bookings tab must show only the bookings of that site and never leak another site's customers. */
final class SiteDashboardBookingsTest extends TestCase
{
    private const USER = 999950;
    private const SITE_A = 999950;
    private const SITE_B = 999951;
    private const PRODUCT_A = 999950;
    private const PRODUCT_B = 999951;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::getInstance()->getConnection();
        $this->cleanup();
        $p = $this->pdo;
        $p->exec("INSERT INTO users (id, email, password, company_name, created_at) VALUES (" . self::USER . ", 'site-bookings@tourfecto.test', 'x', 'T', NOW()) ON DUPLICATE KEY UPDATE email = email");
        foreach ([[self::SITE_A, 'dash-a'], [self::SITE_B, 'dash-b']] as [$id, $slug]) {
            $p->exec("INSERT INTO generated_websites (id, user_id, slug, status, content_json, created_at) VALUES ($id, " . self::USER . ", '$slug', 'published', '{}', NOW()) ON DUPLICATE KEY UPDATE user_id = user_id");
        }
        foreach ([[self::PRODUCT_A, self::SITE_A, 'Felucca'], [self::PRODUCT_B, self::SITE_B, 'Safari']] as [$id, $site, $name]) {
            $p->exec("INSERT INTO crm_products (id, user_id, website_id, name, sku, price, currency, is_active) VALUES ($id, " . self::USER . ", $site, '$name', 'DASH-$id', 100, 'USD', 1) ON DUPLICATE KEY UPDATE website_id = $site");
        }
        $this->booking(self::PRODUCT_A, 'Amira Hassan', 'pending', 100);
        $this->booking(self::PRODUCT_A, 'Omar Said', 'confirmed', 250);
        $this->booking(self::PRODUCT_B, 'Other Site Customer', 'confirmed', 999);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $this->pdo->exec('DELETE FROM bookings WHERE user_id = ' . self::USER);
            $this->pdo->exec('DELETE FROM crm_products WHERE id IN (' . self::PRODUCT_A . ',' . self::PRODUCT_B . ')');
            $this->pdo->exec('DELETE FROM generated_websites WHERE id IN (' . self::SITE_A . ',' . self::SITE_B . ')');
            $this->pdo->exec('DELETE FROM users WHERE id = ' . self::USER);
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function booking(int $product, string $name, string $status, float $total): void
    {
        $this->pdo->prepare("INSERT INTO bookings (booking_reference, user_id, product_id, customer_name, customer_phone, start_date, adults_count, children_count, total_amount, currency, status, source)
                             VALUES (?, ?, ?, ?, '+201000000000', '2030-01-01', 2, 0, ?, 'USD', ?, 'website')")
            ->execute(['BK-' . strtoupper(bin2hex(random_bytes(4))), self::USER, $product, $name, $total, $status]);
    }

    private function rows(int $siteId, string $status = '', string $q = ''): array
    {
        $c = (new ReflectionClass(SiteDashboardController::class))->newInstanceWithoutConstructor();
        $db = new ReflectionProperty(Controller::class, 'db');
        $db->setAccessible(true);
        $db->setValue($c, Database::getInstance());
        $m = new ReflectionMethod($c, 'siteBookingRows');
        $m->setAccessible(true);
        return $m->invoke($c, (new GeneratedWebsite())->find($siteId), $status, $q);
    }

    public function testASiteSeesOnlyItsOwnBookings(): void
    {
        $names = array_column($this->rows(self::SITE_A), 'customer_name');
        sort($names);
        $this->assertSame(['Amira Hassan', 'Omar Said'], $names);
        $this->assertSame(['Other Site Customer'], array_column($this->rows(self::SITE_B), 'customer_name'));
    }

    public function testStatusFilterAndSearch(): void
    {
        $this->assertSame(['Amira Hassan'], array_column($this->rows(self::SITE_A, 'pending'), 'customer_name'));
        $this->assertSame(['Omar Said'], array_column($this->rows(self::SITE_A, '', 'omar'), 'customer_name'));
        $this->assertSame([], $this->rows(self::SITE_A, 'bogus-status', 'zzz'));
        $this->assertCount(2, $this->rows(self::SITE_A, 'bogus-status'), 'an unknown status is ignored, not injected');
    }

    public function testSearchTreatsPercentSignsLiterally(): void
    {
        $this->assertSame([], $this->rows(self::SITE_A, '', '%'));
    }

    public function testCsvCellsThatLookLikeFormulasAreNeutralised(): void
    {
        $this->assertSame("'=SUM(A1:A9)", SiteDashboardController::csvSafe('=SUM(A1:A9)'));
        $this->assertSame("'@cmd", SiteDashboardController::csvSafe('@cmd'));
        $this->assertSame('Amira', SiteDashboardController::csvSafe('Amira'));
    }
}
