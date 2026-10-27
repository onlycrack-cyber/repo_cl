#!/usr/bin/env python3
"""Синхронизация устройств NetBox -> хосты Zabbix.

NetBox — источник истины. Скрипт забирает устройства через pynetbox и приводит
к ним хосты Zabbix через JSON-RPC API:

* статус ``active``            -> хост создаётся или обновляется (имя, IP, группы,
                                  шаблоны, теги, прокси, инвентарь);
* статус ``decommissioning``   -> предлагается режим обслуживания (MM);
* ``staged``/``offline``/...   -> хост деактивируется (status = disabled);
* устройство удалено из NetBox -> хост деактивируется.

Скрипт трогает только «свои» хосты: с тегом ``netbox_sync=managed`` (имя и
значение тега задаются в конфиге). Хосты, созданные вручную, не меняются.

Режимы запуска:
    sync        полная синхронизация (по умолчанию; для cron / systemd timer)
    serve       HTTP-приёмник вебхуков NetBox + периодическая полная синхронизация
    approve-mm  подтвердить предложенный режим обслуживания
    check       проверить конфиг и доступ к обоим API, ничего не меняя

Коды выхода: 0 — всё хорошо, 1 — часть хостов с ошибками, 2 — фатальная ошибка
(конфиг, недоступен NetBox/Zabbix, сработала защита от массовой деактивации).

Поддерживаются NetBox 3.x/4.x и Zabbix 6.0+ (основная целевая версия — 7.0).
"""

import argparse
import copy
import hashlib
import hmac
import ipaddress
import json
import logging
import logging.handlers
import os
import queue
import re
import string
import sys
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import requests
import yaml

try:  # fcntl есть только на POSIX; без него работаем без блокировки
    import fcntl
except ImportError:  # pragma: no cover
    fcntl = None

VERSION = '1.0.0'

log = logging.getLogger('netbox_zabbix_sync')


# ---------------------------------------------------------------------------
# Исключения
# ---------------------------------------------------------------------------

class SyncError(Exception):
    """Базовое исключение модуля."""


class ConfigError(SyncError):
    """Ошибка конфигурации: запуск невозможен."""


class NetBoxError(SyncError):
    """NetBox недоступен или вернул ошибку. Синхронизация прерывается целиком."""


class ZabbixError(SyncError):
    """Ошибка, которую вернул API Zabbix (касается одного запроса / хоста)."""


class ZabbixConnectionError(ZabbixError):
    """Zabbix недоступен по сети. Синхронизация прерывается целиком."""


class SkipDevice(SyncError):
    """Устройство нельзя синхронизировать (нет IP, нет имени и т.п.)."""


# ---------------------------------------------------------------------------
# Конфигурация
# ---------------------------------------------------------------------------

# Значения по умолчанию. YAML-конфиг накладывается поверх, переменные
# окружения — поверх YAML.
DEFAULT_CONFIG = {
    'netbox': {
        'url': None,
        'token': None,
        'verify_ssl': True,        # true/false или путь к CA-бандлу
        'timeout': 30,
        'retries': 3,
        'threading': False,        # параллельная пагинация pynetbox
        # Фильтры API /dcim/devices/, например {site: [dc1, dc2], tag: [monitoring]}.
        # Статус сюда НЕ добавляйте: устройства в других статусах нужны,
        # чтобы деактивировать их хосты.
        'filters': {},
        # Откуда брать IP интерфейса, по порядку. primary_ip4 — требование ТЗ;
        # oob_ip (NetBox 4.0+) удобен для iLO/iDRAC.
        'ip_sources': ['primary_ip4'],
    },
    'zabbix': {
        'url': None,
        'token': None,             # API-токен (рекомендуется) ...
        'user': None,              # ... или логин/пароль
        'password': None,
        'verify_ssl': True,
        'timeout': 30,
        'retries': 3,
        # Метка «хост управляется синхронизацией». Только такие хосты скрипт
        # деактивирует, когда устройство пропадает из NetBox.
        'managed_tag': {'tag': 'netbox_sync', 'value': 'managed'},
        # Хост с тем же техническим именем уже есть, но без managed_tag:
        # true — взять под управление, false — пропустить с предупреждением.
        'adopt_existing': False,
        # Включать хост, если устройство снова active (NetBox — источник истины).
        'enable_on_active': True,
        # Прокси: default — для всех, by_site — по slug сайта. Если оба пусты,
        # прокси хоста скрипт не трогает.
        'proxy': {'default': None, 'by_site': {}},
    },
    # Что делать с хостом в зависимости от статуса устройства в NetBox:
    #   sync        — создать/обновить
    #   disable     — деактивировать
    #   maintenance — предложить / включить режим обслуживания (MM)
    #   ignore      — ничего не делать
    'status_actions': {
        'active': 'sync',
        'decommissioning': 'maintenance',
        'staged': 'disable',
        'offline': 'disable',
        'planned': 'disable',
        'failed': 'disable',
        'inventory': 'disable',
    },
    'default_action': 'disable',   # для статусов, которых нет в status_actions
    'missing_action': 'disable',   # устройство удалено из NetBox: disable | ignore
    'interface': {
        'type': 'agent',           # agent | snmp | ipmi | jmx
        'port': None,              # по умолчанию стандартный порт типа
        'details': {},             # для SNMP: {version: 2, community: '{$SNMP_COMMUNITY}'}
    },
    'host_groups': {
        # Шаблоны имён групп. Плейсхолдеры: см. device_context().
        # Группа пропускается, если значение плейсхолдера пустое.
        'patterns': ['NetBox/Sites/{site}', 'NetBox/Roles/{role}'],
        # Группы хоста с этими префиксами считаются «нашими» и приводятся
        # к NetBox; остальные (добавленные вручную) сохраняются.
        'managed_prefixes': ['NetBox/'],
        'create_missing': True,
        'fallback': 'NetBox/Unsorted',
    },
    'templates': {
        # Сопоставление модели устройства шаблонам, сверху вниз.
        #   contains — подстрока после нормализации (регистр, пробелы, точки,
        #              Gen10 == G10): '380 g10' совпадёт с 'ProLiant DL380 Gen10';
        #   regex    — регулярное выражение по исходной строке модели.
        # У правила можно задать свой interface (например SNMP для СХД).
        'rules': [],
        'default': ['ICMP Ping'],  # если ни одно правило не подошло
        'always': [],              # добавляются всем хостам
        'first_match_only': True,  # false — объединить шаблоны всех подошедших правил
        # Имя custom field устройства со списком шаблонов (через запятую);
        # если поле заполнено, оно заменяет результат правил.
        'custom_field': None,
        # При отвязке «нашего» шаблона удалять его элементы с хоста (templates_clear).
        'clear_on_unlink': False,
    },
    # Дополнительные теги хоста: {имя тега: шаблон значения}.
    'extra_tags': {'site': '{site_slug}', 'role': '{role}'},
    'inventory': {
        'enabled': False,
        'fields': {
            'serialno_a': '{serial}',
            'asset_tag': '{asset_tag}',
            'vendor': '{manufacturer}',
            'model': '{model}',
            'location': '{site}',
        },
    },
    # Описание хоста; None — не трогать. Пример: 'NetBox: {url}'
    'description': None,
    'maintenance': {
        # propose — предложить: в терминале спросить, в cron/вебхуке поставить
        #           тег netbox_mm=proposed и ждать `approve-mm`;
        # auto    — сразу создать обслуживание;
        # off     — только записать в лог.
        'mode': 'propose',
        'duration_hours': 72,
        'collect_data': True,      # обслуживание со сбором данных
        'name_prefix': 'NetBox decommissioning: ',
        # Устройство снова active — удалить созданное скриптом обслуживание.
        'remove_on_active': True,
    },
    # Защита от массовой деактивации (ошибка фильтра, пустой ответ NetBox ...).
    # Превышение любого порога — деактивация не выполняется (кроме --force).
    'safety': {'max_disable_count': 20, 'max_disable_ratio': 0.3},
    'webhook': {
        'listen': '127.0.0.1',
        'port': 8085,
        'path': '/netbox/webhook',
        'secret': None,            # секрет вебхука NetBox (HMAC-SHA512)
        'full_sync_interval': 3600,  # полная синхронизация в режиме serve, сек; 0 — выкл.
        'debounce': 2,             # сек: собрать пачку событий перед синхронизацией
    },
    'logging': {
        'level': 'INFO',
        'file': None,
        'max_bytes': 10 * 1024 * 1024,
        'backup_count': 5,
    },
    'lock_file': os.path.join(tempfile.gettempdir(), 'netbox_zabbix_sync.lock'),
    'summary_file': None,          # JSON с итогами последнего запуска
}

# Переменные окружения -> путь в конфиге. Секреты лучше задавать только так.
ENV_OVERRIDES = {
    'NETBOX_URL': ('netbox', 'url'),
    'NETBOX_TOKEN': ('netbox', 'token'),
    'NETBOX_VERIFY_SSL': ('netbox', 'verify_ssl'),
    'ZABBIX_URL': ('zabbix', 'url'),
    'ZABBIX_TOKEN': ('zabbix', 'token'),
    'ZABBIX_USER': ('zabbix', 'user'),
    'ZABBIX_PASSWORD': ('zabbix', 'password'),
    'ZABBIX_VERIFY_SSL': ('zabbix', 'verify_ssl'),
    'NETBOX_WEBHOOK_SECRET': ('webhook', 'secret'),
    'NBZS_LOG_LEVEL': ('logging', 'level'),
    'NBZS_LOG_FILE': ('logging', 'file'),
}

