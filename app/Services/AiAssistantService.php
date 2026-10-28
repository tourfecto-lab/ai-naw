<?php

/**
 * Tourfecto - AI Assistant Service
 * مساعد ذكاء اصطناعي عام بهوية المنصة بالكامل (مش أي منتج تاني) -
 * بيستخدم Gemini API (نفس المفتاح المستخدم في كل الموقع)، والدفع إما
 * من رصيد المحفظة (ادفع حسب الاستخدام) أو مجانًا لو مفعّل في الباقة.
 * @version 1.1.0
 */
class AiAssistantService
{
    /** @var GeminiClient */
    private $gemini;

    /** التعليمات الأساسية للمساعد - يقدّم نفسه كجزء من المنصة، مش يذكر أي مزوّد ذكاء اصطناعي خارجي.
     * بنطلب منه صراحة يستخدم تنسيق Markdown بسيط عشان الواجهة بتعرضه
     * منسّق فعليًا (عناوين بارزة، نقاط، تركيز) بدل نص عادي مسطّح. */
    private const SYSTEM_INSTRUCTION = "إنت \"المساعد الذكي\" جوه منصة Tourfecto لتسويق الأعمال السياحية - مساعد محترف وسريع البديهة، مش أي منتج ذكاء اصطناعي تاني. مهمتك تساعد صاحب العمل السياحي (فندق، شركة رحلات، مرشد سياحي، مكتب حجوزات...) في أي حاجة يحتاجها: كتابة محتوى تسويقي (وصف باقات سياحية، عروض، بوستات سوشيال ميديا، نصوص لموقعه)، الرد على تقييمات العملاء، أفكار وحملات تسويقية، ترجمة نصوص، أو أي استفسار عام يخص شغله.\n\nلما يكون الرد فيه أكتر من فكرة أو خطوات، استخدم تنسيق Markdown بسيط عشان يبان منظم: نجمتين حوالين أي حاجة مهمة **زي كده**، شرطة في أول السطر للنقاط - زي كده، وأرقام لو الترتيب مهم. من غير حشو أو مقدمات طويلة.\n\nقاعدة مهمة جدًا: لما تكتب عن رحلة أو باقة أو خدمة معينة، ماتخترعش أي معلومة محددة صاحب العمل ماقالهاش - زي السعر، المدة، اللي شامله السعر، المواصلات، الوجبات، لغة المرشد، مكان التجمع، أو سياسة الإلغاء - لأن النص ده هيتنشر لسياح حقيقيين وأي معلومة غلط بتعمل شكوى. مكان أي تفصيلة مش معروفة حط علامة واضحة زي [المواصلات] أو [السعر]، وفي آخر الرد اكتب سطر قصير: \"قبل ما تنشر: املأ ...\" بالحاجات اللي محتاج يكملها. الوصف الجذاب والعاطفي مسموح، المعلومات المحددة لأ.\n\nردودك بالعربي المصري الواضح ما لم يُطلب غير كده - عملية، مباشرة، ومفيدة فعليًا لصاحب عمل مشغول.";

    /** أقصى طول لعنوان المحادثة اللي بيتولّد تلقائيًا من أول رسالة */
    private const AUTO_TITLE_MAX_LENGTH = 42;

    public function __construct()
    {
        $this->gemini = new GeminiClient();
    }

    /** إنشاء محادثة جديدة لمستخدم */
    public function createConversation(int $userId, string $title = 'محادثة جديدة'): AiConversation
    {
        $conversation = new AiConversation();
        $conversation->fill(['user_id' => $userId, 'title' => $title]);
        $conversation->save();
        return $conversation;
    }

    /** كل محادثات مستخدم، الأحدث أولاً */
    public function getConversations(int $userId, int $limit = 30): array
    {
        return (new AiConversation())->where(['user_id' => $userId], ['updated_at' => 'DESC'], $limit);
    }

    /** كل رسائل محادثة معيّنة بالترتيب */
    public function getMessages(int $conversationId): array
    {
        return (new AiMessage())->where(['conversation_id' => $conversationId], ['created_at' => 'ASC']);
    }

