# Контакты MySQL и вложения — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Подключить клиентскую базу WhatsApp из Excel/MySQL без регистрации и доставлять одобренные объявления с файлами в Telegram, WhatsApp и email.

**Architecture:** MySQL хранит каталог клиентов, SQLite — анкеты Telegram, outbox синхронизации, черновики, снимки аудитории и очередь. Внешние операции выполняются через Worker вне транзакции Kernel, с одной внешней операцией за тик. Закрытое файловое хранилище и HTTPS endpoint обеспечивают доставку ссылками, если прямое вложение невозможно.

**Tech Stack:** PHP 8.4, PDO MySQL/SQLite, PHPUnit 11, OpenSpout, PHPMailer, Gmail API, Telegram Bot API, существующий WppConnect; без фреймворка и дополнительного сервера очередей.

**Spec:** [Согласованное описание и технические уточнения](../specs/2026-11-06-multichannel-files-design.md).

## Global Constraints

- Сохранять Telegram-регистрацию, её одобрение и повторную подачу прежней анкеты.
- WppConnect остаётся транспортом WhatsApp; входящие сообщения — менеджеру, без регистрации WhatsApp.
- Максимум 10 файлов по 20 000 000 байт; тема обязательна; текст необязателен; переводятся только тема и текст.
- Telegram-email приоритетен; язык Telegram не заменяется языком WhatsApp.
- `/unsubscribe ID` отключает все каналы; повторное одобрение Telegram возобновляет доступные каналы.
- Ссылки HTTPS доступны 30 дней, отсутствующие каналы пропускаются; успех означает приём транспортом.
- Снимок аудитории публикуется целиком до первой доставки; новые WhatsApp-клиенты не получают архив.
- Один update — одна короткая SQLite-транзакция; сеть только в фазах Worker, без распределённой транзакции.
- Новые миграции, prepared statements, строгие типы, русский UI через Messages; не читать .env и секреты в вывод.
- Логи только с ID/статусами/классами; без upstream errors, файлов, телефонов, адресов и токенов.
- Полный `composer test` обязателен в каждой затронутой ветке; обновлять VERIFICATION.md с фактическими результатами.

## Review Focus

1. MySQL принял изменение, SQLite не успел отметить успех: повтор операции не дублирует клиента и не воскрешает старую подписку — задачи 2–3.
2. Номер совпал с незавершённой/отклонённой анкетой Telegram: WhatsApp активен независимо, Telegram не получает одобрение автоматически — задача 4.
3. Файл заменён во время скачивания или альбом пришёл несколькими updates: одобряется только полный конкретный комплект без дублей — задачи 5–6.
4. WppConnect принял файл, но ответ потерян: нет автоматического повторного файла или fallback-ссылки — задачи 7–8.
5. Ссылка истекла, задание осталось или восстановлена старая копия БД: нет утечки/удаления живого файла и нет ложного успешного повтора — задачи 9–10.

## Порядок и текущая точка

Рабочая ветка на момент подготовки: `broadcast-whatsapp`, дерево чистое до добавления этих документов. Исследованы Store, Kernel, Staff, Worker, AccountLinker, SqliteStore, шлюзы каналов, WppConnectClient, WhatsAppPoller, bin/console, миграции 001–005. Текущий импорт `bin/import-users.php` предназначен для восстановления полного экспорта; не заменять его упрощённым импортом.

Последовательность: 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9 → 10. Два функциональных этапа: после 4 готова текстовая WhatsApp-рассылка из каталога; после 10 готовы вложения и ввод. Промежуточное состояние не выпускать как завершённую задачу.

### 1. Зафиксировать исходное поведение и контракты интеграции

**Files:** `tests/RegistrationNavigationTest.php`, `tests/ApprovalFlowTest.php`, `tests/WhatsApp/Integration/WorkerWhatsAppTest.php`, `tests/WhatsApp/Transport/WppConnectClientTest.php`, `docs/whatsapp/TRANSPORT-SPIKE.md`, `VERIFICATION.md`.

**Interfaces:** существующие `Kernel::handle(array): void`, `Store::decideApplication(int,int,string,int): bool`, `WhatsAppGateway::sendText(string,string): string` сохраняют назначение.

