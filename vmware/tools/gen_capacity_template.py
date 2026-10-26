#!/usr/bin/env python3
"""Generates vmware/zbx_template_vmware_capacity.yaml (Zabbix 7.0).

"VMware capacity planning" is linked to the vCenter host next to the standard
"VMware" template. It adds calculated items on top of what that template
already collects:

* datastores: used %, used/free bytes, days until free space falls below a
  threshold, forecast at a horizon, change over 30 days and over one year;
* clusters (aggregated over the cluster's hypervisor hosts): CPU, memory,
  power, daily energy, CPU/memory forecasts.

Run: python3 vmware/tools/gen_capacity_template.py vmware/zbx_template_vmware_capacity.yaml

Facts verified on Zabbix 7.0.31 that the formulas rely on:
* the standard templates keep the literal {$VMWARE.URL} in item keys, and
  calculated formulas match keys literally;
* foreach filters need one wildcard per key parameter: key[*,*] for the two
  parameter hypervisor keys (key[*] silently matches nothing);
* timeleft() returns 1.79e308 both when the threshold is never reached and
  when it is already behind us, so "already crossed" is handled explicitly;
* the discovery rules here reuse the standard keys with a quoted parameter
  (vmware.datastore.discovery["{$VMWARE.URL}"]): the same request for the
  VMware collector, but a distinct key, so both templates fit on one host.
"""
import hashlib
import sys

import yaml

T = 'VMware capacity planning'
URL = '{$VMWARE.URL}'
DS_P = 'vmware.datastore.size[{$VMWARE.URL},{#DATASTORE.UUID},pfree]'
DS_T = 'vmware.datastore.size[{$VMWARE.URL},{#DATASTORE.UUID}]'
HV = '?[group="{#CLUSTER.NAME} (hypervisor)"]'


def uid(name):
    h = bytearray(hashlib.md5(('vmware-capacity:' + name).encode()).digest())
    h[6] = (h[6] & 0x0F) | 0x40
    h[8] = (h[8] & 0x3F) | 0x80
    return h.hex()


def tags(component, **extra):
    out = [{'tag': 'component', 'value': component}, {'tag': 'target', 'value': 'vmware'}]
    out += [{'tag': k, 'value': v} for k, v in extra.items()]
    return sorted(out, key=lambda t: t['tag'])


def calc(name, key, formula, component, units='', delay='5m', desc='', trig=(), history='90d',
         trends='730d', entity=None):
    it = {'uuid': uid('item:' + key), 'name': name, 'type': 'CALCULATED', 'key': key, 'delay': delay,
          'history': history, 'trends': trends, 'value_type': 'FLOAT'}
    if units:
        it['units'] = units
    it['params'] = formula
    if desc:
        it['description'] = desc
    it['tags'] = tags(component, **(entity or {}))
    if trig:
        it['trigger_prototypes'] = list(trig)
    return it


def trigger(name, expr, priority, component, recovery=None, desc='', deps=(), opdata='', entity=None):
    t = {'uuid': uid('trigger:' + name), 'expression': expr}
    if recovery:
        t['recovery_mode'] = 'RECOVERY_EXPRESSION'
        t['recovery_expression'] = recovery
    t['name'] = name
    if opdata:
        t['opdata'] = opdata
    t['priority'] = priority
    if desc:
        t['description'] = desc
    if deps:
        t['dependencies'] = [{'name': d['name'], 'expression': d['expression'],
                              'recovery_expression': d['recovery_expression']} for d in deps]
    t['tags'] = sorted(tags(component, **(entity or {})) + [{'tag': 'scope', 'value': 'capacity'}],
                       key=lambda x: x['tag'])
    return t


def has_history(ref):
    """1 when `ref` already had values {$VMW.FORECAST.MIN.HISTORY} ago, else 0.

    Without it a fresh item (e.g. a cluster aggregate right after linking the template) gets a
    linear fit over a few noisy points and forecasts nonsense such as "CPU full in 0.003 days".
    """
    return '(count(//%s,1d:now-{$VMW.FORECAST.MIN.HISTORY})>0)' % ref


