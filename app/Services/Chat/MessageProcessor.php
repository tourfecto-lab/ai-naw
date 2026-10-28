<?php

/**
 * Tourfecto - Message Processor
 * معالج الرسائل لتحليل وتصنيف الرسائل الواردة
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class MessageProcessor
{
    /**
     * @var array $intentPatterns - أنماط التعرف على النوايا
     */
    private $intentPatterns = [];

    /**
     * @var array $stopWords - كلمات توقف
     */
    private $stopWords = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->loadIntentPatterns();
        $this->loadStopWords();
    }

    /**
     * معالجة الرسالة
     * @param array $webhookData
     * @param int $userId
     * @param int $websiteId
     * @param array $botSettings
     * @return array
     */
    public function process(array $webhookData, int $userId, int $websiteId, array $botSettings): array
    {
        $message = $webhookData['message'];
        $phoneNumber = $webhookData['phone_number'];

        // 1. تنظيف الرسالة
        $cleanedMessage = $this->cleanMessage($message);

        // 2. تحديد اللغة
        $language = $this->detectLanguage($cleanedMessage);

        // 3. تحليل النية
        $intent = $this->detectIntent($cleanedMessage);

        // 4. استخراج الكيانات
        $entities = $this->extractEntities($cleanedMessage);

        // 5. تحليل المشاعر
        $sentiment = $this->analyzeSentiment($cleanedMessage);

        // 6. التحقق من الكلمات المحظورة
        $hasBlockedWords = $this->checkBlockedWords($cleanedMessage, $botSettings);

        // 7. إنشاء سياق المحادثة
        $context = $this->buildContext($phoneNumber, $intent, $entities, $sentiment);

        return [
            'success' => true,
            'cleaned_message' => $cleanedMessage,
            'language' => $language,
            'intent' => $intent,
            'entities' => $entities,
            'sentiment' => $sentiment,
            'has_blocked_words' => $hasBlockedWords,
            'context' => $context,
            'session_id' => $this->generateSessionId($phoneNumber),
            'auto_pilot' => $botSettings['auto_pilot'] ?? false
        ];
    }

    /**
     * تنظيف الرسالة
     * @param string $message
     * @return string
     */
    public function cleanMessage(string $message): string
    {
        $message = trim($message);
        $message = preg_replace('/\s+/', ' ', $message);
        $message = preg_replace('/https?:\/\/[^\s]+/', '', $message);
        $message = preg_replace('/[0-9]+/', '', $message);
        $message = preg_replace('/[!?.]+/', '.', $message);
        $message = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $message);

        return $message;
    }

    /**
     * تحديد اللغة
     * @param string $text
     * @return string
     */
    public function detectLanguage(string $text): string
    {
        $arabicPattern = '/[\x{0600}-\x{06FF}]/u';
        if (preg_match($arabicPattern, $text)) {
            return 'ar';
        }

        $englishPattern = '/[a-zA-Z]/';
        if (preg_match($englishPattern, $text)) {
            return 'en';
        }

        return 'unknown';
    }

    /**
     * تحليل نية الرسالة
     * @param string $message
     * @return array
     */
    public function detectIntent(string $message): array
    {
        $messageLower = strtolower($message);
        $intents = [];

        foreach ($this->intentPatterns as $intent => $patterns) {
            foreach ($patterns as $pattern) {
                if (strpos($messageLower, $pattern) !== false) {
                    $intents[] = $intent;
                    break;
                }
            }
        }

        if (empty($intents)) {
            return [
                'primary' => 'general',
                'secondary' => [],
                'confidence' => 0.3
            ];
        }

        $primary = $intents[0];
        $secondary = array_slice($intents, 1);

        return [
            'primary' => $primary,
            'secondary' => $secondary,
            'confidence' => min(1, count($intents) * 0.2 + 0.3)
        ];
    }

    /**
     * استخراج الكيانات من الرسالة
     * @param string $message
     * @return array
     */
    public function extractEntities(string $message): array
    {
        $entities = [];

        // استخراج التواريخ
        $datePattern = '/(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}|\d{1,2}\s+(يناير|فبراير|مارس|أبريل|مايو|يونيو|يوليو|أغسطس|سبتمبر|أكتوبر|نوفمبر|دیسمبر|January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{2,4})/i';
        preg_match($datePattern, $message, $dateMatches);
        if (!empty($dateMatches)) {
            $entities['date'] = $dateMatches[0];
        }

        // استخراج الأرقام
        $numberPattern = '/\d+(?:\.\d+)?/';
        preg_match_all($numberPattern, $message, $numberMatches);
        if (!empty($numberMatches[0])) {
            $entities['numbers'] = $numberMatches[0];
        }

        // استخراج الأماكن
        $locationPattern = '/في\s+([\p{L}\s]+)|to\s+([\p{L}\s]+)|من\s+([\p{L}\s]+)/ui';
        preg_match($locationPattern, $message, $locationMatches);
        if (!empty($locationMatches)) {
            $entities['locations'] = array_filter(array_slice($locationMatches, 1));
        }

        // استخراج أسماء الأشخاص
        $namePattern = '/اسمي\s+([\p{L}\s]+)|my name is\s+([\p{L}\s]+)/ui';
        preg_match($namePattern, $message, $nameMatches);
        if (!empty($nameMatches)) {
            $entities['name'] = $nameMatches[1] ?? $nameMatches[2] ?? null;
        }

        return $entities;
    }

    /**
     * تحليل المشاعر
     * @param string $message
     * @return array
     */
    public function analyzeSentiment(string $message): array
    {
        $positiveWords = ['رائع', 'ممتاز', 'جيد', 'جميل', 'مذهل', 'أفضل', 'مثالي', 'سعيد', 'شكراً', 'great', 'good', 'excellent', 'amazing', 'happy', 'thanks'];
        $negativeWords = ['سيئ', 'مخيب', 'فاشل', 'رديء', 'محبط', 'غاضب', 'أسوأ', 'سيء', 'bad', 'terrible', 'awful', 'disappointed', 'worst', 'angry'];

        $messageLower = strtolower($message);
        $positiveCount = 0;
        $negativeCount = 0;

        foreach ($positiveWords as $word) {
            if (strpos($messageLower, strtolower($word)) !== false) {
                $positiveCount++;
            }
        }

        foreach ($negativeWords as $word) {
            if (strpos($messageLower, strtolower($word)) !== false) {
                $negativeCount++;
            }
        }

        $score = 0.5;
        $label = 'neutral';

        if ($positiveCount > $negativeCount) {
            $score = 0.7 + (($positiveCount - $negativeCount) / 20);
            $label = 'positive';
        } elseif ($negativeCount > $positiveCount) {
            $score = 0.3 - (($negativeCount - $positiveCount) / 20);
            $label = 'negative';
        }

        $score = max(0, min(1, $score));

        return [
            'label' => $label,
            'score' => $score,
            'positive_words' => $positiveCount,
            'negative_words' => $negativeCount,
            'confidence' => min(1, ($positiveCount + $negativeCount) / 10)
        ];
    }

    /**
     * التحقق من الكلمات المحظورة
     * @param string $message
     * @param array $botSettings
     * @return bool
     */
    public function checkBlockedWords(string $message, array $botSettings): bool
    {
        $blockedKeywords = $botSettings['blocked_keywords'] ?? [];

        if (empty($blockedKeywords)) {
            return false;
        }

        $messageLower = strtolower($message);

        foreach ($blockedKeywords as $keyword) {
            if (strpos($messageLower, strtolower($keyword)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * بناء سياق المحادثة
     * @param string $phoneNumber
     * @param array $intent
     * @param array $entities
     * @param array $sentiment
     * @return array
     */
    public function buildContext(string $phoneNumber, array $intent, array $entities, array $sentiment): array
    {
        return [
            'phone_number' => $phoneNumber,
            'intent' => $intent,
            'entities' => $entities,
            'sentiment' => $sentiment,
            'timestamp' => date('Y-m-d H:i:s'),
            'turn_count' => 0
        ];
    }

    /**
     * توليد معرف جلسة
     * @param string $phoneNumber
     * @return string
     */
    private function generateSessionId(string $phoneNumber): string
    {
        return 'session_' . md5($phoneNumber . '_' . date('Y-m-d'));
    }

    /**
     * تحميل أنماط النوايا
     */
    private function loadIntentPatterns(): void
    {
        $this->intentPatterns = [
            'booking' => ['حجز', 'احجز', 'احج', 'اريد حجز', 'booking', 'book', 'reserve', 'reservation'],
            'inquiry' => ['استفسار', 'سؤال', 'استعلام', 'inquiry', 'question', 'ask', 'query'],
            'complaint' => ['شكوى', 'شكوي', 'مشكلة', 'اشكالي', 'complaint', 'issue', 'problem', 'complaint'],
            'pricing' => ['سعر', 'اسعار', 'تكلفة', 'ثمن', 'price', 'cost', 'pricing', 'fee'],
            'location' => ['عنوان', 'موقع', 'مكان', 'address', 'location', 'where', 'find'],
            'hours' => ['ساعات', 'وقت', 'دوام', 'hours', 'time', 'open', 'close'],
            'cancellation' => ['الغاء', 'إلغاء', 'cancellation', 'cancel', 'refund'],
            'support' => ['دعم', 'مساعدة', 'مساعد', 'help', 'support', 'assist'],
            'general' => ['general', 'hello', 'مرحبا', 'اهلا', 'hi']
        ];
    }

    /**
     * تحميل كلمات التوقف
     */
    private function loadStopWords(): void
    {
        $this->stopWords = [
            'ar' => ['في', 'من', 'عن', 'على', 'الى', 'ما', 'هذا', 'ذلك', 'هذه', 'تلك', 'مع', 'بين', 'بعد', 'قبل'],
            'en' => ['the', 'a', 'an', 'is', 'are', 'am', 'was', 'were', 'be', 'been', 'being', 'to', 'of', 'and', 'for']
        ];
    }
}
