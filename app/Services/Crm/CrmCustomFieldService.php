<?php

/**
 * Tourfecto - CRM Custom Field Service (المرحلة 12 - G2)
 * @version 1.0.0
 *
 * حقول مخصصة لكل كيان (contact/lead/deal/company) بدون تعديل سكيما
 * الجداول الأصلية (Additive - بند 40). التعريفات في جدول، والقيم في
 * جدول منفصل (EAV بسيط). يسمح لفرق المبيعات بإضافة حقول خاصة
 * بأعمالهم (مثال: "مصدر القرار"، "فئة السوق") - ميزة يملكها كل
 * المنافسين الكبار.
 *
 * طريقة الاستخدام: عرّف الحقل أولًا (createDefinition)، ثم اكتب/اقرأ
 * القيم (setValues/getValues). الدمج مع بيانات الكيان الأصلية يتم
 * في الـController أو الـFrontend عبر `attachToEntity()`.
 */
class CrmCustomFieldService
{
    /** إنشاء تعريف حقل مخصص */
    public function createDefinition(int $userId, array $data): CrmCustomField
    {
        $entityType = (string) ($data['entity_type'] ?? '');
        $fieldKey = $this->normalizeKey((string) ($data['field_key'] ?? ''));
        $label = trim((string) ($data['label'] ?? ''));
        $fieldType = (string) ($data['field_type'] ?? 'text');

        if (!in_array($entityType, CrmCustomField::ENTITY_TYPES, true)) {
            throw new Exception('نوع الكيان غير صالح (contact/lead/deal/company)', 422);
        }
        if ($fieldKey === '') {
            throw new Exception('مفتاح الحقل مطلوب (أحرف صغيرة وشرطات)', 422);
        }
        if ($label === '') {
            throw new Exception('تسمية الحقل مطلوبة', 422);
        }
        if (!in_array($fieldType, CrmCustomField::TYPES, true)) {
            throw new Exception('نوع الحقل غير صالح (text/number/date/select)', 422);
        }

        if ((new CrmCustomField())->findByKey($userId, $entityType, $fieldKey)) {
            throw new Exception('يوجد حقل بنفس المفتاح لهذا الكيان مسبقًا', 422);
        }

        $options = null;
        if ($fieldType === 'select') {
            $optionsRaw = $data['options'] ?? [];
            if (is_string($optionsRaw)) {
                $optionsRaw = preg_split('/\r\n|\r|\n/', $optionsRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }
            $options = json_encode(array_values(array_filter(array_map('trim', (array) $optionsRaw), fn ($v) => $v !== '')), JSON_UNESCAPED_UNICODE);
        }

        $field = new CrmCustomField([
            'user_id' => $userId,
            'entity_type' => $entityType,
            'field_key' => $fieldKey,
            'label' => $label,
            'field_type' => $fieldType,
            'options' => $options,
        ]);
        $field->save();
        return $field;
    }

    /** تعديل تعريف حقل (label/options فقط - المفتاح والنوع ثابتان بعد الإنشاء) */
    public function updateDefinition(int $userId, int $fieldId, array $data): CrmCustomField
    {
        $field = (new CrmCustomField())->findOwned($userId, $fieldId);
        if (!$field) {
            throw new Exception('الحقل غير موجود', 404);
        }
        if (isset($data['label'])) {
            $label = trim((string) $data['label']);
            if ($label === '') {
                throw new Exception('تسمية الحقل مطلوبة', 422);
            }
            $field->setAttribute('label', $label);
        }
        if (isset($data['options']) && $field->getAttribute('field_type') === 'select') {
            $optionsRaw = is_string($data['options']) ? preg_split('/\r\n|\r|\n/', $data['options'], -1, PREG_SPLIT_NO_EMPTY) : (array) $data['options'];
            $field->setAttribute('options', json_encode(array_values(array_filter(array_map('trim', $optionsRaw), fn ($v) => $v !== '')), JSON_UNESCAPED_UNICODE));
        }
        $field->save();
        return $field;
    }

    /** حذف تعريف (يحذف قيمه تلقائيًا عبر FK ON DELETE CASCADE) */
    public function deleteDefinition(int $userId, int $fieldId): bool
    {
        $field = (new CrmCustomField())->findOwned($userId, $fieldId);
        if (!$field) {
            throw new Exception('الحقل غير موجود', 404);
        }
        return $field->delete();
    }

    /** كتابة مجموعة قيم لكيان (upsert لكل حقل موجود) */
    public function setValues(int $userId, string $entityType, int $entityId, array $values): array
    {
        if (!in_array($entityType, CrmCustomField::ENTITY_TYPES, true)) {
            throw new Exception('نوع الكيان غير صالح', 422);
        }
        $saved = [];
        foreach ($values as $fieldKey => $rawValue) {
            $field = (new CrmCustomField())->findByKey($userId, $entityType, (string) $fieldKey);
            if (!$field) {
                continue; // تجاهل مفاتيح غير معرّفة بدل إخفاق الطلب بالكامل
            }
            $value = $this->sanitizeValue($field, $rawValue);
            $existing = (new CrmCustomFieldValue())->findForField($userId, $entityType, $entityId, (int) $field->getAttribute('id'));
            if ($existing) {
                if ($value === '') {
                    $existing->delete();
                    continue;
                }
                $existing->setAttribute('value', $value);
                $existing->save();
                $saved[(string) $field->getAttribute('field_key')] = $value;
            } else {
                if ($value === '') {
                    continue;
                }
                $fv = new CrmCustomFieldValue([
                    'user_id' => $userId,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'field_id' => (int) $field->getAttribute('id'),
                    'value' => $value,
                ]);
                $fv->save();
                $saved[(string) $field->getAttribute('field_key')] = $value;
            }
        }
        return $saved;
    }

    /** قراءة كل قيم كيان (بالتسمية الظاهرة) */
    public function getValues(int $userId, string $entityType, int $entityId): array
    {
        $fields = (new CrmCustomField())->forUser($userId, $entityType);
        $values = (new CrmCustomFieldValue())->allForEntity($userId, $entityType, $entityId);
        $out = [];
        foreach ($fields as $field) {
            $fieldId = (int) $field['id'];
            $out[] = [
                'field_key' => $field['field_key'],
                'label' => $field['label'],
                'field_type' => $field['field_type'],
                'value' => $values[$fieldId] ?? null,
            ];
        }
        return $out;
    }

    /** تعريفات الحساب (مع كل حقولها) حسب الكيان */
    public function definitions(int $userId, string $entityType = ''): array
    {
        return (new CrmCustomField())->forUser($userId, $entityType);
    }

    /** تعقيد قيمة حسب نوع الحقل (إرجاع '' للقيم الفارغة لتمكين الحذف) */
    private function sanitizeValue(CrmCustomField $field, $rawValue): string
    {
        $value = trim((string) $rawValue);
        if ($value === '') {
            return '';
        }
        $type = (string) $field->getAttribute('field_type');
        if ($type === 'number') {
            if (!is_numeric($value)) {
                throw new Exception('القيمة يجب أن تكون رقمًا للحقل: ' . $field->getAttribute('label'), 422);
            }
            return (string) $value;
        }
        if ($type === 'select') {
            $options = $field->optionsList();
            if (!empty($options) && !in_array($value, $options, true)) {
                throw new Exception('القيمة غير مدرجة في خيارات الحقل: ' . $field->getAttribute('label'), 422);
            }
            return $value;
        }
        return $value;
    }

    /** توحيد مفتاح الحقل: أحرف صغيرة + شرطات فقط */
    private function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9]+/', '-', $key);
        return trim($key, '-');
    }
}
