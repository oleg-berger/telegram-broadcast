# Plan: готовое локальное приложение WhatsApp

Scope: whatsapp
Depth: tree 2
Mode: orchestrated

## Contract

Revision 2. Shared interfaces, error model, schema, ownership boundaries and toolchain: CONTRACT.md. Original specification: docs/whatsapp/REQUEST.txt. User amendment: Telegram/mail use local fake APIs; a live WhatsApp test series to the previously authorized recipient is permitted. No production deployment. Entire implementation stays in broadcast-whatsapp.

Host launch mode: Codex native subagents. Wave1 starts independent store/flow/transport implementations against the fixed interface; no leaf may change another's paths. Root integrates after all three returns. Max3 concurrent leaves. Root independently re-verifies, reviews security-sensitive linking/migration/uncertain-send cases and runs regression and local demonstration. Native history-free briefs include this contract and exact leaf ledger only.

## Current contract inventory

| ID | Required outcome or constraint | Owner | Observing gate or manual review | Disposition | Revision |
|---|---|---|---|---|---|
| C01 | Separate stable person identity and messenger accounts; lossless additive migration | 1.1 | leaf-1.1:G1,G2 | MET | 1 |
| C02 | New WhatsApp-only registration reuses existing workflow | 1.2 | leaf-1.2:G1 | MET | 1 |
| C03 | Exact existing four-language registration texts | 1.2 | leaf-1.2:G1,G2 | MET | 1 |
| C04 | Edit/back/preview/submit preserve validation | 1.2 | leaf-1.2:G1 | MET | 1 |
| C05 | Verified PN vs declared phone vs LID remain distinct | 1.3 | leaf-1.3:G1,G2 | MET | 1 |
| C06 | Staff Telegram card, approve/return/reject roles and stale controls | 1.2 | leaf-1.2:G1 | MET | 1 |
| C07 | Staff comment translation and no original fallback | 1.4 | leaf-1.4:G1 | MET | 1 |
| C08 | No announcement before approval; audience/channel/language snapshots | 1.1 | leaf-1.1:G1 | MET | 1 |
| C09 | Origin messenger plus one email, linked both messengers plus one email | 1.1 | leaf-1.1:G1 | MET | 1 |
| C10 | Confirmation in original messenger in BOTH directions | 1.2 | leaf-1.2:G1 | MET | 1 |
| C11 | One-use expiry/rate limits/foreign/stale/repeated confirmation | 1.1 | leaf-1.1:G1,G2 | MET | 1 |
| C12 | No disclosure/automerge; email-only and inaccessible origin need audited staff resolution | 1.2 | leaf-1.2:G1,G2 | MET | 1 |
| C13 | Pending user may link but needs common approval | 1.1 | leaf-1.1:G1 | MET | 1 |
| C14 | Latest catchup on first approval; new channel only on link; no phantom report | 1.1 | leaf-1.1:G1 | MET | 1 |
| C15 | Language shared, future snapshots only | 1.2 | leaf-1.2:G1 | MET | 1 |
| C16 | Full unsubscribe all channels, explicit stop request respected, no extra button | 1.2 | leaf-1.2:G1 | MET | 1 |
| C17 | WhatsApp outage preserves days-long FIFO backlog, other channels continue | 1.4 | leaf-1.4:G1 | MET | 1 |
| C18 | Global outage consumes no recipient retry budget and transition alerts do not spam | 1.4 | leaf-1.4:G1 | MET | 1 |
| C19 | Distinguish accepted/delivered/read/permanent/transient/unavailable/unknown | 1.4 | leaf-1.4:G1 | MET | 1 |
| C20 | Persist sending intent; reconcile trustworthy evidence; no blind resend of unknown | 1.4 | leaf-1.4:G1,G2 | MET | 1 |
| C21 | Unicode, accents, newlines, long-text limits and persistent part progress | 1.3 | leaf-1.3:G1 | MET | 1 |
| C22 | Event replay dedup and separate TG/WA namespaces, transactional cursor | 1.3 | leaf-1.3:G1 | MET | 1 |
| C23 | Token authorization, loopback API, private sessions, recipient allowlist | 1.3 | leaf-1.3:G1,G2 | MET | 1 |
| C24 | No profile/text/contact/secret/upstream errors in logs | 1.4 | GATES:G5 | MET | 1 |
| C25 | Preserve all Telegram/email regressions and independent retries | 1.4 | GATES:G3 | MET | 1 |
| C26 | Session and queue survive process restart | 1.4 | leaf-1.4:G1,G2 | MET | 1 |
| C27 | Actual idle/connect/exchange/recovery RAM CPU measurements including sampled peaks | 1.4 | GATES:G4 | MET | 1 |
| C28 | Full local registration→staff→approval→announcement→translation→delivery→report demo | 1.4 | GATES:G2,G4 | MET | 1 |
| C29 | Backup-before-migration, rollback restore, lock all processes, reset safe | 1.4 | leaf-1.4:G1,G2 | MET | 1 |
| C30 | WPPConnect Go pinned, no silent replacement, no duplicate transport/PHP retries | 1.3 | leaf-1.3:G2 | MET | 1 |
| C31 | No production changes; local stand isolated, APIs fake except authorized WA series | 1.4 | GATES:G4,G5 | MET | 1 |
| C32 | Russian runbook, migration design, verification results, honest limits | 1.4 | leaf-1.4:G2 | MET | 1 |
| C33 | No WA admin, media broadcast, channel chooser, extra channel unsubscribe, CRM or DB engine changes | 1.4 | GATES:G6 | MET | 1 |
| C34 | Safely free disk space using only user-approved two installer deletions | 1.4 | GATES:G6 | MET | 2 |
| C35 | Back up Docker disk, authorized WSL restart, restore WhatsApp and OpenClaw services | 1.4 | GATES:G6 | MET | 2 |

