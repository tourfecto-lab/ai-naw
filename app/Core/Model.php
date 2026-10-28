<?php

/**
 * Tourfecto - Base Model Class
 * كلاس النموذج الأساسي مع ORM بسيط
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 * @phpstan-consistent-constructor
 */
abstract class Model
{
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    protected $db;

    /**
     * @var string $table - اسم الجدول
     */
    protected $table;

    /**
     * @var string $primaryKey - المفتاح الأساسي
     */
    protected $primaryKey = 'id';

    /**
     * @var array $fillable - الحقول القابلة للتعبئة
     */
    protected $fillable = [];

    /**
     * @var array $hidden - الحقول المخفية
     */
    protected $hidden = [];

    /**
     * @var array $attributes - قيم النموذج
     */
    protected $attributes = [];

    /**
     * @var array $original - القيم الأصلية
     */
    protected $original = [];

    /**
     * @var array $relations - العلاقات
     */
    protected $relations = [];

    /**
     * @var array $columnAliases - تحويل اسم الحقل في الكود (مفتاح المصفوفة
     * المستخدم في كل مكان بالتطبيق) إلى اسم العمود الحقيقي في قاعدة
     * البيانات لو مختلف (مثال: ['main_url' => 'url']). فاضية افتراضيًا
     * (بدون أي تغيير في السلوك لأي Model تاني)، وبتتحدد وقت التشغيل في
     * الكلاسات اللي محتاجاها فقط، عشان نتعامل مع اختلاف قاعدة البيانات
     * الفعلية المنشورة عن أسماء الأعمدة المفترضة في الكود/الـ migrations.
     */
    protected $columnAliases = [];

    /**
     * @var array $columnValueTransforms - دالة تحويل اختيارية لقيمة حقل
     * معيّن (مفتاح المصفوفة = اسم الحقل في الكود، زي columnAliases فوق)
     * تتطبّق قبل الحفظ في قاعدة البيانات مباشرة. فاضية افتراضيًا (بدون
     * أي تأثير على أي Model تاني). أُضيفت عشان تحل باگ حقيقي: أعمدة
     * زي website.target_countries بقت JSON فعليًا على السيرفر (مع
     * CHECK CONSTRAINT بيرفض أي قيمة مش JSON صالح)، لكن الكود لسه
     * بيبعت نص عادي (زي "مصر") تحت اسم الحقل القديم target_country -
     * فـ columnAliases لوحدها كانت بتغيّر اسم العمود بس مش شكل القيمة،
     * فالإدراج كان بيفشل بـ"Integrity constraint violation".
     */
    protected $columnValueTransforms = [];

