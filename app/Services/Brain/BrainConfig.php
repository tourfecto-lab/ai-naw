<?php
/**
 * Tourfecto - إعدادات العقل الاستراتيجي على مستوى المنصة (من لوحة الأدمن، جدول system_settings)
 *
 * - brain_enabled:              إيقاف العقل للكل (صفحة العميل بتقول إنه متوقف مؤقتًا).
 * - brain_autonomy_allowed:     السماح بالتشغيل الذاتي للشركات (لو اتقفل، مفيش تنفيذ تلقائي حتى لو الشركة فاتحاه).
 * - brain_market_enabled:       جلب بيانات السوق من المصادر الخارجية.
 * - brain_auto_actions_per_day: أقصى عدد قرارات بتتنفّذ تلقائيًا لكل شركة في اليوم.
 * أي خطأ في القراية = القيمة الافتراضية (الإعدادات دي ماتوقفش الصفحة).
 */
final class BrainConfig
{
    public const DEFAULTS = ['brain_enabled' => 1, 'brain_autonomy_allowed' => 1, 'brain_market_enabled' => 1, 'brain_auto_actions_per_day' => 10];
    private static ?array $cache = null;

    public static function all(?Database $db = null): array
    {
        if (self::$cache !== null) return self::$cache;
        $out = self::DEFAULTS;
        try {
            $keys = array_keys(self::DEFAULTS);
            $rows = ($db ?? Database::getInstance())->query('SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')', $keys) ?: [];
            foreach ($rows as $r) if (is_numeric($r['setting_value'])) $out[$r['setting_key']] = max(0, (int) $r['setting_value']);
        } catch (Throwable $e) {
            // الافتراضي
        }
        return self::$cache = $out;
    }

    public static function get(string $key, ?Database $db = null): int
    {
        return (int) (self::all($db)[$key] ?? 0);
    }

    /** حفظ من لوحة الأدمن (القيم أرقام صحيحة بس) */
    public static function save(Database $db, array $values): array
    {
        foreach (self::DEFAULTS as $key => $default) {
            if (!array_key_exists($key, $values)) continue;
            $v = $key === 'brain_auto_actions_per_day' ? max(0, min(200, (int) $values[$key])) : (filter_var($values[$key], FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
            $db->query("INSERT INTO system_settings (setting_key, setting_value, is_secret, category) VALUES (?, ?, 0, 'brain')
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$key, (string) $v]);
        }
        self::$cache = null;
        return self::all($db);
    }

    /** للاختبارات */
    public static function reset(): void
    {
        self::$cache = null;
    }
}
