-- ============================================
-- Tourfecto - AI Chat & Customer Communication Platform
-- Migration: Phase 1 - Foundation schema
-- Created: 2026-08-08
--
-- ملاحظات مهمة قبل التشغيل:
--   1. هذا الملف إضافي بالكامل: لا يحذف ولا يعدّل أي جدول أو بيانات موجودة
--      بطريقة كاسرة. العملية الوحيدة على جدول موجود هي ALTER TABLE واحدة
--      تضيف عمود اختياري (nullable) جديد إلى `chat_messages` (انظر تحت).
--   2. شغّل هذا الملف مرة واحدة بعد نسخة احتياطية من قاعدة البيانات.
--   3. كل الجداول الجديدة مرتبطة بـ website_id لضمان عزل بيانات كل شركة
--      (Multi-tenant) طبقًا لبند 26 في المتطلبات.
-- ============================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- 1) قاعدة معرفة الشركة (Company Knowledge Base)
--    بند 4: Company Information / Services / Tours / Destinations /
--    Prices / FAQs / Policies / Cancellation / Contact / Business Hours /
--    Custom Instructions. كما يخزّن هنا Brand Voice (بند 13).
-- ============================================
CREATE TABLE IF NOT EXISTS `ai_knowledge_base` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL COMMENT 'الشركة/الموقع المالك لهذا المحتوى',
    `section` ENUM(
        'company_info', 'service', 'tour', 'destination', 'pricing',
        'faq', 'policy', 'cancellation_policy', 'contact_info',
        'business_hours', 'custom_instructions', 'brand_voice'
    ) NOT NULL COMMENT 'نوع محتوى قاعدة المعرفة',
    `title` VARCHAR(255) DEFAULT NULL COMMENT 'عنوان مختصر (مثال: اسم الجولة أو سؤال الـFAQ)',
    `content` MEDIUMTEXT DEFAULT NULL COMMENT 'المحتوى النصي (إجابة، وصف، سياسة...)',
    `structured_data` JSON DEFAULT NULL COMMENT 'بيانات منظمة اختيارية (سعر/عملة/مدة/الخ)',
    `language` VARCHAR(10) NOT NULL DEFAULT 'ar' COMMENT 'لغة هذا المحتوى',
    `tone` VARCHAR(30) DEFAULT NULL COMMENT 'يُستخدم فقط مع section=brand_voice: professional/friendly/luxury/casual/formal/sales_focused',
    `priority` INT(11) NOT NULL DEFAULT 0 COMMENT 'ترتيب الأهمية عند بناء الـ Context (الأعلى أولاً)',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by_user_id` INT(11) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    INDEX `idx_website_section` (`website_id`, `section`, `is_active`),
    INDEX `idx_website_lang` (`website_id`, `language`),
    INDEX `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='قاعدة معرفة الشركة الخاصة بـ AI Chat (بند 4)';

