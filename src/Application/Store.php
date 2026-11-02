<?php
declare(strict_types=1);

namespace Broadcast\Application;

/**
 * Persistence boundary for registration and the durable outbox. All times are Unix seconds.
 * @phpstan-type ChannelIdentity array{id:int,user_id:int,channel:string,address:string,verified_phone:?string,enabled:int,retired:int}
 * @phpstan-type LinkRequest array{id:int,source_identity_id:int,source_user_id:int,target_user_id:int,confirm_identity_id:int,created_at:int,expires_at:int,manual:int,status:string,actor:?int,resolved_at:?int,reason:?string}
 * @phpstan-type LinkedIdentity array{id:int,user_id:int,identity_id:int,channel:string}
 */
interface Store
{
    public function transaction(callable $operation): mixed;
    public function acceptUpdate(int $id, ?int $sourceDate = null): bool;
    public function user(int $id): ?array;
    public function saveUser(array $user): void;
    /** @return ChannelIdentity|null */
    public function identity(string $channel, string $address): ?array;
    /** @return ChannelIdentity|null */
    public function identityById(int $id): ?array;
    /** @return list<ChannelIdentity> */
    public function identities(int $userId): array;
    /** @return ChannelIdentity&array{created:bool,address_conflict?:bool,conflict_user_id?:int} */
    public function ensureIdentity(string $channel, string $address, ?string $verifiedPhone = null): array;
    public function queueUserReply(int $userId, string $text, array $options = [], ?int $identityId = null): void;
    public function queueUserPhoto(int $userId, string $caption, ?int $identityId = null): void;
    /** @return array{options:array<int,string>,expires_at:int}|array{} */
    public function menu(int $identityId): array;
    /** Dated pre-reset messages advance dedup/cursor but do not apply business effects. */
    public function acceptChannelEvent(string $channel, string $eventId, int $sequence, ?int $sourceDate = null): bool;
    public function channelCursor(string $channel): int;
    /**
     * Resolve reserved contacts through approved link audit chains; exclude the same effective person.
     * @return array<string,mixed>|null Current profile; callers must not disclose it to the requester.
     */
    public function contactOwner(string $field, string $value, int $exceptId): ?array;
    /** @return (LinkRequest&array{token:string})|null Raw confirmation token is returned only upon creation/escalation. */
    public function createLinkRequest(int $sourceIdentityId, int $targetUserId, int $now, bool $manual = false): ?array;
    /** @return LinkRequest|null */
    public function linkRequest(int $id): ?array;
    /** @return list<LinkRequest> Staff-only bounded results. */
    public function pendingLinks(): array;
    /** @return LinkedIdentity|null */
    public function confirmLink(string $token, int $confirmIdentityId, int $now): ?array;
    /** @return LinkedIdentity|LinkRequest|null Caller must enforce the superadmin role. */
    public function resolveLink(int $id, int $actor, bool $approve, string $reason, int $now): ?array;
    public function addChannelCatchup(int $userId, string $language, string $channel): bool;
    /** @return array{state:string,next_probe_at:int} */
    public function channelState(string $channel): array;
    public function setChannelState(string $channel, string $state, int $nextProbeAt): bool;
    public function beginWhatsAppPart(int $jobId, int $part, int $now): bool;
    public function completeWhatsAppPart(int $jobId, int $part, string $messageId, bool $last): void;
    public function markWhatsAppUncertain(int $jobId, int $part): void;
    /** @return list<int> IDs changed to terminal unknown; never eligible for blind retry. */
    public function recoverWhatsAppDispatches(): array;
    public function recordWhatsAppReceipt(string $messageId, string $status, string $address): bool;
    /** @param list<string> $messageIds */
    public function deferWhatsAppReceipt(string $eventId, int $sequence, array $messageIds, string $status, string $address): void;
    /** @return list<array{event_id:string,sequence:int,message_ids:list<string>,status:string,address:string}> */
    public function pendingWhatsAppReceipts(int $limit = 100): array;
    public function completeWhatsAppReceipt(string $eventId): void;
    public function queueReply(int $chatId, string $text, array $options = []): void;
    public function queuePhoto(int $chatId, string $caption): void;
    public function queueCallback(string $id): void;
    public function createBroadcast(int $adminId, string $text): array;
    public function broadcast(int $id): ?array;
    public function addCatchup(int $userId, string $language): bool;
    /** Field reservations are authoritative; legacy contacts can retain archived profile values. */
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
    /** For a WhatsApp sending intent, use only when failure positively establishes no send occurred. */
    public function deferJob(int $id, int $attempts, int $nextAt, string $error, bool $failed): void;
    public function disableSubscriber(int $id): void;
    public function refreshBroadcasts(): void;
}
