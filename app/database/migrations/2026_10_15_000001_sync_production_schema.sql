-- ============================================================
-- Tourfecto - مزامنة قاعدة بيانات السيرفر مع الكود (2026-10-15)
--
-- اتعمل بمقارنة هيكل قاعدة البيانات الحقيقية على السيرفر (تصدير
-- phpMyAdmin بتاريخ 2026-10-15) بالكود. بيضيف بس الحاجات الناقصة اللي
-- الكود محتاجها - مش بيمسح أي جدول أو عمود أو بيانات.
--
-- آمن تشغّله أكتر من مرة (كل خطوة بتتأكد الأول إن الحاجة مش موجودة).
-- متوافق مع MySQL 8/9 و MariaDB.
--
-- التشغيل: phpMyAdmin > اختار قاعدة البيانات > SQL > الصق الملف > Go
-- ============================================================

SET @tf_schema = DATABASE();

-- 1) reviews.reply_sent: كود قديم بيقرا/يكتب العمود ده بجانب reply_sent_at.
SET @tf_sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@tf_schema AND table_name='reviews' AND column_name='reply_sent'),
    'SELECT 1',
    'ALTER TABLE reviews ADD COLUMN reply_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER reply_status');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
UPDATE reviews SET reply_sent = 1 WHERE reply_sent_at IS NOT NULL AND reply_sent = 0;

-- 2) WhatsApp Marketing: أعمدة القوالب والحملات والتاجز (من 2026_10_11_whatsapp_completion.sql
--    اللي ماتشغّلش على السيرفر - من غيرها إنشاء القوالب والحملات بيفشل).
SET @tf_sql=IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@tf_schema AND table_name='wa_marketing_contacts' AND column_name='tags'),'SELECT 1','ALTER TABLE wa_marketing_contacts ADD COLUMN tags VARCHAR(250) NOT NULL DEFAULT \'\'');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_sql=IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@tf_schema AND table_name='wa_marketing_templates' AND column_name='components_json'),'SELECT 1','ALTER TABLE wa_marketing_templates ADD COLUMN components_json LONGTEXT NULL');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
SET @tf_sql=IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@tf_schema AND table_name='wa_marketing_campaigns' AND column_name='components_json'),'SELECT 1','ALTER TABLE wa_marketing_campaigns ADD COLUMN components_json LONGTEXT NULL, ADD COLUMN extra_parameters_json LONGTEXT NULL');
PREPARE tf_stmt FROM @tf_sql; EXECUTE tf_stmt; DEALLOCATE PREPARE tf_stmt;
CREATE TABLE IF NOT EXISTS wa_marketing_quota_reservations (
 campaign_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,
 subscription_id BIGINT UNSIGNED NULL,period_start DATETIME NOT NULL,units INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY wa_quota_period(user_id,period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS wa_crm_inbound_links (
 user_id BIGINT UNSIGNED NOT NULL,website_id BIGINT UNSIGNED NOT NULL,wamid VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 message_id BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(user_id,website_id,wamid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Existing CRM communication/team tables: fill genuinely missing storage without changing installed tables.
CREATE TABLE IF NOT EXISTS crm_team_members (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,tenant_user_id BIGINT UNSIGNED NOT NULL,member_user_id BIGINT UNSIGNED NOT NULL,
 role ENUM('admin','manager','sales','support','viewer') NOT NULL DEFAULT 'viewer',added_by_user_id BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_member_single_tenant(member_user_id),KEY idx_crm_team_tenant(tenant_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS crm_conversations (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,contact_id INT NULL,
 channel ENUM('whatsapp','email','sms') NOT NULL,external_thread_id VARCHAR(255) NULL,assigned_user_id BIGINT UNSIGNED NULL,
 last_message_at DATETIME NULL,unread_count INT NOT NULL DEFAULT 0,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_crm_conv_user(user_id),KEY idx_crm_conv_contact(contact_id),KEY idx_crm_conv_channel_thread(channel,external_thread_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS crm_messages (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,conversation_id INT NOT NULL,direction ENUM('inbound','outbound') NOT NULL,
 sender_user_id BIGINT UNSIGNED NULL,body TEXT NOT NULL,subject VARCHAR(255) NULL,status VARCHAR(30) NOT NULL DEFAULT 'sent',
 external_message_id VARCHAR(255) NULL,error VARCHAR(500) NULL,sent_at DATETIME NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY idx_crm_msg_conversation(conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) تنبيهات الأدمن عند تسجيل حساب جديد / تسجيل الدخول (الجدول ناقص على السيرفر).
CREATE TABLE IF NOT EXISTS registration_admin_alerts (
 user_id BIGINT UNSIGNED NOT NULL,recipient VARCHAR(254) NOT NULL,signup_method VARCHAR(30) NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'queued',error_message VARCHAR(300) NULL,
 created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,sent_at DATETIME NULL,
 PRIMARY KEY(user_id),KEY ix_signup_alert_status(status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS login_admin_alerts (
 event_id CHAR(32) NOT NULL,user_id BIGINT UNSIGNED NOT NULL,recipient VARCHAR(254) NOT NULL,
 login_method VARCHAR(30) NOT NULL,occurred_at DATETIME NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'queued',
 error_message VARCHAR(300) NULL,updated_at DATETIME NOT NULL,sent_at DATETIME NULL,
 PRIMARY KEY(event_id),KEY ix_login_alert_owner(user_id,occurred_at),KEY ix_login_alert_status(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Website Optimizer: الأعمدة دي أقصر على السيرفر من القيم اللي الكود
--    بيكتبها (من 2026_10_03_000001_align_schema_with_code.sql). توسيع بس.
ALTER TABLE `wo_audit_findings`
    MODIFY COLUMN `check_key` VARCHAR(100) DEFAULT NULL,
    MODIFY COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'info',
    MODIFY COLUMN `severity` VARCHAR(20) NOT NULL DEFAULT 'medium',
    MODIFY COLUMN `message` TEXT DEFAULT NULL;
ALTER TABLE `wo_fixes` MODIFY COLUMN `fix_type` VARCHAR(30) DEFAULT NULL;
