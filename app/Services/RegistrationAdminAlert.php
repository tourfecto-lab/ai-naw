<?php
/** Owner-authorized account alerts. One outbox entry per new user; no password/token fields. */
class RegistrationAdminAlert
{
    public const RECIPIENT='tourfectoai@gmail.com';
    public static function enqueue(int $userId,string $method): void{
        try{require_once __DIR__.'/AccountAlertLocation.php';$ip=AccountAlertLocation::capture();$db=Database::getInstance();$db->transaction(function()use($db,$userId,$method,$ip){$pdo=$db->getConnection();$s=$pdo->prepare("INSERT IGNORE INTO registration_admin_alerts(user_id,recipient,signup_method,status,created_at,updated_at) VALUES(?,?,?,'queued',UTC_TIMESTAMP(),UTC_TIMESTAMP())");$s->execute([$userId,self::RECIPIENT,mb_substr($method,0,30)]);if(!$s->rowCount())return;if(!(new QueueManager($db))->push(SendRegistrationAdminAlertJob::class,['user_id'=>$userId,'request_ip'=>$ip],'email'))throw new RuntimeException('Signup alert queue unavailable');});}
        catch(Throwable $e){Logger::warning('Signup admin alert could not be queued',['user_id'=>$userId]);}
    }
    public static function body(array $user,string $method,array $location=[]): string{
        $escape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$name=trim(($user['first_name']??'').' '.($user['last_name']??''));$rows=['رقم الحساب'=>$user['id']??'','اسم العميل'=>$name,'اسم الشركة'=>$user['company_name']??'','البريد الإلكتروني'=>$user['email']??'','طريقة التسجيل'=>$method==='password'?'بريد وكلمة مرور':$method,'تاريخ التسجيل (UTC)'=>$user['created_at']??gmdate('Y-m-d H:i:s')];$rows+=['عنوان IP'=>$location['ip']??'غير متاح','المدينة التقريبية'=>$location['city']??'غير متاحة','البلد التقريبي'=>$location['country']??'غير متاح'];$html='<div dir="rtl"><h2>حساب جديد على Tourfecto.pro</h2><table>';foreach($rows as $label=>$value)$html.='<tr><th style="text-align:right;padding:8px">'.$escape($label).'</th><td style="padding:8px">'.$escape($value).'</td></tr>';return $html.'</table><p>الموقع الجغرافي تقريبي من عنوان IP وقد يعكس VPN أو شبكة الاتصال.</p><p><a href="https://tourfecto.pro/admin">فتح لوحة الإدارة</a></p></div>';
    }
    public static function send(int $userId,string $requestIp=''): void{
        $db=Database::getInstance();$pdo=$db->getConnection();$s=$pdo->prepare("UPDATE registration_admin_alerts SET status='sending',updated_at=UTC_TIMESTAMP() WHERE user_id=? AND status='queued'");$s->execute([$userId]);if(!$s->rowCount())return; // A sending/uncertain delivery is never blindly replayed.
        $state='uncertain';$error=null;
        try{require_once __DIR__.'/AccountAlertLocation.php';$location=AccountAlertLocation::lookup($requestIp);$s=$pdo->prepare('SELECT recipient,signup_method FROM registration_admin_alerts WHERE user_id=?');$s->execute([$userId]);$alert=$s->fetch(PDO::FETCH_ASSOC);$user=(new User())->find($userId);if(!$user)throw new RuntimeException('User unavailable');$result=(new Mailer())->send($alert['recipient'],'Tourfecto','حساب جديد على Tourfecto.pro',self::body($user->toArray(),$alert['signup_method'],$location));$state=!empty($result['success'])?'sent':'failed';if($state==='failed')$error='SMTP did not confirm delivery. Check the mail log before resending.';}
        catch(Throwable $e){$error='Sending outcome requires review. Check the mail log before resending.';Logger::warning('Signup admin email requires review',['user_id'=>$userId]);}
        $s=$pdo->prepare('UPDATE registration_admin_alerts SET status=?,error_message=?,sent_at=?,updated_at=UTC_TIMESTAMP() WHERE user_id=?');$s->execute([$state,$error,$state==='sent'?gmdate('Y-m-d H:i:s'):null,$userId]);
    }
}
