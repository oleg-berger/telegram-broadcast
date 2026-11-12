<?php
declare(strict_types=1);

namespace Broadcast\Tests\Contacts;

use Broadcast\Maintenance\ContactImportCommand;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/bin/import-whatsapp-contacts.php';

final class ContactImportCommandTest extends TestCase
{
    public function testDryRunNeedsNoDatabaseAndPrintsNoContacts(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'contacts-cli-');
        $writer = new Writer(); $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['phone','language','email']));
        $writer->addRow(Row::fromValues(['+33123456789','FR','private@example.test'])); $writer->close();
        try {
            $result = (new ContactImportCommand())->run([$path]);
            self::assertSame(0, $result['exit']);
            self::assertSame('dry_run', $result['report']['status']);
            self::assertSame(1, $result['report']['contacts']);
            self::assertStringNotContainsString('33123456789', json_encode($result));
            self::assertStringNotContainsString('private@example.test', json_encode($result));
        } finally { unlink($path); }
    }

    public function testApplyRequiresReviewedContentHashBeforeConnecting(): void
    {
        $result = (new ContactImportCommand())->run(['missing.xlsx','--apply']);
        self::assertSame(1, $result['exit']);
        self::assertSame('import_failed', $result['report']['status']);
    }
}
