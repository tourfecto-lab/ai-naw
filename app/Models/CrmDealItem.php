<?php

/** Tourfecto - CRM Deal Item Model (المرحلة 13 - G3) @version 1.0.0 */
class CrmDealItem extends Model
{
    protected $table = 'crm_deal_items';
    protected $fillable = [
        'user_id', 'deal_id', 'product_id', 'product_name', 'description',
        'unit_price', 'quantity', 'discount', 'line_total',
    ];

    /** بنود صفقة (مع التأكد أنها مملوكة للحساب عبر الـdeal) */
    public function forDeal(int $userId, int $dealId): array
    {
        return $this->db->query(
            "SELECT * FROM crm_deal_items WHERE deal_id = ? AND user_id = ? ORDER BY id ASC",
            [$dealId, $userId]
        );
    }

    /** بند مملوك (عبر deal + user) */
    public function findOwned(int $userId, int $dealId, int $itemId): ?CrmDealItem
    {
        $rows = $this->db->query(
            "SELECT * FROM crm_deal_items WHERE id = ? AND deal_id = ? AND user_id = ? LIMIT 1",
            [$itemId, $dealId, $userId]
        );
        if (empty($rows)) {
            return null;
        }
        $model = new static($rows[0]);
        $model->original = $rows[0];
        return $model;
    }

    /** مجموع بنود صفقة - لإعادة حساب قيمة الصفقة */
    public function totalForDeal(int $userId, int $dealId): float
    {
        $rows = $this->db->query(
            "SELECT COALESCE(SUM(line_total), 0) AS total FROM crm_deal_items WHERE deal_id = ? AND user_id = ?",
            [$dealId, $userId]
        );
        return (float) ($rows[0]['total'] ?? 0);
    }
}
