-- التشغيل الذاتي للعقل لكل شركة (مقفول افتراضيًا): لما يتفتح، العقل بيحوّل القرارات الجديدة اللي مافيهاش صرف
-- لمهام وتنبيهات لوحده. أي حاجة فيها صرف فلوس بتفضل مستنية موافقة صاحب الشركة.
CREATE TABLE IF NOT EXISTS brain_settings (
 user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 autonomy TINYINT(1) NOT NULL DEFAULT 0,
 updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
