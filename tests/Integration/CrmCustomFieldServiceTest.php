<?php

/**
 * CrmCustomFieldService: تعريف الحقول، كتابة/قراءة القيم، التحقق حسب النوع، وعزل الحسابات.
 * بيتخطى تلقائيًا لو DB أو جداول الحقول المخصصة مش متاحة.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Core/Model.php';
require_once __DIR__ . '/../../app/Models/CrmCustomField.php';
require_once __DIR__ . '/../../app/Models/CrmCustomFieldValue.php';
require_once __DIR__ . '/../../app/Services/Crm/CrmCustomFieldService.php';

final class CrmCustomFieldServiceTest extends TestCase
{
    private const OWNER = 998400;
    private const OTHER = 998401;

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
            foreach (['crm_custom_fields', 'crm_custom_field_values'] as $table) {
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
            $this->markTestSkipped('DB أو جداول الحقول المخصصة غير متاحة');
        }
        $this->cleanup();
        foreach ([self::OWNER, self::OTHER] as $id) {
            $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                        VALUES ({$id}, 'crm-cf-{$id}@tourfecto.test', 'x', 'CRM custom field test', NOW())
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
        self::$pdo->exec("DELETE FROM crm_custom_field_values WHERE user_id IN ({$ids})");
        self::$pdo->exec("DELETE FROM crm_custom_fields WHERE user_id IN ({$ids})");
        self::$pdo->exec("DELETE FROM users WHERE id IN ({$ids})");
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function define(string $key, string $type = 'text', string $entity = 'contact', array $extra = []): CrmCustomField
    {
        return (new CrmCustomFieldService())->createDefinition(self::OWNER, array_merge([
            'entity_type' => $entity, 'field_key' => $key, 'label' => 'L-' . $key, 'field_type' => $type,
        ], $extra));
    }

    public function testCreateDefinitionNormalizesKeyAndRejectsDuplicates(): void
    {
        $f = $this->define('Decision Maker!');
        $this->assertSame('decision-maker', $f->getAttribute('field_key'));

        try {
            $this->define('decision-maker');
            $this->fail('كان لازم يرفض مفتاح مكرر لنفس الكيان');
        } catch (Exception $e) {
            $this->assertSame(422, $e->getCode());
        }
        // نفس المفتاح على كيان تاني مسموح
        $this->assertNotNull($this->define('decision-maker', 'text', 'deal'));
    }

    public function testCreateDefinitionValidation(): void
    {
        $svc = new CrmCustomFieldService();
        $bad = [
            ['entity_type' => 'invoice', 'field_key' => 'k', 'label' => 'L'],
            ['entity_type' => 'contact', 'field_key' => '###', 'label' => 'L'],
            ['entity_type' => 'contact', 'field_key' => 'k', 'label' => ' '],
            ['entity_type' => 'contact', 'field_key' => 'k', 'label' => 'L', 'field_type' => 'file'],
        ];
        foreach ($bad as $data) {
            try {
                $svc->createDefinition(self::OWNER, $data);
                $this->fail('كان لازم يرفض: ' . json_encode($data));
            } catch (Exception $e) {
                $this->assertSame(422, $e->getCode());
            }
        }
    }

    public function testSelectOptionsAcceptNewlineStringAndAreCleaned(): void
    {
        $f = $this->define('tier', 'select', 'contact', ['options' => "Gold\n\n Silver \r\nBronze"]);
        $this->assertSame(['Gold', 'Silver', 'Bronze'], $f->optionsList());
    }

    public function testSetAndGetValuesWithUpsertAndClearing(): void
    {
        $svc = new CrmCustomFieldService();
        $this->define('segment');
        $this->define('budget', 'number');

        $saved = $svc->setValues(self::OWNER, 'contact', 7, ['segment' => 'vip', 'budget' => '2500.5', 'unknown' => 'x']);
        $this->assertSame(['segment' => 'vip', 'budget' => '2500.5'], $saved);

        $svc->setValues(self::OWNER, 'contact', 7, ['segment' => 'regular']);
        $byKey = array_column($svc->getValues(self::OWNER, 'contact', 7), 'value', 'field_key');
        $this->assertSame('regular', $byKey['segment']);
        $this->assertSame('2500.5', $byKey['budget']);

        // قيمة فاضية بتمسح القيمة المحفوظة
        $svc->setValues(self::OWNER, 'contact', 7, ['segment' => '']);
        $byKey = array_column($svc->getValues(self::OWNER, 'contact', 7), 'value', 'field_key');
        $this->assertNull($byKey['segment']);
    }

    public function testValueValidationByType(): void
    {
        $svc = new CrmCustomFieldService();
        $this->define('budget', 'number');
        $this->define('tier', 'select', 'contact', ['options' => ['Gold', 'Silver']]);

        foreach ([['budget' => 'abc'], ['tier' => 'Platinum']] as $values) {
            try {
                $svc->setValues(self::OWNER, 'contact', 1, $values);
                $this->fail('كان لازم يرفض: ' . json_encode($values));
            } catch (Exception $e) {
                $this->assertSame(422, $e->getCode());
            }
        }
        $this->assertSame(['tier' => 'Gold'], $svc->setValues(self::OWNER, 'contact', 1, ['tier' => 'Gold']));
    }

    public function testGetValuesExposesSelectOptionsForTheUi(): void
    {
        $svc = new CrmCustomFieldService();
        $this->define('tier', 'select', 'contact', ['options' => ['Gold', 'Silver']]);
        $this->define('note');

        $rows = array_column($svc->getValues(self::OWNER, 'contact', 1), null, 'field_key');
        $this->assertSame(['Gold', 'Silver'], $rows['tier']['options']);
        $this->assertSame([], $rows['note']['options']);
    }

    public function testUpdateDefinitionChangesLabelAndOptionsOnly(): void
    {
        $svc = new CrmCustomFieldService();
        $f = $this->define('tier', 'select', 'contact', ['options' => ['A']]);

        $u = $svc->updateDefinition(self::OWNER, (int) $f->getAttribute('id'), [
            'label' => 'Tier', 'options' => "A\nB", 'field_key' => 'hacked', 'field_type' => 'text',
        ]);

        $this->assertSame('Tier', $u->getAttribute('label'));
        $this->assertSame(['A', 'B'], $u->optionsList());
        $this->assertSame('tier', $u->getAttribute('field_key'));
        $this->assertSame('select', $u->getAttribute('field_type'));
    }

    public function testOtherAccountIsIsolated(): void
    {
        $svc = new CrmCustomFieldService();
        $f = $this->define('segment');
        $svc->setValues(self::OWNER, 'contact', 5, ['segment' => 'vip']);

        $this->assertSame([], $svc->definitions(self::OTHER));
        // الحساب التاني مفيش عنده تعريف فالقيمة بتتجاهل
        $this->assertSame([], $svc->setValues(self::OTHER, 'contact', 5, ['segment' => 'stolen']));

        foreach ([
            fn() => $svc->updateDefinition(self::OTHER, (int) $f->getAttribute('id'), ['label' => 'x']),
            fn() => $svc->deleteDefinition(self::OTHER, (int) $f->getAttribute('id')),
        ] as $attack) {
            try {
                $attack();
                $this->fail('حساب تاني ما كانش المفروض يقدر يعدّل التعريف');
            } catch (Exception $e) {
                $this->assertSame(404, $e->getCode());
            }
        }
        $byKey = array_column($svc->getValues(self::OWNER, 'contact', 5), 'value', 'field_key');
        $this->assertSame('vip', $byKey['segment']);
    }

    public function testDeleteDefinitionRemovesItsValues(): void
    {
        $svc = new CrmCustomFieldService();
        $f = $this->define('segment');
        $svc->setValues(self::OWNER, 'contact', 9, ['segment' => 'vip']);

        $this->assertTrue($svc->deleteDefinition(self::OWNER, (int) $f->getAttribute('id')));

        $this->assertSame([], $svc->definitions(self::OWNER));
        $left = (int) self::$pdo->query('SELECT COUNT(*) FROM crm_custom_field_values WHERE user_id = ' . self::OWNER)->fetchColumn();
        $this->assertSame(0, $left);
    }
}
