<?php

/**
 * Tourfecto - Email Marketing Transactional Email Service (المرحلة 4)
 * @version 1.0.0
 *
 * رسائل المعاملات (مثل Brevo's Transactional API): قوالب مخصصة تُرسل
 * عبر إعدادات SMTP للمستخدم مع تتبع اختياري (فتح/كليك) لكن من غير
 * إلغاء اشتراك — رسائل زي تأكيد التسجيل، استعادة كلمة المرور، الفواتير.
 *
 * Additive خالص على البنية الحالية.
 */
class TransactionalEmailService
{
    /** @var Database */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ============================ Template CRUD ============================

    public function listTemplates(int $userId): array
    {
        return $this->db->query(
            "SELECT t.*,
                    (SELECT COUNT(*) FROM email_transactional_logs l
                     WHERE l.template_id = t.id) AS send_count
             FROM email_transactional_templates t
             WHERE t.user_id = ?
             ORDER BY t.created_at DESC",
            [$userId]
        );
    }

    public function getTemplate(int $userId, int $templateId): ?array
    {
        $template = (new EmailTransactionalTemplate())->find($templateId);
        if (!$template || (int) $template->getAttribute('user_id') !== $userId) {
            return null;
        }
        return $template->toArray();
    }

    public function getTemplateBySlug(int $userId, string $slug): ?array
    {
        $rows = $this->db->query(
            "SELECT * FROM email_transactional_templates WHERE user_id = ? AND slug = ? LIMIT 1",
            [$userId, $slug]
        );
        return $rows[0] ?? null;
    }

    public function createTemplate(int $userId, array $data): array
    {
        if (trim((string) ($data['name'] ?? '')) === '') {
            return ['success' => false, 'error' => 'اسم القالب مطلوب'];
        }
        if (trim((string) ($data['subject'] ?? '')) === '') {
            return ['success' => false, 'error' => 'عنوان البريد (subject) مطلوب'];
        }
        if (trim((string) ($data['html_body'] ?? '')) === '') {
            return ['success' => false, 'error' => 'محتوى القالب مطلوب'];
        }

        $slug = trim((string) ($data['slug'] ?? ''));
        if ($slug === '') {
            $slug = $this->slugify((string) $data['name']);
        }
        $existing = $this->getTemplateBySlug($userId, $slug);
        if ($existing) {
            return ['success' => false, 'error' => 'القيمة المختصرة (slug) مستخدمة بالفعل'];
        }

        $template = new EmailTransactionalTemplate([
            'user_id' => $userId,
            'name' => trim((string) $data['name']),
            'slug' => $slug,
            'subject' => trim((string) $data['subject']),
            'html_body' => (string) $data['html_body'],
            'is_active' => array_key_exists('is_active', $data) ? (!empty($data['is_active']) ? 1 : 0) : 1,
        ]);
        $id = (int) $template->save();
        if ($id <= 0) {
            return ['success' => false, 'error' => 'تعذر حفظ القالب'];
        }
        return ['success' => true, 'id' => $id];
    }

    public function updateTemplate(int $userId, int $templateId, array $data): array
    {
        $template = (new EmailTransactionalTemplate())->find($templateId);
        if (!$template || (int) $template->getAttribute('user_id') !== $userId) {
            return ['success' => false, 'error' => 'القالب غير موجود'];
        }
        foreach (['name', 'slug', 'subject', 'html_body'] as $field) {
            if (array_key_exists($field, $data)) {
                $template->setAttribute($field, trim((string) $data[$field]));
            }
        }
        if (array_key_exists('is_active', $data)) {
            $template->setAttribute('is_active', !empty($data['is_active']) ? 1 : 0);
        }
        $template->save();
        return ['success' => true];
    }

    public function deleteTemplate(int $userId, int $templateId): array
    {
        $template = (new EmailTransactionalTemplate())->find($templateId);
        if (!$template || (int) $template->getAttribute('user_id') !== $userId) {
            return ['success' => false, 'error' => 'القالب غير موجود'];
        }
        $template->delete();
        return ['success' => true];
    }

    /**
     * نسخ قالب معاملات بقالب جديد. يضيف لاحقة "-copy" للاسم وslug مع ضمان
     * عدم تكرار slug لكل مستخدم.
     */
    public function duplicateTemplate(int $userId, int $templateId): array
    {
        $template = (new EmailTransactionalTemplate())->find($templateId);
        if (!$template || (int) $template->getAttribute('user_id') !== $userId) {
            return ['success' => false, 'error' => 'القالب غير موجود'];
        }

        $newName = trim((string) $template->getAttribute('name')) . ' (نسخة)';
        $baseSlug = rtrim((string) $template->getAttribute('slug'), '-') . '-copy';
        $slug = $baseSlug;
        $i = 2;
        while ($this->getTemplateBySlug($userId, $slug)) {
            $slug = $baseSlug . '-' . $i;
            $i++;
        }

        $copy = new EmailTransactionalTemplate([
            'user_id' => $userId,
            'name' => $newName,
            'slug' => $slug,
            'subject' => (string) $template->getAttribute('subject'),
            'html_body' => (string) $template->getAttribute('html_body'),
            'is_active' => (int) $template->getAttribute('is_active'),
        ]);
        $id = (int) $copy->save();
        if ($id <= 0) {
            return ['success' => false, 'error' => 'تعذر نسخ القالب'];
        }
        return ['success' => true, 'id' => $id];
    }

