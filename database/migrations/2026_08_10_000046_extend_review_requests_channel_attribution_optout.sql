-- ============================================================
-- Tourfecto - Migration: توسيع نظام طلب المراجعات
-- (Review Request Extension - Batch 2026-08-10)
--
-- توسيع فقط، بدون أي DROP/TRUNCATE أو حذف بيانات موجودة:
--   1) دعم قناة الإيميل بجانب واتساب (channel + guest_email) على
--      review_requests، باستخدام EmailChannelAPI/Mailer الموجودين
--      فعليًا في المشروع (app/Services/Chat/EmailChannelAPI.php).
--   2) Attribution حقيقي: ربط الطلب بالـ review الفعلي (reviews.id)
--      اللي طابقه، بدل الاكتفاء بتغيير status بس.
--   3) جدول Opt-Out/Do-Not-Contact مستقل - عشان "الإلغاء" يمنع أي
--      طلب مراجعة جديد لنفس الضيف مستقبلاً، مش بس يلغي الطلب الحالي.
-- @version 1.0.0  @date 2026-08-10
-- ============================================================

-- 1) قناة الإرسال + إيميل الضيف (اختياري، بجانب رقم الواتساب)
ALTER TABLE `review_requests`
    ADD COLUMN `channel` ENUM('whatsapp', 'email') NOT NULL DEFAULT 'whatsapp'
        COMMENT 'قناة الإرسال الفعلية المستخدمة لهذا الطلب' AFTER `guest_phone`,
    ADD COLUMN `guest_email` VARCHAR(190) DEFAULT NULL
        COMMENT 'إيميل الضيف - مطلوب لو channel = email' AFTER `channel`;

-- guest_phone كان NOT NULL من الميجريشن الأصلي؛ بما إننا بندعم دلوقتي
-- طلب بقناة إيميل بس (من غير رقم واتساب)، لازم يبقى قابل يكون فاضي.
ALTER TABLE `review_requests`
    MODIFY COLUMN `guest_phone` VARCHAR(30) NULL DEFAULT NULL
        COMMENT 'رقم واتساب بصيغة دولية (بدون +) - مطلوب لو channel = whatsapp';

-- 2) Attribution حقيقي مع جدول reviews الموجود فعليًا
ALTER TABLE `review_requests`
    ADD COLUMN `matched_review_id` INT(11) DEFAULT NULL
        COMMENT 'يشير لـ reviews.id الفعلي اللي طابق هذا الطلب (Attribution حقيقي)' AFTER `crm_deal_id`,
    ADD COLUMN `reviewed_at` DATETIME DEFAULT NULL
        COMMENT 'وقت اكتشاف تطابق المراجعة فعليًا (لحساب Time-to-Review)' AFTER `matched_review_id`,
    ADD CONSTRAINT `fk_review_requests_matched_review`
        FOREIGN KEY (`matched_review_id`) REFERENCES `reviews`(`id`) ON DELETE SET NULL;

-- فهارس لفحص التكرار (Duplicate Protection) بسرعة قبل إنشاء أي طلب جديد
CREATE INDEX `idx_rr_dup_phone` ON `review_requests` (`website_id`, `guest_phone`, `created_at`);
CREATE INDEX `idx_rr_dup_email` ON `review_requests` (`website_id`, `guest_email`, `created_at`);
CREATE INDEX `idx_rr_matched_review` ON `review_requests` (`matched_review_id`);

-- 3) عنوان رسالة الإيميل (بجانب message_template الموجود لواتساب)
ALTER TABLE `review_request_settings`
    ADD COLUMN `email_subject` VARCHAR(190) DEFAULT NULL
        COMMENT 'عنوان رسالة الإيميل لو channel = email - يدعم {name}' AFTER `message_template`;

-- 4) Opt-Out / Do-Not-Contact مستقل عن الطلبات - بيتفحص قبل أي إنشاء
-- طلب جديد (يدوي أو تلقائي من CRM)، عشان نضمن إننا مش هنبعت لضيف
-- طلب "إلغاء" هو أصلاً.
CREATE TABLE IF NOT EXISTS `review_request_opt_outs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `guest_phone` VARCHAR(30) DEFAULT NULL,
    `guest_email` VARCHAR(190) DEFAULT NULL,
    `reason` VARCHAR(255) DEFAULT NULL,
    `source_request_id` INT(11) DEFAULT NULL COMMENT 'الطلب اللي أدى للـ Opt-Out (لو موجود)',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rroo_website_phone` (`website_id`, `guest_phone`),
    KEY `idx_rroo_website_email` (`website_id`, `guest_email`),
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='قائمة عدم التواصل - Review Requests';
