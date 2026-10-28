<?php
/** Expired exports only; never follows a stored path outside the private export directory. */
class ExportRetentionService
{
    public static function safePath(string $storedPath, int $userId, ?string $directory = null): ?string
    {
        $directory = $directory ?? TOURFECTO_STORAGE . '/exports';
        if (is_link($directory)) return null;
        $root = realpath($directory);
        if ($root === false || is_link($storedPath)) return null;
        $path = realpath($storedPath);
        if ($path === false || dirname($path) !== $root || !is_file($path)) return null;
        if (!preg_match('/^export_' . preg_quote((string)$userId, '/') . '_[a-zA-Z0-9_-]+\.json$/D', basename($path))) return null;
        return $path;
    }

    public static function cleanup(Database $db, ?string $directory = null, int $limit = 300): array
    {
        $pdo=$db->getConnection();
        $limit=max(1,min(1000,$limit));
        $rows=$pdo->query("SELECT id FROM data_export_requests WHERE expires_at IS NOT NULL AND expires_at <= CURRENT_TIMESTAMP AND file_path IS NOT NULL ORDER BY expires_at, id LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC);
        $out=['examined'=>count($rows),'deleted'=>0,'cleared'=>0,'unsafe'=>0,'failed'=>0];
        foreach ($rows as $row) {
            try {
                $result=$db->transaction(function() use($pdo,$row,$directory) {
                    $sql='SELECT * FROM data_export_requests WHERE id=? AND expires_at <= CURRENT_TIMESTAMP';
                    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') $sql.=' FOR UPDATE';
                    $stmt=$pdo->prepare($sql);$stmt->execute([(int)$row['id']]);$export=$stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$export || empty($export['file_path'])) return ['deleted'=>0,'cleared'=>0];
                    $stored=(string)$export['file_path'];
                    $safe=self::safePath($stored,(int)$export['user_id'],$directory);
                    // An absent file can be detached, but links and existing unsafe paths cannot.
                    if ($safe===null && (file_exists($stored)||is_link($stored))) return ['unsafe'=>1];
                    $deleted=0;
                    if ($safe!==null) {if(!unlink($safe)) throw new RuntimeException('Cannot remove expired export');$deleted=1;}
                    $stmt=$pdo->prepare('UPDATE data_export_requests SET file_path=NULL WHERE id=?');$stmt->execute([(int)$export['id']]);
                    return ['deleted'=>$deleted,'cleared'=>1];
                });
                foreach ($result as $key=>$n) $out[$key]+=$n;
            } catch(Throwable $e) { $out['failed']++;if(class_exists('Logger'))Logger::error('Expired export cleanup failed',['request_id'=>(int)$row['id']]); }
        }
        return $out;
    }
}
