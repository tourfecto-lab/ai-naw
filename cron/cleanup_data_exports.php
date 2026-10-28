<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/bootstrap.php';
try {
    $result=ExportRetentionService::cleanup(Database::getInstance());
    echo json_encode($result,JSON_THROW_ON_ERROR) . "\n";
    exit($result['failed'] || $result['unsafe'] ? 1 : 0);
} catch(Throwable $e) {
    if(class_exists('Logger'))Logger::error('Export retention worker failed',['message'=>$e->getMessage()]);
    fwrite(STDERR,"Export cleanup could not complete; inspect private logs.\n");exit(1);
}