VALID_ACTIONS = ('sync', 'disable', 'maintenance', 'ignore')
INTERFACE_TYPES = {'agent': 1, 'snmp': 2, 'ipmi': 3, 'jmx': 4}
DEFAULT_PORTS = {1: '10050', 2: '161', 3: '623', 4: '12345'}
SECRET_KEYS = (('netbox', 'token'), ('zabbix', 'token'), ('zabbix', 'password'), ('webhook', 'secret'))

_ENV_REF = re.compile(r'\$\{([A-Za-z_][A-Za-z0-9_]*)(?::-([^}]*))?\}')


def deep_merge(base, override):
    """Рекурсивно накладывает override на base (словари сливаются, остальное заменяется)."""
    result = copy.deepcopy(base)
    for key, value in (override or {}).items():
        if isinstance(value, dict) and isinstance(result.get(key), dict):
            result[key] = deep_merge(result[key], value)
        else:
            result[key] = copy.deepcopy(value)
    return result


def expand_env(value):
    """Подставляет ${VAR} и ${VAR:-default} из окружения во все строки конфига."""
    if isinstance(value, dict):
        return {k: expand_env(v) for k, v in value.items()}
    if isinstance(value, list):
        return [expand_env(v) for v in value]
    if isinstance(value, str):
        def repl(match):
            env = os.environ.get(match.group(1))
            if env is None:
                if match.group(2) is None:
                    raise ConfigError('переменная окружения %s не задана' % match.group(1))
                return match.group(2)
            return env
        return _ENV_REF.sub(repl, value)
    return value


def parse_bool_or_path(value):
    """verify_ssl: 'true'/'false' из окружения -> bool, иначе путь к CA-бандлу."""
    if isinstance(value, str):
        low = value.strip().lower()
        if low in ('1', 'true', 'yes', 'on'):
            return True
        if low in ('0', 'false', 'no', 'off'):
            return False
    return value


def load_config(path=None, environ=None):
    """Собирает конфиг: значения по умолчанию <- YAML/JSON <- переменные окружения."""
    environ = os.environ if environ is None else environ
    cfg = copy.deepcopy(DEFAULT_CONFIG)
    if path:
        try:
            with open(path, encoding='utf-8') as fh:
                data = yaml.safe_load(fh) or {}  # JSON — подмножество YAML
        except OSError as exc:
            raise ConfigError('не удалось прочитать конфиг %s: %s' % (path, exc))
        except yaml.YAMLError as exc:
            raise ConfigError('ошибка разбора конфига %s: %s' % (path, exc))
        if not isinstance(data, dict):
            raise ConfigError('конфиг %s должен быть словарём' % path)
        data = expand_env(data)
        cfg = deep_merge(cfg, data)
        _warn_world_readable_secrets(path, data)
    for env, (section, key) in ENV_OVERRIDES.items():
        if environ.get(env):
            cfg[section][key] = environ[env]
    for section in ('netbox', 'zabbix'):
        cfg[section]['verify_ssl'] = parse_bool_or_path(cfg[section]['verify_ssl'])
    validate_config(cfg)
    return cfg


def _warn_world_readable_secrets(path, data):
    """Предупреждает, если секреты лежат в файле, который могут читать все."""
    has_secret = any((data.get(s) or {}).get(k) for s, k in SECRET_KEYS)
    try:
        world_readable = os.stat(path).st_mode & 0o004
    except OSError:
        return
    if has_secret and world_readable:
        log.warning('Конфиг %s содержит секреты и доступен на чтение всем; '
                    'выполните chmod 640 или перенесите секреты в переменные окружения', path)


def validate_config(cfg):
    """Проверяет обязательные параметры и компилирует правила шаблонов."""
    errors = []
    if not cfg['netbox'].get('url'):
        errors.append('не задан netbox.url (NETBOX_URL)')
    if not cfg['netbox'].get('token'):
        errors.append('не задан netbox.token (NETBOX_TOKEN)')
    zbx = cfg['zabbix']
    if not zbx.get('url'):
        errors.append('не задан zabbix.url (ZABBIX_URL)')
    if not zbx.get('token') and not (zbx.get('user') and zbx.get('password')):
        errors.append('для Zabbix нужен token (ZABBIX_TOKEN) или user+password (ZABBIX_USER/ZABBIX_PASSWORD)')
    for status, action in cfg['status_actions'].items():
        if action not in VALID_ACTIONS:
            errors.append('status_actions.%s: неизвестное действие %r' % (status, action))
    if cfg['default_action'] not in VALID_ACTIONS:
        errors.append('default_action: неизвестное действие %r' % cfg['default_action'])
    if cfg['missing_action'] not in ('disable', 'ignore'):
        errors.append('missing_action: допустимо disable или ignore')
    if cfg['maintenance']['mode'] not in ('propose', 'auto', 'off'):
        errors.append('maintenance.mode: допустимо propose, auto или off')
    try:
        interface_spec(cfg['interface'])
    except ConfigError as exc:
        errors.append('interface: %s' % exc)
    for i, rule in enumerate(cfg['templates'].get('rules') or []):
        where = 'templates.rules[%d]' % i
        if not isinstance(rule, dict):
            errors.append('%s: правило должно быть словарём' % where)
            continue
        if not rule.get('templates'):
            errors.append('%s: не указан список templates' % where)
        if not rule.get('contains') and not rule.get('regex'):
            errors.append('%s: нужен contains или regex' % where)
        if rule.get('regex'):
            try:
                rule['_regex'] = re.compile(rule['regex'], re.IGNORECASE)
            except re.error as exc:
                errors.append('%s: неверный regex: %s' % (where, exc))
        if rule.get('contains'):
            values = rule['contains'] if isinstance(rule['contains'], list) else [rule['contains']]
            rule['_contains'] = [normalize_model(v) for v in values]
        if rule.get('interface'):
            try:
                interface_spec(rule['interface'])
            except ConfigError as exc:
                errors.append('%s.interface: %s' % (where, exc))
    if errors:
        raise ConfigError('; '.join(errors))


def interface_spec(icfg):
    """Нормализует описание интерфейса из конфига: (тип Zabbix, порт, details)."""
    itype = INTERFACE_TYPES.get(str(icfg.get('type', 'agent')).lower())
    if itype is None:
        raise ConfigError('неизвестный тип %r (agent|snmp|ipmi|jmx)' % icfg.get('type'))
    port = str(icfg.get('port') or DEFAULT_PORTS[itype])
    details = {k: str(v) for k, v in (icfg.get('details') or {}).items()}
    if itype == 2:
        details.setdefault('version', '2')
        details.setdefault('bulk', '1')
        if details['version'] in ('1', '2'):
            details.setdefault('community', '{$SNMP_COMMUNITY}')
    return itype, port, details


# ---------------------------------------------------------------------------
# Логирование
# ---------------------------------------------------------------------------

def setup_logging(cfg, verbose=False):
    """Лог в stderr (попадает в journald) и, если задан, в файл с ротацией."""
    level = logging.DEBUG if verbose else getattr(logging, str(cfg['level']).upper(), logging.INFO)
    fmt = logging.Formatter('%(asctime)s %(levelname)-7s %(message)s')
    root = logging.getLogger()
    root.handlers[:] = []
    root.setLevel(level)
    stream = logging.StreamHandler(sys.stderr)
    stream.setFormatter(fmt)
    root.addHandler(stream)
    if cfg.get('file'):
        fh = logging.handlers.RotatingFileHandler(
            cfg['file'], maxBytes=int(cfg['max_bytes']), backupCount=int(cfg['backup_count']),
            encoding='utf-8')
        fh.setFormatter(fmt)
        root.addHandler(fh)
    # urllib3 на DEBUG пишет каждый запрос — это шум
    logging.getLogger('urllib3').setLevel(logging.WARNING)


# ---------------------------------------------------------------------------
# Разбор устройства NetBox
# ---------------------------------------------------------------------------

def _name(obj, key='name'):
    """Безопасно достаёт поле вложенного объекта NetBox (может быть None)."""
    return (obj.get(key) or '') if isinstance(obj, dict) else ''


def device_status(dev):
    status = dev.get('status')
    if isinstance(status, dict):
        status = status.get('value')
    return (status or '').lower()


def device_context(dev, netbox_url=''):
    """Плейсхолдеры для шаблонов групп, тегов, инвентаря и описания."""
    dtype = dev.get('device_type') or {}
    role = dev.get('role') or dev.get('device_role') or {}  # NetBox 4.x / 3.x
    site = dev.get('site') or {}
    return {
        'name': dev.get('name') or '',
        'device_id': str(dev.get('id', '')),
        'status': device_status(dev),
        'site': _name(site),
        'site_slug': _name(site, 'slug'),
        'region': _name(site.get('region') if isinstance(site, dict) else None),
        'location': _name(dev.get('location')),
        'rack': _name(dev.get('rack')),
        'role': _name(role),
        'role_slug': _name(role, 'slug'),
        'tenant': _name(dev.get('tenant')),
        'platform': _name(dev.get('platform')),
        'manufacturer': _name(dtype.get('manufacturer')),
        'model': dtype.get('model') or '',
        'model_slug': dtype.get('slug') or '',
        'part_number': dtype.get('part_number') or '',
        'serial': dev.get('serial') or '',
        'asset_tag': dev.get('asset_tag') or '',
        'url': '%s/dcim/devices/%s/' % (netbox_url.rstrip('/'), dev.get('id')),
    }