    /**
     * Constructor
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->db = Database::getInstance();
        $this->fill($attributes);

        // تصحيح: primaryKey ('id' افتراضيًا) غير مدرج عمدًا ضمن $fillable
        // (حماية من mass-assignment)، لكن هذا كان يجعل fill() يتجاهله حتى
        // عند بناء الكائن من صف قاعدة بيانات حقيقي عبر find()/where()، فيظل
        // $attributes['id'] مفقودًا دائمًا (Undefined array key "id").
        // نضيفه هنا صراحة بمعزل عن قائمة fillable.
        if (isset($attributes[$this->primaryKey])) {
            $this->attributes[$this->primaryKey] = $attributes[$this->primaryKey];
        }
    }

    /**
     * تعبئة النموذج بالبيانات
     * @param array $attributes
     * @return Model
     */
    public function fill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            if (in_array($key, $this->fillable)) {
                $this->attributes[$key] = $value;
            }
        }
        return $this;
    }

    /**
     * الحصول على قيمة حقل
     * @param string $key
     * @return mixed
     */
    public function getAttribute(string $key)
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * ربط الكائن بصف موجود عشان save() يعمل UPDATE مش INSERT.
     * المفتاح الأساسي مش في $fillable (حماية من mass-assignment)، فـ setAttribute('id', ...) كان بيتجاهله بصمت
     * وكل "إعادة ربط" كانت بتعمل صف جديد (5 نسخ من ربط Google Business لنفس الفرع). ده نداء صريح بس من الكود.
     */
    public function setKeyValue($id): self
    {
        $this->attributes[$this->primaryKey] = $id;
        return $this;
    }

    /**
     * تعيين قيمة حقل
     * @param string $key
     * @param mixed $value
     * @return Model
     */
    public function setAttribute(string $key, $value): self
    {
        if (in_array($key, $this->fillable)) {
            $this->attributes[$key] = $value;
        }
        return $this;
    }

    /**
     * حفظ النموذج
     * @return bool|int
     */
    public function save()
    {
        if (isset($this->attributes[$this->primaryKey])) {
            return $this->update();
        }
        return $this->insert();
    }

    /**
     * ترجمة مفاتيح المصفوفة (attribute keys) لأسماء الأعمدة الحقيقية في
     * قاعدة البيانات حسب $columnAliases، لو موجودة. بدون تأثير لو
     * $columnAliases فاضية (السلوك الافتراضي لكل الـ Models القديمة).
     */
    protected function toDbAttributes(array $attrs): array
    {
        // تحويل القيم أولًا (لو فيه أي تحويل معرّف لأي حقل) - ده لازم يحصل
        // قبل تغيير أسماء الأعمدة عشان $this->columnValueTransforms
        // بيستخدم أسماء الحقول زي ما الكود شايفها (قبل الـalias).
        if (!empty($this->columnValueTransforms)) {
            foreach ($attrs as $key => $value) {
                if (isset($this->columnValueTransforms[$key]) && is_callable($this->columnValueTransforms[$key])) {
                    $attrs[$key] = ($this->columnValueTransforms[$key])($value);
                }
            }
        }

        if (empty($this->columnAliases)) {
            return $attrs;
        }
        $out = [];
        foreach ($attrs as $key => $value) {
            $out[$this->columnAliases[$key] ?? $key] = $value;
        }
        return $out;
    }

    /**
     * العكس: ترجمة صف حقيقي من قاعدة البيانات (بأسماء الأعمدة الحقيقية)
     * لمفاتيح الكود المتوقعة، قبل ما نبني الكائن منه.
     */
    protected function fromDbRow(array $row): array
    {
        if (empty($this->columnAliases)) {
            return $row;
        }
        $flipped = array_flip($this->columnAliases);
        $out = [];
        foreach ($row as $key => $value) {
            $out[$flipped[$key] ?? $key] = $value;
        }
        return $out;
    }

    /**
     * إدراج سجل جديد
     * @return int
     */
    private function insert(): int
    {
        $dbAttributes = $this->toDbAttributes($this->attributes);
        $fields = array_keys($dbAttributes);
        $values = array_values($dbAttributes);

        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $fieldsList = implode('`, `', $fields);

        $sql = "INSERT INTO `{$this->table}` (`{$fieldsList}`) VALUES ({$placeholders})";

        $id = $this->db->query($sql, $values);

        if ($id) {
            $this->attributes[$this->primaryKey] = $id;
            $this->original = $this->attributes;
        }

        return $id;
    }

    /**
     * تحديث سجل
     * @return bool
     */
    private function update(): bool
    {
        $id = $this->attributes[$this->primaryKey];
        $attrsWithoutPk = $this->attributes;
        unset($attrsWithoutPk[$this->primaryKey]);

        $dbAttributes = $this->toDbAttributes($attrsWithoutPk);
        $fields = array_keys($dbAttributes);
        $values = array_values($dbAttributes);

        $sets = [];
        foreach ($fields as $field) {
            $sets[] = "`{$field}` = ?";
        }

        $sql = "UPDATE `{$this->table}` SET " . implode(', ', $sets) .
               " WHERE `{$this->primaryKey}` = ?";

        $values[] = $id;

        $result = $this->db->query($sql, $values);
        $this->original = $this->attributes;
        $this->attributes[$this->primaryKey] = $id;

        return $result !== false;
    }

    /**
     * حذف السجل
     * @return bool
     */
    public function delete(): bool
    {
        if (!isset($this->attributes[$this->primaryKey])) {
            return false;
        }

        $sql = "DELETE FROM `{$this->table}` WHERE `{$this->primaryKey}` = ?";
        $result = $this->db->query($sql, [$this->attributes[$this->primaryKey]]);

        if ($result !== false) {
            $this->attributes = [];
            $this->original = [];
        }

        return $result !== false;
    }

    /**
     * البحث عن سجل بالمفتاح الأساسي
     * @param mixed $id
     * @return Model|null
     */
    public function find($id): ?self
    {
        $sql = "SELECT * FROM `{$this->table}` WHERE `{$this->primaryKey}` = ? LIMIT 1";
        $result = $this->db->query($sql, [$id]);

        if (empty($result)) {
            return null;
        }

        $model = new static($this->fromDbRow($result[0]));
        $model->original = $result[0];
        return $model;
    }

    /**
     * البحث عن سجلات حسب الشرط
     * @param array $conditions
     * @param array $orderBy
     * @param int $limit
     * @return array
     */
    public function where(array $conditions, array $orderBy = [], int $limit = 0): array
    {
        $sql = "SELECT * FROM `{$this->table}` WHERE 1=1";
        $params = [];

        $dbConditions = $this->toDbAttributes($conditions);
        foreach ($dbConditions as $key => $value) {
            $sql .= " AND `{$key}` = ?";
            $params[] = $value;
        }

        if (!empty($orderBy)) {
            $sql .= " ORDER BY ";
            $orderParts = [];
            $dbOrderBy = $this->toDbAttributes($orderBy);
            foreach ($dbOrderBy as $field => $direction) {
                $orderParts[] = "`{$field}` " . ($direction === 'DESC' ? 'DESC' : 'ASC');
            }
            $sql .= implode(', ', $orderParts);
        }

        if ($limit > 0) {
            $sql .= " LIMIT ?";
            $params[] = $limit;
        }

        $result = $this->db->query($sql, $params);

        return array_map(function ($data) {
            return new static($this->fromDbRow($data));
        }, $result);
    }

    /**
     * الحصول على جميع السجلات
     * @param array $orderBy
     * @param int $limit
     * @return array
     */
    public function all(array $orderBy = [], int $limit = 0): array
    {
        return $this->where([], $orderBy, $limit);
    }

    /**
     * تحويل النموذج إلى مصفوفة
     * @return array
     */
    public function toArray(): array
    {
        $data = $this->attributes;

        // إخفاء الحقول المحمية
        foreach ($this->hidden as $field) {
            unset($data[$field]);
        }

        // إضافة العلاقات
        foreach ($this->relations as $key => $value) {
            $data[$key] = $value;
        }

        return $data;
    }

    /**
     * تحويل النموذج إلى JSON
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * تعريف علاقة belongsTo
     * @param string $related
     * @param string $foreignKey
     * @param string $localKey
     * @return Model|null
     */
    protected function belongsTo(string $related, string $foreignKey, string $localKey = 'id'): ?Model
    {
        $relatedModel = new $related();
        $value = $this->attributes[$foreignKey] ?? null;

        if (!$value) {
            return null;
        }

        return $relatedModel->find($value);
    }

    /**
     * تعريف علاقة hasMany
     * @param string $related
     * @param string $foreignKey
     * @param string $localKey
     * @return array
     */
    protected function hasMany(string $related, string $foreignKey, string $localKey = 'id'): array
    {
        $relatedModel = new $related();
        $value = $this->attributes[$localKey] ?? null;

        if (!$value) {
            return [];
        }

        return $relatedModel->where([$foreignKey => $value]);
    }

    /**
     * __get magic method
     * @param string $key
     * @return mixed
     */
    public function __get(string $key)
    {
        return $this->getAttribute($key);
    }

    /**
     * __set magic method
     * @param string $key
     * @param mixed $value
     */
    public function __set(string $key, $value)
    {
        $this->setAttribute($key, $value);
    }

    /**
     * __isset magic method
     * @param string $key
     * @return bool
     */
    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }
}
