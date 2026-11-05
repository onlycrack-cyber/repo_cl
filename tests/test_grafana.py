"""Checks the Grafana dashboards against their Zabbix templates.

grafana/hpe-3par-*.json against the HPE SSMC template, vmware/nsx/grafana/nsx-health.json
against "VMware NSX by HTTP", esxi_ntp_check/grafana/esxi-health.json against "Template ESXi Health by vCenter".

Every item filter in the dashboard must match at least one item or item
prototype name of the template, so renaming an item in the template cannot
silently leave a panel empty.
"""
import json
import os
import re
import unittest

import yaml

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DASHBOARD = os.path.join(ROOT, 'grafana', 'hpe-3par-zabbix.json')
HEALTH_DASHBOARD = os.path.join(ROOT, 'grafana', 'hpe-3par-health.json')
TEMPLATE = os.path.join(ROOT, 'zbx_export_templates_hpe_ssmc.yaml')
ESXI_DASHBOARD = os.path.join(ROOT, 'esxi_ntp_check', 'grafana', 'esxi-health.json')
ESXI_TEMPLATE = os.path.join(ROOT, 'esxi_ntp_check', 'Template ESXi Health by vCenter.yaml')
NSX_DASHBOARD = os.path.join(ROOT, 'vmware', 'nsx', 'grafana', 'nsx-health.json')
NSX_TEMPLATE = os.path.join(ROOT, 'vmware', 'nsx', 'zbx_template_vmware_nsx.yaml')

# Sample values for LLD macros, shaped like what discovery produces.
LLD_SAMPLES = {
    '{#DISK_POS}': '0:3:0', '{#VOLUME_NAME}': 'vol_db01', '{#NODE_NAME}': 'Node 1',
    '{#BATTERY_POS}': 'Node 1 PS 0 Batt 0', '{#PORT_POS}': '1:1:2', '{#ALERT_ID}': '101',
    '{#CERT_SERVICE}': 'wsapi', '{#PSU_POS}': 'Node 0 PS 0', '{#NET_LABEL}': 'Node 0', '{#NET_ADDR}': '10.0.0.11',
}
NSX_LLD_SAMPLES = {
    '{#GROUP.TYPE}': 'POLICY', '{#NODE.NAME}': 'esx-01', '{#EC.NAME}': 'edge-cluster-01', '{#EDGE.NAME}': 'edge-01',
    '{#T0.NAME}': 'T0-GW', '{#T1.NAME}': 'T1-APP', '{#BGP.SOURCE}': '10.0.0.2', '{#BGP.NEIGHBOR}': '10.0.0.1',
    '{#BGP.REMOTE.AS}': '65001', '{#BGP.EDGE}': 'edge-01', '{#ALARM.FEATURE}': 'Edge Health',
    '{#ALARM.EVENT}': 'Edge Node Down', '{#ALARM.NODE}': 'edge-02', '{#CERT.NAME}': 'mgr-cluster-api',
    '{#LIC.NAME}': 'NSX Enterprise Plus', '{#LIC.KEY}': '*****E1234',
}
ESXI_LLD_SAMPLES = {'{#ESXINAME}': 'esx01.dc.local', '{#NIC}': 'vmnic0', '{#VSWITCH}': 'vSwitch0'}


def template_item_names(template=TEMPLATE, samples=None):
    samples = LLD_SAMPLES if samples is None else samples
    with open(template) as fh:
        tpl = yaml.safe_load(fh)['zabbix_export']['templates'][0]
    names = [i['name'] for i in tpl['items']]
    for rule in tpl['discovery_rules']:
        for proto in rule['item_prototypes']:
            name = proto['name']
            for macro, value in samples.items():
                name = name.replace(macro, value)
            assert '{#' not in name, 'add a sample for ' + name
            names.append(name)
    return names


def template_valuemaps(template=TEMPLATE, samples=None):
    """Item or prototype name (with sample LLD values) -> {value: text} of its value map."""
    samples = LLD_SAMPLES if samples is None else samples
    with open(template) as fh:
        tpl = yaml.safe_load(fh)['zabbix_export']['templates'][0]
    maps = {v['name']: {m['value']: m['newvalue'] for m in v['mappings']} for v in tpl['valuemaps']}
    out = {}
    protos = [i for i in tpl['items']] + [p for r in tpl['discovery_rules'] for p in r['item_prototypes']]
    for it in protos:
        if 'valuemap' in it:
            name = it['name']
            for macro, value in samples.items():
                name = name.replace(macro, value)
            out[name] = maps[it['valuemap']['name']]
    return out


