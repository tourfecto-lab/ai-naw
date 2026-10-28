<?php

/**
 * Tourfecto - GBP Audit Logger
 * تسجيل بسيط وآمن لعمليات موديول GBP المهمة (بند 16 بالسبيك الأصلي).
 * الحماية الأساسية هنا: blocklist صريح لأي مفتاح اسمه فيه إشارة لـ
 * secret/token قبل حتى ما نحاول نسجّله - دفاع إضافي فوق الانضباط
 * اليدوي في باقي الكود (اللي أصلاً بيتجنب تمرير التوكنات للـ logger).
 * @version 1.0.0
 * @since 2026-08-14 (GBP Module Upgrade - Round 7: Production Finalization)
 */
class GbpAuditLogger
{
    /** أي مفتاح فيه واحدة من الكلمات دي بيتشال تلقائيًا من الـ details قبل التسجيل */
    private const FORBIDDEN_KEY_PATTERNS = ['token', 'secret', 'password', 'client_secret', 'refresh_token', 'access_token', 'authorization'];

    public static function log(string $action, ?int $websiteId, ?int $userId, string $status = 'success', array $details = []): void
    {
        try {
            $safeDetails = self::stripSecrets($details);

            Database::getInstance()->query(
                "INSERT INTO gbp_audit_log (website_id, user_id, action, status, details, ip_address, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())",
                [
                    $websiteId, $userId, $action, $status,
                    json_encode($safeDetails, JSON_UNESCAPED_UNICODE),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                ]
            );
        } catch (Throwable $e) {
            // فشل تسجيل الـ Audit Log منظامش يوقف العملية الأساسية نفسها -
            // بس نسجّله في الـ Logger العادي عشان نعرف لو الجدول مش موجود لسه.
            if (class_exists('Logger')) {
                Logger::error('GBP audit log write failed', ['error' => $e->getMessage(), 'action' => $action]);
            }
        }
    }

    private static function stripSecrets(array $details): array
    {
        $clean = [];
        foreach ($details as $key => $value) {
            $lowerKey = strtolower((string) $key);
            $isForbidden = false;
            foreach (self::FORBIDDEN_KEY_PATTERNS as $pattern) {
                if (strpos($lowerKey, $pattern) !== false) {
                    $isForbidden = true;
                    break;
                }
            }
            if ($isForbidden) {
                continue;
            }
            $clean[$key] = is_array($value) ? self::stripSecrets($value) : $value;
        }
        return $clean;
    }
}
