<?php

/**
 * Tourfecto - Business Center Frontend Wiring Test (Phase 23)
 * @version 1.0.0
 *
 * بيشتغل offline بالكامل (لا DB، لا HTTP) وبيتحقق إن الصفحة الجديدة
 * متوصّلة صح في كل نقاط التسجيل:
 *   1) الويب راوت /business-center => BusinessCenterController::index
 *   2) السايد بار في Core/Controller فيه عنصر business_center
 *   3) الكلاسماب في public_html/index.php فيه الكنترولر
 *   4) مفاتيح الترجمة business_center.* موجودة في اللغات الأربع
 *   5) الـJS في الكنترولر بينادي على الـEndpoints الصحيحة
 */
class BusinessCenterWiringTest
{
    private $passed = 0;
    private $failed = 0;
    private $root;

    public function runAll(): void
    {
        echo "\nBusiness Center Frontend Wiring (Phase 23) Tests\n";
        echo "=================================================\n\n";
        $this->root = dirname(__DIR__, 3);

        $this->testRouteRegistered();
        $this->testSidebarEntry();
        $this->testClassmapRegistered();
        $this->testControllerExportsIndex();
        $this->testEndpointsWired();
        $this->testTranslationsComplete();

        $this->printSummary();
    }

    private function testRouteRegistered(): void
    {
        $this->startTest('Web route /business-center => BusinessCenterController::index');
        $web = $this->readFile('app/routes/web.php');
        $ok = preg_match(
            "#get\('/business-center',\s*'BusinessCenterController',\s*'index',\s*\['AuthMiddleware'\]#",
            $web
        ) === 1;
        $ok ? $this->pass('route with AuthMiddleware found') : $this->fail('route not found / wrong signature');
    }

    private function testSidebarEntry(): void
    {
        $this->startTest('Sidebar entry business_center in Core/Controller');
        $core = $this->readFile('app/Core/Controller.php');
        $ok = strpos($core, "'business_center' => [t('sidebar.business_center')") !== false;
        $ok ? $this->pass('business_center link present') : $this->fail('sidebar entry missing');
    }

    private function testClassmapRegistered(): void
    {
        $this->startTest('Classmap registration in public_html/index.php');
        $idx = $this->readFile('public_html/index.php');
        $ok = preg_match("#APP_PATH \. '/Controllers/BusinessCenterController\.php'#", $idx) === 1;
        $ok ? $this->pass('controller registered in optionalNewClassFiles') : $this->fail('classmap entry missing');
    }

    private function testControllerExportsIndex(): void
    {
        $this->startTest('BusinessCenterController has index() rendering the panel page');
        $ctrl = $this->readFile('app/Controllers/BusinessCenterController.php');
        $ok = preg_match("#public function index\(array#", $ctrl) === 1
            && strpos($ctrl, 'renderPanelPage(') !== false
            && strpos($ctrl, 'business_center') !== false;
        $ok ? $this->pass('index() + renderPanelPage + activeTab key present') : $this->fail('controller structure wrong');
    }

    private function testEndpointsWired(): void
    {
        $this->startTest('Frontend JS wires the correct business API endpoints');
        $ctrl = $this->readFile('app/Controllers/BusinessCenterController.php');
        $required = [
            "'/api/business/overview'",
            "'/api/business/' + bcBusinessId + '/team'",
            "'/api/business/' + bcBusinessId + '/team/invite'",
            "'/api/business/' + bcBusinessId + '/api-keys'",
            "audit-log",
        ];
        $ok = true;
        foreach ($required as $needle) {
            if (strpos($ctrl, $needle) === false) {
                $ok = false;
                $this->fail("missing wire: {$needle}");
            }
        }
        $ok ? $this->pass('all five API endpoints wired in JS') : null;
    }

    private function testTranslationsComplete(): void
    {
        $this->startTest('business_center.* keys present in all four languages');
        $required = [
            'business_center.page.title',
            'business_center.tab.overview',
            'business_center.tab.team',
            'business_center.tab.keys',
            'business_center.tab.audit',
            'business_center.readiness.title',
            'business_center.keys.title',
            'business_center.audit.title',
            'business_center.team.title',
            'business_center.empty.create_btn',
        ];
        $ok = true;
        foreach (['en', 'ar', 'fr', 'de'] as $lang) {
            $content = $this->readFile("app/Lang/{$lang}.php");
            foreach ($required as $key) {
                if (strpos($content, "'{$key}'") === false) {
                    $ok = false;
                    $this->fail("{$lang}.php missing: {$key}");
                }
            }
        }
        $ok ? $this->pass('10 core keys x 4 languages all present') : null;
    }

    private function readFile(string $rel): string
    {
        $path = $this->root . '/' . $rel;
        return is_file($path) ? (string) file_get_contents($path) : '';
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
        echo "Business Center Wiring Test Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  Passed: {$this->passed}\n  Failed: {$this->failed}\n  Total: {$total}\n  Success Rate: {$percentage}%\n";
        echo str_repeat('=', 50) . "\n\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    (new BusinessCenterWiringTest())->runAll();
}