def item_matches(item_filter, name):
    if item_filter.startswith('/') and item_filter.endswith('/'):
        return re.search(item_filter[1:-1], name) is not None
    return item_filter == name


class DashboardTests(unittest.TestCase):
    dashboard_file = DASHBOARD
    template_file = TEMPLATE
    lld_samples = LLD_SAMPLES

    @classmethod
    def setUpClass(cls):
        with open(cls.dashboard_file) as fh:
            cls.dash = json.load(fh)
        cls.names = template_item_names(cls.template_file, cls.lld_samples)
        cls.panels = [p for p in cls.dash['panels'] if p['type'] != 'row']

    def test_every_item_filter_matches_the_template(self):
        for p in self.panels:
            for t in p['targets']:
                if t['queryType'] in ('0', '2'):
                    flt = t['item']['filter']
                    self.assertTrue(any(item_matches(flt, n) for n in self.names),
                                    '%s: %r matches no template item' % (p['title'], flt))

    def test_value_mappings_cover_the_value_map_of_the_item(self):
        # A state the template can produce but the panel does not map would show a bare number.
        maps = template_valuemaps(self.template_file, self.lld_samples)
        for p in self.panels:
            if p.get('options', {}).get('reduceOptions', {}).get('calcs') == ['count']:
                continue  # "N problems" tile: maps the count, not the state
            mapped = {k for m in p.get('fieldConfig', {}).get('defaults', {}).get('mappings', [])
                      if m['type'] == 'value' for k in m['options']}
            if not mapped or len(p['targets']) != 1 or p['targets'][0]['queryType'] not in ('0', '2'):
                continue
            flt = p['targets'][0]['item']['filter']
            wanted = [maps[n] for n in maps if item_matches(flt, n)]
            for vm in wanted:
                self.assertEqual(mapped, set(vm), '%s: panel maps %s, template value map has %s'
                                 % (p['title'], sorted(mapped), sorted(vm)))

    def test_rename_regexes_match_their_items(self):
        # renameByRegex must apply to the names the query returns, or labels stay long.
        for p in self.panels:
            for tr in p.get('transformations', []):
                if tr['id'] != 'renameByRegex':
                    continue
                flt = p['targets'][0]['item']['filter']
                returned = [n for n in self.names if item_matches(flt, n)]
                self.assertTrue(returned and all(re.search(tr['options']['regex'], n) for n in returned),
                                '%s: rename regex does not match %s' % (p['title'], returned))

    def test_queries_use_dashboard_variables(self):
        for p in self.panels:
            self.assertEqual(p['datasource']['uid'], '${datasource}', p['title'])
            for t in p['targets']:
                self.assertEqual((t['group']['filter'], t['host']['filter']), ('$group', '$host'), p['title'])
                self.assertEqual(t['datasource']['uid'], '${datasource}', p['title'])

    def test_layout_has_no_overlaps(self):
        cells = {}
        for p in self.dash['panels']:
            g = p['gridPos']
            self.assertLessEqual(g['x'] + g['w'], 24, p['title'])
            for x in range(g['x'], g['x'] + g['w']):
                for y in range(g['y'], g['y'] + g['h']):
                    self.assertNotIn((x, y), cells, '%s overlaps %s' % (p['title'], cells.get((x, y))))
                    cells[(x, y)] = p['title']

    def test_ids_unique(self):
        ids = [p['id'] for p in self.dash['panels']]
        self.assertEqual(len(ids), len(set(ids)))

    def test_state_timelines_use_mapping_colours(self):
        # In thresholds colour mode Grafana 11 ignores value-mapping colours in state timelines.
        for p in self.panels:
            if p['type'] == 'state-timeline':
                self.assertEqual(p['fieldConfig']['defaults']['color']['mode'], 'fixed', p['title'])
                self.assertTrue(p['fieldConfig']['defaults']['mappings'], p['title'])


