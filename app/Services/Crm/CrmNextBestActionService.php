<?php

/**
 * Tourfecto - CRM Next Best Action Service (بند 10)
 * @version 1.0.0
 *
 * كل اقتراح هنا Rule-based ومبني على حالة الـLead/Deal الفعلية في القاعدة
 * فقط. لا يوجد أي تنفيذ تلقائي لأي Action خارجي (اتصال/رسالة/إيميل) -
 * الخدمة تقترح فقط، والمستخدم هو من ينفّذ يدويًا (بند 10: "لا ينفذ Action
 * خارجي تلقائيًا إلا إذا كان هناك Integration رسمي وصلاحية واضحة" - ولا
 * يوجد تكامل رسمي فعلي مُفعّل بعد في هذا الموديول).
 */
class CrmNextBestActionService
{
    /** الإجراءات الممكنة كما وردت حرفيًا في الطلب الأصلي */
    private const ACTIONS = ['call', 'send_message', 'send_email', 'schedule_meeting', 'follow_up', 'send_proposal', 'wait', 'close'];

    public function forLead(int $leadId): array
    {
        $lead = (new CrmLead())->find($leadId);
        if (!$lead) {
            throw new Exception('Lead غير موجود', 404);
        }

        $status = $lead->getAttribute('status');
        $daysSinceEngagement = $lead->getAttribute('last_engagement_at')
            ? (int) floor((time() - strtotime($lead->getAttribute('last_engagement_at'))) / 86400)
            : null;
        $hasFollowUp = !empty($lead->getAttribute('next_follow_up_at'));
        $hasPhone = !empty((new CrmContact())->find((int) $lead->getAttribute('contact_id'))?->getAttribute('phone'));

        if ($status === 'disqualified' || $status === 'converted') {
            return $this->result('close', 'الحالة الحالية (' . $status . ') لا تحتاج إجراء إضافي');
        }
        if ($status === 'new' && $daysSinceEngagement === null) {
            return $this->result($hasPhone ? 'call' : 'send_message', 'Lead جديد بدون أي تواصل مسجّل بعد - أول تواصل مطلوب');
        }
        if ($daysSinceEngagement !== null && $daysSinceEngagement > 14 && !$hasFollowUp) {
            return $this->result('follow_up', 'لا يوجد تفاعل منذ ' . $daysSinceEngagement . ' يوم ولا متابعة مجدولة');
        }
        if ($status === 'qualified') {
            return $this->result('send_proposal', 'Lead مؤهّل بالفعل - الخطوة التالية المنطقية هي عرض السعر');
        }
        if ($status === 'nurturing' && $daysSinceEngagement !== null && $daysSinceEngagement <= 3) {
            return $this->result('schedule_meeting', 'تفاعل حديث خلال آخر 3 أيام - فرصة جيدة لحجز اجتماع');
        }
        if ($hasFollowUp) {
            return $this->result('wait', 'يوجد متابعة مجدولة بالفعل - لا حاجة لإجراء إضافي الآن');
        }

        return $this->result('follow_up', 'لا توجد إشارة كافية لاقتراح إجراء أدق - المتابعة الدورية هي الأنسب حاليًا');
    }

    public function forDeal(int $dealId, int $ownerUserId): array
    {
        $deal = (new CrmDeal())->find($dealId);
        if (!$deal || (int) $deal->getAttribute('owner_user_id') !== $ownerUserId) {
            throw new Exception('الصفقة غير موجودة', 404);
        }

        if ($deal->getAttribute('status') !== 'open') {
            return $this->result('close', 'الصفقة مغلقة بالفعل (' . $deal->getAttribute('status') . ')');
        }

        $daysSinceUpdate = (int) floor((time() - strtotime($deal->getAttribute('updated_at'))) / 86400);
        $expectedClose = $deal->getAttribute('expected_close_date');
        $daysToClose = $expectedClose ? (int) floor((strtotime($expectedClose) - time()) / 86400) : null;

        if ($daysSinceUpdate > 21) {
            return $this->result('follow_up', 'لا يوجد أي تحديث على الصفقة منذ ' . $daysSinceUpdate . ' يوم - خطر فقدان الفرصة');
        }
        if ($daysToClose !== null && $daysToClose <= 7 && $daysToClose >= 0) {
            return $this->result('call', 'تاريخ الإغلاق المتوقع خلال ' . $daysToClose . ' يوم - تواصل مباشر مطلوب لتأكيد القرار');
        }
        if ($daysToClose !== null && $daysToClose < 0) {
            return $this->result('follow_up', 'تجاوزنا تاريخ الإغلاق المتوقع بدون تحديث - يحتاج مراجعة فورية');
        }

        return $this->result('send_message', 'متابعة دورية لإبقاء الصفقة نشطة');
    }

    private function result(string $action, string $reason): array
    {
        return ['action' => $action, 'reason' => $reason];
    }
}
