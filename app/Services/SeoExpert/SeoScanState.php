<?php
/** حالة الفحص كما يراها العميل: بتفرّق بين الانتظار والتشغيل والجزئي وعدم قراءة أي صفحة والنجاح بلا توصيات. */
final class SeoScanState
{
    /** @return array{state:string,pages_audited:int,pages_failed:int,pages_excluded:int,pages_queued:int} */
    public static function classify(array $run, array $snapshot, int $tasks, array $health = []): array
    {
        $c = $snapshot['coverage'] ?? [];
        $audited = (int)($c['audited'] ?? count($snapshot['pages'] ?? []));
        $failed = (int)($c['failed'] ?? count($snapshot['failures'] ?? []));
        $queued = (int)($c['queued'] ?? count($snapshot['pending_urls'] ?? []));
        $excluded = count($snapshot['excluded_routes'] ?? []);
        $status = (string)($run['status'] ?? '');
        $workerWaiting = in_array($health['state'] ?? '', ['waiting_worker', 'worker_unavailable'], true);
        if ($status === 'queued') $state = $workerWaiting || empty($health) ? 'waiting_worker' : 'running';
        elseif ($status === 'failed') $state = $audited === 0 ? 'no_pages_readable' : 'failed';
        elseif (in_array($status, ['scanning', 'scanned', 'planning'], true)) $state = ($status === 'scanning' && $workerWaiting) ? 'waiting_worker' : (!empty($snapshot['partial']) && $status === 'scanning' ? 'partial' : 'running');
        elseif ($audited === 0) $state = 'no_pages_readable';
        elseif (!empty($snapshot['partial'])) $state = 'partial';
        elseif ($tasks === 0) $state = 'completed_no_recommendations';
        else $state = 'completed';
        return ['state' => $state, 'pages_audited' => $audited, 'pages_failed' => $failed, 'pages_excluded' => $excluded, 'pages_queued' => $queued];
    }
}
