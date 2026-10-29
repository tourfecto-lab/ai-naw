<?php

/** TOURFECTO AI CEO: المحادثة بتجاوب الأسئلة الأساسية من الأرقام، ومابتنادي الذكاء الاصطناعي وهي متوقفة */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AiCeo/PlatformDecisions.php';
require_once __DIR__ . '/../../app/Services/AiCeo/AiCeoChat.php';
if (!class_exists('PlatformDecisionsTest', false)) require_once __DIR__ . '/PlatformDecisionsTest.php';

final class AiCeoChatTest extends TestCase
{
    private function decisions(array $snap): array
    {
        return array_map(static fn ($d) => $d + ['id' => 1, 'status' => 'proposed'], PlatformDecisions::decide($snap));
    }

    public function testTheSixOwnerQuestionsAreRecognised(): void
    {
        $this->assertSame('today', AiCeoChat::intent('إيه اللي حصل النهارده؟'));
        $this->assertSame('top_problem', AiCeoChat::intent('إيه أهم مشكلة عندنا؟'));
        $this->assertSame('subs_down', AiCeoChat::intent('ليه الاشتراكات قلت؟'));
        $this->assertSame('services_dev', AiCeoChat::intent('إيه الخدمات اللي محتاجة تطوير؟'));
        $this->assertSame('best_country', AiCeoChat::intent('إيه أفضل دولة نستهدفها؟'));
        $this->assertSame('needs_me', AiCeoChat::intent('إيه المطلوب مني دلوقتي؟'));
        $this->assertNull(AiCeoChat::intent('اكتبلي خطة تسويق لشهر ديسمبر'));
    }

    public function testCoreQuestionsAreAnsweredFromTheNumbersWithoutAi(): void
    {
        $snap = PlatformDecisionsTest::snapshot();
        $calls = 0;
        $chat = new AiCeoChat(function () use (&$calls) { $calls++; return 'x'; });
        $r = $chat->answer('ليه الاشتراكات قلت؟', $snap, $this->decisions($snap), ['paused' => false]);
        $this->assertSame('data', $r['source']);
        $this->assertStringContainsString('9', $r['answer']);
        $this->assertStringContainsString('10%', $r['answer']);
        $top = $chat->answer('إيه أهم مشكلة عندنا؟', $snap, $this->decisions($snap), ['paused' => false]);
        $this->assertStringContainsString('/admin/', $top['answer']);
        $this->assertSame(0, $calls);
    }

    public function testUnknownSourcesSayUnknown(): void
    {
        $snap = PlatformDecisionsTest::snapshot(['revenue' => null]);
        $r = (new AiCeoChat(fn () => null))->answer('إيه اللي حصل النهارده؟', $snap, [], ['paused' => false]);
        $this->assertStringContainsString('مش معروف', $r['answer']);
    }

    public function testOpenQuestionsUseAiButNotDuringEmergencyStop(): void
    {
        $snap = PlatformDecisionsTest::snapshot();
        $prompts = [];
        $chat = new AiCeoChat(function (string $p) use (&$prompts) { $prompts[] = $p; return 'خطة مختصرة'; });
        $stopped = $chat->answer('اكتبلي خطة تسويق لشهر ديسمبر', $snap, [], ['paused' => true]);
        $this->assertSame('none', $stopped['source']);
        $this->assertSame([], $prompts);
        $ok = $chat->answer('اكتبلي خطة تسويق لشهر ديسمبر', $snap, [], ['paused' => false]);
        $this->assertSame('ai', $ok['source']);
        $this->assertStringContainsString('Never invent numbers', $prompts[0]);
        $this->assertStringContainsString('Egyptian Arabic', $prompts[0]);
    }

    public function testBestCountryWarnsAboutSmallSamples(): void
    {
        $snap = PlatformDecisionsTest::snapshot(['outreach' => ['total' => 30, 'awaiting_approval' => 0, 'pending_sends' => 0, 'sent' => 20, 'opened' => 5, 'clicked' => 2, 'paused_reason' => '', 'countries' => [
            ['country' => 'EG', 'total' => 20, 'contacted' => 12, 'interested' => 2, 'signed_up' => 1, 'clicks' => 3],
            ['country' => 'AE', 'total' => 10, 'contacted' => 8, 'interested' => 1, 'signed_up' => 0, 'clicks' => 1]]]]);
        $r = (new AiCeoChat(fn () => null))->answer('إيه أفضل دولة نستهدفها؟', $snap, [], ['paused' => false]);
        $this->assertStringContainsString('EG', $r['answer']);
        $this->assertStringContainsString('العينة صغيرة', $r['answer']);
    }

    public function testBudgetGuardAndCacheAvoidAiCalls(): void
    {
        $snap = PlatformDecisionsTest::snapshot();
        $calls = 0;
        $ai = function () use (&$calls) { $calls++; return 'x'; };
        $blocked = (new AiCeoChat($ai, fn () => 'daily'))->answer('اكتبلي خطة تسويق', $snap, [], ['paused' => false]);
        $this->assertSame('none', $blocked['source']);
        $this->assertStringContainsString('الميزانية', $blocked['answer']);
        $cached = (new AiCeoChat($ai, fn () => null, fn (string $q) => 'إجابة محفوظة'))->answer('اكتبلي خطة تسويق', $snap, [], ['paused' => false]);
        $this->assertSame('cache', $cached['source']);
        $this->assertSame('إجابة محفوظة', $cached['answer']);
        $this->assertSame(0, $calls);
    }
}
