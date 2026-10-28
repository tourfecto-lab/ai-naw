<?php

/**
 * Tourfecto - Business Model
 * Business Profile - منفصل عن User Profile (Business Control Center Phase 2)
 * @version 1.0.0
 */
class Business extends Model
{
    protected $table = 'businesses';

    protected $fillable = [
        'owner_user_id',
        'legal_name',
        'trade_name',
        'logo_url',
        'description',
        'website_url',
        'business_email',
        'business_phone',
        'whatsapp_number',
        'country_code',
        'city',
        'address',
        'postal_code',
        'tourism_license_number',
        'tax_number',
        'business_type',
        'year_established',
        'primary_language',
        'supported_languages',
        'default_currency',
        'timezone',
    ];

    /**
     * أنواع الشركات المسموحة - في الكود مش DB ENUM عمدًا (Extensibility:
     * إضافة نوع جديد محتاجة سطر واحد هنا، مش Migration لتعديل ENUM).
     * @return array<string,string> key => English label (تترجم في الواجهة عبر i18n)
     */
    public static function allowedBusinessTypes(): array
    {
        return [
            'travel_agency' => 'Travel Agency',
            'tour_operator' => 'Tour Operator',
            'dmc' => 'DMC (Destination Management Company)',
            'hotel' => 'Hotel',
            'cruise' => 'Cruise',
            'transportation' => 'Transportation',
            'dmo' => 'Destination Management Organization',
            'travel_consultant' => 'Travel Consultant',
            'other' => 'Other',
        ];
    }

    /**
     * فك تشفير supported_languages من JSON مخزّن لمصفوفة PHP حقيقية.
     * العمود بيتخزن كـJSON string (MySQL JSON column)، لكن لما بيترجع من
     * الاستعلام بيكون string خام - الدالة دي بتحوّله لمصفوفة قابلة
     * للاستخدام المباشر في الكود، بدل ما كل Controller يعمل json_decode
     * بنفسه ويكرر نفس المنطق.
     * @return string[]
     */
    public function getSupportedLanguages(): array
    {
        $raw = $this->getAttribute('supported_languages');
        if (empty($raw)) {
            return [];
        }
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * هل المستخدم ده Owner للـBusiness ده؟ فحص ملكية مركزي - أي Controller
     * محتاج يتحقق من صلاحية الوصول لازم يستخدم الدالة دي بدل ما يقارن
     * owner_user_id يدويًا في كل مكان (نفس السبب اللي هيتحول لاحقًا
     * لـPolicy/Gate حقيقي في Phase 11 - RBAC، لكن دلوقتي في مرحلة
     * Business Profile الأساسية، مركزية الفحص هي الأهم).
     */
    public function isOwnedBy(int $userId): bool
    {
        return (int) $this->getAttribute('owner_user_id') === $userId;
    }
}
