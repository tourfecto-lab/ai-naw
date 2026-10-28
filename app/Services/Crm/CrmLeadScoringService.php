<?php

/**
 * Tourfecto - CRM AI Lead Scoring Service (بند 8)
 * @version 1.0.0
 *
 * تصميم متعمّد: التقييم هنا Rule-based/Heuristic شفاف بالكامل - كل نقطة
 * في السكور مربوطة بإشارة حقيقية موجودة فعليًا في بيانات الحساب (Source،
 * سرعة الاستجابة/التفاعل الأخير، قيمة الصفقة المقدّرة، عدد التفاعلات
 * المسجّلة). لا يوجد استدعاء لأي نموذج AI خارجي هنا ولا أي بيانات مُختلقة
 * (بند 39/8: "لا تخترع معلومات غير موجودة") - وبالتالي لا يستهلك AI
 * Credits. هذا أساس واضح وقابل للتفسير الكامل (Explainable)، وأي إشارات
 * إضافية مستقبلية (رد فعل بريد/واتساب حقيقي مثلًا) يمكن إضافتها هنا لاحقًا
 * دون تغيير الواجهة الخارجية للخدمة.
 */
class CrmLeadScoringService
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * يحسب Score (0-100) وPriority وReason نصي لسبب التقييم، بناءً على
     * إشارات حقيقية فقط، ثم يحفظها على سجل الـLead.
     */
    public function scoreLead(int $leadId): CrmLead
    {
        $lead = (new CrmLead())->find($leadId);
        if (!$lead) {
            throw new Exception('Lead غير موجود', 404);
        }

        $signals = $this->collectSignals($lead);
        [$score, $reasons] = $this->computeScore($signals);
        $priority = $score >= 70 ? 'high' : ($score >= 40 ? 'medium' : 'low');

        $lead->setAttribute('score', $score);
        $lead->setAttribute('priority', $priority);
        $lead->setAttribute('score_reason', implode('؛ ', $reasons));
        $lead->save();

        ActivityLog::record('crm', 'lead.scored', [
            'subject_type' => 'crm_leads', 'subject_id' => $leadId,
            'meta' => ['score' => $score, 'priority' => $priority],
        ]);

        return $lead;
    }

    /** يجمع كل الإشارات الحقيقية المتاحة عن الـLead - بدون أي قيمة افتراضية مُختلقة */
    private function collectSignals(CrmLead $lead): array
    {
        $contactId = (int) $lead->getAttribute('contact_id');

        $tasksCount = (int) $this->scalar(
            "SELECT COUNT(*) FROM crm_tasks WHERE related_type IN ('lead', 'crm_leads') AND related_id = ?",
            [$lead->getAttribute('id')]
        );
        $notesCount = (int) $this->scalar(
            "SELECT COUNT(*) FROM crm_notes WHERE related_type IN ('lead', 'crm_leads') AND related_id = ?",
            [$lead->getAttribute('id')]
        );
        $meetingsCount = (int) $this->scalar(
            "SELECT COUNT(*) FROM crm_meetings WHERE contact_id = ?",
            [$contactId]
        );

        $lastEngagement = $lead->getAttribute('last_engagement_at');
        $daysSinceEngagement = $lastEngagement ? (int) floor((time() - strtotime($lastEngagement)) / 86400) : null;

        $createdAt = $lead->getAttribute('created_at');
        $daysSinceCreated = $createdAt ? (int) floor((time() - strtotime($createdAt)) / 86400) : 0;

        return [
            'source' => $lead->getAttribute('source'),
            'value' => $lead->getAttribute('value') !== null ? (float) $lead->getAttribute('value') : null,
            'status' => $lead->getAttribute('status'),
            'interactions' => $tasksCount + $notesCount + $meetingsCount,
            'days_since_engagement' => $daysSinceEngagement,
            'days_since_created' => $daysSinceCreated,
            'has_next_follow_up' => !empty($lead->getAttribute('next_follow_up_at')),
        ];
    }

    /** منطق التسجيل - قابل للمراجعة بالكامل، كل بند مُفسَّر بسبب واضح */
    private function computeScore(array $s): array
    {
        $score = 20; // نقطة بداية محايدة لأي Lead جديد (يمثّل "لسه مجهول")
        $reasons = [];

        // جودة المصدر: مصادر ذات نية شراء أعلى تاريخيًا (توصية/واتساب مباشر)
        if (in_array($s['source'], ['referral', 'whatsapp'], true)) {
            $score += 15;
            $reasons[] = 'المصدر (' . $s['source'] . ') عادة ما يكون بنية أقوى للتحويل';
        } elseif ($s['source'] === 'website') {
            $score += 8;
        }

        // التفاعل الحديث = إشارة اهتمام فعلي
        if ($s['days_since_engagement'] !== null) {
            if ($s['days_since_engagement'] <= 2) {
                $score += 20;
                $reasons[] = 'تفاعل خلال آخر يومين';
            } elseif ($s['days_since_engagement'] <= 7) {
                $score += 10;
                $reasons[] = 'تفاعل خلال آخر أسبوع';
            } elseif ($s['days_since_engagement'] > 30) {
                $score -= 15;
                $reasons[] = 'لا يوجد تفاعل منذ أكثر من 30 يوم';
            }
        } else {
            $reasons[] = 'لا يوجد تفاعل مسجّل بعد';
        }

        // قيمة الصفقة المقدّرة (لو موجودة فعليًا)
        if ($s['value'] !== null && $s['value'] > 0) {
            $score += $s['value'] >= 5000 ? 20 : ($s['value'] >= 1000 ? 10 : 5);
            $reasons[] = 'قيمة تقديرية مسجّلة: ' . $s['value'];
        }

        // عدد التفاعلات المسجّلة (مهام/ملاحظات/اجتماعات فعلية)
        if ($s['interactions'] >= 3) {
            $score += 10;
            $reasons[] = 'عدد تفاعلات مسجّلة مرتفع (' . $s['interactions'] . ')';
        } elseif ($s['interactions'] === 0) {
            $score -= 5;
            $reasons[] = 'لا توجد أي مهام/ملاحظات/اجتماعات مسجّلة بعد';
        }

        // متابعة قادمة مجدولة = إدارة فعّالة للفرصة
        if ($s['has_next_follow_up']) {
            $score += 5;
        }

        // لو Lead قديم بدون تحرك حالته لسه "جديد" - إشارة سلبية
        if ($s['status'] === 'new' && $s['days_since_created'] > 14) {
            $score -= 10;
            $reasons[] = 'لسه بحالة "جديد" رغم مرور ' . $s['days_since_created'] . ' يوم';
        }

        $score = max(0, min(100, $score));
        if (empty($reasons)) {
            $reasons[] = 'بيانات محدودة حاليًا - تقييم مبدئي فقط';
        }

        return [$score, $reasons];
    }

    private function scalar(string $sql, array $params)
    {
        $rows = $this->db->query($sql, $params);
        if (empty($rows)) {
            return 0;
        }
        return reset($rows[0]);
    }
}