    /**
     * إرسال رسالة جديدة وإرجاع رد المساعد. بيتأكد الأول إن العميل قادر
     * يدفع (اشتراك يشمل الميزة، أو رصيد محفظة كافي)، وبيخصم الثمن فعليًا
     * بس لو الرد نجح فعلاً (مش قبل ما نتأكد إن الرد اتولّد صح). أول رسالة
     * في أي محادثة بتحدد عنوانها تلقائيًا (بدل "محادثة جديدة" الثابتة)
     * عشان القائمة الجانبية تبقى مفيدة فعلاً وتساعد العميل يلاقي محادثته
     * تاني من غير ما يفتحها.
     */
    public function sendMessage(int $userId, int $conversationId, string $userMessage): array
    {
        $walletService = new WalletService();
        // الباقة الأول (حصة رسائل المساعد)، والمحفظة لو الحصة خلصت أو مفيش اشتراك
        $priceCheck = PlanUsage::check($userId, 'ai_assistant_message', 'ai_assistant_message', 'رصيدك في المحفظة مش كافي لإرسال رسالة جديدة');

        if (!$priceCheck['ok']) {
            return [
                'success' => false,
                'error' => $priceCheck['error'],
                'shortfall' => $priceCheck['shortfall'] ?? null,
            ];
        }

        // احفظ رسالة المستخدم فورًا
        $userMsg = new AiMessage();
        $userMsg->fill(['conversation_id' => $conversationId, 'role' => 'user', 'content' => $userMessage]);
        $userMsg->save();

        // ابني الـ prompt الكامل بالسياق (آخر 10 رسائل بس - كفاية للسياق من غير إسراف في التوكنات)
        $history = $this->getMessages($conversationId);
        $isFirstMessageInConversation = count($history) === 1;
        $recentHistory = array_slice($history, -10);
        $contextText = self::SYSTEM_INSTRUCTION . "\n\n";
        foreach ($recentHistory as $msg) {
            $roleLabel = $msg->getAttribute('role') === 'user' ? 'المستخدم' : 'المساعد';
            $contextText .= "{$roleLabel}: " . $msg->getAttribute('content') . "\n";
        }
        $contextText .= "المساعد:";

        $response = $this->gemini->generateContent($contextText, ['task'=>'ai_assistant']);

        if (!$response['success']) {
            return ['success' => false, 'error' => 'تعذر الحصول على رد - جرّب تاني'];
        }

        $assistantText = trim((string) $response['data']);

        $assistantMsg = new AiMessage();
        $assistantMsg->fill(['conversation_id' => $conversationId, 'role' => 'assistant', 'content' => $assistantText]);
        $assistantMsg->save();

        // الخصم بس بعد نجاح الرد فعليًا - من حصة الباقة لو فيه، وإلا من المحفظة
        $paidBy = PlanUsage::charge($userId, 'ai_assistant_message', 'ai_assistant_message', 'رسالة مساعد ذكي');

        // تحديث وقت آخر تعديل للمحادثة (يظهر فوق في القائمة)، وتوليد
        // عنوان تلقائي من أول رسالة لو المحادثة كانت لسه بعنوانها الافتراضي
        $conversation = (new AiConversation())->find($conversationId);
        $newTitle = null;
        if ($conversation) {
            if ($isFirstMessageInConversation) {
                $newTitle = $this->deriveTitleFromMessage($userMessage);
                $conversation->fill(['title' => $newTitle]);
            }
            $conversation->save();
        }

        return [
            'success' => true,
            'reply' => $assistantText,
            'new_balance' => $walletService->getBalance($userId),
            'paid_by' => $paidBy,
            'conversation_title' => $newTitle,
        ];
    }

