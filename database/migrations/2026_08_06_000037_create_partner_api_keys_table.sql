-- ============================================================
-- Tourfecto - Migration: مفاتيح API الخاصة بالشركاء الخارجيين (Partners)
-- بداية "المرحلة 2" من خطة API Gateway: فصل مصادقة الشركاء الخارجيين
-- (Partner API) تمامًا عن مصادقة المستخدم العادي (Session/api_token
-- الحالي في جدول users، وده مصمم لتطبيق الموبايل/الويب بتاع المستخدم
-- نفسه بس). الشريك الخارجي مش مستخدم عنده حساب في المنصة، فمحتاج نظام
-- مستقل: مفتاح + سر، صلاحيات محدودة (scopes)، وحد أقصى لمعدل الطلبات
-- خاص بيه لوحده - قابل للإلغاء فورًا من لوحة الأدمن من غير ما يأثر على
-- أي مستخدم تاني.
-- @version 1.0.0  @date 2026-08-06
-- ============================================================

CREATE TABLE IF NOT EXISTS `partner_api_keys` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `partner_name` VARCHAR(120) NOT NULL COMMENT 'اسم الشريك/الجهة (يظهر في لوحة الأدمن ولوجات الاستخدام)',
    `contact_email` VARCHAR(190) DEFAULT NULL,

    -- المفتاح الفعلي مش بيتخزّن أبدًا بشكل واضح (نفس مبدأ تخزين الباسورد):
    -- بنخزّن hash بس، ونعرض المفتاح الخام مرة واحدة فقط وقت الإنشاء.
    `key_prefix` VARCHAR(12) NOT NULL COMMENT 'أول 8 حروف من المفتاح - يظهر في لوحة الأدمن للتمييز بدون كشف المفتاح كامل',
    `key_hash` VARCHAR(255) NOT NULL COMMENT 'password_hash() للمفتاح الكامل',

    -- صلاحيات محدودة (Scopes): بيوضح بالظبط الشريك مسموحله يوصل لإيه.
    -- مخزّنة JSON عشان تتوسع بسهولة من غير migration جديدة كل مرة.
    -- مثال: ["reputation:read", "reviews:read"]
    `scopes` TEXT NOT NULL COMMENT 'JSON array من الصلاحيات المسموحة',

    `rate_limit_per_minute` INT(11) NOT NULL DEFAULT 60,
    `status` ENUM('active', 'revoked') NOT NULL DEFAULT 'active',

    `last_used_at` TIMESTAMP NULL DEFAULT NULL,
    `last_used_ip` VARCHAR(45) DEFAULT NULL,
    `created_by_admin_id` INT(11) DEFAULT NULL COMMENT 'أدمن أنشأ المفتاح - للتتبع',

    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `revoked_at` TIMESTAMP NULL DEFAULT NULL,

    PRIMARY KEY (`id`),
    KEY `idx_key_prefix` (`key_prefix`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='مفاتيح API لشركاء خارجيين (Partner API) - منفصلة تمامًا عن جلسات/توكنات المستخدمين';
