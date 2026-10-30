<?php

use PHPUnit\Framework\TestCase;

/**
 * Regressions found by using the platform end to end as a customer:
 * hidden dashboard tabs, Arabic leaking into the English dashboard, and an untranslated wizard prompt.
 */
final class SiteDashboardWalkthroughTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    private function source(string $relative): string
    {
        return (string) file_get_contents(self::ROOT . $relative);
    }

    public function testEveryDashboardTabButtonIsKnownToTheTabSwitcher(): void
    {
        $src = $this->source('app/Controllers/SiteDashboardController.php');
        preg_match_all("/id=\"sdTab([A-Za-z]+)Btn\"/", $src, $buttons);
        $this->assertNotEmpty($buttons[1]);
        $this->assertSame(1, preg_match("/\\['overview'[^\\]]*\\]\\.forEach\\(t =>/", $src, $list), 'tab switcher list not found');
        foreach ($buttons[1] as $name) {
            $tab = strtolower($name);
            $this->assertStringContainsString("'" . $tab . "'", $list[0], "tab '$tab' has a button but the switcher never shows its panel");
            $this->assertStringContainsString('id="sdTab' . $name . '"', $src, "tab '$tab' has no panel");
        }
    }

    /** @return string[] Arabic runs of a source file that the English dictionary cannot translate */
    private function untranslatedRuns(string $relative): array
    {
        require_once self::ROOT . 'app/Helpers/ui_autotranslate.php';
        $dict = tf_autotranslate_dict();
        $lines = preg_split('/\R/u', $this->source($relative));
        $code = array_filter($lines, static function ($l) {
            $t = ltrim($l);
            return strpos($t, '//') !== 0 && strpos($t, '*') !== 0 && strpos($t, '/*') !== 0;
        });
        preg_match_all(tf_autotranslate_run_regex(), implode("\n", $code), $m);
        $missing = [];
        foreach (array_unique(array_map('trim', $m[0])) as $run) {
            if ($run !== '' && tf_autotranslate_phrase($run, $dict) === null) {
                $missing[] = $run;
            }
        }
        return $missing;
    }

    public function testSiteDashboardAndManagerPortalAreFullyTranslatedToEnglish(): void
    {
        foreach (['app/Controllers/SiteManagerController.php', 'app/Services/Website/SiteManagerService.php'] as $file) {
            $this->assertSame([], $this->untranslatedRuns($file), "$file has Arabic text the English UI cannot translate");
        }
        // one hidden legacy comment-like label is intentionally left alone
        $this->assertSame(['حقول قديمة (لسه بيقراها كود النظرة العامة) - مخفية'], $this->untranslatedRuns('app/Controllers/SiteDashboardController.php'));
    }

    public function testTranslatedPhrasesReadAsEnglishNotWordSalad(): void
    {
        require_once self::ROOT . 'app/Helpers/ui_autotranslate.php';
        $this->assertSame('Code not found', tf_autotranslate_text('الكود مش موجود'));
        $this->assertSame('Published', tf_autotranslate_text('موقع منشور'));
        $this->assertSame('Site editor', tf_autotranslate_text('محرر الموقع'));
        $this->assertSame('No activity yet', tf_autotranslate_text('لا يوجد نشاط بعد'));
    }

    public function testWizardItemPromptsTranslateInFull(): void
    {
        require_once self::ROOT . 'app/Helpers/ui_autotranslate.php';
        foreach ([['رحلة', 'رحلات'], ['غرفة/جناح', 'غرف']] as [$one, $many]) {
            $first = tf_autotranslate_text("دلوقتي احكيلي عن الـ{$one} اللي بتقدّمها - واحدة في كل رسالة. لما تخلّص كل الـ{$many} اكتب \"خلصت\".");
            $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', $first, 'first item prompt leaked Arabic');
            for ($n = 1; $n <= 8; $n++) {
                $next = tf_autotranslate_text("تمام، سجّلتها! في {$one} تانية؟ (عندك دلوقتي {$n} {$many} مسجّلة) (اكتبها، أو دوس \"خلصت\" للانتهاء)");
                $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', $next, "another-item prompt for $n leaked Arabic");
            }
        }
    }

    public function testPublicBookingMessageDoesNotRepeatTheReference(): void
    {
        $src = $this->source('app/Controllers/WebsiteBuilderController.php');
        $this->assertStringContainsString('text.indexOf(res.data.booking_reference) === -1', $src);
    }

    public function testPublicFormEndpointsAnswerInTheSitesOwnLanguage(): void
    {
        $src = $this->source('app/Controllers/WebsiteBuilderController.php');
        $this->assertMatchesRegularExpression('/\$this->publicSiteLanguage = \$siteLang;/', $src, 'findViewableWebsite must set the site language');
    }

    public function testHotelQuoteShowsNightsNotAdultAndChildPrices(): void
    {
        $src = $this->source('app/Controllers/WebsiteBuilderController.php');
        $this->assertStringContainsString("r.data.nights?' ('+r.data.nights+' × '+r.data.adult_price", $src);
    }

    public function testEnquiryAndReviewAnswersUseTheSiteLanguageAndOwnersAreNotified(): void
    {
        $src = $this->source('app/Controllers/WebsiteBuilderController.php');
        foreach (['submitLead', 'submitReview'] as $fn) {
            $start = strpos($src, 'public function ' . $fn . '(');
            $body = substr($src, $start, (int) strpos($src, "
    }
", $start) - $start);
            $this->assertDoesNotMatchRegularExpression("/->(success|error)\((\[\],\s*)?'[^']*[\x{0600}-\x{06FF}]/u", $body, "$fn returns a hard-coded Arabic message");
        }
        $this->assertStringContainsString("'site_lead_new'", $src);
        $this->assertStringContainsString("'site_lead_new' => 'leads'", $this->source('app/Models/Notification.php'));
        $map = require self::ROOT . 'app/Config/site_interface_en.php';
        foreach (['تم إرسال طلبك، هيتم التواصل معاك قريبًا', 'اكتب اسمك من فضلك', 'الإيميل مش صحيح', 'غير موجود'] as $k) {
            $this->assertArrayHasKey($k, $map);
        }
    }

    public function testCustomerDashboardShowsTheCustomersWebsiteAndPendingBookings(): void
    {
        $src = $this->source('app/Controllers/DashboardController.php');
        $this->assertStringContainsString('getSiteStats', $src);
        $this->assertStringContainsString('renderSiteCard(data.site', $src);
        foreach (['ar', 'en'] as $lang) {
            $this->assertStringContainsString("'dashboard.site.pending'", $this->source("app/Lang/$lang.php"));
        }
    }
}
