<?php

/** Tourfecto - CRM Message Model @version 1.0.0 */
class CrmMessage extends Model
{
    protected $table = 'crm_messages';
    protected $fillable = [
        'conversation_id', 'direction', 'sender_user_id', 'body', 'subject',
        'status', 'external_message_id', 'error', 'sent_at',
    ];

    public function forConversation(int $conversationId, int $limit = 200): array
    {
        return $this->db->query(
            "SELECT * FROM crm_messages WHERE conversation_id = ? ORDER BY sent_at ASC LIMIT ?",
            [$conversationId, $limit]
        );
    }
}
