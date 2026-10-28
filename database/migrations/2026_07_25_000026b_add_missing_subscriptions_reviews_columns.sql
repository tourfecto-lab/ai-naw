-- ============================================================
-- Tourfecto - Migration: أعمدة ناقصة من subscriptions و reviews
-- @version 1.0.0  @date 2026-07-25 (تصحيح بأثر رجعي)
--
-- 🐛 باگ حقيقي اتلقى أثناء اختبار الداشبورد على تنصيب فاضي - /api/dashboard/stats
-- كان بيرجّع 200 لكن بيسجّل 3 أخطاء SQL صامتة في اللوج (الكود بيمسكهم
-- بـ try/catch ويرجع قيم افتراضية بدل ما يوقف الصفحة، فالمستخدم عمره ما
-- كان هيلاحظ إن بياناته غلط):
--   - subscriptions.plan_id / usage_ai_analysis_count / usage_ai_message_count
--     / usage_review_reply_count مش موجودين (Subscription::activeSubscriptionRow)
--   - reviews.ai_generated_reply مش موجود (DashboardController::getStats)
--
-- app/Models/Subscription.php و app/Models/Review.php فيهم تعليقات
-- توثيقية صريحة بتقول إن الجدول الحقيقي على السيرفر (اتفحص فعليًا من
-- phpMyAdmin) مختلف عن ملفات الـmigration الأساسية القديمة (نفس النمط
-- اللي حصل مع users و subscription_plans). بنضيف هنا بالظبط الأعمدة
-- الموثّقة في تعليقات الموديلات دي، من غير ما نمسح القديمة (توافق رجعي).
-- ============================================================

-- ---------- subscriptions: التصميم الجديد (plan_id -> subscription_plans + عدادات استخدام) ----------
SET @__fx_col_1_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'plan_id'
);
SET @__fx_col_1_sql := IF(@__fx_col_1_exists = 0,
    'ALTER TABLE `subscriptions` ADD COLUMN `plan_id` INT(11) NULL DEFAULT NULL COMMENT ''مفتاح خارجي لـ subscription_plans - التصميم الجديد بدل plan_name/plan_type المباشرين'' AFTER `user_id`',
    'SELECT 1');
PREPARE __fx_col_1_stmt FROM @__fx_col_1_sql;
EXECUTE __fx_col_1_stmt;
DEALLOCATE PREPARE __fx_col_1_stmt;

SET @__fx_col_2_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'trial_ends_at'
);
SET @__fx_col_2_sql := IF(@__fx_col_2_exists = 0,
    'ALTER TABLE `subscriptions` ADD COLUMN `trial_ends_at` TIMESTAMP NULL DEFAULT NULL AFTER `expiry_date`',
    'SELECT 1');
PREPARE __fx_col_2_stmt FROM @__fx_col_2_sql;
EXECUTE __fx_col_2_stmt;
DEALLOCATE PREPARE __fx_col_2_stmt;

SET @__fx_col_3_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'current_period_start'
);
SET @__fx_col_3_sql := IF(@__fx_col_3_exists = 0,
    'ALTER TABLE `subscriptions` ADD COLUMN `current_period_start` TIMESTAMP NULL DEFAULT NULL COMMENT ''نمط Stripe قياسي - بديل start_date'' AFTER `trial_ends_at`',
    'SELECT 1');
PREPARE __fx_col_3_stmt FROM @__fx_col_3_sql;
EXECUTE __fx_col_3_stmt;
DEALLOCATE PREPARE __fx_col_3_stmt;

SET @__fx_col_4_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'current_period_end'
);
SET @__fx_col_4_sql := IF(@__fx_col_4_exists = 0,
    'ALTER TABLE `subscriptions` ADD COLUMN `current_period_end` TIMESTAMP NULL DEFAULT NULL COMMENT ''نمط Stripe قياسي - بديل expiry_date'' AFTER `current_period_start`',
    'SELECT 1');
PREPARE __fx_col_4_stmt FROM @__fx_col_4_sql;
EXECUTE __fx_col_4_stmt;
DEALLOCATE PREPARE __fx_col_4_stmt;

SET @__fx_col_5_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'cancel_at_period_end'
);
SET @__fx_col_5_sql := IF(@__fx_col_5_exists = 0,
    'ALTER TABLE `subscriptions` ADD COLUMN `cancel_at_period_end` TINYINT(1) NOT NULL DEFAULT 0 AFTER `cancellation_reason`',
    'SELECT 1');
