# WhatsApp: локальная проверка транспорта

Дата: 02.11.2026. Ветка: `broadcast-whatsapp`, создана от `deepl-translate` (`b7b9cf5`).
Исходное задание сохранено без изменений в [REQUEST.txt](REQUEST.txt).
Эта работа ограничена первым этапом: проверкой транспорта. Изменения модели пользователей,
регистрации, очереди PHP и миграций требуют результатов живого теста.

## Результат запуска 02.11.2026

**Этап не пройден: выбранный релиз запускается, но не выдаёт QR для подключения.**

- Образ скачан после первоначального сетевого тайм-аута Docker Hub. OCI label `org.opencontainers.image.revision` совпал с закреплённым commit; digest записан ниже и в Compose.
- Проверка контейнера: healthy; `/healthz`: HTTP 200. Авторизованный `start-session`: `INITIALIZING`, затем `status-session`: `CLOSED`.
- SQLite event API сохранил события `status-find` со значением `CLOSED`. QR endpoint вернул JSON отсутствия QR, PNG не создан.
- Причина подтверждена чтением stdout через фильтр, выводящий только известные категории: `outdated`. В закреплённом SDK [ClientOutdated](https://github.com/wppconnect-team/whatsmeow/blob/a7ea56383ec4/connectionevents.go) закрывает QR-канал; в [clientpayload.go](https://github.com/wppconnect-team/whatsmeow/blob/a7ea56383ec4/store/clientpayload.go) закреплена версия WA Web `2.3000.1040390703`. Полные строки, токены и QR в вывод не попадали, Docker-логи не сохранялись.
- Пользователь подтвердил готовность отдельного аккаунта, но аккаунт **не подключён**. Реальных отправок, входящих сообщений и проверок delivery/receipts не было.
- Контейнер штатно остановлен командой `stop`; проверено состояние `exited`, работающих контейнеров этого Compose-проекта нет. Docker Desktop остаётся запущенным; образ и отдельные тестовые данные сохранены.

Два моментальных замера Docker stats, **без подключённого аккаунта**:

| Момент | RAM контейнера | CPU |
|---|---|---|
| После запуска, до создания сессии | 6,508 MiB | 6,45% |
| После неуспешных попыток подключения | 17,93 MiB | 0,23% |

Это не пики и не измерение рабочего WhatsApp: переносить эти числа на будущий сервер нельзя.
Ресурсы Docker Desktop/WSL отдельно не измерялись.

**Следующее решение:** согласовать отдельный локальный опыт с обновлённым whatsmeow/версией
протокола внутри того же WPPConnect либо пересмотреть транспорт. Свежий SDK сам по себе
не гарантирует успешного подключения. Исходники транспорта и версия протокола в этой
итерации не исправлялись; альтернативный транспорт не подключался.

## Закреплённый кандидат

- [WPPConnect Server Go v0.1.10](https://github.com/wppconnect-team/wppconnect-server-go/releases/tag/v0.1.10), опубликован 01.11.2026.
- Commit: `cf1098f91c1a1e2028e5d864490f45c72f6b8358`.
- Образ для локального опыта: `wppconnect/wppconnect-server-go:v0.1.10`.
- Registry digest: `sha256:094427e6ec4ac13c76ff2b67de4001e4d9009aa20f27c559b144d618e5473d2f`; закреплён также в Compose.
- [go.mod](https://github.com/wppconnect-team/wppconnect-server-go/blob/cf1098f91c1a1e2028e5d864490f45c72f6b8358/go.mod): Go 1.26.10, pure-Go SQLite `modernc.org/sqlite`; fork whatsmeow закреплён на `a7ea56383ec4`.
- В upstream указана Apache-2.0; зависимости имеют собственные лицензии, включая MPL-2.0 у whatsmeow. Это сведения из [README/NOTICE](https://github.com/wppconnect-team/wppconnect-server-go/tree/cf1098f91c1a1e2028e5d864490f45c72f6b8358), не заключение о лицензировании продукта.
- [CI](https://github.com/wppconnect-team/wppconnect-server-go/blob/cf1098f91c1a1e2028e5d864490f45c72f6b8358/.github/workflows/ci.yml) проверяет Linux, образы amd64/arm64. Готовых Windows-бинарников в релизе нет. Native Windows-сборка здесь не проверена; локальный стенд использует Linux-контейнер Docker Desktop.

## Что подтверждено чтением исходников

Эти сведения описывают контракт исходного кода. Они сами по себе не доказывают работу с живым аккаунтом.

| Область | Найденное поведение | Следствие для PHP-интеграции |
|---|---|---|
| Авторизация | Bearer-токен связан с именем сессии и `SECRET_KEY`; есть offline `/app/token` | Не передавать секрет в URL генерации токена. Использовать заголовок Authorization |
| Сессии | SQLite, отдельные метаданные, автоматическое восстановление сохранённых активных сессий | Хранить весь каталог транспорта отдельно от SQLite PHP; проверять восстановление на реальном аккаунте |
| Готовность | `/healthz` и `/readyz` относятся к процессу/восстановлению | Доступность WhatsApp определять дополнительно по `status-session` |
| События | Текущий `cmd/server` подключает SQLite eventbus, `/events` и replay | Дедуплицировать ID события и сообщения; сохранять событие до подтверждения |
| Webhooks | `X-WPP-Event-ID`, `X-WPP-Attempt`; повтор сетевых ошибок, 408/425/429/5xx, ограниченное число попыток | Повтор webhook не должен повторять бизнес-действие |
| Авторизация webhook | В HTTP target нет подписи или настраиваемого Authorization | Варианты для исследования: закрытая сеть с отдельной проверкой либо чтение `/events` по Bearer. Выбор пока не сделан |
| Отправка текста | До 65 536 Unicode code points; HTTP JSON до 128 KiB; один запрос может содержать несколько получателей | Для приложения отправлять одному получателю за раз. Реальный предел и размер частей ещё проверить; не переносить 4096 Telegram |
| PN/LID | Есть `/contact/pn-lid/{pnLid}`, возвращающий доступное сопоставление; входящий `from/author` может быть LID | Не считать цифры LID телефоном. При отсутствии mapping нельзя автоматически подставить телефон анкеты |
| Ответ пользователю LID | `send-message` использует `destinations()`, допускающий PN/c.us и группы, но отвергающий `@lid` | Существенная проверка пригодности: сможет ли бот ответить новому пользователю до появления PN mapping |
| Результат отправки | Успешный HTTP-ответ содержит message ID; есть `onack`, история и `/message-receipts/{messageId}` | Разделять принятие и доставку; не превращать HTTP 201 в подтверждение получения |
| Неопределённая отправка | API не принимает клиентский message ID/idempotency key. На ошибке не сообщает ID неуспешного получателя. История пишется после успешного SDK-вызова | Потерянный ответ иногда можно сверить с историей/receipt, но доказуемая сверка не гарантирована. При неизвестном результате — остановка автоматического повтора и уведомление сотруднику |
| Повторы исходящих | HTTP-обработчик вызывает SDK один раз, отдельной долговечной очереди рассылок здесь не обнаружено. В SDK есть `retryFrame` и retry receipts | Нельзя обещать отсутствие внутренних повторов SDK. PHP не должен слепо повторять неоднозначный HTTP-запрос |
| Логи | Session manager создаёт INFO-логгер SDK; SDK содержит предупреждения с JID | В тестовом Compose отключено сохранение stdout/stderr (`logging: none`). Для будущего рабочего запуска нужен отдельный аудит/редактор логов |

Основные источники на закреплённом commit:

- [Запуск и eventbus](https://github.com/wppconnect-team/wppconnect-server-go/blob/cf1098f91c1a1e2028e5d864490f45c72f6b8358/cmd/server/main.go), [HTTP target](https://github.com/wppconnect-team/wppconnect-server-go/blob/cf1098f91c1a1e2028e5d864490f45c72f6b8358/internal/eventbus/targets.go).
- [API авторизации и сессий](https://github.com/wppconnect-team/wppconnect-server-go/blob/cf1098f91c1a1e2028e5d864490f45c72f6b8358/internal/httpapi/router.go), [менеджер сессий](https://github.com/wppconnect-team/wppconnect-server-go/blob/cf1098f91c1a1e2028e5d864490f45c72f6b8358/internal/session/manager.go).
- [Отправка текста](https://github.com/wppconnect-team/wppconnect-server-go/blob/cf1098f91c1a1e2028e5d864490f45c72f6b8358/internal/httpapi/message_actions.go), [проверка адресатов](https://github.com/wppconnect-team/wppconnect-server-go/blob/cf1098f91c1a1e2028e5d864490f45c72f6b8358/internal/httpapi/media.go), [ограничение текста](https://github.com/wppconnect-team/wppconnect-server-go/blob/cf1098f91c1a1e2028e5d864490f45c72f6b8358/internal/messages/actions.go).
- [Нормализация входящих](https://github.com/wppconnect-team/wppconnect-server-go/blob/cf1098f91c1a1e2028e5d864490f45c72f6b8358/internal/media/event.go), [PN/LID](https://github.com/wppconnect-team/wppconnect-server-go/blob/cf1098f91c1a1e2028e5d864490f45c72f6b8358/internal/httpapi/directory.go).
- [Receipts](https://github.com/wppconnect-team/wppconnect-server-go/blob/cf1098f91c1a1e2028e5d864490f45c72f6b8358/internal/notifications/protocol.go), [история](https://github.com/wppconnect-team/wppconnect-server-go/blob/cf1098f91c1a1e2028e5d864490f45c72f6b8358/internal/httpapi/history.go), [SDK send/retry](https://github.com/wppconnect-team/whatsmeow/blob/a7ea56383ec4/send.go).

Внимание при последующих проверках: старый раздел `docs/operation.pt-BR.md` описывает
очередь webhooks в памяти и помечен как устаревший. Текущий `cmd/server` использует другой,
долговечный eventbus. Наличие старого `internal/webhook/dispatcher.go` не означает,
что именно он обслуживает запущенный сервер.

## Граница с существующим PHP-приложением

Повторно проверены README, миграции 001–003, `Kernel`, `Registration`, маршруты `Worker`
и соответствующие точки `SqliteStore`. Сейчас Telegram ID одновременно служит `users.id`
и адресом служебных ответов; проверка собственного контакта также сравнивает его с ID.
Доставка уникальна по `(broadcast_id,user_id,channel)` — это полезная основа для единственного email.
Для WhatsApp потребуются отдельные привязки адресов, адресация ответов регистрации и снимок назначения.
Само число WhatsApp нельзя подставить в `users.id`. Текущий Worker перебирает Telegram/email,
поэтому добавления одного HTTP-клиента недостаточно.

Схема совместимой миграции, переиспользование регистрации, правила подтверждения привязок
и изменения отчётов здесь намеренно ещё не реализованы: это следующий этап после проверки транспорта.
Тексты `ui.json` не изменены. Рабочая база, `.env`, сервер и ветки `main`/`deepl-translate` не изменяются.

## Локальный стенд Windows

Скрипт [local.ps1](../../tools/whatsapp-spike/local.ps1) — исследовательский инструмент,
не часть `Kernel`/`Worker` и не инструкция деплоя. Подготовка и запуск разрешены только
в `broadcast-whatsapp`; остановка доступна и после переключения ветки/потери файла настроек.
Стенд использует отдельный Compose-проект `broadcast-whatsapp-spike`, API только на
`127.0.0.1:21465`, отдельную техническую SQLite, без подключения к ботам, email и основной БД.

```powershell
# В корне репозитория, только на broadcast-whatsapp:
.\tools\whatsapp-spike\local.ps1 prepare
.\tools\whatsapp-spike\local.ps1 start
.\tools\whatsapp-spike\local.ps1 status
.\tools\whatsapp-spike\local.ps1 pair
.\tools\whatsapp-spike\local.ps1 qr
```

`prepare` не перезаписывает существующий секрет. В Windows ограничивает доступ к новым
тестовым каталогам текущим пользователем, SYSTEM и администраторами; изменение ACL может
потребовать разрешения среды исполнения. `pair` получает токен offline внутри контейнера;
секрет не попадает в URL или вывод. `qr` сохраняет только PNG в `var/whatsapp-spike/qr.png`.
Откройте этот локальный файл и подключите **отдельный тестовый** WhatsApp через «Связанные устройства».
QR является временным секретом; не добавляйте его в отчёт или Git.

Приватные файлы:

- `secrets/whatsapp-spike/transport.env`: только отдельный случайный `SECRET_KEY`.
- `secrets/whatsapp-spike/session-token.json`: session-bound токен.
- `var/whatsapp-spike/data/`: все технические БД, история, события и данные сессии.

Оба дерева уже исключены из Git и Docker build context. Не выводите результат `compose config`
без `--quiet`, `docker inspect` без точного безопасного шаблона или целый ответ `status-session`:
они могут содержать секреты/QR. Скрипт печатает только известные статусы и классы ошибок.

Остановка сохраняет данные и не делает logout:

```powershell
.\tools\whatsapp-spike\local.ps1 stats
.\tools\whatsapp-spike\local.ps1 stop
.\tools\whatsapp-spike\local.ps1 start
.\tools\whatsapp-spike\local.ps1 status
```

Лимиты 1 CPU / 512 MiB — выбранные разработчиком ограничения опыта, **не замер потребления**.
Вывод `stats` — моментальный снимок, не доказательство пиков RAM/CPU; для приёмки нужны серии
замеров с интервалом и отдельные пики при связывании, отправке и восстановлении. Накладные
расходы Docker Desktop/WSL на ПК не равны ресурсам нативного процесса на будущем Linux-сервере.

В скрипте нет команды отправки сообщений. Она может появиться после подключения и явного
списка разрешённых получателей. Открытое локальное API само по себе не реализует allowlist;
это пока стенд подключения, а не готовая безопасная утилита рассылки.

## Что осталось подтвердить живым тестом

- QR-подключение, статус CONNECTED и восстановление после перезапуска.
- Новый входящий личный чат: namespace message/event IDs, PN/LID и наличие подтверждённого mapping.
- Только разрешённым получателям: кириллица, акценты, emoji, переносы и длинный текст; получение на телефоне и receipts.
- Повтор webhook/replay, отказ приёмника и подтверждение после фиксации события.
- Отключение сети, несколько дней ожидающих заданий и порядок восстановления (очередь PHP будет проверяться позднее с фейками времени).
- Потеря HTTP-ответа после отправки: что реально можно сверить по истории/receipt; неизвестное не повторять автоматически.
- Серии и пики RAM/CPU в простое, при подключении, обмене и восстановлении.

До этих проверок рекомендация ограничена продолжением локального исследования.
Пригодность WPPConnect для общей регистрации и рассылок пока не доказана, переход к большой
переработке PHP и деплою не разрешён результатами этого этапа.
