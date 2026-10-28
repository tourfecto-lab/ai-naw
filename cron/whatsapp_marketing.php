<?php
require_once __DIR__.'/bootstrap.php';
try{$count=(new WhatsAppMarketingService())->queueDue(5);fwrite(STDOUT,'WhatsApp marketing campaigns queued: '.$count.PHP_EOL);}catch(Throwable $e){fwrite(STDERR,'WhatsApp marketing scheduler failed. Check installation.'.PHP_EOL);exit(1);}