## Tree

- 1 Local application ........ GATES.md
  - 1.1 Persistence .......... gates/leaf-1.1.md
  - 1.2 Shared registration .. gates/leaf-1.2.md
  - 1.3 Transport ............ gates/leaf-1.3.md
  - 1.4 Integration/runtime .. gates/leaf-1.4.md

## Leaf dispatch table

| Leaf | Owns | Needs | Tier | Planned wave | State |
|---|---|---|---|---|---|
| 1.1 | src/Application/Store.php, src/Infrastructure/SqliteStore.php, migrations/004_whatsapp.sql, tests/WhatsApp/Store/** | - | judgment | 1 | VERIFIED |
| 1.2 | src/Application/Kernel.php, src/Application/Registration.php, src/Application/Staff.php, src/Application/ApplicationCard.php, src/Application/AccountLinker.php, src/Application/ui.json, tests/WhatsApp/Flow/** | - | judgment | 1 | VERIFIED |
| 1.3 | src/Application/WhatsAppGateway.php, src/Domain/WhatsAppFailure.php, src/Domain/WhatsAppTextParts.php, src/Infrastructure/WhatsApp/**, src/Infrastructure/Runtime/WhatsAppPoller.php, tests/WhatsApp/Transport/** | - | judgment | 1 | VERIFIED |
| 1.4 | src/Application/Worker.php, src/Infrastructure/Runtime/Config.php, src/Infrastructure/Runtime/DatabaseReset.php, src/Infrastructure/Runtime/CommandMenu.php, bin/console, tools/whatsapp/**, tests/WhatsApp/Integration/**, docs/whatsapp/APPLICATION.md, VERIFICATION.md, .env.example, compose.whatsapp.yaml | - | judgment | 1 | VERIFIED |

Review focus: numeric identity collision; early LID receipts; crash after send before persistence; concurrent link expiry/duplicate; reset replay of old events. Tests required, plus independent root review. Existing test assertions are not weakened. If compatibility expectations rely on TG ID==person ID, introduce explicit fixture identity resolution with the same behavioral assertion, after recording rationale.