def days_left(ref, threshold, below):
    """Days until `ref` crosses `threshold`.

    0 when already crossed, {$VMW.FORECAST.MAX.DAYS} when never (timeleft() returns 1.79e308 in
    both of those cases, hence the explicit guard), -1 while history is too short to forecast or
    timeleft() cannot fit a trend.
    """
    crossed = 'last(//%s)>%s' % (ref, threshold) if below else 'last(//%s)<%s' % (ref, threshold)
    tl = 'timeleft(//%s,{$VMW.FORECAST.PERIOD},%s)' % (ref, threshold)
    # timeleft() itself returns -1 when it cannot fit (e.g. right after linking): treat it as
    # "no forecast" (-1), not as -0.00001 days, which would read as "already exhausted".
    neg = '(%s<0)' % tl
    days = '(%s)*((1-%s)*min(%s/86400,{$VMW.FORECAST.MAX.DAYS})-%s)' % (crossed, neg, tl, neg)
    ok = has_history(ref)
    return '%s*%s-(1-%s)' % (ok, days, ok)


def exhaustion_date(days_key):
    """now + days for a real forecast; 0 for "already reached" stays now; 0 when -1 or never."""
    d = 'last(//%s)' % days_key
    return '(%s>=0)*(%s<{$VMW.FORECAST.MAX.DAYS})*(now()+%s*86400)' % (d, d, d)


def forecast_at_horizon(ref, expr='%s'):
    """Value at {$VMW.FORECAST.HORIZON}; the current value while history is too short to forecast."""
    fc = expr % ('forecast(//%s,{$VMW.FORECAST.PERIOD},{$VMW.FORECAST.HORIZON})' % ref)
    now = expr % ('last(//%s)' % ref)
    ok = has_history(ref)
    return 'min(max(%s*%s+(1-%s)*%s,0),100)' % (ok, fc, ok, now)


def forecast_triggers(item_key, what, threshold_desc, component, entity):
    """High below {$VMW.FORECAST.CRIT} days, Warning below {$VMW.FORECAST.WARN} days, 20% hysteresis."""
    ref = '/%s/%s' % (T, item_key)
    high = trigger('%s: %s in less than {$VMW.FORECAST.CRIT} days' % (what, threshold_desc),
                   'last(%s)>=0 and last(%s)<{$VMW.FORECAST.CRIT}' % (ref, ref), 'HIGH', component,
                   recovery='last(%s)<0 or last(%s)>{$VMW.FORECAST.CRIT}*1.2' % (ref, ref), opdata='Days left: {ITEM.LASTVALUE1}',
                   desc='Linear forecast over the last {$VMW.FORECAST.PERIOD}. 0 days means the threshold is '
                        'already reached. Ignores -1 (not enough history yet).', entity=entity)
    warn = trigger('%s: %s in less than {$VMW.FORECAST.WARN} days' % (what, threshold_desc),
                   'last(%s)>=0 and last(%s)<{$VMW.FORECAST.WARN}' % (ref, ref), 'WARNING', component,
                   recovery='last(%s)<0 or last(%s)>{$VMW.FORECAST.WARN}*1.2' % (ref, ref), opdata='Days left: {ITEM.LASTVALUE1}',
                   desc='Linear forecast over the last {$VMW.FORECAST.PERIOD}.', deps=[high], entity=entity)
    return [high, warn]


