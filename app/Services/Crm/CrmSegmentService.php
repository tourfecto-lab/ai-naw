<?php

/**
 * Tourfecto - CRM Segment Service (بند 19)
 * @version 1.0.0
 *
 * القطاع = فلتر محفوظ باسم، وليس نظام منفصل - يعيد استخدام
 * `CrmContactService::search()` بالكامل (بند 33: لا تنشئ أنظمة مكررة).
 * القطاعات الافتراضية (Seed) مبنية على بيانات حقيقية موجودة فعليًا في
 * السكيما فقط (status/source/lead score/deal value/تفاعل أخير) - لا يوجد
 * "High Value" حقيقي بمعنى إجمالي المشتريات لأن بيانات المشتريات نفسها
 * غير موجودة في نطاق موديول CRM هذا (بند 39/45).
 */
class CrmSegmentService
{
    private $contactService;

    public function __construct(?CrmContactService $contactService = null)
    {
        $this->contactService = $contactService ?? new CrmContactService();
    }

    public function listForUser(int $userId): array
    {
        return (new CrmSegment())->availableForUser($userId);
    }

    public function create(int $userId, string $name, array $filters): CrmSegment
    {
        if (empty($name)) {
            throw new Exception('اسم القطاع مطلوب');
        }
        $segment = new CrmSegment([
            'user_id' => $userId, 'name' => $name,
            'filters' => json_encode($filters, JSON_UNESCAPED_UNICODE), 'is_system' => 0,
        ]);
        $segment->save();
        return $segment;
    }

    public function delete(int $userId, int $segmentId): bool
    {
        $segment = (new CrmSegment())->find($segmentId);
        if (!$segment || (int) $segment->getAttribute('is_system') === 1) {
            throw new Exception('لا يمكن حذف قطاع افتراضي', 403);
        }
        if ((int) $segment->getAttribute('user_id') !== $userId) {
            throw new Exception('القطاع غير موجود', 404);
        }
        return $segment->delete();
    }

    /** ينفّذ القطاع فعليًا (بيرجع جهات الاتصال المطابقة، بنفس Pagination الفلاتر العادية) */
    public function run(int $userId, int $segmentId, int $page = 1, int $perPage = 25): array
    {
        $segment = (new CrmSegment())->find($segmentId);
        if (!$segment || ((int) $segment->getAttribute('user_id') !== $userId && $segment->getAttribute('user_id') !== null)) {
            throw new Exception('القطاع غير موجود', 404);
        }
        $filters = json_decode((string) $segment->getAttribute('filters'), true) ?: [];
        $result = $this->contactService->search($userId, $filters, $page, $perPage);
        $result['segment'] = $segment->toArray();
        return $result;
    }
}
