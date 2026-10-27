# NetBox → Zabbix: синхронизация устройств

`netbox_zabbix_sync.py` приводит хосты Zabbix к устройствам NetBox. NetBox — источник истины,
Zabbix — цель. NetBox читается через `pynetbox`, Zabbix — через JSON-RPC API (Zabbix 6.0+,
основная целевая версия 7.0).

| NetBox | Zabbix |
|---|---|
| `name` | видимое имя хоста; техническое имя — то же, недопустимые символы заменяются на `_` |
| `primary_ip4` | IP главного интерфейса (agent по умолчанию, у правила шаблона может быть SNMP) |
| сайт, роль | группы `NetBox/Sites/<site>`, `NetBox/Roles/<role>` (шаблоны имён настраиваются) |
| модель (`device_type.model`) | шаблоны по правилам `templates.rules` |
| статус `active` | хост создаётся / обновляется / включается |
| статус `decommissioning` | предлагается режим обслуживания (MM) |
| `staged`, `offline`, `planned`, `failed`, `inventory` | хост деактивируется |
| устройство удалено | хост деактивируется (не удаляется: история сохраняется) |

## Как это работает

```
            ┌────────────── cron / systemd timer (sync) ─────────────┐
NetBox ──── │ 1. GET /api/dcim/devices/ (все статусы, фильтры конфига)│
  │ webhook │ 2. Zabbix: справочники (группы, шаблоны, прокси, MM)    │ ──► Zabbix API
  └───────► │ 3. Zabbix: хосты с тегом netbox_sync=managed            │
  (serve)   │ 4. План: sync / disable / maintenance по статусу        │
            │ 5. create / update (только отличия) → disable → MM      │
            │ 6. Итог в лог и last_run.json                           │
            └─────────────────────────────────────────────────────────┘
```

1. Скрипт читает устройства NetBox **во всех статусах** (статус нужен, чтобы понять, что делать
   с хостом). Если NetBox недоступен или вернул ошибку, запуск прерывается **до** любых изменений
   в Zabbix: недоступность NetBox не превращается в массовую деактивацию.
2. Из Zabbix одним запросом на справочник читаются группы, шаблоны, прокси и созданные скриптом
   обслуживания.
3. Читаются «свои» хосты: с тегом `netbox_sync=managed`. Связь с устройством — тег
   `netbox_device_id`, поэтому переименование устройства в NetBox переименует хост, а не создаст
   новый. Хосты без этого тега (созданные вручную) скрипт не трогает. Если хост с нужным именем
   уже есть, но без тега, он пропускается с предупреждением (`zabbix.adopt_existing: true` —
   взять под управление).
4. Для каждого устройства по статусу выбирается действие (`status_actions`).
5. `sync`: строится желаемое состояние хоста и сравнивается с текущим; в API уходят **только
   изменения**, повторный запуск без изменений в NetBox ничего не пишет. В лог пишется, что
   изменилось: `Обновлён хост srv-01: IP 10.0.0.1 -> 10.1.1.1; группы +[NetBox/Roles/DB] -[...]`.
   Группы и шаблоны, добавленные вручную (вне `managed_prefixes` и не упомянутые в конфиге),
   и чужие теги сохраняются.
6. `disable`: хост переводится в состояние *Disabled*, в тег `netbox_status` пишется причина
   (`offline`, `deleted`, ...). Перед деактивацией срабатывает защита: если к деактивации больше
   `safety.max_disable_count` хостов или больше `safety.max_disable_ratio` от всех управляемых,
   деактивация не выполняется, запуск завершается с кодом 2 (обойти: `--force`).
7. `maintenance` (decommissioning): см. ниже.
8. Ошибка одного хоста (например, неверный параметр шаблона) записывается в лог и итог, остальные
   хосты синхронизируются. Недоступность Zabbix прерывает запуск.

## Режим обслуживания для выводимых из эксплуатации устройств

Устройство в статусе `decommissioning` ещё стоит в стойке, но работы с ним уже идут, и тревоги
по нему не нужны. Деактивировать хост сразу не всегда правильно (пропадает сбор данных), поэтому
скрипт **предлагает** MM (`maintenance.mode`):

- `propose` (по умолчанию):
  - при запуске из терминала спрашивает: `Перевести хост srv-01 в режим обслуживания на 72 ч? [y/N]`;
  - из cron / timer / вебхука ставит хосту тег `netbox_mm=proposed`, пишет предупреждение в лог
    и в `mm_proposed` итога. Подтверждение:

    ```sh
    netbox_zabbix_sync.py approve-mm srv-01           # один хост
    netbox_zabbix_sync.py approve-mm all --hours 24   # все предложенные
    ```

    Тег `netbox_mm=proposed` удобно использовать в Zabbix: фильтр на дашборде или
    триггер на элементе со значением `mm_proposed_count` (см. «Наблюдение»).
