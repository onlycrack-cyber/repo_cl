#!/usr/bin/env python3
"""Generates the VMware capacity dashboards for Grafana (Zabbix plugin 4.x/5.x).

    python3 vmware/tools/gen_grafana_dashboards.py vmware/grafana

Writes vmware-datastores.json, vmware-cpu-memory.json, vmware-power.json.

Data sources (all through the Zabbix data source):
* history and year-over-year come from the stock "VMware" / "VMware Hypervisor"
  items, so they work on the history Zabbix already has (timeShift(1y));
* forecasts, exhaustion dates and year-over-year numbers per datastore/cluster
  come from the "VMware capacity planning" template items on the vCenter host.
"""
import json
import os
import sys

DS = {'type': 'alexanderzobnin-zabbix-datasource', 'uid': '${datasource}'}
REFS = 'ABCDEFGHIJKLMNOP'
OK, WARN, HIGH, CRIT, GREY, BLUE = 'green', 'orange', 'red', 'dark-red', 'text', 'blue'
LAST_YEAR = 'год назад'

# Hosts holding the data.
VC = ('$vcgroup', '$vcenter')                               # vCenter host: stock datastores + capacity template
HV_ALL = ('/^($cluster) \\(hypervisor\\)$/', '/.*/')       # hypervisors of the selected clusters
HV_ONE = ('/^$cluster \\(hypervisor\\)$/', '/.*/')         # hypervisors of one cluster (repeated rows)


# ------------------------------------------------------------------ query helpers
def fn(name, *params):
    return {'def': {'name': name, 'category': '', 'params': [{'name': 'p%d' % i, 'type': 'string'}
                                                             for i in range(len(params))],
                    'defaultParams': []}, 'params': list(params), 'added': False}


def zq(ref, where, item, functions=(), hide=False):
    group, host = where
    t = {'refId': ref, 'datasource': DS, 'queryType': '0', 'group': {'filter': group},
         'host': {'filter': host}, 'application': {'filter': ''}, 'itemTag': {'filter': ''},
         'item': {'filter': item}, 'functions': list(functions),
         'options': {'showDisabledItems': False, 'skipEmptyValues': False, 'disableDataAlignment': False,
                     'useZabbixValueMapping': False}}
    if hide:
        t['hide'] = True
    return t


def named_q(ref, where, item, alias, functions=()):
    """Query whose series is renamed by the plugin (setAlias), so transformations can refer to it."""
    return zq(ref, where, item, list(functions) + [fn('setAlias', alias)])


# Ratios and year-over-year changes are computed with Grafana transformations, in the browser.
# Server-side expressions are not usable here: Zabbix plugin 4.6 does not substitute dashboard
# variables in queries sent with expressions (verified: Zabbix receives "$vcenter" literally).
def calc(alias, left, op, right):
    return {'id': 'calculateField', 'options': {'mode': 'binary', 'alias': alias, 'replaceFields': False,
                                                'binary': {'left': left, 'operator': op, 'right': right}}}


def keep(*names):
    return {'id': 'filterFieldsByName', 'options': {'include': {'names': list(names)}}}


JOIN = {'id': 'joinByField', 'options': {'byField': 'Time', 'mode': 'outer'}}
# Join first: one frame keeps each aliased series as its own column. (reduce + merge instead would
# stack all the series into a single "Value" column and the formulas would find nothing.)
LAST_EACH = [JOIN, {'id': 'reduce', 'options': {'mode': 'reduceFields', 'reducers': ['lastNotNull']}}]
MEAN_EACH = [JOIN, {'id': 'reduce', 'options': {'mode': 'reduceFields', 'reducers': ['mean']}}]


def change(now, ago, alias):
    """(now - ago) / ago as a fraction (shown with the percentunit unit)."""
    return [calc(alias + '_d', now, '-', ago), calc(alias, alias + '_d', '/', ago)]


def used_pct_from_free():
    """Stock items only have free %, with full history: used % = 100 - free %."""
    return [fn('scale', '-1'), fn('offset', '100')]


def rx(name):
    """Item name -> regex for the Zabbix plugin, with $datastore/$cluster left as variables."""
    out = name
    for ch in '.()[]+?':
        out = out.replace(ch, '\\' + ch)
    return '/^' + out.replace('\\[$datastore\\]', '\\[($datastore)\\]').replace(
        '\\[$cluster\\]', '\\[($cluster)\\]') + '$/'


# ------------------------------------------------------------------ panel helpers
class Builder:
    def __init__(self):
        self.panels = []
        self.next_id = 1
        self.y = 0

    def add(self, p, w, h, x=None, now=False):
        if now:
            # Shows the latest value: read the last 24 h (history) whatever the dashboard range.
            p['timeFrom'] = '24h'
            p['hideTimeOverride'] = True
        p['id'] = self.next_id
        self.next_id += 1
        if x is None:
            x = self._x
        p['gridPos'] = {'x': x, 'y': self.y, 'w': w, 'h': h}
        self._x = x + w
        self._h = max(self._h, h)
        if self._x >= 24:
            self.newline()
        self.panels.append(p)
        return p

    def row(self, title, repeat=None):
        self.newline()
        r = {'id': self.next_id, 'type': 'row', 'title': title, 'collapsed': False,
             'gridPos': {'x': 0, 'y': self.y, 'w': 24, 'h': 1}, 'panels': []}
        if repeat:
            r['repeat'] = repeat
        self.next_id += 1
        self.panels.append(r)
        self.y += 1
        self._x, self._h = 0, 0

    def newline(self):
        if getattr(self, '_h', 0):
            self.y += self._h
        self._x, self._h = 0, 0

    _x, _h = 0, 0