    // ============================ Sending ============================

    /**
     * إرسال رسالة معاملات. يسجّل السجل مع توكنات تتبع (فتح/كليك) لكن
     * من غير إلغاء اشتراك.
     *
     * @param array $data بيانات التخصيص: name, first_name, email + attributes
     * @param array $options خيارات إضافية: track (bool), to_name
     * @return array ['success'=>bool, 'id'=>int, 'error'=>?string]
     */
    public function send(int $userId, int $templateId, string $toEmail, array $data = [], array $options = []): array
    {
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'id' => 0, 'error' => 'بريد المستلم غير صالح'];
        }
        $template = $this->getTemplate($userId, $templateId);
        if (!$template) {
            return ['success' => false, 'id' => 0, 'error' => 'القالب غير موجود'];
        }
        if (!(int) ($template['is_active'] ?? 1)) {
            return ['success' => false, 'id' => 0, 'error' => 'القالب غير مفعّل'];
        }

        $track = $options['track'] ?? true;
        $openToken = $track ? $this->token() : null;
        $clickToken = $track ? $this->token() : null;

        $recipientData = array_merge([
            'name' => $data['to_name'] ?? $data['name'] ?? '',
            'first_name' => $data['to_name'] ?? $data['name'] ?? '',
            'email' => $toEmail,
            'company_name' => $this->companyName($userId),
            'campaign_name' => $template['name'],
        ], $data);

        $baseUrl = $this->trackingBaseUrl();
        $html = $this->renderer()->finalizeTransactional(
            (string) $template['html_body'],
            $recipientData,
            (string) $openToken,
            (string) $clickToken,
            $baseUrl
        );
        $subject = $this->renderer()->personalize((string) $template['subject'], $recipientData);

        $mailer = (new SmtpSettingsService())->mailerForUser($userId);
        $result = $mailer->send($toEmail, (string) ($recipientData['to_name'] ?? $recipientData['name'] ?? ''), $subject, $html);

        $status = !empty($result['success']) ? EmailTransactionalLog::STATUS_SENT : EmailTransactionalLog::STATUS_FAILED;
        $log = new EmailTransactionalLog([
            'user_id' => $userId,
            'template_id' => $templateId,
            'to_email' => $toEmail,
            'to_name' => $recipientData['to_name'] ?? $recipientData['name'] ?? null,
            'subject' => $subject,
            'status' => $status,
            'error' => empty($result['error']) ? null : substr((string) $result['error'], 0, 1000),
            'open_token' => $openToken,
            'click_token' => $clickToken,
        ]);
        $logId = (int) $log->save();

        return [
            'success' => !empty($result['success']),
            'id' => $logId,
            'error' => empty($result['error']) ? null : $result['error'],
        ];
    }

    // ============================ Logs & Stats ============================

    public function logs(int $userId, array $filters = []): array
    {
        $sql = "SELECT l.*, t.name AS template_name
                FROM email_transactional_logs l
                LEFT JOIN email_transactional_templates t ON t.id = l.template_id
                WHERE l.user_id = ?";
        $params = [$userId];

        if (!empty($filters['status'])) {
            $sql .= " AND l.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['template_id'])) {
            $sql .= " AND l.template_id = ?";
            $params[] = (int) $filters['template_id'];
        }
        if (!empty($filters['email'])) {
            $sql .= " AND l.to_email LIKE ?";
            $params[] = '%' . $filters['email'] . '%';
        }
        $sql .= " ORDER BY l.created_at DESC";
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . max(1, (int) $filters['limit']);
        }

        return $this->db->query($sql, $params);
    }

    public function stats(int $userId): array
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                    SUM(open_count) AS opens,
                    SUM(click_count) AS clicks
             FROM email_transactional_logs WHERE user_id = ?",
            [$userId]
        )[0] ?? [];

        $total = (int) ($row['total'] ?? 0);
        $opened = (int) $this->db->query(
            "SELECT COUNT(*) AS c FROM email_transactional_logs
             WHERE user_id = ? AND opened_at IS NOT NULL",
            [$userId]
        )[0]['c'] ?? 0;
        $clicked = (int) $this->db->query(
            "SELECT COUNT(*) AS c FROM email_transactional_logs
             WHERE user_id = ? AND clicked_at IS NOT NULL",
            [$userId]
        )[0]['c'] ?? 0;

        return [
            'total' => $total,
            'sent' => (int) ($row['sent'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'opens' => (int) ($row['opens'] ?? 0),
            'clicks' => (int) ($row['clicks'] ?? 0),
            'opened' => $opened,
            'clicked' => $clicked,
            'unique_opened' => $opened,
            'open_rate' => $total > 0 ? round($opened / $total * 100, 1) : 0,
            'recent' => $this->logs($userId, ['limit' => 5]),
        ];
    }

    // ============================ Helpers ============================

    private function renderer(): EmailRenderer
    {
        return new EmailRenderer();
    }

    private function companyName(int $userId): string
    {
        $rows = $this->db->query("SELECT company_name FROM users WHERE id = ? LIMIT 1", [$userId]);
        return $rows[0]['company_name'] ?? 'Tourfecto';
    }

    private function trackingBaseUrl(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        return ($https ? 'https' : 'http') . '://' . $host;
    }

    private function token(): string
    {
        return bin2hex(random_bytes(20));
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;
        return trim($value, '-') ?: 'template';
    }
}
