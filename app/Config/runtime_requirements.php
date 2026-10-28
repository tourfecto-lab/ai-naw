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
    static $loader=null;
    if($loader!==null)spl_autoload_unregister($loader);
    else {
        $map=require __DIR__.'/project_classmap.php';
        $loader=static function(string $class)use($map):void{
            if(isset($map[$class])&&is_file($map[$class])) { require_once $map[$class]; return; }
            // ZIP updates can leave newer installed classes outside an older release map.
            // Resolve only a unique conventional filename inside trusted application code.
            if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$class))return;
            static $files=null;
            if($files===null){
                $files=[];$app=realpath(dirname(__DIR__));
                foreach(['Services','Controllers','Models','Jobs','Core','Exceptions','Middleware'] as $directory){
                    $base=$app.'/'.$directory;if(!is_dir($base))continue;
                    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS));
                    foreach($iterator as $file){
                        if(!$file->isFile()||$file->isLink()||$file->getExtension()!=='php')continue;
                        $path=$file->getRealPath();
                        if(!$path||!str_starts_with($path,$app.DIRECTORY_SEPARATOR))continue;
                        $name=$file->getBasename('.php');$files[$name][]=$path;
                    }
                }
            }
            // Never guess between legacy wrappers or duplicate implementations.
            if(count($files[$class]??[])===1)require_once $files[$class][0];
        };
    }
    // Composer prepends its own loader; re-register after vendor/autoload.php too.
    spl_autoload_register($loader,true,true);
}
