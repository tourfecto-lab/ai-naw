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
        $dir = dirname(__DIR__, 3) . '/database/migrations/';
        foreach (['brain_decisions' => '2026_10_29_000003_strategic_brain.sql', 'brain_sharing' => '2026_10_29_000004_brain_shared_market.sql',
            'market_signals' => '2026_10_29_000004_brain_shared_market.sql', 'market_fetch_log' => '2026_10_29_000004_brain_shared_market.sql',
            'brain_settings' => '2026_10_29_000005_brain_settings.sql',
            'aiceo_decisions' => '2026_10_29_000006_ai_ceo_foundation.sql', 'aiceo_memory' => '2026_10_29_000006_ai_ceo_foundation.sql', 'aiceo_activity' => '2026_10_29_000006_ai_ceo_foundation.sql',
            'tbi_reports' => '2026_10_29_000007_tourism_business_intelligence.sql', 'tbi_consent_events' => '2026_10_29_000007_tourism_business_intelligence.sql',
            'tbi_suppression' => '2026_10_29_000007_tourism_business_intelligence.sql', 'tbi_merges' => '2026_10_29_000007_tourism_business_intelligence.sql',
            'tbi_crm_handoffs' => '2026_10_29_000008_ai_ceo_sales.sql'] as $table => $file) {
            if ($db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?", [$table])) continue;
            // نفس تعريف الجدول من ملف الهجرة بالظبط (مصدر واحد للحقيقة)
            if (!preg_match('/CREATE TABLE IF NOT EXISTS ' . $table . ' \\(.*?\\) ENGINE=[^;]+;/s', (string) file_get_contents($dir . $file), $m)) throw new RuntimeException('Brain migration missing: ' . $table);
            $pdo->exec($m[0]);
        }
        self::$ready = true;
    }
}
