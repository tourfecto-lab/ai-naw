<?php
require_once __DIR__ . '/CrmPaginationHelper.php';
/** Tourfecto - CRM Company Service @version 1.1.0 */
class CrmCompanyService
{
    use CrmPaginationHelper;

    public function create(int $userId, array $data): CrmCompany
    {
        if (empty($data['name'])) {
            throw new Exception('اسم الشركة مطلوب');
        }
        $company = new CrmCompany([
            'user_id' => $userId,
            'name' => $data['name'],
            'industry' => $data['industry'] ?? null,
            'website' => $data['website'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'country' => $data['country'] ?? null,
            'company_size' => $data['company_size'] ?? null,
            'notes' => $data['notes'] ?? null,
            'tags' => isset($data['tags']) ? json_encode($data['tags'], JSON_UNESCAPED_UNICODE) : null,
        ]);
        $company->save();

        ActivityLog::record('crm', 'company.created', [
            'user_id' => $userId, 'subject_type' => 'crm_companies', 'subject_id' => (int) $company->getAttribute('id'),
        ]);

        return $company;
    }

    public function findOwned(int $userId, int $companyId): CrmCompany
    {
        $company = (new CrmCompany())->find($companyId);
        if (!$company || (int) $company->getAttribute('user_id') !== $userId) {
            throw new Exception('الشركة غير موجودة', 404);
        }
        return $company;
    }

    public function update(int $userId, int $companyId, array $data): CrmCompany
    {
        $company = $this->findOwned($userId, $companyId);
        foreach (['name', 'industry', 'website', 'phone', 'email', 'address', 'city', 'country', 'company_size', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $company->setAttribute($field, $data[$field]);
            }
        }
        $company->save();

        ActivityLog::record('crm', 'company.updated', [
            'user_id' => $userId, 'subject_type' => 'crm_companies', 'subject_id' => $companyId,
        ]);

        return $company;
    }

    /**
     * حذف شركة مملوكة للحساب. جهات الاتصال والصفقات المرتبطة بها بتفضل موجودة
     * بس بتتفك عن الشركة (company_id = NULL)، وقيم الحقول المخصصة الخاصة بالشركة بتتمسح.
     * @return array {contacts_unlinked:int, deals_unlinked:int}
     */
    public function delete(int $userId, int $companyId): array
    {
        $company = $this->findOwned($userId, $companyId);
        $db = Database::getInstance();

        $contacts = (int) ($db->query('SELECT COUNT(*) AS n FROM crm_contacts WHERE company_id = ? AND user_id = ?', [$companyId, $userId])[0]['n'] ?? 0);
        $deals = (int) ($db->query('SELECT COUNT(*) AS n FROM crm_deals WHERE company_id = ? AND owner_user_id = ?', [$companyId, $userId])[0]['n'] ?? 0);

        $db->query('UPDATE crm_contacts SET company_id = NULL WHERE company_id = ? AND user_id = ?', [$companyId, $userId]);
        $db->query('UPDATE crm_deals SET company_id = NULL WHERE company_id = ? AND owner_user_id = ?', [$companyId, $userId]);
        $db->query("DELETE FROM crm_custom_field_values WHERE user_id = ? AND entity_type = 'company' AND entity_id = ?", [$userId, $companyId]);

        if (!$company->delete()) {
            throw new Exception('تعذر حذف الشركة', 500);
        }

        ActivityLog::record('crm', 'company.deleted', [
            'user_id' => $userId, 'subject_type' => 'crm_companies', 'subject_id' => $companyId,
            'meta' => ['contacts_unlinked' => $contacts, 'deals_unlinked' => $deals],
        ]);

        return ['contacts_unlinked' => $contacts, 'deals_unlinked' => $deals];
    }

    public function listForUser(int $userId, int $limit = 200): array
    {
        return (new CrmCompany())->allForUser($userId, $limit);
    }

    /** Filters + Pagination حقيقي (بند 29، 37) */
    public function search(int $userId, array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = ['user_id = ?'];
        $params = [$userId];

        if (!empty($filters['industry'])) {
            $where[] = 'industry = ?';
            $params[] = $filters['industry'];
        }
        if (!empty($filters['country'])) {
            $where[] = 'country = ?';
            $params[] = $filters['country'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(name LIKE ? OR email LIKE ? OR website LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like);
        }

        return $this->paginateQuery('crm_companies', implode(' AND ', $where), $params, $page, $perPage);
    }
}