def thresholds(*steps):
    return {'mode': 'absolute', 'steps': [{'color': c, 'value': v} for c, v in steps]}


def vmap(*pairs):
    """pairs: (value, text, color)."""
    return {'type': 'value', 'options': {str(v): {'text': t, 'color': c, 'index': i}
                                         for i, (v, t, c) in enumerate(pairs)}}


def rmap(frm, to, text, color, index=10):
    return {'type': 'range', 'options': {'from': frm, 'to': to, 'result': {'text': text, 'color': color,
                                                                          'index': index}}}


DAYS_MAP = [vmap((-1, 'мало данных', GREY)), rmap(3650, 1e9, 'не растёт', OK, 11)]
DAYS = 'suffix: дн.'          # Grafana's "d" unit turns 93 days into "13 weeks"
DATE = 'time: DD.MM.YYYY'     # date items are scaled to ms in the query (Zabbix returns seconds)
DATE_MAP = [vmap((0, '—', GREY))]
DAYS_THRESH = thresholds((CRIT, None), (HIGH, 30), (WARN, 90), (OK, 180))


def panel(ptype, title, targets, desc='', defaults=None, options=None, overrides=None, transformations=None,
          **extra):
    defaults = defaults or {}
    overrides = list(overrides or [])
    if defaults.get('unit') and ptype != 'table':
        # The Zabbix plugin puts the item's unit into the field config, and that beats panel defaults
        # (e.g. the "d" of the days items); an override wins over the data source.
        overrides.insert(0, {'matcher': {'id': 'byType', 'options': 'number'},
                             'properties': [{'id': 'unit', 'value': defaults['unit']}]})
    p = {'type': ptype, 'title': title, 'datasource': DS,
         'targets': targets, 'fieldConfig': {'defaults': defaults, 'overrides': overrides},
         'options': options or {}}
    if desc:
        p['description'] = desc
    if transformations:
        p['transformations'] = transformations
    p.update(extra)
    return p


def rename(regex, pattern='$1'):
    return {'id': 'renameByRegex', 'options': {'regex': regex, 'renamePattern': pattern}}


def stat(title, targets, desc='', unit='none', decimals=None, thresh=None, mappings=None, color_mode='value',
         graph='none', text_mode='auto', calcs=('lastNotNull',), transformations=None, fields='',
         overrides=None, values=False):
    d = {'unit': unit, 'color': {'mode': 'thresholds'}, 'thresholds': thresh or thresholds((BLUE, None)),
         'mappings': mappings or []}
    if decimals is not None:
        d['decimals'] = decimals
    return panel('stat', title, targets, desc, d,
                 {'reduceOptions': {'calcs': list(calcs), 'fields': fields, 'values': values},
                  'orientation': 'auto', 'textMode': text_mode, 'colorMode': color_mode, 'graphMode': graph,
                  'justifyMode': 'auto', 'wideLayout': True, 'showPercentChange': False},
                 overrides, transformations)


def gauge(title, targets, desc='', unit='percent', thresh=None, transformations=None, max_=100):
    return panel('gauge', title, targets, desc,
                 {'unit': unit, 'min': 0, 'max': max_, 'decimals': 1, 'color': {'mode': 'thresholds'},
                  'thresholds': thresh or thresholds((OK, None), (WARN, 80), (HIGH, 90))},
                 {'reduceOptions': {'calcs': ['lastNotNull'], 'fields': '', 'values': False},
                  'showThresholdLabels': False, 'showThresholdMarkers': True, 'sizing': 'auto'},
                 transformations=transformations)


def timeseries(title, targets, desc='', unit='none', decimals=None, thresh=None, thresh_style='off',
               transformations=None, overrides=None, fill=8, min_=None, max_=None, bars=False, stack=False,
               legend_calcs=(), **extra):
    d = {'unit': unit, 'color': {'mode': 'palette-classic'},
         'custom': {'drawStyle': 'bars' if bars else 'line', 'lineWidth': 1 if bars else 2,
                    'fillOpacity': 80 if bars else fill, 'showPoints': 'never', 'spanNulls': True,
                    'gradientMode': 'none' if bars else 'opacity', 'axisBorderShow': False,
                    'barAlignment': 0, 'stacking': {'mode': 'normal' if stack else 'none', 'group': 'A'},
                    'thresholdsStyle': {'mode': thresh_style}},
         'thresholds': thresh or thresholds((OK, None))}
    if decimals is not None:
        d['decimals'] = decimals
    if min_ is not None:
        d['min'] = min_
    if max_ is not None:
        d['max'] = max_
    return panel('timeseries', title, targets, desc, d,
                 {'legend': {'showLegend': True, 'displayMode': 'table' if legend_calcs else 'list',
                             'placement': 'bottom', 'calcs': list(legend_calcs)},
                  'tooltip': {'mode': 'multi', 'sort': 'desc'}},
                 overrides, transformations, **extra)


