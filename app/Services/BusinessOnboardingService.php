<?php

/**
 * Tourfecto - Business Onboarding Service
 * Business Control Center Phase 17: Onboarding wizard progress
 * @version 1.0.0
 *
 * بيحسب حالة إكمال خطوات الـOnboarding للـBusiness من بيانات فعلية
 * (نفس BusinessContextService - Single Source of Truth). الـWizard على
 * مستوى الموقع القديم (OnboardingController::complete) بيعمل Website +
 * competitors + audit. ده بيكمّله على مستوى الـBusiness: بيقول للمستخدم
 * إيه الخطوات المكمّلة وإيه اللي لسه - من غير تخزين حالة منفصلة قابلة
 * للتصادم (البيانات نفسها هي الحالة).
 *
 * طبقتين (نفس نمط BusinessReadinessService):
 * 1) progressFromContext(array $context): pure logic - قابل للاختبار offline.
 * 2) progress(int $businessId): wrapper بيجيب الـContext ويمرره.
 */
class BusinessOnboardingService
{
    /** خطوات الـOnboarding بالترتيب مع مفتاح الفحص في الـcontext */
    public const STEPS = [
        ['key' => 'business_identity', 'label' => 'بيانات النشاط الأساسية'],
        ['key' => 'contact_info',      'label' => 'بيانات التواصل'],
        ['key' => 'locations',         'label' => 'المواقع والفروع'],
        ['key' => 'services',          'label' => 'الخدمات'],
        ['key' => 'target_markets',    'label' => 'الأسواق المستهدفة'],
        ['key' => 'ai_context',        'label' => 'السياق الذكي للـAI'],
        ['key' => 'brand_settings',    'label' => 'الهوية البصرية'],
        ['key' => 'team',              'label' => 'فريق العمل'],
    ];

    /**
     * حساب تقدم الـOnboarding من الـContext - pure logic.
     *
     * @param array $context ناتج BusinessContextService::getContext()
     * @return array{
     *   exists: bool,
     *   steps: array<int,array{key:string,label:string,completed:bool}>,
     *   completed_steps: int,
     *   total_steps: int,
     *   progress_percent: int,
     *   all_completed: bool,
     *   next_step: ?string
     * }
     */
    public function progressFromContext(array $context): array
    {
        if (empty($context['exists'])) {
            return [
                'exists' => false,
                'steps' => [],
                'completed_steps' => 0,
                'total_steps' => count(self::STEPS),
                'progress_percent' => 0,
                'all_completed' => false,
                'next_step' => self::STEPS[0]['key'] ?? null,
            ];
        }

        $steps = [];
        foreach (self::STEPS as $step) {
            $steps[] = [
                'key' => $step['key'],
                'label' => $step['label'],
                'completed' => $this->isStepCompleted($step['key'], $context),
            ];
        }

        $completed = count(array_filter($steps, fn ($s) => $s['completed']));
        $total = count($steps);
        $percent = $total > 0 ? (int) round($completed * 100 / $total) : 0;
        $nextStep = null;
        foreach ($steps as $step) {
            if (!$step['completed']) {
                $nextStep = $step['key'];
                break;
            }
        }

        return [
            'exists' => true,
            'steps' => $steps,
            'completed_steps' => $completed,
            'total_steps' => $total,
            'progress_percent' => $percent,
            'all_completed' => $completed === $total,
            'next_step' => $nextStep,
        ];
    }

    /** wrapper - نفس progressFromContext لكن بيجيب الـContext من الكاش/DB */
    public function progress(int $businessId): array
    {
        $context = (new BusinessContextService())->getContext($businessId);
        return $this->progressFromContext($context);
    }

    // ============================================
    // Pure step checks (قابلة للاختبار بشكل مستقل)
    // ============================================

    public function isStepCompleted(string $stepKey, array $context): bool
    {
        switch ($stepKey) {
            case 'business_identity':
                return $this->hasIdentity($context);
            case 'contact_info':
                return $this->hasContact($context);
            case 'locations':
                return count($context['locations'] ?? []) > 0;
            case 'services':
                return count($context['services'] ?? []) > 0;
            case 'target_markets':
                return $this->hasTargetMarkets($context);
            case 'ai_context':
                return !empty($context['ai_context']['business_summary']);
            case 'brand_settings':
                return !empty($context['brand_settings']);
            case 'team':
                return $this->hasTeam($context);
            default:
                return false;
        }
    }

    /** هل الهوية الأساسية مكتملة؟ (الاسم القانوني + النوع على الأقل) */
    public function hasIdentity(array $context): bool
    {
        $business = $context['business'] ?? [];
        $legal = (string) ($business['legal_name'] ?? '');
        $type = (string) ($business['business_type'] ?? '');
        return $legal !== '' && $type !== '';
    }

    /** هل فيه وسيلة تواصل واحدة على الأقل؟ */
    public function hasContact(array $context): bool
    {
        $business = $context['business'] ?? [];
        return !empty($business['business_email'])
            || !empty($business['business_phone'])
            || !empty($business['whatsapp_number'])
            || !empty($business['website_url']);
    }

    /** هل حدد أسواق مستهدفة (دول أو لغات أو عملاء)؟ */
    public function hasTargetMarkets(array $context): bool
    {
        $markets = $context['target_markets'] ?? [];
        $countries = $markets['countries'] ?? [];
        $languages = $markets['languages'] ?? [];
        $cities = $markets['cities'] ?? [];
        $customers = $markets['target_customers'] ?? [];
        return count($countries) > 0 || count($languages) > 0 || count($cities) > 0 || !empty($customers);
    }

    /**
     * هل في فريق؟ المالك نفسه = فريق (على الأقل شخص واحد). فبنعتبر الخطوة
     * مكتملة دايماً طالما الـBusiness موجود - صحيح بس هي الخطوة الوحيدة
     * اللي ممكن تعتبر "مكتملة افتراضيًا" عشان دعوة أعضاء إضافيين أمر
     * اختياري، والمالك نفسه فريق شرعي. لو عايز نلزم بدعوة فعلية، نعدّل
     * السطر هنا بس.
     */
    public function hasTeam(array $context): bool
    {
        return !empty($context['business']['id']);
    }
}
