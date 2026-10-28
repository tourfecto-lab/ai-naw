<?php
/**
 * Tourfecto - تقرير الهجرات
 *
 * بيطبّق كل ملفات database/migrations/20*.sql بالترتيب على قاعدة بيانات فاضية (لازم تكون
 * قاعدة اختبار) وبيطبع الجمل اللي فشلت متجمّعة بالملف والسبب. مش بيوقف عند أول فشل.
 *
 * الاستخدام:
 *   DB_HOST=127.0.0.1 DB_NAME=tourfecto_test DB_USER=root DB_PASS= php tools/migration_report.php [--json]
 *
 * الحماية: بيرفض يشتغل لو DB_NAME مش بيحتوي على "test" (عشان ما يتطبّقش على قاعدة حقيقية بالغلط).
 */

declare(strict_types=1);

final class SqlStatementSplitter
{
    /**
     * يقسّم نص SQL لجمل منفصلة عند ';' بره النصوص ('..' ".." `..`) والتعليقات (سطرية -- و # ومتعددة الأسطر).
     * @return string[]
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $buf = '';
        $len = strlen($sql);
        $quote = null;
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $n = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($quote !== null) {
                $buf .= $c;
                if ($c === '\\' && $quote !== '`' && $n !== '') {
                    $buf .= $n;
                    $i++;
                } elseif ($c === $quote) {
                    if ($n === $quote) { // '' داخل نص = علامة اقتباس حرفية
                        $buf .= $n;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if ($c === '-' && $n === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2]))) {
                while ($i < $len && $sql[$i] !== "\n") { $i++; }
                $buf .= "\n";
                continue;
            }
            if ($c === '#') {
                while ($i < $len && $sql[$i] !== "\n") { $i++; }
                $buf .= "\n";
                continue;
            }
            if ($c === '/' && $n === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 1;
                $buf .= ' ';
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $buf .= $c;
                continue;
            }
            if ($c === ';') {
                $stmt = trim($buf);
                if ($stmt !== '') { $statements[] = $stmt; }
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        $tail = trim($buf);
        if ($tail !== '') { $statements[] = $tail; }
        return $statements;
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $dbName = (string) (getenv('DB_NAME') ?: '');
    if ($dbName === '' || stripos($dbName, 'test') === false) {
        fwrite(STDERR, "Refusing to run: DB_NAME must be set and contain 'test' (got '{$dbName}').\n");
        exit(2);
    }
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306', $dbName),
        (string) (getenv('DB_USER') ?: 'root'),
        (string) (getenv('DB_PASS') ?: ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true]
    );
    $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

    $dir = dirname(__DIR__) . '/database/migrations';
    $files = glob($dir . '/20*.sql') ?: [];
    natsort($files);

    $failures = [];
    $applied = 0;
    $statements = 0;
    foreach ($files as $path) {
        $applied++;
        foreach (SqlStatementSplitter::split((string) file_get_contents($path)) as $idx => $stmt) {
            $statements++;
            try {
                // EXECUTE لجملة SELECT (هجرات الـ PREPARE) بترجّع نتيجة لازم تتقري وتتقفل،
                // وإلا PDO بيرفض أي جملة بعدها (2014 unbuffered queries).
                $st = $pdo->query($stmt);
                if ($st !== false) {
                    if ($st->columnCount() > 0) { $st->fetchAll(); }
                    $st->closeCursor();
                }
            } catch (Throwable $e) {
                $failures[] = [
                    'file' => basename($path),
                    'statement' => $idx + 1,
                    'error' => preg_replace('/\s+/', ' ', $e->getMessage()),
                    'sql' => mb_substr(preg_replace('/\s+/', ' ', $stmt), 0, 110),
                ];
            }
        }
    }

    if (in_array('--json', $argv, true)) {
        echo json_encode(['files' => $applied, 'statements' => $statements, 'failures' => $failures], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
        exit($failures ? 1 : 0);
    }

    echo "Applied {$applied} files, {$statements} statements, " . count($failures) . " failed\n";
    $byFile = [];
    foreach ($failures as $f) { $byFile[$f['file']][] = $f; }
    foreach ($byFile as $file => $items) {
        echo "\n{$file} (" . count($items) . ")\n";
        foreach (array_slice($items, 0, 3) as $f) {
            echo "  #{$f['statement']}: " . mb_substr($f['error'], 0, 170) . "\n      " . $f['sql'] . "\n";
        }
    }
    exit($failures ? 1 : 0);
}
