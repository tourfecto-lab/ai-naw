ALTER TABLE ai_articles MODIFY COLUMN target_language VARCHAR(10) NOT NULL DEFAULT 'ar';
ALTER TABLE seo_content_campaigns ADD COLUMN target_language VARCHAR(10) NOT NULL DEFAULT 'ar';
