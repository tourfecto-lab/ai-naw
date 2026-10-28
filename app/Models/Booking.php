<?php

/** Tourfecto - Booking Model (Booking Engine - Phase 2) @version 1.0.0 */
class Booking extends Model
{
    protected $table = 'bookings';
    protected $fillable = [
        'booking_reference', 'user_id', 'product_id', 'customer_id',
        'customer_name', 'customer_phone', 'customer_email',
        'start_date', 'start_time', 'adults_count', 'children_count',
        'total_amount', 'currency', 'status', 'source', 'notes', 'inventory_units', 'pricing_json', 'ticket_token', 'checked_in_at',
    ];

    /** حجوزات الحساب، بفلترة اختيارية بالحالة والتاريخ */
    public function forUser(int $userId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $sql = 'SELECT b.*, p.name AS product_name FROM bookings b LEFT JOIN crm_products p ON p.id = b.product_id WHERE b.user_id = ?';
        $params = [$userId];

        if (!empty($filters['status'])) {
            $sql .= ' AND b.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['product_id'])) {
            $sql .= ' AND b.product_id = ?';
            $params[] = (int) $filters['product_id'];
        }
        if (!empty($filters['from_date'])) {
            $sql .= ' AND b.start_date >= ?';
            $params[] = $filters['from_date'];
        }
        if (!empty($filters['to_date'])) {
            $sql .= ' AND b.start_date <= ?';
            $params[] = $filters['to_date'];
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND (b.booking_reference LIKE ? OR b.customer_name LIKE ? OR b.customer_phone LIKE ? OR b.customer_email LIKE ?)';
            $q = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']) . '%';
            array_push($params, $q, $q, $q, $q);
        }

        $sql .= ' ORDER BY b.start_date DESC, b.id DESC LIMIT ' . max(1, (int) $perPage)
            . ' OFFSET ' . max(0, ((int) $page - 1) * (int) $perPage);

        return $this->db->query($sql, $params);
    }

    /** حجز واحد مملوك للحساب (يمنع أي حساب يشوف حجز حساب تاني) */
    public function findOwned(int $userId, int $bookingId): ?Booking
    {
        $rows = $this->db->query(
            'SELECT * FROM bookings WHERE id = ? AND user_id = ? LIMIT 1',
            [$bookingId, $userId]
        );
        if (empty($rows)) {
            return null;
        }
        $model = new static($rows[0]);
        $model->original = $rows[0];

        return $model;
    }

    public function findByReference(string $reference): ?Booking
    {
        $rows = $this->db->query(
            'SELECT * FROM bookings WHERE booking_reference = ? LIMIT 1',
            [$reference]
        );
        if (empty($rows)) {
            return null;
        }
        $model = new static($rows[0]);
        $model->original = $rows[0];

        return $model;
    }

    /** إحصاءات سريعة للوحة التحكم */
    public function dashboardStats(int $userId): array
    {
        $rows = $this->db->query(
            "SELECT status, COUNT(*) AS count, COALESCE(SUM(total_amount), 0) AS total
             FROM bookings WHERE user_id = ? GROUP BY status",
            [$userId]
        );

        $stats = [
            'pending' => 0, 'confirmed' => 0, 'cancelled' => 0,
            'completed' => 0, 'no_show' => 0, 'total_revenue' => 0.0,
        ];
        foreach ($rows as $row) {
            $stats[$row['status']] = (int) $row['count'];
            if (in_array($row['status'], ['confirmed', 'completed'], true)) {
                $stats['total_revenue'] += (float) $row['total'];
            }
        }

        return $stats;
    }
}
