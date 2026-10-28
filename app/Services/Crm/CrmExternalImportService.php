<?php

/**
 * Tourfecto - CRM External Import Service (المرحلة 15 - G14)
 * @version 1.0.0
 *
 * استيراد جهات اتصال من CRMs خارجية (HubSpot / Zoho / Pipedrive / Freshsales)
 * - سد فجوة 2.10: "استيراد من أنظمة CRM أخرى: ❌".
 *
 * Additive خالص: لا يمس CrmImportExportService القائم إطلاقًا. يُضيف
 * طبقة "قوالب جاهزة" (presets) لرؤوس أعمدة الـCSV الخاصة بكل نظام خارجي
 * وتجري معاينة/استيراد عبر نفس دوال CrmImportExportService القائمة
 * (preview/commit) دون أي تعديل فيها.
 */
class CrmExternalImportService
{
    /** قوالب جاهزة: رؤوس أعمدة CSV الفعلية في كل نظام -> حقول CRM الداخلية */
    public const PRESETS = [
        'hubspot' => [
            'name' => 'HubSpot',
            'description_ar' => 'جهات الاتصال المُصدَّرة من HubSpot (Contacts)',
            'mapping' => [
                'First name' => 'name',
                'Last name' => 'name',
                'Email' => 'email',
                'Phone Number' => 'phone',
                'Country/Region' => 'country',
                'Contact owner' => 'source',
                'Create date' => 'notes',
            ],
        ],
        'zoho' => [
            'name' => 'Zoho CRM',
            'description_ar' => 'جهات الاتصال المُصدَّرة من Zoho CRM (Contacts)',
            'mapping' => [
                'First Name' => 'name',
                'Last Name' => 'name',
                'Email' => 'email',
                'Phone' => 'phone',
                'Mobile' => 'phone',
                'Country' => 'country',
                'Lead Source' => 'source',
            ],
        ],
        'pipedrive' => [
            'name' => 'Pipedrive',
            'description_ar' => 'الأشخاص المُصدَّرون من Pipedrive (Persons)',
            'mapping' => [
                'name' => 'name',
                'email' => 'email',
                'phone' => 'phone',
                'org_name' => 'notes',
                'add_time' => 'notes',
            ],
        ],
        'freshsales' => [
            'name' => 'Freshsales',
            'description_ar' => 'جهات الاتصال المُصدَّرة من Freshsales (Contacts)',
            'mapping' => [
                'First Name' => 'name',
                'Last Name' => 'name',
                'Email Address' => 'email',
                'Phone Number' => 'phone',
                'Country' => 'country',
                'Lead Source' => 'source',
            ],
        ],
    ];

    /** قائمة القوالب المتاحة (للـUI) */
    public function presets(): array
    {
        $list = [];
        foreach (self::PRESETS as $key => $preset) {
            $list[] = [
                'key' => $key,
                'name' => $preset['name'],
                'description_ar' => $preset['description_ar'],
                'mapping' => $preset['mapping'],
            ];
        }
        return $list;
    }

    /** تحويل صفوف من صيغة نظام خارجي إلى الصيغة الداخلية (name/email/phone/...) */
    public function normalizeRows(string $presetKey, array $rows): array
    {
        $preset = self::PRESETS[$presetKey] ?? null;
        if (!$preset) {
            throw new Exception('قالب استيراد غير معروف', 422);
        }
        $mapping = $preset['mapping'];

        $normalized = [];
        foreach ($rows as $row) {
            $mapped = [];
            foreach ($mapping as $csvColumn => $crmField) {
                $value = trim((string) ($row[$csvColumn] ?? ''));
                if ($value === '') {
                    continue;
                }
                if ($crmField === 'name' && isset($mapped['name'])) {
                    $mapped['name'] .= ' ' . $value;
                } elseif (isset($mapped[$crmField])) {
                    $mapped[$crmField] .= ' ' . $value;
                } else {
                    $mapped[$crmField] = $value;
                }
            }
            $normalized[] = $mapped;
        }
        return $normalized;
    }

    /**
     * معاينة CSV مُصدَّر من نظام خارجي (يُحلل الرؤوس تلقائيًا ويطابقها
     * مع القالب) - بدون أي كتابة في القاعدة (نفس مبدأ preview في
     * CrmImportExportService).
     */
    public function preview(int $userId, string $presetKey, string $csvContent): array
    {
        $preset = self::PRESETS[$presetKey] ?? null;
        if (!$preset) {
            throw new Exception('قالب استيراد غير معروف', 422);
        }

        $raw = $this->parseCsv($csvContent);
        if (empty($raw)) {
            throw new Exception('ملف CSV فارغ أو غير صالح');
        }
        $header = array_shift($raw);

        $rows = [];
        foreach ($raw as $row) {
            $assoc = [];
            foreach ($header as $i => $col) {
                $assoc[$col] = $row[$i] ?? '';
            }
            $rows[] = $assoc;
        }

        $normalized = $this->normalizeRows($presetKey, $rows);

        $results = [];
        $contactModel = new CrmContact();
        foreach ($normalized as $i => $mapped) {
            $errors = [];
            if (empty($mapped['name'])) {
                $errors[] = 'الحقل "الاسم" مطلوب';
            }
            if (!empty($mapped['email']) && !filter_var($mapped['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'بريد إلكتروني غير صالح';
            }

            $duplicates = [];
            if (empty($errors) && (!empty($mapped['email']) || !empty($mapped['phone']))) {
                $duplicates = $contactModel->findDuplicateCandidates($userId, $mapped['email'] ?? null, $mapped['phone'] ?? null);
            }

            $results[] = [
                'row_number' => $i + 2,
                'data' => $mapped,
                'valid' => empty($errors),
                'errors' => $errors,
                'duplicate_candidates' => array_map(fn ($d) => ['id' => $d['id'], 'name' => $d['name']], $duplicates),
            ];
        }

        return [
            'preset' => $presetKey,
            'total_rows' => count($results),
            'valid_rows' => count(array_filter($results, fn ($r) => $r['valid'])),
            'invalid_rows' => count(array_filter($results, fn ($r) => !$r['valid'])),
            'rows' => $results,
        ];
    }

    /** استيراد فعلي للصفوف المؤكَّدة (يُمرَّر إلى commit القائم) */
    public function commit(int $userId, array $rowsToImport, bool $skipDuplicates = true): array
    {
        return (new CrmImportExportService())->commit($userId, $rowsToImport, $skipDuplicates);
    }

    private function parseCsv(string $content): array
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($content));
        return array_map('str_getcsv', $lines);
    }
}
