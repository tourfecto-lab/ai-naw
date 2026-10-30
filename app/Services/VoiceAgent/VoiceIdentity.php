<?php

/** Tourfecto - AI Voice Agent: knowledge-based identity check. Caller ID alone is never enough to reveal booking details. */
class VoiceIdentity
{
    public const MAX_ATTEMPTS = 3;

    public static function normalize(string $s): string
    {
        $s = strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        $s = mb_strtolower(trim($s));
        // speech recognition writes emails as "name at example dot com"
        $s = preg_replace(['/\s+at\s+/u', '/\s+dot\s+/u', '/\s+/u'], ['@', '.', ''], $s);
        return preg_replace('/[^\p{L}\p{N}@.]/u', '', $s);
    }

    /** @param array{email?:?string, refs?:string[]} $factors what we hold for the matched customer */
    public static function matches(string $answer, array $factors): bool
    {
        $a = self::normalize($answer);
        if (strlen($a) < 5) {
            return false;
        }
        $candidates = array_map([self::class, 'normalize'], array_filter(array_merge([(string) ($factors['email'] ?? '')], $factors['refs'] ?? [])));
        foreach ($candidates as $c) {
            if ($c !== '' && hash_equals($c, $a)) {
                return true;
            }
        }
        return false;
    }

    public static function attemptsLeft(int $used): int
    {
        return max(0, self::MAX_ATTEMPTS - $used);
    }
}
