<?php

/** Tourfecto - CRM Import Batch Model (بند 37) @version 1.0.0 */
class CrmImportBatch extends Model
{
    protected $table = 'crm_import_batches';
    protected $fillable = ['user_id', 'status', 'total_rows', 'imported_count', 'skipped_count', 'error', 'completed_at'];
}
