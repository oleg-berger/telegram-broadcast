<?php
declare(strict_types=1);

namespace Broadcast\Application;

use Broadcast\Domain\ApiFailure;
use Broadcast\Domain\TextParts;
use Broadcast\Domain\WhatsAppFailure;
use Broadcast\Domain\WhatsAppTextParts;

final class Worker
{
    private bool $recoveredWhatsApp = false;

    public function __construct(
        private Store $store,
        private TelegramGateway $telegram,
        private Translator $translator,
        private ?\Closure $logger = null,
        private ?MailGateway $mail = null,
        private ?UserExporter $exporter = null,
        private int $mailInterval = 2,
        private ?WhatsAppGateway $whatsapp = null,
        private array $whatsappStaffIds = [],
    ) {}

    /** Perform at most one external operation. Runtime throttles ticks; tests can control time. */
    public function tick(?int $now = null): bool
    {
        $now ??= time();
        if (!$this->recoveredWhatsApp) {
            $this->store->transaction(function (): void {
                foreach ($this->store->recoverWhatsAppDispatches() as $id) {
                    $this->notifyWhatsApp('wa_uncertain', ['id' => $id]);
                }
            });
            $this->recoveredWhatsApp = true;
        }
        $this->store->refreshBroadcasts();
        $job = $this->store->nextJob($now);
        if ($job === null) { return false; }
        if ($job['kind'] === 'delivery' && $job['channel'] === 'email' && $this->store->mailNextAt() > $now) {
            // Mail pace is throttled without consuming delivery attempts.
            $this->store->deferJob($job['id'], $job['attempts'], $this->store->mailNextAt(), 'mail_rate_limit', false);
            return true;
        }
        try {
            match ($job['kind']) {
                'translate' => $this->translate($job),
                'translate_subject' => $this->translateSubject($job),
                'delivery' => $this->deliver($job, $now),
                'channel_post' => $this->channelPost($job),
                'message' => $this->message($job, $now),
                'photo' => $this->photo($job),
                'callback' => $this->callback($job),
                'comment' => $this->comment($job, $now),
                'export' => $this->export($job),
                default => throw new ApiFailure('Unsupported queue job.'),
            };
            $this->log('job_processed', $job);
        } catch (WhatsAppFailure $failure) {
            $this->whatsappFailure($job, $failure, $now);
        } catch (ApiFailure $failure) {
            $attempts = $job['attempts'] + 1;
            $failed = !$failure->transient || $attempts >= 5;
            $error = $failure->blocked ? 'blocked' : ($failure->transient ? 'temporary_api_error' : 'permanent_api_error');
            // Error text is deliberately not persisted: upstream responses can echo personal data.
            $this->store->deferJob($job['id'], $attempts, $now + max($failure->retryAfter ?? 0, 2 ** $attempts), $error, $failed);
            $this->log($failed ? 'job_failed' : 'job_deferred', $job, $attempts);
            if ($failure->blocked && $job['channel'] === 'telegram' && $job['user_id'] !== null && $job['user_id'] > 0) {
                $identity = isset($job['payload']['identity_id'])
                    ? $this->store->identityById($job['payload']['identity_id'])
                    : $this->store->identity('telegram', (string) $job['user_id']);
                if ($identity !== null && (!isset($job['payload']['identity_id']) || $identity['user_id'] === $job['user_id'])) {
                    $this->store->disableSubscriber($identity['user_id']);
                }
            }
            if ($failed && in_array($job['kind'], ['translate', 'translate_subject'], true)) {
                $broadcast = $this->store->broadcast($job['broadcast_id']);
                if ($broadcast['approved_by']) {
                    $this->store->queueReply($broadcast['approved_by'], Messages::text('RU', 'broadcast_translation_failed', ['id' => $broadcast['id'], 'language' => $job['language']]));
                }
            }
            if ($failed && in_array($job['kind'], ['comment', 'export'], true)) {
                $staff = $job['payload']['staff_id'] ?? $job['user_id'];
                $this->store->queueReply($staff, sprintf('Задание #%d (%s) не выполнено. Проверьте сервис. Повтор: /retryjob %d', $job['id'], $job['kind'], $job['id']));
            }
        }
        $this->store->refreshBroadcasts();
        return true;
    }

    private function log(string $event, array $job, ?int $attempt = null): void
    {
        if ($this->logger === null) { return; }
        $record = ['event' => $event, 'job_id' => $job['id'], 'kind' => $job['kind']];
        if ($attempt !== null) { $record['attempt'] = $attempt; }
        ($this->logger)($record);
    }

