<?php

/**
 * Tourfecto - AI Chat Platform
 * إدارة قاعدة معرفة الشركة الخاصة بـ AI Chat (بند 4 + 13 Brand Voice).
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class AiKnowledgeBaseController extends Controller
{
    /** @var KnowledgeBaseService */
    private $knowledgeBase;

    /** @var AiKnowledgeBase */
    private $model;

    public function __construct()
    {
        parent::__construct();
        $this->knowledgeBase = new KnowledgeBaseService();
        $this->model = new AiKnowledgeBase();
    }

    /**
     * كل عناصر قاعدة المعرفة لموقع معيّن، مجمّعة حسب القسم.
     * GET /api/ai-chat/websites/{id}/knowledge-base
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

        $grouped = $this->knowledgeBase->getGroupedByCompany((int) $website->getAttribute('id'));
        $result = [];
        foreach ($grouped as $section => $entries) {
            $result[$section] = array_map(function (AiKnowledgeBase $entry) {
                return $this->serialize($entry);
            }, $entries);
        }

        return $this->success([
            'sections' => $result,
            'brand_voice' => $this->knowledgeBase->getBrandVoice((int) $website->getAttribute('id')),
        ]);
    }

    /**
     * POST /api/ai-chat/websites/{id}/playground {message, history[]}
     * «جرّب الشات»: رد البوت الحقيقي (نفس قاعدة المعرفة والبرومبت) من غير ما يتسجل أي حاجة
     */
    public function playground(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($e = $this->verifyCsrf()) || ($e = $this->rateLimitGuard('user', 'chat_playground', 30, 600))) {
            return $e;
        }
        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('الموقع غير موجود', 404);
        }
        if (!in_array($this->teamRole, ['owner', 'admin'], true)) {
            return $this->error('Only the account owner or a team admin can change this', 403);
        }
        $message = mb_substr(trim((string) $this->get('message', '')), 0, 1500);
        if ($message === '') {
            return $this->error('اكتب رسالة زي ما السائح هيكتبها', 422);
        }
        $history = $this->get('history', []);
        $result = (new AIConversationEngine())->previewReply((int) $website->getAttribute('id'), (int) $website->getAttribute('user_id'), $message, is_array($history) ? $history : []);
        if ($result['error'] !== null) {
            return $this->error('الذكاء الاصطناعي مارد دلوقتي - جرّب تاني بعد دقيقة', 502);
        }
        // مهمة «جرّب الشات كأنك سائح» في خطة أول 3 أيام
        if (class_exists('TrialPlanService')) {
            TrialPlanService::markPlaygroundUsed((int) $this->user['id']);
        }
        return $this->success($result);
    }

    /**
     * إضافة عنصر جديد لقاعدة المعرفة.
     * POST /api/ai-chat/websites/{id}/knowledge-base
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
        if (!in_array($this->teamRole, ['owner', 'admin'], true)) {
            return $this->error('Only the account owner or a team admin can change this', 403);
        }

        $section = trim((string) $this->get('section', ''));
        if ($section === '') {
            return $this->error('section is required', 422);
        }

        try {
            $entry = $this->knowledgeBase->addEntry((int) $website->getAttribute('id'), [
                'section' => $section,
                'title' => $this->get('title'),
                'content' => $this->get('content'),
                'structured_data' => $this->get('structured_data'),
                'language' => $this->get('language', 'en'),
                'tone' => $this->get('tone'),
                'priority' => (int) $this->get('priority', 0),
                'created_by_user_id' => $this->user['id'],
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        }

        if (!$entry) {
            return $this->error('Failed to save knowledge base entry', 500);
        }

        return $this->success(['entry' => $this->serialize($entry)], 'Knowledge base entry added', 201);
    }

    /**
     * تحديث عنصر موجود.
     * PUT /api/ai-chat/websites/{id}/knowledge-base/{entryId}
     */
    public function update(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }
        if (!in_array($this->teamRole, ['owner', 'admin'], true)) {
            return $this->error('Only the account owner or a team admin can change this', 403);
        }

        $entryId = (int) ($params['entryId'] ?? 0);
        $updated = $this->knowledgeBase->updateEntry($entryId, (int) $website->getAttribute('id'), $this->all());

        if (!$updated) {
            return $this->error('Entry not found or does not belong to this website', 404);
        }

        return $this->success([], 'Knowledge base entry updated');
    }

    /**
     * حذف (منطقي) لعنصر من قاعدة المعرفة.
     * DELETE /api/ai-chat/websites/{id}/knowledge-base/{entryId}
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
        if (!in_array($this->teamRole, ['owner', 'admin'], true)) {
            return $this->error('Only the account owner or a team admin can change this', 403);
        }

        $entryId = (int) ($params['entryId'] ?? 0);
        $deleted = $this->knowledgeBase->deleteEntry($entryId, (int) $website->getAttribute('id'));

        if (!$deleted) {
            return $this->error('Entry not found or does not belong to this website', 404);
        }

        return $this->success([], 'Knowledge base entry deleted');
    }

    /**
     * معاينة النص الفعلي الذي سيُحقن في الـAI System Prompt (مفيد للتشخيص
     * والتأكد أن المعلومات المحفوظة صحيحة قبل تفعيل AI Chat).
     * GET /api/ai-chat/websites/{id}/knowledge-base/preview
     */
    public function preview(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }

        $language = $this->get('language');
        $context = $this->knowledgeBase->buildContextForPrompt((int) $website->getAttribute('id'), $language);

        return $this->success(['context_preview' => $context]);
    }

    /**
     * التحقق من ملكية الموقع للمستخدم الحالي (عزل بيانات - بند 26).
     * @param int $websiteId
     * @return Website|null
     */
    /**
     * POST /api/ai-chat/websites/{id}/knowledge-base/import
     * رابط صفحة (url) أو ملف (file: PDF/TXT) → معلومات مقترحة للمعاينة (مش بتتحفظ هنا)
     */
    public function import(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }
        if (!in_array($this->teamRole, ['owner', 'admin'], true)) {
            return $this->error('Only the account owner or a team admin can change this', 403);
        }
        if ($rl = $this->rateLimitGuard('user', 'kb_import', 10, 3600)) {
            return $rl;
        }
        if($csrf=$this->verifyCsrf())return $csrf;
        $uid = (int) $website->getAttribute('user_id'); // AI usage is billed to the business account
        $svc = new KnowledgeImportService();
        $file = $_FILES['file'] ?? null;
        if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
                return $this->error('رفع الملف فشل - جرّب تاني', 422);
            }
            $src = $svc->textFromFile((string) $file['tmp_name'], (string) $file['name'],filter_var($this->get('use_ocr',false),FILTER_VALIDATE_BOOLEAN),(string)$this->get('language','en'));
            $sourceLabel = (string) $file['name'];
        } else {
            $url = trim((string) $this->get('url', ''));
            if ($url === '') {
                return $this->error('Add a page link or upload a file', 422);
            }
            $src = $svc->textFromUrl($url);
            $sourceLabel = $url;
        }
        if (empty($src['ok'])) {
            return $this->error($src['error'] ?? 'مقدرناش نقرا المصدر', 422);
        }

        $language = (string)$this->get('language','');
        if($language==='')$language=ContentLanguageService::forWebsite((int)$website->getAttribute('id'));
        if(!InternationalSettingsService::validLanguage($language))return $this->error('Choose a supported audience language',422);
        $reservation=null;
        try{
        $reservation=ServiceUsageReservation::reserve($uid,'marketing_tool','marketing_tool');
        $res = $svc->extractEntries((string) $src['text'], $language);
        if (empty($res['ok'])) {
            ServiceUsageReservation::settle($reservation,!empty($res['uncertain'])?'uncertain':'released');
            return $this->error($res['error'] ?? 'تعذر الاستخراج', 502);
        }
        if (!$res['entries']) {
            ServiceUsageReservation::settle($reservation,'released');
            return $this->error('We found no useful information for the bot in this source', 422);
        }
        if(!ServiceUsageReservation::settle($reservation,'completed'))throw new RuntimeException('Usage settlement failed');
        return $this->success([
            'entries' => $res['entries'],
            'source' => mb_substr($sourceLabel, 0, 300),
            'language' => $language,
            'operation_id'=>$reservation,
            'extraction_method'=>$src['method']??'text',
            'source_note'=>$src['source_note']??null,
        ], 'Found ' . count($res['entries']) . ' items - choose what to save');
            }catch(Throwable $e){if($reservation)ServiceUsageReservation::settle($reservation,'uncertain');return $this->error($reservation?'Extraction outcome needs review. Contact support with the operation ID.':'Unable to reserve extraction credit.', $reservation?503:402,['operation_id'=>$reservation]);}
    }

    /**
     * POST /api/ai-chat/websites/{id}/knowledge-base/import/save
     * حفظ المعلومات اللي العميل اختارها من المعاينة (entries[]: section/title/content)
     */
    public function importSave(array $params = []): array
    {
        if($csrfError=$this->verifyCsrf())return $csrfError;
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }
        if (!in_array($this->teamRole, ['owner', 'admin'], true)) {
            return $this->error('Only the account owner or a team admin can change this', 403);
        }
        $entries = $this->get('entries', []);
        $entries = KnowledgeImportService::sanitizeEntries(is_array($entries) ? $entries : []);
        if (!$entries) {
            return $this->error('مفيش معلومات مختارة', 422);
        }
        $language = (string)$this->get('language','');
        if($language==='')$language=ContentLanguageService::forWebsite((int)$website->getAttribute('id'));
        if(!InternationalSettingsService::validLanguage($language))return $this->error('Choose a supported import language',422);
        $saved = 0;
        foreach ($entries as $e) {
            try {
                if ($this->knowledgeBase->addEntry((int) $website->getAttribute('id'), [
                    'section' => $e['section'],
                    'title' => $e['title'] !== '' ? $e['title'] : null,
                    'content' => $e['content'],
                    'language' => $language,
                    'priority' => 0,
                    'created_by_user_id' => $this->user['id'],
                ])) {
                    $saved++;
                }
            } catch (Throwable $ex) {
                Logger::warning('kb import save failed', ['error' => CustomerError::message($ex->getMessage(), 500)]);
            }
        }
        return $this->success(['saved' => $saved], 'Saved ' . $saved . ' items - the bot will use them from now on', 201);
    }

    /** Role of the current user on the website last authorized (owner, admin, member, viewer). */
    private ?string $teamRole = null;

    private function authorizedWebsite(int $websiteId): ?Website
    {
        if ($websiteId <= 0) {
            return null;
        }
        $website = (new Website())->find($websiteId);
        if (!$website) {
            return null;
        }
        // The owner, or their team (Workspace or Business Center): everyone can view, owner/admin can change.
        $this->teamRole = (int) $website->getAttribute('user_id') === (int) $this->user['id'] ? 'owner'
            : (class_exists('TeamAccess') ? TeamAccess::inboxRole((int) $website->getAttribute('user_id'), (int) $this->user['id']) : null);
        if ($this->teamRole === null) {
            return null;
        }
        return $website;
    }

    /**
     * @param AiKnowledgeBase $entry
     * @return array
     */
    private function serialize(AiKnowledgeBase $entry): array
    {
        return [
            'id' => $entry->getAttribute('id'),
            'section' => $entry->getAttribute('section'),
            'title' => $entry->getAttribute('title'),
            'content' => $entry->getAttribute('content'),
            'structured_data' => $entry->getAttribute('structured_data') ? json_decode($entry->getAttribute('structured_data'), true) : null,
            'language' => $entry->getAttribute('language'),
            'tone' => $entry->getAttribute('tone'),
            'priority' => $entry->getAttribute('priority'),
            'created_at' => $entry->getAttribute('created_at'),
            'updated_at' => $entry->getAttribute('updated_at'),
        ];
    }
}
