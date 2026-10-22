# Telegram Broadcast

Бот регистрирует пользователей, переводит объявления и отправляет их в Telegram и на email.
PHP 8.4+. SQLite. Два процесса: `poll` принимает сообщения, `worker` выполняет задания.

## Возможности

- Языки: RU, EN-GB, ES, FR.
- Суперадминистратор одобряет заявки пользователей.
- Администратор передаёт объявление на согласование.
- Суперадминистратор запускает собственное объявление из предпросмотра.
- Тема и текст письма переводятся. Каждый получатель получает отдельное письмо.
- Telegram и email работают независимо. Автор не получает собственную рассылку.
- Канал команды получает русские тему и текст.
- Одобривший суперадминистратор получает подробный отчёт и ошибки перевода.
- Новый одобренный пользователь получает последнее готовое объявление.

Без одобрения рассылка не начинается. Исходный текст не заменяет отсутствующий перевод.
Успех email означает принятие письма сервисом. Он не подтверждает получение во входящие.

## Настройка

1. Скопируйте `.env.example` в `.env`.
2. Заполните значения. Не сохраняйте секреты в Git.
3. Выберите почтовый транспорт.

| Настройка | Назначение |
|---|---|
| `TELEGRAM_BOT_TOKEN` | Токен бота. |
| `TELEGRAM_ADMIN_IDS`, `TELEGRAM_SUPERADMIN_IDS` | Положительные Telegram ID через запятую. |
| `TELEGRAM_TEAM_CHANNEL_ID` | ID канала `-100…`. Пустое значение отключает новые публикации. |
| `TRANSLATOR_API_KEY`, `TRANSLATOR_API_URL` | Ключ и адрес переводчика. |
| `DATABASE_PATH` | Путь к SQLite. В Docker: `/data/broadcast.sqlite`. |
| `APP_ENV` | `production`. Значение `test` используйте только для локального SMTP-приёмника. |
| `MAIL_TRANSPORT` | `smtp` или `gmail`. По умолчанию `smtp`. |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Адрес и имя отправителя. |
| `MAIL_REPLY_TO` | Адрес для ответа. Пустое значение использует адрес отправителя. |
| `MAIL_SEND_INTERVAL_SECONDS` | Пауза между письмами: 1–86400 секунд. По умолчанию 2. |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION` | Для SMTP. Шифрование: `starttls` или `tls`. |
| `SMTP_USERNAME`, `SMTP_PASSWORD` | Доступ к SMTP. |
| `GMAIL_OAUTH_CLIENT_FILE`, `GMAIL_OAUTH_TOKEN_FILE` | Для Gmail. Пути к приватным JSON. |
| `ADMINS_CAN_APPROVE_USERS`, `ADMINS_CAN_EXPORT_USERS` | Дополнительные права администраторов. По умолчанию `false`. |

Ветка `deepl-translate` использует DeepL. Адрес: `https://api-free.deepl.com` или `https://api.deepl.com`, без пути. Модель и температура не используются.

Настройте [Gmail OAuth](docs/GMAIL.md) до запуска режима Gmail.
Подключите [канал команды](docs/TEAM-CHANNEL.md), если нужна история объявлений.
Бот должен иметь право публикации в канале.
Пользователи и сотрудники должны сначала открыть личный чат с ботом.
Отключите webhook перед запуском long polling.

## Запуск в Docker с Gmail

Нужны Docker Engine и Compose. Входящие порты и домен не нужны.
Разрешите исходящий HTTPS к Telegram, переводчику и Google.
Поместите OAuth-файлы в `secrets/gmail-client.json` и `secrets/gmail-token.json`.

```bash
docker compose -f compose.yaml -f compose.gmail.yaml config --quiet
docker build --target test -t telegram-broadcast:test .
docker compose -f compose.yaml -f compose.gmail.yaml build
docker compose -f compose.yaml -f compose.gmail.yaml run --rm poll check
docker compose -f compose.yaml -f compose.gmail.yaml run --rm poll migrate
docker compose -f compose.yaml -f compose.gmail.yaml run --rm poll commands
docker compose -f compose.yaml -f compose.gmail.yaml up -d --no-build
```

Для SMTP уберите `-f compose.gmail.yaml`. Разрешите соединение с SMTP-сервером.
Для локальной проверки используйте Mailpit:

```bash
docker compose -f compose.yaml -f compose.test.yaml up -d --build
```

Письма Mailpit доступны по адресу `http://127.0.0.1:8025`.

## Запуск без Docker

