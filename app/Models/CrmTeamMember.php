<?php

/** Tourfecto - CRM Team Member Model (بند 30) @version 1.0.0 */
class CrmTeamMember extends Model
{
    protected $table = 'crm_team_members';
    protected $fillable = ['tenant_user_id', 'member_user_id', 'role', 'added_by_user_id'];

    public function membershipFor(int $userId): ?self
    {
        $rows = $this->where(['member_user_id' => $userId], [], 1);
        return $rows[0] ?? null;
    }

    public function forTenant(int $tenantUserId): array
    {
        return $this->db->query(
            "SELECT tm.*, u.first_name, u.last_name, u.email
             FROM crm_team_members tm JOIN users u ON u.id = tm.member_user_id
             WHERE tm.tenant_user_id = ? ORDER BY tm.created_at ASC",
            [$tenantUserId]
        );
    }
}
