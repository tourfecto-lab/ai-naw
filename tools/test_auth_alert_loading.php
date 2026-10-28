<?php
/** Run standalone with PHP 8.1+, without application bootstrap. No database or mail calls. */
class Controller{}
require_once __DIR__.'/../app/Controllers/AuthController.php';
if(!class_exists('AuthController',false))throw new RuntimeException('Auth controller did not load');
foreach(['SendLoginAdminAlertJob','SendRegistrationAdminAlertJob','LoginAdminAlert','RegistrationAdminAlert'] as $name)if(class_exists($name,false))throw new RuntimeException('Unexpected eager dependency: '.$name);
echo "PASS: AuthController loads without mail services, jobs or QueueJobInterface\n";
