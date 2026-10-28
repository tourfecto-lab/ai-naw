<?php

/**
 * Tourfecto - الروابط القصيرة للمنشورات (/go/{code}): بتعدّ النقرة وتحفظ إسناد الحجز وتحوّل لموقع العميل.
 * عام (من غير تسجيل دخول) لأن زوار السوشيال هم اللي بيفتحوه.
 */
require_once __DIR__ . '/../Services/SocialMedia/SocialAttribution.php';
class SocialLinkController extends Controller
{
    public function go(array $params = []): void
    {
        $dest = null;
        try {
            $dest = SocialAttribution::click((string) ($params['code'] ?? ''));
        } catch (Throwable $e) {
            Logger::warning('Social short link failed', ['code' => substr((string) ($params['code'] ?? ''), 0, 16)]);
        }
        if (!$dest || !preg_match('#^https?://#i', $dest)) {
            $dest = rtrim(defined('APP_URL') ? (string) APP_URL : '', '/') . '/';
        }
        if (!headers_sent()) {
            header('Cache-Control: no-store');
            header('Referrer-Policy: no-referrer-when-downgrade');
            header('Location: ' . $dest, true, 302);
        }
        exit; // زي روابط /r/ الإعلانية: لو رجّعنا للراوتر هيحوّل الحالة لـ 200
    }
}
