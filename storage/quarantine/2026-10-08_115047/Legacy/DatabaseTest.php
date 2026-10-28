<?php

/**
 * Tourfecto - Database Test
 * اختبارات اتصال واستعلامات قاعدة البيانات
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class DatabaseTest
{
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;

    /**
     * @var array $testResults - نتائج الاختبارات
     */
    private $testResults = [];

    /**
     * @var int $passed - عدد الاختبارات الناجحة
     */
    private $passed = 0;

    /**
     * @var int $failed - عدد الاختبارات الفاشلة
     */
    private $failed = 0;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->testResults = [];
    }

    /**
     * تشغيل جميع الاختبارات
     */
    public function runAll(): void
    {
        echo "\n🧪 Database Tests\n";
        echo "=================\n\n";

        $this->testConnection();
        $this->testQuery();
        $this->testTransaction();
        $this->testPreparedStatement();
        $this->testBatchInsert();
        $this->testPagination();
        $this->testSearch();
        $this->testBackup();

        $this->printSummary();
    }

    /**
     * اختبار الاتصال بقاعدة البيانات
     */
    private function testConnection(): void
    {
        $this->startTest('Database Connection');

        try {
            $isConnected = $this->db->isConnected();

            if ($isConnected) {
                $this->pass('Database connection successful');
            } else {
                $this->fail('Database connection failed');
            }
        } catch (Exception $e) {
            $this->fail('Database connection error: ' . $e->getMessage());
        }
    }

    /**
     * اختبار الاستعلامات
     */
    private function testQuery(): void
    {
        $this->startTest('SQL Queries');

        try {
            // اختبار SELECT
            $sql = "SELECT * FROM users WHERE id = 1";
            $result = $this->db->query($sql);

            if (is_array($result)) {
                $this->pass('SELECT query successful');
            } else {
                $this->fail('SELECT query failed');
            }

            // اختبار INSERT
            $testData = [
                'company_name' => 'Test_Company_' . uniqid(),
                'email' => 'test_' . uniqid() . '@example.com',
                'password' => password_hash('Test@123', PASSWORD_ARGON2ID),
                'phone' => '+966500000001',
                'is_active' => 1
            ];

            $sql = "INSERT INTO users (company_name, email, password, phone, is_active) 
                    VALUES (:company_name, :email, :password, :phone, :is_active)";

            $id = $this->db->query($sql, $testData);

            if ($id > 0) {
                $this->pass('INSERT query successful, ID: ' . $id);

                // حذف البيانات المؤقتة
                $sql = "DELETE FROM users WHERE id = :id";
                $this->db->query($sql, [':id' => $id]);
            } else {
                $this->fail('INSERT query failed');
            }

            // اختبار UPDATE
            $sql = "UPDATE users SET phone = '+966500000002' WHERE id = 1";
            $affected = $this->db->query($sql);

            if ($affected !== false) {
                $this->pass('UPDATE query successful, affected: ' . $affected);
            } else {
                $this->fail('UPDATE query failed');
            }

        } catch (Exception $e) {
            $this->fail('Query test error: ' . $e->getMessage());
        }
    }

    /**
     * اختبار المعاملات
     */
    private function testTransaction(): void
    {
        $this->startTest('Transactions');

        try {
            $this->db->beginTransaction();

            // إدراج بيانات مؤقتة
            $sql = "INSERT INTO users (company_name, email, password, phone, is_active) 
                    VALUES (:company_name, :email, :password, :phone, :is_active)";

            $id1 = $this->db->query($sql, [
                ':company_name' => 'Test_Trans_1',
                ':email' => 'trans1@example.com',
                ':password' => password_hash('Test@123', PASSWORD_ARGON2ID),
                ':phone' => '+966500000001',
                ':is_active' => 1
            ]);

            $id2 = $this->db->query($sql, [
                ':company_name' => 'Test_Trans_2',
                ':email' => 'trans2@example.com',
                ':password' => password_hash('Test@123', PASSWORD_ARGON2ID),
                ':phone' => '+966500000002',
                ':is_active' => 1
            ]);

            if ($id1 > 0 && $id2 > 0) {
                // تراجع عن المعاملة
                $this->db->rollback();

                // التحقق من عدم وجود البيانات
                $sql = "SELECT * FROM users WHERE id IN (:id1, :id2)";
                $result = $this->db->query($sql, [':id1' => $id1, ':id2' => $id2]);

                if (empty($result)) {
                    $this->pass('Transaction rollback successful');
                } else {
                    $this->fail('Transaction rollback failed');
                }
            } else {
                $this->db->rollback();
                $this->fail('Transaction insert failed');
            }

        } catch (Exception $e) {
            $this->db->rollback();
            $this->fail('Transaction test error: ' . $e->getMessage());
        }
    }

    /**
     * اختبار الاستعلامات المحضرة
     */
    private function testPreparedStatement(): void
    {
        $this->startTest('Prepared Statements');

        try {
            // اختبار معاملات متعددة
            $sql = "SELECT * FROM users WHERE id IN (:id1, :id2, :id3)";
            $params = [
                ':id1' => 1,
                ':id2' => 2,
                ':id3' => 3
            ];

            $result = $this->db->query($sql, $params);

            if (is_array($result) && count($result) <= 3) {
                $this->pass('Prepared statement with multiple params successful');
            } else {
                $this->fail('Prepared statement with multiple params failed');
            }

            // اختبار مع Null
            $sql = "SELECT * FROM users WHERE deleted_at IS NULL AND id > :id";
            $params = [':id' => 0];

            $result = $this->db->query($sql, $params);

            if (is_array($result)) {
                $this->pass('Prepared statement with NULL condition successful');
            } else {
                $this->fail('Prepared statement with NULL condition failed');
            }

        } catch (Exception $e) {
            $this->fail('Prepared statement test error: ' . $e->getMessage());
        }
    }

    /**
     * اختبار الإدراج المجمع
     */
    private function testBatchInsert(): void
    {
        $this->startTest('Batch Insert');

        try {
            $data = [];
            for ($i = 0; $i < 5; $i++) {
                $data[] = [
                    'company_name' => 'Batch_Test_' . $i,
                    'email' => 'batch_' . $i . '_' . uniqid() . '@example.com',
                    'password' => password_hash('Test@123', PASSWORD_ARGON2ID),
                    'phone' => '+96650000000' . $i,
                    'is_active' => 1
                ];
            }

            $ids = [];
            foreach ($data as $row) {
                $sql = "INSERT INTO users (company_name, email, password, phone, is_active) 
                        VALUES (:company_name, :email, :password, :phone, :is_active)";

                $id = $this->db->query($sql, $row);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }

            if (count($ids) === 5) {
                $this->pass('Batch insert successful: ' . count($ids) . ' records');

                // تنظيف البيانات
                $idsList = implode(',', $ids);
                $sql = "DELETE FROM users WHERE id IN ({$idsList})";
                $this->db->query($sql);
            } else {
                $this->fail('Batch insert failed: only ' . count($ids) . ' records inserted');
            }

        } catch (Exception $e) {
            $this->fail('Batch insert error: ' . $e->getMessage());
        }
    }

    /**
     * اختبار التصفح (Pagination)
     */
    private function testPagination(): void
    {
        $this->startTest('Pagination');

        try {
            $page = 1;
            $limit = 10;
            $offset = ($page - 1) * $limit;

            $sql = "SELECT * FROM users LIMIT :limit OFFSET :offset";
            $result = $this->db->query($sql, [
                ':limit' => $limit,
                ':offset' => $offset
            ]);

            if (is_array($result) && count($result) <= $limit) {
                $this->pass('Pagination query successful, returned: ' . count($result) . ' records');
            } else {
                $this->fail('Pagination query failed');
            }

        } catch (Exception $e) {
            $this->fail('Pagination test error: ' . $e->getMessage());
        }
    }

    /**
     * اختبار البحث
     */
    private function testSearch(): void
    {
        $this->startTest('Search');

        try {
            $searchTerm = 'admin';

            $sql = "SELECT * FROM users 
                    WHERE company_name LIKE :search 
                    OR email LIKE :search 
                    LIMIT 10";

            $result = $this->db->query($sql, [':search' => '%' . $searchTerm . '%']);

            if (is_array($result)) {
                $this->pass('Search query successful, found: ' . count($result) . ' records');
            } else {
                $this->fail('Search query failed');
            }

        } catch (Exception $e) {
            $this->fail('Search test error: ' . $e->getMessage());
        }
    }

    /**
     * اختبار النسخ الاحتياطي
     */
    private function testBackup(): void
    {
        $this->startTest('Database Backup');

        try {
            $backupFile = DB_BACKUP_PATH . '/test_backup_' . date('Y-m-d') . '.sql';
            $result = $this->db->backup('test_backup_' . date('Y-m-d') . '.sql');

            if ($result && file_exists($backupFile)) {
                $this->pass('Database backup created: ' . basename($backupFile));
                unlink($backupFile);
            } else {
                $this->fail('Database backup failed');
            }

        } catch (Exception $e) {
            $this->fail('Backup test error: ' . $e->getMessage());
        }
    }

    /**
     * بدء اختبار
     * @param string $name
     */
    private function startTest(string $name): void
    {
        echo "\n  ▶ {$name}\n";
    }

    /**
     * تسجيل نجاح
     * @param string $message
     */
    private function pass(string $message): void
    {
        echo "    ✅ {$message}\n";
        $this->passed++;
        $this->testResults[] = ['status' => 'PASS', 'message' => $message];
    }

    /**
     * تسجيل فشل
     * @param string $message
     */
    private function fail(string $message): void
    {
        echo "    ❌ {$message}\n";
        $this->failed++;
        $this->testResults[] = ['status' => 'FAIL', 'message' => $message];
    }

    /**
     * طباعة الملخص
     */
    private function printSummary(): void
    {
        $total = $this->passed + $this->failed;
        $percentage = $total > 0 ? round(($this->passed / $total) * 100, 2) : 0;

        echo "\n" . str_repeat('=', 50) . "\n";
        echo "📊 Database Test Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  ✅ Passed: {$this->passed}\n";
        echo "  ❌ Failed: {$this->failed}\n";
        echo "  📝 Total: {$total}\n";
        echo "  📈 Success Rate: {$percentage}%\n";
        echo str_repeat('=', 50) . "\n\n";
    }
}

// ============================================
// 6. تشغيل الاختبارات
// ============================================
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $test = new DatabaseTest();
    $test->runAll();
}
