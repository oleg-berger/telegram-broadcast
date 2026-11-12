<?php
declare(strict_types=1);

namespace Broadcast\Tests;

use Broadcast\Application\MailGateway;
use Broadcast\Application\TelegramGateway;
use Broadcast\Application\Translator;
use Broadcast\Application\Worker;
use Broadcast\Domain\ApiFailure;
use Broadcast\Infrastructure\SqliteStore;
use PDO;
use PHPUnit\Framework\TestCase;

final class CatchupHistoryTest extends TestCase
{
    private SqliteStore $store;
    private PDO $db;
    private Worker $worker;
    private array $sent = [];
    private array $mail = [];
    private array $calls = [];
    private array $logs = [];
    private array $failLanguages = [];
    private bool $failSubject = false;
    private int $now = 0;

    protected function setUp(): void
    {
        $this->store = new SqliteStore(':memory:');
        $this->store->migrate();
        $this->db = (new \ReflectionProperty(SqliteStore::class, 'db'))->getValue($this->store);
        $telegram = $this->createMock(TelegramGateway::class);
        $telegram->method('sendMessage')->willReturnCallback(function (int $id, string $text): void {
            $this->sent[] = [$id, $text];
        });
        $mail = $this->createMock(MailGateway::class);
        $mail->method('send')->willReturnCallback(function (string $to, string $subject, string $text): void {
            $this->mail[] = [$to, $subject, $text];
        });
        $translator = $this->createMock(Translator::class);
        $translator->method('translate')->willReturnCallback(function (string $text, string $language): string {
            $this->calls[] = [$text, $language];
            if (in_array($language, $this->failLanguages, true) || ($this->failSubject && $text === 'Subject')) {
                throw new ApiFailure('Synthetic secret must not be logged', true);
            }
            return "$language:$text";
        });
        $this->worker = new Worker($this->store, $telegram, $translator, function (array $event): void {
            $this->logs[] = $event;
        }, $mail);
    }

    private function user(int $id, string $language): void
    {
        $statement = $this->db->prepare("INSERT INTO users(id,language,status,subscribed,email) VALUES(?,?,'approved',1,?)");
        $statement->execute([$id, $language, "user$id@example.invalid"]);
        $this->store->saveUser($this->store->user($id));
    }

    private function approve(string $text, int $author = 99): int
    {
        $draft = $this->store->createDraft($author, $text);
        $this->store->saveDraft($draft['id'], $draft['version'], ['subject' => 'Subject', 'status' => 'pending']);
        return $this->store->transaction(fn () => $this->store->approveDraft($draft['id'], $draft['version'] + 1, 100))['id'];
    }

    private function catchup(int $id, string $language): bool
    {
        return $this->store->transaction(fn () => $this->store->addCatchup($id, $language));
    }

    private function drain(): void
    {
        for ($i = 0; $i < 250 && $this->worker->tick($this->now += 120); ++$i) {}
        self::assertLessThan(250, $i);
    }

    private function messages(int $user): array
    {
        return array_column(array_values(array_filter($this->sent, static fn (array $row): bool => $row[0] === $user)), 1);
    }

    public function testCatchupIncludesPreparingAndFailedBroadcastsWithoutRetryingOldRecipients(): void
    {
        $this->user(1, 'FR');
        $this->failLanguages = ['FR'];
        foreach (range(1, 5) as $number) {
            $this->approve("News$number");
        }
        $this->drain();
        self::assertSame(5, (int) $this->db->query("SELECT COUNT(*) FROM broadcasts WHERE status='failed'")->fetchColumn());
        $this->user(2, 'ES');
        self::assertTrue($this->catchup(2, 'ES'));
        $this->drain();
        self::assertSame(['ES:News1', 'ES:News2', 'ES:News3', 'ES:News4', 'ES:News5'], $this->messages(2));
        self::assertCount(5, $this->mail);
        self::assertSame([], $this->messages(1));
        self::assertSame(10, (int) $this->db->query("SELECT COUNT(*) FROM jobs WHERE user_id=1 AND kind='delivery' AND status='failed'")->fetchColumn());
        foreach ($this->db->query("SELECT report_included,status FROM jobs WHERE user_id=2 AND kind='delivery'") as $job) {
            self::assertSame(['report_included' => 1, 'status' => 'done'], $job);
        }

        $this->approve('Preparing');
        $this->user(3, 'EN-GB');
        self::assertTrue($this->catchup(3, 'EN-GB'));
        $this->drain();
        self::assertSame(['EN-GB:News2', 'EN-GB:News3', 'EN-GB:News4', 'EN-GB:News5', 'EN-GB:Preparing'], $this->messages(3));
    }

