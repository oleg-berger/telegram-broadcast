# Gates: Merge WhatsApp into deepl-translate

OWNS: src/**, tests/**, migrations/**, Dockerfile, compose*.yaml, GATES.md, VERIFICATION.md, docs/**, scripts/**

Scope: Preserve DeepL five-message catchup, integrate WhatsApp/MySQL and the tested attachment UI, verify both database upgrade paths, and create a merge commit on deepl-translate.

Environment: PHP extensions intl, zip, fileinfo, pdo_mysql; CONTACTS_REQUIRE_MYSQL_TESTS=1; isolated MySQL database broadcast_test on 127.0.0.1:13306. No production or live-test data.

- [x] G1: Five-message history works with and without the MySQL directory
  CHECK: php vendor/bin/phpunit tests/CatchupHistoryTest.php tests/MergedCatchupTest.php
  EXPECT: OK (
  EVIDENCE: automatic-evidence=v1; definition-sha256=8bd5b7ca8e4e17bfbea8d761fe2d4284a12d8044959aaabba7ef00f349fd5fe1; exit=0; EXPECT=matched; output-sha256=3d50052db7b57348fa7746cffbafa4ce90542c75214b21b8ad7abeca9f70f6c0; output-bytes=301; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=0b021a622426/55 entries

- [x] G2: WhatsApp attachment interface and safety regressions pass
  CHECK: php vendor/bin/phpunit tests/AttachmentInteractionTest.php tests/TelegramCompatibilityTest.php tests/AttachmentDeliveryTest.php
  EXPECT: OK (
  EVIDENCE: automatic-evidence=v1; definition-sha256=ce2417ecaf3d9084e9b9e74a9895449059c95981f985f0bd57cf44d3f3367997; exit=0; EXPECT=matched; output-sha256=2bd0ade8283ac8f6821512d771c34114030ade708c193080b6e264129b31371a; output-bytes=301; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=0b021a622426/55 entries

- [x] G3: Migrations preserve data from both source branches
  CHECK: php vendor/bin/phpunit tests/MergeMigrationTest.php
  EXPECT: OK (
  EVIDENCE: automatic-evidence=v1; definition-sha256=5f399e2a7c692b6e9ab97a1f5d9c2b9250506413e78d577fe1f240a22c3fd4d9; exit=0; EXPECT=matched; output-sha256=898ac510c8df86c168a3d376b915043b5ce0ec424d97f8c30dde9a7cad53a2a6; output-bytes=299; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=0b021a622426/55 entries

- [x] G4: Full suite including isolated MySQL integration passes
  CHECK: composer test
  EXPECT: OK (
  EVIDENCE: automatic-evidence=v1; definition-sha256=636a34909f8aa514dfadbcee4d7860e9341fe6946b89c513bfa23a824b57f469; exit=0; EXPECT=matched; output-sha256=82b8c9c8a2580b434fb0497296639cf18c7a4b3d4ad6d152a6358e6179419368; output-bytes=1032; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=0b021a622426/55 entries

- [x] G5: Merge commit on deepl-translate contains both original branch tips
  CHECK: node -e "const{execFileSync:x}=require('node:child_process'),a=require('node:assert/strict'),g=(...s)=>x('git',s,{encoding:'utf8'}).trim();a.equal(g('branch','--show-current'),'deepl-translate');a.deepEqual(g('show','-s','--format=%P','HEAD').split(' '),['0ee0705b78be9ce33a06893e7e04017c8a9cd494','f6dabc7b45a3c0be5ff83c9e02b2ac8e02c5ef3e']);console.log('MERGE_ANCESTRY_OK')"
  EXPECT: MERGE_ANCESTRY_OK
  EVIDENCE: automatic-evidence=v1; definition-sha256=132e7b55dd41f3f815319e956892af07ba93ede6342ac82dabecbf79961410bb; exit=0; EXPECT=matched; output-sha256=68c9bf5f59c1a4cc86ec1d626f429bc3100b5d138ad68d763a1ae1e4b4b6be30; output-bytes=18; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=0b021a622426/55 entries

- [x] G6: Merged Docker test image builds and runs the suite on PHP 8.4
  CHECK: docker build --target test --progress=plain -t telegram-broadcast:merge-test .
  EXPECT: naming to docker.io/library/telegram-broadcast:merge-test
  EVIDENCE: automatic-evidence=v1; definition-sha256=5176f90f2ec94707cd3196bc112c26f6140af5ff47f2f825f4cb20b18fefd581; exit=0; EXPECT=matched; output-sha256=000671b4259e80a59fb3eff9c502b9fe007dd12c8b07d64f5a42e6ffba057c5b; output-bytes=5036; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=0b021a622426/55 entries
