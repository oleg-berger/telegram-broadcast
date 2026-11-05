# Shared implementation contract, revision 1

Only branch broadcast-whatsapp. PHP 8.4, SQLite, PSR-4. Existing migrations immutable. No production configuration, no live API in unit tests. Read AGENTS.md and docs/whatsapp/REQUEST.txt. Existing registration text values must remain byte-identical. New linking/status texts use Messages::text on all four languages. Code and comments English; documentation Russian.

## Identity and persistence (leaf 1.1)

users.id is an internal stable integer. Preserve existing IDs and all history; backfill explicit Telegram identities in migration 004. Allocate NEW identities using an internal sequence, never derive WhatsApp person IDs from phone/LID. Transport address is separate. Old direct saveUser callers remain supported as legacy Telegram users when no identity exists (document that compatibility boundary); ensureIdentity creates its user and identity atomically without that legacy fallback. Kernel always resolves identities for incoming traffic. Staff actors remain Telegram IDs, never person IDs.

Extend Store and implement in SqliteStore with these signatures. Arrays are typed through docblocks; not raw transport responses:

```
identity(string $channel, string $address): ?array
identityById(int $id): ?array
identities(int $userId): array
ensureIdentity(string $channel, string $address, ?string $verifiedPhone = null): array
queueUserReply(int $userId, string $text, array $options = [], ?int $identityId = null): void
queueUserPhoto(int $userId, string $caption, ?int $identityId = null): void
menu(int $identityId): array
acceptChannelEvent(string $channel, string $eventId, int $sequence): bool
channelCursor(string $channel): int
contactOwner(string $field, string $value, int $exceptId): ?array
createLinkRequest(int $sourceIdentityId, int $targetUserId, int $now, bool $manual = false): ?array
linkRequest(int $id): ?array
pendingLinks(): array
confirmLink(string $token, int $confirmIdentityId, int $now): ?array
resolveLink(int $id, int $actor, bool $approve, string $reason, int $now): ?array
addChannelCatchup(int $userId, string $language, string $channel): bool
channelState(string $channel): array
setChannelState(string $channel, string $state, int $nextProbeAt): bool
beginWhatsAppPart(int $jobId, int $part, int $now): bool
completeWhatsAppPart(int $jobId, int $part, string $messageId, bool $last): void
markWhatsAppUncertain(int $jobId, int $part): void
recoverWhatsAppDispatches(): array
recordWhatsAppReceipt(string $messageId, string $status, string $address): bool
deferWhatsAppReceipt(string $eventId, int $sequence, array $messageIds, string $status, string $address): void
pendingWhatsAppReceipts(int $limit = 100): array
completeWhatsAppReceipt(string $eventId): void
```

Identity shape: id,user_id,channel,address,verified_phone(nullable normalized +E164),enabled. Channel address: Telegram decimal positive ID; WhatsApp PN digits only (verified) OR opaque `digits@lid` when mapping unavailable. Never interpret LID as phone. Identity uniqueness channel+address and user+channel. Linking moves only the proposed identity, never contacts/history; reject merging two submitted profiles except explicit audited staff resolution. No new approval for approved target. Draft source becomes linked tombstone after attachment. Repeated/expired/foreign confirmation does nothing. Token 32 random bytes, hash at rest, TTL 15 minutes, at most 3 requests/source/hour and at most one active request/source. Store returns raw token only at creation for queueing confirmation. Manual resolution requires nonblank reason, records actor/time/decision; caller enforces superadmin. Return successful link array includes user_id,identity_id,channel and request id. pendingLinks returns staff-only records. Target must lack source channel. Expired requests are not approvable.

Amendment 2026-11-02 (implementation evidence, scope unchanged): ensureIdentity additionally returns created boolean for initial intro. Preserve raw WhatsApp chat JID through incoming normalization together with verified_phone; store maintains proven address aliases so LID→PN mapping later does not create another person. Distinct existing submitted profiles are not merged by alias update. Dedup incoming stable message_id in namespace whatsapp-message in addition to server event_id in whatsapp. Upstream event retention is seven days: unresolved receipt mappings are durably deferred (event_id,sequence,message_ids,status,address) before cursor advances, then retried separately without blocking later messages. Pending receipt methods above store only normalized receipt fields, not whole private upstream envelopes.

queueReply/queuePhoto continue to mean explicit Telegram staff destination. queueUserReply routes to identityId when given (must belong to user), otherwise all confirmed identities. For WhatsApp flatten exact keyboard labels into numbered options, save current menu mapping (number => callback_data or literal text), expiry 15 min; remove_keyboard clears menu. queueUserPhoto sends caption as text to WA (no new media support). Queued payloads snapshot address and identity_id. All outgoing external calls stay Worker. Comment jobs independently per confirmed identity; translate once/job, no original fallback. Recipient snapshots include confirmed channel identities and one email/person. Legacy saved users have explicit Telegram identity. addCatchup/addChannelCatchup only reset reported if a delivery was actually inserted; latest ready approved broadcast; new channel catches up only itself. Full unsubscribe checks user status for every channel. Disabled TG cannot disable WA/email. Reports distinguish accepted/delivered/read/unknown; unknown is terminal and excluded from retryBroadcast.

acceptChannelEvent persists channel+event ID and monotonically advances cursor transactionally; duplicate business effects forbidden. Telegram processed_updates remains isolated. Reset/backup compatibility includes all new tables; reset must retain event cursors/dedup so old events do not recreate users. nextJob honors global WA next_probe_at and FIFO WA deliveries across announcements; no head-of-line blocking of other channels. beginWhatsAppPart persists sending intent before external call; restart recovers sending jobs as unknown with no blind resend. completeWhatsAppPart persists transport message ID and per-part accepted status, advances part, job accepted only on last part. receipt updates only matching message ID AND snapshot address; aggregate delivered/read only when every part qualifies. Receipt arrival before acceptance must not be silently lost (durable unmatched receipt reconciliation). Unknown IDs cannot resolve ambiguous sends without strong evidence. channelState shape {state,next_probe_at}, default unknown/0; set returns state changed.

