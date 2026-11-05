# Gates: последние пять одобренных рассылок

Scope: История при одобрении пользователя, переводы и ограниченное восстановление ошибок, независимые доставки без дублей, обе ветки. Сервер и рабочая БД исключены из изменений.

Environment: Windows, PHP 8.5.9; PHP_INI_SCAN_DIR=D:/telegram-bot-source/telegram-bot/var/catchup-check (intl, zip, fileinfo).

- [x] G1: Пять последних одобренных рассылок доставляются через оба канала, включая подготовку и старые ошибки перевода
  CHECK: php vendor/bin/phpunit --filter Catchup --do-not-cache-result
  EXPECT: OK (
  EVIDENCE: automatic-evidence=v1; definition-sha256=8565289e017b306b14ddb198e5082e267edd0529b6afd16fc883f7eeb842765a; exit=0; EXPECT=matched; output-sha256=d88627564ecad553ff7fa17131fe8ba6b0a95ec680110072375e475c42f8499f; output-bytes=301; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=c28d8e055baf/55 entries

- [x] G2: Полный набор тестов deepl-translate проходит
  CHECK: composer test
  EXPECT: OK (
  EVIDENCE: automatic-evidence=v1; definition-sha256=636a34909f8aa514dfadbcee4d7860e9341fe6946b89c513bfa23a824b57f469; exit=0; EXPECT=matched; output-sha256=de858c3716164db8e644067d9bf7185b444b8c28c9de0b83b5ec407fd2d09ceb; output-bytes=628; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=c28d8e055baf/55 entries

- [x] G3: Полный набор тестов main проходит
  CHECK: composer --working-dir=D:/telegram-bot-source/telegram-bot-main test
  EXPECT: OK (
  EVIDENCE: automatic-evidence=v1; definition-sha256=4e3a0c9bb5a0bf3f5ccd1e7390ad25916a3aa074fe8a54a3480140ee832fe17f; exit=0; EXPECT=matched; output-sha256=72ff96da122982d0d932d5d941b2575f1c9fdfdb16c95f18e227b25957db7168; output-bytes=632; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=c28d8e055baf/55 entries

- [x] G4: Общие файлы совпадают в обеих ветках, UI и документация описывают пять рассылок
  CHECK: node scripts/verify-catchup.mjs
  EXPECT: CATCHUP_PARITY_OK
  EVIDENCE: automatic-evidence=v1; definition-sha256=bb4b586805992ee98b2b7a30eb024e532e457e183f535784f87b597859330a63; exit=0; EXPECT=matched; output-sha256=d745d237ee6e2436070caa74d2cc4a3a280a27bf3a2663104690c11021c21e8a; output-bytes=18; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=c28d8e055baf/55 entries

- [x] G5: Изменения не содержат ошибок пробелов в обеих ветках
  CHECK: node scripts/verify-catchup.mjs --whitespace
  EXPECT: CATCHUP_WHITESPACE_OK
  EVIDENCE: automatic-evidence=v1; definition-sha256=1916d00ddf0169bcc0fe294ec0898684287dab83d068b29abfc4f90db15092b1; exit=0; EXPECT=matched; output-sha256=a0b13629df06f2911e941ab3745c8ce356c89245f7be45ea67cb1399428f85c3; output-bytes=22; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=c28d8e055baf/55 entries

- [x] G6: Исправный Docker test-образ действительно выполняет PHPUnit без внешних API
  CHECK: docker run --rm --network none --entrypoint php telegram-broadcast:deepl-test vendor/bin/phpunit --do-not-cache-result
  EXPECT: OK (
  EVIDENCE: automatic-evidence=v1; definition-sha256=a7b00b9746809973743bba0ad909a9d17eee5322c66b5e46ae077a224a4e7ebf; exit=0; EXPECT=matched; output-sha256=176e9e3fb3e849bbe2b1129d1375b9cfb1cbe08be91113901f62036c4d3beded; output-bytes=590; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=c28d8e055baf/55 entries

- [x] G7: Production-образ загружает Composer autoload и запускает справку без БД и API
  CHECK: docker run --rm --network none --read-only --entrypoint php telegram-broadcast:deepl-local bin/console help
  EXPECT: Commands:
  EVIDENCE: automatic-evidence=v1; definition-sha256=fd95cfe20691b422ff8605060fff163f742327ca69790f6acde83cdeeabba6e7; exit=0; EXPECT=matched; output-sha256=5befc753b38c668eaedbf36fab09f118aa8ff1249b78a89a9f4e489eec7a4872; output-bytes=139; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=c28d8e055baf/55 entries

- [x] G8: Compose сохраняет прежние значения по умолчанию и изолирует тестовый образ, базу и порт
  CHECK: node scripts/verify-docker.mjs
  EXPECT: DOCKER_CONFIG_OK
  EVIDENCE: automatic-evidence=v1; definition-sha256=ddc2d93da049b1431c646e0e380851dfc63a1074e8c631206d0b4694f578ae79; exit=0; EXPECT=matched; output-sha256=b1116adf7e8629c834310d9aee170e743e176879d4e09c880c4ab667c7a79372; output-bytes=17; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=c28d8e055baf/55 entries

- [x] G9: Тестовый DeepL работает без перезапусков, конкурирующий Telegram-poller остановлен, WhatsApp работает
  CHECK: node scripts/verify-docker.mjs --runtime
  EXPECT: DOCKER_RUNTIME_OK
  EVIDENCE: automatic-evidence=v1; definition-sha256=163b59f3a166e95b0e06f46f93e076896fe48e7a9177f19231f062586c299176; exit=0; EXPECT=matched; output-sha256=f29c6524580b68e278066c3a821217844bbbc8acaf094bc13d5edc5280bfd8b3; output-bytes=18; shell=C:\Windows\system32\cmd.exe; cwd=D:\telegram-bot-source\telegram-bot; path=c28d8e055baf/55 entries
