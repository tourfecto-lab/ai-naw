<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/SeoExpert/SeoExpertService.php';

/**
 * Opening the hub, a run's details or the readiness/report screens must only read saved data:
 * no code on these paths may reference a paid provider client (DataForSEO, AI generation).
 */
final class SeoReadPathsMakeNoPaidCallsTest extends TestCase
{
    private const PAID = ['DataForSeoClient', 'DataForSeoResearchService', 'ContentBriefService', 'KeywordResearchService', 'RankTrackingService', 'ServiceUsageReservation::reserve', 'AiClient', 'AIManager', 'chatCompletion', 'generateContent', 'completeJson'];

    private function methodSource(string $class, string $method): string
    {
        $m = new ReflectionMethod($class, $method);
        $lines = file($m->getFileName());
        return implode('', array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
    }

    public function testHubReadMethodsDoNotReferencePaidProviders(): void
    {
        foreach (['overview', 'detail', 'state', 'quote', 'setup'] as $method) {
            if (!method_exists('SeoExpertService', $method)) continue;
            $src = $this->methodSource('SeoExpertService', $method);
            foreach (self::PAID as $needle) {
                if ($method === 'quote' && $needle === 'ServiceUsageReservation::reserve') continue;
                $this->assertStringNotContainsString($needle, $src, "SeoExpertService::$method must not reach $needle");
            }
        }
    }

    public function testReadinessAndReportFilesDoNotReferencePaidProviders(): void
    {
        foreach (['SeoExpert/SeoServiceReadiness.php', 'Seo/SeoMonthlyReportService.php', 'Seo/SeoChartService.php', 'SeoExpert/SeoCycleReport.php'] as $file) {
            $path = __DIR__ . '/../../app/Services/' . $file;
            if (!is_file($path)) continue;
            $src = file_get_contents($path);
            foreach (['DataForSeoClient', 'DataForSeoResearchService', 'chatCompletion', 'generateContent', 'completeJson'] as $needle) {
                $this->assertStringNotContainsString($needle, $src, "$file must read saved data only ($needle)");
            }
        }
    }

    public function testControllerReadActionsDoNotStartWork(): void
    {
        $src = file_get_contents(__DIR__ . '/../../app/Controllers/SeoExpertController.php');
        $this->assertMatchesRegularExpression("/\\\$write=!in_array\\(\\\$action,\\['setup','overview','detail','readiness'\\],true\\)/", $src, 'read actions are exactly setup/overview/detail/readiness and everything else requires CSRF');
    }
}
