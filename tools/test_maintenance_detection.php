<?php
require __DIR__.'/../app/Services/AutoFix/PageContext.php';
$cases=[
 ['<html><title>Coming Soon</title><body><h1>Coming Soon</h1>We are launching soon.</body></html>',true],
 ['<html><title>Coming Soon | Tourfecto</title><body>Our new site is launching soon.</body></html>',true],
 ['<html><title>Travel services</title><body><h1>Under construction</h1></body></html>',true],
 ['<html><title>New tours coming soon</title><body><h1>Book your trip</h1>Our new tours launch next month.</body></html>',false],
 ['<html><title>Travel services</title><body><h1>Tourfecto</h1><!-- seedprod coming soon -->Book a trip.</body></html>',false],
 ['<html><title>Coming Soon</title><body><h1>Travel services</h1>'.str_repeat('Real travel content. ',150).'</body></html>',false],
];
foreach($cases as $i=>$case){if(PageContext::isMaintenanceHtml($case[0])!==$case[1])throw new RuntimeException('Case '.$i.' failed');}
foreach(['https://example.com/','https://example.com/?lang=en#section'] as $url){$fresh=PageContext::freshAuditUrl($url);if(strpos($fresh,'_tourfecto_audit=')===false||strpos($fresh,'#')!==false)throw new RuntimeException('Fresh URL failed');}
echo "Maintenance detection checks passed.\n";
