-- ============================================================
-- Tourfecto - Migration: توكنات التحديث (Refresh Tokens) لدعم JWT
-- جزء من المرحلة 2 من خطة API Gateway - مصادقة حقيقية متعددة الأجهزة
-- بدل الاعتماد الكلي على api_token واحد ثابت في جدول users (اللي مالوش
-- انتهاء صلاحية ولا تمييز بين الأجهزة). كل جهاز/تسجيل دخول بياخد
-- refresh token خاص بيه، قابل للإلغاء لوحده من غير ما يأثر على باقي
-- أجهزة نفس المستخدم.
-- @version 1.0.0  @date 2026-08-06
-- ============================================================

CREATE TABLE IF NOT EXISTS `user_refresh_tokens` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,

    -- زي partner_api_keys.key_hash بالظبط: التوكن الخام مايتخزنش أبدًا،
    -- بس الـ hash بتاعه. لو قاعدة البيانات اتسرّبت، مفيش توكنات صالحة
    -- تتسرق منها مباشرة.
    `token_hash` VARCHAR(255) NOT NULL,

    `device_name` VARCHAR(190) DEFAULT NULL COMMENT 'اسم يوضحه العميل وقت تسجيل الدخول (مثال: iPhone 15 - تطبيق Tourfecto)',
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,

    `expires_at` TIMESTAMP NOT NULL,
    `revoked_at` TIMESTAMP NULL DEFAULT NULL,
    `last_used_at` TIMESTAMP NULL DEFAULT NULL,

    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='توكنات تحديث JWT لكل جهاز على حدة - يدعم تسجيل الخروج من جهاز واحد بدون التأثير على باقي الأجهزة';
