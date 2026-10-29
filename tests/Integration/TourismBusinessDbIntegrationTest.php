<?php

/**
 * TOURFECTO AI CEO (المرحلة 3): قاعدة شركات السياحة فوق platform_leads
 *   البحث والتصفية · تاريخ التقارير (مابيتكتبش فوق القديم) · الاعتراض بيوقف التواصل · الحذف بيمسح ويمنع إعادة الاستيراد
 *   · المكرر والدمج والرجوع فيه · مفيش كتابة في جداول العملاء
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AiCeo/TourismBusinessDb.php';

final class TourismBusinessDbIntegrationTest extends TestCase
{
    private ?Database $db = null;
    private ?TourismBusinessDb $tbi = null;

    protected function setUp(): void
    {
        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) foreach (['app.php', 'database.php', 'encryption.php'] as $cfg) if (file_exists($app . '/Config/' . $cfg)) require_once $app . '/Config/' . $cfg;
            if (!class_exists('Database')) require_once $app . '/Core/Database.php';
            $this->db = Database::getInstance();
            $this->tbi = new TourismBusinessDb($this->db);
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
        $ids = array_map('intval', array_column($this->db->query("SELECT id FROM platform_leads WHERE source = 'phpunit-tbi'") ?: [], 'id'));
        if ($ids) {
            $in = implode(',', $ids);
            foreach (['tbi_reports', 'tbi_consent_events'] as $t) $this->db->query("DELETE FROM {$t} WHERE lead_id IN ({$in})");
            $this->db->query("DELETE FROM tbi_merges WHERE primary_id IN ({$in}) OR duplicate_id IN ({$in})");
        }
        $this->db->query("DELETE FROM platform_leads WHERE source = 'phpunit-tbi'");
        $this->db->query("DELETE FROM tbi_suppression WHERE value_hash IN (?, ?, ?)", [TourismBusinessDb::hash('email', 'erase@tbi.test'), TourismBusinessDb::hash('domain', 'erase-tbi.test'), TourismBusinessDb::hash('phone', '+20 100 555 0001')]);
    }

    private function lead(array $v): int
    {
        $v += ['company' => 'TBI Test Co', 'email' => null, 'phone' => null, 'website' => null, 'domain' => null, 'city' => 'Luxor', 'country' => 'EG', 'status' => 'new', 'score' => null, 'track' => null];
        $this->db->query("INSERT INTO platform_leads (source, company, email, phone, website, domain, city, country, status, score, track, token) VALUES ('phpunit-tbi', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$v['company'], $v['email'], $v['phone'], $v['website'], $v['domain'], $v['city'], $v['country'], $v['status'], $v['score'], $v['track'], bin2hex(random_bytes(16))]);
        return (int) ($this->db->query("SELECT MAX(id) AS id FROM platform_leads WHERE source = 'phpunit-tbi'")[0]['id'] ?? 0);
    }

    public function testSearchFiltersAndProfile(): void
    {
        $a = $this->lead(['company' => 'TBI Nile Tours', 'email' => 'a@tbi.test', 'country' => 'EG', 'score' => 80, 'track' => 'seo']);
        $this->lead(['company' => 'TBI Rome Trips', 'country' => 'IT', 'score' => 20]);
        $r = $this->tbi->search(['q' => 'TBI', 'country' => 'EG', 'has_email' => true]);
        $this->assertSame(1, $r['total']);
        $this->assertSame($a, (int) $r['items'][0]['id']);
        $this->assertSame(1, $this->tbi->search(['q' => 'TBI', 'min_score' => 50])['total']);
        $p = $this->tbi->profile($a);
        $this->assertSame('TBI Nile Tours', $p['company']['company']);
        $this->assertArrayNotHasKey('token', $p['company']);
        $this->assertNull($p['customer']);
    }

    public function testEveryNewAnalysisIsKeptAsHistory(): void
    {
        $id = $this->lead(['score' => 55, 'track' => 'reputation']);
        $this->db->query("UPDATE platform_leads SET scanned_at = NOW() - INTERVAL 2 DAY, data_json = ?, needs_json = ? WHERE id = ?", [json_encode(['audit' => ['score' => 55]]), json_encode([['track' => 'reputation', 'score' => 9]]), $id]);
        $this->assertSame(true, $this->tbi->captureReports() >= 1);
        $this->tbi->captureReports();
        $this->assertCount(1, $this->db->query('SELECT id FROM tbi_reports WHERE lead_id = ?', [$id]));
        // تحليل جديد: بيتضاف، والقديم بيفضل
        $this->db->query("UPDATE platform_leads SET scanned_at = NOW(), score = 70 WHERE id = ?", [$id]);
        $this->tbi->captureReports();
        $this->assertCount(2, $this->db->query('SELECT id FROM tbi_reports WHERE lead_id = ?', [$id]));
    }

    public function testObjectionStopsContactAndIsLogged(): void
    {
        $id = $this->lead(['email' => 'obj@tbi.test']);
        $this->tbi->recordConsent($id, 'objection', 'email', 1, 'replied: please stop');
        $this->assertSame('unsubscribed', $this->db->query('SELECT status FROM platform_leads WHERE id = ?', [$id])[0]['status']);
        $this->assertSame('objection', $this->tbi->profile($id)['consent'][0]['event']);
        // إلغاء من لينك الإيميل (الحالة بس) بيتسجل في السجل مرة واحدة
        $other = $this->lead(['email' => 'unsub@tbi.test', 'status' => 'unsubscribed']);
        $this->tbi->backfillUnsubscribes();
        $this->tbi->backfillUnsubscribes();
        $this->assertCount(1, $this->db->query("SELECT id FROM tbi_consent_events WHERE lead_id = ? AND event = 'unsubscribe'", [$other]));
    }

    public function testErasureRemovesDataAndBlocksReimport(): void
    {
        $id = $this->lead(['email' => 'erase@tbi.test', 'domain' => 'erase-tbi.test', 'phone' => '+20 100 555 0001', 'website' => 'https://erase-tbi.test']);
        $this->tbi->erase($id, 1, 'request #42');
        $row = $this->db->query('SELECT company, email, phone, domain, website, status FROM platform_leads WHERE id = ?', [$id])[0];
        $this->assertNull($row['email']);
        $this->assertNull($row['phone']);
        $this->assertNull($row['domain']);
        $this->assertSame('invalid', $row['status']);
        $this->assertSame(true, $this->tbi->isSuppressed('ERASE@tbi.test', null, null));
        $this->assertSame(true, $this->tbi->isSuppressed(null, 'www.erase-tbi.test', null));
        $this->assertSame(true, $this->tbi->isSuppressed(null, null, '00201005550001') || $this->tbi->isSuppressed(null, null, '+201005550001'));
        $this->assertSame(false, $this->tbi->isSuppressed('someone-else@tbi.test', null, null));
    }

    public function testDuplicatesMergeAndUndo(): void
    {
        $users = (int) $this->db->query('SELECT COUNT(*) AS c FROM users')[0]['c'];
        $p = $this->lead(['company' => 'TBI Dup', 'email' => 'p@tbi.test', 'domain' => 'dup-tbi.test']);
        $d = $this->lead(['company' => 'TBI Dup', 'domain' => 'dup-tbi.test', 'phone' => '+20 100 555 0099']);
        $group = array_values(array_filter($this->tbi->duplicates(200), static fn ($g) => $g['reason'] === 'same_domain' && $g['key'] === 'dup-tbi.test'));
        $this->assertSame([$p, $d], $group[0]['ids']);

        $mid = $this->tbi->merge($p, $d, 1, 'same_domain');
        $this->assertSame('+20 100 555 0099', $this->db->query('SELECT phone FROM platform_leads WHERE id = ?', [$p])[0]['phone']);
        $this->assertSame('paused', $this->db->query('SELECT status FROM platform_leads WHERE id = ?', [$d])[0]['status']);
        $this->assertSame([], array_values(array_filter($this->tbi->duplicates(200), static fn ($g) => $g['key'] === 'dup-tbi.test')));

        $this->tbi->undoMerge($mid);
        $this->assertNull($this->db->query('SELECT phone FROM platform_leads WHERE id = ?', [$p])[0]['phone']);
        $this->assertSame('new', $this->db->query('SELECT status FROM platform_leads WHERE id = ?', [$d])[0]['status']);
        $this->assertSame($users, (int) $this->db->query('SELECT COUNT(*) AS c FROM users')[0]['c']);
    }
}
