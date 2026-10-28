<?php

/** Tourfecto - CRM Pipeline Stage Model @version 1.1.0 */
class CrmPipelineStage extends Model
{
    protected $table = 'crm_pipeline_stages';
    protected $fillable = [
        'agency_id', 'pipeline_id', 'name', 'slug', 'description',
        'sort_order', 'win_probability', 'is_won', 'is_lost', 'color',
    ];

    /**
     * مراحل مسار معيّن؛ لو $pipelineId=null يرجع المراحل العامة الافتراضية
     * (نفس سلوك CrmController::listPipelineStages الحالي).
     */
    public function forPipeline(?int $pipelineId): array
    {
        if ($pipelineId === null) {
            return self::groupDefaultStages($this->db->query(
                "SELECT * FROM `crm_pipeline_stages` WHERE `agency_id` IS NULL AND `pipeline_id` IS NULL ORDER BY `sort_order` ASC, `id` ASC"
            ));
        }
        return $this->db->query(
            "SELECT * FROM `crm_pipeline_stages` WHERE `pipeline_id` = ? ORDER BY `sort_order` ASC",
            [$pipelineId]
        );
    }
    /** Group identical default seeds without deleting rows or deal references. */
    public static function groupDefaultStages(array $rows): array
    {
        $groups = [];
        $result = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $default = empty($row['agency_id']) && empty($row['pipeline_id']) && trim((string) ($row['slug'] ?? '')) !== '';
            $identity = $default ? json_encode([
                (string) $row['slug'], (string) ($row['name'] ?? ''),
                (int) ($row['sort_order'] ?? 0), (int) ($row['is_won'] ?? 0),
                (int) ($row['is_lost'] ?? 0), (float) ($row['win_probability'] ?? 0),
                (string) ($row['color'] ?? ''), (string) ($row['description'] ?? ''),
            ], JSON_THROW_ON_ERROR) : null;
            if ($identity !== null && isset($groups[$identity])) {
                $index = $groups[$identity];
                if (!in_array($id, $result[$index]['stage_ids'], true)) $result[$index]['stage_ids'][] = $id;
                continue;
            }
            $row['stage_ids'] = [$id];
            if ($identity !== null) $groups[$identity] = count($result);
            $result[] = $row;
        }
        return $result;
    }

}
