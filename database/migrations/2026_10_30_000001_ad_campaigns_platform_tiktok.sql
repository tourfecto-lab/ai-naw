-- ad_campaigns.platform ENUM gets 'tiktok_ads'. Optional and idempotent: TikTok campaigns publish without it (the platform
-- label then stays 'manual'); apply it by hand on the server when TikTok Ads is switched on.
ALTER TABLE `ad_campaigns` MODIFY COLUMN `platform` ENUM('manual','meta_ads','google_ads','tiktok_ads') NOT NULL DEFAULT 'manual';
