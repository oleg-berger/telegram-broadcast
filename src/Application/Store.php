<?php
declare(strict_types=1);

namespace Broadcast\Application;

/** Persistence boundary for registration and the durable outbox. All times are Unix seconds. */
interface Store
{
    public function transaction(callable $operation): mixed;
    public function acceptUpdate(int $id, ?int $sourceDate = null): bool;
    public function user(int $id): ?array;
    public function saveUser(array $user): void;
    public function queueReply(int $chatId, string $text, array $options = []): void;
    public function queuePhoto(int $chatId, string $caption): void;
    public function queueCallback(string $id): void;
    public function createBroadcast(int $adminId, string $text): array;
    public function broadcast(int $id): ?array;
    public function addCatchup(int $userId, string $language): bool;
    public function contactTaken(string $field, string $value, int $exceptId): bool;
    public function submitApplication(int $id): bool;
    public function applications(?int $id = null): array;
    public function decideApplication(int $id, int $revision, string $status, int $actor): bool;
    public function unsubscribeUser(int $id): bool;
    public function session(int $actor): ?array;
    public function saveSession(int $actor, ?array $session): void;
    public function createDraft(int $admin, string $text): array;
    public function draft(int $id): ?array;
    public function saveDraft(int $id, int $version, array $changes): bool;
    public function approveDraft(int $id, int $version, int $actor): ?array;
    public function queueComment(int $userId, string $language, string $text, string $prefix, int $staffId, array $options = []): void;
    public function queueExport(int $chatId): void;
    public function exportUsers(): array;
    public function translation(int $broadcastId, string $language): ?string;
    public function saveTranslation(int $broadcastId, string $language, string $text): void;
    public function translationSubject(int $id, string $language): ?string;
    public function saveTranslationSubject(int $id, string $language, string $subject): void;
    public function updateJobPayload(int $id, array $payload): void;
    public function mailNextAt(): int;
    public function setMailNextAt(int $time): void;
    public function retryAuxiliaryJob(int $jobId, int $actor, bool $superadmin): bool;
    public function retryBroadcast(int $id): bool;
    public function nextJob(int $now): ?array;
    public function completeJob(int $id, string $status = 'done'): void;
    public function advancePart(int $id): void;
    public function deferJob(int $id, int $attempts, int $nextAt, string $error, bool $failed): void;
    public function disableSubscriber(int $id): void;
    public function refreshBroadcasts(): void;
}
