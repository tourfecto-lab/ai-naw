<?php

/**
 * Tourfecto - Email AB Test Model (اختبار أ/ب لحملات البريد)
 * @version 1.0.0
 */
class EmailAbTest extends Model
{
    protected $table = 'email_ab_tests';
    protected $fillable = [
        'user_id', 'name', 'base_campaign_id', 'variant_a_id', 'variant_b_id',
        'split_percent', 'metric', 'status', 'winner', 'winner_at',
        'holdout_percent', 'auto_winner', 'test_hours', 'started_at', 'holdout_campaign_id', 'holdout_sent_at',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_RUNNING = 'running';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_CANCELLED = 'cancelled';

    public const METRIC_OPEN = 'open';
    public const METRIC_CLICK = 'click';

    public const WINNER_A = 'a';
    public const WINNER_B = 'b';

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT => 'مسودة',
            self::STATUS_RUNNING => 'قيد التشغيل',
            self::STATUS_FINISHED => 'منتهي',
            self::STATUS_CANCELLED => 'ملغي',
        ];
    }
}
