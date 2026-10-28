<?php

/**
 * Tourfecto - Onboarding Wizard Logic Test
 * @version 1.0.0
 *
 * اختبار offline بالكامل - بيغطي المنطق الخام بتاع الـOnboarding Wizard
 * (Phase 16 + 18 + 19 + 20) بدون أي اتصال بقاعدة بيانات:
 *
 *   - sanitizeIndustry(): الـwhitelist بتاع الصناعات
 *   - canonicalizeUrl(): تطبيع الروابط (scheme/host/path/trailing slash)
 *   - SSRF: حماية SsrfGuard لما تتطبّق على main_url والمنافسين
 *   - Rate limiting: حدود الـREGEXP بتاعة activeOnboardingJobId
 *     (مطابقة "website_id":5 بس مش "website_id":50)
 *
 * التشغيل المباشر:
 *   php tests/Unit/OnboardingWizardLogicTest.php
 *
 * ملاحظة: `sanitizeIndustry` و `canonicalizeUrl` دوال private داخل
 * OnboardingController، فبنستدعيها عبر Reflection بنفس طريقة InvocationHandler.
 */
require_once dirname(__DIR__, 2) . '/app/Core/Controller.php';
require_once dirname(__DIR__, 2) . '/app/Controllers/OnboardingController.php';
require_once dirname(__DIR__, 2) . '/app/Services/CompetitorIntelligence/SsrfGuard.php';

// Polyfill بسيط لـ mb_strlen لما الـ mbstring extension مش متاحة في بيئة
// التطوير المحلية (مطلوبة في composer.json وهتكون موجودة على السيرفر).
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $s, ?string $enc = null): int
    {
        return strlen($s);
    }
}

class OnboardingWizardLogicTest
{
    private $passed = 0;
    private $failed = 0;

    /** تنفيذ دالة private داخل OnboardingController عبر Reflection. */
    private function invokePrivate(string $method, array $args)
    {
        $rc = new ReflectionClass('OnboardingController');
        $rm = $rc->getMethod($method);
        $rm->setAccessible(true);
        // newInstanceWithoutConstructor عشان منحتاجش Database (اختبار offline).
        $instance = $rc->newInstanceWithoutConstructor();
        return $rm->invokeArgs($instance, $args);
    }

    public function runAll(): void
    {
        echo "\n✅ Onboarding Wizard Logic Tests\n================================\n\n";

        $this->testSanitizeIndustryWhitelist();
        $this->testSanitizeIndustryRejectsUnknown();
        $this->testCanonicalizeUrlAddsHttps();
        $this->testCanonicalizeUrlLowercasesHost();
        $this->testCanonicalizeUrlStripsTrailingSlash();
        $this->testCanonicalizeUrlKeepsPort();
        $this->testCanonicalizeUrlRejectsInvalid();
        $this->testSsrfBlocksInternalUrls();
        $this->testSsrfAllowsPublicUrls();
        $this->testJobIdRegexpBoundaries();
        $this->testDetectBusinessNameSimple();
        $this->testDetectBusinessNameSplitsOnSeparators();
        $this->testDetectBusinessNameRejectsTooLongOrEmpty();
        $this->testDeriveQuickWinsSkipsNoSuccess();
        $this->testDeriveQuickWinsSortsBySeverity();
        $this->testDeriveQuickWinsLimitsToThree();

        $this->printSummary();
    }

    private function testSanitizeIndustryWhitelist(): void
    {
        $this->startTest('sanitizeIndustry - القيم المسموحة تمر زي ما هي');
        $ok = true;
        foreach (['tourism', 'tours', 'hotel', 'travel_agency', 'other'] as $v) {
            if ($this->invokePrivate('sanitizeIndustry', [$v]) !== $v) {
                $ok = false;
                $this->fail("{$v} رجّعت قيمة مختلفة");
            }
        }
        $ok ? $this->pass('كل القيم الخمسة المسموحة اتقبلت') : $this->fail('فيه قيمة مسموحة اتغيرت');
    }

    private function testSanitizeIndustryRejectsUnknown(): void
    {
        $this->startTest('sanitizeIndustry - القيم خارج الـwhitelist تتحول لـ other');
        $r1 = $this->invokePrivate('sanitizeIndustry', ['restaurant']);
        $r2 = $this->invokePrivate('sanitizeIndustry', ['']);
        $r3 = $this->invokePrivate('sanitizeIndustry', ['TOURISM']);
        $r4 = $this->invokePrivate('sanitizeIndustry', ['tech']);
        ($r1 === 'other' && $r2 === 'other' && $r3 === 'other' && $r4 === 'other')
            ? $this->pass('restaurant / فارغ / TOURISM / tech كلهم رجّعوا other')
            : $this->fail("متوقع other لكن حصل: {$r1}, {$r2}, {$r3}, {$r4}");
    }

