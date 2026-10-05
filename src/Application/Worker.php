<?php
declare(strict_types=1);

namespace Broadcast\Application;

use Broadcast\Domain\ApiFailure;
use Broadcast\Domain\TextParts;

final class Worker
{
    public function __construct(
        private Store $store,
        private TelegramGateway $telegram,
        private Translator $translator,
        private ?\Closure $logger = null,
        private ?MailGateway $mail = null,
        private ?UserExporter $exporter = null,
        private int $mailInterval = 2,
    ) {}

    /** Perform at most one external operation. Runtime throttles ticks; tests can control time. */
    public function tick(?int $now = null): bool
    {
        $now ??= time();
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
                'message' => $this->message($job),
                'photo' => $this->photo($job),
                'callback' => $this->callback($job),
                'comment' => $this->comment($job),
                'export' => $this->export($job),
                default => throw new ApiFailure('Unsupported queue job.'),
            };
            $this->log('job_processed', $job);
        } catch (ApiFailure $failure) {
            $attempts = $job['attempts'] + 1;
            $failed = !$failure->transient || $attempts >= 5;
            $error = $failure->blocked ? 'blocked' : ($failure->transient ? 'temporary_api_error' : 'permanent_api_error');
            // Error text is deliberately not persisted: upstream responses can echo personal data.
            $this->store->deferJob($job['id'], $attempts, $now + max($failure->retryAfter ?? 0, 2 ** $attempts), $error, $failed);
            $this->log($failed ? 'job_failed' : 'job_deferred', $job, $attempts);
            if ($failure->blocked && $job['user_id'] !== null && $job['user_id'] > 0) { $this->store->disableSubscriber($job['user_id']); }
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
        if (!$user['subscribed']) { $this->store->completeJob($job['id'], 'skipped'); return; }
        $this->sendParts($job, $this->store->translation($job['broadcast_id'], $job['language']));
    }

    private function message(array $job): void
    {
        $this->sendParts($job, $job['payload']['text'], $job['payload']['options']);
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
        $this->telegram->sendPhoto($job['user_id'], $path, $job['payload']['caption']);
        $this->store->completeJob($job['id']);
    }

    private function sendParts(array $job, string $text, array $options = []): void
    {
        $parts = TextParts::split($text);
        if (!isset($parts[$job['part']])) { $this->store->completeJob($job['id']); return; }
        $last = $job['part'] + 1 === count($parts);
        $this->telegram->sendMessage($job['user_id'], $parts[$job['part']], $last ? $options : []);
        // Record each successful chunk so a retry continues from the first unsent chunk.
        $this->store->advancePart($job['id']);
        if ($last) { $this->store->completeJob($job['id']); }
    }

    private function callback(array $job): void
    {
        $this->telegram->answerCallbackQuery($job['payload']['id']);
        $this->store->completeJob($job['id']);
    }

    private function comment(array $job): void
    {
        $payload = $job['payload'];
        if (!isset($payload['translated'])) {
            $payload['translated'] = $this->translator->translate($payload['text'], $job['language']);
            if (trim($payload['translated']) === '') { throw new ApiFailure('Empty comment translation.'); }
            $this->store->updateJobPayload($job['id'], $payload);
            return;
        }
        // The original comment is never substituted for a missing translation.
        $this->sendParts($job, $payload['prefix'] . "\n\n" . $payload['translated'], $payload['options'] ?? []);
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
