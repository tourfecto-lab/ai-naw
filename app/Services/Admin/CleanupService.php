<?php

/**
 * Tourfecto - تنظيف المنصة من لوحة الأدمن (صحة المنصة ← تنظيف المنصة)
 *
 * فيه 3 أنواع:
 *  - ملفات السيرفر: لوجات كبيرة/قديمة، كاش قديم، جلسات منتهية.
 *  - قاعدة البيانات: توكنات منتهية وسجلات تشغيل قديمة (حسب مدة احتفاظ ثابتة).
 *    مابنلمسش أي بيانات عملاء أو فلوس: الفواتير، المحفظة، سجل التدقيق، الحجوزات، التقييمات... كلها بتفضل.
 *  - ملفات زيادة في المشروع: نسخ مكررة قديمة (CONTRO~1، app/app ...) وملفات SQL يدوية خلاص اتجمعت.
 *    دي مابتتمسحش - بتتنقل لـ storage/quarantine/<التاريخ>/ بنفس مسارها، وتقدر ترجّعها لو احتجتها.
 *    بعد 14 يوم تقدر تمسح الحجر نهائي.
 *
 * كل حاجة بتتعرض الأول (عدد + حجم) ومابيتنفذش غير اللي الأدمن يختاره.
 */
class CleanupService
{
    private const QUARANTINE_DAYS = 14;
    private const LOG_MAX_BYTES = 10485760;   // لوج أكبر من 10MB بيتقص
    private const LOG_KEEP_BYTES = 2097152;   // ونسيب آخر 2MB منه
    private const SCAN_LIMIT = 200000;         // أقصى عدد ملفات نفحصه في الكاش/الجلسات

    /** فولدرات المشروع الأساسية - عمرها ما تتلمس */
    private const KEEP_ROOT = ['app', 'public_html', 'public', 'cron', 'database', 'storage', 'vendor', 'tests', 'tools',
        'scripts', 'docs', 'deploy', 'maintenance', '.well-known', '.git', 'node_modules'];
    private const KEEP_ROOT_FILES = ['.env', '.env.example', '.htaccess', '.gitignore', '.user.ini', 'composer.json', 'composer.lock',
        'bootstrap.php', 'phpstan-bootstrap.php', 'phpstan.neon', 'phpunit.xml', 'phpunit.xml.dist', 'pint.json', 'index.php'];

    private string $root;
    private string $storage;
    private Database $db;

