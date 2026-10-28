<?php
/**
 * يدمج عدة ملفات baseline لـ PHPStan (واحد لكل جزء تحليل) في ملف واحد.
 *   php tools/phpstan_merge_baseline.php <output.neon> <input1.neon> [input2.neon ...]
 * نفس الإدخال (message + identifier + path) بيتجمّع عدّه.
 */

declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "usage: phpstan_merge_baseline.php <output> <input>...\n");
    exit(2);
}

$out = $argv[1];
$root = str_replace(chr(92), '/', (string) realpath(dirname($out)));

/** مسار baseline بيتحسب نسبةً لمكان ملف الـ baseline نفسه؛ نحوّله لمسار نسبةً لجذر المستودع. */
function rootRelative(string $path, string $baselineDir, string $root): string
{
    $abs = $path;
    if (!preg_match('#^([A-Za-z]:)?/#', str_replace(chr(92), '/', $path))) {
        $abs = $baselineDir . '/' . $path;
    }
    $parts = [];
    foreach (explode('/', str_replace(chr(92), '/', $abs)) as $seg) {
        if ($seg === '' || $seg === '.') {
            if ($seg === '' && !$parts) { $parts[] = ''; }
            continue;
        }
        if ($seg === '..') { array_pop($parts); continue; }
        $parts[] = $seg;
    }
    $abs = implode('/', $parts);
    return str_starts_with($abs, $root . '/') ? substr($abs, strlen($root) + 1) : $path;
}

$entries = [];
foreach (array_slice($argv, 2) as $file) {
    $baselineDir = str_replace(chr(92), '/', (string) realpath(dirname($file)));
    $text = (string) file_get_contents($file);
    // كل إدخال بيبدأ بسطر "\t\t-" وبعده الحقول
    $blocks = preg_split('/\R\t\t-\R/', $text);
    array_shift($blocks); // اللي قبل أول إدخال (الترويسة)
    foreach ($blocks as $block) {
        $fields = [];
        foreach (preg_split('/\R/', $block) as $line) {
            if (preg_match('/^\t\t\t(message|identifier|count|path): (.*)$/', $line, $m)) {
                $fields[$m[1]] = $m[2];
            }
        }
        if (!isset($fields['message'], $fields['path'])) {
            continue;
        }
        $fields['path'] = rootRelative($fields['path'], $baselineDir, $root);
        $key = $fields['message'] . "\0" . ($fields['identifier'] ?? '') . "\0" . $fields['path'];
        if (!isset($entries[$key])) {
            $entries[$key] = $fields + ['count' => '1'];
            $entries[$key]['count'] = (int) ($fields['count'] ?? 1);
        } else {
            $entries[$key]['count'] += (int) ($fields['count'] ?? 1);
        }
    }
}

ksort($entries);
$lines = ["parameters:", "\tignoreErrors:"];
if (!$entries) {
    $lines = ["parameters:", "\tignoreErrors: []"];
}
foreach ($entries as $e) {
    $lines[] = "\t\t-";
    $lines[] = "\t\t\tmessage: " . $e['message'];
    if (!empty($e['identifier'])) {
        $lines[] = "\t\t\tidentifier: " . $e['identifier'];
    }
    $lines[] = "\t\t\tcount: " . $e['count'];
    $lines[] = "\t\t\tpath: " . $e['path'];
    $lines[] = "";
}
file_put_contents($out, implode("\n", $lines) . "\n");
fwrite(STDERR, count($entries) . " baseline entries written to {$out}\n");
