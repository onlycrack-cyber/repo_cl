#!/usr/bin/env python3
"""Generates esxi_ntp_check/grafana/esxi-health.json: dashboard of "Template ESXi Health by vCenter".

    python3 esxi_ntp_check/tools/gen_esxi_dashboard.py esxi_ntp_check/grafana/esxi-health.json

Same layout and helpers as the NSX and HPE 3PAR health maps: a traffic light per check (a green "OK" or
the number of hosts not in the normal state), the Zabbix problems, a tile per host for every check,
uplinks and vSwitches, storage and time, and the history of the states. Every number comes from an
item of the template; red means "needs action", orange - redundancy lost or alerts switched off.

The hosts are monitored by the template only through the "master" Zabbix host of a vCenter, so the
dashboard has one host variable: the master host. Performance (CPU, memory, datastores, VMs) and the
HPE hardware are covered by the standard VMware template and SNMP and are deliberately not repeated.
"""
import json
import os
import sys

here = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(here, '..', '..', 'grafana', 'tools'))
from gen_health_dashboard import (  # noqa: E402
    DS, GREEN, ORANGE, RED, Dashboard, bars, problems_tile, query, rename, stat, thresholds, tiles, timeline,
    timeseries)

# Same keys as the value maps of the template. Texts are for people; the colours say "needs action".
NTP = {0: ('НЕ РАБОТАЕТ', RED), 1: ('OK', GREEN)}
DNS = {0: ('НЕ НАСТРОЕН', ORANGE), 1: ('OK', GREEN)}
POWER = {0: ('НЕ High perf.', ORANGE), 1: ('OK', GREEN)}
VMK = {0: ('НЕТ VMK', ORANGE), 1: ('OK', GREEN)}
STORAGE = {0: ('АДАПТЕР OFFLINE', RED), 1: ('OK', GREEN)}
HA = {0: ('ПРОБЛЕМА', ORANGE), 1: ('OK', GREEN)}
ALERTING = {0: ('ПОДАВЛЕНЫ', ORANGE), 1: ('включены', GREEN)}
LINK = {0: ('DOWN', RED), 1: ('UP', GREEN)}

ITEMS = {
    'ntp': ('/^NTP configured on .*$/', '^NTP configured on (.*)$'),
    'dns': ('/^DNS configured on .*$/', '^DNS configured on (.*)$'),
    'power': ('/^Power policy on .*$/', '^Power policy on (.*)$'),
    'vmk': ('/^VMkernel adapters on .*$/', '^VMkernel adapters on (.*)$'),
    'storage': ('/^Storage adapters on .*$/', '^Storage adapters on (.*)$'),
    'ha': ('/^vSphere HA agent on .*$/', '^vSphere HA agent on (.*)$'),
    'dead': ('/^Dead storage paths on .*$/', '^Dead storage paths on (.*)$'),
    'ds': ('/^Inaccessible datastores on .*$/', '^Inaccessible datastores on (.*)$'),
    'offset': ('/^NTP time offset on .*$/', '^NTP time offset on (.*)$'),
    # host names have no spaces; the uplink / vSwitch variants of the flag carry a suffix in brackets
    'alert': ('/^Alerting enabled for [^ ]+$/', '^Alerting enabled for ([^ ]+)$'),
    'link': ('/^Link state of .* on .*$/', '^Link state of (.*) on (.*)$'),
    'speed': ('/^Link speed of .* on .*$/', '^Link speed of (.*) on (.*)$'),
    'live': ('/^Live uplinks of .* on .*$/', '^Live uplinks of (.*) on (.*)$'),
}
HOSTS = 'ESXi hosts found in vCenter'
WARN = 'Collector warnings'
NIC_LABEL = '$2 · $1'            # "esx01 · vmnic0"

# The template defaults of {$ESXI.NTP.OFFSET.WARN} / {$ESXI.NTP.OFFSET.HIGH}, seconds.
OFFSET_WARN, OFFSET_HIGH = 1, 5


def item(key):
    return ITEMS[key][0]


