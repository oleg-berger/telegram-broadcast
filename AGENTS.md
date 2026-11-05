# AGENTS.md

Многоязычный Telegram-бот рассылок на PHP 8.4 и SQLite, без фреймворков. Пользователи проходят регистрацию с одобрением; администратор готовит объявление, суперадминистратор одобряет; бот переводит через DeepL API и рассылает в Telegram и на email (SMTP). Два процесса: `poll` (приём updates) и `worker` (фоновая очередь). Дополнительных серверов очередей нет.

## Структура

- `src/Application` — логика: `Kernel` (роутинг updates), `Registration`, `Staff`, `Worker`, `Messages` + `ui.json` (заранее переведённые UI-тексты на RU/EN-GB/ES/FR). Интерфейсы внешних зависимостей: `Store`, `TelegramGateway`, `Translator`, `MailGateway`, `UserExporter`.
- `src/Domain` — `TextParts` (деление длинных текстов), `PhoneNumber` (нормализация и страна по коду), `ApiFailure` (классификация ошибок API: transient/retryAfter/blocked).
- `src/Infrastructure` — `SqliteStore` (единственная реализация Store), `Http` (TelegramClient, DeepLTranslator, CurlHttpClient), `Mail/SmtpMailer` (PHPMailer), `Mail/GmailApiMailer` (Gmail API OAuth), `GoogleOAuthFiles` (приватные JSON), `GmailAuthorization` (локальная настройка Desktop OAuth), `Export/XlsxUsers` (OpenSpout), `Runtime` (Config, Poller, ProcessLock, DailyBackup, SqliteBackup, Log, StopSignal, CommandMenu).
- `migrations` — SQL-миграции по порядку номеров; применяет `SqliteStore::migrate()` (каждая в своей строке таблицы `migrations`).
- `bin/gmail-authorize CLIENT_JSON TOKEN_JSON` — разовая локальная авторизация Gmail Desktop app оператором (browser + loopback/PKCE), без отправки писем; инструкция docs/GMAIL.md. Секреты хранятся отдельно в secrets, исключены из Git и образа.
- `bin/console` — единая точка входа: `poll`, `worker`, `migrate`, `check`, `commands`, `backup`, `restore`.
- `tests` — PHPUnit 11, фейки внешних API внутри тестовых файлов.

## Команды

```bash
composer install
composer test                 # весь набор PHPUnit
php bin/console check         # валидация .env без запуска и обращений к API
php bin/console migrate       # применить миграции
php bin/console commands      # записать меню команд Telegram
php bin/console poll|worker [--once]
```

Локально на Windows нужны включённые расширения `intl`, `zip`, `fileinfo` (в `php.ini` могут быть отключены — тогда запуск тестов через `php -d extension=intl -d extension=zip -d extension=fileinfo vendor/bin/phpunit`).

Docker:

```bash
docker build --target test -t telegram-broadcast:test .   # сборка с прогоном тестов внутри
docker compose build
docker compose run --rm poll migrate
docker compose run --rm poll commands
docker compose up -d                                      # poll + worker
docker compose -f compose.yaml -f compose.test.yaml up -d # + Mailpit (тестовый SMTP)
```

`docker compose up -d` сам не пересобирает образ — после изменения кода сначала `docker compose build`. Изменения только `.env` подхватываются пересозданием контейнеров (`docker compose up -d`); после смены ролей/флагов дополнительно `docker compose run --rm poll commands`.

## Конфигурация

`TELEGRAM_TEAM_CHANNEL_ID` — необязательный полный отрицательный Bot API ID канала `-100…`; пустое значение отключает новые публикации. Канал фиксируется снимком при одобрении. RU-переводы только для канала не должны отменять пользовательские доставки; результат `channel_post` учитывается отдельно в отчёте. Подробности: docs/TEAM-CHANNEL.md.

Читается только из переменных окружения (загрузчика `.env` нет, Compose подставляет файл сам). Обязательные ключи: `TELEGRAM_BOT_TOKEN`, `TELEGRAM_ADMIN_IDS`, `TELEGRAM_SUPERADMIN_IDS`, `TRANSLATOR_API_KEY`, `TRANSLATOR_API_URL` (`https://api-free.deepl.com` или `https://api.deepl.com`, без пути; модель и температура не используются), `DATABASE_PATH`, `MAIL_FROM_ADDRESS/NAME`. `MAIL_TRANSPORT=smtp` по умолчанию требует `SMTP_HOST/PORT/ENCRYPTION/USERNAME/PASSWORD`; `MAIL_TRANSPORT=gmail` требует `GMAIL_OAUTH_CLIENT_FILE` и `GMAIL_OAUTH_TOKEN_FILE` — пути к приватным JSON OAuth-клиента и refresh token. Файлы читаются без сети при check; секреты не печатаются. Опциональные: `APP_ENV` (`production` по умолчанию; `test` разрешает `SMTP_ENCRYPTION=none` и пустые почтовые доступы — только локальный приёмник), `ADMINS_CAN_APPROVE_USERS`, `ADMINS_CAN_EXPORT_USERS` (`true`/`false`), `MAIL_REPLY_TO`, `MAIL_SEND_INTERVAL_SECONDS` (1–86400, по умолчанию 2). Значения с префиксом `replace_with_` отклоняются. `Config` бросает одно общее сообщение без значений секретов.

