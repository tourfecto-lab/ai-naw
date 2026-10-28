<?php

/** Tourfecto - Website Review Model (تقييمات الزوار) @version 2.0.0 */
class WebsiteReview extends Model
{
    protected $table = 'website_reviews';
    protected $fillable = ['website_id', 'item_id', 'visitor_name', 'rating', 'comment', 'status'];

    /** التقييمات المعتمدة بس - دي اللي بتتعرض للزوار في الموقع العام */
    public function approvedFor(int $websiteId, ?string $itemId = null): array
    {
        $where = ['website_id' => $websiteId, 'status' => 'approved'];
        if ($itemId !== null) {
            $where['item_id'] = $itemId;
        }
        return $this->where($where, ['created_at' => 'DESC']);
    }

    /** كل التقييمات (لأصحاب الموقع في لوحة التحكم، بكل الحالات) */
    public function allFor(int $websiteId): array
    {
        return $this->where(['website_id' => $websiteId], ['created_at' => 'DESC']);
    }

    public function averageRating(int $websiteId): float
    {
        $rows = $this->approvedFor($websiteId);
        if (empty($rows)) {
            return 0.0;
        }
        $sum = 0;
        foreach ($rows as $r) {
            $sum += (int) $r->getAttribute('rating');
        }
        return round($sum / count($rows), 1);
    }
}