# ------------------------------------------------------------------ datastores
DSK = lambda k: 'vmw.cap.ds.%s[{#DATASTORE.UUID}]' % k
ds_entity = {'datastore': '{#DATASTORE}'}
ds_items = [
    calc('Datastore [{#DATASTORE}]: used, %', DSK('pused'), '100-last(//%s)' % DS_P, 'datastore', '%',
         entity=ds_entity),
    calc('Datastore [{#DATASTORE}]: used space', DSK('used'),
         'last(//%s)*(100-last(//%s))/100' % (DS_T, DS_P), 'datastore', 'B', entity=ds_entity),
    calc('Datastore [{#DATASTORE}]: free space', DSK('free'),
         'last(//%s)*last(//%s)/100' % (DS_T, DS_P), 'datastore', 'B', entity=ds_entity),
    calc('Datastore [{#DATASTORE}]: days until free space below threshold', DSK('daysleft'),
         days_left(DS_P, '{$VMW.DS.PFREE.MIN}', below=True), 'datastore', 'd', delay='1h',
         desc='Linear trend of free space over {$VMW.FORECAST.PERIOD}. 0: already below the threshold. '
              '{$VMW.FORECAST.MAX.DAYS}: not decreasing or later than that. -1: less than '
              '{$VMW.FORECAST.MIN.HISTORY} of history, no forecast yet.',
         trig=forecast_triggers(DSK('daysleft'), 'Datastore [{#DATASTORE}]',
                                'free space below {$VMW.DS.PFREE.MIN}%', 'datastore', ds_entity),
         entity=ds_entity),
    calc('Datastore [{#DATASTORE}]: date free space below threshold (forecast)', DSK('exhaust.date'),
         exhaustion_date(DSK('daysleft')), 'datastore', 'unixtime', delay='1h',
         desc='When free space is expected to fall below {$VMW.DS.PFREE.MIN}%. 0: no forecast (not decreasing, '
              'later than {$VMW.FORECAST.MAX.DAYS} days, or not enough history).', entity=ds_entity),
    calc('Datastore [{#DATASTORE}]: used at forecast horizon, %', DSK('forecast'),
         forecast_at_horizon(DS_P, '(100-%s)'),
         'datastore', '%', delay='1h', entity=ds_entity),
    calc('Datastore [{#DATASTORE}]: used change over 30 days, p.p.', DSK('pused.change30d'),
         'trendavg(//%s,1d:now/d-30d)-avg(//%s,1d)' % (DS_P, DS_P), 'datastore', '%', delay='1h',
         desc='Daily average now minus the daily average 30 days ago, percentage points.', entity=ds_entity),
    calc('Datastore [{#DATASTORE}]: used change over 1 year, p.p.', DSK('pused.change1y'),
         'trendavg(//%s,1d:now/d-1y)-avg(//%s,1d)' % (DS_P, DS_P), 'datastore', '%', delay='1h',
         desc='Daily average now minus the same day a year ago. Needs trends older than one year.',
         entity=ds_entity),
    calc('Datastore [{#DATASTORE}]: used space 1 year ago', DSK('used.1y'),
         'trendavg(//%s,1d:now/d-1y)*(100-trendavg(//%s,1d:now/d-1y))/100' % (DS_T, DS_P),
         'datastore', 'B', delay='1h', entity=ds_entity),
    calc('Datastore [{#DATASTORE}]: used space change over 1 year', DSK('used.change1y'),
         'avg(//%s,1d)*(100-avg(//%s,1d))/100-trendavg(//%s,1d:now/d-1y)*(100-trendavg(//%s,1d:now/d-1y))/100'
         % (DS_T, DS_P, DS_T, DS_P), 'datastore', 'B', delay='1h', entity=ds_entity),
    calc('Datastore [{#DATASTORE}]: capacity change over 1 year', DSK('total.change1y'),
         'avg(//%s,1d)-trendavg(//%s,1d:now/d-1y)' % (DS_T, DS_T), 'datastore', 'B', delay='1h',
         desc='Added (positive) or removed capacity compared with a year ago.', entity=ds_entity),
]

# ------------------------------------------------------------------ clusters
CLK = lambda k: 'vmw.cap.cl.%s["{#CLUSTER.NAME}"]' % k
cl_entity = {'cluster': '{#CLUSTER.NAME}'}


def fe(func, key, period=''):
    return '%s_foreach(/*/%s%s%s)' % (func, key, HV, (',' + period) if period else '')


HV_CPU = 'vmware.hv.cpu.usage[*,*]'
HV_CPU_PCT = 'vmware.hv.cpu.usage.perf[*,*]'
HV_MEM = 'vmware.hv.memory.used[*,*]'
HV_MEM_TOTAL = 'vmware.hv.hw.memory[*,*]'
HV_POWER = 'vmware.hv.power[*,*]'
HV_POWER_MAX = 'vmware.hv.power[*,*,max]'

