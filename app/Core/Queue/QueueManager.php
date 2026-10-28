<?php

/**
 * Tourfecto - Queue Manager
 * @version 1.0.0
 *
 * ليه DB-backed queue مش Redis/RabbitMQ؟
 * -----------------------------------------
 * الاستضافة الحالية (Hostinger مشتركة) معندهاش worker process دائم ولا
 * غالبًا Redis. أقصى حاجة متاحة هي Cron Job حقيقي من cPanel بيشغّل سكريبت
 * PHP كل دقيقة (أو أي فترة). عشان كده الطابور هنا جدول عادي في قاعدة
 * البيانات (`jobs`)، و"الـ worker" هو سكريبت CLI (انظر cron/process_queue.php)
 * بيتنده من الـ cron بيسحب المهام المستحقة ويشغّلها، بدل daemon حقيقي.
 *
 * لو يوم من الأيام الاستضافة اتغيرت لـ VPS فيه Redis، تقدر تستبدل الكلاس
 * ده بنسخة تانية بنفس الـ public API (push/processNext) من غير ما تغيّر
 * حرف في أي كود بينادي عليه.
 *
 * الاستخدام:
 *   QueueManager الـ table اسمها `jobs` - شوف
 *   database/migrations/2026_07_13_000001_create_jobs_table.sql
 *
 *   $queue = Container::getInstance()->make(QueueManager::class);
 *   $queue->push(SendWhatsAppJob::class, ['phone' => '...', 'message' => '...']);
 *
 *   // في سكريبت الـ cron:
 *   $queue->processDue(20); // شغّل حتى 20 مهمة مستحقة
 */
class QueueManager
{
    private const TABLE = 'jobs';
    private const MAX_ATTEMPTS = 3;
    /** ثواني - أي مهمة "processing" أكتر من كده تعتبر عالقة وترجع pending */
    private const STALE_LOCK_SECONDS = 300;

    /** @var Database */
    private $db;

