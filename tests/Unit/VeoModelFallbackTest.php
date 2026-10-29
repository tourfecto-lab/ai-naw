<?php

use PHPUnit\Framework\TestCase;

/** Google retired veo-3.1-fast-generate-preview; the client must find a Veo model that still exists instead of failing every video. */
final class VeoModelFallbackTest extends TestCase
{
    public function testRetiredModelMessagesAreRecognised(): void
    {
        $this->assertTrue(VeoClient::isRetiredModelError('This model models/veo-3.1-fast-generate-preview is no longer available. Please update your code'));
        $this->assertTrue(VeoClient::isRetiredModelError('models/veo-x is not found for API version v1beta, or is not supported for predictLongRunning'));
        $this->assertFalse(VeoClient::isRetiredModelError('Your quota is exhausted'));
        $this->assertFalse(VeoClient::isRetiredModelError('Blocked by safety filters'));
    }

    public function testPickModelPrefersNewestFastAndStable(): void
    {
        $all = ['veo-2.0-generate-001', 'veo-3.0-fast-generate-001', 'veo-3.1-generate-preview', 'veo-3.1-lite-generate-preview', 'veo-3.1-fast-generate-preview'];
        $this->assertSame('veo-3.1-fast-generate-preview', VeoClient::pickModel($all));
        $this->assertSame('veo-3.1-generate-preview', VeoClient::pickModel($all, 'veo-3.1-fast-generate-preview'), 'with the retired fast model excluded, the full 3.1 model is next');
        $this->assertSame('veo-3.1-generate-preview', VeoClient::pickModel(['veo-3.1-generate-preview', 'veo-3.0-generate-001']));
        $this->assertSame('veo-3.0-fast-generate-001', VeoClient::pickModel(['veo-3.0-fast-generate-001', 'veo-3.0-generate-001', 'veo-2.0-generate-001']));
        $this->assertNull(VeoClient::pickModel([], 'veo-3.1-fast-generate-preview'));
        $this->assertNull(VeoClient::pickModel(['veo-3.1-fast-generate-preview'], 'veo-3.1-fast-generate-preview'), 'the retired model itself is never the replacement');
    }

    public function testGenerationSwitchesModelAndRetriesOnce(): void
    {
        $client = new class('veo-3.1-fast-generate-preview') extends VeoClient {
            public array $calls = [];

            public function __construct(string $model)
            {
                parent::__construct($model);
                (function () { $this->apiKey = 'test-key'; })->bindTo($this, VeoClient::class)();
            }

            protected function request(string $method, string $url, ?array $payload): array
            {
                $this->calls[] = [$method, $url];
                if (str_contains($url, '/models?pageSize')) {
                    return ['success' => true, 'data' => ['models' => [
                        ['name' => 'models/veo-3.1-fast-generate-preview', 'supportedGenerationMethods' => ['predictLongRunning']],
                        ['name' => 'models/veo-3.1-generate-preview', 'supportedGenerationMethods' => ['predictLongRunning']],
                        ['name' => 'models/gemini-omni-1.1-flash', 'supportedGenerationMethods' => ['generateContent']],
                    ]]];
                }
                if (str_contains($url, 'veo-3.1-fast-generate-preview:predictLongRunning')) {
                    return ['success' => false, 'error' => 'This model models/veo-3.1-fast-generate-preview is no longer available.'];
                }
                return ['success' => true, 'data' => ['name' => 'models/veo-3.1-generate-preview/operations/abc123']];
            }
        };
        $r = $client->startGeneration('A felucca on the Nile at sunset', '9:16', 8);
        $this->assertTrue($r['success'], json_encode($r));
        $this->assertSame('models/veo-3.1-generate-preview/operations/abc123', $r['operation_name']);
        $posts = array_values(array_filter($client->calls, fn ($c) => $c[0] === 'POST'));
        $this->assertCount(2, $posts, 'exactly one retry');
        $this->assertStringContainsString('veo-3.1-generate-preview:predictLongRunning', $posts[1][1]);
    }

    public function testOtherErrorsAreNotRetried(): void
    {
        $client = new class('veo-3.1-fast-generate-preview') extends VeoClient {
            public int $posts = 0;

            public function __construct(string $model)
            {
                parent::__construct($model);
                (function () { $this->apiKey = 'test-key'; })->bindTo($this, VeoClient::class)();
            }

            protected function request(string $method, string $url, ?array $payload): array
            {
                $this->posts += $method === 'POST' ? 1 : 0;
                return ['success' => false, 'error' => 'Quota exceeded'];
            }
        };
        $r = $client->startGeneration('x', '9:16', 8);
        $this->assertFalse($r['success']);
        $this->assertSame(1, $client->posts);
    }
}