def name(key):
    return ITEMS[key][1]


def text_query(ref, item_name):
    """Text-mode query of the Zabbix plugin (queryType 2) for a text item."""
    q = query(ref, item_name)
    q.update({'queryType': '2', 'textFilter': '', 'useCaptureGroups': False})
    return q


def keep_outside(limit):
    """Keep the hosts whose last value is below -limit or above +limit."""
    return {'id': 'filterByValue', 'options': {'type': 'include', 'match': 'any', 'filters': [
        {'fieldName': 'Last *', 'config': {'id': 'lower', 'options': {'value': -limit}}},
        {'fieldName': 'Last *', 'config': {'id': 'greater', 'options': {'value': limit}}}]}}


def light(title, key, bad, desc, unit_word='хост.', colour=RED, keep=None):
    """Traffic light: number of hosts whose last value matches `bad` (or the `keep` filter); green "OK" if none."""
    return problems_tile(title, item(key), bad, desc, unit_word, colour, keep)


def per_host_numbers(title, key, desc, unit_word):
    """Tile per host holding the count: green 0 / OK, red above 0."""
    return stat(title, [query('A', item(key))], desc,
                maps=[{'type': 'value', 'options': {'0': {'text': 'OK', 'color': GREEN, 'index': 0}}}],
                unit='suffix: ' + unit_word, thresh=thresholds((GREEN, None), (RED, 1)),
                transformations=[rename(name(key))], value_size=18, title_size=14, text_mode='value_and_name',
                decimals=0)


def problems_panel():
    return {'type': 'alexanderzobnin-zabbix-triggers-panel', 'title': 'Активные проблемы',
            'description': 'Проблемы Zabbix по мастер-хосту vCenter от Warning и выше, самые важные сверху. '
                           'Имя ESXi-хоста — в тегах (esxi) и в названии проблемы.',
            'targets': [{'refId': 'A', 'datasource': DS, 'queryType': '5', 'group': {'filter': '$group'},
                         'host': {'filter': '$host'}, 'application': {'filter': ''}, 'proxy': {'filter': ''},
                         'trigger': {'filter': ''}, 'tags': {'filter': ''}, 'showProblems': 'problems',
                         'options': {'hostsInMaintenance': False, 'hostProxy': False, 'limit': 100,
                                     'sortProblems': 'priority', 'acknowledged': 2, 'minSeverity': 2}}],
            'options': {'layout': 'table', 'showTags': True, 'hostField': False, 'hostTechNameField': False,
                        'statusField': True, 'severityField': True, 'ackField': True, 'ageField': True,
                        'descriptionField': True, 'descriptionAtNewLine': False, 'sortProblems': 'priority',
                        'fontSize': '100%', 'pageSize': 10, 'problemTimeline': True, 'highlightNewEvents': True,
                        'highlightNewerThan': '1h', 'customLastChangeFormat': False}}


