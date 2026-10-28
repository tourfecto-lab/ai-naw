<?php
/** Display and freeze a price before provider execution. No API call, no upfront charge. */
class CreativePricing
{
    public const STORYBOARD_MAX_SCENES=6;
    /** @param bool $storyboard سكربت → فيديو: عدة لقطات فيديو بنفس الإعدادات في طلب واحد */
    public static function quote(int $userId,string $kind,array $options,int $count=1,int $duration=8,bool $storyboard=false): array
    {
        if($storyboard){if($kind!=='video'||$count<1||$count>self::STORYBOARD_MAX_SCENES)throw new InvalidArgumentException('Invalid scene count');}
        elseif(!in_array($kind,['image','video'],true)||!in_array($count,[1,2],true)||($kind==='video'&&$count!==1))throw new InvalidArgumentException('Invalid quantity');
        $o=GenerationOptions::normalize($options,$kind==='video');
        if($o['reference_id'])ReferenceMedia::load($userId,$o['reference_id']);
        $premium=$kind==='video'&&$o['resolution']!=='720p';
        if($kind==='video'){if(!in_array($duration,[4,6,8],true)||(($premium||$o['reference_id'])&&$duration!==8))throw new InvalidArgumentException('This video setting requires eight seconds');if($premium&&!VeoClient::supportsResolution($o['resolution']))throw new InvalidArgumentException('Resolution not supported by configured model');}
        $feature=$kind==='video'?'media_video':'media_image';$key=$premium?$feature.'_'.$o['resolution']:$feature;
        $q=$premium?['covered'=>false,'remaining'=>0]:PlanUsage::quota($userId,$feature);
        $covered=!empty($q['covered']);$remaining=$q['remaining']??null;$plan=$covered?($remaining===null?$count:min($count,max(0,(int)$remaining))):0;
        $wallet=new WalletService();$pricing=$wallet->getUsagePricing();$price=$pricing[$key]??null;$paid=$count-$plan;
        if($paid&&(!$price||($price['currency']??'USD')!=='USD'||!is_numeric($price['price'])||(float)$price['price']<0||($premium&&(float)$price['price']<=0)))throw new InvalidArgumentException('Pricing unavailable');
        $unit=$paid?round((float)$price['price'],2):0.0;$total=round($unit*$paid,2);$balance=$wallet->getBalance($userId);
        $units=[];for($i=0;$i<$count;$i++)$units[]=['source'=>$i<$plan?'plan':'wallet','amount'=>$i<$plan?0.0:$unit,'currency'=>'USD','key'=>$key,'settled'=>false];
        $data=['kind'=>$kind,'storyboard'=>$storyboard,'count'=>$count,'resolution'=>$o['resolution'],'duration'=>$kind==='video'?$duration:0,'reference_id'=>$o['reference_id'],'plan_units'=>$plan,'wallet_units'=>$paid,'unit_price'=>$unit,'total'=>$total,'currency'=>'USD','units'=>$units];
        $data['hash']=hash('sha256',json_encode([$userId,$data],JSON_THROW_ON_ERROR));$data['ok']=$balance+0.00001>=$total;
        // للعرض بس (خارج الـ hash): العميل يعرف رصيده وناقصه كام بدل رسالة عامة
        $data['balance']=round(max(0,$balance),2);$data['shortfall']=round(max(0,$total-$balance),2);return $data;
    }
    public static function settle(MediaItem $item,array $billing,string $feature): string
    {
        $userId=(int)$item->getAttribute('user_id');
        // Capture releases only this asset's hold inside the enclosing settlement transaction.
        CreativeReservations::validateSettlement($item,$feature);CreativeReservations::capture((int)$item->getAttribute('id'));
$note='Creative media #'.(int)$item->getAttribute('id');
        if(($billing['source']??'')==='plan'){if(!PlanUsage::consume($userId,$feature,$note))throw new RuntimeException('Approved plan quota unavailable');return 'plan';}
        if(($billing['source']??'')!=='wallet'||!is_numeric($billing['amount']??null))throw new RuntimeException('Invalid approved quote');
        $amount=round((float)$billing['amount'],2);if($amount<0)throw new RuntimeException('Invalid quoted amount');
        $pdo=Database::getInstance()->getConnection();$lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $s=$pdo->prepare('SELECT id FROM users WHERE id=?'.$lock);$s->execute([$userId]);if(!$s->fetchColumn())throw new RuntimeException('User unavailable');
        $s=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE user_id=? AND status='completed'");$s->execute([$userId]);if((float)$s->fetchColumn()-CreativeReservations::walletHeld($userId)+0.00001<$amount)throw new RuntimeException('Approved wallet funds unavailable');
        $tx=new WalletTransaction(['user_id'=>$userId,'type'=>'subscription_charge','amount'=>-$amount,'currency'=>'USD','status'=>'completed','reference_note'=>$note,'feature_key'=>$billing['key'],'approved_at'=>date('Y-m-d H:i:s'),'idempotency_key'=>'creative_media_'.(int)$item->getAttribute('id')]);if(!$tx->save())throw new RuntimeException('Creative charge persistence failed');return 'wallet';
    }
}