def render(pattern, ctx, skip_empty=False):
    """Подставляет плейсхолдеры. skip_empty: вернуть None, если хоть одно значение пустое."""
    fields = [f for _, f, _, _ in string.Formatter().parse(pattern) if f]
    for field in fields:
        if field not in ctx:
            raise ConfigError('неизвестный плейсхолдер {%s} в %r' % (field, pattern))
        if skip_empty and not ctx[field]:
            return None
    return pattern.format(**ctx)


def device_ip(dev, sources):
    """IP для интерфейса Zabbix: первый заполненный источник из ip_sources, без маски."""
    for source in sources:
        value = dev.get(source)
        address = value.get('address') if isinstance(value, dict) else value
        if not address:
            continue
        ip = str(address).split('/')[0]
        try:
            ipaddress.ip_address(ip)
        except ValueError:
            log.warning('Устройство %s: некорректный адрес %r в %s', dev.get('name'), address, source)
            continue
        return ip
    return None


def normalize_model(text):
    """'ProLiant DL380 Gen10' -> 'proliantdl380g10', '.360G10' -> '360g10', '380 G9' -> '380g9'."""
    s = re.sub(r'[^0-9a-z]+', '', str(text or '').lower())
    return re.sub(r'gen(?=\d)', 'g', s)


def match_templates(tcfg, dev):
    """Подбирает шаблоны по модели устройства.

    Возвращает (список имён шаблонов, интерфейс из правила или None, описание источника).
    """
    ctx = device_context(dev)
    custom_field = tcfg.get('custom_field')
    if custom_field:
        value = (dev.get('custom_fields') or {}).get(custom_field)
        if value:
            names = value if isinstance(value, list) else [v.strip() for v in str(value).split(',')]
            names = [n for n in names if n]
            if names:
                return _unique(names + list(tcfg.get('always') or [])), None, 'custom field %s' % custom_field

    raw = [ctx['model'], '%s %s' % (ctx['manufacturer'], ctx['model']), ctx['part_number'], ctx['model_slug']]
    raw = [r.strip() for r in raw if r.strip()]
    normalized = [normalize_model(r) for r in raw]

    names, interface, sources = [], None, []
    for i, rule in enumerate(tcfg.get('rules') or []):
        hit = False
        if rule.get('_contains'):
            hit = any(c and c in n for c in rule['_contains'] for n in normalized)
        if not hit and rule.get('_regex') is not None:
            hit = any(rule['_regex'].search(r) for r in raw)
        if not hit:
            continue
        names.extend(rule['templates'])
        sources.append(rule.get('name') or 'rule #%d' % i)
        if interface is None and rule.get('interface'):
            interface = rule['interface']
        if tcfg.get('first_match_only', True):
            break
    if not sources:
        names = list(tcfg.get('default') or [])
        sources = ['default']
    return _unique(names + list(tcfg.get('always') or [])), interface, ', '.join(sources)


def _unique(items):
    seen, out = set(), []
    for item in items:
        if item not in seen:
            seen.add(item)
            out.append(item)
    return out


def zabbix_host_name(name):
    """Техническое имя хоста Zabbix: допустимы только [0-9A-Za-z_. -], до 128 символов."""
    return re.sub(r'[^0-9A-Za-z_. \-]', '_', name).strip()[:128]


class DesiredHost(object):
    """Каким хост должен быть в Zabbix по данным NetBox (имена, ещё не ID)."""

    def __init__(self, **kw):
        self.device_id = kw['device_id']
        self.nb_name = kw['nb_name']
        self.host = kw['host']
        self.name = kw['name']
        self.status = kw['status']
        self.ip = kw['ip']
        self.interface = kw['interface']        # (type, port, details)
        self.groups = kw['groups']
        self.templates = kw['templates']
        self.template_source = kw['template_source']
        self.tags = kw['tags']                  # [{'tag', 'value'}]
        self.proxy = kw['proxy']                # имя прокси, None — сервер, False — не управлять
        self.description = kw['description']    # None — не управлять
        self.inventory = kw['inventory']        # None — не управлять


# ---------------------------------------------------------------------------
# Клиент Zabbix API
# ---------------------------------------------------------------------------

class ZabbixAPI(object):
    """Минимальный клиент JSON-RPC Zabbix: токен или логин/пароль, повторы, версии 6.0+."""

    def __init__(self, url, token=None, user=None, password=None, verify=True, timeout=30,
                 retries=3, session=None):
        if not url.rstrip('/').endswith('api_jsonrpc.php'):
            url = url.rstrip('/') + '/api_jsonrpc.php'
        self.url = url
        self.token = token
        self.user = user
        self.password = password
        self.verify = verify
        self.timeout = timeout
        self.retries = max(1, int(retries))
        self.session = session or requests.Session()
        self.version = (0, 0)
        self._auth = None
        self._logged_in = False
        self._id = 0

    @classmethod
    def from_config(cls, zcfg):
        return cls(zcfg['url'], token=zcfg.get('token'), user=zcfg.get('user'),
                   password=zcfg.get('password'), verify=zcfg.get('verify_ssl', True),
                   timeout=zcfg.get('timeout', 30), retries=zcfg.get('retries', 3))

    def login(self):
        """Определяет версию API и аутентифицируется."""
        raw = self.call('apiinfo.version', {})
        self.version = tuple(int(x) for x in re.findall(r'\d+', raw)[:2])
        if self.version < (6, 0):
            raise ZabbixError('Zabbix %s не поддерживается, нужен 6.0+' % raw)
        if self.token:
            self._auth = self.token
            self.call('hostgroup.get', {'output': ['groupid'], 'limit': 1})  # проверка токена
            log.info('Zabbix %s: вход по API-токену', raw)
        else:
            self._auth = self.call('user.login', {'username': self.user, 'password': self.password})
            self._logged_in = True
            log.info('Zabbix %s: вход под пользователем %s', raw, self.user)

    def logout(self):
        if self._logged_in:
            try:
                self.call('user.logout', [])
            except ZabbixError as exc:
                log.debug('user.logout: %s', exc)
            self._logged_in = False

    def call(self, method, params=None, _relogin=True):
        self._id += 1
        payload = {'jsonrpc': '2.0', 'method': method,
                   'params': {} if params is None else params, 'id': self._id}
        headers = {'Content-Type': 'application/json-rpc'}
        if self._auth and method not in ('apiinfo.version', 'user.login'):
            if self.version >= (6, 4):
                headers['Authorization'] = 'Bearer ' + self._auth
            else:
                payload['auth'] = self._auth
        data = self._post(method, payload, headers)
        if 'error' in data:
            err = data['error']
            text = '%s: %s %s' % (method, err.get('message', ''), err.get('data', ''))
            # Сессия по логину/паролю истекла (долгоживущий режим serve) — входим заново
            if (_relogin and self._logged_in and
                    re.search(r'session terminated|not authori[sz]ed|re-login', text, re.I)):
                log.info('Сессия Zabbix истекла, повторный вход')
                self._logged_in = False
                self._auth = self.call('user.login', {'username': self.user, 'password': self.password})
                self._logged_in = True
                return self.call(method, params, _relogin=False)
            raise ZabbixError(text.strip())
        return data['result']

    def _post(self, method, payload, headers):
        # Чтение можно повторять при любом сбое. Запись — только если запрос
        # точно не дошёл (ошибка соединения), иначе возможен дубликат.
        readonly = method.endswith('.get') or method == 'apiinfo.version'
        delay = 1
        for attempt in range(1, self.retries + 1):
            try:
                resp = self.session.post(self.url, json=payload, headers=headers,
                                         timeout=self.timeout, verify=self.verify)
                if resp.status_code >= 500 and readonly and attempt < self.retries:
                    raise requests.ConnectionError('HTTP %s' % resp.status_code)
                resp.raise_for_status()
                return resp.json()
            except requests.ReadTimeout as exc:
                last = exc
                if not readonly:
                    break
            except requests.ConnectionError as exc:
                last = exc
            except (requests.RequestException, ValueError) as exc:
                raise ZabbixConnectionError('%s: ошибка обращения к Zabbix API: %s' % (method, exc))
            if attempt < self.retries:
                log.warning('Zabbix API недоступен (%s), попытка %d/%d, повтор через %d с',
                            last, attempt, self.retries, delay)
                time.sleep(delay)
                delay *= 2
        raise ZabbixConnectionError('%s: Zabbix API недоступен: %s' % (method, last))


# ---------------------------------------------------------------------------
# Источник NetBox
# ---------------------------------------------------------------------------

