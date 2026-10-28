<?php

/**
 * Tourfecto - Audit Log Model
 * سجل نشاط أمني/إعدادات لحساب المستخدم - Read-Only من الواجهة.
 * @version 1.0.0
 */

class AuditLog extends Model
{
    protected $table = 'audit_logs';

    protected $fillable = [
        'user_id',
        'action',
        'object_type',
        'object_id',
        'result',
        'meta',
        'ip_address',
        'user_agent',
    ];

    /**
     * تسجيل حدث - نداء "fire and forget": لو فشل التسجيل لأي سبب،
     * منمنعش العملية الأصلية (تغيير الباسورد مثلًا) من إنها تنجح.
     * السجل نفسه مش أهم من العملية اللي بيوثّقها.
     */
    public static function record(int $userId, string $action, string $result = 'success', ?string $objectType = null, ?string $objectId = null, array $meta = []): void
    {
        try {
            $log = new self([
                'user_id' => $userId,
                'action' => $action,
                'object_type' => $objectType,
                'object_id' => $objectId,
                'result' => $result,
                'meta' => empty($meta) ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
            ]);
            $log->save();
        } catch (\Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('AuditLog::record failed: ' . $e->getMessage());
            }
        }

        self::dispatchEvent($userId, $action, $result, $objectType, $objectId, $meta);
    }

    /**
     * يطلق AppEvent حقيقي عبر EventDispatcher الموجود بالفعل في المشروع
     * (app/Core/Events/*) - Phase 11. طبقة Container/EventDispatcher كانت
     * جاهزة تمامًا لكن دالة event() المختصرة نفسها ما كانتش بتتحمّل
     * (تم إصلاح ذلك في public_html/index.php). أي حدث حقيقي بيتسجّل هنا
     * في audit_logs (تغيير باسورد، إنشاء مفتاح API، دعوة عضو فريق...)
     * بقى بيطلق حدث فعلي كمان يقدر أي جزء تاني "يستمع" له من غير ما
     * يعدّل الكود ده أصلًا.
     *
     * بنطلق الحدث بس لما result = 'success' - محاولات فاشلة بتتسجّل في
     * audit_logs للمراجعة، لكن مش المفروض تُعتبر "حدث حصل فعلًا".
     */
    private static function dispatchEvent(int $userId, string $action, string $result, ?string $objectType, ?string $objectId, array $meta): void
    {
        if ($result !== 'success' || !function_exists('event')) {
            return;
        }

        try {
            event('user.' . $action, [
                'user_id' => $userId,
                'object_type' => $objectType,
                'object_id' => $objectId,
                'meta' => $meta,
            ]);
        } catch (\Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('AuditLog event dispatch failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * قائمة مُصفّاة ومقسّمة صفحات لسجل مستخدم معيّن - مش أكتر من 100
     * صف في الاستدعاء الواحد (Performance - spec section 22).
     *
     * @return array{rows: array, total: int}
     */
    public static function listFor(int $userId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $db = Database::getInstance();

        $where = ['user_id = :user_id'];
        $params = [':user_id' => $userId];

        if (!empty($filters['action'])) {
            $where[] = 'action = :action';
            $params[':action'] = $filters['action'];
        }
        if (!empty($filters['result']) && in_array($filters['result'], ['success', 'failed'], true)) {
            $where[] = 'result = :result';
            $params[':result'] = $filters['result'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(action LIKE :search OR object_type LIKE :search OR object_id LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['from'])) {
            $where[] = 'created_at >= :from';
            $params[':from'] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 'created_at <= :to';
            $params[':to'] = $filters['to'] . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);

        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $countRow = $db->query("SELECT COUNT(*) AS c FROM `audit_logs` WHERE {$whereSql}", $params);
        $total = (int) ($countRow[0]['c'] ?? 0);

        $rows = $db->query(
            "SELECT `action`, `object_type`, `object_id`, `result`, `created_at` FROM `audit_logs` WHERE {$whereSql} ORDER BY `created_at` DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return ['rows' => $rows ?: [], 'total' => $total];
    }

    /**
     * كل الصفوف المطابقة للفلاتر بدون ترقيم صفحات - للتصدير (CSV).
     * الحد الأقصى لكل طلب 5000 صف، لكن بدعم `offset` عشان الـ frontend
     * يقدر يجيب الـ CSV على دفعات (Phase 16D) لو الصفوف أكتر من 5000 -
     * بنمشي على الـ offset بدل ما نحمّل السيرفر بـ limit ضخم.
     *
     * @return array مصفوفة من الصفوف اللي لسه فاضية من الفلاتر
     */
    public static function exportFor(int $userId, array $filters = [], int $maxRows = 5000, int $offset = 0): array
    {
        $db = Database::getInstance();

        $where = ['user_id = :user_id'];
        $params = [':user_id' => $userId];

        if (!empty($filters['action'])) {
            $where[] = 'action = :action';
            $params[':action'] = $filters['action'];
        }
        if (!empty($filters['result']) && in_array($filters['result'], ['success', 'failed'], true)) {
            $where[] = 'result = :result';
            $params[':result'] = $filters['result'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(action LIKE :search OR object_type LIKE :search OR object_id LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['from'])) {
            $where[] = 'created_at >= :from';
            $params[':from'] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 'created_at <= :to';
            $params[':to'] = $filters['to'] . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);
        $maxRows = max(1, min(5000, (int) $maxRows));
        $offset = max(0, (int) $offset);

        return $db->query(
            "SELECT `action`, `object_type`, `object_id`, `result`, `meta`, `ip_address`, `created_at` FROM `audit_logs` WHERE {$whereSql} ORDER BY `created_at` DESC LIMIT {$maxRows} OFFSET {$offset}",
            $params
        ) ?: [];
    }

    /**
     * إجمالي عدد الصفوف المطابقة لنفس فلاتر exportFor - بيسمح للتصدير
     * يدور على الدفعات لحد ما يوصل للنهاية (Pagination beyond 5000).
     */
    public static function countFor(int $userId, array $filters = []): int
    {
        $db = Database::getInstance();

        $where = ['user_id = :user_id'];
        $params = [':user_id' => $userId];

        if (!empty($filters['action'])) {
            $where[] = 'action = :action';
            $params[':action'] = $filters['action'];
        }
        if (!empty($filters['result']) && in_array($filters['result'], ['success', 'failed'], true)) {
            $where[] = 'result = :result';
            $params[':result'] = $filters['result'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(action LIKE :search OR object_type LIKE :search OR object_id LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['from'])) {
            $where[] = 'created_at >= :from';
            $params[':from'] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 'created_at <= :to';
            $params[':to'] = $filters['to'] . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);
        $row = $db->query("SELECT COUNT(*) AS c FROM `audit_logs` WHERE {$whereSql}", $params);

        return (int) (($row[0]['c'] ?? $row['c'] ?? 0) ?: 0);
    }
}
