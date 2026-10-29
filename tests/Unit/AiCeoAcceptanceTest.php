<?php

/**
 * TOURFECTO AI CEO (المرحلة 10): اختبارات قبول ثابتة على الكود.
 * - كل مسارات AI CEO وراها تسجيل دخول + أدمن، وكل دالة ليها مسار موجودة.
 * - كل دالة في الكنترولر بتتأكد من الصلاحية، وكل POST بيعدّي على guard (CSRF + حد الطلبات).
 * - خدمات AI CEO مش بتبعت إيميل ولا واتساب، وطلبات الشبكة محصورة: دومين المنصة، GitHub API، أو SafeHttp.
 * - مراحل التفعيل التدريجي.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AiCeo/AiCeoActivation.php';

final class AiCeoAcceptanceTest extends TestCase
{
    private function routes(): array
    {
        $out = [];
        foreach (['web', 'api'] as $f) {
            $src = (string) file_get_contents(__DIR__ . '/../../app/routes/' . $f . '.php');
            preg_match_all("#\\\$router->(get|post)\\('([^']*ai-ceo[^']*)'\\s*,\\s*'AiCeoController'\\s*,\\s*'(\\w+)'\\s*,\\s*\\[([^\\]]*)\\]#", $src, $m, PREG_SET_ORDER);
            foreach ($m as $r) $out[] = ['verb' => $r[1], 'path' => $r[2], 'method' => $r[3], 'mw' => $r[4]];
        }
        return $out;
    }

    private function methodBody(string $src, string $name): string
    {
        $start = strpos($src, 'public function ' . $name . '(');
        $this->assertSame(true, $start !== false, 'missing method ' . $name);
        $next = strpos($src, "\n    public function ", $start + 10);
        $priv = strpos($src, "\n    private function ", $start + 10);
        $end = min(array_filter([$next, $priv, strlen($src)], static fn ($x) => $x !== false));
        return substr($src, $start, $end - $start);
    }

    public function testEveryRouteIsAdminOnlyAndHandled(): void
    {
        $routes = $this->routes();
        $this->assertSame(true, count($routes) >= 30, 'expected all AI CEO routes, got ' . count($routes));
        $src = (string) file_get_contents(__DIR__ . '/../../app/Controllers/AiCeoController.php');
        foreach ($routes as $r) {
            $this->assertStringContainsString('AuthMiddleware', $r['mw'], $r['path']);
            $this->assertStringContainsString('AdminMiddleware', $r['mw'], $r['path']);
            $body = $this->methodBody($src, $r['method']);
            if ($r['verb'] === 'post') $this->assertStringContainsString('$this->guard(', $body, 'POST ' . $r['path'] . ' must use guard (CSRF)');
            else $this->assertSame(true, str_contains($body, '$this->allowed()') || str_contains($body, '$this->guard('), 'GET ' . $r['path'] . ' must check permission');
        }
    }

    public function testOwnerOnlyActions(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../app/Controllers/AiCeoController.php');
        foreach (['brandPlan', 'successSettings', 'executiveAction'] as $m) $this->assertStringContainsString('$this->guard(true)', $this->methodBody($src, $m), $m);
        // التكلفة بالذكاء الاصطناعي بتعدي على ميزانية AI CEO
        foreach (['brandPlan' => 'new AiCostIntelligence', 'financeAction' => 'new AiCostIntelligence'] as $m => $needle) $this->assertStringContainsString($needle, $this->methodBody($src, $m), $m);
    }

    public function testServicesNeverSendMessagesAndFetchSafely(): void
    {
        foreach (glob(__DIR__ . '/../../app/Services/AiCeo/*.php') as $f) {
            $src = (string) file_get_contents($f);
            $this->assertSame(0, preg_match('/(?<![A-Za-z_])mail\(|PHPMailer|sendMessage\(|sendTemplate\(|WhatsAppService|EmailService/', $src), basename($f) . ' must not send messages');
            if (str_contains($src, 'curl_init(')) $this->assertSame(true, in_array(basename($f), ['BrandSiteAudit.php', 'OpsAdvisor.php'], true), basename($f) . ' must fetch through SafeHttp');
        }
        $ex = (string) file_get_contents(__DIR__ . '/../../app/Services/AiCeo/ExecutiveIntel.php');
        $this->assertStringContainsString('SafeHttp::exec(', $ex);
    }

    public function testActivationStages(): void
    {
        $c = static fn ($stage, $ok) => ['key' => 'k' . $stage . (int) $ok . mt_rand(), 'stage' => $stage, 'label' => 'x', 'ok' => $ok, 'how' => '', 'tab' => 'overview'];
        $this->assertSame(0, AiCeoActivation::stage([$c(0, false), $c(1, true)], 'approval')['stage']);
        $this->assertSame(0, AiCeoActivation::stage([$c(0, true), $c(1, false)], 'auto')['stage'], 'auto mode does not skip missing data');
        $this->assertSame(1, AiCeoActivation::stage([$c(0, true), $c(1, true), $c(3, false)], 'approval')['stage']);
        $this->assertSame(2, AiCeoActivation::stage([$c(0, true), $c(1, true), $c(3, false)], 'auto')['stage']);
        $this->assertSame(3, AiCeoActivation::stage([$c(0, true), $c(1, true), $c(3, true)], 'auto')['stage']);
        $this->assertSame(1, AiCeoActivation::stage([$c(0, true), $c(1, true), $c(3, true)], 'approval')['stage'], 'growth needs auto mode reviewed first');
        $s = AiCeoActivation::stage([$c(3, false), $c(0, false)], 'approval');
        $this->assertSame(0, $s['next'][0]['stage'], 'earliest missing items first');
    }
}
