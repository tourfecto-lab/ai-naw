<?php

/**
 * Tourfecto - Business Control Center Phases 8-9/12/13-14/15-16/17 Tests
 * @version 1.0.0
 *
 * اختبارات offline بالكامل - بتغطي المنطق الخالص للمراحل الجديدة:
 *   - BusinessOnboardingService::progressFromContext() (Phase 17)
 *   - BusinessApiKey::scopeAllows() (Phase 12)
 *   - BusinessAuditService::actionLabels()/labelFor() (Phase 13-14)
 *   - BusinessAccountClosureService::pickSuccessorFromMembers() (Phase 15-16)
 *   - BusinessIntegrationsService::mergeStatuses() (Phase 8-9)
 *
 * نفس نمط اختبارات SsrfGuard: require مباشرة للملفات، وبناء بيانات
 * يدويًا بنفس شكل ناتج BusinessContextService::getContext().
 */

// BusinessApiKey.php بيمتد Model - ولتجنب أي اتصال DB في الاختبار
// offline، بنعرّف نسخة stub فارغة من Model (كل الاختبارات هنا بتستخدم
// المنطق الخالص static بس - scopeAllows - اللي مبيستدعيش أي DB).
if (!class_exists('Model')) {
    class Model
    {
        protected $table = '';
        protected $fillable = [];
        public function getAttribute(string $key)
        {
            return null;
        }
        public function setAttribute(string $key, $value)
        {
        }
        public function save()
        {
            return true;
        }
        public function toArray(): array
        {
            return [];
        }
        public function where(array $conditions = [], array $order = [], int $limit = 0)
        {
            return [];
        }
        public function find(int $id)
        {
            return null;
        }
        public function delete()
        {
            return true;
        }
    }
}

require_once dirname(__DIR__, 3) . '/app/Services/BusinessAccessService.php';
require_once dirname(__DIR__, 3) . '/app/Models/BusinessApiKey.php';
require_once dirname(__DIR__, 3) . '/app/Services/BusinessAuditService.php';
require_once dirname(__DIR__, 3) . '/app/Services/BusinessOnboardingService.php';
require_once dirname(__DIR__, 3) . '/app/Services/BusinessAccountClosureService.php';
require_once dirname(__DIR__, 3) . '/app/Services/BusinessIntegrationsService.php';

class BusinessCenterPhase8912Test
{
    private $passed = 0;
    private $failed = 0;

    public function runAll(): void
    {
        echo "\nBusiness Control Center (Phases 8-9/12/13-14/15-16/17) Tests\n";
        echo "=================================\n\n";

        $this->testApiKeyScopes();
        $this->testAuditLabels();
        $this->testOnboardingNonexistent();
        $this->testOnboardingEmpty();
        $this->testOnboardingComplete();
        $this->testOnboardingStepChecks();
        $this->testOnboardingNextStep();
        $this->testSuccessorSelection();
        $this->testMergeStatuses();

        $this->printSummary();
    }

    // ============ Phase 12: BusinessApiKey::scopeAllows ============
    private function testApiKeyScopes(): void
    {
        $this->startTest('BusinessApiKey::scopeAllows maps read/write correctly (pure)');
        $ok = true;
        $ok = $ok && BusinessApiKey::scopeAllows('read', 'read');
        $ok = $ok && BusinessApiKey::scopeAllows('write', 'read');
        $ok = $ok && BusinessApiKey::scopeAllows('write', 'write');
        $ok = $ok && !BusinessApiKey::scopeAllows('read', 'write');
        $ok = $ok && !BusinessApiKey::scopeAllows('', 'read');
        $ok = $ok && in_array('read', BusinessApiKey::allowedScopes(), true);
        $ok = $ok && in_array('write', BusinessApiKey::allowedScopes(), true);
        $ok
            ? $this->pass('read-only key blocked from write; write allows both')
            : $this->fail('scope matrix mismatch');
    }

    // ============ Phase 13-14: BusinessAuditService labels ============
    private function testAuditLabels(): void
    {
        $this->startTest('BusinessAuditService action labels (pure)');
        $labels = BusinessAuditService::actionLabels();
        $ok = isset($labels[BusinessAuditService::ACTION_BUSINESS_UPDATED])
            && BusinessAuditService::labelFor(BusinessAuditService::ACTION_MEMBER_INVITED) !== ''
            && BusinessAuditService::labelFor('unknown_action') === 'unknown_action';
        $ok
            ? $this->pass('known actions labeled, unknown falls back to raw key')
            : $this->fail('label mapping broken');
    }

    // ============ Phase 17: BusinessOnboardingService ============
    private function testOnboardingNonexistent(): void
    {
        $this->startTest('Onboarding: nonexistent business -> exists=false');
        $svc = new BusinessOnboardingService();
        $r = $svc->progressFromContext(['exists' => false]);
        $ok = $r['exists'] === false && $r['progress_percent'] === 0 && !$r['all_completed'];
        $ok
            ? $this->pass('exists=false, progress=0')
            : $this->fail('Unexpected: ' . json_encode($r));
    }

    private function testOnboardingEmpty(): void
    {
        $this->startTest('Onboarding: empty business -> 0/8 steps, next=business_identity');
        $svc = new BusinessOnboardingService();
        $r = $svc->progressFromContext($this->buildContext([]));
        $ok = $r['exists'] === true
            && $r['completed_steps'] === 0
            && $r['total_steps'] === 8
            && $r['next_step'] === 'business_identity';
        $ok
            ? $this->pass('0/8, next=business_identity')
            : $this->fail('Unexpected: ' . json_encode($r));
    }

