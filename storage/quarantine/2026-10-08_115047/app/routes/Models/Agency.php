<?php
/**
 * Tourfecto - Agency Model (White-Label)
 * الوكالة مساحة عمل مملوكة لمستخدم موجود في users - ليست نظام دخول منفصل
 * @version 1.0.0
 */
class Agency extends Model {
    protected $table = 'agencies';
    protected $fillable = [
        'owner_user_id', 'name', 'slug', 'status', 'plan_seats'
    ];
}
