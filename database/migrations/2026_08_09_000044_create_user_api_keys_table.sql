-- ============================================================
-- Tourfecto - Migration: مفاتيح API شخصية للمستخدم (Personal API Keys)
-- جزء من Settings Center - قسم API & Integrations (Phase 3).
--
-- ده منفصل تمامًا عن `users.api_token` الحالي (اللي بيُستخدم داخليًا
-- لمصادقة الكوكي/الجلسة - عمود واحد بس، بيتغيّر بالكامل مع كل
-- "Regenerate"، ومفيهوش تعدد أجهزة ولا اسم/تتبع لكل مفتاح).
-- هنا المستخدم يقدر يطلع أكتر من مفتاح مسمّى (مثلًا: "Zapier"،
-- "n8n workflow")، كل واحد يتلغى لوحده من غير ما يأثر على الباقي أو
-- على تسجيل دخوله العادي بالموقع - بالظبط نفس فلسفة partner_api_keys
-- الموجودة بالفعل، بس على مستوى المستخدم مش الشريك الخارجي.
-- @version 1.0.0  @date 2026-08-09
-- ============================================================

CREATE TABLE IF NOT EXISTS `user_api_keys` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,

    `name` VARCHAR(120) NOT NULL COMMENT 'اسم يختاره المستخدم للمفتاح (مثال: Zapier, n8n)',

    -- نفس مبدأ partner_api_keys بالظبط: المفتاح الخام ميتخزنش أبدًا،
    -- بيتعرض مرة واحدة فقط وقت الإنشاء.
    `key_prefix` VARCHAR(16) NOT NULL COMMENT 'أول جزء من المفتاح - للتمييز في القائمة بدون كشف المفتاح كامل',
    `key_hash` VARCHAR(255) NOT NULL COMMENT 'password_hash() للمفتاح الكامل',

    `last_used_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `revoked_at` TIMESTAMP NULL DEFAULT NULL,

    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_key_prefix` (`key_prefix`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='مفاتيح API شخصية للمستخدم - منفصلة عن users.api_token الخاص بجلسة الموقع';
