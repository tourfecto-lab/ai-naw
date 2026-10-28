-- Tourfecto - Email Double Opt-In (بند 2: تأكيد الاشتراك)
-- يضيف حالة pending_optin (بلا تعديل القيم الموجودة في الـ ENUM) + عمود
-- optin_token للتأكيد. أعمدة optin_ip/optin_at موجودة أصلًا من ميجريشن
-- 2026_08_21_000011_email_marketing_contacts.sql.
--
-- ملاحظة: ALTER MODIFY على الـ ENUM بيضيف القيمة الجديدة فقط ويترك القيم
-- القديمة كما هي (subscribed/unsubscribed/bounced) — لا مساس بأي صف موجود.
-- لو اتعاد تشغيل الملف على قاعدة محدّثة هيترمى Duplicate column ويتجاهله
-- حلقة الميجريشن بأمان.

SET NAMES utf8mb4;

ALTER TABLE `email_subscribers`
    MODIFY COLUMN `status` ENUM('subscribed','unsubscribed','bounced','pending_optin') NOT NULL DEFAULT 'subscribed' COMMENT 'حالة الاشتراك (pending_optin = في انتظار تأكيد البريد - Double Opt-In)',
    ADD COLUMN `optin_token` VARCHAR(64) NULL DEFAULT NULL COMMENT 'توكن تأكيد الاشتراك (Double Opt-In)' AFTER `unsubscribe_token`,
    ADD KEY `idx_email_subscribers_optin` (`optin_token`);
