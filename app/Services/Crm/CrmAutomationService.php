<?php

/**
 * Tourfecto - CRM Automation Workflow Engine (بند 12، 36)
 * @version 1.0.0
 *
 * قرار معماري مهم: `Core/Events/EventDispatcher.php` موجود بالفعل في
 * المشروع (Observer Pattern)، لكن لا يوجد أي ملف Bootstrap مؤكد في
 * الأرشيف المرفوع يُسجّل الـlisteners في بداية كل طلب (راجع CHANGELOG -
 * "لم يكن `public/index.php` أو ما يعادله ضمن الأرشيف"). الاعتماد على
 * `EventDispatcher::listen()` بدون التأكد من مكان تسجيله كان سيعني قاعدة
 * Automation قد لا تُنفَّذ إطلاقًا في الإنتاج دون أي خطأ ظاهر - وهذا أخطر
 * من عدم وجود الميزة (بند 39/40: لا تدّعي وظيفة لا تعمل فعليًا). لذلك هذا
 * المحرك يُستدعى مباشرة (Direct Call) من نقاط الحدث في الكود (راجع
 * CrmLeadService::createLead/updateStatus وCrmController::createDeal/
 * updateDealStage) بدل الاعتماد على تسجيل غير مؤكد - نفس فكرة الأتمتة
 * (بند 36) لكن بتنفيذ مضمون 100%.
 *
 * كل Action هنا فعل داخلي فقط داخل قاعدة بيانات CRM (تعيين مسؤول/إنشاء
 * مهمة/ملاحظة/إشعار داخلي) - لا يوجد أي إرسال خارجي فعلي (واتساب/إيميل)
 * من هذا المحرك؛ إرسال رسائل خارجية فعلية يحتاج قرار صريح من المستخدم عبر
 * CrmWhatsAppService/CrmEmailService (بند 10: "لا ينفذ Action خارجي
 * تلقائيًا إلا إذا كان هناك Integration رسمي وصلاحية واضحة").
 */
class CrmAutomationService
{
    private $db;

    /** أمثلة جاهزة مطابقة حرفيًا لما ورد في الطلب الأصلي (بند 12) */
    public const TEMPLATES = [
        'new_lead_onboarding' => [
            'name_ar' => 'عند إنشاء Lead جديد',
            'trigger_event' => 'lead.created',
            'conditions' => [],
            'actions' => [
                ['type' => 'create_task', 'title' => 'أول تواصل مع الـLead الجديد', 'due_offset_days' => 0, 'priority' => 'high'],
                ['type' => 'notify_user', 'title' => 'Lead جديد يحتاج متابعة'],
            ],
        ],
        'deal_won_onboarding' => [
            'name_ar' => 'عند كسب صفقة (Deal Won)',
            'trigger_event' => 'deal.won',
            'conditions' => [],
            'actions' => [
                ['type' => 'create_onboarding_task', 'title' => 'بدء إجراءات تأهيل العميل الجديد (Onboarding)'],
                ['type' => 'notify_team', 'title' => 'صفقة جديدة مكسوبة 🎉'],
            ],
        ],
    ];

