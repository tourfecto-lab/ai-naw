<?php

/**
 * Tourfecto - AI Chat Platform
 * خدمة قاعدة معرفة الشركة (بند 4): إدارة المحتوى + تجميعه في نص Context
 * جاهز لحقنه في System Prompt الخاص بـ AI Conversation Engine، مع قاعدة
 * صارمة: الـAI يعتمد فقط على ما هو مكتوب هنا، ولا يخترع أسعارًا أو
 * معلومات غير موجودة.
 *
 * @version 1.0.0
 */

class KnowledgeBaseService
{
    /** @var AiKnowledgeBase */
    private $model;

    /** @var array أسماء الأقسام القابلة للعرض بشكل مقروء داخل الـPrompt */
    private $sectionLabels = [
        'company_info' => 'Company Information',
        'service' => 'Services',
        'tour' => 'Tours',
        'destination' => 'Destinations',
        'pricing' => 'Pricing',
        'faq' => 'Frequently Asked Questions',
        'policy' => 'Policies',
        'cancellation_policy' => 'Cancellation Policy',
        'contact_info' => 'Contact Information',
        'business_hours' => 'Business Hours',
        'custom_instructions' => 'Custom Instructions From The Company',
        'brand_voice' => 'Brand Voice',
    ];

    public function __construct()
    {
        $this->model = new AiKnowledgeBase();
    }

    /**
     * إضافة عنصر جديد لقاعدة المعرفة.
     * @param int $websiteId
     * @param array $data ['section','title','content','structured_data','language','tone','priority','created_by_user_id']
     * @return AiKnowledgeBase|null
     */
    public function addEntry(int $websiteId, array $data): ?AiKnowledgeBase
    {
        if (empty($data['section']) || !isset($this->sectionLabels[$data['section']])) {
            throw new InvalidArgumentException('Invalid knowledge base section: ' . ($data['section'] ?? ''));
        }

        $entry = new AiKnowledgeBase();
        $entry->fill([
            'website_id' => $websiteId,
            'section' => $data['section'],
            'title' => $data['title'] ?? null,
            'content' => $data['content'] ?? null,
            'structured_data' => isset($data['structured_data']) ? json_encode($data['structured_data'], JSON_UNESCAPED_UNICODE) : null,
            'language' => $data['language'] ?? 'en',
            'tone' => $data['tone'] ?? null,
            'priority' => $data['priority'] ?? 0,
            'is_active' => 1,
            'created_by_user_id' => $data['created_by_user_id'] ?? null,
        ]);

        return $entry->save() !== false ? $entry : null;
    }

    /**
     * تحديث عنصر موجود، مع التأكد أنه يخص نفس الموقع (عزل بيانات - بند 26).
     * @param int $entryId
     * @param int $websiteId
     * @param array $data
     * @return bool
     */
    public function updateEntry(int $entryId, int $websiteId, array $data): bool
    {
        $entry = $this->model->find($entryId);
        if (!$entry || (int) $entry->getAttribute('website_id') !== $websiteId) {
            return false;
        }

        $updatable = ['title', 'content', 'language', 'tone', 'priority', 'is_active'];
        $payload = array_intersect_key($data, array_flip($updatable));

        if (isset($data['structured_data'])) {
            $payload['structured_data'] = json_encode($data['structured_data'], JSON_UNESCAPED_UNICODE);
        }

        $entry->fill($payload);
        return $entry->save() !== false;
    }

    /**
     * حذف منطقي (Soft delete) لعنصر من قاعدة المعرفة.
     * @param int $entryId
     * @param int $websiteId
     * @return bool
     */
    public function deleteEntry(int $entryId, int $websiteId): bool
    {
        return $this->updateEntry($entryId, $websiteId, ['is_active' => 0]);
    }