    public function __construct()
    {
        $this->root = rtrim(defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 3), '/');
        $this->storage = rtrim(defined('TOURFECTO_STORAGE') ? TOURFECTO_STORAGE : $this->root . '/storage', '/');
        $this->db = Database::getInstance();
    }

    // ================================================================== التعريفات

    /** قواعد قاعدة البيانات: [key, table, where, أعمدة لازمة, label, شرح] */
    private function dbRules(): array
    {
        return [
            ['db_reset_tokens', 'password_reset_tokens', '(expires_at < NOW() - INTERVAL 1 DAY OR used_at < NOW() - INTERVAL 1 DAY)', ['expires_at', 'used_at'],
                'روابط استعادة الباسورد المنتهية', 'روابط اتستخدمت أو صلاحيتها خلصت'],
            ['db_verify_tokens', 'email_verification_tokens', 'created_at < NOW() - INTERVAL 7 DAY AND user_id IN (SELECT id FROM users WHERE email_verified_at IS NOT NULL)', ['created_at', 'user_id'],
                'روابط تأكيد البريد القديمة', 'لعملاء أكّدوا بريدهم خلاص'],
            ['db_refresh_tokens', 'user_refresh_tokens', '(expires_at < NOW() OR revoked_at < NOW() - INTERVAL 7 DAY)', ['expires_at', 'revoked_at'],
                'جلسات دخول منتهية', 'توكنات "افتكرني" اللي انتهت أو اتلغت'],
            ['db_csrf_tokens', 'csrf_tokens', 'expires_at < NOW()', ['expires_at'], 'توكنات CSRF منتهية', ''],
            ['db_rate_blocks', 'rate_limit_blocks', 'expires_at < NOW() - INTERVAL 1 DAY', ['expires_at'], 'حظر مؤقت منتهي', 'عناوين IP كانت محظورة والحظر خلص'],
            ['db_jobs_done', 'jobs', "status = 'completed' AND created_at < NOW() - INTERVAL 14 DAY", ['status', 'created_at'],
                'مهام الطابور اللي خلصت', 'أقدم من 14 يوم'],
            ['db_jobs_failed', 'jobs', "status = 'failed' AND created_at < NOW() - INTERVAL 30 DAY", ['status', 'created_at'],
                'مهام الطابور الفاشلة القديمة', 'أقدم من 30 يوم'],
            ['db_notifications', 'notifications', 'read_at IS NOT NULL AND read_at < NOW() - INTERVAL 90 DAY', ['read_at'],
                'إشعارات اتقرت', 'أقدم من 90 يوم'],
            ['db_visitor_logs', 'visitor_logs', 'created_at < NOW() - INTERVAL 90 DAY', ['created_at'], 'سجل زوار الموقع', 'أقدم من 90 يوم'],
            ['db_activity_logs', 'activity_logs', 'created_at < NOW() - INTERVAL 180 DAY', ['created_at'], 'سجل النشاط', 'أقدم من 6 شهور'],
            ['db_login_history', 'login_history', 'created_at < NOW() - INTERVAL 180 DAY', ['created_at'], 'سجل محاولات الدخول', 'أقدم من 6 شهور'],
            ['db_api_usage', 'api_usage_logs', 'created_at < NOW() - INTERVAL 180 DAY', ['created_at'], 'سجل طلبات الـ APIs', 'أقدم من 6 شهور (تكلفة AI بتتحسب من آخر 30 يوم)'],
            ['db_ai_usage', 'ai_usage_logs', 'created_at < NOW() - INTERVAL 180 DAY', ['created_at'], 'سجل استهلاك AI التفصيلي', 'أقدم من 6 شهور'],
            ['db_gbp_sync', 'gbp_sync_logs', 'created_at < NOW() - INTERVAL 90 DAY', ['created_at'], 'سجل مزامنة Google Business', 'أقدم من 90 يوم'],
            ['db_ai_webhooks', 'ai_webhook_events', 'received_at < NOW() - INTERVAL 30 DAY', ['received_at'], 'أحداث Webhook الشات', 'أقدم من 30 يوم'],
            ['db_gbp_cache', 'gbp_insights_cache', 'fetched_at < NOW() - INTERVAL 7 DAY', ['fetched_at'], 'كاش إحصائيات Google Business', 'أقدم من 7 أيام (بيتجاب تاني لوحده)'],
            ['db_alerts', 'admin_alerts', "status = 'resolved' AND resolved_at < NOW() - INTERVAL 90 DAY", ['status', 'resolved_at'], 'تنبيهات أدمن اتقفلت', 'أقدم من 90 يوم'],
            ['db_mail_body', 'mail_log', 'body_html IS NOT NULL AND created_at < NOW() - INTERVAL 14 DAY', ['body_html', 'created_at'],
                'محتوى الإيميلات القديمة في سجل البريد', 'بنشيل نص الرسالة بس (السجل نفسه بيفضل)', 'UPDATE `mail_log` SET body_html = NULL WHERE %s'],
        ];
    }

    // ================================================================== المعاينة

    public function preview(): array
    {
        $items = [];
        $items[] = $this->item('files', 'logs_scrub', 'شيل الباسوردات والتوكنات من اللوجات', 'مابيمسحش حاجة - بيستبدلها بـ [REDACTED]', $this->logFiles(), true);
        $items[] = $this->item('files', 'logs_trim', 'لوجات كبيرة أو قديمة', 'لوج أكبر من 10MB بيتقص لآخر 2MB، واللوجات المؤرشفة (.log.1 / .gz) الأقدم من 14 يوم بتتمسح', $this->bigLogs(), true);
        $items[] = $this->item('files', 'cache', 'كاش منتهي', 'ملفات storage/cache اللي صلاحيتها خلصت (مابتتقريش تاني أصلًا)', $this->expiredCache(), true);
        $items[] = $this->item('files', 'sessions', 'جلسات منتهية', 'ملفات جلسات مالمسهاش حد من 7 أيام', $this->oldFiles($this->storage . '/sessions', 7, 'sess_'), true);

        foreach ($this->dbRules() as $r) {
            [$key, $table, $where, $cols, $label, $desc] = $r;
            if (!$this->hasColumns($table, $cols)) {
                continue;
            }
            try {
                $n = (int) ($this->db->query("SELECT COUNT(*) AS c FROM `{$table}` WHERE {$where}")[0]['c'] ?? 0);
            } catch (Throwable $e) {
                continue;
            }
            $items[] = ['group' => 'db', 'key' => $key, 'label' => $label, 'desc' => $desc . ' · جدول ' . $table, 'count' => $n, 'bytes' => null, 'default' => true, 'details' => []];
        }

        $junk = $this->junkPaths();
        $items[] = $this->item('code', 'junk_code', 'ملفات ونسخ زيادة في المشروع', 'نسخ قديمة مكررة وملفات SQL يدوية خلاص اتجمعت. بتتنقل للحجر (storage/quarantine) مش بتتمسح - تقدر ترجّعها', $junk, true);
        $items[] = $this->item('code', 'quarantine_purge', 'مسح الحجر القديم نهائيًا', 'ملفات اتنقلت للحجر من أكتر من ' . self::QUARANTINE_DAYS . ' يوم', $this->oldQuarantine(), false);

        $items = array_values(array_filter($items, fn($i) => $i['count'] > 0));
        return [
            'items' => $items,
            'tables' => $this->biggestTables(),
            'disk' => $this->disk(),
        ];
    }

    // ================================================================== التنفيذ

    public function run(array $keys): array
    {
        @set_time_limit(300);
        $keys = array_flip($keys);
        $out = [];
        $freed = 0;

        if (isset($keys['logs_scrub'])) {
            if (!class_exists('LogRedactor')) {
                require_once APP_PATH . '/Core/LogRedactor.php';
            }
            $n = 0;
            foreach ($this->logFiles()['paths'] as $f) {
                if (filesize($f) <= 200 * 1048576) {
                    $n += LogRedactor::scrubFile($f);
                }
            }
            $out[] = ['key' => 'logs_scrub', 'message' => $n . ' سطر اتنضف'];
        }
        if (isset($keys['logs_trim'])) {
            $before = $this->bigLogs()['bytes'];
            $done = 0;
            foreach ($this->bigLogs()['paths'] as $f) {
                if (preg_match('/\.log$/', $f) && filesize($f) > self::LOG_MAX_BYTES) {
                    $done += $this->trimFile($f) ? 1 : 0;
                } elseif (@unlink($f)) {
                    $done++;
                }
            }
            clearstatcache();
            $saved = max(0, $before - $this->bigLogs()['bytes']);
            $freed += $saved;
            $out[] = ['key' => 'logs_trim', 'message' => $done . ' ملف · وفّرنا ' . $this->human($saved)];
        }
        foreach (['cache', 'sessions'] as $k) {
            if (!isset($keys[$k])) {
                continue;
            }
            $dir = $this->storage . '/' . $k;
            $r = $k === 'cache' ? $this->expiredCache() : $this->oldFiles($dir, 7, 'sess_');
            $n = 0;
            foreach ($r['paths'] as $f) {
                $n += @unlink($f) ? 1 : 0;
            }
            if ($k === 'cache') {
                $this->removeEmptyDirs($dir);
            }
            $freed += $r['bytes'];
            $out[] = ['key' => $k, 'message' => $n . ' ملف اتمسح · ' . $this->human($r['bytes'])];
        }

        foreach ($this->dbRules() as $r) {
            [$key, $table, $where, $cols] = $r;
            if (!isset($keys[$key]) || !$this->hasColumns($table, $cols)) {
                continue;
            }
            $total = 0;
            $err = null;
            $start = microtime(true);
            try {
                if (!empty($r[6])) {
                    $total = $this->affected(sprintf($r[6], $where));
                } else {
                    // على دفعات عشان الجدول مايتقفلش وقت طويل
                    do {
                        $n = $this->affected("DELETE FROM `{$table}` WHERE {$where} LIMIT 5000");
                        $total += $n;
                    } while ($n === 5000 && microtime(true) - $start < 60);
                }
            } catch (Throwable $e) {
                $err = mb_substr($e->getMessage(), 0, 160);
            }
            $out[] = ['key' => $key, 'message' => $err ? 'فشل: ' . $err : $total . ' صف', 'error' => (bool) $err];
        }

        if (isset($keys['junk_code'])) {
            $dest = $this->storage . '/quarantine/' . date('Y-m-d_His');
            $moved = 0;
            $failed = [];
            foreach ($this->junkPaths()['paths'] as $p) {
                $rel = ltrim(substr($p, strlen($this->root)), '/');
                $target = $dest . '/' . $rel;
                @mkdir(dirname($target), 0755, true);
                if (@rename($p, $target)) {
                    $moved++;
                } else {
                    $failed[] = $rel;
                }
            }
            if ($moved) {
                @file_put_contents($dest . '/README.txt', "الملفات دي اتنقلت من تنظيف لوحة الأدمن يوم " . date('Y-m-d H:i') . ".\nلو احتجت أي حاجة منها رجّعها لنفس مسارها جوه فولدر المشروع.\n");
            }
            $out[] = ['key' => 'junk_code', 'message' => $moved . ' اتنقلوا للحجر' . ($failed ? ' · مقدرناش ننقل: ' . implode('، ', array_slice($failed, 0, 5)) . ' (صلاحيات - انقلهم يدوي)' : ''), 'error' => (bool) $failed];
        }
        if (isset($keys['quarantine_purge'])) {
            $r = $this->oldQuarantine();
            foreach ($r['paths'] as $d) {
                $this->rrmdir($d);
            }
            $freed += $r['bytes'];
            $out[] = ['key' => 'quarantine_purge', 'message' => count($r['paths']) . ' فولدر اتمسح · ' . $this->human($r['bytes'])];
        }

        Logger::info('Admin cleanup', ['keys' => array_keys($keys), 'freed' => $freed]);
        return ['results' => $out, 'freed_bytes' => $freed, 'freed' => $this->human($freed)];
    }

    // ================================================================== ملفات

    private function logFiles(): array
    {
        $paths = array_merge(glob($this->storage . '/logs/*.log') ?: [], glob($this->storage . '/logs/cron/*.log') ?: []);
        return $this->summarize($paths);
    }

    private function bigLogs(): array
    {
        $paths = [];
        foreach (array_merge(glob($this->storage . '/logs/*') ?: [], glob($this->storage . '/logs/cron/*') ?: []) as $f) {
            if (!is_file($f)) {
                continue;
            }
            $b = basename($f);
            if (preg_match('/\.log$/', $b)) {
                if (filesize($f) > self::LOG_MAX_BYTES) {
                    $paths[] = $f;
                }
            } elseif (preg_match('/\.log\.\d+$|\.log\.gz$|\.gz$|\.old$|\.bak$|\.scrub\.\d+$/', $b) && filemtime($f) < time() - 14 * 86400) {
                $paths[] = $f;
            } elseif (preg_match('/\.scrub\.\d+$/', $b) && filemtime($f) < time() - 3600) {
                $paths[] = $f;
            }
        }
        $s = $this->summarize($paths);
        // الحجم اللي هيتوفر فعلًا (اللوج الكبير بنسيب منه 2MB)
        $s['bytes'] = 0;
        foreach ($paths as $f) {
            $sz = (int) @filesize($f);
            $s['bytes'] += preg_match('/\.log$/', $f) ? max(0, $sz - self::LOG_KEEP_BYTES) : $sz;
        }
        return $s;
    }

    private function trimFile(string $f): bool
    {
        $size = filesize($f);
        $h = @fopen($f, 'rb');
        if (!$h) {
            return false;
        }
        fseek($h, max(0, $size - self::LOG_KEEP_BYTES));
        $tail = stream_get_contents($h);
        fclose($h);
        $nl = strpos((string) $tail, "\n");
        if ($nl !== false) {
            $tail = substr($tail, $nl + 1); // نبدأ من أول سطر كامل
        }
        return @file_put_contents($f, '[' . date('Y-m-d H:i:s') . "] [info] --- اللوج اتقص من لوحة الأدمن (كان " . $this->human($size) . ") ---\n" . $tail, LOCK_EX) !== false;
    }

    private function oldFiles(string $dir, int $days, string $prefix = ''): array
    {
        $paths = [];
        if (!is_dir($dir)) {
            return $this->summarize([]);
        }
        $cut = time() - $days * 86400;
        $n = 0;
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (++$n > self::SCAN_LIMIT) {
                    break;
                }
                if (!$f->isFile() || in_array($f->getFilename(), ['.gitignore', '.htaccess', 'index.html', 'index.php'], true)) {
                    continue;
                }
                if ($prefix !== '' && strpos($f->getFilename(), $prefix) !== 0) {
                    continue;
                }
                if ($f->getMTime() < $cut) {
                    $paths[] = $f->getPathname();
                }
            }
        } catch (Throwable $e) {
        }
        return $this->summarize($paths, false);
    }

    /** ملفات كاش صلاحيتها خلصت (أو مالهاش صلاحية ومالمسهاش حد من 30 يوم) */
    private function expiredCache(): array
    {
        $dir = $this->storage . '/cache';
        $paths = [];
        if (!is_dir($dir)) {
            return $this->summarize([]);
        }
        $now = time();
        $n = 0;
        try {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
                if (++$n > self::SCAN_LIMIT) {
                    break;
                }
                if (!$f->isFile() || $f->getExtension() !== 'cache') {
                    continue;
                }
                $h = @fopen($f->getPathname(), 'rb');
                $head = $h ? (string) fread($h, 4096) : '';
                if ($h) {
                    fclose($h);
                }
                if (preg_match('/s:7:"expires";i:(\d+);/', $head, $m)) {
                    $exp = (int) $m[1];
                    if ($exp > 0 && $exp < $now) {
                        $paths[] = $f->getPathname();
                    }
                } elseif ($f->getMTime() < $now - 30 * 86400) {
                    $paths[] = $f->getPathname();
                }
            }
        } catch (Throwable $e) {
        }
        return $this->summarize($paths, false);
    }

    /** نسخ قديمة/مكررة وملفات يدوية مالهاش لازمة */
    private function junkPaths(): array
    {
        $paths = [];
        $appIndex = null;
        $inApp = function (string $base) use (&$appIndex): bool {
            if ($appIndex === null) {
                $appIndex = [];
                try {
                    foreach (['/app', '/tests'] as $d) {
                        if (!is_dir($this->root . $d)) {
                            continue;
                        }
                        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root . $d, FilesystemIterator::SKIP_DOTS));
                        foreach ($it as $f) {
                            $p = $f->getPathname();
                            if ($f->isFile() && strpos($p, '~') === false && strpos($p, '/app/app/') === false && strpos($p, '/routes/Models/') === false) {
                                $appIndex[$f->getFilename()] = true;
                            }
                        }
                    }
                } catch (Throwable $e) {
                }
            }
            return isset($appIndex[$base]);
        };

        // 1) جوه app/: نسخ ويندوز بأسماء قصيرة (CONTRO~1) + app/app + app/routes/Models + routes/api_ADDITIONS.php
        foreach ([$this->root . '/app/app', $this->root . '/app/routes/Models', $this->root . '/app/routes/api_ADDITIONS.php'] as $p) {
            if (file_exists($p)) {
                $paths[] = $p;
            }
        }
        foreach (array_merge(glob($this->root . '/app/*') ?: [], glob($this->root . '/app/*/*') ?: []) as $p) {
            if (strpos(basename($p), '~') !== false) {
                $paths[] = $p;
            }
        }

        // 2) في جذر المشروع
        foreach (scandir($this->root) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $p = $this->root . '/' . $name;
            if (is_dir($p)) {
                if (in_array($name, self::KEEP_ROOT, true)) {
                    continue;
                }
                $junk = strpos($name, '~') !== false || in_array($name, ['TOURFECTO_DROPIN', 'previews', '0'], true);
                if (!$junk) {
                    // فولدر أغلب ملفات الـ PHP اللي فيه نسخ من ملفات موجودة في app/ أو tests/
                    $php = 0;
                    $dup = 0;
                    try {
                        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS)) as $f) {
                            if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
                                $php++;
                                $dup += $inApp($f->getFilename()) ? 1 : 0;
                            }
                        }
                    } catch (Throwable $e) {
                    }
                    $junk = $php > 0 && $dup / $php >= 0.8;
                }
                if ($junk) {
                    $paths[] = $p;
                }
                continue;
            }
            if (in_array($name, self::KEEP_ROOT_FILES, true)) {
                continue;
            }
            if (strpos($name, '~') !== false
                || in_array($name, ['.env.example.new', 'BUILD_REPORT.json', 'preview-desktop.png', 'preview-mobile.png'], true)
                || preg_match('/^TOURFECTO_.*\.sql$/i', $name)
                || (preg_match('/\.php$/', $name) && $inApp($name))) {
                $paths[] = $p;
            }
        }

        // 3) ملفات SQL اليدوية القديمة اللي اتجمعت في migration 2026_10_08_000004
        foreach (glob($this->root . '/database/TOURFECTO_*.sql') ?: [] as $p) {
            $paths[] = $p;
        }
        foreach (['fix_local_schema_divergences.sql', 'fix_missing_tables.sql'] as $n) {
            if (is_file($this->root . '/database/' . $n)) {
                $paths[] = $this->root . '/database/' . $n;
            }
        }
        foreach (glob($this->root . '/database/*~*') ?: [] as $p) {
            $paths[] = $p;
        }

        return $this->summarize(array_values(array_unique($paths)), true, true);
    }

    private function oldQuarantine(): array
    {
        $paths = [];
        foreach (glob($this->storage . '/quarantine/*', GLOB_ONLYDIR) ?: [] as $d) {
            if (filemtime($d) < time() - self::QUARANTINE_DAYS * 86400) {
                $paths[] = $d;
            }
        }
        return $this->summarize($paths, true, true);
    }

    // ================================================================== أدوات

    private function item(string $group, string $key, string $label, string $desc, array $s, bool $default): array
    {
        return ['group' => $group, 'key' => $key, 'label' => $label, 'desc' => $desc, 'count' => $s['count'], 'bytes' => $s['bytes'],
            'default' => $default, 'details' => $s['details']];
    }

    private function summarize(array $paths, bool $withDetails = true, bool $recursiveSize = false): array
    {
        $bytes = 0;
        foreach ($paths as $p) {
            $bytes += $recursiveSize && is_dir($p) ? $this->dirSize($p) : (int) @filesize($p);
        }
        $details = [];
        if ($withDetails) {
            foreach (array_slice($paths, 0, 40) as $p) {
                $details[] = ltrim(substr($p, strlen($this->root)), '/') . (is_dir($p) ? '/' : '');
            }
        }
        return ['paths' => $paths, 'count' => count($paths), 'bytes' => $bytes, 'details' => $details];
    }

    private function dirSize(string $d): int
    {
        $s = 0;
        try {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS)) as $f) {
                $s += $f->isFile() ? $f->getSize() : 0;
            }
        } catch (Throwable $e) {
        }
        return $s;
    }

    private function rrmdir(string $d): void
    {
        if (strpos(realpath($d) ?: '', realpath($this->storage . '/quarantine') ?: '/nonexistent') !== 0) {
            return; // أمان: مابنمسحش فولدرات غير اللي جوه الحجر
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($d);
    }

    private function removeEmptyDirs(string $dir): void
    {
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                if ($f->isDir()) {
                    @rmdir($f->getPathname()); // بيفشل لوحده لو مش فاضي
                }
            }
        } catch (Throwable $e) {
        }
    }

    private function hasColumns(string $table, array $cols): bool
    {
        static $cache = [];
        if (!isset($cache[$table])) {
            try {
                $rows = $this->db->query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
                $cache[$table] = array_column($rows, 'COLUMN_NAME');
            } catch (Throwable $e) {
                $cache[$table] = [];
            }
        }
        return $cache[$table] && !array_diff($cols, $cache[$table]);
    }

    private function affected(string $sql): int
    {
        return (int) $this->db->getConnection()->exec($sql);
    }

    private function biggestTables(): array
    {
        try {
            return $this->db->query(
                'SELECT TABLE_NAME AS name, TABLE_ROWS AS row_count, (DATA_LENGTH + INDEX_LENGTH) AS bytes
                 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY bytes DESC LIMIT 8'
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    private function disk(): array
    {
        $free = @disk_free_space($this->storage);
        $total = @disk_total_space($this->storage);
        $db = 0;
        try {
            $db = (int) ($this->db->query('SELECT SUM(DATA_LENGTH + INDEX_LENGTH) AS b FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')[0]['b'] ?? 0);
        } catch (Throwable $e) {
        }
        return ['free' => $free ?: null, 'total' => $total ?: null, 'db_bytes' => $db];
    }

    public function human(int $b): string
    {
        if ($b < 1024) {
            return $b . ' B';
        }
        $u = ['KB', 'MB', 'GB', 'TB'];
        $i = -1;
        do {
            $b /= 1024;
            $i++;
        } while ($b >= 1024 && $i < 3);
        return round($b, 1) . ' ' . $u[$i];
    }
}