    /**
     * "مصدر الحقيقة" الوحيد لأدوات الـVisual Builder في الواجهة (بند 12) -
     * كل حدث وحقول Context الحقيقية المتاحة له مأخوذة حرفيًا من نقاط
     * الاستدعاء الفعلية لـ trigger() في الكود (CrmLeadService/CrmController)،
     * وكل Action ونوع بياناته الحقيقية مأخوذ حرفيًا من executeAction() تحت.
     * لا نعرض للمستخدم أي حقل أو خيار غير موجود فعليًا في المنطق المُنفَّذ
     * (بند 39: لا تخترع معلومات غير موجودة) - لو حدث/حقل جديد اتضاف هنا،
     * لازم يتضاف فعليًا في trigger()/executeAction() في نفس الوقت.
     */
    public const SCHEMA = [
        'triggers' => [
            'lead.created' => ['label_ar' => 'عند إنشاء Lead جديد', 'context_fields' => ['contact_id', 'lead_id']],
            'lead.status_changed' => ['label_ar' => 'عند تغيّر حالة Lead', 'context_fields' => ['status', 'previous_status', 'lead_id']],
            'deal.created' => ['label_ar' => 'عند إنشاء صفقة جديدة', 'context_fields' => ['deal_id']],
            'deal.stage_changed' => ['label_ar' => 'عند نقل صفقة بين المراحل', 'context_fields' => ['deal_id', 'stage_id']],
            'deal.won' => ['label_ar' => 'عند كسب صفقة (Won)', 'context_fields' => ['deal_id', 'stage_id']],
            'deal.lost' => ['label_ar' => 'عند خسارة صفقة (Lost)', 'context_fields' => ['deal_id', 'stage_id']],
            'whatsapp.message_received' => ['label_ar' => 'عند استلام رسالة واتساب', 'context_fields' => ['contact_id']],
            'sms.message_received' => ['label_ar' => 'عند استلام رسالة SMS', 'context_fields' => ['contact_id']],
            'email.received' => ['label_ar' => 'عند استلام إيميل وارد', 'context_fields' => ['contact_id']],
        ],
        'operators' => ['=' => '=', '!=' => '≠', '>' => '>', '<' => '<', 'contains' => 'يحتوي على'],
        'action_types' => [
            'assign_owner' => ['label_ar' => 'تعيين مسؤول للـLead', 'fields' => ['owner_user_id'], 'applies_to' => ['lead.created', 'lead.status_changed']],
            'create_task' => ['label_ar' => 'إنشاء مهمة', 'fields' => ['title', 'due_offset_days', 'priority'], 'applies_to' => '*'],
            'create_onboarding_task' => ['label_ar' => 'إنشاء مهمة Onboarding', 'fields' => ['title'], 'applies_to' => '*'],
            'create_note' => ['label_ar' => 'إضافة ملاحظة', 'fields' => ['body'], 'applies_to' => '*'],
            'notify_user' => ['label_ar' => 'إشعار لي (صاحب الحساب)', 'fields' => ['title', 'body'], 'applies_to' => '*'],
            'notify_team' => ['label_ar' => 'إشعار الفريق', 'fields' => ['title', 'body'], 'applies_to' => '*'],
        ],
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /** ينفّذ كل القواعد المفعّلة المطابقة لحدث معيّن لحساب معيّن */
    public function trigger(string $event, int $userId, array $context): void
    {
        if ($userId <= 0) {
            return; // لا يوجد Tenant واضح - لا شيء ينفّذ (أأمن من التخمين)
        }

        // أتمتة التسويق بالبريد (crm_lead_created / crm_deal_won) - مستقلة عن قواعد CRM
        if (class_exists('EmailPlatformBridge')) {
            (new EmailPlatformBridge())->onCrmEvent($event, $userId, $context);
        }

        try {
            $rules = (new CrmAutomationRule())->activeForUserAndEvent($userId, $event);
        } catch (Exception $e) {
            Logger::warning('CrmAutomationService: تعذر جلب القواعد', ['event' => $event, 'message' => $e->getMessage()]);
            return;
        }

        foreach ($rules as $rule) {
            try {
                $conditions = json_decode((string) $rule['conditions'], true) ?: [];
                if (!$this->conditionsMatch($conditions, $context)) {
                    continue;
                }
                $actions = json_decode((string) $rule['actions'], true) ?: [];
                foreach ($actions as $action) {
                    $this->executeAction($action, $userId, $context);
                }

                ActivityLog::record('crm', 'automation.executed', [
                    'user_id' => $userId, 'subject_type' => 'crm_automation_rules', 'subject_id' => (int) $rule['id'],
                    'meta' => ['event' => $event, 'rule_name' => $rule['name']],
                ]);
            } catch (Exception $e) {
                // فشل قاعدة واحدة ميوقفش باقي القواعد ولا العملية الأصلية
                // (إنشاء الـLead/الصفقة) - نفس مبدأ EventDispatcher الأصلي.
                Logger::warning('CrmAutomationService: فشل تنفيذ قاعدة', [
                    'rule_id' => $rule['id'] ?? null, 'event' => $event, 'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /** شروط بسيطة: كل شرط لازم يتحقق (AND) - field/operator/value مقابل $context */
    private function conditionsMatch(array $conditions, array $context): bool
    {
        foreach ($conditions as $cond) {
            $field = $cond['field'] ?? null;
            $operator = $cond['operator'] ?? '=';
            $value = $cond['value'] ?? null;
            if ($field === null || !array_key_exists($field, $context)) {
                return false;
            }
            $actual = $context[$field];
            $matches = match ($operator) {
                '=' => $actual == $value,
                '!=' => $actual != $value,
                '>' => $actual > $value,
                '<' => $actual < $value,
                'contains' => is_string($actual) && str_contains($actual, (string) $value),
                default => false,
            };
            if (!$matches) {
                return false;
            }
        }
        return true;
    }

    private function executeAction(array $action, int $userId, array $context): void
    {
        $type = $action['type'] ?? '';

        switch ($type) {
            case 'assign_owner':
                if (!empty($context['lead_id']) && !empty($action['owner_user_id'])) {
                    (new CrmLeadService())->assignOwner((int) $context['lead_id'], (int) $action['owner_user_id']);
                }
                break;

            case 'create_task':
                [$relatedType, $relatedId] = $this->resolveRelated($context);
                (new CrmTaskService())->create($userId, [
                    'title' => $action['title'] ?? 'مهمة من Automation',
                    'due_date' => isset($action['due_offset_days']) ? date('Y-m-d H:i:s', strtotime('+' . (int) $action['due_offset_days'] . ' days')) : null,
                    'priority' => $action['priority'] ?? 'medium',
                    'related_type' => $relatedType, 'related_id' => $relatedId,
                    'assigned_to_user_id' => $userId,
                ]);
                break;

            case 'create_onboarding_task':
                [$relatedType, $relatedId] = $this->resolveRelated($context);
                (new CrmTaskService())->create($userId, [
                    'title' => $action['title'] ?? 'Onboarding العميل الجديد',
                    'due_date' => date('Y-m-d H:i:s', strtotime('+1 day')),
                    'priority' => 'high',
                    'related_type' => $relatedType, 'related_id' => $relatedId,
                    'assigned_to_user_id' => $userId,
                ]);
                break;

            case 'create_note':
                [$relatedType, $relatedId] = $this->resolveRelated($context);
                if ($relatedType && $relatedId) {
                    (new CrmNoteService())->create($userId, [
                        'body' => $action['body'] ?? '', 'related_type' => $relatedType, 'related_id' => $relatedId,
                    ]);
                }
                break;

            case 'notify_user':
                if (class_exists('Notification')) {
                    Notification::notify($userId, 'crm_automation', $action['title'] ?? 'تنبيه CRM', $action['body'] ?? '', '/crm');
                }
                break;

            case 'notify_team':
                // ملاحظة قيد معروف: النظام الحالي مفيهوش قائمة "فريق" واضحة
                // لكل Tenant (كل حساب = مالك واحد أساسًا - راجع بند 30 في
                // CHANGELOG المرحلة 1) - فـ"الفريق" هنا يعني صاحب الحساب حاليًا.
                if (class_exists('Notification')) {
                    Notification::notify($userId, 'crm_automation', $action['title'] ?? 'تنبيه فريق CRM', $action['body'] ?? '', '/crm');
                }
                break;

            default:
                Logger::warning('CrmAutomationService: نوع Action غير معروف', ['type' => $type]);
        }
    }

    private function resolveRelated(array $context): array
    {
        if (!empty($context['deal_id'])) {
            return ['crm_deals', (int) $context['deal_id']];
        }
        if (!empty($context['lead_id'])) {
            return ['crm_leads', (int) $context['lead_id']];
        }
        if (!empty($context['contact_id'])) {
            return ['crm_contacts', (int) $context['contact_id']];
        }
        return [null, null];
    }

    // ================================================================
    // إدارة القواعد (CRUD) - يستخدمها CrmApiController
    // ================================================================

    public function create(int $userId, array $data): CrmAutomationRule
    {
        if (empty($data['name']) || empty($data['trigger_event']) || empty($data['actions'])) {
            throw new Exception('بيانات القاعدة ناقصة (الاسم/الحدث/الإجراءات مطلوبة)');
        }
        $rule = new CrmAutomationRule([
            'user_id' => $userId,
            'name' => $data['name'],
            'trigger_event' => $data['trigger_event'],
            'conditions' => json_encode($data['conditions'] ?? [], JSON_UNESCAPED_UNICODE),
            'actions' => json_encode($data['actions'], JSON_UNESCAPED_UNICODE),
            'is_active' => 1,
        ]);
        $rule->save();
        return $rule;
    }

    public function createFromTemplate(int $userId, string $templateKey): CrmAutomationRule
    {
        if (!isset(self::TEMPLATES[$templateKey])) {
            throw new Exception('قالب غير معروف');
        }
        $tpl = self::TEMPLATES[$templateKey];
        return $this->create($userId, [
            'name' => $tpl['name_ar'], 'trigger_event' => $tpl['trigger_event'],
            'conditions' => $tpl['conditions'], 'actions' => $tpl['actions'],
        ]);
    }

    /** تعديل قاعدة موجودة بالكامل (Visual Builder - بند 12) */
    public function update(int $userId, int $ruleId, array $data): CrmAutomationRule
    {
        $rule = (new CrmAutomationRule())->find($ruleId);
        if (!$rule || (int) $rule->getAttribute('user_id') !== $userId) {
            throw new Exception('القاعدة غير موجودة', 404);
        }
        if (empty($data['name']) || empty($data['trigger_event']) || empty($data['actions'])) {
            throw new Exception('بيانات القاعدة ناقصة (الاسم/الحدث/الإجراءات مطلوبة)');
        }
        $rule->setAttribute('name', $data['name']);
        $rule->setAttribute('trigger_event', $data['trigger_event']);
        $rule->setAttribute('conditions', json_encode($data['conditions'] ?? [], JSON_UNESCAPED_UNICODE));
        $rule->setAttribute('actions', json_encode($data['actions'], JSON_UNESCAPED_UNICODE));
        $rule->save();
        return $rule;
    }

    public function toggle(int $userId, int $ruleId): CrmAutomationRule
    {
        $rule = (new CrmAutomationRule())->find($ruleId);
        if (!$rule || (int) $rule->getAttribute('user_id') !== $userId) {
            throw new Exception('القاعدة غير موجودة', 404);
        }
        $rule->setAttribute('is_active', $rule->getAttribute('is_active') ? 0 : 1);
        $rule->save();
        return $rule;
    }

    public function delete(int $userId, int $ruleId): bool
    {
        $rule = (new CrmAutomationRule())->find($ruleId);
        if (!$rule || (int) $rule->getAttribute('user_id') !== $userId) {
            throw new Exception('القاعدة غير موجودة', 404);
        }
        return $rule->delete();
    }

    public function listForUser(int $userId): array
    {
        return (new CrmAutomationRule())->allForUser($userId);
    }
}