    /**
     * كل عناصر قاعدة المعرفة لموقع معيّن مجمّعة حسب القسم (للعرض في لوحة الإدارة).
     * @param int $websiteId
     * @return array
     */
    public function getGroupedByCompany(int $websiteId): array
    {
        $entries = $this->model->activeFor($websiteId);
        $grouped = [];
        foreach ($entries as $entry) {
            $section = $entry->getAttribute('section');
            $grouped[$section][] = $entry;
        }
        return $grouped;
    }

    /**
     * بناء إعدادات Brand Voice الحالية للموقع (بند 13)، أو قيمة افتراضية
     * "professional" لو لم يحددها صاحب الشركة بعد.
     * @param int $websiteId
     * @return array ['tone' => string, 'custom_instructions' => string|null]
     */
    public function getBrandVoice(int $websiteId): array
    {
        $entries = $this->model->forSection($websiteId, 'brand_voice');
        $tone = 'professional';
        $customInstructions = null;

        if (!empty($entries)) {
            $tone = $entries[0]->getAttribute('tone') ?: 'professional';
            $customInstructions = $entries[0]->getAttribute('content');
        }

        return ['tone' => $tone, 'custom_instructions' => $customInstructions];
    }

    /**
     * تجميع كل قاعدة المعرفة الفعّالة في نص واحد جاهز للحقن داخل System
     * Prompt الخاص بـ AI Conversation Engine. هذه هي نقطة الالتقاء
     * الأساسية بين الوحدة الحالية (Knowledge Base) وبين بند 4 و5 و9
     * (عدم اختلاق معلومات، وطلب تحويل لموظف عند نقص المعرفة).
     *
     * تحسين تنافسي (Re-ranking): لو وُردت رسالة العميل `$customerMessage`
     * ومعها `$maxEntries`، تُرتَّب العناصر حسب الصلة بـ
     * `rerankForQuery()` ويُستخدم الأعلى صلة فقط بدل كل المحتوى —
     * يحسّن دقة الإجابة ويوفر توكنز (مستوحى من Reranker في Intercom Fin).
     * لغة محايدة تمامًا (تعمل على عربي/إنجليزي/أي لغة).
     *
     * @param int $websiteId
     * @param string|null $language لو محدد، يُفضَّل محتوى بنفس اللغة أولاً
     * @param string|null $customerMessage نص رسالة العميل للترتيب حسب الصلة
     * @param int $maxEntries عدد أقصى للعناصر عند استخدام إعادة الترتيب (0 = الكل)
     * @return string
     */
    public function buildContextForPrompt(int $websiteId, ?string $language = null, ?string $customerMessage = null, int $maxEntries = 0): string
    {
        $entries = $this->model->activeFor($websiteId, $language);

        // لو لا يوجد محتوى بنفس اللغة، استخدم كل المحتوى المتاح كاحتياط
        if (empty($entries) && $language) {
            $entries = $this->model->activeFor($websiteId);
        }

        // رحلات الشركة وأسعارها من «رحلاتي» بتوصل للبوت لوحدها - من غير ما صاحب الشركة يكتبها تاني في قاعدة المعرفة
        $tripsBlock = $this->bookableTripsBlock($websiteId);

        if (empty($entries) && $tripsBlock !== '') {
            return "### COMPANY KNOWLEDGE BASE (this is the ONLY source of truth - never invent prices, services, or policies not listed here) ###\n"
                . $tripsBlock;
        }

        if (empty($entries)) {
            return "No company knowledge base has been configured yet. "
                . "You must NOT invent any company information, services, prices, or policies. "
                . "If the customer asks anything beyond a generic greeting, tell them you need to "
                . "check with the team and offer to connect them with a human agent.";
        }

        // إعادة ترتيب حسب الصلة بسؤال العميل (اختياري، عند توفير الرسالة والحد)
        if ($customerMessage !== null && $maxEntries > 0) {
            $entries = $this->rerankForQuery($entries, $customerMessage, $maxEntries);
        }

        $bySection = [];
        foreach ($entries as $entry) {
            $section = $entry->getAttribute('section');
            if ($section === 'brand_voice') {
                continue; // الـBrand Voice يُحقن بشكل منفصل في الـSystem Prompt
            }
            $label = $this->sectionLabels[$section] ?? $section;
            $line = '- ' . trim(($entry->getAttribute('title') ? $entry->getAttribute('title') . ': ' : '') . $entry->getAttribute('content'));

            $structured = $entry->getAttribute('structured_data');
            if (!empty($structured)) {
                $decoded = json_decode($structured, true);
                if (is_array($decoded)) {
                    $line .= ' (' . http_build_query($decoded, '', ', ') . ')';
                }
            }

            $bySection[$label][] = $line;
        }

        $output = "### COMPANY KNOWLEDGE BASE (this is the ONLY source of truth - "
            . "never invent prices, services, or policies not listed here) ###\n";

        foreach ($bySection as $label => $lines) {
            $output .= "\n[{$label}]\n" . implode("\n", $lines) . "\n";
        }

        return $output . $tripsBlock;
    }

