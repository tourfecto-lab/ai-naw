<?php

/** Tourfecto - Website Lead Model (طلبات حجز/تواصل من الموقع المنشور) @version 2.0.0 */
class WebsiteLead extends Model
{
    protected $table = 'website_leads';
    protected $fillable = ['website_id', 'item_id', 'visitor_name', 'phone', 'email', 'message', 'status'];

    public function allFor(int $websiteId): array
    {
        return $this->where(['website_id' => $websiteId], ['created_at' => 'DESC']);
    }

    public function newCountFor(int $websiteId): int
    {
        return count($this->where(['website_id' => $websiteId, 'status' => 'new']));
    }
}
