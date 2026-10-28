-- Explicit SEO-site mapping, independent of crm_products.website_id (a Builder site id).
-- One product belongs to one SEO measurement site; bookings/payment records are unchanged.
CREATE TABLE IF NOT EXISTS seo_platform_booking_products (
 product_id INT NOT NULL PRIMARY KEY,
 website_id INT NOT NULL,
 user_id INT NOT NULL,
 confirmed_at DATETIME NOT NULL,
 KEY owner_site(user_id,website_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
