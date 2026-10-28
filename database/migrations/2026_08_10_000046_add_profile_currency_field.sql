-- ============================================================
-- Tourfecto - Migration: Global Account & Profile Center (Phase 1)
-- إضافة Currency Preference لجدول users فقط.
-- @version 1.0.1 (مُعدَّلة عن نسخة الموديول الأصلية) @date 2026-08-10
--
-- ⚠️ ملاحظة مهمة (اكتُشفت أثناء الدمج، مش موجودة في نسخة الموديول
-- الأصلية): أعمدة display_name و job_title المذكورة في نسخة الـmigration
-- الأصلية **موجودة بالفعل فعليًا** في قاعدة البيانات (تم التأكد بفحص
-- الكود الحالي المُدمَج مسبقًا - UserController::renderSettingsPage()
-- بيستخدمهم فعليًا). لو شغّلت الـmigration الأصلية اللي جاية مع
-- الموديول، هي هتفشل فورًا على "Duplicate column" وممكن الـALTER TABLE
-- كله (لو 3 أعمدة في statement واحد) ميضيفش currency خالص. لذلك هذه
-- النسخة المُعدَّلة بتضيف currency لوحدها فقط.
--
-- إضافية بالكامل (ADD COLUMN فقط) - لا DROP ولا TRUNCATE.
--
-- قبل التشغيل، نفّذ يدويًا للتأكيد:
--   SHOW COLUMNS FROM users LIKE 'display_name';   -- المتوقع: موجود بالفعل
--   SHOW COLUMNS FROM users LIKE 'job_title';       -- المتوقع: موجود بالفعل
--   SHOW COLUMNS FROM users LIKE 'currency';        -- المتوقع: مفيش نتائج
-- ============================================================

ALTER TABLE `users`
    ADD COLUMN `currency` VARCHAR(3) DEFAULT NULL
        COMMENT 'عملة تفضيل العرض (ISO 4217) - لا علاقة لها بعملة الاشتراك/الفوترة في subscriptions/invoices'
        AFTER `timezone`;
