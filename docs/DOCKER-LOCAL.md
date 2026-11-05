# Локальная проверка DeepL в Docker

Выполняйте команды из PowerShell в папке с веткой `deepl-translate`. Docker собирает содержимое текущей папки. Проверьте ветку командой `git branch --show-current`.

Используйте отдельного тестового Telegram-бота в `.env`. Для одного токена должен работать только один poller. Mailpit заменяет реальную почту, но не отключает Telegram и DeepL. Исходящие запросы к ним остаются реальными.

## Изоляция от WhatsApp и других локальных запусков

```powershell
cd D:\telegram-bot-source\telegram-bot
$env:BOT_IMAGE = 'telegram-broadcast:deepl-local'
$env:MAILPIT_WEB_PORT = '8026'
$dc = @('compose', '-p', 'telegram-deepl-test', '-f', 'compose.yaml', '-f', 'compose.test.yaml')
```

Это выбирает отдельный тег образа, отдельные контейнеры и том `telegram-deepl-test_bot-data`. Mailpit доступен по адресу http://127.0.0.1:8026. Прежние значения по умолчанию сохранены: образ `telegram-broadcast:local`, порт 8025.

## Сборка и первый запуск

Выполняйте команды по одной. При ошибке не переходите к следующей.

```powershell
docker build --target test -t telegram-broadcast:deepl-test .
docker @dc build
docker @dc run --rm --no-deps --entrypoint php poll bin/console help
docker @dc up -d mailpit
docker @dc run --rm --no-deps poll check
docker @dc run --rm --no-deps poll migrate
docker @dc up -d --no-build
docker @dc logs -f --tail 100 poll worker
```

Проверка `help` загружает autoload, но не запускает бота и не обращается к БД или API. Миграция требует, чтобы оба процесса этого тестового проекта были остановлены. Если проект уже запущен, сначала выполните `docker @dc stop poll worker`.

Чтобы проверить историю, одобрите шесть рассылок. Затем зарегистрируйте и одобрите другой аккаунт, который не является их автором. Ожидаются последние пять рассылок в Telegram и пять писем в Mailpit.

Остановка с сохранением базы: `docker @dc down`. Не добавляйте `-v`, если база ещё нужна.

## Ошибка отсутствующего vendor/autoload.php

При диагностике 2026-11-05 в образе обнаружен пустой файл `/usr/local/bin/composer` размером 0 байт. Команды `composer install` и `composer test` завершались без результата. В контрольной сборке без кэша копировался исправный Composer. Это указывает на повреждённый кэш BuildKit; точная причина его появления не установлена.

Dockerfile теперь копирует бинарный образ `composer/composer:2-bin`, проверяет непустой файл и запускает Composer через PHP. После установки проверяется autoload. PHPUnit запускается напрямую через PHP, чтобы пустая команда Composer не могла скрыть отсутствие тестов. Такой источник Composer рекомендован в https://getcomposer.org/doc/00-intro.md#docker-image.

После такой ошибки выполните один раз:

```powershell
docker build --no-cache --target test -t telegram-broadcast:deepl-test .
docker @dc build --no-cache
```

Не требуется удалять тома, очищать весь Docker или перезапускать WhatsApp. Не запускайте старый неуспешный образ до завершения исправленной сборки.
