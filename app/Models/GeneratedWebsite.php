<?php

/** Tourfecto - Generated Website Model @version 1.0.0 */
class GeneratedWebsite extends Model
{
    protected $table = 'generated_websites';
    protected $fillable = [
        'user_id', 'slug', 'status', 'theme_color', 'content_json', 'custom_domain',
        // v2.0.0 - مكتبة تصميمات + بيانات لوحة التحكم
        'template_id', 'niche_key', 'logo_url', 'favicon_url', 'seo_title',
        'seo_description', 'views_count', 'last_published_at',
    ];

    public function getContent(): array
    {
        $json = $this->getAttribute('content_json');
        $decoded = $json ? json_decode((string) $json, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    /** المجال السياحي: بيرجع niche_key لو متسجل، وإلا بيستنتجه من industry القديمة (توافق مع مواقع قديمة) */
    public function resolveNicheKey(): string
    {
        $niche = (string) $this->getAttribute('niche_key');
        if ($niche !== '') {
            return $niche;
        }
        $content = $this->getContent();
        return ($content['industry'] ?? 'tours') === 'hotel' ? 'hotels' : 'tours';
    }
}