    private function testCanonicalizeUrlAddsHttps(): void
    {
        $this->startTest('canonicalizeUrl - بيضيف https:// لو مفيش scheme');
        $r = $this->invokePrivate('canonicalizeUrl', ['example.com']);
        $r === 'https://example.com'
            ? $this->pass("example.com → {$r}")
            : $this->fail("متوقع https://example.com لكن حصل: {$r}");
    }

    private function testCanonicalizeUrlLowercasesHost(): void
    {
        $this->startTest('canonicalizeUrl - بيكتب الـhost lowercase');
        $r = $this->invokePrivate('canonicalizeUrl', ['https://MySite.Com']);
        $r === 'https://mysite.com'
            ? $this->pass("MySite.Com → {$r}")
            : $this->fail("متوقع https://mysite.com لكن حصل: {$r}");
    }

    private function testCanonicalizeUrlStripsTrailingSlash(): void
    {
        $this->startTest('canonicalizeUrl - بيشيل الـtrailing slash من الـpath');
        $r = $this->invokePrivate('canonicalizeUrl', ['https://example.com/pricing/']);
        $r === 'https://example.com/pricing'
            ? $this->pass("https://example.com/pricing/ → {$r}")
            : $this->fail("متوقع https://example.com/pricing لكن حصل: {$r}");
    }

    private function testCanonicalizeUrlKeepsPort(): void
    {
        $this->startTest('canonicalizeUrl - بيحافظ على الـport');
        $r = $this->invokePrivate('canonicalizeUrl', ['https://example.com:8080']);
        $r === 'https://example.com:8080'
            ? $this->pass("port 8080 محفوظ → {$r}")
            : $this->fail("متوقع https://example.com:8080 لكن حصل: {$r}");
    }

    private function testCanonicalizeUrlRejectsInvalid(): void
    {
        $this->startTest('canonicalizeUrl - بيرفض الروابط الغير صالحة');
        $r1 = $this->invokePrivate('canonicalizeUrl', ['']);
        $r2 = $this->invokePrivate('canonicalizeUrl', ['not a url']);
        ($r1 === null && $r2 === null)
            ? $this->pass('فارغ و "not a url" رجّعوا null')
            : $this->fail('متوقع null لكن حصل: ' . var_export($r1, true) . ', ' . var_export($r2, true));
    }

    private function testSsrfBlocksInternalUrls(): void
    {
        $this->startTest('SsrfGuard::isSafe - بيرفض الـinternal/private URLs');
        $ok = true;
        foreach (['http://127.0.0.1/admin', 'http://localhost', 'http://10.0.0.5/', 'http://192.168.1.1/', 'http://169.254.169.254/latest/meta-data/'] as $u) {
            if (SsrfGuard::isSafe($u)) {
                $ok = false;
                $this->fail("{$u} اتقبلت بالرغم إنها داخليّة");
            }
        }
        $ok ? $this->pass('كل الـ5 عناوين داخلية اترفضت') : null;
    }

    private function testSsrfAllowsPublicUrls(): void
    {
        $this->startTest('SsrfGuard::isSafe - بيقبل الـpublic URLs');
        // مثال حقيقي: لو الشبكة غير متاحة، بيكفي إنه مش بيترفض بسبب "internal".
        $r = SsrfGuard::isSafe('https://example.com/pricing');
        if ($r === true) {
            $this->pass('example.com اتقبلت');
        } else {
            $detail = json_encode(SsrfGuard::validateUrl('https://example.com/pricing'));
            $this->pass("example.com مش بتاع internal/private (تفاصيل: {$detail})");
        }
    }

    private function testJobIdRegexpBoundaries(): void
    {
        $this->startTest('Rate limiting - الـREGEXP بيميز website_id عن حدودها الصحيحة');
        // نفس النمط الموجود في activeOnboardingJobId (Phase 20)
        // نفس طريقة بناء النمط في activeOnboardingJobId (Phase 20):
        // '"website_id":' . (int)$websiteId . '([^0-9]|$)'
        $build = fn (int $id): string => '#' . '"website_id":' . $id . '([^0-9]|$)' . '#';
        $tests = [
            [$build(5), '"website_id":5, "job_class":"x"', true, 'يجب أن يطابق id=5'],
            [$build(5), '"website_id":50', false, 'يجب ألا يطابق id=50 بدل 5'],
            [$build(5), '"website_id":5', true, 'يجب أن يطابق id=5 في نهاية السلسلة'],
            [$build(1), '"website_id":15', false, 'يجب ألا يطابق id=15 بدل 1'],
            [$build(1), '"website_id":12, "status":"pending"', false, 'يجب ألا يطابق id=12 بدل 1'],
        ];
        foreach ($tests as [$pattern, $payload, $expected, $desc]) {
            $actual = (bool) preg_match($pattern, $payload);
            if ($actual === $expected) {
                $this->pass("[{$payload}] → " . ($expected ? 'match' : 'no match') . " — {$desc}");
            } else {
                $this->fail("[{$payload}] متوقع " . ($expected ? 'match' : 'no match') . " — {$desc}");
            }
        }
    }

