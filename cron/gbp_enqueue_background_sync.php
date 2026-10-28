<?php
/** Every six hours via the central scheduler; the worker performs the existing GBP sync. */
require_once __DIR__.'/bootstrap.php';
try {
    $out=(new GbpBackgroundScheduler(Database::getInstance()))->run();
    fwrite(STDOUT,json_encode($out,JSON_THROW_ON_ERROR).PHP_EOL);
} catch(Throwable $e) {Logger::error('GBP scheduling failed',['message'=>$e->getMessage()]);fwrite(STDERR,"GBP scheduling unavailable; check Phase 5 migration and queue.\n");exit(1);}
