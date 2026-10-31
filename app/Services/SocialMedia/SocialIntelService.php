<?php

/**
 * Tourfecto - ذكاء السوشيال: كل فحص بيفيد المنصة بدون ما يكلّف مرتين.
 *
 *  1) سجل الفحص العام (social_check_log): لينكات الصفحات العامة (هاندلز بس، مفيش IP ولا بيانات شخصية) - مين مهتم، ومين كمّل وسجّل، وإيه اللي بيغلّطهم.
 *  2) دليل المنافسين للسوق (social_market_directory): كل منافس حقيقي اتلقى لعميل بيتحفظ بصفحاته (فيسبوك/إنستجرام) حسب السوق واللغة،
 *     فالعميل الجاي في نفس السوق بياخد اقتراحات من غير ما ندفع لجوجل تاني، وبيكبر كقاعدة بيانات للمنصة.
 *  3) سجل تشغيلات البحث (social_finder_runs): المصدر والعدد والتكلفة الفعلية (من رد المزوّد) عشان الأدمن يشوف الإنفاق ونسبة الكاش، وسقف شهري.
 *  4) insights(): ملخص للأدمن (أعلى المنافسين تكرارًا، أكتر أسباب فشل متابعة المنافسين، آخر المهتمين).
 */
final class SocialIntelService
{
    private Database $db;
    private static bool $ready = false;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        if (!self::$ready) {
            $pdo = $this->db->getConnection();
            $pdo->exec("CREATE TABLE IF NOT EXISTS social_check_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                lang VARCHAR(2) NOT NULL DEFAULT 'en',
                fb_handle VARCHAR(100) NULL,
                ig_handle VARCHAR(40) NULL,
                competitors TEXT NULL,
                n_comp TINYINT NOT NULL DEFAULT 0,
                errors VARCHAR(200) NULL,
                converted TINYINT(1) NOT NULL DEFAULT 0,
                converted_at DATETIME NULL,
                KEY idx_created (created_at),
                KEY idx_fb (fb_handle),
                KEY idx_ig (ig_handle)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS social_market_directory (
                id INT AUTO_INCREMENT PRIMARY KEY,
                market_key VARCHAR(16) NOT NULL,
                domain VARCHAR(190) NOT NULL,
                name VARCHAR(255) NULL,
                facebook_handle VARCHAR(100) NULL,
                instagram_handle VARCHAR(40) NULL,
                seen_count INT NOT NULL DEFAULT 1,
                first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_market_domain (market_key, domain),
                KEY idx_market_seen (market_key, seen_count)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS social_finder_runs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                user_id INT NOT NULL,
                source VARCHAR(24) NOT NULL,
                found INT NOT NULL DEFAULT 0,
                cost_usd DECIMAL(8,4) NOT NULL DEFAULT 0,
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            self::$ready = true;
        }
    }

    /** مفتاح السوق: أول دولة مستهدفة + لغة (EG-ar) */
    public static function marketKey(array $countries, string $language): string
    {
        $c = strtoupper(substr((string) ($countries[0] ?? 'XX'), 0, 2));
        return (preg_match('/^[A-Z]{2}$/', $c) ? $c : 'XX') . '-' . (preg_match('/^[a-z]{2}$/', $language) ? $language : 'en');
    }

    // ------------------------------------------------------------------ الفحص العام

    /** @param list<string> $competitors "platform:handle" */
    public function logCheck(string $lang, ?string $fb, ?string $ig, array $competitors, array $errorKeys = []): void
    {
        try {
            $this->db->query('INSERT INTO social_check_log (lang, fb_handle, ig_handle, competitors, n_comp, errors) VALUES (?, ?, ?, ?, ?, ?)', [
                $lang === 'ar' ? 'ar' : 'en', $fb ? mb_substr($fb, 0, 100) : null, $ig ? mb_substr($ig, 0, 40) : null,
                $competitors ? json_encode(array_slice($competitors, 0, 3), JSON_UNESCAPED_UNICODE) : null, count($competitors),
                $errorKeys ? mb_substr(implode(',', array_unique(array_map(static fn ($k) => preg_replace('/_\d+$/', '', (string) $k), $errorKeys))), 0, 200) : null,
            ]);
        } catch (Throwable $e) {
            Logger::warning('Social check log failed', ['message' => $e->getMessage()]);
        }
    }

    /** العميل سجّل ونفّذ التحليل: نعلّم أحدث فحص بنفس الصفحة (خلال 14 يوم) إنه كمّل */
    public function markConverted(?string $fb, ?string $ig): void
    {
        $fb = $fb ? mb_substr($fb, 0, 100) : null;
        $ig = $ig ? mb_substr($ig, 0, 40) : null;
        if (!$fb && !$ig) {
            return;
        }
        try {
            $this->db->query('UPDATE social_check_log SET converted = 1, converted_at = NOW() WHERE converted = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 14 DAY) AND ((? IS NOT NULL AND fb_handle = ?) OR (? IS NOT NULL AND ig_handle = ?))', [$fb, $fb, $ig, $ig]);
        } catch (Throwable $e) {
            Logger::warning('Social check convert failed', ['message' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------ دليل السوق

    /** @param list<array{domain:string,name?:?string,facebook?:?string,instagram?:?string}> $rows */
    public function recordCompetitors(string $market, array $rows): void
    {
        foreach ($rows as $r) {
            $d = strtolower(trim((string) ($r['domain'] ?? '')));
            if ($d === '' || !preg_match('/^[a-z0-9.\-]{3,190}$/', $d)) {
                continue;
            }
            $fb = !empty($r['facebook']) ? mb_substr((string) $r['facebook'], 0, 100) : null;
            $ig = !empty($r['instagram']) ? mb_substr((string) $r['instagram'], 0, 40) : null;
            try {
                $this->db->query('INSERT INTO social_market_directory (market_key, domain, name, facebook_handle, instagram_handle) VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE seen_count = seen_count + 1, last_seen = NOW(), name = COALESCE(VALUES(name), name),
                    facebook_handle = COALESCE(VALUES(facebook_handle), facebook_handle), instagram_handle = COALESCE(VALUES(instagram_handle), instagram_handle)',
                    [$market, $d, !empty($r['name']) ? mb_substr((string) $r['name'], 0, 255) : null, $fb, $ig]);
            } catch (Throwable $e) {
                Logger::warning('Social directory upsert failed', ['message' => $e->getMessage()]);
            }
        }
    }

    /** @return list<array{domain:string,name:?string,seen:int}> شركات ظهرت كمنافسين لأكتر من عميل في نفس السوق */
    public function directory(string $market, array $exclude = [], int $limit = 6, int $minSeen = 2): array
    {
        try {
            $rows = $this->db->query('SELECT domain, name, seen_count FROM social_market_directory WHERE market_key = ? AND seen_count >= ? ORDER BY seen_count DESC, last_seen DESC LIMIT 40', [$market, $minSeen]) ?: [];
        } catch (Throwable $e) {
            return [];
        }
        $ex = array_flip(array_map('strtolower', $exclude));
        $out = [];
        foreach ($rows as $r) {
            if (isset($ex[strtolower((string) $r['domain'])])) {
                continue;
            }
            $out[] = ['domain' => (string) $r['domain'], 'name' => $r['name'] !== null ? (string) $r['name'] : null, 'seen' => (int) $r['seen_count']];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ تكلفة البحث

    public function logRun(int $userId, string $source, int $found, float $costUsd): void
    {
        try {
            $this->db->query('INSERT INTO social_finder_runs (user_id, source, found, cost_usd) VALUES (?, ?, ?, ?)', [$userId, mb_substr($source, 0, 24), $found, round(max(0, $costUsd), 4)]);
        } catch (Throwable $e) {
            Logger::warning('Social finder run log failed', ['message' => $e->getMessage()]);
        }
    }

    public function spentThisMonth(): float
    {
        try {
            return (float) ($this->db->query("SELECT COALESCE(SUM(cost_usd), 0) AS s FROM social_finder_runs WHERE created_at >= DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01')")[0]['s'] ?? 0);
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    /** السقف الشهري للبحث المدفوع: -1 = من غير سقف، 0 = البحث المدفوع مقفول */
    public static function budget(): float
    {
        return (float) SocialPackages::config()['finder_budget_usd'];
    }

    public function budgetAllows(): bool
    {
        $b = self::budget();
        return $b < 0 || ($b > 0 && $this->spentThisMonth() < $b);
    }

    // ------------------------------------------------------------------ للأدمن

    public function insights(): array
    {
        $q = fn (string $sql, array $p = []) => $this->db->query($sql, $p) ?: [];
        $out = ['checks_30d' => 0, 'converted_30d' => 0, 'top_errors' => [], 'recent' => [], 'runs_30d' => 0, 'by_source' => [], 'cost_30d' => 0.0, 'spent_month' => 0.0, 'budget' => self::budget(),
            'cache_hit_pct' => null, 'directory_size' => 0, 'directory_top' => [], 'blockers' => []];
        try {
            $c = $q("SELECT COUNT(*) AS n, COALESCE(SUM(converted), 0) AS c FROM social_check_log WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)")[0] ?? [];
            $out['checks_30d'] = (int) ($c['n'] ?? 0);
            $out['converted_30d'] = (int) ($c['c'] ?? 0);
            $errs = [];
            foreach ($q("SELECT errors FROM social_check_log WHERE errors IS NOT NULL AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)") as $r) {
                foreach (explode(',', (string) $r['errors']) as $k) {
                    if ($k !== '') {
                        $errs[$k] = ($errs[$k] ?? 0) + 1;
                    }
                }
            }
            arsort($errs);
            $out['top_errors'] = array_slice($errs, 0, 5, true);
            $out['recent'] = array_map(static fn ($r) => ['fb' => $r['fb_handle'], 'ig' => $r['ig_handle'], 'n_comp' => (int) $r['n_comp'], 'at' => $r['created_at'], 'converted' => (bool) $r['converted']],
                $q('SELECT fb_handle, ig_handle, n_comp, created_at, converted FROM social_check_log WHERE fb_handle IS NOT NULL OR ig_handle IS NOT NULL ORDER BY id DESC LIMIT 15'));
            $runs = $q("SELECT source, COUNT(*) AS n, COALESCE(SUM(cost_usd), 0) AS cost FROM social_finder_runs WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY source");
            $total = 0;
            $free = 0;
            foreach ($runs as $r) {
                $out['by_source'][$r['source']] = (int) $r['n'];
                $out['cost_30d'] += (float) $r['cost'];
                $total += (int) $r['n'];
                if ((float) $r['cost'] == 0.0) {
                    $free += (int) $r['n'];
                }
            }
            $out['runs_30d'] = $total;
            $out['cost_30d'] = round($out['cost_30d'], 2);
            $out['cache_hit_pct'] = $total > 0 ? (int) round($free / $total * 100) : null;
            $out['spent_month'] = round($this->spentThisMonth(), 2);
            $out['directory_size'] = (int) ($q('SELECT COUNT(*) AS n FROM social_market_directory')[0]['n'] ?? 0);
            $out['directory_top'] = array_map(static fn ($r) => ['domain' => $r['domain'], 'market' => $r['market_key'], 'seen' => (int) $r['seen_count']],
                $q('SELECT domain, market_key, seen_count FROM social_market_directory ORDER BY seen_count DESC, last_seen DESC LIMIT 8'));
            $b = [];
            foreach ($q("SELECT LEFT(track_error, 70) AS e, COUNT(*) AS n FROM social_competitor_pages WHERE track_error IS NOT NULL GROUP BY LEFT(track_error, 70) ORDER BY n DESC LIMIT 5") as $r) {
                $b[(string) $r['e']] = (int) $r['n'];
            }
            $out['blockers'] = $b;
        } catch (Throwable $e) {
            Logger::warning('Social intel insights failed', ['message' => $e->getMessage()]);
        }
        return $out;
    }
}
