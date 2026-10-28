<?php

/**
 * ريلز من صور الرحلة الحقيقية (من غير أي ذكاء صناعي مدفوع): غلاف بهوية الشركة وعنوان البوست ← 3-5 صور من نفس الرحلة
 * بحركة زوم هادية وانتقالات ناعمة ← كارت النهاية (الدعوة للحجز + الدومين). 9:16 (1080×1920) حوالي 12-16 ثانية، بصوت صامت
 * (عشان المنصات تقبله والعميل يقدر يضيف موسيقى ترند من التطبيق لو حب).
 * plan() نقية: بتختار الصور (نفس الرحلة، وإلا نفس المكان) - بتتختبر.
 */
final class SocialReelMaker
{
    public const W = 1080;
    public const H = 1920;
    private const SEC = 2.6;
    private const FADE = 0.45;
    private const MIN_PHOTOS = 3;
    private const MAX_PHOTOS = 5;

    private PDO $pdo;
    private string $publicRoot;

    public function __construct(?PDO $pdo = null, ?string $publicRoot = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        $this->publicRoot = $publicRoot ?? ROOT_PATH . '/public_html';
    }

    public static function available(): bool
    {
        return class_exists('VideoEditing') && VideoEditing::available();
    }

    /**
     * صور الريل (نقية): صورة البوست الأول، وبعدها صور من نفس الرحلة، وإلا نفس المكان. أقل من 3 ← null (مانعملش ريل ضعيف).
     * @param array $assets [['id','video'?, 'trip'?, 'place'?, 'quality'?, 'has_text'?]]
     */
    public static function plan(?int $mainId, array $assets): ?array
    {
        $photos = array_values(array_filter($assets, static fn ($a) => empty($a['video'])));
        $byId = [];
        foreach ($photos as $a) $byId[(int) $a['id']] = $a;
        $main = $mainId !== null && isset($byId[$mainId]) ? $byId[$mainId] : null;
        if (!$main) return null;
        $norm = static fn ($v) => mb_strtolower(trim((string) $v));
        foreach (['trip', 'place'] as $key) {
            $k = $norm($main[$key] ?? '');
            if ($k === '') continue;
            $same = array_filter($photos, static fn ($a) => (int) $a['id'] !== (int) $main['id'] && $norm($a[$key] ?? '') === $k && empty($a['has_text']));
            usort($same, static fn ($a, $b) => (int) ($b['quality'] ?? 3) <=> (int) ($a['quality'] ?? 3));
            if (count($same) + 1 >= self::MIN_PHOTOS) {
                return array_merge([(int) $main['id']], array_map(static fn ($a) => (int) $a['id'], array_slice($same, 0, self::MAX_PHOTOS - 1)));
            }
        }
        return null;
    }

    /** فلتر ffmpeg (نقي): $kinds لكل مدخل 'cover' (تصميم 4:5 فوق خلفية مغبّشة) أو 'photo' (ملء الشاشة بزوم) */
    public static function filter(array $kinds): string
    {
        $fps = 30;
        $frames = (int) round(self::SEC * $fps);
        $parts = [];
        $labels = '';
        foreach (array_values($kinds) as $i => $kind) {
            $fade = sprintf('fade=t=in:st=0:d=%.2f,fade=t=out:st=%.2f:d=%.2f', self::FADE, self::SEC - self::FADE, self::FADE);
            if ($kind === 'cover') {
                $parts[] = "[{$i}:v]split=2[b{$i}][f{$i}];[b{$i}]scale=" . self::W . ':' . self::H . ':force_original_aspect_ratio=increase,crop=' . self::W . ':' . self::H . ",boxblur=24:2,eq=brightness=-0.08[bg{$i}];"
                    . "[f{$i}]scale=" . self::W . ":-2[fg{$i}];[bg{$i}][fg{$i}]overlay=(W-w)/2:(H-h)/2,fps={$fps},trim=duration=" . self::SEC . ",setsar=1,format=yuv420p,{$fade}[v{$i}]";
            } else {
                // زوم هادي للداخل (بالتبادل يمين/شمال) - الصورة بتتكبّر الأول عشان الحركة تبقى ناعمة من غير اهتزاز
                $x = $i % 2 ? "iw/2-(iw/zoom/2)+(on*0.9)" : "iw/2-(iw/zoom/2)-(on*0.9)";
                $parts[] = "[{$i}:v]scale=" . (int) (self::W * 1.5) . ':' . (int) (self::H * 1.5) . ':force_original_aspect_ratio=increase,crop=' . (int) (self::W * 1.5) . ':' . (int) (self::H * 1.5)
                    . ",zoompan=z='min(zoom+0.0011,1.10)':x='{$x}':y='ih/2-(ih/zoom/2)':d={$frames}:s=" . self::W . 'x' . self::H . ":fps={$fps},trim=duration=" . self::SEC . ",setsar=1,format=yuv420p,{$fade}[v{$i}]";
            }
            $labels .= "[v{$i}]";
        }
        return implode(';', $parts) . ';' . $labels . 'concat=n=' . count($kinds) . ':v=1:a=0[v]';
    }

