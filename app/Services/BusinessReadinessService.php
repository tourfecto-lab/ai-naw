<?php

/**
 * Tourfecto - Business Readiness Service
 * Business Control Center - AI Audit scoring (Competitive phase 2026-08-15)
 * @version 1.0.0
 *
 * الهدف: نقطة القوة التنافسية المعادلة لـ SOCi Local Visibility Index
 * و Birdeye Brand Grader - درجة رقمية (0-100) بتقول للمستخدم "بيزنس
 * بروفايلك مكتمل قد إيه" مع تفصيل لكل فئة وترتيب الأولويات (Next Steps).
 *
 * التصميم مكون من طبقتين:
 * 1) scoreFromContext(array $context): المنطق الخالص (pure logic) اللي
 *    بياخد ناتج BusinessContextService::getContext() (نفس الـ Single
 *    Source of Truth - مفيش أي استعلام DB هنا إطلاقًا). الطبقة دي بتخلى
 *    الاختبارات تشتغل offline من غير DB (نفس نمط اختبارات SsrfGuard).
 * 2) score(int $businessId): wrapper رفيع بيجيب الـContext من الكاش/DB
 *    وبيمرره للطبقة الأولى - ده اللي بيستخدمه الـController.
 *
 * ملاحظة ملكية: زي BusinessContextService، الـService هنا مش بيفحص
 * Authorization - الـController اللي بينادي score() هو المسؤول إنه يتأكد
 * إن الـbusinessId يخص المستخدم الحالي قبل النداء.
 */
class BusinessReadinessService
{
    /**
     * أوزان الفئات السبع - مجموعها 100. قابلة للتغيير مستقبلًا (قابلة
     * للتخصيص لكل باقة/عميل) لكنها بتفضل ثابتة دلوقتي عشان الاستقرار.
     * الأوزان بتعكس أثر كل فئة على جودة مخرجات الـAI: بيانات الهوية
     * والسياق الـAI هما الأهم، ثم التواصل والمواقع، ثم الخدمات والأسواق
     * والهوية البصرية.
     */
    private const CATEGORY_WEIGHTS = [
        'identity'      => 20,
        'ai_context'    => 20,
        'contact'       => 15,
        'locations'     => 15,
        'services'      => 10,
        'target_markets' => 10,
        'brand'         => 10,
    ];

    /**
     * درجة الجاهزية الكاملة للـBusiness - دي اللي بيستخدمها الـController.
     */
    public function score(int $businessId): array
    {
        $context = (new BusinessContextService())->getContext($businessId);
        return $this->scoreFromContext($context);
    }

