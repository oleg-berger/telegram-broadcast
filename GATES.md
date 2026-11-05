# Gates: последние пять одобренных рассылок

Scope: История при одобрении пользователя, переводы и ограниченное восстановление ошибок, независимые доставки без дублей, обе ветки. Сервер и рабочая БД исключены из изменений.

Environment: Windows, PHP 8.5.9; PHP_INI_SCAN_DIR=D:/telegram-bot-source/telegram-bot/var/catchup-check (intl, zip, fileinfo).

- [x] G1: Пять последних одобренных рассылок доставляются через оба канала, включая подготовку и старые ошибки перевода
  CHECK: php vendor/bin/phpunit --filter Catchup --do-not-cache-result
  EXPECT: OK (
  EVIDENCE: automatic-evidence=v1; definition-sha256=8565289e017b306b14ddb198e5082e267edd0529b6afd16fc883f7eeb842765a; exit=0; EXPECT=matched; output-sha256=8863ddd2049e6f8be3cd1199b921f7199ed602060ee2c8db82c48947d0ce66a5; output-bytes=301; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=c28d8e055baf/55 entries

- [x] G2: Полный набор тестов deepl-translate проходит
  CHECK: composer test
  EXPECT: OK (
  EVIDENCE: automatic-evidence=v1; definition-sha256=636a34909f8aa514dfadbcee4d7860e9341fe6946b89c513bfa23a824b57f469; exit=0; EXPECT=matched; output-sha256=9f4bb83cbf06ad3ec68814ec0b34f029d89a196e6e54a31fa9a6039fd2b94464; output-bytes=628; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=c28d8e055baf/55 entries

- [x] G3: Полный набор тестов main проходит
  CHECK: composer --working-dir=D:/telegram-bot-source/telegram-bot-main test
  EXPECT: OK (
  EVIDENCE: automatic-evidence=v1; definition-sha256=4e3a0c9bb5a0bf3f5ccd1e7390ad25916a3aa074fe8a54a3480140ee832fe17f; exit=0; EXPECT=matched; output-sha256=c436a372ff6bc421e1169228b89b5ce741f499ac9bb8fa9bc8bfe1d3abf46799; output-bytes=632; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=c28d8e055baf/55 entries

- [x] G4: Общие файлы совпадают в обеих ветках, UI и документация описывают пять рассылок
  CHECK: node scripts/verify-catchup.mjs
  EXPECT: CATCHUP_PARITY_OK
  EVIDENCE: automatic-evidence=v1; definition-sha256=bb4b586805992ee98b2b7a30eb024e532e457e183f535784f87b597859330a63; exit=0; EXPECT=matched; output-sha256=d745d237ee6e2436070caa74d2cc4a3a280a27bf3a2663104690c11021c21e8a; output-bytes=18; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=c28d8e055baf/55 entries

- [x] G5: Изменения не содержат ошибок пробелов в обеих ветках
  CHECK: node scripts/verify-catchup.mjs --whitespace
  EXPECT: CATCHUP_WHITESPACE_OK
  EVIDENCE: automatic-evidence=v1; definition-sha256=1916d00ddf0169bcc0fe294ec0898684287dab83d068b29abfc4f90db15092b1; exit=0; EXPECT=matched; output-sha256=a0b13629df06f2911e941ab3745c8ce356c89245f7be45ea67cb1399428f85c3; output-bytes=22; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=c28d8e055baf/55 entries
