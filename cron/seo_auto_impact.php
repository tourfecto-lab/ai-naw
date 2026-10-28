<?php
require_once __DIR__.'/bootstrap.php';
fwrite(STDOUT,'Automatic task monitor mappings: '.(new SeoImpactService())->syncAutomatic().PHP_EOL);
