<?php
/** Cross-service spendable wallet balance and shared-subscription quota reservations. */
class CreativeReservations
{
    private static function sum(string $sql,array $args): float
    {
        $pdo=Database::getInstance()->getConnection();try{$s=$pdo->prepare($sql);$s->execute($args);return (float)$s->fetchColumn();}catch(PDOException $e){
            // A pre-migration installation must keep unrelated billing available.
            if(in_array((string)$e->getCode(),['42S02','HY000'],true)&&preg_match('/(no such table|doesn.t exist|not found)/i',$e->getMessage()))return 0;throw $e;
        }
    }
    public static function walletSnapshot(int $userId): array
    {
        $pdo=Database::getInstance()->getConnection();try{$s=$pdo->prepare("SELECT COALESCE(SUM(amount),0) ledger_balance,(SELECT COALESCE(SUM(amount),0) FROM creative_reservations WHERE user_id=? AND source='wallet' AND status='held') reserved FROM wallet_transactions WHERE user_id=? AND status='completed'");$s->execute([$userId,$userId]);$row=$s->fetch(PDO::FETCH_ASSOC);$gross=(float)$row['ledger_balance'];$held=(float)$row['reserved'];}
        catch(PDOException $e){if(!in_array((string)$e->getCode(),['42S02','HY000'],true)||!preg_match('/(no such table|doesn.t exist|not found)/i',$e->getMessage()))throw $e;$s=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE user_id=? AND status='completed'");$s->execute([$userId]);$gross=(float)$s->fetchColumn();$held=0.0;}
        return ['ledger_balance'=>$gross,'reserved'=>$held,'balance'=>round($gross-$held,2)];
    }
    public static function walletHeld(int $userId): float{return self::sum("SELECT COALESCE(SUM(amount),0) FROM creative_reservations WHERE user_id=? AND source='wallet' AND status='held'",[$userId]);}
    public static function planHeld(int $subscriptionId,string $feature): int{return (int)self::sum("SELECT COUNT(*) FROM creative_reservations WHERE subscription_id=? AND feature_key=? AND source='plan' AND status='held'",[$subscriptionId,$feature]);}
    public static function reserve(MediaItem $item,array $billing): void
    {
        $pdo=Database::getInstance()->getConnection();$lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$user=(int)$item->getAttribute('user_id');$id=(int)$item->getAttribute('id');
        $s=$pdo->prepare('SELECT id FROM users WHERE id=?'.$lock);$s->execute([$user]);if(!$s->fetchColumn())throw new RuntimeException('Owner unavailable');
        $s=$pdo->prepare('SELECT status,subscription_id,period_start FROM creative_reservations WHERE media_item_id=?');$s->execute([$id]);$prior=$s->fetch(PDO::FETCH_ASSOC);$state=$prior['status']??null;if($state==='held')return;if($state==='captured')throw new RuntimeException('Reservation already captured');
        $sub=null;$period=null;$source=$billing['source']??'';$feature=$item->getAttribute('type')==='short_video'?'media_video':'media_image';
        if($source==='plan'){$q=PlanUsage::quota($user,$feature);$sub=(int)($q['subscription_id']??0);$period=$q['period_start']??null;if($prior&&((int)$prior['subscription_id']!==$sub||($prior['period_start']??null)!==$period))throw new RuntimeException('Original reservation period changed');if($sub<1)throw new RuntimeException('Subscription unavailable');$s=$pdo->prepare('SELECT id FROM subscriptions WHERE id=?'.$lock);$s->execute([$sub]);if(!$s->fetchColumn()||empty(PlanUsage::quota($user,$feature)['covered']))throw new RuntimeException('Plan reservation unavailable');}
        elseif($source==='wallet'){if((new WalletService())->getBalance($user)+0.00001<(float)$billing['amount'])throw new RuntimeException('Wallet reservation unavailable');}else throw new RuntimeException('Invalid reservation source');
        if($state){$s=$pdo->prepare("UPDATE creative_reservations SET status='held',source=?,amount=?,subscription_id=?,period_start=?,feature_key=?,updated_at=NOW() WHERE media_item_id=?");$s->execute([$source,$billing['amount'],$sub,$period,$billing['key'],$id]);}
        else{$s=$pdo->prepare("INSERT INTO creative_reservations(media_item_id,user_id,subscription_id,period_start,feature_key,source,amount,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,'held',NOW(),NOW())");$s->execute([$id,$user,$sub,$period,$billing['key'],$source,$billing['amount']]);}
    }
    public static function validateSettlement(MediaItem $item,string $feature): void
    {
        $pdo=Database::getInstance()->getConnection();$s=$pdo->prepare('SELECT * FROM creative_reservations WHERE media_item_id=?');$s->execute([(int)$item->getAttribute('id')]);$row=$s->fetch(PDO::FETCH_ASSOC);$meta=json_decode((string)$item->getAttribute('generation_meta'),true)?:[];
        if(!empty($meta['reservation_required'])&&(!$row||$row['status']!=='held'))throw new RuntimeException('Approved reservation unavailable');
        if($row&&$row['source']==='plan'){$q=PlanUsage::quota((int)$item->getAttribute('user_id'),$feature);if((int)($q['subscription_id']??0)!==(int)$row['subscription_id']||($q['period_start']??null)!==($row['period_start']??null))throw new RuntimeException('Approved subscription period changed');}
    }
    public static function capture(int $id): bool
    {
        $pdo=Database::getInstance()->getConnection();$s=$pdo->prepare("UPDATE creative_reservations SET status='captured',updated_at=NOW() WHERE media_item_id=? AND status='held'");$s->execute([$id]);return $s->rowCount()===1;
    }
    public static function release(int $id): void
    {
        $s=Database::getInstance()->getConnection()->prepare("UPDATE creative_reservations SET status='released',updated_at=NOW() WHERE media_item_id=? AND status='held'");$s->execute([$id]);
    }
}
