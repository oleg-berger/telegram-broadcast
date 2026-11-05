# Gates: transport

OWNS: src/Application/WhatsAppGateway.php, src/Domain/WhatsAppFailure.php, src/Domain/WhatsAppTextParts.php, src/Infrastructure/WhatsApp/**, src/Infrastructure/Runtime/WhatsAppPoller.php, tests/WhatsApp/Transport/**

Scope: bounded authenticated WPPConnect adapter, safe failure categories, event normalization and long text.

- [x] G1: fake HTTP proves authorization, allowlist, failure categories, PN/LID mapping, replay and lossless text splitting
  CHECK: node tools/whatsapp/verify.mjs transport
  EXPECT: VERIFIED transport
  EVIDENCE: automatic-evidence=v1; definition-sha256=526db1c0ada9023487fc7912b6eee47b296b7f52766b9ebc6368af606faeb324; exit=0; EXPECT=matched; output-sha256=c4002d3ae3d1890e77b6c86092a781f5bbe9ef5666a1495ce3d27d8592d02630; output-bytes=320; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=27fbc06d7211/55 entries
- [x] G2: pinned transport behavior and privacy reviewed against source, without assumed exactly-once semantics
  EVIDENCE: source review WPPConnect cf1098f and SDK9d2171c, patches retained; README documents same-ID frame retry and seven-day event retention, no exactly-once claim. Server response budget2MiB fits client3MiB. Bearer/header-only auth, loopback, empty-allowlist deny, private session and Docker logging none verified. Batch1000 deferred receipt persistence fixed and tested. Independent review found no remaining transport issue.
- [x] G0: ledger has valid fail-capable oracles
  CHECK: node C:/Users/oleg-berger/.codex/skills/unlazy/scripts/gate-lint.mjs .unlazy/whatsapp/gates/leaf-1.3.md
  EXPECT: LINT OK
  EVIDENCE: automatic-evidence=v1; definition-sha256=f1b87a2597387f4095e0780c2ecd0c21f444d9ea3b19cf080286d8109c3a1113; exit=0; EXPECT=matched; output-sha256=65b27d43a9edc523d1e6927f091f24ded7d2829d421b9fd85064b92373d59f22; output-bytes=176; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=27fbc06d7211/55 entries
