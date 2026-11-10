"""Checks 'Template ESXi Health by vCenter' against the output of esxi_ntp_check.py collect.

No Zabbix is needed: every dependent LLD / item JSONPath of the template is evaluated on a
fake fleet, so a typo in a path or a trigger key shows up here.
"""
import importlib.util
import json
import os
import re
import sys
import types
import unittest
from unittest import mock

import yaml

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DIR = os.path.join(ROOT, 'esxi_ntp_check')
TEMPLATE = os.path.join(DIR, 'Template ESXi Health by vCenter.yaml')


def _load():
    connect = types.ModuleType('pyVim.connect')
    connect.SmartConnect = connect.Disconnect = lambda *a, **k: None
    mods = {'pyVim': types.ModuleType('pyVim'), 'pyVim.connect': connect,
            'pyVmomi': types.ModuleType('pyVmomi')}
    mods['pyVmomi'].vim = types.SimpleNamespace()
    mods['pyVmomi'].vmodl = types.SimpleNamespace(query=types.SimpleNamespace())
    with mock.patch.dict(sys.modules, mods):
        spec = importlib.util.spec_from_file_location(
            'esxi_ntp_check', os.path.join(DIR, 'esxi_ntp_check.py'))
        mod = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(mod)
    return mod


M = _load()


def jsonpath(doc, path):
    """Tiny evaluator for the $.a["b"].c forms the template uses. Raises KeyError if no match."""
    assert path.startswith('$'), path
    cur = doc
    for m in re.finditer(r'\.(\w+)|\["([^"]+)"\]', path[1:]):
        cur = cur[m.group(1) or m.group(2)]
    return cur


def rec(i, **kw):
    r = {'state': 'connected', 'configured': 1, 'ip': '10.0.0.%d' % i, 'mgmt_ip': '10.0.0.%d' % i,
         'ts': 0, 'vmk': ['10.0.0.%d' % i], 'storage': ['online', 'online'],
         'dns': ['10.0.0.53', '10.0.1.53'], 'power': 'High performance',
         'pnic': {'vmnic0': {'up': 1, 'mb': 10000}, 'vmnic1': {'up': 0, 'mb': 0}},
         'sw': {'vSwitch0': {'n': 2, 'up': 1}}, 'ntp_offset': 0.12, 'mp_dead': 0, 'ds_bad': [], 'ha': 'connectedToMaster'}
    r.update(kw)
    return r


def fleet(n=3):
    f = {'esx%02d.dc.local' % i: rec(i) for i in range(1, n + 1)}
    f['esx-down.dc.local'] = rec(99, state='notResponding')
    return f


class Collect(unittest.TestCase):
    def setUp(self):
        self.out = M._build_collect(fleet(), ['', '', 'High performance'])

    def test_connected_host_values(self):
        h = self.out['hosts']['esx01.dc.local']
        self.assertEqual((h['ntp'], h['dns'], h['vmk'], h['stor'], h['pwr']), (1, 1, 1, 1, 1))
        self.assertEqual((h['mp_dead'], h['ds_bad'], h['ha_ok']), (0, 0, 1))
        self.assertEqual(h['ntp_off'], 0.12)

    def test_alert_flag(self):
        mm = M._build_collect({'a': rec(1, mm=True), 'b': rec(2, mm=False), 'c': rec(3, mm=None),
                               'd': rec(4, state='disconnected', mm=False)}, [])['hosts']
        self.assertEqual([mm[k]['alert'] for k in 'abcd'], [0, 1, 1, 0])
        self.assertEqual(mm['a']['ntp'], 1)             # в MM значения продолжают собираться

    def test_disconnected_host_has_no_values(self):
        self.assertEqual(self.out['hosts']['esx-down.dc.local'],
                         {'state': 'notResponding', 'alert': 0})
        # строки LLD сохраняются (иначе элементы отключались бы при каждом обрыве), значений нет
        names = [r['{#ESXINAME}'] for r in self.out['lld']['pnic']]
        self.assertIn('esx-down.dc.local', names)
        self.assertNotIn('esx-down.dc.local', self.out['pnic'])
        self.assertNotIn('esx-down.dc.local', self.out['sw'])
        self.assertIn('esx-down.dc.local', [r['{#ESXINAME}'] for r in self.out['lld']['hosts']])

    def test_missing_data_is_omitted_not_zero(self):
        out = M._build_collect({'a': rec(1, storage=None, power=None, mp_dead=None, ds_bad=None)}, [])
        h = out['hosts']['a']
        out2 = M._build_collect({'a': rec(1, ntp_offset=None)}, [])
        self.assertNotIn('ntp_off', out2['hosts']['a'])
        for k in ('stor', 'pwr', 'mp_dead', 'ds_bad'):
            self.assertNotIn(k, h)

    def test_ha_bad_states(self):
        for st, ok in (('connectedToMaster', 1), ('master', 1), ('none', 1), ('networkIsolated', 0),
                       ('networkPartitionedFromMaster', 0), ('hostDown', 0)):
            h = M._build_collect({'a': rec(1, ha=st)}, [])['hosts']['a']
            self.assertEqual(h['ha_ok'], ok, st)

    def test_warn_is_never_an_empty_string(self):
        # Zabbix may not store an empty value, then the last warning would stay forever: use a sentinel.
        self.assertEqual(self.out['warn'], 'OK')
        self.assertEqual(M._build_collect({'a': rec(1, warn='')}, [])['warn'], 'OK')

    def test_empty_datastore_names_are_a_dash(self):
        h = M._build_collect({'a': rec(1, ds_bad=[]), 'b': rec(2, ds_bad=['ds1', 'ds2'])}, [])['hosts']
        self.assertEqual((h['a']['ds_names'], h['b']['ds_names']), ('-', 'ds1, ds2'))

    def test_unreadable_ntp_ref_is_omitted(self):
        self.assertNotIn('ntp', M._build_collect({'a': rec(1, configured=None)}, [])['hosts']['a'])

    def test_size_budget_200_hosts(self):
        size = len(json.dumps(M._build_collect(fleet(200), []), ensure_ascii=False,
                              separators=(',', ':')).encode())
        print('collect JSON for 200 hosts: %d bytes' % size)
        self.assertLess(size, 200_000)


