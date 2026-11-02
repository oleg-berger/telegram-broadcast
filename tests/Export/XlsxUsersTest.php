<?php
declare(strict_types=1);

namespace Broadcast\Tests\Export;

use Broadcast\Infrastructure\Export\XlsxUsers;
use PHPUnit\Framework\TestCase;

final class XlsxUsersTest extends TestCase
{
    public function testExportWritesTextCellsWithFiltersAndFrozenHeader(): void
    {
        $path = (new XlsxUsers())->export([
            ['id' => 42, 'name' => 'Jane', 'country' => 'France', 'company' => '=HYPERLINK()', 'phone' => '+447700900123', 'email' => 'j@example.com', 'language' => 'EN-GB', 'status' => 'approved', 'submitted_at' => 1758000000, 'approved_at' => null],
        ]);
        try {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path));
            $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();
        } finally {
            unlink($path);
        }
        // User text stays text: inline string cells cannot run Excel formulas.
        self::assertStringContainsString('<t>=HYPERLINK()</t>', $sheet);
        self::assertStringContainsString('<t>+447700900123</t>', $sheet);
        self::assertStringNotContainsString('<f>', $sheet);
        self::assertStringContainsString('<autoFilter ref="A1:M2"/>', $sheet);
        self::assertStringContainsString('state="frozen"', $sheet);
        self::assertStringContainsString('<t>2025-09-16 05:20:00</t>', $sheet);
        self::assertStringContainsString('<t>ID пользователя</t>', $sheet);
        self::assertStringContainsString('<t>Telegram ID</t>', $sheet);
        self::assertStringContainsString('<t>WhatsApp ID</t>', $sheet);
    }

    public function testPersonIdsNeverSubstituteForMissingTelegramIdentity(): void
    {
        $profile = ['name' => 'Person', 'country' => 'France', 'company' => 'Company', 'phone' => '+33123456789',
            'email' => 'person@example.test', 'language' => 'RU', 'status' => 'approved', 'submitted_at' => 1700000000, 'approved_at' => 1700000010];
        $path = (new XlsxUsers())->export([
            ['id' => 501, 'whatsapp_id' => '77010000001', 'whatsapp_verified_phone' => '+77010000001'] + $profile,
            ['id' => 502, 'telegram_id' => '42'] + $profile,
        ]);
        $reader = new \OpenSpout\Reader\XLSX\Reader();
        try {
            $reader->open($path);
            $rows = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) { $rows[] = $row->toArray(); }
            }
            self::assertSame('501', $rows[1][0]);
            self::assertContains($rows[1][10], ['', null]);
            self::assertSame('77010000001', $rows[1][11]);
            self::assertSame('+77010000001', $rows[1][12]);
            self::assertSame('502', $rows[2][0]);
            self::assertSame('42', $rows[2][10]);
            self::assertContains($rows[2][11], ['', null]);
            self::assertContains($rows[2][12], ['', null]);
        } finally {
            $reader->close();
            unlink($path);
        }
    }
}
