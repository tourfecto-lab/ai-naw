<?php
/** CLI-only checksum verification; makes no changes. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);
$manifestFile=__DIR__.'/october_repair_manifest.json';
$manifest=json_decode((string) @file_get_contents($manifestFile),true);
if (!is_array($manifest) || !isset($manifest['files'])) { fwrite(STDERR,"Repair manifest missing or invalid.\n"); exit(1); }
$failed=0;
foreach ($manifest['files'] as $file) {
    $relative=$file['path'];
    if (!preg_match('#^[a-zA-Z0-9_./-]+$#D',$relative) || str_contains($relative,'..')) { $failed++;continue; }
    $path=$root.'/'.$relative;
    $ok=is_file($path) && hash_equals($file['sha256'],hash_file('sha256',$path));
    echo ($ok?'OK ':'MISMATCH ').$relative.PHP_EOL;
    if (!$ok) { $failed++; }
}
echo $failed ? "Verification failed: $failed mismatched files.\n" : "All repair files match.\n";
exit($failed?1:0);