    /** @var bool */
    private $tableExists;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->tableExists = $this->checkTableExists();
    }

    private function checkTableExists(): bool
    {
        try {
            $rows = $this->db->query(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [self::TABLE]
            );
            return !empty($rows);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * إضافة مهمة جديدة للطابور.
     * @param string $jobClass كلاس بيعمل implements QueueJobInterface
     * @param array $payload
     * @param string $queue اسم الطابور (default/high/low...) لو حابب تفصل الأولويات
     * @param int $delaySeconds تأخير قبل ما تبقى قابلة للتنفيذ
     * @return int|false معرف المهمة، أو false لو فشل
     */
    public function push(string $jobClass, array $payload = [], string $queue = 'default', int $delaySeconds = 0)
    {
        if (!$this->tableExists) {
            if (class_exists('Logger')) {
                Logger::warning('QueueManager: جدول jobs غير موجود، شغّل migration جدول jobs الأول', ['job' => $jobClass]);
            }
            return false;
        }

        // Use the same database clock for available_at and the due-job comparison (NOW()).
        $delaySeconds = max(0, $delaySeconds);

        $sql = "INSERT INTO `" . self::TABLE . "` (queue, job_class, payload, status, attempts, available_at, created_at)
                VALUES (?, ?, ?, 'pending', 0, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())";

        return $this->db->query($sql, [$queue, $jobClass, json_encode($payload, JSON_UNESCAPED_UNICODE), $delaySeconds]);
    }

    /**
     * تنفيذ حتى $limit مهمة مستحقة الآن. ده اللي بينادي عليه سكريبت الـ cron.
     * @param int $limit
     * @return array ملخص التنفيذ ['processed' => n, 'failed' => n]
     */
    public function processDue(int $limit = 20): array
    {
        if (!$this->tableExists) {
            return ['processed' => 0, 'failed' => 0, 'error' => 'jobs table missing'];
        }

        $limit = max(1, min(200, $limit));
        $this->releaseStaleLocks();

        $jobs = $this->db->query(
            "SELECT * FROM `" . self::TABLE . "`
             WHERE status = 'pending' AND available_at <= NOW()
             ORDER BY id ASC LIMIT ?",
            [$limit]
        );

        $processed = 0;
        $failed = 0;

        foreach ($jobs as $job) {
            // لو مهمة تانية (cron تاني / worker احتياطي) حجزتها قبلنا: نعدّي عليها
            if (!$this->markProcessing((int) $job['id'])) {
                continue;
            }

            try {
                $this->runJob($job);
                $this->markCompleted((int) $job['id']);
                $processed++;
            } catch (Throwable $e) {
                $this->markFailed((int) $job['id'], (int) $job['attempts'], $e->getMessage());
                $failed++;
            }
        }

        return ['processed' => $processed, 'failed' => $failed, 'total' => count($jobs)];
    }

    private function runJob(array $job): void
    {
        $jobClass = $job['job_class'];
        if(in_array($jobClass,['SendLoginAdminAlertJob','SendRegistrationAdminAlertJob','SocialMonthlyRecurringJob','GenerateSocialMonthlyPlanJob','RefreshSocialPlanMetricsJob','SeoExpertCycleJob','SeoExpertExecuteJob','SeoImpactCollectJob'],true)&&!class_exists($jobClass)){
            require_once dirname(__DIR__,2).'/Jobs/'.$jobClass.'.php';
        }

        if (!class_exists($jobClass)) {
            throw new Exception("Queue job class not found: {$jobClass}");
        }

        $instance = new $jobClass();
        if (!($instance instanceof QueueJobInterface)) {
            throw new Exception("Queue job {$jobClass} لازم يعمل implements QueueJobInterface");
        }

        $payload = json_decode($job['payload'] ?? '[]', true) ?: [];
        $instance->handle($payload);
    }

    /**
     * حجز ذرّي للمهمة: بيرجّع true لو العملية دي هي اللي حجزتها فعلاً.
     * (الـ cron والـ worker الاحتياطي ممكن يشتغلوا في نفس اللحظة، ولازم المهمة ما تتنفّذش مرتين.)
     * ملاحظة: Database::query() بترجّع عدد الصفوف المتأثرة في UPDATE، أما exec() فبترجّع bool بس.
     */
    private function markProcessing(int $id): bool
    {
        $n = $this->db->query(
            "UPDATE `" . self::TABLE . "` SET status = 'processing', attempts = attempts + 1, reserved_at = NOW() WHERE id = ? AND status = 'pending' AND attempts < " . self::MAX_ATTEMPTS . "",
            [$id]
        );
        return (int) $n === 1;
    }

    /**
     * تنفيذ مهمة واحدة بعينها (للـ worker الاحتياطي لما الـ cron مش شغّال).
     * @return array ['claimed'=>bool, 'status'=>'completed'|'failed'|'not_pending'|'lost_race', 'error'=>?string]
     */
    public function processJob(int $id): array
    {
        if (!$this->tableExists) {
            return ['claimed' => false, 'status' => 'not_pending'];
        }
        // مهمة "processing" عالقة أكتر من STALE_LOCK_SECONDS (العامل وقع) نرجّعها pending
        $this->db->query(
            "UPDATE `" . self::TABLE . "` SET status = CASE WHEN attempts >= " . self::MAX_ATTEMPTS . " THEN 'failed' ELSE 'pending' END WHERE id = ? AND status = 'processing' AND reserved_at < DATE_SUB(NOW(), INTERVAL " . (int) self::STALE_LOCK_SECONDS . " SECOND)",
            [$id]
        );
        $rows = $this->db->query(
            "SELECT * FROM `" . self::TABLE . "` WHERE id = ? AND status = 'pending' AND available_at <= NOW() LIMIT 1",
            [$id]
        );
        if (empty($rows)) {
            return ['claimed' => false, 'status' => 'not_pending'];
        }
        $job = $rows[0];
        if (!$this->markProcessing($id)) {
            return ['claimed' => false, 'status' => 'lost_race'];
        }
        try {
            $this->runJob($job);
            $this->markCompleted($id);
            return ['claimed' => true, 'status' => 'completed'];
        } catch (Throwable $e) {
            $this->markFailed($id, (int) $job['attempts'], $e->getMessage());
            return ['claimed' => true, 'status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    private function markCompleted(int $id): void
    {
        $this->db->query(
            "UPDATE `" . self::TABLE . "` SET status = 'completed', completed_at = NOW() WHERE id = ?",
            [$id]
        );
    }

    private function markFailed(int $id, int $attemptsBefore, string $errorMessage): void
    {
        $attemptsNow = $attemptsBefore + 1;
        $status = $attemptsNow >= self::MAX_ATTEMPTS ? 'failed' : 'pending';
        // إعادة المحاولة بتأخير تصاعدي بسيط (backoff): 30s, 60s, 90s...
        $backoff = 30 * $attemptsNow;

        $this->db->query(
            "UPDATE `" . self::TABLE . "`
             SET status = ?, last_error = ?, available_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
             WHERE id = ?",
            [$status, substr($errorMessage, 0, 1000), $backoff, $id]
        );

        if (class_exists('Logger')) {
            Logger::error('Queue job failed', ['job_id' => $id, 'attempts' => $attemptsNow, 'error' => $errorMessage]);
        }
    }

    /**
     * أي مهمة فضلت "processing" أكتر من STALE_LOCK_SECONDS (يعني cron
     * سابق اتقفل فجأة/timeout) - نرجّعها pending تاني بدل ما تفضل عالقة للأبد.
     */
    private function releaseStaleLocks(): void
    {
        $this->db->query(
            "UPDATE `" . self::TABLE . "` SET status = CASE WHEN attempts >= " . self::MAX_ATTEMPTS . " THEN 'failed' ELSE 'pending' END
             WHERE status = 'processing' AND reserved_at < DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [self::STALE_LOCK_SECONDS]
        );
    }

    public function isReady(): bool
    {
        return $this->tableExists;
    }
}
