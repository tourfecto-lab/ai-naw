<?php

/**
 * Tourfecto - CRM Customer 360 Service (بند 2)
 * @version 1.0.0
 *
 * يبني ملف عميل موحّد من كل الجداول المرتبطة، ويجمّع Timeline واحدة من
 * activity_logs (سجل النشاط الموحد الموجود بالفعل في المشروع - بند 33:
 * "لا تنشئ أنظمة مكررة") بالإضافة لـTasks/Notes/Meetings/Deals مباشرة.
 * أي قسم بيانات غير متوفر (مثال: Purchases/Reviews) يظهر فارغًا صراحة
 * بدل اختلاقه (بند 39).
 */
class CrmCustomer360Service
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function build(int $userId, int $contactId): array
    {
        $contact = (new CrmContact())->find($contactId);
        if (!$contact || (int) $contact->getAttribute('user_id') !== $userId) {
            throw new Exception('جهة الاتصال غير موجودة', 404);
        }

        $company = null;
        if ($contact->getAttribute('company_id')) {
            $company = (new CrmCompany())->find((int) $contact->getAttribute('company_id'));
        }

        $leads = (new CrmLead())->where(['contact_id' => $contactId], ['created_at' => 'DESC']);
        $deals = $this->db->query(
            "SELECT d.*, s.name AS stage_name, s.color AS stage_color FROM crm_deals d
             JOIN crm_pipeline_stages s ON s.id = d.stage_id
             WHERE d.contact_id = ? ORDER BY d.created_at DESC",
            [$contactId]
        );
        $tasks = (new CrmTask())->forRelated('crm_contacts', $contactId);
        $notes = (new CrmNote())->forRelated('crm_contacts', $contactId);
        $meetings = (new CrmMeeting())->forContact($contactId);

        $activity = $this->db->query(
            "SELECT * FROM activity_logs WHERE module = 'crm' AND (
                (subject_type = 'crm_contacts' AND subject_id = ?)
                OR (subject_type = 'crm_leads' AND subject_id IN (SELECT id FROM crm_leads WHERE contact_id = ?))
                OR (subject_type = 'crm_deals' AND subject_id IN (SELECT id FROM crm_deals WHERE contact_id = ?))
             ) ORDER BY created_at DESC LIMIT 100",
            [$contactId, $contactId, $contactId]
        );

        return [
            'contact' => $contact->toArray(),
            'company' => $company ? $company->toArray() : null,
            // ملاحظة إصلاح: Core/Model::where() يُرجع مصفوفة من كائنات Model
            // (وليس مصفوفات خام)، وModel لا يُطبّق JsonSerializable - فلو
            // مررناها كما هي، json_encode() في الـController كان سيحوّلها
            // لكائنات فارغة {} في استجابة الـAPI بدل بيانات حقيقية. لازم
            // toArray() صراحة على كل عنصر قبل الإرجاع.
            'leads' => array_map(fn ($l) => $l->toArray(), $leads),
            'deals' => $deals,
            'tasks' => array_map(fn ($t) => $t->toArray(), $tasks),
            'notes' => array_map(fn ($n) => $n->toArray(), $notes),
            'appointments' => array_map(fn ($m) => $m->toArray(), $meetings),
            'timeline' => $activity,
            // أقسام تتطلب تكاملات غير مبنية في هذا الموديول (بند 45: لا نعيد
            // بناء موديولات أخرى) - تظهر فارغة صراحة بدل بيانات وهمية.
            'purchases' => [],
            'reviews' => [],
            'marketing_attribution' => [],
        ];
    }
}
