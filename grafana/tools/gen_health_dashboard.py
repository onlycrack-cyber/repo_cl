#!/usr/bin/env python3
"""Generates grafana/hpe-3par-health.json: the "health map" dashboard of the HPE SSMC template.

    python3 grafana/tools/gen_health_dashboard.py grafana/hpe-3par-health.json

Top to bottom: one verdict for the whole array, a traffic light per area (nodes, power, network,
disks, ports, volumes, capacity, certificates, alerts, collector), the Zabbix problems, then a
tile per physical object (node, battery, network address, port, disk) coloured by its state, and
finally the history of those states. All numbers come from the items of the template; the
colours are Zabbix value-map colours, so red always means "needs action".
"""
import json
import sys

DS = {'type': 'alexanderzobnin-zabbix-datasource', 'uid': '${datasource}'}
GREEN, ORANGE, RED, DARK_RED, GREY = 'green', 'orange', 'red', 'dark-red', 'text'

# Same mappings as the value maps of the template.
SYSTEM = {1: ('Норма', GREEN), 2: ('Деградация', ORANGE), 3: ('ОТКАЗ', DARK_RED), 4: ('ОТКАЗ', DARK_RED),
          5: ('Неизвестно', GREY)}
NODE = {1: ('OK', GREEN), 2: ('Деградация', ORANGE), 4: ('ОТКАЗ', DARK_RED), 5: ('Неизвестно', GREY)}
DISK = {1: ('OK', GREEN), 2: ('Деградация', ORANGE), 3: ('Новый', 'blue'), 4: ('ОТКАЗ', DARK_RED),
        5: ('Неизвестно', GREY)}
UPDOWN = {0: ('НЕТ СВЯЗИ', DARK_RED), 1: ('OK', GREEN)}
PORT = {0: ('UNKNOWN', GREY), 1: ('CONFIG_WAIT', ORANGE), 2: ('ALPA_WAIT', ORANGE), 3: ('LOGIN_WAIT', ORANGE),
        4: ('READY', GREEN), 5: ('LOSS_SYNC', RED), 6: ('ERROR', DARK_RED), 7: ('XXX', ORANGE),
        8: ('NONPARTICIPATING', ORANGE), 9: ('INITIALIZING', ORANGE), 10: ('PENDING_RESET', ORANGE),
        11: ('OFFLINE', RED)}
COLLECT = {0: ('OK', GREEN), 1: ('Частично', ORANGE), 2: ('СБОЙ', DARK_RED), 3: ('Ошибка настройки', DARK_RED),
           4: ('Неверный ответ', DARK_RED)}
EXPIRED = {0: ('Годна', GREEN), 1: ('ИСТЁК', RED)}


def mappings(table):
    return [{'type': 'value', 'options': {str(k): {'text': t, 'color': c, 'index': i}
                                           for i, (k, (t, c)) in enumerate(table.items())}}]


def thresholds(*steps):
    return {'mode': 'absolute', 'steps': [{'color': c, 'value': v} for c, v in steps]}


def query(ref, item, functions=()):
    return {'refId': ref, 'datasource': DS, 'queryType': '0', 'group': {'filter': '$group'},
            'host': {'filter': '$host'}, 'application': {'filter': ''}, 'itemTag': {'filter': ''},
            'item': {'filter': item}, 'functions': list(functions),
            'options': {'showDisabledItems': False, 'skipEmptyValues': False, 'disableDataAlignment': False,
                        'useZabbixValueMapping': False}}


def rename(regex, pattern='$1'):
    return {'id': 'renameByRegex', 'options': {'regex': regex, 'renamePattern': pattern}}


LAST_PER_ROW = {'id': 'reduce', 'options': {'reducers': ['lastNotNull'], 'mode': 'seriesToRows',
                                            'includeTimeField': False}}


def keep_rows(*conditions):
    """Keep the rows (objects) whose last value matches every condition: ('notEqual'|'equal', n)."""
    return {'id': 'filterByValue', 'options': {'type': 'include', 'match': 'all', 'filters': [
        {'fieldName': 'Last *', 'config': {'id': op, 'options': {'value': v}}} for op, v in conditions]}}


class Dashboard:
    def __init__(self):
        self.panels = []
        self.next_id = 1

    def add(self, panel, x, y, w, h):
        panel.update({'id': self.next_id, 'gridPos': {'x': x, 'y': y, 'w': w, 'h': h}})
        if panel['type'] != 'row':
            panel['datasource'] = DS
        self.next_id += 1
        self.panels.append(panel)

    def row(self, title, y):
        self.add({'type': 'row', 'title': title, 'collapsed': False, 'panels': []}, 0, y, 24, 1)


