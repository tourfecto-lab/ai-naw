<?php

/**
 * Tourfecto - Health Controller
 * فحص حالة الموقع وقاعدة البيانات (Health Check)
 * @version 1.0.0
 */

class HealthController extends Controller
{
    public function __construct()
    {
        $this->parseInput();
        $this->loadAuthenticatedUser();
        $this->db = null;
    }

    private function connectDatabase(): void
    {
        try { $this->db = Database::getInstance(); } catch (Throwable $e) { $this->db = null; }
    }

    /** GET /ping و GET /api/ping */
    public function ping(array $params = []): array
    {
        return $this->success(['pong' => true, 'time' => date('c')]);
    }

    /** GET /health */
    public function webCheck(array $params = []): array
    {
        return $this->check($params);
    }

    /** GET /api/health */
    public function check(array $params = []): array
    {
        $this->connectDatabase();
        $dbOk = false;
        try {
            $dbOk = $this->db !== null && $this->db->isConnected();
        } catch (Throwable $e) {
            $this->connectDatabase();
        $dbOk = false;
        }

        $healthy = $dbOk;

        return [
            'success' => $healthy,
            'message' => $healthy ? 'الخدمة تعمل بشكل طبيعي' : 'هناك مشكلة في أحد الخدمات',
            'data' => [
                'database' => $dbOk ? 'ok' : 'down',
                'php_version' => phpversion(),
                'time' => date('c'),
            ],
            'code' => $healthy ? 200 : 503,
        ];
    }

    /** GET /api/health/detailed (يتطلب تسجيل دخول) */
    public function detailed(array $params = []): array
    {
        if (!$this->isAuthenticated()) { return $this->error('Unauthorized', 401); }
        $this->connectDatabase();
        $dbOk = false;
        $dbStats = [];
        try {
            $dbOk = $this->db !== null && $this->db->isConnected();
            $dbStats = $this->db->getQueryStats();
        } catch (Throwable $e) {
            $this->connectDatabase();
        $dbOk = false;
        }

        $operations = [];
        if ($dbOk) {
            $id = (int) ($this->user['id'] ?? 0);
            $row = $this->db->query('SELECT owner_user_id, role FROM users WHERE id = ? LIMIT 1', [$id])[0] ?? null;
            if (!$row) { return $this->error('Unable to verify scope', 503); }
            $operations = RuntimeHealthService::snapshot($this->db, (int) ($row['owner_user_id'] ?: $id), in_array(($row['role'] ?? ''), ['admin', 'super_admin'], true));
        }
        return $this->success([
            'operations' => $operations,
            'database' => ['connected' => $dbOk, 'stats' => $dbStats],
            'memory_usage' => memory_get_usage(true),
            'php_version' => phpversion(),
            'app_env' => defined('APP_ENV') ? APP_ENV : 'unknown',
            'time' => date('c'),
        ]);
    }
}
