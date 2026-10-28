<?php

/** Tourfecto - SEO Strategy Task Model (Phase 14) @version 1.0.0 */
class SeoStrategyTask extends Model
{
    protected $table = 'seo_strategy_tasks';
    protected $fillable = ['plan_id', 'phase', 'week_label', 'title', 'description', 'priority', 'estimated_impact', 'difficulty', 'owner', 'status'];
}
