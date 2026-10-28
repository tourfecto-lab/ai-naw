<?php
/**
 * Tourfecto - Agency Email Template Model (White-Label)
 * @version 1.0.0
 */
class AgencyEmailTemplate extends Model {
    protected $table = 'agency_email_templates';
    protected $fillable = [
        'agency_id', 'template_key', 'subject', 'body_html'
    ];
}