class _TimeoutSession(requests.Session):
    """requests.Session с таймаутом по умолчанию (pynetbox таймаут не передаёт)."""

    def __init__(self, timeout):
        super(_TimeoutSession, self).__init__()
        self._timeout = timeout

    def request(self, *args, **kwargs):
        kwargs.setdefault('timeout', self._timeout)
        return super(_TimeoutSession, self).request(*args, **kwargs)


class NetBoxSource(object):
    """Чтение устройств из NetBox через pynetbox. Устройства отдаются обычными dict."""

    def __init__(self, ncfg):
        try:
            import pynetbox
        except ImportError:
            raise ConfigError('не установлен pynetbox: pip install pynetbox')
        from requests.adapters import HTTPAdapter
        from urllib3.util.retry import Retry

        self._pynetbox = pynetbox
        self.url = ncfg['url'].rstrip('/')
        self.filters = dict(ncfg.get('filters') or {})
        self.api = pynetbox.api(self.url, token=ncfg['token'], threading=bool(ncfg.get('threading')))
        session = _TimeoutSession(ncfg.get('timeout', 30))
        session.verify = ncfg.get('verify_ssl', True)
        retry = Retry(total=int(ncfg.get('retries', 3)), backoff_factor=1,
                      status_forcelist=(429, 502, 503, 504), allowed_methods=frozenset(['GET']))
        session.mount('http://', HTTPAdapter(max_retries=retry))
        session.mount('https://', HTTPAdapter(max_retries=retry))
        self.api.http_session = session

    def _wrap(self, what, func):
        try:
            return func()
        except self._pynetbox.RequestError as exc:
            raise NetBoxError('%s: NetBox вернул ошибку: %s' % (what, getattr(exc, 'error', exc)))
        except requests.RequestException as exc:
            raise NetBoxError('%s: NetBox недоступен: %s' % (what, exc))
        except ValueError as exc:  # не JSON (прокси, страница логина)
            raise NetBoxError('%s: некорректный ответ NetBox: %s' % (what, exc))

    def status(self):
        return self._wrap('status', self.api.status)

    def fetch_devices(self, extra_filters=None):
        """Все устройства под фильтрами (в любом статусе)."""
        filters = dict(self.filters)
        filters.update(extra_filters or {})

        def fetch():
            records = (self.api.dcim.devices.filter(**filters) if filters
                       else self.api.dcim.devices.all())
            return [dict(r) for r in records]
        devices = self._wrap('dcim/devices', fetch)
        log.info('NetBox: получено устройств: %d (фильтры: %s)', len(devices), filters or 'нет')
        return devices

    def get_device(self, device_id):
        """Одно устройство по ID или None, если удалено / не проходит фильтры."""
        filters = dict(self.filters)
        filters['id'] = device_id
        records = self._wrap('dcim/devices/%s' % device_id,
                             lambda: [dict(r) for r in self.api.dcim.devices.filter(**filters)])
        return records[0] if records else None


# ---------------------------------------------------------------------------
# Итоги запуска
# ---------------------------------------------------------------------------

class Summary(object):
    """Счётчики и списки хостов по результату; пишется в лог и summary_file."""

    FIELDS = ('created', 'updated', 'unchanged', 'disabled', 'skipped', 'errors',
              'mm_proposed', 'mm_created', 'mm_removed')

    def __init__(self, mode='sync', dry_run=False):
        self.mode = mode
        self.dry_run = dry_run
        self.started = time.time()
        self.fatal = None
        for field in self.FIELDS:
            setattr(self, field, [])

    def as_dict(self):
        data = {'mode': self.mode, 'dry_run': self.dry_run, 'version': VERSION,
                'started': int(self.started), 'duration': round(time.time() - self.started, 1),
                'fatal': self.fatal}
        for field in self.FIELDS:
            data[field] = getattr(self, field)
            data[field + '_count'] = len(getattr(self, field))
        return data

    def exit_code(self):
        if self.fatal:
            return 2
        return 1 if self.errors else 0

    def log(self):
        parts = ['%s=%d' % (f, len(getattr(self, f))) for f in self.FIELDS]
        prefix = '[DRY-RUN] ' if self.dry_run else ''
        log.info('%sИтог: %s', prefix, ', '.join(parts))
        if self.mm_proposed:
            log.warning('Предложено обслуживание (MM) для: %s. Подтвердить: '
                        'netbox_zabbix_sync.py approve-mm %s', ', '.join(self.mm_proposed),
                        ' '.join(self.mm_proposed))
        if self.fatal:
            log.error('Синхронизация прервана: %s', self.fatal)


# ---------------------------------------------------------------------------
# Синхронизация
# ---------------------------------------------------------------------------

