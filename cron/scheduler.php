<?php
/**
 * Tourfecto - منظّم الكرونات (سطر Cron واحد بيشغّل الكل)
 *
 * على aaPanel ← Cron ← Add Task (Shell Script، كل 1 دقيقة) حط سطر واحد بس:
 *   sudo -u www /www/server/php/83/bin/php /www/wwwroot/tourfecto.pro/cron/scheduler.php >/dev/null 2>&1
 * (غيّر 83 لرقم نسخة PHP عندك - شوف /www/server/php/)
 *
 * كل دقيقة بيبص في cron/schedule.php، واللي جه ميعاده بيشغّله في الخلفية
 * لوحده (عشان سكربت بطيء مايأخرش الباقي). وبيمنع إن نفس السكربت يشتغل
 * مرتين في نفس الوقت، وبيقفل أي سكربت علّق أكتر من max_minutes.
 *
 * أوامر مفيدة (من Terminal، بعد cd /www/wwwroot/tourfecto.pro، وقبل كل أمر: sudo -u www /www/server/php/83/bin/php):
 *   php cron/scheduler.php --list            حالة كل الكرونات (آخر تشغيل، نجح ولا فشل)
 *   php cron/scheduler.php --run=automations شغّل كرون دلوقتي بإيدك وشوف النتيجة
 *   php cron/scheduler.php --disable=ads_alerts   وقّف كرون
 *   php cron/scheduler.php --enable=tripadvisor   شغّل كرون
 *   php cron/scheduler.php --due="2026-10-08 02:00"  إيه اللي هيشتغل في الميعاد ده (للتجربة)
 *
 * الحالة بتتسجل في storage/cron/state.json، واللوجات في storage/logs/cron/KEY.log
 *
 * @version 1.0.0
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

final class TourfectoScheduler
{
    private string $root;
    private string $dir;
    private string $logDir;
    private array $jobs;
    private DateTimeZone $tz;

    public function __construct()
    {
        $this->root = dirname(__DIR__);
        $this->dir = $this->root . '/storage/cron';
        $this->logDir = $this->root . '/storage/logs/cron';
        foreach ([$this->dir, $this->dir . '/locks', $this->logDir] as $d) {
            if (!is_dir($d)) {
                @mkdir($d, 0775, true);
            }
        }
        $this->jobs = require __DIR__ . '/schedule.php';
        $tzName = $this->env('CRON_TIMEZONE') ?: 'Africa/Cairo';
        try {
            $this->tz = new DateTimeZone($tzName);
        } catch (Throwable $e) {
            $this->tz = new DateTimeZone('UTC');
        }
    }

    // ------------------------------------------------------------------
    // الدورة العادية (كل دقيقة)
    // ------------------------------------------------------------------
    public function tick(?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now', $this->tz);
        $this->writeHeartbeat($now);
        $started = [];
        foreach ($this->dueJobs($now) as $key) {
            if ($this->isRunning($key)) {
                $this->setState($key, ['last_skipped_at' => $now->format('Y-m-d H:i:s'), 'last_skip_reason' => 'لسه شغّال من المرة اللي فاتت']);
                continue;
            }
            if ($this->spawn($key)) {
                $started[] = $key;
            } else {
                // مفيش طريقة نشغّل في الخلفية على السيرفر ده: نشغّله هنا على طول
                $this->execute($key, false);
                $started[] = $key . ' (inline)';
            }
        }
        return $started;
    }

    /** الكرونات اللي ميعادها الدقيقة دي */
    public function dueJobs(DateTimeImmutable $now): array
    {
        $out = [];
        foreach ($this->jobs as $key => $job) {
            if (!$this->isEnabled($key)) {
                continue;
            }
            if (!is_file(__DIR__ . '/' . $job['file'])) {
                continue;
            }
            if (self::matches($job['when'], $now)) {
                $out[] = $key;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // تشغيل كرون واحد (بيتنادى في الخلفية: --exec=KEY)
    // ------------------------------------------------------------------
    public function execute(string $key, bool $toConsole): int
    {
        if (!isset($this->jobs[$key])) {
            fwrite(STDERR, "مفيش كرون اسمه {$key}\n");
            return 2;
        }
        $job = $this->jobs[$key];
        $lock = @fopen($this->dir . "/locks/{$key}.lock", 'c');
        if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($toConsole) {
                fwrite(STDOUT, "{$key} شغّال دلوقتي بالفعل - استنى لما يخلص\n");
            }
            return 3;
        }

        $logFile = $this->logDir . "/{$key}.log";
        $this->rotate($logFile);
        $start = microtime(true);
        $this->setState($key, ['running' => true, 'pid' => getmypid(), 'last_start' => $this->nowStr()]);

        $cmd = [PHP_BINARY, __DIR__ . '/' . $job['file']];
        $header = '[' . $this->nowStr() . "] ===== بداية {$key} =====\n";
        @file_put_contents($logFile, $header, FILE_APPEND);

        $exit = 1;
        $timedOut = false;
        if (function_exists('proc_open') && !$this->disabled('proc_open')) {
            $spec = $toConsole
                ? [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']]
                : [0 => ['file', '/dev/null', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']];
            $proc = proc_open($cmd, $spec, $pipes, $this->root);
            if (is_resource($proc)) {
                $limit = max(1, (int) ($job['max_minutes'] ?? 30)) * 60;
                if ($toConsole) {
                    stream_set_blocking($pipes[1], false);
                    stream_set_blocking($pipes[2], false);
                }
                while (true) {
                    if ($toConsole) {
                        foreach ([1, 2] as $i) {
                            $chunk = stream_get_contents($pipes[$i]);
                            if ($chunk !== '' && $chunk !== false) {
                                fwrite(STDOUT, $chunk);
                                @file_put_contents($logFile, $chunk, FILE_APPEND);
                            }
                        }
                    }
                    $st = proc_get_status($proc);
                    if (!$st['running']) {
                        $exit = (int) $st['exitcode'];
                        break;
                    }
                    if (microtime(true) - $start > $limit) {
                        $timedOut = true;
                        proc_terminate($proc, 15);
                        sleep(3);
                        if (proc_get_status($proc)['running']) {
                            proc_terminate($proc, 9);
                        }
                        $exit = 124;
                        break;
                    }
                    usleep(200000);
                }
                if ($toConsole) {
                    foreach ([1, 2] as $i) {
                        $rest = stream_get_contents($pipes[$i]);
                        if ($rest) {
                            fwrite(STDOUT, $rest);
                            @file_put_contents($logFile, $rest, FILE_APPEND);
                        }
                        fclose($pipes[$i]);
                    }
                }
                $closeCode = proc_close($proc);
                if ($exit === -1) {
                    $exit = $closeCode;
                }
            }
        } else {
            // آخر حل: نفس العملية (من غير حد زمني)
            $line = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $job['file']) . ' >> ' . escapeshellarg($logFile) . ' 2>&1';
            if (function_exists('exec') && !$this->disabled('exec')) {
                exec('cd ' . escapeshellarg($this->root) . ' && ' . $line, $o, $exit);
            } else {
                @file_put_contents($logFile, "السيرفر قافل proc_open و exec - مش هينفع نشغّل السكربت\n", FILE_APPEND);
                $exit = 126;
            }
        }

        $secs = round(microtime(true) - $start, 1);
        $ok = $exit === 0;
        $note = $timedOut ? "اتقفل لأنه عدّى {$job['max_minutes']} دقيقة" : ($ok ? 'تمام' : "فشل (كود {$exit}) - شوف اللوج");
        @file_put_contents($logFile, '[' . $this->nowStr() . "] ===== نهاية {$key}: {$note} في {$secs} ثانية =====\n", FILE_APPEND);

        $prev = $this->state()[$key] ?? [];
        $this->setState($key, [
            'running' => false,
            'pid' => null,
            'last_end' => $this->nowStr(),
            'last_exit' => $exit,
            'last_seconds' => $secs,
            'last_result' => $note,
            'runs' => (int) ($prev['runs'] ?? 0) + 1,
            'fails_in_row' => $ok ? 0 : (int) ($prev['fails_in_row'] ?? 0) + 1,
            'last_ok' => $ok ? $this->nowStr() : ($prev['last_ok'] ?? null),
        ]);

        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $exit;
    }

    // ------------------------------------------------------------------
    // عرض الحالة
    // ------------------------------------------------------------------
    public function listJobs(): void
    {
        $state = $this->state();
        $hb = @file_get_contents($this->dir . '/heartbeat');
        $hbAge = $hb ? (time() - strtotime($hb)) : null;
        echo 'التوقيت: ' . $this->tz->getName() . ' | آخر دورة للمنظّم: ' . ($hb ?: 'ولا مرة')
            . ($hbAge !== null && $hbAge > 180 ? '  ⚠️ المنظّم مش شغّال! راجع سطر الـ Cron' : '') . "\n\n";
        $groups = ['core' => 'الأساس', 'reviews' => 'التقييمات', 'seo' => 'الموقع والـ SEO', 'ads' => 'الإعلانات', 'competitors' => 'المنافسين', 'growth' => 'المتابعة والإيرادات'];
        foreach ($groups as $g => $gName) {
            echo "== {$gName}\n";
            foreach ($this->jobs as $key => $job) {
                if (($job['group'] ?? '') !== $g) {
                    continue;
                }
                $s = $state[$key] ?? [];
                if (!$this->isEnabled($key)) {
                    $status = '⏸  موقوف';
                } elseif (!empty($s['running']) && $this->isRunning($key)) {
                    $status = '▶  شغّال دلوقتي (من ' . ($s['last_start'] ?? '?') . ')';
                } elseif (!isset($s['last_exit'])) {
                    $status = '…  لسه ما اشتغلش';
                } elseif ((int) $s['last_exit'] === 0) {
                    $status = '✓  ' . ($s['last_end'] ?? '') . ' (' . ($s['last_seconds'] ?? '?') . 'ث)';
                } else {
                    $status = '✗  ' . ($s['last_end'] ?? '') . ' - ' . ($s['last_result'] ?? '') . ((int) ($s['fails_in_row'] ?? 0) > 1 ? " ({$s['fails_in_row']} مرات ورا بعض)" : '');
                }
                printf("  %-20s %-18s %s\n      %s\n", $key, $job['when'], $status, $job['title']);
            }
        }
    }

    public function setEnabled(string $key, bool $on): bool
    {
        if (!isset($this->jobs[$key])) {
            return false;
        }
        $file = $this->dir . '/overrides.json';
        $ov = json_decode((string) @file_get_contents($file), true) ?: [];
        $ov[$key] = $on;
        file_put_contents($file, json_encode($ov, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        return true;
    }

    public function keys(): array
    {
        return array_keys($this->jobs);
    }

    public function timezone(): DateTimeZone
    {
        return $this->tz;
    }

    // ------------------------------------------------------------------
    // Cron expression: دقيقة ساعة يوم-الشهر شهر يوم-الأسبوع
    // بيدعم: *  */n  a-b  a-b/n  a,b,c  (والأسبوع 0 أو 7 = الحد)
    // ------------------------------------------------------------------
    public static function matches(string $expr, DateTimeInterface $t): bool
    {
        $f = preg_split('/\s+/', trim($expr));
        if (count($f) !== 5) {
            return false;
        }
        $dowVal = (int) $t->format('w');
        $min = self::field($f[0], (int) $t->format('i'), 0, 59);
        $hour = self::field($f[1], (int) $t->format('G'), 0, 23);
        $mon = self::field($f[3], (int) $t->format('n'), 1, 12);
        $dom = self::field($f[2], (int) $t->format('j'), 1, 31);
        $dow = self::field($f[4], $dowVal, 0, 7) || ($dowVal === 0 && self::field($f[4], 7, 0, 7));
        if (!$min || !$hour || !$mon) {
            return false;
        }
        // زي cron العادي: لو اليوم والأسبوع الاتنين متحددين، كفاية واحد منهم
        $domStar = $f[2] === '*' || $f[2] === '?';
        $dowStar = $f[4] === '*' || $f[4] === '?';
        if (!$domStar && !$dowStar) {
            return $dom || $dow;
        }
        return $dom && $dow;
    }

    private static function field(string $spec, int $v, int $lo, int $hi): bool
    {
        foreach (explode(',', $spec) as $part) {
            $step = 1;
            if (strpos($part, '/') !== false) {
                [$part, $s] = explode('/', $part, 2);
                $step = max(1, (int) $s);
            }
            if ($part === '*' || $part === '?') {
                [$a, $b] = [$lo, $hi];
            } elseif (strpos($part, '-') !== false) {
                [$a, $b] = array_map('intval', explode('-', $part, 2));
            } else {
                $a = (int) $part;
                $b = $step > 1 ? $hi : $a;
            }
            if ($v >= $a && $v <= $b && (($v - $a) % $step) === 0) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------
    // داخلي
    // ------------------------------------------------------------------
    private function spawn(string $key): bool
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --exec=' . escapeshellarg($key) . ' > /dev/null 2>&1 &';
        if (function_exists('exec') && !$this->disabled('exec')) {
            exec($cmd, $o, $code);
            return $code === 0;
        }
        if (function_exists('proc_open') && !$this->disabled('proc_open')) {
            $p = proc_open($cmd, [], $pipes);
            if (is_resource($p)) {
                proc_close($p);
                return true;
            }
        }
        if (function_exists('popen') && !$this->disabled('popen')) {
            $h = popen($cmd, 'r');
            if ($h) {
                pclose($h);
                return true;
            }
        }
        return false;
    }

    private function isRunning(string $key): bool
    {
        $lock = @fopen($this->dir . "/locks/{$key}.lock", 'c');
        if (!$lock) {
            return false;
        }
        $free = flock($lock, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($lock, LOCK_UN);
        }
        fclose($lock);
        return !$free;
    }

    private function isEnabled(string $key): bool
    {
        static $ov = null;
        if ($ov === null) {
            $ov = json_decode((string) @file_get_contents($this->dir . '/overrides.json'), true) ?: [];
        }
        return array_key_exists($key, $ov) ? (bool) $ov[$key] : (bool) ($this->jobs[$key]['enabled'] ?? true);
    }

    private function state(): array
    {
        return json_decode((string) @file_get_contents($this->dir . '/state.json'), true) ?: [];
    }

    private function setState(string $key, array $patch): void
    {
        $file = $this->dir . '/state.json';
        $h = @fopen($file, 'c+');
        if (!$h) {
            return;
        }
        flock($h, LOCK_EX);
        $all = json_decode((string) stream_get_contents($h), true) ?: [];
        $all[$key] = array_merge($all[$key] ?? [], $patch);
        ftruncate($h, 0);
        rewind($h);
        fwrite($h, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($h);
        flock($h, LOCK_UN);
        fclose($h);
    }

    private function writeHeartbeat(DateTimeImmutable $now): void
    {
        @file_put_contents($this->dir . '/heartbeat', $now->format('Y-m-d H:i:s P'));
    }

    private function rotate(string $file): void
    {
        if (is_file($file) && filesize($file) > 5 * 1024 * 1024) {
            @rename($file, $file . '.1');
        }
    }

    private function nowStr(): string
    {
        return (new DateTimeImmutable('now', $this->tz))->format('Y-m-d H:i:s');
    }

    private function disabled(string $fn): bool
    {
        $list = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return in_array($fn, $list, true);
    }

    /** قراية متغير من .env من غير ما نحمّل المنصة كلها */
    private function env(string $name): ?string
    {
        $v = getenv($name);
        if ($v !== false && $v !== '') {
            return $v;
        }
        $file = $this->root . '/.env';
        if (!is_file($file)) {
            return null;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^\s*' . preg_quote($name, '/') . '\s*=\s*"?([^"#]*)"?/', $line, $m)) {
                $val = trim($m[1]);
                return $val === '' ? null : $val;
            }
        }
        return null;
    }
}

// ----------------------------------------------------------------------
$s = new TourfectoScheduler();
$opts = getopt('', ['exec:', 'run:', 'list', 'enable:', 'disable:', 'due:']);

if (isset($opts['exec'])) {
    exit($s->execute((string) $opts['exec'], false));
}
if (isset($opts['run'])) {
    exit($s->execute((string) $opts['run'], true));
}
if (isset($opts['list'])) {
    $s->listJobs();
    exit(0);
}
if (isset($opts['enable']) || isset($opts['disable'])) {
    $key = (string) ($opts['enable'] ?? $opts['disable']);
    $on = isset($opts['enable']);
    if (!$s->setEnabled($key, $on)) {
        fwrite(STDERR, "مفيش كرون اسمه {$key}. الأسماء: " . implode(', ', $s->keys()) . "\n");
        exit(2);
    }
    echo $key . ($on ? ' اتشغّل' : ' اتوقف') . "\n";
    exit(0);
}
if (isset($opts['due'])) {
    $at = new DateTimeImmutable((string) $opts['due'], $s->timezone());
    echo $at->format('Y-m-d H:i l') . ': ' . (implode(', ', $s->dueJobs($at)) ?: 'ولا حاجة') . "\n";
    exit(0);
}

$started = $s->tick();
if ($started) {
    echo '[' . date('Y-m-d H:i:s') . '] started: ' . implode(', ', $started) . "\n";
}
exit(0);
