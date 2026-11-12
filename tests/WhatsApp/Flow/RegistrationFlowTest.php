<?php
declare(strict_types=1);

namespace Broadcast\Tests\WhatsApp\Flow;

use Broadcast\Application\Kernel;
use Broadcast\Application\Messages;
use Broadcast\Infrastructure\SqliteStore;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegistrationFlowTest extends TestCase
{
    private SqliteStore $store;
    private Kernel $kernel;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->store = new SqliteStore(':memory:');
        $this->store->migrate();
        $this->kernel = new Kernel($this->store, [99], [100]);
    }

    private function wa(string $body, string $address = '442079460018', ?string $phone = '+442079460018', array $extra = []): array
    {
        $event = array_replace(['event_id' => 'event-' . ++$this->sequence, 'sequence' => $this->sequence,
            'message_id' => 'message-' . $this->sequence, 'address' => $address, 'verified_phone' => $phone,
            'body' => $body, 'from_me' => false, 'is_group' => false, 'created_at' => time()], $extra);
        $this->kernel->handleWhatsApp($event);
        return $event;
    }

    private function tg(int $actor, string $text, bool $callback = false): void
    {
        $message = ['from' => ['id' => $actor], 'chat' => ['id' => $actor, 'type' => 'private'], 'text' => $text];
        $update = ['update_id' => ++$this->sequence];
        if ($callback) {
            $update['callback_query'] = ['id' => (string) $this->sequence, 'from' => ['id' => $actor], 'message' => $message, 'data' => $text];
        } else {
            $update['message'] = $message;
        }
        $this->kernel->handle($update);
    }

    private function db(): PDO
    {
        return (new \ReflectionProperty($this->store, 'db'))->getValue($this->store);
    }

    private function jobs(): array
    {
        return array_map(static function (array $job): array {
            $job['payload'] = json_decode($job['payload'], true, flags: JSON_THROW_ON_ERROR);
            return $job;
        }, $this->db()->query('SELECT * FROM jobs ORDER BY id')->fetchAll());
    }

    private function person(string $channel = 'whatsapp', string $address = '442079460018'): array
    {
        return $this->store->user($this->store->identity($channel, $address)['user_id']);
    }

    private function registerWhatsApp(string $language = 'EN-GB', string $address = '442079460018', ?string $phone = '+442079460018'): array
    {
        $say = fn (string $body) => $this->wa($body, $address, $phone);
        $say('/start');
        $say((string) (array_search($language, array_keys(Messages::LANGUAGES), true) + 1));
        $say('1');
        $say('Example Name');
        $say('Example Company');
        if ($phone === null) { $say('+442079460018'); }
        $say('1');
        $say('example@example.com');
        return $this->person('whatsapp', $address);
    }

    public static function languages(): array
    {
        return [['RU'], ['EN-GB'], ['ES'], ['FR']];
    }

    #[DataProvider('languages')]
    public function testWhatsAppUsesExistingRegistrationAndNumericMenus(string $language): void
    {
        $user = $this->registerWhatsApp($language);
        self::assertSame('review', $user['step']);
        self::assertSame('+442079460018', $user['phone']);
        self::assertSame($language, $user['language']);
        self::assertSame('draft', $user['status']);
        $this->wa('1');
        $user = $this->person();
        self::assertSame('pending', $user['status']);
        self::assertSame(0, $user['subscribed']);
        $jobs = $this->jobs();
        $cards = array_filter($jobs, static fn ($job) => $job['user_id'] === 100 && str_contains($job['payload']['text'] ?? '', 'Заявка #'));
        self::assertCount(1, $cards);
        self::assertStringContainsString('WhatsApp', array_values($cards)[0]['payload']['text']);
        self::assertSame([], array_filter($jobs, static fn ($job) => $job['kind'] === 'delivery'));
        $baseline = json_decode(file_get_contents(__DIR__ . '/original-ui.json'), true, flags: JSON_THROW_ON_ERROR);
        $texts = implode("\n", array_map(static fn ($job) => $job['payload']['text'] ?? '', $jobs));
        $index = array_search($language, array_keys(Messages::LANGUAGES), true);
        foreach (['name', 'company', 'email', 'submitted'] as $key) {
            self::assertStringContainsString($baseline[$key][$index], $texts);
        }
        self::assertStringNotContainsString($baseline['phone'][$index], $texts);
    }

    public function testExistingTextValuesRemainByteIdentical(): void
    {
        $baseline = json_decode(file_get_contents(__DIR__ . '/original-ui.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($baseline as $key => $values) {
            foreach (array_keys(Messages::LANGUAGES) as $index => $language) {
                self::assertSame($values[$index], Messages::text($language, $key), $key . ':' . $language);
            }
        }
    }

    public function testVerifiedPhoneCannotBeEditedAndNavigationReturnsToCompany(): void
    {
        $this->wa('/start'); $this->wa('2'); $this->wa('1'); $this->wa('Name'); $this->wa('Company');
        $this->wa('3');
        self::assertSame('company', $this->person()['step']);
        $this->wa('Changed company'); $this->wa('1'); $this->wa('a@example.com');
        $identity = $this->store->identity('whatsapp', '442079460018');
        (new \Broadcast\Application\Registration($this->store, [100]))->callback($identity['user_id'], 'reg:field:phone', $identity['id']);
        self::assertSame('review', $this->person()['step']);
        self::assertSame('+442079460018', $this->person()['phone']);
        $this->wa('2'); $this->wa('1'); $this->wa('Changed Name');
        self::assertSame('review', $this->person()['step']);
        self::assertSame('Changed Name', $this->person()['name']);
    }

    public function testUnknownLidAsksForPhoneWithoutTreatingLidAsPhone(): void
    {
        $say = fn ($body) => $this->wa($body, '123456789012345@lid', null);
        $say('/start'); $say('2'); $say('1'); $say('Name'); $say('Company');
        self::assertSame('phone', $this->person('whatsapp', '123456789012345@lid')['step']);
        $menu = $this->store->menu($this->store->identity('whatsapp', '123456789012345@lid')['id']);
        self::assertSame([1 => '⬅️ Back'], $menu['options']);
        $say('+442079460018');
        self::assertNull($this->store->identity('whatsapp', '123456789012345@lid')['verified_phone']);
        self::assertSame('country_confirm', $this->person('whatsapp', '123456789012345@lid')['step']);
    }

    public function testDuplicateEventsAndMalformedOrNonPrivateEventsHaveNoEffects(): void
    {
        $event = $this->wa('/start');
        $count = count($this->jobs());
        $this->kernel->handleWhatsApp($event);
        self::assertCount($count, $this->jobs());
        $this->wa('/start', '442079460018', '+442079460018', ['message_id' => $event['message_id']]);
        self::assertCount($count, $this->jobs());
        foreach ([['from_me' => true], ['is_group' => true], ['sequence' => '4'], ['body' => []], ['address' => '-100'], ['verified_phone' => null], ['verified_phone' => '+12025550123']] as $extra) {
            $this->wa('/start', '442079460018', '+442079460018', $extra);
        }
        self::assertCount($count, $this->jobs());
    }

    public function testWhatsAppCannotObtainStaffPowersAndTelegramActorIsNotPersonId(): void
    {
        $this->kernel = new Kernel($this->store, [99, 442079460018], [100]);
        $this->wa('Create announcement');
        self::assertNull($this->store->draft(1));
        $this->tg(99, 'Real staff announcement');
        self::assertSame(99, $this->store->draft(1)['admin_id']);
        $this->tg(99, 'Subject');
        $this->tg(99, 'draft:submit:1:' . $this->store->draft(1)['version'], true);
        $this->tg(100, 'draft:approve:1:' . $this->store->draft(1)['version'], true);
        self::assertSame(100, $this->store->broadcast(1)['approved_by']);
    }

    public function testStaffReviewRoutesApprovalReturnRejectToWhatsApp(): void
    {
        $this->registerWhatsApp(); $this->wa('1');
        $user = $this->person();
        $this->tg(99, 'app:approve:' . $user['id'] . ':' . $user['revision'], true);
        self::assertSame('pending', $this->person()['status']);
        $this->tg(100, 'app:changes:' . $user['id'] . ':' . $user['revision'], true);
        $this->tg(100, 'Please correct company');
        self::assertSame('changes', $this->person()['status']);
        $comments = array_values(array_filter($this->jobs(), static fn ($job) => $job['kind'] === 'comment'));
        self::assertCount(1, $comments);
        self::assertSame('whatsapp', $comments[0]['channel']);
        $this->wa('/start'); $this->wa('1');
        $user = $this->person();
        $this->tg(100, 'app:reject:' . $user['id'] . ':' . $user['revision'], true);
        $this->tg(100, 'Cannot approve yet');
        self::assertSame('rejected', $this->person()['status']);
        $user = $this->person();
        $this->tg(100, 'app:reopen:' . $user['id'] . ':' . $user['revision'], true);
        $this->wa('/start'); $this->wa('1');
        $user = $this->person();
        $this->tg(100, 'app:approve:' . $user['id'] . ':' . $user['revision'], true);
        self::assertSame('approved', $this->person()['status']);
        $messages = array_values(array_filter($this->jobs(), static fn ($job) => $job['kind'] === 'message' && $job['channel'] === 'whatsapp'));
        self::assertContains(Messages::text('EN-GB', 'approved', $user), array_column(array_column($messages, 'payload'), 'text'));
        self::assertContains('Channels: WhatsApp, email', array_column(array_column($messages, 'payload'), 'text'));
    }

    public function testLanguageAndStopWordsChangeSharedPerson(): void
    {
        $this->registerWhatsApp(); $this->wa('1');
        $user = $this->person();
        $this->tg(100, 'app:approve:' . $user['id'] . ':' . $user['revision'], true);
        $this->wa('/language'); $this->wa('4');
        self::assertSame('FR', $this->person()['language']);
        $this->wa('Arrêtez de m’envoyer des messages');
        self::assertSame('unsubscribed', $this->person()['status']);
        self::assertSame(0, $this->person()['subscribed']);
    }

    private function submittedTarget(string $channel, string $address, string $phone = '+442079460018', string $email = 'target@example.com'): array
    {
        $identity = $this->store->ensureIdentity($channel, $address, $channel === 'whatsapp' ? $phone : null);
        $user = $this->store->user($identity['user_id']);
        $this->store->saveUser(array_replace($user, ['language' => 'EN-GB', 'step' => 'review', 'choosing_language' => 0,
            'name' => 'Private Target Name', 'company' => 'Private Target Company', 'country' => 'United Kingdom', 'phone' => $phone, 'email' => $email]));
        self::assertTrue($this->store->submitApplication($user['id']));
        $user = $this->store->user($user['id']);
        self::assertTrue($this->store->decideApplication($user['id'], $user['revision'], 'approved', 100));
        return $identity;
    }

    private function linkToken(): string
    {
        foreach (array_reverse($this->jobs()) as $job) {
            foreach ($job['payload']['options']['reply_markup']['inline_keyboard'] ?? [] as $row) {
                foreach ($row as $button) {
                    if (str_starts_with($button['callback_data'] ?? '', 'link:confirm:')) {
                        self::assertLessThanOrEqual(64, strlen($button['callback_data']));
                        return substr($button['callback_data'], 13);
                    }
                }
            }
        }
        self::fail('No confirmation sent to the original messenger.');
    }

    public function testWhatsAppLinkRequiresOriginalTelegramIdentityAndDoesNotDiscloseTarget(): void
    {
        $target = $this->submittedTarget('telegram', '501');
        $this->wa('/start'); $this->wa('2'); $this->wa('1'); $this->wa('New'); $this->wa('Company');
        $source = $this->store->identity('whatsapp', '442079460018');
        $this->wa('/link');
        self::assertNotSame($target['user_id'], $source['user_id']);
        $token = $this->linkToken();
        foreach ($this->jobs() as $job) {
            if ($job['channel'] === 'whatsapp') {
                self::assertStringNotContainsString('Private Target', json_encode($job['payload']));
                self::assertStringNotContainsString('target@example.com', json_encode($job['payload']));
                self::assertStringNotContainsString($token, json_encode($job['payload']));
            }
        }
        $this->tg(777, '/start');
        $this->tg(777, 'link:confirm:' . $token, true);
        self::assertSame($source['user_id'], $this->store->identity('whatsapp', '442079460018')['user_id']);
        $this->tg(501, 'link:confirm:' . $token, true);
        self::assertSame($target['user_id'], $this->store->identity('whatsapp', '442079460018')['user_id']);
        self::assertSame('approved', $this->store->user($target['user_id'])['status']);
        $count = count($this->jobs());
        $this->tg(501, 'link:confirm:' . $token, true);
        self::assertSame($count + 2, count($this->jobs()));
        self::assertCount(2, $this->store->identities($target['user_id']));
    }

    private function requestTelegramLink(int $actor): array
    {
        $this->tg($actor, '/start'); $this->tg($actor, 'language:EN-GB', true); $this->tg($actor, 'reg:begin', true);
        $this->tg($actor, 'New'); $this->tg($actor, 'Company'); $this->tg($actor, '+442079460018'); $this->tg($actor, '/link');
        return $this->store->identity('telegram', (string) $actor);
    }

    private function whatsAppConfirmation(): string
    {
        foreach (array_reverse($this->jobs()) as $job) {
            if ($job['channel'] === 'whatsapp' && preg_match('/\/confirm [A-Za-z0-9_-]{43}(?![A-Za-z0-9_-])/', $job['payload']['text'] ?? '', $matches)) {
                return $matches[0];
            }
        }
        self::fail('The original WhatsApp account did not receive a unique confirmation command.');
    }

    public function testTelegramLinkConfirmsViaUniqueWhatsAppCommand(): void
    {
        $target = $this->submittedTarget('whatsapp', '442079460018');
        $source = $this->requestTelegramLink(501);
        self::assertNotSame($source['user_id'], $target['user_id']);
        self::assertSame([], $this->store->menu($target['id']));
        $this->wa($this->whatsAppConfirmation());
        self::assertSame($target['user_id'], $this->store->identity('telegram', '501')['user_id']);
        self::assertCount(2, $this->store->identities($target['user_id']));
        $this->tg(501, '/language'); $this->tg(501, 'language:ES', true);
        self::assertSame('ES', $this->person()['language']);
    }

    public function testNumericReplyCannotConfirmEitherOfTwoWhatsAppLinkRequests(): void
    {
        $target = $this->submittedTarget('whatsapp', '442079460018');
        $first = $this->requestTelegramLink(501);
        $second = $this->requestTelegramLink(502);
        self::assertCount(2, $this->store->pendingLinks());
        $this->wa('1');
        self::assertSame($first['user_id'], $this->store->identity('telegram', '501')['user_id']);
        self::assertSame($second['user_id'], $this->store->identity('telegram', '502')['user_id']);
        self::assertCount(1, $this->store->identities($target['user_id']));
    }

    public function testEarlierWhatsAppCommandConfirmsOnlyItsOwnRequest(): void
    {
        $target = $this->submittedTarget('whatsapp', '442079460018');
        $first = $this->requestTelegramLink(501);
        $firstCommand = $this->whatsAppConfirmation();
        $second = $this->requestTelegramLink(502);
        $secondCommand = $this->whatsAppConfirmation();
        self::assertNotSame($firstCommand, $secondCommand);
        self::assertCount(2, $this->store->pendingLinks());
        $this->wa($firstCommand);
        self::assertSame($target['user_id'], $this->store->identity('telegram', '501')['user_id']);
        self::assertSame($second['user_id'], $this->store->identity('telegram', '502')['user_id']);
        $this->wa($secondCommand);
        self::assertSame($second['user_id'], $this->store->identity('telegram', '502')['user_id']);
    }

    public function testExpiredWhatsAppCommandCannotConfirmReplacementRequest(): void
    {
        $target = $this->submittedTarget('whatsapp', '442079460018');
        $first = $this->requestTelegramLink(501);
        $expiredCommand = $this->whatsAppConfirmation();
        $this->db()->exec('UPDATE link_requests SET expires_at=1');
        $second = $this->requestTelegramLink(502);
        $currentCommand = $this->whatsAppConfirmation();
        self::assertNotSame($expiredCommand, $currentCommand);
        $this->wa($expiredCommand);
        $this->wa('1');
        self::assertSame($first['user_id'], $this->store->identity('telegram', '501')['user_id']);
        self::assertSame($second['user_id'], $this->store->identity('telegram', '502')['user_id']);
        $this->wa($currentCommand);
        self::assertSame($target['user_id'], $this->store->identity('telegram', '502')['user_id']);
    }

    public function testLegacyWhatsAppNumericConfirmationAndForeignCommandAreRejected(): void
    {
        $target = $this->submittedTarget('whatsapp', '442079460018');
        $source = $this->requestTelegramLink(501);
        $command = $this->whatsAppConfirmation();
        $token = substr($command, strlen('/confirm '));
        $this->store->queueUserReply($target['user_id'], 'Legacy confirmation', ['reply_markup' => ['inline_keyboard' => [[[
            'text' => 'Confirm', 'callback_data' => 'link:confirm:' . $token,
        ]]]]], $target['id']);
        $this->wa('1');
        self::assertSame($source['user_id'], $this->store->identity('telegram', '501')['user_id']);
        $this->wa($command, '12025550123', '+12025550123');
        self::assertSame($source['user_id'], $this->store->identity('telegram', '501')['user_id']);
        $this->wa($command);
        self::assertSame($target['user_id'], $this->store->identity('telegram', '501')['user_id']);
    }

    public function testEmailOnlyMatchAndUnknownLidRequireAuditedSuperadminResolution(): void
    {
        $target = $this->submittedTarget('telegram', '501', '+12025550123');
        $this->registerWhatsApp();
        $this->wa('2'); $this->wa('4'); $this->wa('target@example.com');
        $this->wa('/link');
        $pending = $this->store->pendingLinks();
        self::assertCount(1, $pending);
        self::assertSame(1, (int) $pending[0]['manual']);
        $id = $pending[0]['id'];
        $this->tg(99, '/linkresolve ' . $id . ' approve verified out of band');
        self::assertNotSame($target['user_id'], $this->person()['id']);
        $this->tg(100, '/linkresolve ' . $id . ' approve');
        self::assertNotSame($target['user_id'], $this->person()['id']);
        $this->tg(100, '/links');
        $this->tg(100, '/linkresolve ' . $id . ' approve verified out of band');
        self::assertSame($target['user_id'], $this->person()['id']);
        $request = $this->store->linkRequest($id);
        self::assertNotSame('pending', $request['status']);
    }

    public function testUnmappedLidMatchingPhoneNeverReceivesAutomaticConfirmation(): void
    {
        $target = $this->submittedTarget('telegram', '501');
        $say = fn ($body) => $this->wa($body, '123456789012345@lid', null);
        $say('/start'); $say('2'); $say('1'); $say('New'); $say('Company'); $say('+442079460018'); $say('/link');
        $pending = $this->store->pendingLinks();
        self::assertCount(1, $pending);
        self::assertSame(1, (int) $pending[0]['manual']);
        self::assertNotSame($target['user_id'], $this->person('whatsapp', '123456789012345@lid')['id']);
        foreach ($this->jobs() as $job) {
            self::assertStringNotContainsString('link:confirm:', json_encode($job['payload']));
        }
    }

    public function testExpiredWhatsAppMenuCannotExecuteOldChoice(): void
    {
        $this->wa('/start');
        $this->db()->exec('UPDATE channel_menus SET expires_at=1');
        $this->wa('2');
        self::assertSame('RU', $this->person()['language']);
        self::assertSame('language', $this->person()['step']);
        $this->wa('2');
        self::assertSame('EN-GB', $this->person()['language']);
    }

    public function testLinkHelpEscalatesExistingConfirmationAndInvalidatesOldToken(): void
    {
        $target = $this->submittedTarget('telegram', '501');
        $this->wa('/start'); $this->wa('2'); $this->wa('1'); $this->wa('New'); $this->wa('Company'); $this->wa('/link');
        $token = $this->linkToken();
        $this->wa('/linkhelp');
        $requests = $this->store->pendingLinks();
        self::assertCount(1, $requests);
        self::assertSame(1, (int) $requests[0]['manual']);
        $this->tg(501, 'link:confirm:' . $token, true);
        self::assertNotSame($target['user_id'], $this->person()['id']);
        $this->tg(100, '/links');
        $staffTexts = implode("\n", array_map(static fn ($job) => $job['user_id'] === 100 ? ($job['payload']['text'] ?? '') : '', $this->jobs()));
        self::assertStringContainsString('New', $staffTexts);
        $this->tg(100, '/linkresolve ' . $requests[0]['id'] . ' reject ownership not established');
        self::assertSame('rejected', $this->store->linkRequest($requests[0]['id'])['status']);
        self::assertSame(100, $this->store->linkRequest($requests[0]['id'])['actor']);
        self::assertNotSame($target['user_id'], $this->person()['id']);
    }

    public function testLidMappingUsesSameIdentityAndVerifiedPhoneBeforeSubmission(): void
    {
        $address = '123456789012345@lid';
        $say = fn ($body) => $this->wa($body, $address, null);
        $say('/start'); $say('2'); $say('1'); $say('Name'); $say('Company');
        $say('+12025550123'); $say('1'); $say('a@example.com');
        $original = $this->store->identity('whatsapp', $address);
        $this->wa('1', $address, '+442079460018');
        $identity = $this->store->identity('whatsapp', '442079460018');
        self::assertSame($original['id'], $identity['id']);
        self::assertSame($original['user_id'], $identity['user_id']);
        self::assertSame('draft', $this->person()['status']);
        self::assertSame('+442079460018', $this->person()['phone']);
        self::assertSame('country_confirm', $this->person()['step']);
    }

    public function testVerifiedAliasConflictRequiresAuditedResolution(): void
    {
        $target = $this->submittedTarget('whatsapp', '442079460018');
        $this->wa('/start', '123456789012345@lid', null);
        $source = $this->store->identity('whatsapp', '123456789012345@lid');
        $before = $this->store->user($source['user_id']);
        $this->wa('2', '123456789012345@lid', '+442079460018');
        self::assertSame($before, $this->store->user($source['user_id']));
        $pending = $this->store->pendingLinks();
        self::assertCount(1, $pending);
        self::assertSame(1, (int) $pending[0]['manual']);
        self::assertSame($target['user_id'], $pending[0]['target_user_id']);
        $this->tg(100, '/linkresolve ' . $pending[0]['id'] . ' approve verified transport account evidence');
        self::assertSame($target['user_id'], $this->store->identity('whatsapp', '123456789012345@lid')['user_id']);
    }

    public function testStopDuringProvenAliasConflictStopsBothProfilesWithoutMerging(): void
    {
        $target = $this->submittedTarget('whatsapp', '442079460018');
        $this->wa('/start', '123456789012345@lid', null);
        $source = $this->store->identity('whatsapp', '123456789012345@lid');
        $user = $this->store->user($source['user_id']);
        $this->store->saveUser(array_replace($user, ['step' => 'review', 'name' => 'Source Name', 'company' => 'Source Company',
            'country' => 'United States', 'phone' => '+12025550123', 'email' => 'source@example.com', 'choosing_language' => 0]));
        $this->store->submitApplication($user['id']);
        $this->store->decideApplication($user['id'], $this->store->user($user['id'])['revision'], 'approved', 100);
        $this->wa('/stop', '123456789012345@lid', '+442079460018');
        self::assertSame('unsubscribed', $this->store->user($target['user_id'])['status']);
        self::assertSame('unsubscribed', $this->store->user($source['user_id'])['status']);
        self::assertNotSame($this->store->identity('whatsapp', '123456789012345@lid')['user_id'], $this->store->identity('whatsapp', '442079460018')['user_id']);
        self::assertSame([], $this->store->pendingLinks());
    }

    public function testSharedContactChecksTelegramAddressNotInternalPersonId(): void
    {
        $this->wa('/start');
        $this->tg(501, '/start'); $this->tg(501, 'language:EN-GB', true); $this->tg(501, 'reg:begin', true);
        $this->tg(501, 'Name'); $this->tg(501, 'Company');
        $identity = $this->store->identity('telegram', '501');
        self::assertNotSame(501, $identity['user_id']);
        $this->kernel->handle(['update_id' => ++$this->sequence, 'message' => ['from' => ['id' => 501], 'chat' => ['id' => 501, 'type' => 'private'],
            'contact' => ['user_id' => $identity['user_id'], 'phone_number' => '12025550123']]]);
        self::assertSame('', $this->store->user($identity['user_id'])['phone']);
        $this->kernel->handle(['update_id' => ++$this->sequence, 'message' => ['from' => ['id' => 501], 'chat' => ['id' => 501, 'type' => 'private'],
            'contact' => ['user_id' => 501, 'phone_number' => '12025550123']]]);
        self::assertSame('+12025550123', $this->store->user($identity['user_id'])['phone']);
    }

    public function testUnknownTelegramHelpDoesNotAllocatePerson(): void
    {
        $this->tg(501, '/help');
        self::assertNull($this->store->identity('telegram', '501'));
        self::assertSame(0, (int) $this->db()->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $jobs = $this->jobs();
        self::assertCount(1, $jobs);
        self::assertSame('photo', $jobs[0]['kind']);
        self::assertSame(501, $jobs[0]['user_id']);
        $this->tg(502, 'Hello');
        self::assertNull($this->store->identity('telegram', '502'));
    }

    public function testPendingApplicationLinkStillRequiresCommonApproval(): void
    {
        $target = $this->submittedTarget('telegram', '501');
        $profile = $this->store->user($target['user_id']);
        $profile['status'] = 'pending';
        $profile['subscribed'] = 0;
        $this->store->saveUser($profile);
        $this->wa('/start'); $this->wa('2'); $this->wa('1'); $this->wa('New'); $this->wa('Company'); $this->wa('/link');
        $this->tg(501, 'link:confirm:' . $this->linkToken(), true);
        self::assertSame($target['user_id'], $this->person()['id']);
        self::assertSame('pending', $this->person()['status']);
        self::assertCount(0, array_filter($this->jobs(), static fn ($job) => $job['kind'] === 'delivery'));
        $this->tg(100, 'app:approve:' . $target['user_id'] . ':' . $profile['revision'], true);
        self::assertSame('approved', $this->person()['status']);
        $channels = [];
        foreach ($this->jobs() as $job) {
            if (str_contains($job['payload']['text'] ?? '', 'your application has been approved')) { $channels[] = $job['channel']; }
        }
        sort($channels);
        self::assertSame(['telegram', 'whatsapp'], $channels);
    }

    public function testExpiredConfirmationCannotLinkThroughTelegramCallback(): void
    {
        $target = $this->submittedTarget('telegram', '501');
        $this->wa('/start'); $this->wa('2'); $this->wa('1'); $this->wa('New'); $this->wa('Company'); $this->wa('/link');
        $token = $this->linkToken();
        $this->db()->exec('UPDATE link_requests SET expires_at=1');
        $this->tg(501, 'link:confirm:' . $token, true);
        self::assertNotSame($target['user_id'], $this->person()['id']);
        self::assertCount(1, $this->store->identities($target['user_id']));
    }

    public static function stopRequests(): array
    {
        return [['/stop'], ['Не отправляйте мне сообщения'], ['Please stop sending me messages!'], ['Dejen de enviarme mensajes'], ['Arrêtez de m’envoyer des messages']];
    }

    #[DataProvider('stopRequests')]
    public function testExplicitStopNeverResubscribesOnStart(string $request): void
    {
        $this->submittedTarget('whatsapp', '442079460018');
        $this->wa($request);
        self::assertSame('unsubscribed', $this->person()['status']);
        $this->wa('/start');
        self::assertSame('unsubscribed', $this->person()['status']);
        self::assertSame(0, $this->person()['subscribed']);
    }
}