PREPARE __fx_col_5_stmt FROM @__fx_col_5_sql;
EXECUTE __fx_col_5_stmt;
DEALLOCATE PREPARE __fx_col_5_stmt;

SET @__fx_col_6_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'gateway_subscription_id'
);
SET @__fx_col_6_sql := IF(@__fx_col_6_exists = 0,
    'ALTER TABLE `subscriptions` ADD COLUMN `gateway_subscription_id` VARCHAR(255) NULL DEFAULT NULL AFTER `payment_gateway`',
    'SELECT 1');
PREPARE __fx_col_6_stmt FROM @__fx_col_6_sql;
EXECUTE __fx_col_6_stmt;
DEALLOCATE PREPARE __fx_col_6_stmt;

SET @__fx_col_7_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'gateway_customer_id'
);
SET @__fx_col_7_sql := IF(@__fx_col_7_exists = 0,
    'ALTER TABLE `subscriptions` ADD COLUMN `gateway_customer_id` VARCHAR(255) NULL DEFAULT NULL AFTER `gateway_subscription_id`',
    'SELECT 1');
PREPARE __fx_col_7_stmt FROM @__fx_col_7_sql;
EXECUTE __fx_col_7_stmt;
DEALLOCATE PREPARE __fx_col_7_stmt;

SET @__fx_col_8_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'usage_ai_analysis_count'
);
SET @__fx_col_8_sql := IF(@__fx_col_8_exists = 0,
    'ALTER TABLE `subscriptions` ADD COLUMN `usage_ai_analysis_count` INT(11) NOT NULL DEFAULT 0 COMMENT ''عدّاد استخدام فعلي - بديل ai_credits_used في التصميم الجديد'' AFTER `ai_credits_used`',
    'SELECT 1');
PREPARE __fx_col_8_stmt FROM @__fx_col_8_sql;
EXECUTE __fx_col_8_stmt;
DEALLOCATE PREPARE __fx_col_8_stmt;

SET @__fx_col_9_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'usage_ai_message_count'
);
SET @__fx_col_9_sql := IF(@__fx_col_9_exists = 0,
    'ALTER TABLE `subscriptions` ADD COLUMN `usage_ai_message_count` INT(11) NOT NULL DEFAULT 0 AFTER `chat_credits_used`',
    'SELECT 1');
PREPARE __fx_col_9_stmt FROM @__fx_col_9_sql;
EXECUTE __fx_col_9_stmt;
DEALLOCATE PREPARE __fx_col_9_stmt;

SET @__fx_col_10_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'usage_review_reply_count'
);
SET @__fx_col_10_sql := IF(@__fx_col_10_exists = 0,
    'ALTER TABLE `subscriptions` ADD COLUMN `usage_review_reply_count` INT(11) NOT NULL DEFAULT 0 AFTER `review_credits_used`',
    'SELECT 1');
PREPARE __fx_col_10_stmt FROM @__fx_col_10_sql;
EXECUTE __fx_col_10_stmt;
DEALLOCATE PREPARE __fx_col_10_stmt;

SET @__fx_col_11_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'last_usage_reset_at'
);
SET @__fx_col_11_sql := IF(@__fx_col_11_exists = 0,
    'ALTER TABLE `subscriptions` ADD COLUMN `last_usage_reset_at` TIMESTAMP NULL DEFAULT NULL AFTER `usage_review_reply_count`',
    'SELECT 1');
PREPARE __fx_col_11_stmt FROM @__fx_col_11_sql;
EXECUTE __fx_col_11_stmt;
DEALLOCATE PREPARE __fx_col_11_stmt;

-- status enum محتاج قيمتين إضافيتين (trialing, past_due) - Subscription::activeSubscriptionRow
-- بيقرا منهم فعليًا (Phase 16 - Subscription Lifecycle) لكن القيم دي مش
-- موجودة في enum الأساسي، فأي محاولة تحديث حالة اشتراك لـ trialing أو
-- past_due كانت هتفشل بصمت أو تتحول لأقرب قيمة غلط.
ALTER TABLE `subscriptions`
    MODIFY COLUMN `status` ENUM('active','expired','cancelled','pending','trialing','past_due') NOT NULL DEFAULT 'pending';

