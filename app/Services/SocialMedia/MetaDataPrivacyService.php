<?php

/**
 * Tourfecto - تنفيذ طلبات الخصوصية من Meta.
 *
 *  Deauthorize : المستخدم شال التطبيق من إعدادات فيسبوك -> نفصل اتصالاته ونوقف منشوراته المجدولة.
 *  Data Deletion: المستخدم طلب حذف بياناته -> نفس الفصل + نمسح التوكنات المخزنة ونحذف صحة الاتصال، ونرجّع كود تأكيد.
 *
 * المطابقة بين مستخدم فيسبوك والاتصالات بتتم عن طريق social_connection_health.fb_user_id (بيتملي من debug_token
 * في الفحص اليومي). لو مفيش مطابقة (الفحص لسه ما اشتغلش للاتصال ده) الطلب بيتسجّل 'no_matching_connection' بصراحة
 * ومش بنقول إننا حذفنا حاجة. التوكنات القديمة بتفشل عند Meta أصلًا بعد الإلغاء.
 * المسح بيتم على جدول platform_connections المشترك بـ status='disconnected' و access_token='' بس (من غير ما نخمّن أعمدة تانية).
 * @version 1.0.0
 */
class MetaDataPrivacyService
{
    public static function connectionIdsForUser(string $fbUserId): array
    {
        if ($fbUserId === '' || !preg_match('/^[0-9]{1,40}$/', $fbUserId)) {
            return [];
        }
        $rows = Database::getInstance()->query('SELECT platform_connection_id FROM social_connection_health WHERE fb_user_id = ?', [$fbUserId]) ?: [];
        return array_values(array_unique(array_map(static fn ($r) => (int) $r['platform_connection_id'], $rows)));
    }

    /** @return array ['connections'=>int,'status'=>'completed'|'no_matching_connection','code'=>string] */
    public static function deauthorize(string $fbUserId): array
    {
        return self::process('deauthorize', $fbUserId, false);
    }

    public static function requestDeletion(string $fbUserId): array
    {
        return self::process('deletion', $fbUserId, true);
    }

    /** @return array|null ['status','type','created_at'] */
    public static function status(string $code): ?array
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $code)) {
            return null;
        }
        $r = Database::getInstance()->query('SELECT status, type, created_at FROM meta_data_requests WHERE confirmation_code = ? LIMIT 1', [$code]);
        return $r ? $r[0] : null;
    }

    private static function process(string $type, string $fbUserId, bool $purge): array
    {
        $db = Database::getInstance();
        $ids = self::connectionIdsForUser($fbUserId);
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            $reason = $purge ? 'اتحذفت البيانات بناءً على طلب المستخدم من فيسبوك' : 'المستخدم ألغى تفويض التطبيق من إعدادات فيسبوك - اربط الصفحة تاني';
            $db->query("UPDATE platform_connections SET status = 'disconnected', access_token = '', last_error = ? WHERE id IN ({$in})", [$reason]);
            // المنشورات اللي لسه ما اتنشرتش على الاتصالات دي ما ينفعش تفضل "مجدولة" لحد ما تفشل لوحدها
            $db->query("UPDATE social_post_targets SET status = 'failed', last_error = ? WHERE platform_connection_id IN ({$in}) AND status IN ('scheduled', 'pending')", ['الاتصال اتفصل (إلغاء تفويض من فيسبوك) - اربط الصفحة وأعد النشر']);
            // بيانات الصحة (فيها معرّف مستخدم فيسبوك) تتمسح في الحالتين؛ ما بقاش ليها لازمة
            $db->query("DELETE FROM social_connection_health WHERE platform_connection_id IN ({$in})");
        }
        $code = bin2hex(random_bytes(12));
        $status = $ids ? 'completed' : 'no_matching_connection';
        $db->query(
            'INSERT INTO meta_data_requests (confirmation_code, fb_user_hash, type, status, connections_affected) VALUES (?, ?, ?, ?, ?)',
            [$code, hash('sha256', $fbUserId), $type, $status, count($ids)]
        );
        return ['connections' => count($ids), 'status' => $status, 'code' => $code];
    }
}