    public function testCatchupRecoversOldFailedTextAndSubjectOnceWithoutDuplicatingSuccessfulRecipients(): void
    {
        $this->user(1, 'ES');
        $this->failLanguages = ['ES'];
        $this->approve('Text failed');
        $this->drain();
        $this->failLanguages = [];
        $this->failSubject = true;
        $this->approve('Subject failed');
        $this->drain();
        $this->failSubject = false;
        $this->approve('Delivered');
        $this->drain();
        self::assertSame(['ES:Delivered'], $this->messages(1));
        $this->user(2, 'ES');
        self::assertTrue($this->catchup(2, 'ES'));
        self::assertTrue($this->catchup(2, 'ES'));
        $this->drain();
        self::assertSame(['ES:Text failed', 'ES:Subject failed', 'ES:Delivered'], $this->messages(2));
        self::assertSame(['ES:Delivered'], $this->messages(1));
        self::assertCount(4, $this->mail);
        $count = count($this->calls);
        $this->catchup(2, 'ES');
        $this->drain();
        self::assertCount($count, $this->calls);
        self::assertCount(4, $this->mail);
    }

    public function testCatchupCannotResetFailedTranslationBudgetForEveryNewUser(): void
    {
        $this->user(1, 'ES');
        $this->failLanguages = ['ES'];
        $id = $this->approve('Permanent outage');
        $this->drain();
        self::assertCount(5, $this->calls);
        $this->user(2, 'ES');
        $this->catchup(2, 'ES');
        $this->drain();
        self::assertCount(10, $this->calls);
        $this->user(3, 'ES');
        $this->catchup(3, 'ES');
        $this->catchup(2, 'ES');
        $this->drain();
        self::assertCount(10, $this->calls);
        self::assertSame([], $this->messages(2));
        self::assertSame([], $this->messages(3));
        self::assertSame([], $this->mail);
        self::assertStringNotContainsString('Synthetic secret', json_encode($this->logs));
        self::assertStringNotContainsString('Permanent outage', json_encode($this->logs));
        $this->failLanguages = [];
        self::assertTrue($this->store->retryBroadcast($id));
        $this->drain();
        self::assertSame(['ES:Permanent outage'], $this->messages(2));
        self::assertSame(['ES:Permanent outage'], $this->messages(3));
    }