def build():
    d = Dashboard()
    d.row('Состояние ESXi: зелёный — норма, красный — нужны действия, оранжевый — потеряна избыточность', 0)

    # --- traffic lights, row 1 (y=1): the collector and the time
    d.add(stat('Хостов в vCenter', [query('A', HOSTS)],
               'Сколько ESXi-хостов видит сборщик. «Нет данных» (красный): Zabbix не получает ответ — vCenter '
               'недоступен, неверная учётка или сломан скрипт; остальные плитки при этом устаревают.',
               thresh=thresholds((RED, None), (GREEN, 1)), decimals=0, value_size=40, title_size=18,
               no_value='Нет данных'), 0, 1, 3, 5)
    d.add(stat('Сборщик: предупреждения', [text_query('A', WARN)],
               'Что скрипт не смог прочитать у vCenter (неверное имя свойства, нет прав, медленный режим). '
               'OK — предупреждений нет. Иначе показан текст предупреждения: часть проверок не обновляется.',
               maps=[{'type': 'value', 'options': {'OK': {'text': 'OK', 'color': GREEN, 'index': 0}}}],
               thresh=thresholds((ORANGE, None)), value_size=14, title_size=18, no_value='нет данных'),
          3, 1, 3, 5)
    d.add(light('Алерты выключены', 'alert', [('equal', 0)],
                'Хосты в Maintenance Mode или не в состоянии connected: их алерты подавлены (и закрываются '
                'сами). Оранжевый — просто напоминание, что хост выведен из контроля.', colour=ORANGE),
          6, 1, 3, 5)
    d.add(light('NTP не работает', 'ntp', [('notEqual', 1)],
                'Число хостов, где NTP не настроен или служба ntpd остановлена.'), 9, 1, 3, 5)
    d.add(light('Смещение времени', 'offset', [],
                'Число хостов, у которых время отличается от vCenter больше чем на %d с (порог '
                '{$ESXI.NTP.OFFSET.WARN} шаблона по умолчанию).' % OFFSET_WARN, keep=keep_outside(OFFSET_WARN)),
          12, 1, 3, 5)
    d.add(light('DNS', 'dns', [('notEqual', 1)],
                'Число хостов, где нет двух DNS-серверов (или нет заданных {$ESXI.DNS.PREFERRED} / '
                '{$ESXI.DNS.ALTERNATE}).'), 15, 1, 3, 5)
    d.add(light('Power policy', 'power', [('notEqual', 1)],
                'Число хостов, у которых активная политика энергосбережения не равна {$ESXI.POWER.POLICY} '
                '(по умолчанию High performance).'), 18, 1, 3, 5)
    d.add(light('VMkernel', 'vmk', [('notEqual', 1)],
                'Число хостов без настроенных VMkernel-адаптеров с IP.'), 21, 1, 3, 5)

    # --- traffic lights, row 2 (y=6): storage, HA and network
    d.add(light('Storage-адаптеры', 'storage', [('notEqual', 1)],
                'Число хостов, у которых хотя бы один известный адаптер не online. Один отказавший порт из двух: '
                'хост работает на оставшемся, избыточность потеряна.'), 0, 6, 4, 5)
    d.add(light('Dead-пути к хранилищу', 'dead', [('greater', 0)],
                'Число хостов, у которых есть пути к LUN в состоянии dead (мультипасинг). Local-диски '
                'с одним путём не затрагиваются.'), 4, 6, 4, 5)
    d.add(light('Датасторы недоступны', 'ds', [('greater', 0)],
                'Число хостов, которым недоступен датастор (mountInfo.accessible = false).'), 8, 6, 4, 5)
    d.add(light('HA-агент', 'ha', [('notEqual', 1)],
                'Число хостов, у которых HA-агент в проблемном состоянии (isolated, partitioned, ошибки '
                'инициализации, hostDown). Хост вне HA-кластера не считается.'), 12, 6, 4, 5)
    d.add(light('Аплинки: линк down', 'link', [('notEqual', 1)],
                'Число физических портов (аплинков vSwitch/DVS) с опущенным линком. Свободные порты, не входящие '
                'в свитч, не считаются.', 'портов'), 16, 6, 4, 5)
    d.add(light('vSwitch без аплинков', 'live', [('equal', 0)],
                'Число свитчей, у которых не осталось ни одного живого аплинка: сетевая изоляция.', 'свитч.'),
          20, 6, 4, 5)
    d.add(problems_panel(), 0, 11, 24, 8)

    # --- map: one tile per host
    d.row('Карта: каждая плитка — отдельный ESXi-хост', 19)
    d.add(tiles('NTP', item('ntp'), NTP, name('ntp'), 'NTP настроен и служба ntpd запущена.', value_size=14),
          0, 20, 8, 5)
    d.add(tiles('DNS', item('dns'), DNS, name('dns'), 'Есть два DNS-сервера (или заданные макросами).',
                value_size=14), 8, 20, 8, 5)
    d.add(tiles('Power policy', item('power'), POWER, name('power'),
                'Активная политика энергосбережения совпадает с ожидаемой.', value_size=14), 16, 20, 8, 5)
    d.add(tiles('VMkernel', item('vmk'), VMK, name('vmk'), 'Есть VMkernel-адаптер с IP.', value_size=14),
          0, 25, 8, 5)
    d.add(tiles('Storage-адаптеры', item('storage'), STORAGE, name('storage'),
                'Все известные адаптеры online. Адаптеры в состоянии unknown не считаются проблемой.',
                value_size=14), 8, 25, 8, 5)
    d.add(tiles('HA-агент', item('ha'), HA, name('ha'),
                'Состояние HA-агента хоста; текст состояния — в элементе «vSphere HA agent state».',
                value_size=14), 16, 25, 8, 5)
    d.add(tiles('Алерты по хостам', item('alert'), ALERTING, name('alert'),
                'ПОДАВЛЕНЫ — хост в Maintenance Mode или не connected; через 10 минут после возврата алерты '
                'включаются снова ({$ESXI.ALERT.HOLDDOWN}).', value_size=14), 0, 30, 8, 5)
    d.add(per_host_numbers('Dead-пути', 'dead', 'Число путей в состоянии dead на каждом хосте.', 'пут.'),
          8, 30, 8, 5)
    d.add(per_host_numbers('Недоступные датасторы', 'ds', 'Число датасторов, недоступных хосту.', 'ДС'),
          16, 30, 8, 5)

    # --- network
    d.row('Сеть: аплинки и vSwitch', 35)
    d.add(tiles('Линк физических портов', item('link'), LINK, name('link'),
                'Каждый порт, который служит аплинком vSwitch/DVS: хост · vmnic. DOWN — потеряна избыточность '
                'или связность хоста.', rename_to=NIC_LABEL, value_size=14), 0, 36, 8, 7)
    d.add(stat('Живые аплинки vSwitch', [query('A', item('live'))],
               'Сколько аплинков vSwitch/DVS сейчас с поднятым линком (хост · свитч). 0 — свитч изолирован; '
               'число меньше назначенного — смотрите плитки портов слева.',
               thresh=thresholds((RED, None), (GREEN, 1)), transformations=[rename(name('live'), '$2 · $1')],
               value_size=18, title_size=14, text_mode='value_and_name', decimals=0, unit='suffix: аплинк.'),
          8, 36, 8, 7)
    speed = bars('Скорость линка', item('speed'), name('speed'),
                 'Скорость каждого аплинка, Мбит/с (0 — линк опущен). Порог для предупреждения о снижении '
                 'скорости ({$ESXI.NIC.SPEED.MIN}) в шаблоне по умолчанию выключен.', 'suffix: Мбит/с',
                 thresholds((RED, None), (GREEN, 1)))
    speed['fieldConfig']['defaults']['decimals'] = 0
    speed['transformations'] = [rename(name('speed'), NIC_LABEL)]
    d.add(speed, 16, 36, 8, 7)

    # --- storage and time (history)
    d.row('Хранилище и время', 43)
    d.add(timeseries('Dead-пути к хранилищу', item('dead'),
                     'Число путей к LUN в состоянии dead по хостам. Любое значение выше нуля — потеряна '
                     'избыточность или LUN.', 'none', name('dead'),
                     thresh_lines=thresholds((GREEN, None), (RED, 1))), 0, 44, 8, 7)
    d.add(timeseries('Недоступные датасторы', item('ds'),
                     'Число датасторов, недоступных хосту, по хостам.', 'none', name('ds'),
                     thresh_lines=thresholds((GREEN, None), (RED, 1))), 8, 44, 8, 7)
    offset = timeseries('Смещение времени относительно vCenter', item('offset'),
                        'Время ESXi минус время vCenter. Линии — пороги шаблона: ±1 с предупреждение, ±5 с '
                        'высокая важность. Положительное значение — ESXi идёт вперёд.', 's', name('offset'),
                        thresh_lines=thresholds((RED, None), (ORANGE, -OFFSET_HIGH), (GREEN, -OFFSET_WARN),
                                                (ORANGE, OFFSET_WARN), (RED, OFFSET_HIGH)))
    offset['fieldConfig']['defaults'].pop('min')          # the offset can be negative
    d.add(offset, 16, 44, 8, 7)

    # --- history of the states
    d.row('История: когда это случилось', 51)
    d.add(timeline('NTP', item('ntp'), NTP, name('ntp'), 'История NTP по хостам.'), 0, 52, 8, 7)
    d.add(timeline('HA-агент', item('ha'), HA, name('ha'), 'История состояния HA-агента по хостам.'), 8, 52, 8, 7)
    d.add(timeline('Алерты по хостам', item('alert'), ALERTING, name('alert'),
                   'Когда хост был в Maintenance Mode или не connected (алерты подавлены).'), 16, 52, 8, 7)
    d.add(timeline('Storage-адаптеры', item('storage'), STORAGE, name('storage'),
                   'История состояния адаптеров хранилища.'), 0, 59, 8, 7)
    d.add(timeline('Линк физических портов', item('link'), LINK, name('link'),
                   'История линков аплинков: хост · vmnic.', rename_to=NIC_LABEL), 8, 59, 8, 7)
    d.add(timeline('Power policy', item('power'), POWER, name('power'), 'История политики энергосбережения.'),
          16, 59, 8, 7)
    return d.panels


