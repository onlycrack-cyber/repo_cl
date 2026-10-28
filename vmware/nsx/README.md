# VMware NSX (NSX-T 3.x / NSX 4.x): мониторинг в Zabbix 7.0 через REST API

Шаблон **VMware NSX by HTTP** опрашивает NSX Manager по REST API: агент не нужен, запросы
идут напрямую с Zabbix-сервера или прокси.

| Файл | Что это |
|---|---|
| `zbx_template_vmware_nsx.yaml` | шаблон для импорта (родной формат экспорта Zabbix 7.0) |
| `zbx_template_vmware_nsx.json` | тот же шаблон в JSON, импортируется так же |
| `../tools/gen_nsx_template.py` | генератор обоих файлов; руками их не правят |
| `../../tests/test_nsx.py`, `../../tests/fixtures/nsx/api.json` | тесты: ответы API прогоняются через все LLD, препроцессинг и триггеры |

## Архитектура сбора

Мастер-элементы типа **HTTP agent** забирают JSON (история не хранится, `history: 0`).
Из этого JSON значения достают зависимые элементы (Dependent items) и зависимые LLD-правила:
JSONPath, а JavaScript только там, где JSONPath не справляется (строковый статус в число,
подсчёт, разворачивание вложенных списков).

```
NSX: Get Cluster Status   GET /api/v1/cluster/status                         1m
 ├─ overall_status, mgmt_cluster_status, control_cluster_status, ноды online/offline
 └─ LLD: сервисные группы (MANAGER, POLICY, CONTROLLER, DATASTORE…)
NSX: Get transport nodes  GET /api/v1/transport-nodes                        1h
 └─ LLD: ESXi-узлы (HostNode) ─ HTTP-прототип GET /api/v1/transport-nodes/<id>/status   5m
          └─ статус, связь с Manager/CCP, GENEVE-туннели (status/up/down), pNIC
NSX: Get edge clusters    GET /api/v1/edge-clusters                          1h
 ├─ LLD: Edge-кластеры ─ GET /api/v1/edge-clusters/<id>/status               5m
 └─ LLD: Edge-ноды     ─ GET /api/v1/transport-nodes/<id>/status             5m
          └─ статус, туннели, CPU DPDK/сервисный, память, uptime
NSX: Get Tier-0/Tier-1    GET /policy/api/v1/infra/tier-0s | tier-1s         1h
 └─ LLD: шлюзы ─ GET /policy/api/v1/infra/tier-Xs/<id>/state                 5m
          └─ HA: какие SR ACTIVE/STANDBY/DOWN, кто активен сейчас
NSX: Get BGP neighbors    Script: tier-0s → locale-services → bgp/neighbors/status   5m
 └─ LLD: BGP-соседи ─ состояние, время с установления, префиксы
NSX: Get alarms           GET /api/v1/alarms?status=OPEN                     1m
 ├─ счётчики по severity
 └─ LLD: один элемент + один триггер на каждый открытый аларм NSX
NSX: Get certificates     GET /api/v1/trust-management/certificates?details=true   1h
 └─ LLD: сертификаты ─ дата окончания, дней до истечения
NSX: Get licenses         GET /api/v1/licenses                               1h
 └─ NSX: Licenses (normalized): JS убирает ключи лицензий
     └─ LLD: лицензии ─ дата окончания, дней до истечения, expired, evaluation, количество
```

**Почему BGP собирается Script-элементом.** Статус соседей лежит на два уровня глубже
шлюза (`/tier-0s/<t0>/locale-services/<ls>/bgp/neighbors/status`), а вложенного LLD в
Zabbix 7.0 нет (он появился в 7.4). Script-элемент обходит Tier-0 и их locale-services
(идентификатор `default` не зашит) и отдаёт плоский список. Ошибка одного шлюза не
ломает остальные: она попадает в элемент `NSX: BGP collection errors` и триггер Warning.

## Права в NSX

Шаблон только читает (только GET), поэтому нужна учётная запись с ролью **Auditor**
(read-only). Enterprise Admin не нужен и не рекомендуется.

