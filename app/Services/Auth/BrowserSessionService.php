<?php
/** Per-device opaque credentials stored only as hashes. Legacy shared api_token is not a login session. */
class BrowserSessionService
{
    public static function cookie(string $value,int $expires): void
    {
        $url=defined('APP_URL')?(string)APP_URL:(getenv('APP_URL')?:'');
        $host=strtolower((string)parse_url($url,PHP_URL_HOST));
        $secure=!in_array($host,['localhost','127.0.0.1','::1'],true)||str_starts_with(strtolower($url),'https://')||(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off');
        // OAuth callbacks are top-level cross-site GET navigations. Lax permits their redirect chain.
        if($value==='')unset($_COOKIE['auth_token']);else $_COOKIE['auth_token']=$value;
        setcookie('auth_token',$value,['expires'=>$expires,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
    }
    /** أقصى مدة جلسة من غير أي استخدام - بتتجدد مع كل استخدام (كانت ساعة ثابتة من وقت الدخول فالعميل كان بيخرج وهو شغال) */
    public static function idleTtl(): int
    {
        return max(3 * 86400, min(30 * 86400, (int) (defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 0)));
    }

    /** أقصى عمر للجلسة مهما اتجددت - بعده لازم تسجيل دخول تاني */
    public const MAX_AGE = 30 * 86400;

    public static function issue(int $userId): string
    {
        $token='tf_ws_'.bin2hex(random_bytes(32));$ttl=self::idleTtl();
        $s=Database::getInstance()->getConnection()->prepare('INSERT INTO browser_sessions(user_id,token_hash,expires_at,created_at,last_used_at) VALUES(?,?,?,NOW(),NOW())');$s->execute([$userId,hash('sha256',$token),gmdate('Y-m-d H:i:s',time()+$ttl)]);
        $_SESSION['browser_session_hash']=hash('sha256',$token);self::cookie($token,time()+$ttl);return $token;
    }
    public static function validate(string $token): ?array
    {
        if(!preg_match('/^tf_ws_[a-f0-9]{64}$/D',$token))return null;
        $pdo=Database::getInstance()->getConnection();$s=$pdo->prepare("SELECT u.*,b.token_hash session_hash FROM browser_sessions b JOIN users u ON u.id=b.user_id LEFT JOIN user_refresh_tokens r ON r.id=b.refresh_token_id WHERE b.token_hash=? AND b.revoked_at IS NULL AND b.expires_at>NOW() AND u.status='active' AND (b.refresh_token_id IS NULL OR (r.id IS NOT NULL AND r.revoked_at IS NULL AND r.expires_at>NOW()))");$s->execute([hash('sha256',$token)]);$user=$s->fetch(PDO::FETCH_ASSOC);if(!$user)return null;
        // تجديد تلقائي: لو فاضل أقل من نص المدة نمدّها (من غير ما نعدّي أقصى عمر للجلسة)
        $ttl=self::idleTtl();$hash=hash('sha256',$token);
        $s=$pdo->prepare('UPDATE browser_sessions SET last_used_at=NOW(), expires_at=LEAST(DATE_ADD(NOW(), INTERVAL ? SECOND), DATE_ADD(created_at, INTERVAL ? SECOND)) WHERE token_hash=? AND expires_at < DATE_ADD(NOW(), INTERVAL ? SECOND)');
        $s->execute([$ttl,self::MAX_AGE,$hash,intdiv($ttl,2)]);
        if($s->rowCount()>0&&!headers_sent()&&($_COOKIE['auth_token']??'')===$token){$e=$pdo->prepare('SELECT UNIX_TIMESTAMP(expires_at) FROM browser_sessions WHERE token_hash=?');$e->execute([$hash]);self::cookie($token,(int)$e->fetchColumn());}
        else{$s=$pdo->prepare('UPDATE browser_sessions SET last_used_at=NOW() WHERE token_hash=?');$s->execute([$hash]);}unset($user['password_hash'],$user['api_token'],$user['two_factor_secret'],$user['two_factor_recovery_codes'],$user['session_hash']);return $user;
    }
    public static function bindRefresh(string $token,int $id): void
    {
        $s=Database::getInstance()->getConnection()->prepare('UPDATE browser_sessions SET refresh_token_id=? WHERE token_hash=?');$s->execute([$id,hash('sha256',$token)]);
    }
    public static function revoke(?string $token=null): void
    {
        $hash=$token?hash('sha256',$token):($_SESSION['browser_session_hash']??null);if($hash){$s=Database::getInstance()->getConnection()->prepare('UPDATE browser_sessions SET revoked_at=NOW() WHERE token_hash=?');$s->execute([$hash]);}
        unset($_SESSION['browser_session_hash']);self::cookie('',time()-3600);
    }
}