    private function testOnboardingComplete(): void
    {
        $this->startTest('Onboarding: fully complete business -> 8/8, all_completed');
        $svc = new BusinessOnboardingService();
        $r = $svc->progressFromContext($this->buildContext([
            'business' => $this->fullBusiness(),
            'locations' => [$this->fullLocation()],
            'services' => [$this->fullService()],
            'target_markets' => ['countries' => ['EG'], 'languages' => ['en'], 'cities' => ['Cairo']],
            'ai_context' => ['business_summary' => 'Premium DMC'],
            'brand_settings' => ['brand_colors' => ['primary' => '#0077BE']],
        ]));
        $ok = $r['all_completed'] === true
            && $r['completed_steps'] === 8
            && $r['progress_percent'] === 100
            && $r['next_step'] === null;
        $ok
            ? $this->pass('8/8, progress=100%, next=null')
            : $this->fail('Unexpected: ' . json_encode($r));
    }

    private function testOnboardingStepChecks(): void
    {
        $this->startTest('Onboarding: individual step checks (pure methods)');
        $svc = new BusinessOnboardingService();
        $ctx = $this->buildContext(['business' => $this->fullBusiness()]);
        $ok = $svc->hasIdentity($ctx)
            && $svc->hasContact($ctx)
            && !$svc->hasTargetMarkets($ctx)
            && $svc->isStepCompleted('business_identity', $ctx)
            && !$svc->isStepCompleted('locations', $ctx);
        $ok
            ? $this->pass('identity+contact complete, markets+locations incomplete')
            : $this->fail('step check mismatch');
    }

    private function testOnboardingNextStep(): void
    {
        $this->startTest('Onboarding: next_step is first incomplete step in order');
        $svc = new BusinessOnboardingService();
        $r = $svc->progressFromContext($this->buildContext([
            'business' => $this->fullBusiness(),
            'locations' => [$this->fullLocation()],
            'services' => [$this->fullService()],
        ]));
        // identity/contact/locations/services + team (مالك = فريق، يُعتبر
        // مكتمل افتراضيًا) => 5 مكتملة -> next هو target_markets
        $ok = $r['next_step'] === 'target_markets' && $r['completed_steps'] === 5;
        $ok
            ? $this->pass('after 5 steps, next=target_markets')
            : $this->fail('Unexpected next=' . var_export($r['next_step'], true) . ' completed=' . $r['completed_steps']);
    }

    // ============ Phase 15-16: BusinessAccountClosureService successor ============
    private function testSuccessorSelection(): void
    {
        $this->startTest('Account closure: successor is highest-ranked active member (pure)');
        $svc = new BusinessAccountClosureService();
        $none = $svc->pickSuccessorFromMembers([]);
        $ok = $none === null;
        $ok = $ok && $svc->pickSuccessorFromMembers([
            ['user_id' => 1, 'role' => 'member'],
            ['user_id' => 2, 'role' => 'admin'],
            ['user_id' => 3, 'role' => 'viewer'],
        ]) === 2;
        $ok = $ok && $svc->pickSuccessorFromMembers([
            ['user_id' => 7, 'role' => 'member'],
            ['user_id' => 9, 'role' => 'member'],
        ]) === 7;
        $ok = $ok && $svc->pickSuccessorFromMembers([
            ['user_id' => 4, 'role' => 'viewer'],
        ]) === 4;
        $ok
            ? $this->pass('admin beats member beats viewer; empty => null')
            : $this->fail('successor selection broken');
    }

    // ============ Phase 8-9: BusinessIntegrationsService::mergeStatuses ============
    private function testMergeStatuses(): void
    {
        $this->startTest('Integrations: mergeStatuses ORs across websites (pure)');
        $svc = new BusinessIntegrationsService();
        $merged = $svc->mergeStatuses([
            [
                'google_business' => ['connected' => false, 'detail' => null],
                'tripadvisor' => ['connected' => true, 'detail' => 'TA Location'],
            ],
            [
                'google_business' => ['connected' => true, 'detail' => 'GBP Location'],
                'tripadvisor' => ['connected' => false, 'detail' => null],
            ],
        ]);
        $ok = $merged['google_business']['connected'] === true
            && $merged['google_business']['detail'] === 'GBP Location'
            && $merged['tripadvisor']['connected'] === true
            && $merged['tripadvisor']['detail'] === 'TA Location'
            && $merged['google_business']['websites_count'] === 2;
        $ok
            ? $this->pass('OR across sites + detail retained + websites_count tracked')
            : $this->fail('mergeStatuses mismatch: ' . json_encode($merged));
    }

    // ============ Helpers ============

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
        return array_merge($base, $overrides);
    }

    private function fullBusiness(): array
    {
        return [
            'legal_name' => 'Nile Wonders Travel',
            'description' => 'Premium travel company in Egypt',
            'website_url' => 'https://example.com',
            'business_email' => 'hello@example.com',
            'business_phone' => '+201234567890',
            'country_code' => 'EG',
            'business_type' => 'dmc',
            'id' => 1,
        ];
    }

    private function fullLocation(): array
    {
        return ['name' => 'Head Office', 'city' => 'Cairo', 'is_primary' => 1];
    }

    private function fullService(): array
    {
        return ['name' => 'Egypt Classic Tours', 'active' => 1];
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
        echo "Business Center Phase 8-9/12/13-14/15-16/17 Test Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  Passed: {$this->passed}\n  Failed: {$this->failed}\n  Total: {$total}\n  Success Rate: {$percentage}%\n";
        echo str_repeat('=', 50) . "\n\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    (new BusinessCenterPhase8912Test())->runAll();
}
