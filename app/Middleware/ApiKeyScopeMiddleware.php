<?php

/**
 * Tourfecto - API Key Scope Middleware
 * يفرض الصلاحيات (Scopes) على الطلبات الجاية عبر مفاتيح API الشخصية.
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 *
 * كيف يعمل:
 * - الطلبات الجاية بمفتاح API (`tf_pk_...`) بتتعرف من AuthMiddleware اللي
 *   بيخزّن السياق في `$_SERVER['auth_method'] = 'api_key'` +
 *   `$_SERVER['auth_api_key_scopes']` (JSON أو NULL للوصول الكامل).
 * - هنا نتحقق إن المفتاح فعلاً عنده الصلاحية المطلوبة. لو مفيش، نرفض
 *   بـ 403.
 * - الطلبات الجاية بجلسة عادية (متصفح) مش متأثرة - المستخدم في المتصفح
 *   عنده وصول كامل (Same-Origin). دي خاصة بتقييد المفاتيح بس، نفس فلسفة
 *   GitHub Fine-grained PATs.
 *
 * الاستخدام في routes: ['AuthMiddleware', 'ApiKeyScopeMiddleware:audit:read']
 * (الصيغة اللي Router بيدعمها: 'ClassName:modifier').
 */

class ApiKeyScopeMiddleware
{
    /** @var string|null $requiredScope - الصلاحية المطلوبة لهذا المسار */
    private $requiredScope = null;

    /**
     * الصلاحية المطلوبة للمسار (تُنادى من الـ Router عبر الصيغة
     * 'ApiKeyScopeMiddleware:audit:read').
     */
    public function applyModifier(string $modifier): self
    {
        $this->requiredScope = trim($modifier);
        return $this;
    }

    /**
     * @return array|null null = مسموح، array = رفض
     */
    public function handle(): ?array
    {
        // مش طلب بمفتاح API أصلاً (جلسة عادية في المتصفح) - منفرضش
        // أي صلاحية هنا، المستخدم في المتصفح عنده وصول كامل.
        if (($_SERVER['auth_method'] ?? null) !== 'api_key') {
            return null;
        }

        $required = $this->requiredScope;
        if ($required === null || $required === '') {
            return null; // مفيش صلاحية مطلوبة صراحة - سماح
        }

        $scopesJson = $_SERVER['auth_api_key_scopes'] ?? null;

        if (!class_exists('UserApiKey') || !UserApiKey::hasScope($scopesJson, $required)) {
            http_response_code(403);
            return [
                'success' => false,
                'error' => 'Insufficient scope: ' . $required,
                'code' => 403,
            ];
        }

        return null;
    }
}
