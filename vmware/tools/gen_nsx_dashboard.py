#!/usr/bin/env python3
"""Generates vmware/nsx/grafana/nsx-health.json: the health map of the "VMware NSX by HTTP" template.

    python3 vmware/tools/gen_nsx_dashboard.py vmware/nsx/grafana/nsx-health.json

Same layout and conventions as the HPE 3PAR health map (grafana/hpe-3par-health.json), whose
generic panel helpers are reused: one verdict for the NSX Manager cluster, a traffic light per
area (manager services, ESXi, tunnels, edges, gateways, BGP, certificates, licenses, alarms), the
Zabbix problems, a tile per object coloured by its state, edge resources, history, expiry.
Every number comes from an item of the template; the state colours follow its value maps, so red
always means "needs action".
"""
import json
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'grafana', 'tools'))
from gen_health_dashboard import (  # noqa: E402
    DS, GREEN, ORANGE, RED, DARK_RED, GREY, LAST_PER_ROW, Dashboard, mappings, problems_tile, query, rename, stat,
    thresholds, tiles, timeline, timeseries)

# Same keys as the value maps of the template ("NSX status", "NSX cluster status", "NSX BGP state").
STATUS = {0: ('UNKNOWN', GREY), 1: ('UP', GREEN), 2: ('DEGRADED', ORANGE), 3: ('DOWN', DARK_RED)}
# Tunnel status UNKNOWN means "no tunnels needed" (host without overlay workloads), not a failure.
TUNNEL = {0: ('нет туннелей', GREY), 1: ('UP', GREEN), 2: ('DEGRADED', ORANGE), 3: ('DOWN', DARK_RED)}
CLUSTER = {0: ('UNKNOWN', GREY), 1: ('STABLE', GREEN), 2: ('DEGRADED', ORANGE), 3: ('UNSTABLE', RED),
           4: ('UNAVAILABLE', DARK_RED), 5: ('INITIALIZING', ORANGE), 6: ('NO_CONTROLLERS', DARK_RED)}
VERDICT = {0: ('Неизвестно', GREY), 1: ('Норма', GREEN), 2: ('Деградация', ORANGE), 3: ('Нестабилен', RED),
           4: ('НЕДОСТУПЕН', DARK_RED), 5: ('Запуск', ORANGE), 6: ('Нет контроллеров', DARK_RED)}
# ACTIVE is a BGP state where the session is NOT up (the router keeps retrying): red, not green.
# 0 means the neighbour is no longer reported at all (edge down or neighbour removed).
BGP = {0: ('НЕТ ДАННЫХ', DARK_RED), 1: ('ESTABLISHED', GREEN), 2: ('IDLE', RED), 3: ('CONNECT', ORANGE),
       4: ('ACTIVE', RED), 5: ('OPEN_SENT', ORANGE), 6: ('OPEN_CONFIRM', ORANGE), 7: ('INVALID', DARK_RED)}

SERVICE = '/^NSX: Service group \\[.*\\]: status$/'
SERVICE_NAME = '^NSX: Service group \\[(.*)\\]: status$'
HOST = '/^Host TN \\[.*\\]: overall status$/'
HOST_NAME = '^Host TN \\[(.*)\\]: overall status$'
HOST_TUN = '/^Host TN \\[.*\\]: overlay tunnels status$/'
HOST_TUN_NAME = '^Host TN \\[(.*)\\]: overlay tunnels status$'
EDGE = '/^Edge \\[.*\\]: overall status$/'
EDGE_NAME = '^Edge \\[(.*)\\]: overall status$'
EC = '/^Edge cluster \\[.*\\]: status$/'
EC_NAME = '^Edge cluster \\[(.*)\\]: status$'
GW_BAD = '/^Tier-[01] \\[.*\\]: SR nodes not ACTIVE\\/STANDBY$/'
GW_BAD_NAME = '^(Tier-[01]) \\[(.*)\\]: SR nodes not ACTIVE/STANDBY$'
BGP_STATE = '/^BGP \\[.*\\): state$/'
# "BGP [T0-GW] 10.0.0.2 -> 10.0.0.1 (AS 65001, edge-01): state" -> "T0-GW · edge-01 → 10.0.0.1"
BGP_NAME = '^BGP \\[(.*)\\] (.*) -> (.*) \\(AS (.*), (.*)\\): state$'
BGP_LABEL = '$1 · $5 → $3'
CERT_DAYS = '/^Certificate \\[.*\\]: days until expiry$/'
CERT_NAME = '^Certificate \\[(.*)\\]: days until expiry$'
LIC_DAYS = '/^License \\[.*\\]: days until expiry$/'
LIC_NAME = '^License \\[(.*)\\]: days until expiry$'
ALARMS = '/^NSX: Open alarms (CRITICAL|HIGH|MEDIUM)$/'
ALARM_NAME = '^NSX: Open alarms (.*)$'
ALARM_COLOURS = (('CRITICAL', DARK_RED), ('HIGH', RED), ('MEDIUM', ORANGE))


