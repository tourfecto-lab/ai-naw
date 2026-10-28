<?php

/**
 * Tourfecto - Email Tag Model (وسوم المشتركين)
 * @version 1.0.0
 */
class EmailTag extends Model
{
    protected $table = 'email_tags';
    protected $fillable = ['user_id', 'name', 'color'];
}
