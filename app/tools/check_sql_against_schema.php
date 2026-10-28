<?php
/**
 * فحص استعلامات SQL الثابتة في الكود مقابل قاعدة بيانات حقيقية.
 *
 * بيطلع كل نص SQL ثابت من app/ و cron/ ويعمله PREPARE على قاعدة البيانات
 * (من غير ما ينفّذه) - MySQL بيرفض أي عمود أو جدول مش موجود وقت الـ
 * PREPARE، فبنعرف الاستعلامات اللي هتفشل على السيرفر قبل ما العميل يقابلها.
 *
 * الاستخدام (على نسخة من هيكل قاعدة البيانات، مش بيعدّل فيها حاجة):
 *   DB_HOST=127.0.0.1 DB_NAME=x DB_USER=u DB_PASS=p php tools/check_sql_against_schema.php
 *
 * ملاحظة: استعلامات Google Ads (GAQL) زي "FROM customer" بتظهر كتحذير
 * غلط - دي مش SQL.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$dbName = getenv('DB_NAME') ?: '';
$pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ";dbname=$dbName;charset=utf8mb4", getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$loaded = array_map(fn ($p) => substr($p, strlen($root) + 1), require $root . '/app/Config/project_classmap.php');
$files = explode("\n", trim(shell_exec("cd " . escapeshellarg($root) . " && git ls-files 'app/*.php' 'cron/*.php' | grep -v -e quarantine")));
$out = [];
foreach ($files as $f) {
    $tokens = token_get_all(file_get_contents("$root/$f"));
    foreach ($tokens as $i => $t) {
        if (!is_array($t)) continue;
        $sql = null;
        if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
            $q = $t[1][0];
            $sql = substr($t[1], 1, -1);
            if ($q === "'") $sql = str_replace(["\\'", '\\\\'], ["'", '\\'], $sql);
            // skip if concatenated with something (partial SQL)
            $prev = $tokens[$i - 1] ?? null; $next = $tokens[$i + 1] ?? null;
            $isWs = fn($x) => is_array($x) && $x[0] === T_WHITESPACE;
            $p = $isWs($prev) ? ($tokens[$i - 2] ?? null) : $prev;
            $n = $isWs($next) ? ($tokens[$i + 2] ?? null) : $next;
            if ($p === '.' || $n === '.') continue;
        } elseif ($t[0] === T_START_HEREDOC) {
            continue;
        }
        if ($sql === null) continue;
        $s = trim($sql);
        if (!preg_match('/^(SELECT|UPDATE|INSERT|DELETE|REPLACE)\b/i', $s)) continue;
        if (!preg_match('/\b(FROM|INTO|UPDATE|SET)\b/i', $s)) continue;
        if (preg_match('/\{\$|\$[a-zA-Z_]|%s|%d/', $s)) continue; // dynamic
        $prep = preg_replace('/:[a-zA-Z_][a-zA-Z0-9_]*/', '?', $s);
        try { $pdo->prepare($prep); }
        catch (PDOException $e) {
            $m = $e->getMessage();
            if (preg_match("/Unknown column '([^']+)' in '([^']+)'|Table '[^.]+\.([^']+)' doesn't exist/", $m, $mm)) {
                $line = $t[2];
                $out[] = ['loaded' => in_array($f, $loaded, true) || str_starts_with($f, 'cron/'), 'file' => $f, 'line' => $line, 'error' => preg_replace('/^SQLSTATE\[\w+\]: [^:]+: \d+ /', '', $m), 'sql' => preg_replace('/\s+/', ' ', substr($s, 0, 160))];
            }
        }
    }
}
foreach ($out as $x) {
    printf("%s %s:%d  %s\n", $x['loaded'] ? 'USED  ' : 'unused', $x['file'], $x['line'], $x['error']);
}
echo count($out) . " issue(s); 'unused' = نسخة مكررة مش بتتحمّل\n";
exit(count(array_filter($out, fn ($x) => $x['loaded'])) ? 1 : 0);
