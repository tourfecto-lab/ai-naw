<?php

/**
 * Tourfecto - CRM AI Summary Service (بند 27)
 * @version 1.0.0
 *
 * نفس مبدأ CrmAiAssistantService: نجمع البيانات الحقيقية أولًا (Customer
 * Overview / Recent Activity / Current Deal / Open Tasks / Potential
 * Risks) من الخدمات الموجودة بالفعل (Customer360Service، DealService،
 * TaskService) بدون أي استدعاء AI، ثم GeminiClient يُستخدم فقط لصياغة
 * فقرة موجزة من هذه البيانات - مع fallback حتمي (Deterministic) بصيغة
 * نقاط لو فشل استدعاء الـAI، بحيث الميزة لا تعتمد كليًا على توفر مفتاح AI.
 */
class CrmAiSummaryService
{
    private $ai;
    private $customer360;
    private $nbaService;

    public function __construct(?GeminiClient $ai = null)
    {
        $this->ai = $ai ?? new GeminiClient();
        $this->customer360 = new CrmCustomer360Service();
        $this->nbaService = new CrmNextBestActionService();
    }

    public function summarizeContact(int $userId, int $contactId): array
    {
        $profile = $this->customer360->build($userId, $contactId);

        $openTasks = array_values(array_filter($profile['tasks'], fn ($t) => !in_array($t['status'], ['done', 'cancelled'], true)));
        $currentDeal = $profile['deals'][0] ?? null;

        $facts = [
            'customer_overview' => $profile['contact']['name'] . ' - ' . ($profile['contact']['status'] ?? ''),
            'recent_activity' => array_slice(array_map(fn ($a) => $a['action'] ?? '', $profile['timeline']), 0, 5),
            'current_deal' => $currentDeal ? ($currentDeal['title'] . ' (' . $currentDeal['stage_name'] . ', ' . $currentDeal['value'] . ' ' . $currentDeal['currency'] . ')') : null,
            'open_tasks_count' => count($openTasks),
            'leads_count' => count($profile['leads']),
        ];

        $risks = [];
        if (empty($profile['timeline'])) {
            $risks[] = 'لا يوجد أي نشاط مسجّل بعد على هذا العميل';
        }
        if (!empty($currentDeal) && $currentDeal['status'] === 'open') {
            $staleDays = (int) floor((time() - strtotime($currentDeal['updated_at'])) / 86400);
            if ($staleDays > 14) {
                $risks[] = 'الصفقة الحالية بدون تحديث منذ ' . $staleDays . ' يوم';
            }
        }
        $facts['potential_risks'] = $risks ?: ['لا توجد مخاطر واضحة حاليًا بناءً على البيانات المتاحة'];

        $recommendedAction = null;
        if (!empty($profile['leads'])) {
            try {
                $recommendedAction = $this->nbaService->forLead((int) $profile['leads'][0]['id']);
            } catch (Exception $e) {
                $recommendedAction = null;
            }
        }
        $facts['recommended_next_action'] = $recommendedAction;

        $summaryText = $this->composeSummary($facts, 'عميل: ' . $profile['contact']['name']);

        return ['facts' => $facts, 'summary' => $summaryText];
    }

    private function composeSummary(array $facts, string $subject): string
    {
        $factsJson = json_encode($facts, JSON_UNESCAPED_UNICODE);
        $prompt = "لخّص حالة \"{$subject}\" داخل نظام CRM في فقرة قصيرة بالعربية المصرية (5 أسطر "
            . "بحد أقصى)، بالاعتماد حصريًا على البيانات الحقيقية التالية (JSON). لا تخترع أي معلومة "
            . "أو رقم غير موجود فيها.\n\nالبيانات:\n{$factsJson}\n\nالملخص:";

        $response = $this->ai->generateContent($prompt, ['task'=>'crm_summary', 'maxOutputTokens' => 400]);
        if (!empty($response['success']) && !empty($response['data'])) {
            return trim((string) $response['data']);
        }

        // Fallback حتمي بصيغة نقاط - لا يعتمد على توفر AI Credits
        $lines = [];
        $lines[] = 'نظرة عامة: ' . ($facts['customer_overview'] ?? '-');
        if (!empty($facts['current_deal'])) {
            $lines[] = 'الصفقة الحالية: ' . $facts['current_deal'];
        }
        $lines[] = 'مهام مفتوحة: ' . ($facts['open_tasks_count'] ?? 0);
        if (!empty($facts['potential_risks'])) {
            $lines[] = 'مخاطر محتملة: ' . implode('، ', $facts['potential_risks']);
        }
        if (!empty($facts['recommended_next_action']['action'])) {
            $lines[] = 'الإجراء التالي المقترح: ' . $facts['recommended_next_action']['action'] . ' - ' . $facts['recommended_next_action']['reason'];
        }
        return implode("\n", $lines);
    }
}