    /**
     * رحلات الشركة المتاحة للحجز وأسعارها (crm_products) كنص للبوت.
     * الأسعار للفرد زي ما الشركة كتبتها؛ التوفر لازم يتأكد قبل أي وعد بالحجز.
     */
    private function bookableTripsBlock(int $websiteId): string
    {
        try {
            $db = Database::getInstance();
            $owner = (int) ($db->query('SELECT user_id FROM websites WHERE id = ? LIMIT 1', [$websiteId])[0]['user_id'] ?? 0);
            if ($owner < 1) {
                return '';
            }
            $rows = $db->query(
                'SELECT name, price, currency, description FROM crm_products
                 WHERE user_id = ? AND is_active = 1 AND (website_id = ? OR website_id IS NULL)
                 ORDER BY name LIMIT 40',
                [$owner, $websiteId]
            );
        } catch (Throwable $e) {
            return '';
        }
        if (!$rows) {
            return '';
        }
        $lines = [];
        foreach ($rows as $r) {
            $price = (float) $r['price'] > 0 ? ' - ' . rtrim(rtrim(number_format((float) $r['price'], 2, '.', ''), '0'), '.') . ' ' . ($r['currency'] ?: 'USD') . ' per person' : '';
            $desc = trim(preg_replace('/\s+/u', ' ', (string) ($r['description'] ?? '')));
            $lines[] = '- ' . trim((string) $r['name']) . $price . ($desc !== '' ? ': ' . mb_substr($desc, 0, 300) : '');
        }
        return "\n[Bookable trips and prices (from the company's booking system; always confirm availability for the requested date before promising a booking)]\n" . implode("\n", $lines) . "\n";
    }

