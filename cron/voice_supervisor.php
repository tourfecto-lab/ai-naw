<?php
/** Hourly: the AI Call Supervisor reviews flagged and sampled calls (capped per company per day, skipped when the company's budget is spent) and pushes insights to the AI CEO. */
require_once __DIR__.'/bootstrap.php';
try {
    fwrite(STDOUT,json_encode((new VoiceSupervisorService())->run(20),JSON_THROW_ON_ERROR).PHP_EOL);
} catch(Throwable $e) {Logger::error('Voice supervisor run failed',['message'=>$e->getMessage()]);fwrite(STDERR,"Voice supervisor run failed; check the voice migrations.\n");exit(1);}