-- ---------- reviews: التصميم الجديد (أسماء أعمدة مطابقة لتعليق Review.php) ----------
SET @__fx_col_1_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reviews' AND COLUMN_NAME = 'source_platform'
);
SET @__fx_col_1_sql := IF(@__fx_col_1_exists = 0,
    'ALTER TABLE `reviews` ADD COLUMN `source_platform` ENUM(''tripadvisor'',''google_business'',''booking'',''expedia'',''trustpilot'',''other'') NULL DEFAULT NULL COMMENT ''بديل platform - الاسم اللي بيستخدمه الكود الحالي'' AFTER `platform`',
    'SELECT 1');
PREPARE __fx_col_1_stmt FROM @__fx_col_1_sql;
EXECUTE __fx_col_1_stmt;
DEALLOCATE PREPARE __fx_col_1_stmt;

SET @__fx_col_2_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reviews' AND COLUMN_NAME = 'external_review_id'
);
SET @__fx_col_2_sql := IF(@__fx_col_2_exists = 0,
    'ALTER TABLE `reviews` ADD COLUMN `external_review_id` VARCHAR(255) NULL DEFAULT NULL COMMENT ''بديل platform_review_id'' AFTER `source_platform`',
    'SELECT 1');
PREPARE __fx_col_2_stmt FROM @__fx_col_2_sql;
EXECUTE __fx_col_2_stmt;
DEALLOCATE PREPARE __fx_col_2_stmt;

SET @__fx_col_3_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reviews' AND COLUMN_NAME = 'sentiment'
);
SET @__fx_col_3_sql := IF(@__fx_col_3_exists = 0,
    'ALTER TABLE `reviews` ADD COLUMN `sentiment` ENUM(''positive'',''neutral'',''negative'',''mixed'') NULL DEFAULT NULL COMMENT ''بديل sentiment_label - وفيها قيمة mixed إضافية'' AFTER `sentiment_label`',
    'SELECT 1');
PREPARE __fx_col_3_stmt FROM @__fx_col_3_sql;
EXECUTE __fx_col_3_stmt;
DEALLOCATE PREPARE __fx_col_3_stmt;

SET @__fx_col_4_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reviews' AND COLUMN_NAME = 'ai_generated_reply'
);
SET @__fx_col_4_sql := IF(@__fx_col_4_exists = 0,
    'ALTER TABLE `reviews` ADD COLUMN `ai_generated_reply` TEXT NULL DEFAULT NULL COMMENT ''بديل auto_reply_generated'' AFTER `sentiment_confidence`',
    'SELECT 1');
PREPARE __fx_col_4_stmt FROM @__fx_col_4_sql;
EXECUTE __fx_col_4_stmt;
DEALLOCATE PREPARE __fx_col_4_stmt;

SET @__fx_col_5_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reviews' AND COLUMN_NAME = 'reply_status'
);
SET @__fx_col_5_sql := IF(@__fx_col_5_exists = 0,
    'ALTER TABLE `reviews` ADD COLUMN `reply_status` ENUM(''pending'',''approved'',''rejected'',''sent'',''auto_sent'') NULL DEFAULT NULL COMMENT ''بديل reply_sent (tinyint) - حالة أدق للرد'' AFTER `ai_generated_reply`',
    'SELECT 1');
PREPARE __fx_col_5_stmt FROM @__fx_col_5_sql;
EXECUTE __fx_col_5_stmt;
DEALLOCATE PREPARE __fx_col_5_stmt;

-- مزامنة القيم القديمة مع الأعمدة الجديدة لأي صف موجود بالفعل قبل الميجريشن دي.
UPDATE `reviews` SET `source_platform` = `platform` WHERE `source_platform` IS NULL;
UPDATE `reviews` SET `external_review_id` = `platform_review_id` WHERE `external_review_id` IS NULL;
UPDATE `reviews` SET `sentiment` = `sentiment_label` WHERE `sentiment` IS NULL;
UPDATE `reviews` SET `ai_generated_reply` = `auto_reply_generated` WHERE `ai_generated_reply` IS NULL;
UPDATE `reviews` SET `reply_status` = IF(`reply_sent` = 1, 'sent', 'pending') WHERE `reply_status` IS NULL;
