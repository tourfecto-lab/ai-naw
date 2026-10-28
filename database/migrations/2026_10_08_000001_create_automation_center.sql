-- ============================================================
-- Tourfecto - مركز الأتمتة (Automation Center)
--
-- طبقة موحّدة بتتحكم في كل حاجة المنصة بتعملها لوحدها بعد الربط:
-- الرد على التقييمات، ردود الشات، إصلاحات الموقع، نشر المحتوى،
-- طلبات التقييم، تعديلات الإعلانات.
--
-- لكل خدمة العميل بيختار وضع:
--   auto     = أوتوماتيك بالكامل
--   approval = المنصة بتجهّز وهو يوافق
--   learning = بتجهّز وهو يوافق/يعدّل، والمنصة بتتعلم من تعديلاته،
--              ولما توصل لنسبة موافقة عالية بتتحول أوتوماتيك لوحدها
--   manual   = مفيش أي حاجة تلقائية
--
-- إضافة فقط (مفيش DROP/DELETE)، وينفع يتشغّل أكتر من مرة.
-- @date 2026-10-08
-- ============================================================

CREATE TABLE IF NOT EXISTS `automation_policies` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `feature_key` VARCHAR(50) NOT NULL,
    `mode` ENUM('auto','approval','learning','manual') NOT NULL DEFAULT 'auto',
    -- أوضاع خاصة لكل فئة جوه الخدمة (مثلاً: التقييمات السلبية بموافقة)
    `bucket_modes` TEXT NULL COMMENT 'JSON: {"negative":"approval"}',
    -- الفئات اللي اتخرجت من وضع التعلم واتحولت أوتوماتيك
    `graduated_buckets` TEXT NULL COMMENT 'JSON: {"positive":"2026-10-08 10:00:00"}',
    `learn_window` SMALLINT UNSIGNED NOT NULL DEFAULT 20 COMMENT 'عدد آخر قرارات بتتحسب منها النسبة',
    `learn_min_samples` SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    `learn_threshold` DECIMAL(4,2) NOT NULL DEFAULT 0.90 COMMENT 'نسبة الموافقة من غير تعديل جوهري للتخرّج',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_automation_policy` (`user_id`, `feature_key`),
    KEY `idx_automation_policy_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- كل إجراء المنصة عملته أو جهّزته (سجل + صندوق الموافقات)
CREATE TABLE IF NOT EXISTS `automation_decisions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `website_id` INT NULL,
    `feature_key` VARCHAR(50) NOT NULL,
    `bucket` VARCHAR(50) NOT NULL DEFAULT 'default',
    `subject_type` VARCHAR(50) NOT NULL,
    `subject_id` VARCHAR(100) NOT NULL,
    `mode_at_decision` ENUM('auto','approval','learning','manual') NOT NULL,
    `status` ENUM('pending','auto_executed','approved','approved_edited','rejected','executed','failed','expired','cancelled') NOT NULL DEFAULT 'pending',
    `title` VARCHAR(255) NULL,
    `context_text` TEXT NULL COMMENT 'النص اللي الإجراء بيرد عليه (مثلاً نص التقييم)',
    `draft_text` MEDIUMTEXT NULL COMMENT 'اللي المنصة جهّزته',
    `final_text` MEDIUMTEXT NULL COMMENT 'اللي اتنفّذ فعلاً بعد تعديل العميل',
    `payload` MEDIUMTEXT NULL COMMENT 'JSON: بيانات التنفيذ',
    `similarity` DECIMAL(5,4) NULL COMMENT 'قرب النص النهائي من المسودة 0..1',
    `feedback` ENUM('good','bad') NULL COMMENT 'تقييم العميل لإجراء اتعمل أوتوماتيك',
    `error_message` VARCHAR(500) NULL,
    `decided_by` INT NULL,
    `decided_at` DATETIME NULL,
    `executed_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_automation_subject` (`feature_key`, `subject_type`, `subject_id`),
    KEY `idx_automation_user_status` (`user_id`, `status`),
    KEY `idx_automation_learning` (`user_id`, `feature_key`, `bucket`, `decided_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- أمثلة أسلوب العميل: كل رد كتبه أو عدّله بنفسه بيتحفظ هنا ويتحط في
-- تعليمات الذكاء الاصطناعي، فالردود الجاية تطلع بنفس أسلوبه.
CREATE TABLE IF NOT EXISTS `automation_examples` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `feature_key` VARCHAR(50) NOT NULL,
    `bucket` VARCHAR(50) NOT NULL DEFAULT 'default',
    `source` ENUM('manual','edited','approved') NOT NULL,
    `context_text` TEXT NULL,
    `draft_text` MEDIUMTEXT NULL,
    `final_text` MEDIUMTEXT NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_automation_examples` (`user_id`, `feature_key`, `bucket`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