1. *System → User Management → Local Users* (или пользователь/группа из LDAP/vIDM):
   создать, например, `zabbix-ro`. Локальных пользователей `guestuser1`/`guestuser2`
   сначала нужно активировать.
2. *Role Assignment → Add → Role: Auditor*. В NSX 4.1+ с проектами (multi-tenancy) назначайте
   роль на уровне по умолчанию (Default), а не внутри проекта.
3. Срок жизни пароля у локальных пользователей по умолчанию 90 дней. Чтобы мониторинг
   не отвалился внезапно, на NSX Manager:
   `set user zabbix-ro password-expiration 9999` (CLI под admin).
4. Auditor видит алармы, статусы транспортных узлов, Edge, шлюзов, BGP, список
   сертификатов (без закрытых ключей) и лицензии: этого хватает для всех запросов шаблона.

**Аутентификация.** Используется HTTP Basic (каждый запрос самодостаточен, что
удобно для HTTP agent). Если в NSX выключена basic-аутентификация
(`basic_authentication_enabled: false` в `/api/v1/node/services/http`), шаблон работать
не будет: session-based (cookie + `X-XSRF-TOKEN`) в HTTP agent без скрипта не
реализовать. Для LDAP-пользователя логин пишется как `user@domain`.

**Лимиты API.** NSX ограничивает клиента: по умолчанию 100 запросов в секунду и 40
одновременных запросов. Шаблон делает примерно
`(хостов + edge + edge-кластеров + T0 + T1) / 5 мин` плюс несколько общих запросов в минуту;
даже 500 хостов дают ~2 запроса в секунду. На больших инсталляциях увеличьте
`{$NSX.INTERVAL.NODE}` или ограничьте обнаружение макросами `…NAME.MATCHES`.

## Установка

1. *Data collection → Templates → Import* → `zbx_template_vmware_nsx.yaml`
   (или `.json`). Шаблон попадает в группу `Templates/Applications`.
2. Создайте хост, например `nsx-manager-cluster`. Интерфейс не нужен. Привяжите
   шаблон **VMware NSX by HTTP**. Если NSX Manager доступен только из определённой
   сети, мониторьте хост через Zabbix proxy в этой сети.
3. Макросы хоста:
   - `{$NSX.MANAGER.URL}`: `https://<VIP или FQDN кластера>`, без `/` в конце. Указывайте
     VIP кластера, а не отдельную ноду: тогда отказ одной ноды не прерывает мониторинг.
   - `{$NSX.USER}`, `{$NSX.PASSWORD}` (тип **Secret text**).
   - для NSX-T 2.x/3.x: `{$NSX.BGP.UPTIME.MULTIPLIER}` = `0.001` (см. ниже).
4. Проверьте доступ с сервера/прокси Zabbix тем же пользователем:

   ```sh
   curl -sk -u 'zabbix-ro:PASSWORD' https://nsx.example.local/api/v1/cluster/status | head -c 300
   ```

5. Через пару минут: *Monitoring → Latest data*, фильтр по хосту. Объекты LLD появятся
   после первого опроса списков (до `{$NSX.INTERVAL.LLD}` = 1 ч; *Execute now* на
   мастер-элементах `NSX: Get …` ускоряет).

**TLS.** HTTP agent по умолчанию не проверяет сертификат (`verify_peer: NO`), как и
Script-элемент. При сертификате от доверенного CA включите *Verify peer / Verify host*
на HTTP-элементах.

## Макросы