class NtpOffset(unittest.TestCase):
    def test_offsets_use_vcenter_time_and_skip_failures(self):
        import datetime as dt
        base = dt.datetime(2026, 1, 1, 12, 0, 0, tzinfo=dt.timezone.utc)
        ticks = iter([100.0] + [100.5] * 10)           # 0.5 s прошло с момента снятия времени vCenter

        class Dts:
            def __init__(self, delta, fail=False):
                self.delta, self.fail = delta, fail

            def QueryDateTime(self):
                if self.fail:
                    raise RuntimeError('boom')
                return base + dt.timedelta(seconds=0.5 + self.delta)

        got = M._ntp_offsets(base, [('a', Dts(2.0)), ('b', Dts(-3.25)), ('c', Dts(0, fail=True))],
                             workers=1, clock=lambda: next(ticks))
        self.assertEqual(got, {'a': 2.0, 'b': -3.25})

    def test_naive_datetime_is_treated_as_utc(self):
        import datetime as dt
        naive = dt.datetime(2026, 1, 1, 12, 0, 0)
        got = M._ntp_offsets(naive, [('a', types.SimpleNamespace(QueryDateTime=lambda: naive))],
                             workers=1, clock=lambda: 5.0)
        self.assertEqual(got, {'a': 0.0})

    def test_empty(self):
        self.assertEqual(M._ntp_offsets(None, []), {})


class Aggregates(unittest.TestCase):
    def test_net_summary_ignores_unused_ports_and_internal_switches(self):
        P = types.SimpleNamespace
        pnics = [P(key='k0', device='vmnic0', linkSpeed=P(speedMb=10000)),
                 P(key='k1', device='vmnic1', linkSpeed=None),
                 P(key='k2', device='vmnic2', linkSpeed=None)]       # vmnic2 unused
        nics, sws = M._net_summary(pnics, [P(name='vSwitch0', pnic=['k0', 'k1']),
                                           P(name='internal', pnic=[])],
                                   [P(dvsName='DSwitch', pnic=['k0'])])
        self.assertEqual(nics, {'vmnic0': {'up': 1, 'mb': 10000}, 'vmnic1': {'up': 0, 'mb': 0}})
        self.assertEqual(sws, {'vSwitch0': {'n': 2, 'up': 1}, 'DSwitch': {'n': 1, 'up': 1}})

    def test_mp_and_ds(self):
        P = types.SimpleNamespace
        luns = [P(path=[P(pathState='active'), P(pathState='dead')]), P(path=[P(pathState='dead')])]
        self.assertEqual(M._mp_dead(luns), 2)
        mounts = [P(volume=P(name='ds1'), mountInfo=P(accessible=False)),
                  P(volume=P(name='ds2'), mountInfo=P(accessible=True)),
                  P(volume=P(name='ds3'), mountInfo=P(accessible=None))]
        self.assertEqual(M._ds_bad(mounts), ['ds1'])