class HealthDashboardTests(DashboardTests):
    dashboard_file = HEALTH_DASHBOARD

    def test_generated_file_is_committed(self):
        import subprocess
        import sys
        import tempfile
        with tempfile.TemporaryDirectory() as tmp:
            out = os.path.join(tmp, 'health.json')
            subprocess.run([sys.executable, os.path.join(ROOT, 'grafana', 'tools', 'gen_health_dashboard.py'), out],
                           check=True, stdout=subprocess.DEVNULL)
            with open(out) as fresh, open(HEALTH_DASHBOARD) as committed:
                self.assertEqual(fresh.read(), committed.read(), 'regenerate grafana/hpe-3par-health.json')

    def test_traffic_lights_count_problems_without_state_codes_drifting(self):
        # Every "N problems" tile keeps the rows that are NOT in the normal state.
        tiles = [p for p in self.panels if p['type'] == 'stat' and p['options']['reduceOptions']['calcs'] == ['count']]
        self.assertGreaterEqual(len(tiles), 6)
        for p in tiles:
            self.assertEqual(p['fieldConfig']['defaults']['thresholds']['steps'][0]['color'], 'green', p['title'])
            conds = {(f['config']['id'], f['config']['options']['value'])
                     for f in p['transformations'][1]['options']['filters']}
            self.assertTrue(conds, p['title'])

    def test_links_to_the_detailed_dashboard(self):
        self.assertEqual(self.dash['links'][0]['tags'], ['hpe-3par'])
        with open(DASHBOARD) as fh:
            self.assertIn('hpe-3par', json.load(fh)['tags'])



class NsxHealthDashboardTests(DashboardTests):
    dashboard_file = NSX_DASHBOARD
    template_file = NSX_TEMPLATE
    lld_samples = NSX_LLD_SAMPLES

    def test_generated_file_is_committed(self):
        import subprocess
        import sys
        import tempfile
        with tempfile.TemporaryDirectory() as tmp:
            out = os.path.join(tmp, 'nsx.json')
            subprocess.run([sys.executable, os.path.join(ROOT, 'vmware', 'tools', 'gen_nsx_dashboard.py'), out],
                           check=True, stdout=subprocess.DEVNULL)
            with open(out) as fresh, open(NSX_DASHBOARD) as committed:
                self.assertEqual(fresh.read(), committed.read(), 'regenerate vmware/nsx/grafana/nsx-health.json')

    def test_traffic_lights_cover_every_area(self):
        titles = [p['title'] for p in self.panels if p['gridPos']['y'] < 11]
        for area in ('Кластер NSX', 'Алармы NSX', 'Manager: сервисы', 'Manager: ноды offline', 'ESXi-узлы',
                     'ESXi: туннели', 'Edge-ноды', 'Edge-кластеры', 'Шлюзы: HA', 'BGP-сессии', 'Сертификаты',
                     'Лицензии'):
            self.assertIn(area, titles)

    def test_tunnels_without_workloads_are_not_counted_as_problems(self):
        # Tunnel status UNKNOWN (0) is normal for a host without overlay workloads.
        tile = next(p for p in self.panels if p['title'] == 'ESXi: туннели')
        conds = {(f['config']['id'], f['config']['options']['value'])
                 for f in tile['transformations'][1]['options']['filters']}
        self.assertEqual(conds, {('notEqual', 1), ('notEqual', 0)})

    def test_thresholds_match_template_defaults(self):
        with open(NSX_TEMPLATE) as fh:
            macros = {m['macro']: m.get('value') for m in
                      yaml.safe_load(fh)['zabbix_export']['templates'][0]['macros']}

        def steps(title):
            p = next(p for p in self.panels if p['title'] == title)
            return {s['value'] for s in p['fieldConfig']['defaults']['thresholds']['steps']} - {None}

        self.assertIn(int(macros['{$NSX.CERT.EXPIRY.CRIT}']), steps('Сертификаты'))
        self.assertIn(int(macros['{$NSX.CERT.EXPIRY.WARN}']), steps('Сертификаты'))
        self.assertIn(int(macros['{$NSX.LICENSE.EXPIRY.CRIT}']), steps('Лицензии'))
        self.assertIn(int(macros['{$NSX.LICENSE.EXPIRY.WARN}']), steps('Лицензии'))
        self.assertIn(int(macros['{$NSX.EDGE.CPU.DP.MAX}']), steps('CPU datapath (DPDK, среднее по ядрам)'))
        self.assertIn(int(macros['{$NSX.EDGE.CPU.SVC.MAX}']), steps('CPU сервисов (не-DPDK ядра)'))
        self.assertIn(int(macros['{$NSX.EDGE.MEM.MAX}']), steps('Память'))

    def test_bgp_labels_are_short(self):
        tile = next(p for p in self.panels if p['title'] == 'BGP-сессии' and p['type'] == 'stat'
                    and p['gridPos']['y'] > 11)
        tr = tile['transformations'][0]['options']
        name = next(n for n in self.names if re.search(tr['regex'], n))
        label = re.sub(tr['regex'], tr['renamePattern'].replace('$', '\\'), name)
        self.assertEqual(label, 'T0-GW · edge-01 → 10.0.0.1')


