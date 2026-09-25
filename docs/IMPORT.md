# Импорт шаблона «HPE SSMC StorageArray» в Zabbix 7.0

Сначала определите, какая версия шаблона сейчас стоит в Zabbix: *Data collection → Templates →
HPE SSMC StorageArray → Items*.

| Что видно | Версия | Как импортировать |
|---|---|---|
| Шаблона нет | — | вариант А |
| Мастер-элементы `ssmc.collect[...]` | 2.0, агентная | вариант А |
| Шесть мастер-элементов с ключами `…"{$SSMC_SSH_PASS}","system"]`, `"disks"]` и т.д. | 1.x | **вариант Б, два прохода** |

## 1. Подготовка на сервере Zabbix (или на прокси, через который мониторится массив)

```sh
pip3 install paramiko                     # или пакет python3-paramiko
install -m 755 ssmc_collect.py /usr/lib/zabbix/externalscripts/
install -d -o zabbix -g zabbix -m 700 /var/lib/zabbix/.ssh   # сюда сохранится SSH-ключ хоста массива
```

Каталог скриптов задаётся параметром `ExternalScripts` в `zabbix_server.conf`
(или `zabbix_proxy.conf`).

Проверьте запуск от пользователя `zabbix`: команда должна вернуть JSON и `exit=0`.

```sh
sudo -u zabbix /usr/lib/zabbix/externalscripts/ssmc_collect.py \
    --host=<IP массива> --user=<логин> --password-file=/root/pw --pretty; echo "exit=$?"
```

`/root/pw` — временный файл, в котором только пароль (`chmod 600`). После проверки удалите его.
Так пароль не попадёт в историю shell.

## 2. Импорт шаблона

Перед импортом сделайте резервную копию: экспортируйте текущий шаблон
(*Templates → отметить шаблон → Export*) или снимите дамп БД.

### Вариант А: новая установка или обновление с версии 2.0 (один проход)

1. *Data collection → Templates → Import*.
2. Файл `zbx_export_templates_hpe_ssmc.yaml`.
3. В правилах импорта (Rules) отметьте **Create new** и **Update existing** для всех объектов.
   **Delete missing** можно включить для Items, Discovery rules и Triggers, история сохранится.
4. *Import*.

### Вариант Б: обновление с версии 1.x (только два прохода)

> Если импортировать в один проход с «Delete missing», Zabbix удалит старые мастер-элементы, а
> вместе с ними правила обнаружения. Пропадут все обнаруженные диски, тома, ноды, батареи и
> алерты вместе с их историей. Проверено на Zabbix 7.0.31.

**Способ 1: скрипт-помощник.** Токен создаётся в *User settings → API tokens*, пользователю
нужны права на изменение шаблонов.

```sh
export ZABBIX_URL=https://zabbix.example.com
export ZABBIX_API_TOKEN=<токен>
deploy/zbx_template_upgrade.py zbx_export_templates_hpe_ssmc.yaml --dry-run   # предпросмотр изменений
deploy/zbx_template_upgrade.py zbx_export_templates_hpe_ssmc.yaml
```

Скрипт выведет «step 1/2 … step 2/2».

**Способ 2: вручную через интерфейс.**

1. Импорт с **Create new** и **Update existing**, **Delete missing** везде снят. Зависимые
   элементы и правила переключатся на новый мастер-элемент.
2. Повторный импорт того же файла, теперь с **Delete missing** для Items, Discovery rules и
   Triggers. Удалятся только шесть старых мастер-элементов.

## 3. Макросы на хосте массива

*Data collection → Hosts → хост → Macros* (шаблон должен быть привязан к хосту):

| Макрос | Значение |
|---|---|
| `{$SSMC_HOST}` | IP или имя массива |
| `{$SSMC_SSH_USER}` | логин (достаточно read-only: `createuser -c <pwd> zabbix all browse`) |
| `{$SSMC_SSH_PASS}` | пароль, тип **Secret text** (переключатель справа от поля) |
| `{$SSMC_NODE_ADDRS}` | по желанию: адреса нод, `0=10.0.0.11,1=10.0.0.12` |
| `{$SSMC_TLS_ENDPOINTS}` | по желанию: порты для проверки сертификатов, по умолчанию `8080` (WSAPI) |

Интерфейс на хосте не нужен.

- **Обновление с 1.x:** макросы с хостов удалять не нужно, они используются как раньше.
  Проверьте только, что у `{$SSMC_SSH_PASS}` тип Secret text.
- **Обновление с 2.0 (агентной):** удалите на агенте
  `/etc/zabbix/zabbix_agentd.d/userparameter_ssmc.conf` и уберите имена массивов из его
  `Hostname=`.

Пароль из макроса виден в списке процессов сервера (`ps`), пока идёт опрос. Как этого избежать,
описано в [README](../README.md#что-видно-и-где-хранится-пароль).

## 4. Проверка (через 5–10 минут)

*Monitoring → Latest data → хост массива:*

- `Collection status` = **OK (0)**, `Collection error` пустой;
- `Collection duration` — несколько секунд;
- появились обнаруженные элементы: диски, тома, порты, ноды, батареи, сертификаты, сетевая
  доступность. Обнаружение отрабатывает на первом опросе, значения приходят со следующего;
- при обновлении с 1.x: история старых элементов (`Disk [...] state`,
  `Volume [...] utilization` и т.д.) продолжается без разрыва.

### Если `Collection status` = Failed

Причина будет в имени проблемы «Data collection failed: …»:

| Текст | Что делать |
|---|---|
| `SSH authentication failed` | проверьте `{$SSMC_SSH_USER}` и `{$SSMC_SSH_PASS}` |
| `SSH connect … timed out` | нет сетевого доступа от сервера к массиву по TCP/22 |
| `invalid collector output: …` | не установлен `paramiko` или у скрипта нет прав на запуск |
| `host key mismatch` | у массива сменился SSH-ключ. Если так и задумано, удалите его строку из `~zabbix/.ssh/ssmc_known_hosts` |
