<?php

/**
 * Tourfecto - Ad Team Member Model
 * عضوية فريق على موديول الإعلانات (بند 27) - راجع تعليق migration
 * 2026_08_12_000045 عن نطاق الميزة الجديدة دي بالكامل.
 * @version 1.0.0
 */
class AdTeamMember extends Model
{
    protected $table = 'ad_team_members';
    protected $fillable = ['owner_user_id', 'member_user_id', 'role', 'invited_by_user_id', 'status'];
}
