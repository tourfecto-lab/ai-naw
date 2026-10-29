-- TOURFECTO AI CEO (المرحلة 9): مؤشر صحة الشركة والذكاء التنافسي. إضافة بس.
-- aiceo_health_scores: درجة كل يوم (0-100) ومكوناتها، عشان نشوف إيه اتحسن وإيه اتراجع. لو البيانات ناقصة الدرجة بتتسجل NULL ومعاها نسبة التغطية.
-- aiceo_competitors: المنافسين اللي صاحب المنصة بيضيفهم (السعر والمميزات ونقاط القوة والضعف)، وبصمة صفحتهم عشان نعرف لو اتغيرت.
CREATE TABLE IF NOT EXISTS aiceo_health_scores (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 day DATE NOT NULL,
 score TINYINT NULL,
 partial_score TINYINT NULL,
 coverage TINYINT NOT NULL DEFAULT 0,
 components_json TEXT NOT NULL,
 created_at DATETIME NOT NULL,
 UNIQUE KEY uq_aiceo_health_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aiceo_competitors (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 url VARCHAR(255) NULL,
 starting_price_usd DECIMAL(10,2) NULL,
 pricing_notes VARCHAR(500) NULL,
 features TEXT NULL,
 strengths TEXT NULL,
 weaknesses TEXT NULL,
 page_title VARCHAR(255) NULL,
 page_hash CHAR(40) NULL,
 page_changed_at DATETIME NULL,
 checked_at DATETIME NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
