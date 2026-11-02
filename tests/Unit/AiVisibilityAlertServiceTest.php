<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AiVisibility/AiVisibilityAlertService.php';

class AiVisibilityAlertServiceTest extends TestCase
{
    private function metrics(array $trend): array
    {
        return ['providers' => ['openai' => ['trend' => $trend]]];
    }

    private function week(string $w, int $q, ?float $sov, ?float $cit = null): array
    {
        return ['week' => $w, 'questions' => $q, 'share_of_voice' => $sov, 'citation_rate' => $cit];
    }

    public function testMentionLost(): void
    {
        $a = AiVisibilityAlertService::detect($this->metrics([$this->week('2026-10-26', 10, 0.4), $this->week('2026-11-02', 10, 0.0)]));
        $this->assertSame('mention_lost', $a[0]['kind']);
    }

    public function testBigDropAndCitationLost(): void
    {
        $a = AiVisibilityAlertService::detect($this->metrics([$this->week('2026-10-26', 10, 0.8, 0.5), $this->week('2026-11-02', 10, 0.4, 0.0)]));
        $this->assertSame(['sov_drop', 'citation_lost'], array_column($a, 'kind'));
    }

    public function testSmallDropAndPartialSamplesDoNotAlert(): void
    {
        $this->assertSame([], AiVisibilityAlertService::detect($this->metrics([$this->week('a', 10, 0.5), $this->week('b', 10, 0.4)])));
        $this->assertSame([], AiVisibilityAlertService::detect($this->metrics([$this->week('a', 10, 0.5), $this->week('b', 2, 0.0)])));
        $this->assertSame([], AiVisibilityAlertService::detect($this->metrics([$this->week('a', 10, 0.5)])));
    }

    public function testMessageIsHonestAboutSampling(): void
    {
        [$title, $body] = AiVisibilityAlertService::message([['provider' => 'openai', 'kind' => 'mention_lost', 'from' => 0.4, 'to' => 0.0, 'week' => 'x']]);
        $this->assertStringContainsString('ChatGPT', $body);
        $this->assertStringContainsString('40%', $body);
    }
}
