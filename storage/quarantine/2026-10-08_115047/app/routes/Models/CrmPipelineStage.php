<?php
/** Tourfecto - CRM Pipeline Stage Model @version 1.0.0 */
class CrmPipelineStage extends Model {
    protected $table = 'crm_pipeline_stages';
    protected $fillable = ['agency_id', 'name', 'slug', 'sort_order', 'win_probability', 'is_won', 'is_lost', 'color'];
}
