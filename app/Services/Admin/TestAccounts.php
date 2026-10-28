<?php

/**
 * حسابات التجربة: أي عميل عليه تاج «تجربة» (أو test) من نافذة العميل في الأدمن بيتشال من إحصائيات المنصة
 * (رصيد المنصة، الإيداعات، الإيراد الشهري MRR، الاشتراكات، عدد العملاء). حسابه نفسه بيفضل شغال عادي.
 */
final class TestAccounts
{
    public const TAGS = ['تجربة', 'test', 'اختبار'];
    private static ?bool $ready = null;

    private static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = (bool) Database::getInstance()->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'admin_user_tags'");
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    /** « AND <col> NOT IN (حسابات التجربة)» - $col عمود رقم العميل (زي s.user_id أو user_id) */
    public static function exclude(string $col): string
    {
        if (!self::ready() || !preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/i', $col)) return '';
        $tags = implode(',', array_map(fn ($t) => "'" . str_replace("'", "''", $t) . "'", self::TAGS));
        return " AND {$col} NOT IN (SELECT t.user_id FROM admin_user_tags t WHERE t.tag IN ({$tags}))";
    }

    public static function isTest(int $userId): bool
    {
        if (!self::ready()) return false;
        try {
            $in = implode(',', array_fill(0, count(self::TAGS), '?'));
            return (bool) Database::getInstance()->query("SELECT 1 FROM admin_user_tags WHERE user_id = ? AND tag IN ({$in}) LIMIT 1", array_merge([$userId], self::TAGS));
        } catch (Throwable $e) {
            return false;
        }
    }
}
