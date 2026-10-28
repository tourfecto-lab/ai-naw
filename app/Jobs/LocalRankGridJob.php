<?php
class LocalRankGridJob implements QueueJobInterface {
    public function handle(array $payload): void {(new LocalRankGridService(Database::getInstance()))->step((int)($payload['run_id']??0));}
}
