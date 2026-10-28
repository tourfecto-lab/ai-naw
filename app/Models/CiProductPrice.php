<?php

/**
 * Tourfecto - Competitor Intelligence: Product Price Model (G7)
 * @version 1.0.0
 *
 * سجل أسعار منتجات/SKUs المنافس عبر الزمن مع العملة ومصدر الرصد.
 */
class CiProductPrice extends Model
{
    protected $table = 'ci_product_prices';
    protected $fillable = [
        'competitor_id', 'product_name', 'price', 'currency',
        'source_url', 'page_type', 'detected_at',
    ];
}
