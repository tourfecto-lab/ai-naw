<?php

use PHPUnit\Framework\TestCase;

/**
 * حراسة لأعطال "استدعاء دالة مش موجودة" اللي PHPStan لقاها وكانت مخفية لأن الاستثناء بيتبلع أو بيطلع كخطأ عام:
 *  - AiVisibilityController::llms كان بينادي SeoMarketService::owned() (مش موجودة) فالـ endpoint كان بيرجّع 422 دايمًا.
 *  - TrialFollowUpService بينادي TrialPlanService::adminProgress() جوه try/catch(Throwable) فالتقدّم مبيظهرش للأدمن
 *    (معروف ومسجّل في docs/ops/PENDING_FOR_OWNER_AR.md، مش مغطّى هنا لأن دلالته محتاجة قرار).
 */
final class UndefinedMethodGuardsTest extends TestCase
{
    /** كل استدعاء (new SeoMarketService(...))->method( في app لازم يشير لدالة موجودة. */
    public function testSeoMarketServiceCallsResolve(): void
    {
        $root = dirname(__DIR__, 2) . '/app';
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        $missing = [];
        $found = 0;
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            if (strpos($src, 'SeoMarketService') === false) {
                continue;
            }
            if (preg_match_all('/\(new SeoMarketService\([^)]*\)\)->([A-Za-z0-9_]+)\(/', $src, $m)) {
                foreach ($m[1] as $method) {
                    $found++;
                    if (!method_exists(SeoMarketService::class, $method)) {
                        $missing[] = str_replace($root, 'app', $file->getPathname()) . ': SeoMarketService::' . $method . '()';
                    }
                }
            }
        }
        $this->assertGreaterThan(3, $found, 'المفروض نلاقي استدعاءات SeoMarketService في الكود');
        $this->assertSame([], $missing, "استدعاءات لدوال مش موجودة:\n" . implode("\n", $missing));
    }
}
