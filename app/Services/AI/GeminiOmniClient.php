<?php

/**
 * Tourfecto - Gemini Omni video client (Interactions API).
 *
 * Google switched off the Veo 3.1 Fast preview and moved video generation to gemini-omni (POST /v1beta/interactions).
 * Unlike Veo it is not a long-running operation: one request returns the finished clip (inline, or as a Files API URI),
 * so startGeneration() already carries the video and GenerateVideoJob finishes in the same run.
 *
 * Differences the Studio has to live with (from Google's Omni documentation):
 *  - no duration parameter: the requested length is only a hint in the prompt;
 *  - no negative-prompt field: "things to avoid" are appended to the prompt;
 *  - 1080p / 4k are upscaled from 720p.
 * Extends VeoClient only to reuse its key handling, HTTP helper and the SSRF-guarded download.
 */
class GeminiOmniClient extends VeoClient
{
    public const DEFAULT_MODEL = 'gemini-omni-1.1-flash';

    public static function handles(?string $model): bool
    {
        return is_string($model) && str_starts_with($model, 'gemini-omni');
    }

    /** The client for a configured model name: Omni for gemini-omni*, Veo otherwise. */
    public static function forModel(?string $model): VeoClient
    {
        $model = $model ?: (defined('VEO_MODEL') ? VEO_MODEL : self::DEFAULT_MODEL);
        return self::handles($model) ? new self($model) : new VeoClient($model);
    }

    /** @return array the JSON body of POST /v1beta/interactions */
    public static function buildRequest(string $model, string $prompt, string $ratio, int $duration, array $settings = []): array
    {
        $text = trim($prompt);
        $text .= "\nClip length: about {$duration} seconds.";
        $avoid = $settings['negative_prompt'] ?? '';
        if (is_string($avoid) && trim($avoid) !== '') {
            $text .= "\nAvoid: " . mb_substr(trim($avoid), 0, 600);
        }
        $wanted = $settings['resolution'] ?? '720p';
        $resolution = in_array($wanted, ['360p', '720p', '1080p', '4k'], true) ? $wanted : '720p';
        $body = [
            'model' => $model,
            'response_format' => ['type' => 'video', 'aspect_ratio' => $ratio, 'resolution' => $resolution, 'delivery' => 'uri'],
        ];
        $ref = $settings['reference'] ?? null;
        if (is_array($ref) && !empty($ref['data']) && !empty($ref['mimeType'])) {
            ReferenceMedia::validateInline($ref);
            $body['input'] = [
                ['type' => 'image', 'data' => $ref['data'], 'mime_type' => $ref['mimeType']],
                ['type' => 'text', 'text' => $text],
            ];
            $body['generation_config'] = ['video_config' => ['task' => 'image_to_video']];
        } else {
            $body['input'] = $text;
        }
        return $body;
    }

    /** @return array{video?: array, text: string} the first video item of the answer (inline bytes or uri) and any text the model wrote */
    public static function parseVideo(array $response): array
    {
        $video = null;
        $text = '';
        foreach ((array) ($response['steps'] ?? []) as $step) {
            if (($step['type'] ?? '') !== 'model_output') {
                continue;
            }
            foreach ((array) ($step['content'] ?? []) as $c) {
                if (($c['type'] ?? '') === 'video' && $video === null && (!empty($c['data']) || !empty($c['uri']))) {
                    $video = $c;
                } elseif (($c['type'] ?? '') === 'text') {
                    $text .= ' ' . (string) ($c['text'] ?? '');
                }
            }
        }
        return ['video' => $video, 'text' => trim($text)];
    }

    /** @return string[] omni model names this key can see (for the admin diagnostics tool) */
    public function listOmniModels(): array
    {
        $names = [];
        $r = $this->request('GET', $this->baseUrl . '/models?pageSize=200', null);
        foreach ((array) ($r['data']['models'] ?? []) as $m) {
            $name = preg_replace('#^models/#', '', (string) ($m['name'] ?? ''));
            if (str_contains($name, 'omni')) {
                $names[] = $name . ' [' . implode(',', (array) ($m['supportedGenerationMethods'] ?? [])) . ']';
            }
        }
        return $names;
    }

    protected function pause(int $seconds): void
    {
        sleep($seconds);
    }

    /** @return string|null download URL once the hosted file is ACTIVE */
    private function readyFileUrl(string $uri): ?string
    {
        if (!preg_match('#/files/([A-Za-z0-9_-]+)#', $uri, $m)) {
            return null;
        }
        $id = $m[1];
        for ($i = 0; $i < 30; $i++) {
            $r = $this->request('GET', $this->baseUrl . '/files/' . $id, null);
            $state = strtoupper((string) ($r['data']['state'] ?? ''));
            if ($r['success'] && $state === 'ACTIVE') {
                return $this->baseUrl . '/files/' . $id . ':download?alt=media';
            }
            if ($state === 'FAILED') {
                return null;
            }
            $this->pause(3);
        }
        return null;
    }

    public function startGeneration(string $prompt, string $aspectRatio = '16:9', int $durationSeconds = 8, array $settings = []): array
    {
        if ($this->apiKey === '') {
            return ['success' => false, 'error' => 'Video provider not configured'];
        }
        if (!in_array($aspectRatio, ['16:9', '9:16'], true) || !in_array($durationSeconds, [4, 6, 8], true)) {
            return ['success' => false, 'error' => 'Invalid video settings'];
        }
        try {
            $body = self::buildRequest($this->model, $prompt, $aspectRatio, $durationSeconds, $settings);
        } catch (InvalidArgumentException $e) {
            return ['success' => false, 'error' => 'Invalid video settings'];
        }

        $this->timeout = 600; // the clip is generated inside this one request
        $r = $this->request('POST', $this->baseUrl . '/interactions', $body);
        $this->timeout = 30;
        if (!$r['success']) {
            return $r;
        }
        $answer = self::parseVideo((array) $r['data']);
        $id = (string) ($r['data']['id'] ?? 'sync');
        $v = $answer['video'];
        if ($v === null) {
            $why = $answer['text'] !== '' ? mb_substr($answer['text'], 0, 240) : 'no video in the answer (status ' . (string) ($r['data']['status'] ?? '?') . ')';
            return ['success' => false, 'error' => 'Gemini Omni returned no video: ' . $why];
        }
        if (!empty($v['data'])) {
            $bytes = base64_decode((string) $v['data'], true);
            if ($bytes === false || $bytes === '') {
                return ['success' => false, 'error' => 'Gemini Omni returned unreadable video data'];
            }
            return ['success' => true, 'operation_name' => 'omni:' . $id, 'video_data' => $bytes];
        }
        $url = $this->readyFileUrl((string) $v['uri']);
        if ($url === null) {
            return ['success' => false, 'error' => 'Gemini Omni video file did not become ready'];
        }
        return ['success' => true, 'operation_name' => 'omni:' . $id, 'video_uri' => $url];
    }

    /** Omni clips are delivered by startGeneration(); there is no operation to poll or resume. */
    public function checkOperation(string $operationName): array
    {
        return ['success' => false, 'done' => true, 'error' => 'Gemini Omni results cannot be re-fetched; generate again'];
    }
}
