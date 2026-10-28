<?php

/** Tourfecto - Outreach Email Model (Phase 10) @version 1.0.0 */
class OutreachEmail extends Model
{
    protected $table = 'outreach_emails';
    protected $fillable = [
        'prospect_id', 'sequence_number', 'subject', 'body', 'status',
        'sent_at', 'error_message',
    ];
}