-- ============================================
-- 2) المحادثات الموحدة (Unified Inbox)
--    بند 1: يجمع كل قناة (website/whatsapp/messenger/instagram/email) في
--    كيان "محادثة" واحد، فوق جدول chat_messages الموجود بالفعل الذي
--    يبقى مصدر الرسائل نفسها (بدون تكرار جدول رسائل جديد).
-- ============================================
CREATE TABLE IF NOT EXISTS `ai_conversations` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL COMMENT 'صاحب الحساب/الشركة',
    `channel` ENUM('website_chat', 'whatsapp', 'messenger', 'instagram', 'email') NOT NULL,
    `channel_thread_id` VARCHAR(255) DEFAULT NULL COMMENT 'معرف المحادثة في القناة الخارجية (رقم واتساب/thread id...)',
    `customer_name` VARCHAR(255) DEFAULT NULL,
    `customer_phone` VARCHAR(50) DEFAULT NULL,
    `customer_email` VARCHAR(255) DEFAULT NULL,
    `encrypted_phone` BLOB DEFAULT NULL,
    `encrypted_email` BLOB DEFAULT NULL,
    `customer_key` VARCHAR(191) DEFAULT NULL COMMENT 'مفتاح ثابت لتوحيد هوية العميل عبر القنوات (hash لرقم الهاتف أو البريد)',
    `status` ENUM('open', 'pending', 'resolved', 'closed') NOT NULL DEFAULT 'open',
    `ai_status` ENUM('ai', 'human', 'paused') NOT NULL DEFAULT 'ai' COMMENT 'هل الذكاء الاصطناعي هو من يرد حاليًا أم تم التحويل لموظف',
    `assigned_agent_id` INT(11) DEFAULT NULL COMMENT 'الموظف المسؤول بعد Human Handoff',
    `handoff_reason` VARCHAR(100) DEFAULT NULL COMMENT 'سبب التحويل لموظف (بند 8)',
    `handoff_at` TIMESTAMP NULL DEFAULT NULL,
    `lead_status` ENUM('none', 'new_inquiry', 'qualifying', 'qualified', 'hot_lead', 'converted', 'lost') NOT NULL DEFAULT 'none',
    `priority` ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
    `tags` JSON DEFAULT NULL COMMENT 'مصفوفة Tags (بند 11)',
    `ai_summary` TEXT DEFAULT NULL COMMENT 'ملخص المحادثة التلقائي (بند 10)',
    `ai_confidence_score` DECIMAL(3,2) DEFAULT NULL COMMENT 'آخر درجة ثقة لرد الـAI (بند 9)',
    `language` VARCHAR(10) DEFAULT NULL COMMENT 'لغة العميل المكتشفة',
    `unread_count` INT(11) NOT NULL DEFAULT 0,
    `last_message_at` TIMESTAMP NULL DEFAULT NULL,
    `last_customer_message_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'يُستخدم لتشغيل Follow-up Automation (بند 7)',
    `do_not_contact` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'العميل طلب عدم التواصل - Stop Condition',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uniq_channel_thread` (`website_id`, `channel`, `channel_thread_id`),
    INDEX `idx_website_status` (`website_id`, `status`),
    INDEX `idx_website_lead_status` (`website_id`, `lead_status`),
    INDEX `idx_website_ai_status` (`website_id`, `ai_status`),
    INDEX `idx_customer_key` (`customer_key`),
    INDEX `idx_last_message_at` (`last_message_at`),
    INDEX `idx_last_customer_message_at` (`last_customer_message_at`),
    INDEX `idx_assigned_agent` (`assigned_agent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Unified Inbox - محادثة موحدة لكل عميل/قناة (بند 1)';

-- إضافة اختيارية غير كاسرة على جدول الرسائل الموجود: كل رسالة قديمة أو
-- جديدة تنتمي (اختياريًا) لمحادثة موحدة. القيمة NULL افتراضيًا فلا يتأثر
-- أي كود أو تقرير حالي يعتمد على chat_messages.
--
-- ملحوظة توافقية: ADD COLUMN/INDEX IF NOT EXISTS غير مدعومة في كل إصدارات
-- MySQL (متاحة فقط من MySQL 8.0.29+ أو MariaDB)، لذلك استخدمنا هنا صيغة
-- عادية (تعمل على كل الإصدارات) على افتراض أن هذا الملف يُشغَّل مرة واحدة
-- فقط. لو كان العمود موجودًا بالفعل من تشغيل سابق، احذف هذا البلوك يدويًا
-- قبل إعادة التشغيل.
ALTER TABLE `chat_messages`
    ADD COLUMN `conversation_id` INT(11) DEFAULT NULL
        COMMENT 'ربط اختياري بـ ai_conversations (Unified Inbox)' AFTER `website_id`;
ALTER TABLE `chat_messages`
    ADD INDEX `idx_conversation_id` (`conversation_id`);

-- ============================================
-- 3) ذاكرة العميل (AI Memory) - بند 3
-- ============================================
CREATE TABLE IF NOT EXISTS `ai_customer_memory` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `customer_key` VARCHAR(191) NOT NULL COMMENT 'نفس مفتاح توحيد الهوية في ai_conversations',
    `memory_key` VARCHAR(100) NOT NULL COMMENT 'name, country, trip_type, travelers_count, travel_date, budget, interests, requested_services, ...',
    `memory_value` TEXT DEFAULT NULL,
    `source_conversation_id` INT(11) DEFAULT NULL,
    `confidence` DECIMAL(3,2) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`source_conversation_id`) REFERENCES `ai_conversations`(`id`) ON DELETE SET NULL,
    UNIQUE KEY `uniq_customer_memory_key` (`website_id`, `customer_key`, `memory_key`),
    INDEX `idx_customer_key` (`customer_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='ذاكرة طويلة المدى للعميل عبر المحادثات (بند 3)';

-- ============================================
-- 4) Lead Qualification - بند 6
--    ملاحظة: هذا منفصل عمدًا عن `website_leads` الموجود، لأن ذاك الجدول
--    خاص بنماذج التواصل/الحجز من الموقع المنشور للعميل النهائي، بينما
--    هذا الجدول هو ملف Lead غني يبنيه الـAI Sales Agent من المحادثة.
-- ============================================
CREATE TABLE IF NOT EXISTS `ai_leads` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `conversation_id` INT(11) NOT NULL,
    `name` VARCHAR(255) DEFAULT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `email` VARCHAR(255) DEFAULT NULL,
    `encrypted_phone` BLOB DEFAULT NULL,
    `encrypted_email` BLOB DEFAULT NULL,
    `source` VARCHAR(50) DEFAULT 'ai_chat' COMMENT 'ai_chat, website_form, referral...',
    `channel` ENUM('website_chat', 'whatsapp', 'messenger', 'instagram', 'email') NOT NULL,
    `interest` VARCHAR(255) DEFAULT NULL COMMENT 'الخدمة/الباقة التي يهتم بها العميل',
    `destination` VARCHAR(255) DEFAULT NULL,
    `travel_date` VARCHAR(100) DEFAULT NULL COMMENT 'نص حر (شهر/فترة تقريبية) وليس تاريخًا صارمًا لأن العميل نادرًا ما يحدده بدقة',
    `budget` VARCHAR(100) DEFAULT NULL,
    `travelers_count` INT(11) DEFAULT NULL,
    `intent_score` TINYINT(4) DEFAULT NULL COMMENT '0-100: درجة نية الشراء',
    `lead_score` TINYINT(4) DEFAULT NULL COMMENT '0-100: الدرجة الإجمالية بناءً على اكتمال البيانات + النية',
    `status` ENUM('new', 'contacted', 'qualified', 'proposal_sent', 'won', 'lost') NOT NULL DEFAULT 'new',
    `ai_summary` TEXT DEFAULT NULL,
    `next_recommended_action` VARCHAR(255) DEFAULT NULL,
    `assigned_agent_id` INT(11) DEFAULT NULL,
    `last_interaction_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations`(`id`) ON DELETE CASCADE,
    INDEX `idx_website_status` (`website_id`, `status`),
    INDEX `idx_website_score` (`website_id`, `lead_score`),
    INDEX `idx_conversation` (`conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='ملف Lead الغني الذي يبنيه AI Sales Agent (بند 5-6)';

-- ============================================
-- 5) قواعد وسجلّ المتابعة التلقائية (Follow-up Automation) - بند 7
-- ============================================
CREATE TABLE IF NOT EXISTS `ai_followup_rules` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `steps` JSON NOT NULL COMMENT 'مصفوفة خطوات: [{"after_hours":24,"template":"..."}, {"after_hours":72,"template":"..."}, {"after_hours":168,"template":"...","is_final":true}]',
    `max_followups` INT(11) NOT NULL DEFAULT 3,
    `stop_conditions` JSON DEFAULT NULL COMMENT 'مصفوفة أسباب الإيقاف الإضافية القابلة للتخصيص فوق الافتراضية',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uniq_website_rules` (`website_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='إعدادات المتابعة التلقائية القابلة للتعديل لكل شركة (بند 7)';

CREATE TABLE IF NOT EXISTS `ai_followups` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `conversation_id` INT(11) NOT NULL,
    `lead_id` INT(11) DEFAULT NULL,
    `followup_number` INT(11) NOT NULL DEFAULT 1,
    `scheduled_at` TIMESTAMP NOT NULL,
    `sent_at` TIMESTAMP NULL DEFAULT NULL,
    `status` ENUM('pending', 'sent', 'cancelled', 'failed') NOT NULL DEFAULT 'pending',
    `template_used` TEXT DEFAULT NULL,
    `stop_reason` VARCHAR(100) DEFAULT NULL COMMENT 'يُملأ عند status=cancelled: customer_opted_out, human_handoff, lead_closed, booking_completed, max_reached...',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`lead_id`) REFERENCES `ai_leads`(`id`) ON DELETE SET NULL,
    INDEX `idx_status_scheduled` (`status`, `scheduled_at`),
    INDEX `idx_conversation` (`conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='سجلّ رسائل المتابعة المجدولة/المرسلة (بند 7)';

-- ============================================
-- 6) Tags مخصصة قابلة للإضافة من صاحب الشركة - بند 11
--    (الـTags الجاهزة HOT_LEAD/NEW_INQUIRY/... تُدار في الكود مباشرة
--    ولا تحتاج جدولاً، هذا الجدول فقط للـTags الإضافية المخصصة)
-- ============================================
CREATE TABLE IF NOT EXISTS `ai_custom_tags` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) NOT NULL,
    `name` VARCHAR(50) NOT NULL,
    `color` VARCHAR(20) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`website_id`) REFERENCES `websites`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uniq_website_tag` (`website_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tags مخصصة لكل شركة تُضاف فوق القائمة الجاهزة (بند 11)';