## Ключевые инварианты — не нарушать

- Один update = одна короткая транзакция в `Kernel` (приём update, изменения анкеты/черновика, постановка заданий). Повторный update игнорируется (`processed_updates`).
- Вся внешняя активность — через таблицу `jobs` и `Worker::tick()` (одна операция за тик). Известные `kind`: `message`, `photo`, `callback`, `translate`, `translate_subject`, `delivery` (каналы `telegram`/`email`), `channel_post` (русская тема и текст в командный канал), `comment`, `export`. Новые виды добавлять и в `nextJob`, и в `match` Worker'а, и в каскады `refreshBroadcasts` при необходимости.
- Рассылка начинается только после одобрения черновика суперадминистратором; аудитория и языки фиксируются снимком на момент одобрения. Кнопки привязаны к `id:version` / `id:revision` — старые кнопки обязаны оставаться безопасными.
- Исходный текст никогда не подменяет отсутствующий перевод (объявления и комментарии). Ошибки перевода → повторы, затем уведомление сотруднику; оригинал не отправляется.
- Telegram и email — независимые задания; сбой одного канала не отменяет другой. Блокировка бота отключает только Telegram-доставку (`subscribed=0`), одобрение и почта продолжаются.
- Повторы: до 5 попыток с экспоненциальной задержкой, `retry_after` Telegram — нижняя граница. Успешные доставки и отправленные части (`advancePart`) не повторяются. `/retry ID` — только неуспешные задания одобренной рассылки; `/retryjob ID` — неуспешные `comment`/`export` с проверкой владельца.
- Доставки (включая догоняющие новым пользователям) входят в отчёт (`report_included=1`); отчёт отдельно по Telegram и email.
- В логи (JSON в stdout через `Log`) — только ID, статусы, классы исключений. Никаких анкет, адресов, телефонов, токенов, полных текстов. Ошибки API классифицируются через `ApiFailure` без прокидывания сообщений upstream. При добавлении кода проверяйте, что тексты ошибок не утекают в лог/БД.
- Телефон и email уникальны среди отправленных заявок (таблица `contacts`, email `COLLATE NOCASE`), включая отклонённые; черновик анкеты контакты не резервирует.
- Отписка суперадминистратором (`/unsubscribe ID`) переводит одобренного пользователя в статус `unsubscribed`: прекращаются оба канала и включение в новые рассылки; повторная подача заявки возможна и снова требует одобрения.
- Все пользовательские UI-тексты — только через `Messages::text()` (ui.json), регистрация не зависит от API перевода.
- Присваивать свойства PHPMailer только существующие (динамические свойства — deprecation → падение worker под строгим обработчиком ошибок; сторожит `SmtpMailerTest`).

## Стиль кода

- Общие исправления вносить в обе ветки: `main` и `deepl-translate`. Логика приложения должна совпадать; различаются только интеграция переводчика, её настройки, тесты и соответствующая документация. Проверять `composer test` отдельно в каждой ветке.

- `declare(strict_types=1);` в каждом файле, PSR-4 `Broadcast\`, типизированные сигнатуры, без фреймворков и ORM (подготовленные запросы PDO).
- Компактные методы, ранние return, скобки `{}` даже для одной строки в новых файлах Application. Комментарии — по-английски, только там, где неочевидно «почему».
- Новые внешние вызовы — через интерфейс в `src/Application` + реализация в `src/Infrastructure` + фейк в тестах.
- Документация и пользовательские тексты — по-русски (как README); коммиты и код — по-английски.

## Тесты

- `composer test` — обязателен зелёный прогон перед завершением работы. В Docker образе `test` тесты выполняются при сборке.
- Фейки (`FakeTelegram`, `FakeTranslator`, `FakeMailer`, `FakeExporter`, `FakeHttpClient`) живут в тестовых файлах; реальные API в тестах не вызываются. Время в `Worker::tick($now)` контролируемое — используйте его вместо sleep.
- При изменении поведения обновляйте/добавляйте тесты; существующие сценарии не ослаблять.
- `VERIFICATION.md` — обновлять итоги проверок после значимых итераций.

## Запрещено

- Коммитить `.env` или любые секреты; читать/печатать содержимое `.env` в открытую (только статусы ключей).
- Встраивать почтовые доступы или токены в образ/логи/код.
- Менять применённые миграции задним числом — только новые файлы с следующим номером.
- Подменять перевод оригиналом, писать персональные данные в логи, обходить одобрение заявок/рассылок.