| Макрос | По умолчанию | Назначение |
|---|---|---|
| `{$NSX.MANAGER.URL}` | `https://nsx-manager.local` | URL NSX Manager |
| `{$NSX.USER}` / `{$NSX.PASSWORD}` | `zabbix-ro` / — | учётные данные (пароль: Secret text) |
| `{$NSX.DATA.TIMEOUT}` | `15s` | таймаут HTTP-запросов |
| `{$NSX.SCRIPT.TIMEOUT}` | `30s` | таймаут BGP-скрипта (несколько запросов подряд) |
| `{$NSX.HTTP.PROXY}` | пусто | HTTP-прокси |
| `{$NSX.INTERVAL.STATUS}` / `.NODE` / `.LLD` | `1m` / `5m` / `1h` | интервалы: кластер и алармы / узлы, шлюзы, BGP / списки для LLD |
| `{$NSX.NODATA.TIMEOUT}` | `10m` | нет данных от API дольше этого: проблема |
| `{$NSX.EDGE.CPU.DP.MAX}` | `80` | порог CPU datapath (DPDK) Edge, %; можно с контекстом: `{$NSX.EDGE.CPU.DP.MAX:"edge-01"}` |
| `{$NSX.EDGE.CPU.SVC.MAX}` | `90` | порог CPU сервисных ядер Edge, % |
| `{$NSX.EDGE.MEM.MAX}` | `90` | порог памяти Edge, % |
| `{$NSX.EDGE.UTIL.PERIOD}` | `15m` | сколько порог должен держаться (`min()` за период) |
| `{$NSX.BGP.FLAP.PERIOD}` | `10m` | сессия моложе этого: «флап» |
| `{$NSX.BGP.UPTIME.MULTIPLIER}` | `1` | `time_since_established` в секундах (NSX 4.x); `0.001` для NSX-T 2.x/3.x (миллисекунды) |
| `{$NSX.ALARM.STATUS}` | `OPEN` | фильтр статуса в запросе `/api/v1/alarms` |
| `{$NSX.ALARM.SEVERITY.MATCHES}` | `^(CRITICAL\|HIGH\|MEDIUM)$` | какие алармы NSX превращать в проблемы (LOW по умолчанию нет) |
| `{$NSX.ALARM.FEATURE.NOT_MATCHES}` | `CHANGE_IF_NEEDED` | игнорировать алармы этих feature (regex по отображаемому имени) |
| `{$NSX.INTERVAL.CERT}` | `1h` | интервал опроса сертификатов |
| `{$NSX.CERT.EXPIRY.WARN}` / `.CRIT` | `30` / `7` | дней до истечения для Warning / High; можно с контекстом: `{$NSX.CERT.EXPIRY.WARN:"mgr-cluster-api"}` |
| `{$NSX.CERT.USED_BY.MATCHES}` | `.*` | фильтр по сервисам, использующим сертификат; `.+` пропускает неиспользуемые |
| `{$NSX.INTERVAL.LICENSE}` | `1h` | интервал опроса лицензий |
| `{$NSX.LICENSE.EXPIRY.WARN}` / `.CRIT` | `60` / `14` | дней до окончания лицензии для Warning / Average; можно с контекстом по имени лицензии |
| `{$NSX.<TN\|EDGE\|EC\|T0\|T1\|CERT\|LICENSE>.NAME.MATCHES}` / `.NOT_MATCHES` | `.*` / `CHANGE_IF_NEEDED` | фильтры LLD по имени хоста, Edge, Edge-кластера, шлюзов, сертификата, лицензии |

## Триггеры