    /**
     * المنطق الخالص للتقييم - بيفهم ناتج getContext() بس، ومفيش أي
     * اعتماد على DB/كاش/سيرفر هنا عشان الاختبارات تشتغل مباشرة.
     *
     * @param array $context ناتج BusinessContextService::getContext()
     * @return array{
     *   exists: bool,
     *   total: int,
     *   grade: string,
     *   categories: array<string,array{score:int,weight:int,contribution:int,label:string}>,
     *   recommendations: array<int,array{category:string,priority:string,message:string}>,
     *   generated_at: string
     * }
     */
    public function scoreFromContext(array $context): array
    {
        if (empty($context['exists'])) {
            return [
                'exists' => false,
                'total' => 0,
                'grade' => 'F',
                'categories' => [],
                'recommendations' => [],
                'generated_at' => date('c'),
            ];
        }

        $scorers = [
            'identity'       => ['label' => 'Basic business details', 'weight' => self::CATEGORY_WEIGHTS['identity'],       'fn' => fn () => $this->scoreIdentity($context)],
            'contact'        => ['label' => 'Contact and verification details',  'weight' => self::CATEGORY_WEIGHTS['contact'],        'fn' => fn () => $this->scoreContact($context)],
            'locations'      => ['label' => 'Locations and branches',        'weight' => self::CATEGORY_WEIGHTS['locations'],      'fn' => fn () => $this->scoreLocations($context)],
            'services'       => ['label' => 'Services',                'weight' => self::CATEGORY_WEIGHTS['services'],       'fn' => fn () => $this->scoreServices($context)],
            'target_markets' => ['label' => 'Target markets',      'weight' => self::CATEGORY_WEIGHTS['target_markets'], 'fn' => fn () => $this->scoreTargetMarkets($context)],
            'ai_context'     => ['label' => 'Smart context forAI',     'weight' => self::CATEGORY_WEIGHTS['ai_context'],     'fn' => fn () => $this->scoreAiContext($context)],
            'brand'          => ['label' => 'Visual identity and tone', 'weight' => self::CATEGORY_WEIGHTS['brand'],          'fn' => fn () => $this->scoreBrand($context)],
        ];

        $categories = [];
        $total = 0;
        foreach ($scorers as $key => $def) {
            $categoryScore = $def['fn']();
            $contribution = (int) round($categoryScore * $def['weight'] / 100);
            $total += $contribution;
            $categories[$key] = [
                'score' => $categoryScore,
                'weight' => $def['weight'],
                'contribution' => $contribution,
                'label' => $def['label'],
            ];
        }
        // جمع كل فئة (بعد تقريب كل مساهمة) ممكن يطلع 99/101 في الحافة -
        // نثبّته على 100 لو خرج عن النطاق (درجة نهاية منطقية مش تجميع عشوائي).
        $total = max(0, min(100, $total));

        return [
            'exists' => true,
            'total' => $total,
            'grade' => $this->gradeFor($total),
            'categories' => $categories,
            'recommendations' => $this->buildRecommendations($categories, $context),
            'generated_at' => date('c'),
        ];
    }

    /**
     * أهم النصائح المرتبة حسب الأثر (أولًا الفئات الأثقل وزنًا، وبعدين
     * الفرص الأعلى فاقدًا داخل كل فئة). كل نص رسالة إجراء واضح.
     */
    private function buildRecommendations(array $categories, array $context): array
    {
        $recommendations = [];

        foreach ($categories as $key => $cat) {
            if ($cat['score'] === 100) {
                continue;
            }
            $next = $this->topMissingSignals($key, $context);
            foreach ($next as $signal) {
                $recommendations[] = [
                    'category' => $key,
                    'priority' => $cat['weight'] >= 20 ? 'high' : ($cat['weight'] >= 15 ? 'medium' : 'low'),
                    'message' => $signal,
                ];
            }
        }

        // ترتيب: الأولوية (high > medium > low) وبعدين الفئة الأثقل وزنًا
        $priorityRank = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($recommendations, function (array $a, array $b) use ($priorityRank, $categories) {
            $pa = $priorityRank[$a['priority']];
            $pb = $priorityRank[$b['priority']];
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return $categories[$b['category']]['weight'] <=> $categories[$a['category']]['weight'];
        });

        return $recommendations;
    }

