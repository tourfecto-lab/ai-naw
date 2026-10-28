<?php

/**
 * Tourfecto - AutoFix Capability
 *
 * أي إصلاحات من نتيجة تدقيق الموقع ينفع تتنفّذ تلقائي على موقع العميل، وبأنهي حقل وبأنهي مستوى خطورة.
 * الباقي (سرعة الاستجابة، حجم الصفحة، الروابط المكسورة، أصول خارجية...) محتاج وصول للسيرفر أو تعديل محتوى،
 * فبيفضل يدوي بالكود الجاهز.
 *
 * ملحوظة مهمة: الأكواد الجاهزة في wo_fixes.code_snippet قوالب فيها كلام وهمي ("اسم البراند"، "yourpage"...)،
 * فعمرنا ما بنطبّقها زي ما هي: القيمة الحقيقية بتتولّد من صفحة العميل (FixValueBuilder).
 *
 * @version 1.0.0
 */
class FixCapability
{
    /** check_key => [field, risk, label] */
    public const MAP = [
        'title_tag'        => ['title',       'low',    'عنوان الصفحة (title)'],
        'meta_description' => ['description', 'low',    'وصف الصفحة (meta description)'],
        'canonical_tag'    => ['canonical',   'low',    'الرابط الأساسي (canonical)'],
        'robots_meta'      => ['robots',      'low',    'وسم robots'],
        'viewport'         => ['viewport',    'low',    'وسم viewport للموبايل'],
        'html_lang'        => ['lang',        'low',    'لغة الصفحة (lang)'],
        'open_graph'       => ['og',          'low',    'وسوم المشاركة (Open Graph)'],
        'structured_data'  => ['jsonld',      'medium', 'بيانات الشركة المنظّمة (Schema)'],
        'hsts'             => ['hsts',        'high',   'ترويسة HSTS'],
        'llms_txt'         => ['llms_txt',    'low',    'ملف llms.txt لمحركات الذكاء الاصطناعي'],
    ];

    /** اسم الحقل في جدول auto_seo_applied_fixes (متوافق مع embed.js القديم لو في تقاطع) */
    public const DB_FIELD = [
        'title' => 'seo_title', 'description' => 'seo_description', 'canonical' => 'canonical_url', 'robots' => 'robots_meta',
        'viewport' => 'viewport', 'lang' => 'html_lang', 'og' => 'og_tags', 'jsonld' => 'json_ld', 'hsts' => 'hsts', 'llms_txt' => 'llms_txt',
    ];

    /** @return array{field:string, db_field:string, risk:string, label:string}|null */
    public static function forCheck(?string $checkKey): ?array
    {
        if ($checkKey === null || !isset(self::MAP[$checkKey])) {
            return null;
        }
        [$field, $risk, $label] = self::MAP[$checkKey];
        return ['field' => $field, 'db_field' => self::DB_FIELD[$field], 'risk' => $risk, 'label' => $label];
    }

    /** للواجهة: كل القدرات (من غير أي بيانات) */
    public static function all(): array
    {
        $out = [];
        foreach (self::MAP as $key => $_) {
            $out[$key] = self::forCheck($key);
        }
        return $out;
    }
}
