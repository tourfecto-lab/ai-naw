<?php
/** Every minute: dials due AI voice jobs. Each job is re-checked by the compliance engine first; without platform live mode + tenant live mode + per-job approval it only simulates. */
require_once __DIR__.'/bootstrap.php';
try {
    $out=(new VoiceOutboundService())->runDue(10);
    fwrite(STDOUT,json_encode($out,JSON_THROW_ON_ERROR).PHP_EOL);
} catch(Throwable $e) {Logger::error('Voice outbound run failed',['message'=>$e->getMessage()]);fwrite(STDERR,"Voice outbound run failed; check the voice_outbound migration.\n");exit(1);}