class Syncer(object):
    """Приводит хосты Zabbix к состоянию устройств NetBox."""

    INVENTORY_MODE_MANUAL = 0
    INVENTORY_MODE_DISABLED = -1

    def __init__(self, cfg, netbox, zapi, dry_run=False, interactive=False, prompt=None):
        self.cfg = cfg
        self.nb = netbox
        self.zapi = zapi
        self.dry_run = dry_run
        self.interactive = interactive
        self.prompt = prompt or _ask_yes_no
        mt = cfg['zabbix']['managed_tag']
        self.managed_tag = (mt['tag'], mt['value'])
        self.our_tag_names = ({mt['tag'], 'netbox_device_id', 'netbox_status', 'netbox_mm'} |
                              set(cfg.get('extra_tags') or {}))
        pcfg = cfg['zabbix'].get('proxy') or {}
        self.manage_proxy = bool(pcfg.get('default') or pcfg.get('by_site'))
        self.groups = {}
        self.templates = {}
        self.proxies = {}
        self.maintenances = {}

    # ---- кэши справочников Zabbix ------------------------------------------

    def load_caches(self):
        """Группы, шаблоны, прокси и «наши» обслуживания: по одному запросу на справочник."""
        z = self.zapi
        self.groups = {g['name']: g['groupid'] for g in
                       z.call('hostgroup.get', {'output': ['groupid', 'name']})}
        self.templates = {}
        for t in z.call('template.get', {'output': ['templateid', 'host', 'name']}):
            self.templates[t['host']] = t['templateid']
            self.templates[t['name']] = t['templateid']
        self.proxies = {}
        if self.manage_proxy:
            name_field = 'name' if z.version >= (7, 0) else 'host'
            for p in z.call('proxy.get', {'output': ['proxyid', name_field]}):
                self.proxies[p[name_field]] = p['proxyid']
        prefix = self.cfg['maintenance']['name_prefix']
        self.maintenances = {m['name']: m for m in z.call('maintenance.get', {
            'output': ['maintenanceid', 'name', 'active_till'],
            'search': {'name': prefix}, 'startSearch': True, 'selectHosts': ['hostid']})}

    def managed_template_ids(self):
        """ID шаблонов, которыми управляет синхронизация (все упомянутые в конфиге)."""
        tcfg = self.cfg['templates']
        names = list(tcfg.get('default') or []) + list(tcfg.get('always') or [])
        for rule in tcfg.get('rules') or []:
            names.extend(rule['templates'])
        return {self.templates[n] for n in names if n in self.templates}

    # ---- чтение хостов -----------------------------------------------------

    def _host_get_params(self):
        z = self.zapi
        groups_key = 'selectHostGroups' if z.version >= (6, 2) else 'selectGroups'
        output = ['hostid', 'host', 'name', 'status', 'description', 'inventory_mode']
        output += ['monitored_by', 'proxyid'] if z.version >= (7, 0) else ['proxy_hostid']
        params = {
            'output': output,
            groups_key: ['groupid', 'name'],
            'selectParentTemplates': ['templateid', 'host', 'name'],
            'selectInterfaces': ['interfaceid', 'type', 'main', 'useip', 'ip', 'dns', 'port', 'details'],
            'selectTags': ['tag', 'value'],
        }
        if self.cfg['inventory']['enabled']:
            params['selectInventory'] = list(self.cfg['inventory']['fields'])
        return params

    def _norm_host(self, h):
        h['groups'] = h.pop('hostgroups', None) or h.get('groups') or []
        h['parentTemplates'] = h.get('parentTemplates') or []
        h['tags'] = h.get('tags') or []
        h['interfaces'] = h.get('interfaces') or []
        return h

    def get_managed_hosts(self):
        """{device_id: host} для хостов с managed_tag."""
        params = self._host_get_params()
        params['tags'] = [{'tag': self.managed_tag[0], 'value': self.managed_tag[1], 'operator': 1}]
        result = {}
        for h in self.zapi.call('host.get', params):
            self._norm_host(h)
            dev_id = _tag_value(h, 'netbox_device_id')
            if not dev_id or not dev_id.isdigit():
                log.warning('Хост %s помечен как управляемый, но без корректного тега '
                            'netbox_device_id — пропущен', h['host'])
                continue
            if int(dev_id) in result:
                log.warning('Хосты %s и %s ссылаются на одно устройство NetBox #%s; используется первый',
                            result[int(dev_id)]['host'], h['host'], dev_id)
                continue
            result[int(dev_id)] = h
        log.info('Zabbix: управляемых хостов: %d', len(result))
        return result

    def get_hosts_by_name(self, names):
        if not names:
            return {}
        params = self._host_get_params()
        params['filter'] = {'host': sorted(names)}
        return {h['host']: self._norm_host(h) for h in self.zapi.call('host.get', params)}

    # ---- построение желаемого состояния ------------------------------------

    def build_desired(self, dev):
        cfg = self.cfg
        nb_name = dev.get('name')
        if not nb_name:
            raise SkipDevice('у устройства #%s нет имени' % dev.get('id'))
        host = zabbix_host_name(nb_name)
        if not host:
            raise SkipDevice('имя %r не годится для Zabbix' % nb_name)
        ip = device_ip(dev, cfg['netbox']['ip_sources'])
        if not ip:
            raise SkipDevice('нет IP (%s)' % ', '.join(cfg['netbox']['ip_sources']))
        ctx = device_context(dev, cfg['netbox']['url'])

        templates, rule_iface, source = match_templates(cfg['templates'], dev)
        iface = interface_spec(rule_iface or cfg['interface'])

        gcfg = cfg['host_groups']
        groups = [g for g in (render(p, ctx, skip_empty=True) for p in gcfg['patterns']) if g]
        if not groups and gcfg.get('fallback'):
            groups = [gcfg['fallback']]
        if not groups:
            raise SkipDevice('не удалось определить ни одной группы')

        tags = [{'tag': self.managed_tag[0], 'value': self.managed_tag[1]},
                {'tag': 'netbox_device_id', 'value': str(dev['id'])},
                {'tag': 'netbox_status', 'value': ctx['status']}]
        for tag, pattern in (cfg.get('extra_tags') or {}).items():
            value = render(pattern, ctx, skip_empty=True)
            if value:
                tags.append({'tag': tag, 'value': value})

        proxy = False
        if self.manage_proxy:
            pcfg = cfg['zabbix']['proxy']
            proxy = (pcfg.get('by_site') or {}).get(ctx['site_slug']) or pcfg.get('default') or None

        inventory = None
        if cfg['inventory']['enabled']:
            inventory = {f: render(p, ctx) for f, p in cfg['inventory']['fields'].items()}

        description = render(cfg['description'], ctx) if cfg.get('description') else None

        return DesiredHost(device_id=dev['id'], nb_name=nb_name, host=host, name=nb_name,
                           status=ctx['status'], ip=ip, interface=iface, groups=groups,
                           templates=templates, template_source=source, tags=tags, proxy=proxy,
                           description=description, inventory=inventory)

    def resolve_groups(self, names):
        """Имена групп -> ID, недостающие создаются (если разрешено)."""
        ids = []
        for name in names:
            if name not in self.groups:
                if not self.cfg['host_groups']['create_missing']:
                    log.error('Группа хостов %r не существует (create_missing=false) — пропущена', name)
                    continue
                if self.dry_run:
                    log.info('[DRY-RUN] Будет создана группа хостов %r', name)
                    self.groups[name] = 'dry-run:' + name
                else:
                    self.groups[name] = self.zapi.call('hostgroup.create', {'name': name})['groupids'][0]
                    log.info('Создана группа хостов %r', name)
            ids.append(self.groups[name])
        return ids

    def resolve_templates(self, names, host):
        ids = []
        for name in names:
            if name in self.templates:
                ids.append(self.templates[name])
            else:
                log.error('Хост %s: шаблон %r не найден в Zabbix — пропущен', host, name)
        return _unique(ids)

    def resolve_proxy(self, desired):
        """(monitored_by/proxy params) или None, если прокси не управляем / не найден."""
        if desired.proxy is False:
            return None
        if desired.proxy and desired.proxy not in self.proxies:
            log.error('Хост %s: прокси %r не найден в Zabbix — прокси не меняется',
                      desired.host, desired.proxy)
            return None
        proxyid = self.proxies.get(desired.proxy, '0') if desired.proxy else '0'
        if self.zapi.version >= (7, 0):
            return {'monitored_by': '1' if proxyid != '0' else '0', 'proxyid': proxyid}
        return {'proxy_hostid': proxyid}

    # ---- создание / обновление ---------------------------------------------

    def interface_params(self, desired):
        itype, port, details = desired.interface
        iface = {'type': itype, 'main': 1, 'useip': 1, 'ip': desired.ip, 'dns': '', 'port': port}
        if details:
            iface['details'] = details
        return iface

    def create_host(self, desired, summary):
        params = {
            'host': desired.host,
            'name': desired.name,
            'status': 0,
            'groups': [{'groupid': g} for g in self.resolve_groups(desired.groups)],
            'templates': [{'templateid': t} for t in self.resolve_templates(desired.templates, desired.host)],
            'interfaces': [self.interface_params(desired)],
            'tags': desired.tags,
        }
        if desired.description is not None:
            params['description'] = desired.description
        if desired.inventory is not None:
            params['inventory_mode'] = self.INVENTORY_MODE_MANUAL
            params['inventory'] = desired.inventory
        proxy = self.resolve_proxy(desired)
        if proxy:
            params.update(proxy)
        info = 'IP %s, группы [%s], шаблоны [%s] (%s)' % (
            desired.ip, ', '.join(desired.groups), ', '.join(desired.templates), desired.template_source)
        if self.dry_run:
            log.info('[DRY-RUN] Будет создан хост %s: %s', desired.host, info)
        else:
            self.zapi.call('host.create', params)
            log.info('Создан хост %s: %s', desired.host, info)
        summary.created.append(desired.host)

    def diff_host(self, desired, host):
        """Сравнивает хост с желаемым состоянием.

        Возвращает (параметры host.update, операции с интерфейсом, список изменений для лога).
        """
        params, changes, iface_ops = {}, [], []

        if host['host'] != desired.host:
            params['host'] = desired.host
            changes.append('имя %s -> %s' % (host['host'], desired.host))
        if host.get('name') != desired.name:
            params['name'] = desired.name
            changes.append('видимое имя %r -> %r' % (host.get('name'), desired.name))
        if str(host.get('status')) == '1' and self.cfg['zabbix']['enable_on_active']:
            params['status'] = 0
            changes.append('хост включён')

        # Группы: «наши» (по префиксу) приводим к NetBox, остальные сохраняем
        prefixes = tuple(self.cfg['host_groups']['managed_prefixes'] or ())
        desired_gids = set(self.resolve_groups(desired.groups))
        current = {g['groupid']: g['name'] for g in host['groups']}
        keep = {gid for gid, name in current.items() if not (prefixes and name.startswith(prefixes))}
        new_gids = keep | desired_gids
        if new_gids and new_gids != set(current):
            params['groups'] = [{'groupid': g} for g in sorted(new_gids)]
            added = [n for n in desired.groups if self.groups.get(n) not in current]
            removed = [current[g] for g in set(current) - new_gids]
            changes.append('группы +[%s] -[%s]' % (', '.join(added), ', '.join(sorted(removed))))

        # Шаблоны: «наши» (упомянутые в конфиге) приводим, чужие сохраняем
        managed = self.managed_template_ids()
        desired_tids = set(self.resolve_templates(desired.templates, desired.host))
        current_t = {t['templateid']: t['name'] for t in host['parentTemplates']}
        new_tids = {t for t in current_t if t not in managed} | desired_tids
        if new_tids != set(current_t):
            params['templates'] = [{'templateid': t} for t in sorted(new_tids)]
            removed = set(current_t) - new_tids
            if removed and self.cfg['templates']['clear_on_unlink']:
                params['templates_clear'] = [{'templateid': t} for t in sorted(removed)]
            added = [n for n in desired.templates if self.templates.get(n) in new_tids - set(current_t)]
            changes.append('шаблоны +[%s] -[%s]' % (
                ', '.join(added), ', '.join(sorted(current_t[t] for t in removed))))

        # Теги: свои заменяем, чужие сохраняем
        others = [{'tag': t['tag'], 'value': t['value']} for t in host['tags']
                  if t['tag'] not in self.our_tag_names]
        new_tags = others + desired.tags
        if _tag_set(new_tags) != _tag_set(host['tags']):
            params['tags'] = new_tags
            changes.append('теги')

        if desired.description is not None and host.get('description', '') != desired.description:
            params['description'] = desired.description
            changes.append('описание')

        proxy = self.resolve_proxy(desired)
        if proxy and any(str(host.get(k, '0')) != str(v) for k, v in proxy.items()):
            params.update(proxy)
            changes.append('прокси -> %s' % (desired.proxy or 'сервер'))

        if desired.inventory is not None:
            current_inv = host.get('inventory') or {}
            if not isinstance(current_inv, dict):  # Zabbix отдаёт [] при выключенном инвентаре
                current_inv = {}
            if str(host.get('inventory_mode')) == str(self.INVENTORY_MODE_DISABLED):
                params['inventory_mode'] = self.INVENTORY_MODE_MANUAL
            diff = {k: v for k, v in desired.inventory.items() if current_inv.get(k, '') != v}
            if diff or 'inventory_mode' in params:
                params['inventory'] = desired.inventory
                changes.append('инвентарь [%s]' % ', '.join(sorted(diff)))

        # Главный интерфейс нужного типа: обновляем IP/порт или создаём
        itype, port, details = desired.interface
        main = [i for i in host['interfaces'] if str(i['type']) == str(itype) and str(i['main']) == '1']
        if main:
            cur = main[0]
            cur_details = cur.get('details') if isinstance(cur.get('details'), dict) else {}
            upd = {}
            if cur['ip'] != desired.ip or str(cur.get('useip')) != '1':
                upd.update(ip=desired.ip, useip=1)
                changes.append('IP %s -> %s' % (cur['ip'] or cur.get('dns'), desired.ip))
            if str(cur['port']) != port:
                upd['port'] = port
                changes.append('порт %s -> %s' % (cur['port'], port))
            if any(str(cur_details.get(k)) != v for k, v in details.items()):
                merged = {k: str(v) for k, v in cur_details.items()}
                merged.update(details)
                upd['details'] = merged
                changes.append('параметры интерфейса')
            if upd:
                upd['interfaceid'] = cur['interfaceid']
                iface_ops.append(('hostinterface.update', upd))
        else:
            iface = self.interface_params(desired)
            iface['hostid'] = host['hostid']
            iface_ops.append(('hostinterface.create', iface))
            changes.append('добавлен интерфейс %s:%s' % (desired.ip, port))
        return params, iface_ops, changes

    def update_host(self, desired, host, summary):
        params, iface_ops, changes = self.diff_host(desired, host)
        mm = self._our_maintenance(host)
        if mm and self.cfg['maintenance']['remove_on_active']:
            changes.append('снято обслуживание %r' % mm['name'])
        if not changes:
            log.debug('Хост %s без изменений', desired.host)
            summary.unchanged.append(desired.host)
            return
        text = '; '.join(changes)
        if self.dry_run:
            log.info('[DRY-RUN] Будет обновлён хост %s: %s', desired.host, text)
        else:
            if params:
                params['hostid'] = host['hostid']
                self.zapi.call('host.update', params)
            for method, iface in iface_ops:
                self.zapi.call(method, iface)
            if mm and self.cfg['maintenance']['remove_on_active']:
                self._delete_maintenance(mm)
                summary.mm_removed.append(desired.host)
            log.info('Обновлён хост %s: %s', desired.host, text)
        summary.updated.append(desired.host)

    def disable_host(self, host, reason, status, summary):
        tags = [t for t in host['tags'] if t['tag'] != 'netbox_status'] + \
            [{'tag': 'netbox_status', 'value': status}]
        if self.dry_run:
            log.info('[DRY-RUN] Будет деактивирован хост %s (%s)', host['host'], reason)
        else:
            self.zapi.call('host.update', {'hostid': host['hostid'], 'status': 1,
                                           'tags': _clean_tags(tags)})
            log.info('Деактивирован хост %s (%s)', host['host'], reason)
        summary.disabled.append(host['host'])

    # ---- режим обслуживания ------------------------------------------------

    def _mm_name(self, host):
        return self.cfg['maintenance']['name_prefix'] + host['host']

    def _our_maintenance(self, host):
        mm = self.maintenances.get(self._mm_name(host))
        if mm and any(h['hostid'] == host['hostid'] for h in mm.get('hosts') or []):
            return mm
        return None

    def _delete_maintenance(self, mm):
        self.zapi.call('maintenance.delete', [mm['maintenanceid']])
        self.maintenances.pop(mm['name'], None)
        log.info('Удалено обслуживание %r', mm['name'])

    def create_maintenance(self, host, reason, summary, hours=None):
        mcfg = self.cfg['maintenance']
        hours = float(hours or mcfg['duration_hours'])
        name = self._mm_name(host)
        now = int(time.time())
        period = int(hours * 3600)
        if self.dry_run:
            log.info('[DRY-RUN] Будет создано обслуживание %r на %g ч', name, hours)
            summary.mm_created.append(host['host'])
            return
        old = self.maintenances.get(name)
        if old:  # просроченное обслуживание с тем же именем
            self._delete_maintenance(old)
        result = self.zapi.call('maintenance.create', {
            'name': name,
            'active_since': now,
            'active_till': now + period,
            'maintenance_type': 0 if mcfg['collect_data'] else 1,
            'description': 'Создано netbox_zabbix_sync: %s' % reason,
            'hosts': [{'hostid': host['hostid']}],
            'timeperiods': [{'timeperiod_type': 0, 'start_date': now, 'period': period}],
        })
        self.maintenances[name] = {'maintenanceid': result['maintenanceids'][0], 'name': name,
                                   'active_till': str(now + period),
                                   'hosts': [{'hostid': host['hostid']}]}
        self._set_mm_tag(host, 'active')
        log.info('Хост %s переведён в обслуживание %r на %g ч (%s)', host['host'], name, hours, reason)
        summary.mm_created.append(host['host'])

    def _set_mm_tag(self, host, value):
        tags = [t for t in host['tags'] if t['tag'] != 'netbox_mm']
        if value:
            tags.append({'tag': 'netbox_mm', 'value': value})
        if _tag_set(tags) == _tag_set(host['tags']):
            return
        if not self.dry_run:
            self.zapi.call('host.update', {'hostid': host['hostid'], 'tags': _clean_tags(tags)})
        host['tags'] = tags

    def handle_maintenance(self, dev, host, summary):
        """Устройство в статусе decommissioning: предложить или включить MM."""
        status = device_status(dev)
        name = dev.get('name') or '#%s' % dev.get('id')
        if host is None:
            log.info('Устройство %s (%s): хоста в Zabbix нет, обслуживание не требуется', name, status)
            summary.skipped.append({'host': name, 'reason': 'нет хоста для MM'})
            return
        if str(host.get('status')) == '1':
            log.info('Хост %s деактивирован, обслуживание не требуется', host['host'])
            summary.unchanged.append(host['host'])
            return
        mm = self._our_maintenance(host)
        if mm and int(mm.get('active_till') or 0) > time.time():
            log.debug('Хост %s уже в обслуживании %r', host['host'], mm['name'])
            summary.unchanged.append(host['host'])
            return
        reason = 'устройство %s в NetBox в статусе %s' % (name, status)
        mode = self.cfg['maintenance']['mode']
        if mode == 'off':
            log.warning('Хост %s: %s; обслуживание отключено настройкой maintenance.mode=off',
                        host['host'], reason)
            summary.unchanged.append(host['host'])
        elif mode == 'auto':
            self.create_maintenance(host, reason, summary)
        elif self.interactive and not self.dry_run:
            question = ('Устройство %s выведено из эксплуатации (%s). Перевести хост %s '
                        'в режим обслуживания на %s ч? [y/N]: ' % (
                            name, status, host['host'], self.cfg['maintenance']['duration_hours']))
            if self.prompt(question):
                self.create_maintenance(host, reason, summary)
            else:
                log.info('Обслуживание для %s отклонено оператором', host['host'])
                summary.unchanged.append(host['host'])
        else:
            if _tag_value(host, 'netbox_mm') != 'proposed':
                self._set_mm_tag(host, 'proposed')
            log.warning('Хост %s: %s. Предлагается режим обслуживания: '
                        'netbox_zabbix_sync.py approve-mm %s', host['host'], reason, host['host'])
            summary.mm_proposed.append(host['host'])

    def approve_maintenance(self, names, hours=None):
        """`approve-mm`: создать обслуживание для предложенных хостов (или всех: 'all')."""
        summary = Summary('approve-mm', self.dry_run)
        self.load_caches()
        hosts = list(self.get_managed_hosts().values())
        if names == ['all']:
            targets = [h for h in hosts if _tag_value(h, 'netbox_mm') == 'proposed']
            if not targets:
                log.info('Нет хостов с предложенным обслуживанием')
        else:
            wanted = set(names)
            targets = [h for h in hosts if h['host'] in wanted or h.get('name') in wanted]
            for missing in wanted - {h['host'] for h in targets} - {h.get('name') for h in targets}:
                log.error('Хост %s не найден среди управляемых хостов', missing)
                summary.errors.append({'host': missing, 'error': 'не найден'})
        for host in targets:
            self._guard(summary, host['host'], self.create_maintenance, host,
                        'подтверждено оператором (approve-mm)', summary, hours)
        return summary

    # ---- основной цикл -----------------------------------------------------

    def _guard(self, summary, name, func, *args):
        """Ошибка одного хоста не останавливает синхронизацию остальных.

        Недоступность Zabbix (ZabbixConnectionError) пробрасывается — продолжать бессмысленно.
        """
        try:
            func(*args)
        except ZabbixConnectionError:
            raise
        except SkipDevice as exc:
            log.warning('Устройство %s пропущено: %s', name, exc)
            summary.skipped.append({'host': name, 'reason': str(exc)})
        except (ZabbixError, ConfigError) as exc:
            log.error('Хост %s: ошибка: %s', name, exc)
            summary.errors.append({'host': name, 'error': str(exc)})
        except Exception as exc:  # неожиданная ошибка — в лог с трейсбеком, идём дальше
            log.exception('Хост %s: непредвиденная ошибка', name)
            summary.errors.append({'host': name, 'error': repr(exc)})

    def sync_device(self, dev, host, summary):
        desired = self.build_desired(dev)
        if host is None:
            self.create_host(desired, summary)
        else:
            self.update_host(desired, host, summary)

    def action_for(self, status):
        return self.cfg['status_actions'].get(status, self.cfg['default_action'])

    def run(self, devices, missing_ids=(), full=True, force=False, mode='sync'):
        """Обрабатывает список устройств NetBox.

        devices      — устройства NetBox (dict);
        missing_ids  — ID устройств, которых больше нет (для частичной синхронизации);
        full         — полная синхронизация: «наши» хосты без устройства деактивируются
                       и работает защита от массовой деактивации.
        """
        summary = Summary(mode, self.dry_run)
        self.load_caches()
        managed = self.get_managed_hosts()

        sync_items, mm_items, disable_plan = [], [], []
        seen = {d['id'] for d in devices}
        for dev in devices:
            action = self.action_for(device_status(dev))
            host = managed.get(dev['id'])
            if action == 'sync':
                sync_items.append([dev, host])
            elif action == 'maintenance':
                mm_items.append((dev, host))
            elif action == 'disable' and host is not None:
                disable_plan.append((host, 'статус в NetBox: %s' % device_status(dev), device_status(dev)))
            elif action == 'ignore':
                log.debug('Устройство %s (%s) игнорируется', dev.get('name'), device_status(dev))

        # Хоста по тегу нет — ищем по техническому имени (хост создан вручную
        # или устройство пересоздано в NetBox с новым ID).
        adopted = set()
        lookup = {}
        for item in sync_items:
            if item[1] is None and item[0].get('name'):
                lookup[zabbix_host_name(item[0]['name'])] = item
        for hname, h in self.get_hosts_by_name(set(lookup)).items():
            dev, _ = lookup[hname]
            other = _tag_value(h, 'netbox_device_id')
            is_ours = _tag_value(h, self.managed_tag[0]) == self.managed_tag[1]
            if is_ours and other and other.isdigit() and int(other) in seen and int(other) != dev['id']:
                log.error('Конфликт имён: хост %s уже привязан к устройству NetBox #%s, '
                          'устройство #%s пропущено', hname, other, dev['id'])
                summary.errors.append({'host': hname, 'error': 'конфликт имён устройств NetBox'})
                sync_items.remove(lookup[hname])
                continue
            if not is_ours and not self.cfg['zabbix']['adopt_existing']:
                log.warning('Хост %s уже есть в Zabbix, но не управляется синхронизацией '
                            '(нет тега %s=%s; включите zabbix.adopt_existing) — пропущен',
                            hname, *self.managed_tag)
                summary.skipped.append({'host': hname, 'reason': 'существующий неуправляемый хост'})
                sync_items.remove(lookup[hname])
                continue
            log.info('Хост %s найден по имени и привязан к устройству NetBox #%s', hname, dev['id'])
            lookup[hname][1] = h
            adopted.add(h['hostid'])

        if self.cfg['missing_action'] == 'disable':
            gone = [i for i in managed if i not in seen] if full else list(missing_ids)
            for dev_id in gone:
                host = managed.get(dev_id)
                if host is not None and host['hostid'] not in adopted:
                    disable_plan.append((host, 'устройство #%s отсутствует в NetBox' % dev_id, 'deleted'))
        disable_plan = [d for d in disable_plan if str(d[0].get('status')) == '0']

        for dev, host in sync_items:
            self._guard(summary, dev.get('name') or '#%s' % dev['id'], self.sync_device, dev, host, summary)

        if disable_plan:
            problem = self._safety_problem(len(disable_plan), len(managed)) if full else None
            if problem and not force:
                summary.fatal = ('защита от массовой деактивации: %s. Хосты НЕ деактивированы: %s. '
                                 'Проверьте фильтры NetBox или запустите с --force'
                                 % (problem, ', '.join(h['host'] for h, _, _ in disable_plan)))
                log.error(summary.fatal)
            else:
                if problem:
                    log.warning('Порог деактивации превышен (%s), но указан --force', problem)
                for host, reason, status in disable_plan:
                    self._guard(summary, host['host'], self.disable_host, host, reason, status, summary)

        for dev, host in mm_items:
            self._guard(summary, dev.get('name') or '#%s' % dev['id'],
                        self.handle_maintenance, dev, host, summary)
        return summary

    def _safety_problem(self, count, managed_total):
        scfg = self.cfg['safety']
        max_count = scfg.get('max_disable_count')
        max_ratio = scfg.get('max_disable_ratio')
        if max_count is not None and count > int(max_count):
            return 'к деактивации %d хостов при лимите %s' % (count, max_count)
        if max_ratio is not None and managed_total and count / float(managed_total) > float(max_ratio):
            return 'к деактивации %d из %d хостов (> %d%%)' % (
                count, managed_total, float(max_ratio) * 100)
        return None

    def run_full(self, force=False):
        devices = self.nb.fetch_devices()  # NetBoxError -> прерываемся ДО любых изменений
        return self.run(devices, full=True, force=force)

    def run_names(self, names):
        """Синхронизация только указанных устройств (по имени в NetBox)."""
        devices = self.nb.fetch_devices({'name': list(names)})
        found = {d.get('name') for d in devices}
        for name in names:
            if name not in found:
                log.warning('Устройство %s не найдено в NetBox (или не проходит фильтры)', name)
        return self.run(devices, full=False, mode='sync-device')

    def run_ids(self, ids):
        """Синхронизация по ID (вебхуки). Удалённые устройства -> missing_action."""
        devices, missing = [], []
        for dev_id in ids:
            dev = self.nb.get_device(dev_id)
            if dev is None:
                missing.append(dev_id)
            else:
                devices.append(dev)
        return self.run(devices, missing_ids=missing, full=False, mode='webhook')