def by_name(name, *props):
    return {'matcher': {'id': 'byName', 'options': name}, 'properties': [{'id': k, 'value': v} for k, v in props]}


def min_days(title, item, crit, warn, desc):
    """Shortest time left among certificates or licenses."""
    return stat(title, [query('A', item)], desc, unit='suffix: дн.',
                thresh=thresholds((RED, None), (ORANGE, crit), (GREEN, warn)), calcs=('min',), fields='/^Last/',
                transformations=[LAST_PER_ROW], decimals=0, value_size=40, title_size=18, no_value='нет')


def gateway_tiles(title, desc):
    """One tile per gateway: OK, or the number of service routers that are DOWN / not in sync."""
    return stat(title, [query('A', GW_BAD)], desc,
                maps=[{'type': 'value', 'options': {'0': {'text': 'OK', 'color': GREEN, 'index': 0}}}],
                unit='suffix: SR не в норме', thresh=thresholds((GREEN, None), (RED, 1)),
                transformations=[rename(GW_BAD_NAME, '$1 $2')], value_size=18, title_size=14,
                text_mode='value_and_name')


def bars(title, item, name_regex, desc, unit, thresh, max_=None, sort_asc=False):
    """Horizontal bar per object, coloured by the thresholds; optionally sorted by the last value."""
    defaults = {'unit': unit, 'min': 0, 'decimals': 0 if unit.startswith('suffix') else 1,
                'color': {'mode': 'thresholds'}, 'thresholds': thresh}
    if max_ is not None:
        defaults['max'] = max_
    transformations = [rename(name_regex)]
    reduce_options = {'calcs': ['lastNotNull'], 'fields': '', 'values': False}
    if sort_asc:
        # One row per object, shortest first: the one that runs out next is on top.
        transformations += [LAST_PER_ROW, {'id': 'sortBy', 'options': {'sort': [{'field': 'Last *', 'desc': False}]}}]
        reduce_options = {'calcs': ['lastNotNull'], 'fields': '/^Last/', 'values': True}
    return {'type': 'bargauge', 'title': title, 'description': desc, 'targets': [query('A', item)],
            'fieldConfig': {'defaults': defaults, 'overrides': []},
            'options': {'reduceOptions': reduce_options, 'orientation': 'horizontal', 'displayMode': 'basic',
                        'valueMode': 'color', 'namePlacement': 'left', 'showUnfilled': True, 'sizing': 'auto',
                        'minVizHeight': 16, 'maxVizHeight': 24, 'legend': {'showLegend': False}},
            'transformations': transformations}


