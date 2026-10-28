<?php

/** لوحة الأدمن ← الباقات: سعر اشتراك الموقع وخصم مشتركين المنصة وكل المواقع وحالة اشتراكها */
class SiteSubscriptionAdminController extends Controller
{
    /** GET /api/admin/site-subscriptions */
    public function index(array $params = []): array
    {
        try {
            return $this->success(['config' => SiteSubscriptionService::config(), 'sites' => (new SiteSubscriptionService())->adminList()]);
        } catch (Throwable $e) {
            Logger::error('Site subscriptions admin list failed', ['error' => $e->getMessage()]);
            return $this->error('تعذر التحميل', 500);
        }
    }

    /** PUT /api/admin/site-subscriptions/config */
    public function saveConfig(array $params = []): array
    {
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        $c = SiteSubscriptionService::saveConfig([
            'enabled' => $this->get('enabled'), 'monthly' => $this->get('monthly'), 'yearly' => $this->get('yearly'),
            'platform_discount' => $this->get('platform_discount'), 'grace_days' => $this->get('grace_days'),
        ]);
        $this->log('Admin Updated Site Plan Pricing', $c);
        return $this->success(['config' => $c], 'تم حفظ أسعار الموقع');
    }

    /** POST /api/admin/site-subscriptions/{id}/grant {days} */
    public function grant(array $params = []): array
    {
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        $end = (new SiteSubscriptionService())->adminGrant((int) ($params['id'] ?? 0), (int) $this->get('days', 30), (int) $this->user['id']);
        if (!$end) {
            return $this->error('الموقع مش موجود أو عدد الأيام غلط', 422);
        }
        return $this->success(['period_end' => $end], 'الموقع شغال لحد ' . substr($end, 0, 10));
    }
}
