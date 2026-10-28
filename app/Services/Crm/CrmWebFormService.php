<?php

/**
 * Tourfecto - CRM Web Form Service (المرحلة 15 - G11)
 * @version 1.0.0
 *
 * التقاط Leads عبر نماذج ويب عامة (Form Builder) - سد فجوة 2.1:
 * "التقاط Leads (Web Form / API): 🔶 (إدخال يدوي + API، بدون Form Builder)".
 *
 * Additive خالص: جدولان جديدان (crm_web_forms / crm_web_form_submissions) فقط.
 * لا يعدّل أي منطق/جدول قائم. عند الإرسال العام يُنشأ Contact + Lead
 * (نفس منطق CrmLeadService::createContact/createLeadWithData) مع تسجيل
 * الإرسال وربطه بالـContact/الـLead.
 */
class CrmWebFormService
{
    /** نماذج الحساب */
    public function listForms(int $userId): array
    {
        return (new CrmWebForm())->forUser($userId);
    }

    /** إنشاء/تحديث نموذج */
    public function saveForm(int $userId, array $data, ?int $formId = null): CrmWebForm
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new Exception('اسم النموذج مطلوب', 422);
        }
        $slug = trim((string) ($data['slug'] ?? ''));
        if ($slug === '') {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
        }
        $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
        $slug = trim($slug, '-');
        if ($slug === '') {
            throw new Exception('المعرف (slug) مطلوب', 422);
        }
        $slug = substr($slug, 0, 80);

        $fields = $data['fields'] ?? null;
        if (is_array($fields)) {
            $fields = json_encode(array_values($fields));
        }

        if ($formId !== null) {
            $form = (new CrmWebForm())->findOwned($userId, $formId);
            if (!$form) {
                throw new Exception('النموذج غير موجود', 404);
            }
            $existingSlug = $this->db()->query(
                "SELECT id FROM crm_web_forms WHERE slug = ? AND user_id = ? AND id != ? LIMIT 1",
                [$slug, $userId, $formId]
            );
            if (!empty($existingSlug)) {
                throw new Exception('يوجد نموذج بنفس المعرّف (slug) مسبقًا', 422);
            }
            $form->setAttribute('name', $name);
            $form->setAttribute('slug', $slug);
            $form->setAttribute('description', $data['description'] ?? null);
            $form->setAttribute('fields', $fields);
            $form->setAttribute('success_message', $data['success_message'] ?? null);
            $form->setAttribute('redirect_url', $data['redirect_url'] ?? null);
            $form->setAttribute('owner_user_id', $data['owner_user_id'] ?? null);
            $form->setAttribute('source', $data['source'] ?? 'web_form');
            $form->setAttribute('is_active', !empty($data['is_active']) ? 1 : 1);
            $form->save();
            return $form;
        }

        $existingSlug = $this->db()->query(
            "SELECT id FROM crm_web_forms WHERE slug = ? LIMIT 1",
            [$slug]
        );
        if (!empty($existingSlug)) {
            throw new Exception('يوجد نموذج بنفس المعرّف (slug) مسبقًا', 422);
        }

        $form = new CrmWebForm([
            'user_id' => $userId,
            'name' => $name,
            'slug' => $slug,
            'description' => $data['description'] ?? null,
            'fields' => $fields,
            'success_message' => $data['success_message'] ?? null,
            'redirect_url' => $data['redirect_url'] ?? null,
            'owner_user_id' => $data['owner_user_id'] ?? null,
            'source' => $data['source'] ?? 'web_form',
            'is_active' => 1,
        ]);
        $form->save();

        ActivityLog::record('crm', 'web_form.created', [
            'user_id' => $userId, 'subject_type' => 'crm_web_forms', 'subject_id' => (int) $form->getAttribute('id'),
        ]);
        return $form;
    }

    /** حذف نموذج */
    public function deleteForm(int $userId, int $formId): bool
    {
        $form = (new CrmWebForm())->findOwned($userId, $formId);
        if (!$form) {
            throw new Exception('النموذج غير موجود', 404);
        }
        return $form->delete();
    }

    /**
     * إرسال عام لنموذج نشط عبر slug (يستدعيه الزائر - بلا جلسة).
     * يُنشئ Contact + Lead ويسجّل الإرسال. آمن: لا يقرأ جلسة، ويفحص
     * حقل anti-bot اختياري (honeypot) في البيانات المُرسلة.
     */
    public function handleSubmission(string $slug, array $payload): array
    {
        $form = (new CrmWebForm())->findBySlug($slug);
        if (!$form) {
            throw new Exception('النموذج غير موجود أو غير نشط', 404);
        }

        // Honeypot بسيط: لو امتلأ حقل خفي (website) تجاهل الإرسال
        if (!empty($payload['website'])) {
            return ['ignored' => true];
        }

        $userId = (int) $form->getAttribute('user_id');
        $name = trim((string) ($payload['name'] ?? $payload['full_name'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $phone = trim((string) ($payload['phone'] ?? $payload['phone_number'] ?? ''));
        $notes = trim((string) ($payload['message'] ?? $payload['notes'] ?? ''));
        $source = (string) ($form->getAttribute('source') ?: 'web_form');

        if ($name === '') {
            throw new Exception('الاسم مطلوب', 422);
        }
        if ($email === '' && $phone === '') {
            throw new Exception('البريد الإلكتروني أو الهاتف مطلوب', 422);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('بريد إلكتروني غير صالح', 422);
        }

        $contactService = new CrmLeadService();
        $contact = $contactService->createContact($userId, [
            'name' => $name,
            'email' => $email !== '' ? $email : null,
            'phone' => $phone !== '' ? $phone : null,
            'country' => $payload['country'] ?? null,
            'language' => $payload['language'] ?? null,
            'source' => $source,
            'notes' => $notes !== '' ? $notes : null,
        ]);

        $ownerUserId = (int) ($form->getAttribute('owner_user_id') ?? 0);
        $lead = $contactService->createLeadWithData(
            (int) $contact->getAttribute('id'),
            $ownerUserId > 0 ? $ownerUserId : null,
            [
                'source' => $source,
                'value' => $payload['value'] ?? null,
                'interest' => $payload['interest'] ?? null,
            ]
        );

        $submission = new CrmWebFormSubmission([
            'user_id' => $userId,
            'web_form_id' => (int) $form->getAttribute('id'),
            'contact_id' => (int) $contact->getAttribute('id'),
            'lead_id' => (int) $lead->getAttribute('id'),
            'payload' => json_encode($payload),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
        ]);
        $submission->save();

        ActivityLog::record('crm', 'web_form.submitted', [
            'user_id' => $userId, 'subject_type' => 'crm_web_forms', 'subject_id' => (int) $form->getAttribute('id'),
            'meta' => ['lead_id' => (int) $lead->getAttribute('id'), 'contact_id' => (int) $contact->getAttribute('id')],
        ]);

        // توجيه تلقائي إن وُجدت قاعدة مطابقة (G5 - Lead Routing)
        try {
            (new CrmLeadRoutingService())->routeLead($userId, (int) $lead->getAttribute('id'), [
                'source' => $source,
                'country' => $payload['country'] ?? null,
                'value' => $payload['value'] ?? null,
            ]);
        } catch (Exception $e) {
            // التوجيه اختياري - الفشل لا يمنع نجاح الإرسال
        }

        return [
            'form_id' => (int) $form->getAttribute('id'),
            'contact_id' => (int) $contact->getAttribute('id'),
            'lead_id' => (int) $lead->getAttribute('id'),
            'success_message' => (string) ($form->getAttribute('success_message') ?: 'تم استلام طلبك بنجاح'),
            'redirect_url' => $form->getAttribute('redirect_url'),
        ];
    }

    /** إرسالات النموذج (مع اسم/بريد الـContact) */
    public function submissions(int $userId, ?int $formId = null, int $limit = 100): array
    {
        $sql = "SELECT s.*, c.name AS contact_name, c.email AS contact_email
                FROM crm_web_form_submissions s
                LEFT JOIN crm_contacts c ON c.id = s.contact_id
                WHERE s.user_id = ?";
        $params = [$userId];
        if ($formId !== null) {
            $sql .= " AND s.web_form_id = ?";
            $params[] = $formId;
        }
        $sql .= " ORDER BY s.created_at DESC LIMIT " . (int) $limit;
        return $this->db()->query($sql, $params);
    }

    private function db()
    {
        return Database::getInstance();
    }
}
