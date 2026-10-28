<?php

/** Tourfecto - CRM Sales Goal Model (المرحلة 12 - G4) @version 1.0.0 */
class CrmSalesGoal extends Model
{
    protected $table = 'crm_sales_goals';
    protected $fillable = ['user_id', 'period', 'target_value'];

    /** هدف شهر محدد (أو null لو مفيش هدف مسجّل) - عزل تينانت */
    public function findForPeriod(int $userId, string $period): ?CrmSalesGoal
    {
        $rows = $this->db->query(
            "SELECT * FROM crm_sales_goals WHERE user_id = ? AND period = ? LIMIT 1",
            [$userId, $period]
        );
        if (empty($rows)) {
            return null;
        }
        $model = new static($rows[0]);
        $model->original = $rows[0];
        return $model;
    }

    /** كل أهداف الحساب مرتبة بالشهر (الأحدث أولًا) */
    public function allForUser(int $userId): array
    {
        return $this->db->query(
            "SELECT * FROM crm_sales_goals WHERE user_id = ? ORDER BY period DESC",
            [$userId]
        );
    }
}