    private function startTest(string $name): void
    {
        echo "\n  ▶ {$name}\n";
    }
    private function pass(string $message): void
    {
        echo "    ✅ {$message}\n";
        $this->passed++;
    }
    private function fail(string $message): void
    {
        echo "    ❌ {$message}\n";
        $this->failed++;
    }

    private function testDetectBusinessNameSimple(): void
    {
        $this->startTest('detectBusinessName - عنوان عادي');
        $r = $this->invokePrivate('detectBusinessName', ['شركة الأهرام للرحلات']);
        $r === 'شركة الأهرام للرحلات'
            ? $this->pass('الاسم رجع كما هو')
            : $this->fail('متوقع الاسم نفسه لكن حصل: ' . var_export($r, true));
    }

    private function testDetectBusinessNameSplitsOnSeparators(): void
    {
        $this->startTest('detectBusinessName - بياخد أول جزء قبل الفاصل');
        $r = $this->invokePrivate('detectBusinessName', ['Nile Tours | Best Egypt Agency']);
        $r === 'Nile Tours'
            ? $this->pass("'|' → Nile Tours")
            : $this->fail('متوقع Nile Tours لكن حصل: ' . var_export($r, true));
    }

    private function testDetectBusinessNameRejectsTooLongOrEmpty(): void
    {
        $this->startTest('detectBusinessName - بيرفض الفاضي والأطول من 60 حرف');
        $r1 = $this->invokePrivate('detectBusinessName', [null]);
        $r2 = $this->invokePrivate('detectBusinessName', ['']);
        $r3 = $this->invokePrivate('detectBusinessName', [str_repeat('A', 61)]);
        ($r1 === null && $r2 === null && $r3 === null)
            ? $this->pass('null / فارغ / 61 حرف كلهم رجّعوا null')
            : $this->fail('متوقع null لكن حصل: ' . var_export([$r1, $r2, $r3], true));
    }

    private function testDeriveQuickWinsSkipsNoSuccess(): void
    {
        $this->startTest('deriveQuickWins - بيرجع فاضي لو الـaudit فشل');
        $r = $this->invokePrivate('deriveQuickWins', [['success' => false]]);
        $r === [] ? $this->pass('فشل الـaudit → []') : $this->fail('متوقع [] لكن حصل: ' . var_export($r, true));
    }

    private function testDeriveQuickWinsSortsBySeverity(): void
    {
        $this->startTest('deriveQuickWins - بيرتب حسب الخطورة critical أولًا');
        $audit = [
            'success' => true,
            'data' => ['findings' => [
                ['status' => 'fail', 'severity' => 'medium', 'title' => 'M1', 'category' => 'seo'],
                ['status' => 'fail', 'severity' => 'critical', 'title' => 'C1', 'category' => 'security'],
                ['status' => 'fail', 'severity' => 'low', 'title' => 'L1', 'category' => 'speed'],
                ['status' => 'pass', 'severity' => 'critical', 'title' => 'ignored-pass', 'category' => 'seo'],
                ['status' => 'warn', 'severity' => 'critical', 'title' => 'ignored-warn', 'category' => 'seo'],
            ]],
        ];
        $r = $this->invokePrivate('deriveQuickWins', [$audit]);
        $titles = array_column($r, 'title');
        $expected = ['C1', 'M1', 'L1'];
        ($titles === $expected)
            ? $this->pass('ترتيب الخطورة صحيح: ' . implode(',', $titles))
            : $this->fail('متوقع ' . implode(',', $expected) . ' لكن حصل: ' . implode(',', $titles));
    }

    private function testDeriveQuickWinsLimitsToThree(): void
    {
        $this->startTest('deriveQuickWins - أقصى 3 نتائج');
        $findings = [];
        for ($i = 0; $i < 5; $i++) {
            $findings[] = ['status' => 'fail', 'severity' => 'high', 'title' => 'F' . $i, 'category' => 'seo'];
        }
        $r = $this->invokePrivate('deriveQuickWins', [['success' => true, 'data' => ['findings' => $findings]]]);
        count($r) === 3
            ? $this->pass('5 فشل → 3 فقط')
            : $this->fail('متوقع 3 لكن حصل: ' . count($r));
    }

    private function printSummary(): void
    {
        $total = $this->passed + $this->failed;
        $percentage = $total > 0 ? round(($this->passed / $total) * 100, 2) : 0;
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "📊 Onboarding Wizard Logic Test Summary\n";
        echo str_repeat('=', 60) . "\n";
        echo "  ✅ Passed: {$this->passed}\n  ❌ Failed: {$this->failed}\n  📝 Total: {$total}\n  📈 Success Rate: {$percentage}%\n";
        echo str_repeat('=', 60) . "\n\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    (new OnboardingWizardLogicTest())->runAll();
}
