-- ============================================================
-- Tourfecto - Migration: موقع النشاط على الخريطة (websites)
-- @date 2026-08-08
--
-- الملف ده كان بالغلط نسخة من migration الـ ad_campaigns.platform.
-- الأعمدة مطابقة لـ app/Models/Website.php (fillable) وخريطة العملاء في
-- AdminProController.
-- ============================================================

ALTER TABLE `websites` ADD COLUMN `latitude` DECIMAL(10,7) DEFAULT NULL;

ALTER TABLE `websites` ADD COLUMN `longitude` DECIMAL(10,7) DEFAULT NULL;

ALTER TABLE `websites` ADD COLUMN `formatted_address` VARCHAR(500) DEFAULT NULL;

ALTER TABLE `websites` ADD COLUMN `location_updated_at` DATETIME DEFAULT NULL;