- `auto`: обслуживание создаётся сразу;
- `off`: только запись в лог.

Обслуживание называется `NetBox decommissioning: <host>`, по умолчанию со сбором данных,
на `duration_hours`. Хост получает тег `netbox_mm=active`. Если устройство вернули в `active`,
обслуживание удаляется (`remove_on_active`). Когда устройство окончательно выведут (`offline`)
или удалят, хост будет деактивирован.

## Шаблоны по модели

Правила проверяются сверху вниз, срабатывает первое (`first_match_only: true`).
`contains` сравнивается после нормализации: регистр, пробелы, точки и дефисы не важны,
`Gen10` равно `G10`. Поэтому `"380 g10"` совпадёт с `ProLiant DL380 Gen10`, `DL380G10`
и `DL380 Gen10 Plus`. Более узкие правила (Gen10 Plus) ставьте **выше** общих.
`regex` проверяется по исходной строке модели (без учёта регистра). Сравнение идёт по модели,
по «производитель + модель», по part number и по slug типа устройства.

```yaml
templates:
  rules:
    - name: DL380 Gen10 Plus
      contains: "380 g10 plus"
      templates: ["HPE ProLiant DL380 Gen10 Plus by SNMP"]
      interface: {type: snmp, details: {version: 2, community: "{$SNMP_COMMUNITY}"}}
    - name: DL380 Gen10
      contains: "380 g10"
      templates: ["HPE ProLiant DL380 Gen10 by SNMP"]
    - name: DL360 Gen10
      contains: [".360G10", "360 gen10"]
      templates: ["HPE ProLiant DL360 Gen10 by SNMP"]
    - name: Gen9
      regex: 'DL3[68]0\s*G(en)?9'
      templates: ["HPE ProLiant Gen9 by SNMP"]
  default: ["ICMP Ping"]
```

Имена шаблонов — как в Zabbix (видимое или техническое имя). Шаблон, которого нет в Zabbix,
пропускается с ошибкой в логе; `check` заранее проверяет все шаблоны из конфига.
Ручное переопределение для отдельного устройства: custom field (`templates.custom_field`,
список через запятую) заменяет результат правил.

Скрипт управляет только шаблонами, упомянутыми в конфиге: если модель сменилась, старый шаблон
отвязывается, новый привязывается. Шаблоны, привязанные вручную, остаются.
`clear_on_unlink: true` — при отвязке удалять и элементы шаблона (по умолчанию они остаются
на хосте как обычные, без потери истории).

## Конфигурация

Конфиг — YAML или JSON (`-c` или `NBZS_CONFIG`), полный пример: [config.example.yaml](config.example.yaml).
Все параметры и значения по умолчанию описаны в `DEFAULT_CONFIG` в начале скрипта.

Секреты задаются переменными окружения (они важнее значений из файла); в YAML на них можно
сослаться: `${VAR}` или `${VAR:-default}`. Если секрет всё же лежит в файле, доступном на
чтение всем, скрипт предупредит.

| Переменная | Назначение |
|---|---|
| `NETBOX_URL`, `NETBOX_TOKEN` | адрес и токен NetBox (достаточно read-only) |
| `ZABBIX_URL` | адрес фронтенда Zabbix (`/api_jsonrpc.php` добавляется сам) |
| `ZABBIX_TOKEN` | API-токен Zabbix (рекомендуется) |
| `ZABBIX_USER`, `ZABBIX_PASSWORD` | вместо токена: сервисная учётка |
| `NETBOX_VERIFY_SSL`, `ZABBIX_VERIFY_SSL` | `true`/`false` или путь к CA-бандлу |
| `NETBOX_WEBHOOK_SECRET` | секрет вебхука NetBox (режим `serve`) |
| `NBZS_CONFIG` | путь к конфигу |
| `NBZS_LOG_LEVEL`, `NBZS_LOG_FILE` | уровень и файл лога |

Пример env-файла: [deploy/netbox-zabbix-sync.env.example](deploy/netbox-zabbix-sync.env.example).

### Права

- **NetBox**: токен только на чтение (`dcim.view_device`). Для вебхуков — ещё создание
  Webhook и Event Rule администратором.
- **Zabbix**: пользователь с ролью типа *Admin* (не Super admin) и правами **Read-write** на
  группы `NetBox/*` (и на группы, куда вы вручную добавляете синхронизируемые хосты),
  **Read** на группы шаблонов. Если `host_groups.create_missing: true`, создавать группы может
  только *Super admin*; иначе создайте группы заранее и выставьте `create_missing: false`.
  В роли разрешите методы `host.*`, `hostinterface.*`, `hostgroup.get`, `template.get`,
  `proxy.get`, `maintenance.*`.

## Установка