def stat(title, targets, desc='', maps=None, unit='none', thresh=None, calcs=('lastNotNull',), transformations=(),
         fields='', text_mode='auto', color_mode='background', value_size=None, title_size=None, no_value=None,
         overrides=(), min_=None, max_=None, decimals=None, orientation='auto'):
    defaults = {'unit': unit, 'mappings': maps or [], 'color': {'mode': 'thresholds'},
                'thresholds': thresh or thresholds((GREY, None))}
    if min_ is not None:
        defaults['min'] = min_
    if max_ is not None:
        defaults['max'] = max_
    if decimals is not None:
        defaults['decimals'] = decimals
    if no_value is not None:
        defaults['noValue'] = no_value
    text = {}
    if value_size:
        text['valueSize'] = value_size
    if title_size:
        text['titleSize'] = title_size
    return {'type': 'stat', 'title': title, 'description': desc, 'targets': targets,
            'fieldConfig': {'defaults': defaults, 'overrides': list(overrides)},
            'options': {'reduceOptions': {'calcs': list(calcs), 'fields': fields, 'values': False},
                        'orientation': orientation, 'textMode': text_mode, 'colorMode': color_mode,
                        'graphMode': 'none', 'justifyMode': 'center', 'wideLayout': True,
                        'showPercentChange': False, 'text': text},
            'transformations': list(transformations)}


def problems_tile(title, item, bad, desc, unit_word='пробл.'):
    """Number of objects not in the normal state; green "OK" when there are none."""
    return stat(title, [query('A', item)], desc,
                maps=[{'type': 'value', 'options': {'0': {'text': 'OK', 'color': GREEN, 'index': 0}}},
                      {'type': 'special', 'options': {'match': 'null+nan', 'result': {
                          'text': 'OK', 'color': GREEN, 'index': 1}}}],
                unit='suffix: ' + unit_word, thresh=thresholds((GREEN, None), (RED, 1)), calcs=('count',),
                fields='/^Last/', transformations=[LAST_PER_ROW, keep_rows(*bad)], no_value='OK',
                value_size=40, title_size=18)


BATTERY_NAME = '^Battery \\[Node (\\d+) PS (\\d+) Batt (\\d+)\\] status$'


def tiles(title, item, table, rename_regex, desc, rename_to='$1', value_size=None):
    """One coloured tile per object, titled with its name and holding its state."""
    return stat(title, [query('A', item)], desc, maps=mappings(table),
                thresh=thresholds((GREY, None)), transformations=[rename(rename_regex, rename_to)],
                value_size=value_size or 22, title_size=14, text_mode='value_and_name')


def timeline(title, item, table, rename_regex, desc, rename_to='$1'):
    return {'type': 'state-timeline', 'title': title, 'description': desc, 'targets': [query('A', item)],
            'fieldConfig': {'defaults': {'mappings': mappings(table), 'color': {'mode': 'fixed', 'fixedColor': GREY},
                                         'custom': {'fillOpacity': 80, 'lineWidth': 0}}, 'overrides': []},
            'options': {'showValue': 'never', 'mergeValues': True, 'rowHeight': 0.8, 'alignValue': 'left',
                        'legend': {'showLegend': False, 'displayMode': 'list', 'placement': 'bottom'},
                        'tooltip': {'mode': 'single', 'sort': 'none'}},
            'transformations': [rename(rename_regex, rename_to)]}


def timeseries(title, item, desc, unit, rename_regex, rename_to='$1', thresh_lines=None, min_=0, max_=None):
    defaults = {'unit': unit, 'min': min_, 'color': {'mode': 'palette-classic'},
                'custom': {'lineWidth': 2, 'fillOpacity': 10, 'showPoints': 'never', 'spanNulls': True,
                           'thresholdsStyle': {'mode': 'line'} if thresh_lines else {'mode': 'off'}},
                'thresholds': thresh_lines or thresholds((GREEN, None))}
    if max_ is not None:
        defaults['max'] = max_
    return {'type': 'timeseries', 'title': title, 'description': desc, 'targets': [query('A', item)],
            'fieldConfig': {'defaults': defaults, 'overrides': []},
            'options': {'legend': {'showLegend': True, 'displayMode': 'list', 'placement': 'bottom'},
                        'tooltip': {'mode': 'multi', 'sort': 'desc'}},
            'transformations': [rename(rename_regex, rename_to)]}


