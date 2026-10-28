<?php

/**
 * Tourfecto - Tax Rule Model
 * @version 1.0.0
 */
class TaxRule extends Model
{
    protected $table = 'tax_rules';

    protected $fillable = ['country_code', 'tax_type', 'tax_rate_percent', 'is_active'];
}