- [ ] Выполнить исходный `composer test`, записать число тестов и среду. При отключённых Windows extensions использовать `php -d extension=intl -d extension=zip -d extension=fileinfo vendor/bin/phpunit`.
- [ ] Добавить регрессионные сценарии: `testTelegramResubmissionRetainsPreviousForm`, `testTelegramBlockDoesNotDisableEmailOrWhatsApp`, `testStaleApprovalCannotSend`; проверить точные состояния, отсутствие лишних заданий и неизменность языка.
- [ ] Исследовать именно закреплённый transport image/Dockerfile и его source/API для файлов: адрес endpoint, формат multipart/base64, message ID, MIME, лимиты и классификация отказов. Не предполагать, что имя WppConnectClient гарантирует конкретный upstream API. Результат записать в TRANSPORT-SPIKE.md с версией.
- [ ] Проверить наличие MySQL, версию, существующие таблицы/индексы и HTTPS-инфраструктуру без вывода клиентских данных и доступов. Если сервер пока недоступен, разработка идёт на изолированной MySQL; совместимость сервера остаётся обязательным gate ввода.
- [ ] Выполнить `composer test`; отдельный commit `test: lock Telegram and broadcast regression behavior`.

### 2. Каталог MySQL и безопасные миграции

**Create:** `src/Application/ContactDirectory.php`, `src/Domain/DirectoryContact.php`, `src/Domain/RecipientSnapshot.php`, `src/Infrastructure/Contacts/MySqlContactDirectory.php`, `migrations/mysql/001_contact_directory.sql`, `tests/Contacts/MySqlContactDirectoryTest.php`, `compose.contacts-test.yaml`.
**Modify:** `src/Infrastructure/Runtime/Config.php`, `bin/console`, `composer.json`, `Dockerfile`, `Dockerfile.whatsapp`, `tests/WhatsApp/Integration/WhatsAppConfigTest.php`.

**Interfaces:** `ContactDirectory::applyOperation(string $operationId, array $change): void`; `snapshot(string $snapshotId): RecipientSnapshot`; `deliveryAllowed(int $contactId, string $channel): bool`. `DirectoryContact` содержит стабильный ID, E.164 телефон, отдельные языки/адреса/флаги и Telegram revision. `RecipientSnapshot` содержит snapshot ID, время и список конечных адресатов с contact ID, channel, address, language. Все операции идемпотентны.

- [ ] Написать падающие тесты `testPhoneIsUniqueAfterNormalization`, `testEmptyEmailIsNull`, `testTelegramEmailWins`, `testOldTelegramRevisionCannotOverwriteNewState`, `testSameOperationIsAppliedOnce`, `testInvalidManualRowPreventsSnapshot`. Snapshot либо целый, либо ошибка; недопустимый язык/адрес не пропускается молча.
- [ ] Добавить таблицы contacts, channel settings и applied operations с InnoDB, внешними ключами, уникальным телефоном и индексами адреса/статуса. Ручные поля отделить от полей Telegram; реальные имена существующих серверных таблиц не угадывать и не перезаписывать.
- [ ] Реализовать адаптер PDO и согласованный snapshot одной MySQL-транзакцией. Не держать открытую SQLite-транзакцию при чтении MySQL. Читать каталог полностью один раз на рассылку, оценить память на ожидаемом размере базы; при превышении бюджета материализовать серверный snapshot и читать страницами по стабильному ID.
- [ ] Добавить опциональные `CONTACTS_MYSQL_DSN`, `CONTACTS_MYSQL_USER`, `CONTACTS_MYSQL_PASSWORD`, отдельную явную команду миграции каталога и extension pdo_mysql. В обычных main/deepl без каталога MySQL не обязателен. `check` проверяет синтаксис без сети и без значений секретов; готовность соединения — отдельная команда.
- [ ] Запустить `docker compose -f compose.contacts-test.yaml up -d --wait`, затем `vendor/bin/phpunit tests/Contacts/MySqlContactDirectoryTest.php`; ожидается PASS на реальной изолированной MySQL. Полный suite и commit `feat: add MySQL contact directory`.

### 3. Outbox, снимок аудитории и глобальное отключение