    private function file(int $uid, int $mediaId): string
    {
        $row = PrivateMedia::owned($uid, $mediaId);
        $file = (new SocialBrandStudio($this->pdo))->imageFile($uid, $row);
        if (!$file || !is_file($file)) throw new RuntimeException('Reel photo unavailable');
        return $file;
    }

    /**
     * يعمل الريل ويرجّع media_item_id (نوع short_video).
     * @param int|null $coverId تصميم الغلاف بهوية الشركة (لو موجود)
     * @param int[] $photoIds صور الرحلة بالترتيب
     * @param int|null $endId كارت النهاية (لو موجود)
     */
    public function make(int $uid, ?int $coverId, array $photoIds, ?int $endId, string $title): int
    {
        if (!self::available()) throw new RuntimeException('Video rendering unavailable');
        $inputs = [];
        $kinds = [];
        if ($coverId) { $inputs[] = $this->file($uid, $coverId); $kinds[] = 'cover'; }
        foreach (array_slice($photoIds, 0, self::MAX_PHOTOS) as $id) { $inputs[] = $this->file($uid, (int) $id); $kinds[] = 'photo'; }
        if ($endId) { $inputs[] = $this->file($uid, $endId); $kinds[] = 'cover'; }
        if (count(array_filter($kinds, static fn ($k) => $k === 'photo')) < self::MIN_PHOTOS - 1) throw new RuntimeException('Not enough photos for a reel');

        $dir = $this->publicRoot . '/uploads/social/' . $uid;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('Upload folder not writable');
        $base = '/uploads/social/' . $uid . '/reel_' . date('Ymd') . '_' . bin2hex(random_bytes(6));
        $out = $this->publicRoot . $base . '.mp4';
        $args = [VideoEditing::binary('ffmpeg'), '-nostdin', '-v', 'error', '-y'];
        foreach ($inputs as $f) array_push($args, '-loop', '1', '-t', (string) self::SEC, '-i', $f);
        $duration = round(count($inputs) * self::SEC, 2);
        array_push($args, '-f', 'lavfi', '-t', (string) $duration, '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100',
            '-filter_complex_threads', '1', '-filter_complex', self::filter($kinds), '-map', '[v]', '-map', count($inputs) . ':a',
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '21', '-pix_fmt', 'yuv420p', '-r', '30', '-c:a', 'aac', '-b:a', '96k', '-shortest', '-threads', '2', '-movflags', '+faststart', $out);
        try {
            VideoEditing::process($args, 150);
            if (!is_file($out) || filesize($out) < 1000) throw new RuntimeException('Reel render failed');
            $poster = null;
            try {
                VideoEditing::process([VideoEditing::binary('ffmpeg'), '-nostdin', '-v', 'error', '-y', '-ss', '0.8', '-i', $out, '-frames:v', '1', '-vf', 'scale=540:-2', $this->publicRoot . $base . '.jpg'], 20);
                if (is_file($this->publicRoot . $base . '.jpg')) $poster = $base . '.jpg';
            } catch (Throwable $e) {
            }
            $meta = ['source' => 'social_reel', 'cover_media_id' => $coverId, 'photo_ids' => array_values(array_map('intval', $photoIds)), 'end_media_id' => $endId, 'headline' => $title];
            $this->pdo->prepare("INSERT INTO media_items(user_id,type,prompt,file_path,thumbnail_path,width,height,duration_seconds,status,generation_meta,created_at,updated_at) VALUES(?,'short_video',?,?,?,?,?,?,'completed',?,NOW(),NOW())")
                ->execute([$uid, mb_substr($title, 0, 250), $base . '.mp4', $poster ?? $base . '.mp4', self::W, self::H, (int) ceil($duration), json_encode($meta, JSON_UNESCAPED_UNICODE)]);
            return (int) $this->pdo->lastInsertId();
        } catch (Throwable $e) {
            if (is_file($out)) @unlink($out);
            if (is_file($this->publicRoot . $base . '.jpg')) @unlink($this->publicRoot . $base . '.jpg');
            throw $e;
        }
    }
}