Нужны Composer и расширения PHP: curl, fileinfo, intl, mbstring, pdo_sqlite, sqlite3, zip, XML/DOM.
В Linux установите pcntl для штатной остановки.
Передайте переменные окружения обоим процессам. Приложение не читает `.env` автоматически.
В Supervisor укажите одинаковые `DATABASE_PATH` и системного пользователя для обоих процессов.

```bash
composer install --no-dev
php bin/console check
php bin/console migrate
php bin/console commands
```

Запускайте через Supervisor или systemd:

```bash
php bin/console poll
php bin/console worker
```

Запускайте только один экземпляр каждой роли. Не используйте один токен для двух установок бота.

## Проверка и обновление

```bash
docker compose -f compose.yaml -f compose.gmail.yaml ps
docker compose -f compose.yaml -f compose.gmail.yaml logs --tail=100 -f
```

Логи содержат JSON-события, ID и статусы. Данные анкет, тексты и секреты не записываются.
Docker хранит три файла логов по 10 МБ на процесс.

Перед обновлением сохраните копию базы. Соберите образы до остановки процессов.
Если тесты или сборка не прошли, остановите обновление.

```bash
docker compose -f compose.yaml -f compose.gmail.yaml run --rm worker backup
docker build --target test -t telegram-broadcast:test .
docker compose -f compose.yaml -f compose.gmail.yaml build
docker compose -f compose.yaml -f compose.gmail.yaml run --rm poll check
docker compose -f compose.yaml -f compose.gmail.yaml stop poll worker
docker compose -f compose.yaml -f compose.gmail.yaml run --rm poll migrate
docker compose -f compose.yaml -f compose.gmail.yaml up -d --no-build
```

После смены `.env` пересоздайте контейнеры через `up -d --no-build --force-recreate`.
После смены ролей выполните `run --rm poll commands` с теми же Compose-файлами.
Команда `restart` не обновляет окружение. Команда `up` не пересобирает образ.
Сохраните имя Compose-проекта `telegram-broadcast`, чтобы подключить прежний том базы.

## База и восстановление

SQLite хранит пользователей, объявления и очередь в одном файле.
Docker использует том `bot-data`. Удаление контейнеров сохраняет том.
**Не выполняйте `docker compose down -v`: команда удаляет данные и копии в томе.**

Worker создаёт ежедневные копии в `<каталог базы>/backups`. В Docker это `/data/backups`.
Обычные копии хранятся семь дней. Переносите их на отдельный защищённый носитель.

Для восстановления остановите оба процесса. Укажите существующую копию:

```bash
docker compose -f compose.yaml -f compose.gmail.yaml stop poll worker
docker compose -f compose.yaml -f compose.gmail.yaml run --rm worker restore /data/backups/ИМЯ_КОПИИ.sqlite --confirm
docker compose -f compose.yaml -f compose.gmail.yaml run --rm poll migrate
docker compose -f compose.yaml -f compose.gmail.yaml up -d --no-build
```

Restore сохраняет текущую базу перед заменой. Изменения после выбранного снимка будут потеряны.
Не копируйте активные SQLite/WAL-файлы вручную.

- [Очистка тестовых данных](docs/DATABASE-RESET.md).
- [Восстановление пользователей из Excel](docs/IMPORT-USERS.md).

## Команды Telegram

| Команда | Действие |
|---|---|
| `/start`, `/language`, `/help` | Анкета, язык, справка. |
| `/requests [ID]` | Нерешённые заявки или заявка по ID. |
| `/draft ID` | Черновик объявления. |
| `/retry ID` | Повтор неуспешных заданий рассылки. Успешные части сохраняются. |
| `/retryjob ID` | Повтор неуспешного комментария или выгрузки Excel. |
| `/userdata` | Выгрузка отправленных заявок в Excel. |
| `/unsubscribe ID` | Отписка от обоих каналов. Только суперадминистратор. |

Доступ зависит от роли. `/retry` своей рассылки доступен автору.
Блокировка бота отключает только Telegram. Для отключения email нужна отписка.

## Тесты

```bash
composer install
composer test
```

В Windows при отключённых расширениях:

```powershell
php -d extension=intl -d extension=zip -d extension=fileinfo vendor/bin/phpunit
```

Тесты не вызывают внешние API.
Перед рабочим запуском проверьте регистрацию, одобрение, переводы, получение email и пост в канале.
Результаты проверок: [VERIFICATION.md](VERIFICATION.md).
История развёртывания: [деплой 5 октября 2026 года](docs/DEPLOYMENT-2026-10-05.md).
