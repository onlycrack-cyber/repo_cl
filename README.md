# HPE 3PAR / Primera: мониторинг в Zabbix 7.0

Скрипт `ssmc_collect.py` опрашивает массив через 3PAR CLI по SSH и отдаёт один JSON.
Шаблон `zbx_export_templates_hpe_ssmc.yaml` («HPE SSMC StorageArray») разбирает этот JSON
зависимыми элементами данных (Dependent items).

```
Zabbix agent (active) на хосте-коллекторе
 ├─ ssmc.collect[{$SSMC_HOST},all,...]   раз в 5m, одна SSH-сессия, 9 команд CLI параллельно
 │     └─ collect.*, system.*, alerts.*  + LLD: диски, тома, порты, ноды, батареи,
 │                                         алерты, сертификаты
 └─ ssmc.collect[{$SSMC_HOST},net,...]   раз в 1m, без SSH: ICMP + TCP/22 до массива и нод
       └─ LLD: сетевая доступность нод
```

## Установка

1. На хосте-коллекторе (Zabbix agent или agent 2 версии 7.0+, Python 3.8+ (CI проверяет 3.9 и 3.12)):

   ```sh
   pip3 install paramiko            # или пакет python3-paramiko
   install -d /usr/lib/zabbix/ssmc
   install -m 755 ssmc_collect.py /usr/lib/zabbix/ssmc/
   install -m 644 deploy/userparameter_ssmc.conf /etc/zabbix/zabbix_agentd.d/
   install -o root -g zabbix -m 640 deploy/ssmc_collect.conf.example /etc/zabbix/ssmc_collect.conf
   install -d -o zabbix -g zabbix -m 700 /var/lib/zabbix/.ssh
   ```

   Заполните `/etc/zabbix/ssmc_collect.conf`: пользователь и пароль (или `password_file`,
   или `key_file`). Нужна только read-only учётка на массиве:
   `createuser -c <pwd> zabbix all browse`.

2. Добавьте имя хоста массива в Zabbix в список `Hostname=` агента: активные проверки
   привязаны к имени хоста, например `Hostname=collector01,3par-st01`. Перезапустите агент.

3. Проверьте от пользователя `zabbix`:

   ```sh
   sudo -u zabbix /usr/lib/zabbix/ssmc/ssmc_collect.py --host 10.0.0.5 --pretty; echo "exit=$?"
   zabbix_agentd -t 'ssmc.collect[10.0.0.5,net,"0=10.0.0.11,1=10.0.0.12"]'
   ```

4. Импортируйте шаблон и задайте на хосте макросы `{$SSMC_HOST}` и при необходимости
   `{$SSMC_NODE_ADDRS}` и `{$SSMC_TLS_ENDPOINTS}`.

### Обновление с версии 1.x (важно)

Шаблон 1.x создавал шесть External-check мастер-элементов, в ключах которых был пароль. В 2.0
вместо них один мастер. **Не импортируйте новый шаблон за один проход с флагом «Delete missing»:**
Zabbix сначала удалит старые мастер-элементы, а вместе с ними каскадом все привязанные к ним
зависимые элементы и правила LLD. Будут потеряны все обнаруженные диски, тома, ноды, батареи,
алерты и их история. Это проверено на Zabbix 7.0.31.

Правильный порядок — два импорта:

```sh
ZABBIX_URL=https://zabbix.example.com ZABBIX_API_TOKEN=... \
  deploy/zbx_template_upgrade.py zbx_export_templates_hpe_ssmc.yaml
```

В интерфейсе то же самое: сначала импорт с «Update existing» / «Create new», **без**
«Delete missing»; затем повторный импорт с «Delete missing».

При этом:
- сохраняются все прежние ключи и UUID элементов, правил LLD, прототипов и триггеров, а значит
  и история (на тестовом стенде 37 из 37 сущностей сохранили свои id);
- удаляются только шесть старых `ssmc_collect.py[...]` мастер-элементов. Их история (TEXT, 1h)
  ценности не несёт;
- макросы `{$SSMC_SSH_USER}` и `{$SSMC_SSH_PASS}` больше не используются. Удалите их с хостов
  после переноса учётных данных в `/etc/zabbix/ssmc_collect.conf`;