**Create:** `migrations/006_contact_directory.sql`, `src/Application/DirectorySync.php`, `tests/Contacts/DirectorySyncTest.php`, `tests/Contacts/AudienceSnapshotTest.php`.
**Modify:** `src/Application/Store.php`, `src/Infrastructure/SqliteStore.php`, `src/Application/Kernel.php`, `src/Application/Staff.php`, `src/Application/Worker.php`, `src/Application/ui.json`, `bin/console`.

**Interfaces:** `DirectorySync::tick(int $now): bool` исполняет не более одной внешней операции; `Store::enqueueDirectoryChange(string $operationId, array $change): void`; `Store::publishAudience(int $broadcastId, RecipientSnapshot $snapshot): bool`; `Store::blockContact(int $contactId): void`. Связь directory ID/local user ID хранится явно. Изменение Telegram и outbox записываются одной SQLite-транзакцией.

- [ ] Написать падающие тесты: `testCrashAfterMySqlCommitReplaysSafely`, `testApprovalWaitsForPendingDirectoryChanges`, `testRepeatedApprovalPublishesOneSnapshot`, `testMySqlOutageNeverPublishesPartialAudience`, `testUnsubscribeBlocksQueuedPartsImmediately`, `testStaleActivationCannotUndoUnsubscribe`.
- [ ] Ввести состояния подготовки/публикации снимка и outbox со стабильными operation ID. Подготовка читает MySQL вне SQLite transaction; публикация аудитории/переводов/доставок — одна SQLite transaction с уникальными ключами. Повтор после сбоя использует сохранённый snapshot либо один опубликованный результат, не смешивает страницы разных чтений.
- [ ] Добавить фазу проверки deliveryAllowed перед отправкой и локальную проверку запрета непосредственно перед вызовом шлюза. У проверки и отправки разные тики; MySQL outage откладывает проверку, не потребляет попытку транспортной отправки. Дать синхронизации/проверкам ограниченную справедливую долю очереди, чтобы медленный каталог не блокировал callback и независимые сервисные задания.
- [ ] Для новых job kinds (`directory_sync`, `audience_snapshot`, `delivery_check`) согласованно обновить nextJob, Worker match, retry и refreshBroadcasts; технические jobs не считать пользовательскими доставками. Запрос MySQL и вызов канала не выполнять в одном тике.
- [ ] Сохранить локальную отписку и повторное одобрение при недоступности MySQL, синхронизировать их в порядке ревизий. Пока нужная синхронизация не закончена, не начинать общую рассылку. Количества в preview предварительные; окончательные публикуются после снимка.
- [ ] `vendor/bin/phpunit tests/Contacts` и `composer test` → PASS; commit `feat: synchronize directory and snapshot broadcast audiences`.

### 4. Первоначальный Excel и WhatsApp без регистрации

**Create:** `src/Infrastructure/Import/XlsxContacts.php`, `bin/import-whatsapp-contacts.php`, `tests/Contacts/XlsxContactsTest.php`, `tests/WhatsApp/Flow/DirectoryRecipientsTest.php`, `docs/whatsapp/CONTACTS.md`.
**Modify:** `src/Application/Kernel.php`, `src/Application/Registration.php`, `src/Application/AccountLinker.php`, `src/Infrastructure/Runtime/WhatsAppPoller.php`, `src/Infrastructure/WhatsApp/WppConnectClient.php`, `src/Infrastructure/SqliteStore.php`, `src/Infrastructure/Runtime/Config.php`.

**Interfaces:** `XlsxContacts::read(string $path): array` возвращает проверенные строки phone/language/email и ошибки по номеру строки без вывода персональных значений. Импорт вызывает ContactDirectory::applyOperation; повтор идентичного файла не создаёт дубли.

