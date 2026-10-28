<?php
final class OtaSupplierSyncJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        $service=new OtaSupplierService();$uid=(int)($payload['user_id']??0);$wid=(int)($payload['website_id']??0);$platform=(string)($payload['platform']??'');
        $row=$service->connection($uid,$wid,$platform);
        if(!$row||(int)$row['id']!==(int)($payload['connection_id']??0)||$row['verified_at']!==($payload['verified_at']??null)||!hash_equals(hash('sha256',(string)$row['credentials_encrypted']),(string)($payload['config_hash']??'')))return;
        try{$service->sync($uid,$wid,$platform,(string)$payload['config_hash'],(int)$payload['connection_id']);}catch(Throwable $e){
            $pdo=Database::getInstance()->getConnection();$q=$pdo->prepare("UPDATE ota_supplier_connections SET state='partial',last_error='Background read failed. Check connection and retry.',next_sync_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 MINUTE) WHERE id=? AND BINARY credentials_encrypted=? AND state IN ('queued','syncing')");$q->execute([$row['id'],$row['credentials_encrypted']]);throw $e;
        }
    }
}