cl_items = [
    calc('Cluster [{#CLUSTER.NAME}]: hypervisors', CLK('hosts'), 'count(%s)' % fe('last', HV_MEM_TOTAL),
         'cluster', entity=cl_entity),
    calc('Cluster [{#CLUSTER.NAME}]: CPU usage', CLK('cpu.used'), 'sum(%s)' % fe('last', HV_CPU), 'cpu', 'Hz',
         entity=cl_entity),
    calc('Cluster [{#CLUSTER.NAME}]: CPU utilization, %', CLK('cpu.pused'), 'avg(%s)' % fe('last', HV_CPU_PCT),
         'cpu', '%', desc='Average of the hosts\' "CPU usage in percent" (exact for clusters of identical hosts).',
         entity=cl_entity),
    calc('Cluster [{#CLUSTER.NAME}]: memory used', CLK('mem.used'), 'sum(%s)' % fe('last', HV_MEM), 'memory', 'B',
         entity=cl_entity),
    calc('Cluster [{#CLUSTER.NAME}]: memory total', CLK('mem.total'), 'sum(%s)' % fe('last', HV_MEM_TOTAL),
         'memory', 'B', entity=cl_entity),
    calc('Cluster [{#CLUSTER.NAME}]: memory utilization, %', CLK('mem.pused'),
         '100*sum(%s)/sum(%s)' % (fe('last', HV_MEM), fe('last', HV_MEM_TOTAL)), 'memory', '%', entity=cl_entity),
    calc('Cluster [{#CLUSTER.NAME}]: power usage', CLK('power'), 'sum(%s)' % fe('last', HV_POWER), 'power', 'W',
         entity=cl_entity),
    calc('Cluster [{#CLUSTER.NAME}]: power usage maximum allowed', CLK('power.max'),
         'sum(%s)' % fe('last', HV_POWER_MAX), 'power', 'W', entity=cl_entity),
    calc('Cluster [{#CLUSTER.NAME}]: energy per day', CLK('energy.day'),
         'sum(%s)*24/1000' % fe('avg', HV_POWER, '1d'), 'power', 'kWh', delay='1h',
         desc='Average power over the last 24 hours × 24 h.', entity=cl_entity),
    calc('Cluster [{#CLUSTER.NAME}]: days until CPU above threshold', CLK('cpu.daysleft'),
         days_left(CLK('cpu.pused'), '{$VMW.CL.CPU.MAX}', below=False), 'cpu', 'd', delay='1h',
         desc='Linear trend of cluster CPU utilization over {$VMW.FORECAST.PERIOD}. -1 until the cluster item '
              'has {$VMW.FORECAST.MIN.HISTORY} of history (it starts when the template is linked).',
         trig=forecast_triggers(CLK('cpu.daysleft'), 'Cluster [{#CLUSTER.NAME}]',
                                'CPU utilization above {$VMW.CL.CPU.MAX}%', 'cpu', cl_entity),
         entity=cl_entity),
    calc('Cluster [{#CLUSTER.NAME}]: date CPU above threshold (forecast)', CLK('cpu.exhaust.date'),
         exhaustion_date(CLK('cpu.daysleft')), 'cpu', 'unixtime', delay='1h',
         desc='0: no forecast (not growing, too far ahead, or not enough history).', entity=cl_entity),
    calc('Cluster [{#CLUSTER.NAME}]: CPU at forecast horizon, %', CLK('cpu.forecast'),
         forecast_at_horizon(CLK('cpu.pused')),
         'cpu', '%', delay='1h', entity=cl_entity),
    calc('Cluster [{#CLUSTER.NAME}]: days until memory above threshold', CLK('mem.daysleft'),
         days_left(CLK('mem.pused'), '{$VMW.CL.MEM.MAX}', below=False), 'memory', 'd', delay='1h',
         trig=forecast_triggers(CLK('mem.daysleft'), 'Cluster [{#CLUSTER.NAME}]',
                                'memory utilization above {$VMW.CL.MEM.MAX}%', 'memory', cl_entity),
         entity=cl_entity),
    calc('Cluster [{#CLUSTER.NAME}]: date memory above threshold (forecast)', CLK('mem.exhaust.date'),
         exhaustion_date(CLK('mem.daysleft')), 'memory', 'unixtime', delay='1h',
         desc='0: no forecast (not growing, too far ahead, or not enough history).', entity=cl_entity),
    calc('Cluster [{#CLUSTER.NAME}]: memory at forecast horizon, %', CLK('mem.forecast'),
         forecast_at_horizon(CLK('mem.pused')),
         'memory', '%', delay='1h', entity=cl_entity),
]