- [ ] Тесты RED: пустая почта; Excel-number/scientific notation с потерей цифр отклоняется; дубли после E.164, неизвестный язык, повтор файла; приоритет Telegram-email; совпавшая pending/rejected Telegram-анкета не одобряется; импорт не снимает unsubscribe; новый WhatsApp не получает старые broadcasts.
- [ ] Реализовать dry-run по умолчанию и явное применение проверенного файла одной каталоговой транзакцией. Не писать Excel, SQL с клиентами и отчёт с контактами в Git. `bin/import-users.php` оставить восстановлением старого полного экспорта.
- [ ] Разделить доступность WhatsApp и статус Telegram-анкеты. Автосвязь по доказанному телефону не меняет Telegram revision/approval и не раскрывает прежнюю анкету постороннему. Оставить staff linking для неоднозначных случаев; `/unsubscribe ID` находит также клиента только с WhatsApp.
- [ ] Входящие WhatsApp отмечать обработанными без автоматического создания пользователя/меню/анкеты/ответа; receipts и дедупликация сохраняются. Не перехватывать менеджерскую переписку.
- [ ] Заменить статический production allowlist проверкой активного адресата каталога/снимка. Тестовый allowlist оставить дополнительным обязательным ограничением; пустой allowlist тестового контура по-прежнему запрещает всё.
- [ ] `vendor/bin/phpunit tests/Contacts tests/WhatsApp tests/RegistrationNavigationTest.php tests/ApprovalFlowTest.php` и полный suite → PASS; commit `feat: onboard WhatsApp recipients from Excel`.

### 5. Модель вложений и закрытое файловое хранилище

**Create:** `migrations/007_attachments.sql`, `src/Domain/Attachment.php`, `src/Application/AttachmentStorage.php`, `src/Infrastructure/Files/LocalAttachmentStorage.php`, `tests/Files/AttachmentStorageTest.php`.
**Modify:** `src/Application/Store.php`, `src/Infrastructure/SqliteStore.php`, `src/Application/TelegramGateway.php`, `src/Infrastructure/Http/TelegramClient.php`, `src/Infrastructure/Runtime/Config.php`, `tests/Http/TelegramClientTest.php`.

**Interfaces:** `Attachment` — id, безопасное displayName, MIME, byteSize, sha256, storageKey, position; `AttachmentStorage::put(string $sourcePath, string $displayName): Attachment`; `path(string $storageKey): string`; `delete(string $storageKey): void`; `TelegramGateway::downloadFile(string $fileId, string $targetPath, int $maxBytes): void`. Шлюз скачивает потоково, не возвращает token URL в исключении.

- [ ] RED: файл ровно 20 000 000 байт принимается, +1 отклоняется; десятый принимается, одиннадцатый отклоняется; traversal/CRLF в имени не меняют путь/заголовки; скачивание сверх лимита обрывается и удаляет temp; повтор update не создаёт второй файл.
- [ ] Хранить метаданные отдельно от draft/broadcast relations; случайные storage keys, atomic rename, fileinfo, hash и неизменяемые blobs. Скачивание — job `attachment_download`, один внешний вызов за тик; статус ready только после проверки размера/hash и atomic commit метаданных. Убрать сиротские temp после сбоя.
- [ ] Принимать Telegram document и наибольшую подходящую photo version; не скачивать файлы внутри Kernel. Не делать вызовы getFile и download в одном тике: хранить отдельные фазы получения remote path и скачивания, не печатать path с token. Заблокировать approval пока все файлы не ready.
- [ ] `vendor/bin/phpunit tests/Files tests/Http/TelegramClientTest.php` и suite → PASS; commit `feat: store broadcast attachments safely`.

### 6. Сборка черновика и проверяемое одобрение

**Create:** `tests/AttachmentDraftTest.php`.
**Modify:** `src/Application/Staff.php`, `src/Application/Kernel.php`, `src/Application/Store.php`, `src/Infrastructure/SqliteStore.php`, `src/Application/ui.json`, `tests/DraftReworkTest.php`, `tests/SuperadminBroadcastTest.php`.

**Interfaces:** `Store::addDraftAttachment(int $draftId, int $version, int $attachmentId): bool`; `removeDraftAttachment(int $draftId, int $version, int $attachmentId): bool`; `draftAttachments(int $draftId): array`. Любое изменение состава/порядка увеличивает version. `approveDraft` атомарно закрепляет immutable attachment relations.

