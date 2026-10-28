<?php

/**
 * Tourfecto - Feature Flag Service
 * التحكم في إتاحة الميزات - إما للموقع كله، أو استثناء لعميل معيّن
 * بالذات (أعلى أولوية من الإعداد العام). بيتفحص مركزيًا في
 * Controller::renderPanelPage() فبيغطي كل صفحات اللوحة تلقائيًا.
 * @version 1.0.0
 */
class FeatureFlagService
{
    /** @var Database */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * هل الميزة متاحة لمستخدم معيّن؟ الاستثناء الشخصي (لو موجود) بياخد
     * أولوية دايمًا فوق الإعداد العام. آمن 100% حتى لو الجداول مش
     * موجودة بعد - بيرجع "متاحة" افتراضيًا (مش بيمنع حاجة بالغلط).
     */
    /**
     * فحص مجمّع لكل الميزات دفعة واحدة (استعلامين بس مش استعلام لكل
     * عنصر) - بيرجع map: feature_key => bool. مناسب لبناء القائمة
     * الجانبية اللي فيها 20+ عنصر في كل تحميل صفحة.
     */
    public function getEnabledMap(int $userId): array
    {
        $map = [];
        try {
            $globals = $this->db->query("SELECT feature_key, is_enabled FROM feature_flags");
            foreach ($globals as $row) {
                $map[$row['feature_key']] = (bool) $row['is_enabled'];
            }

            $overrides = $this->db->query(
                "SELECT feature_key, is_enabled FROM user_feature_overrides WHERE user_id = ?",
                [$userId]
            );
            foreach ($overrides as $row) {
                $map[$row['feature_key']] = (bool) $row['is_enabled']; // الاستثناء بيغلب الإعداد العام
            }
        } catch (Exception $e) {
            // فشل الفحص - نرجع map فاضي، يعني كل الميزات هتتعامل كـ "متاحة" افتراضيًا
        }
        return $map;
    }

    public function isEnabled(string $featureKey, int $userId): bool
    {
        try {
            $override = $this->db->query(
                "SELECT is_enabled FROM user_feature_overrides WHERE user_id = ? AND feature_key = ? LIMIT 1",
                [$userId, $featureKey]
            );
            if (!empty($override)) {
                return (bool) $override[0]['is_enabled'];
            }

            $global = $this->db->query(
                "SELECT is_enabled FROM feature_flags WHERE feature_key = ? LIMIT 1",
                [$featureKey]
            );
            if (!empty($global)) {
                return (bool) $global[0]['is_enabled'];
            }

            return true; // ميزة مش مسجّلة في النظام أصلاً - افتراضي متاحة
        } catch (Exception $e) {
            return true; // فشل الفحص - منمنعش حاجة بالغلط بسبب مشكلة تقنية
        }
    }

    /** كل الميزات وحالتها العامة - لعرضها في لوحة الأدمن */
    public function getAllGlobal(): array
    {
        try {
            return $this->db->query("SELECT * FROM feature_flags ORDER BY label ASC");
        } catch (Exception $e) {
            return [];
        }
    }

    /** تحديث حالة ميزة عامة (للموقع كله) */
    public function setGlobal(string $featureKey, bool $isEnabled): void
    {
        $this->db->exec(
            "UPDATE feature_flags SET is_enabled = ? WHERE feature_key = ?",
            [$isEnabled ? 1 : 0, $featureKey]
        );
    }

    /** كل استثناءات عميل معيّن */
    public function getUserOverrides(int $userId): array
    {
        try {
            return $this->db->query("SELECT * FROM user_feature_overrides WHERE user_id = ?", [$userId]);
        } catch (Exception $e) {
            return [];
        }
    }

    /** إضافة/تحديث استثناء لعميل معيّن */
    public function setUserOverride(int $userId, string $featureKey, bool $isEnabled, string $note = ''): void
    {
        $this->db->exec(
            "INSERT INTO user_feature_overrides (user_id, feature_key, is_enabled, note) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), note = VALUES(note)",
            [$userId, $featureKey, $isEnabled ? 1 : 0, $note]
        );
    }

    /** حذف استثناء عميل (يرجع للإعداد العام تاني) */
    public function removeUserOverride(int $userId, string $featureKey): void
    {
        $this->db->exec(
            "DELETE FROM user_feature_overrides WHERE user_id = ? AND feature_key = ?",
            [$userId, $featureKey]
        );
    }
}
