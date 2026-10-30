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

    public function testAnswersLeftByAnotherAccountInTheSameBrowserAreDropped(): void
    {
        $_SESSION = [
            'user_id' => 6,
            'website_builder_uid' => 5,
            'website_builder_prefill_done' => true,
            'website_builder_answers' => ['language' => 'en', 'industry' => 'tours', 'business_name' => 'Nile Breeze Tours', 'service_type' => 'Day trips', 'items' => ['Pyramids'], 'items_done' => true, 'contact_text' => '+20 100'],
        ];
        $state = (new WebsiteBuilderService())->getCurrentState();
        $this->assertFalse($state['done']);
        $this->assertNotSame('Nile Breeze Tours', $_SESSION['website_builder_answers']['business_name'] ?? null);
        $this->assertSame(6, $_SESSION['website_builder_uid']);
    }

    public function testPrefilledNoteOnlyNamesWhatWasActuallyPrefilled(): void
    {
        $_SESSION = [
            'user_id' => 7,
            'website_builder_uid' => 7,
            'website_builder_prefill_done' => true,
            'website_builder_prefilled' => ['business_name', 'items'],
            'website_builder_prefilled_items' => 2,
            'website_builder_answers' => ['language' => 'ar', 'industry' => 'tours', 'business_name' => 'شركة النيل', 'service_type' => 'رحلات نيلية في الأقصر', 'items' => ['رحلة الأهرامات', 'رحلة الفلوكة']],
        ];
        $state = (new WebsiteBuilderService())->getCurrentState();
        $this->assertSame('items', $state['step']);
        $this->assertStringContainsString('أخدنا اسم شركتك و2 رحلات من «رحلاتي»', $state['question']);
        $this->assertStringNotContainsString('بيانات التواصل', $state['question']);
        $this->assertSame(['خلصت'], $state['options']);
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
