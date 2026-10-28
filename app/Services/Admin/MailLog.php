<?php

/**
 * Tourfecto - سجل البريد
 *
 * كل إيميل المنصة بتبعته (أو بتفشل تبعته) بيتسجّل هنا من Mailer::send().
 * لوحة الأدمن ← سجل البريد بتعرضه، ومنه تقدر تعيد الإرسال.
 *
 * - التسجيل عمره ما يوقّف الإرسال: أي خطأ هنا بيتبلع.
 * - جسم الرسالة بيتمسح بعد BODY_DAYS، والسجل كله بعد KEEP_DAYS (cleanup()).
 *
 * @version 1.0.0
 */
class MailLog
{
    public const BODY_DAYS = 14;
    public const KEEP_DAYS = 90;

    /** @var bool لو الجدول مش موجود (الـ migration ماتشغّلش) نبطّل نحاول في نفس الطلب */
    private static $disabled = false;

    public static function record(array $row): void
    {
        if (self::$disabled) {
            return;
        }
        try {
            $db = Database::getInstance();
            $userId = $row['user_id'] ?? null;
            if (!$userId && !empty($row['to_email'])) {
                $u = $db->query('SELECT id FROM users WHERE email = ? LIMIT 1', [(string) $row['to_email']]);
                $userId = $u[0]['id'] ?? null;
            }
            $body = $row['body_html'] ?? null;
            if (is_string($body) && strlen($body) > 400000) {
                $body = null; // رسالة ضخمة: مانخزنهاش
            }
            $db->query(
                'INSERT INTO mail_log (to_email, to_name, subject, body_html, status, error, context, smtp_host, from_email, user_id, resent_from_id, duration_ms)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    mb_substr((string) $row['to_email'], 0, 255),
                    $row['to_name'] !== null ? mb_substr((string) $row['to_name'], 0, 255) : null,
                    mb_substr((string) $row['subject'], 0, 500),
                    $body,
                    $row['status'] === 'sent' ? 'sent' : 'failed',
                    isset($row['error']) && $row['error'] !== null ? mb_substr((string) $row['error'], 0, 1000) : null,
                    $row['context'] ?? null,
                    $row['smtp_host'] ?? null,
                    $row['from_email'] ?? null,
                    $userId ? (int) $userId : null,
                    $row['resent_from_id'] ?? null,
                    $row['duration_ms'] ?? null,
                ]
            );
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'mail_log') !== false) {
                self::$disabled = true;
            }
        }
    }

    /** إحصائيات سريعة لصفحة صحة المنصة والتنبيهات */
    public static function stats(int $hours = 24): array
    {
        try {
            $r = Database::getInstance()->query(
                "SELECT COUNT(*) total, SUM(status = 'sent') sent, SUM(status = 'failed') failed,
                        MAX(CASE WHEN status = 'sent' THEN created_at END) last_sent_at,
                        MAX(CASE WHEN status = 'failed' THEN created_at END) last_failed_at
                   FROM mail_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)",
                [$hours]
            );
            $r = $r[0] ?? [];
            $lastErr = Database::getInstance()->query("SELECT error FROM mail_log WHERE status = 'failed' ORDER BY id DESC LIMIT 1");
            return [
                'total' => (int) ($r['total'] ?? 0),
                'sent' => (int) ($r['sent'] ?? 0),
                'failed' => (int) ($r['failed'] ?? 0),
                'last_sent_at' => $r['last_sent_at'] ?? null,
                'last_failed_at' => $r['last_failed_at'] ?? null,
                'last_error' => $lastErr[0]['error'] ?? null,
            ];
        } catch (Throwable $e) {
            return ['total' => 0, 'sent' => 0, 'failed' => 0, 'last_sent_at' => null, 'last_failed_at' => null, 'last_error' => null, 'unavailable' => true];
        }
    }

    public static function cleanup(): void
    {
        try {
            $db = Database::getInstance();
            $db->exec('UPDATE mail_log SET body_html = NULL WHERE body_html IS NOT NULL AND created_at < DATE_SUB(NOW(), INTERVAL ' . self::BODY_DAYS . ' DAY)');
            $db->exec('DELETE FROM mail_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . self::KEEP_DAYS . ' DAY)');
        } catch (Throwable $e) {
        }
    }
}
