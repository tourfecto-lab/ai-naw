<?php
/** Durable boundary around external writes. An interrupted write is never blindly replayed. */
class ExternalExecution
{
    public static function lock(string $key): bool
    {
        $s=Database::getInstance()->getConnection()->prepare('SELECT GET_LOCK(?,0)');
        $s->execute(['tf_exec_'.substr(hash('sha256',$key),0,56)]);return (int)$s->fetchColumn()===1;
    }
    public static function unlock(string $key): void
    {
        $s=Database::getInstance()->getConnection()->prepare('SELECT RELEASE_LOCK(?)');$s->execute(['tf_exec_'.substr(hash('sha256',$key),0,56)]);
    }
    public static function run(string $key,int $userId,callable $write): array
    {
        if(!self::lock($key))throw new RuntimeException('Operation busy; retry after current attempt');
        $pdo=Database::getInstance()->getConnection();$hash=hash('sha256',$key);
        try {
            $q=$pdo->prepare('SELECT * FROM external_execution_attempts WHERE operation_key=?');$q->execute([$hash]);$prior=$q->fetch(PDO::FETCH_ASSOC);
            if($prior&&$prior['state']==='completed')return json_decode($prior['result_json'],true,512,JSON_THROW_ON_ERROR);
            if($prior&&in_array($prior['state'],['started','uncertain'],true)){
                $q=$pdo->prepare("UPDATE external_execution_attempts SET state='uncertain',updated_at=NOW() WHERE operation_key=?");$q->execute([$hash]);
                throw new RuntimeException('Outcome uncertain; inspect provider before retrying');
            }
            $q=$pdo->prepare("INSERT INTO external_execution_attempts(operation_key,user_id,operation_label,state,created_at,updated_at) VALUES(?,?,?,'started',NOW(),NOW()) ON DUPLICATE KEY UPDATE state='started',updated_at=NOW()");$q->execute([$hash,$userId,substr($key,0,190)]);
            try {$result=$write();if(!is_array($result))throw new RuntimeException('Invalid external write result');}
            catch(Throwable $e){self::persist($hash,'uncertain',['success'=>false,'error'=>'Outcome uncertain']);throw $e;}
            // Only an explicit rejection establishes that retrying cannot duplicate an external write.
            $code=(int)($result['http_code']??0);
            $state=!empty($result['success'])?'completed':(!empty($result['definitive_failure'])||($code>=400&&$code<500&&$code!==408&&$code!==429)?'failed':'uncertain');
            $safe=array_intersect_key($result,array_flip(['success','error','http_code','post_id','video_id','container_id','publish_id','operation_name','message_id','external_message_id','status','id','definitive_failure']));
            $safe['outcome_state']=$state;self::persist($hash,$state,$safe);return $safe;
        } finally {self::unlock($key);}
    }
    private static function persist(string $hash,string $state,array $result): void
    {
        $s=Database::getInstance()->getConnection()->prepare('UPDATE external_execution_attempts SET state=?,result_json=?,updated_at=NOW() WHERE operation_key=?');$s->execute([$state,json_encode($result,JSON_THROW_ON_ERROR),$hash]);
    }
}
