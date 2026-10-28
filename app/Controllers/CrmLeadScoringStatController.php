<?php

/**
 * Tourfecto - CRM Statistical Lead Scoring Controller (بند 6)
 * @version 1.0.0
 *
 * نقاط API للطبقة الإحصائية الشفافة على تقييم الـLeads:
 *   - GET  /api/crm/leads/scoring/stats          → معدلات التحويل لكل مصدر
 *   - POST /api/crm/leads/{id}/scoring           → حساب + حفظ الطبقة الإحصائية
 *   - GET  /api/crm/leads/{id}/scoring           → قراءة الطبقة الإحصائية المخزّنة
 *
 * عزل تينانت مطابق لـ CrmApiController: resolveTenantId() عبر
 * CrmPermissionService، ولا يُلمس CrmApiController الأصلي إطلاقًا.
 */

class CrmLeadScoringStatController extends Controller
{
    private $permissionService;

    public function __construct()
    {
        parent::__construct();
        $this->permissionService = new CrmPermissionService();
    }

    private function tenantId(): int
    {
        return $this->permissionService->resolveTenantId((int) ($this->user['id'] ?? 0));
    }

    /** GET /api/crm/leads/scoring/stats - إحصائيات تحويل المصادر */
    public function stats(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success((new CrmStatisticalLeadScoringService())->sourceConversionStats($this->tenantId()));
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 500) ?: 'خطأ غير متوقع', $e->getCode() >= 400 ? $e->getCode() : 500);
        }
    }

    /** POST /api/crm/leads/{id}/scoring - حساب + حفظ الطبقة الإحصائية */
    public function scoreLead(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $lead = (new CrmStatisticalLeadScoringService())->scoreLead((int) ($params['id'] ?? 0), $this->tenantId());
            return $this->success(['lead' => $lead->toArray()], 'تم حساب الطبقة الإحصائية للتقييم');
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 500) ?: 'خطأ غير متوقع', $e->getCode() >= 400 ? $e->getCode() : 500);
        }
    }

    /** GET /api/crm/leads/{id}/scoring - قراءة الطبقة الإحصائية المخزّنة */
    public function getScoring(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $lead = (new CrmLead())->find((int) ($params['id'] ?? 0));
            if (!$lead) {
                return $this->error('Lead غير موجود', 404);
            }
            $contact = $this->db->query("SELECT user_id FROM crm_contacts WHERE id = ?", [(int) $lead->getAttribute('contact_id')]);
            if (empty($contact) || (int) $contact[0]['user_id'] !== $this->tenantId()) {
                return $this->error('لا تملك صلاحية الوصول إلى هذا الـLead', 403);
            }

            $signals = json_decode((string) $lead->getAttribute('score_signals_json'), true);
            return $this->success([
                'lead_id' => (int) $lead->getAttribute('id'),
                'conv_probability' => $lead->getAttribute('conv_probability'),
                'score_confidence' => $lead->getAttribute('score_confidence'),
                'signals' => is_array($signals) ? $signals : null,
                'basis' => 'statistical',
            ]);
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 500) ?: 'خطأ غير متوقع', $e->getCode() >= 400 ? $e->getCode() : 500);
        }
    }
}
