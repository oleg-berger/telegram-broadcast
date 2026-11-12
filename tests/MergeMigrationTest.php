<?php
declare(strict_types=1);

namespace Broadcast\Tests;

use Broadcast\Infrastructure\SqliteStore;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MergeMigrationTest extends TestCase
{
    public static function sourceBranches(): array
    {
        return ['deepl-translate' => [false], 'broadcast-whatsapp' => [true]];
    }

    #[DataProvider('sourceBranches')]
    public function testUpgradePreservesBothBranchHistoriesAndIsRepeatable(bool $whatsapp): void
    {
        $db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec('PRAGMA foreign_keys=ON; CREATE TABLE migrations(version TEXT PRIMARY KEY)');
        foreach (glob(dirname(__DIR__) . '/migrations/*.sql') as $path) {
            $name = basename($path);
            if ($whatsapp ? $name === '004_catchup_history.sql' : $name > '004_catchup_history.sql') { continue; }
            $db->exec(file_get_contents($path));
            $db->prepare('INSERT INTO migrations VALUES (?)')->execute([$name]);
        }
        $db->exec("INSERT INTO users(id,status,subscribed,phone,email) VALUES(777,'approved',1,'+447700900001','old@example.test');
            INSERT INTO contacts VALUES(777,'+447700900001','old@example.test');
            INSERT INTO drafts(id,admin_id,text,created_at,status,subject) VALUES(1,99,'Draft',5,'draft','Subject');
            INSERT INTO broadcasts(id,admin_id,text,created_at,status,approved_by) VALUES(1,99,'News',10,'ready',100);
            INSERT INTO translations VALUES(1,'ES','Translated','Subject');
            INSERT INTO jobs(kind,broadcast_id,user_id,language,status,channel,part,attempts,report_included)
                VALUES('delivery',1,777,'ES','done','telegram',3,0,1),('delivery',1,777,'ES','failed','email',1,5,1);
            INSERT INTO processed_updates(id) VALUES(999)");
        $tables = ['users', 'contacts', 'drafts', 'broadcasts', 'translations', 'jobs', 'processed_updates'];
        if ($whatsapp) {
            $db->exec("INSERT INTO channel_identities(user_id,channel,address) VALUES(777,'telegram','777');
                INSERT INTO directory_links(contact_id,user_id) VALUES(42,777);
                INSERT INTO attachments(file_id,display_name,expected_size,created_at) VALUES('file-1','test.zip',100,1);
                INSERT INTO draft_attachments VALUES(1,1,0);
                INSERT INTO broadcast_attachments VALUES(1,1,0);
                INSERT INTO jobs(kind,broadcast_id,user_id,language,status,channel) VALUES('delivery',1,777,'ES','unknown','whatsapp')");
            $tables = [...$tables, 'channel_identities', 'directory_links', 'attachments', 'draft_attachments', 'broadcast_attachments'];
        } else {
            $db->exec('UPDATE jobs SET catchup=1,catchup_recovered=1');
        }
        $before = [];
        foreach ($tables as $table) { $before[$table] = $db->query("SELECT * FROM $table")->fetchAll(); }
        $store = new SqliteStore(':memory:');
        (new \ReflectionProperty($store, 'db'))->setValue($store, $db);
        $store->migrate();
        $store->migrate();
        foreach ($before as $table => $rows) {
            $columns = implode(',', array_keys($rows[0]));
            self::assertSame($rows, $db->query("SELECT $columns FROM $table")->fetchAll(), $table);
        }
        self::assertSame(777, $store->identity('telegram', '777')['user_id']);
        self::assertSame(2, (int) $db->query("SELECT COUNT(*) FROM migrations WHERE version IN ('004_catchup_history.sql','004_whatsapp.sql')")->fetchColumn());
        self::assertSame(count(glob(dirname(__DIR__) . '/migrations/*.sql')), (int) $db->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
        if ($whatsapp) { self::assertSame(0, (int) $db->query('SELECT SUM(catchup+catchup_recovered) FROM jobs')->fetchColumn()); }
        self::assertSame('ok', $db->query('PRAGMA integrity_check')->fetchColumn());
        self::assertSame([], $db->query('PRAGMA foreign_key_check')->fetchAll());
    }
}
