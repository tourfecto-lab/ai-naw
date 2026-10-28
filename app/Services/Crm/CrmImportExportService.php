<?php

/**
 * Tourfecto - CRM Import / Export Service (بند 20/21)
 * @version 1.0.0
 *
 * الاستيراد يتم على مرحلتين إلزاميًا: preview() أولًا (تحقق + معاينة +
 * اكتشاف تكرار بدون أي كتابة في القاعدة)، ثم commit() فقط بعد موافقة
 * المستخدم الصريحة على الصفوف المطلوب استيرادها. لا يوجد استيراد صامت
 * لبيانات تالفة (بند 20).
 */
class CrmImportExportService
{
    private const REQUIRED_FIELDS = ['name'];
    private const ALLOWED_FIELDS = ['name', 'email', 'phone', 'country', 'language', 'source', 'notes'];

    /** يحلل CSV نصي، يتحقق من الصفوف، ويكتشف التكرار المحتمل - بدون حفظ */
    public function preview(int $userId, string $csvContent, array $fieldMapping): array
    {
        $rows = $this->parseCsv($csvContent);
        if (empty($rows)) {
            throw new Exception('ملف CSV فارغ أو غير صالح');
        }

        $header = array_shift($rows);
        $results = [];
        $contactModel = new CrmContact();

        foreach ($rows as $i => $row) {
            $mapped = [];
            foreach ($fieldMapping as $csvColumn => $crmField) {
                if (!in_array($crmField, self::ALLOWED_FIELDS, true)) {
                    continue;
                }
                $colIndex = array_search($csvColumn, $header, true);
                $mapped[$crmField] = $colIndex !== false ? trim((string) ($row[$colIndex] ?? '')) : null;
            }

            $errors = [];
            foreach (self::REQUIRED_FIELDS as $required) {
                if (empty($mapped[$required])) {
                    $errors[] = "الحقل \"{$required}\" مطلوب";
                }
            }
            if (!empty($mapped['email']) && !filter_var($mapped['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'بريد إلكتروني غير صالح';
            }

            $duplicates = [];
            if (empty($errors) && (!empty($mapped['email']) || !empty($mapped['phone']))) {
                $duplicates = $contactModel->findDuplicateCandidates($userId, $mapped['email'] ?? null, $mapped['phone'] ?? null);
            }

            $results[] = [
                'row_number' => $i + 2, // +1 للهيدر +1 للفهرسة من 1
                'data' => $mapped,
                'valid' => empty($errors),
                'errors' => $errors,
                'duplicate_candidates' => array_map(fn ($d) => ['id' => $d['id'], 'name' => $d['name']], $duplicates),
            ];
        }

        return [
            'total_rows' => count($results),
            'valid_rows' => count(array_filter($results, fn ($r) => $r['valid'])),
            'invalid_rows' => count(array_filter($results, fn ($r) => !$r['valid'])),
            'rows' => $results,
        ];
    }

    /**
     * إصدار Background لـ`commit()` (بند 37) - بدل انتظار المستخدم لحد ما
     * الاستيراد الكبير يخلص Synchronous، بينشئ سجل متابعة (`crm_import_batches`)
     * ويُدخل الشغل فعليًا في نظام الـQueue الموجود بالفعل بالمشروع
     * (`Core/Queue/QueueManager.php` - بند 33: لا تنشئ أنظمة مكررة).
     * `commit()` القديمة (Synchronous) لسه موجودة زي ما هي بدون أي تعديل -
     * دي إضافة بديلة اختيارية، مش استبدال.
     */
    public function commitAsync(int $userId, array $rowsToImport, bool $skipDuplicates = true): CrmImportBatch
    {
        $batch = new CrmImportBatch([
            'user_id' => $userId, 'status' => 'pending', 'total_rows' => count($rowsToImport),
        ]);
        $batch->save();

        if (function_exists('enqueue')) {
            enqueue(CrmImportContactsJob::class, [
                'batch_id' => (int) $batch->getAttribute('id'),
                'user_id' => $userId,
                'rows' => $rowsToImport,
                'skip_duplicates' => $skipDuplicates,
            ]);
        } else {
            // Fallback أمين: لو نظام الـQueue مش متاح لأي سبب، نفّذ فورًا
            // بدل ما نسيب Batch عالق في pending للأبد بدون تنفيذ.
            Logger::warning('CrmImportExportService: enqueue() غير متاح - تنفيذ الاستيراد Synchronous كـFallback');
            (new CrmImportContactsJob())->handle([
                'batch_id' => (int) $batch->getAttribute('id'), 'user_id' => $userId,
                'rows' => $rowsToImport, 'skip_duplicates' => $skipDuplicates,
            ]);
        }

        return $batch;
    }

    public function importBatchStatus(int $userId, int $batchId): ?CrmImportBatch
    {
        $batch = (new CrmImportBatch())->find($batchId);
        if (!$batch || (int) $batch->getAttribute('user_id') !== $userId) {
            return null;
        }
        return $batch;
    }

    /** يستورد فعليًا فقط الصفوف اللي المستخدم أكّد استيرادها بعد المعاينة */
    public function commit(int $userId, array $rowsToImport, bool $skipDuplicates = true): array
    {
        $imported = 0;
        $skipped = 0;
        $contactService = new CrmContactService();

        foreach ($rowsToImport as $row) {
            if (empty($row['name'])) {
                $skipped++;
                continue;
            }
            if ($skipDuplicates && (!empty($row['email']) || !empty($row['phone']))) {
                $dupes = (new CrmContact())->findDuplicateCandidates($userId, $row['email'] ?? null, $row['phone'] ?? null);
                if (!empty($dupes)) {
                    $skipped++;
                    continue;
                }
            }
            $row['source'] = $row['source'] ?? 'import';
            $contactService->create($userId, $row);
            $imported++;
        }

        ActivityLog::record('crm', 'contacts.imported', [
            'user_id' => $userId, 'meta' => ['imported' => $imported, 'skipped' => $skipped],
        ]);

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    public function exportContactsCsv(int $userId): string
    {
        $contacts = (new CrmContact())->allForUser($userId, 100000);
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['id', 'name', 'email', 'phone', 'country', 'language', 'source', 'status', 'created_at']);
        foreach ($contacts as $c) {
            fputcsv($handle, [
                $c->getAttribute('id'), $c->getAttribute('name'), $c->getAttribute('email'),
                $c->getAttribute('phone'), $c->getAttribute('country'), $c->getAttribute('language'),
                $c->getAttribute('source'), $c->getAttribute('status'), $c->getAttribute('created_at'),
            ]);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }

    /** Export لصفقات الحساب (بند 20 - استكمال المرحلة 9، كان Contacts بس قبل كده) */
    public function exportDealsCsv(int $userId): string
    {
        $db = Database::getInstance();
        $deals = $db->query(
            "SELECT d.id, d.title, d.value, d.currency, d.status, s.name AS stage_name,
                    d.expected_close_date, d.closed_at, d.created_at
             FROM crm_deals d JOIN crm_pipeline_stages s ON s.id = d.stage_id
             WHERE d.owner_user_id = ? ORDER BY d.created_at DESC",
            [$userId]
        );
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['id', 'title', 'value', 'currency', 'status', 'stage', 'expected_close_date', 'closed_at', 'created_at']);
        foreach ($deals as $d) {
            fputcsv($handle, [
                $d['id'], $d['title'], $d['value'], $d['currency'], $d['status'],
                $d['stage_name'], $d['expected_close_date'], $d['closed_at'], $d['created_at'],
            ]);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }

    /** Export لمهام الحساب (بند 20) */
    public function exportTasksCsv(int $userId): string
    {
        $tasks = (new CrmTask())->allForUser($userId, 100000);
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['id', 'title', 'priority', 'status', 'due_date', 'completed_at', 'created_at']);
        foreach ($tasks as $t) {
            fputcsv($handle, [
                $t->getAttribute('id'), $t->getAttribute('title'), $t->getAttribute('priority'),
                $t->getAttribute('status'), $t->getAttribute('due_date'), $t->getAttribute('completed_at'), $t->getAttribute('created_at'),
            ]);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }

    /** Export لـLeads الحساب (بند 20) */
    public function exportLeadsCsv(int $userId): string
    {
        $db = Database::getInstance();
        $leads = $db->query(
            "SELECT l.id, c.name AS contact_name, c.email AS contact_email, c.phone AS contact_phone,
                    l.source, l.status, l.priority, l.score, l.value, l.currency, l.created_at
             FROM crm_leads l JOIN crm_contacts c ON c.id = l.contact_id
             WHERE c.user_id = ? ORDER BY l.created_at DESC",
            [$userId]
        );
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['id', 'contact_name', 'contact_email', 'contact_phone', 'source', 'status', 'priority', 'score', 'value', 'currency', 'created_at']);
        foreach ($leads as $l) {
            fputcsv($handle, [
                $l['id'], $l['contact_name'], $l['contact_email'], $l['contact_phone'], $l['source'],
                $l['status'], $l['priority'], $l['score'], $l['value'], $l['currency'], $l['created_at'],
            ]);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }

    private function parseCsv(string $content): array
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($content));
        return array_map('str_getcsv', $lines);
    }
}
