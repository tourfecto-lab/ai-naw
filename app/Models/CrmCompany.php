<?php

/** Tourfecto - CRM Company Model @version 1.0.0 */
class CrmCompany extends Model
{
    protected $table = 'crm_companies';
    protected $fillable = [
        'user_id', 'name', 'industry', 'website', 'phone', 'email',
        'address', 'city', 'country', 'company_size', 'notes', 'tags',
    ];

    public function allForUser(int $userId, int $limit = 200): array
    {
        return $this->where(['user_id' => $userId], ['created_at' => 'DESC'], $limit);
    }
}
