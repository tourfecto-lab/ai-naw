<?php
/**
 * Tourfecto - AI Article Model
 * نموذج المقالات المولّدة بالذكاء الاصطناعي
 * @version 1.0.0
 */
class AIArticle extends Model {
    protected $table = 'ai_articles';

    protected $fillable = [
        'user_id',
        'website_id',
        'topic',
        'target_language',
        'tone',
        'title',
        'meta_description',
        'slug',
        'content',
        'suggested_keywords',
        'word_count',
        'status',
        'error_message',
        'published_at',
        'published_url',
        'wp_post_id',
    ];

    public function getSuggestedKeywordsArray(): array {
        $raw = $this->getAttribute('suggested_keywords');
        if (!$raw) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}