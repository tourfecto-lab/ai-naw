<?php

/**
 * Tourfecto - Email Custom Field Model (حقول مخصصة للمشتركين)
 * @version 1.0.0
 */
class EmailCustomField extends Model
{
    protected $table = 'email_custom_fields';
    protected $fillable = [
        'user_id', 'name', 'label', 'field_type', 'options',
        'is_required', 'is_system', 'sort_order'
    ];

    public const TYPE_TEXT = 'text';
    public const TYPE_NUMBER = 'number';
    public const TYPE_DATE = 'date';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_SELECT = 'select';
    public const TYPE_MULTI_SELECT = 'multi_select';

    public const VALID_TYPES = [
        self::TYPE_TEXT, self::TYPE_NUMBER, self::TYPE_DATE,
        self::TYPE_BOOLEAN, self::TYPE_SELECT, self::TYPE_MULTI_SELECT,
    ];

    /**
     * الحقول المدمجة التي تُنشأ تلقائيًا لكل حساب جديد
     * @return array
     */
    public static function systemFields(): array
    {
        return [
            ['name' => 'first_name', 'label' => 'الاسم الأول', 'field_type' => self::TYPE_TEXT],
            ['name' => 'last_name', 'label' => 'الاسم الأخير', 'field_type' => self::TYPE_TEXT],
            ['name' => 'company', 'label' => 'الشركة', 'field_type' => self::TYPE_TEXT],
            ['name' => 'city', 'label' => 'المدينة', 'field_type' => self::TYPE_TEXT],
            ['name' => 'phone', 'label' => 'الهاتف', 'field_type' => self::TYPE_TEXT],
            ['name' => 'birthday', 'label' => 'تاريخ الميلاد', 'field_type' => self::TYPE_DATE],
        ];
    }
}
