-- =====================================================================
-- Tourfecto - 2026_10_08_000005: فلوس حجوزات مواقع العملاء تروح لصاحب النشاط
--
-- قبل كده كل حجز مدفوع من مواقع العملاء كان بيتقبض على حساب Stripe بتاع المنصة.
-- دلوقتي كل صاحب نشاط بيختار:
--   request    = طلب حجز من غير دفع أونلاين (هو يتواصل مع الزائر ويقبض) - الافتراضي
--   own_stripe = الدفع على حساب Stripe بتاعه هو (المفتاح متخزّن مشفّر)
--   platform   = المنصة تقبض وتحوّله (متاح بس لو الأدمن فعّل PLATFORM_COLLECTS_BOOKINGS)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `operator_payment_settings` (
  `user_id` INT NOT NULL,
  `mode` VARCHAR(20) NOT NULL DEFAULT 'request',
  `stripe_secret_key` TEXT NULL DEFAULT NULL,
  `stripe_key_last4` VARCHAR(8) NULL DEFAULT NULL,
  `stripe_account_name` VARCHAR(190) NULL DEFAULT NULL,
  `verified_at` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