    private function translate(array $job): void
    {
        if ($this->store->translation($job['broadcast_id'], $job['language']) === null) {
            $broadcast = $this->store->broadcast($job['broadcast_id']);
            $translation = $this->translator->translate($broadcast['text'], $job['language']);
            if (trim($translation) === '') { throw new ApiFailure('Empty translation.'); }
            $this->store->saveTranslation($job['broadcast_id'], $job['language'], $translation);
        }
        $this->store->completeJob($job['id']);
    }

    private function translateSubject(array $job): void
    {
        if ($this->store->translationSubject($job['broadcast_id'], $job['language']) === null) {
            $broadcast = $this->store->broadcast($job['broadcast_id']);
            $subject = $this->translator->translate($broadcast['subject'], $job['language']);
            if (trim($subject) === '' || preg_match('/[\r\n]/', $subject)) { throw new ApiFailure('Invalid translated subject.'); }
            $this->store->saveTranslationSubject($job['broadcast_id'], $job['language'], $subject);
        }
        $this->store->completeJob($job['id']);
    }

    private function deliver(array $job, int $now): void
    {
        $user = $this->store->user($job['user_id']);
        if ($user === null || $user['status'] !== 'approved') { $this->store->completeJob($job['id'], 'skipped'); return; }
        if ($job['channel'] === 'email') {
            if ($this->mail === null) { throw new ApiFailure('Mail adapter is not configured.'); }
            $this->store->setMailNextAt($now + $this->mailInterval);
            $this->mail->send($job['payload']['email'], $this->store->translationSubject($job['broadcast_id'], $job['language']), $this->store->translation($job['broadcast_id'], $job['language']));
            $this->store->completeJob($job['id']);
            return;
        }
        if ($job['channel'] === 'telegram' && !$user['subscribed']) { $this->store->completeJob($job['id'], 'skipped'); return; }
        $text = $this->store->translation($job['broadcast_id'], $job['language']);
        if ($text === null || trim($text) === '') { throw new ApiFailure('Translation is unavailable.', true); }
        $this->sendParts($job, $text, now: $now);
    }

    private function message(array $job, int $now): void
    {
        $this->sendParts($job, $job['payload']['text'], $job['payload']['options'], $now);
    }

    private function channelPost(array $job): void
    {
        $broadcast = $this->store->broadcast($job['broadcast_id']);
        if ($broadcast === null || !$broadcast['approved_by'] || $broadcast['team_channel_id'] !== $job['user_id']) {
            throw new ApiFailure('Channel publication is not approved.');
        }
        $text = $this->store->translation($job['broadcast_id'], 'RU');
        $subject = $this->store->translationSubject($job['broadcast_id'], 'RU');
        if ($text === null || trim($text) === '' || $subject === null || trim($subject) === '') {
            throw new ApiFailure('Russian channel translation is unavailable.', true);
        }
        $this->sendParts($job, $subject . "\n\n" . $text);
    }

    private function photo(array $job): void
    {
        $path = dirname(__DIR__, 2) . '/assets/help-card.png';
        if (!is_file($path)) { throw new ApiFailure('Help image is unavailable.'); }
        $this->telegram->sendPhoto((int) ($job['payload']['address'] ?? $job['user_id']), $path, $job['payload']['caption']);
        $this->store->completeJob($job['id']);
    }

    private function sendParts(array $job, string $text, array $options = [], ?int $now = null): void
    {
        if (isset($job['payload']['identity_id'])) {
            $identity = $this->store->identityById($job['payload']['identity_id']);
            if ($identity === null || $identity['user_id'] !== $job['user_id'] || !$identity['enabled']) {
                $this->store->completeJob($job['id'], 'skipped');
                return;
            }
        }
        if ($job['channel'] === 'whatsapp') {
            $this->sendWhatsAppParts($job, $text, $now ?? time());
            return;
        }
        $parts = TextParts::split($text);
        if (!isset($parts[$job['part']])) { $this->store->completeJob($job['id']); return; }
        $last = $job['part'] + 1 === count($parts);
        $this->telegram->sendMessage((int) ($job['payload']['address'] ?? $job['user_id']), $parts[$job['part']], $last ? $options : []);
        // Record each successful chunk so a retry continues from the first unsent chunk.
        $this->store->advancePart($job['id']);
        if ($last) { $this->store->completeJob($job['id']); }
    }

    private function callback(array $job): void
    {
        $this->telegram->answerCallbackQuery($job['payload']['id']);
        $this->store->completeJob($job['id']);
    }

    private function comment(array $job, int $now): void
    {
        $payload = $job['payload'];
        if (!isset($payload['translated'])) {
            $payload['translated'] = $this->translator->translate($payload['text'], $job['language']);
            if (trim($payload['translated']) === '') { throw new ApiFailure('Empty comment translation.'); }
            $this->store->updateJobPayload($job['id'], $payload);
            return;
        }
        // The original comment is never substituted for a missing translation.
        $this->sendParts($job, $payload['prefix'] . "\n\n" . $payload['translated'] . ($payload['menu_suffix'] ?? ''), $payload['options'] ?? [], $now);
    }

