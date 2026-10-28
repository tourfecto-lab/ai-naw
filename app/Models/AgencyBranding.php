<?php

/**
 * Tourfecto - Agency Branding Model (White-Label)
 * @version 1.0.0
 */
class AgencyBranding extends Model
{
    protected $table = 'agency_branding';
    protected $fillable = [
        'agency_id', 'logo_path', 'favicon_path', 'primary_color',
        'secondary_color', 'custom_css', 'support_email'
    ];
}
