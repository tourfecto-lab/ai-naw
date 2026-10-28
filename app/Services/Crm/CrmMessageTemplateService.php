<?php

/**
 * Tourfecto - CRM Message Template Service (المرحلة 12 - G1)
 * @version 1.0.0
 *
 * مكتبة قوالب رسائل قابلة لإعادة الاستخدام لكل القنوات (Email/WhatsApp/SMS).
 * الفجوة الأصلية: كانت الرسائل تُرسل كنصوص مبعثرة بدون قوالب محفوظة
 * (راجع docs/COMPETITIVE_ANALYSIS.md - كل المنافسين يملكون هذه الميزة).
 *
 * المتغيرات تُكتب بصيغة `{{key}}` وتُستبدل وقت الإرسال عبر `render()`.
 * القائمة المرجعية الوحيدة للمتغيرات المسموحة هي `VARIABLES` - أي متغير
 * بره القائمة لا يُستبدل (يبقى كما هو في النص، بلا اختراع قيم).
 */
class CrmMessageTemplateService
{
    /** المتغيرات المسموحة (الـmapping مع قيمها يحدث وقت الـrender حسب السياق) */
    private const VARIABLES = [
        ['key' => 'name', 'label' => 'crm.templates.var.name'],
        ['key' => 'phone', 'label' => 'crm.templates.var.phone'],
        ['key' => 'email', 'label' => 'crm.templates.var.email'],
        ['key' => 'company', 'label' => 'crm.templates.var.company'],
        ['key' => 'deal_title', 'label' => 'crm.templates.var.deal_title'],
        ['key' => 'deal_value', 'label' => 'crm.templates.var.deal_value'],
        ['key' => 'currency', 'label' => 'crm.templates.var.currency'],
        ['key' => 'signature', 'label' => 'crm.templates.var.signature'],
    ];

    public function listForUser(int $userId, string $channel = ''): array
    {
        return (new CrmMessageTemplate())->forUser($userId, $channel);
    }

    public function variables(): array
    {
        return self::VARIABLES;
    }

    /**
     * إنشاء قالب جديد.
     * @param array $data {channel, name, subject?, body, variables?}
     */
    public function create(int $userId, int $actorUserId, array $data): CrmMessageTemplate
    {
        $channel = (string) ($data['channel'] ?? '');
        $name = trim((string) ($data['name'] ?? ''));
        $body = trim((string) ($data['body'] ?? ''));

        if (!in_array($channel, ['email', 'whatsapp', 'sms'], true)) {
            throw new Exception('القناة غير صالحة (email/whatsapp/sms)', 422);
        }
        if ($name === '') {
            throw new Exception('اسم القالب مطلوب', 422);
        }
        if ($body === '') {
            throw new Exception('نص القالب مطلوب', 422);
        }

        $subject = isset($data['subject']) ? trim((string) $data['subject']) : null;
        $variables = isset($data['variables']) ? json_encode($data['variables'], JSON_UNESCAPED_UNICODE) : null;

        $template = new CrmMessageTemplate([
            'user_id' => $userId,
            'channel' => $channel,
            'name' => $name,
            'subject' => $subject,
            'body' => $body,
            'variables' => $variables,
            'created_by_user_id' => $actorUserId,
        ]);
        $template->save();
        ActivityLog::record('crm', 'template.created', [
            'user_id' => $userId, 'subject_type' => 'crm_message_templates', 'subject_id' => (int) $template->getAttribute('id'),
            'meta' => ['actor_user_id' => $actorUserId, 'channel' => $channel],
        ]);
        return $template;
    }

    /** تحديث قالب (الاسم/القناة/النص/الموضوع) */
    public function update(int $userId, int $templateId, array $data): CrmMessageTemplate
    {
        $template = (new CrmMessageTemplate())->findOwned($userId, $templateId);
        if (!$template) {
            throw new Exception('القالب غير موجود', 404);
        }

        if (isset($data['channel'])) {
            $channel = (string) $data['channel'];
            if (!in_array($channel, ['email', 'whatsapp', 'sms'], true)) {
                throw new Exception('القناة غير صالحة (email/whatsapp/sms)', 422);
            }
            $template->setAttribute('channel', $channel);
        }
        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new Exception('اسم القالب مطلوب', 422);
            }
            $template->setAttribute('name', $name);
        }
        if (isset($data['subject'])) {
            $template->setAttribute('subject', trim((string) $data['subject']));
        }
        if (isset($data['body'])) {
            $body = trim((string) $data['body']);
            if ($body === '') {
                throw new Exception('نص القالب مطلوب', 422);
            }
            $template->setAttribute('body', $body);
        }
        if (isset($data['variables'])) {
            $template->setAttribute('variables', json_encode($data['variables'], JSON_UNESCAPED_UNICODE));
        }
        $template->save();
        return $template;
    }

    /** حذف قالب (فقط المملوك للحساب) */
    public function delete(int $userId, int $templateId): bool
    {
        $template = (new CrmMessageTemplate())->findOwned($userId, $templateId);
        if (!$template) {
            throw new Exception('القالب غير موجود', 404);
        }
        ActivityLog::record('crm', 'template.deleted', [
            'user_id' => $userId, 'subject_type' => 'crm_message_templates', 'subject_id' => $templateId,
        ]);
        return $template->delete();
    }

    /**
     * يبني قالبًا جاهزًا للإرسال (subject + body) بعد استبدال المتغيرات.
     * @param array $context القيم الفعلية مثل ['name' => 'أحمد', 'deal_value' => '5000']
     * @return array {subject: string, body: string}
     */
    public function render(int $userId, int $templateId, array $context): array
    {
        $template = (new CrmMessageTemplate())->findOwned($userId, $templateId);
        if (!$template) {
            throw new Exception('القالب غير موجود', 404);
        }

        $subject = (string) $template->getAttribute('subject');
        $body = (string) $template->getAttribute('body');

        $context = array_filter($context, fn ($v) => $v !== null);
        foreach (self::VARIABLES as $var) {
            $key = $var['key'];
            if (!array_key_exists($key, $context)) {
                continue;
            }
            $value = (string) $context[$key];
            $subject = str_replace('{{' . $key . '}}', $value, $subject);
            $body = str_replace('{{' . $key . '}}', $value, $body);
        }

        return ['subject' => $subject, 'body' => $body];
    }
}
