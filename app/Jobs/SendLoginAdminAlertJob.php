<?php
require_once __DIR__.'/../Core/Contracts/QueueJobInterface.php';
require_once __DIR__.'/../Services/LoginAdminAlert.php';
class SendLoginAdminAlertJob implements QueueJobInterface
{
    public function handle(array $payload): void{LoginAdminAlert::send((string)($payload['event_id']??''),(string)($payload['request_ip']??''));}
}
