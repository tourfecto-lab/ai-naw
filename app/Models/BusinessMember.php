<?php

/**
 * Tourfecto - Business Member Model
 * Team Management + RBAC (Business Control Center Phase 10-11)
 * @version 1.0.0
 *
 * جدول `business_members` - الأعضاء الإضافيين للـBusiness (غير المالك).
 * المالك (owner) مش مخزّن هنا إطلاقًا - بيتحدد عبر `businesses.owner_user_id`
 * في BusinessAccessService، عشان مفيش نسختين من "المالك" ممكن تختلفوا.
 *
 * الحقول JSON: مفيش حقل JSON هنا حاليًا، لكن بنفس نمط باقي Models الموديول
 * (BusinessTargetMarket مثلًا)، أي قراءة لحقل مفتاحه موجود هتتم عبر getters
 * والتحقق من الشكل - مش json_decode مبعثر في المتحكمات.
 */
class BusinessMember extends Model
{
    protected $table = 'business_members';

    protected $fillable = [
        'business_id',
        'user_id',
        'role',
        'status',
        'invited_by_user_id',
        'invited_email',
        'invite_token',
        'invite_expires_at',
    ];

    // F3 (Phase 26 security audit): حماية دفاعية - حتى لو حد استدعى
    // toArray() على أي صف BusinessMember، التوكن ووقت الانتهاء مش
    // هيتسرّبوا. كل المسارات الحالية بتستخدم memberToArray() الصريح
    // في BusinessTeamService أصلاً - دي طبقة تانية.
    protected $hidden = ['invite_token', 'invite_expires_at'];

    /**
     * هل العضو ده نشط فعليًا (مش دعوة معلقة)؟
     */
    public function isActive(): bool
    {
        return $this->getAttribute('status') === 'active';
    }

    /**
     * هل العضو ده دعوة معلقة لسه؟
     */
    public function isPending(): bool
    {
        return $this->getAttribute('status') === 'invited';
    }
}
