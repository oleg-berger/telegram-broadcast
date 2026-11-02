# WhatsApp: локальная проверка транспорта

Дата: 02.11.2026. Ветка: `broadcast-whatsapp`, создана от `deepl-translate` (`b7b9cf5`).
Исходное задание сохранено без изменений в [REQUEST.txt](REQUEST.txt).
Эта работа ограничена первым этапом: проверкой транспорта. Изменения модели пользователей,
регистрации, очереди PHP и миграций требуют результатов живого теста.

## Результат после обновления 02.11.2026

**QR получен: локальная сессия перешла из `INITIALIZING` в `QRCODE`.**

- Запущен образ `broadcast-whatsapp-transport:cf1098f-9d2171c`, ID `sha256:07b6d9bb57ddce828e9281c76c2f60c538f8756d521afc2ca85763600e6c1ea6`; проверены revision SDK и label `frame-fix=true`.
- Контейнер `running/healthy`, `/healthz`: HTTP 200. `local.ps1 qr` сохранил PNG: **244×244, 1779 байт** при первой успешной проверке. Подпись PNG проверена; содержимое QR не включается в документацию или Git.
- Пройдены полный `go test ./...`, новый тест передачи WebSocket-кадров и `composer test` (**304 теста, 2086 проверок**).
- На момент проверки статус — `QRCODE`; подключение телефона и `CONNECTED` ещё не подтверждены. Отправок сообщений не было. Стенд оставлен запущенным для сканирования.

QR временный. Для нового изображения выполните `pair`, затем `qr` из инструкции ниже
и заново откройте `var/whatsapp-spike/qr.png`. Этап проверки транспорта в целом ещё
не завершён: требуется привязка телефона, восстановление сессии и согласованные тесты сообщений.

## Первый запуск исходного релиза 02.11.2026

**Исходный релиз запускается, но не выдаёт QR для подключения.**

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

После этого запуска пользователь поручил обновить WhatsApp-клиент внутри того же
локального стенда и повторить проверку QR. Параметры обновления приведены ниже.

## Закреплённый кандидат

