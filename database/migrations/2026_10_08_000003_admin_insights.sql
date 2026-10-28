-- Tourfecto - لوحة الأدمن الاحترافية: صحة المنصة، سجل البريد، التنبيهات،
-- الكوبونات، تاجات العملاء، الفلاتر المحفوظة، صفحة الحالة وسجل التحديثات.
-- آمن يتشغّل أكتر من مرة (IF NOT EXISTS).

-- كل إيميل المنصة بعتته (أو فشلت تبعته). جسم الرسالة بيتمسح بعد 14 يوم والسجل بعد 90.
CREATE TABLE IF NOT EXISTS mail_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    to_email VARCHAR(255) NOT NULL,
    to_name VARCHAR(255) NULL,
    subject VARCHAR(500) NOT NULL,
    body_html MEDIUMTEXT NULL,
    status ENUM('sent','failed') NOT NULL,
    error VARCHAR(1000) NULL,
    context VARCHAR(60) NULL COMMENT 'password_reset / campaign / test / ...',
    smtp_host VARCHAR(190) NULL,
    from_email VARCHAR(255) NULL,
    user_id INT UNSIGNED NULL COMMENT 'العميل اللي الإيميل بتاعه (لو معروف)',
    resent_from_id BIGINT UNSIGNED NULL,
    duration_ms INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_created (created_at),
    KEY idx_status_created (status, created_at),
    KEY idx_to (to_email),
    KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- تنبيهات الأدمن: تنبيه مفتوح واحد لكل مشكلة (alert_key)، بيتقفل لوحده لما المشكلة تتحل
CREATE TABLE IF NOT EXISTS admin_alerts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alert_key VARCHAR(120) NOT NULL,
    severity ENUM('critical','warning','info') NOT NULL DEFAULT 'warning',
    title VARCHAR(255) NOT NULL,
    body TEXT NULL,
    link VARCHAR(255) NULL,
    status ENUM('open','resolved','muted') NOT NULL DEFAULT 'open',
    occurrences INT UNSIGNED NOT NULL DEFAULT 1,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notified_at DATETIME NULL,
    resolved_at DATETIME NULL,
    resolved_by INT UNSIGNED NULL,
    KEY idx_key_status (alert_key, status),
    KEY idx_status (status, severity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- آخر نتيجة اختبار تلقائي لكل تكامل (بيتعمل من cron/admin_watchdog.php)
CREATE TABLE IF NOT EXISTS integration_checks (
    integration_key VARCHAR(60) NOT NULL PRIMARY KEY,
    ok TINYINT(1) NOT NULL DEFAULT 0,
    message VARCHAR(1000) NULL,
    fails_in_row INT UNSIGNED NOT NULL DEFAULT 0,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- أكواد الخصم
CREATE TABLE IF NOT EXISTS coupons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL,
    description VARCHAR(255) NULL,
    discount_type ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
    amount DECIMAL(10,2) NOT NULL,
    max_redemptions INT UNSIGNED NULL COMMENT 'NULL = بدون حد',
    per_user_limit INT UNSIGNED NOT NULL DEFAULT 1,
    valid_from DATETIME NULL,
    valid_until DATETIME NULL,
    plan_codes TEXT NULL COMMENT 'JSON ["pro",...] - NULL = كل الباقات',
    first_payment_only TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupon_redemptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    coupon_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    plan_code VARCHAR(60) NULL,
    original_amount DECIMAL(10,2) NOT NULL,
    discount_amount DECIMAL(10,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_coupon (coupon_id),
    KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- تاجات داخلية على العملاء (VIP، شريك، محتاج متابعة ...)
CREATE TABLE IF NOT EXISTS admin_user_tags (
    user_id INT UNSIGNED NOT NULL,
    tag VARCHAR(40) NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, tag),
    KEY idx_tag (tag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- فلاتر محفوظة لكل أدمن
CREATE TABLE IF NOT EXISTS admin_saved_views (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id INT UNSIGNED NOT NULL,
    page VARCHAR(40) NOT NULL,
    name VARCHAR(80) NOT NULL,
    filters TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_admin_page (admin_id, page)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- صفحة الحالة العامة: الحوادث والصيانة
CREATE TABLE IF NOT EXISTS status_incidents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    body TEXT NULL,
    component VARCHAR(40) NOT NULL DEFAULT 'platform',
    severity ENUM('minor','major','maintenance') NOT NULL DEFAULT 'minor',
    status ENUM('investigating','identified','monitoring','resolved','scheduled') NOT NULL DEFAULT 'investigating',
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_status (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- سجل التحديثات (إيه الجديد)
CREATE TABLE IF NOT EXISTS changelog_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    body TEXT NULL,
    category ENUM('new','improved','fixed') NOT NULL DEFAULT 'new',
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    published_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pub (is_published, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
