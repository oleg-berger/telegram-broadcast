<?php
declare(strict_types=1);

namespace Broadcast\Tests\Contacts;

use Broadcast\Infrastructure\Import\XlsxContacts;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\TestCase;

final class XlsxContactsTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) { unlink($file); }
    }

    private function workbook(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'contacts-');
        $this->files[] = $path;
        $writer = new Writer();
        $writer->openToFile($path);
        foreach ($rows as $row) { $writer->addRow(Row::fromValues($row)); }
        $writer->close();
        return $path;
    }

    public function testNormalizesPhonesAndLanguagesWithoutRequiringEmail(): void
    {
        $result = (new XlsxContacts())->read($this->workbook([
            ['Телефон', 'Язык', 'Почта'], ['+33 (1) 23-45-67-89', 'eng', ''], ['+34912345678', 'es', 'a@example.test'],
        ]));
        self::assertSame([], $result['errors']);
        self::assertSame([
            ['phone' => '+33123456789', 'language' => 'EN-GB', 'email' => null, 'row' => 2],
            ['phone' => '+34912345678', 'language' => 'ES', 'email' => 'a@example.test', 'row' => 3],
        ], $result['rows']);
    }

    public function testLegacyLayoutReportsIncompleteRowsAndNeverIncludesNames(): void
    {
        $result = (new XlsxContacts())->read($this->workbook([
            ['', '', '', 'язык рассылки', 'Страна'],
            ['Secret Company', '+33123456789', 'Secret Name', 'ru', 'France'],
            ['Secret Company', '', '', '', 'France'],
        ]));
        self::assertCount(1, $result['rows']);
        self::assertSame([['row' => 3, 'code' => 'missing_contact']], $result['errors']);
        self::assertStringNotContainsString('Secret', json_encode($result));
    }

    public function testDuplicatesAndUnknownLanguageAreErrorsWithRowNumbersOnly(): void
    {
        $result = (new XlsxContacts())->read($this->workbook([
            ['phone', 'language'], ['+33123456789', 'RU'], ['+33 123456789', 'RU'], ['+34912345678', 'unknown'],
        ]));
        self::assertSame([['row' => 3, 'code' => 'duplicate_phone'], ['row' => 4, 'code' => 'invalid_language']], $result['errors']);
        self::assertCount(1, $result['rows']);
    }

    public function testRejectsNumericCellsRatherThanGuessLostLeadingDigits(): void
    {
        $result = (new XlsxContacts())->read($this->workbook([['phone', 'language'], [33123456789, 'FR']]));
        self::assertSame([['row' => 2, 'code' => 'phone_must_be_text']], $result['errors']);
    }

    public function testAddsOnlyMissingPlusWithoutInventingCountryCode(): void
    {
        $result = (new XlsxContacts())->read($this->workbook([['phone', 'language'], ['33 123456789', 'FR']]));
        self::assertSame([], $result['errors']);
        self::assertSame('+33123456789', $result['rows'][0]['phone']);
        self::assertSame([['row' => 2, 'code' => 'missing_plus_normalized']], $result['warnings']);
    }

    public function testExplicitContinuationRowsBelongToPreviousContact(): void
    {
        $path = $this->workbook([
            ['', '', '', 'язык рассылки', 'Страна'],
            ['Company', '+33123456789', 'Name', 'ru', 'France'],
            ['Continuation', '', '', '', 'France'],
        ]);
        $result = (new XlsxContacts())->read($path, continuationRows: [3]);
        self::assertSame([], $result['errors']);
        self::assertCount(1, $result['rows']);
        self::assertSame([['row' => 3, 'code' => 'contact_continuation', 'source_row' => 2]], $result['warnings']);
    }

    public function testAcceptsOnlyExactPhoneLiteralFormulaAndNeverEvaluatesOtherFormulas(): void
    {
        $file = $this->workbook([['phone', 'language', 'email'], ['=+33123456789', 'FR', ''], ['+34912345678', 'ES', '=1+1']]);
        $result = (new XlsxContacts())->read($file);
        self::assertSame([['row' => 3, 'code' => 'formula_not_allowed']], $result['errors']);
        self::assertSame('+33123456789', $result['rows'][0]['phone']);
        self::assertSame([['row' => 2, 'code' => 'literal_phone_formula_normalized']], $result['warnings']);
    }
}