def dashboard():
    return {
        'uid': 'esxi-health-vcenter', 'title': 'VMware ESXi — здоровье хостов (vCenter)',
        'description': 'Конфигурация и состояние ESXi-хостов из vCenter: NTP и смещение времени, DNS, power policy, '
                       'VMkernel, адаптеры хранилища и dead-пути, датасторы, HA-агент, аплинки и vSwitch. '
                       'Дашборд для шаблона Zabbix «Template ESXi Health by vCenter». Производительность и железо — '
                       'в стандартном шаблоне VMware и в SNMP.',
        'tags': ['zabbix', 'vmware', 'esxi', 'health'], 'editable': True, 'graphTooltip': 1,
        'refresh': '1m', 'schemaVersion': 39, 'version': 1, 'time': {'from': 'now-6h', 'to': 'now'},
        'timepicker': {}, 'timezone': 'browser', 'fiscalYearStartMonth': 0, 'liveNow': False, 'weekStart': '',
        'links': [],
        'annotations': {'list': [{'builtIn': 1, 'datasource': {'type': 'grafana', 'uid': '-- Grafana --'},
                                  'enable': True, 'hide': True, 'iconColor': 'rgba(0, 211, 255, 1)',
                                  'name': 'Annotations & Alerts', 'type': 'dashboard'}]},
        'templating': {'list': [
            {'name': 'datasource', 'label': 'Zabbix', 'type': 'datasource', 'query': DS['type'], 'refresh': 1,
             'regex': '', 'hide': 0, 'current': {}, 'options': [], 'includeAll': False, 'multi': False},
            {'name': 'group', 'label': 'Группа', 'type': 'query', 'datasource': DS, 'refresh': 2, 'sort': 1,
             'query': {'group': '/.*/', 'host': '', 'application': '', 'itemTag': '', 'item': '',
                       'queryType': 'group'}, 'definition': '', 'regex': '', 'hide': 0, 'current': {},
             'options': [], 'includeAll': False, 'multi': False},
            {'name': 'host', 'label': 'vCenter (мастер-хост)', 'type': 'query', 'datasource': DS, 'refresh': 2,
             'sort': 1, 'query': {'group': '$group', 'host': '/.*/', 'application': '', 'itemTag': '', 'item': '',
                                  'queryType': 'host'}, 'definition': '', 'regex': '', 'hide': 0, 'current': {},
             'options': [], 'includeAll': False, 'multi': False}]},
        'panels': build()}


if __name__ == '__main__':
    with open(sys.argv[1], 'w') as fh:
        json.dump(dashboard(), fh, ensure_ascii=False, indent=2)
        fh.write('\n')
    print('panels:', len([p for p in build() if p['type'] != 'row']))
