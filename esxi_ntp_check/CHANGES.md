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
