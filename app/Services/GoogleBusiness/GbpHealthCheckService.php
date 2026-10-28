<?php

/**
 * Tourfecto - GBP Health Check
 * فحص صحة حقيقي للموديول (بند AP/AQ بالسبيك) - كل عنصر بيتفحص فعليًا من
 * قاعدة البيانات/الإعدادات، مفيش "OK" افتراضي. الهدف: منع حالة "النظام
 * شغال" وهمية بينما الطابور واقف فعليًا (زي ما طلب صراحة بند AQ).
 * @version 1.0.0
 * @since 2026-08-14 (GBP Module Upgrade - Round 8: Professional Finalization / Phase AP)
 */
class GbpHealthCheckService
{
    /** لو مفيش Job (من أي نوع، مش GBP بس) اتنفذت خلال الفترة دي، الطابور غالبًا واقف */
    private const QUEUE_STALE_MINUTES = 15;

    public function check(): array
    {
        $items = [];

        $items['oauth_configured'] = $this->checkOAuthConfigured();
        $items['google_maps_configured'] = $this->checkMapsConfigured();
        $items['database_tables'] = $this->checkDatabaseTables();
        $items['queue_worker'] = $this->checkQueueWorker();
        $items['last_successful_sync'] = $this->checkLastSync('success');
        $items['last_failed_sync'] = $this->checkLastSync('failed');
        $items['ai_available'] = $this->checkAiAvailable();

        $overall = 'OK';
        foreach ($items as $item) {
            if ($item['status'] === 'ERROR') {
                $overall = 'ERROR';
                break;
            }
            if ($item['status'] === 'WARNING' && $overall !== 'ERROR') {
                $overall = 'WARNING';
            }
        }

        return ['overall' => $overall, 'checks' => $items, 'checked_at' => date('Y-m-d H:i:s')];
    }

    private function checkOAuthConfigured(): array
    {
        try {
            $oauth = new GoogleOAuthClient();
            return $oauth->isConfigured()
                ? ['status' => 'OK', 'message' => 'OAuth Client مضبوط']
                : ['status' => 'ERROR', 'message' => 'OAuth Client غير مضبوط - الاتصال بـ Google مستحيل حاليًا'];
        } catch (Throwable $e) {
            return ['status' => 'ERROR', 'message' => 'تعذر فحص إعداد OAuth'];
        }
    }

    private function checkMapsConfigured(): array
    {
        try {
            $envKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
            $key = class_exists('SystemSettingsService') ? (new SystemSettingsService())->get('google_maps_api_key', $envKey) : $envKey;
            return $key !== ''
                ? ['status' => 'OK', 'message' => 'مفتاح Google Maps مضبوط']
                : ['status' => 'WARNING', 'message' => 'مفتاح Google Maps غير مضبوط - الخريطة مش هتظهر'];
        } catch (Throwable $e) {
            return ['status' => 'WARNING', 'message' => 'تعذر فحص إعداد Maps'];
        }
    }

    private function checkDatabaseTables(): array
    {
        $requiredTables = ['gbp_sync_logs', 'gbp_photos', 'gbp_insights_cache', 'gbp_audit_log', 'gbp_scheduled_posts', 'gbp_content', 'platform_connections'];
        $missing = [];

        try {
            $db = Database::getInstance();
            foreach ($requiredTables as $table) {
                try {
                    $db->query("SELECT 1 FROM `{$table}` LIMIT 1", []);
                } catch (Throwable $e) {
                    $missing[] = $table;
                }
            }
        } catch (Throwable $e) {
            return ['status' => 'ERROR', 'message' => 'تعذر الاتصال بقاعدة البيانات'];
        }

        if (empty($missing)) {
            return ['status' => 'OK', 'message' => 'كل الجداول المطلوبة موجودة'];
        }
        return ['status' => 'ERROR', 'message' => 'جداول ناقصة - شغّل الـ migrations: ' . implode('، ', $missing)];
    }

    /**
     * مؤشر غير مباشر لو الـ Queue Worker (cron/process_queue.php) شغّال:
     * بنشوف آخر مرة أي Job (مش GBP بس - مؤشر عام) اتعالج فعليًا. لو مفيش
     * حركة خلال آخر 15 دقيقة رغم وجود Jobs pending، الطابور غالبًا واقف.
     */
    private function checkQueueWorker(): array
    {
        try {
            $db = Database::getInstance();
            $rows = $db->query(
                "SELECT MAX(completed_at) AS last_completed FROM jobs WHERE completed_at IS NOT NULL",
                []
            );
            $lastCompleted = $rows[0]['last_completed'] ?? null;

            $pendingRows = $db->query("SELECT COUNT(*) AS cnt FROM jobs WHERE status = 'pending' AND available_at <= NOW()", []);
            $duePending = (int) ($pendingRows[0]['cnt'] ?? 0);

            if (!$lastCompleted && $duePending === 0) {
                return ['status' => 'WARNING', 'message' => 'مفيش أي Job اتنفذ لسه - طبيعي لو المشروع جديد'];
            }

            $minutesSinceLastRun = $lastCompleted ? (int) round((time() - strtotime($lastCompleted)) / 60) : null;

            if ($duePending > 0 && ($minutesSinceLastRun === null || $minutesSinceLastRun > self::QUEUE_STALE_MINUTES)) {
                return [
                    'status' => 'ERROR',
                    'message' => "Queue worker مش شغّال - فيه {$duePending} Job مستني، وآخر تنفيذ كان من " . ($minutesSinceLastRun ?? '∞') . ' دقيقة. تأكد إن cron/process_queue.php مضبوط كـ Cron Job فعليًا.',
                ];
            }

            return ['status' => 'OK', 'message' => 'Queue worker شغّال (آخر تنفيذ من ' . ($minutesSinceLastRun ?? 0) . ' دقيقة)'];
        } catch (Throwable $e) {
            return ['status' => 'WARNING', 'message' => 'تعذر فحص حالة الطابور (جدول jobs غير موجود؟)'];
        }
    }

    private function checkLastSync(string $status): array
    {
        try {
            $db = Database::getInstance();
            $rows = $db->query(
                "SELECT MAX(finished_at) AS last_time FROM gbp_sync_logs WHERE status = ?",
                [$status]
            );
            $lastTime = $rows[0]['last_time'] ?? null;

            if (!$lastTime) {
                return ['status' => 'WARNING', 'message' => "مفيش سجل مزامنة {$status} لسه"];
            }
            return ['status' => 'OK', 'message' => "آخر مزامنة {$status}: {$lastTime}"];
        } catch (Throwable $e) {
            return ['status' => 'WARNING', 'message' => 'جدول gbp_sync_logs غير موجود - شغّل الـ migration'];
        }
    }

    private function checkAiAvailable(): array
    {
        try {
            $configured = defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '';
            return $configured
                ? ['status' => 'OK', 'message' => 'مزود الـ AI مضبوط']
                : ['status' => 'WARNING', 'message' => 'مزود الـ AI غير مضبوط - ميزات AI Insights/Post Generation مش هتشتغل'];
        } catch (Throwable $e) {
            return ['status' => 'WARNING', 'message' => 'تعذر فحص إعداد AI'];
        }
    }
}
