<?php

/**
 * Tourfecto - Outreach Email Generator
 * Phase 10 (Backlink/Outreach Agent). بيولّد رسالة تواصل مخصصة فعليًا
 * لكل Prospect بناءً على بياناته الحقيقية (دومين/نوع نشاط/صفحة محددة/فكرة
 * تعاون) - مفيش "Dear Sir/Madam" ولا رسائل عامة، بالظبط زي ما السبيك
 * طلبت صراحةً. لو أي بيانات أساسية ناقصة (خصوصًا relevant_page)، بنطلب
 * نكمّلها الأول بدل ما نولّد رسالة عامة ضعيفة.
 * @version 1.0.0
 */
class OutreachEmailGenerator
{
    /** @var mixed أي كائن عنده generateContent($prompt,$options):array - عادة AIOrchestrator */
    private $ai;

    public function __construct($ai = null)
    {
        $this->ai = $ai ?? (class_exists('AIOrchestrator') ? new AIOrchestrator() : new GeminiClient());
    }

    /**
     * @param array $prospect بيانات الـProspect (domain, business_type, relevant_page, collaboration_idea, contact_name)
     * @param array $myWebsite بيانات موقعي (company_name, main_url, industry)
     * @param int $sequenceNumber 0 = أول رسالة، 1/2/3 = متابعة
     * @return array
     */
    public function generate(array $prospect, array $myWebsite, int $sequenceNumber = 0): array
    {
        if (empty($prospect['domain'])) {
            return ['success' => false, 'error' => 'دومين الـProspect مطلوب'];
        }
        // لازم سبب حقيقي للتواصل - رسالة بدون relevant_page أو collaboration_idea
        // بتبقى عامة وضعيفة بالظبط زي اللي السبيك بترفضها صراحةً.
        if ($sequenceNumber === 0 && empty($prospect['relevant_page']) && empty($prospect['collaboration_idea'])) {
            return ['success' => false, 'error' => 'محتاج صفحة ذات صلة أو فكرة تعاون محددة الأول عشان الرسالة تبقى مخصصة فعليًا مش عامة'];
        }

        $prompt = $sequenceNumber === 0
            ? $this->buildInitialPrompt($prospect, $myWebsite)
            : $this->buildFollowUpPrompt($prospect, $myWebsite, $sequenceNumber);

        $response = $this->ai->generateContent($prompt, [
            'maxOutputTokens' => 2048,
            'responseMimeType' => 'application/json',
            'task' => 'content_generation', 'output_language'=>'en',
        ]);

        if (!($response['success'] ?? false)) {
            return ['success' => false, 'error' => $response['error'] ?? 'فشل الاتصال بمحرك الذكاء الاصطناعي'];
        }

        $parsed = $this->extractJson((string) ($response['data'] ?? ''));
        if (!$parsed || empty($parsed['subject']) || empty($parsed['body'])) {
            return ['success' => false, 'error' => 'تعذّر تحليل رد الذكاء الاصطناعي إلى رسالة منظّمة'];
        }

        return [
            'success' => true,
            'data' => [
                'subject' => (string) $parsed['subject'],
                'body' => (string) $parsed['body'],
            ],
            'tokens_used' => $response['tokens_used'] ?? 0,
            'cost' => $response['cost'] ?? 0,
        ];
    }

    private function buildInitialPrompt(array $prospect, array $myWebsite): string
    {
        $domain = $prospect['domain'];
        $businessType = $prospect['business_type'] ?? 'موقع سياحي';
        $relevantPage = $prospect['relevant_page'] ?? '';
        $collaborationIdea = $prospect['collaboration_idea'] ?? '';
        $contactName = $prospect['contact_name'] ?? '';
        $myCompany = $myWebsite['company_name'] ?? 'شركتنا';
        $myUrl = $myWebsite['main_url'] ?? '';
        $myIndustry = $myWebsite['industry'] ?? 'سياحة';

        $greetingHint = $contactName !== ''
            ? "اسم الشخص المسؤول (لو معروف): {$contactName} - استخدمه في التحية لو مناسب."
            : "مفيش اسم شخص معروف - استخدم تحية مهنية باسم الموقع نفسه (مثلاً \"فريق {$domain}\")، ممنوع تستخدم Dear Sir/Madam أو أي تحية عامة.";

        return <<<PROMPT
أنت مسؤول Outreach محترف في شركة سياحة اسمها {$myCompany} ({$myUrl}), نشاطها {$myIndustry}.
اكتب إيميل تواصل أول (Cold Outreach) قصير واحترافي لموقع {$businessType} اسم الدومين بتاعه {$domain}.

{$greetingHint}

السبب المحدد للتواصل: {$relevantPage}
فكرة التعاون المقترحة: {$collaborationIdea}

قواعد صارمة:
- ممنوع أي تحية عامة زي "Dear Sir/Madam" أو "To whom it may concern".
- لازم تشاور صريح للصفحة/المحتوى المحدد بتاعهم اللي خلاك تتواصل معاهم.
- الإيميل قصير (أقل من 150 كلمة)، نبرة إنسانية مش قالب جاهز، بدون مبالغة أو وعود مش حقيقية.
- ممنوع تدّعي حاجة مش صحيحة (زي "قرأت مقالك بالتفصيل" لو المعلومة الوحيدة المتاحة هي اسم الصفحة بس).
- اختم برسالة تعاون واضحة وسؤال بسيط (مش إلحاح).

رجّع الرد **بصيغة JSON فقط**:
{"subject": "عنوان الإيميل (قصير وواضح، مش Spam-y)", "body": "نص الإيميل الكامل"}
PROMPT;
    }

    private function buildFollowUpPrompt(array $prospect, array $myWebsite, int $sequenceNumber): string
    {
        $domain = $prospect['domain'];
        $myCompany = $myWebsite['company_name'] ?? 'شركتنا';
        $tone = match ($sequenceNumber) {
            1 => 'ودود ومختصر جدًا - مجرد تذكير لطيف، مش إلحاح',
            2 => 'مختصر جدًا، اطرح زاوية تعاون بديلة أبسط لو الأصلية معقدة',
            default => 'رسالة أخيرة مهذبة - اقفل الموضوع بلطف لو مفيش رد',
        };

        return <<<PROMPT
أنت مسؤول Outreach في {$myCompany}. اكتب رسالة متابعة رقم {$sequenceNumber} لموقع {$domain}
بعد ما بعتنا رسالة تواصل أولى ومفيش رد. النبرة: {$tone}.
قصيرة جدًا (أقل من 60 كلمة)، من غير تكرار كل تفاصيل الرسالة الأولى، بس إشارة سريعة ليها.

رجّع الرد **بصيغة JSON فقط**:
{"subject": "عنوان الإيميل (ممكن يبدأ بـ Re: )", "body": "نص الرسالة"}
PROMPT;
    }

    private function extractJson(string $text): ?array
    {
        if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
            $data = json_decode($m[0], true);
            return json_last_error() === JSON_ERROR_NONE ? $data : null;
        }
        return null;
    }
}