def problems_panel():
    return {'type': 'alexanderzobnin-zabbix-triggers-panel', 'title': 'Активные проблемы',
            'description': 'Проблемы Zabbix по хосту NSX от Warning и выше, самые важные сверху. Проблемы из '
                           'алармов NSX помечены тегом nsx-severity.',
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
    d.row('Состояние NSX: зелёный — норма, красный — нужны действия', 0)

    # --- verdict, alarms and the traffic lights (y=1..10)
    d.add(stat('Кластер NSX', [query('A', 'NSX: Cluster overall status')],
               'Общее состояние кластера NSX Manager (detailed_cluster_status.overall_status). НЕДОСТУПЕН: '
               'кластер потерял кворум, изменения конфигурации невозможны; трафик при этом продолжает идти. '
               '«Нет данных»: Zabbix не получает ответ от API NSX.',
               maps=mappings(VERDICT), value_size=40, title_size=22, no_value='Нет данных'), 0, 1, 4, 5)
    d.add(stat('Алармы NSX', [query('A', ALARMS)],
               'Открытые алармы NSX: CRITICAL — тёмно-красный, HIGH — красный, MEDIUM — оранжевый. Тексты — в '
               'активных проблемах (LOW по умолчанию в проблемы не попадает).',
               thresh=thresholds((GREEN, None)), value_size=30, title_size=14, text_mode='value_and_name',
               transformations=[rename(ALARM_NAME)],
               overrides=[by_name(n, ('thresholds', thresholds((GREEN, None), (c, 1)))) for n, c in ALARM_COLOURS]),
          0, 6, 4, 5)
    lights = [
        problems_tile('Manager: сервисы', SERVICE, [('notEqual', 1)],
                      'Число сервисных групп кластера Manager (MANAGER, POLICY, CONTROLLER, DATASTORE…) не в '
                      'состоянии STABLE.'),
        stat('Manager: ноды offline', [query('A', 'NSX: Manager nodes offline')],
             'Ноды NSX Manager вне кластера. Кластер из трёх нод переживает потерю одной; после второй он '
             'только читает.', maps=[{'type': 'value', 'options': {'0': {'text': 'OK', 'color': GREEN, 'index': 0}}}],
             unit='suffix: нод', thresh=thresholds((GREEN, None), (RED, 1)), value_size=40, title_size=18),
        problems_tile('ESXi-узлы', HOST, [('notEqual', 1)],
                      'Число ESXi transport nodes не в состоянии UP (DEGRADED, DOWN, UNKNOWN).', 'узл.'),
        problems_tile('ESXi: туннели', HOST_TUN, [('notEqual', 1), ('notEqual', 0)],
                      'Число ESXi-узлов, у которых GENEVE-туннели DEGRADED или DOWN. Узлы без туннелей (нет '
                      'нагрузки на overlay) не считаются.', 'узл.'),
        problems_tile('Edge-ноды', EDGE, [('notEqual', 1)],
                      'Число Edge-нод не в состоянии UP. Edge DOWN — его SR переехали на соседа, если он есть.', 'нод'),
        problems_tile('Edge-кластеры', EC, [('notEqual', 1)],
                      'Число Edge-кластеров не в состоянии UP: DEGRADED — нет резерва, DOWN — север-юг и сервисы '
                      'шлюзов на этом кластере не работают.', 'кл.'),
        problems_tile('Шлюзы: HA', GW_BAD, [('notEqual', 0)],
                      'Число шлюзов Tier-0/Tier-1, у которых есть сервисный маршрутизатор (SR) в состоянии '
                      'DOWN, SYNC или UNKNOWN.', 'шл.'),
        problems_tile('BGP-сессии', BGP_STATE, [('notEqual', 1)],
                      'Число BGP-сессий Tier-0, которые не в состоянии ESTABLISHED.', 'сесс.'),
        min_days('Сертификаты', CERT_DAYS, 7, 30,
                 'Наименьший срок до истечения среди сертификатов NSX: оранжевый меньше 30 дней, красный меньше '
                 '7 (как пороги шаблона по умолчанию). Отрицательное число — сертификат уже истёк.'),
        min_days('Лицензии', LIC_DAYS, 14, 60,
                 'Наименьший срок до окончания срочных лицензий NSX: оранжевый меньше 60 дней, красный меньше 14. '
                 'Бессрочные лицензии не учитываются; «нет» — срочных лицензий нет.'),
    ]
    for i, t in enumerate(lights):
        d.add(t, 4 + (i % 5) * 4, 1 + (i // 5) * 5, 4, 5)

    d.add(problems_panel(), 0, 11, 24, 8)

    # --- map: one tile per object
    d.row('Карта: каждая плитка — отдельный объект', 19)
    d.add(tiles('Edge-ноды', EDGE, STATUS, EDGE_NAME,
                'Состояние каждой Edge-ноды. DOWN — нода недоступна, её SR работают на соседней ноде кластера.',
                value_size=18), 0, 20, 8, 5)
    d.add(tiles('Edge-кластеры', EC, STATUS, EC_NAME, 'Состояние Edge-кластеров.', value_size=18), 8, 20, 4, 5)
    d.add(tiles('Сервисы NSX Manager', SERVICE, CLUSTER, SERVICE_NAME,
                'Сервисные группы кластера Manager. Не STABLE — смотрите System → Appliances в NSX.',
                value_size=14), 12, 20, 12, 5)
    d.add(gateway_tiles('Шлюзы Tier-0 / Tier-1',
                        'OK — все SR в ACTIVE/STANDBY (или у Tier-1 нет SR). Число — сколько SR шлюза DOWN или не '
                        'синхронизированы: при ACTIVE_STANDBY шлюз работает без резерва, при ACTIVE_ACTIVE '
                        'потеряна часть ECMP-ёмкости.'), 0, 25, 12, 6)
    d.add(tiles('BGP-сессии', BGP_STATE, BGP, BGP_NAME,
                'Каждая сессия: Tier-0 · Edge → сосед. ACTIVE в BGP означает, что сессия НЕ установлена и '
                'маршрутизатор пытается подключиться. «НЕТ ДАННЫХ» — сосед пропал из вывода NSX.',
                rename_to=BGP_LABEL, value_size=14), 12, 25, 12, 6)
    d.add(tiles('ESXi-узлы', HOST, STATUS, HOST_NAME, 'Состояние каждого ESXi transport node.', value_size=12),
          0, 31, 12, 7)
    d.add(tiles('ESXi: overlay-туннели', HOST_TUN, TUNNEL, HOST_TUN_NAME,
                'GENEVE-туннели каждого ESXi-узла. DOWN у всех узлов разом — общая причина: MTU транспортной '
                'сети, VLAN TEP, физический коммутатор.', value_size=12), 12, 31, 12, 7)

    # --- edge resources
    d.row('Edge: ресурсы', 38)
    d.add(bars('CPU datapath (DPDK, среднее по ядрам)', '/^Edge \\[.*\\]: datapath CPU usage \\(avg DPDK core\\)$/',
               '^Edge \\[(.*)\\]: datapath CPU usage \\(avg DPDK core\\)$',
               'Реальная нагрузка обработкой пакетов (DPDK-ядра в top всегда около 100 %). Порог шаблона — 80 %.',
               'percent', thresholds((GREEN, None), (ORANGE, 70), (RED, 80)), max_=100), 0, 39, 8, 7)
    d.add(bars('CPU сервисов (не-DPDK ядра)', '/^Edge \\[.*\\]: services CPU usage \\(avg non-DPDK core\\)$/',
               '^Edge \\[(.*)\\]: services CPU usage \\(avg non-DPDK core\\)$',
               'Маршрутизация, балансировщик, VPN, DHCP. Порог шаблона — 90 %.',
               'percent', thresholds((GREEN, None), (ORANGE, 80), (RED, 90)), max_=100), 8, 39, 8, 7)
    d.add(bars('Память', '/^Edge \\[.*\\]: memory utilization$/', '^Edge \\[(.*)\\]: memory utilization$',
               'Использование памяти Edge. Порог шаблона — 90 %.',
               'percent', thresholds((GREEN, None), (ORANGE, 80), (RED, 90)), max_=100), 16, 39, 8, 7)

    # --- history
    d.row('История: когда это случилось', 46)
    d.add(timeline('Сервисы NSX Manager', SERVICE, CLUSTER, SERVICE_NAME,
                   'История состояния сервисных групп кластера Manager.'), 0, 47, 8, 7)
    d.add(timeline('Edge-ноды', EDGE, STATUS, EDGE_NAME,
                   'История состояния Edge-нод. Совпадает по времени с провалом BGP — причина в Edge.'), 8, 47, 8, 7)
    d.add(timeline('BGP-сессии', BGP_STATE, BGP, BGP_NAME,
                   'История BGP-сессий. Провал всех сессий одной Edge — проблема Edge или её аплинков; всех '
                   'сессий к одному соседу — проблема на стороне соседа.', rename_to=BGP_LABEL), 16, 47, 8, 7)
    d.add(timeseries('Edge: CPU datapath', '/^Edge \\[.*\\]: datapath CPU usage \\(avg DPDK core\\)$/',
                     'Нагрузка DPDK-ядер во времени; линия — порог шаблона 80 %.', 'percent',
                     '^Edge \\[(.*)\\]: datapath CPU usage \\(avg DPDK core\\)$', max_=100,
                     thresh_lines=thresholds((GREEN, None), (RED, 80))), 0, 54, 12, 7)
    alarms = timeseries('Открытые алармы NSX', ALARMS,
                        'Число открытых алармов NSX по важности. Рост — смотрите активные проблемы.', 'none',
                        ALARM_NAME)
    alarms['fieldConfig']['defaults']['decimals'] = 0
    alarms['fieldConfig']['defaults']['custom'].update({'lineInterpolation': 'stepAfter', 'fillOpacity': 0})
    alarms['fieldConfig']['overrides'] = [by_name(n, ('color', {'mode': 'fixed', 'fixedColor': c}))
                                          for n, c in ALARM_COLOURS]
    d.add(alarms, 12, 54, 12, 7)

    # --- expiry
    d.row('Сроки действия', 61)
    d.add(bars('Сертификаты: дней до истечения', CERT_DAYS, CERT_NAME,
               'Все сертификаты NSX, ближайший к истечению сверху. Красный — меньше 7 дней, оранжевый — меньше '
               '30. Шкала обрезана на 365 днях.', 'suffix: дн.',
               thresholds((RED, None), (ORANGE, 7), (GREEN, 30)), max_=365, sort_asc=True), 0, 62, 12, 7)
    d.add(bars('Лицензии: дней до окончания', LIC_DAYS, LIC_NAME,
               'Срочные лицензии NSX (ключ показан маской). Красный — меньше 14 дней, оранжевый — меньше 60.',
               'suffix: дн.', thresholds((RED, None), (ORANGE, 14), (GREEN, 60)), max_=365, sort_asc=True),
          12, 62, 12, 7)
    return d.panels


def dashboard():
    return {
        'uid': 'vmware-nsx-health', 'title': 'VMware NSX — карта здоровья',
        'description': 'Состояние NSX: кластер Manager, ESXi и туннели, Edge, шлюзы и HA, BGP, алармы, сроки '
                       'сертификатов и лицензий. Дашборд для шаблона Zabbix «VMware NSX by HTTP».',
        'tags': ['zabbix', 'vmware', 'nsx', 'health'], 'editable': True, 'graphTooltip': 1,
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
            {'name': 'host', 'label': 'NSX', 'type': 'query', 'datasource': DS, 'refresh': 2, 'sort': 1,
             'query': {'group': '$group', 'host': '/.*/', 'application': '', 'itemTag': '', 'item': '',
                       'queryType': 'host'}, 'definition': '', 'regex': '', 'hide': 0, 'current': {},
             'options': [], 'includeAll': False, 'multi': False}]},
        'panels': build()}


if __name__ == '__main__':
    with open(sys.argv[1], 'w') as fh:
        json.dump(dashboard(), fh, ensure_ascii=False, indent=2)
        fh.write('\n')
    print('panels:', len([p for p in build() if p['type'] != 'row']))