def last_year_style(ref='B', name=LAST_YEAR):
    """Override: the timeShift(1y) query drawn as a grey dashed line named 'год назад'."""
    return {'matcher': {'id': 'byFrameRefID', 'options': ref},
            'properties': [{'id': 'displayName', 'value': name},
                           {'id': 'color', 'value': {'mode': 'fixed', 'fixedColor': 'text'}},
                           {'id': 'custom.lineStyle', 'value': {'fill': 'dash', 'dash': [6, 4]}},
                           {'id': 'custom.fillOpacity', 'value': 0}]}


def last_year_by_name(name=LAST_YEAR):
    """Same as last_year_style() for a field produced by a transformation."""
    return {'matcher': {'id': 'byName', 'options': name},
            'properties': [{'id': 'color', 'value': {'mode': 'fixed', 'fixedColor': 'text'}},
                           {'id': 'custom.lineStyle', 'value': {'fill': 'dash', 'dash': [6, 4]}},
                           {'id': 'custom.fillOpacity', 'value': 0}]}


def named(ref, name, color=None):
    props = [{'id': 'displayName', 'value': name}]
    if color:
        props.append({'id': 'color', 'value': {'mode': 'fixed', 'fixedColor': color}})
    return {'matcher': {'id': 'byFrameRefID', 'options': ref}, 'properties': props}


def bargauge(title, targets, desc='', unit='percent', thresh=None, transformations=None, mappings=None,
             min_=0, max_=None, decimals=1):
    d = {'unit': unit, 'min': min_, 'color': {'mode': 'thresholds'},
         'thresholds': thresh or thresholds((OK, None), (WARN, 80), (HIGH, 90)), 'mappings': mappings or []}
    if decimals is not None:
        d['decimals'] = decimals
    if max_ is not None:
        d['max'] = max_
    return panel('bargauge', title, targets, desc, d,
                 {'reduceOptions': {'calcs': ['lastNotNull'], 'fields': '', 'values': False},
                  'orientation': 'horizontal', 'displayMode': 'basic', 'valueMode': 'color',
                  'namePlacement': 'left', 'showUnfilled': True, 'sizing': 'auto', 'minVizHeight': 16,
                  'maxVizHeight': 26, 'legend': {'showLegend': False}},
                 transformations=transformations)


def pivot(title, targets, entity_rx, columns, desc='', first_col='Объект', sort=None):
    """Table: one row per entity, one column per metric.

    entity_rx: regex with named groups (?<entity>…) and (?<metric>…) applied to series names.
    columns: [(metric name as in Zabbix, column title, field config props)] in display order.
    """
    overrides = [{'matcher': {'id': 'byName', 'options': 'entity\\metric'},
                  'properties': [{'id': 'displayName', 'value': first_col}]}]
    order = {'entity\\metric': 0}
    for i, (metric, col, props) in enumerate(columns, 1):
        order[metric] = i
        overrides.append({'matcher': {'id': 'byName', 'options': metric},
                          'properties': [{'id': 'displayName', 'value': col}] + props})
    trans = [{'id': 'reduce', 'options': {'reducers': ['lastNotNull'], 'mode': 'seriesToRows',
                                          'includeTimeField': False}},
             {'id': 'extractFields', 'options': {'source': 'Field', 'format': 'regexp', 'regExp': entity_rx,
                                                 'replace': False, 'keepTime': False}},
             {'id': 'groupingToMatrix', 'options': {'columnField': 'metric', 'rowField': 'entity',
                                                    'valueField': 'Last *', 'emptyValue': 'null'}},
             {'id': 'organize', 'options': {'indexByName': order}}]
    opts = {'showHeader': True, 'cellHeight': 'sm', 'footer': {'show': False}}
    if sort:
        opts['sortBy'] = [{'displayName': sort[0], 'desc': sort[1]}]
    return panel('table', title, targets, desc,
                 {'custom': {'align': 'auto', 'cellOptions': {'type': 'auto'}, 'inspect': False},
                  'color': {'mode': 'thresholds'}, 'thresholds': thresholds((GREY, None))},
                 opts, overrides, trans)


def colored(unit, thresh, mappings=(), decimals=None, mode='color-background'):
    props = [{'id': 'unit', 'value': unit}, {'id': 'thresholds', 'value': thresh},
             {'id': 'color', 'value': {'mode': 'thresholds'}},
             {'id': 'custom.cellOptions', 'value': {'type': mode, 'mode': 'basic'}}]
    if mappings:
        props.append({'id': 'mappings', 'value': list(mappings)})
    if decimals is not None:
        props.append({'id': 'decimals', 'value': decimals})
    return props


def plain(unit, decimals=None, mappings=()):
    props = [{'id': 'unit', 'value': unit}]
    if decimals is not None:
        props.append({'id': 'decimals', 'value': decimals})
    if mappings:
        props.append({'id': 'mappings', 'value': list(mappings)})
    return props


