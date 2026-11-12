# Gates: Telegram compatibility with deepl-translate

OWNS: src/Application/**, src/Infrastructure/SqliteStore.php, src/Infrastructure/Http/TelegramClient.php, tests/**, VERIFICATION.md, GATES.md

Scope: Restore existing Telegram responses and approval workflow while retaining WhatsApp delivery and attachment support. Batch attachment confirmations, automatic preview after done, compact deletion menu and native green/red buttons.

- [x] G8: Individual file deletion buttons use the default color
  CHECK: php -d extension=intl -d extension=zip -d extension=fileinfo vendor/bin/phpunit tests/AttachmentInteractionTest.php --filter DefaultColor
  EXPECT: OK (
  EVIDENCE: automatic-evidence=v1; definition-sha256=c2ba752b65b8bd068e8414d5fbee17c2f687951cb93f9c04b235dfcd2acb6a6d; exit=0; EXPECT=matched; output-sha256=752a8d8a982723dfe98950bbc7daac14fd95f2786b9d029722d806bd0a293bc4; output-bytes=694; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=0b021a622426/55 entries

- [x] G9: Back restores the main keyboard in place without resending the preview
  CHECK: php -d extension=intl -d extension=zip -d extension=fileinfo vendor/bin/phpunit tests/AttachmentInteractionTest.php --filter Back
  EXPECT: OK (
  EVIDENCE: automatic-evidence=v1; definition-sha256=66cf429858156cedc3ef6417a2c5998df4afdd8b1469aa4aa7c4f3a7d8f0df90; exit=0; EXPECT=matched; output-sha256=3e70cbd21b486fd7efafdbad0acc842dddec7e97f0a222dfbb0e765659e81928; output-bytes=694; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=0b021a622426/55 entries

- [x] G10: Upload confirmation offers a green Done button opening the full draft
  CHECK: php -d extension=intl -d extension=zip -d extension=fileinfo vendor/bin/phpunit tests/AttachmentInteractionTest.php --filter DoneButton
  EXPECT: OK (
  EVIDENCE: automatic-evidence=v1; definition-sha256=0ac6ffede7e98d05b8865370f1d36091463262281cf455a46b0011591948a7d3; exit=0; EXPECT=matched; output-sha256=e2dfa78b395474ebccd810bcf31dd5b09cae34bb65eafdd4978a01caaf598f48; output-bytes=696; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=0b021a622426/55 entries

- [x] G11: Add files help uses the requested wording and a working Done button
  CHECK: php -d extension=intl -d extension=zip -d extension=fileinfo vendor/bin/phpunit tests/AttachmentInteractionTest.php --filter AddFilesHelp
  EXPECT: OK (
  EVIDENCE: automatic-evidence=v1; definition-sha256=93d99d0b14d874e16e110880d01e0144b194daad72d75e94e85e1486f8acf89b; exit=0; EXPECT=matched; output-sha256=c91b4bcc969b620fc243d435a8f26789f6e66ad9384a3993a8b09af036db0cc9; output-bytes=694; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=0b021a622426/55 entries

- [x] G12: Updated menu navigation and Done button run in the local Docker stack
  EVIDENCE: 2026-11-12 image 42f5206f1caa rebuilt and installed. In-container .unlazy/attachment-ui-smoke.php returned ATTACHMENT_UI_REFINEMENT_DEPLOYED_OK after checking green Done from the upload confirmation, full preview, default individual deletion colors, Back producing exactly one keyboard edit with the original rows, and removal producing a fresh preview. Fake Telegram and in-memory SQLite only. TEST_RECIPIENT_VERIFIED confirmed the authorized recipient in broadcast_live_test. init exited 0; poll, worker and whatsapp-poll running with zero restarts.

- [x] G1: Telegram preview and shared texts match the reference branch
  CHECK: php -d extension=intl -d extension=zip -d extension=fileinfo vendor/bin/phpunit tests/TelegramCompatibilityTest.php
  EXPECT: OK (
  EVIDENCE: automatic-evidence=v1; definition-sha256=713f410888b0e03beb22f7611eea13a2ad4a3e396997095bc879f1422b6ddf17; exit=0; EXPECT=matched; output-sha256=d99a7436e237122602c1d2bde98407de55ede2afd650bfc1da6bc53e2a337502; output-bytes=697; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=0b021a622426/55 entries

- [x] G2: Existing delivery, registration, approval and attachment regressions pass
  CHECK: composer test
  EXPECT: Tests:
  EVIDENCE: automatic-evidence=v1; definition-sha256=87dc244988d7281241986951895b8b1d01f0733041ecc1d195ee3aeccfccb631; exit=0; EXPECT=matched; output-sha256=5f4ec614bbe85c195164b9004581560ffe47561403c4caacb77b9466f893aa29; output-bytes=1077; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=0b021a622426/55 entries

- [x] G3: Updated local image runs with only the authorized WhatsApp recipient
  EVIDENCE: 2026-11-12 Docker Compose rebuilt and started image c33f8bbe7358. Memory-only Kernel and Worker scenario returned ATTACHMENT_UI_DEPLOYED_OK; read-only MySQL check returned TEST_RECIPIENT_VERIFIED for exactly the authorized number in broadcast_live_test. init exited 0; poll, worker and whatsapp-poll running with zero restarts. Competing telegram-deepl-test-poll remains stopped.

- [x] G4: A file batch produces one loaded count and early done waits for automatic preview
  CHECK: php -d extension=intl -d extension=zip -d extension=fileinfo vendor/bin/phpunit tests/AttachmentInteractionTest.php --filter Batch
  EXPECT: OK (
  EVIDENCE: automatic-evidence=v1; definition-sha256=269290b4383c042a3097a82bdc233b81b4680dc5759a81520940210d6c62d531; exit=0; EXPECT=matched; output-sha256=a8e91ee701c038e973ddb8237ac7b7ca348e623525463b78a6932fd10bce2fe4; output-bytes=696; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=0b021a622426/55 entries

- [x] G5: Delete menu edits the clicked keyboard and removal returns a fresh safe preview
  CHECK: php -d extension=intl -d extension=zip -d extension=fileinfo vendor/bin/phpunit tests/AttachmentInteractionTest.php --filter Menu
  EXPECT: OK (
  EVIDENCE: automatic-evidence=v1; definition-sha256=319710fec1b0cdb4dd146afd746d433be59f3f92d516946a065eafda7580893b; exit=0; EXPECT=matched; output-sha256=5ba753fdcd403665f67925a57581d4a8842b2734b2198b199105de6a8f5bace3; output-bytes=696; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=0b021a622426/55 entries

- [x] G6: Green send and red delete styles reach Telegram JSON and keyboard edits are retryable
  CHECK: php -d extension=intl -d extension=zip -d extension=fileinfo vendor/bin/phpunit tests/Http/TelegramClientTest.php --filter Keyboard
  EXPECT: OK (
  EVIDENCE: automatic-evidence=v1; definition-sha256=407077d6d08002e8bfe3a4d8b0a8ba508a316018a94c9f7e29084709059ef35b; exit=0; EXPECT=matched; output-sha256=7adf53d649c47636e10acce121c96f97b4c5dec087a3c571bde60b545f0a3d05; output-bytes=694; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=0b021a622426/55 entries

- [x] G7: Updated attachment interface is installed in the running local test stack
  EVIDENCE: 2026-11-12 .unlazy/attachment-ui-smoke.php executed inside rebuilt worker image c33f8bbe7358 and returned ATTACHMENT_UI_DEPLOYED_OK. Synthetic three-file download produced one count, full preview and six buttons with success/danger styles; clicking deletion edited message 77, removing one file cleared its keyboard and produced a reviewed two-file preview. Zero broadcasts created; all Telegram calls used a fake. Native client rendering has not been manually inspected.
