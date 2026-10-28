<?php
/** Additive fallback for ZIP deployments; never runs DDL inside an application transaction. */
final class AiVisibilitySchema
{
    private static bool $ready=false;
    public static function ensureSchema(Database $db): void
    {
        if (self::$ready) return;
        $pdo=$db->getConnection();
        if ($pdo->inTransaction()) throw new RuntimeException('Install GEO schema outside a transaction');
        if ((int)$pdo->query("SELECT GET_LOCK('tourfecto:geo-schema',10)")->fetchColumn()!==1) throw new RuntimeException('GEO schema busy');
        try {
            $s=$pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $s->execute(['seo_ai_prompt_sets']);
            if (!$s->fetchColumn()) $pdo->exec("CREATE TABLE IF NOT EXISTS `seo_ai_prompt_sets` (id INT AUTO_INCREMENT PRIMARY KEY, website_id INT NOT NULL, user_id INT NOT NULL, market_id INT NOT NULL, version INT NOT NULL DEFAULT 1, profile_version INT NOT NULL, facts_hash CHAR(64) NOT NULL, questions_json MEDIUMTEXT NOT NULL, approved_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE KEY site_market(website_id,market_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $s=$pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $s->execute(['seo_ai_entities']);
            if (!$s->fetchColumn()) $pdo->exec("CREATE TABLE IF NOT EXISTS `seo_ai_entities` (website_id INT PRIMARY KEY, user_id INT NOT NULL, version INT NOT NULL DEFAULT 1, aliases_json TEXT NOT NULL, profiles_json TEXT NOT NULL, address VARCHAR(500) NOT NULL DEFAULT '', confirmed_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $s=$pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $s->execute(['seo_ai_calls']);
            if (!$s->fetchColumn()) $pdo->exec("CREATE TABLE IF NOT EXISTS `seo_ai_calls` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, website_id INT NOT NULL, user_id INT NOT NULL, market_id INT NOT NULL, provider VARCHAR(24) NOT NULL, question_hash CHAR(64) NOT NULL, question VARCHAR(500) NOT NULL, week_start DATE NOT NULL, set_version INT NOT NULL, profile_version INT NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'pending', reserved_cost DECIMAL(14,6) NOT NULL, cost DECIMAL(14,6) NULL, cost_basis VARCHAR(60) NULL, created_at DATETIME NOT NULL, UNIQUE KEY weekly_check(website_id,market_id,provider,question_hash,week_start), KEY month_cost(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $s=$pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $s->execute(['seo_ai_answers']);
            if (!$s->fetchColumn()) $pdo->exec("CREATE TABLE IF NOT EXISTS `seo_ai_answers` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, call_id BIGINT UNSIGNED NOT NULL UNIQUE, website_id INT NOT NULL, user_id INT NOT NULL, market_id INT NOT NULL, provider VARCHAR(24) NOT NULL, question VARCHAR(500) NOT NULL, question_hash CHAR(64) NOT NULL, week_start DATE NOT NULL, set_version INT NOT NULL, mentioned_brand TINYINT NOT NULL, brand_position INT NULL, cited_domain TINYINT NULL, cited_urls MEDIUMTEXT NOT NULL, competitors_mentioned MEDIUMTEXT NOT NULL, answer_excerpt TEXT NOT NULL, model VARCHAR(100) NOT NULL, cost DECIMAL(14,6) NULL, cost_basis VARCHAR(60) NOT NULL, usage_json TEXT NOT NULL, checked_at DATETIME NOT NULL, KEY trend(website_id,market_id,provider,week_start)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $s=$pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $s->execute(['seo_ai_tasks']);
            if (!$s->fetchColumn()) $pdo->exec("CREATE TABLE IF NOT EXISTS `seo_ai_tasks` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, website_id INT NOT NULL, user_id INT NOT NULL, market_id INT NOT NULL, evidence_hash CHAR(64) NOT NULL, kind VARCHAR(50) NOT NULL, title VARCHAR(255) NOT NULL, instructions TEXT NOT NULL, evidence_json MEDIUMTEXT NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'needs_review', created_at DATETIME NOT NULL, UNIQUE KEY task_evidence(website_id,market_id,evidence_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $s=$pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $s->execute(['seo_ai_budget_alerts']);
            if (!$s->fetchColumn()) $pdo->exec("CREATE TABLE IF NOT EXISTS `seo_ai_budget_alerts` (month_key CHAR(7) PRIMARY KEY, created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("INSERT INTO pay_per_use_pricing(feature_key,label,price,currency,currency_symbol,is_active) VALUES ('ai_visibility','قياس ظهور AI: سؤال ومزوّد',0.15,'USD','$',1) ON DUPLICATE KEY UPDATE feature_key=feature_key");
            self::$ready=true;
        } finally { $pdo->query("SELECT RELEASE_LOCK('tourfecto:geo-schema')"); }
    }
}
