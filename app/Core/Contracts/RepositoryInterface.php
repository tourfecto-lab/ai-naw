<?php

/**
 * Tourfecto - Repository Contract
 * العقد الأساسي لأي Repository في المشروع (Repository Pattern)
 * @version 1.0.0
 *
 * الهدف: فصل منطق الوصول لقاعدة البيانات (SQL, أسماء الأعمدة، الـ joins)
 * عن الـ Controllers والـ Services تمامًا. الـ Controller/Service محتاج
 * يعرف بس "هات لي مستخدم بالـ id ده" مش تفاصيل الاستعلام أو اسم العمود
 * الحقيقي في القاعدة (اللي زي ما شفنا في المشروع ده بيختلف أحيانًا عن
 * الكود المفترض - مشكلة is_active/status، main_url/url، expiry_date...).
 * الـ Repository هو المكان الوحيد المسموح له يعرف التفاصيل دي.
 */
interface RepositoryInterface
{
    /**
     * البحث عن سجل بالمفتاح الأساسي.
     * @param int|string $id
     * @return array|null
     */
    public function find($id): ?array;

    /**
     * كل السجلات اللي بتحقق شرط معيّن.
     * @param array $criteria ['column' => value]
     * @param array $orderBy ['column' => 'ASC'|'DESC']
     * @param int $limit
     * @return array
     */
    public function findBy(array $criteria, array $orderBy = [], int $limit = 0): array;

    /**
     * إنشاء سجل جديد، بيرجع الـ id بتاعه.
     * @param array $data
     * @return int|false
     */
    public function create(array $data);

    /**
     * تحديث سجل موجود.
     * @param int|string $id
     * @param array $data
     * @return bool
     */
    public function update($id, array $data): bool;

    /**
     * حذف سجل.
     * @param int|string $id
     * @return bool
     */
    public function delete($id): bool;
}
