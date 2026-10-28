<?php

/**
 * Tourfecto - AI Article Model
 * نموذج المقالات المولّدة بالذكاء الاصطناعي
 * @version 1.0.0
 */
class AIArticle extends Model
{
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
        'scheduled_at',
        'scheduled_job_id',
        'featured_image_path',
        'featured_image_alt',
        'featured_image_prompt',
        'wp_media_id',
        'image_error',
        // Phase 8 (Content Agent) - إضافي، الحقول القديمة فوق متلمستش
        'faqs_json',
        'schema_suggestion',
        'internal_link_suggestions_json',
    ];

    public function getSuggestedKeywordsArray(): array
    {
        $raw = $this->getAttribute('suggested_keywords');
        if (!$raw) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
    /** Read-only cleanup shared by preview, download and publishers. */
    public function contentForPresentation(): string
    {
        require_once __DIR__ . '/../Services/AI/ArticleFaqContent.php';
        $faqs = json_decode((string) $this->getAttribute('faqs_json'), true);
        return ArticleFaqContent::clean((string) $this->getAttribute('content'), is_array($faqs) ? $faqs : []);
    }

}