    /**
     * إعادة توليد آخر رد للمساعد في محادثة معيّنة (لو الرد ماكانش مقنع
     * كفاية). بتمسح آخر رد قديم وتولّد بديل مكانه بنفس سياق المحادثة،
     * وبتتحاسب زي أي رسالة عادية (نفس سعر "ادفع حسب الاستخدام").
     */
    public function regenerateLastResponse(int $userId, int $conversationId): array
    {
        $walletService = new WalletService();
        $priceCheck = PlanUsage::check($userId, 'ai_assistant_message', 'ai_assistant_message', 'رصيدك في المحفظة مش كافي لإعادة توليد الرد');

        if (!$priceCheck['ok']) {
            return [
                'success' => false,
                'error' => $priceCheck['error'],
                'shortfall' => $priceCheck['shortfall'] ?? null,
            ];
        }

        $history = $this->getMessages($conversationId);
        if (empty($history)) {
            return ['success' => false, 'error' => 'مفيش رسائل في المحادثة دي لسه'];
        }

        $lastMessage = end($history);
        if ($lastMessage->getAttribute('role') !== 'assistant') {
            return ['success' => false, 'error' => 'مفيش رد سابق لإعادة توليده'];
        }

        $lastMessage->delete();

        $historyWithoutLastReply = array_slice($history, 0, -1);
        $recentHistory = array_slice($historyWithoutLastReply, -10);
        $contextText = self::SYSTEM_INSTRUCTION . "\n\n";
        foreach ($recentHistory as $msg) {
            $roleLabel = $msg->getAttribute('role') === 'user' ? 'المستخدم' : 'المساعد';
            $contextText .= "{$roleLabel}: " . $msg->getAttribute('content') . "\n";
        }
        $contextText .= "المساعد:";

        $response = $this->gemini->generateContent($contextText, ['task'=>'ai_assistant']);

        if (!$response['success']) {
            return ['success' => false, 'error' => 'تعذر إعادة توليد الرد - جرّب تاني'];
        }

        $assistantText = trim((string) $response['data']);

        $assistantMsg = new AiMessage();
        $assistantMsg->fill(['conversation_id' => $conversationId, 'role' => 'assistant', 'content' => $assistantText]);
        $assistantMsg->save();

        $paidBy = PlanUsage::charge($userId, 'ai_assistant_message', 'ai_assistant_message', 'إعادة توليد رد المساعد الذكي');

        $conversation = (new AiConversation())->find($conversationId);
        if ($conversation) {
            $conversation->save();
        }

        return ['success' => true, 'reply' => $assistantText, 'new_balance' => $walletService->getBalance($userId), 'paid_by' => $paidBy];
    }

    /** تعديل عنوان محادثة يدويًا (العميل ضغط على تعديل الاسم من القائمة الجانبية) */
    public function renameConversation(int $conversationId, string $title): AiConversation
    {
        $conversation = (new AiConversation())->find($conversationId);
        if (!$conversation) {
            throw new Exception('المحادثة غير موجودة');
        }

        $cleanTitle = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
        if ($cleanTitle === '') {
            $cleanTitle = 'محادثة جديدة';
        }
        $cleanTitle = mb_substr($cleanTitle, 0, 100);

        $conversation->fill(['title' => $cleanTitle]);
        $conversation->save();

        return $conversation;
    }

    /** حذف محادثة (ورسائلها) */
    public function deleteConversation(int $conversationId): void
    {
        $db = Database::getInstance();
        $db->exec("DELETE FROM ai_assistant_messages WHERE conversation_id = ?", [$conversationId]);
        $db->exec("DELETE FROM ai_assistant_conversations WHERE id = ?", [$conversationId]);
    }

    /**
     * يشتق عنوان قصير مفيد من أول رسالة للمستخدم بدل "محادثة جديدة"
     * الثابتة - بيقطع عند آخر مسافة قبل الحد الأقصى عشان منقصش كلمة
     * نص نص، وبيرجّع نقط "…" لو فعلاً قصّ حاجة.
     */
    private function deriveTitleFromMessage(string $message): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', $message) ?? '');
        if ($clean === '') {
            return 'محادثة جديدة';
        }
        if (mb_strlen($clean) <= self::AUTO_TITLE_MAX_LENGTH) {
            return $clean;
        }
        $truncated = mb_substr($clean, 0, self::AUTO_TITLE_MAX_LENGTH);
        $lastSpace = mb_strrpos($truncated, ' ');
        if ($lastSpace !== false && $lastSpace > 15) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }
        return $truncated . '…';
    }
}
