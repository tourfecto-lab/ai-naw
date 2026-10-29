<?php

/**
 * The last few things Meta posted to the shared Messenger / Instagram webhook and what we did with each. Meta shows nothing
 * on its side when a message is ignored, so this is how an owner (or we) can tell "Meta never sent it" from "we received it
 * but dropped it". Ids and outcomes only - never message text. One small private file, no database change.
 */
final class MetaWebhookLog
{
    private const KEEP = 40;

    private static function file(): string
    {
        return TOURFECTO_STORAGE . '/private/meta-webhook-log.json';
    }

    /** @param array<string,scalar|null> $row */
    public static function add(array $row): void
    {
        if (defined('TOURFECTO_TEST_ENV')) {
            return; // the test suite exercises route() with fake accounts; keep them out of the real log
        }
        try {
            $f = self::file();
            $dir = dirname($f);
            if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
                return;
            }
            $h = @fopen($f, 'c+');
            if (!$h) {
                return;
            }
            if (flock($h, LOCK_EX)) {
                $all = json_decode((string) stream_get_contents($h), true);
                $all = is_array($all) ? $all : [];
                $all[] = ['t' => gmdate('Y-m-d H:i:s')] + array_map(static fn ($v) => is_string($v) ? mb_substr($v, 0, 80) : $v, $row);
                $all = array_slice($all, -self::KEEP);
                ftruncate($h, 0);
                rewind($h);
                fwrite($h, (string) json_encode($all, JSON_UNESCAPED_UNICODE));
                fflush($h);
                flock($h, LOCK_UN);
            }
            fclose($h);
        } catch (Throwable $e) {
            // diagnostics must never affect message handling
        }
    }

    /** @return array<int,array<string,mixed>> newest first */
    public static function recent(): array
    {
        $all = json_decode((string) @file_get_contents(self::file()), true);
        return is_array($all) ? array_reverse($all) : [];
    }
}