class Template(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        with open(TEMPLATE, encoding='utf-8') as f:
            cls.t = yaml.safe_load(f)['zabbix_export']['templates'][0]
        cls.doc = M._build_collect(fleet(), ['', '', 'High performance'])
        cls.master = cls.t['items'][0]['key']

    def test_all_dependent_have_master(self):
        items = list(self.t['items'])
        for r in self.t['discovery_rules']:
            self.assertEqual(r['master_item']['key'], self.master)
            items += r['item_prototypes']
        for i in items[1:]:
            self.assertEqual(i['type'], 'DEPENDENT', i['key'])
            self.assertEqual(i['master_item']['key'], self.master, i['key'])

    def test_every_jsonpath_resolves_for_every_discovered_row(self):
        checked = 0
        for r in self.t['discovery_rules']:
            rows = json.loads(json.dumps(jsonpath(self.doc, r['preprocessing'][0]['parameters'][0])))
            self.assertTrue(rows, r['key'])
            for row in rows:
                for ip in r['item_prototypes']:
                    path = ip['preprocessing'][0]['parameters'][0]
                    for k, v in row.items():
                        path = path.replace(k, v)
                    self.assertNotIn('{#', path)
                    if row['{#ESXINAME}'] == 'esx-down.dc.local':
                        # хост не connected: значений нет -> unsupported, а не ложный 0
                        if path.endswith('.alert'):
                            self.assertEqual(jsonpath(self.doc, path), 0)   # подавитель доступен всегда
                        else:
                            with self.assertRaises(KeyError):
                                jsonpath(self.doc, path)
                        continue
                    jsonpath(self.doc, path)       # KeyError = typo in the template
                    checked += 1
        self.assertGreater(checked, 30)

    def test_triggers_reference_existing_keys(self):
        keys = {self.master}
        trig = list(self.t['items'][0].get('triggers', [])) + list(self.t['items'][1].get('triggers', []))
        keys |= {i['key'] for i in self.t['items']}
        for r in self.t['discovery_rules']:
            for ip in r['item_prototypes']:
                keys.add(ip['key'])
                trig += ip.get('trigger_prototypes', [])
        prefix = '/%s/' % self.t['template']
        for tr in trig:
            for m in re.finditer(r'(?:last|max|min|avg|nodata|length)\(' + re.escape(prefix) + r'((?:[^,()]|,"(?:[^"]*)")+?)(?:,#\d+|,\d+[mh]|,\{\$[A-Z.]+\})?\)(?=[=<> )]|$)',
                                 tr['expression']):
                self.assertIn(m.group(1), keys, tr['expression'])
            self.assertIn('manual_close', tr)

    def test_every_host_trigger_is_guarded_by_alert_flag_of_its_own_rule(self):
        n = 0
        for r in self.t['discovery_rules']:
            own = {ip['key'] for ip in r['item_prototypes']}
            alert = [k for k in own if k.startswith('esxi.alert')]
            self.assertEqual(len(alert), 1, r['key'])
            for ip in r['item_prototypes']:
                for tr in ip.get('trigger_prototypes', []):
                    n += 1
                    e = tr['expression']
                    self.assertTrue(e.startswith('min(/%s/%s,{$ESXI.ALERT.HOLDDOWN})=1 and (' % (
                        self.t['template'], alert[0])), e)
                    # trigger prototype may only use item prototypes of its own LLD rule
                    for k in re.findall(r'/%s/(esxi[^,)]*?\])' % re.escape(self.t['template']), e):
                        self.assertIn(k, own, e)
        self.assertEqual(n, 13)

    def test_warn_trigger_compares_with_the_sentinel(self):
        trig = next(x for i in self.t['items'] for x in i.get('triggers', []) if 'collect.warn' in x['expression'])
        self.assertTrue(trig['expression'].endswith('<>"OK"'), trig['expression'])
        w = M._build_collect({'a': rec(1, warn='свойство x недоступно')}, [])
        self.assertEqual(w['warn'], 'свойство x недоступно')

    def test_collector_triggers_are_not_guarded(self):
        for i in self.t['items']:
            for tr in i.get('triggers', []):
                self.assertNotIn('ALERT.HOLDDOWN', tr['expression'])

    def test_lost_hosts_are_disabled_immediately(self):
        for r in self.t['discovery_rules']:
            self.assertEqual(r['enabled_lifetime_type'], 'DISABLE_IMMEDIATELY', r['key'])
            self.assertEqual(r['lifetime'], '7d')

    def test_unique_uuids_and_valuemaps_exist(self):
        with open(TEMPLATE, encoding='utf-8') as f:
            text = f.read()
        uuids = re.findall(r'uuid: ([0-9a-f]{32})', text)
        self.assertEqual(len(uuids), len(set(uuids)))
        for u in uuids:
            self.assertEqual(u[12], '4')
            self.assertIn(u[16], '89ab')
        maps = {v['name'] for v in self.t['valuemaps']}
        for r in self.t['discovery_rules']:
            for ip in r['item_prototypes']:
                if 'valuemap' in ip:
                    self.assertIn(ip['valuemap']['name'], maps)


if __name__ == '__main__':
    unittest.main()
