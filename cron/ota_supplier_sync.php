<?php
require_once __DIR__.'/bootstrap.php';
try {$n=(new OtaSupplierService())->queueDue(10);fwrite(STDOUT,'OTA supplier jobs queued: '.$n.PHP_EOL);}catch(Throwable $e){fwrite(STDERR,'OTA supplier scheduler failed. Check installation.'.PHP_EOL);exit(1);}
