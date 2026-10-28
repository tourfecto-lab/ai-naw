<?php

/**
 * Tourfecto - Competitor Model
 * @version 1.1.0
 *
 * تصحيح (2026-07-14): الجدول كان موجودًا بالفعل في قاعدة البيانات
 * الحقيقية قبل أي دمج (تأكيد من ملف تصدير phpMyAdmin الحقيقي)، بأعمدة
 * مختلفة تمامًا عن الافتراض الأول. الأعمدة الحقيقية المؤكدة:
 * id, website_id, user_id, competitor_domain, competitor_name,
 * competitor_tripadvisor_url, notes, is_active, created_at.
 * لا يوجد عمود last_analyzed_at ولا url ولا name مباشرة.
 */
class Competitor extends Model
{
    protected $table = 'competitors';
    protected $fillable = [
        'user_id', 'website_id', 'competitor_domain', 'competitor_name',
        'competitor_tripadvisor_url', 'notes', 'is_active',
        // Phase 7 (Competitor Intelligence) - إضافي، الأعمدة القديمة فوق متلمستش
        'competitor_score', 'my_score', 'last_analyzed_at',
    ];
}
