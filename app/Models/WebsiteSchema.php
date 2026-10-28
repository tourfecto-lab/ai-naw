<?php

/**
 * أعمدة جدول websites الحقيقية على السيرفر الحالي.
 * جدول الإنتاج أعمدته مختلفة عن النسخ المحلية (domain بدل main_url، brand_name بدل company_name، ومن غير deleted_at أحيانًا)،
 * وDatabase::query بيرجّع [] بصمت لأي SELECT فيه عمود مش موجود - فأي استعلام جديد على websites لازم يبني أعمدته من هنا.
 */
final class WebsiteSchema
{
    private static ?array $cols = null;

    public static function columns(): array
    {
        if (self::$cols === null) {
            self::$cols = [];
            try {
                foreach ((array) Database::getInstance()->query('SHOW COLUMNS FROM `websites`') as $r) {
                    $n = strtolower((string) ($r['Field'] ?? ''));
                    if ($n !== '') self::$cols[$n] = true;
                }
            } catch (Throwable $e) {
            }
        }
        return self::$cols;
    }

    public static function has(string $col): bool
    {
        return isset(self::columns()[strtolower($col)]);
    }

    /** عمود رابط الموقع */
    public static function url(): string
    {
        return class_exists('Website') ? Website::urlColumn() : 'main_url';
    }

    private static function p(string $alias): string
    {
        return $alias !== '' ? $alias . '.' : '';
    }

    /** «$a.`عمود` AS اسم» لو العمود موجود، وإلا NULL AS اسم - عشان الاستعلام مايقعش */
    public static function col(string $col, string $as, string $alias = ''): string
    {
        return (self::has($col) ? self::p($alias) . '`' . $col . '`' : 'NULL') . ' AS `' . $as . '`';
    }

    /** اسم الشركة: أول عمود موجود من الأسماء المحتملة، وإلا الرابط */
    public static function nameExpr(string $alias = ''): string
    {
        $parts = [];
        foreach (['brand_name', 'company_name', 'business_name', 'name'] as $c) {
            if (self::has($c)) $parts[] = "NULLIF(" . self::p($alias) . "`{$c}`, '')";
        }
        $parts[] = self::p($alias) . '`' . self::url() . '`';
        return count($parts) > 1 ? 'COALESCE(' . implode(', ', $parts) . ')' : $parts[0];
    }

    /** شرط «الموقع مش ممسوح» لو الجدول فيه deleted_at (وإلا مفيش شرط) */
    public static function alive(string $alias = ''): string
    {
        return self::has('deleted_at') ? ' AND ' . self::p($alias) . 'deleted_at IS NULL' : '';
    }

    /** عمود اسم الشركة للـINSERT (أو null لو مفيش) */
    public static function nameColumn(): ?string
    {
        foreach (['brand_name', 'company_name', 'business_name', 'name'] as $c) {
            if (self::has($c)) return $c;
        }
        return null;
    }
}
