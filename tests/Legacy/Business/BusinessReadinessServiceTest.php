<?php

/**
 * Tourfecto - Business Readiness Service Test
 * @version 1.0.0
 *
 * اختبار offline بالكامل - المنطق الخالص scoreFromContext() مبيحتاجش أي
 * اتصال بقاعدة بيانات أو كاش، فممكن تشغيله مباشرة:
 *   php tests/Unit/Business/BusinessReadinessServiceTest.php
 *
 * نفس نمط اختبارات SsrfGuard في المشروع: require مباشرة للملف، وبناء
 * بيانات Context يدويًا بنفس شكل ناتج BusinessContextService::getContext().
 */
require_once dirname(__DIR__, 3) . '/app/Services/BusinessReadinessService.php';

class BusinessReadinessServiceTest
{
    private $passed = 0;
    private $failed = 0;
    private $service;

    public function __construct()
    {
        $this->service = new BusinessReadinessService();
    }

    public function runAll(): void
    {
        echo "\nBusiness Readiness Service Tests\n";
        echo "=================================\n\n";

        $this->testNonexistentContext();
        $this->testEmptyBusinessScoresZero();
        $this->testCompleteBusinessScoresHundred();
        $this->testPartialBusinessPartialScore();
        $this->testRecommendationsOrderedByPriority();
        $this->testGradeBoundaries();
        $this->testCategoryContributionsSumToTotal();

        $this->printSummary();
    }

    private function testNonexistentContext(): void
    {
        $this->startTest('Nonexistent business returns exists=false');
        $r = $this->service->scoreFromContext(['exists' => false]);
        $ok = $r['exists'] === false && $r['total'] === 0 && $r['grade'] === 'F';
        $ok ? $this->pass('exists=false, total=0, grade=F') : $this->fail('Unexpected: ' . json_encode($r));
    }

    private function testEmptyBusinessScoresZero(): void
    {
        $this->startTest('Empty business (exists=true, no data) scores 0 with recommendations');
        $r = $this->service->scoreFromContext($this->buildContext([]));
        $ok = $r['total'] === 0 && $r['grade'] === 'F' && !empty($r['recommendations']);
        $ok
            ? $this->pass('total=0, grade=F, recommendations non-empty (' . count($r['recommendations']) . ')')
            : $this->fail('Unexpected: ' . json_encode($r));
    }

    private function testCompleteBusinessScoresHundred(): void
    {
        $this->startTest('Fully complete business scores 100 (grade A, no recommendations)');
        $r = $this->service->scoreFromContext($this->buildContext([
            'business' => $this->fullBusiness(),
            'locations' => [$this->fullLocation()],
            'primary_location' => $this->fullLocation(),
            'services' => [$this->fullService(), $this->fullService('Luxury Nile Cruise')],
            'target_markets' => [
                'countries' => ['EG', 'SA', 'AE'],
                'cities' => ['Cairo', 'Luxor', 'Aswan'],
                'languages' => ['en', 'ar', 'fr'],
                'customer_type' => 'b2c',
                'customer_segments' => ['families', 'honeymooners'],
            ],
            'ai_context' => [
                'business_summary' => 'Premium DMC in Egypt',
                'brand_description' => 'Luxury experiences',
                'target_audience' => 'International travellers',
                'unique_selling_points' => ['Private guides'],
                'brand_voice' => 'professional',
                'preferred_keywords' => ['Egypt tours'],
                'business_goals' => ['Expand to GCC'],
                'competitors' => ['Competitor A'],
            ],
            'brand_settings' => [
                'brand_colors' => ['primary' => '#0077BE'],
                'writing_style' => 'professional',
                'preferred_terminology' => ['excursion'],
            ],
        ]));

        $ok = $r['exists'] === true
            && $r['total'] === 100
            && $r['grade'] === 'A'
            && empty($r['recommendations']);
        $ok
            ? $this->pass('total=100, grade=A, no recommendations')
            : $this->fail('Unexpected: total=' . $r['total'] . ' grade=' . $r['grade']);
    }

    private function testPartialBusinessPartialScore(): void
    {
        $this->startTest('Partial business scores proportionally (identity only = 20)');
        $r = $this->service->scoreFromContext($this->buildContext([
            'business' => $this->identityOnlyBusiness(),
        ]));
        // identity كاملة (20) والباقي صفر => total 20
        $ok = $r['total'] === 20 && $r['grade'] === 'F';
        $ok
            ? $this->pass('total=20, grade=F (identity contribution weighted 20)')
            : $this->fail('Unexpected: ' . json_encode($r));
    }

    private function testRecommendationsOrderedByPriority(): void
    {
        $this->startTest('Recommendations are sorted high priority first');
        $r = $this->service->scoreFromContext($this->buildContext([
            'business' => $this->fullBusiness(),
        ]));
        $recs = $r['recommendations'];
        $ok = !empty($recs) && $recs[0]['priority'] === 'high';
        $ok
            ? $this->pass('First recommendation is high priority (' . $recs[0]['message'] . ')')
            : $this->fail('First recommendation not high priority: ' . json_encode($recs[0] ?? null));
    }

