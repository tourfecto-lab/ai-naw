<?php

/**
 * Tourfecto - قراءة أخطاء النظام من ملفات اللوج (للأدمن)
 *
 * بيقرا آخر جزء من storage/logs/app.log و php_errors.log (مش الملف كله - عشان لو كبير)،
 * ويجمّع الأخطاء المتشابهة في سطر واحد (نفس الرسالة بعد شيل الأرقام والقيم المتغيرة)
 * عشان تشوف "إيه اللي بيتكرر" بدل آلاف السطور.
 *
 * أي نص بيطلع للواجهة بيعدّي على LogRedactor (مفيش باسوردات/توكنات تظهر).
 */
class ErrorLogService
{
    /** أقصى حجم بنقراه من آخر كل ملف */
    private const TAIL_BYTES = [
        'app.log' => 3145728,        // 3MB
        'php_errors.log' => 1048576, // 1MB
    ];

    private const LEVELS = ['emergency' => 'error', 'alert' => 'error', 'critical' => 'error', 'error' => 'error', 'warning' => 'warning'];

    private string $dir;

    public function __construct(?string $logDir = null)
    {
        $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 3);
        $this->dir = rtrim($logDir ?: $root . '/storage/logs', '/');
    }

    /** آخر جزء من الملف كسطور كاملة */
    private function tailLines(string $file): array
    {
        $path = $this->dir . '/' . $file;
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }
        $size = (int) @filesize($path);
        $max = self::TAIL_BYTES[$file] ?? 1048576;
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return [];
        }
        $start = max(0, $size - $max);
        fseek($fh, $start);
        $buf = (string) stream_get_contents($fh);
        fclose($fh);
        $lines = explode("\n", $buf);
        if ($start > 0) {
            array_shift($lines); // أول سطر غالبًا مقطوع
        }
        return $lines;
    }

    /**
     * كل سطر خطأ/تحذير كـ [ts, level, source, message, context]
     * - app.log:        [2026-10-09 03:34:06] [error] رسالة {json}
     * - php_errors.log: [07-Sep-2026 05:41:12 UTC] PHP Warning:  رسالة in /path on line N
     */
    private function entries(int $sinceTs): array
    {
        $out = [];
        foreach ($this->tailLines('app.log') as $line) {
            if (!preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \[(\w+)\] (.*)$/u', $line, $m)) {
                continue;
            }
            $lvl = self::LEVELS[strtolower($m[2])] ?? null;
            if (!$lvl) {
                continue;
            }
            $ts = strtotime($m[1]);
            if (!$ts || $ts < $sinceTs) {
                continue;
            }
            $msg = $m[3];
            $ctx = '';
            $p = strpos($msg, ' {');
            if ($p !== false && substr(rtrim($msg), -1) === '}') {
                $ctx = substr($msg, $p + 1);
                $msg = substr($msg, 0, $p);
            }
            $out[] = ['ts' => $ts, 'level' => $lvl, 'source' => 'app', 'message' => $msg, 'context' => $ctx];
        }
        foreach ($this->tailLines('php_errors.log') as $line) {
            if (!preg_match('/^\[([^\]]+)\] PHP ([A-Za-z ]+?):\s+(.*)$/u', $line, $m)) {
                continue;
            }
            $kind = strtolower($m[2]);
            $lvl = (strpos($kind, 'fatal') !== false || strpos($kind, 'parse') !== false || $kind === 'error' || strpos($kind, 'exception') !== false)
                ? 'error' : ((strpos($kind, 'warning') !== false) ? 'warning' : null);
            if (!$lvl) {
                continue; // Notice/Deprecated: مش مهمين هنا
            }
            $ts = strtotime($m[1]);
            if (!$ts || $ts < $sinceTs) {
                continue;
            }
            $out[] = ['ts' => $ts, 'level' => $lvl, 'source' => 'php', 'message' => 'PHP ' . $m[2] . ': ' . $m[3], 'context' => ''];
        }
        return $out;
    }

    /** بصمة الرسالة: نفس الغلط بأرقام/قيم مختلفة = نفس المجموعة */
    public static function signature(string $msg): string
    {
        $s = preg_replace('/\'[^\']{0,200}\'|"[^"]{0,200}"|`[^`]{0,80}`/u', '?', $msg);
        $s = preg_replace('/\b0x[0-9a-f]+\b|\b\d+(\.\d+)?\b/i', 'N', (string) $s);
        $s = preg_replace('/\s+/', ' ', (string) $s);
        return mb_substr(trim((string) $s), 0, 300);
    }

    /**
     * رسايل عامة زي "Query Error" السبب الحقيقي فيها جوه الـ context ({"message": "..."}).
     * بنضيفه للبصمة عشان كل سبب يبقى سطر لوحده بدل ما 700 غلط مختلف يتجمعوا في سطر واحد.
     */
    private static function contextReason(string $ctx): string
    {
        if ($ctx === '') {
            return '';
        }
        $j = json_decode($ctx, true);
        if (!is_array($j)) {
            return '';
        }
        foreach (['message', 'error', 'reason'] as $k) {
            if (isset($j[$k]) && is_string($j[$k]) && $j[$k] !== '') {
                return ' — ' . mb_substr($j[$k], 0, 220);
            }
        }
        return '';
    }

    /** عدد الأخطاء (مش التحذيرات) من دقايق معينة - لفحص الصحة */
    public function errorCount(int $minutes = 60): int
    {
        $n = 0;
        foreach ($this->entries(time() - $minutes * 60) as $e) {
            if ($e['level'] === 'error') {
                $n++;
            }
        }
        return $n;
    }

    /**
     * @return array{groups:array, hourly:array, totals:array, files:array}
     */
    public function report(int $hours = 24, string $level = '', string $q = '', string $source = ''): array
    {
        $hours = max(1, min(168, $hours));
        $since = time() - $hours * 3600;
        $entries = $this->entries($since);

        $totals = ['error' => 0, 'warning' => 0, 'last_hour_errors' => 0];
        $hourly = [];
        for ($i = min(24, $hours) - 1; $i >= 0; $i--) {
            $hourly[gmdate('Y-m-d H:00', time() - $i * 3600)] = ['error' => 0, 'warning' => 0];
        }
        $groups = [];
        $qLower = mb_strtolower($q);
        foreach ($entries as $e) {
            $totals[$e['level']]++;
            if ($e['level'] === 'error' && $e['ts'] >= time() - 3600) {
                $totals['last_hour_errors']++;
            }
            $hk = gmdate('Y-m-d H:00', $e['ts']);
            if (isset($hourly[$hk])) {
                $hourly[$hk][$e['level']]++;
            }
            if ($level !== '' && $e['level'] !== $level) {
                continue;
            }
            if ($source !== '' && $e['source'] !== $source) {
                continue;
            }
            if ($qLower !== '' && mb_strpos(mb_strtolower($e['message'] . ' ' . $e['context']), $qLower) === false) {
                continue;
            }
            $sig = self::signature($e['message'] . self::contextReason($e['context']));
            $k = md5($e['level'] . '|' . $sig);
            if (!isset($groups[$k])) {
                $groups[$k] = ['key' => substr($k, 0, 12), 'level' => $e['level'], 'source' => $e['source'], 'message' => $sig,
                    'count' => 0, 'first_seen' => $e['ts'], 'last_seen' => $e['ts'], 'sample' => '', 'sample_context' => ''];
            }
            $g = &$groups[$k];
            $g['count']++;
            $g['first_seen'] = min($g['first_seen'], $e['ts']);
            if ($e['ts'] >= $g['last_seen']) {
                $g['last_seen'] = $e['ts'];
                $g['sample'] = $e['message'];
                $g['sample_context'] = $e['context'];
            }
            unset($g);
        }
        // الأحدث والأكتر تكرارًا فوق
        usort($groups, fn ($a, $b) => [$b['last_seen'] >= time() - 3600, $b['count'], $b['last_seen']] <=> [$a['last_seen'] >= time() - 3600, $a['count'], $a['last_seen']]);
        $groups = array_slice($groups, 0, 200);
        foreach ($groups as &$g) {
            $g['message'] = self::clean($g['message'], 400);
            $g['sample'] = self::clean($g['sample'], 1200);
            $g['sample_context'] = self::clean($g['sample_context'], 2000);
            $g['first_seen'] = gmdate('Y-m-d H:i:s', $g['first_seen']);
            $g['last_seen'] = gmdate('Y-m-d H:i:s', $g['last_seen']);
        }
        unset($g);

        $files = [];
        foreach (array_keys(self::TAIL_BYTES) as $f) {
            $p = $this->dir . '/' . $f;
            $files[] = ['name' => $f, 'exists' => is_file($p), 'size' => is_file($p) ? (int) filesize($p) : 0,
                'scanned' => is_file($p) ? min((int) filesize($p), self::TAIL_BYTES[$f]) : 0];
        }
        $hourlyOut = [];
        foreach ($hourly as $h => $v) {
            $hourlyOut[] = ['hour' => $h, 'error' => $v['error'], 'warning' => $v['warning']];
        }
        return ['groups' => $groups, 'hourly' => $hourlyOut, 'totals' => $totals, 'files' => $files, 'hours' => $hours];
    }

    /** إخفاء الأسرار + تقصير + شيل مسار السيرفر الطويل */
    private static function clean(string $s, int $max): string
    {
        if (class_exists('LogRedactor')) {
            // context بصيغة JSON: نخفي بالمفتاح (password/token/api_key...) مش بشكل القيمة بس
            $t = ltrim($s);
            if ($t !== '' && $t[0] === '{') {
                $j = json_decode($t, true);
                if (is_array($j)) {
                    $s = (string) json_encode(LogRedactor::context($j), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                }
            }
            // "key":"value" جوه نص عادي (أو JSON مقطوع)
            $s = (string) preg_replace_callback('/"([A-Za-z0-9_\-]{2,40})"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', function ($m) {
                return LogRedactor::isSensitiveKey($m[1]) && $m[2] !== '' && $m[2] !== '[REDACTED]' ? '"' . $m[1] . '":"[REDACTED]"' : $m[0];
            }, $s);
            $s = LogRedactor::text($s);
        }
        $root = defined('ROOT_PATH') ? rtrim(ROOT_PATH, '/') : '';
        if ($root !== '') {
            $s = str_replace($root . '/', '', $s);
        }
        return mb_substr($s, 0, $max);
    }
}
