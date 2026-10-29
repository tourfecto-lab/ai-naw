<?php

use PHPUnit\Framework\TestCase;

/** Agent-set contact photos: validated, cropped to a small square JPEG, one private file per conversation. */
final class ChatContactPhotosTest extends TestCase
{
    private const WID = 987801;
    private const CID = 987802;

    protected function setUp(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not available');
        }
        ChatContactPhotos::remove(self::WID, self::CID);
    }

    protected function tearDown(): void
    {
        ChatContactPhotos::remove(self::WID, self::CID);
        @rmdir(dirname(ChatContactPhotos::path(self::WID, self::CID)));
    }

    private function png(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
        ob_start();
        imagepng($im);
        return (string) ob_get_clean();
    }

    public function testSavesACroppedSquareJpeg(): void
    {
        $this->assertSame(0, ChatContactPhotos::version(self::WID, self::CID));
        $this->assertNull(ChatContactPhotos::save(self::WID, self::CID, $this->png(400, 250)));

        $bytes = ChatContactPhotos::read(self::WID, self::CID);
        $this->assertNotNull($bytes);
        $info = getimagesizefromstring($bytes);
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertSame([160, 160], [$info[0], $info[1]]);
        $this->assertGreaterThan(0, ChatContactPhotos::version(self::WID, self::CID));
    }

    public function testRemoveAndReplace(): void
    {
        ChatContactPhotos::save(self::WID, self::CID, $this->png(100, 100));
        ChatContactPhotos::save(self::WID, self::CID, $this->png(120, 300));
        $this->assertNotNull(ChatContactPhotos::read(self::WID, self::CID));

        ChatContactPhotos::remove(self::WID, self::CID);
        $this->assertNull(ChatContactPhotos::read(self::WID, self::CID));
        $this->assertSame(0, ChatContactPhotos::version(self::WID, self::CID));
    }

    public function testRejectsNonImagesAndOversizedUploads(): void
    {
        $this->assertNotNull(ChatContactPhotos::save(self::WID, self::CID, 'not an image at all'));
        $this->assertNotNull(ChatContactPhotos::save(self::WID, self::CID, '<?php echo 1;'));
        $this->assertNotNull(ChatContactPhotos::save(self::WID, self::CID, ''));
        $this->assertNotNull(ChatContactPhotos::save(self::WID, self::CID, str_repeat('a', 5 * 1048576 + 1)));
        $this->assertNull(ChatContactPhotos::read(self::WID, self::CID), 'nothing is stored for a rejected upload');
    }

    public function testPathStaysInsideThePhotoFolder(): void
    {
        $p = ChatContactPhotos::path(12, 34);
        $this->assertStringEndsWith('/private/contact-photos/12/34.jpg', str_replace('\\', '/', $p));
    }

    public function testRoutesAndHandlersExist(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/routes/api.php');
        foreach (["{conversationId}/photo', 'ChatInboxController', 'photo'", "{conversationId}/photo', 'ChatInboxController', 'setPhoto'", "{conversationId}/photo/remove', 'ChatInboxController', 'removePhoto'"] as $needle) {
            $this->assertStringContainsString($needle, $src);
        }
        foreach (['photo', 'setPhoto', 'removePhoto'] as $m) {
            $this->assertTrue(method_exists(ChatInboxController::class, $m));
        }
    }
}