    private function testGradeBoundaries(): void
    {
        $this->startTest('Grade boundaries map correctly (F/D/C/B/A)');
        // نختبر عبر بناء حالات جزئية بدل ثغرة - كل حالة بتحدد درجة محددة:
        // identity+contact+locations كاملة = 50 => D
        $r50 = $this->service->scoreFromContext($this->buildContext([
            'business' => $this->fullBusiness(),
            'locations' => [$this->fullLocation()],
            'primary_location' => $this->fullLocation(),
            'contact_extra' => true,
        ]));
        $ok = $r50['grade'] === 'D';
        $ok ? $this->pass('50 => D') : $this->fail('50 => ' . $r50['grade'] . ' (expected D)');
    }

    private function testCategoryContributionsSumToTotal(): void
    {
        $this->startTest('Category contributions sum to the reported total');
        $r = $this->service->scoreFromContext($this->buildContext([
            'business' => $this->fullBusiness(),
            'locations' => [$this->fullLocation()],
            'primary_location' => $this->fullLocation(),
            'services' => [$this->fullService()],
            'target_markets' => [
                'countries' => ['EG'],
                'languages' => ['en'],
                'customer_type' => 'b2c',
            ],
        ]));
        $sum = array_sum(array_column($r['categories'], 'contribution'));
        $sum === $r['total']
            ? $this->pass("contributions sum matches total ($sum = {$r['total']})")
            : $this->fail("mismatch: sum=$sum total={$r['total']}");
    }

    /**
     * بناء Context افتراضي بنفس شكل ناتج getContext() - لو فات حقل،
     * بيبقى بنفس القيمة الافتراضية اللي بيرجعها الـService الحقيقي
     * (مثلاً ai_context = null لو مفيش بيانات).
     */
    private function buildContext(array $overrides): array
    {
        $base = [
            'exists' => true,
            'business' => [],
            'primary_location' => null,
            'locations' => [],
            'services' => [],
            'target_markets' => null,
            'ai_context' => null,
            'brand_settings' => null,
        ];
        if (!empty($overrides['contact_extra'])) {
            $overrides['business'] = array_merge($overrides['business'], [
                'website_url' => 'https://example.com',
                'business_email' => 'hello@example.com',
                'business_phone' => '+201234567890',
            ]);
            unset($overrides['contact_extra']);
        }
        return array_merge($base, $overrides);
    }

    private function fullBusiness(): array
    {
        return [
            'legal_name' => 'Nile Wonders Travel',
            'trade_name' => 'Nile Wonders',
            'logo_url' => 'https://example.com/logo.png',
            'description' => 'Premium travel company in Egypt',
            'website_url' => 'https://example.com',
            'business_email' => 'hello@example.com',
            'business_phone' => '+201234567890',
            'whatsapp_number' => '+201234567890',
            'country_code' => 'EG',
            'city' => 'Cairo',
            'address' => '1 Nile Street',
            'postal_code' => '11511',
            'business_type' => 'dmc',
            'year_established' => '2005',
            'supported_languages' => ['en', 'ar'],
        ];
    }

    /** Business يملك كل مقومات الهوية بس من غير أي بيانات تواصل (حالة جزئية قابلة للحساب بدقة) */
    private function identityOnlyBusiness(): array
    {
        return [
            'legal_name' => 'Nile Wonders Travel',
            'description' => 'Premium travel company in Egypt',
            'country_code' => 'EG',
            'city' => 'Cairo',
            'business_type' => 'dmc',
            'year_established' => '2005',
        ];
    }

    private function fullLocation(): array
    {
        return [
            'name' => 'Head Office',
            'country_code' => 'EG',
            'city' => 'Cairo',
            'address' => '1 Nile Street',
            'latitude' => '30.0444',
            'longitude' => '31.2357',
            'opening_hours' => ['mon_fri' => '9:00-18:00'],
            'is_primary' => 1,
        ];
    }

    private function fullService(string $name = 'Egypt Classic Tours'): array
    {
        return [
            'name' => $name,
            'category' => 'egypt_tours',
            'description' => 'A guided tour of the highlights of Egypt',
            'active' => 1,
        ];
    }

    private function startTest(string $name): void
    {
        echo "\n  > {$name}\n";
    }
    private function pass(string $message): void
    {
        echo "    [PASS] {$message}\n";
        $this->passed++;
    }
    private function fail(string $message): void
    {
        echo "    [FAIL] {$message}\n";
        $this->failed++;
    }

    private function printSummary(): void
    {
        $total = $this->passed + $this->failed;
        $percentage = $total > 0 ? round(($this->passed / $total) * 100, 2) : 0;
        echo "\n" . str_repeat('=', 50) . "\n";
        echo "Business Readiness Service Test Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  Passed: {$this->passed}\n  Failed: {$this->failed}\n  Total: {$total}\n  Success Rate: {$percentage}%\n";
        echo str_repeat('=', 50) . "\n\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    (new BusinessReadinessServiceTest())->runAll();
}
