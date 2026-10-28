<?php

/**
 * Tourfecto - Email Campaign Recipient Model (مستلمو الحملة + تتبع)
 * @version 1.0.0
 */
class EmailCampaignRecipient extends Model
{
    protected $table = 'email_campaign_recipients';
    protected $fillable = [
        'campaign_id', 'subscriber_id', 'email', 'name', 'status',
        'open_token', 'click_token', 'opened_at', 'clicked_at',
        'open_count', 'click_count', 'error_message'
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_OPENED = 'opened';
    public const STATUS_CLICKED = 'clicked';
    public const STATUS_UNSUBSCRIBED = 'unsubscribed';
    public const STATUS_BOUNCED = 'bounced';
    public const STATUS_FAILED = 'failed';
}
