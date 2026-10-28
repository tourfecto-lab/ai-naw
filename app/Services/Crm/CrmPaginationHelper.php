<?php

/**
 * Tourfecto - CRM Pagination Helper Trait
 * يوفر دوال التصفح والترقيم لخدمات CRM
 */
trait CrmPaginationHelper
{
    /**
     * معالجة معاملات الترقيم
     * @param int $page
     * @param int $perPage
     * @param int $maxPerPage
     * @return array [offset, limit, page, per_page]
     */
    protected function paginateParams(int $page = 1, int $perPage = 20, int $maxPerPage = 100): array
    {
        $page = max(1, $page);
        $perPage = max(1, min($maxPerPage, $perPage));
        $offset = ($page - 1) * $perPage;

        return [
            'offset'   => $offset,
            'limit'    => $perPage,
            'page'     => $page,
            'per_page' => $perPage
        ];
    }

    /**
     * بناء هيكل الرد المرقّم
     * @param array $items
     * @param int $total
     * @param int $page
     * @param int $perPage
     * @return array
     */
    protected function paginateResponse(array $items, int $total, int $page, int $perPage): array
    {
        return [
            'items'        => $items,
            'total'        => $total,
            'page'         => $page,
            'per_page'     => $perPage,
            'total_pages'  => (int) ceil($total / max(1, $perPage)),
            'has_more'     => ($page * $perPage) < $total
        ];
    }

    /**
     * استعلام مرقّم كامل على جدول واحد (COUNT + SELECT بـ LIMIT/OFFSET)
     * بيستخدمه search() في خدمات الشركات/المهام/المواعيد.
     * $table و $orderBy قيم ثابتة من الكود (مش من المستخدم)، والفلاتر كلها
     * بتتمرر كـ prepared params في $params.
     */
    protected function paginateQuery(string $table, string $where, array $params, int $page = 1, int $perPage = 25, string $orderBy = 'created_at DESC'): array
    {
        $p = $this->paginateParams($page, $perPage);
        $whereSql = $where !== '' ? $where : '1=1';
        $db = Database::getInstance();

        $total = (int) ($db->query("SELECT COUNT(*) AS c FROM {$table} WHERE {$whereSql}", $params)[0]['c'] ?? 0);
        $items = $db->query(
            "SELECT * FROM {$table} WHERE {$whereSql} ORDER BY {$orderBy} LIMIT ? OFFSET ?",
            array_merge($params, [$p['limit'], $p['offset']])
        );

        return $this->paginateResponse($items ?: [], $total, $p['page'], $p['per_page']);
    }
}