<?php

use PHPUnit\Framework\TestCase;

/**
 * app/Config/project_classmap.php هو المرجع الفعلي لتحميل الكلاسات على السيرفر (السيرفر مفيهوش composer
 * dump-autoload — انظر تعليقات public_html/index.php). أي هدف فيه مش موجود = كلاس بيرمي "Failed opening required"
 * وقت التشغيل. (حصل فعلًا: الـ classmap بيشير لـ "HomeController (1).php" وكان اتحذف باعتباره مكرّر.)
 */
final class ClassmapTargetsExistTest extends TestCase
{
    public function testEveryClassmapTargetFileExists(): void
    {
        $root = dirname(__DIR__, 2);
        $map = require $root . '/app/Config/project_classmap.php';

        $this->assertIsArray($map);
        $this->assertGreaterThan(500, count($map), 'الـ classmap المفروض فيه مئات الكلاسات');

        $missing = [];
        foreach ($map as $class => $path) {
            if (!is_file($path)) {
                $missing[] = $class . ' => ' . str_replace(str_replace('\\', '/', $root) . '/', '', str_replace('\\', '/', $path));
            }
        }
        $this->assertSame([], $missing, "كلاسات في الـ classmap ملفاتها مش موجودة:\n" . implode("\n", $missing));
    }

    /** index.php بيحمّل قائمة ملفات بـ file_exists (ميزة ناقصة بس، مش موقع واقع) — لكن الملفات الإلزامية لازم تكون موجودة. */
    public function testEntryPointsExist(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['public_html/index.php', 'app/Config/runtime_requirements.php', 'app/Config/project_classmap.php', 'app/Core/bootstrap.php'] as $f) {
            $this->assertFileExists($root . '/' . $f);
        }
    }
}