if __name__ == '__main__':
    unittest.main()


class EsxiHealthDashboardTests(DashboardTests):
    dashboard_file = ESXI_DASHBOARD
    template_file = ESXI_TEMPLATE
    lld_samples = ESXI_LLD_SAMPLES

    def test_generated_file_is_committed(self):
        import subprocess
        import sys
        import tempfile
        with tempfile.TemporaryDirectory() as tmp:
            out = os.path.join(tmp, 'esxi.json')
            subprocess.run([sys.executable, os.path.join(ROOT, 'esxi_ntp_check', 'tools', 'gen_esxi_dashboard.py'),
                            out], check=True, stdout=subprocess.DEVNULL)
            with open(out) as fresh, open(ESXI_DASHBOARD) as committed:
                self.assertEqual(fresh.read(), committed.read(), 'regenerate esxi_ntp_check/grafana/esxi-health.json')

    def test_traffic_lights_cover_every_check(self):
        titles = [p['title'] for p in self.panels if p['gridPos']['y'] < 11]
        for area in ('Хостов в vCenter', 'Сборщик: предупреждения', 'Алерты выключены', 'NTP не работает',
                     'Смещение времени', 'DNS', 'Power policy', 'VMkernel', 'Storage-адаптеры',
                     'Dead-пути к хранилищу', 'Датасторы недоступны', 'HA-агент', 'Аплинки: линк down',
                     'vSwitch без аплинков'):
            self.assertIn(area, titles)

    def test_host_alert_flag_filter_does_not_catch_uplink_or_vswitch_flags(self):
        # The three discovery rules each have an "Alerting enabled for ..." item; only the per-host one counts.
        flt = next(p for p in self.panels if p['title'] == 'Алерты выключены')['targets'][0]['item']['filter']
        hits = [n for n in self.names if n.startswith('Alerting enabled for') and item_matches(flt, n)]
        self.assertEqual(hits, ['Alerting enabled for esx01.dc.local'])

    def test_time_offset_thresholds_match_template_defaults(self):
        with open(ESXI_TEMPLATE) as fh:
            macros = {m['macro']: m.get('value') for m in
                      yaml.safe_load(fh)['zabbix_export']['templates'][0]['macros']}
        tile = next(p for p in self.panels if p['title'] == 'Смещение времени' and p['type'] == 'stat')
        limits = {f['config']['options']['value'] for f in tile['transformations'][1]['options']['filters']}
        self.assertEqual(limits, {-int(macros['{$ESXI.NTP.OFFSET.WARN}']), int(macros['{$ESXI.NTP.OFFSET.WARN}'])})
        series = next(p for p in self.panels if p['title'].startswith('Смещение времени относительно'))
        steps = {s['value'] for s in series['fieldConfig']['defaults']['thresholds']['steps']} - {None}
        high = int(macros['{$ESXI.NTP.OFFSET.HIGH}'])
        self.assertEqual(steps, {-high, -int(macros['{$ESXI.NTP.OFFSET.WARN}']),
                                 int(macros['{$ESXI.NTP.OFFSET.WARN}']), high})

    def test_collector_warnings_use_text_mode(self):
        p = next(p for p in self.panels if p['title'] == 'Сборщик: предупреждения')
        self.assertEqual(p['targets'][0]['queryType'], '2')

