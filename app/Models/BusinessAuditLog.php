<?php

/**
 * Tourfecto - Business Audit Log Model
 * Centralized Business Audit Log - Business Control Center Phase 13-14
 * @version 1.0.0
 *
 * سجل موحّد لكل حدث على مستوى الـBusiness (تعديل بيانات/خدمات/فريق/
 * مفاتيح API...). بيكمّل AuditLog الموجود على مستوى المستخدم - ده
 * بيكمّل صورة "مين عمل إيه في الـBusiness" للمالك والـadmin.
 *
 * تصميم: Fire-and-forget في التسجيل (لو فشل التسجيل منمنعش العملية
 * الأصلية) - نفس فلسفة AuditLog::record(). القراءة تتم بفلترة وصفحات
 * زي AuditLog::listFor() بالظبط، لكن بمفتاح business_id.
 */

class BusinessAuditLog extends Model
{
    protected $table = 'business_audit_logs';

    protected $fillable = [
        'business_id',
        'actor_user_id',
        'action',
        'object_type',
        'object_id',
        'result',
        'meta',
        'ip_address',
        'user_agent',
    ];

    /**
     * تسجيل حدث على مستوى الـBusiness - fire and forget: لو فشل
     * التسجيل لأي سبب، منمنعش العملية الأصلية من إنها تنجح.
     */
    public static function record(
        int $businessId,
        int $actorUserId,
        string $action,
        string $result = 'success',
        ?string $objectType = null,
        ?string $objectId = null,
        array $meta = []
    ): void {
        try {
            $log = new self([
                'business_id' => $businessId,
                'actor_user_id' => $actorUserId,
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
                Logger::error('BusinessAuditLog::record failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * قائمة مُصفّاة ومقسّمة صفحات لسجل Business معيّن - مش أكتر من 100
     * صف في الاستدعاء الواحد.
     *
     * @return array{rows: array, total: int}
     */
    public static function listFor(int $businessId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $db = Database::getInstance();

        $where = ['business_id = :business_id'];
        $params = [':business_id' => $businessId];

        if (!empty($filters['action'])) {
            $where[] = 'action = :action';
            $params[':action'] = $filters['action'];
        }
        if (!empty($filters['actor_user_id'])) {
            $where[] = 'actor_user_id = :actor_user_id';
            $params[':actor_user_id'] = (int) $filters['actor_user_id'];
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

        $countRow = $db->query("SELECT COUNT(*) AS c FROM `business_audit_logs` WHERE {$whereSql}", $params);
        $total = (int) ($countRow[0]['c'] ?? 0);

        $rows = $db->query(
            "SELECT `id`, `action`, `object_type`, `object_id`, `result`, `meta`, `actor_user_id`, `ip_address`, `created_at` FROM `business_audit_logs` WHERE {$whereSql} ORDER BY `created_at` DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        // فك تشفير meta (JSON) لكل صف + إضافة اسم الفاعل إن أمكن
        $decodedRows = [];
        foreach (($rows ?: []) as $row) {
            $decoded = $row;
            if (!empty($decoded['meta'])) {
                $decodedMeta = json_decode((string) $decoded['meta'], true);
                $decoded['meta'] = is_array($decodedMeta) ? $decodedMeta : null;
            }
            $decodedRows[] = $decoded;
        }

        return ['rows' => $decodedRows, 'total' => $total];
    }
}
