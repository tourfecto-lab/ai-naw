<?php

/**
 * Tourfecto - Email Automation Entry Model (مشارك في سير عمل أتمتة البريد)
 * @version 1.0.0
 */
class EmailAutomationEntry extends Model
{
    protected $table = 'email_automation_entries';
    protected $fillable = [
        'automation_id', 'user_id', 'subscriber_id', 'step_position',
        'status', 'context', 'next_run_at', 'last_processed_at', 'completed_at',
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_EXITED = 'exited';
    public const STATUS_PAUSED = 'paused';
}