- [ ] RED: files-only + subject разрешено; пустое всё запрещено; subject обязателен; pending download запрещает отправку на согласование; старая кнопка после замены/удаления не одобряет; повтор альбома не дублирует файлы.
- [ ] Сохранить существующий вход «текст → тема», добавить понятное начало с файла/фото и кнопки добавить/удалить/завершить. Альбом — несколько updates; готовность только по явной кнопке сотрудника, без sleep и догадки по таймеру.
- [ ] Preview отправляет суперадминистратору тему, текст, упорядоченный комплект через очередь, затем кнопку конкретной версии; кнопка активируется только после успешной отправки всех частей preview. Ошибка предпросмотра не даёт одобрить невидимый файл. Отдельные captions не сохранять как скрытый непереведённый текст: пояснять правило единого текста через Messages.
- [ ] При пустом тексте не создавать перевод пустой строки; subject переводится независимо. Обновить SQL dependencies переводов и channel_post, не подменять отсутствующий перевод оригиналом.
- [ ] `vendor/bin/phpunit tests/AttachmentDraftTest.php tests/DraftReworkTest.php tests/SuperadminBroadcastTest.php tests/TranslationCompletionTest.php tests/TeamChannelTest.php` → PASS; suite и commit `feat: compose and approve attachment broadcasts`.

### 7. Прямые вложения в транспортных адаптерах

**Create:** `src/Domain/OutboundMail.php`, `tests/WhatsApp/Transport/WppConnectFilesTest.php`, `tests/Mail/MailAttachmentsTest.php`.
**Modify:** `src/Application/TelegramGateway.php`, `src/Infrastructure/Http/TelegramClient.php`, `src/Application/WhatsAppGateway.php`, `src/Infrastructure/WhatsApp/WppConnectClient.php`, `src/Application/MailGateway.php`, `src/Infrastructure/Mail/SmtpMailer.php`, `src/Infrastructure/Mail/GmailApiMailer.php`, `tests/Mail/SmtpMailerTest.php`, `tests/Mail/GmailApiMailerTest.php`, фейки шлюзов во всех затронутых tests/tools.