PCT = thresholds((OK, None), (WARN, 80), (HIGH, 90))
CHANGE = thresholds((OK, None), (WARN, 5), (HIGH, 15))   # growth in p.p. per period
DAYS_MIN_PIPE = [  # nearest exhaustion across series: drop "-1 no data", sort, keep the first
    {'id': 'reduce', 'options': {'reducers': ['lastNotNull'], 'mode': 'seriesToRows', 'includeTimeField': False}},
    {'id': 'filterByValue', 'options': {'type': 'include', 'match': 'any', 'filters': [
        {'fieldName': 'Last *', 'config': {'id': 'greaterOrEqual', 'options': {'value': 0}}}]}},
    {'id': 'sortBy', 'options': {'sort': [{'field': 'Last *', 'desc': False}]}},
    {'id': 'limit', 'options': {'limitField': 1}},
]


# ------------------------------------------------------------------ variables
def var_ds():
    return {'name': 'datasource', 'label': 'Zabbix', 'type': 'datasource', 'query': 'alexanderzobnin-zabbix-datasource',
            'refresh': 1, 'regex': '', 'hide': 0, 'current': {}, 'options': [], 'includeAll': False, 'multi': False}


def var_zbx(name, label, qtype, group='', host='', item='', regex='', multi=False, include_all=False, hide=0):
    q = {'queryType': qtype, 'group': group, 'host': host, 'application': '', 'itemTag': '', 'item': item}
    return {'name': name, 'label': label, 'type': 'query', 'datasource': DS, 'query': q, 'definition': '',
            'refresh': 2, 'sort': 1, 'regex': regex, 'hide': hide, 'current': {}, 'options': [],
            'includeAll': include_all, 'multi': multi}


def common_vars():
    return [var_ds(),
            var_zbx('vcgroup', 'Группа vCenter', 'group', group='/.*/'),
            var_zbx('vcenter', 'vCenter', 'host', group='$vcgroup', host='/.*/')]


def dashboard(uid, title, desc, panels, variables, time_from='now-90d', tags=()):
    return {
        'uid': uid, 'title': title, 'description': desc, 'tags': ['vmware', 'zabbix', 'capacity'] + list(tags),
        'editable': True, 'graphTooltip': 1, 'refresh': '', 'schemaVersion': 39, 'version': 1,
        'time': {'from': time_from, 'to': 'now'}, 'timepicker': {}, 'timezone': 'browser',
        'fiscalYearStartMonth': 0, 'liveNow': False, 'weekStart': '',
        'links': [{'title': 'VMware', 'type': 'dashboards', 'tags': ['vmware', 'capacity'], 'asDropdown': True,
                   'includeVars': True, 'keepTime': True, 'icon': 'external link', 'targetBlank': False,
                   'tooltip': '', 'url': ''}],
        'annotations': {'list': [{'builtIn': 1, 'datasource': {'type': 'grafana', 'uid': '-- Grafana --'},
                                  'enable': True, 'hide': True, 'iconColor': 'rgba(0, 211, 255, 1)',
                                  'name': 'Annotations & Alerts', 'type': 'dashboard'}]},
        'templating': {'list': variables}, 'panels': panels}


