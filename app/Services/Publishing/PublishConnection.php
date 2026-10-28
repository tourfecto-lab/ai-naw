<?php

/**
 * حفظ ربط النشر (custom_api: Shopify/Webflow/Ghost/Webhook/المدونة المستضافة) في platform_connections.
 * جدول الإنتاج أعمدته مش زي النسخ المحلية، وDatabase::exec بيرجّع false بصمت لو عمود مش موجود -
 * فبنكتب في الأعمدة الموجودة فعلًا بس، وبنتأكد إن الربط اتسجل قبل ما نقول للعميل «اتربط».
 */
final class PublishConnection
{
    private static ?array $cols = null;

    private static function cols(Database $db): array
    {
        if (self::$cols === null) {
            self::$cols = [];
            foreach ((array) $db->query('SHOW COLUMNS FROM `platform_connections`') as $r) {
                $n = strtolower((string) ($r['Field'] ?? ''));
                if ($n !== '') self::$cols[$n] = true;
            }
        }
        return self::$cols;
    }

    /** @return bool الربط متسجل ومتصل فعلًا */
    public static function saveCustomApi(Database $db, int $websiteId, int $userId, string $encryptedSecret, string $endpoint, string $name): bool
    {
        $cols = self::cols($db);
        $now = date('Y-m-d H:i:s');
        $vals = ['access_token' => $encryptedSecret, 'external_location_id' => $endpoint, 'external_location_name' => mb_substr($name, 0, 250), 'status' => 'connected'];
        if (isset($cols['last_error'])) $vals['last_error'] = null;
        if (isset($cols['connected_at'])) $vals['connected_at'] = $now;
        $vals = array_intersect_key($vals, $cols);
        $existing = $db->query("SELECT id FROM platform_connections WHERE website_id = ? AND platform = 'custom_api' ORDER BY id DESC LIMIT 1", [$websiteId]);
        if ($existing) {
            $set = implode(', ', array_map(fn ($k) => "`{$k}` = ?", array_keys($vals)));
            $ok = $db->exec("UPDATE platform_connections SET {$set} WHERE id = ?", array_merge(array_values($vals), [(int) $existing[0]['id']]));
        } else {
            $row = ['website_id' => $websiteId, 'user_id' => $userId, 'platform' => 'custom_api'] + $vals;
            if (isset($cols['created_at'])) $row['created_at'] = $now;
            $row = array_intersect_key($row, $cols);
            $ok = $db->exec('INSERT INTO platform_connections (`' . implode('`, `', array_keys($row)) . '`) VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')', array_values($row));
        }
        $saved = $db->query("SELECT id FROM platform_connections WHERE website_id = ? AND platform = 'custom_api' AND status = 'connected' AND external_location_id = ? ORDER BY id DESC LIMIT 1", [$websiteId, $endpoint]);
        if (!$ok || !$saved) {
            Logger::error('Publish connection not saved', ['website_id' => $websiteId, 'endpoint_kind' => strtok($endpoint, ':'), 'exec_ok' => $ok]);
            return false; // الربط القديم (لو فيه) بيفضل زي ما هو
        }
        // طريقة نشر واحدة للموقع: بعد ما الجديد اتسجل فعلًا، أي ربط تاني بيتوقف
        $db->exec("UPDATE platform_connections SET status = 'disconnected' WHERE website_id = ? AND platform IN ('wordpress','custom_api') AND id <> ?", [$websiteId, (int) $saved[0]['id']]);
        return true;
    }
}
