<?php

/**
 * Tourfecto - Competitor Intelligence: Centralized Constants
 * @version 1.0.0
 *
 * مركز واحد لكل القوائم والقيم الثابتة اللي بتتكرر في الموديول (تصنيفات
 * المنافسين، مستويات الخطورة، وتيرة المراقبة، قنوات التنبيه...). الهدف إن
 * أي منطق مقارنة (in_array) أو عرض بيستخدم نفس المرجع بدل قيم نصية متناثرة
 * ممكن تتحرف في نسخة وتنسى في نسخة - ده بيقلل أخطاء الـ typo وبيخلي
 * معنى أي قيمة في الداتابيز واضح من مكان واحد.
 */
class CiConstants
{
    /** تصنيف المنافسين - زي ما هو مخزّن في عمود `category` بجدول competitors */
    public const CATEGORIES = ['direct', 'indirect', 'emerging', 'potential'];

    /** وتيرة المراقبة التلقائية - عمود `monitoring_frequency` */
    public const FREQUENCIES = ['daily', 'weekly', 'custom'];

    /** مستويات خطورة التنبيه/التغيير - عمود `severity` */
    public const SEVERITIES = ['info', 'low', 'medium', 'high', 'critical'];

    /** مستويات ثقة رؤى الذكاء الاصطناعي - عمود `confidence` */
    public const CONFIDENCE_LEVELS = ['high', 'medium', 'low'];

    /** قنوات إرسال التنبيه - عمود `alert_channels` (JSON array) */
    public const ALERT_CHANNELS = ['dashboard', 'email', 'in_app', 'webhook', 'slack'];

    /** حالات رؤى الفرص/التهديدات - عمود `status` بجدول ci_insights */
    public const INSIGHT_STATUSES = ['new', 'reviewed', 'dismissed'];

    /** ترتيب تصاعدي لمقارنة الخطورة (للـ sort) */
    public const SEVERITY_RANK = ['info' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

    /** الصفحات الفرعية المدعومة للمراقبة */
    public const PAGE_TYPES = ['homepage', 'pricing', 'products', 'services', 'offers', 'blog', 'contact', 'careers'];

    /** سقف عدد الصفوف في استدعاء واحد للـ bulk import (منع استعلامات ضخمة) */
    public const BULK_IMPORT_MAX_ROWS = 200;

    /** إرجاع هل القيمة ضمن قائمة مسموحة، مع default بديل لو مش موجودة */
    public static function within(array $allowed, mixed $value, mixed $default): mixed
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }
}
