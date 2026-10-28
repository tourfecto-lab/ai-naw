<?php

/** Tourfecto - CRM Global Search Service (بند 28) @version 1.0.0 */
class CrmSearchService
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function search(int $userId, string $query, int $limitPerType = 10): array
    {
        $like = '%' . $query . '%';

        $contacts = $this->db->query(
            "SELECT id, name, email, phone FROM crm_contacts WHERE user_id = ? AND (name LIKE ? OR email LIKE ? OR phone LIKE ?) LIMIT ?",
            [$userId, $like, $like, $like, $limitPerType]
        );
        $companies = $this->db->query(
            "SELECT id, name, industry FROM crm_companies WHERE user_id = ? AND name LIKE ? LIMIT ?",
            [$userId, $like, $limitPerType]
        );
        $leads = $this->db->query(
            "SELECT l.id, l.status, c.name AS contact_name FROM crm_leads l
             JOIN crm_contacts c ON c.id = l.contact_id
             WHERE c.user_id = ? AND (c.name LIKE ? OR l.interest LIKE ?) LIMIT ?",
            [$userId, $like, $like, $limitPerType]
        );
        $deals = $this->db->query(
            "SELECT id, title, value, status FROM crm_deals WHERE owner_user_id = ? AND title LIKE ? LIMIT ?",
            [$userId, $like, $limitPerType]
        );
        $tasks = $this->db->query(
            "SELECT id, title, status, due_date FROM crm_tasks WHERE user_id = ? AND title LIKE ? LIMIT ?",
            [$userId, $like, $limitPerType]
        );
        $notes = $this->db->query(
            "SELECT id, body, related_type, related_id FROM crm_notes WHERE user_id = ? AND body LIKE ? LIMIT ?",
            [$userId, $like, $limitPerType]
        );

        return compact('contacts', 'companies', 'leads', 'deals', 'tasks', 'notes');
    }
}
