<?php
/** Every 15 minutes: queues AI follow-up calls from bookings (abandoned recovery, trip reminders, confirmations) for companies that turned them on. Queuing never dials: compliance and live approvals still apply at dial time. */
require_once __DIR__.'/bootstrap.php';
try {
    fwrite(STDOUT,json_encode((new VoiceAutoFollowUps())->run(),JSON_THROW_ON_ERROR).PHP_EOL);
} catch(Throwable $e) {Logger::error('Voice follow-up queueing failed',['message'=>$e->getMessage()]);fwrite(STDERR,"Voice follow-up queueing failed; check the voice migrations.\n");exit(1);}
