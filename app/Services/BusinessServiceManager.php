<?php

/**
 * Tourfecto - Business Service Manager (Service layer)
 * @version 1.0.0
 *
 * اسم الكلاس BusinessServiceManager مش BusinessServiceService عمدًا -
 * تجنبًا للالتباس مع BusinessService (الـModel) في القراءة والاستخدام.
 *
 * المسؤولية الوحيدة هنا: توليد slug فريد لكل خدمة **داخل نطاق نفس
 * الـBusiness فقط** (مش عالميًا - راجع تعليق الـUNIQUE KEY في الـMigration).
 */
class BusinessServiceManager
{
    /**
     * يولّد slug من الاسم، ولو فيه تعارض مع خدمة تانية لنفس الـBusiness،
     * يضيف رقم تسلسلي (-2, -3...) لحد ما يلاقي واحد فاضي. بيستبعد
     * الخدمة الحالية نفسها لو بنعمل Update (عشان نفس الاسم القديم
     * يفضل شغال من غير ما يتصادم مع نفسه).
     */
    public function generateUniqueSlug(int $businessId, string $name, ?int $excludeServiceId = null): string
    {
        $base = $this->slugify($name);
        if ($base === '') {
            $base = 'service';
        }

        $slug = $base;
        $suffix = 1;

        while ($this->slugExists($businessId, $slug, $excludeServiceId)) {
            $suffix++;
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }

    protected function slugExists(int $businessId, string $slug, ?int $excludeServiceId): bool
    {
        $conditions = ['business_id' => $businessId, 'slug' => $slug];
        $matches = (new BusinessService())->where($conditions, [], 2);

        foreach ($matches as $match) {
            if ($excludeServiceId !== null && (int) $match->getAttribute('id') === $excludeServiceId) {
                continue; // ده نفس السجل اللي بنعدّله، مش تعارض حقيقي
            }
            return true;
        }

        return false;
    }

    private function slugify(string $text): string
    {
        // نقل عمومي بسيط: يدعم عربي وإنجليزي - بيشيل أي حرف مش
        // حروف/أرقام/مسافة/شرطة، ويحوّل المسافات لشرطات، ويوحّد
        // الشرطات المتتالية (لو جت من " - " أو مسافات مضاعفة).
        $text = trim($text);
        $text = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $text);
        $text = preg_replace('/[\s_]+/u', '-', $text);
        $text = preg_replace('/-+/u', '-', $text);
        $text = trim($text, '-');
        return mb_strtolower($text);
    }
}
