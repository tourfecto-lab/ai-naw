<?php

/**
 * Tourfecto - SEO: Scheduled Report Model (G6)
 * @version 1.0.0
 *
 * جدولة إرسال تقرير SEO بريدي دوري (daily/weekly/monthly) لموقع معيّن.
 */
class SeoReportSchedule extends Model
{
    protected $table = 'seo_report_schedules';
    protected $fillable = [
        'website_id', 'user_id', 'frequency', 'weekday', 'hour',
        'recipient_email', 'is_active', 'last_sent_at',
    ];
}
