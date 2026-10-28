<?php

/**
 * Tourfecto - Website Niches Config (v2.0.0)
 * قائمة مركزية لكل المجالات السياحية المدعومة في منشئ المواقع، مستخدمة في:
 * - خطوة اختيار المجال والتصميم داخل شات الإنشاء
 * - عرض الموقع العام (تسمية العناصر: رحلة/غرفة/باقة... وأيقونة الهيدر)
 * - لوحة تحكم كل موقع (SiteDashboardController)
 * إضافة مجال جديد = سطر واحد هنا + تصميمات في جدول website_templates.
 */
class WebsiteNiches
{
    /**
     * items_key: اسم مفتاح العناصر جوه content_json (rooms للفنادق، tours للباقي)
     * item_label: اسم العنصر المفرد باللغة العربية (يتغيّر في كل الواجهات والمعاينة)
     * icon: إيموجي يظهر في نافبار الموقع المولّد ولوحة التحكم
     */
    public const NICHES = [
        'tours' => ['items_key' => 'tours', 'item_label' => 'رحلة', 'icon' => '🌍', 'name_ar' => 'رحلات سياحية عامة'],
        'hotels' => ['items_key' => 'rooms', 'item_label' => 'غرفة', 'icon' => '🏨', 'name_ar' => 'فنادق وإقامة'],
        'nile_cruises' => ['items_key' => 'tours', 'item_label' => 'رحلة نيلية', 'icon' => '🚢', 'name_ar' => 'رحلات نيلية'],
        'desert_safari' => ['items_key' => 'tours', 'item_label' => 'رحلة سفاري', 'icon' => '🏜️', 'name_ar' => 'سفاري صحراوي'],
        'diving' => ['items_key' => 'tours', 'item_label' => 'رحلة غوص', 'icon' => '🤿', 'name_ar' => 'غوص والبحر الأحمر'],
        'religious_tourism' => ['items_key' => 'tours', 'item_label' => 'برنامج', 'icon' => '🕌', 'name_ar' => 'سياحة دينية وعمرة'],
        'travel_agency' => ['items_key' => 'tours', 'item_label' => 'باقة سفر', 'icon' => '✈️', 'name_ar' => 'مكتب سياحة وسفر'],
        'city_tours' => ['items_key' => 'tours', 'item_label' => 'جولة', 'icon' => '🏛️', 'name_ar' => 'جولات داخل المدن'],
        'camping' => ['items_key' => 'tours', 'item_label' => 'رحلة تخييم', 'icon' => '⛺', 'name_ar' => 'تخييم ورحلات برية'],
        'boat_trips' => ['items_key' => 'tours', 'item_label' => 'رحلة بحرية', 'icon' => '🛥️', 'name_ar' => 'رحلات بحرية ويخوت'],
        'car_rental' => ['items_key' => 'tours', 'item_label' => 'سيارة', 'icon' => '🚗', 'name_ar' => 'تأجير سيارات سياحية'],
    ];

    public static function get(string $nicheKey): array
    {
        return self::NICHES[$nicheKey] ?? self::NICHES['tours'];
    }

    public static function itemsKey(string $nicheKey): string
    {
        return self::get($nicheKey)['items_key'];
    }

    public static function isHotelLike(string $nicheKey): bool
    {
        return self::itemsKey($nicheKey) === 'rooms';
    }

    public static function options(): array
    {
        $out = [];
        foreach (self::NICHES as $key => $cfg) {
            $out[$key] = $cfg['name_ar'] . ' ' . $cfg['icon'];
        }
        return $out;
    }
}
