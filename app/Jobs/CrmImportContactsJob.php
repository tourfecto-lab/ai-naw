<?php

/**
 * Tourfecto - CRM Import Contacts Job (بند 37)
 * @version 1.0.0
 *
 * تفعيل Background فعلي لاستيراد جهات الاتصال - نفس نمط `GenerateMediaJob`
 * الموجود بالفعل بالضبط (Status Tracking + Notification + Error Handling).
 * المنطق الفعلي للاستيراد (`CrmImportExportService::commit()`) لم يتغيّر -
 * هذا الـJob غلاف Background حوله بس.
 */
class CrmImportContactsJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        $batchId = (int) ($payload['batch_id'] ?? 0);
        $userId = (int) ($payload['user_id'] ?? 0);
        $rows = $payload['rows'] ?? [];
        $skipDuplicates = (bool) ($payload['skip_duplicates'] ?? true);

        $batch = (new CrmImportBatch())->find($batchId);
        if (!$batch) {
            throw new Exception("CrmImportBatch #{$batchId} غير موجود");
        }

        $batch->setAttribute('status', 'processing');
        $batch->save();

        try {
            $result = (new CrmImportExportService())->commit($userId, $rows, $skipDuplicates);

            $batch->setAttribute('status', 'completed');
            $batch->setAttribute('imported_count', $result['imported']);
            $batch->setAttribute('skipped_count', $result['skipped']);
            $batch->setAttribute('completed_at', date('Y-m-d H:i:s'));
            $batch->save();

            if (class_exists('Notification')) {
                Notification::notify(
                    $userId,
                    'crm_import_completed',
                    'اكتمل استيراد جهات الاتصال',
                    "تم استيراد {$result['imported']} جهة اتصال ({$result['skipped']} تم تخطيها).",
                    '/crm/contacts'
                );
            }

            Logger::info('CRM Import Completed', ['batch_id' => $batchId, 'imported' => $result['imported'], 'skipped' => $result['skipped']]);
        } catch (Throwable $e) {
            $batch->setAttribute('status', 'failed');
            $batch->setAttribute('error', $e->getMessage());
            $batch->setAttribute('completed_at', date('Y-m-d H:i:s'));
            $batch->save();

            Logger::error('CRM Import Failed', ['batch_id' => $batchId, 'message' => $e->getMessage()]);
        }
    }
}
