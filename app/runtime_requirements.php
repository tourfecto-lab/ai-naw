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
    static $registered=false;
    if ($registered) return;
    $registered=true;
    $map=require __DIR__.'/project_classmap.php';
    spl_autoload_register(function(string $class)use($map):void{
        if (isset($map[$class]) && is_file($map[$class])) require_once $map[$class];
    }, true, true);
}
