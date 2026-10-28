<?php

/**
 * Tourfecto - System Setting Model
 * @version 1.0.0
 */
class SystemSetting extends Model
{
    protected $table = 'system_settings';
    protected $fillable = ['setting_key', 'setting_value', 'is_secret', 'category'];
}
