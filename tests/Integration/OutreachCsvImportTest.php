<?php

/**
 * Apify TripAdvisor CSV: multi-line descriptions, nested "a/b" columns, Arabic category names, and re-importing
 * companies that were first imported by an older parser (reviews, TripAdvisor url and facts were lost).
 */

use PHPUnit\Framework\TestCase;

final class OutreachCsvImportTest extends TestCase
{
    private ?Database $db = null;
    private PlatformOutreachService $svc;

    protected function setUp(): void
    {
        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) foreach (['app.php', 'database.php', 'encryption.php'] as $cfg) if (file_exists($app . '/Config/' . $cfg)) require_once $app . '/Config/' . $cfg;
            if (!class_exists('Database')) require_once $app . '/Core/Database.php';
            $this->db = Database::getInstance();
            $this->svc = new PlatformOutreachService();
        } catch (Throwable $e) {
            $this->markTestSkipped('DB غير متاحة: ' . $e->getMessage());
        }
        $this->clean();
    }

    protected function tearDown(): void
    {
        if ($this->db) $this->clean();
    }

    private function clean(): void
    {
        $this->db->query("DELETE FROM platform_leads WHERE domain LIKE '%.csvtest.example'");
    }

    private function csv(): string
    {
        $head = '"name","category","rating","numberOfReviews","photoCount","rankingString","webUrl","website","phone","description","travelerChoiceAward","ratingHistogram/count1","ratingHistogram/count2","ratingHistogram/count3","ratingHistogram/count4","ratingHistogram/count5","addressObj/city","addressObj/country","subcategories/0","subcategories/1","subcategories/2"';
        $rows = [
            '"Nile Test Tours","attraction","4.9","454","0","رقم 267 من 2,709 رحلات وأنشطة في القاهرة","https://ar.tripadvisor.com/Attraction_Review-g294201-d1-Reviews-Nile_Test_Tours.html","https://niletest.csvtest.example","+20 100 000 0001","first line' . "\n" . 'second line","true","10","12","5","30","400","القاهرة","مصر","رحلات خاصة","الرحلات النهارية",""',
            '"Cairo Museum Test","attraction","4.5","9000","300","رقم 1","https://ar.tripadvisor.com/Attraction_Review-g294201-d2-Reviews-Museum.html","https://museum.csvtest.example","","","false","1","1","1","1","1","القاهرة","مصر","متاحف","معالم تاريخية",""',
            '"Desert Test Safari","attraction","5","80","40","رقم 3","https://ar.tripadvisor.com/Attraction_Review-g294201-d3-Reviews-Safari.html","https://safari.csvtest.example","+20 100 000 0003","line a' . "\n\n" . 'line b, with comma","false","0","0","1","4","75","الجيزة","مصر","رحلات سفاري","","",',
        ];
        return "\xEF\xBB\xBF" . $head . "\n" . implode("\n", $rows) . "\n";
    }

    public function testMultiLineFieldsAndNestedColumnsAreParsedAsTheJsonWouldBe(): void
    {
        $rows = PlatformOutreachService::parseCsvRows($this->csv());
        $this->assertCount(3, $rows, 'a description over several lines is one record');
        $this->assertSame("first line\nsecond line", $rows[0]['description']);
        $this->assertSame("line a\n\nline b, with comma", $rows[2]['description']);
        $this->assertSame('10', $rows[0]['ratingHistogram']['count1']);
        $this->assertSame(['رحلات خاصة', 'الرحلات النهارية'], $rows[0]['subcategories'], 'empty trailing cells are not subcategories');
        $this->assertSame('القاهرة', $rows[0]['addressObj']['city']);
        $this->assertSame('454', $rows[0]['numberOfReviews'], 'original header case is kept');
        $this->assertSame('454', $rows[0]['numberofreviews'], 'and a lower-case copy for older files');
        $this->assertSame('Nile Test Tours', $rows[0]['name'], 'the BOM does not leak into the first header');
    }

    public function testRowsMapToEverythingTheTripadvisorFileCarries(): void
    {
        $m = PlatformOutreachService::mapRow(PlatformOutreachService::parseCsvRows($this->csv())[0]);
        $this->assertSame(454, $m['reviews']);
        $this->assertSame(4.9, $m['rating']);
        $this->assertStringContainsString('tripadvisor.com/Attraction_Review', (string) $m['maps_url']);
        $this->assertSame('القاهرة', $m['city']);
        $this->assertSame('EG', $m['country']);
        $this->assertSame(22, $m['extra']['ta']['negative'], 'one and two star counts');
        $this->assertSame(0, $m['extra']['ta']['photos']);
        $this->assertTrue($m['extra']['ta']['award']);
        $this->assertSame(['رحلات خاصة', 'الرحلات النهارية'], $m['extra']['ta']['subcategories']);
    }

    public function testArabicAndTurkishCategoriesAreSellersAndMuseumsAreNot(): void
    {
        $this->assertTrue(OutreachNeeds::isSeller(['subcategories' => ['رحلات خاصة', 'الرحلات النهارية']], null));
        $this->assertTrue(OutreachNeeds::isSeller(['subcategories' => ["\u{202A}جولات في المدينة\u{202C}"]], null), 'direction marks around the name');
        $this->assertTrue(OutreachNeeds::isSeller(['subcategories' => ['سيارات أجرة وحافلات نقل']], null));
        $this->assertTrue(OutreachNeeds::isSeller(['subcategories' => ['Tekne Turları']], null));
        $this->assertTrue(OutreachNeeds::isSeller(['subcategories' => ['Tours']], null), 'the English names still work');
        $this->assertFalse(OutreachNeeds::isSeller(['subcategories' => ['متاحف', 'معالم تاريخية']], null));
        $this->assertFalse(OutreachNeeds::isSeller(['subcategories' => ['Museums', 'Sights & Landmarks']], null));
    }

    public function testImportAddsCompaniesWithAllTheirFieldsAndSkipsMuseums(): void
    {
        $r = $this->svc->importText($this->csv());
        $this->assertSame(2, $r['added']);
        $this->assertSame(1, $r['skipped'], 'the museum');
        $lead = $this->db->query("SELECT * FROM platform_leads WHERE domain = 'niletest.csvtest.example'")[0];
        $this->assertSame(454, (int) $lead['reviews']);
        $this->assertStringContainsString('tripadvisor.com', (string) $lead['maps_url']);
        $this->assertSame('القاهرة', $lead['city']);
        $ta = json_decode((string) $lead['data_json'], true)['ta'];
        $this->assertSame(22, $ta['negative']);
        $this->assertStringContainsString('267', $ta['ranking']);
        $this->assertSame(0, $this->db->query("SELECT COUNT(*) AS n FROM platform_leads WHERE domain = 'museum.csvtest.example'")[0]['n'] + 0);
    }

    public function testReimportFillsWhatAnOlderImportLostAndQueuesAScanOnlyForUnemailedCompanies(): void
    {
        // imported by the old parser: rating present, reviews / url / city / TripAdvisor facts missing
        foreach ([['niletest', 0], ['safari', 1]] as [$d, $step]) {
            $this->db->query("INSERT INTO platform_leads (source, company, domain, website, country, rating, status, step, token, data_json, scanned_at)
                VALUES ('import', ?, ?, ?, 'EG', 4.9, ?, ?, ?, '{\"site\":{\"ok\":true}}', NOW())",
                [$d . ' old', $d . '.csvtest.example', 'https://' . $d . '.csvtest.example', $step === 0 ? 'new' : 'contacted', $step, bin2hex(random_bytes(16))]);
        }
        $r = $this->svc->importText($this->csv());
        $this->assertSame(2, $r['updated']);
        $this->assertSame(0, $r['added']);

        $new = $this->db->query("SELECT * FROM platform_leads WHERE domain = 'niletest.csvtest.example'")[0];
        $this->assertSame(454, (int) $new['reviews']);
        $this->assertNotEmpty($new['maps_url']);
        $this->assertSame('القاهرة', $new['city']);
        $this->assertSame(22, json_decode((string) $new['data_json'], true)['ta']['negative']);
        $this->assertNull($new['scanned_at'], 'not emailed yet: scanned again with the new data');

        $emailed = $this->db->query("SELECT * FROM platform_leads WHERE domain = 'safari.csvtest.example'")[0];
        $this->assertSame(80, (int) $emailed['reviews'], 'the data is completed');
        $this->assertSame('contacted', $emailed['status'], 'and the status is never touched');
        $this->assertNotNull($emailed['scanned_at'], 'an emailed company is not re-scanned');
    }
}