def build():
    d = Dashboard()
    d.row('Состояние массива: зелёный — норма, красный — нужны действия', 0)

    # --- verdict for the whole array and the traffic lights (y=1..11)
    d.add(stat('СХД', [query('A', 'System overall state')],
               'Общее состояние массива. Деградация и отказ: см. причину ниже и проблемы Zabbix.',
               maps=mappings(SYSTEM), value_size=44, title_size=22), 0, 1, 4, 10)
    tile_defs = [
        problems_tile('Ноды', '/^Node \\[.*\\] status$/', [('notEqual', 1)],
                      'Число нод не в состоянии OK. Отказ ноды = потеря питания, перезагрузка или выход из кластера.'),
        problems_tile('Питание: батареи', '/^Battery \\[.*\\] status$/', [('notEqual', 1)],
                      'Число батарей (BBU) не в состоянии OK: без них при обрыве питания теряется кэш записи.'),
        problems_tile('Сеть: недоступно', '/: reachable$/', [('equal', 0)],
                      'Число адресов (массив, ноды), не отвечающих на ping и SSH одновременно. '
                      'Нода пропала из сети — смотрите «Ноды» и «Питание».'),
        problems_tile('Диски', '/^Disk \\[.*\\] state$/', [('notEqual', 1), ('notEqual', 3)],
                      'Число дисков в состоянии Degraded, Failed или Unknown (новые диски не считаются).'),
        problems_tile('Порты', '/^Port \\[.*\\] link state$/', [('notEqual', 4)],
                      'Число портов, которые не в состоянии READY (потеря синхронизации, offline, ошибка).'),
        problems_tile('Тома: состояние', '/^Volume \\[.*\\] state$/', [('notEqual', 1)],
                      'Число томов не в состоянии Normal. Заполненность томов — на панели «Утилизация томов» ниже.'),
    ]
    for i, t in enumerate(tile_defs):
        d.add(t, 4 + (i % 5) * 4, 1 + (i // 5) * 5, 4, 5)
    d.add(stat('Ёмкость', [query('A', 'Capacity used, in %')],
               'Заполненность массива: оранжевый от 80 %, красный от 90 %.', unit='percent', min_=0, max_=100,
               thresh=thresholds((GREEN, None), (ORANGE, 80), (RED, 90)), decimals=1, value_size=40, title_size=18),
          8, 6, 4, 5)
    d.add(stat('Сертификаты', [query('A', '/^Certificate \\[.*\\] days left$/')],
               'Наименьший срок до истечения сертификатов: оранжевый меньше 30 дней, красный меньше 7.',
               unit='suffix: дн.', thresh=thresholds((RED, None), (ORANGE, 7), (GREEN, 30)), calcs=('min',),
               fields='/^Last/', transformations=[LAST_PER_ROW], decimals=0, value_size=40, title_size=18),
          12, 6, 4, 5)
    d.add(stat('Алерты массива', [query('A', 'Active CRITICAL alerts'), query('B', 'Active MAJOR alerts')],
               'Активные алерты массива: CRITICAL — красный, MAJOR — оранжевый. Тексты в проблемах Zabbix.',
               thresh=thresholds((GREEN, None)), value_size=40, title_size=14, text_mode='value_and_name',
               transformations=[rename('^Active (\\w+) alerts$')],
               overrides=[{'matcher': {'id': 'byName', 'options': 'CRITICAL'}, 'properties': [
                              {'id': 'thresholds', 'value': thresholds((GREEN, None), (DARK_RED, 1))}]},
                          {'matcher': {'id': 'byName', 'options': 'MAJOR'}, 'properties': [
                              {'id': 'thresholds', 'value': thresholds((GREEN, None), (ORANGE, 1))}]}]),
          16, 6, 4, 5)
    d.add(stat('Сбор данных', [query('A', 'Collection status')],
               'Работает ли сбор с массива. Если сбор упал, остальные плитки показывают устаревшие данные.',
               maps=mappings(COLLECT), value_size=34, title_size=18), 20, 6, 4, 5)

    # --- problems
    d.add({'type': 'alexanderzobnin-zabbix-triggers-panel', 'title': 'Активные проблемы',
           'description': 'Проблемы Zabbix по этому массиву, от Warning и выше, самые важные сверху.',
           'targets': [{'refId': 'A', 'datasource': DS, 'queryType': '5', 'group': {'filter': '$group'},
                        'host': {'filter': '$host'}, 'application': {'filter': ''}, 'proxy': {'filter': ''},
                        'trigger': {'filter': ''}, 'tags': {'filter': ''}, 'showProblems': 'problems',
                        'options': {'hostsInMaintenance': False, 'hostProxy': False, 'limit': 100,
                                    'sortProblems': 'priority', 'acknowledged': 2, 'minSeverity': 2}}],
           'options': {'layout': 'table', 'showTags': True, 'hostField': False, 'hostTechNameField': False,
                       'statusField': True, 'severityField': True, 'ackField': True, 'ageField': True,
                       'descriptionField': True, 'descriptionAtNewLine': False, 'sortProblems': 'priority',
                       'fontSize': '100%', 'pageSize': 10, 'problemTimeline': True, 'highlightNewEvents': True,
                       'highlightNewerThan': '1h', 'customLastChangeFormat': False}}, 0, 11, 24, 8)

    # --- map of the hardware: one tile per object
    d.row('Карта оборудования: каждая плитка — отдельный объект', 19)
    d.add(tiles('Ноды', '/^Node \\[.*\\] status$/', NODE, '^Node \\[(.*)\\] status$',
                'Контроллеры массива. Красная плитка — нода вышла из кластера, потеряла питание или отказала.',
                value_size=18), 0, 20, 8, 5)
    d.add(tiles('Питание: батареи', '/^Battery \\[.*\\] status$/', NODE, BATTERY_NAME,
                'Батареи питания кэша (BBU): N — нода, PS — блок питания, B — батарея. Failed — кэш записи '
                'под угрозой при обрыве питания.', rename_to='N$1 PS$2 B$3', value_size=14), 8, 20, 8, 5)
    d.add(tiles('Питание: срок годности батарей', '/^Battery \\[.*\\] expired$/', EXPIRED,
                '^Battery \\[Node (\\d+) PS (\\d+) Batt (\\d+)\\] expired$',
                'Батарея с истёкшим сроком службы подлежит замене.', rename_to='N$1 PS$2 B$3', value_size=14), 16, 20, 8, 5)
    d.add(tiles('Сеть: доступность', '/: reachable$/', UPDOWN, '^(.*): reachable$',
                'Доступность адресов массива и нод по ICMP/SSH с сервера Zabbix. «НЕТ СВЯЗИ» — пропала сеть '
                'или питание.'), 0, 25, 12, 5)
    d.add(tiles('Сеть: SSH-порт', '/: SSH port$/', UPDOWN, '^(.*): SSH port$',
                'Отвечает ли TCP-порт 22: адрес пингуется, но SSH закрыт — проблема ОС ноды или файрвола.'),
          12, 25, 12, 5)
    d.add(tiles('Порты', '/^Port \\[.*\\] link state$/', PORT, '^Port \\[(.*)\\] link state$',
                'Порты хост-интерфейсов (ячейка:слот:порт). Красный — потеря синхронизации или offline: '
                'обрыв кабеля, SFP или проблема на коммутаторе.', value_size=14), 0, 30, 12, 7)
    d.add(tiles('Диски', '/^Disk \\[.*\\] state$/', DISK, '^Disk \\[(.*)\\] state$',
                'Каждый квадрат — физический диск (магазин:слот:диск). Красный — отказ, оранжевый — деградация.',
                value_size=12), 12, 30, 12, 7)

    # --- history
    d.row('История: когда это случилось', 37)
    d.add(timeline('Ноды', '/^Node \\[.*\\] status$/', NODE, '^Node \\[(.*)\\] status$',
                   'История состояния нод.'), 0, 38, 8, 6)
    d.add(timeline('Батареи', '/^Battery \\[.*\\] status$/', NODE, BATTERY_NAME,
                   'История состояния батарей питания.', 'N$1 PS$2 B$3'), 8, 38, 8, 6)
    d.add(timeline('Сеть', '/: reachable$/', UPDOWN, '^(.*): reachable$',
                   'История доступности адресов. Провал одновременно у нескольких нод — общая причина '
                   '(коммутатор, питание стойки).'), 16, 38, 8, 6)
    d.add(timeseries('Заполненность массива', 'Capacity used, in %', 'Динамика заполнения.', 'percent',
                     '^(.*)$', max_=100, thresh_lines=thresholds((GREEN, None), (ORANGE, 80), (RED, 90))),
          0, 44, 12, 7)
    d.add(timeseries('Время отклика по сети', '/: ICMP response time$/',
                     'Задержка ping до массива и нод; рост — перегрузка или проблемы сети.', 'ms',
                     '^(.*): ICMP response time$'), 12, 44, 12, 7)
    d.row('Детали', 51)
    d.add({'type': 'bargauge', 'title': 'Утилизация томов', 'targets': [query('A', '/^Volume \\[.*\\] utilization \\(%\\)$/')],
           'description': 'Занятое место от размера тома: оранжевый от 80 %, красный от 90 %. У томов с полным '
                          'выделением (admin, .srdata) всегда 100 %, триггеров на них нет.',
           'fieldConfig': {'defaults': {'unit': 'percent', 'min': 0, 'max': 100, 'decimals': 1,
                                        'color': {'mode': 'thresholds'},
                                        'thresholds': thresholds((GREEN, None), (ORANGE, 80), (RED, 90))},
                           'overrides': []},
           'options': {'reduceOptions': {'calcs': ['lastNotNull'], 'fields': '', 'values': False},
                       'orientation': 'horizontal', 'displayMode': 'basic', 'valueMode': 'color',
                       'namePlacement': 'left', 'showUnfilled': True, 'sizing': 'auto', 'minVizHeight': 16,
                       'maxVizHeight': 24, 'legend': {'showLegend': False}},
           'transformations': [rename('^Volume \\[(.*)\\] utilization \\(%\\)$'), {'id': 'sortBy', 'options': {}}]},
          0, 52, 12, 7)
    reasons = query('A', 'System state reasons')
    reasons.update({'queryType': '2', 'textFilter': '', 'useCaptureGroups': False})
    d.add({'type': 'table', 'title': 'Причина состояния', 'targets': [reasons],
           'description': 'Почему массив считается Degraded или Failed. «нет» — всё в норме.',
           'fieldConfig': {'defaults': {'noValue': 'нет', 'color': {'mode': 'fixed', 'fixedColor': GREY},
                                        'custom': {'align': 'left', 'cellOptions': {'type': 'auto', 'wrapText': True},
                                                   'inspect': True}},
                           'overrides': [{'matcher': {'id': 'byName', 'options': 'Field'},
                                          'properties': [{'id': 'custom.hidden', 'value': True}]}]},
           'options': {'showHeader': False, 'cellHeight': 'md', 'footer': {'show': False}},
           'transformations': [LAST_PER_ROW]}, 12, 52, 12, 7)
    return d.panels


def dashboard():
    return {
        'uid': 'hpe-3par-health', 'title': 'HPE 3PAR — карта здоровья',
        'description': 'Наглядное состояние СХД: светофор по областям, плитки по узлам, питанию, сети, '
                       'портам и дискам. Дашборд для шаблона Zabbix «HPE SSMC StorageArray».',
        'tags': ['zabbix', 'storage', 'hpe-3par', 'health'], 'editable': True, 'graphTooltip': 1,
        'refresh': '30s', 'schemaVersion': 39, 'version': 1, 'time': {'from': 'now-6h', 'to': 'now'},
        'timepicker': {}, 'timezone': 'browser', 'fiscalYearStartMonth': 0, 'liveNow': False, 'weekStart': '',
        'links': [{'title': 'HPE 3PAR', 'type': 'dashboards', 'tags': ['hpe-3par'], 'asDropdown': True,
                   'includeVars': True, 'keepTime': True, 'icon': 'external link', 'targetBlank': False,
                   'tooltip': '', 'url': ''}],
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
            {'name': 'host', 'label': 'Массив', 'type': 'query', 'datasource': DS, 'refresh': 2, 'sort': 1,
             'query': {'group': '$group', 'host': '/.*/', 'application': '', 'itemTag': '', 'item': '',
                       'queryType': 'host'}, 'definition': '', 'regex': '', 'hide': 0, 'current': {},
             'options': [], 'includeAll': False, 'multi': False}]},
        'panels': build()}


if __name__ == '__main__':
    with open(sys.argv[1], 'w') as fh:
        json.dump(dashboard(), fh, ensure_ascii=False, indent=2)
        fh.write('\n')
    print('panels:', len([p for p in build() if p['type'] != 'row']))
