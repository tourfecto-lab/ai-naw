<?php

/**
 * Tourfecto - Email Segment Model (شرائح الجمهور)
 * @version 1.0.0
 */
class EmailSegment extends Model
{
    protected $table = 'email_segments';
    protected $fillable = [
        'user_id', 'name', 'description', 'conditions', 'match_all', 'subscriber_count'
    ];
}
