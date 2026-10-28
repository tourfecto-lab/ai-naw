<?php

/**
 * Tourfecto - Website Lead Form Integration Test (بند 3: نموذج تواصل/حجز)
 * بيتخطى تلقائيًا (markTestSkipped) لو DB غير متاحة.
 *
 * بيفحص نموذج التواصل/طلب الحجز الجديد في الصفحة الرئيسية للموقع المولّد:
 *   1) نموذج الـ lead موجود في HTML صفحة الجولات (id="wsLeadForm" + الحقول
 *      visitor_name/phone/email/message وبيانات action توجّه لـ /sites/{slug}/lead).
 *   2) نفس النموذج موجود في صفحة الفندق.
 *   3) زرار "احجز الآن" (ws-btn-outline) بيظهر في الهيرو بس لو الموقع ليه
 *      crm_products مرتبط (website_id + is_active=1) وبيوجّه لأقرب قسم جولات.
 *   4) زرار "احجز الآن" مش بيظهر لو مفيش منتجات مرتبطة.
 *   5) submitLead (الـ endpoint الموجود) بيخزّن WebsiteLead فعليًا بـ status='new'.
 *   6) submitLead بيفشل (422) لو visitor_name فاضي من غير ما يسجّل صف.
 * @version 1.0.0  @date 2026-09-01
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/WebsiteBuilderService.php';
require_once __DIR__ . '/../../app/Models/GeneratedWebsite.php';
require_once __DIR__ . '/../../app/Models/CrmProduct.php';
require_once __DIR__ . '/../../app/Models/WebsiteLead.php';
require_once __DIR__ . '/../../app/Controllers/WebsiteBuilderController.php';

final class WebsiteLeadFormIntegrationTest extends TestCase
{
    private const TEST_USER_ID = 999004;
    private const TEST_WEBSITE_ID = 999004;
    private const TEST_SLUG = 'lead-form-test-site';

    private static ?PDO $pdo = null;
    private static bool $dbChecked = false;
    private static ?WebsiteBuilderController $controller = null;

    private function db(): ?PDO
    {
        if (self::$dbChecked) {
            return self::$pdo;
        }
        self::$dbChecked = true;

        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) {
                foreach ([$app . '/Config/app.php', $app . '/Config/database.php'] as $cfg) {
                    if (file_exists($cfg)) {
                        require_once $cfg;
                    }
                }
            }
            foreach ([
                'Database' => '/Core/Database.php',
                'Logger' => '/Core/Logger.php',
                'Model' => '/Core/Model.php',
                'Validator' => '/Core/Validator.php',
                'Controller' => '/Core/Controller.php',
            ] as $class => $relPath) {
                if (!class_exists($class) && file_exists($app . $relPath)) {
                    require_once $app . $relPath;
                }
            }

            $db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $conn = $ref->getValue($db);
            if (!$conn instanceof PDO) {
                return null;
            }

            foreach (['users', 'generated_websites', 'website_leads', 'crm_products'] as $table) {
                $found = $conn->query("SHOW TABLES LIKE '{$table}'")->fetchAll();
                if (empty($found)) {
                    return null;
                }
            }

            self::$pdo = $conn;
            return self::$pdo;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function siteContent(string $industry): string
    {
        return json_encode([
            'industry' => $industry,
            'business_name' => 'Lead Form Test Agency',
            'language' => 'ar',
            'contact' => ['whatsapp' => '+201001234567'],
            'tours' => [
                ['slug' => 'nile-cruise', 'name' => 'رحلة النيل', 'short_description' => 'جولة نيلية', 'price' => '350$'],
            ],
            'rooms' => [
                ['slug' => 'nile-view', 'name' => 'غرفة نيل فيو', 'short_description' => 'إطلالة', 'price' => '120$'],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    protected function setUp(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            $this->markTestSkipped('DB غير متاحة');
        }

        $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                    VALUES (999004, 'lead-form@tourfecto.test', 'x', 'Test', NOW())
                    ON DUPLICATE KEY UPDATE email = email");

        $pdo->exec("INSERT INTO generated_websites
                        (id, user_id, slug, status, content_json, created_at)
                    VALUES (999004, 999004, 'lead-form-test-site', 'published', "
                        . $pdo->quote($this->siteContent('tours')) . ", NOW())
                    ON DUPLICATE KEY UPDATE user_id = user_id, content_json = VALUES(content_json), status = 'published'");

        $pdo->exec("DELETE FROM website_leads WHERE website_id = 999004");
        $pdo->exec("DELETE FROM crm_products WHERE website_id = 999004");

        if (self::$controller === null) {
            self::$controller = new WebsiteBuilderController();
        }

        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
    }

    protected function tearDown(): void
    {
        $pdo = self::$pdo;
        if ($pdo === null) {
            return;
        }
        $pdo->exec("DELETE FROM website_leads WHERE website_id = 999004");
        $pdo->exec("DELETE FROM crm_products WHERE website_id = 999004");
    }

    private function invokePrivate(object $obj, string $method, array $args)
    {
        $ref = new ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }

    private function setControllerData(array $data): void
    {
        $ref = new ReflectionProperty(self::$controller, 'data');
        $ref->setAccessible(true);
        $ref->setValue(self::$controller, $data);
    }

    private function renderHome(string $industry): string
    {
        $website = (new GeneratedWebsite())->find(self::TEST_WEBSITE_ID);
        $content = $website->getContent();
        $content['industry'] = $industry;

        $ref = new ReflectionMethod(WebsiteBuilderController::class, 'siteLangAttrs');
        $ref->setAccessible(true);
        $ref->invoke(self::$controller, $content);

        $render = $industry === 'hotel'
            ? 'renderHotelHome'
            : 'renderToursHome';
        return $this->invokePrivate(self::$controller, $render, [
            self::TEST_SLUG,
            $content,
            '',
            '',
            '',
            '',
            'gold',
            '',
            self::TEST_WEBSITE_ID,
        ]);
    }

    private function addLinkedProduct(bool $active = true): void
    {
        self::$pdo->exec(
            "INSERT INTO crm_products (user_id, website_id, tour_slug, name, price, currency, is_active)
             VALUES (999004, 999004, 'nile-cruise', 'رحلة النيل', 350.00, 'USD', " . (int) $active . ")"
        );
    }

    public function testToursHomeContainsLeadForm(): void
    {
        $html = $this->renderHome('tours');

        $this->assertStringContainsString('id="wsLeadForm"', $html, 'نموذج الـ lead لازم يكون موجود في الصفحة');
        $this->assertStringContainsString('data-action="/sites/lead-form-test-site/lead"', $html, 'النموذج لازم يبعث لـ submitLead');
        $this->assertStringContainsString('name="visitor_name"', $html);
        $this->assertStringContainsString('name="phone"', $html);
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('name="message"', $html);
        $this->assertStringContainsString('/sites/lead-form-test-site/lead', $html, 'الـ JS لازم يستدعي endpoint الـ lead');
    }

    public function testHotelHomeContainsLeadForm(): void
    {
        $html = $this->renderHome('hotel');

        $this->assertStringContainsString('id="wsLeadForm"', $html);
        $this->assertStringContainsString('data-action="/sites/lead-form-test-site/lead"', $html);
        $this->assertStringContainsString('name="visitor_name"', $html);
    }

    public function testBookNowButtonAppearsWhenLinkedProductExists(): void
    {
        $this->addLinkedProduct(true);

        $html = $this->renderHome('tours');

        $this->assertStringContainsString('ws-btn-outline', $html, 'زرار احجز الآن لازم يظهر لما يكون فيه منتج مرتبط');
        $this->assertStringContainsString('احجز الآن', $html);
        $this->assertStringContainsString('href="#tours"', $html, 'الزرار لازم يوجّه لأقرب قسم جولات');
    }

    public function testBookNowButtonHiddenWithoutLinkedProduct(): void
    {
        $html = $this->renderHome('tours');

        $this->assertStringNotContainsString('ws-btn-outline', $html, 'من غير منتجات مرتبطة مفيش زرار احجز الآن');
    }

    public function testSubmitLeadStoresWebsiteLead(): void
    {
        $this->setControllerData([
            'visitor_name' => 'عميل تجريبي',
            'phone' => '+201000000002',
            'email' => 'visitor@example.com',
            'message' => 'حجز رحلة النيل يوم 15',
        ]);

        $result = self::$controller->submitLead(['slug' => self::TEST_SLUG]);

        $this->assertTrue($result['success']);
        $this->assertSame(201, $result['code']);

        $row = self::$pdo->query(
            "SELECT * FROM website_leads WHERE website_id = 999004 ORDER BY id DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($row, 'الـ lead لازم يتخزّن في website_leads');
        $this->assertSame('عميل تجريبي', $row['visitor_name']);
        $this->assertSame('+201000000002', $row['phone']);
        $this->assertSame('visitor@example.com', $row['email']);
        $this->assertSame('حجز رحلة النيل يوم 15', $row['message']);
        $this->assertSame('new', $row['status']);
    }

    public function testSubmitLeadRejectsMissingName(): void
    {
        $this->setControllerData([
            'phone' => '+201000000003',
            'message' => 'بدون اسم',
        ]);

        $result = self::$controller->submitLead(['slug' => self::TEST_SLUG]);

        $this->assertFalse($result['success']);
        $this->assertSame(422, $result['code']);
        $count = (int) self::$pdo->query("SELECT COUNT(*) FROM website_leads WHERE website_id = 999004")->fetchColumn();
        $this->assertSame(0, $count, 'validation فشل - مفيش صف يتخزّن');
    }
}
