<?php

/**
 * Tourfecto - Email Campaign Model (حملات تسويق البريد)
 * @version 1.0.0
 */
class EmailCampaign extends Model
{
    protected $table = 'email_campaigns';
    protected $fillable = [
        'user_id', 'name', 'subject', 'from_name', 'from_email',
        'template_id', 'list_id', 'audience_ids', 'segment_id', 'html_body',
        'status', 'scheduled_at', 'sent_at',
        'total_recipients', 'sent_count', 'opened_count', 'clicked_count',
        'unsubscribed_count', 'bounced_count', 'error_message',
        'send_time_optimization', 'recurrence', 'next_run_at', 'parent_campaign_id'
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PARTIAL='partial';
    public const STATUS_UNCERTAIN='uncertain';
}
