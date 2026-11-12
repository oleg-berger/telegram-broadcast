<?php
declare(strict_types=1);

namespace Broadcast\Tests\WhatsApp\Store;

use Broadcast\Infrastructure\SqliteStore;
use PDO;
use PHPUnit\Framework\TestCase;

final class WhatsAppStoreTest extends TestCase
{
    private SqliteStore $store;
    private PDO $db;

    protected function setUp(): void
    {
        $this->store = new SqliteStore(':memory:');
        $this->store->migrate();
        $this->db = (new \ReflectionProperty($this->store, 'db'))->getValue($this->store);
    }

    public function testAdditiveMigrationPreservesHistoricalRecordsAndBackfillsAddresses(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'wa-migrate-');
        try {
            $old = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $old->exec('CREATE TABLE migrations(version TEXT PRIMARY KEY)');
            foreach (glob(dirname(__DIR__, 3) . '/migrations/00[123]*.sql') as $file) {
                $old->exec(file_get_contents($file));
                $old->prepare('INSERT INTO migrations VALUES (?)')->execute([basename($file)]);
            }
            $old->exec("INSERT INTO users(id,name,status,phone,email,submitted_at) VALUES (900001,'Historical','approved','+12025550100','old@example.test',10)");
            $old->exec("INSERT INTO contacts VALUES (900001,'+12025550100','old@example.test')");
            $old->exec("INSERT INTO broadcasts(id,admin_id,text,created_at,status,approved_by) VALUES (9,77,'history',5,'ready',77)");
            $old->exec("INSERT INTO jobs(id,kind,broadcast_id,user_id,status,part,payload) VALUES (12,'delivery',9,900001,'done',3,'{}')");
            $before = [];
            foreach (['users','contacts','broadcasts','jobs'] as $table) {
                $before[$table] = $old->query("SELECT * FROM $table")->fetchAll(PDO::FETCH_ASSOC);
            }
            $store = new SqliteStore($path);
            $store->migrate();
            foreach ($before as $table => $rows) {
                $columns = implode(',', array_keys($rows[0]));
                self::assertSame($rows, $old->query("SELECT $columns FROM $table")->fetchAll(PDO::FETCH_ASSOC));
            }
            self::assertNull($old->query('SELECT audience_snapshot FROM broadcasts WHERE id=9')->fetchColumn());
            self::assertSame(900001, $store->identity('telegram', '900001')['user_id']);
            self::assertGreaterThan(900001, $store->ensureIdentity('whatsapp', '12025550100', '+12025550100')['user_id']);
            $store->close();
            $old = null;
        } finally {
            if (isset($store)) { $store->close(); }
            $old = null;
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                if (is_file($file)) { unlink($file); }
            }
        }
    }

    public function testInternalIdsNeverBecomeTelegramOrWhatsappAddresses(): void
    {
        $wa = $this->store->ensureIdentity('whatsapp', '12025550100', '+12025550100');
        $tg = $this->store->ensureIdentity('telegram', (string) $wa['user_id']);
        self::assertNotSame($wa['user_id'], $tg['user_id']);
        self::assertCount(1, $this->store->identities($wa['user_id']));
        $profile = $this->store->user($wa['user_id']);
        $profile['name'] = 'Name';
        $this->store->saveUser($profile);
        self::assertCount(1, $this->store->identities($wa['user_id']));
        self::assertTrue($wa['created']);
        $existing = $this->store->ensureIdentity('whatsapp', '12025550100', '+12025550100');
        self::assertFalse($existing['created']);
        self::assertSame($wa['id'], $existing['id']);
        $lid = $this->store->ensureIdentity('whatsapp', '998877@lid');
        self::assertNull($lid['verified_phone']);
    }

    public function testIdentityCreationRollsBackWithIncomingEvent(): void
    {
        try {
            $this->store->transaction(function (): void {
                $this->store->acceptChannelEvent('whatsapp', 'event1', 10);
                $this->store->ensureIdentity('whatsapp', '12025550100', '+12025550100');
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {}
        self::assertNull($this->store->identity('whatsapp', '12025550100'));
        self::assertSame(0, $this->store->channelCursor('whatsapp'));
        self::assertTrue($this->store->acceptChannelEvent('whatsapp', 'event1', 10));
        self::assertFalse($this->store->acceptChannelEvent('whatsapp', 'event1', 20));
        self::assertSame(20, $this->store->channelCursor('whatsapp'));
        self::assertTrue($this->store->acceptUpdate(10));
        self::assertTrue($this->store->acceptChannelEvent('whatsapp', 'older', 2));
        self::assertSame(20, $this->store->channelCursor('whatsapp'));
    }

    public function testRepliesSnapshotAddressAndPersistExactNumberedLabels(): void
    {
        $wa = $this->store->ensureIdentity('whatsapp', '12025550100', '+12025550100');
        $this->store->queueUserReply($wa['user_id'], 'Вопрос', ['reply_markup' => ['inline_keyboard' => [[['text' => 'Да', 'callback_data' => 'yes'], ['text' => 'Нет', 'callback_data' => 'no']]]]], $wa['id']);
        $job = $this->store->nextJob(time());
        self::assertSame('whatsapp', $job['channel']);
        self::assertSame('12025550100', $job['payload']['address']);
        self::assertSame($wa['id'], $job['payload']['identity_id']);
        self::assertSame("Вопрос\n\n1. Да\n2. Нет", $job['payload']['text']);
        self::assertSame([1 => 'yes', 2 => 'no'], $this->store->menu($wa['id'])['options']);
        $this->store->queueUserReply($wa['user_id'], 'Done', ['reply_markup' => ['remove_keyboard' => true]], $wa['id']);
        self::assertSame([], $this->store->menu($wa['id']));
        $tg = $this->store->ensureIdentity('telegram', '500');
        $this->expectException(\InvalidArgumentException::class);
        $this->store->queueUserReply($wa['user_id'], 'Private', [], $tg['id']);
    }

    public function testConfirmationRequiresOriginalIdentityOneUseAndNoProfileMerge(): void
    {
        [$target, $source] = $this->linkPair();
        $request = $this->store->createLinkRequest($source['id'], $target['user_id'], 1000);
        self::assertNotNull($request);
        self::assertSame(43, strlen($request['token']));
        $stored = $this->db->query('SELECT * FROM link_requests')->fetch(PDO::FETCH_ASSOC);
        self::assertNotContains($request['token'], $stored);
        self::assertNull($this->store->confirmLink($request['token'], $source['id'], 1001));
        $linked = $this->store->confirmLink($request['token'], $target['id'], 1001);
        self::assertSame($target['user_id'], $linked['user_id']);
        self::assertSame($target['user_id'], $this->store->identityById($source['id'])['user_id']);
        self::assertSame('linked', $this->store->user($source['user_id'])['status']);
        self::assertSame('approved', $this->store->user($target['user_id'])['status']);
        self::assertNull($this->store->confirmLink($request['token'], $target['id'], 1002));
    }

    public function testLinkLimitsExpiryAndManualAuditAreEnforced(): void
    {
        [$target, $source] = $this->linkPair();
        $first = $this->store->createLinkRequest($source['id'], $target['user_id'], 1000);
        self::assertNull($this->store->createLinkRequest($source['id'], $target['user_id'], 1001));
        self::assertNull($this->store->confirmLink($first['token'], $target['id'], 1900));
        self::assertNull($this->store->resolveLink($first['id'], 42, true, 'Checked', 1900));
        $second = $this->store->createLinkRequest($source['id'], $target['user_id'], 1901, true);
        self::assertNull($this->store->confirmLink($second['token'], $target['id'], 1902));
        self::assertNull($this->store->resolveLink($second['id'], 42, true, ' ', 1902));
        self::assertNotNull($this->store->resolveLink($second['id'], 42, false, 'No proof', 1902));
        $third = $this->store->createLinkRequest($source['id'], $target['user_id'], 1903, true);
        self::assertNotNull($third);
        self::assertNotNull($this->store->resolveLink($third['id'], 42, false, 'No proof', 1904));
        self::assertNull($this->store->createLinkRequest($source['id'], $target['user_id'], 1905));
        self::assertSame(42, $this->store->linkRequest($third['id'])['actor']);
        self::assertSame('No proof', $this->store->linkRequest($third['id'])['reason']);
    }

    public function testSubmittedSourcesRequireStaffAndRetainContactsAndHistory(): void
    {
        [$target, $source] = $this->linkPair();
        $this->profile($source['user_id'], 'pending');
        self::assertNull($this->store->createLinkRequest($source['id'], $target['user_id'], 1000));
        $request = $this->store->createLinkRequest($source['id'], $target['user_id'], 1000, true);
        self::assertNotNull($this->store->resolveLink($request['id'], 77, true, 'Ownership verified', 1001));
        self::assertSame($target['user_id'], $this->store->contactOwner('email', 'user' . $source['user_id'] . '@example.test', 0)['id']);
        self::assertSame($source['user_id'], (int) $this->db->query("SELECT user_id FROM contacts WHERE email='user" . $source['user_id'] . "@example.test'")->fetchColumn());
        self::assertNotNull($this->store->user($source['user_id'])['submitted_at']);
    }

    public function testPendingTargetAndReverseChannelLinkRemainPending(): void
    {
        $target = $this->store->ensureIdentity('whatsapp', '12025550100', '+12025550100');
        $source = $this->store->ensureIdentity('telegram', '77');
        $this->profile($target['user_id'], 'pending');
        $request = $this->store->createLinkRequest($source['id'], $target['user_id'], 1000);
        self::assertNotNull($this->store->confirmLink($request['token'], $target['id'], 1001));
        self::assertSame('pending', $this->store->user($target['user_id'])['status']);
        self::assertNull($this->store->createLinkRequest($source['id'], $target['user_id'], 1002));
    }

    public function testAudienceAndCatchupSnapshotOneEmailAndEachConfirmedChannel(): void
    {
        [$target, $source] = $this->linkPair();
        $broadcast = $this->approvedBroadcast();
        self::assertSame(['email','telegram'], $this->deliveryChannels($broadcast['id']));
        $this->store->saveTranslation($broadcast['id'], 'RU', 'Текст');
        $this->store->saveTranslationSubject($broadcast['id'], 'RU', 'Тема');
        $this->db->exec("UPDATE jobs SET status='done' WHERE kind IN ('translate','translate_subject','delivery')");
        $this->store->refreshBroadcasts();
        self::assertSame(1, $this->store->broadcast($broadcast['id'])['reported']);
        $request = $this->store->createLinkRequest($source['id'], $target['user_id'], 1000);
        $this->store->confirmLink($request['token'], $target['id'], 1001);
        self::assertTrue($this->store->addChannelCatchup($target['user_id'], 'FR', 'whatsapp'));
        self::assertSame(['email','telegram','whatsapp'], $this->deliveryChannels($broadcast['id']));
        $this->db->exec('UPDATE broadcasts SET reported=1');
        self::assertFalse($this->store->addChannelCatchup($target['user_id'], 'ES', 'whatsapp'));
        self::assertSame(1, $this->store->broadcast($broadcast['id'])['reported']);
        $row = $this->db->query("SELECT * FROM jobs WHERE kind='delivery' AND channel='whatsapp'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('FR', $row['language']);
        self::assertSame('12025550100', json_decode($row['payload'], true)['address']);
        $next = $this->approvedBroadcast();
        self::assertSame(['email','telegram','whatsapp'], $this->deliveryChannels($next['id']));
    }

    public function testOutageDoesNotBlockOtherChannelsAndWhatsappDeliveryRemainsFifo(): void
    {
        $wa = $this->store->ensureIdentity('whatsapp', '12025550100', '+12025550100');
        $this->profile($wa['user_id'], 'approved');
        $first = $this->approvedBroadcast();
        $second = $this->approvedBroadcast();
        $this->db->exec("UPDATE broadcasts SET status='ready'; UPDATE jobs SET status='done' WHERE kind IN ('translate','translate_subject')");
        foreach ([$first, $second] as $broadcast) {
            $this->store->saveTranslation($broadcast['id'], 'RU', 'Текст');
            $this->store->saveTranslationSubject($broadcast['id'], 'RU', 'Тема');
        }
        $firstJob = (int) $this->db->query("SELECT id FROM jobs WHERE kind='delivery' AND channel='whatsapp' ORDER BY id LIMIT 1")->fetchColumn();
        $this->store->deferJob($firstJob, 0, 500, 'unavailable', false);
        $this->db->exec("UPDATE jobs SET status='done' WHERE channel='email'");
        self::assertNull($this->store->nextJob(100));
        self::assertTrue($this->store->setChannelState('whatsapp', 'unavailable', 1000));
        self::assertFalse($this->store->setChannelState('whatsapp', 'unavailable', 1000));
        $this->store->queueReply(77, 'Staff');
        self::assertSame('telegram', $this->store->nextJob(600)['channel']);
        $this->db->exec("UPDATE jobs SET status='done' WHERE kind='message'");
        self::assertNull($this->store->nextJob(600));
        self::assertSame($firstJob, $this->store->nextJob(1000)['id']);
    }

    public function testPartsReconcileEarlyReceiptsAndNeverDowngradeOrAcceptWrongAddress(): void
    {
        $job = $this->whatsappJob();
        self::assertTrue($this->store->beginWhatsAppPart($job['id'], 0, 100));
        self::assertFalse($this->store->beginWhatsAppPart($job['id'], 0, 101));
        self::assertTrue($this->store->recordWhatsAppReceipt('m1', 'read', '12025550100'));
        self::assertFalse($this->store->recordWhatsAppReceipt('m1', 'read', '12025550100'));
        $this->store->completeWhatsAppPart($job['id'], 0, 'm1', false);
        self::assertSame(1, $this->store->nextJob(102)['part']);
        self::assertTrue($this->store->beginWhatsAppPart($job['id'], 1, 103));
        $this->store->completeWhatsAppPart($job['id'], 1, 'm2', true);
        self::assertSame('accepted', $this->jobRow($job['id'])['status']);
        $this->store->recordWhatsAppReceipt('m2', 'read', '19999999999');
        self::assertSame('accepted', $this->jobRow($job['id'])['status']);
        $this->store->recordWhatsAppReceipt('m2', 'delivered', '12025550100');
        self::assertSame('delivered', $this->jobRow($job['id'])['status']);
        $this->store->recordWhatsAppReceipt('m2', 'read', '12025550100');
        $this->store->recordWhatsAppReceipt('m1', 'delivered', '12025550100');
        self::assertSame('read', $this->jobRow($job['id'])['status']);
    }

    public function testCrashRecoveryAndAmbiguousPartAreNeverBlindlyRetried(): void
    {
        $job = $this->whatsappJob();
        $this->store->beginWhatsAppPart($job['id'], 0, 100);
        self::assertCount(1, $this->store->recoverWhatsAppDispatches());
        self::assertSame([], $this->store->recoverWhatsAppDispatches());
        self::assertSame('unknown', $this->jobRow($job['id'])['status']);
        self::assertNull($this->store->nextJob(1000));
        $this->store->recordWhatsAppReceipt('unrelated', 'read', '12025550100');
        self::assertSame('unknown', $this->jobRow($job['id'])['status']);
        self::assertFalse($this->store->beginWhatsAppPart($job['id'], 0, 1000));
    }

    public function testResetClearsBusinessDataButPreservesDedupAndIdentitySequence(): void
    {
        $job = $this->whatsappJob();
        $firstId = $job['user_id'];
        $this->store->beginWhatsAppPart($job['id'], 0, 100);
        $this->store->recordWhatsAppReceipt('future', 'read', '12025550100');
        $this->store->acceptChannelEvent('whatsapp', 'old', 200);
        $this->store->resetBusinessData(300);
        self::assertSame(0, array_sum($this->store->inspectBusinessData()));
        self::assertSame(200, $this->store->channelCursor('whatsapp'));
        self::assertFalse($this->store->acceptChannelEvent('whatsapp', 'old', 200));
        self::assertGreaterThan($firstId, $this->store->ensureIdentity('telegram', '900')['user_id']);
    }

    public function testConnectedChannelCanSendBeforeNextHealthProbe(): void
    {
        $job = $this->whatsappJob();
        $this->store->setChannelState('whatsapp', 'connected', 1000);
        self::assertSame($job['id'], $this->store->nextJob(100)['id']);
    }

    public function testMappingUpgradesOpaqueAddressWithoutCreatingAnotherPerson(): void
    {
        $first = $this->store->ensureIdentity('whatsapp', '998877@lid');
        $upgraded = $this->store->ensureIdentity('whatsapp', '998877@lid', '+12025550100');
        self::assertSame($first['user_id'], $upgraded['user_id']);
        self::assertSame('12025550100', $upgraded['address']);
        self::assertSame($first['id'], $this->store->identity('whatsapp', '998877@lid')['id']);
        self::assertSame($first['id'], $this->store->identity('whatsapp', '12025550100')['id']);
        self::assertFalse($upgraded['created']);
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testProvenAliasConflictRequiresAuditedResolutionAndRetainsOldSnapshot(): void
    {
        $target = $this->store->ensureIdentity('whatsapp', '12025550100', '+12025550100');
        $this->profile($target['user_id'], 'approved');
        $source = $this->store->ensureIdentity('whatsapp', '998877@lid');
        $this->store->queueUserReply($source['user_id'], 'Old response');
        $oldJob = $this->store->nextJob(time());
        $conflict = $this->store->ensureIdentity('whatsapp', '998877@lid', '+12025550100');
        self::assertTrue($conflict['address_conflict']);
        self::assertSame($target['user_id'], $conflict['conflict_user_id']);
        self::assertNull($conflict['verified_phone']);
        self::assertSame(0, $conflict['enabled']);
        self::assertNull($this->store->createLinkRequest($source['id'], $target['user_id'], 1000));
        $manual = $this->store->createLinkRequest($source['id'], $target['user_id'], 1000, true);
        $result = $this->store->resolveLink($manual['id'], 77, true, 'Verified account mapping', 1001);
        self::assertSame($target['id'], $result['identity_id']);
        self::assertSame($target['id'], $this->store->identity('whatsapp', '998877@lid')['id']);
        self::assertSame(1, $this->store->identityById($source['id'])['retired']);
        self::assertSame($oldJob['payload'], json_decode($this->jobRow($oldJob['id'])['payload'], true));
        self::assertSame('linked', $this->store->user($source['user_id'])['status']);
    }

    public function testLinkHelpEscalatesExistingAutomaticRequestAndRevokesOldToken(): void
    {
        [$target, $source] = $this->linkPair();
        $first = $this->store->createLinkRequest($source['id'], $target['user_id'], 1000);
        $manual = $this->store->createLinkRequest($source['id'], $target['user_id'], 1001, true);
        self::assertNotNull($manual);
        self::assertSame($first['id'], $manual['id']);
        self::assertSame(1, $manual['manual']);
        self::assertSame($first['expires_at'], $manual['expires_at']);
        self::assertNull($this->store->confirmLink($first['token'], $target['id'], 1002));
        self::assertNotNull($this->store->resolveLink($manual['id'], 77, true, 'Checked', 1002));
    }

    public function testDeferredReceiptsAreDurableAndFairAcrossUnresolvedRows(): void
    {
        $this->store->transaction(function (): void {
            $this->store->deferWhatsAppReceipt('r1', 1, ['m1'], 'read', '99@lid');
            $this->store->acceptChannelEvent('whatsapp', 'r1', 1);
        });
        $this->store->deferWhatsAppReceipt('r2', 2, ['m2'], 'delivered', '88@lid');
        $this->store->deferWhatsAppReceipt('r3', 3, ['m3'], 'read', '77@lid');
        self::assertSame('r1', $this->store->pendingWhatsAppReceipts(1)[0]['event_id']);
        self::assertSame('r2', $this->store->pendingWhatsAppReceipts(1)[0]['event_id']);
        self::assertSame('r3', $this->store->pendingWhatsAppReceipts(1)[0]['event_id']);
        self::assertSame('r1', $this->store->pendingWhatsAppReceipts(1)[0]['event_id']);
        $this->store->completeWhatsAppReceipt('r1');
        self::assertCount(2, $this->store->pendingWhatsAppReceipts());
        self::assertSame(1, $this->store->channelCursor('whatsapp'));
        self::assertFalse($this->store->acceptChannelEvent('whatsapp', 'r1', 1));
    }

    public function testSafeNoSendFailureCanRetryButUnknownCannot(): void
    {
        $job = $this->whatsappJob();
        self::assertTrue($this->store->beginWhatsAppPart($job['id'], 0, 100));
        $this->store->deferJob($job['id'], 1, 200, 'whatsapp_transient', false);
        self::assertSame($job['id'], $this->store->nextJob(200)['id']);
        self::assertTrue($this->store->beginWhatsAppPart($job['id'], 0, 200));
        $this->store->markWhatsAppUncertain($job['id'], 0);
        $this->store->deferJob($job['id'], 0, 0, 'temporary_api_error', false);
        self::assertSame('unknown', $this->jobRow($job['id'])['status']);
        self::assertNull($this->store->nextJob(1000));
    }

    public function testLateSubmissionAndCompetingChannelRejectStaleAutomaticLinks(): void
    {
        [$target, $source] = $this->linkPair();
        $request = $this->store->createLinkRequest($source['id'], $target['user_id'], 1000);
        $this->profile($source['user_id'], 'pending');
        self::assertNull($this->store->confirmLink($request['token'], $target['id'], 1001));
        self::assertSame($source['user_id'], $this->store->identityById($source['id'])['user_id']);
        $manual = $this->store->createLinkRequest($source['id'], $target['user_id'], 1002, true);
        self::assertNotNull($manual);
        $other = $this->store->ensureIdentity('whatsapp', '12025550101', '+12025550101');
        $competing = $this->store->createLinkRequest($other['id'], $target['user_id'], 1003);
        self::assertNotNull($this->store->confirmLink($competing['token'], $target['id'], 1004));
        self::assertNull($this->store->resolveLink($manual['id'], 77, true, 'Checked', 1005));
        self::assertSame($source['user_id'], $this->store->identityById($source['id'])['user_id']);
    }

    public function testLegacySavedTelegramAndAuthorExclusionUseTransportAddress(): void
    {
        $wa = $this->store->ensureIdentity('whatsapp', '12025550100', '+12025550100');
        $this->profile($wa['user_id'], 'approved');
        $tg = $this->store->ensureIdentity('telegram', (string) $wa['user_id']);
        $this->profile($tg['user_id'], 'approved');
        $draft = $this->store->createDraft($wa['user_id'], 'Text');
        $this->store->saveDraft($draft['id'], 1, ['status' => 'pending', 'subject' => 'Subject']);
        $broadcast = $this->store->approveDraft($draft['id'], 2, 77);
        self::assertSame(1, $broadcast['recipients']);
        self::assertSame(['email', 'whatsapp'], $this->deliveryChannels($broadcast['id']));
        $legacy = $this->store->user($wa['user_id']);
        $legacy['id'] = 900;
        $legacy['phone'] = '+12025550900';
        $legacy['email'] = 'legacy@example.test';
        $this->store->saveUser($legacy);
        self::assertSame(900, $this->store->identity('telegram', '900')['user_id']);
        self::assertGreaterThan(900, $this->store->ensureIdentity('telegram', '2')['user_id']);
    }

    public function testPendingAndUnapprovedBroadcastsCannotCatchupOrRetryUnknown(): void
    {
        [$target, $source] = $this->linkPair();
        $this->profile($source['user_id'], 'pending');
        $unapproved = $this->store->createBroadcast(77, 'Unapproved');
        $this->db->exec("UPDATE broadcasts SET status='ready'");
        self::assertFalse($this->store->addCatchup($target['user_id'], 'RU'));
        self::assertFalse($this->store->addCatchup($source['user_id'], 'RU'));
        $this->db->exec("UPDATE jobs SET status='failed'");
        self::assertFalse($this->store->retryBroadcast($unapproved['id']));
        $approved = $this->approvedBroadcast();
        $this->db->exec("UPDATE jobs SET status='unknown' WHERE broadcast_id=" . $approved['id']);
        self::assertFalse($this->store->retryBroadcast($approved['id']));
    }

    public function testMenusExpireAndTelegramFailureDoesNotDisableWhatsapp(): void
    {
        [$target, $source] = $this->linkPair();
        $link = $this->store->createLinkRequest($source['id'], $target['user_id'], 1000);
        $this->store->confirmLink($link['token'], $target['id'], 1001);
        $this->store->queueUserReply($target['user_id'], 'Menu', ['reply_markup' => ['keyboard' => [['Назад']]]]);
        self::assertSame([1 => 'Назад'], $this->store->menu($source['id'])['options']);
        $this->db->exec('UPDATE channel_menus SET expires_at=0');
        self::assertSame([], $this->store->menu($source['id']));
        $this->store->disableSubscriber($target['user_id']);
        self::assertSame(0, $this->store->identityById($target['id'])['enabled']);
        self::assertSame(1, $this->store->identityById($source['id'])['enabled']);
        self::assertSame('approved', $this->store->user($target['user_id'])['status']);
        self::assertTrue($this->store->unsubscribeUser($target['user_id']));
        self::assertFalse($this->store->addChannelCatchup($target['user_id'], 'RU', 'whatsapp'));
    }

    public function testResetDiscardsUnseenOldMessagesButStillAdvancesCursor(): void
    {
        $this->store->resetBusinessData(300);
        self::assertFalse($this->store->acceptChannelEvent('whatsapp', 'old-backlog', 100, 299));
        self::assertSame(100, $this->store->channelCursor('whatsapp'));
        self::assertFalse($this->store->acceptChannelEvent('whatsapp', 'same-second', 101, 300));
        self::assertTrue($this->store->acceptChannelEvent('whatsapp', 'fresh', 102, 301));
        self::assertFalse($this->store->acceptChannelEvent('whatsapp', 'old-backlog', 103, 301));
        self::assertTrue($this->store->acceptChannelEvent('whatsapp', 'receipt', 104));
    }

    public function testCommentOptionsHaveIndependentChannelJobsAndWhatsappMenu(): void
    {
        [$target, $source] = $this->linkPair();
        $request = $this->store->createLinkRequest($source['id'], $target['user_id'], 1000);
        $this->store->confirmLink($request['token'], $target['id'], 1001);
        $options = ['reply_markup' => ['inline_keyboard' => [[['text' => 'Исправить', 'callback_data' => 'reg:edit']]]]];
        $this->store->queueComment($target['user_id'], 'FR', 'Comment', 'Prefix', 77, $options);
        $jobs = $this->db->query("SELECT * FROM jobs WHERE kind='comment' ORDER BY channel")->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $jobs);
        self::assertSame($options, json_decode($jobs[0]['payload'], true)['options']);
        $wa = json_decode($jobs[1]['payload'], true);
        self::assertSame("\n\n1. Исправить", $wa['menu_suffix']);
        self::assertSame([], $wa['options']);
        self::assertSame([1 => 'reg:edit'], $this->store->menu($source['id'])['options']);
    }

    public function testVerifiedIncomingTelegramReenablesOnlyTransportAndNeverResubscribes(): void
    {
        [$target] = $this->linkPair();
        $this->store->disableSubscriber($target['user_id']);
        $identity = $this->store->ensureIdentity('telegram', $target['address']);
        self::assertSame(1, $identity['enabled']);
        self::assertSame(0, $this->store->user($target['user_id'])['subscribed']);
        $this->store->unsubscribeUser($target['user_id']);
        $this->store->ensureIdentity('telegram', $target['address']);
        self::assertSame('unsubscribed', $this->store->user($target['user_id'])['status']);
    }

    public function testProvenAliasConflictCanBeManuallyResolvedBeforeEitherProfileIsSubmitted(): void
    {
        $target = $this->store->ensureIdentity('whatsapp', '12025550100', '+12025550100');
        $source = $this->store->ensureIdentity('whatsapp', '998877@lid');
        $this->store->ensureIdentity('whatsapp', '998877@lid', '+12025550100');
        $request = $this->store->createLinkRequest($source['id'], $target['user_id'], 1000, true);
        self::assertNotNull($request);
        self::assertNotNull($this->store->resolveLink($request['id'], 77, true, 'Verified transport proof', 1001));
        self::assertSame('draft', $this->store->user($target['user_id'])['status']);
        self::assertNull($this->store->user($target['user_id'])['submitted_at']);
    }

    public function testTwoConnectionsReconcileReceiptRaceAndRestartWithoutResending(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'wa-durable-');
        $writer = $reader = null;
        try {
            $writer = new SqliteStore($path);
            $writer->migrate();
            $reader = new SqliteStore($path);
            $identity = $writer->ensureIdentity('whatsapp', '12025550100', '+12025550100');
            $writer->queueUserReply($identity['user_id'], 'One');
            $job = $writer->nextJob(100);
            self::assertTrue($writer->beginWhatsAppPart($job['id'], 0, 100));
            self::assertTrue($reader->recordWhatsAppReceipt('race-id', 'read', '12025550100'));
            $writer->completeWhatsAppPart($job['id'], 0, 'race-id', true);
            self::assertFalse($reader->recordWhatsAppReceipt('race-id', 'accepted', '12025550100'));
            $writer->queueUserReply($identity['user_id'], 'Two');
            $interrupted = $writer->nextJob(101);
            self::assertTrue($writer->beginWhatsAppPart($interrupted['id'], 0, 101));
            $writer->deferWhatsAppReceipt('late', 10, ['late-id'], 'delivered', '778899@lid');
            $writer->acceptChannelEvent('whatsapp', 'late', 10);
            $writer->close();
            $reader->close();
            $writer = new SqliteStore($path);
            self::assertSame([$interrupted['id']], $writer->recoverWhatsAppDispatches());
            self::assertSame([], $writer->recoverWhatsAppDispatches());
            self::assertNull($writer->nextJob(1000));
            self::assertSame(10, $writer->channelCursor('whatsapp'));
            self::assertSame(['late-id'], $writer->pendingWhatsAppReceipts()[0]['message_ids']);
            self::assertFalse($writer->acceptChannelEvent('whatsapp', 'late', 10));
            $database = (new \ReflectionProperty($writer, 'db'))->getValue($writer);
            self::assertSame('read', $database->query('SELECT status FROM jobs WHERE id=' . $job['id'])->fetchColumn());
            $database = null;
        } finally {
            $writer = $reader = null;
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                if (is_file($file)) { unlink($file); }
            }
        }
    }

    public function testPhotoCaptionIsTextForWhatsappAndUsesRealTelegramDestination(): void
    {
        [$target, $source] = $this->linkPair();
        $request = $this->store->createLinkRequest($source['id'], $target['user_id'], 1000);
        $this->store->confirmLink($request['token'], $target['id'], 1001);
        $this->store->queueUserPhoto($target['user_id'], 'Help');
        $jobs = $this->db->query('SELECT * FROM jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame('photo', $jobs[0]['kind']);
        self::assertSame($target['address'], json_decode($jobs[0]['payload'], true)['address']);
        self::assertSame('message', $jobs[1]['kind']);
        self::assertSame('whatsapp', $jobs[1]['channel']);
        self::assertSame('Help', json_decode($jobs[1]['payload'], true)['text']);
    }

    public function testDeferredReceiptWith101IdsSurvivesDatabaseReopenAndRetainsCursor(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'wa-receipt-batch-');
        $store = null;
        $messageIds = array_map(static fn (int $id): string => 'receipt-' . $id, range(1, 101));
        try {
            $store = new SqliteStore($path);
            $store->migrate();
            $store->transaction(function () use ($store, $messageIds): void {
                $store->deferWhatsAppReceipt('batch-101', 101, $messageIds, 'read', '998877@lid');
                self::assertTrue($store->acceptChannelEvent('whatsapp', 'batch-101', 101));
            });
            $store->close();
            $store = new SqliteStore($path);
            self::assertSame([[
                'event_id' => 'batch-101', 'sequence' => 101, 'message_ids' => $messageIds,
                'status' => 'read', 'address' => '998877@lid',
            ]], $store->pendingWhatsAppReceipts());
            self::assertSame(101, $store->channelCursor('whatsapp'));
            self::assertFalse($store->acceptChannelEvent('whatsapp', 'batch-101', 101));
        } finally {
            $store?->close();
            $store = null;
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                if (is_file($file)) { unlink($file); }
            }
        }
    }

    public function testDeferredReceiptLimitMatchesBoundedTransportBatch(): void
    {
        $messageIds = array_map(static fn (int $id): string => 'receipt-' . $id, range(1, 1000));
        $this->store->deferWhatsAppReceipt('batch-limit', 1, $messageIds, 'delivered', '998877@lid');
        self::assertSame($messageIds, $this->store->pendingWhatsAppReceipts()[0]['message_ids']);
        $this->expectException(\InvalidArgumentException::class);
        $this->store->deferWhatsAppReceipt('over-limit', 2, [...$messageIds, 'receipt-1001'], 'read', '998877@lid');
    }

    public function testManualMergeCanResubmitInheritedContactsWithoutReleasingHistoricalReservations(): void
    {
        [$target, $source] = $this->linkPair();
        $this->profile($source['user_id'], 'approved');
        $sourceUser = $this->store->user($source['user_id']);
        $reservations = $this->db->query('SELECT * FROM contacts ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC);
        $request = $this->store->createLinkRequest($source['id'], $target['user_id'], 1000, true);
        self::assertNotNull($this->store->resolveLink($request['id'], 77, true, 'Both accounts verified', 1001));
        self::assertFalse($this->store->contactTaken('phone', $sourceUser['phone'], $target['user_id']));
        self::assertNull($this->store->contactOwner('email', strtoupper($sourceUser['email']), $target['user_id']));
        self::assertSame($target['user_id'], $this->store->contactOwner('phone', $sourceUser['phone'], 0)['id']);
        self::assertTrue($this->store->unsubscribeUser($target['user_id']));
        $user = $this->store->user($target['user_id']);
        $user['phone'] = $sourceUser['phone'];
        $user['email'] = strtoupper($sourceUser['email']);
        $this->store->saveUser($user);
        self::assertTrue($this->store->submitApplication($target['user_id']));
        self::assertSame('pending', $this->store->user($target['user_id'])['status']);
        self::assertSame($sourceUser['phone'], $this->store->user($target['user_id'])['phone']);
        self::assertSame($reservations, $this->db->query('SELECT * FROM contacts ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC));
        foreach ($reservations as $reservation) {
            foreach (['phone', 'email'] as $field) {
                if ($reservation['user_id'] === $source['user_id']) {
                    self::assertTrue($this->store->contactTaken($field, $reservation[$field], 999));
                    self::assertSame($target['user_id'], $this->store->contactOwner($field, $reservation[$field], 999)['id']);
                } else {
                    self::assertFalse($this->store->contactTaken($field, $reservation[$field], 999));
                }
            }
        }
        $outsider = $this->store->ensureIdentity('telegram', '999');
        $outsiderUser = array_replace($this->store->user($outsider['user_id']), ['phone' => $sourceUser['phone'], 'email' => 'outsider@example.test']);
        $this->store->saveUser($outsiderUser);
        self::assertFalse($this->store->submitApplication($outsider['user_id']));
        self::assertSame('draft', $this->store->user($outsider['user_id'])['status']);
    }

    public function testReturnedPendingMergeRetainsInheritedPhoneAndReservesNewEmail(): void
    {
        $target = $this->store->ensureIdentity('telegram', '501');
        $source = $this->store->ensureIdentity('whatsapp', '12025550100', '+12025550100');
        $this->profile($target['user_id'], 'pending');
        $this->profile($source['user_id'], 'pending');
        $sourceUser = $this->store->user($source['user_id']);
        $targetPhone = $this->store->user($target['user_id'])['phone'];
        $sourceReservation = $this->db->query('SELECT * FROM contacts WHERE user_id=' . $source['user_id'])->fetch(PDO::FETCH_ASSOC);
        $request = $this->store->createLinkRequest($source['id'], $target['user_id'], 1000, true);
        self::assertNotNull($this->store->resolveLink($request['id'], 77, true, 'Both accounts verified', 1001));
        self::assertTrue($this->store->decideApplication($target['user_id'], $this->store->user($target['user_id'])['revision'], 'changes', 77));
        $user = array_replace($this->store->user($target['user_id']), ['phone' => $sourceUser['phone'], 'email' => 'new@example.test']);
        $this->store->saveUser($user);
        self::assertTrue($this->store->submitApplication($target['user_id']));
        self::assertSame($sourceReservation, $this->db->query('SELECT * FROM contacts WHERE user_id=' . $source['user_id'])->fetch(PDO::FETCH_ASSOC));
        $targetReservation = $this->db->query('SELECT * FROM contacts WHERE user_id=' . $target['user_id'])->fetch(PDO::FETCH_ASSOC);
        self::assertSame($targetPhone, $targetReservation['phone']);
        self::assertSame('new@example.test', $targetReservation['email']);
        self::assertTrue($this->store->contactTaken('email', 'NEW@example.test', 999));
        self::assertFalse($this->store->contactTaken('email', $sourceUser['email'], $target['user_id']));
    }

    public function testContactOwnershipFollowsApprovedLinkChainsAndFailsClosedOnCycles(): void
    {
        $first = $this->store->ensureIdentity('whatsapp', '998877@lid');
        $second = $this->store->ensureIdentity('whatsapp', '12025550100', '+12025550100');
        $third = $this->store->ensureIdentity('telegram', '501');
        foreach ([$first, $second, $third] as $identity) { $this->profile($identity['user_id'], 'pending'); }
        $phone = $this->store->user($first['user_id'])['phone'];
        $this->store->ensureIdentity('whatsapp', '998877@lid', '+12025550100');
        $a = $this->store->createLinkRequest($first['id'], $second['user_id'], 1000, true);
        self::assertNotNull($this->store->resolveLink($a['id'], 77, true, 'Verified alias', 1001));
        $b = $this->store->createLinkRequest($second['id'], $third['user_id'], 1002, true);
        self::assertNotNull($this->store->resolveLink($b['id'], 77, true, 'Verified accounts', 1003));
        self::assertSame($third['user_id'], $this->store->contactOwner('phone', $phone, 0)['id']);
        self::assertFalse($this->store->contactTaken('phone', $phone, $third['user_id']));
        self::assertNull($this->store->contactOwner('phone', $phone, $second['user_id']));
        $this->db->exec('UPDATE link_requests SET target_user_id=' . $first['user_id'] . ' WHERE id=' . $b['id']);
        self::assertTrue($this->store->contactTaken('phone', $phone, $third['user_id']));
        self::assertNull($this->store->contactOwner('phone', $phone, 0));
    }

    public function testDraftAliasTargetCanSubmitInheritedPhoneAndNewEmailWithIndependentReservations(): void
    {
        $source = $this->store->ensureIdentity('whatsapp', '998877@lid');
        $target = $this->store->ensureIdentity('whatsapp', '12025550100', '+12025550100');
        $this->profile($source['user_id'], 'pending');
        $sourceUser = $this->store->user($source['user_id']);
        $sourceContact = $this->db->query('SELECT * FROM contacts WHERE user_id=' . $source['user_id'])->fetch(PDO::FETCH_ASSOC);
        $this->store->ensureIdentity('whatsapp', '998877@lid', '+12025550100');
        $request = $this->store->createLinkRequest($source['id'], $target['user_id'], 1000, true);
        self::assertNotNull($this->store->resolveLink($request['id'], 77, true, 'Verified alias', 1001));
        $user = array_replace($this->store->user($target['user_id']), ['phone' => $sourceUser['phone'], 'email' => 'new-target@example.test']);
        $this->store->saveUser($user);
        self::assertTrue($this->store->submitApplication($target['user_id']));
        self::assertSame('pending', $this->store->user($target['user_id'])['status']);
        self::assertSame($sourceContact, $this->db->query('SELECT * FROM contacts WHERE user_id=' . $source['user_id'])->fetch(PDO::FETCH_ASSOC));
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM contacts WHERE user_id=' . $target['user_id'])->fetchColumn());
        foreach (['phone' => $sourceUser['phone'], 'email' => 'new-target@example.test'] as $field => $value) {
            self::assertTrue($this->store->contactTaken($field, $value, 999));
            self::assertSame($target['user_id'], $this->store->contactOwner($field, $value, 999)['id']);
            self::assertFalse($this->store->contactTaken($field, $value, $target['user_id']));
        }
        self::assertTrue($this->store->contactTaken('email', $sourceUser['email'], 999));
    }

    public function testOrdinaryCorrectionReleasesOldContactsAndReservesNewValuesAtomically(): void
    {
        $identity = $this->store->ensureIdentity('telegram', '501');
        $this->profile($identity['user_id'], 'pending');
        $old = $this->store->user($identity['user_id']);
        self::assertTrue($this->store->decideApplication($identity['user_id'], $old['revision'], 'changes', 77));
        $new = array_replace($this->store->user($identity['user_id']), ['phone' => '+12025550999', 'email' => 'changed@example.test']);
        $this->store->saveUser($new);
        self::assertTrue($this->store->submitApplication($identity['user_id']));
        foreach (['phone', 'email'] as $field) {
            self::assertFalse($this->store->contactTaken($field, $old[$field], 999));
            self::assertTrue($this->store->contactTaken($field, $new[$field], 999));
        }
        self::assertSame($new['phone'], $this->db->query('SELECT phone FROM contacts WHERE user_id=' . $identity['user_id'])->fetchColumn());
        self::assertSame($new['email'], $this->db->query('SELECT email FROM contacts WHERE user_id=' . $identity['user_id'])->fetchColumn());
    }

    public function testReleasedLiveReservationCanBeReusedEvenWhenLegacyRowRetainsItsOldField(): void
    {
        [$target, $source] = $this->linkPair();
        $this->profile($source['user_id'], 'pending');
        $oldTarget = $this->store->user($target['user_id']);
        $sourceUser = $this->store->user($source['user_id']);
        $request = $this->store->createLinkRequest($source['id'], $target['user_id'], 1000, true);
        self::assertNotNull($this->store->resolveLink($request['id'], 77, true, 'Verified accounts', 1001));
        self::assertTrue($this->store->unsubscribeUser($target['user_id']));
        $user = array_replace($this->store->user($target['user_id']), ['phone' => $sourceUser['phone']]);
        $this->store->saveUser($user);
        self::assertTrue($this->store->submitApplication($target['user_id']));
        self::assertSame($oldTarget['phone'], $this->db->query('SELECT phone FROM contacts WHERE user_id=' . $target['user_id'])->fetchColumn());
        $newPerson = $this->store->ensureIdentity('telegram', '777');
        $newProfile = array_replace($this->store->user($newPerson['user_id']), ['phone' => $oldTarget['phone'], 'email' => 'new-person@example.test']);
        $this->store->saveUser($newProfile);
        self::assertTrue($this->store->submitApplication($newPerson['user_id']));
        self::assertSame($newPerson['user_id'], $this->store->contactOwner('phone', $oldTarget['phone'], 0)['id']);
        self::assertTrue($this->store->contactTaken('phone', $oldTarget['phone'], $target['user_id']));
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM contacts WHERE user_id=' . $newPerson['user_id'])->fetchColumn());
    }

    public function testFailedSubmissionRollsBackReservationChangesAndKeepsPreviousApplication(): void
    {
        $identity = $this->store->ensureIdentity('telegram', '501');
        $this->profile($identity['user_id'], 'pending');
        $previous = $this->store->user($identity['user_id']);
        self::assertTrue($this->store->decideApplication($identity['user_id'], $previous['revision'], 'changes', 77));
        $new = array_replace($this->store->user($identity['user_id']), ['phone' => '+12025550999', 'email' => 'changed@example.test']);
        $this->store->saveUser($new);
        $this->db->exec("CREATE TRIGGER reject_reservation_update BEFORE UPDATE ON contacts BEGIN SELECT RAISE(ABORT,'test'); END");
        try {
            $this->store->submitApplication($identity['user_id']);
            self::fail('Injected persistence error must abort submission.');
        } catch (\PDOException) {}
        self::assertSame('changes', $this->store->user($identity['user_id'])['status']);
        foreach (['phone', 'email'] as $field) {
            self::assertTrue($this->store->contactTaken($field, $previous[$field], 999));
            self::assertFalse($this->store->contactTaken($field, $new[$field], 999));
        }
    }

    public function testExportUsesExplicitTransportIdentitiesAndNeverInfersTelegramFromPersonId(): void
    {
        [$target, $source] = $this->linkPair();
        $this->profile($source['user_id'], 'pending');
        $before = array_column($this->store->exportUsers(), null, 'id');
        self::assertArrayHasKey('telegram_id', $before[$source['user_id']]);
        self::assertNull($before[$source['user_id']]['telegram_id']);
        self::assertSame('12025550100', $before[$source['user_id']]['whatsapp_id']);
        self::assertSame('+12025550100', $before[$source['user_id']]['whatsapp_verified_phone']);
        self::assertNull($before[$target['user_id']]['whatsapp_id']);
        $request = $this->store->createLinkRequest($source['id'], $target['user_id'], 1000, true);
        self::assertNotNull($this->store->resolveLink($request['id'], 77, true, 'Verified accounts', 1001));
        $after = array_column($this->store->exportUsers(), null, 'id');
        self::assertSame('501', $after[$target['user_id']]['telegram_id']);
        self::assertNotSame((string) $target['user_id'], $after[$target['user_id']]['telegram_id']);
        self::assertSame('12025550100', $after[$target['user_id']]['whatsapp_id']);
        self::assertSame('+12025550100', $after[$target['user_id']]['whatsapp_verified_phone']);
        self::assertNull($after[$source['user_id']]['telegram_id']);
        self::assertNull($after[$source['user_id']]['whatsapp_id']);
    }

    private function linkPair(): array
    {
        $target = $this->store->ensureIdentity('telegram', '501');
        $source = $this->store->ensureIdentity('whatsapp', '12025550100', '+12025550100');
        $this->profile($target['user_id'], 'approved');
        return [$target, $source];
    }

    private function profile(int $id, string $status): void
    {
        $user = $this->store->user($id);
        $user = array_replace($user, ['name' => 'Test','company' => 'Team','phone' => '+1202555' . str_pad((string) $id, 4, '0', STR_PAD_LEFT),'email' => 'user' . $id . '@example.test','status' => 'draft','language' => 'RU']);
        $this->store->saveUser($user);
        self::assertTrue($this->store->submitApplication($id));
        if ($status === 'approved') {
            self::assertTrue($this->store->decideApplication($id, $this->store->user($id)['revision'], 'approved', 77));
        }
    }

    private function approvedBroadcast(): array
    {
        $draft = $this->store->createDraft(77, 'Announcement');
        $this->store->saveDraft($draft['id'], $draft['version'], ['subject' => 'Subject','status' => 'pending']);
        return $this->store->approveDraft($draft['id'], $draft['version'] + 1, 77);
    }

    private function deliveryChannels(int $id): array
    {
        return $this->db->query("SELECT channel FROM jobs WHERE kind='delivery' AND broadcast_id=$id ORDER BY channel")->fetchAll(PDO::FETCH_COLUMN);
    }

    private function whatsappJob(): array
    {
        $wa = $this->store->ensureIdentity('whatsapp', '12025550100', '+12025550100');
        $this->store->queueUserReply($wa['user_id'], 'Text');
        return $this->store->nextJob(time());
    }

    private function jobRow(int $id): array
    {
        return $this->db->query('SELECT * FROM jobs WHERE id=' . $id)->fetch(PDO::FETCH_ASSOC);
    }
}
