<?php

use PHPUnit\Framework\TestCase;

/** النشر لازم يفشل بوضوح لو ملف الميديا الخاص مش موجود على السيرفر (بدل ما رابطه يتبعت لـMeta ويفشل هناك) */
final class PublishMediaFileCheckTest extends TestCase
{
    private function check(string $path): void
    {
        $m = new ReflectionMethod(PublishSocialPostJob::class, 'assertMediaFileExists');
        $m->setAccessible(true);
        $m->invoke((new ReflectionClass(PublishSocialPostJob::class))->newInstanceWithoutConstructor(), $path);
    }

    public function testMissingPrivateFileFailsWithPublishMediaUnavailable(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Publish media unavailable');
        $this->check('/uploads/media/definitely_missing_' . bin2hex(random_bytes(4)) . '.mp4');
    }

    public function testExistingPrivateFilePasses(): void
    {
        $name = 'phpunit_' . bin2hex(random_bytes(4)) . '.png';
        $file = PrivateMedia::directory() . '/' . $name;
        file_put_contents($file, 'x');
        try {
            $this->check('/uploads/media/' . $name);
            $this->addToAssertionCount(1);
        } finally {
            @unlink($file);
        }
    }

    public function testNonPrivatePathsAreNotChecked(): void
    {
        $this->check('/uploads/social/5/photo.jpg');
        $this->addToAssertionCount(1);
    }
}