def _tag_value(host, name):
    for tag in host.get('tags') or []:
        if tag['tag'] == name:
            return tag.get('value')
    return None


def _tag_set(tags):
    return sorted((t['tag'], t.get('value', '')) for t in tags)


def _clean_tags(tags):
    return [{'tag': t['tag'], 'value': t.get('value', '')} for t in tags]


def _ask_yes_no(question):
    try:
        sys.stderr.write(question)
        sys.stderr.flush()
        return sys.stdin.readline().strip().lower() in ('y', 'yes', 'д', 'да')
    except (EOFError, KeyboardInterrupt):
        return False


# ---------------------------------------------------------------------------
# Режим serve: вебхуки NetBox
# ---------------------------------------------------------------------------

def verify_signature(secret, body, signature):
    """Проверка подписи вебхука NetBox: X-Hook-Signature = HMAC-SHA512(secret, body) в hex."""
    if not secret:
        return True
    if not signature:
        return False
    expected = hmac.new(secret.encode('utf-8'), body, hashlib.sha512).hexdigest()
    return hmac.compare_digest(expected, signature.strip().lower())


def parse_webhook(payload):
    """Из тела вебхука NetBox достаёт (событие, ID устройства) или None, если это не устройство."""
    if not isinstance(payload, dict) or payload.get('model') != 'device':
        return None
    data = payload.get('data') or {}
    dev_id = data.get('id')
    if not isinstance(dev_id, int):
        return None
    return payload.get('event') or 'updated', dev_id