def rule(name, key, desc, items):
    return {'uuid': uid('rule:' + key), 'name': name, 'type': 'SIMPLE', 'key': key, 'delay': '1h',
            'username': '{$VMWARE.USERNAME}', 'password': '{$VMWARE.PASSWORD}', 'lifetime': '7d',
            'description': desc, 'item_prototypes': items}


rules = [
    rule('Capacity: datastore discovery', 'vmware.datastore.discovery["{$VMWARE.URL}"]',
         'Same request as the standard datastore discovery; the quoted parameter only makes the key distinct.',
         ds_items),
    rule('Capacity: cluster discovery', 'vmware.cluster.discovery["{$VMWARE.URL}"]',
         'Same request as the standard cluster discovery. Cluster items aggregate the hypervisor hosts in the '
         'host group "<cluster> (hypervisor)" that the standard template creates.', cl_items),
]

macros = [
    ('{$VMW.CL.CPU.MAX}', '80', 'Cluster CPU utilization, %, considered exhausted for planning.'),
    ('{$VMW.CL.MEM.MAX}', '85', 'Cluster memory utilization, %, considered exhausted for planning.'),
    ('{$VMW.DS.PFREE.MIN}', '10', 'Datastore free space, %, considered exhausted for planning.'),
    ('{$VMW.FORECAST.CRIT}', '30', 'High trigger when a resource runs out in fewer days.'),
    ('{$VMW.FORECAST.HORIZON}', '90d', 'How far ahead the "forecast" items look.'),
    ('{$VMW.FORECAST.MAX.DAYS}', '3650', '"Days until" value meaning "not within 10 years / not growing".'),
    ('{$VMW.FORECAST.MIN.HISTORY}', '7d', 'Minimum history before forecasting; until then "days until" is -1.'),
    ('{$VMW.FORECAST.PERIOD}', '30d', 'History window the linear forecasts are fitted on.'),
    ('{$VMW.FORECAST.WARN}', '90', 'Warning trigger when a resource runs out in fewer days.'),
]

template = {
    'uuid': uid('template'),
    'template': T,
    'name': T,
    'description': (
        'Capacity planning for VMware: forecasts and year-over-year changes for datastores, cluster CPU, '
        'memory and power.\n\n'
        'Link to the vCenter host together with the standard "VMware" template (uses its {$VMWARE.URL}, '
        '{$VMWARE.USERNAME}, {$VMWARE.PASSWORD} macros and its items). Cluster items aggregate the hypervisor '
        'hosts that template discovers into the "<cluster> (hypervisor)" host groups.\n\n'
        'Year-over-year items need trends older than one year: the standard templates keep trends for 365d, '
        'so raise it (Administration > Housekeeping > Override item trend period, e.g. 800d).'),
    'vendor': {'name': 'Custom', 'version': '7.0-1.0.0'},
    'groups': [{'name': 'Templates/Applications'}],
    'discovery_rules': rules,
    'tags': [{'tag': 'class', 'value': 'software'}, {'tag': 'target', 'value': 'vmware'}],
    'macros': [{'macro': m, 'value': v, 'description': d} for m, v, d in sorted(macros)],
}

export = {'zabbix_export': {
    'version': '7.0',
    # UUID of the stock group in Zabbix 7.0, so the import reuses it instead of clashing.
    'template_groups': [{'uuid': 'a571c0d144b14fd4a87a9d9b2aa9fcd6', 'name': 'Templates/Applications'}],
    'templates': [template],
}}


class Dumper(yaml.SafeDumper):
    def increase_indent(self, flow=False, indentless=False):
        return super().increase_indent(flow, False)


def _str(dumper, data):
    style = '|' if '\n' in data else None
    return dumper.represent_scalar('tag:yaml.org,2002:str', data, style=style)


Dumper.add_representer(str, _str)
Dumper.ignore_aliases = lambda self, data: True

if __name__ == '__main__':
    with open(sys.argv[1], 'w') as fh:
        yaml.dump(export, fh, Dumper=Dumper, sort_keys=False, allow_unicode=True, width=1000)
    print('items:', len(ds_items) + len(cl_items), 'triggers:',
          sum(len(i.get('trigger_prototypes', [])) for i in ds_items + cl_items))
