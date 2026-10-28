<?php
/** Additive tables for AI insights (sources, sentiment, crawler visits); same ZIP-deploy pattern as AiVisibilitySchema. */
final class AiInsightsSchema
{
    private static bool $ready=false;
    public static function ensureSchema(Database $db): void
    {
        if (self::$ready) return;
        $pdo=$db->getConnection();
        if ($pdo->inTransaction()) throw new RuntimeException('Install AI insights schema outside a transaction');
        AiVisibilitySchema::ensureSchema($db);
        if ((int)$pdo->query("SELECT GET_LOCK('tourfecto:ai-insights-schema',10)")->fetchColumn()!==1) throw new RuntimeException('AI insights schema busy');
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `seo_ai_sentiment` (answer_id BIGINT UNSIGNED PRIMARY KEY, website_id INT NOT NULL, user_id INT NOT NULL, market_id INT NOT NULL, provider VARCHAR(24) NOT NULL, sentiment VARCHAR(12) NOT NULL, reason VARCHAR(400) NOT NULL DEFAULT '', excerpt TEXT NOT NULL, method VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, KEY site(website_id,market_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS `seo_ai_crawler_hits` (website_id INT NOT NULL, user_id INT NOT NULL, day DATE NOT NULL, bot VARCHAR(40) NOT NULL, purpose VARCHAR(16) NOT NULL, path_hash CHAR(40) NOT NULL, path VARCHAR(255) NOT NULL, source VARCHAR(10) NOT NULL, hits INT UNSIGNED NOT NULL DEFAULT 0, last_seen DATETIME NOT NULL, PRIMARY KEY(website_id,day,bot,path_hash,source), KEY recent(website_id,day)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS `seo_ai_crawler_tokens` (website_id INT PRIMARY KEY, user_id INT NOT NULL, token_hash CHAR(64) NOT NULL, created_at DATETIME NOT NULL, UNIQUE KEY token(token_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            self::$ready=true;
        } finally { $pdo->query("SELECT RELEASE_LOCK('tourfecto:ai-insights-schema')"); }
    }
}