    private function sendWhatsAppParts(array $job, string $text, int $now): void
    {
        $state = $this->store->channelState('whatsapp');
        if ($state['state'] !== 'connected' || $state['next_probe_at'] <= $now) {
            try {
                $connected = $this->whatsapp !== null && $this->whatsapp->connected();
            } catch (WhatsAppFailure) {
                $connected = false;
            }
            $this->store->transaction(function () use ($connected, $now, $state): void {
                $changed = $this->store->setChannelState('whatsapp', $connected ? 'connected' : 'unavailable', $now + 60);
                if ($changed && (!$connected || $state['state'] === 'unavailable')) {
                    $this->notifyWhatsApp($connected ? 'wa_recovered' : 'wa_unavailable');
                }
            });
            // A health probe consumes this tick's external operation budget.
            return;
        }
        if ($this->whatsapp === null) { throw new WhatsAppFailure('unavailable'); }
        $parts = WhatsAppTextParts::split($text);
        if (!isset($parts[$job['part']])) { $this->store->completeJob($job['id']); return; }
        $address = (string) ($job['payload']['address'] ?? '');
        if (str_ends_with($address, '@lid') && isset($job['payload']['identity_id'])) {
            $identity = $this->store->identityById($job['payload']['identity_id']);
            if ($identity !== null && $identity['user_id'] === $job['user_id'] && $identity['verified_phone'] !== null) {
                $address = ltrim($identity['verified_phone'], '+');
                $job['payload']['address'] = $address;
                $this->store->updateJobPayload($job['id'], $job['payload']);
            }
        }
        if (!preg_match('/^[1-9][0-9]{6,14}$/D', $address)) {
            // No proof of a routable phone: preserve the job until mapping becomes available.
            $this->store->deferJob($job['id'], $job['attempts'], $now + 60, 'whatsapp_mapping_pending', false);
            return;
        }
        if (!$this->store->beginWhatsAppPart($job['id'], $job['part'], $now)) { return; }
        $messageId = $this->whatsapp->sendText($address, $parts[$job['part']]);
        if ($messageId === '') { throw new WhatsAppFailure('uncertain'); }
        $this->store->completeWhatsAppPart($job['id'], $job['part'], $messageId, $job['part'] + 1 === count($parts));
    }

    private function whatsappFailure(array $job, WhatsAppFailure $failure, int $now): void
    {
        if ($failure->category === 'uncertain') {
            $this->store->transaction(function () use ($job): void {
                $this->store->markWhatsAppUncertain($job['id'], $job['part']);
                $this->notifyWhatsApp('wa_uncertain', ['id' => $job['id']]);
            });
            $this->log('job_uncertain', $job);
            return;
        }
        if ($failure->category === 'unavailable') {
            $this->store->transaction(function () use ($job, $now): void {
                if ($this->store->setChannelState('whatsapp', 'unavailable', $now + 60)) {
                    $this->notifyWhatsApp('wa_unavailable');
                }
                $this->store->deferJob($job['id'], $job['attempts'], $now + 60, 'whatsapp_unavailable', false);
            });
            $this->log('job_deferred', $job, $job['attempts']);
            return;
        }
        $attempts = $job['attempts'] + 1;
        $failed = $failure->category === 'permanent' || $attempts >= 5;
        $this->store->deferJob($job['id'], $attempts, $now + max($failure->retryAfter ?? 0, 2 ** $attempts),
            $failed ? 'whatsapp_permanent_error' : 'whatsapp_temporary_error', $failed);
        $this->log($failed ? 'job_failed' : 'job_deferred', $job, $attempts);
        if ($failed && $job['kind'] === 'comment') {
            $this->store->queueReply($job['payload']['staff_id'], Messages::text('RU', 'wa_job_failed', ['id' => $job['id']]));
        }
    }

    private function notifyWhatsApp(string $key, array $values = []): void
    {
        foreach (array_unique($this->whatsappStaffIds) as $staff) {
            $this->store->queueReply($staff, Messages::text('RU', $key, $values));
        }
    }

    private function export(array $job): void
    {
        if ($this->exporter === null) { throw new ApiFailure('Export adapter is not configured.'); }
        $path = null;
        try {
            $path = $this->exporter->export($this->store->exportUsers());
            $this->telegram->sendDocument($job['user_id'], $path, 'users.xlsx');
            $this->store->completeJob($job['id']);
        } catch (ApiFailure $failure) {
            throw $failure;
        } catch (\Throwable) {
            throw new ApiFailure('Could not generate export.');
        } finally {
            if ($path !== null && is_file($path)) { unlink($path); }
        }
    }
}
