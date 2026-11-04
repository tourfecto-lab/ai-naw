<?php
/** جدول قرارات العقل وقت التشغيل (الهجرات بتتطبق يدوي على السيرفر). DDL مايتعملش جوه transaction. */
final class BrainSchema
{
    private static bool $ready = false;
    private static ?bool $extras = null;

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
            'tbi_crm_handoffs' => '2026_10_29_000008_ai_ceo_sales.sql', 'aiceo_brand_audits' => '2026_10_29_000009_ai_ceo_brand.sql',
            'aiceo_ops_checks' => '2026_10_29_000010_ai_ceo_ops.sql', 'aiceo_incidents' => '2026_10_29_000010_ai_ceo_ops.sql', 'aiceo_dev_backlog' => '2026_10_29_000010_ai_ceo_ops.sql',
            'aiceo_cost_items' => '2026_10_29_000011_ai_ceo_finance.sql', 'aiceo_goals' => '2026_10_29_000011_ai_ceo_finance.sql', 'aiceo_goal_tasks' => '2026_10_29_000011_ai_ceo_finance.sql',
            'aiceo_health_scores' => '2026_10_29_000012_ai_ceo_executive.sql', 'aiceo_competitors' => '2026_10_29_000012_ai_ceo_executive.sql'] as $table => $file) {
            if ($db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?", [$table])) continue;
            // نفس تعريف الجدول من ملف الهجرة بالظبط (مصدر واحد للحقيقة)
            if (!preg_match('/CREATE TABLE IF NOT EXISTS ' . $table . ' \\(.*?\\) ENGINE=[^;]+;/s', (string) file_get_contents($dir . $file), $m)) throw new RuntimeException('Brain migration missing: ' . $table);
            $pdo->exec($m[0]);
        }
        self::$ready = true;
    }

    /**
     * جداول الأهداف والسياسة والذاكرة والسجل. منفصلة عن ensure() عن قصد: لو السيرفر مارضيش يعمل DDL،
     * العقل الأساسي (القرارات) يفضل شغال، والإضافات دي بس هي اللي تتعطل.
     */
    public static function ensureExtras(Database $db): bool
    {
        if (self::$extras !== null) return self::$extras;
        try {
            $pdo = $db->getConnection();
            if ($pdo->inTransaction()) return false;
            $file = dirname(__DIR__, 3) . '/database/migrations/2026_11_04_000001_brain_goals_plan_control.sql';
            $sql = (string) file_get_contents($file);
            foreach (['brain_goals', 'brain_policy', 'brain_prefs', 'brain_memory', 'brain_audit'] as $table) {
                if ($db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?", [$table])) continue;
                if (!preg_match('/CREATE TABLE IF NOT EXISTS ' . $table . ' \\(.*?\\) ENGINE=[^;]+;/s', $sql, $m)) return self::$extras = false;
                $pdo->exec($m[0]);
            }
            return self::$extras = true;
        } catch (Throwable $e) {
            if (class_exists('Logger')) Logger::warning('Brain extras schema unavailable', ['error' => $e->getMessage()]);
            return self::$extras = false;
        }
    }
}