    /**
     * أكتر حاجة ناقصة في الفئة - بترجع أقصى 3 رسائل فعلية (مش كل فاضي).
     */
    private function topMissingSignals(string $category, array $context): array
    {
        $missing = [];

        switch ($category) {
            case 'identity':
                $b = $context['business'];
                if (empty($b['legal_name']) && empty($b['trade_name'])) {
                    $missing[] = 'Add the trade name or legal name of the business';
                }
                if (empty($b['business_type'])) {
                    $missing[] = 'Set the company type (travel agency, tour operator, DMC...)';
                }
                if (empty($b['description'])) {
                    $missing[] = 'Write a short description of the business and main services';
                }
                if (empty($b['year_established'])) {
                    $missing[] = 'Add the founding year to build trust with customers';
                }
                break;

            case 'contact':
                $b = $context['business'];
                if (empty($b['website_url'])) {
                    $missing[] = 'Add your website URL - essential for any content AI';
                }
                if (empty($b['business_email'])) {
                    $missing[] = 'Add the official business email';
                }
                if (empty($b['business_phone']) && empty($b['whatsapp_number'])) {
                    $missing[] = 'Add a phone or WhatsApp number for direct contact';
                }
                break;

            case 'locations':
                $locations = $context['locations'] ?? [];
                if (empty($locations)) {
                    $missing[] = 'Add at least one location - the headquarters is required';
                } else {
                    $primary = $context['primary_location'] ?? $locations[0];
                    if (empty($primary['opening_hours'])) {
                        $missing[] = 'Add working hours for the main location - helps search engines andAI';
                    }
                    if (empty($primary['address'])) {
                        $missing[] = 'Complete the main location address (street and area)';
                    }
                    if (empty($primary['latitude']) || empty($primary['longitude'])) {
                        $missing[] = 'Add the main location coordinates to appear on maps';
                    }
                }
                break;

            case 'services':
                $services = $context['services'] ?? [];
                if (empty($services)) {
                    $missing[] = 'Add at least one active service (tours, Nile cruises...)';
                } else {
                    $hasDescription = array_filter($services, fn ($s) => !empty($s['description']));
                    if (count($hasDescription) !== count($services)) {
                        $missing[] = 'Add a detailed description for each service to feed the content ofAI';
                    }
                    $hasCategory = array_filter($services, fn ($s) => !empty($s['category']));
                    if (count($hasCategory) !== count($services)) {
                        $missing[] = 'Classify each service into a clear category (trips, hotels, transport...)';
                    }
                }
                break;

            case 'target_markets':
                $tm = $context['target_markets'] ?? null;
                if (empty($tm['countries'])) {
                    $missing[] = 'Set the target travel countries';
                }
                if (empty($tm['languages'])) {
                    $missing[] = 'Set your target audience languages';
                }
                if (empty($tm['customer_type'])) {
                    $missing[] = 'Set the customer type (individuals B2C or businesses B2B or both)';
                }
                break;

            case 'ai_context':
                $ai = $context['ai_context'] ?? null;
                if (empty($ai['business_summary'])) {
                    $missing[] = 'Write a smart business summary - the backbone of every content generation';
                }
                if (empty($ai['target_audience'])) {
                    $missing[] = 'Define your target audience clearly so the AI addresses it correctly';
                }
                if (empty($ai['unique_selling_points'])) {
                    $missing[] = 'Add your competitive advantages (features your competitors do not have)';
                }
                if (empty($ai['preferred_keywords'])) {
                    $missing[] = 'Add your preferred keywords to improve SEO';
                }
                break;

            case 'brand':
                $brand = $context['brand_settings'] ?? null;
                if (empty($brand['brand_colors'])) {
                    $missing[] = 'Set the official brand colors';
                }
                if (empty($brand['writing_style'])) {
                    $missing[] = 'Set the approved writing style (formal, friendly...)';
                }
                if (empty($brand['preferred_terminology'])) {
                    $missing[] = 'Set preferred terms to ensure consistent messaging';
                }
                break;
        }

        return array_slice($missing, 0, 3);
    }

    private function scoreIdentity(array $context): int
    {
        $b = $context['business'];
        $score = 0;
        if (!empty($b['legal_name']) || !empty($b['trade_name'])) {
            $score += 30;
        }
        if (!empty($b['business_type'])) {
            $score += 20;
        }
        if (!empty($b['description'])) {
            $score += 20;
        }
        if (!empty($b['year_established'])) {
            $score += 10;
        }
        if (!empty($b['country_code'])) {
            $score += 10;
        }
        if (!empty($b['city'])) {
            $score += 10;
        }
        return min(100, $score);
    }

    private function scoreContact(array $context): int
    {
        $b = $context['business'];
        $score = 0;
        if (!empty($b['website_url'])) {
            $score += 25;
        }
        if (!empty($b['business_email'])) {
            $score += 20;
        }
        if (!empty($b['business_phone'])) {
            $score += 15;
        }
        if (!empty($b['whatsapp_number'])) {
            $score += 10;
        }
        if (!empty($b['logo_url'])) {
            $score += 10;
        }
        if (!empty($b['address'])) {
            $score += 10;
        }
        if (!empty($b['postal_code'])) {
            $score += 5;
        }
        if (!empty($b['supported_languages'])) {
            $score += 5;
        }
        return min(100, $score);
    }

