<?php

/**
 * Tourfecto - تحديثات قاعدة البيانات من لوحة الأدمن (من غير Terminal ولا phpMyAdmin)
 *
 *  1) بيشغّل ملفات database/migrations الجديدة (من BASELINE ورايح) اللي لسه
 *     ماتسجلتش في schema_migrations - نفس الجدول اللي database/repair_production.php
 *     بيستخدمه، يعني الطريقتين متوافقين.
 *     أي جملة بتفشل لأن الحاجة موجودة أصلًا (عمود/جدول/فهرس) بتتعدّى عادي.
 *  2) بيوسّع أعمدة ENUM: بيضيف القيم اللي الكود محتاجها من غير ما يشيل أي
 *     قيمة موجودة (بدل MODIFY ثابت ممكن يمسح بيانات). القائمة تحت = اللي
 *     كان في TOURFECTO_FIX_ENUMS / FIX_ALL / FIX_AI_USAGE_ENUM.
 *  3) تحويلات بيانات بعد التوسيع (crm_tasks: pending→open، completed→done).
 */
class SchemaUpdater
{
    /** الملفات اللي قبل كده متطبقة على الإنتاج يدويًا - مش هنلمسها من هنا */
    private const BASELINE = '2026_10_08_000000';

    /** أخطاء معناها "موجود بالفعل" */
    private const IGNORABLE = [1050, 1060, 1061, 1068, 1091, 1826, 1831];

    /** [table, column, القيم المطلوبة, default (null = سيبه زي ما هو)] */
    private const ENUMS = [
        ['users', 'role', ['super_admin', 'admin', 'agency_owner', 'manager', 'agent', 'client', 'user'], null],
        // إيقاف الحساب من لوحة الأدمن بيكتب 'suspended'
        ['users', 'status', ['active', 'inactive', 'banned', 'suspended'], null],
        ['crm_tasks', 'status', ['open', 'in_progress', 'done', 'cancelled'], 'open'],
        ['crm_meetings', 'status', ['scheduled', 'confirmed', 'completed', 'cancelled', 'no_show'], null],
        ['ad_alerts', 'rule_type', ['budget_exhausted', 'cpc_spike', 'ctr_drop', 'landing_page_down', 'budget_pacing', 'creative_underperforming', 'creative_stale', 'variant_wasted_spend', 'ab_test_inconclusive'], null],
        ['ad_alert_rules', 'rule_type', ['budget_exhausted', 'cpc_spike', 'ctr_drop', 'landing_page_down', 'budget_pacing', 'creative_underperforming', 'creative_stale', 'variant_wasted_spend', 'ab_test_inconclusive'], null],
        ['ad_optimization_logs', 'action_type', ['increase_budget', 'decrease_budget', 'pause_campaign', 'add_negative_keywords', 'add_keywords', 'rotate_ad_copy', 'broaden_audience', 'narrow_audience', 'no_action_recommended', 'resume_campaign', 'rollback'], null],
        ['ci_snapshots', 'page_type', ['homepage', 'pricing', 'offers', 'products', 'services', 'blog', 'landing', 'contact', 'sitemap', 'careers'], null],
        ['ci_changes', 'page_type', ['homepage', 'pricing', 'offers', 'products', 'services', 'blog', 'landing', 'contact', 'sitemap', 'careers'], null],
        ['wallet_transactions', 'type', ['deposit', 'subscription_charge', 'refund', 'admin_adjustment', 'card_redemption', 'subscription_credit'], null],
        ['invoices', 'status', ['pending', 'paid', 'failed', 'cancelled', 'draft', 'issued', 'partially_paid', 'overdue', 'refunded'], null],
        ['email_subscribers', 'status', ['subscribed', 'unsubscribed', 'bounced', 'pending_optin'], null],
        ['reviews', 'source_platform', ['tripadvisor', 'google_business', 'booking', 'trustpilot', 'custom', 'expedia', 'other'], null],
        ['agency_commissions', 'status', ['pending', 'paid', 'voided'], null],
        ['ai_reports', 'report_type', ['seo_aeo_geo_full', 'seo_only', 'aeo_only', 'geo_only', 'seo', 'aeo', 'geo', 'full'], null],
        ['ai_articles', 'status', ['generating', 'completed', 'failed', 'scheduled', 'schedule_failed', 'published', 'publish_failed'], null],
        ['platform_connections', 'status', ['connected', 'disconnected', 'error', 'token_expired'], null],
        ['ci_insights', 'status', ['new', 'reviewed', 'dismissed', 'actioned'], null],
        ['api_usage_logs', 'api_type', ['gemini', 'openai', 'deepseek', 'kimi', 'whatsapp', 'tripadvisor', 'google', 'stripe', 'paypal', 'other', 'anthropic'], null],
    ];

