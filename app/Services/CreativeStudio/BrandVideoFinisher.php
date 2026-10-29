<?php

/**
 * Branded copy of a finished video: the company's logo placed in a safe area, produced with the local video editor
 * (free: no AI call and no wallet debit). The original clip is never touched, so the customer keeps both.
 *
 * The overlay is a transparent PNG with the video's aspect ratio; RenderCreativeVideoJob scales it over the clip.
 * Placement keeps clear of the zones Reels / Stories / TikTok cover with their own buttons and captions.
 */
class BrandVideoFinisher
{
    /**
     * @return string|null PNG bytes, or null when the logo is missing / unreadable
     */
    public static function overlayPng(int $width, int $height, ?string $logoPath): ?string
    {
        if ($width < 64 || $height < 64 || $logoPath === null || $logoPath === '' || !function_exists('imagecreatetruecolor')) {
            return null;
        }
        $file = (defined('ROOT_PATH') ? ROOT_PATH : '') . '/public_html' . $logoPath;
        $realBase = realpath((defined('ROOT_PATH') ? ROOT_PATH : '') . '/public_html');
        $real = is_file($file) ? realpath($file) : false;
        if (!$real || !$realBase || strpos($real, $realBase . DIRECTORY_SEPARATOR) !== 0) {
            return null; // never read outside the public folder
        }
        $logo = @imagecreatefromstring((string) @file_get_contents($real));
        if (!$logo) {
            return null;
        }
        $lw = imagesx($logo);
        $lh = imagesy($logo);

        $portrait = $height >= $width;
        $target = (int) round(min($width, $height) * ($portrait ? 0.20 : 0.14)); // logo box width
        $pad = max(10, (int) round($target * 0.12));
        $scale = min(($target - 2 * $pad) / $lw, ($target * 0.6 - 2 * $pad) / $lh);
        $tw = max(8, (int) round($lw * $scale));
        $th = max(8, (int) round($lh * $scale));
        $bw = $tw + 2 * $pad;
        $bh = $th + 2 * $pad;
        // portrait: left, above the caption / button zone (~28% from the bottom); landscape: bottom-left corner
        $x = (int) round($width * 0.06);
        $y = $portrait ? (int) round($height * 0.70) - $bh : (int) round($height * 0.90) - $bh;

        $img = imagecreatetruecolor($width, $height);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagealphablending($img, true);
        // soft white badge so any logo (dark or light) stays readable on every background
        $badge = imagecolorallocatealpha($img, 255, 255, 255, 18);
        self::roundedRect($img, $x, $y, $x + $bw, $y + $bh, (int) round($bh * 0.22), $badge);
        imagecopyresampled($img, $logo, $x + $pad, $y + $pad, 0, 0, $tw, $th, $lw, $lh);
        imagedestroy($logo);

        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);
        return $png !== '' ? $png : null;
    }

    private static function roundedRect($img, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        imagefilledellipse($img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
    }

    /**
     * Queue the branded copy of a just-finished generated video. Best effort: it must never break the generation itself.
     * @return MediaItem|null the queued edit, or null when nothing applies
     */
    public static function queue(MediaItem $item): ?MediaItem
    {
        try {
            $item = (new MediaItem())->find((int) $item->getAttribute('id')) ?: $item; // the caller's copy may predate completion
            if ((string) $item->getAttribute('type') !== 'short_video' || (string) $item->getAttribute('status') !== 'completed') {
                return null;
            }
            $meta = json_decode((string) $item->getAttribute('generation_meta'), true) ?: [];
            if (($meta['source'] ?? '') === 'video_editor' || !empty($meta['storyboard']) || (($meta['options']['brand_overlay'] ?? true) === false)) {
                return null; // an edit itself, a storyboard scene (assembled later) or an explicit opt-out
            }
            $brandId = (int) $item->getAttribute('brand_profile_id');
            if ($brandId < 1 || !VideoEditing::available()) {
                return null;
            }
            $brand = (new BrandProfile())->find($brandId);
            if (!$brand || (int) $brand->getAttribute('user_id') !== (int) $item->getAttribute('user_id')) {
                return null;
            }
            $logo = (string) $brand->getAttribute('logo_path');
            if ($logo === '') {
                return null;
            }
            $id = (int) $item->getAttribute('id');
            $probe = VideoEditing::probe(PrivateMedia::resolve((string) $item->getAttribute('file_path')));
            $png = self::overlayPng((int) $probe['width'], (int) $probe['height'], $logo);
            if ($png === null) {
                return null;
            }
            return VideoEditing::enqueue(
                (int) $item->getAttribute('user_id'),
                $id,
                $png,
                ['start' => 0, 'end' => min(8, round((float) $probe['duration'], 2)), 'mute' => false],
                'brandfin_' . $id . '_' . substr(hash('sha256', $id . '|' . $logo), 0, 16)
            );
        } catch (Throwable $e) {
            Logger::warning('Branded copy skipped', ['media_item_id' => (int) $item->getAttribute('id'), 'reason' => mb_substr($e->getMessage(), 0, 160)]);
            return null;
        }
    }
}