# ================================================================== 1. datastores
def datastores():
    b = Builder()
    DSI = lambda m: rx('Datastore [$datastore]: ' + m)
    FREE = rx('Free space on datastore [$datastore] (percentage)')
    TOTAL = rx('Total size of datastore [$datastore]')

    b.row('Сводка')
    b.add(stat('Ёмкость', [zq('A', VC, TOTAL, [fn('sumSeries')])],
               'Суммарный размер выбранных датасторов.', 'bytes', 1), 4, 4, now=True)
    b.add(stat('Занято', [zq('A', VC, DSI('used space'), [fn('sumSeries')])], '', 'bytes', 1), 4, 4, now=True)
    b.add(stat('Свободно', [zq('A', VC, DSI('free space'), [fn('sumSeries')])], '', 'bytes', 1), 4, 4, now=True)
    b.add(gauge('Заполненность', [named_q('A', VC, DSI('used space'), 'used', [fn('sumSeries')]),
                                  named_q('B', VC, TOTAL, 'total', [fn('sumSeries')])],
                'Занято / ёмкость по всем выбранным датасторам.', 'percentunit',
                thresholds((OK, None), (WARN, 0.8), (HIGH, 0.9)),
                LAST_EACH + [calc('fill', 'used', '/', 'total'), keep('fill')], max_=1), 4, 4, now=True)
    b.add(stat('Прирост занятого за год', [zq('A', VC, DSI('used space change over 1 year'), [fn('sumSeries')])],
               'Занятое место сейчас минус год назад (среднее за сутки). Нужны тренды старше года.', 'bytes', 1), 4, 4, now=True)
    b.add(stat('Ближайшее исчерпание', [zq('A', VC, DSI('days until free space below threshold'))],
               'Датастор, у которого раньше всех свободное место упадёт ниже порога {$VMW.DS.PFREE.MIN} '
               '(10 %), и сколько дней до этого. Прогноз — линейный тренд за {$VMW.FORECAST.PERIOD}.',
               DAYS, 0, DAYS_THRESH, DAYS_MAP, text_mode='value_and_name', calcs=('lastNotNull',),
               transformations=[rename('^Datastore \\[(.*)\\]: .*$')] + DAYS_MIN_PIPE, fields='/^Last/',
               values=True), 4, 4, now=True)

    b.row('Прогноз и планирование')
    b.add(pivot('Датасторы: прогноз и изменения', [zq('A', VC, (
        '/^Datastore \\[($datastore)\\]: (used, %|used at forecast horizon, %|'
        'days until free space below threshold|'
        'used change over 30 days, p\\.p\\.|used change over 1 year, p\\.p\\.|free space|'
        'used space change over 1 year|capacity change over 1 year)$/')),
        zq('B', VC, DSI('date free space below threshold (forecast)'), [fn('scale', '1000')])],
        '/^Datastore \\[(?<entity>.*)\\]: (?<metric>.*)$/', [
            ('used, %', 'Занято', colored('percent', PCT, decimals=1)),
            ('used at forecast horizon, %', 'Через 90 дней', colored('percent', PCT, decimals=1)),
            ('days until free space below threshold', 'Дней до исчерпания',
             colored(DAYS, DAYS_THRESH, DAYS_MAP, decimals=0)),
            ('date free space below threshold (forecast)', 'Дата исчерпания', plain(DATE, mappings=DATE_MAP)),
            ('used change over 30 days, p.p.', 'За 30 дней, п.п.', colored('none', CHANGE, decimals=1, mode='color-text')),
            ('used change over 1 year, p.p.', 'За год, п.п.', colored('none', CHANGE, decimals=1, mode='color-text')),
            ('used space change over 1 year', 'Прирост за год', plain('bytes', 1)),
            ('capacity change over 1 year', 'Ёмкость: изменение за год', plain('bytes', 1)),
            ('free space', 'Свободно', plain('bytes', 1)),
        ],
        'Исчерпание — когда свободного места станет меньше {$VMW.DS.PFREE.MIN} (10 %). «Не растёт» — тренд не '
        'ведёт к исчерпанию в ближайшие 10 лет. «Мало данных» — меньше {$VMW.FORECAST.MIN.HISTORY} истории. '
        'Изменения в процентных пунктах заполненности; положительные — рост.', 'Датастор',
        sort=('Дней до исчерпания', False)), 24, 8, now=True)
    b.add(bargauge('Дней до исчерпания', [zq('A', VC, DSI('days until free space below threshold'))],
                   'Меньше 30 дней — красный, меньше 90 — оранжевый.', DAYS, DAYS_THRESH,
                   [rename('^Datastore \\[(.*)\\]: .*$')], DAYS_MAP, 0, 365, 0),
          12, 8, now=True)
    b.add(bargauge('Заполненность через 90 дней (прогноз)', [zq('A', VC, DSI('used at forecast horizon, %'))],
                   'Линейный прогноз на {$VMW.FORECAST.HORIZON} по тренду за {$VMW.FORECAST.PERIOD}.',
                   transformations=[rename('^Datastore \\[(.*)\\]: .*$')], max_=100), 12, 8, now=True)

    b.row('Утилизация за год')
    b.add(timeseries('Заполненность датасторов', [zq('A', VC, FREE, used_pct_from_free())],
                     'Занято = 100 − «Free space on datastore (percentage)» из стандартного шаблона: вся история, '
                     'которая есть в Zabbix. Пунктир — 80 и 90 %.', 'percent', 1, PCT, 'dashed',
                     [rename('^Free space on datastore \\[(.*)\\] \\(percentage\\)$')], min_=0, max_=100, fill=0,
                     legend_calcs=('lastNotNull', 'max'), timeFrom='1y'), 24, 9)
    b.add(timeseries('Ёмкость датасторов', [zq('A', VC, TOTAL)],
                     'Размер каждого датастора: видно, когда добавляли или убирали ёмкость.', 'bytes', 1,
                     transformations=[rename('^Total size of datastore \\[(.*)\\]$')], stack=True, fill=30,
                     timeFrom='1y'), 24, 7)

    b.row('Год к году')
    b.add(bargauge('Изменение заполненности за год, п.п.', [zq('A', VC, DSI('used change over 1 year, p.p.'))],
                   'Заполненность сейчас минус та же дата год назад. Положительное — рост, отрицательное — '
                   'освобождение.', 'none', thresholds((OK, None), (WARN, 5), (HIGH, 15)),
                   [rename('^Datastore \\[(.*)\\]: .*$')], min_=-30, max_=50), 12, 8, now=True)
    b.add(bargauge('Прирост занятого места за год', [zq('A', VC, DSI('used space change over 1 year'))],
                   'Сколько данных добавилось (или удалено) за год.', 'bytes',
                   thresholds((BLUE, None)), [rename('^Datastore \\[(.*)\\]: .*$')],
                   min_=None, decimals=1), 12, 8, now=True)
    p = timeseries('$datastore: этот год и прошлый', [
        zq('A', VC, rx('Free space on datastore [$datastore] (percentage)'), used_pct_from_free()),
        zq('B', VC, rx('Free space on datastore [$datastore] (percentage)'),
           used_pct_from_free() + [fn('timeShift', '1y')])],
        'Заполненность за выбранный период и за тот же период год назад (пунктир).', 'percent', 1, PCT, 'dashed',
        overrides=[named('A', 'сейчас'), last_year_style()], min_=0, max_=100, fill=10,
        repeat='datastore', repeatDirection='h', maxPerRow=3)
    b.add(p, 8, 8)
    return dashboard('vmware-datastores', 'VMware — датасторы',
                     'Ёмкость, утилизация, изменения год к году и прогноз исчерпания датасторов vCenter.',
                     b.panels, common_vars() + [
                         var_zbx('datastore', 'Датастор', 'item', '$vcgroup', '$vcenter',
                                 '/^Total size of datastore \\[.*\\]$/', '/^Total size of datastore \\[(.*)\\]$/',
                                 multi=True, include_all=True)],
                     tags=['datastore'])


