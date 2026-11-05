<?php

/**
 * CrmMessageTemplateService: إنشاء/تعديل/حذف القوالب، استبدال المتغيرات المسموحة فقط، وعزل الحسابات.
 * بيتخطى تلقائيًا لو DB أو جدول القوالب مش متاح.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Core/Model.php';
require_once __DIR__ . '/../../app/Models/CrmMessageTemplate.php';
require_once __DIR__ . '/../../app/Models/ActivityLog.php';
require_once __DIR__ . '/../../app/Services/Crm/CrmMessageTemplateService.php';

final class CrmMessageTemplateServiceTest extends TestCase
{
    private const OWNER = 998300;
    private const OTHER = 998301;

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
            if (!$conn instanceof PDO || !$conn->query("SHOW TABLES LIKE 'crm_message_templates'")->fetchAll()) {
                return null;
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
            $this->markTestSkipped('DB أو جدول القوالب غير متاح');
        }
        $this->cleanup();
        foreach ([self::OWNER, self::OTHER] as $id) {
            $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                        VALUES ({$id}, 'crm-tpl-{$id}@tourfecto.test', 'x', 'CRM template test', NOW())
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
        $ids = self::OWNER . ',' . self::OTHER;
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        self::$pdo->exec("DELETE FROM crm_message_templates WHERE user_id IN ({$ids})");
        self::$pdo->exec("DELETE FROM users WHERE id IN ({$ids})");
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function make(string $channel = 'whatsapp', string $name = 'welcome', string $body = 'Hi {{name}}'): CrmMessageTemplate
    {
        return (new CrmMessageTemplateService())->create(self::OWNER, self::OWNER, ['channel' => $channel, 'name' => $name, 'body' => $body]);
    }

    public function testCreateAndListReturnsPlainRowsFilteredByChannel(): void
    {
        $svc = new CrmMessageTemplateService();
        $this->make('whatsapp', 'a');
        $this->make('email', 'b');

        $all = $svc->listForUser(self::OWNER);
        $this->assertCount(2, $all);
        // الـ API بيعتمد إن الصفوف arrays (مش Models)
        $this->assertIsArray($all[0]);
        $this->assertSame(['b'], array_column($svc->listForUser(self::OWNER, 'email'), 'name'));
    }

    public function testCreateValidation(): void
    {
        $svc = new CrmMessageTemplateService();
        $bad = [
            ['channel' => 'telegram', 'name' => 'x', 'body' => 'x'],
            ['channel' => 'sms', 'name' => '  ', 'body' => 'x'],
            ['channel' => 'sms', 'name' => 'x', 'body' => '   '],
        ];
        foreach ($bad as $data) {
            try {
                $svc->create(self::OWNER, self::OWNER, $data);
                $this->fail('كان لازم يرفض: ' . json_encode($data));
            } catch (Exception $e) {
                $this->assertSame(422, $e->getCode());
            }
        }
    }

    public function testRenderReplacesOnlyAllowedVariablesInSubjectAndBody(): void
    {
        $svc = new CrmMessageTemplateService();
        $t = $svc->create(self::OWNER, self::OWNER, [
            'channel' => 'email', 'name' => 'quote', 'subject' => 'Offer for {{name}}',
            'body' => 'Hi {{name}}, {{deal_title}} costs {{deal_value}} {{currency}}. {{secret}} {{phone}}',
        ]);

        $out = $svc->render(self::OWNER, (int) $t->getAttribute('id'), [
            'name' => 'Ahmed', 'deal_title' => 'Luxor', 'deal_value' => '1500', 'currency' => 'USD', 'secret' => 'LEAK', 'phone' => null,
        ]);

        $this->assertSame('Offer for Ahmed', $out['subject']);
        // متغير مش في القائمة ما بيتبدلش، ومتغير قيمته null بيفضل زي ما هو
        $this->assertSame('Hi Ahmed, Luxor costs 1500 USD. {{secret}} {{phone}}', $out['body']);
    }

    public function testUpdateChangesFieldsAndValidates(): void
    {
        $svc = new CrmMessageTemplateService();
        $id = (int) $this->make()->getAttribute('id');

        $svc->update(self::OWNER, $id, ['name' => 'renamed', 'channel' => 'sms', 'body' => 'new body']);
        $row = $svc->listForUser(self::OWNER)[0];
        $this->assertSame('renamed', $row['name']);
        $this->assertSame('sms', $row['channel']);
        $this->assertSame('new body', $row['body']);

        foreach ([['name' => ' '], ['body' => ' '], ['channel' => 'fax']] as $data) {
            try {
                $svc->update(self::OWNER, $id, $data);
                $this->fail('كان لازم يرفض: ' . json_encode($data));
            } catch (Exception $e) {
                $this->assertSame(422, $e->getCode());
            }
        }
    }

    public function testOtherAccountCannotReadUpdateRenderOrDelete(): void
    {
        $svc = new CrmMessageTemplateService();
        $id = (int) $this->make()->getAttribute('id');

        $this->assertSame([], $svc->listForUser(self::OTHER));
        foreach ([
            fn() => $svc->update(self::OTHER, $id, ['name' => 'hacked']),
            fn() => $svc->render(self::OTHER, $id, []),
            fn() => $svc->delete(self::OTHER, $id),
        ] as $attack) {
            try {
                $attack();
                $this->fail('حساب تاني ما كانش المفروض يوصل للقالب');
            } catch (Exception $e) {
                $this->assertSame(404, $e->getCode());
            }
        }
        $this->assertCount(1, $svc->listForUser(self::OWNER));
    }

    public function testDeleteRemovesTheTemplate(): void
    {
        $svc = new CrmMessageTemplateService();
        $id = (int) $this->make()->getAttribute('id');

        $this->assertTrue($svc->delete(self::OWNER, $id));
        $this->assertSame([], $svc->listForUser(self::OWNER));
    }
}
