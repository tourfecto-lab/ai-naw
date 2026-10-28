<?php

/**
 * AI Chat Platform - Real Integration Test Harness
 * يشتغل ضد قاعدة بيانات MariaDB حقيقية محلية (مش mock)، بيستخدم كود
 * المشروع الفعلي بدون أي تعديل. الهدف: تنفيذ حقيقي بدل التحليل الساكن
 * فقط (php -l) اللي كان متاح لحد دلوقتي.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ============================================
// بيئة الاختبار
// ============================================
putenv('DB_HOST=localhost');
putenv('DB_NAME=tourfecto_db');
putenv('DB_USER=root');
putenv('DB_PASS=');
putenv('APP_DEBUG=true');
putenv('APP_ENV=testing');
putenv('ENCRYPTION_KEY=base64:dGVzdGtleWZvcmludGVncmF0aW9udGVzdHMxMjM0NTY=');
putenv('AI_PROVIDER_PRIORITY=gemini');
putenv('GEMINI_API_KEY=');
putenv('OPENAI_API_KEY=');
putenv('DEEPSEEK_API_KEY=');
putenv('KIMI_API_KEY=');

$root = dirname(__DIR__);
define('ROOT_PATH', $root);
define('APP_PATH', $root . '/app');
define('TOURFECTO_ROOT', $root);
define('TOURFECTO_STORAGE', $root . '/storage');
putenv('APP_URL=http://localhost');

function env(string $key, $default = null)
{
    if (array_key_exists($key, $_ENV)) {
        return $_ENV[$key];
    }
    $v = getenv($key);
    return $v !== false ? $v : $default;
}

require_once $root . '/app/Config/app.php';
require_once $root . '/app/Config/database.php';
require_once $root . '/app/Config/encryption.php';
require_once $root . '/app/Config/constants.php';
require_once $root . '/app/Config/gemini.php';
require_once $root . '/app/Config/whatsapp.php';
require_once $root . '/app/Config/openai.php';
require_once $root . '/app/Config/deepseek.php';
require_once $root . '/app/Config/kimi.php';

require_once $root . '/app/Core/Database.php';
require_once $root . '/app/Core/Encryption.php';
require_once $root . '/app/Core/Logger.php';
require_once $root . '/app/Core/Model.php';
require_once $root . '/app/Core/Cache.php';

require_once $root . '/app/Models/Website.php';
require_once $root . '/app/Models/Notification.php';
require_once $root . '/app/Models/ChatMessage.php';
require_once $root . '/app/Models/AiKnowledgeBase.php';
require_once $root . '/app/Models/AiChatConversation.php';
require_once $root . '/app/Models/AiCustomerMemory.php';
require_once $root . '/app/Models/AiLead.php';
require_once $root . '/app/Models/AiFollowup.php';
require_once $root . '/app/Models/AiFollowupRule.php';
require_once $root . '/app/Models/AiCustomTag.php';
require_once $root . '/app/Models/AiUsageLog.php';

require_once $root . '/app/Services/AI/Providers/AIProviderInterface.php';
require_once $root . '/app/Services/AI/Providers/OpenAICompatibleProvider.php';
require_once $root . '/app/Services/AI/Providers/GeminiProvider.php';
require_once $root . '/app/Services/AI/Providers/OpenAIProvider.php';
require_once $root . '/app/Services/AI/Providers/DeepSeekProvider.php';
require_once $root . '/app/Services/AI/Providers/KimiProvider.php';
require_once $root . '/app/Services/AI/Providers/AIProviderManager.php';
require_once $root . '/app/Services/AI/KnowledgeBaseService.php';
require_once $root . '/app/Services/Chat/UnifiedInboxService.php';
require_once $root . '/app/Services/AI/LeadScoringService.php';
require_once $root . '/app/Services/AI/FollowUpAutomationService.php';
require_once $root . '/app/Services/AI/AiAnalyticsService.php';
require_once $root . '/app/Services/AI/BusinessHoursService.php';
require_once $root . '/app/Services/AI/AIConversationEngine.php';

// GeminiClient مطلوب لـ GeminiProvider، وChatManager لـ FollowUpAutomationService
if (file_exists($root . '/app/Services/AI/GeminiClient.php')) {
    require_once $root . '/app/Services/AI/GeminiClient.php';
}

// ============================================
// Test runner بسيط
// ============================================
$passed = 0;
$failed = 0;
$failures = [];

function check(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed, $failures;
    if ($condition) {
        $passed++;
        echo "  ✅ {$label}\n";
    } else {
        $failed++;
        $failures[] = $label . ($detail ? " ({$detail})" : '');
        echo "  ❌ {$label}" . ($detail ? " - {$detail}" : '') . "\n";
    }
}

function section(string $title): void
{
    echo "\n=== {$title} ===\n";
}

try {
    $db = Database::getInstance();
    $db->query("SELECT 1");
    echo "✅ الاتصال بقاعدة البيانات الحقيقية نجح\n";
} catch (Throwable $e) {
    echo "❌ فشل الاتصال بقاعدة البيانات: " . $e->getMessage() . "\n";
    exit(1);
}

// ============================================
// إعداد بيانات اختبار: مستخدمين وموقعين (Multi-tenant)
// ============================================
section('إعداد بيانات الاختبار');

$db->query("DELETE FROM users WHERE email LIKE 'aichat_test_%'");
$userIdA = (int) $db->query(
    "INSERT INTO users (company_name, email, password, phone, country, language, timezone, role, is_active, email_verified)
     VALUES ('AI Chat Test Co A', 'aichat_test_a@example.com', 'x', '+201000000001', 'EG', 'ar', 'UTC', 'user', 1, 1)"
);
$userIdB = (int) $db->query(
    "INSERT INTO users (company_name, email, password, phone, country, language, timezone, role, is_active, email_verified)
     VALUES ('AI Chat Test Co B', 'aichat_test_b@example.com', 'x', '+201000000002', 'EG', 'ar', 'UTC', 'user', 1, 1)"
);

$websiteIdA = (int) $db->query(
    "INSERT INTO websites (user_id, main_url, company_name, industry, target_language, target_country, is_verified)
     VALUES (?, 'https://a-test.example.com', 'Website A', 'tourism', 'ar', 'EG', 1)",
    [$userIdA]
);
$websiteIdB = (int) $db->query(
    "INSERT INTO websites (user_id, main_url, company_name, industry, target_language, target_country, is_verified)
     VALUES (?, 'https://b-test.example.com', 'Website B', 'tourism', 'ar', 'EG', 1)",
    [$userIdB]
);

check('تم إنشاء مستخدمين وموقعين اختباريين', $userIdA > 0 && $userIdB > 0 && $websiteIdA > 0 && $websiteIdB > 0);

// ============================================
// 1) Knowledge Base Service
// ============================================
section('1) KnowledgeBaseService (بند 4، 13)');

$kb = new KnowledgeBaseService();

$emptyContext = $kb->buildContextForPrompt($websiteIdA);
check('قاعدة معرفة فاضية بترجع تعليمات "متخترعش معلومة"', strpos($emptyContext, 'must NOT invent') !== false || strpos($emptyContext, 'No company knowledge') !== false, $emptyContext);

$entry = $kb->addEntry($websiteIdA, [
    'section' => 'pricing',
    'title' => '7-Day Egypt Tour',
    'content' => 'Cairo, Luxor, Aswan package',
    'structured_data' => ['price_usd' => 1200],
    'language' => 'en',
]);
check('إضافة عنصر Knowledge Base نجحت', $entry !== null && $entry->getAttribute('id') > 0);

$context = $kb->buildContextForPrompt($websiteIdA, 'en');
check('السياق المُولَّد يحتوي على السعر المُدخَل', strpos($context, '1200') !== false, $context);
check('السياق المُولَّد يحتوي على عنوان العنصر', strpos($context, '7-Day Egypt Tour') !== false);

$brandVoice = $kb->addEntry($websiteIdA, ['section' => 'brand_voice', 'tone' => 'luxury', 'content' => 'Always mention free cancellation']);
$voice = $kb->getBrandVoice($websiteIdA);
check('Brand Voice اتحفظ واترجع صح', $voice['tone'] === 'luxury' && strpos($voice['custom_instructions'], 'cancellation') !== false);

// عزل بيانات: موقع B لازم ميشوفش معلومات موقع A
$contextB = $kb->buildContextForPrompt($websiteIdB);
check('عزل البيانات: موقع B مبيشوفش أسعار موقع A (Multi-tenant)', strpos($contextB, '1200') === false);

$updated = $kb->updateEntry((int) $entry->getAttribute('id'), $websiteIdA, ['content' => 'Updated content']);
check('تحديث عنصر Knowledge Base (PUT) نجح', $updated === true);

$deleted = $kb->deleteEntry((int) $entry->getAttribute('id'), $websiteIdA);
check('حذف عنصر Knowledge Base نجح', $deleted === true);

$crossTenantDelete = $kb->deleteEntry((int) $brandVoice->getAttribute('id'), $websiteIdB);
check('أمان: موقع B مايقدرش يحذف عنصر موقع A', $crossTenantDelete === false);

// ============================================
// 2) Unified Inbox Service
// ============================================
section('2) UnifiedInboxService (بند 1، 8)');

$inbox = new UnifiedInboxService();

$key1 = $inbox->buildCustomerKey($websiteIdA, '201234567890', null);
$key1Again = $inbox->buildCustomerKey($websiteIdA, '201234567890', null);
check('buildCustomerKey: نفس الرقم بنفس الصيغة بيدّي نفس المفتاح دايمًا', $key1 === $key1Again);

// ⚠️ اكتشاف حقيقي من الاختبار الفعلي (مش افتراض): buildCustomerKey() بيستخدم
// preg_replace لشيل غير الأرقام بس، وده معناه "+201234567890" (صيغة دولية)
// و"01234567890" (صيغة محلية بصفر البداية) بيرجّعوا مفتاحين مختلفين تمامًا
// لأنهم نصوص أرقام مختلفة فعليًا (201234567890 vs 01234567890) - رغم إنهم
// نفس رقم الهاتف الحقيقي. هذا قيد موجود من قبل في الكود، مش رجعة جديدة -
// بيوثّق هنا كملاحظة (مش Pass/Fail) لأن إصلاحه يحتاج قرار عمل بمنطق تطبيع
// أرقام هاتف دولي (كود الدولة لكل سوق) خارج نطاق هذا الاختبار.
$intlKey = $inbox->buildCustomerKey($websiteIdA, '+201234567890', null);
$localKey = $inbox->buildCustomerKey($websiteIdA, '01234567890', null);
echo "  ℹ️  ملاحظة (مش فشل): نفس الرقم بصيغة دولية vs محلية بيدّي مفاتيح مختلفة "
    . "(" . ($intlKey === $localKey ? 'متطابقين بالصدفة هنا' : 'مختلفين كما هو متوقع من القيد الموجود') . ") "
    . "- محتاج تطبيع أرقام هاتف لو حبيتوا نفس العميل يتعرّف عليه عبر صيغ مختلفة\n";

$key3 = $inbox->buildCustomerKey($websiteIdB, '201234567890', null);
check('buildCustomerKey: نفس الرقم في موقع مختلف بيدّي مفتاح مختلف (عزل)', $key1 !== $key3);

$conv = $inbox->findOrCreateConversation($websiteIdA, $userIdA, 'whatsapp', '201111111111', ['name' => 'Ahmed', 'phone' => '201111111111']);
check('إنشاء محادثة جديدة نجح', $conv->getAttribute('id') > 0);
check('المحادثة الجديدة status=open وai_status=ai افتراضيًا', $conv->getAttribute('status') === 'open' && $conv->getAttribute('ai_status') === 'ai');

$convAgain = $inbox->findOrCreateConversation($websiteIdA, $userIdA, 'whatsapp', '201111111111', ['name' => 'Ahmed']);
check('نفس رقم الهاتف/القناة بيرجّع نفس المحادثة (مش تكرار)', $convAgain->getAttribute('id') === $conv->getAttribute('id'));

$convId = (int) $conv->getAttribute('id');
$inbox->addTags($convId, ['HOT_LEAD', 'PRICE_REQUEST']);
$fresh = (new AiChatConversation())->find($convId);
$tags = json_decode((string) $fresh->getAttribute('tags'), true);
check('إضافة Tags نجحت', in_array('HOT_LEAD', $tags, true) && in_array('PRICE_REQUEST', $tags, true), json_encode($tags));

check('shouldStopAutomation=false لمحادثة عادية', $inbox->shouldStopAutomation($convId) === false);

$inbox->handoffToHuman($convId, 'customer_requested_human');
$afterHandoff = (new AiChatConversation())->find($convId);
check('Human Handoff غيّر ai_status لـ human', $afterHandoff->getAttribute('ai_status') === 'human');
check('shouldStopAutomation=true بعد Handoff', $inbox->shouldStopAutomation($convId) === true);

$inbox->resumeAI($convId);
$afterResume = (new AiChatConversation())->find($convId);
check('resumeAI رجّع ai_status لـ ai', $afterResume->getAttribute('ai_status') === 'ai');

$inbox->markDoNotContact($convId);
check('markDoNotContact بيوقف الأتمتة', $inbox->shouldStopAutomation($convId) === true);

// عزل بيانات: نفس رقم الهاتف على موقع مختلف لازم يعمل محادثة منفصلة
$convB = $inbox->findOrCreateConversation($websiteIdB, $userIdB, 'whatsapp', '201111111111', ['name' => 'Ahmed']);
check('عزل البيانات: نفس الرقم على موقع B بيعمل محادثة منفصلة تمامًا', $convB->getAttribute('id') !== $conv->getAttribute('id'));

$results = $inbox->search($websiteIdA, []);
check('البحث بيرجّع محادثات موقع A بس', count($results) >= 1 && $results[0]->getAttribute('website_id') == $websiteIdA);

$resultsB = $inbox->search($websiteIdB, []);
$leakedIds = array_filter($resultsB, function ($c) use ($convId) {
    return (int) $c->getAttribute('id') === $convId;
});
check('عزل البيانات: نتائج بحث موقع B ما فيهاش محادثة موقع A', empty($leakedIds));

// ============================================
// 3) AI Customer Memory
// ============================================
section('3) AiCustomerMemory (بند 3)');

$memory = new AiCustomerMemory();
$customerKey = $conv->getAttribute('customer_key');
$memory->remember($websiteIdA, $customerKey, 'country', 'Dubai', $convId);
$memory->remember($websiteIdA, $customerKey, 'budget', '$3000', $convId);
$facts = $memory->memoryFor($websiteIdA, $customerKey);
check('حفظ واسترجاع ذاكرة العميل نجح', $facts['country'] === 'Dubai' && $facts['budget'] === '$3000', json_encode($facts));

// تحديث حقيقة موجودة (Upsert) بدل تكرارها
$memory->remember($websiteIdA, $customerKey, 'country', 'Cairo', $convId);
$factsUpdated = $memory->memoryFor($websiteIdA, $customerKey);
check('تحديث حقيقة موجودة (Upsert) بدل تكرارها', $factsUpdated['country'] === 'Cairo' && count($factsUpdated) === 2);

// ============================================
// 4) Lead Scoring Service
// ============================================
section('4) LeadScoringService (بند 5، 6)');

$inbox->updateConversation($convId, ['lead_status' => 'hot_lead']);
$freshConv = (new AiChatConversation())->find($convId);

$leadScoring = new LeadScoringService();
$lead = $leadScoring->upsertFromConversation($freshConv, $factsUpdated, 'Customer wants Dubai trip', null);
check('بناء Lead تلقائي من محادثة hot_lead نجح', $lead !== null && $lead->getAttribute('id') > 0);
check('Lead Score محسوب كرقم منطقي بين 0-100', $lead->getAttribute('lead_score') >= 0 && $lead->getAttribute('lead_score') <= 100, (string) $lead->getAttribute('lead_score'));
check('Intent Score لمحادثة hot_lead عالي (>= 80)', $lead->getAttribute('intent_score') >= 80, (string) $lead->getAttribute('intent_score'));
check('الوجهة (destination) اتاخدت من الذاكرة صح', $lead->getAttribute('destination') === 'Cairo');

// تحديث نفس المحادثة تاني - لازم يحدّث نفس الـLead مش ينشئ واحد جديد
$lead2 = $leadScoring->upsertFromConversation($freshConv, $factsUpdated, 'Updated summary', null);
check('تحديث Lead موجود (مش تكرار Lead جديد لنفس المحادثة)', $lead2->getAttribute('id') === $lead->getAttribute('id'));

// محادثة عادية (new_inquiry بسيط) - لازم بردو تعمل Lead لكن بـscore أقل
$conv2 = $inbox->findOrCreateConversation($websiteIdA, $userIdA, 'whatsapp', '201222222222', ['name' => 'Sara']);
$lead3 = $leadScoring->upsertFromConversation($conv2, [], null, null);
check('محادثة new_inquiry بسيطة برضو بتعمل Lead', $lead3 !== null);
check('Lead Score لمحادثة بسيطة أقل من Lead Score لمحادثة hot_lead', $lead3->getAttribute('lead_score') < $lead->getAttribute('lead_score'), $lead3->getAttribute('lead_score') . ' vs ' . $lead->getAttribute('lead_score'));

// عدم التراجع عن قرار يدوي (won/lost)
$lead->fill(['status' => 'won']);
$lead->save();
$lead4 = $leadScoring->upsertFromConversation($freshConv, $factsUpdated, null, null);
check('عدم التراجع عن حالة "won" اليدوية عند تحديث تلقائي جديد', $lead4->getAttribute('status') === 'won');

// ============================================
// 5) Follow-up Automation Service
// ============================================
section('5) FollowUpAutomationService (بند 7)');

require_once $root . '/app/Services/Chat/MessengerAPI.php';
require_once $root . '/app/Services/Chat/InstagramAPI.php';
require_once $root . '/app/Services/Chat/EmailChannelAPI.php';
require_once $root . '/app/Services/Chat/WhatsAppAPI.php';
require_once $root . '/app/Services/Chat/UltraMsgAPI.php';
require_once $root . '/app/Services/Chat/MessageProcessor.php';
require_once $root . '/app/Services/Chat/AutoReplyEngine.php';
require_once $root . '/app/Services/Chat/ApprovalSystem.php';
require_once $root . '/app/Services/Subscription/UsageTracker.php';
require_once $root . '/app/Services/Subscription/WalletService.php';
require_once $root . '/app/Models/SubscriptionPlan.php';
require_once $root . '/app/Services/Subscription/SubscriptionValidator.php';
require_once $root . '/app/Services/AI/PromptBuilder.php';
require_once $root . '/app/Services/AI/ResponseParser.php';
require_once $root . '/app/Services/AI/SemanticCache.php';
require_once $root . '/app/Services/Chat/ChatManager.php';
require_once $root . '/app/Services/Security/RateLimiter.php';
require_once $root . '/app/Services/AI/TourfectoAIEngine.php';
require_once $root . '/app/Services/AI/AiReplySuggestionsService.php';
require_once $root . '/app/Models/Notification.php';
require_once $root . '/app/Models/PlatformConnection.php';
require_once $root . '/app/Models/User.php';
require_once $root . '/app/Models/Review.php';
require_once $root . '/app/Models/AIReport.php';
require_once $root . '/app/Models/WalletRechargeCard.php';
require_once $root . '/app/Models/WalletTransaction.php';
require_once $root . '/app/Services/Mailer.php';
require_once $root . '/app/Services/System/SystemSettingsService.php';

$followUp = new FollowUpAutomationService();

$defaultRules = $followUp->getRules($websiteIdA);
check('إعدادات المتابعة الافتراضية معطّلة (Opt-in)', $defaultRules['is_enabled'] === false);

$saved = $followUp->updateRules($websiteIdA, [
    'is_enabled' => true,
    'max_followups' => 2,
    'steps' => [['after_hours' => 0, 'template' => 'Hi {name}!']],
]);
check('حفظ إعدادات المتابعة نجح', $saved === true);

$reloaded = $followUp->getRules($websiteIdA);
check('استرجاع الإعدادات بعد الحفظ صحيح', $reloaded['is_enabled'] === true && $reloaded['max_followups'] === 2);

$rulesB = $followUp->getRules($websiteIdB);
check('عزل البيانات: موقع B لسه معطّل رغم تفعيل موقع A', $rulesB['is_enabled'] === false);

// ============================================
// 6) Custom Tags (بند 11)
// ============================================
section('6) AiCustomTag (بند 11)');

$customTag = new AiCustomTag();
$customTag->fill(['website_id' => $websiteIdA, 'name' => 'URGENT_QUOTE', 'color' => 'red']);
$tagSaved = $customTag->save();
check('إنشاء وسم مخصص نجح', $tagSaved !== false);

$tagsForA = $customTag->forWebsite($websiteIdA);
check('استرجاع الوسوم المخصصة لموقع A', count($tagsForA) === 1 && $tagsForA[0]->getAttribute('name') === 'URGENT_QUOTE');

$tagsForB = $customTag->forWebsite($websiteIdB);
check('عزل البيانات: موقع B مالوش وسوم موقع A', count($tagsForB) === 0);

// ============================================
// 7) AIConversationEngine - منطق التحليل بدون استدعاء AI فعلي (بند 2، 9)
// ============================================
section('7) AIConversationEngine - JSON parsing واتخاذ القرار (بند 2، 9)');

$engine = new AIConversationEngine();
$reflection = new ReflectionClass($engine);

$parseMethod = $reflection->getMethod('parseDecision');
$parseMethod->setAccessible(true);

$validJson = '{"reply":"Hello!","language":"en","confidence":0.85,"needs_human":false,"handoff_reason":null,"summary":"test","tags":["HOT_LEAD"],"lead_status":"qualified","memory":{"budget":"$500"}}';
$decision1 = $parseMethod->invoke($engine, $validJson);
check('تحليل JSON صحيح من الـAI نجح', $decision1['reply'] === 'Hello!' && $decision1['confidence'] === 0.85 && $decision1['needs_human'] === false);

$jsonWithFences = "```json\n{\"reply\":\"Hi\",\"confidence\":0.9,\"needs_human\":false,\"tags\":[],\"memory\":{}}\n```";
$decision2 = $parseMethod->invoke($engine, $jsonWithFences);
check('إزالة ```json fences قبل التحليل تعمل صح', $decision2['reply'] === 'Hi');

$outOfRangeConfidence = '{"reply":"Test","confidence":1.5,"needs_human":false,"tags":[],"memory":{}}';
$decision3 = $parseMethod->invoke($engine, $outOfRangeConfidence);
check('الثقة بتتقصّ لأقصى حد 1.0 لو الـAI رجّع رقم أكبر', $decision3['confidence'] === 1.0);

$brokenJson = 'This is not JSON at all, just plain text from a confused model.';
$decision4 = $parseMethod->invoke($engine, $brokenJson);
check('نص غير JSON بيترد كـfallback بثقة 0.5 بدل رفض الرد بالكامل (بند 24)', $decision4['confidence'] === 0.5 && $decision4['reply'] === $brokenJson);

$emptyBrokenJson = '   ';
$decision5 = $parseMethod->invoke($engine, $emptyBrokenJson);
check('نص فاضي تمامًا بيترد كـreply=null مش string فاضي', $decision5['reply'] === null);

$jsonWithNextAction = '{"reply":"Please tell me your dates","confidence":0.8,"needs_human":false,"handoff_reason":null,"summary":"asked for dates","tags":["BOOKING_INTENT"],"lead_status":"qualified","next_action":"ask_dates","memory":{}}';
$decision6 = $parseMethod->invoke($engine, $jsonWithNextAction);
check('تحليل next_action من استجابة الـAI نجح (تحسين تنافسي)', $decision6['next_action'] === 'ask_dates');

$jsonWithoutNextAction = '{"reply":"ok","confidence":0.8,"needs_human":false,"tags":[],"memory":{}}';
$decision7 = $parseMethod->invoke($engine, $jsonWithoutNextAction);
check('غياب next_action من الاستجابة بيرجّع null بأمان', $decision7['next_action'] === null);

$langMethod = $reflection->getMethod('detectLanguage');
$langMethod->setAccessible(true);
check('كشف اللغة: نص عربي', $langMethod->invoke($engine, 'مرحبا كيف حالك') === 'ar');
check('كشف اللغة: نص إنجليزي', $langMethod->invoke($engine, 'Hello how are you') === 'en');
check('كشف اللغة: نص بدون حروف (أرقام فقط) يرجع null', $langMethod->invoke($engine, '12345') === null);

// ============================================
// 8) AI Provider Manager
// ============================================
section('8) AIProviderManager (بند 20)');

$providerManager = new AIProviderManager();
$configured = $providerManager->getConfiguredProviders();
check('من غير أي API key، مفيش مزودين مُهيّئين', empty($configured), json_encode($configured));

$resultNoProvider = $providerManager->generateReply('system prompt', [['role' => 'user', 'content' => 'hi']], []);
check('طلب رد من غير أي مزود مُهيّئ بيرجّع success=false برسالة واضحة (بند 24)', $resultNoProvider['success'] === false && !empty($resultNoProvider['error']));

// ============================================
// 9) Business Hours Service (تحسين تنافسي)
// ============================================
section('9) BusinessHoursService - إدراك ساعات العمل في الأتمتة');

// بدون أي ساعات عمل مهيأة => null => 24/7 (سلوك قديم محفوظ تمامًا)
$nullSchedule = BusinessHoursService::fromEntries([]);
check('لا ساعات عمل مهيأة => schedule=null => 24/7', $nullSchedule === null);
check('isOpenAt مع null دايماً true (مفيش تغيير سلوك)', BusinessHoursService::isOpenAt(time(), null) === true);

// Structured data: {monday: 09:00-18:00}
$monSchedule = BusinessHoursService::fromEntries([
    ['content' => '', 'structured_data' => json_encode(['monday' => ['09:00-18:00']])],
]);
check('بناء جدول من structured_data نجح', $monSchedule !== null && isset($monSchedule[1]));

// نصوص حرة: "Mon-Fri 9:00-18:00" و "الجمعة مغلق"
$workWeek = BusinessHoursService::parseFreeText('Mon-Fri 9:00-18:00');
check('نص حر EN: Mon-Fri 9:00-18:00 يغطي 5 أيام', is_array($workWeek) && count($workWeek) === 5);
check('نص حر EN: يوم الجمعة (5) له نطاق 09:00-18:00', $workWeek[5] === [[540, 1080]]);

$arClosed = BusinessHoursService::parseFreeText('الجمعة مغلق');
check('نص حر AR: "الجمعة مغلق" => الجمعة بدون نطاقات', is_array($arClosed) && isset($arClosed[5]) && empty($arClosed[5]));

$allWeek = BusinessHoursService::parseFreeText('24/7');
check('نص "24/7" => كل الأيام مفتوحة', is_array($allWeek) && count($allWeek) === 7);

// isOpenAt / nextOpenTime على ساعات عمل Mon-Fri 9-18
$tz = new DateTimeZone('UTC');
$wed_noon = (new DateTimeImmutable('next Wednesday 12:00', $tz))->getTimestamp();
check('isOpenAt: الأربعاء 12:00 (نهار) مفتوح', BusinessHoursService::isOpenAt($wed_noon, $workWeek, $tz) === true);

$wed_midnight = (new DateTimeImmutable('next Wednesday 00:30', $tz))->getTimestamp();
check('isOpenAt: الأربعاء 00:30 (ليل) مغلق', BusinessHoursService::isOpenAt($wed_midnight, $workWeek, $tz) === false);

$nextOpen = BusinessHoursService::nextOpenTime($wed_midnight, $workWeek, $tz);
$expectedNext = (new DateTimeImmutable('next Wednesday 09:00', $tz))->getTimestamp();
check('nextOpenTime: من الأربعاء 00:30 لأقرب فتح = الأربعاء 9 ص (نفس اليوم)', $nextOpen === $expectedNext, date('c', $nextOpen));

$sat_noon = (new DateTimeImmutable('next Saturday 12:00', $tz))->getTimestamp();
$nextFromSat = BusinessHoursService::nextOpenTime($sat_noon, $workWeek, $tz);
$expectedMon = (new DateTimeImmutable('next Monday 09:00', $tz))->getTimestamp();
check('nextOpenTime: من السبت 12:00 (عطلة) لأقرب فتح = الاثنين 9 ص', $nextFromSat === $expectedMon, date('c', $nextFromSat));

// ============================================
// 10) next_recommended_action (تحسين تنافسي) - الحفظ والاسترجاع
// ============================================
section('10) next_recommended_action - الإجراء التالي في الـUnified Inbox');

$inbox->updateConversation($convId, ['next_recommended_action' => 'ask_dates']);
$freshWithAction = (new AiChatConversation())->find($convId);
check('حفظ next_recommended_action على المحادثة نجح', $freshWithAction->getAttribute('next_recommended_action') === 'ask_dates');

// محادثة موقع B ما تاخدش action موقع A (عزل بيانات)
$convBfresh = (new AiChatConversation())->find($convB->getAttribute('id'));
check('عزل البيانات: موقع B ما عندوش next_action موقع A', $convBfresh->getAttribute('next_recommended_action') === null);

// ============================================
// النتيجة النهائية
// ============================================
echo "\n" . str_repeat('=', 50) . "\n";
echo "النتيجة: {$passed} نجح، {$failed} فشل، من إجمالي " . ($passed + $failed) . " اختبار\n";
if ($failed > 0) {
    echo "\nالاختبارات الفاشلة:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
}
echo str_repeat('=', 50) . "\n";

// تنظيف بيانات الاختبار
$db->query("DELETE FROM users WHERE id IN (?, ?)", [$userIdA, $userIdB]);

exit($failed > 0 ? 1 : 0);
