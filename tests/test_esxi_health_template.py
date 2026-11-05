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
         'sw': {'vSwitch0': {'n': 2, 'up': 1}}, 'mp_dead': 0, 'ds_bad': [], 'ha': 'connectedToMaster'}
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

    def test_disconnected_host_has_no_values(self):
        self.assertEqual(self.out['hosts']['esx-down.dc.local'], {'state': 'notResponding'})
        names = [r['{#ESXINAME}'] for r in self.out['lld']['pnic']]
        self.assertNotIn('esx-down.dc.local', names)
        self.assertIn('esx-down.dc.local', [r['{#ESXINAME}'] for r in self.out['lld']['hosts']])

    def test_missing_data_is_omitted_not_zero(self):
        out = M._build_collect({'a': rec(1, storage=None, power=None, mp_dead=None, ds_bad=None)}, [])
        h = out['hosts']['a']
        for k in ('stor', 'pwr', 'mp_dead', 'ds_bad'):
            self.assertNotIn(k, h)

    def test_ha_bad_states(self):
        for st, ok in (('connectedToMaster', 1), ('master', 1), ('none', 1), ('networkIsolated', 0),
                       ('networkPartitionedFromMaster', 0), ('hostDown', 0)):
            h = M._build_collect({'a': rec(1, ha=st)}, [])['hosts']['a']
            self.assertEqual(h['ha_ok'], ok, st)

    def test_size_budget_200_hosts(self):
        size = len(json.dumps(M._build_collect(fleet(200), []), ensure_ascii=False,
                              separators=(',', ':')).encode())
        print('collect JSON for 200 hosts: %d bytes' % size)
        self.assertLess(size, 200_000)


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
            for m in re.finditer(r'(?:last|max|min|nodata)\(' + re.escape(prefix) + r'(.+?)(?:,#\d+|,\d+[mh])?\)(?=[=<> ]|$)',
                                 tr['expression']):
                self.assertIn(m.group(1), keys, tr['expression'])
            self.assertIn('manual_close', tr)

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