| Объект | Триггер | Важность |
|---|---|---|
| Кластер | overall_status = UNAVAILABLE | High |
| Кластер | кластер / management plane / control plane / сервисная группа не STABLE | Average (зависят от UNAVAILABLE) |
| Кластер | есть offline-ноды Manager | High |
| API | нет данных `{$NSX.NODATA.TIMEOUT}` | Average |
| Хост / Edge | статус DOWN | High |
| Хост / Edge | статус DEGRADED; потеря связи с Manager или CCP | Average |
| Хост / Edge | все GENEVE-туннели DOWN | High |
| Хост / Edge | туннели DEGRADED | Average |
| Хост / Edge | N туннелей down; pNIC degraded | Warning |
| Edge | CPU datapath > порога | Average |
| Edge | CPU сервисных ядер > порога | Warning |
| Edge | память > порога | Average |
| Edge | перезагрузка (uptime < 10m) | Warning, закрывается вручную |
| Edge-кластер | DOWN / DEGRADED | High / Average |
| Tier-0/Tier-1 | SR в состоянии DOWN/SYNC/UNKNOWN | High |
| Tier-0/Tier-1 | нет ни одного ACTIVE SR | High |
| Tier-0/Tier-1 | **HA failover**: сменился набор ACTIVE SR | Average, закрывается вручную |
| Tier-0 | два ACTIVE SR в ACTIVE_STANDBY (split brain) | High |
| BGP | сессия не ESTABLISHED (или сосед пропал из вывода) | High |
| BGP | сессия переустановлена меньше `{$NSX.BGP.FLAP.PERIOD}` назад | Warning, закрывается вручную |
| Сертификат | истёк | Disaster |
| Сертификат | истекает через < `{$NSX.CERT.EXPIRY.CRIT}` дней | High (зависит от «истёк») |
| Сертификат | истекает через < `{$NSX.CERT.EXPIRY.WARN}` дней | Warning (зависит от High) |
| Лицензия | истекла (`is_expired`) | High |
| Лицензия | истекает через < `{$NSX.LICENSE.EXPIRY.CRIT}` дней | Average (зависит от «истекла») |
| Лицензия | истекает через < `{$NSX.LICENSE.EXPIRY.WARN}` дней | Warning (зависит от Average) |
| Лицензия | установлен evaluation-ключ | Information |

Триггеры зависят друг от друга: при падении Edge не приходит отдельная проблема на каждый
туннель и каждый сервис.

Для Tier-0 в режиме **ACTIVE_ACTIVE** триггеры «HA failover» и «split brain» не
создаются (LLD override по `{#T0.HA_MODE}`): там все SR активны, и для них остаётся
триггер «SR DOWN». Tier-1 без сервисов (только DR) не имеет SR: его счётчики равны 0,
и триггеры не срабатывают.

### Почему CPU Edge двух видов

Ядра DPDK (fast path) постоянно опрашивают сетевые карты, поэтому `top` на Edge всегда
показывает около 100%. NSX отдаёт реальную загрузку обработкой пакетов:
`avg_cpu_core_usage_dpdk`. Сервисные ядра (`non_dpdk`) обслуживают маршрутизацию, LB,
VPN, DHCP. Пороги у них разные.

## Алармы NSX (passthrough)

`/api/v1/alarms?status=OPEN` → LLD с JSONPath-фильтром `$.results[?(@.status=='OPEN')]`
→ JavaScript собирает LLD-макросы → по одному элементу и триггеру на каждый аларм.
Важность триггера задают LLD overrides по `{#ALARM.SEVERITY}`:

| NSX severity | Zabbix |
|---|---|
| CRITICAL | High |
| HIGH | Average |
| MEDIUM | Warning |
| LOW | Information (по умолчанию отфильтрован) |

Элемент аларма показывает OPEN, пока аларм открыт. Когда аларм решён или подтверждён
в NSX, он пропадает из выборки, элемент получает RESOLVED и проблема в Zabbix закрывается
сама. Потерянные объекты LLD не отключаются (`DISABLE_NEVER`) и удаляются через сутки:
иначе Zabbix 7.0 отключил бы элемент сразу и проблема осталась бы открытой.

Алармы NSX частично дублируют собственные триггеры шлюза (например, «Edge Node Down»).
Проблемы алармов помечены тегом `scope: notice` и `nsx-severity`, их легко отделить
в действиях. Если дубли мешают, отфильтруйте feature в `{$NSX.ALARM.FEATURE.NOT_MATCHES}`
или выключите правило «NSX: Alarms discovery».

## Сертификаты

Все сертификаты из *System → Certificates*: API и кластер Manager, транспортные узлы,
балансировщик, VPN, импортированные CA. Для каждого:

- **expires on**: `details[0].not_after` (листовой сертификат цепочки), тип `unixtime`;
- **days until expiry**: целые дни до окончания; после истечения значение отрицательное.
  Пересчитывается при каждом опросе (`{$NSX.INTERVAL.CERT}` = 1 ч).