    /**
     * إعادة ترتيب عناصر قاعدة المعرفة حسب صلة كل عنصر برسالة العميل
     * (Re-ranking / Retrieval). خوارزمية لغة محايدة (agnostic): تقطيع
     * الكلمات، وتجاهل علامات الترقيم واختلاف الحالة، ووزن الصلة من
     * العنوان والمحتوى مع معاملات قسم/أولوية. لا تتطلب أي خدمة خارجية.
     *
     * @param array $entries عناصر AiKnowledgeBase
     * @param string $customerMessage
     * @param int $limit أقصى عدد عناصر يُرجَع
     * @return array العناصر مرتبة تنازليًا حسب الصلة
     */
    public function rerankForQuery(array $entries, string $customerMessage, int $limit = 10): array
    {
        if (empty($entries)) {
            return [];
        }
        $queryTokens = $this->tokenize($customerMessage);
        if (empty($queryTokens)) {
            return array_slice($entries, 0, $limit);
        }

        // أوزان أقسام: بعض الأقسام أقرب لنية العميل في المحادثة (FAQ/سعر/جولة)
        $sectionWeights = [
            'faq' => 1.4,
            'pricing' => 1.3,
            'service' => 1.2,
            'tour' => 1.3,
            'destination' => 1.2,
            'policy' => 1.1,
            'cancellation_policy' => 1.1,
            'contact_info' => 1.0,
            'business_hours' => 1.0,
            'company_info' => 0.9,
            'custom_instructions' => 0.9,
        ];

        $scored = [];
        foreach ($entries as $entry) {
            $title = (string) $entry->getAttribute('title');
            $content = (string) $entry->getAttribute('content');
            $section = (string) $entry->getAttribute('section');
            $priority = (int) $entry->getAttribute('priority');

            $titleTokens = $this->tokenize($title);
            $contentTokens = $this->tokenize($content);

            $titleHits = $this->countOverlap($queryTokens, $titleTokens);
            $contentHits = $this->countOverlap($queryTokens, $contentTokens);

            // الصلة: تطابق العنوان أعلى وزنًا من المحتوى (أسلوب RAG قياسي)
            $score = ($titleHits * 2.0) + ($contentHits * 1.0);

            if ($score <= 0) {
                $score = 0.05; // حد أدنى حتى لا يختفي محتوى لا يطابقه أي كلمة
            }

            $score *= ($sectionWeights[$section] ?? 1.0);
            $score += min(2.0, $priority * 0.5); // الأولوية الأعلى تُزيح قليلًا

            $scored[] = ['entry' => $entry, 'score' => $score];
        }

        usort($scored, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return array_slice(array_map(function ($item) {
            return $item['entry'];
        }, $scored), 0, $limit);
    }

    /**
     * تقطيع نص إلى كلمات مع تطبيع محايد للغة (حروف صغيرة + إزالة ترقيم
     * + استثناء الكلمات الأكثر شيوعًا التي لا تحمل معنى).
     * @param string $text
     * @return string[]
     */
    private function tokenize(string $text): array
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return [];
        }
        $text = preg_replace('/[\p{P}\p{S}]/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (empty($words)) {
            return [];
        }

        $stopWords = [
            'the', 'a', 'an', 'is', 'are', 'was', 'were', 'to', 'of', 'in',
            'on', 'for', 'and', 'or', 'with', 'about', 'at', 'do', 'does',
            'did', 'i', 'you', 'we', 'can', 'could', 'will', 'would', 'what',
            'how', 'when', 'where', 'who', 'which', 'it', 'its', 'my', 'your',
            'our', 'their', 'this', 'that', 'these', 'those', 'have', 'has',
            'had', 'be', 'by', 'from', 'as', 'than', 'then', 'there', 'please',
            'hi', 'hello', 'hey', 'thank', 'thanks', 'yes', 'no', 'ok', 'okay',
            'على', 'من', 'عن', 'في', 'إلى', 'الى', 'ما', 'لا', 'هل', 'و',
            'التي', 'الذي', 'هذا', 'هذه', 'مع', 'بين', 'بعد', 'قبل', 'كل',
        ];

        return array_values(array_filter($words, function ($word) use ($stopWords) {
            $word = trim($word);
            return $word !== '' && mb_strlen($word) >= 2 && !in_array($word, $stopWords, true);
        }));
    }

    /**
     * عدد كلمات الاستعلام المطابقة في نص مرجعي (لكلمة تظهر مرتين تُحسب مرتين).
     * @param string[] $queryTokens
     * @param string[] $targetTokens
     * @return int
     */
    private function countOverlap(array $queryTokens, array $targetTokens): int
    {
        if (empty($queryTokens) || empty($targetTokens)) {
            return 0;
        }
        $targetCounts = array_count_values($targetTokens);
        $hits = 0;
        foreach ($queryTokens as $token) {
            if (isset($targetCounts[$token]) && $targetCounts[$token] > 0) {
                $hits++;
                $targetCounts[$token]--;
            }
        }
        return $hits;
    }
}