class WebhookService(object):
    """Очередь событий + рабочий поток: синхронизация последовательная, без гонок."""

    MAX_BODY = 1024 * 1024

    def __init__(self, cfg, syncer_factory):
        self.cfg = cfg
        self.wcfg = cfg['webhook']
        self.syncer_factory = syncer_factory
        self.queue = queue.Queue()
        self.stop = threading.Event()
        self.last_summary = None

    def _write_summary(self, summary):
        self.last_summary = summary.as_dict()
        write_summary(self.cfg, summary)

    def worker(self):
        interval = float(self.wcfg.get('full_sync_interval') or 0)
        last_full = 0.0
        while not self.stop.is_set():
            timeout = max(0.1, interval - (time.time() - last_full)) if interval else 5
            try:
                first = self.queue.get(timeout=timeout)
            except queue.Empty:
                first = None
            try:
                if first is None:
                    if interval and time.time() - last_full >= interval:
                        log.info('Периодическая полная синхронизация')
                        summary = self.syncer_factory().run_full()
                        last_full = time.time()
                        summary.log()
                        self._write_summary(summary)
                    continue
                # Собираем пачку: при массовом изменении в NetBox прилетают десятки событий
                time.sleep(float(self.wcfg.get('debounce') or 0))
                ids = {first}
                while True:
                    try:
                        ids.add(self.queue.get_nowait())
                    except queue.Empty:
                        break
                log.info('Вебхук: синхронизация устройств %s', sorted(ids))
                summary = self.syncer_factory().run_ids(sorted(ids))
                summary.log()
                self._write_summary(summary)
            except SyncError as exc:
                log.error('Синхронизация не выполнена: %s', exc)
            except Exception:
                log.exception('Непредвиденная ошибка рабочего потока')
                time.sleep(5)

    def make_handler(self):
        service = self
        path = self.wcfg['path']
        secret = self.wcfg.get('secret')

        class Handler(BaseHTTPRequestHandler):
            server_version = 'netbox-zabbix-sync/' + VERSION

            def log_message(self, fmt, *args):
                log.debug('HTTP %s %s', self.address_string(), fmt % args)

            def _reply(self, code, body):
                data = json.dumps(body, ensure_ascii=False).encode('utf-8')
                self.send_response(code)
                self.send_header('Content-Type', 'application/json; charset=utf-8')
                self.send_header('Content-Length', str(len(data)))
                self.end_headers()
                self.wfile.write(data)

            def do_GET(self):
                if self.path == '/health':
                    self._reply(200, {'status': 'ok', 'queue': service.queue.qsize(),
                                      'last_summary': service.last_summary})
                else:
                    self._reply(404, {'error': 'not found'})

            def do_POST(self):
                if self.path.split('?')[0] != path:
                    return self._reply(404, {'error': 'not found'})
                length = int(self.headers.get('Content-Length') or 0)
                if length <= 0 or length > service.MAX_BODY:
                    return self._reply(413, {'error': 'bad body size'})
                body = self.rfile.read(length)
                if not verify_signature(secret, body, self.headers.get('X-Hook-Signature')):
                    log.warning('Вебхук от %s отклонён: неверная подпись', self.client_address[0])
                    return self._reply(403, {'error': 'bad signature'})
                try:
                    event = parse_webhook(json.loads(body.decode('utf-8')))
                except ValueError:
                    return self._reply(400, {'error': 'bad json'})
                if event is None:
                    return self._reply(202, {'status': 'ignored'})
                log.info('Вебхук NetBox: %s устройства #%s', event[0], event[1])
                service.queue.put(event[1])
                return self._reply(202, {'status': 'queued'})

        return Handler

    def serve(self):
        if not self.wcfg.get('secret'):
            log.warning('webhook.secret не задан: подпись вебхуков не проверяется')
        httpd = ThreadingHTTPServer((self.wcfg['listen'], int(self.wcfg['port'])), self.make_handler())
        worker = threading.Thread(target=self.worker, name='sync-worker', daemon=True)
        worker.start()
        log.info('Приём вебхуков NetBox на http://%s:%s%s', self.wcfg['listen'],
                 self.wcfg['port'], self.wcfg['path'])
        try:
            httpd.serve_forever()
        except KeyboardInterrupt:
            pass
        finally:
            self.stop.set()
            httpd.server_close()


