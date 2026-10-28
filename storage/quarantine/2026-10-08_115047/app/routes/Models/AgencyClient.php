<?php
/**
 * Tourfecto - Agency Client Model (White-Label)
 * ربط عملاء الوكالة بمستخدمين حقيقيين في users - لا يوجد جدول مستخدمين منفصل
 * @version 1.0.0
 */
class AgencyClient extends Model {
    protected $table = 'agency_clients';
    protected $fillable = [
        'agency_id', 'client_user_id', 'status'
    ];
}
