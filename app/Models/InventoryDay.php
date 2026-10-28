<?php

/** Tourfecto - Inventory Model: التوفر اليومي لكل خدمة/جولة (Booking Engine - Phase 2) @version 1.0.0 */
class InventoryDay extends Model
{
    protected $table = 'inventory';
    protected $fillable = [
        'user_id', 'product_id', 'date', 'capacity', 'booked_count',
        'price_override', 'is_blocked',
    ];

    public function forProductAndDate(int $productId, string $date): ?InventoryDay
    {
        $rows = $this->db->query(
            'SELECT * FROM inventory WHERE product_id = ? AND date = ? LIMIT 1',
            [$productId, $date]
        );
        if (empty($rows)) {
            return null;
        }
        $model = new static($rows[0]);
        $model->original = $rows[0];

        return $model;
    }

    /** تقويم التوفر لمنتج معين بين تاريخين (لواجهة الحجز) */
    public function calendar(int $productId, string $fromDate, string $toDate): array
    {
        return $this->db->query(
            'SELECT date, capacity, booked_count, price_override, is_blocked
             FROM inventory WHERE product_id = ? AND date BETWEEN ? AND ?
             ORDER BY date ASC',
            [$productId, $fromDate, $toDate]
        );
    }
}
