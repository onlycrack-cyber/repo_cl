"""Checks grafana/hpe-3par-zabbix.json against the Zabbix template.

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
TEMPLATE = os.path.join(ROOT, 'zbx_export_templates_hpe_ssmc.yaml')

# Sample values for LLD macros, shaped like what discovery produces.
LLD_SAMPLES = {
    '{#DISK_POS}': '0:3:0', '{#VOLUME_NAME}': 'vol_db01', '{#NODE_NAME}': 'Node 1',
    '{#BATTERY_POS}': 'Node 1 PS 0 Batt 0', '{#PORT_POS}': '1:1:2', '{#ALERT_ID}': '101',
    '{#CERT_SERVICE}': 'wsapi', '{#NET_LABEL}': 'Node 0', '{#NET_ADDR}': '10.0.0.11',
}


def template_item_names():
    with open(TEMPLATE) as fh:
        tpl = yaml.safe_load(fh)['zabbix_export']['templates'][0]
    names = [i['name'] for i in tpl['items']]
    for rule in tpl['discovery_rules']:
        for proto in rule['item_prototypes']:
            name = proto['name']
            for macro, value in LLD_SAMPLES.items():
                name = name.replace(macro, value)
            assert '{#' not in name, 'add a sample for ' + name
            names.append(name)
    return names


def item_matches(item_filter, name):
    if item_filter.startswith('/') and item_filter.endswith('/'):
        return re.search(item_filter[1:-1], name) is not None
    return item_filter == name


class DashboardTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        with open(DASHBOARD) as fh:
            cls.dash = json.load(fh)
        cls.names = template_item_names()
        cls.panels = [p for p in cls.dash['panels'] if p['type'] != 'row']

    def test_every_item_filter_matches_the_template(self):
        for p in self.panels:
            for t in p['targets']:
                if t['queryType'] in ('0', '2'):
                    flt = t['item']['filter']
                    self.assertTrue(any(item_matches(flt, n) for n in self.names),
                                    '%s: %r matches no template item' % (p['title'], flt))

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


if __name__ == '__main__':
    unittest.main()
