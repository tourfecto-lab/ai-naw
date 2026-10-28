-- ============================================
-- Tourfecto - AI Chat & Customer Communication Platform
-- Migration: Learning Loop (Resolution + Knowledge Gaps)
-- Created: 2026-08-16
--
-- ملاحظات:
--   1. هذا الملف إضافي بالكامل: لا يعدّل أي جدول أو بيانات موجودة.
--   2. شغّل هذا الملف مرة واحدة بعد نسخة احتياطية من قاعدة البيانات.
--   3. الفكرة مستوحاة من "Resolution Learning Loop" في Zendesk وIntercom
--      Fin Flywheel: نتعلم من نتيجة كل محادثة (هل الـAI حل فعلاً أم أحيل
--      لموظف؟) ونكتشف فجوات المعرفة (أسئلة لم يستطع الـAI الإجابة عنها)
--      لنقترح إضافتها لقاعدة المعرفة تلقائيًا.
-- ============================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- 1) أحداث نتيجة المحادثة (Resolution Events)
--    تُسجَّل عند إغلاق/حل محادثة: هل الـAI حلّها بالكامل، أم أحيلت لموظف؟
--    أساس حساب "AI Resolution Rate" الحقيقي والتحسين المستمر.
-- ============================================
CREATE TABLE IF NOT EXISTS `ai_resolution_events` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `conversation_id` INT(11) DEFAULT NULL,
    `channel` VARCHAR(30) DEFAULT NULL COMMENT 'website_chat/whatsapp/messenger/instagram/email',
    `language` VARCHAR(10) DEFAULT NULL,
    `outcome` ENUM('ai_resolved', 'human_resolved', 'abandoned', 'reopened') NOT NULL,
    `handoff_reason` VARCHAR(100) DEFAULT NULL COMMENT 'سبب التحويل لو outcome=human_resolved',
    `ai_confidence_score` DECIMAL(3,2) DEFAULT NULL COMMENT 'آخر ثقة للرد الآلي في المحادثة',
    `resolved_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    INDEX `idx_website_created` (`website_id`, `created_at`),
    INDEX `idx_outcome` (`outcome`),
    INDEX `idx_conversation` (`conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Learning Loop - نتيجة كل محادثة (هل حلها الـAI فعلاً؟)';

-- ============================================
-- 2) فجوات المعرفة (Knowledge Gaps)
--    أسئلة العملاء التي لم يستطع الـAI الإجابة عنها فتحوّل لموظف.
--    تُجمَّع حسب السؤال بعد تسويته نصيًا، وتُقترح لصاحب الشركة
--    لإضافتها لقاعدة المعرفة (Flywheel). نفس المحادثة لا تُحسب إلا مرة.
-- ============================================
CREATE TABLE IF NOT EXISTS `ai_knowledge_gaps` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `conversation_id` INT(11) DEFAULT NULL COMMENT 'لضمان عدم تكرار نفس المحادثة',
    `question` TEXT NOT NULL COMMENT 'آخر رسالة للعميل قبل التحويل (ما لم يستطع الـAI الإجابة عنه)',
    `normalized_question` VARCHAR(500) NOT NULL COMMENT 'السؤال بعد التسوية (حروف صغيرة + إزالة علامات الترقيم) للتجميع',
    `language` VARCHAR(10) DEFAULT NULL,
    `handoff_reason` VARCHAR(100) DEFAULT NULL,
    `occurrence_count` INT(11) NOT NULL DEFAULT 1 COMMENT 'عدد المحادثات المختلفة التي طرحت نفس السؤال',
    `status` ENUM('new', 'acknowledged', 'added_to_kb', 'dismissed') NOT NULL DEFAULT 'new',
    `last_seen_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uniq_website_conversation` (`website_id`, `conversation_id`),
    INDEX `idx_website_status` (`website_id`, `status`),
    INDEX `idx_website_occurrences` (`website_id`, `occurrence_count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Learning Loop - أسئلة لم يستطع الـAI الإجابة عنها (فجوات معرفة)';

SET FOREIGN_KEY_CHECKS = 1;
