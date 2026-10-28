<?php
/** Local heartbeat and operational diagnostics; never returns credentials. */
class RuntimeHealthService
{
    private static function heartbeatPath(): string
    {
        $base = defined('TOURFECTO_STORAGE') ? TOURFECTO_STORAGE : dirname(__DIR__, 2) . '/storage';
        return $base . '/cache/queue-heartbeat.json';
    }

    public static function heartbeat(string $status, array $counts = []): void
    {
        $path = self::heartbeatPath();
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0750, true) && !is_dir(dirname($path))) { throw new RuntimeException('Heartbeat storage unavailable'); }
        $temp = $path . '.' . bin2hex(random_bytes(6));
        $json = json_encode(['time' => time(), 'status' => $status, 'counts' => $counts], JSON_THROW_ON_ERROR);
        if (file_put_contents($temp, $json, LOCK_EX) === false || !rename($temp, $path)) { @unlink($temp); throw new RuntimeException('Heartbeat write failed'); }
        chmod($path, 0640);
    }

    public static function snapshot(Database $db, int $ownerId, bool $admin): array
    {
        $data = [];
        if ($admin) {
            $data += self::operationalChecks($db);
            $heart = is_file(self::heartbeatPath()) ? json_decode((string) file_get_contents(self::heartbeatPath()), true) : null;
            $age = is_array($heart) ? max(0, time() - (int) ($heart['time'] ?? 0)) : null;
            $data['queue_cron'] = ['status' => $age === null ? 'missing' : ($age > 300 ? 'stale' : ($heart['status'] ?? 'unknown')), 'age_seconds' => $age];
            try {
                $data['queue'] = $db->query("SELECT status, COUNT(*) AS count, MIN(available_at) AS oldest_available_at FROM jobs GROUP BY status");
                $data['queue_overdue'] = (int) ($db->query("SELECT COUNT(*) AS n FROM jobs WHERE (status = 'pending' AND available_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)) OR (status = 'processing' AND reserved_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))")[0]['n'] ?? 0);
                $data['refunds_processing'] = (int) ($db->query("SELECT COUNT(*) AS n FROM refunds WHERE status = 'processing'")[0]['n'] ?? 0);
            } catch (Throwable $e) { $data['queue_status'] = 'unavailable'; }
        }
        try {
            $data['integrations'] = $db->query('SELECT platform, status, COUNT(*) AS count FROM platform_connections WHERE user_id = ? GROUP BY platform, status', [$ownerId]);
        } catch (Throwable $e) { $data['integrations_status'] = 'unavailable'; }
        $configured = defined('MAIL_HOST') && MAIL_HOST !== '' && defined('MAIL_USERNAME') && MAIL_USERNAME !== '' && defined('MAIL_PASSWORD') && MAIL_PASSWORD !== '';
        $data['email'] = ['configuration' => $configured ? 'configured' : 'incomplete', 'delivery_verified' => false];
        return $data;
    }

    public static function operationalChecks(Database $db): array
    {
        $base=defined('TOURFECTO_STORAGE')?TOURFECTO_STORAGE:dirname(__DIR__,2).'/storage';
        $stamp=trim((string)@file_get_contents($base.'/cron/heartbeat'));
        $ts=$stamp!==''?strtotime($stamp):false;
        $age=$ts===false?null:time()-$ts;
        $out=['scheduler'=>['status'=>$age===null?'missing':($age<0?'invalid':($age>300?'stale':'ok')),'age_seconds'=>$age]];
        $state=json_decode((string)@file_get_contents($base.'/cron/state.json'),true);
        $overrides=json_decode((string)@file_get_contents($base.'/cron/overrides.json'),true);
        foreach (['trip_reminders'=>2400,'cleanup_exports'=>10800] as $key=>$maxAge) {
            $s=is_array($state)?($state[$key]??[]):[];
            $last=!empty($s['last_end'])?strtotime((string)$s['last_end']):false;
            $out['scheduled_tasks'][$key]=['status'=>is_array($overrides)&&array_key_exists($key,$overrides)&&!$overrides[$key]?'disabled':($last===false?'missing':((int)($s['last_exit']??1)!==0?'failed':(time()-$last>$maxAge?'stale':'ok'))),'last_exit'=>isset($s['last_exit'])?(int)$s['last_exit']:null];
        }
        try {
            $pdo=$db->getConnection();
            $r=$pdo->query("SELECT SUM(CASE WHEN reminder_status='manual_review' THEN 1 ELSE 0 END) AS needs_review, SUM(CASE WHEN reminder_status='failed' AND reminder_attempts>=3 THEN 1 ELSE 0 END) AS exhausted FROM bookings")->fetch(PDO::FETCH_ASSOC);
            $out['reminder_delivery']=['status'=>'available','needs_review'=>(int)($r['needs_review']??0),'exhausted'=>(int)($r['exhausted']??0)];
        } catch(Throwable $e) { $out['reminder_delivery']=['status'=>'unavailable']; }
        return $out;
    }
}