# ================================================================== 2. CPU and memory
def cpu_memory():
    b = Builder()
    CLI = lambda m: rx('Cluster [$cluster]: ' + m)
    CL_RENAME = rename('^Cluster \\[(.*)\\]: .*$')

    b.row('Сводка по кластерам')
    b.add(stat('CPU, %', [zq('A', VC, CLI('CPU utilization, %'))],
               'Средняя загрузка CPU хостов кластера.', 'percent', 1, thresholds((OK, None), (WARN, 70), (HIGH, 80)),
               transformations=[CL_RENAME], graph='area'), 6, 5, now=True)
    b.add(stat('Память, %', [zq('A', VC, CLI('memory utilization, %'))],
               'Занятая память / вся память хостов кластера.', 'percent', 1,
               thresholds((OK, None), (WARN, 75), (HIGH, 85)), transformations=[CL_RENAME], graph='area'), 6, 5, now=True)
    b.add(stat('CPU: дней до порога', [zq('A', VC, CLI('days until CPU above threshold'))],
               'Через сколько дней загрузка CPU кластера превысит {$VMW.CL.CPU.MAX} (80 %).', DAYS, 0,
               DAYS_THRESH, DAYS_MAP, transformations=[CL_RENAME]), 6, 5, now=True)
    b.add(stat('Память: дней до порога', [zq('A', VC, CLI('days until memory above threshold'))],
               'Через сколько дней загрузка памяти кластера превысит {$VMW.CL.MEM.MAX} (85 %).', DAYS, 0,
               DAYS_THRESH, DAYS_MAP, transformations=[CL_RENAME]), 6, 5, now=True)

    b.row('Прогноз и планирование')
    b.add(pivot('Кластеры: прогноз', [zq('A', VC, '/^Cluster \\[($cluster)\\]: (CPU utilization, %|CPU at forecast '
                                                 'horizon, %|days until CPU above threshold|memory utilization, %|'
                                                 'memory at forecast horizon, %|days until memory above threshold|'
                                                 'hypervisors|memory total)$/'),
                                 zq('B', VC, '/^Cluster \\[($cluster)\\]: date (CPU|memory) above threshold '
                                             '\\(forecast\\)$/', [fn('scale', '1000')])],
                '/^Cluster \\[(?<entity>.*)\\]: (?<metric>.*)$/', [
                    ('hypervisors', 'Хостов', plain('none', 0)),
                    ('CPU utilization, %', 'CPU', colored('percent', thresholds((OK, None), (WARN, 70), (HIGH, 80)), decimals=1)),
                    ('CPU at forecast horizon, %', 'CPU через 90 дней', colored('percent', thresholds((OK, None), (WARN, 70), (HIGH, 80)), decimals=1)),
                    ('days until CPU above threshold', 'CPU: дней до порога', colored(DAYS, DAYS_THRESH, DAYS_MAP, 0)),
                    ('date CPU above threshold (forecast)', 'CPU: дата', plain(DATE, mappings=DATE_MAP)),
                    ('memory utilization, %', 'Память', colored('percent', thresholds((OK, None), (WARN, 75), (HIGH, 85)), decimals=1)),
                    ('memory at forecast horizon, %', 'Память через 90 дней', colored('percent', thresholds((OK, None), (WARN, 75), (HIGH, 85)), decimals=1)),
                    ('days until memory above threshold', 'Память: дней до порога', colored(DAYS, DAYS_THRESH, DAYS_MAP, 0)),
                    ('date memory above threshold (forecast)', 'Память: дата', plain(DATE, mappings=DATE_MAP)),
                    ('memory total', 'Память всего', plain('bytes', 0)),
                ],
                'Порог исчерпания: CPU {$VMW.CL.CPU.MAX} (80 %), память {$VMW.CL.MEM.MAX} (85 %). Прогноз — линейный '
                'тренд за {$VMW.FORECAST.PERIOD}; появляется, когда у элементов кластера накопится '
                '{$VMW.FORECAST.MIN.HISTORY} истории.', 'Кластер'), 24, 6, now=True)

    b.row('Кластер $cluster', repeat='cluster')
    b.add(timeseries('CPU по хостам', [zq('A', HV_ONE, 'CPU usage in percent')],
                     'Загрузка CPU каждого гипервизора кластера (стандартный шаблон «VMware Hypervisor»). '
                     'Пунктир — порог 80 %.', 'percent', 1, thresholds((OK, None), (HIGH, 80)), 'dashed',
                     [rename('^(.*): CPU usage in percent$')], min_=0, max_=100, fill=0,
                     legend_calcs=('mean', 'max')), 12, 8)
    b.add(timeseries('Память кластера: этот год и прошлый', [
        named_q('A', HV_ONE, 'Used memory', 'used', [fn('sumSeries')]),
        named_q('B', HV_ONE, 'Total memory', 'total', [fn('sumSeries')]),
        named_q('C', HV_ONE, 'Used memory', 'used_1y', [fn('sumSeries'), fn('timeShift', '1y')]),
        named_q('D', HV_ONE, 'Total memory', 'total_1y', [fn('sumSeries'), fn('timeShift', '1y')])],
        'Занятая память / вся память хостов кластера, сейчас и за тот же период год назад (пунктир).',
        'percentunit', 1, thresholds((OK, None), (HIGH, 0.85)), 'dashed',
        [JOIN, calc('сейчас', 'used', '/', 'total'), calc(LAST_YEAR, 'used_1y', '/', 'total_1y'),
         keep('Time', 'сейчас', LAST_YEAR)],
        overrides=[last_year_by_name()], min_=0, max_=1), 12, 8)
    b.add(timeseries('CPU кластера, частота: этот год и прошлый', [
        zq('A', HV_ONE, 'CPU usage', [fn('sumSeries')]),
        zq('B', HV_ONE, 'CPU usage', [fn('sumSeries'), fn('timeShift', '1y')])],
        'Суммарная потребляемая частота CPU всех хостов кластера.', 'hertz', 1,
        overrides=[named('A', 'сейчас'), last_year_style()]), 12, 8)
    b.add(stat('Изменение к прошлому году', [
        named_q('A', HV_ONE, 'CPU usage', 'cpu', [fn('sumSeries')]),
        named_q('B', HV_ONE, 'CPU usage', 'cpu_1y', [fn('sumSeries'), fn('timeShift', '1y')]),
        named_q('C', HV_ONE, 'Used memory', 'mem', [fn('sumSeries')]),
        named_q('D', HV_ONE, 'Used memory', 'mem_1y', [fn('sumSeries'), fn('timeShift', '1y')])],
        'Среднее за выбранный период против того же периода год назад. Рост CPU — по потребляемой частоте, '
        'памяти — по занятому объёму.', 'percentunit', 1,
        thresholds((OK, None), (WARN, 0.15), (HIGH, 0.3)), text_mode='value_and_name',
        transformations=MEAN_EACH + change('cpu', 'cpu_1y', 'CPU') + change('mem', 'mem_1y', 'Память')
        + [keep('CPU', 'Память')]), 12, 8)

    b.row('Хосты')
    b.add(pivot('Гипервизоры', [zq('A', HV_ALL, '/^(CPU usage in percent|Used memory|Total memory|CPU cores|'
                                                'CPU frequency)$/')],
                '/^(?<entity>[^:]+): (?<metric>.*)$/', [
                    ('CPU usage in percent', 'CPU', colored('percent', thresholds((OK, None), (WARN, 70), (HIGH, 80)), decimals=1)),
                    ('Used memory', 'Память занято', plain('bytes', 1)),
                    ('Total memory', 'Память всего', plain('bytes', 0)),
                    ('CPU cores', 'Ядер', plain('none', 0)),
                    ('CPU frequency', 'Частота', plain('hertz', 1)),
                ], 'Текущие значения по каждому гипервизору выбранных кластеров.', 'Хост', sort=('CPU', True)),
          24, 8, now=True)
    return dashboard('vmware-cpu-memory', 'VMware — CPU и память',
                     'Утилизация CPU и памяти кластеров vCenter, изменения год к году и прогноз исчерпания.',
                     b.panels, common_vars() + [
                         var_zbx('cluster', 'Кластер', 'item', '$vcgroup', '$vcenter',
                                 '/^Cluster \\[.*\\]: CPU utilization, %$/', '/^Cluster \\[(.*)\\]: CPU/',
                                 multi=True, include_all=True)],
                     tags=['cpu', 'memory'])


