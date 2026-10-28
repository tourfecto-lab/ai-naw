<?php

/** Tourfecto - CRM Appointment Service (بند 18) @version 1.1.0 */
class CrmAppointmentService
{
    use CrmPaginationHelper;

    /** $actorUserId اختياري (بند 30 - استكمال) - راجع نفس الشرح في CrmTaskService::create() */
    public function create(int $userId, array $data, ?int $actorUserId = null): CrmMeeting
    {
        if (empty($data['title']) || empty($data['starts_at'])) {
            throw new Exception('عنوان وتاريخ الموعد مطلوبان');
        }
        $actorUserId = $actorUserId ?? $userId;
        $meeting = new CrmMeeting([
            'user_id' => $userId,
            'organizer_user_id' => $actorUserId,
            'contact_id' => $data['contact_id'] ?? null,
            'related_type' => $data['related_type'] ?? null,
            'related_id' => $data['related_id'] ?? null,
            'title' => $data['title'],
            'purpose' => $data['purpose'] ?? null,
            'meeting_link' => $data['meeting_link'] ?? null,
            'location' => $data['location'] ?? null,
            'timezone' => $data['timezone'] ?? null,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'] ?? null,
            'status' => 'scheduled',
            'notes' => $data['notes'] ?? null,
        ]);
        $meeting->save();

        ActivityLog::record('crm', 'appointment.created', [
            'user_id' => $actorUserId, 'subject_type' => 'crm_meetings', 'subject_id' => (int) $meeting->getAttribute('id'),
        ]);

        return $meeting;
    }

    public function findOwned(int $userId, int $meetingId): CrmMeeting
    {
        $meeting = (new CrmMeeting())->find($meetingId);
        if (!$meeting || (int) $meeting->getAttribute('user_id') !== $userId) {
            throw new Exception('الموعد غير موجود', 404);
        }
        return $meeting;
    }

    public function updateStatus(int $userId, int $meetingId, string $status): CrmMeeting
    {
        $allowed = ['scheduled', 'confirmed', 'completed', 'cancelled', 'no_show'];
        if (!in_array($status, $allowed, true)) {
            throw new Exception('حالة غير صحيحة');
        }
        $meeting = $this->findOwned($userId, $meetingId);
        $meeting->setAttribute('status', $status);
        $meeting->save();

        ActivityLog::record('crm', 'appointment.status_changed', [
            'user_id' => $userId, 'subject_type' => 'crm_meetings', 'subject_id' => $meetingId, 'meta' => ['status' => $status],
        ]);

        return $meeting;
    }

    public function listForUser(int $userId, int $limit = 200): array
    {
        return (new CrmMeeting())->allForUser($userId, $limit);
    }

    /** Filters + Pagination حقيقي (بند 29، 37) */
    public function search(int $userId, array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = ['user_id = ?'];
        $params = [$userId];

        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'starts_at >= ?';
            $params[] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 'starts_at <= ?';
            $params[] = $filters['to'] . ' 23:59:59';
        }
        if (!empty($filters['search'])) {
            $where[] = 'title LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
        }

        return $this->paginateQuery('crm_meetings', implode(' AND ', $where), $params, $page, $perPage, 'starts_at ASC');
    }
}