    private function scoreLocations(array $context): int
    {
        $locations = $context['locations'] ?? [];
        if (empty($locations)) {
            return 0;
        }
        $primary = $context['primary_location'] ?? $locations[0];

        $score = 30; // يوجد موقع واحد على الأقل
        if (!empty($context['primary_location'])) {
            $score += 30; // يوجد مقر رئيسي محدد
        }
        if (!empty($primary['country_code']) && !empty($primary['city'])) {
            $score += 15;
        }
        if (!empty($primary['opening_hours'])) {
            $score += 15;
        }
        if (!empty($primary['address'])) {
            $score += 10;
        }
        return min(100, $score);
    }

    private function scoreServices(array $context): int
    {
        $services = $context['services'] ?? [];
        if (empty($services)) {
            return 0;
        }
        $score = 60; // خدمة نشطة واحدة على الأقل
        $all = count($services);
        $withDescription = count(array_filter($services, fn ($s) => !empty($s['description'])));
        $withCategory = count(array_filter($services, fn ($s) => !empty($s['category'])));
        if ($all > 0 && $withDescription === $all) {
            $score += 20;
        }
        if ($all > 0 && $withCategory === $all) {
            $score += 20;
        }
        return min(100, $score);
    }

    private function scoreTargetMarkets(array $context): int
    {
        $tm = $context['target_markets'] ?? null;
        if (!$tm) {
            return 0;
        }
        $score = 0;
        if (!empty($tm['countries'])) {
            $score += 30;
        }
        if (!empty($tm['cities'])) {
            $score += 20;
        }
        if (!empty($tm['languages'])) {
            $score += 20;
        }
        if (!empty($tm['customer_type'])) {
            $score += 15;
        }
        if (!empty($tm['customer_segments'])) {
            $score += 15;
        }
        return min(100, $score);
    }

    private function scoreAiContext(array $context): int
    {
        $ai = $context['ai_context'] ?? null;
        if (!$ai) {
            return 0;
        }
        $score = 0;
        if (!empty($ai['business_summary'])) {
            $score += 20;
        }
        if (!empty($ai['brand_description'])) {
            $score += 15;
        }
        if (!empty($ai['target_audience'])) {
            $score += 15;
        }
        if (!empty($ai['unique_selling_points'])) {
            $score += 15;
        }
        if (!empty($ai['brand_voice'])) {
            $score += 10;
        }
        if (!empty($ai['preferred_keywords'])) {
            $score += 10;
        }
        if (!empty($ai['business_goals'])) {
            $score += 10;
        }
        if (!empty($ai['competitors'])) {
            $score += 5;
        }
        return min(100, $score);
    }

    private function scoreBrand(array $context): int
    {
        $brand = $context['brand_settings'] ?? null;
        if (!$brand) {
            return 0;
        }
        $score = 0;
        if (!empty($brand['brand_colors'])) {
            $score += 40;
        }
        if (!empty($brand['writing_style'])) {
            $score += 30;
        }
        if (!empty($brand['preferred_terminology'])) {
            $score += 30;
        }
        return min(100, $score);
    }

    /**
     * تحويل الدرجة لرمز تقييم (A-F) - نفس فلسفة SOCi/Birdeye: رمز سهل
     * الواجهة بتعرضه بلون معين من غير ما تشرح الأرقام.
     */
    private function gradeFor(int $total): string
    {
        if ($total >= 90) {
            return 'A';
        }
        if ($total >= 75) {
            return 'B';
        }
        if ($total >= 60) {
            return 'C';
        }
        if ($total >= 40) {
            return 'D';
        }
        return 'F';
    }
}
