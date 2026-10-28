<?php

/**
 * Tourfecto - CRM Lead Routing Service (المرحلة 13 - G5)
 * @version 1.0.0
 *
 * توجيه تلقائي للـLeads: أول قاعدة نشطة مطابقة (حسب source/country/
 * value) تحدد المالك. وضعان: fixed (مستخدم محدد) أو round_robin
 * (توزيع بالتناوب بين فريق الحساب = المالك + أعضاء الفريق). الميزة
 * يملكها Freshsales/Pipedrive.
 *
 * Additive بالكامل: جدول جديد فقط. نقاط نهاية CrmController::createLead
 * الأصلية لم تُلمس - يمكن للعميل استدعاء `routeLead` بعد إنشاء الـLead
 * يدويًا (أو تفعيله لاحقًا عبر Hook اختياري في نهاية إضافية).
 */
class CrmLeadRoutingService
{
    /** أول قاعدة نشطة مطابقة لبيانات الـLead - أو null لو لا توجد */
    public function findMatchingRule(int $tenantUserId, array $leadContext): ?array
    {
        $rules = (new CrmLeadRoutingRule())->activeForUser($tenantUserId);
        if (empty($rules)) {
            return null;
        }

        $source = (string) ($leadContext['source'] ?? '');
        $country = (string) ($leadContext['country'] ?? '');
        $value = (float) ($leadContext['value'] ?? 0);

        foreach ($rules as $rule) {
            if ($rule['match_source'] !== null && $rule['match_source'] !== '' && $rule['match_source'] !== $source) {
                continue;
            }
            if ($rule['match_country'] !== null && $rule['match_country'] !== '' && $rule['match_country'] !== $country) {
                continue;
            }
            if ($rule['match_min_value'] !== null && $value < (float) $rule['match_min_value']) {
                continue;
            }
            if ($rule['match_max_value'] !== null && $value > (float) $rule['match_max_value']) {
                continue;
            }
            return $rule;
        }
        return null;
    }

    /** المستخدمون المرشحون للتناوب: المالك + أعضاء الفريق */
    private function candidateUsers(int $tenantUserId): array
    {
        $owner = (int) $tenantUserId;
        $members = (new CrmTeamMember())->forTenant($tenantUserId);
        $ids = [$owner];
        foreach ($members as $member) {
            $ids[] = (int) $member['member_user_id'];
        }
        return array_values(array_unique($ids));
    }

    /** اختيار المالك حسب القاعدة (fixed أو round_robin) */
    private function resolveAssignee(int $tenantUserId, array $rule): int
    {
        if ($rule['assignment_mode'] === 'round_robin') {
            $candidates = $this->candidateUsers($tenantUserId);
            $count = count($candidates);
            if ($count === 0) {
                return $tenantUserId;
            }
            $index = (int) $rule['rotation_index'];
            $assignee = $candidates[$index % $count];
            $this->advanceRotation($tenantUserId, (int) $rule['id'], $index + 1);
            return $assignee;
        }
        // fixed
        return (int) ($rule['assignee_user_id'] ?? $tenantUserId);
    }

    /** تقدم عداد التناوب على القاعدة */
    private function advanceRotation(int $tenantUserId, int $ruleId, int $nextIndex): void
    {
        $this->db()->query(
            "UPDATE crm_lead_routing_rules SET rotation_index = ? WHERE id = ? AND user_id = ?",
            [$nextIndex, $ruleId, $tenantUserId]
        );
    }

    private function db()
    {
        return Database::getInstance();
    }

    /**
     * تطبيق التوجيه على Lead (تحديث owner_user_id).
     * @return array ['assigned' => bool, 'assignee_user_id' => ?int, 'rule_id' => ?int]
     */
    public function routeLead(int $tenantUserId, int $leadId, array $leadContext = []): array
    {
        $lead = (new CrmLead())->find($leadId);
        $isOwner = $lead && (int) $lead->getAttribute('owner_user_id') === $tenantUserId;
        $isTenantLead = $lead && !$isOwner && $this->leadBelongsToTenant($tenantUserId, $lead);
        if (!$lead || (!$isOwner && !$isTenantLead)) {
            throw new Exception('الـLead غير موجود', 404);
        }

        $context = $leadContext;
        if (!isset($context['source']) || $context['source'] === null) {
            $context['source'] = $lead->getAttribute('source');
        }
        if (!isset($context['country'])) {
            $contact = (new CrmContact())->find((int) $lead->getAttribute('contact_id'));
            $context['country'] = $contact ? $contact->getAttribute('country') : '';
        }
        if (!isset($context['value']) || $context['value'] === null) {
            $context['value'] = $lead->getAttribute('value');
        }

        $rule = $this->findMatchingRule($tenantUserId, $context);
        if (!$rule) {
            return ['assigned' => false, 'assignee_user_id' => null, 'rule_id' => null];
        }

        $assignee = $this->resolveAssignee($tenantUserId, $rule);
        $lead->setAttribute('owner_user_id', $assignee);
        $lead->save();

        ActivityLog::record('crm', 'lead.routed', [
            'user_id' => $tenantUserId, 'subject_type' => 'crm_leads', 'subject_id' => $leadId,
            'meta' => ['rule_id' => (int) $rule['id'], 'assignee_user_id' => $assignee],
        ]);

        return [
            'assigned' => true,
            'assignee_user_id' => $assignee,
            'rule_id' => (int) $rule['id'],
        ];
    }

