<?php

/**
 * Contact photos for the inbox. The WhatsApp Cloud API never tells us a customer's profile picture, so an agent can set
 * one by hand. Stored as a small square JPEG in private storage (no database change), one file per conversation.
 */
class ChatContactPhotos
{
    private const SIZE = 160;
    private const MAX_BYTES = 5 * 1048576;
    private const MAX_PIXELS = 36000000;

    public static function path(int $websiteId, int $conversationId): string
    {
        return TOURFECTO_STORAGE . '/private/contact-photos/' . $websiteId . '/' . $conversationId . '.jpg';
    }

    /** Version (file time) for cache busting, or 0 when the conversation has no photo. */
    public static function version(int $websiteId, int $conversationId): int
    {
        $p = self::path($websiteId, $conversationId);
        return is_file($p) ? (int) @filemtime($p) : 0;
    }

    public static function read(int $websiteId, int $conversationId): ?string
    {
        $p = self::path($websiteId, $conversationId);
        if (!is_file($p)) {
            return null;
        }
        $bytes = @file_get_contents($p);
        return $bytes === false ? null : $bytes;
    }

    public static function remove(int $websiteId, int $conversationId): void
    {
        $p = self::path($websiteId, $conversationId);
        if (is_file($p)) {
            @unlink($p);
        }
    }

    /**
     * Validate, crop to a square, shrink and store an uploaded picture.
     * @return string|null an error message, or null when saved
     */
    public static function save(int $websiteId, int $conversationId, string $bytes): ?string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return 'The server cannot process images (GD is missing)';
        }
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return 'Choose a picture up to 5 MB';
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            return 'Use a JPG, PNG or WebP picture';
        }
        if ((int) $info[0] * (int) $info[1] > self::MAX_PIXELS) {
            return 'The picture is too large';
        }
        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            return 'The picture could not be read';
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $dst = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // transparent PNGs get a white background
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), self::SIZE, self::SIZE, $side, $side);
        ob_start();
        imagejpeg($dst, null, 82);
        $jpeg = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        if ($jpeg === '') {
            return 'The picture could not be processed';
        }
        $p = self::path($websiteId, $conversationId);
        $dir = dirname($p);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return 'The picture could not be saved';
        }
        return @file_put_contents($p, $jpeg, LOCK_EX) === false ? 'The picture could not be saved' : null;
    }
}
