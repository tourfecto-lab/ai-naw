<?php
/**
 * Tourfecto - Competitor Intelligence: SsrfGuard Test
 * @version 1.0.0
 *
 * اختبار offline بالكامل - SsrfGuard منطق بحت، مفيش أي اتصال بقاعدة
 * بيانات، فممكن تشغيله مباشرة:
 *   php tests/Unit/CompetitorIntelligence/SsrfGuardTest.php
 */
require_once dirname(__DIR__, 3) . '/app/Services/CompetitorIntelligence/SsrfGuard.php';

class SsrfGuardTest {
    private $passed = 0;
    private $failed = 0;

    public function runAll(): void {
        echo "\n✅ SsrfGuard Tests\n==================\n\n";

        $this->testBlocksLoopback();
        $this->testBlocksPrivateRanges();
        $this->testBlocksMetadataEndpoint();
        $this->testBlocksNonHttpScheme();
        $this->testBlocksUnusualPort();
        $this->testAllowsStandardPublicUrl();
        $this->testBuildSubPageUrlKeepsHost();

        $this->printSummary();
    }

    private function testBlocksLoopback(): void {
        $this->startTest('Blocks loopback / localhost');
        $r1 = SsrfGuard::validateUrl('http://127.0.0.1/admin');
        $r2 = SsrfGuard::validateUrl('http://localhost:8080/');
        $r1['safe'] === false ? $this->pass('127.0.0.1 blocked') : $this->fail('127.0.0.1 NOT blocked');
        $r2['safe'] === false ? $this->pass('localhost blocked') : $this->fail('localhost NOT blocked');
    }

    private function testBlocksPrivateRanges(): void {
        $this->startTest('Blocks RFC1918 private ranges');
        foreach (['http://10.0.0.5/', 'http://192.168.1.1/', 'http://172.16.0.1/'] as $url) {
            $r = SsrfGuard::validateUrl($url);
            $r['safe'] === false ? $this->pass("{$url} blocked") : $this->fail("{$url} NOT blocked");
        }
    }

    private function testBlocksMetadataEndpoint(): void {
        $this->startTest('Blocks cloud metadata endpoint');
        $r = SsrfGuard::validateUrl('http://169.254.169.254/latest/meta-data/');
        $r['safe'] === false ? $this->pass('169.254.169.254 blocked') : $this->fail('169.254.169.254 NOT blocked');
    }

    private function testBlocksNonHttpScheme(): void {
        $this->startTest('Blocks non-http(s) schemes');
        $r1 = SsrfGuard::validateUrl('file:///etc/passwd');
        $r2 = SsrfGuard::validateUrl('gopher://example.com/');
        $r1['safe'] === false ? $this->pass('file:// blocked') : $this->fail('file:// NOT blocked');
        $r2['safe'] === false ? $this->pass('gopher:// blocked') : $this->fail('gopher:// NOT blocked');
    }

    private function testBlocksUnusualPort(): void {
        $this->startTest('Blocks non-standard ports');
        $r = SsrfGuard::validateUrl('http://example.com:3306/');
        $r['safe'] === false && $r['reason'] === 'blocked_port'
            ? $this->pass('port 3306 blocked')
            : $this->fail('port 3306 NOT blocked (reason=' . ($r['reason'] ?? 'null') . ')');
    }

    private function testAllowsStandardPublicUrl(): void {
        $this->startTest('Allows a standard public https URL');
        // example.com يُحل دائمًا لـ IP عام ثابت من IANA - آمن للاختبار offline بدون شبكة فعلية هنا،
        // لكن في بيئة CI حقيقية بشبكة متاحة هيتحل فعليًا؛ لو الشبكة غير متاحة، النتيجة private_or_unresolvable_host متوقعة ومقبولة.
        $r = SsrfGuard::validateUrl('https://example.com/pricing');
        if ($r['safe'] === true || $r['reason'] === 'private_or_unresolvable_host') {
            $this->pass('example.com handled correctly (safe=' . var_export($r['safe'], true) . ')');
        } else {
            $this->fail('Unexpected result for example.com: ' . json_encode($r));
        }
    }

    private function testBuildSubPageUrlKeepsHost(): void {
        $this->startTest('buildSubPageUrl preserves base host');
        $url = SsrfGuard::buildSubPageUrl('https://competitor.com/', 'pricing');
        $url === 'https://competitor.com/pricing'
            ? $this->pass('Sub-page URL built correctly')
            : $this->fail('Unexpected sub-page URL: ' . $url);
    }

    private function startTest(string $name): void { echo "\n  ▶ {$name}\n"; }
    private function pass(string $message): void { echo "    ✅ {$message}\n"; $this->passed++; }
    private function fail(string $message): void { echo "    ❌ {$message}\n"; $this->failed++; }

    private function printSummary(): void {
        $total = $this->passed + $this->failed;
        $percentage = $total > 0 ? round(($this->passed / $total) * 100, 2) : 0;
        echo "\n" . str_repeat('=', 50) . "\n";
        echo "📊 SsrfGuard Test Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  ✅ Passed: {$this->passed}\n  ❌ Failed: {$this->failed}\n  📝 Total: {$total}\n  📈 Success Rate: {$percentage}%\n";
        echo str_repeat('=', 50) . "\n\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    (new SsrfGuardTest())->runAll();
}
