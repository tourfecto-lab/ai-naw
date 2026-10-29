-- Tourfecto - الشات: تثبيت / أرشفة / "غير مقروء" لكل موظف (WhatsApp-style). آمن للتشغيل أكتر من مرة (CREATE TABLE IF NOT EXISTS).
-- اختياري: من غير الجدول ده الواجهة بتحفظ الاختيارات في المتصفح بس (زي ما هي دلوقتي) ومفيش أي خطأ.
CREATE TABLE IF NOT EXISTS `chat_conversation_prefs` (
    `user_id` INT(11) NOT NULL,
    `conversation_id` INT(11) NOT NULL,
    `pinned` TINYINT(1) NOT NULL DEFAULT 0,
    `archived` TINYINT(1) NOT NULL DEFAULT 0,
    `marked_unread` TINYINT(1) NOT NULL DEFAULT 0,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `conversation_id`),
    KEY `idx_ccp_conversation` (`conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
