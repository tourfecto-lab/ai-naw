<?php
/** Public error messages. Technical diagnostics stay in redacted server logs. */
class CustomerError
{
    public static function message(string $message, int $status = 400): string
    {
        $plain = trim(strip_tags(html_entity_decode($message, ENT_QUOTES, 'UTF-8')));
        $technical = preg_match('/SQLSTATE|PDOException|Exception\b|stack trace|Fatal error|Warning:|UNAUTHENTICATED|invalid.authentication|invalid.credentials|invalid_grant|access_token|refresh_token|client_secret|client_id|redirect_uri|\.env\b|HTTP\s*\d{3}|https?:\/\/|\/var\/|\/home\/|curl|cURL|API\s*error|token exchange|تفاصيل تقنية|تبادل التوكن|بيانات OAuth|CLIENT_KEY|CLIENT_SECRET|\b[A-Z][A-Z0-9]+_(?:API_KEY|KEY|SECRET|TOKEN|JSON|ID|ENABLED)\b/', $plain) || preg_match('/\.env\b/i', $plain);
        if (!$technical && $status < 500 && $plain !== '' && strlen($plain) <= 600) { return $plain; }
        if (class_exists('Logger')) { Logger::warning('Customer operation failed', ['status' => $status, 'message' => $message]); }
        if (preg_match('/UNAUTHENTICATED|invalid.authentication|invalid.credentials|invalid_grant|access_token|refresh_token|تبادل التوكن/i', $plain)) {
            return 'تعذر التحقق من اتصال الحساب. أعد ربط الحساب ثم حاول مرة أخرى.';
        }
        // خدمة محتاجة مفتاح/إعداد من الأدمن: العميل يعرف إنها مش غلطته وإن الحل عند فريق المنصة
        if (preg_match('/\.env\b|\b[A-Z][A-Z0-9]+_(?:API_KEY|KEY|SECRET|TOKEN|JSON|ID|ENABLED)\b|غير مُ?هيأ|غير مُ?فعّلة|مش مفعّل من إدارة النظام/u', $plain)) {
            return 'الخدمة دي لسه محتاجة تفعيل من فريق المنصة. بلّغنا وهنفعّلها لك في أقرب وقت.';
        }
        if ($status === 401 || $status === 419) { return 'انتهت جلستك. سجّل الدخول ثم حاول مرة أخرى.'; }
        if ($status === 403) { return 'ليس لديك إذن لإتمام هذه العملية.'; }
        if ($status === 429) { return 'هناك طلبات كثيرة الآن. انتظر قليلًا ثم حاول مرة أخرى.'; }
        return 'تعذر إتمام العملية الآن. حاول مرة أخرى، وإذا استمرت المشكلة تواصل مع الدعم.';
    }

    public static function details(array $details): array
    {
        foreach ($details as $key => $value) {
            if (preg_match('/^(trace|stack|file|line|sql|query|exception|technical.*|debug|access_token|refresh_token|client_secret|password|api_key)$/i', (string) $key)) {
                unset($details[$key]);
            } elseif (is_array($value)) {
                $details[$key] = self::details($value);
            } elseif (is_string($value)) {
                $details[$key] = self::message($value);
            }
        }
        return $details;
    }

    public static function oauthMessage(string $message): string
    {
        // Provider responses and configuration names must never become HTML output.
        return self::message($message, 400);
    }
}