```sh
useradd -r -s /sbin/nologin nbzs
install -d /opt/netbox-zabbix-sync /etc/netbox-zabbix-sync
python3 -m venv /opt/netbox-zabbix-sync/venv
/opt/netbox-zabbix-sync/venv/bin/pip install -r netbox/requirements.txt
install -m 755 netbox/netbox_zabbix_sync.py /opt/netbox-zabbix-sync/
install -m 640 -g nbzs netbox/config.example.yaml /etc/netbox-zabbix-sync/config.yaml
install -m 600 netbox/deploy/netbox-zabbix-sync.env.example /etc/netbox-zabbix-sync/env
# заполните env и config.yaml

set -a; . /etc/netbox-zabbix-sync/env; set +a
/opt/netbox-zabbix-sync/venv/bin/python /opt/netbox-zabbix-sync/netbox_zabbix_sync.py check
/opt/netbox-zabbix-sync/venv/bin/python /opt/netbox-zabbix-sync/netbox_zabbix_sync.py sync --dry-run
```

`check` проверяет доступ к обоим API и наличие всех шаблонов и прокси из конфига, ничего не
меняя. `--dry-run` показывает, какие хосты будут созданы, обновлены и деактивированы.

Периодическая синхронизация (systemd timer, раз в 10 минут):

```sh
cp netbox/deploy/netbox-zabbix-sync.{service,timer} /etc/systemd/system/
systemctl daemon-reload && systemctl enable --now netbox-zabbix-sync.timer
journalctl -u netbox-zabbix-sync -f
```

Или cron: `*/10 * * * * nbzs . /etc/netbox-zabbix-sync/env && /opt/.../netbox_zabbix_sync.py sync`.
Повторный запуск, пока предыдущий не закончился, не стартует (блокировка `lock_file`).

### Вебхуки NetBox (почти мгновенная синхронизация)

```sh
cp netbox/deploy/netbox-zabbix-sync-webhook.service /etc/systemd/system/
systemctl daemon-reload && systemctl enable --now netbox-zabbix-sync-webhook
```

Режим `serve` принимает вебхуки на `webhook.listen:port` + `path`, проверяет подпись
`X-Hook-Signature` (HMAC-SHA512 от `NETBOX_WEBHOOK_SECRET`), собирает события в пачку
(`debounce`) и синхронизирует только затронутые устройства. Раз в `full_sync_interval` выполняется
полная синхронизация (на случай потерянных событий), поэтому timer при `serve` не нужен.
`GET /health` отдаёт состояние и итог последнего запуска.

В NetBox 4.x:
1. *Operations → Integrations → Webhooks → Add*: URL `https://<host>/netbox/webhook`,
   метод POST, Content type `application/json`, **Secret** = `NETBOX_WEBHOOK_SECRET`,
   тело — по умолчанию.
2. *Operations → Event Rules → Add*: Object types `DCIM > Device`, события
   *Object created / updated / deleted*, Action type *Webhook* → созданный вебхук.

Слушайте `127.0.0.1` за reverse proxy с TLS (nginx) или в изолированной сети.

## Наблюдение за синхронизацией

- Лог: каждый созданный, обновлённый (с перечнем изменений), деактивированный хост, каждая ошибка
  и итог `created=.. updated=.. disabled=.. errors=..`. Пишется в stderr (journald) и, если задан
  `logging.file`, в файл с ротацией.
- Код выхода: `0` — успех, `1` — ошибки у части хостов, `2` — запуск прерван (конфиг, NetBox или
  Zabbix недоступен, сработала защита от массовой деактивации).
- `summary_file` (`/var/lib/netbox-zabbix-sync/last_run.json`): итог последнего запуска с
  `*_count` и списками хостов. Его удобно забрать агентом Zabbix:

  ```
  # zabbix_agent2.d/nbzs.conf
  UserParameter=nbzs.last_run,cat /var/lib/netbox-zabbix-sync/last_run.json
  ```

  и зависимыми элементами (JSONPath `$.errors_count`, `$.mm_proposed_count`, `$.fatal`,
  `$.started`) построить триггеры «синхронизация не выполнялась > 1 ч», «есть ошибки»,
  «есть устройства, ожидающие MM».

## Справка по командам

```
netbox_zabbix_sync.py [-c CONFIG] [-v] [-n] [--json] {sync,serve,approve-mm,check}

sync [--device NAME ...] [--force] [--interactive|--non-interactive]
approve-mm HOST [HOST ...] | all  [--hours N]
serve
check
```

`-n/--dry-run` работает для всех команд, `--json` печатает итог в stdout.

## Тесты

`tests/test_netbox_sync.py` гоняет синхронизацию на поддельных NetBox и Zabbix API; `pynetbox`
для тестов не нужен:

```sh
python3 -m unittest tests.test_netbox_sync -v
```
