<?php

/** Tourfecto - CRM Product Model (المرحلة 13 - G3) @version 1.0.0 */
class CrmProduct extends Model
{
    protected $table = 'crm_products';
    protected $fillable = ['user_id', 'website_id', 'tour_slug', 'name', 'description', 'sku', 'price', 'currency', 'is_active'];

    /** منتجات الحساب (اختيار بالحالة) */
    public function forUser(int $userId, bool $onlyActive = false): array
    {
        $sql = "SELECT * FROM crm_products WHERE user_id = ?";
        $params = [$userId];
        if ($onlyActive) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY name ASC";
        return $this->db->query($sql, $params);
    }

    /** منتج مملوك للحساب */
    public function findOwned(int $userId, int $productId): ?CrmProduct
    {
        $rows = $this->db->query(
            "SELECT * FROM crm_products WHERE id = ? AND user_id = ? LIMIT 1",
            [$productId, $userId]
        );
        if (empty($rows)) {
            return null;
        }
        $model = new static($rows[0]);
        $model->original = $rows[0];
        return $model;
    }
}
