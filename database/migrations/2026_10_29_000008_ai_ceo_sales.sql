-- TOURFECTO AI CEO (المرحلة 4): المهتمين من تسويق المنصة اللي اتنقلوا لـ CRM بتاع صاحب المنصة (مرة واحدة لكل شركة).
-- إضافة بس.
CREATE TABLE IF NOT EXISTS tbi_crm_handoffs (
 lead_id INT NOT NULL PRIMARY KEY,
 crm_contact_id BIGINT UNSIGNED NOT NULL,
 crm_lead_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
