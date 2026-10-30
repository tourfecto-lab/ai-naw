<?php

/** Tourfecto - AI Voice Agent: platform-wide load. Returns a single number (calls in progress across all companies) and nothing about any company. */
class VoicePlatformLoad
{
    public static function activeCalls(): int
    {
        return (int) (Database::getInstance()->query("SELECT COUNT(*) AS n FROM voice_calls WHERE status = 'in_progress' AND started_at >= NOW() - INTERVAL 15 MINUTE")[0]['n'] ?? 0);
    }
}