# ================================================================== 3. power
def power():
    b = Builder()
    KW = [fn('sumSeries'), fn('scale', '0.001')]
    KWH_DAY = [fn('sumSeries'), fn('groupBy', '1d', 'avg'), fn('scale', '0.024')]   # avg W × 24 h / 1000

    b.row('Сводка')
    b.add(stat('Потребление сейчас', [zq('A', HV_ALL, 'Power usage', KW)],
               'Суммарная мощность всех гипервизоров выбранных кластеров.', 'kwatt', 2, graph='area'), 5, 5, now=True)
    b.add(stat('Разрешённый максимум', [zq('A', HV_ALL, 'Power usage maximum allowed', KW)],
               'Сумма «Power usage maximum allowed» хостов: предел, заданный политикой питания.', 'kwatt', 2), 5, 5, now=True)
    b.add(gauge('Загрузка по питанию', [named_q('A', HV_ALL, 'Power usage', 'power', [fn('sumSeries')]),
                                        named_q('B', HV_ALL, 'Power usage maximum allowed', 'max', [fn('sumSeries')])],
                'Потребление сейчас / разрешённый максимум.', 'percentunit',
                thresholds((OK, None), (WARN, 0.7), (HIGH, 0.85)),
                LAST_EACH + [calc('load', 'power', '/', 'max'), keep('load')], max_=1),
          4, 5, now=True)
    b.add(stat('Энергия за период', [zq('A', HV_ALL, 'Power usage', KWH_DAY)],
               'Сумма суточного потребления за выбранный период (средняя мощность за сутки × 24 ч).', 'kwatth', 1,
               calcs=('sum',)), 5, 5)
    b.add(stat('Изменение к прошлому году', [
        named_q('A', HV_ALL, 'Power usage', 'power', [fn('sumSeries')]),
        named_q('B', HV_ALL, 'Power usage', 'power_1y', [fn('sumSeries'), fn('timeShift', '1y')])],
        'Средняя мощность за выбранный период против того же периода год назад.', 'percentunit', 1,
        thresholds((OK, None), (WARN, 0.1), (HIGH, 0.25)),
        transformations=MEAN_EACH + change('power', 'power_1y', 'YOY') + [keep('YOY')]), 5, 5)

    b.row('Динамика')
    b.add(timeseries('Потребление: этот год и прошлый', [
        zq('A', HV_ALL, 'Power usage', KW), zq('B', HV_ALL, 'Power usage', KW + [fn('timeShift', '1y')])],
        'Суммарная мощность выбранных кластеров и тот же период год назад (пунктир).', 'kwatt', 2,
        overrides=[named('A', 'сейчас'), last_year_style()], legend_calcs=('mean', 'max')), 24, 8)
    b.add(timeseries('Энергия по дням: этот год и прошлый', [
        zq('A', HV_ALL, 'Power usage', KWH_DAY), zq('B', HV_ALL, 'Power usage', KWH_DAY + [fn('timeShift', '1y')])],
        'кВт·ч в сутки = средняя мощность за сутки × 24 ч. Столбцы — этот год, пунктир — тот же день год назад.',
        'kwatth', 1, bars=True,
        overrides=[named('A', 'сейчас', 'blue'), last_year_style(),
                   {'matcher': {'id': 'byFrameRefID', 'options': 'B'},
                    'properties': [{'id': 'custom.drawStyle', 'value': 'line'},
                                   {'id': 'custom.lineWidth', 'value': 2}]}],
        legend_calcs=('sum', 'mean')),
          24, 8)

    b.row('Кластер $cluster', repeat='cluster')
    b.add(timeseries('Потребление по хостам', [zq('A', HV_ONE, 'Power usage', [fn('scale', '0.001')])],
                     'Мощность каждого гипервизора кластера, накопительно.', 'kwatt', 2,
                     transformations=[rename('^(.*): Power usage$')], stack=True, fill=40), 12, 8)
    b.add(timeseries('Кластер: этот год и прошлый', [
        zq('A', HV_ONE, 'Power usage', KW), zq('B', HV_ONE, 'Power usage', KW + [fn('timeShift', '1y')])],
        '', 'kwatt', 2, overrides=[named('A', 'сейчас'), last_year_style()], legend_calcs=('mean',)), 12, 8)

    b.row('Хосты')
    b.add(pivot('Гипервизоры', [zq('A', HV_ALL, '/^Power usage( maximum allowed)?$/')],
                '/^(?<entity>[^:]+): (?<metric>.*)$/', [
                    ('Power usage', 'Сейчас', plain('watt')),
                    ('Power usage maximum allowed', 'Максимум', plain('watt')),
                ], 'Текущая мощность каждого гипервизора.', 'Хост', sort=('Сейчас', True)), 12, 8, now=True)
    b.add(bargauge('Самые нагруженные хосты', [zq('A', HV_ALL, 'Power usage', [fn('scale', '0.001')])],
                   '', 'kwatt', thresholds((BLUE, None)), [rename('^(.*): Power usage$')], decimals=None),
          12, 8, now=True)
    return dashboard('vmware-power', 'VMware — питание',
                     'Потребление гипервизоров vCenter в кВт, энергия по дням и сравнение с прошлым годом.',
                     b.panels, common_vars() + [
                         var_zbx('cluster', 'Кластер', 'item', '$vcgroup', '$vcenter',
                                 '/^Cluster \\[.*\\]: power usage$/', '/^Cluster \\[(.*)\\]: power/',
                                 multi=True, include_all=True)],
                     tags=['power'])


def main(out_dir):
    for name, build in (('vmware-datastores.json', datastores), ('vmware-cpu-memory.json', cpu_memory),
                        ('vmware-power.json', power)):
        d = build()
        with open(os.path.join(out_dir, name), 'w') as fh:
            json.dump(d, fh, ensure_ascii=False, indent=2)
            fh.write('\n')
        print('%-26s panels=%d' % (name, len([p for p in d['panels'] if p['type'] != 'row'])))


if __name__ == '__main__':
    main(sys.argv[1])
