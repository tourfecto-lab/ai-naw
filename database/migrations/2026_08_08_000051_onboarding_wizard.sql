-- ============================================================
-- Tourfecto - Migration: Onboarding Wizard (Phase 16)
-- إضافي بالكامل - أعمدة جديدة على جدول websites الموجود، مفيش أي عمود
-- أو بيانات موجودة اتحذفت. الجدول أصلًا فيه company_name/industry/
-- target_language/target_country/competitor_1-3_url (من المشروع الأصلي) -
-- الناقص فقط target_customers و main_services وتتبع اكتمال الـWizard.
-- ============================================================

ALTER TABLE `websites`
    ADD COLUMN `target_customers` TEXT NULL DEFAULT NULL
        COMMENT 'وصف العملاء المستهدفين (من خطوة 5 في Onboarding Wizard)' AFTER `target_country`,
    ADD COLUMN `main_services` TEXT NULL DEFAULT NULL
        COMMENT 'الخدمات الأساسية اللي بيقدمها النشاط (من خطوة 6 في Onboarding Wizard) - نص حر أو JSON array مبسّط'
        AFTER `target_customers`,
    ADD COLUMN `onboarding_completed_at` TIMESTAMP NULL DEFAULT NULL
        COMMENT 'وقت اكتمال Onboarding Wizard لهذا الموقع - مختلف عن مجرد إضافة موقع عادي'
        AFTER `main_services`;
