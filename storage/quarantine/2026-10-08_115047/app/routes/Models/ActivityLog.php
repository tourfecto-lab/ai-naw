<?php
/**
 * Tourfecto - Activity Log Model
 * سجل نشاط موحّد لكل الموديولات (بدل 3 جداول مكررة كانت في الموديولات الأصلية)
 * @version 1.0.0
 */
class ActivityLog extends Model {
    protected $table = 'activity_logs';
    protected $fillable = [
        'user_id', 'agency_id', 'module', 'action', 'subject_type',
        'subject_id', 'meta', 'ip_address'
    ];

    /**
     * تسجيل حدث نشاط - نقطة الدخول الموحدة الوحيدة لكل الموديولات.
     * كل Controller/Service جديد يستخدم هذه الدالة بدل كتابة SQL مباشرة.
     */
    public static function record(string $module, string $action, array $data = []): void {
        try {
            $log = new self([
                'user_id'      => $data['user_id'] ?? (function_exists('current_user_id') ? current_user_id() : null),
                'agency_id'    => $data['agency_id'] ?? null,
                'module'       => $module,
                'action'       => $action,
                'subject_type' => $data['subject_type'] ?? null,
                'subject_id'   => $data['subject_id'] ?? null,
                'meta'         => isset($data['meta']) ? json_encode($data['meta'], JSON_UNESCAPED_UNICODE) : null,
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
            $log->save();
        } catch (\Throwable $e) {
            // لا نكسر أي طلب بسبب فشل تسجيل نشاط - نسجّل الخطأ فقط في Logger الحالي
            // (Logger::error() الثابتة - نفس الأسلوب المستخدم في باقي المشروع،
            // لا يوجد Logger::getInstance() في هذا الكلاس)
            if (class_exists('Logger')) {
                Logger::error('ActivityLog::record failed: ' . $e->getMessage());
            }
        }
    }
}
