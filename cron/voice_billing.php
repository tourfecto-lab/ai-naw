<?php
/** Daily: accrue number rental, purge expired transcripts per company retention, and (on the 1st) create last month's invoices. Charging stays off unless voice_billing_enabled=1. */
require_once __DIR__.'/bootstrap.php';
try {
    $usage=new VoiceUsageService();
    $out=['rental_rows'=>$usage->accrueRental(),'purged'=>0,'invoices'=>0];
    $retention=new VoiceRetentionService();
    $settings=new VoiceSettingsService();
    foreach(Database::getInstance()->query('SELECT tenant_user_id FROM voice_agent_settings') ?: [] as $r){
        $t=(int)$r['tenant_user_id'];
        $out['purged']+=$retention->purgeExpired($t,$settings->get($t));
    }
    if((int)gmdate('j')===1){
        $month=gmdate('Y-m',strtotime('first day of last month'));
        $billing=new VoiceBillingService($usage);
        foreach(Database::getInstance()->query("SELECT DISTINCT tenant_user_id FROM voice_usage_ledger WHERE occurred_at >= ? AND occurred_at < DATE_ADD(?, INTERVAL 1 MONTH)",[$month.'-01 00:00:00',$month.'-01 00:00:00']) ?: [] as $r){
            $t=(int)$r['tenant_user_id'];
            $billing->invoice($t,$month);
            $billing->settle($t,$month);
            $out['invoices']++;
        }
    }
    fwrite(STDOUT,json_encode($out,JSON_THROW_ON_ERROR).PHP_EOL);
} catch(Throwable $e) {Logger::error('Voice billing run failed',['message'=>$e->getMessage()]);fwrite(STDERR,"Voice billing run failed; check the voice migrations.\n");exit(1);}
