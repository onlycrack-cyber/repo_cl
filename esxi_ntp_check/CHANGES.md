# esxi_ntp_check — правки v2 (логика проверок сохранена)

Скрипт
- storage: 0 только если известный адаптер не online (один из двух портов offline — тревога,
  как задумано). Все адаптеры `unknown` -> 1 (раньше ложный 0). Нет данных -> ошибка.
- Хост не `connected` (disconnected/notResponding) или свойство не прочитано -> ошибка
  (unsupported), а не 0: нет шторма из 5 ложных триггеров.
- `{#ESXIADDR}` теперь IP vmk0 (`summary.config.ipAddress` у HostConfigSummary не существует).
- `_find_host`: точное имя/IP; короткое имя только однозначное; убраны fallback «единственный
  хост» и поиск по подстроке.
- Кэш: TTL 900 c (> интервала айтемов), обновление под `flock`, уникальный tmp-файл.
- Общий дедлайн `ESXI_DEADLINE` (25 c) через SIGALRM; новый режим `hosts` (число хостов).
- Опциональная проверка TLS: `ESXI_CA_BUNDLE` (по умолчанию выключена, как раньше).
- Исправлен shim `ssl.wrap_socket` (`ciphers` не принимается SSLContext.wrap_socket); опечатки.

Шаблон
- Триггеры: `max(...,#2)=0` (2 подряд плохих значения, ~10 мин задержки), event_name с
  `{HOST.NAME}`, теги component/scope, manual_close.
- Самопроверка vCenter: айтем «ESXi hosts found in vCenter» + 2 триггера (nodata 30m, =0).
- Фильтр LLD по макросам, lifetime 7d, timeout 30s.
- Макросы `{$VMWARE.*}` по-прежнему берутся с мастер-хоста.

Не менялось: NTP = «настроен и ntpd запущен»; Power = политика из vCenter (High performance).
Не проверено: импорт в Zabbix 7.0 и работа против живого vCenter (нет доступа).

# v3 — шаблон "ESXi Health by vCenter" (вариант B + D)

Новый файл `Template ESXi Health by vCenter.yaml` (старый шаблон не тронут и работает с тем же
скриптом — старые режимы сохранены). Один внешний айтем `collect` раз в 5 минут отдаёт JSON по всем
хостам; LLD и все значения — зависимые (JSONPath). Недоступное значение опускается -> unsupported.

Остаётся из v2: NTP (настроен + ntpd), DNS, VMkernel, Storage-адаптеры, Power policy.
Добавлено (только то, чего нет в стандартном шаблоне VMware и SNMP по HPE):
- LLD аплинков vmnic (только порты, входящие в vSwitch/DVS): link up/down, скорость
  (порог `{$ESXI.NIC.SPEED.MIN}`, 0 = выкл.), триггер при down;
- LLD vSwitch/DVS: живые/назначенные аплинки, HIGH если живых 0;
- мультипасинг: число dead-путей (HIGH);
- датасторы, недоступные хосту (`accessible=false`, HIGH) + их имена;
- HA-агент (`dasHostState`): WARNING для isolated/partitioned/error/hostDown; хост вне HA — норма.
Намеренно НЕ добавлено: состояние хоста, overallStatus, uptime, датчики железа, ICMP, тревоги vCenter.

Проверено: py_compile, 15+11 unit-тестов (JSONPath каждого айтема на выходе collect, ключи
триггеров, uuid, valuemaps). НЕ проверено: импорт в Zabbix 7.0, работа против живого vCenter,
реальные имена свойств pyVmomi (`config.network.pnic/vswitch/proxySwitch`, `multipathInfo`,
`fileSystemVolume.mountInfo`, `runtime.dasHostState`).
Размер JSON ~490 байт/хост (200 хостов ≈ 99 КБ) — см. лимит значения типа text в вашей версии.

# v3.1 — смещение времени NTP
- Скрипт: `QueryDateTime()` на каждый подключённый хост (ThreadPool, `ESXI_NTP_WORKERS`=8),
  сравнение с временем vCenter (`si.CurrentTime()` + монотонные часы), отключается `ESXI_NTP_OFFSET=0`.
  Хост не ответил -> значение опускается (unsupported), не 0.
- Шаблон: `NTP time offset on {#ESXINAME}` (FLOAT, s); WARNING при |avg(3)| > `{$ESXI.NTP.OFFSET.WARN}` (1 c),
  HIGH при > `{$ESXI.NTP.OFFSET.HIGH}` (5 c). Проверка «NTP настроен и работает» осталась.
- Не проверено на живом vCenter: потокобезопасность pyVmomi при параллельных QueryDateTime и
  время опроса на вашем числе хостов (укладывается ли в `ESXI_DEADLINE`=25 c).
