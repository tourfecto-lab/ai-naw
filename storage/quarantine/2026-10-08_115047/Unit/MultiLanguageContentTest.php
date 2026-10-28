<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AI/ArticleGenerator.php';

class MultiLanguageContentTest extends TestCase
{
    public function testLanguageMapIncludesAllSupportedLanguages()
    {
        $ref = new ReflectionClass(ArticleGenerator::class);
        $method = $ref->getMethod('buildPrompt');
        $method->setAccessible(true);

        $generator = new ArticleGenerator();

        foreach (['ar', 'en', 'es', 'it', 'tr', 'ru', 'zh', 'ja', 'pt', 'nl'] as $lang) {
            $prompt = $method->invoke($generator, 'Test Topic', $lang, 'professional', null, null, []);
            $this->assertNotEmpty($prompt);
        }
    }
}
