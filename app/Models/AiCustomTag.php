<?php

/**
 * Tourfecto - AI Chat Platform
 * Tags مخصصة لكل شركة، فوق القائمة الجاهزة (بند 11).
 * @version 1.0.0
 */
class AiCustomTag extends Model
{
    protected $table = 'ai_custom_tags';
    protected $fillable = ['website_id', 'name', 'color'];

    /**
     * @param int $websiteId
     * @return array
     */
    public function forWebsite(int $websiteId): array
    {
        return $this->where(['website_id' => $websiteId], ['name' => 'ASC']);
    }
}