-- ============================================
-- 7) تسجيل استخدام/تكلفة الذكاء الاصطناعي - بند 21
--    منفصل عن api_usage_logs العام الموجود لأن هذا يحتاج حقولاً خاصة
--    بالـConversation/Provider القابل للتبديل ولميزة AI Chat تحديدًا.
-- ============================================
CREATE TABLE IF NOT EXISTS `ai_usage_logs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) DEFAULT NULL,
    `user_id` INT(11) DEFAULT NULL,
    `conversation_id` INT(11) DEFAULT NULL,
    `provider` VARCHAR(30) NOT NULL COMMENT 'openai, gemini, deepseek, kimi',
    `model` VARCHAR(100) DEFAULT NULL,
    `feature` VARCHAR(60) NOT NULL COMMENT 'chat_reply, summary, lead_scoring, reply_suggestions, translation, ...',
    `tokens_input` INT(11) DEFAULT 0,
    `tokens_output` INT(11) DEFAULT 0,
    `tokens_total` INT(11) DEFAULT 0,
    `estimated_cost_usd` DECIMAL(10,6) DEFAULT 0,
    `status` ENUM('success', 'failed', 'fallback_used') NOT NULL DEFAULT 'success',
    `duration_ms` INT(11) DEFAULT NULL,
    `error_message` VARCHAR(500) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_website_created` (`website_id`, `created_at`),
    INDEX `idx_provider` (`provider`),
    INDEX `idx_conversation` (`conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='سجلّ استخدام وتكلفة كل طلب AI Chat (بند 21)';

-- ============================================
-- 8) Idempotency لأحداث الـWebhooks الخارجية - بند 23
-- ============================================
CREATE TABLE IF NOT EXISTS `ai_webhook_events` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `website_id` INT(11) DEFAULT NULL,
    `channel` VARCHAR(30) NOT NULL,
    `external_event_id` VARCHAR(255) NOT NULL COMMENT 'معرف الحدث/الرسالة من مزود القناة',
    `payload_hash` VARCHAR(64) DEFAULT NULL,
    `status` ENUM('received', 'processed', 'ignored_duplicate', 'error') NOT NULL DEFAULT 'received',
    `error_message` VARCHAR(500) DEFAULT NULL,
    `received_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_channel_event` (`channel`, `external_event_id`),
    INDEX `idx_website` (`website_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='منع معالجة نفس Webhook مرتين (بند 23)';

SET FOREIGN_KEY_CHECKS = 1;