В описании триггера есть Subject CN, Issuer CN и сервисы, которые используют сертификат
(`{#CERT.USED_BY}` из `used_by[].service_types`). Неиспользуемые сертификаты, например
старые импортированные CA, тоже обнаруживаются и тоже дадут проблему, когда истекут.
Чтобы их пропускать, задайте `{$NSX.CERT.USED_BY.MATCHES}` = `.+`, а отдельные сертификаты
исключайте через `{$NSX.CERT.NAME.NOT_MATCHES}`. Порог для конкретного сертификата задаётся
макросом с контекстом, например `{$NSX.CERT.EXPIRY.WARN:"mgr-cluster-api"}` = `60`.

Сертификат без `details` в ответе становится в Zabbix «не поддерживается» с текстом ошибки:
так он не пропадает из мониторинга молча. Удалённый в NSX сертификат перестаёт получать
значения, а через 7 дней удаляется из Zabbix.

Алармы NSX о сертификатах (feature `certificates`) по-прежнему приходят через passthrough
алармов. Если два вида проблем для одного сертификата мешают, исключите feature алармов:
`{$NSX.ALARM.FEATURE.NOT_MATCHES}` = `(?i)^certificates$` (сравнивается отображаемое имя feature,
а если его нет, системное).

## Лицензии

`/api/v1/licenses` (*System → Licenses*). На каждый установленный ключ:

- **expires on** и **days until expiry**: дата окончания и целые дни до неё (после окончания
  значение отрицательное). У бессрочной лицензии (`expiry` = 0 или нет поля) этих данных нет,
  и триггеры по срокам к ней не срабатывают;
- **expired**, **evaluation**: флаги `is_expired` и `is_eval` (Yes/No);
- **licensed quantity**: сколько единиц `capacity_type` (CPU, CORE, VM, USER…) покрывает ключ.

Пороги по умолчанию больше, чем у сертификатов (60 и 14 дней): продление лицензии обычно
требует закупки. Истёкшая лицензия NSX не останавливает передачу трафика, но ограничивает
настройку лицензируемых функций, поэтому её триггер High, а не Disaster.

**Ключи лицензий не попадают в Zabbix.** Сырой ответ API хранится только в памяти
(`history: 0`). JavaScript в элементе `NSX: Licenses (normalized)` заменяет ключ хешем (он
используется как `{#LIC.ID}`) и маской из последних пяти символов (`*****E1234`): только эта
маска видна в именах элементов и триггеров. Тест `test_license_keys_never_leave_the_raw_item`
проверяет, что полный ключ нигде не сохраняется.

Использование лицензий (сколько CPU/VM занято, `/api/v1/licenses/licenses-usage`) шаблон
пока не собирает: формат этого ответа надо сначала сверить на живом NSX.

В инсталляциях VMware Cloud Foundation лицензии могут управляться централизованно
(vCenter, VCF Operations). Тогда список в NSX может быть пустым: правило ничего не
обнаружит, а `NSX: Licenses installed` покажет 0.

## Пример: мастер-элемент и LLD-правило (фрагмент YAML)

```yaml
        - uuid: …
          name: 'NSX: Get Cluster Status'
          type: HTTP_AGENT
          key: nsx.cluster.status.get
          delay: '{$NSX.INTERVAL.STATUS}'
          history: '0'
          value_type: TEXT
          authtype: BASIC
          username: '{$NSX.USER}'
          password: '{$NSX.PASSWORD}'
          timeout: '{$NSX.DATA.TIMEOUT}'
          url: '{$NSX.MANAGER.URL}/api/v1/cluster/status'
        - name: 'NSX: Cluster overall status'
          type: DEPENDENT
          key: nsx.cluster.overall_status
          preprocessing:
            - type: JSONPATH
              parameters: [$.detailed_cluster_status.overall_status]
            - type: JAVASCRIPT          # STABLE → 1, DEGRADED → 2, UNAVAILABLE → 4 …
          master_item: {key: nsx.cluster.status.get}
      discovery_rules:
        - name: 'NSX: Alarms discovery'
          type: DEPENDENT
          key: nsx.alarm.discovery
          master_item: {key: nsx.alarms.get}
          preprocessing:
            - type: JSONPATH
              parameters: ["$.results[?(@.status=='OPEN')]"]
              error_handler: CUSTOM_VALUE
              error_handler_params: '[]'
          item_prototypes:
            - key: 'nsx.alarm.status[{#ALARM.ID}]'
              preprocessing:
                - type: JSONPATH
                  parameters: ["$.results[?(@.id=='{#ALARM.ID}')].status.first()"]
                  error_handler: CUSTOM_VALUE
                  error_handler_params: RESOLVED
```