# ---------------------------------------------------------------------------
# Точка входа
# ---------------------------------------------------------------------------

def write_summary(cfg, summary):
    path = cfg.get('summary_file')
    if not path:
        return
    tmp = path + '.tmp'
    try:
        with open(tmp, 'w', encoding='utf-8') as fh:
            json.dump(summary.as_dict(), fh, ensure_ascii=False, indent=2)
        os.replace(tmp, path)
    except OSError as exc:
        log.error('Не удалось записать %s: %s', path, exc)


class RunLock(object):
    """Не даёт запустить две синхронизации одновременно (cron + ручной запуск)."""

    def __init__(self, path):
        self.path = path
        self.fh = None

    def __enter__(self):
        if fcntl is None or not self.path:
            return self
        self.fh = open(self.path, 'a')
        try:
            fcntl.flock(self.fh, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError:
            self.fh.close()
            raise SyncError('синхронизация уже выполняется (блокировка %s)' % self.path)
        return self

    def __exit__(self, *exc):
        if self.fh:
            fcntl.flock(self.fh, fcntl.LOCK_UN)
            self.fh.close()


def _add_common(parser, suppress=False):
    """Общие опции. У подкоманд default=SUPPRESS, иначе они затрут значения,
    указанные до подкоманды (`-c conf.yaml sync`)."""
    def d(value):
        return argparse.SUPPRESS if suppress else value
    parser.add_argument('-c', '--config', default=d(os.environ.get('NBZS_CONFIG')),
                        help='YAML/JSON конфиг (или переменная NBZS_CONFIG)')
    parser.add_argument('-v', '--verbose', action='store_true', default=d(False),
                        help='подробный лог (DEBUG)')
    parser.add_argument('-n', '--dry-run', action='store_true', default=d(False),
                        help='только показать, что будет сделано, без изменений в Zabbix')
    parser.add_argument('--json', action='store_true', default=d(False),
                        help='вывести итог в stdout в JSON')


def build_parser():
    common = argparse.ArgumentParser(add_help=False)
    _add_common(common, suppress=True)

    parser = argparse.ArgumentParser(description='Синхронизация устройств NetBox в хосты Zabbix')
    _add_common(parser)
    parser.add_argument('--version', action='version', version=VERSION)
    sub = parser.add_subparsers(dest='command')

    p_sync = sub.add_parser('sync', parents=[common], help='синхронизация (по умолчанию)')
    p_sync.add_argument('--device', action='append', default=[],
                        help='синхронизировать только это устройство (можно несколько раз)')
    p_sync.add_argument('--force', action='store_true',
                        help='деактивировать хосты даже при превышении порогов safety')
    group = p_sync.add_mutually_exclusive_group()
    group.add_argument('--interactive', dest='interactive', action='store_true', default=None,
                       help='спрашивать подтверждение MM в терминале')
    group.add_argument('--non-interactive', dest='interactive', action='store_false',
                       help='не спрашивать (по умолчанию, если stdin не терминал)')

    p_mm = sub.add_parser('approve-mm', parents=[common],
                          help='создать обслуживание для предложенных хостов')
    p_mm.add_argument('hosts', nargs='+', help="имена хостов или 'all' — все с тегом netbox_mm=proposed")
    p_mm.add_argument('--hours', type=float, help='длительность обслуживания, ч')

    sub.add_parser('serve', parents=[common], help='приём вебхуков NetBox + периодическая синхронизация')
    sub.add_parser('check', parents=[common], help='проверить конфиг и доступ к API')
    return parser


def run_check(cfg, netbox, zapi):
    """Проверка без изменений: доступ к API и наличие шаблонов/прокси из конфига."""
    ok = True
    status = netbox.status()
    log.info('NetBox: доступен, версия %s', (status or {}).get('netbox-version', '?'))
    syncer = Syncer(cfg, netbox, zapi, dry_run=True)
    syncer.load_caches()
    tcfg = cfg['templates']
    names = set(tcfg.get('default') or []) | set(tcfg.get('always') or [])
    for rule in tcfg.get('rules') or []:
        names.update(rule['templates'])
    for name in sorted(names):
        if name not in syncer.templates:
            log.error('Шаблон %r из конфига не найден в Zabbix', name)
            ok = False
    pcfg = cfg['zabbix']['proxy']
    for proxy in set(filter(None, [pcfg.get('default')] + list((pcfg.get('by_site') or {}).values()))):
        if proxy not in syncer.proxies:
            log.error('Прокси %r из конфига не найден в Zabbix', proxy)
            ok = False
    log.info('Zabbix: групп %d, шаблонов %d, управляемых хостов %d',
             len(syncer.groups), len(set(syncer.templates.values())), len(syncer.get_managed_hosts()))
    log.info('Проверка %s', 'пройдена' if ok else 'НЕ пройдена')
    return 0 if ok else 1


def main(argv=None):
    parser = build_parser()
    args = parser.parse_args(argv)
    command = args.command or 'sync'
    logging.basicConfig(level=logging.INFO, format='%(asctime)s %(levelname)-7s %(message)s')
    try:
        cfg = load_config(args.config)
    except ConfigError as exc:
        log.critical('Ошибка конфигурации: %s', exc)
        return 2
    setup_logging(cfg['logging'], args.verbose)
    log.info('netbox_zabbix_sync %s: %s%s', VERSION, command, ' (dry-run)' if args.dry_run else '')

    zapi = None
    summary = None
    try:
        netbox = NetBoxSource(cfg['netbox'])
        zapi = ZabbixAPI.from_config(cfg['zabbix'])
        zapi.login()

        if command == 'check':
            return run_check(cfg, netbox, zapi)

        if command == 'serve':
            service = WebhookService(cfg, lambda: Syncer(cfg, netbox, zapi, dry_run=args.dry_run))
            service.serve()
            return 0

        with RunLock(cfg.get('lock_file')):
            if command == 'approve-mm':
                syncer = Syncer(cfg, netbox, zapi, dry_run=args.dry_run)
                summary = syncer.approve_maintenance(args.hosts, args.hours)
            else:
                # без подкоманды опций sync в args нет
                interactive = getattr(args, 'interactive', None)
                if interactive is None:
                    interactive = sys.stdin.isatty()
                devices = getattr(args, 'device', None)
                syncer = Syncer(cfg, netbox, zapi, dry_run=args.dry_run, interactive=interactive)
                summary = (syncer.run_names(devices) if devices
                           else syncer.run_full(getattr(args, 'force', False)))
    except NetBoxError as exc:
        summary = summary or Summary(command, args.dry_run)
        summary.fatal = 'NetBox: %s. Изменения в Zabbix не вносились' % exc
    except ZabbixConnectionError as exc:
        summary = summary or Summary(command, args.dry_run)
        summary.fatal = 'Zabbix недоступен: %s' % exc
    except (ZabbixError, SyncError) as exc:
        summary = summary or Summary(command, args.dry_run)
        summary.fatal = str(exc)
    finally:
        if zapi is not None:
            zapi.logout()

    summary.log()
    write_summary(cfg, summary)
    if args.json:
        print(json.dumps(summary.as_dict(), ensure_ascii=False, indent=2))
    return summary.exit_code()


if __name__ == '__main__':
    sys.exit(main())