- ID батарей теперь `node.ps.bat` (например `1.0.0`). Раньше это был номер ноды, из-за чего
  батареи одной ноды давали дубликаты в LLD. Старые батареи уйдут по lifetime правила.

Для перехода без агента старый вызов `ssmc_collect.py HOST USER PASS SECTION` по-прежнему
работает (всегда exit 0, формат ответа прежний, в ответе есть поле `deprecated`).

## Скрипт

```
ssmc_collect.py --host ADDR [--section all|net|system,disks,...] [--node-addrs '0=IP,1=IP']
                [--tls-endpoints '8080,ssmc:8443'] [--deadline 25] [--parallel 4] [--pretty]
ssmc_collect.py --test [--section ...]    # данные из tests/fixtures + проверка схемы
```

| Что | Как |
|---|---|
| Учётные данные | `--user`/`--password-file`, env `SSMC_SSH_USER`, `SSMC_SSH_PASS`, `SSMC_SSH_PASS_FILE`, `SSMC_SSH_KEY_FILE`, либо INI `/etc/zabbix/ssmc_collect.conf` (`[default]` и `[<host>]`). Пароль в командной строке не принимается. |
| SSH host key | `host_key_policy = tofu` (по умолчанию: первый ключ запоминается, смена ключа — ошибка), `strict` или `insecure`. |
| Таймауты | connect 5s, команда 20s, общий `--deadline` 25s (меньше таймаута элемента `{$SSMC_ITEM_TIMEOUT}`=30s). Зависшая команда не блокирует остальные. |
| Нагрузка | Одно SSH-подключение на опрос вместо шести, до `--parallel` каналов CLI одновременно. Сетевые проверки не логинятся на массив. |
| Exit-коды | 0 OK, 1 частично (часть команд упала), 2 сбор не удался, 3 ошибка конфигурации, 4 не прошла `--validate`. JSON печатается всегда; stderr пуст. |

Поля JSON: `error` (null / текст), `errors` (по командам), `status`, `duration`, `system`,
`ports`, `disks`, `volumes`, `batteries`, `nodes`, `alerts`, `alert_summary`, `certificates`,
`net`. Если секцию собрать не удалось, её нет в ответе. Зависимые элементы тогда отбрасывают
значение и не пишут нули, а LLD не удаляет сущности.

## Тесты

```sh
pip3 install paramiko pyyaml cryptography
python3 -m unittest discover -s tests -v
```

- `tests/test_ssmc_collect.py`: парсеры на реальных выводах CLI, поддельный SSH-сервер 3PAR
  (`tests/fake_3par.py`): параллельность, таймауты, неверный пароль, подмена host key,
  legacy-режим, TLS-проба, сетевые проверки.
- `tests/test_template.py`: структура шаблона (UUID v4, ссылки триггеров, зависимости, макросы,
  теги, сохранность legacy-ключей) и прогон вывода `--test` через все шаги препроцессинга
  (JSONPath и JavaScript через node).

## Отчёт аудита (версия 2.0)

### Найденные ошибки исходной версии

| # | Где | Проблема | Последствие |
|---|---|---|---|
| 1 | `showalert` | Парсер ожидал табличный вывод, а 3PAR выдаёт блоки `Key : Value` | Алерты не собирались вообще |
| 2 | Шаблон, `alert.severity` | Сопоставление `{CRITICAL: 5}[severity]` чувствительно к регистру, а CLI пишет `Critical` | Критичные алерты давали 0, триггер не срабатывал |
| 3 | `system.state` | `overallState` жёстко равен 1 | Триггеры Degraded/Failed не могли сработать |
| 4 | `showbattery` | Перепутаны колонки: в статус попадала колонка `Expired`, ID батареи равен номеру ноды | Статус всегда Unknown, дубликаты LLD |
| 5 | `showvv` | `parts[0]` на пустой строке вызывал IndexError вне try | Терялись все тома |
| 6 | `showport` | В `type` писался WWN, а не тип порта; всё, что не ready, превращалось в 9 (INITIALIZING) | Неверные данные, нельзя отфильтровать `free`-порты |
| 7 | `shownode` | В `model` попадала колонка InCluster («Yes»); выпавшая из кластера нода давала «not found» | Отказ ноды не алертился |
| 8 | Безопасность | Пароль в ключе элемента и в argv (виден в `ps`, UI, логах) | Утечка пароля |
| 9 | Безопасность | `AutoAddPolicy` без known_hosts | Возможна MITM-атака |
| 10 | Нагрузка | 6 отдельных SSH-подключений на каждый цикл | Лишние логины на массив, записи в eventlog |
| 11 | Шаблон | Тома с полным выделением (`admin`, `.srdata`) всегда показывают 100% утилизации | Постоянный ложный High |
| 12 | Шаблон | При ошибке сбора JS-прототипы и LLD получали пустые списки | Сущности удалялись по lifetime, в историю писались нули |
| 13 | Скрипт | `--password` молча принимался как `--password-file` (сокращения argparse) | Путаница с источником пароля |

