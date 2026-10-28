<?php
require_once __DIR__.'/../Core/Contracts/QueueJobInterface.php';
require_once __DIR__.'/../Services/RegistrationAdminAlert.php';
class SendRegistrationAdminAlertJob implements QueueJobInterface
{
    public function handle(array $payload): void{RegistrationAdminAlert::send((int)($payload['user_id']??0),(string)($payload['request_ip']??''));}
}
