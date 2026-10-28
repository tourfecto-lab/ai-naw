<?php

/**
 * Tourfecto - Business Location Service
 * @version 1.0.0
 *
 * يحتوي منطق العمل (Business Rules) الخاص بالمواقع، منفصل عن الـController
 * زي ما طُلب صراحة (Service layer) - أهم قاعدة هنا: مقر رئيسي (is_primary)
 * واحد بس لكل Business في أي وقت. الفحص ده مركزي هنا مش متكرر في أي مكان
 * تاني، عشان أي كود مستقبلي (Onboarding Wizard مثلاً) يستخدم نفس المنطق
 * بدل ما يعيد كتابته وممكن يفوّت الحالة الحدّية.
 */
class BusinessLocationService
{
    /**
     * إنشاء موقع جديد. لو is_primary=true، بيلغي أي primary سابق لنفس
     * الـBusiness أولًا (Transaction-safe: لو فشل أي جزء، محدش هيفضل في
     * حالة نصف متضاربة - أكتر من موقع primary في نفس الوقت).
     */
    public function create(int $businessId, array $data): BusinessLocation
    {
        $db = Database::getInstance();
        $wantsPrimary = !empty($data['is_primary']);

        try {
            $db->beginTransaction();

            if ($wantsPrimary) {
                $this->clearPrimaryFlag($businessId);
            } else {
                // أول Location للـBusiness تبقى Primary تلقائيًا حتى لو
                // محددش المستخدم كده صراحة - عشان محدش يتفاجئ إنه مفيش
                // أي موقع Primary خالص من غير ما يقصد.
                $existingCount = (new BusinessLocation())->where(['business_id' => $businessId], [], 1);
                if (empty($existingCount)) {
                    $wantsPrimary = true;
                }
            }

            $location = new BusinessLocation();
            $location->setAttribute('business_id', $businessId);
            foreach (['name', 'country_code', 'city', 'address', 'postal_code', 'latitude', 'longitude', 'phone', 'email'] as $field) {
                if (array_key_exists($field, $data)) {
                    $location->setAttribute($field, $data[$field]);
                }
            }
            $location->setAttribute('is_primary', $wantsPrimary ? 1 : 0);
            if (array_key_exists('opening_hours', $data) && is_array($data['opening_hours'])) {
                $location->setAttribute('opening_hours', json_encode($data['opening_hours']));
            }

            if ($location->save() === false) {
                $db->rollback();
                throw new \Exception('تعذر إنشاء الموقع');
            }

            $db->commit();
            return $location;
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    /** تحديث موقع موجود - نفس منطق الـPrimary أعلاه */
    public function update(BusinessLocation $location, array $data): bool
    {
        $db = Database::getInstance();
        $businessId = (int) $location->getAttribute('business_id');
        $wantsPrimary = array_key_exists('is_primary', $data) && !empty($data['is_primary']);

        try {
            $db->beginTransaction();

            if ($wantsPrimary) {
                $this->clearPrimaryFlag($businessId);
                $location->setAttribute('is_primary', 1);
            } elseif (array_key_exists('is_primary', $data)) {
                $location->setAttribute('is_primary', 0);
            }

            foreach (['name', 'country_code', 'city', 'address', 'postal_code', 'latitude', 'longitude', 'phone', 'email'] as $field) {
                if (array_key_exists($field, $data)) {
                    $location->setAttribute($field, $data[$field]);
                }
            }
            if (array_key_exists('opening_hours', $data) && is_array($data['opening_hours'])) {
                $location->setAttribute('opening_hours', json_encode($data['opening_hours']));
            }

            if ($location->save() === false) {
                $db->rollback();
                return false;
            }

            $db->commit();
            return true;
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    /**
     * حذف موقع. لو كان هو الـPrimary وفيه مواقع تانية، بنخلي أقدم موقع
     * متبقي هو الـPrimary الجديد تلقائيًا - عشان الـBusiness مايفضلش
     * من غير أي موقع Primary خالص من غير قصد.
     *
     * Competitive fix (2026-08-15): الحذف + إعادة الترقية للـPrimary الجديد
     * بيتنفذوا دلوقتي في Transaction واحدة - قبل كده الحذف كان بيتم وبعدين
     * الـPrimary الجديد بيتحفظ برة الـTransaction، فلو فشل الـsave بتاع
     * الترقية (أو حصل خطأ اتصال)، الـBusiness كان ممكن يفضل من غير أي موقع
     * Primary خالص - كسر للـInvariant اللي الخدمة دي مكلفة بضمانه. دلوقتي
     * لو فشل أي جزء، كله بيتراجع والحذف نفسه مش بيحصل.
     */
    public function delete(BusinessLocation $location): bool
    {
        $db = Database::getInstance();
        $businessId = (int) $location->getAttribute('business_id');
        $wasPrimary = (bool) $location->getAttribute('is_primary');

        try {
            $db->beginTransaction();

            if (!$location->delete()) {
                $db->rollback();
                return false;
            }

            if ($wasPrimary) {
                $remaining = (new BusinessLocation())->where(['business_id' => $businessId], ['id' => 'ASC'], 1);
                if (!empty($remaining)) {
                    $remaining[0]->setAttribute('is_primary', 1);
                    if ($remaining[0]->save() === false) {
                        $db->rollback();
                        return false;
                    }
                }
            }

            $db->commit();
            return true;
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    private function clearPrimaryFlag(int $businessId): void
    {
        Database::getInstance()->exec(
            'UPDATE business_locations SET is_primary = 0 WHERE business_id = ?',
            [$businessId]
        );
    }
}
