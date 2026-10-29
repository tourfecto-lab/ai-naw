<?php

/**
 * Automatic frame check of a finished video before it is delivered: two frames are pulled with the local ffmpeg and
 * reviewed by the same vision gate the images use (stray text / logos / watermarks, deformed hands and faces, melted or duplicated
 * objects, brief mismatch). The lowest frame score decides. Fail-open: no ffmpeg, no vision service or any error means "not checked".
 */
class VideoQualityGate
{
    /** Clips are regenerated at this score or below (stricter than the image gate, a retry is worth more than a flawed clip). */
    public const REJECT_SCORE = 4;

    /** @var callable|null fn(string $file, float $second): ?string JPEG/PNG bytes */
    private $frames;
    private CreativeQualityGate $gate;

    public function __construct(?callable $frames = null, ?CreativeQualityGate $gate = null)
    {
        $this->frames = $frames;
        $this->gate = $gate ?: new CreativeQualityGate();
    }

    /** @return array{checked:bool,score:?int,passed:bool,reject:bool,issues:array,fix:string} */
    public function review(string $file, string $brief, float $duration, int $userId = 0): array
    {
        $worst = ['checked' => false, 'score' => null, 'passed' => true, 'reject' => false, 'issues' => [], 'fix' => ''];
        try {
            if ($this->frames === null && !VideoEditing::available()) {
                return $worst;
            }
            $duration = $duration > 0 ? $duration : 8.0;
            foreach ([0.25, 0.65] as $at) {
                $bytes = $this->frames ? ($this->frames)($file, round($duration * $at, 2)) : self::frame($file, round($duration * $at, 2));
                if (!is_string($bytes) || $bytes === '') {
                    continue;
                }
                $r = $this->gate->review($bytes, $brief, $userId);
                if (!$r['checked']) {
                    continue;
                }
                $r['reject'] = $r['score'] <= self::REJECT_SCORE;
                $r['passed'] = $r['score'] >= CreativeQualityGate::PASS_SCORE;
                if (!$worst['checked'] || $r['score'] < $worst['score']) {
                    $issues = $r['issues'];
                    $worst = $r;
                    $worst['issues'] = $issues;
                }
            }
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('Video QA error', ['error' => mb_substr($e->getMessage(), 0, 160)]);
            }
            return ['checked' => false, 'score' => null, 'passed' => true, 'reject' => false, 'issues' => [], 'fix' => ''];
        }
        return $worst;
    }

    private static function frame(string $file, float $second): ?string
    {
        $tmp = PrivateMedia::directory() . '/qa_' . bin2hex(random_bytes(8)) . '.jpg';
        try {
            VideoEditing::process([VideoEditing::binary('ffmpeg'), '-nostdin', '-v', 'error', '-y', '-ss', (string) $second, '-protocol_whitelist', 'file,pipe', '-f', 'mov', '-enable_drefs', '0', '-use_absolute_path', '0', '-i', $file, '-frames:v', '1', '-vf', 'scale=768:-2', '-threads', '1', $tmp], 15);
            $bytes = @file_get_contents($tmp);
            return is_string($bytes) && $bytes !== '' ? $bytes : null;
        } catch (Throwable $e) {
            return null;
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
