<?php

/**
 * Tourfecto - Email List Model (قوائم الجمهور)
 * @version 1.0.0
 */
class EmailList extends Model
{
    protected $table = 'email_lists';
    protected $fillable = [
        'user_id', 'name', 'description', 'subscriber_count'
    ];
}
