<?php

use PHPUnit\Framework\TestCase;

/** Subscriber import: CSV files from Excel (BOM, semicolons, header row, Arabic headers) must parse, not just "email,name" lines. */
final class EmailImportParserTest extends TestCase
{
    private function parse(string $raw): array
    {
        $m = new ReflectionMethod(EmailMarketingController::class, 'parseImportData');
        $m->setAccessible(true);
        $controller = (new ReflectionClass(EmailMarketingController::class))->newInstanceWithoutConstructor();
        return $m->invoke($controller, $raw);
    }

    public function testHeaderlessEmailNameLinesStillWork(): void
    {
        $rows = $this->parse("a@example.com,Ahmed\nb@example.com\n");
        $this->assertSame([['email' => 'a@example.com', 'name' => 'Ahmed'], ['email' => 'b@example.com', 'name' => '']], $rows);
    }

    public function testJsonIsPassedThrough(): void
    {
        $this->assertSame([['email' => 'a@example.com', 'name' => 'Ahmed']], $this->parse('[{"email":"a@example.com","name":"Ahmed"}]'));
    }

    public function testExcelCsvWithBomSemicolonsAndHeader(): void
    {
        $rows = $this->parse("\xEF\xBB\xBFEmail;Name;Phone\r\na@example.com;Ahmed;010\r\nb@example.com;Sara;011\r\n");
        $this->assertCount(2, $rows, 'the header row is not a subscriber');
        $this->assertSame('a@example.com', $rows[0]['email']);
        $this->assertSame('Ahmed', $rows[0]['name']);
        $this->assertSame('011', $rows[1]['phone'], 'extra columns are kept for custom-field mapping');
    }

    public function testArabicHeaderAndColumnOrder(): void
    {
        $rows = $this->parse("الاسم,البريد الإلكتروني\nأحمد,a@example.com\n");
        $this->assertSame('a@example.com', $rows[0]['email']);
        $this->assertSame('أحمد', $rows[0]['name']);
    }

    public function testFirstAndLastNameAreCombinedAndTabsWork(): void
    {
        $rows = $this->parse("first_name\tlast_name\temail\nAhmed\tAli\ta@example.com\n");
        $this->assertSame('Ahmed Ali', $rows[0]['name']);
        $this->assertSame('a@example.com', $rows[0]['email']);
    }

    public function testQuotedValuesAndBlankLines(): void
    {
        $rows = $this->parse("email,name\n\n\"a@example.com\",\"Ali, Ahmed\"\n\n");
        $this->assertCount(1, $rows);
        $this->assertSame('Ali, Ahmed', $rows[0]['name']);
    }

    public function testEmptyInputGivesNoRows(): void
    {
        $this->assertSame([], $this->parse("  \n "));
        $this->assertSame([], $this->parse("\xEF\xBB\xBF"));
    }
}
