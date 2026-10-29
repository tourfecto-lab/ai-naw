<?php
/** جدول قرارات العقل وقت التشغيل (الهجرات بتتطبق يدوي على السيرفر). DDL مايتعملش جوه transaction. */
final class BrainSchema
{
    private static bool $ready = false;

    public static function ensure(Database $db): void
    {
        if (self::$ready) return;
        $pdo = $db->getConnection();
        if ($pdo->inTransaction()) throw new RuntimeException('Install brain schema before starting transactions');
        if (!$db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='brain_decisions'")) {
            $sql = (string) file_get_contents(dirname(__DIR__, 3) . '/database/migrations/2026_10_29_000003_strategic_brain.sql');
            $create = substr($sql, (int) strpos($sql, 'CREATE TABLE'));
            if ($create === '' || !str_starts_with($create, 'CREATE TABLE')) throw new RuntimeException('Brain migration missing');
            $pdo->exec($create);
        }
        self::$ready = true;
    }
}