## Shared registration and linking (leaf 1.2)

Kernel handle Telegram routes staff by real Telegram actor, public registration by identity.user_id. Add handleWhatsApp(array $event) with normalized shape event_id,sequence,message_id,address,verified_phone,body,from_me,is_group,created_at. Dedup and all effects in one short transaction; no network. Trusted transport supplies verified_phone or null. Reject malformed, group/from-me events. Numeric menu input maps to current unexpired stored menu. WhatsApp has no staff routing even when numeric address resembles admin. Registration is reused, optional identity ID added to message/callback parameters (default null preserves direct-call tests). Send user responses via queueUserReply/Photo with current identity. Verified WA phone skips manual phone question after company and cannot be replaced with an unverified number. Unknown LID permits declared phone input but it is NOT channel verification; matching/linking goes to staff, never auto-confirms.

Linking from duplicate confirmed phone offers new localized prompt without disclosing target profile; /link requests confirmation to original channel; /linkhelp escalates to staff. Email-only match always manual. Both directions supported using same service. Confirmation callback `link:confirm:TOKEN` accepted only from original identity of target. Success sends localized notices and addChannelCatchup for new channel if approved. /stop plus explicit stop words in four languages request full unsubscribe (no new menu button); never ignore. Staff /links and /linkresolve ID approve|reject REASON, superadmin only, audited; Staff approval/status/comment responses route user identities. ApplicationCard includes available channels, no spoofed Telegram mention for internal person ID. Existing staff workflow and role checks remain intact.

## Transport (leaf 1.3)

Application WhatsAppGateway interface:
```
connected(): bool
sendText(string $address, string $text): string
events(int $cursor, int $limit = 100): array
resolvePhone(string $address): ?string
```
events returns native bounded server events (poller normalizes); sendText returns nonempty message ID or throws Domain WhatsAppFailure. WhatsAppFailure extends RuntimeException with readonly category in unavailable,transient,permanent,uncertain and nullable retryAfter; static sanitized messages only, no upstream content/PII. WppConnectClient constructor(string baseUrl,string session,string token,array allowedRecipients,?WhatsAppHttpClient http=null). HTTP boundary dedicated interface request(method,url,headers,?body,timeout):HttpResponse; curl implementation no retries/redirects, bounded body, safe errors. Loopback HTTP only (HTTPS optional), session strict pattern, nonempty token; allowlist checked BEFORE send, empty list fails closed local stand. Resolve PN/LID using authenticated endpoint; never derive phone from LID. /send-message uses digits PN and isGroup:false. HTTP timeout/lost/5xx after POST send => uncertain unless positively known no send; disconnected health => unavailable. No transport autosend/replay/retry. /message-receipts cannot be trusted (PN/LID mismatch observed); consume onack events by message ID and verified PN mapping instead. Split text via Domain WhatsAppTextParts::split(text), max65536 Unicode scalars and encoded request<=128KiB, retain all characters, choose conservative60000 UTF8 bytes to cover JSON escaping.

Runtime WhatsAppPoller constructor(Store,WhatsAppGateway,Kernel); once():int reads cursor, events, normalizes onmessage and onack; ignore QR/other event bodies, never log them. Resolve phone outside Kernel transaction; mapping failure must not fabricate a number. Persist/retry mapping failures without losing cursor; preserve opaque LID when unmapped, route reply send refuses unresolved LID safely. receipt address resolve before record. Ignore events from other sessions (client enforces). Poller called from separate whatsapp-poll process with lock; maintenance must lock it too. Unit tests HTTP fakes only.

## Integration owner root (leaf 1.4)

Worker optional WhatsAppGateway at end of constructor plus notification staff IDs. One external op/tick: scheduled health probe before send, persist health and return; later tick sends, does not re-probe every message. Global outage defers without spending recipient attempts, notification only transition, healthy retry probe interval60s. Uncertain part never normal retry; notify staff once. Recover intent after restart. Explicit /retry only failed safe jobs. Optional WhatsApp config disabled default, token private file, isolated local test config, runnable fake Telegram/mail/translator demonstration. Existing reset/restore/locks updated. Record all25 acceptance scenarios, measured resource data, local runbook/rollback, VERIFICATION.md. No production deployment.

## Verification

Leaf tests own distinct directories tests/WhatsApp/{Store,Flow,Transport}; root tests/WhatsApp/Integration plus regression suite. Tests use in-memory/temp SQLite and fakes, never live sends. Run PHP via `php -d extension=intl -d extension=zip -d extension=fileinfo vendor/bin/phpunit PATH` (local extensions may already loaded: prefer existing PHP_INI_SCAN_DIR setup if so). Leaf check commands use fixed repository verify runner authored by root. No --approve until user has approved inspected commands. Agents can implement and inspect scripts; driver handles execution approval. Four passes: implement, expert reread, defect hunt, polish. Report exact tests and remaining gaps, no invented completion.

Final amendments 2026-11-02: WhatsApp account confirmation uses literal request-specific /confirm TOKEN, never a replaceable numeric menu. Migration005 contact_reservations is the canonical independent field registry; audited approved link chains determine effective ownership while legacy contacts retain history. Unknown/status transition and staff alerts commit atomically. Poller and Store defer up to1000 receipt IDs consistently. Root branch integration also updates XLSX export/import for explicit channel addresses, reservations and person_sequence, plus Docker test copies LocalStand. Windows local launcher is the concrete runnable stand; no separate application Compose override is required.