    /** تحويلات بيانات بعد التوسيع: [table, column, from, to] */
    private const DATA_MAPS = [
        ['crm_tasks', 'status', 'pending', 'open'],
        ['crm_tasks', 'status', 'completed', 'done'],
    ];

    private PDO $pdo;
    private string $dir;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
        $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 3);
        $this->dir = $root . '/database/migrations';
    }

    /** الحالة الحالية: إيه اللي ناقص */
    public function status(): array
    {
        $done = $this->applied();
        $files = [];
        foreach ($this->files() as $f) {
            $name = basename($f);
            $files[] = [
                'file' => $name,
                'applied' => isset($done[$name]),
                'applied_at' => $done[$name]['applied_at'] ?? null,
            ];
        }
        $enums = [];
        foreach (self::ENUMS as [$t, $c, $need]) {
            $cur = $this->enumValues($t, $c);
            if ($cur === null) {
                continue; // الجدول/العمود مش موجود - مش مشكلتنا هنا
            }
            $missing = array_values(array_diff($need, $cur));
            if ($missing) {
                $enums[] = ['table' => $t, 'column' => $c, 'missing' => $missing];
            }
        }
        $pendingMaps = 0;
        foreach (self::DATA_MAPS as [$t, $c, $from]) {
            $cur = $this->enumValues($t, $c);
            if ($cur !== null && in_array($from, $cur, true)) {
                try {
                    $pendingMaps += (int) $this->pdo->query("SELECT COUNT(*) FROM `{$t}` WHERE `{$c}` = " . $this->pdo->quote($from))->fetchColumn();
                } catch (Throwable $e) {
                }
            }
        }
        $pendingFiles = count(array_filter($files, fn($f) => !$f['applied']));
        return [
            'files' => $files,
            'pending_files' => $pendingFiles,
            'enums' => $enums,
            'pending_data' => $pendingMaps,
            'up_to_date' => $pendingFiles === 0 && !$enums && $pendingMaps === 0,
        ];
    }

    /** طبّق كل الناقص */
    public function apply(): array
    {
        @set_time_limit(300);
        $report = ['files' => [], 'enums' => [], 'data' => [], 'errors' => 0];
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->ensureTable();
        $done = $this->applied();

        foreach ($this->files() as $f) {
            $name = basename($f);
            if (isset($done[$name])) {
                continue;
            }
            $sql = (string) file_get_contents($f);
            $ok = 0;
            $skipped = 0;
            $failed = [];
            foreach ($this->split($sql) as $stmt) {
                try {
                    // MySQL (عكس MariaDB) مابيدعمش ADD COLUMN/INDEX IF NOT EXISTS - بنفحص بنفسنا
                    $stmt = $this->mysqlCompat($stmt);
                    if ($stmt === null) {
                        $skipped++;
                        continue;
                    }
                    $st = $this->pdo->query($stmt);
                    if ($st instanceof PDOStatement) {
                        do {
                            if ($st->columnCount() > 0) {
                                $st->fetchAll();
                            }
                        } while ($st->nextRowset());
                        $st->closeCursor();
                    }
                    $ok++;
                } catch (PDOException $e) {
                    $code = (int) ($e->errorInfo[1] ?? 0);
                    if (in_array($code, self::IGNORABLE, true) || ($code === 1005 && strpos((string) ($e->errorInfo[2] ?? ''), 'errno: 121') !== false)) {
                        $skipped++;
                        continue;
                    }
                    // جدول مش موجود أصلًا (ميزة مش مستخدمة على السيرفر ده) - تحذير مش فشل
                    $failed[] = ['code' => $code, 'error' => mb_substr((string) ($e->errorInfo[2] ?? $e->getMessage()), 0, 200), 'sql' => mb_substr(preg_replace('/\s+/', ' ', $stmt), 0, 140)];
                }
            }
            // بنسجّل الملف حتى لو فيه جمل فشلت بسبب جداول مش موجودة (1146) عشان مايتعادش كل مرة؛
            // أي فشل تاني بيخلي الملف يفضل "ناقص" عشان تشوفه وتعيد بعد ما تصلّحه.
            $hard = array_filter($failed, fn($x) => $x['code'] !== 1146 && $x['code'] !== 1054);
            if (!$hard) {
                $ins = $this->pdo->prepare('INSERT INTO schema_migrations (migration, checksum) VALUES (?, ?) ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), applied_at = CURRENT_TIMESTAMP');
                $ins->execute([$name, hash('sha256', $sql)]);
            }
            $report['errors'] += count($hard);
            $report['files'][] = ['file' => $name, 'ok' => $ok, 'already' => $skipped, 'warnings' => count($failed) - count($hard), 'failed' => array_values($hard)];
        }

        foreach (self::ENUMS as [$t, $c, $need, $default]) {
            $r = $this->widenEnum($t, $c, $need, $default);
            if ($r !== null) {
                $report['enums'][] = $r;
                if (!empty($r['error'])) {
                    $report['errors']++;
                }
            }
        }

        foreach (self::DATA_MAPS as [$t, $c, $from, $to]) {
            $cur = $this->enumValues($t, $c);
            if ($cur === null || !in_array($from, $cur, true) || !in_array($to, $cur, true)) {
                continue;
            }
            try {
                $n = $this->pdo->exec("UPDATE `{$t}` SET `{$c}` = " . $this->pdo->quote($to) . " WHERE `{$c}` = " . $this->pdo->quote($from));
                if ($n) {
                    $report['data'][] = ['table' => $t, 'column' => $c, 'from' => $from, 'to' => $to, 'rows' => (int) $n];
                }
            } catch (Throwable $e) {
                $report['errors']++;
            }
        }

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        Logger::info('SchemaUpdater applied', ['files' => count($report['files']), 'enums' => count($report['enums']), 'errors' => $report['errors']]);
        return $report;
    }

    // ------------------------------------------------------------------

    /**
     * MySQL (عكس MariaDB) مابيدعمش IF NOT EXISTS في ADD COLUMN / ADD INDEX / CREATE INDEX.
     * بنفحص بنفسنا: العمود/الفهرس الموجود بيتشال من الجملة، والباقي بيتنفّذ من غير IF NOT EXISTS.
     * بيدعم ALTER فيه أكتر من إضافة (ADD ..., ADD ...) — قبل كده كانت أول إضافة بس اللي بتتحوّل
     * والباقي بيفشل بـ syntax error. بيرجّع null لو مفيش حاجة تتنفّذ.
     */
    private function mysqlCompat(string $stmt): ?string
    {
        return self::rewriteForMysql(
            $stmt,
            fn (string $table, string $column): bool => (bool) $this->column($table, $column),
            function (string $table, string $index): bool {
                $q = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
                $q->execute([$table, $index]);
                return (int) $q->fetchColumn() > 0;
            }
        );
    }

    /**
     * منطق خالص (قابل للاختبار من غير قاعدة بيانات).
     * @param callable(string,string):bool $columnExists
     * @param callable(string,string):bool $indexExists
     */
    public static function rewriteForMysql(string $stmt, callable $columnExists, callable $indexExists): ?string
    {
        // CREATE [UNIQUE] INDEX IF NOT EXISTS i ON t (...)
        if (preg_match('/^\s*CREATE\s+(UNIQUE\s+)?INDEX\s+IF\s+NOT\s+EXISTS\s+`?(\w+)`?\s+ON\s+`?(\w+)`?\s*(\(.*)$/is', $stmt, $m)) {
            if ($indexExists($m[3], $m[2])) {
                return null;
            }
            return 'CREATE ' . ($m[1] ? 'UNIQUE ' : '') . 'INDEX `' . $m[2] . '` ON `' . $m[3] . '` ' . $m[4];
        }
        if (!preg_match('/^\s*ALTER\s+TABLE\s+`?(\w+)`?\s+(.*)$/is', $stmt, $m) || !preg_match('/IF\s+NOT\s+EXISTS/i', $m[2])) {
            return $stmt;
        }
        $table = $m[1];
        $kept = [];
        foreach (self::splitTopLevel($m[2]) as $clause) {
            if (preg_match('/^ADD\s+(?:COLUMN\s+)?IF\s+NOT\s+EXISTS\s+`?(\w+)`?(\s.*)$/is', $clause, $c)) {
                if (!$columnExists($table, $c[1])) {
                    $kept[] = 'ADD COLUMN `' . $c[1] . '`' . $c[2];
                }
                continue;
            }
            if (preg_match('/^ADD\s+(UNIQUE\s+)?(?:INDEX|KEY)\s+IF\s+NOT\s+EXISTS\s+`?(\w+)`?(\s*\(.*)$/is', $clause, $c)) {
                if (!$indexExists($table, $c[2])) {
                    $kept[] = 'ADD ' . ($c[1] ? 'UNIQUE ' : '') . 'INDEX `' . $c[2] . '`' . $c[3];
                }
                continue;
            }
            $kept[] = $clause;
        }
        return $kept ? 'ALTER TABLE `' . $table . '` ' . implode(', ', $kept) : null;
    }

    /** تقسيم على الفواصل في المستوى الأعلى بس (مش جوه أقواس زي DECIMAL(12,2) ولا جوه نصوص/تعليقات) */
    public static function splitTopLevel(string $body): array
    {
        $parts = [];
        $buf = '';
        $depth = 0;
        $quote = null;
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];
            if ($quote !== null) {
                $buf .= $ch;
                if ($ch === '\\' && $i + 1 < $len) {
                    $buf .= $body[++$i];
                } elseif ($ch === $quote) {
                    if (($body[$i + 1] ?? '') === $quote) {
                        $buf .= $body[++$i];
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
            } elseif ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($ch === ',' && $depth === 0) {
                $parts[] = trim($buf);
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if (trim($buf) !== '') {
            $parts[] = trim($buf);
        }
        return array_values(array_filter($parts, fn ($p) => $p !== ''));
    }

    private function files(): array
    {
        $out = [];
        foreach (glob($this->dir . '/*.sql') ?: [] as $f) {
            $n = basename($f);
            if ($n[0] !== '_' && strcmp($n, self::BASELINE) > 0) {
                $out[] = $f;
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    private function ensureTable(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `schema_migrations` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `migration` VARCHAR(255) NOT NULL,
            `checksum` CHAR(64) NOT NULL,
            `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_schema_migrations_migration` (`migration`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function applied(): array
    {
        try {
            $rows = $this->pdo->query('SELECT migration, applied_at FROM schema_migrations')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[$r['migration']] = $r;
        }
        return $out;
    }

    /** @return string[]|null قيم الـ ENUM الحالية، أو null لو مش موجود/مش ENUM */
    private function enumValues(string $table, string $column): ?array
    {
        $col = $this->column($table, $column);
        if (!$col || stripos((string) $col['COLUMN_TYPE'], 'enum(') !== 0) {
            return null;
        }
        preg_match_all("/'((?:[^'\\\\]|\\\\.|'')*)'/", (string) $col['COLUMN_TYPE'], $m);
        return array_map(fn($v) => str_replace("''", "'", $v), $m[1]);
    }

    private function column(string $table, string $column): ?array
    {
        $st = $this->pdo->prepare('SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $st->execute([$table, $column]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function widenEnum(string $table, string $column, array $need, ?string $default): ?array
    {
        $col = $this->column($table, $column);
        $cur = $this->enumValues($table, $column);
        if (!$col || $cur === null) {
            return null;
        }
        $missing = array_values(array_diff($need, $cur));
        $curDefault = $col['COLUMN_DEFAULT'];
        if ($curDefault !== null) {
            $curDefault = trim((string) $curDefault, "'");
            if (strtoupper($curDefault) === 'NULL') {
                $curDefault = null;
            }
        }
        $newDefault = $default !== null ? $default : $curDefault;
        if (!$missing && $newDefault === $curDefault) {
            return null;
        }
        $all = array_merge($cur, $missing); // القيم القديمة بنفس ترتيبها + الجديدة في الآخر
        $nullable = $col['IS_NULLABLE'] === 'YES';
        $sql = "ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` ENUM(" . implode(',', array_map([$this->pdo, 'quote'], $all)) . ')'
            . ($nullable ? ' NULL' : ' NOT NULL')
            . ($newDefault !== null ? ' DEFAULT ' . $this->pdo->quote($newDefault) : ($nullable ? ' DEFAULT NULL' : ''))
            . ($col['COLUMN_COMMENT'] !== '' ? ' COMMENT ' . $this->pdo->quote($col['COLUMN_COMMENT']) : '');
        try {
            $this->pdo->exec($sql);
            return ['table' => $table, 'column' => $column, 'added' => $missing, 'default' => $newDefault];
        } catch (Throwable $e) {
            return ['table' => $table, 'column' => $column, 'added' => [], 'error' => mb_substr($e->getMessage(), 0, 200)];
        }
    }

    /** تقسيم ملف SQL لجمل (بيحترم النصوص والتعليقات) */
    private function split(string $sql): array
    {
        $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
        $out = [];
        $buf = '';
        $len = strlen($sql);
        $q = null;
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $n = $sql[$i + 1] ?? '';
            if ($q !== null) {
                $buf .= $c;
                if ($c === '\\' && $i + 1 < $len) {
                    $buf .= $sql[++$i];
                    continue;
                }
                if ($c === $q) {
                    if ($n === $q) {
                        $buf .= $sql[++$i];
                    } else {
                        $q = null;
                    }
                }
                continue;
            }
            if ($c === '#' || ($c === '-' && $n === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2])))) {
                $nl = strpos($sql, "\n", $i);
                $i = $nl === false ? $len : $nl;
                $buf .= "\n";
                continue;
            }
            if ($c === '/' && $n === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 1;
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $q = $c;
                $buf .= $c;
                continue;
            }
            if ($c === ';') {
                if (trim($buf) !== '') {
                    $out[] = trim($buf);
                }
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }
}
