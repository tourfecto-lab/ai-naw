<?php

/**
 * Tourfecto - Agency Invitation Model (White-Label)
 * دعوة وكيل لعميل حقيقي للانضمام لوكالته عبر رمز/رابط فريد.
 * عند القبول يتحوّل سجل الدعوة لحالة accepted ويُضاف العميل
 * في agency_clients تلقائيًا.
 * @version 1.0.0  @date 2026-08-31
 */
class AgencyInvitation extends Model
{
    protected $table = 'agency_invitations';
    protected $fillable = [
        'agency_id', 'email', 'token', 'commission_rate',
        'invited_by', 'status', 'expires_at', 'accepted_at',
    ];
}
