<?php

use PHPUnit\Framework\TestCase;

/** The site-builder wizard must not lose a customer's answers or silently pick a wrong language / industry. */
final class WebsiteWizardTest extends TestCase
{
    private WebsiteBuilderService $wizard;

    private array $savedSession = [];

    protected function setUp(): void
    {
        $this->savedSession = $_SESSION ?? [];
        $_SESSION = ['website_builder_prefill_done' => true];
        $this->wizard = new WebsiteBuilderService();
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->savedSession;
    }

    private function toItemsStep(string $industry = 'رحلات وجولات سياحية'): void
    {
        $this->wizard->submitAnswer('العربية');
        $this->wizard->submitAnswer($industry);
        $this->wizard->submitAnswer('شركة النيل');
        $this->wizard->submitAnswer('رحلات نيلية في الأقصر');
    }

    public function testDoneSignalIsTheWordItselfNotAnyDescriptionContainingIt(): void
    {
        $this->assertTrue(WebsiteBuilderService::isDoneSignal('خلصت'));
        $this->assertTrue(WebsiteBuilderService::isDoneSignal(' خلاص '));
        $this->assertFalse(WebsiteBuilderService::isDoneSignal('رحلة بالفلوكة لمدة ساعتين تخلص عند الغروب مع عشاء'));
    }

    public function testAnItemDescriptionContainingTheDoneWordIsStillSaved(): void
    {
        $this->toItemsStep();
        $this->wizard->submitAnswer('رحلة فلوكة ساعتين تخلص عند الغروب مع عشاء على النيل');
        $this->assertCount(1, $_SESSION['website_builder_answers']['items']);
        $this->assertEmpty($_SESSION['website_builder_answers']['items_done'] ?? null);
    }

    public function testUnknownLanguageAndIndustryAreAskedAgainInsteadOfGuessed(): void
    {
        $r = $this->wizard->submitAnswer('Italiano');
        $this->assertSame('language', $r['step']);
        $this->assertArrayNotHasKey('language', $_SESSION['website_builder_answers'] ?? []);
        $this->wizard->submitAnswer('en');
        $this->assertSame('en', $_SESSION['website_builder_answers']['language']);
        $r = $this->wizard->submitAnswer('مطعم');
        $this->assertSame('industry', $r['step']);
        $this->wizard->submitAnswer('hotel');
        $this->assertSame('hotel', $_SESSION['website_builder_answers']['industry']);
    }

    public function testEmptyAnswersAreRejectedAndNeverStoredAsItems(): void
    {
        $this->toItemsStep();
        $r = $this->wizard->submitAnswer('   ');
        $this->assertSame('items', $r['step']);
        $this->assertEmpty($_SESSION['website_builder_answers']['items'] ?? []);
    }

    public function testItemsAreCappedSoImageGenerationStaysWithinTheRequest(): void
    {
        $this->toItemsStep();
        for ($i = 1; $i <= 12; $i++) {
            $this->wizard->submitAnswer('رحلة رقم ' . $i . ' في الأقصر');
        }
        $this->assertCount(8, $_SESSION['website_builder_answers']['items']);
        $this->assertTrue($_SESSION['website_builder_answers']['items_done']);
    }
}
