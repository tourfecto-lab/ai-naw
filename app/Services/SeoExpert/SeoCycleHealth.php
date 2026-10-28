<?php
/** Read-only diagnosis; only a missing pre-generation crawl job can be recovered. */
final class SeoCycleHealth {
    public static function evaluate(array $run,array $jobs,bool $available,bool $worker,bool $identity,string $reservation,int $tasks,int $now): array {
        $phase=(string)($run['status']??'');$raw=(string)($run['updated_at']??'');
        $stamp=preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$raw)?strtotime($raw.' UTC'):false;
        $age=$stamp!==false&&gmdate('Y-m-d H:i:s',$stamp)===$raw&&$now>=$stamp?$now-$stamp:null;$counts=['pending'=>0,'processing'=>0,'failed'=>0,'completed'=>0];$blockedAttempts=false;
        foreach($jobs as $job){$state=(string)($job['status']??'');if(isset($counts[$state]))$counts[$state]++;else $available=false;if($state==='pending'&&(int)($job['attempts']??0)>=3)$blockedAttempts=true;}
        $limited=count($jobs)>50;$state='not_recoverable';$safe=false;
        if(!$available||$limited)$state='queue_unavailable';
        elseif($counts['processing'])$state='processing';
        elseif($counts['pending'])$state=$blockedAttempts?'attempt_limit':'waiting_worker';
        elseif(!in_array($phase,['queued','scanning'],true))$state=in_array($phase,['planning','failed'],true)?'outcome_review':'phase_not_recoverable';
        elseif(!$identity)$state='identity_review';
        elseif(!$worker)$state='worker_unavailable';
        elseif($reservation!=='reserved'||$tasks>0||!empty($run['approved_at']))$state='outcome_review';
        elseif($age===null)$state='time_unavailable';
        elseif($age<900)$state='recent_progress';
        else {$state='missing_crawl_job';$safe=true;}
        return ['state'=>$state,'recoverable'=>$safe,'phase'=>$phase,'last_progress_at'=>$raw?:null,'age_seconds'=>$age,'worker_ready'=>$worker,'queue_available'=>$available&&!$limited,'jobs'=>$counts,'no_new_cycle_charge'=>true];
    }
}
