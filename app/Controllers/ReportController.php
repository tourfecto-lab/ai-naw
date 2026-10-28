<?php

/**
 * Tourfecto - Report Controller
 * عرض وتصدير التقارير
 * @version 1.0.0
 *
 * ملاحظة: قاعدة البيانات (database/schema.sql) لا تحتوي على جدول مخصص
 * للتقارير المجدولة (scheduled reports)، لذا دوال الجدولة هنا تُعيد استجابة
 * صريحة بأن الميزة غير مفعّلة بدلاً من التظاهر بأنها تعمل.
 */

class ReportController extends Controller
{
    /** GET /reports */
    public function index(array $params = []): array
    {
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            return $this->error('غير مسجل دخول', 401);
        }

        try {
            $sql = "SELECT * FROM ai_reports WHERE user_id = ? ORDER BY created_at DESC LIMIT 50";
            $reports = $this->db->query($sql, [$userId]);
            return $this->success(['reports' => $reports]);
        } catch (Exception $e) {
            Logger::error('Report Index Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب التقارير', 500);
        }
    }

    /** GET /reports/export و GET /api/reports/export */
    public function export(array $params = []): array
    {
        return $this->error('ميزة تصدير التقارير (PDF/Excel) غير مفعّلة بعد في هذه النسخة', 501);
    }

    /** GET /reports/scheduled */
    public function scheduled(array $params = []): array
    {
        return $this->getScheduled($params);
    }

    /** GET /api/reports/scheduled */
    public function getScheduled(array $params = []): array
    {
        return $this->success(['scheduled_reports' => []], 'لا توجد ميزة جدولة تقارير في قاعدة البيانات الحالية بعد');
    }

    /** POST /api/reports/schedule */
    public function schedule(array $params = []): array
    {
        return $this->error('ميزة جدولة التقارير غير مفعّلة بعد؛ يتطلب إضافة جدول scheduled_reports لقاعدة البيانات', 501);
    }

    /** DELETE /api/reports/schedule/{id} */
    public function deleteSchedule(array $params = []): array
    {
        return $this->error('ميزة جدولة التقارير غير مفعّلة بعد', 501);
    }
}
