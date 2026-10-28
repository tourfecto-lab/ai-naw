<?php

/**
 * Tourfecto - AI Chat Platform
 * Tags مخصصة لكل شركة (بند 11: "ويستطيع صاحب الشركة إنشاء Tags إضافية").
 * فوق القائمة الجاهزة (HOT_LEAD, NEW_INQUIRY, ...) التي يديرها الكود
 * مباشرة - هذا الـController يدير الوسوم الإضافية التي تضيفها كل شركة
 * بنفسها عبر `ai_custom_tags` (موجود من المرحلة 1، لم يكن له واجهة).
 *
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class AiCustomTagController extends Controller
{
    /** @var AiCustomTag */
    private $model;

    public function __construct()
    {
        parent::__construct();
        $this->model = new AiCustomTag();
    }

    /**
     * كل الوسوم المخصصة لموقع معيّن.
     * GET /api/ai-chat/websites/{id}/custom-tags
     */
    public function index(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }

        $tags = $this->model->forWebsite((int) $website->getAttribute('id'));

        return $this->success([
            'tags' => array_map(function (AiCustomTag $tag) {
                return [
                    'id' => $tag->getAttribute('id'),
                    'name' => $tag->getAttribute('name'),
                    'color' => $tag->getAttribute('color'),
                ];
            }, $tags),
        ]);
    }

    /**
     * إضافة وسم مخصص جديد.
     * POST /api/ai-chat/websites/{id}/custom-tags
     * Body: name, color (اختياري)
     */
    public function store(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }

        $name = strtoupper(trim((string) $this->get('name', '')));
        $name = preg_replace('/[^A-Z0-9_\x{0600}-\x{06FF} ]/u', '', $name);

        if ($name === '') {
            return $this->error('name is required', 422);
        }
        if (mb_strlen($name) > 50) {
            return $this->error('name too long (max 50 characters)', 422);
        }

        $tag = new AiCustomTag();
        $tag->fill([
            'website_id' => (int) $website->getAttribute('id'),
            'name' => $name,
            'color' => $this->get('color', 'gray'),
        ]);

        if ($tag->save() === false) {
            return $this->error('This tag already exists or failed to save', 422);
        }

        return $this->success([
            'tag' => [
                'id' => $tag->getAttribute('id'),
                'name' => $tag->getAttribute('name'),
                'color' => $tag->getAttribute('color'),
            ],
        ], 'Custom tag created', 201);
    }

    /**
     * حذف وسم مخصص.
     * DELETE /api/ai-chat/websites/{id}/custom-tags/{tagId}
     */
    public function destroy(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }

        $tagId = (int) ($params['tagId'] ?? 0);
        $tag = $this->model->find($tagId);
        if (!$tag || (int) $tag->getAttribute('website_id') !== (int) $website->getAttribute('id')) {
            return $this->error('Tag not found', 404);
        }

        $tag->delete();

        return $this->success([], 'Custom tag deleted');
    }

    /**
     * @param int $websiteId
     * @return Website|null
     */
    private function authorizedWebsite(int $websiteId): ?Website
    {
        if ($websiteId <= 0) {
            return null;
        }
        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->user['id']) {
            return null;
        }
        return $website;
    }
}
