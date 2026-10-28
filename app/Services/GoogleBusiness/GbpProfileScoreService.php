<?php

/**
 * Tourfecto - Google Business Profile Completeness Score
 * Phase 9 (Google Business Agent): درجة حتمية (deterministic) 0-100 مبنية
 * على وجود/غياب عناصر Profile أساسية - نفس المدخلات = نفس النتيجة دايمًا
 * (بدل ما نسأل الذكاء الاصطناعي "اديني رقم" وممكن يختلف كل مرة).
 * بتاخد شكل الـarray اللي GoogleBusinessAPI::getLocation() بيرجّعه (بعد
 * التوسيع في Phase 9) وترجّع Score + قايمة عناصر ناقصة + توصية لكل واحدة.
 * @version 1.0.0
 */
class GbpProfileScoreService
{
    /**
     * @param array $location نفس شكل ['location' => [...]] الراجع من GoogleBusinessAPI::getLocation()
     * @return array{score:int, max_score:int, missing:array, complete:array}
     */
    public function calculateCompletenessScore(array $location): array
    {
        $checks = [
            'name' => ['weight' => 15, 'label' => 'اسم النشاط', 'recommendation' => 'أضف اسم النشاط الرسمي - أول حاجة يشوفها العميل.'],
            'address' => ['weight' => 15, 'label' => 'العنوان', 'recommendation' => 'أضف العنوان الكامل - ضروري لظهورك في نتائج البحث المحلي (Local Pack).'],
            'phone' => ['weight' => 15, 'label' => 'رقم الهاتف', 'recommendation' => 'أضف رقم هاتف صحيح - العملاء بيتوقعوا يتواصلوا بسهولة قبل الحجز.'],
            'website' => ['weight' => 10, 'label' => 'رابط الموقع', 'recommendation' => 'اربط موقعك الإلكتروني - بيزود المصداقية ويوجّه زيارات حقيقية.'],
            'primary_category' => ['weight' => 15, 'label' => 'التصنيف الأساسي', 'recommendation' => 'حدد تصنيف أساسي دقيق (مثلاً "Tour operator" مش "Business") - ده أكتر عامل مؤثر في ظهورك للبحث المحلي.'],
            'additional_categories' => ['weight' => 5, 'label' => 'تصنيفات إضافية', 'recommendation' => 'أضف تصنيفات إضافية دقيقة لتوسيع الكلمات اللي هتظهر بيها.'],
            'regular_hours' => ['weight' => 10, 'label' => 'ساعات العمل', 'recommendation' => 'أضف ساعات العمل - نسبة كبيرة من العملاء بيستبعدوا أي نشاط من غير ساعات عمل واضحة.'],
            'description' => ['weight' => 10, 'label' => 'الوصف', 'recommendation' => 'اكتب وصف واضح للنشاط يشمل الخدمات الأساسية - فرصة SEO حقيقية لو مكتوب كويس.'],
            'has_coordinates' => ['weight' => 5, 'label' => 'الموقع على الخريطة', 'recommendation' => 'اربط موقع دقيق على الخريطة - يساعد في الاتجاهات ونتائج "قريب مني".'],
        ];

        $score = 0;
        $missing = [];
        $complete = [];

        foreach ($checks as $key => $meta) {
            $value = $location[$key] ?? null;
            $isPresent = match (true) {
                $key === 'additional_categories' => is_array($value) && !empty(array_filter($value)),
                $key === 'has_coordinates' => $value === true,
                default => !empty($value),
            };

            if ($isPresent) {
                $score += $meta['weight'];
                $complete[] = $meta['label'];
            } else {
                $missing[] = [
                    'field' => $key,
                    'label' => $meta['label'],
                    'weight' => $meta['weight'],
                    'recommendation' => $meta['recommendation'],
                ];
            }
        }

        // أولوية الناقصين حسب الوزن (الأكبر تأثيرًا أولًا) - يجاوب على "أنهي حاجة أعملها الأول"
        usort($missing, fn ($a, $b) => $b['weight'] <=> $a['weight']);

        return [
            'score' => $score,
            'max_score' => 100,
            'missing' => $missing,
            'complete' => $complete,
        ];
    }
}
