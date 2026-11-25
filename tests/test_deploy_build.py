"""deploy/make_test_build.py: the side-by-side copies must not collide with the production files."""
import json
import os
import re
import subprocess
import sys
import tempfile
import unittest

import yaml

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SCRIPT = os.path.join(ROOT, 'deploy', 'make_test_build.py')

def uuids(text):
    return set(re.findall(r'uuid: ([0-9a-f]{32})', text))


def group_uuids(text):
    """The shared template groups keep their uuid, or the import would create duplicate groups."""
    return {g['uuid'] for g in yaml.safe_load(text)['zabbix_export']['template_groups']}


def read(*parts):
    with open(os.path.join(ROOT, *parts)) as fh:
        return fh.read()


class BuildTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory()
        subprocess.run([sys.executable, SCRIPT, cls.tmp.name], check=True, stdout=subprocess.DEVNULL)

    @classmethod
    def tearDownClass(cls):
        cls.tmp.cleanup()

    def out(self, name):
        with open(os.path.join(self.tmp.name, name)) as fh:
            return fh.read()

    def test_writes_all_three_files(self):
        self.assertEqual(sorted(os.listdir(self.tmp.name)), [
            'hpe-3par-health_v2.json', 'hpe-3par-zabbix_v2.json', 'ssmc_collect_v2.py',
            'zbx_export_templates_hpe_ssmc_v2.yaml', 'zbx_template_vmware_capacity_v2.yaml'])

    def test_dashboard_copies_do_not_overwrite_the_production_dashboards(self):
        for name in ('hpe-3par-zabbix', 'hpe-3par-health'):
            old = json.loads(read('grafana', name + '.json'))
            new = json.loads(self.out('%s_v2.json' % name))
            self.assertEqual(new['uid'], old['uid'] + '-v2')
            self.assertTrue(new['title'].endswith(' v2'))
            self.assertNotEqual(new['title'], old['title'])
            self.assertEqual(new['panels'], old['panels'])
            self.assertEqual(new['templating'], old['templating'])
            self.assertIn('v2', new['tags'])
            self.assertNotIn('id', new)

    def test_ssmc_copy_shares_no_uuid_but_the_template_group(self):
        new, old = self.out('zbx_export_templates_hpe_ssmc_v2.yaml'), read('zbx_export_templates_hpe_ssmc.yaml')
        self.assertEqual(uuids(new) & uuids(old), group_uuids(old))
        self.assertEqual(len(uuids(new)), len(uuids(old)))
        tpl = yaml.safe_load(new)['zabbix_export']['templates'][0]
        self.assertEqual(tpl['template'], 'HPE SSMC StorageArray v2')
        self.assertNotRegex(new, r'HPE SSMC StorageArray(?! v2)')
        self.assertNotIn('ssmc_collect.py', new)
        self.assertIn('ssmc_collect_v2.py[', new)

    def test_collector_copy(self):
        script = self.out('ssmc_collect_v2.py')
        self.assertRegex(script, r"VERSION = '[0-9.]+-v2'")
        self.assertNotIn('ssmc_collect.py', script)
        self.assertTrue(os.access(os.path.join(self.tmp.name, 'ssmc_collect_v2.py'), os.X_OK))

    def test_vmware_copy_has_own_uuids_names_and_keys(self):
        new, old = self.out('zbx_template_vmware_capacity_v2.yaml'), read('vmware', 'zbx_template_vmware_capacity.yaml')
        self.assertEqual(uuids(new) & uuids(old), group_uuids(old))
        self.assertEqual(len(uuids(new)), len(uuids(old)))
        doc = yaml.safe_load(new)['zabbix_export']
        tpl = doc['templates'][0]
        self.assertEqual(tpl['template'], 'VMware capacity planning v2')
        self.assertEqual(doc['template_groups'], yaml.safe_load(old)['zabbix_export']['template_groups'])
        self.assertNotRegex(new, r'VMware capacity planning(?! v2)')
        self.assertNotIn('vmw.cap.', new)
        self.assertIn('vmw.cap_v2.', new)

    def test_vmware_copy_keys_never_collide_with_production(self):
        old_doc = yaml.safe_load(read('vmware', 'zbx_template_vmware_capacity.yaml'))['zabbix_export']['templates'][0]
        new_doc = yaml.safe_load(self.out('zbx_template_vmware_capacity_v2.yaml'))['zabbix_export']['templates'][0]
        keys = lambda t: ({r['key'] for r in t['discovery_rules']} |
                          {p['key'] for r in t['discovery_rules'] for p in r['item_prototypes']})
        self.assertEqual(keys(old_doc) & keys(new_doc), set())
        # the discovery keys differ only by a space before the quoted parameter
        self.assertEqual({k.replace('[ "', '["') for k in keys(new_doc) if k.startswith('vmware.')},
                         {k for k in keys(old_doc) if k.startswith('vmware.')})

    def test_vmware_copy_formulas_follow_the_renamed_keys(self):
        new_doc = yaml.safe_load(self.out('zbx_template_vmware_capacity_v2.yaml'))['zabbix_export']['templates'][0]
        own = {p['key'] for r in new_doc['discovery_rules'] for p in r['item_prototypes']}
        own_refs = 0
        for r in new_doc['discovery_rules']:
            for p in r['item_prototypes']:
                texts = [p.get('params', '')] + [x['expression'] + x.get('recovery_expression', '')
                                                  for x in p.get('trigger_prototypes', [])]
                for ref in re.findall(r'/(?:/|VMware capacity planning v2/)(vmw\.cap_v2\.[^,)]*?\])', ' '.join(texts)):
                    own_refs += 1
                    self.assertIn(ref, own)
        self.assertGreater(own_refs, 20)

    def test_suffix_and_product_options(self):
        with tempfile.TemporaryDirectory() as tmp:
            subprocess.run([sys.executable, SCRIPT, tmp, '--suffix', 'b2', '--product', 'vmware'], check=True,
                           stdout=subprocess.DEVNULL)
            self.assertEqual(os.listdir(tmp), ['zbx_template_vmware_capacity_b2.yaml'])
            with open(os.path.join(tmp, 'zbx_template_vmware_capacity_b2.yaml')) as fh:
                self.assertIn('vmw.cap_b2.', fh.read())
        bad = subprocess.run([sys.executable, SCRIPT, '--suffix', 'a b'], stderr=subprocess.DEVNULL)
        self.assertNotEqual(bad.returncode, 0)


if __name__ == '__main__':
    unittest.main()
