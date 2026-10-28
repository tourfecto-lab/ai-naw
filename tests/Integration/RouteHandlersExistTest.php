<?php

use PHPUnit\Framework\TestCase;

/**
 * كل مسار في app/routes/web.php و api.php لازم يشير لكلاس كنترولر موجود ودالة public فيه.
 * (مسار بيشير لدالة ناقصة بيفشل وقت الطلب فقط بخطأ فادح، ومحدش بيلاحظ قبل ما عميل يضغط عليه.)
 * app/routes/webhooks.php مستثنى: مش بيتحمّل في أي مكان (دوال handleStripe... مش موجودة أصلًا).
 */
final class RouteHandlersExistTest extends TestCase
{
    /** @return array<string,array{string,string,string}> */
    public static function handlers(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];
        foreach (['app/routes/web.php', 'app/routes/api.php'] as $file) {
            $src = (string) file_get_contents($root . '/' . $file);
            preg_match_all(
                '/\$router->(?:get|post|put|patch|delete|any)\(\s*\'([^\']+)\'\s*,\s*\'([A-Za-z0-9_]+)\'\s*,\s*\'([A-Za-z0-9_]+)\'/',
                $src,
                $m,
                PREG_SET_ORDER
            );
            foreach ($m as [$all, $path, $controller, $method]) {
                $out["$controller::$method"] = [$controller, $method, $path];
            }
        }
        return $out;
    }

    public function testRouteFilesAreParsed(): void
    {
        $this->assertGreaterThan(1000, count(self::handlers()), 'مفروض نلاقي أكتر من 1000 معالج مسار');
    }

    public function testEveryRouteHandlerExists(): void
    {
        $missing = [];
        foreach (self::handlers() as $key => [$controller, $method, $path]) {
            if (!class_exists($controller)) {
                $missing[] = "$key: الكلاس مش موجود ($path)";
            } elseif (!method_exists($controller, $method)) {
                $missing[] = "$key: الدالة مش موجودة ($path)";
            } elseif (!(new ReflectionMethod($controller, $method))->isPublic()) {
                $missing[] = "$key: الدالة مش public ($path)";
            }
        }
        $this->assertSame([], $missing, "مسارات بتشير لمعالج ناقص:\n" . implode("\n", array_slice($missing, 0, 40)));
    }
}
