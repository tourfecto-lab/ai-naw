<?php

/**
 * Tourfecto runtime preflight guards.
 * Keeps missing PHP extensions / storage problems from surfacing as opaque 500s.
 */
declare(strict_types=1);

function tourfecto_required_runtime_extensions(): array
{
    return [
        'pdo' => 'PDO',
        'pdo_mysql' => 'PDO MySQL',
        'mbstring' => 'mbstring',
        'openssl' => 'OpenSSL',
        'curl' => 'cURL',
        'gd' => 'GD',
    ];
}

function tourfecto_missing_runtime_extensions(): array
{
    $missing = [];
    foreach (tourfecto_required_runtime_extensions() as $extension => $label) {
        if (!extension_loaded($extension)) {
            $missing[$extension] = $label;
        }
    }
    return $missing;
}

function tourfecto_register_fallback_autoloader(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    spl_autoload_register(function (string $class): void {
        // Project classes are currently non-namespaced. Leave vendor/framework names alone.
        if (str_contains($class, '\\')) {
            return;
        }

        static $index = null;
        if ($index === null) {
            $index = [];
            $appDir = defined('APP_PATH') ? APP_PATH : dirname(__DIR__);
            if (!is_dir($appDir)) {
                return;
            }
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($it as $file) {
                    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                        continue;
                    }
                    $path = $file->getPathname();
                    if (str_contains($path, DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR)
                        || str_contains($path, DIRECTORY_SEPARATOR . 'Lang' . DIRECTORY_SEPARATOR)) {
                        continue;
                    }
                    $index[$file->getBasename('.php')][] = $path;
                }
            } catch (Throwable $e) {
                return;
            }
        }

        foreach ($index[$class] ?? [] as $path) {
            $source = @file_get_contents($path);
            if ($source !== false
                && preg_match('/^\s*(?:abstract\s+|final\s+)?(?:class|interface|trait)\s+' . preg_quote($class, '/') . '\b/m', $source)) {
                require_once $path;
                return;
            }
        }
    }, true, true);
}

function tourfecto_require_runtime_extensions(bool $throw = true): array
{
    $missing = tourfecto_missing_runtime_extensions();
    if ($missing && $throw) {
        throw new RuntimeException(
            'Tourfecto requires missing PHP extensions: ' . implode(', ', array_values($missing))
            . '. Install/enable them before starting the application.'
        );
    }
    return $missing;
}
