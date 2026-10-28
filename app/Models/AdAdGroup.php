<?php

/**
 * Tourfecto - Ad Group Model
 * مجموعة إعلانية تنظيمية محلية داخل حملة (بند 6 من طلب Ads Frontend) -
 * راجع تعليق migration 2026_08_11_000044 لتوضيح النطاق (تنظيم محلي، مش
 * مزامنة حقيقية مع Ad Set/Ad Group على المنصات الخارجية).
 * @version 1.0.0
 */
class AdAdGroup extends Model
{
    protected $table = 'ad_ad_groups';
    protected $fillable = ['campaign_id', 'name', 'status', 'budget_allocation_pct'];
}
