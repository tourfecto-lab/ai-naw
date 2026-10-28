<?php

/**
 * Tourfecto - Revenue AI Forecast Model
 * سجل تاريخي لتوقعات الإيرادات المولّدة من RevenueForecastService.
 * @version 1.0.0
 */
class RevaiForecast extends Model
{
    protected $table = 'revai_forecasts';
    protected $fillable = [
        'user_id', 'period_type', 'period_start', 'period_end',
        'expected_revenue', 'low_estimate', 'high_estimate', 'confidence',
        'growth_trend', 'method', 'data_points_used', 'insufficient_data',
    ];
}
