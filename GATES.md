# Gates: последние три объявления новому пользователю

Scope: В обеих ветках новый одобренный пользователь получает до трёх последних готовых объявлений через существующую очередь без повторных доставок.

Environment: PHP 8.5.9; PHP_INI_SCAN_DIR=D:/telegram-bot-source/telegram-bot/var/catchup-check (intl, zip, fileinfo). Проверки выполнены на Windows через cmd.exe.

- [x] G1: Догоняющая рассылка выбирает последние объявления, сохраняет порядок, оба канала и отчёты
  CHECK: php vendor/bin/phpunit --filter Catchup tests/DeliveryTest.php
  EXPECT: OK (
  EVIDENCE: automatic-evidence=v1; definition-sha256=b74e8ceeb64ed4da3ecc2bc2beb208661132725bfdcb8b952cf9847fbf7f9e26; exit=0; EXPECT=matched; output-sha256=c608a54388b99bb8eb46f7ed4a25aa6ebc542bc512f3479b050e3dd16e1e0c2e; output-bytes=298; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=68f4b331ccea/55 entries

- [x] G2: Полный набор тестов deepl-translate проходит
  CHECK: composer test
  EXPECT: OK (
  EVIDENCE: automatic-evidence=v1; definition-sha256=636a34909f8aa514dfadbcee4d7860e9341fe6946b89c513bfa23a824b57f469; exit=0; EXPECT=matched; output-sha256=a585ff4567f6b580c3145ddd2bed88e6b345e1d8d414fbaf8c8036c486b501a0; output-bytes=627; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=68f4b331ccea/55 entries

- [x] G3: Полный набор тестов main проходит
  CHECK: composer --working-dir=D:/telegram-bot-source/telegram-bot-main test
  EXPECT: OK (
  EVIDENCE: automatic-evidence=v1; definition-sha256=4e3a0c9bb5a0bf3f5ccd1e7390ad25916a3aa074fe8a54a3480140ee832fe17f; exit=0; EXPECT=matched; output-sha256=5df77d65c0df01c6ca67aadacabe99eb884ba1327713b8a01d5fede6c1f2c47c; output-bytes=632; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=68f4b331ccea/55 entries

- [x] G4: Обе ветки содержат одинаковое исправление; UI и документация описывают три объявления
  EVIDENCE: 2026-11-05: SHA-256 попарно совпадают у SqliteStore.php, DeliveryTest.php и ui.json в обеих рабочих копиях. README:16 в обеих ветках описывает до трёх объявлений; submitted проверен на RU/EN-GB/ES/FR. git diff --check проходит в обеих ветках. Чужие изменения main в README/VERIFICATION и импорте пользователей сохранены.
