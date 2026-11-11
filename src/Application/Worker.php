<?php
declare(strict_types=1);

namespace Broadcast\Application;

use Broadcast\Domain\ApiFailure;
use Broadcast\Domain\Attachment;
use Broadcast\Domain\MailSizeExceeded;
use Broadcast\Domain\TextParts;

final class Worker
{
    private bool $recoveredDeliveryParts = false;
    public function __construct(
        private Store $store,
        private TelegramGateway $telegram,
        private Translator $translator,
        private ?\Closure $logger = null,
        private ?MailGateway $mail = null,
        private ?UserExporter $exporter = null,
        private int $mailInterval = 2,
        private ?AttachmentStorage $attachments = null,
        private ?AttachmentLinks $attachmentLinks = null,
        private int $mailMaxMessageBytes = 25_000_000,
    ) {}

    /** Perform at most one external operation. Runtime throttles ticks; tests can control time. */
    public function tick(?int $now = null): bool
    {
        $now ??= time();
        if ($this->attachments !== null) { $this->store->queueAttachmentCleanup($now); }
        if (!$this->recoveredDeliveryParts) {
            foreach ($this->store->recoverDeliveryParts() as $id) { $this->log('job_uncertain', ['id' => $id, 'kind' => 'delivery', 'broadcast_id' => null]); }
            $this->recoveredDeliveryParts = true;
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
                'attachment_download' => $this->downloadAttachment($job),
                'draft_preview' => $this->draftPreview($job),
                'attachment_cleanup' => $this->cleanupAttachments($job, $now),
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
        } catch (ApiFailure|MailSizeExceeded $failure) {
            if ($failure instanceof MailSizeExceeded) { $failure = new ApiFailure('Email exceeds size limit.'); }
            if ($failure->uncertain) {
                $this->store->completeJob($job['id'], 'unknown');
                $this->log('job_uncertain', $job);
                $this->store->refreshBroadcasts();
                return true;
            }
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
            if ($failed && in_array($job['kind'], ['attachment_download','draft_preview'], true)) {
                $this->store->queueReply($job['payload']['staff_id'] ?? $job['user_id'], Messages::text('RU', 'attachment_download_failed', ['id' => $job['id']]));
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
        if ($job['broadcast_id'] !== null) {
            $record += ['broadcast_id' => $job['broadcast_id'], 'channel' => $job['channel'], 'language' => $job['language']];
        }
        if ($attempt !== null) { $record['attempt'] = $attempt; }
        ($this->logger)($record);
    }

    private function translate(array $job): void
    {
        if ($this->store->translation($job['broadcast_id'], $job['language']) === null) {
            $broadcast = $this->store->broadcast($job['broadcast_id']);
            $translation = trim($broadcast['text']) === '' ? '' : $this->translator->translate($broadcast['text'], $job['language']);
            if (trim($translation) === '' && trim($broadcast['text']) !== '') { throw new ApiFailure('Empty translation.'); }
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
        if ($this->store->broadcastAttachments($job['broadcast_id']) !== []) {
            if ($job['channel'] === 'telegram' && !$user['subscribed']) { $this->store->completeJob($job['id'], 'skipped'); return; }
            $this->deliverAttachments($job, $now);
            return;
        }
        if ($job['channel'] === 'email') {
            if ($this->mail === null) { throw new ApiFailure('Mail adapter is not configured.'); }
            if ($this->mail instanceof MailPreparationGateway && $this->mail->prepareDelivery()) { return; }
            $this->store->setMailNextAt($now + $this->mailInterval);
            if (!$this->store->beginDeliveryPart($job['id'])) { return; }
            $this->mail->send($job['payload']['email'], $this->store->translationSubject($job['broadcast_id'], $job['language']), $this->store->translation($job['broadcast_id'], $job['language']));
            $this->store->completeJob($job['id']);
            return;
        }
        if ($job['channel'] === 'telegram' && !$user['subscribed']) { $this->store->completeJob($job['id'], 'skipped'); return; }
        $text = $this->store->translation($job['broadcast_id'], $job['language']);
        if ($text === null || trim($text) === '') { throw new ApiFailure('Translation is unavailable.', true); }
        $this->sendParts($job, $text);
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
        if ($text === null || $subject === null || trim($subject) === '') {
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

    private function cleanupAttachments(array $job, int $now): void
    {
        if ($this->attachmentLinks !== null) { $this->attachmentLinks->cleanup($now); }
        if ($this->attachments !== null) {
            if ($this->attachments instanceof AttachmentStagingStorage) { $this->attachments->cleanupTemporary($now); }
            foreach ($this->store->expiredAttachments($now) as $row) {
                $file = $this->attachmentObject($row);
                $this->attachments->delete($file->storageKey);
                $this->store->markAttachmentExpired($row['id']);
            }
        }
        $this->store->completeJob($job['id']);
    }

    private function attachmentObject(?array $row): Attachment
    {
        if ($row === null || $row['status'] !== 'ready' || !is_string($row['metadata'])) { throw new ApiFailure('Attachment unavailable.'); }
        try {
            $data = json_decode($row['metadata'], true, flags: JSON_THROW_ON_ERROR);
            return new Attachment(...$data);
        } catch (\Throwable) { throw new ApiFailure('Attachment metadata unavailable.'); }
    }

    private function downloadAttachment(array $job): void
    {
        $row = $this->store->attachment($job['payload']['attachment_id']);
        if (!$row || $row['status'] === 'ready') { $this->store->completeJob($job['id']); return; }
        if (!$this->telegram instanceof AttachmentTelegramGateway || $this->attachments === null) { throw new ApiFailure('Attachment adapter unavailable.'); }
        if (!isset($job['payload']['remote_path'])) {
            $job['payload']['remote_path'] = $this->telegram->filePath($row['file_id']);
            $this->store->updateJobPayload($job['id'], $job['payload']);
            return;
        }
        $temporary = null;
        try {
            if ($this->attachments instanceof AttachmentStagingStorage) { $temporary = $this->attachments->downloadPath(); }
            else {
                $temporary = tempnam(sys_get_temp_dir(), 'broadcast-download-');
                if ($temporary === false || !unlink($temporary)) { throw new ApiFailure('Attachment download unavailable.', true); }
            }
            $this->telegram->downloadFile($job['payload']['remote_path'], $temporary, Attachment::MAX_BYTES);
            $file = $this->attachments->put($temporary, $row['display_name']);
            if ($file->byteSize !== $row['expected_size']) {
                $this->attachments->delete($file->storageKey);
                throw new ApiFailure('Attachment size changed.');
            }
            $this->store->transaction(function () use ($row, $file, $job): void {
                $this->store->completeAttachment($row['id'], $file);
                $this->store->completeJob($job['id']);
            });
        } catch (ApiFailure $failure) { throw $failure; }
        catch (\Throwable) { throw new ApiFailure('Attachment download failed.', true); }
        finally { if (is_string($temporary) && is_file($temporary)) { unlink($temporary); } }
    }

    private function draftPreview(array $job): void
    {
        $payload = $job['payload'];
        $draft = $this->store->draft($payload['draft_id']);
        if (!$draft || $draft['version'] !== $payload['version'] || !$this->store->draftReady($draft['id'])) { $this->store->completeJob($job['id'], 'skipped'); return; }
        if (!isset($payload['audience_counts'])) {
            $payload['audience_counts'] = $this->store->draftAudienceCounts($draft['admin_id']);
            $payload['text'] .= "\n\n" . Messages::text('RU', 'draft_audience_counts', $payload['audience_counts']);
            $this->store->updateJobPayload($job['id'], $payload);
        }
        $textParts = TextParts::split($payload['text']);
        $files = $this->store->draftAttachments($draft['id']);
        $part = $job['part'];
        if ($part < count($textParts)) {
            $this->telegram->sendMessage($job['user_id'], $textParts[$part]);
        } elseif ($part < count($textParts) + count($files)) {
            if (!$this->telegram instanceof AttachmentTelegramGateway || $this->attachments === null) { throw new ApiFailure('Attachment adapter unavailable.'); }
            $attachment = $this->attachmentObject($files[$part - count($textParts)]);
            $this->telegram->sendAttachment($job['user_id'], $attachment, $this->attachments->path($attachment->storageKey));
        } else {
            $this->telegram->sendMessage($job['user_id'], Messages::text('RU', $files === [] ? 'draft_send_prompt' : 'attachment_preview_complete'), $payload['options']);
            $this->store->transaction(function () use ($job, $draft): void {
                $this->store->markDraftPreviewed($draft['id'], $draft['version'], $job['user_id']);
                $this->store->completeJob($job['id']);
            });
            return;
        }
        $this->store->advancePart($job['id']);
    }

    private function deliverAttachments(array $job, int $now): void
    {
        $text = $this->store->translation($job['broadcast_id'], $job['language']);
        $subject = $this->store->translationSubject($job['broadcast_id'], $job['language']);
        if ($text === null || $subject === null) { throw new ApiFailure('Translation unavailable.', true); }
        if ($this->attachments === null) { throw new ApiFailure('Attachment storage unavailable.'); }
        if (!isset($job['payload']['manifest'])) {
            $parts = [];
            $textChunks = $text === '' ? [] : TextParts::split($text);
            foreach ($textChunks as $chunk) { $parts[] = ['type' => 'text', 'text' => $chunk]; }
            foreach ($this->store->broadcastAttachments($job['broadcast_id']) as $row) {
                $file = $this->attachmentObject($row);
                $direct = match ($job['channel']) {
                    'telegram' => $this->telegram instanceof AttachmentTelegramGateway,
                    'email' => $this->mail instanceof AttachmentMailGateway,
                    default => false,
                };
                if ($direct) {
                    $parts[] = ['type' => 'file', 'id' => $row['id']];
                } else {
                    $parts[] = ['type' => 'link_pending', 'id' => $row['id']];
                }
            }
            $job['payload']['manifest'] = $parts;
            $this->store->updateJobPayload($job['id'], $job['payload']);
        }
        $parts = $job['payload']['manifest'];
        if ($job['channel'] === 'email') {
            if ($this->mail === null) { throw new ApiFailure('Mail adapter unavailable.'); }
            if ($this->mail instanceof MailPreparationGateway && $this->mail->prepareDelivery()) { return; }
            $files = []; $starts = [];
            foreach (array_keys($parts) as $index) {
                $part = $this->resolveAttachmentPart($job, $index, $now);
                if ($part['type'] === 'file') {
                    $row = $this->store->attachment($part['id']);
                    $file = $this->availableAttachment($part['id'], $now);
                    $files[] = ['attachment' => $file, 'path' => $this->attachments->path($file->storageKey)];
                    if ($row['first_sent_at'] === null) { $starts[] = $part['id']; }
                } elseif ($part['type'] === 'link') { $text .= "\n\n" . $part['text']; }
            }
            $this->store->setMailNextAt($now + $this->mailInterval);
            if (!$this->store->beginDeliveryPart($job['id'])) { return; }
            foreach ($starts as $id) { $this->store->markAttachmentSent($id, $now); }
            try {
                if ($files !== [] && $this->mail instanceof AttachmentMailGateway) { $this->mail->sendWithAttachments($job['payload']['email'], $subject, $text, $files); }
                else { $this->mail->send($job['payload']['email'], $subject, $text); }
            } catch (MailSizeExceeded) {
                foreach ($starts as $id) { $this->store->clearUnsentAttachmentStart($id, $now); }
                $this->fallbackEmailFiles($job, $now);
                return;
            }
            $this->store->completeJob($job['id']);
            return;
        }
        $part = $parts[$job['part']] ?? null;
        if ($part === null) { $this->store->completeJob($job['id']); return; }
        $part = $this->resolveAttachmentPart($job, $job['part'], $now);
        $chat = (int) ($job['payload']['address'] ?? $job['user_id']);
        $file = $part['type'] === 'file' ? $this->availableAttachment($part['id'], $now) : null;
        $path = $file !== null ? $this->attachments->path($file->storageKey) : null;
        if (!$this->store->beginDeliveryPart($job['id'])) { return; }
        if ($part['type'] === 'file') {
            if (!$this->telegram instanceof AttachmentTelegramGateway) { throw new ApiFailure('Attachment adapter unavailable.'); }
            $this->store->markAttachmentSent($part['id'], $now);
            $this->telegram->sendAttachment($chat, $file, $path);
        } else { $this->telegram->sendMessage($chat, $part['text']); }
        $this->store->advancePart($job['id']);
        if ($job['part'] + 1 === count($parts)) { $this->store->completeJob($job['id']); }
    }

    private function fallbackEmailFiles(array $job, int $now): void
    {
        if ($this->attachmentLinks === null) { throw new ApiFailure('Attachment links unavailable.'); }
        $changed = false;
        foreach ($job['payload']['manifest'] as &$part) {
            if ($part['type'] !== 'file') { continue; }
            $part = ['type' => 'link_pending', 'id' => $part['id']];
            $changed = true;
        }
        unset($part);
        if (!$changed) { throw new ApiFailure('Email exceeds size limit.'); }
        // This exception proves no delivery request was made; reset its intent without an attempt.
        $this->store->transaction(function () use ($job, $now): void {
            $this->store->updateJobPayload($job['id'], $job['payload']);
            $this->store->deferJob($job['id'], $job['attempts'], $now, 'mail_size_fallback', false);
        });
    }

    private function availableAttachment(int $id, int $now): Attachment
    {
        $row = $this->store->attachment($id);
        $file = $this->attachmentObject($row);
        if ($row['first_sent_at'] !== null && $now >= $row['first_sent_at'] + 2_592_000) { throw new ApiFailure('Attachment expired.'); }
        return $file;
    }

    private function resolveAttachmentPart(array &$job, int $index, int $now): array
    {
        $part = $job['payload']['manifest'][$index];
        if (isset($part['expires_at']) && $now >= $part['expires_at']) { throw new ApiFailure('Attachment link expired.'); }
        if (!in_array($part['type'], ['link_pending','link'], true)) { return $part; }
        if ($this->attachmentLinks === null) { throw new ApiFailure('Attachment links unavailable.'); }
        $file = $this->availableAttachment($part['id'], $now);
        $started = $this->store->attachment($part['id'])['first_sent_at'] ?? $now;
        $url = $this->attachmentLinks->issue($file, $job['user_id'], $started);
        $this->store->markAttachmentSent($part['id'], $started);
        $part = ['type' => 'link', 'id' => $part['id'], 'expires_at' => $started + 2_592_000];
        $job['payload']['manifest'][$index] = $part;
        $this->store->updateJobPayload($job['id'], $job['payload']);
        return $part + ['text' => $file->displayName . "\n" . $url];
    }
}
