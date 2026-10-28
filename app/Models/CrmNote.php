<?php

/** Tourfecto - CRM Note Model @version 1.1.0 */
class CrmNote extends Model
{
    protected $table = 'crm_notes';
    protected $fillable = ['user_id', 'author_user_id', 'related_type', 'related_id', 'body', 'pinned'];

    public function forRelated(string $relatedType, int $relatedId): array
    {
        return $this->where(['related_type' => $relatedType, 'related_id' => $relatedId], ['created_at' => 'DESC']);
    }
}
