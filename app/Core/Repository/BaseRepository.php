<?php

/**
 * Tourfecto - Base Repository
 * الأساس المشترك لأي Repository (Repository Pattern)
 * @version 1.0.0
 *
 * ليه Repository منفصل عن Model الموجود أصلاً؟
 * -----------------------------------------------
 * كلاس Model الحالي (app/Core/Model.php) بسيط ومباشر (Active Record) وده
 * كويس لأغلب الحالات، لكن له قيود بتظهر في مشروع زي ده تحديدًا:
 *   1) قاعدة البيانات المنشورة فعليًا مختلفة أحيانًا عن الكود المفترض
 *      (users.status بدل is_active، مثلاً)، والـ Model الحالي مبيعملش أي
 *      ترجمة لأسماء الأعمدة.
 *   2) استعلامات معقدة (joins, aggregates, معايير بحث متعددة) صعب تتكتب
 *      بشكل نضيف جوه Active Record بسيط.
 *   3) اختبار Controller محتاج mock للـ Repository بسهولة (interface) —
 *      صعب تعمل mock لكلاس Model اللي بيفتح اتصال DB حقيقي جوه constructor.
 *
 * BaseRepository هنا بيدي مكان واحد نضيف فيه:
 *   - اكتشاف اسم العمود الحقيقي ديناميكيًا (نفس فكرة Subscription::expiryColumn()
 *     اللي اتعملت قبل كده، لكن بشكل عام قابل لإعادة الاستخدام في أي Repository).
 *   - استعلامات مخصصة كتير من غير ما تبوظ الـ Model الأساسي المستخدم في
 *     كل حتة تانية في المشروع.
 *
 * التوافق: BaseRepository ده كلاس جديد كليًا. محدش من الكود الحالي بيستخدمه
 * أو بيعرف بوجوده، فمفيش أي احتمال يبوظ حاجة شغالة.
 */
abstract class BaseRepository implements RepositoryInterface
{
    /** @var Database */
    protected $db;

    /** @var string اسم الجدول */
    protected $table;

    /** @var string المفتاح الأساسي */
    protected $primaryKey = 'id';

    /**
     * @var array<string,string> ترجمة اسم الحقل في الكود لاسم العمود
     * الحقيقي لو مختلف. مثال: ['is_active' => 'status']
     */
    protected $columnMap = [];

    /** @var bool هل الجدول بيدعم Soft Delete (عمود deleted_at)؟ */
    protected $softDeletes = false;

    /** @var array<string,string> كاش ثابت لكل نتائج اكتشاف الأعمدة الديناميكية */
    private static $detectedColumnsCache = [];

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * ترجمة اسم عمود منطقي لاسم العمود الحقيقي (لو موجود في $columnMap).
     */
    protected function col(string $logicalName): string
    {
        return $this->columnMap[$logicalName] ?? $logicalName;
    }

    /**
     * اكتشاف اسم عمود حقيقي في الجدول من قائمة مرشحين، عن طريق
     * INFORMATION_SCHEMA، بدل التخمين. النتيجة بتتحفظ (cache) على مستوى
     * الكلاس/الجدول عشان الاستعلام يحصل مرة واحدة بس لكل (جدول+مرشحين).
     *
     * الاستخدام النموذجي (في Repository الفرعي):
     *   $urlCol = $this->detectColumn(['main_url', 'url', 'website_url']);
     *
     * @param array $candidates أسماء مرشحة بترتيب الأولوية
     * @param string|null $fallback القيمة اللي ترجع لو مفيش أي مرشح موجود
     * @return string
     */
    protected function detectColumn(array $candidates, ?string $fallback = null): string
    {
        $cacheKey = $this->table . ':' . implode(',', $candidates);
        if (isset(self::$detectedColumnsCache[$cacheKey])) {
            return self::$detectedColumnsCache[$cacheKey];
        }

        $result = $fallback ?? ($candidates[0] ?? '');

        try {
            $placeholders = implode(',', array_fill(0, count($candidates), '?'));
            $sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                    AND COLUMN_NAME IN ({$placeholders})";
            $rows = $this->db->query($sql, array_merge([$this->table], $candidates));
            $found = array_map('strtolower', array_column($rows, 'COLUMN_NAME'));

            foreach ($candidates as $c) {
                if (in_array(strtolower($c), $found, true)) {
                    $result = $c;
                    break;
                }
            }
        } catch (Exception $e) {
            if (class_exists('Logger')) {
                Logger::warning('BaseRepository::detectColumn failed', [
                    'table' => $this->table,
                    'candidates' => $candidates,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        self::$detectedColumnsCache[$cacheKey] = $result;
        return $result;
    }

    public function find($id): ?array
    {
        $sql = "SELECT * FROM `{$this->table}` WHERE `{$this->primaryKey}` = ? " . $this->softDeleteClause() . " LIMIT 1";
        $result = $this->db->query($sql, [$id]);
        return $result[0] ?? null;
    }

    public function findBy(array $criteria, array $orderBy = [], int $limit = 0): array
    {
        $sql = "SELECT * FROM `{$this->table}` WHERE 1=1";
        $params = [];

        foreach ($criteria as $key => $value) {
            $column = $this->col($key);
            if ($value === null) {
                $sql .= " AND `{$column}` IS NULL";
            } else {
                $sql .= " AND `{$column}` = ?";
                $params[] = $value;
            }
        }

        $sql .= $this->softDeleteClause();

        if (!empty($orderBy)) {
            $parts = [];
            foreach ($orderBy as $field => $direction) {
                $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
                $parts[] = "`{$this->col($field)}` {$direction}";
            }
            $sql .= " ORDER BY " . implode(', ', $parts);
        }

        if ($limit > 0) {
            $sql .= " LIMIT ?";
            $params[] = $limit;
        }

        return $this->db->query($sql, $params);
    }

    public function create(array $data)
    {
        $mapped = [];
        foreach ($data as $key => $value) {
            $mapped[$this->col($key)] = $value;
        }

        $fields = array_keys($mapped);
        $values = array_values($mapped);
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $fieldsList = implode('`, `', $fields);

        $sql = "INSERT INTO `{$this->table}` (`{$fieldsList}`) VALUES ({$placeholders})";
        return $this->db->query($sql, $values);
    }

    public function update($id, array $data): bool
    {
        $mapped = [];
        foreach ($data as $key => $value) {
            $mapped[$this->col($key)] = $value;
        }

        $sets = [];
        $values = [];
        foreach ($mapped as $column => $value) {
            $sets[] = "`{$column}` = ?";
            $values[] = $value;
        }
        $values[] = $id;

        $sql = "UPDATE `{$this->table}` SET " . implode(', ', $sets) . " WHERE `{$this->primaryKey}` = ?";
        return $this->db->query($sql, $values) !== false;
    }

    public function delete($id): bool
    {
        if ($this->softDeletes) {
            return $this->update($id, ['deleted_at' => date('Y-m-d H:i:s')]);
        }

        $sql = "DELETE FROM `{$this->table}` WHERE `{$this->primaryKey}` = ?";
        return $this->db->query($sql, [$id]) !== false;
    }

    private function softDeleteClause(): string
    {
        return $this->softDeletes ? " AND deleted_at IS NULL" : "";
    }
}
