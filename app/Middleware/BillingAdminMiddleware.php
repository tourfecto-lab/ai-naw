<?php

/**
 * Tourfecto - Billing Admin Middleware
 * صلاحيات الفوترة (Billing Admin): بيسمح لـ super_admin/admin (زي أي مكان
 * تاني) + manager (الدور المتوسط الموجود بالفعل في users.role ENUM) بالوصول
 * لعمليات الفوترة اليومية (موافقة/رفض إيداعات، توليد بطاقات شحن، أسعار
 * الاستخدام الفردي) من غير ما يحتاج صلاحية Admin كاملة على كل لوحة التحكم.
 *
 * ملحوظة مهمة: العمليات الأخطر (تغيير IBAN/PayPal اللي بتستلم فيها الفلوس
 * فعليًا) لسه محمية بـ AdminMiddleware العادي بس (super_admin/admin فقط) -
 * عشان لو حساب manager اتخترق، أقصى ضرر ممكن هو موافقة/رفض إيداعات
 * ومراجعتها لاحقًا، مش تحويل وجهة استلام الفلوس نفسها.
 *
 * @version 1.0.0
 * @date 2026-08-09
 */
class BillingAdminMiddleware
{
    /** الأدوار المسموح لها بعمليات الفوترة اليومية */
    /**
     * أمان (2026-10): كان بيسمح بـ manager/agent، لكن 20 من 22 عميل دورهم manager،
     * فأي عميل كان يقدر يعتمد إيداعاته بنفسه ويشوف إحصائيات الإيرادات وكروت الشحن.
     * الفوترة بقت للأدمن بس، والصلاحية الدقيقة (billing.*) بتتفحص في AdminAccess.
     */
    private const ALLOWED_ROLES = ['super_admin', 'admin'];

    public function handle(): ?array
    {
        $user = $_SESSION['user'] ?? null;

        if (!$user && isset($_SERVER['auth_user'])) {
            $user = $_SERVER['auth_user'];
        }

        if (!$user) {
            http_response_code(401);
            return ['success' => false, 'error' => 'مطلوب تسجيل الدخول', 'code' => 401];
        }

        $role = $user['role'] ?? 'user';

        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            http_response_code(403);
            return ['success' => false, 'error' => 'هذا المسار مخصص لمدراء الفوترة فقط', 'code' => 403];
        }

        if (class_exists('AdminAccess')) {
            return AdminAccess::enforceRequest($user);
        }

        return null;
    }
}
