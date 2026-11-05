<?php

/**
 * CrmLeadRoutingService: أول قاعدة مطابقة تفوز، التوجيه الثابت والتناوب، والعزل بين الحسابات.
 * بيتخطى تلقائيًا لو DB أو جداول CRM مش متاحة.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Core/Model.php';
require_once __DIR__ . '/../../app/Models/CrmLeadRoutingRule.php';
require_once __DIR__ . '/../../app/Models/CrmLead.php';
require_once __DIR__ . '/../../app/Models/CrmContact.php';
require_once __DIR__ . '/../../app/Models/CrmTeamMember.php';
require_once __DIR__ . '/../../app/Models/ActivityLog.php';
require_once __DIR__ . '/../../app/Services/Crm/CrmLeadRoutingService.php';

final class CrmLeadRoutingServiceTest extends TestCase
{
    private const OWNER = 998200;
    private const REP_1 = 998201;
    private const REP_2 = 998202;
    private const OTHER_OWNER = 998203;

    private static ?PDO $pdo = null;
    private static bool $dbChecked = false;

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
            if (!class_exists('Database') && file_exists($app . '/Core/Database.php')) {
                require_once $app . '/Core/Database.php';
            }
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $conn = $ref->getValue(Database::getInstance());
            if (!$conn instanceof PDO) {
                return null;
            }
            foreach (['crm_lead_routing_rules', 'crm_leads', 'crm_contacts', 'crm_team_members'] as $table) {
                if (!$conn->query("SHOW TABLES LIKE '{$table}'")->fetchAll()) {
                    return null;
                }
            }
            self::$pdo = $conn;
        } catch (\Throwable $e) {
            self::$pdo = null;
        }
        return self::$pdo;
    }

    protected function setUp(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            $this->markTestSkipped('DB أو جداول CRM غير متاحة');
        }
        $this->cleanup();
        foreach ([self::OWNER, self::REP_1, self::REP_2, self::OTHER_OWNER] as $id) {
            $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                        VALUES ({$id}, 'crm-routing-{$id}@tourfecto.test', 'x', 'CRM routing test', NOW())
                        ON DUPLICATE KEY UPDATE email = email");
        }
    }

    protected function tearDown(): void
    {
        if (self::$pdo !== null) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $pdo = self::$pdo;
        $ids = implode(',', [self::OWNER, self::REP_1, self::REP_2, self::OTHER_OWNER]);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->exec("DELETE FROM crm_lead_routing_rules WHERE user_id IN ({$ids})");
        $pdo->exec("DELETE FROM crm_team_members WHERE tenant_user_id IN ({$ids})");
        $pdo->exec("DELETE FROM crm_leads WHERE contact_id IN (SELECT id FROM crm_contacts WHERE user_id IN ({$ids}))");
        $pdo->exec("DELETE FROM crm_contacts WHERE user_id IN ({$ids})");
        $pdo->exec("DELETE FROM users WHERE id IN ({$ids})");
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function lead(string $source = 'website', ?float $value = null, string $country = 'EG'): int
    {
        $pdo = self::$pdo;
        $pdo->prepare("INSERT INTO crm_contacts (user_id, name, country) VALUES (?, 'Test', ?)")
            ->execute([self::OWNER, $country]);
        $contactId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO crm_leads (contact_id, owner_user_id, source, value) VALUES (?, ?, ?, ?)')
            ->execute([$contactId, self::OWNER, $source, $value]);
        return (int) $pdo->lastInsertId();
    }

    private function leadOwner(int $leadId): int
    {
        return (int) self::$pdo->query("SELECT owner_user_id FROM crm_leads WHERE id = {$leadId}")->fetchColumn();
    }

    public function testFirstMatchingRuleByOrderWinsAndFiltersApply(): void
    {
        $svc = new CrmLeadRoutingService();
        $svc->createRule(self::OWNER, ['name' => 'big', 'match_min_value' => 5000, 'assignment_mode' => 'fixed', 'assignee_user_id' => self::REP_1, 'sort_order' => 1]);
        $svc->createRule(self::OWNER, ['name' => 'wa', 'match_source' => 'whatsapp', 'assignment_mode' => 'fixed', 'assignee_user_id' => self::REP_2, 'sort_order' => 2]);
        $svc->createRule(self::OWNER, ['name' => 'fallback', 'assignment_mode' => 'fixed', 'assignee_user_id' => self::OWNER, 'sort_order' => 3]);

        $this->assertSame('big', $svc->findMatchingRule(self::OWNER, ['source' => 'whatsapp', 'value' => 9000])['name']);
        $this->assertSame('wa', $svc->findMatchingRule(self::OWNER, ['source' => 'whatsapp', 'value' => 10])['name']);
        $this->assertSame('fallback', $svc->findMatchingRule(self::OWNER, ['source' => 'website', 'value' => 10])['name']);
    }

    public function testMaxValueAndCountryFilters(): void
    {
        $svc = new CrmLeadRoutingService();
        $svc->createRule(self::OWNER, ['name' => 'small-eg', 'match_country' => 'EG', 'match_max_value' => 100, 'assignment_mode' => 'fixed', 'assignee_user_id' => self::REP_1]);

        $this->assertNotNull($svc->findMatchingRule(self::OWNER, ['country' => 'EG', 'value' => 50]));
        $this->assertNull($svc->findMatchingRule(self::OWNER, ['country' => 'EG', 'value' => 500]));
        $this->assertNull($svc->findMatchingRule(self::OWNER, ['country' => 'SA', 'value' => 50]));
    }

    public function testInactiveRulesAndOtherAccountsRulesAreIgnored(): void
    {
        $svc = new CrmLeadRoutingService();
        $svc->createRule(self::OWNER, ['name' => 'off', 'is_active' => 0, 'assignment_mode' => 'fixed', 'assignee_user_id' => self::REP_1]);
        $svc->createRule(self::OTHER_OWNER, ['name' => 'theirs', 'assignment_mode' => 'fixed', 'assignee_user_id' => self::OTHER_OWNER]);

        $this->assertNull($svc->findMatchingRule(self::OWNER, ['source' => 'website']));
    }

    public function testRouteLeadFixedAssignsTheLead(): void
    {
        $svc = new CrmLeadRoutingService();
        $svc->createRule(self::OWNER, ['name' => 'fixed', 'assignment_mode' => 'fixed', 'assignee_user_id' => self::REP_1]);
        $leadId = $this->lead();

        $r = $svc->routeLead(self::OWNER, $leadId);

        $this->assertTrue($r['assigned']);
        $this->assertSame(self::REP_1, $r['assignee_user_id']);
        $this->assertSame(self::REP_1, $this->leadOwner($leadId));
    }

    public function testRouteLeadRoundRobinRotatesBetweenOwnerAndTeam(): void
    {
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        self::$pdo->exec('INSERT INTO crm_team_members (tenant_user_id, member_user_id, role) VALUES (' . self::OWNER . ',' . self::REP_1 . ", 'sales')");
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $svc = new CrmLeadRoutingService();
        $svc->createRule(self::OWNER, ['name' => 'rr', 'assignment_mode' => 'round_robin']);

        $assigned = [];
        for ($i = 0; $i < 4; $i++) {
            $assigned[] = $svc->routeLead(self::OWNER, $this->lead())['assignee_user_id'];
        }

        $this->assertSame([self::OWNER, self::REP_1, self::OWNER, self::REP_1], $assigned);
    }

    public function testRouteLeadWithoutMatchingRuleChangesNothing(): void
    {
        $svc = new CrmLeadRoutingService();
        $leadId = $this->lead();

        $r = $svc->routeLead(self::OWNER, $leadId);

        $this->assertFalse($r['assigned']);
        $this->assertSame(self::OWNER, $this->leadOwner($leadId));
    }

    public function testRouteLeadRejectsLeadOfAnotherAccount(): void
    {
        $svc = new CrmLeadRoutingService();
        $leadId = $this->lead();

        try {
            $svc->routeLead(self::OTHER_OWNER, $leadId);
            $this->fail('حساب تاني ما كانش المفروض يقدر يوجّه الـLead');
        } catch (Exception $e) {
            $this->assertSame(404, $e->getCode());
        }
    }

    public function testCreateRuleValidation(): void
    {
        $svc = new CrmLeadRoutingService();
        $bad = [
            ['name' => '   '],
            ['name' => 'x', 'assignment_mode' => 'random'],
            ['name' => 'x', 'assignment_mode' => 'fixed'],
        ];
        foreach ($bad as $data) {
            try {
                $svc->createRule(self::OWNER, $data);
                $this->fail('كان لازم يرفض: ' . json_encode($data));
            } catch (Exception $e) {
                $this->assertSame(422, $e->getCode());
            }
        }
    }

    public function testUpdateAndDeleteAreScopedToTheOwner(): void
    {
        $svc = new CrmLeadRoutingService();
        $rule = $svc->createRule(self::OWNER, ['name' => 'mine', 'assignment_mode' => 'fixed', 'assignee_user_id' => self::REP_1]);
        $id = (int) $rule->getAttribute('id');

        foreach ([
            fn() => $svc->updateRule(self::OTHER_OWNER, $id, ['name' => 'hacked']),
            fn() => $svc->deleteRule(self::OTHER_OWNER, $id),
        ] as $attack) {
            try {
                $attack();
                $this->fail('حساب تاني ما كانش المفروض يقدر يعدّل القاعدة');
            } catch (Exception $e) {
                $this->assertSame(404, $e->getCode());
            }
        }

        $svc->updateRule(self::OWNER, $id, ['is_active' => 0]);
        $this->assertSame(0, (int) $svc->listRules(self::OWNER)[0]['is_active']);
        $this->assertTrue($svc->deleteRule(self::OWNER, $id));
        $this->assertSame([], $svc->listRules(self::OWNER));
    }
}
