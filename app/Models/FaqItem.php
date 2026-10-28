<?php

/**
 * Tourfecto - FAQ Item Model
 * @version 1.0.0
 */
class FaqItem extends Model
{
    protected $table = 'faq_items';
    protected $fillable = ['question', 'answer', 'sort_order', 'is_active'];
}