Полная версия: в файлах шаблона.

## Что проверено и что сверить на своей версии NSX

Проверено автоматически (`python -m unittest tests.test_nsx`):

- структура импорта: каждое поле и значение-перечисление сверено со схемой валидатора
  импорта Zabbix 7.0 и официальными шаблонами 7.0;
- JSON- и YAML-файлы совпадают, UUID уникальны, макросы определены и используются,
  триггеры ссылаются на свои элементы, зависимости триггеров существуют;
- весь JavaScript (ES5 для Duktape) выполняется в node, включая BGP-скрипт со
  stub-ом `HttpRequest`: проверяется Basic-аутентификация, обход locale-services и
  продолжение работы при ошибке одного шлюза;
- end-to-end на `tests/fixtures/nsx/api.json`: LLD, фильтры, overrides, значения и
  набор сработавших триггеров.

**Импорт в живой Zabbix и опрос живого NSX не проверялись.** Фикстуры написаны по
справочнику NSX API. Структуру ответов, в которых версии NSX расходятся, стоит сверить
на своей инсталляции (`curl` под пользователем мониторинга):

| Что | Где используется | На что смотреть |
|---|---|---|
| `tier0_status.per_node_status[].high_availability_status` (`tier1_status` для T1) | HA шлюзов | `GET /policy/api/v1/infra/tier-0s/<id>/state` |
| `transport_node.target_display_name` в статусе BGP-соседа | имя Edge в BGP | при отсутствии берутся `target_id`, `edge_path`, затем пустая строка |
| `time_since_established` | uptime BGP | секунды в 4.x, миллисекунды в 2.x/3.x → `{$NSX.BGP.UPTIME.MULTIPLIER}` |
| `node_status.system_status.cpu_usage.*`, `edge_mem_usage.system_mem_usage` | CPU/RAM Edge | без `edge_mem_usage` память считается как `mem_used / mem_total` |
| `?source=cached` | статус транспортных узлов | кешированный статус дешевле для Manager; убрать, если нужен realtime |
| `license_key`, `description`, `expiry`, `is_eval`, `is_expired`, `capacity_type`, `quantity` | лицензии | `GET /api/v1/licenses`; `expiry` ожидается в миллисекундах epoch, 0 = бессрочная |
| `details[0].not_after`, `details[0].subject_cn`, `used_by[].service_types` | сертификаты | `GET /api/v1/trust-management/certificates?details=true`; `not_after` ожидается в миллисекундах epoch (секунды тоже распознаются) |

В NSX 4.x Manager API `/api/v1/transport-nodes` помечен как deprecated в пользу
Policy API (`/policy/api/v1/infra/sites/default/enforcement-points/default/host-transport-nodes`),
но GET по-прежнему работает. Используемые здесь статусные эндпоинты есть только в
Manager API.

## Изменение шаблона

Правьте `vmware/tools/gen_nsx_template.py`, затем:

```sh
python3 vmware/tools/gen_nsx_template.py vmware/nsx
python3 -m unittest tests.test_nsx
```

Тест `test_generator_output_is_committed` падает, если файлы шаблона не перегенерированы.
UUID детерминированные (от ключей и имён), поэтому повторный импорт обновляет объекты, а не
дублирует их (*Import → Update existing*).
