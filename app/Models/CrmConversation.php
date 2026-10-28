<?php

/** Tourfecto - CRM Conversation Model (بند 15، 16، 17) @version 1.0.0 */
class CrmConversation extends Model
{
    protected $table = 'crm_conversations';
    protected $fillable = ['user_id', 'contact_id', 'channel', 'external_thread_id', 'assigned_user_id', 'last_message_at', 'unread_count'];

    public function findOrCreate(int $userId, ?int $contactId, string $channel, ?string $externalThreadId): self
    {
        $conditions = ['user_id' => $userId, 'channel' => $channel];
        if ($externalThreadId) {
            $conditions['external_thread_id'] = $externalThreadId;
        } elseif ($contactId) {
            $conditions['contact_id'] = $contactId;
        }
        $existing = $this->where($conditions, [], 1);
        if (!empty($existing)) {
            return $existing[0];
        }
        $conversation = new self([
            'user_id' => $userId, 'contact_id' => $contactId, 'channel' => $channel,
            'external_thread_id' => $externalThreadId, 'unread_count' => 0,
        ]);
        $conversation->save();
        return $conversation;
    }

    public function allForUser(int $userId, int $limit = 100): array
    {
        return $this->db->query(
            "SELECT cv.*, c.name AS contact_name FROM crm_conversations cv
             LEFT JOIN crm_contacts c ON c.id = cv.contact_id
             WHERE cv.user_id = ? ORDER BY cv.last_message_at DESC, cv.id DESC LIMIT ?",
            [$userId, $limit]
        );
    }
}