    public function testCatchupUsesApprovalOrderExcludesAuthorAndUnapprovedRowsAndKeepsSnapshot(): void
    {
        $earlyDraft = $this->store->createDraft(99, 'Approved last');
        foreach (range(1, 6) as $number) {
            $this->approve("News$number");
        }
        $this->store->saveDraft($earlyDraft['id'], 1, ['subject' => 'Subject', 'status' => 'pending']);
        $this->store->approveDraft($earlyDraft['id'], 2, 100);
        $this->approve('Own announcement', 2);
        $this->store->createBroadcast(99, 'Legacy unapproved');
        $this->store->createDraft(99, 'Draft');
        $this->user(2, 'ES');
        $this->catchup(2, 'ES');
        $idsBefore = $this->db->query("SELECT broadcast_id FROM jobs WHERE kind='delivery' AND user_id=2 AND channel='telegram' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([3, 4, 5, 6, 7], $idsBefore);
        $this->drain();
        self::assertSame(['ES:News3', 'ES:News4', 'ES:News5', 'ES:News6', 'ES:Approved last'], $this->messages(2));
        $this->catchup(2, 'ES');
        $this->drain();
        self::assertCount(5, $this->messages(2));
        self::assertCount(5, $this->mail);
    }

    public function testCatchupDiagnosticsIdentifyBroadcastChannelAndLanguageWithoutContent(): void
    {
        $id = $this->approve('Private original');
        $this->user(2, 'ES');
        $this->catchup(2, 'ES');
        $this->drain();
        $events = array_values(array_filter($this->logs, static fn (array $row): bool => $row['kind'] === 'delivery'));
        self::assertCount(2, $events);
        self::assertSame([$id, $id], array_column($events, 'broadcast_id'));
        self::assertSame(['telegram', 'email'], array_column($events, 'channel'));
        self::assertSame(['ES', 'ES'], array_column($events, 'language'));
        self::assertStringNotContainsString('Private original', json_encode($events));
        self::assertStringNotContainsString('example.invalid', json_encode($events));
    }

    public function testCatchupRecoveryOnReadyBroadcastDoesNotResetActiveAttemptsOrOldDeliveries(): void
    {
        $this->approve('Ready history');
        $this->drain();
        $this->user(1, 'ES');
        $this->failLanguages = ['ES'];
        $this->catchup(1, 'ES');
        $this->drain();
        self::assertSame('ready', $this->store->broadcast(1)['status']);
        self::assertCount(5, $this->calls);
        $this->user(2, 'ES');
        $this->catchup(2, 'ES');
        self::assertTrue($this->worker->tick($this->now += 120));
        self::assertCount(6, $this->calls);
        $this->user(3, 'ES');
        $this->catchup(3, 'ES');
        $this->catchup(2, 'ES');
        self::assertSame(1, (int) $this->db->query("SELECT attempts FROM jobs WHERE kind='translate' AND language='ES'")->fetchColumn());
        $this->failLanguages = [];
        $this->drain();
        self::assertSame([], $this->messages(1));
        self::assertSame(['ES:Ready history'], $this->messages(2));
        self::assertSame(['ES:Ready history'], $this->messages(3));
        self::assertSame(2, (int) $this->db->query("SELECT COUNT(*) FROM jobs WHERE user_id=1 AND kind='delivery' AND status='failed'")->fetchColumn());
    }

    public function testCatchupRejectsUnapprovedAndMissingUsers(): void
    {
        $this->approve('Approved');
        $this->drain();
        self::assertFalse($this->catchup(2, 'ES'));
        $this->user(2, 'ES');
        $this->db->exec("UPDATE users SET status='pending' WHERE id=2");
        self::assertFalse($this->catchup(2, 'ES'));
        self::assertSame(0, (int) $this->db->query("SELECT COUNT(*) FROM jobs WHERE user_id=2")->fetchColumn());
    }

    public function testCatchupMigrationPreservesExistingJobsTranslationsAndDeliveryParts(): void
    {
        $legacy = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $legacy->exec('PRAGMA foreign_keys=ON; CREATE TABLE migrations(version TEXT PRIMARY KEY)');
        foreach (['001_initial.sql', '002_approvals_and_email.sql', '003_team_channel.sql'] as $file) {
            $legacy->exec(file_get_contents(dirname(__DIR__) . '/migrations/' . $file));
            $legacy->prepare('INSERT INTO migrations(version) VALUES(?)')->execute([$file]);
        }
        $legacy->exec("INSERT INTO users(id,status) VALUES(1,'approved');
            INSERT INTO broadcasts(id,admin_id,text,created_at,status,approved_by) VALUES(1,99,'Old',1,'ready',100);
            INSERT INTO translations(broadcast_id,language,text,subject) VALUES(1,'ES','Translated','Subject');
            INSERT INTO jobs(kind,broadcast_id,user_id,language,part,status,channel,report_included)
            VALUES('delivery',1,1,'ES',2,'done','telegram',1),('delivery',1,1,'ES',0,'pending','email',1)");
        $before = $legacy->query('SELECT * FROM jobs ORDER BY id')->fetchAll();
        (new \ReflectionProperty(SqliteStore::class, 'db'))->setValue($this->store, $legacy);
        $this->store->migrate();
        $this->store->migrate();
        foreach ($legacy->query('SELECT * FROM jobs ORDER BY id')->fetchAll() as $index => $job) {
            self::assertSame(0, $job['catchup']);
            self::assertSame(0, $job['catchup_recovered']);
            unset($job['catchup'], $job['catchup_recovered']);
            self::assertSame($before[$index], $job);
        }
        self::assertSame('Translated', $this->store->translation(1, 'ES'));
        self::assertSame('Subject', $this->store->translationSubject(1, 'ES'));
        self::assertSame(1, (int) $legacy->query("SELECT COUNT(*) FROM migrations WHERE version='004_catchup_history.sql'")->fetchColumn());
        self::assertSame('ok', $legacy->query('PRAGMA integrity_check')->fetchColumn());
    }
}