    /** الـLead يتبع الحساب عبر صاحب جهة الاتصال (نفس منطق CrmLead::allForUser) */
    private function leadBelongsToTenant(int $tenantUserId, CrmLead $lead): bool
    {
        $contact = (new CrmContact())->find((int) $lead->getAttribute('contact_id'));
        return $contact && (int) $contact->getAttribute('user_id') === $tenantUserId;
    }

    // ------------------------------------------------------------
    // إدارة القواعد (CRUD)
    // ------------------------------------------------------------

    public function createRule(int $userId, array $data): CrmLeadRoutingRule
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new Exception('اسم القاعدة مطلوب', 422);
        }
        $mode = (string) ($data['assignment_mode'] ?? 'fixed');
        if (!in_array($mode, ['fixed', 'round_robin'], true)) {
            throw new Exception('وضع التوجيه غير صالح (fixed/round_robin)', 422);
        }
        $assignee = (int) ($data['assignee_user_id'] ?? 0);
        if ($mode === 'fixed' && $assignee <= 0) {
            throw new Exception('اختر المستخدم المستهدف للوضع الثابت', 422);
        }

        $rule = new CrmLeadRoutingRule([
            'user_id' => $userId,
            'name' => $name,
            'is_active' => isset($data['is_active']) ? (int) $data['is_active'] : 1,
            'match_source' => ($data['match_source'] ?? null) !== '' && ($data['match_source'] ?? null) !== null ? (string) $data['match_source'] : null,
            'match_country' => ($data['match_country'] ?? null) !== '' && ($data['match_country'] ?? null) !== null ? (string) $data['match_country'] : null,
            'match_min_value' => ($data['match_min_value'] ?? null) !== null && ($data['match_min_value'] ?? '') !== '' ? (float) $data['match_min_value'] : null,
            'match_max_value' => ($data['match_max_value'] ?? null) !== null && ($data['match_max_value'] ?? '') !== '' ? (float) $data['match_max_value'] : null,
            'assignment_mode' => $mode,
            'assignee_user_id' => $assignee > 0 ? $assignee : null,
            'rotation_index' => 0,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);
        $rule->save();
        return $rule;
    }

    public function updateRule(int $userId, int $ruleId, array $data): CrmLeadRoutingRule
    {
        $rule = (new CrmLeadRoutingRule())->findOwned($userId, $ruleId);
        if (!$rule) {
            throw new Exception('القاعدة غير موجودة', 404);
        }
        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new Exception('اسم القاعدة مطلوب', 422);
            }
            $rule->setAttribute('name', $name);
        }
        if (isset($data['is_active'])) {
            $rule->setAttribute('is_active', (int) $data['is_active']);
        }
        if (isset($data['match_source'])) {
            $rule->setAttribute('match_source', ($data['match_source'] !== '' && $data['match_source'] !== null) ? (string) $data['match_source'] : null);
        }
        if (isset($data['match_country'])) {
            $rule->setAttribute('match_country', ($data['match_country'] !== '' && $data['match_country'] !== null) ? (string) $data['match_country'] : null);
        }
        if (isset($data['match_min_value'])) {
            $rule->setAttribute('match_min_value', ($data['match_min_value'] !== '' && $data['match_min_value'] !== null) ? (float) $data['match_min_value'] : null);
        }
        if (isset($data['match_max_value'])) {
            $rule->setAttribute('match_max_value', ($data['match_max_value'] !== '' && $data['match_max_value'] !== null) ? (float) $data['match_max_value'] : null);
        }
        if (isset($data['sort_order'])) {
            $rule->setAttribute('sort_order', (int) $data['sort_order']);
        }

        if (isset($data['assignment_mode'])) {
            $mode = (string) $data['assignment_mode'];
            if (!in_array($mode, ['fixed', 'round_robin'], true)) {
                throw new Exception('وضع التوجيه غير صالح', 422);
            }
            $rule->setAttribute('assignment_mode', $mode);
            if ($mode === 'fixed' && (int) ($data['assignee_user_id'] ?? 0) <= 0 && (int) $rule->getAttribute('assignee_user_id') <= 0) {
                throw new Exception('اختر المستخدم المستهدف للوضع الثابت', 422);
            }
        }
        if (isset($data['assignee_user_id'])) {
            $assignee = (int) $data['assignee_user_id'];
            if ((string) $rule->getAttribute('assignment_mode') === 'fixed' && $assignee <= 0) {
                throw new Exception('اختر المستخدم المستهدف للوضع الثابت', 422);
            }
            $rule->setAttribute('assignee_user_id', $assignee > 0 ? $assignee : null);
        }

        $rule->save();
        return $rule;
    }

    public function deleteRule(int $userId, int $ruleId): bool
    {
        $rule = (new CrmLeadRoutingRule())->findOwned($userId, $ruleId);
        if (!$rule) {
            throw new Exception('القاعدة غير موجودة', 404);
        }
        return $rule->delete();
    }

    public function listRules(int $userId): array
    {
        return (new CrmLeadRoutingRule())->allForUser($userId);
    }
}