- [WPPConnect Server Go v0.1.10](https://github.com/wppconnect-team/wppconnect-server-go/releases/tag/v0.1.10), опубликован 01.11.2026.
- Commit: `cf1098f91c1a1e2028e5d864490f45c72f6b8358`.
- Исходный образ: `wppconnect/wppconnect-server-go:v0.1.10`.
- Registry digest: `sha256:094427e6ec4ac13c76ff2b67de4001e4d9009aa20f27c559b144d618e5473d2f`; закреплён в Dockerfile как основа runtime.
- [go.mod](https://github.com/wppconnect-team/wppconnect-server-go/blob/cf1098f91c1a1e2028e5d864490f45c72f6b8358/go.mod): Go 1.26.10, pure-Go SQLite `modernc.org/sqlite`; fork whatsmeow закреплён на `a7ea56383ec4`.
- В upstream указана Apache-2.0; зависимости имеют собственные лицензии, включая MPL-2.0 у whatsmeow. Это сведения из [README/NOTICE](https://github.com/wppconnect-team/wppconnect-server-go/tree/cf1098f91c1a1e2028e5d864490f45c72f6b8358), не заключение о лицензировании продукта.
- [CI](https://github.com/wppconnect-team/wppconnect-server-go/blob/cf1098f91c1a1e2028e5d864490f45c72f6b8358/.github/workflows/ci.yml) проверяет Linux, образы amd64/arm64. Готовых Windows-бинарников в релизе нет. Native Windows-сборка здесь не проверена; локальный стенд использует Linux-контейнер Docker Desktop.

## Локальная сборка с обновлённым клиентом

[Dockerfile](../../tools/whatsapp-spike/Dockerfile) сохраняет исходники HTTP-сервера на
`cf1098f91c1a1e2028e5d864490f45c72f6b8358` и заменяет зависимость whatsmeow на
[`9d2171c39a1037e867d7df1b9188757d27634918`](https://github.com/wppconnect-team/whatsmeow/commit/9d2171c39a1037e867d7df1b9188757d27634918)
от 01.11.2026 (`v0.0.0-20261101180530-9d2171c39a10`). В её
[clientpayload.go](https://github.com/wppconnect-team/whatsmeow/blob/9d2171c39a1037e867d7df1b9188757d27634918/store/clientpayload.go)
версия WA Web — `2.3000.1052556849`.

- Архив исходников сервера проверяется по SHA-256 `da33c2e1c24a5d7122cd50b6430f6b26488359d88cc4c5eb1f903c0136909582`.
- Builder: Go 1.26.10 Alpine, digest `sha256:a2012b4a1d1f9f7e9f44e0d39b805a901e856b1dd80b4e6ee4338db547050308`; автоматическая смена Go toolchain отключена.
- Сборка выполняет `go mod tidy`, `go test ./...`, пересобирает сервер и offline token helper, обновляет уведомления о лицензиях зависимостей.
- [compat.patch](../../tools/whatsapp-spike/compat.patch) адаптирует `GetBlocklist` и `UpdateBlocklist` к новому аргументу SDK: пустой hash сохраняет безусловные операции со списком. Тестовые медиа используют реальную расшифровку и запись SDK с подставным HTTP; публичная маршрутизация `DownloadToFile` и обнаружение media-host через живую WhatsApp-сессию этими offline-фикстурами не проверяются. Проверки целостности, HTTP-ошибок и содержимого сохранены.
- Старый строковый `SetStatusMessage` преобразуется в `types.SetStatusInput{Text: &text}`; тестовый клиент аккаунта использует тот же адаптер. Живые изменения профиля и списка блокировок при проверке QR не выполняются.
- В новом SDK обнаружен отдельный дефект [`socket.FrameSocket.SendFrame`](https://github.com/wppconnect-team/whatsmeow/blob/9d2171c39a1037e867d7df1b9188757d27634918/socket/framesocket.go): после выделения только заголовка и трёх байтов длины `copy` получает пустой срез назначения, поэтому payload не отправляется. [sdk-frame.patch](../../tools/whatsapp-spike/sdk-frame.patch) заменяет эту операцию на `append` в vendored SDK, сохраняя проверки размера. Изменённый файл с уведомлением MPL включён в `/app/third_party/whatsmeow/framesocket.go`.
- Регрессионный тест [framesocket_test.go](../../tools/whatsapp-spike/framesocket_test.go) проверяет реальные байты двух последовательных кадров через локальный WebSocket. На исходном SDK он воспроизвёл **7 вместо 11** и **3 вместо 303** байт; после исправления проходит. Подключений к WhatsApp в нём нет.
- Compose использует локальный образ `broadcast-whatsapp-transport:cf1098f-9d2171c`. `start` не скачивает и не пересобирает образ: после изменения Dockerfile нужен отдельный `build`.
- Перед обновлением остановлена только тестовая служба, её каталог `data` скопирован в приватный `var/whatsapp-spike/backups/before-client-update-<UTC>/data`.

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
.\tools\whatsapp-spike\local.ps1 build
.\tools\whatsapp-spike\local.ps1 start
.\tools\whatsapp-spike\local.ps1 status
.\tools\whatsapp-spike\local.ps1 pair
.\tools\whatsapp-spike\local.ps1 qr
```

`prepare` не перезаписывает существующий секрет. В Windows ограничивает доступ к новым
тестовым каталогам текущим пользователем, SYSTEM и администраторами; изменение ACL может
потребовать разрешения среды исполнения. `pair` получает токен offline внутри контейнера;
секрет не попадает в URL или вывод. `qr` проверяет переход `INITIALIZING` в `QRCODE` в течение
30 секунд (последний HTTP-запрос может занять ещё до 10 секунд)
и сохраняет проверенный PNG в `var/whatsapp-spike/qr.png`. При уже подключённом аккаунте
выводит `CONNECTED`. При закрытой сессии нужно снова выполнить `pair`, затем `qr`.
Откройте этот локальный файл и подключите **отдельный тестовый** WhatsApp через «Связанные устройства».
QR является временным секретом; не добавляйте его в отчёт или Git.

Приватные файлы:

- `secrets/whatsapp-spike/transport.env`: только отдельный случайный `SECRET_KEY`.
- `secrets/whatsapp-spike/session-token.json`: session-bound токен.
- `var/whatsapp-spike/data/`: все технические БД, история, события и данные сессии.

Оба дерева уже исключены из Git и Docker build context. Не выводите результат `compose config`
без `--quiet`, `docker inspect` без точного безопасного шаблона или целый ответ `status-session`:
они могут содержать секреты/QR. Скрипт печатает только известные статусы, статические коды
причин и классы ошибок.

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