**Interfaces:** сохранить прежние вызовы MailGateway::send через необязательный `array $attachments = []` (list<Attachment>); TelegramGateway::sendDocument — необязательный `string $mime = 'application/octet-stream'`; WhatsAppGateway::sendFile(string $address, Attachment $attachment, string $path): string`. OutboundMail рассчитывает закодированный MIME size до отправки; один и тот же подготовленный состав повторяется без перестройки.

- [ ] RED: PDF/DOCX/XLSX/MD/TXT и photo получают правильные filename/MIME/bytes; subject/filename не допускают header injection; прежний XLSX-export работает; SMTP не получает динамических свойств; Gmail multipart/base64url корректен.
- [ ] Реализовать Telegram multipart без жёсткого XLSX MIME, SMTP addAttachment и Gmail MIME attachments. Не держать десять файлов целиком в памяти без ограничения; закрепить ресурсный бюджет и проверить MIME overhead у границы лимита.
- [ ] Реализовать WhatsApp файл согласно контракту задачи 1: validate recipient, accepted ID и адрес назначения. Поддержать arbitrary document fallback, известный unsupported-format отделить от unknown/transient, raw responses не сохранять.
- [ ] RED/PASS `testAcceptedFileWithLostResponseBecomesUnknown`: таймаут после передачи не приводит к автоматической повторной отправке. Не ослаблять существующие receipt/uncertain проверки текста.
- [ ] `vendor/bin/phpunit tests/Http tests/Mail tests/WhatsApp/Transport` и полный suite → PASS; commit `feat: send attachments through all delivery gateways`.

### 8. Прогресс комплекта, повторы и отчёт

**Create:** `migrations/008_delivery_parts.sql`, `tests/AttachmentDeliveryTest.php`.
**Modify:** `src/Application/Worker.php`, `src/Application/Store.php`, `src/Infrastructure/SqliteStore.php`, `src/Application/ui.json`, `tests/DeliveryTest.php`, `tests/WhatsApp/Integration/WorkerWhatsAppTest.php`, `tests/WorkerLoggingTest.php`.

**Interfaces:** `Store::nextDeliveryPart(int $jobId): ?array`; `completeDeliveryPart(int $jobId, int $part, ?string $transportId): void`; строки прогресса имеют unique(job_id,part), type text/file/link/mail, attachment_id и status. WhatsApp использует существующий begin/complete/uncertain protocol, расширенный на файлы.

- [ ] RED: текст и file1 успешны, file2 падает → повтор только file2; успешная почта не повторяется при сбое WhatsApp; отписка между файлами останавливает остаток; success не выставляется до принятия всего комплекта; channel failure не отменяет другие каналы.
- [ ] Разделить delivery на фазы check/send и устойчивый manifest. Один вызов транспорта за tick; каждый файл имеет индивидуальный счётчик 5 попыток, backoff/retry_after; /retry ID не повторяет done/accepted/delivered/read/unknown.
- [ ] SMTP/Gmail — одна часть-письмо, не отдельный job на каждый attachment. При потере ответа после возможного приёма выставлять unknown, не обещать отсутствие дубля через слепой retry. Отчёт различает принятые, неполные, failed, unknown, skipped и receipts WhatsApp; report_included сохраняется для всех пользовательских доставок.
- [ ] Проверить целиком nextJob/refreshBroadcasts, независимость RU channel_post, files-only translations и all-skipped/zero audience. Лог-тест подставляет секретные маркеры в имена/ответы ошибок и утверждает их отсутствие в stdout/error columns.
- [ ] `vendor/bin/phpunit tests/AttachmentDeliveryTest.php tests/DeliveryTest.php tests/WhatsApp/Integration tests/WorkerLoggingTest.php tests/TeamChannelTest.php` и suite → PASS; commit `feat: track multipart broadcast delivery and safe retries`.

### 9. HTTPS-ссылки, MIME fallback и очистка

**Create:** `migrations/009_attachment_links.sql`, `src/Application/AttachmentLinks.php`, `src/Infrastructure/Files/AttachmentDownload.php`, `public/download.php`, `tests/Files/AttachmentLinksTest.php`, `tests/Files/AttachmentDownloadTest.php`, `docs/ATTACHMENTS.md`, `deploy/attachments-nginx.conf`.
**Modify:** `src/Infrastructure/SqliteStore.php`, `src/Application/Worker.php`, `src/Infrastructure/Runtime/Config.php`, `bin/console`, `compose.whatsapp.yaml`, `Dockerfile`.

**Interfaces:** `AttachmentLinks::issue(int $attachmentId, int $contactId, int $now): string`; `resolve(string $token, int $now): ?Attachment`; `cleanup(int $now): int`. Криптографический токен >=32 random bytes; SQLite хранит hash, связи и expiry. Конфигурация `ATTACHMENTS_PATH`, `ATTACHMENTS_PUBLIC_BASE_URL`, `MAIL_MAX_MESSAGE_BYTES` с проверкой HTTPS/диапазона.

- [ ] RED: valid link отдаёт точные bytes/безопасный filename; unknown/expired token не раскрывает существование; traversal/range abuse не читает соседний файл; токен не появляется в логах; удаление невозможно пока файл закреплён живым объявлением.
- [ ] Выпустить ссылку с TTL 2 592 000 секунд от первой внешней отправки; не продлевать при retry. Публичный endpoint только GET/HEAD, nosniff, attachment disposition, no-store, без directory listing. Веб-процесс не имеет права изменять файлы; отключить логирование URL-токенов на proxy и в PHP.
- [ ] По capability канала и размеру MIME детерминированно выбрать direct/link до отправки, заморозить manifest. Большой email остаётся одним письмом с нужными ссылками. Unsupported fallback допустим лишь когда доказано, что прямой отправки не произошло.
- [ ] Очистку оформить устойчивым `attachment_cleanup` job (добавить nextJob/match/cascades). Срока недостаточно: учесть ссылки/черновики/живые jobs; истёкшие повторы прекращать с понятным статусом и уведомлением, а не отправлять мёртвый URL. Тестировать stale backup и отсутствующий blob.
- [ ] `vendor/bin/phpunit tests/Files tests/AttachmentDeliveryTest.php tests/Mail` и suite → PASS; commit `feat: provide expiring attachment download links`.

### 10. Миграция, ветки и проверка на тестовом контуре

**Create:** `docs/whatsapp/ROLLOUT-CONTACTS-FILES.md`.
**Modify:** `README.md`, `docs/whatsapp/APPLICATION.md`, `docs/IMPORT-USERS.md`, `VERIFICATION.md`, `compose.whatsapp.yaml`, `tools/whatsapp/verify.mjs`, `tools/whatsapp/LocalStand.php`.

**Interfaces:** включение directory mode явно, обычный Telegram без MySQL остаётся поддержан. До включения directory mode все локальные Telegram-контакты экспортированы и сверены; миграция не меняет processed updates, job IDs, part progress и active WhatsApp session.

- [ ] Прогнать миграцию на копии действующей SQLite с пользователями approved/pending/rejected/unsubscribed, черновиками и незавершёнными jobs. Сверить числа и связи до/после; завершённые доставки не возвращаются в pending. Проверить повтор миграции и восстановление.
- [ ] Сделать отдельные резервные копии SQLite, каталога MySQL и файлов с manifest/version. Описать согласованное восстановление и сверку operation IDs; нельзя откатить только SQLite и забыть о MySQL/истёкших ссылках. Перед rollback остановить процессы, новые принятые сообщения не переотправлять автоматически.
- [ ] Изолированный тест: MySQL, тестовый бот, тестовый WhatsApp-номер, отдельная SQLite/volume, обязательный allowlist. Рабочий team channel отключён. Секреты только private mounts/env; клиентскую аудиторию не использовать.
- [ ] Заполнить тестовые контакты: WhatsApp-only без почты, Telegram+WhatsApp с разными языками, все три канала, unsubscribed. Реальную Excel загрузить только после предоставления пользователем: dry-run → сверка количества/конфликтов → контролируемое применение. До этого использовать синтетические данные.
- [ ] Проверить настоящую отправку фото/PDF/DOCX/XLSX/MD/TXT, 10 файлов, files-only+subject, MIME fallback, HTTPS извне и истечение времени в автоматических тестах без sleep. Сверить байтовые hashes документов. Не тратить 30 дней ожидания на тест срока.
- [ ] Проверить разрыв WppConnect, перезапуск worker, MySQL outage, сбой после отправки до коммита, /unsubscribe между частями и повторное Telegram-одобрение. Внешние тесты только на явно тестовые контакты; стандартный PHPUnit не обращается к реальным API.
- [ ] Общие исправления/вложения перенести отдельными коммитами в `main` и `deepl-translate`, без WhatsApp/MySQL wiring; translator-specific файлы не уравнивать. Использовать отдельные worktrees при выполнении, не переключать ветку с незакоммиченными изменениями. `main` уже занят worktree: сначала определить его путь и состояние.
- [ ] Выполнить `composer test` отдельно в каждой изменённой ветке, `docker build --target test -t telegram-broadcast:test .`, `php bin/console check` с безопасно предоставленным окружением и проверку compose config без вывода подставленных секретов. Зафиксировать результаты, непройденные проверки и версии в VERIFICATION.md.
- [ ] Обновить русскую документацию по MySQL-полям, приоритету почты, ручному отключению/возврату, лимитам/ссылкам и запуску. Commit `docs: document contact directory and attachment rollout`. Производственный запуск выполнить отдельно после успешного тестового gate; не выдавать подготовленный код за выполненный ввод.

## Критерии завершения

- [ ] WhatsApp-клиент из phone+language получает новое объявление без анкеты и без email.
- [ ] Telegram сохраняет регистрацию, linking, прежнюю анкету после отписки и повторное одобрение.
- [ ] Разные языки каналов и приоритет Telegram-email подтверждены тестами.
- [ ] Один одобренный комплект приходит во все включённые каналы напрямую либо рабочей ссылкой; отсутствующие каналы пропущены.
- [ ] Нет повторов завершённых частей; неопределённые отправки видны отдельно; отписка блокирует остаток.
- [ ] Снимок и синхронизация восстанавливаются после межбазового сбоя; нет отправки до одобрения/полной публикации аудитории.
- [ ] Нет секретов и персональных данных в репозитории/логах; ссылки истекают, закрытые файлы не доступны обходным путём.
- [ ] Зелёные автоматические проверки всех затронутых веток и успешный тестовый прогон записаны в VERIFICATION.md.

## Самопроверка плана

Требования Q1–Q35 покрыты задачами 2–10, включая исключения Telegram Q6/Q28 и корректировку импорта Q14. Все новые сетевые вызовы имеют интерфейс и отдельную worker-фазу; новые jobs требуют обновления выборки, dispatch и completion cascades. Межбазовая атомарность не обещается: есть outbox/revisions/idempotency. Пять Review Focus привязаны к проверкам. Решения по точному началу TTL, удержанию брошенных черновиков и асинхронному моменту снимка явно отмечены в spec как технические уточнения, подлежащие просмотру перед реализацией.
