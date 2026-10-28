<?php

/**
 * Tourfecto - PHP Syntax Linter
 * يفحص صياغة كل ملفات PHP في المشروع ويخرج نتيجة موحّدة.
 * الاستخدام: php tools/lint.php
 */

$root = dirname(__DIR__);
$dirs = ['app', 'cron', 'public_html', 'public', 'tests', 'tools', 'scripts', 'maintenance'];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$errors = [];
$checked = 0;

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    if (strpos($path, '/vendor/') !== false) {
        continue;
    }
    $inTarget = dirname($path) === $root;
    foreach ($dirs as $dir) {
        if (strpos($path, $root . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR) === 0) {
            $inTarget = true;
            break;
        }
    }
    if (!$inTarget) {
        continue;
    }
    $checked++;
    $output = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);
    if ($code !== 0) {
        $errors[] = implode("\n", $output);
    }
}

if ($errors) {
    echo "FAILED: " . count($errors) . " file(s) with syntax errors out of {$checked} checked\n\n";
    echo implode("\n\n", $errors) . "\n";
    exit(1);
}

echo "OK: {$checked} PHP files checked, no syntax errors.\n";
exit(0);