Отдельно (не исправлено, чтобы не ломать историю): множитель `1.0E-6` переводит MiB в «TB»
приближённо (1 MiB = 1.048576 MB), отклонение около 4.9%.

### Новые метрики (ключи)

| Ключ | Описание |
|---|---|
| `ssmc.collect[{$SSMC_HOST},all,,"{$SSMC_TLS_ENDPOINTS}"]` | Мастер, Zabbix agent (active), заменяет 6 external checks |
| `ssmc.collect[{$SSMC_HOST},net,"{$SSMC_NODE_ADDRS}"]` | Мастер сетевых проверок, agent (active), 1m |
| `collect.status`, `collect.duration` | Состояние и длительность сбора |
| `system.capacity.pused`, `system.model`, `system.serial`, `system.state.reasons` | Заполненность массива, инвентарь, причина Degraded/Failed |
| `alerts.count.total/critical/major`, `alerts.critical.last` | Сводка алертов массива |
| `battery.expired[{#BATTERY_ID}]` | Батарея просрочена |
| LLD `certs.discovery`: `cert.expiry[{#CERT_ID}]`, `cert.days_left[{#CERT_ID}]` | Сроки сертификатов: `showcert` (cim, wsapi, unified-server, syslog, ldap, ekm, ...) и TLS-порты |
| LLD `net.discovery`: `net.node.up/ssh/icmp.loss/icmp.rtt[{#NET_ADDR}]` | Сетевая доступность management-адреса и нод |

### Триггеры

| Триггер | Важность | Восстановление |
|---|---|---|
| No data from the collector for `{$SSMC_NODATA_TIMEOUT}` (сбор и сеть) | High / Warning | nodata |
| Data collection failed (2 опроса подряд) | High | `last(collect.status)<2` |
| Data collection is partial (2 опроса подряд) | Warning, зависит от failed | status=0 |
| Collector is slow (>`{$SSMC_COLLECT_TIME_WARN}`s, 3 опроса подряд) | Warning | max(#3) ниже порога |
| **CRITICAL alert [{#ALERT_ID}]: {#ALERT_MSG}** (Critical/Fatal) | **Disaster** | алерт Fixed или удалён на массиве; manual close |
| Major alert [{#ALERT_ID}] (ранее срабатывал на >=4) | High | severity≠4 |
| Certificate expired / < `{$SSMC_CERT_EXPIRY_CRIT}`d / < `{$SSMC_CERT_EXPIRY_WARN}`d | High / High / Warning, с цепочкой зависимостей | через `now()`, без флапа |
| {#NET_LABEL} unreachable (3 проверки подряд) | High | 2 успешные подряд |
| SSH port not reachable / ICMP loss / ICMP RTT | Warning, зависят от unreachable | гистерезис |
| Array capacity usage high / critical | Warning / High | порог минус `{$SSMC_UTIL_HYSTERESIS}` |
| Node failed / left cluster / missing | Disaster | |
| Node degraded or unknown | High, зависит от failed | |
| Battery expired | Warning, зависит от «not OK» | |
| Volume utilization high / critical | гистерезис; warn зависит от crit; нет для `full` | |
| Port not READY | 2 опроса подряд | `last()=4` |

Все элементы и триггеры размечены тегами `target: hpe-3par`, `service: {$SSMC_SERVICE}`,
`component: ...`, у триггеров также `scope: availability|capacity|performance|security|notice`.
Сущности LLD получают ещё теги `disk`, `volume`, `port`, `node`, `battery`, `alert`,
`certificate`.
